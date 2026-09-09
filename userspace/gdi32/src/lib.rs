#![no_std]
#![allow(non_snake_case)]
#![allow(non_camel_case_types)]

extern crate alloc;

use ntdll::*;

// ============================================================
// Types
// ============================================================

pub type HDC = HANDLE;
pub type HBITMAP = HANDLE;
pub type HBRUSH = HANDLE;
pub type HPEN = HANDLE;
pub type HFONT = HANDLE;
pub type HGDIOBJ = HANDLE;
pub type HRGN = HANDLE;
pub type HPALETTE = HANDLE;
pub type HICON = HANDLE;

pub type DWORD = u32;
pub type WORD = u16;
pub type BYTE = u8;
pub type BOOL = i32;
pub type LONG = i32;
pub type UINT = u32;
pub type LPVOID = *mut core::ffi::c_void;
pub type LPCVOID = *const core::ffi::c_void;
pub type LPSTR = *mut u8;
pub type LPCSTR = *const u8;
pub type LPWSTR = *mut u16;
pub type LPCWSTR = *const u16;

pub const TRUE: BOOL = 1;
pub const FALSE: BOOL = 0;

#[repr(C)]
pub struct RECT {
    pub left: LONG,
    pub top: LONG,
    pub right: LONG,
    pub bottom: LONG,
}

#[repr(C)]
pub struct POINT {
    pub x: LONG,
    pub y: LONG,
}

#[repr(C)]
pub struct BITMAPINFOHEADER {
    pub bi_size: u32,
    pub bi_width: i32,
    pub bi_height: i32,
    pub bi_planes: u16,
    pub bi_bit_count: u16,
    pub bi_compression: u32,
    pub bi_size_image: u32,
    pub bi_x_pels_per_meter: i32,
    pub bi_y_pels_per_meter: i32,
    pub bi_clr_used: u32,
    pub bi_clr_important: u32,
}

#[repr(C)]
pub struct BITMAPINFO {
    pub bmi_header: BITMAPINFOHEADER,
    pub bmi_colors: [u32; 1],
}

#[repr(C)]
pub struct RGBQUAD {
    pub rgb_blue: u8,
    pub rgb_green: u8,
    pub rgb_red: u8,
    pub rgb_reserved: u8,
}

#[repr(C)]
pub struct LOGFONTW {
    pub lf_height: LONG,
    pub lf_width: LONG,
    pub lf_escapement: LONG,
    pub lf_orientation: LONG,
    pub lf_weight: LONG,
    pub lf_italic: BYTE,
    pub lf_underline: BYTE,
    pub lf_strike_out: BYTE,
    pub lf_char_set: BYTE,
    pub lf_out_precision: BYTE,
    pub lf_clip_precision: BYTE,
    pub lf_quality: BYTE,
    pub lf_pitch_and_family: BYTE,
    pub lf_face_name: [u16; 32],
}

#[repr(C)]
pub struct TEXTMETRICW {
    pub tm_height: LONG,
    pub tm_ascent: LONG,
    pub tm_descent: LONG,
    pub tm_internal_leading: LONG,
    pub tm_external_leading: LONG,
    pub tm_ave_char_width: LONG,
    pub tm_max_char_width: LONG,
    pub tm_weight: LONG,
    pub tm_overhang: LONG,
    pub tm_digitized_aspect_x: LONG,
    pub tm_digitized_aspect_y: LONG,
    pub tm_first_char: u16,
    pub tm_last_char: u16,
    pub tm_default_char: u16,
    pub tm_break_char: u16,
    pub tm_pitch_and_family: BYTE,
    pub tm CharSet: BYTE,
}

#[repr(C)]
pub struct BITMAP {
    pub bm_type: LONG,
    pub bm_width: LONG,
    pub bm_height: LONG,
    pub bm_width_bytes: LONG,
    pub bm_planes: WORD,
    pub bm_bits_pixel: WORD,
    pub bm_bits: LPVOID,
}

// ============================================================
// Device Context functions
// ============================================================

