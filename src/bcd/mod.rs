/// Bcd - Boot Configuration Datastore (Bcd/Bi)
use crate::types::*;

pub struct BcdStore {
    pub key_handle: Handle,
    pub store_id: [u16; 64],
}

pub struct BcdElement {
    pub data_type: u32,
    pub data_size: u32,
    pub data: [u8; 256],
}

pub unsafe fn bcd_open_store(
    store_path: *const u16,
    store: *mut *mut BcdStore,
) -> NtStatus {
    let mut key_handle: Handle = core::ptr::null_mut();
    let mut store_str = UnicodeString::new();
    if !store_path.is_null() {
        let mut len = 0usize;
        while *store_path.add(len) != 0 {
            len += 1;
        }
        store_str = UnicodeString {
            length: (len * 2) as Ushort,
            maximum_length: ((len + 1) * 2) as Ushort,
            buffer: store_path,
        };
    }
    let status = crate::cm::cm_open_key(
        core::ptr::null_mut(),
        &mut store_str,
        0,
        0x20019,
        core::ptr::null_mut(),
        &mut key_handle,
    );
    if status != STATUS_SUCCESS { return status; }

    let s = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<BcdStore>()) as *mut BcdStore;
    if s.is_null() {
        crate::ob::ob_close_handle(key_handle, 0);
        return STATUS_NO_MEMORY;
    }

    (*s).key_handle = key_handle;
    *store = s;
    STATUS_SUCCESS
}

