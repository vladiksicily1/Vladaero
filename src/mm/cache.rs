//! # Cache Manager Interface
//!
//! CcInitializeCacheManager, CcFileCacheInit, and cache map operations
//! matching ntoskrnl.exe's cache manager subsystem.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use super::{
    MmPteFlags, MmProtectionMask2, ListEntry, SpinLock, Mutex, PoolTag, TAG_CC,
    SEC_COMMIT, SEC_FILE,
    PAGE_SHIFT, PAGE_SIZE_X64,
    mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Cache map entry
// ============================================================

#[repr(C)]
pub struct CacheMapEntry {
    pub link: ListEntry,
    pub file_object: *mut c_void,
    pub file_size: u64,
    pub section: *mut super::section::SectionObject,
    pub section_size: u64,
    pub valid_data_length: u64,
    pub maps: ListEntry,
    pub number_of_views: u32,
    pub flags: u32,
    pub shared_cache_map: *mut SharedCacheMap,
    pub file_ref_count: u32,
}

impl CacheMapEntry {
    pub fn new() -> Self {
        Self {
            link: ListEntry::new(),
            file_object: core::ptr::null_mut(),
            file_size: 0,
            section: core::ptr::null_mut(),
            section_size: 0,
            valid_data_length: 0,
            maps: ListEntry::new(),
            number_of_views: 0,
            flags: 0,
            shared_cache_map: core::ptr::null_mut(),
            file_ref_count: 0,
        }
    }
}

// ============================================================
// Shared cache map
// ============================================================

#[repr(C)]
pub struct SharedCacheMap {
    pub node_type_code: u16,
    pub node_byte_size: u16,
    pub dirty_pages: u32,
    pub pinned_pages: u32,
    pub file_object: *mut c_void,
    pub section: *mut super::section::SectionObject,
    pub section_size: u64,
    pub valid_data_length: u64,
    pub valid_data_goal: u64,
    pub lazy_write_context: *mut c_void,
    pub shared_cache_map_links: ListEntry,
    pub page_sizes: u32,
    pub flags: u32,
    pub fault_count: u32,
    pub mb_count: u32,
    pub file_size: u64,
    pub status: u32,
    pub dirty_pages_threshold: u32,
    pub dirty_page_count: u32,
    pub view_lock: SpinLock,
    pub views: ListEntry,
}

impl SharedCacheMap {
    pub fn new() -> Self {
        Self {
            node_type_code: 0x4366, // 'Cf'
            node_byte_size: core::mem::size_of::<Self>() as u16,
            dirty_pages: 0,
            pinned_pages: 0,
            file_object: core::ptr::null_mut(),
            section: core::ptr::null_mut(),
            section_size: 0,
            valid_data_length: 0,
            valid_data_goal: 0,
            lazy_write_context: core::ptr::null_mut(),
            shared_cache_map_links: ListEntry::new(),
            page_sizes: 1,
            flags: 0,
            fault_count: 0,
            mb_count: 0,
            file_size: 0,
            status: 0,
            dirty_pages_threshold: 1024,
            dirty_page_count: 0,
            view_lock: SpinLock::new(),
            views: ListEntry::new(),
        }
    }
}

// ============================================================
// Cache view
// ============================================================

#[repr(C)]
pub struct CacheView {
    pub view_links: ListEntry,
    pub shared_cache_map: *mut SharedCacheMap,
    pub file_offset: u64,
    pub size: u64,
    pub bcb: *mut c_void,
    pub page_array: *mut u64,
    pub number_of_pages: u32,
    pub flags: u32,
    pub valid_pages: u32,
}

impl CacheView {
    pub fn new() -> Self {
        Self {
            view_links: ListEntry::new(),
            shared_cache_map: core::ptr::null_mut(),
            file_offset: 0,
            size: 0,
            bcb: core::ptr::null_mut(),
            page_array: core::ptr::null_mut(),
            number_of_pages: 0,
            flags: 0,
            valid_pages: 0,
        }
    }
}

// ============================================================
// Lazy writer
// ============================================================

