#![cfg_attr(not(feature = "std"), no_std)]

pub mod format;

pub use format::*;

impl FileRecord {
    pub fn is_active(&self) -> bool {
        self.flags & FLAG_ACTIVE != 0
    }

    pub fn is_dir(&self) -> bool {
        self.flags & FLAG_DIRECTORY != 0
    }

    pub fn is_resident(&self) -> bool {
        self.flags & FLAG_RESIDENT != 0
    }

    pub fn name_str(&self) -> &str {
        let len = (self.name_len as usize).min(self.name.len());
        core::str::from_utf8(&self.name[..len]).unwrap_or("<invalid utf8>")
    }

    pub fn resident_data(&self) -> &[u8] {
        if self.is_resident() {
            let size = (self.file_size as usize).min(RESIDENT_DATA_CAPACITY);
            unsafe { &self.data.resident[..size] }
        } else {
            &[]
        }
    }

    pub fn extents(&self) -> &[Extent] {
        if !self.is_resident() {
            let count = (self.extent_count as usize).min(MAX_EXTENTS);
            unsafe { &self.data.extents[..count] }
        } else {
            &[]
        }
    }
}

impl DirEntry {
    pub fn new(record_id: u32, file_type: u8, name_str: &str) -> Self {
        let mut entry = Self {
            record_id,
            file_type,
            name_len: 0,
            name: [0; 58],
        };
        let bytes = name_str.as_bytes();
        let len = bytes.len().min(58);
        entry.name_len = len as u8;
        entry.name[..len].copy_from_slice(&bytes[..len]);
        entry
    }

    pub fn name_str(&self) -> &str {
        let len = (self.name_len as usize).min(self.name.len());
        core::str::from_utf8(&self.name[..len]).unwrap_or("<invalid utf8>")
    }
}

impl Superblock {
    pub fn is_valid(&self) -> bool {
        self.magic == SUPERBLOCK_MAGIC && self.block_size == BLOCK_SIZE as u32
    }

    pub fn label_str(&self) -> &str {
        let nul = self.volume_label.iter().position(|&c| c == 0).unwrap_or(self.volume_label.len());
        core::str::from_utf8(&self.volume_label[..nul]).unwrap_or("VladOS")
    }
}
