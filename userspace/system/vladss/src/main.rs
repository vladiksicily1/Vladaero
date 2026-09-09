#![no_std]
#![no_main]
#![allow(non_snake_case)]

extern crate alloc;
use ntdll::*;

const STATUS_SUCCESS: NTSTATUS = 0;

// ============================================================
// VladSS — Session Manager (SMSS equivalent)
//
// First user-mode process. Responsible for:
//   1. Creating essential object directories
//   2. Setting up device mappings (CON, NUL, drive letters)
//   3. Starting Session 0 subsystems (csrss, wininit)
//   4. Starting Session 1 subsystems (winlogon)
//   5. Monitoring critical processes (BSOD on csrss/winlogon death)
// ============================================================

unsafe fn create_directory(name: &str) {
    let (oa, _buf) = make_oa(name);
    let mut handle: HANDLE = core::ptr::null_mut();
    let s = NtCreateDirectoryObject(&mut handle, DIRECTORY_QUERY | DIRECTORY_CREATE_OBJECT, &oa);
    if !handle.is_null() {
        NtClose(handle);
    }
}

unsafe fn create_device_mapping(name: &str, target: &str) {
    // Open \DosDevices directory
    let (dir_oa, _dir_buf) = make_oa("\\DosDevices");
    let mut dir_handle: HANDLE = core::ptr::null_mut();
    let s = NtOpenDirectoryObject(&mut dir_handle, DIRECTORY_CREATE_OBJECT, &dir_oa);
    if s == STATUS_SUCCESS {
        let (link_oa, _link_buf) = make_oa(name);
        let (target_us, _target_buf) = u16str(target);
        let mut link_handle: HANDLE = core::ptr::null_mut();
        let _s = NtCreateSymbolicLinkObject(
            &mut link_handle, SYMBOLIC_LINK_QUERY, &link_oa, &target_us,
        );
        if !link_handle.is_null() {
            NtClose(link_handle);
        }
        NtClose(dir_handle);
    }
}

unsafe fn launch_process(path: &str, fatal: bool) -> HANDLE {
    match create_process_from_file(path) {
        Ok(h) => {
            h
        }
        Err(_e) => {
            if fatal {
                // In Windows this would BugCheck (BSOD)
                // For now, hang forever
                loop { ntdll::sleep(1000); }
            }
            core::ptr::null_mut()
        }
    }
}

unsafe fn wait_and_close(h: HANDLE) {
    if h.is_null() { return; }
    NtWaitForSingleObject(h, FALSE, &INFINITE as *const i64 as *mut i64);
    NtClose(h);
}

#[no_mangle]
pub extern "C" fn _start() -> ! {
    unsafe {
        // Phase 1: Create essential object directories
        create_directory("\\BaseNamedObjects");
        create_directory("\\DosDevices");
        create_directory("\\GLOBAL??");
        create_directory("\\ObjectTypes");

        // Phase 2: Create device mappings
        create_device_mapping("CON", "\\Device\\Keyboard");
        create_device_mapping("NUL", "\\Device\\Null");
        create_device_mapping("AUX", "\\Device\\Serial0");
        create_device_mapping("COM1", "\\Device\\Serial0");
        create_device_mapping("COM2", "\\Device\\Serial1");
        create_device_mapping("PRN", "\\Device\\Parallel0");
        create_device_mapping("LPT1", "\\Device\\Parallel0");
        create_device_mapping("C:", "\\Device\\HarddiskVolume0");

        // Phase 3: Create paging file
        // In real Windows: pagefile.sys from HKLM\...\Memory Management

        // Phase 4: Handle PendingFileRenameOperations
        // In real Windows: renames files that couldn't be renamed while in use

        // Phase 5: Start Session 0 subsystems
        let mut csrss_handle = launch_process(
            "\\SystemRoot\\System32\\csrss.vex", true,
        );

        let mut wininit_handle = launch_process(
            "\\SystemRoot\\System32\\wininit.vex", true,
        );

        // Phase 6: Start Session 1 — user login
        let mut winlogon_handle = launch_process(
            "\\SystemRoot\\System32\\winlogon.vex", true,
        );

        // Phase 7: Monitor critical processes
        // In Windows, SMSS monitors csrss and winlogon.
        // If either terminates, SMSS calls KeBugCheckEx with CRITICAL_OBJECT_TERMINATION.
        loop {
            // Check if csrss is still alive
            if !csrss_handle.is_null() {
                let mut exit_status: NTSTATUS = 0;
                let s = NtQueryInformationProcess(
                    csrss_handle,
                    0, // ProcessBasicInformation
                    &mut exit_status as *mut _ as PVOID,
                    core::mem::size_of::<NTSTATUS>() as u32,
                    core::ptr::null_mut(),
                );
                if s != STATUS_SUCCESS {
                    // csrss terminated — bugcheck
                    loop { ntdll::sleep(1000); }
                }
            }

            ntdll::sleep(1000);
        }
    }
}
