/// KiDpc - Windows 10 Deferred Procedure Call Implementation
///
/// DPC object management, per-processor DPC queues, DPC interrupt
/// dispatch, and DPC storm prevention matching ntoskrnl.exe behavior.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 8
///   - WRK: ntoskrnl/ke/dpc.c, ki/dpc.c
///   - ReactOS: ke/dpc.c

use core::arch::asm;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicUsize, Ordering};

use crate::types::*;
use super::dispatcher::*;

// ============================================================
// DPC Constants
// ============================================================

/// Maximum DPC depth per processor before DPC storm prevention kicks in.
pub const MAXIMUM_DPC_QUEUE_DEPTH: i32 = 100;

/// Default DPC time target in 100ns units (100 microseconds).
pub const DEFAULT_DPC_TIME_TARGET: u64 = 1000;

/// Maximum DPCs to process per dispatch interrupt.
pub const MAXIMUM_DPCS_PER_SLICE: u32 = 20;

/// DPC request rate limit: minimum ticks between DPC bursts.
pub const DPC_REQUEST_RATE_LIMIT: u64 = 2;

/// Timer resolution in 100ns units.
pub const TIMER_RESOLUTION: u64 = 10_000;

/// Total DPCs dispatched (for profiling).
pub static mut KI_TOTAL_DPCS_DISPATCHED: u64 = 0;

/// DPC queue depth limit hit count.
pub static mut KI_DPC_QUEUE_DEPTH_LIMIT_HIT: u64 = 0;

/// Time between DPC storms detection.
pub static mut KI_DPC_STORM_TIMESTAMP: u64 = 0;

// ============================================================
// DpcType - Types of DPC Objects
// ============================================================

/// Types of DPC objects that can be queued.
#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DpcType {
    /// Standard DPC object
    DpcObject = 0,
    /// Timer DPC (fires when a Ktimer expires)
    TimerDpc = 1,
    /// Kernel-mode APC queued to user address space
    QueueUserApc = 2,
    /// Standard kernel normal routine DPC
    KernelNormalRoutine = 3,
    /// Kernel special user APC
    KernelSpecialUserApc = 4,
    /// Ready DPC (used by the scheduler)
    ReadyDpc = 5,
}

// ============================================================
// KDPC - Kernel DPC Object
// ============================================================

/// DPC object. When a DPC is inserted into a processor's DPC queue,
/// it is linked via dpc_list_entry. When dequeued, the deferred_routine
/// is called with the context arguments.
#[repr(C)]
pub struct Kdpc {
    /// Type of DPC object
    pub r#type: DpcType,
    /// Importance: 0=low, 1=medium, 2=high (high-importance DPCs
    /// are inserted at the head of the queue)
    pub importance: u8,
    /// Processor number this DPC is targeting
    pub number: u8,
    /// Padding
    pub pad: [u8; 5],
    /// List entry for per-processor DPC queue
    pub dpc_list_entry: ListEntry,
    /// The deferred routine to call when the DPC is dequeued
    pub deferred_routine: Option<extern "C" fn(
        *mut Kdpc,
        Pvoid,
        Pvoid,
        Pvoid,
    )>,
    /// Context argument passed to deferred_routine
    pub deferred_context: Pvoid,
    /// Optional system argument 1
    pub system_argument1: Pvoid,
    /// Optional system argument 2
    pub system_argument2: Pvoid,
    /// Pointer to per-processor DPC data
    pub dpc_data: *mut KdpcData,
}

unsafe impl Send for Kdpc {}
unsafe impl Sync for Kdpc {}

impl Kdpc {
    pub fn uninitialized() -> Self {
        Self {
            r#type: DpcType::DpcObject,
            importance: 0,
            number: 0,
            pad: [0; 5],
            dpc_list_entry: ListEntry::uninitialized(),
            deferred_routine: None,
            deferred_context: core::ptr::null_mut(),
            system_argument1: core::ptr::null_mut(),
            system_argument2: core::ptr::null_mut(),
            dpc_data: core::ptr::null_mut(),
        }
    }
}

// ============================================================
// KDPCDATA - Per-Processor DPC Queue Data
// ============================================================

/// Per-processor DPC queue data.
#[repr(C)]
pub struct KdpcData {
    /// Lock protecting this DPC queue
    pub lock: u64,
    /// Head of the DPC list
    pub list_head: ListEntry,
    /// Number of DPCs in the queue
    pub count: i32,
    /// Maximum queue depth (for this processor)
    pub maximum_depth: i32,
    /// Total DPCs dispatched from this queue
    pub dispatched_count: u64,
    /// Whether a DPC interrupt is pending
    pub interrupt_pending: bool,
}

