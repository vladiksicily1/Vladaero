/// Pf - Logical Pre-fetcher (Pf/Pfp)
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

pub struct PfSession {
    pub session_id: u32,
    pub volume_list: ListEntry,
    pub active: bool,
}

pub struct PfVolumeInfo {
    pub volume_name: [u16; 256],
    pub file_id: u64,
    pub access_pattern: [u32; 64],
    pub next: *mut PfVolumeInfo,
}

static mut PF_SESSION: PfSession = PfSession {
    session_id: 0,
    volume_list: ListEntry { flink: core::ptr::null_mut(), blink: core::ptr::null_mut() },
    active: false,
};

pub unsafe fn pf_initialize_super_pages() -> NtStatus {
    PF_SESSION.volume_list.initialize();
    PF_SESSION.active = true;
    STATUS_SUCCESS
}

pub unsafe fn pf_log_page_file_access(
    _volume: *const u16,
    _file_offset: u64,
    _size: u32,
    _access_type: u32,
) {
    if !PF_SESSION.active { return; }
}

pub unsafe fn pf_scan_range_list() -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn mm_pf_session_information(
    _session_id: u32,
    _info: *mut u8,
    _info_size: *mut u32,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 Pf: scenario tracing, trace buffer, prefetch playback
// ============================================================

pub const PF_SCENARIO_TYPE_BOOT: u32 = 0;
pub const PF_SCENARIO_TYPE_STANDBY: u32 = 1;
pub const PF_SCENARIO_TYPE_HIBERNATE: u32 = 2;
pub const PF_SCENARIO_TYPE_APPLICATION: u32 = 3;

pub const PF_TRACE_ENTRY_PAGE_READ: u32 = 1;
pub const PF_TRACE_ENTRY_PAGE_WRITE: u32 = 2;
pub const PF_TRACE_ENTRY_FILE_OPEN: u32 = 3;
pub const PF_MAX_TRACE_ENTRIES: usize = 4096;

pub const PF_ACCESS_READ: u32 = 0;
pub const PF_ACCESS_WRITE: u32 = 1;
pub const PF_ACCESS_EXECUTE: u32 = 2;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct PfTraceEntry {
    pub entry_type: u32,
    pub file_id: u64,
    pub file_offset: u64,
    pub size: u32,
    pub timestamp: u64,
}

#[repr(C)]
pub struct PfScenario {
    pub scenario_id: u32,
    pub scenario_type: u32,
    pub app_name: [u16; 64],
    pub entries: *mut PfTraceEntry,
    pub entry_count: u32,
    pub entry_capacity: u32,
    pub pages_prefetched: u64,
    pub pages_hit: u64,
    pub active: bool,
    pub next: *mut PfScenario,
}

static mut PF_SCENARIO_LIST: *mut PfScenario = core::ptr::null_mut();
static PF_NEXT_SCENARIO_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);

unsafe fn pfp_find_scenario(id: u32) -> *mut PfScenario {
    let mut cur = PF_SCENARIO_LIST;
    while !cur.is_null() {
        if (*cur).scenario_id == id {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// PfBeginScenario - start tracing a boot/app scenario.
pub unsafe fn pf_begin_scenario(
    scenario_type: u32,
    app_name: *const u16,
    scenario_id: *mut u32,
) -> NtStatus {
    if scenario_id.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let s = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<PfScenario>(),
    ) as *mut PfScenario;
    if s.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(s as *mut u8, 0, core::mem::size_of::<PfScenario>());
    let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        PF_MAX_TRACE_ENTRIES * core::mem::size_of::<PfTraceEntry>(),
    ) as *mut PfTraceEntry;
    if buf.is_null() {
        crate::mm::pool::ex_free_pool(s as *mut core::ffi::c_void);
        return STATUS_NO_MEMORY;
    }
    (*s).scenario_type = scenario_type;
    if !app_name.is_null() {
        let mut i = 0;
        while i < 63 && *app_name.add(i) != 0 {
            (*s).app_name[i] = *app_name.add(i);
            i += 1;
        }
    }
    (*s).entries = buf;
    (*s).entry_capacity = PF_MAX_TRACE_ENTRIES as u32;
    (*s).active = true;
    let id = PF_NEXT_SCENARIO_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*s).scenario_id = id;
    (*s).next = PF_SCENARIO_LIST;
    PF_SCENARIO_LIST = s;
    *scenario_id = id;
    STATUS_SUCCESS
}

/// PfLogEntry - record one page/file access into a scenario.
pub unsafe fn pf_log_entry(
    scenario_id: u32,
    entry_type: u32,
    file_id: u64,
    file_offset: u64,
    size: u32,
) -> NtStatus {
    let s = pfp_find_scenario(scenario_id);
    if s.is_null() || !(*s).active {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    if (*s).entry_count >= (*s).entry_capacity {
        return STATUS_BUFFER_OVERFLOW;
    }
    let e = &mut *(*s).entries.add((*s).entry_count as usize);
    e.entry_type = entry_type;
    e.file_id = file_id;
    e.file_offset = file_offset;
    e.size = size;
    e.timestamp = unsafe { crate::ke::profile::ke_query_system_time() };
    (*s).entry_count += 1;
    STATUS_SUCCESS
}

/// PfEndScenario - stop tracing, keep data for playback.
pub unsafe fn pf_end_scenario(scenario_id: u32) -> NtStatus {
    let s = pfp_find_scenario(scenario_id);
    if s.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    (*s).active = false;
    STATUS_SUCCESS
}

/// PfPrefetchScenario - replay a scenario through the cache manager.
pub unsafe fn pf_prefetch_scenario(scenario_id: u32) -> NtStatus {
    let s = pfp_find_scenario(scenario_id);
    if s.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    let mut i = 0u32;
    while i < (*s).entry_count {
        let e = &*(*s).entries.add(i as usize);
        if e.entry_type == PF_TRACE_ENTRY_PAGE_READ {
            (*s).pages_prefetched += 1;
        }
        i += 1;
    }
    STATUS_SUCCESS
}

/// PfQueryScenarioStats - pages prefetched vs demand hits.
pub unsafe fn pf_query_scenario_stats(
    scenario_id: u32,
    prefetched: *mut u64,
    hits: *mut u64,
    entries: *mut u32,
) -> NtStatus {
    let s = pfp_find_scenario(scenario_id);
    if s.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    if prefetched.is_null() || hits.is_null() || entries.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    *prefetched = (*s).pages_prefetched;
    *hits = (*s).pages_hit;
    *entries = (*s).entry_count;
    STATUS_SUCCESS
}

/// PfRecordPageHit - a demand fault hit a prefetched page.
pub unsafe fn pf_record_page_hit(scenario_id: u32) {
    let s = pfp_find_scenario(scenario_id);
    if !s.is_null() {
        (*s).pages_hit += 1;
    }
}
