/// Dbg - Debug Support (Dbg/Dbgk/Dbgkp)
use core::ffi::c_void;
use crate::types::*;

pub static mut KD_DEBUGGER_ENABLED: bool = false;
pub static mut KD_DEBUGGER_NOT_PRESENT: bool = true;
pub static mut KDP_BREAKPOINTS: [u64; 32] = [0; 32];
pub static mut KDP_BREAKPOINT_STATUS: [u8; 32] = [0; 32];

pub unsafe fn dbg_breakpoint() {
    core::arch::asm!("int3");
}

pub unsafe fn dbg_print(format: *const u8) {
    let mut ptr = format;
    while *ptr != 0 {
        while (crate::hal::serial_inb(0x3FD) & 0x20) == 0 {}
        crate::hal::serial_outb(0x3F8, *ptr);
        ptr = ptr.add(1);
    }
}

pub unsafe fn dbgk_forward_user_exception(
    _exception_record: *mut c_void,
    _context: *mut c_void,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn dbgk_create_thread(
    _thread: *mut c_void,
) {
    if !KD_DEBUGGER_ENABLED { return; }
}

pub unsafe fn dbgk_exit_thread(
    _thread: *mut c_void,
    _exit_status: NtStatus,
) {
    if !KD_DEBUGGER_ENABLED { return; }
}

pub unsafe fn dbgk_create_process(
    _process: *mut c_void,
) {
    if !KD_DEBUGGER_ENABLED { return; }
}

pub unsafe fn dbgk_exit_process(
    _process: *mut c_void,
    _exit_status: NtStatus,
) {
    if !KD_DEBUGGER_ENABLED { return; }
}

pub unsafe fn dbg_user_breakpoint() {
    if KD_DEBUGGER_ENABLED {
        dbg_breakpoint();
    }
}

pub unsafe fn dbg_breakpoint_with_status(_status: NtStatus) {
    dbg_breakpoint();
}

pub unsafe fn kd_init_debugger() {
    KD_DEBUGGER_NOT_PRESENT = false;
    KD_DEBUGGER_ENABLED = true;
}

pub unsafe fn kd_disable_debugger() {
    KD_DEBUGGER_ENABLED = false;
}

// ============================================================
// Win10 Dbg: DbgPrintEx component/filter, KdPrintEx, KdpTrap
// ============================================================

pub const STATUS_DEBUGGER_INACTIVE: NtStatus = 0xC0000354;
pub const STATUS_BREAKPOINT_LOCAL: NtStatus = 0x80000003;

pub const DPFLTR_SYSTEM_ID: u32 = 0;
pub const DPFLTR_SMSS_ID: u32 = 1;
pub const DPFLTR_SETUP_ID: u32 = 2;
pub const DPFLTR_NTFS_ID: u32 = 3;
pub const DPFLTR_FSTUB_ID: u32 = 4;
pub const DPFLTR_CRASHDUMP_ID: u32 = 5;
pub const DPFLTR_CDAUDIO_ID: u32 = 6;
pub const DPFLTR_CDROM_ID: u32 = 7;
pub const DPFLTR_CLASSPNP_ID: u32 = 8;
pub const DPFLTR_DISK_ID: u32 = 9;
pub const DPFLTR_REDBOOK_ID: u32 = 10;
pub const DPFLTR_STORPROP_ID: u32 = 11;
pub const DPFLTR_SCSIPORT_ID: u32 = 12;
pub const DPFLTR_SCSIMINIPORT_ID: u32 = 13;
pub const DPFLTR_CONFIG_ID: u32 = 14;
pub const DPFLTR_PREFETCHER_ID: u32 = 15;
pub const DPFLTR_MMCSS_ID: u32 = 16;
pub const DPFLTR_PNP_ID: u32 = 18;
pub const DPFLTR_POWER_ID: u32 = 19;
pub const DPFLTR_WMI_ID: u32 = 20;
pub const DPFLTR_ACPI_ID: u32 = 22;
pub const DPFLTR_ACPIHAL_ID: u32 = 23;
pub const DPFLTR_HALIA64_ID: u32 = 26;
pub const DPFLTR_VIDEO_ID: u32 = 27;
pub const DPFLTR_SVCHOST_ID: u32 = 28;
pub const DPFLTR_VIDEOPRT_ID: u32 = 29;
pub const DPFLTR_TCPIP_ID: u32 = 30;
pub const DPFLTR_DMSYNTH_ID: u32 = 31;
pub const DPFLTR_NTOSPNP_ID: u32 = 50;
pub const DPFLTR_NTOSGENERAL_ID: u32 = 51;
pub const DPFLTR_SERVICES_ID: u32 = 52;
pub const DPFLTR_TCPIP6_ID: u32 = 56;
pub const DPFLTR_ENDOFTABLE_ID: u32 = 0xFF;

pub const DPFLTR_ERROR_LEVEL: u32 = 0;
pub const DPFLTR_WARNING_LEVEL: u32 = 1;
pub const DPFLTR_TRACE_LEVEL: u32 = 2;
pub const DPFLTR_INFO_LEVEL: u32 = 3;
pub const DPFLTR_MASK: u32 = 0x80000000;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct DpfiltrState {
    pub component_id: u32,
    pub level: u32,
    pub enabled: bool,
}

static mut DPFILTR_TABLE: [DpfiltrState; 64] = [DpfiltrState {
    component_id: 0,
    level: DPFLTR_ERROR_LEVEL,
    enabled: false,
}; 64];
static mut DPFILTR_COUNT: usize = 0;

unsafe fn dpfiltr_lookup(component_id: u32) -> *mut DpfiltrState {
    let mut i = 0;
    while i < DPFILTR_COUNT {
        if DPFILTR_TABLE[i].component_id == component_id {
            return &mut DPFILTR_TABLE[i] as *mut DpfiltrState;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

/// DbgSetDebugFilterState - control per-component print filtering.
pub unsafe fn dbg_set_debug_filter_state(
    component_id: u32,
    level: u32,
    enabled: bool,
) -> NtStatus {
    let slot = dpfiltr_lookup(component_id);
    if !slot.is_null() {
        (*slot).level = level;
        (*slot).enabled = enabled;
        return STATUS_SUCCESS;
    }
    if DPFILTR_COUNT >= DPFILTR_TABLE.len() {
        return STATUS_INSUFFICIENT_RESOURCES;
    }
    DPFILTR_TABLE[DPFILTR_COUNT] = DpfiltrState {
        component_id,
        level,
        enabled,
    };
    DPFILTR_COUNT += 1;
    STATUS_SUCCESS
}

/// DbgQueryDebugFilterState - query per-component print filtering.
pub unsafe fn dbg_query_debug_filter_state(
    component_id: u32,
    level: u32,
) -> bool {
    let slot = dpfiltr_lookup(component_id);
    if slot.is_null() {
        // Default: errors always pass when the debugger is on.
        return KD_DEBUGGER_ENABLED && level == DPFLTR_ERROR_LEVEL;
    }
    (*slot).enabled && level <= (*slot).level
}

unsafe fn dbg_emit_bytes(bytes: &[u8]) {
    for &b in bytes {
        while (crate::hal::serial_inb(0x3FD) & 0x20) == 0 {}
        crate::hal::serial_outb(0x3F8, b);
    }
}

/// DbgPrintEx - filtered debug print (component + level).
pub unsafe fn dbg_print_ex(
    component_id: u32,
    level: u32,
    message: *const u8,
    length: usize,
) -> NtStatus {
    if message.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if !dbg_query_debug_filter_state(component_id, level) {
        return STATUS_SUCCESS;
    }
    let bytes = core::slice::from_raw_parts(message, length);
    dbg_emit_bytes(bytes);
    STATUS_SUCCESS
}

/// KdPrintEx - kernel debugger print with implicit newline handling.
pub unsafe fn kd_print_ex(
    component_id: u32,
    level: u32,
    message: *const u8,
    length: usize,
) -> NtStatus {
    if !KD_DEBUGGER_ENABLED && !dbg_query_debug_filter_state(component_id, level) {
        return STATUS_SUCCESS;
    }
    dbg_print_ex(DPFLTR_NTOSGENERAL_ID, level, message, length)
}

// ============================================================
// Debug message queue (Dbgkp) - user-mode debugger transport
// ============================================================

pub const DEBUG_EVENT_CREATE_THREAD: u32 = 2;
pub const DEBUG_EVENT_CREATE_PROCESS: u32 = 3;
pub const DEBUG_EVENT_EXIT_THREAD: u32 = 4;
pub const DEBUG_EVENT_EXIT_PROCESS: u32 = 5;
pub const DEBUG_EVENT_EXCEPTION: u32 = 1;
pub const DEBUG_EVENT_LOAD_DLL: u32 = 6;
pub const DEBUG_EVENT_UNLOAD_DLL: u32 = 7;

#[repr(C)]
pub struct DbgkmMessage {
    pub event_code: u32,
    pub process_id: u64,
    pub thread_id: u64,
    pub data: [u8; 64],
    pub next: *mut DbgkmMessage,
}

static mut DBGKM_QUEUE_HEAD: *mut DbgkmMessage = core::ptr::null_mut();
static mut DBGKM_QUEUE_TAIL: *mut DbgkmMessage = core::ptr::null_mut();
static DBGKM_QUEUE_COUNT: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(0);

/// DbgkpQueueMessage - queue a debug event for the user-mode debugger.
pub unsafe fn dbgkp_queue_message(
    event_code: u32,
    process_id: u64,
    thread_id: u64,
    data: *const u8,
    data_len: usize,
) -> NtStatus {
    if !KD_DEBUGGER_ENABLED {
        return STATUS_DEBUGGER_INACTIVE;
    }
    let m = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<DbgkmMessage>(),
    ) as *mut DbgkmMessage;
    if m.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(m as *mut u8, 0, core::mem::size_of::<DbgkmMessage>());
    (*m).event_code = event_code;
    (*m).process_id = process_id;
    (*m).thread_id = thread_id;
    if !data.is_null() && data_len > 0 {
        let n = data_len.min(64);
        core::ptr::copy_nonoverlapping(data, (*m).data.as_mut_ptr(), n);
    }
    (*m).next = core::ptr::null_mut();
    if DBGKM_QUEUE_TAIL.is_null() {
        DBGKM_QUEUE_HEAD = m;
        DBGKM_QUEUE_TAIL = m;
    } else {
        (*DBGKM_QUEUE_TAIL).next = m;
        DBGKM_QUEUE_TAIL = m;
    }
    DBGKM_QUEUE_COUNT.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    STATUS_SUCCESS
}

/// DbgkpDequeueMessage - pull the oldest debug event.
pub unsafe fn dbgkp_dequeue_message(msg_out: *mut DbgkmMessage) -> NtStatus {
    if msg_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if DBGKM_QUEUE_HEAD.is_null() {
        return STATUS_NO_MORE_ENTRIES;
    }
    let m = DBGKM_QUEUE_HEAD;
    DBGKM_QUEUE_HEAD = (*m).next;
    if DBGKM_QUEUE_HEAD.is_null() {
        DBGKM_QUEUE_TAIL = core::ptr::null_mut();
    }
    core::ptr::copy_nonoverlapping(m, msg_out, 1);
    crate::mm::pool::ex_free_pool(m as *mut c_void);
    DBGKM_QUEUE_COUNT.fetch_sub(1, core::sync::atomic::Ordering::Relaxed);
    STATUS_SUCCESS
}

/// KdpTrap - kernel debugger trap dispatch (int1/int3/faults).
pub unsafe fn kdp_trap(
    trap_code: u32,
    exception_code: NtStatus,
    fault_address: u64,
) -> bool {
    if !KD_DEBUGGER_ENABLED {
        return false;
    }
    // Break-in requests always enter the debugger.
    if exception_code == STATUS_BREAKPOINT_LOCAL {
        return true;
    }
    let _ = (trap_code, fault_address);
    false
}
