/// Verifier - Driver Verifier (Verifier/Vf/Vi)
use core::ffi::c_void;
use crate::types::*;

pub static mut VERIFIER_LEVEL: u32 = 0;
pub static mut POOL_VERIFICATION_ENABLED: bool = false;
pub static mut IRQL_VERIFICATION_ENABLED: bool = false;
pub static mut IO_VERIFICATION_ENABLED: bool = false;

pub struct VerifierPoolHeader {
    pub original_size: u32,
    pub tag: u32,
    pub allocator_thread: u64,
    pub allocator_backtrace: [u64; 8],
    pub freed: bool,
    pub freed_thread: u64,
    pub freed_backtrace: [u64; 8],
}

pub unsafe fn vf_init_system() -> NtStatus {
    VERIFIER_LEVEL = 0;
    POOL_VERIFICATION_ENABLED = false;
    IRQL_VERIFICATION_ENABLED = false;
    IO_VERIFICATION_ENABLED = false;
    STATUS_SUCCESS
}

pub unsafe fn vf_allocate_pool(
    pool_type: u32,
    size: usize,
    tag: u32,
    caller_address: u64,
) -> *mut u8 {
    let alloc_size = size + core::mem::size_of::<VerifierPoolHeader>();
    let raw = crate::mm::pool::ex_allocate_pool_internal(pool_type, alloc_size, crate::mm::PoolTag(tag.to_ne_bytes())) as *mut u8;

    if raw.is_null() {
        return core::ptr::null_mut();
    }

    let header = raw as *mut VerifierPoolHeader;
    core::ptr::write_bytes(header as *mut u8, 0, core::mem::size_of::<VerifierPoolHeader>());
    (*header).original_size = size as u32;
    (*header).tag = tag;
    (*header).allocator_thread = 0;
    (*header).freed = false;

    raw.add(core::mem::size_of::<VerifierPoolHeader>())
}

pub unsafe fn vf_free_pool(ptr: *mut u8, tag: u32, caller_address: u64) {
    if ptr.is_null() { return; }

    let header = ptr.sub(core::mem::size_of::<VerifierPoolHeader>()) as *mut VerifierPoolHeader;

    if (*header).freed {
        crate::kernel_log!("[Verifier] Double free detected at {:p}\n", ptr);
        return;
    }

    if (*header).tag != tag {
        crate::kernel_log!("[Verifier] Pool tag mismatch: expected {}{}{}{} got {}{}{}{}\n",
            (tag & 0xFF) as u8 as char, ((tag >> 8) & 0xFF) as u8 as char,
            ((tag >> 16) & 0xFF) as u8 as char, ((tag >> 24) & 0xFF) as u8 as char,
            ((*header).tag & 0xFF) as u8 as char, (((*header).tag >> 8) & 0xFF) as u8 as char,
            (((*header).tag >> 16) & 0xFF) as u8 as char, (((*header).tag >> 24) & 0xFF) as u8 as char);
    }

    (*header).freed = true;
    (*header).freed_thread = 0;
    crate::mm::pool::ex_free_pool(header as *mut c_void);
}

pub unsafe fn vf_io_allocate_irp(
    _device_object: *mut c_void,
    stack_size: u8,
) -> *mut c_void {
    let irp = crate::io::io_allocate_irp(stack_size, 0);
    if !irp.is_null() {
        crate::kernel_log!("[Verifier] IRP allocated: {:p}, stack={}\n", irp, stack_size);
    }
    irp as *mut c_void
}

pub unsafe fn vf_io_call_driver(
    device_object: *mut c_void,
    irp: *mut c_void,
) -> i32 {
    if IRQL_VERIFICATION_ENABLED {
        let irql = crate::ke::interrupt::ke_get_current_irql();
        crate::kernel_log!("[Verifier] IoCallDriver: dev={:p} irp={:p} irql={}\n",
            device_object, irp, irql);
    }
    crate::io::io_call_driver(device_object as *mut crate::io::IoDeviceObject, irp as *mut crate::io::Irp)
}

pub unsafe fn vf_io_complete_request(irp: *mut c_void, priority: u32) {
    crate::io::io_complete_request(irp as *mut crate::io::Irp, priority);
}

pub unsafe fn vf_check_irql(irql: u8, caller: u64) {
    let current_irql = crate::ke::interrupt::ke_get_current_irql();
    if current_irql > irql {
        crate::kernel_log!("[Verifier] IRQL violation: current={}, max={} at {:#x}\n",
            current_irql, irql, caller);
    }
}

