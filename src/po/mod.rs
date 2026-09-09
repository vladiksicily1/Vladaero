/// Po - Power Manager (Po/Pop)
use core::ffi::c_void;
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

pub type DevicePowerState = u32;
pub const PowerDeviceUnspecified: DevicePowerState = 0;
pub const PowerDeviceD0: DevicePowerState = 1;
pub const PowerDeviceD1: DevicePowerState = 2;
pub const PowerDeviceD2: DevicePowerState = 3;
pub const PowerDeviceD3: DevicePowerState = 4;
pub const PowerDeviceMaximum: DevicePowerState = 5;

pub type SystemPowerState = u32;
pub const PowerSystemUnspecified: SystemPowerState = 0;
pub const PowerSystemWorking: SystemPowerState = 1;
pub const PowerSystemSleeping1: SystemPowerState = 2;
pub const PowerSystemSleeping2: SystemPowerState = 3;
pub const PowerSystemSleeping3: SystemPowerState = 4;
pub const PowerSystemHibernate: SystemPowerState = 5;
pub const PowerSystemShutdown: SystemPowerState = 6;
pub const PowerSystemMaximum: SystemPowerState = 7;

pub type PowerAction = u32;
pub const PowerActionNone: PowerAction = 0;
pub const PowerActionReserved: PowerAction = 1;
pub const PowerActionSleep: PowerAction = 2;
pub const PowerActionHibernate: PowerAction = 3;
pub const PowerActionShutdown: PowerAction = 4;
pub const PowerActionShutdownReset: PowerAction = 5;
pub const PowerActionShutdownOff: PowerAction = 6;
pub const PowerActionWarmEject: PowerAction = 7;

#[repr(C)]
pub struct PowerState {
    pub system_state: SystemPowerState,
    pub device_state: DevicePowerState,
}

#[repr(C)]
pub struct Irp {
    pub type_: i32,
    pub flags: u32,
    pub mdl_address: *mut c_void,
    pub system_buffer: *mut c_void,
    pub user_buffer: *mut c_void,
    pub io_status: IoStatusBlock,
    pub thread_list_entry: ListEntry,
    pub device_object: *mut c_void,
    pub current_location: i32,
    pub stack_count: i32,
}

pub struct PowerIrpContext {
    pub irp: Irp,
    pub power_state: PowerState,
    pub callback: extern "C" fn(*mut PowerIrpContext),
    pub context: *mut c_void,
}

pub struct PowerDeviceEntry {
    pub device_id: [u16; 256],
    pub current_power_state: DevicePowerState,
    pub desired_power_state: DevicePowerState,
    pub capabilities: u32,
    pub d_state_map: [DevicePowerState; 8],
    pub system_wake: SystemPowerState,
    pub device_wake: DevicePowerState,
}

pub struct SystemPowerStateContext {
    pub current_state: SystemPowerState,
    pub target_state: SystemPowerState,
    pub wake_reason: u32,
    pub policy: [SystemPowerState; 8],
}

static mut SYSTEM_POWER_CONTEXT: SystemPowerStateContext = SystemPowerStateContext {
    current_state: PowerSystemWorking,
    target_state: PowerSystemWorking,
    wake_reason: 0,
    policy: [0; 8],
};

pub unsafe fn po_initialize_power_manager() {
    SYSTEM_POWER_CONTEXT.current_state = PowerSystemWorking;
    SYSTEM_POWER_CONTEXT.target_state = PowerSystemWorking;

    // Set up default power policy
    for i in 0..8 {
        SYSTEM_POWER_CONTEXT.policy[i] = PowerSystemWorking;
    }
    SYSTEM_POWER_CONTEXT.policy[PowerSystemSleeping1 as usize] = PowerSystemWorking;
    SYSTEM_POWER_CONTEXT.policy[PowerSystemSleeping2 as usize] = PowerSystemWorking;
    SYSTEM_POWER_CONTEXT.policy[PowerSystemHibernate as usize] = PowerSystemShutdown;
}

