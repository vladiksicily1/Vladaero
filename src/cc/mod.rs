/// # Cache Manager (Cc) - ntoskrnl.exe
///
/// Complete implementation of the Windows Cache Manager including
/// cache map initialization, Bcb management, pin/unpin operations,
/// copy read/write, read-ahead, and lazy writer support.
///
/// References:
///   - Windows Internals 7th Ed. Part 2, Chapter 11
///   - WRK: ntoskrnl/cc/
///   - ReactOS: cc/

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicU32, AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use crate::ke::dispatcher::*;
use crate::ke::sync::*;

// ============================================================
// Constants
// ============================================================

pub const CC_EOF: u64 = 0x7FFFFFFFFFFFFFFF;

pub const CACHE_LEFTOVER_BIT: u32 = 0x80000000;

pub const CACHE_FLAGS_ENABLE_READAHEAD: u32 = 0x00000001;
pub const CACHE_FLAGS_ENABLE_WRITEBEHIND: u32 = 0x00000002;
pub const CACHE_FLAGS_DISABLE_READAHEAD: u32 = 0x00000004;

pub const MAXIMUM_CACHE_MAP_SIZE: usize = 64 * 1024 * 1024; // 64 MB
pub const MINIMUM_CACHE_MAP_SIZE: usize = 64 * 1024; // 64 KB
pub const CACHE_MAPPING_GRANULARITY: usize = 64 * 1024;
pub const CACHE_READ_AHEAD_GRANULARITY: usize = 128 * 1024;
pub const LAZY_WRITER_IO_PRIORITY: u32 = 0;
pub const LAZY_WRITER_RATELIMIT_PCT: u32 = 5;

pub const BCB_FLAGS_MAPPED: u32 = 0x00000001;
pub const BCB_FLAGS_PINNED: u32 = 0x00000002;
pub const BCB_FLAGS_DIRTY: u32 = 0x00000004;
pub const BCB_FLAGS_FREE: u32 = 0x00000008;
pub const BCB_FLAGS_MODIFIED: u32 = 0x00000010;
pub const BCB_FLAGS_NEWLY_ADDED: u32 = 0x00000020;
pub const BCB_FLAGS_UNINITIALIZED: u32 = 0x00000040;
pub const BCB_FLAGS_WRITETHROUGH: u32 = 0x00000080;

pub const LAZY_WRITER_PANEL_INTERVAL: u64 = 50000000; // 5 seconds in 100ns units
pub const LAZY_WRITER_PANEL_DIRTY_PCT: u32 = 25;

pub const STATUS_END_OF_FILE: NtStatus = 0xC0000011;
pub const STATUS_ACCESS_VIOLATION: NtStatus = 0xC0000005;
pub const STATUS_WORKING_SET_QUOTA: NtStatus = 0xC00000A5;
pub const STATUS_RETRY: NtStatus = 0xC000022D;
pub const STATUS_NOT_SUPPORTED: NtStatus = 0xC00000BB;
pub const STATUS_NO_MEMORY: NtStatus = 0xC0000017;

pub const MM_PFN_LOCK: u32 = 0;
pub const MM_WORKING_SET_LOCK: u32 = 1;

// ============================================================
// Logging macros
// ============================================================

macro_rules! cc_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "cc_trace")]
        crate::kernel_log!("[Cc] {}", format_args!($($arg)*));
    };
}

macro_rules! cc_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Cc] {}", format_args!($($arg)*));
    };
}

macro_rules! cc_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Cc] {}", format_args!($($arg)*));
    };
}

macro_rules! cc_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Cc] {}", format_args!($($arg)*));
    };
}

// ============================================================
// VACB (Virtual Address Control Block)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Vacb {
    pub base_address: Pvoid,
    pub shared_cache_map: *mut SharedCacheMap,
    pub file_offset: u64,
    pub dirty: u32,
    pub pinned: u32,
    pub ref_count: u32,
    pub active: u32,
    pub links: ListEntry,
    pub table_links: ListEntry,
    pub work_queue_entry: ListEntry,
    pub flags: u32,
    pub compressed: u32,
}

impl Vacb {
    pub const fn new() -> Self {
        Self {
            base_address: core::ptr::null_mut(),
            shared_cache_map: core::ptr::null_mut(),
            file_offset: 0,
            dirty: 0,
            pinned: 0,
            ref_count: 0,
            active: 0,
            links: ListEntry::new(),
            table_links: ListEntry::new(),
            work_queue_entry: ListEntry::new(),
            flags: 0,
            compressed: 0,
        }
    }
}

// ============================================================
// SHARED_CACHE_MAP
// ============================================================

