#![no_std]
#![no_main]
#![allow(non_snake_case)]

extern crate alloc;
use ntdll::*;

const STATUS_SUCCESS: NTSTATUS = 0;

// ============================================================
// Services — Service Control Manager (SCM)
//
// Started by wininit.exe. Responsible for:
//   1. Starting system services (boot, system, auto-start)
//   2. Managing service lifecycle (start, stop, pause, continue)
//   3. Handling recovery actions (restart service, restart computer)
//   4. Managing svchost.exe instances for DLL-based services
//   5. Providing service control interface to other processes
// ============================================================

// Service states
const SERVICE_STOPPED: u32 = 1;
const SERVICE_START_PENDING: u32 = 2;
const SERVICE_RUNNING: u32 = 4;
const SERVICE_CONTINUE_PENDING: u32 = 5;
const SERVICE_PAUSE_PENDING: u32 = 6;
const SERVICE_PAUSED: u32 = 7;

// Service types
const SERVICE_KERNEL_DRIVER: u32 = 1;
const SERVICE_FILE_SYSTEM_DRIVER: u32 = 2;
const SERVICE_WIN32_OWN_PROCESS: u32 = 16;
const SERVICE_WIN32_SHARE_PROCESS: u32 = 32;

// Service start types
const SERVICE_BOOT_START: u32 = 0;
const SERVICE_SYSTEM_START: u32 = 1;
const SERVICE_AUTO_START: u32 = 2;
const SERVICE_DEMAND_START: u32 = 3;
const SERVICE_DISABLED: u32 = 4;

// Service entry
struct ServiceEntry {
    name: [u16; 64],
    display_name: [u16; 128],
    service_type: u32,
    start_type: u32,
    state: u32,
    process_handle: HANDLE,
    service_dll: [u16; 256],
    is_active: bool,
}

static mut SERVICES: [ServiceEntry; 128] = unsafe { core::mem::zeroed() };
static mut SERVICE_COUNT: u32 = 0;

unsafe fn register_service(name: &str, display: &str, svc_type: u32, start: u32, dll: &str) {
    if SERVICE_COUNT as usize >= SERVICES.len() { return; }
    let svc = &mut SERVICES[SERVICE_COUNT as usize];
    svc.service_type = svc_type;
    svc.start_type = start;
    svc.state = SERVICE_STOPPED;
    svc.process_handle = core::ptr::null_mut();
    svc.is_active = true;

    // Copy name
    let name_u16 = name.encode_utf16().collect::<alloc::vec::Vec<u16>>();
    let copy_len = core::cmp::min(name_u16.len(), 63);
    for i in 0..copy_len { svc.name[i] = name_u16[i]; }
    svc.name[copy_len] = 0;

    // Copy display name
    let disp_u16 = display.encode_utf16().collect::<alloc::vec::Vec<u16>>();
    let copy_len = core::cmp::min(disp_u16.len(), 127);
    for i in 0..copy_len { svc.display_name[i] = disp_u16[i]; }
    svc.display_name[copy_len] = 0;

    // Copy DLL
    let dll_u16 = dll.encode_utf16().collect::<alloc::vec::Vec<u16>>();
    let copy_len = core::cmp::min(dll_u16.len(), 255);
    for i in 0..copy_len { svc.service_dll[i] = dll_u16[i]; }
    svc.service_dll[copy_len] = 0;

    SERVICE_COUNT += 1;
}

unsafe fn start_service(svc_idx: u32) -> bool {
    if svc_idx as u32 >= SERVICE_COUNT { return false; }
    let svc = &mut SERVICES[svc_idx as usize];
    if !svc.is_active || svc.state != SERVICE_STOPPED { return false; }
    if svc.start_type == SERVICE_DISABLED { return false; }

    svc.state = SERVICE_START_PENDING;

    // For DLL-based services, start svchost.exe and load the DLL
    // For exe-based services, start the executable directly
    // In VladOS, all services run as separate processes

    // Build the executable path: \SystemRoot\System32\svchost.exe -k <group>
    // In real Windows, services run inside svchost.exe instances.
    // Each svchost hosts a group of services.
    match create_process_from_file("\\SystemRoot\\System32\\svchost.vex") {
        Ok(h) => {
            svc.process_handle = h;
            svc.state = SERVICE_RUNNING;
            true
        }
        Err(_) => {
            svc.state = SERVICE_STOPPED;
            false
        }
    }
}

