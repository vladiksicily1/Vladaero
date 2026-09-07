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

static mut BUF: [u8; 4096] = [0u8; 4096];
static mut OP_BUF: [u8; 2048] = [0u8; 2048];

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

#[derive(Clone, Copy)]
struct ParsedVol<'a> {
    vol_str: &'a str,
    ltr: &'a str,
    label: &'a str,
    fs: &'a str,
    layout: &'a str,
    size_mb: &'a str,
    free_mb: &'a str,
    status: &'a str,
}

fn show_overview() {
    println("");
    println("================================================================================");
    println("          VladOS 10 Disk Management Console (diskmgmt.msc / diskutil)           ");
    println("================================================================================");
    println(" Volume List:");
    println(" -------------------------------------------------------------------------------");
    println(" Vol  Ltr  Label           File System  Layout      Capacity  Free     Status");
    println(" ---  ---  --------------  -----------  ----------  --------  -------  ---------");

    let n = unsafe { sys_drives(&mut BUF) };
    let info = if n > 0 && n <= 4096 {
        unsafe { core::str::from_utf8(&BUF[..n]).unwrap_or("") }
    } else {
        ""
    };

    let mut parsed_vols: [Option<ParsedVol>; 16] = [None; 16];
    let mut parsed_cnt = 0;

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
            let free_mb = parts.next().unwrap_or("");
            let status = parts.next().unwrap_or("Healthy");

            if parsed_cnt < 16 {
                parsed_vols[parsed_cnt] = Some(ParsedVol {
                    vol_str,
                    ltr,
                    label,
                    fs,
                    layout,
                    size_mb,
                    free_mb,
                    status,
                });
                parsed_cnt += 1;
            }

            print(" ");
            print(vol_str);
            for _ in 0..(9usize.saturating_sub(vol_str.len())) { print(" "); }
            print(ltr);
            for _ in 0..4 { print(" "); }
            print(label);
            for _ in 0..(16usize.saturating_sub(label.len())) { print(" "); }
            print(fs);
            for _ in 0..(13usize.saturating_sub(fs.len())) { print(" "); }
            print(layout);
            for _ in 0..(12usize.saturating_sub(layout.len())) { print(" "); }
            print(size_mb);
            print(" MB  ");
            for _ in 0..(5usize.saturating_sub(size_mb.len())) { print(" "); }
            print(free_mb);
            print(" MB  ");
            for _ in 0..(5usize.saturating_sub(free_mb.len())) { print(" "); }
            println(status);
        }
    } else {
        println("  Vol 0  C:   VLADOS_SYS      VladFS       Partition      32 MB    24 MB  Healthy (Boot)");
    }

    println("");
    println(" Physical / Dynamic Disks Diagram (Win10 Graphical View):");
    println(" -------------------------------------------------------------------------------");
    if let Some(idx) = info.find("=== DISKS ===") {
        let slice = &info[idx..];
        let end_idx = slice.find("=== VOLUMES ===").unwrap_or(slice.len());
        for line in slice[..end_idx].lines().skip(1) {
            let trimmed = line.trim();
            if trimmed.is_empty() { continue; }
            let mut parts = trimmed.split('|');
            let d_str = parts.next().unwrap_or("");
            let status = parts.next().unwrap_or("Online");
            let size = parts.next().unwrap_or("");
            let _fr = parts.next();
            let is_dyn = parts.next().unwrap_or("Basic");
            let is_gpt = parts.next().unwrap_or("GPT");
            let name = parts.next().unwrap_or("");

            print(" [");
            print(d_str);
            print("] ");
            print(is_dyn);
            print(" (");
            print(size);
            print(", ");
            print(status);
            print(", ");
            print(is_gpt);
            print(") - ");
            println(name);

            // Dynamic graphical block representation:
            if d_str.contains("0") {
                let c_vol = parsed_vols[0].unwrap_or(ParsedVol {
                    vol_str: "Vol 0",
                    ltr: "C",
                    label: "VLADOS_SYS",
                    fs: "VladFS",
                    layout: "Partition",
                    size_mb: "32",
                    free_mb: "24",
                    status: "Healthy (Boot)",
                });
                print("   | [EFI ESP: 64 MB] | [");
                print(c_vol.ltr);
                print(": ");
                print(c_vol.label);
                print(" (");
                print(c_vol.fs);
                print(", ");
                print(c_vol.size_mb);
                print(" MB, Healthy)] |");
                println("");
            } else {
                // Find matching volume for this secondary disk, if any
                let mut found = false;
                for v_opt in parsed_vols.iter().skip(1) {
                    if let Some(v) = v_opt {
                        print("   | [");
                        print(v.ltr);
                        print(": ");
                        print(v.label);
                        print(" (");
                        print(v.fs);
                        print(", ");
                        print(v.size_mb);
                        print(" MB, ");
                        print(v.status);
                        print(")] |");
                        println("");
                        found = true;
                    }
                }
                if !found {
                    println("   | [Unallocated: Dynamic Storage Available]                           |");
                }
            }
            println("   +---------------------------------------------------------------------+");
        }
    } else {
        println(" [Disk 0] Basic (98 MB, Online, GPT) - PCI Storage (Boot)");
        println("   | [EFI ESP: 64 MB] | [C: VLADOS_SYS (VladFS, 32 MB, Healthy)] |");
    }
    println("");
}

