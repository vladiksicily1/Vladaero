/// Win32k - Kernel-Mode Window Manager + GDI (win32kbase.sys/win32kfull.sys)
///
/// Implements the USER half (windows, message queues, input, window
/// stations/desktops) and the GDI half (device contexts, bitmaps,
/// pens/brushes/fonts, drawing) in kernel mode, dispatched through the
/// shadow SSDT (W32pServiceTable) as NtUser*/NtGdi* services.
///
/// Architecture (matches Windows 10):
///   - win32kbase: shared base (handle tables, callbacks, framebuffer)
///   - win32kfull: desktop window manager + full GDI
///   - tagWND kernel objects, HWND = USER handle table index
///   - GDI handle table (read-only mirror concept), 10k quotas
///   - THREADINFO (W32THREAD) per GUI thread + message queue
///   - WinSta0 + Default/Winlogon/ScreenSaver desktops
///   - KeUserModeCallback via PEB.KernelCallbackTable
///
/// References:
///   - Windows Internals 7th Ed.
///   - ReactOS win32ss (user/ntuser, gdi/eng)

use core::ffi::c_void;

use crate::types::*;

pub mod user;
pub mod gdi;
pub mod compositor;
pub mod callback;

// ============================================================
// Logging
// ============================================================

macro_rules! win32k_trace {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Win32k] {}", format_args!($($arg)*));
    };
}

// ============================================================
// Framebuffer (attached by the bootloader / bootvid)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Win32kFramebuffer {
    pub base: u64,
    pub width: u32,
    pub height: u32,
    pub pitch: u32,
    pub bpp: u32,
    pub attached: bool,
}

impl Win32kFramebuffer {
    pub const fn new() -> Self {
        Self {
            base: 0,
            width: 0,
            height: 0,
            pitch: 0,
            bpp: 32,
            attached: false,
        }
    }
}

static mut WIN32K_FRAMEBUFFER: Win32kFramebuffer = Win32kFramebuffer::new();

/// Win32kAttachFramebuffer - called by bootvid/GOP handoff.
pub fn win32k_attach_framebuffer(
    base: u64,
    width: u32,
    height: u32,
    pitch: u32,
    bpp: u32,
) {
    unsafe {
        WIN32K_FRAMEBUFFER = Win32kFramebuffer {
            base,
            width,
            height,
            pitch,
            bpp,
            attached: base != 0 && width > 0 && height > 0,
        };
    }
    win32k_trace!(
        "Framebuffer {}x{} pitch={} bpp={}",
        width,
        height,
        pitch,
        bpp
    );
}

pub fn win32k_framebuffer() -> Win32kFramebuffer {
    unsafe { WIN32K_FRAMEBUFFER }
}

// ============================================================
// Shadow SSDT (W32pServiceTable)
// ============================================================

pub const WIN32K_SSDT_SIZE: usize = 512;
pub const WIN32K_SERVICE_USER_BASE: u32 = 0x1000;
pub const WIN32K_SERVICE_GDI_BASE: u32 = 0x1200;

pub type Win32kServiceHandler =
    unsafe extern "C" fn(args: *const u64, arg_count: u32) -> u64;

unsafe extern "C" fn win32k_unimplemented(_args: *const u64, _n: u32) -> u64 {
    u64::MAX
}

static mut WIN32K_SERVICE_TABLE: [Win32kServiceHandler; WIN32K_SSDT_SIZE] =
    [win32k_unimplemented; WIN32K_SSDT_SIZE];

/// Register one shadow-SSDT service.
pub unsafe fn win32k_register_service(index: u32, handler: Win32kServiceHandler) -> NtStatus {
    if (index as usize) >= WIN32K_SSDT_SIZE {
        return STATUS_INVALID_PARAMETER;
    }
    WIN32K_SERVICE_TABLE[index as usize] = handler;
    STATUS_SUCCESS
}

/// Dispatch a shadow-SSDT call (from the syscall trap).
pub unsafe fn win32k_dispatch_service(
    index: u32,
    args: *const u64,
    arg_count: u32,
) -> u64 {
    if (index as usize) >= WIN32K_SSDT_SIZE {
        return u64::MAX;
    }
    WIN32K_SERVICE_TABLE[index as usize](args, arg_count)
}

