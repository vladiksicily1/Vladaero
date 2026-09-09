#![no_std]
#![no_main]

use core::ffi::c_void;

extern crate alloc;

#[panic_handler]
fn panic(_info: &core::panic::PanicInfo) -> ! {
    loop {}
}

#[no_mangle]
pub extern "efiapi" fn efi_main(image_handle: u64, system_table: *mut c_void) -> u64 {
    use vladboot::uefi::UefiBoot;
    use vladboot::boot_args::KernelArgs;

    unsafe {
        let mut boot = UefiBoot::new(image_handle, system_table);

        boot.clear_screen();
        boot.print("[VladOS] UEFI Bootloader starting...\r\n");

        if !boot.init_graphics() {
            boot.print("[VladOS] ERROR: Failed to initialize graphics\r\n");
            return 1;
        }

        let mut fb = boot.framebuffer_info();
        vladboot::boot_screen::show_boot_screen(&mut fb);

        boot.print("[VladOS] Detecting memory map...\r\n");
        let mem_map = boot.get_memory_map();
        boot.print("[VladOS] Memory map detected\r\n");

        boot.print("[VladOS] Searching for VladOS partition...\r\n");

        let (kernel_data, _kernel_size) = match boot.load_kernel() {
            Some((ptr, sz)) => {
                boot.print("[VladOS] Kernel loaded from VladFS\r\n");
                (ptr, sz)
            }
            None => {
                boot.print("[VladOS] ERROR: No kernel found\r\n");
                return 1;
            }
        };

        let pe = match vladboot::pe::load_pe(core::slice::from_raw_parts(kernel_data, 4096)) {
            Ok(pe) => {
                boot.print("[VladOS] Kernel PE loaded\r\n");
                pe
            }
            Err(_) => {
                boot.print("[VladOS] ERROR: Invalid PE\r\n");
                return 1;
            }
        };

        let mut args = KernelArgs::new();
        args.acpi_rsdp = boot.acpi_rsdp();
        args.memory_map_base = mem_map.entries as u64;
        args.memory_map_size = (mem_map.count * core::mem::size_of::<vladboot::boot_args::MemoryMapEntry>()) as u64;
        args.framebuffer_base = fb.base as u64;
        args.framebuffer_width = fb.width as u64;
        args.framebuffer_height = fb.height as u64;
        args.framebuffer_stride = fb.stride as u64;

        let args_ptr = 0x81000 as *mut KernelArgs;
        core::ptr::write_volatile(args_ptr, args);

        boot.print("[VladOS] Jumping to kernel...\r\n");

        core::arch::asm!("cli");
        let kernel_fn: extern "sysv64" fn(*const KernelArgs) -> ! =
            core::mem::transmute(pe.entry_point);
        kernel_fn(&*args_ptr)
    }
}
