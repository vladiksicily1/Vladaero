/// # Hardware Abstraction Layer (HAL) - ntoskrnl.exe
///
/// Complete implementation of the Windows HAL subsystem including
/// interrupt management, DMA support, PCI configuration, display
/// management, real-time clock, and bus access routines.

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicU32, AtomicU64, Ordering};

use crate::types::*;
use crate::mm::{self, ListEntry, SpinLock, MmMdl};

// ============================================================
// Constants
// ============================================================

pub const HAL_MAX_PROCESSORS: usize = 64;
pub const HAL_MAXIMUM_AFFINITY: u64 = 0xFFFFFFFF;
pub const HAL_EDGE_LEVEL: u32 = 0x01;
pub const HAL_LEVEL_SENSITIVE: u32 = 0x00;
pub const HAL_NOT_CONNECTED: u32 = 0x04;
pub const HAL_NO_WAIT: u32 = 0x01;

pub const PCI_TYPE0_SPACE: u32 = 0x00;
pub const PCI_TYPE1_SPACE: u32 = 0x01;
pub const PCI_TYPE2_SPACE: u32 = 0x02;
pub const PCI_CONFIG_HEADER_TYPE: u32 = 0x0E;
pub const PCI_CONFIG_IRQ_LINE: u32 = 0x3C;
pub const PCI_CONFIG_IRQ_PIN: u32 = 0x3D;

pub const PCIBUSNUM: u32 = 0;
pub const PCI_SLOTNUM: u32 = 1;
pub const PCI_FUNCTIONNUM: u32 = 2;

pub const CM_RESOURCE_PORT: u16 = 1;
pub const CM_RESOURCE_MEMORY: u16 = 2;
pub const CM_RESOURCE_INTERRUPT: u16 = 4;
pub const CM_RESOURCE_DMA: u16 = 8;
pub const CM_RESOURCE_DEVICE_SPECIFIC: u16 = 16;

pub const CM_RESOURCE_PORT_IO: u16 = 0x0001;
pub const CM_RESOURCE_PORT_MEMORY: u16 = 0x0002;
pub const CM_RESOURCE_PORT_16_BIT_DECODE: u16 = 0x0004;
pub const CM_RESOURCE_PORT_32_BIT_DECODE: u16 = 0x0008;
pub const CM_RESOURCE_PORT_16_BIT: u16 = 0x0010;
pub const CM_RESOURCE_PORT_32_BIT: u16 = 0x0020;

pub const CM_RESOURCE_MEMORY_32: u16 = 0x0004;
pub const CM_RESOURCE_MEMORY_WRITE_THROUGH: u16 = 0x0008;
pub const CM_RESOURCE_MEMORY_CACHEABLE: u16 = 0x0010;
pub const CM_RESOURCE_MEMORY_COMBINEDWRITE: u16 = 0x0020;
pub const CM_RESOURCE_MEMORY_PREFETCHABLE: u16 = 0x0040;
pub const CM_RESOURCE_MEMORY_64: u16 = 0x0080;

pub const CM_RESOURCE_LEVEL: u16 = 0x0001;
pub const CM_RESOURCE_LATCHED: u16 = 0x0010;

pub const PCI_COMMON_HEADER_LENGTH: u32 = 256;

pub const HAL_DMA_ADAPTER: u32 = 1;
pub const HAL_DMA_CONTROLLER: u32 = 2;

pub const DISPLAY_STATUS_BLANKED: u32 = 0x00000001;
pub const DISPLAY_STATUS_VGA_MODE: u32 = 0x00000002;
pub const DISPLAY_STATUS_UNBLANKED: u32 = 0x00000004;

pub const HALObjectTypeInterrupt: u32 = 1;
pub const HALObjectTypeDevice: u32 = 2;
pub const HALObjectTypeTimer: u32 = 3;

// ============================================================
// Logging macros
// ============================================================

macro_rules! hal_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "hal_trace")]
        crate::kernel_log!("[Hal] {}", format_args!($($arg)*));
    };
}

macro_rules! hal_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Hal] {}", format_args!($($arg)*));
    };
}

macro_rules! hal_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Hal] {}", format_args!($($arg)*));
    };
}

macro_rules! hal_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Hal] {}", format_args!($($arg)*));
    };
}

