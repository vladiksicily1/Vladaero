//! # Virtual Memory Management
//!
//! MmAllocateVirtualMemory, MmFreeVirtualMemory, MmProtectVirtualMemory,
//! MmMapViewOfSection, MmUnmapViewOfSection - matching ntoskrnl.exe.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use super::{
    MmPteFlags, MmProtectionMask2, MmPte, MmPfn, ListEntry, SpinLock, Mutex,
    MiAddressFlags, MI_ADDRESS_DATA, MI_ADDRESS_PRIVATE, PoolTag, TAG_MMVA, TAG_MMVAD, TAG_POOL,
    MEM_COMMIT, MEM_RESERVE, MEM_PRIVATE, MEM_RELEASE, MEM_DECOMMIT,
    PAGE_SHIFT, PAGE_SIZE_X64, PAGE_MASK_X64,
    mi_get_pfn_element, mi_locate_vad, mi_locate_vad_hint,
    mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Virtual memory allocation types
// ============================================================

pub const MM_ALLOCATE_VM_DEFAULT: u32 = 0x0000;
pub const MM_ALLOCATE_VM_LARGE_PAGES: u32 = 0x0001;
pub const MM_ALLOCATE_VM_COMMIT: u32 = 0x0002;

pub const MM_FREE_VM_RELEASE: u32 = 0x0000;
pub const MM_FREE_VM_DECOMMIT: u32 = 0x0001;

// ============================================================
// Process virtual address space descriptor
// ============================================================

#[repr(C)]
pub struct MiAddressSpace {
    pub lock: SpinLock,
    pub vad_tree: super::vad::MiVadTree,
    pub working_set: super::ws::MiWorkingSet,
    pub top_down_highest: u64,
    pub bottom_up_highest: u64,
    pub total_commit: u64,
    pub total_reserve: u64,
    pub process: *mut c_void,
}

impl MiAddressSpace {
    pub const fn new() -> Self {
        Self {
            lock: SpinLock::new(),
            vad_tree: super::vad::MiVadTree::new(),
            working_set: super::ws::MiWorkingSet::new(),
            top_down_highest: 0x7FFF_0000_0000,
            bottom_up_highest: 0x10000,
            total_commit: 0,
            total_reserve: 0,
            process: core::ptr::null_mut(),
        }
    }
}

unsafe impl Send for MiAddressSpace {}
unsafe impl Sync for MiAddressSpace {}

static SYSTEM_ADDRESS_SPACE: Mutex<MiAddressSpace> = Mutex::new(MiAddressSpace::new());

// ============================================================
// MmAllocateVirtualMemory
// ============================================================

pub fn mm_allocate_virtual_memory(
    process: *mut c_void,
    base_address: *mut *mut c_void,
    zero_bits: u64,
    region_size: *mut usize,
    allocation_type: u32,
    protection: u32,
) -> NtStatus {
    if base_address.is_null() || region_size.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let requested_size = unsafe { *region_size };
    if requested_size == 0 {
        return STATUS_INVALID_PARAMETER;
    }

    let aligned_size = (requested_size + PAGE_SIZE_X64 - 1) & PAGE_MASK_X64;
    let page_count = aligned_size / PAGE_SIZE_X64;

    let prot = mi_decode_protection(protection);

    // Find free region in VAD tree
    let mut addr_space = SYSTEM_ADDRESS_SPACE.lock();

    let requested_base = unsafe { *base_address } as u64;
    let start_addr;
    let end_addr;

    if requested_base != 0 {
        // Specific address requested
        start_addr = requested_base & !(PAGE_SIZE_X64 as u64 - 1);
        end_addr = start_addr + aligned_size as u64 - 1;

        if mi_locate_vad(start_addr, &addr_space.vad_tree).is_some() ||
           mi_locate_vad(end_addr, &addr_space.vad_tree).is_some() {
            return STATUS_CONFLICTING_ADDRESSES;
        }
    } else {
        // Find suitable free region
        let found = mi_find_free_region(
            &addr_space,
            aligned_size,
            zero_bits,
        );

        if let Some((start, end)) = found {
            start_addr = start;
            end_addr = end;
        } else {
            return STATUS_NO_MEMORY;
        }
    }

    // Create VAD
    let mut flags: MiAddressFlags = 0;
    if allocation_type & MEM_COMMIT != 0 {
        flags |= MI_ADDRESS_DATA;
    }
    if allocation_type & MEM_RESERVE != 0 {
        flags |= MI_ADDRESS_DATA;
    }
    if allocation_type & MEM_PRIVATE != 0 {
        flags |= MI_ADDRESS_PRIVATE;
    }

    let vad = super::vad::mm_create_vad(
        start_addr, end_addr, flags, prot, process
    );

    if vad.is_null() {
        return STATUS_NO_MEMORY;
    }

    let status = super::vad::mm_insert_vad(vad, &mut addr_space.vad_tree);

    if status != STATUS_SUCCESS {
        return status;
    }

    // Allocate pages if committing
    if allocation_type & MEM_COMMIT != 0 {
        for i in 0..page_count {
            let va = start_addr + (i as u64) * (PAGE_SIZE_X64 as u64);

            if let Some(frame) = super::pfn::mi_allocate_pfn() {
                let pfn = unsafe { mi_get_pfn_element(frame) };
                let phys = (frame as u64) << PAGE_SHIFT;

                pfn.flags2 |= super::pfn::MMPFN_ACTIVE;
                pfn.reference_count_encoded = 1;

                super::pte::mi_create_pte(va, phys, prot, 0);

                super::MmResidentAvailablePages.fetch_sub(1, Ordering::Relaxed);
                super::MmAvailablePages.fetch_sub(1, Ordering::Relaxed);
            } else {
                return STATUS_NO_MEMORY;
            }
        }

        addr_space.total_commit += aligned_size as u64;
    }

    addr_space.total_reserve += aligned_size as u64;

    unsafe {
        *base_address = start_addr as *mut c_void;
        *region_size = aligned_size;
    }

    mm_trace!("MmAllocateVirtualMemory: {:p} size={:#x} prot={:#x}",
        start_addr as *mut c_void, aligned_size, protection);

    STATUS_SUCCESS
}

