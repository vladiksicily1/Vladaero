/// Whea - Windows Hardware Error Architecture (Whea/Wheap)
use core::ffi::c_void;
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

pub const WHEA_ERROR_RECORD_SIGNATURE: u32 = 0x45484543; // "EHEC"

#[repr(C)]
pub struct WheaErrorRecordHeader {
    pub signature: u32,
    pub revision: u16,
    pub signature_end: u16,
    pub section_count: u32,
    pub severity: u32,
    pub valid_bits: u32,
    pub length: u32,
    pub timestamp: u64,
    pub platform_id: [u8; 16],
    pub partition_id: [u8; 16],
    pub creator_id: [u8; 16],
    pub notify_type: [u8; 16],
    pub record_id: u64,
    pub flags: u32,
    pub persistence_information: u32,
    pub reserved: [u8; 12],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct WheaErrorRecordSectionDescriptor {
    pub section_offset: u32,
    pub section_length: u32,
    pub reserved1: u64,
    pub section_flags: u32,
    pub section_type: [u8; 16],
    pub fru_id: [u8; 16],
    pub fru_text: [u8; 20],
}

#[repr(C)]
pub struct WheaErrorRecord {
    pub header: WheaErrorRecordHeader,
    pub descriptors: [WheaErrorRecordSectionDescriptor; 4],
}

#[repr(C)]
pub struct WheaErrorSourceDescriptor {
    pub source_id: u32,
    pub error_source_id: u32,
    pub status: u32,
    pub flags: u32,
    pub max_sections: u32,
    pub creator_id: [u8; 16],
    pub notification_type: [u8; 16],
    pub descriptor_count: u32,
    pub descriptors: [WheaErrorRecordSectionDescriptor; 4],
}

pub struct WheaErrorSourceEntry {
    pub descriptor: WheaErrorSourceDescriptor,
    pub active: bool,
    pub next: *mut WheaErrorSourceEntry,
}

static mut ERROR_SOURCE_LIST: *mut WheaErrorSourceEntry = core::ptr::null_mut();

pub unsafe fn whea_initialize() -> NtStatus {
    ERROR_SOURCE_LIST = core::ptr::null_mut();
    STATUS_SUCCESS
}

pub unsafe fn whea_report_hw_error(
    record: *mut WheaErrorRecord,
) -> NtStatus {
    if record.is_null() { return STATUS_INVALID_PARAMETER; }
    let r = &mut *record;

    // Log the error
    crate::kernel_log!("[WHEA] Hardware error reported: severity={}\n", r.header.severity);

    STATUS_SUCCESS
}

pub unsafe fn whea_register_error_source(
    descriptor: *mut WheaErrorSourceDescriptor,
) -> NtStatus {
    if descriptor.is_null() { return STATUS_INVALID_PARAMETER; }

    let entry = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<WheaErrorSourceEntry>()) as *mut WheaErrorSourceEntry;
    if entry.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::copy_nonoverlapping(descriptor, &mut (*entry).descriptor, 1);
    (*entry).active = true;
    (*entry).next = ERROR_SOURCE_LIST;
    ERROR_SOURCE_LIST = entry;

    STATUS_SUCCESS
}

pub unsafe fn whea_unregister_error_source(source_id: u32) -> NtStatus {
    let mut prev: *mut WheaErrorSourceEntry = core::ptr::null_mut();
    let mut cur = ERROR_SOURCE_LIST;

    while !cur.is_null() {
        if (*cur).descriptor.source_id == source_id {
            if prev.is_null() {
                ERROR_SOURCE_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }

    STATUS_OBJECT_NAME_NOT_FOUND
}

pub unsafe fn whea_init_processor_err_src(_processor: u32) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 WHEA: error record creation, bugcheck hook, injection
// ============================================================

pub const WHEA_SEVERITY_RECOVERABLE: u32 = 0;
pub const WHEA_SEVERITY_FATAL: u32 = 1;
pub const WHEA_SEVERITY_CORRECTED: u32 = 2;
pub const WHEA_SEVERITY_INFORMATIONAL: u32 = 3;

pub const WHEA_SOURCE_MACHINE_CHECK: u32 = 0;
pub const WHEA_SOURCE_CORRECTED_MCE: u32 = 1;
pub const WHEA_SOURCE_NMI: u32 = 3;
pub const WHEA_SOURCE_PCIE_AER: u32 = 6;

static WHEA_NEXT_RECORD_ID: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(1);
static mut WHEA_LAST_BUGCHECK_RECORD: WheaErrorRecord = WheaErrorRecord {
    header: WheaErrorRecordHeader {
        signature: WHEA_ERROR_RECORD_SIGNATURE,
        revision: 0,
        signature_end: 0,
        section_count: 0,
        severity: 0,
        valid_bits: 0,
        length: 0,
        timestamp: 0,
        platform_id: [0; 16],
        partition_id: [0; 16],
        creator_id: [0; 16],
        notify_type: [0; 16],
        record_id: 0,
        flags: 0,
        persistence_information: 0,
        reserved: [0; 12],
    },
    descriptors: [WheaErrorRecordSectionDescriptor {
        section_offset: 0,
        section_length: 0,
        reserved1: 0,
        section_flags: 0,
        section_type: [0; 16],
        fru_id: [0; 16],
        fru_text: [0; 20],
    }; 4],
};

/// WheaCreateRecord - build a fatal error record for a bugcheck.
pub unsafe fn whea_create_record(
    severity: u32,
    notify_type: *const u8,
    record_out: *mut WheaErrorRecord,
) -> NtStatus {
    if record_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    core::ptr::write_bytes(record_out as *mut u8, 0, core::mem::size_of::<WheaErrorRecord>());
    (*record_out).header.signature = WHEA_ERROR_RECORD_SIGNATURE;
    (*record_out).header.revision = 0x0201;
    (*record_out).header.severity = severity;
    (*record_out).header.length = core::mem::size_of::<WheaErrorRecord>() as u32;
    (*record_out).header.record_id =
        WHEA_NEXT_RECORD_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    if !notify_type.is_null() {
        core::ptr::copy_nonoverlapping(
            notify_type,
            (*record_out).header.notify_type.as_mut_ptr(),
            16,
        );
    }
    STATUS_SUCCESS
}

/// WheaBugcheckNotify - called by KeBugCheckEx (must not fault).
pub unsafe fn whea_bugcheck_notify(bugcheck_code: u32) {
    // Snapshot a minimal record for the crash dump header path.
    WHEA_LAST_BUGCHECK_RECORD.header.signature = WHEA_ERROR_RECORD_SIGNATURE;
    WHEA_LAST_BUGCHECK_RECORD.header.severity = WHEA_SEVERITY_FATAL;
    WHEA_LAST_BUGCHECK_RECORD.header.record_id =
        WHEA_NEXT_RECORD_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    // Encode the bugcheck code in the first section length field
    // (crash-dump visible, no allocation allowed here).
    WHEA_LAST_BUGCHECK_RECORD.descriptors[0].section_length = bugcheck_code;
    // Deliver to registered error sources (best effort).
    let mut cur = ERROR_SOURCE_LIST;
    while !cur.is_null() {
        if (*cur).active {
            (*cur).descriptor.status = bugcheck_code;
        }
        cur = (*cur).next;
    }
}

/// WheaLastBugcheckRecord - pointer for the dump path.
pub unsafe fn whea_last_bugcheck_record() -> *mut WheaErrorRecord {
    &mut WHEA_LAST_BUGCHECK_RECORD as *mut WheaErrorRecord
}
