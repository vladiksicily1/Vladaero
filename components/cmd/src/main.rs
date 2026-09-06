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

// -----------------------------------------------------------------------------
// VladOS VFS and RBAC Security Syscalls
// -----------------------------------------------------------------------------

const SYS_VLADOS_VFS_READ: usize   = 0x5646;
const SYS_VLADOS_VFS_WRITE: usize  = 0x5647;
const SYS_VLADOS_VFS_MKDIR: usize  = 0x5648;
const SYS_VLADOS_VFS_UNLINK: usize = 0x5649;
const SYS_VLADOS_VFS_RENAME: usize = 0x564A;
const SYS_VLADOS_VFS_LIST: usize   = 0x564C;
const SYS_VLADOS_VFS_CHMOD: usize  = 0x564E;
const SYS_VLADOS_WHOAMI: usize     = 0x5650;
const SYS_VLADOS_SU: usize         = 0x5651;
const SYS_VLADOS_DRIVES: usize     = 0x5652;

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

fn print(s: &str) {
    unsafe {
        sys_write(1, s.as_bytes());
    }
}

fn println(s: &str) {
    print(s);
    print("\r\n");
}

fn starts_with_ignore_case(s: &str, prefix: &str) -> bool {
    if s.len() < prefix.len() {
        return false;
    }
    s[..prefix.len()].eq_ignore_ascii_case(prefix)
}

fn eq_ignore_ascii_case(a: &str, b: &str) -> bool {
    a.eq_ignore_ascii_case(b)
}

static mut DIR_BUF: [u8; 4096] = [0u8; 4096];
static mut FILE_BUF: [u8; 8192] = [0u8; 8192];
static mut INFO_BUF: [u8; 2048] = [0u8; 2048];

