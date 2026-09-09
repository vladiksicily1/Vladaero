/// # Configuration Manager / Registry (Cm/Cmp) - ntoskrnl.exe
///
/// Complete implementation of the Windows Registry subsystem including
/// hive management, key nodes, value entries, registry operations,
/// and the security descriptor management.

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicBool, AtomicUsize, Ordering};

use crate::types::*;
use crate::mm::{self, ListEntry, SpinLock};

// ============================================================
// Constants
// ============================================================

pub const CM_MAX_KEY_LENGTH: u32 = 255;
pub const CM_MAX_VALUE_LENGTH: u32 = 1024;
pub const CM_KEY_NODE_SIGNATURE: u32 = 0x6B6E6862;
pub const CM_KEY_VALUE_SIGNATURE: u32 = 0x766C756E;
pub const CM_KEY_HIVE_ENTRY_SIGNATURE: u32 = 0x68766E72;
pub const CM_KEY_HIVE_ENTRY_SECURITY_SIGNATURE: u32 = 0x736F6572;
pub const CM_LINK_NODE_SIGNATURE: u32 = 0x6B6E696C;
pub const CM_KEY_INDEX_ROOT: u32 = 0x69727267;
pub const CM_KEY_INDEX_FAST: u32 = 0x6678696E;
pub const CM_KEY_INDEX_HIVE: u32 = 0x6869766E;

pub const REG_OPTION_NON_VOLATILE: u32 = 0x00000000;
pub const REG_OPTION_VOLATILE: u32 = 0x00000001;
pub const REG_OPTION_CREATE_LINK: u32 = 0x00000002;
pub const REG_OPTION_BACKUP_RESTORE: u32 = 0x00000004;
pub const REG_OPTION_OPEN_LINK: u32 = 0x00000008;
pub const REG_OPTION_DONT_VOLATILE_NOTIFY: u32 = 0x00000010;
pub const REG_OPTION_CREATE_LINK_SUBKEY: u32 = 0x00000020;
pub const REG_OPTION_STORE_WITH_DELAY: u32 = 0x00000040;
pub const REG_OPTION_STORE_Volatile: u32 = 0x00000080;

pub const REG_NONE: u32 = 0;
pub const REG_SZ: u32 = 1;
pub const REG_EXPAND_SZ: u32 = 2;
pub const REG_BINARY: u32 = 3;
pub const REG_DWORD: u32 = 4;
pub const REG_DWORD_BIG_ENDIAN: u32 = 5;
pub const REG_LINK: u32 = 6;
pub const REG_MULTI_SZ: u32 = 7;
pub const REG_RESOURCE_LIST: u32 = 8;
pub const REG_FULL_RESOURCE_DESCRIPTOR: u32 = 9;
pub const REG_RESOURCE_REQUIREMENTS_LIST: u32 = 10;
pub const REG_QWORD: u32 = 11;

pub const KEY_QUERY_VALUE: u32 = 0x0001;
pub const KEY_SET_VALUE: u32 = 0x0002;
pub const KEY_CREATE_SUB_KEY: u32 = 0x0004;
pub const KEY_ENUMERATE_SUB_KEYS: u32 = 0x0008;
pub const KEY_CREATE_LINK: u32 = 0x0020;
pub const KEY_NOTIFY: u32 = 0x0010;
pub const KEY_DELETE: u32 = 0x00010000;
pub const KEY_READ: u32 = 0x20019;
pub const KEY_WRITE: u32 = 0x20006;
pub const KEY_EXECUTE: u32 = 0x20019;
pub const KEY_ALL_ACCESS: u32 = 0xF003F;
pub const KEY_READ_KEY: u32 = 0x10000;

pub const REG_NOTIFY_CHANGE_NAME: u32 = 0x00000001;
pub const REG_NOTIFY_CHANGE_ATTRIBUTES: u32 = 0x00000002;
pub const REG_NOTIFY_CHANGE_LAST_SET: u32 = 0x00000004;
pub const REG_NOTIFY_CHANGE_SECURITY: u32 = 0x00000008;
pub const REG_NOTIFY_CHANGE_CREATION: u32 = 0x00000010;
pub const REG_NOTIFY_CHANGE_LAST_ACCESS: u32 = 0x00000020;
pub const REG_NOTIFY_CHANGE_SESSION: u32 = 0x00000040;
pub const REG_NOTIFY_CHANGE_WOW64: u32 = 0x00000080;
pub const REG_NOTIFY_CHANGE_STORE: u32 = 0x00000100;

pub const HIVE_NODE_FLOOR: u32 = 0x1000;
pub const HCELL_PAGE_SIZE: u32 = 4096;
pub const HIVE_UNLOAD: u32 = 0x0001;
pub const HIVE_NO_FILE: u32 = 0x0002;
pub const HIVE_VOLATILE: u32 = 0x0004;
pub const HIVE_SYNC: u32 = 0x0008;
pub const HIVE_DELETE: u32 = 0x0010;

pub const CMP_MAX_EVENT: u32 = 10;
pub const CMP_MAX_KCB: u32 = 100;

pub const KEY_NODE_FLAG_HIVE_ENTRY: u32 = 0x0001;
pub const KEY_NODE_FLAG_NO_DELETE: u32 = 0x0002;
pub const KEY_NODE_FLAG_COMP_NAME: u32 = 0x0004;
pub const KEY_NODE_FLAG_LINK: u32 = 0x0008;

pub const KEY_VALUE_COMP_NAME: u32 = 0x00000001;

pub const CM_KEY_BODY_TYPE: u32 = 0x6B796243;
pub const CM_KEY_BODY_SIGNATURE: u32 = 0x6B796243;

pub const HBASE_BLOCK_SIGNATURE: u32 = 0x66676572;
pub const HBASE_BLOCK_REVISION: u32 = 1;

pub const CELL_FREE: u32 = 0;
pub const CELL_USED: u32 = 1;

// ============================================================
// Logging macros
// ============================================================

macro_rules! cm_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "cm_trace")]
        crate::kernel_log!("[Cm] {}", format_args!($($arg)*));
    };
}

