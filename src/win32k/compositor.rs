/// Win32k DWM - Desktop Window Manager (compositor)
///
/// Per-window surfaces (32bpp), z-ordered composition to the
/// framebuffer with dirty-rect tracking, cursor overlay, and
/// present statistics. Kernel-side state; policy lives in the
/// user-mode DWM process.

use core::ffi::c_void;

use crate::types::*;
use super::win32k_framebuffer;

// ============================================================
// Surfaces
// ============================================================

pub const DWM_MAX_SURFACES: usize = 512;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct DwmSurface {
    pub surface_id: u64,
    pub width: u32,
    pub height: u32,
    pub stride: u32,
    pub bits: *mut u32,
    pub z_order: u32,
    pub pos_x: i32,
    pub pos_y: i32,
    pub visible: bool,
    pub dirty: bool,
    pub alpha: u8,
    pub owned: bool,
}

static mut DWM_SURFACES: [DwmSurface; DWM_MAX_SURFACES] = [DwmSurface {
    surface_id: 0,
    width: 0,
    height: 0,
    stride: 0,
    bits: core::ptr::null_mut(),
    z_order: 0,
    pos_x: 0,
    pos_y: 0,
    visible: false,
    dirty: false,
    alpha: 255,
    owned: false,
}; DWM_MAX_SURFACES];
static DWM_NEXT_SURFACE_ID: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(1);
static mut DWM_Z_TOP: u32 = 0;
static mut DWM_PRESENT_COUNT: u64 = 0;
static mut DWM_FRAME_MS: u64 = 0;

