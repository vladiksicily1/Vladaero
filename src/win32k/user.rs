/// Win32k USER - window manager, messages, input, stations/desktops
///
/// tagWND objects, USER handle table, window classes (CLS/FNID),
/// per-thread message queues, WinSta0 + desktops, keyboard/mouse
/// state, focus/capture, menus, timers.

use core::ffi::c_void;

use crate::types::*;
use super::{win32k_register_service, Win32kServiceHandler};

// ============================================================
// Window messages (WM_*)
// ============================================================

pub const WM_NULL: u32 = 0x0000;
pub const WM_CREATE: u32 = 0x0001;
pub const WM_DESTROY: u32 = 0x0002;
pub const WM_MOVE: u32 = 0x0003;
pub const WM_SIZE: u32 = 0x0005;
pub const WM_ACTIVATE: u32 = 0x0006;
pub const WM_SETFOCUS: u32 = 0x0007;
pub const WM_KILLFOCUS: u32 = 0x0008;
pub const WM_ENABLE: u32 = 0x000A;
pub const WM_PAINT: u32 = 0x000F;
pub const WM_CLOSE: u32 = 0x0010;
pub const WM_QUIT: u32 = 0x0012;
pub const WM_ERASEBKGND: u32 = 0x0014;
pub const WM_SHOWWINDOW: u32 = 0x0018;
pub const WM_SETCURSOR: u32 = 0x0020;
pub const WM_MOUSEMOVE: u32 = 0x0200;
pub const WM_LBUTTONDOWN: u32 = 0x0201;
pub const WM_LBUTTONUP: u32 = 0x0202;
pub const WM_RBUTTONDOWN: u32 = 0x0204;
pub const WM_RBUTTONUP: u32 = 0x0205;
pub const WM_MBUTTONDOWN: u32 = 0x0207;
pub const WM_MBUTTONUP: u32 = 0x0208;
pub const WM_MOUSEWHEEL: u32 = 0x020A;
pub const WM_KEYDOWN: u32 = 0x0100;
pub const WM_KEYUP: u32 = 0x0101;
pub const WM_CHAR: u32 = 0x0102;
pub const WM_SYSKEYDOWN: u32 = 0x0104;
pub const WM_SYSKEYUP: u32 = 0x0105;
pub const WM_TIMER: u32 = 0x0113;
pub const WM_COMMAND: u32 = 0x0111;
pub const WM_SYSCOMMAND: u32 = 0x0112;
pub const WM_NCPAINT: u32 = 0x0085;
pub const WM_NCACTIVATE: u32 = 0x0086;
pub const WM_GETTEXT: u32 = 0x000D;
pub const WM_SETTEXT: u32 = 0x000C;
pub const WM_WINDOWPOSCHANGED: u32 = 0x0047;
pub const WM_DISPLAYCHANGE: u32 = 0x007E;
pub const WM_DWMCOMPOSITIONCHANGED: u32 = 0x031E;

// Window styles.
pub const WS_OVERLAPPED: u32 = 0x00000000;
pub const WS_POPUP: u32 = 0x80000000;
pub const WS_CHILD: u32 = 0x40000000;
pub const WS_VISIBLE: u32 = 0x10000000;
pub const WS_DISABLED: u32 = 0x08000000;
pub const WS_CAPTION: u32 = 0x00C00000;
pub const WS_BORDER: u32 = 0x00800000;
pub const WS_THICKFRAME: u32 = 0x00040000;
pub const WS_SYSMENU: u32 = 0x00080000;
pub const WS_MINIMIZEBOX: u32 = 0x00020000;
pub const WS_MAXIMIZEBOX: u32 = 0x00010000;
pub const WS_MINIMIZE: u32 = 0x20000000;
pub const WS_MAXIMIZE: u32 = 0x01000000;
pub const WS_EX_TOPMOST: u32 = 0x00000008;
pub const WS_EX_TOOLWINDOW: u32 = 0x00000080;
pub const WS_EX_APPWINDOW: u32 = 0x00040000;
pub const WS_EX_LAYERED: u32 = 0x00080000;

pub const SW_HIDE: u32 = 0;
pub const SW_SHOW: u32 = 5;
pub const SW_MINIMIZE: u32 = 6;
pub const SW_RESTORE: u32 = 9;
pub const SW_MAXIMIZE: u32 = 3;

pub const HWND_DESKTOP: u64 = 0;
pub const HWND_TOP: u64 = 0;
pub const HWND_BOTTOM: u64 = 1;
pub const HWND_TOPMOST: u64 = 0xFFFFFFFF_FFFFFFFF;
pub const HWND_NOTOPMOST: u64 = 0xFFFFFFFF_FFFFFFFE;

// FNID (fast window-proc dispatch ids).
pub const FNID_DESKTOP: u32 = 0x029A;
pub const FNID_BUTTON: u32 = 0x02A1;
pub const FNID_EDIT: u32 = 0x02A5;
pub const FNID_STATIC: u32 = 0x02A4;
pub const FNID_LISTBOX: u32 = 0x02A6;
pub const FNID_SCROLLBAR: u32 = 0x02A3;
pub const FNID_COMBOBOX: u32 = 0x02A2;

// ============================================================
// USER handle table (HWND/MENU/HOOK, 10k quota per process)
// ============================================================

pub const USER_HANDLE_TYPE_FREE: u8 = 0;
pub const USER_HANDLE_TYPE_WINDOW: u8 = 1;
pub const USER_HANDLE_TYPE_MENU: u8 = 2;
pub const USER_HANDLE_TYPE_CURSOR: u8 = 3;
pub const USER_HANDLE_TYPE_HOOK: u8 = 4;
pub const USER_HANDLE_TYPE_ACCEL: u8 = 5;

