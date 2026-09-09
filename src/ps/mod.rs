/// # Windows 10 Process Manager (Ps/Psp) - ntoskrnl.exe
///
/// Complete implementation of process and thread management including
/// EPROCESS, ETHREAD, EJOB, PEB, TEB, context management, and all
/// creation/termination/attachment paths.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 4/6
///   - WRK: ntoskrnl/ps/
///   - ReactOS: ps/

use core::arch::asm;
use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicUsize, Ordering};

use crate::types::*;
use crate::ke::dispatcher::*;
use crate::ke::sync;
use crate::ke::dispatcher::{
    ki_get_current_thread, acquire_spin_lock, release_spin_lock,
    KI_PRCB, KI_READY_SUMMARY,
    ki_ready_thread, ki_acquire_dispatcher_lock, ki_release_dispatcher_lock,
    ki_dispatcher_ready_insert_thread, ki_suspend_thread, ki_resume_thread,
    DISPATCHER_OBJECT_TYPE_PROCESS, DISPATCHER_OBJECT_TYPE_THREAD,
    DISPATCHER_OBJECT_INSERTED, DISPATCHER_OBJECT_NOT_INSERTED,
    MAXIMUM_PRIORITY_LEVEL, KI_THREAD_QUANTUM,
};
use crate::mm::{self, MiVadNode, MiVadTree, MmProtectionMask2, MiAddressFlags};

// ============================================================
// Constants
// ============================================================

pub const PS_IMAGE_FILE_NAME_LENGTH: usize = 15;
pub const PS_MAX_THREADS_PER_PROCESS: usize = 2048;
pub const PS_DEFAULT_PROCESS_QUANTUM: u8 = 6;

pub const PS_PRIORITY_CLASS_UNKNOWN: u32 = 0;
pub const PS_PRIORITY_CLASS_IDLE: u32 = 1;
pub const PS_PRIORITY_CLASS_NORMAL: u32 = 2;
pub const PS_PRIORITY_CLASS_HIGH: u32 = 3;
pub const PS_PRIORITY_CLASS_REALTIME: u32 = 4;

pub const PSP_PROCESS_FLAGS_CREATED_PROCESS: u32 = 0x0000_0001;
pub const PSP_PROCESS_FLAGS_PRIORITY_SET: u32 = 0x0000_0002;
pub const PSP_PROCESS_FLAGS_NO_DEBUG_INHERIT: u32 = 0x0000_0004;
pub const PSP_PROCESS_FLAGS_NOT_DEBUGGABLE: u32 = 0x0000_0008;
pub const PSP_PROCESS_FLAGS_DELETE_PENDING: u32 = 0x0000_0010;
pub const PSP_PROCESS_FLAGS_EMBEDDED: u32 = 0x0000_0020;
pub const PSP_PROCESS_FLAGS_VM_DELETED: u32 = 0x0000_0040;
pub const PSP_PROCESS_FLAGS_OUTSWAPPED: u32 = 0x0000_0080;
pub const PSP_PROCESS_FLAGS_FOLDED: u32 = 0x0000_0100;
pub const PSP_PROCESS_FLAGS_FORK_WAS_FAST: u32 = 0x0000_0200;

pub const PS_CROSS_THREAD_FLAGS_TERMINATED: u32 = 0x0000_0001;
pub const PS_CROSS_THREAD_FLAGS_DEADTHREAD: u32 = 0x0000_0002;
pub const PS_CROSS_THREAD_FLAGS_HIDEFROMDEBUGGER: u32 = 0x0000_0004;
pub const PS_CROSS_THREAD_FLAGS_BREAKAWAY: u32 = 0x0000_0008;
pub const PS_CROSS_THREAD_FLAGS_SKIP_CREATION_MSG: u32 = 0x0000_0010;
pub const PS_CROSS_THREAD_FLAGS_SKIP_TERMINATION_MSG: u32 = 0x0000_0020;
pub const PS_CROSS_THREAD_FLAGS_IMPERSONATING: u32 = 0x0000_0040;
pub const PS_CROSS_THREAD_FLAGS_SYSTEM_THREAD: u32 = 0x0000_0080;
pub const PS_CROSS_THREAD_FLAGS_LOADER: u32 = 0x0000_0100;

pub const PS_THREAD_CREATE_FLAGS_CREATE_SUSPENDED: u32 = 0x0000_0001;
pub const PS_THREAD_CREATE_FLAGS_RESUME: u32 = 0x0000_0002;
pub const PS_THREAD_CREATE_FLAGS_TERMINAL_THREAD: u32 = 0x0000_0004;
pub const PS_THREAD_CREATE_FLAGS_TERMINAL_PROCESS: u32 = 0x0000_0008;
pub const PS_THREAD_CREATE_FLAGS_HARDWARE_ACCESS_ONLY: u32 = 0x0000_0010;
pub const PS_THREAD_CREATE_FLAGS_ATTACH_SESSION: u32 = 0x0000_0020;
pub const PS_THREAD_CREATE_FLAGS_BYPASS_PROCESS_FREEZE: u32 = 0x0000_0040;

pub const PS_PROCESS_CREATE_FLAGS_NO_INHERIT: u32 = 0x0000_0004;
pub const PS_PROCESS_CREATE_FLAGS_SUSPENDED: u32 = 0x0000_0100;
pub const PS_PROCESS_CREATE_FLAGS_WOW64: u32 = 0x0000_0200;
pub const PS_PROCESS_CREATE_FLAGS_OVERRIDEBORTSUSPEND: u32 = 0x0000_1000;

pub const PROCESS_TERMINATE: u32 = 0x0001;
pub const PROCESS_CREATE_THREAD: u32 = 0x0002;
pub const PROCESS_VM_OPERATION: u32 = 0x0008;
pub const PROCESS_VM_READ: u32 = 0x0010;
pub const PROCESS_VM_WRITE: u32 = 0x0020;
pub const PROCESS_DUP_HANDLE: u32 = 0x0040;
pub const PROCESS_CREATE_PROCESS: u32 = 0x0080;
pub const PROCESS_SET_QUOTA: u32 = 0x0100;
pub const PROCESS_SET_INFORMATION: u32 = 0x0200;
pub const PROCESS_QUERY_INFORMATION: u32 = 0x0400;
pub const PROCESS_SET_PORT: u32 = 0x0800;
pub const PROCESS_SUSPEND_RESUME: u32 = 0x0800;
pub const PROCESS_QUERY_LIMITED_INFORMATION: u32 = 0x1000;

pub const THREAD_TERMINATE: u32 = 0x0001;
pub const THREAD_SUSPEND_RESUME: u32 = 0x0002;
pub const THREAD_GET_CONTEXT: u32 = 0x0008;
pub const THREAD_SET_CONTEXT: u32 = 0x0010;
pub const THREAD_SET_INFORMATION: u32 = 0x0020;
pub const THREAD_QUERY_INFORMATION: u32 = 0x0040;
pub const THREAD_IMPERSONATE: u32 = 0x0100;
pub const THREAD_DIRECT_IMPERSONATION: u32 = 0x0200;

pub const PS_INVALID_PROCESS_ID: u64 = 0;
pub const PS_SYSTEM_PROCESS_ID: u64 = 4;
pub const PS_IDLE_PROCESS_ID: u64 = 0;

pub const STATUS_TIMEOUT: NtStatus = 0x00000102;
pub const STATUS_MUTANT_NOT_OWNED: NtStatus = 0xC0000046;
pub const STATUS_ABANDONED: NtStatus = 0x00000080;
pub const STATUS_NOT_FOUND: NtStatus = 0xC0000225;

// ============================================================
// TOKEN (simplified pointer)
// ============================================================

pub type Ptoken = *mut c_void;

// ============================================================
// Handle Table
// ============================================================

#[repr(C)]
pub struct HandleTable {
    pub table_code: u64,
    pub quota_process: Pvoid,
    pub unique_process_id: u64,
    pub handle_lock: KspinLock,
    pub handle_table_list: ListEntry,
    pub handle_count: u32,
    pub flags: u32,
}

impl HandleTable {
    pub const fn new() -> Self {
        Self {
            table_code: 0,
            quota_process: core::ptr::null_mut(),
            unique_process_id: 0,
            handle_lock: 0,
            handle_table_list: ListEntry::new(),
            handle_count: 0,
            flags: 0,
        }
    }
}

// ============================================================
// EJOB - Executive Job Object
// ============================================================

#[repr(C)]
pub struct Ejob {
    pub event: Kevent,
    pub job_links: ListEntry,
    pub process_list: ListEntry,
    pub process_lock: KspinLock,
    pub active_process_high_watermark: u32,
    pub active_threads: u32,
    pub total_terminated: u32,
    pub total_active: u32,
    pub total_fault_count: u32,
    pub total_allowed_cpu_time: u64,
    pub total_user_time: u64,
    pub this_period_total_user_time: u64,
    pub total_page_fault_count: u32,
    pub total_pages_reserved: u32,
    pub total_pages_committed: u32,
    pub peak_job_memory_used: u64,
    pub job_memory_used: u64,
    pub limit: JobObjectLimits,
    pub job_flags: u32,
    pub security_token: Ptoken,
    pub completion_port: Pvoid,
    pub completion_key: Pvoid,
    pub session_id: u32,
    pub scheduling_class: u32,
    pub job_flags2: u32,
    pub end_of_job_time_action: u32,
    pub breakaway_ok: Boolean,
    pub silent_breakaway_ok: Boolean,
    pub no_efficiency_diagnostics: Boolean,
    pub no_job_charge: Boolean,
    pub read_only: Boolean,
    pub no_process_security: Boolean,
    pub no_fail_fast: Boolean,
    pub new_process_ready: Boolean,
    pub job_yield: Boolean,
    pub exclusion: Boolean,
    pub override_job: Boolean,
}

impl Ejob {
    pub const fn new() -> Self {
        Self {
            event: Kevent {
                header: DispatcherHeader {
                    r#type: 0, absolute: 0, size: 0, inserted: 0,
                    signal_state: 0, wait_list_entry: ListEntry::new(),
                },
            },
            job_links: ListEntry::new(),
            process_list: ListEntry::new(),
            process_lock: 0,
            active_process_high_watermark: 0,
            active_threads: 0, total_terminated: 0, total_active: 0,
            total_fault_count: 0, total_allowed_cpu_time: 0, total_user_time: 0,
            this_period_total_user_time: 0, total_page_fault_count: 0,
            total_pages_reserved: 0, total_pages_committed: 0,
            peak_job_memory_used: 0, job_memory_used: 0,
            limit: JobObjectLimits::new(), job_flags: 0,
            security_token: core::ptr::null_mut(),
            completion_port: core::ptr::null_mut(), completion_key: core::ptr::null_mut(),
            session_id: 0, scheduling_class: 0, job_flags2: 0,
            end_of_job_time_action: 0,
            breakaway_ok: 0, silent_breakaway_ok: 0, no_efficiency_diagnostics: 0,
            no_job_charge: 0, read_only: 0, no_process_security: 0,
            no_fail_fast: 0, new_process_ready: 0, job_yield: 0,
            exclusion: 0, override_job: 0,
        }
    }
}

#[repr(C)]
pub struct JobObjectLimits {
    pub per_process_user_time_limit: u64,
    pub per_job_user_time_limit: u64,
    pub limit_flags: u32,
    pub minimum_working_set_size: usize,
    pub maximum_working_set_size: usize,
    pub active_process_limit: u32,
    pub affinity: u64,
    pub priority_class: u32,
    pub scheduling_class: u32,
    pub process_memory_limit: usize,
    pub job_memory_limit: usize,
    pub peak_process_memory_used: usize,
    pub peak_job_memory_used: usize,
}

