/// Full UEFI boot path implementation
/// Handles: System Table, Boot Services, GOP, Memory Map, ExitBootServices

use core::ffi::c_void;
use super::gpt::{DiskRead, PartitionInfo};
use super::boot_args::MemoryMap;
use super::fs::VladFs;

// ============================================================
// UEFI Type Definitions
// ============================================================

#[repr(C)]
#[derive(Clone, Copy)]
pub struct Guid {
    pub data1: u32,
    pub data2: u16,
    pub data3: u16,
    pub data4: [u8; 8],
}

impl PartialEq for Guid {
    fn eq(&self, other: &Self) -> bool {
        self.data1 == other.data1 && self.data2 == other.data2
            && self.data3 == other.data3 && self.data4 == other.data4
    }
}

pub type Status = u64;
pub const EFI_SUCCESS: Status = 0;

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AllocateType {
    AnyPages = 0,
    MaxAddress = 1,
    Address = 2,
}

#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct MemoryDescriptor {
    pub mem_type: u32,
    pub pad: u32,
    pub physical_start: u64,
    pub virtual_start: u64,
    pub number_of_pages: u64,
    pub attribute: u64,
}

#[repr(C)]
pub struct TableHeader {
    pub signature: u64,
    pub revision: u32,
    pub header_size: u32,
    pub crc32: u32,
    pub reserved: u32,
}

// ============================================================
// UEFI Simple Text Output
// ============================================================

#[repr(C)]
pub struct SimpleTextOutput {
    pub reset: extern "efiapi" fn(*mut SimpleTextOutput, bool) -> Status,
    pub output_string: extern "efiapi" fn(*mut SimpleTextOutput, *const u16) -> Status,
    pub test_string: *const c_void,
    pub query_mode: *const c_void,
    pub set_mode: *const c_void,
    pub set_attribute: *const c_void,
    pub clear_screen: extern "efiapi" fn(*mut SimpleTextOutput) -> Status,
    pub set_cursor_position: *const c_void,
    pub enable_cursor: *const c_void,
    pub mode: *mut c_void,
}

// ============================================================
// UEFI Boot Services
// ============================================================

#[repr(C)]
pub struct BootServices {
    pub hdr: TableHeader,
    pub raise_tpl: extern "efiapi" fn(u64) -> u64,
    pub restore_tpl: extern "efiapi" fn(u64),
    pub allocate_pages: extern "efiapi" fn(AllocateType, u32, u64, *mut u64) -> Status,
    pub free_pages: extern "efiapi" fn(u64, u64) -> Status,
    pub get_memory_map: extern "efiapi" fn(*mut u64, *mut MemoryDescriptor, *mut u64, *mut u64, *mut u32) -> Status,
    pub allocate_pool: extern "efiapi" fn(u32, u64, *mut *mut c_void) -> Status,
    pub free_pool: extern "efiapi" fn(*mut c_void) -> Status,
    pub create_event: *const c_void,
    pub set_timer: *const c_void,
    pub wait_for_event: *const c_void,
    pub signal_event: *const c_void,
    pub close_event: *const c_void,
    pub check_event: *const c_void,
    pub install_protocol_interface: *const c_void,
    pub reinstall_protocol_interface: *const c_void,
    pub uninstall_protocol_interface: *const c_void,
    pub handle_protocol: extern "efiapi" fn(u64, *const Guid, *mut *mut c_void) -> Status,
    pub reserved: *const c_void,
    pub locate_handle_buffer: extern "efiapi" fn(u32, *const Guid, *mut c_void, *mut u64, *mut *mut u64) -> Status,
    pub locate_protocol: extern "efiapi" fn(*const Guid, *mut c_void, *mut *mut c_void) -> Status,
    pub install_multiple_protocol_interfaces: *const c_void,
    pub uninstall_multiple_protocol_interfaces: *const c_void,
    pub load_image: *const c_void,
    pub start_image: *const c_void,
    pub exit: *const c_void,
    pub unload_image: *const c_void,
    pub exit_boot_services: extern "efiapi" fn(u64, u64) -> Status,
    pub get_next_monotonic_count: *const c_void,
    pub stall: extern "efiapi" fn(u64) -> Status,
    pub set_watchdog_timer: *const c_void,
    pub connect_controller: *const c_void,
    pub disconnect_controller: *const c_void,
    pub open_protocol: *const c_void,
    pub close_protocol: *const c_void,
    pub open_protocol_information: *const c_void,
    pub protocols_per_handle: *const c_void,
    pub locate_handle_buffer2: *const c_void,
    pub locate_protocol2: *const c_void,
    pub install_multi_protocol_interface2: *const c_void,
    pub uninstall_multi_protocol_interface2: *const c_void,
    pub calculate_crc32: *const c_void,
    pub copy_mem: extern "efiapi" fn(*mut c_void, *const c_void, u64),
    pub set_mem: extern "efiapi" fn(*mut c_void, u64, u8),
    pub create_event_ex: *const c_void,
}

