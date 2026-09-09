//! # Memory Descriptor List (MDL) Operations
//!
//! MmCreateMdl, MmBuildMdlForNonPagedPool, MmProbeAndLockPages,
//! MmUnlockPages, MmGetSystemAddressForMdlSafe - matching ntoskrnl.exe.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU32, Ordering};

use crate::types::*;
use super::{
    MmMdl, MmPteFlags, MmProtectionMask2, MmPte, SpinLock, PoolTag, TAG_MMDL,
    MDL_PAGES_LOCKED, MDL_MAPPED_TO_SYSTEM_VA,
    PAGE_SHIFT, PAGE_SIZE_X64,
    mi_get_pfn_element, mm_warn, mm_dbg, mm_err,
};

// ============================================================
// MDL flags
// ============================================================

pub const MDL_ALLOCATE_MUST_SUCCEED: i32 = 0x1000;
pub const MDL_SOURCE_IS_NONPAGED: i32 = 0x0002;

// ============================================================
// MmCreateMdl
// ============================================================

pub fn mm_create_mdl(
    base: *mut c_void,
    length: usize,
) -> *mut MmMdl {
    let page_count = (length + PAGE_SIZE_X64 - 1) / PAGE_SIZE_X64;
    let mdl_size = core::mem::size_of::<MmMdl>() + page_count * core::mem::size_of::<u64>();

    let mdl = super::pool::mm_allocate_pool_nonpaged(mdl_size, TAG_MMDL) as *mut MmMdl;
    if mdl.is_null() {
        mm_err!("MmCreateMdl: allocation failed size={}", mdl_size);
        return core::ptr::null_mut();
    }

    unsafe {
        core::ptr::write_bytes(mdl as *mut u8, 0, mdl_size);
        (*mdl).next = core::ptr::null_mut();
        (*mdl).size = core::mem::size_of::<MmMdl>() as i32;
        (*mdl).mdl_flags = 0;
        (*mdl).process = core::ptr::null_mut();
        (*mdl).start_va = base;
        (*mdl).byte_offset = if !base.is_null() {
            (base as usize & (PAGE_SIZE_X64 - 1)) as u32
        } else {
            0
        };
        (*mdl).byte_count = length as u64;
        (*mdl).mapped_system_va = core::ptr::null_mut();
        (*mdl).start_weapon = core::ptr::null_mut();
    }

    mm_trace!("MmCreateMdl: created MDL {:p} base={:p} length={}", mdl, base, length);
    mdl
}

// ============================================================
// MmCreateMdlForNonPagedPool (alias for build)
// ============================================================

pub fn mm_create_mdl_for_non_paged_pool(
    base: *mut c_void,
    length: usize,
) -> *mut MmMdl {
    let mdl = mm_create_mdl(base, length);
    if !mdl.is_null() {
        unsafe {
            (*mdl).mdl_flags |= MDL_SOURCE_IS_NONPAGED;
        }
    }
    mdl
}

// ============================================================
// MmProbeAndLockPages
// ============================================================

pub fn mm_probe_and_lock_pages(
    mdl: *mut MmMdl,
    access_mode: KprocessorMode,
    lock_operation: u32,
) -> NtStatus {
    if mdl.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    unsafe {
        let base = (*mdl).start_va as u64;
        let size = (*mdl).byte_count as usize;
        let offset = (*mdl).byte_offset as usize;
        let page_count = (size + PAGE_SIZE_X64 - 1) / PAGE_SIZE_X64;

        let mut pages_locked: usize = 0;

        for i in 0..page_count {
            let va = base + (i as u64) * (PAGE_SIZE_X64 as u64) + offset as u64;
            let pte_addr = super::pte::mi_get_pte_address(va);
            let pte = &*(pte_addr as *const MmPte);

            if !pte.flags.contains(MmPteFlags::VALID) {
                mm_warn!("MmProbeAndLockPages: page not present at {:#x}", va);
                break;
            }

            if access_mode == KprocessorMode::UserMode {
                if !pte.flags.contains(MmPteFlags::OWNER_USER) {
                    return STATUS_ACCESS_DENIED;
                }
            }

            let phys = (pte.page_frame_number << PAGE_SHIFT) | (va & 0xFFF);

            let mdl_page = (mdl as *mut u8).add(core::mem::size_of::<MmMdl>()) as *mut u64;
            *mdl_page.add(i) = phys;

            // Pin the page
            let pfn_num = pte.page_frame_number as usize;
            if pfn_num < super::MmPfnDatabaseLength.load(Ordering::Relaxed) {
                let pfn = mi_get_pfn_element(pfn_num);
                pfn.flags2 |= super::pfn::MMPFN_LOCKED;
            }

            pages_locked += 1;
        }

        (*mdl).mdl_flags |= MDL_PAGES_LOCKED;
        mm_trace!("MmProbeAndLockPages: locked {} pages", pages_locked);
    }

    STATUS_SUCCESS
}