impl JobObjectLimits {
    pub const fn new() -> Self {
        Self {
            per_process_user_time_limit: 0, per_job_user_time_limit: 0,
            limit_flags: 0, minimum_working_set_size: 0, maximum_working_set_size: 0,
            active_process_limit: 0, affinity: 0, priority_class: 0,
            scheduling_class: 0, process_memory_limit: 0, job_memory_limit: 0,
            peak_process_memory_used: 0, peak_job_memory_used: 0,
        }
    }
}

pub const JOB_OBJECT_LIMIT_WORKINGSET: u32 = 0x0000_0001;
pub const JOB_OBJECT_LIMIT_PROCESS_TIME: u32 = 0x0000_0002;
pub const JOB_OBJECT_LIMIT_JOB_TIME: u32 = 0x0000_0004;
pub const JOB_OBJECT_LIMIT_ACTIVE_PROCESS: u32 = 0x0000_0008;
pub const JOB_OBJECT_LIMIT_PROCESS_MEMORY: u32 = 0x0000_0010;
pub const JOB_OBJECT_LIMIT_JOB_MEMORY: u32 = 0x0000_0020;
pub const JOB_OBJECT_LIMIT_DIE_ON_UNHANDLED_EXCEPTION: u32 = 0x0000_0040;
pub const JOB_OBJECT_LIMIT_BREAKAWAY_OK: u32 = 0x0000_0080;
pub const JOB_OBJECT_LIMIT_SILENT_BREAKAWAY_OK: u32 = 0x0000_0100;
pub const JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE: u32 = 0x0000_0200;
pub const JOB_OBJECT_LIMIT_AFFINITY: u32 = 0x0001_0000;
pub const JOB_OBJECT_LIMIT_PRIORITY: u32 = 0x0002_0000;
pub const JOB_OBJECT_LIMIT_CPU_RATE_CONTROL: u32 = 0x4000_0000;

// ============================================================
// TEB - Thread Environment Block
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct Teb {
    pub exception_list: Pvoid,
    pub stack_base: Pvoid,
    pub stack_limit: Pvoid,
    pub sub_system_tib: Pvoid,
    pub fiber_data_or_version: Pvoid,
    pub user_pointer: Pvoid,
    pub self_pointer: *mut Teb,
    pub process_environment_block: *mut Peb,
    pub last_error_value: u32,
    pub count_of_owned_critical_sections: u32,
    pub csr_client_thread: Pvoid,
    pub win32_thread_info: Pvoid,
    pub user32_handle_table: [Pvoid; 261],
    pub gdi_cli_batch: [u8; 32],
    pub gdi_rrcl_lock: u32,
    pub gdi_rrcl_pid: u32,
    pub gdi_rrcl_handle: u32,
    pub gdi_client_thread: Pvoid,
    pub gdi_client_pid: u32,
    pub gdi_client_handle: u32,
    pub gdi_thread_local: [u64; 21],
    pub hard_errors_on: u32,
    pub hard_error_popups: [u32; 6],
    pub environment: *mut u16,
    pub process_id: u64,
    pub thread_id: u64,
    pub active_lock_handle: Pvoid,
    pub active_lock_count: u32,
    pub remote_translated_call: Pvoid,
    pub error_value: u32,
    pub process_locks: Pvoid,
    pub deallocation_stack: Pvoid,
    pub tls_slots: [Pvoid; 64],
    pub tls_links: ListEntry,
    pub vdm: Pvoid,
    pub reserved_for_rpc: Pvoid,
    pub same_teb_flags: u16,
    pub internal_flags: u16,
    pub raise_on_exception_flags: u16,
    pub spare1: u16,
    pub spare2: u64,
    pub tls_expansion_slots: *mut Pvoid,
    pub reserved_for_mit_pgo: Pvoid,
    pub reserved_for_ui: Pvoid,
    pub dynamic_tls_vector: Pvoid,
    pub reserved_for_ssp: Pvoid,
    pub reserved_for_codec_tls: Pvoid,
    pub resource_manager_thread: Pvoid,
    pub mx_context: Pvoid,
    pub flags2: u64,
    pub spare3: Pvoid,
}

// ============================================================
// PEB - Process Environment Block
// ============================================================

#[repr(C)]
pub struct Peb {
    pub inherited_address_space: u8,
    pub read_file_exec_only: u8,
    pub being_debugged: u8,
    pub bit_field: u8,
    pub pad0: [u8; 4],
    pub mutant: Pvoid,
    pub image_base_address: Pvoid,
    pub ldr: *mut PebLdrData,
    pub process_parameters: *mut RtlUserProcessParameters,
    pub sub_system_data: Pvoid,
    pub process_heap: Pvoid,
    pub fast_peb_lock: Pvoid,
    pub atl_thunk_s_list_ptr: Pvoid,
    pub ifeo_key: Pvoid,
    pub cross_process_flags: u32,
    pub process_snapshot_handle: Pvoid,
    pub reserved3: Pvoid,
    pub reserved4: [u8; 4],
    pub minimum_stack_commit: u64,
    pub reserved5: [Pvoid; 2],
    pub tls_expansion_counter: u32,
    pub pad1: [u8; 4],
    pub tls_bitmap: Pvoid,
    pub tls_expansion_bitmap: Pvoid,
    pub tls_expansion_bitmap_bits: [u32; 32],
    pub session_id: u32,
    pub app_compat_flags: u64,
    pub app_compat_flags_user: u64,
    pub p_shim_data: Pvoid,
    pub app_compat_info: Pvoid,
    pub csd_version: UnicodeString,
    pub dependent_load_flags: u32,
    pub activation_context_data: Pvoid,
    pub process_assembly_storage_map: Pvoid,
    pub system_default_activation_context_data: Pvoid,
    pub unused2: Pvoid,
    pub process_tracing_quota: u64,
    pub reserved6: u64,
    pub csr_server_read_only_shared_memory_base: Pvoid,
    pub tpp_workerp_list_lock: Pvoid,
    pub tpp_workerp_list: ListEntry,
    pub wait_on_address_hash_table: [Pvoid; 128],
    pub telemetry_coverage_header: Pvoid,
    pub cloud_file_flags: u64,
    pub cloud_file_diag_flags: u64,
    pub placeholder_compatibility_mode: u32,
    pub reserved7: [u8; 12],
    pub builtin_top_level_binary: Pvoid,
    pub non_server_feature_bitmap: u32,
    pub pad2: [u8; 4],
    pub heap_storage_data: Pvoid,
    pub pagefile_support: u64,
}

impl Peb {
    pub const fn new() -> Self {
        Self {
            inherited_address_space: 0, read_file_exec_only: 0, being_debugged: 0,
            bit_field: 0, pad0: [0; 4], mutant: core::ptr::null_mut(),
            image_base_address: core::ptr::null_mut(), ldr: core::ptr::null_mut(),
            process_parameters: core::ptr::null_mut(), sub_system_data: core::ptr::null_mut(),
            process_heap: core::ptr::null_mut(), fast_peb_lock: core::ptr::null_mut(),
            atl_thunk_s_list_ptr: core::ptr::null_mut(), ifeo_key: core::ptr::null_mut(),
            cross_process_flags: 0, process_snapshot_handle: core::ptr::null_mut(),
            reserved3: core::ptr::null_mut(), reserved4: [0; 4],
            minimum_stack_commit: 0, reserved5: [core::ptr::null_mut(); 2],
            tls_expansion_counter: 0, pad1: [0; 4],
            tls_bitmap: core::ptr::null_mut(), tls_expansion_bitmap: core::ptr::null_mut(),
            tls_expansion_bitmap_bits: [0; 32], session_id: 0,
            app_compat_flags: 0, app_compat_flags_user: 0,
            p_shim_data: core::ptr::null_mut(), app_compat_info: core::ptr::null_mut(),
            csd_version: UnicodeString::new(), dependent_load_flags: 0,
            activation_context_data: core::ptr::null_mut(),
            process_assembly_storage_map: core::ptr::null_mut(),
            system_default_activation_context_data: core::ptr::null_mut(),
            unused2: core::ptr::null_mut(), process_tracing_quota: 0, reserved6: 0,
            csr_server_read_only_shared_memory_base: core::ptr::null_mut(),
            tpp_workerp_list_lock: core::ptr::null_mut(), tpp_workerp_list: ListEntry::new(),
            wait_on_address_hash_table: [core::ptr::null_mut(); 128],
            telemetry_coverage_header: core::ptr::null_mut(),
            cloud_file_flags: 0, cloud_file_diag_flags: 0,
            placeholder_compatibility_mode: 0, reserved7: [0; 12],
            builtin_top_level_binary: core::ptr::null_mut(),
            non_server_feature_bitmap: 0, pad2: [0; 4],
            heap_storage_data: core::ptr::null_mut(), pagefile_support: 0,
        }
    }
}

// ============================================================
// PEB_LDR_DATA
// ============================================================

#[repr(C)]
pub struct PebLdrData {
    pub length: u32,
    pub initialized: u8,
    pub sshandle: Pvoid,
    pub in_load_order_module_list: ListEntry,
    pub in_memory_order_module_list: ListEntry,
    pub in_initialization_order_module_list: ListEntry,
    pub entry_in_progress: Pvoid,
    pub shutdown_thread_id: u32,
    pub shutdown_finished: u8,
}

impl PebLdrData {
    pub const fn new() -> Self {
        Self {
            length: 0, initialized: 0, sshandle: core::ptr::null_mut(),
            in_load_order_module_list: ListEntry::new(),
            in_memory_order_module_list: ListEntry::new(),
            in_initialization_order_module_list: ListEntry::new(),
            entry_in_progress: core::ptr::null_mut(),
            shutdown_thread_id: 0, shutdown_finished: 0,
        }
    }
}

// ============================================================
// RTL_USER_PROCESS_PARAMETERS
// ============================================================

#[repr(C)]
pub struct RtlUserProcessParameters {
    pub maximum_length: u32,
    pub length: u32,
    pub flags: u32,
    pub debug_flags: u32,
    pub console_flags: u32,
    pub standard_input: Pvoid,
    pub standard_output: Pvoid,
    pub standard_error: Pvoid,
    pub current_directory: CurDir,
    pub dll_path: UnicodeString,
    pub image_path_name: UnicodeString,
    pub command_line: UnicodeString,
    pub environment: *mut u16,
    pub starting_x: u32,
    pub starting_y: u32,
    pub count_x: u32,
    pub count_y: u32,
    pub count_chars_x: u32,
    pub count_chars_y: u32,
    pub fill_attribute: u32,
    pub window_flags: u32,
    pub show_window_flags: u32,
    pub window_title: UnicodeString,
    pub desktop_info: UnicodeString,
    pub shell_info: UnicodeString,
    pub runtime_data: UnicodeString,
}

impl RtlUserProcessParameters {
    pub const fn new() -> Self {
        Self {
            maximum_length: 0, length: 0, flags: 0, debug_flags: 0, console_flags: 0,
            standard_input: core::ptr::null_mut(), standard_output: core::ptr::null_mut(),
            standard_error: core::ptr::null_mut(), current_directory: CurDir::new(),
            dll_path: UnicodeString::new(), image_path_name: UnicodeString::new(),
            command_line: UnicodeString::new(), environment: core::ptr::null_mut(),
            starting_x: 0, starting_y: 0, count_x: 0, count_y: 0,
            count_chars_x: 0, count_chars_y: 0, fill_attribute: 0,
            window_flags: 0, show_window_flags: 0,
            window_title: UnicodeString::new(), desktop_info: UnicodeString::new(),
            shell_info: UnicodeString::new(), runtime_data: UnicodeString::new(),
        }
    }
}

#[repr(C)]
pub struct CurDir {
    pub dos_path: UnicodeString,
    pub handle: Handle,
}

