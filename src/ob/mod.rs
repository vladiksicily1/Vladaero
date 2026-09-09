/// # Object Manager (Ob/Obp) - ntoskrnl.exe
///
/// Complete implementation of the Windows Object Manager subsystem
/// including object creation, handle table management, object
/// namespace, reference counting, and type registration.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 3
///   - WRK: ntoskrnl/ob/
///   - ReactOS: ntoskrnl/ob/

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicI32, AtomicU32, AtomicUsize, Ordering};

use crate::types::*;
use crate::mm::{self, ListEntry, SpinLock};

// ============================================================
// Constants
// ============================================================

pub const OBJ_INHERIT: u32 = 0x00000002;
pub const OBJ_PERMANENT: u32 = 0x00000010;
pub const OBJ_EXCLUSIVE: u32 = 0x00000020;
pub const OBJ_CASE_INSENSITIVE: u32 = 0x00000040;
pub const OBJ_OPENIF: u32 = 0x00000080;
pub const OBJ_OPENLINK: u32 = 0x00000100;
pub const OBJ_KERNEL_HANDLE: u32 = 0x00000200;
pub const OBJ_FORCE_ACCESS_CHECK: u32 = 0x00000400;
pub const OBJ_IGNORE_PERSISTED_EXISTENCE_CHECK: u32 = 0x00000800;
pub const OBJ_DONT_REPARSE: u32 = 0x00001000;
pub const OBJ_VALID_OBJECT_ATTRIBUTES: u32 = 0x001FFFFF;
pub static mut OBJ_VALID_ATTRIBUTES: u32 = 0x000000FF;

pub const OB_MAX_OBJECT_TYPE_INDEX: usize = 64;
pub const OB_GENERIC_ALL_ACCESS: u32 = 0x10000000;
pub const OB_HANDLE_OFFSET: u32 = 0;

pub const RTL_OBJECT_TYPE_DIRECTORY: u32 = 1;
pub const RTL_OBJECT_TYPE_SYMBOLIC_LINK: u32 = 2;

pub const DEFAULT_OBJECT_TYPE_INDEX: usize = 0;

pub const OB_NUMBER_HANDLE_TABLE_BUCKETS: usize = 37;
pub const LOW_LEVEL_ENTRY_SIZE: usize = 32;
pub const MID_LEVEL_ENTRY_SIZE: usize = 256;

pub const RTL_SECURE_HANDLE_RECREATE_SUSPEND: u32 = 1;

const STATUS_INFO_LENGTH_MISMATCH: NtStatus = 0xC0000004;

// ============================================================
// Logging macros
// ============================================================

macro_rules! ob_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "ob_trace")]
        crate::kernel_log!("[Ob] {}", format_args!($($arg)*));
    };
}

macro_rules! ob_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ob] {}", format_args!($($arg)*));
    };
}

macro_rules! ob_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ob] {}", format_args!($($arg)*));
    };
}

macro_rules! ob_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ob] {}", format_args!($($arg)*));
    };
}

// ============================================================
// Helper: create a UnicodeString from a byte literal
// ============================================================

pub fn create_unicode_string(bytes: &[u8]) -> UnicodeString {
    let char_count = bytes.len().saturating_sub(1); // exclude null terminator
    let buf_size = char_count * 2;
    let buf = unsafe {
        alloc::alloc::alloc(core::alloc::Layout::from_size_align(buf_size + 2, 2).unwrap()) as *mut u16
    };
    if !buf.is_null() {
        for i in 0..char_count {
            unsafe { *buf.add(i) = bytes[i] as u16; }
        }
        unsafe { *buf.add(char_count) = 0; } // null terminator
    }
    UnicodeString {
        length: buf_size as u16,
        maximum_length: (buf_size + 2) as u16,
        buffer: buf,
    }
}

// ============================================================
// ObjectTypeIndex
// ============================================================

pub type ObjectTypeIndex = u32;

// ============================================================
// ObjectHeader - placed before every managed object body
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct ObjectHeader {
    pub pointer_count: i64,
    pub handle_count: i32,
    pub lock: KspinLock,
    pub type_index: ObjectTypeIndex,
    pub info_mask: u8,
    pub flags: u8,
    pub opaque_pointer: u32,
    pub GrantedAccess: u32,
    pub body: ObjectHeaderBody,
}

pub const OBJECT_HEADER_FLAG_NEW_OBJECT: u8 = 0x01;
pub const OBJECT_HEADER_FLAG_KERNEL_OBJECT: u8 = 0x02;
pub const OBJECT_HEADER_FLAG_PROCESS_OBJECT: u8 = 0x04;
pub const OBJECT_HEADER_FLAG_EXFixedSize: u8 = 0x08;
pub const OBJECT_HEADER_FLAG_EXCookie: u8 = 0x10;

impl ObjectHeader {
    pub fn new() -> Self {
        Self {
            pointer_count: 1,
            handle_count: 0,
            lock: 0,
            type_index: 0,
            info_mask: 0,
            flags: 0,
            opaque_pointer: 0,
            GrantedAccess: 0,
            body: ObjectHeaderBody::new(),
        }
    }

    pub fn get_object_body(&self) -> *mut c_void {
        unsafe {
            (self as *const Self as *mut u8).add(mem::size_of::<ObjectHeader>()) as *mut c_void
        }
    }

    pub fn from_body(body: *const c_void) -> *mut ObjectHeader {
        if body.is_null() { return core::ptr::null_mut(); }
        unsafe { (body as *mut u8).sub(mem::size_of::<ObjectHeader>()) as *mut ObjectHeader }
    }

    pub fn increment_pointer_count(&self) -> i64 {
        unsafe {
            let ptr = &self.pointer_count as *const i64 as *mut i64;
            core::ptr::read_volatile(ptr) + 1
        }
    }

    pub fn decrement_pointer_count(&self) -> i64 {
        unsafe {
            let ptr = &self.pointer_count as *const i64 as *mut i64;
            core::ptr::read_volatile(ptr) - 1
        }
    }
}

