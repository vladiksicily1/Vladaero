/// Keyboard class driver (kbdclass.sys)
///
/// Connects port drivers (i8042, HID) to the input stack: per-key
/// state, LED tracking, focus-thread routing into win32k.

use core::ffi::c_void;

use crate::types::*;

pub const KBDCLASS_MAX_KEYS: usize = 256;
pub const KBDCLASS_QUEUE: usize = 128;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KbdInputEvent {
    pub vk: u8,
    pub ch: u8,
    pub down: bool,
    pub time_ms: u64,
}

#[repr(C)]
pub struct KbdClassDevice {
    pub key_state: [bool; KBDCLASS_MAX_KEYS],
    pub leds: u8, // bit0 Scroll, bit1 Num, bit2 Caps
    pub queue: [KbdInputEvent; KBDCLASS_QUEUE],
    pub q_head: usize,
    pub q_count: usize,
    pub connected_ports: u32,
}

static mut KBDCLASS_DEV: KbdClassDevice = KbdClassDevice {
    key_state: [false; KBDCLASS_MAX_KEYS],
    leds: 0,
    queue: [KbdInputEvent {
        vk: 0,
        ch: 0,
        down: false,
        time_ms: 0,
    }; KBDCLASS_QUEUE],
    q_head: 0,
    q_count: 0,
    connected_ports: 0,
};

/// KbdClassInject - port driver -> class (ISR context).
pub unsafe fn kbdclass_inject(vk: u8, ch: u8, down: bool) {
    if (vk as usize) < KBDCLASS_MAX_KEYS {
        KBDCLASS_DEV.key_state[vk as usize] = down;
    }
    if vk == 0x14 && down {
        KBDCLASS_DEV.leds ^= 0x04; // Caps
    }
    if vk == 0x90 && down {
        KBDCLASS_DEV.leds ^= 0x02; // Num
    }
    if KBDCLASS_DEV.q_count < KBDCLASS_QUEUE {
        let idx = (KBDCLASS_DEV.q_head + KBDCLASS_DEV.q_count) % KBDCLASS_QUEUE;
        KBDCLASS_DEV.queue[idx] = KbdInputEvent {
            vk,
            ch,
            down,
            time_ms: 0,
        };
        KBDCLASS_DEV.q_count += 1;
    }
}

/// KbdClassDequeue - win32k input thread consumes events.
pub unsafe fn kbdclass_dequeue(ev_out: *mut KbdInputEvent) -> bool {
    if ev_out.is_null() || KBDCLASS_DEV.q_count == 0 {
        return false;
    }
    *ev_out = KBDCLASS_DEV.queue[KBDCLASS_DEV.q_head];
    KBDCLASS_DEV.q_head = (KBDCLASS_DEV.q_head + 1) % KBDCLASS_QUEUE;
    KBDCLASS_DEV.q_count -= 1;
    true
}

pub unsafe fn kbdclass_key_down(vk: u8) -> bool {
    if (vk as usize) < KBDCLASS_MAX_KEYS {
        KBDCLASS_DEV.key_state[vk as usize]
    } else {
        false
    }
}

pub unsafe extern "C" fn kbdclass_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    KBDCLASS_DEV.connected_ports = 1;
    let mut dev: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\KeyboardClass0\0");
    crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x0000000B, // FILE_DEVICE_KEYBOARD
        0,
        0,
        &mut dev,
    )
}