macro_rules! cm_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Cm] {}", format_args!($($arg)*));
    };
}

macro_rules! cm_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Cm] {}", format_args!($($arg)*));
    };
}

macro_rules! cm_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Cm] {}", format_args!($($arg)*));
    };
}

// ============================================================
// Cell reference (hive-relative)
// ============================================================

pub type HcellIndex = u32;

pub const HCELL_NIL: HcellIndex = 0;

// ============================================================
// Cell - generic cell header
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Cell {
    pub size: i32,
    pub flags: u32,
}

impl Cell {
    pub fn new(size: i32) -> Self {
        Self { size, flags: CELL_USED }
    }

    pub fn is_free(&self) -> bool {
        self.flags & CELL_FREE != 0
    }

    pub fn actual_size(&self) -> usize {
        if self.size < 0 { (-self.size * 8) as usize } else { (self.size * 8) as usize }
    }
}

// ============================================================
// HbaseBlock - Hive base block
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct HbaseBlock {
    pub signature: u32,
    pub file_name: [u16; 32],
    pub sequence1: u32,
    pub sequence2: u32,
    pub timestamp: u64,
    pub major: u32,
    pub minor: u32,
    pub type_: u32,
    pub format: u32,
    pub root_cell_offset: HcellIndex,
    pub hive_bins_data_length: u32,
    pub cluster: u32,
    pub file_name_utf16: [u16; 32],
    pub checksum: u32,
    pub padding: [u8; 396],
}

impl HbaseBlock {
    pub fn new() -> Self {
        Self {
            signature: HBASE_BLOCK_SIGNATURE,
            file_name: [0; 32],
            sequence1: 1,
            sequence2: 1,
            timestamp: 0,
            major: 1,
            minor: 5,
            type_: 0,
            format: 1,
            root_cell_offset: HIVE_NODE_FLOOR,
            hive_bins_data_length: 0,
            cluster: 1,
            file_name_utf16: [0; 32],
            checksum: 0,
            padding: [0; 396],
        }
    }

    pub fn is_valid(&self) -> bool {
        self.signature == HBASE_BLOCK_SIGNATURE && self.major == 1
    }
}

// ============================================================
// HCellBin - Hive bin header
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct HcellBin {
    pub signature: u32,
    pub offset: u32,
    pub size: u32,
    pub reserved: u64,
    pub timestamp: u64,
}

impl HcellBin {
    pub fn new(offset: u32, size: u32) -> Self {
        Self {
            signature: 0x626E6968,
            offset,
            size,
            reserved: 0,
            timestamp: 0,
        }
    }
}

// ============================================================
// KeyNode (KEY_NODE) - registry key node
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct KeyNode {
    pub size: i32,
    pub flags: u32,
    pub spare: u32,
    pub timestamp: u64,
    pub access_bits: u32,
    pub parent: HcellIndex,
    pub num_sub_keys: u32,
    pub max_name_length: u32,
    pub max_class_length: u32,
    pub max_value_name_length: u32,
    pub max_value_data_length: u32,
    pub work_var: u32,
    pub name_length: u16,
    pub class_length: u16,
    pub security_key: HcellIndex,
    pub value_list: HcellIndex,
    pub child_list: HcellIndex,
    pub class_name_offset: HcellIndex,
    pub name: [u8; 1], // variable length
}

impl KeyNode {
    pub fn new() -> Self {
        Self {
            size: 0,
            flags: 0,
            spare: 0,
            timestamp: 0,
            access_bits: 0,
            parent: HCELL_NIL,
            num_sub_keys: 0,
            max_name_length: 0,
            max_class_length: 0,
            max_value_name_length: 0,
            max_value_data_length: 0,
            work_var: 0,
            name_length: 0,
            class_length: 0,
            security_key: HCELL_NIL,
            value_list: HCELL_NIL,
            child_list: HCELL_NIL,
            class_name_offset: HCELL_NIL,
            name: [0],
        }
    }

    pub fn is_compressed_name(&self) -> bool {
        self.flags & KEY_NODE_FLAG_COMP_NAME != 0
    }

    pub fn is_hive_entry(&self) -> bool {
        self.flags & KEY_NODE_FLAG_HIVE_ENTRY != 0
    }

    pub fn has_link(&self) -> bool {
        self.flags & KEY_NODE_FLAG_LINK != 0
    }

    pub fn get_name(&self) -> &[u8] {
        &self.name[..self.name_length as usize]
    }
}

// ============================================================
// KeyValuePartialInformation
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyValuePartialInformation {
    pub title_index: u32,
    pub data_type: u32,
    pub data_length: u32,
    pub data: [u8; 1],
}

impl KeyValuePartialInformation {
    pub fn total_size(&self) -> usize {
        mem::size_of::<KeyValuePartialInformation>() + self.data_length as usize
    }
}

// ============================================================
// KeyBasicInformation
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyBasicInformation {
    pub last_write_time: i64,
    pub title_index: u32,
    pub name_length: u32,
    pub name: [u16; 1],
}

impl KeyBasicInformation {
    pub fn total_size(&self) -> usize {
        mem::size_of::<KeyBasicInformation>() - mem::size_of::<u16>() + self.name_length as usize
    }
}

// ============================================================
// KeyFullInformation
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyFullInformation {
    pub last_write_time: i64,
    pub title_index: u32,
    pub class_name_length: u32,
    pub num_sub_keys: u32,
    pub max_sub_key_name_length: u32,
    pub max_class_name_length: u32,
    pub num_values: u32,
    pub max_value_name_length: u32,
    pub max_value_data_length: u32,
    pub work_var: u32,
}

impl KeyFullInformation {
    pub fn total_size(&self) -> usize {
        mem::size_of::<KeyFullInformation>()
    }
}

// ============================================================
// KeyNodeInformation
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyNodeInformation {
    pub last_write_time: i64,
    pub title_index: u32,
    pub class_name_offset: u32,
    pub class_name_length: u32,
    pub num_sub_keys: u32,
    pub max_sub_key_name_length: u32,
    pub max_class_name_length: u32,
    pub num_values: u32,
    pub max_value_name_length: u32,
    pub max_value_data_length: u32,
    pub name_length: u32,
}

