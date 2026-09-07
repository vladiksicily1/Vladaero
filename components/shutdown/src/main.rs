#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_YIELD: usize = 158;
const SYS_VLADOS_POWER: usize = 0x5656;

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
unsafe fn sys_power(action: usize) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_POWER => ret,
        in("rdi") action,
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

#[no_mangle]
pub extern "C" fn _start(arg_ptr: *const u8, arg_len: usize) -> usize {
    let args = if arg_ptr.is_null() || arg_len == 0 {
        ""
    } else {
        unsafe {
            core::str::from_utf8(core::slice::from_raw_parts(arg_ptr, arg_len)).unwrap_or("")
        }
    };
    run_shutdown(args);
    0
}

pub fn run_shutdown(args: &str) {
    let arg = args.trim();
    if arg.is_empty() || arg == "/?" || arg == "-?" || arg == "/help" || arg == "help" {
        println("Usage: shutdown [/i | /l | /s | /sg | /r | /g | /a | /p | /h | /e | /o] [/hybrid] [/soft] [/fw] [/f]");
        println("    [/?]    Display help. This is the same as not typing any options.");
        println("    [/l]    Log off.");
        println("    [/s]    Shutdown the computer via ACPI poweroff.");
        println("    [/r]    Full reboot and restart the computer via 8042 reset.");
        println("    [/p]    Turn off the local computer immediately.");
        return;
    }

    if eq_ignore_case(arg, "/r") || eq_ignore_case(arg, "-r") || eq_ignore_case(arg, "restart") {
        println("VladOS is restarting...");
        unsafe { sys_power(1) };
    } else if eq_ignore_case(arg, "/s") || eq_ignore_case(arg, "-s") || eq_ignore_case(arg, "/p") || eq_ignore_case(arg, "poweroff") {
        println("VladOS is shutting down...");
        unsafe { sys_power(0) };
    } else {
        print("shutdown: Unknown option '");
        print(arg);
        println("'. Type 'shutdown /?' for usage.");
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