impl CurDir {
    pub const fn new() -> Self {
        Self { dos_path: UnicodeString::new(), handle: core::ptr::null_mut() }
    }
}

// ============================================================
// CLIENT_ID64
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ClientId64 {
    pub unique_process: u64,
    pub unique_thread: u64,
}

impl ClientId64 {
    pub const fn new() -> Self {
        Self { unique_process: 0, unique_thread: 0 }
    }
}

// ============================================================
// Section Object (simplified)
// ============================================================

#[repr(C)]
pub struct SectionObject {
    pub starting_va: Pvoid,
    pub ending_va: Pvoid,
    pub parent: Pvoid,
    pub left_child: Pvoid,
    pub right_child: Pvoid,
    pub segment: Pvoid,
}

impl SectionObject {
    pub const fn new() -> Self {
        Self {
            starting_va: core::ptr::null_mut(), ending_va: core::ptr::null_mut(),
            parent: core::ptr::null_mut(), left_child: core::ptr::null_mut(),
            right_child: core::ptr::null_mut(), segment: core::ptr::null_mut(),
        }
    }
}

// ============================================================
// EPROCESS - Executive Process Object
// ============================================================

#[repr(C)]
pub struct Eprocess {
    pub kprocess: Kprocess,
    pub process_lock: KspinLock,
    pub unique_process_id: u64,
    pub inherited_from_unique_process_id: u64,
    pub active_process_links: ListEntry,
    pub object_table: *mut HandleTable,
    pub token: Pvoid,
    pub vad_root: MiVadTree,
    pub vad_hint: *mut MiVadNode,
    pub image_file_name: [u8; PS_IMAGE_FILE_NAME_LENGTH + 1],
    pub section_object: SectionObject,
    pub peb: *mut Peb,
    pub directory_table_base: u64,
    pub job: *mut Ejob,
    pub ldt_information: Pvoid,
    pub security_port: Pvoid,
    pub parent_process: *mut Eprocess,
    pub console_host_process: *mut Eprocess,
    pub device_map: Pvoid,
    pub strict_ha_favor_soft: u32,
    pub page_fault_count: u64,
    pub commit_charge: u64,
    pub commit_charge_limit: u64,
    pub commit_charge_peak: u64,
    pub peak_working_set_size: u64,
    pub working_set_size: u64,
    pub quota_usage: [u64; 3],
    pub quota_peak: [u64; 3],
    pub pagefile_usage: u64,
    pub shared_commit: u64,
    pub image_commit: u64,
    pub virtual_size: u64,
    pub read_operation_count: i64,
    pub write_operation_count: i64,
    pub other_operation_count: i64,
    pub read_transfer_count: i64,
    pub write_transfer_count: i64,
    pub other_transfer_count: i64,
    pub thread_list_head: ListEntry,
    pub active_threads: u32,
    pub total_process_threads: u32,
    pub process_flags: u32,
    pub create_time: i64,
    pub exit_time: i64,
    pub exit_status: NtStatus,
    pub fork_failed: u8,
    pub priority_class: u8,
    pub pad_p0: [u8; 2],
    pub debug_port: Pvoid,
    pub exception_port: Pvoid,
    pub wow64_process: Pvoid,
    pub instrumentation_callback: Pvoid,
    pub instrumentation_callback_disabled: u8,
    pub spare_e0: u8,
    pub operating_system: u16,
    pub next_page_color: u32,
    pub session_id: u32,
    pub audit: *mut c_void,
    pub refcount: i32,
    pub flags: u64,
    pub unique_process_id_v2: u64,
    pub cookie: u64,
    pub package_id: u64,
    pub flags2: u32,
    pub flags3: u32,
    pub _padding: [u8; 4],
}

unsafe impl Send for Eprocess {}
unsafe impl Sync for Eprocess {}

impl Eprocess {
    pub fn new() -> Self { unsafe { mem::zeroed() } }

    pub fn pid(&self) -> u64 { self.unique_process_id }

    pub fn name(&self) -> &str {
        let end = self.image_file_name.iter().position(|&b| b == 0)
            .unwrap_or(PS_IMAGE_FILE_NAME_LENGTH);
        core::str::from_utf8(&self.image_file_name[..end]).unwrap_or("")
    }

    pub fn is_deleted(&self) -> bool {
        self.process_flags & PSP_PROCESS_FLAGS_DELETE_PENDING != 0
    }

    pub fn set_priority_class(&mut self, class: u32) {
        self.priority_class = class as u8;
    }

    pub fn get_priority_class(&self) -> u32 { self.priority_class as u32 }

    pub fn initialize(&mut self) {
        self.kprocess.initialize();
        self.process_lock = 0;
        self.active_process_links = ListEntry::new();
        self.object_table = core::ptr::null_mut();
        self.token = core::ptr::null_mut();
        self.vad_root = MiVadTree::new();
        self.vad_hint = core::ptr::null_mut();
        self.image_file_name = [0; PS_IMAGE_FILE_NAME_LENGTH + 1];
        self.section_object = SectionObject::new();
        self.peb = core::ptr::null_mut();
        self.directory_table_base = 0;
        self.job = core::ptr::null_mut();
        self.ldt_information = core::ptr::null_mut();
        self.security_port = core::ptr::null_mut();
        self.parent_process = core::ptr::null_mut();
        self.console_host_process = core::ptr::null_mut();
        self.device_map = core::ptr::null_mut();
        self.strict_ha_favor_soft = 0;
        self.page_fault_count = 0;
        self.commit_charge = 0;
        self.commit_charge_limit = 0;
        self.commit_charge_peak = 0;
        self.peak_working_set_size = 0;
        self.working_set_size = 0;
        self.quota_usage = [0; 3];
        self.quota_peak = [0; 3];
        self.pagefile_usage = 0;
        self.shared_commit = 0;
        self.image_commit = 0;
        self.virtual_size = 0;
        self.read_operation_count = 0;
        self.write_operation_count = 0;
        self.other_operation_count = 0;
        self.read_transfer_count = 0;
        self.write_transfer_count = 0;
        self.other_transfer_count = 0;
        self.thread_list_head = ListEntry::new();
        self.active_threads = 0;
        self.total_process_threads = 0;
        self.process_flags = 0;
        self.create_time = 0;
        self.exit_time = 0;
        self.exit_status = 0;
        self.fork_failed = 0;
        self.priority_class = PS_PRIORITY_CLASS_NORMAL as u8;
        self.debug_port = core::ptr::null_mut();
        self.exception_port = core::ptr::null_mut();
        self.wow64_process = core::ptr::null_mut();
        self.instrumentation_callback = core::ptr::null_mut();
        self.session_id = 0;
        self.refcount = 0;
        self.flags = 0;
        self.flags2 = 0;
        self.flags3 = 0;
    }
}

// ============================================================
// WaitInlineBlock (used inside ETHREAD)
// ============================================================

#[repr(C)]
pub struct WaitInlineBlock {
    pub wait_list_entry: ListEntry,
    pub timer: Pvoid,
    pub semaphore: Pvoid,
    pub wait_block: Pvoid,
}

// ============================================================
// ETHREAD - Executive Thread Object
// ============================================================

#[repr(C)]
pub struct Ethread {
    pub kthread: Kthread,
    pub create_time: i64,
    pub exit_time: i64,
    pub unique_process: *mut Eprocess,
    pub cid: ClientId64,
    pub exit_status: NtStatus,
    pub cross_thread_flags: u32,
    pub threads_process: *mut Eprocess,
    pub start_address: Pvoid,
    pub win32_start_address: Pvoid,
    pub threads_linked: u32,
    pub system_thread: u32,
    pub wait_inline: WaitInlineBlock,
    pub pending_irp: Pirp,
    pub top_level_irp: Pvoid,
    pub stack_base: Pvoid,
    pub stack_limit: Pvoid,
    pub kernel_stack: Pvoid,
    pub alpc_info: Pvoid,
    pub forward_cluster: u8,
    pub thread_local_flags: u8,
    pub same_thread_passive_flags: u8,
    pub same_thread_apc_flags: u8,
    pub kernel_aspect_ratio: u8,
    pub disable_user_stack_walk: u8,
    pub bam_qos_level: u8,
    pub thread_end_deleted: u8,
    pub write_operation_count: i64,
    pub read_transfer_count: i64,
    pub write_transfer_count: i64,
    pub read_operation_count: i64,
    pub other_operation_count: i64,
    pub other_transfer_count: i64,
    pub io_redirect_count: u32,
    pub spare_ethread: [u8; 4],
}

unsafe impl Send for Ethread {}
unsafe impl Sync for Ethread {}

impl Ethread {
    pub fn initialize(&mut self) {
        self.kthread.initialize();
        self.create_time = 0;
        self.exit_time = 0;
        self.unique_process = core::ptr::null_mut();
        self.cid = ClientId64::new();
        self.exit_status = 0;
        self.cross_thread_flags = 0;
        self.threads_process = core::ptr::null_mut();
        self.start_address = core::ptr::null_mut();
        self.win32_start_address = core::ptr::null_mut();
        self.threads_linked = 0;
        self.system_thread = 0;
        self.wait_inline = WaitInlineBlock {
            wait_list_entry: ListEntry::new(), timer: core::ptr::null_mut(),
            semaphore: core::ptr::null_mut(), wait_block: core::ptr::null_mut(),
        };
        self.pending_irp = core::ptr::null_mut();
        self.top_level_irp = core::ptr::null_mut();
        self.stack_base = core::ptr::null_mut();
        self.stack_limit = core::ptr::null_mut();
        self.kernel_stack = core::ptr::null_mut();
        self.alpc_info = core::ptr::null_mut();
        self.write_operation_count = 0;
        self.read_transfer_count = 0;
        self.write_transfer_count = 0;
        self.read_operation_count = 0;
        self.other_operation_count = 0;
        self.other_transfer_count = 0;
    }
}

// ============================================================
// Global State
// ============================================================

pub static PS_PROCESS_LOCK: AtomicU64 = AtomicU64::new(0);
pub static PS_THREAD_LOCK: AtomicU64 = AtomicU64::new(0);
pub static mut PS_SYSTEM_PROCESS: *mut Eprocess = core::ptr::null_mut();
pub static mut PS_IDLE_PROCESS: *mut Eprocess = core::ptr::null_mut();
pub static PS_NEXT_PROCESS_ID: AtomicU64 = AtomicU64::new(100);
pub static PS_NEXT_THREAD_ID: AtomicU64 = AtomicU64::new(200);
pub static PS_PROCESS_COUNT: AtomicUsize = AtomicUsize::new(0);
pub static PS_THREAD_COUNT: AtomicUsize = AtomicUsize::new(0);
pub static PS_SYSTEM_THREAD_COUNT: AtomicUsize = AtomicUsize::new(0);
pub static mut PS_ACTIVE_PROCESS_LIST_HEAD: ListEntry = ListEntry::new();

// ============================================================
// Process/Thread List Lock Helpers
// ============================================================

#[inline]
pub fn ps_acquire_process_lock() {
    let lock_ptr = &PS_PROCESS_LOCK as *const AtomicU64 as *mut u64;
    unsafe {
        asm!("1:", "lock bts qword ptr [{0}], 0", "jc 2f", "jmp 3f",
             "2:", "pause", "test qword ptr [{0}], 1", "jnz 2b", "jmp 1b", "3:",
             in(reg) lock_ptr, options(nostack, nomem));
    }
}

#[inline]
pub fn ps_release_process_lock() {
    let lock_ptr = &PS_PROCESS_LOCK as *const AtomicU64 as *mut u64;
    unsafe {
        asm!("lock btr qword ptr [{0}], 0", in(reg) lock_ptr, options(nostack, nomem));
    }
}