#[repr(C)]
pub struct LazyWriter {
    pub work_queue: ListEntry,
    pub work_lock: SpinLock,
    pub number_of_work_items: u32,
    pub active: bool,
    pub torn_flush: bool,
    pub write_ticket: u32,
    pub pages_written: u32,
    pub flush_in_progress: bool,
    pub last_flush_time: u64,
}

impl LazyWriter {
    pub const fn new() -> Self {
        Self {
            work_queue: ListEntry::new(),
            work_lock: SpinLock::new(),
            number_of_work_items: 0,
            active: false,
            torn_flush: false,
            write_ticket: 0,
            pages_written: 0,
            flush_in_progress: false,
            last_flush_time: 0,
        }
    }
}

// ============================================================
// Global cache manager state
// ============================================================

static CC_INITIALIZED: AtomicU32 = AtomicU32::new(0);
static CC_CACHE_MAP_LIST: Mutex<ListEntry> = Mutex::new(ListEntry::new());
static CC_SHARED_CACHE_MAP_LIST: Mutex<ListEntry> = Mutex::new(ListEntry::new());
static CC_TOTAL_CACHE_SIZE: AtomicUsize = AtomicUsize::new(0);
static CC_DIRTY_PAGE_COUNT: AtomicUsize = AtomicUsize::new(0);
static CC_FAULT_COUNT: AtomicU64 = AtomicU64::new(0);
static CC_MAXIMUM_CACHE_SIZE: AtomicUsize = AtomicUsize::new(0x8000000); // 128 MB default
static CC_FLUSH_TICKET: AtomicU32 = AtomicU32::new(0);

static mut CC_LAZY_WRITER: LazyWriter = LazyWriter::new();

// ============================================================
// CcInitializeCacheManager
// ============================================================

pub fn cc_initialize_cache_manager() {
    if CC_INITIALIZED.compare_exchange(0, 1, Ordering::Acquire, Ordering::Relaxed).is_err() {
        return;
    }

    unsafe {
        CC_LAZY_WRITER = LazyWriter::new();
    }

    let mut cache_list = CC_CACHE_MAP_LIST.lock();
    cache_list.initialize();

    let mut shared_list = CC_SHARED_CACHE_MAP_LIST.lock();
    shared_list.initialize();

    CC_TOTAL_CACHE_SIZE.store(0, Ordering::Relaxed);
    CC_DIRTY_PAGE_COUNT.store(0, Ordering::Relaxed);
    CC_FAULT_COUNT.store(0, Ordering::Relaxed);

    mm_dbg!("CcInitializeCacheManager: cache manager initialized, max_size={:#x}",
        CC_MAXIMUM_CACHE_SIZE.load(Ordering::Relaxed));
}

// ============================================================
// CcFileCacheInit
// ============================================================

pub fn cc_file_cache_init() {
    if CC_INITIALIZED.load(Ordering::Relaxed) == 0 {
        cc_initialize_cache_manager();
    }

    mm_dbg!("CcFileCacheInit: file cache initialized");
}

// ============================================================
// CcInitializeCacheMap
// ============================================================