// ============================================================
// MmFreeVirtualMemory
// ============================================================

pub fn mm_free_virtual_memory(
    process: *mut c_void,
    base_address: *mut *mut c_void,
    region_size: *mut usize,
    free_type: u32,
) -> NtStatus {
    if base_address.is_null() || region_size.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let addr = unsafe { *base_address } as u64;
    let size = unsafe { *region_size };

    if addr == 0 || size == 0 {
        return STATUS_INVALID_PARAMETER;
    }

    let mut addr_space = SYSTEM_ADDRESS_SPACE.lock();

    let aligned_addr = addr & !(PAGE_SIZE_X64 as u64 - 1);
    let aligned_size = (size + PAGE_SIZE_X64 - 1) & PAGE_MASK_X64;

    if let Some(vad_ptr) = mi_locate_vad(aligned_addr, &addr_space.vad_tree) {
        unsafe {
            let vad = &*vad_ptr;

            if free_type & MEM_RELEASE != 0 {
                // Release entire region
                let start = vad.start_address;
                let end = vad.end_address;
                let total_size = (end - start + 1) as usize;
                let total_pages = total_size / PAGE_SIZE_X64;

                for i in 0..total_pages {
                    let va = start + (i as u64) * (PAGE_SIZE_X64 as u64);
                    super::pte::mi_make_pte_not_valid(va, &mut MmPte {
                        flags: MmPteFlags::empty(),
                        page_frame_number: 0,
                        protection: MmProtectionMask2::empty(),
                    });
                }

                super::vad::mm_remove_vad(vad_ptr, &mut addr_space.vad_tree);
                addr_space.total_reserve = addr_space.total_reserve.saturating_sub(total_size as u64);
                addr_space.total_commit = addr_space.total_commit.saturating_sub(total_size as u64);

                super::pte::mi_flush_pte_range(start, total_size);
            } else if free_type & MEM_DECOMMIT != 0 {
                // Decommit: free physical pages, keep reservation
                let total_pages = aligned_size / PAGE_SIZE_X64;

                for i in 0..total_pages {
                    let va = aligned_addr + (i as u64) * (PAGE_SIZE_X64 as u64);
                    super::pte::mi_make_pte_not_valid(va, &mut MmPte {
                        flags: MmPteFlags::empty(),
                        page_frame_number: 0,
                        protection: MmProtectionMask2::empty(),
                    });
                }

                super::pte::mi_flush_pte_range(aligned_addr, aligned_size);
                addr_space.total_commit = addr_space.total_commit.saturating_sub(aligned_size as u64);
            }
        }
    } else {
        return STATUS_INVALID_PARAMETER;
    }

    mm_trace!("MmFreeVirtualMemory: {:p} size={:#x}", addr as *mut c_void, size);
    STATUS_SUCCESS
}

// ============================================================
// MmProtectVirtualMemory
// ============================================================

