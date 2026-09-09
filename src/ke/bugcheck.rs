/// Ke - Bug Check (KeBugCheck / KeBugCheckEx) - ntoskrnl.exe
///
/// Windows 10 Blue Screen of Death implementation: bug check codes,
/// parameter capture, crash dump header, BSOD screen painter (VGA text
/// + serial), and the KeBugCheckEx / KeBugCheck entry points.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 3 (Bug Checks)
///   - WRK: ntoskrnl/ke/bugcheck.c
///   - ReactOS: ntoskrnl/ke/bugcheck.c

use core::ffi::c_void;
use core::sync::atomic::{AtomicBool, AtomicU32, Ordering};

use crate::types::*;

// ============================================================
// Logging
// ============================================================

macro_rules! bc_trace {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Bc] {}", format_args!($($arg)*));
    };
}

// ============================================================
// Bug Check Codes (a subset of Win10 bugcheck codes)
// ============================================================

pub const APC_INDEX_MISMATCH: u32 = 0x00000001;
pub const DEVICE_QUEUE_NOT_BUSY: u32 = 0x00000002;
pub const INVALID_AFFINITY_SET: u32 = 0x00000003;
pub const INVALID_DATA_ACCESS_TRAP: u32 = 0x00000004;
pub const INVALID_PROCESS_ATTACH_ATTEMPT: u32 = 0x00000005;
pub const INVALID_PROCESS_DETACH_ATTEMPT: u32 = 0x00000006;
pub const INVALID_SOFTWARE_INTERRUPT: u32 = 0x00000007;
pub const IRQL_NOT_DISPATCH_LEVEL: u32 = 0x00000008;
pub const IRQL_NOT_GREATER_OR_EQUAL: u32 = 0x00000009;
pub const IRQL_NOT_LESS_OR_EQUAL: u32 = 0x0000000A;
pub const NO_EXCEPTION_HANDLING_SUPPORT: u32 = 0x0000000B;
pub const MAXIMUM_WAIT_OBJECTS_EXCEEDED: u32 = 0x0000000C;
pub const MUTEX_LEVEL_NUMBER_VIOLATION: u32 = 0x0000000D;
pub const NO_USER_MODE_CONTEXT: u32 = 0x0000000E;
pub const SPIN_LOCK_ALREADY_OWNED: u32 = 0x0000000F;
pub const SPIN_LOCK_NOT_OWNED: u32 = 0x00000010;
pub const THREAD_NOT_MUTEX_OWNER: u32 = 0x00000011;
pub const TRAP_CAUSE_UNKNOWN: u32 = 0x00000012;
pub const EMPTY_THREAD_REAPER_LIST: u32 = 0x00000013;
pub const CREATE_DELETE_LOCK_NOT_LOCKED: u32 = 0x00000014;
pub const LAST_CHANCE_CALLED_FROM_KMODE: u32 = 0x00000015;
pub const CID_HANDLE_CREATION: u32 = 0x00000016;
pub const CID_HANDLE_DELETION: u32 = 0x00000017;
pub const REFERENCE_BY_POINTER: u32 = 0x00000018;
pub const BAD_POOL_HEADER: u32 = 0x00000019;
pub const MEMORY_MANAGEMENT: u32 = 0x0000001A;
pub const PFN_LIST_CORRUPT: u32 = 0x0000001E;
pub const MACHINE_CHECK_EXCEPTION: u32 = 0x0000009C;
pub const KMODE_EXCEPTION_NOT_HANDLED: u32 = 0x0000001E + 0; // 0x1E alias region
pub const KERNEL_MODE_EXCEPTION_NOT_HANDLED: u32 = 0x0000008E;
pub const UNEXPECTED_KERNEL_MODE_TRAP: u32 = 0x0000007F;
pub const KERNEL_DATA_INPAGE_ERROR: u32 = 0x0000007A;
pub const INACCESSIBLE_BOOT_DEVICE: u32 = 0x0000007B;
pub const SYSTEM_THREAD_EXCEPTION_NOT_HANDLED: u32 = 0x0000007E;
pub const KERNEL_SECURITY_CHECK_FAILURE: u32 = 0x00000139;
pub const DRIVER_IRQL_NOT_LESS_OR_EQUAL: u32 = 0x000000D1;
pub const DRIVER_POWER_STATE_FAILURE: u32 = 0x0000009F;
pub const DRIVER_OVERRAN_STACK_BUFFER: u32 = 0x000000F7;
pub const PAGE_FAULT_IN_NONPAGED_AREA: u32 = 0x00000050;
pub const SYSTEM_SERVICE_EXCEPTION: u32 = 0x0000003B;
pub const CRITICAL_PROCESS_DIED: u32 = 0x000000EF;
pub const CRITICAL_STRUCTURE_CORRUPTION: u32 = 0x00000109;
pub const PROCESS_HAS_LOCKED_PAGES: u32 = 0x00000076;
pub const KERNEL_APC_PENDING_DURING_EXIT: u32 = 0x00000020;
pub const PANIC_STACK_SWITCH: u32 = 0x0000002B;
pub const MANUALLY_INITIATED_CRASH: u32 = 0x000000E2;
pub const VIDEO_TDR_FAILURE: u32 = 0x00000116;
pub const DPC_WATCHDOG_VIOLATION: u32 = 0x00000133;
pub const CLOCK_WATCHDOG_TIMEOUT: u32 = 0x00000101;
pub const WHEA_UNCORRECTABLE_ERROR: u32 = 0x00000124;

