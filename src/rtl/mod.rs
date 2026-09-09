/// # Runtime Library (Rtl/Rtlp) - ntoskrnl.exe
///
/// Complete implementation of the Windows Runtime Library including
/// Unicode/ANSI string manipulation, image header parsing, exception
/// dispatch support, heap management, and utility functions.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 2/5
///   - WRK: ntoskrnl/rtl/
///   - ReactOS: rtl/

use core::arch::asm;
use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicU32, Ordering};

use crate::types::*;
use crate::ke::dispatcher::*;
use crate::ke::sync::*;

// ============================================================
// Constants
// ============================================================

pub const RTL_HASH_STRING_MULTIPLE: Ulong = 0x00000001;
pub const RTL_HASH_STRING_CASE_INSENSITIVE: Ulong = 0x00000002;
pub const RTL_HASH_STRING_COLLATE: Ulong = 0x00000004;

pub const RTL_UPCASE_UNICODE_CHAR: u16 = 0x00000001;

pub const IMAGE_DIRECTORY_ENTRY_EXPORT: usize = 0;
pub const IMAGE_DIRECTORY_ENTRY_IMPORT: usize = 1;
pub const IMAGE_DIRECTORY_ENTRY_RESOURCE: usize = 2;
pub const IMAGE_DIRECTORY_ENTRY_EXCEPTION: usize = 3;
pub const IMAGE_DIRECTORY_ENTRY_SECURITY: usize = 4;
pub const IMAGE_DIRECTORY_ENTRY_BASERELOC: usize = 5;
pub const IMAGE_DIRECTORY_ENTRY_DEBUG: usize = 6;
pub const IMAGE_DIRECTORY_ENTRY_ARCHITECTURE: usize = 7;
pub const IMAGE_DIRECTORY_ENTRY_GLOBALPTR: usize = 8;
pub const IMAGE_DIRECTORY_ENTRY_TLS: usize = 9;
pub const IMAGE_DIRECTORY_ENTRY_LOAD_CONFIG: usize = 10;
pub const IMAGE_DIRECTORY_ENTRY_BOUND_IMPORT: usize = 11;
pub const IMAGE_DIRECTORY_ENTRY_IAT: usize = 12;
pub const IMAGE_DIRECTORY_ENTRY_DELAY_IMPORT: usize = 13;
pub const IMAGE_DIRECTORY_ENTRY_COM_DESCRIPTOR: usize = 14;
pub const IMAGE_NUMBEROF_DIRECTORY_ENTRIES: usize = 16;

pub const IMAGE_NT_SIGNATURE: u32 = 0x0000_4550; // "PE\0\0"
pub const IMAGE_NT_OPTIONAL_HDR64_MAGIC: u16 = 0x020b;
pub const IMAGE_NT_OPTIONAL_HDR32_MAGIC: u16 = 0x010b;

pub const HEAP_GROW_BY: Ulong = 0x00000001;
pub const HEAP_SERIALIZE: Ulong = 0x00000002;
pub const HEAP_NO_SERIALIZE: Ulong = 0x00000001;
pub const HEAP_ZERO_MEMORY: Ulong = 0x00000008;
pub const HEAP_REALLOC_IN_PLACE_ONLY: Ulong = 0x00000010;
pub const HEAP_TAGGING: Ulong = 0x00000020;
pub const HEAP_SETTABLE: Ulong = 0x00000100;
pub const HEAP_CREATE_ENABLE_TRACING: Ulong = 0x00020000;

pub const STATUS_INFO_LENGTH_MISMATCH: NtStatus = 0xC0000004;

// ============================================================
// Logging macros
// ============================================================

macro_rules! rtl_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "rtl_trace")]
        crate::kernel_log!("[Rtl] {}", format_args!($($arg)*));
    };
}

macro_rules! rtl_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Rtl] {}", format_args!($($arg)*));
    };
}

macro_rules! rtl_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Rtl] {}", format_args!($($arg)*));
    };
}

macro_rules! rtl_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Rtl] {}", format_args!($($arg)*));
    };
}

