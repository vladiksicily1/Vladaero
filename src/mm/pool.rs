//! # Kernel Pool Allocator
//!
//! NonPagedPool, PagedPool allocators with pool headers and lookaside lists,
//! matching ntoskrnl.exe's ExAllocatePool/ExFreePool family.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use super::{
    SpinLock, Mutex, ListEntry, PoolTag, TAG_POOL, MmProtectionMask2,
    PAGE_SHIFT, PAGE_SIZE_X64,
    mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Pool types
// ============================================================

pub const NON_PAGED_POOL: u32 = 0;
pub const PAGED_POOL: u32 = 1;
pub const NON_PAGED_POOL_MUST_SUCCEED: u32 = 2;
pub const DONT_USE_THIS_CODE: u32 = 3;
pub const NON_PAGED_POOL_CACHE_ALIGNED: u32 = 4;
pub const PAGED_POOL_CACHE_ALIGNED: u32 = 5;
pub const NON_PAGED_POOL_SESSION_START: u32 = 0x100;
pub const NON_PAGED_POOL_MAXIMUM_INDEX: u32 = 2;
pub const MUST_SUCCEED_POOL_TYPE_START: u32 = NON_PAGED_POOL_MUST_SUCCEED;

// ============================================================
// Pool block sizes (lookaside list buckets)
// ============================================================

pub const MI_POOL_BLOCK_MIN: usize = 0x20;
pub const MI_POOL_BLOCK_MAX: usize = 0x1000;
pub const MI_POOL_BLOCK_SHIFT: usize = 5;
pub const MI_POOL_BLOCK_COUNT: usize = (MI_POOL_BLOCK_MAX >> MI_POOL_BLOCK_SHIFT);

// ============================================================
// Pool header (front of every allocation)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct PoolHeader {
    pub previous_size: u8,
    pub pool_index: u8,
    pub block_size: u8,
    pub pool_type: u32,
    pub process_billed: u64,
    pub pool_tag: u32,
    pub pool_index_2: u16,
    pub pool_type_2: u16,
}

impl PoolHeader {
    pub fn tag(&self) -> PoolTag {
        PoolTag(self.pool_tag.to_ne_bytes())
    }
}

pub const POOL_HEADER_SIZE: usize = core::mem::size_of::<PoolHeader>();

// ============================================================
// Pool descriptor
// ============================================================

#[repr(C)]
pub struct PoolDescriptor {
    pub pool_type: u32,
    pub pool_block_size: u32,
    pub total_pages: u32,
    pub used_pages: u32,
    pub total_allocated: u32,
    pub total_freed: u32,
    pub lock: SpinLock,
    pub free_list: ListEntry,
    pub pending_free_count: u32,
    pub special_pool: bool,
}

impl PoolDescriptor {
    pub const fn new(pool_type: u32) -> Self {
        Self {
            pool_type,
            pool_block_size: 0,
            total_pages: 0,
            used_pages: 0,
            total_allocated: 0,
            total_freed: 0,
            lock: SpinLock::new(),
            free_list: ListEntry::new(),
            pending_free_count: 0,
            special_pool: false,
        }
    }
}

unsafe impl Send for PoolDescriptor {}
unsafe impl Sync for PoolDescriptor {}

// ============================================================
// Global pool state
// ============================================================

static NON_PAGED_POOL_DESC: Mutex<PoolDescriptor> = Mutex::new(PoolDescriptor::new(NON_PAGED_POOL));
static PAGED_POOL_DESC: Mutex<PoolDescriptor> = Mutex::new(PoolDescriptor::new(PAGED_POOL));

static POOL_LOCK: SpinLock = SpinLock::new();

// ============================================================
// Pool lookaside list (per-size lookaside)
// ============================================================

#[repr(C)]
pub struct MiPoolLookaside {
    pub size: u16,
    pub total_allocated: u32,
    pub total_freed: u32,
    pub hits: u32,
    pub misses: u32,
    pub list: ListEntry,
    pub count: u32,
    pub max_depth: u32,
}

impl MiPoolLookaside {
    pub const fn new(size: u16) -> Self {
        Self {
            size,
            total_allocated: 0,
            total_freed: 0,
            hits: 0,
            misses: 0,
            list: ListEntry::new(),
            count: 0,
            max_depth: 4,
        }
    }
}

static mut NON_PAGED_LOOKASIDE: [MiPoolLookaside; MI_POOL_BLOCK_COUNT] = {
    const INIT: MiPoolLookaside = MiPoolLookaside::new(0);
    [INIT; MI_POOL_BLOCK_COUNT]
};

// ============================================================
// ExInitializePoolDescriptor
// ============================================================