pub unsafe fn po_set_power_state(
    device: *mut c_void,
    power_state: PowerState,
) -> NtStatus {
    if device.is_null() { return STATUS_INVALID_PARAMETER; }
    STATUS_SUCCESS
}

pub unsafe fn po_request_power_irp(
    device: *mut c_void,
    major_function: u8,
    system_context: SystemPowerState,
    callback: extern "C" fn(*mut PowerIrpContext),
    context: *mut c_void,
) -> NtStatus {
    let ctx = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<PowerIrpContext>()) as *mut PowerIrpContext;
    if ctx.is_null() { return STATUS_NO_MEMORY; }

    (*ctx).power_state.system_state = system_context;
    (*ctx).power_state.device_state = PowerDeviceD0;
    (*ctx).callback = callback;
    (*ctx).context = context;

    callback(ctx);
    STATUS_SUCCESS
}

pub unsafe fn po_start_next_power_irp() {
    // Process next power IRP from queue
}

pub unsafe fn pop_system_power_state(target: SystemPowerState) -> NtStatus {
    let current = SYSTEM_POWER_CONTEXT.current_state;
    SYSTEM_POWER_CONTEXT.target_state = target;

    if target == PowerSystemShutdown {
        // Initiate system shutdown
        return STATUS_SUCCESS;
    }

    if target == PowerSystemHibernate {
        // Save system state to hibernation file
        return STATUS_SUCCESS;
    }

    SYSTEM_POWER_CONTEXT.current_state = target;
    STATUS_SUCCESS
}

pub unsafe fn pop_ui_display_state(state: DevicePowerState) {
    match state {
        PowerDeviceD0 => {
            // Turn on display
        }
        PowerDeviceD3 => {
            // Turn off display
        }
        _ => {}
    }
}

pub unsafe fn po_set_system_power_state(
    state: SystemPowerState,
    action: PowerAction,
) -> NtStatus {
    match action {
        PowerActionShutdown => {
            pop_system_power_state(PowerSystemShutdown);
        }
        PowerActionSleep => {
            pop_system_power_state(PowerSystemSleeping1);
        }
        PowerActionHibernate => {
            pop_system_power_state(PowerSystemHibernate);
        }
        _ => {}
    }
    STATUS_SUCCESS
}

pub unsafe fn po_get_system_power_state() -> SystemPowerState {
    SYSTEM_POWER_CONTEXT.current_state
}

