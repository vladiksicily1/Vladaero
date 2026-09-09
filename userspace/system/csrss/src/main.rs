#![no_std]
#![no_main]
#![allow(non_snake_case)]

extern crate alloc;
use ntdll::*;

const STATUS_SUCCESS: NTSTATUS = 0;

// ============================================================
// CSRSS — Client/Server Runtime Subsystem
//
// This is the Win32 subsystem server process.
// In Windows, CSRSS is responsible for:
//   1. Console window management
//   2. Thread creation/deletion side effects
//   3. Process deletion side effects (cleanup)
//   4. Exception dispatching
//   5. NLS (National Language Support)
//   6. Hard error mode reporting
//
// CSRSS runs in every session. If it terminates, Windows bugchecks.
// ============================================================

// Simple process table tracking
struct ProcessEntry {
    pid: u32,
    session_id: u32,
    console_handle: HANDLE,
    is_active: bool,
}

static mut PROCESS_TABLE: [ProcessEntry; 256] = unsafe { core::mem::zeroed() };
static mut PROCESS_COUNT: u32 = 0;

unsafe fn register_process(pid: u32, session_id: u32) {
    if (PROCESS_COUNT as usize) < PROCESS_TABLE.len() {
        let entry = &mut PROCESS_TABLE[PROCESS_COUNT as usize];
        entry.pid = pid;
        entry.session_id = session_id;
        entry.console_handle = core::ptr::null_mut();
        entry.is_active = true;
        PROCESS_COUNT += 1;
    }
}

unsafe fn unregister_process(pid: u32) {
    for i in 0..PROCESS_COUNT as usize {
        if PROCESS_TABLE[i].pid == pid && PROCESS_TABLE[i].is_active {
            PROCESS_TABLE[i].is_active = false;
            // Notify session manager if csrss/winlogon process dies
            break;
        }
    }
}

unsafe fn find_process(pid: u32) -> Option<usize> {
    for i in 0..PROCESS_COUNT as usize {
        if PROCESS_TABLE[i].pid == pid && PROCESS_TABLE[i].is_active {
            return Some(i);
        }
    }
    None
}

// Create console for a process
unsafe fn create_console(session_id: u32) -> Result<HANDLE, NTSTATUS> {
    // Open or create \BaseNamedObjects\Console directory
    let (dir_oa, _dir_buf) = make_oa("\\BaseNamedObjects\\Console");
    let mut dir_handle: HANDLE = core::ptr::null_mut();
    let status = NtOpenDirectoryObject(&mut dir_handle, DIRECTORY_QUERY | DIRECTORY_CREATE_OBJECT, &dir_oa);
    if status != STATUS_SUCCESS {
        // Try creating it
        let _s = NtCreateDirectoryObject(&mut dir_handle, DIRECTORY_QUERY | DIRECTORY_CREATE_OBJECT, &dir_oa);
    }

    // Create a console input buffer
    let (input_oa, _input_buf) = make_oa("\\BaseNamedObjects\\Console\\InputBuffer");
    let mut input_handle: HANDLE = core::ptr::null_mut();
    let mut max_size: i64 = 4096;
    let _s = NtCreateSection(
        &mut input_handle,
        SECTION_ALL_ACCESS,
        &input_oa,
        &mut max_size,
        PAGE_READWRITE,
        0x08000000, // SEC_COMMIT
        core::ptr::null_mut(),
    );

    if !dir_handle.is_null() {
        NtClose(dir_handle);
    }

    // Return a handle representing the console
    // In real Windows this is a handle to the console device
    if input_handle.is_null() {
        // Fallback: return a dummy handle
        Ok(1isize as HANDLE)
    } else {
        Ok(input_handle)
    }
}

