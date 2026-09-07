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

const SYS_VLADOS_DRIVES: usize = 0x5652;
const SYS_VLADOS_RESCAN: usize = 0x5653;
const SYS_VLADOS_DISK_OP: usize = 0x5654;

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

#[inline(always)]
unsafe fn sys_disk_op(cmd: &str, buf: &mut [u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_DISK_OP => ret,
        in("rdi") cmd.as_ptr() as usize,
        in("rsi") cmd.len(),
        in("rdx") buf.as_mut_ptr() as usize,
        in("r10") buf.len(),
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

fn eq_ignore_case(a: &str, b: &str) -> bool {
    if a.len() != b.len() {
        return false;
    }
    a.bytes().zip(b.bytes()).all(|(x, y)| x.to_ascii_lowercase() == y.to_ascii_lowercase())
}

fn starts_with_ignore_case(s: &str, prefix: &str) -> bool {
    if s.len() < prefix.len() {
        return false;
    }
    eq_ignore_case(&s[..prefix.len()], prefix)
}

static mut DRIVES_BUF: [u8; 4096] = [0u8; 4096];
static mut OP_BUF: [u8; 2048] = [0u8; 2048];

struct DiskPartState {
    selected_disk: usize,
    selected_volume: usize,
    selected_partition: usize,
}

static mut STATE: DiskPartState = DiskPartState {
    selected_disk: 0,
    selected_volume: 0,
    selected_partition: 1,
};

#[no_mangle]
pub extern "C" fn _start(arg_ptr: *const u8, arg_len: usize) -> usize {
    let args = if arg_ptr.is_null() || arg_len == 0 {
        ""
    } else {
        unsafe {
            core::str::from_utf8(core::slice::from_raw_parts(arg_ptr, arg_len)).unwrap_or("")
        }
    };

    println("");
    println("VladOS DiskPart version 10.0.22000.1");
    println("Copyright (C) Vlad Corporation. All rights reserved.");
    println("On computer: VLADOS-PC");
    println("");

    if !args.trim().is_empty() {
        dispatch_command(args.trim());
        return 0;
    }

    // Interactive REPL loop
    let mut line_buf = [0u8; 256];
    loop {
        print("DISKPART> ");
        let mut line_len = 0;
        loop {
            let mut ch = [0u8; 1];
            let n = unsafe { sys_read(0, &mut ch) };
            if n == 1 {
                if ch[0] == b'\r' || ch[0] == b'\n' {
                    println("");
                    break;
                } else if ch[0] == 0x08 || ch[0] == 0x7F {
                    if line_len > 0 {
                        line_len -= 1;
                        print("\x08 \x08");
                    }
                } else if ch[0] >= 0x20 && ch[0] < 0x7F && line_len < 250 {
                    line_buf[line_len] = ch[0];
                    line_len += 1;
                    unsafe { sys_write(1, &ch) };
                }
            } else {
                unsafe { sys_yield() };
            }
        }

        if line_len == 0 {
            continue;
        }

        if let Ok(line) = core::str::from_utf8(&line_buf[..line_len]) {
            let cmd = line.trim();
            if eq_ignore_case(cmd, "exit") || eq_ignore_case(cmd, "quit") {
                println("Leaving DiskPart...");
                break;
            }
            dispatch_command(cmd);
        }
    }

    0
}

fn execute_op(cmd: &str) {
    let n = unsafe { sys_disk_op(cmd, &mut OP_BUF) };
    if n > 0 && n <= 2048 {
        if let Ok(resp) = core::str::from_utf8(unsafe { &OP_BUF[..n] }) {
            print(resp);
            if !resp.ends_with('\n') {
                println("");
            }
        }
    } else {
        println("Operation failed or not supported by kernel.");
    }
}

fn get_drives_info() -> &'static str {
    let n = unsafe { sys_drives(&mut DRIVES_BUF) };
    if n > 0 && n <= 4096 {
        unsafe { core::str::from_utf8(&DRIVES_BUF[..n]).unwrap_or("") }
    } else {
        ""
    }
}

fn dispatch_command(cmd: &str) {
    if eq_ignore_case(cmd, "help") || eq_ignore_case(cmd, "?") {
        print_help();
        return;
    }

    if eq_ignore_case(cmd, "filesystems") {
        execute_op("filesystems");
        return;
    }

    if eq_ignore_case(cmd, "rescan") {
        println("Please wait while DiskPart scans your configuration...");
        unsafe { sys_rescan() };
        println("DiskPart has finished scanning your configuration.");
        return;
    }

    // 1. LIST Commands
    if starts_with_ignore_case(cmd, "list ") {
        let sub = cmd[5..].trim();
        if eq_ignore_case(sub, "disk") {
            list_disks();
        } else if eq_ignore_case(sub, "volume") || eq_ignore_case(sub, "vol") {
            list_volumes();
        } else if eq_ignore_case(sub, "partition") || eq_ignore_case(sub, "part") {
            list_partitions();
        } else if eq_ignore_case(sub, "vdisk") {
            println("There are no virtual disks to show.");
        } else {
            println("Invalid list argument. Usage: LIST [DISK | VOLUME | PARTITION | VDISK]");
        }
        return;
    }

    // 2. SELECT Commands
    if starts_with_ignore_case(cmd, "select ") || starts_with_ignore_case(cmd, "sel ") {
        let sub = if starts_with_ignore_case(cmd, "select ") { cmd[7..].trim() } else { cmd[4..].trim() };
        if starts_with_ignore_case(sub, "disk ") {
            let num = sub[5..].trim().parse::<usize>().unwrap_or(0);
            unsafe { STATE.selected_disk = num };
            print("Disk ");
            print(sub[5..].trim());
            println(" is now the selected disk.");
        } else if starts_with_ignore_case(sub, "volume ") || starts_with_ignore_case(sub, "vol ") {
            let rest = if starts_with_ignore_case(sub, "volume ") { sub[7..].trim() } else { sub[4..].trim() };
            let num = rest.parse::<usize>().unwrap_or(0);
            unsafe { STATE.selected_volume = num };
            print("Volume ");
            print(rest);
            println(" is now the selected volume.");
        } else if starts_with_ignore_case(sub, "partition ") || starts_with_ignore_case(sub, "part ") {
            let rest = if starts_with_ignore_case(sub, "partition ") { sub[10..].trim() } else { sub[5..].trim() };
            let num = rest.parse::<usize>().unwrap_or(1);
            unsafe { STATE.selected_partition = num };
            print("Partition ");
            print(rest);
            println(" is now the selected partition.");
        } else {
            println("Usage: SELECT [DISK <N> | VOLUME <N> | PARTITION <N>]");
        }
        return;
    }

    // 3. DETAIL Commands
    if starts_with_ignore_case(cmd, "detail ") {
        let sub = cmd[7..].trim();
        if eq_ignore_case(sub, "disk") {
            detail_disk();
        } else if eq_ignore_case(sub, "volume") || eq_ignore_case(sub, "vol") {
            detail_volume();
        } else if eq_ignore_case(sub, "partition") || eq_ignore_case(sub, "part") {
            detail_partition();
        } else {
            println("Usage: DETAIL [DISK | VOLUME | PARTITION]");
        }
        return;
    }

    // 4. CONVERT Commands (Dynamic / Basic / GPT / MBR)
    if starts_with_ignore_case(cmd, "convert ") {
        let sub = cmd[8..].trim();
        let cur_disk = unsafe { STATE.selected_disk };
        if eq_ignore_case(sub, "dynamic") {
            let mut op_str = [0u8; 64];
            let s = format_op_convert(cur_disk, "dynamic", &mut op_str);
            execute_op(s);
        } else if eq_ignore_case(sub, "basic") {
            let mut op_str = [0u8; 64];
            let s = format_op_convert(cur_disk, "basic", &mut op_str);
            execute_op(s);
        } else if eq_ignore_case(sub, "gpt") {
            let mut op_str = [0u8; 64];
            let s = format_op_convert(cur_disk, "gpt", &mut op_str);
            execute_op(s);
        } else if eq_ignore_case(sub, "mbr") {
            let mut op_str = [0u8; 64];
            let s = format_op_convert(cur_disk, "mbr", &mut op_str);
            execute_op(s);
        } else {
            println("Usage: CONVERT [DYNAMIC | BASIC | GPT | MBR]");
        }
        return;
    }

    // 5. CREATE VOLUME Commands (Simple / Stripe / Span)
    if starts_with_ignore_case(cmd, "create volume ") {
        let sub = cmd[14..].trim();
        let mut vtype = "simple";
        let mut size_mb = "1024";
        let mut fs = "VladFS";

        if starts_with_ignore_case(sub, "simple") {
            vtype = "simple";
        } else if starts_with_ignore_case(sub, "stripe") {
            vtype = "stripe";
            fs = "NTFS";
        } else if starts_with_ignore_case(sub, "span") {
            vtype = "span";
            fs = "ext4";
        }

        for part in sub.split_ascii_whitespace() {
            if starts_with_ignore_case(part, "size=") {
                size_mb = &part[5..];
            } else if starts_with_ignore_case(part, "fs=") {
                fs = &part[3..];
            }
        }

        let cur_disk = unsafe { STATE.selected_disk };
        let mut op_cmd = [0u8; 128];
        let mut i = 0;
        let p1 = b"create_volume type=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i..i+vtype.len()].copy_from_slice(vtype.as_bytes()); i += vtype.len();
        let p2 = b" disk=";
        op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
        op_cmd[i] = b'0' + (cur_disk as u8); i += 1;
        let p3 = b" size=";
        op_cmd[i..i+p3.len()].copy_from_slice(p3); i += p3.len();
        op_cmd[i..i+size_mb.len()].copy_from_slice(size_mb.as_bytes()); i += size_mb.len();
        let p4 = b" fs=";
        op_cmd[i..i+p4.len()].copy_from_slice(p4); i += p4.len();
        op_cmd[i..i+fs.len()].copy_from_slice(fs.as_bytes()); i += fs.len();
        let p5 = b" label=NEW_VOL";
        op_cmd[i..i+p5.len()].copy_from_slice(p5); i += p5.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // 6. CREATE PARTITION Commands
    if starts_with_ignore_case(cmd, "create partition ") || starts_with_ignore_case(cmd, "create part ") {
        println("DiskPart succeeded in creating the specified partition.");
        return;
    }

    // 7. FORMAT Command
    if starts_with_ignore_case(cmd, "format") {
        let cur_vol = unsafe { STATE.selected_volume };
        let mut target_letter = get_volume_letter(cur_vol);
        let mut fs = "VladFS";
        let mut label = "NEW_VOLUME";

        for part in cmd.split_ascii_whitespace() {
            if part.len() == 2 && part.as_bytes()[1] == b':' && part.as_bytes()[0].is_ascii_alphabetic() {
                target_letter = part.as_bytes()[0].to_ascii_uppercase() as char;
            } else if starts_with_ignore_case(part, "drive=") && part.len() >= 7 {
                target_letter = part.as_bytes()[6].to_ascii_uppercase() as char;
            } else if starts_with_ignore_case(part, "letter=") && part.len() >= 8 {
                target_letter = part.as_bytes()[7].to_ascii_uppercase() as char;
            } else if starts_with_ignore_case(part, "fs=") {
                fs = &part[3..];
            } else if starts_with_ignore_case(part, "label=") {
                label = &part[6..];
            }
        }

        let mut op_cmd = [0u8; 128];
        let mut i = 0;
        let p1 = b"format drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i] = target_letter as u8; i += 1;
        let p2 = b" fs=";
        op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
        op_cmd[i..i+fs.len()].copy_from_slice(fs.as_bytes()); i += fs.len();
        let p3 = b" label=";
        op_cmd[i..i+p3.len()].copy_from_slice(p3); i += p3.len();
        op_cmd[i..i+label.len()].copy_from_slice(label.as_bytes()); i += label.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // 8. ASSIGN / MOUNT Command
    if starts_with_ignore_case(cmd, "assign") || starts_with_ignore_case(cmd, "mount") {
        let mut letter: Option<char> = None;
        let mut fs = "VladFS";
        let mut label = "MOUNTED_VOL";
        let mut size_mb = "1024";
        let mut layout = "Simple";
        let cur_disk = unsafe { STATE.selected_disk };

        for part in cmd.split_ascii_whitespace() {
            if starts_with_ignore_case(part, "letter=") && part.len() >= 8 {
                letter = Some(part.as_bytes()[7].to_ascii_uppercase() as char);
            } else if starts_with_ignore_case(part, "drive=") && part.len() >= 7 {
                letter = Some(part.as_bytes()[6].to_ascii_uppercase() as char);
            } else if part.len() == 2 && part.as_bytes()[1] == b':' && part.as_bytes()[0].is_ascii_alphabetic() {
                letter = Some(part.as_bytes()[0].to_ascii_uppercase() as char);
            } else if starts_with_ignore_case(part, "fs=") {
                fs = &part[3..];
            } else if starts_with_ignore_case(part, "label=") {
                label = &part[6..];
            } else if starts_with_ignore_case(part, "size=") {
                size_mb = &part[5..];
            } else if starts_with_ignore_case(part, "layout=") {
                layout = &part[7..];
            }
        }

        let mut op_cmd = [0u8; 128];
        let mut i = 0;
        let p1 = b"mount drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        if let Some(l) = letter {
            op_cmd[i] = l as u8; i += 1;
        } else {
            let auto = b"auto";
            op_cmd[i..i+auto.len()].copy_from_slice(auto); i += auto.len();
        }
        let p2 = b" disk=";
        op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
        op_cmd[i] = b'0' + (cur_disk as u8); i += 1;
        let p3 = b" size=";
        op_cmd[i..i+p3.len()].copy_from_slice(p3); i += p3.len();
        op_cmd[i..i+size_mb.len()].copy_from_slice(size_mb.as_bytes()); i += size_mb.len();
        let p4 = b" fs=";
        op_cmd[i..i+p4.len()].copy_from_slice(p4); i += p4.len();
        op_cmd[i..i+fs.len()].copy_from_slice(fs.as_bytes()); i += fs.len();
        let p5 = b" label=";
        op_cmd[i..i+p5.len()].copy_from_slice(p5); i += p5.len();
        op_cmd[i..i+label.len()].copy_from_slice(label.as_bytes()); i += label.len();
        let p6 = b" layout=";
        op_cmd[i..i+p6.len()].copy_from_slice(p6); i += p6.len();
        op_cmd[i..i+layout.len()].copy_from_slice(layout.as_bytes()); i += layout.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // 9. REMOVE / UNMOUNT Command
    if starts_with_ignore_case(cmd, "remove") || starts_with_ignore_case(cmd, "unmount") {
        let cur_vol = unsafe { STATE.selected_volume };
        let mut letter = get_volume_letter(cur_vol);
        for part in cmd.split_ascii_whitespace() {
            if starts_with_ignore_case(part, "letter=") && part.len() >= 8 {
                letter = part.as_bytes()[7].to_ascii_uppercase() as char;
            } else if starts_with_ignore_case(part, "drive=") && part.len() >= 7 {
                letter = part.as_bytes()[6].to_ascii_uppercase() as char;
            } else if part.len() == 2 && part.as_bytes()[1] == b':' && part.as_bytes()[0].is_ascii_alphabetic() {
                letter = part.as_bytes()[0].to_ascii_uppercase() as char;
            }
        }
        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"unmount drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i] = letter as u8; i += 1;

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // 10. EXTEND Command
    if starts_with_ignore_case(cmd, "extend") {
        let cur_vol = unsafe { STATE.selected_volume };
        let letter = get_volume_letter(cur_vol);
        let mut size_mb = "512";
        for part in cmd.split_ascii_whitespace() {
            if starts_with_ignore_case(part, "size=") {
                size_mb = &part[5..];
            }
        }
        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"extend drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i] = letter as u8; i += 1;
        let p2 = b" size=";
        op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
        op_cmd[i..i+size_mb.len()].copy_from_slice(size_mb.as_bytes()); i += size_mb.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // 11. SHRINK Command
    if starts_with_ignore_case(cmd, "shrink") {
        let cur_vol = unsafe { STATE.selected_volume };
        let letter = get_volume_letter(cur_vol);
        let mut size_mb = "256";
        for part in cmd.split_ascii_whitespace() {
            if starts_with_ignore_case(part, "desired=") {
                size_mb = &part[8..];
            } else if starts_with_ignore_case(part, "size=") {
                size_mb = &part[5..];
            }
        }
        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"shrink drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i] = letter as u8; i += 1;
        let p2 = b" desired=";
        op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
        op_cmd[i..i+size_mb.len()].copy_from_slice(size_mb.as_bytes()); i += size_mb.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // 12. CLEAN Command
    if starts_with_ignore_case(cmd, "clean") {
        let cur_disk = unsafe { STATE.selected_disk };
        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"clean disk=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i] = b'0' + (cur_disk as u8); i += 1;

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // 13. ONLINE / OFFLINE Commands
    if starts_with_ignore_case(cmd, "online ") {
        let cur_disk = unsafe { STATE.selected_disk };
        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"online disk=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i] = b'0' + (cur_disk as u8); i += 1;

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    if starts_with_ignore_case(cmd, "offline ") {
        let cur_disk = unsafe { STATE.selected_disk };
        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"offline disk=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i] = b'0' + (cur_disk as u8); i += 1;

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // 14. ACTIVE / INACTIVE
    if eq_ignore_case(cmd, "active") {
        println("DiskPart marked the current partition as active.");
        return;
    }
    if eq_ignore_case(cmd, "inactive") {
        println("DiskPart marked the current partition as inactive.");
        return;
    }

    println("DiskPart does not recognize this command. Type 'help' for a list of commands.");
}

fn format_op_convert<'a>(disk: usize, target: &str, buf: &'a mut [u8; 64]) -> &'a str {
    let mut i = 0;
    let p1 = b"convert disk=";
    buf[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
    buf[i] = b'0' + (disk as u8); i += 1;
    buf[i] = b' '; i += 1;
    buf[i..i+target.len()].copy_from_slice(target.as_bytes()); i += target.len();
    core::str::from_utf8(&buf[..i]).unwrap_or("")
}

fn get_volume_letter(vol_idx: usize) -> char {
    let info = get_drives_info();
    if let Some(idx) = info.find("=== VOLUMES ===") {
        for line in info[idx..].lines().skip(1) {
            let mut parts = line.split('|');
            let v_str = parts.next().unwrap_or("");
            let ltr_str = parts.next().unwrap_or("");
            let num = if v_str.len() >= 7 {
                v_str[7..].trim().parse::<usize>().unwrap_or(usize::MAX)
            } else {
                usize::MAX
            };
            if num == vol_idx {
                if let Some(c) = ltr_str.trim().chars().next() {
                    return c.to_ascii_uppercase();
                }
            }
        }
    }
    match vol_idx {
        0 => 'C',
        1 => 'D',
        2 => 'E',
        3 => 'F',
        _ => 'D',
    }
}

fn format_num(n: usize) -> &'static str {
    match n {
        0 => "0", 1 => "1", 2 => "2", 3 => "3", 4 => "4", 5 => "5", 6 => "6", 7 => "7", 8 => "8", 9 => "9",
        _ => "0",
    }
}

fn list_disks() {
    println("");
    println("  Disk ###  Status         Size     Free     Dyn  Gpt");
    println("  --------  -------------  -------  -------  ---  ---");

    let info = get_drives_info();
    let cur_disk = unsafe { STATE.selected_disk };

    if let Some(idx) = info.find("=== DISKS ===") {
        let slice = &info[idx..];
        let end_idx = slice.find("=== VOLUMES ===").unwrap_or(slice.len());
        for line in slice[..end_idx].lines().skip(1) {
            let trimmed = line.trim();
            if trimmed.is_empty() { continue; }
            let mut parts = trimmed.split('|');
            let disk_name = parts.next().unwrap_or("");
            let status = parts.next().unwrap_or("Online");
            let size = parts.next().unwrap_or("");
            let free = parts.next().unwrap_or("");
            let is_dyn = parts.next().unwrap_or("").contains("Dynamic");
            let is_gpt = parts.next().unwrap_or("").contains("GPT");

            let num = if disk_name.len() >= 5 {
                disk_name[5..].parse::<usize>().unwrap_or(0)
            } else {
                0
            };

            let prefix = if num == cur_disk { "* " } else { "  " };
            print(prefix);
            print(disk_name);
            let p1_len = disk_name.len();
            for _ in 0..(10usize.saturating_sub(p1_len)) { print(" "); }
            print(status);
            let s_len = status.len();
            for _ in 0..(15usize.saturating_sub(s_len)) { print(" "); }
            print(size);
            let sz_len = size.len();
            for _ in 0..(9usize.saturating_sub(sz_len)) { print(" "); }
            print(free);
            let f_len = free.len();
            for _ in 0..(9usize.saturating_sub(f_len)) { print(" "); }
            print(if is_dyn { " *   " } else { "     " });
            println(if is_gpt { "*" } else { " " });
        }
    } else {
        println("  Disk 0    Online           32 MB      0 B        *");
        println("  Disk 1    Online         4096 MB  2048 MB   *    *");
        println("  Disk 2    Online        30720 MB  2120 MB");
    }
    println("");
}

fn list_volumes() {
    println("");
    println("  Volume ###  Ltr  Label           Fs       Type        Size     Status     Info");
    println("  ----------  ---  --------------  -------  ----------  -------  ---------  --------");

    let info = get_drives_info();
    let cur_vol = unsafe { STATE.selected_volume };

    if let Some(idx) = info.find("=== VOLUMES ===") {
        for line in info[idx..].lines().skip(1) {
            let trimmed = line.trim();
            if trimmed.is_empty() { continue; }
            let mut parts = trimmed.split('|');
            let vol_str = parts.next().unwrap_or("");
            let ltr = parts.next().unwrap_or(" ");
            let label = parts.next().unwrap_or("");
            let fs = parts.next().unwrap_or("");
            let layout = parts.next().unwrap_or("");
            let size_mb = parts.next().unwrap_or("");
            let _free = parts.next().unwrap_or("");
            let status = parts.next().unwrap_or("Healthy");
            let info_str = parts.next().unwrap_or("");

            let num = if vol_str.len() >= 7 {
                vol_str[7..].parse::<usize>().unwrap_or(0)
            } else {
                0
            };

            let prefix = if num == cur_vol { "* " } else { "  " };
            print(prefix);
            print(vol_str);
            for _ in 0..(12usize.saturating_sub(vol_str.len())) { print(" "); }
            print(ltr);
            for _ in 0..4 { print(" "); }
            print(label);
            for _ in 0..(16usize.saturating_sub(label.len())) { print(" "); }
            print(fs);
            for _ in 0..(9usize.saturating_sub(fs.len())) { print(" "); }
            print(layout);
            for _ in 0..(12usize.saturating_sub(layout.len())) { print(" "); }
            print(size_mb);
            print(" MB  ");
            for _ in 0..(5usize.saturating_sub(size_mb.len())) { print(" "); }
            print(status);
            for _ in 0..(11usize.saturating_sub(status.len())) { print(" "); }
            println(info_str);
        }
    } else {
        println("  Volume 0     C   VLADOS_SYS      VladFS   Partition     32 MB  Healthy    System, Boot");
        println("  Volume 1     D   DATA_DRIVE      FAT32    Simple      2048 MB  Healthy    Data");
        println("  Volume 2     E   VLADOS_INSTALL  ISO9660  DVD-ROM       97 MB  Healthy    Read-Only");
        println("  Volume 3     U   USB_FLASH       exFAT    Simple      30720 MB Healthy    Removable");
    }
    println("");
}

fn list_partitions() {
    let cur_disk = unsafe { STATE.selected_disk };
    println("");
    print("  Partition ###  Type              Size     Offset\r\n");
    print("  -------------  ----------------  -------  -------\r\n");
    if cur_disk == 0 {
        println("  Partition 1    System               1 MB  1024 KB");
        println("  Partition 2    Primary             31 MB  2048 KB");
    } else if cur_disk == 1 {
        println("  Partition 1    Dynamic Simple    2048 MB  1024 KB");
        println("  Partition 2    Dynamic Simple    2048 MB  2049 MB");
    } else {
        println("  Partition 1    Primary          30720 MB  1024 KB");
    }
    println("");
}

fn detail_disk() {
    let cur_disk = unsafe { STATE.selected_disk };
    println("");
    print("Disk ");
    print(format_num(cur_disk));
    println(" is now the selected disk.");
    println("");

    let info = get_drives_info();
    let mut disk_name = "Standard Storage Device";
    let mut is_dyn = false;
    let mut is_gpt = true;
    let mut size_mb = "4096 MB";

    if let Some(idx) = info.find("=== DISKS ===") {
        let slice = &info[idx..];
        let end_idx = slice.find("=== VOLUMES ===").unwrap_or(slice.len());
        for line in slice[..end_idx].lines().skip(1) {
            let mut parts = line.split('|');
            let d_str = parts.next().unwrap_or("");
            let _status = parts.next();
            let sz = parts.next().unwrap_or("");
            let _fr = parts.next();
            let dy = parts.next().unwrap_or("");
            let gp = parts.next().unwrap_or("");
            let nm = parts.next().unwrap_or("");
            if d_str.contains(&format_num(cur_disk)) {
                disk_name = nm;
                size_mb = sz;
                is_dyn = dy.contains("Dynamic");
                is_gpt = gp.contains("GPT");
                break;
            }
        }
    }

    print("Device Name:          "); println(disk_name);
    print("Type:                 "); println(if is_dyn { "Dynamic" } else { "Basic" });
    print("Status:               Online\r\n");
    print("Path:                 0\r\n");
    print("Target:               0\r\n");
    print("LUN ID:               0\r\n");
    print("Location Path:        PCIROOT(0)#PCI(0101)#ATA(C00T00L00)\r\n");
    print("Current Read-only State: No\r\n");
    print("Read-only:            No\r\n");
    print("Boot Disk:            "); println(if cur_disk == 0 { "Yes" } else { "No" });
    print("Pagefile Disk:        "); println(if cur_disk == 0 { "Yes" } else { "No" });
    print("Partition Style:      "); println(if is_gpt { "GPT" } else { "MBR" });
    print("Capacity:             "); println(size_mb);
    println("");
    list_volumes();
}

fn detail_volume() {
    let cur_vol = unsafe { STATE.selected_volume };
    let letter = get_volume_letter(cur_vol);
    println("");
    print("  Volume ");
    print(format_num(cur_vol));
    println(" is the selected volume.");
    println("");
    print("  Drive Letter:      ");
    let l_buf = [letter as u8, b':'];
    if let Ok(s) = core::str::from_utf8(&l_buf) {
        print(s);
    }
    println("");
    println("  State:             Healthy");
    println("  Read-only:         No");
    println("  Hidden:            No");
    println("  No Default Drive Letter: No");
    println("  Shadow Copy:       No");
    println("  Offline:           No");
    println("  BitLocker Encrypted: No");
    println("  Installable:       Yes");
    println("");
    println("  Allocation Unit Size: 4KB");
    println("");
}

fn detail_partition() {
    let cur_part = unsafe { STATE.selected_partition };
    println("");
    print("Partition ");
    print(format_num(cur_part));
    println("");
    println("Type    : 07");
    println("Hidden  : No");
    println("Active  : Yes");
    println("Offset in Bytes: 1048576");
    println("");
}

fn print_help() {
    println("");
    println("Microsoft Windows / VladOS 10 DiskPart commands:");
    println("");
    println("ACTIVE      - Mark the selected partition as active.");
    println("ASSIGN      - Assign a drive letter or mount point to the selected volume.");
    println("              ASSIGN [LETTER=<letter>]");
    println("CLEAN       - Clear the configuration information, or all information, off the disk.");
    println("CONVERT     - Converts between Basic/Dynamic and GPT/MBR disk formats.");
    println("CREATE      - Create a volume, partition, or virtual disk.");
    println("              CREATE VOLUME SIMPLE [SIZE=<N>] [DISK=<N>]");
    println("              CREATE VOLUME STRIPE [SIZE=<N>] DISK=<N>,<M>");
    println("              CREATE VOLUME SPAN   [SIZE=<N>] DISK=<N>,<M>");
    println("              CREATE PARTITION PRIMARY [SIZE=<N>]");
    println("DETAIL      - Provide details about an object (DISK, VOLUME, PARTITION).");
    println("EXIT        - Exit DiskPart.");
    println("EXTEND      - Extend a volume capacity.");
    println("FILESYSTEMS - Display supported file systems (VladFS, NTFS, exFAT, FAT32, ext4, UDF).");
    println("FORMAT      - Format the volume with specified file system.");
    println("              FORMAT [drive:] [FS=<VladFS|NTFS|exFAT|FAT32|ext4>] [LABEL=<label>] [QUICK]");
    println("HELP        - Prints a list of commands.");
    println("INACTIVE    - Mark the selected partition as inactive.");
    println("LIST        - Display a list of objects (DISK, VOLUME, PARTITION, VDISK).");
    println("MOUNT       - Mount a storage volume with dynamic drive letter assignment.");
    println("              MOUNT [LETTER=<letter>] [DISK=<N>] [SIZE=<MB>] [FS=<FS>] [LABEL=<label>]");
    println("ONLINE      - Online an object that is currently marked as offline.");
    println("OFFLINE     - Offline an object that is currently marked as online.");
    println("REMOVE      - Remove a drive letter or mount point assignment.");
    println("              REMOVE [LETTER=<letter>]");
    println("RESCAN      - Rescan the computer looking for disks and volumes.");
    println("SELECT      - Shift the focus to an object (DISK <N>, VOLUME <N>, PARTITION <N>).");
    println("SHRINK      - Reduce the size of the selected volume.");
    println("");
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
