/// # Image Loader (Ldr/Ldrp) - ntoskrnl.exe
///
/// Complete implementation of the Windows image loader subsystem
/// including DLL loading, import resolution, relocation processing,
/// and module management.
///
/// References:
///   - Windows Internals 7th Ed. Part 2, Chapter 3
///   - WRK: ntoskrnl/ldr/
///   - ReactOS: ldr/

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicU32, AtomicUsize, Ordering};

use crate::types::*;
use crate::ke::dispatcher::*;
use crate::rtl::*;

// ============================================================
// Constants
// ============================================================

pub const LDR_IMAGE_BASE: u64 = 0x00000001_40000000;

pub const LDRP_DLL_PROCESS_ATTACH: u32 = 1;
pub const LDRP_DLL_PROCESS_DETACH: u32 = 0;
pub const LDRP_DLL_THREAD_ATTACH: u32 = 2;
pub const LDRP_DLL_THREAD_DETACH: u32 = 3;

pub const LDRP_LOAD_SNAPPED: u32 = 0x00000001;
pub const LDRP_UNLOAD_SNAPPED: u32 = 0x00000002;
pub const LDRP_FAILED_BUCKETS: u32 = 0x00000004;
pub const LDRP_SYSTEM_MAPPED: u32 = 0x00000008;
pub const LDRP_IMAGE_DLL: u32 = 0x00000010;
pub const LDRP_LOAD_IN_PROGRESS: u32 = 0x00000020;
pub const LDRP_UNLOAD_IN_PROGRESS: u32 = 0x00000040;
pub const LDRP_ENTRY_INSERTED: u32 = 0x00000080;
pub const LDRP_DONT_CALL_FOR_THREADS: u32 = 0x00000100;
pub const LDRP_PROCESS_ATTACH_CALLED: u32 = 0x00000200;
pub const LDRP_PROCESS_STATIC_IMPORT: u32 = 0x00000400;
pub const LDRP_DEBUG_SYMBOLS_LOADED: u32 = 0x00000800;
pub const LDRP_IMAGE_NOT_TARGETED: u32 = 0x00001000;
pub const LDRP_IMAGE_DLL_MISMATCH: u32 = 0x00002000;
pub const LDRP_REDIRECTION_FAILED: u32 = 0x00004000;
pub const LDRP_REDIRECTION_SUCCESS: u32 = 0;
pub const LDRP_MATERIALIZE_IMAGE_RANGE: u32 = 0x00008000;

pub const LDR_DATA_TABLE_ENTRY_SIZE: usize = 0x120;

pub const IMAGE_SNAP_BY_ORDINAL64: bool = true;

pub const STATUS_DLL_NOT_FOUND: NtStatus = 0xC0000135;
pub const STATUS_ENTRYPOINT_NOT_FOUND: NtStatus = 0xC0000139;
pub const STATUS_ORDINAL_NOT_FOUND: NtStatus = 0xC0000138;
pub const STATUS_INVALID_IMAGE_FORMAT: NtStatus = 0xC000007B;
pub const STATUS_INVALID_IMAGE_WIN_16: NtStatus = 0xC00000BB;
pub const STATUS_IMAGE_MACHINE_TYPE_MISMATCH: NtStatus = 0xC0000220;
pub const STATUS_DLL_INIT_FAILED: NtStatus = 0xC0000142;
pub const STATUS_BUFFER_OVERFLOW: NtStatus = 0x80000005;
pub const STATUS_INFO_LENGTH_MISMATCH: NtStatus = 0xC0000004;
pub const STATUS_NOT_IMPLEMENTED: NtStatus = 0xC0000002;

// ============================================================
// Logging macros
// ============================================================

macro_rules! ldr_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "ldr_trace")]
        crate::kernel_log!("[Ldr] {}", format_args!($($arg)*));
    };
}

macro_rules! ldr_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ldr] {}", format_args!($($arg)*));
    };
}

macro_rules! ldr_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ldr] {}", format_args!($($arg)*));
    };
}

macro_rules! ldr_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Ldr] {}", format_args!($($arg)*));
    };
}

// ============================================================
// IMAGE_IMPORT_DESCRIPTOR
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageImportDescriptor {
    pub original_first_thunk: u32,
    pub time_date_stamp: u32,
    pub forwarder_chain: u32,
    pub name: u32,
    pub first_thunk: u32,
}

// ============================================================
// IMAGE_THUNK_DATA64
// ============================================================

#[repr(C)]
#[derive(Clone, Copy)]
pub union ImageThunkData64 {
    pub forwarder_string: u64,
    pub function: u64,
    pub ordinal: u64,
    pub address_of_data: u64,
}

impl core::fmt::Debug for ImageThunkData64 {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        write!(f, "ImageThunkData64({:#x})", unsafe { self.function })
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageImportByName {
    pub hint: u16,
    pub name: [u8; 1],
}

// ============================================================
// IMAGE_EXPORT_DIRECTORY
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageExportDirectory {
    pub characteristics: u32,
    pub time_date_stamp: u32,
    pub major_version: u16,
    pub minor_version: u16,
    pub name: u32,
    pub base: u32,
    pub number_of_functions: u32,
    pub number_of_names: u32,
    pub address_of_functions: u32,
    pub address_of_names: u32,
    pub address_of_name_ordinals: u32,
}

// ============================================================
// LDR_DATA_TABLE_ENTRY
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct LdrDataTableEntry {
    pub in_load_order_links: ListEntry,
    pub in_memory_order_links: ListEntry,
    pub in_initialization_order_links: ListEntry,
    pub dll_base: Pvoid,
    pub entry_point: Pvoid,
    pub size_of_image: u32,
    pub full_dll_name: UnicodeString,
    pub base_dll_name: UnicodeString,
    pub flags: u32,
    pub load_count: i16,
    pub tls_index: i16,
    pub hash_links: ListEntry,
    pub time_date_stamp: u32,
    pub imported_as_single_in_pseudo_context: u64,
    pub lock: KspinLock,
    pub thunks: *mut c_void,
    pub actual_thunks: *mut c_void,
    pub original_first_thunk: *mut c_void,
}

impl LdrDataTableEntry {
    pub const fn new() -> Self {
        Self {
            in_load_order_links: ListEntry::new(),
            in_memory_order_links: ListEntry::new(),
            in_initialization_order_links: ListEntry::new(),
            dll_base: core::ptr::null_mut(),
            entry_point: core::ptr::null_mut(),
            size_of_image: 0,
            full_dll_name: UnicodeString::new(),
            base_dll_name: UnicodeString::new(),
            flags: 0,
            load_count: -1,
            tls_index: 0,
            hash_links: ListEntry::new(),
            time_date_stamp: 0,
            imported_as_single_in_pseudo_context: 0,
            lock: 0,
            thunks: core::ptr::null_mut(),
            actual_thunks: core::ptr::null_mut(),
            original_first_thunk: core::ptr::null_mut(),
        }
    }
}

// ============================================================
// PEB_LDR_DATA (extended for loader)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct LdrLoaderLock {
    pub lock: KspinLock,
}

