/// Full GPT (GUID Partition Table) parser with CRC32 validation

/// GPT header signature
pub const GPT_SIGNATURE: [u8; 8] = *b"EFI PART";

/// GPT Header at LBA 1
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct GptHeader {
    pub signature: [u8; 8],
    pub revision: u32,
    pub header_size: u32,
    pub header_crc32: u32,
    pub reserved: u32,
    pub my_lba: u64,
    pub alternate_lba: u64,
    pub first_usable_lba: u64,
    pub last_usable_lba: u64,
    pub disk_guid: [u8; 16],
    pub partition_entry_lba: u64,
    pub num_partition_entries: u32,
    pub partition_entry_size: u32,
    pub partition_entry_crc32: u32,
}

/// GPT Partition Entry (128 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct GptPartitionEntry {
    pub type_guid: [u8; 16],
    pub unique_guid: [u8; 16],
    pub starting_lba: u64,
    pub ending_lba: u64,
    pub attributes: u64,
    pub name: [u16; 36],
}

/// Standard EFI System Partition GUID
pub const EFI_SYSTEM_PARTITION_GUID: [u8; 16] = [
    0x00, 0x00, 0xC1, 0x12, 0x67, 0xD3, 0x11, 0xD2,
    0x8A, 0x3C, 0x00, 0x06, 0x29, 0x60, 0x53, 0x12,
];

/// Microsoft Basic Data GUID
pub const MS_BASIC_DATA_GUID: [u8; 16] = [
    0x00, 0x00, 0x11, 0xDE, 0xA5, 0x23, 0xD3, 0x11,
    0x97, 0xBB, 0x00, 0x06, 0x29, 0x60, 0x53, 0x12,
];

/// VladOS partition GUID
pub const VLADOS_PARTITION_GUID: [u8; 16] = [
    0x56, 0x4C, 0x41, 0x44, 0x00, 0x01, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
];

/// Found partition info
#[derive(Debug, Clone, Copy)]
pub struct PartitionInfo {
    pub start_lba: u64,
    pub end_lba: u64,
    pub size_sectors: u64,
    pub type_guid: [u8; 16],
    pub unique_guid: [u8; 16],
    pub attributes: u64,
    pub name: [u16; 36],
}

impl PartitionInfo {
    pub fn name_str(&self) -> alloc::string::String {
        let mut s = alloc::string::String::new();
        for &c in &self.name {
            if c == 0 {
                break;
            }
            if let Some(ch) = char::from_u32(c as u32) {
                s.push(ch);
            }
        }
        s
    }

    pub fn size_bytes(&self) -> u64 {
        (self.end_lba - self.start_lba + 1) * 512
    }
}

/// Disk read trait
pub trait DiskRead {
    fn read_sectors(&mut self, lba: u64, count: u32, buf: &mut [u8]) -> Result<(), ()>;
    fn sector_size(&self) -> u32;
    fn total_sectors(&self) -> u64;
}