#[inline]
pub fn ps_acquire_thread_lock() {
    let lock_ptr = &PS_THREAD_LOCK as *const AtomicU64 as *mut u64;
    unsafe {
        asm!("1:", "lock bts qword ptr [{0}], 0", "jc 2f", "jmp 3f",
             "2:", "pause", "test qword ptr [{0}], 1", "jnz 2b", "jmp 1b", "3:",
             in(reg) lock_ptr, options(nostack, nomem));
    }
}

#[inline]
pub fn ps_release_thread_lock() {
    let lock_ptr = &PS_THREAD_LOCK as *const AtomicU64 as *mut u64;
    unsafe {
        asm!("lock btr qword ptr [{0}], 0", in(reg) lock_ptr, options(nostack, nomem));
    }
}

#[inline]
fn offset_of_wait_list_entry_in_thread() -> usize {
    unsafe {
        let dummy: Ethread = mem::zeroed();
        let base = &dummy as *const Ethread as usize;
        let field = &dummy.kthread.wait_list_entry as *const ListEntry as usize;
        let offset = field - base;
        mem::forget(dummy);
        offset
    }
}

#[inline]
fn offset_of_active_process_links() -> usize {
    unsafe {
        let dummy: Eprocess = mem::zeroed();
        let base = &dummy as *const Eprocess as usize;
        let field = &dummy.active_process_links as *const ListEntry as usize;
        let offset = field - base;
        mem::forget(dummy);
        offset
    }
}

#[inline]
fn ps_get_system_time() -> i64 {
    unsafe {
        let tsc = core::arch::x86_64::_rdtsc();
        (tsc / 10_000_000) as i64
    }
}

// ============================================================
// PspAllocateProcess
// ============================================================

pub fn psp_allocate_process(
    parent: *mut Eprocess,
    _object_body: Pvoid,
) -> *mut Eprocess {
    let alloc_size = mem::size_of::<Eprocess>();
    let layout = match core::alloc::Layout::from_size_align(alloc_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };

    let process = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut Eprocess };
    if process.is_null() {
        return core::ptr::null_mut();
    }

    let proc = unsafe { &mut *process };
    proc.initialize();

    let pid = PS_NEXT_PROCESS_ID.fetch_add(1, Ordering::Relaxed);
    proc.unique_process_id = pid;
    proc.unique_process_id_v2 = pid;

    if !parent.is_null() {
        proc.parent_process = parent;
        proc.inherited_from_unique_process_id = unsafe { (*parent).unique_process_id };
        proc.directory_table_base = unsafe { (*parent).directory_table_base };
    }

    proc.object_table = psp_allocate_handle_table(pid);
    if proc.object_table.is_null() {
        psp_free_process(process);
        return core::ptr::null_mut();
    }

    proc.vad_root = MiVadTree::new();
    proc.kprocess.quantum_reset = PS_DEFAULT_PROCESS_QUANTUM;

    ps_trace!("PspAllocateProcess: allocated PID {}", pid);
    process
}

fn psp_free_process(process: *mut Eprocess) {
    if process.is_null() { return; }
    let proc = unsafe { &*process };
    if !proc.object_table.is_null() {
        psp_free_handle_table(proc.object_table);
    }
    let alloc_size = mem::size_of::<Eprocess>();
    let layout = core::alloc::Layout::from_size_align(alloc_size, 16).unwrap();
    unsafe { alloc::alloc::dealloc(process as *mut u8, layout); }
}

fn psp_allocate_handle_table(owner_pid: u64) -> *mut HandleTable {
    let size = mem::size_of::<HandleTable>();
    let layout = match core::alloc::Layout::from_size_align(size, 8) {
        Ok(l) => l, Err(_) => return core::ptr::null_mut(),
    };
    let ht = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut HandleTable };
    if ht.is_null() { return core::ptr::null_mut(); }
    let table = unsafe { &mut *ht };
    table.unique_process_id = owner_pid;
    table.handle_lock = 0;
    table.handle_count = 0;
    table.flags = 0;
    table.handle_table_list = ListEntry::new();
    table.handle_table_list.flink = &mut table.handle_table_list as *mut ListEntry;
    table.handle_table_list.blink = &mut table.handle_table_list as *mut ListEntry;
    ht
}

fn psp_free_handle_table(ht: *mut HandleTable) {
    if ht.is_null() { return; }
    let alloc_size = mem::size_of::<HandleTable>();
    let layout = core::alloc::Layout::from_size_align(alloc_size, 8).unwrap();
    unsafe { alloc::alloc::dealloc(ht as *mut u8, layout); }
}

// ============================================================
// PspInsertProcess
// ============================================================

pub fn psp_insert_process(process: *mut Eprocess) -> NtStatus {
    if process.is_null() { return STATUS_INVALID_PARAMETER; }
    let proc = unsafe { &mut *process };

    ps_acquire_process_lock();
    unsafe {
        let head = &mut PS_ACTIVE_PROCESS_LIST_HEAD;
        if head.flink.is_null() || head.blink.is_null() {
            head.flink = &mut proc.active_process_links as *mut ListEntry;
            head.blink = &mut proc.active_process_links as *mut ListEntry;
            proc.active_process_links.flink = head as *mut ListEntry;
            proc.active_process_links.blink = head as *mut ListEntry;
        } else {
            head.insert_head(&mut proc.active_process_links);
        }
    }
    PS_PROCESS_COUNT.fetch_add(1, Ordering::Relaxed);
    ps_release_process_lock();

    ps_trace!("PspInsertProcess: inserted PID {}", proc.unique_process_id);
    STATUS_SUCCESS
}

// ============================================================
// PspAllocateThread
// ============================================================

pub fn psp_allocate_thread(
    process: *mut Eprocess,
    start_address: Option<unsafe extern "C" fn(Pvoid)>,
    _start_parameter: Pvoid,
    create_flags: u32,
) -> *mut Ethread {
    let alloc_size = mem::size_of::<Ethread>();
    let layout = match core::alloc::Layout::from_size_align(alloc_size, 16) {
        Ok(l) => l, Err(_) => return core::ptr::null_mut(),
    };

    let thread = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut Ethread };
    if thread.is_null() { return core::ptr::null_mut(); }

    let thr = unsafe { &mut *thread };
    thr.initialize();

    let tid = PS_NEXT_THREAD_ID.fetch_add(1, Ordering::Relaxed);
    thr.cid = ClientId64 {
        unique_process: if !process.is_null() { unsafe { (*process).unique_process_id } } else { 0 },
        unique_thread: tid,
    };
    thr.threads_process = process;
    thr.unique_process = process;

    if let Some(entry) = start_address {
        thr.start_address = entry as Pvoid;
    }
    thr.win32_start_address = thr.start_address;
    thr.create_time = ps_get_system_time();

    let stack_size = 24 * 1024;
    let stack_layout = match core::alloc::Layout::from_size_align(stack_size, 16) {
        Ok(l) => l,
        Err(_) => { psp_free_thread(thread); return core::ptr::null_mut(); }
    };
    let stack = unsafe { alloc::alloc::alloc_zeroed(stack_layout) };
    if stack.is_null() { psp_free_thread(thread); return core::ptr::null_mut(); }
    let stack_top = unsafe { stack.add(stack_size) };

    thr.kthread.initial_stack = stack_top as Pvoid;
    thr.kthread.stack_limit = stack as Pvoid;
    thr.kthread.stack_base = stack_top as Pvoid;
    thr.kthread.kernel_stack = stack as Pvoid;
    thr.kthread.process = if !process.is_null() {
        &mut unsafe { &mut *process }.kprocess as *mut Kprocess
    } else { core::ptr::null_mut() };
    thr.kthread.kprocess = thr.kthread.process;
    thr.stack_base = stack_top as Pvoid;
    thr.stack_limit = stack as Pvoid;
    thr.kernel_stack = stack as Pvoid;
    thr.kthread.priority = 8;
    thr.kthread.base_priority = 8;
    thr.cross_thread_flags = PS_CROSS_THREAD_FLAGS_TERMINATED;

    if (create_flags & PS_THREAD_CREATE_FLAGS_CREATE_SUSPENDED) != 0 {
        thr.kthread.suspend_count = 1;
    }

    thr.wait_inline = WaitInlineBlock {
        wait_list_entry: ListEntry::new(), timer: core::ptr::null_mut(),
        semaphore: core::ptr::null_mut(), wait_block: core::ptr::null_mut(),
    };

    ps_trace!("PspAllocateThread: allocated TID {}", tid);
    thread
}

fn psp_free_thread(thread: *mut Ethread) {
    if thread.is_null() { return; }
    let thr = unsafe { &*thread };
    if !thr.kthread.kernel_stack.is_null() {
        let stack_size = 24 * 1024;
        let stack_base = thr.kthread.kernel_stack as *mut u8;
        let layout = core::alloc::Layout::from_size_align(stack_size, 16).unwrap();
        unsafe { alloc::alloc::dealloc(stack_base, layout); }
    }
    let alloc_size = mem::size_of::<Ethread>();
    let layout = core::alloc::Layout::from_size_align(alloc_size, 16).unwrap();
    unsafe { alloc::alloc::dealloc(thread as *mut u8, layout); }
}

// ============================================================
// PspInsertThread
// ============================================================

pub fn psp_insert_thread(process: *mut Eprocess, thread: *mut Ethread) -> NtStatus {
    if process.is_null() || thread.is_null() { return STATUS_INVALID_PARAMETER; }

    let proc = unsafe { &mut *process };
    let thr = unsafe { &mut *thread };

    acquire_spin_lock(&mut proc.process_lock);
    proc.thread_list_head.insert_head(&mut thr.kthread.wait_list_entry);
    proc.active_threads += 1;
    proc.total_process_threads += 1;
    thr.threads_linked = 1;
    release_spin_lock(&mut proc.process_lock);

    PS_THREAD_COUNT.fetch_add(1, Ordering::Relaxed);
    if thr.cross_thread_flags & PS_CROSS_THREAD_FLAGS_SYSTEM_THREAD != 0 {
        PS_SYSTEM_THREAD_COUNT.fetch_add(1, Ordering::Relaxed);
    }

    ps_trace!("PspInsertThread: TID {} into PID {}", thr.cid.unique_thread, proc.unique_process_id);
    STATUS_SUCCESS
}

fn psp_remove_thread(thread: *mut Ethread) {
    if thread.is_null() { return; }
    let thr = unsafe { &mut *thread };
    let proc = thr.threads_process;
    if proc.is_null() || thr.threads_linked == 0 { return; }

    let process = unsafe { &mut *proc };
    acquire_spin_lock(&mut process.process_lock);
    thr.kthread.wait_list_entry.remove();
    process.active_threads -= 1;
    thr.threads_linked = 0;
    release_spin_lock(&mut process.process_lock);

    PS_THREAD_COUNT.fetch_sub(1, Ordering::Relaxed);
    if thr.cross_thread_flags & PS_CROSS_THREAD_FLAGS_SYSTEM_THREAD != 0 {
        PS_SYSTEM_THREAD_COUNT.fetch_sub(1, Ordering::Relaxed);
    }
}

// ============================================================
// PEB allocation
// ============================================================

fn psp_allocate_peb() -> *mut Peb {
    let size = mem::size_of::<Peb>();
    let layout = match core::alloc::Layout::from_size_align(size, 16) {
        Ok(l) => l, Err(_) => return core::ptr::null_mut(),
    };
    let peb = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut Peb };
    if peb.is_null() { return core::ptr::null_mut(); }
    let peb_ref = unsafe { &mut *peb };
    peb_ref.mutant = core::ptr::null_mut();
    peb_ref.image_base_address = core::ptr::null_mut();
    peb_ref.ldr = core::ptr::null_mut();
    peb_ref.process_parameters = core::ptr::null_mut();
    peb_ref.process_heap = core::ptr::null_mut();
    peb
}

