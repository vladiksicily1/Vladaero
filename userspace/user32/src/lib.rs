#![no_std]
#![allow(non_snake_case)]
#![allow(non_camel_case_types)]

extern crate alloc;

use core::result::Result::{Ok, Err};
use ntdll::*;

// ============================================================
// Types
// ============================================================

pub type HWND = HANDLE;
pub type HINSTANCE = HANDLE;
pub type HMODULE = HANDLE;
pub type HICON = HANDLE;
pub type HCURSOR = HANDLE;
pub type HBRUSH = HANDLE;
pub type HFONT = HANDLE;
pub type HMENU = HANDLE;
pub type HDC = HANDLE;
pub type HBITMAP = HANDLE;
pub type HGDIOBJ = HANDLE;
pub type HPALETTE = HANDLE;
pub type HRGN = HANDLE;
pub type HACCEL = HANDLE;
pub type HMONITOR = HANDLE;

pub type DWORD = u32;
pub type WORD = u16;
pub type BYTE = u8;
pub type BOOL = i32;
pub type LONG = i32;
pub type UINT = u32;
pub type WPARAM = usize;
pub type LPARAM = isize;
pub type LRESULT = isize;
pub type LPSTR = *mut u8;
pub type LPCSTR = *const u8;
pub type LPWSTR = *mut u16;
pub type LPCWSTR = *const u16;
pub type LPVOID = *mut core::ffi::c_void;

pub const TRUE: BOOL = 1;
pub const FALSE: BOOL = 0;
pub const NULL: usize = 0;

// Window styles
pub const WS_OVERLAPPED: DWORD = 0x00000000;
pub const WS_POPUP: DWORD = 0x80000000;
pub const WS_CHILD: DWORD = 0x40000000;
pub const WS_VISIBLE: DWORD = 0x10000000;
pub const WS_DISABLED: DWORD = 0x08000000;
pub const WS_MINIMIZE: DWORD = 0x20000000;
pub const WS_MAXIMIZE: DWORD = 0x01000000;
pub const WS_CAPTION: DWORD = 0x00C00000;
pub const WS_BORDER: DWORD = 0x00800000;
pub const WS_SYSMENU: DWORD = 0x00080000;
pub const WS_THICKFRAME: DWORD = 0x00040000;
pub const WS_MINIMIZEBOX: DWORD = 0x00020000;
pub const WS_MAXIMIZEBOX: DWORD = 0x00010000;
pub const WS_OVERLAPPEDWINDOW: DWORD = WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU | WS_THICKFRAME | WS_MINIMIZEBOX | WS_MAXIMIZEBOX;
pub const WS_POPUPWINDOW: DWORD = WS_POPUP | WS_BORDER | WS_SYSMENU;

// Window extended styles
pub const WS_EX_CLIENTEDGE: DWORD = 0x00000200;
pub const WS_EX_APPWINDOW: DWORD = 0x00040000;
pub const WS_EX_TOPMOST: DWORD = 0x00000008;

// Show window commands
pub const SW_HIDE: i32 = 0;
pub const SW_SHOWNORMAL: i32 = 1;
pub const SW_SHOWMINIMIZED: i32 = 2;
pub const SW_SHOWMAXIMIZED: i32 = 3;
pub const SW_SHOWNOACTIVATE: i32 = 4;
pub const SW_SHOW: i32 = 5;
pub const SW_MINIMIZE: i32 = 6;
pub const SW_RESTORE: i32 = 9;

