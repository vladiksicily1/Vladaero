//! VladFS on-disk format definitions (inspired by NTFS MFT architecture)

pub const BLOCK_SIZE: usize = 4096;
pub const RECORD_SIZE: usize = 1024;
pub const RECORDS_PER_BLOCK: usize = BLOCK_SIZE / RECORD_SIZE; // 4

pub const SUPERBLOCK_MAGIC: [u8; 8] = *b"VLADFS01";
pub const RECORD_MAGIC_FILE: [u8; 4] = *b"FILE";
pub const RECORD_MAGIC_DIR: [u8; 4] = *b"DIR ";
pub const RECORD_MAGIC_FREE: [u8; 4] = *b"FREE";

pub const FLAG_ACTIVE: u32 = 1 << 0;
pub const FLAG_DIRECTORY: u32 = 1 << 1;
pub const FLAG_RESIDENT: u32 = 1 << 2;
pub const FLAG_READONLY: u32 = 1 << 3;
pub const FLAG_SYSTEM: u32 = 1 << 4;
pub const FLAG_HIDDEN: u32 = 1 << 5;

pub const ROOT_RECORD_ID: u32 = 2;
pub const BITMAP_RECORD_ID: u32 = 1;
pub const SCHEMA_RECORD_ID: u32 = 0;

pub const RESIDENT_DATA_CAPACITY: usize = 880;
pub const MAX_EXTENTS: usize = 55;

#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Extent {
    pub block_start: u64,
    pub block_count: u32,
    pub flags: u32,
}

#[repr(C)]
#[derive(Clone, Copy)]
pub union RecordData {
    pub resident: [u8; RESIDENT_DATA_CAPACITY],
    pub extents: [Extent; MAX_EXTENTS],
}

#[repr(C)]
#[derive(Clone, Copy)]
pub struct FileRecord {
    pub magic: [u8; 4],
    pub record_id: u32,
    pub parent_id: u32,
    pub flags: u32,
    pub file_size: u64,
    pub allocated_size: u64,
    pub creation_time: u64,
    pub modified_time: u64,
    pub extent_count: u32,
    pub name_len: u16,
    pub reserved1: u16,
    pub name: [u8; 80],
    pub data: RecordData,
    pub checksum: u32,
    pub reserved2: [u8; 4],
}

const _: () = assert!(core::mem::size_of::<FileRecord>() == RECORD_SIZE);

#[repr(C)]
#[derive(Clone, Copy, Debug)]
pub struct DirEntry {
    pub record_id: u32,
    pub file_type: u8, // 1 = regular file, 2 = directory
    pub name_len: u8,
    pub name: [u8; 58],
}

const _: () = assert!(core::mem::size_of::<DirEntry>() == 64);

#[repr(C)]
#[derive(Clone, Copy, Debug)]
pub struct Superblock {
    pub magic: [u8; 8],
    pub block_size: u32,
    pub reserved0: u32,
    pub total_blocks: u64,
    pub frt_start_block: u64,
    pub frt_block_count: u32,
    pub reserved1: u32,
    pub bitmap_start_block: u64,
    pub bitmap_block_count: u32,
    pub reserved2: u32,
    pub data_start_block: u64,
    pub root_record_id: u32,
    pub free_records: u32,
    pub free_blocks: u64,
    pub volume_label: [u8; 32],
    pub volume_uuid: [u8; 16],
    pub reserved3: [u8; 3968],
}

const _: () = assert!(core::mem::size_of::<Superblock>() == BLOCK_SIZE);

