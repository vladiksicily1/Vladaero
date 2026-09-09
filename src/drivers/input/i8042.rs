/// i8042 PS/2 controller (i8042prt.sys)
///
/// Controller init + self-test, keyboard (port 1) scancode set 1
/// decode with full shift/caps handling, mouse (port 2) 3-byte
/// packets. Injects into kbdclass/mouclass and win32k input.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::{port_inb, port_outb};

// Ports.
pub const I8042_DATA: u16 = 0x60;
pub const I8042_STATUS: u16 = 0x64;
pub const I8042_CMD: u16 = 0x64;

pub const I8042_ST_OBF: u8 = 0x01;
pub const I8042_ST_IBF: u8 = 0x02;

pub const I8042_CMD_READ_CFG: u8 = 0x20;
pub const I8042_CMD_WRITE_CFG: u8 = 0x60;
pub const I8042_CMD_SELF_TEST: u8 = 0xAA;
pub const I8042_CMD_TEST_PORT1: u8 = 0xAB;
pub const I8042_CMD_ENABLE_PORT1: u8 = 0xAE;
pub const I8042_CMD_DISABLE_PORT1: u8 = 0xAD;
pub const I8042_CMD_ENABLE_PORT2: u8 = 0xA8;
pub const I8042_CMD_TEST_PORT2: u8 = 0xA9;
pub const I8042_CMD_WRITE_PORT2: u8 = 0xD4;

pub const I8042_CFG_IRQ1: u8 = 0x01;
pub const I8042_CFG_IRQ2: u8 = 0x02;

static mut I8042_PRESENT: bool = false;
static mut I8042_MOUSE_PRESENT: bool = false;

// Keyboard shift state.
static mut KBD_SHIFT: bool = false;
static mut KBD_CTRL: bool = false;
static mut KBD_ALT: bool = false;
static mut KBD_CAPS: bool = false;
static mut KBD_EXTENDED: bool = false;