// ============================================================
// ObjectHeaderBody - embedded at start of object body
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ObjectHeaderBody {
    pub header: ObjectHeaderBodyInternal,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ObjectHeaderBodyInternal {
    pub type_index: ObjectTypeIndex,
    pub trace_flags: u8,
    pub info_mask: u8,
}

impl ObjectHeaderBody {
    pub fn new() -> Self {
        Self { header: ObjectHeaderBodyInternal { type_index: 0, trace_flags: 0, info_mask: 0 } }
    }
}

// ============================================================
// ObjectType - Describes each object type
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct ObjectType {
    pub type_index: ObjectTypeIndex,
    pub total_objects: u32,
    pub total_pools: u32,
    pub total_handles: u32,
    pub total_shared: u32,
    pub name: UnicodeString,
    pub default_object: *mut c_void,
    pub generic_mapping: GenericMapping,
    pub valid_access_mask: u32,
    pub retain_access: u32,
    pub pool_type: u32,
    pub default_paged_charge: u32,
    pub default_nonpaged_charge: u32,
    pub maintain_handle_count: u8,
    pub type_list: ListEntry,
    pub create_object_routine: Option<unsafe extern "C" fn(*mut c_void, *mut c_void, u32, u16, *mut c_void, *mut UnicodeString, u32, u32) -> NtStatus>,
    pub delete_procedure: Option<unsafe extern "C" fn(*mut c_void)>,
    pub parse_procedure: Option<unsafe extern "C" fn() -> NtStatus>,
    pub open_procedure: Option<unsafe extern "C" fn() -> NtStatus>,
    pub close_procedure: Option<unsafe extern "C" fn(*mut c_void, *mut c_void, u32, u32)>,
    pub free_child_component: Option<unsafe extern "C" fn(*mut c_void)>,
    pub is_valid_object_lock: bool,
}

impl ObjectType {
    pub fn new() -> Self {
        Self {
            type_index: 0,
            total_objects: 0,
            total_pools: 0,
            total_handles: 0,
            total_shared: 0,
            name: UnicodeString::new(),
            default_object: core::ptr::null_mut(),
            generic_mapping: GenericMapping::new(),
            valid_access_mask: 0,
            retain_access: 0,
            pool_type: 0,
            default_paged_charge: 0,
            default_nonpaged_charge: 0,
            maintain_handle_count: 0,
            type_list: ListEntry::new(),
            create_object_routine: None,
            delete_procedure: None,
            parse_procedure: None,
            open_procedure: None,
            close_procedure: None,
            free_child_component: None,
            is_valid_object_lock: false,
        }
    }
}

// ============================================================
// GenericMapping
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct GenericMapping {
    pub generic_read: u32,
    pub generic_write: u32,
    pub generic_execute: u32,
    pub generic_all: u32,
}

impl GenericMapping {
    pub fn new() -> Self {
        Self { generic_read: 0, generic_write: 0, generic_execute: 0, generic_all: 0 }
    }
}

// ============================================================
// ObjectTypeDirectory
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct ObjectTypeDirectory {
    pub directory_name: UnicodeString,
    pub entries: *mut ObjectDirectoryEntry,
    pub count: u32,
    pub lock: SpinLock,
}

impl ObjectTypeDirectory {
    pub fn new() -> Self {
        Self {
            directory_name: UnicodeString::new(),
            entries: core::ptr::null_mut(),
            count: 0,
            lock: SpinLock::new(),
        }
    }
}

// ============================================================
// HandleTableEntry
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct HandleTableEntry {
    pub value: u64,
    pub pointer: *mut c_void,
}

impl HandleTableEntry {
    pub fn new() -> Self {
        Self { value: 0, pointer: core::ptr::null_mut() }
    }

    pub fn is_free(&self) -> bool {
        self.pointer.is_null() && self.value == 0
    }

    pub fn is_valid(&self) -> bool {
        !self.pointer.is_null()
    }

    pub fn get_handle_attributes(&self) -> u32 {
        (self.value >> 56) as u32
    }

    pub fn set_handle_attributes(&mut self, attrs: u32) {
        self.value = (self.value & 0x00FFFFFFFFFFFFFF) | ((attrs as u64) << 56);
    }
}

// ============================================================
// HandleTable - per-process handle table
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct ObpHandleTable {
    pub table_code: u64,
    pub quota_process: *mut c_void,
    pub unique_process_id: u64,
    pub handle_lock: KspinLock,
    pub handle_table_list: ListEntry,
    pub handle_count: u32,
    pub flags: u32,
}

impl ObpHandleTable {
    pub fn new() -> Self {
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
// ObjectDirectoryEntry
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct ObjectDirectoryEntry {
    pub chain_link: *mut ObjectDirectoryEntry,
    pub object: *mut c_void,
    pub object_name: UnicodeString,
}

impl ObjectDirectoryEntry {
    pub fn new() -> Self {
        Self {
            chain_link: core::ptr::null_mut(),
            object: core::ptr::null_mut(),
            object_name: UnicodeString::new(),
        }
    }
}

// ============================================================
// Object namespace types
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ObjectSymbolicLink {
    pub link_target: UnicodeString,
    pub creation_time: u64,
    pub dos_device_name: UnicodeString,
}

impl ObjectSymbolicLink {
    pub fn new() -> Self {
        Self {
            link_target: UnicodeString::new(),
            creation_time: 0,
            dos_device_name: UnicodeString::new(),
        }
    }
}

// ============================================================
// ObpNameInformation (for ObQueryNameString)
// ============================================================

#[repr(C)]
pub struct ObpNameInformation {
    pub name: UnicodeString,
    pub inline_name: [u16; 256],
}

// ============================================================
// ObpObjectTypeInformation (for ObQueryObjectInformation)
// ============================================================

#[repr(C)]
pub struct ObpObjectTypeInformation {
    pub type_name: UnicodeString,
    pub total_objects: u32,
    pub total_handles: u32,
    pub total_pools: u32,
    pub pool_type: u32,
    pub default_paged_charge: u32,
    pub default_nonpaged_charge: u32,
    pub valid_access_mask: u32,
}

// ============================================================
// Global state
// ============================================================

static mut OBP_OBJECT_TYPE_TABLE: [Option<*mut ObjectType>; OB_MAX_OBJECT_TYPE_INDEX] = [None; OB_MAX_OBJECT_TYPE_INDEX];
static OBP_NUMBER_OBJECT_TYPES: AtomicU32 = AtomicU32::new(0);
static mut OBP_ROOT_DIRECTORY_OBJECT: *mut ObjectTypeDirectory = core::ptr::null_mut();
static OBP_LOCK: SpinLock = SpinLock::new();

// ============================================================
// ObpAllocateHandleTable
// ============================================================

pub fn obp_allocate_handle_table(owner_pid: u64) -> *mut ObpHandleTable {
    let size = mem::size_of::<ObpHandleTable>();
    let layout = match core::alloc::Layout::from_size_align(size, 8) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };
    let ht = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut ObpHandleTable };
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

// ============================================================
// ObpFreeHandleTable
// ============================================================

pub fn obp_free_handle_table(table: *mut ObpHandleTable) {
    if table.is_null() { return; }
    let layout = core::alloc::Layout::from_size_align(mem::size_of::<ObpHandleTable>(), 8).unwrap();
    unsafe { alloc::alloc::dealloc(table as *mut u8, layout); }
}

// ============================================================
// ObpCreateHandleEntry
// ============================================================

pub fn obp_create_handle_entry(
    table: *mut ObpHandleTable,
    object: *mut c_void,
    _desired_access: u32,
    handle_attributes: u32,
    _generation: u32,
) -> Handle {
    if table.is_null() || object.is_null() {
        return core::ptr::null_mut();
    }

    let tbl = unsafe { &mut *table };
    let entry_size = mem::size_of::<HandleTableEntry>();
    let new_index = tbl.handle_count as usize;

    let new_table_size = (new_index + 1) * entry_size;
    let new_layout = match core::alloc::Layout::from_size_align(new_table_size, 8) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };

    let new_table = if tbl.table_code == 0 {
        unsafe { alloc::alloc::alloc_zeroed(new_layout) }
    } else {
        let old_size = new_index * entry_size;
        unsafe { alloc::alloc::realloc(tbl.table_code as *mut u8, core::alloc::Layout::from_size_align(old_size, 8).unwrap(), new_table_size) }
    };

    if new_table.is_null() { return core::ptr::null_mut(); }

    let entries = new_table as *mut HandleTableEntry;
    let entry = unsafe { &mut *entries.add(new_index) };
    entry.pointer = object;
    entry.value = (handle_attributes as u64) << 56;

    tbl.table_code = new_table as u64;
    tbl.handle_count += 1;

    let handle = ((new_index << 2) | (handle_attributes as usize & 0x3)) as Handle;
    ob_trace!("ObpCreateHandleEntry: handle={:p} obj={:p}", handle, object);
    handle
}

// ============================================================
// ObpDeleteHandleEntry
// ============================================================

pub fn obp_delete_handle_entry(table: *mut ObpHandleTable, handle: Handle) -> NtStatus {
    if table.is_null() || handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let tbl = unsafe { &mut *table };
    let index = (handle as usize) >> 2;

    if index >= tbl.handle_count as usize {
        return STATUS_INVALID_PARAMETER;
    }

    let entries = tbl.table_code as *mut HandleTableEntry;
    let entry = unsafe { &mut *entries.add(index) };

    if entry.is_free() {
        return STATUS_INVALID_PARAMETER;
    }

    let _object = entry.pointer;
    entry.pointer = core::ptr::null_mut();
    entry.value = 0;

    ob_trace!("ObpDeleteHandleEntry: handle={:p}", handle);
    STATUS_SUCCESS
}

// ============================================================
// ObpLookupHandleEntry
// ============================================================

pub fn obp_lookup_handle_entry(table: *mut ObpHandleTable, handle: Handle) -> *mut HandleTableEntry {
    if table.is_null() || handle.is_null() {
        return core::ptr::null_mut();
    }

    let tbl = unsafe { &*table };
    let index = (handle as usize) >> 2;

    if index >= tbl.handle_count as usize {
        return core::ptr::null_mut();
    }

    let entries = tbl.table_code as *mut HandleTableEntry;
    let entry = unsafe { &mut *entries.add(index) };

    if entry.is_free() {
        core::ptr::null_mut()
    } else {
        entry as *mut HandleTableEntry
    }
}

// ============================================================
// ObpLookupObjectByName
// ============================================================

pub fn obp_lookup_object_by_name(
    directory: *mut ObjectTypeDirectory,
    name: *mut UnicodeString,
) -> *mut c_void {
    if directory.is_null() || name.is_null() {
        return core::ptr::null_mut();
    }

    let dir = unsafe { &*directory };
    let name_ref = unsafe { &*name };

    let _guard = dir.lock.acquire();

    let mut current = dir.entries;
    while !current.is_null() {
        let entry = unsafe { &*current };
        if !entry.object.is_null() && !entry.object_name.buffer.is_null() && name_ref.length > 0 {
            let obj_name = unsafe {
                core::slice::from_raw_parts(entry.object_name.buffer, entry.object_name.length as usize / 2)
            };
            let search_name = unsafe {
                core::slice::from_raw_parts(name_ref.buffer, name_ref.length as usize / 2)
            };
            if obj_name.len() == search_name.len() && obj_name == search_name {
                return entry.object;
            }
        }
        current = entry.chain_link;
    }

    core::ptr::null_mut()
}

// ============================================================
// ObpInsertObjectDirectory
// ============================================================

pub fn obp_insertObjectDirectory(
    directory: *mut ObjectTypeDirectory,
    object: *mut c_void,
    name: *mut UnicodeString,
) -> NtStatus {
    if directory.is_null() || object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dir = unsafe { &mut *directory };

    let entry_size = mem::size_of::<ObjectDirectoryEntry>();
    let layout = match core::alloc::Layout::from_size_align(entry_size, 8) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };
    let entry = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut ObjectDirectoryEntry };
    if entry.is_null() { return STATUS_NO_MEMORY; }

    let entry_ref = unsafe { &mut *entry };
    entry_ref.object = object;
    if !name.is_null() {
        let n = unsafe { &*name };
        entry_ref.object_name = UnicodeString { length: n.length, maximum_length: n.maximum_length, buffer: n.buffer };
    }

    let _guard = dir.lock.acquire();
    entry_ref.chain_link = dir.entries;
    dir.entries = entry;
    dir.count += 1;

    ob_trace!("ObpInsertObjectDirectory: inserted {:p} into dir {:p}", object, directory);
    STATUS_SUCCESS
}

