#![no_std]
#![no_main]
#![allow(non_snake_case)]

extern crate alloc;
use ntdll::*;

const STATUS_SUCCESS: NTSTATUS = 0;

// ============================================================
// WinInit — Windows Startup Application
//
// Started by SMSS in Session 0. Responsible for:
//   1. Starting services.exe (SCM)
//   2. Starting lsass.exe (authentication)
//   3. Starting lsm.exe (session manager)
// ============================================================

unsafe fn launch_process(path: &str, background: bool) -> HANDLE {
    match create_process_from_file(path) {
        Ok(h) => h,
        Err(_e) => {
            if !background {
                loop { ntdll::sleep(1000); }
            }
            core::ptr::null_mut()
        }
    }
}

#[panic_handler]
fn panic(_info: &core::panic::PanicInfo) -> ! { loop {} }

#[no_mangle]
pub extern "C" fn _start() -> ! {
    unsafe {
        // Phase 1: Start services.exe (Service Control Manager)
        let services_h = launch_process(
            "\\SystemRoot\\System32\\services.vex", false,
        );

        // Phase 2: Start lsass.exe (Local Security Authority)
        let lsass_h = launch_process(
            "\\SystemRoot\\System32\\lsass.vex", false,
        );

        // Phase 3: Start lsm.exe (Local Session Manager)
        let _lsm_h = launch_process(
            "\\SystemRoot\\System32\\lsm.vex", false,
        );

        // Phase 4: Start svchost.exe instances
        // In real Windows, svchost instances are spawned for each service group.
        // Services are listed in HKLM\SYSTEM\CurrentControlSet\Services.
        // Each service has a ServiceDll value pointing to the DLL to load.

        // WinInit stays resident and monitors services.
        // If services.exe terminates, WinInit initiates shutdown.
        // If lsass.exe terminates, WinInit forces immediate shutdown.
        loop {
            // Monitor services.exe health
            if !services_h.is_null() {
                let mut exit_status: NTSTATUS = 0;
                let s = NtQueryInformationProcess(
                    services_h,
                    0, // ProcessBasicInformation
                    &mut exit_status as *mut _ as PVOID,
                    core::mem::size_of::<NTSTATUS>() as u32,
                    core::ptr::null_mut(),
                );
                if s != STATUS_SUCCESS {
                    // services.exe terminated — initiate shutdown
                    loop { ntdll::sleep(1000); }
                }
            }

            // Monitor lsass.exe health
            if !lsass_h.is_null() {
                let mut exit_status: NTSTATUS = 0;
                let s = NtQueryInformationProcess(
                    lsass_h,
                    0,
                    &mut exit_status as *mut _ as PVOID,
                    core::mem::size_of::<NTSTATUS>() as u32,
                    core::ptr::null_mut(),
                );
                if s != STATUS_SUCCESS {
                    // lsass terminated — force immediate shutdown
                    loop { ntdll::sleep(1000); }
                }
            }

            ntdll::sleep(1000);
        }
    }
}