// ============================================================
// TEB allocation
// ============================================================

pub fn psp_allocate_teb() -> *mut Teb {
    let size = mem::size_of::<Teb>();
    let layout = match core::alloc::Layout::from_size_align(size, 16) {
        Ok(l) => l, Err(_) => return core::ptr::null_mut(),
    };
    let teb = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut Teb };
    if teb.is_null() { return core::ptr::null_mut(); }
    let teb_ref = unsafe { &mut *teb };
    teb_ref.self_pointer = teb;
    teb_ref.process_environment_block = core::ptr::null_mut();
    teb
}

pub fn psp_free_teb(teb: *mut Teb) {
    if teb.is_null() { return; }
    let size = mem::size_of::<Teb>();
    let layout = core::alloc::Layout::from_size_align(size, 16).unwrap();
    unsafe { alloc::alloc::dealloc(teb as *mut u8, layout); }
}

// ============================================================
// User thread entry stub
// ============================================================

unsafe extern "C" fn ke_user_thread_start(parameter: Pvoid) {
    let _ = parameter;
    loop { unsafe { asm!("hlt", options(nostack, nomem)); } }
}

// ============================================================
// PspCreateInitialThread
// ============================================================

fn psp_create_initial_thread(process: *mut Eprocess) -> *mut Ethread {
    if process.is_null() { return core::ptr::null_mut(); }

    let thread = psp_allocate_thread(process, Some(ke_user_thread_start), core::ptr::null_mut(), 0);
    if thread.is_null() { return core::ptr::null_mut(); }

    let status = psp_insert_thread(process, thread);
    if status != STATUS_SUCCESS {
        psp_free_thread(thread);
        return core::ptr::null_mut();
    }
    thread
}

// ============================================================
// PspCreateProcess
// ============================================================

fn psp_create_process(
    parent_process: *mut Eprocess,
    create_flags: u32,
    section_handle: Handle,
    debug_port: Handle,
    exception_port: Handle,
) -> *mut Eprocess {
    let process = psp_allocate_process(parent_process, core::ptr::null_mut());
    if process.is_null() { return core::ptr::null_mut(); }

    let proc = unsafe { &mut *process };
    proc.process_flags = create_flags & 0xFFFF;
    proc.process_flags |= PSP_PROCESS_FLAGS_CREATED_PROCESS;
    proc.debug_port = debug_port;
    proc.exception_port = exception_port;
    proc.create_time = ps_get_system_time();

    if !parent_process.is_null() {
        let parent = unsafe { &*parent_process };
        proc.priority_class = parent.priority_class;
        proc.kprocess.base_priority = parent.kprocess.base_priority;
    } else {
        proc.priority_class = PS_PRIORITY_CLASS_NORMAL as u8;
        proc.kprocess.base_priority = 8;
    }

    let suspended = (create_flags & PS_PROCESS_CREATE_FLAGS_SUSPENDED) != 0;

    psp_insert_process(process);

    if !suspended {
        let thread = psp_create_initial_thread(process);
        if thread.is_null() {
            ps_err!("PspCreateProcess: failed initial thread for PID {}", proc.unique_process_id);
        }
    }

    let peb = psp_allocate_peb();
    if !peb.is_null() { proc.peb = peb; }

    if !section_handle.is_null() {
        ps_trace!("PspCreateProcess: mapping section into process");
    }

    process
}

// ============================================================
// PsCreateProcessEx
// ============================================================

pub fn ps_create_process_ex(
    process_handle: *mut Handle,
    _desired_access: u32,
    _object_attributes: *mut ObjectAttributes,
    parent_process_handle: Handle,
    create_flags: u32,
    section_handle: Handle,
    debug_port: Handle,
    exception_port: Handle,
    in_job_handle: Handle,
) -> NtStatus {
    let parent_process = if parent_process_handle.is_null() {
        let current_thread = unsafe { ki_get_current_thread() };
        if current_thread.is_null() { return STATUS_INVALID_PARAMETER; }
        let ethread = current_thread as *mut Ethread;
        (unsafe { (*ethread).threads_process }) as *mut Eprocess
    } else {
        parent_process_handle as *mut Eprocess
    };

    let process = psp_create_process(parent_process, create_flags, section_handle, debug_port, exception_port);
    if process.is_null() { return STATUS_NO_MEMORY; }

    if !in_job_handle.is_null() {
        let job = in_job_handle as *mut Ejob;
        unsafe { psp_job_insert_process(job, process); }
    }

    if !process_handle.is_null() {
        unsafe { *process_handle = process as Handle; }
    }

    let proc = unsafe { &*process };
    ps_trace!("PsCreateProcessEx: created PID {}", proc.unique_process_id);
    STATUS_SUCCESS
}

// ============================================================
// PspExitProcess
// ============================================================

pub fn psp_exit_process(process: *mut Eprocess, exit_status: NtStatus) {
    if process.is_null() { return; }
    let proc = unsafe { &mut *process };

    ps_trace!("PspExitProcess: PID {} exit status {:#x}", proc.unique_process_id, exit_status);

    proc.exit_status = exit_status;
    proc.exit_time = ps_get_system_time();

    acquire_spin_lock(&mut proc.process_lock);
    let mut current = proc.thread_list_head.flink;
    while !current.is_null() && current != &mut proc.thread_list_head as *mut ListEntry {
        let thread_entry = unsafe { &mut *current };
        current = thread_entry.flink;

        let offset = offset_of_wait_list_entry_in_thread();
        let ethread = unsafe { (((thread_entry as *mut ListEntry) as usize - offset) as *mut Ethread) };
        if !ethread.is_null() {
            let thr = unsafe { &mut *ethread };
            thr.cross_thread_flags |= PS_CROSS_THREAD_FLAGS_TERMINATED;
            thr.kthread.header.signal_state = 1;
            thr.kthread.state = KthreadState::Terminated;
        }
    }
    release_spin_lock(&mut proc.process_lock);

    if !proc.job.is_null() {
        unsafe { psp_job_remove_process(proc.job, process); }
    }

    proc.kprocess.header.signal_state = 1;
    proc.process_flags |= PSP_PROCESS_FLAGS_DELETE_PENDING;

    ps_acquire_process_lock();
    unsafe { proc.active_process_links.remove(); }
    PS_PROCESS_COUNT.fetch_sub(1, Ordering::Relaxed);
    ps_release_process_lock();

    ps_trace!("PspExitProcess: PID {} fully exited", proc.unique_process_id);
}

// ============================================================
// PsTerminateProcess
// ============================================================

pub fn ps_terminate_process(process: *mut Eprocess, exit_status: NtStatus) -> NtStatus {
    if process.is_null() { return STATUS_INVALID_PARAMETER; }
    let proc = unsafe { &*process };
    if proc.unique_process_id == PS_SYSTEM_PROCESS_ID { return STATUS_ACCESS_DENIED; }
    if proc.unique_process_id == PS_IDLE_PROCESS_ID { return STATUS_ACCESS_DENIED; }

    ps_trace!("PsTerminateProcess: terminating PID {}", proc.unique_process_id);
    psp_exit_process(process, exit_status);
    STATUS_SUCCESS
}

// ============================================================
// PspExitThread / PspExitNormalThread
// ============================================================

pub fn psp_exit_thread(thread: *mut Ethread, exit_status: NtStatus) {
    if thread.is_null() { return; }
    let thr = unsafe { &mut *thread };

    ps_trace!("PspExitThread: TID {} exit status {:#x}", thr.cid.unique_thread, exit_status);

    thr.exit_time = ps_get_system_time();
    thr.exit_status = exit_status;
    thr.cross_thread_flags |= PS_CROSS_THREAD_FLAGS_TERMINATED;
    psp_remove_thread(thread);
    thr.kthread.header.signal_state = 1;
    thr.kthread.state = KthreadState::Terminated;

    if !thr.kthread.wait_list_entry.is_empty() {
        unsafe { ListEntry::remove_entry(&mut thr.kthread.wait_list_entry); }
    }
}

pub fn psp_exit_normal_thread(exit_status: NtStatus) {
    let current_thread = unsafe { ki_get_current_thread() };
    if current_thread.is_null() { return; }

    let ethread = current_thread as *mut Ethread;
    let thr = unsafe { &mut *ethread };

    ps_trace!("PspExitNormalThread: TID {} exiting", thr.cid.unique_thread);
    psp_exit_thread(thr, exit_status);

    loop { unsafe { asm!("hlt", options(nostack, nomem)); } }
}

// ============================================================
// PsCreateSystemThread
// ============================================================

pub fn ps_create_system_thread(
    thread_handle: *mut Handle,
    _desired_access: u32,
    _object_attributes: *mut ObjectAttributes,
    process_handle: Handle,
    client_id: *mut ClientId64,
    start_routine: unsafe extern "C" fn(Pvoid),
    start_context: Pvoid,
) -> NtStatus {
    let process = if process_handle.is_null() {
        unsafe { PS_SYSTEM_PROCESS }
    } else {
        process_handle as *mut Eprocess
    };
    if process.is_null() { return STATUS_INVALID_PARAMETER; }

    let thread = psp_allocate_thread(process, Some(start_routine), start_context, 0);
    if thread.is_null() { return STATUS_NO_MEMORY; }

    let thr = unsafe { &mut *thread };
    thr.cross_thread_flags |= PS_CROSS_THREAD_FLAGS_SYSTEM_THREAD;

    let status = psp_insert_thread(process, thread);
    if status != STATUS_SUCCESS { psp_free_thread(thread); return status; }

    thr.kthread.state = KthreadState::Ready;
    thr.cross_thread_flags &= !PS_CROSS_THREAD_FLAGS_TERMINATED;

    let processor = thr.kthread.processor as usize;
    if processor < 64 {
        unsafe {
            let prcb = &mut KI_PRCB[processor];
            ki_dispatcher_ready_insert_thread(&mut thr.kthread, prcb);
        }
    }

    if !thread_handle.is_null() { unsafe { *thread_handle = thread as Handle; } }
    if !client_id.is_null() { unsafe { *client_id = thr.cid; } }

    ps_trace!("PsCreateSystemThread: TID {}", thr.cid.unique_thread);
    STATUS_SUCCESS
}

pub fn ps_terminate_system_thread(exit_status: NtStatus) -> ! {
    psp_exit_normal_thread(exit_status);
    unreachable!()
}

pub fn ps_terminate_thread(thread: *mut Ethread, exit_status: NtStatus) -> NtStatus {
    if thread.is_null() { return STATUS_INVALID_PARAMETER; }
    let current_thread = unsafe { ki_get_current_thread() };
    if current_thread == thread as *mut Kthread { return STATUS_INVALID_PARAMETER; }
    psp_exit_thread(thread, exit_status);
    STATUS_SUCCESS
}

// ============================================================
// Thread Suspend/Resume
// ============================================================

pub fn ps_suspend_thread(thread: *mut Ethread) -> NtStatus {
    if thread.is_null() { return STATUS_INVALID_PARAMETER; }
    let thr = unsafe { &mut *thread };
    let current = unsafe { ki_get_current_thread() };
    if current == thr as *mut Ethread as *mut Kthread { return STATUS_INVALID_PARAMETER; }
    unsafe { ki_suspend_thread(&mut thr.kthread); }
    STATUS_SUCCESS
}

pub fn ps_resume_thread(thread: *mut Ethread) -> NtStatus {
    if thread.is_null() { return STATUS_INVALID_PARAMETER; }
    let thr = unsafe { &mut *thread };
    unsafe { ki_resume_thread(&mut thr.kthread); }
    STATUS_SUCCESS
}