pub const USER_HANDLE_TABLE_SIZE: usize = 65536;
pub const USER_HANDLE_QUOTA_PER_PROCESS: u32 = 10000;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UserHandleEntry {
    pub object: *mut c_void,
    pub handle_type: u8,
    pub generation: u16,
    pub process_id: u64,
    pub flags: u8,
}

static mut USER_HANDLE_TABLE: [UserHandleEntry; USER_HANDLE_TABLE_SIZE] =
    [UserHandleEntry {
        object: core::ptr::null_mut(),
        handle_type: USER_HANDLE_TYPE_FREE,
        generation: 0,
        process_id: 0,
        flags: 0,
    }; USER_HANDLE_TABLE_SIZE];
static mut USER_HANDLE_USED: usize = 0;

unsafe fn user_alloc_handle(
    object: *mut c_void,
    handle_type: u8,
    process_id: u64,
) -> u64 {
    // Quota check.
    let mut owned = 0u32;
    let mut i = 1usize;
    while i < USER_HANDLE_TABLE_SIZE {
        if USER_HANDLE_TABLE[i].handle_type != USER_HANDLE_TYPE_FREE
            && USER_HANDLE_TABLE[i].process_id == process_id
        {
            owned += 1;
        }
        i += 1;
    }
    if owned >= USER_HANDLE_QUOTA_PER_PROCESS {
        return 0;
    }
    i = 1;
    while i < USER_HANDLE_TABLE_SIZE {
        if USER_HANDLE_TABLE[i].handle_type == USER_HANDLE_TYPE_FREE {
            USER_HANDLE_TABLE[i].object = object;
            USER_HANDLE_TABLE[i].handle_type = handle_type;
            USER_HANDLE_TABLE[i].process_id = process_id;
            USER_HANDLE_TABLE[i].generation =
                USER_HANDLE_TABLE[i].generation.wrapping_add(1);
            if USER_HANDLE_TABLE[i].generation == 0 {
                USER_HANDLE_TABLE[i].generation = 1;
            }
            USER_HANDLE_USED += 1;
            // HWND = index | (generation << 24) | type tag.
            return (i as u64)
                | ((USER_HANDLE_TABLE[i].generation as u64) << 24)
                | ((handle_type as u64) << 48);
        }
        i += 1;
    }
    0
}

unsafe fn user_free_handle(handle: u64) {
    let idx = (handle & 0xFFFF) as usize;
    if idx == 0 || idx >= USER_HANDLE_TABLE_SIZE {
        return;
    }
    if USER_HANDLE_TABLE[idx].handle_type != USER_HANDLE_TYPE_FREE {
        USER_HANDLE_TABLE[idx].handle_type = USER_HANDLE_TYPE_FREE;
        USER_HANDLE_TABLE[idx].object = core::ptr::null_mut();
        USER_HANDLE_USED -= 1;
    }
}

pub unsafe fn user_lookup_handle(handle: u64, expect_type: u8) -> *mut c_void {
    let idx = (handle & 0xFFFF) as usize;
    if idx == 0 || idx >= USER_HANDLE_TABLE_SIZE {
        return core::ptr::null_mut();
    }
    let e = &USER_HANDLE_TABLE[idx];
    if e.handle_type != expect_type {
        return core::ptr::null_mut();
    }
    let gen = ((handle >> 24) & 0xFFFF) as u16;
    if e.generation != gen {
        return core::ptr::null_mut();
    }
    e.object
}

// ============================================================
// Rectangles
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct WinRect {
    pub left: i32,
    pub top: i32,
    pub right: i32,
    pub bottom: i32,
}

impl WinRect {
    pub const fn new() -> Self {
        Self {
            left: 0,
            top: 0,
            right: 0,
            bottom: 0,
        }
    }
    pub fn width(&self) -> i32 {
        self.right - self.left
    }
    pub fn height(&self) -> i32 {
        self.bottom - self.top
    }
    pub fn contains(&self, x: i32, y: i32) -> bool {
        x >= self.left && x < self.right && y >= self.top && y < self.bottom
    }
    pub fn intersect(&self, other: &WinRect) -> WinRect {
        WinRect {
            left: self.left.max(other.left),
            top: self.top.max(other.top),
            right: self.right.min(other.right),
            bottom: self.bottom.min(other.bottom),
        }
    }
    pub fn is_empty(&self) -> bool {
        self.right <= self.left || self.bottom <= self.top
    }
}

// ============================================================
// Window classes (CLS)
// ============================================================

pub const USER_MAX_CLASSES: usize = 1024;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct WindowClass {
    pub atom: u32,
    pub class_name: [u16; 64],
    pub style: u32,
    pub wnd_proc: u64,
    pub cls_extra: i32,
    pub wnd_extra: i32,
    pub instance: u64,
    pub fnid: u32,
    pub ref_count: u32,
    pub registered: bool,
}

static mut USER_CLASSES: [WindowClass; USER_MAX_CLASSES] = [WindowClass {
    atom: 0,
    class_name: [0; 64],
    style: 0,
    wnd_proc: 0,
    cls_extra: 0,
    wnd_extra: 0,
    instance: 0,
    fnid: 0,
    ref_count: 0,
    registered: false,
}; USER_MAX_CLASSES];
static mut USER_CLASS_COUNT: usize = 0;
static USER_NEXT_ATOM: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(0xC000);

