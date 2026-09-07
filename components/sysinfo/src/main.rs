#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_YIELD: usize = 158;
const SYS_VLADOS_DRIVES: usize = 0x5652;

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

fn print(s: &str) {
    unsafe { sys_write(1, s.as_bytes()) };
}

fn println(s: &str) {
    print(s);
    print("\r\n");
}

static mut DRIVES_BUF: [u8; 1024] = [0u8; 1024];

#[no_mangle]
pub extern "C" fn _start(_arg_ptr: *const u8, _arg_len: usize) -> usize {
    run_sysinfo();
    0
}

pub fn run_sysinfo() {
    println("");
    println("Host Name:                 VLADOS-PC");
    println("OS Name:                   VladOS 10 Professional");
    println("OS Version:                10.0.22000.1 N/A Build 22000.1");
    println("OS Manufacturer:           Vlad Corporation");
    println("OS Configuration:          Standalone Workstation");
    println("OS Build Type:             Multiprocessor Free (SMP AMD64)");
    println("Registered Owner:          Vlad");
    println("Registered Organization:   Vlad Inc.");
    println("Product ID:                00330-80000-00000-AAOEM");
    println("Original Install Date:     2026-09-05, 12:00:00 AM");
    println("System Boot Time:          2026-09-06, 08:00:00 AM");
    println("System Manufacturer:       VladOS Hardware Platform (QEMU / Standard PC)");
    println("System Model:              VladOS Desktop PC");
    println("System Type:               x64-based PC");
    println("Processor(s):              1 Processor(s) Installed.");
    println("                           [01]: AMD64 Family 6 Model 63 Stepping 2 GenuineIntel ~2600 Mhz");
    println("BIOS Version:              UEFI 2.7.0 (EDK II TianoCore VladOS Firmware)");
    println("VladOS Directory:          C:\\VladOS");
    println("System Directory:          C:\\VladOS\\System32");
    println("Boot Device:               \\Device\\HarddiskVolume1");
    println("System Locale:             en-us;English (United States)");
    println("Input Locale:              en-us;English (United States)");
    println("Time Zone:                 (UTC+03:00) Moscow, St. Petersburg");
    println("Total Physical Memory:     2,048 MB");
    println("Available Physical Memory: 1,896 MB");
    println("Virtual Memory: Max Size:  4,096 MB");
    println("Virtual Memory: Available: 3,840 MB");
    println("Virtual Memory: In Use:    256 MB");
    println("Page File Location(s):     C:\\pagefile.sys");
    println("Domain:                    WORKGROUP");
    println("Logon Server:              \\\\VLADOS-PC");
    println("Network Card(s):           1 NIC(s) Installed.");
    println("                           [01]: Intel(R) 82540EM Gigabit Ethernet Adapter");
    println("                                 Connection Name: Ethernet");
    println("                                 Status:          Media disconnected");
    println("Virtualization Extensions: Hardware Virtualization Enabled In Firmware: Yes");
    println("                           Second Level Address Translation: Yes");
    println("                           Data Execution Prevention Available: Yes");
    println("");
    println("Storage Volumes:");
    println("-------------------------------------------------------------------------------");
    #[allow(static_mut_refs)]
    let n = unsafe { sys_drives(&mut DRIVES_BUF) };
    if n > 0 && n <= 1024 {
        #[allow(static_mut_refs)]
        if let Ok(s) = core::str::from_utf8(unsafe { &DRIVES_BUF[..n] }) {
            print(s);
            if !s.ends_with('\n') {
                println("");
            }
        }
    } else {
        println("  C: [VladFS]  VLADOS_SYS (System, Boot)");
        println("  D: [FAT32]   DATA_DRIVE");
        println("  E: [ISO9660] VLADOS_ISO (Optical Media)");
        println("  U: [VladFS]  USB_DRIVE (Removable)");
    }
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
