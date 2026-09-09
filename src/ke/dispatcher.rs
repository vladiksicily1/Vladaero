/// KiDispatcher - Windows 10 Kernel Dispatcher Implementation
///
/// Core thread scheduling, wait/signal primitives, context switching,
/// and dispatcher ready queue management matching ntoskrnl.exe behavior.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 5/6
///   - WRK (Windows Research Kernel): ntoskrnl/ke/dispatch, thrready, thrwait
///   - ReactOS ke/dispatch.c, ke/thrready.c, ke/thrwait.c

use core::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use core::{arch::asm, mem, ptr};

use crate::types::*;

// ============================================================
// Dispatch object types (USEROBJECT_HEADER.TypeIndex range)
// ============================================================

pub const DISPATCHER_OBJECT_TYPE_EVENT: u8 = 0;
pub const DISPATCHER_OBJECT_TYPE_EVENT_PAIR: u8 = 1;
pub const DISPATCHER_OBJECT_TYPE_MUTANT: u8 = 2;
pub const DISPATCHER_OBJECT_TYPE_PROCESS: u8 = 3;
pub const DISPATCHER_OBJECT_TYPE_QUEUE: u8 = 4;
pub const DISPATCHER_OBJECT_TYPE_SEMAPHORE: u8 = 5;
pub const DISPATCHER_OBJECT_TYPE_THREAD: u8 = 6;
pub const DISPATCHER_OBJECT_TYPE_TIMER: u8 = 8;

pub const DISPATCHER_OBJECT_INSERTED: u8 = 1;
pub const DISPATCHER_OBJECT_NOT_INSERTED: u8 = 0;

/// Priority level count (0..31)
pub const MAXIMUM_PRIORITY_LEVEL: usize = 32;

/// Default quantum in timer ticks
pub const KI_THREAD_QUANTUM: u64 = 6;

/// Priority bounds
pub const MAX_THREAD_PRIORITY: i8 = 31;
pub const MIN_THREAD_PRIORITY: i8 = 0;

/// Maximum supported processors
pub const MAXIMUM_PROCESSORS: u32 = 64;

/// Maximum wait objects in a multiple-object wait
pub const MAXIMUM_WAIT_OBJECTS: usize = 3;

/// Event types
pub const SYNCHRONIZATION_EVENT: u8 = 0;
pub const NOTIFICATION_EVENT: u8 = 1;

/// Wait types
pub const WAIT_TYPE_ANY: u16 = 0;
pub const WAIT_TYPE_ALL: u16 = 1;

/// STATUS_TIMEOUT
pub const STATUS_TIMEOUT: NtStatus = 0x00000102;
pub const STATUS_MUTANT_NOT_OWNED: NtStatus = 0xC0000046;
pub const STATUS_SEMAPHORE_LIMIT_EXCEEDED: NtStatus = 0xC000005B;
pub const STATUS_ABANDONED: NtStatus = 0x00000080;

// ============================================================
// ListEntry - Doubly-linked list (LIST_ENTRY)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ListEntry {
    pub flink: *mut ListEntry,
    pub blink: *mut ListEntry,
}

unsafe impl Send for ListEntry {}
unsafe impl Sync for ListEntry {}

impl ListEntry {
    pub const fn uninitialized() -> Self {
        Self {
            flink: ptr::null_mut(),
            blink: ptr::null_mut(),
        }
    }

    pub const fn new() -> Self {
        Self::uninitialized()
    }

    /// Initialize a list entry to point to itself (empty list).
    pub fn initialize(&mut self) {
        self.flink = self as *mut Self;
        self.blink = self as *mut Self;
    }

    /// Returns true if the list is empty (head points to itself).
    pub fn is_empty(&self) -> bool {
        self.flink == self as *const Self as *mut Self
    }

    /// Insert Entry after self (at the head of the list).
    pub fn insert_head(&mut self, entry: &mut ListEntry) {
        let next = self.flink;
        entry.flink = next;
        entry.blink = self;
        unsafe {
            (*next).blink = entry as *mut ListEntry;
        }
        self.flink = entry as *mut ListEntry;
    }

    /// Insert Entry before self (at the tail of the list).
    pub fn insert_tail(&mut self, entry: &mut ListEntry) {
        let prev = self.blink;
        entry.flink = self;
        entry.blink = prev;
        unsafe {
            (*prev).flink = entry as *mut ListEntry;
        }
        self.blink = entry as *mut ListEntry;
    }

    /// Remove this entry from the list.
    pub fn remove(&mut self) {
        let blink = self.blink;
        let flink = self.flink;
        unsafe {
            (*flink).blink = blink;
            (*blink).flink = flink;
        }
        self.flink = self;
        self.blink = self;
    }

    /// Remove a specific entry from the list (static helper).
    pub fn remove_entry(entry: &mut ListEntry) {
        entry.remove();
    }

    /// Count elements in the list (excluding the head sentinel).
    pub fn count(&self) -> usize {
        let mut count = 0usize;
        let mut current = self.flink;
        while current != self as *const Self as *mut ListEntry {
            count += 1;
            current = unsafe { (*current).flink };
        }
        count
    }
}

// ============================================================
// DispatcherHeader
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct DispatcherHeader {
    pub r#type: u8,
    pub absolute: u8,
    pub size: u8,
    pub inserted: u8,
    pub signal_state: i32,
    pub wait_list_entry: ListEntry,
}

unsafe impl Send for DispatcherHeader {}
unsafe impl Sync for DispatcherHeader {}

impl DispatcherHeader {
    pub fn initialize(&mut self, object_type: u8) {
        self.r#type = object_type;
        self.absolute = 0;
        self.size = mem::size_of::<Self>() as u8;
        self.inserted = 0;
        self.signal_state = 0;
        self.wait_list_entry.initialize();
    }

    pub fn is_signaled(&self) -> bool {
        self.signal_state > 0
    }
}

// ============================================================
// KWAIT_BLOCK
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KwaitBlock {
    pub wait_list_entry: ListEntry,
    pub thread: *mut Kthread,
    pub object: *mut DispatcherHeader,
    pub next_wait_block: *mut KwaitBlock,
    pub wait_key: u16,
    pub wait_type: u16,
    pub wait_state: u16,
    pub spare1: u16,
}

unsafe impl Send for KwaitBlock {}
unsafe impl Sync for KwaitBlock {}

impl KwaitBlock {
    pub fn initialize(&mut self) {
        self.wait_list_entry.initialize();
        self.thread = ptr::null_mut();
        self.object = ptr::null_mut();
        self.next_wait_block = ptr::null_mut();
        self.wait_key = 0;
        self.wait_type = 0;
        self.wait_state = 0;
        self.spare1 = 0;
    }
}

// ============================================================
// KEVENT - Synchronization/Notification Event
// ============================================================

#[repr(C)]
pub struct Kevent {
    pub header: DispatcherHeader,
}

unsafe impl Send for Kevent {}
unsafe impl Sync for Kevent {}
impl Clone for Kevent {
    fn clone(&self) -> Self { Self { header: self.header.clone() } }
}
impl Copy for Kevent {}
impl core::fmt::Debug for Kevent {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("Kevent").field("header", &self.header).finish()
    }
}

impl Kevent {
    pub fn initialize(&mut self, event_type: u8, state: i32) {
        self.header.initialize(DISPATCHER_OBJECT_TYPE_EVENT);
        self.header.r#type = event_type;
        self.header.signal_state = state;
    }
}

// ============================================================
// KSEMAPHORE
// ============================================================

#[repr(C)]
pub struct Ksemaphore {
    pub header: DispatcherHeader,
    pub limit: i32,
}

unsafe impl Send for Ksemaphore {}
unsafe impl Sync for Ksemaphore {}
impl Clone for Ksemaphore {
    fn clone(&self) -> Self { Self { header: self.header.clone(), limit: self.limit } }
}
impl Copy for Ksemaphore {}
impl core::fmt::Debug for Ksemaphore {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("Ksemaphore").field("header", &self.header).field("limit", &self.limit).finish()
    }
}

impl Ksemaphore {
    pub fn initialize(&mut self, count: i32, limit: i32) {
        self.header.initialize(DISPATCHER_OBJECT_TYPE_SEMAPHORE);
        self.header.signal_state = count;
        self.limit = limit;
    }
}

// ============================================================
// KMUTANT
// ============================================================

