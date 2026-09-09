/// # VladFS Driver
///
/// Filesystem driver that registers with the I/O Manager and handles IRPs.
/// This is the glue between the NT-style I/O subsystem and VladFS operations.
///
/// Architecture:
///   User-space NtCreateFile → I/O Manager → IRP_MJ_CREATE → VladsFsDriver::dispatch
///   VladsFsDriver reads the IRP, calls VladsFs methods, completes the IRP.

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicUsize, Ordering};

use crate::io::*;
use crate::types::*;

use super::VladsFs;
use super::superblock::{SuperblockState, VLFS_BLOCK_SIZE, VLFS_SECTOR_SIZE};

/// Maximum number of open files per volume
const MAX_OPEN_FILES: usize = 1024;

/// Device extension for the volume device object
#[repr(C)]
pub struct VladsVolumeExtension {
    /// The VladsFs instance
    pub fs: Option<VladsFs>,
    /// Volume device number
    pub device_number: u32,
    /// Whether the volume is mounted
    pub mounted: bool,
    /// Drive letter (C=0, D=1, etc.)
    pub drive_letter: u8,
    /// Volume label
    pub volume_label: [u16; 32],
    /// Total space in bytes
    pub total_space: u64,
    /// Free space in bytes
    pub free_space: u64,
    /// Sector size
    pub sector_size: u16,
    /// Block size
    pub block_size: u32,
}

/// Open file context (stored in FileObject's FsContext)
#[repr(C)]
pub struct VladsFileContext {
    /// Inode number
    pub inode: u64,
    /// Current file position
    pub position: u64,
    /// File size
    pub size: u64,
    /// File attributes
    pub attributes: u32,
    /// Is directory
    pub is_directory: bool,
    /// Parent directory inode
    pub parent_inode: u64,
    /// Reference count
    pub ref_count: u32,
}

/// Global driver state
static VLADS_DRIVER_INITIALIZED: AtomicBool = AtomicBool::new(false);
static VLADS_DRIVER_OBJECT: core::sync::atomic::AtomicPtr<DriverObject> =
    core::sync::atomic::AtomicPtr::new(core::ptr::null_mut());

/// Read sector callback (wraps HAL disk read)
/// For now, uses a simple port I/O approach
unsafe fn vladfs_read_sector_impl(lba: u64, count: u32, buf: &mut [u8]) -> Result<(), ()> {
    // This is a simplified implementation
    // In a real system, this would go through the disk class driver
    // For now, we'll use a stub that fills with zeros
    // TODO: Integrate with AHCI/NVMe driver
    let bytes = count as usize * VLFS_SECTOR_SIZE as usize;
    let copy_len = core::cmp::min(bytes, buf.len());
    buf[..copy_len].fill(0);
    Ok(())
}

/// Write sector callback (wraps HAL disk write)
unsafe fn vladfs_write_sector_impl(lba: u64, count: u32, buf: &[u8]) -> Result<(), ()> {
    // Stub - would go through disk class driver
    Ok(())
}

/// Wrapper to pass as &mut dyn FnMut
macro_rules! read_sector_fn {
    () => { &mut |lba: u64, count: u32, buf: &mut [u8]| unsafe { vladfs_read_sector_impl(lba, count, buf) } };
}

macro_rules! write_sector_fn {
    () => { &mut |lba: u64, count: u32, buf: &[u8]| unsafe { vladfs_write_sector_impl(lba, count, buf) } };
}

// ============================================================
// IRP Dispatch Handlers
// ============================================================