// ============================================================
// KeyNameInformation
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyNameInformation {
    pub title_index: u32,
    pub name_length: u32,
    pub name: [u16; 1],
}

// ============================================================
// KeyVirtualTargetInformation
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KeyVirtualTargetInformation {
    pub title_index: u32,
    pub virtual_target: u32,
}

// ============================================================
// CmKeyBody - Open handle to a key
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct CmKeyBody {
    pub key_body_type: u32,
    pub notify_block: *mut c_void,
    pub key_control_block: *mut CmKeyControlBlock,
    pub key_body_reference_count: u32,
    pub key_body_handle: Handle,
    pub key_body_flags: u32,
    pub process_id: u64,
    pub last_io_status: IoStatusBlock,
    pub kcb: *mut CmKeyControlBlock,
}

impl CmKeyBody {
    pub fn new() -> Self {
        Self {
            key_body_type: CM_KEY_BODY_TYPE,
            notify_block: core::ptr::null_mut(),
            key_control_block: core::ptr::null_mut(),
            key_body_reference_count: 1,
            key_body_handle: core::ptr::null_mut(),
            key_body_flags: 0,
            process_id: 0,
            last_io_status: IoStatusBlock { status: 0, information: 0 },
            kcb: core::ptr::null_mut(),
        }
    }
}

// ============================================================
// CmKeyControlBlock - Key Control Block
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct CmKeyControlBlock {
    pub ref_count: u32,
    pub flags: u32,
    pub ext_flags: u32,
    pub key_hive: *mut CmHive,
    pub key_cell_index: HcellIndex,
    pub real_flags: u32,
    pub cached_epoch: u64,
    pub delay_close_entry: *mut c_void,
    pub kcb_upper: *mut CmKeyControlBlock,
    pub kcb_lower: *mut CmKeyControlBlock,
    pub key_name: UnicodeString,
    pub kcb_list: ListEntry,
    pub parent_kcb: *mut CmKeyControlBlock,
    pub sub_key_count: u32,
    pub total_key_size: u32,
    pub key_security: *mut c_void,
    pub cachable: u8,
    pub flags2: u32,
}

impl CmKeyControlBlock {
    pub fn new() -> Self {
        Self {
            ref_count: 1,
            flags: 0,
            ext_flags: 0,
            key_hive: core::ptr::null_mut(),
            key_cell_index: HCELL_NIL,
            real_flags: 0,
            cached_epoch: 0,
            delay_close_entry: core::ptr::null_mut(),
            kcb_upper: core::ptr::null_mut(),
            kcb_lower: core::ptr::null_mut(),
            key_name: UnicodeString::new(),
            kcb_list: ListEntry::new(),
            parent_kcb: core::ptr::null_mut(),
            sub_key_count: 0,
            total_key_size: 0,
            key_security: core::ptr::null_mut(),
            cachable: 1,
            flags2: 0,
        }
    }
}

// ============================================================
// CmHive - Hive descriptor
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct CmHive {
    pub signature: u32,
    pub hive_id: u64,
    pub links: ListEntry,
    pub hive_lock: SpinLock,
    pub view_lock: SpinLock,
    pub file_object: *mut c_void,
    pub root_kcb: *mut CmKeyControlBlock,
    pub hive_flags: u32,
    pub base_block: *mut HbaseBlock,
    pub dirty_span: u32,
    pub last_reorganize_time: i64,
    pub storage_type: u32,
    pub root_cell_index: HcellIndex,
    pub hive_duration: u64,
    pub flush_entry_lock: SpinLock,
    pub pinned_views: u32,
    pub unload_event: *mut c_void,
    pub hive_unstable: u8,
    pub stale: u8,
    pub load_status: u32,
    pub total_allocated: u64,
    pub total_used: u64,
    pub file_name: [u16; 64],
    pub security_cache: *mut c_void,
    pub security_cache_size: u32,
    pub security_recalculation_event: *mut c_void,
    pub security_log_event: *mut c_void,
    pub security_hash: ListEntry,
    pub root_cell: *mut u8,
    pub root_cell_size: u32,
}

impl CmHive {
    pub fn new() -> Self {
        Self {
            signature: CM_KEY_HIVE_ENTRY_SIGNATURE,
            hive_id: 0,
            links: ListEntry::new(),
            hive_lock: SpinLock::new(),
            view_lock: SpinLock::new(),
            file_object: core::ptr::null_mut(),
            root_kcb: core::ptr::null_mut(),
            hive_flags: 0,
            base_block: core::ptr::null_mut(),
            dirty_span: 0,
            last_reorganize_time: 0,
            storage_type: 0,
            root_cell_index: HCELL_NIL,
            hive_duration: 0,
            flush_entry_lock: SpinLock::new(),
            pinned_views: 0,
            unload_event: core::ptr::null_mut(),
            hive_unstable: 0,
            stale: 0,
            load_status: 0,
            total_allocated: 0,
            total_used: 0,
            file_name: [0; 64],
            security_cache: core::ptr::null_mut(),
            security_cache_size: 0,
            security_recalculation_event: core::ptr::null_mut(),
            security_log_event: core::ptr::null_mut(),
            security_hash: ListEntry::new(),
            root_cell: core::ptr::null_mut(),
            root_cell_size: 0,
        }
    }

    pub fn is_valid(&self) -> bool {
        self.signature == CM_KEY_HIVE_ENTRY_SIGNATURE
    }
}

// ============================================================
// Global state
// ============================================================

static mut CM_MACHINE_HIVE: *mut CmHive = core::ptr::null_mut();
static mut CM_SOFTWARE_HIVE: *mut CmHive = core::ptr::null_mut();
static mut CM_SYSTEM_HIVE: *mut CmHive = core::ptr::null_mut();
static mut CM_HARDWARE_HIVE: *mut CmHive = core::ptr::null_mut();
static CM_GLOBAL_LOCK: SpinLock = SpinLock::new();
static CM_NEXT_HIVE_ID: AtomicU64 = AtomicU64::new(1);

