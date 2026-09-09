/// Wer - Windows Error Reporting (Wer)
use core::ffi::c_void;
use crate::types::*;

pub struct WerReport {
    pub report_id: u32,
    pub report_type: u32,
    pub process_id: u32,
    pub thread_id: u32,
    pub exception_code: u32,
    pub flags: u32,
    pub bucket_id: [u16; 128],
}

pub unsafe fn wer_init() -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn wer_report_create(
    _report_type: u32,
    _exception_code: u32,
    _exception_record: *mut c_void,
    _report: *mut *mut WerReport,
) -> NtStatus {
    let report = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<WerReport>()) as *mut WerReport;
    if report.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::write_bytes(report as *mut u8, 0, core::mem::size_of::<WerReport>());
    (*report).exception_code = _exception_code;
    (*report).process_id = 0;
    *_report = report;
    STATUS_SUCCESS
}

pub unsafe fn wer_report_submit(
    _report: *mut WerReport,
    _consent: u32,
    _flags: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn wer_store_open(
    _store_path: *const u16,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 WER: fault buckets, consent, report queue, critical
// process handling (Wer/Werp)
// ============================================================

pub const WER_REPORT_TYPE_CRASH: u32 = 1;
pub const WER_REPORT_TYPE_HANG: u32 = 2;
pub const WER_REPORT_TYPE_KERNEL: u32 = 3;
pub const WER_REPORT_TYPE_GENERIC: u32 = 4;

pub const WER_CONSENT_NOT_ASKED: u32 = 1;
pub const WER_CONSENT_APPROVED: u32 = 2;
pub const WER_CONSENT_DENIED: u32 = 3;
pub const WER_CONSENT_ALWAYS_PROMPT: u32 = 4;

pub const WER_SUBMIT_QUEUE: u32 = 0x01;
pub const WER_SUBMIT_OUT_OF_PROCESS: u32 = 0x02;
pub const WER_SUBMIT_NO_CLOSE_UI: u32 = 0x04;
pub const WER_SUBMIT_NO_ARCHIVE: u32 = 0x08;
pub const WER_SUBMIT_HONOR_RESTART: u32 = 0x10;

pub const WER_MAX_PARAMETERS: usize = 10;
pub const WER_MAX_BUCKET_ID: usize = 64;

#[repr(C)]
pub struct WerReportFull {
    pub base: WerReport,
    pub report_type: u32,
    pub consent: u32,
    pub flags: u32,
    pub parameters: [[u16; 64]; WER_MAX_PARAMETERS],
    pub parameter_count: u32,
    pub bucket_hash: u32,
    pub queued_time: u64,
    pub next: *mut WerReportFull,
}

static mut WER_QUEUE_HEAD: *mut WerReportFull = core::ptr::null_mut();
static mut WER_QUEUE_TAIL: *mut WerReportFull = core::ptr::null_mut();
static WER_QUEUE_COUNT: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(0);
static WER_NEXT_REPORT_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);

/// WerpHashBucket - FNV-1a bucket hash over module+offset+code.
pub unsafe fn wer_hash_bucket(
    module_name: *const u16,
    fault_offset: u64,
    exception_code: u32,
) -> u32 {
    let mut hash = 0x811C9DC5u32;
    if !module_name.is_null() {
        let mut i = 0;
        while *module_name.add(i) != 0 && i < 128 {
            let ch = crate::nls::nls_upcase_full(*module_name.add(i));
            hash ^= (ch & 0xFF) as u32;
            hash = hash.wrapping_mul(0x01000193);
            hash ^= (ch >> 8) as u32;
            hash = hash.wrapping_mul(0x01000193);
            i += 1;
        }
    }
    let mut b = fault_offset.to_le_bytes();
    let mut i = 0;
    while i < 8 {
        hash ^= b[i] as u32;
        hash = hash.wrapping_mul(0x01000193);
        i += 1;
    }
    b = (exception_code as u64).to_le_bytes();
    i = 0;
    while i < 4 {
        hash ^= b[i] as u32;
        hash = hash.wrapping_mul(0x01000193);
        i += 1;
    }
    hash
}

/// WerReportCreateFull - create a queued report with parameters.
pub unsafe fn wer_report_create_full(
    report_type: u32,
    process_id: u32,
    thread_id: u32,
    exception_code: u32,
    module_name: *const u16,
    fault_offset: u64,
    report_out: *mut *mut WerReportFull,
) -> NtStatus {
    if report_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let r = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<WerReportFull>(),
    ) as *mut WerReportFull;
    if r.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(r as *mut u8, 0, core::mem::size_of::<WerReportFull>());
    (*r).base.report_id =
        WER_NEXT_REPORT_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*r).base.report_type = report_type;
    (*r).base.process_id = process_id;
    (*r).base.thread_id = thread_id;
    (*r).base.exception_code = exception_code;
    (*r).report_type = report_type;
    (*r).consent = WER_CONSENT_NOT_ASKED;
    (*r).bucket_hash = wer_hash_bucket(module_name, fault_offset, exception_code);
    (*r).queued_time = unsafe { crate::ke::profile::ke_query_system_time() };
    *report_out = r;
    STATUS_SUCCESS
}

