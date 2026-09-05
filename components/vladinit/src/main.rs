#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
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

#[no_mangle]
pub extern "C" fn _start() -> ! {
    println("=================================================");
    println("   VladOS System Supervisor: vladinit.vex");
    println("   Architecture: x86_64 | Ring 3 Userspace");
    println("   Filesystem: VladFS (/VladOS/System32/)");
    println("=================================================");
    println("[vladinit.vex] Loading system services...");
    println("[vladinit.vex] Initializing display and keyboard...");
    println("[vladinit.vex] VladOS ready for user login.");

    loop {
        unsafe {
            sys_yield();
        }
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    println("[vladinit.vex] CRITICAL PANIC in Ring 3!");
    loop {
        unsafe {
            sys_yield();
        }
    }
}
