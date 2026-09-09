/// # VladFS Journal
///
/// Transaction journal for crash recovery (write-ahead logging).
///
/// Journal layout:
///   [0..4]    magic: "VJNL"
///   [4..8]    version: u32
///   [8..16]   total_transactions: u64
///   [16..24]  current_position: u64 (current write position in blocks)
///   [24..32]  journal_size: u64 (total journal blocks)
///   [32..40]  sequence: u64
///   [40..64]  reserved
///
/// Each transaction record:
///   [0..8]    transaction_id: u64
///   [8..16]   sequence: u64
///   [16..20]  num_ops: u32
///   [20..24]  flags: u32 (0x01=commit, 0x02=rollback)
///   [24..32]  checksum: u64
///   Followed by operation records:
///     [0..8]   block_number: u64
///     [8..16]  offset_in_block: u64
///     [16..24] length: u64
///     [24..32] reserved
///     Followed by data (original or new data)

use core::sync::atomic::{AtomicBool, AtomicU64, Ordering};

use super::superblock::SuperblockState;

/// Journal magic
pub const JOURNAL_MAGIC: [u8; 4] = *b"VJNL";
pub const JOURNAL_VERSION: u32 = 1;

/// Journal header (64 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct JournalHeader {
    pub magic: [u8; 4],
    pub version: u32,
    pub total_transactions: u64,
    pub current_position: u64,
    pub journal_size: u64,
    pub sequence: u64,
    pub _reserved: [u8; 24],
}

impl JournalHeader {
    pub fn new(journal_size_blocks: u64) -> Self {
        Self {
            magic: JOURNAL_MAGIC,
            version: JOURNAL_VERSION,
            total_transactions: 0,
            current_position: 1, // Start after header block
            journal_size: journal_size_blocks,
            sequence: 0,
            _reserved: [0; 24],
        }
    }

    pub fn validate(&self) -> bool {
        self.magic == JOURNAL_MAGIC && self.version == JOURNAL_VERSION
    }
}

/// Journal transaction flags
pub const JOURNAL_COMMIT: u32 = 0x01;
pub const JOURNAL_ROLLBACK: u32 = 0x02;

/// Journal operation
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct JournalOp {
    pub block_number: u64,
    pub offset_in_block: u64,
    pub length: u64,
    pub _reserved: u64,
}

impl JournalOp {
    pub fn new(block_number: u64, offset: u64, length: u64) -> Self {
        Self {
            block_number,
            offset_in_block: offset,
            length,
            _reserved: 0,
        }
    }
}

/// Journal transaction record header
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct TransactionHeader {
    pub transaction_id: u64,
    pub sequence: u64,
    pub num_ops: u32,
    pub flags: u32,
    pub checksum: u64,
}

/// A journal transaction
pub struct Transaction {
    pub id: u64,
    pub ops: alloc::vec::Vec<TransactionOp>,
}

pub struct TransactionOp {
    pub journal_op: JournalOp,
    pub old_data: alloc::vec::Vec<u8>,
    pub new_data: alloc::vec::Vec<u8>,
}

/// Journal manager
pub struct JournalManager {
    /// Journal header (cached)
    pub header: JournalHeader,
    /// Start of journal area (in blocks from partition start)
    pub journal_start: u64,
    /// Size of journal in blocks
    pub journal_blocks: u64,
    /// Current transaction
    current_tx: Option<Transaction>,
    /// Next transaction ID
    next_tx_id: u64,
    /// Dirty flag
    pub dirty: AtomicBool,
}

impl JournalManager {
    /// Initialize journal from disk
    pub fn load(
        journal_start: u64,
        journal_blocks: u64,
        block_size: u32,
        read_block: &mut dyn FnMut(u64, &mut [u8]) -> Result<(), ()>,
    ) -> Result<Self, ()> {
        let mut buf = alloc::vec![0u8; block_size as usize];
        read_block(journal_start, &mut buf)?;

        let header = unsafe { core::ptr::read_volatile(buf.as_ptr() as *const JournalHeader) };

        if !header.validate() {
            // Initialize new journal
            let header = JournalHeader::new(journal_blocks);
            return Ok(Self {
                header,
                journal_start,
                journal_blocks,
                current_tx: None,
                next_tx_id: 1,
                dirty: AtomicBool::new(true),
            });
        }

        Ok(Self {
            header,
            journal_start,
            journal_blocks,
            current_tx: None,
            next_tx_id: header.total_transactions + 1,
            dirty: AtomicBool::new(false),
        })
    }

    /// Create a new journal manager
    pub fn new(journal_start: u64, journal_blocks: u64) -> Self {
        Self {
            header: JournalHeader::new(journal_blocks),
            journal_start,
            journal_blocks,
            current_tx: None,
            next_tx_id: 1,
            dirty: AtomicBool::new(true),
        }
    }

    /// Begin a new transaction
    pub fn begin_transaction(&mut self) -> u64 {
        let tx_id = self.next_tx_id;
        self.next_tx_id += 1;

        self.current_tx = Some(Transaction {
            id: tx_id,
            ops: alloc::vec::Vec::new(),
        });

        tx_id
    }

