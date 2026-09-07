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

// VladOS VFS and Security Syscalls
const SYS_VLADOS_VFS_READ: usize   = 0x5646;
const SYS_VLADOS_VFS_WRITE: usize  = 0x5647;
const SYS_VLADOS_VFS_MKDIR: usize  = 0x5648;
const SYS_VLADOS_VFS_UNLINK: usize = 0x5649;
const SYS_VLADOS_VFS_RENAME: usize = 0x564A;
const SYS_VLADOS_VFS_LIST: usize   = 0x564C;
const SYS_VLADOS_VFS_CHMOD: usize  = 0x564E;
const SYS_VLADOS_SU: usize         = 0x5651;

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

fn print(s: &str) {
    unsafe {
        sys_write(1, s.as_bytes());
    }
}

fn println(s: &str) {
    print(s);
    print("\r\n");
}

fn eq_ignore_ascii_case(a: &str, b: &str) -> bool {
    if a.len() != b.len() {
        return false;
    }
    a.bytes().zip(b.bytes()).all(|(x, y)| x.to_ascii_lowercase() == y.to_ascii_lowercase())
}

fn starts_with_ignore_case(s: &str, prefix: &str) -> bool {
    if s.len() < prefix.len() {
        return false;
    }
    eq_ignore_ascii_case(&s[..prefix.len()], prefix)
}

// -----------------------------------------------------------------------------
// PATH Management
// -----------------------------------------------------------------------------
static mut SYSTEM_PATH: [u8; 512] = [0u8; 512];
static mut SYSTEM_PATH_LEN: usize = 0;
const PATH_CONFIG_FILE: &str = "C:\\VladOS\\System32\\Config\\path.cfg";

fn init_path() {
    let mut buf = [0u8; 512];
    let n = unsafe { sys_vfs_read(PATH_CONFIG_FILE, &mut buf) };
    if n > 0 && n <= 512 {
        unsafe {
            SYSTEM_PATH[..n].copy_from_slice(&buf[..n]);
            SYSTEM_PATH_LEN = n;
        }
    } else {
        let default_path = b"C:\\VladOS\\System32\r\n";
        unsafe {
            SYSTEM_PATH[..default_path.len()].copy_from_slice(default_path);
            SYSTEM_PATH_LEN = default_path.len();
        }
    }
}

fn get_path() -> &'static str {
    unsafe {
        let len = SYSTEM_PATH_LEN;
        core::str::from_utf8(&SYSTEM_PATH[..len]).unwrap_or("C:\\VladOS\\System32").trim()
    }
}

fn save_path_config() -> bool {
    unsafe {
        let len = SYSTEM_PATH_LEN;
        let data = &SYSTEM_PATH[..len];
        let written = sys_vfs_write(PATH_CONFIG_FILE, data);
        written > 0
    }
}

