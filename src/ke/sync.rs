/// KiSync - Windows 10 Kernel Synchronization Primitives
///
/// Events, mutants, semaphores, spin locks, guarded mutexes,
/// push locks, and fast mutexes matching ntoskrnl.exe behavior.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 5/8
///   - WRK: ntoskrnl/ke/event.c, mutex.c, sema.c, spinlock.c
///   - ReactOS: ke/event.c, ke/mutex.c, ke/sema.c, ke/spinlock.c

use core::arch::asm;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicUsize, Ordering};

use crate::types::*;
use super::dispatcher::*;

// ============================================================
// Synchronization Constants
// ============================================================

/// Maximum number of waiters on a guarded mutex
pub const MAXIMUM_WAITERS: i32 = 0x7FFFFFFF;

/// Guarded mutex owner bit (in the value field)
pub const GUARDED_MUTEX_LOCKED: u64 = 1;
pub const GUARDED_MUTEX_OWNER_BIT: u64 = 2;
pub const GUARDED_MUTEX_NESTED_MASK: u64 = 0xFFFFFFFFFFFFFFFC;

/// Fast mutex owner thread field
pub const FAST_MUTEX_OWNER: u64 = 1;

// ============================================================
// KEVENT - Synchronization/Notification Event
// ============================================================

/// Initialize an event object.
///
/// # Arguments
/// * `event` - Pointer to the Kevent to initialize
/// * `event_type` - SYNCHRONIZATION_EVENT (0) or NOTIFICATION_EVENT (1)
/// * `initial_state` - 0 = non-signaled, non-zero = signaled
pub unsafe fn ke_initialize_event(event: *mut Kevent, event_type: u8, initial_state: i32) {
    if event.is_null() {
        return;
    }

    let e = &mut *event;
    e.header.initialize(DISPATCHER_OBJECT_TYPE_EVENT);
    e.header.r#type = event_type;
    e.header.signal_state = initial_state;
}

/// Reset (clear) an event to the non-signaled state.
///
/// # Arguments
/// * `event` - Pointer to the event
/// * `previous_state` - Optional out pointer to receive the previous signal state
///
/// # Returns
/// STATUS_SUCCESS
pub unsafe fn ke_reset_event(event: *mut Kevent, previous_state: *mut i32) -> NtStatus {
    if event.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let e = &mut *event;

    ki_acquire_dispatcher_lock();

    if !previous_state.is_null() {
        *previous_state = e.header.signal_state;
    }
    e.header.signal_state = 0;

    ki_release_dispatcher_lock();

    STATUS_SUCCESS
}

/// Clear an event (alias for KeResetEvent).
///
/// # Arguments
/// * `event` - Pointer to the event
///
/// # Returns
/// Previous signal state
pub unsafe fn ke_clear_event(event: *mut Kevent) -> i32 {
    if event.is_null() {
        return 0;
    }

    let e = &mut *event;

    ki_acquire_dispatcher_lock();
    let previous = e.header.signal_state;
    e.header.signal_state = 0;
    ki_release_dispatcher_lock();

    previous
}

/// Set an event to the signaled state and optionally wake waiting threads.
///
/// # Arguments
/// * `event` - Pointer to the event
/// * `increment` - Amount to increment signal state by (typically 1)
/// * `wait` - If non-zero, the caller waits for a waiter to be satisfied
///
/// # Returns
/// STATUS_SUCCESS
pub unsafe fn ke_set_event(event: *mut Kevent, increment: i32, wait: Boolean) -> NtStatus {
    if event.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let e = &mut *event;

    ki_acquire_dispatcher_lock();

    if e.header.r#type != DISPATCHER_OBJECT_TYPE_EVENT {
        ki_release_dispatcher_lock();
        return STATUS_OBJECT_TYPE_MISMATCH;
    }

    e.header.signal_state += increment;

    // Wake waiting threads
    if e.header.signal_state > 0 && !e.header.wait_list_entry.is_empty() {
        let mut current_entry = e.header.wait_list_entry.flink;
        let list_head = &e.header.wait_list_entry as *const ListEntry as *mut ListEntry;

        while current_entry != list_head {
            let wb = wait_block_from_wait_list_entry(current_entry);
            let next = (*current_entry).flink;
            let wait_type = (*wb).wait_type;

            ListEntry::remove_entry(&mut (*wb).wait_list_entry);

            let waiting_thread = (*wb).thread;
            if !waiting_thread.is_null() {
                (*waiting_thread).wait_block_list = core::ptr::null_mut();
                (*waiting_thread).wait_status = STATUS_SUCCESS;
                ki_ready_thread(waiting_thread);
            }

            // For WaitAny, wake only the first thread
            if wait_type == WAIT_TYPE_ANY {
                break;
            }

            current_entry = next;
        }
    }

    ki_release_dispatcher_lock();

    STATUS_SUCCESS
}