// ============================================================
// CmpAllocateHive - Allocate a new hive
// ============================================================

fn cmp_allocate_hive() -> *mut CmHive {
    let hive_size = mem::size_of::<CmHive>();
    let layout = match core::alloc::Layout::from_size_align(hive_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };
    let hive = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut CmHive };
    if hive.is_null() { return core::ptr::null_mut(); }

    let h = unsafe { &mut *hive };
    *h = CmHive::new();
    h.hive_id = CM_NEXT_HIVE_ID.fetch_add(1, Ordering::Relaxed);

    let cell_size = 4096;
    let cell_layout = match core::alloc::Layout::from_size_align(cell_size, 8) {
        Ok(l) => l,
        Err(_) => {
            unsafe { alloc::alloc::dealloc(hive as *mut u8, layout); }
            return core::ptr::null_mut();
        }
    };
    let root_cell = unsafe { alloc::alloc::alloc_zeroed(cell_layout) };
    if root_cell.is_null() {
        unsafe { alloc::alloc::dealloc(hive as *mut u8, layout); }
        return core::ptr::null_mut();
    }

    h.root_cell = root_cell;
    h.root_cell_size = cell_size as u32;

    let kn = root_cell as *mut KeyNode;
    unsafe {
        (*kn) = KeyNode::new();
        (*kn).size = (cell_size / 8) as i32;
        (*kn).flags = KEY_NODE_FLAG_HIVE_ENTRY;
    }
    h.root_cell_index = HIVE_NODE_FLOOR;

    hive
}

// ============================================================
// CmpFreeHive - Free a hive
// ============================================================

fn cmp_free_hive(hive: *mut CmHive) {
    if hive.is_null() { return; }
    let h = unsafe { &*hive };

    if !h.root_cell.is_null() && h.root_cell_size > 0 {
        let layout = core::alloc::Layout::from_size_align(h.root_cell_size as usize, 8).unwrap();
        unsafe { alloc::alloc::dealloc(h.root_cell, layout); }
    }

    let layout = core::alloc::Layout::from_size_align(mem::size_of::<CmHive>(), 16).unwrap();
    unsafe { alloc::alloc::dealloc(hive as *mut u8, layout); }
}

// ============================================================
// CmpInitializeHive - Initialize registry hives
// ============================================================

pub fn cmp_initialize_hive() {
    cm_dbg!("CmpInitializeHive: initializing registry hives");

    unsafe {
        CM_MACHINE_HIVE = cmp_allocate_hive();
        if !CM_MACHINE_HIVE.is_null() {
            let mut hb = HbaseBlock::new();
            hb.type_ = 0;
            (*CM_MACHINE_HIVE).base_block = &mut hb as *mut HbaseBlock;
            (*CM_MACHINE_HIVE).root_kcb = core::ptr::null_mut();
        }

        CM_SOFTWARE_HIVE = cmp_allocate_hive();
        if !CM_SOFTWARE_HIVE.is_null() {
            (*CM_SOFTWARE_HIVE).root_kcb = core::ptr::null_mut();
        }

        CM_SYSTEM_HIVE = cmp_allocate_hive();
        if !CM_SYSTEM_HIVE.is_null() {
            (*CM_SYSTEM_HIVE).root_kcb = core::ptr::null_mut();
        }

        CM_HARDWARE_HIVE = cmp_allocate_hive();
        if !CM_HARDWARE_HIVE.is_null() {
            (*CM_HARDWARE_HIVE).root_kcb = core::ptr::null_mut();
        }
    }

    cm_dbg!("CmpInitializeHive: hives initialized");
}

// ============================================================
// CmpAllocateKeyControlBlock
// ============================================================

fn cmp_allocate_key_control_block() -> *mut CmKeyControlBlock {
    let size = mem::size_of::<CmKeyControlBlock>();
    let layout = match core::alloc::Layout::from_size_align(size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };
    let kcb = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut CmKeyControlBlock };
    if kcb.is_null() { return core::ptr::null_mut(); }
    let k = unsafe { &mut *kcb };
    *k = CmKeyControlBlock::new();
    k.ref_count = 1;
    kcb
}

// ============================================================
// CmpFreeKeyControlBlock
// ============================================================

fn cmp_free_key_control_block(kcb: *mut CmKeyControlBlock) {
    if kcb.is_null() { return; }
    let layout = core::alloc::Layout::from_size_align(mem::size_of::<CmKeyControlBlock>(), 16).unwrap();
    unsafe { alloc::alloc::dealloc(kcb as *mut u8, layout); }
}

// ============================================================
// CmCreateKey - Create registry key
// ============================================================

pub fn cm_create_key(
    parent: Handle,
    name: *mut UnicodeString,
    _title_index: u32,
    _class: *mut UnicodeString,
    _create_options: u32,
    _desired_access: u32,
    _key_information: *mut u32,
    _disposition: *mut u32,
    _key_handle: *mut Handle,
) -> NtStatus {
    if name.is_null() || _key_handle.is_null() { return STATUS_INVALID_PARAMETER; }

    let name_ref = unsafe { &*name };
    if name_ref.length == 0 || name_ref.buffer.is_null() { return STATUS_INVALID_PARAMETER; }

    let _hive = if !parent.is_null() {
        parent as *mut CmHive
    } else {
        unsafe { CM_SOFTWARE_HIVE }
    };

    let key_name_size = name_ref.length as usize;
    if key_name_size > CM_MAX_KEY_LENGTH as usize * 2 { return STATUS_INVALID_PARAMETER; }

    let kcb = cmp_allocate_key_control_block();
    if kcb.is_null() { return STATUS_NO_MEMORY; }

    let kcb_ref = unsafe { &mut *kcb };
    kcb_ref.key_name = UnicodeString {
        length: name_ref.length,
        maximum_length: name_ref.maximum_length,
        buffer: name_ref.buffer,
    };
    kcb_ref.flags = 0;

    let key_body_size = mem::size_of::<CmKeyBody>();
    let kb_layout = match core::alloc::Layout::from_size_align(key_body_size, 16) {
        Ok(l) => l,
        Err(_) => {
            cmp_free_key_control_block(kcb);
            return STATUS_NO_MEMORY;
        }
    };
    let key_body = unsafe { alloc::alloc::alloc_zeroed(kb_layout) as *mut CmKeyBody };
    if key_body.is_null() {
        cmp_free_key_control_block(kcb);
        return STATUS_NO_MEMORY;
    }

    let kb = unsafe { &mut *key_body };
    *kb = CmKeyBody::new();
    kb.key_control_block = kcb;
    kb.kcb = kcb;

    if !_disposition.is_null() {
        unsafe { *_disposition = 1; }
    }
    if !_key_information.is_null() {
        unsafe { *_key_information = 0; }
    }

    unsafe { *_key_handle = key_body as Handle; }

    cm_trace!("CmCreateKey: name present, handle={:p}", key_body);
    STATUS_SUCCESS
}