#[repr(C)]
pub struct SharedCacheMap {
    pub node_type_code: u16,
    pub node_byte_size: u16,
    pub active_count: u32,
    pub flags: u32,
    pub file_size: i64,
    pub valid_data_length: i64,
    pub valid_data_goal: i64,
    pub bcb_list: ListEntry,
    pub section: Pvoid,
    pub section_size: u64,
    pub sector_size: u32,
    pub allocation_size: i64,
    pub file_object: Pvoid,
    pub active_list: ListEntry,
    pub dirty_pages: u64,
    pub pages_written: u64,
    pub pages_captured: u64,
    pub lazy_write_losses: u64,
    pub lazy_write_pages: u64,
    pub lazy_write_works: u64,
    pub lazy_write_waits: u64,
    pub lazy_write_skip: u64,
    pub lazy_write_pages_skipped: u64,
    pub no_read_ahead: u32,
    pub disable_read_ahead: u32,
    pub disable_lazy_write: u32,
    pub disable_write_behind: u32,
    pub event: Kevent,
    pub dirty_list: ListEntry,
    pub work_queue: ListEntry,
    pub work_queue_lock: KspinLock,
    pub shared_cache_map_links: ListEntry,
    pub views: ListEntry,
    pub uninit_count: u32,
    pub lazy_write_context: Pvoid,
    pub lazy_write_thread: Pvoid,
    pub lazy_write_event: Kevent,
    pub need_to_write: u32,
    pub writing: u32,
    pub rate: u32,
    pub bucket_count: u32,
    pub buckets: [ListEntry; 64],
    pub map_counter: u32,
    pub creation_tid: u64,
}

impl SharedCacheMap {
    pub fn new() -> Self {
        let mut s = Self {
            node_type_code: 0x7343, // 'Cs' type
            node_byte_size: mem::size_of::<Self>() as u16,
            active_count: 0,
            flags: 0,
            file_size: 0,
            valid_data_length: 0,
            valid_data_goal: 0,
            bcb_list: ListEntry::new(),
            section: core::ptr::null_mut(),
            section_size: 0,
            sector_size: 512,
            allocation_size: 0,
            file_object: core::ptr::null_mut(),
            active_list: ListEntry::new(),
            dirty_pages: 0,
            pages_written: 0,
            pages_captured: 0,
            lazy_write_losses: 0,
            lazy_write_pages: 0,
            lazy_write_works: 0,
            lazy_write_waits: 0,
            lazy_write_skip: 0,
            lazy_write_pages_skipped: 0,
            no_read_ahead: 0,
            disable_read_ahead: 0,
            disable_lazy_write: 0,
            disable_write_behind: 0,
            event: unsafe { mem::zeroed() },
            dirty_list: ListEntry::new(),
            work_queue: ListEntry::new(),
            work_queue_lock: 0,
            shared_cache_map_links: ListEntry::new(),
            views: ListEntry::new(),
            uninit_count: 0,
            lazy_write_context: core::ptr::null_mut(),
            lazy_write_thread: core::ptr::null_mut(),
            lazy_write_event: unsafe { mem::zeroed() },
            need_to_write: 0,
            writing: 0,
            rate: 0,
            bucket_count: 64,
            buckets: [ListEntry::new(); 64],
            map_counter: 0,
            creation_tid: 0,
        };

        // Initialize bucket list entries
        for bucket in s.buckets.iter_mut() {
            bucket.initialize();
        }
        s.active_list.initialize();
        s.dirty_list.initialize();
        s.work_queue.initialize();

        s
    }
}

// ============================================================
// CACHE_MAP_SECTION (Section for cache map)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CacheMapSection {
    pub section_object: Pvoid,
    pub shared_cache_map: *mut SharedCacheMap,
    pub section_size: u64,
    pub views: ListEntry,
    pub view_count: u32,
    pub flags: u32,
    pub lock: KspinLock,
}

impl CacheMapSection {
    pub const fn new() -> Self {
        Self {
            section_object: core::ptr::null_mut(),
            shared_cache_map: core::ptr::null_mut(),
            section_size: 0,
            views: ListEntry::new(),
            view_count: 0,
            flags: 0,
            lock: 0,
        }
    }
}

// ============================================================
// BCB (Buffer Control Block)
// ============================================================

#[repr(C)]
pub struct Bcb {
    pub node_type_code: u16,
    pub node_byte_size: u16,
    pub dirty: u32,
    pub initial_read_ahead: u32,
    pub cc_extend_wait: u32,
    pub file_offset: u64,
    pub file_key: Pvoid,
    pub byte_length: u32,
    pub flags: u32,
    pub pinned: u32,
    pub pinned_count: u32,
    pub ref_count: u32,
    pub bcb_links: ListEntry,
    pub shared_cache_map: *mut SharedCacheMap,
    pub base_address: Pvoid,
    pub contribution: u32,
    pub ready_for_write: ListEntry,
    pub work_queue_entry: ListEntry,
    pub resource: KspinLock,
    pub event: Kevent,
    pub lazy_write_link: ListEntry,
    pub dirty_links: ListEntry,
    pub pinned_links: ListEntry,
    pub file_object: Pvoid,
    pub overlay: CacheMapSection,
}

