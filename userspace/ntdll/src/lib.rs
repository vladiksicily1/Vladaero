#![no_std]
#![allow(non_camel_case_types)]

extern crate alloc;

use linked_list_allocator::LockedHeap;

#[global_allocator]
static ALLOCATOR: LockedHeap = LockedHeap::empty();

#[panic_handler]
fn panic(_info: &core::panic::PanicInfo) -> ! { loop {} }

// ============================================================
// Types
// ============================================================
pub type NTSTATUS = i32;
pub type HANDLE = *mut core::ffi::c_void;
pub type PVOID = *mut core::ffi::c_void;
pub type ULONG = u32;
pub type ULONG_PTR = usize;
pub type USHORT = u16;
pub type BOOLEAN = u8;
pub type LONG = i32;
pub const TRUE: BOOLEAN = 1;
pub const FALSE: BOOLEAN = 0;
pub const STATUS_SUCCESS: NTSTATUS = 0;
pub const STATUS_UNSUCCESSFUL: NTSTATUS = -1073741823; // 0xC0000001

// ============================================================
// File constants
// ============================================================
pub const FILE_GENERIC_READ: ULONG = 0x00120089;
pub const FILE_GENERIC_WRITE: ULONG = 0x00120116;
pub const FILE_GENERIC_EXECUTE: ULONG = 0x001200A0;
pub const FILE_GENERIC_ALL: ULONG = 0x001F01FF;
pub const FILE_LIST_DIRECTORY: ULONG = 0x0001;
pub const FILE_READ_DATA: ULONG = 0x0001;
pub const FILE_WRITE_DATA: ULONG = 0x0002;
pub const FILE_APPEND_DATA: ULONG = 0x0004;
pub const FILE_EXECUTE: ULONG = 0x0020;
pub const FILE_CREATE: ULONG = 1;
pub const FILE_OPEN: ULONG = 2;
pub const FILE_OPEN_IF: ULONG = 3;
pub const FILE_OVERWRITE: ULONG = 4;
pub const FILE_OVERWRITE_IF: ULONG = 5;
pub const FILE_SUPERSEDE: ULONG = 0;
pub const FILE_ATTRIBUTE_NORMAL: ULONG = 0x80;
pub const FILE_ATTRIBUTE_DIRECTORY: ULONG = 0x10;
pub const FILE_DIRECTORY_FILE: ULONG = 0x00000001;
pub const FILE_SYNCHRONOUS_IO_ALERT: ULONG = 0x00000010;
pub const OBJ_CASE_INSENSITIVE: ULONG = 0x40;
pub const OBJ_INHERIT: ULONG = 0x00000002;
pub const OBJ_OPENIF: ULONG = 0x00000080;

// Memory constants
pub const MEM_COMMIT: ULONG = 0x1000;
pub const MEM_RESERVE: ULONG = 0x2000;
pub const MEM_RELEASE: ULONG = 0x8000;
pub const MEM_DECOMMIT: ULONG = 0x4000;
pub const PAGE_READWRITE: ULONG = 0x04;
pub const PAGE_EXECUTE_READWRITE: ULONG = 0x40;
pub const PAGE_READONLY: ULONG = 0x02;
pub const PAGE_EXECUTE: ULONG = 0x10;

// Process/Thread constants
pub const PROCESS_CREATE_PROCESS: ULONG = 0x0080;
pub const PROCESS_CREATE_THREAD: ULONG = 0x0002;
pub const PROCESS_VM_OPERATION: ULONG = 0x0008;
pub const PROCESS_VM_READ: ULONG = 0x0010;
pub const PROCESS_VM_WRITE: ULONG = 0x0020;
pub const PROCESS_DUP_HANDLE: ULONG = 0x0040;
pub const PROCESS_SET_INFORMATION: ULONG = 0x0200;
pub const PROCESS_QUERY_INFORMATION: ULONG = 0x0400;
pub const PROCESS_TERMINATE: ULONG = 0x0001;
pub const PROCESS_SUSPEND_RESUME: ULONG = 0x0800;
pub const THREAD_CREATE: ULONG = 0x0002;
pub const THREAD_SET_CONTEXT: ULONG = 0x0008;
pub const THREAD_GET_CONTEXT: ULONG = 0x0004;
pub const THREAD_SUSPEND_RESUME: ULONG = 0x0002;
pub const THREAD_TERMINATE: ULONG = 0x0001;
pub const THREAD_QUERY_INFORMATION: ULONG = 0x0040;
pub const THREAD_ALL_ACCESS: ULONG = 0x001FFFFF;
pub const INITIAL_JOB: ULONG = 0;
pub const INFINITE: i64 = -1i64;
pub const NtCurrentProcess: HANDLE = -1isize as HANDLE;
pub const NtCurrentThread: HANDLE = -2isize as HANDLE;

