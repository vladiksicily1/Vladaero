//! # Physical Page Database (MmPfnDatabase)
//!
//! Per-page frame descriptor tracking every physical page in the system.
//! Mirrors the MmPfn array at ntoskrnl.exe offsets.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use super::{
    MmPfn, MmPte, MmPteFlags, MmProtectionMask2, MmPfnDatabase, MmPfnDatabaseLength,
    SpinLock, ListEntry, MMPFN_VALID_PFN_MASK,
    PAGE_SHIFT, PAGE_SIZE_X64, mi_get_pfn_element, mi_get_pfn_number_from_address,
    mm_warn, mm_dbg,
};

pub const MI_PFN_CHAR_DELETED: u32 = 0x0001;

pub fn mi_get_physical_address_from_pfn(pfn_number: usize) -> u64 {
    (pfn_number as u64) << PAGE_SHIFT
}

// ============================================================
// MmPfn flags
// ============================================================

pub const MMPFN_DIRTY: u32 = 0x00000001;
pub const MMPFN_ACTIVE: u32 = 0x00000002;
pub const MMPFN_STANDBY: u32 = 0x00000004;
pub const MMPFN_MODIFIED: u32 = 0x00000008;
pub const MMPFN_CONTIGUITY: u32 = 0x00000010;
pub const MMPFN_LOCKED: u32 = 0x00000020;
pub const MMPFN_PROTECTED: u32 = 0x00000040;
pub const MMPFN_BAD: u32 = 0x00000080;
pub const MMPFN_ZEROED: u32 = 0x00000100;
pub const MMPFN_FREE: u32 = 0x00000200;
pub const MMPFN_FILE: u32 = 0x00000400;
pub const MMPFN_PAGE_TABLE: u32 = 0x00000800;
pub const MMPFN_PDE: u32 = 0x00001000;
pub const MMPFN_PPE: u32 = 0x00002000;
pub const MMPFN_PXE: u32 = 0x00004000;
pub const MMPFN_SHARED: u32 = 0x00008000;
pub const MMPFN_PRIVILEGE: u32 = 0x00010000;
pub const MMPFN_COLOR_MASK: u32 = 0x000E0000;
pub const MMPFN_REFERENCE_MASK: u32 = 0xFFF00000;

pub const MMPFN_COLOR_SHIFT: u32 = 17;

pub const MI_PFN_NOT_USED: u32 = 0;
pub const MI_PFN_SELFHEAL: u32 = 1;
pub const MI_PFN_DELETED: u32 = 0xFFFFFFFF;

// ============================================================
// Page priority / aging
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum MiPagePriority {
    Zero = 0,
    One = 1,
    Two = 2,
    Three = 3,
    Four = 4,
    Five = 5,
    MaximumPriority = 6,
}

// ============================================================
// MmPfn flags field layout (reuses existing Pfn union from types)
// ============================================================

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct PfnCharacter(pub u32);

impl PfnCharacter {
    pub const VALID: PfnCharacter = PfnCharacter(0x00000001);
    pub const STANDY_LIST: PfnCharacter = PfnCharacter(0x00000002);
    pub const MODIFIED_LIST: PfnCharacter = PfnCharacter(0x00000004);
    pub const TRANSITION: PfnCharacter = PfnCharacter(0x00000008);
    pub const LOCKED: PfnCharacter = PfnCharacter(0x00000010);
    pub const PROTECTION: PfnCharacter = PfnCharacter(0x00000020);
    pub const AWE: PfnCharacter = PfnCharacter(0x00000040);
    pub const PRIVILEGE_FAULT: PfnCharacter = PfnCharacter(0x00000080);
    pub const PROTOTYPE_PTE: PfnCharacter = PfnCharacter(0x00000100);