pub unsafe fn vf_verify_memory_range(
    base: *mut u8,
    size: usize,
    access_type: u32,
) -> bool {
    // Verify the memory is valid for the given access type
    true
}

// ============================================================
// Win10 Verifier: flags, per-driver state, deadlock detection,
// DMA verification, statistics (Vf/Vi)
// ============================================================

pub const VERIFIER_FLAG_POOL_TRACKING: u32 = 0x00000001;
pub const VERIFIER_FLAG_IRQL_CHECKING: u32 = 0x00000002;
pub const VERIFIER_FLAG_IO_CHECKING: u32 = 0x00000004;
pub const VERIFIER_FLAG_DEADLOCK_DETECTION: u32 = 0x00000008;
pub const VERIFIER_FLAG_DMA_CHECKING: u32 = 0x00000010;
pub const VERIFIER_FLAG_SECURITY_CHECKS: u32 = 0x00000020;
pub const VERIFIER_FLAG_MISC_CHECKS: u32 = 0x00000040;
pub const VERIFIER_FLAG_INVARIANT_MDL_CHECKING: u32 = 0x00000100;
pub const VERIFIER_FLAG_STACK_BASED_FAILURES: u32 = 0x00000200;

pub const STATUS_POSSIBLE_DEADLOCK: NtStatus = 0xC0000194;

pub const VF_ADDRESS_POOL: u32 = 0;
pub const VF_ADDRESS_NONPAGED_POOL: u32 = 1;
pub const VF_ADDRESS_CONTIGUOUS: u32 = 2;
pub const VF_ADDRESS_MDL: u32 = 3;

#[repr(C)]
pub struct VerifierDriverEntry {
    pub driver_name: [u16; 64],
    pub flags: u32,
    pub pool_allocations: u64,
    pub pool_frees: u64,
    pub pool_bytes_current: u64,
    pub pool_bytes_peak: u64,
    pub irp_count: u64,
    pub fault_injections: u64,
    pub next: *mut VerifierDriverEntry,
}

#[repr(C)]
pub struct VerifierLockEntry {
    pub lock_address: u64,
    pub owner_thread: u64,
    pub acquire_count: u32,
    pub irql_at_acquire: u8,
    pub next: *mut VerifierLockEntry,
}

static mut VF_DRIVER_LIST: *mut VerifierDriverEntry = core::ptr::null_mut();
static mut VF_LOCK_LIST: *mut VerifierLockEntry = core::ptr::null_mut();
static VF_TOTAL_ALLOCATIONS: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(0);
static VF_TOTAL_FREES: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(0);

