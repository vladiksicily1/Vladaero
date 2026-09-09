/// # NT System Calls for VladOS
///
/// Native API syscall numbers matching Windows NT convention.
/// Syscall numbers start at 0x1000 to avoid collision with Redox syscalls.

use crate::types::*;

/// NT Syscall Numbers
pub const NT_SYSCALL_BASE: usize = 0x1000;

// File I/O
pub const NT_CREATE_FILE: usize = NT_SYSCALL_BASE + 0x00;
pub const NT_OPEN_FILE: usize = NT_SYSCALL_BASE + 0x01;
pub const NT_READ_FILE: usize = NT_SYSCALL_BASE + 0x02;
pub const NT_WRITE_FILE: usize = NT_SYSCALL_BASE + 0x03;
pub const NT_DELETE_FILE: usize = NT_SYSCALL_BASE + 0x04;
pub const NT_RENAME_FILE: usize = NT_SYSCALL_BASE + 0x05;
pub const NT_SET_INFORMATION_FILE: usize = NT_SYSCALL_BASE + 0x06;
pub const NT_QUERY_INFORMATION_FILE: usize = NT_SYSCALL_BASE + 0x07;
pub const NT_DEVICE_IO_CONTROL_FILE: usize = NT_SYSCALL_BASE + 0x08;
pub const NT_FLUSH_BUFFERS_FILE: usize = NT_SYSCALL_BASE + 0x09;
pub const NT_QUERY_DIRECTORY_FILE: usize = NT_SYSCALL_BASE + 0x0A;
pub const NT_CLOSE: usize = NT_SYSCALL_BASE + 0x0F;

// Process/Thread
pub const NT_CREATE_USER_PROCESS: usize = NT_SYSCALL_BASE + 0x10;
pub const NT_CREATE_THREAD: usize = NT_SYSCALL_BASE + 0x11;
pub const NT_OPEN_PROCESS: usize = NT_SYSCALL_BASE + 0x12;
pub const NT_TERMINATE_PROCESS: usize = NT_SYSCALL_BASE + 0x13;
pub const NT_SUSPEND_PROCESS: usize = NT_SYSCALL_BASE + 0x14;
pub const NT_RESUME_PROCESS: usize = NT_SYSCALL_BASE + 0x15;
pub const NT_QUERY_INFORMATION_PROCESS: usize = NT_SYSCALL_BASE + 0x16;
pub const NT_SET_INFORMATION_THREAD: usize = NT_SYSCALL_BASE + 0x17;
pub const NT_WAIT_FOR_SINGLE_OBJECT: usize = NT_SYSCALL_BASE + 0x18;
pub const NT_WAIT_FOR_MULTIPLE_OBJECTS: usize = NT_SYSCALL_BASE + 0x19;
pub const NT_QUERY_INFORMATION_THREAD: usize = NT_SYSCALL_BASE + 0x1A;

// Memory
pub const NT_ALLOCATE_VIRTUAL_MEMORY: usize = NT_SYSCALL_BASE + 0x20;
pub const NT_FREE_VIRTUAL_MEMORY: usize = NT_SYSCALL_BASE + 0x21;
pub const NT_PROTECT_VIRTUAL_MEMORY: usize = NT_SYSCALL_BASE + 0x22;
pub const NT_QUERY_VIRTUAL_MEMORY: usize = NT_SYSCALL_BASE + 0x23;
pub const NT_CREATE_SECTION: usize = NT_SYSCALL_BASE + 0x24;
pub const NT_OPEN_SECTION: usize = NT_SYSCALL_BASE + 0x25;
pub const NT_MAP_VIEW_OF_SECTION: usize = NT_SYSCALL_BASE + 0x26;
pub const NT_UNMAP_VIEW_OF_SECTION: usize = NT_SYSCALL_BASE + 0x27;

// Objects
pub const NT_OPEN_DIRECTORY_OBJECT: usize = NT_SYSCALL_BASE + 0x30;
pub const NT_CREATE_DIRECTORY_OBJECT: usize = NT_SYSCALL_BASE + 0x31;
pub const NT_OPEN_SYMBOLIC_LINK_OBJECT: usize = NT_SYSCALL_BASE + 0x32;
pub const NT_CREATE_SYMBOLIC_LINK_OBJECT: usize = NT_SYSCALL_BASE + 0x33;
pub const NT_QUERY_DIRECTORY_OBJECT: usize = NT_SYSCALL_BASE + 0x34;

// Registry
pub const NT_OPEN_KEY: usize = NT_SYSCALL_BASE + 0x40;
pub const NT_CREATE_KEY: usize = NT_SYSCALL_BASE + 0x41;
pub const NT_DELETE_KEY: usize = NT_SYSCALL_BASE + 0x42;
pub const NT_SET_VALUE_KEY: usize = NT_SYSCALL_BASE + 0x43;
pub const NT_QUERY_VALUE_KEY: usize = NT_SYSCALL_BASE + 0x44;
pub const NT_ENUMERATE_KEY: usize = NT_SYSCALL_BASE + 0x45;

// Security
pub const NT_OPEN_PROCESS_TOKEN: usize = NT_SYSCALL_BASE + 0x50;
pub const NT_QUERY_INFORMATION_TOKEN: usize = NT_SYSCALL_BASE + 0x51;
pub const NT_ADJUST_PRIVILEGES_TOKEN: usize = NT_SYSCALL_BASE + 0x52;

// Synchronization
pub const NT_CREATE_EVENT: usize = NT_SYSCALL_BASE + 0x60;
pub const NT_SET_EVENT: usize = NT_SYSCALL_BASE + 0x61;
pub const NT_CLEAR_EVENT: usize = NT_SYSCALL_BASE + 0x62;
pub const NT_CREATE_MUTANT: usize = NT_SYSCALL_BASE + 0x63;
pub const NT_CREATE_SEMAPHORE: usize = NT_SYSCALL_BASE + 0x64;
pub const NT_DELAY_EXECUTION: usize = NT_SYSCALL_BASE + 0x65;
pub const NT_YIELD_EXECUTION: usize = NT_SYSCALL_BASE + 0x66;

// System Information
pub const NT_QUERY_SYSTEM_INFORMATION: usize = NT_SYSCALL_BASE + 0x70;

// Maximum syscall number (base table)
pub const NT_SYSCALL_COUNT_BASE: usize = 0x80;

// ---- Extended table (0x80..0xD0): second-wave Native API ----

// File I/O extras
pub const NT_CANCEL_IO_FILE: usize = NT_SYSCALL_BASE + 0x80;
pub const NT_CREATE_NAMED_PIPE_FILE: usize = NT_SYSCALL_BASE + 0x81;
pub const NT_QUERY_VOLUME_INFORMATION_FILE: usize = NT_SYSCALL_BASE + 0x82;
pub const NT_LOCK_FILE: usize = NT_SYSCALL_BASE + 0x83;
pub const NT_UNLOCK_FILE: usize = NT_SYSCALL_BASE + 0x84;

// Process/Thread extras
pub const NT_OPEN_THREAD: usize = NT_SYSCALL_BASE + 0x85;
pub const NT_TERMINATE_THREAD: usize = NT_SYSCALL_BASE + 0x86;
pub const NT_SUSPEND_THREAD: usize = NT_SYSCALL_BASE + 0x87;
pub const NT_RESUME_THREAD: usize = NT_SYSCALL_BASE + 0x88;
pub const NT_GET_CONTEXT_THREAD: usize = NT_SYSCALL_BASE + 0x89;
pub const NT_SET_CONTEXT_THREAD: usize = NT_SYSCALL_BASE + 0x8A;
pub const NT_SET_INFORMATION_THREAD_FULL: usize = NT_SYSCALL_BASE + 0x8B;
pub const NT_SET_INFORMATION_PROCESS: usize = NT_SYSCALL_BASE + 0x8C;
pub const NT_CREATE_JOB_OBJECT: usize = NT_SYSCALL_BASE + 0x8D;
pub const NT_ASSIGN_PROCESS_TO_JOB: usize = NT_SYSCALL_BASE + 0x8E;
pub const NT_TERMINATE_JOB_OBJECT: usize = NT_SYSCALL_BASE + 0x8F;
pub const NT_READ_VIRTUAL_MEMORY: usize = NT_SYSCALL_BASE + 0x90;
pub const NT_WRITE_VIRTUAL_MEMORY: usize = NT_SYSCALL_BASE + 0x91;
pub const NT_CREATE_THREAD_EX: usize = NT_SYSCALL_BASE + 0x92;
pub const NT_CREATE_USER_PROCESS_FULL: usize = NT_SYSCALL_BASE + 0x93;

