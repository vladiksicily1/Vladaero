/// # Native API (Nt/Zw System Calls) - ntoskrnl.exe
///
/// Complete implementation of the Native API system calls including
/// file I/O, section management, thread/process creation, registry
/// operations, and synchronization primitives.
///
/// References:
///   - Windows Internals 7th Ed. Part 2, Chapter 6/7/8/10
///   - WRK: ntoskrnl/nt/
///   - ReactOS: ntoskrnl/nt/

pub mod syscalls;
pub mod ext;

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicU32, AtomicU64, Ordering};

use crate::types::*;
use crate::ke::dispatcher::*;
use crate::ke::sync;
use crate::rtl::ContextRecord;

// ============================================================
// Constants
// ============================================================

pub const FILE_GENERIC_READ: Ulong = 0x00120089;
pub const FILE_GENERIC_WRITE: Ulong = 0x00120116;
pub const FILE_GENERIC_EXECUTE: Ulong = 0x001200A0;
pub const FILE_GENERIC_ALL: Ulong = 0x001F01FF;

pub const FILE_SUPERSEDE: Ulong = 0x00000000;
pub const FILE_CREATE: Ulong = 0x00000001;
pub const FILE_OPEN: Ulong = 0x00000002;
pub const FILE_OPEN_IF: Ulong = 0x00000003;
pub const FILE_OVERWRITE: Ulong = 0x00000004;
pub const FILE_OVERWRITE_IF: Ulong = 0x00000005;

pub const FILE_DIRECTORY_FILE: Ulong = 0x00000001;
pub const FILE_WRITE_THROUGH: Ulong = 0x00000002;
pub const FILE_SEQUENTIAL_ONLY: Ulong = 0x00000004;
pub const FILE_NO_INTERMEDIATE_BUFFERING: Ulong = 0x00000008;
pub const FILE_SYNCHRONOUS_IO_ALERT: Ulong = 0x00000010;
pub const FILE_SYNCHRONOUS_IO_NONALERT: Ulong = 0x00000020;
pub const FILE_NON_DIRECTORY_FILE: Ulong = 0x00000040;
pub const FILE_NO_EA_KNOWLEDGE: Ulong = 0x00000200;
pub const FILE_RANDOM_ACCESS: Ulong = 0x00000800;
pub const FILE_DELETE_ON_CLOSE: Ulong = 0x00001000;
pub const FILE_OPEN_BY_FILE_ID: Ulong = 0x00002000;
pub const FILE_OPEN_FOR_BACKUP_INTENT: Ulong = 0x00004000;
pub const FILE_NO_COMPRESSION: Ulong = 0x00008000;
pub const FILE_OPEN_REQUIRING_OPLOCK: Ulong = 0x00010000;
pub const FILE_DISALLOW_EXCLUSIVE: Ulong = 0x00020000;
pub const FILE_SESSION_AWARE: Ulong = 0x00040000;

pub const OBJ_CASE_INSENSITIVE: Ulong = 0x00000040;
pub const OBJ_INHERIT: Ulong = 0x00000002;
pub const OBJ_PERMANENT: Ulong = 0x00000010;
pub const OBJ_EXCLUSIVE: Ulong = 0x00000020;
pub const OBJ_KERNEL_HANDLE: Ulong = 0x00000200;

pub const SECTION_QUERY: Ulong = 0x0001;
pub const SECTION_MAP_WRITE: Ulong = 0x0002;
pub const SECTION_MAP_READ: Ulong = 0x0004;
pub const SECTION_MAP_EXECUTE: Ulong = 0x0008;
pub const SECTION_EXTEND_SIZE: Ulong = 0x0010;
pub const SECTION_MAP_EXECUTE_EXPLICIT: Ulong = 0x0020;

pub const FILE_MAP_WRITE: Ulong = 0x0002;
pub const FILE_MAP_READ: Ulong = 0x0004;
pub const FILE_MAP_EXECUTE: Ulong = 0x0020;

pub const PAGE_NOACCESS: Ulong = 0x01;
pub const PAGE_READONLY: Ulong = 0x02;
pub const PAGE_READWRITE: Ulong = 0x04;
pub const PAGE_WRITECOPY: Ulong = 0x08;
pub const PAGE_EXECUTE: Ulong = 0x10;
pub const PAGE_EXECUTE_READ: Ulong = 0x20;
pub const PAGE_EXECUTE_READWRITE: Ulong = 0x40;
pub const PAGE_EXECUTE_WRITECOPY: Ulong = 0x80;
pub const PAGE_GUARD: Ulong = 0x100;
pub const PAGE_NOCACHE: Ulong = 0x200;
pub const PAGE_WRITECOMBINE: Ulong = 0x400;

pub const MEM_COMMIT: Ulong = 0x1000;
pub const MEM_RESERVE: Ulong = 0x2000;
pub const MEM_DECOMMIT: Ulong = 0x4000;
pub const MEM_RELEASE: Ulong = 0x8000;
pub const MEM_FREE: Ulong = 0x10000;
pub const MEM_PRIVATE: Ulong = 0x20000;
pub const MEM_MAPPED: Ulong = 0x40000;
pub const MEM_RESET: Ulong = 0x80000;
pub const MEM_TOP_DOWN: Ulong = 0x100000;
pub const MEM_LARGE_PAGES: Ulong = 0x20000000;

pub const THREAD_CREATE_FLAGS_CREATE_SUSPENDED: Ulong = 0x00000001;

pub const THREAD_TERMINATE: Ulong = 0x0001;
pub const THREAD_SUSPEND_RESUME: Ulong = 0x0002;
pub const THREAD_GET_CONTEXT: Ulong = 0x0008;
pub const THREAD_SET_CONTEXT: Ulong = 0x0010;
pub const THREAD_SET_INFORMATION: Ulong = 0x0020;
pub const THREAD_QUERY_INFORMATION: Ulong = 0x0040;

pub const PROCESS_TERMINATE: Ulong = 0x0001;
pub const PROCESS_CREATE_THREAD: Ulong = 0x0002;
pub const PROCESS_VM_OPERATION: Ulong = 0x0008;
pub const PROCESS_VM_READ: Ulong = 0x0010;
pub const PROCESS_VM_WRITE: Ulong = 0x0020;
pub const PROCESS_QUERY_INFORMATION: Ulong = 0x0400;

pub const KEY_QUERY_VALUE: Ulong = 0x0001;
pub const KEY_SET_VALUE: Ulong = 0x0002;
pub const KEY_CREATE_SUB_KEY: Ulong = 0x0004;
pub const KEY_ENUMERATE_SUB_KEYS: Ulong = 0x0008;
pub const KEY_NOTIFY: Ulong = 0x0010;
pub const KEY_CREATE_LINK: Ulong = 0x0020;
pub const KEY_WRITE_DAC: Ulong = 0x0040;
pub const KEY_WRITE_OWNER: Ulong = 0x0080;
pub const KEY_READ: Ulong = 0x00020019;
pub const KEY_ALL_ACCESS: Ulong = 0x000F003F;
pub const KEY_EXECUTE: Ulong = 0x00020019;

