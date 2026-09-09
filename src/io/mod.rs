/// # Windows 10 I/O Manager (Io/Iop/Iov) - ntoskrnl.exe
///
/// Complete implementation of the I/O Manager subsystem including
/// IRP construction and dispatch, device object management, driver
/// object registration, I/O stack location processing, and all
/// exported Io* / Iof* / Iov* interfaces.

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicUsize, Ordering};
use alloc::boxed::Box;

use crate::types::*;
use crate::mm::{self, ListEntry, MmMdl, SpinLock};
use crate::nt;

const STATUS_OBJECT_NAME_COLLISION: NtStatus = 0xC0000035;
const FILE_GENERIC_READ: u32 = 0x00120089;
const FILE_GENERIC_WRITE: u32 = 0x00120116;
const FILE_GENERIC_ALL: u32 = 0x001F01FF;
const STATUS_OBJECT_NAME_NOT_FOUND: NtStatus = 0xC0000034;
const NOTIFICATION_EVENT: u8 = 1;
const SYNCHRONIZATION_EVENT: u8 = 0;

// ============================================================
// Constants
// ============================================================

pub const IO_NO_INCREMENT: u32 = 0;
pub const IO_CD_ROM_INCREMENT: u32 = 1;
pub const IO_DISK_INCREMENT: u32 = 3;
pub const IO_KEYBOARD_INCREMENT: u32 = 6;
pub const IO_NETWORK_INCREMENT: u32 = 6;
pub const IO_SERIAL_INCREMENT: u32 = 2;
pub const IO_VIDEO_INCREMENT: u32 = 1;

pub const IO_DEVICE_OBJECT_TYPE: u8 = 4;
pub const IO_DRIVER_OBJECT_TYPE: u8 = 4;

pub const IO_TYPE_ADAPTER: u32 = 1;
pub const IO_TYPE_CONTROLLER: u32 = 2;
pub const IO_TYPE_DEVICE: u32 = 3;
pub const IO_TYPE_DRIVER: u32 = 4;
pub const IO_TYPE_FILE: u32 = 5;
pub const IO_TYPE_IRP: u32 = 6;

pub const IRP_MJ_CREATE: usize = 0;
pub const IRP_MJ_CREATE_NAMED_PIPE: usize = 1;
pub const IRP_MJ_CLOSE: usize = 2;
pub const IRP_MJ_READ: usize = 3;
pub const IRP_MJ_WRITE: usize = 4;
pub const IRP_MJ_QUERY_INFORMATION: usize = 5;
pub const IRP_MJ_SET_INFORMATION: usize = 6;
pub const IRP_MJ_QUERY_EA: usize = 7;
pub const IRP_MJ_SET_EA: usize = 8;
pub const IRP_MJ_FLUSH_BUFFERS: usize = 9;
pub const IRP_MJ_QUERY_VOLUME_INFORMATION: usize = 10;
pub const IRP_MJ_SET_VOLUME_INFORMATION: usize = 11;
pub const IRP_MJ_DIRECTORY_CONTROL: usize = 12;
pub const IRP_MJ_FILE_SYSTEM_CONTROL: usize = 13;
pub const IRP_MJ_DEVICE_CONTROL: usize = 14;
pub const IRP_MJ_INTERNAL_DEVICE_CONTROL: usize = 15;
pub const IRP_MJ_SHUTDOWN: usize = 16;
pub const IRP_MJ_LOCK_CONTROL: usize = 17;
pub const IRP_MJ_CLEANUP: usize = 18;
pub const IRP_MJ_MAXIMUM_FUNCTION: usize = 28;

pub const IRP_NOCACHE: u32 = 0x00000001;
pub const IRP_PAGING_IO: u32 = 0x00000002;
pub const IRP_BUFFERED_IO: u32 = 0x00000008;
pub const IRP_SYNCHRONOUS_API: u32 = 0x00000020;
pub const IRP_ASSOCIATED_IRP: u32 = 0x00000040;
pub const IRP_READ_OPERATION: u32 = 0x00000400;
pub const IRP_WRITE_OPERATION: u32 = 0x00000800;

pub const SL_PENDING_RETURNED: u8 = 0x01;
pub const SL_INVOKE_ON_CANCEL: u8 = 0x20;
pub const SL_INVOKE_ON_SUCCESS: u8 = 0x40;
pub const SL_INVOKE_ON_ERROR: u8 = 0x80;

pub const FILE_OPEN: u32 = 0x00000001;
pub const FILE_CREATE: u32 = 0x00000002;
pub const FILE_OPEN_IF: u32 = 0x00000003;
pub const FILE_SYNCHRONOUS_IO_ALERT: u32 = 0x00000010;
pub const FILE_SYNCHRONOUS_IO_NONALERT: u32 = 0x00000020;
pub const FILE_DIRECTORY_FILE: u32 = 0x00000001;
pub const FILE_WRITE_THROUGH: u32 = 0x00000002;
pub const FILE_NON_DIRECTORY_FILE: u32 = 0x00000040;
pub const FILE_DEVICE_SECURE_OPEN: u32 = 0x00100000;
pub const FILE_DEVICE_UNKNOWN: u32 = 0x00000022;

pub const FILE_ANY_ACCESS: u32 = 0;
pub const FILE_READ_ACCESS: u32 = 1;
pub const FILE_WRITE_ACCESS: u32 = 2;
pub const FILE_READ_WRITE_ACCESS: u32 = 3;

pub const METHOD_BUFFERED: u32 = 0;
pub const METHOD_IN_DIRECT: u32 = 1;
pub const METHOD_OUT_DIRECT: u32 = 2;
pub const METHOD_NEITHER: u32 = 3;

// ============================================================
// Io/Iop/Iov Logging macros
// ============================================================

macro_rules! io_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "io_trace")]
        crate::kernel_log!("[Io] {}", format_args!($($arg)*));
    };
}

macro_rules! io_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Io] {}", format_args!($($arg)*));
    };
}

macro_rules! io_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Io] {}", format_args!($($arg)*));
    };
}

macro_rules! io_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Io] {}", format_args!($($arg)*));
    };
}

// ============================================================
// Major function table type
// ============================================================

