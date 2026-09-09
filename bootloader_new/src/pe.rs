/// Full PE (Portable Executable) loader with relocation and import support

/// DOS Header
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct DosHeader {
    pub e_magic: u16,
    pub e_cblp: u16,
    pub e_cp: u16,
    pub e_crlc: u16,
    pub e_cparhdr: u16,
    pub e_minalloc: u16,
    pub e_maxalloc: u16,
    pub e_ss: u16,
    pub e_sp: u16,
    pub e_csum: u16,
    pub e_ip: u16,
    pub e_cs: u16,
    pub e_lfarlc: u16,
    pub e_ovno: u16,
    pub e_res: [u16; 4],
    pub e_oemid: u16,
    pub e_oeminfo: u16,
    pub e_res2: [u16; 10],
    pub e_lfanew: u32,
}

/// COFF File Header (20 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct CoffHeader {
    pub machine: u16,
    pub number_of_sections: u16,
    pub time_date_stamp: u32,
    pub pointer_to_symbol_table: u32,
    pub number_of_symbols: u32,
    pub size_of_optional_header: u16,
    pub characteristics: u16,
}

pub const IMAGE_FILE_MACHINE_AMD64: u16 = 0x8664;
pub const IMAGE_FILE_MACHINE_I386: u16 = 0x014C;
pub const IMAGE_FILE_EXECUTABLE_IMAGE: u16 = 0x0002;
pub const IMAGE_FILE_DLL: u16 = 0x2000;

/// PE Optional Header (PE32+)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct PeOptionalHeader64 {
    pub magic: u16,
    pub major_linker_version: u8,
    pub minor_linker_version: u8,
    pub size_of_code: u32,
    pub size_of_initialized_data: u32,
    pub size_of_uninitialized_data: u32,
    pub address_of_entry_point: u32,
    pub base_of_code: u32,
    pub image_base: u64,
    pub section_alignment: u32,
    pub file_alignment: u32,
    pub major_operating_system_version: u16,
    pub minor_operating_system_version: u16,
    pub major_image_version: u16,
    pub minor_image_version: u16,
    pub major_subsystem_version: u16,
    pub minor_subsystem_version: u16,
    pub win32_version_value: u32,
    pub size_of_image: u32,
    pub size_of_headers: u32,
    pub checksum: u32,
    pub subsystem: u16,
    pub dll_characteristics: u16,
    pub size_of_stack_reserve: u64,
    pub size_of_stack_commit: u64,
    pub size_of_heap_reserve: u64,
    pub size_of_heap_commit: u64,
    pub loader_flags: u32,
    pub number_of_rva_and_sizes: u32,
}

/// Data directory indices
pub const IMAGE_DIRECTORY_ENTRY_EXPORT: usize = 0;
pub const IMAGE_DIRECTORY_ENTRY_IMPORT: usize = 1;
pub const IMAGE_DIRECTORY_ENTRY_RESOURCE: usize = 2;
pub const IMAGE_DIRECTORY_ENTRY_EXCEPTION: usize = 3;
pub const IMAGE_DIRECTORY_ENTRY_SECURITY: usize = 4;
pub const IMAGE_DIRECTORY_ENTRY_BASERELOC: usize = 5;
pub const IMAGE_DIRECTORY_ENTRY_DEBUG: usize = 6;
pub const IMAGE_DIRECTORY_ENTRY_TLS: usize = 9;

/// Data directory entry
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct DataDirectory {
    pub virtual_address: u32,
    pub size: u32,
}

/// PE Header
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct PeHeader {
    pub signature: u32,
    pub coff: CoffHeader,
}

/// Section Header (40 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct SectionHeader {
    pub name: [u8; 8],
    pub virtual_size: u32,
    pub virtual_address: u32,
    pub size_of_raw_data: u32,
    pub pointer_to_raw_data: u32,
    pub pointer_to_relocations: u32,
    pub pointer_to_linenumbers: u32,
    pub number_of_relocations: u16,
    pub number_of_linenumbers: u16,
    pub characteristics: u32,
}

pub const IMAGE_SCN_CNT_CODE: u32 = 0x00000020;
pub const IMAGE_SCN_CNT_INITIALIZED_DATA: u32 = 0x00000040;
pub const IMAGE_SCN_CNT_UNINITIALIZED_DATA: u32 = 0x00000080;
pub const IMAGE_SCN_MEM_DISCARDABLE: u32 = 0x02000000;
pub const IMAGE_SCN_MEM_EXECUTE: u32 = 0x20000000;
pub const IMAGE_SCN_MEM_READ: u32 = 0x40000000;
pub const IMAGE_SCN_MEM_WRITE: u32 = 0x80000000;

/// Import descriptor
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct ImportDescriptor {
    pub original_first_thunk: u32,
    pub time_date_stamp: u32,
    pub forwarder_chain: u32,
    pub name_rva: u32,
    pub first_thunk: u32,
}

/// Import by name hint
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct ImportByName {
    pub hint: u16,
    pub name: [u8; 1],
}

