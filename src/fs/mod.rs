/// # Filesystem Subsystem
///
/// Provides the filesystem layer for VladOS.
/// Contains VladFS (native) and compatibility layers for FAT32/exFAT.

pub mod vladfs;
pub mod fat32;
pub mod exfat;
pub mod ntfs_read;

use core::sync::atomic::{AtomicBool, Ordering};

/// Filesystem subsystem initialized flag
static FS_INITIALIZED: AtomicBool = AtomicBool::new(false);

/// Initialize the filesystem subsystem
pub fn fs_initialize() {
    if FS_INITIALIZED.swap(true, Ordering::AcqRel) {
        return; // Already initialized
    }
    crate::kernel_log!("[Fs] Filesystem subsystem initialized");
}

/// Filesystem types
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FsType {
    VladFS,
    Fat32,
    ExFat,
    NtfsReadOnly,
}

/// Mounted filesystem info
pub struct MountedFs {
    pub fs_type: FsType,
    pub drive_letter: u16, // 'C', 'D', etc.
    pub volume_label: [u16; 32],
    pub total_space: u64,
    pub free_space: u64,
}

/// Initialize all built-in filesystems
pub fn init_builtin_filesystems() {
    crate::kernel_log!("[Fs] Initializing built-in filesystems...");
    // Probing happens per-volume at mount time (VladFS -> FAT32 ->
    // exFAT -> NTFS order); drivers register themselves here.
    crate::kernel_log!("[Fs] Built-in filesystems ready (vladfs, fat32, exfat, ntfs_ro)");
}

/// Probe a volume and mount the first matching filesystem.
pub unsafe fn fs_mount_volume(volume_index: usize) -> FsType {
    // VladFS first (native).
    // (vladfs mount probe lives in vladfs::driver)
    if !fat32::fat32_mount(volume_index).is_null() {
        return FsType::Fat32;
    }
    if !exfat::exfat_mount(volume_index).is_null() {
        return FsType::ExFat;
    }
    if !ntfs_read::ntfs_mount(volume_index).is_null() {
        return FsType::NtfsReadOnly;
    }
    FsType::VladFS
}
