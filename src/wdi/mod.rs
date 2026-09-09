/// Wdi - Windows Diagnostic Infrastructure (Wdi/Wdip)
use crate::types::*;

pub struct WdiIssue {
    pub issue_id: u64,
    pub issue_type: u32,
    pub severity: u32,
    pub timestamp: u64,
    pub component: [u16; 64],
    pub description: [u16; 256],
}

pub unsafe fn wdi_initialize() -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn wdi_track_issue(
    _issue_type: u32,
    _severity: u32,
    _component: *const u16,
    _description: *const u16,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn wdi_diagnose_execution(
    _process_name: *const u16,
    _exit_code: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 WDI: WLAN Device Interface (miniport <-> WDI framework)
// ============================================================

use core::ffi::c_void;

pub const WDI_PORT_TYPE_STA: u32 = 1;
pub const WDI_PORT_TYPE_AP: u32 = 2;
pub const WDI_PORT_TYPE_P2P_DEVICE: u32 = 3;
pub const WDI_PORT_TYPE_P2P_GO: u32 = 4;
pub const WDI_PORT_TYPE_P2P_CLIENT: u32 = 5;

pub const WDI_ASSOC_STATE_DISCONNECTED: u32 = 0;
pub const WDI_ASSOC_STATE_SCANNING: u32 = 1;
pub const WDI_ASSOC_STATE_AUTHENTICATING: u32 = 2;
pub const WDI_ASSOC_STATE_ASSOCIATING: u32 = 3;
pub const WDI_ASSOC_STATE_ASSOCIATED: u32 = 4;
pub const WDI_ASSOC_STATE_ROAMING: u32 = 5;

pub const WDI_SCAN_TYPE_ACTIVE: u32 = 1;
pub const WDI_SCAN_TYPE_PASSIVE: u32 = 2;

pub const WDI_MAX_SSID_LENGTH: usize = 32;
pub const WDI_MAX_BSS_ENTRIES: usize = 64;
pub const WDI_MAC_ADDRESS_LENGTH: usize = 6;

pub const WDI_TASK_OPEN: u32 = 1;
pub const WDI_TASK_CLOSE: u32 = 2;
pub const WDI_TASK_SCAN: u32 = 3;
pub const WDI_TASK_CONNECT: u32 = 4;
pub const WDI_TASK_DISCONNECT: u32 = 5;
pub const WDI_TASK_SET_RADIO_STATE: u32 = 6;
pub const WDI_TASK_SEND: u32 = 7;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct WdiMacAddress {
    pub bytes: [u8; WDI_MAC_ADDRESS_LENGTH],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct WdiSsid {
    pub length: u8,
    pub bytes: [u8; WDI_MAX_SSID_LENGTH],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct WdiBssEntry {
    pub bssid: WdiMacAddress,
    pub ssid: WdiSsid,
    pub channel: u32,
    pub band: u32,
    pub rssi: i32,
    pub link_quality: u32,
    pub beacon_period: u32,
    pub privacy: bool,
}

#[repr(C)]
pub struct WdiTask {
    pub task_id: u32,
    pub task_type: u32,
    pub port_id: u32,
    pub status: NtStatus,
    pub completed: bool,
    pub next: *mut WdiTask,
}

#[repr(C)]
pub struct WdiPort {
    pub port_id: u32,
    pub port_type: u32,
    pub mac_address: WdiMacAddress,
    pub op_mode: u32,
    pub assoc_state: u32,
    pub ssid: WdiSsid,
    pub bssid: WdiMacAddress,
    pub channel: u32,
    pub tx_packets: u64,
    pub rx_packets: u64,
    pub next: *mut WdiPort,
}

#[repr(C)]
pub struct WdiAdapter {
    pub adapter_id: u32,
    pub permanent_address: WdiMacAddress,
    pub radio_on: bool,
    pub ports: *mut WdiPort,
    pub port_count: u32,
    pub tasks: *mut WdiTask,
    pub bss_list: [WdiBssEntry; WDI_MAX_BSS_ENTRIES],
    pub bss_count: u32,
    pub scan_in_progress: bool,
    pub next: *mut WdiAdapter,
}

static mut WDI_ADAPTER_LIST: *mut WdiAdapter = core::ptr::null_mut();
static WDI_NEXT_ADAPTER_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);
static WDI_NEXT_PORT_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);
static WDI_NEXT_TASK_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);

unsafe fn wdip_find_adapter(id: u32) -> *mut WdiAdapter {
    let mut cur = WDI_ADAPTER_LIST;
    while !cur.is_null() {
        if (*cur).adapter_id == id {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

unsafe fn wdip_find_port(adapter: *mut WdiAdapter, port_id: u32) -> *mut WdiPort {
    let mut cur = (*adapter).ports;
    while !cur.is_null() {
        if (*cur).port_id == port_id {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// WdiRegisterAdapter - a miniport registers its WLAN adapter.
pub unsafe fn wdi_register_adapter(
    permanent_address: *const u8,
    adapter_out: *mut *mut WdiAdapter,
) -> NtStatus {
    if permanent_address.is_null() || adapter_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let a = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<WdiAdapter>(),
    ) as *mut WdiAdapter;
    if a.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(a as *mut u8, 0, core::mem::size_of::<WdiAdapter>());
    core::ptr::copy_nonoverlapping(
        permanent_address,
        (*a).permanent_address.bytes.as_mut_ptr(),
        WDI_MAC_ADDRESS_LENGTH,
    );
    (*a).radio_on = true;
    (*a).adapter_id =
        WDI_NEXT_ADAPTER_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*a).next = WDI_ADAPTER_LIST;
    WDI_ADAPTER_LIST = a;
    *adapter_out = a;
    STATUS_SUCCESS
}

/// WdiCreatePort - create a STA/AP/P2P port on the adapter.
pub unsafe fn wdi_create_port(
    adapter: *mut WdiAdapter,
    port_type: u32,
    mac_address: *const u8,
    port_out: *mut *mut WdiPort,
) -> NtStatus {
    if adapter.is_null() || port_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let p = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<WdiPort>(),
    ) as *mut WdiPort;
    if p.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(p as *mut u8, 0, core::mem::size_of::<WdiPort>());
    (*p).port_type = port_type;
    if !mac_address.is_null() {
        core::ptr::copy_nonoverlapping(
            mac_address,
            (*p).mac_address.bytes.as_mut_ptr(),
            WDI_MAC_ADDRESS_LENGTH,
        );
    } else {
        (*p).mac_address = (*adapter).permanent_address;
    }
    (*p).assoc_state = WDI_ASSOC_STATE_DISCONNECTED;
    (*p).port_id = WDI_NEXT_PORT_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*p).next = (*adapter).ports;
    (*adapter).ports = p;
    (*adapter).port_count += 1;
    *port_out = p;
    STATUS_SUCCESS
}

/// WdiQueueTask - enqueue an OID task (open/scan/connect/...).
pub unsafe fn wdi_queue_task(
    adapter_id: u32,
    task_type: u32,
    port_id: u32,
    task_out: *mut *mut WdiTask,
) -> NtStatus {
    let a = wdip_find_adapter(adapter_id);
    if a.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    let t = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<WdiTask>(),
    ) as *mut WdiTask;
    if t.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(t as *mut u8, 0, core::mem::size_of::<WdiTask>());
    (*t).task_type = task_type;
    (*t).port_id = port_id;
    (*t).task_id = WDI_NEXT_TASK_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*t).status = crate::nt::STATUS_PENDING;
    (*t).next = (*a).tasks;
    (*a).tasks = t;
    if !task_out.is_null() {
        *task_out = t;
    }
    STATUS_SUCCESS
}

/// WdiCompleteTask - mark a task done and run its state transition.
pub unsafe fn wdi_complete_task(
    adapter_id: u32,
    task_id: u32,
    status: NtStatus,
) -> NtStatus {
    let a = wdip_find_adapter(adapter_id);
    if a.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    let mut prev: *mut WdiTask = core::ptr::null_mut();
    let mut cur = (*a).tasks;
    while !cur.is_null() {
        if (*cur).task_id == task_id {
            (*cur).status = status;
            (*cur).completed = true;
            // State transitions.
            let port = wdip_find_port(a, (*cur).port_id);
            if !port.is_null() {
                match (*cur).task_type {
                    WDI_TASK_SCAN => {
                        (*a).scan_in_progress = false;
                        if status == STATUS_SUCCESS {
                            (*port).assoc_state = WDI_ASSOC_STATE_DISCONNECTED;
                        }
                    }
                    WDI_TASK_CONNECT => {
                        if status == STATUS_SUCCESS {
                            (*port).assoc_state = WDI_ASSOC_STATE_ASSOCIATED;
                        } else {
                            (*port).assoc_state = WDI_ASSOC_STATE_DISCONNECTED;
                        }
                    }
                    WDI_TASK_DISCONNECT => {
                        (*port).assoc_state = WDI_ASSOC_STATE_DISCONNECTED;
                    }
                    WDI_TASK_SET_RADIO_STATE => {}
                    _ => {}
                }
            }
            if prev.is_null() {
                (*a).tasks = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// WdiAddBssEntry - cache a scan result.
pub unsafe fn wdi_add_bss_entry(
    adapter_id: u32,
    entry: *const WdiBssEntry,
) -> NtStatus {
    let a = wdip_find_adapter(adapter_id);
    if a.is_null() || entry.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*a).bss_count >= WDI_MAX_BSS_ENTRIES as u32 {
        // Evict the weakest entry.
        let mut worst = 0u32;
        let mut i = 1u32;
        while i < (*a).bss_count {
            if (*a).bss_list[i as usize].rssi < (*a).bss_list[worst as usize].rssi {
                worst = i;
            }
            i += 1;
        }
        (*a).bss_list[worst as usize] = *entry;
        return STATUS_SUCCESS;
    }
    (*a).bss_list[(*a).bss_count as usize] = *entry;
    (*a).bss_count += 1;
    STATUS_SUCCESS
}

/// WdiQueryBssList - copy cached scan results out.
pub unsafe fn wdi_query_bss_list(
    adapter_id: u32,
    buffer: *mut WdiBssEntry,
    count_in_out: *mut u32,
) -> NtStatus {
    let a = wdip_find_adapter(adapter_id);
    if a.is_null() || count_in_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if buffer.is_null() || *count_in_out < (*a).bss_count {
        *count_in_out = (*a).bss_count;
        return STATUS_BUFFER_TOO_SMALL;
    }
    let mut i = 0u32;
    while i < (*a).bss_count {
        *buffer.add(i as usize) = (*a).bss_list[i as usize];
        i += 1;
    }
    *count_in_out = (*a).bss_count;
    STATUS_SUCCESS
}

/// WdiSetRadioState - software radio on/off.
pub unsafe fn wdi_set_radio_state(adapter_id: u32, radio_on: bool) -> NtStatus {
    let a = wdip_find_adapter(adapter_id);
    if a.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    (*a).radio_on = radio_on;
    if !radio_on {
        // Disconnect all ports.
        let mut p = (*a).ports;
        while !p.is_null() {
            (*p).assoc_state = WDI_ASSOC_STATE_DISCONNECTED;
            p = (*p).next;
        }
    }
    STATUS_SUCCESS
}
