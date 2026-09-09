/// Full BIOS boot path implementation
/// Handles: INT 13h LBA disk reads, INT 15h E820 memory map, VGA framebuffer,
/// Protected mode thunk, real mode callbacks

use super::gpt::{DiskRead, PartitionInfo};
use super::boot_args::MemoryMap;
use super::fs::VladFs;

// ============================================================
// BIOS Interrupt Definitions
// ============================================================

/// INT 13h - Extended Read Sectors
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct Dap {
    pub size: u8,
    pub reserved: u8,
    pub sector_count: u16,
    pub buf_offset: u16,
    pub buf_segment: u16,
    pub lba_low: u32,
    pub lba_high: u32,
}

impl Dap {
    pub fn new(lba: u64, count: u16, buf_seg: u16, buf_off: u16) -> Self {
        Self {
            size: 0x10,
            reserved: 0,
            sector_count: count,
            buf_offset: buf_off,
            buf_segment: buf_seg,
            lba_low: lba as u32,
            lba_high: (lba >> 32) as u32,
        }
    }
}

/// INT 15h E820 Memory Map Entry
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct BiosE820Entry {
    pub base: u64,
    pub size: u64,
    pub mem_type: u32,
    pub acpi_ext: u32,
}

impl BiosE820Entry {
    pub fn is_usable(&self) -> bool {
        self.mem_type == 1
    }

    pub fn kind(&self) -> u32 {
        match self.mem_type {
            1 => super::boot_args::MEMORY_USABLE,
            _ => super::boot_args::MEMORY_RESERVED,
        }
    }
}

/// INT 10h VBE Mode Info Block
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct BiosVbeModeInfo {
    pub attributes: u16,
    pub win_a: u8,
    pub win_b: u8,
    pub win_granularity: u16,
    pub win_size: u16,
    pub win_a_seg: u16,
    pub win_b_seg: u16,
    pub win_func_ptr: u32,
    pub bytes_per_scan_line: u16,
    pub x_res: u16,
    pub y_res: u16,
    pub x_char_size: u8,
    pub y_char_size: u8,
    pub num_planes: u8,
    pub bits_per_pixel: u8,
    pub num_banks: u8,
    pub memory_model: u8,
    pub bank_size: u8,
    pub num_image_pages: u8,
    pub reserved1: u8,
    pub red_mask_size: u8,
    pub red_field_pos: u8,
    pub green_mask_size: u8,
    pub green_field_pos: u8,
    pub blue_mask_size: u8,
    pub blue_field_pos: u8,
    pub rsvd_mask_size: u8,
    pub rsvd_field_pos: u8,
    pub direct_color_mode_info: u8,
    pub phys_base_ptr: u32,
    pub reserved2: [u8; 216],
}

// ============================================================
// BIOS Calls (Assembly Thunks)
// ============================================================

extern "C" {
    fn bios_int13h_read_sectors(disk_number: u16, dap_ptr: *const Dap) -> u8;
    fn bios_int13h_check_extensions(disk_number: u16) -> u16;
    fn bios_int13h_read_params(disk_number: u16, cyl_out: *mut u16, sec_out: *mut u16) -> u8;
    fn bios_int15h_e820(continuation: *mut u32, entry_ptr: *mut BiosE820Entry) -> u8;
    fn bios_int15h_c3h() -> u8;
    fn bios_outb(port: u16, value: u8);
    fn bios_inb(port: u16) -> u8;
    fn bios_cpuid(leaf: u32, eax: *mut u32, ebx: *mut u32, ecx: *mut u32, edx: *mut u32);
    fn bios_int10h_vbe_info(mode_number: u16, mode_info: *mut BiosVbeModeInfo) -> u16;
    fn bios_int10h_vbe_set_mode(mode_number: u16) -> u16;
}

// ============================================================
// BIOS Disk Read Implementation (DiskRead trait)
// ============================================================

pub struct BiosDisk {
    disk_number: u16,
}

impl BiosDisk {
    pub fn new(disk_number: u16) -> Self {
        Self { disk_number }
    }
}

impl DiskRead for BiosDisk {
    fn read_sectors(&mut self, lba: u64, count: u32, buf: &mut [u8]) -> Result<(), ()> {
        let sector_size = self.sector_size() as usize;
        let bytes_to_read = count as usize * sector_size;
        if bytes_to_read > buf.len() {
            return Err(());
        }

        unsafe {
            // INT 13h reads to a physical address; we need to convert buf ptr
            let buf_addr = buf.as_ptr() as u32;
            let buf_seg = ((buf_addr >> 4) & 0xFFFF) as u16;
            let buf_off = (buf_addr & 0xF) as u16;

            let dap = Dap::new(lba, count as u16, buf_seg, buf_off);
            let status = bios_int13h_read_sectors(self.disk_number, &dap);
            if status == 0 {
                Ok(())
            } else {
                Err(())
            }
        }
    }

    fn sector_size(&self) -> u32 {
        512
    }

    fn total_sectors(&self) -> u64 {
        0 // Unknown without query
    }
}

// ============================================================
// BIOS Boot Implementation
// ============================================================

pub struct BiosBoot {
    disk_number: u16,
    pub framebuffer_base: u64,
    framebuffer_size: u64,
    pub screen_width: u32,
    pub screen_height: u32,
    pub screen_pitch: u32,
    screen_bpp: u32,
    memory_entries: alloc::vec::Vec<BiosE820Entry>,
}

impl BiosBoot {
    pub unsafe fn new(disk_number: u16) -> Self {
        let mut boot = Self {
            disk_number,
            framebuffer_base: 0,
            framebuffer_size: 0,
            screen_width: 0,
            screen_height: 0,
            screen_pitch: 0,
            screen_bpp: 0,
            memory_entries: alloc::vec::Vec::new(),
        };
        boot.init_a20();
        boot
    }

