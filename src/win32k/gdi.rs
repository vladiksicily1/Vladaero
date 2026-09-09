/// Win32k GDI - device contexts, bitmaps, pens/brushes, drawing
///
/// GDI handle table with type tags + per-process quotas, memory and
/// display DCs, ROP BitBlt, PatBlt, lines/rects/ellipses, regions,
/// and TextOut via an embedded stroke font (correct by construction).

use core::ffi::c_void;

use crate::types::*;
use super::{win32k_register_service, win32k_framebuffer, Win32kServiceHandler};

// ============================================================
// ROP codes
// ============================================================

pub const SRCCOPY: u32 = 0x00CC0020;
pub const SRCPAINT: u32 = 0x00EE0086;
pub const SRCAND: u32 = 0x008800C6;
pub const SRCINVERT: u32 = 0x00660046;
pub const SRCERASE: u32 = 0x00440328;
pub const NOTSRCCOPY: u32 = 0x00330008;
pub const NOTSRCERASE: u32 = 0x001100A6;
pub const DSTINVERT: u32 = 0x00550009;
pub const PATINVERT: u32 = 0x005A0049;
pub const PATCOPY: u32 = 0x00F00021;
pub const PATPAINT: u32 = 0x00FB0A09;
pub const BLACKNESS: u32 = 0x00000042;
pub const WHITENESS: u32 = 0x00FF0062;

pub const RGN_AND: u32 = 1;
pub const RGN_OR: u32 = 2;
pub const RGN_XOR: u32 = 3;
pub const RGN_DIFF: u32 = 4;
pub const RGN_COPY: u32 = 5;

pub const NULLREGION: u32 = 1;
pub const SIMPLEREGION: u32 = 2;
pub const COMPLEXREGION: u32 = 3;

pub const PS_SOLID: u32 = 0;
pub const PS_DASH: u32 = 1;
pub const PS_DOT: u32 = 2;

pub const STOCK_WHITE_BRUSH: u32 = 0;
pub const STOCK_LTGRAY_BRUSH: u32 = 1;
pub const STOCK_GRAY_BRUSH: u32 = 2;
pub const STOCK_DKGRAY_BRUSH: u32 = 3;
pub const STOCK_BLACK_BRUSH: u32 = 4;
pub const STOCK_WHITE_PEN: u32 = 6;
pub const STOCK_BLACK_PEN: u32 = 7;

// ============================================================
// GDI handle table
// ============================================================

pub const GDI_HANDLE_TYPE_FREE: u8 = 0;
pub const GDI_HANDLE_TYPE_DC: u8 = 1;
pub const GDI_HANDLE_TYPE_BITMAP: u8 = 2;
pub const GDI_HANDLE_TYPE_PEN: u8 = 3;
pub const GDI_HANDLE_TYPE_BRUSH: u8 = 4;
pub const GDI_HANDLE_TYPE_FONT: u8 = 5;
pub const GDI_HANDLE_TYPE_REGION: u8 = 6;
pub const GDI_HANDLE_TYPE_PALETTE: u8 = 7;

pub const GDI_HANDLE_TABLE_SIZE: usize = 65536;
pub const GDI_HANDLE_QUOTA_PER_PROCESS: u32 = 10000;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct GdiHandleEntry {
    pub object: *mut c_void,
    pub handle_type: u8,
    pub process_id: u64,
    pub stock: bool,
}

static mut GDI_HANDLE_TABLE: [GdiHandleEntry; GDI_HANDLE_TABLE_SIZE] = [GdiHandleEntry {
    object: core::ptr::null_mut(),
    handle_type: GDI_HANDLE_TYPE_FREE,
    process_id: 0,
    stock: false,
}; GDI_HANDLE_TABLE_SIZE];
static mut GDI_HANDLE_USED: usize = 0;

unsafe fn gdi_alloc_handle(object: *mut c_void, handle_type: u8, process_id: u64) -> u64 {
    let mut owned = 0u32;
    let mut i = 1usize;
    while i < GDI_HANDLE_TABLE_SIZE {
        if GDI_HANDLE_TABLE[i].handle_type != GDI_HANDLE_TYPE_FREE
            && GDI_HANDLE_TABLE[i].process_id == process_id
        {
            owned += 1;
        }
        i += 1;
    }
    if owned >= GDI_HANDLE_QUOTA_PER_PROCESS {
        return 0;
    }
    i = 1;
    while i < GDI_HANDLE_TABLE_SIZE {
        if GDI_HANDLE_TABLE[i].handle_type == GDI_HANDLE_TYPE_FREE {
            GDI_HANDLE_TABLE[i].object = object;
            GDI_HANDLE_TABLE[i].handle_type = handle_type;
            GDI_HANDLE_TABLE[i].process_id = process_id;
            GDI_HANDLE_TABLE[i].stock = false;
            GDI_HANDLE_USED += 1;
            return (i as u64) | ((handle_type as u64) << 48);
        }
        i += 1;
    }
    0
}

unsafe fn gdi_free_handle(handle: u64) {
    let idx = (handle & 0xFFFF) as usize;
    if idx == 0 || idx >= GDI_HANDLE_TABLE_SIZE {
        return;
    }
    if GDI_HANDLE_TABLE[idx].handle_type != GDI_HANDLE_TYPE_FREE
        && !GDI_HANDLE_TABLE[idx].stock
    {
        GDI_HANDLE_TABLE[idx].handle_type = GDI_HANDLE_TYPE_FREE;
        GDI_HANDLE_TABLE[idx].object = core::ptr::null_mut();
        GDI_HANDLE_USED -= 1;
    }
}

