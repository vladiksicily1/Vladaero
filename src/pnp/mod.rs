/// PnP - Plug and Play Manager (Pp/Pnp/Pi/Pip)
use core::ffi::c_void;
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

pub const MAX_DEVICE_NODE_LEVEL: u32 = 20;

pub const DeviceNodeUninitialized: u32 = 0;
pub const DeviceNodeInitialized: u32 = 1;
pub const DeviceNodeStartPending: u32 = 2;
pub const DeviceNodeStartPostStarted: u32 = 3;
pub const DeviceNodeStarted: u32 = 4;
pub const DeviceNodeStartCompletion: u32 = 5;
pub const DeviceNodeUnspecifiedProblem: u32 = 6;
pub const DeviceNodeNeedsEject: u32 = 7;
pub const DeviceNodeHid: u32 = 8;

#[repr(C)]
pub struct DeviceNode {
    pub next: *mut DeviceNode,
    pub child: *mut DeviceNode,
    pub sibling: *mut DeviceNode,
    pub parent: *mut DeviceNode,
    pub state: u32,
    pub problem: u32,
    pub flags: u32,
    pub bus_type_guid: [u8; 16],
    pub enumerator_name: [u16; 64],
    pub instance_name: [u16; 128],
    pub hardware_id: [u16; 256],
    pub compatible_ids: [u16; 256],
    pub device_id: [u16; 256],
    pub level: u32,
    pub disable_count: u32,
    pub children_link: ListEntry,
    pub sibling_link: ListEntry,
    pub target_device: *mut c_void,
    pub physical_device_object: *mut c_void,
    pub attached_device: *mut c_void,
    pub dock_info: *mut c_void,
}

#[repr(C)]
pub struct BusRelations {
    pub count: u32,
    pub objects: [*mut c_void; 32],
}

#[repr(C)]
pub struct DeviceActionWorkItem {
    pub work_item: WorkItem,
    pub device_node: *mut DeviceNode,
    pub action: DeviceAction,
}

#[repr(u32)]
pub enum DeviceAction {
    None = 0,
    StartDevice = 1,
    EjectDevice = 2,
    RemoveDevice = 3,
    MoveDevice = 4,
    RequestDeviceEject = 5,
    EnableDevice = 6,
    DisableDevice = 7,
    QuiesceDevice = 8,
}

pub struct WorkItem {
    pub list_entry: ListEntry,
    pub worker_routine: extern "C" fn(*mut WorkItem),
    pub context: *mut c_void,
}

static mut DEVICE_TREE_ROOT: *mut DeviceNode = core::ptr::null_mut();
static mut PNP_LOCK: u64 = 0;

pub unsafe fn ppnp_init_system() -> NtStatus {
    DEVICE_TREE_ROOT = core::ptr::null_mut();
    STATUS_SUCCESS
}

pub unsafe fn io_init_system(_boot_arg: u64) -> NtStatus {
    ppnp_init_system();

    let status = ppnp_create_root_device_node();
    if status != STATUS_SUCCESS {
        return status;
    }

    ppnp_process_boot_devices();
    STATUS_SUCCESS
}

unsafe fn ppnp_create_root_device_node() -> NtStatus {
    let node = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<DeviceNode>()) as *mut DeviceNode;
    if node.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(node as *mut u8, 0, core::mem::size_of::<DeviceNode>());
    (*node).state = DeviceNodeStarted;
    (*node).level = 0;
    DEVICE_TREE_ROOT = node;
    STATUS_SUCCESS
}