unsafe impl Send for KdpcData {}
unsafe impl Sync for KdpcData {}

// ============================================================
// Global DPC State
// ============================================================

/// Per-processor DPC queue data
static mut KI_DPC_QUEUE: [KdpcData; 64] = unsafe { mem::zeroed() };

/// DPC storm prevention: synch count for tracking rapid DPC insertion
pub static SYNCH_COUNT: AtomicUsize = AtomicUsize::new(0);

/// DPC interrupt requested flag per processor
static KI_DPC_INTERRUPT: [AtomicBool; 64] = [
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
    AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false), AtomicBool::new(false),
];

/// DPC execution time tracking per processor
static mut KI_DPC_TIME: [u64; 64] = [0u64; 64];

/// DPCs executed per interrupt dispatch
static mut KI_DPC_EXECUTED: [u32; 64] = [0u32; 64];

/// Total DPCs queued since boot
static mut KI_DPC_QUEUE_COUNT: u64 = 0;

/// DPC lock (per-processor in real ntoskrnl)
static mut KI_DPC_LOCK: u64 = 0;

// ============================================================
// DPC Queue Lock
// ============================================================

#[inline]
unsafe fn ki_acquire_dpc_lock() {
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
        in(reg) &mut KI_DPC_LOCK as *mut u64,
        options(nostack, nomem),
    );
}

#[inline]
unsafe fn ki_release_dpc_lock() {
    asm!(
        "lock btr qword ptr [{0}], 0",
        in(reg) &mut KI_DPC_LOCK as *mut u64,
        options(nostack, nomem),
    );
}

// ============================================================
// KeInitializeDpc
// ============================================================

/// Initialize a DPC object.
///
/// # Arguments
/// * `dpc` - Pointer to the Kdpc to initialize
/// * `deferred_routine` - The function to call when the DPC fires
/// * `deferred_context` - Context argument passed to the routine
pub unsafe fn ke_initialize_dpc(
    dpc: *mut Kdpc,
    deferred_routine: extern "C" fn(*mut Kdpc, Pvoid, Pvoid, Pvoid),
    deferred_context: Pvoid,
) {
    if dpc.is_null() {
        return;
    }

    let d = &mut *dpc;
    d.r#type = DpcType::DpcObject;
    d.importance = 1; // Medium
    d.number = 0;
    d.pad = [0; 5];
    d.dpc_list_entry.initialize();
    d.deferred_routine = Some(deferred_routine);
    d.deferred_context = deferred_context;
    d.system_argument1 = core::ptr::null_mut();
    d.system_argument2 = core::ptr::null_mut();
    d.dpc_data = core::ptr::null_mut();
}

/// Initialize a DPC with explicit importance and target processor.
pub unsafe fn ke_initialize_dpc_ex(
    dpc: *mut Kdpc,
    deferred_routine: extern "C" fn(*mut Kdpc, Pvoid, Pvoid, Pvoid),
    deferred_context: Pvoid,
    importance: u8,
    target_processor: u32,
) {
    ke_initialize_dpc(dpc, deferred_routine, deferred_context);
    if !dpc.is_null() {
        (*dpc).importance = importance;
        (*dpc).number = target_processor as u8;
    }
}

// ============================================================
// KeInsertQueueDpc
// ============================================================

