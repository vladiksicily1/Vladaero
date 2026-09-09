/// Kse - Kernel Shimming Engine (Kse/Ksep)
use crate::types::*;

pub struct KseShim {
    pub shim_id: [u16; 64],
    pub target: [u16; 64],
    pub flags: u32,
    pub active: bool,
    pub next: *mut KseShim,
}

static mut SHIM_LIST: *mut KseShim = core::ptr::null_mut();

pub unsafe fn kse_initialize() -> NtStatus {
    SHIM_LIST = core::ptr::null_mut();
    STATUS_SUCCESS
}

pub unsafe fn kse_lookup_shim(
    target: *const u16,
) -> *mut KseShim {
    let mut cur = SHIM_LIST;
    while !cur.is_null() {
        if (*cur).target == *core::slice::from_raw_parts(target, 32) {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

pub unsafe fn kse_register_shim(
    shim_id: *const u16,
    target: *const u16,
    flags: u32,
) -> NtStatus {
    let shim = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<KseShim>()) as *mut KseShim;
    if shim.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::copy_nonoverlapping(shim_id, (*shim).shim_id.as_mut_ptr(), 32);
    core::ptr::copy_nonoverlapping(target, (*shim).target.as_mut_ptr(), 32);
    (*shim).flags = flags;
    (*shim).active = true;
    (*shim).next = SHIM_LIST;
    SHIM_LIST = shim;
    STATUS_SUCCESS
}

pub unsafe fn kse_set_shim(
    _shim: *mut KseShim,
    _enable: bool,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 KSE: shim database with version matching (Ksep)
// ============================================================

use core::ffi::c_void;

pub const KSE_MATCH_MAJOR_ONLY: u32 = 0x00000001;
pub const KSE_MATCH_MINOR_ONLY: u32 = 0x00000002;
pub const KSE_MATCH_BUILD_ONLY: u32 = 0x00000004;
pub const KSE_MATCH_EXACT: u32 = 0x00000008;

pub const KSE_HOOK_IMPORT_REDIRECT: u32 = 1;
pub const KSE_HOOK_API_PATCH: u32 = 2;
pub const KSE_HOOK_DRIVER_INIT: u32 = 3;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KseVersionRange {
    pub min_major: u16,
    pub min_minor: u16,
    pub min_build: u16,
    pub max_major: u16,
    pub max_minor: u16,
    pub max_build: u16,
    pub match_flags: u32,
}

#[repr(C)]
pub struct KseHook {
    pub hook_type: u32,
    pub module_name: [u16; 64],
    pub function_name: [u8; 64],
    pub replacement: *mut c_void,
    pub original: *mut c_void,
    pub active: bool,
    pub next: *mut KseHook,
}

#[repr(C)]
pub struct KseShimFull {
    pub shim_id: [u16; 64],
    pub target_driver: [u16; 64],
    pub version_range: KseVersionRange,
    pub flags: u32,
    pub active: bool,
    pub hooks: *mut KseHook,
    pub next: *mut KseShimFull,
}

static mut KSE_SHIM_DB: *mut KseShimFull = core::ptr::null_mut();

unsafe fn ksep_version_in_range(
    major: u16,
    minor: u16,
    build: u16,
    range: *const KseVersionRange,
) -> bool {
    if range.is_null() {
        return true;
    }
    let r = &*range;
    if r.match_flags & KSE_MATCH_EXACT != 0 {
        return major == r.min_major && minor == r.min_minor && build == r.min_build;
    }
    // Major must match unless MAJOR_ONLY cleared... simplified range check.
    let above_min = (major > r.min_major)
        || (major == r.min_major
            && (minor > r.min_minor || (minor == r.min_minor && build >= r.min_build)));
    let below_max = (major < r.max_major)
        || (major == r.max_major
            && (minor < r.max_minor || (minor == r.max_minor && build <= r.max_build)));
    above_min && below_max
}

/// KseRegisterShimFull - register a shim with a driver version range.
pub unsafe fn kse_register_shim_full(
    shim_id: *const u16,
    target_driver: *const u16,
    range: *const KseVersionRange,
    flags: u32,
) -> NtStatus {
    if shim_id.is_null() || target_driver.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let s = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<KseShimFull>(),
    ) as *mut KseShimFull;
    if s.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(s as *mut u8, 0, core::mem::size_of::<KseShimFull>());
    let mut i = 0;
    while i < 63 && *shim_id.add(i) != 0 {
        (*s).shim_id[i] = *shim_id.add(i);
        i += 1;
    }
    i = 0;
    while i < 63 && *target_driver.add(i) != 0 {
        (*s).target_driver[i] = *target_driver.add(i);
        i += 1;
    }
    if !range.is_null() {
        (*s).version_range = *range;
    } else {
        (*s).version_range = KseVersionRange {
            min_major: 0,
            min_minor: 0,
            min_build: 0,
            max_major: 0xFFFF,
            max_minor: 0xFFFF,
            max_build: 0xFFFF,
            match_flags: 0,
        };
    }
    (*s).flags = flags;
    (*s).active = true;
    (*s).next = KSE_SHIM_DB;
    KSE_SHIM_DB = s;
    STATUS_SUCCESS
}

/// KseFindShim - find an active shim for a driver+version.
pub unsafe fn kse_find_shim(
    target_driver: *const u16,
    major: u16,
    minor: u16,
    build: u16,
) -> *mut KseShimFull {
    if target_driver.is_null() {
        return core::ptr::null_mut();
    }
    let mut cur = KSE_SHIM_DB;
    while !cur.is_null() {
        // Compare target name (case-insensitive).
        let mut i = 0;
        let mut name_match = true;
        loop {
            let a = if i < 64 { (*cur).target_driver[i] } else { 0 };
            let b = *target_driver.add(i);
            if a == 0 && b == 0 {
                break;
            }
            if crate::nls::nls_upcase_full(a) != crate::nls::nls_upcase_full(b) {
                name_match = false;
                break;
            }
            i += 1;
            if i >= 64 {
                break;
            }
        }
        if name_match
            && (*cur).active
            && ksep_version_in_range(major, minor, build, &(*cur).version_range)
        {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// KseAddHook - attach an import redirect / API patch to a shim.
pub unsafe fn kse_add_hook(
    shim: *mut KseShimFull,
    hook_type: u32,
    module_name: *const u16,
    function_name: *const u8,
    replacement: *mut c_void,
    original_out: *mut *mut c_void,
) -> NtStatus {
    if shim.is_null() || function_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let h = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<KseHook>(),
    ) as *mut KseHook;
    if h.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(h as *mut u8, 0, core::mem::size_of::<KseHook>());
    (*h).hook_type = hook_type;
    if !module_name.is_null() {
        let mut i = 0;
        while i < 63 && *module_name.add(i) != 0 {
            (*h).module_name[i] = *module_name.add(i);
            i += 1;
        }
    }
    let mut j = 0;
    while j < 63 && *function_name.add(j) != 0 {
        (*h).function_name[j] = *function_name.add(j);
        j += 1;
    }
    (*h).replacement = replacement;
    (*h).active = true;
    (*h).next = (*shim).hooks;
    (*shim).hooks = h;
    if !original_out.is_null() {
        *original_out = (*h).original;
    }
    STATUS_SUCCESS
}

/// KseResolveHook - resolve a hooked import for a driver.
pub unsafe fn kse_resolve_hook(
    target_driver: *const u16,
    major: u16,
    minor: u16,
    build: u16,
    module_name: *const u16,
    function_name: *const u8,
) -> *mut c_void {
    let shim = kse_find_shim(target_driver, major, minor, build);
    if shim.is_null() || function_name.is_null() {
        return core::ptr::null_mut();
    }
    let mut h = (*shim).hooks;
    while !h.is_null() {
        if (*h).active {
            // Compare function name.
            let mut i = 0;
            let mut fn_match = true;
            loop {
                let a = if i < 64 { (*h).function_name[i] } else { 0 };
                let b = *function_name.add(i);
                if a == 0 && b == 0 {
                    break;
                }
                if a != b {
                    fn_match = false;
                    break;
                }
                i += 1;
                if i >= 64 {
                    break;
                }
            }
            if fn_match && !(*h).replacement.is_null() {
                return (*h).replacement;
            }
        }
        h = (*h).next;
    }
    core::ptr::null_mut()
}
