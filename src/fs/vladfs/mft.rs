/// # VladFS Master File Table (MFT)
///
/// Each MFT record is 1024 bytes, stores inode metadata and inline attributes.
/// Records are addressed by inode number: record N is at byte offset N * 1024 in MFT.
///
/// MFT Record layout:
///   [0..4]     signature: "FILE"
///   [4..12]    inode: u64
///   [12..14]   sequence: u16
///   [14..16]   flags: u16 (0x01=allocated, 0x02=directory)
///   [16..20]   record_size: u32
///   [20..28]   data_size: u64
///   [28..32]   first_attribute: u32 (offset from record start)
///   [32..36]   attributes_size: u32
///   [36..44]   parent_inode: u64
///   [44..52]   creation_time: u64
///   [52..60]   modification_time: u64
///   [60..68]   mft_change_time: u64
///   [68..76]   access_time: u64
///   [76..80]   packed_attrs: u32
///   [80..84]   checksum: u32
///   [84..1024] attribute area

use core::mem;
use core::sync::atomic::{AtomicBool, AtomicU64, Ordering};

use super::superblock::{SuperblockState, VLFS_MFT_RECORD_SIZE};
use super::attributes::{AttrHeader, ATTR_END};

/// MFT record signature
pub const MFT_SIGNATURE: [u8; 4] = *b"FILE";

/// MFT record flags
pub const MFT_FLAG_ALLOCATED: u16 = 0x0001;
pub const MFT_FLAG_DIRECTORY: u16 = 0x0002;

/// On-disk MFT record header (first 84 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct MftRecordHeader {
    pub signature: [u8; 4],
    pub inode: u64,
    pub sequence: u16,
    pub flags: u16,
    pub record_size: u32,
    pub data_size: u64,
    pub first_attribute: u32,
    pub attributes_size: u32,
    pub parent_inode: u64,
    pub creation_time: u64,
    pub modification_time: u64,
    pub mft_change_time: u64,
    pub access_time: u64,
    pub packed_attrs: u32,
    pub checksum: u32,
}

impl MftRecordHeader {
    pub fn new(inode: u64, flags: u16, parent_inode: u64) -> Self {
        let now = unsafe { crate::ke::timer::ke_query_system_time() } as u64;
        Self {
            signature: MFT_SIGNATURE,
            inode,
            sequence: 1,
            flags,
            record_size: VLFS_MFT_RECORD_SIZE,
            data_size: 0,
            first_attribute: mem::size_of::<Self>() as u32,
            attributes_size: 0,
            parent_inode,
            creation_time: now,
            modification_time: now,
            mft_change_time: now,
            access_time: now,
            packed_attrs: 0,
            checksum: 0,
        }
    }

    /// Check if this record is valid
    pub fn is_valid(&self) -> bool {
        self.signature == MFT_SIGNATURE
    }

    /// Check if this record is allocated
    pub fn is_allocated(&self) -> bool {
        self.flags & MFT_FLAG_ALLOCATED != 0
    }

    /// Check if this record is a directory
    pub fn is_directory(&self) -> bool {
        self.flags & MFT_FLAG_DIRECTORY != 0
    }

    /// Recompute record checksum
    pub fn recompute_checksum(&mut self, record_data: &[u8; VLFS_MFT_RECORD_SIZE as usize]) {
        self.checksum = 0;
        // Checksum everything except the checksum field itself
        let before = &record_data[..80];
        let after = &record_data[84..];
        let mut crc: u32 = 0xFFFFFFFF;
        for &byte in before.iter().chain(after.iter()) {
            crc ^= byte as u32;
            for _ in 0..8 {
                if crc & 1 != 0 {
                    crc = (crc >> 1) ^ 0x82F63B78;
                } else {
                    crc >>= 1;
                }
            }
        }
        self.checksum = crc ^ 0xFFFFFFFF;
    }
}

/// In-memory MFT record (full 1024-byte record)
pub struct MftRecord {
    pub header: MftRecordHeader,
    pub raw: [u8; VLFS_MFT_RECORD_SIZE as usize],
}

impl MftRecord {
    /// Create a new empty record
    pub fn new(inode: u64, flags: u16, parent_inode: u64) -> Self {
        let mut raw = [0u8; VLFS_MFT_RECORD_SIZE as usize];
        let header = MftRecordHeader::new(inode, flags, parent_inode);
        // Copy header into raw
        let header_bytes = unsafe {
            core::slice::from_raw_parts(
                &header as *const MftRecordHeader as *const u8,
                mem::size_of::<MftRecordHeader>(),
            )
        };
        raw[..header_bytes.len()].copy_from_slice(header_bytes);
        Self { header, raw }
    }

    /// Parse from raw bytes
    pub fn from_raw(raw: &[u8; VLFS_MFT_RECORD_SIZE as usize]) -> Option<Self> {
        let header = unsafe { core::ptr::read_volatile(raw.as_ptr() as *const MftRecordHeader) };
        if !header.is_valid() {
            return None;
        }
        Some(Self { header, raw: *raw })
    }

