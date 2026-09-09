/// Disk class driver (disk.sys) + partition manager (partmgr)
///
/// Routes block I/O to AHCI/NVMe, parses MBR + GPT partition tables,
/// exposes volumes (HarddiskVolumeN) with drive-letter mapping.

use core::ffi::c_void;

use crate::types::*;
use super::ahci;
use super::nvme;

// ============================================================
// Partition tables
// ============================================================

pub const MBR_SIGNATURE: u16 = 0xAA55;
pub const GPT_SIGNATURE: u64 = 0x5452415020494645; // "EFI PART"
pub const GPT_ENTRY_SIZE: usize = 128;
pub const GPT_HEADER_LBA: u64 = 1;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MbrPartitionEntry {
    pub boot: u8,
    pub chs_start: [u8; 3],
    pub part_type: u8,
    pub chs_end: [u8; 3],
    pub lba_start: u32,
    pub sector_count: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct GptHeader {
    pub signature: u64,
    pub revision: u32,
    pub header_size: u32,
    pub crc32: u32,
    pub reserved: u32,
    pub current_lba: u64,
    pub backup_lba: u64,
    pub first_usable: u64,
    pub last_usable: u64,
    pub disk_guid: [u8; 16],
    pub entries_lba: u64,
    pub entry_count: u32,
    pub entry_size: u32,
    pub entries_crc32: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct GptEntry {
    pub type_guid: [u8; 16],
    pub unique_guid: [u8; 16],
    pub first_lba: u64,
    pub last_lba: u64,
    pub attributes: u64,
    pub name: [u16; 36],
}

// Well-known partition type GUIDs.
pub const GPT_TYPE_EFI_SYSTEM: [u8; 16] = [
    0x28, 0x73, 0x2A, 0xC1, 0x1F, 0xF8, 0xD2, 0x11, 0xBA, 0x4B, 0x00, 0xA0, 0xC9, 0x3E,
    0xC9, 0x3B,
];
pub const GPT_TYPE_BASIC_DATA: [u8; 16] = [
    0xA2, 0xA0, 0xD0, 0xEB, 0xE5, 0xB9, 0x33, 0x44, 0x87, 0xC0, 0x68, 0xB6, 0xB7, 0x26,
    0x99, 0xC7,
];
pub const GPT_TYPE_VLADFS: [u8; 16] = [
    0x56, 0x4C, 0x41, 0x44, 0x46, 0x53, 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07,
    0x08, 0x09,
];

// ============================================================
// Disks + volumes
// ============================================================

pub const DISK_MAX: usize = 8;
pub const VOLUME_MAX: usize = 16;

#[repr(C)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DiskBackend {
    None,
    Ahci,
    Nvme,
}

#[repr(C)]
pub struct DiskDevice {
    pub backend: DiskBackend,
    pub backend_index: usize,
    pub sectors: u64,
    pub bytes_per_sector: u32,
    pub present: bool,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct VolumeDevice {
    pub disk_index: usize,
    pub start_lba: u64,
    pub sector_count: u64,
    pub drive_letter: u8, // b'C', b'D', ... 0 = none
    pub gpt_type: [u8; 16],
    pub mbr_type: u8,
    pub in_use: bool,
}

static mut CLASS_DISKS: [DiskDevice; DISK_MAX] = [DiskDevice {
    backend: DiskBackend::None,
    backend_index: 0,
    sectors: 0,
    bytes_per_sector: 512,
    present: false,
}; DISK_MAX];
static mut CLASS_DISK_COUNT: usize = 0;

static mut CLASS_VOLUMES: [VolumeDevice; VOLUME_MAX] = [VolumeDevice {
    disk_index: 0,
    start_lba: 0,
    sector_count: 0,
    drive_letter: 0,
    gpt_type: [0; 16],
    mbr_type: 0,
    in_use: false,
}; VOLUME_MAX];
static mut CLASS_VOLUME_COUNT: usize = 0;
static mut NEXT_DRIVE_LETTER: u8 = b'C';

/// Read raw sectors from a disk (backend routing).
pub unsafe fn class_read_sectors(
    disk_index: usize,
    lba: u64,
    buffer: *mut u8,
    sectors: u16,
) -> NtStatus {
    if disk_index >= CLASS_DISK_COUNT || buffer.is_null() || sectors == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let d = &CLASS_DISKS[disk_index];
    if !d.present {
        return STATUS_INVALID_PARAMETER;
    }
    if lba + sectors as u64 > d.sectors {
        return STATUS_INVALID_PARAMETER;
    }
    match d.backend {
        DiskBackend::Ahci => ahci::ahci_read(d.backend_index, lba, buffer, sectors),
        DiskBackend::Nvme => {
            // NVMe reads whole buffer in one command (PRP single range).
            nvme::nvme_read(lba, buffer, sectors)
        }
        DiskBackend::None => STATUS_INVALID_PARAMETER,
    }
}

/// Read sectors from a volume (adds the partition offset).
pub unsafe fn class_volume_read(
    volume_index: usize,
    lba: u64,
    buffer: *mut u8,
    sectors: u16,
) -> NtStatus {
    if volume_index >= CLASS_VOLUME_COUNT || buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let v = &CLASS_VOLUMES[volume_index];
    if !v.in_use {
        return STATUS_INVALID_PARAMETER;
    }
    if lba + sectors as u64 > v.sector_count {
        return STATUS_INVALID_PARAMETER;
    }
    class_read_sectors(v.disk_index, v.start_lba + lba, buffer, sectors)
}

unsafe fn class_add_volume(
    disk_index: usize,
    start_lba: u64,
    sector_count: u64,
    mbr_type: u8,
    gpt_type: *const u8,
) {
    if CLASS_VOLUME_COUNT >= VOLUME_MAX || sector_count == 0 {
        return;
    }
    let idx = CLASS_VOLUME_COUNT;
    CLASS_VOLUMES[idx].disk_index = disk_index;
    CLASS_VOLUMES[idx].start_lba = start_lba;
    CLASS_VOLUMES[idx].sector_count = sector_count;
    CLASS_VOLUMES[idx].mbr_type = mbr_type;
    if !gpt_type.is_null() {
        core::ptr::copy_nonoverlapping(gpt_type, CLASS_VOLUMES[idx].gpt_type.as_mut_ptr(), 16);
    }
    CLASS_VOLUMES[idx].drive_letter = NEXT_DRIVE_LETTER;
    if NEXT_DRIVE_LETTER < b'Z' {
        NEXT_DRIVE_LETTER += 1;
    }
    CLASS_VOLUMES[idx].in_use = true;
    CLASS_VOLUME_COUNT += 1;
    crate::kernel_log!(
        "[disk] Volume {}: disk={} start={} len={} letter={}\n",
        idx,
        disk_index,
        start_lba,
        sector_count,
        CLASS_VOLUMES[idx].drive_letter
    );
}

/// Parse MBR (and protective-MBR -> GPT) on a disk.
unsafe fn class_parse_partitions(disk_index: usize) {
    let sector = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(512) as *mut u8;
    if sector.is_null() {
        return;
    }
    if class_read_sectors(disk_index, 0, sector, 1) != STATUS_SUCCESS {
        crate::mm::pool::ex_free_pool(sector as *mut c_void);
        return;
    }
    let sig = *(sector.add(510) as *const u16);
    if sig != MBR_SIGNATURE {
        // Unpartitioned superfloppy: whole disk is one volume.
        let sectors = CLASS_DISKS[disk_index].sectors;
        class_add_volume(disk_index, 0, sectors, 0, core::ptr::null());
        crate::mm::pool::ex_free_pool(sector as *mut c_void);
        return;
    }
    // Check for protective MBR (type 0xEE) -> GPT.
    let mut is_gpt = false;
    let mut i = 0;
    while i < 4 {
        let e = sector.add(446 + i * 16) as *const MbrPartitionEntry;
        if (*e).part_type == 0xEE {
            is_gpt = true;
        }
        i += 1;
    }
    if is_gpt {
        class_parse_gpt(disk_index, sector);
        crate::mm::pool::ex_free_pool(sector as *mut c_void);
        return;
    }
    // Classic MBR entries.
    i = 0;
    while i < 4 {
        let e = sector.add(446 + i * 16) as *const MbrPartitionEntry;
        let ptype = (*e).part_type;
        let start = (*e).lba_start as u64;
        let count = (*e).sector_count as u64;
        if ptype != 0 && count > 0 {
            class_add_volume(disk_index, start, count, ptype, core::ptr::null());
        }
        i += 1;
    }
    crate::mm::pool::ex_free_pool(sector as *mut c_void);
}

unsafe fn class_parse_gpt(disk_index: usize, _sector0: *mut u8) {
    let hdr_buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(512) as *mut u8;
    if hdr_buf.is_null() {
        return;
    }
    if class_read_sectors(disk_index, GPT_HEADER_LBA, hdr_buf, 1) != STATUS_SUCCESS {
        crate::mm::pool::ex_free_pool(hdr_buf as *mut c_void);
        return;
    }
    let hdr = hdr_buf as *const GptHeader;
    if (*hdr).signature != GPT_SIGNATURE {
        crate::mm::pool::ex_free_pool(hdr_buf as *mut c_void);
        return;
    }
    let entry_count = (*hdr).entry_count.min(128);
    let entries_lba = (*hdr).entries_lba;
    // Read all entries (128 * 128B = 32 sectors max, read in chunks).
    let total_bytes = entry_count as usize * GPT_ENTRY_SIZE;
    let total_sectors = (total_bytes + 511) / 512;
    let entries = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(total_sectors * 512)
        as *mut u8;
    if entries.is_null() {
        crate::mm::pool::ex_free_pool(hdr_buf as *mut c_void);
        return;
    }
    let mut s = 0usize;
    while s < total_sectors {
        let chunk = (total_sectors - s).min(32) as u16;
        if class_read_sectors(
            disk_index,
            entries_lba + s as u64,
            entries.add(s * 512),
            chunk,
        ) != STATUS_SUCCESS
        {
            break;
        }
        s += chunk as usize;
    }
    let mut i = 0u32;
    while i < entry_count {
        let e = entries.add(i as usize * GPT_ENTRY_SIZE) as *const GptEntry;
        // Empty GUID = unused.
        let mut empty = true;
        let mut k = 0;
        while k < 16 {
            if (*e).type_guid[k] != 0 {
                empty = false;
                break;
            }
            k += 1;
        }
        if !empty && (*e).last_lba >= (*e).first_lba {
            class_add_volume(
                disk_index,
                (*e).first_lba,
                (*e).last_lba - (*e).first_lba + 1,
                0,
                (*e).type_guid.as_ptr(),
            );
        }
        i += 1;
    }
    crate::mm::pool::ex_free_pool(entries as *mut c_void);
    crate::mm::pool::ex_free_pool(hdr_buf as *mut c_void);
}

pub unsafe fn class_disk_count() -> usize {
    CLASS_DISK_COUNT
}

pub unsafe fn class_volume_count() -> usize {
    CLASS_VOLUME_COUNT
}

pub unsafe fn class_volume_letter(volume_index: usize) -> u8 {
    if volume_index < CLASS_VOLUME_COUNT {
        CLASS_VOLUMES[volume_index].drive_letter
    } else {
        0
    }
}

// ============================================================
// DriverEntry
// ============================================================

pub unsafe extern "C" fn disk_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Claim AHCI ports as disks.
    let mut i = 0usize;
    while i < ahci::ahci_port_count() && CLASS_DISK_COUNT < DISK_MAX {
        let idx = CLASS_DISK_COUNT;
        CLASS_DISKS[idx].backend = DiskBackend::Ahci;
        CLASS_DISKS[idx].backend_index = i;
        CLASS_DISKS[idx].sectors = ahci::ahci_port_sectors(i);
        CLASS_DISKS[idx].present = true;
        CLASS_DISK_COUNT += 1;
        i += 1;
    }
    // Claim NVMe as one disk (namespace 0).
    if nvme::nvme_is_ready() && CLASS_DISK_COUNT < DISK_MAX {
        let idx = CLASS_DISK_COUNT;
        CLASS_DISKS[idx].backend = DiskBackend::Nvme;
        CLASS_DISKS[idx].backend_index = 0;
        CLASS_DISKS[idx].sectors = nvme::nvme_namespace_sectors();
        CLASS_DISKS[idx].present = true;
        CLASS_DISK_COUNT += 1;
    }
    // Parse partitions on every disk.
    let mut d = 0usize;
    while d < CLASS_DISK_COUNT {
        class_parse_partitions(d);
        d += 1;
    }
    let mut dev_obj: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\Harddisk0\\DR0\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x00000007, // FILE_DEVICE_DISK
        0,
        0,
        &mut dev_obj,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    crate::kernel_log!(
        "[disk] {} disks, {} volumes\n",
        CLASS_DISK_COUNT,
        CLASS_VOLUME_COUNT
    );
    STATUS_SUCCESS
}