pub unsafe fn CreateCompatibleDC(hdc: HDC) -> HDC {
    // Create a memory DC compatible with the specified DC
    // Allocate a DC handle
    let dc = alloc_mem(core::mem::size_of::<usize>());
    match dc {
        Ok(ptr) => ptr as HDC,
        Err(_) => core::ptr::null_mut(),
    }
}

pub unsafe fn DeleteDC(hdc: HDC) -> BOOL {
    if hdc.is_null() { return FALSE; }
    let _ = free_mem(hdc as LPVOID, core::mem::size_of::<usize>());
    TRUE
}

pub unsafe fn GetDeviceCaps(hdc: HDC, nIndex: i32) -> i32 {
    match nIndex {
        8 => 8,      // BITSPIXEL
        10 => 72,    // LOGPIXELSX
        11 => 72,    // LOGPIXELSY
        12 => 96,    // HORZRES
        13 => 96,    // VERTRES
        14 => 0,     // HORZSIZE
        15 => 0,     // VERTSIZE
        24 => 0,     // RASTERCAPS
        38 => 1,     // NUMCOLORS
        45 => 65536, // NUMBRUSHES
        46 => 0,     // NUMPENS
        56 => 1,     // NUMFONTS
        88 => 256,   // COLORRES
        113 => 0,    // SIZEPALETTE
        114 => 0,    // NUMRESERVED
        124 => 2,    // ASPECTX
        125 => 2,    // ASPECTY
        126 => 3,    // ASPECTXY
        _ => 0,
    }
}

pub unsafe fn GetStockObject(i: i32) -> HGDIOBJ {
    // Return stock objects
    match i {
        0 => 1isize as HGDIOBJ, // WHITE_BRUSH
        1 => 2isize as HGDIOBJ, // LTGRAY_BRUSH
        2 => 3isize as HGDIOBJ, // GRAY_BRUSH
        3 => 4isize as HGDIOBJ, // DKGRAY_BRUSH
        4 => 5isize as HGDIOBJ, // BLACK_BRUSH
        5 => 6isize as HGDIOBJ, // NULL_BRUSH
        6 => 7isize as HGDIOBJ, // WHITE_PEN
        7 => 8isize as HGDIOBJ, // BLACK_PEN
        8 => 9isize as HGDIOBJ, // NULL_PEN
        10 => 10isize as HGDIOBJ, // OEM_FIXED_FONT
        11 => 11isize as HGDIOBJ, // ANSI_FIXED_FONT
        12 => 12isize as HGDIOBJ, // ANSI_VAR_FONT
        13 => 13isize as HGDIOBJ, // SYSTEM_FONT
        16 => 14isize as HGDIOBJ, // DEFAULT_PALETTE
        17 => 15isize as HGDIOBJ, // SYSTEM_PALETTE
        _ => 0isize as HGDIOBJ,
    }
}

pub unsafe fn SaveDC(hdc: HDC) -> i32 {
    1 // saved state ID
}

pub unsafe fn RestoreDC(hdc: HDC, nSavedDC: i32) -> BOOL {
    TRUE
}

pub unsafe fn SetViewportOrgEx(hdc: HDC, x: i32, y: i32, lp_point: *mut POINT) -> BOOL {
    TRUE
}

pub unsafe fn SetWindowOrgEx(hdc: HDC, x: i32, y: i32, lp_point: *mut POINT) -> BOOL {
    TRUE
}

pub unsafe fn GetViewportOrgEx(hdc: HDC, lp_point: *mut POINT) -> BOOL {
    if !lp_point.is_null() { *lp_point = POINT { x: 0, y: 0 }; }
    TRUE
}

// ============================================================
// Bitmap functions
// ============================================================

pub unsafe fn CreateCompatibleBitmap(hdc: HDC, n_width: i32, n_height: i32) -> HBITMAP {
    // Create a bitmap compatible with the specified device
    let size = core::mem::size_of::<BITMAP>();
    match alloc_mem(size) {
        Ok(ptr) => {
            let bmp = &mut *(ptr as *mut BITMAP);
            bmp.bm_type = 0;
            bmp.bm_width = n_width;
            bmp.bm_height = n_height;
            bmp.bm_width_bytes = (n_width * 4 + 3) & !3;
            bmp.bm_planes = 1;
            bmp.bm_bits_pixel = 32;
            bmp.bm_bits = core::ptr::null_mut();
            ptr as HBITMAP
        }
        Err(_) => core::ptr::null_mut(),
    }
}

