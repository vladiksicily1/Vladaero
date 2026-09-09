/// # VladFS B-tree Index
///
/// Directory entries are stored in a B-tree index structure.
/// For small directories (< ~30 entries), the index is inline in $INDEX_ROOT.
/// For large directories, $INDEX_ALLOCATION contains index blocks.
///
/// Index Root layout (within MFT record):
///   [0..4]   attr_type: u32 (always ATTR_FILE_NAME = 0x30)
///   [4..8]   collation_rule: u32 (1 = filename)
///   [8..12]  index_block_size: u32 (4096)
///   [12..13] clusters_per_index: u8 (1)
///   [13..16] reserved: [u8; 3]
///   [16..32] Index Header:
///     [0..4]  flags: u32 (0=small, 1=large)
///     [4..8]  total_entries_size: u32
///     [8..12] allocated_size: u32
///     [12..16] padding: u32
///   Followed by Index Entries

use core::mem;

use super::attributes::{AttrHeader, ATTR_INDEX_ROOT, ATTR_INDEX_ALLOC};
use super::mft::MftRecord;

/// Index Root header
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IndexRootAttr {
    pub attr_type: u32,
    pub collation_rule: u32,
    pub index_block_size: u32,
    pub clusters_per_index: u8,
    pub _reserved: [u8; 3],
}

/// Index Header
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IndexHeader {
    pub flags: u32,
    pub total_entries_size: u32,
    pub allocated_size: u32,
    pub padding: u32,
}

/// Index Header flags
pub const INDEX_SMALL: u32 = 0;
pub const INDEX_LARGE: u32 = 1;

/// Index Entry header (before the name)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IndexEntryHeader {
    pub inode: u64,
    pub mft_record_number: u64,
    pub entry_size: u16,
    pub name_length: u8,
    pub name_namespace: u8,
}

/// Index Entry (complete, with name)
#[derive(Debug, Clone)]
pub struct IndexEntry {
    pub inode: u64,
    pub mft_record_number: u64,
    pub name: alloc::string::String,
    pub namespace: u8,
}

impl IndexEntry {
    /// Serialize to bytes (for inline storage)
    pub fn to_bytes(&self) -> alloc::vec::Vec<u8> {
        let name_utf16: alloc::vec::Vec<u16> = self.name.encode_utf16().collect();
        let name_bytes = name_utf16.len() * 2;
        let entry_size = (mem::size_of::<IndexEntryHeader>() + name_bytes + 7) & !7;
        let mut buf = alloc::vec![0u8; entry_size];

        let header = IndexEntryHeader {
            inode: self.inode,
            mft_record_number: self.mft_record_number,
            entry_size: entry_size as u16,
            name_length: name_utf16.len() as u8,
            name_namespace: self.namespace,
        };

        let header_bytes = unsafe {
            core::slice::from_raw_parts(
                &header as *const IndexEntryHeader as *const u8,
                mem::size_of::<IndexEntryHeader>(),
            )
        };
        buf[..header_bytes.len()].copy_from_slice(header_bytes);

        // Write name as UTF-16LE
        let name_start = mem::size_of::<IndexEntryHeader>();
        for (i, &ch) in name_utf16.iter().enumerate() {
            let offset = name_start + i * 2;
            buf[offset] = (ch & 0xFF) as u8;
            buf[offset + 1] = (ch >> 8) as u8;
        }

        buf
    }

    /// Parse from bytes (after the IndexEntryHeader)
    pub fn from_bytes(data: &[u8]) -> Option<Self> {
        if data.len() < mem::size_of::<IndexEntryHeader>() {
            return None;
        }

        let header = unsafe {
            core::ptr::read_volatile(data.as_ptr() as *const IndexEntryHeader)
        };

        let name_start = mem::size_of::<IndexEntryHeader>();
        let name_len = header.name_length as usize;

        if name_start + name_len * 2 > data.len() {
            return None;
        }

        let mut name = alloc::string::String::new();
        for i in 0..name_len {
            let offset = name_start + i * 2;
            let ch = ((data[offset] as u16) | ((data[offset + 1] as u16) << 8)) as u32;
            if let Some(c) = core::char::from_u32(ch) {
                name.push(c);
            }
        }

        Some(Self {
            inode: header.inode,
            mft_record_number: header.mft_record_number,
            name,
            namespace: header.name_namespace,
        })
    }
}