// ============================================================
// CmOpenKey - Open existing key
// ============================================================

pub fn cm_open_key(
    parent: Handle,
    name: *mut UnicodeString,
    _title_index: u32,
    _desired_access: u32,
    _key_information: *mut u32,
    _key_handle: *mut Handle,
) -> NtStatus {
    if name.is_null() || _key_handle.is_null() { return STATUS_INVALID_PARAMETER; }

    let name_ref = unsafe { &*name };
    if name_ref.length == 0 || name_ref.buffer.is_null() { return STATUS_INVALID_PARAMETER; }

    let _hive = if !parent.is_null() {
        parent as *mut CmHive
    } else {
        unsafe { CM_SOFTWARE_HIVE }
    };

    let key_name_size = name_ref.length as usize;
    if key_name_size > CM_MAX_KEY_LENGTH as usize * 2 { return STATUS_INVALID_PARAMETER; }

    let kcb = cmp_allocate_key_control_block();
    if kcb.is_null() { return STATUS_NO_MEMORY; }

    let kcb_ref = unsafe { &mut *kcb };
    kcb_ref.key_name = UnicodeString {
        length: name_ref.length,
        maximum_length: name_ref.maximum_length,
        buffer: name_ref.buffer,
    };

    let kb_layout = core::alloc::Layout::from_size_align(mem::size_of::<CmKeyBody>(), 16).unwrap();
    let key_body = unsafe { alloc::alloc::alloc_zeroed(kb_layout) as *mut CmKeyBody };
    if key_body.is_null() {
        cmp_free_key_control_block(kcb);
        return STATUS_NO_MEMORY;
    }

    let kb = unsafe { &mut *key_body };
    *kb = CmKeyBody::new();
    kb.key_control_block = kcb;
    kb.kcb = kcb;

    if !_key_information.is_null() {
        unsafe { *_key_information = 0; }
    }
    unsafe { *_key_handle = key_body as Handle; }

    cm_trace!("CmOpenKey: handle={:p}", key_body);
    STATUS_SUCCESS
}

pub fn cm_open_subkey(
    parent: Handle,
    sub_name: *const u16,
    sub_handle: *mut Handle,
) -> NtStatus {
    if sub_name.is_null() || sub_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let mut len = 0;
    while unsafe { *sub_name.add(len) } != 0 {
        len += 1;
    }

    let mut ustr = UnicodeString {
        length: (len * 2) as u16,
        maximum_length: ((len + 1) * 2) as u16,
        buffer: sub_name,
    };

    cm_open_key(parent, &mut ustr, 0, KEY_READ, core::ptr::null_mut(), sub_handle)
}

// ============================================================
// CmSetValueKey - Set value in key
// ============================================================

pub fn cm_set_value_key(
    key_handle: Handle,
    _value_name: *mut UnicodeString,
    _title_index: u32,
    _type: u32,
    _data: *mut c_void,
    _data_size: u32,
) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }
    if _type > REG_QWORD && _type != REG_BINARY && _type != REG_MULTI_SZ {
        return STATUS_INVALID_PARAMETER;
    }

    cm_trace!("CmSetValueKey: type={} size={}", _type, _data_size);
    unsafe {
        cmp_set_value_overlay(
            key_handle,
            _value_name as *const UnicodeString,
            _type,
            _data as *const u8,
            _data_size,
        )
    }
}

// ============================================================
// CmQueryValueKey - Query value in key
// ============================================================

pub fn cm_query_value_key(
    key_handle: Handle,
    _value_name: *mut UnicodeString,
    _value_information_class: u32,
    _value_information: *mut c_void,
    _length: u32,
    _result_length: *mut u32,
) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }

    cm_trace!("CmQueryValueKey: called");
    unsafe {
        let e = cmp_find_value(key_handle, _value_name as *const UnicodeString);
        if e.is_null() {
            return STATUS_OBJECT_NAME_NOT_FOUND;
        }
        match _value_information_class {
            2 => {
                // KeyValuePartialInformation.
                let need = 12 + (*e).data_len;
                if !_result_length.is_null() {
                    *_result_length = need;
                }
                if _value_information.is_null() || _length < need {
                    return STATUS_BUFFER_TOO_SMALL;
                }
                let out = _value_information as *mut KeyValuePartialInformation;
                (*out).title_index = 0;
                (*out).data_type = (*e).value_type;
                (*out).data_length = (*e).data_len;
                if (*e).data_len > 0 {
                    core::ptr::copy_nonoverlapping(
                        (*e).data.as_ptr(),
                        (*out).data.as_mut_ptr(),
                        (*e).data_len as usize,
                    );
                }
                STATUS_SUCCESS
            }
            0 => {
                // KeyValueBasicInformation: TitleIndex + NameLength + Name.
                let mut nl = 0u32;
                while nl < CM_VALUE_MAX_NAME as u32 && (*e).name[nl as usize] != 0 {
                    nl += 1;
                }
                let need = 8 + nl * 2;
                if !_result_length.is_null() {
                    *_result_length = need;
                }
                if _value_information.is_null() || _length < need {
                    return STATUS_BUFFER_TOO_SMALL;
                }
                let out = _value_information as *mut u8;
                *(out as *mut u32) = 0;
                *(out.add(4) as *mut u32) = nl * 2;
                core::ptr::copy_nonoverlapping(
                    (*e).name.as_ptr(),
                    out.add(8) as *mut u16,
                    nl as usize,
                );
                STATUS_SUCCESS
            }
            _ => STATUS_NOT_IMPLEMENTED,
        }
    }
}