// Section constants
pub const SECTION_QUERY: ULONG = 0x0001;
pub const SECTION_MAP_WRITE: ULONG = 0x0002;
pub const SECTION_MAP_READ: ULONG = 0x0004;
pub const SECTION_MAP_EXECUTE: ULONG = 0x0008;
pub const SECTION_ALL_ACCESS: ULONG = 0x000F001F;

// Synchronization constants
pub const EVENT_MODIFY_STATE: ULONG = 0x0002;
pub const MUTANT_QUERY_STATE: ULONG = 0x0001;
pub const SEMAPHORE_MODIFY_STATE: ULONG = 0x0002;

// Registry constants
pub const REG_SZ: ULONG = 1;
pub const REG_EXPAND_SZ: ULONG = 2;
pub const REG_BINARY: ULONG = 3;
pub const REG_DWORD: ULONG = 4;
pub const REG_MULTI_SZ: ULONG = 7;
pub const REG_OPTION_NON_VOLATILE: ULONG = 0;
pub const REG_CREATED_NEW_KEY: ULONG = 1;
pub const REG_OPENED_EXISTING_KEY: ULONG = 2;

// Object directory constants
pub const DIRECTORY_QUERY: ULONG = 0x0001;
pub const DIRECTORY_CREATE_OBJECT: ULONG = 0x0002;
pub const DIRECTORY_TRAVERSE: ULONG = 0x0008;
pub const SYMBOLIC_LINK_QUERY: ULONG = 0x0001;
pub const SYMBOLIC_LINK_ALL_ACCESS: ULONG = 0x001F0001;

// Key access
pub const KEY_READ: ULONG = 0x20019;
pub const KEY_WRITE: ULONG = 0x20006;
pub const KEY_ALL_ACCESS: ULONG = 0xF003F;
pub const KEY_QUERY_VALUE: ULONG = 0x0001;
pub const KEY_SET_VALUE: ULONG = 0x0002;
pub const KEY_CREATE_SUB_KEY: ULONG = 0x0004;
pub const KEY_ENUMERATE_SUB_KEYS: ULONG = 0x0008;

// IO control
pub const FILE_DEVICE_NETWORK: ULONG = 0x0012;

// Security
pub const TOKEN_QUERY: ULONG = 0x0008;
pub const TOKEN_ADJUST_PRIVILEGES: ULONG = 0x0020;

// ============================================================
// Structures
// ============================================================

#[repr(C)]
pub struct UNICODE_STRING {
    pub length: USHORT,
    pub maximum_length: USHORT,
    pub buffer: *const u16,
}

#[repr(C)]
pub struct OBJECT_ATTRIBUTES {
    pub length: ULONG,
    pub root_directory: HANDLE,
    pub object_name: *const UNICODE_STRING,
    pub attributes: ULONG,
    pub security_descriptor: PVOID,
    pub security_quality_of_service: PVOID,
}

#[repr(C)]
pub struct IO_STATUS_BLOCK {
    pub status: NTSTATUS,
    pub information: usize,
}

#[repr(C)]
pub struct CLIENT_ID {
    pub unique_process: HANDLE,
    pub unique_thread: HANDLE,
}

#[repr(C)]
pub struct PS_ATTRIBUTE {
    pub attribute: usize,
    pub size: usize,
    pub value: usize,
    pub return_length: *mut usize,
}

#[repr(C)]
pub struct PS_CREATE_INFO {
    pub size: usize,
    pub state: u32,
    pub pad: u32,
    pub init_state: u64,
}

#[repr(C)]
pub struct LARGE_INTEGER {
    pub low_part: u32,
    pub high_part: i32,
}

#[repr(C)]
pub struct SECTION_IMAGE_INFORMATION {
    pub image_base_address: PVOID,
    pub image_subsystem: u32,
    pub image_characteristics: u32,
    pub image_virtual_size: u32,
    pub image_section_alignment: u32,
    pub image_file_alignment: u32,
    pub image_header_size: u32,
}

#[repr(C)]
pub struct FILE_DIRECTORY_INFORMATION {
    pub next_entry_offset: u32,
    pub file_index: u32,
    pub creation_time: i64,
    pub last_access_time: i64,
    pub last_write_time: i64,
    pub change_time: i64,
    pub end_of_file: i64,
    pub allocation_size: i64,
    pub file_attributes: u32,
    pub file_name_length: u32,
    pub file_name: [u16; 1], // variable length
}

#[repr(C)]
pub struct TOKEN_USER {
    // Simplified
    pub user_sid: [u8; 68],
}

