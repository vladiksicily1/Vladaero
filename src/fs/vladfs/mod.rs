/// # VladFS - VladOS File System
///
/// NT-like filesystem with MFT, B-tree indices, journal, and data runs.
///
/// Architecture:
///   Superblock (sector 0)
///   MFT (Master File Table) - inodes stored in fixed 1024-byte records
///   Journal - transaction log for crash recovery
///   Data area - block bitmap + file data
///
/// Each MFT record contains attributes:
///   $STANDARD_INFORMATION (0x10) - times, permissions
///   $FILE_NAME (0x30) - name, parent inode
///   $DATA (0x80) - file content (resident or non-resident)
///   $INDEX_ROOT (0x90) - directory entries (inline for small dirs)
///   $INDEX_ALLOCATION (0xA0) - large directory B-tree

pub mod superblock;
pub mod mft;
pub mod attributes;
pub mod btree;
pub mod block_alloc;
pub mod journal;
pub mod driver;

use core::mem;
use core::sync::atomic::{AtomicBool, AtomicU64, Ordering};

use self::superblock::{Superblock, SuperblockState, VLFS_BLOCK_SIZE, VLFS_MFT_RECORD_SIZE};
use self::mft::{MftRecord, MftRecordHeader, MFT_SIGNATURE, MFT_FLAG_ALLOCATED, MFT_FLAG_DIRECTORY};
use self::attributes::*;
use self::btree::*;
use self::block_alloc::BlockAllocator;
use self::journal::JournalManager;

/// VladFS filesystem driver
pub struct VladsFs {
    /// Superblock state
    pub sb_state: SuperblockState,
    /// Journal manager
    pub journal: JournalManager,
    /// Block allocator
    pub allocator: BlockAllocator,
    /// MFT manager
    pub mft: mft::MftManager,
    /// Partition start LBA
    pub partition_start: u64,
    /// Block size
    pub block_size: u32,
    /// Sectors per block
    pub sectors_per_block: u32,
}

impl VladsFs {
    /// Open/initialize VladFS on a partition
    pub fn open(
        partition_start_lba: u64,
        partition_sectors: u64,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
    ) -> Result<Self, ()> {
        let sector_size = 512u64;

        // Read superblock (first block)
        let mut sb_buf = alloc::vec![0u8; VLFS_BLOCK_SIZE as usize];
        read_sector(partition_start_lba, VLFS_BLOCK_SIZE as u32 / sector_size as u32, &mut sb_buf)?;

        let sb = unsafe { core::ptr::read_volatile(sb_buf.as_ptr() as *const Superblock) };

        if !sb.validate() {
            return Err(());
        }

        let sb_state = SuperblockState::new(sb, partition_start_lba);
        let sectors_per_block = sb.block_size / 512;

        // Initialize journal
        let journal = JournalManager::load(
            sb.journal_start,
            sb.journal_blocks,
            sb.block_size,
            &mut |block, buf| {
                let lba = partition_start_lba + block * sectors_per_block as u64;
                read_sector(lba, sectors_per_block, buf)
            },
        )?;

        // Initialize block allocator
        let mut allocator = BlockAllocator::load(
            sb.data_start,
            sb.data_blocks,
            sb.block_size,
            &mut |block, buf| {
                let lba = partition_start_lba + block * sectors_per_block as u64;
                read_sector(lba, sectors_per_block, buf)
            },
        )?;

        // Initialize MFT manager
        let records_per_block = sb.block_size / VLFS_MFT_RECORD_SIZE;
        let total_mft_records = sb.mft_blocks * records_per_block as u64;
        let mft = mft::MftManager::new(total_mft_records, records_per_block);

        Ok(Self {
            sb_state,
            journal,
            allocator,
            mft,
            partition_start: partition_start_lba,
            block_size: sb.block_size,
            sectors_per_block,
        })
    }