// Synchronization extras
pub const NT_CREATE_MUTANT_FULL: usize = NT_SYSCALL_BASE + 0x94;
pub const NT_OPEN_MUTANT: usize = NT_SYSCALL_BASE + 0x95;
pub const NT_RELEASE_MUTANT: usize = NT_SYSCALL_BASE + 0x96;
pub const NT_CREATE_SEMAPHORE_FULL: usize = NT_SYSCALL_BASE + 0x97;
pub const NT_OPEN_SEMAPHORE: usize = NT_SYSCALL_BASE + 0x98;
pub const NT_RELEASE_SEMAPHORE: usize = NT_SYSCALL_BASE + 0x99;
pub const NT_QUERY_SEMAPHORE: usize = NT_SYSCALL_BASE + 0x9A;
pub const NT_CREATE_TIMER: usize = NT_SYSCALL_BASE + 0x9B;
pub const NT_OPEN_TIMER: usize = NT_SYSCALL_BASE + 0x9C;
pub const NT_SET_TIMER: usize = NT_SYSCALL_BASE + 0x9D;
pub const NT_CANCEL_TIMER: usize = NT_SYSCALL_BASE + 0x9E;
pub const NT_CREATE_KEYED_EVENT: usize = NT_SYSCALL_BASE + 0x9F;
pub const NT_OPEN_KEYED_EVENT: usize = NT_SYSCALL_BASE + 0xA0;
pub const NT_WAIT_FOR_KEYED_EVENT: usize = NT_SYSCALL_BASE + 0xA1;
pub const NT_RELEASE_KEYED_EVENT: usize = NT_SYSCALL_BASE + 0xA2;
pub const NT_CREATE_IO_COMPLETION: usize = NT_SYSCALL_BASE + 0xA3;
pub const NT_SET_IO_COMPLETION: usize = NT_SYSCALL_BASE + 0xA4;
pub const NT_REMOVE_IO_COMPLETION: usize = NT_SYSCALL_BASE + 0xA5;
pub const NT_SIGNAL_AND_WAIT: usize = NT_SYSCALL_BASE + 0xA6;
pub const NT_CLEAR_EVENT_FULL: usize = NT_SYSCALL_BASE + 0xA7;

// Object extras
pub const NT_DUPLICATE_OBJECT: usize = NT_SYSCALL_BASE + 0xA8;
pub const NT_MAKE_TEMPORARY_OBJECT: usize = NT_SYSCALL_BASE + 0xA9;
pub const NT_QUERY_OBJECT: usize = NT_SYSCALL_BASE + 0xAA;
pub const NT_CREATE_SYMBOLIC_LINK: usize = NT_SYSCALL_BASE + 0xAB;
pub const NT_OPEN_SYMBOLIC_LINK: usize = NT_SYSCALL_BASE + 0xAC;
pub const NT_QUERY_SYMBOLIC_LINK: usize = NT_SYSCALL_BASE + 0xAD;

// Registry extras
pub const NT_DELETE_VALUE_KEY: usize = NT_SYSCALL_BASE + 0xAE;
pub const NT_QUERY_KEY: usize = NT_SYSCALL_BASE + 0xAF;
pub const NT_ENUMERATE_VALUE_KEY: usize = NT_SYSCALL_BASE + 0xB0;
pub const NT_FLUSH_KEY: usize = NT_SYSCALL_BASE + 0xB1;

// Security extras
pub const NT_OPEN_THREAD_TOKEN: usize = NT_SYSCALL_BASE + 0xB2;
pub const NT_DUPLICATE_TOKEN: usize = NT_SYSCALL_BASE + 0xB3;
pub const NT_QUERY_INFORMATION_TOKEN: usize = NT_SYSCALL_BASE + 0xB4;
pub const NT_ACCESS_CHECK: usize = NT_SYSCALL_BASE + 0xB5;

// System extras
pub const NT_SHUTDOWN_SYSTEM: usize = NT_SYSCALL_BASE + 0xB6;
pub const NT_QUERY_SYSTEM_TIME: usize = NT_SYSCALL_BASE + 0xB7;
pub const NT_QUERY_TIMER_RESOLUTION: usize = NT_SYSCALL_BASE + 0xB8;
pub const NT_SET_TIMER_RESOLUTION: usize = NT_SYSCALL_BASE + 0xB9;
pub const NT_QUERY_PERFORMANCE_COUNTER: usize = NT_SYSCALL_BASE + 0xBA;
pub const NT_DISPLAY_STRING: usize = NT_SYSCALL_BASE + 0xBB;
pub const NT_RAISE_HARD_ERROR: usize = NT_SYSCALL_BASE + 0xBC;
pub const NT_LOAD_DRIVER: usize = NT_SYSCALL_BASE + 0xBD;
pub const NT_UNLOAD_DRIVER: usize = NT_SYSCALL_BASE + 0xBE;
pub const NT_TRACE_EVENT: usize = NT_SYSCALL_BASE + 0xBF;
pub const NT_QUERY_INFORMATION_PROCESS_FULL: usize = NT_SYSCALL_BASE + 0xC0;
pub const NT_QUERY_INFORMATION_THREAD_FULL: usize = NT_SYSCALL_BASE + 0xC1;

// ALPC
pub const NT_ALPC_CREATE_PORT: usize = NT_SYSCALL_BASE + 0xC2;
pub const NT_ALPC_CONNECT_PORT: usize = NT_SYSCALL_BASE + 0xC3;
pub const NT_ALPC_SEND_WAIT_RECEIVE: usize = NT_SYSCALL_BASE + 0xC4;
pub const NT_ALPC_DISCONNECT_PORT: usize = NT_SYSCALL_BASE + 0xC5;

// KTM transactions
pub const NT_CREATE_TRANSACTION_MANAGER: usize = NT_SYSCALL_BASE + 0xC6;
pub const NT_CREATE_TRANSACTION: usize = NT_SYSCALL_BASE + 0xC7;
pub const NT_COMMIT_TRANSACTION: usize = NT_SYSCALL_BASE + 0xC8;
pub const NT_ROLLBACK_TRANSACTION: usize = NT_SYSCALL_BASE + 0xC9;
pub const NT_CREATE_RESOURCE_MANAGER: usize = NT_SYSCALL_BASE + 0xCA;
pub const NT_CREATE_ENLISTMENT: usize = NT_SYSCALL_BASE + 0xCB;

// Power
pub const NT_SET_SYSTEM_POWER_STATE: usize = NT_SYSCALL_BASE + 0xCC;
pub const NT_POWER_INFORMATION: usize = NT_SYSCALL_BASE + 0xCD;
pub const NT_INITIATE_POWER_ACTION: usize = NT_SYSCALL_BASE + 0xCE;

// win32k bridge (shadow SSDT)
pub const NT_WIN32K_CALL: usize = NT_SYSCALL_BASE + 0xCF;

// Maximum syscall number
pub const NT_SYSCALL_COUNT: usize = 0xD0;

/// NT syscall handler function type
pub type NtSyscallHandler = unsafe fn(
    usize, usize, usize, usize, usize, usize
) -> NtStatus;

/// Dispatch table for NT syscalls
pub static mut NT_SYSCALL_TABLE: [NtSyscallHandler; NT_SYSCALL_COUNT] = {
    const INVALID: NtSyscallHandler = |_a, _b, _c, _d, _e, _f| -> NtStatus {
        0xC0000002i32 // STATUS_NOT_IMPLEMENTED
    };
    [INVALID; NT_SYSCALL_COUNT]
};