pub unsafe fn po_set_device_power_state(
    _device_id: *const u16,
    _state: DevicePowerState,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 Po: power IRP queue, device states, idle, hibernate
// ============================================================

pub const PO_IRP_QUEUE_MAX: usize = 256;

pub const PO_IDLE_CHECK_INTERVAL_MS: u64 = 5000;
pub const PO_DISK_SPIN_DOWN_MS: u64 = 20 * 60 * 1000;

pub const PO_NOTIFY_DEVICE_POWER_ON: u32 = 1;
pub const PO_NOTIFY_DEVICE_POWER_OFF: u32 = 2;
pub const PO_NOTIFY_SYSTEM_SLEEP: u32 = 3;
pub const PO_NOTIFY_SYSTEM_WAKE: u32 = 4;

pub const STATUS_DEVICE_BUSY_LOCAL: NtStatus = 0x80000011;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct PoPowerIrpEntry {
    pub device_object: *mut c_void,
    pub minor_function: u8,
    pub system_state: SystemPowerState,
    pub device_state: DevicePowerState,
    pub shutdown_type: u32,
    pub completed: bool,
}

#[repr(C)]
pub struct PoRegisteredDevice {
    pub device_object: *mut c_void,
    pub device_id: [u16; 64],
    pub current_state: DevicePowerState,
    pub mapped_system_state: [DevicePowerState; 7],
    pub capabilities: u32,
    pub idle_timeout_ms: u64,
    pub last_active_ms: u64,
    pub next: *mut PoRegisteredDevice,
}

static mut PO_POWER_IRP_QUEUE: [PoPowerIrpEntry; PO_IRP_QUEUE_MAX] =
    [PoPowerIrpEntry {
        device_object: core::ptr::null_mut(),
        minor_function: 0,
        system_state: PowerSystemWorking,
        device_state: PowerDeviceD0,
        shutdown_type: 0,
        completed: true,
    }; PO_IRP_QUEUE_MAX];
static mut PO_POWER_IRP_COUNT: usize = 0;
static mut PO_DEVICE_LIST: *mut PoRegisteredDevice = core::ptr::null_mut();
static PO_HIBERNATE_IN_PROGRESS: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(0);

/// PoRegisterDevice - register a device with S/D-state mapping.
pub unsafe fn po_register_device(
    device_object: *mut c_void,
    device_id: *const u16,
    device_out: *mut *mut PoRegisteredDevice,
) -> NtStatus {
    if device_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let d = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<PoRegisteredDevice>(),
    ) as *mut PoRegisteredDevice;
    if d.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(d as *mut u8, 0, core::mem::size_of::<PoRegisteredDevice>());
    (*d).device_object = device_object;
    if !device_id.is_null() {
        let mut i = 0;
        while i < 63 && *device_id.add(i) != 0 {
            (*d).device_id[i] = *device_id.add(i);
            i += 1;
        }
    }
    // Default S0->D0, S1..S3->D3, S4->D3, S5->D3.
    (*d).mapped_system_state[PowerSystemWorking as usize] = PowerDeviceD0;
    (*d).mapped_system_state[PowerSystemSleeping1 as usize] = PowerDeviceD3;
    (*d).mapped_system_state[PowerSystemSleeping2 as usize] = PowerDeviceD3;
    (*d).mapped_system_state[PowerSystemSleeping3 as usize] = PowerDeviceD3;
    (*d).mapped_system_state[PowerSystemHibernate as usize] = PowerDeviceD3;
    (*d).mapped_system_state[PowerSystemShutdown as usize] = PowerDeviceD3;
    (*d).current_state = PowerDeviceD0;
    (*d).next = PO_DEVICE_LIST;
    PO_DEVICE_LIST = d;
    if !device_out.is_null() {
        *device_out = d;
    }
    STATUS_SUCCESS
}

/// PoQueuePowerIrp - enqueue an IRP_MJ_POWER request.
pub unsafe fn po_queue_power_irp(
    device_object: *mut c_void,
    minor_function: u8,
    system_state: SystemPowerState,
    device_state: DevicePowerState,
) -> NtStatus {
    if PO_POWER_IRP_COUNT >= PO_IRP_QUEUE_MAX {
        return STATUS_INSUFFICIENT_RESOURCES;
    }
    let idx = PO_POWER_IRP_COUNT;
    PO_POWER_IRP_QUEUE[idx] = PoPowerIrpEntry {
        device_object,
        minor_function,
        system_state,
        device_state,
        shutdown_type: 0,
        completed: false,
    };
    PO_POWER_IRP_COUNT += 1;
    STATUS_SUCCESS
}

/// PoProcessPowerIrpQueue - dispatch queued power IRPs in order.
pub unsafe fn po_process_power_irp_queue() -> u32 {
    let mut processed = 0u32;
    let mut i = 0;
    while i < PO_POWER_IRP_COUNT {
        if !PO_POWER_IRP_QUEUE[i].completed {
            let entry = PO_POWER_IRP_QUEUE[i];
            // Update the registered device state.
            let mut d = PO_DEVICE_LIST;
            while !d.is_null() {
                if (*d).device_object == entry.device_object {
                    (*d).current_state = entry.device_state;
                    break;
                }
                d = (*d).next;
            }
            PO_POWER_IRP_QUEUE[i].completed = true;
            processed += 1;
        }
        i += 1;
    }
    // Compact.
    let mut w = 0;
    let mut r = 0;
    while r < PO_POWER_IRP_COUNT {
        if !PO_POWER_IRP_QUEUE[r].completed {
            if w != r {
                PO_POWER_IRP_QUEUE[w] = PO_POWER_IRP_QUEUE[r];
            }
            w += 1;
        }
        r += 1;
    }
    PO_POWER_IRP_COUNT = w;
    processed
}