    /// Create a new VladFS filesystem (format)
    pub fn format(
        partition_start_lba: u64,
        partition_sectors: u64,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
    ) -> Result<Self, ()> {
        let sector_size = 512u64;
        let total_blocks = partition_sectors * sector_size / VLFS_BLOCK_SIZE as u64;
        let sectors_per_block = VLFS_BLOCK_SIZE as u64 / sector_size;

        // Layout:
        // Block 0: Superblock
        // Blocks 1..=mft_blocks: MFT
        // Blocks after MFT: Journal
        // Remaining: Data area

        let mft_blocks: u64 = 256; // 256 blocks * (4096/1024) = 1024 records
        let journal_blocks: u64 = 64; // 64 blocks journal
        let mft_start: u64 = 1;
        let journal_start = mft_start + mft_blocks;
        let data_start = journal_start + journal_blocks;
        let data_blocks = total_blocks - data_start;

        // Create superblock
        let sb = Superblock::new(
            total_blocks,
            mft_start,
            mft_blocks,
            journal_start,
            journal_blocks,
            data_start,
            data_blocks,
            2, // root inode
        );

        // Write superblock
        let mut sb_buf = alloc::vec![0u8; VLFS_BLOCK_SIZE as usize];
        let sb_bytes = unsafe {
            core::slice::from_raw_parts(
                &sb as *const Superblock as *const u8,
                mem::size_of::<Superblock>(),
            )
        };
        sb_buf[..sb_bytes.len()].copy_from_slice(sb_bytes);
        write_sector(partition_start_lba, sectors_per_block as u32, &sb_buf)?;

        // Clear MFT area
        let mut zero_buf = alloc::vec![0u8; VLFS_BLOCK_SIZE as usize];
        for i in 0..mft_blocks {
            let lba = partition_start_lba + (mft_start + i) * sectors_per_block;
            write_sector(lba, sectors_per_block as u32, &zero_buf)?;
        }

        // Clear journal area
        for i in 0..journal_blocks {
            let lba = partition_start_lba + (journal_start + i) * sectors_per_block;
            write_sector(lba, sectors_per_block as u32, &zero_buf)?;
        }

        // Create root directory (inode 2)
        let sb_state = SuperblockState::new(sb, partition_start_lba);
        let records_per_block = VLFS_BLOCK_SIZE / VLFS_MFT_RECORD_SIZE;
        let total_mft_records = mft_blocks * records_per_block as u64;
        let mft = mft::MftManager::new(total_mft_records, records_per_block);

        // Write root MFT record
        let mut root_record = MftRecord::new(2, MFT_FLAG_ALLOCATED | MFT_FLAG_DIRECTORY, 2);
        root_record.header.parent_inode = 2; // Root is its own parent

        // Add $STANDARD_INFORMATION
        let std_info = StandardInfo::new(FILE_ATTRIBUTE_DIRECTORY);
        let si_bytes = std_info.to_bytes();
        let si_attr = AttrHeader::new(ATTR_STANDARD_INFO, si_bytes.len() as u32);
        let mut si_buf = alloc::vec![0u8; ((mem::size_of::<AttrHeader>() + si_bytes.len() + 7) & !7)];
        let si_hdr_bytes = unsafe {
            core::slice::from_raw_parts(
                &si_attr as *const AttrHeader as *const u8,
                mem::size_of::<AttrHeader>(),
            )
        };
        si_buf[..si_hdr_bytes.len()].copy_from_slice(si_hdr_bytes);
        si_buf[mem::size_of::<AttrHeader>()..mem::size_of::<AttrHeader>() + si_bytes.len()].copy_from_slice(&si_bytes);
        root_record.append_attribute(&si_buf);

        // Add $FILE_NAME for "."
        let dot_attr = FileNameAttr::new(2, ".", FILE_ATTRIBUTE_DIRECTORY, 0, 0, NS_WIN32);
        root_record.append_attribute(&dot_attr);

        // Add $INDEX_ROOT (empty directory)
        let index_root = build_index_root(&[]);
        root_record.append_attribute(&index_root);

        root_record.sync_header();

        // Write root record to MFT
        let root_mft_block = mft.inode_to_mft_block(2);
        let root_mft_offset = mft.inode_to_record_offset(2);
        let mut mft_block_buf = alloc::vec![0u8; VLFS_BLOCK_SIZE as usize];
        let lba = partition_start_lba + (mft_start + root_mft_block) * sectors_per_block;
        read_sector(lba, sectors_per_block as u32, &mut mft_block_buf)?;

        mft_block_buf[root_mft_offset..root_mft_offset + VLFS_MFT_RECORD_SIZE as usize]
            .copy_from_slice(&root_record.raw);

        write_sector(lba, sectors_per_block as u32, &mft_block_buf)?;

        // Initialize block allocator
        let mut allocator = BlockAllocator::new(data_blocks, VLFS_BLOCK_SIZE);

        // Initialize journal
        let journal = JournalManager::new(journal_start, journal_blocks);

        Ok(Self {
            sb_state,
            journal,
            allocator,
            mft,
            partition_start: partition_start_lba,
            block_size: VLFS_BLOCK_SIZE,
            sectors_per_block: sectors_per_block as u32,
        })
    }

