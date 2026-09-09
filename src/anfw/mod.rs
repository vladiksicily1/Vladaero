/// AnFw - Animation Framework for Bootup (AnFw/AnFwp)
use core::ffi::c_void;
use crate::types::*;

pub struct AnFwState {
    pub initialized: bool,
    pub animation_active: bool,
    pub boot_phase: u32,
    pub work_item_count: u32,
}

static mut ANFW_STATE: AnFwState = AnFwState {
    initialized: false,
    animation_active: false,
    boot_phase: 0,
    work_item_count: 0,
};

pub unsafe fn anfw_initialize() -> NtStatus {
    ANFW_STATE.initialized = true;
    ANFW_STATE.boot_phase = 0;
    STATUS_SUCCESS
}

pub unsafe fn anfw_initialize_work_item(
    _callback: *mut c_void,
    _context: *mut c_void,
) -> NtStatus {
    if !ANFW_STATE.initialized { return STATUS_NOT_IMPLEMENTED; }
    ANFW_STATE.work_item_count += 1;
    STATUS_SUCCESS
}

pub unsafe fn anfw_post_render() {
    if !ANFW_STATE.initialized { return; }
    ANFW_STATE.animation_active = true;
}

pub unsafe fn anfw_set_boot_phase(phase: u32) {
    ANFW_STATE.boot_phase = phase;
    if phase >= 4 {
        ANFW_STATE.animation_active = false;
    }
}

// ============================================================
// Win10 boot animation: frame timeline, spinner, progress
// ============================================================

pub const ANFW_PHASE_FIRMWARE: u32 = 0;
pub const ANFW_PHASE_BOOTMGR: u32 = 1;
pub const ANFW_PHASE_KERNEL_INIT: u32 = 2;
pub const ANFW_PHASE_DRIVERS: u32 = 3;
pub const ANFW_PHASE_SESSION: u32 = 4;
pub const ANFW_PHASE_LOGON: u32 = 5;

pub const ANFW_MAX_FRAMES: usize = 256;
pub const ANFW_SPINNER_DOTS: usize = 6;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AnFwFrame {
    pub frame_index: u32,
    pub timestamp_ms: u64,
    pub phase: u32,
    pub progress_percent: u32,
}

#[repr(C)]
pub struct AnFwTimeline {
    pub frames: [AnFwFrame; ANFW_MAX_FRAMES],
    pub frame_count: u32,
    pub spinner_position: u32,
    pub start_time_ms: u64,
    pub progress_percent: u32,
    pub fault_text: [u16; 128],
    pub has_fault: bool,
}

static mut ANFW_TIMELINE: AnFwTimeline = AnFwTimeline {
    frames: [AnFwFrame {
        frame_index: 0,
        timestamp_ms: 0,
        phase: 0,
        progress_percent: 0,
    }; ANFW_MAX_FRAMES],
    frame_count: 0,
    spinner_position: 0,
    start_time_ms: 0,
    progress_percent: 0,
    fault_text: [0; 128],
    has_fault: false,
};

/// AnfwpTick - advance the spinner + record a frame.
pub unsafe fn anfw_tick(now_ms: u64, phase: u32) {
    ANFW_TIMELINE.spinner_position =
        (ANFW_TIMELINE.spinner_position + 1) % ANFW_SPINNER_DOTS as u32;
    if ANFW_TIMELINE.frame_count < ANFW_MAX_FRAMES as u32 {
        let idx = ANFW_TIMELINE.frame_count as usize;
        ANFW_TIMELINE.frames[idx] = AnFwFrame {
            frame_index: ANFW_TIMELINE.frame_count,
            timestamp_ms: now_ms,
            phase,
            progress_percent: ANFW_TIMELINE.progress_percent,
        };
        ANFW_TIMELINE.frame_count += 1;
    }
    ANFW_STATE.boot_phase = phase;
    ANFW_STATE.animation_active = phase < ANFW_PHASE_LOGON;
}

/// AnfwpSetProgress - 0..100 boot progress for the boot screen.
pub unsafe fn anfw_set_progress(percent: u32) {
    ANFW_TIMELINE.progress_percent = percent.min(100);
}

/// AnfwpReportFault - show a boot error line under the spinner.
pub unsafe fn anfw_report_fault(text: *const u16) {
    if text.is_null() {
        return;
    }
    let mut i = 0;
    while i < 127 && *text.add(i) != 0 {
        ANFW_TIMELINE.fault_text[i] = *text.add(i);
        i += 1;
    }
    ANFW_TIMELINE.fault_text[i] = 0;
    ANFW_TIMELINE.has_fault = true;
}

/// AnfwpQueryState - snapshot for the boot video driver.
pub unsafe fn anfw_query_state(
    spinner: *mut u32,
    progress: *mut u32,
    phase: *mut u32,
    frames: *mut u32,
) {
    if !spinner.is_null() {
        *spinner = ANFW_TIMELINE.spinner_position;
    }
    if !progress.is_null() {
        *progress = ANFW_TIMELINE.progress_percent;
    }
    if !phase.is_null() {
        *phase = ANFW_STATE.boot_phase;
    }
    if !frames.is_null() {
        *frames = ANFW_TIMELINE.frame_count;
    }
}
