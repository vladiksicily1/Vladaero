/// # VladFS Attributes
///
/// MFT records contain attributes that store metadata and data.
/// Each attribute has a header followed by type-specific content.
///
/// Attribute types:
///   0x10 - $STANDARD_INFORMATION (fixed, resident)
///   0x30 - $FILE_NAME (variable, resident)
///   0x80 - $DATA (resident or non-resident)
///   0x90 - $INDEX_ROOT (directories)
///   0xA0 - $INDEX_ALLOCATION (large directories)
///   0xB0 - $BITMAP (allocation bitmap)
///   0x00 - End of attributes

use core::mem;

use super::mft::MftRecord;

/// Attribute type constants
pub const ATTR_END: u32 = 0x00;
pub const ATTR_STANDARD_INFO: u32 = 0x10;
pub const ATTR_FILE_NAME: u32 = 0x30;
pub const ATTR_OBJECT_ID: u32 = 0x40;
pub const ATTR_SECURITY_DESC: u32 = 0x50;
pub const ATTR_VOLUME_LABEL: u32 = 0x60;
pub const ATTR_VOLUME_INFO: u32 = 0x70;
pub const ATTR_DATA: u32 = 0x80;
pub const ATTR_INDEX_ROOT: u32 = 0x90;
pub const ATTR_INDEX_ALLOC: u32 = 0xA0;
pub const ATTR_BITMAP: u32 = 0xB0;
pub const ATTR_REPARSE: u32 = 0xC0;
pub const ATTR_EA: u32 = 0xD0;

/// Attribute header (24 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct AttrHeader {
    pub attr_type: u32,
    pub length: u32,
    pub non_resident: u8,
    pub name_length: u8,
    pub name_offset: u16,
    pub flags: u16,
    pub attribute_id: u16,
}

impl AttrHeader {
    pub fn new(attr_type: u32, data_size: u32) -> Self {
        let total_size = mem::size_of::<AttrHeader>() as u32 + data_size;
        let aligned = (total_size + 7) & !7;
        Self {
            attr_type,
            length: aligned,
            non_resident: 0,
            name_length: 0,
            name_offset: mem::size_of::<AttrHeader>() as u16,
            flags: 0,
            attribute_id: 0,
        }
    }

    pub fn resident_size(&self) -> u32 {
        self.length - mem::size_of::<AttrHeader>() as u32
    }
}

// ============================================================
// $STANDARD_INFORMATION (0x10)
// ============================================================

/// Standard Information attribute (resident, fixed 48 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct StandardInfo {
    pub creation_time: u64,
    pub modification_time: u64,
    pub mft_change_time: u64,
    pub access_time: u64,
    pub file_attributes: u32,
    pub packed_permissions: u32,
}

impl StandardInfo {
    pub fn new(file_attributes: u32) -> Self {
        let now = unsafe { crate::ke::timer::ke_query_system_time() } as u64;
        Self {
            creation_time: now,
            modification_time: now,
            mft_change_time: now,
            access_time: now,
            file_attributes,
            packed_permissions: 0,
        }
    }

    /// Update modification time
    pub fn touch(&mut self) {
        let now = unsafe { crate::ke::timer::ke_query_system_time() } as u64;
        self.modification_time = now;
        self.mft_change_time = now;
    }

    /// Serialize to bytes
    pub fn to_bytes(&self) -> [u8; 48] {
        let mut buf = [0u8; 48];
        let src = unsafe {
            core::slice::from_raw_parts(
                self as *const Self as *const u8,
                mem::size_of::<Self>(),
            )
        };
        buf[..src.len()].copy_from_slice(src);
        buf
    }

    /// Parse from bytes
    pub fn from_bytes(data: &[u8]) -> Option<Self> {
        if data.len() < mem::size_of::<Self>() {
            return None;
        }
        Some(unsafe { core::ptr::read_volatile(data.as_ptr() as *const Self) })
    }
}

/// File attribute flags
pub const FILE_ATTRIBUTE_READONLY: u32 = 0x00000001;
pub const FILE_ATTRIBUTE_HIDDEN: u32 = 0x00000002;
pub const FILE_ATTRIBUTE_SYSTEM: u32 = 0x00000004;
pub const FILE_ATTRIBUTE_DIRECTORY: u32 = 0x00000010;
pub const FILE_ATTRIBUTE_ARCHIVE: u32 = 0x00000020;
pub const FILE_ATTRIBUTE_NORMAL: u32 = 0x00000080;
pub const FILE_ATTRIBUTE_TEMPORARY: u32 = 0x00000100;

// ============================================================
// $FILE_NAME (0x30)
// ============================================================

/// File Name attribute header (before the name itself)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct FileNameAttr {
    pub parent_inode: u64,
    pub creation_time: u64,
    pub modification_time: u64,
    pub mft_change_time: u64,
    pub access_time: u64,
    pub allocated_size: u64,
    pub real_size: u64,
    pub file_attributes: u32,
    pub reparse_point: u32,
    pub name_length: u8,
    pub namespace: u8,
}