pub fn mm_protect_virtual_memory(
    process: *mut c_void,
    base_address: *mut *mut c_void,
    region_size: *mut usize,
    new_protection: u32,
    old_protection: *mut u32,
) -> NtStatus {
    if base_address.is_null() || region_size.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let addr = unsafe { *base_address } as u64;
    let size = unsafe { *region_size };

    if addr == 0 || size == 0 {
        return STATUS_INVALID_PARAMETER;
    }

    let prot = mi_decode_protection(new_protection);
    let aligned_addr = addr & !(PAGE_SIZE_X64 as u64 - 1);
    let aligned_size = (size + PAGE_SIZE_X64 - 1) & PAGE_MASK_X64;

    let mut addr_space = SYSTEM_ADDRESS_SPACE.lock();

    if let Some(vad_ptr) = mi_locate_vad(aligned_addr, &addr_space.vad_tree) {
        unsafe {
            let vad = &mut *(vad_ptr as *mut super::vad::MiVadNode);
            let old_prot = vad.protection;
            vad.protection = prot;

            if !old_protection.is_null() {
                *old_protection = mi_encode_protection(old_prot);
            }
        }

        // Update PTEs
        let page_count = aligned_size / PAGE_SIZE_X64;
        for i in 0..page_count {
            let va = aligned_addr + (i as u64) * (PAGE_SIZE_X64 as u64);
            super::pte::mi_set_pte_protection(va, prot);
        }

        super::pte::mi_flush_pte_range(aligned_addr, aligned_size);

        mm_trace!("MmProtectVirtualMemory: {:p} new_prot={:#x}", addr as *mut c_void, new_protection);
        STATUS_SUCCESS
    } else {
        STATUS_INVALID_PARAMETER
    }
}

// ============================================================
// MmMapViewOfSection
// ============================================================

pub fn mm_map_view_of_section(
    section: *mut super::section::SectionObject,
    process: *mut c_void,
    base_address: *mut *mut c_void,
    zero_bits: u64,
    commit_size: u64,
    section_offset: *mut u64,
    view_size: *mut u64,
    inherit_disposition: u32,
    allocation_type: u32,
    win32_protect: u32,
) -> NtStatus {
    if section.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    unsafe {
        let section_ref = &*section;
        let requested_size = if !view_size.is_null() { *view_size } else { 0 };
        let mut map_size = if requested_size > 0 { requested_size } else { section_ref.size };

        let status = mm_allocate_virtual_memory(
            process,
            base_address,
            zero_bits,
            &mut map_size as *mut u64 as *mut usize,
            MEM_RESERVE | MEM_COMMIT,
            win32_protect,
        );

        if status != STATUS_SUCCESS {
            return status;
        }

        let map_addr = *base_address as u64;

        // Map section pages
        let page_count = (map_size + PAGE_SIZE_X64 as u64 - 1) / PAGE_SIZE_X64 as u64;
        let prot = mi_decode_protection(win32_protect);

        for i in 0..page_count {
            let va = map_addr + i * PAGE_SIZE_X64 as u64;
            let file_offset = if !section_offset.is_null() {
                *section_offset + i * PAGE_SIZE_X64 as u64
            } else {
                i * PAGE_SIZE_X64 as u64
            };

            if let Some(frame) = super::pfn::mi_allocate_pfn() {
                let pfn = mi_get_pfn_element(frame);
                let phys = (frame as u64) << PAGE_SHIFT;

                pfn.flags2 |= super::pfn::MMPFN_ACTIVE;
                pfn.reference_count_encoded = 1;

                super::pte::mi_create_pte(va, phys, prot, 0);
            } else {
                return STATUS_NO_MEMORY;
            }
        }

        if !section_offset.is_null() {
            *section_offset = 0;
        }
        if !view_size.is_null() {
            *view_size = map_size;
        }

        mm_trace!("MmMapViewOfSection: mapped at {:p} size={:#x}", map_addr as *mut c_void, map_size);
    }

    STATUS_SUCCESS
}

// ============================================================
// MmUnmapViewOfSection
// ============================================================

pub fn mm_unmap_view_of_section(
    process: *mut c_void,
    base_address: *mut c_void,
) -> NtStatus {
    if base_address.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let addr = base_address as u64;
    let mut addr_space = SYSTEM_ADDRESS_SPACE.lock();

    if let Some(vad_ptr) = mi_locate_vad(addr, &addr_space.vad_tree) {
        unsafe {
            let vad = &*vad_ptr;
            let start = vad.start_address;
            let end = vad.end_address;
            let total_size = (end - start + 1) as usize;

            let page_count = total_size / PAGE_SIZE_X64;
            for i in 0..page_count {
                let va = start + (i as u64) * (PAGE_SIZE_X64 as u64);
                super::pte::mi_make_pte_not_valid(va, &mut MmPte {
                    flags: MmPteFlags::empty(),
                    page_frame_number: 0,
                    protection: MmProtectionMask2::empty(),
                });
            }

            super::vad::mm_remove_vad(vad_ptr, &mut addr_space.vad_tree);
            super::pte::mi_flush_pte_range(start, total_size);
        }

        mm_trace!("MmUnmapViewOfSection: unmapped at {:p}", base_address);
        STATUS_SUCCESS
    } else {
        STATUS_INVALID_PARAMETER
    }
}

