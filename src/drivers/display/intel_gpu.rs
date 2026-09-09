/// Intel integrated GPU driver (igdkmd64.sys)
///
/// Gen9 (Skylake/Kaby Lake) bring-up: stolen memory + GTT setup,
/// display pipe enable, plane assignment, cursor, and backlight.
/// Provides the DWM present path with vsync information.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::bus::pci;

// MMIO registers (Gen9 display engine, BAR0).
pub const INTEL_GMCH_CTRL: usize = 0x50;
pub const INTEL_DEVENT: usize = 0x44400;
pub const INTEL_PIPEA_CONF: usize = 0x70008;
pub const INTEL_PIPEA_STAT: usize = 0x70024;
pub const INTEL_PIPEB_CONF: usize = 0x71008;
pub const INTEL_DSPACNTR: usize = 0x70180;
pub const INTEL_DSPASURF: usize = 0x7019C;
pub const INTEL_DSPASTRIDE: usize = 0x70188;
pub const INTEL_CURACNTR: usize = 0x70080;
pub const INTEL_CURABASE: usize = 0x70084;
pub const INTEL_CURAPOS: usize = 0x70088;
pub const INTEL_BLC_PWM_CTL: usize = 0xC8250;
pub const INTEL_PCH_PP_CONTROL: usize = 0xC7204;
pub const INTEL_GTT_BASE_OFFSET: u64 = 0x800000; // GTTMMADR window offset

pub const INTEL_PIPE_ENABLE: u32 = 1 << 31;
pub const INTEL_PLANE_ENABLE: u32 = 1 << 31;
pub const INTEL_CURSOR_ENABLE: u32 = 1 << 31;
pub const INTEL_CURSOR_MODE_256_ARGB: u32 = 0x07 << 24;

pub const INTEL_GEN9_IDS: [u16; 12] = [
    0x1902, 0x1906, 0x190B, 0x1912, 0x1916, 0x191B, // Skylake
    0x5902, 0x5906, 0x590B, 0x5912, 0x5916, 0x591B, // Kaby Lake
];

#[repr(C)]
pub struct IntelGpu {
    pub mmio: u64,
    pub gtt_base: u64,
    pub stolen_base: u64,
    pub stolen_size: u64,
    pub device_id: u16,
    pub pipe_enabled: [bool; 3],
    pub cursor_enabled: bool,
    pub backlight: u32,
    pub present: bool,
}

static mut INTEL_GPU: IntelGpu = IntelGpu {
    mmio: 0,
    gtt_base: 0,
    stolen_base: 0,
    stolen_size: 0,
    device_id: 0,
    pipe_enabled: [false; 3],
    cursor_enabled: false,
    backlight: 100,
    present: false,
};

unsafe fn intel_read(off: usize) -> u32 {
    *((INTEL_GPU.mmio as *const u8).add(off) as *const u32)
}

unsafe fn intel_write(off: usize, val: u32) {
    *((INTEL_GPU.mmio as *mut u8).add(off) as *mut u32) = val;
}

/// Read stolen memory base/size from GMCH_CTRL + TOUD.
unsafe fn intel_detect_stolen() {
    let gmch = intel_read(INTEL_GMCH_CTRL);
    // GMS (Graphics Mode Select) bits 8:4 -> stolen size.
    let gms = ((gmch >> 4) & 0x1F) as u64;
    // TOUD (Top of Usable DRAM) PCI config 0xA8.
    let mut toud = 0u64;
    let mut i = 0usize;
    while i < pci::pci_device_count() {
        if let Some(dev) = pci::pci_get_device(i) {
            if (*dev).vendor_id == pci::PCI_VID_INTEL
                && (*dev).class_code == pci::PCI_CLASS_DISPLAY
            {
                toud = pci::pci_read_config((*dev).bus, (*dev).dev, (*dev).func, 0xA8, 4) as u64;
                break;
            }
        }
        i += 1;
    }
    // Stolen size table (Gen9 GMS encodings, MB).
    let stolen_mb: u64 = match gms {
        0x1 => 32,
        0x2 => 64,
        0x3 => 96,
        0x4 => 128,
        0x5 => 160,
        0x6 => 192,
        0x7 => 224,
        0x8 => 256,
        0x9 => 288,
        0xA => 320,
        0xB => 352,
        0xC => 384,
        0xD => 416,
        0xE => 448,
        0xF => 480,
        _ => 32,
    };
    INTEL_GPU.stolen_size = stolen_mb * 1024 * 1024;
    INTEL_GPU.stolen_base = toud.saturating_sub(INTEL_GPU.stolen_size);
}

