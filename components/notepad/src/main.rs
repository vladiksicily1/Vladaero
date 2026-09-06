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

static mut TEXT_BUFFER: [u8; 16384] = [0u8; 16384];
static mut TEXT_LEN: usize = 0;
static mut FILE_NAME: [u8; 128] = [0u8; 128];
static mut FILE_NAME_LEN: usize = 0;

#[no_mangle]
pub extern "C" fn _start() -> ! {
    print("\x1b[2J\x1b[H"); // Clear screen
    println("================================================================================");
    println("                      VladOS 10 Professional - Notepad                          ");
    println("================================================================================");
    println(" Commands: :w (Save) | :q (Quit) | :n <filename> (New/Open) | :show (Display)");
    println("--------------------------------------------------------------------------------");

    // Set default file name
    let default_name = b"C:\\Users\\Vlad\\Documents\\Note.txt";
    unsafe {
        FILE_NAME[..default_name.len()].copy_from_slice(default_name);
        FILE_NAME_LEN = default_name.len();
    }

    // Try loading existing file
    let path = unsafe { core::str::from_utf8_unchecked(&FILE_NAME[..FILE_NAME_LEN]) };
    let n = unsafe { sys_vfs_read(path, &mut TEXT_BUFFER) };
    if n > 0 && n <= 16384 {
        unsafe { TEXT_LEN = n };
        print("Opened file: ");
        println(path);
    } else {
        let initial_text = b"Welcome to VladOS Notepad!\r\nType text or edit files across C:, D:, and U: (USB Flash Drive).\r\n";
        unsafe {
            TEXT_BUFFER[..initial_text.len()].copy_from_slice(initial_text);
            TEXT_LEN = initial_text.len();
        }
        print("Created new file: ");
        println(path);
    }

    println("Current Content:");
    println("----------------");
    let current_text = unsafe { core::str::from_utf8_unchecked(&TEXT_BUFFER[..TEXT_LEN]) };
    print(current_text);
    if !current_text.ends_with('\n') {
        print("\r\n");
    }
    println("----------------");

    let mut line_buf = [0u8; 256];
    let mut line_len = 0;

    loop {
        print("notepad> ");
        line_len = 0;
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
        if cmd == ":q" || cmd == ":quit" || cmd == "exit" {
            println("Exiting Notepad...");
            break;
        } else if cmd == ":w" || cmd == ":save" {
            let p = unsafe { core::str::from_utf8_unchecked(&FILE_NAME[..FILE_NAME_LEN]) };
            let ret = unsafe { sys_vfs_write(p, &TEXT_BUFFER[..TEXT_LEN]) };
            if ret > 0 {
                print("Successfully saved ");
                print(p);
                println(" !");
            } else {
                println("Error: Failed to save file (Access Denied or Volume Read-Only).");
            }
        } else if cmd == ":show" {
            println("--- File Buffer ---");
            let t = unsafe { core::str::from_utf8_unchecked(&TEXT_BUFFER[..TEXT_LEN]) };
            print(t);
            if !t.ends_with('\n') {
                print("\r\n");
            }
            println("-------------------");
        } else if cmd.starts_with(":n ") || cmd.starts_with(":open ") {
            let arg = if cmd.starts_with(":n ") { &cmd[3..] } else { &cmd[6..] }.trim();
            unsafe {
                let bytes = arg.as_bytes();
                let l = bytes.len().min(128);
                FILE_NAME[..l].copy_from_slice(&bytes[..l]);
                FILE_NAME_LEN = l;
            }
            let p = unsafe { core::str::from_utf8_unchecked(&FILE_NAME[..FILE_NAME_LEN]) };
            let n = unsafe { sys_vfs_read(p, &mut TEXT_BUFFER) };
            if n > 0 && n <= 16384 {
                unsafe { TEXT_LEN = n };
                print("Opened existing file: ");
                println(p);
            } else {
                unsafe { TEXT_LEN = 0 };
                print("New empty file: ");
                println(p);
            }
        } else {
            // Append line to text buffer
            let cur_len = unsafe { TEXT_LEN };
            let add_len = cmd.len() + 2;
            if cur_len + add_len <= 16384 {
                unsafe {
                    TEXT_BUFFER[cur_len..cur_len + cmd.len()].copy_from_slice(cmd.as_bytes());
                    TEXT_BUFFER[cur_len + cmd.len()] = b'\r';
                    TEXT_BUFFER[cur_len + cmd.len() + 1] = b'\n';
                    TEXT_LEN += add_len;
                }
                println("[Appended line to buffer]");
            } else {
                println("Buffer full!");
            }
        }
    }

    loop {
        unsafe { sys_yield() };
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    print("\r\n[Notepad Panic]\r\n");
    loop {
        unsafe { sys_yield() };
    }
}
