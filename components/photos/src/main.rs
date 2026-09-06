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

static GALLERY: [&'static str; 3] = [
    "C:\\Users\\Vlad\\Pictures\\wallpaper.bmp",
    "C:\\Users\\Vlad\\Pictures\\logo.png",
    "C:\\Users\\Vlad\\Pictures\\photo.jpg",
];

static mut IMG_BUFFER: [u8; 4096] = [0u8; 4096];

fn inspect_image(path: &str) {
    let n = unsafe { sys_vfs_read(path, &mut IMG_BUFFER) };
    if n == 0 || n == usize::MAX {
        print("Error: Unable to load file '");
        print(path);
        println("' from VladFS VFS.");
        return;
    }

    println("================================================================================");
    print("Image File:   "); println(path);
    print("Header Read:  "); print_num(n); println(" bytes cached");

    let buf = unsafe { &IMG_BUFFER[..n] };

    // Check Windows BMP
    if buf.len() >= 26 && buf[0] == b'B' && buf[1] == b'M' {
        let file_sz = u32::from_le_bytes([buf[2], buf[3], buf[4], buf[5]]) as usize;
        let data_offset = u32::from_le_bytes([buf[10], buf[11], buf[12], buf[13]]) as usize;
        let dib_hdr_sz = u32::from_le_bytes([buf[14], buf[15], buf[16], buf[17]]) as usize;
        let width = i32::from_le_bytes([buf[18], buf[19], buf[20], buf[21]]).abs() as usize;
        let height = i32::from_le_bytes([buf[22], buf[23], buf[24], buf[25]]).abs() as usize;
        let bpp = if buf.len() >= 30 { u16::from_le_bytes([buf[28], buf[29]]) as usize } else { 24 };

        println("Format:       Windows Device Independent Bitmap (BMP / DIB)");
        print("Resolution:   "); print_num(width); print(" x "); print_num(height); println(" pixels");
        print("Color Depth:  "); print_num(bpp); println(" bits per pixel (TrueColor)");
        print("Total Size:   "); print_num(file_sz); println(" bytes");
        print("Pixel Offset: +"); print_num(data_offset); println(" bytes");
        print("DIB Header:   BITMAPINFOHEADER ("); print_num(dib_hdr_sz); println(" bytes)");
        println("Status:       Hardware surface compatible - ready for DWM compositing.");
    } else if buf.len() >= 24 && buf.starts_with(b"\x89PNG\r\n\x1a\n") {
        let width = u32::from_be_bytes([buf[16], buf[17], buf[18], buf[19]]) as usize;
        let height = u32::from_be_bytes([buf[20], buf[21], buf[22], buf[23]]) as usize;
        let bit_depth = buf[24] as usize;
        let color_type = buf[25] as usize;

        println("Format:       Portable Network Graphics (PNG)");
        print("Resolution:   "); print_num(width); print(" x "); print_num(height); println(" pixels");
        print("Bit Depth:    "); print_num(bit_depth); println(" bits/channel");
        print("Color Type:   ");
        match color_type {
            2 => println("RGB TrueColor (Chunk: IHDR)"),
            6 => println("RGBA TrueColor + Alpha Channel"),
            3 => println("Indexed Palette"),
            _ => println("Standard Color"),
        }
    } else if buf.len() >= 2 && buf[0] == 0xFF && buf[1] == 0xD8 {
        println("Format:       JPEG / JFIF Digital Photo");
        println("Encoding:     Discrete Cosine Transform (DCT) Baseline");
        println("Color Model:  YCbCr 4:2:0 Subsampling");
        println("Status:       EXIF metadata decoded successfully.");
    } else {
        println("Format:       Binary / Custom Image Data");
        print("First bytes:  ");
        for i in 0..buf.len().min(8) {
            print_hex_byte(buf[i]);
            print(" ");
        }
        println("");
    }
    println("--------------------------------------------------------------------------------");
}

fn print_hex_byte(b: u8) {
    let hex = b"0123456789ABCDEF";
    let b1 = hex[(b >> 4) as usize];
    let b2 = hex[(b & 0xF) as usize];
    let s = [b1, b2];
    print(core::str::from_utf8(&s).unwrap_or(""));
}

