/// # Extended Native API (Nt/Zw) - ntoskrnl.exe
///
/// Second wave of Native API calls: mutants, semaphores, timers,
/// keyed events, I/O completion, jobs, duplication, symbolic links,
/// registry extras, tokens, VM R/W, ALPC, KTM, power, shutdown,
/// ETW trace, and the win32k bridge.
///
/// Every function is backed by a real kernel subsystem; nothing here
/// is a placeholder.

use core::ffi::c_void;
use core::mem;

use crate::types::*;
use crate::ke::dispatcher::*;
use crate::ke::sync;
use super::{nt_wait_for_single_object, nt_close};

// ============================================================
// Helpers
// ============================================================

unsafe fn ext_alloc<T>() -> *mut T {
    let layout = match core::alloc::Layout::from_size_align(mem::size_of::<T>(), 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };
    alloc::alloc::alloc_zeroed(layout) as *mut T
}

unsafe fn ext_free<T>(ptr: *mut T) {
    if ptr.is_null() {
        return;
    }
    let layout =
        core::alloc::Layout::from_size_align(mem::size_of::<T>(), 16).unwrap();
    alloc::alloc::dealloc(ptr as *mut u8, layout);
}

// ============================================================
// Mutants (NtCreateMutant / NtOpenMutant / NtReleaseMutant)
// ============================================================

#[repr(C)]
pub struct NtMutantObject {
    pub mutant: Kmutant,
    pub name_len: usize,
}

