//! # Page Table Management
//!
//! PML4E, PDPTE, PDE, PTE structs and page table manipulation routines
//! matching ntoskrnl.exe's page table walker and page table creation.

use core::sync::atomic::{AtomicU64, Ordering};

use crate::types::*;
use super::{
    MmPteFlags, MmProtectionMask2, MmPte, PfnCharacter,
    PAGE_SHIFT, PAGE_SIZE_X64, PAGE_MASK_X64,
    mi_get_virtual_address_pxe, mi_get_virtual_address_pde, mi_get_virtual_address_pte,
    mi_get_pfn_element, mi_get_pfn_number_from_address,
    mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Page table entry bit definitions (Intel SDM Vol 3A)
// ============================================================

pub const PTE_PRESENT: u64 = 1 << 0;
pub const PTE_WRITE: u64 = 1 << 1;
pub const PTE_USER: u64 = 1 << 2;
pub const PTE_PWT: u64 = 1 << 3;
pub const PTE_PCD: u64 = 1 << 4;
pub const PTE_ACCESSED: u64 = 1 << 5;
pub const PTE_DIRTY: u64 = 1 << 6;
pub const PTE_PAT: u64 = 1 << 7;
pub const PTE_GLOBAL: u64 = 1 << 8;
pub const PTE_BIT_9: u64 = 1 << 9;
pub const PTE_BIT_10: u64 = 1 << 10;
pub const PTE_BIT_11: u64 = 1 << 11;
pub const PTE_FRAME_MASK: u64 = 0x000F_FFFF_FFFF_F000;
pub const PTE_SOFT_VALID: u64 = 1 << 63;

pub const VAD_NO_ACCESS: u8 = 0;
pub const VAD_READ_ONLY: u8 = 1;
pub const VAD_READWRITE: u8 = 2;
pub const VAD_EXECUTE: u8 = 4;
pub const VAD_EXECUTE_READ: u8 = 5;
pub const VAD_EXECUTE_READWRITE: u8 = 6;
pub const VAD_GUARD: u8 = 8;
pub const VAD_NOCACHE: u8 = 16;

// ============================================================
// PML4E - Page Map Level 4 Entry (64-bit)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MiPml4e {
    pub low_part: u64,
    pub high_part: u64,
}

impl MiPml4e {
    pub fn present(&self) -> bool {
        self.low_part & PTE_PRESENT != 0
    }

    pub fn writable(&self) -> bool {
        self.low_part & PTE_WRITE != 0
    }

    pub fn user_accessible(&self) -> bool {
        self.low_part & PTE_USER != 0
    }

    pub fn page_frame_number(&self) -> u64 {
        (self.low_part & PTE_FRAME_MASK) >> PAGE_SHIFT
    }

    pub fn set_present(&mut self, present: bool) {
        if present {
            self.low_part |= PTE_PRESENT;
        } else {
            self.low_part &= !PTE_PRESENT;
        }
    }

    pub fn set_writable(&mut self, writable: bool) {
        if writable {
            self.low_part |= PTE_WRITE;
        } else {
            self.low_part &= !PTE_WRITE;
        }
    }

    pub fn set_user(&mut self, user: bool) {
        if user {
            self.low_part |= PTE_USER;
        } else {
            self.low_part &= !PTE_USER;
        }
    }

    pub fn set_page_frame_number(&mut self, pfn: u64) {
        self.low_part = (self.low_part & !PTE_FRAME_MASK) | ((pfn << PAGE_SHIFT) & PTE_FRAME_MASK);
    }
}

// ============================================================
// PDPTE - Page Directory Pointer Table Entry (64-bit)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MiPdpte {
    pub low_part: u64,
    pub high_part: u64,
}

impl MiPdpte {
    pub fn present(&self) -> bool {
        self.low_part & PTE_PRESENT != 0
    }

    pub fn writable(&self) -> bool {
        self.low_part & PTE_WRITE != 0
    }

    pub fn user_accessible(&self) -> bool {
        self.low_part & PTE_USER != 0
    }

    pub fn is_large_page(&self) -> bool {
        self.low_part & (1 << 7) != 0
    }