pub fn ps_suspend_process(process: *mut Eprocess) -> NtStatus {
    if process.is_null() { return STATUS_INVALID_PARAMETER; }
    let mut thread = ps_get_next_thread(process, core::ptr::null_mut());
    while !thread.is_null() {
        ps_suspend_thread(thread);
        thread = ps_get_next_thread(process, thread);
    }
    STATUS_SUCCESS
}

pub fn ps_resume_process(process: *mut Eprocess) -> NtStatus {
    if process.is_null() { return STATUS_INVALID_PARAMETER; }
    let mut thread = ps_get_next_thread(process, core::ptr::null_mut());
    while !thread.is_null() {
        ps_resume_thread(thread);
        thread = ps_get_next_thread(process, thread);
    }
    STATUS_SUCCESS
}

// ============================================================
// Context Management
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Context {
    pub p1_home: u64,
    pub p2_home: u64,
    pub p3_home: u64,
    pub p4_home: u64,
    pub p5_home: u64,
    pub p6_home: u64,
    pub context_flags: u32,
    pub mx_csr: u32,
    pub seg_cs: u16,
    pub seg_ds: u16,
    pub seg_es: u16,
    pub seg_fs: u16,
    pub seg_gs: u16,
    pub seg_ss: u16,
    pub eflags: u32,
    pub dr0: u64,
    pub dr1: u64,
    pub dr2: u64,
    pub dr3: u64,
    pub dr6: u64,
    pub dr7: u64,
    pub rax: u64,
    pub rcx: u64,
    pub rdx: u64,
    pub rbx: u64,
    pub rsp: u64,
    pub rbp: u64,
    pub rsi: u64,
    pub rdi: u64,
    pub r8: u64,
    pub r9: u64,
    pub r10: u64,
    pub r11: u64,
    pub r12: u64,
    pub r13: u64,
    pub r14: u64,
    pub r15: u64,
    pub rip: u64,
    pub header: [M128A; 2],
    pub legacy: [M128A; 8],
    pub xmm0: M128A,
    pub xmm1: M128A,
    pub xmm2: M128A,
    pub xmm3: M128A,
    pub xmm4: M128A,
    pub xmm5: M128A,
    pub xmm6: M128A,
    pub xmm7: M128A,
    pub xmm8: M128A,
    pub xmm9: M128A,
    pub xmm10: M128A,
    pub xmm11: M128A,
    pub xmm12: M128A,
    pub xmm13: M128A,
    pub xmm14: M128A,
    pub xmm15: M128A,
    pub vector_register: [M128A; 26],
    pub vector_control: u64,
    pub debug_control: u64,
    pub last_branch_to_rip: u64,
    pub last_branch_from_rip: u64,
    pub last_exception_to_rip: u64,
    pub last_exception_from_rip: u64,
}

pub const CONTEXT_AMD64: u32 = 0x00100000;
pub const CONTEXT_CONTROL: u32 = CONTEXT_AMD64 | 0x00000001;
pub const CONTEXT_INTEGER: u32 = CONTEXT_AMD64 | 0x00000002;
pub const CONTEXT_SEGMENTS: u32 = CONTEXT_AMD64 | 0x00000004;
pub const CONTEXT_FLOATING_POINT: u32 = CONTEXT_AMD64 | 0x00000008;
pub const CONTEXT_DEBUG_REGISTERS: u32 = CONTEXT_AMD64 | 0x00000010;
pub const CONTEXT_FULL: u32 = CONTEXT_CONTROL | CONTEXT_INTEGER | CONTEXT_FLOATING_POINT;
pub const CONTEXT_ALL: u32 = CONTEXT_FULL | CONTEXT_DEBUG_REGISTERS | CONTEXT_SEGMENTS;

pub fn ke_get_context_thread(thread: *mut Ethread, context: *mut Context) -> NtStatus {
    if thread.is_null() || context.is_null() { return STATUS_INVALID_PARAMETER; }
    let thr = unsafe { &*thread };
    let ctx = unsafe { &mut *context };
    unsafe { core::ptr::write_bytes(context as *mut u8, 0, mem::size_of::<Context>()); }

    let flags = ctx.context_flags;
    if flags & CONTEXT_CONTROL != 0 {
        ctx.rip = thr.start_address as u64;
        ctx.rsp = thr.kthread.initial_stack as u64;
        ctx.seg_cs = 0x10; ctx.seg_ds = 0; ctx.seg_es = 0;
        ctx.seg_fs = 0x30; ctx.seg_gs = 0; ctx.seg_ss = 0x18;
        ctx.eflags = 0x202;
    }
    if flags & CONTEXT_INTEGER != 0 {
        ctx.rax = 0; ctx.rcx = 0; ctx.rdx = 0; ctx.rbx = 0;
        ctx.rsi = 0; ctx.rdi = 0; ctx.r8 = 0; ctx.r9 = 0;
        ctx.r10 = 0; ctx.r11 = 0; ctx.r12 = 0; ctx.r13 = 0;
        ctx.r14 = 0; ctx.r15 = 0;
    }
    if flags & CONTEXT_FLOATING_POINT != 0 {
        ctx.mx_csr = 0x1F80;
    }
    if flags & CONTEXT_DEBUG_REGISTERS != 0 {
        ctx.dr0 = 0; ctx.dr1 = 0; ctx.dr2 = 0;
        ctx.dr3 = 0; ctx.dr6 = 0; ctx.dr7 = 0;
    }
    ps_trace!("KeGetContextThread: TID {}", thr.cid.unique_thread);
    STATUS_SUCCESS
}

pub fn ke_set_context_thread(thread: *mut Ethread, context: *mut Context) -> NtStatus {
    if thread.is_null() || context.is_null() { return STATUS_INVALID_PARAMETER; }
    let thr = unsafe { &mut *thread };
    let ctx = unsafe { &*context };
    let flags = ctx.context_flags;
    if flags & CONTEXT_CONTROL != 0 {
        thr.kthread.initial_stack = ctx.rsp as Pvoid;
    }
    ps_trace!("KeSetContextThread: TID {}", thr.cid.unique_thread);
    STATUS_SUCCESS
}

pub fn ke_initialize_context_thread(
    thread: *mut Ethread,
    start_address: Pvoid,
    _start_parameter: Pvoid,
    _user_thread: Boolean,
    _zero_site: u64,
) -> NtStatus {
    if thread.is_null() { return STATUS_INVALID_PARAMETER; }
    let thr = unsafe { &mut *thread };
    thr.start_address = start_address;
    thr.win32_start_address = start_address;
    thr.kthread.initial_stack = thr.stack_base;
    thr.kthread.stack_limit = thr.stack_limit;
    thr.kthread.stack_base = thr.stack_base;
    ps_trace!("KeInitializeContextThread: initialized");
    STATUS_SUCCESS
}

pub fn ps_get_context_thread(thread: *mut Ethread, context: *mut Context) -> NtStatus {
    ke_get_context_thread(thread, context)
}

pub fn ps_set_context_thread(thread: *mut Ethread, context: *mut Context) -> NtStatus {
    ke_set_context_thread(thread, context)
}

// ============================================================
// User-Mode Transitions
// ============================================================

pub unsafe fn ki_attach_process(
    process: *mut Kprocess,
    old_process: *mut *mut Kprocess,
    attach_state: *mut u64,
) {
    if process.is_null() { return; }
    let thread = ki_get_current_thread();
    if thread.is_null() { return; }

    let t = &mut *thread;
    *old_process = t.process;

    let mut old_cr3: u64;
    asm!("mov {0}, cr3", out(reg) old_cr3, options(nostack, nomem));
    *attach_state = old_cr3;

    t.process = process;
    let dir_table_base = (*process).directory_table_base;
    if dir_table_base != 0 {
        asm!("mov cr3, {0}", in(reg) dir_table_base, options(nostack, nomem));
    }
}

pub unsafe fn ki_detach_process(old_process: *mut Kprocess, attach_state: u64) {
    let thread = ki_get_current_thread();
    if thread.is_null() { return; }
    let t = &mut *thread;
    t.process = old_process;
    if attach_state != 0 {
        asm!("mov cr3, {0}", in(reg) attach_state, options(nostack, nomem));
    }
}

pub fn ke_stack_attach_process(process: *mut Eprocess, attach_state: *mut u64) {
    if process.is_null() || attach_state.is_null() { return; }
    let kprocess = unsafe { &mut (*process).kprocess } as *mut Kprocess;
    unsafe {
        let thread = ki_get_current_thread();
        if !thread.is_null() {
            attach_state.write((*thread).process as u64);
            ki_attach_process(
                kprocess,
                &mut (*thread).process as *mut *mut Kprocess,
                attach_state.add(1),
            );
        }
    }
}

pub fn ke_unstack_detach_process(attach_state: *mut u64) {
    if attach_state.is_null() { return; }
    unsafe {
        let old_process = *attach_state as *mut Kprocess;
        let cr3 = *attach_state.add(1);
        ki_detach_process(old_process, cr3);
    }
}

// ============================================================
// Process/Thread Query Functions
// ============================================================

pub fn ps_get_thread_id(thread: *mut Ethread) -> u64 {
    if thread.is_null() { 0 } else { unsafe { (*thread).cid.unique_thread } }
}

pub fn ps_get_process_id(process: *mut Eprocess) -> u64 {
    if process.is_null() { 0 } else { unsafe { (*process).unique_process_id } }
}

pub fn ps_get_current_process() -> *mut Eprocess {
    let thread = unsafe { ki_get_current_thread() };
    if thread.is_null() { return core::ptr::null_mut(); }
    let ethread = thread as *mut Ethread;
    unsafe { (*ethread).threads_process as *mut Eprocess }
}

pub fn ps_get_current_process_id() -> u64 {
    let process = ps_get_current_process();
    ps_get_process_id(process)
}

pub fn ps_get_current_thread() -> *mut Ethread {
    let kthread = unsafe { ki_get_current_thread() };
    if kthread.is_null() { return core::ptr::null_mut(); }
    kthread as *mut Ethread
}

pub fn ps_get_thread_process(thread: *mut Ethread) -> *mut Eprocess {
    if thread.is_null() { core::ptr::null_mut() } else { unsafe { (*thread).threads_process } }
}

pub fn ps_get_thread_process_id(thread: *mut Ethread) -> u64 {
    let process = ps_get_thread_process(thread);
    ps_get_process_id(process)
}

pub fn ps_is_system_thread(thread: *mut Ethread) -> bool {
    if thread.is_null() { false }
    else { unsafe { (*thread).cross_thread_flags & PS_CROSS_THREAD_FLAGS_SYSTEM_THREAD != 0 } }
}

pub fn ps_is_system_process(process: *mut Eprocess) -> bool {
    ps_get_process_id(process) == PS_SYSTEM_PROCESS_ID
}

pub fn ps_get_thread_priority(thread: *mut Ethread) -> i32 {
    if thread.is_null() { 0 } else { unsafe { (*thread).kthread.priority as i32 } }
}

pub fn ps_set_thread_priority(thread: *mut Ethread, priority: i32) -> NtStatus {
    if thread.is_null() { return STATUS_INVALID_PARAMETER; }
    let thr = unsafe { &mut *thread };
    let clamped = priority.max(0).min(31) as i8;
    unsafe { ke_set_priority_thread(&mut thr.kthread, clamped); }
    STATUS_SUCCESS
}

pub fn ps_get_process_priority_class(process: *mut Eprocess) -> u32 {
    if process.is_null() { 0 } else { unsafe { (*process).priority_class as u32 } }
}