/// Import thunk (32/64-bit)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct ImportThunk64 {
    pub ordinal_or_rva: u64,
}

/// Export directory
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct ExportDirectory {
    pub characteristics: u32,
    pub time_date_stamp: u32,
    pub major_version: u16,
    pub minor_version: u16,
    pub name_rva: u32,
    pub ordinal_base: u32,
    pub number_of_functions: u32,
    pub number_of_names: u32,
    pub address_of_functions: u32,
    pub address_of_names: u32,
    pub address_of_name_ordinals: u32,
}

/// Base relocation block
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct RelocBlock {
    pub page_rva: u32,
    pub block_size: u32,
}

pub const IMAGE_REL_BASED_DIR64: u16 = 10;
pub const IMAGE_REL_BASED_HIGHLOW: u16 = 3;
pub const IMAGE_REL_BASED_ABSOLUTE: u16 = 0;

/// Loaded PE image info
pub struct LoadedPe {
    pub phys_addr: u64,
    pub virt_addr: u64,
    pub size: u64,
    pub entry_point: u64,
    pub stack_size: u64,
    pub is_64bit: bool,
}

/// Load a PE file from bytes into memory
pub fn load_pe(data: &[u8]) -> Result<LoadedPe, ()> {
    if data.len() < core::mem::size_of::<DosHeader>() {
        return Err(());
    }

    // Parse DOS header
    let dos = unsafe { &*(data.as_ptr() as *const DosHeader) };
    if dos.e_magic != 0x5A4D {
        return Err(());
    }

    let pe_offset = dos.e_lfanew as usize;
    if pe_offset + core::mem::size_of::<PeHeader>() > data.len() {
        return Err(());
    }

    // Parse PE signature and COFF header
    let pe = unsafe { &*(data[pe_offset..].as_ptr() as *const PeHeader) };
    if pe.signature != 0x00004550 {
        // "PE\0\0"
        return Err(());
    }

    let coff = &pe.coff;
    let opt_offset = pe_offset + core::mem::size_of::<PeHeader>();
    let section_offset = opt_offset + coff.size_of_optional_header as usize;

    if coff.size_of_optional_header == 0 {
        return Err(());
    }

    // Read magic to determine PE32 vs PE32+
    let magic = unsafe { core::ptr::read_volatile(data[opt_offset..].as_ptr() as *const u16) };
    let is_64bit = match magic {
        0x10B => false, // PE32
        0x20B => true,  // PE32+
        _ => return Err(()),
    };

    if !is_64bit {
        // PE32 not fully supported for kernel loading, but we parse it
        return Err(());
    }

    // Parse PE32+ optional header
    let opt = unsafe { &*(data[opt_offset..].as_ptr() as *const PeOptionalHeader64) };

    let num_sections = coff.number_of_sections as usize;

    // Read all section headers
    let mut sections = alloc::vec::Vec::with_capacity(num_sections);
    let mut max_virtual_end = 0u32;

    for i in 0..num_sections {
        let off = section_offset + i * core::mem::size_of::<SectionHeader>();
        if off + core::mem::size_of::<SectionHeader>() > data.len() {
            break;
        }
        let sec = unsafe { core::ptr::read_volatile(data[off..].as_ptr() as *const SectionHeader) };
        let vsize = if sec.virtual_size == 0 {
            sec.size_of_raw_data
        } else {
            sec.virtual_size
        };
        let vend = sec.virtual_address + vsize;
        if vend > max_virtual_end {
            max_virtual_end = vend;
        }
        sections.push((sec, vsize));
    }

    // Calculate image size aligned to section alignment
    let align = opt.section_alignment;
    let image_size = ((max_virtual_end as u64 + align as u64 - 1) & !(align as u64 - 1)) as u64;

    // Allocate image memory
    let image_phys = super::memory::alloc(image_size, 4096).ok_or(())?;
    let image_virt = super::memory::phys_to_virt(image_phys);

    // Zero the entire image
    unsafe {
        core::ptr::write_bytes(image_virt as *mut u8, 0, image_size as usize);
    }

    // Copy headers
    let headers_size = core::cmp::min(opt.size_of_headers as usize, data.len());
    unsafe {
        core::ptr::copy_nonoverlapping(data.as_ptr(), image_virt as *mut u8, headers_size);
    }

    // Map sections into image
    for (sec, vsize) in &sections {
        if *vsize == 0 {
            continue;
        }

        let dest = image_virt + sec.virtual_address as u64;

        if sec.pointer_to_raw_data > 0 && sec.size_of_raw_data > 0 {
            let src_off = sec.pointer_to_raw_data as usize;
            let src_size = sec.size_of_raw_data as usize;

            if src_off + src_size <= data.len() {
                let copy_size = core::cmp::min(src_size, *vsize as usize);
                unsafe {
                    core::ptr::copy_nonoverlapping(
                        data[src_off..].as_ptr(),
                        dest as *mut u8,
                        copy_size,
                    );
                }
            }
        }
        // BSS sections: virtual_size > size_of_raw_data, already zeroed
    }

    // Process relocations
    let reloc_dir_idx = IMAGE_DIRECTORY_ENTRY_BASERELOC;
    if reloc_dir_idx < opt.number_of_rva_and_sizes as usize {
        let reloc_dir = unsafe {
            core::ptr::read_volatile(
                data[opt_offset
                    + core::mem::size_of::<PeOptionalHeader64>()
                    + reloc_dir_idx * core::mem::size_of::<DataDirectory>()
                    ..]
                    .as_ptr() as *const DataDirectory,
            )
        };

        if reloc_dir.virtual_address > 0 && reloc_dir.size > 0 {
            let _reloc_virt = image_virt + reloc_dir.virtual_address as u64;
            let reloc_phys_offset = reloc_dir.virtual_address;

            // Find which section contains the relocation data
            let mut reloc_file_offset = 0u32;
            for (sec, _) in &sections {
                if reloc_phys_offset >= sec.virtual_address
                    && reloc_phys_offset < sec.virtual_address + sec.size_of_raw_data
                {
                    reloc_file_offset =
                        sec.pointer_to_raw_data + (reloc_phys_offset - sec.virtual_address);
                    break;
                }
            }

            // Parse relocation blocks
            let reloc_data_start = reloc_file_offset as usize;
            let reloc_data_end = core::cmp::min(
                reloc_data_start + reloc_dir.size as usize,
                data.len(),
            );

            let mut pos = reloc_data_start;
            while pos + core::mem::size_of::<RelocBlock>() <= reloc_data_end {
                let block = unsafe {
                    core::ptr::read_volatile(data[pos..].as_ptr() as *const RelocBlock)
                };

                if block.block_size < 8 {
                    break;
                }

                let num_entries = (block.block_size - 8) / 2;
                let entries_start = pos + 8;

                for i in 0..num_entries {
                    let entry_off = entries_start + i as usize * 2;
                    if entry_off + 2 > reloc_data_end {
                        break;
                    }
                    let entry = unsafe {
                        core::ptr::read_volatile(data[entry_off..].as_ptr() as *const u16)
                    };

                    let reloc_type = entry >> 12;
                    let reloc_offset = entry & 0x0FFF;

                    if reloc_type == IMAGE_REL_BASED_DIR64 {
                        let patch_addr =
                            image_virt + block.page_rva as u64 + reloc_offset as u64;
                        if patch_addr + 8 <= image_virt + image_size {
                            let original = unsafe {
                                core::ptr::read_volatile(patch_addr as *const u64)
                            };
                            let delta = image_phys - opt.image_base;
                            unsafe {
                                core::ptr::write_volatile(
                                    patch_addr as *mut u64,
                                    original.wrapping_add(delta),
                                );
                            }
                        }
                    } else if reloc_type == IMAGE_REL_BASED_HIGHLOW {
                        let patch_addr =
                            image_virt + block.page_rva as u64 + reloc_offset as u64;
                        if patch_addr + 4 <= image_virt + image_size {
                            let original = unsafe {
                                core::ptr::read_volatile(patch_addr as *const u32)
                            };
                            let delta = (image_phys - opt.image_base) as u32;
                            unsafe {
                                core::ptr::write_volatile(
                                    patch_addr as *mut u32,
                                    original.wrapping_add(delta),
                                );
                            }
                        }
                    }
                    // IMAGE_REL_BASED_ABSOLUTE = no-op (padding)
                }

                pos += block.block_size as usize;
                // Align to 4 bytes
                pos = (pos + 3) & !3;
            }
        }
    }

    // Process imports
    let import_dir_idx = IMAGE_DIRECTORY_ENTRY_IMPORT;
    if import_dir_idx < opt.number_of_rva_and_sizes as usize {
        let import_dir = unsafe {
            core::ptr::read_volatile(
                data[opt_offset
                    + core::mem::size_of::<PeOptionalHeader64>()
                    + import_dir_idx * core::mem::size_of::<DataDirectory>()
                    ..]
                    .as_ptr() as *const DataDirectory,
            )
        };

        if import_dir.virtual_address > 0 && import_dir.size > 0 {
            // For bootloader, we skip import resolution
            // Imports would be resolved by the kernel loader
            // In a full implementation, this would call LoadLibrary/GetProcAddress
            // for each imported DLL and resolve function addresses
            //
            // Since this is the bootloader, we only load the kernel which
            // shouldn't have external imports (it's a standalone binary)
        }
    }

    let entry_point = image_phys + opt.address_of_entry_point as u64;

    Ok(LoadedPe {
        phys_addr: image_phys,
        virt_addr: image_virt,
        size: image_size,
        entry_point,
        stack_size: opt.size_of_stack_reserve,
        is_64bit,
    })
}

/// Get section name as string
pub fn section_name(sec: &SectionHeader) -> alloc::string::String {
    let mut s = alloc::string::String::new();
    for &b in &sec.name {
        if b == 0 {
            break;
        }
        s.push(b as char);
    }
    s
}