pub unsafe fn CreateBitmap(n_width: i32, n_height: i32, c_planes: UINT, c_bits_per_pixel: UINT, lp_bits: LPCVOID) -> HBITMAP {
    core::ptr::null_mut()
}

pub unsafe fn CreateBitmapIndirect(lpbm: *const BITMAP) -> HBITMAP {
    core::ptr::null_mut()
}

pub unsafe fn CreateDIBSection(
    hdc: HDC, lpbmi: *const BITMAPINFO, usage: UINT,
    ppv_bits: *mut LPVOID, h_section: HANDLE, offset: u32,
) -> HBITMAP {
    if lpbmi.is_null() { return core::ptr::null_mut(); }
    let bmi = &*lpbmi;
    let w = bmi.bmi_header.bi_width;
    let h = bmi.bmi_header.bi_height.abs();
    let stride = ((w as usize * 4) + 3) & !3;
    let total = stride * h as usize;
    match alloc_mem(total) {
        Ok(ptr) => {
            if !ppv_bits.is_null() { *ppv_bits = ptr; }
            match alloc_mem(core::mem::size_of::<BITMAP>()) {
                Ok(bmp_ptr) => {
                    let bmp = &mut *(bmp_ptr as *mut BITMAP);
                    bmp.bm_type = 0;
                    bmp.bm_width = w;
                    bmp.bm_height = h;
                    bmp.bm_width_bytes = stride as LONG;
                    bmp.bm_planes = 1;
                    bmp.bm_bits_pixel = 32;
                    bmp.bm_bits = ptr;
                    bmp_ptr as HBITMAP
                }
                Err(_) => {
                    let _ = free_mem(ptr, total);
                    core::ptr::null_mut()
                }
            }
        }
        Err(_) => core::ptr::null_mut(),
    }
}

pub unsafe fn DeleteObject(hObject: HGDIOBJ) -> BOOL {
    if hObject.is_null() { return FALSE; }
    let _ = free_mem(hObject as LPVOID, core::mem::size_of::<usize>());
    TRUE
}

pub unsafe fn SelectObject(hdc: HDC, hgdiobj: HGDIOBJ) -> HGDIOBJ {
    // Return previous object
    hgdiobj
}

pub unsafe fn GetObjectW(hObject: HGDIOBJ, c: i32, lpv: LPVOID) -> i32 {
    if hObject.is_null() || lpv.is_null() { return 0; }
    if c as usize >= core::mem::size_of::<BITMAP>() {
        let bmp = &mut *(lpv as *mut BITMAP);
        bmp.bm_type = 0;
        bmp.bm_width = 0;
        bmp.bm_height = 0;
        bmp.bm_width_bytes = 0;
        bmp.bm_planes = 1;
        bmp.bm_bits_pixel = 32;
        core::mem::size_of::<BITMAP>() as i32
    } else {
        0
    }
}

// ============================================================
// Drawing functions
// ============================================================

pub unsafe fn BitBlt(
    hdc_dst: HDC, x_dst: i32, y_dst: i32, c_width: i32, c_height: i32,
    hdc_src: HDC, x_src: i32, y_src: i32, rop: u32,
) -> BOOL {
    TRUE
}

pub unsafe fn StretchBlt(
    hdc_dst: HDC, x_dst: i32, y_dst: i32, w_dst: i32, h_dst: i32,
    hdc_src: HDC, x_src: i32, y_src: i32, w_src: i32, h_src: i32, rop: u32,
) -> BOOL {
    TRUE
}

pub unsafe fn PatBlt(hdc: HDC, x: i32, y: i32, width: i32, height: i32, rop: u32) -> BOOL {
    TRUE
}

