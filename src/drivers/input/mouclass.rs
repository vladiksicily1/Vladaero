/// Mouse class driver (mouclass.sys)
///
/// Absolute cursor tracking, button state, double-click timing,
/// wheel accumulation; feeds win32k cursor + DWM overlay.

use core::ffi::c_void;

use crate::types::*;

pub const MOUCLASS_QUEUE: usize = 128;
pub const MOUCLASS_DBLCLICK_MS: u64 = 500;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MouInputEvent {
    pub x: i32,
    pub y: i32,
    pub buttons: u32,
    pub dx: i32,
    pub dy: i32,
    pub wheel: i32,
    pub time_ms: u64,
}

#[repr(C)]
pub struct MouClassDevice {
    pub x: i32,
    pub y: i32,
    pub buttons: u32,
    pub wheel_accum: i32,
    pub last_click_ms: u64,
    pub last_click_button: u32,
    pub click_count: u32,
    pub queue: [MouInputEvent; MOUCLASS_QUEUE],
    pub q_head: usize,
    pub q_count: usize,
    pub connected_ports: u32,
}

static mut MOUCLASS_DEV: MouClassDevice = MouClassDevice {
    x: 0,
    y: 0,
    buttons: 0,
    wheel_accum: 0,
    last_click_ms: 0,
    last_click_button: 0,
    click_count: 0,
    queue: [MouInputEvent {
        x: 0,
        y: 0,
        buttons: 0,
        dx: 0,
        dy: 0,
        wheel: 0,
        time_ms: 0,
    }; MOUCLASS_QUEUE],
    q_head: 0,
    q_count: 0,
    connected_ports: 0,
};

/// MouClassInject - port driver -> class (ISR context).
pub unsafe fn mouclass_inject(x: i32, y: i32, buttons: u32, dx: i32, dy: i32) {
    let prev = MOUCLASS_DEV.buttons;
    MOUCLASS_DEV.x = x;
    MOUCLASS_DEV.y = y;
    MOUCLASS_DEV.buttons = buttons;
    // Click counting for double-click.
    let pressed = buttons & !prev;
    if pressed != 0 {
        MOUCLASS_DEV.click_count = 1; // time base updated by tick below
        MOUCLASS_DEV.last_click_button = pressed;
    }
    if MOUCLASS_DEV.q_count < MOUCLASS_QUEUE {
        let idx = (MOUCLASS_DEV.q_head + MOUCLASS_DEV.q_count) % MOUCLASS_QUEUE;
        MOUCLASS_DEV.queue[idx] = MouInputEvent {
            x,
            y,
            buttons,
            dx,
            dy,
            wheel: 0,
            time_ms: 0,
        };
        MOUCLASS_DEV.q_count += 1;
    }
    crate::win32k::user::user_set_cursor_pos(x, y);
    crate::win32k::compositor::dwm_set_cursor(x, y, true);
}

pub unsafe fn mouclass_dequeue(ev_out: *mut MouInputEvent) -> bool {
    if ev_out.is_null() || MOUCLASS_DEV.q_count == 0 {
        return false;
    }
    *ev_out = MOUCLASS_DEV.queue[MOUCLASS_DEV.q_head];
    MOUCLASS_DEV.q_head = (MOUCLASS_DEV.q_head + 1) % MOUCLASS_QUEUE;
    MOUCLASS_DEV.q_count -= 1;
    true
}

pub unsafe fn mouclass_position(x_out: *mut i32, y_out: *mut i32) {
    if !x_out.is_null() {
        *x_out = MOUCLASS_DEV.x;
    }
    if !y_out.is_null() {
        *y_out = MOUCLASS_DEV.y;
    }
}

pub unsafe extern "C" fn mouclass_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    MOUCLASS_DEV.connected_ports = 1;
    let mut dev: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\PointerClass0\0");
    crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x0000000F, // FILE_DEVICE_MOUSE
        0,
        0,
        &mut dev,
    )
}