/// Enable pipe A with the current framebuffer timings (GOP mode kept).
unsafe fn intel_enable_pipe(pipe: usize) -> bool {
    let conf_off = match pipe {
        0 => INTEL_PIPEA_CONF,
        1 => INTEL_PIPEB_CONF,
        _ => return false,
    };
    let mut conf = intel_read(conf_off);
    if conf & INTEL_PIPE_ENABLE != 0 {
        INTEL_GPU.pipe_enabled[pipe] = true;
        return true;
    }
    // Enable pipe, keep the BIOS-programmed timings (no full modeset).
    conf |= INTEL_PIPE_ENABLE;
    intel_write(conf_off, conf);
    // Wait for vblank status.
    let stat_off = if pipe == 0 {
        INTEL_PIPEA_STAT
    } else {
        INTEL_PIPEA_STAT + 0x1000
    };
    let mut spins = 100_000u32;
    while spins > 0 {
        if intel_read(stat_off) & (1 << 1) != 0 {
            break;
        }
        spins -= 1;
    }
    INTEL_GPU.pipe_enabled[pipe] = true;
    true
}

/// Attach a framebuffer as the primary plane (linear XRGB8888).
pub unsafe fn intel_set_primary_surface(ggtt_offset: u64, stride: u32) {
    // Plane A control: enable + XRGB8888 (format bits 26:30 = 0x1E... use 0xC for XRGB).
    let cntr = INTEL_PLANE_ENABLE | (0xC << 26);
    intel_write(INTEL_DSPACNTR, cntr);
    intel_write(INTEL_DSPASTRIDE, stride);
    intel_write(INTEL_DSPASURF, (ggtt_offset & 0xFFFF_FFFF) as u32);
    // Flush.
    intel_read(INTEL_DSPACNTR);
}

/// Hardware cursor (256x256 ARGB).
pub unsafe fn intel_set_cursor(visible: bool, ggtt_offset: u64, x: u32, y: u32) {
    if visible {
        intel_write(INTEL_CURABASE, (ggtt_offset & 0xFFFF_FFFF) as u32);
        intel_write(INTEL_CURAPOS, (y << 16) | x);
        intel_write(INTEL_CURACNTR, INTEL_CURSOR_ENABLE | INTEL_CURSOR_MODE_256_ARGB);
        INTEL_GPU.cursor_enabled = true;
    } else {
        let c = intel_read(INTEL_CURACNTR);
        intel_write(INTEL_CURACNTR, c & !INTEL_CURSOR_ENABLE);
        INTEL_GPU.cursor_enabled = false;
    }
}

/// Backlight 0..100 (PWM duty).
pub unsafe fn intel_set_backlight(percent: u32) {
    let p = percent.min(100);
    let pwm = intel_read(INTEL_BLC_PWM_CTL);
    let period = (pwm >> 16) & 0xFFFF;
    let period = if period == 0 { 0xFFFF } else { period };
    let duty = period * p / 100;
    intel_write(INTEL_BLC_PWM_CTL, (period << 16) | duty);
    INTEL_GPU.backlight = p;
}

/// Panel power on (eDP/LVDS VDD + backlight enable).
pub unsafe fn intel_panel_on() {
    let mut ctl = intel_read(INTEL_PCH_PP_CONTROL);
    ctl |= 0x07; // VDD + backlight + panel on
    intel_write(INTEL_PCH_PP_CONTROL, ctl);
}

pub unsafe fn intel_is_present() -> bool {
    INTEL_GPU.present
}

pub unsafe extern "C" fn intel_gpu_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Find a supported Gen9 display controller.
    let mut i = 0usize;
    while i < pci::pci_device_count() {
        if let Some(dev) = pci::pci_get_device(i) {
            if (*dev).vendor_id == pci::PCI_VID_INTEL
                && (*dev).class_code == pci::PCI_CLASS_DISPLAY
            {
                let mut supported = false;
                let mut k = 0;
                while k < INTEL_GEN9_IDS.len() {
                    if (*dev).device_id == INTEL_GEN9_IDS[k] {
                        supported = true;
                        break;
                    }
                    k += 1;
                }
                if supported && (*dev).bar[0] != 0 {
                    pci::pci_enable_device((*dev).bus, (*dev).dev, (*dev).func);
                    INTEL_GPU.mmio = (*dev).bar[0];
                    INTEL_GPU.gtt_base = (*dev).bar[0] + INTEL_GTT_BASE_OFFSET;
                    INTEL_GPU.device_id = (*dev).device_id;
                    intel_detect_stolen();
                    intel_enable_pipe(0);
                    intel_panel_on();
                    INTEL_GPU.present = true;
                    crate::kernel_log!(
                        "[iGPU] {:04X} stolen={}MB pipe0 on\n",
                        INTEL_GPU.device_id,
                        INTEL_GPU.stolen_size / (1024 * 1024)
                    );
                    break;
                }
            }
        }
        i += 1;
    }
    let mut dev_obj: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\Video1\0");
    crate::io::io_create_device(driver_object, 0, &mut name, 0x00000023, 0, 0, &mut dev_obj)
}