unsafe fn stop_service(svc_idx: u32) -> bool {
    if svc_idx as u32 >= SERVICE_COUNT { return false; }
    let svc = &mut SERVICES[svc_idx as usize];
    if !svc.is_active || svc.state == SERVICE_STOPPED { return false; }

    // Terminate the service process
    if !svc.process_handle.is_null() {
        NtTerminateProcess(svc.process_handle, 0);
        NtClose(svc.process_handle);
        svc.process_handle = core::ptr::null_mut();
    }

    svc.state = SERVICE_STOPPED;
    true
}

unsafe fn load_services_from_registry() {
    // Read service entries from:
    //   HKLM\SYSTEM\CurrentControlSet\Services
    //
    // Each key represents a service with values:
    //   DisplayName — display name
    //   Type — service type
    //   Start — start type (0=Boot, 1=System, 2=Auto, 3=Demand, 4=Disabled)
    //   ImagePath — executable path (for exe-based services)
    //   ServiceDll — DLL path (for svchost-based services)
    //   Group — service group (for load ordering)

    // Register built-in services for VladOS
    register_service("RpcSs", "Remote Procedure Call", SERVICE_WIN32_SHARE_PROCESS, SERVICE_AUTO_START, "rpcss.dll");
    register_service("Dhcp", "DHCP Client", SERVICE_WIN32_OWN_PROCESS, SERVICE_AUTO_START, "dhcpcsvc.dll");
    register_service("Dnscache", "DNS Client", SERVICE_WIN32_SHARE_PROCESS, SERVICE_AUTO_START, "dnscache.dll");
    register_service("EventLog", "Windows Event Log", SERVICE_WIN32_OWN_PROCESS, SERVICE_AUTO_START, "wevtsvc.dll");
    register_service("Schedule", "Task Scheduler", SERVICE_WIN32_OWN_PROCESS, SERVICE_AUTO_START, "schedsvc.dll");
    register_service("Spooler", "Print Spooler", SERVICE_WIN32_OWN_PROCESS, SERVICE_AUTO_START, "spoolsv.exe");
    register_service("LanmanServer", "Server", SERVICE_WIN32_SHARE_PROCESS, SERVICE_AUTO_START, "srvsvc.dll");
    register_service("LanmanWorkstation", "Workstation", SERVICE_WIN32_SHARE_PROCESS, SERVICE_AUTO_START, "wkssvc.dll");
}

#[panic_handler]
fn panic(_info: &core::panic::PanicInfo) -> ! { loop {} }

#[no_mangle]
pub extern "C" fn _start() -> ! {
    unsafe {
        // Phase 1: Initialize SCM
        load_services_from_registry();

        // Phase 2: Start system-start and auto-start services
        for i in 0..SERVICE_COUNT {
            if SERVICES[i as usize].start_type == SERVICE_AUTO_START ||
               SERVICES[i as usize].start_type == SERVICE_SYSTEM_START
            {
                start_service(i);
            }
        }

        // Phase 3: Main SCM loop
        // In real Windows, SCM:
        //   1. Monitors service health (restarts crashed services)
        //   2. Handles service control requests (start/stop/pause/continue)
        //   3. Handles recovery options
        //   4. Reports service status to the Service Control Manager
        loop {
            // Check service health
            for i in 0..SERVICE_COUNT {
                let svc = &mut SERVICES[i as usize];
                if svc.is_active && svc.state == SERVICE_RUNNING && !svc.process_handle.is_null() {
                    // Check if process is still alive
                    let mut exit_status: NTSTATUS = 0;
                    let s = NtQueryInformationProcess(
                        svc.process_handle,
                        0, // ProcessBasicInformation
                        &mut exit_status as *mut _ as PVOID,
                        core::mem::size_of::<NTSTATUS>() as u32,
                        core::ptr::null_mut(),
                    );
                    if s != STATUS_SUCCESS {
                        // Service process terminated unexpectedly
                        // Apply recovery action (restart, restart computer, etc.)
                        svc.state = SERVICE_STOPPED;
                        svc.process_handle = core::ptr::null_mut();

                        // Simple recovery: restart the service
                        ntdll::sleep(5000); // Wait 5 seconds before restart
                        start_service(i);
                    }
                }
            }

            ntdll::sleep(1000);
        }
    }
}