/// Read the current signal state of an event.
pub unsafe fn ke_read_state_event(event: *mut Kevent) -> i32 {
    if event.is_null() { 0 } else { (*event).header.signal_state }
}

/// Check if an event is signaled.
pub unsafe fn ke_query_event(event: *mut Kevent) -> bool {
    if event.is_null() { false } else { (*event).header.is_signaled() }
}

// ============================================================
// KMUTANT - Executive Mutant (Mutex)
// ============================================================

/// Initialize a mutant (mutex) object.
///
/// # Arguments
/// * `mutant` - Pointer to the Kmutant to initialize
/// * `initial_owner` - If TRUE, the calling thread immediately owns the mutant
pub unsafe fn ke_initialize_mutant(mutant: *mut Kmutant, initial_owner: Boolean) {
    if mutant.is_null() {
        return;
    }

    let m = &mut *mutant;
    m.header.initialize(DISPATCHER_OBJECT_TYPE_MUTANT);
    m.mutant_list_entry.initialize();
    m.owner_thread = core::ptr::null_mut();
    m.apc_disable_count = 0;
    m.abandoned = 0;
    m.apc_disable = 0;

    if initial_owner != 0 {
        let current_thread = ke_get_current_thread();
        if !current_thread.is_null() {
            m.owner_thread = current_thread;
            m.header.signal_state = 0;
            m.header.inserted = DISPATCHER_OBJECT_INSERTED;
        } else {
            m.header.signal_state = 1;
        }
    } else {
        m.header.signal_state = 1;
    }
}

/// Release a mutant (mutex). Decrements the signal state and
/// transfers ownership to the next waiter if any.
///
/// # Arguments
/// * `mutant` - Pointer to the Kmutant
/// * `increment` - Amount to increment signal state (typically 1)
/// * `abandoned` - If TRUE, the mutant is marked as abandoned
/// * `wait` - If non-zero, wait for a waiter to be satisfied
///
/// # Returns
/// STATUS_SUCCESS, STATUS_ABANDONED, or error
pub unsafe fn ke_release_mutant(
    mutant: *mut Kmutant,
    increment: i32,
    abandoned: Boolean,
    wait: Boolean,
) -> NtStatus {
    if mutant.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let m = &mut *mutant;
    let mut status = STATUS_SUCCESS;

    ki_acquire_dispatcher_lock();

    if m.header.r#type != DISPATCHER_OBJECT_TYPE_MUTANT {
        ki_release_dispatcher_lock();
        return STATUS_OBJECT_TYPE_MISMATCH;
    }

    if m.owner_thread.is_null() {
        ki_release_dispatcher_lock();
        return STATUS_MUTANT_NOT_OWNED;
    }

    let current_thread = ke_get_current_thread();
    if m.owner_thread != current_thread {
        ki_release_dispatcher_lock();
        return STATUS_MUTANT_NOT_OWNED;
    }

    m.apc_disable_count -= 1;

    if abandoned != 0 {
        m.header.signal_state = 1;
        m.abandoned = 1;
        status = STATUS_ABANDONED;
    } else {
        m.header.signal_state += increment;
    }

    // If mutant is now signaled, release ownership and wake a waiter
    if m.header.signal_state > 0 {
        m.owner_thread = core::ptr::null_mut();
        m.header.inserted = DISPATCHER_OBJECT_NOT_INSERTED;

        if !m.header.wait_list_entry.is_empty() {
            let wait_entry = m.header.wait_list_entry.flink;
            let wb = wait_block_from_wait_list_entry(wait_entry);

            ListEntry::remove_entry(&mut (*wb).wait_list_entry);

            let waiting_thread = (*wb).thread;
            if !waiting_thread.is_null() {
                (*waiting_thread).wait_block_list = core::ptr::null_mut();
                (*waiting_thread).wait_status = STATUS_SUCCESS;

                // Transfer ownership
                m.owner_thread = waiting_thread;
                m.header.signal_state -= 1;
                m.header.inserted = DISPATCHER_OBJECT_INSERTED;
                m.abandoned = 0;

                ki_ready_thread(waiting_thread);
            }
        }
    }

    ki_release_dispatcher_lock();

    status
}

