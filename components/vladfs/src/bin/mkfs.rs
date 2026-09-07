use std::fs::{self, File, OpenOptions};
use std::io::{Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};
use std::time::SystemTime;

use vladfs::*;

struct VladFsBuilder {
    file: File,
    total_blocks: u64,
    frt_start_block: u64,
    frt_block_count: u32,
    bitmap_start_block: u64,
    bitmap: Vec<u8>,
    next_data_block: u64,
    records: Vec<FileRecord>,
    root_entries: Vec<DirEntry>,
}

impl VladFsBuilder {
    fn new(file: File, size_bytes: u64, label: &str) -> Self {
        let total_blocks = size_bytes / BLOCK_SIZE as u64;
        let frt_records = 1024; // 1024 records
        let frt_block_count = (frt_records * RECORD_SIZE / BLOCK_SIZE) as u32; // 256 blocks
        let frt_start_block = 1;
        let bitmap_start_block = frt_start_block + frt_block_count as u64;
        let bitmap_block_count = 1;
        let data_start_block = bitmap_start_block + bitmap_block_count as u64;

        let bitmap_size = BLOCK_SIZE;
        let mut bitmap = vec![0u8; bitmap_size];

        // Mark superblock, FRT, and bitmap blocks as used
        for b in 0..data_start_block as usize {
            bitmap[b / 8] |= 1 << (b % 8);
        }

        let now = SystemTime::now()
            .duration_since(SystemTime::UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();

        let mut records = Vec::with_capacity(frt_records);

        // Record 0: Volume schema / metadata
        let mut rec0 = FileRecord {
            magic: RECORD_MAGIC_FILE,
            record_id: 0,
            parent_id: 0,
            flags: FLAG_ACTIVE | FLAG_SYSTEM,
            file_size: 0,
            allocated_size: 0,
            creation_time: now,
            modified_time: now,
            extent_count: 0,
            name_len: 7,
            mode: 0o444,
            name: [0; 80],
            data: RecordData { resident: [0; RESIDENT_DATA_CAPACITY] },
            checksum: 0,
            owner_uid: 0,
            group_gid: 0,
        };
        rec0.name[..7].copy_from_slice(b"$Volume");
        records.push(rec0);

        // Record 1: Bitmap
        let mut rec1 = FileRecord {
            magic: RECORD_MAGIC_FILE,
            record_id: 1,
            parent_id: 0,
            flags: FLAG_ACTIVE | FLAG_SYSTEM,
            file_size: BLOCK_SIZE as u64,
            allocated_size: BLOCK_SIZE as u64,
            creation_time: now,
            modified_time: now,
            extent_count: 1,
            name_len: 7,
            mode: 0o444,
            name: [0; 80],
            data: RecordData {
                extents: [Extent {
                    block_start: bitmap_start_block,
                    block_count: 1,
                    flags: 0,
                }; MAX_EXTENTS],
            },
            checksum: 0,
            owner_uid: 0,
            group_gid: 0,
        };
        rec1.name[..7].copy_from_slice(b"$Bitmap");
        records.push(rec1);

        // Record 2: Root Directory "/"
        let mut rec2 = FileRecord {
            magic: RECORD_MAGIC_DIR,
            record_id: ROOT_RECORD_ID,
            parent_id: ROOT_RECORD_ID,
            flags: FLAG_ACTIVE | FLAG_DIRECTORY | FLAG_RESIDENT,
            file_size: 0,
            allocated_size: 0,
            creation_time: now,
            modified_time: now,
            extent_count: 0,
            name_len: 1,
            mode: 0o755,
            name: [0; 80],
            data: RecordData { resident: [0; RESIDENT_DATA_CAPACITY] },
            checksum: 0,
            owner_uid: 0,
            group_gid: 0,
        };
        rec2.name[0] = b'/';
        records.push(rec2);

        Self {
            file,
            total_blocks,
            frt_start_block,
            frt_block_count,
            bitmap_start_block,
            bitmap,
            next_data_block: data_start_block,
            records,
            root_entries: Vec::new(),
        }
    }

    fn allocate_blocks(&mut self, count: u32) -> u64 {
        let start = self.next_data_block;
        self.next_data_block += count as u64;
        assert!(self.next_data_block <= self.total_blocks, "Out of disk space on VladFS!");

        for b in start..self.next_data_block {
            let bi = b as usize;
            self.bitmap[bi / 8] |= 1 << (bi % 8);
        }
        start
    }

    fn add_file(&mut self, parent_id: u32, name: &str, content: &[u8]) -> u32 {
        let record_id = self.records.len() as u32;
        let now = SystemTime::now()
            .duration_since(SystemTime::UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();

        let is_system = name.ends_with(".sys") || name.ends_with(".vex") || name.ends_with(".ini") || name.ends_with(".cfg");
        let (owner_uid, group_gid, mode) = if is_system {
            (0, 0, if name.ends_with(".vex") { 0o755 } else { 0o644 })
        } else {
            (1000, 1000, 0o644)
        };

        let mut rec = FileRecord {
            magic: RECORD_MAGIC_FILE,
            record_id,
            parent_id,
            flags: FLAG_ACTIVE | (if is_system { FLAG_SYSTEM } else { 0 }),
            file_size: content.len() as u64,
            allocated_size: 0,
            creation_time: now,
            modified_time: now,
            extent_count: 0,
            name_len: name.len().min(80) as u16,
            mode,
            name: [0; 80],
            data: RecordData { resident: [0; RESIDENT_DATA_CAPACITY] },
            checksum: 0,
            owner_uid,
            group_gid,
        };
        let nbytes = name.as_bytes();
        rec.name[..nbytes.len().min(80)].copy_from_slice(&nbytes[..nbytes.len().min(80)]);

        if content.len() <= RESIDENT_DATA_CAPACITY {
            rec.flags |= FLAG_RESIDENT;
            rec.allocated_size = 0;
            unsafe {
                rec.data.resident[..content.len()].copy_from_slice(content);
            }
        } else {
            let block_count = content.len().div_ceil(BLOCK_SIZE) as u32;
            let block_start = self.allocate_blocks(block_count);
            rec.allocated_size = (block_count as u64) * (BLOCK_SIZE as u64);
            rec.extent_count = 1;

            let mut extents = [Extent { block_start: 0, block_count: 0, flags: 0 }; MAX_EXTENTS];
            extents[0] = Extent { block_start, block_count, flags: 0 };
            rec.data.extents = extents;

            // Write content to allocated data blocks
            self.file.seek(SeekFrom::Start(block_start * BLOCK_SIZE as u64)).unwrap();
            self.file.write_all(content).unwrap();

            // Pad remaining block with zeroes
            let remainder = (block_count as usize * BLOCK_SIZE) - content.len();
            if remainder > 0 {
                self.file.write_all(&vec![0u8; remainder]).unwrap();
            }
        }

        self.records.push(rec);
        record_id
    }

    fn add_dir(&mut self, parent_id: u32, name: &str) -> u32 {
        let record_id = self.records.len() as u32;
        let now = SystemTime::now()
            .duration_since(SystemTime::UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();

        let is_system_dir = name.eq_ignore_ascii_case("System32") || name.eq_ignore_ascii_case("VladOS") || name.eq_ignore_ascii_case("drivers") || name.eq_ignore_ascii_case("config");
        let (owner_uid, group_gid) = if is_system_dir { (0, 0) } else { (1000, 1000) };

        let mut rec = FileRecord {
            magic: RECORD_MAGIC_DIR,
            record_id,
            parent_id,
            flags: FLAG_ACTIVE | FLAG_DIRECTORY | FLAG_RESIDENT | (if is_system_dir { FLAG_SYSTEM } else { 0 }),
            file_size: 0,
            allocated_size: 0,
            creation_time: now,
            modified_time: now,
            extent_count: 0,
            name_len: name.len().min(80) as u16,
            mode: 0o755,
            name: [0; 80],
            data: RecordData { resident: [0; RESIDENT_DATA_CAPACITY] },
            checksum: 0,
            owner_uid,
            group_gid,
        };
        let nbytes = name.as_bytes();
        rec.name[..nbytes.len().min(80)].copy_from_slice(&nbytes[..nbytes.len().min(80)]);

        self.records.push(rec);
        record_id
    }

    fn add_tree_recursive(&mut self, parent_id: u32, host_path: &Path) {
        let mut entries = Vec::new();
        if let Ok(dir_read) = fs::read_dir(host_path) {
            for item in dir_read.flatten() {
                let path = item.path();
                let fname = item.file_name().to_string_lossy().to_string();
                if item.file_type().map(|ft| ft.is_dir()).unwrap_or(false) {
                    let dir_id = self.add_dir(parent_id, &fname);
                    entries.push(DirEntry::new(dir_id, 2, &fname));
                    self.add_tree_recursive(dir_id, &path);
                } else if item.file_type().map(|ft| ft.is_file()).unwrap_or(false) {
                    if let Ok(content) = fs::read(&path) {
                        let file_id = self.add_file(parent_id, &fname, &content);
                        entries.push(DirEntry::new(file_id, 1, &fname));
                    }
                }
            }
        }

        // Pack entries into parent directory's resident data
        let mut serialized = Vec::new();
        for entry in &entries {
            let bytes = unsafe {
                core::slice::from_raw_parts(
                    entry as *const DirEntry as *const u8,
                    core::mem::size_of::<DirEntry>(),
                )
            };
            serialized.extend_from_slice(bytes);
        }

        if serialized.len() <= RESIDENT_DATA_CAPACITY {
            let parent_rec = &mut self.records[parent_id as usize];
            parent_rec.file_size = serialized.len() as u64;
            parent_rec.flags |= FLAG_RESIDENT;
            unsafe {
                parent_rec.data.resident[..serialized.len()].copy_from_slice(&serialized);
            }
        } else {
            let block_count = serialized.len().div_ceil(BLOCK_SIZE) as u32;
            let block_start = self.allocate_blocks(block_count);
            let parent_rec = &mut self.records[parent_id as usize];
            parent_rec.flags &= !FLAG_RESIDENT;
            parent_rec.file_size = serialized.len() as u64;
            parent_rec.allocated_size = (block_count as u64) * (BLOCK_SIZE as u64);
            parent_rec.extent_count = 1;

            let mut extents = [Extent { block_start: 0, block_count: 0, flags: 0 }; MAX_EXTENTS];
            extents[0] = Extent { block_start, block_count, flags: 0 };
            parent_rec.data.extents = extents;

            self.file.seek(SeekFrom::Start(block_start * BLOCK_SIZE as u64)).unwrap();
            self.file.write_all(&serialized).unwrap();

            let remainder = (block_count as usize * BLOCK_SIZE) - serialized.len();
            if remainder > 0 {
                self.file.write_all(&vec![0u8; remainder]).unwrap();
            }
        }
    }

    fn finish(&mut self, label: &str) {
        // Write FRT (File Record Table)
        for (i, rec) in self.records.iter().enumerate() {
            let offset = (self.frt_start_block * BLOCK_SIZE as u64) + (i * RECORD_SIZE) as u64;
            self.file.seek(SeekFrom::Start(offset)).unwrap();
            let slice = unsafe {
                core::slice::from_raw_parts(
                    rec as *const FileRecord as *const u8,
                    RECORD_SIZE,
                )
            };
            self.file.write_all(slice).unwrap();
        }

        // Write Bitmap
        self.file.seek(SeekFrom::Start(self.bitmap_start_block * BLOCK_SIZE as u64)).unwrap();
        self.file.write_all(&self.bitmap).unwrap();

        // Write Superblock (LBA 0)
        let mut sb = Superblock {
            magic: SUPERBLOCK_MAGIC,
            block_size: BLOCK_SIZE as u32,
            reserved0: 0,
            total_blocks: self.total_blocks,
            frt_start_block: self.frt_start_block,
            frt_block_count: self.frt_block_count,
            reserved1: 0,
            bitmap_start_block: self.bitmap_start_block,
            bitmap_block_count: 1,
            reserved2: 0,
            data_start_block: self.next_data_block,
            root_record_id: ROOT_RECORD_ID,
            free_records: (self.frt_block_count * RECORDS_PER_BLOCK as u32) - self.records.len() as u32,
            free_blocks: self.total_blocks - self.next_data_block,
            volume_label: [0; 32],
            volume_uuid: [0x56, 0x4C, 0x41, 0x44, 0x4F, 0x53, 0x10, 0x01, 0, 0, 0, 0, 0, 0, 0, 1],
            reserved3: [0; 3968],
        };
        let lbytes = label.as_bytes();
        sb.volume_label[..lbytes.len().min(32)].copy_from_slice(&lbytes[..lbytes.len().min(32)]);

        self.file.seek(SeekFrom::Start(0)).unwrap();
        let sb_slice = unsafe {
            core::slice::from_raw_parts(
                &sb as *const Superblock as *const u8,
                BLOCK_SIZE,
            )
        };
        self.file.write_all(sb_slice).unwrap();
        self.file.flush().unwrap();
    }
}

fn main() {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 2 {
        eprintln!("Usage: mkfs.vladfs <output_img> [--size-mb <size>] [--label <label>] [--root-dir <dir>]");
        std::process::exit(1);
    }

    let out_path = &args[1];
    let mut size_mb = 64u64;
    let mut label = "VladOS System".to_string();
    let mut root_dir: Option<PathBuf> = None;

    let mut i = 2;
    while i < args.len() {
        match args[i].as_str() {
            "--size-mb" => {
                i += 1;
                size_mb = args[i].parse().unwrap_or(64);
            }
            "--label" => {
                i += 1;
                label = args[i].clone();
            }
            "--root-dir" => {
                i += 1;
                root_dir = Some(PathBuf::from(&args[i]));
            }
            _ => {}
        }
        i += 1;
    }

    println!("Formatting '{out_path}' with VladFS ({size_mb} MB, label='{label}')...");
    let file = OpenOptions::new()
        .read(true)
        .write(true)
        .create(true)
        .truncate(true)
        .open(out_path)
        .expect("Failed to open output file");

    let size_bytes = size_mb * 1024 * 1024;
    file.set_len(size_bytes).expect("Failed to set file size");

    let mut builder = VladFsBuilder::new(file, size_bytes, &label);

    if let Some(dir) = root_dir {
        println!("Populating VladFS from host directory: {:?}", dir);
        builder.add_tree_recursive(ROOT_RECORD_ID, &dir);
    }

    builder.finish(&label);
    println!("Successfully formatted VladFS on '{out_path}'! Records created: {}", builder.records.len());
}