/// PoSetSystemStateFull - S-state transition with device mapping.
///
/// Sends every registered device to its mapped D-state, then flips
/// the system state (like PopSetSystemPowerState).
pub unsafe fn po_set_system_state_full(target: SystemPowerState) -> NtStatus {
    if target >= PowerSystemMaximum {
        return STATUS_INVALID_PARAMETER;
    }
    let current = SYSTEM_POWER_CONTEXT.current_state;
    if current == target {
        return STATUS_SUCCESS;
    }
    if target == PowerSystemHibernate {
        if PO_HIBERNATE_IN_PROGRESS
            .compare_exchange(
                0,
                1,
                core::sync::atomic::Ordering::AcqRel,
                core::sync::atomic::Ordering::Relaxed,
            )
            .is_err()
        {
            return STATUS_DEVICE_BUSY_LOCAL;
        }
    }
    // 1. Move all devices to their mapped D-state.
    let mut d = PO_DEVICE_LIST;
    while !d.is_null() {
        let mapped = (*d).mapped_system_state[target as usize];
        po_queue_power_irp((*d).device_object, 2, target, mapped);
        d = (*d).next;
    }
    po_process_power_irp_queue();
    // 2. Flip system state.
    SYSTEM_POWER_CONTEXT.target_state = target;
    SYSTEM_POWER_CONTEXT.current_state = target;
    if target == PowerSystemHibernate {
        PO_HIBERNATE_IN_PROGRESS.store(0, core::sync::atomic::Ordering::Release);
    }
    STATUS_SUCCESS
}

/// PoNotifyIdle - device idle detection (disk spin-down, display off).
pub unsafe fn po_notify_idle(device_object: *mut c_void, now_ms: u64) {
    let mut d = PO_DEVICE_LIST;
    while !d.is_null() {
        if (*d).device_object == device_object {
            if (*d).idle_timeout_ms > 0
                && now_ms - (*d).last_active_ms >= (*d).idle_timeout_ms
                && (*d).current_state == PowerDeviceD0
            {
                (*d).current_state = PowerDeviceD3;
                po_queue_power_irp(device_object, 2, PowerSystemWorking, PowerDeviceD3);
            }
            break;
        }
        d = (*d).next;
    }
    po_process_power_irp_queue();
}

/// PoNotifyActive - mark a device busy (resets idle timer).
pub unsafe fn po_notify_active(device_object: *mut c_void, now_ms: u64) {
    let mut d = PO_DEVICE_LIST;
    while !d.is_null() {
        if (*d).device_object == device_object {
            (*d).last_active_ms = now_ms;
            if (*d).current_state != PowerDeviceD0 {
                (*d).current_state = PowerDeviceD0;
                po_queue_power_irp(device_object, 2, PowerSystemWorking, PowerDeviceD0);
                po_process_power_irp_queue();
            }
            break;
        }
        d = (*d).next;
    }
}

/// PoQueryDevicePowerState - current D-state of a device.
pub unsafe fn po_query_device_power_state(
    device_object: *mut c_void,
) -> DevicePowerState {
    let mut d = PO_DEVICE_LIST;
    while !d.is_null() {
        if (*d).device_object == device_object {
            return (*d).current_state;
        }
        d = (*d).next;
    }
    PowerDeviceUnspecified
}
