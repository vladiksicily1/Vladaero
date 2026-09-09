//! # Physical Memory Allocation
//!
//! Contiguous memory allocation, MDL-based allocation, and the
//! page frame free list, matching ntoskrnl.exe routines.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use super::{
    MmPte, MmPteFlags, MmProtectionMask2, MmPfn, MmMdl, ListEntry, SpinLock,
    PAGE_SHIFT, PAGE_SIZE_X64, PAGE_MASK_X64,
    TAG_MMDL, TAG_POOL, MDL_MAPPED_TO_SYSTEM_VA, MDL_PAGES_LOCKED,
    mi_get_pfn_element, mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Memory range descriptors
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MmMemoryRangeDescriptor {
    pub base_address: u64,
    pub size: u64,
    pub flags: u32,
    pub type_field: u32,
    pub attributes: u64,
}

pub const MI_MM_RANGE_RAM: u32 = 0x0001;
pub const MI_MM_RANGE_RESERVED: u32 = 0x0002;
pub const MI_MM_RANGE_ACPI: u32 = 0x0004;
pub const MI_MM_RANGE_NVS: u32 = 0x0008;

// ============================================================
// Large page descriptor
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MiLargePageDescriptor {
    pub base_address: u64,
    pub size: u64,
    pub is_large_page: bool,
    pub page_count: u32,
}

// ============================================================
// Contiguous memory allocator state
// ============================================================

static CONTIGUOUS_LOCK: SpinLock = SpinLock::new();
static CONTIGUOUS_BASE: AtomicU64 = AtomicU64::new(0);
static CONTIGUOUS_SIZE: AtomicUsize = AtomicUsize::new(0);

// ============================================================
// MmAllocateContiguousMemory
// ============================================================

pub fn mm_allocate_contiguous_memory(size: usize, highest_address: u64) -> *mut c_void {
    if size == 0 {
        return core::ptr::null_mut();
    }

    let aligned_size = (size + PAGE_SIZE_X64 - 1) & PAGE_MASK_X64;
    let _guard = CONTIGUOUS_LOCK.acquire();

    // Try free list first
    let page_count = aligned_size / PAGE_SIZE_X64;
    let order = page_count.next_power_of_two().trailing_zeros();

    if let Some(frame) = super::pfn::mi_allocate_pfn() {
        let phys = (frame as u64) << PAGE_SHIFT;
        if phys <= highest_address {
            let pfn = unsafe { mi_get_pfn_element(frame) };
            pfn.flags2 |= super::pfn::MMPFN_ACTIVE | super::pfn::MMPFN_LOCKED;
            pfn.reference_count_encoded = 1;

            // Allocate a virtual address to map this physical page.
            // We cannot call mm_allocate_contiguous_memory recursively,
            // so we use a simple bump allocator for the kernel heap.
            let virt = unsafe {
                alloc::alloc::alloc(
                    core::alloc::Layout::from_size_align_unchecked(aligned_size, PAGE_SIZE_X64)
                ) as *mut c_void
            };
            if !virt.is_null() {
                super::pte::mi_create_pte(
                    virt as u64, phys, MmProtectionMask2::READWRITE, 0
                );
                return virt;
            } else {
                // Free the physical page if we can't get virtual memory
                unsafe { super::pfn::mi_release_pfn(frame, 0); }
            }
        }
    }

    // Fallback: carve from static reserved area
    let base = CONTIGUOUS_BASE.load(Ordering::Relaxed);
    let remaining = CONTIGUOUS_SIZE.load(Ordering::Relaxed);

    if remaining >= aligned_size {
        let addr = base;
        CONTIGUOUS_BASE.store(base + aligned_size as u64, Ordering::Relaxed);
        CONTIGUOUS_SIZE.store(remaining - aligned_size, Ordering::Relaxed);

        let pfn_num = (addr >> PAGE_SHIFT) as usize;
        if pfn_num < super::MmPfnDatabaseLength.load(Ordering::Relaxed) {
            let pfn = unsafe { mi_get_pfn_element(pfn_num) };
            pfn.flags2 |= super::pfn::MMPFN_ACTIVE | super::pfn::MMPFN_LOCKED;
            pfn.reference_count_encoded = 1;
        }

        return addr as *mut c_void;
    }

    mm_warn!("MmAllocateContiguousMemory: failed to allocate {} bytes", size);
    core::ptr::null_mut()
}

