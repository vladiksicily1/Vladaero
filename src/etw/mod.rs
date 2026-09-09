/// Etw - Event Tracing for Windows (Etw/Etwp)
use core::ffi::c_void;
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

#[repr(C)]
pub struct WnodeHeader {
    pub buffer_size: u32,
    pub provider_id: u64,
    pub historical_context: u64,
    pub count: u32,
    pub flags: u32,
    pub guid: [u8; 16],
    pub client_context: u32,
    pub version: u32,
}

#[repr(C)]
pub struct EventTraceHeader {
    pub wnode: WnodeHeader,
    pub buffer_flags: u16,
    pub version: u8,
    pub cpu_number: u8,
    pub process_id: u32,
    pub thread_id: u32,
    pub time_stamp: u64,
    pub system_time: u64,
}

#[repr(C)]
pub struct TraceEnableContext {
    pub provider_id: u32,
    pub logger_id: u32,
    pub level: u8,
    pub internal: u8,
    pub enable: u8,
    pub reserved: u8,
}

#[repr(C)]
pub struct TraceGuidReg {
    pub guid: [u8; 16],
    pub reg_entry: *mut c_void,
    pub callback: *mut c_void,
}

#[repr(C)]
pub struct LoggerContext {
    pub logger_id: u32,
    pub buffers: [*mut u8; 2],
    pub buffer_size: u32,
    pub buffer_count: u32,
    pub events_lost: u32,
    pub buffers_written: u64,
    pub buffer_pointer: *mut u8,
    pub buffer_offset: u32,
    pub enabled: bool,
    pub flags: u32,
    pub stop: bool,
}

pub struct TraceProvider {
    pub guid: [u8; 16],
    pub reg_entry: TraceGuidReg,
    pub enable_count: u32,
    pub next: *mut TraceProvider,
}

static mut ETW_LOGGER: LoggerContext = LoggerContext {
    logger_id: 0,
    buffers: [core::ptr::null_mut(); 2],
    buffer_size: 0,
    buffer_count: 0,
    events_lost: 0,
    buffers_written: 0,
    buffer_pointer: core::ptr::null_mut(),
    buffer_offset: 0,
    enabled: false,
    flags: 0,
    stop: false,
};

static mut PROVIDER_LIST: *mut TraceProvider = core::ptr::null_mut();
static mut ETW_INITIALIZED: bool = false;

pub unsafe fn etw_initialize() {
    ETW_LOGGER.buffer_size = 64 * 1024;
    ETW_LOGGER.buffer_count = 2;

    let buf1 = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(ETW_LOGGER.buffer_size as usize) as *mut u8;
    let buf2 = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(ETW_LOGGER.buffer_size as usize) as *mut u8;
    ETW_LOGGER.buffers[0] = buf1;
    ETW_LOGGER.buffers[1] = buf2;
    ETW_LOGGER.buffer_pointer = buf1;
    ETW_LOGGER.buffer_offset = 0;
    ETW_INITIALIZED = true;
}

pub unsafe fn etw_write(
    provider_id: *const u8,
    event_trace: *mut EventTraceHeader,
) -> NtStatus {
    if !ETW_INITIALIZED || ETW_LOGGER.buffer_pointer.is_null() {
        return STATUS_NOT_IMPLEMENTED;
    }

    let event_size = (*event_trace).wnode.buffer_size as usize;
    if ETW_LOGGER.buffer_offset as usize + event_size > ETW_LOGGER.buffer_size as usize {
        etw_flush_current_buffer();
    }

    core::ptr::copy_nonoverlapping(
        event_trace as *const u8,
        ETW_LOGGER.buffer_pointer.add(ETW_LOGGER.buffer_offset as usize),
        event_size,
    );
    ETW_LOGGER.buffer_offset += event_size as u32;
    ETW_LOGGER.buffers_written += 1;

    STATUS_SUCCESS
}

unsafe fn etw_flush_current_buffer() {
    let current_idx = (ETW_LOGGER.buffers_written & 1) as usize;
    ETW_LOGGER.buffer_offset = 0;
    ETW_LOGGER.buffer_pointer = ETW_LOGGER.buffers[current_idx];
}

pub unsafe fn etw_write_start_trace(guid: *const u8) -> NtStatus {
    ETW_LOGGER.enabled = true;
    STATUS_SUCCESS
}

pub unsafe fn etw_write_end_trace(guid: *const u8) -> NtStatus {
    ETW_LOGGER.stop = true;
    etw_flush_current_buffer();
    ETW_LOGGER.enabled = false;
    STATUS_SUCCESS
}