// Scancode set 1 -> VK + unshifted/shifted char (US layout).
// Returns (vk, normal, shifted).
fn kbd_decode(sc: u8) -> (u8, u8, u8) {
    match sc {
        0x01 => (0x1B, 0x1B, 0x1B), // Esc
        0x02 => (0x31, b'1', b'!'),
        0x03 => (0x32, b'2', b'@'),
        0x04 => (0x33, b'3', b'#'),
        0x05 => (0x34, b'4', b'$'),
        0x06 => (0x35, b'5', b'%'),
        0x07 => (0x36, b'6', b'^'),
        0x08 => (0x37, b'7', b'&'),
        0x09 => (0x38, b'8', b'*'),
        0x0A => (0x39, b'9', b'('),
        0x0B => (0x30, b'0', b')'),
        0x0C => (0xBD, b'-', b'_'),
        0x0D => (0xBB, b'=', b'+'),
        0x0E => (0x08, 0x08, 0x08), // Backspace
        0x0F => (0x09, b'\t', b'\t'),
        0x10 => (0x51, b'q', b'Q'),
        0x11 => (0x57, b'w', b'W'),
        0x12 => (0x45, b'e', b'E'),
        0x13 => (0x52, b'r', b'R'),
        0x14 => (0x54, b't', b'T'),
        0x15 => (0x59, b'y', b'Y'),
        0x16 => (0x55, b'u', b'U'),
        0x17 => (0x49, b'i', b'I'),
        0x18 => (0x4F, b'o', b'O'),
        0x19 => (0x50, b'p', b'P'),
        0x1A => (0xDB, b'[', b'{'),
        0x1B => (0xDD, b']', b'}'),
        0x1C => (0x0D, b'\r', b'\r'),
        0x1D => (0x11, 0, 0), // Ctrl
        0x1E => (0x41, b'a', b'A'),
        0x1F => (0x53, b's', b'S'),
        0x20 => (0x44, b'd', b'D'),
        0x21 => (0x46, b'f', b'F'),
        0x22 => (0x47, b'g', b'G'),
        0x23 => (0x48, b'h', b'H'),
        0x24 => (0x4A, b'j', b'J'),
        0x25 => (0x4B, b'k', b'K'),
        0x26 => (0x4C, b'l', b'L'),
        0x27 => (0xBA, b';', b':'),
        0x28 => (0xDE, b'\'', b'"'),
        0x29 => (0xC0, b'`', b'~'),
        0x2A => (0x10, 0, 0), // LShift
        0x2B => (0xDC, b'\\', b'|'),
        0x2C => (0x5A, b'z', b'Z'),
        0x2D => (0x58, b'x', b'X'),
        0x2E => (0x43, b'c', b'C'),
        0x2F => (0x56, b'v', b'V'),
        0x30 => (0x42, b'b', b'B'),
        0x31 => (0x4E, b'n', b'N'),
        0x32 => (0x4D, b'm', b'M'),
        0x33 => (0xBC, b',', b'<'),
        0x34 => (0xBE, b'.', b'>'),
        0x35 => (0xBF, b'/', b'?'),
        0x36 => (0x10, 0, 0), // RShift
        0x37 => (0x6A, b'*', b'*'),
        0x38 => (0x12, 0, 0), // Alt
        0x39 => (0x20, b' ', b' '),
        0x3A => (0x14, 0, 0), // CapsLock
        0x3B => (0x70, 0, 0),
        0x3C => (0x71, 0, 0),
        0x3D => (0x72, 0, 0),
        0x3E => (0x73, 0, 0),
        0x3F => (0x74, 0, 0),
        0x40 => (0x75, 0, 0),
        0x41 => (0x76, 0, 0),
        0x42 => (0x77, 0, 0),
        0x43 => (0x78, 0, 0),
        0x44 => (0x79, 0, 0),
        0x45 => (0x90, 0, 0), // NumLock
        0x46 => (0x91, 0, 0), // ScrollLock
        0x47 => (0x67, b'7', b'7'),
        0x48 => (0x68, b'8', b'8'),
        0x49 => (0x69, b'9', b'9'),
        0x4A => (0x6D, b'-', b'-'),
        0x4B => (0x64, b'4', b'4'),
        0x4C => (0x65, b'5', b'5'),
        0x4D => (0x66, b'6', b'6'),
        0x4E => (0x6B, b'+', b'+'),
        0x4F => (0x61, b'1', b'1'),
        0x50 => (0x62, b'2', b'2'),
        0x51 => (0x63, b'3', b'3'),
        0x52 => (0x60, b'0', b'0'),
        0x53 => (0x6E, b'.', b'.'),
        _ => (0, 0, 0),
    }
}

// Extended (0xE0) scancodes -> VK.
fn kbd_decode_extended(sc: u8) -> u8 {
    match sc {
        0x1C => 0x0D, // Numpad Enter
        0x1D => 0x11, // RCtrl
        0x35 => 0x6F, // Numpad /
        0x38 => 0x12, // RAlt
        0x47 => 0x24, // Home
        0x48 => 0x26, // Up
        0x49 => 0x21, // PgUp
        0x4B => 0x25, // Left
        0x4D => 0x27, // Right
        0x4F => 0x23, // End
        0x50 => 0x28, // Down
        0x51 => 0x22, // PgDn
        0x52 => 0x2D, // Insert
        0x53 => 0x2E, // Delete
        _ => 0,
    }
}

unsafe fn i8042_wait_write() -> bool {
    let mut spins = 100_000u32;
    while spins > 0 {
        if port_inb(I8042_STATUS) & I8042_ST_IBF == 0 {
            return true;
        }
        spins -= 1;
    }
    false
}

unsafe fn i8042_flush() {
    let mut n = 32;
    while n > 0 {
        if port_inb(I8042_STATUS) & I8042_ST_OBF == 0 {
            break;
        }
        port_inb(I8042_DATA);
        n -= 1;
    }
}