// ============================================================
// MmFreeContiguousMemory
// ============================================================

pub fn mm_free_contiguous_memory(base: *mut c_void, size: usize) {
    if base.is_null() || size == 0 {
        return;
    }

    let aligned_size = (size + PAGE_SIZE_X64 - 1) & PAGE_MASK_X64;
    let _guard = CONTIGUOUS_LOCK.acquire();

    let phys = base as u64;
    let pfn_num = (phys >> PAGE_SHIFT) as usize;

    if pfn_num < super::MmPfnDatabaseLength.load(Ordering::Relaxed) {
        let pfn = unsafe { mi_get_pfn_element(pfn_num) };
        pfn.flags2 &= !(super::pfn::MMPFN_ACTIVE | super::pfn::MMPFN_LOCKED);
        pfn.reference_count_encoded = 0;
    }

    let page_count = aligned_size / PAGE_SIZE_X64;
    for i in 0..page_count {
        let pfn = pfn_num + i;
        if pfn < super::MmPfnDatabaseLength.load(Ordering::Relaxed) {
            unsafe {
                let pfn_entry = mi_get_pfn_element(pfn);
                pfn_entry.flags2 = 0;
                pfn_entry.reference_count_encoded = 0;
            }
        }
    }

    // Unmap virtual addresses        let page_count = aligned_size / PAGE_SIZE_X64;
        for i in 0..page_count {
            let va = base as u64 + (i as u64) * (PAGE_SIZE_X64 as u64);
            super::pte::mi_make_pte_not_valid(va, &mut MmPte {
                flags: MmPteFlags::empty(),
                page_frame_number: 0,
                protection: MmProtectionMask2::empty(),
            });
        }

        // Free the kernel heap allocation
        unsafe {
            alloc::alloc::dealloc(
                base as *mut u8,
                core::alloc::Layout::from_size_align_unchecked(aligned_size, PAGE_SIZE_X64)
            );
        }

        mm_trace!("MmFreeContiguousMemory: freed {} bytes at {:p}", size, base);
}

// ============================================================
// MmAllocateContiguousMemorySpecifyCache
// ============================================================

pub fn mm_allocate_contiguous_memory_specify_cache(
    size: usize,
    lowest_address: u64,
    highest_address: u64,
    caching_type: MemoryCachingType,
    block_alignment: u64,
) -> *mut c_void {
    mm_allocate_contiguous_memory(size, highest_address)
}

// ============================================================
// MmAllocatePagesForMdl
// ============================================================

