#![no_std]
#![allow(non_camel_case_types)]

/// # kernel32.vll — Core Win32 API Library
///
/// Реальные Win32 API функции, которые оборачивают Nt* syscall stubs.

extern crate alloc;

use core::result::Result::{Ok, Err};
use core::iter::Iterator;
use ntdll::*;

// ============================================================
// Types
// ============================================================

pub type DWORD = u32;
pub type WORD = u16;
pub type BYTE = u8;
pub type BOOL = i32;
pub type LPCSTR = *const u8;
pub type LPSTR = *mut u8;
pub type LPCWSTR = *const u16;
pub type LPWSTR = *mut u16;
pub type LPVOID = *mut core::ffi::c_void;
pub type SIZE_T = usize;
pub type HANDLE = *mut core::ffi::c_void;

pub const TRUE: BOOL = 1;
pub const FALSE: BOOL = 0;
pub const INVALID_HANDLE_VALUE: HANDLE = -1isize as HANDLE;
pub const MAX_PATH: usize = 260;

pub const GENERIC_READ: DWORD = 0x80000000;
pub const GENERIC_WRITE: DWORD = 0x40000000;

pub const CREATE_NEW: DWORD = 1;
pub const CREATE_ALWAYS: DWORD = 2;
pub const OPEN_EXISTING: DWORD = 3;
pub const OPEN_ALWAYS: DWORD = 4;
pub const TRUNCATE_EXISTING: DWORD = 5;

pub const MEM_COMMIT: DWORD = 0x00001000;
pub const MEM_RESERVE: DWORD = 0x00002000;
pub const MEM_RELEASE: DWORD = 0x00008000;

pub const PAGE_READWRITE: DWORD = 0x04;
pub const PAGE_EXECUTE_READWRITE: DWORD = 0x40;

pub const INFINITE: DWORD = 0xFFFFFFFF;
pub const WAIT_OBJECT_0: DWORD = 0;
pub const WAIT_FAILED: DWORD = 0xFFFFFFFF;

pub const STD_OUTPUT_HANDLE: DWORD = -11i32 as DWORD;
pub const STD_INPUT_HANDLE: DWORD = -10i32 as DWORD;
pub const STD_ERROR_HANDLE: DWORD = -12i32 as DWORD;

// ============================================================
// STARTUPINFOW
// ============================================================

#[repr(C)]
pub struct STARTUPINFOW {
    pub cb: DWORD,
    pub lp_reserved: LPWSTR,
    pub lp_desktop: LPWSTR,
    pub lp_title: LPWSTR,
    pub dw_x: DWORD,
    pub dw_y: DWORD,
    pub dw_x_size: DWORD,
    pub dw_y_size: DWORD,
    pub dw_x_count_chars: DWORD,
    pub dw_y_count_chars: DWORD,
    pub dw_fill_attribute: DWORD,
    pub dw_flags: DWORD,
    pub w_show_window: WORD,
    pub cb_reserved2: WORD,
    pub lp_reserved2: *mut BYTE,
    pub h_std_input: HANDLE,
    pub h_std_output: HANDLE,
    pub h_std_error: HANDLE,
}

// ============================================================
// PROCESS_INFORMATION
// ============================================================

#[repr(C)]
pub struct PROCESS_INFORMATION {
    pub h_process: HANDLE,
    pub h_thread: HANDLE,
    pub dw_process_id: DWORD,
    pub dw_thread_id: DWORD,
}

// ============================================================
// CreateFileW
// ============================================================

pub fn CreateFileW(
    lp_filename: LPCWSTR,
    dw_desired_access: DWORD,
    _dw_share_mode: DWORD,
    _lp_security_attributes: *mut core::ffi::c_void,
    dw_creation_disposition: DWORD,
    _dw_flags_and_attributes: DWORD,
    _h_template_file: HANDLE,
) -> HANDLE {
    unsafe {
        let mut len = 0;
        let mut ptr = lp_filename;
        while *ptr != 0 { len += 1; ptr = ptr.add(1); }
        let path_slice = core::slice::from_raw_parts(lp_filename, len);
        let path = alloc::string::String::from_utf16_lossy(path_slice);

        let nt_disposition = match dw_creation_disposition {
            CREATE_NEW => FILE_CREATE,
            CREATE_ALWAYS => FILE_OVERWRITE_IF,
            OPEN_EXISTING => FILE_OPEN,
            OPEN_ALWAYS => FILE_OPEN_IF,
            TRUNCATE_EXISTING => FILE_OVERWRITE,
            _ => FILE_OPEN,
        };

        let nt_access = if dw_desired_access & GENERIC_READ != 0 { FILE_GENERIC_READ }
                        else if dw_desired_access & GENERIC_WRITE != 0 { FILE_GENERIC_WRITE }
                        else { dw_desired_access };

        match ntdll::open(&path, nt_access, nt_disposition) {
            Ok(handle) => handle,
            Err(_) => INVALID_HANDLE_VALUE,
        }
    }
}

