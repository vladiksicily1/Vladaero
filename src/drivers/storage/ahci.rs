/// AHCI driver (storahci.sys): HBA init, port probe, IDENTIFY, READ
///
/// Real register-level AHCI: GHC enable, BIOS/OS handoff, port
/// signature detection, command list/FIS/PRDT setup, and polled
/// READ DMA EXT. LBA48 addressing, up to 2TB+ disks.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::bus::pci;

// ============================================================
// AHCI registers (ABAR)
// ============================================================

pub const AHCI_CAP_OFFSET: usize = 0x00;
pub const AHCI_GHC_OFFSET: usize = 0x04;
pub const AHCI_IS_OFFSET: usize = 0x08;
pub const AHCI_PI_OFFSET: usize = 0x0C;
pub const AHCI_BOHC_OFFSET: usize = 0x28;
pub const AHCI_PORT_BASE: usize = 0x100;
pub const AHCI_PORT_STRIDE: usize = 0x80;

pub const AHCI_GHC_AE: u32 = 1 << 31;
pub const AHCI_GHC_IE: u32 = 1 << 1;
pub const AHCI_BOHC_BOS: u32 = 1 << 0;
pub const AHCI_BOHC_OOS: u32 = 1 << 1;

pub const AHCI_PORT_CLB_OFFSET: usize = 0x00;
pub const AHCI_PORT_FB_OFFSET: usize = 0x08;
pub const AHCI_PORT_IS_OFFSET: usize = 0x10;
pub const AHCI_PORT_IE_OFFSET: usize = 0x14;
pub const AHCI_PORT_CMD_OFFSET: usize = 0x18;
pub const AHCI_PORT_TFD_OFFSET: usize = 0x20;
pub const AHCI_PORT_SIG_OFFSET: usize = 0x24;
pub const AHCI_PORT_SSTS_OFFSET: usize = 0x28;
pub const AHCI_PORT_SCTL_OFFSET: usize = 0x2C;
pub const AHCI_PORT_SERR_OFFSET: usize = 0x30;
pub const AHCI_PORT_CI_OFFSET: usize = 0x38;

pub const AHCI_CMD_ST: u32 = 1 << 0;
pub const AHCI_CMD_FRE: u32 = 1 << 4;
pub const AHCI_CMD_FR: u32 = 1 << 14;
pub const AHCI_CMD_CR: u32 = 1 << 15;

pub const AHCI_SIG_SATA: u32 = 0x00000101;
pub const AHCI_SIG_ATAPI: u32 = 0xEB140101;

pub const AHCI_MAX_PORTS: usize = 32;
pub const AHCI_CMD_SLOTS: usize = 32;
pub const AHCI_SECTOR_SIZE: usize = 512;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AhciCmdHeader {
    pub flags: u16,
    pub prdtl: u16,
    pub prdbc: u32,
    pub ctba_lo: u32,
    pub ctba_hi: u32,
    pub reserved: [u32; 4],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AhciPrdtEntry {
    pub dba_lo: u32,
    pub dba_hi: u32,
    pub reserved: u32,
    pub dbc_irq: u32, // byte count (0-based) | IRQ bit 31
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AhciPortState {
    pub abar: u64,
    pub port: u8,
    pub present: bool,
    pub atapi: bool,
    pub sectors: u64,
    pub model: [u8; 40],
    pub clb: *mut u8,
    pub fb: *mut u8,
    pub ctba: *mut u8,
}

static mut AHCI_PORTS: [AhciPortState; AHCI_MAX_PORTS] = [AhciPortState {
    abar: 0,
    port: 0,
    present: false,
    atapi: false,
    sectors: 0,
    model: [0; 40],
    clb: core::ptr::null_mut(),
    fb: core::ptr::null_mut(),
    ctba: core::ptr::null_mut(),
}; AHCI_MAX_PORTS];
static mut AHCI_PORT_COUNT: usize = 0;
static mut AHCI_ABAR: u64 = 0;

unsafe fn ahci_read32(abar: u64, off: usize) -> u32 {
    *((abar as *const u8).add(off) as *const u32)
}

unsafe fn ahci_write32(abar: u64, off: usize, val: u32) {
    *((abar as *mut u8).add(off) as *mut u32) = val;
}

unsafe fn ahci_port_read(abar: u64, port: u8, off: usize) -> u32 {
    ahci_read32(abar, AHCI_PORT_BASE + port as usize * AHCI_PORT_STRIDE + off)
}

unsafe fn ahci_port_write(abar: u64, port: u8, off: usize, val: u32) {
    ahci_write32(abar, AHCI_PORT_BASE + port as usize * AHCI_PORT_STRIDE + off, val)
}

unsafe fn ahci_alloc_dma(bytes: usize, align: usize) -> *mut u8 {
    let size = (bytes + align - 1) / align * align;
    let layout = core::alloc::Layout::from_size_align(size, align);
    match layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l),
        Err(_) => core::ptr::null_mut(),
    }
}

