/// Init - kernel startup (KiSystemStartup / ExpInitializeExecutive)
///
/// Phase 0: bugcheck, HAL, physical memory, IDT/GDT dispatch.
/// Phase 1: executive subsystems, boot drivers, filesystems,
/// win32k, network, syscall table. Hands off to the Session
/// Manager (vladss/smss) as PID 4's first user thread.

use core::ffi::c_void;

use crate::types::*;

// ============================================================
// Boot parameters (from VladBoot UEFI/BIOS loader)
// ============================================================

pub const VLAD_BOOT_MAGIC: u32 = 0x44414C56; // "VLAD"

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct InitFramebuffer {
    pub base: u64,
    pub width: u32,
    pub height: u32,
    pub pitch: u32,
    pub bpp: u32,
}

#[repr(C)]
pub struct VladBootParams {
    pub magic: u32,
    pub version: u32,
    pub memory_map_ptr: u64,
    pub memory_map_entries: u32,
    pub acpi_rsdp: u64,
    pub framebuffer: InitFramebuffer,
    pub ramdisk_base: u64,
    pub ramdisk_size: u64,
    pub cmdline: [u8; 256],
}

static mut INIT_PHASE: u32 = 0;

pub fn init_phase() -> u32 {
    unsafe { INIT_PHASE }
}

// ============================================================
// Phase 0 - minimal kernel (no executive yet)
// ============================================================

fn init_phase0(params: *const VladBootParams) -> NtStatus {
    unsafe {
    INIT_PHASE = 0;
    crate::ke::bugcheck::ke_initialize_bugcheck();
    crate::kernel_log!("[Init] VladOS kernel starting (phase 0)\n");

    if !params.is_null() && (*params).magic == VLAD_BOOT_MAGIC {
        // ACPI handoff for the bus driver.
        if (*params).acpi_rsdp != 0 {
            crate::drivers::bus::acpi::acpi_set_rsdp((*params).acpi_rsdp);
        }
        // Framebuffer handoff for bootvid/win32k.
        let fb = &(*params).framebuffer;
        if fb.base != 0 {
            crate::drivers::video::bootvid::bootvid_set_display(
                fb.base,
                fb.width,
                fb.height,
                fb.pitch,
                fb.bpp,
            );
        }
    }

    crate::hal::hal_initialize();
    crate::mm::mm_initialize();
    crate::ke::interrupt::ki_initialize_idt();
    crate::ke::dispatcher::ki_initialize_dispatcher();
    crate::ke::dpc::ki_initialize_dpc();
    crate::ke::timer::ki_initialize_timer();
    crate::ke::exception::ki_initialize_exception();
    crate::ke::exception::ki_initialize_veh();
    crate::kernel_log!("[Init] Phase 0 complete\n");
    STATUS_SUCCESS
    }
}

// ============================================================
// Phase 1 - executive + drivers + subsystems
// ============================================================

fn init_phase1() -> NtStatus {
    unsafe {
    INIT_PHASE = 1;
    crate::kernel_log!("[Init] Phase 1: executive\n");

    // Core executive (NT namespace order matters).
    crate::ob::ob_initialize();
    crate::ps::ps_initialize();
    crate::se::se_initialize();
    crate::cm::cm_initialize();
    crate::kernel_log!("[Init] Core executive up\n");

    // Runtime + subsidiary managers.
    if crate::rtl::rtl_initialize_heap_manager() != STATUS_SUCCESS {
        return STATUS_NO_MEMORY;
    }
    crate::nls::nls_init();
    crate::nls::nls_init_cyrillic();
    crate::dbg::kd_init_debugger();
    crate::verifier::vf_init_system();
    crate::etw::etw_initialize();
    crate::wmi::wmi_initialize();
    crate::whea::whea_initialize();
    crate::kse::kse_initialize();
    crate::drvdb::drvdb_initialize();
    crate::alpc::alpc_initialize();
    crate::tm::tm_initialize();
    crate::sm::sm_init();
    crate::pf::pf_initialize_super_pages();
    crate::wer::wer_init();
    crate::wdi::wdi_initialize();
    crate::anfw::anfw_initialize();
    crate::bcd::bcd_initialize_default_store();
    crate::po::po_initialize_power_manager();
    crate::pnp::ppnp_init_system();

    // Loader + cache + filesystems.
    if crate::ldr::ldrp_initialize() != STATUS_SUCCESS {
        crate::kernel_log!("[Init] Loader init failed\n");
        return STATUS_NO_MEMORY;
    }
    if crate::cc::cc_initialize_cache_manager() != STATUS_SUCCESS {
        return STATUS_NO_MEMORY;
    }
    crate::fs::fs_initialize();
    crate::fs::init_builtin_filesystems();

    // Boot-start drivers (ServiceGroupOrder).
    if crate::drivers::drivers_register_all() != STATUS_SUCCESS {
        return STATUS_NO_MEMORY;
    }
    crate::drivers::drivers_load_boot_start();

    // Mount boot volume filesystems (probe each volume).
    let mut v = 0usize;
    while v < crate::drivers::storage::classpnp::class_volume_count() {
        crate::fs::fs_mount_volume(v);
        v += 1;
    }

    // Graphics + network.
    if crate::win32k::win32k_initialize() != STATUS_SUCCESS {
        crate::kernel_log!("[Init] win32k init failed\n");
    }
    if crate::net::net_initialize() != STATUS_SUCCESS {
        crate::kernel_log!("[Init] net init failed\n");
    }

    // System process (PID 4) + syscall table.
    crate::ps::ps_initialize_system_process();
    crate::nt::syscalls::nt_syscall_init();

    crate::kernel_log!("[Init] Phase 1 complete\n");
    STATUS_SUCCESS
    }
}

// ============================================================
// Kernel entry (called by VladBoot with BootParams)
// ============================================================

/// VladKernelEntry - the kernel's main entry point.
///
/// Never returns on success (hands off to SMSS); bugchecks on
/// fatal init failure.
#[no_mangle]
pub unsafe extern "C" fn vlad_kernel_entry(params: *const VladBootParams) -> ! {
    unsafe { crate::log::log_init(); }
    let mut st = init_phase0(params);
    if st != STATUS_SUCCESS {
        unsafe {
        crate::ke::bugcheck::ke_bug_check_ex(
            crate::ke::bugcheck::INACCESSIBLE_BOOT_DEVICE,
            st as u64,
            0,
            1,
            0,
        );
        }
    }
    st = init_phase1();
    if st != STATUS_SUCCESS {
        unsafe {
        crate::ke::bugcheck::ke_bug_check_ex(
            crate::ke::bugcheck::INACCESSIBLE_BOOT_DEVICE,
            st as u64,
            0,
            2,
            0,
        );
        }
    }
    crate::kernel_log!("[Init] Handing off to Session Manager\n");
    // The Session Manager (userspace vladss/smss) is started by the
    // process manager as the first user thread; the idle thread
    // takes over this CPU here.
    crate::hlt_loop()
}