unsafe fn ppnp_process_boot_devices() {
    // Enumerate boot-start devices from registry
    let mut key_handle: Handle = core::ptr::null_mut();
    let key_name: [u16; 49] = utf16_lit("\\Registry\\Machine\\System\\CurrentControlSet\\Services\0");
    let mut key_name_str = UnicodeString {
        length: ((key_name.len() - 1) * 2) as Ushort,
        maximum_length: (key_name.len() * 2) as Ushort,
        buffer: key_name.as_ptr(),
    };
    let mut status = crate::cm::cm_open_key(
        core::ptr::null_mut(),
        &mut key_name_str,
        0,
        0x20019,
        core::ptr::null_mut(),
        &mut key_handle,
    );
    if status != STATUS_SUCCESS { return; }

    let mut index = 0u32;
    loop {
        let mut name_buf = [0u16; 256];
        let mut name_len: u32 = 512;
        status = crate::cm::cm_enumerate_key(
            key_handle,
            index,
            0,
            &mut name_buf as *mut _ as *mut c_void,
            name_len,
            &mut name_len,
        );
        if status != STATUS_SUCCESS { break; }

        let mut sub_handle: Handle = core::ptr::null_mut();
        status = crate::cm::cm_open_subkey(key_handle, name_buf.as_ptr(), &mut sub_handle);
        if status == STATUS_SUCCESS {
            let mut type_val = 0u32;
            let mut type_len = core::mem::size_of::<u32>() as u32;
            let val_name: [u16; 5] = utf16_lit("Type\0");
            let mut val_name_str = UnicodeString {
                length: ((val_name.len() - 1) * 2) as Ushort,
                maximum_length: (val_name.len() * 2) as Ushort,
                buffer: val_name.as_ptr(),
            };
            let status2 = crate::cm::cm_query_value_key(
                sub_handle,
                &mut val_name_str,
                0,
                &mut type_val as *mut _ as *mut c_void,
                type_len,
                &mut type_len,
            );
            if status2 == STATUS_SUCCESS && type_val == 0 {
                ppnp_start_device(sub_handle);
            }
            crate::ob::ob_close_handle(sub_handle, 0);
        }

        index += 1;
    }

    crate::ob::ob_close_handle(key_handle, 0);
}

const fn utf16_lit<const N: usize>(s: &str) -> [u16; N] {
    let bytes = s.as_bytes();
    let mut out = [0u16; N];
    let mut i = 0;
    while i < bytes.len() && i < N {
        out[i] = bytes[i] as u16;
        i += 1;
    }
    out
}

unsafe fn ppnp_start_device(_service_handle: Handle) {
    // Start the device driver
}

pub unsafe fn ppnp_report_new_device(
    bus_driver: *mut c_void,
    device_id: *const u16,
) -> NtStatus {
    let node = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<DeviceNode>()) as *mut DeviceNode;
    if node.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::write_bytes(node as *mut u8, 0, core::mem::size_of::<DeviceNode>());

    let mut i = 0;
    while i < 255 && *device_id.add(i) != 0 {
        (*node).device_id[i] = *device_id.add(i);
        i += 1;
    }
    (*node).state = DeviceNodeInitialized;
    (*node).target_device = bus_driver;

    ppnp_attach_device_node(node);
    STATUS_SUCCESS
}

unsafe fn ppnp_attach_device_node(node: *mut DeviceNode) {
    if DEVICE_TREE_ROOT.is_null() {
        DEVICE_TREE_ROOT = node;
    } else {
        (*node).sibling = (*DEVICE_TREE_ROOT).child;
        (*DEVICE_TREE_ROOT).child = node;
        (*node).parent = DEVICE_TREE_ROOT;
    }
}

pub unsafe fn ppnp_device_action_request(
    node: *mut DeviceNode,
    action: DeviceAction,
) -> NtStatus {
    if node.is_null() { return STATUS_INVALID_PARAMETER; }

    match action {
        DeviceAction::StartDevice => {
            (*node).state = DeviceNodeStartPending;
            (*node).state = DeviceNodeStarted;
        }
        DeviceAction::RemoveDevice => {
            (*node).state = DeviceNodeUnspecifiedProblem;
        }
        DeviceAction::EjectDevice => {
            (*node).state = DeviceNodeNeedsEject;
        }
        _ => {}
    }
    STATUS_SUCCESS
}

pub unsafe fn io_register_plug_play_notification(
    _event_category: u32,
    _event_data: *const u8,
    _event_data_size: u32,
    _callback: *mut c_void,
    _context: *mut c_void,
    _entry: *mut *mut c_void,
) -> NtStatus {
    // Register for PnP event notifications
    STATUS_SUCCESS
}