/// Namespace types
pub const NS_POSIX: u8 = 0;
pub const NS_WIN32: u8 = 1;
pub const NS_DOS: u8 = 2;
pub const NS_WIN32DOS: u8 = 3;

impl FileNameAttr {
    /// Calculate total attribute size including name and alignment
    pub fn total_size(name_len_chars: u8) -> u32 {
        let base = mem::size_of::<AttrHeader>() + mem::size_of::<FileNameAttr>();
        let name_bytes = name_len_chars as usize * 2; // UTF-16LE
        let total = base + name_bytes;
        ((total + 7) & !7) as u32
    }

    /// Create a new File Name attribute
    pub fn new(
        parent_inode: u64,
        name: &str,
        file_attributes: u32,
        allocated_size: u64,
        real_size: u64,
        namespace: u8,
    ) -> alloc::vec::Vec<u8> {
        let now = unsafe { crate::ke::timer::ke_query_system_time() } as u64;
        let name_len = name.len() as u8;

        let header = AttrHeader::new(ATTR_FILE_NAME, Self::total_size(name_len) - mem::size_of::<AttrHeader>() as u32);

        let fname = FileNameAttr {
            parent_inode,
            creation_time: now,
            modification_time: now,
            mft_change_time: now,
            access_time: now,
            allocated_size,
            real_size,
            file_attributes,
            reparse_point: 0,
            name_length: name_len,
            namespace,
        };

        let total = Self::total_size(name_len) as usize;
        let mut buf = alloc::vec![0u8; total];

        // Write header
        let header_bytes = unsafe {
            core::slice::from_raw_parts(
                &header as *const AttrHeader as *const u8,
                mem::size_of::<AttrHeader>(),
            )
        };
        buf[..header_bytes.len()].copy_from_slice(header_bytes);

        // Write FileNameAttr
        let fname_offset = mem::size_of::<AttrHeader>();
        let fname_bytes = unsafe {
            core::slice::from_raw_parts(
                &fname as *const FileNameAttr as *const u8,
                mem::size_of::<FileNameAttr>(),
            )
        };
        buf[fname_offset..fname_offset + fname_bytes.len()].copy_from_slice(fname_bytes);

        // Write name (ASCII -> UTF-16LE)
        let name_offset = fname_offset + mem::size_of::<FileNameAttr>();
        for (i, ch) in name.bytes().enumerate() {
            let offset = name_offset + i * 2;
            if offset + 1 < buf.len() {
                buf[offset] = ch;
                buf[offset + 1] = 0;
            }
        }

        buf
    }

    /// Parse name from attribute data (after AttrHeader)
    pub fn parse_name(data: &[u8]) -> Option<alloc::string::String> {
        if data.len() < mem::size_of::<FileNameAttr>() {
            return None;
        }
        let fname = unsafe { core::ptr::read_volatile(data.as_ptr() as *const FileNameAttr) };
        let name_len = fname.name_length as usize;
        let name_start = mem::size_of::<FileNameAttr>();

        if name_start + name_len * 2 > data.len() {
            return None;
        }

        let mut name = alloc::string::String::new();
        for i in 0..name_len {
            let offset = name_start + i * 2;
            let ch = unsafe {
                core::ptr::read_volatile(data[offset..].as_ptr() as *const u16)
            };
            if let Some(c) = core::char::from_u32(ch as u32) {
                name.push(c);
            }
        }
        Some(name)
    }
}

// ============================================================
// $DATA (0x80)
// ============================================================

/// Non-resident data attribute extension
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct NonResidentExt {
    pub lowest_vcn: u64,
    pub highest_vcn: u64,
    pub data_runs_offset: u16,
    pub compression_unit: u16,
    pub _reserved: u32,
    pub allocated_size: u64,
    pub data_size: u64,
    pub initialized_size: u64,
}

/// Data run descriptor
#[derive(Debug, Clone, Copy)]
pub struct DataRun {
    pub lcn: u64,
    pub length: u64,
}

impl DataRun {
    /// Encode a data run into bytes
    pub fn encode(&self, prev_lcn: i64) -> alloc::vec::Vec<u8> {
        let mut buf = alloc::vec::Vec::new();

        // Run length
        let mut len_bytes = alloc::vec::Vec::new();
        let mut val = self.length;
        while val > 0 {
            len_bytes.push((val & 0xFF) as u8);
            val >>= 8;
        }
        if len_bytes.is_empty() {
            len_bytes.push(0);
        }

        // Run offset (relative)
        let offset = self.lcn as i64 - prev_lcn;
        let mut off_bytes = alloc::vec::Vec::new();
        let mut val = offset;
        if val == 0 {
            off_bytes.push(0);
        } else {
            // Sign-extend approach
            let positive = val >= 0;
            let mut abs_val = val.unsigned_abs();
            while abs_val > 0 {
                off_bytes.push((abs_val & 0xFF) as u8);
                abs_val >>= 8;
            }
            // Ensure sign bit is correct
            if positive && off_bytes.last().map_or(false, |&b| b & 0x80 != 0) {
                off_bytes.push(0);
            } else if !positive && off_bytes.last().map_or(true, |&b| b & 0x80 == 0) {
                // Need to set sign bit
                if let Some(last) = off_bytes.last_mut() {
                    *last |= 0x80;
                }
            }
        }

        let header = ((off_bytes.len() & 0x0F) << 4) | (len_bytes.len() & 0x0F);
        buf.push(header as u8);
        buf.extend_from_slice(&len_bytes);
        buf.extend_from_slice(&off_bytes);

        buf
    }
}

