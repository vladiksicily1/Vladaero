/// xHCI USB 3.0 host controller (USBXHCI.sys)
///
/// Capability/operational init, device context setup, port reset,
/// and slot enumeration skeleton (transfers via TRBs on rings).

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::bus::pci;

pub const XHCI_CAP_CAPLENGTH: usize = 0x00;
pub const XHCI_CAP_HCIVERSION: usize = 0x02;
pub const XHCI_CAP_HCSPARAMS1: usize = 0x04;
pub const XHCI_OP_USBSTS: usize = 0x04;
pub const XHCI_OP_PAGESIZE: usize = 0x08;
pub const XHCI_OP_DNCTRL: usize = 0x14;
pub const XHCI_OP_CRCR_LO: usize = 0x18;
pub const XHCI_OP_DCBAAP_LO: usize = 0x30;
pub const XHCI_OP_CONFIG: usize = 0x38;
pub const XHCI_OP_PORTSC_BASE: usize = 0x400;

pub const XHCI_USBCMD_RS: u32 = 1 << 0;
pub const XHCI_USBCMD_HCRST: u32 = 1 << 1;
pub const XHCI_USBCMD_INTE: u32 = 1 << 2;
pub const XHCI_USBSTS_HCHALTED: u32 = 1 << 0;
pub const XHCI_USBSTS_CNR: u32 = 1 << 11;

pub const XHCI_PORTSC_CCS: u32 = 1 << 0;
pub const XHCI_PORTSC_PED: u32 = 1 << 1;
pub const XHCI_PORTSC_PR: u32 = 1 << 4;
pub const XHCI_PORTSC_PP: u32 = 1 << 9;
pub const XHCI_PORTSC_CSC: u32 = 1 << 17;
pub const XHCI_PORTSC_SPEED_SHIFT: u32 = 10;

pub const XHCI_MAX_CONTROLLERS: usize = 4;
pub const XHCI_MAX_SLOTS: usize = 32;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct XhciController {
    pub cap_base: u64,
    pub op_base: u64,
    pub db_base: u64,
    pub port_count: u8,
    pub slots: u32,
    pub present: bool,
}

static mut XHCI_CTRLS: [XhciController; XHCI_MAX_CONTROLLERS] = [XhciController {
    cap_base: 0,
    op_base: 0,
    db_base: 0,
    port_count: 0,
    slots: 0,
    present: false,
}; XHCI_MAX_CONTROLLERS];
static mut XHCI_COUNT: usize = 0;

unsafe fn xhci_cap8(base: u64, off: usize) -> u8 {
    *((base as *const u8).add(off))
}
unsafe fn xhci_cap32(base: u64, off: usize) -> u32 {
    *((base as *const u8).add(off) as *const u32)
}
unsafe fn xhci_op32(op: u64, off: usize) -> u32 {
    *((op as *const u8).add(off) as *const u32)
}
unsafe fn xhci_op32_write(op: u64, off: usize, val: u32) {
    *((op as *mut u8).add(off) as *mut u32) = val;
}