    pub fn page_frame_number(&self) -> u64 {
        (self.low_part & PTE_FRAME_MASK) >> PAGE_SHIFT
    }

    pub fn set_present(&mut self, present: bool) {
        if present {
            self.low_part |= PTE_PRESENT;
        } else {
            self.low_part &= !PTE_PRESENT;
        }
    }

    pub fn set_writable(&mut self, writable: bool) {
        if writable {
            self.low_part |= PTE_WRITE;
        } else {
            self.low_part &= !PTE_WRITE;
        }
    }

    pub fn set_user(&mut self, user: bool) {
        if user {
            self.low_part |= PTE_USER;
        } else {
            self.low_part &= !PTE_USER;
        }
    }

    pub fn set_large_page(&mut self, large: bool) {
        if large {
            self.low_part |= 1 << 7;
        } else {
            self.low_part &= !(1 << 7);
        }
    }

    pub fn set_page_frame_number(&mut self, pfn: u64) {
        self.low_part = (self.low_part & !PTE_FRAME_MASK) | ((pfn << PAGE_SHIFT) & PTE_FRAME_MASK);
    }
}

// ============================================================
// PDE - Page Directory Entry (64-bit)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MiPde {
    pub low_part: u64,
    pub high_part: u64,
}

impl MiPde {
    pub fn present(&self) -> bool {
        self.low_part & PTE_PRESENT != 0
    }

    pub fn writable(&self) -> bool {
        self.low_part & PTE_WRITE != 0
    }

    pub fn user_accessible(&self) -> bool {
        self.low_part & PTE_USER != 0
    }

    pub fn is_large_page(&self) -> bool {
        self.low_part & (1 << 7) != 0
    }

    pub fn page_frame_number(&self) -> u64 {
        (self.low_part & PTE_FRAME_MASK) >> PAGE_SHIFT
    }

    pub fn set_present(&mut self, present: bool) {
        if present {
            self.low_part |= PTE_PRESENT;
        } else {
            self.low_part &= !PTE_PRESENT;
        }
    }

    pub fn set_writable(&mut self, writable: bool) {
        if writable {
            self.low_part |= PTE_WRITE;
        } else {
            self.low_part &= !PTE_WRITE;
        }
    }

    pub fn set_user(&mut self, user: bool) {
        if user {
            self.low_part |= PTE_USER;
        } else {
            self.low_part &= !PTE_USER;
        }
    }

    pub fn set_large_page(&mut self, large: bool) {
        if large {
            self.low_part |= 1 << 7;
        } else {
            self.low_part &= !(1 << 7);
        }
    }

    pub fn set_page_frame_number(&mut self, pfn: u64) {
        self.low_part = (self.low_part & !PTE_FRAME_MASK) | ((pfn << PAGE_SHIFT) & PTE_FRAME_MASK);
    }
}

// ============================================================
// PTE (extended) - Page Table Entry (64-bit)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MiPteExtended {
    pub low_part: u64,
    pub high_part: u64,
}

impl MiPteExtended {
    pub fn present(&self) -> bool {
        self.low_part & PTE_PRESENT != 0
    }

    pub fn writable(&self) -> bool {
        self.low_part & PTE_WRITE != 0
    }

    pub fn user_accessible(&self) -> bool {
        self.low_part & PTE_USER != 0
    }

    pub fn dirty(&self) -> bool {
        self.low_part & PTE_DIRTY != 0
    }

    pub fn accessed(&self) -> bool {
        self.low_part & PTE_ACCESSED != 0
    }

    pub fn global(&self) -> bool {
        self.low_part & PTE_GLOBAL != 0
    }

    pub fn page_frame_number(&self) -> u64 {
        (self.low_part & PTE_FRAME_MASK) >> PAGE_SHIFT
    }

    pub fn set_present(&mut self, present: bool) {
        if present {
            self.low_part |= PTE_PRESENT;
        } else {
            self.low_part &= !PTE_PRESENT;
        }
    }

    pub fn set_writable(&mut self, writable: bool) {
        if writable {
            self.low_part |= PTE_WRITE;
        } else {
            self.low_part &= !PTE_WRITE;
        }
    }

    pub fn set_user(&mut self, user: bool) {
        if user {
            self.low_part |= PTE_USER;
        } else {
            self.low_part &= !PTE_USER;
        }
    }