/// Insert a DPC into a processor's DPC queue. The DPC will be
/// executed when the processor reaches DISPATCH_LEVEL or when
/// the DPC interrupt is serviced.
///
/// # Arguments
/// * `dpc` - The DPC to insert
/// * `arg1` - Optional first system argument (stored in DPC)
/// * `arg2` - Optional second system argument (stored in DPC)
/// * `processor_number` - Target processor (0 = current)
///
/// # Returns
/// `true` if the DPC was successfully queued, `false` if already
/// queued or invalid
pub unsafe fn ke_insert_queue_dpc(
    dpc: *mut Kdpc,
    arg1: Pvoid,
    arg2: Pvoid,
    processor_number: u32,
) -> bool {
    if dpc.is_null() {
        return false;
    }

    let d = &mut *dpc;

    // Don't re-insert if already in a queue
    if d.dpc_list_entry.flink != core::ptr::null_mut()
        && d.dpc_list_entry.flink != &d.dpc_list_entry as *const ListEntry as *mut ListEntry
    {
        return false;
    }

    let proc = if processor_number == 0 {
        ke_get_current_processor_number() as usize
    } else {
        processor_number as usize
    };

    if proc >= 64 {
        return false;
    }

    // DPC storm prevention: check synch count
    let current_synch = SYNCH_COUNT.fetch_add(1, Ordering::AcqRel);
    if current_synch > MAXIMUM_DPC_QUEUE_DEPTH as usize {
        SYNCH_COUNT.fetch_sub(1, Ordering::AcqRel);
        KI_DPC_QUEUE_DEPTH_LIMIT_HIT += 1;
        return false;
    }

    let queue = &mut KI_DPC_QUEUE[proc];

    ki_acquire_dpc_lock();

    // Set system arguments on the DPC
    d.system_argument1 = arg1;
    d.system_argument2 = arg2;
    d.number = proc as u8;

    // Insert into the queue (high-importance at head, low at tail)
    if d.importance == 2 {
        // High importance: insert at head
        queue.list_head.insert_head(&mut d.dpc_list_entry);
    } else {
        // Medium/Low importance: insert at tail
        queue.list_head.insert_tail(&mut d.dpc_list_entry);
    }

    queue.count += 1;
    KI_DPC_QUEUE_COUNT += 1;

    ki_release_dpc_lock();

    // Request DPC interrupt on target processor
    KI_DPC_INTERRUPT[proc].store(true, Ordering::Release);

    // Request an inter-processor interrupt if targeting another processor
    if proc != ke_get_current_processor_number() as usize {
        ki_request_dpc_interrupt(proc as u32);
    }

    true
}

// ============================================================
// KeRemoveQueueDpc
// ============================================================

/// Remove a DPC from the queue without executing it.
///
/// # Arguments
/// * `dpc` - The DPC to remove
///
/// # Returns
/// `true` if the DPC was found and removed, `false` otherwise
pub unsafe fn ke_remove_queue_dpc(dpc: *mut Kdpc) -> bool {
    if dpc.is_null() {
        return false;
    }

    let d = &mut *dpc;
    let proc = d.number as usize;

    if proc >= 64 {
        return false;
    }

    let queue = &mut KI_DPC_QUEUE[proc];

    ki_acquire_dpc_lock();

    // Check if this DPC is actually in the queue
    if d.dpc_list_entry.flink.is_null()
        || d.dpc_list_entry.flink == &d.dpc_list_entry as *const ListEntry as *mut ListEntry
    {
        ki_release_dpc_lock();
        return false;
    }

    // Remove from the queue
    d.dpc_list_entry.remove();
    queue.count = queue.count.saturating_sub(1);

    ki_release_dpc_lock();

    SYNCH_COUNT.fetch_sub(1, Ordering::AcqRel);

    true
}

// ============================================================
// KeRemoveQueuedDpc
// ============================================================

/// Dequeue a DPC and process it immediately. This is used when a
/// DPC needs to be executed synchronously (e.g., during KeFlushQueuedDpcs).
///
/// # Arguments
/// * `dpc` - The DPC to dequeue and process
///
/// # Returns
/// `true` if the DPC was found and executed
pub unsafe fn ke_remove_queued_dpc(dpc: *mut Kdpc) -> bool {
    if dpc.is_null() {
        return false;
    }

    let d = &mut *dpc;
    let proc = d.number as usize;

    if proc >= 64 {
        return false;
    }

    // Remove from the queue
    if !ke_remove_queue_dpc(dpc) {
        return false;
    }

    // Execute the DPC
    ki_execute_dpc(d);

    true
}

// ============================================================
// KiDispatchInterrupt - Process DPC Queue
// ============================================================

/// Process the DPC queue for the current processor. This is called
/// when a DPC interrupt is received or when lowering IRQL from
/// above DISPATCH_LEVEL to below DISPATCH_LEVEL.
///
/// Processes all pending DPCs up to MAXIMUM_DPCS_PER_SLICE.
pub unsafe fn ki_dispatch_interrupt(prcb: *mut Kprcb) {
    let proc = if !prcb.is_null() {
        (*prcb).number as usize
    } else {
        ke_get_current_processor_number() as usize
    };

    if proc >= 64 {
        return;
    }

    // Clear the DPC interrupt pending flag
    KI_DPC_INTERRUPT[proc].store(false, Ordering::Release);

    let queue = &mut KI_DPC_QUEUE[proc];
    let mut dispatched = 0u32;
    let start_time = ki_query_dpc_time();

    loop {
        ki_acquire_dpc_lock();

        if queue.count == 0 || dispatched >= MAXIMUM_DPCS_PER_SLICE {
            ki_release_dpc_lock();
            break;
        }

        // Dequeue the first DPC from the list
        if queue.list_head.is_empty() {
            ki_release_dpc_lock();
            break;
        }

        let entry = queue.list_head.flink;
        let dpc = dpc_from_list_entry(entry);

        if dpc.is_null() {
            ki_release_dpc_lock();
            break;
        }

        // Remove from queue
        (*entry).remove();
        queue.count -= 1;

        ki_release_dpc_lock();

        // Execute the DPC
        ki_execute_dpc(dpc);
        dispatched += 1;
        SYNCH_COUNT.fetch_sub(1, Ordering::AcqRel);

        // Update per-processor stats
        KI_DPC_EXECUTED[proc] += 1;
        KI_TOTAL_DPCS_DISPATCHED += 1;
    }

    let end_time = ki_query_dpc_time();
    KI_DPC_TIME[proc] += end_time.saturating_sub(start_time);
}

