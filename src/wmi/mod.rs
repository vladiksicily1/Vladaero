/// Wmi - Windows Management Instrumentation (Wmi/Wmip)
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

#[repr(C)]
pub struct WmiLoggerContext {
    pub buffer: *mut u8,
    pub buffer_size: u32,
    pub buffer_count: u32,
    pub flags: u32,
    pub enabled: bool,
    pub guid: [u8; 16],
    pub provider_list: ListEntry,
}

static mut WMI_LOGGER: WmiLoggerContext = WmiLoggerContext {
    buffer: core::ptr::null_mut(),
    buffer_size: 0,
    buffer_count: 0,
    flags: 0,
    enabled: false,
    guid: [0; 16],
    provider_list: ListEntry { flink: core::ptr::null_mut(), blink: core::ptr::null_mut() },
};

pub unsafe fn wmi_initialize() -> NtStatus {
    WMI_LOGGER.buffer_size = 32 * 1024;
    WMI_LOGGER.buffer = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(32 * 1024) as *mut u8;
    WMI_LOGGER.enabled = true;
    WMI_LOGGER.provider_list.initialize();
    STATUS_SUCCESS
}

pub unsafe fn wmi_system_control(
    _guid: *const u8,
    _action: u32,
    _in_buffer: *const u8,
    _in_size: u32,
    _out_buffer: *mut u8,
    _out_size: *mut u32,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn wmi_trace_event(
    _guid: *const u8,
    _event_type: u16,
    _data: *const u8,
    _data_size: u32,
) -> NtStatus {
    if !WMI_LOGGER.enabled || WMI_LOGGER.buffer.is_null() {
        return STATUS_SUCCESS;
    }
    STATUS_SUCCESS
}

pub unsafe fn io_wmi_registration_control(
    _object: *mut u8,
    _action: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn wmi_query_data(
    _guid: *const u8,
    _instance_name: *const u16,
    _in_buffer: *const u8,
    _in_size: u32,
    _out_buffer: *mut u8,
    _out_size: *mut u32,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn wmi_set_data(
    _guid: *const u8,
    _instance_name: *const u16,
    _in_buffer: *const u8,
    _in_size: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 WMI: GUID registry + SystemControl dispatch (Wmip)
// ============================================================

pub const WMIREG_ACTION_REGISTER: u32 = 1;
pub const WMIREG_ACTION_DEREGISTER: u32 = 2;
pub const WMIREG_ACTION_REREGISTER: u32 = 3;
pub const WMIREG_ACTION_UPDATE_GUIDS: u32 = 4;

pub const WMI_ACTION_QUERY_ALL_DATA: u32 = 0;
pub const WMI_ACTION_QUERY_SINGLE_INSTANCE: u32 = 1;
pub const WMI_ACTION_SET_SINGLE_INSTANCE: u32 = 2;
pub const WMI_ACTION_SET_SINGLE_ITEM: u32 = 3;
pub const WMI_ACTION_EXECUTE_METHOD: u32 = 4;
pub const WMI_ACTION_ENABLE_COLLECTION: u32 = 5;
pub const WMI_ACTION_DISABLE_COLLECTION: u32 = 6;
pub const WMI_ACTION_REGINFO: u32 = 7;

pub const WMI_GUID_TYPE_DATA_BLOCK: u32 = 0;
pub const WMI_GUID_TYPE_EVENT: u32 = 1;
pub const WMI_GUID_TYPE_METHOD: u32 = 2;

pub const STATUS_WMI_GUID_NOT_FOUND: NtStatus = 0xC0000295;

#[repr(C)]
pub struct WmiGuidEntry {
    pub guid: [u8; 16],
    pub guid_type: u32,
    pub flags: u32,
    pub instance_count: u32,
    pub data_block: *mut u8,
    pub data_block_size: u32,
    pub device_object: *mut c_void,
    pub next: *mut WmiGuidEntry,
}

#[repr(C)]
pub struct WmiMethodContext {
    pub guid: [u8; 16],
    pub method_id: u32,
    pub in_buffer: *const u8,
    pub in_size: u32,
    pub out_buffer: *mut u8,
    pub out_size: *mut u32,
}

static mut WMI_GUID_LIST: *mut WmiGuidEntry = core::ptr::null_mut();
static WMI_GUID_COUNT: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(0);

unsafe fn wmip_find_guid(guid: *const u8) -> *mut WmiGuidEntry {
    if guid.is_null() {
        return core::ptr::null_mut();
    }
    let target = core::slice::from_raw_parts(guid, 16);
    let mut cur = WMI_GUID_LIST;
    while !cur.is_null() {
        if &(*cur).guid[..] == target {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// WmipRegisterGuid - register a data block / event / method GUID.
pub unsafe fn wmi_register_guid(
    guid: *const u8,
    guid_type: u32,
    instance_count: u32,
    device_object: *mut c_void,
) -> NtStatus {
    if guid.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if !wmip_find_guid(guid).is_null() {
        return crate::nt::STATUS_OBJECT_NAME_COLLISION;
    }
    let e = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<WmiGuidEntry>(),
    ) as *mut WmiGuidEntry;
    if e.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(e as *mut u8, 0, core::mem::size_of::<WmiGuidEntry>());
    core::ptr::copy_nonoverlapping(guid, (*e).guid.as_mut_ptr(), 16);
    (*e).guid_type = guid_type;
    (*e).instance_count = instance_count;
    (*e).device_object = device_object;
    (*e).next = WMI_GUID_LIST;
    WMI_GUID_LIST = e;
    WMI_GUID_COUNT.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    STATUS_SUCCESS
}

/// WmipDeregisterGuid - remove a GUID registration.
pub unsafe fn wmi_deregister_guid(guid: *const u8) -> NtStatus {
    if guid.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let target = core::slice::from_raw_parts(guid, 16);
    let mut prev: *mut WmiGuidEntry = core::ptr::null_mut();
    let mut cur = WMI_GUID_LIST;
    while !cur.is_null() {
        if &(*cur).guid[..] == target {
            if prev.is_null() {
                WMI_GUID_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            if !(*cur).data_block.is_null() {
                crate::mm::pool::ex_free_pool((*cur).data_block as *mut c_void);
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            WMI_GUID_COUNT.fetch_sub(1, core::sync::atomic::Ordering::Relaxed);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// WmipSystemControl - full IRP_MJ_SYSTEM_CONTROL dispatch.
pub unsafe fn wmi_system_control_full(
    guid: *const u8,
    action: u32,
    in_buffer: *const u8,
    in_size: u32,
    out_buffer: *mut u8,
    out_size: *mut u32,
) -> NtStatus {
    match action {
        WMI_ACTION_REGINFO => {
            // Return count of registered GUIDs.
            if out_buffer.is_null() || out_size.is_null() || *out_size < 4 {
                if !out_size.is_null() {
                    *out_size = 4;
                }
                return STATUS_BUFFER_TOO_SMALL;
            }
            *(out_buffer as *mut u32) =
                WMI_GUID_COUNT.load(core::sync::atomic::Ordering::Relaxed);
            *out_size = 4;
            STATUS_SUCCESS
        }
        WMI_ACTION_QUERY_ALL_DATA | WMI_ACTION_QUERY_SINGLE_INSTANCE => {
            let entry = wmip_find_guid(guid);
            if entry.is_null() {
                return STATUS_WMI_GUID_NOT_FOUND;
            }
            let e = &*entry;
            if out_buffer.is_null() || out_size.is_null() || *out_size < e.data_block_size {
                if !out_size.is_null() {
                    *out_size = e.data_block_size;
                }
                return STATUS_BUFFER_TOO_SMALL;
            }
            if !e.data_block.is_null() && e.data_block_size > 0 {
                core::ptr::copy_nonoverlapping(
                    e.data_block,
                    out_buffer,
                    e.data_block_size as usize,
                );
            }
            *out_size = e.data_block_size;
            STATUS_SUCCESS
        }
        WMI_ACTION_SET_SINGLE_INSTANCE | WMI_ACTION_SET_SINGLE_ITEM => {
            let entry = wmip_find_guid(guid);
            if entry.is_null() {
                return STATUS_WMI_GUID_NOT_FOUND;
            }
            if in_buffer.is_null() || in_size == 0 {
                return STATUS_INVALID_PARAMETER;
            }
            let e = &mut *entry;
            if !e.data_block.is_null() {
                crate::mm::pool::ex_free_pool(e.data_block as *mut c_void);
                e.data_block = core::ptr::null_mut();
                e.data_block_size = 0;
            }
            let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(in_size as usize)
                as *mut u8;
            if buf.is_null() {
                return STATUS_NO_MEMORY;
            }
            core::ptr::copy_nonoverlapping(in_buffer, buf, in_size as usize);
            e.data_block = buf;
            e.data_block_size = in_size;
            STATUS_SUCCESS
        }
        WMI_ACTION_ENABLE_COLLECTION | WMI_ACTION_DISABLE_COLLECTION => STATUS_SUCCESS,
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

/// WmipFireEvent - deliver a WMI event to ETW/logger.
pub unsafe fn wmi_fire_event(
    guid: *const u8,
    event_data: *const u8,
    event_data_size: u32,
) -> NtStatus {
    let entry = wmip_find_guid(guid);
    if entry.is_null() {
        return STATUS_WMI_GUID_NOT_FOUND;
    }
    // Route through the WMI logger buffer when present.
    if WMI_LOGGER.enabled && !WMI_LOGGER.buffer.is_null() {
        WMI_LOGGER.buffer_count += 1;
    }
    crate::etw::etw_write_transfer(guid, guid, event_data, event_data_size)
}