#[repr(C)]
pub struct Kmutant {
    pub header: DispatcherHeader,
    pub mutant_list_entry: ListEntry,
    pub owner_thread: *mut Kthread,
    pub apc_disable_count: i32,
    pub abandoned: u8,
    pub apc_disable: u8,
}

unsafe impl Send for Kmutant {}
unsafe impl Sync for Kmutant {}

impl Kmutant {
    pub fn initialize(&mut self) {
        self.header.initialize(DISPATCHER_OBJECT_TYPE_MUTANT);
        self.mutant_list_entry.initialize();
        self.owner_thread = ptr::null_mut();
        self.apc_disable_count = 0;
        self.abandoned = 0;
        self.apc_disable = 0;
    }
}

// ============================================================
// KQUEUE
// ============================================================

#[repr(C)]
pub struct Kqueue {
    pub header: DispatcherHeader,
    pub entry_list: ListEntry,
    pub wait_list: ListEntry,
    pub queue_count: i32,
    pub queue_count_limit: i32,
    pub thread_count: i32,
    pub dispatch_count: u32,
    pub thread_handle: Handle,
}

unsafe impl Send for Kqueue {}
unsafe impl Sync for Kqueue {}

impl Kqueue {
    pub fn initialize(&mut self, count: i32) {
        self.header.initialize(DISPATCHER_OBJECT_TYPE_QUEUE);
        self.entry_list.initialize();
        self.wait_list.initialize();
        self.queue_count = 0;
        self.queue_count_limit = count;
        self.thread_count = 0;
        self.dispatch_count = 0;
        self.thread_handle = ptr::null_mut();
    }
}

// ============================================================
// KTHREAD
// ============================================================

#[repr(C)]
pub struct Kthread {
    pub header: DispatcherHeader,
    pub cycle_time: u64,
    pub quantum_target: u64,
    pub initial_stack: Pvoid,
    pub stack_limit: Pvoid,
    pub stack_base: Pvoid,
    pub thread_lock: KspinLock,
    pub wait_status: NtStatus,
    pub wait_block_list: *mut KwaitBlock,
    pub wait_list_entry: ListEntry,
    pub processor: u8,
    pub wait_time: u64,
    pub teb: Pvoid,
    pub context_switches: u64,
    pub state: KthreadState,
    pub alertable: Boolean,
    pub wait_type: u16,
    pub wait_irql: Irql,
    pub wait_mode: u8,
    pub kprocess: *mut Kprocess,
    pub queue: *mut Kqueue,
    pub wait_prcb: *mut Kprcb,
    pub user_affinity: Kaffinity,
    pub system_affinity: Kaffinity,
    pub suspend_count: i32,
    pub freeze_count: i32,
    pub kernel_stack: Pvoid,
    pub next_thread: *mut Kthread,
    pub process: *mut Kprocess,
    pub affinity: Kaffinity,
    pub priority: i8,
    pub base_priority: i8,
    pub preemption_disable_count: i16,
    pub ideal_processor: u8,
    pub swap_busy: AtomicBool,
    pub previous_mode: u8,
    pub apc_state_index: u8,
    pub apc_pending: Boolean,
    pub user_apc_pending: Boolean,
}

unsafe impl Send for Kthread {}
unsafe impl Sync for Kthread {}

impl Kthread {
    pub fn initialize(&mut self) {
        self.header.initialize(DISPATCHER_OBJECT_TYPE_THREAD);
        self.cycle_time = 0;
        self.quantum_target = 0;
        self.initial_stack = ptr::null_mut();
        self.stack_limit = ptr::null_mut();
        self.stack_base = ptr::null_mut();
        self.thread_lock = 0;
        self.wait_status = STATUS_SUCCESS;
        self.wait_block_list = ptr::null_mut();
        self.wait_list_entry.initialize();
        self.processor = 0;
        self.wait_time = 0;
        self.teb = ptr::null_mut();
        self.context_switches = 0;
        self.state = KthreadState::Initialized;
        self.alertable = 0;
        self.wait_type = 0;
        self.wait_irql = PASSIVE_LEVEL;
        self.wait_mode = 0;
        self.kprocess = ptr::null_mut();
        self.queue = ptr::null_mut();
        self.wait_prcb = ptr::null_mut();
        self.user_affinity = 1;
        self.system_affinity = 1;
        self.suspend_count = 0;
        self.freeze_count = 0;
        self.kernel_stack = ptr::null_mut();
        self.next_thread = ptr::null_mut();
        self.process = ptr::null_mut();
        self.affinity = 1;
        self.priority = 0;
        self.base_priority = 0;
        self.preemption_disable_count = 0;
        self.ideal_processor = 0;
        self.swap_busy = AtomicBool::new(false);
        self.previous_mode = 0;
        self.apc_state_index = 0;
        self.apc_pending = 0;
        self.user_apc_pending = 0;
    }
}

// ============================================================
// KPROCESS
// ============================================================

#[repr(C)]
pub struct Kprocess {
    pub header: DispatcherHeader,
    pub profile_lock: KspinLock,
    pub ready_list_entry: ListEntry,
    pub affinity: Kaffinity,
    pub affinity_lock: KspinLock,
    pub process_lock: KspinLock,
    pub base_priority: u8,
    pub quantum_reset: u8,
    pub process_flags: u32,
    pub directory_table_base: u64,
    pub process_id: u64,
    pub ideal_processor: u8,
}

unsafe impl Send for Kprocess {}
unsafe impl Sync for Kprocess {}

pub const PROCESS_FLAG_AUTO_ALIGNMENT: u32 = 0x00000001;
pub const PROCESS_FLAG_DEBUG_PORT: u32 = 0x00000010;
pub const PROCESS_FLAG_LADR: u32 = 0x00000020;
pub const PROCESS_CREATE_FLAGS_SUSPENDED: u32 = 0x00000100;

impl Kprocess {
    pub fn initialize(&mut self) {
        self.header.initialize(DISPATCHER_OBJECT_TYPE_PROCESS);
        self.profile_lock = 0;
        self.ready_list_entry.initialize();
        self.affinity = 1;
        self.affinity_lock = 0;
        self.process_lock = 0;
        self.base_priority = 8;
        self.quantum_reset = 6;
        self.process_flags = 0;
        self.directory_table_base = 0;
        self.process_id = 0;
        self.ideal_processor = 0;
    }
}

// ============================================================
// KPRCB - Processor Control Block
// ============================================================

#[repr(C)]
pub struct Kprcb {
    pub current_thread: *mut Kthread,
    pub next_thread: *mut Kthread,
    pub idle_thread: *mut Kthread,
    pub number: u32,
    pub lock: KspinLock,
    pub gdt: Pvoid,
    pub tss: Pvoid,
    pub dpc_queue_depth: i32,
    pub dpc_lock: KspinLock,
    pub dpc_requests_per_loop: u32,
    pub quantum_end: u64,
    pub ready_summary: u32,
    pub dispatcher_ready_list_head: [ListEntry; MAXIMUM_PRIORITY_LEVEL],
    pub dpc_list_entry: ListEntry,
    pub interrupt_count: u64,
    pub kernel_time: u64,
    pub user_time: u64,
    pub interrupt_time: u64,
    pub current_irql: u8,
    pub suspended_domain: u8,
}

unsafe impl Send for Kprcb {}
unsafe impl Sync for Kprcb {}

impl Kprcb {
    pub fn initialize(&mut self, number: u32) {
        self.current_thread = ptr::null_mut();
        self.next_thread = ptr::null_mut();
        self.idle_thread = ptr::null_mut();
        self.number = number;
        self.lock = 0;
        self.gdt = ptr::null_mut();
        self.tss = ptr::null_mut();
        self.dpc_queue_depth = 0;
        self.dpc_lock = 0;
        self.dpc_requests_per_loop = 0;
        self.quantum_end = 0;
        self.ready_summary = 0;
        for entry in self.dispatcher_ready_list_head.iter_mut() {
            entry.initialize();
        }
        self.dpc_list_entry.initialize();
        self.interrupt_count = 0;
        self.kernel_time = 0;
        self.user_time = 0;
        self.interrupt_time = 0;
        self.current_irql = PASSIVE_LEVEL;
        self.suspended_domain = 0;
    }
}

// ============================================================
// KPCR - Processor Control Region
// ============================================================

#[repr(C)]
pub struct Kpcr {
    pub current_thread: *mut Kthread,
    pub prcb: *mut Kprcb,
    pub number: u32,
}

unsafe impl Send for Kpcr {}
unsafe impl Sync for Kpcr {}

// ============================================================
// Global dispatcher state
// ============================================================