// ============================================================
// Bug check state
// ============================================================

static BUGCHECK_IN_PROGRESS: AtomicBool = AtomicBool::new(false);
static BUGCHECK_CODE: AtomicU32 = AtomicU32::new(0);
static BUGCHECK_P1: AtomicU32 = AtomicU32::new(0);
static BUGCHECK_P2: AtomicU32 = AtomicU32::new(0);
static BUGCHECK_P3: AtomicU32 = AtomicU32::new(0);
static BUGCHECK_P4: AtomicU32 = AtomicU32::new(0);

// ============================================================
// Crash dump header (DUMP_HEADER subset)
// ============================================================

pub const DUMP_HEADER_SIGNATURE: u32 = 0x45474150; // 'PAGE'
pub const DUMP_HEADER_VALID_DUMP: u32 = 0x454C4941; // 'ALIE'

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CrashDumpHeader {
    pub signature: u32,
    pub valid_dump: u32,
    pub major_version: u32,
    pub minor_version: u32,
    pub directory_table_base: u64,
    pub pfn_database: u64,
    pub ps_loaded_module_list: u64,
    pub ps_active_process_head: u64,
    pub machine_image_type: u32,
    pub number_processors: u32,
    pub bug_check_code: u32,
    pub bug_check_p1: u64,
    pub bug_check_p2: u64,
    pub bug_check_p3: u64,
    pub bug_check_p4: u64,
    pub version_user: [u8; 32],
    pub pae_enabled: u8,
    pub kd_secondary_version: u8,
    pub spare: [u8; 2],
}

impl CrashDumpHeader {
    pub const fn new() -> Self {
        Self {
            signature: DUMP_HEADER_SIGNATURE,
            valid_dump: 0,
            major_version: 10,
            minor_version: 0,
            directory_table_base: 0,
            pfn_database: 0,
            ps_loaded_module_list: 0,
            ps_active_process_head: 0,
            machine_image_type: 0x8664,
            number_processors: 1,
            bug_check_code: 0,
            bug_check_p1: 0,
            bug_check_p2: 0,
            bug_check_p3: 0,
            bug_check_p4: 0,
            version_user: [0; 32],
            pae_enabled: 1,
            kd_secondary_version: 0,
            spare: [0; 2],
        }
    }
}

static mut CRASH_DUMP_HEADER: CrashDumpHeader = CrashDumpHeader::new();

// ============================================================
// BSOD screen painter (VGA text mode 80x25 at 0xB8000)
// ============================================================

const VGA_TEXT_BUFFER: usize = 0xB8000;
const VGA_COLS: usize = 80;
const VGA_ROWS: usize = 25;
// White on blue
const BSOD_ATTR: u16 = 0x1F00;

unsafe fn bsod_write_cell(row: usize, col: usize, ch: u8) {
    if row >= VGA_ROWS || col >= VGA_COLS {
        return;
    }
    let vga = VGA_TEXT_BUFFER as *mut u16;
    // Best effort: physical 0xB8000 is identity mapped during early boot.
    *vga.add(row * VGA_COLS + col) = BSOD_ATTR | (ch as u16);
}