pub unsafe fn io_unregister_plug_play_notification(_entry: *mut c_void) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn ppnp_get_device_node(_pdo: *mut c_void) -> *mut DeviceNode {
    DEVICE_TREE_ROOT
}

// ============================================================
// Win10 PnP: start/remove/eject, resources, driver binding
// ============================================================

pub const PNP_RESOURCE_TYPE_MEMORY: u32 = 0;
pub const PNP_RESOURCE_TYPE_IO_PORT: u32 = 1;
pub const PNP_RESOURCE_TYPE_IRQ: u32 = 2;
pub const PNP_RESOURCE_TYPE_DMA: u32 = 3;

pub const PNP_PROBLEM_NONE: u32 = 0;
pub const PNP_PROBLEM_NO_DRIVER: u32 = 28;
pub const PNP_PROBLEM_FAILED_START: u32 = 10;
pub const PNP_PROBLEM_CONFLICT: u32 = 12;
pub const PNP_PROBLEM_DISABLED: u32 = 22;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct PnpResourceRequirement {
    pub resource_type: u32,
    pub min: u64,
    pub max: u64,
    pub length: u64,
    pub alignment: u64,
    pub allocated: u64,
}

#[repr(C)]
pub struct PnpDeviceFull {
    pub node: DeviceNode,
    pub resources: [PnpResourceRequirement; 8],
    pub resource_count: u32,
    pub driver_object: *mut c_void,
    pub device_object: *mut c_void,
    pub started: bool,
    pub list_next: *mut PnpDeviceFull,
}

unsafe fn pnpp_copy_id(dst: *mut u16, src: *const u16) {
    if src.is_null() {
        return;
    }
    let mut i = 0;
    while i < 255 && *src.add(i) != 0 {
        *dst.add(i) = *src.add(i);
        i += 1;
    }
    *dst.add(i) = 0;
}

static mut PNP_DEVICE_LIST: *mut PnpDeviceFull = core::ptr::null_mut();

unsafe fn pnpp_conflicts(
    resource_type: u32,
    cand: u64,
    length: u64,
    skip: *mut PnpDeviceFull,
) -> bool {
    let mut cur = PNP_DEVICE_LIST;
    while !cur.is_null() {
        if cur != skip {
            let mut j = 0u32;
            while j < (*cur).resource_count {
                let o = &(*cur).resources[j as usize];
                if o.allocated != 0
                    && o.resource_type == resource_type
                    && cand <= o.allocated + o.length - 1
                    && o.allocated <= cand + length - 1
                {
                    return true;
                }
                j += 1;
            }
        }
        cur = (*cur).list_next;
    }
    false
}

/// PnpCreateDeviceFull - create a devnode with resource requirements.
pub unsafe fn pnp_create_device_full(
    parent: *mut DeviceNode,
    hardware_id: *const u16,
    compatible_ids: *const u16,
    device_out: *mut *mut PnpDeviceFull,
) -> NtStatus {
    if device_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let d = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<PnpDeviceFull>(),
    ) as *mut PnpDeviceFull;
    if d.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(d as *mut u8, 0, core::mem::size_of::<PnpDeviceFull>());
    (*d).node.state = DeviceNodeInitialized;
    (*d).node.parent = parent;
    pnpp_copy_id((*d).node.hardware_id.as_mut_ptr(), hardware_id);
    pnpp_copy_id((*d).node.compatible_ids.as_mut_ptr(), compatible_ids);
    if !parent.is_null() {
        (*d).node.level = (*parent).level + 1;
        (*d).node.sibling = (*parent).child;
        (*parent).child = &mut (*d).node as *mut DeviceNode;
    }
    (*d).list_next = PNP_DEVICE_LIST;
    PNP_DEVICE_LIST = d;
    ppnp_attach_device_node(&mut (*d).node as *mut DeviceNode);
    *device_out = d;
    STATUS_SUCCESS
}

/// PnpAddResourceRequirement - declare a memory/IRQ/IO/DMA need.
pub unsafe fn pnp_add_resource_requirement(
    device: *mut PnpDeviceFull,
    resource_type: u32,
    min: u64,
    max: u64,
    length: u64,
    alignment: u64,
) -> NtStatus {
    if device.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*device).resource_count >= 8 {
        return STATUS_BUFFER_OVERFLOW;
    }
    let idx = (*device).resource_count as usize;
    (*device).resources[idx] = PnpResourceRequirement {
        resource_type,
        min,
        max,
        length,
        alignment,
        allocated: 0,
    };
    (*device).resource_count += 1;
    STATUS_SUCCESS
}

