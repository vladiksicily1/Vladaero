/// Kernel arguments passed from bootloader to kernel
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct KernelArgs {
    pub kernel_base: u64,
    pub kernel_size: u64,
    pub kernel_entry: u64,
    pub stack_base: u64,
    pub stack_size: u64,
    pub framebuffer_base: u64,
    pub framebuffer_width: u64,
    pub framebuffer_height: u64,
    pub framebuffer_stride: u64,
    pub acpi_rsdp: u64,
    pub memory_map_base: u64,
    pub memory_map_size: u64,
    pub initrd_base: u64,
    pub initrd_size: u64,
}

impl KernelArgs {
    pub fn new() -> Self {
        Self {
            kernel_base: 0,
            kernel_size: 0,
            kernel_entry: 0,
            stack_base: 0,
            stack_size: 0,
            framebuffer_base: 0,
            framebuffer_width: 0,
            framebuffer_height: 0,
            framebuffer_stride: 0,
            acpi_rsdp: 0,
            memory_map_base: 0,
            memory_map_size: 0,
            initrd_base: 0,
            initrd_size: 0,
        }
    }
}

#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct MemoryMapEntry {
    pub base: u64,
    pub size: u64,
    pub kind: u32,
    pub _reserved: u32,
}

pub const MEMORY_USABLE: u32 = 1;
pub const MEMORY_RESERVED: u32 = 2;
pub const MEMORY_ACPI_RECLAIM: u32 = 3;
pub const MEMORY_ACPI_NVS: u32 = 4;
pub const MEMORY_BAD: u32 = 5;

pub struct MemoryMap {
    pub entries: *mut MemoryMapEntry,
    pub count: usize,
    pub buf: alloc::vec::Vec<u8>,
}
