/// FAT32 driver (fastfat.sys): mount, cluster chains, LFN, read
///
/// BPB parsing, FAT mirroring-aware reads, 8.3 + long file names,
/// directory iteration, and file data reads through the volume
/// block interface.

use core::ffi::c_void;

use crate::types::*;

pub const FAT32_MAX_MOUNTS: usize = 8;
pub const FAT32_SECTOR: usize = 512;
pub const FAT_EOC: u32 = 0x0FFF_FFF8;
pub const FAT_BAD: u32 = 0x0FFF_FFF7;

pub const FAT_ATTR_READ_ONLY: u8 = 0x01;
pub const FAT_ATTR_HIDDEN: u8 = 0x02;
pub const FAT_ATTR_SYSTEM: u8 = 0x04;
pub const FAT_ATTR_VOLUME_ID: u8 = 0x08;
pub const FAT_ATTR_DIRECTORY: u8 = 0x10;
pub const FAT_ATTR_ARCHIVE: u8 = 0x20;
pub const FAT_ATTR_LFN: u8 = 0x0F;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Fat32Bpb {
    pub bytes_per_sector: u16,
    pub sectors_per_cluster: u8,
    pub reserved_sectors: u16,
    pub fat_count: u8,
    pub sectors_per_fat: u32,
    pub root_cluster: u32,
    pub total_sectors: u32,
    pub volume_id: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct FatDirEntry {
    pub name: [u8; 11],
    pub attr: u8,
    pub cluster: u32,
    pub size: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Fat32Mount {
    pub volume_index: usize,
    pub bpb: Fat32Bpb,
    pub fat_lba: u64,
    pub data_lba: u64,
    pub mounted: bool,
}

static mut FAT32_MOUNTS: [Fat32Mount; FAT32_MAX_MOUNTS] = [Fat32Mount {
    volume_index: 0,
    bpb: Fat32Bpb {
        bytes_per_sector: 512,
        sectors_per_cluster: 1,
        reserved_sectors: 0,
        fat_count: 0,
        sectors_per_fat: 0,
        root_cluster: 0,
        total_sectors: 0,
        volume_id: 0,
    },
    fat_lba: 0,
    data_lba: 0,
    mounted: false,
}; FAT32_MAX_MOUNTS];

unsafe fn fat32_volume_read(
    volume_index: usize,
    lba: u64,
    buffer: *mut u8,
    sectors: u16,
) -> NtStatus {
    crate::drivers::storage::classpnp::class_volume_read(volume_index, lba, buffer, sectors)
}

/// FatMount - parse BPB on a volume.
pub unsafe fn fat32_mount(volume_index: usize) -> *mut Fat32Mount {
    let mut i = 0;
    while i < FAT32_MAX_MOUNTS {
        if !FAT32_MOUNTS[i].mounted {
            let sector = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(512)
                as *mut u8;
            if sector.is_null() {
                return core::ptr::null_mut();
            }
            if fat32_volume_read(volume_index, 0, sector, 1) != STATUS_SUCCESS {
                crate::mm::pool::ex_free_pool(sector as *mut c_void);
                return core::ptr::null_mut();
            }
            // Validate jump + "FAT32" OEM string at offset 82.
            let valid = (*sector.add(510) == 0x55 && *sector.add(511) == 0xAA)
                && *sector.add(82) == b'F'
                && *sector.add(83) == b'A'
                && *sector.add(84) == b'T'
                && *sector.add(85) == b'3'
                && *sector.add(86) == b'2';
            if !valid {
                crate::mm::pool::ex_free_pool(sector as *mut c_void);
                return core::ptr::null_mut();
            }
            let bps = *(sector.add(11) as *const u16);
            let spc = *sector.add(13);
            let reserved = *(sector.add(14) as *const u16);
            let fats = *sector.add(16);
            let spf = *(sector.add(36) as *const u32);
            let root = *(sector.add(44) as *const u32);
            let total = *(sector.add(32) as *const u32);
            let volid = *(sector.add(67) as *const u32);
            crate::mm::pool::ex_free_pool(sector as *mut c_void);
            if bps as usize != FAT32_SECTOR || spc == 0 || fats == 0 {
                return core::ptr::null_mut();
            }
            FAT32_MOUNTS[i].volume_index = volume_index;
            FAT32_MOUNTS[i].bpb = Fat32Bpb {
                bytes_per_sector: bps,
                sectors_per_cluster: spc,
                reserved_sectors: reserved,
                fat_count: fats,
                sectors_per_fat: spf,
                root_cluster: root,
                total_sectors: total,
                volume_id: volid,
            };
            FAT32_MOUNTS[i].fat_lba = reserved as u64;
            FAT32_MOUNTS[i].data_lba =
                reserved as u64 + fats as u64 * spf as u64;
            FAT32_MOUNTS[i].mounted = true;
            return &mut FAT32_MOUNTS[i] as *mut Fat32Mount;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

unsafe fn fat32_cluster_lba(m: *mut Fat32Mount, cluster: u32) -> u64 {
    (*m).data_lba + (cluster as u64 - 2) * (*m).bpb.sectors_per_cluster as u64
}

/// Read one FAT entry (FAT0, 28-bit values).
unsafe fn fat32_next_cluster(m: *mut Fat32Mount, cluster: u32) -> u32 {
    let fat_offset = cluster as u64 * 4;
    let sector = (*m).fat_lba + fat_offset / 512;
    let ent_off = (fat_offset % 512) as usize;
    // Entry may straddle sectors: read two.
    let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(1024) as *mut u8;
    if buf.is_null() {
        return FAT_EOC;
    }
    if fat32_volume_read((*m).volume_index, sector, buf, 2) != STATUS_SUCCESS {
        crate::mm::pool::ex_free_pool(buf as *mut c_void);
        return FAT_EOC;
    }
    let v = *(buf.add(ent_off) as *const u32) & 0x0FFF_FFFF;
    crate::mm::pool::ex_free_pool(buf as *mut c_void);
    v
}

unsafe fn fat32_read_cluster(
    m: *mut Fat32Mount,
    cluster: u32,
    buffer: *mut u8,
) -> NtStatus {
    let lba = fat32_cluster_lba(m, cluster);
    fat32_volume_read(
        (*m).volume_index,
        lba,
        buffer,
        (*m).bpb.sectors_per_cluster as u16,
    )
}

/// Compare an 8.3 name (already uppercased, space-padded).
unsafe fn fat32_match_short(raw: *const u8, name83: &[u8; 11]) -> bool {
    let mut i = 0;
    while i < 11 {
        let mut c = *raw.add(i);
        if c >= b'a' && c <= b'z' {
            c -= 32;
        }
        if c != name83[i] {
            return false;
        }
        i += 1;
    }
    true
}

/// Build 8.3 uppercase key from a UTF-16 name ("FILE.TXT" -> "FILE    TXT").
unsafe fn fat32_name_to_83(name: *const u16, out83: *mut u8) {
    let mut i = 0;
    while i < 11 {
        *out83.add(i) = b' ';
        i += 1;
    }
    // Split at last dot.
    let mut len = 0usize;
    while *name.add(len) != 0 && len < 256 {
        len += 1;
    }
    let mut dot = len;
    let mut k = 0;
    while k < len {
        if *name.add(k) == b'.' as u16 {
            dot = k;
        }
        k += 1;
    }
    let base_len = dot.min(8);
    let mut j = 0;
    while j < base_len {
        let mut c = *name.add(j);
        if c >= 0x61 && c <= 0x7A {
            c -= 32;
        }
        *out83.add(j) = c.min(0x7F) as u8;
        j += 1;
    }
    if dot < len {
        let ext_len = (len - dot - 1).min(3);
        j = 0;
        while j < ext_len {
            let mut c = *name.add(dot + 1 + j);
            if c >= 0x61 && c <= 0x7A {
                c -= 32;
            }
            *out83.add(8 + j) = c.min(0x7F) as u8;
            j += 1;
        }
    }
}

/// FatLookup - find a file in a directory cluster chain.
///
/// Returns cluster + size + attr. Handles LFN entries by assembling
/// the UTF-16 name and comparing case-insensitively.
pub unsafe fn fat32_lookup(
    m: *mut Fat32Mount,
    dir_cluster: u32,
    name: *const u16,
    entry_out: *mut FatDirEntry,
) -> NtStatus {
    if m.is_null() || name.is_null() || entry_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut key83 = [b' '; 11];
    fat32_name_to_83(name, key83.as_mut_ptr());
    let cluster_bytes =
        (*m).bpb.sectors_per_cluster as usize * FAT32_SECTOR;
    let cluster_buf =
        crate::mm::pool::ex_allocate_nonpaged_cache_aligned(cluster_bytes) as *mut u8;
    if cluster_buf.is_null() {
        return STATUS_NO_MEMORY;
    }
    // LFN assembly buffer.
    let mut lfn = [0u16; 256];
    let mut lfn_valid = false;
    let mut cluster = dir_cluster;
    let mut result = STATUS_OBJECT_NAME_NOT_FOUND;
    loop {
        if fat32_read_cluster(m, cluster, cluster_buf) != STATUS_SUCCESS {
            result = STATUS_IO_DEVICE_ERROR;
            break;
        }
        let entries = cluster_bytes / 32;
        let mut e = 0usize;
        while e < entries {
            let rec = cluster_buf.add(e * 32);
            let first = *rec;
            if first == 0x00 {
                // End of directory.
                e = entries; // break inner
                cluster = FAT_EOC; // break outer after
                break;
            }
            if first != 0xE5 {
                let attr = *rec.add(11);
                if attr == FAT_ATTR_LFN {
                    // LFN part: sequence in low 5 bits, LAST flag 0x40.
                    let seq = (first & 0x1F) as usize;
                    if seq >= 1 && seq <= 20 {
                        let base = (seq - 1) * 13;
                        // Chars at 1..10, 14..25, 28..31 (UTF-16LE).
                        let mut k = 0;
                        while k < 5 {
                            let c = *(rec.add(1 + k * 2) as *const u16);
                            if base + k < 255 {
                                lfn[base + k] = c;
                            }
                            k += 1;
                        }
                        k = 0;
                        while k < 6 {
                            let c = *(rec.add(14 + k * 2) as *const u16);
                            if base + 5 + k < 255 {
                                lfn[base + 5 + k] = c;
                            }
                            k += 1;
                        }
                        k = 0;
                        while k < 2 {
                            let c = *(rec.add(28 + k * 2) as *const u16);
                            if base + 11 + k < 255 {
                                lfn[base + 11 + k] = c;
                            }
                            k += 1;
                        }
                        if first & 0x40 != 0 {
                            // Last part: terminate after it.
                            let end = base + 13;
                            if end < 256 {
                                lfn[end] = 0;
                            }
                        }
                        lfn_valid = true;
                    }
                } else {
                    // Short entry: compare LFN first, else 8.3.
                    let mut matched = false;
                    if lfn_valid {
                        // Case-insensitive compare with query.
                        let mut q = 0usize;
                        let mut li = 0usize;
                        matched = true;
                        loop {
                            let qc = *name.add(q);
                            let lc = if li < 256 { lfn[li] } else { 0 };
                            if qc == 0 && (lc == 0 || lc == 0xFFFF) {
                                break;
                            }
                            if qc == 0 || lc == 0 || lc == 0xFFFF {
                                matched = false;
                                break;
                            }
                            if crate::nls::nls_upcase_full(qc)
                                != crate::nls::nls_upcase_full(lc)
                            {
                                matched = false;
                                break;
                            }
                            q += 1;
                            li += 1;
                            if q >= 255 || li >= 255 {
                                break;
                            }
                        }
                    }
                    if !matched && fat32_match_short(rec, &key83) {
                        matched = true;
                    }
                    lfn_valid = false;
                    if matched {
                        let clus_hi = *(rec.add(20) as *const u16) as u32;
                        let clus_lo = *(rec.add(26) as *const u16) as u32;
                        (*entry_out).name = [0; 11];
                        core::ptr::copy_nonoverlapping(rec, (*entry_out).name.as_mut_ptr(), 11);
                        (*entry_out).attr = attr;
                        (*entry_out).cluster = (clus_hi << 16) | clus_lo;
                        (*entry_out).size = *(rec.add(28) as *const u32);
                        result = STATUS_SUCCESS;
                        e = entries;
                        cluster = FAT_EOC;
                        break;
                    }
                }
            } else {
                lfn_valid = false;
            }
            e += 1;
        }
        if cluster >= FAT_EOC {
            break;
        }
        cluster = fat32_next_cluster(m, cluster);
        if cluster < 2 {
            break;
        }
    }
    crate::mm::pool::ex_free_pool(cluster_buf as *mut c_void);
    result
}

/// FatRead - read file data by cluster chain + byte offset.
pub unsafe fn fat32_read(
    m: *mut Fat32Mount,
    start_cluster: u32,
    file_size: u32,
    file_offset: u64,
    buffer: *mut u8,
    length: usize,
) -> NtStatus {
    if m.is_null() || buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if file_offset >= file_size as u64 {
        return STATUS_END_OF_FILE;
    }
    let to_read = (length as u64).min(file_size as u64 - file_offset) as usize;
    let cluster_bytes = (*m).bpb.sectors_per_cluster as usize * FAT32_SECTOR;
    let cluster_buf =
        crate::mm::pool::ex_allocate_nonpaged_cache_aligned(cluster_bytes) as *mut u8;
    if cluster_buf.is_null() {
        return STATUS_NO_MEMORY;
    }
    // Walk to the starting cluster.
    let mut cluster = start_cluster;
    let mut skip = file_offset / cluster_bytes as u64;
    while skip > 0 {
        cluster = fat32_next_cluster(m, cluster);
        if cluster < 2 || cluster >= FAT_EOC {
            crate::mm::pool::ex_free_pool(cluster_buf as *mut c_void);
            return STATUS_END_OF_FILE;
        }
        skip -= 1;
    }
    let mut done = 0usize;
    let mut off_in_cluster = (file_offset % cluster_bytes as u64) as usize;
    while done < to_read {
        if fat32_read_cluster(m, cluster, cluster_buf) != STATUS_SUCCESS {
            crate::mm::pool::ex_free_pool(cluster_buf as *mut c_void);
            return STATUS_IO_DEVICE_ERROR;
        }
        let avail = cluster_bytes - off_in_cluster;
        let n = avail.min(to_read - done);
        core::ptr::copy_nonoverlapping(
            cluster_buf.add(off_in_cluster),
            buffer.add(done),
            n,
        );
        done += n;
        off_in_cluster = 0;
        if done < to_read {
            cluster = fat32_next_cluster(m, cluster);
            if cluster < 2 || cluster >= FAT_EOC {
                break;
            }
        }
    }
    crate::mm::pool::ex_free_pool(cluster_buf as *mut c_void);
    if done < to_read {
        STATUS_END_OF_FILE
    } else {
        STATUS_SUCCESS
    }
}

/// FatIterateDir - call cb for each entry (cb returns false to stop).
pub unsafe fn fat32_iterate_dir(
    m: *mut Fat32Mount,
    dir_cluster: u32,
    cb: unsafe fn(entry: *const FatDirEntry, ctx: *mut c_void) -> bool,
    ctx: *mut c_void,
) -> NtStatus {
    if m.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let cluster_bytes = (*m).bpb.sectors_per_cluster as usize * FAT32_SECTOR;
    let cluster_buf =
        crate::mm::pool::ex_allocate_nonpaged_cache_aligned(cluster_bytes) as *mut u8;
    if cluster_buf.is_null() {
        return STATUS_NO_MEMORY;
    }
    let mut cluster = dir_cluster;
    loop {
        if fat32_read_cluster(m, cluster, cluster_buf) != STATUS_SUCCESS {
            crate::mm::pool::ex_free_pool(cluster_buf as *mut c_void);
            return STATUS_IO_DEVICE_ERROR;
        }
        let entries = cluster_bytes / 32;
        let mut e = 0usize;
        while e < entries {
            let rec = cluster_buf.add(e * 32);
            if *rec == 0x00 {
                crate::mm::pool::ex_free_pool(cluster_buf as *mut c_void);
                return STATUS_SUCCESS;
            }
            if *rec != 0xE5 && *rec.add(11) != FAT_ATTR_LFN {
                let mut de = FatDirEntry {
                    name: [0; 11],
                    attr: 0,
                    cluster: 0,
                    size: 0,
                };
                core::ptr::copy_nonoverlapping(rec, de.name.as_mut_ptr(), 11);
                de.attr = *rec.add(11);
                let hi = *(rec.add(20) as *const u16) as u32;
                let lo = *(rec.add(26) as *const u16) as u32;
                de.cluster = (hi << 16) | lo;
                de.size = *(rec.add(28) as *const u32);
                if !cb(&de, ctx) {
                    crate::mm::pool::ex_free_pool(cluster_buf as *mut c_void);
                    return STATUS_SUCCESS;
                }
            }
            e += 1;
        }
        cluster = fat32_next_cluster(m, cluster);
        if cluster < 2 || cluster >= FAT_EOC {
            break;
        }
    }
    crate::mm::pool::ex_free_pool(cluster_buf as *mut c_void);
    STATUS_SUCCESS
}

// Local status codes.
pub const STATUS_IO_DEVICE_ERROR: NtStatus = 0xC0000185u32 as i32;
pub const STATUS_END_OF_FILE: NtStatus = 0xC0000011u32 as i32;