/// Per-processor PRCB array
pub static mut KI_PRCB: [Kprcb; 64] = unsafe { mem::zeroed() };

/// Idle threads per processor
pub static mut KI_IDLE_THREAD: [Kthread; 64] = unsafe { mem::zeroed() };

/// Ready summary across all processors (for migration)
pub static KI_READY_SUMMARY: AtomicUsize = AtomicUsize::new(0);

/// Global dispatcher lock
pub static KI_DISPATCHER_LOCK: AtomicUsize = AtomicUsize::new(0);

/// Tick count
pub static mut KI_TICK_COUNT: u64 = 0;

/// DPC interrupt requested flag
pub static KI_DPC_INTERRUPT_REQUESTED: AtomicBool = AtomicBool::new(false);

/// Number of active processors
pub static KE_NUMBER_PROCESSORS: AtomicUsize = AtomicUsize::new(1);

// ============================================================
// Spin lock helpers
// ============================================================

#[inline]
pub fn acquire_spin_lock(lock: &mut KspinLock) {
    unsafe {
        let lock_ptr = lock as *mut KspinLock;
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
}

#[inline]
pub fn release_spin_lock(lock: &mut KspinLock) {
    unsafe {
        let lock_ptr = lock as *mut KspinLock;
        asm!(
            "lock btr qword ptr [{0}], 0",
            in(reg) lock_ptr,
            options(nostack, nomem),
        );
    }
}

pub struct KspinLockGuard<'a> {
    lock: &'a mut KspinLock,
}

impl<'a> KspinLockGuard<'a> {
    pub fn new(lock: &'a mut KspinLock) -> Self {
        acquire_spin_lock(lock);
        Self { lock }
    }
}

impl<'a> Drop for KspinLockGuard<'a> {
    fn drop(&mut self) {
        release_spin_lock(self.lock);
    }
}

// ============================================================
// Dispatcher lock
// ============================================================