unsafe fn bsod_clear() {
    for r in 0..VGA_ROWS {
        for c in 0..VGA_COLS {
            bsod_write_cell(r, c, b' ');
        }
    }
}

unsafe fn bsod_write_str(row: usize, mut col: usize, s: &[u8]) {
    for &b in s {
        if col >= VGA_COLS {
            break;
        }
        bsod_write_cell(row, col, b);
        col += 1;
    }
}

unsafe fn bsod_write_hex(row: usize, col: usize, value: u64, digits: usize) {
    let mut c = col;
    let mut shift = (digits as u32) * 4;
    while shift > 0 {
        shift -= 4;
        let nibble = ((value >> shift) & 0xF) as u8;
        let ch = if nibble < 10 { b'0' + nibble } else { b'A' + nibble - 10 };
        if c < VGA_COLS {
            bsod_write_cell(row, c, ch);
        }
        c += 1;
    }
}

unsafe fn bsod_paint(code: u32, p1: u64, p2: u64, p3: u64, p4: u64) {
    bsod_clear();
    bsod_write_str(2, 4, b"VladOS");
    bsod_write_str(4, 4, b":(");
    bsod_write_str(
        6,
        4,
        b"Your PC ran into a problem and needs to restart.",
    );
    bsod_write_str(9, 4, b"Stop code: 0x");
    bsod_write_hex(9, 17, code as u64, 8);
    bsod_write_str(11, 4, b"P1: 0x");
    bsod_write_hex(11, 10, p1, 16);
    bsod_write_str(12, 4, b"P2: 0x");
    bsod_write_hex(12, 10, p2, 16);
    bsod_write_str(13, 4, b"P3: 0x");
    bsod_write_hex(13, 10, p3, 16);
    bsod_write_str(14, 4, b"P4: 0x");
    bsod_write_hex(14, 10, p4, 16);
    bsod_write_str(20, 4, b"Collecting crash dump ... 100% complete");
}

// ============================================================
// Bug check name lookup
// ============================================================

pub fn ke_bugcheck_name(code: u32) -> &'static str {
    match code {
        IRQL_NOT_LESS_OR_EQUAL => "IRQL_NOT_LESS_OR_EQUAL",
        KMODE_EXCEPTION_NOT_HANDLED => "KMODE_EXCEPTION_NOT_HANDLED",
        UNEXPECTED_KERNEL_MODE_TRAP => "UNEXPECTED_KERNEL_MODE_TRAP",
        KERNEL_DATA_INPAGE_ERROR => "KERNEL_DATA_INPAGE_ERROR",
        INACCESSIBLE_BOOT_DEVICE => "INACCESSIBLE_BOOT_DEVICE",
        SYSTEM_THREAD_EXCEPTION_NOT_HANDLED => "SYSTEM_THREAD_EXCEPTION_NOT_HANDLED",
        KERNEL_SECURITY_CHECK_FAILURE => "KERNEL_SECURITY_CHECK_FAILURE",
        DRIVER_IRQL_NOT_LESS_OR_EQUAL => "DRIVER_IRQL_NOT_LESS_OR_EQUAL",
        PAGE_FAULT_IN_NONPAGED_AREA => "PAGE_FAULT_IN_NONPAGED_AREA",
        SYSTEM_SERVICE_EXCEPTION => "SYSTEM_SERVICE_EXCEPTION",
        CRITICAL_PROCESS_DIED => "CRITICAL_PROCESS_DIED",
        CRITICAL_STRUCTURE_CORRUPTION => "CRITICAL_STRUCTURE_CORRUPTION",
        MEMORY_MANAGEMENT => "MEMORY_MANAGEMENT",
        PFN_LIST_CORRUPT => "PFN_LIST_CORRUPT",
        MACHINE_CHECK_EXCEPTION => "MACHINE_CHECK_EXCEPTION",
        MANUALLY_INITIATED_CRASH => "MANUALLY_INITIATED_CRASH",
        VIDEO_TDR_FAILURE => "VIDEO_TDR_FAILURE",
        DPC_WATCHDOG_VIOLATION => "DPC_WATCHDOG_VIOLATION",
        CLOCK_WATCHDOG_TIMEOUT => "CLOCK_WATCHDOG_TIMEOUT",
        WHEA_UNCORRECTABLE_ERROR => "WHEA_UNCORRECTABLE_ERROR",
        BAD_POOL_HEADER => "BAD_POOL_HEADER",
        _ => "UNKNOWN_BUGCHECK",
    }
}

