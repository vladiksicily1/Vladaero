/// Drivers - kernel-mode WDM drivers (.sys)
///
/// Boot-start driver loading in ServiceGroupOrder Group/Tag order
/// (Early-Launch first), then: PCI/ACPI bus, AHCI/NVMe storage +
/// disk class (MBR/GPT), PS/2 + class input, VGA/bootvid video,
/// EHCI/xHCI USB, e1000/RTL8169 NICs, Intel iGPU.
///
/// References:
///   - MS Learn: Specifying Driver Load Order, NDIS Miniports
///   - Windows Internals 7th Ed. Part 1, Chapter 3/6

use core::ffi::c_void;

use crate::types::*;

pub mod bus;
pub mod storage;
pub mod input;
pub mod video;
pub mod usb;
pub mod net;
pub mod display;

// ============================================================
// Port I/O (x86 IN/OUT)
// ============================================================

#[inline]
pub unsafe fn port_inb(port: u16) -> u8 {
    let v: u8;
    core::arch::asm!("in al, dx", out("al") v, in("dx") port, options(nomem, nostack, preserves_flags));
    v
}

#[inline]
pub unsafe fn port_outb(port: u16, value: u8) {
    core::arch::asm!("out dx, al", in("dx") port, in("al") value, options(nomem, nostack, preserves_flags));
}

#[inline]
pub unsafe fn port_inw(port: u16) -> u16 {
    let v: u16;
    core::arch::asm!("in ax, dx", out("ax") v, in("dx") port, options(nomem, nostack, preserves_flags));
    v
}

#[inline]
pub unsafe fn port_outw(port: u16, value: u16) {
    core::arch::asm!("out dx, ax", in("dx") port, in("ax") value, options(nomem, nostack, preserves_flags));
}

#[inline]
pub unsafe fn port_ind(port: u16) -> u32 {
    let v: u32;
    core::arch::asm!("in eax, dx", out("eax") v, in("dx") port, options(nomem, nostack, preserves_flags));
    v
}

#[inline]
pub unsafe fn port_outd(port: u16, value: u32) {
    core::arch::asm!("out dx, eax", in("dx") port, in("eax") value, options(nomem, nostack, preserves_flags));
}

// ============================================================
// Driver registry + load order
// ============================================================

pub const SERVICE_BOOT_START: u32 = 0;
pub const SERVICE_SYSTEM_START: u32 = 1;
pub const SERVICE_AUTO_START: u32 = 2;
pub const SERVICE_DEMAND_START: u32 = 3;
pub const SERVICE_DISABLED: u32 = 4;

pub const DRIVER_FLAG_STARTED: u32 = 0x00000001;
pub const DRIVER_FLAG_FAILED: u32 = 0x00000002;
pub const DRIVER_FLAG_BOOT_START: u32 = 0x00000004;

pub const DRIVER_MAX: usize = 64;

pub type DriverEntryFn =
    unsafe extern "C" fn(driver_object: *mut crate::io::DriverObject, registry_path: Pvoid) -> NtStatus;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KernelDriver {
    pub service_name: [u16; 64],
    pub group: [u16; 32],
    pub tag: u32,
    pub start_type: u32,
    pub entry: Option<DriverEntryFn>,
    pub driver_object: *mut crate::io::DriverObject,
    pub flags: u32,
    pub load_order: u32,
}

static mut DRIVER_TABLE: [KernelDriver; DRIVER_MAX] = [KernelDriver {
    service_name: [0; 64],
    group: [0; 32],
    tag: 0,
    start_type: SERVICE_DISABLED,
    entry: None,
    driver_object: core::ptr::null_mut(),
    flags: 0,
    load_order: 0,
}; DRIVER_MAX];
static mut DRIVER_COUNT: usize = 0;

// ServiceGroupOrder List (boot order, Early-Launch hardcoded first).
// Real Win10 order: Early-Launch, Core..., System Reserved, Boot Bus
// Extender, System Bus Extender, SCSI miniport, Port, Primary Disk,
// SCSI Class, SCSI CDROM Class, FSFilter..., Boot File System, Base,
// Keyboard Port/Class, Pointer Port/Class, Video, etc.
static DRIVER_GROUP_ORDER: [&str; 24] = [
    "Early-Launch",
    "Core",
    "System Reserved",
    "Boot Bus Extender",
    "System Bus Extender",
    "SCSI miniport",
    "Port",
    "Primary Disk",
    "SCSI Class",
    "SCSI CDROM Class",
    "FSFilter Infrastructure",
    "FSFilter System",
    "FSFilter Bottom",
    "Boot File System",
    "Base",
    "Keyboard Port",
    "Keyboard Class",
    "Pointer Port",
    "Pointer Class",
    "Video Init",
    "Video",
    "Network",
    "NDIS",
    "TDI",
];