/// Read the signal state of a mutant.
pub unsafe fn ke_read_state_mutant(mutant: *mut Kmutant) -> i32 {
    if mutant.is_null() { 0 } else { (*mutant).header.signal_state }
}

/// Query if the current thread owns the mutant.
pub unsafe fn ke_query_mutant(mutant: *mut Kmutant) -> i32 {
    if mutant.is_null() { 0 } else {
        if (*mutant).owner_thread == ke_get_current_thread() { 1 } else { 0 }
    }
}

/// Get the owner thread of a mutant.
pub unsafe fn ke_query_owner_mutant(mutant: *mut Kmutant) -> *mut Kthread {
    if mutant.is_null() { core::ptr::null_mut() } else { (*mutant).owner_thread }
}

// ============================================================
// KSEMAPHORE - Executive Semaphore
// ============================================================

/// Initialize a semaphore object.
///
/// # Arguments
/// * `semaphore` - Pointer to the Ksemaphore to initialize
/// * `count` - Initial count (number of available permits)
/// * `limit` - Maximum count (maximum permits)
pub unsafe fn ke_initialize_semaphore(semaphore: *mut Ksemaphore, count: i32, limit: i32) {
    if semaphore.is_null() {
        return;
    }

    let s = &mut *semaphore;
    s.header.initialize(DISPATCHER_OBJECT_TYPE_SEMAPHORE);
    s.header.signal_state = count;
    s.limit = limit;
}

/// Release a semaphore, incrementing its count and optionally
/// waking a waiting thread.
///
/// # Arguments
/// * `semaphore` - Pointer to the Ksemaphore
/// * `increment` - Amount to increment the count by (typically 1)
/// * `adjust_limit` - If non-zero, the limit is adjusted to this value
/// * `wait` - If non-zero, wait for a waiter to be satisfied
///
/// # Returns
/// STATUS_SUCCESS or STATUS_SEMAPHORE_LIMIT_EXCEEDED
pub unsafe fn ke_release_semaphore(
    semaphore: *mut Ksemaphore,
    increment: i32,
    adjustment_limit: i32,
    wait: Boolean,
) -> NtStatus {
    if semaphore.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let s = &mut *semaphore;

    ki_acquire_dispatcher_lock();

    if s.header.r#type != DISPATCHER_OBJECT_TYPE_SEMAPHORE {
        ki_release_dispatcher_lock();
        return STATUS_OBJECT_TYPE_MISMATCH;
    }

    let new_count = s.header.signal_state + increment;
    if new_count > adjustment_limit {
        s.header.signal_state = adjustment_limit;
        ki_release_dispatcher_lock();
        return STATUS_SEMAPHORE_LIMIT_EXCEEDED;
    }

    s.header.signal_state = new_count;

    // Wake a waiting thread if available
    if !s.header.wait_list_entry.is_empty() && s.header.signal_state > 0 {
        let wait_entry = s.header.wait_list_entry.flink;
        let wb = wait_block_from_wait_list_entry(wait_entry);

        ListEntry::remove_entry(&mut (*wb).wait_list_entry);

        let waiting_thread = (*wb).thread;
        if !waiting_thread.is_null() {
            (*waiting_thread).wait_block_list = core::ptr::null_mut();
            (*waiting_thread).wait_status = STATUS_SUCCESS;
            ki_ready_thread(waiting_thread);
        }

        s.header.signal_state -= 1;
    }

    ki_release_dispatcher_lock();

    STATUS_SUCCESS
}

/// Read the current count of a semaphore.
pub unsafe fn ke_read_state_semaphore(semaphore: *mut Ksemaphore) -> i32 {
    if semaphore.is_null() { 0 } else { (*semaphore).header.signal_state }
}

// ============================================================
// KSPIN_LOCK - Spin Lock
// ============================================================

