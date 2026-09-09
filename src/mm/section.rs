//! # Section Objects
//!
//! Section creation, mapping, and management matching ntoskrnl.exe's
//! section object and control area structures.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU32, AtomicU64, Ordering};

use crate::types::*;
use super::{
    MmPteFlags, MmProtectionMask2, ListEntry, SpinLock, PoolTag, TAG_MMSO,
    SEC_COMMIT, SEC_RESERVE, SEC_IMAGE, SEC_FILE,
    PAGE_SHIFT, PAGE_SIZE_X64,
    mi_get_pfn_element, mm_warn, mm_dbg, mm_err,
};

// ============================================================
// Section flags
// ============================================================

pub const MM_SECTION_FLAGS_COMMIT: u32 = 0x00000001;
pub const MM_SECTION_FLAGS_RESERVE: u32 = 0x00000002;
pub const MM_SECTION_FLAGS_IMAGE: u32 = 0x00000004;
pub const MM_SECTION_FLAGS_FILE: u32 = 0x00000008;
pub const MM_SECTION_FLAGS_SYNC: u32 = 0x00000010;

pub const MM_DATA_SEGMENT: u32 = 0;
pub const MM_CODE_SEGMENT: u32 = 1;
pub const MM_SHARED_SEGMENT: u32 = 2;

// ============================================================
// ControlArea (per-file mapping descriptor)
// ============================================================

#[repr(C)]
pub struct ControlArea {
    pub file_object: *mut c_void,
    pub starting_vpn: u64,
    pub ending_vpn: u64,
    pub parent: *mut c_void,
    pub left_child: *mut c_void,
    pub right_child: *mut c_void,
    pub creating_process: *mut c_void,
    pub charge: i64,
    pub number_of_references: u32,
    pub segment_flags: u32,
    pub number_of_mapped_views: u32,
    pub flush_in_progress: u16,
    pub modified_write_count: u16,
    pub control_area_lock: SpinLock,
    pub proto_area: ListEntry,
    pub flags: u32,
}

impl ControlArea {
    pub fn new() -> Self {
        Self {
            file_object: core::ptr::null_mut(),
            starting_vpn: 0,
            ending_vpn: 0,
            parent: core::ptr::null_mut(),
            left_child: core::ptr::null_mut(),
            right_child: core::ptr::null_mut(),
            creating_process: core::ptr::null_mut(),
            charge: 0,
            number_of_references: 0,
            segment_flags: 0,
            number_of_mapped_views: 0,
            flush_in_progress: 0,
            modified_write_count: 0,
            control_area_lock: SpinLock::new(),
            proto_area: ListEntry::new(),
            flags: 0,
        }
    }
}

// ============================================================
// SegmentObject
// ============================================================

#[repr(C)]
pub struct SegmentObject {
    pub starting_vpn: u64,
    pub ending_vpn: u64,
    pub parent: *mut c_void,
    pub left_child: *mut c_void,
    pub right_child: *mut c_void,
    pub creating_process: *mut c_void,
    pub lock: SpinLock,
    pub control_area: *mut ControlArea,
    pub flags: u32,
}

impl SegmentObject {
    pub fn new() -> Self {
        Self {
            starting_vpn: 0,
            ending_vpn: 0,
            parent: core::ptr::null_mut(),
            left_child: core::ptr::null_mut(),
            right_child: core::ptr::null_mut(),
            creating_process: core::ptr::null_mut(),
            lock: SpinLock::new(),
            control_area: core::ptr::null_mut(),
            flags: 0,
        }
    }
}

// ============================================================
// SectionObject
// ============================================================

#[repr(C)]
pub struct SectionObject {
    pub data_segments: [*mut SegmentObject; 4],
    pub control_area: *mut ControlArea,
    pub file_object: *mut c_void,
    pub size: u64,
    pub section_flags: u32,
    pub initial_data_pfn: u64,
    pub lock: SpinLock,
}

impl SectionObject {
    pub fn new() -> Self {
        Self {
            data_segments: [core::ptr::null_mut(); 4],
            control_area: core::ptr::null_mut(),
            file_object: core::ptr::null_mut(),
            size: 0,
            section_flags: 0,
            initial_data_pfn: 0,
            lock: SpinLock::new(),
        }
    }
}

// ============================================================
// Section view
// ============================================================

