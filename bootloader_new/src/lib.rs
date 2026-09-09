#![no_std]
#![allow(dead_code)]
#![allow(unused_imports)]

extern crate alloc;

pub mod boot_args;
pub mod boot_screen;
pub mod bmp;
pub mod font_data;
pub mod logo_data;
pub mod math;
pub mod memory;
pub mod fs;
pub mod gpt;
pub mod pe;
pub mod truetype;

// Boot path modules (used by entry points)
pub mod uefi;
pub mod bios;

pub fn hlt_loop() -> ! {
    loop {
        unsafe {
            core::arch::asm!("hlt");
        }
    }
}