pub unsafe fn user_register_class(
    class_name: *const u16,
    style: u32,
    wnd_proc: u64,
    wnd_extra: i32,
    fnid: u32,
) -> u32 {
    if class_name.is_null() {
        return 0;
    }
    // Already registered?
    let mut i = 0;
    while i < USER_MAX_CLASSES {
        if USER_CLASSES[i].registered {
            let mut j = 0;
            let mut same = true;
            loop {
                let a = USER_CLASSES[i].class_name[j];
                let b = *class_name.add(j);
                if a != b {
                    same = false;
                    break;
                }
                if a == 0 {
                    break;
                }
                j += 1;
                if j >= 64 {
                    break;
                }
            }
            if same {
                USER_CLASSES[i].ref_count += 1;
                return USER_CLASSES[i].atom;
            }
        }
        i += 1;
    }
    // New slot.
    i = 0;
    while i < USER_MAX_CLASSES {
        if !USER_CLASSES[i].registered {
            let atom = USER_NEXT_ATOM.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
            USER_CLASSES[i].atom = atom;
            let mut j = 0;
            while j < 63 && *class_name.add(j) != 0 {
                USER_CLASSES[i].class_name[j] = *class_name.add(j);
                j += 1;
            }
            USER_CLASSES[i].class_name[j] = 0;
            USER_CLASSES[i].style = style;
            USER_CLASSES[i].wnd_proc = wnd_proc;
            USER_CLASSES[i].wnd_extra = wnd_extra;
            USER_CLASSES[i].fnid = fnid;
            USER_CLASSES[i].ref_count = 1;
            USER_CLASSES[i].registered = true;
            USER_CLASS_COUNT += 1;
            return atom;
        }
        i += 1;
    }
    0
}

