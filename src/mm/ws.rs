//! # Working Set Manager
//!
//! Page aging, working set trimming, and WS quota management
//! matching ntoskrnl.exe's working set manager.

use core::ffi::c_void;
use alloc::boxed::Box;
use alloc::vec::Vec;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use super::{
    MmPteFlags, MmPte, MmPfn, ListEntry, SpinLock, Mutex,
    PAGE_SHIFT, PAGE_SIZE_X64,
    mi_get_pfn_element, mi_locate_vad, mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Working Set constants
// ============================================================

pub const MmDefaultWsMinPages: usize = 120;
pub const MmDefaultWsMaxPages: usize = 0x7FFFFFFF;
pub const MmWsInitialSize: usize = 120;

pub const MI_WORKING_SET_MANAGER_KEY: u32 = 0x57734D6D; // "WsMm"
pub const MI_WS_TRIM_AGING_INTERVAL: u64 = 1000; // milliseconds
pub const MI_WS_TRIM_MIN_PAGES: usize = 20;
pub const MI_WS_TRIM_MAX_PAGES: usize = 1024;

pub const MI_WS_LOCK_TIMEOUT: u64 = 100;

pub const MI_AGE_CLEAR: u8 = 0;
pub const MI_AGE_REFCOUNTED: u8 = 1;
pub const MI_AGE_ACCESSED: u8 = 2;

pub const MI_WS_TRIM_IDLE: u32 = 0;
pub const MI_WS_TRIM_RUNNING: u32 = 1;
pub const MI_WS_TRIM_STALLED: u32 = 2;

// ============================================================
// Working Set entry
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MiWorkingSetEntry {
    pub entry_list: ListEntry,
    pub virtual_address: u64,
    pub pte: *mut MmPte,
    pub age: u8,
    pub flags: u32,
}

// ============================================================
// Process Working Set
// ============================================================

#[repr(C)]
pub struct MiWorkingSet {
    pub ws_lock: SpinLock,
    pub ws_entries: [ListEntry; 1],
    pub ws_count: u32,
    pub ws_min: u32,
    pub ws_max: u32,
    pub ws_fault_count: u64,
    pub ws_faults_when_full: u64,
    pub ws_trim_trim_count: u32,
    pub ws_trim_skip_count: u32,
    pub last_trim_time: u64,
    pub age_counter: u32,
}

impl MiWorkingSet {
    pub const fn new() -> Self {
        Self {
            ws_lock: SpinLock::new(),
            ws_entries: [ListEntry::new(); 1],
            ws_count: 0,
            ws_min: MmDefaultWsMinPages as u32,
            ws_max: MmDefaultWsMaxPages as u32,
            ws_fault_count: 0,
            ws_faults_when_full: 0,
            ws_trim_trim_count: 0,
            ws_trim_skip_count: 0,
            last_trim_time: 0,
            age_counter: 0,
        }
    }
}

unsafe impl Send for MiWorkingSet {}
unsafe impl Sync for MiWorkingSet {}

// ============================================================
// Working Set Manager state
// ============================================================

static WS_MANAGER_STATE: AtomicU32 = AtomicU32::new(0);
static WS_TRIM_TIMER_DUE: AtomicU64 = AtomicU64::new(0);
static WS_TRIM_PAGES_EVALUATED: AtomicUsize = AtomicUsize::new(0);
static WS_TRIM_PAGES_TRIMMED: AtomicUsize = AtomicUsize::new(0);
static WS_AGING_ROTATION: AtomicU32 = AtomicU32::new(0);

// ============================================================
// MmWorkingSetManagerEntry (periodic callback)
// ============================================================

pub fn mm_working_set_manager_entry() {
    if WS_MANAGER_STATE.compare_exchange(
        MI_WS_TRIM_IDLE,
        MI_WS_TRIM_RUNNING,
        Ordering::Acquire,
        Ordering::Relaxed,
    ).is_err() {
        return;
    }

    mm_trace!("WsManager: entering working set manager pass");

    mi_working_set_trim();

    WS_MANAGER_STATE.store(MI_WS_TRIM_IDLE, Ordering::Release);

    mm_trace!("WsManager: completed working set manager pass");
}

// ============================================================
// MiWorkingSetTrim (core aging / eviction logic)
// ============================================================

fn mi_working_set_trim() -> usize {
    let mut pages_trimmed: usize = 0;
    let aging_cycle = WS_AGING_ROTATION.fetch_add(1, Ordering::Relaxed);

    WS_TRIM_PAGES_EVALUATED.store(0, Ordering::Relaxed);
    WS_TRIM_PAGES_TRIMMED.store(0, Ordering::Relaxed);

    let modified_list_len = super::MmModifiedPageListHead.lock().number_of_entries as usize;
    let standby_list_len = super::MmStandbyPageListHead.lock().number_of_entries as usize;

    let free_list_len = super::MmFreePageListHead.lock().number_of_entries as usize;

    mm_trace!(
        "WsManager: aging cycle {}, free={}, standby={}, modified={}",
        aging_cycle, free_list_len, standby_list_len, modified_list_len
    );

    if free_list_len < MI_WS_TRIM_MIN_PAGES {
        pages_trimmed = mi_ws_trim_modified_list(MI_WS_TRIM_MIN_PAGES - free_list_len);
        if pages_trimmed > 0 {
            mm_dbg!("WsManager: trimmed {} pages from modified list", pages_trimmed);
        }
    }

    if free_list_len + pages_trimmed < MI_WS_TRIM_MIN_PAGES {
        let additional = mi_ws_trim_standby_list(MI_WS_TRIM_MIN_PAGES - free_list_len - pages_trimmed);
        pages_trimmed += additional;
    }

    WS_TRIM_PAGES_TRIMMED.store(pages_trimmed, Ordering::Relaxed);

    if pages_trimmed > 0 {
        mm_trace!("WsManager: total pages trimmed = {}", pages_trimmed);
    }

    pages_trimmed
}

// ============================================================
// MiWsTrimModifiedList
// ============================================================

fn mi_ws_trim_modified_list(target: usize) -> usize {
    let mut trimmed = 0;
    let mut modified_list = super::MmModifiedPageListHead.lock();

    while trimmed < target && modified_list.number_of_entries > 0 {
        let list_entry = &mut modified_list.list_heads[0] as *mut ListEntry;
        unsafe {
            let first = (*list_entry).flink;
            if first.is_null() || first == list_entry {
                break;
            }
            (*first).remove();
        }
        modified_list.number_of_entries -= 1;
        trimmed += 1;
    }

    trimmed
}

// ============================================================
// MiWsTrimStandbyList
// ============================================================

fn mi_ws_trim_standby_list(target: usize) -> usize {
    let mut trimmed = 0;
    let mut standby = super::MmStandbyPageListHead.lock();

    for color in 0..8 {
        if trimmed >= target {
            break;
        }
        while trimmed < target && standby.number_of_entries > 0 {
            let list_entry = &mut standby.list_heads[color] as *mut ListEntry;
            unsafe {
                let first = (*list_entry).flink;
                if first.is_null() || first == list_entry {
                    break;
                }
                (*first).remove();
            }
            standby.number_of_entries -= 1;
            trimmed += 1;
        }
    }

    trimmed
}

// ============================================================
// MmWsTrimProcessWorkingSet
// ============================================================

pub fn mm_ws_trim_process_working_set(
    process_ws: &mut MiWorkingSet,
    target_pages: usize,
) -> usize {
    let _guard = process_ws.ws_lock.acquire();

    let mut trimmed = 0;

    if process_ws.ws_count as usize <= target_pages {
        return 0;
    }

    let pages_to_trim = process_ws.ws_count as usize - target_pages;

    let mut entries = process_ws.ws_entries[0].flink;
    while !entries.is_null() && trimmed < pages_to_trim {
        unsafe {
            let entry = &*(entries as *const MiWorkingSetEntry);
            let next = (*entries).flink;

            if entry.age >= MI_AGE_ACCESSED {
                (*entries).remove();
                process_ws.ws_count -= 1;
                trimmed += 1;
            }

            entries = next;
        }
    }

    mm_trace!("WsTrimProcess: trimmed {} entries (target={})", trimmed, target_pages);
    trimmed
}

// ============================================================
// MmWsCalculatePageTrimCount
// ============================================================

pub fn mm_ws_calculate_page_trim_count(ws: &MiWorkingSet) -> usize {
    let fault_rate = ws.ws_fault_count.saturating_sub(ws.ws_faults_when_full);
    let pages_to_trim = if fault_rate > 100 {
        MI_WS_TRIM_MAX_PAGES
    } else if fault_rate > 10 {
        MI_WS_TRIM_MIN_PAGES + (fault_rate as usize * 10)
    } else {
        MI_WS_TRIM_MIN_PAGES
    };

    pages_to_trim.min(ws.ws_count as usize)
}

// ============================================================
// MmWsPageFault
// ============================================================

pub fn mm_ws_page_fault(
    ws: &mut MiWorkingSet,
    virtual_address: u64,
    pte: &MmPte,
) -> NtStatus {
    ws.ws_fault_count += 1;

    if ws.ws_count >= ws.ws_max {
        ws.ws_faults_when_full += 1;
    }

    let entry = MiWorkingSetEntry {
        entry_list: ListEntry::new(),
        virtual_address,
        pte: pte as *const MmPte as *mut MmPte,
        age: MI_AGE_CLEAR,
        flags: 0,
    };

    // Insert at head (MRU)
    let _guard = ws.ws_lock.acquire();

    let list_entry = &mut ws.ws_entries[0] as *mut ListEntry;
    unsafe {
        let new_entry = Box::into_raw(Box::new(entry));
        (*new_entry).virtual_address = virtual_address;
        (*list_entry).insert_head(&mut (*new_entry).entry_list);
    }

    ws.ws_count += 1;

    STATUS_SUCCESS
}

// ============================================================
// MmWsAgeWorkingSet
// ============================================================

pub fn mm_ws_age_working_set(ws: &MiWorkingSet) {
    let _guard = ws.ws_lock.acquire();

    let mut entries = ws.ws_entries[0].flink;
    while !entries.is_null() {
        unsafe {
            let entry = &mut *(entries as *mut MiWorkingSetEntry);
            let next = (*entries).flink;

            if entry.age < MI_AGE_ACCESSED {
                entry.age += 1;
            }

            entries = next;
        }
    }
}

// ============================================================
// MiWsScanWorkingSet
// ============================================================

pub fn mi_ws_scan_working_set(
    ws: &MiWorkingSet,
    scan_count: usize,
) -> Vec<usize> {
    let mut to_evict = Vec::new();
    let _guard = ws.ws_lock.acquire();

    let mut scanned = 0;
    let mut entries = ws.ws_entries[0].flink;

    while !entries.is_null() && scanned < scan_count {
        unsafe {
            let entry = &*(entries as *const MiWorkingSetEntry);
            let next = (*entries).flink;

            if entry.age >= MI_AGE_REFCOUNTED {
                if let Some(pte) = entry.pte.as_ref() {
                    if pte.flags.contains(MmPteFlags::ACCESSED) {
                        let pfn_num = pte.page_frame_number as usize;
                        to_evict.push(pfn_num);
                    }
                }
            }

            scanned += 1;
            entries = next;
        }
    }

    to_evict
}

// ============================================================
// MmWsTrimWorkingSetCallback
// ============================================================

pub unsafe extern "C" fn mm_ws_trim_working_set_callback(context: *mut c_void) {
    unsafe {
        if context.is_null() {
            return;
        }
        mm_working_set_manager_entry();
    }
}

// ============================================================
// MmWsInit
// ============================================================

pub fn mm_ws_init() {
    WS_MANAGER_STATE.store(MI_WS_TRIM_IDLE, Ordering::Relaxed);
    WS_TRIM_TIMER_DUE.store(0, Ordering::Relaxed);

    mm_dbg!("MmWsInit: working set manager initialized, default min={} pages",
        MmDefaultWsMinPages);
}

// ============================================================
// MmWsGetWsInfo
// ============================================================

pub fn mm_ws_get_ws_info(ws: &MiWorkingSet) -> WorkingSetInfo {
    WorkingSetInfo {
        count: ws.ws_count,
        min: ws.ws_min,
        max: ws.ws_max,
        fault_count: ws.ws_fault_count,
        faults_when_full: ws.ws_faults_when_full,
        trim_count: ws.ws_trim_trim_count,
        skip_count: ws.ws_trim_skip_count,
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct WorkingSetInfo {
    pub count: u32,
    pub min: u32,
    pub max: u32,
    pub fault_count: u64,
    pub faults_when_full: u64,
    pub trim_count: u32,
    pub skip_count: u32,
}