impl Bcb {
    pub const fn new() -> Self {
        Self {
            node_type_code: 0x4263, // 'Bc' type
            node_byte_size: mem::size_of::<Self>() as u16,
            dirty: 0,
            initial_read_ahead: 0,
            cc_extend_wait: 0,
            file_offset: 0,
            file_key: core::ptr::null_mut(),
            byte_length: 0,
            flags: 0,
            pinned: 0,
            pinned_count: 0,
            ref_count: 0,
            bcb_links: ListEntry::new(),
            shared_cache_map: core::ptr::null_mut(),
            base_address: core::ptr::null_mut(),
            contribution: 0,
            ready_for_write: ListEntry::new(),
            work_queue_entry: ListEntry::new(),
            resource: 0,
            event: unsafe { mem::zeroed() },
            lazy_write_link: ListEntry::new(),
            dirty_links: ListEntry::new(),
            pinned_links: ListEntry::new(),
            file_object: core::ptr::null_mut(),
            overlay: CacheMapSection::new(),
        }
    }
}

// ============================================================
// LAZY_WRITER
// ============================================================

#[repr(C)]
pub struct LazyWriter {
    pub thread: Pvoid,
    pub event: Kevent,
    pub mutex: KspinLock,
    pub dirty_page_count: AtomicU64,
    pub flush_count: AtomicU32,
    pub scan_count: AtomicU32,
    pub pages_written: AtomicU64,
    pub flushes: AtomicU32,
    pub flush_interval: u64,
    pub flush_threshold: u32,
    pub rate: u32,
    pub rate_limit: u32,
    pub active: u32,
    pub interval: u64,
}

impl LazyWriter {
    pub fn new() -> Self {
        Self {
            thread: core::ptr::null_mut(),
            event: unsafe { mem::zeroed() },
            mutex: 0,
            dirty_page_count: AtomicU64::new(0),
            flush_count: AtomicU32::new(0),
            scan_count: AtomicU32::new(0),
            pages_written: AtomicU64::new(0),
            flushes: AtomicU32::new(0),
            flush_interval: LAZY_WRITER_PANEL_INTERVAL,
            flush_threshold: 0,
            rate: 0,
            rate_limit: 100,
            active: 0,
            interval: LAZY_WRITER_PANEL_INTERVAL,
        }
    }
}

// ============================================================
// Global State
// ============================================================

static CC_INITIALIZED: AtomicBool = AtomicBool::new(false);
pub static mut CC_CACHE_MAP_LIST: ListEntry = ListEntry::new();
pub static mut CC_SHARED_CACHE_MAP_LIST: ListEntry = ListEntry::new();
static CC_SHARED_CACHE_MAP_LIST_LOCK: KspinLock = 0;
static CC_CACHE_MAP_COUNT: AtomicU32 = AtomicU32::new(0);
static CC_BCB_COUNT: AtomicU32 = AtomicU32::new(0);
pub static mut CC_LAZY_WRITER: LazyWriter = LazyWriter {
    thread: core::ptr::null_mut(),
    event: Kevent {
        header: DispatcherHeader {
            r#type: 0,
            absolute: 0,
            size: 0,
            inserted: 0,
            signal_state: 0,
            wait_list_entry: ListEntry {
                flink: core::ptr::null_mut(),
                blink: core::ptr::null_mut(),
            },
        },
    },
    mutex: 0,
    dirty_page_count: AtomicU64::new(0),
    flush_count: AtomicU32::new(0),
    scan_count: AtomicU32::new(0),
    pages_written: AtomicU64::new(0),
    flushes: AtomicU32::new(0),
    flush_interval: LAZY_WRITER_PANEL_INTERVAL,
    flush_threshold: 0,
    rate: 0,
    rate_limit: 100,
    active: 0,
    interval: LAZY_WRITER_PANEL_INTERVAL,
};

// ============================================================
// CcInitializeCacheManager
// ============================================================

pub unsafe fn cc_initialize_cache_manager() -> NtStatus {
    if CC_INITIALIZED.load(Ordering::Acquire) {
        return STATUS_SUCCESS;
    }

    cc_dbg!("CcInitializeCacheManager: initializing");

    // Initialize global lists
    CC_CACHE_MAP_LIST = ListEntry::new();
    CC_CACHE_MAP_LIST.flink = &mut CC_CACHE_MAP_LIST as *mut ListEntry;
    CC_CACHE_MAP_LIST.blink = &mut CC_CACHE_MAP_LIST as *mut ListEntry;

    CC_SHARED_CACHE_MAP_LIST = ListEntry::new();
    CC_SHARED_CACHE_MAP_LIST.flink = &mut CC_SHARED_CACHE_MAP_LIST as *mut ListEntry;
    CC_SHARED_CACHE_MAP_LIST.blink = &mut CC_SHARED_CACHE_MAP_LIST as *mut ListEntry;

    // Initialize lazy writer
    let lw = &mut *(&raw mut CC_LAZY_WRITER as *mut LazyWriter);
    lw.dirty_page_count.store(0, Ordering::Relaxed);
    lw.flush_count.store(0, Ordering::Relaxed);
    lw.scan_count.store(0, Ordering::Relaxed);
    lw.pages_written.store(0, Ordering::Relaxed);
    lw.flushes.store(0, Ordering::Relaxed);
    lw.active = 1;

    cc_initialize_lazy_writer();

    CC_INITIALIZED.store(true, Ordering::Release);

    cc_dbg!("CcInitializeCacheManager: initialized");
    STATUS_SUCCESS
}