    /// Get the attribute area (after header)
    pub fn attribute_area(&self) -> &[u8] {
        let start = self.header.first_attribute as usize;
        &self.raw[start..]
    }

    /// Get mutable attribute area
    pub fn attribute_area_mut(&mut self) -> &mut [u8] {
        let start = self.header.first_attribute as usize;
        &mut self.raw[start..]
    }

    /// Find first attribute of given type
    pub fn find_attribute(&self, attr_type: u32) -> Option<(usize, AttrHeader)> {
        let mut offset = self.header.first_attribute as usize;
        let end = self.header.first_attribute as usize + self.header.attributes_size as usize;

        while offset + mem::size_of::<AttrHeader>() <= end
            && offset + mem::size_of::<AttrHeader>() <= self.raw.len()
        {
            let attr = unsafe {
                core::ptr::read_volatile(self.raw[offset..].as_ptr() as *const AttrHeader)
            };
            if attr.attr_type == ATTR_END || attr.length == 0 {
                return None;
            }
            if attr.attr_type == attr_type {
                return Some((offset, attr));
            }
            offset += attr.length as usize;
            offset = (offset + 7) & !7; // 8-byte align
        }
        None
    }

    /// Find all attributes of given type
    pub fn find_all_attributes(&self, attr_type: u32) -> alloc::vec::Vec<(usize, AttrHeader)> {
        let mut result = alloc::vec::Vec::new();
        let mut offset = self.header.first_attribute as usize;
        let end = self.header.first_attribute as usize + self.header.attributes_size as usize;

        while offset + mem::size_of::<AttrHeader>() <= end
            && offset + mem::size_of::<AttrHeader>() <= self.raw.len()
        {
            let attr = unsafe {
                core::ptr::read_volatile(self.raw[offset..].as_ptr() as *const AttrHeader)
            };
            if attr.attr_type == ATTR_END || attr.length == 0 {
                break;
            }
            if attr.attr_type == attr_type {
                result.push((offset, attr));
            }
            offset += attr.length as usize;
            offset = (offset + 7) & !7;
        }
        result
    }

    /// Append an attribute to the record
    /// Returns the offset where the attribute was placed, or None if no space
    pub fn append_attribute(&mut self, attr_data: &[u8]) -> Option<usize> {
        let start = self.header.first_attribute as usize + self.header.attributes_size as usize;
        let aligned_start = (start + 7) & !7;
        let end = aligned_start + attr_data.len();

        if end > VLFS_MFT_RECORD_SIZE as usize {
            return None; // No space
        }

        self.raw[aligned_start..end].copy_from_slice(attr_data);
        self.header.attributes_size = (end - self.header.first_attribute as usize) as u32;
        self.header.record_size = VLFS_MFT_RECORD_SIZE;

        // Update raw header
        let header_bytes = unsafe {
            core::slice::from_raw_parts(
                &self.header as *const MftRecordHeader as *const u8,
                mem::size_of::<MftRecordHeader>(),
            )
        };
        self.raw[..header_bytes.len()].copy_from_slice(header_bytes);

        Some(aligned_start)
    }

    /// Serialize header back to raw
    pub fn sync_header(&mut self) {
        let header_bytes = unsafe {
            core::slice::from_raw_parts(
                &self.header as *const MftRecordHeader as *const u8,
                mem::size_of::<MftRecordHeader>(),
            )
        };
        self.raw[..header_bytes.len()].copy_from_slice(header_bytes);
        self.header.recompute_checksum(&self.raw);
        let header_bytes = unsafe {
            core::slice::from_raw_parts(
                &self.header as *const MftRecordHeader as *const u8,
                mem::size_of::<MftRecordHeader>(),
            )
        };
        self.raw[..header_bytes.len()].copy_from_slice(header_bytes);
    }
}

/// MFT manager (tracks MFT state)
pub struct MftManager {
    /// Next free inode number (hint)
    next_free_inode: AtomicU64,
    /// Total MFT records
    total_records: u64,
    /// Records per block
    records_per_block: u32,
}

impl MftManager {
    pub fn new(total_records: u64, records_per_block: u32) -> Self {
        Self {
            next_free_inode: AtomicU64::new(3), // 0=unused, 1=bad, 2=root
            total_records,
            records_per_block,
        }
    }

    /// Block number in MFT area for given inode
    pub fn inode_to_mft_block(&self, inode: u64) -> u64 {
        inode / self.records_per_block as u64
    }

    /// Offset within MFT block for given inode
    pub fn inode_to_record_offset(&self, inode: u64) -> usize {
        (inode % self.records_per_block as u64) as usize * VLFS_MFT_RECORD_SIZE as usize
    }

    /// Allocate a new inode number
    pub fn allocate_inode(&self) -> u64 {
        let inode = self.next_free_inode.fetch_add(1, Ordering::Relaxed);
        if inode >= self.total_records {
            // Wrap around - caller must check availability
            0
        } else {
            inode
        }
    }

    /// Mark an inode as free (for future use)
    pub fn free_inode(&self, _inode: u64) {
        // In a full implementation, this would update a bitmap
        // For now, inodes are never truly freed
    }
}