/// IRP_MJ_CREATE - Open/create a file
unsafe extern "C" fn vladfs_create(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    if device_object.is_null() || irp.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dev_ref = &*device_object;
    let irp_ref = &mut *irp;
    let stack = irp_ref.get_stack_location();
    if stack.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let stack_ref = &*stack;
    let ext = dev_ref.device_extension as *mut VladsVolumeExtension;
    if ext.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let vol_ext = &mut *ext;
    if vol_ext.fs.is_none() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let fs = vol_ext.fs.as_mut().unwrap();

    // Get file name from file object (if available)
    let file_name = if !stack_ref.file_object.is_null() {
        let fo = &*(stack_ref.file_object as *const crate::nt::FileObject);
        if !fo.file_name.buffer.is_null() && fo.file_name.length > 0 {
            let name_len = fo.file_name.length as usize / 2;
            let slice = core::slice::from_raw_parts(fo.file_name.buffer, name_len);
            // Convert UTF-16 to ASCII for path lookup
            let mut name = alloc::string::String::new();
            for &ch in slice {
                if let Some(c) = core::char::from_u32(ch as u32) {
                    name.push(c);
                }
            }
            Some(name)
        } else {
            None
        }
    } else {
        None
    };

    let file_name = match file_name {
        Some(n) if !n.is_empty() => n,
        _ => {
            // Root directory open
            let ctx = alloc::alloc::alloc_zeroed(
                core::alloc::Layout::new::<VladsFileContext>()
            ) as *mut VladsFileContext;
            if ctx.is_null() {
                irp_ref.io_status.status = STATUS_NO_MEMORY;
                return STATUS_NO_MEMORY;
            }
            (*ctx).inode = fs.sb_state.sb.root_inode;
            (*ctx).position = 0;
            (*ctx).size = 0;
            (*ctx).attributes = super::attributes::FILE_ATTRIBUTE_DIRECTORY;
            (*ctx).is_directory = true;
            (*ctx).parent_inode = fs.sb_state.sb.root_inode;
            (*ctx).ref_count = 1;

            if !stack_ref.file_object.is_null() {
                let fo = &mut *(stack_ref.file_object as *mut crate::nt::FileObject);
                fo.section_object = ctx as Pvoid;
            }

            irp_ref.io_status.status = STATUS_SUCCESS;
            irp_ref.io_status.information = 0;
            return STATUS_SUCCESS;
        }
    };

    // Strip leading slash
    let path = file_name.trim_start_matches('/');

    // Try to open existing file
    let result = fs.open_path(path, read_sector_fn!());

    match result {
        Ok(inode) => {
            let record = match fs.read_mft_record(inode, read_sector_fn!()) {
                Ok(r) => r,
                Err(_) => {
                    irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
                    return STATUS_INVALID_PARAMETER;
                }
            };

            let is_dir = record.header.is_directory();
            let mut file_size = record.header.data_size;

            // Get attributes from $STANDARD_INFORMATION
            let attributes = if let Some((off, attr)) = record.find_attribute(super::attributes::ATTR_STANDARD_INFO) {
                let si_off = core::mem::size_of::<crate::fs::vladfs::attributes::AttrHeader>();
                if si_off + core::mem::size_of::<super::attributes::StandardInfo>() <= record.raw.len() {
                    super::attributes::StandardInfo::from_bytes(&record.raw[si_off..si_off + core::mem::size_of::<super::attributes::StandardInfo>()])
                        .map(|si| si.file_attributes)
                        .unwrap_or(0)
                } else {
                    0
                }
            } else {
                0
            };

            // Check create disposition
            let create_disposition = stack_ref.parameters.create.options >> 24 & 0xFF;

            // FILE_OPEN_IF = 3 (open if exists, create if not)
            // FILE_CREATE = 1 (fail if exists)
            if create_disposition == 1 {
                // FILE_CREATE - file already exists, fail
                irp_ref.io_status.status = 0xC0000035; // STATUS_OBJECT_NAME_COLLISION
                return 0xC0000035;
            }

            // Allocate file context
            let ctx = alloc::alloc::alloc_zeroed(
                core::alloc::Layout::new::<VladsFileContext>()
            ) as *mut VladsFileContext;
            if ctx.is_null() {
                irp_ref.io_status.status = STATUS_NO_MEMORY;
                return STATUS_NO_MEMORY;
            }

            (*ctx).inode = inode;
            (*ctx).position = 0;
            (*ctx).size = file_size;
            (*ctx).attributes = attributes;
            (*ctx).is_directory = is_dir;
            (*ctx).parent_inode = record.header.parent_inode;
            (*ctx).ref_count = 1;

            if !stack_ref.file_object.is_null() {
                let fo = &mut *(stack_ref.file_object as *mut crate::nt::FileObject);
                fo.section_object = ctx as Pvoid;
            }

            irp_ref.io_status.status = STATUS_SUCCESS;
            irp_ref.io_status.information = 0;
            STATUS_SUCCESS
        }
        Err(_) => {
            // File not found - check if we should create it
            let create_disposition = stack_ref.parameters.create.options >> 24 & 0xFF;

            // FILE_OPEN = 2, FILE_OPEN_IF = 3
            if create_disposition == 2 {
                // FILE_OPEN - must exist
                irp_ref.io_status.status = STATUS_OBJECT_NAME_NOT_FOUND;
                return STATUS_OBJECT_NAME_NOT_FOUND;
            }

            // Create new file
            // Parse parent path and file name
            let parts: alloc::vec::Vec<&str> = path.rsplitn(2, '/').collect();
            let (parent_path, file_name_str) = if parts.len() == 2 {
                (parts[1], parts[0])
            } else {
                ("", parts[0])
            };

            let parent_inode = if parent_path.is_empty() {
                fs.sb_state.sb.root_inode
            } else {
                match fs.open_path(parent_path, read_sector_fn!()) {
                    Ok(inode) => inode,
                    Err(_) => {
                        irp_ref.io_status.status = STATUS_OBJECT_NAME_NOT_FOUND;
                        return STATUS_OBJECT_NAME_NOT_FOUND;
                    }
                }
            };

            let is_directory = stack_ref.parameters.create.options & FILE_DIRECTORY_FILE != 0;

            match fs.create(parent_inode, file_name_str, is_directory, read_sector_fn!(), write_sector_fn!()) {
                Ok(new_inode) => {
                    let ctx = alloc::alloc::alloc_zeroed(
                        core::alloc::Layout::new::<VladsFileContext>()
                    ) as *mut VladsFileContext;
                    if ctx.is_null() {
                        irp_ref.io_status.status = STATUS_NO_MEMORY;
                        return STATUS_NO_MEMORY;
                    }

                    (*ctx).inode = new_inode;
                    (*ctx).position = 0;
                    (*ctx).size = 0;
                    (*ctx).attributes = if is_directory {
                        super::attributes::FILE_ATTRIBUTE_DIRECTORY
                    } else {
                        super::attributes::FILE_ATTRIBUTE_ARCHIVE
                    };
                    (*ctx).is_directory = is_directory;
                    (*ctx).parent_inode = parent_inode;
                    (*ctx).ref_count = 1;

                    if !stack_ref.file_object.is_null() {
                        let fo = &mut *(stack_ref.file_object as *mut crate::nt::FileObject);
                        fo.section_object = ctx as Pvoid;
                    }

                    irp_ref.io_status.status = STATUS_SUCCESS;
                    irp_ref.io_status.information = 0;
                    STATUS_SUCCESS
                }
                Err(_) => {
                    irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
                    STATUS_INVALID_PARAMETER
                }
            }
        }
    }
}