    fn init_a20(&mut self) {
        unsafe {
            let result = bios_int15h_c3h();
            if result == 0 {
                return;
            }
            self.fast_a20_enable();
        }
    }

    fn fast_a20_enable(&self) {
        unsafe {
            while (bios_inb(0x64) & 2) != 0 {}
            bios_outb(0x64, 0xD1);
            while (bios_inb(0x64) & 2) != 0 {}
            bios_outb(0x60, 0xDF);
            while (bios_inb(0x64) & 2) != 0 {}
            bios_outb(0x64, 0xFF);
            let _ = bios_inb(0x60);
        }
    }

    pub fn detect_memory_map(&mut self) {
        unsafe {
            self.memory_entries.clear();
            let mut continuation: u32 = 0;
            let mut entry = BiosE820Entry { base: 0, size: 0, mem_type: 0, acpi_ext: 0 };
            loop {
                let result = bios_int15h_e820(&mut continuation, &mut entry);
                if result < 3 || result > 4 {
                    break;
                }
                if entry.size > 0 && entry.base < 0x100000000 {
                    self.memory_entries.push(entry);
                }
                if continuation == 0 {
                    break;
                }
            }
        }
    }

    pub fn memory_map(&self) -> MemoryMap {
        let count = self.memory_entries.len();
        let entry_size = core::mem::size_of::<super::boot_args::MemoryMapEntry>();
        let buf_size = count * entry_size + 8;
        let mut buf = alloc::vec![0u8; buf_size];
        let out_ptr = buf.as_mut_ptr() as *mut super::boot_args::MemoryMapEntry;

        for (i, entry) in self.memory_entries.iter().enumerate() {
            unsafe {
                *out_ptr.add(i) = super::boot_args::MemoryMapEntry {
                    base: entry.base,
                    size: entry.size,
                    kind: entry.kind(),
                    _reserved: 0,
                };
            }
        }

        MemoryMap { entries: out_ptr, count, buf }
    }

    pub fn init_vbe(&mut self) -> bool {
        unsafe {
            let target_modes: [u16; 5] = [0x411B, 0x4118, 0x4112, 0x4105, 0x4103];
            let mut best_mode: u16 = 0;
            let mut best_info = core::mem::zeroed::<BiosVbeModeInfo>();

            for &mode in &target_modes {
                let mut mode_buf = core::mem::zeroed::<BiosVbeModeInfo>();
                let result = bios_int10h_vbe_info(mode, &mut mode_buf);
                if result == 0x004F && (mode_buf.attributes & 0x90) == 0x90 {
                    best_mode = mode;
                    best_info = mode_buf;
                    break;
                }
            }

            if best_mode == 0 {
                return false;
            }

            let result = bios_int10h_vbe_set_mode(best_mode | 0x4000);
            if result != 0x004F {
                return false;
            }

            self.framebuffer_base = best_info.phys_base_ptr as u64;
            self.screen_width = best_info.x_res as u32;
            self.screen_height = best_info.y_res as u32;
            self.screen_bpp = best_info.bits_per_pixel as u32;
            self.screen_pitch = best_info.bytes_per_scan_line as u32;
            self.framebuffer_size = (self.screen_pitch * self.screen_height) as u64;
            true
        }
    }

    pub fn framebuffer_mut(&mut self) -> &mut [u8] {
        if self.framebuffer_base == 0 { return &mut [] }
        unsafe { core::slice::from_raw_parts_mut(self.framebuffer_base as *mut u8, self.framebuffer_size as usize) }
    }

    pub fn framebuffer_info(&self) -> super::boot_screen::Framebuffer {
        super::boot_screen::Framebuffer {
            base: self.framebuffer_base as *mut u8,
            width: self.screen_width,
            height: self.screen_height,
            stride: self.screen_pitch,
        }
    }

    pub fn has_long_mode(&self) -> bool {
        unsafe { let mut e=[0u32;4]; bios_cpuid(0x80000001, &mut e[0], &mut e[1], &mut e[2], &mut e[3]); (e[3] & (1<<29))!=0 }
    }

    /// Get a DiskRead implementor for this boot
    pub fn disk(&self) -> BiosDisk {
        BiosDisk::new(self.disk_number)
    }

    /// Find VladOS partition via GPT
    pub fn find_vlados_partition(&mut self) -> Option<PartitionInfo> {
        let mut disk = self.disk();
        super::gpt::find_vlados_partition(&mut disk)
    }

    /// Find ESP partition
    pub fn find_esp_partition(&mut self) -> Option<PartitionInfo> {
        let mut disk = self.disk();
        super::gpt::find_esp(&mut disk)
    }

    /// Open VladFS on a partition
    pub fn open_vlados_fs(&mut self, partition: PartitionInfo) -> Result<VladFs<BiosDisk>, ()> {
        let disk = self.disk();
        VladFs::open(disk, partition)
    }

    /// Load kernel from VladOS partition
    pub fn load_kernel(&mut self) -> Option<(*const u8, u64)> {
        let partition = self.find_vlados_partition().or_else(|| self.find_esp_partition())?;

        let mut fs = self.open_vlados_fs(partition).ok()?;

        let mut file = fs.open_file("System/boot/vlados.bin").ok()?;
        let size = file.size;
        let mut buf = alloc::vec![0u8; size as usize];
        fs.read_at(&mut file, 0, &mut buf).ok()?;

        let ptr = buf.as_ptr();
        core::mem::forget(buf);
        Some((ptr, size))
    }
}