    /// Read a block from the filesystem
    pub fn read_block(
        &self,
        block: u64,
        buf: &mut [u8],
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
    ) -> Result<(), ()> {
        let lba = self.partition_start + block * self.sectors_per_block as u64;
        read_sector(lba, self.sectors_per_block, buf)
    }

    /// Write a block to the filesystem
    pub fn write_block(
        &self,
        block: u64,
        buf: &[u8],
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
    ) -> Result<(), ()> {
        let lba = self.partition_start + block * self.sectors_per_block as u64;
        write_sector(lba, self.sectors_per_block, buf)
    }

    /// Read an MFT record by inode
    pub fn read_mft_record(
        &self,
        inode: u64,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
    ) -> Result<MftRecord, ()> {
        let block_in_mft = self.mft.inode_to_mft_block(inode);
        let record_offset = self.mft.inode_to_record_offset(inode);

        let mut block_buf = alloc::vec![0u8; self.block_size as usize];
        let mft_block = self.sb_state.sb.mft_start + block_in_mft;
        self.read_block(mft_block, &mut block_buf, read_sector)?;

        let mut record_raw = [0u8; VLFS_MFT_RECORD_SIZE as usize];
        record_raw.copy_from_slice(&block_buf[record_offset..record_offset + VLFS_MFT_RECORD_SIZE as usize]);

        MftRecord::from_raw(&record_raw).ok_or(())
    }

    /// Write an MFT record
    pub fn write_mft_record(
        &mut self,
        record: &MftRecord,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
    ) -> Result<(), ()> {
        let block_in_mft = self.mft.inode_to_mft_block(record.header.inode);
        let record_offset = self.mft.inode_to_record_offset(record.header.inode);

        let mut block_buf = alloc::vec![0u8; self.block_size as usize];
        let mft_block = self.sb_state.sb.mft_start + block_in_mft;

        // Read existing block first (may contain other records)
        let mut tmp = alloc::vec![0u8; self.block_size as usize];
        self.read_block(mft_block, &mut tmp, read_sector)?;

        tmp[record_offset..record_offset + VLFS_MFT_RECORD_SIZE as usize]
            .copy_from_slice(&record.raw);

        self.write_block(mft_block, &tmp, write_sector)?;
        self.sb_state.mark_dirty();

        Ok(())
    }