// ============================================================
// MmProbeAndLockProcessPages
// ============================================================

pub fn mm_probe_and_lock_process_pages(
    mdl: *mut MmMdl,
    process: *mut c_void,
    access_mode: KprocessorMode,
    lock_operation: u32,
) -> NtStatus {
    if !mdl.is_null() {
        unsafe { (*mdl).process = process; }
    }
    mm_probe_and_lock_pages(mdl, access_mode, lock_operation)
}

// ============================================================
// MmUnlockPages
// ============================================================

pub fn mm_unlock_pages(mdl: *mut MmMdl) {
    if mdl.is_null() {
        return;
    }

    unsafe {
        let page_count = (*mdl).byte_count as usize / PAGE_SIZE_X64;
        let mdl_page = (mdl as *mut u8).add(core::mem::size_of::<MmMdl>()) as *mut u64;

        for i in 0..page_count {
            let phys = *mdl_page.add(i);
            if phys == 0 {
                continue;
            }

            let pfn_num = (phys >> PAGE_SHIFT) as usize;
            if pfn_num < super::MmPfnDatabaseLength.load(Ordering::Relaxed) {
                let pfn = mi_get_pfn_element(pfn_num);
                pfn.flags2 &= !super::pfn::MMPFN_LOCKED;
                super::pfn::mi_decrement_reference_count(pfn);
            }

            *mdl_page.add(i) = 0;
        }

        (*mdl).mdl_flags &= !MDL_PAGES_LOCKED;
    }

    mm_trace!("MmUnlockPages: unlocked MDL pages");
}

// ============================================================
// MmGetSystemAddressForMdlSafe
// ============================================================

pub fn mm_get_system_address_for_mdl_safe(
    mdl: *mut MmMdl,
    highest_address: u64,
) -> *mut c_void {
    if mdl.is_null() {
        return core::ptr::null_mut();
    }

    unsafe {
        if !(*mdl).mapped_system_va.is_null() {
            return (*mdl).mapped_system_va;
        }

        if (*mdl).start_va.is_null() {
            return core::ptr::null_mut();
        }

        let size = (*mdl).byte_count as usize;
        let system_addr = super::phys::mm_allocate_contiguous_memory(size, highest_address);

        if system_addr.is_null() {
            mm_warn!("MmGetSystemAddressForMdlSafe: allocation failed");
            return core::ptr::null_mut();
        }

        // Copy data from locked pages
        let page_count = size / PAGE_SIZE_X64;
        let mdl_page = (mdl as *mut u8).add(core::mem::size_of::<MmMdl>()) as *mut u64;

        for i in 0..page_count {
            let phys = *mdl_page.add(i);
            if phys == 0 {
                continue;
            }

            let dst = (system_addr as u64) + (i as u64) * (PAGE_SIZE_X64 as u64);
            super::pte::mi_create_pte(dst, phys, MmProtectionMask2::READWRITE, 0);
        }

        (*mdl).mapped_system_va = system_addr;
        (*mdl).mdl_flags |= MDL_MAPPED_TO_SYSTEM_VA;

        mm_trace!("MmGetSystemAddressForMdlSafe: mapped at {:p}", system_addr);
        system_addr
    }
}

// ============================================================
// MmGetSystemAddressForMdl
// ============================================================

pub fn mm_get_system_address_for_mdl(mdl: *mut MmMdl) -> *mut c_void {
    mm_get_system_address_for_mdl_safe(mdl, 0xFFFF_FFFF_FFFF_FFFF)
}

// ============================================================
// MmBuildMdlForNonPagedPool
// ============================================================