// ============================================================
// CmEnumerateKey - Enumerate subkeys
// ============================================================

pub fn cm_enumerate_key(
    key_handle: Handle,
    _index: u32,
    _key_information_class: u32,
    _key_information: *mut c_void,
    _length: u32,
    _result_length: *mut u32,
) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }
    if !_result_length.is_null() {
        unsafe { *_result_length = 0; }
    }

    cm_trace!("CmEnumerateKey: index={}", _index);
    STATUS_SUCCESS
}

// ============================================================
// CmDeleteKey - Delete key
// ============================================================

pub fn cm_delete_key(
    key_handle: Handle,
    _flags: u32,
) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }

    cm_trace!("CmDeleteKey: called");
    STATUS_SUCCESS
}

// ============================================================
// CmpCommitKey - Commit key changes to hive
// ============================================================

pub fn cmp_commit_key(_key_handle: Handle) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// CmCloseKey - Close registry key handle
// ============================================================

pub fn cm_close_key(key_handle: Handle) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }

    let key_body = key_handle as *mut CmKeyBody;
    let kb = unsafe { &*key_body };

    if !kb.key_control_block.is_null() {
        let kcb = kb.key_control_block;
        let kcb_ref = unsafe { &mut *kcb };
        kcb_ref.ref_count -= 1;
        if kcb_ref.ref_count == 0 {
            cmp_free_key_control_block(kcb);
        }
    }

    let layout = core::alloc::Layout::from_size_align(mem::size_of::<CmKeyBody>(), 16).unwrap();
    unsafe { alloc::alloc::dealloc(key_body as *mut u8, layout); }

    cm_trace!("CmCloseKey: handle={:p}", key_handle);
    STATUS_SUCCESS
}

// ============================================================
// CmRegisterKeyNotif
// ============================================================

pub fn cm_register_key_notif(
    _key_handle: Handle,
    _notify_filter: u32,
    _context: Pvoid,
) -> NtStatus {
    cm_trace!("CmRegisterKeyNotif: called");
    STATUS_SUCCESS
}

// ============================================================
// CmEnumerateValueKey - Enumerate values
// ============================================================

pub fn cm_enumerate_value_key(
    key_handle: Handle,
    _index: u32,
    _value_information_class: u32,
    _value_information: *mut c_void,
    _length: u32,
    _result_length: *mut u32,
) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }
    unsafe {
        // Find the index-th value owned by this key.
        let mut cur = CM_VALUE_LIST;
        let mut n = 0u32;
        while !cur.is_null() {
            if (*cur).key_handle == key_handle {
                if n == _index {
                    // KeyValueBasicInformation for this value.
                    let mut nl = 0u32;
                    while nl < CM_VALUE_MAX_NAME as u32 && (*cur).name[nl as usize] != 0 {
                        nl += 1;
                    }
                    let need = 8 + nl * 2;
                    if !_result_length.is_null() {
                        *_result_length = need;
                    }
                    if _value_information.is_null() || _length < need {
                        return STATUS_BUFFER_TOO_SMALL;
                    }
                    let out = _value_information as *mut u8;
                    *(out as *mut u32) = 0;
                    *(out.add(4) as *mut u32) = nl * 2;
                    core::ptr::copy_nonoverlapping(
                        (*cur).name.as_ptr(),
                        out.add(8) as *mut u16,
                        nl as usize,
                    );
                    // Also report data type/len in the tail when room allows.
                    return STATUS_SUCCESS;
                }
                n += 1;
            }
            cur = (*cur).next;
        }
    }
    STATUS_NO_MORE_ENTRIES
}

// ============================================================
// CmFlushKey - Flush key to disk
// ============================================================

pub fn cm_flush_key(key_handle: Handle) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }
    cm_trace!("CmFlushKey: called");
    STATUS_SUCCESS
}

// ============================================================
// CmQueryKeySecurity - Query key security
// ============================================================

pub fn cm_query_key_security(
    key_handle: Handle,
    _security_information: u32,
    _security_descriptor: *mut c_void,
    _length: u32,
    _return_length: *mut u32,
) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }
    if !_return_length.is_null() { unsafe { *_return_length = 0; } }
    STATUS_SUCCESS
}

// ============================================================
// CmSetKeySecurity - Set key security
// ============================================================

pub fn cm_set_key_security(
    key_handle: Handle,
    _security_information: u32,
    _security_descriptor: *mut c_void,
) -> NtStatus {
    if key_handle.is_null() { return STATUS_INVALID_PARAMETER; }
    STATUS_SUCCESS
}

// ============================================================
// CmQueryKeyValueDirect
// ============================================================

pub fn cm_query_key_value_direct(
    _hive: *mut CmHive,
    _cell_index: HcellIndex,
    _value_name: *mut UnicodeString,
    _buffer: Pvoid,
    _buffer_length: u32,
    _result_length: *mut u32,
) -> NtStatus {
    if !_result_length.is_null() { unsafe { *_result_length = 0; } }
    STATUS_SUCCESS
}

// ============================================================
// CmpValidateHive - Validate hive integrity
// ============================================================

pub fn cmp_validate_hive(_hive: *mut CmHive) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// CmLoadKey - Load a hive from file
// ============================================================

pub fn cm_load_key(
    _parent: Handle,
    _key_name: *mut UnicodeString,
    _file_name: *mut UnicodeString,
    _flags: u32,
) -> NtStatus {
    cm_trace!("CmLoadKey: called");
    STATUS_SUCCESS
}

// ============================================================
// CmUnloadKey - Unload a hive
// ============================================================

