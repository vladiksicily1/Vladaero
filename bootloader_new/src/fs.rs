/// Full VladFS filesystem driver for bootloader
/// Read-only implementation for loading kernel and initrd
///
/// VladFS on-disk layout:
/// Superblock at partition start
/// MFT (Master File Table) - B-tree of inodes
/// Journal - transaction log for consistency
/// Data blocks - file content

use super::gpt::DiskRead;

/// Superblock signature
pub const VLFS_MAGIC: [u8; 4] = *b"VLFS";

/// Superblock (at partition offset 0, size = 1 sector = 512 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct Superblock {
    pub magic: [u8; 4],            // "VLFS"
    pub version: u32,              // Filesystem version (1)
    pub block_size: u32,           // Block size in bytes (4096)
    pub total_blocks: u64,         // Total blocks on partition
    pub mft_start: u64,            // MFT start block number
    pub mft_blocks: u64,           // MFT size in blocks
    pub journal_start: u64,        // Journal start block number
    pub journal_blocks: u64,       // Journal size in blocks
    pub data_start: u64,           // Data area start block number
    pub data_blocks: u64,          // Data area size in blocks
    pub root_inode: u64,           // Root directory inode number
    pub free_blocks: u64,          // Number of free blocks
    pub uuid: [u8; 16],            // Volume UUID
    pub volume_label: [u16; 32],   // Volume label (UTF-16LE)
    pub checksum: u32,             // Superblock checksum
    pub _reserved: [u8; 356],      // Pad to 512 bytes
}

/// MFT record header (each record is 1024 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct MftRecord {
    pub signature: [u8; 4],        // "FILE"
    pub inode: u64,                // Inode number
    pub sequence: u16,             // Sequence number (incremented on reuse)
    pub flags: u16,                // 0x01 = allocated, 0x02 = directory
    pub record_size: u32,          // Record size in bytes (1024)
    pub data_size: u64,            // File data size (0 for directories)
    pub first_attribute: u32,      // Offset to first attribute
    pub attributes_size: u32,      // Total attributes size
    pub parent_inode: u64,         // Parent directory inode
    pub creation_time: u64,        // Creation time (100ns since 1601)
    pub modification_time: u64,    // Last modification time
    pub mft_change_time: u64,      // Last MFT change time
    pub access_time: u64,          // Last access time
    pub packed_attrs: u32,         // Packed attributes
    pub checksum: u32,             // Record checksum
}

/// MFT attribute header
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct AttrHeader {
    pub attr_type: u32,            // Attribute type
    pub length: u32,               // Total length of attribute
    pub non_resident: u8,          // 0 = resident, 1 = non-resident
    pub name_length: u8,           // Name length (in UTF-16 characters)
    pub name_offset: u16,          // Offset to attribute name
    pub flags: u16,                // Attribute flags
    pub attribute_id: u16,         // Unique attribute ID
}

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

/// Standard Information attribute (resident, fixed layout)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct StdInfoAttr {
    pub creation_time: u64,
    pub modification_time: u64,
    pub mft_change_time: u64,
    pub access_time: u64,
    pub file_attributes: u32,      // FILE_ATTRIBUTE_DIRECTORY etc
    pub packed_permissions: u32,
}

pub const FILE_ATTRIBUTE_READONLY: u32 = 0x00000001;
pub const FILE_ATTRIBUTE_HIDDEN: u32 = 0x00000002;
pub const FILE_ATTRIBUTE_SYSTEM: u32 = 0x00000004;
pub const FILE_ATTRIBUTE_DIRECTORY: u32 = 0x00000010;
pub const FILE_ATTRIBUTE_ARCHIVE: u32 = 0x00000020;
pub const FILE_ATTRIBUTE_NORMAL: u32 = 0x00000080;

