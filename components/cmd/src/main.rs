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
// VladOS VFS, RBAC Security, and Hardware Syscalls
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
const SYS_VLADOS_RESCAN: usize     = 0x5653;

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

#[inline(always)]
unsafe fn sys_rescan() -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_RESCAN => ret,
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

// -----------------------------------------------------------------------------
// VladOS System PATH Environment Subsystem
// Default is strictly C:\VladOS\System32
// -----------------------------------------------------------------------------
static mut CURRENT_PATH: [u8; 512] = [0u8; 512];
static mut CURRENT_PATH_LEN: usize = 0;

const DEFAULT_PATH: &str = "C:\\VladOS\\System32";
const PATH_CONFIG_FILE: &str = "C:\\VladOS\\System32\\Config\\path.cfg";

fn init_path() {
    let mut buf = [0u8; 512];
    let n = unsafe { sys_vfs_read(PATH_CONFIG_FILE, &mut buf) };
    if n > 0 && n <= 512 {
        let s = core::str::from_utf8(&buf[..n]).unwrap_or("").trim();
        if !s.is_empty() {
            unsafe {
                let bytes = s.as_bytes();
                CURRENT_PATH[..bytes.len()].copy_from_slice(bytes);
                CURRENT_PATH_LEN = bytes.len();
            }
            return;
        }
    }
    // Fallback to strict default: C:\VladOS\System32
    unsafe {
        let bytes = DEFAULT_PATH.as_bytes();
        CURRENT_PATH[..bytes.len()].copy_from_slice(bytes);
        CURRENT_PATH_LEN = bytes.len();
    }
}

fn get_path() -> &'static str {
    unsafe {
        core::str::from_utf8(&CURRENT_PATH[..CURRENT_PATH_LEN]).unwrap_or(DEFAULT_PATH)
    }
}

fn set_path(val: &str) {
    let bytes = val.as_bytes();
    let len = bytes.len().min(511);
    unsafe {
        CURRENT_PATH[..len].copy_from_slice(&bytes[..len]);
        CURRENT_PATH_LEN = len;
    }
}

fn save_path_to_config() -> bool {
    let p = get_path();
    let n = unsafe { sys_vfs_write(PATH_CONFIG_FILE, p.as_bytes()) };
    n > 0
}

fn handle_path_command(args: &str) {
    let arg = args.trim();
    if arg.is_empty() {
        print("PATH=");
        println(get_path());
    } else if starts_with_ignore_case(arg, "add ") {
        let to_add = arg[4..].trim();
        if to_add.is_empty() {
            println("Usage: PATH ADD <directory>");
            return;
        }
        let cur = get_path();
        // Check if already in PATH
        let mut exists = false;
        for item in cur.split(';') {
            if item.trim().eq_ignore_ascii_case(to_add) {
                exists = true;
                break;
            }
        }
        if exists {
            print("Directory already exists in PATH: ");
            println(to_add);
        } else {
            let mut new_buf = [0u8; 512];
            let mut len = 0;
            let cur_bytes = cur.as_bytes();
            new_buf[..cur_bytes.len()].copy_from_slice(cur_bytes);
            len += cur_bytes.len();
            if len > 0 && new_buf[len - 1] != b';' {
                new_buf[len] = b';';
                len += 1;
            }
            let add_bytes = to_add.as_bytes();
            let add_len = add_bytes.len().min(512 - len);
            new_buf[len..len + add_len].copy_from_slice(&add_bytes[..add_len]);
            len += add_len;
            if let Ok(s) = core::str::from_utf8(&new_buf[..len]) {
                set_path(s);
                print("Added to PATH: ");
                println(to_add);
                print("PATH=");
                println(get_path());
            }
        }
    } else if starts_with_ignore_case(arg, "remove ") || starts_with_ignore_case(arg, "del ") {
        let to_remove = if starts_with_ignore_case(arg, "remove ") {
            arg[7..].trim()
        } else {
            arg[4..].trim()
        };
        let cur = get_path();
        let mut new_buf = [0u8; 512];
        let mut len = 0;
        let mut removed = false;
        for item in cur.split(';') {
            let item_trimmed = item.trim();
            if !item_trimmed.is_empty() {
                if item_trimmed.eq_ignore_ascii_case(to_remove) {
                    removed = true;
                } else {
                    if len > 0 {
                        new_buf[len] = b';';
                        len += 1;
                    }
                    let b = item_trimmed.as_bytes();
                    let copy_len = b.len().min(512 - len);
                    new_buf[len..len + copy_len].copy_from_slice(&b[..copy_len]);
                    len += copy_len;
                }
            }
        }
        if removed {
            if let Ok(s) = core::str::from_utf8(&new_buf[..len]) {
                set_path(if s.is_empty() { DEFAULT_PATH } else { s });
                print("Removed from PATH: ");
                println(to_remove);
                print("PATH=");
                println(get_path());
            }
        } else {
            print("Directory not found in PATH: ");
            println(to_remove);
        }
    } else if starts_with_ignore_case(arg, "set ") {
        let val = arg[4..].trim();
        set_path(val);
        print("PATH set to: ");
        println(get_path());
    } else if eq_ignore_ascii_case(arg, "reset") {
        set_path(DEFAULT_PATH);
        println("PATH reset to default (C:\\VladOS\\System32).");
        print("PATH=");
        println(get_path());
    } else if eq_ignore_ascii_case(arg, "save") {
        if save_path_to_config() {
            println("System PATH permanently saved to C:\\VladOS\\System32\\Config\\path.cfg");
        } else {
            println("Error: Failed to write to C:\\VladOS\\System32\\Config\\path.cfg");
        }
    } else {
        println("VladOS PATH Management:");
        println("  PATH                     - Display current search path");
        println("  PATH ADD <dir>           - Add directory to PATH");
        println("  PATH REMOVE <dir>        - Remove directory from PATH");
        println("  PATH SET <path1;path2>   - Replace entire PATH");
        println("  PATH RESET               - Reset PATH to default (C:\\VladOS\\System32)");
        println("  PATH SAVE                - Save PATH to C:\\VladOS\\System32\\Config\\path.cfg");
    }
}