/// Handle one keyboard byte (IRQ1 service routine body).
pub unsafe fn i8042_keyboard_isr() {
    if port_inb(I8042_STATUS) & I8042_ST_OBF == 0 {
        return;
    }
    let sc = port_inb(I8042_DATA);
    if sc == 0xE0 {
        KBD_EXTENDED = true;
        return;
    }
    let released = sc & 0x80 != 0;
    let code = sc & 0x7F;
    if KBD_EXTENDED {
        KBD_EXTENDED = false;
        let vk = kbd_decode_extended(code);
        if vk == 0 {
            return;
        }
        if vk == 0x11 {
            KBD_CTRL = !released;
        }
        if vk == 0x12 {
            KBD_ALT = !released;
        }
        super::kbdclass::kbdclass_inject(vk, 0, !released);
        crate::win32k::user::user_inject_key(vk, !released, 0);
        return;
    }
    let (vk, normal, shifted) = kbd_decode(code);
    if vk == 0 {
        return;
    }
    match vk {
        0x10 => KBD_SHIFT = !released,
        0x11 => KBD_CTRL = !released,
        0x12 => KBD_ALT = !released,
        0x14 => {
            if !released {
                KBD_CAPS = !KBD_CAPS;
            }
        }
        _ => {}
    }
    // CapsLock flips letters.
    let is_letter = normal >= b'a' && normal <= b'z';
    let use_shift = if is_letter {
        KBD_SHIFT ^ KBD_CAPS
    } else {
        KBD_SHIFT
    };
    let ch = if use_shift { shifted } else { normal };
    super::kbdclass::kbdclass_inject(vk, ch, !released);
    crate::win32k::user::user_inject_key(vk, !released, 0);
    // Ctrl+Alt+Del: reboot via keyboard controller pulse.
    if !released && vk == 0x2E && KBD_CTRL && KBD_ALT {
        port_outb(I8042_CMD, 0xFE);
    }
}

// Mouse packet assembly (3-byte PS/2).
static mut MOUSE_PHASE: u8 = 0;
static mut MOUSE_PACKET: [u8; 3] = [0; 3];
static mut MOUSE_X: i32 = 0;
static mut MOUSE_Y: i32 = 0;
static mut MOUSE_BUTTONS: u32 = 0;

/// Handle one mouse byte (IRQ12 service routine body).
pub unsafe fn i8042_mouse_isr() {
    if port_inb(I8042_STATUS) & I8042_ST_OBF == 0 {
        return;
    }
    let b = port_inb(I8042_DATA);
    match MOUSE_PHASE {
        0 => {
            // Bit 3 must be set for a valid first byte.
            if b & 0x08 == 0 {
                return;
            }
            MOUSE_PACKET[0] = b;
            MOUSE_PHASE = 1;
        }
        1 => {
            MOUSE_PACKET[1] = b;
            MOUSE_PHASE = 2;
        }
        _ => {
            MOUSE_PACKET[2] = b;
            MOUSE_PHASE = 0;
            let b0 = MOUSE_PACKET[0];
            let dx = MOUSE_PACKET[1] as i32 - if b0 & 0x10 != 0 { 256 } else { 0 };
            let dy = MOUSE_PACKET[2] as i32 - if b0 & 0x20 != 0 { 256 } else { 0 };
            MOUSE_X += dx;
            MOUSE_Y -= dy; // screen Y grows downward
            if MOUSE_X < 0 {
                MOUSE_X = 0;
            }
            if MOUSE_Y < 0 {
                MOUSE_Y = 0;
            }
            MOUSE_BUTTONS = (b0 & 0x07) as u32;
            super::mouclass::mouclass_inject(MOUSE_X, MOUSE_Y, MOUSE_BUTTONS, dx, dy);
            crate::win32k::user::user_inject_mouse(MOUSE_X, MOUSE_Y, MOUSE_BUTTONS, 0);
        }
    }
}