/// File Name attribute (resident, variable length)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct FileNameAttr {
    pub parent_inode: u64,         // Parent directory inode
    pub creation_time: u64,
    pub modification_time: u64,
    pub mft_change_time: u64,
    pub access_time: u64,
    pub allocated_size: u64,       // Allocated size (rounded up to cluster)
    pub real_size: u64,            // Actual file size
    pub file_attributes: u32,
    pub reparse_point: u32,        // Reparse point tag (0 = not reparse)
    pub name_length: u8,           // Name length in characters
    pub namespace: u8,             // 0=POSIX, 1=Win32, 2=DOS, 3=Win32+DOS
    // Followed by UTF-16LE name
}

/// Non-resident attribute header extension
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct NonResidentExt {
    pub lowest_vcn: u64,           // Lowest VCN covered
    pub highest_vcn: u64,          // Highest VCN covered
    pub data_runs_offset: u16,     // Offset to data runs
    pub compression_unit: u16,
    pub _reserved: u32,
    pub allocated_size: u64,
    pub data_size: u64,            // Actual data size
    pub initialized_size: u64,
}

/// Index Root attribute (for directories)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IndexRootAttr {
    pub attr_type: u32,            // Indexed attribute type (always ATTR_FILE_NAME = 0x30)
    pub collation_rule: u32,       // 0 = binary, 1 = file name
    pub index_block_size: u32,     // Size of index block (usually 4096)
    pub clusters_per_index: u8,    // Clusters per index block
    pub _reserved: [u8; 3],
    // Followed by Index Header
}

#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IndexHeader {
    pub flags: u32,                // 0 = small index, 1 = large index
    pub total_entries_size: u32,   // Total size of entries
    pub allocated_size: u32,       // Allocated size
    pub _padding: u32,
}

/// Index entry (within an index block)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IndexEntry {
    pub inode: u64,                // Inode this entry refers to
    pub mft_record_number: u64,    // MFT record number (for reference)
    pub entry_size: u16,           // Size of this entry
    pub name_length: u8,           // Name length
    pub name_namespace: u8,        // Namespace
    // Followed by UTF-16LE name
    // Followed by padding to 8-byte boundary
}

/// Index allocation entry (non-resident index)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IndexAllocAttr {
    pub _reserved: [u8; 8],
    pub vcn_start: u64,
    pub vcn_end: u64,
    // Followed by data runs for index blocks
}

/// Data run descriptor (for non-resident attributes)
#[derive(Debug, Clone, Copy)]
pub struct DataRun {
    pub lcn: u64,                  // Logical Cluster Number (start)
    pub length: u64,               // Length in clusters
}