fn handle_path_command(args: &str) {
    let args = args.trim();
    if args.is_empty() {
        print("PATH=");
        println(get_path());
        return;
    }

    if eq_ignore_ascii_case(args, "reset") {
        let default_path = b"C:\\VladOS\\System32\r\n";
        unsafe {
            SYSTEM_PATH[..default_path.len()].copy_from_slice(default_path);
            SYSTEM_PATH_LEN = default_path.len();
        }
        if save_path_config() {
            println("PATH reset to default: C:\\VladOS\\System32");
        }
        return;
    }

    if starts_with_ignore_case(args, "add ") {
        let new_dir = args[4..].trim();
        if new_dir.is_empty() {
            println("Syntax: PATH add <directory>");
            return;
        }

        let cur_path = get_path();
        let mut new_path_buf = [0u8; 512];
        let mut new_len = 0;

        for b in cur_path.bytes() {
            new_path_buf[new_len] = b;
            new_len += 1;
        }
        if new_len > 0 && new_path_buf[new_len - 1] != b';' {
            new_path_buf[new_len] = b';';
            new_len += 1;
        }
        for b in new_dir.bytes() {
            if new_len < 500 {
                new_path_buf[new_len] = b;
                new_len += 1;
            }
        }
        new_path_buf[new_len] = b'\r';
        new_len += 1;
        new_path_buf[new_len] = b'\n';
        new_len += 1;

        unsafe {
            SYSTEM_PATH[..new_len].copy_from_slice(&new_path_buf[..new_len]);
            SYSTEM_PATH_LEN = new_len;
        }

        if save_path_config() {
            print("Added to PATH: ");
            println(new_dir);
            print("PATH=");
            println(get_path());
        }
        return;
    }

    // Direct assignment: PATH C:\dir1;C:\dir2
    let mut new_path_buf = [0u8; 512];
    let mut new_len = 0;
    for b in args.bytes() {
        if new_len < 500 {
            new_path_buf[new_len] = b;
            new_len += 1;
        }
    }
    new_path_buf[new_len] = b'\r';
    new_len += 1;
    new_path_buf[new_len] = b'\n';
    new_len += 1;

    unsafe {
        SYSTEM_PATH[..new_len].copy_from_slice(&new_path_buf[..new_len]);
        SYSTEM_PATH_LEN = new_len;
    }
    save_path_config();
    print("PATH=");
    println(get_path());
}

// -----------------------------------------------------------------------------
// Real Dynamic ELF Execution from PATH
// -----------------------------------------------------------------------------
static mut ELF_LOAD_BUF: [u8; 262144] = [0u8; 262144]; // 256 KB buffer
static mut DIR_BUF: [u8; 4096] = [0u8; 4096];
static mut FILE_BUF: [u8; 8192] = [0u8; 8192];