pub unsafe fn TransparentBlt(
    hdc_dst: HDC, x_dst: i32, y_dst: i32, w_dst: i32, h_dst: i32,
    hdc_src: HDC, x_src: i32, y_src: i32, w_src: i32, h_src: i32, transparent: u32,
) -> BOOL {
    TRUE
}

// ============================================================
// Brush functions
// ============================================================

pub unsafe fn CreateSolidBrush(cr_color: u32) -> HBRUSH {
    match alloc_mem(core::mem::size_of::<u32>()) {
        Ok(ptr) => {
            *(ptr as *mut u32) = cr_color;
            ptr as HBRUSH
        }
        Err(_) => core::ptr::null_mut(),
    }
}

pub unsafe fn CreateHatchBrush(i_style: i32, cr_color: u32) -> HBRUSH {
    core::ptr::null_mut()
}

pub unsafe fn CreatePatternBrush(hbmp: HBITMAP) -> HBRUSH {
    core::ptr::null_mut()
}

pub unsafe fn CreateBrushIndirect(lplb: LPVOID) -> HBRUSH {
    core::ptr::null_mut()
}

// ============================================================
// Pen functions
// ============================================================

pub unsafe fn CreatePen(i_style: i32, n_width: i32, cr_color: u32) -> HPEN {
    match alloc_mem(core::mem::size_of::<u32>() * 2) {
        Ok(ptr) => {
            let p = ptr as *mut u32;
            *p = i_style as u32;
            *p.add(1) = cr_color;
            ptr as HPEN
        }
        Err(_) => core::ptr::null_mut(),
    }
}

pub unsafe fn CreatePenIndirect(lplpen: LPVOID) -> HPEN {
    core::ptr::null_mut()
}

// ============================================================
// Font functions
// ============================================================

pub unsafe fn CreateFontIndirectW(lplf: *const LOGFONTW) -> HFONT {
    if lplf.is_null() { return core::ptr::null_mut(); }
    match alloc_mem(core::mem::size_of::<LOGFONTW>()) {
        Ok(ptr) => {
            core::ptr::copy_nonoverlapping(lplf, ptr as *mut LOGFONTW, 1);
            ptr as HFONT
        }
        Err(_) => core::ptr::null_mut(),
    }
}

pub unsafe fn CreateFontW(
    n_height: i32, n_width: i32, n_escapement: i32, n_orientation: i32,
    n_weight: i32, fdw_italic: DWORD, fdw_underline: DWORD, fdw_strike_out: DWORD,
    fdw_char_set: DWORD, fdw_output_precision: DWORD, fdw_clip_precision: DWORD,
    fdw_quality: DWORD, fdw_pitch_and_family: DWORD, lpsz_face: LPCWSTR,
) -> HFONT {
    let mut lf: LOGFONTW = core::mem::zeroed();
    lf.lf_height = n_height;
    lf.lf_width = n_width;
    lf.lf_escapement = n_escapement;
    lf.lf_orientation = n_orientation;
    lf.lf_weight = n_weight;
    lf.lf_italic = fdw_italic as BYTE;
    lf.lf_underline = fdw_underline as BYTE;
    lf.lf_strike_out = fdw_strike_out as BYTE;
    lf.lf_char_set = fdw_char_set as BYTE;
    lf.lf_out_precision = fdw_output_precision as BYTE;
    lf.lf_clip_precision = fdw_clip_precision as BYTE;
    lf.lf_quality = fdw_quality as BYTE;
    lf.lf_pitch_and_family = fdw_pitch_and_family as BYTE;
    if !lpsz_face.is_null() {
        let mut i = 0;
        while i < 31 && *lpsz_face.add(i) != 0 {
            lf.lf_face_name[i] = *lpsz_face.add(i);
            i += 1;
        }
        lf.lf_face_name[i] = 0;
    }
    CreateFontIndirectW(&lf)
}