    /// Create a new file or directory
    pub fn create(
        &mut self,
        parent_inode: u64,
        name: &str,
        is_directory: bool,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
    ) -> Result<u64, ()> {
        // Read parent record
        let parent = self.read_mft_record(parent_inode, read_sector)?;

        if !parent.header.is_directory() {
            return Err(());
        }

        // Check if name already exists
        if find_in_index(&parent, name).is_some() {
            return Err(());
        }

        // Allocate new inode
        let new_inode = self.mft.allocate_inode();
        if new_inode == 0 {
            return Err(()); // No free inodes
        }

        let flags = if is_directory {
            MFT_FLAG_ALLOCATED | MFT_FLAG_DIRECTORY
        } else {
            MFT_FLAG_ALLOCATED
        };

        // Create new MFT record
        let mut record = MftRecord::new(new_inode, flags, parent_inode);

        // Add $STANDARD_INFORMATION
        let file_attrs = if is_directory {
            FILE_ATTRIBUTE_DIRECTORY
        } else {
            FILE_ATTRIBUTE_ARCHIVE
        };
        let std_info = StandardInfo::new(file_attrs);
        let si_bytes = std_info.to_bytes();
        let si_attr = AttrHeader::new(ATTR_STANDARD_INFO, si_bytes.len() as u32);
        let mut si_buf = alloc::vec![0u8; ((mem::size_of::<AttrHeader>() + si_bytes.len() + 7) & !7)];
        let si_hdr_bytes = unsafe {
            core::slice::from_raw_parts(
                &si_attr as *const AttrHeader as *const u8,
                mem::size_of::<AttrHeader>(),
            )
        };
        si_buf[..si_hdr_bytes.len()].copy_from_slice(si_hdr_bytes);
        si_buf[mem::size_of::<AttrHeader>()..mem::size_of::<AttrHeader>() + si_bytes.len()].copy_from_slice(&si_bytes);
        record.append_attribute(&si_buf);

        // Add $FILE_NAME
        let fname = FileNameAttr::new(
            parent_inode,
            name,
            file_attrs,
            0,
            0,
            NS_WIN32,
        );
        record.append_attribute(&fname);

        // Add $INDEX_ROOT for directories
        if is_directory {
            let index_root = build_index_root(&[]);
            record.append_attribute(&index_root);
        }

        record.sync_header();

        // Write new record
        self.write_mft_record(&record, read_sector, write_sector)?;

        // Add to parent directory index
        let new_entry = IndexEntry {
            inode: new_inode,
            mft_record_number: new_inode,
            name: alloc::string::String::from(name),
            namespace: NS_WIN32,
        };

        let updated_index = add_to_index(&parent, new_entry).map_err(|_| ())?;

        // Update parent record with new index
        let mut updated_parent = parent;
        // Remove old $INDEX_ROOT and replace with new one
        if let Some((offset, attr)) = updated_parent.find_attribute(ATTR_INDEX_ROOT) {
            let attr_len = attr.length as usize;
            let first_attr = updated_parent.header.first_attribute as usize;
            {
                let area = updated_parent.attribute_area_mut();
                let start = offset - first_attr;
                let end = start + attr_len;
                let remaining = area.len() - end;

                // Shift remaining data
                if remaining > 0 {
                    let mut temp = alloc::vec![0u8; remaining];
                    temp.copy_from_slice(&area[end..end + remaining]);
                    area[start..start + remaining].copy_from_slice(&temp);
                }
            }
            updated_parent.header.attributes_size -= attr_len as u32;
            updated_parent.sync_header();
            updated_parent.append_attribute(&updated_index);
        }

        self.write_mft_record(&updated_parent, read_sector, write_sector)?;

        Ok(new_inode)
    }

    /// Open a file/directory by path from root
    pub fn open_path(
        &mut self,
        path: &str,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
    ) -> Result<u64, ()> {
        let mut current_inode = self.sb_state.sb.root_inode;

        for component in path.split('/') {
            if component.is_empty() || component == "." {
                continue;
            }
            if component == ".." {
                let record = self.read_mft_record(current_inode, read_sector)?;
                current_inode = record.header.parent_inode;
                continue;
            }

            let record = self.read_mft_record(current_inode, read_sector)?;
            let child = find_in_index(&record, component).ok_or(())?;
            current_inode = child;
        }

        Ok(current_inode)
    }

