/// # VladFS Superblock
///
/// On-disk superblock at partition offset 0 (sector 0).
/// Magic: "VLFS", version 1.0, block size 4096.
///
/// Layout:
///   [0..4]     magic: "VLFS"
///   [4..8]     version: u32 LE
///   [8..12]    block_size: u32 LE
///   [12..20]   total_blocks: u64 LE
///   [20..28]   mft_start: u64 LE
///   [28..36]   mft_blocks: u64 LE
///   [36..44]   journal_start: u64 LE
///   [44..52]   journal_blocks: u64 LE
///   [52..60]   data_start: u64 LE
///   [60..68]   data_blocks: u64 LE
///   [68..76]   root_inode: u64 LE
///   [76..84]   free_blocks: u64 LE
///   [84..100]  uuid: [u8; 16]
///   [100..164] volume_label: [u16; 32] LE
///   [164..168] checksum: u32 LE
///   [168..512] reserved

use core::sync::atomic::{AtomicBool, AtomicU64, Ordering};

use crate::mm::{self, SpinLock};

/// Superblock constants
pub const VLFS_MAGIC: [u8; 4] = *b"VLFS";
pub const VLFS_VERSION: u32 = 1;
pub const VLFS_BLOCK_SIZE: u32 = 4096;
pub const VLFS_MFT_RECORD_SIZE: u32 = 1024;
pub const VLFS_SECTOR_SIZE: u32 = 512;

/// On-disk superblock (512 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct Superblock {
    pub magic: [u8; 4],
    pub version: u32,
    pub block_size: u32,
    pub total_blocks: u64,
    pub mft_start: u64,
    pub mft_blocks: u64,
    pub journal_start: u64,
    pub journal_blocks: u64,
    pub data_start: u64,
    pub data_blocks: u64,
    pub root_inode: u64,
    pub free_blocks: u64,
    pub uuid: [u8; 16],
    pub volume_label: [u16; 32],
    pub checksum: u32,
    pub _reserved: [u8; 344],
}

impl Superblock {
    /// Create a new superblock for formatting
    pub fn new(
        total_blocks: u64,
        mft_start: u64,
        mft_blocks: u64,
        journal_start: u64,
        journal_blocks: u64,
        data_start: u64,
        data_blocks: u64,
        root_inode: u64,
    ) -> Self {
        let mut sb = Superblock {
            magic: VLFS_MAGIC,
            version: VLFS_VERSION,
            block_size: VLFS_BLOCK_SIZE,
            total_blocks,
            mft_start,
            mft_blocks,
            journal_start,
            journal_blocks,
            data_start,
            data_blocks,
            root_inode,
            free_blocks: data_blocks,
            uuid: [0u8; 16],
            volume_label: [0u16; 32],
            checksum: 0,
            _reserved: [0u8; 344],
        };
        // Generate a simple UUID (placeholder)
        sb.uuid[0..8].copy_from_slice(&total_blocks.to_le_bytes());
        sb.uuid[8..16].copy_from_slice(&mft_start.to_le_bytes());
        // Set label "VladOS"
        let label = b"VladOS";
        for (i, &b) in label.iter().enumerate() {
            if i < 32 {
                sb.volume_label[i] = b as u16;
            }
        }
        sb.recompute_checksum();
        sb
    }

    /// Validate the superblock
    pub fn validate(&self) -> bool {
        if self.magic != VLFS_MAGIC {
            return false;
        }
        if self.version != VLFS_VERSION {
            return false;
        }
        if self.block_size < 512 || (self.block_size & (self.block_size - 1)) != 0 {
            return false;
        }
        if self.total_blocks == 0 {
            return false;
        }
        if self.mft_start + self.mft_blocks > self.total_blocks {
            return false;
        }
        if self.journal_start + self.journal_blocks > self.total_blocks {
            return false;
        }
        if self.data_start + self.data_blocks > self.total_blocks {
            return false;
        }
        // Verify checksum
        let mut sb_copy = *self;
        sb_copy.checksum = 0;
        let bytes = unsafe {
            core::slice::from_raw_parts(
                &sb_copy as *const Self as *const u8,
                core::mem::size_of::<Superblock>(),
            )
        };
        let computed = crc32(bytes);
        computed == self.checksum
    }

    /// Recompute checksum
    pub fn recompute_checksum(&mut self) {
        self.checksum = 0;
        let bytes = unsafe {
            core::slice::from_raw_parts(
                self as *const Self as *const u8,
                core::mem::size_of::<Superblock>(),
            )
        };
        self.checksum = crc32(bytes);
    }
}

/// CRC32 (Castagnoli) for checksums
pub fn crc32(data: &[u8]) -> u32 {
    let mut crc: u32 = 0xFFFFFFFF;
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

/// In-memory superblock state
pub struct SuperblockState {
    pub sb: Superblock,
    pub dirty: AtomicBool,
    pub partition_start_lba: u64,
    pub sectors_per_block: u32,
}

impl SuperblockState {
    pub fn new(sb: Superblock, partition_start_lba: u64) -> Self {
        let sectors_per_block = sb.block_size / VLFS_SECTOR_SIZE;
        Self {
            sb,
            dirty: AtomicBool::new(false),
            partition_start_lba,
            sectors_per_block,
        }
    }

    pub fn mark_dirty(&self) {
        self.dirty.store(true, Ordering::Release);
    }

    pub fn is_dirty(&self) -> bool {
        self.dirty.load(Ordering::Acquire)
    }

    /// Total LBA of a block number
    pub fn block_to_lba(&self, block: u64) -> u64 {
        self.partition_start_lba + block * self.sectors_per_block as u64
    }

    /// Number of MFT records per block
    pub fn records_per_block(&self) -> u32 {
        self.sb.block_size / VLFS_MFT_RECORD_SIZE
    }
}