    pub const fn empty() -> Self { PfnCharacter(0) }
    pub const fn bits(self) -> u32 { self.0 }
    pub const fn contains(self, other: PfnCharacter) -> bool { (self.0 & other.0) == other.0 }
    pub fn insert(&mut self, other: PfnCharacter) { self.0 |= other.0; }
    pub fn remove(&mut self, other: PfnCharacter) { self.0 &= !other.0; }
    pub const fn is_empty(self) -> bool { self.0 == 0 }
}

impl core::ops::BitOr for PfnCharacter {
    type Output = Self;
    fn bitor(self, rhs: Self) -> Self { PfnCharacter(self.0 | rhs.0) }
}

impl core::ops::BitOrAssign for PfnCharacter {
    fn bitor_assign(&mut self, rhs: Self) { self.0 |= rhs.0; }
}

impl core::ops::BitAnd for PfnCharacter {
    type Output = Self;
    fn bitand(self, rhs: Self) -> Self { PfnCharacter(self.0 & rhs.0) }
}

impl core::ops::BitAndAssign for PfnCharacter {
    fn bitand_assign(&mut self, rhs: Self) { self.0 &= rhs.0; }
}

impl core::ops::Not for PfnCharacter {
    type Output = Self;
    fn not(self) -> Self { PfnCharacter(!self.0) }
}

impl Default for PfnCharacter {
    fn default() -> Self {
        Self::VALID
    }
}

// ============================================================
// MiPfnUnlink (remove PFN from its working set)
// ============================================================

pub unsafe fn mi_pfn_unlink(pfn: &mut MmPfn) {
    unsafe {
        // Clear the working set pointer
        pfn.used_by1.fields0 = 0;
        pfn.used_by2 = 0;
        pfn.used_by3 = 0;
        pfn.used_by4.fields1 = 0;
    }
}

// ============================================================
// MmInitializePfnDatabase
// ============================================================

pub unsafe fn mm_initialize_pfn_database(
    memory_map: &[super::phys::MmMemoryRangeDescriptor],
) {
    unsafe {
        let mut max_pfn: usize = 0;

        for desc in memory_map {
            let base_pfn = (desc.base_address >> PAGE_SHIFT) as usize;
            let end_pfn = base_pfn + ((desc.size >> PAGE_SHIFT) as usize);
            if end_pfn > max_pfn {
                max_pfn = end_pfn;
            }
        }

        if max_pfn == 0 {
            mm_warn!("MmInitializePfnDatabase: no memory ranges provided");
            return;
        }

        let pfn_size = core::mem::size_of::<MmPfn>();
        let total_bytes = max_pfn * pfn_size;
        let pages_needed = (total_bytes + PAGE_SIZE_X64 - 1) / PAGE_SIZE_X64;

        let pfn_array = super::phys::mm_allocate_contiguous_memory(
            pages_needed * PAGE_SIZE_X64,
            0xFFFF_FFFF_FFFF_FFFF,
        ) as *mut MmPfn;

        if pfn_array.is_null() {
            mm_warn!("MmInitializePfnDatabase: failed to allocate PFN database ({} bytes)",
                total_bytes);
            return;
        }

        core::ptr::write_bytes(pfn_array as *mut u8, 0, total_bytes);

        MmPfnDatabase.get().write(pfn_array);
        MmPfnDatabaseLength.store(max_pfn, Ordering::Relaxed);

        for desc in memory_map {
            let base_pfn = (desc.base_address >> PAGE_SHIFT) as usize;
            let page_count = (desc.size >> PAGE_SHIFT) as usize;

            for i in 0..page_count {
                let pfn_num = base_pfn + i;
                let pfn = &mut *pfn_array.add(pfn_num);

                pfn.pfn_number = pfn_num as u64;

                if desc.flags & super::phys::MI_MM_RANGE_RAM != 0 {
                    pfn.flags2 = MMPFN_ZEROED | MMPFN_VALID_PFN_MASK;
                } else if desc.flags & super::phys::MI_MM_RANGE_RESERVED != 0 {
                    pfn.flags2 = MMPFN_BAD;
                } else {
                    pfn.flags2 = 0;
                }

                pfn.reference_count_encoded = 0;

                let color = (pfn_num % 8) as u32;
                pfn.used_by4.fields1 = (pfn.used_by4.fields1 & !0xE0000) | (color << MMPFN_COLOR_SHIFT);

                // Initialize embedded ListEntry and add to free list
                pfn.list_entry.flink = core::ptr::null_mut();
                pfn.list_entry.blink = core::ptr::null_mut();

                if desc.flags & super::phys::MI_MM_RANGE_RAM != 0 {
                    // Add usable RAM pages to the free list
                    let mut free_list = super::MmFreePageListHead.lock();
                    free_list.list_heads[0].insert_tail(&mut pfn.list_entry);
                    free_list.number_of_entries += 1;
                    super::MmAvailablePages.fetch_add(1, Ordering::Relaxed);
                    super::MmResidentAvailablePages.fetch_add(1, Ordering::Relaxed);
                }
            }
        }

        mm_dbg!("MmInitializePfnDatabase: initialized {} PFN entries ({} MB)",
            max_pfn, total_bytes / (1024 * 1024));
    }
}