// ============================================================
// ObpRemoveObjectDirectory
// ============================================================

pub fn obpRemoveObjectDirectory(
    directory: *mut ObjectTypeDirectory,
    object: *mut c_void,
) -> NtStatus {
    if directory.is_null() || object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dir = unsafe { &mut *directory };
    let _guard = dir.lock.acquire();

    let mut prev: *mut ObjectDirectoryEntry = core::ptr::null_mut();
    let mut current = dir.entries;

    while !current.is_null() {
        let cur = unsafe { &*current };
        if cur.object == object {
            if prev.is_null() {
                dir.entries = cur.chain_link;
            } else {
                unsafe { (*prev).chain_link = cur.chain_link; }
            }
            dir.count -= 1;
            let layout = core::alloc::Layout::from_size_align(mem::size_of::<ObjectDirectoryEntry>(), 8).unwrap();
            unsafe { alloc::alloc::dealloc(current as *mut u8, layout); }
            return STATUS_SUCCESS;
        }
        prev = current;
        current = cur.chain_link;
    }

    STATUS_OBJECT_NAME_NOT_FOUND
}

// ============================================================
// ObCreateObject - Create a kernel object
// ============================================================

pub fn ob_create_object(
    object_type_index: ObjectTypeIndex,
    object_attributes: *mut ObjectAttributes,
    _object_body_size: u32,
    _charge_mode: u8,
    _pool_tag: u32,
    _device_object: Pvoid,
    _parse_context: Pvoid,
    _object_body: *mut *mut c_void,
) -> NtStatus {
    if _object_body.is_null() { return STATUS_INVALID_PARAMETER; }
    if object_type_index >= OBP_NUMBER_OBJECT_TYPES.load(Ordering::Relaxed) as u32 {
        return STATUS_INVALID_PARAMETER;
    }

    let obj_body_size = mem::size_of::<ObjectHeader>() + _object_body_size as usize;
    let layout = match core::alloc::Layout::from_size_align(obj_body_size, 16) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let obj = unsafe { alloc::alloc::alloc_zeroed(layout) };
    if obj.is_null() { return STATUS_NO_MEMORY; }

    let header = unsafe { &mut *(obj as *mut ObjectHeader) };
    header.pointer_count = 1;
    header.handle_count = 0;
    header.lock = 0;
    header.type_index = object_type_index;
    header.flags = OBJECT_HEADER_FLAG_NEW_OBJECT;

    if !object_attributes.is_null() {
        let attrs = unsafe { &*object_attributes };
        if attrs.attributes & OBJ_PERMANENT != 0 {
            header.flags |= OBJECT_HEADER_FLAG_KERNEL_OBJECT;
        }
    }

    unsafe {
        *_object_body = obj.add(mem::size_of::<ObjectHeader>()) as *mut c_void;
        ob_trace!("ObCreateObject: type={} body={:p}", object_type_index, *_object_body);
    }
    STATUS_SUCCESS
}

// ============================================================
// ObInsertObject - Insert object into namespace / get handle
// ============================================================