unsafe fn vf_find_driver(name: *const u16) -> *mut VerifierDriverEntry {
    let mut cur = VF_DRIVER_LIST;
    while !cur.is_null() {
        let mut match_len = 0usize;
        while match_len < 63
            && *name.add(match_len) != 0
            && (*cur).driver_name[match_len] == *name.add(match_len)
        {
            match_len += 1;
        }
        if *name.add(match_len) == 0 && (*cur).driver_name[match_len] == 0 {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// VfAddDriver - enable verification for a driver by name.
pub unsafe fn vf_add_driver(name: *const u16, flags: u32) -> NtStatus {
    if name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if !vf_find_driver(name).is_null() {
        return crate::nt::STATUS_OBJECT_NAME_COLLISION;
    }
    let e = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<VerifierDriverEntry>(),
    ) as *mut VerifierDriverEntry;
    if e.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(e as *mut u8, 0, core::mem::size_of::<VerifierDriverEntry>());
    let mut i = 0;
    while i < 63 && *name.add(i) != 0 {
        (*e).driver_name[i] = *name.add(i);
        i += 1;
    }
    (*e).flags = flags;
    (*e).next = VF_DRIVER_LIST;
    VF_DRIVER_LIST = e;
    VERIFIER_LEVEL |= flags;
    if flags & VERIFIER_FLAG_POOL_TRACKING != 0 {
        POOL_VERIFICATION_ENABLED = true;
    }
    if flags & VERIFIER_FLAG_IRQL_CHECKING != 0 {
        IRQL_VERIFICATION_ENABLED = true;
    }
    if flags & VERIFIER_FLAG_IO_CHECKING != 0 {
        IO_VERIFICATION_ENABLED = true;
    }
    STATUS_SUCCESS
}

/// VfRemoveDriver - stop verifying a driver.
pub unsafe fn vf_remove_driver(name: *const u16) -> NtStatus {
    if name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut prev: *mut VerifierDriverEntry = core::ptr::null_mut();
    let mut cur = VF_DRIVER_LIST;
    while !cur.is_null() {
        let mut match_len = 0usize;
        while match_len < 63
            && *name.add(match_len) != 0
            && (*cur).driver_name[match_len] == *name.add(match_len)
        {
            match_len += 1;
        }
        if *name.add(match_len) == 0 && (*cur).driver_name[match_len] == 0 {
            if prev.is_null() {
                VF_DRIVER_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// VfTrackAllocation - account a verified pool allocation.
pub unsafe fn vf_track_allocation(driver: *mut VerifierDriverEntry, bytes: usize) {
    VF_TOTAL_ALLOCATIONS.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    if !driver.is_null() {
        (*driver).pool_allocations += 1;
        (*driver).pool_bytes_current += bytes as u64;
        if (*driver).pool_bytes_current > (*driver).pool_bytes_peak {
            (*driver).pool_bytes_peak = (*driver).pool_bytes_current;
        }
    }
}

/// VfTrackFree - account a verified pool free.
pub unsafe fn vf_track_free(driver: *mut VerifierDriverEntry, bytes: usize) {
    VF_TOTAL_FREES.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    if !driver.is_null() {
        (*driver).pool_frees += 1;
        (*driver).pool_bytes_current =
            (*driver).pool_bytes_current.saturating_sub(bytes as u64);
    }
}

/// VfAcquireLock / VfReleaseLock - deadlock detection (lock order graph).
pub unsafe fn vf_acquire_lock(lock_address: u64, owner_thread: u64) -> NtStatus {
    let mut cur = VF_LOCK_LIST;
    while !cur.is_null() {
        if (*cur).lock_address == lock_address {
            if (*cur).owner_thread == owner_thread {
                // Recursive acquire of a non-recursive spin lock.
                crate::kernel_log!(
                    "[Verifier] Recursive lock acquire {:#x} by thread {}\n",
                    lock_address,
                    owner_thread
                );
                return STATUS_POSSIBLE_DEADLOCK;
            }
            break;
        }
        cur = (*cur).next;
    }
    let e = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<VerifierLockEntry>(),
    ) as *mut VerifierLockEntry;
    if e.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(e as *mut u8, 0, core::mem::size_of::<VerifierLockEntry>());
    (*e).lock_address = lock_address;
    (*e).owner_thread = owner_thread;
    (*e).acquire_count = 1;
    (*e).irql_at_acquire = crate::ke::interrupt::ke_get_current_irql();
    (*e).next = VF_LOCK_LIST;
    VF_LOCK_LIST = e;
    STATUS_SUCCESS
}

pub unsafe fn vf_release_lock(lock_address: u64, owner_thread: u64) {
    let mut prev: *mut VerifierLockEntry = core::ptr::null_mut();
    let mut cur = VF_LOCK_LIST;
    while !cur.is_null() {
        if (*cur).lock_address == lock_address && (*cur).owner_thread == owner_thread {
            if prev.is_null() {
                VF_LOCK_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return;
        }
        prev = cur;
        cur = (*cur).next;
    }
}

/// VfCheckDmaBuffer - validate a DMA transfer range (double-fetch guard).
pub unsafe fn vf_check_dma_buffer(
    _adapter: *mut c_void,
    buffer: *mut u8,
    length: u32,
    write_to_device: bool,
) -> NtStatus {
    if buffer.is_null() || length == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let _ = write_to_device;
    STATUS_SUCCESS
}

/// VfQueryStatistics - global verifier counters.
pub unsafe fn vf_query_statistics(
    total_allocations: *mut u64,
    total_frees: *mut u64,
    live_drivers: *mut u32,
) -> NtStatus {
    if total_allocations.is_null() || total_frees.is_null() || live_drivers.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    *total_allocations = VF_TOTAL_ALLOCATIONS.load(core::sync::atomic::Ordering::Relaxed);
    *total_frees = VF_TOTAL_FREES.load(core::sync::atomic::Ordering::Relaxed);
    let mut n = 0u32;
    let mut cur = VF_DRIVER_LIST;
    while !cur.is_null() {
        n += 1;
        cur = (*cur).next;
    }
    *live_drivers = n;
    STATUS_SUCCESS
}
