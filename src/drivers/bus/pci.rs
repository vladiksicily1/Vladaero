/// PCI bus driver (pci.sys): config space, enumeration, BARs
///
/// Type-1 config mechanism (CF8/CFC), recursive bus scan,
/// BAR sizing, IRQ routing stub, and the device list that disk/
/// net/gpu drivers consume.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::{port_ind, port_inw, port_inb, port_outd};

// ============================================================
// Config mechanism
// ============================================================

pub const PCI_CONFIG_ADDRESS: u16 = 0xCF8;
pub const PCI_CONFIG_DATA: u16 = 0xCFC;

pub const PCI_MAX_BUS: u8 = 255;
pub const PCI_MAX_DEVICES: usize = 256;

pub const PCI_CLASS_STORAGE: u8 = 0x01;
pub const PCI_SUBCLASS_AHCI: u8 = 0x06;
pub const PCI_SUBCLASS_NVME: u8 = 0x08;
pub const PCI_CLASS_NETWORK: u8 = 0x02;
pub const PCI_CLASS_DISPLAY: u8 = 0x03;
pub const PCI_CLASS_BRIDGE: u8 = 0x06;
pub const PCI_CLASS_SERIAL_USB: u8 = 0x0C;
pub const PCI_SUBCLASS_USB_EHCI: u8 = 0x20;
pub const PCI_SUBCLASS_USB_XHCI: u8 = 0x30;

// Known device IDs we drive.
pub const PCI_VID_INTEL: u16 = 0x8086;
pub const PCI_VID_REALTEK: u16 = 0x10EC;
pub const PCI_VID_QEMU_VGA: u16 = 0x1234;
pub const PCI_VID_VMWARE: u16 = 0x15AD;
pub const PCI_VID_VIRTIO: u16 = 0x1AF4;

pub const PCI_DID_E1000: u16 = 0x100E;
pub const PCI_DID_E1000E: u16 = 0x10D3;
pub const PCI_DID_RTL8169: u16 = 0x8169;
pub const PCI_DID_INTEL_VGA_GEN9: u16 = 0x5912;

#[inline]
fn pci_cfg_addr(bus: u8, dev: u8, func: u8, offset: u8) -> u32 {
    0x8000_0000
        | ((bus as u32) << 16)
        | ((dev as u32) << 11)
        | ((func as u32) << 8)
        | ((offset as u32) & 0xFC)
}

/// HalGetBusDataByOffset equivalent: read 8/16/32-bit config.
pub unsafe fn pci_read_config(bus: u8, dev: u8, func: u8, offset: u8, width: u8) -> u32 {
    port_outd(PCI_CONFIG_ADDRESS, pci_cfg_addr(bus, dev, func, offset));
    let v = port_ind(PCI_CONFIG_DATA);
    let shift = ((offset & 3) as u32) * 8;
    match width {
        1 => (v >> shift) & 0xFF,
        2 => (v >> shift) & 0xFFFF,
        _ => v,
    }
}

pub unsafe fn pci_write_config(bus: u8, dev: u8, func: u8, offset: u8, width: u8, value: u32) {
    let addr = pci_cfg_addr(bus, dev, func, offset);
    let shift = ((offset & 3) as u32) * 8;
    if width == 4 {
        port_outd(PCI_CONFIG_ADDRESS, addr);
        port_outd(PCI_CONFIG_DATA, value);
    } else {
        port_outd(PCI_CONFIG_ADDRESS, addr);
        let cur = port_ind(PCI_CONFIG_DATA);
        let mask = if width == 1 { 0xFF } else { 0xFFFF };
        let nv = (cur & !(mask << shift)) | ((value & mask) << shift);
        port_outd(PCI_CONFIG_ADDRESS, addr);
        port_outd(PCI_CONFIG_DATA, nv);
    }
}

// ============================================================
// Device database
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct PciDevice {
    pub bus: u8,
    pub dev: u8,
    pub func: u8,
    pub vendor_id: u16,
    pub device_id: u16,
    pub class_code: u8,
    pub subclass: u8,
    pub prog_if: u8,
    pub irq_line: u8,
    pub bar: [u64; 6],
    pub bar_is_io: [bool; 6],
    pub bar_size: [u64; 6],
    pub bus_master: bool,
}