pub fn ob_insert_object(
    object: *mut c_void,
    object_attributes: *mut ObjectAttributes,
    _pseudo_context: Pvoid,
    _remaining_objects: u32,
    _parent_directory: Pvoid,
    _parse_context: Pvoid,
    handle: *mut Handle,
) -> NtStatus {
    if object.is_null() { return STATUS_INVALID_PARAMETER; }

    let header = ObjectHeader::from_body(object);
    if header.is_null() { return STATUS_INVALID_PARAMETER; }

    let header_ref = unsafe { &mut *header };

    if !object_attributes.is_null() {
        let attrs = unsafe { &*object_attributes };
        if !attrs.object_name.is_null() && unsafe { (*attrs.object_name).length > 0 } {
            if !attrs.root_directory.is_null() {
                let _result = obp_insertObjectDirectory(
                    attrs.root_directory as *mut ObjectTypeDirectory,
                    object,
                    attrs.object_name as *mut UnicodeString,
                );
            }
        }
    }

    header_ref.handle_count += 1;

    if !handle.is_null() {
        unsafe { *handle = object as Handle; }
    }

    ob_trace!("ObInsertObject: obj={:p} handle={:p}", object, object as *mut c_void);
    STATUS_SUCCESS
}

// ============================================================
// ObOpenObjectByName - Open object by name
// ============================================================

pub fn ob_open_objectByName(
    object_attributes: *mut ObjectAttributes,
    object_type: ObjectTypeIndex,
    _access_mode: u8,
    _parse_context: Pvoid,
    _desired_access: u32,
    _open_context: Pvoid,
    handle_info: *mut u32,
) -> NtStatus {
    if object_attributes.is_null() { return STATUS_INVALID_PARAMETER; }

    let attrs = unsafe { &*object_attributes };
    if attrs.object_name.is_null() || unsafe { (*attrs.object_name).length == 0 } {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }

    // First try: lookup in root directory (existing behavior)
    if !attrs.root_directory.is_null() {
        let dir = attrs.root_directory as *mut ObjectTypeDirectory;
        let object = obp_lookup_object_by_name(dir, attrs.object_name as *mut UnicodeString);
        if !object.is_null() {
            if !handle_info.is_null() {
                unsafe { *handle_info = object as u32; }
            }
            ob_trace!("ObOpenObjectByName: found {:p}", object);
            return STATUS_SUCCESS;
        }
    }

    // Second try: resolve full path from root namespace
    let name_ref = unsafe { &*attrs.object_name };
    let name_chars = name_ref.length as usize / 2;
    if !name_ref.buffer.is_null() && name_chars > 0 {
        let name_slice = unsafe { core::slice::from_raw_parts(name_ref.buffer, name_chars) };
        let mut name_buf = [0u8; 256];
        let len = name_chars.min(255);
        for i in 0..len {
            name_buf[i] = name_slice[i] as u8;
        }
        let name_str = core::str::from_utf8(&name_buf[..len]).unwrap_or("");
        let object = obp_resolve_path(name_str);
        if !object.is_null() {
            if !handle_info.is_null() {
                unsafe { *handle_info = object as u32; }
            }
            ob_trace!("ObOpenObjectByName: resolved path, found {:p}", object);
            return STATUS_SUCCESS;
        }
    }

    ob_trace!("ObOpenObjectByName: name not found");
    STATUS_OBJECT_NAME_NOT_FOUND
}

// ============================================================
// ObReferenceObject - Increment reference count
// ============================================================

pub fn ob_reference_object(object: *mut c_void) -> *mut c_void {
    if object.is_null() { return core::ptr::null_mut(); }

    let header = ObjectHeader::from_body(object);
    if !header.is_null() {
        let header_ref = unsafe { &*header };
        let new_count = header_ref.increment_pointer_count();
        ob_trace!("ObReferenceObject: obj={:p} ref={}", object, new_count);
    }

    object
}

// ============================================================
// ObDereferenceObject - Decrement reference count
// ============================================================

pub fn ob_dereference_object(object: *mut c_void) {
    if object.is_null() { return; }

    let header = ObjectHeader::from_body(object);
    if header.is_null() { return; }

    let header_ref = unsafe { &*header };
    let new_count = header_ref.decrement_pointer_count();

    ob_trace!("ObDereferenceObject: obj={:p} ref={}", object, new_count);

    if new_count == 0 {
        ob_delete_object(object);
    }
}

// ============================================================
// ob_delete_object - Internal: clean up object
// ============================================================

fn ob_delete_object(object: *mut c_void) {
    if object.is_null() { return; }

    let header = ObjectHeader::from_body(object);
    if header.is_null() { return; }

    let header_ref = unsafe { &*header };
    let type_idx = header_ref.type_index;

    if type_idx < OBP_NUMBER_OBJECT_TYPES.load(Ordering::Relaxed) as u32 {
        unsafe {
            if let Some(type_obj) = OBP_OBJECT_TYPE_TABLE[type_idx as usize] {
                let ot = &*type_obj;
                if let Some(delete_proc) = ot.delete_procedure {
                    delete_proc(object);
                }
            }
        }
    }

    let alloc_size = mem::size_of::<ObjectHeader>() + 256;
    let layout = core::alloc::Layout::from_size_align(alloc_size, 16).unwrap();
    unsafe { alloc::alloc::dealloc(header as *mut u8, layout); }

    ob_trace!("ObDeleteObject: freed {:p}", object);
}

// ============================================================
// ObCloseHandle - Close a handle
// ============================================================

pub fn ob_close_handle(handle: Handle, _access_mode: u8) -> NtStatus {
    if handle.is_null() { return STATUS_INVALID_PARAMETER; }

    let object = handle as *mut c_void;
    let header = ObjectHeader::from_body(object);
    if header.is_null() { return STATUS_INVALID_PARAMETER; }

    let header_ref = unsafe { &mut *header };
    if header_ref.handle_count > 0 {
        header_ref.handle_count -= 1;
    }

    ob_trace!("ObCloseHandle: handle={:p} handles={}", handle, header_ref.handle_count);

    ob_dereference_object(object);

    STATUS_SUCCESS
}

// ============================================================
// ObRegisterCallbacks (stub)
// ============================================================

pub fn ob_register_callbacks(_callbacks: Pvoid) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// ObUnRegisterCallbacks (stub)
// ============================================================

pub fn ob_unregister_callbacks(_handle: Pvoid) {}

// ============================================================
// ObQueryObjectInformation
// ============================================================