// NtUser/NtGdi service numbers (stable ABI for user32/gdi32).
pub const NTUSER_CREATE_WINDOW_EX: u32 = WIN32K_SERVICE_USER_BASE + 0;
pub const NTUSER_DESTROY_WINDOW: u32 = WIN32K_SERVICE_USER_BASE + 1;
pub const NTUSER_SHOW_WINDOW: u32 = WIN32K_SERVICE_USER_BASE + 2;
pub const NTUSER_SET_WINDOW_POS: u32 = WIN32K_SERVICE_USER_BASE + 3;
pub const NTUSER_GET_MESSAGE: u32 = WIN32K_SERVICE_USER_BASE + 4;
pub const NTUSER_POST_MESSAGE: u32 = WIN32K_SERVICE_USER_BASE + 5;
pub const NTUSER_SEND_MESSAGE: u32 = WIN32K_SERVICE_USER_BASE + 6;
pub const NTUSER_REGISTER_CLASS: u32 = WIN32K_SERVICE_USER_BASE + 7;
pub const NTUSER_SET_FOCUS: u32 = WIN32K_SERVICE_USER_BASE + 8;
pub const NTUSER_SET_CAPTURE: u32 = WIN32K_SERVICE_USER_BASE + 9;
pub const NTUSER_CREATE_MENU: u32 = WIN32K_SERVICE_USER_BASE + 10;
pub const NTUSER_SET_TIMER: u32 = WIN32K_SERVICE_USER_BASE + 11;
pub const NTUSER_GET_CURSOR_POS: u32 = WIN32K_SERVICE_USER_BASE + 12;
pub const NTUSER_SET_CURSOR_POS: u32 = WIN32K_SERVICE_USER_BASE + 13;
pub const NTUSER_GET_KEYBOARD_STATE: u32 = WIN32K_SERVICE_USER_BASE + 14;
pub const NTUSER_GET_ASYNC_KEY_STATE: u32 = WIN32K_SERVICE_USER_BASE + 15;
pub const NTUSER_CALL_CALLBACK: u32 = WIN32K_SERVICE_USER_BASE + 16;

pub const NTGDI_CREATE_DC: u32 = WIN32K_SERVICE_GDI_BASE + 0;
pub const NTGDI_DELETE_DC: u32 = WIN32K_SERVICE_GDI_BASE + 1;
pub const NTGDI_CREATE_BITMAP: u32 = WIN32K_SERVICE_GDI_BASE + 2;
pub const NTGDI_DELETE_OBJECT: u32 = WIN32K_SERVICE_GDI_BASE + 3;
pub const NTGDI_SELECT_OBJECT: u32 = WIN32K_SERVICE_GDI_BASE + 4;
pub const NTGDI_BITBLT: u32 = WIN32K_SERVICE_GDI_BASE + 5;
pub const NTGDI_PATBLT: u32 = WIN32K_SERVICE_GDI_BASE + 6;
pub const NTGDI_TEXTOUT: u32 = WIN32K_SERVICE_GDI_BASE + 7;
pub const NTGDI_CREATE_PEN: u32 = WIN32K_SERVICE_GDI_BASE + 8;
pub const NTGDI_CREATE_BRUSH: u32 = WIN32K_SERVICE_GDI_BASE + 9;
pub const NTGDI_RECTANGLE: u32 = WIN32K_SERVICE_GDI_BASE + 10;
pub const NTGDI_ELLIPSE: u32 = WIN32K_SERVICE_GDI_BASE + 11;
pub const NTGDI_FILL_RECT: u32 = WIN32K_SERVICE_GDI_BASE + 12;
pub const NTGDI_GET_PIXEL: u32 = WIN32K_SERVICE_GDI_BASE + 13;
pub const NTGDI_SET_PIXEL: u32 = WIN32K_SERVICE_GDI_BASE + 14;
pub const NTGDI_CREATE_REGION: u32 = WIN32K_SERVICE_GDI_BASE + 15;
pub const NTGDI_CREATE_FONT: u32 = WIN32K_SERVICE_GDI_BASE + 16;