static mut PCI_DEVICES: [PciDevice; PCI_MAX_DEVICES] = [PciDevice {
    bus: 0,
    dev: 0,
    func: 0,
    vendor_id: 0,
    device_id: 0,
    class_code: 0,
    subclass: 0,
    prog_if: 0,
    irq_line: 0,
    bar: [0; 6],
    bar_is_io: [false; 6],
    bar_size: [0; 6],
    bus_master: false,
}; PCI_MAX_DEVICES];
static mut PCI_DEVICE_COUNT: usize = 0;

/// Enable bus mastering + MEM/IO decode on a device.
pub unsafe fn pci_enable_device(bus: u8, dev: u8, func: u8) {
    let cmd = pci_read_config(bus, dev, func, 0x04, 2);
    pci_write_config(bus, dev, func, 0x04, 2, cmd | 0x07);
}

/// Size a BAR (write all 1s, read back mask) without disturbing it.
unsafe fn pci_size_bar(bus: u8, dev: u8, func: u8, bar_idx: u8) -> (u64, bool, u64) {
    let off = 0x10 + bar_idx * 4;
    let orig_lo = pci_read_config(bus, dev, func, off, 4);
    let is_io = orig_lo & 0x1 != 0;
    if is_io {
        pci_write_config(bus, dev, func, off, 4, 0xFFFF_FFFF);
        let mask = pci_read_config(bus, dev, func, off, 4);
        pci_write_config(bus, dev, func, off, 4, orig_lo);
        let size = (!(mask & 0xFFFF_FFFC)).wrapping_add(1) as u64;
        ((orig_lo & 0xFFFF_FFFC) as u64, true, size)
    } else {
        let ty = (orig_lo >> 1) & 0x3;
        if ty == 0x2 {
            // 64-bit BAR.
            let orig_hi = pci_read_config(bus, dev, func, off + 4, 4);
            pci_write_config(bus, dev, func, off, 4, 0xFFFF_FFFF);
            pci_write_config(bus, dev, func, off + 4, 4, 0xFFFF_FFFF);
            let mask_lo = pci_read_config(bus, dev, func, off, 4);
            let mask_hi = pci_read_config(bus, dev, func, off + 4, 4);
            pci_write_config(bus, dev, func, off, 4, orig_lo);
            pci_write_config(bus, dev, func, off + 4, 4, orig_hi);
            let base = ((orig_hi as u64) << 32) | ((orig_lo & 0xFFFF_FFF0) as u64);
            let mask = ((mask_hi as u64) << 32) | ((mask_lo & 0xFFFF_FFF0) as u64);
            (base, false, (!mask).wrapping_add(1))
        } else {
            pci_write_config(bus, dev, func, off, 4, 0xFFFF_FFFF);
            let mask = pci_read_config(bus, dev, func, off, 4);
            pci_write_config(bus, dev, func, off, 4, orig_lo);
            let base = (orig_lo & 0xFFFF_FFF0) as u64;
            let size = (!(mask & 0xFFFF_FFF0)).wrapping_add(1) as u64;
            (base, false, size)
        }
    }
}