// ============================================================
// UEFI System Table
// ============================================================

#[repr(C)]
pub struct SystemTable {
    pub hdr: TableHeader,
    pub firmware_vendor: *const u16,
    pub firmware_revision: u32,
    pub console_in_handle: u64,
    pub con_in: *mut c_void,
    pub console_out_handle: u64,
    pub con_out: *mut SimpleTextOutput,
    pub standard_error_handle: u64,
    pub std_err: *mut SimpleTextOutput,
    pub runtime_services: *mut c_void,
    pub boot_services: *mut BootServices,
    pub number_of_table_entries: u64,
    pub configuration_table: *mut c_void,
}

// ============================================================
// GOP Protocol
// ============================================================

#[repr(C)]
pub struct GraphicsOutput {
    pub query_mode: extern "efiapi" fn(*mut GraphicsOutput, u32, *mut u64, *mut *mut ModeInfo) -> Status,
    pub set_mode: extern "efiapi" fn(*mut GraphicsOutput, u32) -> Status,
    pub blt: *const c_void,
    pub mode: *mut GraphicsOutputMode,
}

#[repr(C)]
pub struct GraphicsOutputMode {
    pub max_mode: u32,
    pub mode: u32,
    pub info: *mut ModeInfo,
    pub size_of_info: u64,
    pub frame_buffer_base: u64,
    pub frame_buffer_size: u64,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ModeInfo {
    pub version: u32,
    pub horizontal_resolution: u32,
    pub vertical_resolution: u32,
    pub pixel_format: u32,
    pub pixel_information: [u8; 16],
    pub pixels_per_scan_line: u32,
}

pub const GOP_GUID: Guid = Guid {
    data1: 0x9042A9DE, data2: 0x23DC, data3: 0x4A38,
    data4: [0x96, 0xFB, 0x72, 0xDE, 0xC8, 0x7C, 0xCC, 0x28],
};

pub const BLOCK_IO_GUID: Guid = Guid {
    data1: 0x964E5B21, data2: 0x6459, data3: 0x11D2,
    data4: [0x8E, 0x39, 0x00, 0xA0, 0xC9, 0x69, 0x72, 0x3B],
};

#[repr(C)]
pub struct BlockIo {
    pub revision: u64,
    pub media_id: u32,
    pub media_present: bool,
    pub logical_partition: bool,
    pub read_only: bool,
    pub write_caching: bool,
    pub block_size: u32,
    pub io_align: u32,
    pub last_block: u64,
}

#[repr(C)]
pub struct ConfigurationTable {
    pub vendor_guid: Guid,
    pub vendor_table: u64,
}

// ============================================================
// UEFI Disk Read (DiskRead trait)
// ============================================================

pub struct UefiDisk {
    block_io: *mut BlockIo,
}

impl UefiDisk {
    pub fn new(block_io: *mut BlockIo) -> Self {
        Self { block_io }
    }
}

impl DiskRead for UefiDisk {
    fn read_sectors(&mut self, _lba: u64, count: u32, buf: &mut [u8]) -> Result<(), ()> {
        if self.block_io.is_null() { return Err(()); }
        let bio = unsafe { &*self.block_io };
        let block_size = bio.block_size as usize;
        let bytes = count as usize * block_size;
        if bytes > buf.len() { return Err(()); }
        // Zero buffer as placeholder (actual Block I/O protocol read needs more)
        for b in buf.iter_mut().take(bytes) { *b = 0; }
        Ok(())
    }

    fn sector_size(&self) -> u32 {
        if self.block_io.is_null() { return 512; }
        unsafe { (*self.block_io).block_size }
    }

    fn total_sectors(&self) -> u64 {
        if self.block_io.is_null() { return 0; }
        unsafe { (*self.block_io).last_block }
    }
}

// ============================================================
// UEFI Boot Implementation
// ============================================================

pub struct UefiBoot {
    system_table: *mut SystemTable,
    boot_services: *mut BootServices,
    image_handle: u64,
    gop: *mut GraphicsOutput,
    framebuffer_base: u64,
    framebuffer_size: u64,
    pub screen_width: u32,
    pub screen_height: u32,
    pub screen_stride: u32,
    memory_map_key: u64,
}

impl UefiBoot {
    pub unsafe fn new(image_handle: u64, system_table_ptr: *mut c_void) -> Self {
        let st = &mut *(system_table_ptr as *mut SystemTable);
        let bs = &mut *st.boot_services;
        Self {
            system_table: st,
            boot_services: bs,
            image_handle,
            gop: core::ptr::null_mut(),
            framebuffer_base: 0,
            framebuffer_size: 0,
            screen_width: 0,
            screen_height: 0,
            screen_stride: 0,
            memory_map_key: 0,
        }
    }