#[repr(C)]
pub struct SectionView {
    pub view_links: ListEntry,
    pub process: *mut c_void,
    pub address: *mut c_void,
    pub size: u64,
    pub protection: MmProtectionMask2,
    pub flags: u32,
    pub offset: u64,
}

impl SectionView {
    pub fn new() -> Self {
        Self {
            view_links: ListEntry::new(),
            process: core::ptr::null_mut(),
            address: core::ptr::null_mut(),
            size: 0,
            protection: MmProtectionMask2::empty(),
            flags: 0,
            offset: 0,
        }
    }
}

// ============================================================
// MmCreateSection
// ============================================================

pub fn mm_create_section(
    section: *mut *mut SectionObject,
    desired_access: u32,
    object_attributes: *mut c_void,
    maximum_size: u64,
    protection: u32,
    allocation_attributes: u32,
    file_handle: *mut c_void,
) -> NtStatus {
    if section.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let section_obj = super::pool::mm_allocate_pool_nonpaged(
        core::mem::size_of::<SectionObject>(),
        TAG_MMSO,
    ) as *mut SectionObject;

    if section_obj.is_null() {
        return STATUS_NO_MEMORY;
    }

    unsafe {
        core::ptr::write_bytes(section_obj as *mut u8, 0, core::mem::size_of::<SectionObject>());

        (*section_obj).size = maximum_size;
        (*section_obj).file_object = file_handle;
        (*section_obj).section_flags = allocation_attributes;

        // Create control area
        let control_area = super::pool::mm_allocate_pool_nonpaged(
            core::mem::size_of::<ControlArea>(),
            TAG_MMSO,
        ) as *mut ControlArea;

        if control_area.is_null() {
            super::pool::mm_free_pool(section_obj as *mut c_void, TAG_MMSO);
            return STATUS_NO_MEMORY;
        }

        core::ptr::write_bytes(control_area as *mut u8, 0, core::mem::size_of::<ControlArea>());
        (*control_area).file_object = file_handle;

        if allocation_attributes & SEC_COMMIT != 0 {
            (*control_area).segment_flags = MM_SECTION_FLAGS_COMMIT;
        } else if allocation_attributes & SEC_RESERVE != 0 {
            (*control_area).segment_flags = MM_SECTION_FLAGS_RESERVE;
        }

        if allocation_attributes & SEC_IMAGE != 0 {
            (*control_area).segment_flags |= MM_SECTION_FLAGS_IMAGE;
        } else if allocation_attributes & SEC_FILE != 0 {
            (*control_area).segment_flags |= MM_SECTION_FLAGS_FILE;
        }

        // Create segments
        let page_count = (maximum_size + PAGE_SIZE_X64 as u64 - 1) / PAGE_SIZE_X64 as u64;

        for i in 0..4 {
            let segment = super::pool::mm_allocate_pool_nonpaged(
                core::mem::size_of::<SegmentObject>(),
                TAG_MMSO,
            ) as *mut SegmentObject;

            if !segment.is_null() {
                core::ptr::write_bytes(segment as *mut u8, 0, core::mem::size_of::<SegmentObject>());
                (*segment).starting_vpn = 0;
                (*segment).ending_vpn = page_count;
                (*segment).control_area = control_area;
                (*section_obj).data_segments[i] = segment;
            }
        }

        (*section_obj).control_area = control_area;

        *section = section_obj;
    }

    mm_trace!("MmCreateSection: created section {:p} size={:#x}", section_obj, maximum_size);
    STATUS_SUCCESS
}

// ============================================================
// MmCreateSectionEx (extended version)
// ============================================================

pub fn mm_create_section_ex(
    section: *mut *mut SectionObject,
    desired_access: u32,
    object_attributes: *mut c_void,
    maximum_size: u64,
    protection: u32,
    allocation_attributes: u32,
    file_handle: *mut c_void,
    extended_parameters: *mut c_void,
    parameter_count: u32,
) -> NtStatus {
    mm_create_section(
        section, desired_access, object_attributes,
        maximum_size, protection, allocation_attributes, file_handle,
    )
}

// ============================================================
// MmOpenSection
// ============================================================

pub fn mm_open_section(
    section_object: *mut c_void,
    desired_access: u32,
    object_attributes: *mut c_void,
) -> NtStatus {
    // In real ntoskrnl this would open/verify the section object
    STATUS_SUCCESS
}

// ============================================================
// MmExtendSection
// ============================================================