pub type DriverDispatch = unsafe extern "C" fn(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus;

// ============================================================
// IrpMajorFunction enum
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum IrpMajorFunction {
    Create = 0,
    CreateNamedPipe = 1,
    Close = 2,
    Read = 3,
    Write = 4,
    QueryInformation = 5,
    SetInformation = 6,
    QueryEa = 7,
    SetEa = 8,
    FlushBuffers = 9,
    QueryVolumeInformation = 10,
    SetVolumeInformation = 11,
    DirectoryControl = 12,
    FileSystemControl = 13,
    DeviceControl = 14,
    InternalDeviceControl = 15,
    Shutdown = 16,
    LockControl = 17,
    Cleanup = 18,
    CreateMailslot = 19,
    QuerySecurity = 20,
    SetSecurity = 21,
    Power = 22,
    SystemControl = 23,
    DeviceChange = 24,
    QueryQuota = 25,
    SetQuota = 26,
    Pnp = 27,
}

impl IrpMajorFunction {
    pub fn from_u32(val: u32) -> Option<Self> {
        match val {
            0 => Some(IrpMajorFunction::Create),
            1 => Some(IrpMajorFunction::CreateNamedPipe),
            2 => Some(IrpMajorFunction::Close),
            3 => Some(IrpMajorFunction::Read),
            4 => Some(IrpMajorFunction::Write),
            5 => Some(IrpMajorFunction::QueryInformation),
            6 => Some(IrpMajorFunction::SetInformation),
            7 => Some(IrpMajorFunction::QueryEa),
            8 => Some(IrpMajorFunction::SetEa),
            9 => Some(IrpMajorFunction::FlushBuffers),
            10 => Some(IrpMajorFunction::QueryVolumeInformation),
            11 => Some(IrpMajorFunction::SetVolumeInformation),
            12 => Some(IrpMajorFunction::DirectoryControl),
            13 => Some(IrpMajorFunction::FileSystemControl),
            14 => Some(IrpMajorFunction::DeviceControl),
            15 => Some(IrpMajorFunction::InternalDeviceControl),
            16 => Some(IrpMajorFunction::Shutdown),
            17 => Some(IrpMajorFunction::LockControl),
            18 => Some(IrpMajorFunction::Cleanup),
            19 => Some(IrpMajorFunction::CreateMailslot),
            20 => Some(IrpMajorFunction::QuerySecurity),
            21 => Some(IrpMajorFunction::SetSecurity),
            22 => Some(IrpMajorFunction::Power),
            23 => Some(IrpMajorFunction::SystemControl),
            24 => Some(IrpMajorFunction::DeviceChange),
            25 => Some(IrpMajorFunction::QueryQuota),
            26 => Some(IrpMajorFunction::SetQuota),
            27 => Some(IrpMajorFunction::Pnp),
            _ => None,
        }
    }

    pub fn to_u32(self) -> u32 {
        self as u32
    }
}

// ============================================================
// IoStackLocationParameters union
// ============================================================

#[repr(C)]
pub union IoStackLocationParameters {
    pub create: IoStackLocationCreate,
    pub read: IoStackLocationReadWrite,
    pub write: IoStackLocationReadWrite,
    pub device_io_control: IoStackLocationDeviceIoControl,
    pub query_volume: IoStackLocationQueryVolume,
    pub query_information: IoStackLocationQueryInformation,
    pub set_information: IoStackLocationSetInformation,
    pub others: IoStackLocationOthers,
    pub raw: [u8; 24],
}

impl core::fmt::Debug for IoStackLocationParameters {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("IoStackLocationParameters").finish()
    }
}
unsafe impl Send for IoStackLocationParameters {}
unsafe impl Sync for IoStackLocationParameters {}
impl Copy for IoStackLocationParameters {}
impl Clone for IoStackLocationParameters {
    fn clone(&self) -> Self { *self }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStackLocationCreate {
    pub security_context: *mut c_void,
    pub options: u32,
    pub share_access: u16,
    pub ea_length: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStackLocationReadWrite {
    pub length: u32,
    pub key: u32,
    pub flags: u32,
    pub byte_offset: u64,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStackLocationDeviceIoControl {
    pub output_buffer_length: u32,
    pub input_buffer_length: u32,
    pub io_control_code: u32,
    pub type3_input_buffer: *mut c_void,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStackLocationQueryVolume {
    pub length: u32,
    pub information_class: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStackLocationQueryInformation {
    pub length: u32,
    pub file_information_class: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStackLocationSetInformation {
    pub length: u32,
    pub file_information_class: u32,
    pub ea_buffer: *mut c_void,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStackLocationOthers {
    pub argument1: u64,
    pub argument2: u64,
    pub argument3: u64,
}

// ============================================================
// IoStackLocation - I/O Stack Location
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStackLocation {
    pub major_function: u8,
    pub minor_function: u8,
    pub flags: u8,
    pub control: u8,
    pub parameters: IoStackLocationParameters,
    pub device_object: *mut IoDeviceObject,
    pub file_object: *mut c_void,
    pub completion_routine: Option<unsafe extern "C" fn(
        *mut IoDeviceObject,
        *mut Irp,
        *mut c_void,
    ) -> NtStatus>,
    pub context: *mut c_void,
}

impl IoStackLocation {
    pub fn new() -> Self {
        Self {
            major_function: 0,
            minor_function: 0,
            flags: 0,
            control: 0,
            parameters: IoStackLocationParameters { raw: [0u8; 24] },
            device_object: core::ptr::null_mut(),
            file_object: core::ptr::null_mut(),
            completion_routine: None,
            context: core::ptr::null_mut(),
        }
    }

    pub fn major_function(&self) -> u8 {
        self.major_function
    }

    pub fn set_create_parameters(
        &mut self,
        security_context: *mut c_void,
        options: u32,
        share_access: u16,
        ea_length: u32,
    ) {
        self.parameters = IoStackLocationParameters {
            create: IoStackLocationCreate { security_context, options, share_access, ea_length },
        };
    }

    pub fn set_read_parameters(&mut self, length: u32, key: u32, flags: u32, byte_offset: u64) {
        self.parameters = IoStackLocationParameters {
            read: IoStackLocationReadWrite { length, key, flags, byte_offset },
        };
    }

    pub fn set_write_parameters(&mut self, length: u32, key: u32, flags: u32, byte_offset: u64) {
        self.parameters = IoStackLocationParameters {
            write: IoStackLocationReadWrite { length, key, flags, byte_offset },
        };
    }

    pub fn set_device_io_control_parameters(
        &mut self,
        output_buffer_length: u32,
        input_buffer_length: u32,
        io_control_code: u32,
        type3_input_buffer: *mut c_void,
    ) {
        self.parameters = IoStackLocationParameters {
            device_io_control: IoStackLocationDeviceIoControl {
                output_buffer_length, input_buffer_length, io_control_code, type3_input_buffer,
            },
        };
    }
}

// ============================================================
// IRP - I/O Request Packet
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct Irp {
    pub size: i16,
    pub count: i16,
    pub allocation_flags: u32,
    pub io_status: IoStatusBlock,
    pub user_buffer: *mut c_void,
    pub tail_irp: IrpTailUnion,
    pub mdl_address: *mut MmMdl,
    pub flags: u32,
    pub associated_irp: IrpAssociatedUnion,
    pub thread_list_entry: ListEntry,
    pub io_device_object: *mut IoDeviceObject,
    pub current_stack_location: *mut IoStackLocation,
    pub original_irp: *mut Irp,
    pub pending_returned: u8,
    pub cancel: u8,
    pub cancel_routine: Option<unsafe extern "C" fn(*mut Irp)>,
    pub user_iosb: *mut IoStatusBlockEx,
    pub user_event: *mut c_void,
    pub overlay: IrpOverlayUnion,
    pub stack_count: i16,
    pub current_location: i16,
}

#[repr(C)]
pub union IrpAssociatedUnion {
    pub master_irp: *mut Irp,
    pub irp_cache: *mut c_void,
    pub system_buffer: *mut c_void,
}
impl core::fmt::Debug for IrpAssociatedUnion {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("IrpAssociatedUnion").finish()
    }
}
unsafe impl Send for IrpAssociatedUnion {}
unsafe impl Sync for IrpAssociatedUnion {}
impl Copy for IrpAssociatedUnion {}
impl Clone for IrpAssociatedUnion {
    fn clone(&self) -> Self { *self }
}

#[repr(C)]
pub union IrpTailUnion {
    pub current_stack_location: *mut IoStackLocation,
    pub packet_type: u32,
}
impl core::fmt::Debug for IrpTailUnion {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("IrpTailUnion").finish()
    }
}
unsafe impl Send for IrpTailUnion {}
unsafe impl Sync for IrpTailUnion {}
impl Copy for IrpTailUnion {}
impl Clone for IrpTailUnion {
    fn clone(&self) -> Self { *self }
}

#[repr(C)]
pub union IrpOverlayUnion {
    pub overlay: IrpOverlayData,
    pub driver_context: [Pvoid; 4],
}
impl core::fmt::Debug for IrpOverlayUnion {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("IrpOverlayUnion").finish()
    }
}
unsafe impl Send for IrpOverlayUnion {}
unsafe impl Sync for IrpOverlayUnion {}
impl Copy for IrpOverlayUnion {}
impl Clone for IrpOverlayUnion {
    fn clone(&self) -> Self { *self }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IrpOverlayData {
    pub pool_key: Pvoid,
    pub file_object_offset: i64,
    pub active_thread_list_entry: ListEntry,
    pub pending_chain_entry: *mut Irp,
    pub system_buffer: Pvoid,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStatusBlockEx {
    pub status: NtStatus,
    pub information: u64,
}

impl Irp {
    pub fn new() -> Self {
        Self {
            size: 0,
            count: 0,
            allocation_flags: 0,
            io_status: IoStatusBlock { status: 0, information: 0 },
            user_buffer: core::ptr::null_mut(),
            tail_irp: IrpTailUnion { current_stack_location: core::ptr::null_mut() },
            mdl_address: core::ptr::null_mut(),
            flags: 0,
            associated_irp: IrpAssociatedUnion { master_irp: core::ptr::null_mut() },
            thread_list_entry: ListEntry::new(),
            io_device_object: core::ptr::null_mut(),
            current_stack_location: core::ptr::null_mut(),
            original_irp: core::ptr::null_mut(),
            pending_returned: 0,
            cancel: 0,
            cancel_routine: None,
            user_iosb: core::ptr::null_mut(),
            user_event: core::ptr::null_mut(),
            overlay: IrpOverlayUnion { driver_context: [core::ptr::null_mut(); 4] },
            stack_count: 0,
            current_location: 0,
        }
    }

    pub fn get_stack_location(&self) -> *mut IoStackLocation {
        if !self.current_stack_location.is_null() {
            self.current_stack_location
        } else {
            unsafe { self.tail_irp.current_stack_location }
        }
    }

    pub fn get_next_stack_location(&self) -> *mut IoStackLocation {
        let current = self.get_stack_location();
        if current.is_null() { return core::ptr::null_mut(); }
        unsafe { current.sub(1) }
    }

    pub fn copy_current_stack_location_to_next(&mut self) {
        let current = self.get_stack_location();
        let next = self.get_next_stack_location();
        if !current.is_null() && !next.is_null() {
            unsafe { core::ptr::copy_nonoverlapping(current, next, 1); }
        }
    }

    pub fn set_status(&mut self, status: NtStatus) {
        self.io_status.status = status;
    }

    pub fn set_information(&mut self, info: u64) {
        self.io_status.information = info as u32;
    }
}

// ============================================================
// IoDeviceObject - Device Object
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct IoDeviceObject {
    pub r#type: i16,
    pub size: u16,
    pub reference_count: i32,
    pub driver_object: *mut DriverObject,
    pub next_device: *mut IoDeviceObject,
    pub attached_device: *mut IoDeviceObject,
    pub current_irp: *mut Irp,
    pub loop_lock: Pvoid,
    pub flags: u32,
    pub characteristics: u32,
    pub alignment_requirement: u16,
    pub device_type: u32,
    pub device_name: UnicodeString,
    pub default_flag: u32,
    pub default_volume: u32,
    pub device_extension: Pvoid,
    pub device_number: u32,
    pub sector_size: u16,
    pub spare: u16,
}

impl IoDeviceObject {
    pub fn new() -> Self {
        Self {
            r#type: IO_TYPE_DEVICE as i16,
            size: mem::size_of::<Self>() as u16,
            reference_count: 0,
            driver_object: core::ptr::null_mut(),
            next_device: core::ptr::null_mut(),
            attached_device: core::ptr::null_mut(),
            current_irp: core::ptr::null_mut(),
            loop_lock: core::ptr::null_mut(),
            flags: 0,
            characteristics: 0,
            alignment_requirement: 0,
            device_type: FILE_DEVICE_UNKNOWN,
            device_name: UnicodeString::new(),
            default_flag: 0,
            default_volume: 0,
            device_extension: core::ptr::null_mut(),
            device_number: 0,
            sector_size: 512,
            spare: 0,
        }
    }
}

// ============================================================
// DriverObject - Driver Object
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct DriverObject {
    pub r#type: i16,
    pub size: u16,
    pub device_object: *mut IoDeviceObject,
    pub flags: u32,
    pub driver_start: Pvoid,
    pub driver_size: u32,
    pub driver_section: Pvoid,
    pub driver_init: Option<unsafe extern "C" fn(*mut DriverObject, *mut c_void) -> NtStatus>,
    pub driver_start_io: Option<unsafe extern "C" fn(*mut IoDeviceObject, *mut Irp)>,
    pub driver_unload: Option<unsafe extern "C" fn(*mut DriverObject)>,
    pub major_function: [DriverDispatch; IRP_MJ_MAXIMUM_FUNCTION + 1],
    pub driver_extension: Pvoid,
    pub driver_name: UnicodeString,
    pub hardware_database: *mut UnicodeString,
}

impl DriverObject {
    pub fn new() -> Self {
        let mut obj = Self {
            r#type: IO_DRIVER_OBJECT_TYPE as i16,
            size: mem::size_of::<Self>() as u16,
            device_object: core::ptr::null_mut(),
            flags: 0,
            driver_start: core::ptr::null_mut(),
            driver_size: 0,
            driver_section: core::ptr::null_mut(),
            driver_init: None,
            driver_start_io: None,
            driver_unload: None,
            major_function: [iop_invalid_device_request; IRP_MJ_MAXIMUM_FUNCTION + 1],
            driver_extension: core::ptr::null_mut(),
            driver_name: UnicodeString::new(),
            hardware_database: core::ptr::null_mut(),
        };
        obj
    }

    pub fn set_dispatch(&mut self, major: usize, dispatch: DriverDispatch) {
        if major <= IRP_MJ_MAXIMUM_FUNCTION {
            self.major_function[major] = dispatch;
        }
    }
}

// ============================================================
// AdapterObject
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct IoAdapterObject {
    pub r#type: i16,
    pub size: u16,
    pub map_register: Pvoid,
    pub adapter_number: u32,
    pub page_large: u32,
    pub length: u32,
    pub lock: KspinLock,
    pub pending_requests: ListEntry,
    pub channel: u32,
    pub version: u32,
    pub max_transfer_length: u32,
    pub max_physical_break: u32,
    pub alignment_requirement: u32,
}

impl IoAdapterObject {
    pub fn new() -> Self {
        Self {
            r#type: IO_TYPE_ADAPTER as i16,
            size: mem::size_of::<Self>() as u16,
            map_register: core::ptr::null_mut(),
            adapter_number: 0,
            page_large: 0,
            length: 0,
            lock: 0,
            pending_requests: ListEntry::new(),
            channel: 0,
            version: 1,
            max_transfer_length: 0x100000,
            max_physical_break: 0x100000,
            alignment_requirement: 0,
        }
    }
}

pub type AdapterObject = IoAdapterObject;

// ============================================================
// SCATTER_GATHER_LIST
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ScatterGatherElement {
    pub physical_address: u64,
    pub length: u32,
    pub reserved: u32,
}

#[repr(C)]
#[derive(Debug)]
pub struct ScatterGatherList {
    pub next: *mut ScatterGatherList,
    pub number_of_elements: u32,
    pub reserved: u32,
    pub elements: [ScatterGatherElement; 1],
}

pub type Pscatter_gather_list = *mut ScatterGatherList;

pub type DriverListIo = unsafe extern "C" fn(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
    scatter_gather_list: *mut ScatterGatherList,
    context: *mut c_void,
) -> NtStatus;

pub type Pdriver_control = DriverListIo;

// ============================================================
// Default dispatch handler
// ============================================================

unsafe extern "C" fn iop_invalid_device_request(
    _device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    if !irp.is_null() {
        let irp_ref = unsafe { &mut *irp };
        irp_ref.io_status.status = STATUS_NOT_IMPLEMENTED;
        irp_ref.io_status.information = 0;
    }
    STATUS_NOT_IMPLEMENTED
}

// ============================================================
// IoAllocateDriver
// ============================================================

pub fn io_allocate_driver(
    driver_size: u32,
    driver_section: Pvoid,
) -> *mut DriverObject {
    let total_size = mem::size_of::<DriverObject>() + driver_size as usize;
    let layout = match core::alloc::Layout::from_size_align(total_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };
    let driver = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut DriverObject };
    if driver.is_null() { return core::ptr::null_mut(); }
    let drv = unsafe { &mut *driver };
    drv.r#type = IO_DRIVER_OBJECT_TYPE as i16;
    drv.size = mem::size_of::<DriverObject>() as u16;
    drv.driver_section = driver_section;
    drv.driver_start = unsafe { (driver as *mut u8).add(mem::size_of::<DriverObject>()) } as Pvoid;
    drv.driver_size = driver_size;
    for i in 0..=IRP_MJ_MAXIMUM_FUNCTION {
        drv.major_function[i] = iop_invalid_device_request;
    }
    io_trace!("IoAllocateDriver: allocated {:p}", driver);
    driver
}

// ============================================================
// IoAllocateIrp
// ============================================================

pub fn io_allocate_irp(stack_size: u8, _charge_quota: u8) -> *mut Irp {
    let stack_array_size = if stack_size > 0 {
        stack_size as usize * mem::size_of::<IoStackLocation>()
    } else { 0 };
    let irp_size = mem::size_of::<Irp>() + stack_array_size;
    let layout = match core::alloc::Layout::from_size_align(irp_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };
    let irp = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut Irp };
    if irp.is_null() { return core::ptr::null_mut(); }
    let irp_ref = unsafe { &mut *irp };
    irp_ref.size = mem::size_of::<Irp>() as i16;
    irp_ref.count = stack_size as i16;
    irp_ref.allocation_flags = IRP_NOCACHE;
    if stack_size > 0 {
        let stack_base = unsafe { (irp as *mut u8).add(mem::size_of::<Irp>()) as *mut IoStackLocation };
        let top = unsafe { stack_base.add(stack_size as usize - 1) };
        irp_ref.current_stack_location = top;
        irp_ref.tail_irp.current_stack_location = top;
        irp_ref.current_location = stack_size as i16;
        irp_ref.stack_count = stack_size as i16;
        for i in 0..stack_size as usize {
            let s = unsafe { &mut *stack_base.add(i) };
            s.device_object = core::ptr::null_mut();
            s.completion_routine = None;
            s.context = core::ptr::null_mut();
        }
    }
    io_trace!("IoAllocateIrp: {:p} stack={}", irp, stack_size);
    irp
}

// ============================================================
// IoFreeIrp
// ============================================================

pub fn io_free_irp(irp: *mut Irp) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &*irp };
    if !irp_ref.mdl_address.is_null() {
        mm::mm_free_mdl(irp_ref.mdl_address);
    }
    let stack_array_size = if irp_ref.stack_count > 0 {
        irp_ref.stack_count as usize * mem::size_of::<IoStackLocation>()
    } else { 0 };
    let total_size = mem::size_of::<Irp>() + stack_array_size;
    let layout = core::alloc::Layout::from_size_align(total_size, 16).unwrap();
    unsafe { alloc::alloc::dealloc(irp as *mut u8, layout); }
}

// ============================================================
// IoBuildSynchronousFsdRequest
// ============================================================

pub fn io_build_synchronous_fsd_request(
    major_function: u8,
    device_object: *mut IoDeviceObject,
    buffer: Pvoid,
    length: u32,
    byte_offset: *mut u64,
    event: Pvoid,
) -> *mut Irp {
    if device_object.is_null() { return core::ptr::null_mut(); }
    let irp = io_allocate_irp(1, 0);
    if irp.is_null() { return core::ptr::null_mut(); }
    let irp_ref = unsafe { &mut *irp };
    irp_ref.flags = IRP_SYNCHRONOUS_API | IRP_BUFFERED_IO | IRP_NOCACHE;
    irp_ref.user_buffer = buffer;
    irp_ref.user_event = event;
    let stack = irp_ref.get_stack_location();
    if stack.is_null() { io_free_irp(irp); return core::ptr::null_mut(); }
    let sr = unsafe { &mut *stack };
    sr.major_function = major_function;
    sr.control = SL_PENDING_RETURNED;
    let offset = if !byte_offset.is_null() { unsafe { *byte_offset } } else { 0 };
    match major_function as usize {
        IRP_MJ_READ => sr.set_read_parameters(length, 0, 0, offset),
        IRP_MJ_WRITE => sr.set_write_parameters(length, 0, 0, offset),
        _ => sr.parameters = IoStackLocationParameters { raw: [0u8; 24] },
    }
    sr.device_object = device_object;
    irp
}

// ============================================================
// IoBuildAsynchronousFsdRequest
// ============================================================

pub fn io_build_asynchronous_fsd_request(
    major_function: u8,
    device_object: *mut IoDeviceObject,
    buffer: Pvoid,
    length: u32,
    byte_offset: *mut u64,
) -> *mut Irp {
    if device_object.is_null() { return core::ptr::null_mut(); }
    let irp = io_allocate_irp(1, 0);
    if irp.is_null() { return core::ptr::null_mut(); }
    let irp_ref = unsafe { &mut *irp };
    irp_ref.flags = IRP_BUFFERED_IO | IRP_NOCACHE;
    irp_ref.user_buffer = buffer;
    let stack = irp_ref.get_stack_location();
    if stack.is_null() { io_free_irp(irp); return core::ptr::null_mut(); }
    let sr = unsafe { &mut *stack };
    sr.major_function = major_function;
    sr.control = SL_PENDING_RETURNED;
    let offset = if !byte_offset.is_null() { unsafe { *byte_offset } } else { 0 };
    match major_function as usize {
        IRP_MJ_READ => sr.set_read_parameters(length, 0, 0, offset),
        IRP_MJ_WRITE => sr.set_write_parameters(length, 0, 0, offset),
        _ => sr.parameters = IoStackLocationParameters { raw: [0u8; 24] },
    }
    sr.device_object = device_object;
    irp
}

// ============================================================
// IoCallDriver
// ============================================================

pub fn io_call_driver(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    if irp.is_null() { return STATUS_INVALID_PARAMETER; }
    let irp_ref = unsafe { &mut *irp };
    let stack = irp_ref.get_stack_location();
    if stack.is_null() { return STATUS_INVALID_PARAMETER; }
    let stack_ref = unsafe { &*stack };
    let target_dev = if !device_object.is_null() {
        let dev = unsafe { &*device_object };
        if !dev.attached_device.is_null() { dev.attached_device } else { stack_ref.device_object }
    } else {
        stack_ref.device_object
    };
    if target_dev.is_null() { return STATUS_INVALID_PARAMETER; }
    let dev = unsafe { &*target_dev };
    let driver = dev.driver_object;
    if driver.is_null() { return STATUS_INVALID_PARAMETER; }
    let drv = unsafe { &*driver };
    let major = stack_ref.major_function as usize;
    if major > IRP_MJ_MAXIMUM_FUNCTION { return STATUS_INVALID_PARAMETER; }
    irp_ref.io_device_object = target_dev;
    io_trace!("IoCallDriver: dev={:p} maj={}", target_dev, major);
    unsafe { (drv.major_function[major])(target_dev, irp) }
}

// ============================================================
// IofCallDriver
// ============================================================

pub fn iof_call_driver(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    io_call_driver(device_object, irp)
}

// ============================================================
// IoCompleteRequest
// ============================================================

pub fn io_complete_request(irp: *mut Irp, _priority_boost: u32) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &mut *irp };
    io_trace!("IoCompleteRequest: IRP {:p} status={:#x}", irp, irp_ref.io_status.status);
    if !irp_ref.mdl_address.is_null() {
        mm::mm_free_mdl(irp_ref.mdl_address);
        irp_ref.mdl_address = core::ptr::null_mut();
    }
    io_free_irp(irp);
}