// ============================================================
// MmFreePfnNumber
// ============================================================

pub unsafe fn mm_free_pfn_number(pfn_number: usize) {
    unsafe {
        let pfn = mi_get_pfn_element(pfn_number);
        pfn.flags2 = MMPFN_FREE;
        pfn.reference_count_encoded = 0;
        pfn.used_by1.fields0 = 0;
        pfn.used_by2 = 0;
        pfn.used_by3 = 0;
    }
}

// ============================================================
// MiDecrementReferenceCount
// ============================================================

pub unsafe fn mi_decrement_reference_count(pfn: &mut MmPfn) -> u32 {
    let old = pfn.reference_count_encoded;
    if old > 1 {
        pfn.reference_count_encoded = old - 1;
        old - 1
    } else {
        pfn.reference_count_encoded = 0;
        0
    }
}

// ============================================================
// MiIncrementReferenceCount
// ============================================================

pub unsafe fn mi_increment_reference_count(pfn: &mut MmPfn) -> u32 {
    let old = pfn.reference_count_encoded;
    pfn.reference_count_encoded = old + 1;
    old + 1
}

// ============================================================
// MiGetPfnFromPte
// ============================================================

pub fn mi_get_pfn_from_pte(pte: &MmPte) -> Option<&'static mut MmPfn> {
    if !pte.flags.contains(MmPteFlags::VALID) {
        return None;
    }
    let pfn_num = pte.page_frame_number as usize;
    if pfn_num >= MmPfnDatabaseLength.load(Ordering::Relaxed) {
        return None;
    }
    Some(unsafe { mi_get_pfn_element(pfn_num) })
}

// ============================================================
// MiReleasePfn
// ============================================================

pub unsafe fn mi_release_pfn(pfn_number: usize, page_table_type: u32) -> NtStatus {
    unsafe {
        let pfn = mi_get_pfn_element(pfn_number);

        if pfn.flags2 & MMPFN_BAD != 0 {
            return STATUS_SUCCESS;
        }

        let ref_count = mi_decrement_reference_count(pfn);

        if ref_count == 0 {
            if pfn.flags2 & MMPFN_MODIFIED != 0 {
                let mut modified_list = super::MmModifiedPageListHead.lock();
                modified_list.list_heads[0].insert_tail(&mut pfn.list_entry);
                modified_list.number_of_entries += 1;
                drop(modified_list);
            } else if pfn.flags2 & MMPFN_STANDBY != 0 {
                let color = (pfn.used_by4.fields1 >> MMPFN_COLOR_SHIFT) & 7;
                let mut standby = super::MmStandbyPageListHead.lock();
                standby.list_heads[color as usize].insert_tail(&mut pfn.list_entry);
                standby.number_of_entries += 1;
                drop(standby);
            } else {
                let mut free_list = super::MmFreePageListHead.lock();
                free_list.list_heads[0].insert_tail(&mut pfn.list_entry);
                free_list.number_of_entries += 1;
                drop(free_list);
            }

            mm_trace!("MiReleasePfn: released PFN {} to appropriate list", pfn_number);
        }

        STATUS_SUCCESS
    }
}