unsafe fn user_find_class(atom: u32) -> *mut WindowClass {
    let mut i = 0;
    while i < USER_MAX_CLASSES {
        if USER_CLASSES[i].registered && USER_CLASSES[i].atom == atom {
            return &mut USER_CLASSES[i] as *mut WindowClass;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

// ============================================================
// tagWND - kernel window object
// ============================================================

pub const WND_MAX_EXTRA: usize = 256;

#[repr(C)]
pub struct TagWnd {
    pub hwnd: u64,
    pub style: u32,
    pub ex_style: u32,
    pub rect_window: WinRect,
    pub rect_client: WinRect,
    pub parent: *mut TagWnd,
    pub child_first: *mut TagWnd,
    pub next_sibling: *mut TagWnd,
    pub prev_sibling: *mut TagWnd,
    pub owner: *mut TagWnd,
    pub class_atom: u32,
    pub fnid: u32,
    pub wnd_proc: u64,
    pub process_id: u64,
    pub thread_id: u64,
    pub desktop: *mut c_void,
    pub visible: bool,
    pub enabled: bool,
    pub minimized: bool,
    pub maximized: bool,
    pub topmost: bool,
    pub active: bool,
    pub has_focus: bool,
    pub extra: [u8; WND_MAX_EXTRA],
    pub extra_len: usize,
    pub title: [u16; 128],
    pub paint_dirty: bool,
    pub surface_id: u64,
}

static mut WND_LIST_HEAD: *mut TagWnd = core::ptr::null_mut();
static mut WND_COUNT: usize = 0;
static mut WND_ACTIVE: *mut TagWnd = core::ptr::null_mut();
static mut WND_FOCUS: *mut TagWnd = core::ptr::null_mut();
static mut WND_CAPTURE: *mut TagWnd = core::ptr::null_mut();

/// xxxCreateWindowEx - create a tagWND + HWND.
pub unsafe fn user_create_window_ex(
    ex_style: u32,
    class_atom: u32,
    title: *const u16,
    style: u32,
    x: i32,
    y: i32,
    width: i32,
    height: i32,
    parent_hwnd: u64,
    process_id: u64,
    thread_id: u64,
) -> u64 {
    let cls = user_find_class(class_atom);
    if cls.is_null() {
        return 0;
    }
    let wnd = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<TagWnd>(),
    ) as *mut TagWnd;
    if wnd.is_null() {
        return 0;
    }
    core::ptr::write_bytes(wnd as *mut u8, 0, core::mem::size_of::<TagWnd>());
    (*wnd).style = style;
    (*wnd).ex_style = ex_style;
    (*wnd).rect_window = WinRect {
        left: x,
        top: y,
        right: x + width,
        bottom: y + height,
    };
    // Client rect = window minus caption/border (approximation).
    let has_caption = style & WS_CAPTION != 0;
    let border = if style & WS_THICKFRAME != 0 {
        8
    } else if style & WS_BORDER != 0 {
        1
    } else {
        0
    };
    let caption_h = if has_caption { 30 } else { 0 };
    (*wnd).rect_client = WinRect {
        left: x + border,
        top: y + border + caption_h,
        right: x + width - border,
        bottom: y + height - border,
    };
    // Parent link.
    if parent_hwnd != 0 {
        let p = user_lookup_handle(parent_hwnd, USER_HANDLE_TYPE_WINDOW) as *mut TagWnd;
        (*wnd).parent = p;
        if !p.is_null() {
            (*wnd).next_sibling = (*p).child_first;
            if !(*p).child_first.is_null() {
                (*(*p).child_first).prev_sibling = wnd;
            }
            (*p).child_first = wnd;
        }
    }
    (*wnd).class_atom = class_atom;
    (*wnd).fnid = (*cls).fnid;
    (*wnd).wnd_proc = (*cls).wnd_proc;
    (*wnd).process_id = process_id;
    (*wnd).thread_id = thread_id;
    (*wnd).visible = style & WS_VISIBLE != 0;
    (*wnd).enabled = style & WS_DISABLED == 0;
    (*wnd).minimized = style & WS_MINIMIZE != 0;
    (*wnd).maximized = style & WS_MAXIMIZE != 0;
    (*wnd).topmost = ex_style & WS_EX_TOPMOST != 0;
    (*wnd).extra_len = (*cls).wnd_extra.max(0) as usize;
    if (*wnd).extra_len > WND_MAX_EXTRA {
        (*wnd).extra_len = WND_MAX_EXTRA;
    }
    if !title.is_null() {
        let mut i = 0;
        while i < 127 && *title.add(i) != 0 {
            (*wnd).title[i] = *title.add(i);
            i += 1;
        }
    }
    (*wnd).paint_dirty = true;
    let hwnd = user_alloc_handle(
        wnd as *mut c_void,
        USER_HANDLE_TYPE_WINDOW,
        process_id,
    );
    if hwnd == 0 {
        crate::mm::pool::ex_free_pool(wnd as *mut c_void);
        return 0;
    }
    (*wnd).hwnd = hwnd;
    (*wnd).surface_id = super::compositor::dwm_create_surface(
        width.max(1) as u32,
        height.max(1) as u32,
    );
    WND_COUNT += 1;
    // Post WM_CREATE to the owner thread queue.
    user_post_message(thread_id, hwnd, WM_CREATE, 0, 0);
    hwnd
}

/// xxxDestroyWindow - recursive destroy (children first).
pub unsafe fn user_destroy_window(hwnd: u64) -> NtStatus {
    let wnd = user_lookup_handle(hwnd, USER_HANDLE_TYPE_WINDOW) as *mut TagWnd;
    if wnd.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Destroy children first.
    let mut child = (*wnd).child_first;
    while !child.is_null() {
        let next = (*child).next_sibling;
        user_destroy_window((*child).hwnd);
        child = next;
    }
    // Unlink from parent / siblings.
    if !(*wnd).prev_sibling.is_null() {
        (*(*wnd).prev_sibling).next_sibling = (*wnd).next_sibling;
    } else if !(*wnd).parent.is_null() {
        (*(*wnd).parent).child_first = (*wnd).next_sibling;
    }
    if !(*wnd).next_sibling.is_null() {
        (*(*wnd).next_sibling).prev_sibling = (*wnd).prev_sibling;
    }
    if WND_ACTIVE == wnd {
        WND_ACTIVE = core::ptr::null_mut();
    }
    if WND_FOCUS == wnd {
        WND_FOCUS = core::ptr::null_mut();
    }
    if WND_CAPTURE == wnd {
        WND_CAPTURE = core::ptr::null_mut();
    }
    super::compositor::dwm_destroy_surface((*wnd).surface_id);
    user_free_handle(hwnd);
    crate::mm::pool::ex_free_pool(wnd as *mut c_void);
    WND_COUNT -= 1;
    STATUS_SUCCESS
}

/// xxxShowWindow - show/hide/minimize/maximize.
pub unsafe fn user_show_window(hwnd: u64, cmd: u32) -> bool {
    let wnd = user_lookup_handle(hwnd, USER_HANDLE_TYPE_WINDOW) as *mut TagWnd;
    if wnd.is_null() {
        return false;
    }
    let was_visible = (*wnd).visible;
    match cmd {
        SW_HIDE => {
            (*wnd).visible = false;
        }
        SW_SHOW => {
            (*wnd).visible = true;
            (*wnd).minimized = false;
        }
        SW_MINIMIZE => {
            (*wnd).minimized = true;
        }
        SW_RESTORE => {
            (*wnd).minimized = false;
            (*wnd).maximized = false;
            (*wnd).visible = true;
        }
        SW_MAXIMIZE => {
            (*wnd).maximized = true;
            (*wnd).minimized = false;
            (*wnd).visible = true;
        }
        _ => {
            (*wnd).visible = true;
        }
    }
    (*wnd).paint_dirty = true;
    user_post_message(
        (*wnd).thread_id,
        hwnd,
        WM_SHOWWINDOW,
        if (*wnd).visible { 1 } else { 0 },
        0,
    );
    was_visible
}

/// xxxSetWindowPos - move/resize/z-order (IntLinkHwnd semantics).
pub unsafe fn user_set_window_pos(
    hwnd: u64,
    insert_after: u64,
    x: i32,
    y: i32,
    cx: i32,
    cy: i32,
    flags: u32,
) -> NtStatus {
    const SWP_NOSIZE: u32 = 0x0001;
    const SWP_NOMOVE: u32 = 0x0002;
    const SWP_NOZORDER: u32 = 0x0004;
    const SWP_SHOWWINDOW: u32 = 0x0040;
    const SWP_HIDEWINDOW: u32 = 0x0080;
    let wnd = user_lookup_handle(hwnd, USER_HANDLE_TYPE_WINDOW) as *mut TagWnd;
    if wnd.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let w = (*wnd).rect_window.width();
    let h = (*wnd).rect_window.height();
    if flags & SWP_NOMOVE == 0 {
        let dx = x - (*wnd).rect_window.left;
        let dy = y - (*wnd).rect_window.top;
        (*wnd).rect_window.left = x;
        (*wnd).rect_window.top = y;
        (*wnd).rect_window.right = x + w;
        (*wnd).rect_window.bottom = y + h;
        (*wnd).rect_client.left += dx;
        (*wnd).rect_client.right += dx;
        (*wnd).rect_client.top += dy;
        (*wnd).rect_client.bottom += dy;
    }
    if flags & SWP_NOSIZE == 0 {
        (*wnd).rect_window.right = (*wnd).rect_window.left + cx;
        (*wnd).rect_window.bottom = (*wnd).rect_window.top + cy;
        (*wnd).rect_client.right = (*wnd).rect_client.left + cx;
        (*wnd).rect_client.bottom = (*wnd).rect_client.top + cy;
        super::compositor::dwm_resize_surface((*wnd).surface_id, cx.max(1) as u32, cy.max(1) as u32);
    }
    if flags & SWP_NOZORDER == 0 {
        // Unlink.
        if !(*wnd).prev_sibling.is_null() {
            (*(*wnd).prev_sibling).next_sibling = (*wnd).next_sibling;
        } else if !(*wnd).parent.is_null() {
            (*(*wnd).parent).child_first = (*wnd).next_sibling;
        }
        if !(*wnd).next_sibling.is_null() {
            (*(*wnd).next_sibling).prev_sibling = (*wnd).prev_sibling;
        }
        (*wnd).prev_sibling = core::ptr::null_mut();
        (*wnd).next_sibling = core::ptr::null_mut();
        // Reinsert.
        if insert_after == HWND_TOP || insert_after == 0 {
            if !(*wnd).parent.is_null() {
                (*wnd).next_sibling = (*(*wnd).parent).child_first;
                if !(*wnd).next_sibling.is_null() {
                    (*(*wnd).next_sibling).prev_sibling = wnd;
                }
                (*(*wnd).parent).child_first = wnd;
            }
        } else if insert_after == HWND_BOTTOM {
            if !(*wnd).parent.is_null() {
                let mut tail = (*(*wnd).parent).child_first;
                if tail.is_null() {
                    (*(*wnd).parent).child_first = wnd;
                } else {
                    while !(*tail).next_sibling.is_null() {
                        tail = (*tail).next_sibling;
                    }
                    (*tail).next_sibling = wnd;
                    (*wnd).prev_sibling = tail;
                }
            }
        }
    }
    if flags & SWP_SHOWWINDOW != 0 {
        (*wnd).visible = true;
    }
    if flags & SWP_HIDEWINDOW != 0 {
        (*wnd).visible = false;
    }
    (*wnd).paint_dirty = true;
    user_post_message((*wnd).thread_id, hwnd, WM_WINDOWPOSCHANGED, 0, 0);
    STATUS_SUCCESS
}

// ============================================================
// Message queues (per GUI thread)
// ============================================================

pub const USER_QUEUE_SIZE: usize = 256;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct WinMessage {
    pub hwnd: u64,
    pub message: u32,
    pub w_param: u64,
    pub l_param: u64,
    pub time_ms: u64,
    pub point_x: i32,
    pub point_y: i32,
}

#[repr(C)]
pub struct UserMessageQueue {
    pub thread_id: u64,
    pub messages: [WinMessage; USER_QUEUE_SIZE],
    pub head: usize,
    pub tail: usize,
    pub count: usize,
    pub quit_posted: bool,
    pub quit_code: i32,
}

pub unsafe fn user_create_message_queue(thread_id: u64) -> *mut UserMessageQueue {
    let q = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<UserMessageQueue>(),
    ) as *mut UserMessageQueue;
    if q.is_null() {
        return core::ptr::null_mut();
    }
    core::ptr::write_bytes(q as *mut u8, 0, core::mem::size_of::<UserMessageQueue>());
    (*q).thread_id = thread_id;
    q
}

/// xxxPostMessage - async enqueue.
pub unsafe fn user_post_message(
    thread_id: u64,
    hwnd: u64,
    message: u32,
    w_param: u64,
    l_param: u64,
) -> bool {
    // Find the queue via the GUI thread table.
    let ti = super::win32k_find_gui_thread(thread_id);
    if ti.is_null() {
        return false;
    }
    let q = (*ti).message_queue as *mut UserMessageQueue;
    if q.is_null() {
        return false;
    }
    if message == WM_QUIT {
        (*q).quit_posted = true;
        (*q).quit_code = w_param as i32;
        return true;
    }
    if (*q).count >= USER_QUEUE_SIZE {
        return false;
    }
    let (cx, cy) = (USER_CURSOR_X, USER_CURSOR_Y);
    (*q).messages[(*q).tail] = WinMessage {
        hwnd,
        message,
        w_param,
        l_param,
        time_ms: 0,
        point_x: cx,
        point_y: cy,
    };
    (*q).tail = ((*q).tail + 1) % USER_QUEUE_SIZE;
    (*q).count += 1;
    true
}

/// xxxGetMessage - blocking-style dequeue (non-blocking here).
pub unsafe fn user_get_message(
    thread_id: u64,
    msg_out: *mut WinMessage,
    hwnd_filter: u64,
    msg_min: u32,
    msg_max: u32,
) -> i32 {
    if msg_out.is_null() {
        return -1;
    }
    let ti = super::win32k_find_gui_thread(thread_id);
    if ti.is_null() {
        return -1;
    }
    let q = (*ti).message_queue as *mut UserMessageQueue;
    if q.is_null() {
        return -1;
    }
    if (*q).quit_posted && (*q).count == 0 {
        (*msg_out) = WinMessage {
            hwnd: 0,
            message: WM_QUIT,
            w_param: (*q).quit_code as u64,
            l_param: 0,
            time_ms: 0,
            point_x: 0,
            point_y: 0,
        };
        return 0;
    }
    // Scan for first matching message.
    let mut idx = (*q).head;
    let mut n = 0;
    while n < (*q).count {
        let m = (*q).messages[idx];
        let hwnd_ok = hwnd_filter == 0 || m.hwnd == hwnd_filter;
        let range_ok = (msg_min == 0 && msg_max == 0)
            || (m.message >= msg_min && m.message <= msg_max);
        if hwnd_ok && range_ok {
            *msg_out = m;
            // Remove by shifting (queues are small).
            let mut j = idx;
            while j != (*q).tail {
                let next = (j + 1) % USER_QUEUE_SIZE;
                if next == (*q).tail {
                    break;
                }
                (*q).messages[j] = (*q).messages[next];
                j = next;
            }
            (*q).tail = ((*q).tail + USER_QUEUE_SIZE - 1) % USER_QUEUE_SIZE;
            (*q).count -= 1;
            return 1;
        }
        idx = (idx + 1) % USER_QUEUE_SIZE;
        n += 1;
    }
    0
}

// ============================================================
// Input state (cursor, keys, focus, capture)
// ============================================================

static mut USER_CURSOR_X: i32 = 0;
static mut USER_CURSOR_Y: i32 = 0;
static mut USER_KEY_STATE: [u8; 256] = [0; 256];
static mut USER_ASYNC_KEY_STATE: [u16; 256] = [0; 256];

pub unsafe fn user_set_cursor_pos(x: i32, y: i32) {
    USER_CURSOR_X = x;
    USER_CURSOR_Y = y;
}

pub unsafe fn user_get_cursor_pos(x_out: *mut i32, y_out: *mut i32) {
    if !x_out.is_null() {
        *x_out = USER_CURSOR_X;
    }
    if !y_out.is_null() {
        *y_out = USER_CURSOR_Y;
    }
}

/// Input injection from keyboard/mouse drivers.
pub unsafe fn user_inject_key(vk: u8, down: bool, thread_id: u64) {
    USER_KEY_STATE[vk as usize] = if down { 0x80 } else { 0 };
    USER_ASYNC_KEY_STATE[vk as usize] = if down { 0x8001 } else { 0 };
    let msg = if down { WM_KEYDOWN } else { WM_KEYUP };
    let mut target = 0u64;
    let mut tid = thread_id;
    if !WND_FOCUS.is_null() {
        target = (*WND_FOCUS).hwnd;
        if tid == 0 {
            tid = (*WND_FOCUS).thread_id;
        }
    }
    if tid != 0 {
        user_post_message(tid, target, msg, vk as u64, 0);
    }
}

pub unsafe fn user_inject_mouse(
    x: i32,
    y: i32,
    buttons: u32,
    thread_id: u64,
) {
    let old_buttons = USER_MOUSE_BUTTONS;
    USER_CURSOR_X = x;
    USER_CURSOR_Y = y;
    USER_MOUSE_BUTTONS = buttons;
    // Hit-test top-level windows (reverse z-order not tracked here;
    // deliver to capture window or topmost visible child of desktop).
    let mut target = 0u64;
    if !WND_CAPTURE.is_null() {
        target = (*WND_CAPTURE).hwnd;
    } else {
        target = user_hit_test(x, y);
    }
    // Route to the target window's thread when the caller passes 0.
    let mut tid = thread_id;
    if tid == 0 && target != 0 {
        let wnd = user_lookup_handle(target, USER_HANDLE_TYPE_WINDOW) as *mut TagWnd;
        if !wnd.is_null() {
            tid = (*wnd).thread_id;
        }
    }
    if tid == 0 {
        return;
    }
    user_post_message(tid, target, WM_MOUSEMOVE, buttons as u64, ((y as u64) << 32) | (x as u64 & 0xFFFF_FFFF));
    // Button transitions.
    let changed = old_buttons ^ buttons;
    if changed & 0x1 != 0 {
        user_post_message(
            tid,
            target,
            if buttons & 0x1 != 0 {
                WM_LBUTTONDOWN
            } else {
                WM_LBUTTONUP
            },
            0,
            0,
        );
    }
    if changed & 0x2 != 0 {
        user_post_message(
            tid,
            target,
            if buttons & 0x2 != 0 {
                WM_RBUTTONDOWN
            } else {
                WM_RBUTTONUP
            },
            0,
            0,
        );
    }
}

static mut USER_MOUSE_BUTTONS: u32 = 0;

/// Hit-test: topmost visible window containing (x,y).
unsafe fn user_hit_test(x: i32, y: i32) -> u64 {
    // Walk all top-level windows; last match in creation order wins
    // (approximation of reverse z-order).
    let mut hit = 0u64;
    // We track windows only via handles; scan the handle table.
    let mut i = 1usize;
    while i < USER_HANDLE_TABLE_SIZE {
        if USER_HANDLE_TABLE[i].handle_type == USER_HANDLE_TYPE_WINDOW {
            let wnd = USER_HANDLE_TABLE[i].object as *mut TagWnd;
            if !wnd.is_null()
                && (*wnd).visible
                && !(*wnd).minimized
                && (*wnd).parent.is_null()
                && (*wnd).rect_window.contains(x, y)
            {
                hit = (*wnd).hwnd;
            }
        }
        i += 1;
    }
    hit
}

pub unsafe fn user_set_focus(hwnd: u64) -> u64 {
    let prev = if !WND_FOCUS.is_null() {
        (*WND_FOCUS).hwnd
    } else {
        0
    };
    if !WND_FOCUS.is_null() {
        (*WND_FOCUS).has_focus = false;
        user_post_message((*WND_FOCUS).thread_id, (*WND_FOCUS).hwnd, WM_KILLFOCUS, 0, 0);
    }
    let wnd = user_lookup_handle(hwnd, USER_HANDLE_TYPE_WINDOW) as *mut TagWnd;
    WND_FOCUS = wnd;
    if !wnd.is_null() {
        (*wnd).has_focus = true;
        user_post_message((*wnd).thread_id, hwnd, WM_SETFOCUS, 0, 0);
    }
    prev
}

pub unsafe fn user_set_capture(hwnd: u64) -> u64 {
    let prev = if !WND_CAPTURE.is_null() {
        (*WND_CAPTURE).hwnd
    } else {
        0
    };
    WND_CAPTURE = user_lookup_handle(hwnd, USER_HANDLE_TYPE_WINDOW) as *mut TagWnd;
    prev
}

// ============================================================
// Window stations + desktops (WinSta0, Default, Winlogon)
// ============================================================

#[repr(C)]
pub struct WindowStation {
    pub name: [u16; 64],
    pub interactive: bool,
    pub desktops: *mut Desktop,
    pub clipboard_sequence: u32,
    pub atom_table: u64,
}

#[repr(C)]
pub struct Desktop {
    pub name: [u16; 64],
    pub window_station: *mut WindowStation,
    pub desktop_window: *mut TagWnd,
    pub active_window: *mut TagWnd,
    pub next: *mut Desktop,
}

static mut USER_WINSTA0: *mut WindowStation = core::ptr::null_mut();
static mut USER_DEFAULT_DESKTOP: *mut Desktop = core::ptr::null_mut();
static mut USER_WINLOGON_DESKTOP: *mut Desktop = core::ptr::null_mut();
static mut USER_SCREENSAVER_DESKTOP: *mut Desktop = core::ptr::null_mut();
static mut USER_INPUT_DESKTOP: *mut Desktop = core::ptr::null_mut();

unsafe fn user_make_station(name: &[u16; 7], interactive: bool) -> *mut WindowStation {
    let st = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<WindowStation>(),
    ) as *mut WindowStation;
    if st.is_null() {
        return core::ptr::null_mut();
    }
    core::ptr::write_bytes(st as *mut u8, 0, core::mem::size_of::<WindowStation>());
    let mut i = 0;
    while i < 7 && i < 64 {
        (*st).name[i] = name[i];
        i += 1;
    }
    (*st).interactive = interactive;
    st
}