// ============================================================
// KeBugCheckEx - main entry point
// ============================================================

/// Issue a bug check with 4 parameters. Never returns.
pub unsafe fn ke_bug_check_ex(
    bug_check_code: u32,
    p1: u64,
    p2: u64,
    p3: u64,
    p4: u64,
) -> ! {
    // Only the first bugcheck wins (nested bugchecks are ignored).
    if BUGCHECK_IN_PROGRESS.swap(true, Ordering::AcqRel) {
        loop {
            core::arch::asm!("cli", options(nomem, nostack));
            core::arch::asm!("hlt", options(nomem, nostack));
        }
    }

    BUGCHECK_CODE.store(bug_check_code, Ordering::Release);
    BUGCHECK_P1.store(p1 as u32, Ordering::Release);
    BUGCHECK_P2.store(p2 as u32, Ordering::Release);
    BUGCHECK_P3.store(p3 as u32, Ordering::Release);
    BUGCHECK_P4.store(p4 as u32, Ordering::Release);

    CRASH_DUMP_HEADER.bug_check_code = bug_check_code;
    CRASH_DUMP_HEADER.bug_check_p1 = p1;
    CRASH_DUMP_HEADER.bug_check_p2 = p2;
    CRASH_DUMP_HEADER.bug_check_p3 = p3;
    CRASH_DUMP_HEADER.bug_check_p4 = p4;
    CRASH_DUMP_HEADER.valid_dump = DUMP_HEADER_VALID_DUMP;

    bc_trace!(
        "*** BUGCHECK 0x{:08X} ({}) P1=0x{:X} P2=0x{:X} P3=0x{:X} P4=0x{:X}",
        bug_check_code,
        ke_bugcheck_name(bug_check_code),
        p1,
        p2,
        p3,
        p4
    );

    // Paint the blue screen (best effort).
    bsod_paint(bug_check_code, p1, p2, p3, p4);

    // Notify WHEA/ETW (best effort, must not fault).
    crate::whea::whea_bugcheck_notify(bug_check_code);

    // Halt all processors.
    loop {
        core::arch::asm!("cli", options(nomem, nostack));
        core::arch::asm!("hlt", options(nomem, nostack));
    }
}

/// KeBugCheck - bug check without parameters.
pub unsafe fn ke_bug_check(bug_check_code: u32) -> ! {
    ke_bug_check_ex(bug_check_code, 0, 0, 0, 0);
}

/// KeBugCheck2 - extended bug check with dump data (Win10 WHEA path).
pub unsafe fn ke_bug_check2(
    bug_check_code: u32,
    p1: u64,
    p2: u64,
    p3: u64,
    p4: u64,
    _dump_data: Pvoid,
    _dump_data_size: u32,
) -> ! {
    ke_bug_check_ex(bug_check_code, p1, p2, p3, p4);
}

// ============================================================
// Query helpers
// ============================================================

/// Returns true if a bugcheck is currently in progress.
pub fn ke_bugcheck_in_progress() -> bool {
    BUGCHECK_IN_PROGRESS.load(Ordering::Acquire)
}

/// Returns the last bugcheck code (0 if none).
pub fn ke_bugcheck_code() -> u32 {
    BUGCHECK_CODE.load(Ordering::Acquire)
}

/// Returns a pointer to the crash dump header.
pub fn ke_crash_dump_header() -> *mut CrashDumpHeader {
    unsafe { &mut CRASH_DUMP_HEADER as *mut CrashDumpHeader }
}

/// KeInitializeBugCheck - early init (clears state).
pub fn ke_initialize_bugcheck() {
    BUGCHECK_IN_PROGRESS.store(false, Ordering::Release);
    BUGCHECK_CODE.store(0, Ordering::Release);
    unsafe {
        CRASH_DUMP_HEADER = CrashDumpHeader::new();
    }
    bc_trace!("KeInitializeBugCheck: ready");
}
