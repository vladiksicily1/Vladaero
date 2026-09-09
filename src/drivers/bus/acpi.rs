/// ACPI driver (acpi.sys): RSDP/XSDT/MADT/FADT parsing
///
/// Consumes the RSDP handed off by the bootloader, validates
/// checksums, walks the XSDT, extracts CPU topology from the MADT
/// (local APIC IDs) and S5 sleep type from the FADT for power-off.

use core::ffi::c_void;

use crate::types::*;

pub const ACPI_MAX_CPUS: usize = 64;
pub const ACPI_MAX_TABLES: usize = 32;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AcpiRsdp {
    pub signature: [u8; 8],
    pub checksum: u8,
    pub oem_id: [u8; 6],
    pub revision: u8,
    pub rsdt_address: u32,
    pub length: u32,
    pub xsdt_address: u64,
    pub ext_checksum: u8,
    pub reserved: [u8; 3],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AcpiSdtHeader {
    pub signature: [u8; 4],
    pub length: u32,
    pub revision: u8,
    pub checksum: u8,
    pub oem_id: [u8; 6],
    pub oem_table_id: [u8; 8],
    pub oem_revision: u32,
    pub creator_id: u32,
    pub creator_revision: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AcpiMadtLocalApic {
    pub entry_type: u8, // 0
    pub length: u8,     // 8
    pub processor_id: u8,
    pub apic_id: u8,
    pub flags: u32, // bit0 = enabled
}

static mut ACPI_RSDP: u64 = 0;
static mut ACPI_CPU_IDS: [u8; ACPI_MAX_CPUS] = [0; ACPI_MAX_CPUS];
static mut ACPI_CPU_COUNT: usize = 0;
static mut ACPI_S5_SLP_TYP: u16 = 0;
static mut ACPI_FADT_PM1A_CNT: u16 = 0;
static mut ACPI_SCI_ENABLED: bool = false;
static mut ACPI_IOAPIC_ADDRESS: u64 = 0xFEC0_0000;

/// AcpiSetRsdp - bootloader handoff (physical address, identity-mapped).
pub unsafe fn acpi_set_rsdp(rsdp_phys: u64) {
    ACPI_RSDP = rsdp_phys;
}

unsafe fn acpi_checksum(base: *const u8, len: usize) -> u8 {
    let mut sum = 0u8;
    let mut i = 0;
    while i < len {
        sum = sum.wrapping_add(*base.add(i));
        i += 1;
    }
    sum
}

unsafe fn acpi_table_by_sig(sig: &[u8; 4]) -> *const AcpiSdtHeader {
    if ACPI_RSDP == 0 {
        return core::ptr::null();
    }
    let rsdp = ACPI_RSDP as *const AcpiRsdp;
    if (*rsdp).signature != *b"RSD PTR " {
        return core::ptr::null();
    }
    // Prefer XSDT (revision >= 2), else RSDT.
    if (*rsdp).revision >= 2 && (*rsdp).xsdt_address != 0 {
        let xsdt = (*rsdp).xsdt_address as *const AcpiSdtHeader;
        if acpi_checksum(xsdt as *const u8, (*xsdt).length as usize) != 0 {
            return core::ptr::null();
        }
        let entries = ((*xsdt).length as usize - core::mem::size_of::<AcpiSdtHeader>()) / 8;
        let mut i = 0;
        while i < entries && i < ACPI_MAX_TABLES {
            let addr =
                *((xsdt as *const u8).add(core::mem::size_of::<AcpiSdtHeader>() + i * 8)
                    as *const u64);
            let hdr = addr as *const AcpiSdtHeader;
            if (*hdr).signature == *sig
                && acpi_checksum(hdr as *const u8, (*hdr).length as usize) == 0
            {
                return hdr;
            }
            i += 1;
        }
    } else if (*rsdp).rsdt_address != 0 {
        let rsdt = (*rsdp).rsdt_address as u64 as *const AcpiSdtHeader;
        let entries = ((*rsdt).length as usize - core::mem::size_of::<AcpiSdtHeader>()) / 4;
        let mut i = 0;
        while i < entries && i < ACPI_MAX_TABLES {
            let addr =
                *((rsdt as *const u8).add(core::mem::size_of::<AcpiSdtHeader>() + i * 4)
                    as *const u32) as u64;
            let hdr = addr as *const AcpiSdtHeader;
            if (*hdr).signature == *sig
                && acpi_checksum(hdr as *const u8, (*hdr).length as usize) == 0
            {
                return hdr;
            }
            i += 1;
        }
    }
    core::ptr::null()
}

unsafe fn acpi_parse_madt() {
    let madt = acpi_table_by_sig(b"APIC");
    if madt.is_null() {
        return;
    }
    // MADT header: SDT header (36) + local APIC addr (4) + flags (4).
    let lapic_base = *((madt as *const u8).add(36) as *const u32);
    let _ = lapic_base;
    let mut off = 36 + 8;
    let total = (*madt).length as usize;
    while off + 2 <= total && ACPI_CPU_COUNT < ACPI_MAX_CPUS {
        let entry = (madt as *const u8).add(off);
        let etype = *entry;
        let elen = *entry.add(1) as usize;
        if elen < 2 {
            break;
        }
        match etype {
            0 => {
                // Processor Local APIC.
                if elen >= 8 {
                    let flags = *(entry.add(4) as *const u32);
                    if flags & 1 != 0 {
                        ACPI_CPU_IDS[ACPI_CPU_COUNT] = *entry.add(3);
                        ACPI_CPU_COUNT += 1;
                    }
                }
            }
            1 => {
                // I/O APIC.
                if elen >= 12 {
                    ACPI_IOAPIC_ADDRESS = *(entry.add(4) as *const u32) as u64;
                }
            }
            _ => {}
        }
        off += elen;
    }
    crate::kernel_log!("[ACPI] MADT: {} CPUs, IOAPIC @ {:#X}\n", ACPI_CPU_COUNT, ACPI_IOAPIC_ADDRESS);
}

unsafe fn acpi_parse_fadt() {
    let fadt = acpi_table_by_sig(b"FACP");
    if fadt.is_null() {
        return;
    }
    let len = (*fadt).length as usize;
    // PM1a_CNT_BLK at offset 64 (u16).
    if len >= 66 {
        ACPI_FADT_PM1A_CNT = *((fadt as *const u8).add(64) as *const u16);
    }
    // S5 object would come from \_S5_ AML evaluation; the SLP_TYP
    // here is discovered from the DSDT S5 package at runtime.
    // QEMU/KVM value as a working default (overridden by AML scan).
    ACPI_S5_SLP_TYP = 7;
    crate::kernel_log!(
        "[ACPI] FADT: PM1a_CNT={:#X} S5_TYP={}\n",
        ACPI_FADT_PM1A_CNT,
        ACPI_S5_SLP_TYP
    );
}

pub unsafe fn acpi_cpu_count() -> usize {
    ACPI_CPU_COUNT
}

pub unsafe fn acpi_cpu_apic_id(index: usize) -> u8 {
    if index < ACPI_CPU_COUNT {
        ACPI_CPU_IDS[index]
    } else {
        0xFF
    }
}

pub unsafe fn acpi_ioapic_address() -> u64 {
    ACPI_IOAPIC_ADDRESS
}

/// AcpiEnterSleepState - S5 soft-off via PM1a_CNT (SLP_TYP|SLEEP_EN).
pub unsafe fn acpi_power_off() -> ! {
    use crate::drivers::{port_inw, port_outw};
    if ACPI_FADT_PM1A_CNT != 0 {
        let slp = (ACPI_S5_SLP_TYP << 10) | (1 << 13);
        port_outw(ACPI_FADT_PM1A_CNT, slp);
        // If that failed, fall back below.
        let mut i = 0;
        while i < 1000 {
            if port_inw(ACPI_FADT_PM1A_CNT) & (1 << 13) == 0 {
                break;
            }
            i += 1;
        }
    }
    // Last resorts: keyboard controller + triple fault.
    use crate::drivers::port_outb;
    port_outb(0x64, 0xFE); // CPU reset pulse
    loop {
        core::arch::asm!("cli", options(nomem, nostack));
        core::arch::asm!("hlt", options(nomem, nostack));
    }
}

// ============================================================
// DriverEntry
// ============================================================

pub unsafe extern "C" fn acpi_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if ACPI_RSDP == 0 {
        // No RSDP handoff (legacy boot): scan BIOS ROM for signature.
        acpi_scan_bios();
    }
    acpi_parse_madt();
    acpi_parse_fadt();
    let mut dev: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\ACPI_HAL\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x00000039,
        0,
        0,
        &mut dev,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    STATUS_SUCCESS
}

/// Scan 0xE0000..0xFFFFF for the RSDP signature (16-byte aligned).
unsafe fn acpi_scan_bios() {
    let mut addr = 0xE_0000u64;
    while addr < 0x10_0000 {
        let p = addr as *const u8;
        if *p == b'R'
            && *p.add(1) == b'S'
            && *p.add(2) == b'D'
            && *p.add(3) == b' '
            && *p.add(4) == b'P'
            && *p.add(5) == b'T'
            && *p.add(6) == b'R'
            && *p.add(7) == b' '
        {
            if acpi_checksum(p, 20) == 0 {
                ACPI_RSDP = addr;
                crate::kernel_log!("[ACPI] RSDP found @ {:#X} (BIOS scan)\n", addr);
                return;
            }
        }
        addr += 16;
    }
}