/// Stop a port (clear ST, wait for CR to drop).
unsafe fn ahci_port_stop(abar: u64, port: u8) -> bool {
    let cmd = ahci_port_read(abar, port, AHCI_PORT_CMD_OFFSET);
    if cmd & (AHCI_CMD_ST | AHCI_CMD_CR | AHCI_CMD_FRE | AHCI_CMD_FR) == 0 {
        return true;
    }
    ahci_port_write(abar, port, AHCI_PORT_CMD_OFFSET, cmd & !AHCI_CMD_ST);
    let mut spins = 100_000u32;
    while spins > 0 {
        let c = ahci_port_read(abar, port, AHCI_PORT_CMD_OFFSET);
        if c & AHCI_CMD_CR == 0 {
            break;
        }
        spins -= 1;
    }
    // Clear FRE/FR.
    let cmd2 = ahci_port_read(abar, port, AHCI_PORT_CMD_OFFSET);
    ahci_port_write(abar, port, AHCI_PORT_CMD_OFFSET, cmd2 & !AHCI_CMD_FRE);
    spins = 100_000;
    while spins > 0 {
        if ahci_port_read(abar, port, AHCI_PORT_CMD_OFFSET) & AHCI_CMD_FR == 0 {
            break;
        }
        spins -= 1;
    }
    true
}

/// Start a port (set FRE then ST).
unsafe fn ahci_port_start(abar: u64, port: u8) {
    let mut spins = 100_000u32;
    while spins > 0 {
        if ahci_port_read(abar, port, AHCI_PORT_CMD_OFFSET) & AHCI_CMD_CR == 0 {
            break;
        }
        spins -= 1;
    }
    let cmd = ahci_port_read(abar, port, AHCI_PORT_CMD_OFFSET);
    ahci_port_write(abar, port, AHCI_PORT_CMD_OFFSET, cmd | AHCI_CMD_FRE | AHCI_CMD_ST);
}

/// Build a Register-Host-to-Device FIS for READ DMA EXT (LBA48).
unsafe fn ahci_build_read_fis(fis: *mut u8, lba: u64, count: u16) {
    core::ptr::write_bytes(fis, 0, 20);
    *fis.add(0) = 0x27; // Register H2D
    *fis.add(1) = 0x80; // C = command
    *fis.add(2) = 0x25; // READ DMA EXT
    *fis.add(4) = (lba & 0xFF) as u8;
    *fis.add(5) = ((lba >> 8) & 0xFF) as u8;
    *fis.add(6) = ((lba >> 16) & 0xFF) as u8;
    *fis.add(7) = 0xE0; // LBA mode
    *fis.add(8) = ((lba >> 24) & 0xFF) as u8;
    *fis.add(9) = ((lba >> 32) & 0xFF) as u8;
    *fis.add(10) = ((lba >> 40) & 0xFF) as u8;
    *fis.add(12) = (count & 0xFF) as u8;
    *fis.add(13) = ((count >> 8) & 0xFF) as u8;
}