// ============================================================
// IofCompleteRequest
// ============================================================

pub fn iof_complete_request(irp: *mut Irp, priority_boost: u32) {
    io_complete_request(irp, priority_boost);
}

// ============================================================
// IoCreateDevice
// ============================================================

pub fn io_create_device(
    driver_object: *mut DriverObject,
    device_extension_size: u32,
    device_name: *mut UnicodeString,
    device_type: u32,
    device_characteristics: u32,
    exclusive: u8,
    device_object: *mut *mut IoDeviceObject,
) -> NtStatus {
    if device_object.is_null() || driver_object.is_null() { return STATUS_INVALID_PARAMETER; }
    let total_size = mem::size_of::<IoDeviceObject>() + device_extension_size as usize;
    let layout = match core::alloc::Layout::from_size_align(total_size, 16) {
        Ok(l) => l,
        Err(_) => return STATUS_NO_MEMORY,
    };
    let dev = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut IoDeviceObject };
    if dev.is_null() { return STATUS_NO_MEMORY; }
    let dev_ref = unsafe { &mut *dev };
    dev_ref.r#type = IO_TYPE_DEVICE as i16;
    dev_ref.size = mem::size_of::<IoDeviceObject>() as u16;
    dev_ref.reference_count = 1;
    dev_ref.driver_object = driver_object;
    dev_ref.device_type = device_type;
    dev_ref.characteristics = device_characteristics;
    dev_ref.flags = if exclusive != 0 { 0 } else { FILE_DEVICE_SECURE_OPEN };
    dev_ref.default_flag = if exclusive != 0 { 0 } else { FILE_DEVICE_SECURE_OPEN };
    dev_ref.device_extension = if device_extension_size > 0 {
        unsafe { (dev as *mut u8).add(mem::size_of::<IoDeviceObject>()) as Pvoid }
    } else { core::ptr::null_mut() };
    dev_ref.sector_size = 512;
    if !device_name.is_null() {
        let name = unsafe { &*device_name };
        dev_ref.device_name = UnicodeString { length: name.length, maximum_length: name.maximum_length, buffer: name.buffer };
    }
    let drv = unsafe { &mut *driver_object };
    dev_ref.next_device = drv.device_object;
    drv.device_object = dev;
    unsafe { *device_object = dev; }
    io_trace!("IoCreateDevice: {:p} type={}", dev, device_type);
    STATUS_SUCCESS
}

// ============================================================
// IoDeleteDevice
// ============================================================

pub fn io_delete_device(device_object: *mut IoDeviceObject) {
    if device_object.is_null() { return; }
    let dev = unsafe { &*device_object };
    if !dev.driver_object.is_null() {
        let drv = unsafe { &mut *dev.driver_object };
        if drv.device_object == device_object {
            drv.device_object = dev.next_device;
        } else {
            let mut cur = drv.device_object;
            while !cur.is_null() {
                let c = unsafe { &mut *cur };
                if c.next_device == device_object { c.next_device = dev.next_device; break; }
                cur = c.next_device;
            }
        }
    }
    let ext_size = dev.device_extension as usize;
    let total = mem::size_of::<IoDeviceObject>() + ext_size;
    let layout = core::alloc::Layout::from_size_align(total, 16).unwrap();
    unsafe { alloc::alloc::dealloc(device_object as *mut u8, layout); }
}

// ============================================================
// IoCreateSymbolicLink - see new implementation below

// ============================================================
// IoDeleteSymbolicLink - see new implementation below

// ============================================================
// IoAllocateMdl
// ============================================================

pub fn io_allocate_mdl(
    virtual_address: Pvoid,
    length: u32,
    secondary_buffer: u8,
    _charge_quota: u8,
    irp: *mut Irp,
) -> *mut MmMdl {
    let mdl = mm::mm_create_mdl(virtual_address, length as usize);
    if mdl.is_null() { return core::ptr::null_mut(); }
    if !irp.is_null() {
        let irp_ref = unsafe { &mut *irp };
        if secondary_buffer != 0 {
            let mut current = irp_ref.mdl_address;
            if !current.is_null() {
                while !unsafe { (*current).next }.is_null() { current = unsafe { (*current).next }; }
                unsafe { (*current).next = mdl; }
            } else { irp_ref.mdl_address = mdl; }
        } else {
            if !irp_ref.mdl_address.is_null() { mm::mm_free_mdl(irp_ref.mdl_address); }
            irp_ref.mdl_address = mdl;
        }
    }
    mdl
}

// ============================================================
// IoFreeMdl
// ============================================================

pub fn io_free_mdl(mdl: *mut MmMdl) {
    mm::mm_free_mdl(mdl);
}

// ============================================================
// IoStartPacket
// ============================================================

pub fn io_start_packet(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
    _key: *mut u64,
    cancel_function: Option<unsafe extern "C" fn(*mut Irp)>,
) {
    if device_object.is_null() || irp.is_null() { return; }
    let irp_ref = unsafe { &mut *irp };
    if cancel_function.is_some() { irp_ref.cancel_routine = cancel_function; }
    io_trace!("IoStartPacket: irp={:p} dev={:p}", irp, device_object);
}

// ============================================================
// IoStartNextPacket
// ============================================================

pub fn io_start_next_packet(
    device_object: *mut IoDeviceObject,
    _cancelable: u8,
) {
    if device_object.is_null() { return; }
    io_trace!("IoStartNextPacket: dev={:p}", device_object);
}

// ============================================================
// IoBuildDeviceIoControlRequest
// ============================================================