// ============================================================
// MmCommitVirtualMemory (internal)
// ============================================================

fn mi_commit_virtual_pages(
    base: u64,
    page_count: usize,
    prot: MmProtectionMask2,
) -> NtStatus {
    for i in 0..page_count {
        let va = base + (i as u64) * (PAGE_SIZE_X64 as u64);

        if let Some(frame) = super::pfn::mi_allocate_pfn() {
            let pfn = unsafe { mi_get_pfn_element(frame) };
            let phys = (frame as u64) << PAGE_SHIFT;

            pfn.flags2 |= super::pfn::MMPFN_ACTIVE;
            pfn.reference_count_encoded = 1;

            super::pte::mi_create_pte(va, phys, prot, 0);

            super::MmResidentAvailablePages.fetch_sub(1, Ordering::Relaxed);
            super::MmAvailablePages.fetch_sub(1, Ordering::Relaxed);
        } else {
            return STATUS_NO_MEMORY;
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// MiFindFreeRegion
// ============================================================

fn mi_find_free_region(
    addr_space: &MiAddressSpace,
    size: usize,
    zero_bits: u64,
) -> Option<(u64, u64)> {
    let mut candidate = 0x10000u64;
    let max_user = super::MM_HIGHEST_USER_ADDRESS;
    let aligned_size = (size as u64 + PAGE_SIZE_X64 as u64 - 1) & !(PAGE_SIZE_X64 as u64 - 1);

    while candidate + aligned_size <= max_user {
        let end = candidate + aligned_size - 1;

        if mi_locate_vad(candidate, &addr_space.vad_tree).is_none() &&
           mi_locate_vad(end, &addr_space.vad_tree).is_none() {
            // Check zero bits constraint
            if zero_bits == 0 || (candidate >> zero_bits) << zero_bits == candidate {
                return Some((candidate, end));
            }
        }

        candidate += aligned_size;
    }

    None
}

// ============================================================
// MiDecodeProtection
// ============================================================

fn mi_decode_protection(win32_protect: u32) -> MmProtectionMask2 {
    let mut prot = MmProtectionMask2::empty();

    match win32_protect & 0xFF {
        0x01 => prot |= MmProtectionMask2::NOACCESS,
        0x02 => prot |= MmProtectionMask2::READONLY,
        0x04 => prot |= MmProtectionMask2::READWRITE,
        0x08 => prot |= MmProtectionMask2::WRITECOPY,
        0x10 => prot |= MmProtectionMask2::EXECUTE,
        0x20 => prot |= MmProtectionMask2::EXECUTE_READ,
        0x40 => prot |= MmProtectionMask2::EXECUTE_READWRITE,
        0x80 => prot |= MmProtectionMask2::EXECUTE_WRITECOPY,
        _ => prot |= MmProtectionMask2::READONLY,
    }

    if win32_protect & 0x100 != 0 { prot |= MmProtectionMask2::GUARD; }
    if win32_protect & 0x200 != 0 { prot |= MmProtectionMask2::NOCACHE; }
    if win32_protect & 0x400 != 0 { prot |= MmProtectionMask2::WRITECOMBINE; }
    if win32_protect & 0x800 != 0 { prot |= MmProtectionMask2::USER; }

    prot
}

// ============================================================
// MiEncodeProtection
// ============================================================

fn mi_encode_protection(prot: MmProtectionMask2) -> u32 {
    let mut win32 = 0u32;

    if prot.contains(MmProtectionMask2::NOACCESS) { win32 = 0x01; }
    else if prot.contains(MmProtectionMask2::READONLY) { win32 = 0x02; }
    else if prot.contains(MmProtectionMask2::READWRITE) { win32 = 0x04; }
    else if prot.contains(MmProtectionMask2::WRITECOPY) { win32 = 0x08; }
    else if prot.contains(MmProtectionMask2::EXECUTE) { win32 = 0x10; }
    else if prot.contains(MmProtectionMask2::EXECUTE_READ) { win32 = 0x20; }
    else if prot.contains(MmProtectionMask2::EXECUTE_READWRITE) { win32 = 0x40; }
    else if prot.contains(MmProtectionMask2::EXECUTE_WRITECOPY) { win32 = 0x80; }

    if prot.contains(MmProtectionMask2::GUARD) { win32 |= 0x100; }
    if prot.contains(MmProtectionMask2::NOCACHE) { win32 |= 0x200; }
    if prot.contains(MmProtectionMask2::WRITECOMBINE) { win32 |= 0x400; }
    if prot.contains(MmProtectionMask2::USER) { win32 |= 0x800; }

    win32
}

// ============================================================
// STATUS_CONFLICTING_ADDRESSES
// ============================================================

pub const STATUS_CONFLICTING_ADDRESSES: NtStatus = 0xC0000018;
