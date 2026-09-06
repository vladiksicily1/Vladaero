#![allow(static_mut_refs)]
#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_ARG_MSLICE: usize = 0x0200_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_READ: usize = SYS_CLASS_FILE | SYS_ARG_MSLICE | 3;
const SYS_YIELD: usize = 158;

const SYS_VLADOS_VFS_READ: usize  = 0x5646;
const SYS_VLADOS_VFS_WRITE: usize = 0x5647;

const CONFIG_PATH: &str = "C:\\VladOS\\System32\\Config\\path.cfg";
const DEFAULT_PATH: &str = "C:\\VladOS\\System32";

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

fn print(s: &str) {
    unsafe { sys_write(1, s.as_bytes()) };
}

fn println(s: &str) {
    print(s);
    print("\r\n");
}

static mut PATH_BUF: [u8; 512] = [0u8; 512];
static mut PATH_LEN: usize = 0;

#[no_mangle]
pub extern "C" fn _start() -> ! {
    print("\x1b[2J\x1b[H");
    println("================================================================================");
    println("           VladOS 10 Professional - System PATH Editor (pathedit.vex)           ");
    println("================================================================================");
    println(" Manage environment execution paths for binaries and scripts.");
    println(" Default PATH: C:\\VladOS\\System32");
    println(" Configuration File: C:\\VladOS\\System32\\Config\\path.cfg");
    println("--------------------------------------------------------------------------------");

    load_path();
    show_current_path();

    let mut line_buf = [0u8; 128];

    loop {
        print("pathedit> ");
        let mut line_len = 0;
        let mut char_buf = [0u8; 1];
        loop {
            let n = unsafe { sys_read(0, &mut char_buf) };
            if n == 0 || n == usize::MAX {
                unsafe { sys_yield() };
                continue;
            }
            let b = char_buf[0];
            if b == b'\r' || b == b'\n' {
                print("\r\n");
                break;
            } else if b == 8 || b == 127 {
                if line_len > 0 {
                    line_len -= 1;
                    print("\x08 \x08");
                }
            } else if b >= 32 && b <= 126 {
                if line_len < line_buf.len() {
                    line_buf[line_len] = b;
                    line_len += 1;
                    let s = core::str::from_utf8(&char_buf).unwrap_or("");
                    print(s);
                }
            }
        }

        let cmd = core::str::from_utf8(&line_buf[..line_len]).unwrap_or("").trim();
        if cmd == "exit" || cmd == "quit" || cmd == "q" {
            println("Exiting PATH Editor...");
            break;
        } else if cmd.is_empty() {
            continue;
        } else if eq_ignore_ascii_case(cmd, "view") || eq_ignore_ascii_case(cmd, "list") {
            show_current_path();
        } else if starts_with_ignore_case(cmd, "add ") {
            let dir = cmd[4..].trim();
            add_entry(dir);
            save_path();
            show_current_path();
        } else if starts_with_ignore_case(cmd, "remove ") || starts_with_ignore_case(cmd, "rm ") {
            let split_at = if starts_with_ignore_case(cmd, "remove ") { 7 } else { 3 };
            let dir = cmd[split_at..].trim();
            remove_entry(dir);
            save_path();
            show_current_path();
        } else if eq_ignore_ascii_case(cmd, "reset") {
            reset_default();
            save_path();
            show_current_path();
        } else if eq_ignore_ascii_case(cmd, "save") {
            save_path();
            println("PATH saved successfully to disk.");
        } else if eq_ignore_ascii_case(cmd, "help") || cmd == "?" {
            println("Available Commands:");
            println("  VIEW           - Display current PATH entries");
            println("  ADD <dir>      - Append a directory to PATH and save");
            println("  REMOVE <dir>   - Remove a directory from PATH and save");
            println("  RESET          - Reset PATH back to default (C:\\VladOS\\System32)");
            println("  SAVE           - Force write current PATH to Config\\path.cfg");
            println("  EXIT           - Exit PATH Editor");
        } else {
            println("Unknown command. Type 'help' for command reference.");
        }
    }

    loop {
        unsafe { sys_yield() };
    }
}