pub fn cc_initialize_cache_map(
    file_object: *mut c_void,
    file_size: u64,
    cache_flags: u32,
    call_back: u64,
    call_back_context: u64,
) -> NtStatus {
    if file_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let cache_map = super::pool::mm_allocate_pool_nonpaged(
        core::mem::size_of::<CacheMapEntry>(),
        TAG_CC,
    ) as *mut CacheMapEntry;

    if cache_map.is_null() {
        return STATUS_NO_MEMORY;
    }

    unsafe {
        core::ptr::write_bytes(cache_map as *mut u8, 0, core::mem::size_of::<CacheMapEntry>());
        (*cache_map).file_object = file_object;
        (*cache_map).file_size = file_size;
    }

    // Create section for cache
    let mut section: *mut super::section::SectionObject = core::ptr::null_mut();
    let status = super::section::mm_create_section(
        &mut section,
        0,
        core::ptr::null_mut(),
        file_size,
        0x04, // PAGE_READWRITE
        SEC_COMMIT | SEC_FILE,
        file_object,
    );

    if status != STATUS_SUCCESS {
        super::pool::mm_free_pool(cache_map as *mut c_void, TAG_CC);
        return status;
    }

    unsafe {
        (*cache_map).section = section;
        (*cache_map).section_size = file_size;
    }

    // Create shared cache map
    let shared_map = super::pool::mm_allocate_pool_nonpaged(
        core::mem::size_of::<SharedCacheMap>(),
        TAG_CC,
    ) as *mut SharedCacheMap;

    if !shared_map.is_null() {
        unsafe {
            core::ptr::write_bytes(shared_map as *mut u8, 0, core::mem::size_of::<SharedCacheMap>());
            (*shared_map).file_object = file_object;
            (*shared_map).section = section;
            (*shared_map).section_size = file_size;
            (*shared_map).file_size = file_size;

            (*cache_map).shared_cache_map = shared_map;

            let mut shared_list = CC_SHARED_CACHE_MAP_LIST.lock();
            (*shared_map).shared_cache_map_links.initialize();
            shared_list.insert_tail(&mut (*shared_map).shared_cache_map_links);
            drop(shared_list);
        }
    }

    let mut cache_list = CC_CACHE_MAP_LIST.lock();
    unsafe {
        (*cache_map).link.initialize();
        cache_list.insert_tail(&mut (*cache_map).link);
    }
    drop(cache_list);

    CC_TOTAL_CACHE_SIZE.fetch_add(file_size as usize, Ordering::Relaxed);

    mm_trace!("CcInitializeCacheMap: initialized cache for {:p} size={:#x}", file_object, file_size);
    STATUS_SUCCESS
}

// ============================================================
// CcGetFileObjectFromBcb
// ============================================================

pub fn cc_get_file_object_from_bcb(bcb: *mut c_void) -> *mut c_void {
    if bcb.is_null() {
        return core::ptr::null_mut();
    }

    unsafe {
        let view = &*(bcb as *const CacheView);
        if !view.shared_cache_map.is_null() {
            (*view.shared_cache_map).file_object
        } else {
            core::ptr::null_mut()
        }
    }
}

// ============================================================
// CcPinRead
// ============================================================

pub fn cc_pin_read(
    file_object: *mut c_void,
    file_offset: u64,
    length: u64,
    bcb: *mut *mut c_void,
    buffer: *mut *mut c_void,
) -> NtStatus {
    if file_object.is_null() || bcb.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Simplified: allocate a view and map the data
    let view = super::pool::mm_allocate_pool_nonpaged(
        core::mem::size_of::<CacheView>(),
        TAG_CC,
    ) as *mut CacheView;

    if view.is_null() {
        return STATUS_NO_MEMORY;
    }

    unsafe {
        core::ptr::write_bytes(view as *mut u8, 0, core::mem::size_of::<CacheView>());
        (*view).file_offset = file_offset;
        (*view).size = length;
    }

    // Map pages
    let page_count = (length + PAGE_SIZE_X64 as u64 - 1) / PAGE_SIZE_X64 as u64;
    let pages = super::phys::mm_allocate_pages_for_mdl(
        0, 0xFFFF_FFFF_FFFF_FFFF, 0, page_count * PAGE_SIZE_X64 as u64,
    );

    if !pages.is_null() {
        unsafe {
            (*view).page_array = (*pages).start_va as *mut u64;
            (*view).number_of_pages = page_count as u32;
        }
    }

    unsafe {
        *bcb = view as *mut c_void;
        if !buffer.is_null() {
            *buffer = (*view).page_array as *mut c_void;
        }
    }

    CC_FAULT_COUNT.fetch_add(1, Ordering::Relaxed);

    STATUS_SUCCESS
}

// ============================================================
// CcPinMappedData
// ============================================================

pub fn cc_pin_mapped_data(
    file_object: *mut c_void,
    file_offset: u64,
    length: u64,
    wait: bool,
    bcb: *mut *mut c_void,
) -> NtStatus {
    cc_pin_read(file_object, file_offset, length, bcb, core::ptr::null_mut())
}

// ============================================================
// CcUnpinData
// ============================================================

pub fn cc_unpin_data(bcb: *mut c_void) {
    if bcb.is_null() {
        return;
    }

    unsafe {
        let view = bcb as *mut CacheView;
        if !(*view).page_array.is_null() {
            // Pages are managed by the MDL, no need to free individually
        }
        super::pool::mm_free_pool(view as *mut c_void, TAG_CC);
    }
}

