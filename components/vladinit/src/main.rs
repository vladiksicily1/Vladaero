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
    let sleep_req = TimeSpec {
        tv_sec: 0,
        tv_nsec: 300_000_000,
    };
    unsafe {
        sys_nanosleep(&sleep_req);
    }

    print("\x1b[2J\x1b[H");

    println("=================================================");
    println("   VladOS System Supervisor: vladinit.vex");
    println("   Architecture: x86_64 | Ring 3 Userspace (PID 1)");
    println("   Filesystem: VladFS (/VladOS/System32/)");
    println("=================================================");
    println("[vladinit] Full control transferred from kernel to vladinit.vex.");
    println("[vladinit] Loading system configuration (/VladOS/System32/config/system.ini)...");
    println("[vladinit] Storage subsystem active: VladFS mounted on C:\\");
    println("[vladinit] Initializing Desktop Window Manager: /VladOS/System32/dwm.vex...");
    println("[vladinit] Launching VladOS Desktop Shell: /VladOS/System32/explorer.vex...");
    println("-------------------------------------------------");
    println("");

    load_and_run_explorer();
}

static EXPLORER_ELF: &[u8] = include_bytes!("../../explorer/target/x86_64-unknown-none/release/explorer_bin");

fn load_and_run_explorer() -> ! {
    if EXPLORER_ELF.len() >= 64 && EXPLORER_ELF.starts_with(b"\x7fELF") {
        let entry = u64::from_le_bytes(EXPLORER_ELF[0x18..0x20].try_into().unwrap_or([0; 8])) as usize;
        let phoff = u64::from_le_bytes(EXPLORER_ELF[0x20..0x28].try_into().unwrap_or([0; 8])) as usize;
        let phentsize = u16::from_le_bytes(EXPLORER_ELF[0x36..0x38].try_into().unwrap_or([0; 2])) as usize;
        let phnum = u16::from_le_bytes(EXPLORER_ELF[0x38..0x3A].try_into().unwrap_or([0; 2])) as usize;

        for i in 0..phnum {
            let offset = phoff + i * phentsize;
            if offset + 56 <= EXPLORER_ELF.len() {
                let p_type = u32::from_le_bytes(EXPLORER_ELF[offset..offset + 4].try_into().unwrap_or([0; 4]));
                if p_type == 1 { // PT_LOAD
                    let p_offset = u64::from_le_bytes(EXPLORER_ELF[offset + 8..offset + 16].try_into().unwrap_or([0; 8])) as usize;
                    let p_vaddr = u64::from_le_bytes(EXPLORER_ELF[offset + 16..offset + 24].try_into().unwrap_or([0; 8])) as usize;
                    let p_filesz = u64::from_le_bytes(EXPLORER_ELF[offset + 32..offset + 40].try_into().unwrap_or([0; 8])) as usize;
                    let p_memsz = u64::from_le_bytes(EXPLORER_ELF[offset + 40..offset + 48].try_into().unwrap_or([0; 8])) as usize;

                    if p_offset + p_filesz <= EXPLORER_ELF.len() {
                        unsafe {
                            core::ptr::copy_nonoverlapping(
                                EXPLORER_ELF.as_ptr().add(p_offset),
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
            let explorer_entry: extern "C" fn() -> ! = unsafe { core::mem::transmute(entry) };
            explorer_entry();
        }
    }

    println("[vladinit] Error: Failed to execute /VladOS/System32/explorer.vex");
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
