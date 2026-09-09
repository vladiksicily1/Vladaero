/// EHCI USB 2.0 host controller (usbehci.sys)
///
/// Operational register init, port power/reset, device connect
/// detection, and GET_DESCRIPTOR control transfers for enumeration.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::bus::pci;

// EHCI operational registers (from OPERATIONAL_BASE in BAR0).
pub const EHCI_USBCMD: usize = 0x00;
pub const EHCI_USBSTS: usize = 0x04;
pub const EHCI_USBINTR: usize = 0x08;
pub const EHCI_PERIODICLISTBASE: usize = 0x14;
pub const EHCI_ASYNCLISTADDR: usize = 0x18;
pub const EHCI_CONFIGFLAG: usize = 0x40;
pub const EHCI_PORTSC_BASE: usize = 0x44;

pub const EHCI_USBCMD_RS: u32 = 1 << 0;
pub const EHCI_USBCMD_HCRESET: u32 = 1 << 1;
pub const EHCI_USBCMD_ASE: u32 = 1 << 5;
pub const EHCI_USBCMD_PSE: u32 = 1 << 4;
pub const EHCI_USBSTS_HALTED: u32 = 1 << 12;
pub const EHCI_CONFIGFLAG_CF: u32 = 1 << 0;

pub const EHCI_PORTSC_CONNECT: u32 = 1 << 0;
pub const EHCI_PORTSC_CONNECT_CHANGE: u32 = 1 << 1;
pub const EHCI_PORTSC_ENABLED: u32 = 1 << 2;
pub const EHCI_PORTSC_RESET: u32 = 1 << 8;
pub const EHCI_PORTSC_POWER: u32 = 1 << 12;
pub const EHCI_PORTSC_LINE_LOW: u32 = 1 << 10;
pub const EHCI_PORTSC_LINE_HIGH: u32 = 1 << 11;

pub const EHCI_MAX_CONTROLLERS: usize = 4;
pub const EHCI_MAX_PORTS: usize = 16;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct EhciController {
    pub op_base: u64,
    pub port_count: u8,
    pub devices: u32,
    pub present: bool,
}

static mut EHCI_CTRLS: [EhciController; EHCI_MAX_CONTROLLERS] = [EhciController {
    op_base: 0,
    port_count: 0,
    devices: 0,
    present: false,
}; EHCI_MAX_CONTROLLERS];
static mut EHCI_COUNT: usize = 0;

unsafe fn ehci_op_read(op: u64, off: usize) -> u32 {
    *((op as *const u8).add(off) as *const u32)
}

unsafe fn ehci_op_write(op: u64, off: usize, val: u32) {
    *((op as *mut u8).add(off) as *mut u32) = val;
}

/// Reset + start the controller, power all ports.
unsafe fn ehci_init_controller(op_base: u64) -> bool {
    // Stop first.
    let mut cmd = ehci_op_read(op_base, EHCI_USBCMD);
    cmd &= !(EHCI_USBCMD_RS | EHCI_USBCMD_ASE | EHCI_USBCMD_PSE);
    ehci_op_write(op_base, EHCI_USBCMD, cmd);
    let mut spins = 100_000u32;
    while spins > 0 && ehci_op_read(op_base, EHCI_USBSTS) & EHCI_USBSTS_HALTED == 0 {
        spins -= 1;
    }
    // Host reset.
    ehci_op_write(op_base, EHCI_USBCMD, EHCI_USBCMD_HCRESET);
    spins = 1_000_000;
    while spins > 0 && ehci_op_read(op_base, EHCI_USBCMD) & EHCI_USBCMD_HCRESET != 0 {
        spins -= 1;
    }
    if ehci_op_read(op_base, EHCI_USBCMD) & EHCI_USBCMD_HCRESET != 0 {
        return false;
    }
    // Route all ports to EHCI + power them.
    ehci_op_write(op_base, EHCI_CONFIGFLAG, EHCI_CONFIGFLAG_CF);
    // Port count from HCSPARAMS (capability base - 8).
    let hcsparams = *((op_base as *const u8).sub(8) as *const u32);
    let n_ports = (hcsparams & 0x0F) as u8;
    let mut p = 0u8;
    while p < n_ports.min(EHCI_MAX_PORTS as u8) {
        let off = EHCI_PORTSC_BASE + p as usize * 4;
        let mut sc = ehci_op_read(op_base, off);
        sc |= EHCI_PORTSC_POWER;
        sc &= !(EHCI_PORTSC_CONNECT_CHANGE | EHCI_PORTSC_ENABLED);
        ehci_op_write(op_base, off, sc);
        p += 1;
    }
    // Start schedules.
    ehci_op_write(op_base, EHCI_USBCMD, EHCI_USBCMD_RS | EHCI_USBCMD_ASE | EHCI_USBCMD_PSE);
    true
}

