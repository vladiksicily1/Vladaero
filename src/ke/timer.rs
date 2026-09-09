/// KiTimer - Windows 10 Kernel Timer Implementation
///
/// High-resolution timer management, timer table scanning, DPC-based
/// timer expiration, and system time queries matching ntoskrnl.exe behavior.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 8
///   - WRK: ntoskrnl/ke/timerobj.c, ki/timer.c
///   - ReactOS: ke/timerobj.c, ke/timer.c

use core::arch::asm;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicUsize, Ordering};

use crate::types::*;
use super::dispatcher::*;
use super::dpc;
use super::dpc::{Kdpc, DpcType};

// ============================================================
// Timer Constants
// ============================================================

/// Number of timer table entries (one per time slice bucket).
pub const TIMER_TABLE_SIZE: usize = 256;

/// Timer table entry ranges: each entry covers a different
/// expiration range. Entry 0 = nearest expiration.
pub const TIMER_ENTRY_EXPIRATION_BUCKETS: usize = 4;

/// Timer object state flags
pub const TIMER_INSERTED: u32 = 0x01;
pub const TIMER_PROCESSING: u32 = 0x02;
pub const TIMER_RUNNING: u32 = 0x04;
pub const TIMER_DISABLED: u32 = 0x08;

/// Tick interval: 15.625 ms (100 Hz) on Windows 10
pub const TIME_TOO_LARGE: i64 = 0x7FFFFFFFFFFFFFFF;
pub const TIMER_STOP: u64 = 0;

// ============================================================
// KTIMER - Kernel Timer Object
// ============================================================

/// Kernel timer object. When the timer fires, the DPC routine is
/// queued at DISPATCH_LEVEL. Timers can be absolute or relative.
#[repr(C)]
pub struct Ktimer {
    /// Dispatcher header (type = DISPATCHER_OBJECT_TYPE_TIMER)
    pub header: DispatcherHeader,
    /// Due time in 100ns units (negative = relative)
    pub due_time: i64,
    /// Period in milliseconds (0 = one-shot)
    pub period: i32,
    /// Entry in the global timer list for this processor
    pub timer_list_entry: ListEntry,
    /// DPC to fire when the timer expires
    pub dpc: *mut Kdpc,
    /// Index into KiTimerTable
    pub timer_table_entry_index: u32,
    /// Time at which the timer was last processed
    pub processing_time: u64,
    /// CPU that this timer is currently being processed on
    pub enqueue_cpu: u32,
    /// Padding / flags
    pub flags: u32,
}

unsafe impl Send for Ktimer {}
unsafe impl Sync for Ktimer {}

impl Ktimer {
    pub fn uninitialized() -> Self {
        Self {
            header: unsafe { mem::zeroed() },
            due_time: 0,
            period: 0,
            timer_list_entry: ListEntry::uninitialized(),
            dpc: core::ptr::null_mut(),
            timer_table_entry_index: 0,
            processing_time: 0,
            enqueue_cpu: 0,
            flags: 0,
        }
    }
}

// ============================================================
// KTIMER_TABLE_ENTRY - Timer Table Entry
// ============================================================

/// Each entry in the timer table holds a linked list of timers
/// sorted by expiration time and the earliest expiration for
/// that bucket.
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KtimerTableEntry {
    /// Head of the timer list for this bucket
    pub entry: ListEntry,
    /// Time of the first timer in the list
    pub time: u64,
}

unsafe impl Send for KtimerTableEntry {}
unsafe impl Sync for KtimerTableEntry {}

impl KtimerTableEntry {
    pub const fn uninitialized() -> Self {
        Self {
            entry: ListEntry {
                flink: core::ptr::null_mut(),
                blink: core::ptr::null_mut(),
            },
            time: TIME_TOO_LARGE as u64,
        }
    }
}

// ============================================================
// KiTimerTable - Global Timer Table
// ============================================================