pub unsafe fn bcd_get_element(
    _store: *mut BcdStore,
    _element_id: u32,
    element: *mut BcdElement,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn bcd_set_element(
    _store: *mut BcdStore,
    _element_id: u32,
    _element: *const BcdElement,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn bcd_enumerate_elements(
    _store: *mut BcdStore,
    _elements: *mut BcdElement,
    _count: *mut u32,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 BCD: in-memory objects/elements, well-known GUIDs
// ============================================================

use core::ffi::c_void;

// Well-known BCD object IDs (subset).
pub const BCD_OBJECT_BOOTMGR: u32 = 0x10100001;
pub const BCD_OBJECT_DEFAULT: u32 = 0x10200001;
pub const BCD_OBJECT_CURRENT: u32 = 0x10300001;
pub const BCD_OBJECT_MEMDIAG: u32 = 0x10400001;
pub const BCD_OBJECT_DEBUGGER: u32 = 0x10500001;
pub const BCD_OBJECT_RECOVERY: u32 = 0x10600001;

// Element classes.
pub const BCD_ELEMENT_CLASS_LIBRARY: u32 = 0x10000000;
pub const BCD_ELEMENT_CLASS_APPLICATION: u32 = 0x20000000;
pub const BCD_ELEMENT_CLASS_DEVICE: u32 = 0x30000000;
pub const BCD_ELEMENT_CLASS_TEMPLATE: u32 = 0x40000000;

// Element types (subset of real BCD types).
pub const BCD_TYPE_DEVICE: u32 = 0x11000001;
pub const BCD_TYPE_FILE_PATH: u32 = 0x12000002;
pub const BCD_TYPE_DESCRIPTION: u32 = 0x12000004;
pub const BCD_TYPE_LOCALE: u32 = 0x12000005;
pub const BCD_TYPE_DEFAULT_OBJECT: u32 = 0x23000003;
pub const BCD_TYPE_TIMEOUT: u32 = 0x23000005;
pub const BCD_TYPE_BOOT_STATUS_POLICY: u32 = 0x23000008;
pub const BCD_TYPE_KERNEL_PATH: u32 = 0x22000002;
pub const BCD_TYPE_OS_DEVICE: u32 = 0x21000001;
pub const BCD_TYPE_SYSTEM_ROOT: u32 = 0x22000004;
pub const BCD_TYPE_NX_POLICY: u32 = 0x25000020;
pub const BCD_TYPE_PAE_POLICY: u32 = 0x25000022;
pub const BCD_TYPE_DEBUG_ENABLED: u32 = 0x26000010;
pub const BCD_TYPE_TEST_SIGNING: u32 = 0x260000AD;

pub const BCD_MAX_ELEMENTS_PER_OBJECT: usize = 64;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct BcdElementFull {
    pub element_type: u32,
    pub data_type: u32,
    pub data_size: u32,
    pub data: [u8; 256],
}

#[repr(C)]
pub struct BcdObject {
    pub object_id: u32,
    pub object_type: u32,
    pub elements: [BcdElementFull; BCD_MAX_ELEMENTS_PER_OBJECT],
    pub element_count: u32,
    pub next: *mut BcdObject,
}

static mut BCD_OBJECT_LIST: *mut BcdObject = core::ptr::null_mut();

unsafe fn bcdp_find_object(object_id: u32) -> *mut BcdObject {
    let mut cur = BCD_OBJECT_LIST;
    while !cur.is_null() {
        if (*cur).object_id == object_id {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// BcdCreateObject - create/get a BCD object by well-known ID.
pub unsafe fn bcd_create_object(object_id: u32, object_type: u32) -> *mut BcdObject {
    let existing = bcdp_find_object(object_id);
    if !existing.is_null() {
        return existing;
    }
    let o = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<BcdObject>(),
    ) as *mut BcdObject;
    if o.is_null() {
        return core::ptr::null_mut();
    }
    core::ptr::write_bytes(o as *mut u8, 0, core::mem::size_of::<BcdObject>());
    (*o).object_id = object_id;
    (*o).object_type = object_type;
    (*o).next = BCD_OBJECT_LIST;
    BCD_OBJECT_LIST = o;
    o
}

/// BcdSetElement - set/replace an element on an object.
pub unsafe fn bcd_set_element_full(
    object_id: u32,
    element_type: u32,
    data: *const u8,
    data_size: u32,
) -> NtStatus {
    let o = bcdp_find_object(object_id);
    if o.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    if data.is_null() || data_size as usize > 256 {
        return STATUS_INVALID_PARAMETER;
    }
    // Replace existing.
    let mut i = 0u32;
    while i < (*o).element_count {
        if (*o).elements[i as usize].element_type == element_type {
            core::ptr::copy_nonoverlapping(
                data,
                (*o).elements[i as usize].data.as_mut_ptr(),
                data_size as usize,
            );
            (*o).elements[i as usize].data_size = data_size;
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    if (*o).element_count >= BCD_MAX_ELEMENTS_PER_OBJECT as u32 {
        return STATUS_BUFFER_OVERFLOW;
    }
    let idx = (*o).element_count as usize;
    (*o).elements[idx].element_type = element_type;
    (*o).elements[idx].data_type = element_type & 0xF0000000;
    (*o).elements[idx].data_size = data_size;
    core::ptr::copy_nonoverlapping(
        data,
        (*o).elements[idx].data.as_mut_ptr(),
        data_size as usize,
    );
    (*o).element_count += 1;
    STATUS_SUCCESS
}

/// BcdGetElement - read an element from an object.
pub unsafe fn bcd_get_element_full(
    object_id: u32,
    element_type: u32,
    element_out: *mut BcdElementFull,
) -> NtStatus {
    let o = bcdp_find_object(object_id);
    if o.is_null() || element_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0u32;
    while i < (*o).element_count {
        if (*o).elements[i as usize].element_type == element_type {
            *element_out = (*o).elements[i as usize];
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// BcdDeleteElement - remove an element.
pub unsafe fn bcd_delete_element(object_id: u32, element_type: u32) -> NtStatus {
    let o = bcdp_find_object(object_id);
    if o.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    let mut i = 0u32;
    while i < (*o).element_count {
        if (*o).elements[i as usize].element_type == element_type {
            let mut j = i;
            while j + 1 < (*o).element_count {
                (*o).elements[j as usize] = (*o).elements[(j + 1) as usize];
                j += 1;
            }
            (*o).element_count -= 1;
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// BcdEnumerateObject - copy all elements of an object out.
pub unsafe fn bcd_enumerate_object(
    object_id: u32,
    buffer: *mut BcdElementFull,
    count_in_out: *mut u32,
) -> NtStatus {
    let o = bcdp_find_object(object_id);
    if o.is_null() || count_in_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if buffer.is_null() || *count_in_out < (*o).element_count {
        *count_in_out = (*o).element_count;
        return STATUS_BUFFER_TOO_SMALL;
    }
    let mut i = 0u32;
    while i < (*o).element_count {
        *buffer.add(i as usize) = (*o).elements[i as usize];
        i += 1;
    }
    *count_in_out = (*o).element_count;
    STATUS_SUCCESS
}

/// BcdInitializeDefaultStore - seed {bootmgr}/{default} like winload does.
pub unsafe fn bcd_initialize_default_store() -> NtStatus {
    let bootmgr = bcd_create_object(BCD_OBJECT_BOOTMGR, 0x10100001);
    if bootmgr.is_null() {
        return STATUS_NO_MEMORY;
    }
    let timeout: u32 = 30;
    bcd_set_element_full(
        BCD_OBJECT_BOOTMGR,
        BCD_TYPE_TIMEOUT,
        &timeout as *const u32 as *const u8,
        4,
    );
    bcd_set_element_full(
        BCD_OBJECT_BOOTMGR,
        BCD_TYPE_DEFAULT_OBJECT,
        &BCD_OBJECT_DEFAULT as *const u32 as *const u8,
        4,
    );
    let os = bcd_create_object(BCD_OBJECT_DEFAULT, 0x10200001);
    if os.is_null() {
        return STATUS_NO_MEMORY;
    }
    let desc: [u16; 7] = [0x56, 0x6C, 0x61, 0x64, 0x4F, 0x53, 0]; // "VladOS"
    bcd_set_element_full(
        BCD_OBJECT_DEFAULT,
        BCD_TYPE_DESCRIPTION,
        desc.as_ptr() as *const u8,
        14,
    );
    let nx: u32 = 2; // OptOut
    bcd_set_element_full(
        BCD_OBJECT_DEFAULT,
        BCD_TYPE_NX_POLICY,
        &nx as *const u32 as *const u8,
        4,
    );
    STATUS_SUCCESS
}
