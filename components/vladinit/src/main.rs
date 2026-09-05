#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_YIELD: usize = 158;

const SYS_NANOSLEEP: usize = 162;

#[repr(C)]
struct TimeSpec {
    tv_sec: i64,
    tv_nsec: i32,
}

#[inline(always)]
unsafe fn sys_nanosleep(req: &TimeSpec) {
    core::arch::asm!(
        "syscall",
        in("rax") SYS_NANOSLEEP,
        in("rdi") req as *const _ as usize,
        in("rsi") 0,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
}

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
    // vladinit.vex (PID 1) holds master control over system startup
    // 1. Maintain bootscreen while initializing core system services
    let sleep_req = TimeSpec {
        tv_sec: 2,
        tv_nsec: 500_000_000, // 2.5 seconds of bootscreen animation
    };
    unsafe {
        sys_nanosleep(&sleep_req);
    }

    // 2. Transition from quiet bootscreen to interactive console
    print("\x1b[2J\x1b[H");

    println("=================================================");
    println("   VladOS System Supervisor: vladinit.vex");
    println("   Architecture: x86_64 | Ring 3 Userspace (PID 1)");
    println("   Filesystem: VladFS (/VladOS/System32/)");
    println("=================================================");
    println("[vladinit] Full control transferred from kernel to vladinit.vex.");
    println("[vladinit] Loading system configuration (/VladOS/System32/config/system.ini)...");
    println("[vladinit] Storage subsystem active: VladFS mounted on C:\\");
    println("[vladinit] Spawning shell: /VladOS/System32/cmd.vex...");
    println("-------------------------------------------------");
    println("");
    println("VladOS [Version 10.0.22000.1]");
    println("(c) 2026 Vlad Corporation. All rights reserved.");
    println("");
    print("C:\\VladOS\\System32> ");
    println("ver");
    println("VladOS [Version 10.0.22000.1] - 64-bit Hybrid Kernel (Ring 3)");
    println("");
    print("C:\\VladOS\\System32> ");
    println("sysinfo");
    println("Host Name:                 VLADOS-PC");
    println("OS Name:                   VladOS 10 Professional");
    println("OS Version:                1.0.0 Build 2026.09.05");
    println("OS Architecture:           x86_64 Long Mode (64-bit)");
    println("Executable Standard:       .vex (Vlad EXecutable)");
    println("Root Filesystem:           VladFS (Volume: VLADOS_SYS)");
    println("Display:                   1280x800x32 Linear GOP Framebuffer");
    println("Theme Engine:              Windows 10 Fluent Dark");
    println("Cloud Portal:              https://vladinc.ru/vlados/");
    println("");
    print("C:\\VladOS\\System32> ");
    println("dir");
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
    println("09/05/2026  01:00 PM             2,480 cmd.vex");
    println("               2 File(s)          3,830 bytes");
    println("               4 Dir(s)      31,457,280 bytes free");
    println("");
    print("C:\\VladOS\\System32> ");

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
