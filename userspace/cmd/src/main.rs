#![no_std]
#![allow(non_snake_case)]

extern crate alloc;
use ntdll::*;

// ============================================================
// Cmd — VladOS command interpreter
// ============================================================

const BUFFER_SIZE: usize = 4096;

// Simple string comparison for fixed byte strings
fn str_eq(a: &[u8], b: &[u8]) -> bool {
    if a.len() != b.len() { return false; }
    for i in 0..a.len() { if a[i] != b[i] { return false; } }
    true
}

fn str_starts_with(haystack: &[u8], needle: &[u8]) -> bool {
    if needle.len() > haystack.len() { return false; }
    for i in 0..needle.len() { if haystack[i] != needle[i] { return false; } }
    true
}

fn trim_whitespace(s: &[u8]) -> &[u8] {
    let mut start = 0;
    while start < s.len() && (s[start] == b' ' || s[start] == b'\t') { start += 1; }
    let mut end = s.len();
    while end > start && (s[end - 1] == b' ' || s[end - 1] == b'\t') { end -= 1; }
    &s[start..end]
}

fn write_str(h: HANDLE, s: &[u8]) {
    let _ = ntdll::write(h, s);
}

fn write_crlf(h: HANDLE) {
    let _ = ntdll::write(h, b"\r\n");
}

fn writeln(h: HANDLE, s: &[u8]) {
    write_str(h, s);
    write_crlf(h);
}

// ============================================================
// Built-in commands
// ============================================================

fn cmd_ver(h: HANDLE) {
    writeln(h, b"");
    writeln(h, b"Microsoft Windows [Version 10.0.19041.1]");
    writeln(h, b"(c) VladOS Corporation. All rights reserved.");
    writeln(h, b"");
}

fn cmd_help(h: HANDLE) {
    writeln(h, b"VladOS Command Processor - Available commands:");
    writeln(h, b"");
    writeln(h, b"  ver         - Display OS version");
    writeln(h, b"  help        - Show this help");
    writeln(h, b"  echo        - Display text");
    writeln(h, b"  cls         - Clear screen");
    writeln(h, b"  dir         - List directory contents");
    writeln(h, b"  cd          - Change directory");
    writeln(h, b"  type        - Display file contents");
    writeln(h, b"  del         - Delete a file");
    writeln(h, b"  mkdir       - Create directory");
    writeln(h, b"  ren         - Rename a file");
    writeln(h, b"  copy        - Copy a file");
    writeln(h, b"  time        - Display current time");
    writeln(h, b"  date        - Display current date");
    writeln(h, b"  hostname    - Display computer name");
    writeln(h, b"  set         - Display environment variables");
    writeln(h, b"  path        - Display PATH");
    writeln(h, b"  color       - Set console color");
    writeln(h, b"  title       - Set console title");
    writeln(h, b"  tasklist    - List running processes");
    writeln(h, b"  mem         - Display memory info");
    writeln(h, b"  tree        - Display directory tree");
    writeln(h, b"  exit        - Exit command processor");
    writeln(h, b"");
}

fn cmd_cls(h: HANDLE) {
    // ANSI escape: clear screen + move cursor to 0,0
    let _ = ntdll::write(h, b"\x1b[2J\x1b[H");
}

fn cmd_echo(h: HANDLE, args: &[u8]) {
    if args.is_empty() {
        writeln(h, b"ECHO is on.");
    } else if str_eq(args, b".") || str_eq(args, b"") {
        writeln(h, b"");
    } else {
        write_str(h, args);
        write_crlf(h);
    }
}

fn cmd_time(h: HANDLE) {
    // Display system time (simplified)
    writeln(h, b"The current time is: 12:00:00.00");
}

fn cmd_date(h: HANDLE) {
    writeln(h, b"The current date is: Sun 09/08/2026");
}

fn cmd_hostname(h: HANDLE) {
    writeln(h, b"VladOS");
}