/// Parse data runs from a byte buffer
pub fn parse_data_runs(buf: &[u8], offset: usize) -> alloc::vec::Vec<DataRun> {
    let mut runs = alloc::vec::Vec::new();
    let mut pos = offset;
    let mut current_lcn: i64 = 0;

    while pos < buf.len() {
        let run_header = buf[pos];
        if run_header == 0 {
            break;
        }

        let len_size = (run_header & 0x0F) as usize;
        let off_size = ((run_header >> 4) & 0x0F) as usize;

        if len_size == 0 || pos + 1 + len_size + off_size > buf.len() {
            break;
        }

        // Read run length (little-endian unsigned)
        let mut length: u64 = 0;
        for i in 0..len_size {
            length |= (buf[pos + 1 + i] as u64) << (i * 8);
        }

        // Read run offset (little-endian signed)
        let off_start = pos + 1 + len_size;
        let mut offset_val: i64 = 0;
        if off_size > 0 {
            let last = buf[off_start + off_size - 1] as i8;
            if last < 0 {
                offset_val = -1;
            }
            for i in 0..off_size {
                offset_val = (offset_val & !((0xFFi64) << (i * 8)))
                    | ((buf[off_start + i] as i64) << (i * 8));
            }
        }

        current_lcn += offset_val;

        if length > 0 {
            runs.push(DataRun {
                lcn: current_lcn as u64,
                length,
            });
        }

        pos = off_start + off_size;
    }

    runs
}

/// Create initial data runs for a small file (inline in resident attribute)
pub fn create_resident_data_attr(data: &[u8]) -> alloc::vec::Vec<u8> {
    let data_len = data.len() as u32;
    let header = AttrHeader::new(ATTR_DATA, data_len);
    let total = ((mem::size_of::<AttrHeader>() + data.len() + 7) & !7) as usize;

    let mut buf = alloc::vec![0u8; total];
    let header_bytes = unsafe {
        core::slice::from_raw_parts(
            &header as *const AttrHeader as *const u8,
            mem::size_of::<AttrHeader>(),
        )
    };
    buf[..header_bytes.len()].copy_from_slice(header_bytes);
    buf[mem::size_of::<AttrHeader>()..mem::size_of::<AttrHeader>() + data.len()].copy_from_slice(data);

    buf
}

/// Create a non-resident data attribute header + data runs
pub fn create_non_resident_data_attr(
    data_runs: &[DataRun],
    data_size: u64,
    allocated_size: u64,
) -> alloc::vec::Vec<u8> {
    // Encode data runs
    let mut runs_buf = alloc::vec::Vec::new();
    let mut prev_lcn: i64 = 0;
    for run in data_runs {
        let encoded = run.encode(prev_lcn);
        runs_buf.extend_from_slice(&encoded);
        prev_lcn = run.lcn as i64;
    }

    let runs_offset = mem::size_of::<AttrHeader>() + mem::size_of::<NonResidentExt>();

    let nr = NonResidentExt {
        lowest_vcn: 0,
        highest_vcn: if data_runs.is_empty() {
            0
        } else {
            data_runs.iter().map(|r| r.length).sum::<u64>() - 1
        },
        data_runs_offset: runs_offset as u16,
        compression_unit: 0,
        _reserved: 0,
        allocated_size,
        data_size,
        initialized_size: data_size,
    };

    let total = runs_offset + runs_buf.len();
    let aligned_total = (total + 7) & !7;
    let mut buf = alloc::vec![0u8; aligned_total];

    // Write header
    let mut header = AttrHeader::new(ATTR_DATA, 0);
    header.non_resident = 1;
    header.length = aligned_total as u32;
    let header_bytes = unsafe {
        core::slice::from_raw_parts(
            &header as *const AttrHeader as *const u8,
            mem::size_of::<AttrHeader>(),
        )
    };
    buf[..header_bytes.len()].copy_from_slice(header_bytes);

    // Write non-resident extension
    let nr_bytes = unsafe {
        core::slice::from_raw_parts(
            &nr as *const NonResidentExt as *const u8,
            mem::size_of::<NonResidentExt>(),
        )
    };
    buf[mem::size_of::<AttrHeader>()..mem::size_of::<AttrHeader>() + nr_bytes.len()]
        .copy_from_slice(nr_bytes);

    // Write data runs
    buf[runs_offset..runs_offset + runs_buf.len()].copy_from_slice(&runs_buf);

    buf
}