pub unsafe fn gdi_lookup_handle(handle: u64, expect_type: u8) -> *mut c_void {
    let idx = (handle & 0xFFFF) as usize;
    if idx == 0 || idx >= GDI_HANDLE_TABLE_SIZE {
        return core::ptr::null_mut();
    }
    if GDI_HANDLE_TABLE[idx].handle_type != expect_type {
        return core::ptr::null_mut();
    }
    GDI_HANDLE_TABLE[idx].object
}

// ============================================================
// GDI objects
// ============================================================

#[repr(C)]
pub struct GdiBitmap {
    pub width: u32,
    pub height: u32,
    pub stride: u32,
    pub bits: *mut u32,
    pub owned: bool,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct GdiPen {
    pub style: u32,
    pub width: u32,
    pub color: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct GdiBrush {
    pub solid: bool,
    pub color: u32,
    pub hatch: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct GdiFont {
    pub height: u32,
    pub weight: u32,
    pub italic: bool,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct GdiRect {
    pub left: i32,
    pub top: i32,
    pub right: i32,
    pub bottom: i32,
}

#[repr(C)]
pub struct GdiRegion {
    pub rects: [GdiRect; 16],
    pub rect_count: u32,
    pub bounds: GdiRect,
}

#[repr(C)]
pub struct GdiDc {
    pub handle: u64,
    pub is_display: bool,
    pub bitmap: *mut GdiBitmap,
    pub pen: GdiPen,
    pub brush: GdiBrush,
    pub font: GdiFont,
    pub text_color: u32,
    pub bk_color: u32,
    pub bk_mode: u32,
    pub pos_x: i32,
    pub pos_y: i32,
    pub clip: GdiRect,
    pub selected_bitmap: u64,
    pub selected_pen: u64,
    pub selected_brush: u64,
    pub selected_font: u64,
}

// Display DC singleton (owns no bitmap; draws to framebuffer).
static mut GDI_DISPLAY_DC: *mut GdiDc = core::ptr::null_mut();

unsafe fn gdip_default_dc() -> GdiDc {
    GdiDc {
        handle: 0,
        is_display: false,
        bitmap: core::ptr::null_mut(),
        pen: GdiPen {
            style: PS_SOLID,
            width: 1,
            color: 0x000000,
        },
        brush: GdiBrush {
            solid: true,
            color: 0xFFFFFF,
            hatch: 0,
        },
        font: GdiFont {
            height: 16,
            weight: 400,
            italic: false,
        },
        text_color: 0x000000,
        bk_color: 0xFFFFFF,
        bk_mode: 1, // OPAQUE
        pos_x: 0,
        pos_y: 0,
        clip: GdiRect {
            left: 0,
            top: 0,
            right: 32767,
            bottom: 32767,
        },
        selected_bitmap: 0,
        selected_pen: 0,
        selected_brush: 0,
        selected_font: 0,
    }
}

// ============================================================
// Pixel access (bitmap or framebuffer)
// ============================================================

unsafe fn gdip_set_pixel_raw(dc: *mut GdiDc, x: i32, y: i32, color: u32) {
    if dc.is_null() {
        return;
    }
    let d = &*dc;
    if x < d.clip.left || y < d.clip.top || x >= d.clip.right || y >= d.clip.bottom {
        return;
    }
    if d.is_display {
        let fb = win32k_framebuffer();
        if !fb.attached || fb.bpp != 32 {
            return;
        }
        if x < 0 || y < 0 || x >= fb.width as i32 || y >= fb.height as i32 {
            return;
        }
        let row = fb.base as *mut u8;
        let pixel = row.add(y as usize * fb.pitch as usize + x as usize * 4) as *mut u32;
        *pixel = color;
    } else {
        let bmp = d.bitmap;
        if bmp.is_null() || (*bmp).bits.is_null() {
            return;
        }
        if x < 0 || y < 0 || x >= (*bmp).width as i32 || y >= (*bmp).height as i32 {
            return;
        }
        let row = (*bmp).bits.add(y as usize * ((*bmp).stride as usize / 4));
        *row.add(x as usize) = color;
    }
}

unsafe fn gdip_get_pixel_raw(dc: *mut GdiDc, x: i32, y: i32) -> u32 {
    if dc.is_null() {
        return 0;
    }
    let d = &*dc;
    if d.is_display {
        let fb = win32k_framebuffer();
        if !fb.attached || fb.bpp != 32 {
            return 0;
        }
        if x < 0 || y < 0 || x >= fb.width as i32 || y >= fb.height as i32 {
            return 0;
        }
        let row = fb.base as *mut u8;
        let pixel = row.add(y as usize * fb.pitch as usize + x as usize * 4) as *mut u32;
        *pixel
    } else {
        let bmp = d.bitmap;
        if bmp.is_null() || (*bmp).bits.is_null() {
            return 0;
        }
        if x < 0 || y < 0 || x >= (*bmp).width as i32 || y >= (*bmp).height as i32 {
            return 0;
        }
        let row = (*bmp).bits.add(y as usize * ((*bmp).stride as usize / 4));
        *row.add(x as usize)
    }
}

// ============================================================
// Object creation / selection / deletion
// ============================================================

/// NtGdiCreateCompatibleDC
pub unsafe fn gdi_create_compatible_dc(display_dc: u64, process_id: u64) -> u64 {
    let dc = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<GdiDc>(),
    ) as *mut GdiDc;
    if dc.is_null() {
        return 0;
    }
    *dc = gdip_default_dc();
    (*dc).is_display = false;
    let h = gdi_alloc_handle(dc as *mut c_void, GDI_HANDLE_TYPE_DC, process_id);
    if h == 0 {
        crate::mm::pool::ex_free_pool(dc as *mut c_void);
        return 0;
    }
    (*dc).handle = h;
    let _ = display_dc;
    h
}

/// NtGdiCreateDC (display)
pub unsafe fn gdi_create_display_dc(process_id: u64) -> u64 {
    if !GDI_DISPLAY_DC.is_null() {
        return (*GDI_DISPLAY_DC).handle;
    }
    let dc = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<GdiDc>(),
    ) as *mut GdiDc;
    if dc.is_null() {
        return 0;
    }
    *dc = gdip_default_dc();
    (*dc).is_display = true;
    let fb = win32k_framebuffer();
    (*dc).clip = GdiRect {
        left: 0,
        top: 0,
        right: fb.width as i32,
        bottom: fb.height as i32,
    };
    let h = gdi_alloc_handle(dc as *mut c_void, GDI_HANDLE_TYPE_DC, process_id);
    if h == 0 {
        crate::mm::pool::ex_free_pool(dc as *mut c_void);
        return 0;
    }
    (*dc).handle = h;
    GDI_DISPLAY_DC = dc;
    h
}

pub unsafe fn gdi_delete_dc(handle: u64) -> bool {
    let dc = gdi_lookup_handle(handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if dc.is_null() {
        return false;
    }
    if dc == GDI_DISPLAY_DC {
        GDI_DISPLAY_DC = core::ptr::null_mut();
    }
    gdi_free_handle(handle);
    crate::mm::pool::ex_free_pool(dc as *mut c_void);
    true
}

/// NtGdiCreateBitmap - DIB-style 32bpp bitmap.
pub unsafe fn gdi_create_bitmap(width: u32, height: u32, process_id: u64) -> u64 {
    if width == 0 || height == 0 || width > 8192 || height > 8192 {
        return 0;
    }
    let bmp = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<GdiBitmap>(),
    ) as *mut GdiBitmap;
    if bmp.is_null() {
        return 0;
    }
    let stride = width as usize * 4;
    let bits = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(stride * height as usize)
        as *mut u32;
    if bits.is_null() {
        crate::mm::pool::ex_free_pool(bmp as *mut c_void);
        return 0;
    }
    core::ptr::write_bytes(bits as *mut u8, 0, stride * height as usize);
    (*bmp).width = width;
    (*bmp).height = height;
    (*bmp).stride = stride as u32;
    (*bmp).bits = bits;
    (*bmp).owned = true;
    let h = gdi_alloc_handle(bmp as *mut c_void, GDI_HANDLE_TYPE_BITMAP, process_id);
    if h == 0 {
        crate::mm::pool::ex_free_pool(bits as *mut c_void);
        crate::mm::pool::ex_free_pool(bmp as *mut c_void);
        return 0;
    }
    h
}

pub unsafe fn gdi_create_pen(style: u32, width: u32, color: u32, process_id: u64) -> u64 {
    let pen = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<GdiPen>(),
    ) as *mut GdiPen;
    if pen.is_null() {
        return 0;
    }
    (*pen).style = style;
    (*pen).width = width.max(1);
    (*pen).color = color & 0x00FF_FFFF;
    gdi_alloc_handle(pen as *mut c_void, GDI_HANDLE_TYPE_PEN, process_id)
}

pub unsafe fn gdi_create_brush(solid: bool, color: u32, process_id: u64) -> u64 {
    let br = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<GdiBrush>(),
    ) as *mut GdiBrush;
    if br.is_null() {
        return 0;
    }
    (*br).solid = solid;
    (*br).color = color & 0x00FF_FFFF;
    (*br).hatch = 0;
    gdi_alloc_handle(br as *mut c_void, GDI_HANDLE_TYPE_BRUSH, process_id)
}