unsafe fn user_make_desktop(
    station: *mut WindowStation,
    name: &[u16; 12],
) -> *mut Desktop {
    let d = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<Desktop>(),
    ) as *mut Desktop;
    if d.is_null() {
        return core::ptr::null_mut();
    }
    core::ptr::write_bytes(d as *mut u8, 0, core::mem::size_of::<Desktop>());
    let mut i = 0;
    while i < 12 && i < 64 {
        (*d).name[i] = name[i];
        i += 1;
    }
    (*d).window_station = station;
    (*d).next = (*station).desktops;
    (*station).desktops = d;
    d
}

pub unsafe fn user_get_winsta0() -> *mut WindowStation {
    USER_WINSTA0
}

pub unsafe fn user_get_default_desktop() -> *mut Desktop {
    USER_DEFAULT_DESKTOP
}

pub unsafe fn user_get_input_desktop() -> *mut Desktop {
    USER_INPUT_DESKTOP
}

// ============================================================
// Menus
// ============================================================

pub const USER_MAX_MENUS: usize = 4096;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MenuItem {
    pub id: u32,
    pub flags: u32,
    pub text: [u16; 64],
    pub submenu: u64,
}

#[repr(C)]
pub struct TagMenu {
    pub handle: u64,
    pub items: [MenuItem; 64],
    pub item_count: u32,
    pub parent_window: u64,
}

