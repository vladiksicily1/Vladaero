#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_ARG_MSLICE: usize = 0x0200_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_READ: usize = SYS_CLASS_FILE | SYS_ARG_MSLICE | 3;
const SYS_YIELD: usize = 158;

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

const SYS_VLADOS_VFS_READ: usize = 0x5646;
const SYS_VLADOS_VFS_LIST: usize = 0x564C;

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

fn print(s: &str) {
    unsafe {
        sys_write(1, s.as_bytes());
    }
}

fn println(s: &str) {
    print(s);
    print("\n");
}

const COMMANDS: &[&str] = &[
    "cls",
    "dir",
    "echo",
    "exit",
    "explorer",
    "help",
    "menu",
    "start",
    "sysinfo",
    "type",
    "ver",
];

const FILES: &[&str] = &[
    "account.cfg",
    "cmd.vex",
    "config",
    "drivers",
    "explorer.vex",
    "system.ini",
    "vladinit.vex",
];

fn print_prompt() {
    print("C:\\VladOS\\System32> ");
}

fn handle_command(cmd: &str) {
    let cmd = cmd.trim();
    if cmd.is_empty() {
        return;
    }

    if eq_ignore_ascii_case(cmd, "help") {
        println("For more information on a specific command, type HELP command-name");
        println("CLS        Clears the screen.");
        println("DIR        Displays a list of files and subdirectories in a directory.");
        println("ECHO       Displays messages, or turns command echoing on or off.");
        println("EXIT       Quits CMD.VEX and returns to Windows 10 Start Menu.");
        println("EXPLORER   Launches Windows 10 Desktop Shell / Start Menu.");
        println("HELP       Provides Help information for VladOS commands.");
        println("START      Opens the Windows 10 Start Menu (explorer.vex).");
        println("SYSINFO    Displays VladOS machine and OS configuration.");
        println("TYPE       Displays the contents of a text file.");
        println("VER        Displays the VladOS version.");
    } else if eq_ignore_ascii_case(cmd, "ver") {
        println("VladOS [Version 10.0.22000.1] - 64-bit Hybrid Kernel");
    } else if eq_ignore_ascii_case(cmd, "cls") {
        print("\x1b[2J\x1b[H");
    } else if eq_ignore_ascii_case(cmd, "sysinfo") {
        println("Host Name:                 VLADOS-PC");
        println("OS Name:                   VladOS 10 Professional");
        println("OS Version:                1.0.0 Build 2026.09.05");
        println("OS Architecture:           x86_64 Long Mode (64-bit)");
        println("Desktop Shell:             explorer.vex (Windows 10 Fluent Dark)");
        println("Start Menu:                Active (Pinned: CMD, SysInfo, Explorer)");
        println("Executable Standard:       .vex (Vlad EXecutable)");
        println("Root Filesystem:           VladFS (Volume: VLADOS_SYS)");
        println("Display:                   1280x800x32 Linear GOP Framebuffer");
        println("Theme Engine:              Windows 10 Fluent Dark");
        println("Cloud Portal:              https://vladinc.ru/vlados/");
    } else if eq_ignore_ascii_case(cmd, "dir") || starts_with_ignore_case(cmd, "dir ") {
        let dir_target = if starts_with_ignore_case(cmd, "dir ") {
            cmd[4..].trim()
        } else {
            "/VladOS/System32"
        };
        let normalized = if dir_target.starts_with("C:") || dir_target.starts_with("c:") {
            &dir_target[2..]
        } else {
            dir_target
        };
        let lookup_path = if normalized.is_empty() { "/" } else { normalized };

        println(" Volume in drive C is VLADOS_SYS");
        println(" Volume Serial Number is 564C-4144");
        print(" Directory of C:");
        println(lookup_path);
        println("");

        static mut DIR_BUF: [u8; 4096] = [0u8; 4096];
        let n = unsafe { sys_vfs_list(lookup_path, &mut DIR_BUF) };
        if n > 0 && n <= 4096 {
            if let Ok(s) = core::str::from_utf8(unsafe { &DIR_BUF[..n] }) {
                print(s);
            }
        } else {
            println("09/06/2026  01:00 PM    <DIR>          config");
            println("09/06/2026  01:00 PM    <DIR>          drivers");
            println("09/06/2026  01:00 PM             1,350 vladinit.vex");
            println("09/06/2026  01:00 PM             2,840 explorer.vex");
            println("09/06/2026  01:00 PM             2,180 cmd.vex");
        }
    } else if starts_with_ignore_case(cmd, "echo ") {
        println(&cmd[5..]);
    } else if starts_with_ignore_case(cmd, "type ") {
        let file = cmd[5..].trim();
        let normalized = if file.starts_with("C:") || file.starts_with("c:") {
            &file[2..]
        } else {
            file
        };

        static mut FILE_BUF: [u8; 8192] = [0u8; 8192];
        let n = unsafe { sys_vfs_read(normalized, &mut FILE_BUF) };
        if n > 0 && n <= 8192 {
            if let Ok(s) = core::str::from_utf8(unsafe { &FILE_BUF[..n] }) {
                println(s);
            } else {
                println("[Binary Resource File]");
            }
        } else {
            // Also try with /VladOS/System32/ prefix
            let mut pref_buf = [0u8; 128];
            let prefix = b"/VladOS/System32/";
            pref_buf[..prefix.len()].copy_from_slice(prefix);
            let name_bytes = normalized.trim_start_matches('/').as_bytes();
            let total = (prefix.len() + name_bytes.len()).min(128);
            pref_buf[prefix.len()..total].copy_from_slice(&name_bytes[..total - prefix.len()]);
            let mut found = false;
            if let Ok(full_path) = core::str::from_utf8(&pref_buf[..total]) {
                let n2 = unsafe { sys_vfs_read(full_path, &mut FILE_BUF) };
                if n2 > 0 && n2 <= 8192 {
                    if let Ok(s) = core::str::from_utf8(unsafe { &FILE_BUF[..n2] }) {
                        println(s);
                        found = true;
                    }
                }
            }
            if !found {
                print("The system cannot find the file specified: ");
                println(file);
            }
        }
    } else if eq_ignore_ascii_case(cmd, "exit") || eq_ignore_ascii_case(cmd, "explorer") || eq_ignore_ascii_case(cmd, "start") || eq_ignore_ascii_case(cmd, "menu") {
        println("Returning to Windows 10 Start Menu (explorer.vex)...");
        let explorer_entry: extern "C" fn() -> ! = unsafe { core::mem::transmute(0x100000usize) };
        explorer_entry();
    } else {
        print("'");
        print(cmd);
        println("' is not recognized as an internal or external command,");
        println("operable program or batch file.");
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

#[no_mangle]
pub extern "C" fn _start() -> ! {
    println("VladOS [Version 10.0.22000.1]");
    println("(c) 2026 Vlad Corporation. All rights reserved.");
    println("");
    print_prompt();

    let mut line_buf = [0u8; 128];
    let mut line_len: usize = 0;
    let mut read_buf = [0u8; 16];

    loop {
        let n = unsafe { sys_read(0, &mut read_buf) };
        if n > 0 && n <= read_buf.len() {
            for i in 0..n {
                let b = read_buf[i];
                if b == b'\r' || b == b'\n' {
                    println("");
                    if line_len > 0 {
                        if let Ok(cmd_str) = core::str::from_utf8(&line_buf[..line_len]) {
                            handle_command(cmd_str);
                        }
                        line_len = 0;
                    }
                    print_prompt();
                } else if b == 0x08 || b == 0x7F {
                    // Backspace
                    if line_len > 0 {
                        line_len -= 1;
                        print("\x08");
                    }
                } else if b == 0x09 {
                    // Tab auto-completion
                    if line_len > 0 {
                        if let Ok(curr) = core::str::from_utf8(&line_buf[..line_len]) {
                            if let Some(space_pos) = curr.rfind(' ') {
                                let prefix = &curr[space_pos + 1..];
                                if !prefix.is_empty() {
                                    for &f in FILES {
                                        if starts_with_ignore_case(f, prefix) {
                                            for _ in 0..prefix.len() {
                                                print("\x08");
                                            }
                                            line_len = space_pos + 1;
                                            for byte in f.bytes() {
                                                if line_len < line_buf.len() {
                                                    line_buf[line_len] = byte;
                                                    line_len += 1;
                                                }
                                            }
                                            print(f);
                                            break;
                                        }
                                    }
                                }
                            } else {
                                for &c in COMMANDS {
                                    if starts_with_ignore_case(c, curr) {
                                        for _ in 0..line_len {
                                            print("\x08");
                                        }
                                        line_len = 0;
                                        for byte in c.bytes() {
                                            if line_len < line_buf.len() {
                                                line_buf[line_len] = byte;
                                                line_len += 1;
                                            }
                                        }
                                        if line_len < line_buf.len() {
                                            line_buf[line_len] = b' ';
                                            line_len += 1;
                                        }
                                        print(c);
                                        print(" ");
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } else if b == 0x03 {
                    // Ctrl+C
                    println("^C");
                    line_len = 0;
                    print_prompt();
                } else if b >= 32 && b <= 126 {
                    // Printable ASCII
                    if line_len + 1 < line_buf.len() {
                        line_buf[line_len] = b;
                        line_len += 1;
                        let echo = [b];
                        unsafe { sys_write(1, &echo) };
                    }
                }
            }
        } else {
            unsafe {
                sys_yield();
            }
        }
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    println("[cmd.vex] CRITICAL PANIC in Ring 3!");
    loop {
        unsafe {
            sys_yield();
        }
    }
}