pub unsafe fn GetTextMetricsW(hdc: HDC, lptm: *mut TEXTMETRICW) -> BOOL {
    if lptm.is_null() { return FALSE; }
    // Return default metrics
    (*lptm).tm_height = 16;
    (*lptm).tm_ascent = 12;
    (*lptm).tm_descent = 4;
    (*lptm).tm_internal_leading = 2;
    (*lptm).tm_external_leading = 0;
    (*lptm).tm_ave_char_width = 8;
    (*lptm).tm_max_char_width = 8;
    (*lptm).tm_weight = 400;
    (*lptm).tm_overhang = 0;
    (*lptm).tm_first_char = 32;
    (*lptm).tm_last_char = 255;
    (*lptm).tm_default_char = 63;
    (*lptm).tm_break_char = 32;
    (*lptm).tm_pitch_and_family = 0x22; // FIXED_PITCH | FF_MODERN
    (*lptm).tm CharSet = 0; // DEFAULT_CHARSET
    TRUE
}

pub unsafe fn GetTextFaceW(hdc: HDC, n_count: i32, lp_face_name: LPWSTR) -> i32 {
    if lp_face_name.is_null() || n_count <= 0 { return 0; }
    let name = "FixedSys\0".encode_utf16().collect::<alloc::vec::Vec<u16>>();
    let copy_len = core::cmp::min(name.len(), n_count as usize);
    for i in 0..copy_len { *lp_face_name.add(i) = name[i]; }
    copy_len as i32
}

// ============================================================
// Text drawing
// ============================================================

pub unsafe fn TextOutW(hdc: HDC, x_start: i32, y_start: i32, lp_string: LPCWSTR, c: i32) -> BOOL {
    if lp_string.is_null() || c <= 0 { return FALSE; }
    TRUE
}

pub unsafe fn TextOutA(hdc: HDC, x_start: i32, y_start: i32, lp_string: LPCSTR, c: i32) -> BOOL {
    if lp_string.is_null() || c <= 0 { return FALSE; }
    TRUE
}

pub unsafe fn ExtTextOutW(
    hdc: HDC, x: i32, y: i32, options: UINT,
    lprect: *const RECT, lp_string: LPCWSTR, c: i32, lp_dx: *const i32,
) -> BOOL {
    TRUE
}

pub unsafe fn DrawTextW(
    hdc: HDC, lp_ch_wch: LPCWSTR, cch_text: i32,
    lprc: *mut RECT, format: UINT,
) -> i32 {
    if lprc.is_null() { return 0; }
    // Return height of drawn text
    16
}

pub unsafe fn DrawTextA(
    hdc: HDC, lp_ch_text: LPCSTR, cch_text: i32,
    lprc: *mut RECT, format: UINT,
) -> i32 {
    if lprc.is_null() { return 0; }
    16
}

pub unsafe fn SetTextColor(hdc: HDC, cr_color: u32) -> u32 {
    0x00000000 // return previous color
}

pub unsafe fn GetTextColor(hdc: HDC) -> u32 {
    0x00000000 // black
}

pub unsafe fn SetBkColor(hdc: HDC, cr_color: u32) -> u32 {
    0x00FFFFFF // return previous (white)
}

pub unsafe fn GetBkColor(hdc: HDC) -> u32 {
    0x00FFFFFF
}

pub unsafe fn SetBkMode(hdc: HDC, i_bk_mode: i32) -> i32 {
    1 // TRANSPARENT
}

pub unsafe fn GetBkMode(hdc: HDC) -> i32 {
    1 // TRANSPARENT
}

pub unsafe fn GetTextExtentPoint32W(hdc: HDC, lp_string: LPCWSTR, c: i32, lpsize: *mut POINT) -> BOOL {
    if lpsize.is_null() { return FALSE; }
    (*lpsize).x = c * 8; // assume 8px per char
    (*lpsize).y = 16;
    TRUE
}

pub unsafe fn GetTextExtentExPointW(
    hdc: HDC, lpsz_str: LPCWSTR, cch_string: i32, n_max_extent: i32,
    lp_fit: *mut i32, lp_dx: *mut i32, lpsize: *mut POINT,
) -> BOOL {
    if !lpsize.is_null() { *lpsize = POINT { x: cch_string * 8, y: 16 }; }
    TRUE
}

// ============================================================
// Shape / Region functions
// ============================================================