pub unsafe fn user_create_menu() -> u64 {
    let m = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<TagMenu>(),
    ) as *mut TagMenu;
    if m.is_null() {
        return 0;
    }
    core::ptr::write_bytes(m as *mut u8, 0, core::mem::size_of::<TagMenu>());
    let h = user_alloc_handle(m as *mut c_void, USER_HANDLE_TYPE_MENU, 0);
    (*m).handle = h;
    h
}

pub unsafe fn user_append_menu(
    menu_handle: u64,
    id: u32,
    flags: u32,
    text: *const u16,
) -> bool {
    let m = user_lookup_handle(menu_handle, USER_HANDLE_TYPE_MENU) as *mut TagMenu;
    if m.is_null() || (*m).item_count >= 64 {
        return false;
    }
    let idx = (*m).item_count as usize;
    (*m).items[idx].id = id;
    (*m).items[idx].flags = flags;
    if !text.is_null() {
        let mut i = 0;
        while i < 63 && *text.add(i) != 0 {
            (*m).items[idx].text[i] = *text.add(i);
            i += 1;
        }
    }
    (*m).item_count += 1;
    true
}

// ============================================================
// Timers
// ============================================================

pub const USER_MAX_TIMERS: usize = 256;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UserTimer {
    pub timer_id: u64,
    pub hwnd: u64,
    pub interval_ms: u32,
    pub last_fire_ms: u64,
    pub active: bool,
}