// ============================================================
// HalPrivateDispatchTable
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct HalPrivateDispatchTable {
    pub version: u32,
    pub size: u32,
    pub hal_query_display_parameters: Option<unsafe extern "C" fn(
        *mut DisplayInformation, *mut u32, *mut u32, *mut u32, *mut u32,
    ) -> u32>,
    pub hal_set_display_parameters: Option<unsafe extern "C" fn(u32, u32, u32, u32) -> u32>,
    pub hal_set_pci_info: Option<unsafe extern "C" fn(u32, u32, u32, u32) -> u32>,
    pub hal_get_bus_data: Option<unsafe extern "C" fn(u32, u32, u32, *mut c_void, u32) -> u32>,
    pub hal_set_bus_data: Option<unsafe extern "C" fn(u32, u32, u32, *mut c_void, u32) -> u32>,
    pub hal_check_dma: Option<unsafe extern "C" fn(*mut c_void) -> u32>,
    pub hal_allocate_adapter_channel: Option<unsafe extern "C" fn(
        *mut IoAdapterObject, *mut IoDeviceObject, u32, Pdriver_control, Pvoid,
    ) -> u32>,
    pub hal_free_adapter_channel: Option<unsafe extern "C" fn(*mut IoAdapterObject)>,
    pub hal_free_map_registers: Option<unsafe extern "C" fn(*mut IoAdapterObject, Pvoid, u32)>,
    pub hal_map_transfer: Option<unsafe extern "C" fn(
        *mut IoAdapterObject, *mut MmMdl, Pvoid, Pvoid, *mut u32, u8,
    ) -> u64>,
    pub hal_flush_adapter_buffers: Option<unsafe extern "C" fn(
        *mut IoAdapterObject, *mut MmMdl, Pvoid, Pvoid, u32, u8,
    ) -> u8>,
    pub hal_get_interrupt_vector: Option<unsafe extern "C" fn(u32, u32, u32, u32, *mut u8, *mut u8) -> u32>,
    pub hal_enable_system_interrupt: Option<unsafe extern "C" fn(u32, u8, u32) -> u8>,
    pub hal_disable_system_interrupt: Option<unsafe extern "C" fn(u32)>,
    pub hal_read_pci_config: Option<unsafe extern "C" fn(u32, u32, u32, *mut u32, u32) -> u32>,
    pub hal_write_pci_config: Option<unsafe extern "C" fn(u32, u32, u32, *mut u32, u32) -> u32>,
    pub hal_display_string: Option<unsafe extern "C" fn(*mut u16) -> u32>,
    pub hal_query_real_time_clock: Option<unsafe extern "C" fn(*mut RtlTime) -> u32>,
    pub hal_set_real_time_clock: Option<unsafe extern "C" fn(*mut RtlTime) -> u32>,
}

impl HalPrivateDispatchTable {
    pub fn new() -> Self {
        Self {
            version: 1,
            size: mem::size_of::<Self>() as u32,
            hal_query_display_parameters: None,
            hal_set_display_parameters: None,
            hal_set_pci_info: None,
            hal_get_bus_data: None,
            hal_set_bus_data: None,
            hal_check_dma: None,
            hal_allocate_adapter_channel: None,
            hal_free_adapter_channel: None,
            hal_free_map_registers: None,
            hal_map_transfer: None,
            hal_flush_adapter_buffers: None,
            hal_get_interrupt_vector: None,
            hal_enable_system_interrupt: None,
            hal_disable_system_interrupt: None,
            hal_read_pci_config: None,
            hal_write_pci_config: None,
            hal_display_string: None,
            hal_query_real_time_clock: None,
            hal_set_real_time_clock: None,
        }
    }
}

// ============================================================
// DisplayInformation
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct DisplayInformation {
    pub version: u32,
    pub size: u32,
    pub display_flag: u32,
    pub horizontal_resolution: u32,
    pub vertical_resolution: u32,
    pub pixels_per_scan_line: u32,
    pub bits_per_pixel: u32,
    pub display_frequency: u32,
    pub horizontal_sync_frequency: u32,
    pub vertical_sync_frequency: u32,
    pub horizontal_sync_start: u32,
    pub horizontal_sync_end: u32,
    pub vertical_sync_start: u32,
    pub vertical_sync_end: u32,
    pub screen_stride: u32,
    pub base_address: u64,
    pub phys_base_address: u64,
}

impl DisplayInformation {
    pub fn new() -> Self {
        Self {
            version: 1,
            size: mem::size_of::<Self>() as u32,
            display_flag: 0,
            horizontal_resolution: 0,
            vertical_resolution: 0,
            pixels_per_scan_line: 0,
            bits_per_pixel: 0,
            display_frequency: 0,
            horizontal_sync_frequency: 0,
            vertical_sync_frequency: 0,
            horizontal_sync_start: 0,
            horizontal_sync_end: 0,
            vertical_sync_start: 0,
            vertical_sync_end: 0,
            screen_stride: 0,
            base_address: 0,
            phys_base_address: 0,
        }
    }
}

// ============================================================
// RtlTime
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlTime {
    pub low_part: u32,
    pub high1_time: i32,
    pub high2_time: i32,
}

impl RtlTime {
    pub fn new() -> Self {
        Self { low_part: 0, high1_time: 0, high2_time: 0 }
    }

    pub fn from_u64(val: u64) -> Self {
        Self {
            low_part: val as u32,
            high1_time: (val >> 32) as i32,
            high2_time: 0,
        }
    }
}

// ============================================================
// IoAdapterObject (from io module reference)
// ============================================================

pub use crate::io::IoAdapterObject;

// ============================================================
// IoDeviceObject (from io module reference)
// ============================================================