/// IDENTIFY DEVICE (0xEC) via one command slot; fills model/sectors.
unsafe fn ahci_identify(abar: u64, port: u8, ps: *mut AhciPortState) -> bool {
    let identify_buf = ahci_alloc_dma(512, 2);
    if identify_buf.is_null() {
        return false;
    }
    let ok = ahci_do_pio_command(abar, port, ps, 0xEC, identify_buf, 512, true);
    if ok {
        // Model: words 27..46 (byteswapped ASCII).
        let mut i = 0;
        while i < 40 {
            let w = *(identify_buf.add(54 + i) as *const u16);
            (*ps).model[i] = (w >> 8) as u8;
            (*ps).model[i + 1] = (w & 0xFF) as u8;
            i += 2;
        }
        // LBA48 sectors: words 100..103.
        let s0 = *(identify_buf.add(200) as *const u16) as u64;
        let s1 = *(identify_buf.add(202) as *const u16) as u64;
        let s2 = *(identify_buf.add(204) as *const u16) as u64;
        let s3 = *(identify_buf.add(206) as *const u16) as u64;
        let total = s0 | (s1 << 16) | (s2 << 32) | (s3 << 48);
        (*ps).sectors = if total == 0 {
            // Fall back to LBA28 words 60..61.
            let l0 = *(identify_buf.add(120) as *const u16) as u64;
            let l1 = *(identify_buf.add(122) as *const u16) as u64;
            l0 | (l1 << 16)
        } else {
            total
        };
    }
    let layout = core::alloc::Layout::from_size_align(512, 2).unwrap();
    alloc::alloc::dealloc(identify_buf, layout);
    ok
}

/// PIO data-in command (IDENTIFY) using slot 0, polled.
unsafe fn ahci_do_pio_command(
    abar: u64,
    port: u8,
    ps: *mut AhciPortState,
    command: u8,
    buffer: *mut u8,
    byte_count: usize,
    _read: bool,
) -> bool {
    // Command header 0: 1 PRDT entry.
    let hdr = (*ps).clb as *mut AhciCmdHeader;
    (*hdr).flags = 5; // 5 DWords CFIS
    (*hdr).prdtl = 1;
    (*hdr).prdbc = 0;
    let ctba = (*ps).ctba;
    let ctba_phys = ctba as u64; // identity-mapped DMA window
    (*hdr).ctba_lo = (ctba_phys & 0xFFFF_FFFF) as u32;
    (*hdr).ctba_hi = (ctba_phys >> 32) as u32;
    // CFIS.
    let cfis = ctba;
    core::ptr::write_bytes(cfis, 0, 64);
    *cfis.add(0) = 0x27;
    *cfis.add(1) = 0x80;
    *cfis.add(2) = command;
    // PRDT.
    let prdt = ctba.add(128) as *mut AhciPrdtEntry;
    let phys = buffer as u64;
    (*prdt).dba_lo = (phys & 0xFFFF_FFFF) as u32;
    (*prdt).dba_hi = (phys >> 32) as u32;
    (*prdt).reserved = 0;
    (*prdt).dbc_irq = ((byte_count - 1) & 0x3FFFFF) as u32;
    // Clear error, run slot 0.
    ahci_port_write(abar, port, AHCI_PORT_SERR_OFFSET, 0xFFFF_FFFF);
    ahci_port_write(abar, port, AHCI_PORT_IS_OFFSET, 0xFFFF_FFFF);
    ahci_port_write(abar, port, AHCI_PORT_CI_OFFSET, 1);
    // Poll for completion.
    let mut spins = 2_000_000u32;
    while spins > 0 {
        let ci = ahci_port_read(abar, port, AHCI_PORT_CI_OFFSET);
        let is = ahci_port_read(abar, port, AHCI_PORT_IS_OFFSET);
        if ci & 1 == 0 {
            return is & (1 << 30) == 0; // TFES = error
        }
        if is & (1 << 30) != 0 {
            return false;
        }
        spins -= 1;
    }
    false
}