#[inline]
pub fn ki_acquire_dispatcher_lock() {
    unsafe {
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
}

#[inline]
pub fn ki_release_dispatcher_lock() {
    unsafe {
        let lock_ptr = &KI_DISPATCHER_LOCK as *const AtomicUsize as *mut u64;
        asm!(
            "lock btr qword ptr [{0}], 0",
            in(reg) lock_ptr,
            options(nostack, nomem),
        );
    }
}

// ============================================================
// Offset helpers
// ============================================================

#[inline]
fn offset_of_wait_list_entry_in_thread() -> usize {
    unsafe {
        let dummy: Kthread = mem::zeroed();
        let base = &dummy as *const Kthread as usize;
        let field = &dummy.wait_list_entry as *const ListEntry as usize;
        field - base
    }
}

#[inline]
fn offset_of_wait_list_entry_in_wait_block() -> usize {
    unsafe {
        let dummy: KwaitBlock = mem::zeroed();
        let base = &dummy as *const KwaitBlock as usize;
        let field = &dummy.wait_list_entry as *const ListEntry as usize;
        field - base
    }
}

/// Given a ListEntry* that is inside a Kthread's wait_list_entry, recover the Kthread*.
#[inline]
unsafe fn thread_from_wait_list_entry(entry: *mut ListEntry) -> *mut Kthread {
    let addr = entry as usize - offset_of_wait_list_entry_in_thread();
    addr as *mut Kthread
}

/// Given a ListEntry* that is inside a KwaitBlock's wait_list_entry, recover the KwaitBlock*.
#[inline]
pub(crate) unsafe fn wait_block_from_wait_list_entry(entry: *mut ListEntry) -> *mut KwaitBlock {
    let addr = entry as usize - offset_of_wait_list_entry_in_wait_block();
    addr as *mut KwaitBlock
}

// ============================================================
// Ready queue management
// ============================================================

/// Insert a thread into the per-priority ready queue of a PRCB.
pub fn ki_dispatcher_ready_insert_thread(thread: &mut Kthread, prcb: &mut Kprcb) {
    let priority = thread.priority as usize;
    if priority >= MAXIMUM_PRIORITY_LEVEL {
        return;
    }

    let ready_list = &mut prcb.dispatcher_ready_list_head[priority];
    ready_list.insert_tail(&mut thread.wait_list_entry);

    prcb.ready_summary |= 1 << priority;
    KI_READY_SUMMARY.fetch_or(1 << priority, Ordering::Release);
}

/// Select and remove the highest-priority ready thread from a PRCB.
/// Returns the thread pointer or null if no thread is ready.
pub fn ki_select_thread_from_ready_queue(prcb: &mut Kprcb) -> *mut Kthread {
    let ready_summary = prcb.ready_summary;
    if ready_summary == 0 {
        return ptr::null_mut();
    }

    let priority = 31 - ready_summary.leading_zeros() as usize;
    if priority >= MAXIMUM_PRIORITY_LEVEL {
        return ptr::null_mut();
    }

    let ready_list = &mut prcb.dispatcher_ready_list_head[priority];
    if ready_list.is_empty() {
        prcb.ready_summary &= !(1 << priority);
        KI_READY_SUMMARY.fetch_and(!(1 << priority), Ordering::Release);
        return ptr::null_mut();
    }

    let entry = ready_list.flink;
    unsafe {
        ListEntry::remove(&mut *entry);
    }

    if ready_list.is_empty() {
        prcb.ready_summary &= !(1 << priority);
        KI_READY_SUMMARY.fetch_and(!(1 << priority), Ordering::Release);
    }

    unsafe { thread_from_wait_list_entry(entry) }
}

/// Make a thread ready for execution on its preferred processor.
pub unsafe fn ki_ready_thread(thread: *mut Kthread) {
    if thread.is_null() {
        return;
    }

    let t = &mut *thread;
    let processor = t.processor as usize;
    let prcb = &mut KI_PRCB[processor];

    t.state = KthreadState::Ready;
    ki_dispatcher_ready_insert_thread(t, prcb);
}

// ============================================================
// Context switch
// ============================================================

/// Swap context from the current thread to a new thread.
pub fn ki_swap_context(new_thread: *mut Kthread, prcb: &mut Kprcb) {
    unsafe {
        let old_thread = prcb.current_thread;
        if old_thread == new_thread {
            return;
        }

        prcb.current_thread = new_thread;

        if !old_thread.is_null() {
            let old = &mut *old_thread;
            old.context_switches += 1;
            if old.state == KthreadState::Running {
                old.state = KthreadState::Ready;
                ki_dispatcher_ready_insert_thread(old, prcb);
            }
        }

        if !new_thread.is_null() {
            let new = &mut *new_thread;
            new.state = KthreadState::Running;
            new.processor = prcb.number as u8;
        }

        ki_swap_context_internal(old_thread, new_thread, prcb);
    }
}

/// Architecture-specific context switch: save/restore callee-saved registers,
/// swap kernel stack pointers, optionally swap CR3 for address space change.
pub unsafe fn ki_swap_context_internal(
    old_thread: *mut Kthread,
    new_thread: *mut Kthread,
    prcb: &mut Kprcb,
) {
    if old_thread.is_null() || new_thread.is_null() {
        return;
    }

    let old = &*old_thread;
    let new = &*new_thread;

    // Save current kernel stack to old thread
    let mut current_rsp: u64;
    asm!("mov {0}, rsp", out(reg) current_rsp, options(nostack, nomem));

    let old_mut = &mut *old_thread;
    old_mut.initial_stack = current_rsp as Pvoid;

    // Load new thread's kernel stack
    if !new.initial_stack.is_null() {
        asm!(
            "mov rsp, {0}",
            in(reg) new.initial_stack as u64,
            options(nostack, nomem),
        );
    }

    // Switch address space if threads belong to different processes
    let old_process = old.kprocess;
    let new_process = new.kprocess;
    if old_process != new_process && !new_process.is_null() {
        let new_proc = &*new_process;
        if new_proc.directory_table_base != 0 {
            asm!(
                "mov cr3, {0}",
                in(reg) new_proc.directory_table_base,
                options(nostack, nomem),
            );
        }
    }

    // If DPC interrupt is pending, handle it
    if KI_DPC_INTERRUPT_REQUESTED.swap(false, Ordering::Acquire) {
        ki_dispatch_interrupt(prcb);
    }
}

// ============================================================
// Dispatch interrupt and timer
// ============================================================

pub unsafe fn ki_dispatch_interrupt(prcb: &mut Kprcb) {
    let current_time = ki_query_performance_counter();
    if current_time >= prcb.quantum_end {
        ki_dispatch_timer_interrupt(prcb);
    }

    // Process pending DPCs
    ki_process_dpc_queue(prcb);
}

pub unsafe fn ki_process_dpc_queue(prcb: &mut Kprcb) {
    while prcb.dpc_queue_depth > 0 {
        prcb.dpc_queue_depth -= 1;
        // In a full implementation: dequeue DPC from dpc_list_entry, invoke its routine
    }
}

pub fn ki_query_performance_counter() -> u64 {
    unsafe { core::arch::x86_64::_rdtsc() }
}

pub unsafe fn ki_dispatch_timer_interrupt(prcb: &mut Kprcb) {
    let current_time = ki_query_performance_counter();
    prcb.quantum_end = current_time + KI_THREAD_QUANTUM * 10_000_000;
    KI_TICK_COUNT += 1;

    // Check if current thread should be preempted
    if let Some(current) = prcb.current_thread.as_mut() {
        if current.preemption_disable_count > 0 {
            return;
        }
        let ready_summary = prcb.ready_summary;
        if ready_summary != 0 {
            let highest_priority = 31 - ready_summary.leading_zeros() as i32;
            if highest_priority > current.priority as i32 {
                ki_reschedule_thread(prcb);
            }
        }
    }
}

pub unsafe fn ki_reschedule_thread(prcb: &mut Kprcb) {
    let next_thread = ki_select_thread_from_ready_queue(prcb);
    if next_thread.is_null() {
        return;
    }

    prcb.next_thread = next_thread;

    let next = &mut *next_thread;
    next.state = KthreadState::Standby;

    if let Some(current) = prcb.current_thread.as_mut() {
        if current.state == KthreadState::Running {
            current.state = KthreadState::Ready;
            ki_dispatcher_ready_insert_thread(current, prcb);
        }
    }

    ki_swap_context(next_thread, prcb);
}

// ============================================================
// Wait satisfaction helpers
// ============================================================

unsafe fn ki_wait_test(header: &DispatcherHeader, wait_type: u16) -> bool {
    match wait_type {
        0 => header.is_signaled(),
        1 => header.is_signaled(),
        _ => false,
    }
}

// ============================================================
// KeWaitForSingleObject
// ============================================================

pub unsafe fn ke_wait_for_single_object(
    object: *mut DispatcherHeader,
    wait_reason: KwaitReason,
    wait_mode: u8,
    alertable: Boolean,
    timeout: *mut i64,
) -> NtStatus {
    let current_thread = KI_PRCB[0].current_thread;
    if current_thread.is_null() {
        return STATUS_SUCCESS;
    }

    let thread = &mut *current_thread;

    // Check if the object is already signaled
    if (*object).is_signaled() {
        ki_satisfy_wait(object);
        return STATUS_SUCCESS;
    }

    // Check for zero timeout
    if !timeout.is_null() && *timeout == 0 {
        return STATUS_TIMEOUT;
    }

    let mut wait_block: KwaitBlock = mem::zeroed();
    wait_block.initialize();
    wait_block.thread = current_thread;
    wait_block.object = object;
    wait_block.wait_type = 0;

    // Set thread to Waiting state
    thread.state = KthreadState::Waiting;
    thread.wait_type = 0;
    thread.wait_irql = KI_PRCB[0].current_irql;
    thread.wait_mode = wait_mode;
    thread.alertable = alertable;
    thread.wait_status = STATUS_SUCCESS;
    thread.wait_block_list = &mut wait_block as *mut KwaitBlock;

    // Insert into object's wait list
    wait_block.wait_list_entry.initialize();
    (*object).wait_list_entry.insert_head(&mut wait_block.wait_list_entry);

    // Reschedule
    ki_reschedule_thread(&mut KI_PRCB[0]);

    thread.wait_status
}

// ============================================================
// KeWaitForMultipleObjects
// ============================================================

pub unsafe fn ke_wait_for_multiple_objects(
    count: u32,
    object: *const *mut DispatcherHeader,
    wait_type: u16,
    wait_reason: KwaitReason,
    wait_mode: u8,
    alertable: Boolean,
    timeout: *mut i64,
    wait_block_array: *mut KwaitBlock,
) -> NtStatus {
    let current_thread = KI_PRCB[0].current_thread;
    if current_thread.is_null() {
        return STATUS_SUCCESS;
    }

    if count == 0 || count > MAXIMUM_WAIT_OBJECTS as u32 {
        return STATUS_INVALID_PARAMETER;
    }

    let thread = &mut *current_thread;
    let wait_blocks = if !wait_block_array.is_null() {
        wait_block_array
    } else {
        static mut STACK_WAIT_BLOCKS: [KwaitBlock; MAXIMUM_WAIT_OBJECTS] =
            unsafe { mem::zeroed() };
        &mut STACK_WAIT_BLOCKS[0]
    };

    // Initialize wait blocks and check initial signal state
    let mut all_signaled = true;
    let mut first_signaled_index = -1i32;
    for i in 0..count as usize {
        let wb = &mut *wait_blocks.add(i);
        wb.initialize();
        wb.thread = current_thread;
        wb.object = *object.add(i);
        wb.wait_type = wait_type;
        wb.wait_key = i as u16;

        if i > 0 {
            let prev = &mut *wait_blocks.add(i - 1);
            prev.next_wait_block = wb;
        }

        if (*wb.object).is_signaled() {
            if first_signaled_index < 0 {
                first_signaled_index = i as i32;
            }
        } else {
            all_signaled = false;
        }
    }

    // Satisfy immediately if possible
    match wait_type {
        WAIT_TYPE_ANY => {
            if first_signaled_index >= 0 {
                thread.wait_block_list = wait_blocks;
                thread.wait_status = STATUS_SUCCESS;
                ki_satisfy_wait(*object.add(first_signaled_index as usize));
                return STATUS_SUCCESS;
            }
        }
        WAIT_TYPE_ALL => {
            if all_signaled {
                thread.wait_block_list = wait_blocks;
                thread.wait_status = STATUS_SUCCESS;
                for i in 0..count as usize {
                    ki_satisfy_wait(*object.add(i));
                }
                return STATUS_SUCCESS;
            }
        }
        _ => return STATUS_INVALID_PARAMETER,
    }

    // Zero timeout check
    if !timeout.is_null() && *timeout == 0 {
        return STATUS_TIMEOUT;
    }

    // Set thread to Waiting state
    thread.state = KthreadState::Waiting;
    thread.wait_type = wait_type;
    thread.wait_irql = KI_PRCB[0].current_irql;
    thread.wait_mode = wait_mode;
    thread.alertable = alertable;
    thread.wait_status = STATUS_SUCCESS;
    thread.wait_block_list = wait_blocks;

    // Insert wait blocks into each object's wait list
    for i in 0..count as usize {
        let wb = &mut *wait_blocks.add(i);
        let obj = &mut *wb.object;
        wb.wait_list_entry.initialize();
        obj.wait_list_entry.insert_head(&mut wb.wait_list_entry);
    }

    ki_reschedule_thread(&mut KI_PRCB[0]);
    thread.wait_status
}

// ============================================================
// KeReleaseMutant
// ============================================================

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

    let current_thread = KI_PRCB[0].current_thread;
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
        m.header.signal_state += 1;
    }

    // If mutant is now signaled, release ownership and wake a waiter
    if m.header.signal_state > 0 {
        m.owner_thread = ptr::null_mut();
        m.header.inserted = DISPATCHER_OBJECT_NOT_INSERTED;

        if !m.header.wait_list_entry.is_empty() {
            let wait_entry = m.header.wait_list_entry.flink;
            let wb = wait_block_from_wait_list_entry(wait_entry);
            ki_satisfy_wait(&mut m.header as *mut DispatcherHeader);
            ListEntry::remove_entry(&mut (*wb).wait_list_entry);
            let waiting_thread = (*wb).thread;
            if !waiting_thread.is_null() {
                (*waiting_thread).wait_status = STATUS_SUCCESS;
                (*waiting_thread).wait_block_list = ptr::null_mut();
                ki_ready_thread(waiting_thread);
            }
        }
    }

    ki_release_dispatcher_lock();
    status
}

// ============================================================
// KeReleaseSemaphore
// ============================================================