// Window messages
pub const WM_NULL: UINT = 0x0000;
pub const WM_CREATE: UINT = 0x0001;
pub const WM_DESTROY: UINT = 0x0002;
pub const WM_MOVE: UINT = 0x0003;
pub const WM_SIZE: UINT = 0x0005;
pub const WM_ACTIVATE: UINT = 0x0006;
pub const WM_SETFOCUS: UINT = 0x0007;
pub const WM_KILLFOCUS: UINT = 0x0008;
pub const WM_ENABLE: UINT = 0x000A;
pub const WM_PAINT: UINT = 0x000F;
pub const WM_CLOSE: UINT = 0x0010;
pub const WM_QUIT: UINT = 0x0012;
pub const WM_ERASEBKGND: UINT = 0x0014;
pub const WM_SHOWWINDOW: UINT = 0x0018;
pub const WM_ACTIVATEAPP: UINT = 0x001C;
pub const WM_SETCURSOR: UINT = 0x0020;
pub const WM_MOUSEACTIVATE: UINT = 0x0021;
pub const WM_GETMINMAXINFO: UINT = 0x0024;
pub const WM_PAINTICON: UINT = 0x0026;
pub const WM_ICONERASEBKGND: UINT = 0x0027;
pub const WM_NEXTDLGCTL: UINT = 0x0028;
pub const WM_SPOOLERSTATUS: UINT = 0x002A;
pub const WM_DRAWITEM: UINT = 0x002B;
pub const WM_MEASUREITEM: UINT = 0x002C;
pub const WM_DELETEITEM: UINT = 0x002D;
pub const WM_VKEYTOITEM: UINT = 0x002E;
pub const WM_CHARTOITEM: UINT = 0x002F;
pub const WM_SETFONT: UINT = 0x0030;
pub const WM_GETFONT: UINT = 0x0031;
pub const WM_SETHOTKEY: UINT = 0x0032;
pub const WM_GETHOTKEY: UINT = 0x0033;
pub const WM_QUERYDRAGICON: UINT = 0x0037;
pub const WM_COMPAREITEM: UINT = 0x0039;
pub const WM_GETOBJECT: UINT = 0x003D;
pub const WM_COMPACTING: UINT = 0x0041;
pub const WM_COMMNOTIFY: UINT = 0x0044;
pub const WM_WINDOWPOSCHANGING: UINT = 0x0046;
pub const WM_WINDOWPOSCHANGED: UINT = 0x0047;
pub const WM_POWER: UINT = 0x0048;
pub const WM_COPYDATA: UINT = 0x004A;
pub const WM_CANCELJOURNAL: UINT = 0x004B;
pub const WM_INPUTLANGCHANGEREQUEST: UINT = 0x0050;
pub const WM_INPUTLANGCHANGE: UINT = 0x0051;
pub const WM_TCARD: UINT = 0x0052;
pub const WM_HELP: UINT = 0x0053;
pub const WM_USERCHANGED: UINT = 0x0054;
pub const WM_NOTIFY: UINT = 0x004E;
pub const WM_STYLECHANGED: UINT = 0x007D;
pub const WM_SETMESSAGESTRING: UINT = 0x0320;
pub const WM_ENTERMENULOOP: UINT = 0x0211;
pub const WM_EXITMENULOOP: UINT = 0x0212;

// Keyboard messages
pub const WM_KEYDOWN: UINT = 0x0100;
pub const WM_KEYUP: UINT = 0x0101;
pub const WM_CHAR: UINT = 0x0102;
pub const WM_DEADCHAR: UINT = 0x0103;
pub const WM_SYSKEYDOWN: UINT = 0x0104;
pub const WM_SYSKEYUP: UINT = 0x0105;
pub const WM_SYSCHAR: UINT = 0x0106;

// Mouse messages
pub const WM_MOUSEMOVE: UINT = 0x0200;
pub const WM_LBUTTONDOWN: UINT = 0x0201;
pub const WM_LBUTTONUP: UINT = 0x0202;
pub const WM_LBUTTONDBLCLK: UINT = 0x0203;
pub const WM_RBUTTONDOWN: UINT = 0x0204;
pub const WM_RBUTTONUP: UINT = 0x0205;
pub const WM_RBUTTONDBLCLK: UINT = 0x0206;

// Non-client messages
pub const WM_NCCALCSIZE: UINT = 0x0083;
pub const WM_NCPAINT: UINT = 0x0085;
pub const WM_NCACTIVATE: UINT = 0x0086;
pub const WM_NCLBUTTONDOWN: UINT = 0x00A1;
pub const WM_NCRBUTTONDOWN: UINT = 0x00A4;

// Timer
pub const WM_TIMER: UINT = 0x0113;
pub const WM_SYSTIMER: UINT = 0x0118;

// Command
pub const WM_COMMAND: UINT = 0x0111;
pub const WM_SYSCOMMAND: UINT = 0x0112;

// Clipboard
pub const WM_SETTEXT: UINT = 0x000C;
pub const WM_GETTEXT: UINT = 0x000D;
pub const WM_GETTEXTLENGTH: UINT = 0x000E;

