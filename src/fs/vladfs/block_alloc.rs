/// # VladFS Block Allocator
///
/// Bitmap-based block allocator for the data area.
/// Each bit represents one block: 0 = free, 1 = used.
///
/// The bitmap is stored in a contiguous area of blocks starting at
/// a known location (derived from superblock).

use core::sync::atomic::{AtomicBool, AtomicU64, Ordering};

use super::superblock::SuperblockState;
use super::mft::MftRecord;

/// Bitmap block stores block_size * 8 block bits
const BITS_PER_BYTE: usize = 8;

pub struct BlockAllocator {
    /// Start of data area (in blocks from partition start)
    data_start: u64,
    /// Total data blocks
    total_data_blocks: u64,
    /// Number of blocks used by bitmap itself
    bitmap_blocks: u64,
    /// Cached bitmap (one bit per data block)
    bitmap: alloc::vec::Vec<u8>,
    /// Dirty flag
    dirty: AtomicBool,
}

impl BlockAllocator {
    /// Create a new allocator
    pub fn new(total_data_blocks: u64, block_size: u32) -> Self {
        // Bitmap needs total_data_blocks bits
        let bitmap_size = ((total_data_blocks as usize + BITS_PER_BYTE - 1) / BITS_PER_BYTE) as usize;
        let bitmap_blocks = ((bitmap_size as u64 + block_size as u64 - 1) / block_size as u64) as u64;
        let bitmap_blocks_usize = bitmap_blocks as usize;

        let mut bitmap = alloc::vec![0xFFu8; bitmap_size]; // Mark all as used initially
        // Mark bitmap blocks as used
        for i in 0..bitmap_blocks_usize {
            bitmap[i] = 0xFF;
        }

        Self {
            data_start: 0,
            total_data_blocks,
            bitmap_blocks,
            bitmap,
            dirty: AtomicBool::new(false),
        }
    }

    /// Initialize from on-disk bitmap
    pub fn load(
        data_start: u64,
        total_data_blocks: u64,
        block_size: u32,
        read_block: &mut dyn FnMut(u64, &mut [u8]) -> Result<(), ()>,
    ) -> Result<Self, ()> {
        let bitmap_size = ((total_data_blocks as usize + BITS_PER_BYTE - 1) / BITS_PER_BYTE) as usize;
        let bitmap_blocks = ((bitmap_size as u64 + block_size as u64 - 1) / block_size as u64) as u64;

        let mut bitmap = alloc::vec![0u8; bitmap_size];

        // Read bitmap blocks from disk
        let mut buf = alloc::vec![0u8; block_size as usize];
        for i in 0..bitmap_blocks {
            read_block(data_start + i, &mut buf)?;
            let bytes_to_copy = core::cmp::min(buf.len(), bitmap_size - (i as usize * block_size as usize));
            let dst_start = i as usize * block_size as usize;
            bitmap[dst_start..dst_start + bytes_to_copy].copy_from_slice(&buf[..bytes_to_copy]);
        }

        Ok(Self {
            data_start,
            total_data_blocks,
            bitmap_blocks,
            bitmap,
            dirty: AtomicBool::new(false),
        })
    }

    /// Allocate a contiguous run of blocks
    /// Returns the starting block number (relative to data_start)
    pub fn allocate(&mut self, count: u64) -> Option<u64> {
        let count = count as usize;
        let available = self.total_data_blocks - self.bitmap_blocks;

        // Simple first-fit scan
        let mut run_start: Option<usize> = None;
        let mut run_len: usize = 0;

        // Skip bitmap blocks (marked as used)
        let start_bit = self.bitmap_blocks as usize * 8; // first data bit

        for bit in start_bit..self.bitmap.len() * BITS_PER_BYTE {
            if bit >= self.total_data_blocks as usize {
                break;
            }

            let byte = bit / BITS_PER_BYTE;
            let mask = 1 << (bit % BITS_PER_BYTE);

            if self.bitmap[byte] & mask == 0 {
                // Free block
                if run_start.is_none() {
                    run_start = Some(bit);
                    run_len = 1;
                } else {
                    run_len += 1;
                }
                if run_len >= count {
                    let start = run_start.unwrap() as u64;
                    // Mark as used
                    for i in 0..count {
                        let b = (start + i as u64) as usize;
                        let byte_idx = b / BITS_PER_BYTE;
                        let bit_idx = b % BITS_PER_BYTE;
                        self.bitmap[byte_idx] |= 1 << bit_idx;
                    }
                    self.dirty.store(true, Ordering::Release);
                    return Some(start);
                }
            } else {
                run_start = None;
                run_len = 0;
            }
        }

        None // No contiguous run found
    }

    /// Allocate a single block
    pub fn allocate_single(&mut self) -> Option<u64> {
        self.allocate(1)
    }

    /// Free a contiguous run of blocks
    pub fn free(&mut self, start: u64, count: u64) {
        for i in 0..count {
            let bit = (start + i) as usize;
            if bit < self.bitmap.len() * BITS_PER_BYTE {
                let byte = bit / BITS_PER_BYTE;
                let mask = 1 << (bit % BITS_PER_BYTE);
                self.bitmap[byte] &= !mask;
            }
        }
        self.dirty.store(true, Ordering::Release);
    }

    /// Free a single block
    pub fn free_single(&mut self, block: u64) {
        self.free(block, 1);
    }

    /// Check if a block is allocated
    pub fn is_allocated(&self, block: u64) -> bool {
        let bit = block as usize;
        if bit >= self.bitmap.len() * BITS_PER_BYTE {
            return true; // Out of range = allocated
        }
        let byte = bit / BITS_PER_BYTE;
        let mask = 1 << (bit % BITS_PER_BYTE);
        self.bitmap[byte] & mask != 0
    }

    /// Count free blocks
    pub fn free_count(&self) -> u64 {
        let mut count = 0u64;
        for &byte in &self.bitmap {
            // Count bits that are 0
            count += (!byte).count_ones() as u64;
        }
        count
    }

    /// Save bitmap to disk
    pub fn save(
        &self,
        block_size: u32,
        write_block: &mut dyn FnMut(u64, &[u8]) -> Result<(), ()>,
    ) -> Result<(), ()> {
        let mut buf = alloc::vec![0u8; block_size as usize];
        for i in 0..self.bitmap_blocks {
            let src_start = i as usize * block_size as usize;
            let src_end = core::cmp::min(src_start + block_size as usize, self.bitmap.len());
            let bytes_to_copy = src_end - src_start;

            buf.fill(0);
            buf[..bytes_to_copy].copy_from_slice(&self.bitmap[src_start..src_end]);
            write_block(self.data_start + i, &buf)?;
        }
        self.dirty.store(false, Ordering::Release);
        Ok(())
    }

    pub fn is_dirty(&self) -> bool {
        self.dirty.load(Ordering::Acquire)
    }
}