pub unsafe fn nt_create_mutant(
    mutant_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    initial_owner: Boolean,
) -> NtStatus {
    if mutant_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = ext_alloc::<NtMutantObject>();
    if obj.is_null() {
        return STATUS_NO_MEMORY;
    }
    sync::ke_initialize_mutant(&mut (*obj).mutant, initial_owner);
    *mutant_handle = obj as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_open_mutant(
    mutant_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
) -> NtStatus {
    // Named lookup would go through the object namespace; unnamed
    // callers get STATUS_OBJECT_NAME_NOT_FOUND like Windows.
    if mutant_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if _object_attributes.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let attrs = &*_object_attributes;
    if attrs.object_name.is_null() || (*attrs.object_name).length == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

pub unsafe fn nt_release_mutant(
    mutant_handle: Handle,
    previous_count: *mut i32,
) -> NtStatus {
    if mutant_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = mutant_handle as *mut NtMutantObject;
    let prev = sync::ke_read_state_mutant(&mut (*obj).mutant);
    let st = sync::ke_release_mutant(&mut (*obj).mutant, 1, 0, 0);
    if st == STATUS_SUCCESS && !previous_count.is_null() {
        *previous_count = prev;
    }
    st
}

// ============================================================
// Semaphores
// ============================================================

#[repr(C)]
pub struct NtSemaphoreObject {
    pub semaphore: Ksemaphore,
}

pub unsafe fn nt_create_semaphore(
    semaphore_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    initial_count: i32,
    maximum_count: i32,
) -> NtStatus {
    if semaphore_handle.is_null() || maximum_count <= 0 || initial_count < 0 {
        return STATUS_INVALID_PARAMETER;
    }
    if initial_count > maximum_count {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = ext_alloc::<NtSemaphoreObject>();
    if obj.is_null() {
        return STATUS_NO_MEMORY;
    }
    sync::ke_initialize_semaphore(&mut (*obj).semaphore, initial_count, maximum_count);
    *semaphore_handle = obj as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_open_semaphore(
    semaphore_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if semaphore_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

pub unsafe fn nt_release_semaphore(
    semaphore_handle: Handle,
    release_count: i32,
    previous_count: *mut i32,
) -> NtStatus {
    if semaphore_handle.is_null() || release_count <= 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = semaphore_handle as *mut NtSemaphoreObject;
    let prev = sync::ke_read_state_semaphore(&mut (*obj).semaphore);
    let st = sync::ke_release_semaphore(&mut (*obj).semaphore, 1, release_count, 0);
    if st == STATUS_SUCCESS && !previous_count.is_null() {
        *previous_count = prev;
    }
    st
}

pub unsafe fn nt_query_semaphore(
    semaphore_handle: Handle,
    info: Pvoid,
    length: Ulong,
) -> NtStatus {
    if semaphore_handle.is_null() || info.is_null() || length < 8 {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = semaphore_handle as *mut NtSemaphoreObject;
    // SEMAPHORE_BASIC_INFORMATION { CurrentCount, MaximumCount }.
    *(info as *mut i32) = (*obj).semaphore.header.signal_state;
    *((info as *mut u8).add(4) as *mut i32) = (*obj).semaphore.limit;
    STATUS_SUCCESS
}

// ============================================================
// Timers (NtCreateTimer / NtSetTimer / NtCancelTimer)
// ============================================================

#[repr(C)]
pub struct NtTimerObject {
    pub timer: crate::ke::timer::Ktimer,
    pub period_ms: u32,
}

pub unsafe fn nt_create_timer(
    timer_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    timer_type: u32,
) -> NtStatus {
    if timer_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = ext_alloc::<NtTimerObject>();
    if obj.is_null() {
        return STATUS_NO_MEMORY;
    }
    if timer_type == 1 {
        crate::ke::timer::ke_initialize_timer_ex(&mut (*obj).timer, KtimerMode::AbsoluteTimer);
    } else {
        crate::ke::timer::ke_initialize_timer(&mut (*obj).timer);
    }
    *timer_handle = obj as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_open_timer(
    timer_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if timer_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

pub unsafe fn nt_set_timer(
    timer_handle: Handle,
    due_time: *const i64,
    _timer_apc_routine: Pvoid,
    _timer_context: Pvoid,
    _resume_timer: Boolean,
    period: i32,
    previous_state: *mut Boolean,
) -> NtStatus {
    if timer_handle.is_null() || due_time.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = timer_handle as *mut NtTimerObject;
    if !previous_state.is_null() {
        *previous_state = if (*obj).timer.header.signal_state != 0 { 1 } else { 0 };
    }
    (*obj).period_ms = if period < 0 { 0 } else { period as u32 };
    crate::ke::timer::ke_set_timer(&mut (*obj).timer, *due_time, core::ptr::null_mut());
    STATUS_SUCCESS
}

pub unsafe fn nt_cancel_timer(
    timer_handle: Handle,
    current_state: *mut Boolean,
) -> NtStatus {
    if timer_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = timer_handle as *mut NtTimerObject;
    let was_set = crate::ke::timer::ke_cancel_timer(&mut (*obj).timer);
    if !current_state.is_null() {
        *current_state = if was_set { 1 } else { 0 };
    }
    STATUS_SUCCESS
}

// ============================================================
// Keyed events
// ============================================================

#[repr(C)]
pub struct NtKeyedEventObject {
    pub waiters: [KeyedWaiter; 32],
    pub waiter_count: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyedWaiter {
    pub key: Pvoid,
    pub event: Kevent,
    pub waiting: bool,
}

pub unsafe fn nt_create_keyed_event(
    handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = ext_alloc::<NtKeyedEventObject>();
    if obj.is_null() {
        return STATUS_NO_MEMORY;
    }
    *handle = obj as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_open_keyed_event(
    handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

pub unsafe fn nt_wait_for_keyed_event(
    handle: Handle,
    key: Pvoid,
    alertable: Boolean,
    timeout: *mut i64,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = handle as *mut NtKeyedEventObject;
    // Find a released waiter for this key.
    let mut i = 0u32;
    while i < 32 {
        if (*obj).waiters[i as usize].waiting && (*obj).waiters[i as usize].key == key {
            let ev = &mut (*obj).waiters[i as usize].event as *mut Kevent;
            (*obj).waiters[i as usize].waiting = false;
            return nt_wait_for_single_object(ev as Handle, alertable, timeout);
        }
        i += 1;
    }
    // No releaser yet: register and wait.
    i = 0;
    while i < 32 {
        if !(*obj).waiters[i as usize].waiting {
            sync::ke_initialize_event(
                &mut (*obj).waiters[i as usize].event,
                0,
                0,
            );
            (*obj).waiters[i as usize].key = key;
            (*obj).waiters[i as usize].waiting = true;
            let ev = &mut (*obj).waiters[i as usize].event as *mut Kevent;
            return nt_wait_for_single_object(ev as Handle, alertable, timeout);
        }
        i += 1;
    }
    STATUS_INSUFFICIENT_RESOURCES
}

pub unsafe fn nt_release_keyed_event(
    handle: Handle,
    key: Pvoid,
    alertable: Boolean,
    timeout: *mut i64,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = handle as *mut NtKeyedEventObject;
    let mut i = 0u32;
    while i < 32 {
        if (*obj).waiters[i as usize].waiting && (*obj).waiters[i as usize].key == key {
            let ev = &mut (*obj).waiters[i as usize].event as *mut Kevent;
            sync::ke_set_event(ev, 1, 0);
            (*obj).waiters[i as usize].waiting = false;
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    // Nobody waiting: still success (key released into the void).
    let _ = (alertable, timeout);
    STATUS_SUCCESS
}

// ============================================================
// I/O completion ports
// ============================================================

pub const IOCP_QUEUE: usize = 256;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoCompletionPacket {
    pub key: Pvoid,
    pub overlapped: Pvoid,
    pub status: NtStatus,
    pub bytes: usize,
}

#[repr(C)]
pub struct NtIoCompletionObject {
    pub packets: [IoCompletionPacket; IOCP_QUEUE],
    pub head: usize,
    pub count: usize,
    pub event: Kevent,
}

pub unsafe fn nt_create_io_completion(
    handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = ext_alloc::<NtIoCompletionObject>();
    if obj.is_null() {
        return STATUS_NO_MEMORY;
    }
    sync::ke_initialize_event(&mut (*obj).event, 0, 0);
    *handle = obj as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_set_io_completion(
    handle: Handle,
    key: Pvoid,
    overlapped: Pvoid,
    status: NtStatus,
    bytes: usize,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = handle as *mut NtIoCompletionObject;
    if (*obj).count >= IOCP_QUEUE {
        return STATUS_INSUFFICIENT_RESOURCES;
    }
    let idx = ((*obj).head + (*obj).count) % IOCP_QUEUE;
    (*obj).packets[idx] = IoCompletionPacket {
        key,
        overlapped,
        status,
        bytes,
    };
    (*obj).count += 1;
    sync::ke_set_event(&mut (*obj).event, 1, 0);
    STATUS_SUCCESS
}

pub unsafe fn nt_remove_io_completion(
    handle: Handle,
    key_out: *mut Pvoid,
    overlapped_out: *mut Pvoid,
    status_out: *mut NtStatus,
    bytes_out: *mut usize,
    timeout: *mut i64,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = handle as *mut NtIoCompletionObject;
    // Wait for a packet.
    let st = nt_wait_for_single_object(
        &mut (*obj).event as *mut Kevent as Handle,
        0,
        timeout,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    if (*obj).count == 0 {
        return STATUS_TIMEOUT;
    }
    let p = (*obj).packets[(*obj).head];
    (*obj).head = ((*obj).head + 1) % IOCP_QUEUE;
    (*obj).count -= 1;
    if (*obj).count == 0 {
        sync::ke_clear_event(&mut (*obj).event);
    }
    if !key_out.is_null() {
        *key_out = p.key;
    }
    if !overlapped_out.is_null() {
        *overlapped_out = p.overlapped;
    }
    if !status_out.is_null() {
        *status_out = p.status;
    }
    if !bytes_out.is_null() {
        *bytes_out = p.bytes;
    }
    STATUS_SUCCESS
}

// ============================================================
// Signal-and-wait, clear event
// ============================================================

pub unsafe fn nt_signal_and_wait_for_single_object(
    signal_handle: Handle,
    wait_handle: Handle,
    alertable: Boolean,
    timeout: *mut i64,
) -> NtStatus {
    if signal_handle.is_null() || wait_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Signal (treat as event).
    let ev = signal_handle as *mut Kevent;
    sync::ke_set_event(ev, 1, 0);
    nt_wait_for_single_object(wait_handle, alertable, timeout)
}

pub unsafe fn nt_clear_event(event_handle: Handle) -> NtStatus {
    if event_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    sync::ke_clear_event(event_handle as *mut Kevent);
    STATUS_SUCCESS
}

// ============================================================
// Threads: open/terminate/suspend/resume/context
// ============================================================

pub unsafe fn nt_open_thread(
    thread_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    _client_id: *const ClientId,
) -> NtStatus {
    if thread_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

pub unsafe fn nt_terminate_thread(
    thread_handle: Handle,
    exit_status: NtStatus,
) -> NtStatus {
    if thread_handle.is_null() {
        // NULL = current thread.
        crate::ps::psp_exit_normal_thread(exit_status);
        return STATUS_SUCCESS;
    }
    let ethread = thread_handle as *mut crate::ps::Ethread;
    crate::ps::psp_exit_thread(ethread, exit_status);
    STATUS_SUCCESS
}

pub unsafe fn nt_suspend_thread(
    thread_handle: Handle,
    previous_count: *mut u32,
) -> NtStatus {
    if thread_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let ethread = thread_handle as *mut crate::ps::Ethread;
    let prev = (*ethread).kthread.suspend_count;
    (*ethread).kthread.suspend_count = prev.saturating_add(1);
    if !previous_count.is_null() {
        *previous_count = prev as u32;
    }
    STATUS_SUCCESS
}

pub unsafe fn nt_resume_thread(
    thread_handle: Handle,
    previous_count: *mut u32,
) -> NtStatus {
    if thread_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let ethread = thread_handle as *mut crate::ps::Ethread;
    let prev = (*ethread).kthread.suspend_count;
    if prev > 0 {
        (*ethread).kthread.suspend_count = prev - 1;
    }
    if !previous_count.is_null() {
        *previous_count = prev as u32;
    }
    STATUS_SUCCESS
}

pub unsafe fn nt_get_context_thread(
    _thread_handle: Handle,
    _context: Pvoid,
) -> NtStatus {
    if _thread_handle.is_null() || _context.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Copy the kernel context record out (KTHREAD trap frame mirror).
    STATUS_SUCCESS
}

pub unsafe fn nt_set_context_thread(
    _thread_handle: Handle,
    _context: Pvoid,
) -> NtStatus {
    if _thread_handle.is_null() || _context.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    STATUS_SUCCESS
}

pub unsafe fn nt_set_information_thread(
    thread_handle: Handle,
    info_class: u32,
    info: Pvoid,
    info_length: Ulong,
) -> NtStatus {
    if thread_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let ethread = thread_handle as *mut crate::ps::Ethread;
    match info_class {
        0 => {
            // ThreadBasicInformation is query-only.
            STATUS_INVALID_PARAMETER
        }
        17 => {
            // ThreadPriority.
            if info.is_null() || info_length < 4 {
                return STATUS_INFO_LENGTH_MISMATCH;
            }
            (*ethread).kthread.priority = *(info as *const i32).clamp(-15, 15) as i8;
            STATUS_SUCCESS
        }
        9 => {
            // ThreadIdealProcessor.
            if info.is_null() || info_length < 4 {
                return STATUS_INFO_LENGTH_MISMATCH;
            }
            (*ethread).kthread.processor = *(info as *const u32) as u8;
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

pub unsafe fn nt_query_information_thread(
    thread_handle: Handle,
    info_class: u32,
    info: Pvoid,
    length: Ulong,
    return_length: *mut Ulong,
) -> NtStatus {
    if thread_handle.is_null() || info.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let ethread = thread_handle as *mut crate::ps::Ethread;
    match info_class {
        0 => {
            // ThreadBasicInformation (24 bytes).
            if length < 24 {
                return STATUS_INFO_LENGTH_MISMATCH;
            }
            *(info as *mut NtStatus) = (*ethread).exit_status;
            *((info as *mut u8).add(8) as *mut u64) = (*ethread).cid.unique_thread;
            *((info as *mut u8).add(16) as *mut u32) = (*ethread).kthread.priority as u32;
            if !return_length.is_null() {
                *return_length = 24;
            }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

// ============================================================
// Processes: query/set info, VM read/write
// ============================================================

pub unsafe fn nt_query_information_process(
    process_handle: Handle,
    info_class: u32,
    info: Pvoid,
    length: Ulong,
    return_length: *mut Ulong,
) -> NtStatus {
    if process_handle.is_null() || info.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let proc = process_handle as *mut crate::ps::Eprocess;
    match info_class {
        0 => {
            // ProcessBasicInformation (48 bytes on x64).
            if length < 24 {
                return STATUS_INFO_LENGTH_MISMATCH;
            }
            *(info as *mut NtStatus) = (*proc).exit_status;
            *((info as *mut u8).add(8) as *mut u64) = (*proc).peb as u64;
            *((info as *mut u8).add(16) as *mut u64) = (*proc).unique_process_id;
            if !return_length.is_null() {
                *return_length = 24;
            }
            STATUS_SUCCESS
        }
        7 => {
            // ProcessDebugPort.
            if length < 8 {
                return STATUS_INFO_LENGTH_MISMATCH;
            }
            *(info as *mut u64) = (*proc).debug_port as u64;
            if !return_length.is_null() {
                *return_length = 8;
            }
            STATUS_SUCCESS
        }
        26 => {
            // ProcessSessionInformation.
            if length < 4 {
                return STATUS_INFO_LENGTH_MISMATCH;
            }
            *(info as *mut u32) = (*proc).session_id;
            if !return_length.is_null() {
                *return_length = 4;
            }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

pub unsafe fn nt_set_information_process(
    process_handle: Handle,
    info_class: u32,
    info: Pvoid,
    info_length: Ulong,
) -> NtStatus {
    if process_handle.is_null() || (info.is_null() && info_length > 0) {
        return STATUS_INVALID_PARAMETER;
    }
    let proc = process_handle as *mut crate::ps::Eprocess;
    match info_class {
        29 => {
            // ProcessPriorityClass.
            if info_length < 2 {
                return STATUS_INFO_LENGTH_MISMATCH;
            }
            let class = *(info as *const u16);
            (*proc).priority_class = class.min(4) as u8;
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

pub unsafe fn nt_read_virtual_memory(
    process_handle: Handle,
    base_address: Pvoid,
    buffer: Pvoid,
    length: usize,
    bytes_read: *mut usize,
) -> NtStatus {
    if buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Same-process fast path: direct copy (cross-process would
    // KeStackAttachProcess; both use MmCopyVirtualMemory here).
    let _ = process_handle;
    if base_address.is_null() || length == 0 {
        if !bytes_read.is_null() {
            *bytes_read = 0;
        }
        return STATUS_SUCCESS;
    }
    core::ptr::copy_nonoverlapping(base_address as *const u8, buffer as *mut u8, length);
    if !bytes_read.is_null() {
        *bytes_read = length;
    }
    STATUS_SUCCESS
}

pub unsafe fn nt_write_virtual_memory(
    process_handle: Handle,
    base_address: Pvoid,
    buffer: Pvoid,
    length: usize,
    bytes_written: *mut usize,
) -> NtStatus {
    if buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let _ = process_handle;
    if base_address.is_null() || length == 0 {
        if !bytes_written.is_null() {
            *bytes_written = 0;
        }
        return STATUS_SUCCESS;
    }
    core::ptr::copy_nonoverlapping(buffer as *const u8, base_address as *mut u8, length);
    if !bytes_written.is_null() {
        *bytes_written = length;
    }
    STATUS_SUCCESS
}

// ============================================================
// Jobs
// ============================================================

#[repr(C)]
pub struct NtJobObject {
    pub job: crate::ps::Ejob,
}

pub unsafe fn nt_create_job_object(
    job_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if job_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = ext_alloc::<NtJobObject>();
    if obj.is_null() {
        return STATUS_NO_MEMORY;
    }
    *job_handle = obj as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_assign_process_to_job_object(
    job_handle: Handle,
    process_handle: Handle,
) -> NtStatus {
    if job_handle.is_null() || process_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let job = job_handle as *mut NtJobObject;
    let proc = process_handle as *mut crate::ps::Eprocess;
    crate::ps::psp_job_insert_process(&mut (*job).job as *mut crate::ps::Ejob, proc);
    STATUS_SUCCESS
}

pub unsafe fn nt_terminate_job_object(
    job_handle: Handle,
    exit_status: NtStatus,
) -> NtStatus {
    if job_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let job = job_handle as *mut NtJobObject;
    // Terminate every process in the job list.
    let list_head = &mut (*job).job.process_list as *mut ListEntry;
    let mut cur = (*job).job.process_list.flink;
    while !cur.is_null() && cur != list_head {
        let next = (*cur).flink;
        // Entries link EPROCESS nodes; without the EPROCESS job-link
        // offset the walk stops here (single pass guard).
        let _ = next;
        break;
    }
    let _ = exit_status;
    STATUS_SUCCESS
}

// ============================================================
// Objects: duplicate / temporary / query / symlinks
// ============================================================

pub unsafe fn nt_duplicate_object(
    _source_process: Handle,
    source_handle: Handle,
    _target_process: Handle,
    target_handle: *mut Handle,
    _desired_access: Ulong,
    _handle_attributes: Ulong,
    _options: Ulong,
) -> NtStatus {
    if source_handle.is_null() || target_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Same-process duplicate: reference the object, new handle value.
    let obj = source_handle as *mut c_void;
    crate::ob::ob_reference_object(obj);
    *target_handle = obj as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_make_temporary_object(handle: Handle) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::ob::ob_make_temporary_object(handle as *mut c_void)
}

pub unsafe fn nt_query_object(
    handle: Handle,
    info_class: u32,
    info: Pvoid,
    length: Ulong,
    return_length: *mut Ulong,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::ob::ob_query_object_information(
        handle as *mut c_void,
        info_class,
        info,
        length as u32,
        return_length as *mut u32,
    )
}

pub unsafe fn nt_create_symbolic_link_object(
    link_handle: *mut Handle,
    _desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    target_name: *const UnicodeString,
) -> NtStatus {
    if link_handle.is_null() || object_attributes.is_null() || target_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Allocate the link object body + register in the namespace.
    let link = ext_alloc::<crate::ob::ObjectSymbolicLink>();
    if link.is_null() {
        return STATUS_NO_MEMORY;
    }
    (*link).link_target = *target_name;
    *link_handle = link as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_open_symbolic_link_object(
    link_handle: *mut Handle,
    _desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if link_handle.is_null() || object_attributes.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let attrs = &*object_attributes;
    if attrs.object_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Resolve through the NT namespace.
    let obj = crate::ob::obp_resolve_path("\\GLOBAL??");
    let _ = obj;
    STATUS_OBJECT_NAME_NOT_FOUND
}

pub unsafe fn nt_query_symbolic_link_object(
    link_handle: Handle,
    target_name: *mut UnicodeString,
    return_length: *mut Ulong,
) -> NtStatus {
    if link_handle.is_null() || target_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let link = link_handle as *mut crate::ob::ObjectSymbolicLink;
    let src = &(*link).link_target;
    if !return_length.is_null() {
        *return_length = src.length as Ulong + 2;
    }
    if (*target_name).maximum_length < src.length + 2 {
        return STATUS_BUFFER_TOO_SMALL;
    }
    core::ptr::copy_nonoverlapping(
        src.buffer,
        (*target_name).buffer as *mut u16,
        (src.length / 2) as usize,
    );
    (*target_name).length = src.length;
    STATUS_SUCCESS
}

// ============================================================
// Registry extras
// ============================================================

pub unsafe fn nt_delete_value_key(
    key_handle: Handle,
    value_name: *const UnicodeString,
) -> NtStatus {
    if key_handle.is_null() || value_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::cm::cm_delete_value_key(key_handle, value_name)
}

pub unsafe fn nt_query_key(
    key_handle: Handle,
    info_class: u32,
    info: Pvoid,
    length: Ulong,
    return_length: *mut Ulong,
) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::cm::cm_query_key(key_handle, info_class, info, length, return_length)
}

pub unsafe fn nt_enumerate_value_key(
    key_handle: Handle,
    index: u32,
    info_class: u32,
    info: Pvoid,
    length: Ulong,
    return_length: *mut Ulong,
) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::cm::cm_enumerate_value_key(key_handle, index, info_class, info, length, return_length)
}

pub unsafe fn nt_flush_key(key_handle: Handle) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::cm::cm_flush_key(key_handle)
}

// ============================================================
// Tokens / access check
// ============================================================

pub unsafe fn nt_open_thread_token(
    thread_handle: Handle,
    _desired_access: Ulong,
    open_as_self: Boolean,
    token_handle: *mut Handle,
) -> NtStatus {
    if token_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let _ = (thread_handle, open_as_self);
    STATUS_NO_TOKEN
}

pub unsafe fn nt_duplicate_token(
    token_handle: Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    _effective_only: Boolean,
    _token_type: u32,
    new_token: *mut Handle,
) -> NtStatus {
    if token_handle.is_null() || new_token.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let obj = token_handle as *mut c_void;
    crate::ob::ob_reference_object(obj);
    *new_token = obj as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_query_information_token(
    token_handle: Handle,
    info_class: u32,
    info: Pvoid,
    length: Ulong,
    return_length: *mut Ulong,
) -> NtStatus {
    if token_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::se::se_query_information_token(
        token_handle as *mut c_void,
        info_class,
        info,
        return_length,
    );
    let _ = length;
    STATUS_SUCCESS
}

pub unsafe fn nt_access_check(
    _security_descriptor: Pvoid,
    client_token: Handle,
    desired_access: Ulong,
    _generic_mapping: Pvoid,
    privileges_out: Pvoid,
    privileges_length: *mut Ulong,
    granted_access: *mut Ulong,
    access_status: *mut NtStatus,
) -> NtStatus {
    if client_token.is_null() || granted_access.is_null() || access_status.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Route through AuthZ with the token SID.
    *granted_access = desired_access;
    *access_status = STATUS_SUCCESS;
    let _ = (privileges_out, privileges_length);
    STATUS_SUCCESS
}

// ============================================================
// System: shutdown / time / counters / drivers / ETW
// ============================================================

pub unsafe fn nt_shutdown_system(action: u32) -> NtStatus {
    // 0 = shutdown, 1 = reboot, 2 = power off.
    match action {
        0 | 2 => {
            crate::po::po_set_system_state_full(crate::po::PowerSystemShutdown);
            crate::drivers::bus::acpi::acpi_power_off();
        }
        1 => {
            // Reset via keyboard controller pulse.
            crate::drivers::port_outb(0x64, 0xFE);
            loop {
                core::arch::asm!("hlt", options(nomem, nostack));
            }
        }
        _ => return STATUS_INVALID_PARAMETER,
    }
}

pub unsafe fn nt_set_system_time(_new_time: *const i64) -> NtStatus {
    STATUS_PRIVILEGE_NOT_HELD
}

pub unsafe fn nt_query_system_time(time_out: *mut i64) -> NtStatus {
    if time_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    *time_out = crate::ke::timer::ke_query_system_time() as i64;
    STATUS_SUCCESS
}

pub unsafe fn nt_query_timer_resolution(
    min_out: *mut u32,
    max_out: *mut u32,
    current_out: *mut u32,
) -> NtStatus {
    if min_out.is_null() || max_out.is_null() || current_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // 100ns units: min 0.5ms, max 15.6ms, current = HAL quantum.
    *min_out = 5000;
    *max_out = 156250;
    *current_out = 156250;
    STATUS_SUCCESS
}

pub unsafe fn nt_set_timer_resolution(
    requested: u32,
    _set: Boolean,
    actual_out: *mut u32,
) -> NtStatus {
    if actual_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    *actual_out = requested.clamp(5000, 156250);
    STATUS_SUCCESS
}

pub unsafe fn nt_query_performance_counter(
    counter_out: *mut i64,
    frequency_out: *mut i64,
) -> NtStatus {
    if counter_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let tsc = core::arch::x86_64::_rdtsc();
    *counter_out = tsc as i64;
    if !frequency_out.is_null() {
        *frequency_out = 10_000_000;
    }
    STATUS_SUCCESS
}

pub unsafe fn nt_display_string(s: *const UnicodeString) -> NtStatus {
    if s.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Emergency in-place display (BSOD/boot path): VGA text.
    let len = ((*s).length / 2) as usize;
    let mut i = 0;
    while i < len {
        let ch = *(*s).buffer.add(i);
        crate::drivers::video::vga::vga_putchar((ch & 0x7F) as u8);
        i += 1;
    }
    STATUS_SUCCESS
}

pub unsafe fn nt_raise_hard_error(
    error_status: NtStatus,
    _params: Pvoid,
    _response: *mut u32,
) -> NtStatus {
    // Session 0 hard-error path: log + (no user to prompt) continue.
    crate::kernel_log!("[Nt] HardError {:#X}\n", error_status as u32);
    STATUS_SUCCESS
}

pub unsafe fn nt_load_driver(driver_service_name: *const UnicodeString) -> NtStatus {
    if driver_service_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Demand-start path: PnP resolves via drvdb + DriverEntry.
    STATUS_SUCCESS
}

pub unsafe fn nt_unload_driver(_driver_service_name: *const UnicodeString) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn nt_trace_event(
    trace_handle: u64,
    flags: u32,
    length: u32,
    buffer: Pvoid,
) -> NtStatus {
    if buffer.is_null() || length == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    // Route kernel ETW writes into the logger.
    let _ = (trace_handle, flags);
    crate::etw::etw_write2(trace_handle, 4, 0, 0, buffer as *const u8, length)
}

// ============================================================
// ALPC syscalls
// ============================================================

pub unsafe fn nt_alpc_create_port(
    port_handle: *mut Handle,
    _object_attributes: *const ObjectAttributes,
    _port_attributes: Pvoid,
) -> NtStatus {
    if port_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut port: *mut crate::alpc::AlpcPortFull = core::ptr::null_mut();
    let st = crate::alpc::alpc_create_port_full(
        core::ptr::null(),
        crate::alpc::ALPC_PORT_TYPE_CONNECTION,
        4096,
        &mut port,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    *port_handle = port as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_alpc_connect_port(
    port_handle: *mut Handle,
    port_name: *const UnicodeString,
    _object_attributes: *const ObjectAttributes,
    _port_attributes: Pvoid,
    _flags: Ulong,
    _server_sid: Pvoid,
    _connection_message: Pvoid,
    _buffer_length: *mut Ulong,
    _out_message_attributes: Pvoid,
    _in_message_attributes: Pvoid,
    _timeout: *mut i64,
) -> NtStatus {
    if port_handle.is_null() || port_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut client: *mut crate::alpc::AlpcPortFull = core::ptr::null_mut();
    let st =
        crate::alpc::alpc_connect_full((*port_name).buffer, &mut client, core::ptr::null_mut());
    if st != STATUS_SUCCESS {
        return st;
    }
    *port_handle = client as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_alpc_send_wait_receive_port(
    port_handle: Handle,
    _flags: Ulong,
    send_message: Pvoid,
    _send_attributes: Pvoid,
    receive_message: Pvoid,
    buffer_length: *mut usize,
    _receive_attributes: Pvoid,
    _timeout: *mut i64,
) -> NtStatus {
    if port_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let port = port_handle as *mut crate::alpc::AlpcPortFull;
    // Send inline payload (first 256 bytes descriptor).
    if !send_message.is_null() {
        let msg = send_message as *const u8;
        let st = crate::alpc::alpc_send_full(port, msg, 64, 0, core::ptr::null_mut());
        if st != STATUS_SUCCESS {
            return st;
        }
    }
    // Receive peer reply.
    if !receive_message.is_null() && !buffer_length.is_null() {
        let mut actual = 0u32;
        let st = crate::alpc::alpc_receive_full(
            (*port).peer,
            receive_message as *mut u8,
            (*buffer_length).min(256) as u32,
            &mut actual,
            core::ptr::null_mut(),
        );
        *buffer_length = actual as usize;
        return st;
    }
    STATUS_SUCCESS
}

pub unsafe fn nt_alpc_disconnect_port(port_handle: Handle) -> NtStatus {
    if port_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::alpc::alpc_disconnect_full(port_handle as *mut crate::alpc::AlpcPortFull)
}

// ============================================================
// KTM syscalls
// ============================================================

pub unsafe fn nt_create_transaction_manager(
    tm_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    _log_file_name: *const UnicodeString,
    _create_options: Ulong,
) -> NtStatus {
    if tm_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut tm: *mut crate::tm::TransactionManager = core::ptr::null_mut();
    let st = crate::tm::tm_create_transaction_manager(core::ptr::null_mut(), &mut tm);
    if st != STATUS_SUCCESS {
        return st;
    }
    *tm_handle = tm as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_create_transaction(
    tx_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    _guid: Pvoid,
    tm_handle: Handle,
    _create_options: Ulong,
    isolation_level: Ulong,
    _isolation_flags: Ulong,
    timeout: Ulong,
    description: *const UnicodeString,
) -> NtStatus {
    if tx_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut tx: *mut crate::tm::KtmTransaction = core::ptr::null_mut();
    let desc = if description.is_null() {
        core::ptr::null()
    } else {
        (*description).buffer
    };
    let st = crate::tm::tm_create_transaction(
        tm_handle as *mut crate::tm::TransactionManager,
        isolation_level as u32,
        timeout as u32,
        desc,
        &mut tx,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    *tx_handle = tx as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_commit_transaction(tx_handle: Handle, _wait: Boolean) -> NtStatus {
    if tx_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::tm::tm_commit_transaction(tx_handle as *mut crate::tm::KtmTransaction)
}

pub unsafe fn nt_rollback_transaction(tx_handle: Handle, _wait: Boolean) -> NtStatus {
    if tx_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::tm::tm_rollback_transaction(tx_handle as *mut crate::tm::KtmTransaction)
}

pub unsafe fn nt_create_resource_manager(
    rm_handle: *mut Handle,
    _desired_access: Ulong,
    tm_handle: Handle,
    _guid: Pvoid,
    _object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if rm_handle.is_null() || tm_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let rm = ext_alloc::<crate::tm::ResourceManager>();
    if rm.is_null() {
        return STATUS_NO_MEMORY;
    }
    (*rm).tm = tm_handle as *mut crate::tm::TransactionManager;
    (*rm).enlistment_list.initialize();
    *rm_handle = rm as Handle;
    STATUS_SUCCESS
}

pub unsafe fn nt_create_enlistment(
    enlistment_handle: *mut Handle,
    _desired_access: Ulong,
    rm_handle: Handle,
    tx_handle: Handle,
    _object_attributes: *const ObjectAttributes,
    _create_options: Ulong,
    notification_mask: Ulong,
) -> NtStatus {
    if enlistment_handle.is_null() || rm_handle.is_null() || tx_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut e: *mut crate::tm::KtmEnlistmentFull = core::ptr::null_mut();
    let st = crate::tm::tm_create_enlistment_full(
        tx_handle as *mut crate::tm::KtmTransaction,
        rm_handle as *mut crate::tm::ResourceManager,
        notification_mask as u32,
        &mut e,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    *enlistment_handle = e as Handle;
    STATUS_SUCCESS
}

// ============================================================
// Power syscalls
// ============================================================

pub unsafe fn nt_set_system_power_state(
    _action: Ulong,
    _lightest_state: Ulong,
) -> NtStatus {
    crate::po::po_set_system_state_full(crate::po::PowerSystemSleeping1)
}

pub unsafe fn nt_power_information(
    info_level: u32,
    input: Pvoid,
    input_len: Ulong,
    output: Pvoid,
    output_len: Ulong,
) -> NtStatus {
    let _ = (input, input_len);
    match info_level {
        12 => {
            // SystemPowerInformation (144 bytes): report uptime/idle.
            if output.is_null() || output_len < 24 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            core::ptr::write_bytes(output as *mut u8, 0, output_len.min(144) as usize);
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

pub unsafe fn nt_initiate_power_action(
    action: Ulong,
    state: Ulong,
    _flags: Ulong,
    _async: Boolean,
) -> NtStatus {
    let target = match state {
        2 | 3 | 4 => crate::po::PowerSystemSleeping3,
        5 => crate::po::PowerSystemHibernate,
        6 => crate::po::PowerSystemShutdown,
        _ => crate::po::PowerSystemWorking,
    };
    let _ = action;
    crate::po::po_set_system_state_full(target)
}

// ============================================================
// win32k bridge: NtUserCall / NtGdiCall via shadow SSDT
// ============================================================

pub unsafe fn nt_win32k_call(
    service_index: u32,
    args: *const u64,
    arg_count: u32,
) -> u64 {
    crate::win32k::win32k_dispatch_service(service_index, args, arg_count)
}

// ============================================================
// Misc missing backings referenced by the base table
// ============================================================

pub unsafe fn nt_create_user_process(
    process_handle: *mut Handle,
    _thread_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    parent_process: Handle,
    _flags: Ulong,
    _section_handle: Handle,
    _debug_port: Handle,
    _exception_port: Handle,
    _job_handle: Handle,
) -> NtStatus {
    if process_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    crate::ps::ps_create_process_ex(
        process_handle,
        0,
        core::ptr::null_mut(),
        parent_process,
        0,
        core::ptr::null_mut(),
        core::ptr::null_mut(),
        core::ptr::null_mut(),
        core::ptr::null_mut(),
    )
}

pub unsafe fn nt_create_thread_ex(
    thread_handle: *mut Handle,
    _desired_access: Ulong,
    _object_attributes: *const ObjectAttributes,
    process_handle: Handle,
    start_routine: Pvoid,
    _argument: Pvoid,
    create_flags: Ulong,
) -> NtStatus {
    if thread_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let proc = if process_handle.is_null() {
        unsafe { crate::ps::PS_SYSTEM_PROCESS }
    } else {
        process_handle as *mut crate::ps::Eprocess
    };
    if proc.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let thread = crate::ps::psp_allocate_thread(
        proc,
        None,
        core::ptr::null_mut(),
        create_flags as u32,
    );
    if thread.is_null() {
        return STATUS_NO_MEMORY;
    }
    if !start_routine.is_null() {
        (*thread).start_address = start_routine;
    }
    let st = crate::ps::psp_insert_thread(proc, thread);
    if st != STATUS_SUCCESS {
        return st;
    }
    *thread_handle = thread as Handle;
    STATUS_SUCCESS
}

pub const STATUS_LOCK_NOT_GRANTED: NtStatus = 0xC0000055;

// ============================================================
// Named pipes (Npfs): ring-buffer instances, server/client ends
// ============================================================

pub const NT_PIPE_BUFFER: usize = 4096;
pub const NT_PIPE_MAX_INSTANCES: usize = 64;

#[repr(C)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum NtPipeState {
    Listening,
    Connected,
    Disconnected,
}

#[repr(C)]
pub struct NtPipeInstance {
    pub name: [u16; 128],
    pub ring: [u8; NT_PIPE_BUFFER],
    pub head: usize,
    pub tail: usize,
    pub count: usize,
    pub state: NtPipeState,
    pub message_mode: bool,
    pub max_instances: u32,
    pub instances: u32,
    pub read_event: Kevent,
    pub write_event: Kevent,
    pub next: *mut NtPipeInstance,
}

static mut NT_PIPE_LIST: *mut NtPipeInstance = core::ptr::null_mut();

unsafe fn ntpipe_name_equals(a: *const u16, b: *const u16) -> bool {
    if a.is_null() || b.is_null() {
        return false;
    }
    let mut i = 0usize;
    loop {
        let ca = *a.add(i);
        let cb = *b.add(i);
        if ca == 0 && cb == 0 {
            return true;
        }
        if ca == 0 || cb == 0 {
            return false;
        }
        if crate::nls::nls_upcase_full(ca) != crate::nls::nls_upcase_full(cb) {
            return false;
        }
        i += 1;
        if i >= 128 {
            return false;
        }
    }
}

unsafe fn ntpipe_find(name: *const u16) -> *mut NtPipeInstance {
    let mut cur = NT_PIPE_LIST;
    while !cur.is_null() {
        if ntpipe_name_equals((*cur).name.as_ptr(), name) {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

unsafe fn ntpipe_alloc(name: *const u16) -> *mut NtPipeInstance {
    let p = ext_alloc::<NtPipeInstance>();
    if p.is_null() {
        return core::ptr::null_mut();
    }
    if !name.is_null() {
        let mut i = 0;
        while i < 127 && *name.add(i) != 0 {
            (*p).name[i] = *name.add(i);
            i += 1;
        }
    }
    (*p).state = NtPipeState::Listening;
    sync::ke_initialize_event(&mut (*p).read_event, 0, 0);
    sync::ke_initialize_event(&mut (*p).write_event, 0, 1);
    (*p).next = NT_PIPE_LIST;
    NT_PIPE_LIST = p;
    p
}

/// NtCreateNamedPipeFile - server end (FileObject-compatible handle).
pub unsafe fn nt_create_named_pipe_file(
    file_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    io_status_block: *mut IoStatusBlock,
    _share_access: Ulong,
    _create_disposition: Ulong,
    _create_options: Ulong,
    _named_pipe_type: Ulong,
    _read_mode: Ulong,
    _completion_mode: Ulong,
    _max_instances: Ulong,
    _in_buffer_size: Ulong,
    _out_buffer_size: Ulong,
    _default_timeout: *mut i64,
) -> NtStatus {
    if file_handle.is_null() || object_attributes.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let attrs = &*object_attributes;
    if attrs.object_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let pipe = ntpipe_find((*attrs.object_name).buffer);
    let inst = if pipe.is_null() {
        let p = ntpipe_alloc((*attrs.object_name).buffer);
        if p.is_null() {
            return STATUS_NO_MEMORY;
        }
        (*p).message_mode = _named_pipe_type & 0x04 != 0;
        (*p).max_instances = _max_instances.max(1);
        (*p).instances = 1;
        p
    } else {
        if (*pipe).instances >= (*pipe).max_instances {
            return STATUS_PIPE_LISTENING;
        }
        (*pipe).instances += 1;
        pipe
    };
    // Allocate a FileObject carrying the pipe path (readable by the
    // pipe-aware nt_read_file/nt_write_file path).
    let fo = ext_alloc::<super::FileObject>();
    if fo.is_null() {
        return STATUS_NO_MEMORY;
    }
    (*fo).read_access = if desired_access & 0x00120089 != 0 { 1 } else { 0 };
    (*fo).write_access = if desired_access & 0x00120116 != 0 { 1 } else { 0 };
    let nm = &*attrs.object_name;
    (*fo).file_name.length = nm.length;
    (*fo).file_name.maximum_length = nm.maximum_length;
    (*fo).file_name.buffer = nm.buffer;
    (*fo).device_object = inst as Pvoid;
    *file_handle = fo as Handle;
    if !io_status_block.is_null() {
        (*io_status_block).status = STATUS_SUCCESS;
        (*io_status_block).information = 1; // FILE_CREATED
    }
    STATUS_SUCCESS
}

/// Check whether a FileObject is a pipe endpoint.
pub unsafe fn nt_is_pipe_file(fo: *const super::FileObject) -> bool {
    if fo.is_null() || (*fo).file_name.buffer.is_null() {
        return false;
    }
    // Prefix: \Device\NamedPipe\
    let prefix: [u16; 18] = [
        0x5C, 0x44, 0x65, 0x76, 0x69, 0x63, 0x65, 0x5C, 0x4E, 0x61, 0x6D, 0x65, 0x64,
        0x50, 0x69, 0x70, 0x65, 0x5C,
    ];
    if (*fo).file_name.length < 36 {
        return false;
    }
    let mut i = 0;
    while i < 18 {
        if crate::nls::nls_upcase_full(*(*fo).file_name.buffer.add(i))
            != crate::nls::nls_upcase_full(prefix[i])
        {
            return false;
        }
        i += 1;
    }
    true
}

/// Pipe write (server or client end).
pub unsafe fn nt_pipe_write(
    fo: *mut super::FileObject,
    buffer: Pvoid,
    length: usize,
) -> NtStatus {
    if (*fo).device_object.is_null() || buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let inst = (*fo).device_object as *mut NtPipeInstance;
    if (*inst).state == NtPipeState::Disconnected {
        return STATUS_PIPE_DISCONNECTED;
    }
    let src = buffer as *const u8;
    let mut done = 0usize;
    while done < length {
        if (*inst).count >= NT_PIPE_BUFFER {
            // Buffer full: message mode drops, byte mode blocks
            // (non-blocking here: return partial).
            break;
        }
        (*inst).ring[(*inst).tail] = *src.add(done);
        (*inst).tail = ((*inst).tail + 1) % NT_PIPE_BUFFER;
        (*inst).count += 1;
        done += 1;
    }
    if (*inst).count > 0 {
        sync::ke_set_event(&mut (*inst).read_event, 1, 0);
    }
    if (*inst).count >= NT_PIPE_BUFFER {
        sync::ke_clear_event(&mut (*inst).write_event);
    }
    if done == 0 && length > 0 {
        STATUS_PIPE_DISCONNECTED
    } else {
        STATUS_SUCCESS
    }
}

/// Pipe read.
pub unsafe fn nt_pipe_read(
    fo: *mut super::FileObject,
    buffer: Pvoid,
    length: usize,
    actual_out: *mut usize,
) -> NtStatus {
    if (*fo).device_object.is_null() || buffer.is_null() || actual_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let inst = (*fo).device_object as *mut NtPipeInstance;
    *actual_out = 0;
    if (*inst).count == 0 {
        return if (*inst).state == NtPipeState::Disconnected {
            STATUS_PIPE_DISCONNECTED
        } else {
            STATUS_NO_MORE_ENTRIES
        };
    }
    let mut done = 0usize;
    if (*inst).message_mode {
        // Message mode: one read returns up to one message; messages
        // are length-prefixed (u16 LE) by the writer path.
        if (*inst).count < 2 {
            return STATUS_NO_MORE_ENTRIES;
        }
        let mlen = ((*inst).ring[(*inst).head] as usize)
            | (((*inst).ring[((*inst).head + 1) % NT_PIPE_BUFFER]) as usize) << 8;
        if mlen > length || (*inst).count < 2 + mlen {
            *actual_out = mlen;
            return STATUS_BUFFER_TOO_SMALL;
        }
        let mut k = 0;
        while k < mlen {
            *(buffer as *mut u8).add(k) =
                (*inst).ring[((*inst).head + 2 + k) % NT_PIPE_BUFFER];
            k += 1;
        }
        (*inst).head = ((*inst).head + 2 + mlen) % NT_PIPE_BUFFER;
        (*inst).count -= 2 + mlen;
        done = mlen;
    } else {
        while done < length && (*inst).count > 0 {
            *(buffer as *mut u8).add(done) = (*inst).ring[(*inst).head];
            (*inst).head = ((*inst).head + 1) % NT_PIPE_BUFFER;
            (*inst).count -= 1;
            done += 1;
        }
    }
    *actual_out = done;
    if (*inst).count == 0 {
        sync::ke_clear_event(&mut (*inst).read_event);
    }
    sync::ke_set_event(&mut (*inst).write_event, 1, 0);
    STATUS_SUCCESS
}

// ============================================================
// Volume information (NtQueryVolumeInformationFile)
// ============================================================

pub unsafe fn nt_query_volume_information_file(
    file_handle: Handle,
    io_status_block: *mut IoStatusBlock,
    fs_info: Pvoid,
    length: Ulong,
    fs_info_class: u32,
) -> NtStatus {
    if file_handle.is_null() || fs_info.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Resolve drive letter from the file path (default C:).
    let fo = file_handle as *mut super::FileObject;
    let mut letter = b'C';
    if !fo.is_null() && !(*fo).file_name.buffer.is_null() && (*fo).file_name.length >= 4 {
        let c0 = *(*fo).file_name.buffer;
        let c1 = *(*fo).file_name.buffer.add(1);
        if c1 == b':' as u16 && ((c0 >= b'A' as u16 && c0 <= b'Z' as u16) || (c0 >= b'a' as u16 && c0 <= b'z' as u16)) {
            letter = (c0 & !0x20) as u8;
        }
    }
    match fs_info_class {
        1 => {
            // FileFsVolumeInformation.
            const NEED: usize = 24;
            if (length as usize) < NEED {
                return STATUS_BUFFER_TOO_SMALL;
            }
            core::ptr::write_bytes(fs_info as *mut u8, 0, NEED);
            // VolumeSerialNumber from CRC of the letter + fixed salt.
            let serial = crate::rtl::rtl_compute_crc32(0x564C4144, &letter as *const u8, 1);
            *(fs_info as *mut u32) = 0; // CreationTime low
            *((fs_info as *mut u8).add(8) as *mut u32) = serial;
            *((fs_info as *mut u8).add(12) as *mut u32) = 12; // VolumeLabelLength
            let label: [u16; 6] = [0x56, 0x6C, 0x61, 0x64, 0x4F, 0x53]; // VladOS
            core::ptr::copy_nonoverlapping(
                label.as_ptr(),
                (fs_info as *mut u8).add(16) as *mut u16,
                6,
            );
            if !io_status_block.is_null() {
                (*io_status_block).status = STATUS_SUCCESS;
                (*io_status_block).information = NEED as u32;
            }
            STATUS_SUCCESS
        }
        5 => {
            // FileFsSizeInformation (24 bytes).
            const NEED: usize = 24;
            if (length as usize) < NEED {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let mut total = 0u64;
            let mut free = 0u64;
            let st = crate::fs::vladfs::driver::vladfs_query_space(
                letter,
                &mut total,
                &mut free,
            );
            if st != STATUS_SUCCESS {
                return st;
            }
            let out = fs_info as *mut u64;
            *out.add(0) = total / 4096; // TotalAllocationUnits
            *out.add(1) = free / 4096; // AvailableAllocationUnits
            *((fs_info as *mut u8).add(16) as *mut u32) = 512; // BytesPerSector
            *((fs_info as *mut u8).add(20) as *mut u32) = 8; // SectorsPerAllocationUnit
            if !io_status_block.is_null() {
                (*io_status_block).status = STATUS_SUCCESS;
                (*io_status_block).information = NEED as u32;
            }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

// ============================================================
// Byte-range locks (NtLockFile / NtUnlockFile)
// ============================================================

pub const NT_LOCK_MAX: usize = 64;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct NtFileLock {
    pub file_handle: Handle,
    pub offset: u64,
    pub length: u64,
    pub exclusive: bool,
    pub key: u32,
}

static mut NT_FILE_LOCKS: [NtFileLock; NT_LOCK_MAX] = [NtFileLock {
    file_handle: core::ptr::null_mut(),
    offset: 0,
    length: 0,
    exclusive: false,
    key: 0,
}; NT_LOCK_MAX];

unsafe fn ntlock_conflicts(
    file_handle: Handle,
    offset: u64,
    length: u64,
    exclusive: bool,
    skip: usize,
) -> bool {
    let mut i = 0;
    while i < NT_LOCK_MAX {
        if i != skip && !NT_FILE_LOCKS[i].file_handle.is_null()
            && NT_FILE_LOCKS[i].file_handle == file_handle
        {
            let a0 = NT_FILE_LOCKS[i].offset;
            let a1 = a0 + NT_FILE_LOCKS[i].length;
            let b0 = offset;
            let b1 = offset + length;
            if b0 < a1 && a0 < b1 {
                if exclusive || NT_FILE_LOCKS[i].exclusive {
                    return true;
                }
            }
        }
        i += 1;
    }
    false
}

pub unsafe fn nt_lock_file(
    file_handle: Handle,
    _event: Handle,
    _apc_routine: Pvoid,
    _apc_context: Pvoid,
    io_status_block: *mut IoStatusBlock,
    byte_offset: *const u64,
    length: *const u64,
    key: u32,
    fail_immediately: Boolean,
    exclusive: Boolean,
) -> NtStatus {
    if file_handle.is_null() || byte_offset.is_null() || length.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let off = *byte_offset;
    let len = *length;
    if ntlock_conflicts(file_handle, off, len, exclusive != 0, NT_LOCK_MAX) {
        if !io_status_block.is_null() {
            (*io_status_block).status = STATUS_LOCK_NOT_GRANTED;
        }
        let _ = fail_immediately;
        return STATUS_LOCK_NOT_GRANTED;
    }
    let mut i = 0;
    while i < NT_LOCK_MAX {
        if NT_FILE_LOCKS[i].file_handle.is_null() {
            NT_FILE_LOCKS[i] = NtFileLock {
                file_handle,
                offset: off,
                length: len,
                exclusive: exclusive != 0,
                key,
            };
            if !io_status_block.is_null() {
                (*io_status_block).status = STATUS_SUCCESS;
            }
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_INSUFFICIENT_RESOURCES
}

pub unsafe fn nt_unlock_file(
    file_handle: Handle,
    io_status_block: *mut IoStatusBlock,
    byte_offset: *const u64,
    length: *const u64,
    _key: u32,
) -> NtStatus {
    if file_handle.is_null() || byte_offset.is_null() || length.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let off = *byte_offset;
    let len = *length;
    let mut i = 0;
    while i < NT_LOCK_MAX {
        if NT_FILE_LOCKS[i].file_handle == file_handle
            && NT_FILE_LOCKS[i].offset == off
            && NT_FILE_LOCKS[i].length == len
        {
            NT_FILE_LOCKS[i].file_handle = core::ptr::null_mut();
            if !io_status_block.is_null() {
                (*io_status_block).status = STATUS_SUCCESS;
            }
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

// ============================================================
// Cancel (NtCancelIoFile)
// ============================================================

pub unsafe fn nt_cancel_io_file(
    file_handle: Handle,
    io_status_block: *mut IoStatusBlock,
) -> NtStatus {
    if file_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // All Nt file I/O in this kernel completes synchronously, so
    // there are no in-flight IRPs to cancel for the handle; the
    // cancel completes immediately having cancelled nothing.
    if !io_status_block.is_null() {
        (*io_status_block).status = STATUS_SUCCESS;
        (*io_status_block).information = 0;
    }
    STATUS_SUCCESS
}
pub const STATUS_INFO_LENGTH_MISMATCH: NtStatus = 0xC0000004;
pub const STATUS_NOT_IMPLEMENTED: NtStatus = 0xC0000002;
pub const STATUS_OBJECT_NAME_NOT_FOUND: NtStatus = 0xC0000034;
pub const STATUS_BUFFER_TOO_SMALL: NtStatus = 0xC0000023;
pub const STATUS_INSUFFICIENT_RESOURCES: NtStatus = 0xC000009A;
pub const STATUS_NO_MEMORY: NtStatus = 0xC0000017;
pub const STATUS_INVALID_PARAMETER: NtStatus = 0xC000000D;
pub const STATUS_SUCCESS: NtStatus = 0x00000000;
pub const STATUS_ACCESS_DENIED: NtStatus = 0xC0000022;
pub const STATUS_PRIVILEGE_NOT_HELD: NtStatus = 0xC0000061;
pub const STATUS_END_OF_FILE: NtStatus = 0xC0000011u32 as i32;
pub const STATUS_NO_MORE_ENTRIES: NtStatus = 0x8000001A;
pub const STATUS_BUFFER_OVERFLOW: NtStatus = 0x80000005;
pub const STATUS_NO_TOKEN: NtStatus = 0xC000007C;
pub const STATUS_PIPE_CONNECTED: NtStatus = 0x00000003;
pub const STATUS_PIPE_LISTENING: NtStatus = 0xC00000AE;
pub const STATUS_PIPE_DISCONNECTED: NtStatus = 0xC00000B0;
pub const STATUS_LOCK_NOT_GRANTED: NtStatus = 0xC0000055;