/// Initialize a spin lock to the free state (0).
///
/// # Arguments
/// * `lock` - Pointer to the KspinLock
pub unsafe fn ke_initialize_spin_lock(lock: *mut KspinLock) {
    if !lock.is_null() {
        *lock = 0;
    }
}

/// Acquire a spin lock at PASSIVE_LEVEL. The IRQL is not raised;
/// the caller must ensure proper synchronization.
///
/// # Arguments
/// * `lock` - Pointer to the KspinLock
pub unsafe fn ke_acquire_spin_lock(lock: *mut KspinLock) {
    if lock.is_null() {
        return;
    }
    acquire_spin_lock(&mut *lock);
}

/// Release a spin lock at PASSIVE_LEVEL.
///
/// # Arguments
/// * `lock` - Pointer to the KspinLock
pub unsafe fn ke_release_spin_lock(lock: *mut KspinLock) {
    if lock.is_null() {
        return;
    }
    release_spin_lock(&mut *lock);
}

/// Acquire a spin lock and raise IRQL to DISPATCH_LEVEL.
/// Returns the previous IRQL.
///
/// # Arguments
/// * `lock` - Pointer to the KspinLock
///
/// # Returns
/// Previous IRQL
pub unsafe fn ke_acquire_spin_lock_raise_to_dpc(lock: *mut KspinLock) -> Irql {
    if lock.is_null() {
        return ke_get_current_irql();
    }

    let old_irql = ke_raise_irql(DISPATCH_LEVEL);
    acquire_spin_lock(&mut *lock);
    old_irql
}

/// Release a spin lock and lower IRQL to the previous level.
///
/// # Arguments
/// * `lock` - Pointer to the KspinLock
/// * `old_irql` - Previous IRQL to restore
pub unsafe fn ke_release_spin_lock_lower_irql(lock: *mut KspinLock, old_irql: Irql) {
    if lock.is_null() {
        ke_lower_irql(old_irql);
        return;
    }

    release_spin_lock(&mut *lock);
    ke_lower_irql(old_irql);
}

/// Try to acquire a spin lock without blocking. If the lock is
/// free, it is acquired and IRQL is raised to DISPATCH_LEVEL.
///
/// # Arguments
/// * `lock` - Pointer to the KspinLock
///
/// # Returns
/// Previous IRQL if acquired, DISPATCH_LEVEL if not acquired
pub unsafe fn ke_try_to_acquire_spin_lock_raise_to_dpc(lock: *mut KspinLock) -> Irql {
    if lock.is_null() {
        return DISPATCH_LEVEL;
    }

    let old_irql = ke_get_current_irql();

    // Try atomic compare-and-swap
    let val = core::ptr::read_volatile(lock as *const KspinLock);
    if val == 0 {
        core::ptr::write_volatile(lock as *mut KspinLock, 1);
        ke_raise_irql(DISPATCH_LEVEL);
        old_irql
    } else {
        DISPATCH_LEVEL // Failed to acquire
    }
}

// ============================================================
// KGUARDED_MUTEX - Guarded Mutex
// ============================================================

/// Guarded mutex structure. Uses a single u64 for the lock state
/// with owner tracking and a wait event for contention.
#[repr(C)]
pub struct KguardedMutex {
    /// Lock state: bit 0 = locked, bit 1 = owner bit, bits 2+ = wait count
    pub value: AtomicUsize,
    /// Spin count before blocking
    pub spin_count: u64,
    /// Event used to wait when contended
    pub event: Kevent,
    /// Old/previous safe keep
    pub old_irql: Irql,
}

unsafe impl Send for KguardedMutex {}
unsafe impl Sync for KguardedMutex {}

impl KguardedMutex {
    pub const fn uninitialized() -> Self {
        Self {
            value: AtomicUsize::new(0),
            spin_count: 0,
            event: Kevent {
                header: DispatcherHeader {
                    r#type: 0,
                    absolute: 0,
                    size: 0,
                    inserted: 0,
                    signal_state: 0,
                    wait_list_entry: ListEntry {
                        flink: core::ptr::null_mut(),
                        blink: core::ptr::null_mut(),
                    },
                },
            },
            old_irql: 0,
        }
    }
}