// ============================================================
// CreateFileA
// ============================================================

pub fn CreateFileA(
    lp_filename: LPCSTR,
    dw_desired_access: DWORD,
    _dw_share_mode: DWORD,
    _lp_security_attributes: *mut core::ffi::c_void,
    dw_creation_disposition: DWORD,
    _dw_flags_and_attributes: DWORD,
    _h_template_file: HANDLE,
) -> HANDLE {
    unsafe {
        let mut len = 0;
        let mut ptr = lp_filename;
        while *ptr != 0 { len += 1; ptr = ptr.add(1); }
        let path_slice = core::slice::from_raw_parts(lp_filename, len);
        let path = core::str::from_utf8(path_slice).unwrap_or("");

        let nt_disposition = match dw_creation_disposition {
            CREATE_NEW => FILE_CREATE,
            CREATE_ALWAYS => FILE_OVERWRITE_IF,
            OPEN_EXISTING => FILE_OPEN,
            OPEN_ALWAYS => FILE_OPEN_IF,
            TRUNCATE_EXISTING => FILE_OVERWRITE,
            _ => FILE_OPEN,
        };

        let nt_access = if dw_desired_access & GENERIC_READ != 0 { FILE_GENERIC_READ }
                        else if dw_desired_access & GENERIC_WRITE != 0 { FILE_GENERIC_WRITE }
                        else { dw_desired_access };

        match ntdll::open(path, nt_access, nt_disposition) {
            Ok(handle) => handle,
            Err(_) => INVALID_HANDLE_VALUE,
        }
    }
}

// ============================================================
// ReadFile
// ============================================================

pub fn ReadFile(
    h_file: HANDLE,
    lp_buffer: LPVOID,
    n_number_of_bytes_to_read: DWORD,
    lp_number_of_bytes_read: *mut DWORD,
    _lp_overlapped: LPVOID,
) -> BOOL {
    unsafe {
        let buf = core::slice::from_raw_parts_mut(lp_buffer as *mut u8, n_number_of_bytes_to_read as usize);
        match ntdll::read(h_file, buf) {
            Ok(bytes_read) => {
                if !lp_number_of_bytes_read.is_null() { *lp_number_of_bytes_read = bytes_read as DWORD; }
                TRUE
            }
            Err(_) => FALSE,
        }
    }
}

// ============================================================
// WriteFile
// ============================================================

pub fn WriteFile(
    h_file: HANDLE,
    lp_buffer: *const core::ffi::c_void,
    n_number_of_bytes_to_write: DWORD,
    lp_number_of_bytes_written: *mut DWORD,
    _lp_overlapped: LPVOID,
) -> BOOL {
    unsafe {
        let buf = core::slice::from_raw_parts(lp_buffer as *const u8, n_number_of_bytes_to_write as usize);
        match ntdll::write(h_file, buf) {
            Ok(bytes_written) => {
                if !lp_number_of_bytes_written.is_null() { *lp_number_of_bytes_written = bytes_written as DWORD; }
                TRUE
            }
            Err(_) => FALSE,
        }
    }
}

// ============================================================
// CloseHandle
// ============================================================

pub fn CloseHandle(hObject: HANDLE) -> BOOL {
    match ntdll::close(hObject) { Ok(_) => TRUE, Err(_) => FALSE }
}

// ============================================================
// VirtualAlloc / VirtualFree
// ============================================================

pub fn VirtualAlloc(lp_address: LPVOID, dw_size: SIZE_T, _fl_allocation_type: DWORD, _fl_protect: DWORD) -> LPVOID {
    match ntdll::alloc_mem(dw_size) {
        Ok(ptr) => ptr as LPVOID, Err(_) => core::ptr::null_mut(),
    }
}

pub fn VirtualFree(lp_address: LPVOID, _dw_size: SIZE_T, _dw_free_type: DWORD) -> BOOL {
    match ntdll::free_mem(lp_address as PVOID, 0) { Ok(_) => TRUE, Err(_) => FALSE }
}

// ============================================================
// Sleep / ExitProcess
// ============================================================

pub fn Sleep(dw_milliseconds: DWORD) { ntdll::sleep(dw_milliseconds as u64); }

