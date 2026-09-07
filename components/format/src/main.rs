#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_YIELD: usize = 158;
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
    run_format(args);
    0
}

pub fn run_format(args: &str) {
    let clean = args.trim();
    if clean.is_empty() || clean == "/?" || clean == "-?" || clean == "help" {
        println("Formats a disk for use with VladOS 10.");
        println("");
        println("FORMAT volume [/FS:file-system] [/V:label] [/Q] [/A:size]");
        println("");
        println("  volume          Specifies the drive letter (followed by a colon).");
        println("  /FS:filesystem  Specifies the type of file system (VladFS, NTFS, exFAT,");
        println("                  FAT32, ext4, UDF).");
        println("  /V:label        Specifies the volume label.");
        println("  /Q              Performs a quick format.");
        println("  /A:size         Overrides the default allocation unit size.");
        return;
    }

    let mut drv_char = 'D';
    let mut fs = "VladFS";
    let mut label = "NEW_VOLUME";

    for part in clean.split_ascii_whitespace() {
        if part.len() == 2 && part.as_bytes()[1] == b':' && part.as_bytes()[0].is_ascii_alphabetic() {
            drv_char = part.as_bytes()[0].to_ascii_uppercase() as char;
        } else if starts_with_ignore_case(part, "/fs:") {
            fs = &part[4..];
        } else if starts_with_ignore_case(part, "-fs:") {
            fs = &part[4..];
        } else if starts_with_ignore_case(part, "/v:") {
            label = &part[3..];
        } else if starts_with_ignore_case(part, "-v:") {
            label = &part[3..];
        }
    }

    if drv_char == 'C' {
        println("Error: Access Denied. Cannot format active system boot volume C:\\.");
        return;
    }

    let mut op_cmd = [0u8; 128];
    let mut i = 0;
    let p1 = b"format drive=";
    op_cmd[i..i+p1.len()].copy_from_slice(p1); i += p1.len();
    op_cmd[i] = drv_char as u8; i += 1;
    let p2 = b" fs=";
    op_cmd[i..i+p2.len()].copy_from_slice(p2); i += p2.len();
    op_cmd[i..i+fs.len()].copy_from_slice(fs.as_bytes()); i += fs.len();
    let p3 = b" label=";
    op_cmd[i..i+p3.len()].copy_from_slice(p3); i += p3.len();
    op_cmd[i..i+label.len()].copy_from_slice(label.as_bytes()); i += label.len();

    if let Ok(s) = core::str::from_utf8(&op_cmd[..i]) {
        let n = unsafe { sys_disk_op(s, &mut OP_BUF) };
        if n > 0 && n <= 2048 {
            if let Ok(resp) = core::str::from_utf8(unsafe { &OP_BUF[..n] }) {
                print(resp);
                if !resp.ends_with('\n') {
                    println("");
                }
            }
        } else {
            println("Format failed.");
        }
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