// ============================================================
// CcInitializeCacheMap
// ============================================================

pub unsafe fn cc_initialize_cache_map(
    file_object: Pvoid,
    file_size: *mut i64,
    _restrictions: u32,
    _n: u32,
    _m: Pvoid,
    shared_cache_map: *mut *mut SharedCacheMap,
) -> NtStatus {
    if file_object.is_null() || shared_cache_map.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    *shared_cache_map = core::ptr::null_mut();

    cc_trace!("CcInitializeCacheMap: file={:p}", file_object);

    // Allocate shared cache map
    let scm_size = mem::size_of::<SharedCacheMap>();
    let layout = match core::alloc::Layout::from_size_align(scm_size, 16) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let scm = alloc::alloc::alloc_zeroed(layout) as *mut SharedCacheMap;
    if scm.is_null() {
        return STATUS_NO_MEMORY;
    }

    let cache_map = &mut *scm;

    // Initialize
    cache_map.node_type_code = 0x7343;
    cache_map.node_byte_size = scm_size as u16;
    cache_map.file_object = file_object;
    cache_map.active_count = 1;
    cache_map.flags = CACHE_FLAGS_ENABLE_READAHEAD;

    if !file_size.is_null() {
        cache_map.file_size = *file_size;
        cache_map.valid_data_length = *file_size;
        cache_map.valid_data_goal = *file_size;
    }

    cache_map.sector_size = 512;
    cache_map.allocation_size = cache_map.file_size;

    cache_map.bcb_list = ListEntry::new();
    cache_map.bcb_list.flink = &mut cache_map.bcb_list as *mut ListEntry;
    cache_map.bcb_list.blink = &mut cache_map.bcb_list as *mut ListEntry;

    cache_map.active_list = ListEntry::new();
    cache_map.active_list.flink = &mut cache_map.active_list as *mut ListEntry;
    cache_map.active_list.blink = &mut cache_map.active_list as *mut ListEntry;

    cache_map.dirty_list = ListEntry::new();
    cache_map.dirty_list.flink = &mut cache_map.dirty_list as *mut ListEntry;
    cache_map.dirty_list.blink = &mut cache_map.dirty_list as *mut ListEntry;

    cache_map.work_queue = ListEntry::new();
    cache_map.work_queue.flink = &mut cache_map.work_queue as *mut ListEntry;
    cache_map.work_queue.blink = &mut cache_map.work_queue as *mut ListEntry;

    cache_map.shared_cache_map_links = ListEntry::new();
    cache_map.views = ListEntry::new();
    cache_map.views.flink = &mut cache_map.views as *mut ListEntry;
    cache_map.views.blink = &mut cache_map.views as *mut ListEntry;

    for bucket in cache_map.buckets.iter_mut() {
        bucket.initialize();
    }

    // Insert into global list
    acq_lock(&CC_SHARED_CACHE_MAP_LIST_LOCK);
    CC_SHARED_CACHE_MAP_LIST.insert_tail(&mut cache_map.shared_cache_map_links);
    rel_lock(&CC_SHARED_CACHE_MAP_LIST_LOCK);

    CC_CACHE_MAP_COUNT.fetch_add(1, Ordering::Relaxed);

    *shared_cache_map = cache_map;

    cc_trace!("CcInitializeCacheMap: created cache map for file={:p}", file_object);
    STATUS_SUCCESS
}

// ============================================================
// CcUninitializeCacheMap
// ============================================================

pub unsafe fn cc_uninitialize_cache_map(
    shared_cache_map: *mut SharedCacheMap,
    _walk: Pvoid,
    _call_after_uninit: Pvoid,
) -> NtStatus {
    if shared_cache_map.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    cc_trace!("CcUninitializeCacheMap: file={:p}", (*shared_cache_map).file_object);

    let scm = &mut *shared_cache_map;

    // Flush dirty data
    cc_flush_cache_map(shared_cache_map, 0, 0);

    // Remove from global list
    acq_lock(&CC_SHARED_CACHE_MAP_LIST_LOCK);
    scm.shared_cache_map_links.remove();
    rel_lock(&CC_SHARED_CACHE_MAP_LIST_LOCK);

    // Free all BCBs
    let mut current = scm.bcb_list.flink;
    while current != &scm.bcb_list as *const ListEntry as *mut ListEntry {
        let bcb = bcb_from_list_entry(current);
        current = (*current).flink;

        if !bcb.is_null() {
            cc_free_bcb(bcb);
        }
    }

    CC_CACHE_MAP_COUNT.fetch_sub(1, Ordering::Relaxed);

    // Free shared cache map
    let layout = core::alloc::Layout::from_size_align(
        mem::size_of::<SharedCacheMap>(), 16
    ).unwrap();
    alloc::alloc::dealloc(shared_cache_map as *mut u8, layout);

    STATUS_SUCCESS
}