/// Initialize NT syscall table
pub fn nt_syscall_init() {
    unsafe {
        // File I/O
        NT_SYSCALL_TABLE[NT_CREATE_FILE - NT_SYSCALL_BASE] = nt_syscall_create_file;
        NT_SYSCALL_TABLE[NT_OPEN_FILE - NT_SYSCALL_BASE] = nt_syscall_open_file;
        NT_SYSCALL_TABLE[NT_READ_FILE - NT_SYSCALL_BASE] = nt_syscall_read_file;
        NT_SYSCALL_TABLE[NT_WRITE_FILE - NT_SYSCALL_BASE] = nt_syscall_write_file;
        NT_SYSCALL_TABLE[NT_DELETE_FILE - NT_SYSCALL_BASE] = nt_syscall_delete_file;
        NT_SYSCALL_TABLE[NT_SET_INFORMATION_FILE - NT_SYSCALL_BASE] = nt_syscall_set_information_file;
        NT_SYSCALL_TABLE[NT_QUERY_INFORMATION_FILE - NT_SYSCALL_BASE] = nt_syscall_query_information_file;
        NT_SYSCALL_TABLE[NT_QUERY_DIRECTORY_FILE - NT_SYSCALL_BASE] = nt_syscall_query_directory_file;
        NT_SYSCALL_TABLE[NT_CLOSE - NT_SYSCALL_BASE] = nt_syscall_close;

        // Process/Thread
        NT_SYSCALL_TABLE[NT_CREATE_USER_PROCESS - NT_SYSCALL_BASE] = nt_syscall_create_process;
        NT_SYSCALL_TABLE[NT_CREATE_THREAD - NT_SYSCALL_BASE] = nt_syscall_create_thread;
        NT_SYSCALL_TABLE[NT_OPEN_PROCESS - NT_SYSCALL_BASE] = nt_syscall_open_process;
        NT_SYSCALL_TABLE[NT_TERMINATE_PROCESS - NT_SYSCALL_BASE] = nt_syscall_terminate_process;
        NT_SYSCALL_TABLE[NT_SUSPEND_PROCESS - NT_SYSCALL_BASE] = nt_syscall_suspend_process;
        NT_SYSCALL_TABLE[NT_RESUME_PROCESS - NT_SYSCALL_BASE] = nt_syscall_resume_process;
        NT_SYSCALL_TABLE[NT_QUERY_INFORMATION_PROCESS - NT_SYSCALL_BASE] = nt_syscall_query_information_process;
        NT_SYSCALL_TABLE[NT_SET_INFORMATION_THREAD - NT_SYSCALL_BASE] = nt_syscall_set_information_thread;
        NT_SYSCALL_TABLE[NT_WAIT_FOR_SINGLE_OBJECT - NT_SYSCALL_BASE] = nt_syscall_wait_for_single_object;
        NT_SYSCALL_TABLE[NT_QUERY_INFORMATION_THREAD - NT_SYSCALL_BASE] = nt_syscall_query_information_thread;

        // Memory
        NT_SYSCALL_TABLE[NT_ALLOCATE_VIRTUAL_MEMORY - NT_SYSCALL_BASE] = nt_syscall_allocate_virtual_memory;
        NT_SYSCALL_TABLE[NT_FREE_VIRTUAL_MEMORY - NT_SYSCALL_BASE] = nt_syscall_free_virtual_memory;
        NT_SYSCALL_TABLE[NT_PROTECT_VIRTUAL_MEMORY - NT_SYSCALL_BASE] = nt_syscall_protect_virtual_memory;
        NT_SYSCALL_TABLE[NT_CREATE_SECTION - NT_SYSCALL_BASE] = nt_syscall_create_section;
        NT_SYSCALL_TABLE[NT_MAP_VIEW_OF_SECTION - NT_SYSCALL_BASE] = nt_syscall_map_view_of_section_stub;
        NT_SYSCALL_TABLE[NT_UNMAP_VIEW_OF_SECTION - NT_SYSCALL_BASE] = nt_syscall_unmap_view_of_section;

        // Objects
        NT_SYSCALL_TABLE[NT_CREATE_DIRECTORY_OBJECT - NT_SYSCALL_BASE] = nt_syscall_create_directory_object;
        NT_SYSCALL_TABLE[NT_QUERY_DIRECTORY_OBJECT - NT_SYSCALL_BASE] = nt_syscall_query_directory_object;

        // Registry
        NT_SYSCALL_TABLE[NT_OPEN_KEY - NT_SYSCALL_BASE] = nt_syscall_open_key;
        NT_SYSCALL_TABLE[NT_CREATE_KEY - NT_SYSCALL_BASE] = nt_syscall_create_key;
        NT_SYSCALL_TABLE[NT_DELETE_KEY - NT_SYSCALL_BASE] = nt_syscall_delete_key;
        NT_SYSCALL_TABLE[NT_SET_VALUE_KEY - NT_SYSCALL_BASE] = nt_syscall_set_value_key;
        NT_SYSCALL_TABLE[NT_QUERY_VALUE_KEY - NT_SYSCALL_BASE] = nt_syscall_query_value_key;
        NT_SYSCALL_TABLE[NT_ENUMERATE_KEY - NT_SYSCALL_BASE] = nt_syscall_enumerate_key;

        // Security
        NT_SYSCALL_TABLE[NT_OPEN_PROCESS_TOKEN - NT_SYSCALL_BASE] = nt_syscall_open_process_token;

        // Synchronization
        NT_SYSCALL_TABLE[NT_CREATE_EVENT - NT_SYSCALL_BASE] = nt_syscall_create_event;
        NT_SYSCALL_TABLE[NT_SET_EVENT - NT_SYSCALL_BASE] = nt_syscall_set_event;
        NT_SYSCALL_TABLE[NT_DELAY_EXECUTION - NT_SYSCALL_BASE] = nt_syscall_delay_execution;

        // System Information
        NT_SYSCALL_TABLE[NT_QUERY_SYSTEM_INFORMATION - NT_SYSCALL_BASE] = nt_syscall_query_system_information;

        // ---- Extended table ----
        // File I/O extras
        NT_SYSCALL_TABLE[NT_CANCEL_IO_FILE - NT_SYSCALL_BASE] = nt_syscall_cancel_io_file;
        NT_SYSCALL_TABLE[NT_CREATE_NAMED_PIPE_FILE - NT_SYSCALL_BASE] = nt_syscall_create_named_pipe_file;
        NT_SYSCALL_TABLE[NT_QUERY_VOLUME_INFORMATION_FILE - NT_SYSCALL_BASE] = nt_syscall_query_volume_information_file;
        NT_SYSCALL_TABLE[NT_LOCK_FILE - NT_SYSCALL_BASE] = nt_syscall_lock_file;
        NT_SYSCALL_TABLE[NT_UNLOCK_FILE - NT_SYSCALL_BASE] = nt_syscall_unlock_file;
        // Process/Thread extras
        NT_SYSCALL_TABLE[NT_OPEN_THREAD - NT_SYSCALL_BASE] = nt_syscall_open_thread;
        NT_SYSCALL_TABLE[NT_TERMINATE_THREAD - NT_SYSCALL_BASE] = nt_syscall_terminate_thread;
        NT_SYSCALL_TABLE[NT_SUSPEND_THREAD - NT_SYSCALL_BASE] = nt_syscall_suspend_thread;
        NT_SYSCALL_TABLE[NT_RESUME_THREAD - NT_SYSCALL_BASE] = nt_syscall_resume_thread;
        NT_SYSCALL_TABLE[NT_GET_CONTEXT_THREAD - NT_SYSCALL_BASE] = nt_syscall_get_context_thread;
        NT_SYSCALL_TABLE[NT_SET_CONTEXT_THREAD - NT_SYSCALL_BASE] = nt_syscall_set_context_thread;
        NT_SYSCALL_TABLE[NT_SET_INFORMATION_THREAD_FULL - NT_SYSCALL_BASE] = nt_syscall_set_information_thread_full;
        NT_SYSCALL_TABLE[NT_SET_INFORMATION_PROCESS - NT_SYSCALL_BASE] = nt_syscall_set_information_process;
        NT_SYSCALL_TABLE[NT_CREATE_JOB_OBJECT - NT_SYSCALL_BASE] = nt_syscall_create_job_object;
        NT_SYSCALL_TABLE[NT_ASSIGN_PROCESS_TO_JOB - NT_SYSCALL_BASE] = nt_syscall_assign_process_to_job;
        NT_SYSCALL_TABLE[NT_TERMINATE_JOB_OBJECT - NT_SYSCALL_BASE] = nt_syscall_terminate_job_object;
        NT_SYSCALL_TABLE[NT_READ_VIRTUAL_MEMORY - NT_SYSCALL_BASE] = nt_syscall_read_virtual_memory;
        NT_SYSCALL_TABLE[NT_WRITE_VIRTUAL_MEMORY - NT_SYSCALL_BASE] = nt_syscall_write_virtual_memory;
        NT_SYSCALL_TABLE[NT_CREATE_THREAD_EX - NT_SYSCALL_BASE] = nt_syscall_create_thread_ex;
        NT_SYSCALL_TABLE[NT_CREATE_USER_PROCESS_FULL - NT_SYSCALL_BASE] = nt_syscall_create_user_process_full;
        // Sync extras
        NT_SYSCALL_TABLE[NT_CREATE_MUTANT_FULL - NT_SYSCALL_BASE] = nt_syscall_create_mutant_full;
        NT_SYSCALL_TABLE[NT_OPEN_MUTANT - NT_SYSCALL_BASE] = nt_syscall_open_mutant;
        NT_SYSCALL_TABLE[NT_RELEASE_MUTANT - NT_SYSCALL_BASE] = nt_syscall_release_mutant;
        NT_SYSCALL_TABLE[NT_CREATE_SEMAPHORE_FULL - NT_SYSCALL_BASE] = nt_syscall_create_semaphore_full;
        NT_SYSCALL_TABLE[NT_OPEN_SEMAPHORE - NT_SYSCALL_BASE] = nt_syscall_open_semaphore;
        NT_SYSCALL_TABLE[NT_RELEASE_SEMAPHORE - NT_SYSCALL_BASE] = nt_syscall_release_semaphore;
        NT_SYSCALL_TABLE[NT_QUERY_SEMAPHORE - NT_SYSCALL_BASE] = nt_syscall_query_semaphore;
        NT_SYSCALL_TABLE[NT_CREATE_TIMER - NT_SYSCALL_BASE] = nt_syscall_create_timer;
        NT_SYSCALL_TABLE[NT_OPEN_TIMER - NT_SYSCALL_BASE] = nt_syscall_open_timer;
        NT_SYSCALL_TABLE[NT_SET_TIMER - NT_SYSCALL_BASE] = nt_syscall_set_timer;
        NT_SYSCALL_TABLE[NT_CANCEL_TIMER - NT_SYSCALL_BASE] = nt_syscall_cancel_timer;
        NT_SYSCALL_TABLE[NT_CREATE_KEYED_EVENT - NT_SYSCALL_BASE] = nt_syscall_create_keyed_event;
        NT_SYSCALL_TABLE[NT_OPEN_KEYED_EVENT - NT_SYSCALL_BASE] = nt_syscall_open_keyed_event;
        NT_SYSCALL_TABLE[NT_WAIT_FOR_KEYED_EVENT - NT_SYSCALL_BASE] = nt_syscall_wait_for_keyed_event;
        NT_SYSCALL_TABLE[NT_RELEASE_KEYED_EVENT - NT_SYSCALL_BASE] = nt_syscall_release_keyed_event;
        NT_SYSCALL_TABLE[NT_CREATE_IO_COMPLETION - NT_SYSCALL_BASE] = nt_syscall_create_io_completion;
        NT_SYSCALL_TABLE[NT_SET_IO_COMPLETION - NT_SYSCALL_BASE] = nt_syscall_set_io_completion;
        NT_SYSCALL_TABLE[NT_REMOVE_IO_COMPLETION - NT_SYSCALL_BASE] = nt_syscall_remove_io_completion;
        NT_SYSCALL_TABLE[NT_SIGNAL_AND_WAIT - NT_SYSCALL_BASE] = nt_syscall_signal_and_wait;
        NT_SYSCALL_TABLE[NT_CLEAR_EVENT_FULL - NT_SYSCALL_BASE] = nt_syscall_clear_event_full;
        // Object extras
        NT_SYSCALL_TABLE[NT_DUPLICATE_OBJECT - NT_SYSCALL_BASE] = nt_syscall_duplicate_object;
        NT_SYSCALL_TABLE[NT_MAKE_TEMPORARY_OBJECT - NT_SYSCALL_BASE] = nt_syscall_make_temporary_object;
        NT_SYSCALL_TABLE[NT_QUERY_OBJECT - NT_SYSCALL_BASE] = nt_syscall_query_object;
        NT_SYSCALL_TABLE[NT_CREATE_SYMBOLIC_LINK - NT_SYSCALL_BASE] = nt_syscall_create_symbolic_link;
        NT_SYSCALL_TABLE[NT_OPEN_SYMBOLIC_LINK - NT_SYSCALL_BASE] = nt_syscall_open_symbolic_link;
        NT_SYSCALL_TABLE[NT_QUERY_SYMBOLIC_LINK - NT_SYSCALL_BASE] = nt_syscall_query_symbolic_link;
        // Registry extras
        NT_SYSCALL_TABLE[NT_DELETE_VALUE_KEY - NT_SYSCALL_BASE] = nt_syscall_delete_value_key;
        NT_SYSCALL_TABLE[NT_QUERY_KEY - NT_SYSCALL_BASE] = nt_syscall_query_key;
        NT_SYSCALL_TABLE[NT_ENUMERATE_VALUE_KEY - NT_SYSCALL_BASE] = nt_syscall_enumerate_value_key;
        NT_SYSCALL_TABLE[NT_FLUSH_KEY - NT_SYSCALL_BASE] = nt_syscall_flush_key;
        // Security extras
        NT_SYSCALL_TABLE[NT_OPEN_THREAD_TOKEN - NT_SYSCALL_BASE] = nt_syscall_open_thread_token;
        NT_SYSCALL_TABLE[NT_DUPLICATE_TOKEN - NT_SYSCALL_BASE] = nt_syscall_duplicate_token;
        NT_SYSCALL_TABLE[NT_QUERY_INFORMATION_TOKEN - NT_SYSCALL_BASE] = nt_syscall_query_information_token;
        NT_SYSCALL_TABLE[NT_ACCESS_CHECK - NT_SYSCALL_BASE] = nt_syscall_access_check;
        // System extras
        NT_SYSCALL_TABLE[NT_SHUTDOWN_SYSTEM - NT_SYSCALL_BASE] = nt_syscall_shutdown_system;
        NT_SYSCALL_TABLE[NT_QUERY_SYSTEM_TIME - NT_SYSCALL_BASE] = nt_syscall_query_system_time;
        NT_SYSCALL_TABLE[NT_QUERY_TIMER_RESOLUTION - NT_SYSCALL_BASE] = nt_syscall_query_timer_resolution;
        NT_SYSCALL_TABLE[NT_SET_TIMER_RESOLUTION - NT_SYSCALL_BASE] = nt_syscall_set_timer_resolution;
        NT_SYSCALL_TABLE[NT_QUERY_PERFORMANCE_COUNTER - NT_SYSCALL_BASE] = nt_syscall_query_performance_counter;
        NT_SYSCALL_TABLE[NT_DISPLAY_STRING - NT_SYSCALL_BASE] = nt_syscall_display_string;
        NT_SYSCALL_TABLE[NT_RAISE_HARD_ERROR - NT_SYSCALL_BASE] = nt_syscall_raise_hard_error;
        NT_SYSCALL_TABLE[NT_LOAD_DRIVER - NT_SYSCALL_BASE] = nt_syscall_load_driver;
        NT_SYSCALL_TABLE[NT_UNLOAD_DRIVER - NT_SYSCALL_BASE] = nt_syscall_unload_driver;
        NT_SYSCALL_TABLE[NT_TRACE_EVENT - NT_SYSCALL_BASE] = nt_syscall_trace_event;
        NT_SYSCALL_TABLE[NT_QUERY_INFORMATION_PROCESS_FULL - NT_SYSCALL_BASE] = nt_syscall_query_information_process_full;
        NT_SYSCALL_TABLE[NT_QUERY_INFORMATION_THREAD_FULL - NT_SYSCALL_BASE] = nt_syscall_query_information_thread_full;
        // ALPC
        NT_SYSCALL_TABLE[NT_ALPC_CREATE_PORT - NT_SYSCALL_BASE] = nt_syscall_alpc_create_port;
        NT_SYSCALL_TABLE[NT_ALPC_CONNECT_PORT - NT_SYSCALL_BASE] = nt_syscall_alpc_connect_port;
        NT_SYSCALL_TABLE[NT_ALPC_SEND_WAIT_RECEIVE - NT_SYSCALL_BASE] = nt_syscall_alpc_send_wait_receive;
        NT_SYSCALL_TABLE[NT_ALPC_DISCONNECT_PORT - NT_SYSCALL_BASE] = nt_syscall_alpc_disconnect_port;
        // KTM
        NT_SYSCALL_TABLE[NT_CREATE_TRANSACTION_MANAGER - NT_SYSCALL_BASE] = nt_syscall_create_transaction_manager;
        NT_SYSCALL_TABLE[NT_CREATE_TRANSACTION - NT_SYSCALL_BASE] = nt_syscall_create_transaction;
        NT_SYSCALL_TABLE[NT_COMMIT_TRANSACTION - NT_SYSCALL_BASE] = nt_syscall_commit_transaction;
        NT_SYSCALL_TABLE[NT_ROLLBACK_TRANSACTION - NT_SYSCALL_BASE] = nt_syscall_rollback_transaction;
        NT_SYSCALL_TABLE[NT_CREATE_RESOURCE_MANAGER - NT_SYSCALL_BASE] = nt_syscall_create_resource_manager;
        NT_SYSCALL_TABLE[NT_CREATE_ENLISTMENT - NT_SYSCALL_BASE] = nt_syscall_create_enlistment;
        // Power
        NT_SYSCALL_TABLE[NT_SET_SYSTEM_POWER_STATE - NT_SYSCALL_BASE] = nt_syscall_set_system_power_state;
        NT_SYSCALL_TABLE[NT_POWER_INFORMATION - NT_SYSCALL_BASE] = nt_syscall_power_information;
        NT_SYSCALL_TABLE[NT_INITIATE_POWER_ACTION - NT_SYSCALL_BASE] = nt_syscall_initiate_power_action;
        // win32k bridge
        NT_SYSCALL_TABLE[NT_WIN32K_CALL - NT_SYSCALL_BASE] = nt_syscall_win32k_call;

        crate::kernel_log!("[NtSyscall] Initialized syscall table with {} slots", NT_SYSCALL_COUNT);
    }
}