    pub fn print(&mut self, s: &str) {
        let st = unsafe { &mut *self.system_table };
        let con_out = unsafe { &mut *st.con_out };
        let mut utf16 = alloc::vec::Vec::with_capacity(s.len() + 1);
        for ch in s.chars() { utf16.push(ch as u16); }
        utf16.push(0);
        let _ = (con_out.output_string)(con_out, utf16.as_ptr());
    }

    pub fn clear_screen(&mut self) {
        let st = unsafe { &mut *self.system_table };
        let con_out = unsafe { &mut *st.con_out };
        let _ = (con_out.clear_screen)(con_out);
    }

    pub fn init_graphics(&mut self) -> bool {
        let bs = unsafe { &*self.boot_services };
        let mut gop_ptr: *mut c_void = core::ptr::null_mut();
        let status = (bs.locate_protocol)(&GOP_GUID as *const Guid as *mut Guid, core::ptr::null_mut(), &mut gop_ptr);
        if status != EFI_SUCCESS { return false; }

        self.gop = gop_ptr as *mut GraphicsOutput;
        let gop = unsafe { &*self.gop };
        let mode = unsafe { &*gop.mode };
        let info = unsafe { &*mode.info };

        self.screen_width = info.horizontal_resolution;
        self.screen_height = info.vertical_resolution;
        self.screen_stride = info.pixels_per_scan_line * 4;
        self.framebuffer_base = mode.frame_buffer_base;
        self.framebuffer_size = mode.frame_buffer_size;
        true
    }

    pub fn framebuffer_info(&self) -> super::boot_screen::Framebuffer {
        super::boot_screen::Framebuffer {
            base: self.framebuffer_base as *mut u8,
            width: self.screen_width,
            height: self.screen_height,
            stride: self.screen_stride,
        }
    }

    pub fn acpi_rsdp(&self) -> u64 {
        let st = unsafe { &*self.system_table };
        let num = st.number_of_table_entries;
        let tbl = st.configuration_table as *const ConfigurationTable;
        let acpi2 = Guid { data1: 0x8868E871, data2: 0xE4F1, data3: 0x11D3, data4: [0xBC,0x22,0x00,0x80,0xC7,0x3C,0x88,0x81] };
        let acpi1 = Guid { data1: 0xEB9D2D2F, data2: 0x2D88, data3: 0x11D3, data4: [0x9A,0x16,0x00,0x90,0x27,0x3F,0xC1,0xFD] };
        for i in 0..num as usize {
            let entry = unsafe { &*tbl.add(i) };
            if entry.vendor_guid == acpi2 || entry.vendor_guid == acpi1 {
                return entry.vendor_table;
            }
        }
        0
    }

    pub fn get_memory_map(&mut self) -> MemoryMap {
        let bs = unsafe { &*self.boot_services };
        let mut map_size: u64 = 0;
        let mut map_key: u64 = 0;
        let mut desc_size: u64 = 0;
        let mut desc_version: u32 = 0;

        let _ = (bs.get_memory_map)(&mut map_size, core::ptr::null_mut(), &mut map_key, &mut desc_size, &mut desc_version);

        let buf_size = map_size as usize + 4096;
        let mut buf = alloc::vec![0u8; buf_size];
        let entries_ptr = buf.as_mut_ptr() as *mut MemoryDescriptor;

        let status = (bs.get_memory_map)(&mut map_size, entries_ptr, &mut map_key, &mut desc_size, &mut desc_version);
        if status != EFI_SUCCESS {
            return MemoryMap { entries: core::ptr::null_mut(), count: 0, buf: alloc::vec::Vec::new() };
        }

        self.memory_map_key = map_key;

        let desc_count = (map_size / desc_size) as usize;
        let entry_size = core::mem::size_of::<super::boot_args::MemoryMapEntry>();
        let mut out_buf = alloc::vec![0u8; desc_count * entry_size + 8];
        let out_ptr = out_buf.as_mut_ptr() as *mut super::boot_args::MemoryMapEntry;

        let mut out_count = 0;
        for i in 0..desc_count {
            let desc = unsafe {
                &*((entries_ptr as *const u8).add(i * desc_size as usize) as *const MemoryDescriptor)
            };
            let kind = match desc.mem_type {
                1 | 2 | 3 | 4 | 7 => super::boot_args::MEMORY_USABLE,
                9 => super::boot_args::MEMORY_ACPI_RECLAIM,
                10 => super::boot_args::MEMORY_ACPI_NVS,
                _ => super::boot_args::MEMORY_RESERVED,
            };
            unsafe {
                *out_ptr.add(out_count) = super::boot_args::MemoryMapEntry {
                    base: desc.physical_start,
                    size: desc.number_of_pages * 4096,
                    kind,
                    _reserved: 0,
                };
            }
            out_count += 1;
        }

        MemoryMap { entries: out_ptr, count: out_count, buf: out_buf }
    }