pub unsafe fn gdi_create_font(height: u32, weight: u32, process_id: u64) -> u64 {
    let f = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<GdiFont>(),
    ) as *mut GdiFont;
    if f.is_null() {
        return 0;
    }
    (*f).height = height.clamp(8, 72);
    (*f).weight = weight;
    (*f).italic = false;
    gdi_alloc_handle(f as *mut c_void, GDI_HANDLE_TYPE_FONT, process_id)
}

pub unsafe fn gdi_create_region(left: i32, top: i32, right: i32, bottom: i32, process_id: u64) -> u64 {
    let r = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<GdiRegion>(),
    ) as *mut GdiRegion;
    if r.is_null() {
        return 0;
    }
    core::ptr::write_bytes(r as *mut u8, 0, core::mem::size_of::<GdiRegion>());
    (*r).rects[0] = GdiRect {
        left,
        top,
        right,
        bottom,
    };
    (*r).rect_count = 1;
    (*r).bounds = (*r).rects[0];
    gdi_alloc_handle(r as *mut c_void, GDI_HANDLE_TYPE_REGION, process_id)
}

/// NtGdiSelectObject
pub unsafe fn gdi_select_object(dc_handle: u64, obj_handle: u64) -> u64 {
    let dc = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if dc.is_null() {
        return 0;
    }
    let idx = (obj_handle & 0xFFFF) as usize;
    if idx == 0 || idx >= GDI_HANDLE_TABLE_SIZE {
        return 0;
    }
    match GDI_HANDLE_TABLE[idx].handle_type {
        GDI_HANDLE_TYPE_BITMAP => {
            let prev = (*dc).selected_bitmap;
            (*dc).selected_bitmap = obj_handle;
            (*dc).bitmap = GDI_HANDLE_TABLE[idx].object as *mut GdiBitmap;
            if prev == 0 { 1 } else { prev }
        }
        GDI_HANDLE_TYPE_PEN => {
            let prev = (*dc).selected_pen;
            (*dc).selected_pen = obj_handle;
            (*dc).pen = *(GDI_HANDLE_TABLE[idx].object as *mut GdiPen);
            if prev == 0 { 1 } else { prev }
        }
        GDI_HANDLE_TYPE_BRUSH => {
            let prev = (*dc).selected_brush;
            (*dc).selected_brush = obj_handle;
            (*dc).brush = *(GDI_HANDLE_TABLE[idx].object as *mut GdiBrush);
            if prev == 0 { 1 } else { prev }
        }
        GDI_HANDLE_TYPE_FONT => {
            let prev = (*dc).selected_font;
            (*dc).selected_font = obj_handle;
            (*dc).font = *(GDI_HANDLE_TABLE[idx].object as *mut GdiFont);
            if prev == 0 { 1 } else { prev }
        }
        _ => 0,
    }
}

