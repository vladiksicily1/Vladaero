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
const SYS_VLADOS_VFS_READ: usize = 0x5646;

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
unsafe fn sys_vfs_read(path: &str, buf: &mut [u8]) -> usize {
    let ret: usize;
    core::arch::asm!(
        "syscall",
        inlateout("rax") SYS_VLADOS_VFS_READ => ret,
        in("rdi") path.as_ptr(),
        in("rsi") path.len(),
        in("rdx") buf.as_mut_ptr(),
        in("r10") buf.len(),
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

fn print_num(mut n: usize) {
    if n == 0 {
        print("0");
        return;
    }
    let mut buf = [0u8; 20];
    let mut i = 0;
    while n > 0 {
        buf[i] = b'0' + (n % 10) as u8;
        n /= 10;
        i += 1;
    }
    for j in (0..i).rev() {
        let b = [buf[j]];
        print(core::str::from_utf8(&b).unwrap_or(""));
    }
}

static GALLERY: [&str; 3] = [
    "C:\\Users\\Vlad\\Pictures\\wallpaper.bmp",
    "C:\\Users\\Vlad\\Pictures\\logo.png",
    "C:\\Users\\Vlad\\Pictures\\photo.jpg",
];

static mut IMG_HEADER_BUF: [u8; 4096] = [0u8; 4096];

pub fn inspect_image(path: &str) {
    println("--------------------------------------------------------------------------------");
    print("Opening Image: ");
    println(path);

    let n = unsafe { sys_vfs_read(path, &mut IMG_HEADER_BUF) };
    if n == 0 {
        println("Status:       Error: File not found or read failure");
        println("--------------------------------------------------------------------------------");
        return;
    }

    let buf = unsafe { &IMG_HEADER_BUF[..n] };
    print("File Size:    ");
    print_num(n);
    println(" bytes loaded from VFS");

    if buf.len() >= 30 && buf[0] == b'B' && buf[1] == b'M' {
        let file_sz = u32::from_le_bytes([buf[2], buf[3], buf[4], buf[5]]) as usize;
        let data_offset = u32::from_le_bytes([buf[10], buf[11], buf[12], buf[13]]) as usize;
        let width = i32::from_le_bytes([buf[18], buf[19], buf[20], buf[21]]).abs() as usize;
        let height = i32::from_le_bytes([buf[22], buf[23], buf[24], buf[25]]).abs() as usize;
        let bpp = u16::from_le_bytes([buf[28], buf[29]]) as usize;

        println("Format:       VladOS Device Independent Bitmap (BMP / DIB)");
        print("Resolution:   "); print_num(width); print(" x "); print_num(height); println(" pixels");
        print("Color Depth:  "); print_num(bpp); println(" bits per pixel (TrueColor)");
        print("DIB Size:     "); print_num(file_sz); println(" bytes");
        print("Pixel Offset: +"); print_num(data_offset); println(" bytes");
        println("Rendering:    DWM Hardware Surface Blit Active");
    } else if buf.len() >= 24 && buf.starts_with(b"\x89PNG\r\n\x1a\n") {
        let width = u32::from_be_bytes([buf[16], buf[17], buf[18], buf[19]]) as usize;
        let height = u32::from_be_bytes([buf[20], buf[21], buf[22], buf[23]]) as usize;
        let bit_depth = buf[24] as usize;
        println("Format:       Portable Network Graphics (PNG)");
        print("Resolution:   "); print_num(width); print(" x "); print_num(height); println(" pixels");
        print("Color Depth:  "); print_num(bit_depth * 4); println(" bits per pixel (RGBA)");
    } else if buf.len() >= 2 && buf[0] == 0xFF && buf[1] == 0xD8 {
        println("Format:       JPEG / JFIF Digital Photograph");
        println("Color Model:  YCbCr 4:2:0 Subsampled");
    } else {
        println("Format:       Binary graphic stream");
    }
    println("--------------------------------------------------------------------------------");
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
    if !args.trim().is_empty() {
        inspect_image(args.trim());
        return 0;
    }

    print("\x1b[2J\x1b[H");
    println("================================================================================");
    println("              VladOS 10 Professional - Photos & Image Viewer                    ");
    println("                      Binary: /VladOS/System32/photos.vex                       ");
    println("================================================================================");
    println(" Commands: OPEN <path>, LIST, NEXT, PREV, INFO, EXIT");
    println("--------------------------------------------------------------------------------");

    let mut current_idx: usize = 0;
    inspect_image(GALLERY[current_idx]);

    let mut line_buf = [0u8; 128];

    loop {
        print("photos> ");
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

        let cmd = match core::str::from_utf8(&line_buf[..line_len]) {
            Ok(s) => s.trim(),
            Err(_) => continue,
        };

        if cmd.is_empty() {
            continue;
        }

        if cmd == "exit" || cmd == "q" {
            println("Exiting VladOS Photos...");
            break;
        } else if cmd == "list" || cmd == "l" {
            println("Gallery Images:");
            for (i, &img) in GALLERY.iter().enumerate() {
                print(if i == current_idx { " -> " } else { "    " });
                println(img);
            }
        } else if cmd == "next" || cmd == "n" {
            current_idx = (current_idx + 1) % GALLERY.len();
            inspect_image(GALLERY[current_idx]);
        } else if cmd == "prev" || cmd == "p" {
            current_idx = if current_idx == 0 { GALLERY.len() - 1 } else { current_idx - 1 };
            inspect_image(GALLERY[current_idx]);
        } else if cmd.starts_with("open ") {
            let path = cmd[5..].trim();
            inspect_image(path);
        } else {
            println("Commands: OPEN <path>, LIST, NEXT, PREV, INFO, EXIT");
        }
    }

    0
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    print("\r\n[Photos Panic]\r\n");
    loop {
        unsafe { sys_yield() };
    }
}