    /// Add a block write operation to the current transaction
    pub fn add_operation(
        &mut self,
        block_number: u64,
        offset: u64,
        length: u64,
        old_data: alloc::vec::Vec<u8>,
        new_data: alloc::vec::Vec<u8>,
    ) -> Result<(), ()> {
        let tx = self.current_tx.as_mut().ok_or(())?;

        tx.ops.push(TransactionOp {
            journal_op: JournalOp::new(block_number, offset, length),
            old_data,
            new_data,
        });

        Ok(())
    }

    /// Commit the current transaction to journal
    pub fn commit_transaction(
        &mut self,
        block_size: u32,
        read_block: &mut dyn FnMut(u64, &mut [u8]) -> Result<(), ()>,
        write_block: &mut dyn FnMut(u64, &[u8]) -> Result<(), ()>,
    ) -> Result<(), ()> {
        let tx = self.current_tx.take().ok_or(())?;

        if tx.ops.is_empty() {
            return Ok(());
        }

        // Write transaction to journal area
        let mut buf = alloc::vec![0u8; block_size as usize];
        let mut pos = self.header.current_position as usize;

        // Transaction header
        let th = TransactionHeader {
            transaction_id: tx.id,
            sequence: self.header.sequence + 1,
            num_ops: tx.ops.len() as u32,
            flags: JOURNAL_COMMIT,
            checksum: 0, // TODO: compute checksum
        };

        let th_bytes = unsafe {
            core::slice::from_raw_parts(
                &th as *const TransactionHeader as *const u8,
                core::mem::size_of::<TransactionHeader>(),
            )
        };

        // Write transaction header to first block
        buf[..th_bytes.len()].copy_from_slice(th_bytes);

        // Write operations
        let mut write_offset = core::mem::size_of::<TransactionHeader>();
        for op in &tx.ops {
            // Operation header
            let op_bytes = unsafe {
                core::slice::from_raw_parts(
                    &op.journal_op as *const JournalOp as *const u8,
                    core::mem::size_of::<JournalOp>(),
                )
            };

            if write_offset + op_bytes.len() > block_size as usize {
                // Flush current block and move to next
                write_block(self.journal_start + pos as u64, &buf)?;
                pos += 1;
                if pos >= self.journal_blocks as usize {
                    pos = 1; // Wrap around (skip header block)
                }
                buf.fill(0);
                write_offset = 0;
            }

            buf[write_offset..write_offset + op_bytes.len()].copy_from_slice(op_bytes);
            write_offset += op_bytes.len();

            // Write old data (for undo)
            let old_data_len = op.old_data.len();
            if write_offset + old_data_len > block_size as usize {
                write_block(self.journal_start + pos as u64, &buf)?;
                pos += 1;
                if pos >= self.journal_blocks as usize {
                    pos = 1;
                }
                buf.fill(0);
                write_offset = 0;
            }

            buf[write_offset..write_offset + old_data_len].copy_from_slice(&op.old_data);
            write_offset += old_data_len;

            // Write new data (for redo)
            let new_data_len = op.new_data.len();
            if write_offset + new_data_len > block_size as usize {
                write_block(self.journal_start + pos as u64, &buf)?;
                pos += 1;
                if pos >= self.journal_blocks as usize {
                    pos = 1;
                }
                buf.fill(0);
                write_offset = 0;
            }

            buf[write_offset..write_offset + new_data_len].copy_from_slice(&op.new_data);
            write_offset += new_data_len;
        }

        // Flush remaining
        if write_offset > 0 {
            write_block(self.journal_start + pos as u64, &buf)?;
            pos += 1;
        }

        // Update journal header
        self.header.current_position = pos as u64;
        self.header.total_transactions += 1;
        self.header.sequence += 1;

        // Write header block
        let mut header_buf = alloc::vec![0u8; block_size as usize];
        let header_bytes = unsafe {
            core::slice::from_raw_parts(
                &self.header as *const JournalHeader as *const u8,
                core::mem::size_of::<JournalHeader>(),
            )
        };
        header_buf[..header_bytes.len()].copy_from_slice(header_bytes);
        write_block(self.journal_start, &header_buf)?;

        self.dirty.store(true, Ordering::Release);
        Ok(())
    }

    /// Flush pending data blocks to disk (apply committed transactions)
    pub fn flush(
        &mut self,
        block_size: u32,
        read_block: &mut dyn FnMut(u64, &mut [u8]) -> Result<(), ()>,
        write_block: &mut dyn FnMut(u64, &[u8]) -> Result<(), ()>,
    ) -> Result<(), ()> {
        // Reset journal position
        self.header.current_position = 1;
        self.dirty.store(false, Ordering::Release);

        // Write updated header
        let mut header_buf = alloc::vec![0u8; block_size as usize];
        let header_bytes = unsafe {
            core::slice::from_raw_parts(
                &self.header as *const JournalHeader as *const u8,
                core::mem::size_of::<JournalHeader>(),
            )
        };
        header_buf[..header_bytes.len()].copy_from_slice(header_bytes);
        write_block(self.journal_start, &header_buf)?;

        Ok(())
    }

    pub fn is_dirty(&self) -> bool {
        self.dirty.load(Ordering::Acquire)
    }
}