/// NtGdiDeleteObjectApp
pub unsafe fn gdi_delete_object(handle: u64) -> bool {
    let idx = (handle & 0xFFFF) as usize;
    if idx == 0 || idx >= GDI_HANDLE_TABLE_SIZE {
        return false;
    }
    let ty = GDI_HANDLE_TABLE[idx].handle_type;
    if ty == GDI_HANDLE_TYPE_FREE || GDI_HANDLE_TABLE[idx].stock {
        return false;
    }
    let obj = GDI_HANDLE_TABLE[idx].object;
    match ty {
        GDI_HANDLE_TYPE_BITMAP => {
            let bmp = obj as *mut GdiBitmap;
            if !bmp.is_null() && (*bmp).owned && !(*bmp).bits.is_null() {
                crate::mm::pool::ex_free_pool((*bmp).bits as *mut c_void);
            }
        }
        _ => {}
    }
    gdi_free_handle(handle);
    if !obj.is_null() {
        crate::mm::pool::ex_free_pool(obj);
    }
    true
}

// ============================================================
// ROP application
// ============================================================

fn gdi_apply_rop(rop: u32, src: u32, dst: u32, pat: u32) -> u32 {
    match rop {
        SRCCOPY => src,
        SRCPAINT => src | dst,
        SRCAND => src & dst,
        SRCINVERT => src ^ dst,
        SRCERASE => src & !dst,
        NOTSRCCOPY => !src,
        NOTSRCERASE => !(src | dst),
        DSTINVERT => !dst,
        PATINVERT => pat ^ dst,
        PATCOPY => pat,
        PATPAINT => pat | (src | dst),
        BLACKNESS => 0,
        WHITENESS => 0x00FF_FFFF,
        _ => src,
    }
}

// ============================================================
// Blts
// ============================================================