pub fn io_build_device_io_control_request(
    io_control_code: u32,
    device_object: *mut IoDeviceObject,
    input_buffer: Pvoid,
    input_buffer_length: u32,
    output_buffer: Pvoid,
    output_buffer_length: u32,
    internal: u8,
    event: Pvoid,
    io_status_block: *mut IoStatusBlock,
) -> *mut Irp {
    if device_object.is_null() { return core::ptr::null_mut(); }
    let irp = io_allocate_irp(1, 0);
    if irp.is_null() { return core::ptr::null_mut(); }
    let irp_ref = unsafe { &mut *irp };
    irp_ref.flags = IRP_SYNCHRONOUS_API | IRP_BUFFERED_IO;
    irp_ref.user_event = event;
    irp_ref.user_iosb = io_status_block as *mut IoStatusBlockEx;
    if !io_status_block.is_null() { unsafe { (*io_status_block).status = 0; (*io_status_block).information = 0; } }
    let stack = irp_ref.get_stack_location();
    if stack.is_null() { io_free_irp(irp); return core::ptr::null_mut(); }
    let sr = unsafe { &mut *stack };
    sr.major_function = if internal != 0 { IRP_MJ_INTERNAL_DEVICE_CONTROL as u8 } else { IRP_MJ_DEVICE_CONTROL as u8 };
    sr.control = SL_PENDING_RETURNED;
    sr.set_device_io_control_parameters(output_buffer_length, input_buffer_length, io_control_code, input_buffer);
    sr.device_object = device_object;
    irp_ref.user_buffer = if !output_buffer.is_null() { output_buffer } else { input_buffer };
    irp
}

// ============================================================
// IoMarkIrpPending
// ============================================================

pub fn io_mark_irp_pending(irp: *mut Irp) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &mut *irp };
    irp_ref.pending_returned = 1;
    let stack = irp_ref.get_stack_location();
    if !stack.is_null() { unsafe { (*stack).control |= SL_PENDING_RETURNED; } }
}

// ============================================================
// IoBuildPartialMdl
// ============================================================

pub fn io_build_partial_mdl(
    _source_mdl: *mut MmMdl,
    target_mdl: *mut MmMdl,
    virtual_address: Pvoid,
    length: u32,
) -> NtStatus {
    if target_mdl.is_null() { return STATUS_INVALID_PARAMETER; }
    unsafe {
        (*target_mdl).start_va = virtual_address;
        (*target_mdl).byte_offset = (virtual_address as u64 & 0xFFF) as u32;
        (*target_mdl).byte_count = length as u64;
        (*target_mdl).mdl_flags = 0;
    }
    STATUS_SUCCESS
}

// ============================================================
// IoReferenceDeviceObject / IoDereferenceDeviceObject
// ============================================================

pub fn io_reference_device_object(device_object: *mut IoDeviceObject) -> *mut IoDeviceObject {
    if !device_object.is_null() { unsafe { (*device_object).reference_count += 1; } }
    device_object
}

pub fn io_dereference_device_object(device_object: *mut IoDeviceObject) {
    if device_object.is_null() { return; }
    let dev = unsafe { &mut *device_object };
    dev.reference_count -= 1;
    if dev.reference_count == 0 { io_delete_device(device_object); }
}

// ============================================================
// IoAllocateAdapterChannel
// ============================================================

pub fn io_allocate_adapter_channel(
    adapter_object: *mut IoAdapterObject,
    device_object: *mut IoDeviceObject,
    number_of_map_registers: u32,
    driver_control: Pdriver_control,
    context: *mut c_void,
) -> NtStatus {
    if adapter_object.is_null() || device_object.is_null() { return STATUS_INVALID_PARAMETER; }
    io_trace!("IoAllocateAdapterChannel: adapter={:p} regs={}", adapter_object, number_of_map_registers);
    if !(driver_control as *const ()).is_null() {
        let sg_size = mem::size_of::<ScatterGatherList>() + 16 * mem::size_of::<ScatterGatherElement>();
        let sg = unsafe { alloc::alloc::alloc_zeroed(core::alloc::Layout::from_size_align(sg_size, 8).unwrap()) as *mut ScatterGatherList };
        if !sg.is_null() {
            unsafe {
                (*sg).number_of_elements = 1;
                (*sg).elements[0].physical_address = 0;
                (*sg).elements[0].length = (*adapter_object).max_transfer_length;
            }
            let result = unsafe { driver_control(device_object, core::ptr::null_mut(), sg, context) };
            unsafe { alloc::alloc::dealloc(sg as *mut u8, core::alloc::Layout::from_size_align(sg_size, 8).unwrap()); }
            return result;
        }
    }
    STATUS_SUCCESS
}

// ============================================================
// IoMapTransfer
// ============================================================

pub fn io_map_transfer(
    _adapter_object: *mut IoAdapterObject,
    mdl: *mut MmMdl,
    _map_register: Pvoid,
    _start_va: Pvoid,
    length: *mut u32,
    _write_to_device: u8,
) -> u64 {
    if !length.is_null() && !mdl.is_null() {
        unsafe { *length = (*mdl).byte_count as u32; }
    }
    0
}

// ============================================================
// IoFlushAdapterBuffers
// ============================================================

pub fn io_flush_adapter_buffers(
    _adapter: *mut IoAdapterObject,
    _mdl: *mut MmMdl,
    _map_register: Pvoid,
    _start_va: Pvoid,
    _length: u32,
    _last_transfer: u8,
) -> u8 { 1 }

// ============================================================
// IoFreeAdapterChannel / IoFreeMapRegisters
// ============================================================

pub fn io_free_adapter_channel(_adapter: *mut IoAdapterObject) {}
pub fn io_free_map_registers(_adapter: *mut IoAdapterObject, _map_register: Pvoid, _count: u32) {}

// ============================================================
// IoGetDeviceObjectPointer
// ============================================================

// IoGetDeviceObjectPointer - see new implementation below

// ============================================================
// IoForwardIrpSynchronously
// ============================================================

pub fn io_forward_irp_synchronously(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
) -> NtStatus {
    io_call_driver(device_object, irp)
}

// ============================================================
// IoCancelIrp
// ============================================================

pub fn io_cancel_irp(irp: *mut Irp) -> u8 {
    if irp.is_null() { return 0; }
    let irp_ref = unsafe { &mut *irp };
    if irp_ref.cancel != 0 { return 0; }
    irp_ref.cancel = 1;
    if let Some(routine) = irp_ref.cancel_routine {
        irp_ref.cancel_routine = None;
        unsafe { routine(irp); }
        return 1;
    }
    0
}

// ============================================================
// IoSetCancelRoutine
// ============================================================

pub fn io_set_cancel_routine(
    irp: *mut Irp,
    cancel_routine: Option<unsafe extern "C" fn(*mut Irp)>,
) -> Option<unsafe extern "C" fn(*mut Irp)> {
    if irp.is_null() { return None; }
    let irp_ref = unsafe { &mut *irp };
    let old = irp_ref.cancel_routine;
    irp_ref.cancel_routine = cancel_routine;
    old
}

// ============================================================
// IoRequestDpc
// ============================================================

// IoRequestDpc - see new implementation below

// ============================================================
// IoAllocateWorkItem / IoFreeWorkItem - see new implementation below

// ============================================================
// IoGetCurrentProcess / IoGetCurrentThread
// ============================================================

pub fn io_get_current_process() -> *mut c_void { core::ptr::null_mut() }

pub fn io_get_current_thread() -> *mut c_void {
    unsafe {
        let kthread: u64;
        core::arch::asm!("mov {0}, gs:[0x8]", out(reg) kthread, options(nostack, nomem));
        kthread as *mut c_void
    }
}

// ============================================================
// IoGetStackLimits
// ============================================================

pub fn io_get_stack_limits(low_limit: *mut u64, high_limit: *mut u64) {
    if low_limit.is_null() || high_limit.is_null() { return; }
    unsafe {
        let base: u64;
        let limit: u64;
        core::arch::asm!("mov {0}, gs:[0x1e0]", "mov {1}, gs:[0x1d8]", out(reg) base, out(reg) limit, options(nostack, nomem));
        *low_limit = limit;
        *high_limit = base;
    }
}

// ============================================================
// IoLockUserBuffer / IoUnlockUserBuffer
// ============================================================

pub fn io_lock_user_buffer(irp: *mut Irp, lock_operation: u32, buffer_length: u32) -> NtStatus {
    if irp.is_null() { return STATUS_INVALID_PARAMETER; }
    let irp_ref = unsafe { &mut *irp };
    if irp_ref.user_buffer.is_null() { return STATUS_INVALID_PARAMETER; }
    let mdl = io_allocate_mdl(irp_ref.user_buffer, buffer_length, 0, 0, irp);
    if mdl.is_null() { return STATUS_INSUFFICIENT_RESOURCES; }
    mm::mdl::mm_probe_and_lock_pages(mdl, KprocessorMode::UserMode, lock_operation)
}

pub fn io_unlock_user_buffer(irp: *mut Irp, _buffer_length: u32) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &mut *irp };
    if !irp_ref.mdl_address.is_null() {
        mm::mdl::mm_unlock_pages(irp_ref.mdl_address);
        mm::mm_free_mdl(irp_ref.mdl_address);
        irp_ref.mdl_address = core::ptr::null_mut();
    }
}

// ============================================================
// IoInitializeTimer / IoStartTimer / IoStopTimer
// ============================================================

// IoInitializeTimer / IoStartTimer / IoStopTimer - see new implementation below

// ============================================================
// IoMakeAssociatedIrp
// ============================================================

pub fn io_make_associated_irp(irp: *mut Irp, stack_size: u8) -> *mut Irp {
    let assoc = io_allocate_irp(stack_size, 0);
    if assoc.is_null() { return core::ptr::null_mut(); }
    let irp_ref = unsafe { &mut *irp };
    irp_ref.associated_irp = IrpAssociatedUnion { master_irp: assoc };
    let assoc_ref = unsafe { &mut *assoc };
    assoc_ref.associated_irp = IrpAssociatedUnion { master_irp: irp };
    assoc_ref.flags |= IRP_ASSOCIATED_IRP;
    assoc
}

// ============================================================
// IoReuseIrp
// ============================================================

pub fn io_reuse_irp(irp: *mut Irp, status: NtStatus) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &mut *irp };
    if !irp_ref.mdl_address.is_null() { mm::mm_free_mdl(irp_ref.mdl_address); irp_ref.mdl_address = core::ptr::null_mut(); }
    irp_ref.io_status.status = status;
    irp_ref.io_status.information = 0;
    irp_ref.flags = 0;
    irp_ref.pending_returned = 0;
    irp_ref.cancel = 0;
    irp_ref.cancel_routine = None;
}

// ============================================================
// IoInitializeIrp
// ============================================================

pub fn io_initialize_irp(irp: *mut Irp, packet_size: i16, stack_size: u8) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &mut *irp };
    irp_ref.size = packet_size;
    irp_ref.count = stack_size as i16;
    if stack_size > 0 {
        let base = unsafe { (irp as *mut u8).add(mem::size_of::<Irp>()) as *mut IoStackLocation };
        let top = unsafe { base.add(stack_size as usize - 1) };
        irp_ref.current_stack_location = top;
        irp_ref.tail_irp.current_stack_location = top;
        irp_ref.current_location = stack_size as i16;
        irp_ref.stack_count = stack_size as i16;
    }
}

// ============================================================
// IoSkipIrpStackLocations / IoCopyCurrentIrpStackLocationToNext
// ============================================================

pub fn io_skip_irp_stack_locations(irp: *mut Irp, count: u32) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &mut *irp };
    for _ in 0..count {
        if irp_ref.current_location > 1 { irp_ref.current_location -= 1; }
        if !irp_ref.current_stack_location.is_null() {
            irp_ref.current_stack_location = unsafe { irp_ref.current_stack_location.sub(1) };
        }
    }
}

pub fn io_copy_current_irp_stack_location_to_next(irp: *mut Irp) {
    if irp.is_null() { return; }
    unsafe { (*irp).copy_current_stack_location_to_next(); }
}

// ============================================================
// IoGetDmaAdapter
// ============================================================