pub unsafe fn ke_release_semaphore(
    semaphore: *mut Ksemaphore,
    increment: i32,
    adjustment_limit: i32,
    wait: Boolean,
) -> NtStatus {
    if semaphore.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let sem = &mut *semaphore;

    ki_acquire_dispatcher_lock();

    if sem.header.r#type != DISPATCHER_OBJECT_TYPE_SEMAPHORE {
        ki_release_dispatcher_lock();
        return STATUS_OBJECT_TYPE_MISMATCH;
    }

    let new_count = sem.header.signal_state + increment;
    if new_count > adjustment_limit {
        sem.header.signal_state = adjustment_limit;
        ki_release_dispatcher_lock();
        return STATUS_SEMAPHORE_LIMIT_EXCEEDED;
    }

    sem.header.signal_state = new_count;

    // Wake a waiting thread if available
    if !sem.header.wait_list_entry.is_empty() && sem.header.signal_state > 0 {
        let wait_entry = sem.header.wait_list_entry.flink;
        let wb = wait_block_from_wait_list_entry(wait_entry);

        ListEntry::remove_entry(&mut (*wb).wait_list_entry);
        let waiting_thread = (*wb).thread;
        if !waiting_thread.is_null() {
            (*waiting_thread).wait_block_list = ptr::null_mut();
            (*waiting_thread).wait_status = STATUS_SUCCESS;
            ki_ready_thread(waiting_thread);
        }

        sem.header.signal_state -= 1;
    }

    ki_release_dispatcher_lock();
    STATUS_SUCCESS
}

// ============================================================
// KeSetEvent
// ============================================================

pub unsafe fn ke_set_event(
    event: *mut Kevent,
    increment: i32,
    wait: Boolean,
) -> NtStatus {
    if event.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let ev = &mut *event;

    ki_acquire_dispatcher_lock();

    if ev.header.r#type != DISPATCHER_OBJECT_TYPE_EVENT {
        ki_release_dispatcher_lock();
        return STATUS_OBJECT_TYPE_MISMATCH;
    }

    ev.header.signal_state += increment;

    // Wake waiting threads
    if ev.header.signal_state > 0 && !ev.header.wait_list_entry.is_empty() {
        let mut current_entry = ev.header.wait_list_entry.flink;

        while current_entry != &ev.header.wait_list_entry as *const ListEntry as *mut ListEntry {
            let wb = wait_block_from_wait_list_entry(current_entry);
            let next = (*current_entry).flink;

            // For WaitAny, wake the first thread and stop
            let wait_type = (*wb).wait_type;
            ListEntry::remove_entry(&mut (*wb).wait_list_entry);
            let waiting_thread = (*wb).thread;
            if !waiting_thread.is_null() {
                (*waiting_thread).wait_block_list = ptr::null_mut();
                (*waiting_thread).wait_status = STATUS_SUCCESS;
                ki_ready_thread(waiting_thread);
            }

            if wait_type == WAIT_TYPE_ANY {
                break;
            }

            current_entry = next;
        }
    }

    ki_release_dispatcher_lock();
    STATUS_SUCCESS
}

// ============================================================
// KeResetEvent
// ============================================================

pub unsafe fn ke_reset_event(event: *mut Kevent, previous_state: *mut i32) -> NtStatus {
    if event.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let ev = &mut *event;
    ki_acquire_dispatcher_lock();
    if !previous_state.is_null() {
        *previous_state = ev.header.signal_state;
    }
    ev.header.signal_state = 0;
    ki_release_dispatcher_lock();
    STATUS_SUCCESS
}

// ============================================================
// Object initialization helpers
// ============================================================

pub unsafe fn ke_initialize_event(event: *mut Kevent, event_type: u8, initial_state: i32) {
    if event.is_null() {
        return;
    }
    (*event).initialize(event_type, initial_state);
}

pub unsafe fn ke_initialize_semaphore(semaphore: *mut Ksemaphore, count: i32, limit: i32) {
    if semaphore.is_null() {
        return;
    }
    (*semaphore).initialize(count, limit);
}

pub unsafe fn ke_initialize_mutant(mutant: *mut Kmutant, initial_owner: Boolean) {
    if mutant.is_null() {
        return;
    }
    (*mutant).initialize();
    let current_thread = KI_PRCB[0].current_thread;
    if initial_owner != 0 && !current_thread.is_null() {
        (*mutant).owner_thread = current_thread;
        (*mutant).header.signal_state = 0;
        (*mutant).header.inserted = DISPATCHER_OBJECT_INSERTED;
    } else {
        (*mutant).header.signal_state = 1;
    }
}

pub unsafe fn ke_initialize_queue(queue: *mut Kqueue, count: i32) {
    if queue.is_null() {
        return;
    }
    (*queue).initialize(count);
}

// ============================================================
// Object query helpers
// ============================================================

pub unsafe fn ke_read_state_event(event: *mut Kevent) -> i32 {
    if event.is_null() { 0 } else { (*event).header.signal_state }
}

pub unsafe fn ke_read_state_semaphore(semaphore: *mut Ksemaphore) -> i32 {
    if semaphore.is_null() { 0 } else { (*semaphore).header.signal_state }
}

pub unsafe fn ke_read_state_mutant(mutant: *mut Kmutant) -> i32 {
    if mutant.is_null() { 0 } else { (*mutant).header.signal_state }
}

pub unsafe fn ke_query_mutant(mutant: *mut Kmutant) -> i32 {
    if mutant.is_null() { 0 } else { if (*mutant).owner_thread == KI_PRCB[0].current_thread { 1 } else { 0 } }
}

pub unsafe fn ke_query_owner_mutant(mutant: *mut Kmutant) -> *mut Kthread {
    if mutant.is_null() { ptr::null_mut() } else { (*mutant).owner_thread }
}

pub unsafe fn ke_query_event(event: *mut Kevent) -> bool {
    if event.is_null() { false } else { (*event).header.is_signaled() }
}

// ============================================================
// Queue operations
// ============================================================

pub unsafe fn ki_insert_queue(queue: *mut Kqueue, entry: *mut ListEntry, tail: Boolean) {
    let q = &mut *queue;

    ki_acquire_dispatcher_lock();

    if tail != 0 {
        q.entry_list.insert_tail(&mut *entry);
    } else {
        q.entry_list.insert_head(&mut *entry);
    }
    q.queue_count += 1;

    // Wake a waiting thread if available
    if q.thread_count > 0 && !q.wait_list.is_empty() {
        let wait_entry = q.wait_list.flink;
        let wb = wait_block_from_wait_list_entry(wait_entry);
        ListEntry::remove_entry(&mut (*wb).wait_list_entry);
        q.thread_count -= 1;
        q.queue_count -= 1;

        let waiting_thread = (*wb).thread;
        if !waiting_thread.is_null() {
            (*waiting_thread).wait_block_list = ptr::null_mut();
            (*waiting_thread).wait_status = STATUS_SUCCESS;
            ki_ready_thread(waiting_thread);
        }
    }

    ki_release_dispatcher_lock();
}

pub unsafe fn ki_remove_queue(queue: *mut Kqueue) -> *mut ListEntry {
    let q = &mut *queue;

    ki_acquire_dispatcher_lock();

    if q.entry_list.is_empty() {
        ki_release_dispatcher_lock();
        return ptr::null_mut();
    }

    let entry = q.entry_list.flink;
    ListEntry::remove(&mut *entry);
    q.queue_count -= 1;

    ki_release_dispatcher_lock();
    entry
}

// ============================================================
// Thread suspension / freezing
// ============================================================

pub unsafe fn ki_suspend_thread(thread: *mut Kthread) -> NtStatus {
    if thread.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let t = &mut *thread;

    ki_acquire_dispatcher_lock();
    t.suspend_count += 1;
    ki_release_dispatcher_lock();

    STATUS_SUCCESS
}

pub unsafe fn ki_resume_thread(thread: *mut Kthread) -> NtStatus {
    if thread.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let t = &mut *thread;

    ki_acquire_dispatcher_lock();
    if t.suspend_count > 0 {
        t.suspend_count -= 1;
        if t.suspend_count == 0 {
            ki_ready_thread(thread);
        }
    }
    ki_release_dispatcher_lock();

    STATUS_SUCCESS
}

pub unsafe fn ke_force_resume_thread(thread: *mut Kthread) -> i32 {
    if thread.is_null() {
        return 0;
    }
    let t = &mut *thread;
    let previous_count = t.suspend_count;

    ki_acquire_dispatcher_lock();
    t.suspend_count = 0;
    if previous_count > 0 {
        ki_ready_thread(thread);
    }
    ki_release_dispatcher_lock();

    previous_count
}