fn try_execute_from_path(cmd_name: &str, raw_cmd: &str) -> bool {
    let mut check_paths = [
        [0u8; 128],
        [0u8; 128],
        [0u8; 128],
        [0u8; 128],
        [0u8; 128],
        [0u8; 128],
    ];
    let mut check_count = 0;

    let has_path_separator = cmd_name.contains('\\') || cmd_name.contains('/');

    if has_path_separator {
        let bytes = cmd_name.as_bytes();
        let len = bytes.len().min(127);
        check_paths[check_count][..len].copy_from_slice(&bytes[..len]);
        check_count += 1;

        if !cmd_name.ends_with(".vex") && check_count < 6 {
            let mut l = 0;
            check_paths[check_count][..len].copy_from_slice(&bytes[..len]);
            l += len;
            check_paths[check_count][l..l + 4].copy_from_slice(b".vex");
            check_count += 1;
        }
    } else {
        let path_str = get_path();
        for dir in path_str.split(';') {
            let dir_trimmed = dir.trim();
            if dir_trimmed.is_empty() {
                continue;
            }

            if check_count < 5 {
                let mut buf = [0u8; 128];
                let mut l = 0;
                let db = dir_trimmed.as_bytes();
                buf[..db.len()].copy_from_slice(db);
                l += db.len();
                if l > 0 && buf[l - 1] != b'\\' && buf[l - 1] != b'/' {
                    buf[l] = b'\\';
                    l += 1;
                }
                let cb = cmd_name.as_bytes();
                let clen = cb.len().min(127 - l);
                buf[l..l + clen].copy_from_slice(&cb[..clen]);
                l += clen;

                if cmd_name.ends_with(".vex") {
                    check_paths[check_count][..l].copy_from_slice(&buf[..l]);
                    check_count += 1;
                } else {
                    if l + 4 <= 127 {
                        let mut buf_vex = buf;
                        let l_vex = l;
                        buf_vex[l_vex..l_vex + 4].copy_from_slice(b".vex");
                        check_paths[check_count][..l_vex + 4].copy_from_slice(&buf_vex[..l_vex + 4]);
                        check_count += 1;
                    }
                    if check_count < 6 {
                        check_paths[check_count][..l].copy_from_slice(&buf[..l]);
                        check_count += 1;
                    }
                }
            }
        }
    }

    let args = if let Some(idx) = raw_cmd.find(' ') {
        raw_cmd[idx + 1..].trim()
    } else {
        ""
    };

    // Check each resolved path on the real filesystem
    for i in 0..check_count {
        let p_str = match core::str::from_utf8(&check_paths[i]) {
            Ok(s) => s.trim_matches('\0'),
            Err(_) => continue,
        };
        if p_str.is_empty() {
            continue;
        }

        let n = unsafe { sys_vfs_read(p_str, &mut ELF_LOAD_BUF) };
        if n >= 64 {
            let elf = unsafe { &ELF_LOAD_BUF[..n] };
            if elf.starts_with(b"\x7fELF") {
                // Real 64-bit ELF executable found on disk!
                let entry = u64::from_le_bytes(elf[0x18..0x20].try_into().unwrap_or([0; 8])) as usize;
                let phoff = u64::from_le_bytes(elf[0x20..0x28].try_into().unwrap_or([0; 8])) as usize;
                let phentsize = u16::from_le_bytes(elf[0x36..0x38].try_into().unwrap_or([0; 2])) as usize;
                let phnum = u16::from_le_bytes(elf[0x38..0x3A].try_into().unwrap_or([0; 2])) as usize;

                for p in 0..phnum {
                    let offset = phoff + p * phentsize;
                    if offset + 56 <= elf.len() {
                        let p_type = u32::from_le_bytes(elf[offset..offset + 4].try_into().unwrap_or([0; 4]));
                        if p_type == 1 { // PT_LOAD
                            let p_offset = u64::from_le_bytes(elf[offset + 8..offset + 16].try_into().unwrap_or([0; 8])) as usize;
                            let p_vaddr = u64::from_le_bytes(elf[offset + 16..offset + 24].try_into().unwrap_or([0; 8])) as usize;
                            let p_filesz = u64::from_le_bytes(elf[offset + 32..offset + 40].try_into().unwrap_or([0; 8])) as usize;
                            let p_memsz = u64::from_le_bytes(elf[offset + 40..offset + 48].try_into().unwrap_or([0; 8])) as usize;

                            if p_offset + p_filesz <= elf.len() {
                                unsafe {
                                    core::ptr::copy_nonoverlapping(
                                        elf.as_ptr().add(p_offset),
                                        p_vaddr as *mut u8,
                                        p_filesz,
                                    );
                                    if p_memsz > p_filesz {
                                        core::ptr::write_bytes(
                                            (p_vaddr + p_filesz) as *mut u8,
                                            0,
                                            p_memsz - p_filesz,
                                        );
                                    }
                                }
                            }
                        }
                    }
                }

                if entry != 0 {
                    // Call executable passing arguments
                    let entry_fn: extern "C" fn(*const u8, usize) -> usize = unsafe { core::mem::transmute(entry) };
                    entry_fn(args.as_ptr(), args.len());
                    return true;
                }
            }
        }
    }

    false
}

