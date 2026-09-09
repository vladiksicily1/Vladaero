//! # Page Fault Handler
//!
//! Dispatches hardware page faults: demand-zero, page-file reads,
//! zero-page, copy-on-write, and validates access rights.
//! Mirrors MiDispatchFault and related routines in ntoskrnl.exe.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU32, AtomicU64, Ordering};

use crate::types::*;
use super::{
    MmPteFlags, MmProtectionMask2, MmPte, MmPfn, ListEntry, SpinLock,
    PAGE_SHIFT, PAGE_SIZE_X64, PAGE_MASK_X64,
    mi_get_pfn_element, mi_get_pte_address, mi_locate_vad, mi_locate_vad_hint,
    mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Page fault error codes
// ============================================================

pub const PF_ERROR_OK: u32 = 0;
pub const PF_ERROR_ACCESS_VIOLATION: u32 = 1;
pub const PF_ERROR_GUARD_PAGE: u32 = 2;
pub const PF_ERROR_PAGE_NOT_PRESENT: u32 = 3;
pub const PF_ERROR_WRITE_FAULT: u32 = 4;
pub const PF_ERROR_EXECUTE_FAULT: u32 = 5;
pub const PF_ERROR_IN_PAGE_ERROR: u32 = 6;
pub const PF_ERROR_COPY_ON_WRITE: u32 = 7;
pub const PF_ERROR_DEMAND_ZERO: u32 = 8;
pub const PF_ERROR_PAGE_FILE_READ: u32 = 9;

pub const MM_ZERO_PAGE_PFN: u64 = 0;

// ============================================================
// Fault result
// ============================================================

#[derive(Debug, Clone, Copy)]
pub struct MiFaultResult {
    pub status: NtStatus,
    pub pf_error: u32,
    pub bytes_faulted: u64,
}

impl MiFaultResult {
    pub fn success() -> Self {
        Self {
            status: STATUS_SUCCESS,
            pf_error: PF_ERROR_OK,
            bytes_faulted: 0,
        }
    }

    pub fn error(status: NtStatus, pf_error: u32) -> Self {
        Self {
            status,
            pf_error,
            bytes_faulted: 0,
        }
    }
}

// ============================================================
// MiDispatchFault (main entry point)
// ============================================================

pub fn mi_dispatch_fault(
    faulting_address: u64,
    pte: *mut MmPte,
    is_write: bool,
    process: *mut c_void,
    user_address: bool,
) -> MiFaultResult {
    if pte.is_null() {
        return MiFaultResult::error(STATUS_ACCESS_VIOLATION, PF_ERROR_PAGE_NOT_PRESENT);
    }

    let pte_ref = unsafe { &*pte };

    // Guard page fault
    if pte_ref.flags.contains(MmPteFlags::GUARD) {
        return Mi_dispatch_guard_page_fault(faulting_address, pte_ref, user_address);
    }

    // Page not present
    if !pte_ref.flags.contains(MmPteFlags::VALID) {
        if pte_ref.page_frame_number == 0 && pte_ref.flags.is_empty() {
            return Mi_dispatch_demand_zero_fault(faulting_address, pte, process);
        }

        if pte_ref.flags.contains(MmPteFlags::PAGE_FILE) {
            return mi_dispatch_page_file_read_fault(faulting_address, pte, process);
        }

        if pte_ref.flags.contains(MmPteFlags::PROTOTYPE) {
            return mi_dispatch_prototype_fault(faulting_address, pte, process);
        }

        // Transition fault
        return mi_dispatch_transition_fault(faulting_address, pte, process);
    }

    // Page is present - check protection
    if is_write && !pte_ref.flags.contains(MmPteFlags::WRITE) {
        if pte_ref.flags.contains(MmPteFlags::COPY_ON_WRITE) {
            return mi_copy_on_write(faulting_address, pte, process);
        }
        return MiFaultResult::error(STATUS_ACCESS_VIOLATION, PF_ERROR_WRITE_FAULT);
    }

    // Clear accessed bit for future aging
    unsafe {
        let pte_mut = &mut *pte;
        pte_mut.flags |= MmPteFlags::ACCESSED;
        core::arch::asm!("invlpg [{}]", in(reg) faulting_address, options(nostack));
    }

    MiFaultResult::success()
}

// ============================================================
// MiDispatchGuardPageFault
// ============================================================

fn Mi_dispatch_guard_page_fault(
    faulting_address: u64,
    pte: &MmPte,
    user_address: bool,
) -> MiFaultResult {
    mm_trace!("MiDispatchGuardPageFault: guard page at {:#x}", faulting_address);

    if user_address {
        // User-mode guard page: return STATUS_GUARD_PAGE_VIOLATION (0x80000001)
        // This is caught by the VEH / SEH
        MiFaultResult::error(0x80000001, PF_ERROR_GUARD_PAGE)
    } else {
        // Kernel-mode guard page: usually a stack guard
        MiFaultResult::error(STATUS_ACCESS_VIOLATION, PF_ERROR_GUARD_PAGE)
    }
}

// ============================================================
// MiResolveDemandZeroFault
// ============================================================

fn Mi_dispatch_demand_zero_fault(
    faulting_address: u64,
    pte: *mut MmPte,
    process: *mut c_void,
) -> MiFaultResult {
    mm_trace!("MiResolveDemandZeroFault: VA {:#x}", faulting_address);

    unsafe {
        let pte_ref = &mut *pte;

        let frame = super::pfn::mi_allocate_pfn().unwrap_or_else(|| {
            mm_warn!("MiResolveDemandZeroFault: no free pages");
            return 0usize;
        });

        if frame == 0 {
            return MiFaultResult::error(STATUS_NO_MEMORY, PF_ERROR_DEMAND_ZERO);
        }

        let pfn = mi_get_pfn_element(frame);
        let phys_addr = (frame as u64) << PAGE_SHIFT;

        // Zero the page
        let virt = 0x0000_0000_0000_1000u64; // temporary mapping
        super::pte::mi_create_pte(virt, phys_addr, MmProtectionMask2::READWRITE, 0);
        core::ptr::write_bytes(virt as *mut u8, 0, PAGE_SIZE_X64);

        pfn.flags2 |= super::pfn::MMPFN_ZEROED | super::pfn::MMPFN_ACTIVE;
        pfn.reference_count_encoded = 1;
        pfn.used_by1.fields0 = 0;

        pte_ref.flags = MmPteFlags::VALID | MmPteFlags::WRITE | MmPteFlags::ACCESSED | MmPteFlags::DIRTY;
        pte_ref.page_frame_number = frame as u64;
        pte_ref.protection = MmProtectionMask2::READWRITE;

        super::pte::ki_invalidate_pte(faulting_address);

        super::MmResidentAvailablePages.fetch_sub(1, Ordering::Relaxed);
    }

    MiFaultResult::success()
}

// ============================================================
// MiResolvePageFileRead
// ============================================================

fn mi_dispatch_page_file_read_fault(
    faulting_address: u64,
    pte: *mut MmPte,
    process: *mut c_void,
) -> MiFaultResult {
    mm_trace!("MiResolvePageFileRead: VA {:#x}", faulting_address);

    unsafe {
        let pte_ref = &mut *pte;

        let frame = super::pfn::mi_allocate_pfn().unwrap_or_else(|| {
            mm_warn!("MiResolvePageFileRead: no free pages");
            return 0usize;
        });

        if frame == 0 {
            return MiFaultResult::error(STATUS_NO_MEMORY, PF_ERROR_PAGE_FILE_READ);
        }

        let pfn = mi_get_pfn_element(frame);
        let page_file_number = (pte_ref.page_frame_number >> 1) & 0x7FFFFFFF;
        let page_file_offset = (pte_ref.page_frame_number & 1) * 8;

        let status = super::pagefile::mm_page_file_read(
            page_file_number as u32,
            page_file_offset,
            (frame as u64) << PAGE_SHIFT,
            PAGE_SIZE_X64,
        );

        if status != STATUS_SUCCESS {
            mm_warn!("MiResolvePageFileRead: page file read failed: {:#x}", status);
            super::pfn::mi_release_pfn(frame, 0);
            return MiFaultResult::error(STATUS_IN_PAGE_ERROR, PF_ERROR_IN_PAGE_ERROR);
        }

        pfn.flags2 |= super::pfn::MMPFN_ACTIVE | super::MMPFN_VALID_PFN_MASK;
        pfn.reference_count_encoded = 1;

        pte_ref.flags = MmPteFlags::VALID | MmPteFlags::WRITE | MmPteFlags::ACCESSED;
        pte_ref.page_frame_number = frame as u64;

        super::pte::ki_invalidate_pte(faulting_address);
    }

    MiFaultResult::success()
}

// ============================================================
// MiResolveZeroPage
// ============================================================

pub fn mi_resolve_zero_page(
    faulting_address: u64,
    pte: *mut MmPte,
    process: *mut c_void,
) -> MiFaultResult {
    mm_trace!("MiResolveZeroPage: VA {:#x}", faulting_address);

    unsafe {
        let pte_ref = &mut *pte;

        let zero_frame = super::pfn::mi_allocate_pfn().unwrap_or_else(|| {
            return 0usize;
        });

        if zero_frame == 0 {
            return MiFaultResult::error(STATUS_NO_MEMORY, PF_ERROR_DEMAND_ZERO);
        }

        let pfn = mi_get_pfn_element(zero_frame);
        let phys_addr = (zero_frame as u64) << PAGE_SHIFT;

        // Map and zero
        let virt = 0x0000_0000_0000_1000u64;
        super::pte::mi_create_pte(virt, phys_addr, MmProtectionMask2::READWRITE, 0);
        core::ptr::write_bytes(virt as *mut u8, 0, PAGE_SIZE_X64);

        pfn.flags2 |= super::pfn::MMPFN_ZEROED | super::pfn::MMPFN_ACTIVE;
        pfn.reference_count_encoded = 1;

        pte_ref.flags = MmPteFlags::VALID | MmPteFlags::WRITE | MmPteFlags::ACCESSED | MmPteFlags::DIRTY;
        pte_ref.page_frame_number = zero_frame as u64;
        pte_ref.protection = MmProtectionMask2::READWRITE;

        super::pte::ki_invalidate_pte(faulting_address);
    }

    MiFaultResult::success()
}

// ============================================================
// MiCopyOnWrite
// ============================================================

fn mi_copy_on_write(
    faulting_address: u64,
    pte: *mut MmPte,
    process: *mut c_void,
) -> MiFaultResult {
    mm_trace!("MiCopyOnWrite: VA {:#x}", faulting_address);

    unsafe {
        let pte_ref = &mut *pte;
        let old_pfn_num = pte_ref.page_frame_number as usize;

        // Allocate new page
        let new_frame = super::pfn::mi_allocate_pfn().unwrap_or_else(|| {
            return 0usize;
        });

        if new_frame == 0 {
            return MiFaultResult::error(STATUS_NO_MEMORY, PF_ERROR_COPY_ON_WRITE);
        }

        // Copy old page contents
        let old_phys = (old_pfn_num as u64) << PAGE_SHIFT;
        let new_phys = (new_frame as u64) << PAGE_SHIFT;

        let src = super::phys::mm_allocate_contiguous_memory(PAGE_SIZE_X64, 0xFFFF_FFFF_FFFF_FFFF);
        if !src.is_null() {
            super::pte::mi_create_pte(src as u64, old_phys, MmProtectionMask2::READONLY, 0);
            let dst = super::phys::mm_allocate_contiguous_memory(PAGE_SIZE_X64, 0xFFFF_FFFF_FFFF_FFFF);
            if !dst.is_null() {
                super::pte::mi_create_pte(dst as u64, new_phys, MmProtectionMask2::READWRITE, 0);
                core::ptr::copy_nonoverlapping(src as *const u8, dst as *mut u8, PAGE_SIZE_X64);
            }
        }

        let new_pfn = mi_get_pfn_element(new_frame);
        new_pfn.flags2 |= super::pfn::MMPFN_ACTIVE | super::MMPFN_VALID_PFN_MASK;
        new_pfn.reference_count_encoded = 1;

        // Decrement old page refcount
        let old_pfn = mi_get_pfn_element(old_pfn_num);
        super::pfn::mi_decrement_reference_count(old_pfn);

        // Update PTE
        pte_ref.flags = MmPteFlags::VALID | MmPteFlags::WRITE | MmPteFlags::ACCESSED;
        pte_ref.page_frame_number = new_frame as u64;
        pte_ref.flags &= !MmPteFlags::COPY_ON_WRITE;

        super::pte::ki_invalidate_pte(faulting_address);
    }

    MiFaultResult::success()
}

// ============================================================
// MiDispatchTransitionFault
// ============================================================

fn mi_dispatch_transition_fault(
    faulting_address: u64,
    pte: *mut MmPte,
    process: *mut c_void,
) -> MiFaultResult {
    mm_trace!("MiDispatchTransitionFault: VA {:#x}", faulting_address);

    unsafe {
        let pte_ref = &mut *pte;
        let pfn_num = pte_ref.page_frame_number as usize;

        if pfn_num == 0 {
            return MiFaultResult::error(STATUS_ACCESS_VIOLATION, PF_ERROR_PAGE_NOT_PRESENT);
        }

        let pfn = mi_get_pfn_element(pfn_num);

        // Move from standby/modified back to active
        pfn.flags2 |= super::pfn::MMPFN_ACTIVE;
        pfn.flags2 &= !(super::pfn::MMPFN_STANDBY | super::pfn::MMPFN_MODIFIED);
        pfn.reference_count_encoded = pfn.reference_count_encoded.saturating_add(1);

        pte_ref.flags = MmPteFlags::VALID | MmPteFlags::WRITE | MmPteFlags::ACCESSED;
        pte_ref.page_frame_number = pfn_num as u64;

        super::pte::ki_invalidate_pte(faulting_address);

        super::MmResidentAvailablePages.fetch_sub(1, Ordering::Relaxed);
    }

    MiFaultResult::success()
}

// ============================================================
// MiDispatchPrototypeFault
// ============================================================

fn mi_dispatch_prototype_fault(
    faulting_address: u64,
    pte: *mut MmPte,
    process: *mut c_void,
) -> MiFaultResult {
    mm_trace!("MiDispatchPrototypeFault: VA {:#x}", faulting_address);
    MiFaultResult::error(STATUS_ACCESS_VIOLATION, PF_ERROR_PAGE_NOT_PRESENT)
}

// ============================================================
// MmAccessCheck (user/kernel access validation)
// ============================================================

pub fn mm_access_check(
    virtual_address: u64,
    access_mask: u32,
    user_mode: bool,
) -> NtStatus {
    if virtual_address < 0x1000 {
        return STATUS_ACCESS_VIOLATION;
    }

    if user_mode && virtual_address > super::MM_HIGHEST_USER_ADDRESS {
        return STATUS_ACCESS_VIOLATION;
    }

    let pte_addr = super::pte::mi_get_pte_address(virtual_address);
    let pte = unsafe { &*(pte_addr as *const MmPte) };

    if !pte.flags.contains(MmPteFlags::VALID) {
        return STATUS_ACCESS_VIOLATION;
    }

    if user_mode && !pte.flags.contains(MmPteFlags::OWNER_USER) {
        return STATUS_ACCESS_VIOLATION;
    }

    if access_mask & 0x2 != 0 && !pte.flags.contains(MmPteFlags::WRITE) {
        return STATUS_ACCESS_VIOLATION;
    }

    STATUS_SUCCESS
}

// ============================================================
// MmPageFault (external entry)
// ============================================================

pub fn mm_page_fault(
    faulting_address: u64,
    is_write: bool,
    user_mode: bool,
    process: *mut c_void,
) -> NtStatus {
    let pte_addr = super::pte::mi_get_pte_address(faulting_address);
    let pte = pte_addr as *mut MmPte;

    let result = mi_dispatch_fault(faulting_address, pte, is_write, process, user_mode);

    if result.status != STATUS_SUCCESS {
        mm_warn!("MmPageFault: fault at {:#x} failed: status={:#x} error={}",
            faulting_address, result.status, result.pf_error);
    }

    result.status
}

pub const STATUS_GUARD_PAGE_VIOLATION: NtStatus = 0x80000001;
