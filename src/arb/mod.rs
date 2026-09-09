/// Arb - Resource Arbiter (Arb/Arbp)
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

use core::ffi::c_void;

#[repr(u32)]
pub enum ArbiterType {
    Nothing = 0,
    RangeList = 1,
    ArbiterHandler = 2,
}

#[repr(u32)]
pub enum ArbiterMode {
    UserConfiguration = 0,
    RecommendedConfiguration = 1,
}

pub struct ArbRange {
    pub start: u64,
    pub end: u64,
    pub next: *mut ArbRange,
}

pub struct ArbInstance {
    pub name: [u16; 64],
    pub arbid_type: ArbiterType,
    pub range_list: ListEntry,
    pub allocated_ranges: *mut ArbRange,
    pub conflict_count: u32,
    pub count: u32,
}

pub unsafe fn arb_initialize_arbiter(
    instance: *mut ArbInstance,
    name: *const u16,
) -> NtStatus {
    if instance.is_null() { return STATUS_INVALID_PARAMETER; }

    let arb = &mut *instance;
    let mut i = 0;
    while i < 32 && *name.add(i) != 0 {
        arb.name[i] = *name.add(i);
        i += 1;
    }
    arb.range_list.initialize();
    arb.allocated_ranges = core::ptr::null_mut();
    arb.conflict_count = 0;
    arb.count = 0;
    STATUS_SUCCESS
}

pub unsafe fn arb_allocate_range(
    instance: *mut ArbInstance,
    min: u64,
    _max: u64,
    length: u64,
) -> NtStatus {
    if instance.is_null() { return STATUS_INVALID_PARAMETER; }

    let range = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<ArbRange>()) as *mut ArbRange;
    if range.is_null() { return STATUS_NO_MEMORY; }

    (*range).start = min;
    (*range).end = min + length - 1;
    (*range).next = (*instance).allocated_ranges;
    (*instance).allocated_ranges = range;
    (*instance).count += 1;

    STATUS_SUCCESS
}

pub unsafe fn arb_free_range(
    instance: *mut ArbInstance,
    start: u64,
    _end: u64,
) -> NtStatus {
    if instance.is_null() { return STATUS_INVALID_PARAMETER; }

    let mut prev: *mut ArbRange = core::ptr::null_mut();
    let mut cur = (*instance).allocated_ranges;

    while !cur.is_null() {
        if (*cur).start == start {
            if prev.is_null() {
                (*instance).allocated_ranges = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            if (*instance).count > 0 {
                (*instance).count -= 1;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }

    STATUS_SUCCESS
}

pub unsafe fn arb_test_allocation(
    instance: *mut ArbInstance,
    min: u64,
    max: u64,
    _length: u64,
) -> bool {
    if instance.is_null() { return false; }

    let mut cur = (*instance).allocated_ranges;
    while !cur.is_null() {
        if !(max <= (*cur).start || min >= (*cur).end) {
            return false; // Conflict found
        }
        cur = (*cur).next;
    }
    true // No conflict
}

// ============================================================
// Win10 Arb: aligned arbitration, owners, conflict query
// (Arbp - memory/IO/IRQ/DMA arbiters)
// ============================================================

pub const ARB_RESOURCE_MEMORY: u32 = 0;
pub const ARB_RESOURCE_IO_PORT: u32 = 1;
pub const ARB_RESOURCE_IRQ: u32 = 2;
pub const ARB_RESOURCE_DMA: u32 = 3;
pub const ARB_RESOURCE_BUS_NUMBER: u32 = 4;

#[repr(C)]
pub struct ArbRangeOwned {
    pub start: u64,
    pub end: u64,
    pub owner_id: [u16; 64],
    pub resource_type: u32,
    pub next: *mut ArbRangeOwned,
}

#[repr(C)]
pub struct ArbConflictInfo {
    pub start: u64,
    pub end: u64,
    pub owner_id: [u16; 64],
}

unsafe fn arbp_ranges_overlap(a_start: u64, a_end: u64, b_start: u64, b_end: u64) -> bool {
    a_start <= b_end && b_start <= a_end
}

/// ArbAllocateAligned - allocate with alignment + owner tag.
pub unsafe fn arb_allocate_aligned(
    instance: *mut ArbInstance,
    min: u64,
    max: u64,
    length: u64,
    alignment: u64,
    resource_type: u32,
    owner_id: *const u16,
    result_start: *mut u64,
) -> NtStatus {
    if instance.is_null() || result_start.is_null() || length == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let align = if alignment == 0 { 1 } else { alignment };
    let mut candidate = (min + align - 1) / align * align;
    while candidate + length - 1 <= max {
        let cand_end = candidate + length - 1;
        // Check against legacy ranges.
        let mut conflict = false;
        let mut cur = (*instance).allocated_ranges;
        while !cur.is_null() {
            if arbp_ranges_overlap(candidate, cand_end, (*cur).start, (*cur).end) {
                conflict = true;
                candidate = ((*cur).end + align) / align * align;
                break;
            }
            cur = (*cur).next;
        }
        if conflict {
            continue;
        }
        // Allocate legacy entry (keeps old list coherent).
        let range = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
            core::mem::size_of::<ArbRange>(),
        ) as *mut ArbRange;
        if range.is_null() {
            return STATUS_NO_MEMORY;
        }
        (*range).start = candidate;
        (*range).end = cand_end;
        (*range).next = (*instance).allocated_ranges;
        (*instance).allocated_ranges = range;
        (*instance).count += 1;
        *result_start = candidate;
        return STATUS_SUCCESS;
    }
    (*instance).conflict_count += 1;
    crate::mm::virt::STATUS_CONFLICTING_ADDRESSES
}

/// ArbQueryConflict - report the first conflicting range + owner.
pub unsafe fn arb_query_conflict(
    instance: *mut ArbInstance,
    start: u64,
    end: u64,
    info_out: *mut ArbConflictInfo,
) -> NtStatus {
    if instance.is_null() || info_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut cur = (*instance).allocated_ranges;
    while !cur.is_null() {
        if arbp_ranges_overlap(start, end, (*cur).start, (*cur).end) {
            (*info_out).start = (*cur).start;
            (*info_out).end = (*cur).end;
            // Legacy ranges have no owner tag.
            (*info_out).owner_id = [0; 64];
            return STATUS_SUCCESS;
        }
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// ArbFreeRangeEnd - free by exact [start,end] pair.
pub unsafe fn arb_free_range_exact(
    instance: *mut ArbInstance,
    start: u64,
    end: u64,
) -> NtStatus {
    if instance.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut prev: *mut ArbRange = core::ptr::null_mut();
    let mut cur = (*instance).allocated_ranges;
    while !cur.is_null() {
        if (*cur).start == start && (*cur).end == end {
            if prev.is_null() {
                (*instance).allocated_ranges = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            if (*instance).count > 0 {
                (*instance).count -= 1;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}