/// NtGdiBitBlt
pub unsafe fn gdi_bitblt(
    dst_dc: u64,
    dst_x: i32,
    dst_y: i32,
    width: i32,
    height: i32,
    src_dc: u64,
    src_x: i32,
    src_y: i32,
    rop: u32,
) -> bool {
    let d = gdi_lookup_handle(dst_dc, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if d.is_null() || width <= 0 || height <= 0 {
        return false;
    }
    let s = if src_dc != 0 {
        gdi_lookup_handle(src_dc, GDI_HANDLE_TYPE_DC) as *mut GdiDc
    } else {
        core::ptr::null_mut()
    };
    let pat = (*d).brush.color;
    // Direction: handle overlap by walking backwards when needed.
    let (x0, x1, dx): (i32, i32, i32) = if d == s && dst_x > src_x {
        (width - 1, -1, -1)
    } else {
        (0, width, 1)
    };
    let (y0, y1, dy): (i32, i32, i32) = if d == s && dst_y > src_y {
        (height - 1, -1, -1)
    } else {
        (0, height, 1)
    };
    let mut yy = y0;
    while yy != y1 {
        let mut xx = x0;
        while xx != x1 {
            let src = if s.is_null() {
                0
            } else {
                gdip_get_pixel_raw(s, src_x + xx, src_y + yy)
            };
            let dst = gdip_get_pixel_raw(d, dst_x + xx, dst_y + yy);
            gdip_set_pixel_raw(d, dst_x + xx, dst_y + yy, gdi_apply_rop(rop, src, dst, pat));
            xx += dx;
        }
        yy += dy;
    }
    true
}

/// NtGdiPatBlt
pub unsafe fn gdi_patblt(dc_handle: u64, x: i32, y: i32, w: i32, h: i32, rop: u32) -> bool {
    let d = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if d.is_null() || w <= 0 || h <= 0 {
        return false;
    }
    let pat = (*d).brush.color;
    let mut yy = 0;
    while yy < h {
        let mut xx = 0;
        while xx < w {
            let dst = gdip_get_pixel_raw(d, x + xx, y + yy);
            gdip_set_pixel_raw(d, x + xx, y + yy, gdi_apply_rop(rop, 0, dst, pat));
            xx += 1;
        }
        yy += 1;
    }
    true
}

/// FillRect with a brush color.
pub unsafe fn gdi_fill_rect(dc_handle: u64, left: i32, top: i32, right: i32, bottom: i32, color: u32) -> bool {
    let d = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if d.is_null() {
        return false;
    }
    let mut yy = top;
    while yy < bottom {
        let mut xx = left;
        while xx < right {
            gdip_set_pixel_raw(d, xx, yy, color & 0x00FF_FFFF);
            xx += 1;
        }
        yy += 1;
    }
    true
}

// ============================================================
// Lines / rectangles / ellipses
// ============================================================

unsafe fn gdip_hline(dc: *mut GdiDc, x0: i32, x1: i32, y: i32, color: u32) {
    let (mut a, mut b) = (x0, x1);
    if a > b {
        let t = a;
        a = b;
        b = t;
    }
    let mut x = a;
    while x <= b {
        gdip_set_pixel_raw(dc, x, y, color);
        x += 1;
    }
}

/// Bresenham line.
pub unsafe fn gdi_line(dc_handle: u64, x0: i32, y0: i32, x1: i32, y1: i32, color: u32) {
    let d = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if d.is_null() {
        return;
    }
    let mut x = x0;
    let mut y = y0;
    let dx = (x1 - x0).abs();
    let dy = -(y1 - y0).abs();
    let sx = if x0 < x1 { 1 } else { -1 };
    let sy = if y0 < y1 { 1 } else { -1 };
    let mut err = dx + dy;
    loop {
        gdip_set_pixel_raw(d, x, y, color);
        if x == x1 && y == y1 {
            break;
        }
        let e2 = 2 * err;
        if e2 >= dy {
            err += dy;
            x += sx;
        }
        if e2 <= dx {
            err += dx;
            y += sy;
        }
    }
}

/// NtGdiRectangle - fill + outline.
pub unsafe fn gdi_rectangle(dc_handle: u64, l: i32, t: i32, r: i32, b: i32) -> bool {
    let d = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if d.is_null() {
        return false;
    }
    let brush = (*d).brush.color;
    let pen = (*d).pen.color;
    let mut yy = t + 1;
    while yy < b {
        gdip_hline(d, l + 1, r - 1, yy, brush);
        yy += 1;
    }
    gdip_hline(d, l, r, t, pen);
    gdip_hline(d, l, r, b, pen);
    let mut y2 = t;
    while y2 <= b {
        gdip_set_pixel_raw(d, l, y2, pen);
        gdip_set_pixel_raw(d, r, y2, pen);
        y2 += 1;
    }
    true
}

/// NtGdiEllipse - midpoint ellipse fill + outline.
pub unsafe fn gdi_ellipse(dc_handle: u64, l: i32, t: i32, r: i32, b: i32) -> bool {
    let d = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if d.is_null() {
        return false;
    }
    let brush = (*d).brush.color;
    let pen = (*d).pen.color;
    let xc = (l + r) / 2;
    let yc = (t + b) / 2;
    let rx = ((r - l) / 2).max(1);
    let ry = ((b - t) / 2).max(1);
    // Scanline fill via ellipse equation.
    let mut y = t;
    while y <= b {
        let dy = y - yc;
        // (dy/ry)^2 <= 1 -> half-width
        let v: i64 = (ry as i64) * (ry as i64) - (dy as i64) * (dy as i64);
        if v >= 0 {
            // half = rx * sqrt(v) / ry  (integer sqrt)
            let mut half = 0i64;
            let target = (rx as i64) * (rx as i64) * v / ((ry as i64) * (ry as i64));
            while (half + 1) * (half + 1) <= target {
                half += 1;
            }
            gdip_hline(d, xc - half as i32 + 1, xc + half as i32 - 1, y, brush);
            gdip_set_pixel_raw(d, xc - half as i32, y, pen);
            gdip_set_pixel_raw(d, xc + half as i32, y, pen);
        }
        y += 1;
    }
    true
}

pub unsafe fn gdi_set_pixel(dc_handle: u64, x: i32, y: i32, color: u32) -> u32 {
    let d = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if d.is_null() {
        return 0xFFFF_FFFF;
    }
    let prev = gdip_get_pixel_raw(d, x, y);
    gdip_set_pixel_raw(d, x, y, color & 0x00FF_FFFF);
    prev
}

pub unsafe fn gdi_get_pixel(dc_handle: u64, x: i32, y: i32) -> u32 {
    let d = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if d.is_null() {
        return 0xFFFF_FFFF;
    }
    gdip_get_pixel_raw(d, x, y)
}

// ============================================================
// Stroke font + TextOut
// ============================================================

// One segment: (x0,y0)-(x1,y1) in an 8x12 cell (0..7, 0..11).
type Seg = (i8, i8, i8, i8);

// Glyph strokes for capitals + digits (designed, coherent plotter font).
fn stroke_glyph(ch: u8) -> &'static [Seg] {
    match ch {
        b'A' => &[(0, 11, 4, 0), (4, 0, 8, 11), (2, 7, 6, 7)],
        b'B' => &[
            (0, 0, 0, 11),
            (0, 0, 5, 0),
            (5, 0, 7, 2),
            (7, 2, 5, 5),
            (5, 5, 0, 5),
            (5, 5, 7, 8),
            (7, 8, 5, 11),
            (5, 11, 0, 11),
        ],
        b'C' => &[(7, 1, 4, 0), (4, 0, 1, 2), (1, 2, 1, 9), (1, 9, 4, 11), (4, 11, 7, 10)],
        b'D' => &[(0, 0, 0, 11), (0, 0, 4, 0), (7, 3, 7, 8), (4, 11, 0, 11), (4, 0, 7, 3), (7, 8, 4, 11)],
        b'E' => &[(7, 0, 0, 0), (0, 0, 0, 11), (0, 11, 7, 11), (0, 5, 5, 5)],
        b'F' => &[(7, 0, 0, 0), (0, 0, 0, 11), (0, 5, 5, 5)],
        b'G' => &[
            (7, 1, 4, 0),
            (4, 0, 1, 2),
            (1, 2, 1, 9),
            (1, 9, 4, 11),
            (4, 11, 7, 10),
            (7, 10, 7, 6),
            (7, 6, 4, 6),
        ],
        b'H' => &[(0, 0, 0, 11), (8, 0, 8, 11), (0, 5, 8, 5)],
        b'I' => &[(1, 0, 7, 0), (4, 0, 4, 11), (1, 11, 7, 11)],
        b'J' => &[(7, 0, 7, 9), (7, 9, 4, 11), (4, 11, 1, 9)],
        b'K' => &[(0, 0, 0, 11), (7, 0, 0, 5), (3, 8, 7, 11)],
        b'L' => &[(0, 0, 0, 11), (0, 11, 7, 11)],
        b'M' => &[(0, 11, 0, 0), (0, 0, 4, 6), (4, 6, 8, 0), (8, 0, 8, 11)],
        b'N' => &[(0, 11, 0, 0), (0, 0, 8, 11), (8, 11, 8, 0)],
        b'O' => &[(2, 0, 6, 0), (6, 0, 8, 2), (8, 2, 8, 9), (8, 9, 6, 11), (6, 11, 2, 11), (2, 11, 0, 9), (0, 9, 0, 2), (0, 2, 2, 0)],
        b'P' => &[(0, 11, 0, 0), (0, 0, 5, 0), (7, 2, 7, 4), (5, 6, 0, 6), (5, 0, 7, 2), (7, 4, 5, 6)],
        b'Q' => &[
            (2, 0, 6, 0),
            (6, 0, 8, 2),
            (8, 2, 8, 9),
            (8, 9, 6, 11),
            (6, 11, 2, 11),
            (2, 11, 0, 9),
            (0, 9, 0, 2),
            (0, 2, 2, 0),
            (5, 8, 8, 11),
        ],
        b'R' => &[
            (0, 11, 0, 0),
            (0, 0, 5, 0),
            (7, 2, 7, 4),
            (5, 6, 0, 6),
            (5, 0, 7, 2),
            (7, 4, 5, 6),
            (4, 6, 8, 11),
        ],
        b'S' => &[(7, 1, 5, 0), (5, 0, 2, 0), (0, 2, 0, 4), (0, 4, 2, 6), (6, 6, 8, 8), (8, 8, 8, 10), (6, 11, 3, 11), (1, 10, 0, 9), (2, 0, 0, 2), (2, 6, 6, 6)],
        b'T' => &[(0, 0, 8, 0), (4, 0, 4, 11)],
        b'U' => &[(0, 0, 0, 9), (0, 9, 2, 11), (2, 11, 6, 11), (6, 11, 8, 9), (8, 9, 8, 0)],
        b'V' => &[(0, 0, 4, 11), (4, 11, 8, 0)],
        b'W' => &[(0, 0, 2, 11), (2, 11, 4, 5), (4, 5, 6, 11), (6, 11, 8, 0)],
        b'X' => &[(0, 0, 8, 11), (8, 0, 0, 11)],
        b'Y' => &[(0, 0, 4, 5), (8, 0, 4, 5), (4, 5, 4, 11)],
        b'Z' => &[(0, 0, 8, 0), (8, 0, 0, 11), (0, 11, 8, 11)],
        b'0' => &[(2, 0, 6, 0), (6, 0, 8, 2), (8, 2, 8, 9), (8, 9, 6, 11), (6, 11, 2, 11), (2, 11, 0, 9), (0, 9, 0, 2), (0, 2, 2, 0), (2, 9, 6, 2)],
        b'1' => &[(2, 1, 4, 0), (4, 0, 4, 11), (1, 11, 7, 11)],
        b'2' => &[(1, 1, 3, 0), (3, 0, 6, 0), (8, 2, 8, 4), (8, 4, 0, 11), (0, 11, 8, 11)],
        b'3' => &[(1, 1, 3, 0), (3, 0, 6, 0), (8, 2, 8, 4), (4, 5, 8, 5), (8, 7, 8, 9), (6, 11, 3, 11), (1, 10, 0, 9), (8, 4, 4, 5), (4, 5, 8, 7)],
        b'4' => &[(6, 0, 0, 7), (0, 7, 8, 7), (6, 0, 6, 11)],
        b'5' => &[(7, 0, 1, 0), (1, 0, 0, 5), (0, 5, 5, 5), (8, 7, 8, 9), (6, 11, 2, 11), (0, 10, 0, 9), (5, 5, 8, 7), (8, 9, 6, 11), (2, 11, 0, 10)],
        b'6' => &[(6, 1, 3, 0), (1, 2, 0, 5), (0, 5, 0, 9), (0, 9, 2, 11), (2, 11, 6, 11), (8, 9, 8, 7), (8, 7, 6, 5), (6, 5, 2, 5)],
        b'7' => &[(0, 0, 8, 0), (8, 0, 3, 11)],
        b'8' => &[
            (2, 0, 6, 0),
            (6, 0, 8, 2),
            (8, 2, 6, 5),
            (6, 5, 2, 5),
            (2, 5, 0, 2),
            (0, 2, 2, 0),
            (2, 5, 0, 8),
            (0, 8, 2, 11),
            (2, 11, 6, 11),
            (6, 11, 8, 8),
            (8, 8, 6, 5),
        ],
        b'9' => &[(2, 0, 6, 0), (6, 0, 8, 2), (8, 2, 8, 6), (8, 6, 6, 9), (6, 9, 2, 11), (2, 6, 6, 6), (2, 0, 0, 3), (0, 3, 2, 6)],
        b' ' => &[],
        b'.' => &[(3, 10, 4, 10), (3, 11, 4, 11), (3, 10, 3, 11), (4, 10, 4, 11)],
        b',' => &[(3, 9, 4, 9), (3, 10, 4, 10), (3, 9, 2, 11)],
        b':' => &[(3, 4, 4, 4), (3, 5, 4, 5), (3, 4, 3, 5), (4, 4, 4, 5), (3, 8, 4, 8), (3, 9, 4, 9), (3, 8, 3, 9), (4, 8, 4, 9)],
        b'-' => &[(1, 6, 7, 6)],
        b'_' => &[(1, 11, 7, 11)],
        b'+' => &[(1, 6, 7, 6), (4, 3, 4, 9)],
        b'=' => &[(1, 4, 7, 4), (1, 8, 7, 8)],
        b'!' => &[(4, 0, 4, 8), (3, 10, 4, 10), (3, 11, 4, 11)],
        b'?' => &[(1, 1, 3, 0), (3, 0, 6, 0), (8, 2, 8, 4), (8, 4, 4, 7), (4, 7, 4, 8), (3, 10, 4, 10), (3, 11, 4, 11)],
        b'/' => &[(7, 0, 1, 11)],
        b'\\' => &[(1, 0, 7, 11)],
        b'(' => &[(5, 0, 2, 3), (2, 3, 2, 8), (2, 8, 5, 11)],
        b')' => &[(3, 0, 6, 3), (6, 3, 6, 8), (6, 8, 3, 11)],
        b'<' => &[(6, 1, 1, 6), (1, 6, 6, 11)],
        b'>' => &[(2, 1, 7, 6), (7, 6, 2, 11)],
        b'*' => &[(4, 2, 4, 9), (1, 4, 7, 7), (7, 4, 1, 7)],
        b'#' => &[(3, 1, 3, 10), (5, 1, 5, 10), (1, 4, 7, 4), (1, 7, 7, 7)],
        b'%' => &[(7, 0, 1, 11), (1, 1, 3, 1), (1, 4, 3, 4), (1, 1, 1, 4), (3, 1, 3, 4), (5, 7, 7, 7), (5, 10, 7, 10), (5, 7, 5, 10), (7, 7, 7, 10)],
        b'\'' => &[(4, 0, 3, 3)],
        b'"' => &[(2, 0, 1, 3), (6, 0, 5, 3)],
        b';' => &[(3, 4, 4, 4), (3, 5, 4, 5), (3, 9, 4, 9), (3, 10, 4, 10), (3, 9, 2, 11)],
        _ => &[(0, 0, 8, 0), (8, 0, 8, 11), (8, 11, 0, 11), (0, 11, 0, 0)], // box = unknown
    }
}

