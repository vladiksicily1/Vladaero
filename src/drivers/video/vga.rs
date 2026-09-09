/// VGA driver (vga.sys): text mode, cursor, palette, mode set
///
/// 80x25 text writer used by the BSOD path and early boot log,
/// hardware cursor control, DAC palette, and VESA mode query.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::{port_inb, port_outb};

pub const VGA_TEXT_BUFFER: usize = 0xB8000;
pub const VGA_COLS: usize = 80;
pub const VGA_ROWS: usize = 25;

pub const VGA_MISC_READ: u16 = 0x3CC;
pub const VGA_MISC_WRITE: u16 = 0x3C2;
pub const VGA_SEQ_INDEX: u16 = 0x3C4;
pub const VGA_SEQ_DATA: u16 = 0x3C5;
pub const VGA_CRTC_INDEX: u16 = 0x3D4;
pub const VGA_CRTC_DATA: u16 = 0x3D5;
pub const VGA_GFX_INDEX: u16 = 0x3CE;
pub const VGA_GFX_DATA: u16 = 0x3CF;
pub const VGA_DAC_MASK: u16 = 0x3C6;
pub const VGA_DAC_READ_INDEX: u16 = 0x3C7;
pub const VGA_DAC_WRITE_INDEX: u16 = 0x3C8;
pub const VGA_DAC_DATA: u16 = 0x3C9;
pub const VGA_INPUT_STATUS: u16 = 0x3DA;

static mut VGA_CURSOR_ROW: usize = 0;
static mut VGA_CURSOR_COL: usize = 0;
static mut VGA_ATTR: u8 = 0x07;

unsafe fn vga_cell(row: usize, col: usize) -> *mut u16 {
    (VGA_TEXT_BUFFER as *mut u16).add(row * VGA_COLS + col)
}

/// VgaPutChar - text-mode putchar with scrolling.
pub unsafe fn vga_putchar(ch: u8) {
    if ch == b'\n' {
        VGA_CURSOR_COL = 0;
        VGA_CURSOR_ROW += 1;
    } else if ch == b'\r' {
        VGA_CURSOR_COL = 0;
    } else if ch == 0x08 {
        if VGA_CURSOR_COL > 0 {
            VGA_CURSOR_COL -= 1;
        }
    } else {
        if VGA_CURSOR_ROW < VGA_ROWS && VGA_CURSOR_COL < VGA_COLS {
            *vga_cell(VGA_CURSOR_ROW, VGA_CURSOR_COL) =
                ((VGA_ATTR as u16) << 8) | (ch as u16);
            VGA_CURSOR_COL += 1;
            if VGA_CURSOR_COL >= VGA_COLS {
                VGA_CURSOR_COL = 0;
                VGA_CURSOR_ROW += 1;
            }
        }
    }
    if VGA_CURSOR_ROW >= VGA_ROWS {
        // Scroll up one line.
        let mut r = 0;
        while r + 1 < VGA_ROWS {
            let mut c = 0;
            while c < VGA_COLS {
                *vga_cell(r, c) = *vga_cell(r + 1, c);
                c += 1;
            }
            r += 1;
        }
        let mut c = 0;
        while c < VGA_COLS {
            *vga_cell(VGA_ROWS - 1, c) = (VGA_ATTR as u16) << 8 | 0x20;
            c += 1;
        }
        VGA_CURSOR_ROW = VGA_ROWS - 1;
    }
    vga_move_cursor(VGA_CURSOR_ROW, VGA_CURSOR_COL);
}

/// VgaWrite - text-mode string output.
pub unsafe fn vga_write(bytes: &[u8]) {
    for &b in bytes {
        vga_putchar(b);
    }
}

pub unsafe fn vga_set_attr(attr: u8) {
    VGA_ATTR = attr;
}

/// Move the hardware text cursor.
pub unsafe fn vga_move_cursor(row: usize, col: usize) {
    let pos = (row * VGA_COLS + col) as u16;
    port_outb(VGA_CRTC_INDEX, 0x0F);
    port_outb(VGA_CRTC_DATA, (pos & 0xFF) as u8);
    port_outb(VGA_CRTC_INDEX, 0x0E);
    port_outb(VGA_CRTC_DATA, ((pos >> 8) & 0xFF) as u8);
}

/// Set a DAC palette entry (6-bit per channel).
pub unsafe fn vga_set_palette(index: u8, r: u8, g: u8, b: u8) {
    port_outb(VGA_DAC_WRITE_INDEX, index);
    port_outb(VGA_DAC_DATA, r & 0x3F);
    port_outb(VGA_DAC_DATA, g & 0x3F);
    port_outb(VGA_DAC_DATA, b & 0x3F);
}

/// Read the misc output register (mono/color detect).
pub unsafe fn vga_color_mode() -> bool {
    port_inb(VGA_MISC_READ) & 0x01 != 0
}

pub unsafe extern "C" fn vga_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut dev: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\Video0\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x00000023, // FILE_DEVICE_VIDEO
        0,
        0,
        &mut dev,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    vga_move_cursor(0, 0);
    STATUS_SUCCESS
}