fn cmd_set(h: HANDLE) {
    writeln(h, b"COMSPEC=\\SystemRoot\\System32\\cmd.vex");
    writeln(h, b"PATH=\\SystemRoot\\System32");
    writeln(h, b"SYSTEMROOT=\\SystemRoot");
    writeln(h, b"TEMP=\\SystemRoot\\Temp");
    writeln(h, b"TMP=\\SystemRoot\\Temp");
    writeln(h, b"PROMPT=$P$G");
    writeln(h, b"NUMBER_OF_PROCESSORS=1");
    writeln(h, b"PROCESSOR_ARCHITECTURE=AMD64");
}

fn cmd_path(h: HANDLE) {
    writeln(h, b"PATH=\\SystemRoot\\System32");
}

fn cmd_color(h: HANDLE, args: &[u8]) {
    if args.is_empty() {
        writeln(h, b"Sets the default console foreground and background colors.");
        writeln(h, b"");
        writeln(h, b"COLOR [attr]");
        writeln(h, b"");
        writeln(h, b"  0 = Black       8 = Gray");
        writeln(h, b"  1 = Blue        9 = Light Blue");
        writeln(h, b"  2 = Green       A = Light Green");
        writeln(h, b"  3 = Aqua        B = Light Aqua");
        writeln(h, b"  4 = Red         C = Light Red");
        writeln(h, b"  5 = Purple      D = Light Purple");
        writeln(h, b"  6 = Yellow      E = Light Yellow");
        writeln(h, b"  7 = White       F = Bright White");
    } else {
        writeln(h, b"Color changed.");
    }
}

fn cmd_title(h: HANDLE, _args: &[u8]) {
    // In a real implementation, send escape sequence to change window title
    writeln(h, b"Title set.");
}

fn cmd_tasklist(h: HANDLE) {
    writeln(h, b"");
    writeln(h, b"Image Name          PID Session Name   Mem Usage");
    writeln(h, b"========================= ===== ============ ==========");
    writeln(h, b"vlados.vex            0 Services           0 K");
    writeln(h, b"vladss.vex            1 Services           0 K");
    writeln(h, b"csrss.vex             2 Services           0 K");
    writeln(h, b"wininit.vex           3 Services           0 K");
    writeln(h, b"services.vex          4 Services           0 K");
    writeln(h, b"lsass.vex             5 Services           0 K");
    writeln(h, b"winlogon.vex          6 Console            0 K");
    writeln(h, b"cmd.vex               7 Console            0 K");
    writeln(h, b"");
}

fn cmd_mem(h: HANDLE) {
    writeln(h, b"");
    writeln(h, b"Memory Information:");
    writeln(h, b"  Total Physical Memory:    64 MB");
    writeln(h, b"  Available Physical:       48 MB");
    writeln(h, b"  Virtual Memory:          128 MB");
    writeln(h, b"  Available Virtual:       112 MB");
    writeln(h, b"");
}

fn cmd_dir(h: HANDLE, _args: &[u8]) {
    writeln(h, b" Volume in drive C has no label.");
    writeln(h, b" Volume Serial Number is 1234-5678");
    writeln(h, b"");
    writeln(h, b" Directory of C:\\");
    writeln(h, b"");
    writeln(h, b"09/08/2026  06:00 PM    <DIR>          .");
    writeln(h, b"09/08/2026  06:00 PM    <DIR>          ..");
    writeln(h, b"09/08/2026  06:00 PM    <DIR>          VladOS");
    writeln(h, b"09/08/2026  06:00 PM    <DIR>          System32");
    writeln(h, b"09/08/2026  06:00 PM    <DIR>          Users");
    writeln(h, b"               0 File(s)              0 bytes");
    writeln(h, b"               5 Dir(s)   67,108,864 bytes free");
    writeln(h, b"");
}

fn cmd_cd(h: HANDLE, _args: &[u8]) {
    writeln(h, b"C:\\");
}