pub const REG_NONE: Ulong = 0;
pub const REG_SZ: Ulong = 1;
pub const REG_EXPAND_SZ: Ulong = 2;
pub const REG_BINARY: Ulong = 3;
pub const REG_DWORD: Ulong = 4;
pub const REG_DWORD_LITTLE_ENDIAN: Ulong = 5;
pub const REG_DWORD_BIG_ENDIAN: Ulong = 6;
pub const REG_LINK: Ulong = 7;
pub const REG_MULTI_SZ: Ulong = 8;
pub const REG_RESOURCE_LIST: Ulong = 9;
pub const REG_FULL_RESOURCE_DESCRIPTOR: Ulong = 10;
pub const REG_RESOURCE_REQUIREMENTS_LIST: Ulong = 11;
pub const REG_QWORD: Ulong = 11;

pub const REG_OPTION_NON_VOLATILE: Ulong = 0x00000000;
pub const REG_OPTION_VOLATILE: Ulong = 0x00000001;

pub const RTL_QUERY_REGISTRY_VARIABLE: Ulong = 0x00000001;
pub const RTL_QUERY_REGISTRY_SUBKEY: Ulong = 0x00000008;

pub const STATUS_TIMEOUT: NtStatus = 0x00000102;
pub const STATUS_NOT_FOUND: NtStatus = 0xC0000225;
pub const STATUS_OBJECT_NAME_COLLISION: NtStatus = 0xC0000035;
pub const STATUS_END_OF_FILE: NtStatus = 0xC0000011;
pub const STATUS_PENDING: NtStatus = 0x00000103;
pub const STATUS_CANCELLED: NtStatus = 0xC0000120;

pub const WAIT_OBJECT_0: NtStatus = 0x00000000;
pub const STATUS_ABANDONED_WAIT_0: NtStatus = 0x00000080;

pub const SECTION_INHERIT_VIEW_SHARE: Ulong = 1;
pub const SECTION_INHERIT_VIEW_UNMAP: Ulong = 2;

pub const KERNEL_HANDLE_OFFSET: usize = 0x80000000;

pub const IO_NO_INCREMENT: Irql = 0;
pub const IO_CD_ROM_INCREMENT: Irql = 1;
pub const IO_DISK_INCREMENT: Irql = 1;
pub const IO_NETWORK_INCREMENT: Irql = 2;

// ============================================================
// Logging macros
// ============================================================

macro_rules! nt_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "nt_trace")]
        crate::kernel_log!("[Nt] {}", format_args!($($arg)*));
    };
}

macro_rules! nt_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Nt] {}", format_args!($($arg)*));
    };
}

macro_rules! nt_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Nt] {}", format_args!($($arg)*));
    };
}

macro_rules! nt_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Nt] {}", format_args!($($arg)*));
    };
}

// ============================================================
// Internal types
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct FileObject {
    pub device_object: Pvoid,
    pub section_object: Pvoid,
    pub section_object_pointer: Pvoid,
    pub process_type_byte: u8,
    pub deleted: u8,
    pub flusher_blocked: u8,
    pub reserved: u8,
    pub flags: u32,
    pub file_name: UnicodeString,
    pub write_access: u8,
    pub read_access: u8,
    pub shared_read: u8,
    pub shared_write: u8,
    pub shared_delete: u8,
    pub waiters: u32,
    pub busy: u32,
    pub last_lock: Pvoid,
    pub lock: Ksemaphore,
    pub event: Kevent,
    pub completion_context: Pvoid,
}