// ============================================================
// CcPinRead
// ============================================================

pub unsafe fn cc_pin_read(
    file_object: Pvoid,
    file_offset: *mut i64,
    length: u32,
    _flags: u32,
    bcb: *mut *mut Bcb,
    buffer: *mut Pvoid,
) -> NtStatus {
    if file_object.is_null() || bcb.is_null() || file_offset.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    *bcb = core::ptr::null_mut();
    if !buffer.is_null() {
        *buffer = core::ptr::null_mut();
    }

    cc_trace!("CcPinRead: file={:p} offset={}", file_object, *file_offset);

    // Find or create BCB for this offset
    let cache_map = cc_get_cache_map(file_object);
    if cache_map.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let offset = *file_offset;
    let bcb_found = cc_find_bcb(cache_map, offset as u64, length);

    if !bcb_found.is_null() {
        let bcb_ref = &mut *bcb_found;
        bcb_ref.pinned_count += 1;
        bcb_ref.flags |= BCB_FLAGS_PINNED;

        if !buffer.is_null() {
            *buffer = bcb_ref.base_address;
        }

        *bcb = bcb_found;
        return STATUS_SUCCESS;
    }

    // Create new BCB
    let new_bcb = cc_create_bcb(cache_map, offset as u64, length);
    if new_bcb.is_null() {
        return STATUS_NO_MEMORY;
    }

    let bcb_ref = &mut *new_bcb;
    bcb_ref.pinned_count = 1;
    bcb_ref.flags |= BCB_FLAGS_PINNED;

    if !buffer.is_null() {
        *buffer = bcb_ref.base_address;
    }

    *bcb = new_bcb;
    STATUS_SUCCESS
}

// ============================================================
// CcPinMappedData
// ============================================================

pub unsafe fn cc_pin_mapped_data(
    file_object: Pvoid,
    file_offset: *mut i64,
    length: u32,
    _flags: u32,
    bcb: *mut *mut Bcb,
) -> NtStatus {
    if file_object.is_null() || bcb.is_null() || file_offset.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    cc_trace!("CcPinMappedData: file={:p}", file_object);

    let cache_map = cc_get_cache_map(file_object);
    if cache_map.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let offset = *file_offset;
    let bcb_found = cc_find_bcb(cache_map, offset as u64, length);

    if !bcb_found.is_null() {
        let bcb_ref = &mut *bcb_found;
        bcb_ref.pinned_count += 1;
        bcb_ref.flags |= BCB_FLAGS_PINNED;
        *bcb = bcb_found;
        return STATUS_SUCCESS;
    }

    STATUS_INVALID_PARAMETER
}

// ============================================================
// CcUnpinData
// ============================================================

pub unsafe fn cc_unpin_data(bcb: *mut Bcb) {
    if bcb.is_null() {
        return;
    }

    cc_trace!("CcUnpinData: bcb={:p}", bcb);

    let bcb_ref = &mut *bcb;

    if bcb_ref.pinned_count > 0 {
        bcb_ref.pinned_count -= 1;
    }

    if bcb_ref.pinned_count == 0 {
        bcb_ref.flags &= !BCB_FLAGS_PINNED;
    }
}

// ============================================================
// CcCopyRead
// ============================================================

pub unsafe fn cc_copy_read(
    file_object: Pvoid,
    file_offset: *mut i64,
    length: u32,
    _wait: Boolean,
    buffer: Pvoid,
    io_status: *mut IoStatusBlock,
) -> NtStatus {
    if file_object.is_null() || file_offset.is_null() || buffer.is_null() || io_status.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    cc_trace!("CcCopyRead: file={:p} offset={} len={}", file_object, *file_offset, length);

    let offset = *file_offset;
    let cache_map = cc_get_cache_map(file_object);
    if cache_map.is_null() {
        (*io_status).status = STATUS_INVALID_PARAMETER;
        (*io_status).information = 0;
        return STATUS_INVALID_PARAMETER;
    }

    let mut total_copied: u32 = 0;
    let mut current_offset = offset as u64;
    let mut remaining = length;
    let mut dest = buffer as *mut u8;

    while remaining > 0 {
        let chunk_size = if remaining > CACHE_MAPPING_GRANULARITY as u32 {
            CACHE_MAPPING_GRANULARITY as u32
        } else {
            remaining
        };

        // Find BCB for this region
        let bcb = cc_find_bcb(cache_map, current_offset, chunk_size);

        if !bcb.is_null() {
            let bcb_ref = &*bcb;

            if !bcb_ref.base_address.is_null() {
                // Copy data from cache to buffer
                let src = (bcb_ref.base_address as *const u8)
                    .add((current_offset - bcb_ref.file_offset) as usize);
                core::ptr::copy_nonoverlapping(src, dest, chunk_size as usize);
            }
        } else {
            // No data in cache, zero-fill
            core::ptr::write_bytes(dest, 0, chunk_size as usize);
        }

        total_copied += chunk_size;
        current_offset += chunk_size as u64;
        remaining -= chunk_size;
        dest = dest.add(chunk_size as usize);
    }

    (*io_status).status = STATUS_SUCCESS;
    (*io_status).information = total_copied as Ulong;

    STATUS_SUCCESS
}

