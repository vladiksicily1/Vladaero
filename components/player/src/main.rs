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
const SYS_NANOSLEEP: usize = 162;
const SYS_VLADOS_BEEP: usize = 0x5655;
const SYS_VLADOS_VFS_READ: usize = 0x5646;

#[repr(C)]
struct TimeSpec {
    tv_sec: i64,
    tv_nsec: i32,
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
unsafe fn sys_beep(freq: u32, duration_ms: u32) {
    core::arch::asm!(
        "syscall",
        in("rax") SYS_VLADOS_BEEP,
        in("rdi") freq as usize,
        in("rsi") duration_ms as usize,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
}

#[inline(always)]
unsafe fn sys_sleep_ms(ms: u64) {
    let req = TimeSpec {
        tv_sec: (ms / 1000) as i64,
        tv_nsec: ((ms % 1000) * 1_000_000) as i32,
    };
    core::arch::asm!(
        "syscall",
        in("rax") SYS_NANOSLEEP,
        in("rdi") &req as *const _ as usize,
        in("rsi") 0,
        out("rcx") _,
        out("r11") _,
        options(nostack)
    );
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

fn print(s: &str) {
    unsafe { sys_write(1, s.as_bytes()) };
}

fn println(s: &str) {
    print(s);
    print("\r\n");
}

struct Track {
    title: &'static str,
    artist: &'static str,
    path: &'static str,
    duration: &'static str,
}

static PLAYLIST: [Track; 4] = [
    Track {
        title: "VladOS 10 Fluent Ambient",
        artist: "Vlad Corporation Sound Lab",
        path: "C:\\Users\\Vlad\\Music\\ambient.mp3",
        duration: "03:45",
    },
    Track {
        title: "VladOS Startup Chime",
        artist: "VladOS Kernel Audio",
        path: "C:\\Users\\Vlad\\Music\\startup.wav",
        duration: "00:08",
    },
    Track {
        title: "VladOS Aero Theme Synth",
        artist: "PC Speaker 8254 PIT",
        path: "C:\\VladOS\\Resources\\theme.wav",
        duration: "02:10",
    },
    Track {
        title: "System Hardware Bell",
        artist: "Realtek High Definition",
        path: "C:\\VladOS\\Resources\\bell.wav",
        duration: "00:04",
    },
];

static NOTES_AMBIENT: [(u32, u64); 8] = [
    (523, 160), // C5
    (659, 160), // E5
    (784, 180), // G5
    (1046, 220), // C6
    (880, 180), // A5
    (784, 180), // G5
    (659, 200), // E5
    (523, 300), // C5
];

static NOTES_CHIME: [(u32, u64); 5] = [
    (440, 120), // A4
    (554, 120), // C#5
    (659, 140), // E5
    (880, 200), // A5
    (1108, 350), // C#6
];

pub fn play_audio_file(file_path: &str) {
    let mut header = [0u8; 64];
    let n = unsafe { sys_vfs_read(file_path, &mut header) };
    print("Loading track: ");
    println(file_path);
    if n > 0 {
        print("Audio file size verified (");
        print_num(n);
        println(" header bytes read)");
    } else {
        println("Note: Synthesizing track via VladOS PIT Audio Subsystem");
    }

    if file_path.contains("startup") || file_path.contains("chime") {
        for &(freq, dur) in &NOTES_CHIME {
            unsafe {
                sys_beep(freq, dur as u32);
                sys_sleep_ms(dur);
            }
        }
    } else {
        for &(freq, dur) in &NOTES_AMBIENT {
            unsafe {
                sys_beep(freq, dur as u32);
                sys_sleep_ms(dur);
            }
        }
    }
    unsafe {
        sys_beep(0, 0);
    }
    println("Playback finished.");
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
        play_audio_file(args.trim());
        return 0;
    }

    print("\x1b[2J\x1b[H");
    println("================================================================================");
    println("              VladOS 10 Professional - Media Player                             ");
    println("                      Binary: /VladOS/System32/player.vex                       ");
    println("================================================================================");
    println(" Audio Engine: Realtek High Definition Audio / PC Speaker 8254 PIT Subsystem");
    println(" Commands: PLAY, PAUSE, STOP, NEXT, PREV, LIST, VOL <0-100>, INFO, EXIT");
    println("--------------------------------------------------------------------------------");

    let mut current_track: usize = 0;
    let mut is_playing = false;
    let mut line_buf = [0u8; 128];

    loop {
        print("player> ");
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

        if eq_ignore_ascii_case(cmd, "exit") || eq_ignore_ascii_case(cmd, "q") {
            println("Exiting VladOS Media Player...");
            break;
        } else if eq_ignore_ascii_case(cmd, "play") || eq_ignore_ascii_case(cmd, "p") {
            is_playing = true;
            play_audio_file(PLAYLIST[current_track].path);
        } else if eq_ignore_ascii_case(cmd, "stop") {
            is_playing = false;
            unsafe { sys_beep(0, 0) };
            println("Playback stopped.");
        } else if eq_ignore_ascii_case(cmd, "list") || eq_ignore_ascii_case(cmd, "l") {
            println("Playlist:");
            for (idx, track) in PLAYLIST.iter().enumerate() {
                print(if idx == current_track { " -> " } else { "    " });
                print_num(idx + 1);
                print(". ");
                print(track.title);
                print(" - ");
                print(track.artist);
                print(" (");
                print(track.duration);
                println(")");
            }
        } else if eq_ignore_ascii_case(cmd, "next") || eq_ignore_ascii_case(cmd, "n") {
            current_track = (current_track + 1) % PLAYLIST.len();
            print("Switched to: ");
            println(PLAYLIST[current_track].title);
            if is_playing {
                play_audio_file(PLAYLIST[current_track].path);
            }
        } else if eq_ignore_ascii_case(cmd, "prev") {
            current_track = if current_track == 0 { PLAYLIST.len() - 1 } else { current_track - 1 };
            print("Switched to: ");
            println(PLAYLIST[current_track].title);
            if is_playing {
                play_audio_file(PLAYLIST[current_track].path);
            }
        } else if eq_ignore_ascii_case(cmd, "info") {
            let t = &PLAYLIST[current_track];
            println("--- Audio Stream Information ---");
            print("Track Title: "); println(t.title);
            print("Artist:      "); println(t.artist);
            print("Path:        "); println(t.path);
            print("Hardware:    Realtek ALC887 / Intel HD Audio\r\n");
            print("Status:      "); println(if is_playing { "Playing" } else { "Stopped" });
            println("--------------------------");
        } else {
            println("Commands: PLAY, STOP, NEXT, PREV, LIST, INFO, EXIT");
        }
    }

    0
}

fn eq_ignore_ascii_case(a: &str, b: &str) -> bool {
    if a.len() != b.len() {
        return false;
    }
    a.bytes().zip(b.bytes()).all(|(x, y)| x.to_ascii_lowercase() == y.to_ascii_lowercase())
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

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    print("\r\n[Player Panic]\r\n");
    loop {
        unsafe { sys_yield() };
    }
}
