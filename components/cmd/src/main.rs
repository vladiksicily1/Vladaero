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

fn print(s: &str) {
    unsafe {
        sys_write(1, s.as_bytes());
    }
}

fn println(s: &str) {
    print(s);
    print("\n");
}

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
        println("EXIT       Quits the CMD.VEX program (command interpreter).");
        println("HELP       Provides Help information for VladOS commands.");
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
        println("Executable Standard:       .vex (Vlad EXecutable)");
        println("Root Filesystem:           VladFS (Volume: VLADOS_SYS)");
        println("Display:                   1280x800x32 Linear GOP Framebuffer");
        println("Theme Engine:              Windows 10 Fluent Dark");
        println("Cloud Portal:              https://vladinc.ru/vlados/");
    } else if eq_ignore_ascii_case(cmd, "dir") {
        println(" Volume in drive C is VLADOS_SYS");
        println(" Volume Serial Number is 564C-4144");
        println("");
        println(" Directory of C:\\VladOS\\System32");
        println("");
        println("09/05/2026  01:00 PM    <DIR>          .");
        println("09/05/2026  01:00 PM    <DIR>          ..");
        println("09/05/2026  01:00 PM    <DIR>          config");
        println("09/05/2026  01:00 PM    <DIR>          drivers");
        println("09/05/2026  01:00 PM             1,350 vladinit.vex");
        println("09/05/2026  01:00 PM             2,180 cmd.vex");
        println("               2 File(s)          3,530 bytes");
        println("               4 Dir(s)      24,117,248 bytes free");
    } else if starts_with_ignore_case(cmd, "echo ") {
        println(&cmd[5..]);
    } else if starts_with_ignore_case(cmd, "type ") {
        let file = cmd[5..].trim();
        if file.ends_with("system.ini") {
            println("; VladOS Configuration");
            println("[System]");
            println("OSName=VladOS");
            println("Version=1.0.0");
            println("Architecture=x86_64");
            println("Init=/VladOS/System32/vladinit.vex");
            println("Shell=/VladOS/System32/cmd.vex");
            println("Theme=FluentDark");
        } else if file.ends_with("account.cfg") {
            println("; VladOS Account Settings");
            println("[Account]");
            println("DefaultUser=Vlad");
            println("AuthURL=https://vladinc.ru/vlados/api/auth");
        } else {
            print("The system cannot find the file specified: ");
            println(file);
        }
    } else if eq_ignore_ascii_case(cmd, "exit") {
        println("Exiting VladOS Command Interpreter...");
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