// -----------------------------------------------------------------------------
// Core Internal Commands Dispatch
// -----------------------------------------------------------------------------
fn handle_command(cmd_line: &str) {
    let cmd = cmd_line.trim();
    if cmd.is_empty() {
        return;
    }

    // Core internal built-ins only
    if eq_ignore_ascii_case(cmd, "help") {
        println("VladOS 10 Professional - Command Prompt Reference:");
        println("--------------------------------------------------");
        println("  DIR [path]         - List directory contents on C:, D:, E:, U:");
        println("  CD [path]          - Change working directory");
        println("  TYPE <file>        - Display contents of a text file");
        println("  ECHO <text>        - Print text (use '> file' to write to file)");
        println("  MKDIR <path>       - Create a new directory");
        println("  DEL / RM <path>    - Delete a file");
        println("  REN <old> <new>    - Rename a file or directory");
        println("  CHMOD <mode> <p>   - Change permissions");
        println("  PATH [args]        - View or modify execution PATH");
        println("  SU <user>          - Switch user context");
        println("  CLS                - Clear screen");
        println("  VER                - Print VladOS version information");
        println("  EXIT               - Return to graphical desktop");
        println("");
        println("All other commands are resolved dynamically from PATH (.vex binaries).");
    } else if eq_ignore_ascii_case(cmd, "ver") {
        println("VladOS [Version 10.0.22000.1] - 64-bit Hybrid Microkernel");
    } else if eq_ignore_ascii_case(cmd, "cls") {
        print("\x1b[2J\x1b[H");
    } else if eq_ignore_ascii_case(cmd, "path") || starts_with_ignore_case(cmd, "path ") {
        let args = if starts_with_ignore_case(cmd, "path ") {
            cmd[5..].trim()
        } else {
            ""
        };
        handle_path_command(args);
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
    } else if eq_ignore_ascii_case(cmd, "exit") {
        println("Returning to VladOS Desktop & Window Manager...");
        let dwm_entry: extern "C" fn() -> ! = unsafe { core::mem::transmute(0x100000usize) };
        dwm_entry();
    } else {
        // Any other command is searched and executed from PATH
        let first_word = if let Some(idx) = cmd.find(' ') {
            &cmd[..idx]
        } else {
            cmd
        };

        if !try_execute_from_path(first_word, cmd) {
            print("'");
            print(cmd);
            println("' is not recognized as an internal or external command,");
            println("operable program or batch file.");
        }
    }
}