/// PnpStartDevice - bind best driver (drvdb ranking) + allocate resources.
///
/// Mirrors IopStartDevice: pick driver, send IRP_MN_START_DEVICE with
/// allocated resources, flip devnode state to Started.
pub unsafe fn pnp_start_device(device: *mut PnpDeviceFull) -> NtStatus {
    if device.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*device).started {
        return STATUS_SUCCESS;
    }
    (*device).node.state = DeviceNodeStartPending;
    // 1. Allocate resources: first aligned address with no conflict.
    let mut i = 0u32;
    while i < (*device).resource_count {
        let r = &mut (*device).resources[i as usize];
        let align = if r.alignment == 0 { 1 } else { r.alignment };
        let mut cand = (r.min + align - 1) / align * align;
        let mut ok = false;
        let mut guard = 0u32;
        while cand + r.length - 1 <= r.max && guard < 65536 {
            if !pnpp_conflicts(r.resource_type, cand, r.length, device) {
                ok = true;
                break;
            }
            cand += align;
            guard += 1;
        }
        if !ok {
            (*device).node.state = DeviceNodeUnspecifiedProblem;
            (*device).node.problem = PNP_PROBLEM_CONFLICT;
            return crate::mm::virt::STATUS_CONFLICTING_ADDRESSES;
        }
        r.allocated = cand;
        i += 1;
    }
    // 2. Bind best driver via the driver database ranking.
    let mut best: *mut crate::drvdb::DrvDbPackage = core::ptr::null_mut();
    let st = crate::drvdb::drvdb_select_best_driver(
        (*device).node.hardware_id.as_ptr(),
        &mut best,
    );
    if st != STATUS_SUCCESS || best.is_null() {
        (*device).node.state = DeviceNodeUnspecifiedProblem;
        (*device).node.problem = PNP_PROBLEM_NO_DRIVER;
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    // 3. Mark started (driver's AddDevice/Start path runs via io).
    (*device).node.state = DeviceNodeStarted;
    (*device).node.problem = PNP_PROBLEM_NONE;
    (*device).started = true;
    STATUS_SUCCESS
}

/// PnpRemoveDevice - stop + free resources + mark removed.
pub unsafe fn pnp_remove_device(device: *mut PnpDeviceFull) -> NtStatus {
    if device.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    (*device).started = false;
    (*device).node.state = DeviceNodeUnspecifiedProblem;
    (*device).node.problem = PNP_PROBLEM_DISABLED;
    let mut i = 0u32;
    while i < (*device).resource_count {
        (*device).resources[i as usize].allocated = 0;
        i += 1;
    }
    STATUS_SUCCESS
}

/// PnpRequestEject - surprise/eject flow for removable devices.
pub unsafe fn pnp_request_eject(device: *mut PnpDeviceFull) -> NtStatus {
    if device.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if !(*device).started {
        return STATUS_INVALID_PARAMETER;
    }
    (*device).node.state = DeviceNodeNeedsEject;
    // Flush + stop.
    pnp_remove_device(device);
    (*device).node.state = DeviceNodeNeedsEject;
    STATUS_SUCCESS
}

/// PnpQueryResources - copy allocated resources out.
pub unsafe fn pnp_query_resources(
    device: *mut PnpDeviceFull,
    buffer: *mut PnpResourceRequirement,
    count_in_out: *mut u32,
) -> NtStatus {
    if device.is_null() || count_in_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if buffer.is_null() || *count_in_out < (*device).resource_count {
        *count_in_out = (*device).resource_count;
        return STATUS_BUFFER_TOO_SMALL;
    }
    let mut i = 0u32;
    while i < (*device).resource_count {
        *buffer.add(i as usize) = (*device).resources[i as usize];
        i += 1;
    }
    *count_in_out = (*device).resource_count;
    STATUS_SUCCESS
}