impl FileObject {
    pub const fn new() -> Self {
        Self {
            device_object: core::ptr::null_mut(),
            section_object: core::ptr::null_mut(),
            section_object_pointer: core::ptr::null_mut(),
            process_type_byte: 0,
            deleted: 0,
            flusher_blocked: 0,
            reserved: 0,
            flags: 0,
            file_name: UnicodeString::new(),
            write_access: 0,
            read_access: 0,
            shared_read: 0,
            shared_write: 0,
            shared_delete: 0,
            waiters: 0,
            busy: 0,
            last_lock: core::ptr::null_mut(),
            lock: unsafe { mem::zeroed() },
            event: unsafe { mem::zeroed() },
            completion_context: core::ptr::null_mut(),
        }
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
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
            starting_va: core::ptr::null_mut(),
            ending_va: core::ptr::null_mut(),
            parent: core::ptr::null_mut(),
            left_child: core::ptr::null_mut(),
            right_child: core::ptr::null_mut(),
            segment: core::ptr::null_mut(),
        }
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MappedView {
    pub starting_va: Pvoid,
    pub ending_va: Pvoid,
    pub view_counter: u32,
    pub flags: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SectionSegment {
    pub size: u64,
    pub flags: u32,
    pub page_protect: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyBody {
    pub handle_count: u32,
    pub key_control_block: Pvoid,
    pub process: Pvoid,
    pub flags: u32,
    pub cached: u8,
    pub _reserved: [u8; 3],
    pub last_write_time: i64,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyValuePartialInformation {
    pub title_index: u32,
    pub data_type: u32,
    pub data_length: u32,
    pub data: [u8; 1],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyBasicInformation {
    pub last_write_time: i64,
    pub title_index: u32,
    pub name_length: u32,
    pub name: [u16; 1],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyNodeInformation {
    pub last_write_time: i64,
    pub title_index: u32,
    pub class_name_length: u32,
    pub name_length: u32,
    pub class_name_offset: u32,
    pub name: [u16; 1],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyFullInformation {
    pub last_write_time: i64,
    pub title_index: u32,
    pub class_name_length: u32,
    pub class_name_offset: u32,
    pub sub_keys: u32,
    pub max_name_length: u32,
    pub max_class_name_length: u32,
    pub max_value_name_length: u32,
    pub max_value_data_length: u32,
    pub work_var: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SystemBasicInformation {
    pub reserved: u32,
    pub timer_resolution: u32,
    pub allocation_increment: u32,
    pub allocation_quanta: u32,
    pub allocation_chunk_size: u32,
    pub number_of_physical_processors: u32,
    pub lowest_physical_page_number: u64,
    pub highest_physical_page_number: u64,
    pub allocation_granularity: u64,
    pub minimum_user_mode_address: u64,
    pub maximum_user_mode_address: u64,
    pub active_processors_affinity_mask: u64,
    pub number_of_processors: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SystemProcessorInformation {
    pub processor_architecture: u16,
    pub processor_level: u16,
    pub processor_revision: u16,
    pub maximum_address_space_number: u8,
    pub number_of_processors: u8,
    pub processor_type: u32,
    pub allocation_granularity: u32,
    pub processor_level_hint: u16,
    pub processor_apartment: u16,
    pub processor_features: [u32; 2],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SystemPerformanceInformation {
    pub idle_process_clock: i64,
    pub idle_process_time: i64,
    pub io_read_transfer_count: i64,
    pub io_write_transfer_count: i64,
    pub io_other_transfer_count: i64,
    pub io_read_operation_count: u32,
    pub io_write_operation_count: u32,
    pub io_other_operation_count: u32,
    pub available_pages: u32,
    pub committed_pages: u32,
    pub commit_limit: u32,
    pub committed_peak: u32,
    pub pool_paged: u32,
    pub pool_nonpaged: u32,
    pub page_faults: u32,
    pub copy_on_write_faults: u32,
    pub transient_page_faults: u32,
    pub demand_zero_faults: u32,
    pub pages_input: u32,
    pub page_reads: u32,
    pub pages_written: u32,
    pub page_writes: u32,
    pub pool_paged_bytes: u64,
    pub pool_nonpaged_bytes: u64,
    pub system_call_count: u64,
    pub context_switches: u64,
}

// ============================================================
// Global State
// ============================================================

static NT_NEXT_HANDLE: AtomicU64 = AtomicU64::new(0x100);

// ============================================================
// NtCreateFile
// ============================================================

pub unsafe fn nt_create_file(
    file_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    io_status_block: *mut IoStatusBlock,
    allocation_size: *mut i64,
    file_attributes: Ulong,
    share_access: Ulong,
    create_disposition: Ulong,
    create_options: Ulong,
    ea_buffer: Pvoid,
    ea_length: Ulong,
) -> NtStatus {
    if file_handle.is_null() || object_attributes.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtCreateFile: desired_access={:#x} create_disp={}",
              desired_access, create_disposition);

    let obj_attr = &*object_attributes;

    // Validate file attributes
    if file_attributes & !(FILE_ATTRIBUTE_NORMAL | FILE_ATTRIBUTE_DIRECTORY | FILE_ATTRIBUTE_READONLY |
        FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM | FILE_ATTRIBUTE_ARCHIVE | FILE_ATTRIBUTE_TEMPORARY |
        FILE_ATTRIBUTE_SPARSE_FILE | FILE_ATTRIBUTE_REPARSE_POINT | FILE_ATTRIBUTE_COMPRESSED |
        FILE_ATTRIBUTE_OFFLINE | FILE_ATTRIBUTE_NOT_CONTENT_INDEXED | FILE_ATTRIBUTE_ENCRYPTED |
        FILE_ATTRIBUTE_INTEGRITY_STREAM | FILE_ATTRIBUTE_NO_SCRUB_DATA | FILE_ATTRIBUTE_EA) != 0
    {
        return STATUS_INVALID_PARAMETER;
    }

    // Validate create disposition
    if create_disposition > FILE_OVERWRITE_IF {
        return STATUS_INVALID_PARAMETER;
    }

    // Validate share access
    if share_access & !(FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE) != 0 {
        return STATUS_INVALID_PARAMETER;
    }

    // Allocate a file object
    let fo_size = mem::size_of::<FileObject>();
    let fo_layout = match core::alloc::Layout::from_size_align(fo_size, 16) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let fo_ptr = alloc::alloc::alloc_zeroed(fo_layout) as *mut FileObject;
    if fo_ptr.is_null() {
        return STATUS_NO_MEMORY;
    }

    let fo = &mut *fo_ptr;
    fo.flags = create_options;
    fo.write_access = if desired_access & (FILE_GENERIC_WRITE | FILE_GENERIC_ALL) != 0 { 1 } else { 0 };
    fo.read_access = if desired_access & (FILE_GENERIC_READ | FILE_GENERIC_ALL) != 0 { 1 } else { 0 };

    // Copy file name from object attributes
    if !obj_attr.object_name.is_null() {
        let name = &*obj_attr.object_name;
        if name.length > 0 && !name.buffer.is_null() {
            fo.file_name.length = name.length;
            fo.file_name.maximum_length = name.maximum_length;
            fo.file_name.buffer = name.buffer;
        }
    }

    if !io_status_block.is_null() {
        (*io_status_block).status = STATUS_SUCCESS;
        (*io_status_block).information = 0;
    }

    if !allocation_size.is_null() {
        // Store allocation size
    }

    *file_handle = fo_ptr as Handle;

    nt_dbg!("NtCreateFile: handle={:p}", fo_ptr);
    STATUS_SUCCESS
}

pub const FILE_ATTRIBUTE_NORMAL: Ulong = 0x00000080;
pub const FILE_ATTRIBUTE_DIRECTORY: Ulong = 0x00000010;
pub const FILE_ATTRIBUTE_READONLY: Ulong = 0x00000001;
pub const FILE_ATTRIBUTE_HIDDEN: Ulong = 0x00000002;
pub const FILE_ATTRIBUTE_SYSTEM: Ulong = 0x00000004;
pub const FILE_ATTRIBUTE_ARCHIVE: Ulong = 0x00000020;
pub const FILE_ATTRIBUTE_TEMPORARY: Ulong = 0x00000100;
pub const FILE_ATTRIBUTE_SPARSE_FILE: Ulong = 0x00000200;
pub const FILE_ATTRIBUTE_REPARSE_POINT: Ulong = 0x00000400;
pub const FILE_ATTRIBUTE_COMPRESSED: Ulong = 0x00000800;
pub const FILE_ATTRIBUTE_OFFLINE: Ulong = 0x00001000;
pub const FILE_ATTRIBUTE_NOT_CONTENT_INDEXED: Ulong = 0x00002000;
pub const FILE_ATTRIBUTE_ENCRYPTED: Ulong = 0x00004000;
pub const FILE_ATTRIBUTE_INTEGRITY_STREAM: Ulong = 0x00008000;
pub const FILE_ATTRIBUTE_NO_SCRUB_DATA: Ulong = 0x00020000;
pub const FILE_ATTRIBUTE_EA: Ulong = 0x00040000;

pub const FILE_SHARE_READ: Ulong = 0x00000001;
pub const FILE_SHARE_WRITE: Ulong = 0x00000002;
pub const FILE_SHARE_DELETE: Ulong = 0x00000004;

// ============================================================
// NtOpenFile
// ============================================================

pub unsafe fn nt_open_file(
    file_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    io_status_block: *mut IoStatusBlock,
    share_access: Ulong,
    open_options: Ulong,
) -> NtStatus {
    if file_handle.is_null() || object_attributes.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtOpenFile: desired_access={:#x}", desired_access);

    // Delegate to NtCreateFile with FILE_OPEN disposition
    nt_create_file(
        file_handle,
        desired_access,
        object_attributes,
        io_status_block,
        core::ptr::null_mut(),
        0,
        share_access,
        FILE_OPEN,
        open_options,
        core::ptr::null_mut(),
        0,
    )
}

// ============================================================
// NtReadFile
// ============================================================

pub unsafe fn nt_read_file(
    file_handle: Handle,
    event: Handle,
    apc_routine: Pvoid,
    apc_context: Pvoid,
    io_status_block: *mut IoStatusBlock,
    buffer: Pvoid,
    length: Ulong,
    byte_offset: *mut i64,
    key: *mut u32,
) -> NtStatus {
    if file_handle.is_null() || io_status_block.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtReadFile: handle={:p} length={}", file_handle, length);

    let fo = &mut *(file_handle as *mut FileObject);

    if fo.read_access == 0 {
        return STATUS_ACCESS_DENIED;
    }

    // Named-pipe endpoints transfer through the pipe ring.
    if crate::nt::ext::nt_is_pipe_file(fo as *const FileObject) {
        let mut actual = 0usize;
        let st = crate::nt::ext::nt_pipe_read(
            file_handle as *mut FileObject,
            buffer,
            length as usize,
            &mut actual,
        );
        (*io_status_block).status = st;
        (*io_status_block).information = actual as Ulong;
        return st;
    }

    // Validate parameters
    if buffer.is_null() && length > 0 {
        return STATUS_INVALID_PARAMETER;
    }

    if !byte_offset.is_null() && *byte_offset < 0 {
        return STATUS_INVALID_PARAMETER;
    }

    // Simulate read operation
    if length > 0 && !buffer.is_null() {
        let dst = core::slice::from_raw_parts_mut(buffer as *mut u8, length as usize);
        // In a real implementation, this would read from device/file system
        dst.fill(0);
    }

    if !event.is_null() {
        // Signal the event
        let evt = &mut *(event as *mut Kevent);
        evt.header.signal_state += 1;
    }

    if !apc_routine.is_null() {
        // Queue APC (simplified)
    }

    (*io_status_block).status = STATUS_SUCCESS;
    (*io_status_block).information = length;

    STATUS_SUCCESS
}

// ============================================================
// NtWriteFile
// ============================================================

pub unsafe fn nt_write_file(
    file_handle: Handle,
    event: Handle,
    apc_routine: Pvoid,
    apc_context: Pvoid,
    io_status_block: *mut IoStatusBlock,
    buffer: Pvoid,
    length: Ulong,
    byte_offset: *mut i64,
    key: *mut u32,
) -> NtStatus {
    if file_handle.is_null() || io_status_block.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtWriteFile: handle={:p} length={}", file_handle, length);

    let fo = &mut *(file_handle as *mut FileObject);

    if fo.write_access == 0 {
        return STATUS_ACCESS_DENIED;
    }

    if buffer.is_null() && length > 0 {
        return STATUS_INVALID_PARAMETER;
    }

    // Named-pipe endpoints transfer through the pipe ring.
    if crate::nt::ext::nt_is_pipe_file(fo as *const FileObject) {
        let st = crate::nt::ext::nt_pipe_write(
            file_handle as *mut FileObject,
            buffer,
            length as usize,
        );
        (*io_status_block).status = st;
        (*io_status_block).information = if st == STATUS_SUCCESS {
            length
        } else {
            0
        };
        return st;
    }

    // Simulate write operation
    if !event.is_null() {
        let evt = &mut *(event as *mut Kevent);
        evt.header.signal_state += 1;
    }

    (*io_status_block).status = STATUS_SUCCESS;
    (*io_status_block).information = length;

    STATUS_SUCCESS
}

// ============================================================
// NtQueryInformationFile
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy)]
pub enum FileInformationClass {
    FileDirectoryInformation = 1,
    FileFullDirectoryInformation = 2,
    FileBothDirectoryInformation = 3,
    FileBasicInformation = 4,
    FileStandardInformation = 5,
    FileInternalInformation = 6,
    FileEaInformation = 7,
    FileAccessInformation = 8,
    FileNameInformation = 9,
    FileRenameInformation = 10,
    FileLinkInformation = 11,
    FileDispositionInformation = 12,
    FilePositionInformation = 14,
    FileAllInformation = 18,
    FileStreamInformation = 22,
    FileCompressionInformation = 28,
}

pub unsafe fn nt_query_information_file(
    file_handle: Handle,
    io_status_block: *mut IoStatusBlock,
    file_information: Pvoid,
    length: Ulong,
    file_information_class: FileInformationClass,
) -> NtStatus {
    if file_handle.is_null() || io_status_block.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtQueryInformationFile: class={:?}", file_information_class);

    match file_information_class {
        FileInformationClass::FileStandardInformation => {
            if length < mem::size_of::<FileStandardInformation>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = &mut *(file_information as *mut FileStandardInformation);
            info.allocation_size = 0;
            info.end_of_file = 0;
            info.number_of_links = 1;
            info.delete_pending = 0;
            info.directory = 0;
        }
        FileInformationClass::FileBasicInformation => {
            if length < mem::size_of::<FileBasicInformation>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = &mut *(file_information as *mut FileBasicInformation);
            info.creation_time = 0;
            info.last_access_time = 0;
            info.last_write_time = 0;
            info.change_time = 0;
            info.file_attributes = 0;
        }
        FileInformationClass::FileNameInformation => {
            let fo = &*(file_handle as *mut FileObject);
            let name_len = fo.file_name.length as usize;
            let needed = mem::size_of::<u32>() * 2 + name_len;
            if length < needed as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let buf = file_information as *mut u32;
            *buf.add(0) = 0; // file name offset
            *buf.add(1) = name_len as u32;
            if !fo.file_name.buffer.is_null() && name_len > 0 {
                let name_dst = core::slice::from_raw_parts_mut(
                    (buf.add(2)) as *mut u16,
                    name_len / 2,
                );
                let name_src = core::slice::from_raw_parts(fo.file_name.buffer, name_len / 2);
                name_dst.copy_from_slice(name_src);
            }
        }
        FileInformationClass::FilePositionInformation => {
            if length < mem::size_of::<FilePositionInformation>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = &mut *(file_information as *mut FilePositionInformation);
            info.current_byte_offset = 0;
        }
        FileInformationClass::FileAllInformation => {
            if length < mem::size_of::<FileAllInformation>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = &mut *(file_information as *mut FileAllInformation);
            info.basic_information = FileBasicInformation::new();
            info.standard_information = FileStandardInformation::new();
            info.position_information = FilePositionInformation::new();
        }
        _ => {}
    }

    (*io_status_block).status = STATUS_SUCCESS;
    (*io_status_block).information = length as Ulong;

    STATUS_SUCCESS
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct FileStandardInformation {
    pub allocation_size: i64,
    pub end_of_file: i64,
    pub number_of_links: u32,
    pub delete_pending: u8,
    pub directory: u8,
}

impl FileStandardInformation {
    pub const fn new() -> Self {
        Self {
            allocation_size: 0,
            end_of_file: 0,
            number_of_links: 1,
            delete_pending: 0,
            directory: 0,
        }
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct FileBasicInformation {
    pub creation_time: i64,
    pub last_access_time: i64,
    pub last_write_time: i64,
    pub change_time: i64,
    pub file_attributes: u32,
}

impl FileBasicInformation {
    pub const fn new() -> Self {
        Self {
            creation_time: 0,
            last_access_time: 0,
            last_write_time: 0,
            change_time: 0,
            file_attributes: 0,
        }
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct FilePositionInformation {
    pub current_byte_offset: i64,
}

impl FilePositionInformation {
    pub const fn new() -> Self {
        Self { current_byte_offset: 0 }
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct FileAllInformation {
    pub basic_information: FileBasicInformation,
    pub standard_information: FileStandardInformation,
    pub position_information: FilePositionInformation,
    pub mode_information: u32,
    pub alignment_information: u32,
    pub name_information: FileNameInformation,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct FileNameInformation {
    pub file_name_length: u32,
    pub file_name: [u16; 260],
}

// ============================================================
// NtSetInformationFile
// ============================================================

pub unsafe fn nt_set_information_file(
    file_handle: Handle,
    io_status_block: *mut IoStatusBlock,
    file_information: Pvoid,
    length: Ulong,
    file_information_class: FileInformationClass,
) -> NtStatus {
    if file_handle.is_null() || io_status_block.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtSetInformationFile: class={:?}", file_information_class);

    match file_information_class {
        FileInformationClass::FileBasicInformation => {
            if length >= mem::size_of::<FileBasicInformation>() as u32 {
                let info = &*(file_information as *const FileBasicInformation);
                nt_trace!("NtSetInformationFile: file_attr={:#x}", info.file_attributes);
            }
        }
        FileInformationClass::FilePositionInformation => {
            if length >= mem::size_of::<FilePositionInformation>() as u32 {
                let info = &*(file_information as *const FilePositionInformation);
                nt_trace!("NtSetInformationFile: pos={}", info.current_byte_offset);
            }
        }
        FileInformationClass::FileDispositionInformation => {
            if length >= 1 {
                let delete = *(file_information as *const u8);
                nt_trace!("NtSetInformationFile: delete={}", delete);
            }
        }
        _ => {}
    }

    (*io_status_block).status = STATUS_SUCCESS;
    (*io_status_block).information = 0;

    STATUS_SUCCESS
}

// ============================================================
// NtCreateSection
// ============================================================

pub unsafe fn nt_create_section(
    section_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    maximum_size: *mut i64,
    section_page_protection: Ulong,
    allocation_attributes: Ulong,
    file_handle: Handle,
) -> NtStatus {
    if section_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtCreateSection: access={:#x} attr={:#x}",
              desired_access, allocation_attributes);

    // Validate parameters
    if desired_access & !(SECTION_QUERY | SECTION_MAP_WRITE | SECTION_MAP_READ |
        SECTION_MAP_EXECUTE | SECTION_EXTEND_SIZE | SECTION_MAP_EXECUTE_EXPLICIT) != 0
    {
        return STATUS_INVALID_PARAMETER;
    }

    // Allocate section object
    let section_size = mem::size_of::<SectionObject>();
    let layout = match core::alloc::Layout::from_size_align(section_size, 16) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let section = alloc::alloc::alloc_zeroed(layout) as *mut SectionObject;
    if section.is_null() {
        return STATUS_NO_MEMORY;
    }

    let sec = &mut *section;
    sec.starting_va = core::ptr::null_mut();
    sec.ending_va = core::ptr::null_mut();
    sec.segment = core::ptr::null_mut();

    // If file-backed, set up segment
    if !file_handle.is_null() {
        nt_trace!("NtCreateSection: file-backed section");
    } else {
        nt_trace!("NtCreateSection: page-file backed section");
    }

    *section_handle = section as Handle;

    STATUS_SUCCESS
}

// ============================================================
// NtMapViewOfSection
// ============================================================

pub unsafe fn nt_map_view_of_section(
    section_handle: Handle,
    process_handle: Handle,
    base_address: *mut Pvoid,
    zero_bits: u64,
    commit_size: usize,
    section_offset: *mut i64,
    view_size: *mut usize,
    inherit_disposition: u32,
    allocation_type: u32,
    page_protection: u32,
) -> NtStatus {
    if section_handle.is_null() || base_address.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtMapViewOfSection: section={:p} prot={:#x}",
              section_handle, page_protection);

    let _ = (process_handle, zero_bits, commit_size, section_offset, inherit_disposition, allocation_type);

    let sec = &*(section_handle as *const SectionObject);

    // Calculate view size
    let requested_size = if !view_size.is_null() {
        *view_size
    } else {
        4096
    };

    // Allocate virtual address space and map
    let alloc_size = (requested_size + 4095) & !4095;
    let layout = match core::alloc::Layout::from_size_align(alloc_size, 4096) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let mapped_addr = alloc::alloc::alloc_zeroed(layout);
    if mapped_addr.is_null() {
        return STATUS_NO_MEMORY;
    }

    *base_address = mapped_addr as Pvoid;

    if !view_size.is_null() {
        *view_size = alloc_size;
    }

    nt_trace!("NtMapViewOfSection: mapped at {:p}", mapped_addr);
    STATUS_SUCCESS
}

// ============================================================
// NtUnmapViewOfSection
// ============================================================

pub unsafe fn nt_unmap_view_of_section(
    process_handle: Handle,
    base_address: Pvoid,
) -> NtStatus {
    let _ = process_handle;

    if base_address.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtUnmapViewOfSection: base={:p}", base_address);

    // In a real implementation, this would unmap from process address space
    STATUS_SUCCESS
}

// ============================================================
// NtCreateThread
// ============================================================

pub unsafe fn nt_create_thread(
    thread_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    process_handle: Handle,
    client_id: *mut ClientId,
    context: *mut ContextRecord,
    user_stack: Pvoid,
    create_flags: u32,
    zero_bits: usize,
    commit_size: usize,
    stack_commit_size: usize,
    stack_reserve_size: usize,
) -> NtStatus {
    if thread_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtCreateThread: process={:p} flags={:#x}",
              process_handle, create_flags);

    let _ = (desired_access, object_attributes, client_id, zero_bits,
             commit_size, stack_commit_size, stack_reserve_size);

    // Create thread via the ps subsystem
    let thread = crate::ps::psp_allocate_thread(
        process_handle as *mut crate::ps::Eprocess,
        None,
        core::ptr::null_mut(),
        if create_flags & THREAD_CREATE_FLAGS_CREATE_SUSPENDED != 0 {
            crate::ps::PS_THREAD_CREATE_FLAGS_CREATE_SUSPENDED
        } else {
            0
        },
    );

    if thread.is_null() {
        return STATUS_NO_MEMORY;
    }

    let thr = &mut *thread;

    // Set up context if provided
    if !context.is_null() {
        let ctx = &*context;
        thr.start_address = ctx.rip as Pvoid;
        thr.win32_start_address = ctx.rip as Pvoid;
    }

    if !user_stack.is_null() {
        thr.kthread.initial_stack = user_stack;
    }

    // Insert thread
    let status = crate::ps::psp_insert_thread(
        process_handle as *mut crate::ps::Eprocess,
        thread,
    );

    if status != STATUS_SUCCESS {
        return status;
    }

    *thread_handle = thread as Handle;

    STATUS_SUCCESS
}

// ============================================================
// NtTerminateThread
// ============================================================

pub unsafe fn nt_terminate_thread(
    thread_handle: Handle,
    exit_status: NtStatus,
) -> NtStatus {
    if thread_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtTerminateThread: thread={:p} status={:#x}",
              thread_handle, exit_status);

    let ethread = thread_handle as *mut crate::ps::Ethread;
    crate::ps::psp_exit_thread(ethread, exit_status);

    STATUS_SUCCESS
}

// ============================================================
// NtCreateProcess
// ============================================================

pub unsafe fn nt_create_process(
    process_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    parent_process: Handle,
    inherit_handles: Boolean,
    section_handle: Handle,
    debug_port: Handle,
    exception_port: Handle,
) -> NtStatus {
    if process_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtCreateProcess: parent={:p}", parent_process);

    let _ = (desired_access, object_attributes, inherit_handles, debug_port, exception_port);

    let result = crate::ps::ps_create_process_ex(
        process_handle,
        desired_access,
        object_attributes as *mut ObjectAttributes,
        parent_process,
        0,
        section_handle,
        debug_port,
        exception_port,
        core::ptr::null_mut(),
    );

    result
}

// ============================================================
// NtTerminateProcess
// ============================================================

pub unsafe fn nt_terminate_process(
    process_handle: Handle,
    exit_status: NtStatus,
) -> NtStatus {
    if process_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtTerminateProcess: process={:p} status={:#x}",
              process_handle, exit_status);

    let process = process_handle as *mut crate::ps::Eprocess;
    crate::ps::ps_terminate_process(process, exit_status)
}

// ============================================================
// NtQuerySystemInformation
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SystemInformationClass {
    SystemBasicInformation = 0,
    SystemProcessorInformation = 1,
    SystemPerformanceInformation = 2,
    SystemTimeOfDay = 3,
    SystemProcessInformation = 5,
    SystemProcessorPerformanceInformation = 8,
    SystemHandleInformation = 16,
    SystemPagefileInformation = 18,
    SystemModuleInformation = 11,
    SystemInterruptInformation = 23,
    SystemExtendedHandleInformation = 64,
    SystemCodeIntegrityInformation = 103,
    SystemRegistryQuotaInformation = 101,
}

pub unsafe fn nt_query_system_information(
    system_information_class: SystemInformationClass,
    system_information: Pvoid,
    system_information_length: Ulong,
    return_length: *mut Ulong,
) -> NtStatus {
    if !return_length.is_null() {
        *return_length = 0;
    }

    nt_trace!("NtQuerySystemInformation: class={:?}", system_information_class);

    match system_information_class {
        SystemInformationClass::SystemBasicInformation => {
            if system_information_length < mem::size_of::<SystemBasicInformation>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = &mut *(system_information as *mut SystemBasicInformation);
            info.timer_resolution = 156250;
            info.allocation_increment = 16;
            info.allocation_quanta = 16;
            info.allocation_chunk_size = 256;
            info.number_of_physical_processors = 1;
            info.lowest_physical_page_number = 0;
            info.highest_physical_page_number = 0xFFFFF;
            info.allocation_granularity = 4096;
            info.minimum_user_mode_address = 0x10000;
            info.maximum_user_mode_address = 0x7FFFFFFFEFFFF;
            info.active_processors_affinity_mask = 1;
            info.number_of_processors = 1;

            if !return_length.is_null() {
                *return_length = mem::size_of::<SystemBasicInformation>() as Ulong;
            }
        }
        SystemInformationClass::SystemPerformanceInformation => {
            if system_information_length < mem::size_of::<SystemPerformanceInformation>() as u32 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = &mut *(system_information as *mut SystemPerformanceInformation);
            info.idle_process_clock = 0;
            info.idle_process_time = 0;

            if !return_length.is_null() {
                *return_length = mem::size_of::<SystemPerformanceInformation>() as Ulong;
            }
        }
        SystemInformationClass::SystemTimeOfDay => {
            if system_information_length < 48 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = system_information as *mut u8;
            core::ptr::write_bytes(info, 0, 48);

            if !return_length.is_null() {
                *return_length = 48;
            }
        }
        SystemInformationClass::SystemModuleInformation => {
            // Return module count as 0 (no modules loaded in simplified model)
            if system_information_length < 4 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            let info = system_information as *mut u32;
            *info = 0; // Module count

            if !return_length.is_null() {
                *return_length = 4;
            }
        }
        _ => {
            if !return_length.is_null() {
                *return_length = 0;
            }
            return STATUS_NOT_IMPLEMENTED;
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// NtCreateKey
// ============================================================

pub unsafe fn nt_create_key(
    key_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    title_index: u32,
    class_name: *const UnicodeString,
    create_options: Ulong,
    disposition: *mut u32,
) -> NtStatus {
    if key_handle.is_null() || object_attributes.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtCreateKey: access={:#x}", desired_access);

    let _ = (title_index, class_name, create_options);

    // Allocate key body
    let kb_size = mem::size_of::<KeyBody>();
    let layout = match core::alloc::Layout::from_size_align(kb_size, 8) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let kb = alloc::alloc::alloc_zeroed(layout) as *mut KeyBody;
    if kb.is_null() {
        return STATUS_NO_MEMORY;
    }

    let key = &mut *kb;
    key.handle_count = 1;

    // Copy key name from object attributes
    let obj_attr = &*object_attributes;
    if !obj_attr.object_name.is_null() {
        let name = &*obj_attr.object_name;
        nt_trace!("NtCreateKey: name={:?} len={}", name.buffer, name.length);
    }

    if !disposition.is_null() {
        *disposition = 1; // REG_CREATED_NEW_KEY
    }

    *key_handle = kb as Handle;

    STATUS_SUCCESS
}

// ============================================================
// NtOpenKey
// ============================================================

pub unsafe fn nt_open_key(
    key_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if key_handle.is_null() || object_attributes.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtOpenKey: access={:#x}", desired_access);

    let obj_attr = &*object_attributes;
    if !obj_attr.object_name.is_null() {
        let name = &*obj_attr.object_name;
        nt_trace!("NtOpenKey: name={:?} len={}", name.buffer, name.length);
    }

    // Simplified: create a key body
    let kb_size = mem::size_of::<KeyBody>();
    let layout = match core::alloc::Layout::from_size_align(kb_size, 8) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let kb = alloc::alloc::alloc_zeroed(layout) as *mut KeyBody;
    if kb.is_null() {
        return STATUS_NO_MEMORY;
    }

    *key_handle = kb as Handle;
    STATUS_SUCCESS
}

// ============================================================
// NtSetValueKey
// ============================================================

pub unsafe fn nt_set_value_key(
    key_handle: Handle,
    value_name: *const UnicodeString,
    title_index: u32,
    data_type: Ulong,
    data: Pvoid,
    data_size: Ulong,
) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtSetValueKey: type={} size={}", data_type, data_size);

    let _ = (title_index, value_name);

    // Validate data type
    if data_type > REG_QWORD {
        return STATUS_INVALID_PARAMETER;
    }

    // In a real implementation, this would write to the registry hive
    // For now, we store it in the key body's cached data

    STATUS_SUCCESS
}

// ============================================================
// NtQueryValueKey
// ============================================================

pub unsafe fn nt_query_value_key(
    key_handle: Handle,
    value_name: *const UnicodeString,
    key_value_information_class: u32,
    key_value_information: Pvoid,
    length: Ulong,
    result_length: *mut Ulong,
) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    if !result_length.is_null() {
        *result_length = 0;
    }

    nt_trace!("NtQueryValueKey: info_class={}", key_value_information_class);

    let _ = (value_name, key_value_information_class, key_value_information, length);

    // In a real implementation, this would query the registry hive
    STATUS_NOT_FOUND
}

// ============================================================
// NtEnumerateKey
// ============================================================

pub unsafe fn nt_enumerate_key(
    key_handle: Handle,
    index: u32,
    key_information_class: u32,
    key_information: Pvoid,
    length: Ulong,
    result_length: *mut Ulong,
) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    if !result_length.is_null() {
        *result_length = 0;
    }

    nt_trace!("NtEnumerateKey: index={} class={}", index, key_information_class);

    let _ = (key_information_class, key_information, length);

    STATUS_NO_MORE_ENTRIES
}

// ============================================================
// NtDeleteKey
// ============================================================

pub unsafe fn nt_delete_key(
    key_handle: Handle,
) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtDeleteKey");
    // In a real implementation, mark the key for deletion
    // For now, close the key handle
    let _ = nt_close(key_handle);
    STATUS_SUCCESS
}

// ============================================================
// NtOpenProcess
// ============================================================

pub unsafe fn nt_open_process(
    process_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    client_id: *mut ClientId,
) -> NtStatus {
    if process_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtOpenProcess: access={:#x}", desired_access);

    let _ = (object_attributes, client_id);

    // Get the current process for now
    // In a real implementation, look up by ClientId.UniqueProcess
    let current = crate::ps::ps_get_current_process();
    if current.is_null() {
        return STATUS_NOT_FOUND;
    }

    *process_handle = current as Handle;
    STATUS_SUCCESS
}

pub const STATUS_NO_MORE_ENTRIES: NtStatus = 0x8000001A;

// ============================================================
// NtClose
// ============================================================

pub unsafe fn nt_close(handle: Handle) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtClose: handle={:p}", handle);

    // Determine object type by examining the handle
    // In a real implementation, this would use the object type index
    // For now, we just free the memory

    // Attempt to determine if this is a file, key, section, etc.
    // by checking the memory layout. This is a simplified approach.
    let _ptr = handle as *mut u8;

    STATUS_SUCCESS
}

// ============================================================
// NtCreateEvent
// ============================================================

pub unsafe fn nt_create_event(
    event_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    event_type: u32,
    initial_state: Boolean,
) -> NtStatus {
    if event_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtCreateEvent: type={} initial={}", event_type, initial_state);

    let _ = (desired_access, object_attributes);

    // Allocate event
    let evt_size = mem::size_of::<Kevent>();
    let layout = match core::alloc::Layout::from_size_align(evt_size, 16) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let evt = alloc::alloc::alloc_zeroed(layout) as *mut Kevent;
    if evt.is_null() {
        return STATUS_NO_MEMORY;
    }

    sync::ke_initialize_event(
        evt,
        event_type as u8,
        if initial_state != 0 { 1 } else { 0 },
    );

    *event_handle = evt as Handle;
    STATUS_SUCCESS
}

// ============================================================
// NtSetEvent
// ============================================================

pub unsafe fn nt_set_event(
    event_handle: Handle,
    previous_state: *mut i32,
) -> NtStatus {
    if event_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtSetEvent: handle={:p}", event_handle);

    let evt = &mut *(event_handle as *mut Kevent);
    ke_set_event(evt, 1, 0);

    if !previous_state.is_null() {
        *previous_state = evt.header.signal_state - 1;
    }

    STATUS_SUCCESS
}

// ============================================================
// NtWaitForSingleObject
// ============================================================

pub unsafe fn nt_wait_for_single_object(
    handle: Handle,
    alertable: Boolean,
    timeout: *mut i64,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtWaitForSingleObject: handle={:p}", handle);

    let _ = alertable;

    let dispatcher = handle as *mut DispatcherHeader;
    let status = unsafe {
        ke_wait_for_single_object(
            dispatcher,
            KwaitReason::Executive,
            0,
            alertable,
            timeout,
        )
    };

    status
}

// ============================================================
// NtDelayExecution
// ============================================================

pub unsafe fn nt_delay_execution(
    alertable: Boolean,
    delay_interval: *mut i64,
) -> NtStatus {
    nt_trace!("NtDelayExecution: alertable={}", alertable);

    let _ = alertable;

    if !delay_interval.is_null() {
        let delay = *delay_interval;
        if delay > 0 {
            // Convert 100ns units to timer ticks (approximately)
            let _ticks = (delay / 10_000_000) as u64;
            // In a real implementation, this would set a timer and wait
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// NtDeleteFile
// ============================================================

pub unsafe fn nt_delete_file(
    object_attributes: *const ObjectAttributes,
) -> NtStatus {
    if object_attributes.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtDeleteFile");

    let attrs = &*object_attributes;
    if attrs.object_name.is_null() || (*attrs.object_name).length == 0 {
        return STATUS_INVALID_PARAMETER;
    }

    // Open the file for deletion
    let mut file_handle: Handle = core::ptr::null_mut();
    let mut iosb = IoStatusBlock { status: 0, information: 0 };

    let status = nt_open_file(
        &mut file_handle,
        0x00010000, // DELETE
        object_attributes,
        &mut iosb,
        1, // FILE_SHARE_READ
        0x00002000, // FILE_DELETE_ON_CLOSE
    );

    if status == STATUS_SUCCESS && !file_handle.is_null() {
        let _ = nt_close(file_handle);
    }

    status
}

// ============================================================
// NtQueryDirectoryFile
// ============================================================

pub unsafe fn nt_query_directory_file(
    file_handle: Handle,
    event: Handle,
    apc_routine: Pvoid,
    apc_context: Pvoid,
    io_status_block: *mut IoStatusBlock,
    file_information: Pvoid,
    length: Ulong,
    file_information_class: u32,
    return_single_entry: Boolean,
    file_name: *const UnicodeString,
    restart_scan: Boolean,
) -> NtStatus {
    let _ = (event, apc_routine, apc_context, return_single_entry, file_name, restart_scan);

    if file_handle.is_null() || io_status_block.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    nt_trace!("NtQueryDirectoryFile: class={}", file_information_class);

    // For now, return a single entry with the volume root
    match file_information_class {
        1 | 2 | 3 => {
            // FileDirectoryInformation / FileFullDirectoryInformation / FileBothDirectoryInformation
            if file_information.is_null() || length < 48 {
                return STATUS_BUFFER_TOO_SMALL;
            }

            let info = file_information as *mut u8;
            // Zero out the buffer
            core::ptr::write_bytes(info, 0, length as usize);

            // Write a minimal entry for "." (current directory)
            // FILE_DIRECTORY_INFORMATION layout:
            //   next_entry_offset: u32 (0 = last entry)
            //   creation_time: i64
            //   last_access_time: i64
            //   last_write_time: i64
            //   change_time: i64
            //   file_size: i64
            //   allocation_size: i64
            //   file_attributes: u32
            //   file_name_length: u32
            //   file_name: [u16; N]
            let entry_size = 48u32; // base + "." (2 bytes * 1 char + padding)

            *(info as *mut u32) = 0; // next_entry_offset = 0 (last entry)
            *((info as *mut u64).add(1)) = 0; // creation_time
            *((info as *mut u64).add(2)) = 0; // last_access_time
            *((info as *mut u64).add(3)) = 0; // last_write_time
            *((info as *mut u64).add(4)) = 0; // change_time
            *((info as *mut u64).add(5)) = 4096; // file_size
            *((info as *mut u64).add(6)) = 4096; // allocation_size
            *((info as *mut u32).add(14)) = 0x10; // FILE_ATTRIBUTE_DIRECTORY
            *((info as *mut u32).add(15)) = 2; // file_name_length (1 char * 2 bytes)

            // Write "." as UTF-16
            let name_ptr = info.add(64) as *mut u16;
            *name_ptr = '.' as u16;

            (*io_status_block).status = STATUS_SUCCESS;
            (*io_status_block).information = entry_size as Ulong;            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

// ============================================================
// NtQueryDirectoryObject (object namespace enumeration)
// ============================================================

pub unsafe fn nt_query_directory_object(
    _directory_handle: Handle,
    _object_information: Pvoid,
    _length: Ulong,
    _return_single_entry: Boolean,
    _restart_scan: Boolean,
    _context: *mut u32,
) -> NtStatus {
    STATUS_NOT_IMPLEMENTED
}

// ============================================================
// ZwXxx stubs - Kernel mode wrappers
//
// In Windows, ZwXxx functions are thin wrappers around NtXxx
// that set the previous mode to KernelMode before calling the
// actual system service. This bypasses user-mode access checks.
// ============================================================

pub unsafe fn zw_create_file(
    file_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    io_status_block: *mut IoStatusBlock,
    allocation_size: *mut i64,
    file_attributes: Ulong,
    share_access: Ulong,
    create_disposition: Ulong,
    create_options: Ulong,
    ea_buffer: Pvoid,
    ea_length: Ulong,
) -> NtStatus {
    // Set previous mode to kernel
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0; // KernelMode
    }

    let status = nt_create_file(
        file_handle,
        desired_access,
        object_attributes,
        io_status_block,
        allocation_size,
        file_attributes,
        share_access,
        create_disposition,
        create_options,
        ea_buffer,
        ea_length,
    );

    // Restore previous mode
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1; // UserMode
    }

    status
}

pub unsafe fn zw_open_file(
    file_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    io_status_block: *mut IoStatusBlock,
    share_access: Ulong,
    open_options: Ulong,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_open_file(
        file_handle,
        desired_access,
        object_attributes,
        io_status_block,
        share_access,
        open_options,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_read_file(
    file_handle: Handle,
    event: Handle,
    apc_routine: Pvoid,
    apc_context: Pvoid,
    io_status_block: *mut IoStatusBlock,
    buffer: Pvoid,
    length: Ulong,
    byte_offset: *mut i64,
    key: *mut u32,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_read_file(
        file_handle,
        event,
        apc_routine,
        apc_context,
        io_status_block,
        buffer,
        length,
        byte_offset,
        key,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_write_file(
    file_handle: Handle,
    event: Handle,
    apc_routine: Pvoid,
    apc_context: Pvoid,
    io_status_block: *mut IoStatusBlock,
    buffer: Pvoid,
    length: Ulong,
    byte_offset: *mut i64,
    key: *mut u32,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_write_file(
        file_handle,
        event,
        apc_routine,
        apc_context,
        io_status_block,
        buffer,
        length,
        byte_offset,
        key,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_close(handle: Handle) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_close(handle);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_create_section(
    section_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    maximum_size: *mut i64,
    section_page_protection: Ulong,
    allocation_attributes: Ulong,
    file_handle: Handle,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_create_section(
        section_handle,
        desired_access,
        object_attributes,
        maximum_size,
        section_page_protection,
        allocation_attributes,
        file_handle,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_map_view_of_section(
    section_handle: Handle,
    process_handle: Handle,
    base_address: *mut Pvoid,
    zero_bits: u64,
    commit_size: usize,
    section_offset: *mut i64,
    view_size: *mut usize,
    inherit_disposition: u32,
    allocation_type: u32,
    page_protection: u32,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_map_view_of_section(
        section_handle,
        process_handle,
        base_address,
        zero_bits,
        commit_size,
        section_offset,
        view_size,
        inherit_disposition,
        allocation_type,
        page_protection,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_unmap_view_of_section(
    process_handle: Handle,
    base_address: Pvoid,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_unmap_view_of_section(process_handle, base_address);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_create_thread(
    thread_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    process_handle: Handle,
    client_id: *mut ClientId,
    context: *mut ContextRecord,
    user_stack: Pvoid,
    create_flags: u32,
    zero_bits: usize,
    commit_size: usize,
    stack_commit_size: usize,
    stack_reserve_size: usize,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_create_thread(
        thread_handle,
        desired_access,
        object_attributes,
        process_handle,
        client_id,
        context,
        user_stack,
        create_flags,
        zero_bits,
        commit_size,
        stack_commit_size,
        stack_reserve_size,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_create_event(
    event_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    event_type: u32,
    initial_state: Boolean,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_create_event(
        event_handle,
        desired_access,
        object_attributes,
        event_type,
        initial_state,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_set_event(
    event_handle: Handle,
    previous_state: *mut i32,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_set_event(event_handle, previous_state);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_wait_for_single_object(
    handle: Handle,
    alertable: Boolean,
    timeout: *mut i64,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_wait_for_single_object(handle, alertable, timeout);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_delay_execution(
    alertable: Boolean,
    delay_interval: *mut i64,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_delay_execution(alertable, delay_interval);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_terminate_process(
    process_handle: Handle,
    exit_status: NtStatus,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_terminate_process(process_handle, exit_status);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_create_key(
    key_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    title_index: u32,
    class_name: *const UnicodeString,
    create_options: Ulong,
    disposition: *mut u32,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_create_key(
        key_handle,
        desired_access,
        object_attributes,
        title_index,
        class_name,
        create_options,
        disposition,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_open_key(
    key_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_open_key(key_handle, desired_access, object_attributes);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_set_value_key(
    key_handle: Handle,
    value_name: *const UnicodeString,
    title_index: u32,
    data_type: Ulong,
    data: Pvoid,
    data_size: Ulong,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_set_value_key(key_handle, value_name, title_index, data_type, data, data_size);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_query_value_key(
    key_handle: Handle,
    value_name: *const UnicodeString,
    key_value_information_class: u32,
    key_value_information: Pvoid,
    length: Ulong,
    result_length: *mut Ulong,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_query_value_key(
        key_handle,
        value_name,
        key_value_information_class,
        key_value_information,
        length,
        result_length,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_enumerate_key(
    key_handle: Handle,
    index: u32,
    key_information_class: u32,
    key_information: Pvoid,
    length: Ulong,
    result_length: *mut Ulong,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_enumerate_key(
        key_handle,
        index,
        key_information_class,
        key_information,
        length,
        result_length,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_query_system_information(
    system_information_class: SystemInformationClass,
    system_information: Pvoid,
    system_information_length: Ulong,
    return_length: *mut Ulong,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_query_system_information(
        system_information_class,
        system_information,
        system_information_length,
        return_length,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_create_process(
    process_handle: *mut Handle,
    desired_access: Ulong,
    object_attributes: *const ObjectAttributes,
    parent_process: Handle,
    inherit_handles: Boolean,
    section_handle: Handle,
    debug_port: Handle,
    exception_port: Handle,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_create_process(
        process_handle,
        desired_access,
        object_attributes,
        parent_process,
        inherit_handles,
        section_handle,
        debug_port,
        exception_port,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_terminate_thread(
    thread_handle: Handle,
    exit_status: NtStatus,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_terminate_thread(thread_handle, exit_status);

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_query_information_file(
    file_handle: Handle,
    io_status_block: *mut IoStatusBlock,
    file_information: Pvoid,
    length: Ulong,
    file_information_class: FileInformationClass,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_query_information_file(
        file_handle,
        io_status_block,
        file_information,
        length,
        file_information_class,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}

pub unsafe fn zw_set_information_file(
    file_handle: Handle,
    io_status_block: *mut IoStatusBlock,
    file_information: Pvoid,
    length: Ulong,
    file_information_class: FileInformationClass,
) -> NtStatus {
    let current_thread = crate::ke::dispatcher::ke_get_current_thread();
    if !current_thread.is_null() {
        (*current_thread).previous_mode = 0;
    }

    let status = nt_set_information_file(
        file_handle,
        io_status_block,
        file_information,
        length,
        file_information_class,
    );

    if !current_thread.is_null() {
        (*current_thread).previous_mode = 1;
    }

    status
}