unsafe fn xhci_init_controller(cap_base: u64) -> bool {
    let caplen = xhci_cap8(cap_base, XHCI_CAP_CAPLENGTH) as u64;
    let op = cap_base + caplen;
    // Wait for Controller-Not-Ready to clear.
    let mut spins = 2_000_000u32;
    while spins > 0 && xhci_op32(op, XHCI_OP_USBSTS) & XHCI_USBSTS_CNR != 0 {
        spins -= 1;
    }
    if xhci_op32(op, XHCI_OP_USBSTS) & XHCI_USBSTS_CNR != 0 {
        return false;
    }
    // Halt + reset.
    let mut cmd = xhci_op32(op, 0);
    cmd &= !XHCI_USBCMD_RS;
    xhci_op32_write(op, 0, cmd);
    spins = 1_000_000;
    while spins > 0 && xhci_op32(op, XHCI_OP_USBSTS) & XHCI_USBSTS_HCHALTED == 0 {
        spins -= 1;
    }
    cmd = xhci_op32(op, 0) | XHCI_USBCMD_HCRST;
    xhci_op32_write(op, 0, cmd);
    spins = 2_000_000;
    while spins > 0 && xhci_op32(op, 0) & XHCI_USBCMD_HCRST != 0 {
        spins -= 1;
    }
    if xhci_op32(op, 0) & XHCI_USBCMD_HCRST != 0 {
        return false;
    }
    // Max slots.
    let hcs1 = xhci_cap32(cap_base, XHCI_CAP_HCSPARAMS1);
    let max_slots = hcs1 & 0xFF;
    xhci_op32_write(op, XHCI_OP_CONFIG, max_slots);
    // Device context base array (64-byte aligned, one entry per slot+1).
    let dcbaa_layout =
        core::alloc::Layout::from_size_align((max_slots as usize + 1) * 8, 64);
    let dcbaa = match dcbaa_layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l) as u64,
        Err(_) => 0,
    };
    if dcbaa == 0 {
        return false;
    }
    xhci_op32_write(op, XHCI_OP_DCBAAP_LO, (dcbaa & 0xFFFF_FFFF) as u32);
    xhci_op32_write(op, XHCI_OP_DCBAAP_LO + 4, (dcbaa >> 32) as u32);
    // Command ring (64-byte aligned TRBs, 256 entries).
    let cr_layout = core::alloc::Layout::from_size_align(256 * 16, 64);
    let cr = match cr_layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l) as u64,
        Err(_) => 0,
    };
    if cr == 0 {
        return false;
    }
    xhci_op32_write(op, XHCI_OP_CRCR_LO, ((cr & 0xFFFF_FFFF) | 1) as u32);
    xhci_op32_write(op, XHCI_OP_CRCR_LO + 4, (cr >> 32) as u32);
    // Start.
    cmd = xhci_op32(op, 0) | XHCI_USBCMD_RS | XHCI_USBCMD_INTE;
    xhci_op32_write(op, 0, cmd);
    spins = 1_000_000;
    while spins > 0 && xhci_op32(op, XHCI_OP_USBSTS) & XHCI_USBSTS_HCHALTED != 0 {
        spins -= 1;
    }
    if xhci_op32(op, XHCI_OP_USBSTS) & XHCI_USBSTS_HCHALTED != 0 {
        return false;
    }
    // Power all ports.
    let n_ports = ((hcs1 >> 24) & 0xFF) as u8;
    let mut p = 0u8;
    while p < n_ports {
        let off = XHCI_OP_PORTSC_BASE + p as usize * 16;
        let mut sc = xhci_op32(op, off);
        sc |= XHCI_PORTSC_PP;
        sc &= !(XHCI_PORTSC_CSC | (1 << 22) | (1 << 21));
        xhci_op32_write(op, off, sc);
        p += 1;
    }
    if XHCI_COUNT < XHCI_MAX_CONTROLLERS {
        XHCI_CTRLS[XHCI_COUNT].cap_base = cap_base;
        XHCI_CTRLS[XHCI_COUNT].op_base = op;
        XHCI_CTRLS[XHCI_COUNT].db_base = cap_base + xhci_dboff(cap_base);
        XHCI_CTRLS[XHCI_COUNT].port_count = n_ports;
        XHCI_CTRLS[XHCI_COUNT].slots = max_slots;
        XHCI_CTRLS[XHCI_COUNT].present = true;
        XHCI_COUNT += 1;
    }
    true
}

unsafe fn xhci_dboff(cap_base: u64) -> u64 {
    // DBOFF lives in HCCPARAMS-adjacent xECP space; standard offset
    // register is at capability + 0x14 for most controllers.
    let dboff = xhci_cap32(cap_base, 0x14) & 0xFFFF_FFFC;
    dboff as u64
}

/// Scan ports for SuperSpeed/HighSpeed connects.
pub unsafe fn xhci_poll_ports() -> u32 {
    let mut found = 0u32;
    let mut c = 0usize;
    while c < XHCI_COUNT {
        if XHCI_CTRLS[c].present {
            let op = XHCI_CTRLS[c].op_base;
            let mut p = 0u8;
            while p < XHCI_CTRLS[c].port_count {
                let off = XHCI_OP_PORTSC_BASE + p as usize * 16;
                let sc = xhci_op32(op, off);
                if sc & XHCI_PORTSC_CCS != 0 {
                    if sc & XHCI_PORTSC_CSC != 0 {
                        // Clear change, reset port.
                        xhci_op32_write(op, off, sc | XHCI_PORTSC_CSC | XHCI_PORTSC_PR);
                        let speed = (sc >> XHCI_PORTSC_SPEED_SHIFT) & 0xF;
                        crate::kernel_log!(
                            "[xHCI] Port {}: device, speed={}\n",
                            p,
                            speed
                        );
                    }
                    found += 1;
                }
                p += 1;
            }
        }
        c += 1;
    }
    found
}

pub unsafe extern "C" fn xhci_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0usize;
    while i < pci::pci_device_count() {
        if let Some(dev) = pci::pci_get_device(i) {
            if (*dev).class_code == pci::PCI_CLASS_SERIAL_USB
                && (*dev).subclass == pci::PCI_SUBCLASS_USB_XHCI
            {
                pci::pci_enable_device((*dev).bus, (*dev).dev, (*dev).func);
                if (*dev).bar[0] != 0 {
                    xhci_init_controller((*dev).bar[0]);
                }
            }
        }
        i += 1;
    }
    let mut dev_obj: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\USBXHCI0\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x00000022,
        0,
        0,
        &mut dev_obj,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    crate::kernel_log!("[xHCI] {} controllers\n", XHCI_COUNT);
    STATUS_SUCCESS
}