// ============================================================
// GUI thread promotion (THREADINFO / W32THREAD)
// ============================================================

pub const WIN32K_MAX_GUI_THREADS: usize = 256;
pub const WIN32K_GUI_STACK_PAGES: usize = 24;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Win32kThreadInfo {
    pub thread_id: u64,
    pub process_id: u64,
    pub message_queue: *mut c_void,
    pub desktop: *mut c_void,
    pub window_station: *mut c_void,
    pub active: bool,
}

static mut WIN32K_GUI_THREADS: [Win32kThreadInfo; WIN32K_MAX_GUI_THREADS] =
    [Win32kThreadInfo {
        thread_id: 0,
        process_id: 0,
        message_queue: core::ptr::null_mut(),
        desktop: core::ptr::null_mut(),
        window_station: core::ptr::null_mut(),
        active: false,
    }; WIN32K_MAX_GUI_THREADS];
static mut WIN32K_GUI_THREAD_COUNT: usize = 0;

/// PsConvertToGuiThread - promote a thread (large stack + THREADINFO).
pub unsafe fn win32k_convert_to_gui_thread(
    thread_id: u64,
    process_id: u64,
) -> *mut Win32kThreadInfo {
    // Already promoted?
    let mut i = 0;
    while i < WIN32K_MAX_GUI_THREADS {
        if WIN32K_GUI_THREADS[i].active && WIN32K_GUI_THREADS[i].thread_id == thread_id {
            return &mut WIN32K_GUI_THREADS[i] as *mut Win32kThreadInfo;
        }
        i += 1;
    }
    // Allocate slot.
    i = 0;
    while i < WIN32K_MAX_GUI_THREADS {
        if !WIN32K_GUI_THREADS[i].active {
            WIN32K_GUI_THREADS[i].thread_id = thread_id;
            WIN32K_GUI_THREADS[i].process_id = process_id;
            WIN32K_GUI_THREADS[i].message_queue =
                user::user_create_message_queue(thread_id) as *mut c_void;
            WIN32K_GUI_THREADS[i].desktop = user::user_get_default_desktop() as *mut c_void;
            WIN32K_GUI_THREADS[i].window_station =
                user::user_get_winsta0() as *mut c_void;
            WIN32K_GUI_THREADS[i].active = true;
            WIN32K_GUI_THREAD_COUNT += 1;
            win32k_trace!("GuiThread promoted tid={}", thread_id);
            return &mut WIN32K_GUI_THREADS[i] as *mut Win32kThreadInfo;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

pub unsafe fn win32k_find_gui_thread(thread_id: u64) -> *mut Win32kThreadInfo {
    let mut i = 0;
    while i < WIN32K_MAX_GUI_THREADS {
        if WIN32K_GUI_THREADS[i].active && WIN32K_GUI_THREADS[i].thread_id == thread_id {
            return &mut WIN32K_GUI_THREADS[i] as *mut Win32kThreadInfo;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

// ============================================================
// Init (Win32kInitialize /Win32kFullInitialize)
// ============================================================

static mut WIN32K_INITIALIZED: bool = false;

/// Win32kInitialize - base init: handle tables, stations, callbacks.
pub unsafe fn win32k_initialize() -> NtStatus {
    if WIN32K_INITIALIZED {
        return STATUS_SUCCESS;
    }
    let mut st = user::user_init_base();
    if st != STATUS_SUCCESS {
        return st;
    }
    st = gdi::gdi_init_base();
    if st != STATUS_SUCCESS {
        return st;
    }
    st = compositor::dwm_init();
    if st != STATUS_SUCCESS {
        return st;
    }
    st = callback::callback_init();
    if st != STATUS_SUCCESS {
        return st;
    }
    // Register shadow-SSDT services.
    st = user::user_register_services();
    if st != STATUS_SUCCESS {
        return st;
    }
    st = gdi::gdi_register_services();
    if st != STATUS_SUCCESS {
        return st;
    }
    WIN32K_INITIALIZED = true;
    win32k_trace!("Win32k initialized (base+full)");
    STATUS_SUCCESS
}

pub fn win32k_is_initialized() -> bool {
    unsafe { WIN32K_INITIALIZED }
}