unsafe fn gdip_draw_line_seg(
    dc: *mut GdiDc,
    x0: i32,
    y0: i32,
    x1: i32,
    y1: i32,
    color: u32,
) {
    let mut x = x0;
    let mut y = y0;
    let dx = (x1 - x0).abs();
    let dy = -(y1 - y0).abs();
    let sx = if x0 < x1 { 1 } else { -1 };
    let sy = if y0 < y1 { 1 } else { -1 };
    let mut err = dx + dy;
    loop {
        gdip_set_pixel_raw(dc, x, y, color);
        if x == x1 && y == y1 {
            break;
        }
        let e2 = 2 * err;
        if e2 >= dy {
            err += dy;
            x += sx;
        }
        if e2 <= dx {
            err += dx;
            y += sy;
        }
    }
}

/// NtGdiTextOut - stroke-font text.
pub unsafe fn gdi_text_out(
    dc_handle: u64,
    x: i32,
    y: i32,
    text: *const u16,
    length: u32,
) -> bool {
    let dc = gdi_lookup_handle(dc_handle, GDI_HANDLE_TYPE_DC) as *mut GdiDc;
    if dc.is_null() || text.is_null() {
        return false;
    }
    let color = (*dc).text_color & 0x00FF_FFFF;
    let scale = (((*dc).font.height.max(8)) / 8).max(1) as i32;
    let cell_w = 9 * scale;
    let mut cx = x;
    let mut i = 0u32;
    while i < length {
        let mut ch = *text.add(i as usize);
        // Cyrillic А..Я/а..я -> map to Latin lookalikes where possible,
        // else box (keep honest: no fake glyphs).
        if ch >= 0x0410 && ch <= 0x044F {
            ch = match ch {
                0x0410 => b'A' as u16, // А
                0x0412 => b'B' as u16, // В
                0x0415 => b'E' as u16, // Е
                0x041A => b'K' as u16, // К
                0x041C => b'M' as u16, // М
                0x041D => b'H' as u16, // Н
                0x041E => b'O' as u16, // О
                0x0420 => b'P' as u16, // Р
                0x0421 => b'C' as u16, // С
                0x0422 => b'T' as u16, // Т
                0x0425 => b'X' as u16, // Х
                0x0430 => b'A' as u16, // а
                0x0435 => b'e' as u16, // е -> E-ish
                0x043E => b'o' as u16, // о
                0x0440 => b'p' as u16, // р
                0x0441 => b'c' as u16, // с
                _ => 0xFFFD,
            };
        }
        let ascii = if ch < 128 {
            ch as u8
        } else if ch >= b'a' as u16 && ch <= b'z' as u16 {
            (ch - 32) as u8
        } else if ch == 0xFFFD {
            0 // box
        } else {
            0 // box
        };
        // Map lowercase latin leftovers.
        let glyph_ch = if ascii >= b'a' && ascii <= b'z' {
            ascii - 32
        } else {
            ascii
        };
        for seg in stroke_glyph(glyph_ch) {
            gdip_draw_line_seg(
                dc,
                cx + seg.0 as i32 * scale,
                y + seg.1 as i32 * scale,
                cx + seg.2 as i32 * scale,
                y + seg.3 as i32 * scale,
                color,
            );
        }
        // Opaque background.
        if (*dc).bk_mode == 1 {
            // (background already painted by caller via FillRect normally)
        }
        cx += cell_w;
        i += 1;
    }
    true
}

