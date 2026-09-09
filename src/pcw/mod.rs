/// Pcw - Performance Counter Framework (Pcw/Pcwp)
use core::ffi::c_void;
use crate::types::*;

pub struct PcwRegistration {
    pub name: [u16; 64],
    pub callback: *mut c_void,
    pub context: *mut c_void,
    pub interval: u32,
    pub active: bool,
}

pub struct PcwNotification {
    pub registration: *mut PcwRegistration,
    pub data: *mut u8,
    pub data_size: u32,
}

pub unsafe fn pcw_register(
    name: *const u16,
    callback: *mut c_void,
    context: *mut c_void,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn pcw_unregister(_registration: *mut PcwRegistration) {
}

pub unsafe fn pcw_create_notification(
    _registration: *mut PcwRegistration,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 PCW: counter sets, instances, collect/query (Pcwp)
// ============================================================

pub const PCW_COUNTER_TYPE_VALUE: u32 = 0;
pub const PCW_COUNTER_TYPE_RATE: u32 = 1;
pub const PCW_COUNTER_TYPE_AVERAGE: u32 = 2;
pub const PCW_COUNTER_TYPE_RAW: u32 = 3;

pub const PCW_MAX_COUNTERS_PER_SET: usize = 32;
pub const PCW_MAX_INSTANCES_PER_SET: usize = 256;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct PcwCounterDescriptor {
    pub counter_id: u32,
    pub counter_type: u32,
    pub value: u64,
    pub base_value: u64,
}

#[repr(C)]
pub struct PcwInstance {
    pub instance_id: u32,
    pub instance_name: [u16; 64],
    pub counters: [PcwCounterDescriptor; PCW_MAX_COUNTERS_PER_SET],
    pub counter_count: u32,
    pub next: *mut PcwInstance,
}

#[repr(C)]
pub struct PcwCounterSet {
    pub set_guid: [u8; 16],
    pub set_name: [u16; 64],
    pub collect_callback: *mut c_void,
    pub collect_context: *mut c_void,
    pub instances: *mut PcwInstance,
    pub instance_count: u32,
    pub next: *mut PcwCounterSet,
}

static mut PCW_SET_LIST: *mut PcwCounterSet = core::ptr::null_mut();
static PCW_NEXT_INSTANCE_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);

unsafe fn pcwp_find_set(guid: *const u8) -> *mut PcwCounterSet {
    if guid.is_null() {
        return core::ptr::null_mut();
    }
    let target = core::slice::from_raw_parts(guid, 16);
    let mut cur = PCW_SET_LIST;
    while !cur.is_null() {
        if &(*cur).set_guid[..] == target {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// PcwRegisterCounterSet - register a provider counter set.
pub unsafe fn pcw_register_counter_set(
    set_guid: *const u8,
    set_name: *const u16,
    collect_callback: *mut c_void,
    collect_context: *mut c_void,
) -> NtStatus {
    if set_guid.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if !pcwp_find_set(set_guid).is_null() {
        return crate::nt::STATUS_OBJECT_NAME_COLLISION;
    }
    let s = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<PcwCounterSet>(),
    ) as *mut PcwCounterSet;
    if s.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(s as *mut u8, 0, core::mem::size_of::<PcwCounterSet>());
    core::ptr::copy_nonoverlapping(set_guid, (*s).set_guid.as_mut_ptr(), 16);
    if !set_name.is_null() {
        let mut i = 0;
        while i < 63 && *set_name.add(i) != 0 {
            (*s).set_name[i] = *set_name.add(i);
            i += 1;
        }
    }
    (*s).collect_callback = collect_callback;
    (*s).collect_context = collect_context;
    (*s).next = PCW_SET_LIST;
    PCW_SET_LIST = s;
    STATUS_SUCCESS
}

/// PcwCreateInstance - add a named instance to a counter set.
pub unsafe fn pcw_create_instance(
    set_guid: *const u8,
    instance_name: *const u16,
    instance_out: *mut *mut PcwInstance,
) -> NtStatus {
    let s = pcwp_find_set(set_guid);
    if s.is_null() {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    if (*s).instance_count >= PCW_MAX_INSTANCES_PER_SET as u32 {
        return STATUS_INSUFFICIENT_RESOURCES;
    }
    let inst = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<PcwInstance>(),
    ) as *mut PcwInstance;
    if inst.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(inst as *mut u8, 0, core::mem::size_of::<PcwInstance>());
    (*inst).instance_id =
        PCW_NEXT_INSTANCE_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    if !instance_name.is_null() {
        let mut i = 0;
        while i < 63 && *instance_name.add(i) != 0 {
            (*inst).instance_name[i] = *instance_name.add(i);
            i += 1;
        }
    }
    (*inst).next = (*s).instances;
    (*s).instances = inst;
    (*s).instance_count += 1;
    if !instance_out.is_null() {
        *instance_out = inst;
    }
    STATUS_SUCCESS
}

/// PcwSetCounter - update one counter value in an instance.
pub unsafe fn pcw_set_counter(
    instance: *mut PcwInstance,
    counter_id: u32,
    counter_type: u32,
    value: u64,
) -> NtStatus {
    if instance.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0u32;
    while i < (*instance).counter_count {
        if (*instance).counters[i as usize].counter_id == counter_id {
            (*instance).counters[i as usize].value = value;
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    if (*instance).counter_count >= PCW_MAX_COUNTERS_PER_SET as u32 {
        return STATUS_BUFFER_OVERFLOW;
    }
    let idx = (*instance).counter_count as usize;
    (*instance).counters[idx].counter_id = counter_id;
    (*instance).counters[idx].counter_type = counter_type;
    (*instance).counters[idx].value = value;
    (*instance).counter_count += 1;
    STATUS_SUCCESS
}

/// PcwAddCounter - accumulate a delta into a counter.
pub unsafe fn pcw_add_counter(
    instance: *mut PcwInstance,
    counter_id: u32,
    delta: i64,
) -> NtStatus {
    if instance.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0u32;
    while i < (*instance).counter_count {
        if (*instance).counters[i as usize].counter_id == counter_id {
            (*instance).counters[i as usize].value =
                (*instance).counters[i as usize].value.wrapping_add(delta as u64);
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    pcw_set_counter(instance, counter_id, PCW_COUNTER_TYPE_VALUE, delta as u64)
}

/// PcwQueryInstance - read back counters of one instance.
pub unsafe fn pcw_query_instance(
    instance: *mut PcwInstance,
    buffer: *mut PcwCounterDescriptor,
    count_in_out: *mut u32,
) -> NtStatus {
    if instance.is_null() || count_in_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if buffer.is_null() || *count_in_out < (*instance).counter_count {
        *count_in_out = (*instance).counter_count;
        return STATUS_BUFFER_TOO_SMALL;
    }
    let mut i = 0u32;
    while i < (*instance).counter_count {
        *buffer.add(i as usize) = (*instance).counters[i as usize];
        i += 1;
    }
    *count_in_out = (*instance).counter_count;
    STATUS_SUCCESS
}

/// PcwCloseInstance - remove an instance from its set.
pub unsafe fn pcw_close_instance(set_guid: *const u8, instance: *mut PcwInstance) -> NtStatus {
    let s = pcwp_find_set(set_guid);
    if s.is_null() || instance.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut prev: *mut PcwInstance = core::ptr::null_mut();
    let mut cur = (*s).instances;
    while !cur.is_null() {
        if cur == instance {
            if prev.is_null() {
                (*s).instances = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            (*s).instance_count -= 1;
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}