/// CRC32 calculation (Castagnoli polynomial, same as used in GPT)
pub fn crc32c(data: &[u8]) -> u32 {
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

/// Standard CRC32 (ISO 3309)
pub fn crc32(data: &[u8]) -> u32 {
    let mut crc: u32 = 0xFFFFFFFF;
    for &byte in data {
        crc ^= byte as u32;
        for _ in 0..8 {
            if crc & 1 != 0 {
                crc = (crc >> 1) ^ 0xEDB88320;
            } else {
                crc >>= 1;
            }
        }
    }
    crc ^ 0xFFFFFFFF
}

/// Validate GPT header CRC32
fn validate_header_crc(header: &GptHeader) -> bool {
    let saved_crc = header.header_crc32;
    let header_size = header.header_size as usize;

    // Zero out the CRC field and reserved, recalculate
    let mut header_bytes = alloc::vec![0u8; header_size];
    unsafe {
        core::ptr::copy_nonoverlapping(
            header as *const GptHeader as *const u8,
            header_bytes.as_mut_ptr(),
            header_size,
        );
    }
    // Zero out header_crc32 (offset 16) and reserved (offset 20)
    header_bytes[16] = 0;
    header_bytes[17] = 0;
    header_bytes[18] = 0;
    header_bytes[19] = 0;
    header_bytes[20] = 0;
    header_bytes[21] = 0;
    header_bytes[22] = 0;
    header_bytes[23] = 0;

    let computed = crc32(&header_bytes);
    computed == saved_crc
}

/// Validate partition entry array CRC32
fn validate_partition_entries_crc(header: &GptHeader, entries_buf: &[u8]) -> bool {
    let expected_crc = header.partition_entry_crc32;
    let actual_crc = crc32(&entries_buf);
    actual_crc == expected_crc
}

/// Read and validate a 16-byte GUID
fn guid_to_string(guid: &[u8; 16]) -> alloc::string::String {
    // GUID structure: Data1(4) Data2(2) Data3(2) Data4(8)
    let d1 = u32::from_le_bytes([guid[0], guid[1], guid[2], guid[3]]);
    let d2 = u16::from_le_bytes([guid[4], guid[5]]);
    let d3 = u16::from_le_bytes([guid[6], guid[7]]);

    let mut s = alloc::string::String::with_capacity(36);
    // Write as hex manually
    let hex = b"0123456789ABCDEF";

    // Data1 (8 hex)
    for i in (0..8).rev() {
        s.push(hex[((d1 >> (i * 4)) & 0xF) as usize] as char);
    }
    s.push('-');
    // Data2 (4 hex)
    for i in (0..4).rev() {
        s.push(hex[((d2 >> (i * 4)) & 0xF) as usize] as char);
    }
    s.push('-');
    // Data3 (4 hex)
    for i in (0..4).rev() {
        s.push(hex[((d3 >> (i * 4)) & 0xF) as usize] as char);
    }
    s.push('-');
    // Data4 (first 2 bytes, then dash, then 6 bytes)
    for i in 0..2 {
        s.push(hex[((guid[8 + i] >> 4) & 0xF) as usize] as char);
        s.push(hex[(guid[8 + i] & 0xF) as usize] as char);
    }
    s.push('-');
    for i in 2..8 {
        s.push(hex[((guid[8 + i] >> 4) & 0xF) as usize] as char);
        s.push(hex[(guid[8 + i] & 0xF) as usize] as char);
    }

    s
}

/// Find all GPT partitions on a disk
pub fn find_all_partitions<D: DiskRead>(disk: &mut D) -> Option<alloc::vec::Vec<PartitionInfo>> {
    let sector_size = disk.sector_size() as usize;

    // Read GPT header from LBA 1
    let mut header_buf = alloc::vec![0u8; sector_size];
    disk.read_sectors(1, 1, &mut header_buf).ok()?;

    let header = unsafe { &*(header_buf.as_ptr() as *const GptHeader) };

    // Validate signature
    if header.signature != GPT_SIGNATURE {
        return None;
    }

    // Validate revision (should be 1.0 or 2.0)
    if header.revision < 0x00010000 || header.revision > 0x00020000 {
        return None;
    }

    // Validate header size (minimum 92 bytes)
    if (header.header_size as usize) < 92 {
        return None;
    }

    // Validate header CRC
    if !validate_header_crc(header) {
        return None;
    }

    // Validate my_lba
    if header.my_lba != 1 {
        return None;
    }

    // Read partition entries
    let entry_size = header.partition_entry_size as usize;
    let num_entries = header.num_partition_entries as usize;
    let entries_bytes = entry_size * num_entries;

    if entry_size < core::mem::size_of::<GptPartitionEntry>() {
        return None;
    }

    let mut entries_buf = alloc::vec![0u8; entries_bytes];
    let sectors_for_entries = (entries_bytes + sector_size - 1) / sector_size;
    disk.read_sectors(header.partition_entry_lba, sectors_for_entries as u32, &mut entries_buf)
        .ok()?;

    // Validate partition entries CRC
    if !validate_partition_entries_crc(header, &entries_buf) {
        return None;
    }

    let mut partitions = alloc::vec::Vec::new();

    for i in 0..num_entries {
        let offset = i * entry_size;
        if offset + core::mem::size_of::<GptPartitionEntry>() > entries_buf.len() {
            break;
        }

        let entry = unsafe {
            core::ptr::read_volatile(entries_buf[offset..].as_ptr() as *const GptPartitionEntry)
        };

        // Skip empty entries
        if entry.type_guid == [0u8; 16] {
            continue;
        }

        let start = entry.starting_lba;
        let end = entry.ending_lba;

        if start > end || start < header.first_usable_lba {
            continue;
        }

        let part = PartitionInfo {
            start_lba: start,
            end_lba: end,
            size_sectors: end - start + 1,
            type_guid: entry.type_guid,
            unique_guid: entry.unique_guid,
            attributes: entry.attributes,
            name: entry.name,
        };

        partitions.push(part);
    }

    Some(partitions)
}

/// Find the VladOS partition specifically
pub fn find_vlados_partition<D: DiskRead>(disk: &mut D) -> Option<PartitionInfo> {
    let partitions = find_all_partitions(disk)?;

    // First: look for exact VladOS GUID
    for p in &partitions {
        if p.type_guid == VLADOS_PARTITION_GUID {
            return Some(*p);
        }
    }

    // Fallback: look for Microsoft Basic Data with "VladOS" name
    for p in &partitions {
        if p.type_guid == MS_BASIC_DATA_GUID {
            let name = p.name_str();
            if name.eq_ignore_ascii_case("VladOS") || name.eq_ignore_ascii_case("vlados") {
                return Some(*p);
            }
        }
    }

    None
}

/// Find EFI System Partition
pub fn find_esp<D: DiskRead>(disk: &mut D) -> Option<PartitionInfo> {
    let partitions = find_all_partitions(disk)?;
    for p in &partitions {
        if p.type_guid == EFI_SYSTEM_PARTITION_GUID {
            return Some(*p);
        }
    }
    None
}