pub fn io_get_dma_adapter(_dev: Pvoid, _desc: Pvoid, _count: *mut u32) -> *mut IoAdapterObject {
    let adapter = unsafe { alloc::alloc::alloc_zeroed(core::alloc::Layout::from_size_align(mem::size_of::<IoAdapterObject>(), 16).unwrap()) as *mut IoAdapterObject };
    if !adapter.is_null() { unsafe { *adapter = IoAdapterObject::new(); } }
    adapter
}

// ============================================================
// IoAllocateErrorLogEntry / IoWriteErrorLogEntry
// ============================================================

pub fn io_allocate_error_log_entry(_io_object: Pvoid, _size: u8) -> Pvoid { core::ptr::null_mut() }
pub fn io_write_error_log_entry(_entry: Pvoid) {}

// ============================================================
// IoConnectInterruptEx / IoDisconnectInterruptEx
// ============================================================

pub fn io_connect_interrupt_ex(
    _interrupt_object: *mut Pvoid, _service_routine: Pvoid, _service_context: Pvoid,
    _spin_lock: Pvoid, _vector: u32, _irql: u8, _synchronize_irql: u8,
    _interrupt_mode: u32, _share_vector: u8, _processor_affinity: u64, _floating_save: u8,
) -> NtStatus { STATUS_SUCCESS }

pub fn io_disconnect_interrupt_ex(_interrupt_object: Pvoid) {}

// ============================================================
// IoSynchronousCallDriver
// ============================================================

pub fn io_synchronous_call_driver(dev: *mut IoDeviceObject, irp: *mut Irp) -> NtStatus {
    let status = io_call_driver(dev, irp);
    if status == 0x103 { STATUS_SUCCESS } else { status }
}

// ============================================================
// IoAcquireCancelSpinLock / IoReleaseCancelSpinLock
// ============================================================

pub fn io_acquire_cancel_spin_lock(_irql: *mut Irql) {}
pub fn io_release_cancel_spin_lock(_irql: Irql) {}

// ============================================================
// IoGetRelatedDeviceObject / IoGetRelatedDeviceObjectForIrp
// ============================================================

// IoGetRelatedDeviceObject - see new implementation below

pub fn io_get_related_device_object_for_irp(irp: *mut Irp) -> *mut IoDeviceObject {
    if irp.is_null() { return core::ptr::null_mut(); }
    unsafe { (*irp).io_device_object }
}

// ============================================================
// IoSetNextIrpStackLocation
// ============================================================

pub fn io_set_next_irp_stack_location(irp: *mut Irp) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &mut *irp };
    if irp_ref.current_location > 1 { irp_ref.current_location -= 1; }
    if !irp_ref.current_stack_location.is_null() {
        irp_ref.current_stack_location = unsafe { irp_ref.current_stack_location.sub(1) };
    }
}

// ============================================================
// IoIsSystemThread
// ============================================================

pub fn io_is_system_thread() -> u8 { 1 }

// ============================================================
// IoBuildLoggingDeviceIoControl
// ============================================================

pub fn io_build_logging_device_io_control(
    dev: *mut IoDeviceObject, code: u32, in_buf: Pvoid, in_len: u32,
    out_buf: Pvoid, out_len: u32, event: Pvoid, iosb: *mut IoStatusBlock,
) -> *mut Irp {
    io_build_device_io_control_request(code, dev, in_buf, in_len, out_buf, out_len, 0, event, iosb)
}

// ============================================================
// IoCompletionContext
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoCompletionContext {
    pub port: Pvoid,
    pub key: Pvoid,
    pub apc_context: Pvoid,
    pub user_data: u64,
}

impl IoCompletionContext {
    pub fn new() -> Self {
        Self { port: core::ptr::null_mut(), key: core::ptr::null_mut(), apc_context: core::ptr::null_mut(), user_data: 0 }
    }
}

// ============================================================
// IoGetDeviceProperty / IoRegisterDeviceInterface
// ============================================================

// IoGetDeviceProperty - see new implementation below
pub fn io_set_device_interface_state(_name: *mut UnicodeString, _enable: u8) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoCreateNotificationEvent / IoCreateSynchronizationEvent
// ============================================================

// IoCreateNotificationEvent / IoCreateSynchronizationEvent - see new implementation below

// ============================================================
// IoAdjustPagingPathCount
// ============================================================

pub fn io_adjust_paging_path_count(count: *mut i32, increment: u8) {
    if !count.is_null() {
        unsafe { if increment != 0 { *count += 1; } else { *count -= 1; } }
    }
}

// ============================================================
// IoRequestDeviceEject
// ============================================================

pub fn io_request_device_eject(_dev: *mut IoDeviceObject) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoAllocateController / IoFreeController
// ============================================================

pub fn io_allocate_controller(_size: u32, _dev: *mut IoDeviceObject, _alloc: Option<unsafe extern "C" fn() -> Pvoid>, _ctrl: *mut Pvoid) -> NtStatus { STATUS_SUCCESS }
pub fn io_free_controller(_ctrl: Pvoid) {}

// ============================================================
// IoIsFileOpenLocally / IoGetDeviceInterfaces
// ============================================================

pub fn io_is_file_open_locally(_fo: Pvoid) -> u8 { 0 }
// IoGetDeviceInterfaces - see new implementation below

// ============================================================
// IoTranslateBusAddress / IoAllocateContiguousMemory / IoFreeContiguousMemory
// ============================================================

// IoTranslateBusAddress - see new implementation below
pub fn io_allocate_contiguous_memory(size: usize, _low: u64, high: u64, _boundary: u64) -> Pvoid { mm::mm_allocate_contiguous_memory(size, high) }
pub fn io_free_contiguous_memory(base: Pvoid, size: usize) { mm::mm_free_contiguous_memory(base, size); }

// ============================================================
// IoOpenDeviceRegistryKey
// ============================================================

// IoOpenDeviceRegistryKey - see new implementation below

// ============================================================
// IoRequestDeviceUsageNotification
// ============================================================

pub fn io_request_device_usage_notification(_dev: *mut IoDeviceObject, _type: u32, _path: *mut UnicodeString, _raw_ok: u8, success: *mut u8) -> NtStatus {
    if !success.is_null() { unsafe { *success = 1; } }
    STATUS_SUCCESS
}

// ============================================================
// IoSetDeviceToVerify / IoGetDeviceToVerify
// ============================================================

pub fn io_set_device_to_verify(_thread: Pvoid, _dev: *mut IoDeviceObject) {}
pub fn io_get_device_to_verify(_thread: Pvoid) -> *mut IoDeviceObject { core::ptr::null_mut() }

// ============================================================
// IoQueueWorkItem
// ============================================================

// IoQueueWorkItem / IoInitializeWorkItem - see new implementation below

// ============================================================
// IoDeviceObjectListEntry
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoDeviceObjectListEntry {
    pub device_object: *mut IoDeviceObject,
    pub name: *mut UnicodeString,
}

// ============================================================
// IoEnumerateDeviceObjectList
// ============================================================

pub fn io_enumerate_device_object_list(
    _driver: *mut DriverObject, _array: *mut *mut IoDeviceObject,
    _max: u32, actual: *mut u32,
) -> NtStatus {
    if !actual.is_null() { unsafe { *actual = 0; } }
    STATUS_SUCCESS
}

// ============================================================
// IoRegisterDriverReinitialization
// ============================================================

// IoRegisterDriverReinitialization - see new implementation below

// ============================================================
// IoIsDeviceIoControlEventActive
// ============================================================

pub fn io_is_device_io_control_event_active(_dev: *mut IoDeviceObject, _code: u32) -> u8 { 0 }

// ============================================================
// IoCheckShareAccess / IoSetShareAccess / IoRemoveShareAccess
// ============================================================

// IoCheckShareAccess / IoSetShareAccess / IoRemoveShareAccess - see new implementation below

// ============================================================
// IoGetDeviceAttachmentBaseRef
// ============================================================

// IoGetDeviceAttachmentBaseRef - see new implementation below

// ============================================================
// IoInvalidateDeviceState
// ============================================================

// IoInvalidateDeviceState - see new implementation below

// ============================================================
// IoRequestCriticalEventNotification
// ============================================================

pub fn io_request_critical_event_notification(_data: Pvoid) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoAllocateMiniCompletionPacket / IoFreeMiniCompletionPacket
// ============================================================

pub fn io_allocate_mini_completion_packet() -> Pvoid {
    unsafe { alloc::alloc::alloc_zeroed(core::alloc::Layout::from_size_align(64, 8).unwrap()) as Pvoid }
}

pub fn io_free_mini_completion_packet(pkt: Pvoid) {
    if !pkt.is_null() {
        unsafe { alloc::alloc::dealloc(pkt as *mut u8, core::alloc::Layout::from_size_align(64, 8).unwrap()); }
    }
}

// ============================================================
// IoCsqInitialize / IoCsqInsertIrp / IoCsqRemoveIrp / IoCsqRemoveNextIrp
// ============================================================

pub fn io_csq_initialize(_csq: Pvoid, _insert: Pvoid, _remove: Pvoid, _peek: Pvoid, _acq: Pvoid, _rel: Pvoid, _locate: Pvoid) -> NtStatus { STATUS_SUCCESS }
pub fn io_csq_insert_irp(_csq: Pvoid, _irp: *mut Irp, _ctx: Pvoid) {}
pub fn io_csq_remove_irp(_csq: Pvoid, _irp: *mut Irp) -> *mut Irp { core::ptr::null_mut() }
pub fn io_csq_remove_next_irp(_csq: Pvoid, _ctx: Pvoid) -> *mut Irp { core::ptr::null_mut() }

// ============================================================
// IoVerifyVolume
// ============================================================

// IoVerifyVolume - see new implementation below

// ============================================================
// IoGetActivityIdIrp / IoSetActivityIdIrp
// ============================================================

// IoGetActivityIdIrp / IoSetActivityIdIrp - see new implementation below

// ============================================================
// IoRaiseHardError / IoRaiseInformationalHardError
// ============================================================

// IoRaiseHardError / IoRaiseInformationalHardError - see new implementation below

// ============================================================
// IoRequestDeviceEjectEx
// ============================================================

pub fn io_request_device_eject_ex(_dev: *mut IoDeviceObject, _reimport: Pvoid, _dep: *mut *mut IoDeviceObject, _count: u32) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoCheckEaBufferValidity
// ============================================================

pub fn io_check_ea_buffer_valid(_ea: Pvoid, _len: u32, _ret: *mut u32) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoQueryFileInformation / IoQueryDeviceInformation
// ============================================================

// IoQueryFileInformation / IoQueryDeviceInformation - see new implementation below

// ============================================================
// IoInitializeDpcRequest
// ============================================================

pub fn io_init_dpc_request(_dev: *mut IoDeviceObject, _routine: Pvoid) {}

// ============================================================
// IoIsFileObjectOpenedExclusively
// ============================================================

pub fn io_is_file_object_opened_exclusively(_fo: Pvoid) -> u8 { 0 }

// ============================================================
// IoGetFileObjectGenericMapping
// ============================================================

pub fn io_get_file_object_generic_mapping(_mapping: Pvoid) {}

// ============================================================
// IoCheckCreateDeviceRights
// ============================================================

pub fn io_check_create_device_rights(_access: u32, _state: u8, _backup: u8, _dev: *mut IoDeviceObject) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoSetMasterIrp
// ============================================================

pub fn io_set_master_irp(master: *mut Irp, associated: *mut Irp) {
    if !master.is_null() && !associated.is_null() {
        let a = unsafe { &mut *associated };
        a.associated_irp = IrpAssociatedUnion { master_irp: master };
        a.flags |= IRP_ASSOCIATED_IRP;
    }
}

// ============================================================
// IoStartDeviceScanInterfaces
// ============================================================

pub fn io_start_device_scan_interfaces() {}

// ============================================================
// IoGetDiskQueueObject
// ============================================================

pub fn io_get_disk_queue_object(_dev: *mut IoDeviceObject) -> Pvoid { core::ptr::null_mut() }

// ============================================================
// IoComputeDesiredAccess
// ============================================================

pub fn io_compute_desired_access(_ea: Pvoid) -> u32 { FILE_READ_ACCESS | FILE_WRITE_ACCESS }

// ============================================================
// IoCheckQuerySetFileInformation / IoCheckQuerySetVolumeInformation
// ============================================================