pub fn mm_allocate_pages_for_mdl(
    low_address: u64,
    high_address: u64,
    skip_bytes: u64,
    total_bytes: u64,
) -> *mut MmMdl {
    let page_count = ((total_bytes + PAGE_SIZE_X64 as u64 - 1) / PAGE_SIZE_X64 as u64) as usize;
    if page_count == 0 {
        return core::ptr::null_mut();
    }

    let mdl_size = core::mem::size_of::<MmMdl>() + page_count * core::mem::size_of::<u64>();
    let mdl = super::pool::mm_allocate_pool_nonpaged(mdl_size, TAG_MMDL) as *mut MmMdl;
    if mdl.is_null() {
        return core::ptr::null_mut();
    }

    unsafe {
        (*mdl).byte_count = total_bytes;
        (*mdl).start_va = core::ptr::null_mut();
        (*mdl).byte_offset = 0;
        (*mdl).process = core::ptr::null_mut();
        (*mdl).next = core::ptr::null_mut();
    }

    let mut allocated = 0usize;
    for i in 0..page_count {
        let frame = super::pfn::mi_allocate_pfn().unwrap_or_else(|| {
            return 0usize;
        });

        if frame == 0 {
            break;
        }

        let pfn = unsafe { mi_get_pfn_element(frame) };
        let phys_addr = (frame as u64) << PAGE_SHIFT;

        if phys_addr < low_address || phys_addr > high_address {
            unsafe { super::pfn::mi_release_pfn(frame, 0); }
            continue;
        }

        pfn.flags2 |= super::pfn::MMPFN_ACTIVE | super::pfn::MMPFN_LOCKED;
        pfn.reference_count_encoded = 1;

        // Store physical address in the MDL's page frame number array
        unsafe {
            let mdl_page = (mdl as *mut u8).add(core::mem::size_of::<MmMdl>())
                as *mut u64;
            *mdl_page.add(i) = phys_addr;
        }

        allocated += 1;
    }

    if allocated == 0 {
        super::pool::mm_free_pool(mdl as *mut c_void, TAG_MMDL);
        return core::ptr::null_mut();
    }

    mm_trace!("MmAllocatePagesForMdl: allocated {} pages for MDL", allocated);
    mdl
}

// ============================================================
// MmFreePagesFromMdl
// ============================================================

pub fn mm_free_pages_from_mdl(mdl: *mut MmMdl) {
    if mdl.is_null() {
        return;
    }

    unsafe {
        let page_count = (*mdl).byte_count as usize / PAGE_SIZE_X64;
        let mdl_page = (mdl as *mut u8).add(core::mem::size_of::<MmMdl>()) as *mut u64;

        for i in 0..page_count {
            let phys_addr = *mdl_page.add(i);
            if phys_addr == 0 {
                continue;
            }

            let pfn_num = (phys_addr >> PAGE_SHIFT) as usize;
            if pfn_num < super::MmPfnDatabaseLength.load(Ordering::Relaxed) {
                let pfn = mi_get_pfn_element(pfn_num);
                pfn.flags2 &= !(super::pfn::MMPFN_ACTIVE | super::pfn::MMPFN_LOCKED);
                pfn.reference_count_encoded = 0;

                super::pfn::mi_release_pfn(pfn_num, 0);
            }
        }

        super::pool::mm_free_pool(mdl as *mut c_void, TAG_MMDL);
    }

    mm_trace!("MmFreePagesFromMdl: freed MDL");
}

// ============================================================
// MiAllocatePagesFromFreeList
// ============================================================

pub fn mi_allocate_pages_from_free_list(
    count: usize,
    low_address: u64,
    high_address: u64,
    flags: u32,
) -> Vec<u64> {
    let mut pages = Vec::new();

    for _ in 0..count {
        if let Some(frame) = super::pfn::mi_allocate_pfn() {
            let phys = (frame as u64) << PAGE_SHIFT;

            if phys >= low_address && phys <= high_address {
                let pfn = unsafe { mi_get_pfn_element(frame) };
                pfn.flags2 |= super::pfn::MMPFN_ACTIVE;
                pfn.reference_count_encoded = 1;
                pages.push(phys);
            } else {
                unsafe { super::pfn::mi_release_pfn(frame, 0); }
            }
        } else {
            break;
        }
    }

    pages
}

// ============================================================
// MmAllocatePhysicalMemory
// ============================================================

pub fn mm_allocate_physical_memory(
    size: usize,
    lowest_address: u64,
    highest_address: u64,
    boundary_alignment: u64,
    cache_type: MemoryCachingType,
) -> *mut c_void {
    let aligned_size = (size + PAGE_SIZE_X64 - 1) & PAGE_MASK_X64;
    mm_allocate_contiguous_memory(aligned_size, highest_address)
}

// ============================================================
// MmFreePhysicalMemory
// ============================================================