// ============================================================
// CcSetDirtyPinnedData
// ============================================================

pub fn cc_set_dirty_pinned_data(bcb: *mut c_void, lsn: u64) {
    if bcb.is_null() {
        return;
    }

    CC_DIRTY_PAGE_COUNT.fetch_add(1, Ordering::Relaxed);
}

// ============================================================
// CcSetDataMapInformation
// ============================================================

pub fn cc_set_data_map_information(
    file_object: *mut c_void,
    flags: u32,
    dirty_page_count: u32,
) {
    // Update dirty page tracking
}

// ============================================================
// CcGetDirtyPages
// ============================================================

pub fn cc_get_dirty_pages(
    log_handle: *mut c_void,
    dirty_callback: unsafe extern "C" fn(*mut c_void, *mut c_void, u64),
    context: *mut c_void,
) -> NtStatus {
    if (dirty_callback as *const ()).is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Walk shared cache maps looking for dirty pages
    let shared_list = CC_SHARED_CACHE_MAP_LIST.lock();

    unsafe {
        let mut entry = (*(&*shared_list)).flink;
        while entry != &*shared_list as *const ListEntry as *mut ListEntry {
            if entry.is_null() {
                break;
            }

            let shared_map = (entry as *mut u8).sub(
                core::mem::offset_of!(SharedCacheMap, shared_cache_map_links)
            ) as *mut SharedCacheMap;

            if (*shared_map).dirty_page_count > 0 {
                dirty_callback(log_handle, shared_map as *mut c_void, 0);
            }

            entry = (*entry).flink;
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// CcCoherencyFlushAndPurgePages
// ============================================================

pub fn cc_coherency_flush_and_purge_pages(
    section_object: *mut c_void,
    file_offset: u64,
    length: u64,
    los: *mut u64,
    bytes_flushed: *mut u64,
    flags: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// CcRemapBcb
// ============================================================

pub fn cc_remap_bcb(bcb: *mut c_void) -> *mut c_void {
    bcb
}

// ============================================================
// CcAdjustNumberOfSharedCacheMaps
// ============================================================

pub fn cc_adjust_number_of_shared_cache_maps(
    current_process: *mut c_void,
    quota_charge: i64,
    cache_size: u64,
    flags: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// CcGetFileObjectForBcb
// ============================================================

pub fn cc_get_file_object_for_bcb(bcb: *mut c_void) -> *mut c_void {
    cc_get_file_object_from_bcb(bcb)
}

// ============================================================
// Lazy writer
// ============================================================

pub fn cc_lazy_writer_scan() {
    if CC_INITIALIZED.load(Ordering::Relaxed) == 0 {
        return;
    }

    unsafe {
        let _guard = CC_LAZY_WRITER.work_lock.acquire();
        CC_LAZY_WRITER.active = true;
    }

    // Scan for dirty pages and write them out
    let dirty = CC_DIRTY_PAGE_COUNT.load(Ordering::Relaxed);

    if dirty > 0 {
        mm_trace!("CcLazyWriter: flushing {} dirty pages", dirty);
    }

    unsafe {
        let _guard = CC_LAZY_WRITER.work_lock.acquire();
        CC_LAZY_WRITER.active = false;
        CC_LAZY_WRITER.write_ticket = CC_FLUSH_TICKET.fetch_add(1, Ordering::Relaxed);
    }
}

// ============================================================
// Cache size management
// ============================================================

pub fn cc_set_maximum_cache_size(max_size: usize) {
    CC_MAXIMUM_CACHE_SIZE.store(max_size, Ordering::Relaxed);
}

pub fn cc_get_cache_size() -> usize {
    CC_TOTAL_CACHE_SIZE.load(Ordering::Relaxed)
}

pub fn cc_get_dirty_page_count() -> usize {
    CC_DIRTY_PAGE_COUNT.load(Ordering::Relaxed)
}

pub fn cc_get_fault_count() -> u64 {
    CC_FAULT_COUNT.load(Ordering::Relaxed)
}