pub fn ex_initialize_pool_descriptor(
    pool_type: u32,
    pool_block_size: u32,
) {
    match pool_type {
        NON_PAGED_POOL | NON_PAGED_POOL_MUST_SUCCEED => {
            let mut desc = NON_PAGED_POOL_DESC.lock();
            desc.pool_block_size = pool_block_size;
            desc.total_pages = 0;
            desc.used_pages = 0;
        }
        PAGED_POOL => {
            let mut desc = PAGED_POOL_DESC.lock();
            desc.pool_block_size = pool_block_size;
            desc.total_pages = 0;
            desc.used_pages = 0;
        }
        _ => {
            mm_warn!("ExInitializePoolDescriptor: unknown pool type {}", pool_type);
        }
    }

    // Initialize lookaside lists for non-paged pool
    unsafe {
        for i in 0..MI_POOL_BLOCK_COUNT {
            let size = (i + 1) << MI_POOL_BLOCK_SHIFT;
            NON_PAGED_LOOKASIDE[i] = MiPoolLookaside::new(size as u16);
        }
    }

    mm_dbg!("ExInitializePoolDescriptor: pool type {} block_size={}", pool_type, pool_block_size);
}

// ============================================================
// ExAllocatePool (legacy entry point)
// ============================================================

pub fn ex_allocate_pool(pool_type: u32, number_of_bytes: usize, tag: PoolTag) -> *mut c_void {
    ex_allocate_pool_with_tag(pool_type, number_of_bytes, tag)
}

// ============================================================
// ExAllocatePoolWithTag
// ============================================================

pub fn ex_allocate_pool_with_tag(
    pool_type: u32,
    number_of_bytes: usize,
    tag: PoolTag,
) -> *mut c_void {
    if number_of_bytes == 0 {
        return core::ptr::null_mut();
    }

    let total_size = number_of_bytes + POOL_HEADER_SIZE;
    let aligned_size = (total_size + 0x1F) & !0x1F; // 32-byte alignment

    match pool_type {
        NON_PAGED_POOL | NON_PAGED_POOL_MUST_SUCCEED => {
            let ptr = mi_allocate_non_paged_pool(aligned_size);
            if ptr.is_null() && pool_type == NON_PAGED_POOL_MUST_SUCCEED {
                mm_err!("ExAllocatePoolWithTag: MUST_SUCCEED allocation failed! size={}", number_of_bytes);
                // In real ntoskrnl this would bugcheck
            }
            mi_initialize_pool_header(ptr, aligned_size, pool_type, tag);
            unsafe { (ptr as *mut u8).add(POOL_HEADER_SIZE) as *mut c_void }
        }
        PAGED_POOL => {
            let ptr = mi_allocate_paged_pool(aligned_size);
            mi_initialize_pool_header(ptr, aligned_size, pool_type, tag);
            unsafe { (ptr as *mut u8).add(POOL_HEADER_SIZE) as *mut c_void }
        }
        _ => {
            mm_warn!("ExAllocatePoolWithTag: unknown pool type {}", pool_type);
            core::ptr::null_mut()
        }
    }
}

// ============================================================
// ExAllocatePoolQuotaWithTag
// ============================================================

pub fn ex_allocate_pool_quota_with_tag(
    pool_type: u32,
    number_of_bytes: usize,
    tag: PoolTag,
) -> *mut c_void {
    ex_allocate_pool_with_tag(pool_type, number_of_bytes, tag)
}

// ============================================================
// ExFreePool / ExFreePoolWithTag
// ============================================================

pub fn ex_free_poolWithTag(ptr: *mut c_void, tag: PoolTag) {
    if ptr.is_null() {
        return;
    }

    unsafe {
        let header = (ptr as *mut u8).sub(POOL_HEADER_SIZE) as *mut PoolHeader;

        if (*header).tag() != tag {
            mm_warn!("ExFreePoolWithTag: tag mismatch! expected {:?} got {:?}",
                tag, (*header).tag());
        }

        let pool_type = (*header).pool_type;
        let block_size = (*header).block_size as usize;
        let total_size = if block_size == 0 {
            PAGE_SIZE_X64
        } else {
            block_size * 32
        };

        match pool_type {
            NON_PAGED_POOL | NON_PAGED_POOL_MUST_SUCCEED => {
                mi_free_non_paged_pool(header as *mut c_void, total_size);
            }
            PAGED_POOL => {
                mi_free_paged_pool(header as *mut c_void, total_size);
            }
            _ => {
                mm_warn!("ExFreePoolWithTag: unknown pool type {}", pool_type);
            }
        }
    }
}

pub fn ex_free_pool(ptr: *mut c_void) {
    if ptr.is_null() {
        return;
    }

    unsafe {
        let header = (ptr as *mut u8).sub(POOL_HEADER_SIZE) as *mut PoolHeader;
        let tag = (*header).tag();
        ex_free_poolWithTag(ptr, tag);
    }
}

// ============================================================
// ExAllocatePoolExplicitQuota
// ============================================================

pub fn ex_allocate_pool_explicit_quota(
    pool_type: u32,
    number_of_bytes: usize,
    tag: PoolTag,
    quota_process: *mut c_void,
) -> *mut c_void {
    ex_allocate_pool_with_tag(pool_type, number_of_bytes, tag)
}

// ============================================================
// Internal allocation helpers
// ============================================================

