/// exFAT driver (exfat.sys): mount, bitmap, directory set, read
///
/// Boot sector validation, cluster bitmap allocation tracking,
/// File/Stream/Name entry sets (0x85/0xC0/0xC1), contiguous and
/// fragmented file reads.

use core::ffi::c_void;

use crate::types::*;
use super::fat32::{STATUS_IO_DEVICE_ERROR, STATUS_END_OF_FILE};

pub const EXFAT_MAX_MOUNTS: usize = 8;
pub const EXFAT_SECTOR: usize = 512;

pub const EXFAT_ENTRY_FILE: u8 = 0x85;
pub const EXFAT_ENTRY_STREAM: u8 = 0xC0;
pub const EXFAT_ENTRY_NAME: u8 = 0xC1;
pub const EXFAT_ENTRY_END: u8 = 0x00;

pub const EXFAT_ATTR_DIRECTORY: u16 = 0x10;
pub const EXFAT_STREAM_CONTIGUOUS: u16 = 0x0002;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ExfatMount {
    pub volume_index: usize,
    pub bytes_per_sector_shift: u8,
    pub sectors_per_cluster_shift: u8,
    pub fat_lba: u64,
    pub data_lba: u64,
    pub cluster_count: u32,
    pub root_cluster: u32,
    pub bitmap_cluster: u32,
    pub bitmap_size: u64,
    pub upcase_cluster: u32,
    pub mounted: bool,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ExfatFileInfo {
    pub first_cluster: u32,
    pub data_length: u64,
    pub contiguous: bool,
    pub is_directory: bool,
}

static mut EXFAT_MOUNTS: [ExfatMount; EXFAT_MAX_MOUNTS] = [ExfatMount {
    volume_index: 0,
    bytes_per_sector_shift: 9,
    sectors_per_cluster_shift: 0,
    fat_lba: 0,
    data_lba: 0,
    cluster_count: 0,
    root_cluster: 0,
    bitmap_cluster: 0,
    bitmap_size: 0,
    upcase_cluster: 0,
    mounted: false,
}; EXFAT_MAX_MOUNTS];

unsafe fn exfat_volume_read(
    volume_index: usize,
    lba: u64,
    buffer: *mut u8,
    sectors: u16,
) -> NtStatus {
    crate::drivers::storage::classpnp::class_volume_read(volume_index, lba, buffer, sectors)
}

/// ExfatMount - validate boot sector ("EXFAT   ") and locate heap.
pub unsafe fn exfat_mount(volume_index: usize) -> *mut ExfatMount {
    let mut i = 0;
    while i < EXFAT_MAX_MOUNTS {
        if !EXFAT_MOUNTS[i].mounted {
            let sector = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(512)
                as *mut u8;
            if sector.is_null() {
                return core::ptr::null_mut();
            }
            if exfat_volume_read(volume_index, 0, sector, 1) != STATUS_SUCCESS {
                crate::mm::pool::ex_free_pool(sector as *mut c_void);
                return core::ptr::null_mut();
            }
            let valid = *sector.add(510) == 0x55
                && *sector.add(511) == 0xAA
                && *sector.add(3) == b'E'
                && *sector.add(4) == b'X'
                && *sector.add(5) == b'F'
                && *sector.add(6) == b'A'
                && *sector.add(7) == b'T';
            if !valid {
                crate::mm::pool::ex_free_pool(sector as *mut c_void);
                return core::ptr::null_mut();
            }
            let bps_shift = *sector.add(108);
            let spc_shift = *sector.add(109);
            let fat_offset = *(sector.add(80) as *const u32) as u64;
            let data_offset = *(sector.add(88) as *const u32) as u64;
            let clusters = *(sector.add(92) as *const u32);
            let root = *(sector.add(96) as *const u32);
            crate::mm::pool::ex_free_pool(sector as *mut c_void);
            if bps_shift < 9 || bps_shift > 12 || spc_shift > 25 - bps_shift {
                return core::ptr::null_mut();
            }
            EXFAT_MOUNTS[i].volume_index = volume_index;
            EXFAT_MOUNTS[i].bytes_per_sector_shift = bps_shift;
            EXFAT_MOUNTS[i].sectors_per_cluster_shift = spc_shift;
            EXFAT_MOUNTS[i].fat_lba = fat_offset;
            EXFAT_MOUNTS[i].data_lba = data_offset;
            EXFAT_MOUNTS[i].cluster_count = clusters;
            EXFAT_MOUNTS[i].root_cluster = root;
            EXFAT_MOUNTS[i].mounted = true;
            // Locate bitmap + upcase in the root directory.
            exfat_find_system_files(&mut EXFAT_MOUNTS[i] as *mut ExfatMount);
            return &mut EXFAT_MOUNTS[i] as *mut ExfatMount;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

unsafe fn exfat_cluster_bytes(m: *mut ExfatMount) -> usize {
    (1usize << ((*m).bytes_per_sector_shift + (*m).sectors_per_cluster_shift)) as usize
}

unsafe fn exfat_cluster_lba(m: *mut ExfatMount, cluster: u32) -> u64 {
    let spc = 1u64 << (*m).sectors_per_cluster_shift;
    (*m).data_lba + (cluster as u64 - 2) * spc
}

unsafe fn exfat_read_cluster(m: *mut ExfatMount, cluster: u32, buffer: *mut u8) -> NtStatus {
    let spc = (1u64 << (*m).sectors_per_cluster_shift) as u16;
    exfat_volume_read((*m).volume_index, exfat_cluster_lba(m, cluster), buffer, spc)
}

unsafe fn exfat_next_cluster(m: *mut ExfatMount, cluster: u32) -> u32 {
    // FAT: 4-byte LE entries, 0xFFFFFFFF = EOC.
    let off = cluster as u64 * 4;
    let bps = 1u64 << (*m).bytes_per_sector_shift;
    let sector = (*m).fat_lba + off / bps;
    let ent = (off % bps) as usize;
    let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(1024) as *mut u8;
    if buf.is_null() {
        return 0xFFFF_FFFF;
    }
    if exfat_volume_read((*m).volume_index, sector, buf, 2) != STATUS_SUCCESS {
        crate::mm::pool::ex_free_pool(buf as *mut c_void);
        return 0xFFFF_FFFF;
    }
    let v = *(buf.add(ent) as *const u32);
    crate::mm::pool::ex_free_pool(buf as *mut c_void);
    v
}

/// Scan root for the bitmap (type 0x81) + upcase (0x82) entries.
unsafe fn exfat_find_system_files(m: *mut ExfatMount) {
    let cb = exfat_cluster_bytes(m);
    let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(cb) as *mut u8;
    if buf.is_null() {
        return;
    }
    let mut cluster = (*m).root_cluster;
    loop {
        if exfat_read_cluster(m, cluster, buf) != STATUS_SUCCESS {
            break;
        }
        let entries = cb / 32;
        let mut e = 0usize;
        while e < entries {
            let rec = buf.add(e * 32);
            match *rec {
                0x81 => {
                    // Bitmap: first cluster at offset 20.
                    (*m).bitmap_cluster = *(rec.add(20) as *const u32);
                    (*m).bitmap_size = *(rec.add(24) as *const u64);
                }
                0x82 => {
                    (*m).upcase_cluster = *(rec.add(20) as *const u32);
                }
                EXFAT_ENTRY_END => {
                    e = entries;
                    cluster = 0xFFFF_FFFF;
                    break;
                }
                _ => {}
            }
            e += 1;
        }
        if cluster >= 0xFFFF_FFFF {
            break;
        }
        cluster = exfat_next_cluster(m, cluster);
        if cluster < 2 || cluster == 0xFFFF_FFFF {
            break;
        }
    }
    crate::mm::pool::ex_free_pool(buf as *mut c_void);
}

/// Compare an entry-set name (UTF-16) with the query, case-insensitive.
unsafe fn exfat_name_matches(
    name_entries: *const u8,
    name_len: u8,
    query: *const u16,
) -> bool {
    // Assemble up to 255 chars from consecutive 0xC1 entries.
    let mut assembled = [0u16; 256];
    let mut total = 0usize;
    let mut e = 0u8;
    while e < name_len {
        let rec = name_entries.add(e as usize * 32);
        if *rec != EXFAT_ENTRY_NAME {
            return false;
        }
        let mut k = 0;
        while k < 15 && total < 255 {
            assembled[total] = *(rec.add(2 + k * 2) as *const u16);
            total += 1;
            k += 1;
        }
        e += 1;
    }
    // Compare.
    let mut q = 0usize;
    let mut ai = 0usize;
    loop {
        let qc = *query.add(q);
        let ac = if ai < total { assembled[ai] } else { 0 };
        if qc == 0 && (ac == 0 || ai >= total) {
            return true;
        }
        if qc == 0 || ai >= total {
            return false;
        }
        if crate::nls::nls_upcase_full(qc) != crate::nls::nls_upcase_full(ac) {
            return false;
        }
        q += 1;
        ai += 1;
        if q >= 255 {
            return false;
        }
    }
}

/// ExfatLookup - find a file by name in a directory.
pub unsafe fn exfat_lookup(
    m: *mut ExfatMount,
    dir_cluster: u32,
    name: *const u16,
    info_out: *mut ExfatFileInfo,
) -> NtStatus {
    if m.is_null() || name.is_null() || info_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let cb = exfat_cluster_bytes(m);
    let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(cb) as *mut u8;
    if buf.is_null() {
        return STATUS_NO_MEMORY;
    }
    let mut result = STATUS_OBJECT_NAME_NOT_FOUND;
    let mut cluster = dir_cluster;
    loop {
        if exfat_read_cluster(m, cluster, buf) != STATUS_SUCCESS {
            result = STATUS_IO_DEVICE_ERROR;
            break;
        }
        let entries = cb / 32;
        let mut e = 0usize;
        while e < entries {
            let rec = buf.add(e * 32);
            let etype = *rec;
            if etype == EXFAT_ENTRY_END {
                e = entries;
                cluster = 0xFFFF_FFFF;
                break;
            }
            if etype == EXFAT_ENTRY_FILE {
                let secondaries = *rec.add(1);
                let attr = *(rec.add(4) as *const u16);
                // Stream entry must follow immediately.
                if e + 1 < entries {
                    let stream = buf.add((e + 1) * 32);
                    if *stream == EXFAT_ENTRY_STREAM {
                        let name_len = *stream.add(3);
                        let flags = *(stream.add(1) as *const u16);
                        let first = *(stream.add(20) as *const u32);
                        let data_len = *(stream.add(24) as *const u64);
                        // Name entries follow.
                        let set_len = 2 + name_len as usize;
                        if e + set_len <= entries
                            && exfat_name_matches(buf.add((e + 2) * 32), name_len, name)
                        {
                            (*info_out).first_cluster = first;
                            (*info_out).data_length = data_len;
                            (*info_out).contiguous =
                                flags & EXFAT_STREAM_CONTIGUOUS == 0;
                            (*info_out).is_directory =
                                attr & EXFAT_ATTR_DIRECTORY != 0;
                            result = STATUS_SUCCESS;
                            e = entries;
                            cluster = 0xFFFF_FFFF;
                            break;
                        }
                        let _ = secondaries;
                    }
                }
            }
            e += 1;
        }
        if cluster >= 0xFFFF_FFFF {
            break;
        }
        cluster = exfat_next_cluster(m, cluster);
        if cluster < 2 || cluster == 0xFFFF_FFFF {
            break;
        }
    }
    crate::mm::pool::ex_free_pool(buf as *mut c_void);
    result
}

/// ExfatRead - read file data (contiguous fast path + FAT walk).
pub unsafe fn exfat_read(
    m: *mut ExfatMount,
    info: *const ExfatFileInfo,
    file_offset: u64,
    buffer: *mut u8,
    length: usize,
) -> NtStatus {
    if m.is_null() || info.is_null() || buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if file_offset >= (*info).data_length {
        return STATUS_END_OF_FILE;
    }
    let to_read = (length as u64).min((*info).data_length - file_offset) as usize;
    let cb = exfat_cluster_bytes(m);
    let spc = 1u64 << (*m).sectors_per_cluster_shift;
    let tmp = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(cb) as *mut u8;
    if tmp.is_null() {
        return STATUS_NO_MEMORY;
    }
    let mut done = 0usize;
    let mut cluster = (*info).first_cluster;
    let mut skip_clusters = file_offset / cb as u64;
    // For contiguous files, compute directly.
    if (*info).contiguous {
        let start_lba = exfat_cluster_lba(m, cluster) + (file_offset / 512);
        // Read whole range (may span clusters = contiguous sectors).
        let end_lba = exfat_cluster_lba(m, cluster)
            + ((file_offset + to_read as u64 + 511) / 512);
        let sectors = (end_lba - start_lba) as usize;
        let bounce = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(sectors * 512)
            as *mut u8;
        if bounce.is_null() {
            crate::mm::pool::ex_free_pool(tmp as *mut c_void);
            return STATUS_NO_MEMORY;
        }
        // Chunk to u16 sector counts.
        let mut off = 0usize;
        let mut lba = start_lba;
        let mut st = STATUS_SUCCESS;
        while off < sectors {
            let chunk = (sectors - off).min(32768) as u16;
            st = exfat_volume_read((*m).volume_index, lba, bounce.add(off * 512), chunk);
            if st != STATUS_SUCCESS {
                break;
            }
            off += chunk as usize;
            lba += chunk as u64;
        }
        if st == STATUS_SUCCESS {
            core::ptr::copy_nonoverlapping(
                bounce.add((file_offset % 512) as usize),
                buffer,
                to_read,
            );
        }
        crate::mm::pool::ex_free_pool(bounce as *mut c_void);
        crate::mm::pool::ex_free_pool(tmp as *mut c_void);
        return st;
    }
    // Fragmented: walk FAT.
    while skip_clusters > 0 {
        cluster = exfat_next_cluster(m, cluster);
        if cluster < 2 || cluster == 0xFFFF_FFFF {
            crate::mm::pool::ex_free_pool(tmp as *mut c_void);
            return STATUS_END_OF_FILE;
        }
        skip_clusters -= 1;
    }
    let _ = spc;
    let mut off_in_cluster = (file_offset % cb as u64) as usize;
    while done < to_read {
        if exfat_read_cluster(m, cluster, tmp) != STATUS_SUCCESS {
            crate::mm::pool::ex_free_pool(tmp as *mut c_void);
            return STATUS_IO_DEVICE_ERROR;
        }
        let avail = cb - off_in_cluster;
        let n = avail.min(to_read - done);
        core::ptr::copy_nonoverlapping(tmp.add(off_in_cluster), buffer.add(done), n);
        done += n;
        off_in_cluster = 0;
        if done < to_read {
            cluster = exfat_next_cluster(m, cluster);
            if cluster < 2 || cluster == 0xFFFF_FFFF {
                break;
            }
        }
    }
    crate::mm::pool::ex_free_pool(tmp as *mut c_void);
    if done < to_read {
        STATUS_END_OF_FILE
    } else {
        STATUS_SUCCESS
    }
}