/// Initialize a guarded mutex.
///
/// # Arguments
/// * `mutex` - Pointer to the KguardedMutex to initialize
/// * `level` - Lock level (unused in this implementation)
/// * `string` - Debug string (unused in this implementation)
/// * `count` - Spin count before blocking
pub unsafe fn ke_initialize_guarded_mutex(
    mutex: *mut KguardedMutex,
    level: u32,
    _string: &str,
    count: u64,
) {
    if mutex.is_null() {
        return;
    }

    let m = &mut *mutex;
    m.value.store(0, Ordering::Relaxed);
    m.spin_count = count;
    m.old_irql = PASSIVE_LEVEL;

    ke_initialize_event(&mut m.event, SYNCHRONIZATION_EVENT, 0);
}

/// Acquire a guarded mutex. Spins for up to spin_count iterations
/// before blocking on the event.
///
/// # Arguments
/// * `mutex` - Pointer to the KguardedMutex
pub unsafe fn ke_acquire_guarded_mutex(mutex: *mut KguardedMutex) {
    if mutex.is_null() {
        return;
    }

    let m = &mut *mutex;
    let current_thread = ke_get_current_thread();
    let mut spin_count = m.spin_count;

    loop {
        // Try to acquire: CAS 0 -> 1
        let old = m.value.compare_exchange(
            0,
            GUARDED_MUTEX_LOCKED as usize,
            Ordering::Acquire,
            Ordering::Relaxed,
        );

        if old.is_ok() {
            // Acquired the mutex
            if !current_thread.is_null() {
                (*current_thread).wait_irql = ke_get_current_irql();
            }
            return;
        }

        // Spin if we haven't exhausted our spin count
        if spin_count > 0 {
            spin_count -= 1;
            asm!("pause", options(nostack, nomem));
            continue;
        }

        // Block on the event
        m.value.fetch_add(2, Ordering::AcqRel);

        // Wait for the event to be signaled
        let timeout: i64 = -1; // Infinite timeout
        ke_wait_for_single_object(
            &mut m.event.header as *mut DispatcherHeader,
            KwaitReason::Executive,
            0,
            0,
            &timeout as *const i64 as *mut i64,
        );

        spin_count = m.spin_count;
    }
}

/// Release a guarded mutex and wake a waiter if any.
///
/// # Arguments
/// * `mutex` - Pointer to the KguardedMutex
pub unsafe fn ke_release_guarded_mutex(mutex: *mut KguardedMutex) {
    if mutex.is_null() {
        return;
    }

    let m = &mut *mutex;

    // Release the lock
    m.value.store(0, Ordering::Release);

    // Check for waiters and wake one if present
    let current_val = m.value.load(Ordering::Acquire);
    if current_val & !0x3 != 0 {
        // There are waiters
        ke_set_event(&mut m.event, 1, 0);
    }
}

/// Try to acquire a guarded mutex without blocking.
///
/// # Arguments
/// * `mutex` - Pointer to the KguardedMutex
///
/// # Returns
/// `true` if acquired, `false` if contended
pub unsafe fn ke_try_to_acquire_guarded_mutex(mutex: *mut KguardedMutex) -> bool {
    if mutex.is_null() {
        return false;
    }

    let m = &mut *mutex;
    m.value.compare_exchange(
        0,
        GUARDED_MUTEX_LOCKED as usize,
        Ordering::Acquire,
        Ordering::Relaxed,
    )
    .is_ok()
}

// ============================================================
// PUSHPUSH_LOCK - Push Lock
// ============================================================

/// Push lock structure. Provides exclusive and shared access
/// with a waiting mechanism for contention.
#[repr(C)]
pub struct KpushLock {
    /// Lock value: 0 = free, bit 0 = exclusive lock,
    /// bits 1+ = shared count
    pub value: u64,
    /// Event used to wait when contended
    pub event: Kevent,
}

unsafe impl Send for KpushLock {}
unsafe impl Sync for KpushLock {}

/// Initialize a push lock.
///
/// # Arguments
/// * `push_lock` - Pointer to the KpushLock to initialize
pub unsafe fn ke_initialize_push_lock(push_lock: *mut KpushLock) {
    if push_lock.is_null() {
        return;
    }

    let p = &mut *push_lock;
    p.value = 0;
    ke_initialize_event(&mut p.event, SYNCHRONIZATION_EVENT, 0);
}