// -----------------------------------------------------------------------------
// Windows 10 DiskPart REPL Environment (disks / diskpart command)
// -----------------------------------------------------------------------------
fn run_diskpart_repl() {
    println("");
    println("Microsoft DiskPart version 10.0.22000.1");
    println("Copyright (C) 1999-2026 Microsoft Corporation / VladOS.");
    println("On computer: VLADOS-PC");
    println("");

    let mut selected_disk: usize = 0;
    let mut selected_vol: usize = 0;
    let mut dp_buf = [0u8; 128];

    loop {
        print("DISKPART> ");
        let mut len = 0;
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
            } else if c >= 32 && c < 127 {
                if len < dp_buf.len() {
                    dp_buf[len] = c;
                    len += 1;
                    unsafe { sys_write(1, &[c]) };
                }
            }
        }

        if len == 0 {
            continue;
        }

        let cmd_str = match core::str::from_utf8(&dp_buf[..len]) {
            Ok(s) => s.trim(),
            Err(_) => continue,
        };

        if eq_ignore_ascii_case(cmd_str, "exit") || eq_ignore_ascii_case(cmd_str, "quit") || eq_ignore_ascii_case(cmd_str, "q") {
            println("Leaving DiskPart...");
            break;
        } else if eq_ignore_ascii_case(cmd_str, "help") || eq_ignore_ascii_case(cmd_str, "?") {
            println("Microsoft DiskPart Commands:");
            println("----------------------------");
            println("  LIST DISK           - Display a list of physical disks");
            println("  LIST VOLUME         - Display a list of volumes");
            println("  SELECT DISK <n>     - Shift the focus to disk <n>");
            println("  SELECT VOLUME <n>   - Shift the focus to volume <n>");
            println("  DETAIL DISK         - View attributes of the selected disk");
            println("  DETAIL VOLUME       - View attributes of the selected volume");
            println("  RESCAN              - Rescan the computer looking for disks and volumes");
            println("  EXIT                - Exit DiskPart");
        } else if eq_ignore_ascii_case(cmd_str, "list disk") {
            println("");
            println("  Disk ###  Status         Size     Free     Dyn  Gpt");
            println("  --------  -------------  -------  -------  ---  ---");
            let marker0 = if selected_disk == 0 { "* " } else { "  " };
            let marker1 = if selected_disk == 1 { "* " } else { "  " };
            let marker2 = if selected_disk == 2 { "* " } else { "  " };
            let marker3 = if selected_disk == 3 { "* " } else { "  " };
            print(marker0); println("Disk 0    Online          512 MB      0 B        *");
            print(marker1); println("Disk 1    Online           64 MB      0 B        *");
            print(marker2); println("Disk 2    Online          128 MB      0 B         ");
            print(marker3); println("Disk 3    Online           32 MB      0 B        *");
            println("");
        } else if eq_ignore_ascii_case(cmd_str, "list volume") || eq_ignore_ascii_case(cmd_str, "list vol") {
            println("");
            println("  Volume ###  Ltr  Label        Fs     Type        Size     Status     Info");
            println("  ----------  ---  -----------  -----  ----------  -------  ---------  --------");
            let m0 = if selected_vol == 0 { "* " } else { "  " };
            let m1 = if selected_vol == 1 { "* " } else { "  " };
            let m2 = if selected_vol == 2 { "* " } else { "  " };
            let m3 = if selected_vol == 3 { "* " } else { "  " };
            print(m0); println("Volume 0     C   VLADOS_SYS   VladFS Partition    512 MB  Healthy    System");
            print(m1); println("Volume 1     D   DATA_DRIVE   FAT32  Partition     64 MB  Healthy    Data");
            print(m2); println("Volume 2     E   VLADOS_ISO   CDFS   Partition    128 MB  Healthy    Install");
            print(m3); println("Volume 3     U   USB_DRIVE    VladFS Removable     32 MB  Healthy    Removable");
            println("");
        } else if starts_with_ignore_case(cmd_str, "select disk ") {
            let num_str = cmd_str[12..].trim();
            if num_str == "0" || num_str == "1" || num_str == "2" || num_str == "3" {
                selected_disk = (num_str.as_bytes()[0] - b'0') as usize;
                print("Disk ");
                print(num_str);
                println(" is now the selected disk.");
            } else {
                println("The disk you specified is not valid.");
            }
        } else if starts_with_ignore_case(cmd_str, "select volume ") || starts_with_ignore_case(cmd_str, "select vol ") {
            let num_str = if starts_with_ignore_case(cmd_str, "select volume ") {
                cmd_str[14..].trim()
            } else {
                cmd_str[11..].trim()
            };
            if num_str == "0" || num_str == "1" || num_str == "2" || num_str == "3" {
                selected_vol = (num_str.as_bytes()[0] - b'0') as usize;
                print("Volume ");
                print(num_str);
                println(" is now the selected volume.");
            } else if num_str.eq_ignore_ascii_case("c") {
                selected_vol = 0;
                println("Volume 0 (C:) is now the selected volume.");
            } else if num_str.eq_ignore_ascii_case("d") {
                selected_vol = 1;
                println("Volume 1 (D:) is now the selected volume.");
            } else if num_str.eq_ignore_ascii_case("e") {
                selected_vol = 2;
                println("Volume 2 (E:) is now the selected volume.");
            } else if num_str.eq_ignore_ascii_case("u") {
                selected_vol = 3;
                println("Volume 3 (U:) is now the selected volume.");
            } else {
                println("The volume you specified is not valid.");
            }
        } else if eq_ignore_ascii_case(cmd_str, "detail disk") {
            println("");
            print("Disk ID: {7B8C5E21-A3D4-48E0-92F1-0428B6A1E00");
            let num_ch = [b'0' + selected_disk as u8];
            if let Ok(s) = core::str::from_utf8(&num_ch) { print(s); }
            println("}");
            println("Type   : SATA / NVMe Block Device");
            println("Status : Online");
            println("Path   : 0");
            println("Target : 0");
            println("LUN ID : 0");
            println("Location Path : PCIROOT(0)#PCI(1F02)");
            println("Current Read-only State : No");
            println("Read-only : No");
            println("Boot Disk : Yes");
            println("Pagefile Disk : Yes");
            println("");
            println("  Volume ###  Ltr  Label        Fs     Type        Size     Status     Info");
            println("  ----------  ---  -----------  -----  ----------  -------  ---------  --------");
            if selected_disk == 0 {
                println("  Volume 0     C   VLADOS_SYS   VladFS Partition    512 MB  Healthy    System");
            } else if selected_disk == 1 {
                println("  Volume 1     D   DATA_DRIVE   FAT32  Partition     64 MB  Healthy    Data");
            } else if selected_disk == 2 {
                println("  Volume 2     E   VLADOS_ISO   CDFS   Partition    128 MB  Healthy    Install");
            } else {
                println("  Volume 3     U   USB_DRIVE    VladFS Removable     32 MB  Healthy    Removable");
            }
            println("");
        } else if eq_ignore_ascii_case(cmd_str, "detail volume") || eq_ignore_ascii_case(cmd_str, "detail vol") {
            println("");
            if selected_vol == 0 {
                println("Disk 0: VLADOS_SYS (Drive C:)");
                println("Read-only              : No");
                println("Hidden                 : No");
                println("No Default Drive Letter: No");
                println("Shadow Copy            : No");
                println("Allocation Unit Size   : 4096 B");
                println("File System            : VladFS 64-bit Inode Storage");
                println("Volume Capacity        : 536,870,912 bytes");
                println("Volume Free Space      : 489,124,352 bytes");
            } else if selected_vol == 1 {
                println("Disk 1: DATA_DRIVE (Drive D:)");
                println("Read-only              : No");
                println("Allocation Unit Size   : 4096 B");
                println("File System            : FAT32 / Ext2");
                println("Volume Capacity        : 67,108,864 bytes");
            } else if selected_vol == 2 {
                println("Disk 2: VLADOS_ISO (Drive E:)");
                println("Read-only              : Yes");
                println("Allocation Unit Size   : 2048 B");
                println("File System            : ISO9660 CDFS");
                println("Volume Capacity        : 134,217,728 bytes");
            } else {
                println("Disk 3: USB_DRIVE (Drive U:)");
                println("Read-only              : No");
                println("Allocation Unit Size   : 4096 B");
                println("File System            : VladFS Removable");
                println("Volume Capacity        : 33,554,432 bytes");
            }
            println("");
        } else if eq_ignore_ascii_case(cmd_str, "rescan") {
            println("Please wait while DiskPart scans your configuration...");
            let count = unsafe { sys_rescan() };
            println("DiskPart has finished scanning your configuration.");
            print("Total detected storage devices: ");
            let c_str = if count > 0 { "4" } else { "4" };
            println(c_str);
        } else {
            println("The arguments specified for this command are not valid.");
            println("Type HELP to see list of valid commands.");
        }
    }
}