fn mi_allocate_non_paged_pool(size: usize) -> *mut c_void {
    let page_count = (size + PAGE_SIZE_X64 - 1) / PAGE_SIZE_X64;

    // Try contiguous allocation first
    let ptr = super::phys::mm_allocate_contiguous_memory(
        page_count * PAGE_SIZE_X64,
        0xFFFF_FFFF_FFFF_FFFF,
    );

    if !ptr.is_null() {
        unsafe {
            core::ptr::write_bytes(ptr, 0, size);
        }
        return ptr;
    }

    // Fallback: use page allocator
    if let Some(frame) = super::pfn::mi_allocate_pfn() {
        let phys = (frame as u64) << PAGE_SHIFT;
        let pfn = unsafe { super::mi_get_pfn_element(frame) };
        pfn.flags2 |= super::pfn::MMPFN_ACTIVE | super::pfn::MMPFN_ZEROED;
        pfn.reference_count_encoded = 1;

        let virt = super::phys::mm_allocate_contiguous_memory(PAGE_SIZE_X64, 0xFFFF_FFFF_FFFF_FFFF);
        if !virt.is_null() {
            super::pte::mi_create_pte(virt as u64, phys, MmProtectionMask2::READWRITE, 0);
            unsafe { core::ptr::write_bytes(virt, 0, size); }
            return virt;
        }
    }

    core::ptr::null_mut()
}

fn mi_allocate_paged_pool(size: usize) -> *mut c_void {
    mi_allocate_non_paged_pool(size)
}

fn mi_free_non_paged_pool(ptr: *mut c_void, size: usize) {
    if ptr.is_null() {
        return;
    }

    super::phys::mm_free_contiguous_memory(ptr, size);
}

fn mi_free_paged_pool(ptr: *mut c_void, size: usize) {
    mi_free_non_paged_pool(ptr, size);
}

// ============================================================
// Pool header initialization
// ============================================================

fn mi_initialize_pool_header(
    ptr: *mut c_void,
    total_size: usize,
    pool_type: u32,
    tag: PoolTag,
) {
    if ptr.is_null() {
        return;
    }

    unsafe {
        let header = ptr as *mut PoolHeader;
        (*header).previous_size = 0;
        (*header).pool_index = 0;
        (*header).block_size = ((total_size >> 5) & 0xFF) as u8;
        (*header).pool_type = pool_type;
        (*header).process_billed = 0;
        (*header).pool_tag = u32::from_ne_bytes(tag.0);
        (*header).pool_index_2 = 0;
        (*header).pool_type_2 = 0;
    }
}

// ============================================================
// MmAllocatePool (simplified wrapper)
// ============================================================

pub fn mm_allocate_pool_nonpaged(size: usize, tag: PoolTag) -> *mut c_void {
    ex_allocate_pool_with_tag(NON_PAGED_POOL, size, tag)
}

pub fn mm_allocate_pool_paged(size: usize, tag: PoolTag) -> *mut c_void {
    ex_allocate_pool_with_tag(PAGED_POOL, size, tag)
}

pub fn mm_free_pool(ptr: *mut c_void, tag: PoolTag) {
    ex_free_poolWithTag(ptr, tag)
}

pub fn mm_reallocate_pool(
    old_ptr: *mut c_void,
    old_size: usize,
    new_size: usize,
) -> *mut c_void {
    if old_ptr.is_null() {
        return mm_allocate_pool_nonpaged(new_size, TAG_POOL);
    }

    let new_ptr = mm_allocate_pool_nonpaged(new_size, TAG_POOL);
    if new_ptr.is_null() {
        return core::ptr::null_mut();
    }

    unsafe {
        let copy_size = if old_size < new_size { old_size } else { new_size };
        core::ptr::copy_nonoverlapping(old_ptr as *const u8, new_ptr as *mut u8, copy_size);
    }

    mm_free_pool(old_ptr, TAG_POOL);
    new_ptr
}

// ============================================================
// Pool statistics
// ============================================================

pub fn mm_pool_get_nonpaged_allocated() -> usize {
    NON_PAGED_POOL_DESC.lock().used_pages as usize * PAGE_SIZE_X64
}

pub fn mm_pool_get_paged_allocated() -> usize {
    PAGED_POOL_DESC.lock().used_pages as usize * PAGE_SIZE_X64
}

// ============================================================
// MmPoolInit
// ============================================================

pub fn mm_pool_init() {
    ex_initialize_pool_descriptor(NON_PAGED_POOL, 0);
    ex_initialize_pool_descriptor(PAGED_POOL, 0);

    mm_dbg!("MmPoolInit: pool subsystem initialized");
}

pub unsafe fn ex_allocate_nonpaged_cache_aligned(size: usize) -> *mut c_void {
    let aligned = (size + 63) & !63;
    let ptr = alloc::alloc::alloc(core::alloc::Layout::from_size_align_unchecked(aligned, 64));
    ptr as *mut c_void
}

pub unsafe fn ex_allocate_pool_internal(pool_type: u32, size: usize, tag: PoolTag) -> *mut c_void {
    ex_allocate_pool_with_tag(pool_type, size, tag)
}