static mut USER_TIMERS: [UserTimer; USER_MAX_TIMERS] = [UserTimer {
    timer_id: 0,
    hwnd: 0,
    interval_ms: 0,
    last_fire_ms: 0,
    active: false,
}; USER_MAX_TIMERS];

pub unsafe fn user_set_timer(hwnd: u64, timer_id: u64, interval_ms: u32) -> u64 {
    let mut i = 0;
    while i < USER_MAX_TIMERS {
        if !USER_TIMERS[i].active {
            USER_TIMERS[i].timer_id = if timer_id == 0 { (i + 1) as u64 } else { timer_id };
            USER_TIMERS[i].hwnd = hwnd;
            USER_TIMERS[i].interval_ms = interval_ms;
            USER_TIMERS[i].active = true;
            return USER_TIMERS[i].timer_id;
        }
        i += 1;
    }
    0
}

/// Fire expired timers (called from the win32k tick/DPC).
pub unsafe fn user_fire_timers(now_ms: u64) {
    let mut i = 0;
    while i < USER_MAX_TIMERS {
        if USER_TIMERS[i].active
            && now_ms - USER_TIMERS[i].last_fire_ms >= USER_TIMERS[i].interval_ms as u64
        {
            USER_TIMERS[i].last_fire_ms = now_ms;
            let wnd = user_lookup_handle(USER_TIMERS[i].hwnd, USER_HANDLE_TYPE_WINDOW)
                as *mut TagWnd;
            if !wnd.is_null() {
                user_post_message(
                    (*wnd).thread_id,
                    USER_TIMERS[i].hwnd,
                    WM_TIMER,
                    USER_TIMERS[i].timer_id,
                    0,
                );
            }
        }
        i += 1;
    }
}

// ============================================================
// Base init + shadow-SSDT registration
// ============================================================