/// Per-processor timer table with TIMER_TABLE_SIZE entries.
/// Each entry covers a time slice and holds timers sorted by
/// expiration within that bucket.
#[repr(C)]
pub struct KiTimerTable {
    /// Array of timer table entries
    pub entries: [KtimerTableEntry; TIMER_TABLE_SIZE],
    /// Total number of armed timers
    pub timer_count: u32,
}

unsafe impl Send for KiTimerTable {}
unsafe impl Sync for KiTimerTable {}

/// Global timer table. In ntoskrnl this is per-processor;
/// we use a single instance for simplicity.
static mut KI_TIMER_TABLE: KiTimerTable = KiTimerTable {
    entries: [KtimerTableEntry::uninitialized(); TIMER_TABLE_SIZE],
    timer_count: 0,
};

/// Timer table lock (per-processor in real ntoskrnl)
static KI_TIMER_TABLE_LOCK: AtomicUsize = AtomicUsize::new(0);

/// System time in 100ns units since January 1, 1601
static mut KI_SYSTEM_TIME: u64 = 0;

/// Interrupt time (number of timer ticks since boot)
static mut KI_INTERRUPT_TIME: u64 = 0;

/// Performance counter value
static mut KI_PERFORMANCE_COUNTER: u64 = 0;

/// Performance frequency (ticks per second)
static mut KI_PERFORMANCE_FREQUENCY: u64 = 10_000_000; // 100ns units

/// Timer expiry list for DPC callbacks
static mut KI_TIMER_EXPIRY_LIST: ListEntry = ListEntry {
    flink: core::ptr::null_mut(),
    blink: core::ptr::null_mut(),
};

/// Timer DPC objects (pre-allocated for each timer slot)
static mut KI_TIMER_DPCS: [Kdpc; TIMER_TABLE_SIZE] = unsafe { mem::zeroed() };

/// Timer thread pending flag
static KI_TIMER_THREAD_PENDING: AtomicBool = AtomicBool::new(false);

/// Timer fires count for profiling
static mut KI_TIMER_FIRES_COUNT: u64 = 0;

// ============================================================
// Timer Table Lock
// ============================================================

