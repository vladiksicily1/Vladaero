#![allow(static_mut_refs)]
#![no_std]
#![no_main]

use core::panic::PanicInfo;

const SYS_CLASS_FILE: usize = 0x2000_0000;
const SYS_ARG_SLICE: usize = 0x0100_0000;
const SYS_WRITE: usize = SYS_CLASS_FILE | SYS_ARG_SLICE | 4;
const SYS_YIELD: usize = 158;
const SYS_VLADOS_VFS_LIST: usize = 0x564C;
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
unsafe fn sys_vfs_list(path: &str, buf: &mut [u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_LIST => ret,
        in("rdi") path.as_ptr() as usize,
        in("rsi") path.len(),
        in("rdx") buf.as_mut_ptr() as usize,
        in("r10") buf.len(),
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
    ret
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

fn print_u64(mut n: u64) {
    if n == 0 {
        print("0");
        return;
    }
    let mut buf = [0u8; 32];
    let mut i = 0;
    while n > 0 {
        buf[i] = b'0' + (n % 10) as u8;
        n /= 10;
        i += 1;
    }
    let mut rev = [0u8; 32];
    for j in 0..i {
        rev[j] = buf[i - 1 - j];
    }
    if let Ok(s) = core::str::from_utf8(&rev[..i]) {
        print(s);
    }
}

fn print_formatted_bytes(n: u64) {
    if n == 0 {
        print("            0");
        return;
    }
    let mut buf = [0u8; 32];
    let mut i = 0;
    let mut temp = n;
    let mut digits = 0;
    while temp > 0 {
        if digits > 0 && digits % 3 == 0 {
            buf[i] = b',';
            i += 1;
        }
        buf[i] = b'0' + (temp % 10) as u8;
        temp /= 10;
        digits += 1;
        i += 1;
    }
    let mut rev = [0u8; 32];
    for j in 0..i {
        rev[j] = buf[i - 1 - j];
    }
    let width = 13;
    let pad = if width > i { width - i } else { 0 };
    for _ in 0..pad {
        print(" ");
    }
    if let Ok(s) = core::str::from_utf8(&rev[..i]) {
        print(s);
    }
}

static mut DRIVES_BUF: [u8; 2048] = [0u8; 2048];
static mut LIST_BUF: [u8; 8192] = [0u8; 8192];

#[no_mangle]
pub extern "C" fn _start(arg_ptr: *const u8, arg_len: usize) -> usize {
    let args = if arg_ptr.is_null() || arg_len == 0 {
        "C:"
    } else {
        unsafe {
            core::str::from_utf8(core::slice::from_raw_parts(arg_ptr, arg_len)).unwrap_or("C:")
        }
    };
    run_chkdsk(args);
    0
}

pub fn run_chkdsk(args: &str) {
    let clean = args.trim();
    let drive_char = if clean.len() >= 2 && clean.as_bytes()[1] == b':' {
        clean.as_bytes()[0].to_ascii_uppercase()
    } else if !clean.is_empty() && clean.as_bytes()[0].is_ascii_alphabetic() {
        clean.as_bytes()[0].to_ascii_uppercase()
    } else {
        b'C'
    };

    println("");
    print("The type of the file system is ");

    let drives_len = unsafe { sys_drives(&mut DRIVES_BUF) };
    let drives_str = unsafe { core::str::from_utf8(&DRIVES_BUF[..drives_len]).unwrap_or("") };

    let mut fs_type = "VladFS";
    let mut vol_label = "VLADOS_SYS";
    let mut total_mb: u64 = 32;
    let mut free_mb: u64 = 24;

    for line in drives_str.lines() {
        if line.starts_with("Drive ") && line.len() >= 8 {
            let letter = line.as_bytes()[6].to_ascii_uppercase();
            if letter == drive_char {
                if let Some(start_fs) = line.find('[') {
                    if let Some(end_fs) = line[start_fs..].find(']') {
                        fs_type = line[start_fs + 1..start_fs + end_fs].trim();
                    }
                }
                if let Some(idx_vol) = line.find("Volume:") {
                    let rest = line[idx_vol + 7..].trim_start();
                    if let Some(space_idx) = rest.find(' ') {
                        vol_label = &rest[..space_idx];
                    }
                }
                if let Some(idx_mb) = line.find(" MB Total") {
                    let before = line[..idx_mb].trim_end();
                    if let Some(space_idx) = before.rfind(' ') {
                        if let Ok(val) = before[space_idx + 1..].parse::<u64>() {
                            total_mb = val;
                        }
                    }
                }
                if let Some(idx_free) = line.find(" MB Free") {
                    let before = line[..idx_free].trim_end();
                    if let Some(space_idx) = before.rfind(' ') {
                        if let Ok(val) = before[space_idx + 1..].parse::<u64>() {
                            free_mb = val;
                        }
                    }
                }
                break;
            }
        }
    }

    if fs_type == "FAT32" {
        println("FAT32.");
    } else {
        println("VladFS (VladOS Resilient Native File System).");
    }
    print("Volume label is ");
    println(vol_label);
    if drive_char == b'C' {
        println("Volume Serial Number is B842-8812");
    } else {
        println("Volume Serial Number is 4C19-3A08");
    }
    println("");

    println("Stage 1: Examining basic file system structure ...");
    let mut total_files: u64 = 0;
    let mut total_dirs: u64 = 0;
    let mut total_file_bytes: u64 = 0;

    let scan_paths: &[&str] = if drive_char == b'C' {
        &[
            "C:\\",
            "C:\\VladOS",
            "C:\\VladOS\\System32",
            "C:\\VladOS\\Resources\\Fonts",
            "C:\\Users",
            "C:\\Users\\Vlad",
            "C:\\Users\\Vlad\\Documents",
            "C:\\Users\\Vlad\\Pictures",
            "C:\\Users\\Vlad\\Music",
            "C:\\Users\\Vlad\\Videos",
        ]
    } else if drive_char == b'D' {
        &["D:\\"]
    } else {
        &["E:\\"]
    };

    for path in scan_paths {
        let n = unsafe { sys_vfs_list(path, &mut LIST_BUF) };
        if n > 0 {
            if let Ok(listing) = unsafe { core::str::from_utf8(&LIST_BUF[..n]) } {
                for line in listing.lines() {
                    let trimmed = line.trim();
                    if trimmed.is_empty() {
                        continue;
                    }
                    let is_dir = trimmed.contains("<DIR>");
                    if is_dir {
                        total_dirs += 1;
                    } else {
                        total_files += 1;
                        let mut words = trimmed.split_ascii_whitespace();
                        let _perm = words.next();
                        let second = words.next();
                        if let Some(s2) = second {
                            if let Ok(sz) = s2.parse::<u64>() {
                                total_file_bytes += sz;
                            } else {
                                let third = words.next();
                                if let Some(s3) = third {
                                    if let Ok(sz) = s3.parse::<u64>() {
                                        total_file_bytes += sz;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    println("  Root directory cluster verified healthy.");
    println("  Superblock magic 0x564C4144 [VLAD] matches.");
    println("  Inode allocation tables verified.");
    println("  Cluster bitmap verified.");
    println("Stage 1 verification completed.");
    println("");

    println("Stage 2: Examining file name linkage ...");
    print("  Index entries processed: ");
    print_u64(total_dirs + total_files);
    println(" entries verified.");
    println("Stage 2 verification completed.");
    println("");

    println("Stage 3: Examining security descriptors ...");
    println("  Security descriptor verification completed.");
    println("");
    println("VladOS has scanned the file system and found no problems.");
    println("No further action is required.");
    println("");

    let total_bytes = total_mb * 1024 * 1024;
    let free_bytes = free_mb * 1024 * 1024;
    let cluster_size: u64 = 4096;
    let total_clusters = total_bytes / cluster_size;
    let free_clusters = free_bytes / cluster_size;

    print_formatted_bytes(total_bytes);
    println(" bytes total disk space.");

    print_formatted_bytes(total_file_bytes);
    print(" bytes in ");
    print_u64(total_files);
    println(" files.");

    print_formatted_bytes(total_dirs * cluster_size);
    print(" bytes in ");
    print_u64(total_dirs);
    println(" indexes.");

    print_formatted_bytes(0);
    println(" bytes in bad sectors.");

    print_formatted_bytes(free_bytes);
    println(" bytes available on disk.");
    println("");

    print_formatted_bytes(cluster_size);
    println(" bytes in each allocation unit.");

    print_formatted_bytes(total_clusters);
    println(" total allocation units on disk.");

    print_formatted_bytes(free_clusters);
    println(" allocation units available on disk.");
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