pub unsafe fn Rectangle(hdc: HDC, left: i32, top: i32, right: i32, bottom: i32) -> BOOL {
    TRUE
}

pub unsafe fn FillRect(hdc: HDC, lprc: *const RECT, hbr: HBRUSH) -> i32 {
    1 // success
}

pub unsafe fn FrameRect(hdc: HDC, lprc: *const RECT, hbr: HBRUSH) -> i32 {
    1
}

pub unsafe fn Ellipse(hdc: HDC, left: i32, top: i32, right: i32, bottom: i32) -> BOOL {
    TRUE
}

pub unsafe fn RoundRect(hdc: HDC, left: i32, top: i32, right: i32, bottom: i32, x_width: i32, y_height: i32) -> BOOL {
    TRUE
}

pub unsafe fn LineTo(hdc: HDC, x_end: i32, y_end: i32) -> BOOL {
    TRUE
}

pub unsafe fn MoveToEx(hdc: HDC, x: i32, y: i32, lp_point: *mut POINT) -> BOOL {
    if !lp_point.is_null() { *lp_point = POINT { x: 0, y: 0 }; }
    TRUE
}

pub unsafe fn Polygon(hdc: HDC, lp_points: *const POINT, n_count: i32) -> BOOL {
    TRUE
}

pub unsafe fn Polyline(hdc: HDC, lppt: *const POINT, c_points: i32) -> BOOL {
    TRUE
}

pub unsafe fn Arc(hdc: HDC, x1: i32, y1: i32, x2: i32, y2: i32, x3: i32, y3: i32, x4: i32, y4: i32) -> BOOL {
    TRUE
}

pub unsafe fn Pie(hdc: HDC, x1: i32, y1: i32, x2: i32, y2: i32, x3: i32, y3: i32, x4: i32, y4: i32) -> BOOL {
    TRUE
}

pub unsafe fn Chord(hdc: HDC, x1: i32, y1: i32, x2: i32, y2: i32, x3: i32, y3: i32, x4: i32, y4: i32) -> BOOL {
    TRUE
}

// ============================================================
// Region functions
// ============================================================

pub unsafe fn CreateRectRgn(x1: i32, y1: i32, x2: i32, y2: i32) -> HRGN {
    match alloc_mem(core::mem::size_of::<RECT>()) {
        Ok(ptr) => {
            let rc = &mut *(ptr as *mut RECT);
            *rc = RECT { left: x1, top: y1, right: x2, bottom: y2 };
            ptr as HRGN
        }
        Err(_) => core::ptr::null_mut(),
    }
}

pub unsafe fn CreateRoundRectRgn(x1: i32, y1: i32, x2: i32, y2: i32, x3: i32, y4: i32) -> HRGN {
    CreateRectRgn(x1, y1, x2, y2)
}

pub unsafe fn CombineRgn(hdc_rgn_dst: HRGN, hrgn_src1: HRGN, hrgn_src2: HRGN, n_combine_mode: i32) -> i32 {
    1 // SIMPLEREGION
}

pub unsafe fn OffsetRgn(hrgn: HRGN, x: i32, y: i32) -> i32 {
    1
}

pub unsafe fn PtInRegion(hrgn: HRGN, x: i32, y: i32) -> BOOL {
    FALSE
}

pub unsafe fn SelectClipRgn(hdc: HDC, hrgn: HRGN) -> i32 {
    1 // SIMPLEREGION
}

pub unsafe fn IntersectClipRect(hdc: HDC, left: i32, top: i32, right: i32, bottom: i32) -> i32 {
    1
}

// ============================================================
// Mapping mode / coordinate transform
// ============================================================

pub unsafe fn SetMapMode(hdc: HDC, i_mode: i32) -> i32 {
    1 // MM_TEXT
}

pub unsafe fn GetMapMode(hdc: HDC) -> i32 {
    1 // MM_TEXT
}

// ============================================================
// Palette
// ============================================================

pub unsafe fn CreatePalette(lplgna: LPVOID) -> HPALETTE {
    core::ptr::null_mut()
}

pub unsafe fn SelectPalette(hdc: HDC, hpal: HPALETTE, b_force_background: BOOL) -> HPALETTE {
    core::ptr::null_mut()
}