// -----------------------------------------------------------------------------
// Interactive Tab Autocompletion with Candidate Cycling
// -----------------------------------------------------------------------------
fn interactive_tab_complete(buf: &mut [u8; 256], len: &mut usize) {
    let cmd_candidates = [
        "cls", "dir", "echo", "exit", "explorer", "explorer.vex", "dwm", "dwm.vex",
        "help", "sysinfo", "sysinfo.vex", "type", "ver", "whoami", "whoami.vex",
        "net", "net.vex", "cd", "path", "disks", "diskpart", "diskpart.vex",
        "diskutil", "diskutil.vex", "pathedit", "pathedit.vex",
        "calc", "calc.vex", "notepad", "notepad.vex",
        "player", "player.vex", "photos", "photos.vex",
        "cmd", "cmd.vex", "vladinit.vex", "chkdsk", "chkdsk.vex",
        "reboot", "restart", "shutdown", "shutdown.vex",
        "C:\\VladOS", "C:\\VladOS\\System32", "C:\\VladOS\\Resources",
        "C:\\VladOS\\System32\\Config\\path.cfg",
        "C:\\Users\\Vlad\\Desktop", "C:\\Users\\Vlad\\Documents",
        "C:\\Users\\Vlad\\Pictures", "C:\\Users\\Vlad\\Music", "C:\\Users\\Vlad\\Videos",
        "Welcome.txt", "Notes.txt", "ambient.mp3", "startup.wav", "wallpaper.bmp", "logo.png", "photo.jpg",
    ];

    let current_input = match core::str::from_utf8(&buf[..*len]) {
        Ok(s) => s,
        Err(_) => return,
    };
    if current_input.is_empty() {
        return;
    }

    let (prefix_len, last_token) = if let Some(idx) = current_input.rfind(' ') {
        (idx + 1, &current_input[idx + 1..])
    } else {
        (0, current_input)
    };
    if last_token.is_empty() {
        return;
    }

    let mut matches: [&'static str; 16] = [""; 16];
    let mut match_count = 0;
    for &cand in &cmd_candidates {
        if starts_with_ignore_case(cand, last_token) {
            if match_count < 16 {
                matches[match_count] = cand;
                match_count += 1;
            }
        }
    }

    if match_count == 0 {
        return;
    }

    if match_count == 1 {
        let cand = matches[0];
        let remainder = &cand[last_token.len()..];
        for rb in remainder.bytes() {
            if *len < buf.len() {
                buf[*len] = rb;
                *len += 1;
            }
        }
        print(remainder);
        if *len < buf.len() && !cand.contains('\\') {
            buf[*len] = b' ';
            *len += 1;
            print(" ");
        }
        return;
    }

    let original_len = *len;
    let mut original_buf = [0u8; 256];
    original_buf[..original_len].copy_from_slice(&buf[..original_len]);

    let mut selected: usize = 0;

    print("\r\n");
    print("\x1b[36m[TAB Selector: Use \x1b[1mLeft/Right/Up/Down/Tab\x1b[0;36m to choose, Enter to accept, Esc to cancel]\x1b[0m\r\n");

    loop {
        print("\r");
        for i in 0..match_count {
            if i == selected {
                print("\x1b[7;1m [");
                print(matches[i]);
                print("] \x1b[0m ");
            } else {
                print("  ");
                print(matches[i]);
                print("  ");
            }
        }
        print("\x1b[K\r\n");

        let cand = matches[selected];
        *len = prefix_len;
        for b in cand.bytes() {
            if *len < buf.len() {
                buf[*len] = b;
                *len += 1;
            }
        }
        print("\rC:\\VladOS\\System32> ");
        if let Ok(s) = core::str::from_utf8(&buf[..*len]) {
            print(s);
        }
        print("\x1b[K");

        let mut key_seq = [0u8; 4];
        let kn = unsafe { sys_read(0, &mut key_seq) };
        if kn == 0 {
            unsafe { sys_yield() };
            continue;
        }

        let k0 = key_seq[0];
        if k0 == b'\r' || k0 == b'\n' {
            if *len < buf.len() && !cand.contains('\\') {
                buf[*len] = b' ';
                *len += 1;
            }
            print("\r\n");
            break;
        } else if k0 == b'\t' {
            selected = (selected + 1) % match_count;
            print("\x1b[1A");
        } else if kn >= 3 && key_seq[0] == 27 && key_seq[1] == b'[' {
            let arrow = key_seq[2];
            if arrow == b'C' || arrow == b'B' {
                selected = (selected + 1) % match_count;
                print("\x1b[1A");
            } else if arrow == b'D' || arrow == b'A' {
                selected = if selected == 0 { match_count - 1 } else { selected - 1 };
                print("\x1b[1A");
            }
        } else if k0 == 27 {
            *len = original_len;
            buf[..original_len].copy_from_slice(&original_buf[..original_len]);
            print("\r\nC:\\VladOS\\System32> ");
            if let Ok(s) = core::str::from_utf8(&buf[..*len]) {
                print(s);
            }
            print("\x1b[K");
            break;
        } else if k0 >= 32 && k0 <= 126 {
            if *len < buf.len() {
                buf[*len] = k0;
                *len += 1;
            }
            print("\r\nC:\\VladOS\\System32> ");
            if let Ok(s) = core::str::from_utf8(&buf[..*len]) {
                print(s);
            }
            print("\x1b[K");
            break;
        }
    }
}

#[no_mangle]
pub extern "C" fn _start() -> ! {
    init_path();

    print("\x1b[2J\x1b[H");
    println("VladOS [Version 10.0.22000.1]");
    println("(c) 2026 Vlad Corporation. All rights reserved.");
    println("");
    println("VladOS Command Prompt. Type HELP for internal commands or run any .vex from PATH.");
    println("");

    let mut buf = [0u8; 256];
    let mut len: usize;

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
                if len > 0 {
                    len -= 1;
                    print("\x08 \x08");
                }
            } else if c == b'\t' {
                interactive_tab_complete(&mut buf, &mut len);
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