/// Parse data runs from a byte buffer
fn parse_data_runs(buf: &[u8], offset: usize) -> alloc::vec::Vec<DataRun> {
    let mut runs = alloc::vec::Vec::new();
    let mut pos = offset;
    let mut current_lcn: i64 = 0;

    while pos < buf.len() {
        let run_header = buf[pos];
        if run_header == 0 {
            break; // End of data runs
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

/// File handle returned by open_file
pub struct FileHandle {
    pub inode: u64,
    pub size: u64,
    pub attributes: u32,
    pub data_runs: alloc::vec::Vec<DataRun>,
}

/// VladFS filesystem driver
pub struct VladFs<D> {
    disk: D,
    partition_start: u64,
    block_size: u32,
    sectors_per_block: u32,

    // MFT info
    mft_start_lba: u64,
    mft_record_size: u32,
    records_per_block: u32,

    // Superblock (cached)
    root_inode: u64,
}

impl<D: DiskRead> VladFs<D> {
    /// Open VladFS on a partition
    pub fn open(mut disk: D, partition: super::gpt::PartitionInfo) -> Result<Self, ()> {
        let sector_size = disk.sector_size() as u64;

        // Read superblock (first block of partition)
        let mut sb_buf = alloc::vec![0u8; 512]; // Superblock fits in one sector
        disk.read_sectors(partition.start_lba, 1, &mut sb_buf)
            .map_err(|_| ())?;

        let sb = unsafe { &*(sb_buf.as_ptr() as *const Superblock) };

        // Validate magic
        if sb.magic != VLFS_MAGIC {
            return Err(());
        }

        // Validate version
        if sb.version != 1 {
            return Err(());
        }

        // Validate block size (must be power of 2, >= 512)
        if sb.block_size < 512 || (sb.block_size & (sb.block_size - 1)) != 0 {
            return Err(());
        }

        let block_size = sb.block_size;
        let sectors_per_block = block_size / sector_size as u32;

        // Validate checksum
        let saved_checksum = sb.checksum;
        let mut sb_bytes = alloc::vec![0u8; 512];
        sb_bytes.copy_from_slice(&sb_buf);
        sb_bytes[480..484].copy_from_slice(&[0u8; 4]); // Zero checksum field
        let computed = super::gpt::crc32(&sb_bytes);
        if computed != saved_checksum {
            return Err(());
        }

        let mft_start_lba = partition.start_lba + sb.mft_start * sectors_per_block as u64;
        let mft_record_size = 1024u32;
        let records_per_block = block_size / mft_record_size;

        Ok(Self {
            disk,
            partition_start: partition.start_lba,
            block_size,
            sectors_per_block,
            mft_start_lba,
            mft_record_size,
            records_per_block,
            root_inode: sb.root_inode,
        })
    }

    /// Read a block from the partition
    fn read_block(&mut self, block_num: u64, buf: &mut [u8]) -> Result<(), ()> {
        let lba = self.partition_start + block_num * self.sectors_per_block as u64;
        self.disk
            .read_sectors(lba, self.sectors_per_block, buf)
            .map_err(|_| ())
    }

    /// Read an MFT record by inode number
    fn read_mft_record(&mut self, inode: u64, buf: &mut [u8]) -> Result<MftRecord, ()> {
        let block_in_mft = inode / self.records_per_block as u64;
        let record_in_block = inode % self.records_per_block as u64;

        // Read the block containing this MFT record
        let block_lba = self.mft_start_lba + block_in_mft * self.sectors_per_block as u64;
        self.disk
            .read_sectors(block_lba, self.sectors_per_block, buf)
            .map_err(|_| ())?;

        // Parse the record
        let offset = (record_in_block * self.mft_record_size as u64) as usize;
        if offset + core::mem::size_of::<MftRecord>() > buf.len() {
            return Err(());
        }

        let record = unsafe {
            core::ptr::read_volatile(buf[offset..].as_ptr() as *const MftRecord)
        };

        if &record.signature != b"FILE" {
            return Err(());
        }

        Ok(record)
    }

    /// Find a child entry in a directory inode by name
    fn find_child(&mut self, dir_inode: u64, name: &str) -> Result<u64, ()> {
        let mut block_buf = alloc::vec![0u8; self.block_size as usize];
        let record = self.read_mft_record(dir_inode, &mut block_buf)?;

        if record.flags & 0x02 == 0 {
            return Err(()); // Not a directory
        }

        let base_offset = (dir_inode % self.records_per_block as u64 * self.mft_record_size as u64) as usize;
        let mut offset = record.first_attribute as usize;

        while offset + core::mem::size_of::<AttrHeader>() <= base_offset + self.mft_record_size as usize {
            let abs = base_offset + offset;
            if abs + core::mem::size_of::<AttrHeader>() > block_buf.len() {
                break;
            }

            let attr = unsafe {
                core::ptr::read_volatile(block_buf[abs..].as_ptr() as *const AttrHeader)
            };

            if attr.attr_type == ATTR_END || attr.length == 0 {
                break;
            }

            if attr.attr_type == ATTR_INDEX_ROOT && attr.non_resident == 0 {
                // Parse index root
                let ir_offset = abs + core::mem::size_of::<AttrHeader>();
                if ir_offset + core::mem::size_of::<IndexRootAttr>() > block_buf.len() {
                    break;
                }

                let _ir = unsafe {
                    core::ptr::read_volatile(block_buf[ir_offset..].as_ptr() as *const IndexRootAttr)
                };

                let ih_offset = ir_offset + core::mem::size_of::<IndexRootAttr>();
                if ih_offset + core::mem::size_of::<IndexHeader>() > block_buf.len() {
                    break;
                }

                let ih = unsafe {
                    core::ptr::read_volatile(block_buf[ih_offset..].as_ptr() as *const IndexHeader)
                };

                if ih.flags & 1 == 0 {
                    // Small index - entries are inline
                    let entries_offset = ih_offset + core::mem::size_of::<IndexHeader>();
                    let entries_end = entries_offset + ih.total_entries_size as usize;

                    let mut eoff = entries_offset;
                    while eoff < entries_end && eoff + 16 <= block_buf.len() {
                        let entry = unsafe {
                            core::ptr::read_volatile(block_buf[eoff..].as_ptr() as *const IndexEntry)
                        };

                        if entry.entry_size == 0 {
                            break;
                        }

                        // Check if this entry matches the name
                        let name_start = eoff + 16;
                        let name_len = entry.name_length as usize;

                        if entry.inode != 0 && name_len == name.len() {
                            let mut matches = true;
                            for (i, ch) in name.chars().enumerate() {
                                let utf16_char = unsafe {
                                    core::ptr::read_volatile(
                                        block_buf[name_start + i * 2..].as_ptr() as *const u16,
                                    )
                                };
                                if utf16_char != ch as u16 {
                                    matches = false;
                                    break;
                                }
                            }
                            if matches {
                                return Ok(entry.inode);
                            }
                        }

                        let aligned_size = (entry.entry_size as usize + 7) & !7;
                        eoff += aligned_size;
                    }
                } else {
                    // Large index - index blocks are in non-resident attribute
                    // For bootloader, we skip this (complex B-tree traversal)
                    // TODO: Parse index allocation for large directories
                }
            }

            offset += attr.length as usize;
            offset = (offset + 7) & !7;
        }

        Err(())
    }

    /// Get file info (size, attributes, data runs) from inode
    fn get_file_info(&mut self, inode: u64) -> Result<FileHandle, ()> {
        let mut block_buf = alloc::vec![0u8; self.block_size as usize];
        let record = self.read_mft_record(inode, &mut block_buf)?;

        let base_offset = (inode % self.records_per_block as u64 * self.mft_record_size as u64) as usize;
        let mut offset = record.first_attribute as usize;
        let mut size = record.data_size;
        let mut attributes = 0u32;
        let mut data_runs = alloc::vec::Vec::new();
        let mut is_resident_data = false;
        let mut resident_data_size = 0u32;

        while offset + core::mem::size_of::<AttrHeader>() <= base_offset + self.mft_record_size as usize {
            let abs = base_offset + offset;
            if abs + core::mem::size_of::<AttrHeader>() > block_buf.len() {
                break;
            }

            let attr = unsafe {
                core::ptr::read_volatile(block_buf[abs..].as_ptr() as *const AttrHeader)
            };

            if attr.attr_type == ATTR_END || attr.length == 0 {
                break;
            }

            match attr.attr_type {
                ATTR_STANDARD_INFO => {
                    if attr.non_resident == 0 && attr.length >= 48 {
                        let si_offset = abs + core::mem::size_of::<AttrHeader>();
                        if si_offset + core::mem::size_of::<StdInfoAttr>() <= block_buf.len() {
                            let si = unsafe {
                                core::ptr::read_volatile(
                                    block_buf[si_offset..].as_ptr() as *const StdInfoAttr,
                                )
                            };
                            attributes = si.file_attributes;
                        }
                    }
                }
                ATTR_DATA => {
                    if attr.non_resident == 0 {
                        // Resident data - size is at offset 4 in the value header
                        let val_offset = abs + core::mem::size_of::<AttrHeader>();
                        if val_offset + 8 <= block_buf.len() {
                            resident_data_size = unsafe {
                                core::ptr::read_volatile(
                                    block_buf[val_offset..].as_ptr() as *const u32,
                                )
                            };
                            is_resident_data = true;
                        }
                    } else {
                        // Non-resident data - parse data runs
                        let nr_offset = abs + core::mem::size_of::<AttrHeader>();
                        if nr_offset + core::mem::size_of::<NonResidentExt>() <= block_buf.len() {
                            let nr = unsafe {
                                core::ptr::read_volatile(
                                    block_buf[nr_offset..].as_ptr() as *const NonResidentExt,
                                )
                            };
                            size = nr.data_size;

                            // Parse data runs
                            let runs_from_attr = abs + attr.name_offset as usize;
                            if runs_from_attr < abs + attr.length as usize {
                                data_runs = parse_data_runs(&block_buf, runs_from_attr);
                            }
                        }
                    }
                }
                _ => {}
            }

            offset += attr.length as usize;
            offset = (offset + 7) & !7;
        }

        if is_resident_data {
            size = resident_data_size as u64;
        }

        Ok(FileHandle {
            inode,
            size,
            attributes,
            data_runs,
        })
    }

    /// Open a file by path (e.g., "boot/vladkern.exe")
    pub fn open_file(&mut self, path: &str) -> Result<FileHandle, ()> {
        let mut current_inode = self.root_inode;

        for component in path.split('/') {
            if component.is_empty() {
                continue;
            }
            current_inode = self.find_child(current_inode, component)?;
        }

        self.get_file_info(current_inode)
    }

    /// Read data from file at offset
    pub fn read_at(
        &mut self,
        file: &FileHandle,
        offset: u64,
        buf: &mut [u8],
    ) -> Result<usize, ()> {
        if offset >= file.size {
            return Ok(0);
        }

        let bytes_to_read = core::cmp::min(buf.len() as u64, file.size - offset) as usize;
        let mut remaining = bytes_to_read;
        let mut buf_pos = 0;
        let mut file_off = offset;

        let cluster_size = self.block_size as u64;

        for run in &file.data_runs {
            if remaining == 0 {
                break;
            }

            let run_start_byte = run.lcn * cluster_size;
            let run_size_byte = run.length * cluster_size;

            if file_off >= run_size_byte {
                file_off -= run_size_byte;
                continue;
            }

            let read_from = run_start_byte + file_off;
            let can_read = core::cmp::min(remaining as u64, run_size_byte - file_off) as usize;

            // Read sector-by-sector from disk
            let sector_size = self.disk.sector_size() as u64;
            let start_lba = self.partition_start + read_from / sector_size;

            // Handle partial sector reads
            let partial_offset = (read_from % sector_size) as usize;
            let total_read = can_read + partial_offset;
            let sectors = (total_read as u64 + sector_size - 1) / sector_size;

            if partial_offset > 0 || (can_read as u64 % sector_size != 0 && can_read > 0) {
                // Need sector-aligned reads
                let mut sector_buf = alloc::vec![0u8; (sectors * sector_size) as usize];
                self.disk
                    .read_sectors(start_lba, sectors as u32, &mut sector_buf)
                    .map_err(|_| ())?;

                let copy_from = partial_offset;
                let copy_size = core::cmp::min(can_read, sector_buf.len() - copy_from);
                buf[buf_pos..buf_pos + copy_size]
                    .copy_from_slice(&sector_buf[copy_from..copy_from + copy_size]);

                buf_pos += copy_size;
                remaining -= copy_size;
            } else {
                // Aligned read
                let sectors = (can_read as u64) / sector_size;
                if sectors > 0 {
                    self.disk
                        .read_sectors(start_lba, sectors as u32, &mut buf[buf_pos..buf_pos + can_read])
                        .map_err(|_| ())?;
                    buf_pos += can_read;
                    remaining -= can_read;
                }
            }

            file_off = 0;
        }

        Ok(bytes_to_read)
    }

    /// Read entire file into a new Vec
    pub fn read_file(&mut self, path: &str) -> Result<alloc::vec::Vec<u8>, ()> {
        let file = self.open_file(path)?;
        let size = file.size as usize;
        if size == 0 {
            return Err(());
        }
        let mut buf = alloc::vec![0u8; size];
        self.read_at(&file, 0, &mut buf)?;
        Ok(buf)
    }
}