#[repr(C)]
pub struct TOKEN_PRIVILEGES {
    pub privilege_count: u32,
    pub privileges: [(u32, u32); 1], // LUID + Attributes
}

#[repr(C)]
pub struct OBJECT_NAME_INFORMATION {
    pub name: UNICODE_STRING,
}

#[repr(C)]
pub struct DIRECTORY_BASIC_INFORMATION {
    pub object_name: UNICODE_STRING,
    pub object_type_name: UNICODE_STRING,
    pub attributes: u32,
    pub object_name_length: u32,
    pub object_type_name_length: u32,
}

// ============================================================
// Syscall interface
// ============================================================

#[inline(always)]
unsafe fn sys6(nr: usize, a: [usize; 6]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") nr => ret,
        in("rdi") a[0],
        in("rsi") a[1],
        in("rdx") a[2],
        in("r10") a[3],
        in("r8") a[4],
        in("r9") a[5],
        lateout("rcx") _,
        lateout("r11") _
    );
    ret
}

const N: usize = 0x1000;

// ============================================================
// File I/O syscalls
// ============================================================

pub unsafe fn NtCreateFile(
    file_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    io_status_block: *mut IO_STATUS_BLOCK,
    allocation_size: *mut i64,
    file_attributes: ULONG,
    share_access: ULONG,
    create_disposition: ULONG,
    create_options: ULONG,
    ea_buffer: PVOID,
    ea_length: ULONG,
) -> NTSTATUS {
    sys6(N + 0x00, [
        file_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        io_status_block as usize,
        allocation_size as usize,
        file_attributes as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtOpenFile(
    file_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    io_status_block: *mut IO_STATUS_BLOCK,
    share_access: ULONG,
    open_options: ULONG,
) -> NTSTATUS {
    sys6(N + 0x01, [
        file_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        io_status_block as usize,
        share_access as usize,
        open_options as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtReadFile(
    file_handle: HANDLE,
    event: HANDLE,
    apc_routine: PVOID,
    apc_context: PVOID,
    io_status_block: *mut IO_STATUS_BLOCK,
    buffer: PVOID,
    length: ULONG,
    byte_offset: *mut i64,
    key: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x02, [
        file_handle as usize,
        event as usize,
        apc_routine as usize,
        apc_context as usize,
        io_status_block as usize,
        buffer as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtWriteFile(
    file_handle: HANDLE,
    event: HANDLE,
    apc_routine: PVOID,
    apc_context: PVOID,
    io_status_block: *mut IO_STATUS_BLOCK,
    buffer: PVOID,
    length: ULONG,
    byte_offset: *mut i64,
    key: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x03, [
        file_handle as usize,
        event as usize,
        apc_routine as usize,
        apc_context as usize,
        io_status_block as usize,
        buffer as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtDeleteFile(object_attributes: *const OBJECT_ATTRIBUTES) -> NTSTATUS {
    sys6(N + 0x04, [object_attributes as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtSetInformationFile(
    file_handle: HANDLE,
    io_status_block: *mut IO_STATUS_BLOCK,
    file_information: PVOID,
    length: ULONG,
    file_information_class: ULONG,
) -> NTSTATUS {
    sys6(N + 0x06, [
        file_handle as usize,
        io_status_block as usize,
        file_information as usize,
        length as usize,
        file_information_class as usize,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtQueryInformationFile(
    file_handle: HANDLE,
    io_status_block: *mut IO_STATUS_BLOCK,
    file_information: PVOID,
    length: ULONG,
    file_information_class: ULONG,
) -> NTSTATUS {
    sys6(N + 0x07, [
        file_handle as usize,
        io_status_block as usize,
        file_information as usize,
        length as usize,
        file_information_class as usize,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtDeviceIoControlFile(
    file_handle: HANDLE,
    event: HANDLE,
    apc_routine: PVOID,
    apc_context: PVOID,
    io_status_block: *mut IO_STATUS_BLOCK,
    io_control_code: ULONG,
    input_buffer: PVOID,
    input_length: ULONG,
    output_buffer: PVOID,
    output_length: ULONG,
) -> NTSTATUS {
    // 10 args, pack via pointer
    #[repr(C)]
    struct IoCtlArgs {
        file_handle: usize, event: usize, apc_routine: usize, apc_context: usize,
        io_status_block: usize, io_control_code: usize, input_buffer: usize,
        input_length: usize, output_buffer: usize, output_length: usize,
    }
    let args = IoCtlArgs {
        file_handle: file_handle as usize,
        event: event as usize,
        apc_routine: apc_routine as usize,
        apc_context: apc_context as usize,
        io_status_block: io_status_block as usize,
        io_control_code: io_control_code as usize,
        input_buffer: input_buffer as usize,
        input_length: input_length as usize,
        output_buffer: output_buffer as usize,
        output_length: output_length as usize,
    };
    sys6(N + 0x08, [&args as *const _ as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtFlushBuffersFile(file_handle: HANDLE, io_status_block: *mut IO_STATUS_BLOCK) -> NTSTATUS {
    sys6(N + 0x09, [file_handle as usize, io_status_block as usize, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtQueryDirectoryFile(
    file_handle: HANDLE,
    event: HANDLE,
    apc_routine: PVOID,
    apc_context: PVOID,
    io_status_block: *mut IO_STATUS_BLOCK,
    file_information: PVOID,
    length: ULONG,
    file_information_class: ULONG,
    return_single_entry: BOOLEAN,
    file_name: *const UNICODE_STRING,
    restart_scan: BOOLEAN,
) -> NTSTATUS {
    #[repr(C)]
    struct QueryDirArgs {
        file_handle: usize, event: usize, apc_routine: usize, apc_context: usize,
        io_status_block: usize, file_information: usize, length: usize,
        file_information_class: usize, return_single_entry: usize,
        file_name: usize, restart_scan: usize,
    }
    let args = QueryDirArgs {
        file_handle: file_handle as usize,
        event: event as usize,
        apc_routine: apc_routine as usize,
        apc_context: apc_context as usize,
        io_status_block: io_status_block as usize,
        file_information: file_information as usize,
        length: length as usize,
        file_information_class: file_information_class as usize,
        return_single_entry: return_single_entry as usize,
        file_name: file_name as usize,
        restart_scan: restart_scan as usize,
    };
    sys6(N + 0x0A, [&args as *const _ as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtClose(handle: HANDLE) -> NTSTATUS {
    sys6(N + 0x0F, [handle as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

// ============================================================
// Process/Thread syscalls
// ============================================================

/// NtCreateUserProcess — args match kernel nt_syscall_create_process:
/// 1: process_handle  2: desired_access  3: object_attributes
/// 4: parent_process  5: inherit_handles  6: section_handle
pub unsafe fn NtCreateUserProcess(
    process_handle: *mut HANDLE,
    _thread_handle: *mut HANDLE,  // ignored by kernel, thread created automatically
    desired_access: ULONG,
    _thread_desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    _thread_object_attributes: *const OBJECT_ATTRIBUTES,
    create_process_flags: ULONG,
    _create_thread_flags: ULONG,
    _parameter: PVOID,
    parent_process: PVOID,
    _inherit_list: PVOID,
    _inherit_handles: PVOID, // bool, mapped to arg5
    _environment: PVOID,
    _client_id: PVOID,
    _image_info: PVOID,
    _attribute_list: *mut PS_ATTRIBUTE,
    create_info: *mut PS_CREATE_INFO,
) -> NTSTATUS {
    let inherit = if create_process_flags & 0x00000400 != 0 { 1usize } else { 0usize };
    let section = if !create_info.is_null() { (*create_info).init_state as usize } else { 0 };
    sys6(N + 0x10, [
        process_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        parent_process as usize,
        inherit,
        section,
    ]) as NTSTATUS
}

pub unsafe fn NtCreateThread(
    thread_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    process_handle: HANDLE,
    client_id: *mut CLIENT_ID,
    context: PVOID,
    initial_stack: PVOID,
    zero_bits: BOOLEAN,
    max_commit: usize,
    commit_size: usize,
    stack_reserved: usize,
    stack_commit: usize,
) -> NTSTATUS {
    sys6(N + 0x11, [
        thread_handle as usize,
        process_handle as usize,
        initial_stack as usize,
        context as usize,
        0,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtOpenProcess(
    process_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    client_id: *mut CLIENT_ID,
) -> NTSTATUS {
    sys6(N + 0x12, [
        process_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        client_id as usize,
        0,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtTerminateProcess(process_handle: HANDLE, exit_status: NTSTATUS) -> NTSTATUS {
    sys6(N + 0x13, [process_handle as usize, exit_status as usize, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtSuspendProcess(process_handle: HANDLE) -> NTSTATUS {
    sys6(N + 0x14, [process_handle as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtResumeProcess(process_handle: HANDLE) -> NTSTATUS {
    sys6(N + 0x15, [process_handle as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtQueryInformationProcess(
    process_handle: HANDLE,
    process_information_class: ULONG,
    process_information: PVOID,
    process_information_length: ULONG,
    return_length: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x16, [
        process_handle as usize,
        process_information_class as usize,
        process_information as usize,
        process_information_length as usize,
        return_length as usize,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtSetInformationThread(
    thread_handle: HANDLE,
    thread_information_class: ULONG,
    thread_information: PVOID,
    thread_information_length: ULONG,
) -> NTSTATUS {
    sys6(N + 0x17, [
        thread_handle as usize,
        thread_information_class as usize,
        thread_information as usize,
        thread_information_length as usize,
        0,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtWaitForSingleObject(
    handle: HANDLE,
    alertable: BOOLEAN,
    timeout: *mut i64,
) -> NTSTATUS {
    sys6(N + 0x18, [
        handle as usize,
        alertable as usize,
        timeout as usize,
        0,
        0,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtWaitForMultipleObjects(
    count: ULONG,
    handles: *const HANDLE,
    wait_type: BOOLEAN,
    alertable: BOOLEAN,
    timeout: *mut i64,
) -> NTSTATUS {
    sys6(N + 0x19, [
        count as usize,
        handles as usize,
        wait_type as usize,
        alertable as usize,
        timeout as usize,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtQueryInformationThread(
    thread_handle: HANDLE,
    thread_information_class: ULONG,
    thread_information: PVOID,
    thread_information_length: ULONG,
    return_length: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x1A, [
        thread_handle as usize,
        thread_information_class as usize,
        thread_information as usize,
        thread_information_length as usize,
        return_length as usize,
        0,
    ]) as NTSTATUS
}

// ============================================================
// Memory syscalls
// ============================================================

pub unsafe fn NtAllocateVirtualMemory(
    process_handle: HANDLE,
    base_address: *mut PVOID,
    zero_bits: usize,
    region_size: *mut usize,
    allocation_type: ULONG,
    protect: ULONG,
) -> NTSTATUS {
    sys6(N + 0x20, [
        process_handle as usize,
        base_address as usize,
        zero_bits,
        region_size as usize,
        allocation_type as usize,
        protect as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtFreeVirtualMemory(
    process_handle: HANDLE,
    base_address: *mut PVOID,
    region_size: *mut usize,
    free_type: ULONG,
) -> NTSTATUS {
    sys6(N + 0x21, [
        process_handle as usize,
        base_address as usize,
        region_size as usize,
        free_type as usize,
        0,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtProtectVirtualMemory(
    process_handle: HANDLE,
    base_address: *mut PVOID,
    region_size: *mut usize,
    new_protect: ULONG,
    old_protect: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x22, [
        process_handle as usize,
        base_address as usize,
        region_size as usize,
        new_protect as usize,
        old_protect as usize,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtQueryVirtualMemory(
    process_handle: HANDLE,
    base_address: PVOID,
    memory_information_class: ULONG,
    memory_information: PVOID,
    memory_information_length: usize,
    return_length: *mut usize,
) -> NTSTATUS {
    sys6(N + 0x23, [
        process_handle as usize,
        base_address as usize,
        memory_information_class as usize,
        memory_information as usize,
        memory_information_length,
        return_length as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtCreateSection(
    section_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    maximum_size: *mut i64,
    section_page_protection: ULONG,
    allocation_attributes: ULONG,
    file_handle: HANDLE,
) -> NTSTATUS {
    sys6(N + 0x24, [
        section_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        maximum_size as usize,
        section_page_protection as usize,
        allocation_attributes as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtOpenSection(
    section_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
) -> NTSTATUS {
    sys6(N + 0x25, [
        section_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        0,
        0,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtMapViewOfSection(
    section_handle: HANDLE,
    process_handle: HANDLE,
    base_address: *mut PVOID,
    zero_bits: usize,
    commit_size: usize,
    section_offset: *mut i64,
    view_size: *mut usize,
    inherit_disposition: ULONG,
    allocation_type: ULONG,
    section_protect: ULONG,
) -> NTSTATUS {
    // 10 args — pack
    #[repr(C)]
    struct MapViewArgs {
        section_handle: usize, process_handle: usize, base_address: usize,
        zero_bits: usize, commit_size: usize, section_offset: usize,
        view_size: usize, inherit_disposition: usize, allocation_type: usize,
        section_protect: usize,
    }
    let args = MapViewArgs {
        section_handle: section_handle as usize,
        process_handle: process_handle as usize,
        base_address: base_address as usize,
        zero_bits, commit_size,
        section_offset: section_offset as usize,
        view_size: view_size as usize,
        inherit_disposition: inherit_disposition as usize,
        allocation_type: allocation_type as usize,
        section_protect: section_protect as usize,
    };
    sys6(N + 0x26, [&args as *const _ as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtUnmapViewOfSection(
    process_handle: HANDLE,
    base_address: PVOID,
) -> NTSTATUS {
    sys6(N + 0x27, [process_handle as usize, base_address as usize, 0, 0, 0, 0]) as NTSTATUS
}

// ============================================================
// Object directory syscalls
// ============================================================

pub unsafe fn NtOpenDirectoryObject(
    directory_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
) -> NTSTATUS {
    sys6(N + 0x30, [
        directory_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        0, 0, 0,
    ]) as NTSTATUS
}

pub unsafe fn NtCreateDirectoryObject(
    directory_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
) -> NTSTATUS {
    sys6(N + 0x31, [
        directory_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        0, 0, 0,
    ]) as NTSTATUS
}

pub unsafe fn NtOpenSymbolicLinkObject(
    link_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
) -> NTSTATUS {
    sys6(N + 0x32, [
        link_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        0, 0, 0,
    ]) as NTSTATUS
}

pub unsafe fn NtCreateSymbolicLinkObject(
    link_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    target: *const UNICODE_STRING,
) -> NTSTATUS {
    sys6(N + 0x33, [
        link_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        target as usize,
        0, 0,
    ]) as NTSTATUS
}

pub unsafe fn NtQueryDirectoryObject(
    directory_handle: HANDLE,
    buffer: PVOID,
    length: ULONG,
    return_single_entry: BOOLEAN,
    restart_scan: BOOLEAN,
    context: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x34, [
        directory_handle as usize,
        buffer as usize,
        length as usize,
        return_single_entry as usize,
        restart_scan as usize,
        context as usize,
    ]) as NTSTATUS
}

// ============================================================
// Registry syscalls
// ============================================================

pub unsafe fn NtOpenKey(
    key_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
) -> NTSTATUS {
    sys6(N + 0x40, [
        key_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        0, 0, 0,
    ]) as NTSTATUS
}

pub unsafe fn NtCreateKey(
    key_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    title_index: ULONG,
    class: *const UNICODE_STRING,
    create_options: ULONG,
    disposition: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x41, [
        key_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        title_index as usize,
        class as usize,
        create_options as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtDeleteKey(key_handle: HANDLE) -> NTSTATUS {
    sys6(N + 0x42, [key_handle as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtSetValueKey(
    key_handle: HANDLE,
    value_name: *const UNICODE_STRING,
    title_index: ULONG,
    data_type: ULONG,
    data: PVOID,
    data_size: ULONG,
) -> NTSTATUS {
    sys6(N + 0x43, [
        key_handle as usize,
        value_name as usize,
        title_index as usize,
        data_type as usize,
        data as usize,
        data_size as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtQueryValueKey(
    key_handle: HANDLE,
    value_name: *const UNICODE_STRING,
    information_class: ULONG,
    information: PVOID,
    length: ULONG,
    return_length: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x44, [
        key_handle as usize,
        value_name as usize,
        information_class as usize,
        information as usize,
        length as usize,
        return_length as usize,
    ]) as NTSTATUS
}

pub unsafe fn NtEnumerateKey(
    key_handle: HANDLE,
    index: ULONG,
    key_information_class: ULONG,
    key_information: PVOID,
    length: ULONG,
    return_length: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x45, [
        key_handle as usize,
        index as usize,
        key_information_class as usize,
        key_information as usize,
        length as usize,
        return_length as usize,
    ]) as NTSTATUS
}

// ============================================================
// Security syscalls
// ============================================================

pub unsafe fn NtOpenProcessToken(
    process_handle: HANDLE,
    desired_access: ULONG,
    token_handle: *mut HANDLE,
) -> NTSTATUS {
    sys6(N + 0x50, [
        process_handle as usize,
        desired_access as usize,
        token_handle as usize,
        0, 0, 0,
    ]) as NTSTATUS
}

pub unsafe fn NtQueryInformationToken(
    token_handle: HANDLE,
    token_information_class: ULONG,
    token_information: PVOID,
    token_information_length: ULONG,
    return_length: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x51, [
        token_handle as usize,
        token_information_class as usize,
        token_information as usize,
        token_information_length as usize,
        return_length as usize,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtAdjustPrivilegesToken(
    token_handle: HANDLE,
    disable_all: BOOLEAN,
    new_state: *const TOKEN_PRIVILEGES,
    buffer_length: ULONG,
    previous_state: PVOID,
    return_length: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x52, [
        token_handle as usize,
        disable_all as usize,
        new_state as usize,
        buffer_length as usize,
        previous_state as usize,
        return_length as usize,
    ]) as NTSTATUS
}

// ============================================================
// Synchronization syscalls
// ============================================================

pub unsafe fn NtCreateEvent(
    event_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    event_type: ULONG,
    initial_state: BOOLEAN,
) -> NTSTATUS {
    sys6(N + 0x60, [
        event_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        event_type as usize,
        initial_state as usize,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtSetEvent(event_handle: HANDLE, previous_state: *mut LONG) -> NTSTATUS {
    sys6(N + 0x61, [event_handle as usize, previous_state as usize, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtClearEvent(event_handle: HANDLE) -> NTSTATUS {
    sys6(N + 0x62, [event_handle as usize, 0, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtCreateMutant(
    mutant_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    initial_owner: BOOLEAN,
) -> NTSTATUS {
    sys6(N + 0x63, [
        mutant_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        initial_owner as usize,
        0, 0,
    ]) as NTSTATUS
}

pub unsafe fn NtCreateSemaphore(
    semaphore_handle: *mut HANDLE,
    desired_access: ULONG,
    object_attributes: *const OBJECT_ATTRIBUTES,
    initial_count: LONG,
    maximum_count: LONG,
) -> NTSTATUS {
    sys6(N + 0x64, [
        semaphore_handle as usize,
        desired_access as usize,
        object_attributes as usize,
        initial_count as usize,
        maximum_count as usize,
        0,
    ]) as NTSTATUS
}

pub unsafe fn NtDelayExecution(alertable: BOOLEAN, delay_interval: *mut i64) -> NTSTATUS {
    sys6(N + 0x65, [alertable as usize, delay_interval as usize, 0, 0, 0, 0]) as NTSTATUS
}

pub unsafe fn NtYieldExecution() -> NTSTATUS {
    sys6(N + 0x66, [0, 0, 0, 0, 0, 0]) as NTSTATUS
}

// ============================================================
// System Information syscalls
// ============================================================

pub unsafe fn NtQuerySystemInformation(
    system_information_class: ULONG,
    system_information: PVOID,
    system_information_length: ULONG,
    return_length: *mut ULONG,
) -> NTSTATUS {
    sys6(N + 0x70, [
        system_information_class as usize,
        system_information as usize,
        system_information_length as usize,
        return_length as usize,
        0,
        0,
    ]) as NTSTATUS
}

// ============================================================
// High-level helper functions
// ============================================================

pub fn u16str(s: &str) -> (UNICODE_STRING, alloc::vec::Vec<u16>) {
    let v: alloc::vec::Vec<u16> = s.encode_utf16().collect();
    let l = (v.len() * 2) as USHORT;
    (UNICODE_STRING { length: l, maximum_length: l, buffer: v.as_ptr() }, v)
}

pub fn make_oa(name: &str) -> (OBJECT_ATTRIBUTES, alloc::vec::Vec<u16>) {
    let (us, b) = u16str(name);
    (
        OBJECT_ATTRIBUTES {
            length: core::mem::size_of::<OBJECT_ATTRIBUTES>() as ULONG,
            root_directory: core::ptr::null_mut(),
            object_name: &us as *const UNICODE_STRING,
            attributes: OBJ_CASE_INSENSITIVE,
            security_descriptor: core::ptr::null_mut(),
            security_quality_of_service: core::ptr::null_mut(),
        },
        b,
    )
}

pub fn open(path: &str, access: ULONG, disp: ULONG) -> Result<HANDLE, NTSTATUS> {
    unsafe {
        let (oa, _b) = make_oa(path);
        let mut h: HANDLE = core::ptr::null_mut();
        let mut iosb = IO_STATUS_BLOCK { status: 0, information: 0 };
        let s = NtCreateFile(
            &mut h, access, &oa, &mut iosb,
            core::ptr::null_mut(), FILE_ATTRIBUTE_NORMAL, 0,
            disp, 0x20, core::ptr::null_mut(), 0,
        );
        if s == STATUS_SUCCESS { Ok(h) } else { Err(s) }
    }
}

pub fn read(h: HANDLE, buf: &mut [u8]) -> Result<usize, NTSTATUS> {
    unsafe {
        let mut iosb = IO_STATUS_BLOCK { status: 0, information: 0 };
        let s = NtReadFile(
            h, core::ptr::null_mut(), core::ptr::null_mut(), core::ptr::null_mut(),
            &mut iosb, buf.as_mut_ptr() as PVOID, buf.len() as ULONG,
            core::ptr::null_mut(), core::ptr::null_mut(),
        );
        if s == STATUS_SUCCESS { Ok(iosb.information) } else { Err(s) }
    }
}

pub fn write(h: HANDLE, buf: &[u8]) -> Result<usize, NTSTATUS> {
    unsafe {
        let mut iosb = IO_STATUS_BLOCK { status: 0, information: 0 };
        let s = NtWriteFile(
            h, core::ptr::null_mut(), core::ptr::null_mut(), core::ptr::null_mut(),
            &mut iosb, buf.as_ptr() as PVOID, buf.len() as ULONG,
            core::ptr::null_mut(), core::ptr::null_mut(),
        );
        if s == STATUS_SUCCESS { Ok(iosb.information) } else { Err(s) }
    }
}

pub fn close(h: HANDLE) -> Result<(), NTSTATUS> {
    unsafe { let s = NtClose(h); if s == STATUS_SUCCESS { Ok(()) } else { Err(s) } }
}

pub fn sleep(ms: u64) {
    unsafe {
        let mut d: i64 = -(ms as i64 * 10000);
        NtDelayExecution(FALSE, &mut d);
    }
}

pub fn exit(code: i32) -> ! {
    unsafe { NtTerminateProcess(core::ptr::null_mut(), code); }
    loop {}
}

pub fn alloc_mem(size: usize) -> Result<PVOID, NTSTATUS> {
    unsafe {
        let mut b: PVOID = core::ptr::null_mut();
        let mut s = size;
        let r = NtAllocateVirtualMemory(
            core::ptr::null_mut(), &mut b, 0, &mut s,
            MEM_COMMIT | MEM_RESERVE, PAGE_READWRITE,
        );
        if r == STATUS_SUCCESS { Ok(b) } else { Err(r) }
    }
}

pub fn free_mem(base: PVOID, size: usize) -> Result<(), NTSTATUS> {
    unsafe {
        let mut b = base;
        let mut s = size;
        let r = NtFreeVirtualMemory(core::ptr::null_mut(), &mut b, &mut s, MEM_RELEASE);
        if r == STATUS_SUCCESS { Ok(()) } else { Err(r) }
    }
}

pub fn create_section(file_handle: HANDLE, max_size: u64, protect: ULONG) -> Result<HANDLE, NTSTATUS> {
    unsafe {
        let mut h: HANDLE = core::ptr::null_mut();
        let mut ms = max_size as i64;
        let s = NtCreateSection(
            &mut h, SECTION_ALL_ACCESS, core::ptr::null(),
            &mut ms, protect, 0x08000000, // SEC_COMMIT
            file_handle,
        );
        if s == STATUS_SUCCESS { Ok(h) } else { Err(s) }
    }
}

pub fn open_file(path: &str, access: ULONG, share: ULONG, options: ULONG) -> Result<HANDLE, NTSTATUS> {
    unsafe {
        let (oa, _b) = make_oa(path);
        let mut h: HANDLE = core::ptr::null_mut();
        let mut iosb = IO_STATUS_BLOCK { status: 0, information: 0 };
        let s = NtOpenFile(&mut h, access, &oa, &mut iosb, share, options);
        if s == STATUS_SUCCESS { Ok(h) } else { Err(s) }
    }
}

/// Create a process from a .vex file path
pub fn create_process_from_file(path: &str) -> Result<HANDLE, NTSTATUS> {
    // 1. Open the executable file
    let file_handle = open_file(
        path,
        FILE_GENERIC_READ,
        1, // FILE_SHARE_READ
        FILE_SYNCHRONOUS_IO_ALERT,
    )?;

    // 2. Create section from file
    let section_handle = match create_section(file_handle, 0, PAGE_READONLY) {
        Ok(h) => h,
        Err(e) => {
            unsafe { NtClose(file_handle); }
            return Err(e);
        }
    };

    // 3. Create process with section
    let mut proc_handle: HANDLE = core::ptr::null_mut();
    let s = unsafe {
        NtCreateUserProcess(
            &mut proc_handle,
            core::ptr::null_mut(),
            PROCESS_CREATE_PROCESS | PROCESS_QUERY_INFORMATION | PROCESS_VM_OPERATION
                | PROCESS_VM_READ | PROCESS_VM_WRITE | PROCESS_TERMINATE,
            0,
            core::ptr::null(),
            core::ptr::null(),
            0, 0,
            core::ptr::null_mut(),
            core::ptr::null_mut(), // parent = current process
            core::ptr::null_mut(),
            core::ptr::null_mut(),
            core::ptr::null_mut(),
            core::ptr::null_mut(),
            core::ptr::null_mut(),
            core::ptr::null_mut(),
            &mut PS_CREATE_INFO {
                size: core::mem::size_of::<PS_CREATE_INFO>(),
                state: 0, pad: 0, init_state: section_handle as u64,
            } as *mut PS_CREATE_INFO,
        )
    };

    unsafe {
        NtClose(section_handle);
        NtClose(file_handle);
    }

    if s == STATUS_SUCCESS { Ok(proc_handle) } else { Err(s) }
}

/// Terminate the current process
pub fn terminate_self(code: NTSTATUS) -> ! {
    unsafe { NtTerminateProcess(NtCurrentProcess, code); }
    loop {}
}