unsafe fn drivers_copy_name(dst: *mut u16, len: usize, src: &str) {
    let bytes = src.as_bytes();
    let mut i = 0;
    while i + 1 < len && i < bytes.len() {
        *dst.add(i) = bytes[i] as u16;
        i += 1;
    }
    *dst.add(i) = 0;
}

/// DriversRegister - declare a built-in driver (service, group, tag).
pub unsafe fn drivers_register(
    service: &str,
    group: &str,
    tag: u32,
    start_type: u32,
    entry: DriverEntryFn,
) -> NtStatus {
    if DRIVER_COUNT >= DRIVER_MAX {
        return STATUS_INSUFFICIENT_RESOURCES;
    }
    let idx = DRIVER_COUNT;
    drivers_copy_name(DRIVER_TABLE[idx].service_name.as_mut_ptr(), 64, service);
    drivers_copy_name(DRIVER_TABLE[idx].group.as_mut_ptr(), 32, group);
    DRIVER_TABLE[idx].tag = tag;
    DRIVER_TABLE[idx].start_type = start_type;
    DRIVER_TABLE[idx].entry = Some(entry);
    DRIVER_TABLE[idx].flags = if start_type == SERVICE_BOOT_START {
        DRIVER_FLAG_BOOT_START
    } else {
        0
    };
    DRIVER_COUNT += 1;
    // Also register in the driver database for PnP ranking.
    let mut svc16 = [0u16; 64];
    drivers_copy_name(svc16.as_mut_ptr(), 64, service);
    crate::drvdb::drvdb_add_package(
        svc16.as_ptr(),
        svc16.as_ptr(),
        svc16.as_ptr(),
        svc16.as_ptr(),
        0x000A_0000,
        true,
    );
    STATUS_SUCCESS
}

fn drivers_group_rank(group: &[u16; 32]) -> usize {
    // Decode UTF-16 group name to compare with the order list.
    let mut buf = [0u8; 32];
    let mut n = 0usize;
    while n < 32 && group[n] != 0 {
        buf[n] = group[n] as u8;
        n += 1;
    }
    let name = core::str::from_utf8(&buf[..n]).unwrap_or("");
    let mut i = 0;
    while i < DRIVER_GROUP_ORDER.len() {
        if DRIVER_GROUP_ORDER[i] == name {
            return i;
        }
        i += 1;
    }
    DRIVER_GROUP_ORDER.len()
}

/// DriversLoadBootStart - sort by (group rank, tag) and call DriverEntry.
///
/// Matches winload/IopInitializeBootDrivers: Early-Launch first,
/// then ServiceGroupOrder List, 1-based tags within a group.
pub unsafe fn drivers_load_boot_start() -> NtStatus {
    // Insertion sort by (group_rank, tag).
    let mut i = 1;
    while i < DRIVER_COUNT {
        let mut j = i;
        while j > 0 {
            let a_rank = drivers_group_rank(&DRIVER_TABLE[j - 1].group);
            let b_rank = drivers_group_rank(&DRIVER_TABLE[j].group);
            let swap = (DRIVER_TABLE[j].start_type == SERVICE_BOOT_START)
                && (DRIVER_TABLE[j - 1].start_type != SERVICE_BOOT_START
                    || b_rank < a_rank
                    || (b_rank == a_rank && DRIVER_TABLE[j].tag < DRIVER_TABLE[j - 1].tag));
            if !swap {
                break;
            }
            // Swap entries.
            let tmp = core::ptr::read(&DRIVER_TABLE[j] as *const KernelDriver);
            core::ptr::copy(
                &DRIVER_TABLE[j - 1] as *const KernelDriver,
                &mut DRIVER_TABLE[j] as *mut KernelDriver,
                1,
            );
            core::ptr::write(&mut DRIVER_TABLE[j - 1] as *mut KernelDriver, tmp);
            j -= 1;
        }
        i += 1;
    }
    // Call DriverEntry for boot-start drivers in order.
    let mut order = 0u32;
    let mut k = 0;
    while k < DRIVER_COUNT {
        if DRIVER_TABLE[k].start_type == SERVICE_BOOT_START {
            if let Some(entry) = DRIVER_TABLE[k].entry {
                // Allocate a DriverObject.
                let drv = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
                    core::mem::size_of::<crate::io::DriverObject>(),
                ) as *mut crate::io::DriverObject;
                if !drv.is_null() {
                    core::ptr::write_bytes(
                        drv as *mut u8,
                        0,
                        core::mem::size_of::<crate::io::DriverObject>(),
                    );
                    *drv = crate::io::DriverObject::new();
                    DRIVER_TABLE[k].driver_object = drv;
                    let st = entry(drv, core::ptr::null_mut());
                    if st == STATUS_SUCCESS {
                        DRIVER_TABLE[k].flags |= DRIVER_FLAG_STARTED;
                    } else {
                        DRIVER_TABLE[k].flags |= DRIVER_FLAG_FAILED;
                    }
                    DRIVER_TABLE[k].load_order = order;
                    order += 1;
                }
            }
        }
        k += 1;
    }
    crate::kernel_log!("[Drivers] Boot-start drivers loaded: {}\n", order);
    STATUS_SUCCESS
}