// Paint structure
#[repr(C)]
pub struct PAINTSTRUCT {
    pub hdc: HDC,
    pub f_erase: BOOL,
    pub rc_paint: RECT,
    pub f_restore: BOOL,
    pub f_inc_update: BOOL,
    pub rgb_reserved: [BYTE; 32],
}

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
pub struct MSG {
    pub hwnd: HWND,
    pub message: UINT,
    pub w_param: WPARAM,
    pub l_param: LPARAM,
    pub time: DWORD,
    pub pt: POINT,
}

#[repr(C)]
pub struct WNDCLASSW {
    pub style: UINT,
    pub lpfn_wnd_proc: WNDPROC,
    pub cb_cls_extra: i32,
    pub cb_wnd_extra: i32,
    pub h_instance: HINSTANCE,
    pub h_icon: HICON,
    pub h_cursor: HCURSOR,
    pub hbr_background: HBRUSH,
    pub lpsz_menu_name: LPCWSTR,
    pub lpsz_class_name: LPCWSTR,
}

#[repr(C)]
pub struct CREATESTRUCTW {
    pub lp_create_params: LPVOID,
    pub h_instance: HINSTANCE,
    pub h_menu: HMENU,
    pub hwnd_parent: HWND,
    pub cy: i32,
    pub cx: i32,
    pub y: i32,
    pub x: i32,
    pub style: LONG,
    pub lpsz_name: LPCWSTR,
    pub lpsz_class: LPCWSTR,
    pub dw_ex_style: DWORD,
}

#[repr(C)]
pub struct MINMAXINFO {
    pub pt_reserved: POINT,
    pub pt_max_size: POINT,
    pub pt_max_position: POINT,
    pub pt_max_track_size: POINT,
    pub pt_min_track_size: POINT,
}

#[repr(C)]
pub struct WINDOWPLACEMENT {
    pub length: UINT,
    pub flags: UINT,
    pub show_cmd: UINT,
    pub pt_min_position: POINT,
    pub pt_max_position: POINT,
    pub rc_normal_position: RECT,
}

pub type WNDPROC = unsafe extern "system" fn(HWND, UINT, WPARAM, LPARAM) -> LRESULT;

// ============================================================
// Window Management
// ============================================================

pub unsafe fn RegisterClassExW(lp_wnd_class: *const WNDCLASSW) -> WORD {
    // Register window class — store in a simple table
    if lp_wnd_class.is_null() { return 0; }
    let wc = &*lp_wnd_class;
    // Return class atom (non-zero on success)
    // In a real implementation this would register the class
    // For now, return a non-zero atom
    1
}

pub unsafe fn CreateWindowExW(
    dw_ex_style: DWORD,
    lp_class_name: LPCWSTR,
    lp_window_name: LPCWSTR,
    dw_style: DWORD,
    x: i32, y: i32,
    n_width: i32, n_height: i32,
    h_wnd_parent: HWND,
    h_menu: HMENU,
    h_instance: HINSTANCE,
    lp_param: LPVOID,
) -> HWND {
    // Create a window object — allocate and return handle
    // In a real implementation this would create a window structure
    // For now, return a non-null handle
    let hwnd: HWND = alloc_mem(core::mem::size_of::<usize>())
        .unwrap_or(core::ptr::null_mut());
    if !hwnd.is_null() {
        // Store window info at this address
        // Simplified: just return the pointer as handle
    }
    hwnd
}

pub unsafe fn DestroyWindow(hwnd: HWND) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    // Send WM_DESTROY, WM_NCDESTROY
    free_mem(hwnd as LPVOID, core::mem::size_of::<usize>()).ok();
    TRUE
}

pub unsafe fn ShowWindow(hwnd: HWND, n_cmd_show: i32) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    // Send WM_SHOWWINDOW
    TRUE
}

pub unsafe fn UpdateWindow(hwnd: HWND) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    // Send WM_PAINT
    TRUE
}

pub unsafe fn SetWindowPos(
    hwnd: HWND, hwnd_insert_after: HWND,
    x: i32, y: i32, cx: i32, cy: i32, u_flags: UINT,
) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn MoveWindow(
    hwnd: HWND, x: i32, y: i32, n_width: i32, n_height: i32, b_repaint: BOOL,
) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn GetWindowRect(hwnd: HWND, lp_rect: *mut RECT) -> BOOL {
    if hwnd.is_null() || lp_rect.is_null() { return FALSE; }
    (*lp_rect) = RECT { left: 0, top: 0, right: 800, bottom: 600 };
    TRUE
}