    /// Read file data
    pub fn read_file(
        &mut self,
        inode: u64,
        offset: u64,
        buf: &mut [u8],
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
    ) -> Result<usize, ()> {
        let record = self.read_mft_record(inode, read_sector)?;

        if record.header.is_directory() {
            return Err(());
        }

        // Find $DATA attribute
        if let Some((attr_offset, attr_header)) = record.find_attribute(ATTR_DATA) {
            if attr_header.non_resident == 0 {
                // Resident data
                let data_offset = attr_offset + mem::size_of::<AttrHeader>();
                let data_size = attr_header.resident_size() as u64;

                if offset >= data_size {
                    return Ok(0);
                }

                let copy_size = core::cmp::min(buf.len() as u64, data_size - offset) as usize;
                let src_start = data_offset + offset as usize;
                buf[..copy_size].copy_from_slice(
                    &record.raw[src_start..src_start + copy_size]
                );
                return Ok(copy_size);
            } else {
                // Non-resident data - parse data runs
                let nr_offset = attr_offset + mem::size_of::<AttrHeader>();
                if nr_offset + mem::size_of::<NonResidentExt>() > record.raw.len() {
                    return Err(());
                }

                let nr = unsafe {
                    core::ptr::read_volatile(record.raw[nr_offset..].as_ptr() as *const NonResidentExt)
                };

                let data_size = nr.data_size;
                if offset >= data_size {
                    return Ok(0);
                }

                // Parse data runs
                let runs_offset = nr_offset + mem::size_of::<NonResidentExt>() + nr.data_runs_offset as usize - mem::size_of::<AttrHeader>() as usize;
                let runs = parse_data_runs(&record.raw, runs_offset);

                // Read data using runs
                let bytes_to_read = core::cmp::min(buf.len() as u64, data_size - offset) as usize;
                let cluster_size = self.block_size as u64;
                let mut remaining = bytes_to_read;
                let mut buf_pos = 0;
                let mut file_off = offset;

                for run in &runs {
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

                    // Read from disk
                    let blocks = (can_read as u64 + self.block_size as u64 - 1) / self.block_size as u64;
                    let mut block_buf = alloc::vec![0u8; (blocks * self.block_size as u64) as usize];

                    for b in 0..blocks {
                        let data_block = self.sb_state.sb.data_start + run.lcn + b;
                        self.read_block(data_block, &mut block_buf[b as usize * self.block_size as usize..], read_sector)?;
                    }

                    let copy_from = (read_from % self.block_size as u64) as usize;
                    buf[buf_pos..buf_pos + can_read]
                        .copy_from_slice(&block_buf[copy_from..copy_from + can_read]);

                    buf_pos += can_read;
                    remaining -= can_read;
                    file_off = 0;
                }

                return Ok(bytes_to_read);
            }
        }

        Ok(0)
    }

    /// Write file data
    pub fn write_file(
        &mut self,
        inode: u64,
        offset: u64,
        data: &[u8],
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
    ) -> Result<usize, ()> {
        let mut record = self.read_mft_record(inode, read_sector)?;

        if record.header.is_directory() {
            return Err(());
        }

        let end_offset = offset + data.len() as u64;

        // For simplicity, we support small files with resident data
        // For large files, we'd need full data run management
        if end_offset > 480 {
            // Too large for resident data - allocate non-resident
            return self.write_file_non_resident(inode, offset, data, read_sector, write_sector);
        }

        // Find or create $DATA attribute
        let std_info_offset = record.find_attribute(ATTR_STANDARD_INFO);
        let fname_offset = record.find_attribute(ATTR_FILE_NAME);

        // Rebuild record with updated data
        let mut new_record = MftRecord::new(
            record.header.inode,
            record.header.flags,
            record.header.parent_inode,
        );
        new_record.header.sequence = record.header.sequence + 1;

        // Copy $STANDARD_INFORMATION
        if let Some((off, attr)) = std_info_offset {
            let si_data = &record.raw[off..off + attr.length as usize];
            let mut si_buf = alloc::vec![0u8; ((attr.length as usize + 7) & !7)];
            si_buf[..attr.length as usize].copy_from_slice(si_data);
            new_record.append_attribute(&si_buf);

            // Update modification time
            let si_off = mem::size_of::<AttrHeader>();
            if si_off + mem::size_of::<StandardInfo>() <= si_buf.len() {
                let mut si = unsafe {
                    core::ptr::read_volatile(si_buf[si_off..].as_ptr() as *const StandardInfo)
                };
                si.touch();
                let si_bytes = si.to_bytes();
                si_buf[si_off..si_off + si_bytes.len()].copy_from_slice(&si_bytes);
            }
        }

        // Copy $FILE_NAME
        if let Some((off, attr)) = fname_offset {
            let fn_data = &record.raw[off..off + attr.length as usize];
            let mut fn_buf = alloc::vec![0u8; ((attr.length as usize + 7) & !7)];
            fn_buf[..attr.length as usize].copy_from_slice(fn_data);
            new_record.append_attribute(&fn_buf);
        }

        // Add new $DATA
        let data_attr = create_resident_data_attr(data);
        new_record.append_attribute(&data_attr);

        // Copy $INDEX_ROOT if present (shouldn't be for files, but just in case)
        if let Some((off, attr)) = record.find_attribute(ATTR_INDEX_ROOT) {
            let ir_data = &record.raw[off..off + attr.length as usize];
            let mut ir_buf = alloc::vec![0u8; ((attr.length as usize + 7) & !7)];
            ir_buf[..attr.length as usize].copy_from_slice(ir_data);
            new_record.append_attribute(&ir_buf);
        }

        new_record.header.data_size = end_offset;
        new_record.sync_header();

        self.write_mft_record(&new_record, read_sector, write_sector)?;

        Ok(data.len())
    }