// ============================================================
// MiAllocatePfn (internal page frame allocation)
// ============================================================

pub fn mi_allocate_pfn() -> Option<usize> {
    let mut free_list = super::MmFreePageListHead.lock();

    if free_list.number_of_entries == 0 {
        return None;
    }

    let list_head = &mut free_list.list_heads[0] as *mut super::ListEntry;
    let first = unsafe {
        let entry = (*list_head).flink;
        if entry.is_null() || entry == list_head {
            return None;
        }
        (*entry).remove();
        entry
    };

    free_list.number_of_entries -= 1;
    drop(free_list);

    // Recover PFN number from embedded ListEntry using container_of
    let pfn_number = unsafe {
        let offset = core::mem::offset_of!(MmPfn, list_entry);
        let entry_addr = first as usize;
        let pfn_addr = entry_addr - offset;
        let db = *MmPfnDatabase.get();
        (pfn_addr - db as usize) / core::mem::size_of::<MmPfn>()
    };

    let pfn = unsafe { mi_get_pfn_element(pfn_number) };
    pfn.flags2 = MMPFN_VALID_PFN_MASK | MMPFN_ZEROED;
    pfn.reference_count_encoded = 1;

    Some(pfn_number)
}

// ============================================================
// MmPfnResidentAvailable
// ============================================================

pub fn mm_pfn_resident_available() -> usize {
    super::MmResidentAvailablePages.load(Ordering::Relaxed)
}

// ============================================================
// MiPfnFreeCount
// ============================================================

pub fn mi_pfn_free_count() -> usize {
    super::MmFreePageListHead.lock().number_of_entries as usize
}

// ============================================================
// MiPfnModifiedCount
// ============================================================

pub fn mi_pfn_modified_count() -> usize {
    super::MmModifiedPageListHead.lock().number_of_entries as usize
}

// ============================================================
// MiPfnStandbyCount
// ============================================================

pub fn mi_pfn_standby_count() -> usize {
    let standby = super::MmStandbyPageListHead.lock();
    standby.number_of_entries as usize
}

// ============================================================
// MmGetPageColor (round-robin per processor)
// ============================================================

pub fn mm_get_page_color() -> usize {
    static NEXT_COLOR: AtomicUsize = AtomicUsize::new(0);
    let color = NEXT_COLOR.fetch_add(1, Ordering::Relaxed) % 8;
    color
}

// ============================================================
// MiMapPageAtZero
// ============================================================

pub unsafe fn mi_map_page_at_zero(physical_address: u64) -> *mut c_void {
    unsafe {
        let virt = 0x0000_0000_0000_1000u64;
        let pte_addr = super::mi_get_virtual_address_pte(virt);
        let pte = &mut *(pte_addr as *mut MmPte);

        pte.page_frame_number = physical_address >> PAGE_SHIFT;
        pte.flags = MmPteFlags::VALID | MmPteFlags::WRITE | MmPteFlags::OWNER_USER | MmPteFlags::ACCESSED;
        pte.protection = MmProtectionMask2::READWRITE;

        virt as *mut c_void
    }
}

// ============================================================
// MmPfnStandbyFlushAll
// ============================================================

pub fn mm_pfn_standby_flush_all() -> usize {
    let mut standby = super::MmStandbyPageListHead.lock();
    let count = standby.number_of_entries as usize;
    for list in standby.list_heads.iter_mut() {
        list.initialize();
    }
    standby.number_of_entries = 0;
    count
}