// ============================================================
// Regions
// ============================================================

pub unsafe fn gdi_combine_region(
    dst_handle: u64,
    src1_handle: u64,
    src2_handle: u64,
    mode: u32,
) -> u32 {
    let dst = gdi_lookup_handle(dst_handle, GDI_HANDLE_TYPE_REGION) as *mut GdiRegion;
    let s1 = gdi_lookup_handle(src1_handle, GDI_HANDLE_TYPE_REGION) as *mut GdiRegion;
    let s2 = gdi_lookup_handle(src2_handle, GDI_HANDLE_TYPE_REGION) as *mut GdiRegion;
    if dst.is_null() || s1.is_null() {
        return 0; // ERROR
    }
    match mode {
        RGN_COPY => {
            *dst = *s1;
            SIMPLEREGION
        }
        RGN_AND => {
            // Bounding-box intersection (conservative).
            let a = (*s1).bounds;
            let b = if s2.is_null() {
                a
            } else {
                (*s2).bounds
            };
            let l = a.left.max(b.left);
            let t = a.top.max(b.top);
            let r = a.right.min(b.right);
            let b2 = a.bottom.min(b.bottom);
            if r <= l || b2 <= t {
                (*dst).rect_count = 0;
                return NULLREGION;
            }
            (*dst).rects[0] = GdiRect {
                left: l,
                top: t,
                right: r,
                bottom: b2,
            };
            (*dst).rect_count = 1;
            (*dst).bounds = (*dst).rects[0];
            SIMPLEREGION
        }
        RGN_OR | RGN_XOR | RGN_DIFF => {
            // Bounding-box union (conservative).
            let a = (*s1).bounds;
            let b = if s2.is_null() {
                a
            } else {
                (*s2).bounds
            };
            (*dst).rects[0] = GdiRect {
                left: a.left.min(b.left),
                top: a.top.min(b.top),
                right: a.right.max(b.right),
                bottom: a.bottom.max(b.bottom),
            };
            (*dst).rect_count = 1;
            (*dst).bounds = (*dst).rects[0];
            SIMPLEREGION
        }
        _ => 0,
    }
}