pub fn ExitProcess(u_exit_code: DWORD) -> ! { ntdll::exit(u_exit_code as i32) }

// ============================================================
// CreateEventW / SetEvent / WaitForSingleObject
// ============================================================

pub fn CreateEventW(
    _lp_event_attributes: LPVOID,
    b_manual_reset: BOOL,
    b_initial_state: BOOL,
    _lp_name: LPCWSTR,
) -> HANDLE {
    unsafe {
        let mut handle: HANDLE = core::ptr::null_mut();
        let event_type = if b_manual_reset != 0 { 1 } else { 0 };
        let initial = if b_initial_state != 0 { 1u8 } else { 0u8 };
        let status = NtCreateEvent(&mut handle, 0x001F0003, core::ptr::null(), event_type, initial);
        if status == STATUS_SUCCESS { handle } else { INVALID_HANDLE_VALUE }
    }
}

pub fn SetEvent(h_event: HANDLE) -> BOOL {
    unsafe { match NtSetEvent(h_event, core::ptr::null_mut()) { STATUS_SUCCESS => TRUE, _ => FALSE } }
}

pub fn WaitForSingleObject(h_handle: HANDLE, dw_milliseconds: DWORD) -> DWORD {
    unsafe {
        let mut timeout: i64 = if dw_milliseconds == INFINITE { 0x7FFFFFFFFFFFFFFF }
                               else { -(dw_milliseconds as i64 * 10000) };
        match NtWaitForSingleObject(h_handle, 0u8, &mut timeout) {
            STATUS_SUCCESS => WAIT_OBJECT_0, _ => WAIT_FAILED,
        }
    }
}

// ============================================================
// GetStdHandle / GetCommandLine
// ============================================================

pub fn GetStdHandle(n_std_handle: DWORD) -> HANDLE {
    match n_std_handle {
        STD_INPUT_HANDLE => -5isize as HANDLE,
        STD_OUTPUT_HANDLE => -6isize as HANDLE,
        STD_ERROR_HANDLE => -7isize as HANDLE,
        _ => INVALID_HANDLE_VALUE,
    }
}

pub fn GetCommandLineW() -> LPWSTR {
    static mut CMD: [u16; 256] = [0; 256];
    unsafe {
        if CMD[0] == 0 {
            let name = "cmd.vex\0";
            for (i, &b) in name.as_bytes().iter().enumerate() { CMD[i] = b as u16; }
        }
        CMD.as_mut_ptr()
    }
}

pub fn GetCommandLineA() -> LPCSTR {
    static mut CMD: [u8; 256] = [0; 256];
    unsafe {
        if CMD[0] == 0 { let name = b"cmd.vex\0"; CMD[..name.len()].copy_from_slice(name); }
        CMD.as_ptr()
    }
}

pub fn GetCurrentProcessId() -> DWORD { 1 }

pub fn GetCurrentProcess() -> HANDLE { -1isize as HANDLE }

static mut LAST_ERROR: DWORD = 0;
pub fn GetLastError() -> DWORD { unsafe { LAST_ERROR } }
pub fn SetLastError(dw_err_code: DWORD) { unsafe { LAST_ERROR = dw_err_code; } }

// ============================================================
// Version
// ============================================================

pub fn GetVersion() -> DWORD { 10 | (0 << 8) | (19041 << 16) }

#[repr(C)]
pub struct OSVERSIONINFOW {
    pub dw_os_version_info_size: DWORD,
    pub dw_major_version: DWORD,
    pub dw_minor_version: DWORD,
    pub dw_build_number: DWORD,
    pub dw_platform_id: DWORD,
    pub sz_csd_version: [u16; 128],
}

pub fn GetVersionExW(lp_version_info: *mut OSVERSIONINFOW) -> BOOL {
    unsafe {
        if !lp_version_info.is_null() {
            let info = &mut *lp_version_info;
            info.dw_os_version_info_size = core::mem::size_of::<OSVERSIONINFOW>() as DWORD;
            info.dw_major_version = 10;
            info.dw_minor_version = 0;
            info.dw_build_number = 19041;
            info.dw_platform_id = 2;
            info.sz_csd_version[0] = 0;
        }
        TRUE
    }
}

pub fn GetStartupInfoW(lp_startup_info: *mut STARTUPINFOW) {
    unsafe {
        if !lp_startup_info.is_null() {
            let info = &mut *lp_startup_info;
            info.cb = core::mem::size_of::<STARTUPINFOW>() as DWORD;
        }
    }
}