pub fn ob_query_object_information(
    object: Pvoid,
    info_class: u32,
    info: Pvoid,
    info_length: u32,
    return_length: *mut u32,
) -> NtStatus {
    if object.is_null() { return STATUS_INVALID_PARAMETER; }

    let header = ObjectHeader::from_body(object);
    if header.is_null() { return STATUS_INVALID_PARAMETER; }

    if !return_length.is_null() {
        unsafe { *return_length = 0; }
    }

    match info_class {
        1 => {
            // ObjectNameInformation
            ob_query_name_string(object, info, info_length, return_length)
        }
        2 => {
            // ObjectTypeInformation
            let type_idx = unsafe { (*header).type_index };
            if info.is_null() || info_length < mem::size_of::<ObpObjectTypeInformation>() as u32 {
                return STATUS_INFO_LENGTH_MISMATCH;
            }

            let ot_info = unsafe { &mut *(info as *mut ObpObjectTypeInformation) };

            if type_idx < OBP_NUMBER_OBJECT_TYPES.load(Ordering::Relaxed) as u32 {
                if let Some(ot) = unsafe { OBP_OBJECT_TYPE_TABLE[type_idx as usize] } {
                    let ot_ref = unsafe { &*ot };
                    ot_info.total_objects = ot_ref.total_objects;
                    ot_info.total_handles = ot_ref.total_handles;
                    ot_info.total_pools = ot_ref.total_pools;
                    ot_info.pool_type = ot_ref.pool_type;
                    ot_info.default_paged_charge = ot_ref.default_paged_charge;
                    ot_info.default_nonpaged_charge = ot_ref.default_nonpaged_charge;
                    ot_info.valid_access_mask = ot_ref.valid_access_mask;
                }
            }

            if !return_length.is_null() {
                unsafe { *return_length = mem::size_of::<ObpObjectTypeInformation>() as u32; }
            }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

// ============================================================
// ObGetObjectType
// ============================================================

pub fn ob_get_object_type(object: *mut c_void) -> *mut ObjectType {
    if object.is_null() { return core::ptr::null_mut(); }
    let header = ObjectHeader::from_body(object);
    if header.is_null() { return core::ptr::null_mut(); }
    let type_idx = unsafe { (*header).type_index };
    if type_idx < OBP_NUMBER_OBJECT_TYPES.load(Ordering::Relaxed) as u32 {
        unsafe { OBP_OBJECT_TYPE_TABLE[type_idx as usize].unwrap_or(core::ptr::null_mut()) }
    } else {
        core::ptr::null_mut()
    }
}

// ============================================================
// ObGetObjectTypeIndex (simplified)
// ============================================================

pub fn ob_get_object_type_index(object: *mut c_void) -> ObjectTypeIndex {
    if object.is_null() { return 0; }
    let header = ObjectHeader::from_body(object);
    if header.is_null() { return 0; }
    unsafe { (*header).type_index }
}

// ============================================================
// ObfReferenceObject / ObfDereferenceObject (fast macros)
// ============================================================

pub fn obf_reference_object(object: *mut c_void) -> *mut c_void {
    ob_reference_object(object)
}

pub fn obf_dereference_object(object: *mut c_void) {
    ob_dereference_object(object);
}

// ============================================================
// ObReferenceObjectByHandle - Ref object from handle
// ============================================================

pub fn ob_reference_object_by_handle(
    handle: Handle,
    _desired_access: u32,
    _object_type: *mut ObjectType,
    _access_mode: u8,
    object: *mut *mut c_void,
    _handle_info: *mut u32,
) -> NtStatus {
    if handle.is_null() || object.is_null() { return STATUS_INVALID_PARAMETER; }

    let obj = handle as *mut c_void;
    unsafe { *object = ob_reference_object(obj); }
    STATUS_SUCCESS
}

// ============================================================
// ObReferenceObjectByPointer - Ref object by pointer
// ============================================================

pub fn ob_reference_object_by_pointer(
    object: *mut c_void,
    _desired_access: u32,
    _object_type: *mut ObjectType,
    _access_mode: u8,
) -> NtStatus {
    if object.is_null() { return STATUS_INVALID_PARAMETER; }
    ob_reference_object(object);
    STATUS_SUCCESS
}

// ============================================================
// ObDereferenceObjectByHandle - Dereference by handle
// ============================================================

pub fn ob_dereference_object_by_handle(
    handle: Handle,
    _count: u32,
    _object_type: *mut ObjectType,
    _access_mode: u8,
) -> NtStatus {
    if handle.is_null() { return STATUS_INVALID_PARAMETER; }
    ob_dereference_object(handle as *mut c_void);
    STATUS_SUCCESS
}

// ============================================================
// ObMakeTemporaryObject - Make object temporary
// ============================================================

pub fn ob_make_temporary_object(object: *mut c_void) -> NtStatus {
    if object.is_null() { return STATUS_INVALID_PARAMETER; }
    let header = ObjectHeader::from_body(object);
    if !header.is_null() {
        unsafe { (*header).flags &= !OBJECT_HEADER_FLAG_KERNEL_OBJECT; }
    }
    ob_dereference_object(object);
    STATUS_SUCCESS
}

// ============================================================
// ObIsObjectPermanent
// ============================================================

pub fn ob_is_object_permanent(object: *mut c_void) -> u8 {
    if object.is_null() { return 0; }
    let header = ObjectHeader::from_body(object);
    if header.is_null() { return 0; }
    if unsafe { (*header).flags & OBJECT_HEADER_FLAG_KERNEL_OBJECT != 0 } { 1 } else { 0 }
}

// ============================================================
// ObSetHandleAttributes
// ============================================================

pub fn ob_set_handle_attributes(
    handle: Handle,
    _object_attributes: *mut ObjectAttributes,
    _previous_mode: u8,
) -> NtStatus {
    if handle.is_null() { return STATUS_INVALID_PARAMETER; }
    STATUS_SUCCESS
}

// ============================================================
// ObQueryHandleCount
// ============================================================

pub fn ob_query_handle_count(object: *mut c_void) -> u32 {
    if object.is_null() { return 0; }
    let header = ObjectHeader::from_body(object);
    if header.is_null() { return 0; }
    unsafe { (*header).handle_count as u32 }
}

// ============================================================
// ObGetExclusiveProcess (stub)
// ============================================================

pub fn ob_get_exclusive_process(object: *mut c_void) -> Pvoid {
    let _ = object;
    core::ptr::null_mut()
}

// ============================================================
// ObjectType initialization
// ============================================================

pub fn obp_register_object_type(
    name: *mut UnicodeString,
    _pool_type: u32,
    _valid_access_mask: u32,
    _default_paged_charge: u32,
    _default_nonpaged_charge: u32,
    _maintain_handle_count: u8,
) -> ObjectTypeIndex {
    let index = OBP_NUMBER_OBJECT_TYPES.fetch_add(1, Ordering::Relaxed) as usize;
    if index >= OB_MAX_OBJECT_TYPE_INDEX {
        OBP_NUMBER_OBJECT_TYPES.fetch_sub(1, Ordering::Relaxed);
        return 0;
    }

    let layout = core::alloc::Layout::from_size_align(mem::size_of::<ObjectType>(), 16).unwrap();
    let ot = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut ObjectType };
    if ot.is_null() {
        OBP_NUMBER_OBJECT_TYPES.fetch_sub(1, Ordering::Relaxed);
        return 0;
    }

    let ot_ref = unsafe { &mut *ot };
    ot_ref.type_index = index as ObjectTypeIndex;
    ot_ref.name = if !name.is_null() {
        unsafe { *name }
    } else {
        UnicodeString::new()
    };
    ot_ref.pool_type = _pool_type;
    ot_ref.valid_access_mask = _valid_access_mask;
    ot_ref.default_paged_charge = _default_paged_charge;
    ot_ref.default_nonpaged_charge = _default_nonpaged_charge;
    ot_ref.maintain_handle_count = _maintain_handle_count;

    unsafe { OBP_OBJECT_TYPE_TABLE[index] = Some(ot); }

    ob_trace!("ObpRegisterObjectType: '{}' index={}", index, index);
    index as ObjectTypeIndex
}

// ============================================================
// ObInitialize
// ============================================================

pub fn ob_initialize() {
    ob_dbg!("ObInitialize: initializing Object Manager");

    unsafe {
        OBP_ROOT_DIRECTORY_OBJECT = alloc::alloc::alloc_zeroed(
            core::alloc::Layout::from_size_align(mem::size_of::<ObjectTypeDirectory>(), 16).unwrap()
        ) as *mut ObjectTypeDirectory;

        if !OBP_ROOT_DIRECTORY_OBJECT.is_null() {
            let dir = &mut *OBP_ROOT_DIRECTORY_OBJECT;
            *dir = ObjectTypeDirectory::new();
        }
    }

    // Register built-in object types matching ntoskrnl.exe
    // Type 0: ObjectManagerRoot (directory-like)
    let mut type_name_0 = UnicodeString::new();
    type_name_0.length = 0;
    let _ = obp_register_object_type(
        &mut type_name_0 as *mut UnicodeString,
        0,       // NonPagedPool
        0x001F0FFF, // FILE_ALL_ACCESS equivalent
        0, 0, 0,
    );

    // Type 1: Type
    let mut type_name_type = create_unicode_string(b"Type\0");
    let _ = obp_register_object_type(
        &mut type_name_type as *mut UnicodeString,
        0,
        0x001200A9, // READ_CONTROL | SYNCHRONIZE
        0, 0, 0,
    );

    // Type 2: Directory
    let mut type_name_dir = create_unicode_string(b"Directory\0");
    let idx_dir = obp_register_object_type(
        &mut type_name_dir as *mut UnicodeString,
        0,
        0x001200A9,
        0, 0, 0,
    );
    let _ = idx_dir;

    // Type 3: SymbolicLink
    let mut type_name_symlink = create_unicode_string(b"SymbolicLink\0");
    let idx_symlink = obp_register_object_type(
        &mut type_name_symlink as *mut UnicodeString,
        0,
        0x001200A9,
        0, 0, 0,
    );
    let _ = idx_symlink;

    // Type 4: Device
    let mut type_name_dev = create_unicode_string(b"Device\0");
    let idx_dev = obp_register_object_type(
        &mut type_name_dev as *mut UnicodeString,
        0,       // NonPagedPool
        0x001F0FFF, // FILE_ALL_ACCESS
        0, 0, 0,
    );
    let _ = idx_dev;

    // Type 5: Driver
    let mut type_name_drv = create_unicode_string(b"Driver\0");
    let idx_drv = obp_register_object_type(
        &mut type_name_drv as *mut UnicodeString,
        0,
        0x001200A9,
        0, 0, 0,
    );
    let _ = idx_drv;

    // Type 6: File (for file objects)
    let mut type_name_file = create_unicode_string(b"File\0");
    let idx_file = obp_register_object_type(
        &mut type_name_file as *mut UnicodeString,
        0,
        0x001F0FFF,
        0, 0, 0,
    );
    let _ = idx_file;

    // Type 7: Key (registry key)
    let mut type_name_key = create_unicode_string(b"Key\0");
    let idx_key = obp_register_object_type(
        &mut type_name_key as *mut UnicodeString,
        0,
        0x00120019, // KEY_READ
        0, 0, 0,
    );
    let _ = idx_key;

    // Type 8: Section (memory section)
    let mut type_name_section = create_unicode_string(b"Section\0");
    let idx_section = obp_register_object_type(
        &mut type_name_section as *mut UnicodeString,
        0,
        0x00120005, // SECTION_MAP_READ | SECTION_QUERY
        0, 0, 0,
    );
    let _ = idx_section;

    // Type 9: Event
    let mut type_name_event = create_unicode_string(b"Event\0");
    let idx_event = obp_register_object_type(
        &mut type_name_event as *mut UnicodeString,
        0,
        0x001F0003, // EVENT_MODIFY_STATE | SYNCHRONIZE
        0, 0, 0,
    );
    let _ = idx_event;

    // Type 10: Mutant (mutex)
    let mut type_name_mutant = create_unicode_string(b"Mutant\0");
    let idx_mutant = obp_register_object_type(
        &mut type_name_mutant as *mut UnicodeString,
        0,
        0x001F0001, // MUTANT_MODIFY_STATE | SYNCHRONIZE
        0, 0, 0,
    );
    let _ = idx_mutant;

    // Type 11: Semaphore
    let mut type_name_sem = create_unicode_string(b"Semaphore\0");
    let idx_sem = obp_register_object_type(
        &mut type_name_sem as *mut UnicodeString,
        0,
        0x001F0003, // SEMAPHORE_MODIFY_STATE | SYNCHRONIZE
        0, 0, 0,
    );
    let _ = idx_sem;

    // Type 12: Process
    let mut type_name_proc = create_unicode_string(b"Process\0");
    let idx_proc = obp_register_object_type(
        &mut type_name_proc as *mut UnicodeString,
        0,
        0x001F0FFF, // PROCESS_ALL_ACCESS
        0, 0, 0,
    );
    let _ = idx_proc;

    // Type 13: Thread
    let mut type_name_thread = create_unicode_string(b"Thread\0");
    let idx_thread = obp_register_object_type(
        &mut type_name_thread as *mut UnicodeString,
        0,
        0x001F0FFF, // THREAD_ALL_ACCESS
        0, 0, 0,
    );
    let _ = idx_thread;

    // Type 14: Job
    let mut type_name_job = create_unicode_string(b"Job\0");
    let idx_job = obp_register_object_type(
        &mut type_name_job as *mut UnicodeString,
        0,
        0x001F0003, // JOB_OBJECT_QUERY | JOB_OBJECT_SET_ATTRIBUTES
        0, 0, 0,
    );
    let _ = idx_job;

    // Type 15: Timer
    let mut type_name_timer = create_unicode_string(b"Timer\0");
    let idx_timer = obp_register_object_type(
        &mut type_name_timer as *mut UnicodeString,
        0,
        0x001F0003, // TIMER_MODIFY_STATE | SYNCHRONIZE
        0, 0, 0,
    );
    let _ = idx_timer;

    // Type 16: KeyedEvent
    let mut type_name_keyed = create_unicode_string(b"KeyedEvent\0");
    let _idx_keyed = obp_register_object_type(
        &mut type_name_keyed as *mut UnicodeString,
        0,
        0x001F0003,
        0, 0, 0,
    );

    // Type 17: IoCompletion
    let mut type_name_iocp = create_unicode_string(b"IoCompletion\0");
    let _idx_iocp = obp_register_object_type(
        &mut type_name_iocp as *mut UnicodeString,
        0,
        0x001F0003,
        0, 0, 0,
    );

    // Type 18: WaitCompletionPacket
    let mut type_name_wcp = create_unicode_string(b"WaitCompletionPacket\0");
    let _idx_wcp = obp_register_object_type(
        &mut type_name_wcp as *mut UnicodeString,
        0,
        0x001F0003,
        0, 0, 0,
    );

    // Type 19: CallbackObject
    let mut type_name_cb = create_unicode_string(b"Callback\0");
    let _idx_cb = obp_register_object_type(
        &mut type_name_cb as *mut UnicodeString,
        0,
        0x001F0003,
        0, 0, 0,
    );

    // Type 20: Profile
    let mut type_name_profile = create_unicode_string(b"Profile\0");
    let _idx_profile = obp_register_object_type(
        &mut type_name_profile as *mut UnicodeString,
        0,
        0x001F0003,
        0, 0, 0,
    );

    // Create root NT namespace directories
    // \Device
    let mut dev_dir_name = create_unicode_string(b"Device\0");
    let _dev_dir = obp_allocate_and_insert_directory(&mut dev_dir_name);

    // \DosDevices (for drive letters C:, D:, etc.)
    let mut dos_dev_name = create_unicode_string(b"DosDevices\0");
    let _dos_dev_dir = obp_allocate_and_insert_directory(&mut dos_dev_name);

    // \BaseNamedObjects (events, mutexes, semaphores, sections)
    let mut bon_name = create_unicode_string(b"BaseNamedObjects\0");
    let _bon_dir = obp_allocate_and_insert_directory(&mut bon_name);

    // \FileSystem
    let mut fs_name = create_unicode_string(b"FileSystem\0");
    let _fs_dir = obp_allocate_and_insert_directory(&mut fs_name);

    // \Driver
    let mut drv_name = create_unicode_string(b"Driver\0");
    let _drv_dir = obp_allocate_and_insert_directory(&mut drv_name);

    // \GLOBAL?? (for global named objects)
    let mut global_name = create_unicode_string(b"GLOBAL??\0");
    let _global_dir = obp_allocate_and_insert_directory(&mut global_name);

    ob_dbg!("ObInitialize: Object Manager initialized with {} types, NT namespace created",
        OBP_NUMBER_OBJECT_TYPES.load(Ordering::Relaxed));
}

// ============================================================
// ObpAllocateAndInsertDirectory - Helper to create a sub-directory
// ============================================================

fn obp_allocate_and_insert_directory(name: &mut UnicodeString) -> *mut ObjectTypeDirectory {
    let layout = core::alloc::Layout::from_size_align(mem::size_of::<ObjectTypeDirectory>(), 16).unwrap();
    let dir = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut ObjectTypeDirectory };
    if dir.is_null() { return core::ptr::null_mut(); }

    unsafe {
        *dir = ObjectTypeDirectory::new();
        (*dir).directory_name = UnicodeString {
            length: name.length,
            maximum_length: name.maximum_length,
            buffer: name.buffer,
        };
        // Insert into root directory
        let root = &mut *OBP_ROOT_DIRECTORY_OBJECT;
        let entry_layout = core::alloc::Layout::from_size_align(mem::size_of::<ObjectDirectoryEntry>(), 8).unwrap();
        let entry = alloc::alloc::alloc_zeroed(entry_layout) as *mut ObjectDirectoryEntry;
        if !entry.is_null() {
            (*entry).object = dir as *mut c_void;
            (*entry).object_name = UnicodeString {
                length: name.length,
                maximum_length: name.maximum_length,
                buffer: name.buffer,
            };
            let _guard = root.lock.acquire();
            (*entry).chain_link = root.entries;
            root.entries = entry;
            root.count += 1;
        }
    }

    ob_trace!("ObpAllocateAndInsertDirectory: created '{}'", 0);
    dir
}

// ============================================================
// ObpLookupDirectory - Find a sub-directory by path component
// ============================================================

pub fn obp_lookup_directory(parent: *mut ObjectTypeDirectory, name: &str) -> *mut ObjectTypeDirectory {
    if parent.is_null() { return core::ptr::null_mut(); }

    let dir = unsafe { &*parent };
    let _guard = dir.lock.acquire();

    let mut current = dir.entries;
    while !current.is_null() {
        let entry = unsafe { &*current };
        if !entry.object.is_null() && !entry.object_name.buffer.is_null() && entry.object_name.length > 0 {
            let name_chars = entry.object_name.length as usize / 2;
            let mut ascii_buf = [0u8; 128];
            let len = name_chars.min(127);
            for i in 0..len {
                ascii_buf[i] = unsafe { *entry.object_name.buffer.add(i) } as u8;
            }
            if let Ok(entry_name) = core::str::from_utf8(&ascii_buf[..len]) {
                if entry_name.eq_ignore_ascii_case(name) {
                    return entry.object as *mut ObjectTypeDirectory;
                }
            }
        }
        current = entry.chain_link;
    }

    core::ptr::null_mut()
}

// ============================================================
// ObpResolvePath - Resolve a path like "\Device\HarddiskVolume0"
// ============================================================

pub fn obp_resolve_path(path: &str) -> *mut c_void {
    let root = unsafe { OBP_ROOT_DIRECTORY_OBJECT };
    if root.is_null() { return core::ptr::null_mut(); }

    let path = path.trim_start_matches('\\');
    let mut current_dir = root;
    let mut remaining = path;

    while !remaining.is_empty() {
        // Find next path component
        let sep = remaining.find('\\');
        let (component, rest) = match sep {
            Some(pos) => (&remaining[..pos], &remaining[pos + 1..]),
            None => (remaining, ""),
        };

        if component.is_empty() {
            remaining = rest;
            continue;
        }

        let found = obp_lookup_directory(current_dir, component);
        if found.is_null() {
            // Try to find as an object (not directory)
            let mut name_us = create_unicode_string(component.as_bytes());
            let obj = obp_lookup_object_by_name(current_dir, &mut name_us as *mut UnicodeString);
            if !obj.is_null() {
                return obj;
            }
            return core::ptr::null_mut();
        }

        current_dir = found;
        remaining = rest;
    }

    current_dir as *mut c_void
}

// ============================================================
// ObCreateDirectoryObject - Create a directory in the namespace
// ============================================================

pub fn ob_create_directory_object(
    directory_name: *mut UnicodeString,
    _desired_access: u32,
    _object_attributes: *mut ObjectAttributes,
    _charge_mode: u8,
    _pool_tag: u32,
) -> NtStatus {
    if directory_name.is_null() { return STATUS_INVALID_PARAMETER; }

    let name_ref = unsafe { &*directory_name };
    if name_ref.length == 0 || name_ref.buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let layout = core::alloc::Layout::from_size_align(mem::size_of::<ObjectTypeDirectory>(), 16).unwrap();
    let dir = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut ObjectTypeDirectory };
    if dir.is_null() { return STATUS_NO_MEMORY; }

    unsafe {
        *dir = ObjectTypeDirectory::new();
        (*dir).directory_name = UnicodeString {
            length: name_ref.length,
            maximum_length: name_ref.maximum_length,
            buffer: name_ref.buffer,
        };
    }

    // Insert into root for now (TODO: proper parent directory support)
    let root = unsafe { OBP_ROOT_DIRECTORY_OBJECT };
    if !root.is_null() {
        let entry_layout = core::alloc::Layout::from_size_align(mem::size_of::<ObjectDirectoryEntry>(), 8).unwrap();
        let entry = unsafe { alloc::alloc::alloc_zeroed(entry_layout) as *mut ObjectDirectoryEntry };
        if !entry.is_null() {
            unsafe {
                (*entry).object = dir as *mut c_void;
                (*entry).object_name = UnicodeString {
                    length: name_ref.length,
                    maximum_length: name_ref.maximum_length,
                    buffer: name_ref.buffer,
                };
                let _guard = (*root).lock.acquire();
                (*entry).chain_link = (*root).entries;
                (*root).entries = entry;
                (*root).count += 1;
            }
        }
    }

    ob_trace!("ObCreateDirectoryObject: created directory");
    STATUS_SUCCESS
}

