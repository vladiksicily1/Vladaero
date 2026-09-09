//! # Page File Management
//!
//! MmInitializePageFile, MmPageFileRead, MmPageFileWrite,
//! and page file support routines matching ntoskrnl.exe.

use core::sync::atomic::{AtomicU32, AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use super::{
    SpinLock, ListEntry, PoolTag, TAG_PFLF,
    PAGE_SHIFT, PAGE_SIZE_X64,
    mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Page file descriptor
// ============================================================

#[repr(C)]
pub struct PageFileDescriptor {
    pub file_name: [u16; 32],
    pub size: u64,
    pub max_size: u64,
    pub current_usage: u64,
    pub peak_usage: u64,
    pub status: u32,
    pub flags: u32,
    pub extension: u32,
    pub minimum_size: u64,
}

impl PageFileDescriptor {
    pub const fn new() -> Self {
        Self {
            file_name: [0; 32],
            size: 0,
            max_size: 0,
            current_usage: 0,
            peak_usage: 0,
            status: 0,
            flags: 0,
            extension: 0,
            minimum_size: 0,
        }
    }
}

// ============================================================
// Page file zone descriptor
// ============================================================

#[repr(C)]
pub struct PageFileZone {
    pub base: u64,
    pub size: u64,
    pub bitmap: u64,
    pub allocation: u32,
}

impl PageFileZone {
    pub const fn new() -> Self {
        Self {
            base: 0,
            size: 0,
            bitmap: 0,
            allocation: 0,
        }
    }
}

// ============================================================
// Page file state
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PageFileState {
    Uninitialized = 0,
    Initialized = 1,
    Ready = 2,
    Error = 3,
    Readonly = 4,
}

// ============================================================
// Global page file state
// ============================================================

static PAGE_FILE_COUNT: AtomicU32 = AtomicU32::new(0);
static PAGE_FILE_STATE: AtomicU32 = AtomicU32::new(0);
static PAGE_FILE_LOCK: SpinLock = SpinLock::new();
static PAGE_FILE_TOTAL_SIZE: AtomicU64 = AtomicU64::new(0);
static PAGE_FILE_CURRENT_USAGE: AtomicU64 = AtomicU64::new(0);
static PAGE_FILE_MAXIMUM_SIZE: AtomicU64 = AtomicU64::new(0);
static PAGE_FILE_READ_COUNT: AtomicU64 = AtomicU64::new(0);
static PAGE_FILE_WRITE_COUNT: AtomicU64 = AtomicU64::new(0);
static PAGE_FILE_READ_ERRORS: AtomicU64 = AtomicU64::new(0);
static PAGE_FILE_WRITE_ERRORS: AtomicU64 = AtomicU64::new(0);

static mut PAGE_FILE_DESCRIPTORS: [PageFileDescriptor; 16] = {
    const INIT: PageFileDescriptor = PageFileDescriptor::new();
    [INIT; 16]
};

static mut PAGE_FILE_ZONES: [PageFileZone; 256] = {
    const INIT: PageFileZone = PageFileZone::new();
    [INIT; 256]
};

static mut PFN_MODIFY_SYNC: SpinLock = SpinLock::new();
static mut PFN_VALIDATION_RUNNING: bool = false;

// ============================================================
// MmInitializePageFile
// ============================================================

pub fn mm_page_file_init() {
    let _guard = PAGE_FILE_LOCK.acquire();

    unsafe {
        PAGE_FILE_DESCRIPTORS = {
            const INIT: PageFileDescriptor = PageFileDescriptor::new();
            [INIT; 16]
        };
        PAGE_FILE_ZONES = {
            const INIT: PageFileZone = PageFileZone::new();
            [INIT; 256]
        };
    }

    PAGE_FILE_COUNT.store(0, Ordering::Relaxed);
    PAGE_FILE_CURRENT_USAGE.store(0, Ordering::Relaxed);
    PAGE_FILE_TOTAL_SIZE.store(0, Ordering::Relaxed);
    PAGE_FILE_MAXIMUM_SIZE.store(0x40000000, Ordering::Relaxed); // 1GB default max
    PAGE_FILE_READ_COUNT.store(0, Ordering::Relaxed);
    PAGE_FILE_WRITE_COUNT.store(0, Ordering::Relaxed);

    PAGE_FILE_STATE.store(PageFileState::Initialized as u32, Ordering::Relaxed);

    mm_dbg!("MmInitializePageFile: page file subsystem initialized");
}

// ============================================================
// MmAddPageFile (called during boot to register page files)
// ============================================================

pub fn mm_add_page_file(
    file_name: &[u16],
    size: u64,
    flags: u32,
) -> NtStatus {
    let _guard = PAGE_FILE_LOCK.acquire();

    let index = PAGE_FILE_COUNT.load(Ordering::Relaxed) as usize;
    if index >= 16 {
        return STATUS_INSUFFICIENT_RESOURCES;
    }

    unsafe {
        let desc = &mut PAGE_FILE_DESCRIPTORS[index];
        let copy_len = file_name.len().min(31);
        desc.file_name[..copy_len].copy_from_slice(&file_name[..copy_len]);
        desc.size = size;
        desc.max_size = size;
        desc.flags = flags;
        desc.status = 1;
        desc.current_usage = 0;
        desc.peak_usage = 0;
    }

    PAGE_FILE_COUNT.fetch_add(1, Ordering::Relaxed);
    PAGE_FILE_TOTAL_SIZE.fetch_add(size, Ordering::Relaxed);

    mm_dbg!("MmAddPageFile: added page file {} size={:#x}", index, size);
    STATUS_SUCCESS
}

// ============================================================
// MmPageFileRead
// ============================================================

pub fn mm_page_file_read(
    page_file_number: u32,
    offset: u64,
    physical_address: u64,
    size: usize,
) -> NtStatus {
    let _guard = PAGE_FILE_LOCK.acquire();

    if PAGE_FILE_STATE.load(Ordering::Relaxed) != PageFileState::Ready as u32 {
        mm_warn!("MmPageFileRead: page file not ready");
        return STATUS_IN_PAGE_ERROR;
    }

    PAGE_FILE_READ_COUNT.fetch_add(1, Ordering::Relaxed);

    let index = (page_file_number >> 16) as usize;
    let actual_offset = (page_file_number as u64 & 0xFFFF) * PAGE_SIZE_X64 as u64 + offset;

    if index >= PAGE_FILE_COUNT.load(Ordering::Relaxed) as usize {
        PAGE_FILE_READ_ERRORS.fetch_add(1, Ordering::Relaxed);
        return STATUS_IN_PAGE_ERROR;
    }

    unsafe {
        let desc = &PAGE_FILE_DESCRIPTORS[index];
        if actual_offset + size as u64 > desc.size {
            PAGE_FILE_READ_ERRORS.fetch_add(1, Ordering::Relaxed);
            return STATUS_IN_PAGE_ERROR;
        }
    }

    // In a real implementation, this would read from the page file on disk
    // For now, zero the destination
    unsafe {
        let dest = physical_address as *mut u8;
        if !dest.is_null() {
            core::ptr::write_bytes(dest, 0, size);
        }
    }

    mm_trace!("MmPageFileRead: pf={:#x} offset={:#x} size={}", page_file_number, offset, size);
    STATUS_SUCCESS
}

// ============================================================
// MmPageFileWrite
// ============================================================

pub fn mm_page_file_write(
    page_file_number: u32,
    offset: u64,
    physical_address: u64,
    size: usize,
) -> NtStatus {
    let _guard = PAGE_FILE_LOCK.acquire();

    if PAGE_FILE_STATE.load(Ordering::Relaxed) != PageFileState::Ready as u32 {
        mm_warn!("MmPageFileWrite: page file not ready");
        return STATUS_INSUFFICIENT_RESOURCES;
    }

    PAGE_FILE_WRITE_COUNT.fetch_add(1, Ordering::Relaxed);

    let index = (page_file_number >> 16) as usize;
    let actual_offset = (page_file_number as u64 & 0xFFFF) * PAGE_SIZE_X64 as u64 + offset;

    if index >= PAGE_FILE_COUNT.load(Ordering::Relaxed) as usize {
        PAGE_FILE_WRITE_ERRORS.fetch_add(1, Ordering::Relaxed);
        return STATUS_INSUFFICIENT_RESOURCES;
    }

    unsafe {
        let desc = &mut PAGE_FILE_DESCRIPTORS[index];
        if actual_offset + size as u64 > desc.size {
            PAGE_FILE_WRITE_ERRORS.fetch_add(1, Ordering::Relaxed);
            return STATUS_INSUFFICIENT_RESOURCES;
        }

        desc.current_usage += size as u64;
        if desc.current_usage > desc.peak_usage {
            desc.peak_usage = desc.current_usage;
        }
    }

    PAGE_FILE_CURRENT_USAGE.fetch_add(size as u64, Ordering::Relaxed);

    // In a real implementation, this would write to the page file on disk
    mm_trace!("MmPageFileWrite: pf={:#x} offset={:#x} size={}", page_file_number, offset, size);
    STATUS_SUCCESS
}

// ============================================================
// MmPageFileWrite2 (extended version)
// ============================================================

pub fn mm_page_file_write2(
    page_file_number: u32,
    offset: u64,
    physical_address: u64,
    size: usize,
    flags: u32,
) -> NtStatus {
    mm_page_file_write(page_file_number, offset, physical_address, size)
}

// ============================================================
// MmDeletePageFile
// ============================================================

pub fn mm_delete_page_file(index: u32) -> NtStatus {
    let _guard = PAGE_FILE_LOCK.acquire();

    let idx = index as usize;
    if idx >= PAGE_FILE_COUNT.load(Ordering::Relaxed) as usize {
        return STATUS_INVALID_PARAMETER;
    }

    unsafe {
        let desc = &PAGE_FILE_DESCRIPTORS[idx];
        PAGE_FILE_TOTAL_SIZE.fetch_sub(desc.size, Ordering::Relaxed);
        PAGE_FILE_CURRENT_USAGE.fetch_sub(desc.current_usage, Ordering::Relaxed);

        PAGE_FILE_DESCRIPTORS[idx] = PageFileDescriptor::new();
    }

    PAGE_FILE_COUNT.fetch_sub(1, Ordering::Relaxed);

    mm_dbg!("MmDeletePageFile: deleted page file {}", index);
    STATUS_SUCCESS
}

// ============================================================
// MmGetPageFileUsage
// ============================================================

pub fn mm_get_page_file_usage() -> u64 {
    PAGE_FILE_CURRENT_USAGE.load(Ordering::Relaxed)
}

pub fn mm_get_page_file_total_size() -> u64 {
    PAGE_FILE_TOTAL_SIZE.load(Ordering::Relaxed)
}

pub fn mm_get_page_file_read_count() -> u64 {
    PAGE_FILE_READ_COUNT.load(Ordering::Relaxed)
}

pub fn mm_get_page_file_write_count() -> u64 {
    PAGE_FILE_WRITE_COUNT.load(Ordering::Relaxed)
}

// ============================================================
// MmPageFileCanonicalPath
// ============================================================

pub fn mm_page_file_canonical_path(path: &mut [u16]) -> usize {
    let page_file_name: [u16; 18] = [
        0x005C, 0x0050, 0x0061, 0x0067, 0x0065, 0x0066, 0x0069, 0x006C,
        0x0065, 0x0030, 0x002E, 0x0073, 0x0077, 0x0070, 0x0000,
        0, 0, 0,
    ];

    let len = page_file_name.iter().position(|&c| c == 0).unwrap_or(17);
    let copy_len = len.min(path.len() - 1);

    path[..copy_len].copy_from_slice(&page_file_name[..copy_len]);
    path[copy_len] = 0;

    copy_len
}

// ============================================================
// MmReservePageFileSpace
// ============================================================

pub fn mm_reserve_page_file_space(size: u64) -> NtStatus {
    let _guard = PAGE_FILE_LOCK.acquire();

    let current = PAGE_FILE_CURRENT_USAGE.load(Ordering::Relaxed);
    let max = PAGE_FILE_MAXIMUM_SIZE.load(Ordering::Relaxed);

    if current + size > max {
        mm_warn!("MmReservePageFileSpace: exceeded maximum size");
        return STATUS_INSUFFICIENT_RESOURCES;
    }

    PAGE_FILE_CURRENT_USAGE.fetch_add(size, Ordering::Relaxed);
    STATUS_SUCCESS
}

// ============================================================
// MmReleasePageFileSpace
// ============================================================

pub fn mm_release_page_file_space(size: u64) {
    PAGE_FILE_CURRENT_USAGE.fetch_sub(size.min(PAGE_FILE_CURRENT_USAGE.load(Ordering::Relaxed)), Ordering::Relaxed);
}

// ============================================================
// MmSetPageFileMaximumSize
// ============================================================

pub fn mm_set_page_file_maximum_size(max_size: u64) {
    PAGE_FILE_MAXIMUM_SIZE.store(max_size, Ordering::Relaxed);
}

// ============================================================
// MmPageFileIsRoomForWrite
// ============================================================

pub fn mm_page_file_is_room_for_write(size: usize) -> bool {
    let current = PAGE_FILE_CURRENT_USAGE.load(Ordering::Relaxed);
    let max = PAGE_FILE_MAXIMUM_SIZE.load(Ordering::Relaxed);
    current + size as u64 <= max
}

// STATUS constants are imported via crate::types::*