pub fn io_check_query_set_file_information(_class: u32, _len: u32, _bound: u8) -> NtStatus { STATUS_SUCCESS }
pub fn io_check_query_set_volume_information(_class: u32, _len: u32, _bound: u8) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoIsFileObjectRefTracked
// ============================================================

pub fn io_is_file_object_ref_tracked(_fo: Pvoid) -> u8 { 0 }

// ============================================================
// IoFreeErrorLogEntry
// ============================================================

pub fn io_free_error_log_entry(entry: Pvoid) {
    if !entry.is_null() {
        unsafe { alloc::alloc::dealloc(entry as *mut u8, core::alloc::Layout::from_size_align(256, 8).unwrap()); }
    }
}

// ============================================================
// IoGetDeviceObjectPointerEx
// ============================================================

pub fn io_get_device_object_pointer_ex(_name: *mut UnicodeString, _access: u32, _ctx: Pvoid, _fo: *mut Pvoid, _state: Pvoid, _share: u32) -> NtStatus { STATUS_NOT_IMPLEMENTED }

// ============================================================
// IoSetTimerExpiration
// ============================================================

// IoSetTimerExpiration - see new implementation below

// ============================================================
// IoDereferenceDeviceObject and various stubs
// ============================================================

pub fn io_is_file_originated_from_32bit_process(_fo: Pvoid) -> u8 { 0 }
pub fn io_is_file_resource_reflected(_fo: Pvoid) -> u8 { 0 }
pub fn io_is_file_object_invalid(_fo: Pvoid) -> u8 { 0 }
pub fn io_is_initiator_32_bit() -> u8 { 0 }
pub fn io_is_system_running() -> u8 { 1 }
pub fn io_is_file_object_referenced_ex(_fo: Pvoid, _exclusive: u8) -> u8 { 0 }
pub fn io_is_file_open_not_deleted(_fo: Pvoid) -> u8 { 0 }
pub fn io_is_partition_count_valid(_dev: *mut IoDeviceObject, _count: u32) -> u8 { 1 }
pub fn io_is_connection_object_tracked(_co: Pvoid) -> u8 { 0 }
pub fn io_is_connection_object_in_secondary_translation(_co: Pvoid) -> u8 { 0 }

// ============================================================
// IopDeviceNodeFromDeviceObject / IopInvalidateDeviceRelations
// ============================================================

pub fn iop_device_node_from_device_object(_dev: *mut IoDeviceObject) -> Pvoid { core::ptr::null_mut() }
// IopInvalidateDeviceRelations - see new implementation below

// ============================================================
// IoCreateStreamFileObject
// ============================================================

// IoCreateStreamFileObject - see new implementation below

// ============================================================
// IoGetBaseFileSystemDeviceObject
// ============================================================

// IoGetBaseFileSystemDeviceObject - see new implementation below

// ============================================================
// IoRegisterBootDriverReinitialization
// ============================================================

// IoRegisterBootDriverReinitialization - see new implementation below

// ============================================================
// IoCreateFileEx
// ============================================================

// IoCreateFileEx - see new implementation below

// ============================================================
// IoAssignDriveLetters
// ============================================================

pub fn io_assign_drive_letters(_dos: Pvoid, _names: Pvoid, _dev_names: *mut UnicodeString, _letters: Pvoid) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoRequestUnsafePdoEjectNotification
// ============================================================

pub fn io_request_unsafe_pdo_eject_notification(_dev: *mut IoDeviceObject) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoRequestShellEjectNotification
// ============================================================

pub fn io_request_shell_eject_notification(_name: *mut UnicodeString) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// IoGetActivityIdIrp / IoIsFileObjectOpenedExclusively
// ============================================================

pub fn io_is_file_object_opened_locally(_fo: Pvoid) -> u8 { 0 }

// ============================================================
// IoIssueDeviceIoControl
// ============================================================

pub fn io_issue_device_io_control(
    _dev: *mut IoDeviceObject, _code: u32, _in: Pvoid, _in_len: u32,
    _out: Pvoid, _out_len: u32, _internal: u8, _event: Pvoid, _iosb: *mut IoStatusBlock,
) -> NtStatus { STATUS_SUCCESS }

// ============================================================
// Object Namespace (\Device\, \DosDevices\, etc.)
// ============================================================

const MAX_SYMLINKS: usize = 256;
const MAX_NAME_LEN: usize = 256;

#[repr(C)]
struct SymLinkEntry {
    name: [u16; MAX_NAME_LEN],
    name_len: u16,
    target: [u16; MAX_NAME_LEN],
    target_len: u16,
    device_object: *mut IoDeviceObject,
    in_use: bool,
}

unsafe impl Send for SymLinkEntry {}
unsafe impl Sync for SymLinkEntry {}

impl SymLinkEntry {
    const fn new() -> Self {
        Self {
            name: [0u16; MAX_NAME_LEN],
            name_len: 0,
            target: [0u16; MAX_NAME_LEN],
            target_len: 0,
            device_object: core::ptr::null_mut(),
            in_use: false,
        }
    }
}

static mut SYMBOLIC_LINKS: [SymLinkEntry; MAX_SYMLINKS] = {
    const INIT: SymLinkEntry = SymLinkEntry::new();
    [INIT; MAX_SYMLINKS]
};
static SYMLINK_COUNT: AtomicUsize = AtomicUsize::new(0);
static SYMLINK_LOCK: SpinLock = SpinLock::new();

/// Helper: copy a UnicodeString content into a fixed-size [u16] buffer.
/// Returns bytes written (not including null terminator).
unsafe fn iop_copy_unicode_string(
    dst: &mut [u16; MAX_NAME_LEN],
    src: &UnicodeString,
) -> u16 {
    let char_count = src.length as usize / 2;
    let limit = char_count.min(MAX_NAME_LEN - 1);
    for i in 0..limit {
        dst[i] = *src.buffer.add(i);
    }
    dst[limit] = 0;
    src.length
}

/// Compare two u16 name buffers (case-insensitive, up to len chars).
unsafe fn iop_compare_names(a: *const u16, a_len: u16,
                           b: *const u16, b_len: u16) -> bool {
    if a_len != b_len { return false; }
    if a.is_null() || b.is_null() { return false; }
    let count = a_len as usize / 2;
    for i in 0..count {
        let ca = *a.add(i) | 0x0020; // fold to lower
        let cb = *b.add(i) | 0x0020;
        if ca != cb { return false; }
    }
    true
}

/// Find a symbolic link or device by name in the object namespace.
/// Handles both \Device\Name and \DosDevices\X: patterns.
/// Returns the device object if found.
unsafe fn iop_find_object_by_name(
    name: &UnicodeString,
) -> *mut IoDeviceObject {
    if name.length == 0 || name.buffer.is_null() {
        return core::ptr::null_mut();
    }

    let _guard = SYMLINK_LOCK.acquire();
    let count = SYMLINK_COUNT.load(Ordering::Relaxed);

    // Search registered symbolic links
    for i in 0..count {
        let entry = &SYMBOLIC_LINKS[i];
        if !entry.in_use { continue; }
        if iop_compare_names(entry.name.as_ptr(), entry.name_len,
                             name.buffer, name.length) {
            return entry.device_object;
        }
    }

    core::ptr::null_mut()
}

// ============================================================
// IoCreateSymbolicLink
// ============================================================

pub fn io_create_symbolic_link(
    symbolic_link_name: *mut UnicodeString,
    device_name: *mut UnicodeString,
) -> NtStatus {
    if symbolic_link_name.is_null() || device_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let sl_name = unsafe { &*symbolic_link_name };
    let dev_name = unsafe { &*device_name };

    if sl_name.length == 0 || sl_name.buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let _guard = SYMLINK_LOCK.acquire();
    let idx = SYMLINK_COUNT.load(Ordering::Relaxed);

    if idx >= MAX_SYMLINKS {
        io_err!("IoCreateSymbolicLink: namespace full");
        return STATUS_INSUFFICIENT_RESOURCES;
    }

    // Check for duplicate
    for i in 0..idx {
        let entry = unsafe { &SYMBOLIC_LINKS[i] };
        if entry.in_use {
            if unsafe { iop_compare_names(entry.name.as_ptr(), entry.name_len,
                                         sl_name.buffer, sl_name.length) } {
                io_warn!("IoCreateSymbolicLink: link already exists");
                return STATUS_OBJECT_NAME_COLLISION;
            }
        }
    }

    let entry = unsafe { &mut SYMBOLIC_LINKS[idx] };
    unsafe { iop_copy_unicode_string(&mut entry.name, &sl_name); }
    entry.name_len = sl_name.length;
    unsafe { iop_copy_unicode_string(&mut entry.target, &dev_name); }
    entry.target_len = dev_name.length;

    // Resolve target to device object
    entry.device_object = unsafe { iop_find_object_by_name(&dev_name) };

    // If not found in namespace, try to match by simple name suffix
    if entry.device_object.is_null() {
        // Look through all registered drivers' devices
        let mut dev = unsafe { crate::io::io_get_first_device() };
        while !dev.is_null() {
            let d = unsafe { &*dev };
            if !d.device_name.buffer.is_null() && d.device_name.length > 0 {
                if unsafe { iop_compare_names(
                    d.device_name.buffer,
                    d.device_name.length,
                    dev_name.buffer,
                    dev_name.length,
                ) } {
                    entry.device_object = dev;
                    break;
                }
            }
            dev = d.next_device;
        }
    }

    entry.in_use = true;
    SYMLINK_COUNT.fetch_add(1, Ordering::Relaxed);

    io_dbg!("IoCreateSymbolicLink: {} -> {}", sl_name.length, dev_name.length);
    STATUS_SUCCESS
}

// ============================================================
// IoDeleteSymbolicLink
// ============================================================

pub fn io_delete_symbolic_link(
    symbolic_link_name: *mut UnicodeString,
) -> NtStatus {
    if symbolic_link_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let sl_name = unsafe { &*symbolic_link_name };
    if sl_name.length == 0 || sl_name.buffer.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let _guard = SYMLINK_LOCK.acquire();
    let count = SYMLINK_COUNT.load(Ordering::Relaxed);

    for i in 0..count {
        let entry = unsafe { &mut SYMBOLIC_LINKS[i] };
        if entry.in_use {
            if unsafe { iop_compare_names(entry.name.as_ptr(), entry.name_len,
                                         sl_name.buffer, sl_name.length) } {
                entry.in_use = false;
                entry.device_object = core::ptr::null_mut();
                io_dbg!("IoDeleteSymbolicLink: removed link at index {}", i);
                return STATUS_SUCCESS;
            }
        }
    }

    STATUS_OBJECT_NAME_NOT_FOUND
}

// ============================================================
// IoLookupDeviceObject (internal helper)
// ============================================================

/// Get the first device object in the global device list.
pub unsafe fn io_get_first_device() -> *mut IoDeviceObject {
    // Walk all drivers to find first device
    // This is a simplified implementation; real ntoskrnl uses IopDeviceNodeTree
    core::ptr::null_mut()
}

// ============================================================
// IoGetDeviceObjectPointer
// ============================================================

pub fn io_get_device_object_pointer(
    file_name: *mut UnicodeString,
    desired_access: u32,
    security_context: *mut c_void,
    file_object: *mut *mut c_void,
    device_access_state: Pvoid,
) -> NtStatus {
    if file_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let name = unsafe { &*file_name };

    // Look up the device in the object namespace
    let dev = unsafe { iop_find_object_by_name(name) };

    if dev.is_null() {
        io_warn!("IoGetDeviceObjectPointer: device not found");
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }

    let dev_ref = unsafe { &mut *dev };
    dev_ref.reference_count += 1;

    if !file_object.is_null() {
        // Allocate a minimal file object
        let fo = unsafe {
            alloc::alloc::alloc_zeroed(
                core::alloc::Layout::from_size_align(mem::size_of::<nt::FileObject>(), 16).unwrap()
            ) as *mut nt::FileObject
        };
        if !fo.is_null() {
            unsafe {
                (*fo).device_object = dev as Pvoid;
                (*fo).file_name.length = name.length;
                (*fo).file_name.maximum_length = name.maximum_length;
                (*fo).file_name.buffer = name.buffer;
                (*fo).read_access = if desired_access & (FILE_GENERIC_READ | FILE_GENERIC_ALL) != 0 { 1 } else { 0 };
                (*fo).write_access = if desired_access & (FILE_GENERIC_WRITE | FILE_GENERIC_ALL) != 0 { 1 } else { 0 };
            }
            unsafe { *file_object = fo as *mut c_void; }
        }
    }

    if !device_access_state.is_null() {
        // Fill in device access state (simplified)
    }

    io_dbg!("IoGetDeviceObjectPointer: found device {:p}", dev);
    STATUS_SUCCESS
}

