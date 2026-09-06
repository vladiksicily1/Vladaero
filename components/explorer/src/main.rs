#![no_std]
#![no_main]

use core::panic::PanicInfo;

mod cascadia_font;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_ARG_MSLICE: usize = 0x0200_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_READ: usize = SYS_CLASS_FILE | SYS_ARG_MSLICE | 3;
const SYS_YIELD: usize = 158;

// Framebuffer configuration
const FB_BASE: usize = 0x8000_0000;
const SCREEN_WIDTH: usize = 1280;
const SCREEN_HEIGHT: usize = 800;
const STRIDE: usize = 1280;

#[inline(always)]
unsafe fn sys_write(fd: usize, data: &[u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_WRITE => ret,
        in("rdi") fd,
        in("rsi") data.as_ptr(),
        in("rdx") data.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

#[inline(always)]
unsafe fn sys_read(fd: usize, buf: &mut [u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_READ => ret,
        in("rdi") fd,
        in("rsi") buf.as_mut_ptr(),
        in("rdx") buf.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

const SYS_FCNTL: usize = SYS_CLASS_FILE | 55;
const F_SETFL: usize = 4;
const O_NONBLOCK: usize = 0x0004_0000;

#[inline(always)]
unsafe fn sys_fcntl(fd: usize, cmd: usize, arg: usize) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_FCNTL => ret,
        in("rdi") fd,
        in("rsi") cmd,
        in("rdx") arg,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

const SYS_NANOSLEEP: usize = 162;

#[repr(C)]
struct TimeSpec {
    tv_sec: i64,
    tv_nsec: i32,
}

#[inline(always)]
unsafe fn sys_nanosleep(req: &TimeSpec) {
    core::arch::asm!(
        "syscall",
        in("rax") SYS_NANOSLEEP,
        in("rdi") req as *const _ as usize,
        in("rsi") 0,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
}

#[inline(always)]
unsafe fn sys_yield() {
    core::arch::asm!(
        "syscall",
        in("rax") SYS_YIELD,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
}

const SYS_VLADOS_VFS_READ: usize = 0x5646;

#[inline(always)]
unsafe fn sys_vfs_read(path: &str, buf: &mut [u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_READ => ret,
        in("rdi") path.as_ptr() as usize,
        in("rsi") path.len(),
        in("rdx") buf.as_mut_ptr() as usize,
        in("r10") buf.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

const SYS_VLADOS_VFS_LIST: usize = 0x564C;

#[inline(always)]
unsafe fn sys_vfs_list(path: &str, buf: &mut [u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_LIST => ret,
        in("rdi") path.as_ptr() as usize,
        in("rsi") path.len(),
        in("rdx") buf.as_mut_ptr() as usize,
        in("r10") buf.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

const SYS_VLADOS_VFS_WRITE: usize  = 0x5647;
const SYS_VLADOS_VFS_MKDIR: usize  = 0x5648;
const SYS_VLADOS_VFS_UNLINK: usize = 0x5649;
const SYS_VLADOS_VFS_RENAME: usize = 0x564A;
const SYS_VLADOS_VFS_CHMOD: usize  = 0x564E;
const SYS_VLADOS_WHOAMI: usize     = 0x5650;
const SYS_VLADOS_SU: usize         = 0x5651;
const SYS_VLADOS_DRIVES: usize     = 0x5652;

#[inline(always)]
unsafe fn sys_vfs_write(path: &str, data: &[u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_WRITE => ret,
        in("rdi") path.as_ptr() as usize,
        in("rsi") path.len(),
        in("rdx") data.as_ptr() as usize,
        in("r10") data.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

#[inline(always)]
unsafe fn sys_vfs_mkdir(path: &str) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_MKDIR => ret,
        in("rdi") path.as_ptr() as usize,
        in("rsi") path.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

#[inline(always)]
unsafe fn sys_vfs_unlink(path: &str) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_UNLINK => ret,
        in("rdi") path.as_ptr() as usize,
        in("rsi") path.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

#[inline(always)]
unsafe fn sys_vfs_rename(old_path: &str, new_path: &str) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_RENAME => ret,
        in("rdi") old_path.as_ptr() as usize,
        in("rsi") old_path.len(),
        in("rdx") new_path.as_ptr() as usize,
        in("r10") new_path.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

#[inline(always)]
unsafe fn sys_vfs_chmod(path: &str, mode: usize, flags: usize) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_CHMOD => ret,
        in("rdi") path.as_ptr() as usize,
        in("rsi") path.len(),
        in("rdx") mode,
        in("r10") flags,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

#[inline(always)]
unsafe fn sys_whoami(buf: &mut [u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_WHOAMI => ret,
        in("rdi") buf.as_mut_ptr() as usize,
        in("rsi") buf.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

#[inline(always)]
unsafe fn sys_su(uid: usize) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_SU => ret,
        in("rdi") uid,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

#[inline(always)]
unsafe fn sys_drives(buf: &mut [u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_DRIVES => ret,
        in("rdi") buf.as_mut_ptr() as usize,
        in("rsi") buf.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

static mut TERM_EXEC_BUF: [u8; 4096] = [0u8; 4096];

pub struct FileExplorerState {
    pub open: bool,
    pub x: usize,
    pub y: usize,
    pub w: usize,
    pub h: usize,
    pub view_mode: usize, // 0 = This PC, 1 = Directory
    pub current_path: [u8; 128],
    pub current_path_len: usize,
}

impl FileExplorerState {
    pub fn new() -> Self {
        let mut s = Self {
            open: true,
            x: 200,
            y: 45,
            w: 780,
            h: 510,
            view_mode: 0,
            current_path: [0u8; 128],
            current_path_len: 0,
        };
        s.set_path("This PC");
        s
    }

    pub fn set_path(&mut self, path: &str) {
        let bytes = path.as_bytes();
        let len = bytes.len().min(128);
        self.current_path[..len].copy_from_slice(&bytes[..len]);
        self.current_path_len = len;
    }

    pub fn path_str(&self) -> &str {
        core::str::from_utf8(&self.current_path[..self.current_path_len]).unwrap_or("This PC")
    }
}

static mut ACTIVE_FONT: [u8; 16384] = [0u8; 16384];
static mut FONT_LOADED_FROM_VFS: bool = false;

const SYS_VLADOS_MOUSE: usize = 0x564D;

#[repr(C)]
#[derive(Clone, Copy, Default)]
struct MouseData {
    x: i32,
    y: i32,
    buttons: u32,
    seq: u32,
}

#[inline(always)]
unsafe fn sys_get_mouse(out: &mut MouseData) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_MOUSE => ret,
        in("rdi") out as *mut MouseData as usize,
        in("rsi") 0,
        in("rdx") 0,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

const SYS_VLADOS_DISPLAY: usize = 0x5644;

#[inline(always)]
unsafe fn sys_get_display_dims(dims: &mut [u32; 4]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_DISPLAY => ret,
        in("rdi") dims.as_mut_ptr() as usize,
        in("rsi") 0,
        in("rdx") 0,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
}

// GUI Drawing Engine (Direct Framebuffer GOP rendering)
struct Gfx {
    fb: *mut u32,
    width: usize,
    height: usize,
    stride: usize,
}

impl Gfx {
    fn new() -> Self {
        let mut dims = [0u32; 4];
        unsafe {
            sys_get_display_dims(&mut dims);
        }
        let width = if dims[0] > 0 { dims[0] as usize } else { 800 };
        let height = if dims[1] > 0 { dims[1] as usize } else { 600 };
        let stride = if dims[2] > 0 { dims[2] as usize } else { width };

        Gfx {
            fb: FB_BASE as *mut u32,
            width,
            height,
            stride,
        }
    }

    #[inline(always)]
    fn put_pixel(&self, x: usize, y: usize, color: u32) {
        if x < self.width && y < self.height {
            unsafe {
                *self.fb.add(y * self.stride + x) = color;
            }
        }
    }

    fn fill_rect(&self, x: usize, y: usize, w: usize, h: usize, color: u32) {
        let x_end = core::cmp::min(x + w, self.width);
        let y_end = core::cmp::min(y + h, self.height);
        for row in y..y_end {
            let row_ptr = unsafe { self.fb.add(row * self.stride) };
            for col in x..x_end {
                unsafe {
                    *row_ptr.add(col) = color;
                }
            }
        }
    }

    fn draw_rect_outline(&self, x: usize, y: usize, w: usize, h: usize, color: u32) {
        self.fill_rect(x, y, w, 1, color);
        if h > 1 {
            self.fill_rect(x, y + h - 1, w, 1, color);
        }
        self.fill_rect(x, y, 1, h, color);
        if w > 1 {
            self.fill_rect(x + w - 1, y, 1, h, color);
        }
    }

    fn draw_char(&self, x: usize, y: usize, character: char, color: u32, bg_color: u32) {
        if x + 8 <= self.width && y + 16 <= self.height {
            let ch_idx = (character as usize) & 0x7F;
            let cr = ((color >> 16) & 0xFF) as u32;
            let cg = ((color >> 8) & 0xFF) as u32;
            let cb = (color & 0xFF) as u32;

            let br = ((bg_color >> 16) & 0xFF) as u32;
            let bg = ((bg_color >> 8) & 0xFF) as u32;
            let bb = (bg_color & 0xFF) as u32;

            let font_offset = ch_idx * 128;
            for row in 0..16 {
                let py = y + row;
                let dst_ptr = unsafe { self.fb.add(py * self.stride + x) };
                let row_offset = font_offset + row * 8;
                for col in 0..8 {
                    let a = unsafe { ACTIVE_FONT[row_offset + col] as u32 };
                    if a > 0 {
                        let r = ((cr * a) + (br * (255 - a))) / 255;
                        let g = ((cg * a) + (bg * (255 - a))) / 255;
                        let b = ((cb * a) + (bb * (255 - a))) / 255;
                        unsafe {
                            *dst_ptr.add(col) = (r << 16) | (g << 8) | b;
                        }
                    } else if bg_color != 0xFF_000000 {
                        unsafe {
                            *dst_ptr.add(col) = bg_color;
                        }
                    }
                }
            }
        }
    }

    fn draw_text(&self, start_x: usize, start_y: usize, text: &str, color: u32, bg_color: u32) {
        let mut curr_x = start_x;
        let mut curr_y = start_y;
        for ch in text.chars() {
            if ch == '\n' {
                curr_x = start_x;
                curr_y += 16;
                continue;
            }
            self.draw_char(curr_x, curr_y, ch, color, bg_color);
            curr_x += 8;
        }
    }

    fn draw_desktop(&self) {
        // Windows 10 Fluent Dark Hero Gradient
        for row in 0..self.height {
            let progress = (row * 255) / self.height;
            let r = 5 + (progress * 5) / 255;
            let g = 11 + (progress * 16) / 255;
            let b = 22 + (progress * 38) / 255;
            let col = ((r as u32) << 16) | ((g as u32) << 8) | (b as u32);
            let row_ptr = unsafe { self.fb.add(row * self.stride) };
            for c in 0..self.width {
                unsafe {
                    *row_ptr.add(c) = col;
                }
            }
        }

        // Luminous Windows 10 Fluent 4-tile logo on the right side of desktop
        let cx = (self.width * 3) / 4;
        let cy = self.height / 2;
        if cx > 80 && cy > 70 && cx + 80 < self.width && cy + 70 < self.height {
            // Soft atmospheric ambient glow
            self.fill_rect(cx - 75, cy - 70, 160, 150, 0x0A2346);
            self.fill_rect(cx - 65, cy - 60, 140, 130, 0x0F315E);

            // Pane 1 (Top-Left)
            self.fill_rect(cx - 55, cy - 50, 48, 44, 0x0078D7);
            // Pane 2 (Top-Right)
            self.fill_rect(cx + 4, cy - 50, 60, 44, 0x1E90FF);
            // Pane 3 (Bottom-Left)
            self.fill_rect(cx - 55, cy + 4, 48, 48, 0x005A9E);
            // Pane 4 (Bottom-Right)
            self.fill_rect(cx + 4, cy + 4, 60, 48, 0x0078D7);
        }

        // Desktop branding watermark
        self.draw_text(self.width - 240, 16, "VladOS 10 Professional", 0x4A6588, 0x0A162B);
        self.draw_text(self.width - 240, 32, "x86_64 Long Mode (Ring 3)", 0x3A5070, 0x0A162B);
    }

    fn redraw_wallpaper_rect(&self, rx: usize, ry: usize, rw: usize, rh: usize) {
        let x_end = core::cmp::min(rx + rw, self.width);
        let y_end = core::cmp::min(ry + rh, self.height);
        for row in ry..y_end {
            let progress = (row * 255) / self.height;
            let r = 5 + (progress * 5) / 255;
            let g = 11 + (progress * 16) / 255;
            let b = 22 + (progress * 38) / 255;
            let col = ((r as u32) << 16) | ((g as u32) << 8) | (b as u32);
            let row_ptr = unsafe { self.fb.add(row * self.stride) };
            for c in rx..x_end {
                unsafe {
                    *row_ptr.add(c) = col;
                }
            }
        }
    }

    fn draw_taskbar(&self, start_menu_open: bool, cmd_open: bool, explorer_open: bool) {
        let tb_h = 40;
        let tb_y = self.height.saturating_sub(tb_h);

        // Dark acrylic background
        self.fill_rect(0, tb_y, self.width, tb_h, 0x101418);
        // 1px top border
        self.fill_rect(0, tb_y, self.width, 1, 0x2A2E36);

        // Start button: 48px wide
        let sb_bg = if start_menu_open { 0x1F242C } else { 0x101418 };
        self.fill_rect(0, tb_y + 1, 48, tb_h - 1, sb_bg);
        let win_tile_col = if start_menu_open { 0x0078D7 } else { 0xFFFFFF };

        // 4-tile Windows logo
        self.fill_rect(17, tb_y + 13, 6, 6, win_tile_col);
        self.fill_rect(25, tb_y + 13, 6, 6, win_tile_col);
        self.fill_rect(17, tb_y + 21, 6, 6, win_tile_col);
        self.fill_rect(25, tb_y + 21, 6, 6, win_tile_col);

        // Search Bar
        self.fill_rect(54, tb_y + 5, 170, 30, 0x1C2026);
        self.draw_rect_outline(54, tb_y + 5, 170, 30, 0x2D333C);
        self.draw_text(64, tb_y + 12, "Search VladOS...", 0x6E7684, 0x1C2026);

        // CMD Taskbar Button
        let cmd_bg = if cmd_open { 0x1D222A } else { 0x161A20 };
        self.fill_rect(232, tb_y + 3, 44, 34, cmd_bg);
        self.draw_rect_outline(232, tb_y + 3, 44, 34, 0x2D333C);
        self.draw_text(244, tb_y + 12, ">_", 0x00B7C3, cmd_bg);
        if cmd_open {
            self.fill_rect(236, self.height - 2, 36, 2, 0x0078D7);
        }

        // Explorer Taskbar Button (Yellow Folder Icon)
        let exp_bg = if explorer_open { 0x1D222A } else { 0x161A20 };
        self.fill_rect(282, tb_y + 3, 44, 34, exp_bg);
        self.draw_rect_outline(282, tb_y + 3, 44, 34, 0x2D333C);
        self.fill_rect(294, tb_y + 12, 18, 14, 0xFFC83B);
        self.fill_rect(296, tb_y + 10, 7, 2, 0xFFC83B);
        self.fill_rect(298, tb_y + 14, 10, 8, 0x0078D7);
        if explorer_open {
            self.fill_rect(286, self.height - 2, 36, 2, 0x0078D7);
        }

        // System Tray on right
        self.draw_text(self.width - 180, tb_y + 12, "RUS", 0xA0A6B2, 0x101418);
        self.draw_text(self.width - 130, tb_y + 12, "[x]", 0x0078D7, 0x101418);
        self.draw_text(self.width - 85, tb_y + 4, "19:20", 0xFFFFFF, 0x101418);
        self.draw_text(self.width - 95, tb_y + 20, "09/05/2026", 0x88909D, 0x101418);
    }

    fn draw_start_menu(&self) {
        let tb_h = 40;
        let tb_y = self.height.saturating_sub(tb_h);
        let sm_w = 340;
        let sm_h = 440;
        let sm_y = tb_y.saturating_sub(sm_h);

        // Outer border
        self.draw_rect_outline(0, sm_y, sm_w, sm_h, 0x3A414D);
        // Dark acrylic background
        self.fill_rect(1, sm_y + 1, sm_w - 2, sm_h - 2, 0x14171C);

        // Left navigation strip (width 46px)
        self.fill_rect(1, sm_y + 1, 46, sm_h - 2, 0x0E1115);
        self.fill_rect(47, sm_y + 1, 1, sm_h - 2, 0x222730);

        // Hamburger icon
        self.draw_text(16, sm_y + 16, "=", 0xCCD4E0, 0x0E1115);

        // User Avatar 'V' (Vlad)
        self.fill_rect(12, sm_y + 54, 24, 24, 0x0078D7);
        self.draw_text(20, sm_y + 58, "V", 0xFFFFFF, 0x0078D7);

        // Bottom Power icon
        self.fill_rect(12, tb_y - 38, 24, 24, 0x222730);
        self.draw_text(18, tb_y - 34, "O", 0xE81123, 0x222730);

        // Header
        self.draw_text(60, sm_y + 14, "Vlad", 0xFFFFFF, 0x14171C);
        self.draw_text(60, sm_y + 32, "VladOS 10 Professional", 0x7E8794, 0x14171C);
        self.fill_rect(60, sm_y + 50, sm_w.saturating_sub(70), 1, 0x242932);

        // Section: Pinned Tiles
        self.draw_text(60, sm_y + 60, "PINNED TILES", 0x0078D7, 0x14171C);

        // Tile 1: Command Prompt (cmd.vex)
        self.fill_rect(60, sm_y + 82, 126, 68, 0x0078D7);
        self.draw_rect_outline(60, sm_y + 82, 126, 68, 0x1E90FF);
        self.draw_text(68, sm_y + 90, ">_ CMD", 0xFFFFFF, 0x0078D7);
        self.draw_text(68, sm_y + 110, "cmd.vex", 0xC0E0FF, 0x0078D7);
        self.draw_text(68, sm_y + 126, "[Click/1]", 0x80FF80, 0x0078D7);

        // Tile 2: System Info
        self.fill_rect(194, sm_y + 82, 126, 68, 0x008272);
        self.draw_rect_outline(194, sm_y + 82, 126, 68, 0x00A896);
        self.draw_text(202, sm_y + 90, "[PC] SysInfo", 0xFFFFFF, 0x008272);
        self.draw_text(202, sm_y + 110, "VLADOS-PC", 0xB0F0E0, 0x008272);
        self.draw_text(202, sm_y + 126, "x86_64 [2]", 0xB0F0E0, 0x008272);

        // Tile 3: File Explorer (explorer.vex)
        self.fill_rect(60, sm_y + 158, 126, 68, 0x004E8C);
        self.draw_rect_outline(60, sm_y + 158, 126, 68, 0x1E70B8);
        self.draw_text(68, sm_y + 166, "[DIR] Files", 0xFFFFFF, 0x004E8C);
        self.draw_text(68, sm_y + 186, "explorer.vex", 0xC0D8F0, 0x004E8C);
        self.draw_text(68, sm_y + 202, "C:\\VladOS [3]", 0xC0D8F0, 0x004E8C);

        // Tile 4: Storage (VladFS)
        self.fill_rect(194, sm_y + 158, 126, 68, 0x6B2D5C);
        self.draw_rect_outline(194, sm_y + 158, 126, 68, 0x8E3B7B);
        self.draw_text(202, sm_y + 166, "[C:] Storage", 0xFFFFFF, 0x6B2D5C);
        self.draw_text(202, sm_y + 186, "VladFS 32MB", 0xF0D0E8, 0x6B2D5C);
        self.draw_text(202, sm_y + 202, "24 MB Free", 0xF0D0E8, 0x6B2D5C);

        // Section: Recent Apps
        self.draw_text(60, sm_y + 240, "APPLICATIONS", 0x7E8794, 0x14171C);
        self.draw_text(60, sm_y + 262, ">_  cmd.vex          [Press 1]", 0xFFFFFF, 0x14171C);
        self.draw_text(60, sm_y + 284, "[]  explorer.vex     [Running]", 0xFFFFFF, 0x14171C);
        self.draw_text(60, sm_y + 306, "*   vladinit.vex     [PID 1]", 0xFFFFFF, 0x14171C);
        self.draw_text(60, sm_y + 328, "i   system.ini       [Press 4]", 0xA0A6B2, 0x14171C);
        self.draw_text(60, sm_y + 350, "@   vladinc.ru       [Portal]", 0x00B7C3, 0x14171C);

        // Bottom Action Buttons
        self.fill_rect(60, sm_y + 392, 120, 28, 0x222730);
        self.draw_rect_outline(60, sm_y + 392, 120, 28, 0x333944);
        self.draw_text(74, sm_y + 398, "Shut Down", 0xFF8080, 0x222730);

        self.fill_rect(190, sm_y + 392, 120, 28, 0x222730);
        self.draw_rect_outline(190, sm_y + 392, 120, 28, 0x333944);
        self.draw_text(210, sm_y + 398, "Restart", 0x80C0FF, 0x222730);
    }

    fn draw_cmd_window(&self) {
        let wx = if self.width >= 1024 { 356 } else { 16 };
        let wy = 36;
        let ww = self.width.saturating_sub(wx + 16);
        let wh = self.height.saturating_sub(wy + 48);

        // Windows 10 Active Blue Border
        self.draw_rect_outline(wx, wy, ww, wh, 0x0078D7);

        // Dark Title Bar (Height: 32px)
        self.fill_rect(wx + 1, wy + 1, ww - 2, 31, 0x1F1F1F);
        self.fill_rect(wx + 1, wy + 32, ww - 2, 1, 0x2A2A2A);

        // Window Icon + Title Text
        self.draw_text(wx + 12, wy + 8, ">_", 0x00B7C3, 0x1F1F1F);
        self.draw_text(wx + 36, wy + 8, "Command Prompt - cmd.vex [Administrator]", 0xFFFFFF, 0x1F1F1F);

        // Control Buttons on Top-Right
        let ctrl_start = wx + ww.saturating_sub(138);
        // Minimize [-]
        self.fill_rect(ctrl_start, wy + 1, 46, 31, 0x1F1F1F);
        self.fill_rect(ctrl_start + 18, wy + 18, 10, 2, 0xD0D0D0);

        // Maximize [□]
        self.fill_rect(ctrl_start + 46, wy + 1, 46, 31, 0x1F1F1F);
        self.draw_rect_outline(ctrl_start + 63, wy + 11, 12, 12, 0xD0D0D0);

        // Close [X] in Windows 10 Red
        self.fill_rect(ctrl_start + 92, wy + 1, 45, 31, 0xE81123);
        self.draw_text(ctrl_start + 110, wy + 8, "X", 0xFFFFFF, 0xE81123);

        // Dark Terminal Client Area
        self.fill_rect(wx + 2, wy + 33, ww - 4, wh - 35, 0x0C0C0C);
    }

    fn draw_file_explorer(&self, fe: &FileExplorerState) {
        if !fe.open {
            return;
        }
        let wx = fe.x;
        let wy = fe.y;
        let ww = fe.w;
        let wh = fe.h;

        // 1. Accent Window Border
        self.draw_rect_outline(wx, wy, ww, wh, 0x0078D7);

        // 2. Title Bar (Height: 32px)
        self.fill_rect(wx + 1, wy + 1, ww - 2, 31, 0x1F1F1F);
        // Yellow Folder Icon
        self.fill_rect(wx + 12, wy + 9, 16, 14, 0xFFC83B);
        self.fill_rect(wx + 14, wy + 7, 6, 2, 0xFFC83B);
        // Title Text
        let title = if fe.view_mode == 0 {
            "File Explorer - This PC"
        } else if fe.path_str().starts_with("C:") || fe.path_str().starts_with('/') {
            "File Explorer - Local Disk (C:)"
        } else if fe.path_str().starts_with("D:") {
            "File Explorer - Data Drive (D:)"
        } else {
            "File Explorer - CD Drive (E:)"
        };
        self.draw_text(wx + 36, wy + 8, title, 0xFFFFFF, 0x1F1F1F);

        // Window Controls (Minimize, Maximize, Close)
        let ctrl_start = wx + ww.saturating_sub(138);
        self.fill_rect(ctrl_start, wy + 1, 46, 31, 0x1F1F1F);
        self.fill_rect(ctrl_start + 18, wy + 18, 10, 2, 0xD0D0D0); // -

        self.fill_rect(ctrl_start + 46, wy + 1, 46, 31, 0x1F1F1F);
        self.draw_rect_outline(ctrl_start + 63, wy + 11, 12, 12, 0xD0D0D0); // []

        self.fill_rect(ctrl_start + 92, wy + 1, 45, 31, 0xE81123);
        self.draw_text(ctrl_start + 110, wy + 8, "X", 0xFFFFFF, 0xE81123); // X

        // 3. Ribbon Toolbar (Height: 32px)
        self.fill_rect(wx + 1, wy + 32, ww - 2, 32, 0x2B2B2B);
        self.draw_text(wx + 14, wy + 40, "File   Home   Share   View", 0xE0E0E0, 0x2B2B2B);
        self.fill_rect(wx + 54, wy + 62, 38, 2, 0x0078D7); // Active tab line
        self.draw_text(wx + 220, wy + 40, "[+ New folder]  [Delete]  [Properties]", 0x909090, 0x2B2B2B);

        // 4. Address & Search Bar (Height: 34px)
        self.fill_rect(wx + 1, wy + 64, ww - 2, 34, 0x1F1F1F);
        // Nav buttons
        self.draw_text(wx + 12, wy + 72, "<-", 0x0078D7, 0x1F1F1F);
        self.draw_text(wx + 34, wy + 72, "->", 0x666666, 0x1F1F1F);
        self.draw_text(wx + 54, wy + 72, "^", 0x0078D7, 0x1F1F1F);

        // Breadcrumb Address Bar
        let addr_box_w = ww.saturating_sub(260);
        self.fill_rect(wx + 76, wy + 69, addr_box_w, 24, 0x262626);
        self.draw_rect_outline(wx + 76, wy + 69, addr_box_w, 24, 0x3D3D3D);
        let breadcrumb = if fe.view_mode == 0 {
            "This PC"
        } else {
            fe.path_str()
        };
        self.draw_text(wx + 84, wy + 73, breadcrumb, 0xCCCCCC, 0x262626);

        // Search Box
        let search_x = wx + ww.saturating_sub(175);
        self.fill_rect(search_x, wy + 69, 165, 24, 0x262626);
        self.draw_rect_outline(search_x, wy + 69, 165, 24, 0x3D3D3D);
        self.draw_text(search_x + 8, wy + 73, "Search This PC", 0x777777, 0x262626);

        // 5. Client Area Split: Left Sidebar & Right Main
        let content_y = wy + 98;
        let content_h = wh.saturating_sub(122);
        let sidebar_w = 175;

        // --- Left Sidebar (Quick Access & Drive Tree) ---
        self.fill_rect(wx + 1, content_y, sidebar_w, content_h, 0x181818);
        self.draw_text(wx + 10, content_y + 10, "> Quick access", 0x0078D7, 0x181818);
        self.draw_text(wx + 18, content_y + 30, "Desktop", 0xCCCCCC, 0x181818);
        self.draw_text(wx + 18, content_y + 48, "Downloads", 0xCCCCCC, 0x181818);
        self.draw_text(wx + 18, content_y + 66, "Documents", 0xCCCCCC, 0x181818);
        self.fill_rect(wx + 10, content_y + 88, sidebar_w - 20, 1, 0x2A2A2A);

        self.draw_text(wx + 10, content_y + 98, "v This PC", 0x0078D7, 0x181818);

        // Drive C: (VladFS)
        if fe.view_mode == 1 && fe.path_str().starts_with("C:") {
            self.fill_rect(wx + 8, content_y + 116, sidebar_w - 16, 20, 0x2E2E2E);
        }
        self.draw_text(wx + 18, content_y + 118, "Local Disk (C:)", 0xFFFFFF, 0x181818);

        // Drive D: (FAT32/EXT2)
        if fe.view_mode == 1 && fe.path_str().starts_with("D:") {
            self.fill_rect(wx + 8, content_y + 138, sidebar_w - 16, 20, 0x2E2E2E);
        }
        self.draw_text(wx + 18, content_y + 140, "Data Drive (D:)", 0xCCCCCC, 0x181818);

        // Drive E: (ISO9660)
        if fe.view_mode == 1 && fe.path_str().starts_with("E:") {
            self.fill_rect(wx + 8, content_y + 160, sidebar_w - 16, 20, 0x2E2E2E);
        }
        self.draw_text(wx + 18, content_y + 162, "CD Drive (E:)", 0xCCCCCC, 0x181818);

        self.fill_rect(wx + 10, content_y + 186, sidebar_w - 20, 1, 0x2A2A2A);
        self.draw_text(wx + 10, content_y + 196, "> Network", 0x666666, 0x181818);

        // Sidebar vertical line
        self.fill_rect(wx + sidebar_w + 1, content_y, 1, content_h, 0x2A2A2A);

        // --- Right Main Pane ---
        let main_x = wx + sidebar_w + 2;
        let main_w = ww.saturating_sub(sidebar_w + 4);
        self.fill_rect(main_x, content_y, main_w, content_h, 0x101010);

        if fe.view_mode == 0 {
            // "This PC" Mode: Display logical drives with visual progress bars!
            self.draw_text(main_x + 16, content_y + 12, "Devices and drives (3)", 0x888888, 0x101010);
            self.fill_rect(main_x + 16, content_y + 30, main_w.saturating_sub(32), 1, 0x222222);

            // Drive C: Card (VladFS)
            self.fill_rect(main_x + 16, content_y + 40, 260, 68, 0x181818);
            self.draw_rect_outline(main_x + 16, content_y + 40, 260, 68, 0x2A2A2A);
            self.fill_rect(main_x + 26, content_y + 50, 28, 28, 0x0078D7);
            self.draw_text(main_x + 32, content_y + 56, "C:", 0xFFFFFF, 0x0078D7);
            self.draw_text(main_x + 62, content_y + 46, "Local Disk (C:)", 0xFFFFFF, 0x181818);
            self.draw_text(main_x + 62, content_y + 62, "VladFS Volume (VLADOS_SYS)", 0x888888, 0x181818);
            self.fill_rect(main_x + 62, content_y + 80, 190, 8, 0x333333);
            self.fill_rect(main_x + 62, content_y + 80, 142, 8, 0x0078D7); // 24MB free of 32MB
            self.draw_text(main_x + 62, content_y + 92, "24.2 MB free of 32.0 MB", 0x777777, 0x181818);

            // Drive D: Card (FAT32/EXT2)
            self.fill_rect(main_x + 290, content_y + 40, 260, 68, 0x181818);
            self.draw_rect_outline(main_x + 290, content_y + 40, 260, 68, 0x2A2A2A);
            self.fill_rect(main_x + 300, content_y + 50, 28, 28, 0x2D7D9A);
            self.draw_text(main_x + 306, content_y + 56, "D:", 0xFFFFFF, 0x2D7D9A);
            self.draw_text(main_x + 336, content_y + 46, "Data Drive (D:)", 0xFFFFFF, 0x181818);
            self.draw_text(main_x + 336, content_y + 62, "FAT32 / EXT2 (DATA_DRIVE)", 0x888888, 0x181818);
            self.fill_rect(main_x + 336, content_y + 80, 190, 8, 0x333333);
            self.fill_rect(main_x + 336, content_y + 80, 168, 8, 0x0078D7); // 1.84GB free of 2GB
            self.draw_text(main_x + 336, content_y + 92, "1.84 GB free of 2.00 GB", 0x777777, 0x181818);

            // Drive E: Card (ISO9660)
            self.fill_rect(main_x + 16, content_y + 124, 260, 68, 0x181818);
            self.draw_rect_outline(main_x + 16, content_y + 124, 260, 68, 0x2A2A2A);
            self.fill_rect(main_x + 26, content_y + 134, 28, 28, 0x777777);
            self.draw_text(main_x + 32, content_y + 140, "CD", 0xFFFFFF, 0x777777);
            self.draw_text(main_x + 62, content_y + 126, "CD Drive (E:) VLADOS", 0xFFFFFF, 0x181818);
            self.draw_text(main_x + 62, content_y + 146, "ISO9660 Optical Media", 0x888888, 0x181818);
            self.fill_rect(main_x + 62, content_y + 164, 190, 8, 0x333333);
            self.fill_rect(main_x + 62, content_y + 164, 190, 8, 0x777777);
            self.draw_text(main_x + 62, content_y + 176, "0 bytes free of 97.0 MB", 0x777777, 0x181818);

            // Folders Section
            self.draw_text(main_x + 16, content_y + 206, "Folders (4)", 0x888888, 0x101010);
            self.fill_rect(main_x + 16, content_y + 224, main_w.saturating_sub(32), 1, 0x222222);
            self.draw_text(main_x + 24, content_y + 236, "Desktop", 0xCCCCCC, 0x101010);
            self.draw_text(main_x + 150, content_y + 236, "Documents", 0xCCCCCC, 0x101010);
            self.draw_text(main_x + 290, content_y + 236, "Downloads", 0xCCCCCC, 0x101010);
            self.draw_text(main_x + 430, content_y + 236, "Pictures", 0xCCCCCC, 0x101010);
        } else {
            // Folder Contents View (C:, D:, or E:)
            self.fill_rect(main_x, content_y, main_w, 22, 0x181818);
            self.draw_text(main_x + 16, content_y + 4, "Name", 0xAAAAAA, 0x181818);
            self.draw_text(main_x + 190, content_y + 4, "Type", 0xAAAAAA, 0x181818);
            self.draw_text(main_x + 310, content_y + 4, "Size", 0xAAAAAA, 0x181818);
            self.draw_text(main_x + 380, content_y + 4, "Owner", 0xAAAAAA, 0x181818);
            self.draw_text(main_x + 460, content_y + 4, "Permissions", 0xAAAAAA, 0x181818);
            self.fill_rect(main_x, content_y + 22, main_w, 1, 0x282828);

            // Read entries via sys_vfs_list
            static mut EXP_LIST_BUF: [u8; 4096] = [0u8; 4096];
            #[allow(static_mut_refs)]
            let n = unsafe { sys_vfs_list(fe.path_str(), &mut EXP_LIST_BUF) };
            if n > 0 && n <= 4096 {
                #[allow(static_mut_refs)]
                if let Ok(list_str) = core::str::from_utf8(unsafe { &EXP_LIST_BUF[..n] }) {
                    let mut row_y = content_y + 28;
                    for line in list_str.lines() {
                        if row_y + 18 > content_y + content_h {
                            break;
                        }
                        let mut it = line.split_ascii_whitespace();
                        if let (Some(perm), Some(p1)) = (it.next(), it.next()) {
                            let (is_dir, size, owner, name) = if p1 == "<DIR>" {
                                (true, it.next().unwrap_or("0"), it.next().unwrap_or("SYSTEM"), it.next().unwrap_or(""))
                            } else {
                                (false, p1, it.next().unwrap_or("SYSTEM"), it.next().unwrap_or(""))
                            };

                            if !name.is_empty() {
                                let (icon, item_type, name_color) = if is_dir {
                                    ("[DIR]", "File folder", 0xFFD040)
                                } else if name.ends_with(".sys") {
                                    ("[SYS]", "System Driver", 0x60CDFF)
                                } else if name.ends_with(".vex") {
                                    ("[VEX]", "Executable", 0x76B9ED)
                                } else if name.ends_with(".ini") || name.ends_with(".cfg") || name.ends_with(".theme") {
                                    ("[CFG]", "Config / Theme", 0xCCCCCC)
                                } else {
                                    ("[TXT]", "Resource File", 0xEEEEEE)
                                };

                                self.draw_text(main_x + 16, row_y, icon, name_color, 0x101010);
                                self.draw_text(main_x + 60, row_y, name, name_color, 0x101010);
                                self.draw_text(main_x + 190, row_y, item_type, 0x888888, 0x101010);
                                self.draw_text(main_x + 310, row_y, size, 0x888888, 0x101010);
                                self.draw_text(main_x + 380, row_y, owner, 0x888888, 0x101010);
                                self.draw_text(main_x + 460, row_y, perm, 0x666666, 0x101010);

                                row_y += 20;
                            }
                        }
                    }
                }
            } else {
                self.draw_text(main_x + 30, content_y + 40, "This folder is empty or not accessible.", 0x777777, 0x101010);
            }
        }

        // 6. Status Bar (Height: 24px)
        let status_y = wy + wh.saturating_sub(24);
        self.fill_rect(wx + 1, status_y, ww - 2, 23, 0x181818);
        self.fill_rect(wx + 1, status_y, ww - 2, 1, 0x282828);
        let status_msg = if fe.view_mode == 0 {
            "3 drives available | VladFS, FAT32, ISO9660 active | System Healthy"
        } else {
            "Folder View Active | RBAC Access: Authenticated (Vlad / Administrator)"
        };
        self.draw_text(wx + 16, status_y + 4, status_msg, 0x888888, 0x181818);
    }
}

// Authentic Windows 10 Aero Cursor (16x22)
// Sharp aerodynamic arrow with 1px dark border, pure white fill, and soft alpha drop shadow
const CURSOR_W: usize = 16;
const CURSOR_H: usize = 22;
const BG_BUF_SIZE: usize = 28;

static WIN10_CURSOR: [&str; CURSOR_H] = [
    "X               ",
    "XX              ",
    "X.X             ",
    "X..X            ",
    "X...X           ",
    "X....X          ",
    "X.....X         ",
    "X......X        ",
    "X.......X       ",
    "X........X      ",
    "X.........X     ",
    "X..........X    ",
    "X......XXXXX    ",
    "X...X..X        ",
    "X..X X..X       ",
    "X.X  X..X       ",
    "XX    X..X      ",
    "X     X..X      ",
    "       X..X     ",
    "       X..X     ",
    "        XX      ",
    "                ",
];

struct CursorManager {
    x: usize,
    y: usize,
    saved_bg: [u32; BG_BUF_SIZE * BG_BUF_SIZE],
    saved_x: usize,
    saved_y: usize,
    has_saved: bool,
}

impl CursorManager {
    fn new(init_x: usize, init_y: usize) -> Self {
        CursorManager {
            x: init_x,
            y: init_y,
            saved_bg: [0; BG_BUF_SIZE * BG_BUF_SIZE],
            saved_x: 0,
            saved_y: 0,
            has_saved: false,
        }
    }

    fn hide(&mut self, gfx: &Gfx) {
        if self.has_saved {
            for row in 0..BG_BUF_SIZE {
                let py = self.saved_y + row;
                if py >= gfx.height { break; }
                for col in 0..BG_BUF_SIZE {
                    let px = self.saved_x + col;
                    if px >= gfx.width { break; }
                    let color = self.saved_bg[row * BG_BUF_SIZE + col];
                    gfx.put_pixel(px, py, color);
                }
            }
            self.has_saved = false;
        }
    }

    fn show(&mut self, gfx: &Gfx) {
        self.draw_at(gfx, self.x, self.y);
    }

    fn move_to(&mut self, gfx: &Gfx, new_x: usize, new_y: usize) {
        let new_x = new_x.min(gfx.width.saturating_sub(CURSOR_W + 4));
        let new_y = new_y.min(gfx.height.saturating_sub(CURSOR_H + 4));
        if self.has_saved && self.x == new_x && self.y == new_y {
            return;
        }
        self.hide(gfx);
        self.draw_at(gfx, new_x, new_y);
    }

    fn draw_at(&mut self, gfx: &Gfx, mx: usize, my: usize) {
        self.x = mx;
        self.y = my;
        self.saved_x = mx;
        self.saved_y = my;

        // 1. Save underlying background rectangle
        for row in 0..BG_BUF_SIZE {
            let py = my + row;
            for col in 0..BG_BUF_SIZE {
                let px = mx + col;
                if px < gfx.width && py < gfx.height {
                    let color = unsafe { *gfx.fb.add(py * gfx.stride + px) };
                    self.saved_bg[row * BG_BUF_SIZE + col] = color;
                } else {
                    self.saved_bg[row * BG_BUF_SIZE + col] = 0;
                }
            }
        }
        self.has_saved = true;

        // 2. Draw Windows 10 Soft Drop Shadow (multi-level alpha blending)
        for (cy, row_str) in WIN10_CURSOR.iter().enumerate() {
            for (cx, ch) in row_str.chars().enumerate() {
                if ch == 'X' || ch == '.' {
                    let shadow_offsets = [(2, 2, 55), (2, 3, 35), (3, 2, 35), (3, 3, 20)];
                    for &(so_x, so_y, dark_pct) in &shadow_offsets {
                        let sx = mx + cx + so_x;
                        let sy = my + cy + so_y;
                        if sx < gfx.width && sy < gfx.height {
                            let curr = unsafe { *gfx.fb.add(sy * gfx.stride + sx) };
                            let r = ((curr >> 16) & 0xFF) * (100 - dark_pct) / 100;
                            let g = ((curr >> 8) & 0xFF) * (100 - dark_pct) / 100;
                            let b = (curr & 0xFF) * (100 - dark_pct) / 100;
                            gfx.put_pixel(sx, sy, (r << 16) | (g << 8) | b);
                        }
                    }
                }
            }
        }

        // 3. Draw Windows 10 Crisp Aero Arrow
        for (cy, row_str) in WIN10_CURSOR.iter().enumerate() {
            for (cx, ch) in row_str.chars().enumerate() {
                let px = mx + cx;
                let py = my + cy;
                if ch == 'X' {
                    gfx.put_pixel(px, py, 0x0A0A0A);
                } else if ch == '.' {
                    gfx.put_pixel(px, py, 0xFFFFFF);
                }
            }
        }
    }
}

// Interactive Terminal State inside CMD Window
struct TerminalState {
    history: [[u8; 128]; 32],
    history_lens: [usize; 32],
    history_count: usize,
    input_buf: [u8; 128],
    input_len: usize,
}

impl TerminalState {
    fn new() -> Self {
        let mut term = TerminalState {
            history: [[0; 128]; 32],
            history_lens: [0; 32],
            history_count: 0,
            input_buf: [0; 128],
            input_len: 0,
        };
        term.add_line("VladOS [Version 10.0.22000.1]");
        term.add_line("(c) 2026 Vlad Corporation. All rights reserved.");
        term.add_line("");
        term.add_line("Windows 10 GUI Desktop active (explorer.vex).");
        term.add_line("Type HELP for available commands, or START to toggle Start Menu.");
        term.add_line("");
        term
    }

    fn add_line(&mut self, s: &str) {
        if self.history_count >= 32 {
            // Shift up
            for i in 0..31 {
                self.history[i] = self.history[i + 1];
                self.history_lens[i] = self.history_lens[i + 1];
            }
            self.history_count = 31;
        }
        let bytes = s.as_bytes();
        let len = bytes.len().min(128);
        self.history[self.history_count][..len].copy_from_slice(&bytes[..len]);
        self.history_lens[self.history_count] = len;
        self.history_count += 1;
    }

    fn render(&self, gfx: &Gfx) {
        let wx = if gfx.width >= 1024 { 356 } else { 16 };
        let wy = 36;
        let vx = wx + 8;
        let vy = wy + 40;
        let ww = gfx.width.saturating_sub(wx + 16);
        let wh = gfx.height.saturating_sub(wy + 48);

        // Clear terminal area
        gfx.fill_rect(wx + 2, wy + 33, ww - 4, wh - 35, 0x0C0C0C);

        // Draw history lines
        let max_visible = (wh.saturating_sub(70)) / 18;
        let start_idx = if self.history_count > max_visible {
            self.history_count - max_visible
        } else {
            0
        };

        let mut curr_y = vy;
        for i in start_idx..self.history_count {
            let len = self.history_lens[i];
            if let Ok(line_str) = core::str::from_utf8(&self.history[i][..len]) {
                gfx.draw_text(vx, curr_y, line_str, 0xCCCCCC, 0x0C0C0C);
            }
            curr_y += 18;
        }

        // Draw prompt and current input
        gfx.draw_text(vx, curr_y, "C:\\VladOS\\System32> ", 0xFFFFFF, 0x0C0C0C);
        let prompt_width = 20 * 8;
        if self.input_len > 0 {
            if let Ok(input_str) = core::str::from_utf8(&self.input_buf[..self.input_len]) {
                gfx.draw_text(vx + prompt_width, curr_y, input_str, 0xFFFFFF, 0x0C0C0C);
            }
        }
        // Blinking cursor
        let cursor_x = vx + prompt_width + self.input_len * 8;
        gfx.fill_rect(cursor_x, curr_y, 8, 16, 0x0078D7);
    }

    fn execute_command(
        &mut self,
        gfx: &Gfx,
        start_menu_open: &mut bool,
        window_open: &mut bool,
        fe: &mut FileExplorerState,
    ) {
        let len = self.input_len;
        if len == 0 {
            self.add_line("C:\\VladOS\\System32> ");
            return;
        }

        let mut prompt_line = [0u8; 128];
        let p_prefix = b"C:\\VladOS\\System32> ";
        prompt_line[..p_prefix.len()].copy_from_slice(p_prefix);
        let copy_len = len.min(128 - p_prefix.len());
        prompt_line[p_prefix.len()..p_prefix.len() + copy_len].copy_from_slice(&self.input_buf[..copy_len]);
        if let Ok(s) = core::str::from_utf8(&prompt_line[..p_prefix.len() + copy_len]) {
            self.add_line(s);
        }

        let mut cmd_bytes = [0u8; 128];
        cmd_bytes[..len].copy_from_slice(&self.input_buf[..len]);
        self.input_len = 0;

        if let Ok(cmd) = core::str::from_utf8(&cmd_bytes[..len]) {
            let cmd = cmd.trim();
            if eq_ignore_ascii_case(cmd, "help") {
                self.add_line("VladOS Command Prompt Reference:");
                self.add_line("DIR [path]   - List directory (VladFS C:, FAT32 D:, ISO E:)");
                self.add_line("DRIVES       - List all mounted volumes");
                self.add_line("WHOAMI       - Display current user context, SID and privileges");
                self.add_line("NET USER     - List system security accounts");
                self.add_line("SU <user>    - Switch user (SYSTEM, Vlad, User, Guest)");
                self.add_line("TYPE <file>  - Display contents of a text file");
                self.add_line("ECHO txt [> f]- Print text or write to file");
                self.add_line("MKDIR <path> - Create a new directory");
                self.add_line("DEL <path>   - Delete a file");
                self.add_line("REN <o> <n>  - Rename file or directory");
                self.add_line("CHMOD <m> <p>- Change file permissions");
                self.add_line("EXPLORER     - Toggle / bring File Explorer to front");
                self.add_line("MENU         - Toggle Start Menu");
                self.add_line("SYSINFO      - Hardware & OS specifications");
                self.add_line("CLS          - Clear screen");
                self.add_line("VER          - Display OS version");
                self.add_line("EXIT         - Close CMD window");
            } else if eq_ignore_ascii_case(cmd, "ver") {
                self.add_line("VladOS [Version 10.0.19045.3803]");
                self.add_line("(c) 2026 Vlad Inc. All rights reserved.");
            } else if eq_ignore_ascii_case(cmd, "cls") {
                self.history_count = 0;
            } else if eq_ignore_ascii_case(cmd, "exit") {
                *window_open = false;
                let wx = if gfx.width >= 1024 { 356 } else { 16 };
                let wy = 36;
                let ww = gfx.width.saturating_sub(wx + 16);
                let wh = gfx.height.saturating_sub(wy + 48);
                gfx.redraw_wallpaper_rect(wx, wy, ww, wh);
                if fe.open {
                    gfx.draw_file_explorer(fe);
                }
                gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
                self.add_line("[CMD Window Closed]");
            } else if eq_ignore_ascii_case(cmd, "whoami") {
                #[allow(static_mut_refs)]
                let n = unsafe { sys_whoami(&mut TERM_EXEC_BUF) };
                if n > 0 && n <= 4096 {
                    #[allow(static_mut_refs)]
                    if let Ok(s) = core::str::from_utf8(unsafe { &TERM_EXEC_BUF[..n] }) {
                        for line in s.lines() {
                            self.add_line(line);
                        }
                    }
                }
            } else if eq_ignore_ascii_case(cmd, "net user") {
                self.add_line("User Accounts for \\\\VLADOS-PC");
                self.add_line("--------------------------------------------------");
                self.add_line("SYSTEM                   Vlad                     User");
                self.add_line("Guest                    DefaultAccount");
                self.add_line("The command completed successfully.");
            } else if starts_with_ignore_case(cmd, "su ") || starts_with_ignore_case(cmd, "runas ") {
                let arg = if starts_with_ignore_case(cmd, "su ") {
                    cmd[3..].trim()
                } else {
                    cmd[6..].trim()
                };
                let uid = if eq_ignore_ascii_case(arg, "system") || eq_ignore_ascii_case(arg, "root") || arg == "0" {
                    0
                } else if eq_ignore_ascii_case(arg, "vlad") || eq_ignore_ascii_case(arg, "admin") || arg == "1000" {
                    1000
                } else if eq_ignore_ascii_case(arg, "user") || arg == "1001" {
                    1001
                } else if eq_ignore_ascii_case(arg, "guest") || arg == "1002" {
                    1002
                } else {
                    usize::MAX
                };

                if uid == usize::MAX {
                    self.add_line("Account not found. Available: SYSTEM, Vlad, User, Guest");
                } else {
                    let ret = unsafe { sys_su(uid) };
                    if ret == 0 {
                        let mut msg_buf = [0u8; 64];
                        let prefix = b"Switched security context to: ";
                        msg_buf[..prefix.len()].copy_from_slice(prefix);
                        let arg_len = arg.len().min(64 - prefix.len());
                        msg_buf[prefix.len()..prefix.len() + arg_len].copy_from_slice(&arg.as_bytes()[..arg_len]);
                        if let Ok(m) = core::str::from_utf8(&msg_buf[..prefix.len() + arg_len]) {
                            self.add_line(m);
                        }
                    } else {
                        self.add_line("Failed to switch user: Permission Denied");
                    }
                }
            } else if eq_ignore_ascii_case(cmd, "drives") || eq_ignore_ascii_case(cmd, "wmic logicaldisk") {
                self.add_line("Mounted Logical Drives & Volumes:");
                self.add_line("--------------------------------------------------");
                #[allow(static_mut_refs)]
                let n = unsafe { sys_drives(&mut TERM_EXEC_BUF) };
                if n > 0 && n <= 4096 {
                    #[allow(static_mut_refs)]
                    if let Ok(s) = core::str::from_utf8(unsafe { &TERM_EXEC_BUF[..n] }) {
                        for line in s.lines() {
                            self.add_line(line);
                        }
                    }
                }
            } else if eq_ignore_ascii_case(cmd, "sysinfo") {
                self.add_line("Host Name:                 VLADOS-PC");
                self.add_line("OS Name:                   VladOS 10 Professional");
                self.add_line("OS Version:                10.0.19045 N/A Build 19045");
                self.add_line("System Manufacturer:       Vlad Corporation");
                self.add_line("System Model:              VladBook Pro x86_64");
                self.add_line("Processor(s):              1 Processor(s) Installed. [x86_64 Family]");
                self.add_line("BIOS Version:              EDK2 / OVMF UEFI 2.70");
                self.add_line("Mouse Subsystem:           USB Tablet + PS/2 Hardware Sync");
                self.add_line("Storage / VFS:             VladFS + FAT32 (D:) + ISO9660 (E:)");
            } else if eq_ignore_ascii_case(cmd, "dir") || starts_with_ignore_case(cmd, "dir ") {
                let dir_target = if starts_with_ignore_case(cmd, "dir ") {
                    cmd[4..].trim()
                } else {
                    "C:\\"
                };
                self.add_line(" Directory of:");
                self.add_line(dir_target);
                self.add_line("");
                #[allow(static_mut_refs)]
                let n = unsafe { sys_vfs_list(dir_target, &mut TERM_EXEC_BUF) };
                if n > 0 && n <= 4096 {
                    #[allow(static_mut_refs)]
                    if let Ok(s) = core::str::from_utf8(unsafe { &TERM_EXEC_BUF[..n] }) {
                        for line in s.lines() {
                            self.add_line(line);
                        }
                    }
                } else {
                    self.add_line("File Not Found or Access Denied.");
                }
            } else if starts_with_ignore_case(cmd, "type ") {
                let p = cmd[5..].trim();
                #[allow(static_mut_refs)]
                let n = unsafe { sys_vfs_read(p, &mut TERM_EXEC_BUF) };
                if n > 0 && n <= 4096 {
                    #[allow(static_mut_refs)]
                    if let Ok(s) = core::str::from_utf8(unsafe { &TERM_EXEC_BUF[..n] }) {
                        for line in s.lines() {
                            self.add_line(line);
                        }
                    }
                } else {
                    self.add_line("The system cannot find the file specified or Access Denied.");
                }
            } else if starts_with_ignore_case(cmd, "mkdir ") || starts_with_ignore_case(cmd, "md ") {
                let p = if starts_with_ignore_case(cmd, "mkdir ") {
                    cmd[6..].trim()
                } else {
                    cmd[3..].trim()
                };
                let ret = unsafe { sys_vfs_mkdir(p) };
                if ret > 0 {
                    self.add_line("A subdirectory was created successfully.");
                    if fe.open {
                        gfx.draw_file_explorer(fe);
                    }
                } else {
                    self.add_line("A subdirectory or file already exists or Access Denied.");
                }
            } else if starts_with_ignore_case(cmd, "del ") || starts_with_ignore_case(cmd, "rm ") {
                let p = if starts_with_ignore_case(cmd, "del ") {
                    cmd[4..].trim()
                } else {
                    cmd[3..].trim()
                };
                let ret = unsafe { sys_vfs_unlink(p) };
                if ret == 0 {
                    self.add_line("File deleted successfully.");
                    if fe.open {
                        gfx.draw_file_explorer(fe);
                    }
                } else {
                    self.add_line("Could Not Find or Access Denied.");
                }
            } else if starts_with_ignore_case(cmd, "ren ") {
                let parts = cmd[4..].trim();
                if let Some(idx) = parts.find(' ') {
                    let old_p = parts[..idx].trim();
                    let new_p = parts[idx + 1..].trim();
                    let ret = unsafe { sys_vfs_rename(old_p, new_p) };
                    if ret == 0 {
                        self.add_line("File renamed successfully.");
                        if fe.open {
                            gfx.draw_file_explorer(fe);
                        }
                    } else {
                        self.add_line("The duplicate file name exists, or file cannot be found.");
                    }
                } else {
                    self.add_line("Syntax: REN <old_path> <new_name>");
                }
            } else if starts_with_ignore_case(cmd, "chmod ") {
                let parts = cmd[6..].trim();
                if let Some(idx) = parts.find(' ') {
                    let mode_str = parts[..idx].trim();
                    let path = parts[idx + 1..].trim();
                    let mut mode = 0usize;
                    for b in mode_str.bytes() {
                        if b >= b'0' && b <= b'7' {
                            mode = (mode << 3) | (b - b'0') as usize;
                        }
                    }
                    let ret = unsafe { sys_vfs_chmod(path, mode, 0) };
                    if ret == 0 {
                        self.add_line("Permissions updated successfully.");
                        if fe.open {
                            gfx.draw_file_explorer(fe);
                        }
                    } else {
                        self.add_line("Failed to update permissions (Access Denied).");
                    }
                } else {
                    self.add_line("Syntax: CHMOD <octal_mode> <path>");
                }
            } else if eq_ignore_ascii_case(cmd, "menu") {
                *start_menu_open = !*start_menu_open;
                if *start_menu_open {
                    gfx.draw_start_menu();
                    self.add_line("[Start Menu opened]");
                } else {
                    let tb_y = gfx.height.saturating_sub(40);
                    let sm_h = 440;
                    let sm_y = tb_y.saturating_sub(sm_h);
                    gfx.redraw_wallpaper_rect(0, sm_y, 342, sm_h);
                    self.add_line("[Start Menu closed]");
                }
                gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
            } else if eq_ignore_ascii_case(cmd, "explorer") {
                fe.open = true;
                gfx.draw_file_explorer(fe);
                gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
                self.add_line("[File Explorer opened]");
            } else if starts_with_ignore_case(cmd, "echo ") {
                let rest = cmd[5..].trim();
                if let Some(idx) = rest.find('>') {
                    let text = rest[..idx].trim();
                    let path = rest[idx + 1..].trim();
                    let ret = unsafe { sys_vfs_write(path, text.as_bytes()) };
                    if ret > 0 {
                        self.add_line("File written successfully.");
                        if fe.open {
                            gfx.draw_file_explorer(fe);
                        }
                    } else {
                        self.add_line("Access is denied or volume is write-protected.");
                    }
                } else {
                    let mut echo_buf = [0u8; 128];
                    let echo_len = rest.len().min(128);
                    echo_buf[..echo_len].copy_from_slice(rest.as_bytes()[..echo_len].as_ref());
                    if let Ok(echo_str) = core::str::from_utf8(&echo_buf[..echo_len]) {
                        self.add_line(echo_str);
                    }
                }
            } else {
                self.add_line("Command not recognized. Type HELP for command list.");
            }
        }
        self.input_len = 0;
    }
}


fn handle_mouse_click(
    gfx: &Gfx,
    term: &mut TerminalState,
    start_menu_open: &mut bool,
    window_open: &mut bool,
    fe: &mut FileExplorerState,
    mx: usize,
    my: usize,
) {
    let tb_y = gfx.height.saturating_sub(40);

    // 1. Taskbar Start Button (x: 0..48, y: 760..800)
    if my >= tb_y && mx < 48 {
        *start_menu_open = !*start_menu_open;
        if *start_menu_open {
            gfx.draw_start_menu();
            term.add_line("[Mouse Click: Start Menu opened]");
        } else {
            let sm_h = 440;
            let sm_y = tb_y.saturating_sub(sm_h);
            gfx.redraw_wallpaper_rect(0, sm_y, 342, sm_h);
            term.add_line("[Mouse Click: Start Menu closed]");
        }
        gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
        if *window_open {
            term.render(gfx);
        }
        if fe.open {
            gfx.draw_file_explorer(fe);
        }
        return;
    }

    // 2. Taskbar App Icon (>_ CMD) (x: 230..276, y: 760..800)
    if my >= tb_y && mx >= 230 && mx <= 276 {
        *window_open = !*window_open;
        if *window_open {
            gfx.draw_cmd_window();
            term.add_line("[Mouse Click: CMD window restored]");
            term.render(gfx);
        } else {
            let wx = if gfx.width >= 1024 { 356 } else { 16 };
            let wy = 36;
            let ww = gfx.width.saturating_sub(wx + 16);
            let wh = gfx.height.saturating_sub(wy + 48);
            gfx.redraw_wallpaper_rect(wx, wy, ww, wh);
            term.add_line("[Mouse Click: CMD window minimized]");
        }
        if fe.open {
            gfx.draw_file_explorer(fe);
        }
        gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
        return;
    }

    // 2b. Taskbar App Icon (File Explorer []) (x: 280..326, y: 760..800)
    if my >= tb_y && mx >= 280 && mx <= 326 {
        fe.open = !fe.open;
        if fe.open {
            gfx.draw_file_explorer(fe);
            term.add_line("[Mouse Click: File Explorer opened]");
        } else {
            gfx.redraw_wallpaper_rect(fe.x, fe.y, fe.w, fe.h);
            if *window_open {
                gfx.draw_cmd_window();
                term.render(gfx);
            }
            term.add_line("[Mouse Click: File Explorer minimized]");
        }
        gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
        return;
    }

    // 2c. File Explorer Window Clicks (if open)
    if fe.open && mx >= fe.x && mx <= fe.x + fe.w && my >= fe.y && my <= fe.y + fe.h {
        // Close Button [X]
        if mx >= fe.x + fe.w - 45 && my <= fe.y + 32 {
            fe.open = false;
            gfx.redraw_wallpaper_rect(fe.x, fe.y, fe.w, fe.h);
            if *window_open {
                gfx.draw_cmd_window();
                term.render(gfx);
            }
            gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
            term.add_line("[Mouse Click: File Explorer closed]");
            return;
        }

        // Ribbon Buttons: [+ New folder] [Delete] [Properties]
        if my >= fe.y + 32 && my <= fe.y + 64 {
            if mx >= fe.x + 215 && mx <= fe.x + 325 {
                let cur = fe.path_str();
                if cur.eq_ignore_ascii_case("C:\\VladOS") {
                    unsafe { sys_vfs_mkdir("C:\\VladOS\\NewFolder") };
                } else if cur.eq_ignore_ascii_case("C:\\VladOS\\System32") {
                    unsafe { sys_vfs_mkdir("C:\\VladOS\\System32\\NewFolder") };
                } else {
                    unsafe { sys_vfs_mkdir("C:\\NewFolder") };
                }
                term.add_line("[Ribbon: Created directory NewFolder]");
                gfx.draw_file_explorer(fe);
                if *window_open {
                    term.render(gfx);
                }
                return;
            } else if mx >= fe.x + 335 && mx <= fe.x + 405 {
                term.add_line("[Ribbon: [Delete] action - select an item to delete]");
                if *window_open {
                    term.render(gfx);
                }
                return;
            } else if mx >= fe.x + 415 && mx <= fe.x + 515 {
                let cur = fe.path_str();
                term.add_line("--- Volume / Path Properties ---");
                if cur.starts_with("C:") || cur.starts_with('/') {
                    term.add_line("Location: C: (VladFS Primary System Volume)");
                    term.add_line("Capacity: 32.0 MB (24.2 MB free) | Format: VladFS v1.0");
                    term.add_line("Security: SYSTEM / Administrators (Full Access)");
                } else if cur.starts_with("D:") {
                    term.add_line("Location: D: (Secondary Data Volume)");
                    term.add_line("Capacity: 2.00 GB (1.84 GB free) | Format: FAT32 / EXT2");
                } else {
                    term.add_line("Location: E: (VladOS Installation Media)");
                    term.add_line("Capacity: 97.0 MB (Read-Only) | Format: ISO9660");
                }
                if *window_open {
                    term.render(gfx);
                }
                return;
            }
        }

        // Back Arrow [<-] or Up [^]
        if mx >= fe.x + 8 && mx <= fe.x + 50 && my >= fe.y + 68 && my <= fe.y + 94 {
            let cur = fe.path_str();
            if cur.eq_ignore_ascii_case("C:\\VladOS\\System32\\drivers") {
                fe.set_path("C:\\VladOS\\System32");
            } else if cur.eq_ignore_ascii_case("C:\\VladOS\\System32") || cur.eq_ignore_ascii_case("C:\\VladOS\\Resources") {
                fe.set_path("C:\\VladOS");
            } else {
                fe.view_mode = 0;
                fe.set_path("This PC");
            }
            gfx.draw_file_explorer(fe);
            return;
        }

        // Sidebar items
        let sidebar_top = fe.y + 98;
        if mx <= fe.x + 175 {
            // Quick Access Desktop
            if my >= sidebar_top + 22 && my <= sidebar_top + 38 {
                fe.view_mode = 1;
                fe.set_path("C:\\Users\\Vlad\\Desktop");
                gfx.draw_file_explorer(fe);
                return;
            }
            // Quick Access Downloads
            if my >= sidebar_top + 39 && my <= sidebar_top + 54 {
                fe.view_mode = 1;
                fe.set_path("C:\\Users\\Vlad\\Downloads");
                gfx.draw_file_explorer(fe);
                return;
            }
            // Quick Access Documents
            if my >= sidebar_top + 55 && my <= sidebar_top + 72 {
                fe.view_mode = 1;
                fe.set_path("C:\\Users\\Vlad\\Documents");
                gfx.draw_file_explorer(fe);
                return;
            }
            // "v This PC"
            if my >= sidebar_top + 80 && my <= sidebar_top + 98 {
                fe.view_mode = 0;
                fe.set_path("This PC");
                gfx.draw_file_explorer(fe);
                return;
            }
            // Local Disk (C:)
            if my >= sidebar_top + 99 && my <= sidebar_top + 116 {
                fe.view_mode = 1;
                fe.set_path("C:\\VladOS");
                gfx.draw_file_explorer(fe);
                return;
            }
            // Data Drive (D:)
            if my >= sidebar_top + 117 && my <= sidebar_top + 134 {
                fe.view_mode = 1;
                fe.set_path("D:\\");
                gfx.draw_file_explorer(fe);
                return;
            }
            // CD Drive (E:)
            if my >= sidebar_top + 135 && my <= sidebar_top + 155 {
                fe.view_mode = 1;
                fe.set_path("E:\\");
                gfx.draw_file_explorer(fe);
                return;
            }
        }

        // Main Pane Drives (when in This PC mode)
        if fe.view_mode == 0 {
            let main_x = fe.x + 175;
            // Drive C Card
            if mx >= main_x + 16 && mx <= main_x + 276 && my >= sidebar_top + 40 && my <= sidebar_top + 108 {
                fe.view_mode = 1;
                fe.set_path("C:\\VladOS");
                gfx.draw_file_explorer(fe);
                return;
            }
            // Drive D Card
            if mx >= main_x + 290 && mx <= main_x + 550 && my >= sidebar_top + 40 && my <= sidebar_top + 108 {
                fe.view_mode = 1;
                fe.set_path("D:\\");
                gfx.draw_file_explorer(fe);
                return;
            }
            // Drive E Card
            if mx >= main_x + 16 && mx <= main_x + 276 && my >= sidebar_top + 124 && my <= sidebar_top + 192 {
                fe.view_mode = 1;
                fe.set_path("E:\\");
                gfx.draw_file_explorer(fe);
                return;
            }
        } else {
            // In Directory View (fe.view_mode == 1)
            let main_x = fe.x + 175;
            if mx >= main_x && my >= sidebar_top + 28 {
                let row_idx = (my - (sidebar_top + 28)) / 18;
                let cur = fe.path_str();
                if cur.eq_ignore_ascii_case("C:\\VladOS") {
                    if row_idx == 0 || row_idx == 1 {
                        // System32
                        fe.set_path("C:\\VladOS\\System32");
                        gfx.draw_file_explorer(fe);
                        return;
                    } else if row_idx == 2 {
                        // Resources
                        fe.set_path("C:\\VladOS\\Resources");
                        gfx.draw_file_explorer(fe);
                        return;
                    }
                } else if cur.eq_ignore_ascii_case("C:\\VladOS\\System32") {
                    if row_idx == 0 || row_idx == 1 || row_idx == 2 {
                        // drivers
                        fe.set_path("C:\\VladOS\\System32\\drivers");
                        gfx.draw_file_explorer(fe);
                        return;
                    }
                }
            }
        }
        return;
    }

    // 3. Window Titlebar Close Button [ X ] (x: wx+ww-45..wx+ww, y: wy..wy+32)
    let wx = if gfx.width >= 1024 { 356 } else { 16 };
    let wy = 36;
    let ww = gfx.width.saturating_sub(wx + 16);
    let wh = gfx.height.saturating_sub(wy + 48);
    if *window_open && my >= wy && my <= wy + 32 {
        let close_start = wx + ww.saturating_sub(45);
        if mx >= close_start && mx <= wx + ww {
            *window_open = false;
            gfx.redraw_wallpaper_rect(wx, wy, ww, wh);
            if fe.open {
                gfx.draw_file_explorer(fe);
            }
            gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
            term.add_line("[Mouse Click: CMD window closed]");
            return;
        }
    }

    // 4. Start Menu Pinned Tiles (if open)
    if *start_menu_open {
        let sm_h = 440;
        let sm_y = tb_y.saturating_sub(sm_h);
        if mx < 340 && my >= sm_y && my < tb_y {
            // Tile 1: CMD
            if mx >= 48 && mx <= 148 && my >= sm_y + 110 && my <= sm_y + 160 {
                if !*window_open {
                    *window_open = true;
                    gfx.draw_cmd_window();
                }
                term.add_line("[Mouse Click: CMD Tile activated]");
                term.render(gfx);
            }
            // Tile 2: SysInfo
            else if mx >= 152 && mx <= 252 && my >= sm_y + 110 && my <= sm_y + 160 {
                if !*window_open {
                    *window_open = true;
                    gfx.draw_cmd_window();
                }
                term.add_line("C:\\VladOS\\System32> sysinfo");
                term.add_line("Host Name:                 VLADOS-PC");
                term.add_line("OS Name:                   VladOS 10 Professional");
                term.add_line("Architecture:              x86_64 Long Mode (64-bit)");
                term.add_line("Desktop Shell:             explorer.vex (Windows 10 Fluent Dark)");
                term.add_line("Mouse Subsystem:           USB Tablet / PS/2 Active (Windows 10 Aero)");
                term.add_line("Display:                   1280x800x32 Linear GOP Framebuffer");
                let font_source = if unsafe { FONT_LOADED_FROM_VFS } {
                    "Font Subsystem:            VladFS (/VladOS/Resources/Fonts/CascadiaMono.fnt)"
                } else {
                    "Font Subsystem:            Builtin Embedded Fallback"
                };
                term.add_line(font_source);
                term.render(gfx);
            }
            // Tile 3: Files -> Open Windows 10 File Explorer at C:\VladOS!
            else if mx >= 48 && mx <= 148 && my >= sm_y + 168 && my <= sm_y + 218 {
                fe.open = true;
                fe.view_mode = 1;
                fe.set_path("C:\\VladOS");
                gfx.draw_file_explorer(fe);
                gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
                term.add_line("[Start Menu: File Explorer opened at C:\\VladOS]");
            }
            // Tile 4: Storage C: -> Open Windows 10 File Explorer at This PC!
            else if mx >= 152 && mx <= 252 && my >= sm_y + 168 && my <= sm_y + 218 {
                fe.open = true;
                fe.view_mode = 0;
                fe.set_path("This PC");
                gfx.draw_file_explorer(fe);
                gfx.draw_taskbar(*start_menu_open, *window_open, fe.open);
                term.add_line("[Start Menu: File Explorer opened at This PC]");
            }
        }
    }
}

fn eq_ignore_ascii_case(a: &str, b: &str) -> bool {
    if a.len() != b.len() {
        return false;
    }
    for (ca, cb) in a.bytes().zip(b.bytes()) {
        if ca.to_ascii_lowercase() != cb.to_ascii_lowercase() {
            return false;
        }
    }
    true
}

fn starts_with_ignore_case(a: &str, prefix: &str) -> bool {
    if a.len() < prefix.len() {
        return false;
    }
    eq_ignore_ascii_case(&a[..prefix.len()], prefix)
}

const TAB_COMMANDS: &[&str] = &[
    "cls",
    "dir",
    "echo",
    "exit",
    "explorer",
    "help",
    "menu",
    "mouse",
    "start",
    "sysinfo",
    "type",
    "ver",
];

#[no_mangle]
pub extern "C" fn _start() -> ! {
    // 0. Load Font from VladFS filesystem (/VladOS/Resources/Fonts/CascadiaMono.fnt)
    unsafe {
        let n = sys_vfs_read("/VladOS/Resources/Fonts/CascadiaMono.fnt", &mut ACTIVE_FONT);
        if n == 16384 {
            FONT_LOADED_FROM_VFS = true;
        } else {
            ACTIVE_FONT.copy_from_slice(&cascadia_font::FONT_AA);
        }
    }

    let gfx = Gfx::new();

    // 1. Draw Full Authentic Windows 10 GUI Desktop
    gfx.draw_desktop();
    let mut window_open = false;
    let mut file_explorer = FileExplorerState::new();
    file_explorer.open = true;
    gfx.draw_file_explorer(&file_explorer);
    let mut start_menu_open = false;
    gfx.draw_taskbar(start_menu_open, window_open, file_explorer.open);
    if start_menu_open {
        gfx.draw_start_menu();
    }

    let mut term = TerminalState::new();
    if window_open {
        term.render(&gfx);
    }

    // 2. Initialize Windows 10 Aero Cursor Manager
    let mut cursor = CursorManager::new(450, 260);
    cursor.show(&gfx);

    // Make stdin (fd 0) non-blocking so keyboard polling never stalls mouse / touch tracking!
    unsafe {
        sys_fcntl(0, F_SETFL, O_NONBLOCK);
    }

    let mut last_mouse_seq = 0u32;
    let mut last_buttons = 0u32;
    let mut read_buf = [0u8; 16];
    let mut esc_state = 0u8; // 0 = normal, 1 = saw 0x1B, 2 = saw '['

    loop {
        let mut had_event = false;

        // 3. Poll Hardware PS/2 & USB Tablet Mouse Subsystem via SYS_VLADOS_MOUSE
        let mut mouse = MouseData::default();
        let seq = unsafe { sys_get_mouse(&mut mouse) } as u32;
        if seq != 0 && seq != last_mouse_seq {
            had_event = true;
            last_mouse_seq = seq;
            cursor.move_to(&gfx, mouse.x as usize, mouse.y as usize);

            // Detect Left Button Click
            if (mouse.buttons & 1) != 0 && (last_buttons & 1) == 0 {
                cursor.hide(&gfx);
                handle_mouse_click(
                    &gfx,
                    &mut term,
                    &mut start_menu_open,
                    &mut window_open,
                    &mut file_explorer,
                    mouse.x as usize,
                    mouse.y as usize,
                );
                cursor.show(&gfx);
            }
            last_buttons = mouse.buttons;
        }

        // 4. Poll Keyboard Input (Non-blocking)
        let n = unsafe { sys_read(0, &mut read_buf) };
        if n > 0 && n <= read_buf.len() {
            had_event = true;
            for i in 0..n {
                let b = read_buf[i];

                if esc_state == 1 {
                    if b == b'[' {
                        esc_state = 2;
                        continue;
                    } else {
                        // Standalone Escape key toggles Start Menu!
                        esc_state = 0;
                        cursor.hide(&gfx);
                        start_menu_open = !start_menu_open;
                        if start_menu_open {
                            gfx.draw_start_menu();
                        } else {
                            let tb_y = gfx.height.saturating_sub(40);
                            let sm_h = 440;
                            let sm_y = tb_y.saturating_sub(sm_h);
                            gfx.redraw_wallpaper_rect(0, sm_y, 342, sm_h);
                        }
                        gfx.draw_taskbar(start_menu_open, window_open, file_explorer.open);
                        if window_open {
                            term.render(&gfx);
                        }
                        cursor.show(&gfx);
                    }
                } else if esc_state == 2 {
                    esc_state = 0;
                    // Arrow Keys move Windows 10 cursor smoothly (iPad / keyboard navigation)
                    if b == b'A' {
                        // Up
                        cursor.move_to(&gfx, cursor.x, cursor.y.saturating_sub(24));
                    } else if b == b'B' {
                        // Down
                        cursor.move_to(&gfx, cursor.x, (cursor.y + 24).min(gfx.height - 1));
                    } else if b == b'C' {
                        // Right
                        cursor.move_to(&gfx, (cursor.x + 24).min(gfx.width - 1), cursor.y);
                    } else if b == b'D' {
                        // Left
                        cursor.move_to(&gfx, cursor.x.saturating_sub(24), cursor.y);
                    }
                    continue;
                }

                if b == 0x1B {
                    esc_state = 1;
                    continue;
                }

                if b == b'\r' || b == b'\n' {
                    if term.input_len == 0 {
                        // Empty Enter triggers a click at current cursor position!
                        cursor.hide(&gfx);
                        handle_mouse_click(
                            &gfx,
                            &mut term,
                            &mut start_menu_open,
                            &mut window_open,
                            &mut file_explorer,
                            cursor.x,
                            cursor.y,
                        );
                        if window_open {
                            term.render(&gfx);
                        }
                        cursor.show(&gfx);
                        continue;
                    }

                    // Check if command is mouse simulation: "mouse x y", "click x y", or "tap x y"
                    let curr_cmd = core::str::from_utf8(&term.input_buf[..term.input_len]).unwrap_or("");
                    if starts_with_ignore_case(curr_cmd, "mouse ")
                        || starts_with_ignore_case(curr_cmd, "click ")
                        || starts_with_ignore_case(curr_cmd, "tap ")
                    {
                        let split_at = if starts_with_ignore_case(curr_cmd, "mouse ") { 6 } else { 5 };
                        let mut parts = curr_cmd[split_at..].trim().split_ascii_whitespace();
                        if let (Some(xs), Some(ys)) = (parts.next(), parts.next()) {
                            if let (Ok(x), Ok(y)) = (xs.parse::<usize>(), ys.parse::<usize>()) {
                                cursor.move_to(&gfx, x, y);
                                cursor.hide(&gfx);
                                handle_mouse_click(
                                    &gfx,
                                    &mut term,
                                    &mut start_menu_open,
                                    &mut window_open,
                                    &mut file_explorer,
                                    x,
                                    y,
                                );
                                cursor.show(&gfx);
                            }
                        }
                        term.input_len = 0;
                        if window_open {
                            term.render(&gfx);
                        }
                    } else {
                        cursor.hide(&gfx);
                        term.execute_command(
                            &gfx,
                            &mut start_menu_open,
                            &mut window_open,
                            &mut file_explorer,
                        );
                        if window_open {
                            term.render(&gfx);
                        }
                        cursor.show(&gfx);
                    }
                } else if b == 0x08 || b == 0x7F {
                    // Backspace
                    if term.input_len > 0 {
                        term.input_len -= 1;
                        if window_open {
                            cursor.hide(&gfx);
                            term.render(&gfx);
                            cursor.show(&gfx);
                        }
                    }
                } else if b == 0x09 {
                    // Tab auto-completion
                    if term.input_len > 0 {
                        if let Ok(curr) = core::str::from_utf8(&term.input_buf[..term.input_len]) {
                            for &c in TAB_COMMANDS {
                                if starts_with_ignore_case(c, curr) {
                                    term.input_len = 0;
                                    for byte in c.bytes() {
                                        if term.input_len < term.input_buf.len() {
                                            term.input_buf[term.input_len] = byte;
                                            term.input_len += 1;
                                        }
                                    }
                                    if term.input_len < term.input_buf.len() {
                                        term.input_buf[term.input_len] = b' ';
                                        term.input_len += 1;
                                    }
                                    if window_open {
                                        cursor.hide(&gfx);
                                        term.render(&gfx);
                                        cursor.show(&gfx);
                                    }
                                    break;
                                }
                            }
                        }
                    }
                } else if b == 0x03 {
                    // Ctrl+C
                    term.input_len = 0;
                    term.add_line("^C");
                    if window_open {
                        cursor.hide(&gfx);
                        term.render(&gfx);
                        cursor.show(&gfx);
                    }
                } else if b >= 32 && b <= 126 {
                    // Quick numeric shortcut when buffer is empty:
                    // 1 = CMD, 2 = SysInfo, 3 = Files, 4 = Storage
                    if term.input_len == 0 && (b >= b'1' && b <= b'4') {
                        let target_x = if b == b'1' || b == b'3' { 98 } else { 202 };
                        let target_y = if b == b'1' || b == b'2' { gfx.height.saturating_sub(440) + 130 } else { gfx.height.saturating_sub(440) + 190 };
                        cursor.move_to(&gfx, target_x, target_y);
                        cursor.hide(&gfx);
                        handle_mouse_click(
                            &gfx,
                            &mut term,
                            &mut start_menu_open,
                            &mut window_open,
                            &mut file_explorer,
                            target_x,
                            target_y,
                        );
                        if window_open {
                            term.render(&gfx);
                        }
                        cursor.show(&gfx);
                        continue;
                    }

                    // Printable ASCII character
                    if term.input_len + 1 < term.input_buf.len() {
                        term.input_buf[term.input_len] = b;
                        term.input_len += 1;
                        if window_open {
                            cursor.hide(&gfx);
                            term.render(&gfx);
                            cursor.show(&gfx);
                        }
                    }
                }
            }
        }
        if !had_event {
            let sleep_req = TimeSpec {
                tv_sec: 0,
                tv_nsec: 2_000_000, // 2ms sleep for 500 Hz polling & 0% CPU consumption
            };
            unsafe {
                sys_nanosleep(&sleep_req);
            }
        }
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe {
            sys_yield();
        }
    }
}