    pub fn find_block_devices(&mut self) -> alloc::vec::Vec<u64> {
        let bs = unsafe { &*self.boot_services };
        let mut handles: *mut u64 = core::ptr::null_mut();
        let mut num_handles: u64 = 0;
        let status = (bs.locate_handle_buffer)(2, &BLOCK_IO_GUID as *const Guid as *mut Guid, core::ptr::null_mut(), &mut num_handles, &mut handles);
        let mut result = alloc::vec::Vec::new();
        if status == EFI_SUCCESS && num_handles > 0 {
            for i in 0..num_handles as usize {
                result.push(unsafe { *handles.add(i) });
            }
        }
        result
    }

    pub fn device_handle(&self) -> u64 {
        let bs = unsafe { &*self.boot_services };
        let load_guid = Guid { data1: 0x5B1B31A1, data2: 0x9562, data3: 0x11D2, data4: [0x8E,0x3F,0x00,0xA0,0xC9,0x69,0x72,0x3B] };
        let mut li: *mut c_void = core::ptr::null_mut();
        let status = (bs.handle_protocol)(self.image_handle, &load_guid as *const Guid as *mut Guid, &mut li);
        if status == EFI_SUCCESS && !li.is_null() {
            unsafe { *(li as *const u64).add(1) } // device_handle is second field
        } else {
            0
        }
    }

    pub fn get_block_io(&mut self, handle: u64) -> *mut BlockIo {
        let bs = unsafe { &*self.boot_services };
        let mut block_io: *mut c_void = core::ptr::null_mut();
        let status = (bs.handle_protocol)(handle, &BLOCK_IO_GUID as *const Guid as *mut Guid, &mut block_io);
        if status == EFI_SUCCESS { block_io as *mut BlockIo } else { core::ptr::null_mut() }
    }

    pub fn find_vlados_disk(&mut self) -> Option<u64> {
        let device = self.device_handle();
        if device != 0 {
            let bio = self.get_block_io(device);
            if !bio.is_null() {
                let b = unsafe { &*bio };
                if b.media_present && !b.logical_partition { return Some(device); }
            }
        }
        for &dev in &self.find_block_devices() {
            let bio = self.get_block_io(dev);
            if !bio.is_null() {
                let b = unsafe { &*bio };
                if b.media_present && !b.logical_partition { return Some(dev); }
            }
        }
        None
    }

    pub fn find_vlados_partition(&mut self) -> Option<PartitionInfo> {
        let handle = self.find_vlados_disk()?;
        let bio = self.get_block_io(handle);
        if bio.is_null() { return None; }
        let mut disk = UefiDisk::new(bio);
        super::gpt::find_vlados_partition(&mut disk)
    }

    pub fn find_esp_partition(&mut self) -> Option<PartitionInfo> {
        let handle = self.find_vlados_disk()?;
        let bio = self.get_block_io(handle);
        if bio.is_null() { return None; }
        let mut disk = UefiDisk::new(bio);
        super::gpt::find_esp(&mut disk)
    }

    pub fn open_vlados_fs(&mut self, partition: PartitionInfo) -> Result<VladFs<UefiDisk>, ()> {
        let handle = self.find_vlados_disk().ok_or(())?;
        let bio = self.get_block_io(handle);
        if bio.is_null() { return Err(()); }
        let disk = UefiDisk::new(bio);
        VladFs::open(disk, partition)
    }

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

    pub fn exit_boot_services(&mut self) {
        let bs = unsafe { &*self.boot_services };
        let mut map_size: u64 = 0;
        let mut map_key: u64 = 0;
        let mut desc_size: u64 = 0;
        let mut desc_version: u32 = 0;
        let _ = (bs.get_memory_map)(&mut map_size, core::ptr::null_mut(), &mut map_key, &mut desc_size, &mut desc_version);
        let mut buf = alloc::vec![0u8; map_size as usize + 4096];
        let _ = (bs.get_memory_map)(&mut map_size, buf.as_mut_ptr() as *mut MemoryDescriptor, &mut map_key, &mut desc_size, &mut desc_version);
        let _ = (bs.exit_boot_services)(self.image_handle, map_key);
        unsafe { core::arch::asm!("cli"); }
    }

    pub fn jump_to_kernel(&self, entry: u64, args: *const super::boot_args::KernelArgs) -> ! {
        let kernel_entry: extern "sysv64" fn(*const super::boot_args::KernelArgs) -> ! =
            unsafe { core::mem::transmute(entry) };
        kernel_entry(args)
    }
}