fn handle_command(cmd_line: &str) {
    let cmd = cmd_line.trim();
    if cmd.is_empty() {
        return;
    }

    if eq_ignore_ascii_case(cmd, "help") {
        println("VladOS 10 Professional - Command Prompt Reference:");
        println("--------------------------------------------------");
        println("  DIR [path]         - List directory contents on C:, D:, or E:");
        println("  TYPE <file>        - Display contents of a text file");
        println("  ECHO <text>        - Print text (use '> file' to create/write)");
        println("  MKDIR <path>       - Create a new directory");
        println("  DEL / RM <path>    - Delete a file");
        println("  REN <old> <new>    - Rename a file or directory");
        println("  CHMOD <mode> <p>   - Change permissions (e.g. chmod 755 /path)");
        println("  ATTRIB <path>      - Change file attributes");
        println("  DRIVES             - List all mounted volumes (C:, D:, E:)");
        println("  WHOAMI             - Display current user, SID, role, and privileges");
        println("  NET USER           - List system security accounts");
        println("  SU <user>          - Switch user context (Vlad, System, User, Guest)");
        println("  SYSINFO            - Display system hardware & OS information");
        println("  EXPLORER           - Switch to Windows 10 File Explorer & Desktop");
        println("  CLS                - Clear screen");
        println("  VER                - Print VladOS version information");
        println("  EXIT               - Return to graphical desktop");
    } else if eq_ignore_ascii_case(cmd, "ver") {
        println("VladOS [Version 10.0.22000.1] - 64-bit Hybrid Microkernel");
    } else if eq_ignore_ascii_case(cmd, "cls") {
        print("\x1b[2J\x1b[H");
    } else if eq_ignore_ascii_case(cmd, "whoami") {
        let n = unsafe { sys_whoami(&mut INFO_BUF) };
        if n > 0 && n <= 2048 {
            if let Ok(s) = core::str::from_utf8(unsafe { &INFO_BUF[..n] }) {
                print(s);
            }
        }
    } else if eq_ignore_ascii_case(cmd, "net user") {
        println("User Accounts for \\\\VLADOS-PC");
        println("-------------------------------------------------------------------------------");
        println("SYSTEM                   Vlad                     User");
        println("Guest                    DefaultAccount");
        println("The command completed successfully.");
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
            println("Account not found. Available: SYSTEM, Vlad, User, Guest");
        } else {
            let ret = unsafe { sys_su(uid) };
            if ret == 0 {
                print("Switched security context to: ");
                println(arg);
            } else {
                println("Failed to switch user: Permission Denied");
            }
        }
    } else if eq_ignore_ascii_case(cmd, "drives") || eq_ignore_ascii_case(cmd, "wmic logicaldisk") {
        println("Mounted Logical Drives & Volumes:");
        println("-------------------------------------------------------------------------------");
        let n = unsafe { sys_drives(&mut INFO_BUF) };
        if n > 0 && n <= 2048 {
            if let Ok(s) = core::str::from_utf8(unsafe { &INFO_BUF[..n] }) {
                print(s);
            }
        }
    } else if eq_ignore_ascii_case(cmd, "sysinfo") {
        println("Host Name:                 VLADOS-PC");
        println("OS Name:                   VladOS 10 Professional");
        println("OS Version:                1.0.0 Build 2026.09.06");
        println("OS Architecture:           x86_64 Long Mode (64-bit)");
        println("Desktop Shell:             explorer.vex (Windows 10 Fluent Dark)");
        println("File Explorer:             Windows 10 Explorer Window Active");
        println("Drivers:                   /VladOS/System32/drivers/ (10 Native .sys Drivers)");
        println("Primary Filesystem:        VladFS (Volume: VLADOS_SYS on Drive C:)");
        println("Secondary Filesystem:      FAT32 / EXT2 (Volume: DATA_DRIVE on Drive D:)");
        println("Optical Media:             ISO9660 (Volume: VLADOS_INSTALL on Drive E:)");
        println("Security Subsystem:        RBAC (SYSTEM, Administrator Vlad, Users, Guest)");
        println("Display:                   1280x800x32 Linear GOP Framebuffer");
        println("Cloud Portal:              https://vladinc.ru/vlados/");
    } else if eq_ignore_ascii_case(cmd, "dir") || starts_with_ignore_case(cmd, "dir ") {
        let dir_target = if starts_with_ignore_case(cmd, "dir ") {
            cmd[4..].trim()
        } else {
            "C:\\"
        };

        println(" Directory of ");
        println(dir_target);
        println("");
        println("Mode        Type             Size Owner    Name");
        println("----------  ---------- ---------- -------- -----------------------------");

        let n = unsafe { sys_vfs_list(dir_target, &mut DIR_BUF) };
        if n > 0 && n <= 4096 {
            if let Ok(s) = core::str::from_utf8(unsafe { &DIR_BUF[..n] }) {
                print(s);
            }
        } else {
            println("File Not Found or Access Denied.");
        }
    } else if starts_with_ignore_case(cmd, "mkdir ") || starts_with_ignore_case(cmd, "md ") {
        let p = if starts_with_ignore_case(cmd, "mkdir ") {
            cmd[6..].trim()
        } else {
            cmd[3..].trim()
        };
        let ret = unsafe { sys_vfs_mkdir(p) };
        if ret > 0 {
            print("Created directory: ");
            println(p);
        } else {
            println("Failed to create directory (Access Denied or Directory Exists).");
        }
    } else if starts_with_ignore_case(cmd, "del ") || starts_with_ignore_case(cmd, "rm ") {
        let p = if starts_with_ignore_case(cmd, "del ") {
            cmd[4..].trim()
        } else {
            cmd[3..].trim()
        };
        let ret = unsafe { sys_vfs_unlink(p) };
        if ret == 0 {
            print("Deleted file: ");
            println(p);
        } else {
            println("Cannot delete file (File Not Found or Access Denied).");
        }
    } else if starts_with_ignore_case(cmd, "ren ") {
        let parts = cmd[4..].trim();
        if let Some(idx) = parts.find(' ') {
            let old_p = parts[..idx].trim();
            let new_p = parts[idx + 1..].trim();
            let ret = unsafe { sys_vfs_rename(old_p, new_p) };
            if ret == 0 {
                println("File renamed successfully.");
            } else {
                println("Failed to rename file.");
            }
        } else {
            println("Syntax: REN <old_path> <new_name>");
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
                println("Permissions changed successfully.");
            } else {
                println("Failed to change permissions (Access Denied).");
            }
        } else {
            println("Syntax: CHMOD <octal_mode> <path>");
        }
    } else if starts_with_ignore_case(cmd, "echo ") {
        let rest = cmd[5..].trim();
        if let Some(idx) = rest.find('>') {
            let text = rest[..idx].trim();
            let path = rest[idx + 1..].trim();
            let ret = unsafe { sys_vfs_write(path, text.as_bytes()) };
            if ret > 0 {
                print("Written ");
                print(text);
                print(" -> ");
                println(path);
            } else {
                println("Access Denied or Write Protected volume.");
            }
        } else {
            println(rest);
        }
    } else if starts_with_ignore_case(cmd, "type ") {
        let file = cmd[5..].trim();
        let n = unsafe { sys_vfs_read(file, &mut FILE_BUF) };
        if n > 0 && n <= 8192 {
            if let Ok(s) = core::str::from_utf8(unsafe { &FILE_BUF[..n] }) {
                print(s);
                println("");
            } else {
                println("[Binary File]");
            }
        } else {
            print("The system cannot find the file specified: ");
            println(file);
        }
    } else if eq_ignore_ascii_case(cmd, "exit") || eq_ignore_ascii_case(cmd, "explorer") || eq_ignore_ascii_case(cmd, "start") || eq_ignore_ascii_case(cmd, "menu") {
        println("Returning to Windows 10 Start Menu & File Explorer (explorer.vex)...");
        let explorer_entry: extern "C" fn() -> ! = unsafe { core::mem::transmute(0x100000usize) };
        explorer_entry();
    } else {
        print("'");
        print(cmd);
        println("' is not recognized as an internal or external command,");
        println("operable program or batch file.");
    }
}

#[no_mangle]
pub extern "C" fn _start() -> ! {
    print("\x1b[2J\x1b[H"); // Clear screen
    println("VladOS [Version 10.0.22000.1]");
    println("(c) 2026 Vlad Corporation. All rights reserved.");
    println("");
    println("VladOS Command Prompt (Administrator). Type HELP for available commands.");
    println("");

    let mut buf = [0u8; 256];
    let mut len = 0;

    loop {
        print("C:\\VladOS\\System32> ");

        len = 0;
        loop {
            let mut ch = [0u8; 1];
            let n = unsafe { sys_read(0, &mut ch) };
            if n == 0 {
                unsafe { sys_yield() };
                continue;
            }

            let c = ch[0];
            if c == b'\r' || c == b'\n' {
                print("\r\n");
                break;
            } else if c == 8 || c == 127 {
                // Backspace
                if len > 0 {
                    len -= 1;
                    print("\x08 \x08");
                }
            } else if c >= 32 && c < 127 {
                if len < buf.len() {
                    buf[len] = c;
                    len += 1;
                    unsafe {
                        sys_write(1, &[c]);
                    }
                }
            }
        }

        if len > 0 {
            if let Ok(cmd_line) = core::str::from_utf8(&buf[..len]) {
                handle_command(cmd_line);
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
