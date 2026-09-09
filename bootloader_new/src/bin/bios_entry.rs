#![no_std]
#![no_main]

extern crate alloc;

#[panic_handler]
fn panic(_info: &core::panic::PanicInfo) -> ! {
    loop {}
}

#[no_mangle]
pub extern "sysv64" fn bios_entry() -> ! {
    use vladboot::bios::BiosBoot;
    use vladboot::boot_args::KernelArgs;

    unsafe {
        core::arch::asm!("cli");

        let vga = 0xB8000 as *mut u16;
        for i in 0..80 {
            *vga.add(i) = 0x0F00 | (b'.' as u16);
        }

        let mut bios = BiosBoot::new(0x80);
        bios.detect_memory_map();
        let mem_map = bios.memory_map();

        if bios.init_vbe() {
            let fb = bios.framebuffer_mut();
            for chunk in fb.chunks_exact_mut(4) {
                chunk[0] = 0x00;
                chunk[1] = 0x78;
                chunk[2] = 0xD4;
                chunk[3] = 0x00;
            }
            let mut fb_info = bios.framebuffer_info();
            vladboot::boot_screen::show_boot_screen(&mut fb_info);
        }

        let (kernel_data, _kernel_size) = match bios.load_kernel() {
            Some((ptr, sz)) => (ptr, sz),
            None => {
                let msg = b"No kernel found!";
                let vga = 0xB8000 as *mut u8;
                for (i, &c) in msg.iter().enumerate() {
                    *vga.add(i * 2) = c;
                    *vga.add(i * 2 + 1) = 0x4F;
                }
                loop { core::arch::asm!("hlt"); }
            }
        };

        let pe = match vladboot::pe::load_pe(core::slice::from_raw_parts(kernel_data, 4096)) {
            Ok(pe) => pe,
            Err(_) => {
                let msg = b"Invalid PE!";
                let vga = 0xB8000 as *mut u8;
                for (i, &c) in msg.iter().enumerate() {
                    *vga.add(i * 2) = c;
                    *vga.add(i * 2 + 1) = 0x4F;
                }
                loop { core::arch::asm!("hlt"); }
            }
        };

        let mut args = KernelArgs::new();
        args.memory_map_base = mem_map.entries as u64;
        args.memory_map_size = (mem_map.count * core::mem::size_of::<vladboot::boot_args::MemoryMapEntry>()) as u64;
        if bios.framebuffer_base != 0 {
            args.framebuffer_base = bios.framebuffer_base;
            args.framebuffer_width = bios.screen_width as u64;
            args.framebuffer_height = bios.screen_height as u64;
            args.framebuffer_stride = bios.screen_pitch as u64;
        }

        let args_ptr = 0x81000 as *mut KernelArgs;
        core::ptr::write_volatile(args_ptr, args);

        let kernel_fn: extern "sysv64" fn(*const KernelArgs) -> ! =
            core::mem::transmute(pe.entry_point);
        kernel_fn(&*args_ptr)
    }
}