    pub fn set_page_frame_number(&mut self, pfn: u64) {
        self.low_part = (self.low_part & !PTE_FRAME_MASK) | ((pfn << PAGE_SHIFT) & PTE_FRAME_MASK);
    }
}

// ============================================================
// KiPcr page table base pointers
// ============================================================

static PCR_PML4: AtomicU64 = AtomicU64::new(0);

pub fn ki_pcr_get_pml4() -> u64 {
    let val = PCR_PML4.load(Ordering::Relaxed);
    if val == 0 {
        // Read CR3 from current processor
        let cr3: u64;
        unsafe {
            core::arch::asm!("mov {}, cr3", out(reg) cr3);
        }
        PCR_PML4.store(cr3, Ordering::Relaxed);
        cr3
    } else {
        val
    }
}

// ============================================================
// MiGetPdeAddress / MiGetPteAddress
// ============================================================

pub fn mi_get_pde_address(virtual_address: u64) -> u64 {
    let pml4_index = (virtual_address >> 39) & 0x1FF;
    let pdpt_index = (virtual_address >> 30) & 0x1FF;
    let pd_index = (virtual_address >> 21) & 0x1FF;

    let pml4e_addr = ki_pcr_get_pml4() + pml4_index * 8;
    let pdpte_addr = (unsafe { *(pml4e_addr as *const u64) } & PTE_FRAME_MASK) + pdpt_index * 8;
    let pde_addr = (unsafe { *(pdpte_addr as *const u64) } & PTE_FRAME_MASK) + pd_index * 8;
    pde_addr
}

pub fn mi_get_pte_address(virtual_address: u64) -> u64 {
    let pml4_index = (virtual_address >> 39) & 0x1FF;
    let pdpt_index = (virtual_address >> 30) & 0x1FF;
    let pd_index = (virtual_address >> 21) & 0x1FF;
    let pt_index = (virtual_address >> 12) & 0x1FF;

    let pml4e_addr = ki_pcr_get_pml4() + pml4_index * 8;
    let pdpte_addr = (unsafe { *(pml4e_addr as *const u64) } & PTE_FRAME_MASK) + pdpt_index * 8;
    let pde_addr = (unsafe { *(pdpte_addr as *const u64) } & PTE_FRAME_MASK) + pd_index * 8;
    let pte_addr = (unsafe { *(pde_addr as *const u64) } & PTE_FRAME_MASK) + pt_index * 8;
    pte_addr
}

// ============================================================
// MiCreatePte
// ============================================================

pub fn mi_create_pte(
    virtual_address: u64,
    physical_address: u64,
    protection: MmProtectionMask2,
    page_table_type: u32,
) -> NtStatus {
    unsafe {
        let pte_addr = mi_get_pte_address(virtual_address);
        let pte = &mut *(pte_addr as *mut MmPte);

        if pte.flags.contains(MmPteFlags::VALID) && pte.page_frame_number != 0 {
            mm_warn!("MiCreatePte: PTE already valid at VA {:#x}", virtual_address);
            return STATUS_INVALID_PARAMETER;
        }

        pte.flags = MmPteFlags::VALID | MmPteFlags::ACCESSED;

        if protection.contains(MmProtectionMask2::READWRITE) ||
           protection.contains(MmProtectionMask2::EXECUTE_READWRITE) {
            pte.flags |= MmPteFlags::WRITE;
        }

        if protection.contains(MmProtectionMask2::USER) {
            pte.flags |= MmPteFlags::OWNER_USER;
        }

        pte.page_frame_number = physical_address >> PAGE_SHIFT;
        pte.protection = protection;

        // Update TLB
        core::arch::asm!("invlpg [{}]", in(reg) virtual_address, options(nostack));

        mm_trace!("MiCreatePte: mapped VA {:#x} -> PA {:#x}", virtual_address, physical_address);
        STATUS_SUCCESS
    }
}

// ============================================================
// MiCreatePde
// ============================================================