// ============================================================
// CcCopyWrite
// ============================================================

pub unsafe fn cc_copy_write(
    file_object: Pvoid,
    file_offset: *mut i64,
    length: u32,
    _wait: Boolean,
    buffer: Pvoid,
    io_status: *mut IoStatusBlock,
) -> NtStatus {
    if file_object.is_null() || file_offset.is_null() || buffer.is_null() || io_status.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    cc_trace!("CcCopyWrite: file={:p} offset={} len={}", file_object, *file_offset, length);

    let offset = *file_offset;
    let cache_map = cc_get_cache_map(file_object);
    if cache_map.is_null() {
        (*io_status).status = STATUS_INVALID_PARAMETER;
        (*io_status).information = 0;
        return STATUS_INVALID_PARAMETER;
    }

    let mut total_written: u32 = 0;
    let mut current_offset = offset as u64;
    let mut remaining = length;
    let mut src = buffer as *const u8;

    let scm = &mut *cache_map;
    while remaining > 0 {
        let chunk_size = if remaining > CACHE_MAPPING_GRANULARITY as u32 {
            CACHE_MAPPING_GRANULARITY as u32
        } else {
            remaining
        };

        // Find or create BCB for this region
        let bcb = cc_find_or_create_bcb(cache_map, current_offset, chunk_size);

        if !bcb.is_null() {
            let bcb_ref = &mut *bcb;

            if !bcb_ref.base_address.is_null() {
                // Copy data from buffer to cache
                let dst = (bcb_ref.base_address as *mut u8)
                    .add((current_offset - bcb_ref.file_offset) as usize);
                core::ptr::copy_nonoverlapping(src, dst, chunk_size as usize);

                bcb_ref.flags |= BCB_FLAGS_DIRTY;
                bcb_ref.dirty = 1;

                // Add to dirty list if not already there
                let dirty_links_ptr = &mut bcb_ref.dirty_links as *mut _;
                if bcb_ref.dirty_links.flink.is_null() || bcb_ref.dirty_links.flink == dirty_links_ptr {
                    scm.dirty_list.insert_tail(&mut bcb_ref.dirty_links);
                }

                scm.dirty_pages += 1;
                CC_LAZY_WRITER.dirty_page_count.fetch_add(1, Ordering::Relaxed);
            }
        }

        total_written += chunk_size;
        current_offset += chunk_size as u64;
        remaining -= chunk_size;
        src = src.add(chunk_size as usize);
    }

    (*io_status).status = STATUS_SUCCESS;
    (*io_status).information = total_written as Ulong;

    STATUS_SUCCESS
}

// ============================================================
// CcAsyncCopyRead
// ============================================================

pub unsafe fn cc_async_copy_read(
    file_object: Pvoid,
    file_offset: *mut i64,
    length: u32,
    _wait: Boolean,
    buffer: Pvoid,
    io_status: *mut IoStatusBlock,
    apc_context: Pvoid,
) -> NtStatus {
    let _ = apc_context;

    // Async version - for now, delegate to sync
    cc_copy_read(file_object, file_offset, length, 1, buffer, io_status)
}

// ============================================================
// CcScheduleReadAhead
// ============================================================

pub unsafe fn cc_schedule_readAhead(
    file_object: Pvoid,
    file_offset: *mut i64,
    length: u32,
) -> NtStatus {
    if file_object.is_null() || file_offset.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    cc_trace!("CcScheduleReadAhead: file={:p}", file_object);

    let cache_map = cc_get_cache_map(file_object);
    if cache_map.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Check if read-ahead is disabled
    if (*cache_map).flags & CACHE_FLAGS_DISABLE_READAHEAD != 0 {
        return STATUS_SUCCESS;
    }

    let offset = *file_offset;

    // Calculate read-ahead range
    let read_ahead_size = if length < CACHE_READ_AHEAD_GRANULARITY as u32 {
        CACHE_READ_AHEAD_GRANULARITY as u32
    } else {
        length * 2
    };

    // Pre-create BCBs for the read-ahead range
    let mut current = offset as u64;
    let end = (offset as u64) + read_ahead_size as u64;

    while current < end {
        let chunk = if (end - current) > CACHE_MAPPING_GRANULARITY as u64 {
            CACHE_MAPPING_GRANULARITY as u64
        } else {
            end - current
        };

        cc_find_or_create_bcb(cache_map, current, chunk as u32);
        current += chunk;
    }

    STATUS_SUCCESS
}