impl LdrLoaderLock {
    pub const fn new() -> Self {
        Self { lock: 0 }
    }
}

// ============================================================
// LDRP_LOAD_CONTEXT
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct LdrpLoadContext {
    pub dll_name: UnicodeString,
    pub flags: u32,
    pub base_address: Pvoid,
    pub entry_point: Pvoid,
    pub size_of_image: u32,
    pub full_path: UnicodeString,
    pub parent_entry: *mut LdrDataTableEntry,
    pub dependency_count: u32,
    pub snap_list: ListEntry,
    pub thunks: ListEntry,
}

impl LdrpLoadContext {
    pub fn new() -> Self {
        Self {
            dll_name: UnicodeString::new(),
            flags: 0,
            base_address: core::ptr::null_mut(),
            entry_point: core::ptr::null_mut(),
            size_of_image: 0,
            full_path: UnicodeString::new(),
            parent_entry: core::ptr::null_mut(),
            dependency_count: 0,
            snap_list: ListEntry::new(),
            thunks: ListEntry::new(),
        }
    }
}

// ============================================================
// LDRP_SNAP_CONTEXT
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct LdrpSnapContext {
    pub module_entry: *mut LdrDataTableEntry,
    pub flags: u32,
    pub thunk_address: Pvoid,
    pub snap_address: Pvoid,
    pub snap_ordinal: u16,
    pub snap_hint: u16,
}

// ============================================================
// LDRP_BUCKET
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct LdrpBucket {
    pub hash: u32,
    pub entry: ListEntry,
}

// ============================================================
// Global Loader State
// ============================================================

pub static LDR_LOCK: KspinLock = 0;
static LDR_INITIALIZED: AtomicBool = AtomicBool::new(false);
static LDR_MODULE_COUNT: AtomicU32 = AtomicU32::new(0);
static mut LDR_ORDER_INDEX: u32 = 0;

// ============================================================
// PLDR_DATA_TABLE_ENTRY - macro for getting from list entry
// ============================================================

/// Get LDR_DATA_TABLE_ENTRY from in_load_order_links
#[inline]
pub unsafe fn ldr_get_entry_from_load_order(entry: *mut ListEntry) -> *mut LdrDataTableEntry {
    let offset = mem::offset_of!(LdrDataTableEntry, in_load_order_links);
    (entry as usize - offset) as *mut LdrDataTableEntry
}

/// Get LDR_DATA_TABLE_ENTRY from in_memory_order_links
#[inline]
pub unsafe fn ldr_get_entry_from_memory_order(entry: *mut ListEntry) -> *mut LdrDataTableEntry {
    let offset = mem::offset_of!(LdrDataTableEntry, in_memory_order_links);
    (entry as usize - offset) as *mut LdrDataTableEntry
}

/// Get LDR_DATA_TABLE_ENTRY from in_initialization_order_links
#[inline]
pub unsafe fn ldr_get_entry_from_init_order(entry: *mut ListEntry) -> *mut LdrDataTableEntry {
    let offset = mem::offset_of!(LdrDataTableEntry, in_initialization_order_links);
    (entry as usize - offset) as *mut LdrDataTableEntry
}

// ============================================================
// LdrpDataTableEntry
// ============================================================

/// Get the LDR_DATA_TABLE_ENTRY for a DLL base address
pub unsafe fn ldrp_data_table_entry(dll_base: Pvoid) -> *mut LdrDataTableEntry {
    if dll_base.is_null() {
        return core::ptr::null_mut();
    }

    let peb = crate::ps::ps_get_current_peb();
    if peb.is_null() || (*peb).ldr.is_null() {
        return core::ptr::null_mut();
    }

    let ldr = &*(*peb).ldr;
    let mut current = ldr.in_load_order_module_list.flink;

    while current != &ldr.in_load_order_module_list as *const ListEntry as *mut ListEntry {
        let entry = ldr_get_entry_from_load_order(current);
        if !entry.is_null() && (*entry).dll_base == dll_base {
            return entry;
        }
        current = (*current).flink;
    }

    core::ptr::null_mut()
}

// ============================================================
// LdrpGetThunkInformation
// ============================================================