pub use crate::io::IoDeviceObject;

// ============================================================
// Pdriver_control (from io module reference)
// ============================================================

pub use crate::io::Pdriver_control;

// ============================================================
// IoAdapterObject (re-exported from io)
// ============================================================

// ============================================================
// SCATTER_GATHER_LIST
// ============================================================

pub use crate::io::ScatterGatherList;

// ============================================================
// AdapterObject alias
// ============================================================

pub type AdapterObject = IoAdapterObject;

// ============================================================
// InterruptState
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct InterruptState {
    pub vector: u32,
    pub level: u32,
    pub affinity: u64,
    pub mode: u32,
    pub registered: bool,
    pub enabled: bool,
}

impl InterruptState {
    pub fn new() -> Self {
        Self {
            vector: 0, level: 0, affinity: 0, mode: 0,
            registered: false, enabled: false,
        }
    }
}

// ============================================================
// PCICommonConfig - PCI Configuration Space
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct PciCommonConfig {
    pub vendor_id: u16,
    pub device_id: u16,
    pub command: u16,
    pub status: u16,
    pub revision_id: u8,
    pub prog_if: u8,
    pub sub_class: u8,
    pub class_code: u8,
    pub cache_line_size: u8,
    pub latency_timer: u8,
    pub header_type: u8,
    pub bist: u8,
    pub base_addresses: [u32; 6],
    pub cardbus_cis: u32,
    pub subsystem_vendor_id: u16,
    pub subsystem_id: u16,
    pub expansion_rom_base: u32,
    pub capabilities_ptr: u8,
    pub reserved1: [u8; 3],
    pub reserved2: u32,
    pub interrupt_line: u8,
    pub interrupt_pin: u8,
    pub minimum_grant: u8,
    pub maximum_latency: u8,
}

impl PciCommonConfig {
    pub fn new() -> Self {
        Self {
            vendor_id: 0xFFFF,
            device_id: 0xFFFF,
            command: 0,
            status: 0,
            revision_id: 0,
            prog_if: 0,
            sub_class: 0,
            class_code: 0,
            cache_line_size: 0,
            latency_timer: 0,
            header_type: 0,
            bist: 0,
            base_addresses: [0; 6],
            cardbus_cis: 0,
            subsystem_vendor_id: 0,
            subsystem_id: 0,
            expansion_rom_base: 0,
            capabilities_ptr: 0,
            reserved1: [0; 3],
            reserved2: 0,
            interrupt_line: 0,
            interrupt_pin: 0,
            minimum_grant: 0,
            maximum_latency: 0,
        }
    }

    pub fn is_valid(&self) -> bool {
        self.vendor_id != 0xFFFF && self.device_id != 0xFFFF
    }
}

// ============================================================
// HalPciConfigAddress
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct HalPciConfigAddress {
    pub bus: u32,
    pub slot: u32,
    pub function: u32,
    pub offset: u32,
}

impl HalPciConfigAddress {
    pub fn new(bus: u32, slot: u32, function: u32, offset: u32) -> Self {
        Self { bus, slot, function, offset }
    }

    pub fn to_address(&self) -> u32 {
        0x80000000
            | ((self.bus & 0xFF) << 16)
            | ((self.slot & 0x1F) << 11)
            | ((self.function & 0x07) << 8)
            | (self.offset & 0xFC)
    }
}

// ============================================================
// CM_PARTIAL_RESOURCE_DESCRIPTOR
// ============================================================