    /// Write large file data (non-resident)
    fn write_file_non_resident(
        &mut self,
        inode: u64,
        offset: u64,
        data: &[u8],
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
    ) -> Result<usize, ()> {
        let cluster_size = self.block_size as u64;
        let clusters_needed = (data.len() as u64 + cluster_size - 1) / cluster_size;

        // Allocate blocks
        let start_block = self.allocator.allocate(clusters_needed)
            .ok_or(())?;

        // Write data to allocated blocks
        let mut data_offset = 0usize;
        for i in 0..clusters_needed {
            let data_block = self.sb_state.sb.data_start + start_block + i;
            let mut block_buf = alloc::vec![0u8; self.block_size as usize];

            let copy_size = core::cmp::min(cluster_size as usize, data.len() - data_offset);
            block_buf[..copy_size].copy_from_slice(&data[data_offset..data_offset + copy_size]);

            self.write_block(data_block, &block_buf, write_sector)?;
            data_offset += copy_size;
        }

        // Create data runs
        let data_runs = alloc::vec![attributes::DataRun {
            lcn: start_block,
            length: clusters_needed,
        }];

        // Build non-resident data attribute
        let data_attr = create_non_resident_data_attr(
            &data_runs,
            (offset + data.len() as u64),
            clusters_needed * cluster_size,
        );

        // Update MFT record
        let mut record = self.read_mft_record(inode, read_sector)?;

        // Rebuild with non-resident data
        let mut new_record = MftRecord::new(
            record.header.inode,
            record.header.flags,
            record.header.parent_inode,
        );
        new_record.header.sequence = record.header.sequence + 1;

        // Copy existing attributes except $DATA
        for attr_type in [ATTR_STANDARD_INFO, ATTR_FILE_NAME, ATTR_INDEX_ROOT] {
            if let Some((off, attr)) = record.find_attribute(attr_type) {
                let attr_data = &record.raw[off..off + attr.length as usize];
                let mut buf = alloc::vec![0u8; ((attr.length as usize + 7) & !7)];
                buf[..attr.length as usize].copy_from_slice(attr_data);
                new_record.append_attribute(&buf);
            }
        }

        new_record.append_attribute(&data_attr);
        new_record.header.data_size = offset + data.len() as u64;
        new_record.sync_header();

        self.write_mft_record(&new_record, read_sector, write_sector)?;

        Ok(data.len())
    }

    /// Delete a file or directory
    pub fn delete(
        &mut self,
        parent_inode: u64,
        name: &str,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
    ) -> Result<(), ()> {
        let parent = self.read_mft_record(parent_inode, read_sector)?;
        let child_inode = find_in_index(&parent, name).ok_or(())?;

        let child = self.read_mft_record(child_inode, read_sector)?;

        // For directories, check if empty
        if child.header.is_directory() {
            let entries = parse_index_root(&child);
            if !entries.is_empty() {
                return Err(()); // Directory not empty
            }
        }

        // Free data blocks if non-resident
        if let Some((attr_offset, attr_header)) = child.find_attribute(ATTR_DATA) {
            if attr_header.non_resident != 0 {
                let nr_offset = attr_offset + mem::size_of::<AttrHeader>();
                if nr_offset + mem::size_of::<NonResidentExt>() <= child.raw.len() {
                    let nr = unsafe {
                        core::ptr::read_volatile(child.raw[nr_offset..].as_ptr() as *const NonResidentExt)
                    };
                    let runs_offset = nr_offset + mem::size_of::<NonResidentExt>() + nr.data_runs_offset as usize - mem::size_of::<AttrHeader>() as usize;
                    let runs = parse_data_runs(&child.raw, runs_offset);

                    for run in &runs {
                        self.allocator.free(run.lcn, run.length);
                    }
                }
            }
        }

        // Mark MFT record as deleted
        let mut deleted_record = child;
        deleted_record.header.flags &= !MFT_FLAG_ALLOCATED;
        deleted_record.sync_header();
        self.write_mft_record(&deleted_record, read_sector, write_sector)?;

        // Remove from parent index
        let updated_parent = remove_from_index(&parent, name).map_err(|_| ())?;

        let mut updated_parent_record = parent;
        // Replace $INDEX_ROOT
        if let Some((offset, attr)) = updated_parent_record.find_attribute(ATTR_INDEX_ROOT) {
            let attr_len = attr.length as usize;
            let first_attr = updated_parent_record.header.first_attribute as usize;
            {
                let area = updated_parent_record.attribute_area_mut();
                let start = offset - first_attr;
                let end = start + attr_len;
                let remaining = area.len() - end;

                if remaining > 0 {
                    let mut temp = alloc::vec![0u8; remaining];
                    temp.copy_from_slice(&area[end..end + remaining]);
                    area[start..start + remaining].copy_from_slice(&temp);
                }
            }
            updated_parent_record.header.attributes_size -= attr_len as u32;
            updated_parent_record.sync_header();
            updated_parent_record.append_attribute(&updated_parent);
        }

        self.write_mft_record(&updated_parent_record, read_sector, write_sector)?;

        Ok(())
    }