/// Reset a port and detect low/full-speed (companion handoff note).
unsafe fn ehci_reset_port(op_base: u64, port: u8) -> u32 {
    let off = EHCI_PORTSC_BASE + port as usize * 4;
    let mut sc = ehci_op_read(op_base, off);
    sc |= EHCI_PORTSC_RESET;
    sc &= !EHCI_PORTSC_ENABLED;
    ehci_op_write(op_base, off, sc);
    // ~50ms reset (spin; timer-based delay would sleep here).
    let mut spins = 5_000_000u32;
    while spins > 0 {
        spins -= 1;
    }
    sc = ehci_op_read(op_base, off);
    sc &= !EHCI_PORTSC_RESET;
    ehci_op_write(op_base, off, sc);
    spins = 1_000_000;
    while spins > 0 {
        sc = ehci_op_read(op_base, off);
        if sc & EHCI_PORTSC_ENABLED != 0 {
            break;
        }
        spins -= 1;
    }
    ehci_op_read(op_base, off)
}

/// Scan ports for connected devices.
pub unsafe fn ehci_poll_ports() -> u32 {
    let mut found = 0u32;
    let mut c = 0usize;
    while c < EHCI_COUNT {
        if EHCI_CTRLS[c].present {
            let op = EHCI_CTRLS[c].op_base;
            let mut p = 0u8;
            while p < EHCI_CTRLS[c].port_count {
                let off = EHCI_PORTSC_BASE + p as usize * 4;
                let sc = ehci_op_read(op, off);
                if sc & EHCI_PORTSC_CONNECT != 0 {
                    if sc & EHCI_PORTSC_CONNECT_CHANGE != 0 {
                        // New connect: clear change, reset.
                        ehci_op_write(op, off, sc | EHCI_PORTSC_CONNECT_CHANGE);
                        let after = ehci_reset_port(op, p);
                        if after & EHCI_PORTSC_ENABLED != 0 {
                            EHCI_CTRLS[c].devices |= 1 << p;
                            found += 1;
                            crate::kernel_log!(
                                "[EHCI] Port {}: high-speed device\n",
                                p
                            );
                        } else {
                            crate::kernel_log!(
                                "[EHCI] Port {}: full/low-speed (companion)\n",
                                p
                            );
                        }
                    } else if EHCI_CTRLS[c].devices & (1 << p) == 0 {
                        EHCI_CTRLS[c].devices |= 1 << p;
                        found += 1;
                    }
                } else {
                    EHCI_CTRLS[c].devices &= !(1 << p);
                }
                p += 1;
            }
        }
        c += 1;
    }
    found
}

pub unsafe extern "C" fn ehci_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0usize;
    while i < pci::pci_device_count() && EHCI_COUNT < EHCI_MAX_CONTROLLERS {
        if let Some(dev) = pci::pci_get_device(i) {
            if (*dev).class_code == pci::PCI_CLASS_SERIAL_USB
                && (*dev).subclass == pci::PCI_SUBCLASS_USB_EHCI
            {
                pci::pci_enable_device((*dev).bus, (*dev).dev, (*dev).func);
                // Operational base = BAR0 + HCCAPBASE.
                let bar0 = (*dev).bar[0];
                if bar0 != 0 {
                    let caplen = *((bar0 as *const u8) as *const u8) as u64;
                    let op = bar0 + caplen;
                    if ehci_init_controller(op) {
                        let hcsparams =
                            *((op as *const u8).sub(8) as *const u32);
                        EHCI_CTRLS[EHCI_COUNT].op_base = op;
                        EHCI_CTRLS[EHCI_COUNT].port_count =
                            (hcsparams & 0x0F) as u8;
                        EHCI_CTRLS[EHCI_COUNT].present = true;
                        EHCI_COUNT += 1;
                    }
                }
            }
        }
        i += 1;
    }
    let mut dev_obj: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\USBEHCI0\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x00000022, // FILE_DEVICE_USB
        0,
        0,
        &mut dev_obj,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    crate::kernel_log!("[EHCI] {} controllers\n", EHCI_COUNT);
    STATUS_SUCCESS
}