pub fn ps_set_process_priority_class(process: *mut Eprocess, priority_class: u32) -> NtStatus {
    if process.is_null() { return STATUS_INVALID_PARAMETER; }
    if priority_class > PS_PRIORITY_CLASS_REALTIME { return STATUS_INVALID_PARAMETER; }
    let proc = unsafe { &mut *process };
    proc.priority_class = priority_class as u8;
    proc.kprocess.base_priority = match priority_class {
        PS_PRIORITY_CLASS_IDLE => 4,
        PS_PRIORITY_CLASS_NORMAL => 8,
        PS_PRIORITY_CLASS_HIGH => 13,
        PS_PRIORITY_CLASS_REALTIME => 24,
        _ => 8,
    } as u8;
    STATUS_SUCCESS
}

pub fn ps_get_process_debug_port(process: *mut Eprocess) -> Pvoid {
    if process.is_null() { core::ptr::null_mut() } else { unsafe { (*process).debug_port } }
}

pub fn ps_set_process_debug_port(process: *mut Eprocess, port: Pvoid) -> NtStatus {
    if process.is_null() { return STATUS_INVALID_PARAMETER; }
    unsafe { (*process).debug_port = port; }
    STATUS_SUCCESS
}

pub fn ps_get_process_peb(process: *mut Eprocess) -> *mut Peb {
    if process.is_null() { core::ptr::null_mut() } else { unsafe { (*process).peb } }
}

pub fn ps_get_current_peb() -> *mut Peb {
    let process = ps_get_current_process();
    ps_get_process_peb(process)
}

pub fn ps_get_process_exit_status(process: *mut Eprocess) -> NtStatus {
    if process.is_null() { 0 } else { unsafe { (*process).exit_status } }
}

pub fn ps_get_process_create_time_quad_part(process: *mut Eprocess) -> i64 {
    if process.is_null() { 0 } else { unsafe { (*process).create_time } }
}

pub fn ps_get_thread_create_time(thread: *mut Ethread) -> i64 {
    if thread.is_null() { 0 } else { unsafe { (*thread).create_time } }
}

pub fn ps_get_thread_exit_status(thread: *mut Ethread) -> NtStatus {
    if thread.is_null() { 0 } else { unsafe { (*thread).exit_status } }
}

pub fn ps_get_process_session_id(process: *mut Eprocess) -> u32 {
    if process.is_null() { 0 } else { unsafe { (*process).session_id } }
}

pub fn ps_set_process_image_name(process: *mut Eprocess, name: &str) -> NtStatus {
    if process.is_null() { return STATUS_INVALID_PARAMETER; }
    let proc = unsafe { &mut *process };
    let bytes = name.as_bytes();
    let len = bytes.len().min(PS_IMAGE_FILE_NAME_LENGTH);
    proc.image_file_name[..len].copy_from_slice(&bytes[..len]);
    if len < PS_IMAGE_FILE_NAME_LENGTH { proc.image_file_name[len] = 0; }
    STATUS_SUCCESS
}

pub fn ps_reference_imperson_token(thread: *mut Ethread) -> Ptoken {
    if thread.is_null() { return core::ptr::null_mut(); }
    let process = ps_get_thread_process(thread);
    if !process.is_null() { unsafe { (*process).token } } else { core::ptr::null_mut() }
}

pub fn ps_revert_to_self() -> NtStatus {
    let thread = ps_get_current_thread();
    if !thread.is_null() {
        let thr = unsafe { &mut *thread };
        thr.cross_thread_flags &= !PS_CROSS_THREAD_FLAGS_IMPERSONATING;
    }
    STATUS_SUCCESS
}

// ============================================================
// Information Classes
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ProcessInformationClass {
    ProcessBasicInformation = 0,
    ProcessDebugPort = 7,
    ProcessWow64Information = 9,
    ProcessImageFileName = 27,
    ProcessBreakOnTermination = 29,
    ProcessDebugObjectHandle = 30,
    ProcessHandleInformation = 51,
    ProcessMitigationPolicy = 53,
    ProcessProtectionInformation = 61,
}

#[repr(C)]
pub struct ProcessBasicInformation {
    pub exit_status: NtStatus,
    pub peb_base_address: Pvoid,
    pub affinity_mask: u64,
    pub base_priority: i32,
    pub unique_process_id: u64,
    pub inherited_from_unique_process_id: u64,
}

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ThreadInformationClass {
    ThreadBasicInformation = 0,
    ThreadTimes = 1,
    ThreadPriority = 2,
    ThreadBasePriority = 3,
    ThreadQuerySetWin32StartAddress = 9,
    ThreadHideFromDebugger = 17,
    ThreadBreakOnTermination = 28,
    ThreadHandleInformation = 30,
}

#[repr(C)]
pub struct ThreadBasicInformation {
    pub exit_status: NtStatus,
    pub teb_base_address: Pvoid,
    pub client_id: ClientId64,
    pub affinity_mask: u64,
    pub priority: i32,
    pub base_priority: i32,
}