pub unsafe fn ldrp_get_thunk_information(
    dll_base: Pvoid,
    import_desc: *mut ImageImportDescriptor,
    import_rva: *mut u32,
    thunk_rva: *mut u32,
    bound_import_rva: *mut u32,
) -> NtStatus {
    if dll_base.is_null() || import_desc.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let nt_header = rtl_image_nt_header(dll_base);
    if nt_header.is_null() {
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let nt = &*nt_header;

    // Find import directory
    if nt.optional_header.number_of_rva_and_sizes as usize <= IMAGE_DIRECTORY_ENTRY_IMPORT {
        return STATUS_INVALID_PARAMETER;
    }

    let import_dir = &nt.optional_header.data_directory[IMAGE_DIRECTORY_ENTRY_IMPORT];
    if import_dir.virtual_address == 0 {
        return STATUS_INVALID_PARAMETER;
    }

    let import_desc_ptr = (dll_base as usize + import_dir.virtual_address as usize)
        as *mut ImageImportDescriptor;

    *import_desc = *import_desc_ptr;
    *import_rva = import_dir.virtual_address;
    *thunk_rva = 0;
    *bound_import_rva = 0;

    // Find IAT
    if nt.optional_header.number_of_rva_and_sizes as usize > IMAGE_DIRECTORY_ENTRY_IAT {
        let iat_dir = &nt.optional_header.data_directory[IMAGE_DIRECTORY_ENTRY_IAT];
        if iat_dir.virtual_address != 0 {
            *thunk_rva = iat_dir.virtual_address;
        }
    }

    // Find bound import
    if nt.optional_header.number_of_rva_and_sizes as usize > IMAGE_DIRECTORY_ENTRY_BOUND_IMPORT {
        let bi_dir = &nt.optional_header.data_directory[IMAGE_DIRECTORY_ENTRY_BOUND_IMPORT];
        if bi_dir.virtual_address != 0 {
            *bound_import_rva = bi_dir.virtual_address;
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// LdrpSnapThunk
// ============================================================

pub unsafe fn ldrp_snap_thunk(
    dll_base: Pvoid,
    snap_thunk: *mut ImageThunkData64,
    lookup_thunk: *mut ImageThunkData64,
    ordinal: u16,
    name: *const u8,
    snap_flags: u32,
    thunk_name: *mut UnicodeString,
) -> NtStatus {
    let _ = (lookup_thunk, thunk_name);

    if dll_base.is_null() || snap_thunk.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let nt_header = rtl_image_nt_header(dll_base);
    if nt_header.is_null() {
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let nt = &*nt_header;

    // Check if import is by ordinal
    let thunk = &*snap_thunk;
    let is_ordinal = (thunk.ordinal >> 63) & 1 == 1;

    if is_ordinal {
        ldr_trace!("LdrpSnapThunk: ordinal={}", ordinal);
    } else {
        // Import by name
        let name_rva = thunk.address_of_data as u32;
        if name_rva == 0 {
            return STATUS_INVALID_PARAMETER;
        }

        let import_name_ptr = (dll_base as usize + name_rva as usize) as *const ImageImportByName;
        let hint = (*import_name_ptr).hint as usize;
        let import_name = (*import_name_ptr).name.as_ptr();

        // Find null terminator
        let mut name_len = 0;
        while *import_name.add(name_len) != 0 && name_len < 256 {
            name_len += 1;
        }

        if snap_flags & LDRP_LOAD_SNAPPED != 0 {
            ldr_trace!("LdrpSnapThunk: name={} hint={} (not resolved)",
                       core::str::from_utf8_unchecked(
                           core::slice::from_raw_parts(import_name, name_len)
                       ), hint);
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// LdrpSnapImportModule
// ============================================================

pub unsafe fn ldrp_snap_import_module(
    module_entry: *mut LdrDataTableEntry,
) -> NtStatus {
    if module_entry.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let entry = &mut *module_entry;
    let dll_base = entry.dll_base;
    if dll_base.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    ldr_trace!("LdrpSnapImportModule: {:?}",
               &entry.base_dll_name);

    let nt_header = rtl_image_nt_header(dll_base);
    if nt_header.is_null() {
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let nt = &*nt_header;

    if nt.optional_header.number_of_rva_and_sizes as usize <= IMAGE_DIRECTORY_ENTRY_IMPORT {
        return STATUS_SUCCESS;
    }

    let import_dir = &nt.optional_header.data_directory[IMAGE_DIRECTORY_ENTRY_IMPORT];
    if import_dir.virtual_address == 0 {
        return STATUS_SUCCESS;
    }

    let mut import_desc = (dll_base as usize + import_dir.virtual_address as usize)
        as *const ImageImportDescriptor;

    while (*import_desc).name != 0 {
        let module_name_rva = (*import_desc).name;
        let module_name_ptr = (dll_base as usize + module_name_rva as usize) as *const u8;

        // Find imported module
        let import_module = ldr_find_module_by_name(module_name_ptr);
        if import_module.is_null() {
            ldr_warn!("LdrpSnapImportModule: import module not found");
            return STATUS_DLL_NOT_FOUND;
        }

        let import_base = (*import_module).dll_base;

        // Walk import thunks
        let mut thunk_ref = if (*import_desc).original_first_thunk != 0 {
            (dll_base as usize + (*import_desc).original_first_thunk as usize) as *mut ImageThunkData64
        } else {
            (dll_base as usize + (*import_desc).first_thunk as usize) as *mut ImageThunkData64
        };

        let mut iat = (dll_base as usize + (*import_desc).first_thunk as usize) as *mut ImageThunkData64;

        while (*thunk_ref).function != 0 {
            let is_ordinal = ((*thunk_ref).ordinal >> 63) & 1 == 1;

            if is_ordinal {
                let ordinal = (thunk_ref.read_unaligned().ordinal & 0xFFFF) as u16;
                let resolved = ldr_get_procedure_address_by_ordinal(import_base, ordinal);

                if !resolved.is_null() {
                    (*iat).function = resolved as u64;
                    ldr_trace!("LdrpSnapImportModule: ordinal={} -> {:p}", ordinal, resolved);
                } else {
                    ldr_err!("LdrpSnapImportModule: ordinal {} not found in {:?}",
                             ordinal, &(*import_module).base_dll_name);
                }
            } else {
                let name_rva = (*thunk_ref).address_of_data as u32;
                if name_rva != 0 {
                    let import_by_name = (import_base as usize + name_rva as usize)
                        as *const ImageImportByName;
                    let hint = (*import_by_name).hint as usize;

                    let resolved = ldr_get_procedure_address_by_name(
                        import_base,
                        (*import_by_name).name.as_ptr(),
                    );

                    if !resolved.is_null() {
                        (*iat).function = resolved as u64;
                        ldr_trace!("LdrpSnapImportModule: hint={} resolved", hint);
                    }
                }
            }

            thunk_ref = thunk_ref.add(1);
            iat = iat.add(1);
        }

        import_desc = import_desc.add(1);
    }

    STATUS_SUCCESS
}

// ============================================================
// LdrpDoRelocations
// ============================================================

pub unsafe fn ldrp_do_relocations(
    dll_base: Pvoid,
    old_base: u64,
    new_base: u64,
) -> NtStatus {
    if dll_base.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let nt_header = rtl_image_nt_header(dll_base);
    if nt_header.is_null() {
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let nt = &*nt_header;

    if nt.optional_header.number_of_rva_and_sizes as usize <= IMAGE_DIRECTORY_ENTRY_BASERELOC {
        return STATUS_SUCCESS;
    }

    let reloc_dir = &nt.optional_header.data_directory[IMAGE_DIRECTORY_ENTRY_BASERELOC];
    if reloc_dir.virtual_address == 0 || reloc_dir.size == 0 {
        return STATUS_SUCCESS;
    }

    let delta = new_base.wrapping_sub(old_base);
    if delta == 0 {
        return STATUS_SUCCESS;
    }

    let mut reloc_ptr = (dll_base as usize + reloc_dir.virtual_address as usize) as *const u8;
    let reloc_end = reloc_ptr.add(reloc_dir.size as usize);

    while reloc_ptr < reloc_end {
        let block_rva = *(reloc_ptr as *const u32);
        let block_size = *(reloc_ptr.add(4) as *const u32);

        if block_size == 0 {
            break;
        }

        let entries_count = (block_size as usize - 8) / 2;
        let mut entry_ptr = reloc_ptr.add(8);

        for _ in 0..entries_count {
            let entry = *(entry_ptr as *const u16);
            let entry_type = (entry >> 12) & 0xF;
            let entry_offset = entry & 0xFFF;

            let patch_addr = (dll_base as usize + block_rva as usize + entry_offset as usize)
                as *mut u64;

            match entry_type {
                0 => {
                    // IMAGE_REL_BASED_ABSOLUTE (padding)
                }
                3 => {
                    // IMAGE_REL_BASED_HIGHLOW (32-bit)
                    let addr = patch_addr as *mut u32;
                    *addr = (*addr).wrapping_add(delta as u32);
                }
                10 => {
                    // IMAGE_REL_BASED_DIR64 (64-bit)
                    *patch_addr = patch_addr.read_unaligned().wrapping_add(delta);
                }
                _ => {
                    ldr_warn!("LdrpDoRelocations: unknown relocation type {}", entry_type);
                }
            }

            entry_ptr = entry_ptr.add(2);
        }

        reloc_ptr = reloc_ptr.add(block_size as usize);
    }

    STATUS_SUCCESS
}

// ============================================================
// LdrpProbeLoadedModuleList
// ============================================================

pub unsafe fn ldrp_probe_loaded_module_list() -> NtStatus {
    let peb = crate::ps::ps_get_current_peb();
    if peb.is_null() || (*peb).ldr.is_null() {
        return STATUS_NOT_FOUND;
    }

    let ldr = &*(*peb).ldr;

    // Verify list integrity
    let head = &ldr.in_load_order_module_list;

    if head.flink.is_null() || head.blink.is_null() {
        ldr_err!("LdrpProbeLoadedModuleList: list corrupted (null links)");
        return STATUS_INVALID_PARAMETER;
    }

    // Walk list and verify each entry
    let mut current = head.flink;
    let mut count = 0u32;

    while current != head as *const ListEntry as *mut ListEntry {
        if current.is_null() {
            ldr_err!("LdrpProbeLoadedModuleList: null pointer in list");
            return STATUS_INVALID_PARAMETER;
        }

        let entry = ldr_get_entry_from_load_order(current);
        if entry.is_null() {
            ldr_err!("LdrpProbeLoadedModuleList: bad entry offset");
            return STATUS_INVALID_PARAMETER;
        }

        // Verify basic invariants
        if (*entry).size_of_image == 0 {
            ldr_warn!("LdrpProbeLoadedModuleList: module with zero size");
        }

        if (*entry).dll_base.is_null() && count > 0 {
            ldr_warn!("LdrpProbeLoadedModuleList: module with null base at index {}", count);
        }

        current = (*current).flink;
        count += 1;

        if count > 10000 {
            ldr_err!("LdrpProbeLoadedModuleList: list appears circular");
            return STATUS_INVALID_PARAMETER;
        }
    }

    ldr_trace!("LdrpProbeLoadedModuleList: {} modules verified", count);
    STATUS_SUCCESS
}

// ============================================================
// LdrpLoadImage - Load image into memory
// ============================================================

pub unsafe fn ldrp_load_image(
    full_path: *const u16,
    path_length: u16,
    dll_base: *mut Pvoid,
) -> NtStatus {
    if full_path.is_null() || dll_base.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    ldr_trace!("LdrpLoadImage: loading from path");

    // Read the DOS header
    let dos_header = full_path as *const ImageDosHeader;
    if (*dos_header).e_magic != 0x5a4d {
        ldr_err!("LdrpLoadImage: invalid DOS signature");
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let nt_offset = (*dos_header).e_lfanew as usize;
    let nt_header = (full_path as usize + nt_offset) as *const ImageNtHeaders64;

    if (*nt_header).signature != IMAGE_NT_SIGNATURE {
        ldr_err!("LdrpLoadImage: invalid PE signature");
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let nt = &*nt_header;

    // Verify it's a 64-bit image
    if nt.optional_header.magic != IMAGE_NT_OPTIONAL_HDR64_MAGIC {
        ldr_err!("LdrpLoadImage: not a 64-bit image");
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let image_size = nt.optional_header.size_of_image as usize;
    let image_base = nt.optional_header.image_base;

    // Allocate memory for the image
    let alloc_size = (image_size + 4095) & !4095;
    let layout = match core::alloc::Layout::from_size_align(alloc_size, 4096) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };

    let mapped = alloc::alloc::alloc_zeroed(layout);
    if mapped.is_null() {
        return STATUS_NO_MEMORY;
    }

    // Copy headers
    let header_size = nt.optional_header.size_of_headers as usize;
    core::ptr::copy_nonoverlapping(
        full_path as *const u8,
        mapped,
        header_size,
    );

    // Copy sections
    let section_offset = nt_offset + mem::size_of::<ImageNtHeaders64>()
        - IMAGE_NUMBEROF_DIRECTORY_ENTRIES * mem::size_of::<ImageDataDirectory>()
        + nt.optional_header.number_of_rva_and_sizes as usize * mem::size_of::<ImageDataDirectory>();

    let sections = (full_path as usize + section_offset) as *const ImageSectionHeader;
    let num_sections = nt.file_header.number_of_sections as usize;

    for i in 0..num_sections {
        let sec = &*sections.add(i);
        if sec.size_of_raw_data > 0 && sec.pointer_to_raw_data > 0 {
            let src = (full_path as usize + sec.pointer_to_raw_data as usize) as *const u8;
            let dst = mapped.add(sec.virtual_address as usize);
            let copy_size = sec.size_of_raw_data as usize;

            core::ptr::copy_nonoverlapping(src, dst, copy_size);
        }
    }

    *dll_base = mapped as Pvoid;

    STATUS_SUCCESS
}

// ============================================================
// LoadAsImage - High-level image loading
// ============================================================

pub unsafe fn load_as_image(
    full_path: *const u16,
    path_length: u16,
) -> NtStatus {
    let mut dll_base: Pvoid = core::ptr::null_mut();

    let status = ldrp_load_image(full_path, path_length, &mut dll_base);
    if status != STATUS_SUCCESS {
        return status;
    }

    // Process relocations if the image base doesn't match
    let nt_header = rtl_image_nt_header(dll_base);
    if !nt_header.is_null() {
        let nt = &*nt_header;
        let preferred_base = nt.optional_header.image_base;

        if preferred_base != dll_base as u64 {
            let reloc_status = ldrp_do_relocations(
                dll_base,
                preferred_base,
                dll_base as u64,
            );
            if reloc_status != STATUS_SUCCESS {
                ldr_warn!("LdrpLoadImage: relocation failed");
            }
        }

        // Snap imports
        let mut import_desc: ImageImportDescriptor = mem::zeroed();
        let mut import_rva: u32 = 0;
        let mut thunk_rva: u32 = 0;
        let mut bound_rva: u32 = 0;

        let snap_status = ldrp_get_thunk_information(
            dll_base,
            &mut import_desc,
            &mut import_rva,
            &mut thunk_rva,
            &mut bound_rva,
        );

        if snap_status == STATUS_SUCCESS {
            let entry_point = if nt.optional_header.address_of_entry_point != 0 {
                (dll_base as usize + nt.optional_header.address_of_entry_point as usize) as Pvoid
            } else {
                core::ptr::null_mut()
            };
            let entry = ldr_create_data_table_entry(
                dll_base,
                entry_point,
                nt.optional_header.size_of_image,
                full_path,
                path_length,
            );

            if !entry.is_null() {
                ldr_trace!("LoadAsImage: module loaded at {:p}", dll_base);
            }
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// LdrCreateDataTableEntry
// ============================================================

pub unsafe fn ldr_create_data_table_entry(
    dll_base: Pvoid,
    entry_point: Pvoid,
    size_of_image: u32,
    full_dll_name: *const u16,
    name_length: u16,
) -> *mut LdrDataTableEntry {
    let peb = crate::ps::ps_get_current_peb();
    if peb.is_null() || (*peb).ldr.is_null() {
        return core::ptr::null_mut();
    }

    let ldr = &mut *(*peb).ldr;

    // Allocate new entry
    let entry_size = mem::size_of::<LdrDataTableEntry>();
    let layout = match core::alloc::Layout::from_size_align(entry_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };

    let entry_ptr = alloc::alloc::alloc_zeroed(layout) as *mut LdrDataTableEntry;
    if entry_ptr.is_null() {
        return core::ptr::null_mut();
    }

    let entry = &mut *entry_ptr;
    entry.dll_base = dll_base;
    entry.entry_point = entry_point;
    entry.size_of_image = size_of_image;
    entry.load_count = 1;
    entry.flags = LDRP_IMAGE_DLL | LDRP_LOAD_IN_PROGRESS;
    entry.lock = 0;

    // Set up full DLL name
    if !full_dll_name.is_null() && name_length > 0 {
        let name_buf_size = (name_length as usize + 1) * 2;
        let name_layout = match core::alloc::Layout::from_size_align(name_buf_size, 2) {
            Ok(l) => l,
            Err(_) => {
                alloc::alloc::dealloc(entry_ptr as *mut u8, layout);
                return core::ptr::null_mut();
            }
        };
        let name_buf = alloc::alloc::alloc_zeroed(name_layout) as *mut u16;
        if !name_buf.is_null() {
            core::ptr::copy_nonoverlapping(
                full_dll_name,
                name_buf,
                name_length as usize,
            );
            entry.full_dll_name.length = name_length;
            entry.full_dll_name.maximum_length = name_length + 2;
            entry.full_dll_name.buffer = name_buf;

            // Extract base name (after last backslash)
            let mut last_sep = 0usize;
            for i in 0..name_length as usize {
                if *name_buf.add(i) == '\\' as u16 || *name_buf.add(i) == '/' as u16 {
                    last_sep = i + 1;
                }
            }

            if last_sep > 0 {
                let base_name_len = name_length as usize - last_sep;
                let base_name_layout = match core::alloc::Layout::from_size_align(
                    (base_name_len + 1) * 2, 2
                ) {
                    Ok(l) => l,
                    Err(_) => {
                        alloc::alloc::dealloc(name_buf as *mut u8, name_layout);
                        alloc::alloc::dealloc(entry_ptr as *mut u8, layout);
                        return core::ptr::null_mut();
                    }
                };
                let base_name = alloc::alloc::alloc_zeroed(base_name_layout) as *mut u16;
                if !base_name.is_null() {
                    core::ptr::copy_nonoverlapping(
                        name_buf.add(last_sep),
                        base_name,
                        base_name_len,
                    );
                    entry.base_dll_name.length = (base_name_len * 2) as u16;
                    entry.base_dll_name.maximum_length = ((base_name_len + 1) * 2) as u16;
                    entry.base_dll_name.buffer = base_name;
                }
            }
        }
    }

    // Insert into load order list
    ldr.in_load_order_module_list.insert_tail(&mut entry.in_load_order_links);
    ldr.in_memory_order_module_list.insert_tail(&mut entry.in_memory_order_links);
    ldr.in_initialization_order_module_list.insert_tail(&mut entry.in_initialization_order_links);

    entry.flags |= LDRP_ENTRY_INSERTED;
    LDR_MODULE_COUNT.fetch_add(1, Ordering::Relaxed);

    ldr_trace!("LdrCreateDataTableEntry: {:?} at {:p}", &entry.base_dll_name, dll_base);

    entry_ptr
}

// ============================================================
// LdrFindModuleByName
// ============================================================

pub unsafe fn ldr_find_module_by_name(name: *const u8) -> *mut LdrDataTableEntry {
    if name.is_null() {
        return core::ptr::null_mut();
    }

    let peb = crate::ps::ps_get_current_peb();
    if peb.is_null() || (*peb).ldr.is_null() {
        return core::ptr::null_mut();
    }

    let ldr = &*(*peb).ldr;
    let mut current = ldr.in_load_order_module_list.flink;

    while current != &ldr.in_load_order_module_list as *const ListEntry as *mut ListEntry {
        let entry = ldr_get_entry_from_load_order(current);

        if !entry.is_null() && !(*entry).base_dll_name.buffer.is_null() {
            let base_name = &(*entry).base_dll_name;
            let name_chars = core::slice::from_raw_parts(
                base_name.buffer,
                base_name.length as usize / 2,
            );

            // Compare names case-insensitively
            let mut is_match = true;
            let mut i = 0;
            let mut j = 0;

            while i < name_chars.len() && j < 256 {
                let target_ch = *name.add(j);
                if target_ch == 0 {
                    break;
                }

                let mut src_ch = name_chars[i] as u8;
                let mut tgt_ch = target_ch;

                // Uppercase for comparison
                if src_ch >= b'a' && src_ch <= b'z' {
                    src_ch -= 32;
                }
                if tgt_ch >= b'a' && tgt_ch <= b'z' {
                    tgt_ch -= 32;
                }

                if src_ch != tgt_ch {
                    is_match = false;
                    break;
                }

                i += 1;
                j += 1;
            }

            if is_match && i == name_chars.len() {
                // Also check if we consumed all of the target name
                if j < 256 && *name.add(j) != 0 {
                    // Target name is longer than module name
                } else {
                    return entry;
                }
            }
        }

        current = (*current).flink;
    }

    core::ptr::null_mut()
}

// ============================================================
// LdrFindModuleByUnicodeName
// ============================================================

pub unsafe fn ldr_find_module_by_unicode_name(
    name: *const UnicodeString,
) -> *mut LdrDataTableEntry {
    if name.is_null() || (*name).buffer.is_null() || (*name).length == 0 {
        return core::ptr::null_mut();
    }

    let peb = crate::ps::ps_get_current_peb();
    if peb.is_null() || (*peb).ldr.is_null() {
        return core::ptr::null_mut();
    }

    let ldr = &*(*peb).ldr;
    let mut current = ldr.in_load_order_module_list.flink;

    while current != &ldr.in_load_order_module_list as *const ListEntry as *mut ListEntry {
        let entry = ldr_get_entry_from_load_order(current);

        if !entry.is_null() && !(*entry).base_dll_name.buffer.is_null() {
            if rtl_compare_unicode_string(
                name,
                &(*entry).base_dll_name,
                1, // case insensitive
            ) == 0
            {
                return entry;
            }
        }

        current = (*current).flink;
    }

    core::ptr::null_mut()
}

// ============================================================
// LdrGetProcedureAddress
// ============================================================

pub unsafe fn ldr_get_procedure_address(
    dll_base: Pvoid,
    name: *const u8,
    ordinal: u16,
    procedure_address: *mut Pvoid,
) -> NtStatus {
    if dll_base.is_null() || procedure_address.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let nt_header = rtl_image_nt_header(dll_base);
    if nt_header.is_null() {
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let nt = &*nt_header;

    if nt.optional_header.number_of_rva_and_sizes as usize <= IMAGE_DIRECTORY_ENTRY_EXPORT {
        return STATUS_INVALID_PARAMETER;
    }

    let export_dir = &nt.optional_header.data_directory[IMAGE_DIRECTORY_ENTRY_EXPORT];
    if export_dir.virtual_address == 0 {
        *procedure_address = core::ptr::null_mut();
        return STATUS_ENTRYPOINT_NOT_FOUND;
    }

    let exports = (dll_base as usize + export_dir.virtual_address as usize)
        as *const ImageExportDirectory;

    let functions = dll_base as usize + (*exports).address_of_functions as usize;
    let names = dll_base as usize + (*exports).address_of_names as usize;
    let ordinals = dll_base as usize + (*exports).address_of_name_ordinals as usize;

    let num_functions = (*exports).number_of_functions as usize;
    let num_names = (*exports).number_of_names as usize;

    if !name.is_null() {
        // Search by name
        for i in 0..num_names {
            let name_rva = *((names + i * 4) as *const u32);
            let export_name = (dll_base as usize + name_rva as usize) as *const u8;

            // Compare names
            let mut is_match = true;
            let mut j = 0;
            loop {
                let target = *name.add(j);
                let actual = *export_name.add(j);

                if target == 0 && actual == 0 {
                    break;
                }
                if target != actual {
                    is_match = false;
                    break;
                }
                j += 1;
                if j > 256 {
                    is_match = false;
                    break;
                }
            }

            if is_match {
                let ordinal_idx = *((ordinals + i * 2) as *const u16) as usize;
                let func_rva = *((functions + ordinal_idx * 4) as *const u32);
                let func_addr = (dll_base as usize + func_rva as usize) as Pvoid;

                *procedure_address = func_addr;
                ldr_trace!("LdrGetProcedureAddress: {:?} -> {:p}", name, func_addr);
                return STATUS_SUCCESS;
            }
        }
    } else {
        // Search by ordinal
        let ordinal_idx = ordinal as usize;
        if ordinal_idx < (*exports).base as usize
            || ordinal_idx >= (*exports).base as usize + num_functions
        {
            *procedure_address = core::ptr::null_mut();
            return STATUS_ORDINAL_NOT_FOUND;
        }

        let func_rva = *((functions + (ordinal_idx - (*exports).base as usize) * 4) as *const u32);
        let func_addr = (dll_base as usize + func_rva as usize) as Pvoid;

        *procedure_address = func_addr;
        ldr_trace!("LdrGetProcedureAddress: ordinal={} -> {:p}", ordinal, func_addr);
        return STATUS_SUCCESS;
    }

    *procedure_address = core::ptr::null_mut();
    STATUS_ENTRYPOINT_NOT_FOUND
}

// ============================================================
// LdrGetProcedureAddressByOrdinal (helper)
// ============================================================

pub unsafe fn ldr_get_procedure_address_by_ordinal(
    dll_base: Pvoid,
    ordinal: u16,
) -> Pvoid {
    let mut addr: Pvoid = core::ptr::null_mut();
    let status = ldr_get_procedure_address(dll_base, core::ptr::null(), ordinal, &mut addr);
    if status == STATUS_SUCCESS {
        addr
    } else {
        core::ptr::null_mut()
    }
}

// ============================================================
// LdrGetProcedureAddressByName (helper)
// ============================================================

pub unsafe fn ldr_get_procedure_address_by_name(
    dll_base: Pvoid,
    name: *const u8,
) -> Pvoid {
    let mut addr: Pvoid = core::ptr::null_mut();
    let status = ldr_get_procedure_address(dll_base, name, 0, &mut addr);
    if status == STATUS_SUCCESS {
        addr
    } else {
        core::ptr::null_mut()
    }
}

// ============================================================
// LdrLoadDll
// ============================================================

pub unsafe fn ldr_load_dll(
    path: *const UnicodeString,
    dll_characteristics: *mut u32,
    dll_name: *const UnicodeString,
    dll_handle: *mut Pvoid,
) -> NtStatus {
    if dll_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    *dll_handle = core::ptr::null_mut();

    let name = if !dll_name.is_null() { dll_name } else { path };

    if name.is_null() || (*name).buffer.is_null() || (*name).length == 0 {
        return STATUS_INVALID_PARAMETER;
    }

    ldr_trace!("LdrLoadDll: loading {:?}", &*name);

    // Check if already loaded
    let existing = ldr_find_module_by_unicode_name(name);
    if !existing.is_null() {
        ldr_trace!("LdrLoadDll: already loaded");
        *dll_handle = (*existing).dll_base;
        return STATUS_SUCCESS;
    }

    // Try to load the image
    let status = load_as_image((*name).buffer, (*name).length);
    if status != STATUS_SUCCESS {
        ldr_err!("LdrLoadDll: failed to load image");
        return status;
    }

    // Find the newly loaded entry
    let entry = ldr_find_module_by_unicode_name(name);
    if !entry.is_null() {
        // Snap imports
        let snap_status = ldrp_snap_import_module(entry);
        if snap_status != STATUS_SUCCESS {
            ldr_warn!("LdrLoadDll: import snapping failed");
        }

        (*entry).flags &= !LDRP_LOAD_IN_PROGRESS;

        // Call DllMain
        if !(*entry).entry_point.is_null() {
            let dll_main: unsafe extern "system" fn(Pvoid, u32, Pvoid) -> u32 =
                mem::transmute((*entry).entry_point);
            let result = dll_main((*entry).dll_base, LDRP_DLL_PROCESS_ATTACH, core::ptr::null_mut());
            if result == 0 {
                ldr_warn!("LdrLoadDll: DllMain returned FALSE");
            }
        }

        *dll_handle = (*entry).dll_base;
    } else {
        return STATUS_DLL_NOT_FOUND;
    }

    if !dll_characteristics.is_null() {
        *dll_characteristics = 0;
    }

    STATUS_SUCCESS
}

// ============================================================
// LdrUnloadDll
// ============================================================

pub unsafe fn ldr_unload_dll(dll_handle: Pvoid) -> NtStatus {
    if dll_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    ldr_trace!("LdrUnloadDll: {:p}", dll_handle);

    let entry = ldrp_data_table_entry(dll_handle);
    if entry.is_null() {
        return STATUS_DLL_NOT_FOUND;
    }

    let entry_ref = &mut *entry;

    // Call DllMain with DLL_PROCESS_DETACH
    if !entry_ref.entry_point.is_null() && entry_ref.flags & LDRP_PROCESS_ATTACH_CALLED != 0 {
        let dll_main: unsafe extern "system" fn(Pvoid, u32, Pvoid) -> u32 =
            mem::transmute(entry_ref.entry_point);
        dll_main(entry_ref.dll_base, LDRP_DLL_PROCESS_DETACH, core::ptr::null_mut());
    }

    // Remove from lists
    entry_ref.flags |= LDRP_UNLOAD_IN_PROGRESS;

    entry_ref.in_load_order_links.remove();
    entry_ref.in_memory_order_links.remove();
    entry_ref.in_initialization_order_links.remove();

    entry_ref.flags &= !LDRP_ENTRY_INSERTED;
    LDR_MODULE_COUNT.fetch_sub(1, Ordering::Relaxed);

    // Free name strings
    if !entry_ref.full_dll_name.buffer.is_null() {
        let name_size = (entry_ref.full_dll_name.maximum_length as usize);
        if name_size > 0 {
            let layout = core::alloc::Layout::from_size_align(name_size as usize, 2).unwrap();
            alloc::alloc::dealloc(entry_ref.full_dll_name.buffer as *mut u8, layout);
        }
    }

    if !entry_ref.base_dll_name.buffer.is_null() {
        let name_size = entry_ref.base_dll_name.maximum_length as usize;
        if name_size > 0 {
            let layout = core::alloc::Layout::from_size_align(name_size, 2).unwrap();
            alloc::alloc::dealloc(entry_ref.base_dll_name.buffer as *mut u8, layout);
        }
    }

    // Free the entry
    let entry_layout = core::alloc::Layout::from_size_align(
        mem::size_of::<LdrDataTableEntry>(), 16
    ).unwrap();
    alloc::alloc::dealloc(entry as *mut u8, entry_layout);

    STATUS_SUCCESS
}

// ============================================================
// LdrGetDllHandle
// ============================================================

pub unsafe fn ldr_get_dll_handle(
    path: *const UnicodeString,
    dll_characteristics: *mut u32,
    dll_name: *const UnicodeString,
    dll_handle: *mut Pvoid,
) -> NtStatus {
    if dll_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    *dll_handle = core::ptr::null_mut();

    let name = if !dll_name.is_null() { dll_name } else { path };
    if name.is_null() || (*name).buffer.is_null() || (*name).length == 0 {
        return STATUS_INVALID_PARAMETER;
    }

    let entry = ldr_find_module_by_unicode_name(name);
    if entry.is_null() {
        return STATUS_DLL_NOT_FOUND;
    }

    *dll_handle = (*entry).dll_base;

    if !dll_characteristics.is_null() {
        *dll_characteristics = 0;
    }

    STATUS_SUCCESS
}

// ============================================================
// LdrGetProcedureAddressEx (stub)
// ============================================================

pub unsafe fn ldr_get_procedure_address_ex(
    dll_handle: Pvoid,
    name: *const UnicodeString,
    ordinal: u16,
    procedure_address: *mut Pvoid,
    flags: u32,
) -> NtStatus {
    let _ = flags;

    if name.is_null() || (*name).buffer.is_null() {
        return ldr_get_procedure_address(dll_handle, core::ptr::null(), ordinal, procedure_address);
    }

    // Convert unicode name to ansi for comparison
    let mut ansi_name = [0u8; 256];
    let name_chars = core::slice::from_raw_parts(
        (*name).buffer,
        (*name).length as usize / 2,
    );

    let mut i = 0;
    while i < name_chars.len() && i < 255 {
        ansi_name[i] = if name_chars[i] > 0x7f { b'?' } else { name_chars[i] as u8 };
        i += 1;
    }
    ansi_name[i] = 0;

    ldr_get_procedure_address(dll_handle, ansi_name.as_ptr(), 0, procedure_address)
}

// ============================================================
// LdrpInitialize (loader initialization)
// ============================================================

pub unsafe fn ldrp_initialize() -> NtStatus {
    if LDR_INITIALIZED.load(Ordering::Acquire) {
        return STATUS_SUCCESS;
    }

    ldr_dbg!("LdrpInitialize: initializing loader");

    // Initialize loader lock
    LDR_ORDER_INDEX = 0;

    LDR_INITIALIZED.store(true, Ordering::Release);

    STATUS_SUCCESS
}

// ============================================================
// LdrpGetThunkInformation (extended)
// ============================================================

pub unsafe fn ldrp_getThunkInformation_extended(
    dll_base: Pvoid,
) -> NtStatus {
    if dll_base.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let nt_header = rtl_image_nt_header(dll_base);
    if nt_header.is_null() {
        return STATUS_INVALID_IMAGE_FORMAT;
    }

    let nt = &*nt_header;

    if nt.optional_header.number_of_rva_and_sizes as usize <= IMAGE_DIRECTORY_ENTRY_IMPORT {
        return STATUS_SUCCESS;
    }

    let import_dir = &nt.optional_header.data_directory[IMAGE_DIRECTORY_ENTRY_IMPORT];
    if import_dir.virtual_address == 0 {
        return STATUS_SUCCESS;
    }

    ldr_trace!("LdrpGetThunkInformation: imports at RVA {:#x}", import_dir.virtual_address);

    STATUS_SUCCESS
}

// ============================================================
// RTL_PROCESS_MODULE_INFORMATION
// ============================================================

#[repr(C)]
pub struct RtlProcessModuleInformation {
    pub mapped_base: Pvoid,
    pub image_base: Pvoid,
    pub image_size: u64,
    pub flags: u32,
    pub load_order_index: u16,
    pub implicit_links: u16,
    pub load_count: u16,
    pub offset_to_file_name: u16,
    pub full_path_name: [u8; 256],
}

// ============================================================
// LdrQueryModuleInformation (for NtQuerySystemInformation)
// ============================================================

pub unsafe fn ldr_query_module_information(
    buffer: Pvoid,
    buffer_length: u32,
    required_length: *mut u32,
) -> NtStatus {
    // In Windows, this returns information about loaded modules.
    // In VladOS, we traverse the PEB->Ldr lists.
    let peb = crate::ps::ps_get_current_peb();
    if peb.is_null() || (*peb).ldr.is_null() {
        if !required_length.is_null() {
            *required_length = 0;
        }
        return STATUS_SUCCESS;
    }

    let ldr = &*(*peb).ldr;

    // Count modules in InLoadOrderModuleList
    let mut count: u32 = 0;
    let mut current = ldr.in_load_order_module_list.flink;
    while current != &ldr.in_load_order_module_list as *const ListEntry as *mut ListEntry {
        count += 1;
        current = (*current).flink;
    }

    // Each module entry: RTL_PROCESS_MODULE_INFORMATION (272 bytes on x64)
    let entry_size: u32 = 272; // sizeof(RTL_PROCESS_MODULE_INFORMATION)
    let total_size = core::mem::size_of::<u32>() as u32 + count * entry_size;

    if !required_length.is_null() {
        *required_length = total_size;
    }

    if buffer.is_null() || buffer_length < total_size {
        return STATUS_INFO_LENGTH_MISMATCH;
    }

    // Write NumberOfModules
    *(buffer as *mut u32) = count;

    // Write module entries
    let entries_ptr = (buffer as *mut u8).add(core::mem::size_of::<u32>()) as *mut u8;
    let mut offset: usize = 0;
    current = ldr.in_load_order_module_list.flink;
    while current != &ldr.in_load_order_module_list as *const ListEntry as *mut ListEntry {
        let entry = ldr_get_entry_from_load_order(current);
        if !entry.is_null() {
            let info = entries_ptr.add(offset) as *mut RtlProcessModuleInformation;
            (*info).mapped_base = (*entry).dll_base;
            (*info).image_base = (*entry).dll_base;
            (*info).image_size = (*entry).size_of_image as u64;
            (*info).flags = (*entry).flags;
            (*info).load_order_index = 0; // not tracked in simplified model
            (*info).implicit_links = 0; // not tracked in simplified model
            (*info).load_count = (*entry).load_count as u16;
            (*info).offset_to_file_name = 0;

            // Copy the base DLL name (ANSI)
            let name = &(*entry).base_dll_name;
            if !name.buffer.is_null() && name.length > 0 {
                let name_chars = core::slice::from_raw_parts(name.buffer, name.length as usize / 2);
                let mut i = 0;
                while i < name_chars.len() && i < 255 {
                    (*info).full_path_name[i] = if name_chars[i] > 0x7f { b'?' } else { name_chars[i] as u8 };
                    i += 1;
                }
                (*info).full_path_name[i] = 0;
            } else {
                (*info).full_path_name[0] = 0;
            }

            offset += entry_size as usize;
        }
        current = (*current).flink;
    }

    STATUS_SUCCESS
}

// ============================================================
// LdrpRunInitRoutines (call DllMain for all loaded modules)
// ============================================================

pub unsafe fn ldrp_run_init_routines() -> NtStatus {
    let peb = crate::ps::ps_get_current_peb();
    if peb.is_null() || (*peb).ldr.is_null() {
        return STATUS_SUCCESS;
    }

    let ldr = &*(*peb).ldr;
    let mut current = ldr.in_initialization_order_module_list.flink;

    while current != &ldr.in_initialization_order_module_list as *const ListEntry as *mut ListEntry {
        let entry = ldr_get_entry_from_init_order(current);

        if !entry.is_null()
            && !(*entry).entry_point.is_null()
            && (*entry).flags & LDRP_PROCESS_ATTACH_CALLED == 0
            && (*entry).flags & LDRP_DONT_CALL_FOR_THREADS == 0
        {
            let dll_main: unsafe extern "system" fn(Pvoid, u32, Pvoid) -> u32 =
                mem::transmute((*entry).entry_point);

            (*entry).flags |= LDRP_PROCESS_ATTACH_CALLED;

            let result = dll_main(
                (*entry).dll_base,
                LDRP_DLL_PROCESS_ATTACH,
                core::ptr::null_mut(),
            );

            if result == 0 {
                ldr_warn!("LdrpRunInitRoutines: DllMain returned FALSE for {:?}",
                          &(*entry).base_dll_name);
            } else {
                ldr_trace!("LdrpRunInitRoutines: initialized {:?}",
                           &(*entry).base_dll_name);
            }
        }

        current = (*current).flink;
    }

    STATUS_SUCCESS
}