#[inline]
unsafe fn ki_acquire_timer_table_lock() {
    let lock_ptr = &KI_TIMER_TABLE_LOCK as *const AtomicUsize as *mut u64;
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

#[inline]
unsafe fn ki_release_timer_table_lock() {
    let lock_ptr = &KI_TIMER_TABLE_LOCK as *const AtomicUsize as *mut u64;
    asm!(
        "lock btr qword ptr [{0}], 0",
        in(reg) lock_ptr,
        options(nostack, nomem),
    );
}

// ============================================================
// KeInitializeTimer / KeInitializeTimerEx
// ============================================================

/// Initialize a timer object. The timer is created in the non-signaled
/// state with no DPC and a one-shot (period=0) configuration.
///
/// # Arguments
/// * `timer` - Pointer to the Ktimer to initialize
pub unsafe fn ke_initialize_timer(timer: *mut Ktimer) {
    ke_initialize_timer_ex(timer, KtimerMode::RelativeTimer);
}

/// Initialize a timer object with the specified timer type.
///
/// # Arguments
/// * `timer` - Pointer to the Ktimer to initialize
/// * `type_` - AbsoluteTimer, RelativeTimer, or TimerSubscription
pub unsafe fn ke_initialize_timer_ex(timer: *mut Ktimer, type_: KtimerMode) {
    if timer.is_null() {
        return;
    }

    let t = &mut *timer;
    t.header.initialize(DISPATCHER_OBJECT_TYPE_TIMER);
    t.header.absolute = type_ as u8;
    t.due_time = 0;
    t.period = 0;
    t.timer_list_entry.initialize();
    t.dpc = core::ptr::null_mut();
    t.timer_table_entry_index = 0;
    t.processing_time = 0;
    t.enqueue_cpu = 0;
    t.flags = 0;
}

// ============================================================
// KeSetTimer / KeSetTimerEx
// ============================================================

/// Arm a timer to fire at the specified due time. If the timer is
/// already armed, it is disarmed first. If a DPC is provided, it
/// will be queued when the timer fires.
///
/// # Arguments
/// * `timer` - Pointer to the Ktimer
/// * `due_time` - Due time in 100ns units (negative = relative to current time)
/// * `period` - Timer period in milliseconds (0 = one-shot)
/// * `dpc` - Optional DPC to queue on expiration
///
/// # Returns
/// `true` if the timer was previously armed, `false` otherwise
pub unsafe fn ke_set_timer_ex(
    timer: *mut Ktimer,
    due_time: i64,
    period: i32,
    dpc: *mut Kdpc,
) -> bool {
    if timer.is_null() {
        return false;
    }

    let t = &mut *timer;
    let was_armed = t.header.inserted != 0;

    ki_acquire_timer_table_lock();

    // If already inserted, remove it first
    if was_armed {
        ki_remove_timer_from_table(t);
    }

    // Compute absolute due time
    let abs_time = if due_time < 0 {
        // Relative time: add to current system time
        let current = ki_query_system_time_internal();
        current + (due_time.unsigned_abs() as u64)
    } else {
        // Absolute time
        due_time as u64
    };

    t.due_time = due_time;
    t.period = period;
    t.dpc = dpc;
    t.processing_time = 0;
    t.enqueue_cpu = ke_get_current_processor_number();

    // Compute which timer table bucket this timer belongs to
    let table_index = ki_compute_timer_table_index(abs_time);
    t.timer_table_entry_index = table_index;

    // Insert into the timer table
    ki_insert_timer_into_table(t, abs_time, table_index);

    // Mark as inserted
    t.header.inserted = 1;

    // Request a timer check
    KI_TIMER_THREAD_PENDING.store(true, Ordering::Release);

    ki_release_timer_table_lock();

    was_armed
}

/// Arm a timer (one-shot) with the specified due time and optional DPC.
///
/// # Arguments
/// * `timer` - Pointer to the Ktimer
/// * `due_time` - Due time in 100ns units (negative = relative)
/// * `dpc` - Optional DPC to queue on expiration
///
/// # Returns
/// `true` if the timer was previously armed
pub unsafe fn ke_set_timer(timer: *mut Ktimer, due_time: i64, dpc: *mut Kdpc) -> bool {
    ke_set_timer_ex(timer, due_time, 0, dpc)
}

// ============================================================
// KeCancelTimer
// ============================================================

/// Cancel a pending (armed) timer. If the timer's DPC is currently
/// being processed, this waits for processing to complete.
///
/// # Arguments
/// * `timer` - Pointer to the Ktimer
///
/// # Returns
/// `true` if the timer was armed and is now disarmed, `false` if
/// the timer was not armed.
pub unsafe fn ke_cancel_timer(timer: *mut Ktimer) -> bool {
    if timer.is_null() {
        return false;
    }

    let t = &mut *timer;
    let was_armed = t.header.inserted != 0;

    if was_armed {
        ki_acquire_timer_table_lock();

        // If the timer is currently being processed, spin until done
        if t.flags & TIMER_PROCESSING != 0 {
            ki_release_timer_table_lock();
            while t.flags & TIMER_PROCESSING != 0 {
                core::hint::spin_loop();
            }
            ki_acquire_timer_table_lock();
        }

        // Remove from timer table
        ki_remove_timer_from_table(t);

        // Clear inserted flag
        t.header.inserted = 0;

        ki_release_timer_table_lock();
    }

    was_armed
}

// ============================================================
// KiTimerTableIndex Computation
// ============================================================

/// Compute the timer table index for a given expiration time.
/// The timer table uses a logarithmic bucketing scheme:
///   - Entries 0-63:    1 unit apart (100ns each)
///   - Entries 64-127:  16 units apart
///   - Entries 128-191: 256 units apart
///   - Entries 192-255: 4096 units apart
unsafe fn ki_compute_timer_table_index(expiration_time: u64) -> u32 {
    let now = ki_query_system_time_internal();
    let delta = expiration_time.saturating_sub(now);

    if delta < 64 {
        // Bucket 0-63: delta maps directly
        delta as u32
    } else if delta < 64 + (64 * 16) {
        // Bucket 64-127: each covers 16 units
        let adjusted = delta - 64;
        64 + (adjusted / 16) as u32
    } else if delta < 64 + (64 * 16) + (64 * 256) {
        // Bucket 128-191: each covers 256 units
        let adjusted = delta - 64 - (64 * 16);
        128 + (adjusted / 256) as u32
    } else {
        // Bucket 192-255: each covers 4096 units
        let adjusted = delta - 64 - (64 * 16) - (64 * 256);
        let index = 192 + (adjusted / 4096) as u32;
        if index >= TIMER_TABLE_SIZE as u32 {
            TIMER_TABLE_SIZE as u32 - 1
        } else {
            index
        }
    }
}

/// Compute the starting time for a given bucket index.
unsafe fn ki_get_bucket_start_time(index: u32) -> u64 {
    let now = ki_query_system_time_internal();

    if index < 64 {
        now + index as u64
    } else if index < 128 {
        now + 64 + ((index - 64) as u64) * 16
    } else if index < 192 {
        now + 64 + (64 * 16) + ((index - 128) as u64) * 256
    } else {
        now + 64 + (64 * 16) + (64 * 256) + ((index - 192) as u64) * 4096
    }
}

// ============================================================
// Timer Table Insert / Remove
// ============================================================

/// Insert a timer into the timer table at the given bucket index.
unsafe fn ki_insert_timer_into_table(
    timer: *mut Ktimer,
    expiration_time: u64,
    table_index: u32,
) {
    if table_index >= TIMER_TABLE_SIZE as u32 {
        return;
    }

    let bucket = &mut KI_TIMER_TABLE.entries[table_index as usize];
    let mut inserted = false;

    // Walk the list and insert in sorted order by expiration time
    let mut current = bucket.entry.flink;
    while current != &bucket.entry as *const ListEntry as *mut ListEntry {
        let t = timer_from_timer_list_entry(current);
        if !t.is_null() {
            let t_ref = &*t;
            let t_abs_time = ki_compute_absolute_time(t_ref.due_time);
            if expiration_time <= t_abs_time {
                // Insert before this entry
                let prev = (*current).blink;
                (*timer).timer_list_entry.flink = current;
                (*timer).timer_list_entry.blink = prev;
                (*prev).flink = &mut (*timer).timer_list_entry as *mut ListEntry;
                (*current).blink = &mut (*timer).timer_list_entry as *mut ListEntry;
                inserted = true;
                break;
            }
        }
        current = (*current).flink;
    }

    if !inserted {
        // Insert at tail (end of list)
        bucket.entry.insert_tail(&mut (*timer).timer_list_entry);
    }

    // Update the bucket's earliest expiration time
    if bucket.time > expiration_time {
        bucket.time = expiration_time;
    }

    KI_TIMER_TABLE.timer_count += 1;
}

/// Remove a timer from the timer table.
unsafe fn ki_remove_timer_from_table(timer: *mut Ktimer) {
    let t = &mut *timer;

    if !t.timer_list_entry.flink.is_null()
        && t.timer_list_entry.flink != &t.timer_list_entry as *const ListEntry as *mut ListEntry
    {
        t.timer_list_entry.remove();

        KI_TIMER_TABLE.timer_count = KI_TIMER_TABLE.timer_count.saturating_sub(1);

        // Recompute the bucket's earliest time if this was the first timer
        let index = t.timer_table_entry_index as usize;
        if index < TIMER_TABLE_SIZE {
            let bucket = &mut KI_TIMER_TABLE.entries[index];
            if bucket.entry.is_empty() {
                bucket.time = TIME_TOO_LARGE as u64;
            } else {
                let first = bucket.entry.flink;
                if !first.is_null() {
                    let first_timer = timer_from_timer_list_entry(first);
                    if !first_timer.is_null() {
                        bucket.time = ki_compute_absolute_time((*first_timer).due_time);
                    }
                }
            }
        }
    }
}

/// Recover the Ktimer pointer from its timer_list_entry field.
#[inline]
unsafe fn timer_from_timer_list_entry(entry: *mut ListEntry) -> *mut Ktimer {
    if entry.is_null() {
        return core::ptr::null_mut();
    }
    let dummy: Ktimer = mem::zeroed();
    let base = &dummy as *const Ktimer as usize;
    let field = &dummy.timer_list_entry as *const ListEntry as usize;
    let offset = field - base;
    mem::forget(dummy);
    (entry as usize - offset) as *mut Ktimer
}

/// Compute absolute time from a due_time (handles relative vs absolute).
unsafe fn ki_compute_absolute_time(due_time: i64) -> u64 {
    if due_time < 0 {
        ki_query_system_time_internal() + (due_time.unsigned_abs() as u64)
    } else {
        due_time as u64
    }
}

// ============================================================
// KiTimerExpiration - Timer DPC Callback
// ============================================================

/// Called when a timer fires. Signals the timer's dispatcher header
/// and queues the associated DPC (if any).
///
/// # Arguments
/// * `timer` - The timer that fired
unsafe fn ki_timer_expiration(timer: *mut Ktimer) {
    if timer.is_null() {
        return;
    }

    let t = &mut *timer;
    KI_TIMER_FIRES_COUNT += 1;

    // Mark as no longer inserted
    t.header.inserted = 0;

    // If periodic timer, re-arm it
    if t.period != 0 {
        let period_delta = (t.period as i64) * 10_000; // ms to 100ns
        let new_due = if t.due_time < 0 {
            t.due_time // Re-arm with same relative period
        } else {
            t.due_time + period_delta // Re-arm with same absolute offset
        };

        // Re-insert into timer table
        let abs_time = ki_compute_absolute_time(new_due);
        let table_index = ki_compute_timer_table_index(abs_time);
        t.due_time = new_due;
        t.timer_table_entry_index = table_index;
        ki_insert_timer_into_table(t, abs_time, table_index);
        t.header.inserted = 1;
    }

    // Signal the timer object
    t.header.signal_state = 1;

    // Queue the DPC if one is attached
    if !t.dpc.is_null() {
        dpc::ke_insert_queue_dpc(t.dpc, core::ptr::null_mut(), core::ptr::null_mut(), 0);
    }

    // Process any waiters on the timer object
    ki_process_timer_waiters(t);
}

/// Process waiters blocked on a timer.
unsafe fn ki_process_timer_waiters(timer: *mut Ktimer) {
    let header = &mut (*timer).header;

    if !header.wait_list_entry.is_empty() {
        let mut current = header.wait_list_entry.flink;
        let list_head = &header.wait_list_entry as *const ListEntry as *mut ListEntry;

        while current != list_head {
            let wb = wait_block_from_wait_list_entry(current);
            let next = (*current).flink;

            ListEntry::remove_entry(&mut (*wb).wait_list_entry);

            let waiting_thread = (*wb).thread;
            if !waiting_thread.is_null() {
                (*waiting_thread).wait_block_list = core::ptr::null_mut();
                (*waiting_thread).wait_status = STATUS_SUCCESS;
                ki_ready_thread(waiting_thread);
            }

            current = next;
        }
    }
}

// ============================================================
// KiTimerDispatch - Scan Timer Table and Fire Expired Timers
// ============================================================

/// Scan the timer table and fire all expired timers.
/// Called from the timer interrupt handler and the dispatcher.
///
/// # Arguments
/// * `processor_number` - The processor number scanning its table
pub unsafe fn ki_timer_dispatch(processor_number: u32) {
    let current_time = ki_query_system_time_internal();

    ki_acquire_timer_table_lock();

    // Scan all timer table buckets
    for index in 0..TIMER_TABLE_SIZE {
        let bucket = &mut KI_TIMER_TABLE.entries[index];

        // Skip buckets whose earliest timer hasn't expired yet
        if bucket.time > current_time {
            continue;
        }

        // Walk the bucket's timer list and fire all expired timers
        let mut current = bucket.entry.flink;
        while current != &bucket.entry as *const ListEntry as *mut ListEntry {
            let timer = timer_from_timer_list_entry(current);
            if timer.is_null() {
                break;
            }

            let next = (*current).flink;
            let t = &mut *timer;

            let abs_time = ki_compute_absolute_time(t.due_time);

            // Check if this timer has expired
            if abs_time <= current_time {
                // Mark as processing to prevent re-entrant cancel
                t.flags |= TIMER_PROCESSING;

                // Remove from table
                ki_remove_timer_from_table(t);

                // Clear processing flag
                t.flags &= !TIMER_PROCESSING;

                // Fire the timer
                ki_timer_expiration(t);
            } else {
                // Timer hasn't expired; since the list is sorted,
                // all remaining timers in this bucket are later
                break;
            }

            current = next;
        }
    }

    ki_release_timer_table_lock();
}

// ============================================================
// KeQuerySystemTime
// ============================================================

/// Query the current system time in 100ns intervals since
/// January 1, 1601 (UTC).
///
/// # Returns
/// Current system time as u64
pub unsafe fn ke_query_system_time() -> u64 {
    ki_query_system_time_internal()
}

/// Internal system time query (no locking).
#[inline]
unsafe fn ki_query_system_time_internal() -> u64 {
    KI_SYSTEM_TIME
}

/// Set the system time (called by the real-time clock handler).
pub unsafe fn ke_set_system_time(new_time: u64) {
    KI_SYSTEM_TIME = new_time;
}

// ============================================================
// KeQueryTickCount
// ============================================================

/// Query the number of timer ticks since boot.
/// Each tick is approximately 15.625 ms (100 Hz system clock).
///
/// # Returns
/// Tick count as u64
pub unsafe fn ke_query_tick_count() -> u64 {
    KI_TICK_COUNT
}

/// Query the interrupt time (number of clock interrupts since boot).
///
/// # Returns
/// Interrupt time as u64
pub unsafe fn ke_query_interrupt_time() -> u64 {
    KI_INTERRUPT_TIME
}

/// Increment the interrupt time (called from timer ISR).
pub unsafe fn ki_increment_interrupt_time() {
    KI_INTERRUPT_TIME += 1;
}

// ============================================================
// KeQueryPerformanceCounter
// ============================================================

/// Query the performance counter value. On real hardware this reads
/// the hardware performance counter (HPET, TSC, etc.); here we use
/// the TSC via RDTSC.
///
/// # Arguments
/// * `performance_frequency` - Optional out pointer to receive the
///   performance counter frequency in ticks per second.
///
/// # Returns
/// Current performance counter value
pub unsafe fn ke_query_performance_counter(performance_frequency: *mut u64) -> u64 {
    if !performance_frequency.is_null() {
        *performance_frequency = KI_PERFORMANCE_FREQUENCY;
    }

    let counter: u64;
    let _low: u32;
    let _high: u32;
    asm!("rdtsc", out("eax") _low, out("edx") _high);
    // Use full rdtsc for 64-bit result
    let low: u32;
    let high: u32;
    asm!(
        "rdtsc",
        out("eax") low,
        out("edx") high,
        options(nostack, nomem),
    );
    counter = ((high as u64) << 32) | (low as u64);

    KI_PERFORMANCE_COUNTER = counter;
    counter
}

/// Set the performance counter frequency (called during HAL init).
pub unsafe fn ke_set_performance_frequency(frequency: u64) {
    if frequency > 0 {
        KI_PERFORMANCE_FREQUENCY = frequency;
    }
}

/// Query performance counter without writing to the out pointer.
#[inline]
pub unsafe fn ke_query_performance_counter_value() -> u64 {
    let low: u32;
    let high: u32;
    asm!(
        "rdtsc",
        out("eax") low,
        out("edx") high,
        options(nostack, nomem),
    );
    ((high as u64) << 32) | (low as u64)
}

// ============================================================
// Timer Initialization
// ============================================================

/// Initialize the timer subsystem. Called once during kernel startup.
pub unsafe fn ki_initialize_timer() {
    // Initialize the timer expiry list
    KI_TIMER_EXPIRY_LIST.initialize();

    // Initialize all timer table buckets
    for i in 0..TIMER_TABLE_SIZE {
        KI_TIMER_TABLE.entries[i].entry.initialize();
        KI_TIMER_TABLE.entries[i].time = TIME_TOO_LARGE as u64;
    }
    KI_TIMER_TABLE.timer_count = 0;

    // Initialize per-timer DPCs
    for i in 0..TIMER_TABLE_SIZE {
        let dpc = &mut KI_TIMER_DPCS[i];
        ki_initialize_dpc_for_timer(dpc, i);
    }

    // Initialize time tracking
    KI_SYSTEM_TIME = 0;
    KI_INTERRUPT_TIME = 0;
    KI_PERFORMANCE_COUNTER = 0;
    KI_PERFORMANCE_FREQUENCY = 10_000_000; // Default 100ns resolution
    KI_TIMER_FIRES_COUNT = 0;

    KI_TIMER_TABLE_LOCK.store(0, Ordering::Release);
}

/// Initialize a DPC object for a timer.
unsafe fn ki_initialize_dpc_for_timer(dpc: *mut Kdpc, timer_index: usize) {
    if dpc.is_null() {
        return;
    }

    let d = &mut *dpc;
    d.r#type = DpcType::TimerDpc;
    d.importance = 1; // Medium importance
    d.number = ke_get_current_processor_number() as u8;
    d.dpc_list_entry.initialize();
    d.deferred_routine = Some(ki_timer_dpc_routine);
    d.deferred_context = timer_index as Pvoid;
    d.system_argument1 = core::ptr::null_mut();
    d.system_argument2 = core::ptr::null_mut();
    d.dpc_data = core::ptr::null_mut();
}

/// Default DPC routine for timer expiration. This is the callback
/// that fires when a timer's associated DPC is dequeued.
extern "C" fn ki_timer_dpc_routine(
    dpc: *mut Kdpc,
    deferred_context: Pvoid,
    _system_argument1: Pvoid,
    _system_argument2: Pvoid,
) {
    // In a full implementation, this would look up the timer
    // from the deferred_context (timer index) and fire it.
    // For now, this is a placeholder for the DPC dispatch path.
    let _ = dpc;
    let _ = deferred_context;
}

// ============================================================
// Timer Queries and Utility
// ============================================================

/// Check if a timer is currently armed (inserted in the table).
pub unsafe fn ke_query_timer(timer: *mut Ktimer) -> bool {
    if timer.is_null() {
        false
    } else {
        (*timer).header.inserted != 0
    }
}

/// Get the due time of a timer.
pub unsafe fn ke_query_timer_due_time(timer: *mut Ktimer) -> i64 {
    if timer.is_null() { 0 } else { (*timer).due_time }
}

/// Get the period of a timer.
pub unsafe fn ke_query_timer_period(timer: *mut Ktimer) -> i32 {
    if timer.is_null() { 0 } else { (*timer).period }
}

/// Get the total number of timer fires since boot.
pub unsafe fn ke_query_timer_fires_count() -> u64 {
    KI_TIMER_FIRES_COUNT
}

/// Get the total number of armed timers.
pub unsafe fn ke_query_timer_count() -> u32 {
    KI_TIMER_TABLE.timer_count
}

/// Convert 100ns intervals to milliseconds.
#[inline]
pub fn ke_100ns_to_ms(interval_100ns: u64) -> u64 {
    interval_100ns / 10_000
}

/// Convert milliseconds to 100ns intervals.
#[inline]
pub fn ke_ms_to_100ns(ms: u64) -> u64 {
    ms * 10_000
}

/// Convert 100ns intervals to microseconds.
#[inline]
pub fn ke_100ns_to_us(interval_100ns: u64) -> u64 {
    interval_100ns / 10
}

/// Convert microseconds to 100ns intervals.
#[inline]
pub fn ke_us_to_100ns(us: u64) -> u64 {
    us * 10
}

/// Convert seconds to 100ns intervals.
#[inline]
pub fn ke_seconds_to_100ns(seconds: u64) -> u64 {
    seconds * 10_000_000
}