fn cmd_type(h: HANDLE, args: &[u8]) {
    if args.is_empty() {
        writeln(h, b"Required parameter missing");
        return;
    }
    // Try to open and read the file
    let mut path_buf = [0u8; 256];
    let mut path_len = 0;
    // Prepend C: drive letter if not absolute path
    if args.len() > 0 && args[0] != b'\\' {
        let prefix = b"C:\\";
        for &b in prefix.iter() {
            if path_len < path_buf.len() { path_buf[path_len] = b; path_len += 1; }
        }
    }
    for &b in args.iter() {
        if path_len < path_buf.len() { path_buf[path_len] = b; path_len += 1; }
    }

    match ntdll::open(core::str::from_utf8(&path_buf[..path_len]).unwrap_or(""), FILE_GENERIC_READ, FILE_OPEN) {
        Ok(fh) => {
            let mut buf = [0u8; 4096];
            loop {
                match ntdll::read(fh, &mut buf) {
                    Ok(n) if n > 0 => {
                        let _ = ntdll::write(h, &buf[..n]);
                        if n < buf.len() { break; }
                    }
                    _ => break,
                }
            }
            let _ = ntdll::close(fh);
        }
        Err(_) => {
            write_str(h, b"The system cannot find the file specified.");
            write_crlf(h);
        }
    }
}

fn cmd_del(h: HANDLE, args: &[u8]) {
    if args.is_empty() {
        writeln(h, b"Required parameter missing");
        return;
    }
    // Convert ASCII path to NT path
    let mut nt_path = alloc::vec::Vec::new();
    if args[0] != b'\\' {
        nt_path.extend_from_slice(b"\\DosDevices\\C:\\");
    }
    nt_path.extend_from_slice(args);
    let path_str = core::str::from_utf8(&nt_path).unwrap_or("");

    let (oa, _buf) = make_oa(path_str);
    let status = unsafe { NtDeleteFile(&oa) };
    if status == STATUS_SUCCESS {
        writeln(h, b"File deleted.");
    } else {
        writeln(h, b"The system cannot find the file specified.");
    }
}

fn cmd_mkdir(h: HANDLE, args: &[u8]) {
    if args.is_empty() {
        writeln(h, b"Required parameter missing");
        return;
    }
    let mut nt_path = alloc::vec::Vec::new();
    if args[0] != b'\\' {
        nt_path.extend_from_slice(b"\\DosDevices\\C:\\");
    }
    nt_path.extend_from_slice(args);
    let path_str = core::str::from_utf8(&nt_path).unwrap_or("");

    let (oa, _buf) = make_oa(path_str);
    let mut handle: HANDLE = core::ptr::null_mut();
    let status = unsafe {
        NtCreateDirectoryObject(&mut handle, DIRECTORY_QUERY | DIRECTORY_CREATE_OBJECT, &oa)
    };
    if status == STATUS_SUCCESS {
        writeln(h, b"Directory created.");
        if !handle.is_null() { unsafe { NtClose(handle); } }
    } else {
        writeln(h, b"Unable to create directory.");
    }
}

fn cmd_copy(h: HANDLE, args: &[u8]) {
    if args.is_empty() {
        writeln(h, b"Required parameter missing");
        return;
    }
    // Simple: just show usage
    writeln(h, b"        0 file(s) copied.");
}

fn cmd_ren(h: HANDLE, _args: &[u8]) {
    writeln(h, b"The system cannot find the file specified.");
}

fn cmd_tree(h: HANDLE, _args: &[u8]) {
    writeln(h, b"Folder PATH listing");
    writeln(h, b"Volume serial number is 1234-5678");
    writeln(h, b"C:.");
    writeln(h, b"+---VladOS");
    writeln(h, b"|   +---Boot");
    writeln(h, b"|   +---System32");
    writeln(h, b"+---Users");
}

// ============================================================
// Main command loop
// ============================================================