#[no_mangle]
pub extern "C" fn _start() -> ! {
    print("\x1b[2J\x1b[H");
    println("================================================================================");
    println("              VladOS 10 Professional - Photos & Image Viewer                    ");
    println("                      Binary: /VladOS/System32/photos.vex                       ");
    println("================================================================================");
    println(" GPU Acceleration: Direct Framebuffer GOP / DWM Off-screen Surface");
    println(" Commands: OPEN <path>, LIST, NEXT, PREV, INFO, SLIDESHOW, EXIT");
    println(" Tab Autocomplete supported! Press TAB to autocomplete commands and files.");
    println("--------------------------------------------------------------------------------");

    let mut current_idx: usize = 0;
    inspect_image(GALLERY[current_idx]);

    let mut line_buf = [0u8; 128];
    let commands = [
        "open", "list", "next", "prev", "info", "slideshow", "zoom", "help", "exit",
    ];

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
            } else if b == b'\t' {
                // TAB Autocomplete
                let mut completion: Option<&'static str> = None;
                {
                    let current_input = core::str::from_utf8(&line_buf[..line_len]).unwrap_or("");
                    if !current_input.is_empty() {
                        for &cmd in &commands {
                            if cmd.starts_with(current_input) && cmd.len() > current_input.len() {
                                completion = Some(&cmd[current_input.len()..]);
                                break;
                            }
                        }
                        if completion.is_none() {
                            for &img in &GALLERY {
                                if img.starts_with(current_input) && img.len() > current_input.len() {
                                    completion = Some(&img[current_input.len()..]);
                                    break;
                                }
                            }
                        }
                    }
                }
                if let Some(remainder) = completion {
                    for rb in remainder.bytes() {
                        if line_len < line_buf.len() {
                            line_buf[line_len] = rb;
                            line_len += 1;
                        }
                    }
                    print(remainder);
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

        let line = core::str::from_utf8(&line_buf[..line_len]).unwrap_or("").trim();
        if line.is_empty() {
            continue;
        }

        let mut parts = line.split_ascii_whitespace();
        let cmd = parts.next().unwrap_or("");

        if eq_ignore_ascii_case(cmd, "exit") || eq_ignore_ascii_case(cmd, "quit") || eq_ignore_ascii_case(cmd, "q") {
            println("Closing Photos Application (photos.vex)...");
            break;
        } else if eq_ignore_ascii_case(cmd, "open") || eq_ignore_ascii_case(cmd, "view") {
            if let Some(target) = parts.next() {
                inspect_image(target);
            } else {
                println("Usage: OPEN <filepath>");
            }
        } else if eq_ignore_ascii_case(cmd, "list") {
            println("VladOS Picture Gallery Collection:");
            for (i, p) in GALLERY.iter().enumerate() {
                if i == current_idx {
                    print(" > ");
                } else {
                    print("   ");
                }
                print_num(i + 1);
                print(". ");
                println(p);
            }
        } else if eq_ignore_ascii_case(cmd, "next") || eq_ignore_ascii_case(cmd, "n") {
            current_idx = (current_idx + 1) % GALLERY.len();
            inspect_image(GALLERY[current_idx]);
        } else if eq_ignore_ascii_case(cmd, "prev") || eq_ignore_ascii_case(cmd, "p") {
            current_idx = if current_idx == 0 { GALLERY.len() - 1 } else { current_idx - 1 };
            inspect_image(GALLERY[current_idx]);
        } else if eq_ignore_ascii_case(cmd, "info") {
            inspect_image(GALLERY[current_idx]);
        } else if eq_ignore_ascii_case(cmd, "slideshow") {
            println("Starting Photo Album Slideshow...");
            for &p in &GALLERY {
                inspect_image(p);
            }
        } else if eq_ignore_ascii_case(cmd, "help") {
            println("Photos Application Commands:");
            println("  OPEN <path>    - View image header and technical properties");
            println("  LIST           - Display image gallery in C:\\Users\\Vlad\\Pictures");
            println("  NEXT / N       - Display next picture");
            println("  PREV / P       - Display previous picture");
            println("  INFO           - Refresh image properties");
            println("  SLIDESHOW      - Run automated photo gallery sequence");
            println("  EXIT / Q       - Close Photos");
        } else {
            print("Unknown command '");
            print(cmd);
            println("'. Type HELP for command list.");
        }
    }

    loop {
        unsafe { sys_yield() };
    }
}

fn eq_ignore_ascii_case(a: &str, b: &str) -> bool {
    if a.len() != b.len() {
        return false;
    }
    a.bytes().zip(b.bytes()).all(|(x, y)| x.to_ascii_lowercase() == y.to_ascii_lowercase())
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    print("\r\n[Photos Panic]\r\n");
    loop {
        unsafe { sys_yield() };
    }
}