pub fn mi_create_pde(
    virtual_address: u64,
    physical_address: u64,
    protection: MmProtectionMask2,
) -> NtStatus {
    unsafe {
        let pde_addr = mi_get_pde_address(virtual_address);
        let pde = &mut *(pde_addr as *mut MiPde);

        if pde.present() {
            if pde.is_large_page() {
                mm_trace!("MiCreatePde: large page present at VA {:#x}", virtual_address);
                return STATUS_SUCCESS;
            }
            return STATUS_SUCCESS;
        }

        pde.set_present(true);
        pde.set_writable(true);
        pde.set_user(true);
        pde.set_page_frame_number(physical_address >> PAGE_SHIFT);

        STATUS_SUCCESS
    }
}

// ============================================================
// MiCreatePml4
// ============================================================

pub fn mi_create_pml4() -> NtStatus {
    unsafe {
        let cr3: u64;
        core::arch::asm!("mov {}, cr3", out(reg) cr3);

        let pml4_addr = cr3;
        let pml4 = core::slice::from_raw_parts_mut(pml4_addr as *mut MiPml4e, 512);

        for entry in pml4.iter_mut() {
            if entry.present() {
                continue;
            }
            let frame = super::phys::mm_allocate_contiguous_memory(PAGE_SIZE_X64, 0xFFFF_FFFF_FFFF_FFFF);
            if frame.is_null() {
                mm_err!("MiCreatePml4: failed to allocate page table page");
                return STATUS_NO_MEMORY;
            }
            core::ptr::write_bytes(frame, 0, PAGE_SIZE_X64);

            entry.set_present(true);
            entry.set_writable(true);
            entry.set_user(true);
            entry.set_page_frame_number(frame as u64 >> PAGE_SHIFT);
        }

        STATUS_SUCCESS
    }
}

// ============================================================
// MiIsPteValid
// ============================================================

pub fn mi_is_pte_valid(pte: &MmPte) -> bool {
    pte.flags.contains(MmPteFlags::VALID)
}

// ============================================================
// MiGetPteProtection
// ============================================================

pub fn mi_get_pte_protection(pte: &MmPte) -> MmProtectionMask2 {
    pte.protection
}

// ============================================================
// MiMakePteProtection
// ============================================================

pub fn mi_make_pte_protection(
    no_access: bool,
    read_only: bool,
    readwrite: bool,
    execute: bool,
    writecopy: bool,
    user: bool,
    guard: bool,
    nocache: bool,
    writecombine: bool,
) -> MmProtectionMask2 {
    let mut protection = MmProtectionMask2::empty();

    if guard {
        protection |= MmProtectionMask2::GUARD;
    }
    if nocache {
        protection |= MmProtectionMask2::NOCACHE;
    }
    if writecombine {
        protection |= MmProtectionMask2::WRITECOMBINE;
    }

    if readwrite || execute {
        protection |= MmProtectionMask2::EXECUTE_READWRITE;
    } else if read_only {
        protection |= MmProtectionMask2::READONLY;
    } else {
        protection |= MmProtectionMask2::NOACCESS;
    }

    if writecopy {
        protection |= MmProtectionMask2::WRITECOPY;
    }
    if user {
        protection |= MmProtectionMask2::USER;
    }

    protection
}

// ============================================================
// MiSetPteProtection
// ============================================================

pub fn mi_set_pte_protection(virtual_address: u64, protection: MmProtectionMask2) -> NtStatus {
    unsafe {
        let pte_addr = mi_get_pte_address(virtual_address);
        let pte = &mut *(pte_addr as *mut MmPte);

        if !pte.flags.contains(MmPteFlags::VALID) {
            return STATUS_INVALID_PARAMETER;
        }

        pte.protection = protection;

        if protection.contains(MmProtectionMask2::READWRITE) ||
           protection.contains(MmProtectionMask2::EXECUTE_READWRITE) {
            pte.flags |= MmPteFlags::WRITE;
        } else {
            pte.flags &= !MmPteFlags::WRITE;
        }

        if protection.contains(MmProtectionMask2::USER) {
            pte.flags |= MmPteFlags::OWNER_USER;
        } else {
            pte.flags &= !MmPteFlags::OWNER_USER;
        }

        core::arch::asm!("invlpg [{}]", in(reg) virtual_address, options(nostack));
        STATUS_SUCCESS
    }
}

