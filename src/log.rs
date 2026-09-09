/// Kernel logging
use crate::types::*;

#[macro_export]
macro_rules! kernel_log {
    ($($arg:tt)*) => {
        unsafe {
            // Write to serial port 0x3F8
            let msg = core::format_args!($($arg)*);
            // Serial output fallback
            crate::log::kernel_log_serial(&msg);
        }
    };
}

pub static mut SERIAL_INITIALIZED: bool = false;

pub fn kernel_log_serial(args: &core::fmt::Arguments) {
    use core::fmt::Write;
    struct SerialWriter;
    impl core::fmt::Write for SerialWriter {
        fn write_str(&mut self, s: &str) -> core::fmt::Result {
            unsafe {
                for &b in s.as_bytes() {
                    while (crate::hal::serial_inb(0x3FD) & 0x20) == 0 {}
                    crate::hal::serial_outb(0x3F8, b);
                }
            }
            Ok(())
        }
    }
    let _ = SerialWriter.write_fmt(*args);
}

pub unsafe fn log_init() {
    // Initialize serial port
    crate::hal::serial_outb(0x3F8 + 1, 0x00);
    crate::hal::serial_outb(0x3F8 + 3, 0x80);
    crate::hal::serial_outb(0x3F8, 0x03);
    crate::hal::serial_outb(0x3F8 + 1, 0x00);
    crate::hal::serial_outb(0x3F8 + 3, 0x03);
    crate::hal::serial_outb(0x3F8 + 2, 0xC7);
    crate::hal::serial_outb(0x3F8 + 4, 0x0B);
    SERIAL_INITIALIZED = true;
}
