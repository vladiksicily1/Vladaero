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

fn print(s: &str) {
    unsafe { sys_write(1, s.as_bytes()) };
}

fn println(s: &str) {
    print(s);
    print("\r\n");
}

static mut PATH_BUF: [u8; 512] = [0u8; 512];
static mut PATH_LEN: usize = 0;

const PATH_CFG_FILE: &str = "C:\\VladOS\\System32\\Config\\path.cfg";

fn load_path() {
    let n = unsafe { sys_vfs_read(PATH_CFG_FILE, &mut PATH_BUF) };
    if n > 0 && n <= 512 {
        unsafe { PATH_LEN = n };
    } else {
        let default_path = b"C:\\VladOS\\System32\r\n";
        unsafe {
            PATH_BUF[..default_path.len()].copy_from_slice(default_path);
            PATH_LEN = default_path.len();
        }
    }
}

fn save_path() -> bool {
    let len = unsafe { PATH_LEN };
    let data = unsafe { &PATH_BUF[..len] };
    let ret = unsafe { sys_vfs_write(PATH_CFG_FILE, data) };
    ret > 0
}

fn show_current_path() {
    let len = unsafe { PATH_LEN };
    let s = unsafe { core::str::from_utf8(&PATH_BUF[..len]).unwrap_or("C:\\VladOS\\System32") };
    print("PATH=");
    println(s.trim());
}

pub fn execute_pathedit_cmd(cmd: &str) {
    let cmd = cmd.trim();
    if cmd.is_empty() || cmd == "show" || cmd == "list" {
        show_current_path();
    } else if cmd.starts_with("add ") {
        let dir = cmd[4..].trim();
        let cur_len = unsafe { PATH_LEN };
        let mut cur_str = [0u8; 512];
        cur_str[..cur_len].copy_from_slice(unsafe { &PATH_BUF[..cur_len] });
        let s = core::str::from_utf8(&cur_str[..cur_len]).unwrap_or("").trim();

        let mut new_buf = [0u8; 512];
        let mut new_len = 0;
        for b in s.bytes() {
            new_buf[new_len] = b;
            new_len += 1;
        }
        if new_len > 0 && new_buf[new_len - 1] != b';' {
            new_buf[new_len] = b';';
            new_len += 1;
        }
        for b in dir.bytes() {
            if new_len < 500 {
                new_buf[new_len] = b;
                new_len += 1;
            }
        }
        new_buf[new_len] = b'\r';
        new_len += 1;
        new_buf[new_len] = b'\n';
        new_len += 1;

        unsafe {
            PATH_BUF[..new_len].copy_from_slice(&new_buf[..new_len]);
            PATH_LEN = new_len;
        }
        if save_path() {
            println("Directory added to system PATH successfully.");
        } else {
            println("Failed to write to path.cfg");
        }
        show_current_path();
    } else if cmd == "reset" {
        let default_path = b"C:\\VladOS\\System32\r\n";
        unsafe {
            PATH_BUF[..default_path.len()].copy_from_slice(default_path);
            PATH_LEN = default_path.len();
        }
        save_path();
        println("Reset PATH to default C:\\VladOS\\System32");
        show_current_path();
    } else {
        println("Usage: pathedit [show | add <directory> | reset]");
    }
}

#[no_mangle]
pub extern "C" fn _start(arg_ptr: *const u8, arg_len: usize) -> usize {
    load_path();
    let args = if arg_ptr.is_null() || arg_len == 0 {
        ""
    } else {
        unsafe {
            core::str::from_utf8(core::slice::from_raw_parts(arg_ptr, arg_len)).unwrap_or("")
        }
    };
    if !args.trim().is_empty() {
        execute_pathedit_cmd(args.trim());
        return 0;
    }

    print("\x1b[2J\x1b[H");
    println("================================================================================");
    println("           VladOS 10 Professional - System PATH Editor (pathedit.vex)           ");
    println("================================================================================");
    println(" Configuration File: C:\\VladOS\\System32\\Config\\path.cfg");
    println(" Commands: 'show', 'add <dir>', 'reset', 'exit'");
    println("--------------------------------------------------------------------------------");

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

        let cmd = match core::str::from_utf8(&line_buf[..line_len]) {
            Ok(s) => s.trim(),
            Err(_) => continue,
        };

        if cmd == "exit" || cmd == "quit" || cmd == "q" {
            println("Exiting VladOS PATH Editor...");
            break;
        } else if cmd.is_empty() {
            continue;
        }

        execute_pathedit_cmd(cmd);
    }

    0
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    print("\r\n[pathedit Panic]\r\n");
    loop {
        unsafe { sys_yield() };
    }
}