pub fn mm_build_mdl_for_non_paged_pool(
    base: *mut c_void,
    size: usize,
) -> *mut MmMdl {
    let mdl = mm_create_mdl(base, size);
    if mdl.is_null() {
        return core::ptr::null_mut();
    }

    unsafe {
        (*mdl).mdl_flags |= MDL_SOURCE_IS_NONPAGED;

        let page_count = (size + PAGE_SIZE_X64 - 1) / PAGE_SIZE_X64;
        let mdl_page = (mdl as *mut u8).add(core::mem::size_of::<MmMdl>()) as *mut u64;

        for i in 0..page_count {
            let va = base as u64 + (i as u64) * (PAGE_SIZE_X64 as u64);
            let phys = super::mm_get_physical_address(va);
            *mdl_page.add(i) = phys;
        }
    }

    mm_trace!("MmBuildMdlForNonPagedPool: built MDL for {:p} size={}", base, size);
    mdl
}

// ============================================================
// MmBuildMdlForNonPagedPoolSpecifyCacheType
// ============================================================

pub fn mm_build_mdl_for_non_paged_pool_specify_cache_type(
    base: *mut c_void,
    size: usize,
    cache_type: MemoryCachingType,
) -> *mut MmMdl {
    mm_build_mdl_for_non_paged_pool(base, size)
}

// ============================================================
// MmMdl pages operations
// ============================================================

pub fn mm_mdl_get_page_count(mdl: *const MmMdl) -> usize {
    if mdl.is_null() {
        return 0;
    }
    unsafe { (*mdl).byte_count as usize / PAGE_SIZE_X64 }
}

pub fn mm_mdl_get_page_phys(mdl: *const MmMdl, index: usize) -> u64 {
    if mdl.is_null() {
        return 0;
    }
    unsafe {
        let mdl_page = (mdl as *const u8).add(core::mem::size_of::<MmMdl>()) as *const u64;
        *mdl_page.add(index)
    }
}

pub fn mm_mdl_lock_pages(mdl: *mut MmMdl) -> NtStatus {
    mm_probe_and_lock_pages(mdl, KprocessorMode::KernelMode, 0)
}

pub fn mm_mdl_unlock_pages(mdl: *mut MmMdl) {
    mm_unlock_pages(mdl);
}

// ============================================================
// MmFreeMdl
// ============================================================

pub fn mm_free_mdl(mdl: *mut MmMdl) {
    if mdl.is_null() {
        return;
    }

    unsafe {
        if (*mdl).mdl_flags & MDL_PAGES_LOCKED != 0 {
            mm_unlock_pages(mdl);
        }

        if (*mdl).mdl_flags & MDL_MAPPED_TO_SYSTEM_VA != 0 {
            if !(*mdl).mapped_system_va.is_null() {
                super::phys::mm_free_contiguous_memory(
                    (*mdl).mapped_system_va,
                    (*mdl).byte_count as usize,
                );
                (*mdl).mapped_system_va = core::ptr::null_mut();
                (*mdl).mdl_flags &= !MDL_MAPPED_TO_SYSTEM_VA;
            }
        }
    }

    super::pool::mm_free_pool(mdl as *mut c_void, TAG_MMDL);
    mm_trace!("MmFreeMdl: freed MDL {:p}", mdl);
}

// ============================================================
// MmAllocateIndependentPages
// ============================================================

pub fn mm_allocate_independent_pages(
    number_of_pages: usize,
    node: u32,
) -> *mut c_void {
    let size = number_of_pages * PAGE_SIZE_X64;
    let ptr = super::phys::mm_allocate_contiguous_memory(size, 0xFFFF_FFFF_FFFF_FFFF);

    if ptr.is_null() {
        return core::ptr::null_mut();
    }

    unsafe { core::ptr::write_bytes(ptr, 0, size); }
    ptr
}

// ============================================================
// MmFreeIndependentPages
// ============================================================

pub fn mm_free_independent_pages(base: *mut c_void, number_of_pages: usize) {
    let size = number_of_pages * PAGE_SIZE_X64;
    super::phys::mm_free_contiguous_memory(base, size);
}

// ============================================================
// STATUS_ACCESS_DENIED (if not in types)
// ============================================================

const STATUS_ACCESS_DENIED: NtStatus = 0xC0000022;