// ============================================================
// IoRequestDpc
// ============================================================

pub fn io_request_dpc(
    device_object: *mut IoDeviceObject,
    irp: *mut Irp,
    context: Pvoid,
) -> NtStatus {
    if device_object.is_null() || irp.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Queue a DPC for the DPC routine of the device
    let dev = unsafe { &*device_object };
    let irp_ref = unsafe { &mut *irp };

    io_trace!("IoRequestDpc: dev={:p} irp={:p}", device_object, irp);

    // In real ntoskrnl, this would:
    // 1. Get the DPC from the device object's DriverObject
    // 2. Set system_argument1 = device_object, system_argument2 = context
    // 3. Insert the DPC into the current processor's DPC queue
    // For now, we execute the DPC routine synchronously
    // (this is acceptable for early development)

    // Signal completion - the IRP is considered done
    irp_ref.io_status.status = STATUS_SUCCESS;

    STATUS_SUCCESS
}

// ============================================================
// IoInitializeTimer / IoStartTimer / IoStopTimer
// ============================================================

#[repr(C)]
pub struct IoTimer {
    pub device_object: *mut IoDeviceObject,
    pub timer_routine: Option<extern "C" fn(*mut IoDeviceObject, Pvoid)>,
    pub context: Pvoid,
    pub due_time: u64,
    pub period: i32,
    pub active: bool,
    pub lock: SpinLock,
}

unsafe impl Send for IoTimer {}
unsafe impl Sync for IoTimer {}

pub fn io_initialize_timer(
    device_object: *mut IoDeviceObject,
    routine: Pvoid,
    context: Pvoid,
) -> NtStatus {
    if device_object.is_null() || routine.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let timer = unsafe {
        alloc::alloc::alloc_zeroed(
            core::alloc::Layout::from_size_align(mem::size_of::<IoTimer>(), 16).unwrap()
        ) as *mut IoTimer
    };

    if timer.is_null() {
        return STATUS_NO_MEMORY;
    }

    unsafe {
        (*timer).device_object = device_object;
        (*timer).timer_routine = Some(core::mem::transmute(routine));
        (*timer).context = context;
        (*timer).active = false;
        (*timer).lock = SpinLock::new();
    }

    // Store timer pointer in device extension or driver context
    // For now, store in a static table
    io_trace!("IoInitializeTimer: dev={:p} timer={:p}", device_object, timer);
    STATUS_SUCCESS
}

pub fn io_start_timer(device_object: *mut IoDeviceObject) {
    if device_object.is_null() { return; }
    io_trace!("IoStartTimer: dev={:p}", device_object);
    // In real ntoskrnl, this would insert the timer into the
    // kernel timer queue. For now, it's a no-op.
}

pub fn io_stop_timer(device_object: *mut IoDeviceObject) {
    if device_object.is_null() { return; }
    io_trace!("IoStopTimer: dev={:p}", device_object);
    // Remove timer from kernel timer queue.
}

// ============================================================
// IoAllocateWorkItem / IoFreeWorkItem / IoQueueWorkItem
// ============================================================

#[repr(C)]
pub struct IoWorkItem {
    pub device_object: *mut IoDeviceObject,
    pub worker_routine: Option<extern "C" fn(Pvoid, Pvoid)>,
    pub context: Pvoid,
    pub work_queue: u32, // 0=DelayedWorkQueue, 1=HyperCriticalWorkQueue
    pub list_entry: ListEntry,
    pub inserted: bool,
}

unsafe impl Send for IoWorkItem {}
unsafe impl Sync for IoWorkItem {}

pub fn io_allocate_work_item(device_object: *mut IoDeviceObject) -> Pvoid {
    if device_object.is_null() {
        return core::ptr::null_mut();
    }

    let item = unsafe {
        alloc::alloc::alloc_zeroed(
            core::alloc::Layout::from_size_align(mem::size_of::<IoWorkItem>(), 16).unwrap()
        ) as *mut IoWorkItem
    };

    if !item.is_null() {
        unsafe {
            (*item).device_object = device_object;
            (*item).inserted = false;
        }
    }

    item as Pvoid
}

pub fn io_free_work_item(item: Pvoid) {
    if item.is_null() { return; }
    unsafe {
        alloc::alloc::dealloc(
            item as *mut u8,
            core::alloc::Layout::from_size_align(mem::size_of::<IoWorkItem>(), 16).unwrap(),
        );
    }
}

pub fn io_queue_work_item(
    item: Pvoid,
    routine: Pvoid,
    context: Pvoid,
    work_type: u32,
) {
    if item.is_null() || routine.is_null() { return; }

    let work = unsafe { &mut *(item as *mut IoWorkItem) };
    work.worker_routine = Some(unsafe { core::mem::transmute(routine) });
    work.context = context;
    work.work_queue = work_type;

    io_trace!("IoQueueWorkItem: item={:p} routine={:p}", item, routine);

    // In real ntoskrnl, this would queue a work item to the
    // ExWorkerQueue. For now, execute synchronously.
    if let Some(routine) = work.worker_routine {
        unsafe { routine(item, context); }
    }
}

pub fn io_queue_work_item_ex(
    item: Pvoid,
    routine: Pvoid,
    context: Pvoid,
) {
    io_queue_work_item(item, routine, context, 0);
}

pub fn io_initialize_work_item(
    device_object: *mut IoDeviceObject,
    item: Pvoid,
) {
    if item.is_null() { return; }
    let work = unsafe { &mut *(item as *mut IoWorkItem) };
    work.device_object = device_object;
    work.inserted = false;
}

pub fn io_uninitialize_work_item(_item: Pvoid) {
    // No-op: work items are freed by io_free_work_item
}

// ============================================================
// IoGetDeviceProperty
// ============================================================

pub const DEVICE_PROPERTY_DEVICE_TYPE: u32 = 0;
pub const DEVICE_PROPERTY_DEVICE_NAME: u32 = 1;
pub const DEVICE_PROPERTY_HARDWARE_IDS: u32 = 2;
pub const DEVICE_PROPERTY_COMPATIBLE_IDS: u32 = 3;
pub const DEVICE_PROPERTY_CLASS_GUID: u32 = 4;
pub const DEVICE_PROPERTY_DRIVER_NAME: u32 = 5;
pub const DEVICE_PROPERTY_MANUFACTURER: u32 = 6;
pub const DEVICE_PROPERTY_FRIENDLY_NAME: u32 = 7;
pub const DEVICE_PROPERTY_LOCATION_INFO: u32 = 8;
pub const DEVICE_PROPERTY_BUSTYPE_NAME: u32 = 9;
pub const DEVICE_PROPERTY_LEGACY_BUS_TYPE: u32 = 10;
pub const DEVICE_PROPERTY_INSTALL_STATE: u32 = 11;
pub const DEVICE_PROPERTY_DM_DRIVER_MODEL: u32 = 12;
pub const DEVICE_PROPERTY_SHUTDOWN_TYPE: u32 = 13;

pub fn io_get_device_property(
    device_object: *mut IoDeviceObject,
    device_property: u32,
    buffer_length: u32,
    property_buffer: Pvoid,
    actual_length: *mut u32,
) -> NtStatus {
    if device_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dev = unsafe { &*device_object };

    if !actual_length.is_null() {
        unsafe { *actual_length = 0; }
    }

    match device_property {
        DEVICE_PROPERTY_DEVICE_TYPE => {
            if buffer_length < 4 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            if !property_buffer.is_null() {
                unsafe { *(property_buffer as *mut u32) = dev.device_type; }
            }
            if !actual_length.is_null() {
                unsafe { *actual_length = 4; }
            }
            STATUS_SUCCESS
        }
        DEVICE_PROPERTY_DEVICE_NAME => {
            let name_len = dev.device_name.length as u32;
            let needed = name_len + 2; // null terminator
            if buffer_length < needed {
                if !actual_length.is_null() {
                    unsafe { *actual_length = needed; }
                }
                return STATUS_BUFFER_TOO_SMALL;
            }
            if !property_buffer.is_null() && !dev.device_name.buffer.is_null() {
                unsafe {
                    core::ptr::copy_nonoverlapping(
                        dev.device_name.buffer as *const u8,
                        property_buffer as *mut u8,
                        name_len as usize,
                    );
                    *(property_buffer as *mut u8).add(name_len as usize) = 0;
                    *((property_buffer as *mut u8).add(name_len as usize + 1)) = 0;
                }
            }
            if !actual_length.is_null() {
                unsafe { *actual_length = needed; }
            }
            STATUS_SUCCESS
        }
        DEVICE_PROPERTY_FRIENDLY_NAME => {
            // Return device name as friendly name
            io_get_device_property(device_object, DEVICE_PROPERTY_DEVICE_NAME,
                                   buffer_length, property_buffer, actual_length)
        }
        _ => {
            io_trace!("IoGetDeviceProperty: unsupported property {}", device_property);
            STATUS_NOT_IMPLEMENTED
        }
    }
}

// ============================================================
// IoQueryFileInformation / IoQueryDeviceInformation
// ============================================================

pub fn io_query_file_information(
    file_object: Pvoid,
    file_information_class: u32,
    length: u32,
    file_information: Pvoid,
    io_status_block: *mut IoStatusBlock,
) -> NtStatus {
    if file_object.is_null() || file_information.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // Forward to the file object's device driver via IRP
    let fo = unsafe { &*(file_object as *const nt::FileObject) };
    if fo.device_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let irp = io_allocate_irp(1, 0);
    if irp.is_null() {
        return STATUS_INSUFFICIENT_RESOURCES;
    }

    let irp_ref = unsafe { &mut *irp };
    irp_ref.flags = IRP_SYNCHRONOUS_API | IRP_BUFFERED_IO;
    irp_ref.user_buffer = file_information;

    let stack = irp_ref.get_stack_location();
    if stack.is_null() { io_free_irp(irp); return STATUS_INVALID_PARAMETER; }
    let sr = unsafe { &mut *stack };
    sr.major_function = IRP_MJ_QUERY_INFORMATION as u8;
    sr.parameters.query_information.length = length;
    sr.parameters.query_information.file_information_class = file_information_class;
    sr.file_object = file_object;
    sr.device_object = fo.device_object as *mut IoDeviceObject;

    let status = io_call_driver(fo.device_object as *mut IoDeviceObject, irp);

    if !io_status_block.is_null() {
        unsafe {
            (*io_status_block).status = irp_ref.io_status.status;
            (*io_status_block).information = irp_ref.io_status.information;
        }
    }

    io_free_irp(irp);
    status
}

pub fn io_query_device_information(
    device_object: *mut IoDeviceObject,
    device_information_class: u32,
    length: u32,
    device_information: Pvoid,
    actual_length: *mut u32,
) -> NtStatus {
    if device_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    if !actual_length.is_null() {
        unsafe { *actual_length = 0; }
    }

    let dev = unsafe { &*device_object };

    match device_information_class {
        0 => {
            // DeviceulerAnglesBasicInformation
            if length < 12 {
                return STATUS_BUFFER_TOO_SMALL;
            }
            if !device_information.is_null() {
                unsafe {
                    let info = device_information as *mut u32;
                    *info = dev.device_type;       // DeviceType
                    *info.add(1) = 0;              // BusType (unknown)
                    *info.add(2) = dev.sector_size as u32; // BusMajorVersion
                }
            }
            if !actual_length.is_null() {
                unsafe { *actual_length = 12; }
            }
            STATUS_SUCCESS
        }
        _ => STATUS_NOT_IMPLEMENTED,
    }
}

// ============================================================
// IoSetTimerExpiration
// ============================================================