pub unsafe fn ki_freeze_thread(thread: *mut Kthread) -> NtStatus {
    if thread.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    ki_acquire_dispatcher_lock();
    (*thread).freeze_count += 1;
    ki_release_dispatcher_lock();
    STATUS_SUCCESS
}

pub unsafe fn ki_thaw_thread(thread: *mut Kthread) -> NtStatus {
    if thread.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    ki_acquire_dispatcher_lock();
    if (*thread).freeze_count > 0 {
        (*thread).freeze_count -= 1;
        if (*thread).freeze_count == 0 {
            ki_ready_thread(thread);
        }
    }
    ki_release_dispatcher_lock();
    STATUS_SUCCESS
}

pub unsafe fn ki_is_thread_suspended(thread: *mut Kthread) -> bool {
    !thread.is_null() && (*thread).suspend_count > 0
}

pub unsafe fn ki_is_thread_frozen(thread: *mut Kthread) -> bool {
    !thread.is_null() && (*thread).freeze_count > 0
}

pub unsafe fn ki_is_thread_running(thread: *mut Kthread) -> bool {
    !thread.is_null() && (*thread).state == KthreadState::Running
}

pub unsafe fn ki_is_thread_runnable(thread: *mut Kthread) -> bool {
    if thread.is_null() {
        return false;
    }
    let t = &*thread;
    t.state != KthreadState::Terminated
        && t.state != KthreadState::Initialized
        && !ki_is_thread_suspended(thread)
        && !ki_is_thread_frozen(thread)
}

// ============================================================
// Thread creation
// ============================================================

pub unsafe fn ke_create_thread(
    start_address: Pvoid,
    parameter: Pvoid,
    stack_size: usize,
    process: *mut Kprocess,
    ideal_processor: u8,
) -> *mut Kthread {
    let thread_size = mem::size_of::<Kthread>();
    let thread = alloc::alloc::alloc(
        core::alloc::Layout::from_size_align_unchecked(thread_size, 16),
    ) as *mut Kthread;

    if thread.is_null() {
        return ptr::null_mut();
    }

    let t = &mut *thread;
    t.initialize();

    let alloc_size = stack_size.max(4096);
    let stack = alloc::alloc::alloc(
        core::alloc::Layout::from_size_align_unchecked(alloc_size, 16),
    );
    if stack.is_null() {
        alloc::alloc::dealloc(
            thread as *mut u8,
            core::alloc::Layout::from_size_align_unchecked(thread_size, 16),
        );
        return ptr::null_mut();
    }

    t.initial_stack = stack.add(alloc_size) as Pvoid;
    t.stack_limit = stack as Pvoid;
    t.stack_base = stack.add(alloc_size) as Pvoid;
    t.kernel_stack = stack as Pvoid;
    t.process = process;
    t.kprocess = process as *mut Kprocess;
    t.priority = 8;
    t.base_priority = 8;
    t.ideal_processor = ideal_processor;
    t.processor = ideal_processor;
    t.user_affinity = 1u64 << ideal_processor;
    t.system_affinity = 0xFFFFFFFFFFFFFFFFu64;
    t.affinity = t.user_affinity;
    t.quantum_target = KI_THREAD_QUANTUM;
    t.alertable = 1;
    t.state = KthreadState::Ready;

    let prcb = &mut KI_PRCB[ideal_processor as usize];
    ki_dispatcher_ready_insert_thread(t, prcb);

    thread
}

pub unsafe fn ke_terminate_thread(thread: *mut Kthread) {
    if thread.is_null() {
        return;
    }

    let t = &mut *thread;
    ki_acquire_dispatcher_lock();

    t.state = KthreadState::Terminated;
    t.header.signal_state = 1;

    if !t.wait_list_entry.is_empty() {
        ListEntry::remove_entry(&mut t.wait_list_entry);
    }

    let processor = t.processor as usize;
    if processor < MAXIMUM_PROCESSORS as usize {
        let prcb = &mut KI_PRCB[processor];
        if prcb.current_thread == thread {
            prcb.current_thread = ptr::null_mut();
            ki_reschedule_thread(prcb);
        }
    }

    ki_release_dispatcher_lock();
}

// ============================================================
// Thread priority
// ============================================================

pub unsafe fn ke_set_priority_thread(thread: *mut Kthread, priority: i8) -> i8 {
    if thread.is_null() {
        return 0;
    }
    let t = &mut *thread;
    let old_priority = t.priority;

    ki_acquire_dispatcher_lock();
    t.priority = priority.min(MAX_THREAD_PRIORITY).max(MIN_THREAD_PRIORITY);
    t.base_priority = t.priority;

    if t.state == KthreadState::Ready {
        if !t.wait_list_entry.is_empty() {
            ListEntry::remove_entry(&mut t.wait_list_entry);
        }
        let processor = t.processor as usize;
        let prcb = &mut KI_PRCB[processor];
        ki_dispatcher_ready_insert_thread(t, prcb);
    }

    ki_release_dispatcher_lock();
    old_priority
}

pub unsafe fn ke_query_priority_thread(thread: *mut Kthread) -> i8 {
    if thread.is_null() { 0 } else { (*thread).priority }
}

pub unsafe fn ke_set_base_priority_thread(thread: *mut Kthread, base_priority: i8) -> i8 {
    if thread.is_null() {
        return 0;
    }
    let t = &mut *thread;
    let old_base = t.base_priority;

    ki_acquire_dispatcher_lock();
    t.base_priority = base_priority.min(MAX_THREAD_PRIORITY).max(MIN_THREAD_PRIORITY);
    t.priority = t.base_priority;

    if t.state == KthreadState::Ready {
        if !t.wait_list_entry.is_empty() {
            ListEntry::remove_entry(&mut t.wait_list_entry);
        }
        let processor = t.processor as usize;
        let prcb = &mut KI_PRCB[processor];
        ki_dispatcher_ready_insert_thread(t, prcb);
    }

    ki_release_dispatcher_lock();
    old_base
}

// ============================================================
// Thread affinity
// ============================================================

pub unsafe fn ki_set_affinity_thread(thread: *mut Kthread, affinity: Kaffinity) {
    if thread.is_null() {
        return;
    }
    let t = &mut *thread;

    ki_acquire_dispatcher_lock();
    t.user_affinity = affinity;
    t.affinity = affinity;

    let mut processor = 0u8;
    for i in 0..32u8 {
        if affinity & (1 << i) != 0 {
            processor = i;
            break;
        }
    }
    t.ideal_processor = processor;
    t.processor = processor;

    if t.state == KthreadState::Ready {
        if !t.wait_list_entry.is_empty() {
            ListEntry::remove_entry(&mut t.wait_list_entry);
        }
        let prcb = &mut KI_PRCB[processor as usize];
        ki_dispatcher_ready_insert_thread(t, prcb);
    }

    ki_release_dispatcher_lock();
}

pub unsafe fn ke_set_affinity_thread(thread: *mut Kthread, affinity: Kaffinity) -> Kaffinity {
    if thread.is_null() {
        return 0;
    }
    let old = (*thread).affinity;
    ki_set_affinity_thread(thread, affinity);
    old
}

pub unsafe fn ke_query_affinity_thread(thread: *mut Kthread) -> Kaffinity {
    if thread.is_null() { 0 } else { (*thread).affinity }
}

// ============================================================
// Process affinity
// ============================================================

pub unsafe fn ke_set_affinity_process(process: *mut Kprocess, affinity: Kaffinity) -> NtStatus {
    if process.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    acquire_spin_lock(&mut (*process).affinity_lock);
    (*process).affinity = affinity;
    release_spin_lock(&mut (*process).affinity_lock);
    STATUS_SUCCESS
}

pub unsafe fn ke_query_affinity_process(process: *mut Kprocess) -> Kaffinity {
    if process.is_null() { 0 } else { (*process).affinity }
}

// ============================================================
// Process creation / termination
// ============================================================

pub unsafe fn ki_create_process() -> *mut Kprocess {
    let size = mem::size_of::<Kprocess>();
    let process = alloc::alloc::alloc(
        core::alloc::Layout::from_size_align_unchecked(size, 16),
    ) as *mut Kprocess;

    if process.is_null() {
        return ptr::null_mut();
    }

    (*process).initialize();
    process
}

pub unsafe fn ke_terminate_process(process: *mut Kprocess) -> NtStatus {
    if process.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    ki_acquire_dispatcher_lock();
    (*process).header.signal_state = 1;
    (*process).process_flags |= 0x80000000;
    ki_release_dispatcher_lock();

    STATUS_SUCCESS
}

