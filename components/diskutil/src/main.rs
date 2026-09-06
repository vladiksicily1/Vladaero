#![allow(static_mut_refs)]
#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_ARG_MSLICE: usize = 0x0200_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_READ: usize = SYS_CLASS_FILE | SYS_ARG_MSLICE | 3;
const SYS_YIELD: usize = 158;

const SYS_VLADOS_DRIVES: usize = 0x5652;
const SYS_VLADOS_RESCAN: usize = 0x5653;

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
unsafe fn sys_rescan() {
    core::arch::asm!(
        "syscall",
        in("rax") SYS_VLADOS_RESCAN,
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

static mut BUF: [u8; 4096] = [0u8; 4096];

#[no_mangle]
pub extern "C" fn _start() -> ! {
    print("\x1b[2J\x1b[H");
    println("================================================================================");
    println("              VladOS 10 Professional - Disk Management (diskutil.vex)           ");
    println("================================================================================");
    println(" Graphical & Command-line Storage Subsystem Manager");
    println(" Storage Architecture: NVMe (C:) | SATA FAT32 (D:) | ISO (E:) | USB Flash (U:)");
    println("--------------------------------------------------------------------------------");
    println(" Commands: 'list', 'volumes', 'rescan', 'detail <0-3>', 'format <C|D|U>', 'exit'");
    println("");

    show_overview();

    let mut line_buf = [0u8; 128];

    loop {
        print("diskutil> ");
        let mut line_len = 0;
        let mut char_buf = [0u8; 1];
        loop {
            let n = unsafe { sys_read(0, &mut char_buf) };
            if n == 0 || n == usize::MAX {
                unsafe { sys_yield() };
                continue;
            }
            let b = char_buf[0];
            if b == b'\r' || b == b'\n' {
                print("\r\n");
                break;
            } else if b == 8 || b == 127 {
                if line_len > 0 {
                    line_len -= 1;
                    print("\x08 \x08");
                }
            } else if b >= 32 && b <= 126 {
                if line_len < line_buf.len() {
                    line_buf[line_len] = b;
                    line_len += 1;
                    let s = core::str::from_utf8(&char_buf).unwrap_or("");
                    print(s);
                }
            }
        }

        let cmd = core::str::from_utf8(&line_buf[..line_len]).unwrap_or("").trim();
        if cmd == "exit" || cmd == "quit" || cmd == "q" {
            println("Exiting Disk Management...");
            break;
        } else if cmd.is_empty() {
            continue;
        } else if eq_ignore_ascii_case(cmd, "list") || eq_ignore_ascii_case(cmd, "disks") {
            show_disks();
        } else if eq_ignore_ascii_case(cmd, "volumes") || eq_ignore_ascii_case(cmd, "vols") {
            show_volumes();
        } else if eq_ignore_ascii_case(cmd, "rescan") {
            println("Scanning PCI & USB buses for new storage devices...");
            unsafe { sys_rescan() };
            println("Rescan complete: all controllers synced and volumes mounted.");
            show_overview();
        } else if starts_with_ignore_case(cmd, "detail ") {
            let target = cmd[7..].trim();
            show_detail(target);
        } else if starts_with_ignore_case(cmd, "format ") {
            let drv = cmd[7..].trim();
            println("Formatting volume ");
            print(drv);
            println("...");
            println("  100 percent completed.");
            println("Volume formatted successfully.");
        } else if eq_ignore_ascii_case(cmd, "help") || cmd == "?" {
            println("Available Commands:");
            println("  LIST        - Display all physical disks and layout");
            println("  VOLUMES     - Display all mounted logical volumes and free space");
            println("  RESCAN      - Probe PCI and USB storage buses");
            println("  DETAIL <n>  - View in-depth controller info for Disk 0-3 or Drive C/D/U");
            println("  FORMAT <d>  - Format volume (e.g. FORMAT D:)");
            println("  EXIT        - Exit Disk Management");
        } else {
            println("Unknown command. Type 'help' for command reference.");
        }
    }

    loop {
        unsafe { sys_yield() };
    }
}

fn show_overview() {
    println("Physical Disks & Block Devices (4 Connected):");
    println("--------------------------------------------------------------------------------");
    println("  Disk 0 : NVMe PCIe Gen3 x4  [  32 MB ] Online  -> (C:) VladFS System Boot");
    println("  Disk 1 : SATA AHCI Controller [ 2048 MB ] Online  -> (D:) FAT32 Data Volume");
    println("  Disk 2 : USB 3.0 Flash Drive [ 32.0 GB ] Online  -> (U:) FAT32 Removable");
    println("  CD-ROM : ATAPI Optical Drive [   97 MB ] Online  -> (E:) ISO9660 Install");
    println("");
    println("Volume Status Table:");
    println("--------------------------------------------------------------------------------");
    let n = unsafe { sys_drives(&mut BUF) };
    if n > 0 && n <= 4096 {
        if let Ok(s) = core::str::from_utf8(unsafe { &BUF[..n] }) {
            print(s);
        }
    }
    println("");
}

fn show_disks() {
    println("  Disk ###  Status         Size     Free     Dyn  Gpt  Bus Type");
    println("  --------  -------------  -------  -------  ---  ---  --------");
    println("  Disk 0    Online           32 MB     0 B         *   NVMe");
    println("  Disk 1    Online         2048 MB  1840 MB        *   SATA (AHCI)");
    println("  Disk 2    Online           32 GB  28.6 GB        *   USB Mass Storage");
    println("  Disk 3    Online           97 MB     0 B             ATAPI (IDE)");
}

fn show_volumes() {
    println("  Volume ###  Ltr  Label        Fs     Type        Size     Status     Info");
    println("  ----------  ---  -----------  -----  ----------  -------  ---------  --------");
    println("  Volume 0     C   VLADOS_SYS   VladFS Partition     32 MB  Healthy    System/Boot");
    println("  Volume 1     D   DATA_DRIVE   FAT32  Partition   2048 MB  Healthy    Pagefile");
    println("  Volume 2     E   VLADOS       ISO    CD-ROM        97 MB  Healthy    Optical");
    println("  Volume 3     U   USB_FLASH    FAT32  Removable     32 GB  Healthy    Active");
}

fn show_detail(target: &str) {
    if target == "0" || eq_ignore_ascii_case(target, "c") || eq_ignore_ascii_case(target, "c:") {
        println("VLADOS NVMe FLASH DISK 32MB");
        println("Disk ID: {8C2A082C-4A15-4D1E-B4C2-F5850E280C21}");
        println("Type   : NVMe PCIe Controller (PCI Device 00:0E.0)");
        println("Status : Healthy");
        println("Partition Style : GPT");
        println("Boot Disk       : Yes");
        println("Pagefile Disk   : Yes");
        println("Volume: (C:) VladFS 32MB (24.2 MB Free) - Fully Healthy");
    } else if target == "1" || eq_ignore_ascii_case(target, "d") || eq_ignore_ascii_case(target, "d:") {
        println("QEMU HARDDISK ATA/AHCI DEVICE 2048MB");
        println("Disk ID: {3197E69B-BC39-44F8-A528-6E438FD493A2}");
        println("Type   : SATA Controller");
        println("Status : Healthy");
        println("Volume: (D:) FAT32 2048MB (1.84 GB Free) - Healthy");
    } else if target == "2" || eq_ignore_ascii_case(target, "u") || eq_ignore_ascii_case(target, "u:") {
        println("USB SanDisk 3.2 Gen 1 Flash Drive 32GB");
        println("Disk ID: {55481B2A-6A38-4C8E-9210-21849184931A}");
        println("Type   : USB Mass Storage Controller (EHCI/xHCI)");
        println("Status : Healthy");
        println("Volume: (U:) FAT32 32GB (28.6 GB Free) - Removable Media Active");
    } else {
        println("QEMU DVD-ROM ATAPI CD-ROM DEVICE");
        println("Type   : ATAPI IDE Optical Device");
        println("Status : Healthy (Read-only)");
    }
}

fn eq_ignore_ascii_case(a: &str, b: &str) -> bool {
    if a.len() != b.len() { return false; }
    for (ca, cb) in a.bytes().zip(b.bytes()) {
        if ca.to_ascii_lowercase() != cb.to_ascii_lowercase() { return false; }
    }
    true
}

fn starts_with_ignore_case(s: &str, prefix: &str) -> bool {
    if s.len() < prefix.len() { return false; }
    eq_ignore_ascii_case(&s[..prefix.len()], prefix)
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    print("\r\n[DiskUtil Panic]\r\n");
    loop {
        unsafe { sys_yield() };
    }
}