// ============================================================
// MiFlushPteRange
// ============================================================

pub fn mi_flush_pte_range(virtual_address: u64, size: usize) {
    let page_count = (size + PAGE_SIZE_X64 - 1) / PAGE_SIZE_X64;
    for i in 0..page_count {
        let va = virtual_address + (i as u64) * (PAGE_SIZE_X64 as u64);
        unsafe {
            core::arch::asm!("invlpg [{}]", in(reg) va, options(nostack));
        }
    }
}

// ============================================================
// MiMakeValidPte
// ============================================================

pub fn mi_make_valid_pte(
    physical_address: u64,
    protection: MmProtectionMask2,
) -> MmPte {
    let mut pte = MmPte {
        flags: MmPteFlags::VALID | MmPteFlags::ACCESSED,
        page_frame_number: physical_address >> PAGE_SHIFT,
        protection,
    };

    if protection.contains(MmProtectionMask2::READWRITE) ||
       protection.contains(MmProtectionMask2::EXECUTE_READWRITE) {
        pte.flags |= MmPteFlags::WRITE;
    }

    if protection.contains(MmProtectionMask2::USER) {
        pte.flags |= MmPteFlags::OWNER_USER;
    }

    pte
}

// ============================================================
// MiMakePteNotValid
// ============================================================

pub fn mi_make_pte_not_valid(virtual_address: u64, pte: &mut MmPte) -> NtStatus {
    pte.flags = MmPteFlags::empty();
    pte.page_frame_number = 0;

    unsafe {
        core::arch::asm!("invlpg [{}]", in(reg) virtual_address, options(nostack));
    }

    STATUS_SUCCESS
}

// ============================================================
// KiInvalidatePte
// ============================================================

pub fn ki_invalidate_pte(virtual_address: u64) {
    unsafe {
        core::arch::asm!("invlpg [{}]", in(reg) virtual_address, options(nostack));
    }
}

// ============================================================
// MmFlushTb
// ============================================================

pub fn mm_flush_tb() {
    unsafe {
        let cr3: u64;
        core::arch::asm!("mov {}, cr3", out(reg) cr3);
        core::arch::asm!("mov cr3, {}", in(reg) cr3);
    }
}

// ============================================================
// KiCopyPage
// ============================================================

pub unsafe fn ki_copy_page(source: u64, destination: u64) {
    unsafe {
        let src_ptr = source as *const u8;
        let dst_ptr = destination as *mut u8;
        core::ptr::copy_nonoverlapping(src_ptr, dst_ptr, PAGE_SIZE_X64);
    }
}

// ============================================================
// MiZeroPage
// ============================================================

pub unsafe fn mi_zero_page(address: u64, size: usize) {
    unsafe {
        core::ptr::write_bytes(address as *mut u8, 0, size);
    }
}

// ============================================================
// MiPteToPfn
// ============================================================

pub fn mi_pte_to_pf(pte: &MmPte) -> Option<&'static mut super::MmPfn> {
    if !pte.flags.contains(MmPteFlags::VALID) {
        return None;
    }
    let pfn_num = pte.page_frame_number as usize;
    if pfn_num >= super::MmPfnDatabaseLength.load(Ordering::Relaxed) {
        return None;
    }
    Some(unsafe { mi_get_pfn_element(pfn_num) })
}

// ============================================================
// MiIsPteOnStandby / MiIsPteOnModified
// ============================================================

pub fn mi_is_pte_on_standby(pte: &MmPte) -> bool {
    if pte.flags.contains(MmPteFlags::VALID) {
        return false;
    }
    if pte.page_frame_number != 0 {
        let pfn = unsafe { mi_get_pfn_element(pte.page_frame_number as usize) };
        pfn.flags2 & super::pfn::MMPFN_STANDBY != 0
    } else {
        false
    }
}

pub fn mi_is_pte_on_modified(pte: &MmPte) -> bool {
    if pte.flags.contains(MmPteFlags::VALID) {
        return false;
    }
    if pte.page_frame_number != 0 {
        let pfn = unsafe { mi_get_pfn_element(pte.page_frame_number as usize) };
        pfn.flags2 & super::pfn::MMPFN_MODIFIED != 0
    } else {
        false
    }
}
