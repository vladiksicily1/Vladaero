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

#[no_mangle]
pub extern "C" fn _start(arg_ptr: *const u8, arg_len: usize) -> usize {
    let args = if arg_ptr.is_null() || arg_len == 0 {
        ""
    } else {
        unsafe {
            core::str::from_utf8(core::slice::from_raw_parts(arg_ptr, arg_len)).unwrap_or("")
        }
    };
    run_net(args);
    0
}

pub fn run_net(args: &str) {
    let cmd = args.trim();
    if cmd.is_empty() || cmd == "/?" || cmd == "-?" || cmd == "help" {
        println("The syntax of this command is:");
        println("");
        println("NET");
        println("    [ ACCOUNTS | COMPUTER | CONFIG | CONTINUE | FILE | GROUP | HELP |");
        println("      HELPMSG | LOCALGROUP | PAUSE | SESSION | SHARE | START |");
        println("      STATISTICS | STOP | TIME | USE | USER | VIEW ]");
        println("");
        println("NET USER [username [password | *] [options]] [/DOMAIN]");
        println("         username {password | *} /ADD [options] [/DOMAIN]");
        println("         username [/DELETE] [/DOMAIN]");
        return;
    }

    if starts_with_ignore_case(cmd, "user") {
        let sub = cmd[4..].trim();
        if sub.is_empty() {
            println("");
            println("User accounts for \\\\VLADOS-PC");
            println("");
            println("-------------------------------------------------------------------------------");
            println("Administrator            DefaultAccount           Guest");
            println("SYSTEM                   Vlad                     User");
            println("The command completed successfully.");
        } else if eq_ignore_case(sub, "vlad") {
            println("User name                    Vlad");
            println("Full Name                    Vlad Admin");
            println("Comment                      VladOS System Administrator");
            println("User's comment               ");
            println("Country/region code          000 (System Default)");
            println("Account active               Yes");
            println("Account expires              Never");
            println("");
            println("Password last set            2026-09-05 12:00:00 AM");
            println("Password expires             Never");
            println("Password changeable          Yes");
            println("Password required            No");
            println("User may change password     Yes");
            println("");
            println("Workstations allowed         All");
            println("Logon script                 ");
            println("User profile                 C:\\Users\\Vlad");
            println("Home directory               ");
            println("Last logon                   2026-09-06 08:00:00 AM");
            println("");
            println("Logon hours allowed          All");
            println("");
            println("Local Group Memberships      *Administrators       *Users");
            println("Global Group memberships     *None");
            println("The command completed successfully.");
        } else if eq_ignore_case(sub, "system") {
            println("User name                    SYSTEM");
            println("Full Name                    NT AUTHORITY\\SYSTEM");
            println("Comment                      Operating System Internal Context");
            println("Account active               Yes");
            println("Local Group Memberships      *Administrators");
            println("The command completed successfully.");
        } else {
            print("User name                    ");
            println(sub);
            println("Account active               Yes");
            println("Local Group Memberships      *Users");
            println("The command completed successfully.");
        }
    } else if starts_with_ignore_case(cmd, "accounts") {
        println("Force user logoff how long after time expires?:       Never");
        println("Minimum password age (days):                          0");
        println("Maximum password age (days):                          42");
        println("Minimum password length:                              0");
        println("Length of password history maintained:                None");
        println("Lockout threshold:                                    Never");
        println("Lockout duration (minutes):                           30");
        println("Lockout observation window (minutes):                 30");
        println("Computer role:                                        WORKSTATION");
        println("The command completed successfully.");
    } else if starts_with_ignore_case(cmd, "start") {
        println("These VladOS services are started:");
        println("");
        println("   Desktop Window Manager (DWM)");
        println("   VladOS Explorer Shell (Explorer)");
        println("   VladOS Audio Service (AudioSrv)");
        println("   Workstation");
        println("   Plug and Play");
        println("   Security Accounts Manager (SAM)");
        println("   VladFS Storage Subsystem");
        println("");
        println("The command completed successfully.");
    } else if starts_with_ignore_case(cmd, "view") {
        println("Server Name            Remark");
        println("-------------------------------------------------------------------------------");
        println("\\\\VLADOS-PC            VladOS 10 Workstation");
        println("The command completed successfully.");
    } else {
        print("NET command not recognized: ");
        println(cmd);
        println("Type 'NET HELP' for available commands.");
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