/// Recover the Kdpc pointer from its dpc_list_entry field.
#[inline]
unsafe fn dpc_from_list_entry(entry: *mut ListEntry) -> *mut Kdpc {
    if entry.is_null() {
        return core::ptr::null_mut();
    }
    let dummy: Kdpc = mem::zeroed();
    let base = &dummy as *const Kdpc as usize;
    let field = &dummy.dpc_list_entry as *const ListEntry as usize;
    let offset = field - base;
    mem::forget(dummy);
    (entry as usize - offset) as *mut Kdpc
}

// ============================================================
// KiExecuteDpc - Execute a Single DPC
// ============================================================

/// Execute a single DPC's deferred routine.
///
/// # Arguments
/// * `dpc` - The DPC to execute
pub unsafe fn ki_execute_dpc(dpc: *mut Kdpc) {
    if dpc.is_null() {
        return;
    }

    let d = &*dpc;

    // Call the deferred routine if set
    if let Some(routine) = d.deferred_routine {
        let old_irql = ke_raise_irql(DISPATCH_LEVEL);

        routine(
            dpc,
            d.deferred_context,
            d.system_argument1,
            d.system_argument2,
        );

        ke_lower_irql(old_irql);
    }

    // Clear the DPC list entry after execution
    let d_mut = &mut *dpc;
    d_mut.dpc_list_entry.initialize();
}

// ============================================================
// DPC Storm Prevention
// ============================================================

/// Check if a DPC storm is in progress (too many DPCs queued
/// in rapid succession). Returns true if DPC processing should
/// be throttled.
unsafe fn ki_is_dpc_storm() -> bool {
    let current_count = SYNCH_COUNT.load(Ordering::Acquire);
    current_count > (MAXIMUM_DPC_QUEUE_DEPTH / 2) as usize
}

/// Reset the synch count (called after a DPC burst is processed).
pub unsafe fn ki_reset_synch_count() {
    SYNCH_COUNT.store(0, Ordering::Release);
}

/// Get the current synch count (for diagnostics).
pub unsafe fn ke_query_synch_count() -> usize {
    SYNCH_COUNT.load(Ordering::Acquire)
}

// ============================================================
// KeFlushQueuedDpcs
// ============================================================

/// Flush all queued DPCs on the current processor. This processes
/// all pending DPCs synchronously before returning.
pub unsafe fn ke_flush_queued_dpcs() {
    let proc = ke_get_current_processor_number() as usize;

    if proc >= 64 {
        return;
    }

    let queue = &mut KI_DPC_QUEUE[proc];

    loop {
        ki_acquire_dpc_lock();

        if queue.count == 0 {
            ki_release_dpc_lock();
            break;
        }

        if queue.list_head.is_empty() {
            ki_release_dpc_lock();
            break;
        }

        let entry = queue.list_head.flink;
        let dpc = dpc_from_list_entry(entry);

        if dpc.is_null() {
            ki_release_dpc_lock();
            break;
        }

        (*entry).remove();
        queue.count -= 1;

        ki_release_dpc_lock();

        ki_execute_dpc(dpc);
        SYNCH_COUNT.fetch_sub(1, Ordering::AcqRel);
    }
}

// ============================================================
// KeStallExecutionProcessor
// ============================================================

/// Busy-wait for the specified number of microseconds.
/// This raises IRQL to HIGH_LEVEL and spins, preventing
/// any other processor from running on the current CPU.
///
/// # Arguments
/// * `microseconds` - Number of microseconds to stall
pub unsafe fn ke_stall_execution_processor(microseconds: u32) {
    if microseconds == 0 {
        return;
    }

    let old_irql = ke_raise_irql(HIGH_LEVEL);

    // Estimate TSC ticks for the requested stall duration.
    // Assume 2.4 GHz TSC (typical): 1 us ≈ 2400 ticks.
    let ticks_per_us: u64 = 2400;
    let target_ticks = (microseconds as u64) * ticks_per_us;
    let start = core::arch::x86_64::_rdtsc();

    loop {
        let elapsed = core::arch::x86_64::_rdtsc().wrapping_sub(start);
        if elapsed >= target_ticks {
            break;
        }
        asm!("pause", options(nostack, nomem));
    }

    ke_lower_irql(old_irql);
}