// Handle CSRSS client request (simplified)
unsafe fn handle_request(request_type: u32, param1: usize, param2: usize) -> NTSTATUS {
    match request_type {
        // CSRSS_CONNECT_PROCESS — process connecting to CSRSS
        0x10000 => {
            let session_id = param1 as u32;
            let pid = param2 as u32;
            register_process(pid, session_id);
            STATUS_SUCCESS
        }
        // CSRSS_DISCONNECT_PROCESS — process disconnecting
        0x10001 => {
            let pid = param1 as u32;
            unregister_process(pid);
            STATUS_SUCCESS
        }
        // CSRSS_CREATE_PROCESS — new process notification
        0x10002 => {
            let pid = param1 as u32;
            let session_id = param2 as u32;
            register_process(pid, session_id);
            STATUS_SUCCESS
        }
        // CSRSS_TERMINATE_PROCESS — process termination notification
        0x10003 => {
            let pid = param1 as u32;
            unregister_process(pid);
            STATUS_SUCCESS
        }
        // CSRSS_CREATE_THREAD — thread creation
        0x10004 => STATUS_SUCCESS,
        // CSRSS_TERMINATE_THREAD — thread termination
        0x10005 => STATUS_SUCCESS,
        // CSRSS_EXIT_PROCESS — process exit
        0x10006 => {
            let pid = param1 as u32;
            unregister_process(pid);
            STATUS_SUCCESS
        }
        // CSRSS_GET_PROCESS_TIMES — query process info
        0x10007 => STATUS_SUCCESS,
        // CSRSS_WRITE_CONSOLE — write to console
        0x10010 => STATUS_SUCCESS,
        // CSRSS_READ_CONSOLE — read from console
        0x10011 => STATUS_SUCCESS,
        // CSRSS_ALLOC_CONSOLE — allocate console for process
        0x10012 => {
            let session_id = param1 as u32;
            match create_console(session_id) {
                Ok(_h) => STATUS_SUCCESS,
                Err(e) => e,
            }
        }
        // CSRSS_FREE_CONSOLE — free console
        0x10013 => STATUS_SUCCESS,
        // CSRSS_SET_CURSOR — set console cursor position
        0x10020 => STATUS_SUCCESS,
        // CSRSS_WRITE_CONSOLE_OUTPUT — write to console output
        0x10021 => STATUS_SUCCESS,
        // CSRSS_READ_CONSOLE_INPUT — read console input
        0x10022 => STATUS_SUCCESS,
        // CSRSS_FLUSH_CONSOLE — flush console buffers
        0x10023 => STATUS_SUCCESS,
        // CSRSS_GET_CONSOLE_MODE — get console mode
        0x10030 => STATUS_SUCCESS,
        // CSRSS_SET_CONSOLE_MODE — set console mode
        0x10031 => STATUS_SUCCESS,
        // CSRSS_SET_CONSOLE_TITLE — set console window title
        0x10032 => STATUS_SUCCESS,
        _ => STATUS_SUCCESS,
    }
}

#[panic_handler]
fn panic(_info: &core::panic::PanicInfo) -> ! { loop {} }

#[no_mangle]
pub extern "C" fn _start() -> ! {
    unsafe {
        // Phase 1: Initialize CSRSS
        // CSRSS runs in Session 0 and Session 1

        // Create \BaseNamedObjects directory if it doesn't exist
        let (bno_oa, _bno_buf) = make_oa("\\BaseNamedObjects");
        let mut bno_handle: HANDLE = core::ptr::null_mut();
        let _s = NtCreateDirectoryObject(&mut bno_handle, DIRECTORY_QUERY | DIRECTORY_CREATE_OBJECT, &bno_oa);
        if !bno_handle.is_null() { NtClose(bno_handle); }

        // Phase 2: Create shared memory section for NLS data
        let (nls_oa, _nls_buf) = make_oa("\\BaseNamedObjects\\Windows\\SharedSection");
        let mut nls_handle: HANDLE = core::ptr::null_mut();
        let mut max_size: i64 = 65536; // 64KB shared section
        let _s = NtCreateSection(
            &mut nls_handle,
            SECTION_MAP_READ | SECTION_MAP_WRITE,
            &nls_oa,
            &mut max_size,
            PAGE_READWRITE,
            0x08000000,
            core::ptr::null_mut(),
        );

        // Phase 3: Create event for console I/O synchronization
        let (evt_oa, _evt_buf) = make_oa("\\BaseNamedObjects\\ConsoleEvent");
        let mut evt_handle: HANDLE = core::ptr::null_mut();
        let _s = NtCreateEvent(&mut evt_handle, 0x001F0003, &evt_oa, 0, TRUE);

        // Phase 4: Create mutant for exclusive access to shared structures
        let (mut_oa, _mut_buf) = make_oa("\\BaseNamedObjects\\CsrssLock");
        let mut mut_handle: HANDLE = core::ptr::null_mut();
        let _s = NtCreateMutant(&mut mut_handle, 0x001F0001, &mut_oa, FALSE);

        // Phase 5: Initialize NLS (National Language Support)
        // Load default codepage info from registry
        // In real Windows: reads from HKLM\SYSTEM\CurrentControlSet\Control\Nls\CodePage

        // Phase 6: Main request loop
        // In real Windows, CSRSS sits in a loop processing requests from client
        // processes via LPC (Local Procedure Call) ports.
        // Each request is dispatched through the handle_request function.

        loop {
            // Poll for requests (simplified — no real LPC yet)
            // In a real implementation this would wait on an LPC port

            // Check for process termination events
            for i in 0..PROCESS_COUNT as usize {
                if PROCESS_TABLE[i].is_active && !PROCESS_TABLE[i].console_handle.is_null() {
                    // Check if process is still alive
                    let mut status: NTSTATUS = 0;
                    let s = NtQueryInformationProcess(
                        PROCESS_TABLE[i].console_handle as HANDLE,
                        0, // ProcessBasicInformation
                        core::ptr::null_mut(),
                        0,
                        core::ptr::null_mut(),
                    );
                    if s != STATUS_SUCCESS {
                        // Process terminated
                        PROCESS_TABLE[i].is_active = false;
                    }
                }
            }

            // Sleep briefly to avoid busy-wait
            ntdll::sleep(50);
        }
    }
}
