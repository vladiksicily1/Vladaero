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

    pub fn permissions_str(&self) -> [u8; 10] {
        let mut s = [b'-'; 10];
        if self.is_dir() {
            s[0] = b'd';
        }
        let m = if self.mode != 0 {
            self.mode
        } else if self.is_dir() {
            0o755
        } else {
            0o644
        };
        if m & 0o400 != 0 { s[1] = b'r'; }
        if m & 0o200 != 0 { s[2] = b'w'; }
        if m & 0o100 != 0 { s[3] = b'x'; }
        if m & 0o040 != 0 { s[4] = b'r'; }
        if m & 0o020 != 0 { s[5] = b'w'; }
        if m & 0o010 != 0 { s[6] = b'x'; }
        if m & 0o004 != 0 { s[7] = b'r'; }
        if m & 0o002 != 0 { s[8] = b'w'; }
        if m & 0o001 != 0 { s[9] = b'x'; }
        s
    }

    pub fn owner_name(&self) -> &'static str {
        match self.owner_uid {
            0 => "SYSTEM",
            1000 => "Vlad",
            1001 => "User",
            1002 => "Guest",
            _ => "Unknown",
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