pub unsafe fn RealizePalette(hdc: HDC) -> UINT {
    0
}

// ============================================================
// Clipboard helpers
// ============================================================

pub const CF_TEXT: UINT = 1;
pub const CF_BITMAP: UINT = 2;
pub const CF_UNICODETEXT: UINT = 13;
pub const CF_DIB: UINT = 8;

// ============================================================
// Drawing constants
// ============================================================

// Raster ops
pub const SRCCOPY: u32 = 0x00CC0020;
pub const SRCPAINT: u32 = 0x00EE0886;
pub const SRCAND: u32 = 0x008800C6;
pub const SRCINVERT: u32 = 0x00660046;
pub const SRCERASE: u32 = 0x00440328;
pub const NOTSRCCOPY: u32 = 0x00330008;
pub const NOTSRCERASE: u32 = 0x001100A6;
pub const MERGECOPY: u32 = 0x00C000CA;
pub const MERGEPAINT: u32 = 0x00BB0226;
pub const PATCOPY: u32 = 0x00F00021;
pub const PATPAINT: u32 = 0x00FB0A09;
pub const PATINVERT: u32 = 0x005A0049;
pub const DSTINVERT: u32 = 0x00550009;
pub const BLACKNESS: u32 = 0x00000042;
pub const WHITENESS: u32 = 0x00FF0062;

// Brush styles
pub const BS_SOLID: i32 = 0;
pub const BS_NULL: i32 = 1;
pub const BS_HATCHED: i32 = 2;
pub const BS_HOLLOW: i32 = 1;

// Hatch styles
pub const HS_HORIZONTAL: i32 = 0;
pub const HS_VERTICAL: i32 = 1;
pub const HS_FDIAGONAL: i32 = 2;
pub const HS_BDIAGONAL: i32 = 3;
pub const HS_CROSS: i32 = 4;
pub const HS_DIAGCROSS: i32 = 5;

// Pen styles
pub const PS_SOLID: i32 = 0;
pub const PS_DASH: i32 = 1;
pub const PS_DOT: i32 = 2;
pub const PS_DASHDOT: i32 = 3;
pub const PS_DASHDOTDOT: i32 = 4;
pub const PS_NULL: i32 = 5;

// Font weights
pub const FW_DONTCARE: i32 = 0;
pub const FW_THIN: i32 = 100;
pub const FW_EXTRALIGHT: i32 = 200;
pub const FW_LIGHT: i32 = 300;
pub const FW_NORMAL: i32 = 400;
pub const FW_MEDIUM: i32 = 500;
pub const FW_SEMIBOLD: i32 = 600;
pub const FW_BOLD: i32 = 700;
pub const FW_EXTRABOLD: i32 = 800;
pub const FW_HEAVY: i32 = 900;

// DrawText format
pub const DT_LEFT: UINT = 0x00000000;
pub const DT_TOP: UINT = 0x00000000;
pub const DT_CENTER: UINT = 0x00000001;
pub const DT_RIGHT: UINT = 0x00000002;
pub const DT_VCENTER: UINT = 0x00000004;
pub const DT_BOTTOM: UINT = 0x00000008;
pub const DT_WORDBREAK: UINT = 0x00000010;
pub const DT_SINGLELINE: UINT = 0x00000020;
pub const DT_TABSTOP: UINT = 0x00000080;
pub const DT_CALCRECT: UINT = 0x00000400;
pub const DT_NOCLIP: UINT = 0x00000100;

// GDI object types
pub const OBJ_PEN: i32 = 1;
pub const OBJ_BRUSH: i32 = 2;
pub const OBJ_DC: i32 = 3;
pub const OBJ_BITMAP: i32 = 5;
pub const OBJ_FONT: i32 = 6;
pub const OBJ_REGION: i32 = 8;

// ANSI charset
pub const ANSI_CHARSET: u8 = 0;
pub const DEFAULT_CHARSET: u8 = 1;
pub const SYMBOL_CHARSET: u8 = 2;
pub const OEM_CHARSET: u8 = 255;