// ============================================================
// DPC Timer Helpers
// ============================================================

/// Query the DPC time (ticks since boot).
#[inline]
unsafe fn ki_query_dpc_time() -> u64 {
    core::arch::x86_64::_rdtsc()
}

/// Request a DPC interrupt on a specific processor.
unsafe fn ki_request_dpc_interrupt(processor_number: u32) {
    let proc = processor_number as usize;
    if proc < 64 {
        KI_DPC_INTERRUPT[proc].store(true, Ordering::Release);
    }
}

// ============================================================
// DPC Initialization
// ============================================================

/// Initialize the DPC subsystem. Called once during kernel startup.
pub unsafe fn ki_initialize_dpc() {
    // Initialize per-processor DPC queues
    for proc in 0..64 {
        let queue = &mut KI_DPC_QUEUE[proc];
        queue.lock = 0;
        queue.list_head.initialize();
        queue.count = 0;
        queue.maximum_depth = MAXIMUM_DPC_QUEUE_DEPTH;
        queue.dispatched_count = 0;
        queue.interrupt_pending = false;

        KI_DPC_INTERRUPT[proc].store(false, Ordering::Release);
        KI_DPC_TIME[proc] = 0;
        KI_DPC_EXECUTED[proc] = 0;
    }

    KI_DPC_LOCK = 0;
    KI_DPC_QUEUE_COUNT = 0;
    KI_TOTAL_DPCS_DISPATCHED = 0;
    KI_DPC_QUEUE_DEPTH_LIMIT_HIT = 0;
    KI_DPC_STORM_TIMESTAMP = 0;
}

// ============================================================
// DPC Queries
// ============================================================

/// Get the DPC queue depth for a processor.
pub unsafe fn ke_query_dpc_queue_depth(processor_number: u32) -> i32 {
    let proc = processor_number as usize;
    if proc >= 64 { 0 } else { KI_DPC_QUEUE[proc].count }
}

/// Get the maximum DPC queue depth.
pub unsafe fn ke_query_dpc_maximum_depth() -> i32 {
    MAXIMUM_DPC_QUEUE_DEPTH
}

/// Get the total number of DPCs dispatched.
pub unsafe fn ke_query_total_dpcs_dispatched() -> u64 {
    KI_TOTAL_DPCS_DISPATCHED
}

/// Get the total number of DPCs queued.
pub unsafe fn ke_query_dpc_queue_count() -> u64 {
    KI_DPC_QUEUE_COUNT
}

/// Get the number of DPC depth limit violations.
pub unsafe fn ke_query_dpc_depth_limit_hit() -> u64 {
    KI_DPC_QUEUE_DEPTH_LIMIT_HIT
}

/// Check if a DPC interrupt is pending for a processor.
pub unsafe fn ke_query_dpc_interrupt_pending(processor_number: u32) -> bool {
    let proc = processor_number as usize;
    if proc >= 64 { false } else { KI_DPC_INTERRUPT[proc].load(Ordering::Acquire) }
}

/// Get the DPC execution time for a processor (in TSC ticks).
pub unsafe fn ke_query_dpc_time(processor_number: u32) -> u64 {
    let proc = processor_number as usize;
    if proc >= 64 { 0 } else { KI_DPC_TIME[proc] }
}

/// Get the number of DPCs executed for a processor.
pub unsafe fn ke_query_dpc_executed_count(processor_number: u32) -> u32 {
    let proc = processor_number as usize;
    if proc >= 64 { 0 } else { KI_DPC_EXECUTED[proc] }
}

/// Set the maximum queue depth for a processor.
pub unsafe fn ke_set_dpc_maximum_depth(processor_number: u32, maximum_depth: i32) {
    let proc = processor_number as usize;
    if proc < 64 {
        KI_DPC_QUEUE[proc].maximum_depth = maximum_depth;
    }
}

/// Query if the current processor has any DPCs queued.
pub unsafe fn ke_are_dpcs_queued() -> bool {
    let proc = ke_get_current_processor_number() as usize;
    if proc >= 64 {
        false
    } else {
        KI_DPC_QUEUE[proc].count > 0
    }
}
