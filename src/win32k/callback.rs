/// Win32k callbacks - KeUserModeCallback dispatch
///
/// Kernel-to-user callbacks (window procs, hooks, menu handling)
/// via the per-process PEB.KernelCallbackTable. The kernel records
/// the callback; the trap/syscall layer performs the mode switch.

use core::ffi::c_void;

use crate::types::*;

// ============================================================
// Callback numbers (USER32!apfnDispatch indices, subset)
// ============================================================

pub const CALLBACK_WINDOW_PROC: u32 = 0;
pub const CALLBACK_SEND_MESSAGE: u32 = 1;
pub const CALLBACK_HOOK: u32 = 2;
pub const CALLBACK_LOAD_MENU: u32 = 3;
pub const CALLBACK_CONSOLE_CONTROL: u32 = 4;
pub const CALLBACK_CLIENT_THREAD_SETUP: u32 = 5;
pub const CALLBACK_DDE: u32 = 6;
pub const CALLBACK_NCDESTROY: u32 = 7;

pub const CALLBACK_MAX: usize = 32;
pub const CALLBACK_MAX_PARAMS: usize = 8;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UserCallbackFrame {
    pub api_number: u32,
    pub input_len: u32,
    pub input: [u64; CALLBACK_MAX_PARAMS],
    pub output_len: u32,
    pub output: [u64; CALLBACK_MAX_PARAMS],
    pub status: NtStatus,
    pub completed: bool,
}

#[repr(C)]
pub struct ProcessCallbackTable {
    pub process_id: u64,
    pub table_address: u64,
    pub table_size: u32,
    pub next: *mut ProcessCallbackTable,
}

static mut CALLBACK_TABLES: *mut ProcessCallbackTable = core::ptr::null_mut();

// Pending callback stack per thread (single level is enough for the
// kernel side; nesting is tracked by depth counter).
static mut CALLBACK_PENDING: UserCallbackFrame = UserCallbackFrame {
    api_number: 0,
    input_len: 0,
    input: [0; CALLBACK_MAX_PARAMS],
    output_len: 0,
    output: [0; CALLBACK_MAX_PARAMS],
    status: 0,
    completed: false,
};
static mut CALLBACK_DEPTH: u32 = 0;

/// Register a process's KernelCallbackTable (from user32 init).
pub unsafe fn callback_register_table(
    process_id: u64,
    table_address: u64,
    table_size: u32,
) -> NtStatus {
    if table_address == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let mut cur = CALLBACK_TABLES;
    while !cur.is_null() {
        if (*cur).process_id == process_id {
            (*cur).table_address = table_address;
            (*cur).table_size = table_size;
            return STATUS_SUCCESS;
        }
        cur = (*cur).next;
    }
    let e = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<ProcessCallbackTable>(),
    ) as *mut ProcessCallbackTable;
    if e.is_null() {
        return STATUS_NO_MEMORY;
    }
    (*e).process_id = process_id;
    (*e).table_address = table_address;
    (*e).table_size = table_size;
    (*e).next = CALLBACK_TABLES;
    CALLBACK_TABLES = e;
    STATUS_SUCCESS
}

/// KeUserModeCallback - request a user-mode callback.
///
/// Records the frame for the trap layer; returns the output the
/// user-mode dispatcher (NtCallbackReturn) wrote back. When no
/// callback table is registered, fails closed with a default.
pub unsafe fn ke_user_mode_callback(
    process_id: u64,
    api_number: u32,
    input: *const u64,
    input_len: u32,
    output: *mut u64,
    output_len: *mut u32,
) -> NtStatus {
    if api_number as usize >= CALLBACK_MAX {
        return STATUS_INVALID_PARAMETER;
    }
    // Find the process table.
    let mut cur = CALLBACK_TABLES;
    let mut found = false;
    while !cur.is_null() {
        if (*cur).process_id == process_id {
            found = true;
            break;
        }
        cur = (*cur).next;
    }
    if !found {
        // No user32 yet (early boot / session 0): default action.
        if !output_len.is_null() {
            *output_len = 0;
        }
        return STATUS_CALLBACK_POP_STACK;
    }
    if CALLBACK_DEPTH >= 16 {
        return STATUS_STACK_OVERFLOW;
    }
    CALLBACK_DEPTH += 1;
    CALLBACK_PENDING.api_number = api_number;
    CALLBACK_PENDING.input_len = input_len.min(CALLBACK_MAX_PARAMS as u32);
    if !input.is_null() && CALLBACK_PENDING.input_len > 0 {
        core::ptr::copy_nonoverlapping(
            input,
            CALLBACK_PENDING.input.as_mut_ptr(),
            CALLBACK_PENDING.input_len as usize,
        );
    }
    CALLBACK_PENDING.completed = false;
    CALLBACK_PENDING.status = STATUS_SUCCESS;
    // NOTE: the trap/syscall layer performs the actual mode switch
    // using this frame, then calls ke_callback_return() below.
    // Until the trap path exists, complete synchronously with the
    // default result so kernel code keeps working.
    CALLBACK_PENDING.completed = true;
    if !output.is_null() && !output_len.is_null() && *output_len > 0 {
        let n = (*output_len as usize).min(CALLBACK_MAX_PARAMS);
        core::ptr::copy_nonoverlapping(CALLBACK_PENDING.output.as_ptr(), output, n);
        *output_len = CALLBACK_PENDING.output_len.min(*output_len);
    }
    let st = CALLBACK_PENDING.status;
    CALLBACK_DEPTH -= 1;
    st
}

/// NtCallbackReturn - user-mode dispatcher returns a result.
pub unsafe fn ke_callback_return(
    output: *const u64,
    output_len: u32,
    status: NtStatus,
) -> NtStatus {
    if CALLBACK_DEPTH == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    CALLBACK_PENDING.output_len = output_len.min(CALLBACK_MAX_PARAMS as u32);
    if !output.is_null() && CALLBACK_PENDING.output_len > 0 {
        core::ptr::copy_nonoverlapping(
            output,
            CALLBACK_PENDING.output.as_mut_ptr(),
            CALLBACK_PENDING.output_len as usize,
        );
    }
    CALLBACK_PENDING.status = status;
    CALLBACK_PENDING.completed = true;
    STATUS_SUCCESS
}

/// Query the pending frame (for the trap layer).
pub unsafe fn ke_callback_pending_frame() -> *mut UserCallbackFrame {
    if CALLBACK_DEPTH == 0 || CALLBACK_PENDING.completed {
        return core::ptr::null_mut();
    }
    &mut CALLBACK_PENDING as *mut UserCallbackFrame
}

pub unsafe fn callback_init() -> NtStatus {
    CALLBACK_DEPTH = 0;
    STATUS_SUCCESS
}

// Local status codes (not yet in shared types).
pub const STATUS_CALLBACK_POP_STACK: NtStatus = 0xC0000423;
pub const STATUS_STACK_OVERFLOW: NtStatus = 0xC00000FD;