    /// List directory entries
    pub fn list_directory(
        &mut self,
        inode: u64,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
    ) -> Result<alloc::vec::Vec<(u64, alloc::string::String, u32)>, ()> {
        let record = self.read_mft_record(inode, read_sector)?;

        if !record.header.is_directory() {
            return Err(());
        }

        let entries = parse_index_root(&record);
        let mut result = alloc::vec::Vec::new();

        for entry in entries {
            // Try to get attributes from child record
            let attrs = match self.read_mft_record(entry.inode, read_sector) {
                Ok(child) => {
                    if let Some((_, attr)) = child.find_attribute(ATTR_STANDARD_INFO) {
                        let si_offset = mem::size_of::<AttrHeader>();
                        if si_offset + mem::size_of::<StandardInfo>() <= child.raw.len() {
                            StandardInfo::from_bytes(&child.raw[si_offset..si_offset + mem::size_of::<StandardInfo>()])
                                .map(|si| si.file_attributes)
                                .unwrap_or(0)
                        } else {
                            0
                        }
                    } else {
                        0
                    }
                }
                Err(_) => 0,
            };

            result.push((entry.inode, entry.name, attrs));
        }

        Ok(result)
    }

    /// Flush journal and dirty data to disk
    pub fn flush(
        &mut self,
        write_sector: &mut dyn FnMut(u64, u32, &[u8]) -> Result<(), ()>,
        read_sector: &mut dyn FnMut(u64, u32, &mut [u8]) -> Result<(), ()>,
    ) -> Result<(), ()> {
        if self.allocator.is_dirty() {
            self.allocator.save(self.block_size, &mut |block, buf| {
                self.write_block(block, buf, write_sector)
            })?;
        }

        if self.journal.is_dirty() {
            // Inline journal flush to avoid borrow conflict
            self.journal.header.current_position = 1;
            self.journal.dirty.store(false, Ordering::Release);

            // Write updated journal header
            let mut header_buf = alloc::vec![0u8; self.block_size as usize];
            let header_bytes = unsafe {
                core::slice::from_raw_parts(
                    &self.journal.header as *const journal::JournalHeader as *const u8,
                    core::mem::size_of::<journal::JournalHeader>(),
                )
            };
            header_buf[..header_bytes.len()].copy_from_slice(header_bytes);
            self.write_block(self.journal.journal_start, &header_buf, write_sector)?;
        }

        if self.sb_state.is_dirty() {
            let mut sb = self.sb_state.sb;
            sb.recompute_checksum();
            let mut sb_buf = alloc::vec![0u8; self.block_size as usize];
            let sb_bytes = unsafe {
                core::slice::from_raw_parts(
                    &sb as *const Superblock as *const u8,
                    mem::size_of::<Superblock>(),
                )
            };
            sb_buf[..sb_bytes.len()].copy_from_slice(sb_bytes);
            self.write_block(0, &sb_buf, write_sector)?;
        }

        Ok(())
    }
}