unsafe fn dwmp_find(id: u64) -> *mut DwmSurface {
    if id == 0 {
        return core::ptr::null_mut();
    }
    let mut i = 0;
    while i < DWM_MAX_SURFACES {
        if DWM_SURFACES[i].surface_id == id {
            return &mut DWM_SURFACES[i] as *mut DwmSurface;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

/// DwmCreateSurface - allocate a window backing store.
pub unsafe fn dwm_create_surface(width: u32, height: u32) -> u64 {
    let w = width.max(1).min(4096);
    let h = height.max(1).min(4096);
    let mut i = 0;
    while i < DWM_MAX_SURFACES {
        if DWM_SURFACES[i].surface_id == 0 {
            let bits = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
                w as usize * h as usize * 4,
            ) as *mut u32;
            if bits.is_null() {
                return 0;
            }
            core::ptr::write_bytes(bits as *mut u8, 0, w as usize * h as usize * 4);
            let id =
                DWM_NEXT_SURFACE_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
            DWM_SURFACES[i].surface_id = id;
            DWM_SURFACES[i].width = w;
            DWM_SURFACES[i].height = h;
            DWM_SURFACES[i].stride = w * 4;
            DWM_SURFACES[i].bits = bits;
            DWM_SURFACES[i].owned = true;
            DWM_Z_TOP += 1;
            DWM_SURFACES[i].z_order = DWM_Z_TOP;
            DWM_SURFACES[i].visible = true;
            DWM_SURFACES[i].dirty = true;
            return id;
        }
        i += 1;
    }
    0
}

pub unsafe fn dwm_destroy_surface(id: u64) {
    let s = dwmp_find(id);
    if s.is_null() {
        return;
    }
    if (*s).owned && !(*s).bits.is_null() {
        crate::mm::pool::ex_free_pool((*s).bits as *mut c_void);
    }
    core::ptr::write_bytes(s as *mut u8, 0, core::mem::size_of::<DwmSurface>());
}

pub unsafe fn dwm_resize_surface(id: u64, width: u32, height: u32) -> NtStatus {
    let s = dwmp_find(id);
    if s.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let w = width.max(1).min(4096);
    let h = height.max(1).min(4096);
    if w == (*s).width && h == (*s).height {
        return STATUS_SUCCESS;
    }
    let bits = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        w as usize * h as usize * 4,
    ) as *mut u32;
    if bits.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(bits as *mut u8, 0, w as usize * h as usize * 4);
    // Copy overlap.
    let cw = w.min((*s).width) as usize;
    let ch = h.min((*s).height) as usize;
    let mut y = 0;
    while y < ch {
        core::ptr::copy_nonoverlapping(
            (*s).bits.add(y * ((*s).stride as usize / 4)),
            bits.add(y * w as usize),
            cw,
        );
        y += 1;
    }
    if (*s).owned && !(*s).bits.is_null() {
        crate::mm::pool::ex_free_pool((*s).bits as *mut c_void);
    }
    (*s).width = w;
    (*s).height = h;
    (*s).stride = w * 4;
    (*s).bits = bits;
    (*s).dirty = true;
    STATUS_SUCCESS
}

pub unsafe fn dwm_move_surface(id: u64, x: i32, y: i32, z: u32) {
    let s = dwmp_find(id);
    if s.is_null() {
        return;
    }
    (*s).pos_x = x;
    (*s).pos_y = y;
    if z != u32::MAX {
        (*s).z_order = z;
    }
    (*s).dirty = true;
}

pub unsafe fn dwm_show_surface(id: u64, visible: bool) {
    let s = dwmp_find(id);
    if !s.is_null() {
        (*s).visible = visible;
        (*s).dirty = true;
    }
}

pub unsafe fn dwm_set_alpha(id: u64, alpha: u8) {
    let s = dwmp_find(id);
    if !s.is_null() {
        (*s).alpha = alpha;
        (*s).dirty = true;
    }
}

pub unsafe fn dwm_surface_bits(id: u64) -> *mut u32 {
    let s = dwmp_find(id);
    if s.is_null() {
        return core::ptr::null_mut();
    }
    (*s).dirty = true;
    (*s).bits
}

// ============================================================
// Composition
// ============================================================

fn dwm_blend(src: u32, dst: u32, alpha: u8) -> u32 {
    if alpha == 255 {
        return src;
    }
    if alpha == 0 {
        return dst;
    }
    let a = alpha as u32;
    let inv = 255 - a;
    let sr = (src >> 16) & 0xFF;
    let sg = (src >> 8) & 0xFF;
    let sb = src & 0xFF;
    let dr = (dst >> 16) & 0xFF;
    let dg = (dst >> 8) & 0xFF;
    let db = dst & 0xFF;
    let r = (sr * a + dr * inv) / 255;
    let g = (sg * a + dg * inv) / 255;
    let b = (sb * a + db * inv) / 255;
    (r << 16) | (g << 8) | b
}

/// DwmPresent - compose all visible surfaces to the framebuffer.
///
/// Sorts by z_order (insertion sort, few surfaces) and blits with
/// per-surface alpha. Paints the cursor last.
pub unsafe fn dwm_present() -> NtStatus {
    let fb = win32k_framebuffer();
    if !fb.attached || fb.bpp != 32 || fb.base == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    // Collect visible surfaces.
    let mut order: [u32; DWM_MAX_SURFACES] = [0; DWM_MAX_SURFACES];
    let mut n = 0usize;
    let mut i = 0;
    while i < DWM_MAX_SURFACES {
        if DWM_SURFACES[i].surface_id != 0 && DWM_SURFACES[i].visible {
            order[n] = i as u32;
            n += 1;
        }
        i += 1;
    }
    // Insertion sort by z_order (ascending = back to front).
    let mut k = 1;
    while k < n {
        let key = order[k];
        let key_z = DWM_SURFACES[key as usize].z_order;
        let mut j = k;
        while j > 0 && DWM_SURFACES[order[j - 1] as usize].z_order > key_z {
            order[j] = order[j - 1];
            j -= 1;
        }
        order[j] = key;
        k += 1;
    }
    // Clear to desktop color.
    let dst_base = fb.base as *mut u8;
    let mut yy = 0u32;
    while yy < fb.height {
        let row = dst_base.add(yy as usize * fb.pitch as usize) as *mut u32;
        let mut xx = 0u32;
        while xx < fb.width {
            *row.add(xx as usize) = DWM_DESKTOP_COLOR;
            xx += 1;
        }
        yy += 1;
    }
    // Blit surfaces back-to-front with clipping + alpha.
    let mut s = 0;
    while s < n {
        let surf = &DWM_SURFACES[order[s] as usize];
        if !surf.bits.is_null() {
            let mut sy = 0i32;
            while sy < surf.height as i32 {
                let dy = surf.pos_y + sy;
                if dy >= 0 && dy < fb.height as i32 {
                    let src_row =
                        surf.bits.add(sy as usize * (surf.stride as usize / 4));
                    let dst_row = dst_base
                        .add(dy as usize * fb.pitch as usize)
                        as *mut u32;
                    let mut sx = 0i32;
                    while sx < surf.width as i32 {
                        let dx = surf.pos_x + sx;
                        if dx >= 0 && dx < fb.width as i32 {
                            let src = *src_row.add(sx as usize);
                            let dst = *dst_row.add(dx as usize);
                            *dst_row.add(dx as usize) = dwm_blend(src, dst, surf.alpha);
                        }
                        sx += 1;
                    }
                }
                sy += 1;
            }
        }
        s += 1;
    }
    // Cursor overlay.
    dwm_paint_cursor(dst_base, fb.width, fb.height, fb.pitch);
    DWM_PRESENT_COUNT += 1;
    STATUS_SUCCESS
}

pub const DWM_DESKTOP_COLOR: u32 = 0x0078A9D1;

static mut DWM_CURSOR_X: i32 = 0;
static mut DWM_CURSOR_Y: i32 = 0;
static mut DWM_CURSOR_VISIBLE: bool = true;

pub unsafe fn dwm_set_cursor(x: i32, y: i32, visible: bool) {
    DWM_CURSOR_X = x;
    DWM_CURSOR_Y = y;
    DWM_CURSOR_VISIBLE = visible;
}

unsafe fn dwm_paint_cursor(base: *mut u8, width: u32, height: u32, pitch: u32) {
    if !DWM_CURSOR_VISIBLE {
        return;
    }
    // 12x19 arrow cursor, 1bpp mask (white with black outline).
    const CURSOR: [u16; 19] = [
        0b100000000000,
        0b110000000000,
        0b111000000000,
        0b111100000000,
        0b111110000000,
        0b111111000000,
        0b111111100000,
        0b111111110000,
        0b111111111000,
        0b111111000000,
        0b111011000000,
        0b110011000000,
        0b100001100000,
        0b000001100000,
        0b000000110000,
        0b000000110000,
        0b000000000000,
        0b000000000000,
        0b000000000000,
    ];
    let mut row = 0usize;
    while row < 16 {
        let mut col = 0usize;
        while col < 12 {
            if (CURSOR[row] >> (11 - col)) & 1 != 0 {
                let dx = DWM_CURSOR_X + col as i32;
                let dy = DWM_CURSOR_Y + row as i32;
                if dx >= 0 && dy >= 0 && dx < width as i32 && dy < height as i32 {
                    let px = base.add(dy as usize * pitch as usize + dx as usize * 4)
                        as *mut u32;
                    // Outline on edges, white inside.
                    let edge = col == 0
                        || row == 0
                        || (CURSOR[row] >> (11 - (col + 1))) & 1 == 0
                        || row + 1 >= 16
                        || (CURSOR[row + 1] >> (11 - col)) & 1 == 0;
                    *px = if edge { 0x000000 } else { 0xFF_FFFF };
                }
            }
            col += 1;
        }
        row += 1;
    }
}

pub unsafe fn dwm_present_count() -> u64 {
    DWM_PRESENT_COUNT
}

/// DwmTick - called each win32k VSync tick (timer/DPC context).
pub unsafe fn dwm_tick(now_ms: u64) {
    DWM_FRAME_MS = now_ms;
    // Present only when something is dirty.
    let mut dirty = false;
    let mut i = 0;
    while i < DWM_MAX_SURFACES {
        if DWM_SURFACES[i].surface_id != 0 && DWM_SURFACES[i].dirty {
            dirty = true;
            DWM_SURFACES[i].dirty = false;
        }
        i += 1;
    }
    if dirty {
        dwm_present();
    }
    // Fire USER timers on the same tick.
    super::user::user_fire_timers(now_ms);
}

pub unsafe fn dwm_init() -> NtStatus {
    DWM_PRESENT_COUNT = 0;
    STATUS_SUCCESS
}