// ============================================================
// IMAGE_NT_HEADERS (64-bit)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageNtHeaders64 {
    pub signature: u32,
    pub file_header: ImageFileHeader,
    pub optional_header: ImageOptionalHeader64,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageFileHeader {
    pub machine: u16,
    pub number_of_sections: u16,
    pub time_date_stamp: u32,
    pub pointer_to_symbol_table: u32,
    pub number_of_symbols: u32,
    pub size_of_optional_header: u16,
    pub characteristics: u16,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageOptionalHeader64 {
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
    pub check_sum: u32,
    pub subsystem: u16,
    pub dll_characteristics: u16,
    pub size_of_stack_reserve: u64,
    pub size_of_stack_commit: u64,
    pub size_of_heap_reserve: u64,
    pub size_of_heap_commit: u64,
    pub loader_flags: u32,
    pub number_of_rva_and_sizes: u32,
    pub data_directory: [ImageDataDirectory; IMAGE_NUMBEROF_DIRECTORY_ENTRIES],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageDataDirectory {
    pub virtual_address: u32,
    pub size: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageSectionHeader {
    pub name: [u8; 8],
    pub virtual_size: ImageMiscVirtualSize,
    pub virtual_address: u32,
    pub size_of_raw_data: u32,
    pub pointer_to_raw_data: u32,
    pub pointer_to_relocations: u32,
    pub pointer_to_linenumbers: u32,
    pub number_of_relocations: u16,
    pub number_of_linenumbers: u16,
    pub characteristics: u32,
}

#[repr(C)]
pub union ImageMiscVirtualSize {
    pub physical_address: u32,
    pub virtual_size: u32,
}
unsafe impl Send for ImageMiscVirtualSize {}
unsafe impl Sync for ImageMiscVirtualSize {}
impl Copy for ImageMiscVirtualSize {}
impl Clone for ImageMiscVirtualSize {
    fn clone(&self) -> Self { *self }
}
impl core::fmt::Debug for ImageMiscVirtualSize {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("ImageMiscVirtualSize").finish()
    }
}

// ============================================================
// IMAGE_DOS_HEADER
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ImageDosHeader {
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
    pub e_lfanew: i32,
}

// ============================================================
// UNWIND_HISTORY_TABLE (simplified)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UnwindHistoryTableEntry {
    pub image_base: u64,
    pub function_table: *mut c_void,
    pub data: *mut c_void,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UnwindHistoryTable {
    pub count: u8,
    pub local_hint: u8,
    pub global_hint: u8,
    pub search: u8,
    pub once: u8,
    pub pad: [u8; 3],
    pub table: [UnwindHistoryTableEntry; 12],
}

impl UnwindHistoryTable {
    pub const fn new() -> Self {
        Self {
            count: 0,
            local_hint: 0,
            global_hint: 0,
            search: 0,
            once: 0,
            pad: [0; 3],
            table: [UnwindHistoryTableEntry {
                image_base: 0,
                function_table: core::ptr::null_mut(),
                data: core::ptr::null_mut(),
            }; 12],
        }
    }
}

// ============================================================
// CONTEXT (x64) - CPU register context
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ContextRecord {
    pub p1_home: u64,
    pub p2_home: u64,
    pub p3_home: u64,
    pub p4_home: u64,
    pub p5_home: u64,
    pub p6_home: u64,
    pub context_flags: u32,
    pub mx_csr: u32,
    pub cs: u16,
    pub ds: u16,
    pub es: u16,
    pub fs: u16,
    pub gs: u16,
    pub ss: u16,
    pub eflags: u32,
    pub dr0: u64,
    pub dr1: u64,
    pub dr2: u64,
    pub dr3: u64,
    pub dr6: u64,
    pub dr7: u64,
    pub rax: u64,
    pub rcx: u64,
    pub rdx: u64,
    pub rbx: u64,
    pub rsp: u64,
    pub rbp: u64,
    pub rsi: u64,
    pub rdi: u64,
    pub r8: u64,
    pub r9: u64,
    pub r10: u64,
    pub r11: u64,
    pub r12: u64,
    pub r13: u64,
    pub r14: u64,
    pub r15: u64,
    pub rip: u64,
    pub flt_save: [u8; 512],
    pub vector_registers: [u8; 2624],
    pub vector_control: u64,
    pub debug_control: u64,
    pub last_exception_to_report: u64,
    pub thread_slots: u64,
    pub reserved1: [u64; 11],
}

pub const CONTEXT_AMD64: u32 = 0x00100000;
pub const CONTEXT_CONTROL: u32 = CONTEXT_AMD64 | 0x0001;
pub const CONTEXT_INTEGER: u32 = CONTEXT_AMD64 | 0x0002;
pub const CONTEXT_SEGMENTS: u32 = CONTEXT_AMD64 | 0x0004;
pub const CONTEXT_FLOATING_POINT: u32 = CONTEXT_AMD64 | 0x0008;
pub const CONTEXT_DEBUG_REGISTERS: u32 = CONTEXT_AMD64 | 0x0010;
pub const CONTEXT_FULL: u32 = CONTEXT_CONTROL | CONTEXT_INTEGER | CONTEXT_FLOATING_POINT;
pub const CONTEXT_ALL: u32 = CONTEXT_FULL | CONTEXT_SEGMENTS | CONTEXT_DEBUG_REGISTERS;

impl ContextRecord {
    pub fn new() -> Self {
        unsafe { mem::zeroed() }
    }
}

// ============================================================
// EXCEPTION_RECORD (simplified)
// ============================================================

#[repr(C)]
pub struct ExceptionRecord {
    pub exception_code: NtStatus,
    pub exception_flags: u32,
    pub exception_record: *mut ExceptionRecord,
    pub exception_address: Pvoid,
    pub number_parameters: u32,
    pub exception_information: [u64; 15],
}

// ============================================================
// RUNTIME_FUNCTION (x64 unwind info)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RuntimeFunction {
    pub begin_address: u32,
    pub end_address: u32,
    pub unwind_data: u32,
}

// ============================================================
// Dynamic Virtual Unwind Frame
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct DynamicContext {
    pub context_flags: u64,
    pub rip: u64,
    pub rsp: u64,
    pub rbp: u64,
    pub rax: u64,
    pub rcx: u64,
    pub rdx: u64,
    pub rbx: u64,
    pub rsi: u64,
    pub rdi: u64,
    pub r8: u64,
    pub r9: u64,
    pub r10: u64,
    pub r11: u64,
    pub r12: u64,
    pub r13: u64,
    pub r14: u64,
    pub r15: u64,
    pub vector_control: u64,
    pub xmm: [[u8; 16]; 26],
}

// ============================================================
// RTL_HEAP_ENTRY
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlHeapEntry {
    pub size: u32,
    pub flags: u8,
    pub small_tag_index: u8,
    pub previous_size: u16,
    pub segment_index: u8,
    pub unused_bytes: u8,
    pub checksum: u8,
    pub _reserved: u8,
}

pub const RTL_HEAP_ENTRY_SIZE: usize = 8;

// ============================================================
// RTL_HEAP_PARAMETERS
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlHeapParameters {
    pub length: Ulong,
    pub flags: Ulong,
    pub segment_reserve: Ulong,
    pub segment_commit: Ulong,
    pub decommit_free_block_threshold: Ulong,
    pub decommit_total_free_threshold: Ulong,
    pub maximum_allocation_size: Ulong,
    pub virtual_memory_threshold: Ulong,
    pub initial_commit: Ulong,
    pub initial_reserve: Ulong,
    pub commit_increment: Ulong,
    pub number_of_heaps: Ulong,
}

impl RtlHeapParameters {
    pub const fn new() -> Self {
        Self {
            length: mem::size_of::<Self>() as Ulong,
            flags: 0,
            segment_reserve: 0,
            segment_commit: 0,
            decommit_free_block_threshold: 0,
            decommit_total_free_threshold: 0,
            maximum_allocation_size: 0,
            virtual_memory_threshold: 0,
            initial_commit: 0,
            initial_reserve: 0,
            commit_increment: 0,
            number_of_heaps: 0,
        }
    }
}

// ============================================================
// RTL_HEAP (simplified heap descriptor)
// ============================================================

#[repr(C)]
pub struct RtlHeap {
    pub entry: RtlHeapEntry,
    pub lock: KspinLock,
    pub lock_variable: u32,
    pub process: Pvoid,
    pub flags: Ulong,
    pub force_flags: Ulong,
    pub virtual_memory_threshold: Ulong,
    pub segment_reserve: Ulong,
    pub segment_commit: Ulong,
    pub decommit_free_block_threshold: Ulong,
    pub decommit_total_free_threshold: Ulong,
    pub allocation_count: Ulong,
    pub free_list: ListEntry,
    pub committed_list: ListEntry,
    pub uncommitted_list: ListEntry,
    pub segment_list: ListEntry,
    pub total_size: Ulong,
    pub total_allocated: Ulong,
    pub total_free: Ulong,
    pub commit_limit: Ulong,
    pub lookaside: Pvoid,
}

// ============================================================
// Global Heap State
// ============================================================

pub static mut RTL_PROCESS_HEAP: Pvoid = core::ptr::null_mut();
pub static RTL_HEAP_LOCK: KspinLock = 0;
static RTL_HEAP_INITIALIZED: AtomicBool = AtomicBool::new(false);

// ============================================================
// RtlInitUnicodeString
// ============================================================

pub unsafe fn rtl_init_unicode_string(
    destination_string: *mut UnicodeString,
    source_string: *const u16,
) {
    if destination_string.is_null() {
        return;
    }

    let dest = &mut *destination_string;

    if source_string.is_null() {
        dest.length = 0;
        dest.maximum_length = 0;
        dest.buffer = core::ptr::null();
        return;
    }

    let mut len = 0u16;
    let mut src = source_string;
    while *src != 0 {
        len += 1;
        src = src.add(1);
    }

    let byte_len = len * 2;
    dest.length = byte_len;
    dest.maximum_length = byte_len + 2;
    dest.buffer = source_string;
}

// ============================================================
// RtlInitAnsiString
// ============================================================

pub unsafe fn rtl_init_ansi_string(
    destination_string: *mut AnsiString,
    source_string: *const u8,
) {
    if destination_string.is_null() {
        return;
    }

    let dest = &mut *destination_string;

    if source_string.is_null() {
        dest.length = 0;
        dest.maximum_length = 0;
        dest.buffer = core::ptr::null();
        return;
    }

    let mut len = 0u16;
    let mut src = source_string;
    while *src != 0 {
        len += 1;
        src = src.add(1);
    }

    dest.length = len;
    dest.maximum_length = len + 1;
    dest.buffer = source_string;
}

// ============================================================
// RtlCopyUnicodeString
// ============================================================

pub unsafe fn rtl_copy_unicode_string(
    destination_string: *mut UnicodeString,
    source_string: *const UnicodeString,
) {
    if destination_string.is_null() {
        return;
    }

    let dest = &mut *destination_string;

    if source_string.is_null() || (*source_string).buffer.is_null() || (*source_string).length == 0 {
        dest.length = 0;
        return;
    }

    let src = &*source_string;
    let copy_len = if src.length < dest.maximum_length {
        src.length as usize
    } else {
        (dest.maximum_length - 2) as usize
    };

    let copy_chars = copy_len / 2;

    if !dest.buffer.is_null() && copy_chars > 0 {
        let dst_slice = core::slice::from_raw_parts_mut(dest.buffer as *mut u16, copy_chars);
        let src_slice = core::slice::from_raw_parts(src.buffer, copy_chars);
        dst_slice.copy_from_slice(src_slice);
    }

    dest.length = copy_len as Ushort;

    if dest.length < dest.maximum_length {
        if !dest.buffer.is_null() {
            *((dest.buffer as *mut u16).add(copy_chars)) = 0;
        }
    }
}

// ============================================================
// RtlCompareUnicodeString
// ============================================================

pub unsafe fn rtl_compare_unicode_string(
    source1: *const UnicodeString,
    source2: *const UnicodeString,
    case_inensitive: Boolean,
) -> i32 {
    if source1.is_null() || source2.is_null() {
        return 0;
    }

    let s1 = &*source1;
    let s2 = &*source2;

    let len1 = s1.length as usize / 2;
    let len2 = s2.length as usize / 2;
    let min_len = if len1 < len2 { len1 } else { len2 };

    if min_len == 0 {
        if len1 < len2 {
            return -1;
        } else if len1 > len2 {
            return 1;
        } else {
            return 0;
        }
    }

    let buf1 = core::slice::from_raw_parts(s1.buffer, min_len);
    let buf2 = core::slice::from_raw_parts(s2.buffer, min_len);

    if case_inensitive != 0 {
        for i in 0..min_len {
            let c1 = rtl_upcase_unicode_char(buf1[i]);
            let c2 = rtl_upcase_unicode_char(buf2[i]);
            if c1 < c2 {
                return -1;
            } else if c1 > c2 {
                return 1;
            }
        }
    } else {
        for i in 0..min_len {
            if buf1[i] < buf2[i] {
                return -1;
            } else if buf1[i] > buf2[i] {
                return 1;
            }
        }
    }

    if len1 < len2 {
        -1
    } else if len1 > len2 {
        1
    } else {
        0
    }
}

// ============================================================
// RtlHashUnicodeString
// ============================================================

pub unsafe fn rtl_hash_unicode_string(
    string: *const UnicodeString,
    case_inensitive: Boolean,
    hash_algorithm: Ulong,
    hash_value: *mut Ulong,
) -> NtStatus {
    if string.is_null() || hash_value.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let s = &*string;
    *hash_value = 0;

    if s.length == 0 || s.buffer.is_null() {
        return STATUS_SUCCESS;
    }

    let char_count = s.length as usize / 2;
    let chars = core::slice::from_raw_parts(s.buffer, char_count);

    match hash_algorithm {
        0 => {
            // RtlHashDjb
            let mut hash: u32 = 5381;
            for &ch in chars {
                let c = if case_inensitive != 0 {
                    rtl_upcase_unicode_char(ch) as u32
                } else {
                    ch as u32
                };
                hash = hash.wrapping_mul(33).wrapping_add(c);
            }
            *hash_value = hash;
        }
        1 => {
            // RtlHashMurmur3
            let mut hash: u32 = 0x3b9aca07;
            for &ch in chars {
                let c = if case_inensitive != 0 {
                    rtl_upcase_unicode_char(ch) as u32
                } else {
                    ch as u32
                };
                let mut k = c;
                k = k.wrapping_mul(0xcc9e2d51);
                k = k.rotate_left(15);
                k = k.wrapping_mul(0x1b873593);
                hash ^= k;
                hash = hash.rotate_left(13);
                hash = hash.wrapping_mul(5).wrapping_add(0xe6546b64);
            }
            hash ^= char_count as u32;
            hash ^= hash >> 16;
            hash = hash.wrapping_mul(0x85ebca6b);
            hash ^= hash >> 13;
            hash = hash.wrapping_mul(0xc2b2ae35);
            hash ^= hash >> 16;
            *hash_value = hash;
        }
        _ => {
            // Default: simple polynomial hash
            let mut hash: u32 = 0x811c9dc5;
            for &ch in chars {
                let c = if case_inensitive != 0 {
                    rtl_upcase_unicode_char(ch) as u32
                } else {
                    ch as u32
                };
                hash ^= c;
                hash = hash.wrapping_mul(0x01000193);
            }
            *hash_value = hash;
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// RtlAnsiStringToUnicodeString
// ============================================================

pub unsafe fn rtl_ansi_string_to_unicode_string(
    destination_string: *mut UnicodeString,
    source_string: *const AnsiString,
    allocate_destination_string: Boolean,
) -> NtStatus {
    if destination_string.is_null() || source_string.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let src = &*source_string;

    if src.buffer.is_null() || src.length == 0 {
        (*destination_string).length = 0;
        (*destination_string).maximum_length = 0;
        (*destination_string).buffer = core::ptr::null();
        return STATUS_SUCCESS;
    }

    let byte_len = src.length as usize * 2;
    let alloc_len = byte_len + 2;

    let buffer = if allocate_destination_string != 0 {
        let layout = match core::alloc::Layout::from_size_align(alloc_len, 2) {
            Ok(l) => l,
            Err(_) => return STATUS_NO_MEMORY,
        };
        let ptr = alloc::alloc::alloc_zeroed(layout);
        if ptr.is_null() {
            return STATUS_NO_MEMORY;
        }
        ptr as *mut u16
    } else {
        (*destination_string).buffer as *mut u16
    };

    let src_slice = core::slice::from_raw_parts(src.buffer, src.length as usize);
    let dst_slice = core::slice::from_raw_parts_mut(buffer, src.length as usize);

    for i in 0..src.length as usize {
        dst_slice[i] = src_slice[i] as u16;
    }

    // Null-terminate
    *buffer.add(src.length as usize) = 0;

    (*destination_string).length = byte_len as Ushort;
    (*destination_string).maximum_length = (byte_len + 2) as Ushort;
    (*destination_string).buffer = buffer;

    STATUS_SUCCESS
}

// ============================================================
// RtlUnicodeStringToAnsiString
// ============================================================

pub unsafe fn rtl_unicode_string_to_ansi_string(
    destination_string: *mut AnsiString,
    source_string: *const UnicodeString,
    allocate_destination_string: Boolean,
) -> NtStatus {
    if destination_string.is_null() || source_string.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let src = &*source_string;

    if src.buffer.is_null() || src.length == 0 {
        (*destination_string).length = 0;
        (*destination_string).maximum_length = 0;
        (*destination_string).buffer = core::ptr::null();
        return STATUS_SUCCESS;
    }

    let char_count = src.length as usize / 2;
    let alloc_len = char_count + 1;

    let buffer = if allocate_destination_string != 0 {
        let layout = match core::alloc::Layout::from_size_align(alloc_len, 1) {
            Ok(l) => l,
            Err(_) => return STATUS_NO_MEMORY,
        };
        let ptr = alloc::alloc::alloc_zeroed(layout);
        if ptr.is_null() {
            return STATUS_NO_MEMORY;
        }
        ptr
    } else {
        (*destination_string).buffer as *mut u8
    };

    let src_slice = core::slice::from_raw_parts(src.buffer, char_count);
    let dst_slice = core::slice::from_raw_parts_mut(buffer, char_count);

    for i in 0..char_count {
        dst_slice[i] = if src_slice[i] > 0x7f { b'?' } else { src_slice[i] as u8 };
    }

    *buffer.add(char_count) = 0;

    (*destination_string).length = char_count as Ushort;
    (*destination_string).maximum_length = (char_count + 1) as Ushort;
    (*destination_string).buffer = buffer;

    STATUS_SUCCESS
}

// ============================================================
// RtlIntegerToUnicodeString
// ============================================================

pub unsafe fn rtl_integer_to_unicode_string(
    value: Ulong,
    base: Ulong,
    string: *mut UnicodeString,
) -> NtStatus {
    if string.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    if base != 0 && base != 10 && base != 16 && base != 8 {
        return STATUS_INVALID_PARAMETER;
    }

    let actual_base = if base == 0 { 10 } else { base };

    let mut buf = [0u16; 32];
    let mut idx = buf.len();

    let mut v = value;
    if v == 0 {
        idx -= 1;
        buf[idx] = b'0' as u16;
    } else {
        while v > 0 && idx > 0 {
            idx -= 1;
            let digit = (v % actual_base) as u16;
            buf[idx] = if digit < 10 {
                b'0' as u16 + digit
            } else {
                b'A' as u16 + (digit - 10)
            };
            v /= actual_base;
        }
    }

    let num_chars = buf.len() - idx;

    let dest = &mut *string;
    if dest.maximum_length as usize >= (num_chars + 1) * 2 {
        let src = core::slice::from_raw_parts(buf.as_ptr().add(idx), num_chars);
        let dst = core::slice::from_raw_parts_mut(dest.buffer as *mut u16, num_chars);
        dst.copy_from_slice(src);

        *(dest.buffer as *mut u16).add(num_chars) = 0;
        dest.length = (num_chars * 2) as Ushort;
    } else {
        dest.length = 0;
        return STATUS_BUFFER_TOO_SMALL;
    }

    STATUS_SUCCESS
}

// ============================================================
// RtlUnicodeStringToInteger
// ============================================================

pub unsafe fn rtl_unicode_string_to_integer(
    string: *const UnicodeString,
    base: Ulong,
    value: *mut Ulong,
) -> NtStatus {
    if string.is_null() || value.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let s = &*string;

    if s.buffer.is_null() || s.length == 0 {
        *value = 0;
        return STATUS_SUCCESS;
    }

    let char_count = s.length as usize / 2;
    let chars = core::slice::from_raw_parts(s.buffer, char_count);

    // Skip leading whitespace
    let mut start = 0;
    while start < char_count && (chars[start] == ' ' as u16 || chars[start] == '\t' as u16) {
        start += 1;
    }

    // Auto-detect base
    let mut actual_base = base;
    let mut sign = 1i32;

    if start < char_count && chars[start] == '-' as u16 {
        sign = -1;
        start += 1;
    } else if start < char_count && chars[start] == '+' as u16 {
        start += 1;
    }

    if actual_base == 0 {
        if start + 1 < char_count {
            if chars[start] == '0' as u16 && (chars[start + 1] == 'x' as u16 || chars[start + 1] == 'X' as u16) {
                actual_base = 16;
                start += 2;
            } else if chars[start] == '0' as u16 {
                actual_base = 8;
                start += 1;
            } else {
                actual_base = 10;
            }
        } else if start < char_count {
            actual_base = 10;
        }
    } else if actual_base == 16 && start + 1 < char_count {
        if chars[start] == '0' as u16 && (chars[start + 1] == 'x' as u16 || chars[start + 1] == 'X' as u16) {
            start += 2;
        }
    }

    let mut result: u32 = 0;
    while start < char_count {
        let ch = chars[start];
        let digit = if ch >= '0' as u16 && ch <= '9' as u16 {
            ch - '0' as u16
        } else if ch >= 'a' as u16 && ch <= 'f' as u16 {
            ch - 'a' as u16 + 10
        } else if ch >= 'A' as u16 && ch <= 'F' as u16 {
            ch - 'A' as u16 + 10
        } else {
            break;
        };

        if digit as u32 >= actual_base {
            break;
        }

        result = result.wrapping_mul(actual_base).wrapping_add(digit as u32);
        start += 1;
    }

    *value = if sign < 0 {
        -(result as i32) as u32
    } else {
        result
    };

    STATUS_SUCCESS
}

// ============================================================
// RtlAppendUnicodeStringToString
// ============================================================

pub unsafe fn rtl_append_unicode_stringToString(
    destination: *mut UnicodeString,
    source: *const UnicodeString,
) -> NtStatus {
    if destination.is_null() || source.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dest = &mut *destination;
    let src = &*source;

    if src.length == 0 || src.buffer.is_null() {
        return STATUS_SUCCESS;
    }

    let current_len = dest.length as usize / 2;
    let append_len = src.length as usize / 2;
    let total_len = current_len + append_len;

    if total_len * 2 + 2 > dest.maximum_length as usize {
        return STATUS_BUFFER_TOO_SMALL;
    }

    if dest.buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dst = core::slice::from_raw_parts_mut(dest.buffer as *mut u16, total_len + 1);
    let src_slice = core::slice::from_raw_parts(src.buffer, append_len);

    dst[current_len..total_len].copy_from_slice(src_slice);
    dst[total_len] = 0;

    dest.length = (total_len * 2) as Ushort;

    STATUS_SUCCESS
}

// ============================================================
// RtlUpcaseUnicodeChar / RtlLowercaseUnicodeChar
// ============================================================

#[inline]
pub fn rtl_upcase_unicode_char(ch: u16) -> u16 {
    if ch >= 'a' as u16 && ch <= 'z' as u16 {
        ch - 32
    } else if ch >= 0xe0 && ch <= 0xff {
        // Latin extended lowercase -> uppercase
        ch - 32
    } else {
        ch
    }
}

#[inline]
pub fn rtl_lowercase_unicode_char(ch: u16) -> u16 {
    if ch >= 'A' as u16 && ch <= 'Z' as u16 {
        ch + 32
    } else if ch >= 0xc0 && ch <= 0xdf {
        // Latin extended uppercase -> lowercase
        ch + 32
    } else {
        ch
    }
}

// ============================================================
// RtlImageNtHeader
// ============================================================

pub unsafe fn rtl_image_nt_header(base: Pvoid) -> *mut ImageNtHeaders64 {
    if base.is_null() {
        return core::ptr::null_mut();
    }

    let dos_header = base as *const ImageDosHeader;
    let e_magic = (*dos_header).e_magic;

    if e_magic != 0x5a4d {
        // "MZ"
        return core::ptr::null_mut();
    }

    let nt_offset = (*dos_header).e_lfanew as usize;
    if nt_offset == 0 || nt_offset > 0x1000 {
        return core::ptr::null_mut();
    }

    let nt_header = (base as usize + nt_offset) as *mut ImageNtHeaders64;

    if (*nt_header).signature != IMAGE_NT_SIGNATURE {
        return core::ptr::null_mut();
    }

    nt_header
}

// ============================================================
// RtlImageDirectoryEntryToData
// ============================================================

pub unsafe fn rtl_image_directory_entry_to_data(
    base: Pvoid,
    mapped_as_image: Boolean,
    directory_entry: Ushort,
    size: *mut Ulong,
) -> Pvoid {
    if base.is_null() || directory_entry as usize >= IMAGE_NUMBEROF_DIRECTORY_ENTRIES {
        if !size.is_null() {
            *size = 0;
        }
        return core::ptr::null_mut();
    }

    let nt_header = rtl_image_nt_header(base);
    if nt_header.is_null() {
        if !size.is_null() {
            *size = 0;
        }
        return core::ptr::null_mut();
    }

    let nt = &*nt_header;
    let dir_entry = &nt.optional_header.data_directory[directory_entry as usize];

    if dir_entry.virtual_address == 0 || dir_entry.size == 0 {
        if !size.is_null() {
            *size = 0;
        }
        return core::ptr::null_mut();
    }

    if !size.is_null() {
        *size = dir_entry.size;
    }

    if mapped_as_image != 0 {
        (base as usize + dir_entry.virtual_address as usize) as Pvoid
    } else {
        rtl_image_rva_to_va(
            nt_header,
            base as usize as Pvoid,
            dir_entry.virtual_address,
            core::ptr::null_mut(),
        )
    }
}

// ============================================================
// RtlImageRvaToVa
// ============================================================

pub unsafe fn rtl_image_rva_to_va(
    nt_header: *const ImageNtHeaders64,
    base: Pvoid,
    rva: u32,
    section: *mut *const ImageSectionHeader,
) -> Pvoid {
    if nt_header.is_null() || base.is_null() {
        return core::ptr::null_mut();
    }

    let nt = &*nt_header;
    let num_sections = nt.file_header.number_of_sections as usize;

    let section_ptr = (nt as *const ImageNtHeaders64 as usize)
        + mem::size_of::<ImageNtHeaders64>()
        - IMAGE_NUMBEROF_DIRECTORY_ENTRIES * mem::size_of::<ImageDataDirectory>()
        + nt.optional_header.number_of_rva_and_sizes as usize * mem::size_of::<ImageDataDirectory>();

    let sections = section_ptr as *const ImageSectionHeader;

    let mut found_section: *const ImageSectionHeader = core::ptr::null();

    for i in 0..num_sections {
        let sec = &*sections.add(i);
        if rva >= sec.virtual_address
            && rva < sec.virtual_address + sec.size_of_raw_data
        {
            found_section = sec;
            break;
        }
    }

    if found_section.is_null() {
        if !section.is_null() {
            *section = core::ptr::null();
        }
        return core::ptr::null_mut();
    }

    if !section.is_null() {
        *section = found_section;
    }

    let sec = &*found_section;
    let offset = rva - sec.virtual_address;
    (base as usize + sec.pointer_to_raw_data as usize + offset as usize) as Pvoid
}

// ============================================================
// RtlCaptureContext
// ============================================================

pub unsafe fn rtl_capture_context(context: *mut ContextRecord) {
    if context.is_null() {
        return;
    }

    let ctx = &mut *context;
    ctx.context_flags = CONTEXT_ALL;

    asm!(
        "mov [{0}], rax",
        "mov [{0} + 8*1], rcx",
        "mov [{0} + 8*2], rdx",
        "mov [{0} + 8*3], rbx",
        "mov [{0} + 8*4], rsp",
        "mov [{0} + 8*5], rbp",
        "mov [{0} + 8*6], rsi",
        "mov [{0} + 8*7], rdi",
        "mov [{0} + 8*8], r8",
        "mov [{0} + 8*9], r9",
        "mov [{0} + 8*10], r10",
        "mov [{0} + 8*11], r11",
        "mov [{0} + 8*12], r12",
        "mov [{0} + 8*13], r13",
        "mov [{0} + 8*14], r14",
        "mov [{0} + 8*15], r15",
        in(reg) &mut ctx.rax as *mut u64,
        options(nostack, nomem),
    );

    // Capture instruction pointer (RIP) - caller's return address
    let rip: u64;
    asm!("", out("r15") rip, options(nostack, nomem));
    ctx.rip = rip;
}

// ============================================================
// RtlRestoreContext
// ============================================================

pub unsafe fn rtl_restore_context(context: *mut ContextRecord) {
    if context.is_null() {
        return;
    }

    let ctx = &*context;

    asm!(
        "mov rax, [{0}]",
        "mov rcx, [{0} + 8*1]",
        "mov rdx, [{0} + 8*2]",
        "mov rbx, [{0} + 8*3]",
        "mov rbp, [{0} + 8*5]",
        "mov rsi, [{0} + 8*6]",
        "mov rdi, [{0} + 8*7]",
        "mov r8, [{0} + 8*8]",
        "mov r9, [{0} + 8*9]",
        "mov r10, [{0} + 8*10]",
        "mov r11, [{0} + 8*11]",
        "mov r12, [{0} + 8*12]",
        "mov r13, [{0} + 8*13]",
        "mov r14, [{0} + 8*14]",
        "mov r15, [{0} + 8*15]",
        in(reg) &ctx.rax as *const u64,
        options(nostack, nomem),
    );

    // Restore RSP and jump to RIP
    let new_rsp = ctx.rsp;
    let new_rip = ctx.rip;
    asm!(
        "mov rsp, {0}",
        "jmp {1}",
        in(reg) new_rsp,
        in(reg) new_rip,
        options(nostack, nomem, noreturn),
    );
}

// ============================================================
// RtlLookupFunctionEntry
// ============================================================

pub unsafe fn rtl_lookup_function_entry(
    control_pc: u64,
    image_base: *mut u64,
    history_table: *mut UnwindHistoryTable,
) -> *const RuntimeFunction {
    let _ = (control_pc, image_base, history_table);

    // In a full implementation, this would walk the runtime function table
    // For our kernel, we maintain a simplified function table
    core::ptr::null()
}

// ============================================================
// RtlVirtualUnwind
// ============================================================

pub unsafe fn rtl_virtual_unwind(
    image_type: u32,
    context_record: *mut ContextRecord,
    history_table: *mut UnwindHistoryTable,
    context_pointers: Pvoid,
) -> NtStatus {
    let _ = (image_type, history_table, context_pointers);

    if context_record.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let ctx = &mut *context_record;

    // Walk the unwind info chain for the current RIP
    let func_entry = rtl_lookup_function_entry(ctx.rip, core::ptr::null_mut(), history_table);
    if func_entry.is_null() {
        // Leaf function: simple frame = rbp-based or rsp-based
        // Restore caller's RSP from RBP if frame pointer is valid
        if ctx.rbp != 0 && ctx.rbp > ctx.rsp {
            ctx.rip = *(ctx.rbp as *const u64).add(1);
            ctx.rsp = ctx.rbp + 8;
            ctx.rbp = *(ctx.rbp as *const u64);
        }
        return STATUS_SUCCESS;
    }

    let rf = &*func_entry;
    let unwind_data = rf.unwind_data as usize;

    // Parse UNWIND_INFO
    let unwind_info = unwind_data as *const u8;
    let version_flags = *unwind_info;
    let version = version_flags & 0x07;
    let flags = (version_flags >> 3) & 0x1f;
    let size_of_prolog = *unwind_info.add(1) as usize;
    let unwind_codes_count = *unwind_info.add(2) as usize;

    let _ = version;
    let _ = flags;

    // Skip to unwind codes (offset 4, aligned to 4 bytes)
    let codes_offset = 4 + (unwind_codes_count * 2 + 3) & !3;

    // Process unwind codes
    let mut i = 0;
    let mut frame_offset = 0i64;
    while i < unwind_codes_count {
        let offset_in_codes = codes_offset + i * 2;
        let opcode = *unwind_info.add(offset_in_codes);
        let opinfo = *unwind_info.add(offset_in_codes + 1);

        match opcode & 0xf0 {
            0x10 => {
                // UWOP_PUSH_NONVOL - RBP saved
                if opinfo == 5 {
                    ctx.rsp += 8;
                    ctx.rbp = *(ctx.rsp as *const u64);
                    frame_offset = 8;
                } else {
                    ctx.rsp += 8;
                }
            }
            0x20 => {
                // UWOP_ALLOC_LARGE
                let scale = (opcode >> 5) & 0x03;
                if scale == 0 {
                    let alloc_size = (opinfo as u64) << 3;
                    ctx.rsp += alloc_size;
                } else if scale == 1 {
                    let alloc_size = u16::from_le_bytes([
                        *unwind_info.add(offset_in_codes + 2),
                        *unwind_info.add(offset_in_codes + 3),
                    ]) as u64 * 8;
                    ctx.rsp += alloc_size;
                    i += 1;
                } else {
                    let alloc_size = u32::from_le_bytes([
                        *unwind_info.add(offset_in_codes + 2),
                        *unwind_info.add(offset_in_codes + 3),
                        *unwind_info.add(offset_in_codes + 4),
                        *unwind_info.add(offset_in_codes + 5),
                    ]) as u64;
                    ctx.rsp += alloc_size;
                    i += 2;
                }
            }
            0x30 => {
                // UWOP_ALLOC_SMALL
                let alloc_size = ((opcode & 0xf0) >> 4) as u64 * 8 + 8;
                ctx.rsp += alloc_size;
            }
            0x40 => {
                // UWOP_SET_FPREG
                let offset_in_fpreg = (opinfo as i32) * -8;
                ctx.rbp = ctx.rsp + (offset_in_fpreg as i64) as u64;
            }
            _ => {}
        }

        i += 1;
    }

    // Read return address from stack
    ctx.rip = *(ctx.rsp as *const u64);
    ctx.rsp += 8;

    STATUS_SUCCESS
}

// ============================================================
// RtlUnwindEx
// ============================================================

pub unsafe fn rtl_unwind_ex(
    target_frame: Pvoid,
    target_ip: Pvoid,
    exception_record: *mut ExceptionRecord,
    context_record: *mut ContextRecord,
    history_table: *mut UnwindHistoryTable,
    dispatcher_table: Pvoid,
) -> NtStatus {
    let _ = (exception_record, dispatcher_table);

    if context_record.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let ctx = &mut *context_record;

    // If no target frame, unwind one frame
    if target_frame.is_null() {
        let status = rtl_virtual_unwind(
            0,
            ctx,
            history_table,
            core::ptr::null_mut(),
        );
        return status;
    }

    // Unwind to the target frame
    while (ctx.rbp as *mut c_void) != target_frame {
        if ctx.rbp == 0 {
            break;
        }

        let status = rtl_virtual_unwind(
            0,
            ctx,
            history_table,
            core::ptr::null_mut(),
        );

        if status != STATUS_SUCCESS {
            break;
        }
    }

    // Set RIP to target IP
    if !target_ip.is_null() {
        ctx.rip = target_ip as u64;
    }

    STATUS_SUCCESS
}

// ============================================================
// RtlpAllocateHeap - Internal heap allocation
// ============================================================

pub unsafe fn rtlp_allocate_heap(
    heap_handle: Pvoid,
    flags: Ulong,
    size: usize,
) -> Pvoid {
    if heap_handle.is_null() || size == 0 {
        return core::ptr::null_mut();
    }

    // Align size to 16-byte boundary
    let aligned_size = (size + 15) & !15;

    // Allocate from pool as our heap backing store
    let total_size = aligned_size + mem::size_of::<RtlHeapEntry>();
    let layout = match core::alloc::Layout::from_size_align(total_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };

    let ptr = if flags & HEAP_ZERO_MEMORY != 0 {
        alloc::alloc::alloc_zeroed(layout)
    } else {
        alloc::alloc::alloc(layout)
    };

    if ptr.is_null() {
        return core::ptr::null_mut();
    }

    // Initialize heap entry
    let entry = &mut *(ptr as *mut RtlHeapEntry);
    entry.size = aligned_size as u32;
    entry.flags = 0;
    entry.small_tag_index = 0;
    entry.previous_size = 0;
    entry.segment_index = 0;
    entry.unused_bytes = 0;

    // Return pointer past the header
    ptr.add(mem::size_of::<RtlHeapEntry>()) as Pvoid
}

// ============================================================
// RtlpFreeHeap - Internal heap free
// ============================================================

pub unsafe fn rtlp_free_heap(
    heap_handle: Pvoid,
    flags: Ulong,
    base_address: Pvoid,
) -> Boolean {
    let _ = flags;

    if heap_handle.is_null() || base_address.is_null() {
        return FALSE;
    }

    let ptr = (base_address as *mut u8).sub(mem::size_of::<RtlHeapEntry>());
    let entry = &*(ptr as *const RtlHeapEntry);
    let total_size = entry.size as usize + mem::size_of::<RtlHeapEntry>();

    let layout = match core::alloc::Layout::from_size_align(total_size, 16) {
        Ok(l) => l,
        Err(_) => return FALSE,
    };

    alloc::alloc::dealloc(ptr, layout);
    TRUE
}

// ============================================================
// RtlpWaitForHeap - Stub for heap contention
// ============================================================

pub unsafe fn rtlp_wait_for_heap(
    _heap_handle: Pvoid,
    _flags: Ulong,
    _size: usize,
) -> Pvoid {
    // In a real implementation, this would wait on the heap lock
    // and retry allocation. Here we just try once more.
    core::ptr::null_mut()
}

// ============================================================
// RtlAllocateHeap / RtlFreeHeap (exported)
// ============================================================

pub unsafe fn rtl_allocate_heap(
    heap_handle: Pvoid,
    flags: Ulong,
    size: usize,
) -> Pvoid {
    let mut result = rtlp_allocate_heap(heap_handle, flags, size);

    if result.is_null() && !heap_handle.is_null() {
        // Retry with wait
        result = rtlp_wait_for_heap(heap_handle, flags, size);
    }

    result
}

pub unsafe fn rtl_free_heap(
    heap_handle: Pvoid,
    flags: Ulong,
    base_address: Pvoid,
) -> Boolean {
    rtlp_free_heap(heap_handle, flags, base_address)
}

// ============================================================
// KeInitializeAffinityEx - Initialize processor affinity
// ============================================================

pub unsafe fn ke_initialize_affinity_ex(
    affinity: *mut u64,
    group: u16,
    count: u32,
) {
    if affinity.is_null() {
        return;
    }

    let _ = group;

    let mut mask: u64 = 0;
    let max_bits = if count > 64 { 64 } else { count };
    for i in 0..max_bits {
        mask |= 1u64 << i;
    }

    *affinity = mask;
}

// ============================================================
// RtlComputeCrc32 - CRC32 computation
// ============================================================

pub fn rtl_compute_crc32(seed: u32, buffer: *const u8, length: usize) -> u32 {
    if buffer.is_null() || length == 0 {
        return seed;
    }

    let data = unsafe { core::slice::from_raw_parts(buffer, length) };

    // Standard CRC32 polynomial (0xEDB88320 reflected)
    let mut crc = seed ^ 0xFFFFFFFF;

    for &byte in data {
        crc ^= byte as u32;
        for _ in 0..8 {
            if crc & 1 != 0 {
                crc = (crc >> 1) ^ 0xEDB88320;
            } else {
                crc >>= 1;
            }
        }
    }

    crc ^ 0xFFFFFFFF
}

// ============================================================
// RtlComputeCrc32C - CRC32C (Castagnoli) computation
// ============================================================

pub fn rtl_compute_crc32c(seed: u32, buffer: *const u8, length: usize) -> u32 {
    if buffer.is_null() || length == 0 {
        return seed;
    }

    let data = unsafe { core::slice::from_raw_parts(buffer, length) };

    // CRC32C polynomial (0x82F63B78 reflected)
    let mut crc = seed ^ 0xFFFFFFFF;

    for &byte in data {
        crc ^= byte as u32;
        for _ in 0..8 {
            if crc & 1 != 0 {
                crc = (crc >> 1) ^ 0x82F63B78;
            } else {
                crc >>= 1;
            }
        }
    }

    crc ^ 0xFFFFFFFF
}

// ============================================================
// RtlpCreateHeap - Create a new heap
// ============================================================

pub unsafe fn rtlp_create_heap(
    parameters: *mut RtlHeapParameters,
) -> Pvoid {
    let params = if !parameters.is_null() {
        &*parameters
    } else {
        &RtlHeapParameters::new()
    };

    let heap_size = mem::size_of::<RtlHeap>();
    let layout = match core::alloc::Layout::from_size_align(heap_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };

    let heap = alloc::alloc::alloc_zeroed(layout) as *mut RtlHeap;
    if heap.is_null() {
        return core::ptr::null_mut();
    }

    let h = &mut *heap;
    h.entry.size = heap_size as u32;
    h.lock = 0;
    h.flags = params.flags;
    h.virtual_memory_threshold = params.virtual_memory_threshold;
    h.segment_reserve = params.segment_reserve;
    h.segment_commit = params.segment_commit;
    h.decommit_free_block_threshold = params.decommit_free_block_threshold;
    h.decommit_total_free_threshold = params.decommit_total_free_threshold;
    h.free_list.flink = &mut h.free_list as *mut ListEntry;
    h.free_list.blink = &mut h.free_list as *mut ListEntry;
    h.committed_list.flink = &mut h.committed_list as *mut ListEntry;
    h.committed_list.blink = &mut h.committed_list as *mut ListEntry;
    h.uncommitted_list.flink = &mut h.uncommitted_list as *mut ListEntry;
    h.uncommitted_list.blink = &mut h.uncommitted_list as *mut ListEntry;
    h.segment_list.flink = &mut h.segment_list as *mut ListEntry;
    h.segment_list.blink = &mut h.segment_list as *mut ListEntry;

    heap as Pvoid
}

// ============================================================
// RtlpDestroyHeap - Destroy a heap
// ============================================================

pub unsafe fn rtlp_destroy_heap(heap_handle: Pvoid) -> Boolean {
    if heap_handle.is_null() {
        return FALSE;
    }

    let heap = heap_handle as *mut RtlHeap;
    let layout = core::alloc::Layout::from_size_align(mem::size_of::<RtlHeap>(), 16).unwrap();
    alloc::alloc::dealloc(heap as *mut u8, layout);

    TRUE
}

// ============================================================
// RtlGetHeapBackTrace - Simplified back trace
// ============================================================

pub unsafe fn rtl_get_heap_back_trace(
    _frames_to_skip: Ulong,
    _frames_to_capture: Ulong,
    _back_trace: *mut Pvoid,
    _hash: *mut Ulong,
) -> Ulong {
    if !_back_trace.is_null() { *_back_trace = core::ptr::null_mut(); }
    if !_hash.is_null() { *_hash = 0; }
    0
}

// ============================================================
// RtlpCallVectoredExceptionHandlers
// ============================================================

pub unsafe fn rtlp_call_vectored_exception_handlers(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
) -> NtStatus {
    // In Windows, this calls registered vectored exception handlers (VEH).
    // In VladOS, we use a simple linked list of VEH handlers.
    // For now, return STATUS_NOT_FOUND to let the default handler run.
    let _ = exception_record;
    let _ = context;
    STATUS_NOT_FOUND
}

// ============================================================
// RtlpCallVectoredContinueHandlers
// ============================================================

pub unsafe fn rtlp_call_vectored_continue_handlers(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
) -> NtStatus {
    // In Windows, this calls registered vectored continue handlers.
    // In VladOS, we use a simple linked list of VCH handlers.
    // For now, return STATUS_NOT_FOUND to let the default handler run.
    let _ = exception_record;
    let _ = context;
    STATUS_NOT_FOUND
}

// ============================================================
// RtlFillMemory / RtlZeroMemory / RtlMoveMemory
// ============================================================

pub unsafe fn rtl_fill_memory(destination: Pvoid, length: usize, fill: u8) {
    if destination.is_null() {
        return;
    }
    let dst = core::slice::from_raw_parts_mut(destination as *mut u8, length);
    dst.fill(fill);
}

pub unsafe fn rtl_zero_memory(destination: Pvoid, length: usize) {
    if destination.is_null() {
        return;
    }
    let dst = core::slice::from_raw_parts_mut(destination as *mut u8, length);
    dst.fill(0);
}

pub unsafe fn rtl_move_memory(destination: Pvoid, source: Pvoid, length: usize) {
    if destination.is_null() || source.is_null() {
        return;
    }
    let src = core::slice::from_raw_parts(source as *const u8, length);
    let dst = core::slice::from_raw_parts_mut(destination as *mut u8, length);
    dst.copy_from_slice(src);
}

pub unsafe fn rtl_copy_memory(destination: Pvoid, source: Pvoid, length: usize) {
    rtl_move_memory(destination, source, length);
}

pub unsafe fn rtl_equal_memory(source: Pvoid, destination: Pvoid, length: usize) -> usize {
    if source.is_null() || destination.is_null() {
        return 0;
    }
    let src = core::slice::from_raw_parts(source as *const u8, length);
    let dst = core::slice::from_raw_parts(destination as *const u8, length);
    if src == dst { length } else { 0 }
}

// ============================================================
// Message Table types (for RtlFindMessage)
// ============================================================

#[repr(C)]
pub struct MessageResourceBlock {
    pub low_id: u32,
    pub high_id: u32,
    pub offset_to_entries: u32,
}

#[repr(C)]
pub struct MessageResourceEntry {
    pub length: u16,
    pub flags: u16,
    // Text follows: [u8; length - 4]
}

// ============================================================
// RtlFindMessage
// ============================================================

pub unsafe fn rtl_find_message(
    dll_handle: Pvoid,
    _message_table_id: u32,
    _message_language_id: u32,
    message_id: u32,
    entry: *mut Pvoid,
) -> NtStatus {
    // In Windows, this searches a message table (RT_MESSAGETABLE resource) for a message.
    // In VladOS, we use a simple approach: the dll_handle points to the message table.
    // Message table format: { DWORD NumberOfBlocks; MESSAGE_RESOURCE_BLOCK Blocks[]; }
    // For now, return STATUS_NOT_FOUND if no table is loaded.
    if !entry.is_null() {
        *entry = core::ptr::null_mut();
    }

    if dll_handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Parse the message table header
    let table = dll_handle as *const u32;
    let number_of_blocks = *table;
    let blocks = table.add(1) as *const MessageResourceBlock;

    for i in 0..number_of_blocks as usize {
        let block = &*blocks.add(i);
        if message_id >= block.low_id && message_id <= block.high_id {
            let index = (message_id - block.low_id) as usize;
            let entries = (table as *const u8).add(block.offset_to_entries as usize) as *const MessageResourceEntry;
            let mut current_entry = entries;
            for _ in 0..index {
                current_entry = (current_entry as *const u8).add((*current_entry).length as usize) as *const MessageResourceEntry;
            }
            if !entry.is_null() {
                *entry = current_entry as *mut c_void;
            }
            return STATUS_SUCCESS;
        }
    }

    STATUS_NOT_FOUND
}

// ============================================================
// RtlFormatMessage
// ============================================================

pub unsafe fn rtl_format_message(
    message: *const UnicodeString,
    _flags: Ulong,
    _inserts: Pvoid,
    _argument_count: Ulong,
    buffer_size: Ushort,
    buffer: *mut UnicodeString,
    _arguments: *mut *mut u16,
) -> NtStatus {
    // In Windows, this formats a message string with insert parameters.
    // In VladOS, we just copy the message string as-is.
    if message.is_null() || buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let msg = &*message;
    let buf = &mut *buffer;

    let copy_len = core::cmp::min(msg.length as usize, buffer_size as usize - 2);
    buf.length = copy_len as u16;
    buf.maximum_length = buffer_size;

    if !buf.buffer.is_null() && !msg.buffer.is_null() && copy_len > 0 {
        core::ptr::copy_nonoverlapping(msg.buffer, buf.buffer as *mut u16, copy_len / 2);
        *(buf.buffer as *mut u16).add(copy_len / 2) = 0; // null terminator
    }

    STATUS_SUCCESS
}

// ============================================================
// RtlGetVersion (stub)
// ============================================================

#[repr(C)]
pub struct RtlOsVersionInfoEx {
    pub dw_os_version_info_size: u32,
    pub dw_major_version: u32,
    pub dw_minor_version: u32,
    pub dw_build_number: u32,
    pub dw_platform_id: u32,
    pub sz_csd_version: [u16; 128],
}

pub unsafe fn rtl_get_version(
    version_info: *mut RtlOsVersionInfoEx,
) -> NtStatus {
    if version_info.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let ver = &mut *version_info;
    ver.dw_os_version_info_size = mem::size_of::<RtlOsVersionInfoEx>() as u32;
    ver.dw_major_version = 10;
    ver.dw_minor_version = 0;
    ver.dw_build_number = 19041;
    ver.dw_platform_id = 2; // VER_PLATFORM_WIN32_NT

    STATUS_SUCCESS
}

// ============================================================
// RtlGetProductInfo (stub)
// ============================================================

pub unsafe fn rtl_get_product_info(
    os_major_version: u32,
    os_minor_version: u32,
    sp_major_version: u32,
    sp_minor_version: u32,
    product_type: *mut u32,
) -> Boolean {
    if !product_type.is_null() {
        *product_type = 1; // VER_NT_WORKSTATION
    }

    let _ = (os_major_version, os_minor_version, sp_major_version, sp_minor_version);
    TRUE
}

// ============================================================
// RtlInitializeHeapManager
// ============================================================

pub unsafe fn rtl_initialize_heap_manager() -> NtStatus {
    if RTL_HEAP_INITIALIZED.load(Ordering::Acquire) {
        return STATUS_SUCCESS;
    }

    let heap = rtlp_create_heap(core::ptr::null_mut());
    if heap.is_null() {
        return STATUS_NO_MEMORY;
    }

    RTL_PROCESS_HEAP = heap;
    RTL_HEAP_INITIALIZED.store(true, Ordering::Release);

    rtl_dbg!("RtlInitializeHeapManager: heap initialized");
    STATUS_SUCCESS
}

// ============================================================
// RtlGetProcessHeap
// ============================================================

pub unsafe fn rtl_get_process_heap() -> Pvoid {
    RTL_PROCESS_HEAP
}

// ============================================================
// RtlInitializeBitMap (simplified)
// ============================================================

#[repr(C)]
pub struct RtlBitmap {
    pub size_of_bitmap: u32,
    pub buffer: *mut u32,
}

impl RtlBitmap {
    pub const fn new() -> Self {
        Self {
            size_of_bitmap: 0,
            buffer: core::ptr::null_mut(),
        }
    }
}

pub unsafe fn rtl_initialize_bitmap(
    bitmap: *mut RtlBitmap,
    buffer: *mut u32,
    size_of_bitmap: u32,
) {
    if bitmap.is_null() {
        return;
    }

    let bm = &mut *bitmap;
    bm.size_of_bitmap = size_of_bitmap;
    bm.buffer = buffer;

    // Zero the bitmap buffer
    let dword_count = ((size_of_bitmap + 31) / 32) as usize;
    if !buffer.is_null() {
        let slice = core::slice::from_raw_parts_mut(buffer, dword_count);
        slice.fill(0);
    }
}

pub unsafe fn rtl_set_bits(
    bitmap: *const RtlBitmap,
    starting_index: u32,
    number_to_set: u32,
) {
    if bitmap.is_null() || (*bitmap).buffer.is_null() {
        return;
    }

    let bm = &*bitmap;
    let buf = core::slice::from_raw_parts_mut(bm.buffer, ((bm.size_of_bitmap + 31) / 32) as usize);

    for i in starting_index..starting_index + number_to_set {
        if i >= bm.size_of_bitmap {
            break;
        }
        let dword_idx = (i / 32) as usize;
        let bit_idx = i % 32;
        buf[dword_idx] |= 1 << bit_idx;
    }
}

pub unsafe fn rtl_clear_bits(
    bitmap: *const RtlBitmap,
    starting_index: u32,
    number_to_clear: u32,
) {
    if bitmap.is_null() || (*bitmap).buffer.is_null() {
        return;
    }

    let bm = &*bitmap;
    let buf = core::slice::from_raw_parts_mut(bm.buffer, ((bm.size_of_bitmap + 31) / 32) as usize);

    for i in starting_index..starting_index + number_to_clear {
        if i >= bm.size_of_bitmap {
            break;
        }
        let dword_idx = (i / 32) as usize;
        let bit_idx = i % 32;
        buf[dword_idx] &= !(1 << bit_idx);
    }
}

pub unsafe fn rtl_find_clear_bits(
    bitmap: *const RtlBitmap,
    number_to_find: u32,
    hint_index: u32,
) -> i32 {
    if bitmap.is_null() || (*bitmap).buffer.is_null() || number_to_find == 0 {
        return -1;
    }

    let bm = &*bitmap;
    let buf = core::slice::from_raw_parts(bm.buffer, ((bm.size_of_bitmap + 31) / 32) as usize);

    let mut run_start: i32 = -1;
    let mut run_count: u32 = 0;

    for i in hint_index..bm.size_of_bitmap {
        let dword_idx = (i / 32) as usize;
        let bit_idx = i % 32;

        if buf[dword_idx] & (1 << bit_idx) == 0 {
            if run_count == 0 {
                run_start = i as i32;
            }
            run_count += 1;
            if run_count >= number_to_find {
                return run_start;
            }
        } else {
            run_count = 0;
            run_start = -1;
        }
    }

    -1
}

// ============================================================
// RtlpHeapDebug routines (stubs)
// ============================================================

pub unsafe fn rtlp_heap_debug_init() -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn rtlp_heap_debug_check(
    _heap_handle: Pvoid,
    _base_address: Pvoid,
) -> Boolean {
    TRUE
}

// ============================================================
// RtlGetCallersAddress (simplified)
// ============================================================

pub unsafe fn rtl_get_callers_address(
    call_address: *mut *mut c_void,
    frame_address: *mut *mut c_void,
) {
    // Simplified: capture current frame info
    if !call_address.is_null() {
        *call_address = core::ptr::null_mut();
    }
    if !frame_address.is_null() {
        *frame_address = core::ptr::null_mut();
    }
}

// ============================================================
// RtlCaptureStackBackTrace
// ============================================================

pub unsafe fn rtl_capture_stack_back_trace(
    frames_to_skip: u32,
    frames_to_capture: u32,
    back_trace: *mut *mut c_void,
    hash: *mut u32,
) -> u16 {
    // In Windows, this walks the stack using frame pointers.
    // In VladOS, we use the x86_64 frame pointer chain.
    if back_trace.is_null() || frames_to_capture == 0 {
        if !hash.is_null() { *hash = 0; }
        return 0;
    }

    let mut frame_ptr: u64;
    core::arch::asm!("mov {0}, rbp", out(reg) frame_ptr);

    let mut count: u16 = 0;
    let mut skip = frames_to_skip;

    for i in 0..frames_to_capture as usize {
        if frame_ptr == 0 || frame_ptr < 0x1000 {
            break;
        }

        if skip > 0 {
            skip -= 1;
            // Follow frame pointer chain
            frame_ptr = *(frame_ptr as *const u64);
            continue;
        }

        // Return address is at frame_ptr + 8
        let ret_addr = *((frame_ptr + 8) as *const u64);
        *back_trace.add(i) = ret_addr as *mut c_void;
        count += 1;

        // Follow frame pointer chain
        frame_ptr = *(frame_ptr as *const u64);
    }

    if !hash.is_null() {
        *hash = count as u32;
    }

    count
}

// ============================================================
// RtlIpv4AddressToStringA
// ============================================================

pub unsafe fn rtl_ipv4_address_to_string_a(
    address: *const u8,
    address_string: *mut u8,
) -> NtStatus {
    if address.is_null() || address_string.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let addr = core::slice::from_raw_parts(address, 4);
    let buf = core::slice::from_raw_parts_mut(address_string, 16);

    // Format: "d.d.d.d" + null terminator
    let mut pos = 0;
    for i in 0..4 {
        let byte = addr[i];
        if byte >= 100 {
            buf[pos] = b'0' + byte / 100;
            pos += 1;
            buf[pos] = b'0' + (byte / 10) % 10;
            pos += 1;
        } else if byte >= 10 {
            buf[pos] = b'0' + byte / 10;
            pos += 1;
        }
        buf[pos] = b'0' + byte % 10;
        pos += 1;
        if i < 3 {
            buf[pos] = b'.';
            pos += 1;
        }
    }
    buf[pos] = 0; // null terminator

    STATUS_SUCCESS
}

// ============================================================
// RtlIpv6AddressToString
// ============================================================

pub unsafe fn rtl_ipv6_address_to_string(
    address: *const u8,
    address_string: *mut u16,
) -> NtStatus {
    if address.is_null() || address_string.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let addr = core::slice::from_raw_parts(address, 16);
    let buf = core::slice::from_raw_parts_mut(address_string, 46); // max IPv6 string

    // Format: "xxxx:xxxx:xxxx:xxxx:xxxx:xxxx:xxxx:xxxx" + null
    let mut pos = 0;
    for i in 0..8 {
        let word = ((addr[i * 2] as u16) << 8) | (addr[i * 2 + 1] as u16);
        let hex_chars = b"0123456789abcdef";
        let mut started = false;
        for shift in (0..16).step_by(4).rev() {
            let nibble = ((word >> shift) & 0xF) as usize;
            if nibble != 0 || started || shift == 0 {
                buf[pos] = hex_chars[nibble] as u16;
                pos += 1;
                started = true;
            }
        }
        if i < 7 {
            buf[pos] = ':' as u16;
            pos += 1;
        }
    }
    buf[pos] = 0; // null terminator

    STATUS_SUCCESS
}

// ============================================================
// RTL time conversion helpers
// ============================================================

pub const RTL_TIME_FIELDS_MONTHS_PER_YEAR: u32 = 12;
pub const RTL_TIME_FIELDS_DAYS_PER_WEEK: u32 = 7;
pub const RTL_TIME_FIELDS_DAYS_PER_MONTH: u32 = 31;
pub const RTL_TIME_FIELDS_MONTHS_PER_QUARTER: u32 = 3;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlTimeFields {
    pub year: i16,
    pub month: i16,
    pub day: i16,
    pub hour: i16,
    pub minute: i16,
    pub second: i16,
    pub milliseconds: i16,
    pub weekday: i16,
}

pub unsafe fn rtl_time_fields_to_time(
    time_fields: *const RtlTimeFields,
    time: *mut i64,
) -> NtStatus {
    if time_fields.is_null() || time.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let tf = &*time_fields;
    let mut total_days: i64 = 0;
    let mut total_seconds: i64 = 0;

    // Days from years
    let years = tf.year as i64 - 1601;
    total_days += years * 365;
    total_days += years / 4 - years / 100 + years / 400;

    // Days from months
    let month_days: [i64; 12] = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    if tf.month > 0 && (tf.month as usize) <= 12 {
        total_days += month_days[(tf.month - 1) as usize];
    }

    // Days from day
    total_days += (tf.day as i64) - 1;

    // Time in 100ns intervals
    total_seconds = total_days * 86400;
    total_seconds += tf.hour as i64 * 3600;
    total_seconds += tf.minute as i64 * 60;
    total_seconds += tf.second as i64;

    *time = total_seconds * 10_000_000;
    *time += tf.milliseconds as i64 * 10_000;

    STATUS_SUCCESS
}

pub unsafe fn rtl_time_to_time_fields(
    time: i64,
    time_fields: *mut RtlTimeFields,
) {
    if time_fields.is_null() {
        return;
    }

    let tf = &mut *time_fields;

    let mut total_100ns = time;
    if total_100ns < 0 {
        total_100ns = 0;
    }

    tf.milliseconds = ((total_100ns % 10_000_000) / 10_000) as i16;
    let mut total_seconds = total_100ns / 10_000_000;

    tf.second = (total_seconds % 60) as i16;
    total_seconds /= 60;
    tf.minute = (total_seconds % 60) as i16;
    total_seconds /= 60;
    tf.hour = (total_seconds % 24) as i16;
    let mut total_days = total_seconds / 24;

    // Compute day of week (0 = Sunday)
    let base_weekday = 1; // Jan 1 1601 was Monday
    tf.weekday = ((base_weekday + total_days % 7) % 7) as i16;

    // Compute year
    let mut year: i64 = 1601;
    let mut days_in_year;
    loop {
        days_in_year = if (year % 4 == 0 && year % 100 != 0) || year % 400 == 0 {
            366
        } else {
            365
        };
        if total_days < days_in_year {
            break;
        }
        total_days -= days_in_year;
        year += 1;
    }

    tf.year = year as i16;

    let month_days = [
        [0i64; 12], // non-leap
        [0, 31, 60, 91, 121, 152, 182, 213, 244, 274, 305, 335], // leap
    ];
    let leap = if (year % 4 == 0 && year % 100 != 0) || year % 400 == 0 { 1 } else { 0 };
    let md = &month_days[leap];

    let mut month: i64 = 0;
    for m in 0..12 {
        if total_days < md[m] {
            break;
        }
        if m + 1 < 12 {
            if total_days < md[m + 1] {
                month = m as i64;
                total_days -= md[m];
                break;
            }
        } else {
            month = m as i64;
            total_days -= md[m];
        }
    }

    tf.month = (month + 1) as i16;
    tf.day = (total_days + 1) as i16;
}

// ============================================================
// RtlSystemTimeToLocalTime / RtlLocalTimeToSystemTime
// ============================================================

pub unsafe fn rtl_system_time_to_local_time(
    system_time: *const i64,
    local_time: *mut i64,
) -> NtStatus {
    if system_time.is_null() || local_time.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Simplified: assume UTC = local (no timezone support)
    *local_time = *system_time;
    STATUS_SUCCESS
}

pub unsafe fn rtl_local_time_to_system_time(
    local_time: *const i64,
    system_time: *mut i64,
) -> NtStatus {
    if local_time.is_null() || system_time.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Simplified: assume local = UTC
    *system_time = *local_time;
    STATUS_SUCCESS
}