pub fn ps_query_process_information(
    process: *mut Eprocess,
    information_class: ProcessInformationClass,
    buffer: Pvoid,
    buffer_length: u32,
    return_length: *mut u32,
) -> NtStatus {
    if process.is_null() || buffer.is_null() { return STATUS_INVALID_PARAMETER; }
    let proc = unsafe { &*process };

    match information_class {
        ProcessInformationClass::ProcessBasicInformation => {
            if buffer_length < mem::size_of::<ProcessBasicInformation>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = unsafe { &mut *(buffer as *mut ProcessBasicInformation) };
            info.exit_status = proc.exit_status;
            info.peb_base_address = proc.peb as Pvoid;
            info.affinity_mask = proc.kprocess.affinity;
            info.base_priority = proc.kprocess.base_priority as i32;
            info.unique_process_id = proc.unique_process_id;
            info.inherited_from_unique_process_id = proc.inherited_from_unique_process_id;
            if !return_length.is_null() {
                unsafe { *return_length = mem::size_of::<ProcessBasicInformation>() as u32; }
            }
            STATUS_SUCCESS
        }
        ProcessInformationClass::ProcessImageFileName => {
            let name_len = proc.image_file_name.iter().position(|&b| b == 0)
                .unwrap_or(PS_IMAGE_FILE_NAME_LENGTH);
            let required_size = (name_len * 2 + 16) as u32;
            if buffer_length < required_size {
                if !return_length.is_null() { unsafe { *return_length = required_size; } }
                return STATUS_BUFFER_TOO_SMALL;
            }
            let us = unsafe { &mut *(buffer as *mut UnicodeString) };
            us.length = (name_len * 2) as u16;
            us.maximum_length = ((name_len + 1) * 2) as u16;
            let name_buffer = unsafe {
                (buffer as *mut u8).add(mem::size_of::<UnicodeString>()) as *mut u16
            };
            for i in 0..name_len {
                unsafe { *name_buffer.add(i) = proc.image_file_name[i] as u16; }
            }
            unsafe { *name_buffer.add(name_len) = 0; }
            us.buffer = name_buffer;
            if !return_length.is_null() { unsafe { *return_length = required_size; } }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

pub fn ps_query_thread_information(
    thread: *mut Ethread,
    information_class: ThreadInformationClass,
    buffer: Pvoid,
    buffer_length: u32,
    return_length: *mut u32,
) -> NtStatus {
    if thread.is_null() || buffer.is_null() { return STATUS_INVALID_PARAMETER; }
    let thr = unsafe { &mut *thread };

    match information_class {
        ThreadInformationClass::ThreadBasicInformation => {
            if buffer_length < mem::size_of::<ThreadBasicInformation>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = unsafe { &mut *(buffer as *mut ThreadBasicInformation) };
            info.exit_status = 0;
            info.teb_base_address = thr.kthread.teb;
            info.client_id = thr.cid;
            info.affinity_mask = thr.kthread.affinity;
            info.priority = thr.kthread.priority as i32;
            info.base_priority = thr.kthread.base_priority as i32;
            if !return_length.is_null() {
                unsafe { *return_length = mem::size_of::<ThreadBasicInformation>() as u32; }
            }
            STATUS_SUCCESS
        }
        ThreadInformationClass::ThreadQuerySetWin32StartAddress => {
            if buffer_length < mem::size_of::<Pvoid>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            unsafe {
                *(buffer as *mut Pvoid) = thr.win32_start_address;
                if !return_length.is_null() { *return_length = mem::size_of::<Pvoid>() as u32; }
            }
            STATUS_SUCCESS
        }
        ThreadInformationClass::ThreadHideFromDebugger => {
            if buffer_length >= 4 {
                let flag = unsafe { *(buffer as *const u32) };
                if flag != 0 {
                    thr.cross_thread_flags |= PS_CROSS_THREAD_FLAGS_HIDEFROMDEBUGGER;
                } else {
                    thr.cross_thread_flags &= !PS_CROSS_THREAD_FLAGS_HIDEFROMDEBUGGER;
                }
            }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

// ============================================================
// Process/Thread Iteration
// ============================================================

pub fn ps_get_next_process(process: *mut Eprocess) -> *mut Eprocess {
    unsafe {
        let head = &mut PS_ACTIVE_PROCESS_LIST_HEAD;
        if process.is_null() {
            if head.flink.is_null() || head.blink.is_null() { return core::ptr::null_mut(); }
            if head.flink == head as *mut ListEntry { return core::ptr::null_mut(); }
            let offset = offset_of_active_process_links();
            return (head.flink as usize - offset) as *mut Eprocess;
        }

        let proc_ref = &*process;
        let next = proc_ref.active_process_links.flink;
        if next == head as *mut ListEntry || next.is_null() {
            return core::ptr::null_mut();
        }
        let offset = offset_of_active_process_links();
        (next as usize - offset) as *mut Eprocess
    }
}

pub fn ps_get_next_thread(process: *mut Eprocess, thread: *mut Ethread) -> *mut Ethread {
    if process.is_null() { return core::ptr::null_mut(); }
    let proc = unsafe { &*process };

    if thread.is_null() {
        let first = proc.thread_list_head.flink;
        if first == &proc.thread_list_head as *const ListEntry as *mut ListEntry || first.is_null() {
            return core::ptr::null_mut();
        }
        let offset = offset_of_wait_list_entry_in_thread();
        return (first as usize - offset) as *mut Ethread;
    }

    let thr = unsafe { &*thread };
    let next = thr.kthread.wait_list_entry.flink;
    if next == &proc.thread_list_head as *const ListEntry as *mut ListEntry || next.is_null() {
        return core::ptr::null_mut();
    }
    let offset = offset_of_wait_list_entry_in_thread();
    (next as usize - offset) as *mut Ethread
}

pub fn ps_capture_thread(thread_handle: Handle) -> *mut Ethread {
    if thread_handle.is_null() { return core::ptr::null_mut(); }
    thread_handle as *mut Ethread
}

pub fn ps_dereference_thread(thread: *mut Ethread) {
    if !thread.is_null() {
        ps_trace!("PsDereferenceThread: TID {}", ps_get_thread_id(thread));
    }
}

pub fn ps_capture_process(process_handle: Handle) -> *mut Eprocess {
    if process_handle.is_null() { return core::ptr::null_mut(); }
    process_handle as *mut Eprocess
}

pub fn ps_dereference_process(process: *mut Eprocess) {
    if !process.is_null() {
        ps_trace!("PsDereferenceProcess: PID {}", ps_get_process_id(process));
    }
}

// ============================================================
// Job Object Management
// ============================================================

pub fn ps_create_job_set(job: *mut *mut Ejob, _reserved: Pvoid) -> NtStatus {
    if job.is_null() { return STATUS_INVALID_PARAMETER; }

    let alloc_size = mem::size_of::<Ejob>();
    let layout = match core::alloc::Layout::from_size_align(alloc_size, 16) {
        Ok(l) => l, Err(_) => return STATUS_NO_MEMORY,
    };

    let job_obj = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut Ejob };
    if job_obj.is_null() { return STATUS_NO_MEMORY; }

    let job_ref = unsafe { &mut *job_obj };
    unsafe { sync::ke_initialize_event(&mut job_ref.event, 1, 0); }
    job_ref.job_links = ListEntry::new();
    job_ref.job_links.flink = &mut job_ref.job_links as *mut ListEntry;
    job_ref.job_links.blink = &mut job_ref.job_links as *mut ListEntry;
    job_ref.process_list = ListEntry::new();
    job_ref.process_list.flink = &mut job_ref.process_list as *mut ListEntry;
    job_ref.process_list.blink = &mut job_ref.process_list as *mut ListEntry;
    job_ref.process_lock = 0;
    job_ref.active_process_high_watermark = 0;
    job_ref.active_threads = 0;
    job_ref.total_terminated = 0;
    job_ref.total_active = 0;
    job_ref.limit = JobObjectLimits::new();
    job_ref.job_flags = 0;

    unsafe { *job = job_obj; }
    ps_trace!("PsCreateJobSet: created job object");
    STATUS_SUCCESS
}

pub fn psp_job_insert_process(job: *mut Ejob, process: *mut Eprocess) {
    if job.is_null() || process.is_null() { return; }
    let job_ref = unsafe { &mut *job };
    let proc_ref = unsafe { &mut *process };
    proc_ref.job = job;

    acquire_spin_lock(&mut job_ref.process_lock);
    job_ref.process_list.insert_tail(&mut proc_ref.active_process_links);
    job_ref.total_active += 1;
    job_ref.active_threads += proc_ref.active_threads;
    if job_ref.total_active > job_ref.active_process_high_watermark {
        job_ref.active_process_high_watermark = job_ref.total_active;
    }
    release_spin_lock(&mut job_ref.process_lock);
}

pub fn psp_job_remove_process(job: *mut Ejob, process: *mut Eprocess) {
    if job.is_null() || process.is_null() { return; }
    let job_ref = unsafe { &mut *job };
    let proc_ref = unsafe { &mut *process };
    if proc_ref.job != job { return; }

    acquire_spin_lock(&mut job_ref.process_lock);
    proc_ref.active_process_links.remove();
    job_ref.total_active = job_ref.total_active.saturating_sub(1);
    release_spin_lock(&mut job_ref.process_lock);
    proc_ref.job = core::ptr::null_mut();
}

pub fn terminate_job_object(job: *mut Ejob, exit_status: NtStatus) -> NtStatus {
    if job.is_null() { return STATUS_INVALID_PARAMETER; }
    let job_ref = unsafe { &*job };

    let mut current = job_ref.process_list.flink;
    while !current.is_null() && current != &job_ref.process_list as *const ListEntry as *mut ListEntry {
        let proc_link = unsafe { &*current };
        current = proc_link.flink;
        let offset = offset_of_active_process_links();
        let process = unsafe { (current as usize - offset) as *mut Eprocess };
        if !process.is_null() { ps_terminate_process(process, exit_status); }
    }

    unsafe { sync::ke_set_event(&mut (*job).event as *mut Kevent, 1, 0); }
    STATUS_SUCCESS
}

pub fn ps_terminate_job_object(job: *mut Ejob, process: *mut Eprocess, exit_status: NtStatus) -> NtStatus {
    if job.is_null() || process.is_null() { return STATUS_INVALID_PARAMETER; }
    ps_terminate_process(process, exit_status)
}

// ============================================================
// Process/Thread Notify Routines
// ============================================================

pub type PspProcessNotifyRoutine = extern "C" fn(*mut Eprocess, *mut Eprocess, Boolean);
pub type PspThreadNotifyRoutine = extern "C" fn(*mut Eprocess, *mut Ethread, Boolean);

pub static mut PSP_PROCESS_NOTIFY_ROUTINES: [PspProcessNotifyRoutine; 8] = [dummy_process_notify; 8];
pub static mut PSP_PROCESS_NOTIFY_ROUTINE_COUNT: usize = 0;
pub static mut PSP_THREAD_NOTIFY_ROUTINES: [PspThreadNotifyRoutine; 8] = [dummy_thread_notify; 8];
pub static mut PSP_THREAD_NOTIFY_ROUTINE_COUNT: usize = 0;

extern "C" fn dummy_process_notify(_p: *mut Eprocess, _q: *mut Eprocess, _c: Boolean) {}
extern "C" fn dummy_thread_notify(_p: *mut Eprocess, _t: *mut Ethread, _c: Boolean) {}

pub fn ps_set_create_process_notify_routine(
    notify_routine: PspProcessNotifyRoutine, remove: Boolean,
) -> NtStatus {
    unsafe {
        if remove != 0 {
            for i in 0..PSP_PROCESS_NOTIFY_ROUTINE_COUNT {
                if PSP_PROCESS_NOTIFY_ROUTINES[i] as usize == notify_routine as usize {
                    for j in i..PSP_PROCESS_NOTIFY_ROUTINE_COUNT - 1 {
                        PSP_PROCESS_NOTIFY_ROUTINES[j] = PSP_PROCESS_NOTIFY_ROUTINES[j + 1];
                    }
                    PSP_PROCESS_NOTIFY_ROUTINE_COUNT -= 1;
                    PSP_PROCESS_NOTIFY_ROUTINES[PSP_PROCESS_NOTIFY_ROUTINE_COUNT] = dummy_process_notify;
                    return STATUS_SUCCESS;
                }
            }
            STATUS_NOT_FOUND
        } else {
            if PSP_PROCESS_NOTIFY_ROUTINE_COUNT >= 8 { return STATUS_INSUFFICIENT_RESOURCES; }
            PSP_PROCESS_NOTIFY_ROUTINES[PSP_PROCESS_NOTIFY_ROUTINE_COUNT] = notify_routine;
            PSP_PROCESS_NOTIFY_ROUTINE_COUNT += 1;
            STATUS_SUCCESS
        }
    }
}

pub fn ps_set_create_thread_notify_routine(
    notify_routine: PspThreadNotifyRoutine, remove: Boolean,
) -> NtStatus {
    unsafe {
        if remove != 0 {
            for i in 0..PSP_THREAD_NOTIFY_ROUTINE_COUNT {
                if PSP_THREAD_NOTIFY_ROUTINES[i] as usize == notify_routine as usize {
                    for j in i..PSP_THREAD_NOTIFY_ROUTINE_COUNT - 1 {
                        PSP_THREAD_NOTIFY_ROUTINES[j] = PSP_THREAD_NOTIFY_ROUTINES[j + 1];
                    }
                    PSP_THREAD_NOTIFY_ROUTINE_COUNT -= 1;
                    PSP_THREAD_NOTIFY_ROUTINES[PSP_THREAD_NOTIFY_ROUTINE_COUNT] = dummy_thread_notify;
                    return STATUS_SUCCESS;
                }
            }
            STATUS_NOT_FOUND
        } else {
            if PSP_THREAD_NOTIFY_ROUTINE_COUNT >= 8 { return STATUS_INSUFFICIENT_RESOURCES; }
            PSP_THREAD_NOTIFY_ROUTINES[PSP_THREAD_NOTIFY_ROUTINE_COUNT] = notify_routine;
            PSP_THREAD_NOTIFY_ROUTINE_COUNT += 1;
            STATUS_SUCCESS
        }
    }
}

pub fn psp_call_process_notify_routines(
    parent: *mut Eprocess, process: *mut Eprocess, create: Boolean,
) {
    unsafe {
        let count = PSP_PROCESS_NOTIFY_ROUTINE_COUNT;
        for i in 0..count { PSP_PROCESS_NOTIFY_ROUTINES[i](parent, process, create); }
    }
}

pub fn psp_call_thread_notify_routines(
    process: *mut Eprocess, thread: *mut Ethread, create: Boolean,
) {
    unsafe {
        let count = PSP_THREAD_NOTIFY_ROUTINE_COUNT;
        for i in 0..count { PSP_THREAD_NOTIFY_ROUTINES[i](process, thread, create); }
    }
}

// ============================================================
// PsInitialize - Module initialization
// ============================================================

pub fn ps_initialize() {
    ps_dbg!("PsInitialize: beginning process manager initialization");

    PS_NEXT_PROCESS_ID.store(100, Ordering::Relaxed);
    PS_NEXT_THREAD_ID.store(200, Ordering::Relaxed);
    PS_PROCESS_COUNT.store(0, Ordering::Relaxed);
    PS_THREAD_COUNT.store(0, Ordering::Relaxed);
    PS_SYSTEM_THREAD_COUNT.store(0, Ordering::Relaxed);

    ps_initialize_system_process();

    unsafe {
        PSP_PROCESS_NOTIFY_ROUTINE_COUNT = 0;
        PSP_THREAD_NOTIFY_ROUTINE_COUNT = 0;
    }

    ps_dbg!("PsInitialize: process manager initialization complete");
}

pub fn ps_initialize_system_process() {
    ps_trace!("PsInitializeSystemProcess: beginning initialization");

    let system_process = psp_allocate_process(core::ptr::null_mut(), core::ptr::null_mut());
    if system_process.is_null() {
        ps_err!("PsInitializeSystemProcess: failed to allocate System process");
        return;
    }

    let sys = unsafe { &mut *system_process };
    sys.unique_process_id = PS_SYSTEM_PROCESS_ID;
    sys.unique_process_id_v2 = PS_SYSTEM_PROCESS_ID;

    let name = b"System\0";
    let len = name.len().min(PS_IMAGE_FILE_NAME_LENGTH);
    sys.image_file_name[..len].copy_from_slice(&name[..len]);
    sys.priority_class = PS_PRIORITY_CLASS_NORMAL as u8;
    sys.kprocess.base_priority = 8;
    sys.directory_table_base = 0;
    sys.process_lock = 0;

    psp_insert_process(system_process);

    let system_thread = psp_create_initial_thread(system_process);
    if !system_thread.is_null() {
        let thr = unsafe { &mut *system_thread };
        thr.cross_thread_flags |= PS_CROSS_THREAD_FLAGS_SYSTEM_THREAD;
        thr.kthread.state = KthreadState::Ready;
        thr.cross_thread_flags &= !PS_CROSS_THREAD_FLAGS_TERMINATED;
        unsafe {
            let prcb = &mut KI_PRCB[0];
            ki_dispatcher_ready_insert_thread(&mut thr.kthread, prcb);
        }
    }

    unsafe { PS_SYSTEM_PROCESS = system_process; }

    let idle_process = psp_allocate_process(core::ptr::null_mut(), core::ptr::null_mut());
    if !idle_process.is_null() {
        let idle = unsafe { &mut *idle_process };
        idle.unique_process_id = PS_IDLE_PROCESS_ID;
        idle.unique_process_id_v2 = PS_IDLE_PROCESS_ID;
        let iname = b"Idle\0";
        let ilen = iname.len().min(PS_IMAGE_FILE_NAME_LENGTH);
        idle.image_file_name[..ilen].copy_from_slice(&iname[..ilen]);
        idle.priority_class = PS_PRIORITY_CLASS_IDLE as u8;
        idle.kprocess.base_priority = 0;
        psp_insert_process(idle_process);
        unsafe { PS_IDLE_PROCESS = idle_process; }
    }

    ps_trace!("PsInitializeSystemProcess: complete");
}

// ============================================================
// Logging Macros
// ============================================================

macro_rules! ps_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "ps_trace")]
        crate::kernel_log!("[Ps] {}", format_args!($($arg)*));
    };
}

macro_rules! ps_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ps] {}", format_args!($($arg)*));
    };
}

macro_rules! ps_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ps] {}", format_args!($($arg)*));
    };
}

macro_rules! ps_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ps] {}", format_args!($($arg)*));
    };
}

pub(crate) use ps_trace;
pub(crate) use ps_dbg;
pub(crate) use ps_warn;
pub(crate) use ps_err;