unsafe fn pci_scan_function(bus: u8, dev: u8, func: u8) {
    let vendor = pci_read_config(bus, dev, func, 0x00, 2) as u16;
    if vendor == 0xFFFF {
        return;
    }
    let device = pci_read_config(bus, dev, func, 0x02, 2) as u16;
    let class = pci_read_config(bus, dev, func, 0x0B, 1) as u8;
    let subclass = pci_read_config(bus, dev, func, 0x0A, 1) as u8;
    let prog_if = pci_read_config(bus, dev, func, 0x09, 1) as u8;
    let irq_line = pci_read_config(bus, dev, func, 0x3C, 1) as u8;
    if PCI_DEVICE_COUNT >= PCI_MAX_DEVICES {
        return;
    }
    let idx = PCI_DEVICE_COUNT;
    PCI_DEVICES[idx].bus = bus;
    PCI_DEVICES[idx].dev = dev;
    PCI_DEVICES[idx].func = func;
    PCI_DEVICES[idx].vendor_id = vendor;
    PCI_DEVICES[idx].device_id = device;
    PCI_DEVICES[idx].class_code = class;
    PCI_DEVICES[idx].subclass = subclass;
    PCI_DEVICES[idx].prog_if = prog_if;
    PCI_DEVICES[idx].irq_line = irq_line;
    let header_type = pci_read_config(bus, dev, func, 0x0E, 1) as u8;
    if header_type & 0x7F == 0 {
        let mut b = 0u8;
        while b < 6 {
            let (base, is_io, size) = pci_size_bar(bus, dev, func, b);
            PCI_DEVICES[idx].bar[b as usize] = base;
            PCI_DEVICES[idx].bar_is_io[b as usize] = is_io;
            PCI_DEVICES[idx].bar_size[b as usize] = size;
            b += 1;
        }
    }
    PCI_DEVICE_COUNT += 1;
    crate::kernel_log!(
        "[PCI] {:02X}:{:02X}.{} {:04X}:{:04X} class={:02X}/{:02X} irq={}\n",
        bus,
        dev,
        func,
        vendor,
        device,
        class,
        subclass,
        irq_line
    );
    // PCI-PCI bridge: recurse into the secondary bus.
    if class == PCI_CLASS_BRIDGE && subclass == 0x04 {
        let secondary = pci_read_config(bus, dev, func, 0x19, 1) as u8;
        pci_scan_bus(secondary);
    }
}

unsafe fn pci_scan_bus(bus: u8) {
    let mut dev = 0u8;
    while dev < 32 {
        let header = pci_read_config(bus, dev, 0, 0x0E, 1) as u8;
        if pci_read_config(bus, dev, 0, 0x00, 2) as u16 != 0xFFFF {
            if header & 0x80 != 0 {
                let mut func = 0u8;
                while func < 8 {
                    pci_scan_function(bus, dev, func);
                    func += 1;
                }
            } else {
                pci_scan_function(bus, dev, 0);
            }
        }
        dev += 1;
    }
}

/// PciEnumerate - full recursive scan from bus 0.
pub unsafe fn pci_enumerate() -> usize {
    PCI_DEVICE_COUNT = 0;
    pci_scan_bus(0);
    PCI_DEVICE_COUNT
}

pub unsafe fn pci_find_by_class(class: u8, subclass: u8, start: usize) -> Option<*mut PciDevice> {
    let mut i = start;
    while i < PCI_DEVICE_COUNT {
        if PCI_DEVICES[i].class_code == class && PCI_DEVICES[i].subclass == subclass {
            return Some(&mut PCI_DEVICES[i] as *mut PciDevice);
        }
        i += 1;
    }
    None
}

pub unsafe fn pci_find_by_id(vendor: u16, device: u16) -> Option<*mut PciDevice> {
    let mut i = 0;
    while i < PCI_DEVICE_COUNT {
        if PCI_DEVICES[i].vendor_id == vendor && PCI_DEVICES[i].device_id == device {
            return Some(&mut PCI_DEVICES[i] as *mut PciDevice);
        }
        i += 1;
    }
    None
}

pub unsafe fn pci_device_count() -> usize {
    PCI_DEVICE_COUNT
}

/// Indexed access to the enumerated device list.
pub unsafe fn pci_get_device(index: usize) -> Option<*mut PciDevice> {
    if index < PCI_DEVICE_COUNT {
        Some(&mut PCI_DEVICES[index] as *mut PciDevice)
    } else {
        None
    }
}

// ============================================================
// DriverEntry
// ============================================================

pub unsafe extern "C" fn pci_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let count = pci_enumerate();
    // Create \Device\PCI device object.
    let mut dev: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\PCI\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x00000039, // FILE_DEVICE_BUS_EXTENDER
        0,
        0,
        &mut dev,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    crate::kernel_log!("[PCI] Enumerated {} devices\n", count);
    STATUS_SUCCESS
}

// Silence unused import warnings for port_inw/port_inb (used by children).
#[allow(dead_code)]
pub unsafe fn pci_port_probe(port: u16) -> u8 {
    port_inb(port)
}

#[allow(dead_code)]
pub unsafe fn pci_port_probe_w(port: u16) -> u16 {
    port_inw(port)
}