pub unsafe fn GetClientRect(hwnd: HWND, lp_rect: *mut RECT) -> BOOL {
    if hwnd.is_null() || lp_rect.is_null() { return FALSE; }
    (*lp_rect) = RECT { left: 0, top: 0, right: 800, bottom: 600 };
    TRUE
}

pub unsafe fn SetWindowTextW(hwnd: HWND, lp_string: LPCWSTR) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    // Send WM_SETTEXT
    TRUE
}

pub unsafe fn GetWindowTextW(hwnd: HWND, lp_string: LPWSTR, n_max_count: i32) -> i32 {
    if hwnd.is_null() || lp_string.is_null() || n_max_count <= 0 { return 0; }
    0
}

pub unsafe fn GetWindowTextLengthW(hwnd: HWND) -> i32 {
    if hwnd.is_null() { return 0; }
    0
}

pub unsafe fn IsWindow(hwnd: HWND) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn IsWindowVisible(hwnd: HWND) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn EnableWindow(hwnd: HWND, b_enable: BOOL) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn IsWindowEnabled(hwnd: HWND) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn SetFocus(hwnd: HWND) -> HWND {
    hwnd
}

pub unsafe fn GetFocus() -> HWND {
    core::ptr::null_mut()
}

pub unsafe fn GetForegroundWindow() -> HWND {
    core::ptr::null_mut()
}