pub unsafe fn ke_stack_attach_process(
    process: *mut Kprocess,
    attach_state: *mut u64,
) {
    if process.is_null() || attach_state.is_null() {
        return;
    }

    let thread = KI_PRCB[0].current_thread;
    if thread.is_null() {
        return;
    }

    let t = &mut *thread;
    *attach_state = t.process as u64;
    t.process = process;

    let dir_base = (*process).directory_table_base;
    if dir_base != 0 {
        asm!("mov cr3, {0}", in(reg) dir_base, options(nostack, nomem));
    }
}

pub unsafe fn ke_unstack_detach_process(attach_state: *mut u64) {
    if attach_state.is_null() {
        return;
    }

    let thread = KI_PRCB[0].current_thread;
    if thread.is_null() {
        return;
    }

    let t = &mut *thread;
    let previous = *attach_state as *mut Kprocess;
    t.process = previous;

    if !previous.is_null() {
        let dir_base = (*previous).directory_table_base;
        if dir_base != 0 {
            asm!("mov cr3, {0}", in(reg) dir_base, options(nostack, nomem));
        }
    }
}

// ============================================================
// IRQL management
// ============================================================

pub fn ke_get_current_irql() -> Irql {
    unsafe { KI_PRCB[0].current_irql }
}

pub unsafe fn ke_raise_irql(new_irql: Irql) -> Irql {
    let old = KI_PRCB[0].current_irql;
    KI_PRCB[0].current_irql = new_irql;
    old
}

pub unsafe fn ke_lower_irql(new_irql: Irql) {
    KI_PRCB[0].current_irql = new_irql;
}

pub unsafe fn ke_raise_irql_to_dpc_level() -> Irql {
    ke_raise_irql(DISPATCH_LEVEL)
}

pub unsafe fn ke_lower_irql_from_dpc_level(old_irql: Irql) {
    ke_lower_irql(old_irql);
}

// ============================================================
// PCR / current thread / current process accessors
// ============================================================

#[inline]
pub unsafe fn ki_get_pcr() -> *mut Kpcr {
    let val: u64;
    asm!("mov {0}, gs:[0]", out(reg) val, options(nostack, nomem));
    val as *mut Kpcr
}

#[inline]
pub unsafe fn ki_get_current_thread() -> *mut Kthread {
    KI_PRCB[0].current_thread
}

pub use ki_get_current_thread as ke_get_current_thread;

#[inline]
pub unsafe fn ki_get_current_process() -> *mut Kprocess {
    let t = ki_get_current_thread();
    if t.is_null() { ptr::null_mut() } else { (*t).process }
}

// ============================================================
// Thread wait list processing
// ============================================================

pub unsafe fn ki_process_thread_wait_list(header: *mut DispatcherHeader) {
    if header.is_null() {
        return;
    }
    let h = &mut *header;
    let mut current = h.wait_list_entry.flink;

    while current != &h.wait_list_entry as *const ListEntry as *mut ListEntry {
        let wb = wait_block_from_wait_list_entry(current);
        let next = (*current).flink;

        if ki_wait_test(h, (*wb).wait_type) {
            ListEntry::remove_entry(&mut (*wb).wait_list_entry);
            let waiting_thread = (*wb).thread;
            if !waiting_thread.is_null() {
                (*waiting_thread).wait_block_list = ptr::null_mut();
                (*waiting_thread).wait_status = STATUS_SUCCESS;
                ki_ready_thread(waiting_thread);
            }
        }

        current = next;
    }
}

// ============================================================
// Object signaling
// ============================================================

pub unsafe fn ki_satisfy_wait(header: *mut DispatcherHeader) {
    if header.is_null() {
        return;
    }

    match (*header).r#type {
        DISPATCHER_OBJECT_TYPE_MUTANT => {
            let m = &mut *(header as *mut Kmutant);
            m.header.signal_state -= 1;
            if m.header.signal_state == 0 {
                m.owner_thread = KI_PRCB[0].current_thread;
                m.header.inserted = DISPATCHER_OBJECT_INSERTED;
            }
        }
        DISPATCHER_OBJECT_TYPE_SEMAPHORE => {
            let sem = &mut *(header as *mut Ksemaphore);
            if sem.header.signal_state > 0 && sem.header.signal_state < sem.limit {
                sem.header.signal_state += 1;
            }
        }
        DISPATCHER_OBJECT_TYPE_EVENT => {
            let ev = &mut *(header as *mut Kevent);
            if ev.header.r#type == SYNCHRONIZATION_EVENT {
                ev.header.signal_state = 0;
            }
        }
        _ => {
            if (*header).signal_state > 0 {
                (*header).signal_state -= 1;
            }
        }
    }
}

pub unsafe fn ki_signal_object(header: *mut DispatcherHeader, increment: i32) -> NtStatus {
    if header.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    ki_acquire_dispatcher_lock();
    (*header).signal_state += increment;
    ki_process_thread_wait_list(header);
    ki_release_dispatcher_lock();

    STATUS_SUCCESS
}

// ============================================================
// DPC insertion / removal
// ============================================================

pub unsafe fn ke_insert_queue_dpc(dpc: Pvoid) -> bool {
    let prcb = &mut KI_PRCB[0];
    ki_acquire_dispatcher_lock();
    prcb.dpc_queue_depth += 1;
    KI_DPC_INTERRUPT_REQUESTED.store(true, Ordering::Release);
    ki_release_dispatcher_lock();
    true
}

pub unsafe fn ke_remove_queue_dpc(dpc: Pvoid) -> bool {
    let prcb = &mut KI_PRCB[0];
    ki_acquire_dispatcher_lock();
    let had_dpc = prcb.dpc_queue_depth > 0;
    if had_dpc {
        prcb.dpc_queue_depth -= 1;
    }
    ki_release_dispatcher_lock();
    had_dpc
}

pub unsafe fn ke_flush_queued_dpcs() {
    ki_process_dpc_queue(&mut KI_PRCB[0]);
}

// ============================================================
// Delay execution
// ============================================================

pub unsafe fn ke_delay_execution_thread(
    wait_mode: u8,
    alertable: Boolean,
    interval: *mut i64,
) -> NtStatus {
    let current_thread = KI_PRCB[0].current_thread;
    if current_thread.is_null() {
        return STATUS_SUCCESS;
    }

    if !interval.is_null() && *interval == 0 {
        return STATUS_SUCCESS;
    }

    let thread = &mut *current_thread;
    thread.state = KthreadState::Waiting;

    ki_reschedule_thread(&mut KI_PRCB[0]);
    STATUS_SUCCESS
}

// ============================================================
// Yield / processor queries
// ============================================================

pub fn ke_yield_processor() {
    unsafe {
        asm!("pause", options(nostack, nomem));
    }
}

pub unsafe fn ke_number_processors() -> u32 {
    KE_NUMBER_PROCESSORS.load(Ordering::Relaxed) as u32
}

pub unsafe fn ke_get_current_processor_number() -> u32 {
    KI_PRCB[0].number
}

pub unsafe fn ke_query_active_processors() -> Kaffinity {
    let count = ke_number_processors();
    let mut affinity: Kaffinity = 0;
    let mut i = 0u32;
    while i < count {
        affinity |= 1u64 << i;
        i += 1;
    }
    affinity
}

// ============================================================
// APC helpers
// ============================================================

pub unsafe fn ke_insert_queue_apc(thread: *mut Kthread, apc_type: u8) -> bool {
    if thread.is_null() {
        return false;
    }

    ki_acquire_dispatcher_lock();
    if apc_type == 0 {
        (*thread).apc_pending = 1;
    } else {
        (*thread).user_apc_pending = 1;
    }
    ki_release_dispatcher_lock();
    true
}

pub unsafe fn ki_check_for_apc_delivery() -> bool {
    let thread = KI_PRCB[0].current_thread;
    if thread.is_null() {
        return false;
    }
    let t = &*thread;
    t.apc_pending != 0 && t.alertable != 0 && ke_get_current_irql() == PASSIVE_LEVEL
}

pub unsafe fn ke_test_alert_thread(mode: u8) -> Boolean {
    let thread = KI_PRCB[0].current_thread;
    if thread.is_null() {
        return 0;
    }
    let t = &*thread;
    if t.alertable != 0 && t.wait_mode == mode { 1 } else { 0 }
}

// ============================================================
// Spin lock wrappers
// ============================================================