// ============================================================
// File I/O Syscall Handlers
// ============================================================

unsafe fn nt_syscall_create_file(
    file_handle: usize,
    desired_access: usize,
    object_attributes: usize,
    io_status_block: usize,
    allocation_size: usize,
    file_attributes: usize,
) -> NtStatus {
    crate::nt::nt_create_file(
        file_handle as *mut Handle,
        desired_access as Ulong,
        object_attributes as *const ObjectAttributes,
        io_status_block as *mut IoStatusBlock,
        allocation_size as *mut i64,
        file_attributes as Ulong,
        0, // share_access
        crate::nt::FILE_CREATE,
        0, // create_options
        core::ptr::null_mut(),
        0,
    )
}

unsafe fn nt_syscall_open_file(
    file_handle: usize,
    desired_access: usize,
    object_attributes: usize,
    io_status_block: usize,
    share_access: usize,
    open_options: usize,
) -> NtStatus {
    crate::nt::nt_open_file(
        file_handle as *mut Handle,
        desired_access as Ulong,
        object_attributes as *const ObjectAttributes,
        io_status_block as *mut IoStatusBlock,
        share_access as Ulong,
        open_options as Ulong,
    )
}

unsafe fn nt_syscall_read_file(
    file_handle: usize,
    event: usize,
    apc_routine: usize,
    apc_context: usize,
    io_status_block: usize,
    buffer: usize,
) -> NtStatus {
    crate::nt::nt_read_file(
        file_handle as Handle,
        event as Handle,
        apc_routine as Pvoid,
        apc_context as Pvoid,
        io_status_block as *mut IoStatusBlock,
        buffer as Pvoid,
        4096, // default length
        core::ptr::null_mut(),
        core::ptr::null_mut(),
    )
}