pub fn execute_diskutil_cmd(cmd: &str) {
    let clean = cmd.trim();
    if clean.is_empty() {
        return;
    }

    if eq_ignore_case(clean, "help") || eq_ignore_case(clean, "?") {
        print_help();
        return;
    }

    if eq_ignore_case(clean, "filesystems") {
        execute_op("filesystems");
        return;
    }

    if eq_ignore_case(clean, "list") || eq_ignore_case(clean, "volumes") || eq_ignore_case(clean, "disks") || eq_ignore_case(clean, "visual") {
        show_overview();
        return;
    }

    if eq_ignore_case(clean, "rescan") || clean == "/rescan" {
        println("Probing PCI AHCI, IDE, NVMe, and USB storage buses in real-time...");
        let count = unsafe { sys_rescan() };
        print("Hardware scan completed. Active storage volumes detected: ");
        let mut n = count;
        let mut num_buf = [0u8; 10];
        let mut i = 0;
        if n == 0 {
            print("0");
        } else {
            while n > 0 {
                num_buf[i] = b'0' + (n % 10) as u8;
                n /= 10;
                i += 1;
            }
            for j in (0..i).rev() {
                let b = [num_buf[j]];
                print(core::str::from_utf8(&b).unwrap_or(""));
            }
        }
        println("");
        show_overview();
        return;
    }

    // FORMAT: format <drive_letter> [fs] [label]
    if starts_with_ignore_case(clean, "format ") {
        let rest = clean[7..].trim();
        let mut words = rest.split_ascii_whitespace();
        let drv = words.next().unwrap_or("D");
        let drv_clean = if drv.len() >= 2 && drv.as_bytes()[1] == b':' { &drv[..1] } else { drv };
        let fs = words.next().unwrap_or("VladFS");
        let label = words.next().unwrap_or("NEW_VOL");

        let mut op_cmd = [0u8; 128];
        let mut i = 0;
        let p1 = b"format drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i..i+drv_clean.len()].copy_from_slice(drv_clean.as_bytes()); i += drv_clean.len();
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

    // MOUNT: mount [drive_letter] [size_mb] [fs] [label] [layout]
    if starts_with_ignore_case(clean, "mount") {
        let rest = clean[5..].trim();
        let mut words = rest.split_ascii_whitespace();
        let first = words.next().unwrap_or("auto");
        let (drv_clean, size) = if first.chars().all(|c| c.is_ascii_digit()) {
            ("auto", first)
        } else {
            let cl = if first.len() >= 2 && first.as_bytes()[1] == b':' { &first[..1] } else { first };
            (cl, words.next().unwrap_or("1024"))
        };
        let fs = words.next().unwrap_or("VladFS");
        let label = words.next().unwrap_or("STORAGE");
        let layout = words.next().unwrap_or("Simple");

        let mut op_cmd = [0u8; 128];
        let mut i = 0;
        let p1 = b"mount drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i..i+drv_clean.len()].copy_from_slice(drv_clean.as_bytes()); i += drv_clean.len();
        let p2 = b" size=";
        op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
        op_cmd[i..i+size.len()].copy_from_slice(size.as_bytes()); i += size.len();
        let p3 = b" fs=";
        op_cmd[i..i+p3.len()].copy_from_slice(p3); i += p3.len();
        op_cmd[i..i+fs.len()].copy_from_slice(fs.as_bytes()); i += fs.len();
        let p4 = b" label=";
        op_cmd[i..i+p4.len()].copy_from_slice(p4); i += p4.len();
        op_cmd[i..i+label.len()].copy_from_slice(label.as_bytes()); i += label.len();
        let p5 = b" layout=";
        op_cmd[i..i+p5.len()].copy_from_slice(p5); i += p5.len();
        op_cmd[i..i+layout.len()].copy_from_slice(layout.as_bytes()); i += layout.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // UNMOUNT: unmount <drive_letter>
    if starts_with_ignore_case(clean, "unmount ") || starts_with_ignore_case(clean, "remove ") {
        let rest = if starts_with_ignore_case(clean, "unmount ") { clean[8..].trim() } else { clean[7..].trim() };
        let drv_clean = if rest.len() >= 2 && rest.as_bytes()[1] == b':' { &rest[..1] } else { rest };

        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"unmount drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i..i+drv_clean.len()].copy_from_slice(drv_clean.as_bytes()); i += drv_clean.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // EXTEND: extend <drive_letter> <size_mb>
    if starts_with_ignore_case(clean, "extend ") {
        let rest = clean[7..].trim();
        let mut words = rest.split_ascii_whitespace();
        let drv = words.next().unwrap_or("D");
        let drv_clean = if drv.len() >= 2 && drv.as_bytes()[1] == b':' { &drv[..1] } else { drv };
        let size = words.next().unwrap_or("512");

        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"extend drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i..i+drv_clean.len()].copy_from_slice(drv_clean.as_bytes()); i += drv_clean.len();
        let p2 = b" size=";
        op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
        op_cmd[i..i+size.len()].copy_from_slice(size.as_bytes()); i += size.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // SHRINK: shrink <drive_letter> <size_mb>
    if starts_with_ignore_case(clean, "shrink ") {
        let rest = clean[7..].trim();
        let mut words = rest.split_ascii_whitespace();
        let drv = words.next().unwrap_or("D");
        let drv_clean = if drv.len() >= 2 && drv.as_bytes()[1] == b':' { &drv[..1] } else { drv };
        let size = words.next().unwrap_or("256");

        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"shrink drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i..i+drv_clean.len()].copy_from_slice(drv_clean.as_bytes()); i += drv_clean.len();
        let p2 = b" desired=";
        op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
        op_cmd[i..i+size.len()].copy_from_slice(size.as_bytes()); i += size.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // CONVERT: convert <disk_id> <dynamic|basic>
    if starts_with_ignore_case(clean, "convert ") {
        let rest = clean[8..].trim();
        let mut words = rest.split_ascii_whitespace();
        let disk_id = words.next().unwrap_or("1");
        let target = words.next().unwrap_or("dynamic");

        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"convert disk=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i..i+disk_id.len()].copy_from_slice(disk_id.as_bytes()); i += disk_id.len();
        op_cmd[i] = b' '; i += 1;
        op_cmd[i..i+target.len()].copy_from_slice(target.as_bytes()); i += target.len();

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            execute_op(s);
        }
        return;
    }

    // Pass through to kernel disk op
    execute_op(clean);
}

fn print_help() {
    println("");
    println("VladOS 10 Disk Management (diskutil.vex) Commands:");
    println("--------------------------------------------------------------------------------");
    println("  list / volumes / visual   - View disk and volume graphical layout table");
    println("  filesystems               - List supported filesystems (VladFS, NTFS, exFAT, etc.)");
    println("  format <drv> <fs> [label] - Format volume with VladFS, NTFS, exFAT, FAT32, ext4");
    println("  mount <drv> <mb> <fs>     - Mount new volume on disk (Simple, Spanned, Striped)");
    println("  unmount <drv>             - Dismount volume and free drive letter");
    println("  extend <drv> <mb>         - Increase volume capacity");
    println("  shrink <drv> <mb>         - Decrease volume capacity");
    println("  convert <disk> <dyn|bas>  - Convert disk between Dynamic and Basic format");
    println("  clean <disk>              - Wipe partition and volume data off disk");
    println("  online / offline <disk>   - Set disk online or offline");
    println("  rescan                    - Probes PCI/SATA/USB storage hardware controllers");
    println("  exit / quit               - Exit Disk Management");
    println("--------------------------------------------------------------------------------");
    println("");
}

#[no_mangle]
pub extern "C" fn _start(arg_ptr: *const u8, arg_len: usize) -> usize {
    let args = if arg_ptr.is_null() || arg_len == 0 {
        ""
    } else {
        unsafe {
            core::str::from_utf8(core::slice::from_raw_parts(arg_ptr, arg_len)).unwrap_or("")
        }
    };
    if !args.trim().is_empty() {
        execute_diskutil_cmd(args.trim());
        return 0;
    }

    show_overview();

    let mut line_buf = [0u8; 128];

    loop {
        print("diskutil> ");
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
            println("Exiting VladOS Disk Management...");
            break;
        } else if cmd.is_empty() {
            continue;
        }

        execute_diskutil_cmd(cmd);
    }

    0
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    print("\r\n[diskutil Panic]\r\n");
    loop {
        unsafe { sys_yield() };
    }
}