pub unsafe fn SetForegroundWindow(hwnd: HWND) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn BringWindowToTop(hwnd: HWND) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn SetWindowPlacement(hwnd: HWND, lpwndpl: *const WINDOWPLACEMENT) -> BOOL {
    if hwnd.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn GetWindowPlacement(hwnd: HWND, lpwndpl: *mut WINDOWPLACEMENT) -> BOOL {
    if hwnd.is_null() || lpwndpl.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn GetParent(hwnd: HWND) -> HWND {
    core::ptr::null_mut()
}

pub unsafe fn SetParent(hwnd_child: HWND, hwnd_new_parent: HWND) -> HWND {
    core::ptr::null_mut()
}

pub unsafe fn FindWindowW(lp_class_name: LPCWSTR, lp_window_name: LPCWSTR) -> HWND {
    core::ptr::null_mut()
}

pub unsafe fn EnumWindows(lp_enum_func: usize, l_param: LPARAM) -> BOOL {
    TRUE
}

// ============================================================
// Message Loop
// ============================================================

pub unsafe fn GetMessageW(
    lp_msg: *mut MSG,
    hwnd: HWND,
    w_msg_filter_min: UINT,
    w_msg_filter_max: UINT,
) -> BOOL {
    if lp_msg.is_null() { return -1; }
    // Wait for next message — block until WM_QUIT or message available
    // For now, yield and return FALSE to exit
    ntdll::sleep(10);
    (*lp_msg).message = WM_NULL;
    TRUE
}

pub unsafe fn PeekMessageW(
    lp_msg: *mut MSG,
    hwnd: HWND,
    w_msg_filter_min: UINT,
    w_msg_filter_max: UINT,
    w_remove_msg: UINT,
) -> BOOL {
    if lp_msg.is_null() { return FALSE; }
    FALSE // no message
}

pub unsafe fn TranslateMessage(lp_msg: *const MSG) -> BOOL {
    TRUE
}

pub unsafe fn DispatchMessageW(lp_msg: *const MSG) -> LRESULT {
    if lp_msg.is_null() { return 0; }
    // Call window procedure
    0
}

pub unsafe fn PostQuitMessage(n_exit_code: i32) {
    // Post WM_QUIT
}

pub unsafe fn PostMessageW(hwnd: HWND, msg: UINT, w_param: WPARAM, l_param: LPARAM) -> BOOL {
    TRUE
}

pub unsafe fn SendMessageW(hwnd: HWND, msg: UINT, w_param: WPARAM, l_param: LPARAM) -> LRESULT {
    0
}

pub unsafe fn DefWindowProcW(hwnd: HWND, msg: UINT, w_param: WPARAM, l_param: LPARAM) -> LRESULT {
    match msg {
        WM_DESTROY => { PostQuitMessage(0); 0 }
        WM_CLOSE => { DestroyWindow(hwnd); 0 }
        WM_PAINT => {
            let mut ps: PAINTSTRUCT = core::mem::zeroed();
            BeginPaint(hwnd, &mut ps);
            EndPaint(hwnd, &ps);
            0
        }
        WM_ERASEBKGND => 1,
        WM_GETMINMAXINFO => {
            if l_param != 0 {
                let mmi = &mut *(l_param as *mut MINMAXINFO);
                mmi.pt_min_track_size = POINT { x: 200, y: 100 };
            }
            0
        }
        _ => 0,
    }
}

// ============================================================
// Device Context
// ============================================================

pub unsafe fn BeginPaint(hwnd: HWND, lp_paint: *mut PAINTSTRUCT) -> HDC {
    if hwnd.is_null() || lp_paint.is_null() { return core::ptr::null_mut(); }
    (*lp_paint).hdc = core::ptr::null_mut(); // GetDC(hwnd)
    (*lp_paint).f_erase = TRUE;
    (*lp_paint).rc_paint = RECT { left: 0, top: 0, right: 800, bottom: 600 };
    (*lp_paint).f_restore = FALSE;
    (*lp_paint).f_inc_update = FALSE;
    core::ptr::write_bytes((*lp_paint).rgb_reserved.as_mut_ptr(), 0, 32);
    (*lp_paint).hdc
}

pub unsafe fn EndPaint(hwnd: HWND, lp_paint: *const PAINTSTRUCT) -> BOOL {
    if hwnd.is_null() || lp_paint.is_null() { return FALSE; }
    TRUE
}

pub unsafe fn GetDC(hwnd: HWND) -> HDC {
    // Return screen DC or window DC
    // Simplified: return a non-null handle
    1isize as HDC
}

pub unsafe fn GetWindowDC(hwnd: HWND) -> HDC {
    1isize as HDC
}

pub unsafe fn ReleaseDC(hwnd: HWND, hdc: HDC) -> i32 {
    1
}

pub unsafe fn GetDesktopWindow() -> HWND {
    1isize as HWND
}

pub unsafe fn GetSystemMetrics(n_index: i32) -> i32 {
    match n_index {
        0 => 0,     // SM_CXSCREEN
        1 => 768,   // SM_CYSCREEN
        2 => 0,     // SM_CXVSCROLL
        3 => 0,     // SM_CYHSCROLL
        4 => 0,     // SM_CYCAPTION
        5 => 0,     // SM_CXBORDER
        6 => 0,     // SM_CYBORDER
        7 => 16,    // SM_CXDLGFRAME
        8 => 16,    // SM_CYDLGFRAME
        11 => 0,    // SM_MOUSEWHEELPRESENT
        13 => 1024, // SM_CXSCREEN
        14 => 768,  // SM_CYSCREEN
        15 => 1024, // SM_CXFULLSCREEN
        16 => 768,  // SM_CYFULLSCREEN
        _ => 0,
    }
}

// ============================================================
// Cursor & Icon
// ============================================================

pub unsafe fn LoadCursorW(h_instance: HINSTANCE, lp_cursor_name: LPCWSTR) -> HCURSOR {
    1isize as HCURSOR // IDC_ARROW
}

pub unsafe fn LoadIconW(h_instance: HINSTANCE, lp_icon_name: LPCWSTR) -> HICON {
    1isize as HICON // IDI_APPLICATION
}

pub unsafe fn LoadImageW(
    h_inst: HINSTANCE, name: LPCWSTR, _type: UINT,
    cx: i32, cy: i32, fu_load: UINT,
) -> HANDLE {
    1isize as HANDLE
}

pub unsafe fn SetCursorPos(x: i32, y: i32) -> BOOL {
    TRUE
}

pub unsafe fn GetCursorPos(lp_point: *mut POINT) -> BOOL {
    if lp_point.is_null() { return FALSE; }
    (*lp_point) = POINT { x: 0, y: 0 };
    TRUE
}

pub unsafe fn SetCursor(h_cursor: HCURSOR) -> HCURSOR {
    h_cursor
}

pub unsafe fn ShowCursor(b_show: BOOL) -> i32 {
    0
}

pub unsafe fn ClipCursor(lp_rect: *const RECT) -> BOOL {
    TRUE
}

// ============================================================
// Menu
// ============================================================

pub unsafe fn CreateMenu() -> HMENU {
    1isize as HMENU
}

pub unsafe fn CreatePopupMenu() -> HMENU {
    2isize as HMENU
}

pub unsafe fn AppendMenuW(h_menu: HMENU, u_flags: UINT, u_id_new_item: usize, lp_new_item: LPCWSTR) -> BOOL {
    TRUE
}

pub unsafe fn DestroyMenu(h_menu: HMENU) -> BOOL {
    TRUE
}

pub unsafe fn TrackPopupMenu(h_menu: HMENU, u_flags: UINT, x: i32, y: i32, n_reserved: i32, hwnd: HWND, prc: *const RECT) -> BOOL {
    TRUE
}

pub unsafe fn SetMenu(hwnd: HWND, h_menu: HMENU) -> BOOL {
    TRUE
}

// ============================================================
// Dialog
// ============================================================

pub unsafe fn MessageBoxW(hwnd: HWND, lp_text: LPCWSTR, lp_caption: LPCWSTR, u_type: UINT) -> i32 {
    1 // IDOK
}

pub unsafe fn MessageBoxA(hwnd: HWND, lp_text: LPCSTR, lp_caption: LPCSTR, u_type: UINT) -> i32 {
    1
}

pub unsafe fn DialogBoxParamW(
    h_instance: HINSTANCE, lp_template: LPCWSTR,
    hwnd_parent: HWND, lp_dialog_func: usize, dw_init_param: LPARAM,
) -> isize {
    -1 // IDCANCEL
}

pub unsafe fn EndDialog(h_dlg: HWND, n_result: isize) -> BOOL {
    TRUE
}

pub unsafe fn GetDlgItem(h_dlg: HWND, n_id: i32) -> HWND {
    core::ptr::null_mut()
}

pub unsafe fn SetDlgItemTextW(h_dlg: HWND, n_id: i32, lp_string: LPCWSTR) -> BOOL {
    TRUE
}

pub unsafe fn GetDlgItemTextW(h_dlg: HWND, n_id: i32, lp_string: LPWSTR, n_max_count: i32) -> i32 {
    0
}

// ============================================================
// Clipboard
// ============================================================

pub unsafe fn OpenClipboard(hwnd_new_owner: HWND) -> BOOL {
    TRUE
}

pub unsafe fn CloseClipboard() -> BOOL {
    TRUE
}

pub unsafe fn GetClipboardData(u_format: UINT) -> HANDLE {
    core::ptr::null_mut()
}

pub unsafe fn SetClipboardData(u_format: UINT, h_mem: HANDLE) -> HANDLE {
    core::ptr::null_mut()
}

pub unsafe fn EmptyClipboard() -> BOOL {
    TRUE
}

pub unsafe fn IsClipboardFormatAvailable(format: UINT) -> BOOL {
    FALSE
}

// ============================================================
// Timer
// ============================================================

pub unsafe fn SetTimer(hwnd: HWND, n_id_event: usize, u_elapse: UINT, lp_timer_func: usize) -> usize {
    n_id_event
}

pub unsafe fn KillTimer(hwnd: HWND, n_id_event: usize) -> BOOL {
    TRUE
}

// ============================================================
// Misc
// ============================================================

pub unsafe fn MessageBeep(u_type: UINT) -> BOOL {
    TRUE
}

pub unsafe fn GetKeyState(n_v_key: i32) -> i16 {
    0
}

pub unsafe fn GetAsyncKeyState(v_key: i32) -> i16 {
    0
}

pub unsafe fn MapVirtualKeyW(u_code: UINT, u_map_type: UINT) -> UINT {
    u_code
}

pub unsafe fn GetKeyboardLayout(id_thread: DWORD) -> HANDLE {
    core::ptr::null_mut()
}

pub unsafe fn ToUnicode(
    w Virt_key: UINT, w_scan_code: UINT,
    lp_key_state: *const BYTE, pwsz_buff: LPWSTR,
    cch_buff: i32, w_flags: UINT,
) -> i32 {
    0
}

pub unsafe fn GetKeyboardState(lp_key_state: *mut BYTE) -> BOOL {
    TRUE
}

pub unsafe fn wsprintfW(lp_out: LPWSTR, lp_fmt: LPCWSTR, ...) -> i32 {
    0
}

pub unsafe fn wsprintfA(lp_out: LPSTR, lp_fmt: LPCSTR, ...) -> i32 {
    0
}