unsafe fn nt_syscall_write_file(
    file_handle: usize,
    event: usize,
    apc_routine: usize,
    apc_context: usize,
    io_status_block: usize,
    buffer: usize,
) -> NtStatus {
    crate::nt::nt_write_file(
        file_handle as Handle,
        event as Handle,
        apc_routine as Pvoid,
        apc_context as Pvoid,
        io_status_block as *mut IoStatusBlock,
        buffer as Pvoid,
        4096, // default length
        core::ptr::null_mut(),
        core::ptr::null_mut(),
    )
}

unsafe fn nt_syscall_delete_file(
    object_attributes: usize,
    _b: usize, _c: usize, _d: usize, _e: usize, _f: usize
) -> NtStatus {
    crate::nt::nt_delete_file(object_attributes as *const ObjectAttributes)
}

unsafe fn nt_syscall_query_information_file(
    file_handle: usize,
    io_status_block: usize,
    file_information: usize,
    length: usize,
    file_information_class: usize,
    _f: usize,
) -> NtStatus {
    crate::nt::nt_query_information_file(
        file_handle as Handle,
        io_status_block as *mut IoStatusBlock,
        file_information as Pvoid,
        length as Ulong,
        core::mem::transmute::<u32, crate::nt::FileInformationClass>(file_information_class as u32),
    )
}

unsafe fn nt_syscall_set_information_file(
    file_handle: usize,
    io_status_block: usize,
    file_information: usize,
    length: usize,
    file_information_class: usize,
    _f: usize,
) -> NtStatus {
    crate::nt::nt_set_information_file(
        file_handle as Handle,
        io_status_block as *mut IoStatusBlock,
        file_information as Pvoid,
        length as Ulong,
        core::mem::transmute::<u32, crate::nt::FileInformationClass>(file_information_class as u32),
    )
}

// QueryDirectoryFile needs 11 args; pack via pointer
#[repr(C)]
struct QueryDirArgs {
    file_handle: usize,
    event: usize,
    apc_routine: usize,
    apc_context: usize,
    io_status_block: usize,
    file_information: usize,
    length: usize,
    file_information_class: usize,
    return_single_entry: usize,
    file_name: usize,
    restart_scan: usize,
}

unsafe fn nt_syscall_query_directory_file(
    args_ptr: usize,
    _b: usize, _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    if args_ptr == 0 { return STATUS_INVALID_PARAMETER; }
    let args = &*(args_ptr as *const QueryDirArgs);
    crate::nt::nt_query_directory_file(
        args.file_handle as Handle,
        args.event as Handle,
        args.apc_routine as Pvoid,
        args.apc_context as Pvoid,
        args.io_status_block as *mut IoStatusBlock,
        args.file_information as Pvoid,
        args.length as Ulong,
        args.file_information_class as u32,
        args.return_single_entry as Boolean,
        args.file_name as *const UnicodeString,
        args.restart_scan as Boolean,
    )
}

unsafe fn nt_syscall_close(
    handle: usize,
    _b: usize, _c: usize, _d: usize, _e: usize, _f: usize
) -> NtStatus {
    crate::nt::nt_close(handle as Handle)
}

// ============================================================
// Process/Thread Syscall Handlers
// ============================================================

unsafe fn nt_syscall_create_process(
    process_handle: usize,
    desired_access: usize,
    object_attributes: usize,
    parent_process: usize,
    inherit_handles: usize,
    section_handle: usize,
) -> NtStatus {
    crate::nt::nt_create_process(
        process_handle as *mut Handle,
        desired_access as Ulong,
        object_attributes as *const ObjectAttributes,
        parent_process as Handle,
        inherit_handles as Boolean,
        section_handle as Handle,
        core::ptr::null_mut(),
        core::ptr::null_mut(),
    )
}

unsafe fn nt_syscall_open_process(
    process_handle: usize,
    desired_access: usize,
    object_attributes: usize,
    client_id: usize,
    _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_open_process(
        process_handle as *mut Handle,
        desired_access as Ulong,
        object_attributes as *const ObjectAttributes,
        client_id as *mut ClientId,
    )
}

unsafe fn nt_syscall_create_thread(
    thread_handle: usize,
    process_handle: usize,
    start_address: usize,
    user_stack: usize,
    create_flags: usize,
    _f: usize,
) -> NtStatus {
    crate::nt::nt_create_thread(
        thread_handle as *mut Handle,
        0x001F0FFF, // THREAD_ALL_ACCESS
        core::ptr::null(), // object_attributes
        process_handle as Handle,
        core::ptr::null_mut(), // client_id
        core::ptr::null_mut(), // context
        user_stack as Pvoid,
        create_flags as u32,
        0, 0, 0, 0,
    )
}

unsafe fn nt_syscall_terminate_process(
    process_handle: usize,
    exit_status: usize,
    _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_terminate_process(process_handle as Handle, exit_status as NtStatus)
}

unsafe fn nt_syscall_suspend_process(
    process_handle: usize,
    _b: usize, _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    if process_handle == 0 { return STATUS_INVALID_PARAMETER; }
    let process = process_handle as *mut crate::ps::Eprocess;
    crate::ps::ps_suspend_process(process)
}

unsafe fn nt_syscall_resume_process(
    process_handle: usize,
    _b: usize, _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    if process_handle == 0 { return STATUS_INVALID_PARAMETER; }
    let process = process_handle as *mut crate::ps::Eprocess;
    crate::ps::ps_resume_process(process)
}