/// Acquire a push lock exclusively. Blocks until the lock is acquired.
///
/// # Arguments
/// * `push_lock` - Pointer to the KpushLock
pub unsafe fn ke_acquire_push_lock_exclusive(push_lock: *mut KpushLock) {
    if push_lock.is_null() {
        return;
    }

    let p = &mut *push_lock;

    loop {
        let old = p.value;
        if old == 0 {
            // Free: try exclusive acquire
            if core::ptr::read_volatile(&p.value) == 0 {
                core::ptr::write_volatile(&mut p.value, 1);
                return;
            }
        }

        // Lock held; spin then block
        for _ in 0..64 {
            asm!("pause", options(nostack, nomem));
        }

        let timeout: i64 = -1;
        ke_wait_for_single_object(
            &mut p.event.header as *mut DispatcherHeader,
            KwaitReason::WrPushLock,
            0,
            0,
            &timeout as *const i64 as *mut i64,
        );
    }
}

/// Release an exclusive push lock.
///
/// # Arguments
/// * `push_lock` - Pointer to the KpushLock
pub unsafe fn ke_release_push_lock_exclusive(push_lock: *mut KpushLock) {
    if push_lock.is_null() {
        return;
    }

    let p = &mut *push_lock;
    core::ptr::write_volatile(&mut p.value, 0);
    ke_set_event(&mut p.event, 1, 0);
}

/// Acquire a push lock in shared mode.
///
/// # Arguments
/// * `push_lock` - Pointer to the KpushLock
pub unsafe fn ke_acquire_push_lock_shared(push_lock: *mut KpushLock) {
    if push_lock.is_null() {
        return;
    }

    let p = &mut *push_lock;

    loop {
        let old = p.value;
        if old & 1 == 0 && old < MAXIMUM_WAITERS as u64 * 2 {
            // Not exclusively locked; try to increment shared count
            let new_val = old + 2;
            if core::ptr::read_volatile(&p.value) == old {
                core::ptr::write_volatile(&mut p.value, new_val);
                return;
            }
        }

        // Lock held exclusively or too many shared; spin then block
        for _ in 0..64 {
            asm!("pause", options(nostack, nomem));
        }

        let timeout: i64 = -1;
        ke_wait_for_single_object(
            &mut p.event.header as *mut DispatcherHeader,
            KwaitReason::WrPushLock,
            0,
            0,
            &timeout as *const i64 as *mut i64,
        );
    }
}

/// Release a shared push lock.
///
/// # Arguments
/// * `push_lock` - Pointer to the KpushLock
pub unsafe fn ke_release_push_lock_shared(push_lock: *mut KpushLock) {
    if push_lock.is_null() {
        return;
    }

    let p = &mut *push_lock;
    let old = p.value;
    let new_val = old.saturating_sub(2);
    core::ptr::write_volatile(&mut p.value, new_val);

    if new_val & 1 == 0 && new_val <= 2 {
        ke_set_event(&mut p.event, 1, 0);
    }
}

// ============================================================
// KFAST_MUTEX - Fast Mutex
// ============================================================

/// Fast mutex structure. Uses a simple counter with ownership tracking.
#[repr(C)]
pub struct KfastMutex {
    /// Lock state
    pub count: i64,
    /// Owner thread
    pub owner: *mut Kthread,
    /// Abandoned flag
    pub abandoned: u8,
    pub pad: [u8; 7],
}

unsafe impl Send for KfastMutex {}
unsafe impl Sync for KfastMutex {}

/// Initialize a fast mutex.
///
/// # Arguments
/// * `mutex` - Pointer to the KfastMutex to initialize
pub unsafe fn ke_initialize_fast_mutex(mutex: *mut KfastMutex) {
    if mutex.is_null() {
        return;
    }

    let m = &mut *mutex;
    m.count = 1; // 1 = free, 0 = owned
    m.owner = core::ptr::null_mut();
    m.abandoned = 0;
}

/// Acquire a fast mutex unsafely (without raising IRQL).
/// The caller must ensure proper synchronization.
///
/// # Arguments
/// * `mutex` - Pointer to the KfastMutex
pub unsafe fn ke_acquire_fast_mutex_unsafe(mutex: *mut KfastMutex) {
    if mutex.is_null() {
        return;
    }

    let m = &mut *mutex;

    // Spin while mutex is owned
    while m.count != 1 {
        asm!("pause", options(nostack, nomem));
    }

    // Acquire: decrement count to 0
    m.count = 0;
    m.owner = ke_get_current_thread();
}

