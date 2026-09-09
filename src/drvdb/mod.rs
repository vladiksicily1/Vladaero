/// DrvDb - PnP Driver Database
use crate::types::*;

pub struct DrvDbDatabase {
    pub key_handle: Handle,
    pub database_id: [u16; 64],
    pub initialized: bool,
}

pub unsafe fn drvdb_initialize() -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn drvdb_open_database(
    key_handle: Handle,
    database_id: *const u16,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn drvdb_get_database_id(_db: *mut DrvDbDatabase) -> *const u16 {
    core::ptr::null()
}

pub unsafe fn drvdb_get_driver_package(
    _db: *mut DrvDbDatabase,
    _driver_id: *const u16,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 DrvDb: driver packages, HW-ID match, ranking
// ============================================================

use core::ffi::c_void;

pub const DRVDB_MAX_PACKAGES: usize = 256;

pub const DRVDB_SIGNATURE_SIGNED: u32 = 0;
pub const DRVDB_SIGNATURE_UNSIGNED: u32 = 1;

pub const DRVDB_RANK_SIGNATURE_MASK: u32 = 0xFF000000;
pub const DRVDB_RANK_FEATURE_MASK: u32 = 0x00FF0000;
pub const DRVDB_RANK_IDENTIFIER_MASK: u32 = 0x0000FFFF;

#[repr(C)]
pub struct DrvDbPackage {
    pub driver_id: [u16; 64],
    pub hardware_id: [u16; 128],
    pub compatible_ids: [u16; 256],
    pub service_name: [u16; 64],
    pub inf_path: [u16; 128],
    pub driver_version: u64,
    pub signature_score: u32,
    pub feature_score: u32,
    pub identifier_score: u32,
    pub rank: u32,
    pub is_signed: bool,
    pub next: *mut DrvDbPackage,
}

static mut DRVDB_PACKAGES: *mut DrvDbPackage = core::ptr::null_mut();
static DRVDB_PACKAGE_COUNT: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(0);

unsafe fn drvdbp_copy_str(dst: *mut u16, dst_len: usize, src: *const u16) {
    if src.is_null() {
        return;
    }
    let mut i = 0;
    while i + 1 < dst_len && *src.add(i) != 0 {
        *dst.add(i) = *src.add(i);
        i += 1;
    }
    *dst.add(i) = 0;
}

unsafe fn drvdbp_match_id(pattern: *const u16, pattern_len: usize, id: *const u16) -> bool {
    // Case-insensitive compare of a single HW/COMPAT id.
    let mut i = 0;
    loop {
        let a = if i < pattern_len {
            *pattern.add(i)
        } else {
            0
        };
        let b = *id.add(i);
        if a == 0 && b == 0 {
            return true;
        }
        if a == 0 || b == 0 {
            return false;
        }
        if crate::nls::nls_upcase_full(a) != crate::nls::nls_upcase_full(b) {
            return false;
        }
        i += 1;
    }
}

/// DrvdbAddPackage - register a driver package (from an INF).
pub unsafe fn drvdb_add_package(
    driver_id: *const u16,
    hardware_id: *const u16,
    service_name: *const u16,
    inf_path: *const u16,
    driver_version: u64,
    is_signed: bool,
) -> NtStatus {
    if driver_id.is_null() || hardware_id.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if DRVDB_PACKAGE_COUNT.load(core::sync::atomic::Ordering::Relaxed) >= DRVDB_MAX_PACKAGES as u32
    {
        return STATUS_INSUFFICIENT_RESOURCES;
    }
    let p = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<DrvDbPackage>(),
    ) as *mut DrvDbPackage;
    if p.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(p as *mut u8, 0, core::mem::size_of::<DrvDbPackage>());
    drvdbp_copy_str((*p).driver_id.as_mut_ptr(), 64, driver_id);
    drvdbp_copy_str((*p).hardware_id.as_mut_ptr(), 128, hardware_id);
    drvdbp_copy_str((*p).service_name.as_mut_ptr(), 64, service_name);
    drvdbp_copy_str((*p).inf_path.as_mut_ptr(), 128, inf_path);
    (*p).driver_version = driver_version;
    (*p).is_signed = is_signed;
    (*p).signature_score = if is_signed { 0 } else { 0x80 };
    (*p).feature_score = 0;
    (*p).next = DRVDB_PACKAGES;
    DRVDB_PACKAGES = p;
    DRVDB_PACKAGE_COUNT.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    STATUS_SUCCESS
}

/// DrvdbSelectBestDriver - Windows driver ranking for a device.
///
/// rank = (signature << 24) | (feature << 16) | identifier, lower wins.
/// Exact HW-ID match beats compatible-ID match; signed beats unsigned;
/// newer version wins ties.
pub unsafe fn drvdb_select_best_driver(
    device_hw_id: *const u16,
    best_out: *mut *mut DrvDbPackage,
) -> NtStatus {
    if device_hw_id.is_null() || best_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut best: *mut DrvDbPackage = core::ptr::null_mut();
    let mut best_rank = u32::MAX;
    let mut best_version = 0u64;
    let mut cur = DRVDB_PACKAGES;
    while !cur.is_null() {
        // Identifier score: 0 = exact HW match, 1 = compat match, MAX = none.
        let mut ident = u32::MAX;
        if drvdbp_match_id(
            (*cur).hardware_id.as_ptr(),
            128,
            device_hw_id,
        ) {
            ident = 0;
        } else {
            // Walk ;-separated compatible ID list.
            let mut off = 0usize;
            while off < 256 && (*cur).compatible_ids[off] != 0 {
                if drvdbp_match_id(
                    (*cur).compatible_ids.as_ptr().add(off),
                    256 - off,
                    device_hw_id,
                ) {
                    ident = 1;
                    break;
                }
                while off < 256
                    && (*cur).compatible_ids[off] != 0
                    && (*cur).compatible_ids[off] != b';' as u16
                {
                    off += 1;
                }
                if off < 256 && (*cur).compatible_ids[off] == b';' as u16 {
                    off += 1;
                }
            }
        }
        if ident != u32::MAX {
            (*cur).identifier_score = ident;
            (*cur).rank = ((*cur).signature_score << 24)
                | ((*cur).feature_score << 16)
                | ident;
            if (*cur).rank < best_rank
                || ((*cur).rank == best_rank && (*cur).driver_version > best_version)
            {
                best_rank = (*cur).rank;
                best_version = (*cur).driver_version;
                best = cur;
            }
        }
        cur = (*cur).next;
    }
    if best.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    *best_out = best;
    STATUS_SUCCESS
}

/// DrvdbQueryPackage - look up a package by driver ID.
pub unsafe fn drvdb_query_package(
    driver_id: *const u16,
    pkg_out: *mut *mut DrvDbPackage,
) -> NtStatus {
    if driver_id.is_null() || pkg_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut cur = DRVDB_PACKAGES;
    while !cur.is_null() {
        if drvdbp_match_id((*cur).driver_id.as_ptr(), 64, driver_id) {
            *pkg_out = cur;
            return STATUS_SUCCESS;
        }
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}