/// Parse all index entries from a $INDEX_ROOT attribute
pub fn parse_index_root(record: &MftRecord) -> alloc::vec::Vec<IndexEntry> {
    let mut entries = alloc::vec::Vec::new();

    if let Some((attr_offset, attr_header)) = record.find_attribute(ATTR_INDEX_ROOT) {
        if attr_header.non_resident != 0 {
            return entries;
        }

        let data_start = attr_offset + mem::size_of::<AttrHeader>();
        let data_end = attr_offset + attr_header.length as usize;

        if data_start + mem::size_of::<IndexRootAttr>() > data_end {
            return entries;
        }

        // Skip IndexRootAttr
        let ih_offset = data_start + mem::size_of::<IndexRootAttr>();
        if ih_offset + mem::size_of::<IndexHeader>() > data_end {
            return entries;
        }

        let ih = unsafe {
            core::ptr::read_volatile(record.raw[ih_offset..].as_ptr() as *const IndexHeader)
        };

        if ih.flags & INDEX_LARGE != 0 {
            // Large index - entries are in $INDEX_ALLOCATION
            return entries;
        }

        // Small index - entries are inline
        let entries_start = ih_offset + mem::size_of::<IndexHeader>();
        let entries_end = entries_start + ih.total_entries_size as usize;

        let mut eoff = entries_start;
        while eoff < entries_end && eoff + mem::size_of::<IndexEntryHeader>() <= record.raw.len() {
            let entry = IndexEntry::from_bytes(&record.raw[eoff..]);
            match entry {
                Some(e) if e.inode != 0 => {
                    entries.push(e);
                }
                _ => {}
            }

            // Get entry size
            let hdr = unsafe {
                core::ptr::read_volatile(record.raw[eoff..].as_ptr() as *const IndexEntryHeader)
            };
            if hdr.entry_size == 0 {
                break;
            }
            let aligned = (hdr.entry_size as usize + 7) & !7;
            eoff += aligned;
        }
    }

    entries
}

/// Build an inline index root attribute for a directory
pub fn build_index_root(entries: &[IndexEntry]) -> alloc::vec::Vec<u8> {
    let index_block_size = 4096u32;

    // Build index entries
    let mut entries_data = alloc::vec::Vec::new();
    for entry in entries {
        let bytes = entry.to_bytes();
        entries_data.extend_from_slice(&bytes);
    }

    let total_size = mem::size_of::<IndexRootAttr>()
        + mem::size_of::<IndexHeader>()
        + entries_data.len();

    let ir = IndexRootAttr {
        attr_type: super::attributes::ATTR_FILE_NAME,
        collation_rule: 1, // Filename collation
        index_block_size,
        clusters_per_index: 1,
        _reserved: [0; 3],
    };

    let ih = IndexHeader {
        flags: INDEX_SMALL,
        total_entries_size: entries_data.len() as u32,
        allocated_size: entries_data.len() as u32,
        padding: 0,
    };

    let attr_total = mem::size_of::<AttrHeader>() + total_size;
    let aligned_total = (attr_total + 7) & !7;
    let mut buf = alloc::vec![0u8; aligned_total];

    // Write attribute header
    let header = AttrHeader::new(ATTR_INDEX_ROOT, total_size as u32);
    let header_bytes = unsafe {
        core::slice::from_raw_parts(
            &header as *const AttrHeader as *const u8,
            mem::size_of::<AttrHeader>(),
        )
    };
    buf[..header_bytes.len()].copy_from_slice(header_bytes);

    // Write IndexRootAttr
    let ir_offset = mem::size_of::<AttrHeader>();
    let ir_bytes = unsafe {
        core::slice::from_raw_parts(
            &ir as *const IndexRootAttr as *const u8,
            mem::size_of::<IndexRootAttr>(),
        )
    };
    buf[ir_offset..ir_offset + ir_bytes.len()].copy_from_slice(ir_bytes);

    // Write IndexHeader
    let ih_offset = ir_offset + mem::size_of::<IndexRootAttr>();
    let ih_bytes = unsafe {
        core::slice::from_raw_parts(
            &ih as *const IndexHeader as *const u8,
            mem::size_of::<IndexHeader>(),
        )
    };
    buf[ih_offset..ih_offset + ih_bytes.len()].copy_from_slice(ih_bytes);

    // Write entries
    let entries_offset = ih_offset + mem::size_of::<IndexHeader>();
    buf[entries_offset..entries_offset + entries_data.len()].copy_from_slice(&entries_data);

    buf
}

/// Find a child entry by name in a directory's index
pub fn find_in_index(record: &MftRecord, name: &str) -> Option<u64> {
    let entries = parse_index_root(record);
    for entry in &entries {
        // Case-insensitive comparison (ASCII)
        if entry.name.eq_ignore_ascii_case(name) {
            return Some(entry.inode);
        }
    }
    None
}

/// Add an entry to a directory's index (returns updated $INDEX_ROOT attribute bytes)
pub fn add_to_index(
    record: &MftRecord,
    entry: IndexEntry,
) -> Result<alloc::vec::Vec<u8>, ()> {
    let mut entries = parse_index_root(record);

    // Check for duplicate
    for existing in &entries {
        if existing.name.eq_ignore_ascii_case(&entry.name) {
            return Err(()); // Already exists
        }
    }

    entries.push(entry);
    // Sort by name
    entries.sort_by(|a, b| a.name.cmp(&b.name));

    Ok(build_index_root(&entries))
}

/// Remove an entry from a directory's index
pub fn remove_from_index(
    record: &MftRecord,
    name: &str,
) -> Result<alloc::vec::Vec<u8>, ()> {
    let mut entries = parse_index_root(record);
    let initial_len = entries.len();

    entries.retain(|e| !e.name.eq_ignore_ascii_case(name));

    if entries.len() == initial_len {
        return Err(()); // Not found
    }

    Ok(build_index_root(&entries))
}