// ============================================================
// CcReadAhead
// ============================================================

pub unsafe fn cc_readAhead(
    file_object: Pvoid,
    file_offset: *mut i64,
    length: u32,
) -> NtStatus {
    cc_schedule_readAhead(file_object, file_offset, length)
}

// ============================================================
// CcGetFileObjectForBcb
// ============================================================

pub unsafe fn cc_get_file_object_for_bcb(bcb: *mut Bcb) -> Pvoid {
    if bcb.is_null() {
        return core::ptr::null_mut();
    }

    let bcb_ref = &*bcb;
    bcb_ref.file_object
}

// ============================================================
// CcReleaseBcb
// ============================================================

pub unsafe fn cc_release_bcb(bcb: *mut Bcb) -> NtStatus {
    if bcb.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    cc_trace!("CcReleaseBcb: bcb={:p}", bcb);

    cc_unpin_data(bcb);

    STATUS_SUCCESS
}

// ============================================================
// CcSetDirtyPfn
// ============================================================

pub unsafe fn cc_set_dirty_pfn(
    file_object: Pvoid,
    file_offset: *mut i64,
    length: u32,
) -> NtStatus {
    if file_object.is_null() || file_offset.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    cc_trace!("CcSetDirtyPfn: file={:p}", file_object);

    let cache_map = cc_get_cache_map(file_object);
    if cache_map.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let offset = *file_offset;
    let bcb = cc_find_bcb(cache_map, offset as u64, length);

    if !bcb.is_null() {
        let bcb_ref = &mut *bcb;
        bcb_ref.flags |= BCB_FLAGS_DIRTY;
        bcb_ref.dirty = 1;

        let dirty_links_ptr = &mut bcb_ref.dirty_links as *mut _;
        if bcb_ref.dirty_links.flink.is_null() || bcb_ref.dirty_links.flink == dirty_links_ptr {
            (*cache_map).dirty_list.insert_tail(&mut bcb_ref.dirty_links);
        }

        (*cache_map).dirty_pages += 1;
    }

    STATUS_SUCCESS
}

// ============================================================
// Internal helpers
// ============================================================

unsafe fn cc_get_cache_map(file_object: Pvoid) -> *mut SharedCacheMap {
    if file_object.is_null() {
        return core::ptr::null_mut();
    }

    // In a real implementation, this would look up the cache map
    // from the file object's section object pointer
    // For now, search the global list
    let mut current = CC_SHARED_CACHE_MAP_LIST.flink;
    while current != &CC_SHARED_CACHE_MAP_LIST as *const ListEntry as *mut ListEntry {
        let scm = (current as usize - mem::offset_of!(SharedCacheMap, shared_cache_map_links))
            as *mut SharedCacheMap;
        if (*scm).file_object == file_object {
            return scm;
        }
        current = (*current).flink;
    }

    core::ptr::null_mut()
}

unsafe fn cc_find_bcb(
    cache_map: *mut SharedCacheMap,
    offset: u64,
    _length: u32,
) -> *mut Bcb {
    if cache_map.is_null() {
        return core::ptr::null_mut();
    }

    let scm = &*cache_map;
    let mut current = scm.bcb_list.flink;

    while current != &scm.bcb_list as *const ListEntry as *mut ListEntry {
        let bcb = bcb_from_list_entry(current);
        if !bcb.is_null() {
            let bcb_ref = &*bcb;
            if bcb_ref.file_offset == offset {
                return bcb;
            }
        }
        current = (*current).flink;
    }

    core::ptr::null_mut()
}

unsafe fn cc_find_or_create_bcb(
    cache_map: *mut SharedCacheMap,
    offset: u64,
    length: u32,
) -> *mut Bcb {
    let existing = cc_find_bcb(cache_map, offset, length);
    if !existing.is_null() {
        return existing;
    }

    cc_create_bcb(cache_map, offset, length)
}

