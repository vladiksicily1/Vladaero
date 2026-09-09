/// Boot video (bootvid.dll): GOP framebuffer handoff + splash
///
/// Takes the UEFI GOP / VESA framebuffer from the bootloader,
/// attaches it to win32k, clears to the boot background, and
/// paints the boot logo + progress ring from anfw state.

use core::ffi::c_void;

use crate::types::*;

static mut BOOTVID_BASE: u64 = 0;
static mut BOOTVID_WIDTH: u32 = 0;
static mut BOOTVID_HEIGHT: u32 = 0;
static mut BOOTVID_PITCH: u32 = 0;
static mut BOOTVID_BPP: u32 = 32;

/// BootvidSetDisplay - bootloader handoff.
pub unsafe fn bootvid_set_display(base: u64, width: u32, height: u32, pitch: u32, bpp: u32) {
    BOOTVID_BASE = base;
    BOOTVID_WIDTH = width;
    BOOTVID_HEIGHT = height;
    BOOTVID_PITCH = pitch;
    BOOTVID_BPP = bpp;
    crate::win32k::win32k_attach_framebuffer(base, width, height, pitch, bpp);
}

/// Fill the whole screen.
unsafe fn bootvid_fill(color: u32) {
    if BOOTVID_BASE == 0 || BOOTVID_BPP != 32 {
        return;
    }
    let mut y = 0u32;
    while y < BOOTVID_HEIGHT {
        let row = (BOOTVID_BASE as *mut u8).add(y as usize * BOOTVID_PITCH as usize)
            as *mut u32;
        let mut x = 0u32;
        while x < BOOTVID_WIDTH {
            *row.add(x as usize) = color;
            x += 1;
        }
        y += 1;
    }
}

unsafe fn bootvid_pixel(x: i32, y: i32, color: u32) {
    if x < 0 || y < 0 || x >= BOOTVID_WIDTH as i32 || y >= BOOTVID_HEIGHT as i32 {
        return;
    }
    if BOOTVID_BASE == 0 || BOOTVID_BPP != 32 {
        return;
    }
    let px = (BOOTVID_BASE as *mut u8)
        .add(y as usize * BOOTVID_PITCH as usize + x as usize * 4) as *mut u32;
    *px = color;
}

/// BootvidPaintSplash - logo square + spinner dots (anfw state).
pub unsafe fn bootvid_paint_splash() {
    bootvid_fill(0x001F6FB5); // VladOS boot blue
    let cx = BOOTVID_WIDTH as i32 / 2;
    let cy = BOOTVID_HEIGHT as i32 / 2 - 40;
    // Logo: white rounded-ish square 96x96.
    let mut y = 0;
    while y < 96 {
        let mut x = 0;
        while x < 96 {
            bootvid_pixel(cx - 48 + x, cy - 48 + y, 0x00FF_FFFF);
            x += 1;
        }
        y += 1;
    }
    // Spinner dots below.
    let mut spinner = 0u32;
    let mut progress = 0u32;
    let mut phase = 0u32;
    let mut frames = 0u32;
    crate::anfw::anfw_query_state(&mut spinner, &mut progress, &mut phase, &mut frames);
    let dot_y = cy + 80;
    let mut d = 0u32;
    while d < 6 {
        let dx = (d as i32 - 2) * 22;
        // Radius pulse on the active dot.
        let active = d == spinner % 6;
        let r = if active { 7 } else { 5 };
        let mut oy = -r;
        while oy <= r {
            let mut ox = -r;
            while ox <= r {
                if ox * ox + oy * oy <= r * r {
                    bootvid_pixel(
                        cx + dx + ox,
                        dot_y + oy,
                        if active { 0x00FF_FFFF } else { 0x0090C8E8 },
                    );
                }
                ox += 1;
            }
            oy += 1;
        }
        d += 1;
    }
    let _ = (progress, phase, frames);
}

pub unsafe extern "C" fn bootvid_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Default 1024x768 linear framebuffer if the bootloader did not
    // hand one off (VGA hardware would be programmed here).
    if BOOTVID_BASE == 0 {
        return STATUS_SUCCESS;
    }
    bootvid_paint_splash();
    STATUS_SUCCESS
}