pub unsafe fn user_init_base() -> NtStatus {
    // WinSta0 (interactive).
    let winsta0_name: [u16; 7] = [0x57, 0x69, 0x6E, 0x53, 0x74, 0x61, 0x30]; // WinSta0
    USER_WINSTA0 = user_make_station(&winsta0_name, true);
    if USER_WINSTA0.is_null() {
        return STATUS_NO_MEMORY;
    }
    // Default desktop.
    let default_name: [u16; 12] = [
        0x44, 0x65, 0x66, 0x61, 0x75, 0x6C, 0x74, 0, 0, 0, 0, 0,
    ]; // Default
    USER_DEFAULT_DESKTOP = user_make_desktop(USER_WINSTA0, &default_name);
    // Winlogon desktop.
    let winlogon_name: [u16; 12] = [
        0x57, 0x69, 0x6E, 0x6C, 0x6F, 0x67, 0x6F, 0x6E, 0, 0, 0, 0,
    ]; // Winlogon
    USER_WINLOGON_DESKTOP = user_make_desktop(USER_WINSTA0, &winlogon_name);
    // Screen-saver desktop.
    let ss_name: [u16; 12] = [
        0x53, 0x63, 0x72, 0x65, 0x65, 0x6E, 0x2D, 0x53, 0x61, 0x76, 0x65, 0x72,
    ]; // Screen-Saver
    USER_SCREENSAVER_DESKTOP = user_make_desktop(USER_WINSTA0, &ss_name);
    USER_INPUT_DESKTOP = USER_DEFAULT_DESKTOP;
    // Register system classes (BUTTON/EDIT/STATIC/...).
    let button: [u16; 7] = [0x42, 0x55, 0x54, 0x54, 0x4F, 0x4E, 0];
    user_register_class(button.as_ptr(), 0, 0, 0, FNID_BUTTON);
    let edit: [u16; 5] = [0x45, 0x44, 0x49, 0x54, 0];
    user_register_class(edit.as_ptr(), 0, 0, 0, FNID_EDIT);
    let statik: [u16; 7] = [0x53, 0x54, 0x41, 0x54, 0x49, 0x43, 0];
    user_register_class(statik.as_ptr(), 0, 0, 0, FNID_STATIC);
    STATUS_SUCCESS
}

unsafe extern "C" fn ntuser_create_window_ex(args: *const u64, n: u32) -> u64 {
    if n < 11 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, n as usize);
    user_create_window_ex(
        a[0] as u32,
        a[1] as u32,
        a[2] as *const u16,
        a[3] as u32,
        a[4] as i32,
        a[5] as i32,
        a[6] as i32,
        a[7] as i32,
        a[8],
        a[9],
        a[10],
    )
}

unsafe extern "C" fn ntuser_destroy_window(args: *const u64, n: u32) -> u64 {
    if n < 1 || args.is_null() {
        return STATUS_INVALID_PARAMETER as u32 as u64;
    }
    user_destroy_window(*args) as u32 as u64
}

unsafe extern "C" fn ntuser_show_window(args: *const u64, n: u32) -> u64 {
    if n < 2 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 2);
    user_show_window(a[0], a[1] as u32) as u64
}

unsafe extern "C" fn ntuser_get_message(args: *const u64, n: u32) -> u64 {
    if n < 5 || args.is_null() {
        return u64::MAX;
    }
    let a = core::slice::from_raw_parts(args, 5);
    user_get_message(a[0], a[1] as *mut WinMessage, a[2], a[3] as u32, a[4] as u32) as u64
}

unsafe extern "C" fn ntuser_post_message(args: *const u64, n: u32) -> u64 {
    if n < 5 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 5);
    // args: thread_id, hwnd, msg, wparam, lparam
    user_post_message(a[0], a[1], a[2] as u32, a[3], a[4]) as u64
}

unsafe extern "C" fn ntuser_set_focus(args: *const u64, n: u32) -> u64 {
    if n < 1 || args.is_null() {
        return 0;
    }
    user_set_focus(*args)
}

unsafe extern "C" fn ntuser_set_capture(args: *const u64, n: u32) -> u64 {
    if n < 1 || args.is_null() {
        return 0;
    }
    user_set_capture(*args)
}

unsafe extern "C" fn ntuser_create_menu(args: *const u64, _n: u32) -> u64 {
    let _ = args;
    unsafe { user_create_menu() }
}

unsafe extern "C" fn ntuser_set_timer(args: *const u64, n: u32) -> u64 {
    if n < 3 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 3);
    unsafe { user_set_timer(a[0], a[1], a[2] as u32) }
}

unsafe extern "C" fn ntuser_get_cursor_pos(args: *const u64, n: u32) -> u64 {
    if n < 2 || args.is_null() {
        return 0;
    }
    let a = core::slice::from_raw_parts(args, 2);
    unsafe { user_get_cursor_pos(a[0] as *mut i32, a[1] as *mut i32) };
    1
}

pub unsafe fn user_register_services() -> NtStatus {
    use super::*;
    win32k_register_service(
        NTUSER_CREATE_WINDOW_EX,
        ntuser_create_window_ex as Win32kServiceHandler,
    );
    win32k_register_service(
        NTUSER_DESTROY_WINDOW,
        ntuser_destroy_window as Win32kServiceHandler,
    );
    win32k_register_service(NTUSER_SHOW_WINDOW, ntuser_show_window as Win32kServiceHandler);
    win32k_register_service(NTUSER_GET_MESSAGE, ntuser_get_message as Win32kServiceHandler);
    win32k_register_service(NTUSER_POST_MESSAGE, ntuser_post_message as Win32kServiceHandler);
    win32k_register_service(NTUSER_SET_FOCUS, ntuser_set_focus as Win32kServiceHandler);
    win32k_register_service(NTUSER_SET_CAPTURE, ntuser_set_capture as Win32kServiceHandler);
    win32k_register_service(NTUSER_CREATE_MENU, ntuser_create_menu as Win32kServiceHandler);
    win32k_register_service(NTUSER_SET_TIMER, ntuser_set_timer as Win32kServiceHandler);
    win32k_register_service(
        NTUSER_GET_CURSOR_POS,
        ntuser_get_cursor_pos as Win32kServiceHandler,
    );
    STATUS_SUCCESS
}