unsafe fn i8042_mouse_command(cmd: u8, resp_len: usize, resp: *mut u8) -> bool {
    if !i8042_wait_write() {
        return false;
    }
    port_outb(I8042_CMD, I8042_CMD_WRITE_PORT2);
    if !i8042_wait_write() {
        return false;
    }
    port_outb(I8042_DATA, cmd);
    // Expect ACK (0xFA).
    let mut spins = 200_000u32;
    while spins > 0 {
        if port_inb(I8042_STATUS) & I8042_ST_OBF != 0 {
            let r = port_inb(I8042_DATA);
            if r == 0xFA {
                break;
            }
            if r == 0xFE {
                return false;
            }
        }
        spins -= 1;
    }
    if spins == 0 {
        return false;
    }
    let mut i = 0;
    while i < resp_len {
        spins = 200_000;
        while spins > 0 {
            if port_inb(I8042_STATUS) & I8042_ST_OBF != 0 {
                *resp.add(i) = port_inb(I8042_DATA);
                break;
            }
            spins -= 1;
        }
        if spins == 0 {
            return false;
        }
        i += 1;
    }
    true
}

// ============================================================
// DriverEntry
// ============================================================

pub unsafe extern "C" fn i8042_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Disable ports, flush, self-test.
    if !i8042_wait_write() {
        return STATUS_NO_SUCH_DEVICE;
    }
    port_outb(I8042_CMD, I8042_CMD_DISABLE_PORT1);
    // Controller self-test 0xAA -> 0x55.
    i8042_flush();
    if !i8042_wait_write() {
        return STATUS_NO_SUCH_DEVICE;
    }
    port_outb(I8042_CMD, I8042_CMD_SELF_TEST);
    let mut spins = 500_000u32;
    let mut selftest = 0u8;
    while spins > 0 {
        if port_inb(I8042_STATUS) & I8042_ST_OBF != 0 {
            selftest = port_inb(I8042_DATA);
            break;
        }
        spins -= 1;
    }
    if selftest != 0x55 {
        return STATUS_NO_SUCH_DEVICE;
    }
    // Enable port 1 (keyboard).
    port_outb(I8042_CMD, I8042_CMD_ENABLE_PORT1);
    // Config: enable IRQ1 (+IRQ2 if mouse present).
    port_outb(I8042_CMD, I8042_CMD_READ_CFG);
    spins = 100_000;
    let mut cfg = 0u8;
    while spins > 0 {
        if port_inb(I8042_STATUS) & I8042_ST_OBF != 0 {
            cfg = port_inb(I8042_DATA);
            break;
        }
        spins -= 1;
    }
    cfg |= I8042_CFG_IRQ1;
    // Probe mouse: enable port 2, send Get ID.
    port_outb(I8042_CMD, I8042_CMD_ENABLE_PORT2);
    let mut id = [0u8; 1];
    if i8042_mouse_command(0xF2, 1, id.as_mut_ptr()) {
        I8042_MOUSE_PRESENT = true;
        cfg |= I8042_CFG_IRQ2;
        // Enable data reporting.
        i8042_mouse_command(0xF4, 0, core::ptr::null_mut());
    }
    if i8042_wait_write() {
        port_outb(I8042_CMD, I8042_CMD_WRITE_CFG);
        if i8042_wait_write() {
            port_outb(I8042_DATA, cfg);
        }
    }
    I8042_PRESENT = true;
    let mut dev: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\KeyboardPort0\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x0000000B, // FILE_DEVICE_KEYBOARD
        0,
        0,
        &mut dev,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    crate::kernel_log!(
        "[i8042] Keyboard OK, mouse={}\n",
        I8042_MOUSE_PRESENT as u8
    );
    STATUS_SUCCESS
}

pub unsafe fn i8042_is_present() -> bool {
    I8042_PRESENT
}

// Local status codes.
pub const STATUS_NO_SUCH_DEVICE: NtStatus = 0xC000000E;