/// Release a fast mutex unsafely.
///
/// # Arguments
/// * `mutex` - Pointer to the KfastMutex
pub unsafe fn ke_release_fast_mutex_unsafe(mutex: *mut KfastMutex) {
    if mutex.is_null() {
        return;
    }

    let m = &mut *mutex;
    m.owner = core::ptr::null_mut();
    m.count = 1; // Set to free
}

/// Try to acquire a fast mutex without blocking.
///
/// # Arguments
/// * `mutex` - Pointer to the KfastMutex
///
/// # Returns
/// `true` if acquired, `false` if contended
pub unsafe fn ke_try_to_acquire_fast_mutex(mutex: *mut KfastMutex) -> bool {
    if mutex.is_null() {
        return false;
    }

    let m = &mut *mutex;
    if m.count == 1 {
        m.count = 0;
        m.owner = ke_get_current_thread();
        true
    } else {
        false
    }
}

/// Get the owner thread of a fast mutex.
pub unsafe fn ke_query_fast_mutex_owner(mutex: *mut KfastMutex) -> *mut Kthread {
    if mutex.is_null() { core::ptr::null_mut() } else { (*mutex).owner }
}

// ============================================================
// Dispatcher Lock Helpers (used by sync primitives)
// ============================================================

/// Acquire the global dispatcher lock (at DISPATCH_LEVEL).
#[inline]
pub unsafe fn ki_acquire_dispatcher_lock() {
    let lock_ptr = &KI_DISPATCHER_LOCK as *const AtomicUsize as *mut u64;
    asm!(
        "1:",
        "lock bts qword ptr [{0}], 0",
        "jc 2f",
        "jmp 3f",
        "2:",
        "pause",
        "test qword ptr [{0}], 1",
        "jnz 2b",
        "jmp 1b",
        "3:",
        in(reg) lock_ptr,
        options(nostack, nomem),
    );
}

/// Release the global dispatcher lock.
#[inline]
pub unsafe fn ki_release_dispatcher_lock() {
    let lock_ptr = &KI_DISPATCHER_LOCK as *const AtomicUsize as *mut u64;
    asm!(
        "lock btr qword ptr [{0}], 0",
        in(reg) lock_ptr,
        options(nostack, nomem),
    );
}

/// Wait block recovery from wait_list_entry (inline helper).
#[inline]
unsafe fn wait_block_from_wait_list_entry(entry: *mut ListEntry) -> *mut KwaitBlock {
    let dummy: KwaitBlock = mem::zeroed();
    let base = &dummy as *const KwaitBlock as usize;
    let field = &dummy.wait_list_entry as *const ListEntry as usize;
    let offset = field - base;
    mem::forget(dummy);
    (entry as usize - offset) as *mut KwaitBlock
}

/// Ready a thread for execution.
#[inline]
pub unsafe fn ki_ready_thread(thread: *mut Kthread) {
    if thread.is_null() {
        return;
    }

    let t = &mut *thread;
    let processor = t.processor as usize;

    if processor >= 64 {
        return;
    }

    t.state = KthreadState::Ready;

    let prcb = &mut KI_PRCB[processor];
    let priority = t.priority as usize;
    if priority < MAXIMUM_PRIORITY_LEVEL {
        let ready_list = &mut prcb.dispatcher_ready_list_head[priority];
        ready_list.insert_tail(&mut t.wait_list_entry);
        prcb.ready_summary |= 1 << priority;
        KI_READY_SUMMARY.fetch_or(1 << priority, Ordering::Release);
    }
}

/// Get the current IRQL.
#[inline]
pub unsafe fn ke_get_current_irql() -> Irql {
    KI_PRCB[0].current_irql
}

/// Raise IRQL to the specified level.
#[inline]
pub unsafe fn ke_raise_irql(new_irql: Irql) -> Irql {
    let old = KI_PRCB[0].current_irql;
    KI_PRCB[0].current_irql = new_irql;
    old
}

/// Lower IRQL to the specified level.
#[inline]
pub unsafe fn ke_lower_irql(new_irql: Irql) {
    KI_PRCB[0].current_irql = new_irql;
}