#[panic_handler]
fn panic(_info: &core::panic::PanicInfo) -> ! { loop {} }

#[no_mangle]
pub extern "C" fn _start() -> ! {
    let h_out = unsafe { ntdll::open("\\DosDevices\\CON", FILE_GENERIC_WRITE, FILE_OPEN) }
        .unwrap_or(core::ptr::null_mut());
    let h_in = unsafe { ntdll::open("\\DosDevices\\CON", FILE_GENERIC_READ, FILE_OPEN) }
        .unwrap_or(core::ptr::null_mut());

    // Print banner
    writeln(h_out, b"");
    writeln(h_out, b"VladOS [Version 10.0.19041.1]");
    writeln(h_out, b"(c) VladOS Corporation. All rights reserved.");
    writeln(h_out, b"");

    let mut input_buf = [0u8; BUFFER_SIZE];

    loop {
        // Print prompt
        write_str(h_out, b"C:\\>");

        // Read input line
        let mut line = [0u8; 512];
        let mut line_len = 0;
        let mut line_done = false;

        while !line_done && line_len < line.len() {
            let mut byte = [0u8; 1];
            match ntdll::read(h_in, &mut byte) {
                Ok(1) => {
                    match byte[0] {
                        b'\n' | b'\r' => {
                            if line_len > 0 { line_done = true; }
                            // Echo newline
                            write_str(h_out, b"\r\n");
                        }
                        0x08 => { // Backspace
                            if line_len > 0 {
                                line_len -= 1;
                                write_str(h_out, b"\x08 \x08");
                            }
                        }
                        c => {
                            line[line_len] = c;
                            line_len += 1;
                            let _ = ntdll::write(h_out, &byte);
                        }
                    }
                }
                _ => {
                    ntdll::sleep(10);
                }
            }
        }

        if line_len == 0 { continue; }

        let cmd_line = trim_whitespace(&line[..line_len]);

        // Parse command and arguments
        let space_pos = cmd_line.iter().position(|&b| b == b' ');
        let (cmd, args) = match space_pos {
            Some(pos) => (&cmd_line[..pos], trim_whitespace(&cmd_line[pos + 1..])),
            None => (cmd_line, b"" as &[u8]),
        };

        if cmd.is_empty() { continue; }

        // Execute command
        match cmd.to_ascii_lowercase().as_slice() {
            b"ver" => cmd_ver(h_out),
            b"help" | b"?" => cmd_help(h_out),
            b"cls" => cmd_cls(h_out),
            b"echo" => cmd_echo(h_out, args),
            b"time" => cmd_time(h_out),
            b"date" => cmd_date(h_out),
            b"hostname" => cmd_hostname(h_out),
            b"set" => cmd_set(h_out),
            b"path" => cmd_path(h_out),
            b"color" => cmd_color(h_out, args),
            b"title" => cmd_title(h_out, args),
            b"tasklist" => cmd_tasklist(h_out),
            b"mem" => cmd_mem(h_out),
            b"dir" | b"ls" => cmd_dir(h_out, args),
            b"cd" | b"chdir" => cmd_cd(h_out, args),
            b"type" => cmd_type(h_out, args),
            b"del" | b"erase" => cmd_del(h_out, args),
            b"mkdir" | b"md" => cmd_mkdir(h_out, args),
            b"copy" | b"cp" => cmd_copy(h_out, args),
            b"ren" | b"rename" => cmd_ren(h_out, args),
            b"tree" => cmd_tree(h_out, args),
            b"exit" | b"quit" => {
                let _ = ntdll::close(h_out);
                let _ = ntdll::close(h_in);
                ntdll::exit(0);
            }
            _ => {
                write_str(h_out, b"'");
                write_str(h_out, cmd);
                writeln(h_out, b"' is not recognized as an internal or external command,");
                writeln(h_out, b"operable program or batch file.");
            }
        }
    }
}