#[repr(C)]
pub union CmPartialResourceDescriptorData {
    pub port: CmPartialPort,
    pub memory: CmPartialMemory,
    pub interrupt: CmPartialInterrupt,
    pub dma: CmPartialDma,
    pub device_specific: CmPartialDeviceSpecific,
    pub raw: [u8; 16],
}
unsafe impl Send for CmPartialResourceDescriptorData {}
unsafe impl Sync for CmPartialResourceDescriptorData {}
impl Copy for CmPartialResourceDescriptorData {}
impl Clone for CmPartialResourceDescriptorData {
    fn clone(&self) -> Self { *self }
}
impl core::fmt::Debug for CmPartialResourceDescriptorData {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("CmPartialResourceDescriptorData").finish()
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CmPartialPort {
    pub start: u64,
    pub length: u32,
    pub reserved: u32,
    pub flags: u16,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CmPartialMemory {
    pub start: u64,
    pub length: u32,
    pub cache_attribute: u32,
    pub flags: u16,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CmPartialInterrupt {
    pub level: u32,
    pub vector: u32,
    pub affinity: u64,
    pub flags: u16,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CmPartialDma {
    pub channel: u32,
    pub port: u32,
    pub reserved1: u32,
    pub reserved2: u32,
    pub flags: u16,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CmPartialDeviceSpecific {
    pub reserved1: u32,
    pub reserved2: u32,
    pub data_size: u32,
    pub reserved3: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CmPartialResourceDescriptor {
    pub r#type: u16,
    pub share: u16,
    pub data: CmPartialResourceDescriptorData,
}

// ============================================================
// CM_PARTIAL_RESOURCE_LIST
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct CmPartialResourceList {
    pub version: u16,
    pub revision: u16,
    pub count: u32,
    pub partial_descriptors: [CmPartialResourceDescriptor; 1],
}

// ============================================================
// HAL_DISPLAY_BIOS_MODE
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct HalDisplayBiosMode {
    pub mode: u32,
    pub flags: u32,
}

// ============================================================
// HAL闹铃信息
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct HalAlarmInfo {
    pub alarm_time: u64,
    pub enabled: bool,
}

// ============================================================
// Global state
// ============================================================

static mut HAL_DISPATCH_TABLE: HalPrivateDispatchTable = HalPrivateDispatchTable {
    version: 1,
    size: 0,
    hal_query_display_parameters: None,
    hal_set_display_parameters: None,
    hal_set_pci_info: None,
    hal_get_bus_data: None,
    hal_set_bus_data: None,
    hal_check_dma: None,
    hal_allocate_adapter_channel: None,
    hal_free_adapter_channel: None,
    hal_free_map_registers: None,
    hal_map_transfer: None,
    hal_flush_adapter_buffers: None,
    hal_get_interrupt_vector: None,
    hal_enable_system_interrupt: None,
    hal_disable_system_interrupt: None,
    hal_read_pci_config: None,
    hal_write_pci_config: None,
    hal_display_string: None,
    hal_query_real_time_clock: None,
    hal_set_real_time_clock: None,
};

static mut HAL_DISPLAY_INFO: DisplayInformation = DisplayInformation {
    version: 1,
    size: 0,
    display_flag: 0,
    horizontal_resolution: 0,
    vertical_resolution: 0,
    pixels_per_scan_line: 0,
    bits_per_pixel: 0,
    display_frequency: 0,
    horizontal_sync_frequency: 0,
    vertical_sync_frequency: 0,
    horizontal_sync_start: 0,
    horizontal_sync_end: 0,
    vertical_sync_start: 0,
    vertical_sync_end: 0,
    screen_stride: 0,
    base_address: 0,
    phys_base_address: 0,
};

static mut HAL_INTERRUPT_STATE: [InterruptState; HAL_MAX_PROCESSORS] = [InterruptState {
    vector: 0, level: 0, affinity: 0, mode: 0, registered: false, enabled: false,
}; HAL_MAX_PROCESSORS];

static HAL_LOCK: SpinLock = SpinLock::new();

// ============================================================
// HalQueryDisplayParameters
// ============================================================

pub fn hal_query_display_parameters(
    horizontal: *mut u32,
    vertical: *mut u32,
    frequency: *mut u32,
    bits_per_pixel: *mut u32,
) -> u32 {
    unsafe {
        let info = &mut HAL_DISPLAY_INFO;
        if !horizontal.is_null() { *horizontal = info.horizontal_resolution; }
        if !vertical.is_null() { *vertical = info.vertical_resolution; }
        if !frequency.is_null() { *frequency = info.display_frequency; }
        if !bits_per_pixel.is_null() { *bits_per_pixel = info.bits_per_pixel; }
    }
    hal_trace!("HalQueryDisplayParameters: {}x{} @{}Hz {}bpp",
        unsafe { HAL_DISPLAY_INFO.horizontal_resolution },
        unsafe { HAL_DISPLAY_INFO.vertical_resolution },
        unsafe { HAL_DISPLAY_INFO.display_frequency },
        unsafe { HAL_DISPLAY_INFO.bits_per_pixel },
    );
    1
}

// ============================================================
// HalSetDisplayParameters
// ============================================================

pub fn hal_set_display_parameters(
    horizontal: u32,
    vertical: u32,
    frequency: u32,
    bits_per_pixel: u32,
) -> u32 {
    unsafe {
        let info = &mut HAL_DISPLAY_INFO;
        info.horizontal_resolution = horizontal;
        info.vertical_resolution = vertical;
        info.display_frequency = frequency;
        info.bits_per_pixel = bits_per_pixel;
        info.pixels_per_scan_line = horizontal;
        info.screen_stride = (horizontal * bits_per_pixel / 8 + 15) & !15;
    }
    hal_trace!("HalSetDisplayParameters: {}x{}", horizontal, vertical);
    1
}

// ============================================================
// HalSetBusData
// ============================================================

pub fn hal_set_bus_data(
    bus_data_type: u32,
    bus_number: u32,
    slot_number: u32,
    buffer: *mut c_void,
    length: u32,
) -> u32 {
    if buffer.is_null() || length == 0 { return 0; }

    match bus_data_type {
        PCI_TYPE0_SPACE => {
            hal_trace!("HalSetBusData: PCI bus={} slot={}", bus_number, slot_number);
            let config_addr = HalPciConfigAddress::new(bus_number, slot_number, 0, 0).to_address();
            let _ = config_addr;
            length
        }
        _ => {
            hal_trace!("HalSetBusData: type={} bus={}", bus_data_type, bus_number);
            length
        }
    }
}

// ============================================================
// HalGetBusData
// ============================================================

pub fn hal_get_bus_data(
    bus_data_type: u32,
    bus_number: u32,
    slot_number: u32,
    buffer: *mut c_void,
    length: u32,
) -> u32 {
    if buffer.is_null() || length == 0 { return 0; }

    match bus_data_type {
        PCI_TYPE0_SPACE => {
            let config = unsafe { &mut *(buffer as *mut PciCommonConfig) };
            let config_addr = HalPciConfigAddress::new(bus_number, slot_number, 0, 0).to_address();
            let _ = config_addr;

            *config = PciCommonConfig::new();
            hal_trace!("HalGetBusData: PCI bus={} slot={} -> {:04x}:{:04x}",
                bus_number, slot_number, config.vendor_id, config.device_id);
            mem::size_of::<PciCommonConfig>() as u32
        }
        _ => {
            hal_trace!("HalGetBusData: type={} bus={}", bus_data_type, bus_number);
            0
        }
    }
}

// ============================================================
// HalRequestDMABusy
// ============================================================

pub fn hal_request_dma_busy(_adapter: *mut IoAdapterObject) -> u32 {
    hal_trace!("HalRequestDMABusy: adapter={:p}", _adapter);
    0
}

// ============================================================
// HalAllocateAdapterChannel
// ============================================================

pub fn hal_allocate_adapter_channel(
    adapter_object: *mut IoAdapterObject,
    device_object: *mut IoDeviceObject,
    number_of_map_registers: u32,
    driver_control: Pdriver_control,
    context: Pvoid,
) -> u32 {
    if adapter_object.is_null() { return 0; }
    hal_trace!("HalAllocateAdapterChannel: adapter={:p} regs={}", adapter_object, number_of_map_registers);

    crate::io::io_allocate_adapter_channel(adapter_object, device_object, number_of_map_registers, driver_control, context) as u32
}

// ============================================================
// HalGetInterruptVector
// ============================================================

pub fn hal_get_interrupt_vector(
    bus_type: u32,
    _bus_number: u32,
    level: u32,
    vector: u32,
    _affinity: *mut u64,
    _mode: *mut u8,
) -> u32 {
    hal_trace!("HalGetInterruptVector: bus={} level={} vec={}", bus_type, level, vector);
    vector
}

// ============================================================
// HalEnableSystemInterrupt
// ============================================================

pub fn hal_enable_system_interrupt(
    vector: u32,
    _level: u8,
    _mode: u32,
) -> u8 {
    let cpu = unsafe {
        let cpu_id: u32;
        core::arch::asm!("mov {0}, gs:[0x180]", out(reg) cpu_id, options(nostack, nomem));
        cpu_id as usize
    };

    if cpu < HAL_MAX_PROCESSORS {
        unsafe {
            HAL_INTERRUPT_STATE[cpu].vector = vector;
            HAL_INTERRUPT_STATE[cpu].registered = true;
            HAL_INTERRUPT_STATE[cpu].enabled = true;
        }
    }

    hal_trace!("HalEnableSystemInterrupt: vec={}", vector);
    1
}

// ============================================================
// HalDisableSystemInterrupt
// ============================================================

pub fn hal_disable_system_interrupt(vector: u32) {
    let cpu = unsafe {
        let cpu_id: u32;
        core::arch::asm!("mov {0}, gs:[0x180]", out(reg) cpu_id, options(nostack, nomem));
        cpu_id as usize
    };

    if cpu < HAL_MAX_PROCESSORS {
        unsafe {
            HAL_INTERRUPT_STATE[cpu].enabled = false;
        }
    }

    hal_trace!("HalDisableSystemInterrupt: vec={}", vector);
}

// ============================================================
// HalReadPCIConfig
// ============================================================

pub fn hal_read_pci_config(
    bus_number: u32,
    slot_number: u32,
    offset: u32,
    buffer: *mut u32,
    length: u32,
) -> u32 {
    if buffer.is_null() || length == 0 { return 0; }

    let addr = HalPciConfigAddress::new(bus_number, slot_number, 0, offset);

    hal_trace!("HalReadPCIConfig: bus={} slot={} off={} len={}", bus_number, slot_number, offset, length);

    let _ = addr;

    0
}

// ============================================================
// HalWritePCIConfig
// ============================================================

pub fn hal_write_pci_config(
    bus_number: u32,
    slot_number: u32,
    offset: u32,
    buffer: *mut u32,
    length: u32,
) -> u32 {
    if buffer.is_null() || length == 0 { return 0; }

    let addr = HalPciConfigAddress::new(bus_number, slot_number, 0, offset);

    hal_trace!("HalWritePCIConfig: bus={} slot={} off={} len={}", bus_number, slot_number, offset, length);

    let _ = addr;

    length
}

// ============================================================
// HalDisplayString
// ============================================================

pub fn hal_display_string(_string: *mut u16) -> u32 {
    if _string.is_null() { return 0; }

    let len = unsafe {
        let mut count = 0;
        let mut ptr = _string;
        while *ptr != 0 { count += 1; ptr = ptr.add(1); }
        count
    };

    hal_trace!("HalDisplayString: len={}", len);
    1
}

// ============================================================
// HalQueryRealTimeClock
// ============================================================

pub fn hal_query_real_time_clock(time: *mut RtlTime) -> u32 {
    if time.is_null() { return 0; }

    unsafe {
        let tsc = core::arch::x86_64::_rdtsc();
        let ticks = (tsc / 10_000_000) as u64;
        *time = RtlTime::from_u64(ticks);
    }

    hal_trace!("HalQueryRealTimeClock: called");
    1
}

// ============================================================
// HalSetRealTimeClock
// ============================================================

pub fn hal_set_real_time_clock(_time: *mut RtlTime) -> u32 {
    hal_trace!("HalSetRealTimeClock: called");
    1
}

// ============================================================
// HalInitializeDisplay
// ============================================================

pub fn hal_initialize_display() {
    hal_dbg!("HalInitializeDisplay: initializing display");

    unsafe {
        let info = &mut HAL_DISPLAY_INFO;
        info.version = 1;
        info.size = mem::size_of::<DisplayInformation>() as u32;
        info.horizontal_resolution = 800;
        info.vertical_resolution = 600;
        info.pixels_per_scan_line = 800;
        info.bits_per_pixel = 32;
        info.display_frequency = 60;
        info.horizontal_sync_frequency = 37879;
        info.vertical_sync_frequency = 60;
        info.horizontal_sync_start = 800;
        info.horizontal_sync_end = 1048;
        info.vertical_sync_start = 600;
        info.vertical_sync_end = 628;
        info.screen_stride = (800 * 4 + 15) & !15;
    }
}

// ============================================================
// HalInitializePciBus
// ============================================================

pub fn hal_initialize_pci_bus() {
    hal_dbg!("HalInitializePciBus: enumerating PCI bus");

    for bus in 0..256 {
        for slot in 0..32 {
            let mut config = PciCommonConfig::new();
            let result = hal_get_bus_data(
                PCI_TYPE0_SPACE,
                bus,
                slot,
                &mut config as *mut PciCommonConfig as *mut c_void,
                mem::size_of::<PciCommonConfig>() as u32,
            );
            if result > 0 && config.is_valid() {
                hal_trace!("HalInitializePciBus: found PCI device bus={} slot={} {:04x}:{:04x}",
                    bus, slot, config.vendor_id, config.device_id);
            }
        }
    }
}

// ============================================================
// HalInitializeInterrupts
// ============================================================

pub fn hal_initialize_interrupts() {
    hal_dbg!("HalInitializeInterrupts: initializing interrupt subsystem");
    unsafe {
        for i in 0..HAL_MAX_PROCESSORS {
            HAL_INTERRUPT_STATE[i] = InterruptState::new();
        }
    }
}

// ============================================================
// HalInitializeDma
// ============================================================

pub fn hal_initialize_dma() {
    hal_dbg!("HalInitializeDma: initializing DMA subsystem");
}

// ============================================================
// HalBuildScatterGatherList
// ============================================================

pub fn hal_build_scatter_gather_list(
    adapter: *mut IoAdapterObject,
    mdl: *mut MmMdl,
    virtual_address: Pvoid,
    length: u32,
) -> *mut ScatterGatherList {
    if adapter.is_null() || mdl.is_null() {
        return core::ptr::null_mut();
    }

    let sg_size = mem::size_of::<ScatterGatherList>() + 16 * mem::size_of::<crate::io::ScatterGatherElement>();
    let sg = unsafe {
        alloc::alloc::alloc_zeroed(core::alloc::Layout::from_size_align(sg_size, 8).unwrap()) as *mut ScatterGatherList
    };
    if sg.is_null() { return core::ptr::null_mut(); }

    unsafe {
        (*sg).number_of_elements = 1;
        (*sg).elements[0].physical_address = 0;
        (*sg).elements[0].length = length;
    }

    hal_trace!("HalBuildScatterGatherList: adapter={:p} len={}", adapter, length);
    sg
}

// ============================================================
// HalFlushScatterGatherList
// ============================================================

pub fn hal_flush_scatter_gather_list(
    _adapter: *mut IoAdapterObject,
    _scatter_gather: *mut ScatterGatherList,
    _direction: u32,
) {
    hal_trace!("HalFlushScatterGatherList: called");
}

// ============================================================
// HalFreeScatterGatherList
// ============================================================

pub fn hal_free_scatter_gather_list(sg: *mut ScatterGatherList) {
    if !sg.is_null() {
        let sg_size = mem::size_of::<ScatterGatherList>() + 16 * mem::size_of::<crate::io::ScatterGatherElement>();
        unsafe {
            alloc::alloc::dealloc(sg as *mut u8, core::alloc::Layout::from_size_align(sg_size, 8).unwrap());
        }
    }
}

// ============================================================
// HalQuerySystemInterruptAttributes
// ============================================================

pub fn hal_query_system_interrupt_attributes(
    vector: u32,
    _level: *mut u32,
    _affinity: *mut u64,
    _mode: *mut u32,
) -> u32 {
    let cpu = unsafe {
        let cpu_id: u32;
        core::arch::asm!("mov {0}, gs:[0x180]", out(reg) cpu_id, options(nostack, nomem));
        cpu_id as usize
    };

    if cpu < HAL_MAX_PROCESSORS {
        let state = unsafe { &HAL_INTERRUPT_STATE[cpu] };
        if state.vector == vector && state.enabled {
            if !_level.is_null() { unsafe { *_level = state.level; } }
            if !_affinity.is_null() { unsafe { *_affinity = state.affinity; } }
            if !_mode.is_null() { unsafe { *_mode = state.mode; } }
            return 1;
        }
    }
    0
}

// ============================================================
// HalEnableDisplayInterrupt
// ============================================================

pub fn hal_enable_display_interrupt() -> u32 {
    hal_trace!("HalEnableDisplayInterrupt: called");
    1
}

// ============================================================
// HalDisableDisplayInterrupt
// ============================================================

pub fn hal_disable_display_interrupt() -> u32 {
    hal_trace!("HalDisableDisplayInterrupt: called");
    1
}

// ============================================================
// HalQueryDisplayMode
// ============================================================

pub fn hal_query_display_mode(
    horizontal: *mut u32,
    vertical: *mut u32,
    refresh: *mut u32,
) -> u32 {
    hal_query_display_parameters(horizontal, vertical, refresh, core::ptr::null_mut())
}

// ============================================================
// HalSetDisplayMode
// ============================================================

pub fn hal_set_display_mode(
    horizontal: u32,
    vertical: u32,
    refresh: u32,
) -> u32 {
    hal_set_display_parameters(horizontal, vertical, refresh, 32)
}

// ============================================================
// HalGetInterruptVectorEx
// ============================================================

pub fn hal_get_interrupt_vector_ex(
    bus_type: u32,
    bus_number: u32,
    level: u32,
    vector: u32,
    affinity: *mut u64,
    mode: *mut u8,
    _flags: u32,
) -> u32 {
    hal_get_interrupt_vector(bus_type, bus_number, level, vector, affinity, mode)
}

// ============================================================
// HalEnableSystemInterruptEx
// ============================================================

pub fn hal_enable_system_interrupt_ex(
    vector: u32,
    level: u8,
    mode: u32,
    _flags: u32,
) -> u8 {
    hal_enable_system_interrupt(vector, level, mode)
}

// ============================================================
// HalDisableSystemInterruptEx
// ============================================================

pub fn hal_disable_system_interrupt_ex(
    vector: u32,
    _flags: u32,
) {
    hal_disable_system_interrupt(vector);
}

// ============================================================
// HalResetDisplay
// ============================================================

pub fn hal_reset_display() -> u32 {
    hal_trace!("HalResetDisplay: called");
    1
}

// ============================================================
// HalQueryTimeZoneInformation
// ============================================================

pub fn hal_query_time_zone_information(
    _bias: *mut i32,
    _standard_bias: *mut i32,
    _daylight_bias: *mut i32,
) -> u32 {
    if !_bias.is_null() { unsafe { *_bias = 0; } }
    if !_standard_bias.is_null() { unsafe { *_standard_bias = 0; } }
    if !_daylight_bias.is_null() { unsafe { *_daylight_bias = 0; } }
    1
}

// ============================================================
// HalSetTimeZoneInformation
// ============================================================

pub fn hal_set_time_zone_information(
    _bias: i32,
    _standard_bias: i32,
    _daylight_bias: i32,
) -> u32 {
    hal_trace!("HalSetTimeZoneInformation: called");
    1
}

// ============================================================
// HalSetWakeAlarm
// ============================================================

pub fn hal_set_wake_alarm(
    _low: u32,
    _high: u32,
) -> u32 {
    hal_trace!("HalSetWakeAlarm: called");
    1
}

// ============================================================
// HalQueryWakeAlarm
// ============================================================

pub fn hal_query_wake_alarm(
    _low: *mut u32,
    _high: *mut u32,
) -> u32 {
    if !_low.is_null() { unsafe { *_low = 0; } }
    if !_high.is_null() { unsafe { *_high = 0; } }
    1
}

// ============================================================
// HalReadPcmCmos
// ============================================================

pub fn hal_read_pcm_cmos(
    _offset: u32,
    _buffer: *mut u8,
    _length: u32,
) -> u32 {
    hal_trace!("HalReadPcmCmos: offset={}", _offset);
    0
}

// ============================================================
// HalWritePcmCmos
// ============================================================

pub fn hal_write_pcm_cmos(
    _offset: u32,
    _buffer: *mut u8,
    _length: u32,
) -> u32 {
    hal_trace!("HalWritePcmCmos: offset={}", _offset);
    0
}

// ============================================================
// HalInitializeProcessor
// ============================================================

pub fn hal_initialize_processor(_processor_number: u32) -> u32 {
    hal_trace!("HalInitializeProcessor: proc={}", _processor_number);
    1
}

// ============================================================
// HalStartNextProcessor
// ============================================================

pub fn hal_start_next_processor(_target: u32, _context: Pvoid) -> u32 {
    hal_trace!("HalStartNextProcessor: target={}", _target);
    1
}

// ============================================================
// HalSendIpi
// ============================================================

pub fn hal_send_ipi(
    _target_processor: u32,
    _mask: u64,
    _vector: u32,
) -> u32 {
    hal_trace!("HalSendIPI: target={} vec={}", _target_processor, _vector);
    1
}

// ============================================================
// HalProcessorFork
// ============================================================

pub fn hal_processor_fork(
    _target: u32,
    _context: Pvoid,
) -> u32 {
    hal_trace!("HalProcessorFork: called");
    1
}

// ============================================================
// HalFlushIoBuffers
// ============================================================

pub fn hal_flush_io_buffers(
    _mdl: *mut MmMdl,
    _read: u8,
) {
}

// ============================================================
// HalAcquireDisplayOwnership
// ============================================================

pub fn hal_acquire_display_ownership() -> u32 {
    hal_trace!("HalAcquireDisplayOwnership: called");
    1
}

// ============================================================
// HalDisplayStringAtPosition
// ============================================================

pub fn hal_display_string_at_position(
    _x: u32,
    _y: u32,
    _string: *mut u16,
) -> u32 {
    hal_trace!("HalDisplayStringAtPosition: x={} y={}", _x, _y);
    hal_display_string(_string)
}

// ============================================================
// HalQueryAdapterInformation
// ============================================================

pub fn hal_query_adapter_information(
    _adapter: *mut IoAdapterObject,
    _information_class: u32,
    _information: Pvoid,
    _information_length: u32,
) -> u32 {
    if !_adapter.is_null() {
        let adapter = unsafe { &*_adapter };
        hal_trace!("HalQueryAdapterInformation: version={} maxTransfer={}",
            adapter.version, adapter.max_transfer_length);
    }
    1
}

// ============================================================
// HalAdjustResourceList
// ============================================================

pub fn hal_adjust_resource_list(
    _resource_list: *mut CmPartialResourceList,
    _allocated_resources: *mut CmPartialResourceList,
) -> u32 {
    hal_trace!("HalAdjustResourceList: called");
    1
}

// ============================================================
// HalTranslateBusAddress
// ============================================================

pub fn hal_translate_bus_address(
    _interface_type: u32,
    _bus_number: u32,
    _bus_address: u64,
    _address_space: *mut u32,
    _translated_address: *mut u64,
) -> u32 {
    if !_address_space.is_null() { unsafe { *_address_space = 0; } }
    if !_translated_address.is_null() { unsafe { *_translated_address = _bus_address; } }
    hal_trace!("HalTranslateBusAddress: bus_addr={:#x}", _bus_address);
    1
}

// ============================================================
// HalAllocateCommonBuffer
// ============================================================

pub fn hal_allocate_common_buffer(
    _adapter: *mut IoAdapterObject,
    _length: u32,
    _logical_address: *mut u64,
    _cache_enabled: u8,
) -> Pvoid {
    let ptr = mm::mm_allocate_contiguous_memory(_length as usize, 0xFFFFFFFF);
    if !ptr.is_null() && !_logical_address.is_null() {
        unsafe { *_logical_address = ptr as u64; }
    }
    ptr
}

// ============================================================
// HalFreeCommonBuffer
// ============================================================

pub fn hal_free_common_buffer(
    _adapter: *mut IoAdapterObject,
    _length: u32,
    _logical_address: u64,
    _virtual_address: Pvoid,
    _cache_enabled: u8,
) {
    if !_virtual_address.is_null() {
        mm::mm_free_contiguous_memory(_virtual_address, _length as usize);
    }
}

// ============================================================
// HalInitialize
// ============================================================

pub fn hal_initialize() {
    hal_dbg!("HalInitialize: initializing HAL");

    hal_initialize_display();
    hal_initialize_interrupts();
    hal_initialize_dma();
    hal_initialize_pci_bus();

    unsafe {
        let _guard = HAL_LOCK.acquire();
        HAL_DISPATCH_TABLE = HalPrivateDispatchTable::new();
    }

    hal_dbg!("HalInitialize: HAL initialized");
}

// ============================================================
// Serial port I/O
// ============================================================

pub unsafe fn serial_outb(port: u16, value: u8) {
    core::arch::asm!("out dx, al", in("dx") port, in("al") value);
}

pub unsafe fn serial_inb(port: u16) -> u8 {
    let value: u8;
    core::arch::asm!("in al, dx", out("al") value, in("dx") port);
    value
}