fn load_path() {
    let mut tmp = [0u8; 512];
    let n = unsafe { sys_vfs_read(CONFIG_PATH, &mut tmp) };
    if n > 0 && n <= 512 {
        let s = core::str::from_utf8(&tmp[..n]).unwrap_or("").trim();
        if !s.is_empty() {
            unsafe {
                PATH_BUF[..s.len()].copy_from_slice(s.as_bytes());
                PATH_LEN = s.len();
            }
            return;
        }
    }
    reset_default();
}

fn reset_default() {
    unsafe {
        let d = DEFAULT_PATH.as_bytes();
        PATH_BUF[..d.len()].copy_from_slice(d);
        PATH_LEN = d.len();
    }
}

fn save_path() {
    unsafe {
        let _ = sys_vfs_write(CONFIG_PATH, &PATH_BUF[..PATH_LEN]);
    }
}

fn show_current_path() {
    println("Current Environment PATH:");
    let p_str = unsafe { core::str::from_utf8(&PATH_BUF[..PATH_LEN]).unwrap_or(DEFAULT_PATH) };
    print("  PATH = ");
    println(p_str);
    println("");
    println("Individual Directory Search Order:");
    let mut idx = 1;
    for part in p_str.split(';') {
        let trimmed = part.trim();
        if !trimmed.is_empty() {
            print("  [");
            let mut num_buf = [0u8; 4];
            num_buf[0] = b'0' + (idx % 10) as u8;
            print(core::str::from_utf8(&num_buf[..1]).unwrap_or("1"));
            print("] ");
            println(trimmed);
            idx += 1;
        }
    }
    println("");
}

fn add_entry(dir: &str) {
    let dir = dir.trim();
    if dir.is_empty() { return; }
    unsafe {
        let cur = core::str::from_utf8(&PATH_BUF[..PATH_LEN]).unwrap_or("");
        // Avoid duplicate
        for part in cur.split(';') {
            if eq_ignore_ascii_case(part.trim(), dir) {
                println("Directory is already in PATH.");
                return;
            }
        }
        if PATH_LEN > 0 && PATH_LEN + 1 + dir.len() < 512 {
            PATH_BUF[PATH_LEN] = b';';
            PATH_LEN += 1;
            PATH_BUF[PATH_LEN..PATH_LEN + dir.len()].copy_from_slice(dir.as_bytes());
            PATH_LEN += dir.len();
            println("Directory added to PATH.");
        }
    }
}

fn remove_entry(dir: &str) {
    let dir = dir.trim();
    if dir.is_empty() { return; }
    unsafe {
        let cur = core::str::from_utf8(&PATH_BUF[..PATH_LEN]).unwrap_or("");
        let mut new_buf = [0u8; 512];
        let mut new_len = 0;
        let mut removed = false;

        for part in cur.split(';') {
            let p = part.trim();
            if eq_ignore_ascii_case(p, dir) {
                removed = true;
                continue;
            }
            if !p.is_empty() {
                if new_len > 0 && new_len < 511 {
                    new_buf[new_len] = b';';
                    new_len += 1;
                }
                let copy_sz = p.len().min(512 - new_len);
                new_buf[new_len..new_len + copy_sz].copy_from_slice(&p.as_bytes()[..copy_sz]);
                new_len += copy_sz;
            }
        }

        if removed {
            PATH_BUF[..new_len].copy_from_slice(&new_buf[..new_len]);
            PATH_LEN = new_len;
            println("Directory removed from PATH.");
        } else {
            println("Directory not found in PATH.");
        }
    }
}

fn eq_ignore_ascii_case(a: &str, b: &str) -> bool {
    if a.len() != b.len() { return false; }
    for (ca, cb) in a.bytes().zip(b.bytes()) {
        if ca.to_ascii_lowercase() != cb.to_ascii_lowercase() { return false; }
    }
    true
}

fn starts_with_ignore_case(s: &str, prefix: &str) -> bool {
    if s.len() < prefix.len() { return false; }
    eq_ignore_ascii_case(&s[..prefix.len()], prefix)
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    print("\r\n[PathEdit Panic]\r\n");
    loop {
        unsafe { sys_yield() };
    }
}
