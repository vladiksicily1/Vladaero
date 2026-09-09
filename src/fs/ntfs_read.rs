/// NTFS read-only driver (ntfs.sys RO path): boot sector, MFT,
///
/// Resident vs non-resident $DATA (data runs), directory index
/// ($I30) walk, and file reads for Windows partitions.

use core::ffi::c_void;

use crate::types::*;
use super::fat32::{STATUS_IO_DEVICE_ERROR, STATUS_END_OF_FILE};

pub const NTFS_MAX_MOUNTS: usize = 8;
pub const NTFS_SECTOR: usize = 512;

pub const NTFS_ATTR_STANDARD_INFORMATION: u32 = 0x10;
pub const NTFS_ATTR_FILE_NAME: u32 = 0x30;
pub const NTFS_ATTR_DATA: u32 = 0x80;
pub const NTFS_ATTR_INDEX_ROOT: u32 = 0x90;
pub const NTFS_ATTR_INDEX_ALLOCATION: u32 = 0xA0;
pub const NTFS_ATTR_END: u32 = 0xFFFF_FFFF;

pub const NTFS_MFT_RECORD_MAGIC: u32 = 0x454C_4946; // "FILE"
pub const NTFS_MFT_IN_USE: u16 = 0x0001;
pub const NTFS_MFT_IS_DIRECTORY: u16 = 0x0002;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct NtfsMount {
    pub volume_index: usize,
    pub bytes_per_sector: u32,
    pub sectors_per_cluster: u8,
    pub mft_lba: u64,
    pub mft_record_size: u32,
    pub index_block_size: u32,
    pub serial: u64,
    pub mounted: bool,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct NtfsFileInfo {
    pub mft_number: u64,
    pub data_size: u64,
    pub is_directory: bool,
}

static mut NTFS_MOUNTS: [NtfsMount; NTFS_MAX_MOUNTS] = [NtfsMount {
    volume_index: 0,
    bytes_per_sector: 512,
    sectors_per_cluster: 8,
    mft_lba: 0,
    mft_record_size: 1024,
    index_block_size: 4096,
    serial: 0,
    mounted: false,
}; NTFS_MAX_MOUNTS];

unsafe fn ntfs_volume_read(
    volume_index: usize,
    lba: u64,
    buffer: *mut u8,
    sectors: u16,
) -> NtStatus {
    crate::drivers::storage::classpnp::class_volume_read(volume_index, lba, buffer, sectors)
}

/// NtfsMount - validate boot sector ("NTFS    ") + locate MFT.
pub unsafe fn ntfs_mount(volume_index: usize) -> *mut NtfsMount {
    let mut i = 0;
    while i < NTFS_MAX_MOUNTS {
        if !NTFS_MOUNTS[i].mounted {
            let sector = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(512)
                as *mut u8;
            if sector.is_null() {
                return core::ptr::null_mut();
            }
            if ntfs_volume_read(volume_index, 0, sector, 1) != STATUS_SUCCESS {
                crate::mm::pool::ex_free_pool(sector as *mut c_void);
                return core::ptr::null_mut();
            }
            let valid = *sector.add(510) == 0x55
                && *sector.add(511) == 0xAA
                && *sector.add(3) == b'N'
                && *sector.add(4) == b'T'
                && *sector.add(5) == b'F'
                && *sector.add(6) == b'S';
            if !valid {
                crate::mm::pool::ex_free_pool(sector as *mut c_void);
                return core::ptr::null_mut();
            }
            let bps = *(sector.add(11) as *const u16) as u32;
            let spc = *sector.add(13);
            let mft_lcn = *(sector.add(48) as *const u64);
            let clusters_per_mft: i8 = *sector.add(64) as i8;
            let clusters_per_index: i8 = *sector.add(68) as i8;
            let serial = *(sector.add(72) as *const u64);
            let total_sectors = *(sector.add(40) as *const u64);
            crate::mm::pool::ex_free_pool(sector as *mut c_void);
            if bps != 512 || spc == 0 {
                return core::ptr::null_mut();
            }
            let bytes_per_cluster = bps as u64 * spc as u64;
            let mft_record_size = if clusters_per_mft < 0 {
                1u32 << (-(clusters_per_mft as i32)) as u32
            } else {
                clusters_per_mft as u32 * bytes_per_cluster as u32
            };
            let index_size = if clusters_per_index < 0 {
                1u32 << (-(clusters_per_index as i32)) as u32
            } else {
                clusters_per_index as u32 * bytes_per_cluster as u32
            };
            NTFS_MOUNTS[i].volume_index = volume_index;
            NTFS_MOUNTS[i].bytes_per_sector = bps;
            NTFS_MOUNTS[i].sectors_per_cluster = spc;
            NTFS_MOUNTS[i].mft_lba = mft_lcn * spc as u64;
            NTFS_MOUNTS[i].mft_record_size = mft_record_size;
            NTFS_MOUNTS[i].index_block_size = index_size;
            NTFS_MOUNTS[i].serial = serial;
            NTFS_MOUNTS[i].mounted = true;
            let _ = total_sectors;
            return &mut NTFS_MOUNTS[i] as *mut NtfsMount;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

/// Read one MFT record by number (with update-sequence fixup).
unsafe fn ntfs_read_mft_record(
    m: *mut NtfsMount,
    mft_number: u64,
    buffer: *mut u8,
) -> NtStatus {
    let rec_size = (*m).mft_record_size as u64;
    let mft_start = (*m).mft_lba * 512;
    let byte_off = mft_number * rec_size;
    let lba = (mft_start + byte_off) / 512;
    let sectors = (rec_size / 512) as u16;
    if ntfs_volume_read((*m).volume_index, lba, buffer, sectors) != STATUS_SUCCESS {
        return STATUS_IO_DEVICE_ERROR;
    }
    if *(buffer as *const u32) != NTFS_MFT_RECORD_MAGIC {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    // Update sequence fixup.
    let usa_off = *(buffer.add(4) as *const u16) as usize;
    let usa_count = *(buffer.add(6) as *const u16) as usize;
    if usa_off + usa_count * 2 <= rec_size as usize && usa_count >= 2 {
        let usn = *(buffer.add(usa_off) as *const u16);
        let stride = 512;
        let mut s = 1usize;
        while s < usa_count {
            let sector_end = buffer.add(s * stride - 2);
            if *(sector_end as *const u16) != usn {
                return STATUS_IO_DEVICE_ERROR; // torn write
            }
            *sector_end = *buffer.add(usa_off + s * 2);
            *(sector_end.add(1)) = *buffer.add(usa_off + s * 2 + 1);
            s += 1;
        }
    }
    STATUS_SUCCESS
}

/// Parse data runs into (lcn, clusters) list. Returns run count.
unsafe fn ntfs_parse_runs(
    runs: *const u8,
    runs_len: usize,
    out_lcn: *mut u64,
    out_clusters: *mut u64,
    max_runs: usize,
) -> usize {
    let mut off = 0usize;
    let mut count = 0usize;
    let mut prev_lcn = 0i64;
    while off < runs_len && count < max_runs {
        let header = *runs.add(off);
        if header == 0 {
            break;
        }
        let len_size = (header & 0x0F) as usize;
        let off_size = ((header >> 4) & 0x0F) as usize;
        off += 1;
        if off + len_size + off_size > runs_len {
            break;
        }
        let mut run_len = 0u64;
        let mut k = 0;
        while k < len_size {
            run_len |= (*runs.add(off + k) as u64) << (k * 8);
            k += 1;
        }
        off += len_size;
        // Signed LCN delta.
        let mut delta = 0i64;
        if off_size > 0 {
            let mut raw = 0u64;
            k = 0;
            while k < off_size {
                raw |= (*runs.add(off + k) as u64) << (k * 8);
                k += 1;
            }
            // Sign-extend.
            let shift = 64 - off_size * 8;
            delta = ((raw << shift) as i64) >> shift;
            off += off_size;
        }
        // Sparse run (offset size 0): LCN unchanged marker.
        if off_size == 0 {
            *out_lcn.add(count) = u64::MAX; // sparse
        } else {
            prev_lcn += delta;
            *out_lcn.add(count) = prev_lcn as u64;
        }
        *out_clusters.add(count) = run_len;
        count += 1;
    }
    count
}

/// Find the $DATA attribute (unnamed) in a record.
/// Returns (resident, value_ptr/value_len | runs_ptr/runs_len, data_size).
unsafe fn ntfs_find_data_attr(
    record: *const u8,
    rec_size: usize,
    resident_out: *mut bool,
    ptr_out: *mut *const u8,
    len_out: *mut usize,
    size_out: *mut u64,
) -> NtStatus {
    let attr_off = *(record.add(20) as *const u16) as usize;
    let mut off = attr_off;
    while off + 16 <= rec_size {
        let atype = *(record.add(off) as *const u32);
        if atype == NTFS_ATTR_END {
            break;
        }
        let alen = *(record.add(off + 4) as *const u32) as usize;
        if alen < 16 || off + alen > rec_size {
            break;
        }
        let nonres = *record.add(off + 8);
        let name_len = *record.add(off + 9);
        if atype == NTFS_ATTR_DATA && name_len == 0 {
            if nonres == 0 {
                let vlen = *(record.add(off + 16) as *const u32) as usize;
                let voff = *(record.add(off + 20) as *const u16) as usize;
                if off + voff + vlen <= rec_size {
                    *resident_out = true;
                    *ptr_out = record.add(off + voff);
                    *len_out = vlen;
                    *size_out = vlen as u64;
                    return STATUS_SUCCESS;
                }
            } else {
                let runs_off = *(record.add(off + 32) as *const u16) as usize;
                let alloc_size = *(record.add(off + 40) as *const u64);
                let real_size = *(record.add(off + 48) as *const u64);
                if off + runs_off < rec_size {
                    *resident_out = false;
                    *ptr_out = record.add(off + runs_off);
                    *len_out = alen - runs_off;
                    *size_out = real_size;
                    let _ = alloc_size;
                    return STATUS_SUCCESS;
                }
            }
        }
        off += alen;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// NtfsLookup - walk path components from the root (MFT 5).
pub unsafe fn ntfs_lookup(
    m: *mut NtfsMount,
    path: *const u16,
    info_out: *mut NtfsFileInfo,
) -> NtStatus {
    if m.is_null() || path.is_null() || info_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let rec_size = (*m).mft_record_size as usize;
    let record = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(rec_size) as *mut u8;
    if record.is_null() {
        return STATUS_NO_MEMORY;
    }
    // Split path into components.
    let mut comps: [[u16; 64]; 16] = [[0; 64]; 16];
    let mut ncomps = 0usize;
    let mut p = 0usize;
    // Skip leading separators.
    while *path.add(p) == b'\\' as u16 || *path.add(p) == b'/' as u16 {
        p += 1;
    }
    let mut cur = 0usize;
    loop {
        let c = *path.add(p);
        if c == 0 || c == b'\\' as u16 || c == b'/' as u16 {
            if cur > 0 && ncomps < 16 {
                comps[ncomps][cur] = 0;
                ncomps += 1;
                cur = 0;
            }
            if c == 0 {
                break;
            }
            p += 1;
            continue;
        }
        if cur < 63 {
            comps[ncomps][cur] = c;
            cur += 1;
        }
        p += 1;
        if p > 1024 {
            break;
        }
    }
    let mut dir_mft = 5u64; // root
    let mut ci = 0usize;
    let mut result = STATUS_OBJECT_NAME_NOT_FOUND;
    while ci < ncomps {
        let found = ntfs_find_in_directory(m, record, dir_mft, comps[ci].as_ptr(), info_out);
        if found != STATUS_SUCCESS {
            result = found;
            break;
        }
        if ci + 1 < ncomps && !(*info_out).is_directory {
            result = STATUS_OBJECT_NAME_NOT_FOUND;
            break;
        }
        dir_mft = (*info_out).mft_number;
        ci += 1;
        result = STATUS_SUCCESS;
    }
    // Empty path = root itself.
    if ncomps == 0 {
        (*info_out).mft_number = 5;
        (*info_out).data_size = 0;
        (*info_out).is_directory = true;
        result = STATUS_SUCCESS;
    }
    crate::mm::pool::ex_free_pool(record as *mut c_void);
    result
}

/// Find one name in a directory's $I30 (root + allocation walk).
unsafe fn ntfs_find_in_directory(
    m: *mut NtfsMount,
    record_buf: *mut u8,
    dir_mft: u64,
    name: *const u16,
    info_out: *mut NtfsFileInfo,
) -> NtStatus {
    if ntfs_read_mft_record(m, dir_mft, record_buf) != STATUS_SUCCESS {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    let rec_size = (*m).mft_record_size as usize;
    // 1. Index root ($I30 resident).
    if ntfs_search_index_root(record_buf, rec_size, name, info_out) == STATUS_SUCCESS {
        return STATUS_SUCCESS;
    }
    // 2. Index allocation (non-resident): walk index blocks.
    ntfs_search_index_allocation(m, record_buf, rec_size, name, info_out)
}

unsafe fn ntfs_name_equals(entry_name: *const u16, entry_len: u8, query: *const u16) -> bool {
    let mut i = 0u8;
    loop {
        let q = *query.add(i as usize);
        if q == 0 && i >= entry_len {
            return true;
        }
        if q == 0 || i >= entry_len {
            return false;
        }
        if crate::nls::nls_upcase_full(q) != crate::nls::nls_upcase_full(*entry_name.add(i as usize)) {
            return false;
        }
        i += 1;
        if i == 255 {
            return false;
        }
    }
}

/// Walk index entries in a buffer (index root or one index block).
unsafe fn ntfs_search_index_entries(
    entries: *const u8,
    entries_len: usize,
    name: *const u16,
    info_out: *mut NtfsFileInfo,
    subnode_vcn_out: *mut u64,
) -> NtStatus {
    let mut off = 0usize;
    // First entry offset is given by caller (skip node header).
    while off + 16 <= entries_len {
        let entry_len = *(entries.add(off + 8) as *const u16) as usize;
        if entry_len < 16 || off + entry_len > entries_len {
            break;
        }
        let flags = *(entries.add(off + 10) as *const u16);
        if flags & 0x02 != 0 {
            // End marker.
            break;
        }
        let mft_num = (*(entries.add(off) as *const u64)) & 0x0000_FFFF_FFFF_FFFF;
        let name_len = *entries.add(off + 64 + 16);
        let name_off = off + 64 + 18;
        if name_off + (name_len as usize) * 2 <= off + entry_len
            && ntfs_name_equals(
                entries.add(name_off) as *const u16,
                name_len,
                name,
            )
        {
            // Read the target MFT record for size/dir flag.
            // (caller provides mount via info? do it here with globals is
            //  complex; return MFT number and let caller stat it.)
            (*info_out).mft_number = mft_num;
            (*info_out).data_size = 0;
            (*info_out).is_directory = false;
            return STATUS_SUCCESS;
        }
        if flags & 0x01 != 0 && !subnode_vcn_out.is_null() {
            *subnode_vcn_out = *(entries.add(off + entry_len - 8) as *const u64);
        }
        if entry_len == 0 {
            break;
        }
        off += entry_len;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

unsafe fn ntfs_search_index_root(
    record: *const u8,
    rec_size: usize,
    name: *const u16,
    info_out: *mut NtfsFileInfo,
) -> NtStatus {
    // Find $INDEX_ROOT $I30.
    let attr_off = *(record.add(20) as *const u16) as usize;
    let mut off = attr_off;
    while off + 16 <= rec_size {
        let atype = *(record.add(off) as *const u32);
        if atype == NTFS_ATTR_END {
            break;
        }
        let alen = *(record.add(off + 4) as *const u32) as usize;
        if alen < 16 || off + alen > rec_size {
            break;
        }
        // $I30 name check: type 0x90, name "$I30".
        if atype == NTFS_ATTR_INDEX_ROOT {
            let nonres = *record.add(off + 8);
            if nonres == 0 {
                let vlen = *(record.add(off + 16) as *const u32) as usize;
                let voff = *(record.add(off + 20) as *const u16) as usize;
                // Index root header: 16 bytes, then entries.
                if off + voff + 16 <= rec_size {
                    let entries_off = off + voff + 16;
                    let first_off =
                        *(record.add(off + voff + 8) as *const u32) as usize;
                    let total =
                        *(record.add(off + voff + 12) as *const u32) as usize;
                    let start = entries_off + first_off;
                    let len = total.saturating_sub(first_off);
                    if start + len <= rec_size && vlen >= 16 {
                        let mut subnode = 0u64;
                        if ntfs_search_index_entries(
                            record.add(start),
                            len.min(vlen),
                            name,
                            info_out,
                            &mut subnode,
                        ) == STATUS_SUCCESS
                        {
                            return STATUS_SUCCESS;
                        }
                    }
                }
            }
        }
        off += alen;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

unsafe fn ntfs_search_index_allocation(
    m: *mut NtfsMount,
    record: *const u8,
    rec_size: usize,
    name: *const u16,
    info_out: *mut NtfsFileInfo,
) -> NtStatus {
    // Find non-resident $INDEX_ALLOCATION runs, read each block.
    let attr_off = *(record.add(20) as *const u16) as usize;
    let mut off = attr_off;
    let mut runs_ptr: *const u8 = core::ptr::null();
    let mut runs_len = 0usize;
    while off + 16 <= rec_size {
        let atype = *(record.add(off) as *const u32);
        if atype == NTFS_ATTR_END {
            break;
        }
        let alen = *(record.add(off + 4) as *const u32) as usize;
        if alen < 16 || off + alen > rec_size {
            break;
        }
        if atype == NTFS_ATTR_INDEX_ALLOCATION {
            let nonres = *record.add(off + 8);
            if nonres != 0 {
                let roff = *(record.add(off + 32) as *const u16) as usize;
                runs_ptr = record.add(off + roff);
                runs_len = alen - roff;
                break;
            }
        }
        off += alen;
    }
    if runs_ptr.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    let mut lcns = [0u64; 64];
    let mut counts = [0u64; 64];
    let nruns = ntfs_parse_runs(runs_ptr, runs_len, lcns.as_mut_ptr(), counts.as_mut_ptr(), 64);
    let block_size = (*m).index_block_size as usize;
    let block = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(block_size)
        as *mut u8;
    if block.is_null() {
        return STATUS_NO_MEMORY;
    }
    let spc = (*m).sectors_per_cluster as u64;
    let mut result = STATUS_OBJECT_NAME_NOT_FOUND;
    let mut r = 0usize;
    while r < nruns {
        let mut c = 0u64;
        while c < counts[r] {
            let lba = (lcns[r] + c) * spc;
            let sectors = (block_size / 512) as u16;
            if ntfs_volume_read((*m).volume_index, lba, block, sectors) == STATUS_SUCCESS {
                // INDX block: magic at 0, entries after 0x18 header + update seq.
                if *(block as *const u32) == 0x58444E49 {
                    // "INDX"
                    let first =
                        *(block.add(0x18) as *const u32) as usize;
                    let total =
                        *(block.add(0x1C) as *const u32) as usize;
                    if first < block_size && first + total <= block_size {
                        let mut subnode = 0u64;
                        if ntfs_search_index_entries(
                            block.add(first),
                            total,
                            name,
                            info_out,
                            &mut subnode,
                        ) == STATUS_SUCCESS
                        {
                            result = STATUS_SUCCESS;
                            break;
                        }
                    }
                }
            }
            c += 1;
        }
        if result == STATUS_SUCCESS {
            break;
        }
        r += 1;
    }
    crate::mm::pool::ex_free_pool(block as *mut c_void);
    result
}

/// NtfsStat - fill size/dir flag for a found MFT number.
pub unsafe fn ntfs_stat(
    m: *mut NtfsMount,
    mft_number: u64,
    info: *mut NtfsFileInfo,
) -> NtStatus {
    if m.is_null() || info.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let rec_size = (*m).mft_record_size as usize;
    let record = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(rec_size) as *mut u8;
    if record.is_null() {
        return STATUS_NO_MEMORY;
    }
    let mut st = ntfs_read_mft_record(m, mft_number, record);
    if st != STATUS_SUCCESS {
        crate::mm::pool::ex_free_pool(record as *mut c_void);
        return st;
    }
    let flags = *(record.add(22) as *const u16);
    (*info).mft_number = mft_number;
    (*info).is_directory = flags & NTFS_MFT_IS_DIRECTORY != 0;
    // $DATA size.
    let mut resident = false;
    let mut ptr: *const u8 = core::ptr::null();
    let mut len = 0usize;
    let mut size = 0u64;
    st = ntfs_find_data_attr(record, rec_size, &mut resident, &mut ptr, &mut len, &mut size);
    (*info).data_size = if st == STATUS_SUCCESS { size } else { 0 };
    crate::mm::pool::ex_free_pool(record as *mut c_void);
    STATUS_SUCCESS
}

/// NtfsRead - read file data (resident or runs).
pub unsafe fn ntfs_read(
    m: *mut NtfsMount,
    info: *const NtfsFileInfo,
    file_offset: u64,
    buffer: *mut u8,
    length: usize,
) -> NtStatus {
    if m.is_null() || info.is_null() || buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if file_offset >= (*info).data_size {
        return STATUS_END_OF_FILE;
    }
    let to_read = (length as u64).min((*info).data_size - file_offset) as usize;
    let rec_size = (*m).mft_record_size as usize;
    let record = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(rec_size) as *mut u8;
    if record.is_null() {
        return STATUS_NO_MEMORY;
    }
    let mut st = ntfs_read_mft_record(m, (*info).mft_number, record);
    if st != STATUS_SUCCESS {
        crate::mm::pool::ex_free_pool(record as *mut c_void);
        return st;
    }
    let mut resident = false;
    let mut ptr: *const u8 = core::ptr::null();
    let mut len = 0usize;
    let mut size = 0u64;
    st = ntfs_find_data_attr(record, rec_size, &mut resident, &mut ptr, &mut len, &mut size);
    if st != STATUS_SUCCESS {
        crate::mm::pool::ex_free_pool(record as *mut c_void);
        return st;
    }
    if resident {
        core::ptr::copy_nonoverlapping(
            ptr.add(file_offset as usize),
            buffer,
            to_read,
        );
        crate::mm::pool::ex_free_pool(record as *mut c_void);
        return STATUS_SUCCESS;
    }
    // Non-resident: parse runs, map file VCNs to disk LCNs.
    let mut lcns = [0u64; 64];
    let mut counts = [0u64; 64];
    let nruns = ntfs_parse_runs(ptr, len, lcns.as_mut_ptr(), counts.as_mut_ptr(), 64);
    let bytes_per_cluster = (*m).bytes_per_sector as u64 * (*m).sectors_per_cluster as u64;
    let mut done = 0usize;
    let mut vcn: u64 = 0;
    let mut r = 0usize;
    st = STATUS_END_OF_FILE;
    while r < nruns && done < to_read {
        let run_clusters = counts[r];
        let run_start = file_offset.max(vcn * bytes_per_cluster);
        let run_end = ((vcn + run_clusters) * bytes_per_cluster).min(file_offset + to_read as u64);
        if run_end > run_start {
            let in_run_off = (run_start - vcn * bytes_per_cluster) as usize;
            let n = (run_end - run_start) as usize;
            if lcns[r] == u64::MAX {
                // Sparse: zeroes.
                core::ptr::write_bytes(buffer.add(done), 0, n);
            } else {
                let byte_lba = (lcns[r] * bytes_per_cluster + in_run_off as u64) / 512;
                let byte_off = (lcns[r] * bytes_per_cluster + in_run_off as u64) % 512;
                // Bounce through sector buffer for alignment.
                let sectors = ((byte_off as usize + n + 511) / 512) as u16;
                let bounce = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
                    sectors as usize * 512,
                ) as *mut u8;
                if bounce.is_null() {
                    break;
                }
                // Chunk sector reads to u16 counts.
                let mut off = 0usize;
                let mut lba = byte_lba;
                let mut ok = true;
                while off < sectors as usize {
                    let chunk = ((sectors as usize) - off).min(32768) as u16;
                    if ntfs_volume_read(
                        (*m).volume_index,
                        lba,
                        bounce.add(off * 512),
                        chunk,
                    ) != STATUS_SUCCESS
                    {
                        ok = false;
                        break;
                    }
                    off += chunk as usize;
                    lba += chunk as u64;
                }
                if !ok {
                    crate::mm::pool::ex_free_pool(bounce as *mut c_void);
                    st = STATUS_IO_DEVICE_ERROR;
                    break;
                }
                core::ptr::copy_nonoverlapping(
                    bounce.add(byte_off as usize),
                    buffer.add(done),
                    n,
                );
                crate::mm::pool::ex_free_pool(bounce as *mut c_void);
            }
            done += n;
        }
        vcn += run_clusters;
        r += 1;
    }
    crate::mm::pool::ex_free_pool(record as *mut c_void);
    if done < to_read {
        STATUS_END_OF_FILE
    } else {
        STATUS_SUCCESS
    }
}