pub fn mm_free_physical_memory(base: *mut c_void, size: usize) {
    mm_free_contiguous_memory(base, size)
}

// ============================================================
// MmBuildMdlForNonPagedPool (for already allocated non-paged pool)
// ============================================================

pub fn mm_build_mdl_for_non_paged_pool(
    base: *mut c_void,
    size: usize,
) -> *mut MmMdl {
    let page_count = (size + PAGE_SIZE_X64 - 1) / PAGE_SIZE_X64;
    let mdl_size = core::mem::size_of::<MmMdl>() + page_count * core::mem::size_of::<u64>();

    let mdl = super::pool::mm_allocate_pool_nonpaged(mdl_size, TAG_MMDL) as *mut MmMdl;
    if mdl.is_null() {
        return core::ptr::null_mut();
    }

    unsafe {
        (*mdl).start_va = base;
        (*mdl).byte_offset = ((base as usize) & (PAGE_SIZE_X64 - 1)) as u32;
        (*mdl).byte_count = size as u64;
        (*mdl).process = core::ptr::null_mut();
        (*mdl).next = core::ptr::null_mut();
    }

    let base_addr = base as u64;
    for i in 0..page_count {
        let page_phys = base_addr + (i as u64) * (PAGE_SIZE_X64 as u64);

        unsafe {
            let mdl_page = (mdl as *mut u8).add(core::mem::size_of::<MmMdl>()) as *mut u64;
            *mdl_page.add(i) = page_phys;
        }
    }

    mm_trace!("MmBuildMdlForNonPagedPool: built MDL for {:p} size={}", base, size);
    mdl
}

// NOTE: mm_probe_and_lock_pages, mm_unlock_pages, and mm_get_system_address_for_mdl_safe
// are defined in mdl.rs to avoid duplicate symbol errors.

// ============================================================
// MmGetPhysicalAddress
// ============================================================

pub fn mm_get_physical_address(virtual_address: u64) -> u64 {
    let pte_addr = super::pte::mi_get_pte_address(virtual_address);
    let pte = unsafe { &*(pte_addr as *const MmPte) };

    if pte.flags.contains(MmPteFlags::VALID) {
        (pte.page_frame_number << PAGE_SHIFT) | (virtual_address & 0xFFF)
    } else {
        0
    }
}

// ============================================================
// Vec helper (minimal no_std Vec using pool alloc)
// ============================================================

pub struct Vec<T> {
    ptr: *mut T,
    len: usize,
    cap: usize,
}

impl<T> Vec<T> {
    pub fn new() -> Self {
        Self {
            ptr: core::ptr::null_mut(),
            len: 0,
            cap: 0,
        }
    }

    pub fn push(&mut self, val: T) {
        if self.len >= self.cap {
            let new_cap = if self.cap == 0 { 4 } else { self.cap * 2 };
            let new_layout = core::alloc::Layout::array::<T>(new_cap).unwrap();
            unsafe {
                let new_ptr = if self.ptr.is_null() {
                    super::pool::mm_allocate_pool_nonpaged(new_layout.size(), TAG_POOL)
                } else {
                    super::pool::mm_reallocate_pool(
                        self.ptr as *mut c_void,
                        self.cap * core::mem::size_of::<T>(),
                        new_layout.size(),
                    )
                };
                if new_ptr.is_null() {
                    return;
                }
                self.ptr = new_ptr as *mut T;
                self.cap = new_cap;
            }
        }
        unsafe {
            core::ptr::write(self.ptr.add(self.len), val);
        }
        self.len += 1;
    }

    pub fn len(&self) -> usize {
        self.len
    }
}

impl<T> Drop for Vec<T> {
    fn drop(&mut self) {
        if !self.ptr.is_null() {
            unsafe {
                core::ptr::drop_in_place(core::slice::from_raw_parts_mut(self.ptr, self.len));
                super::pool::mm_free_pool(self.ptr as *mut c_void, TAG_POOL);
            }
        }
    }
}