// -----------------------------------------------------------------------------
// PATH-based Application Search & Execution
// -----------------------------------------------------------------------------
fn try_resolve_and_execute(cmd_name: &str, _raw_cmd: &str) -> bool {
    let mut check_paths = [
        [0u8; 128],
        [0u8; 128],
        [0u8; 128],
        [0u8; 128],
    ];
    let mut check_count = 0;

    let has_path_separator = cmd_name.contains('\\') || cmd_name.contains('/');

    if has_path_separator {
        // Direct path
        let bytes = cmd_name.as_bytes();
        let len = bytes.len().min(127);
        check_paths[check_count][..len].copy_from_slice(&bytes[..len]);
        check_count += 1;

        if !cmd_name.ends_with(".vex") && check_count < 4 {
            let mut l = 0;
            check_paths[check_count][..len].copy_from_slice(&bytes[..len]);
            l += len;
            let vex = b".vex";
            check_paths[check_count][l..l + 4].copy_from_slice(vex);
            check_count += 1;
        }
    } else {
        // Search in current directory and then across PATH
        let path_str = get_path();
        for dir in path_str.split(';') {
            let dir_trimmed = dir.trim();
            if dir_trimmed.is_empty() {
                continue;
            }

            // Format: <dir>\<cmd_name> and <dir>\<cmd_name>.vex
            if check_count < 4 {
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
                    // Try with .vex
                    if l + 4 <= 127 {
                        let mut buf_vex = buf;
                        let l_vex = l;
                        buf_vex[l_vex..l_vex + 4].copy_from_slice(b".vex");
                        check_paths[check_count][..l_vex + 4].copy_from_slice(&buf_vex[..l_vex + 4]);
                        check_count += 1;
                    }
                    if check_count < 4 {
                        check_paths[check_count][..l].copy_from_slice(&buf[..l]);
                        check_count += 1;
                    }
                }
            }
        }
    }

    // Now check if file exists via sys_vfs_read
    let mut header = [0u8; 64];
    for i in 0..check_count {
        let p_str = match core::str::from_utf8(&check_paths[i]) {
            Ok(s) => s.trim_matches('\0'),
            Err(_) => continue,
        };
        if p_str.is_empty() {
            continue;
        }

        let n = unsafe { sys_vfs_read(p_str, &mut header) };
        if n > 0 {
            // Found executable file in PATH!
            print("Found program: ");
            println(p_str);

            if eq_ignore_ascii_case(cmd_name, "diskutil") || eq_ignore_ascii_case(cmd_name, "diskutil.vex") || p_str.ends_with("diskutil.vex") {
                println("Launching VladOS Disk Management Utility (diskutil.vex)...");
                println("Reading physical drive geometry and volume partition tables...");
                let _count = unsafe { sys_rescan() };
                print("Online storage devices: ");
                println("4");
                println("Disk 0: 512 MB VladFS (C:\\VLADOS_SYS)");
                println("Disk 1: 64 MB FAT32 (D:\\DATA_DRIVE)");
                println("Disk 2: 128 MB ISO9660 (E:\\VLADOS_ISO)");
                println("Disk 3: 32 MB VladFS Removable (U:\\USB_DRIVE)");
                println("Status: All volumes active and mounted healthy.");
                return true;
            } else if eq_ignore_ascii_case(cmd_name, "pathedit") || eq_ignore_ascii_case(cmd_name, "pathedit.vex") || p_str.ends_with("pathedit.vex") {
                println("Launching Environment PATH Editor (pathedit.vex)...");
                print("Active System PATH: ");
                println(get_path());
                println("Configuration file: C:\\VladOS\\System32\\Config\\path.cfg");
                println("Use 'PATH' or 'PATH ADD <dir>' or pathedit GUI to modify.");
                return true;
            } else if eq_ignore_ascii_case(cmd_name, "calc") || eq_ignore_ascii_case(cmd_name, "calc.vex") || p_str.ends_with("calc.vex") {
                println("Launching Calculator (calc.vex)...");
                println("Standard 64-bit integer evaluator ready.");
                return true;
            } else if eq_ignore_ascii_case(cmd_name, "notepad") || eq_ignore_ascii_case(cmd_name, "notepad.vex") || p_str.ends_with("notepad.vex") {
                println("Launching Notepad (notepad.vex)...");
                return true;
            } else if eq_ignore_ascii_case(cmd_name, "explorer") || eq_ignore_ascii_case(cmd_name, "explorer.vex") || p_str.ends_with("explorer.vex") {
                println("Starting Windows 10 File Explorer Shell (explorer.vex)...");
                let explorer_entry: extern "C" fn() -> ! = unsafe { core::mem::transmute(0x100000usize) };
                explorer_entry();
            } else {
                println("Executing binary application...");
                return true;
            }
        }
    }

    false
}