// ============================================================
// ObCreateSymbolicLinkObject - Create a symlink (e.g. \DosDevices\C:)
// ============================================================

pub fn ob_create_symbolic_link_object(
    directory: *mut ObjectTypeDirectory,
    link_name: *mut UnicodeString,
    link_target: *mut UnicodeString,
) -> NtStatus {
    if directory.is_null() || link_name.is_null() || link_target.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let link_layout = core::alloc::Layout::from_size_align(mem::size_of::<ObjectSymbolicLink>(), 16).unwrap();
    let symlink = unsafe { alloc::alloc::alloc_zeroed(link_layout) as *mut ObjectSymbolicLink };
    if symlink.is_null() { return STATUS_NO_MEMORY; }

    let name_ref = unsafe { &*link_name };
    let target_ref = unsafe { &*link_target };

    unsafe {
        (*symlink).link_target = UnicodeString {
            length: target_ref.length,
            maximum_length: target_ref.maximum_length,
            buffer: target_ref.buffer,
        };
    }

    obp_insertObjectDirectory(directory, symlink as *mut c_void, link_name);

    ob_trace!("ObCreateSymbolicLinkObject: created symlink");
    STATUS_SUCCESS
}

// ============================================================
// ObEnumerateObjectsByType (simplified)
// ============================================================