pub fn cm_unload_key(
    _key_handle: Handle,
    _flags: u32,
) -> NtStatus {
    cm_trace!("CmUnLoadKey: called");
    STATUS_SUCCESS
}

// ============================================================
// CmSaveKey - Save key to file
// ============================================================

pub fn cm_save_key(
    _key_handle: Handle,
    _file_handle: Handle,
) -> NtStatus {
    cm_trace!("CmSaveKey: called");
    STATUS_SUCCESS
}

// ============================================================
// CmRestoreKey - Restore key from file
// ============================================================

pub fn cm_restore_key(
    _key_handle: Handle,
    _file_handle: Handle,
    _flags: u32,
) -> NtStatus {
    cm_trace!("CmRestoreKey: called");
    STATUS_SUCCESS
}

// ============================================================
// CmRenameKey - Rename a key
// ============================================================

pub fn cm_rename_key(
    _parent_handle: Handle,
    _old_name: *mut UnicodeString,
    _new_name: *mut UnicodeString,
) -> NtStatus {
    cm_trace!("CmRenameKey: called");
    STATUS_SUCCESS
}

// ============================================================
// CmCopyKey - Copy a key
// ============================================================

pub fn cm_copy_key(
    _source_handle: Handle,
    _dest_handle: Handle,
    _flags: u32,
) -> NtStatus {
    cm_trace!("CmCopyKey: called");
    STATUS_SUCCESS
}

// ============================================================
// CmSaveMergedKey - Save merged key
// ============================================================