/// WerReportAddParameter - attach a WER_P0..P9 string parameter.
pub unsafe fn wer_report_add_parameter(
    report: *mut WerReportFull,
    value: *const u16,
) -> NtStatus {
    if report.is_null() || value.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*report).parameter_count >= WER_MAX_PARAMETERS as u32 {
        return STATUS_BUFFER_OVERFLOW;
    }
    let idx = (*report).parameter_count as usize;
    let mut i = 0;
    while i < 63 && *value.add(i) != 0 {
        (*report).parameters[idx][i] = *value.add(i);
        i += 1;
    }
    (*report).parameters[idx][i] = 0;
    (*report).parameter_count += 1;
    STATUS_SUCCESS
}

/// WerReportSubmitFull - enqueue the report for the WER service.
pub unsafe fn wer_report_submit_full(
    report: *mut WerReportFull,
    consent: u32,
    flags: u32,
) -> NtStatus {
    if report.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    (*report).consent = consent;
    (*report).flags = flags;
    (*report).next = core::ptr::null_mut();
    if WER_QUEUE_TAIL.is_null() {
        WER_QUEUE_HEAD = report;
        WER_QUEUE_TAIL = report;
    } else {
        (*WER_QUEUE_TAIL).next = report;
        WER_QUEUE_TAIL = report;
    }
    WER_QUEUE_COUNT.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    // Critical processes take the system down immediately.
    if (*report).report_type == WER_REPORT_TYPE_KERNEL {
        unsafe {
            crate::ke::bugcheck::ke_bug_check_ex(
                crate::ke::bugcheck::CRITICAL_PROCESS_DIED,
                (*report).base.process_id as u64,
                (*report).base.exception_code as u64,
                (*report).bucket_hash as u64,
                0,
            );
        }
    }
    STATUS_SUCCESS
}

/// WerDequeueReport - pull the oldest queued report (for werfault).
pub unsafe fn wer_dequeue_report(report_out: *mut *mut WerReportFull) -> NtStatus {
    if report_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if WER_QUEUE_HEAD.is_null() {
        *report_out = core::ptr::null_mut();
        return STATUS_NO_MORE_ENTRIES;
    }
    let r = WER_QUEUE_HEAD;
    WER_QUEUE_HEAD = (*r).next;
    if WER_QUEUE_HEAD.is_null() {
        WER_QUEUE_TAIL = core::ptr::null_mut();
    }
    (*r).next = core::ptr::null_mut();
    WER_QUEUE_COUNT.fetch_sub(1, core::sync::atomic::Ordering::Relaxed);
    *report_out = r;
    STATUS_SUCCESS
}

/// WerFreeReport - release a dequeued report.
pub unsafe fn wer_free_report(report: *mut WerReportFull) {
    if !report.is_null() {
        crate::mm::pool::ex_free_pool(report as *mut c_void);
    }
}

/// WerQueueCount - pending report count.
pub fn wer_queue_count() -> u32 {
    WER_QUEUE_COUNT.load(core::sync::atomic::Ordering::Relaxed)
}
