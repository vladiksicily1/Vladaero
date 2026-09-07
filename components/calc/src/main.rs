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

fn print(s: &str) {
    unsafe { sys_write(1, s.as_bytes()) };
}

fn println(s: &str) {
    print(s);
    print("\r\n");
}

fn parse_num(s: &str) -> Option<i64> {
    let s = s.trim();
    if s.is_empty() {
        return None;
    }
    let (neg, s) = if let Some(stripped) = s.strip_prefix('-') {
        (true, stripped)
    } else {
        (false, s)
    };
    let mut val: i64 = 0;
    for b in s.bytes() {
        if b >= b'0' && b <= b'9' {
            val = val.checked_mul(10)?.checked_add((b - b'0') as i64)?;
        } else {
            return None;
        }
    }
    Some(if neg { -val } else { val })
}

fn print_i64(mut n: i64) {
    if n == 0 {
        print("0");
        return;
    }
    if n < 0 {
        print("-");
        n = -n;
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

pub fn evaluate_expr(expr: &str) {
    let mut op_idx = None;
    let mut op_char = ' ';
    for (i, c) in expr.char_indices() {
        if i > 0 && (c == '+' || c == '-' || c == '*' || c == '/' || c == '%' || c == '^') {
            op_idx = Some(i);
            op_char = c;
            break;
        }
    }

    if let Some(idx) = op_idx {
        let left_str = expr[..idx].trim();
        let right_str = expr[idx + 1..].trim();

        let left_num = parse_num(left_str);
        let right_num = parse_num(right_str);

        match (left_num, right_num) {
            (Some(a), Some(b)) => {
                print("= ");
                match op_char {
                    '+' => print_i64(a.wrapping_add(b)),
                    '-' => print_i64(a.wrapping_sub(b)),
                    '*' => print_i64(a.wrapping_mul(b)),
                    '/' => {
                        if b == 0 {
                            print("Error: Division by zero");
                        } else {
                            print_i64(a / b);
                        }
                    }
                    '%' => {
                        if b == 0 {
                            print("Error: Modulo by zero");
                        } else {
                            print_i64(a % b);
                        }
                    }
                    '^' => {
                        let mut res = 1i64;
                        for _ in 0..b {
                            res = res.wrapping_mul(a);
                        }
                        print_i64(res);
                    }
                    _ => {}
                }
                println("");
            }
            _ => {
                println("Syntax Error: Invalid operands");
            }
        }
    } else if let Some(n) = parse_num(expr) {
        print("= ");
        print_i64(n);
        println("");
    } else {
        println("Syntax Error: Unknown expression");
    }
}

pub fn run_calc_interactive() {
    print("\x1b[2J\x1b[H");
    println("================================================================================");
    println("                    VladOS 10 Professional - Calculator                         ");
    println("================================================================================");
    println(" Standard 64-bit Integer & Arithmetic Evaluator (supports +, -, *, /, %, ^)");
    println(" Type an expression (e.g. 1024 * 32, 2 ^ 10) or 'exit' to quit.");
    println("--------------------------------------------------------------------------------");

    let mut line_buf = [0u8; 128];

    loop {
        print("calc> ");
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

        let expr = core::str::from_utf8(&line_buf[..line_len]).unwrap_or("").trim();
        if expr == "exit" || expr == "quit" || expr == "q" {
            println("Exiting Calculator...");
            break;
        } else if expr.is_empty() {
            continue;
        }

        evaluate_expr(expr);
    }
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
        evaluate_expr(args.trim());
    } else {
        run_calc_interactive();
    }
    0
}

#[panic_handler]
fn panic(_info: &PanicInfo) -> ! {
    loop {
        unsafe { sys_yield() };
    }
}