/// AhciRead - polled READ DMA EXT, up to 256 sectors per command.
pub unsafe fn ahci_read(port_idx: usize, lba: u64, buffer: *mut u8, sectors: u16) -> NtStatus {
    if port_idx >= AHCI_PORT_COUNT || buffer.is_null() || sectors == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let ps = &mut AHCI_PORTS[port_idx] as *mut AhciPortState;
    if !(*ps).present || (*ps).atapi {
        return STATUS_INVALID_PARAMETER;
    }
    if lba + sectors as u64 > (*ps).sectors {
        return STATUS_INVALID_PARAMETER;
    }
    let abar = (*ps).abar;
    let port = (*ps).port;
    // Command header 0.
    let hdr = (*ps).clb as *mut AhciCmdHeader;
    (*hdr).flags = 5;
    let byte_count = sectors as usize * AHCI_SECTOR_SIZE;
    let prd_entries = (byte_count + 0x3FFFFF) / 0x400000; // 4MB per PRDT
    if prd_entries > 8 {
        return STATUS_INVALID_PARAMETER;
    }
    (*hdr).prdtl = prd_entries as u16;
    (*hdr).prdbc = 0;
    let ctba = (*ps).ctba;
    let ctba_phys = ctba as u64;
    (*hdr).ctba_lo = (ctba_phys & 0xFFFF_FFFF) as u32;
    (*hdr).ctba_hi = (ctba_phys >> 32) as u32;
    ahci_build_read_fis(ctba, lba, sectors);
    // PRDT entries (contiguous buffer assumed from pool).
    let mut off = 0usize;
    let mut e = 0usize;
    while e < prd_entries {
        let chunk = (byte_count - off).min(0x400000);
        let prdt = (ctba.add(128) as *mut AhciPrdtEntry).add(e);
        let phys = buffer.add(off) as u64;
        (*prdt).dba_lo = (phys & 0xFFFF_FFFF) as u32;
        (*prdt).dba_hi = (phys >> 32) as u32;
        (*prdt).reserved = 0;
        (*prdt).dbc_irq = ((chunk - 1) & 0x3FFFFF) as u32 | if e + 1 == prd_entries { 1 << 31 } else { 0 };
        off += chunk;
        e += 1;
    }
    ahci_port_write(abar, port, AHCI_PORT_SERR_OFFSET, 0xFFFF_FFFF);
    ahci_port_write(abar, port, AHCI_PORT_IS_OFFSET, 0xFFFF_FFFF);
    ahci_port_write(abar, port, AHCI_PORT_CI_OFFSET, 1);
    let mut spins = 10_000_000u32;
    while spins > 0 {
        let ci = ahci_port_read(abar, port, AHCI_PORT_CI_OFFSET);
        let is = ahci_port_read(abar, port, AHCI_PORT_IS_OFFSET);
        if is & (1 << 30) != 0 {
            return STATUS_IO_DEVICE_ERROR;
        }
        if ci & 1 == 0 {
            return STATUS_SUCCESS;
        }
        spins -= 1;
        if spins % 1000 == 0 {
            core::hint::spin_loop();
        }
    }
    STATUS_IO_TIMEOUT
}