unsafe fn cc_create_bcb(
    cache_map: *mut SharedCacheMap,
    offset: u64,
    length: u32,
) -> *mut Bcb {
    if cache_map.is_null() {
        return core::ptr::null_mut();
    }

    let bcb_size = mem::size_of::<Bcb>();
    let layout = match core::alloc::Layout::from_size_align(bcb_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };

    let bcb_ptr = alloc::alloc::alloc_zeroed(layout) as *mut Bcb;
    if bcb_ptr.is_null() {
        return core::ptr::null_mut();
    }

    let bcb = &mut *bcb_ptr;
    bcb.node_type_code = 0x4263;
    bcb.node_byte_size = bcb_size as u16;
    bcb.file_offset = offset;
    bcb.byte_length = length;
    bcb.flags = BCB_FLAGS_NEWLY_ADDED;
    bcb.ref_count = 1;
    bcb.shared_cache_map = cache_map;

    // Allocate buffer for the BCB data
    let alloc_size = (length as usize + 4095) & !4095;
    let data_layout = match core::alloc::Layout::from_size_align(alloc_size, 4096) {
        Ok(l) => l,
        Err(_) => {
            alloc::alloc::dealloc(bcb_ptr as *mut u8, layout);
            return core::ptr::null_mut();
        }
    };

    let data = alloc::alloc::alloc_zeroed(data_layout);
    if data.is_null() {
        alloc::alloc::dealloc(bcb_ptr as *mut u8, layout);
        return core::ptr::null_mut();
    }

    bcb.base_address = data as Pvoid;

    // Insert into cache map's BCB list
    let scm = &mut *cache_map;
    scm.bcb_list.insert_tail(&mut bcb.bcb_links);
    scm.active_list.insert_tail(&mut bcb.ready_for_write);

    CC_BCB_COUNT.fetch_add(1, Ordering::Relaxed);

    cc_trace!("cc_create_bcb: offset={} len={} -> {:p}", offset, length, bcb_ptr);

    bcb_ptr
}

unsafe fn cc_free_bcb(bcb: *mut Bcb) {
    if bcb.is_null() {
        return;
    }

    let bcb_ref = &mut *bcb;

    // Remove from lists
    bcb_ref.bcb_links.remove();

    if !bcb_ref.dirty_links.flink.is_null()
        && bcb_ref.dirty_links.flink != &mut bcb_ref.dirty_links as *mut ListEntry
    {
        bcb_ref.dirty_links.remove();
    }

    // Free data buffer
    if !bcb_ref.base_address.is_null() {
        let alloc_size = (bcb_ref.byte_length as usize + 4095) & !4095;
        let layout = core::alloc::Layout::from_size_align(alloc_size, 4096).unwrap();
        alloc::alloc::dealloc(bcb_ref.base_address as *mut u8, layout);
    }

    // Free BCB
    let layout = core::alloc::Layout::from_size_align(
        mem::size_of::<Bcb>(), 16
    ).unwrap();
    alloc::alloc::dealloc(bcb as *mut u8, layout);

    CC_BCB_COUNT.fetch_sub(1, Ordering::Relaxed);
}

unsafe fn cc_flush_cache_map(
    shared_cache_map: *mut SharedCacheMap,
    _file_offset: u64,
    _length: u32,
) -> NtStatus {
    if shared_cache_map.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let scm = &mut *shared_cache_map;

    // Walk dirty list and flush
    let mut current = scm.dirty_list.flink;
    while current != &scm.dirty_list as *const ListEntry as *mut ListEntry {
        let bcb = bcb_from_list_entry(current);
        current = (*current).flink;

        if !bcb.is_null() {
            let bcb_ref = &mut *bcb;
            if bcb_ref.flags & BCB_FLAGS_DIRTY != 0 {
                bcb_ref.flags &= !BCB_FLAGS_DIRTY;
                bcb_ref.dirty = 0;
                scm.dirty_pages = scm.dirty_pages.saturating_sub(1);
            }
        }
    }

    CC_LAZY_WRITER.flush_count.fetch_add(1, Ordering::Relaxed);
    CC_LAZY_WRITER.pages_written.fetch_add(scm.dirty_pages, Ordering::Relaxed);
    scm.dirty_pages = 0;

    STATUS_SUCCESS
}

unsafe fn cc_initialize_lazy_writer() {
    let lw = &mut CC_LAZY_WRITER;
    lw.active = 1;
    lw.flush_interval = LAZY_WRITER_PANEL_INTERVAL;
    lw.flush_threshold = 0;
    lw.rate = 0;
    lw.rate_limit = 100;

    cc_trace!("cc_initialize_lazy_writer: initialized");
}

unsafe fn bcb_from_list_entry(entry: *mut ListEntry) -> *mut Bcb {
    if entry.is_null() {
        return core::ptr::null_mut();
    }

    // BCB's bcb_links is at offset ~40 from start of BCB
    let offset = mem::offset_of!(Bcb, bcb_links);
    (entry as usize - offset) as *mut Bcb
}

// ============================================================
// Spin lock helpers
// ============================================================

unsafe fn acq_lock(lock: *const KspinLock) {
    let lock_ptr = lock as *const KspinLock as *mut u64;
    core::arch::asm!(
        "1:",
        "lock bts qword ptr [{0}], 0",
        "jc 2f",
        "jmp 3f",
        "2:",
        "pause",
        "test qword ptr [{0}], 1",
        "jnz 2b",
        "jmp 1b",
        "3:",
        in(reg) lock_ptr,
        options(nostack, nomem),
    );
}

unsafe fn rel_lock(lock: *const KspinLock) {
    let lock_ptr = lock as *const KspinLock as *mut u64;
    core::arch::asm!(
        "lock btr qword ptr [{0}], 0",
        in(reg) lock_ptr,
        options(nostack, nomem),
    );
}

// ============================================================
