/// Ke/Profile - System Performance Profiling
use core::ffi::c_void;
use crate::types::*;
use super::dispatcher::ListEntry;

pub struct KprofileObject {
    pub interval: u32,
    pub buffer: *mut u8,
    pub buffer_size: u32,
    pub flags: u32,
    pub source: u32,
    pub active: bool,
    pub process: Peprocess,
    pub affine_process: Peprocess,
    pub affine_shift: u32,
    pub next: *mut KprofileObject,
    pub lock: u64,
}

static mut PROFILE_LIST: *mut KprofileObject = core::ptr::null_mut();
static mut PROFILE_LOCK: u64 = 0;
static mut SYSTEM_TIME: u64 = 0;

pub unsafe fn ke_start_profile(
    buffer: *mut u8,
    buffer_size: u32,
    interval: u32,
) -> NtStatus {
    if buffer.is_null() || buffer_size == 0 {
        return STATUS_INVALID_PARAMETER;
    }

    let obj = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<KprofileObject>()
    ) as *mut KprofileObject;

    if obj.is_null() {
        return STATUS_NO_MEMORY;
    }

    core::ptr::write_bytes(obj as *mut u8, 0, core::mem::size_of::<KprofileObject>());
    (*obj).buffer = buffer;
    (*obj).buffer_size = buffer_size;
    (*obj).interval = interval;
    (*obj).active = true;
    (*obj).next = PROFILE_LIST;
    PROFILE_LIST = obj;

    STATUS_SUCCESS
}

pub unsafe fn ke_stop_profile(obj: *mut KprofileObject) {
    if obj.is_null() { return; }
    let mut prev: *mut KprofileObject = core::ptr::null_mut();
    let mut cur = PROFILE_LIST;

    while !cur.is_null() {
        if cur == obj {
            if prev.is_null() {
                PROFILE_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            (*cur).active = false;
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return;
        }
        prev = cur;
        cur = (*cur).next;
    }
}

pub unsafe fn ke_set_system_affinity_thread(affinity: Kaffinity) {
    let thread = super::dispatcher::ki_get_current_thread();
    if !thread.is_null() {
        (*thread).user_affinity = affinity;
    }
}

pub unsafe fn ki_profile_interrupt(vector: u32, _error_code: u64, _rip: u64, _cr2: u64) {
    SYSTEM_TIME += 1;

    let mut cur = PROFILE_LIST;
    while !cur.is_null() {
        if (*cur).active {
            let slot = ((SYSTEM_TIME / (*cur).interval as u64) % ((*cur).buffer_size / 8) as u64) as usize;
            let entry = (*cur).buffer.add(slot * 8) as *mut u64;
            core::ptr::write_volatile(entry, core::ptr::read_volatile(entry).wrapping_add(1));
        }
        cur = (*cur).next;
    }
}

pub unsafe fn ke_update_system_time(delta: u64) {
    SYSTEM_TIME = SYSTEM_TIME.wrapping_add(delta);
}

pub unsafe fn ke_query_system_time() -> u64 {
    SYSTEM_TIME
}