pub unsafe fn etw_write_transfer(
    provider_guid: *const u8,
    event_guid: *const u8,
    user_data: *const u8,
    user_data_len: u32,
) -> NtStatus {
    if !ETW_LOGGER.enabled { return STATUS_SUCCESS; }

    let mut header: EventTraceHeader = core::mem::zeroed();
    header.wnode.buffer_size = core::mem::size_of::<EventTraceHeader>() as u32 + user_data_len;
    core::ptr::copy_nonoverlapping(event_guid, header.wnode.guid.as_mut_ptr(), 16);
    header.time_stamp = unsafe { crate::ke::profile::ke_query_system_time() };

    etw_write(provider_guid, &mut header)
}

pub unsafe fn etw_register_trace(
    guid: *const u8,
    callback: *mut c_void,
) -> NtStatus {
    let provider = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<TraceProvider>()) as *mut TraceProvider;
    if provider.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::copy_nonoverlapping(guid, (*provider).guid.as_mut_ptr(), 16);
    (*provider).reg_entry.callback = callback;
    (*provider).enable_count = 1;
    (*provider).next = PROVIDER_LIST;
    PROVIDER_LIST = provider;
    STATUS_SUCCESS
}

pub unsafe fn etw_unregister_trace(guid: *const u8) -> NtStatus {
    let mut prev: *mut TraceProvider = core::ptr::null_mut();
    let mut cur = PROVIDER_LIST;
    let target_guid = core::slice::from_raw_parts(guid, 16);

    while !cur.is_null() {
        if &(*cur).guid[..] == target_guid {
            if prev.is_null() {
                PROVIDER_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

// ============================================================
// Win10 ETW: levels, keywords, enable masks (Etwp)
// ============================================================

pub const TRACE_LEVEL_NONE: u8 = 0;
pub const TRACE_LEVEL_CRITICAL: u8 = 1;
pub const TRACE_LEVEL_ERROR: u8 = 2;
pub const TRACE_LEVEL_WARNING: u8 = 3;
pub const TRACE_LEVEL_INFORMATION: u8 = 4;
pub const TRACE_LEVEL_VERBOSE: u8 = 5;

pub const EVENT_TRACE_FLAG_PROCESS: u32 = 0x00000001;
pub const EVENT_TRACE_FLAG_THREAD: u32 = 0x00000002;
pub const EVENT_TRACE_FLAG_IMAGE_LOAD: u32 = 0x00000004;
pub const EVENT_TRACE_FLAG_DISK_IO: u32 = 0x00000100;
pub const EVENT_TRACE_FLAG_DISK_FILE_IO: u32 = 0x00000200;
pub const EVENT_TRACE_FLAG_MEMORY_PAGE_FAULTS: u32 = 0x00001000;
pub const EVENT_TRACE_FLAG_MEMORY_HARD_FAULTS: u32 = 0x00002000;
pub const EVENT_TRACE_FLAG_NETWORK_TCPIP: u32 = 0x00010000;
pub const EVENT_TRACE_FLAG_REGISTRY: u32 = 0x00020000;
pub const EVENT_TRACE_FLAG_ALPC: u32 = 0x00100000;

#[repr(C)]
pub struct EtwEnableInfo {
    pub logger_id: u32,
    pub level: u8,
    pub match_any_keyword: u64,
    pub match_all_keyword: u64,
}

#[repr(C)]
pub struct EtwProviderEntry {
    pub guid: [u8; 16],
    pub enable_info: EtwEnableInfo,
    pub callback: *mut c_void,
    pub reg_handle: u64,
    pub next: *mut EtwProviderEntry,
}

static mut ETW_PROVIDER_TABLE: *mut EtwProviderEntry = core::ptr::null_mut();
static ETW_NEXT_REG_HANDLE: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(1);

/// EtwRegister - register a kernel-mode ETW provider, returns RegHandle.
pub unsafe fn etw_register(
    provider_guid: *const u8,
    callback: *mut c_void,
    reg_handle: *mut u64,
) -> NtStatus {
    if provider_guid.is_null() || reg_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let e = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<EtwProviderEntry>(),
    ) as *mut EtwProviderEntry;
    if e.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(e as *mut u8, 0, core::mem::size_of::<EtwProviderEntry>());
    core::ptr::copy_nonoverlapping(provider_guid, (*e).guid.as_mut_ptr(), 16);
    (*e).callback = callback;
    (*e).enable_info.level = TRACE_LEVEL_VERBOSE;
    (*e).enable_info.match_any_keyword = u64::MAX;
    let h = ETW_NEXT_REG_HANDLE.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*e).reg_handle = h;
    (*e).next = ETW_PROVIDER_TABLE;
    ETW_PROVIDER_TABLE = e;
    *reg_handle = h;
    STATUS_SUCCESS
}

/// EtwUnregister - remove a provider by RegHandle.
pub unsafe fn etw_unregister(reg_handle: u64) -> NtStatus {
    let mut prev: *mut EtwProviderEntry = core::ptr::null_mut();
    let mut cur = ETW_PROVIDER_TABLE;
    while !cur.is_null() {
        if (*cur).reg_handle == reg_handle {
            if prev.is_null() {
                ETW_PROVIDER_TABLE = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// EtwSetInformation - enable/disable a provider (level + keywords).
pub unsafe fn etw_set_information(
    reg_handle: u64,
    level: u8,
    match_any: u64,
    match_all: u64,
) -> NtStatus {
    let mut cur = ETW_PROVIDER_TABLE;
    while !cur.is_null() {
        if (*cur).reg_handle == reg_handle {
            (*cur).enable_info.level = level;
            (*cur).enable_info.match_any_keyword = match_any;
            (*cur).enable_info.match_all_keyword = match_all;
            return STATUS_SUCCESS;
        }
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// EtwWrite - kernel-mode event write with level/keyword filtering.
///
/// Mirrors the EtwWrite / EtwEventWrite contract: drops the event when
/// no session enabled it at the requested level/keyword.
pub unsafe fn etw_write2(
    reg_handle: u64,
    level: u8,
    keyword: u64,
    event_id: u16,
    user_data: *const u8,
    user_data_len: u32,
) -> NtStatus {
    if !ETW_INITIALIZED || ETW_LOGGER.buffer_pointer.is_null() {
        return STATUS_NOT_IMPLEMENTED;
    }
    // Find provider and apply filter.
    let mut cur = ETW_PROVIDER_TABLE;
    let mut enabled = false;
    while !cur.is_null() {
        if (*cur).reg_handle == reg_handle {
            let info = &(*cur).enable_info;
            if level <= info.level
                && (keyword == 0
                    || (keyword & info.match_any_keyword) != 0
                    || info.match_any_keyword == u64::MAX)
            {
                enabled = true;
            }
            break;
        }
        cur = (*cur).next;
    }
    if !enabled {
        return STATUS_SUCCESS; // filtered out - not an error
    }

    let total = core::mem::size_of::<EventTraceHeader>() + user_data_len as usize;
    if ETW_LOGGER.buffer_offset as usize + total > ETW_LOGGER.buffer_size as usize {
        etw_flush_current_buffer();
    }
    if total > ETW_LOGGER.buffer_size as usize {
        ETW_LOGGER.events_lost += 1;
        return STATUS_BUFFER_TOO_SMALL;
    }
    let mut header: EventTraceHeader = core::mem::zeroed();
    header.wnode.buffer_size = total as u32;
    header.wnode.flags = event_id as u32;
    header.buffer_flags = level as u16;
    header.version = 1;
    header.time_stamp = unsafe { crate::ke::profile::ke_query_system_time() };
    let dst = ETW_LOGGER
        .buffer_pointer
        .add(ETW_LOGGER.buffer_offset as usize);
    core::ptr::copy_nonoverlapping(
        &header as *const EventTraceHeader as *const u8,
        dst,
        core::mem::size_of::<EventTraceHeader>(),
    );
    if !user_data.is_null() && user_data_len > 0 {
        core::ptr::copy_nonoverlapping(
            user_data,
            dst.add(core::mem::size_of::<EventTraceHeader>()),
            user_data_len as usize,
        );
    }
    ETW_LOGGER.buffer_offset += total as u32;
    ETW_LOGGER.buffers_written += 1;
    STATUS_SUCCESS
}

/// EtwQueryLogger - report logger statistics.
pub unsafe fn etw_query_logger(
    buffers_written: *mut u64,
    events_lost: *mut u32,
    buffer_size: *mut u32,
) -> NtStatus {
    if buffers_written.is_null() || events_lost.is_null() || buffer_size.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    *buffers_written = ETW_LOGGER.buffers_written;
    *events_lost = ETW_LOGGER.events_lost;
    *buffer_size = ETW_LOGGER.buffer_size;
    STATUS_SUCCESS
}