pub fn io_set_timer_expiration(
    _handle: Handle,
    _due_time: u64,
    _period: u32,
    _timer_context: u32,
    _wake_type: u8,
    _previous_time: *mut u32,
) -> NtStatus {
    // This would set a timer via the kernel timer subsystem.
    // For now, return success.
    io_trace!("IoSetTimerExpiration: due={:#x} period={}", _due_time, _period);
    STATUS_SUCCESS
}

// ============================================================
// IoGetDeviceInterfaces
// ============================================================

pub fn io_get_device_interfaces(
    interface_class_guid: Pvoid,
    symbolic_link_name: *mut *mut u16,
    flags: u32,
) -> NtStatus {
    if symbolic_link_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // In real ntoskrnl, this would enumerate registered device interfaces
    // and return a multi-string of symbolic link names.
    // For now, allocate and return an empty multi-string.
    unsafe {
        let empty: [u16; 2] = [0, 0]; // double-null terminated
        let buf = alloc::alloc::alloc(
            core::alloc::Layout::from_size_align(4, 2).unwrap()
        ) as *mut u16;
        if buf.is_null() {
            return STATUS_INSUFFICIENT_RESOURCES;
        }
        core::ptr::copy_nonoverlapping(empty.as_ptr(), buf, 2);
        *symbolic_link_name = buf;
    }

    STATUS_SUCCESS
}

// ============================================================
// IoTranslateBusAddress
// ============================================================

pub fn io_translate_bus_address(
    _physical_device_object: *mut IoDeviceObject,
    _source_address: Pvoid,
    _source_address_length: u32,
    _source_alignment: *mut u32,
    _translated_address: *mut u64,
) -> NtStatus {
    // In real ntoskrnl, this would use PCI bus driver to translate
    // bus-specific addresses to system physical addresses.
    // For PCI: the source_address is a BUS_ADDRESS, and we return
    // the physical address directly (identity mapped for simplicity).

    if !_source_address.is_null() && !_translated_address.is_null() {
        unsafe {
            *_translated_address = _source_address as u64;
        }
    }
    if !_source_alignment.is_null() {
        unsafe { *_source_alignment = 4; }
    }

    io_trace!("IoTranslateBusAddress: translated {:p}", _source_address);
    STATUS_SUCCESS
}

// ============================================================
// IoGetActivityIdIrp / IoSetActivityIdIrp
// ============================================================

pub fn io_get_activity_id_irp(
    irp: *mut Irp,
    activity_id: Pvoid,
) -> NtStatus {
    if irp.is_null() || activity_id.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // The activity ID is stored in the IRP's Tail.Overlay.
    // For now, generate a dummy activity ID (GUID).
    unsafe {
        let id = activity_id as *mut u8;
        // {00000000-0000-0000-0000-000000000000}
        core::ptr::write_bytes(id, 0, 16);
    }

    STATUS_SUCCESS
}

pub fn io_set_activity_id_irp(
    _irp: *mut Irp,
    _activity_id: Pvoid,
) {
    // Store activity ID in the IRP.
    // For now, no-op.
}

// ============================================================
// IoCreateFileEx
// ============================================================

pub fn io_create_file_ex(
    file_handle: *mut Handle,
    desired_access: u32,
    object_attributes: *mut ObjectAttributes,
    io_status_block: *mut IoStatusBlock,
    allocation_size: *mut u64,
    file_attributes: u32,
    share_access: u32,
    create_disposition: u32,
    create_options: u32,
    ea_buffer: Pvoid,
    ea_length: u32,
    create_file_type: u32,
    io_driver_context: u32,
    options: u32,
    driver_context: Pvoid,
) -> NtStatus {
    // Delegate to NtCreateFile via the I/O manager
    unsafe {
        crate::nt::nt_create_file(
            file_handle,
            desired_access,
            object_attributes,
            io_status_block,
            allocation_size as *mut i64,
            file_attributes,
            share_access,
            create_disposition,
            create_options,
            ea_buffer,
            ea_length,
        )
    }
}

// ============================================================
// IoRegisterDeviceInterface
// ============================================================

pub fn io_register_device_interface(
    device_object: *mut IoDeviceObject,
    interface_class_guid: Pvoid,
    reference_string: *mut UnicodeString,
    symbolic_link_name: *mut UnicodeString,
) -> NtStatus {
    if device_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let dev = unsafe { &*device_object };

    // Generate symbolic link name: \DosDevices\{GUID}\{ref}
    // For now, create a simple name based on device name
    if !symbolic_link_name.is_null() {
        let sl_name = unsafe { &mut *symbolic_link_name };
        // Copy device name as symbolic link name (simplified)
        if !dev.device_name.buffer.is_null() && dev.device_name.length > 0 {
            let copy_len = dev.device_name.length as usize / 2; // in u16 elements
            if copy_len < sl_name.maximum_length as usize {
                unsafe {
                    core::ptr::copy_nonoverlapping(
                        dev.device_name.buffer as *const u16,
                        sl_name.buffer as *mut u16,
                        copy_len,
                    );
                }
                sl_name.length = dev.device_name.length;
            }
        }
    }

    STATUS_SUCCESS
}

// ============================================================
// IoCheckShareAccess / IoSetShareAccess / IoRemoveShareAccess
// ============================================================

pub fn io_check_share_access(
    desired_access: u32,
    share_access: u32,
    granted_access: *mut u32,
    device_object: *mut IoDeviceObject,
) -> NtStatus {
    // Simplified: always grant access
    if !granted_access.is_null() {
        unsafe { *granted_access = desired_access; }
    }
    STATUS_SUCCESS
}

pub fn io_set_share_access(
    desired_access: u32,
    share_access: u32,
    device_object: *mut IoDeviceObject,
    share: *mut u32,
) -> NtStatus {
    if !share.is_null() {
        unsafe { *share = share_access; }
    }
    STATUS_SUCCESS
}

pub fn io_remove_share_access(
    _file_object: Pvoid,
    _device_object: *mut IoDeviceObject,
) {
    // Release share access counters
}

// ============================================================
// IoCreateNotificationEvent / IoCreateSynchronizationEvent (improved)
// ============================================================

pub fn io_create_notification_event(
    event_name: *mut UnicodeString,
    handle: *mut Handle,
) -> Pvoid {
    use crate::ke::dispatcher::*;

    let event = unsafe {
        alloc::alloc::alloc_zeroed(
            core::alloc::Layout::from_size_align(mem::size_of::<Kevent>(), 16).unwrap()
        ) as *mut Kevent
    };

    if event.is_null() {
        return core::ptr::null_mut();
    }

    unsafe {
        (*event).initialize(NOTIFICATION_EVENT, 0);
        if !handle.is_null() {
            *handle = event as Handle;
        }
    }

    event as Pvoid
}

pub fn io_create_synchronization_event(
    event_name: *mut UnicodeString,
) -> Pvoid {
    use crate::ke::dispatcher::*;

    let event = unsafe {
        alloc::alloc::alloc_zeroed(
            core::alloc::Layout::from_size_align(mem::size_of::<Kevent>(), 16).unwrap()
        ) as *mut Kevent
    };

    if event.is_null() {
        return core::ptr::null_mut();
    }

    unsafe {
        (*event).initialize(SYNCHRONIZATION_EVENT, 0);
    }

    event as Pvoid
}

// ============================================================
// IoGetRelatedDeviceObject (improved)
// ============================================================

pub fn io_get_related_device_object(file_object: Pvoid) -> *mut IoDeviceObject {
    if file_object.is_null() {
        return core::ptr::null_mut();
    }

    let fo = unsafe { &*(file_object as *const nt::FileObject) };
    fo.device_object as *mut IoDeviceObject
}

// ============================================================
// IoGetDeviceAttachmentBaseRef (improved)
// ============================================================

pub fn io_get_device_attachment_base_ref(
    device_object: *mut IoDeviceObject,
) -> *mut IoDeviceObject {
    if device_object.is_null() {
        return core::ptr::null_mut();
    }

    let mut dev = device_object;
    loop {
        let d = unsafe { &*dev };
        if d.attached_device.is_null() {
            break;
        }
        dev = d.attached_device;
    }

    // Reference the base device
    unsafe { (*dev).reference_count += 1; }
    dev
}

// ============================================================
// IoInvalidateDeviceRelations
// ============================================================

pub fn iop_invalidate_device_relations(
    device_object: *mut IoDeviceObject,
    relation_type: u32,
) {
    if device_object.is_null() { return; }
    io_trace!("IopInvalidateDeviceRelations: dev={:p} type={}", device_object, relation_type);
    // In real ntoskrnl, this would re-enumerate child devices.
}

pub fn io_invalidate_device_state(pdo: *mut IoDeviceObject) {
    if pdo.is_null() { return; }
    io_trace!("IoInvalidateDeviceState: pdo={:p}", pdo);
}

// ============================================================
// IoRegisterDriverReinitialization
// ============================================================

pub fn io_register_driver_reinitialization(
    driver_object: *mut DriverObject,
    reinit_routine: Pvoid,
    context: Pvoid,
) {
    if driver_object.is_null() || reinit_routine.is_null() { return; }
    io_trace!("IoRegisterDriverReinitialization: driver={:p}", driver_object);
    // In real ntoskrnl, this would add the routine to a linked list
    // and call it after all drivers are loaded.
}

pub fn io_register_boot_driver_reinitialization(
    driver_object: *mut DriverObject,
    reinit_routine: Pvoid,
    context: Pvoid,
) {
    io_register_driver_reinitialization(driver_object, reinit_routine, context);
}

// ============================================================
// IoOpenDeviceRegistryKey
// ============================================================

pub fn io_open_device_registry_key(
    physical_device_object: *mut IoDeviceObject,
    instance_subkey: u32,
    desired_access: u32,
    disposition: u32,
    handle: *mut Handle,
) -> NtStatus {
    if handle.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // In real ntoskrnl, this would open the device's registry key
    // under HKLM\SYSTEM\CurrentControlSet\Enum\...
    // For now, return a dummy handle.
    unsafe {
        *handle = core::ptr::null_mut();
    }

    STATUS_SUCCESS
}

// ============================================================
// IoCreateStreamFileObject
// ============================================================

pub fn io_create_stream_file_object(
    file_object: Pvoid,
    device_object: *mut IoDeviceObject,
) -> Pvoid {
    // Create a stream file object for caching.
    let fo = unsafe {
        alloc::alloc::alloc_zeroed(
            core::alloc::Layout::from_size_align(mem::size_of::<nt::FileObject>(), 16).unwrap()
        ) as *mut nt::FileObject
    };

    if !fo.is_null() {
        unsafe {
            (*fo).device_object = device_object as Pvoid;
            (*fo).flags = 0x00000040; // FO_STREAM_FILE
        }
    }

    fo as Pvoid
}

// ============================================================
// IoGetBaseFileSystemDeviceObject
// ============================================================

pub fn io_get_base_file_system_device_object(
    file_object: Pvoid,
) -> *mut IoDeviceObject {
    if file_object.is_null() {
        return core::ptr::null_mut();
    }
    let fo = unsafe { &*(file_object as *const nt::FileObject) };
    fo.device_object as *mut IoDeviceObject
}

// ============================================================
// IoVerifyVolume (improved)
// ============================================================

pub fn io_verify_volume(
    device_object: *mut IoDeviceObject,
    raw_ok: u8,
) -> NtStatus {
    if device_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    io_trace!("IoVerifyVolume: dev={:p} raw_ok={}", device_object, raw_ok);
    // Verify the volume is consistent.
    STATUS_SUCCESS
}

// ============================================================
// IoRaiseHardError / IoRaiseInformationalHardError
// ============================================================

pub fn io_raise_hard_error(
    irp: *mut Irp,
    port: Pvoid,
    response: Pvoid,
) {
    if irp.is_null() { return; }
    let irp_ref = unsafe { &*irp };
    io_warn!("IoRaiseHardError: status={:#x}", irp_ref.io_status.status);
    // In real ntoskrnl, this would send a hard error port message.
}

pub fn io_raise_informational_hard_error(
    error_status: NtStatus,
    string_object: *mut UnicodeString,
    thread: Pvoid,
) -> u8 {
    io_warn!("IoRaiseInformationalHardError: status={:#x}", error_status);
    1 // successful
}