unsafe fn nt_syscall_query_information_process(
    process_handle: usize,
    process_info_class: usize,
    process_info: usize,
    return_length: usize,
    _e: usize, _f: usize,
) -> NtStatus {
    if process_handle == 0 { return STATUS_INVALID_PARAMETER; }
    let process = process_handle as *mut crate::ps::Eprocess;
    let proc_ref = &*process;

    match process_info_class as u32 {
        0 => {
            // ProcessBasicInformation
            if return_length != 0 {
                *(return_length as *mut u32) = core::mem::size_of::<ProcessBasicInformation>() as u32;
            }
            if process_info != 0 {
                let info = &mut *(process_info as *mut ProcessBasicInformation);
                info.exit_status = proc_ref.exit_status;
                info.unique_process_id = proc_ref.unique_process_id as usize;
                info.inherited_from_unique_process_id = 0;
                info.base_priority = proc_ref.kprocess.base_priority as i32;
            }
            STATUS_SUCCESS
        }
        7 => {
            // ProcessDebugPort
            if process_info != 0 {
                *(process_info as *mut u64) = 0;
            }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

unsafe fn nt_syscall_set_information_thread(
    _thread_handle: usize,
    _info_class: usize,
    _info: usize,
    _info_length: usize,
    _e: usize, _f: usize,
) -> NtStatus {
    STATUS_SUCCESS
}

unsafe fn nt_syscall_wait_for_single_object(
    handle: usize,
    alertable: usize,
    timeout: usize,
    _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_wait_for_single_object(
        handle as Handle,
        alertable as Boolean,
        timeout as *mut i64,
    )
}

unsafe fn nt_syscall_query_information_thread(
    _thread_handle: usize,
    _info_class: usize,
    _info: usize,
    _return_length: usize,
    _e: usize, _f: usize,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Memory Syscall Handlers
// ============================================================

unsafe fn nt_syscall_allocate_virtual_memory(
    process_handle: usize,
    base_address: usize,
    zero_bits: usize,
    region_size: usize,
    allocation_type: usize,
    protect: usize,
) -> NtStatus {
    crate::mm::mm_allocate_virtual_memory(
        process_handle as Pvoid,
        base_address as *mut Pvoid,
        zero_bits as u64,
        region_size as *mut usize,
        allocation_type as u32,
        protect as u32,
    )
}

unsafe fn nt_syscall_free_virtual_memory(
    process_handle: usize,
    base_address: usize,
    region_size: usize,
    free_type: usize,
    _e: usize, _f: usize,
) -> NtStatus {
    crate::mm::mm_free_virtual_memory(
        process_handle as Pvoid,
        base_address as *mut Pvoid,
        region_size as *mut usize,
        free_type as u32,
    )
}

unsafe fn nt_syscall_protect_virtual_memory(
    process_handle: usize,
    base_address: usize,
    region_size: usize,
    new_protect: usize,
    old_protect: usize,
    _f: usize,
) -> NtStatus {
    crate::mm::mm_protect_virtual_memory(
        process_handle as Pvoid,
        base_address as *mut Pvoid,
        region_size as *mut usize,
        new_protect as u32,
        old_protect as *mut u32,
    )
}

unsafe fn nt_syscall_create_section(
    section_handle: usize,
    desired_access: usize,
    object_attributes: usize,
    maximum_size: usize,
    section_page_protection: usize,
    allocation_attributes: usize,
) -> NtStatus {
    crate::nt::nt_create_section(
        section_handle as *mut Handle,
        desired_access as Ulong,
        object_attributes as *const ObjectAttributes,
        maximum_size as *mut i64,
        section_page_protection as Ulong,
        allocation_attributes as Ulong,
        core::ptr::null_mut(), // file_handle
    )
}

unsafe fn nt_syscall_map_view_of_section(
    section_handle: usize,
    process_handle: usize,
    base_address: usize,
    _zero_bits: usize,
    _commit_size: usize,
    _section_offset: usize,
    _view_size: usize,
    _inherit_disposition: usize,
    _allocation_type: usize,
    win32_protect: usize,
) -> NtStatus {
    // MapViewOfSection needs 10 args but handler only has 6.
    // Pack the remaining args through a struct on stack.
    // For simplicity, use the nt_map_view_of_section with defaults.
    crate::nt::nt_map_view_of_section(
        section_handle as Handle,
        process_handle as Handle,
        base_address as *mut Pvoid,
        0, // zero_bits
        0, // commit_size
        core::ptr::null_mut(), // section_offset
        core::ptr::null_mut(), // view_size
        1, // VIEW_SHARE
        0, // allocation_type
        win32_protect as Ulong,
    )
}

unsafe fn nt_syscall_unmap_view_of_section(
    process_handle: usize,
    base_address: usize,
    _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_unmap_view_of_section(
        process_handle as Handle,
        base_address as Pvoid,
    )
}

// MapViewOfSection needs 10 args; pack via pointer to args struct
#[repr(C)]
struct MapViewArgs {
    section_handle: usize,
    process_handle: usize,
    base_address: usize,
    zero_bits: usize,
    commit_size: usize,
    section_offset: usize,
    view_size: usize,
    inherit_disposition: usize,
    allocation_type: usize,
    win32_protect: usize,
}

unsafe fn nt_syscall_map_view_of_section_stub(
    args_ptr: usize,
    _b: usize, _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    if args_ptr == 0 { return STATUS_INVALID_PARAMETER; }
    let args = &*(args_ptr as *const MapViewArgs);
    crate::nt::nt_map_view_of_section(
        args.section_handle as Handle,
        args.process_handle as Handle,
        args.base_address as *mut Pvoid,
        args.zero_bits as u64,
        args.commit_size,
        core::ptr::null_mut(),
        core::ptr::null_mut(),
        args.inherit_disposition as u32,
        args.allocation_type as u32,
        args.win32_protect as Ulong,
    )
}

// ============================================================
// Object Syscall Handlers
// ============================================================

unsafe fn nt_syscall_create_directory_object(
    directory_handle: usize,
    desired_access: usize,
    object_attributes: usize,
    _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    if object_attributes == 0 { return STATUS_INVALID_PARAMETER; }
    let attrs = &*(object_attributes as *const ObjectAttributes);
    if attrs.object_name.is_null() { return STATUS_INVALID_PARAMETER; }

    crate::ob::ob_create_directory_object(
        attrs.object_name as *mut UnicodeString,
        desired_access as Ulong,
        object_attributes as *mut ObjectAttributes,
        0, 0,
    )
}

unsafe fn nt_syscall_query_directory_object(
    directory_handle: usize,
    object_information: usize,
    length: usize,
    return_single_entry: usize,
    restart_scan: usize,
    context: usize,
) -> NtStatus {
    crate::nt::nt_query_directory_object(
        directory_handle as Handle,
        object_information as Pvoid,
        length as Ulong,
        return_single_entry as Boolean,
        restart_scan as Boolean,
        context as *mut u32,
    )
}

// ============================================================
// Registry Syscall Handlers
// ============================================================

unsafe fn nt_syscall_open_key(
    key_handle: usize,
    desired_access: usize,
    object_attributes: usize,
    _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_open_key(
        key_handle as *mut Handle,
        desired_access as Ulong,
        object_attributes as *const ObjectAttributes,
    )
}

unsafe fn nt_syscall_create_key(
    key_handle: usize,
    desired_access: usize,
    object_attributes: usize,
    title_index: usize,
    disposition: usize,
    _f: usize,
) -> NtStatus {
    crate::nt::nt_create_key(
        key_handle as *mut Handle,
        desired_access as Ulong,
        object_attributes as *const ObjectAttributes,
        title_index as u32,
        core::ptr::null(), // class_name
        0, // create_options
        disposition as *mut u32,
    )
}

unsafe fn nt_syscall_set_value_key(
    key_handle: usize,
    value_name: usize,
    title_index: usize,
    data_type: usize,
    data: usize,
    data_size: usize,
) -> NtStatus {
    crate::nt::nt_set_value_key(
        key_handle as Handle,
        value_name as *const UnicodeString,
        title_index as Ulong,
        data_type as Ulong,
        data as Pvoid,
        data_size as Ulong,
    )
}

unsafe fn nt_syscall_query_value_key(
    key_handle: usize,
    value_name: usize,
    info_class: usize,
    info: usize,
    length: usize,
    return_length: usize,
) -> NtStatus {
    crate::nt::nt_query_value_key(
        key_handle as Handle,
        value_name as *const UnicodeString,
        info_class as u32,
        info as Pvoid,
        length as Ulong,
        return_length as *mut Ulong,
    )
}

unsafe fn nt_syscall_enumerate_key(
    key_handle: usize,
    index: usize,
    info_class: usize,
    info: usize,
    length: usize,
    return_length: usize,
) -> NtStatus {
    crate::nt::nt_enumerate_key(
        key_handle as Handle,
        index as u32,
        info_class as u32,
        info as Pvoid,
        length as Ulong,
        return_length as *mut Ulong,
    )
}

unsafe fn nt_syscall_delete_key(
    key_handle: usize,
    _b: usize, _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_delete_key(key_handle as Handle)
}

// ============================================================
// Security Syscall Handlers
// ============================================================

unsafe fn nt_syscall_open_process_token(
    process_handle: usize,
    desired_access: usize,
    token_handle: usize,
    _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    // Create a dummy token handle for now
    if token_handle != 0 {
        // Allocate a minimal token object
        let token_layout = core::alloc::Layout::from_size_align(64, 8).unwrap();
        let token = crate::mm::ex_allocate_pool(0, 64, crate::mm::PoolTag(*b"TOKN"));
        if !token.is_null() {
            core::ptr::write_bytes(token, 0, 64);
            *(token_handle as *mut Handle) = token as Handle;
        } else {
            return STATUS_NO_MEMORY;
        }
    }
    STATUS_SUCCESS
}

// ============================================================
// Synchronization Syscall Handlers
// ============================================================

unsafe fn nt_syscall_create_event(
    event_handle: usize,
    object_attributes: usize,
    event_type: usize,
    initial_state: usize,
    _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_create_event(
        event_handle as *mut Handle,
        0x001F0003, // EVENT_MODIFY_STATE | SYNCHRONIZE
        object_attributes as *const ObjectAttributes,
        event_type as Ulong,
        initial_state as Boolean,
    )
}

unsafe fn nt_syscall_set_event(
    event_handle: usize,
    _b: usize, _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_set_event(event_handle as Handle, core::ptr::null_mut())
}

unsafe fn nt_syscall_delay_execution(
    alertable: usize,
    delay_interval: usize,
    _c: usize, _d: usize, _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_delay_execution(alertable as Boolean, delay_interval as *mut i64)
}

// ============================================================
// System Information Syscall Handlers
// ============================================================

unsafe fn nt_syscall_query_system_information(
    info_class: usize,
    info: usize,
    length: usize,
    return_length: usize,
    _e: usize, _f: usize,
) -> NtStatus {
    crate::nt::nt_query_system_information(
        core::mem::transmute::<u32, crate::nt::SystemInformationClass>(info_class as u32),
        info as Pvoid,
        length as Ulong,
        return_length as *mut Ulong,
    )
}

// ============================================================
// Extended Syscall Handlers (backed by crate::nt::ext)
// ============================================================

unsafe fn nt_syscall_cancel_io_file(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_cancel_io_file(a as Handle, b as *mut IoStatusBlock)
}

unsafe fn nt_syscall_create_named_pipe_file(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    // 14-arg call; first 6 inline, rest defaulted (byte mode, 1 instance).
    crate::nt::ext::nt_create_named_pipe_file(
        a as *mut Handle, b as Ulong, c as *const ObjectAttributes,
        d as *mut IoStatusBlock, e as Ulong, f as Ulong, 0, 0, 0, 0, 1, 4096, 4096,
        core::ptr::null_mut(),
    )
}

unsafe fn nt_syscall_query_volume_information_file(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_volume_information_file(
        a as Handle, b as *mut IoStatusBlock, c as Pvoid, d as Ulong, e as u32,
    )
}

unsafe fn nt_syscall_lock_file(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    // Packed: event=b, iosb=c, off=d(*u64), len=e(*u64), key+flags=f packed u32 pair.
    crate::nt::ext::nt_lock_file(
        a as Handle, 0 as Handle, core::ptr::null_mut(), core::ptr::null_mut(),
        c as *mut IoStatusBlock, d as *const u64, e as *const u64,
        (f & 0xFFFF_FFFF) as u32, ((f >> 32) & 1) as Boolean, ((f >> 33) & 1) as Boolean,
    )
}

unsafe fn nt_syscall_unlock_file(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_unlock_file(
        a as Handle, b as *mut IoStatusBlock, c as *const u64, d as *const u64, 0,
    )
}

unsafe fn nt_syscall_open_thread(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_open_thread(a as *mut Handle, b as Ulong, c as *const ObjectAttributes, d as *const ClientId)
}

unsafe fn nt_syscall_terminate_thread(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_terminate_thread(a as Handle, b as NtStatus)
}

unsafe fn nt_syscall_suspend_thread(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_suspend_thread(a as Handle, b as *mut u32)
}

unsafe fn nt_syscall_resume_thread(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_resume_thread(a as Handle, b as *mut u32)
}

unsafe fn nt_syscall_get_context_thread(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_get_context_thread(a as Handle, b as Pvoid)
}

unsafe fn nt_syscall_set_context_thread(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_set_context_thread(a as Handle, b as Pvoid)
}

unsafe fn nt_syscall_set_information_thread_full(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_set_information_thread(a as Handle, b as u32, c as Pvoid, d as Ulong)
}

unsafe fn nt_syscall_set_information_process(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_set_information_process(a as Handle, b as u32, c as Pvoid, d as Ulong)
}

unsafe fn nt_syscall_create_job_object(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_job_object(a as *mut Handle, b as Ulong, c as *const ObjectAttributes)
}

unsafe fn nt_syscall_assign_process_to_job(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_assign_process_to_job_object(a as Handle, b as Handle)
}

unsafe fn nt_syscall_terminate_job_object(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_terminate_job_object(a as Handle, b as NtStatus)
}

unsafe fn nt_syscall_read_virtual_memory(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_read_virtual_memory(a as Handle, b as Pvoid, c as Pvoid, d, e as *mut usize)
}

unsafe fn nt_syscall_write_virtual_memory(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_write_virtual_memory(a as Handle, b as Pvoid, c as Pvoid, d, e as *mut usize)
}

unsafe fn nt_syscall_create_thread_ex(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_create_thread_ex(
        a as *mut Handle, b as Ulong, c as *const ObjectAttributes,
        d as Handle, e as Pvoid, core::ptr::null_mut(), f as Ulong,
    )
}

unsafe fn nt_syscall_create_user_process_full(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_create_user_process(
        a as *mut Handle, b as *mut Handle, c as Ulong, d as *const ObjectAttributes,
        e as Handle, f as Ulong, 0 as Handle, 0 as Handle, 0 as Handle, 0 as Handle,
    )
}

unsafe fn nt_syscall_create_mutant_full(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_mutant(a as *mut Handle, b as Ulong, c as *const ObjectAttributes, d as Boolean)
}

unsafe fn nt_syscall_open_mutant(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_open_mutant(a as *mut Handle, b as Ulong, c as *const ObjectAttributes)
}

unsafe fn nt_syscall_release_mutant(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_release_mutant(a as Handle, b as *mut i32)
}

unsafe fn nt_syscall_create_semaphore_full(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_semaphore(a as *mut Handle, b as Ulong, c as *const ObjectAttributes, d as i32, e as i32)
}

unsafe fn nt_syscall_open_semaphore(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_open_semaphore(a as *mut Handle, b as Ulong, c as *const ObjectAttributes)
}

unsafe fn nt_syscall_release_semaphore(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_release_semaphore(a as Handle, b as i32, c as *mut i32)
}

unsafe fn nt_syscall_query_semaphore(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_semaphore(a as Handle, b as Pvoid, c as Ulong)
}

unsafe fn nt_syscall_create_timer(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_timer(a as *mut Handle, b as Ulong, c as *const ObjectAttributes, d as u32)
}

unsafe fn nt_syscall_open_timer(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_open_timer(a as *mut Handle, b as Ulong, c as *const ObjectAttributes)
}

unsafe fn nt_syscall_set_timer(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_set_timer(a as Handle, b as *const i64, c as Pvoid, d as Pvoid, e as Boolean, f as i32, core::ptr::null_mut())
}

unsafe fn nt_syscall_cancel_timer(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_cancel_timer(a as Handle, b as *mut Boolean)
}

unsafe fn nt_syscall_create_keyed_event(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_keyed_event(a as *mut Handle, b as Ulong, c as *const ObjectAttributes)
}

unsafe fn nt_syscall_open_keyed_event(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_open_keyed_event(a as *mut Handle, b as Ulong, c as *const ObjectAttributes)
}

unsafe fn nt_syscall_wait_for_keyed_event(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_wait_for_keyed_event(a as Handle, b as Pvoid, c as Boolean, d as *mut i64)
}

unsafe fn nt_syscall_release_keyed_event(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_release_keyed_event(a as Handle, b as Pvoid, c as Boolean, d as *mut i64)
}

unsafe fn nt_syscall_create_io_completion(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_io_completion(a as *mut Handle, b as Ulong, c as *const ObjectAttributes)
}

unsafe fn nt_syscall_set_io_completion(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_set_io_completion(a as Handle, b as Pvoid, c as Pvoid, d as NtStatus, e)
}

unsafe fn nt_syscall_remove_io_completion(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_remove_io_completion(
        a as Handle, b as *mut Pvoid, c as *mut Pvoid, d as *mut NtStatus, e as *mut usize, f as *mut i64,
    )
}

unsafe fn nt_syscall_signal_and_wait(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_signal_and_wait_for_single_object(a as Handle, b as Handle, c as Boolean, d as *mut i64)
}

unsafe fn nt_syscall_clear_event_full(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_clear_event(a as Handle)
}

unsafe fn nt_syscall_duplicate_object(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_duplicate_object(a as Handle, b as Handle, c as Handle, d as *mut Handle, e as Ulong, 0, f as Ulong)
}

unsafe fn nt_syscall_make_temporary_object(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_make_temporary_object(a as Handle)
}

unsafe fn nt_syscall_query_object(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_object(a as Handle, b as u32, c as Pvoid, d as Ulong, e as *mut Ulong)
}

unsafe fn nt_syscall_create_symbolic_link(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_symbolic_link_object(
        a as *mut Handle, b as Ulong, c as *const ObjectAttributes, d as *const UnicodeString,
    )
}

unsafe fn nt_syscall_open_symbolic_link(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_open_symbolic_link_object(a as *mut Handle, b as Ulong, c as *const ObjectAttributes)
}

unsafe fn nt_syscall_query_symbolic_link(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_symbolic_link_object(a as Handle, b as *mut UnicodeString, c as *mut Ulong)
}

unsafe fn nt_syscall_delete_value_key(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_delete_value_key(a as Handle, b as *const UnicodeString)
}

unsafe fn nt_syscall_query_key(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_key(a as Handle, b as u32, c as Pvoid, d as Ulong, e as *mut Ulong)
}

unsafe fn nt_syscall_enumerate_value_key(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_enumerate_value_key(a as Handle, b as u32, c as u32, d as Pvoid, e as Ulong, f as *mut Ulong)
}

unsafe fn nt_syscall_flush_key(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_flush_key(a as Handle)
}

unsafe fn nt_syscall_open_thread_token(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_open_thread_token(a as Handle, b as Ulong, c as Boolean, d as *mut Handle)
}

unsafe fn nt_syscall_duplicate_token(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_duplicate_token(a as Handle, b as Ulong, c as *const ObjectAttributes, d as Boolean, e as u32, f as *mut Handle)
}

unsafe fn nt_syscall_query_information_token(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_information_token(a as Handle, b as u32, c as Pvoid, d as Ulong, e as *mut Ulong)
}

unsafe fn nt_syscall_access_check(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    // Packed: sd=a, token=b, access=c, mapping=d, privs=e(ptr), lens=f(ptr to 3 words)
    crate::nt::ext::nt_access_check(
        a as Pvoid, b as Handle, c as Ulong, d as Pvoid,
        e as Pvoid, f as *mut Ulong, (f as *mut Ulong).add(1), (f as *mut NtStatus).add(2),
    )
}

unsafe fn nt_syscall_shutdown_system(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_shutdown_system(a as u32)
}

unsafe fn nt_syscall_query_system_time(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_system_time(a as *mut i64)
}

unsafe fn nt_syscall_query_timer_resolution(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_timer_resolution(a as *mut u32, b as *mut u32, c as *mut u32)
}

unsafe fn nt_syscall_set_timer_resolution(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_set_timer_resolution(a as u32, b as Boolean, c as *mut u32)
}

unsafe fn nt_syscall_query_performance_counter(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_performance_counter(a as *mut i64, b as *mut i64)
}

unsafe fn nt_syscall_display_string(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_display_string(a as *const UnicodeString)
}

unsafe fn nt_syscall_raise_hard_error(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_raise_hard_error(a as NtStatus, b as Pvoid, c as *mut u32)
}

unsafe fn nt_syscall_load_driver(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_load_driver(a as *const UnicodeString)
}

unsafe fn nt_syscall_unload_driver(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_unload_driver(a as *const UnicodeString)
}

unsafe fn nt_syscall_trace_event(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_trace_event(a as u64, b as u32, c as u32, d as Pvoid)
}

unsafe fn nt_syscall_query_information_process_full(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_information_process(a as Handle, b as u32, c as Pvoid, d as Ulong, e as *mut Ulong)
}

unsafe fn nt_syscall_query_information_thread_full(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_query_information_thread(a as Handle, b as u32, c as Pvoid, d as Ulong, e as *mut Ulong)
}

unsafe fn nt_syscall_alpc_create_port(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_alpc_create_port(a as *mut Handle, b as *const ObjectAttributes, c as Pvoid)
}

unsafe fn nt_syscall_alpc_connect_port(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_alpc_connect_port(
        a as *mut Handle, b as *const UnicodeString, c as *const ObjectAttributes,
        d as Pvoid, e as Ulong, f as Pvoid,
        core::ptr::null_mut(), core::ptr::null_mut(), core::ptr::null_mut(),
        core::ptr::null_mut(), core::ptr::null_mut(),
    )
}

unsafe fn nt_syscall_alpc_send_wait_receive(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_alpc_send_wait_receive_port(
        a as Handle, b as Ulong, c as Pvoid, d as Pvoid, e as Pvoid,
        f as *mut usize, core::ptr::null_mut(), core::ptr::null_mut(),
    )
}

unsafe fn nt_syscall_alpc_disconnect_port(a: usize, _b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_alpc_disconnect_port(a as Handle)
}

unsafe fn nt_syscall_create_transaction_manager(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_transaction_manager(
        a as *mut Handle, b as Ulong, c as *const ObjectAttributes, d as *const UnicodeString, e as Ulong,
    )
}

unsafe fn nt_syscall_create_transaction(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_create_transaction(
        a as *mut Handle, b as Ulong, c as *const ObjectAttributes, core::ptr::null_mut(),
        d as Handle, e as Ulong, f as Ulong, 0, 0, core::ptr::null(),
    )
}

unsafe fn nt_syscall_commit_transaction(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_commit_transaction(a as Handle, b as Boolean)
}

unsafe fn nt_syscall_rollback_transaction(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_rollback_transaction(a as Handle, b as Boolean)
}

unsafe fn nt_syscall_create_resource_manager(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_create_resource_manager(
        a as *mut Handle, b as Ulong, c as Handle, d as Pvoid, e as *const ObjectAttributes,
    )
}

unsafe fn nt_syscall_create_enlistment(a: usize, b: usize, c: usize, d: usize, e: usize, f: usize) -> NtStatus {
    crate::nt::ext::nt_create_enlistment(
        a as *mut Handle, b as Ulong, c as Handle, d as Handle,
        e as *const ObjectAttributes, 0, f as Ulong,
    )
}

unsafe fn nt_syscall_set_system_power_state(a: usize, b: usize, _c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_set_system_power_state(a as Ulong, b as Ulong)
}

unsafe fn nt_syscall_power_information(a: usize, b: usize, c: usize, d: usize, e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_power_information(a as u32, b as Pvoid, c as Ulong, d as Pvoid, e as Ulong)
}

unsafe fn nt_syscall_initiate_power_action(a: usize, b: usize, c: usize, d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_initiate_power_action(a as Ulong, b as Ulong, c as Ulong, d as Boolean)
}

unsafe fn nt_syscall_win32k_call(a: usize, b: usize, c: usize, _d: usize, _e: usize, _f: usize) -> NtStatus {
    crate::nt::ext::nt_win32k_call(a as u32, b as *const u64, c as u32) as NtStatus
}

/// Dispatch an NT syscall
pub unsafe fn nt_syscall_dispatch(
    number: usize,
    a: usize, b: usize, c: usize, d: usize, e: usize, f: usize,
) -> NtStatus {
    let index = number - NT_SYSCALL_BASE;
    if index >= NT_SYSCALL_COUNT {
        return 0xC0000002; // STATUS_NOT_IMPLEMENTED
    }

    let handler = NT_SYSCALL_TABLE[index];
    handler(a, b, c, d, e, f)
}

// ============================================================
// Internal structs for QueryInformationProcess
// ============================================================

#[repr(C)]
pub struct ProcessBasicInformation {
    pub exit_status: NtStatus,
    pub peb_base_address: Pvoid,
    pub affinity_mask: usize,
    pub base_priority: i32,
    pub unique_process_id: usize,
    pub inherited_from_unique_process_id: usize,
}