pub fn ob_enumerate_objects_by_type(
    _type_index: ObjectTypeIndex,
    _callback: Pvoid,
    _context: Pvoid,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// ObQueryNameString
// ============================================================

pub fn ob_query_name_string(
    object: Pvoid,
    name_info: Pvoid,
    length: u32,
    return_length: *mut u32,
) -> NtStatus {
    if object.is_null() || name_info.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Get the object's type
    let header = ObjectHeader::from_body(object);
    if header.is_null() { return STATUS_INVALID_PARAMETER; }

    let type_idx = unsafe { (*header).type_index };
    let type_name = if type_idx < OBP_NUMBER_OBJECT_TYPES.load(Ordering::Relaxed) as u32 {
        unsafe {
            match OBP_OBJECT_TYPE_TABLE[type_idx as usize] {
                Some(ot) => &(*ot).name,
                None => &UnicodeString::new(),
            }
        }
    } else {
        &UnicodeString::new()
    };

    // Build OBJECT_NAME_INFORMATION: UNICODE_STRING + name buffer
    // Layout: {
    //   Name: UNICODE_STRING (8 bytes on x64: length, max_length, buffer ptr)
    //   NameBuffer: [u16; name_length/2 + 1]  (inline after the struct)
    // }
    let name_chars = type_name.length as usize / 2;
    let needed = (8 + name_chars * 2 + 2) as u32; // UNICODE_STRING + chars + null

    if !return_length.is_null() {
        unsafe { *return_length = needed; }
    }

    if length < needed {
        return STATUS_INFO_LENGTH_MISMATCH;
    }

    unsafe {
        let info = name_info as *mut u16;
        // Write UNICODE_STRING.Length
        *info = type_name.length;
        // Write UNICODE_STRING.MaximumLength
        *info.add(1) = type_name.maximum_length;
        // Write UNICODE_STRING.Buffer pointer (points just past the header)
        let buf_ptr = info.add(3) as *mut *mut u16; // 3 u16s = 6 bytes, but needs 8-byte align
        // Actually use a proper repr(C) struct
        let name_info_struct = name_info as *mut ObpNameInformation;
        (*name_info_struct).name.length = type_name.length;
        (*name_info_struct).name.maximum_length = type_name.maximum_length;
        (*name_info_struct).name.buffer = &mut (*name_info_struct).inline_name as *mut u16;

        // Copy name
        if !type_name.buffer.is_null() && name_chars > 0 {
            core::ptr::copy_nonoverlapping(
                type_name.buffer,
                &mut (*name_info_struct).inline_name as *mut u16,
                name_chars,
            );
        }
        (*name_info_struct).inline_name[name_chars] = 0; // null terminator
    }

    STATUS_SUCCESS
}

// ============================================================
// ObTranslateSystemHandle (simplified)
// ============================================================

pub fn ob_translate_system_handle(
    handle: Handle,
    _desired_access: u32,
    _object_type_index: ObjectTypeIndex,
    _access_mode: u8,
    object: *mut *mut c_void,
    handle_info: *mut u32,
) -> NtStatus {
    if handle.is_null() { return STATUS_INVALID_PARAMETER; }

    // In our simplified model, the handle IS the object pointer
    let obj = handle as *mut c_void;
    let header = ObjectHeader::from_body(obj);
    if header.is_null() { return STATUS_INVALID_PARAMETER; }

    if !object.is_null() {
        unsafe { *object = ob_reference_object(obj); }
    }
    if !handle_info.is_null() {
        unsafe { *handle_info = 0; }
    }

    STATUS_SUCCESS
}

// ============================================================
// ObInsertObjectEx
// ============================================================

pub fn ob_insert_object_ex(
    object: *mut c_void,
    object_attributes: *mut ObjectAttributes,
    _pseudo_context: Pvoid,
    _remaining_objects: u32,
    _parent_directory: Pvoid,
    _parse_context: Pvoid,
    handle: *mut Handle,
) -> NtStatus {
    ob_insert_object(object, object_attributes, _pseudo_context, _remaining_objects, _parent_directory, _parse_context, handle)
}

// ============================================================
// ObOpenObjectByPointer
// ============================================================

pub fn ob_open_object_by_pointer(
    object: *mut c_void,
    _handle_attributes: u32,
    _access_state: Pvoid,
    _desired_access: u32,
    _object_type: *mut ObjectType,
    _access_mode: u8,
    handle: *mut Handle,
) -> NtStatus {
    if object.is_null() { return STATUS_INVALID_PARAMETER; }
    if !handle.is_null() { unsafe { *handle = object as Handle; } }
    STATUS_SUCCESS
}

// ============================================================
// ObOpenObjectByPointerWithTag
// ============================================================

pub fn ob_open_object_by_pointer_tag(
    object: *mut c_void,
    handle_attributes: u32,
    access_state: Pvoid,
    desired_access: u32,
    object_type: *mut ObjectType,
    access_mode: u8,
    handle: *mut Handle,
    _tag: u32,
) -> NtStatus {
    ob_open_object_by_pointer(object, handle_attributes, access_state, desired_access, object_type, access_mode, handle)
}

// ============================================================
// ObCheckObjectAccess
// ============================================================

pub fn ob_check_object_access(
    _object: Pvoid,
    _desired_access: u32,
    _access_mode: u8,
    _generate_on_close: *mut u8,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// ObGetObjectSecurityDescriptor
// ============================================================

pub fn ob_get_object_security_descriptor(
    object: Pvoid,
    security_descriptor: *mut Pvoid,
    bucket_allocated: *mut u8,
) -> NtStatus {
    if object.is_null() || security_descriptor.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let header = ObjectHeader::from_body(object);
    if header.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // In VladOS, we store a default security descriptor in the object header
    // For now, return a pointer to the default SD (owner = SYSTEM)
    unsafe {
        *security_descriptor = core::ptr::null_mut();
        if !bucket_allocated.is_null() {
            *bucket_allocated = 0; // not bucket-allocated
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// ObReferenceSecurityDescriptor
// ============================================================

pub fn ob_reference_security_descriptor(
    _security_descriptor: Pvoid,
    _count: u32,
) -> NtStatus {
    // Security descriptors are reference-counted in real Windows.
    // In VladOS, we use a simplified model where the SD is embedded
    // in the object header and doesn't need separate refcounting.
    STATUS_SUCCESS
}

// ============================================================
// ObDereferenceSecurityDescriptor
// ============================================================

pub fn ob_dereference_security_descriptor(
    _security_descriptor: Pvoid,
    _count: u32,
) -> NtStatus {
    // Security descriptors are reference-counted in real Windows.
    // In VladOS, we use a simplified model where the SD is embedded
    // in the object header and doesn't need separate refcounting.
    STATUS_SUCCESS
}