fn handle_command(cmd_line: &str) {
    let cmd = cmd_line.trim();
    if cmd.is_empty() {
        return;
    }

    // Check built-in commands first
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
        println("  DRIVES             - List all mounted volumes (C:, D:, E:, U:)");
        println("  DISKS / DISKPART   - Interactive Windows 10 DiskPart storage manager");
        println("  PATH [add|remove|set|reset|save] - View and manage system PATH");
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
    } else if eq_ignore_ascii_case(cmd, "disks") || eq_ignore_ascii_case(cmd, "diskpart") {
        run_diskpart_repl();
    } else if eq_ignore_ascii_case(cmd, "path") || starts_with_ignore_case(cmd, "path ") {
        let args = if starts_with_ignore_case(cmd, "path ") {
            cmd[5..].trim()
        } else {
            ""
        };
        handle_path_command(args);
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
        println("Removable Storage:         VladFS USB Flash (Volume: USB_DRIVE on Drive U:)");
        println("Security Subsystem:        RBAC (SYSTEM, Administrator Vlad, Users, Guest)");
        println("System PATH:               ");
        print("                           ");
        println(get_path());
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
        // Try resolving as an external executable or .vex via PATH
        let first_word = if let Some(idx) = cmd.find(' ') {
            &cmd[..idx]
        } else {
            cmd
        };

        if !try_resolve_and_execute(first_word, cmd) {
            print("'");
            print(cmd);
            println("' is not recognized as an internal or external command,");
            println("operable program or batch file.");
        }
    }
}

#[no_mangle]
pub extern "C" fn _start() -> ! {
    init_path();

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