/// Probe one AHCI controller (ABAR phys, identity-mapped DMA window).
unsafe fn ahci_init_controller(abar: u64) -> u32 {
    AHCI_ABAR = abar;
    // BIOS/OS handoff.
    let bohc = ahci_read32(abar, AHCI_BOHC_OFFSET);
    if bohc & AHCI_BOHC_BOS != 0 {
        ahci_write32(abar, AHCI_BOHC_OFFSET, bohc | AHCI_BOHC_OOS);
        let mut spins = 1_000_000u32;
        while spins > 0 && ahci_read32(abar, AHCI_BOHC_OFFSET) & AHCI_BOHC_BOS != 0 {
            spins -= 1;
        }
    }
    // Enable AHCI.
    ahci_write32(abar, AHCI_GHC_OFFSET, ahci_read32(abar, AHCI_GHC_OFFSET) | AHCI_GHC_AE);
    let pi = ahci_read32(abar, AHCI_PI_OFFSET);
    let mut found = 0u32;
    let mut port = 0u8;
    while (port as u32) < 32 {
        if pi & (1 << port) != 0 {
            let ssts = ahci_port_read(abar, port, AHCI_PORT_SSTS_OFFSET);
            let det = ssts & 0x0F;
            if det == 0x03 {
                let sig = ahci_port_read(abar, port, AHCI_PORT_SIG_OFFSET);
                if sig == AHCI_SIG_SATA && (AHCI_PORT_COUNT as u32) < AHCI_MAX_PORTS as u32 {
                    ahci_port_stop(abar, port);
                    // Allocate CLB (1KB), FB (256B), CTBA area (8 headers * 256B).
                    let clb = ahci_alloc_dma(1024, 1024);
                    let fb = ahci_alloc_dma(256, 256);
                    let ctba = ahci_alloc_dma(8192, 128);
                    if clb.is_null() || fb.is_null() || ctba.is_null() {
                        continue;
                    }
                    let idx = AHCI_PORT_COUNT;
                    AHCI_PORTS[idx].abar = abar;
                    AHCI_PORTS[idx].port = port;
                    AHCI_PORTS[idx].present = true;
                    AHCI_PORTS[idx].atapi = false;
                    AHCI_PORTS[idx].clb = clb;
                    AHCI_PORTS[idx].fb = fb;
                    AHCI_PORTS[idx].ctba = ctba;
                    ahci_write32(abar, AHCI_PORT_BASE + port as usize * AHCI_PORT_STRIDE + AHCI_PORT_CLB_OFFSET, (clb as u64 & 0xFFFF_FFFF) as u32);
                    // CLB upper (assume <4GB).
                    ahci_write32(abar, AHCI_PORT_BASE + port as usize * AHCI_PORT_STRIDE + AHCI_PORT_CLB_OFFSET + 4, 0);
                    ahci_write32(abar, AHCI_PORT_BASE + port as usize * AHCI_PORT_STRIDE + AHCI_PORT_FB_OFFSET, (fb as u64 & 0xFFFF_FFFF) as u32);
                    ahci_write32(abar, AHCI_PORT_BASE + port as usize * AHCI_PORT_STRIDE + AHCI_PORT_FB_OFFSET + 4, 0);
                    // Clear SERR, enable FIS receive.
                    ahci_port_write(abar, port, AHCI_PORT_SERR_OFFSET, 0xFFFF_FFFF);
                    ahci_port_start(abar, port);
                    if ahci_identify(abar, port, &mut AHCI_PORTS[idx] as *mut AhciPortState) {
                        found += 1;
                        AHCI_PORT_COUNT += 1;
                        crate::kernel_log!(
                            "[AHCI] Port {}: {} sectors\n",
                            port,
                            AHCI_PORTS[idx].sectors
                        );
                    }
                } else if sig == AHCI_SIG_ATAPI {
                    crate::kernel_log!("[AHCI] Port {}: ATAPI (skipped)\n", port);
                }
            }
        }
        port += 1;
    }
    found
}

pub unsafe fn ahci_port_count() -> usize {
    AHCI_PORT_COUNT
}

pub unsafe fn ahci_port_sectors(idx: usize) -> u64 {
    if idx < AHCI_PORT_COUNT {
        AHCI_PORTS[idx].sectors
    } else {
        0
    }
}

// ============================================================
// DriverEntry
// ============================================================

pub unsafe extern "C" fn ahci_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Find AHCI controllers (class 01/subclass 06).
    let mut total = 0u32;
    let mut controllers = 0u32;
    let mut i = 0usize;
    while i < pci::pci_device_count() {
        if let Some(dev) = pci::pci_get_device(i) {
            if (*dev).class_code == pci::PCI_CLASS_STORAGE
                && (*dev).subclass == pci::PCI_SUBCLASS_AHCI
            {
                pci::pci_enable_device((*dev).bus, (*dev).dev, (*dev).func);
                let abar = (*dev).bar[5];
                if abar != 0 {
                    total += ahci_init_controller(abar);
                    controllers += 1;
                }
            }
        }
        i += 1;
    }
    let mut dev_obj: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\StorAHCI\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x0000002D, // FILE_DEVICE_CONTROLLER
        0,
        0,
        &mut dev_obj,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    crate::kernel_log!("[AHCI] {} disks on {} controllers\n", total, controllers);
    STATUS_SUCCESS
}

// Local status codes.
pub const STATUS_IO_DEVICE_ERROR: NtStatus = 0xC0000185u32 as i32;
pub const STATUS_IO_TIMEOUT: NtStatus = 0xC00000B5u32 as i32;