pub fn mm_extend_section(
    section_object: *mut SectionObject,
    new_size: u64,
    commit_size: u64,
) -> NtStatus {
    if section_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    unsafe {
        let _guard = (*section_object).lock.acquire();

        if new_size <= (*section_object).size {
            return STATUS_SUCCESS;
        }

        let page_count = (new_size + PAGE_SIZE_X64 as u64 - 1) / PAGE_SIZE_X64 as u64;
        let old_page_count = (*section_object).size / PAGE_SIZE_X64 as u64;

        if !(*section_object).control_area.is_null() {
            let ca = &mut *(*section_object).control_area;
            ca.ending_vpn = page_count;
        }

        (*section_object).size = new_size;

        // Update segments
        for i in 0..4 {
            if !(*section_object).data_segments[i].is_null() {
                let seg = &mut *(*section_object).data_segments[i];
                seg.ending_vpn = page_count;
            }
        }
    }

    mm_trace!("MmExtendSection: extended to {:#x}", new_size);
    STATUS_SUCCESS
}

// ============================================================
// MmQuerySection
// ============================================================

pub fn mm_query_section(
    section_object: *mut SectionObject,
    section_information_class: u32,
    section_information: *mut c_void,
    section_information_length: u32,
    return_length: *mut u32,
) -> NtStatus {
    if section_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    unsafe {
        match section_information_class {
            0 => {
                // SectionBasicInformation
                if section_information_length < core::mem::size_of::<SectionBasicInfo>() as u32 {
                    return STATUS_BUFFER_TOO_SMALL;
                }
                let info = section_information as *mut SectionBasicInfo;
                (*info).size = (*section_object).size;
                (*info).flags = (*section_object).section_flags;
            }
            1 => {
                // SectionImageInformation
                if section_information_length < core::mem::size_of::<SectionImageInfo>() as u32 {
                    return STATUS_BUFFER_TOO_SMALL;
                }
                let info = section_information as *mut SectionImageInfo;
                (*info).entry_point = 0;
                (*info).zero_bits = 0;
                (*info).image_base_address = 0;
            }
            _ => {
                return STATUS_INVALID_PARAMETER;
            }
        }

        if !return_length.is_null() {
            *return_length = core::mem::size_of::<SectionBasicInfo>() as u32;
        }
    }

    STATUS_SUCCESS
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SectionBasicInfo {
    pub flags: u32,
    pub size: u64,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SectionImageInfo {
    pub entry_point: u64,
    pub zero_bits: u32,
    pub image_base_address: u64,
    pub size_of_image: u32,
    pub characteristics: u32,
    pub signature_level: u8,
    pub signature_type: u8,
    pub unused: u16,
}

// ============================================================
// MmDeleteSection
// ============================================================

pub fn mm_delete_section(section_object: *mut SectionObject) -> NtStatus {
    if section_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    unsafe {
        let _guard = (*section_object).lock.acquire();

        // Release control area
        if !(*section_object).control_area.is_null() {
            let ca = &*(*section_object).control_area;
            if ca.number_of_references == 0 {
                super::pool::mm_free_pool((*section_object).control_area as *mut c_void, TAG_MMSO);
            }
        }

        // Release segments
        for i in 0..4 {
            if !(*section_object).data_segments[i].is_null() {
                super::pool::mm_free_pool((*section_object).data_segments[i] as *mut c_void, TAG_MMSO);
                (*section_object).data_segments[i] = core::ptr::null_mut();
            }
        }
    }

    super::pool::mm_free_pool(section_object as *mut c_void, TAG_MMSO);
    mm_trace!("MmDeleteSection: deleted section");
    STATUS_SUCCESS
}

// ============================================================
// MmFlushSection
// ============================================================

pub fn mm_flush_section(
    section_object: *mut SectionObject,
    base_address: u64,
    size: u64,
) -> NtStatus {
    if section_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    mm_trace!("MmFlushSection: flushing section {:p}", section_object);
    STATUS_SUCCESS
}

// ============================================================
// MmIsSectionLoaded
// ============================================================

pub fn mm_is_section_loaded(section_object: *mut SectionObject) -> bool {
    if section_object.is_null() {
        return false;
    }

    unsafe {
        !(*section_object).control_area.is_null()
    }
}

// ============================================================
// MmGetSectionSize
// ============================================================

pub fn mm_get_section_size(section_object: *mut SectionObject) -> u64 {
    if section_object.is_null() {
        return 0;
    }
    unsafe { (*section_object).size }
}
