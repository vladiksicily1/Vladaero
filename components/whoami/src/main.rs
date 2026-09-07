#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_YIELD: usize = 158;
const SYS_VLADOS_WHOAMI: usize = 0x5650;

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

fn print(s: &str) {
    unsafe { sys_write(1, s.as_bytes()) };
}

fn println(s: &str) {
    print(s);
    print("\r\n");
}

static mut INFO_BUF: [u8; 4096] = [0u8; 4096];

#[no_mangle]
pub extern "C" fn _start(arg_ptr: *const u8, arg_len: usize) -> usize {
    let args = if arg_ptr.is_null() || arg_len == 0 {
        ""
    } else {
        unsafe {
            core::str::from_utf8(core::slice::from_raw_parts(arg_ptr, arg_len)).unwrap_or("")
        }
    };
    run_whoami(args);
    0
}

pub fn run_whoami(args: &str) {
    let arg = args.trim();
    if arg == "/?" || arg == "-?" || arg == "/help" {
        println("VladOS [Version 10.0.22000.1] whoami.vex");
        println("");
        println("WHOAMI [/USER] [/GROUPS] [/PRIV] [/ALL] [/?]");
        println("");
        println("Description:");
        println("    Displays user, group and privilege information for the current user in VladOS.");
        return;
    }

    #[allow(static_mut_refs)]
    let n = unsafe { sys_whoami(&mut INFO_BUF) };
    #[allow(static_mut_refs)]
    let info_str = if n > 0 && n <= 4096 {
        unsafe { core::str::from_utf8(&INFO_BUF[..n]).unwrap_or("") }
    } else {
        ""
    };

    let mut user_name = "VLADOS-PC\\Vlad";
    let mut user_sid = "S-1-5-21-3623811015-3361044348-30300820-1000";
    let mut role_str = "Administrator";
    let mut privs_str = "SeShutdownPrivilege, SeChangeNotifyPrivilege, SeSecurityPrivilege";

    for line in info_str.lines() {
        if line.starts_with("User Name:") {
            user_name = line[10..].trim();
        } else if line.starts_with("User SID:") {
            user_sid = line[9..].trim();
        } else if line.starts_with("Role / Class:") {
            role_str = line[13..].trim();
        } else if line.starts_with("Privileges:") {
            privs_str = line[11..].trim();
        }
    }

    if arg.is_empty() {
        println(user_name);
        return;
    }

    if arg.eq_ignore_ascii_case("/user") {
        println("");
        println("USER INFORMATION");
        println("----------------");
        println("");
        println("User Name                 SID");
        println("========================= ===============================================");
        print(user_name);
        let pad = if user_name.len() < 26 { 26 - user_name.len() } else { 1 };
        for _ in 0..pad {
            print(" ");
        }
        println(user_sid);
        return;
    }

    if arg.eq_ignore_ascii_case("/priv") {
        println("");
        println("PRIVILEGES INFORMATION");
        println("----------------------");
        println("");
        println("Privilege Name                Description                          State");
        println("============================= ==================================== ========");
        for priv_item in privs_str.split(',') {
            let p = priv_item.trim();
            if !p.is_empty() {
                print(p);
                let pad = if p.len() < 30 { 30 - p.len() } else { 1 };
                for _ in 0..pad {
                    print(" ");
                }
                print("System security token privilege      ");
                println("Enabled");
            }
        }
        return;
    }

    if arg.eq_ignore_ascii_case("/groups") {
        println("");
        println("GROUP INFORMATION");
        println("-----------------");
        println("");
        println("Group Name                               Type             SID          Attributes");
        println("======================================== ================ ============ ==================================================");
        if role_str.contains("Admin") || role_str.contains("Root") {
            println("VLADOS-PC\\Administrators                 Alias            S-1-5-32-544 Mandatory group, Enabled by default, Enabled group");
        }
        println("Everyone                                 Well-known group S-1-1-0      Mandatory group, Enabled by default, Enabled group");
        println("NT AUTHORITY\\Authenticated Users         Well-known group S-1-5-11     Mandatory group, Enabled by default, Enabled group");
        return;
    }

    // /all: full dump from kernel
    if arg.eq_ignore_ascii_case("/all") {
        print(info_str);
        if !info_str.ends_with('\n') {
            println("");
        }
        return;
    }

    // fallback
    println(user_name);
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