/// IRP_MJ_CLOSE - Close a file
unsafe extern "C" fn vladfs_close(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    if irp.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let irp_ref = &mut *irp;
    let stack = irp_ref.get_stack_location();

    if !stack.is_null() && !(*stack).file_object.is_null() {
        let fo = &*((*stack).file_object as *const crate::nt::FileObject);
        if !fo.section_object.is_null() {
            let ctx = &mut *(fo.section_object as *mut VladsFileContext);
            ctx.ref_count -= 1;
            if ctx.ref_count == 0 {
                alloc::alloc::dealloc(
                    fo.section_object as *mut u8,
                    core::alloc::Layout::new::<VladsFileContext>(),
                );
            }
        }
    }

    irp_ref.io_status.status = STATUS_SUCCESS;
    irp_ref.io_status.information = 0;
    STATUS_SUCCESS
}

/// IRP_MJ_READ - Read file data
unsafe extern "C" fn vladfs_read(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    if device_object.is_null() || irp.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dev_ref = &*device_object;
    let irp_ref = &mut *irp;
    let stack = irp_ref.get_stack_location();
    if stack.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let stack_ref = &*stack;
    let ext = dev_ref.device_extension as *mut VladsVolumeExtension;
    if ext.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let vol_ext = &mut *ext;
    if vol_ext.fs.is_none() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let fs = vol_ext.fs.as_mut().unwrap();

    // Get file context
    let ctx = if !stack_ref.file_object.is_null() {
        let fo = &*(stack_ref.file_object as *const crate::nt::FileObject);
        if !fo.section_object.is_null() {
            &mut *(fo.section_object as *mut VladsFileContext)
        } else {
            irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
            return STATUS_INVALID_PARAMETER;
        }
    } else {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    };

    if ctx.is_directory {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let read_length = stack_ref.parameters.read.length as usize;
    let byte_offset = stack_ref.parameters.read.byte_offset;
    let buffer = irp_ref.user_buffer as *mut u8;

    if buffer.is_null() || read_length == 0 {
        irp_ref.io_status.status = STATUS_SUCCESS;
        irp_ref.io_status.information = 0;
        return STATUS_SUCCESS;
    }

    let offset = if byte_offset != 0 { byte_offset } else { ctx.position };

    let mut read_buf = alloc::vec![0u8; read_length];
    match fs.read_file(ctx.inode, offset, &mut read_buf, read_sector_fn!()) {
        Ok(bytes_read) => {
            core::ptr::copy_nonoverlapping(read_buf.as_ptr(), buffer, bytes_read);
            ctx.position = offset + bytes_read as u64;
            irp_ref.io_status.status = STATUS_SUCCESS;
            irp_ref.io_status.information = bytes_read as u32;
            STATUS_SUCCESS
        }
        Err(_) => {
            irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
            STATUS_INVALID_PARAMETER
        }
    }
}

/// IRP_MJ_WRITE - Write file data
unsafe extern "C" fn vladfs_write(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    if device_object.is_null() || irp.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dev_ref = &*device_object;
    let irp_ref = &mut *irp;
    let stack = irp_ref.get_stack_location();
    if stack.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let stack_ref = &*stack;
    let ext = dev_ref.device_extension as *mut VladsVolumeExtension;
    if ext.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let vol_ext = &mut *ext;
    if vol_ext.fs.is_none() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let fs = vol_ext.fs.as_mut().unwrap();

    // Get file context
    let ctx = if !stack_ref.file_object.is_null() {
        let fo = &*(stack_ref.file_object as *const crate::nt::FileObject);
        if !fo.section_object.is_null() {
            &mut *(fo.section_object as *mut VladsFileContext)
        } else {
            irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
            return STATUS_INVALID_PARAMETER;
        }
    } else {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    };

    if ctx.is_directory {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let write_length = stack_ref.parameters.write.length as usize;
    let byte_offset = stack_ref.parameters.write.byte_offset;
    let buffer = irp_ref.user_buffer as *const u8;

    if buffer.is_null() || write_length == 0 {
        irp_ref.io_status.status = STATUS_SUCCESS;
        irp_ref.io_status.information = 0;
        return STATUS_SUCCESS;
    }

    let offset = if byte_offset != 0 { byte_offset } else { ctx.position };

    let data = core::slice::from_raw_parts(buffer, write_length);
    match fs.write_file(ctx.inode, offset, data, read_sector_fn!(), write_sector_fn!()) {
        Ok(bytes_written) => {
            ctx.position = offset + bytes_written as u64;
            if ctx.position > ctx.size {
                ctx.size = ctx.position;
            }
            irp_ref.io_status.status = STATUS_SUCCESS;
            irp_ref.io_status.information = bytes_written as u32;
            STATUS_SUCCESS
        }
        Err(_) => {
            irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
            STATUS_INVALID_PARAMETER
        }
    }
}

/// IRP_MJ_QUERY_INFORMATION - Query file information
unsafe extern "C" fn vladfs_query_information(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    if device_object.is_null() || irp.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dev_ref = &*device_object;
    let irp_ref = &mut *irp;
    let stack = irp_ref.get_stack_location();
    if stack.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let stack_ref = &*stack;
    let ext = dev_ref.device_extension as *mut VladsVolumeExtension;
    if ext.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let vol_ext = &*ext;
    if vol_ext.fs.is_none() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    // Get file context
    let ctx = if !stack_ref.file_object.is_null() {
        let fo = &*(stack_ref.file_object as *const crate::nt::FileObject);
        if !fo.section_object.is_null() {
            &*(fo.section_object as *const VladsFileContext)
        } else {
            irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
            return STATUS_INVALID_PARAMETER;
        }
    } else {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    };

    let info_class = stack_ref.parameters.query_information.file_information_class;
    let info_length = stack_ref.parameters.query_information.length as usize;
    let buffer = irp_ref.user_buffer as *mut u8;

    if buffer.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    // FileStandardInformation (class 5)
    if info_class == 5 && info_length >= 24 {
        let info = &mut *(buffer as *mut crate::nt::FileStandardInformation);
        info.allocation_size = 0;
        info.end_of_file = ctx.size as i64;
        info.number_of_links = 1;
        info.delete_pending = 0;
        info.directory = if ctx.is_directory { 1 } else { 0 };
        irp_ref.io_status.status = STATUS_SUCCESS;
        irp_ref.io_status.information = 24;
        return STATUS_SUCCESS;
    }

    // FileBasicInformation (class 4)
    if info_class == 4 && info_length >= 40 {
        let info = &mut *(buffer as *mut crate::nt::FileBasicInformation);
        info.creation_time = 0;
        info.last_access_time = 0;
        info.last_write_time = 0;
        info.change_time = 0;
        info.file_attributes = ctx.attributes;
        irp_ref.io_status.status = STATUS_SUCCESS;
        irp_ref.io_status.information = 40;
        return STATUS_SUCCESS;
    }

    irp_ref.io_status.status = STATUS_NOT_IMPLEMENTED;
    STATUS_NOT_IMPLEMENTED
}

/// IRP_MJ_DIRECTORY_CONTROL - List directory contents
unsafe extern "C" fn vladfs_directory_control(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    if device_object.is_null() || irp.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dev_ref = &*device_object;
    let irp_ref = &mut *irp;
    let stack = irp_ref.get_stack_location();
    if stack.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let stack_ref = &*stack;
    let ext = dev_ref.device_extension as *mut VladsVolumeExtension;
    if ext.is_null() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let vol_ext = &mut *ext;
    if vol_ext.fs.is_none() {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let fs = vol_ext.fs.as_mut().unwrap();

    // Get file context
    let ctx = if !stack_ref.file_object.is_null() {
        let fo = &*(stack_ref.file_object as *const crate::nt::FileObject);
        if !fo.section_object.is_null() {
            &*(fo.section_object as *const VladsFileContext)
        } else {
            irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
            return STATUS_INVALID_PARAMETER;
        }
    } else {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    };

    if !ctx.is_directory {
        irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
        return STATUS_INVALID_PARAMETER;
    }

    let buffer = irp_ref.user_buffer as *mut u8;
    let buffer_length = irp_ref.io_status.information as usize;

    if buffer.is_null() || buffer_length == 0 {
        irp_ref.io_status.status = STATUS_SUCCESS;
        irp_ref.io_status.information = 0;
        return STATUS_SUCCESS;
    }

    // List directory
    match fs.list_directory(ctx.inode, read_sector_fn!()) {
        Ok(entries) => {
            let mut offset = 0usize;

            for (inode, name, attrs) in &entries {
                // FILE_DIRECTORY_INFORMATION layout:
                //   next_entry_offset: u32 (0 for last)
                //   file_index: u64
                //   creation_time: i64
                //   last_access_time: i64
                //   last_write_time: i64
                //   change_time: i64
                //   end_of_file: i64
                //   allocation_size: i64
                //   file_attributes: u32
                //   file_name_length: u32
                //   file_name: [u16; N]

                let name_utf16: alloc::vec::Vec<u16> = name.encode_utf16().collect();
                let name_bytes = name_utf16.len() * 2;
                let entry_size = 80 + name_bytes; // Fixed header + name

                if offset + entry_size > buffer_length {
                    break;
                }

                let entry = &mut *(buffer.add(offset) as *mut [u8; 80] as *mut FileDirectoryInformation);
                entry.next_entry_offset = 0; // Will set for non-last
                entry.file_index = *inode;
                entry.creation_time = 0;
                entry.last_access_time = 0;
                entry.last_write_time = 0;
                entry.change_time = 0;

                // Get file size from child record
                let child_size = match fs.read_mft_record(*inode, read_sector_fn!()) {
                    Ok(rec) => rec.header.data_size,
                    Err(_) => 0,
                };

                entry.end_of_file = child_size as i64;
                entry.allocation_size = child_size as i64;
                entry.file_attributes = *attrs;
                entry.file_name_length = name_bytes as u32;

                // Write name (UTF-16LE)
                let name_ptr = buffer.add(offset + 80) as *mut u16;
                for (i, &ch) in name_utf16.iter().enumerate() {
                    *name_ptr.add(i) = ch;
                }

                offset += entry_size;
            }

            // Set next_entry_offset for all but last
            // (simplified - just leave 0 for all)

            irp_ref.io_status.status = STATUS_SUCCESS;
            irp_ref.io_status.information = offset as u32;
            STATUS_SUCCESS
        }
        Err(_) => {
            irp_ref.io_status.status = STATUS_INVALID_PARAMETER;
            STATUS_INVALID_PARAMETER
        }
    }
}

/// FILE_DIRECTORY_INFORMATION structure
#[repr(C)]
#[derive(Debug, Clone, Copy)]
struct FileDirectoryInformation {
    next_entry_offset: u32,
    file_index: u64,
    creation_time: i64,
    last_access_time: i64,
    last_write_time: i64,
    change_time: i64,
    end_of_file: i64,
    allocation_size: i64,
    file_attributes: u32,
    file_name_length: u32,
}

// ============================================================
// Driver Registration
// ============================================================

/// Initialize the VladFS driver and register with I/O Manager
pub fn vladfs_driver_init() -> NtStatus {
    if VLADS_DRIVER_INITIALIZED.swap(true, Ordering::AcqRel) {
        return STATUS_SUCCESS; // Already initialized
    }

    crate::kernel_log!("[VladsFs] Initializing filesystem driver...");

    // Allocate driver object
    let driver = io_allocate_driver(0, core::ptr::null_mut());
    if driver.is_null() {
        crate::kernel_log!("[VladsFs] Failed to allocate driver object");
        return STATUS_NO_MEMORY;
    }

    let drv = unsafe { &mut *driver };

    // Set dispatch functions
    drv.set_dispatch(IRP_MJ_CREATE, vladfs_create);
    drv.set_dispatch(IRP_MJ_CLOSE, vladfs_close);
    drv.set_dispatch(IRP_MJ_READ, vladfs_read);
    drv.set_dispatch(IRP_MJ_WRITE, vladfs_write);
    drv.set_dispatch(IRP_MJ_QUERY_INFORMATION, vladfs_query_information);
    drv.set_dispatch(IRP_MJ_DIRECTORY_CONTROL, vladfs_directory_control);

    VLADS_DRIVER_OBJECT.store(driver, Ordering::Release);

    crate::kernel_log!("[VladsFs] Driver initialized successfully");
    STATUS_SUCCESS
}

/// Mount a VladFS volume on a device
pub fn vladfs_mount_volume(
    driver_object: *mut DriverObject,
    partition_start_lba: u64,
    partition_sectors: u64,
    drive_letter: u8,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    crate::kernel_log!(
        "[VladsFs] Mounting volume {} (LBA={}, sectors={})",
        drive_letter as char,
        partition_start_lba,
        partition_sectors
    );

    // Try to open existing filesystem
    let fs_result = VladsFs::open(
        partition_start_lba,
        partition_sectors,
        read_sector_fn!(),
        write_sector_fn!(),
    );

    let fs = match fs_result {
        Ok(fs) => {
            crate::kernel_log!("[VladsFs] Existing filesystem found");
            fs
        }
        Err(_) => {
            crate::kernel_log!("[VladsFs] No filesystem found, formatting...");
            match VladsFs::format(
                partition_start_lba,
                partition_sectors,
                read_sector_fn!(),
                write_sector_fn!(),
            ) {
                Ok(fs) => fs,
                Err(_) => {
                    crate::kernel_log!("[VladsFs] Failed to format volume");
                    return STATUS_INVALID_PARAMETER;
                }
            }
        }
    };

    // Create device object
    let mut device_object: *mut IoDeviceObject = core::ptr::null_mut();
    let status = io_create_device(
        driver_object,
        core::mem::size_of::<VladsVolumeExtension>() as u32,
        core::ptr::null_mut(), // Device name
        FILE_DEVICE_UNKNOWN,
        0,
        0, // Not exclusive
        &mut device_object,
    );

    if status != STATUS_SUCCESS || device_object.is_null() {
        crate::kernel_log!("[VladsFs] Failed to create device object: {}", status);
        return status;
    }

    // Initialize device extension
    let ext = unsafe { &mut *((*device_object).device_extension as *mut VladsVolumeExtension) };
    ext.fs = Some(fs);
    ext.device_number = 0;
    ext.mounted = true;
    ext.drive_letter = drive_letter;
    ext.volume_label = [0u16; 32];
    ext.total_space = partition_sectors * 512;
    ext.free_space = fs.allocator.free_count() * VLFS_BLOCK_SIZE as u64;
    ext.sector_size = VLFS_SECTOR_SIZE as u16;
    ext.block_size = VLFS_BLOCK_SIZE;
    vladfs_register_volume(drive_letter, ext as *mut VladsVolumeExtension);

    crate::kernel_log!(
        "[VladsFs] Volume {} mounted successfully (device={:p})",
        drive_letter as char,
        device_object
    );

    STATUS_SUCCESS
}

/// Get the driver object
pub fn get_driver_object() -> *mut DriverObject {
    VLADS_DRIVER_OBJECT.load(Ordering::Acquire)
}

// ============================================================
// Volume space registry (for NtQueryVolumeInformationFile)
// ============================================================

const VLADFS_MAX_VOLUMES: usize = 16;

struct VladsVolumeSlot {
    letter: u8,
    extension: *mut VladsVolumeExtension,
    used: bool,
}

impl Clone for VladsVolumeSlot {
    fn clone(&self) -> Self {
        *self
    }
}
impl Copy for VladsVolumeSlot {}

static mut VLADFS_VOLUMES: [VladsVolumeSlot; VLADFS_MAX_VOLUMES] = [VladsVolumeSlot {
    letter: 0,
    extension: core::ptr::null_mut(),
    used: false,
}; VLADFS_MAX_VOLUMES];

unsafe fn vladfs_register_volume(letter: u8, ext: *mut VladsVolumeExtension) {
    let mut i = 0;
    while i < VLADFS_MAX_VOLUMES {
        if !VLADFS_VOLUMES[i].used {
            VLADFS_VOLUMES[i].letter = letter;
            VLADFS_VOLUMES[i].extension = ext;
            VLADFS_VOLUMES[i].used = true;
            return;
        }
        i += 1;
    }
}

/// Query total/free bytes for a drive letter (free recomputed live).
pub fn vladfs_query_space(
    letter: u8,
    total_out: *mut u64,
    free_out: *mut u64,
) -> NtStatus {
    if total_out.is_null() || free_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    unsafe {
        let mut i = 0;
        while i < VLADFS_MAX_VOLUMES {
            if VLADFS_VOLUMES[i].used && VLADFS_VOLUMES[i].letter == letter {
                let ext = &mut *VLADFS_VOLUMES[i].extension;
                *total_out = ext.total_space;
                // Recompute free from the block allocator (live value).
                if let Some(ref fs) = ext.fs {
                    ext.free_space = fs.allocator.free_count() * VLFS_BLOCK_SIZE as u64;
                }
                *free_out = ext.free_space;
                return STATUS_SUCCESS;
            }
            i += 1;
        }
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}