pub unsafe fn ke_acquire_spin_lock_raise_to_synch(lock: *mut KspinLock) -> Irql {
    let old_irql = ke_raise_irql(DISPATCH_LEVEL);
    acquire_spin_lock(&mut *lock);
    old_irql
}

pub unsafe fn ke_release_spin_lock(lock: *mut KspinLock, old_irql: Irql) {
    release_spin_lock(&mut *lock);
    ke_lower_irql(old_irql);
}

pub unsafe fn ke_try_to_acquire_queued_spin_lock(lock: *mut KspinLock) -> bool {
    if lock.is_null() {
        return false;
    }
    let val = core::ptr::read_volatile(lock as *const KspinLock);
    if val == 0 {
        core::ptr::write_volatile(lock as *mut KspinLock, 1);
        true
    } else {
        false
    }
}

// ============================================================
// TLB / cache flush
// ============================================================

pub unsafe fn ki_flush_range(address: u64, size: usize) {
    let mut current = address;
    let end = address + size as u64;
    while current < end {
        asm!("clflush [{0}]", in(reg) current, options(nostack));
        current += 64;
    }
    asm!("mfence", options(nostack, nomem));
}

pub unsafe fn ki_flush_tlb() {
    let cr3: u64;
    asm!("mov {0}, cr3", out(reg) cr3, options(nostack, nomem));
    asm!("mov cr3, {0}", in(reg) cr3, options(nostack, nomem));
}

pub unsafe fn ki_invalidate_range(address: Pvoid, length: usize) {
    let mut current = address as u64;
    let end = current + length as u64;
    while current < end {
        let page = current & !0xFFFu64;
        asm!("invlpg [{0}]", in(reg) page, options(nostack, nomem));
        current += 4096;
    }
}

pub unsafe fn ke_invalidate_all_caches() {
    asm!("wbinvd", options(nostack, nomem));
}

// ============================================================
// Timer helpers
// ============================================================

pub unsafe fn ke_set_timer(timer: Pvoid, due_time: u64) -> bool {
    let current_time = ki_query_performance_counter();
    KI_PRCB[0].quantum_end = current_time + due_time;
    true
}

pub unsafe fn ke_cancel_timer(timer: Pvoid) -> bool {
    true
}

pub unsafe fn ke_query_tick_count() -> u64 {
    KI_TICK_COUNT
}

// ============================================================
// Quantum computation
// ============================================================

pub fn ki_compute_thread_quantum(thread: &Kthread) -> u64 {
    let priority = thread.priority as u64;
    let base = 6u64;
    if priority >= 16 {
        base + (priority - 16) / 4
    } else {
        base.saturating_sub((16 - priority) / 8).max(1)
    }
}

// ============================================================
// KiDispatcher - Main dispatcher loop
// ============================================================

pub unsafe fn ki_dispatcher() -> ! {
    let prcb = &mut KI_PRCB[0];

    loop {
        // 1. Check for DPC/interrupt processing
        if KI_DPC_INTERRUPT_REQUESTED.swap(false, Ordering::Acquire) {
            ki_dispatch_interrupt(prcb);
        }

        // 2. Check for ready threads
        let next_thread = ki_select_thread_from_ready_queue(prcb);

        if !next_thread.is_null() {
            prcb.next_thread = next_thread;
            let next = &mut *next_thread;
            next.state = KthreadState::Standby;
            next.processor = prcb.number as u8;

            if let Some(current) = prcb.current_thread.as_mut() {
                if current.state == KthreadState::Running {
                    current.state = KthreadState::Ready;
                    ki_dispatcher_ready_insert_thread(current, prcb);
                }
            }

            ki_swap_context(next_thread, prcb);
        } else {
            // No threads ready - go idle
            prcb.current_thread = prcb.idle_thread;
            ki_idle_loop(prcb);
        }

        // 3. Process timer expiration
        let current_time = ki_query_performance_counter();
        if current_time >= prcb.quantum_end {
            ki_dispatch_timer_interrupt(prcb);
        }

        // 4. Check for APC delivery
        if let Some(current) = prcb.current_thread.as_mut() {
            if current.apc_pending != 0
                && current.alertable != 0
                && ke_get_current_irql() == PASSIVE_LEVEL
            {
                current.apc_pending = 0;
            }
        }
    }
}

/// Idle thread body - executed when no threads are ready.
pub unsafe fn ki_idle_loop(prcb: &mut Kprcb) -> ! {
    loop {
        asm!("hlt", options(nostack, nomem));
        ki_process_dpc_queue(prcb);
        ki_check_for_dispatch(prcb);
    }
}

// ============================================================
// Initialization
// ============================================================

pub unsafe fn ki_initialize_dispatcher() {
    let prcb = &mut KI_PRCB[0];
    prcb.initialize(0);
    prcb.number = 0;

    let idle_thread = &mut KI_IDLE_THREAD[0];
    idle_thread.initialize();
    idle_thread.state = KthreadState::Running;
    idle_thread.processor = 0;
    idle_thread.quantum_target = KI_THREAD_QUANTUM;

    prcb.idle_thread = idle_thread as *mut Kthread;
    prcb.current_thread = idle_thread as *mut Kthread;
    prcb.next_thread = ptr::null_mut();

    prcb.ready_summary = 0;
    KI_READY_SUMMARY.store(0, Ordering::Release);
    KI_DISPATCHER_LOCK.store(0, Ordering::Release);
    KI_TICK_COUNT = 0;

    let current_time = ki_query_performance_counter();
    prcb.quantum_end = current_time + KI_THREAD_QUANTUM * 10_000_000;
}

// ============================================================
// Dispatch checks
// ============================================================

pub unsafe fn ki_check_for_dispatch(prcb: &mut Kprcb) {
    if prcb.ready_summary != 0 {
        let highest = 31 - prcb.ready_summary.leading_zeros() as i32;
        if let Some(current) = prcb.current_thread.as_ref() {
            if highest > current.priority as i32 {
                ki_reschedule_thread(prcb);
            }
        }
    }
}

pub unsafe fn ki_exit_dispatcher(prcb: &mut Kprcb) {
    if prcb.dpc_queue_depth > 0 {
        ki_process_dpc_queue(prcb);
    }
    ki_check_for_dispatch(prcb);
}

pub unsafe fn ki_update_run_time(prcb: &mut Kprcb) {
    if let Some(current) = prcb.current_thread.as_mut() {
        current.cycle_time += 1;
        prcb.user_time += 1;
    }
}

pub unsafe fn ki_quantum_end(prcb: &mut Kprcb) {
    if let Some(current) = prcb.current_thread.as_mut() {
        if current.cycle_time >= current.quantum_target {
            current.cycle_time = 0;
            ki_reschedule_thread(prcb);
        }
    }
}

// ============================================================
// Synchronize execution
// ============================================================

pub unsafe fn ke_synchronize_execution(
    interrupt: *mut DispatcherHeader,
    synchronizer: extern "C" fn(Pvoid) -> i32,
    context: Pvoid,
) -> bool {
    if interrupt.is_null() {
        return false;
    }
    ki_acquire_dispatcher_lock();
    let result = synchronizer(context);
    ki_release_dispatcher_lock();
    result != 0
}

// ============================================================
// Thread state queries
// ============================================================

pub unsafe fn ki_is_thread_in_queue(thread: *mut Kthread) -> bool {
    !thread.is_null()
        && ((*thread).state == KthreadState::Ready || (*thread).state == KthreadState::Standby)
}

pub unsafe fn ki_is_thread_in_ready_queue(thread: *mut Kthread) -> bool {
    !thread.is_null() && (*thread).state == KthreadState::Ready
}

pub unsafe fn ki_is_thread_in_wait_queue(thread: *mut Kthread) -> bool {
    !thread.is_null() && (*thread).state == KthreadState::Waiting
}

// ============================================================
// Context switch check (IRQL DISPATCH_LEVEL)
// ============================================================

pub unsafe fn ki_check_context_switch(prcb: &mut Kprcb) {
    if prcb.ready_summary != 0 {
        let highest = 31 - prcb.ready_summary.leading_zeros() as i32;
        if let Some(current) = prcb.current_thread.as_ref() {
            if highest > current.priority as i32 {
                ki_reschedule_thread(prcb);
            }
        }
    }
}

// ============================================================
// Kernel stack switch for DPC / APC delivery
// ============================================================

pub unsafe fn ki_switch_to_stack(new_stack: Pvoid) {
    asm!(
        "mov rsp, {0}",
        in(reg) new_stack as u64,
        options(nostack, nomem),
    );
}