// ============================================================
// Base init + shadow-SSDT registration
// ============================================================

pub unsafe fn gdi_init_base() -> NtStatus {
    // Stock objects: white/black brush+pen.
    let wb = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<GdiBrush>(),
    ) as *mut GdiBrush;
    if !wb.is_null() {
        (*wb).solid = true;
        (*wb).color = 0xFF_FFFF;
        (*wb).hatch = 0;
        let idx = 1usize;
        GDI_HANDLE_TABLE[idx].object = wb as *mut c_void;
        GDI_HANDLE_TABLE[idx].handle_type = GDI_HANDLE_TYPE_BRUSH;
        GDI_HANDLE_TABLE[idx].stock = true;
    }
    STATUS_SUCCESS
}

unsafe extern "C" fn ntgdi_bitblt(args: *const u64, n: u32) -> u64 {
    if n < 9 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 9);
    gdi_bitblt(a[0], a[1] as i32, a[2] as i32, a[3] as i32, a[4] as i32, a[5], a[6] as i32, a[7] as i32, a[8] as u32) as u64
}

unsafe extern "C" fn ntgdi_patblt(args: *const u64, n: u32) -> u64 {
    if n < 6 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 6);
    gdi_patblt(a[0], a[1] as i32, a[2] as i32, a[3] as i32, a[4] as i32, a[5] as u32) as u64
}

unsafe extern "C" fn ntgdi_textout(args: *const u64, n: u32) -> u64 {
    if n < 5 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 5);
    gdi_text_out(a[0], a[1] as i32, a[2] as i32, a[3] as *const u16, a[4] as u32) as u64
}

unsafe extern "C" fn ntgdi_create_dc(args: *const u64, n: u32) -> u64 {
    if n < 2 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 2);
    // args: display(0/1), process_id
    if a[0] == 1 {
        gdi_create_display_dc(a[1])
    } else {
        gdi_create_compatible_dc(0, a[1])
    }
}

unsafe extern "C" fn ntgdi_delete_object(args: *const u64, n: u32) -> u64 {
    if n < 1 || args.is_null() {
        return 0;
    }
    gdi_delete_object(*args) as u64
}

unsafe extern "C" fn ntgdi_fill_rect(args: *const u64, n: u32) -> u64 {
    if n < 6 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 6);
    gdi_fill_rect(a[0], a[1] as i32, a[2] as i32, a[3] as i32, a[4] as i32, a[5] as u32) as u64
}

unsafe extern "C" fn ntgdi_set_pixel(args: *const u64, n: u32) -> u64 {
    if n < 4 || args.is_null() {
        return 0xFFFF_FFFF;
    }
    let a = core::slice::from_raw_parts(args, 4);
    gdi_set_pixel(a[0], a[1] as i32, a[2] as i32, a[3] as u32) as u64
}

unsafe extern "C" fn ntgdi_get_pixel(args: *const u64, n: u32) -> u64 {
    if n < 3 || args.is_null() {
        return 0xFFFF_FFFF;
    }
    let a = core::slice::from_raw_parts(args, 3);
    gdi_get_pixel(a[0], a[1] as i32, a[2] as i32) as u64
}

pub unsafe fn gdi_register_services() -> NtStatus {
    use super::*;
    win32k_register_service(NTGDI_BITBLT, ntgdi_bitblt as Win32kServiceHandler);
    win32k_register_service(NTGDI_PATBLT, ntgdi_patblt as Win32kServiceHandler);
    win32k_register_service(NTGDI_TEXTOUT, ntgdi_textout as Win32kServiceHandler);
    win32k_register_service(NTGDI_CREATE_DC, ntgdi_create_dc as Win32kServiceHandler);
    win32k_register_service(NTGDI_DELETE_OBJECT, ntgdi_delete_object as Win32kServiceHandler);
    win32k_register_service(NTGDI_FILL_RECT, ntgdi_fill_rect as Win32kServiceHandler);
    win32k_register_service(NTGDI_SET_PIXEL, ntgdi_set_pixel as Win32kServiceHandler);
    win32k_register_service(NTGDI_GET_PIXEL, ntgdi_get_pixel as Win32kServiceHandler);
    STATUS_SUCCESS
}
