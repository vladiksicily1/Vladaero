#![no_std]
#![allow(dead_code)]
#![allow(unused_imports)]
#![allow(overflowing_literals)]
#![allow(binary_asm_labels)]
#![allow(dangerous_implicit_autorefs)]

extern crate alloc;

pub mod types;
pub mod log;
pub mod ke;
pub mod mm;
pub mod ps;
pub mod io;
pub mod ob;
pub mod se;
pub mod cm;
pub mod hal;
pub mod nt;
pub mod rtl;
pub mod ldr;
pub mod cc;
pub mod fsrtl;
pub mod pnp;
pub mod po;
pub mod etw;
pub mod wmi;
pub mod whea;
pub mod verifier;
pub mod drvdb;
pub mod kse;
pub mod bcd;
pub mod alpc;
pub mod nls;
pub mod authz;
pub mod bcrypt;
pub mod dbg;
pub mod pcw;
pub mod pf;
pub mod sm;
pub mod tm;
pub mod wer;
pub mod wdi;
pub mod anfw;
pub mod arb;
pub mod fs;
pub mod win32k;
pub mod net;
pub mod drivers;
pub mod init;

pub fn hlt_loop() -> ! {
    loop {
        unsafe {
            core::arch::asm!("hlt");
        }
    }
}
