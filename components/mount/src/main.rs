#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_YIELD: usize = 158;
const SYS_VLADOS_DRIVES: usize = 0x5652;
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

#[no_mangle]
pub extern "C" fn _start(arg_ptr: *const u8, arg_len: usize) -> usize {
    let args = if arg_ptr.is_null() || arg_len == 0 {
        ""
    } else {
        unsafe {
            core::str::from_utf8(core::slice::from_raw_parts(arg_ptr, arg_len)).unwrap_or("")
        }
    };
    run_mount(args);
    0
}

pub fn run_mount(args: &str) {
    let clean = args.trim();
    if clean == "/?" || clean == "-?" || clean == "help" {
        println("VladOS 10 Volume Mount Manager (mount / mountvol)");
        println("");
        println("Creates, deletes, or lists a volume mount point.");
        println("");
        println("MOUNTVOL [drive:] [/D] [/L] [/P]");
        println("MOUNT    <drive:> <size_mb> <fs> [label] [layout]");
        println("");
        println("  /D      Removes the volume mount point from specified drive letter.");
        println("  /L      Lists the mounted volume name for the specified drive letter.");
        println("  /P      Removes the volume mount point and dismounts volume.");
        println("");
        println("Examples:");
        println("  mount                          (lists all active mounted volumes)");
        println("  mount F: 1024 NTFS DATA_DRIVE  (mounts 1024 MB NTFS volume as F:)");
        println("  mount /d F:                    (dismounts volume F:)");
        return;
    }

    if clean.is_empty() {
        // List volumes
        println("Possible values for VolumeName along with current mount points:");
        println("");
        #[allow(static_mut_refs)]
        let n = unsafe { sys_drives(&mut DRIVES_BUF) };
        if n > 0 && n <= 4096 {
            #[allow(static_mut_refs)]
            if let Ok(info) = core::str::from_utf8(unsafe { &DRIVES_BUF[..n] }) {
                if let Some(idx) = info.find("=== VOLUMES ===") {
                    for line in info[idx..].lines().skip(1) {
                        let trimmed = line.trim();
                        if trimmed.is_empty() { continue; }
                        let mut parts = trimmed.split('|');
                        let _vol_id = parts.next().unwrap_or("");
                        let ltr = parts.next().unwrap_or("");
                        let label = parts.next().unwrap_or("");
                        let fs = parts.next().unwrap_or("");
                        let layout = parts.next().unwrap_or("");
                        let size = parts.next().unwrap_or("");

                        print("    \\\\?\\Volume{");
                        print(label);
                        print("}\\ (");
                        print(fs);
                        print(", ");
                        print(size);
                        print(" MB, ");
                        print(layout);
                        println(")");
                        print("        ");
                        print(ltr);
                        println(":\\");
                    }
                } else {
                    print(info);
                }
            }
        }
        return;
    }

    // Dismount: mount /d <drv> or mountvol <drv> /d
    if starts_with_ignore_case(clean, "/d") || starts_with_ignore_case(clean, "-d") || clean.ends_with("/d") || clean.ends_with("/D") {
        let mut drv = 'D';
        for part in clean.split_ascii_whitespace() {
            if part.len() >= 2 && part.as_bytes()[1] == b':' {
                drv = part.as_bytes()[0].to_ascii_uppercase() as char;
            }
        }
        let mut op_cmd = [0u8; 64];
        let mut i = 0;
        let p1 = b"unmount drive=";
        op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
        op_cmd[i] = drv as u8; i += 1;

        if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
            #[allow(static_mut_refs)]
            let n = unsafe { sys_disk_op(s, &mut OP_BUF) };
            if n > 0 && n <= 2048 {
                #[allow(static_mut_refs)]
                if let Ok(resp) = core::str::from_utf8(unsafe { &OP_BUF[..n] }) {
                    print(resp);
                    if !resp.ends_with('\n') { println(""); }
                }
            }
        }
        return;
    }

    // Mount command: mount <drive:> <size_mb> <fs> [label] [layout]
    let mut words = clean.split_ascii_whitespace();
    let drv_str = words.next().unwrap_or("F:");
    let drv_char = if drv_str.len() >= 2 && drv_str.as_bytes()[1] == b':' {
        drv_str.as_bytes()[0].to_ascii_uppercase() as char
    } else {
        'F'
    };
    let size = words.next().unwrap_or("1024");
    let fs = words.next().unwrap_or("VladFS");
    let label = words.next().unwrap_or("MOUNTED_VOL");
    let layout = words.next().unwrap_or("Simple");

    let mut op_cmd = [0u8; 128];
    let mut i = 0;
    let p1 = b"mount drive=";
    op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
    op_cmd[i] = drv_char as u8; i += 1;
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
        #[allow(static_mut_refs)]
        let n = unsafe { sys_disk_op(s, &mut OP_BUF) };
        if n > 0 && n <= 2048 {
            #[allow(static_mut_refs)]
            if let Ok(resp) = core::str::from_utf8(unsafe { &OP_BUF[..n] }) {
                print(resp);
                if !resp.ends_with('\n') { println(""); }
            }
        }
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