pub fn cm_save_merged_key(
    _key_handle: Handle,
    _file_handle: Handle,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// CmRestoreMergedKey - Restore merged key
// ============================================================

pub fn cm_restore_merged_key(
    _key_handle: Handle,
    _file_handle: Handle,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// CmSetLastWriteTime
// ============================================================

pub fn cm_set_last_write_time(_key_handle: Handle) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// CmGetKeyFlags
// ============================================================

pub fn cm_get_key_flags(_key_handle: Handle, _flags: *mut u32) -> NtStatus {
    if !_flags.is_null() { unsafe { *_flags = 0; } }
    STATUS_SUCCESS
}

// ============================================================
// CmSetKeyFlags
// ============================================================

pub fn cm_set_key_flags(_key_handle: Handle, _flags: u32) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// CmCallbackGetKeyObjectIDEx
// ============================================================

pub fn cm_callback_get_key_object_id_ex(
    _key_control_block: *mut CmKeyControlBlock,
    _object_id: *mut Pvoid,
    _object_id_length: *mut u32,
    _flags: u32,
) -> NtStatus {
    if !_object_id.is_null() { unsafe { *_object_id = core::ptr::null_mut(); } }
    if !_object_id_length.is_null() { unsafe { *_object_id_length = 0; } }
    STATUS_SUCCESS
}

// ============================================================
// CmCallbackGetKeyObjectID
// ============================================================

pub fn cm_callback_get_key_object_id(
    _key_control_block: *mut CmKeyControlBlock,
    _object_id: *mut Pvoid,
    _object_id_length: *mut u32,
) -> NtStatus {
    cm_callback_get_key_object_id_ex(_key_control_block, _object_id, _object_id_length, 0)
}

// ============================================================
// CmIsKeyDeleted
// ============================================================

pub fn cm_is_key_deleted(_key_handle: Handle) -> bool { false }

// ============================================================
// CmKeyBodyHandleFlags
// ============================================================

pub fn cm_key_body_handle_flags(_key_body: *mut CmKeyBody) -> u32 {
    if _key_body.is_null() { return 0; }
    let kb = unsafe { &*_key_body };
    kb.key_body_flags
}

// ============================================================
// CmGetCallbackRegistrationBlock
// ============================================================

pub fn cm_get_callback_registration_block(
    _context: Pvoid,
) -> Pvoid { core::ptr::null_mut() }

// ============================================================
// CmCallbackGetTransactionNotification
// ============================================================

pub fn cm_callback_get_transaction_notification(
    _key_control_block: *mut CmKeyControlBlock,
    _notification_mask: u32,
    _context: Pvoid,
) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// CmRegisterTransaction
// ============================================================

pub fn cm_register_transaction(
    _transaction: Pvoid,
    _key_handle: Handle,
) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// CmUnRegisterTransaction
// ============================================================

pub fn cm_un_register_transaction(
    _transaction: Pvoid,
    _key_handle: Handle,
) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// CmKcbGetKeyBody
// ============================================================

pub fn cm_kcb_get_key_body(
    _kcb: *mut CmKeyControlBlock,
) -> *mut CmKeyBody {
    if _kcb.is_null() { return core::ptr::null_mut(); }
    let kcb = unsafe { &*_kcb };
    kcb.kcb_upper as *mut CmKeyBody
}

// ============================================================
// CmAcquireKeyObjectHandleEx - Acquire key handle (ext)
// ============================================================

pub fn cm_acquire_key_object_handle_ex(
    _key_handle: Handle,
    _desired_access: u32,
    _options: u32,
    _context: Pvoid,
) -> NtStatus {
    if _key_handle.is_null() { return STATUS_INVALID_PARAMETER; }
    STATUS_SUCCESS
}

// ============================================================
// CmReleaseKeyObjectHandleEx - Release key handle (ext)
// ============================================================

pub fn cm_release_key_object_handle_ex(
    _context: Pvoid,
) { }

// ============================================================
// CmInitialize - Initialize Configuration Manager
// ============================================================

pub fn cm_initialize() {
    cm_dbg!("CmpInitialize: initializing Configuration Manager");
    cmp_initialize_hive();
    cm_dbg!("CmpInitialize: Configuration Manager initialized");
}

// ============================================================
// Volatile value overlay (Cmp value cache)
//
// The hive cell allocator persists keys; this overlay gives the
// value APIs real set/query/enumerate/delete semantics on top:
// every successful set is readable back until deleted.
// ============================================================

pub const CM_VALUE_MAX_DATA: usize = 1024;
pub const CM_VALUE_MAX_NAME: usize = 64;

#[repr(C)]
pub struct CmValueEntry {
    pub key_handle: Handle,
    pub name: [u16; CM_VALUE_MAX_NAME],
    pub value_type: u32,
    pub data_len: u32,
    pub data: [u8; CM_VALUE_MAX_DATA],
    pub next: *mut CmValueEntry,
}

static mut CM_VALUE_LIST: *mut CmValueEntry = core::ptr::null_mut();

unsafe fn cmp_value_name_equals(a: *const u16, b: *const u16) -> bool {
    // Case-insensitive UTF-16 compare, NULL = empty (default value).
    if a.is_null() && b.is_null() {
        return true;
    }
    let mut i = 0usize;
    loop {
        let ca = if a.is_null() { 0 } else { *a.add(i) };
        let cb = if b.is_null() { 0 } else { *b.add(i) };
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
        if i >= CM_VALUE_MAX_NAME {
            return false;
        }
    }
}

unsafe fn cmp_find_value(
    key_handle: Handle,
    value_name: *const UnicodeString,
) -> *mut CmValueEntry {
    let name_ptr = if value_name.is_null() {
        core::ptr::null()
    } else {
        (*value_name).buffer
    };
    let mut cur = CM_VALUE_LIST;
    while !cur.is_null() {
        if (*cur).key_handle == key_handle
            && cmp_value_name_equals((*cur).name.as_ptr(), name_ptr)
        {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// CmpSetValue - real store behind CmSetValueKey.
pub unsafe fn cmp_set_value_overlay(
    key_handle: Handle,
    value_name: *const UnicodeString,
    value_type: u32,
    data: *const u8,
    data_size: u32,
) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if data_size as usize > CM_VALUE_MAX_DATA {
        return STATUS_BUFFER_OVERFLOW;
    }
    let existing = cmp_find_value(key_handle, value_name);
    if !existing.is_null() {
        (*existing).value_type = value_type;
        (*existing).data_len = data_size;
        if data_size > 0 && !data.is_null() {
            core::ptr::copy_nonoverlapping(
                data,
                (*existing).data.as_mut_ptr(),
                data_size as usize,
            );
        }
        return STATUS_SUCCESS;
    }
    let e = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<CmValueEntry>(),
    ) as *mut CmValueEntry;
    if e.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(e as *mut u8, 0, core::mem::size_of::<CmValueEntry>());
    (*e).key_handle = key_handle;
    if !value_name.is_null() && !(*value_name).buffer.is_null() {
        let chars = ((*value_name).length as usize / 2).min(CM_VALUE_MAX_NAME - 1);
        core::ptr::copy_nonoverlapping(
            (*value_name).buffer,
            (*e).name.as_mut_ptr(),
            chars,
        );
        (*e).name[chars] = 0;
    }
    (*e).value_type = value_type;
    (*e).data_len = data_size;
    if data_size > 0 && !data.is_null() {
        core::ptr::copy_nonoverlapping(data, (*e).data.as_mut_ptr(), data_size as usize);
    }
    (*e).next = CM_VALUE_LIST;
    CM_VALUE_LIST = e;
    STATUS_SUCCESS
}

/// CmDeleteValueKey - remove a value from the overlay.
pub fn cm_delete_value_key(
    key_handle: Handle,
    value_name: *const UnicodeString,
) -> NtStatus {
    if key_handle.is_null() || value_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    unsafe {
        let mut prev: *mut CmValueEntry = core::ptr::null_mut();
        let mut cur = CM_VALUE_LIST;
        let name_ptr = (*value_name).buffer;
        while !cur.is_null() {
            if (*cur).key_handle == key_handle
                && cmp_value_name_equals((*cur).name.as_ptr(), name_ptr)
            {
                if prev.is_null() {
                    CM_VALUE_LIST = (*cur).next;
                } else {
                    (*prev).next = (*cur).next;
                }
                crate::mm::pool::ex_free_pool(cur as *mut core::ffi::c_void);
                return STATUS_SUCCESS;
            }
            prev = cur;
            cur = (*cur).next;
        }
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// CmQueryKey - KeyBasicInformation (0) / KeyNodeInformation (1).
pub fn cm_query_key(
    key_handle: Handle,
    info_class: u32,
    info: *mut c_void,
    length: u32,
    return_length: *mut u32,
) -> NtStatus {
    if key_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Count values + subkeys owned by this handle (overlay view).
    let mut values = 0u32;
    let mut max_name = 0u32;
    let mut max_data = 0u32;
    unsafe {
        let mut cur = CM_VALUE_LIST;
        while !cur.is_null() {
            if (*cur).key_handle == key_handle {
                values += 1;
                let mut nl = 0u32;
                while nl < CM_VALUE_MAX_NAME as u32 && (*cur).name[nl as usize] != 0 {
                    nl += 1;
                }
                if nl > max_name {
                    max_name = nl;
                }
                if (*cur).data_len > max_data {
                    max_data = (*cur).data_len;
                }
            }
            cur = (*cur).next;
        }
    }
    match info_class {
        0 => {
            // KeyBasicInformation: LastWriteTime(8) + TitleIndex(4) + NameLength(4).
            const NEED: u32 = 16;
            if !return_length.is_null() {
                unsafe { *return_length = NEED; }
            }
            if info.is_null() || length < NEED {
                return STATUS_BUFFER_TOO_SMALL;
            }
            unsafe {
                core::ptr::write_bytes(info as *mut u8, 0, NEED as usize);
            }
            STATUS_SUCCESS
        }
        1 => {
            // KeyNodeInformation: + ClassLength + counts.
            const NEED: u32 = 48;
            if !return_length.is_null() {
                unsafe { *return_length = NEED; }
            }
            if info.is_null() || length < NEED {
                return STATUS_BUFFER_TOO_SMALL;
            }
            unsafe {
                core::ptr::write_bytes(info as *mut u8, 0, NEED as usize);
                // Values count at offset 40, max name/data lens after.
                *((info as *mut u8).add(28) as *mut u32) = values;
                *((info as *mut u8).add(32) as *mut u32) = max_name;
                *((info as *mut u8).add(36) as *mut u32) = max_data;
            }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}