/// Register all built-in drivers (called once at Phase 1).
pub unsafe fn drivers_register_all() -> NtStatus {
    // Bus.
    let mut st = drivers_register("pci", "Boot Bus Extender", 1, SERVICE_BOOT_START, bus::pci::pci_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    st = drivers_register(
        "ACPI",
        "Boot Bus Extender",
        2,
        SERVICE_BOOT_START,
        bus::acpi::acpi_driver_entry,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    // Storage.
    st = drivers_register("storahci", "SCSI miniport", 1, SERVICE_BOOT_START, storage::ahci::ahci_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    st = drivers_register("stornvme", "SCSI miniport", 2, SERVICE_BOOT_START, storage::nvme::nvme_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    st = drivers_register("disk", "SCSI Class", 1, SERVICE_BOOT_START, storage::classpnp::disk_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    // Input.
    st = drivers_register("i8042prt", "Keyboard Port", 1, SERVICE_SYSTEM_START, input::i8042::i8042_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    st = drivers_register("kbdclass", "Keyboard Class", 1, SERVICE_SYSTEM_START, input::kbdclass::kbdclass_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    st = drivers_register("mouclass", "Pointer Class", 1, SERVICE_SYSTEM_START, input::mouclass::mouclass_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    // Video.
    st = drivers_register("bootvid", "Video Init", 1, SERVICE_BOOT_START, video::bootvid::bootvid_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    st = drivers_register("VGA", "Video", 1, SERVICE_SYSTEM_START, video::vga::vga_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    // USB.
    st = drivers_register("usbehci", "Port", 1, SERVICE_BOOT_START, usb::ehci::ehci_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    st = drivers_register("USBXHCI", "Port", 2, SERVICE_BOOT_START, usb::xhci::xhci_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    // Network.
    st = drivers_register("e1iexpress", "NDIS", 1, SERVICE_SYSTEM_START, net::e1000::e1000_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    st = drivers_register("rtl8169", "NDIS", 2, SERVICE_SYSTEM_START, net::rtl8169::rtl8169_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    // GPU.
    st = drivers_register("igdkmd64", "Video", 2, SERVICE_SYSTEM_START, display::intel_gpu::intel_gpu_driver_entry);
    if st != STATUS_SUCCESS {
        return st;
    }
    STATUS_SUCCESS
}

/// Find a loaded driver object by service name (for nic_transmit etc).
pub unsafe fn drivers_find(service: &str) -> *mut crate::io::DriverObject {
    let bytes = service.as_bytes();
    let mut k = 0;
    while k < DRIVER_COUNT {
        let mut same = true;
        let mut i = 0;
        while i < bytes.len() && i < 63 {
            if DRIVER_TABLE[k].service_name[i] != bytes[i] as u16 {
                same = false;
                break;
            }
            i += 1;
        }
        if same && DRIVER_TABLE[k].service_name[i] == 0 {
            return DRIVER_TABLE[k].driver_object;
        }
        k += 1;
    }
    core::ptr::null_mut()
}
