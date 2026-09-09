/// KiInterrupt - Windows 10 Interrupt Descriptor Table and Interrupt Dispatching
///
/// IDT setup, ISR registration, interrupt object management, IRQL enforcement,
/// APC delivery, and EOI handling matching ntoskrnl.exe behavior.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 8
///   - WRK: ntoskrnl/ke/i386/ixidt*, ki*int.asm, irqobj.c
///   - ReactOS: ke/i386/idt.c, ke/irqobj.c

use core::arch::asm;
use core::mem;
use core::ptr;
use core::ffi::c_void;
use core::sync::atomic::{AtomicBool, AtomicUsize, Ordering};

use crate::types::*;
use super::dispatcher::*;

// ============================================================
// IDT Structures (x86-64)
// ============================================================

/// 64-bit IDT gate descriptor (16 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IdtEntry {
    pub offset_low: u16,
    pub selector: u16,
    pub ist: u8,
    pub type_attr: u8,
    pub offset_mid: u16,
    pub offset_high: u32,
    pub zero: u32,
}

unsafe impl Send for IdtEntry {}
unsafe impl Sync for IdtEntry {}

impl IdtEntry {
    pub const fn empty() -> Self {
        Self {
            offset_low: 0,
            selector: 0,
            ist: 0,
            type_attr: 0,
            offset_mid: 0,
            offset_high: 0,
            zero: 0,
        }
    }

    /// Build a 64-bit interrupt gate descriptor.
    /// type_attr bits: P(1)=Present, DPL(2)=DescriptorPrivilegeLevel, S(1)=System,
    ///                GateType(4)=0xE for 64-bit interrupt gate.
    pub fn from_handler(handler: u64, selector: u16, ist: u8, dpl: u8) -> Self {
        let present: u8 = 0x80; // P=1
        let system: u8 = 0x00;  // S=0 for system gates
        let gate_type: u8 = 0x0E; // 64-bit interrupt gate
        let type_attr = present | ((dpl & 0x03) << 5) | system | gate_type;

        Self {
            offset_low: (handler & 0xFFFF) as u16,
            selector,
            ist,
            type_attr,
            offset_mid: ((handler >> 16) & 0xFFFF) as u16,
            offset_high: ((handler >> 32) & 0xFFFF_FFFF) as u32,
            zero: 0,
        }
    }

    /// Build a trap gate (interrupts remain enabled on entry).
    pub fn from_trap_handler(handler: u64, selector: u16, ist: u8, dpl: u8) -> Self {
        let present: u8 = 0x80;
        let system: u8 = 0x00;
        let gate_type: u8 = 0x0F; // 64-bit trap gate
        let type_attr = present | ((dpl & 0x03) << 5) | system | gate_type;

        Self {
            offset_low: (handler & 0xFFFF) as u16,
            selector,
            ist,
            type_attr,
            offset_mid: ((handler >> 16) & 0xFFFF) as u16,
            offset_high: ((handler >> 32) & 0xFFFF_FFFF) as u32,
            zero: 0,
        }
    }

    /// Check if the entry is present.
    pub fn is_present(&self) -> bool {
        self.type_attr & 0x80 != 0
    }

    /// Extract full handler address from the split fields.
    pub fn handler_address(&self) -> u64 {
        self.offset_low as u64
            | (self.offset_mid as u64) << 16
            | (self.offset_high as u64) << 32
    }
}

/// IDTR register value (LIDT target).
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct IdtPointer {
    pub limit: u16,
    pub base: u64,
}

unsafe impl Send for IdtPointer {}
unsafe impl Sync for IdtPointer {}

// ============================================================
// IDT constants
// ============================================================

/// Total number of IDT entries.
pub const IDT_ENTRY_COUNT: usize = 256;

/// Kernel code segment selector (GDT entry 1, RPL=0).
pub const KERNEL_CS: u16 = 0x08;

/// IST index 1 for double-fault and NMI.
pub const IST_DOUBLE_FAULT: u8 = 1;
pub const IST_NMI: u8 = 2;

/// APIC register addresses (memory-mapped).
const APIC_BASE: u64 = 0xFEE00000;
const APIC_EOI: u64 = APIC_BASE + 0xB0;
const APIC_SPURIOUS: u64 = APIC_BASE + 0xF0;

/// PIC port addresses for legacy EOI fallback.
const PIC1_COMMAND: u16 = 0x20;
const PIC1_DATA: u16 = 0x21;
const PIC2_COMMAND: u16 = 0xA0;
const PIC2_DATA: u16 = 0xA1;
const PIC_EOI: u8 = 0x20;

// ============================================================
// Interrupt Object (matches ntoskrnl!_INTERRUPT_OBJECT)
// ============================================================

/// Service affinity: which processor(s) this interrupt targets.
pub const INTERRUPT_CONNECTED: u32 = 0x00000001;

/// Vector → InterruptObject lookup table.
/// In ntoskrnl this is a hash table; here a flat array for simplicity.
static mut KI_INTERRUPT_TABLE: [Option<*mut InterruptObject>; IDT_ENTRY_COUNT] =
    [None; IDT_ENTRY_COUNT];

/// Global IDT storage.
static mut KI_IDT: [IdtEntry; IDT_ENTRY_COUNT] = [IdtEntry::empty(); IDT_ENTRY_COUNT];

/// IDTR value.
static mut KI_IDTR: IdtPointer = IdtPointer { limit: 0, base: 0 };

/// Global lock for interrupt object list.
static KI_INTERRUPT_TABLE_LOCK: AtomicUsize = AtomicUsize::new(0);

/// Per-processor IRQL tracking (shadow of Kprcb.current_irql).
static mut KI_CURRENT_IRQL: [u8; 64] = [0u8; 64];

/// Current IRQL stack depth (nesting).
static mut KI_IRQL_DEPTH: [u8; 64] = [0u8; 64];

/// Pending APC bitmap per processor.
static mut KI_APC_INTERRUPT_PENDING: [bool; 64] = [false; 64];

/// Deferred Procedure Call interrupt pending per processor.
static mut KI_DPC_INTERRUPT_PENDING: [bool; 64] = [false; 64];

/// Interrupt dispatch count per processor (for profiling).
static mut KI_INTERRUPT_COUNT: [u64; 64] = [0u64; 64];

// ============================================================
// Interrupt Object
// ============================================================

/// Service routine function pointer type.
pub type PkServiceRoutine =
    extern "C" fn(device_object: Pvoid, context: Pvoid) -> i32;

/// Share mode for interrupt lines.
#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum InterruptMode {
    LevelSensitive = 0,
    Latched = 1,
}

/// Interrupt object connecting a device to an IDT vector.
#[repr(C)]
pub struct InterruptObject {
    pub list_entry: ListEntry,
    pub service_routine: Option<PkServiceRoutine>,
    pub service_context: Pvoid,
    pub vector: u32,
    pub irql: u8,
    pub synchronization_irql: u8,
    pub processor_affinity: u64,
    pub mode: InterruptMode,
    pub connected: u32,
    pub share_vector: Boolean,
    pub number: u32,
    pub pad: [u8; 4],
}

unsafe impl Send for InterruptObject {}
unsafe impl Sync for InterruptObject {}

impl InterruptObject {
    pub fn uninitialized() -> Self {
        Self {
            list_entry: ListEntry::uninitialized(),
            service_routine: None,
            service_context: core::ptr::null_mut(),
            vector: 0,
            irql: PASSIVE_LEVEL,
            synchronization_irql: PASSIVE_LEVEL,
            processor_affinity: 1,
            mode: InterruptMode::LevelSensitive,
            connected: 0,
            share_vector: 0,
            number: 0,
            pad: [0; 4],
        }
    }
}

// ============================================================
// Interrupt Stack Frame (pushed by CPU + our stub)
// ============================================================

/// Stack layout when KiInterruptDispatch is entered.
/// CPU pushes: SS, RSP, RFLAGS, CS, RIP, (error_code for some).
/// Our stub pushes: vector number.
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct InterruptStackFrame {
    pub rip: u64,
    pub cs: u64,
    pub rflags: u64,
    pub rsp: u64,
    pub ss: u64,
}

// ============================================================
// APC State
// ============================================================

/// APC (Asynchronous Procedure Call) object.
#[repr(C)]
pub struct KapcState {
    pub apc_list_head: [ListEntry; 2], // [0]=KernelMode, [1]=UserMode
    pub kernel_apc_count: i32,
    pub user_apc_pending: Boolean,
    pub padding: [u8; 3],
}

unsafe impl Send for KapcState {}
unsafe impl Sync for KapcState {}

impl KapcState {
    pub fn uninitialized() -> Self {
        Self {
            apc_list_head: [ListEntry::uninitialized(), ListEntry::uninitialized()],
            kernel_apc_count: 0,
            user_apc_pending: 0,
            padding: [0; 3],
        }
    }
}

/// Per-processor APC queue.
static mut KI_APC_QUEUE: [KapcState; 64] = unsafe { mem::zeroed() };

// ============================================================
// External stubs (defined elsewhere in the kernel)
// ============================================================

extern "C" {
    /// HAL: Send EOI to the local APIC.
    fn HalEndOfInterrupt(vector: u32);
}

/// Send End-Of-Interrupt to the interrupt controller.
/// For legacy PIC vectors 0-15, also cascade PIC EOI.
#[inline]
pub unsafe fn ki_send_eoi(vector: u32) {
    if vector < 16 {
        // Legacy PIC: send EOI to master (and slave for vectors 8-15)
        let port = if vector >= 8 { PIC2_COMMAND } else { PIC1_COMMAND };
        outb(port, PIC_EOI);
    }
    // Always send APIC EOI
    core::ptr::write_volatile(APIC_EOI as *mut u32, 0);
}

/// Mask all external interrupts (CLI).
#[inline]
pub unsafe fn ki_disable_interrupts() {
    asm!("cli", options(nostack, nomem));
}

/// Unmask external interrupts (STI).
#[inline]
pub unsafe fn ki_enable_interrupts() {
    asm!("sti", options(nostack, nomem));
}

/// Query RFLAGS.IF (interrupt flag).
#[inline]
pub unsafe fn ki_get_rflags_if() -> bool {
    let rflags: u64;
    asm!("pushfq; pop {0}", out(reg) rflags, options(nostack, nomem));
    rflags & 0x200 != 0
}

/// Write a byte to an I/O port.
#[inline]
pub unsafe fn outb(port: u16, val: u8) {
    asm!("out dx, al", in("dx") port, in("al") val, options(nostack, nomem));
}

/// Read a byte from an I/O port.
#[inline]
pub unsafe fn inb(port: u16) -> u8 {
    let val: u8;
    asm!("in al, dx", out("al") val, in("dx") port, options(nostack, nomem));
    val
}

// ============================================================
// PCR / PRCB Accessors
// ============================================================

/// Get the Processor Control Region (KPCR) via GS segment.
#[inline]
pub unsafe fn ke_get_pcr() -> *mut Kpcr {
    let val: u64;
    asm!("mov {0}, gs:[0]", out(reg) val, options(nostack, nomem));
    val as *mut Kpcr
}

/// Get the current processor's PRCB.
#[inline]
pub unsafe fn ke_get_current_prcb() -> *mut Kprcb {
    let pcr = ke_get_pcr();
    if pcr.is_null() {
        return &mut KI_PRCB[0] as *mut Kprcb;
    }
    (*pcr).prcb
}

/// Get the currently executing thread.
#[inline]
pub unsafe fn ke_get_current_thread() -> *mut Kthread {
    let prcb = ke_get_current_prcb();
    if prcb.is_null() {
        return ptr::null_mut();
    }
    (*prcb).current_thread
}

/// Get the currently executing thread (alias).
#[inline]
pub unsafe fn ke_get_current_thread_safe() -> *mut Kthread {
    ke_get_current_thread()
}

/// Read CR2 (faulting address for #PF).
#[inline]
pub unsafe fn ki_get_cr2() -> u64 {
    let val: u64;
    asm!("mov {0}, cr2", out(reg) val, options(nostack, nomem));
    val
}

/// Read CR8 (Task Priority Register = IRQL on x86-64 Windows).
#[inline]
pub unsafe fn ki_get_cr8() -> u64 {
    let val: u64;
    asm!("mov {0}, cr8", out(reg) val, options(nostack, nomem));
    val
}

/// Write CR8 (sets IRQL via hardware).
#[inline]
pub unsafe fn ki_set_cr8(val: u64) {
    asm!("mov cr8, {0}", in(reg) val, options(nostack, nomem));
}

// ============================================================
// IRQL Management
// ============================================================

/// Raise IRQL to the specified level and return the old IRQL.
///
/// On x86-64 Windows, IRQL is enforced in software. Raising above
/// DISPATCH_LEVEL is invalid (bugcheck). The new IRQL must be >= old.
pub unsafe fn ki_raise_irql(new_irql: Irql) -> Irql {
    let proc = ke_get_current_processor_number() as usize;
    let old_irql = KI_CURRENT_IRQL[proc];

    if new_irql > HIGH_LEVEL {
        // IRQL_NOT_LESS_OR_EQUAL bugcheck (0xA)
        ki_bugcheck_a(old_irql, new_irql);
    }

    KI_CURRENT_IRQL[proc] = new_irql;
    KI_IRQL_DEPTH[proc] += 1;

    // Update PRCB shadow
    let prcb = ke_get_current_prcb();
    if !prcb.is_null() {
        (*prcb).current_irql = new_irql;
    }

    old_irql
}

/// Lower IRQL to the specified level.
///
/// The new IRQL must be <= old IRQL. Lowering below PASSIVE_LEVEL
/// while a thread is running is a bugcheck.
pub unsafe fn ki_lower_irql(new_irql: Irql) {
    let proc = ke_get_current_processor_number() as usize;
    let old_irql = KI_CURRENT_IRQL[proc];

    if new_irql > old_irql {
        // Bugcheck: IRQL_NOT_LESS_OR_EQUAL
        ki_bugcheck_a(old_irql, new_irql);
    }

    KI_CURRENT_IRQL[proc] = new_irql;
    KI_IRQL_DEPTH[proc] = KI_IRQL_DEPTH[proc].saturating_sub(1);

    let prcb = ke_get_current_prcb();
    if !prcb.is_null() {
        (*prcb).current_irql = new_irql;
    }

    // When lowering to PASSIVE_LEVEL, check for pending APC/DPC delivery
    if new_irql == PASSIVE_LEVEL {
        ki_check_pending_apcs(proc);
        ki_check_pending_dpcs(proc);
    }
}

/// Raise IRQL to the specified level (exported API).
/// Returns old IRQL.
pub unsafe fn ke_raise_irql(new_irql: Irql) -> Irql {
    ki_raise_irql(new_irql)
}

/// Lower IRQL to the specified level (exported API).
pub unsafe fn ke_lower_irql(new_irql: Irql) {
    ki_lower_irql(new_irql);
}

/// Get current IRQL.
pub unsafe fn ke_get_current_irql() -> Irql {
    let proc = ke_get_current_processor_number() as usize;
    KI_CURRENT_IRQL[proc]
}

/// Get the current processor number.
#[inline]
pub unsafe fn ke_get_current_processor_number() -> u32 {
    let prcb = ke_get_current_prcb();
    if prcb.is_null() { 0 } else { (*prcb).number }
}

/// Raise IRQL to DISPATCH_LEVEL.
pub unsafe fn ke_raise_irql_to_dpc_level() -> Irql {
    ki_raise_irql(DISPATCH_LEVEL)
}

/// Lower IRQL from DISPATCH_LEVEL to old level.
pub unsafe fn ke_lower_irql_from_dpc_level(old_irql: Irql) {
    ki_lower_irql(old_irql);
}

/// Raise to SYNCHRONIZE level (DISPATCH_LEVEL) and acquire spinlock.
pub unsafe fn ke_acquire_spin_lock_raise_to_synch(lock: *mut KspinLock) -> Irql {
    let old_irql = ki_raise_irql(DISPATCH_LEVEL);
    acquire_spin_lock(&mut *lock);
    old_irql
}

/// Release spinlock and lower IRQL.
pub unsafe fn ke_release_spin_lock(lock: *mut KspinLock, old_irql: Irql) {
    release_spin_lock(&mut *lock);
    ki_lower_irql(old_irql);
}

// ============================================================
// Bugcheck helpers (inline for IRQL violations)
// ============================================================

unsafe fn ki_bugcheck_a(old_irql: Irql, new_irql: Irql) -> ! {
    // IRQL_NOT_LESS_OR_EQUAL (bugcheck code 0xA)
    // In a real kernel this triggers a blue screen.
    // We spin forever to prevent damage.
    loop {
        asm!("cli; hlt", options(nostack, nomem));
    }
}

// ============================================================
// APC Delivery
// ============================================================

/// Check if there are pending kernel-mode APCs at PASSIVE_LEVEL.
unsafe fn ki_check_pending_apcs(proc: usize) {
    let thread = ke_get_current_thread();
    if thread.is_null() {
        return;
    }

    let t = &*thread;
    if t.apc_pending != 0 {
        // APC level is 1; we're already at PASSIVE_LEVEL (0)
        // so delivery is allowed.
        // In a full implementation we would dequeue from the
        // APC queue and invoke each APC routine.
        KI_APC_INTERRUPT_PENDING[proc] = true;
    }
}

/// Deliver kernel-mode APCs for the current thread.
/// Called at PASSIVE_LEVEL after lowering from DISPATCH.
pub unsafe fn ki_deliver_apcs() {
    let thread = ke_get_current_thread();
    if thread.is_null() {
        return;
    }

    let t = &mut *thread;
    let proc = ke_get_current_processor_number() as usize;

    if t.apc_pending == 0 && t.user_apc_pending == 0 {
        return;
    }

    // Raise to APC_LEVEL to serialize APC delivery
    let old_irql = ki_raise_irql(APC_LEVEL);

    // Process kernel APCs from the APC queue
    let apc_queue = &KI_APC_QUEUE[proc];

    // Iterate kernel-mode APC list
    let mut entry = apc_queue.apc_list_head[0].flink;
    while entry != &apc_queue.apc_list_head[0] as *const ListEntry as *mut ListEntry {
        let next = (*entry).flink;
        // In full implementation: extract APC object, call NormalRoutine
        entry = next;
    }

    t.apc_pending = 0;

    // Process user-mode APCs if alertable
    if t.user_apc_pending != 0 && t.alertable != 0 && t.wait_mode == 1 {
        // User-mode APC delivery happens on return to user mode
        KI_APC_INTERRUPT_PENDING[proc] = false;
    }

    ki_lower_irql(old_irql);
}

/// Check if APCs are deliverable on the current thread.
pub unsafe fn ki_check_for_apc_delivery() -> bool {
    let thread = ke_get_current_thread();
    if thread.is_null() {
        return false;
    }
    let t = &*thread;
    t.apc_pending != 0 && t.alertable != 0 && ke_get_current_irql() == PASSIVE_LEVEL
}

/// Insert an APC into the thread's queue.
pub unsafe fn ke_insert_queue_apc(thread: *mut Kthread, apc_type: u8) -> bool {
    if thread.is_null() {
        return false;
    }

    ki_acquire_interrupt_table_lock();
    if apc_type == 0 {
        (*thread).apc_pending = 1;
    } else {
        (*thread).user_apc_pending = 1;
    }
    ki_release_interrupt_table_lock();
    true
}

/// Remove an APC from the thread's queue.
pub unsafe fn ke_remove_queue_apc(thread: *mut Kthread) -> bool {
    if thread.is_null() {
        return false;
    }

    ki_acquire_interrupt_table_lock();
    let had = (*thread).apc_pending != 0 || (*thread).user_apc_pending != 0;
    (*thread).apc_pending = 0;
    (*thread).user_apc_pending = 0;
    ki_release_interrupt_table_lock();
    had
}

// ============================================================
// DPC Delivery
// ============================================================

/// Check if DPCs are pending on the current processor.
unsafe fn ki_check_pending_dpcs(proc: usize) {
    if KI_DPC_INTERRUPT_PENDING[proc] {
        KI_DPC_INTERRUPT_PENDING[proc] = false;
        // In full implementation: process DPC queue
    }
}

/// Request a DPC interrupt on the current processor.
pub unsafe fn ke_insert_queue_dpc(dpc: Pvoid) -> bool {
    let proc = ke_get_current_processor_number() as usize;
    ki_acquire_interrupt_table_lock();
    KI_DPC_INTERRUPT_PENDING[proc] = true;
    ki_release_interrupt_table_lock();
    true
}

/// Remove a pending DPC from the queue.
pub unsafe fn ke_remove_queue_dpc(dpc: Pvoid) -> bool {
    let proc = ke_get_current_processor_number() as usize;
    ki_acquire_interrupt_table_lock();
    let had = KI_DPC_INTERRUPT_PENDING[proc];
    KI_DPC_INTERRUPT_PENDING[proc] = false;
    ki_release_interrupt_table_lock();
    had
}

/// Flush all queued DPCs on the current processor.
pub unsafe fn ke_flush_queued_dpcs() {
    ki_process_dpc_queue(&mut KI_PRCB[0]);
}

// ============================================================
// Interrupt Table Lock
// ============================================================

#[inline]
unsafe fn ki_acquire_interrupt_table_lock() {
    let lock_ptr = &KI_INTERRUPT_TABLE_LOCK as *const AtomicUsize as *mut u64;
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
unsafe fn ki_release_interrupt_table_lock() {
    let lock_ptr = &KI_INTERRUPT_TABLE_LOCK as *const AtomicUsize as *mut u64;
    asm!(
        "lock btr qword ptr [{0}], 0",
        in(reg) lock_ptr,
        options(nostack, nomem),
    );
}

// ============================================================
// IDT Setup
// ============================================================

/// Fill an IDT entry for a given vector using the provided handler address.
unsafe fn ki_set_idt_entry(vector: usize, handler: u64, ist: u8, dpl: u8) {
    if vector < IDT_ENTRY_COUNT {
        KI_IDT[vector] = IdtEntry::from_handler(handler, KERNEL_CS, ist, dpl);
    }
}

/// Initialize the IDT with KiUnexpectedInterrupt for all vectors,
/// then set up dedicated handlers for CPU exceptions (0-31) and
/// the APIC timer/spurious vectors.
///
/// This is called once during kernel initialization.
pub unsafe fn ki_initialize_idt() {
    // Fill entire IDT with KiUnexpectedInterrupt stub
    let unexpected_addr = ki_get_unexpected_interrupt_address();
    for i in 0..IDT_ENTRY_COUNT {
        KI_IDT[i] = IdtEntry::from_handler(unexpected_addr, KERNEL_CS, 0, 0);
    }

    // CPU exception vectors (0-31) with dedicated handlers
    let dedicated_handlers: [(usize, u64, u8); 21] = [
        (0,  ki_get_divide_error_address(),        0),      // #DE Divide Error
        (1,  ki_get_debug_exception_address(),     0),      // #DB Debug Exception
        (2,  ki_get_nmi_handler_address(),         IST_NMI), // NMI
        (3,  ki_get_breakpoint_address(),          3),      // #BP Breakpoint (DPL=3 for user)
        (4,  ki_get_overflow_address(),            0),      // #OF Overflow
        (5,  ki_get_bound_range_address(),         0),      // #BR Bound Range
        (6,  ki_get_invalid_opcode_address(),      0),      // #UD Invalid Opcode
        (7,  ki_get_device_not_available_address(),0),      // #NM Device Not Available
        (8,  ki_get_double_fault_address(),        IST_DOUBLE_FAULT), // #DF Double Fault
        (9,  ki_get_coprocessor_overrun_address(), 0),      // Coprocessor Segment
        (10, ki_get_invalid_tss_address(),         0),      // #TS Invalid TSS
        (11, ki_get_segment_not_present_address(), 0),      // #NP Segment Not Present
        (13, ki_get_general_protection_address(),  0),      // #GP General Protection
        (14, ki_get_page_fault_address(),          0),      // #PF Page Fault
        (16, ki_get_x87_fpe_address(),             0),      // #MF x87 FP Exception
        (17, ki_get_alignment_check_address(),     0),      // #AC Alignment Check
        (19, ki_get_simd_fp_exception_address(),   0),      // #XM SIMD FP Exception
        (20, ki_get_virtualization_exception_addr(),0),      // #VE Virtualization
        (28, ki_get_hv_injection_exception_addr(), 0),      // #HV Hypervisor
        (29, ki_get_vmm_comm_exception_addr(),     0),      // #VC VMM Communication
        (30, ki_get_security_exception_addr(),     0),      // #SX Security
    ];

    for (vector, handler, ist) in &dedicated_handlers {
        let dpl = if *vector == 3 { 3 } else { 0 };
        ki_set_idt_entry(*vector, *handler, *ist, dpl);
    }

    // APIC timer vector (IRQ 0 = vector 48 in typical mapping)
    let timer_handler = ki_get_apic_timer_handler_address();
    ki_set_idt_entry(48, timer_handler, 0, 0);

    // APIC spurious vector (typically 255)
    let spurious_handler = ki_get_spurious_interrupt_address();
    ki_set_idt_entry(255, spurious_handler, 0, 0);

    // Load IDT register
    KI_IDTR.limit = (IDT_ENTRY_COUNT * mem::size_of::<IdtEntry>() - 1) as u16;
    KI_IDTR.base = &KI_IDT as *const [IdtEntry; IDT_ENTRY_COUNT] as u64;

    asm!(
        "lidt [{0}]",
        in(reg) &KI_IDTR as *const IdtPointer,
        options(nostack, nomem),
    );
}

// ============================================================
// Stub handler address getters
// (In a real kernel these return addresses of asm stubs;
//  here we provide the addresses of our Rust wrappers.)
// ============================================================

extern "C" {
    fn KiUnexpectedInterruptStub();
    fn KiDivideErrorStub();
    fn KiDebugExceptionStub();
    fn KiNmiStub();
    fn KiBreakpointStub();
    fn KiOverflowStub();
    fn KiBoundRangeStub();
    fn KiInvalidOpcodeStub();
    fn KiDeviceNotAvailableStub();
    fn KiDoubleFaultStub();
    fn KiCoprocessorOverrunStub();
    fn KiInvalidTssStub();
    fn KiSegmentNotPresentStub();
    fn KiGeneralProtectionStub();
    fn KiPageFaultStub();
    fn KiX87FpeStub();
    fn KiAlignmentCheckStub();
    fn KiSimdFpExceptionStub();
    fn KiVirtualizationExceptionStub();
    fn KiHvInjectionExceptionStub();
    fn KiVmmCommExceptionStub();
    fn KiSecurityExceptionStub();
    fn KiApicTimerStub();
    fn KiSpuriousInterruptStub();
}

unsafe fn ki_get_unexpected_interrupt_address() -> u64 {
    KiUnexpectedInterruptStub as u64
}

unsafe fn ki_get_divide_error_address() -> u64 {
    KiDivideErrorStub as u64
}

unsafe fn ki_get_debug_exception_address() -> u64 {
    KiDebugExceptionStub as u64
}

unsafe fn ki_get_nmi_handler_address() -> u64 {
    KiNmiStub as u64
}

unsafe fn ki_get_breakpoint_address() -> u64 {
    KiBreakpointStub as u64
}

unsafe fn ki_get_overflow_address() -> u64 {
    KiOverflowStub as u64
}

unsafe fn ki_get_bound_range_address() -> u64 {
    KiBoundRangeStub as u64
}

unsafe fn ki_get_invalid_opcode_address() -> u64 {
    KiInvalidOpcodeStub as u64
}

unsafe fn ki_get_device_not_available_address() -> u64 {
    KiDeviceNotAvailableStub as u64
}

unsafe fn ki_get_double_fault_address() -> u64 {
    KiDoubleFaultStub as u64
}

unsafe fn ki_get_coprocessor_overrun_address() -> u64 {
    KiCoprocessorOverrunStub as u64
}

unsafe fn ki_get_invalid_tss_address() -> u64 {
    KiInvalidTssStub as u64
}

unsafe fn ki_get_segment_not_present_address() -> u64 {
    KiSegmentNotPresentStub as u64
}

unsafe fn ki_get_general_protection_address() -> u64 {
    KiGeneralProtectionStub as u64
}

unsafe fn ki_get_page_fault_address() -> u64 {
    KiPageFaultStub as u64
}

unsafe fn ki_get_x87_fpe_address() -> u64 {
    KiX87FpeStub as u64
}

unsafe fn ki_get_alignment_check_address() -> u64 {
    KiAlignmentCheckStub as u64
}

unsafe fn ki_get_simd_fp_exception_address() -> u64 {
    KiSimdFpExceptionStub as u64
}

unsafe fn ki_get_virtualization_exception_addr() -> u64 {
    KiVirtualizationExceptionStub as u64
}

unsafe fn ki_get_hv_injection_exception_addr() -> u64 {
    KiHvInjectionExceptionStub as u64
}

unsafe fn ki_get_vmm_comm_exception_addr() -> u64 {
    KiVmmCommExceptionStub as u64
}

unsafe fn ki_get_security_exception_addr() -> u64 {
    KiSecurityExceptionStub as u64
}

unsafe fn ki_get_apic_timer_handler_address() -> u64 {
    KiApicTimerStub as u64
}

unsafe fn ki_get_spurious_interrupt_address() -> u64 {
    KiSpuriousInterruptStub as u64
}

// ============================================================
// Interrupt Dispatching
// ============================================================

/// Main interrupt dispatch routine.
///
/// Called from the interrupt stub after saving volatile registers.
/// Looks up the interrupt object for the given vector, calls the
/// service routine, manages IRQL, and sends EOI.
///
/// # Safety
/// Called from interrupt context; must preserve all registers.
pub unsafe fn ki_interrupt_dispatch(vector: u32) {
    let proc = ke_get_current_processor_number() as usize;

    KI_INTERRUPT_COUNT[proc] += 1;

    let old_irql = ke_get_current_irql();

    // Validate vector range
    if vector as usize >= IDT_ENTRY_COUNT {
        ki_send_eoi(vector);
        return;
    }

    // Look up the interrupt object for this vector
    let interrupt_object = KI_INTERRUPT_TABLE[vector as usize];

    match interrupt_object {
        Some(obj_ptr) if !obj_ptr.is_null() => {
            let obj = &*obj_ptr;

            // Check processor affinity
            let cpu_mask = 1u64 << proc;
            if obj.processor_affinity & cpu_mask == 0 {
                ki_send_eoi(vector);
                return;
            }

            // Raise IRQL to the interrupt's synchronization level
            let sync_irql = obj.synchronization_irql;
            if sync_irql > old_irql {
                ki_raise_irql(sync_irql);
            }

            // Call the service routine
            let status = if let Some(routine) = obj.service_routine {
                routine(obj.service_context as Pvoid, core::ptr::null_mut())
            } else {
                -1
            };

            // The return value indicates:
            //   0 = handled (not shared)
            //   >0 = handled (shared, may need to check other handlers)
            //   <0 = not handled
            if status >= 0 {
                // Service routine handled the interrupt; lower IRQL
                if sync_irql > old_irql {
                    ki_lower_irql(old_irql);
                }

                // Check for pending APCs when lowering to PASSIVE_LEVEL
                if old_irql == PASSIVE_LEVEL {
                    if let Some(thread) = ke_get_current_thread().as_mut() {
                        if thread.apc_pending != 0 && thread.alertable != 0 {
                            // APC delivery will occur on return
                        }
                    }
                }

                // Send EOI
                ki_send_eoi(vector);
                return;
            }
            // status < 0: interrupt not handled, fall through

            // Lower IRQL back
            if sync_irql > old_irql {
                ki_lower_irql(old_irql);
            }
        }
        _ => {
            // No handler registered; this is a spurious/unexpected interrupt
        }
    }

    // If we reach here, the interrupt was not handled by any service routine
    // For spurious interrupts, just send EOI
    ki_send_eoi(vector);
}

/// Second-level dispatch for shared interrupts.
/// Walks the interrupt object list for a vector and calls each handler.
pub unsafe fn ki_dispatch_shared_interrupt(vector: u32) -> bool {
    let mut handled = false;

    // In a full implementation this walks the shared interrupt list.
    // For now, just dispatch to the primary handler.
    ki_interrupt_dispatch(vector);
    true
}

// ============================================================
// Dedicated Interrupt Handlers
// ============================================================

/// Unexpected interrupt handler.
/// Any interrupt that fires without a registered handler lands here.
#[no_mangle]
pub unsafe extern "C" fn ki_unexpected_interrupt(vector: u32) {
    // Increment unexpected interrupt counter
    let proc = ke_get_current_processor_number() as usize;
    KI_INTERRUPT_COUNT[proc] += 1;

    // Send EOI
    ki_send_eoi(vector);

    // In debug builds, we could panic. In production, just return.
}

/// Spurious interrupt handler (typically APIC vector 255).
#[no_mangle]
pub unsafe extern "C" fn ki_spurious_interrupt(vector: u32) {
    // Spurious interrupts need no action other than EOI for legacy PIC
    // APIC spurious does not require EOI, but it's harmless.
    ki_send_eoi(vector);
}

/// APIC timer interrupt handler.
#[no_mangle]
pub unsafe extern "C" fn ki_apic_timer_handler(vector: u32) {
    let proc = ke_get_current_processor_number() as usize;

    KI_TICK_COUNT += 1;

    let prcb = &mut KI_PRCB[proc];

    // Check if current thread's quantum has expired
    let current_time = ki_query_performance_counter();
    if current_time >= prcb.quantum_end {
        // Check preemption
        if let Some(current) = prcb.current_thread.as_mut() {
            if current.preemption_disable_count == 0 {
                let ready_summary = prcb.ready_summary;
                if ready_summary != 0 {
                    let highest_priority = 31 - ready_summary.leading_zeros() as i32;
                    if highest_priority > current.priority as i32 {
                        // Preempt current thread
                        let next = ki_select_thread_from_ready_queue(prcb);
                        if !next.is_null() {
                            prcb.next_thread = next;
                            (*next).state = KthreadState::Standby;
                            if current.state == KthreadState::Running {
                                current.state = KthreadState::Ready;
                                ki_dispatcher_ready_insert_thread(current, prcb);
                            }
                            ki_swap_context(next, prcb);
                        }
                    }
                }
            }
        }
    }

    // Send EOI
    ki_send_eoi(vector);
}

// ============================================================
// IoConnectInterrupt / IoDisconnectInterrupt
// ============================================================

/// Connect an interrupt service routine to a hardware vector.
///
/// This registers the service routine in the IDT's interrupt object
/// table and sets the IRQL / affinity constraints.
///
/// Returns STATUS_SUCCESS on success.
pub unsafe fn io_connect_interrupt(
    interrupt_object: *mut InterruptObject,
    service_routine: PkServiceRoutine,
    service_context: Pvoid,
    vector: u32,
    irql: Irql,
    synchronization_irql: Irql,
    processor_affinity: u64,
    mode: InterruptMode,
    share_vector: Boolean,
    number: u32,
) -> NtStatus {
    if interrupt_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    if vector as usize >= IDT_ENTRY_COUNT {
        return STATUS_INVALID_PARAMETER;
    }

    if irql > HIGH_LEVEL {
        return STATUS_INVALID_PARAMETER;
    }

    // Validate that synchronization_irql >= irql
    if synchronization_irql < irql {
        return STATUS_INVALID_PARAMETER;
    }

    let obj = &mut *interrupt_object;
    obj.service_routine = Some(service_routine);
    obj.service_context = service_context;
    obj.vector = vector;
    obj.irql = irql;
    obj.synchronization_irql = synchronization_irql;
    obj.processor_affinity = processor_affinity;
    obj.mode = mode;
    obj.share_vector = share_vector;
    obj.number = number;
    obj.connected = INTERRUPT_CONNECTED;

    // Register in the interrupt table
    ki_acquire_interrupt_table_lock();

    // Check if vector already has a handler (for shared interrupts)
    if let Some(existing) = KI_INTERRUPT_TABLE[vector as usize] {
        if share_vector == 0 {
            // Non-shared vector already in use
            ki_release_interrupt_table_lock();
            return STATUS_INSUFFICIENT_RESOURCES;
        }
        // For shared interrupts, chain via the list entry
        let existing_obj = &mut *existing;
        existing_obj.list_entry.insert_tail(&mut obj.list_entry);
    } else {
        KI_INTERRUPT_TABLE[vector as usize] = Some(interrupt_object);
        obj.list_entry.initialize();
    }

    ki_release_interrupt_table_lock();

    // If the vector is a hardware IRQ (mapped to IDT vector 32+),
    // unmask it in the PIC/APIC if needed
    if vector >= 32 && vector < 48 {
        let irq = vector - 32;
        if irq < 8 {
            let mask = inb(PIC1_DATA) & !(1 << irq);
            outb(PIC1_DATA, mask);
        } else if irq < 16 {
            let mask = inb(PIC2_DATA) & !(1 << (irq - 8));
            outb(PIC2_DATA, mask);
            // Also unmask cascade
            let mask = inb(PIC1_DATA) & !0x02;
            outb(PIC1_DATA, mask);
        }
    }

    STATUS_SUCCESS
}

/// Disconnect a previously connected interrupt.
///
/// Removes the interrupt object from the table and masks the IRQ.
pub unsafe fn io_disconnect_interrupt(interrupt_object: *mut InterruptObject) -> NtStatus {
    if interrupt_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let obj = &mut *interrupt_object;
    if obj.connected != INTERRUPT_CONNECTED {
        return STATUS_INVALID_PARAMETER;
    }

    let vector = obj.vector;

    ki_acquire_interrupt_table_lock();

    // Remove from interrupt table
    if let Some(entry) = KI_INTERRUPT_TABLE[vector as usize] {
        if entry == interrupt_object {
            // This is the primary handler; check for shared chain
            if !obj.list_entry.flink.is_null()
                && obj.list_entry.flink != &obj.list_entry as *const ListEntry as *mut ListEntry
            {
                // Promote the next handler in the chain
                let next_entry = obj.list_entry.flink;
                // Find the next interrupt object from the list entry
                // In a full implementation: container_of(next_entry)
                KI_INTERRUPT_TABLE[vector as usize] = None; // Simplified
            } else {
                KI_INTERRUPT_TABLE[vector as usize] = None;
            }
        } else {
            // This is a shared handler in the chain; remove from list
            obj.list_entry.remove();
        }
    }

    ki_release_interrupt_table_lock();

    obj.connected = 0;
    obj.service_routine = None;
    obj.service_context = core::ptr::null_mut();

    // Mask the IRQ if it's a hardware vector
    if vector >= 32 && vector < 48 {
        let irq = vector - 32;
        if irq < 8 {
            let mask = inb(PIC1_DATA) | (1 << irq);
            outb(PIC1_DATA, mask);
        } else if irq < 16 {
            let mask = inb(PIC2_DATA) | (1 << (irq - 8));
            outb(PIC2_DATA, mask);
        }
    }

    STATUS_SUCCESS
}

/// Connect a CSSR (Connect Service Service Routine) style interrupt.
/// Wrapper that allocates and initializes an interrupt object.
pub unsafe fn io_connect_interrupt_ex(
    service_routine: PkServiceRoutine,
    service_context: Pvoid,
    vector: u32,
    irql: Irql,
    processor_affinity: u64,
    mode: InterruptMode,
    share_vector: Boolean,
) -> NtStatus {
    let obj = alloc::alloc::alloc(
        core::alloc::Layout::from_size_align_unchecked(
            mem::size_of::<InterruptObject>(),
            mem::align_of::<InterruptObject>(),
        ),
    ) as *mut InterruptObject;

    if obj.is_null() {
        return STATUS_NO_MEMORY;
    }

    core::ptr::write_bytes(obj as *mut u8, 0, mem::size_of::<InterruptObject>());

    let status = io_connect_interrupt(
        obj,
        service_routine,
        service_context,
        vector,
        irql,
        irql,
        processor_affinity,
        mode,
        share_vector,
        0,
    );

    if status != STATUS_SUCCESS {
        alloc::alloc::dealloc(
            obj as *mut u8,
            core::alloc::Layout::from_size_align_unchecked(
                mem::size_of::<InterruptObject>(),
                mem::align_of::<InterruptObject>(),
            ),
        );
    }

    status
}

// ============================================================
// Interrupt object queries
// ============================================================

/// Query if an interrupt object is connected.
pub unsafe fn io_query_interrupt_connected(interrupt_object: *mut InterruptObject) -> bool {
    if interrupt_object.is_null() {
        false
    } else {
        (*interrupt_object).connected == INTERRUPT_CONNECTED
    }
}

/// Get the interrupt count for the current processor.
pub unsafe fn ki_get_interrupt_count() -> u64 {
    let proc = ke_get_current_processor_number() as usize;
    KI_INTERRUPT_COUNT[proc]
}

/// Get the interrupt vector from an interrupt object.
pub unsafe fn io_query_interrupt_vector(interrupt_object: *mut InterruptObject) -> u32 {
    if interrupt_object.is_null() { 0 } else { (*interrupt_object).vector }
}

/// Get the IRQL from an interrupt object.
pub unsafe fn io_query_interrupt_irql(interrupt_object: *mut InterruptObject) -> Irql {
    if interrupt_object.is_null() { 0 } else { (*interrupt_object).irql }
}

// ============================================================
// Inter-Processor Interrupts (IPI)
// ============================================================

/// Send an IPI to a set of processors.
/// In a full implementation this writes to the APIC ICR.
pub unsafe fn ki_send_ipi(target_processors: u64, vector: u32) {
    // Write to APIC Interrupt Command Register
    let icr_low = vector as u64;
    let icr_high = (target_processors as u64) << 32;

    let icr_high_ptr = (APIC_BASE + 0x310) as *mut u32;
    let icr_low_ptr = (APIC_BASE + 0x300) as *mut u32;

    // Write high word first (APIC requirement)
    core::ptr::write_volatile(icr_high_ptr, (icr_high >> 32) as u32);
    // Write low word to trigger the IPI
    core::ptr::write_volatile(icr_low_ptr, icr_low as u32);

    // Wait for delivery (APIC sets Delivery Status bit)
    loop {
        let val = core::ptr::read_volatile(icr_low_ptr);
        if val & (1 << 12) == 0 {
            break; // Delivery complete
        }
    }
}

/// Send a TLB flush IPI to all processors.
pub unsafe fn ki_flush_tlb_all() {
    let all_processors = ke_query_active_processors();
    // Use vector 0xFE for TLB flush IPI
    ki_send_ipi(all_processors, 0xFE);
}

// ============================================================
// Interrupt profiling
// ============================================================

/// Get the per-vector interrupt count (useful for debugging).
pub unsafe fn ki_get_vector_count(vector: u32) -> u64 {
    // In a full implementation, per-vector counters.
    // For now return the total count.
    let proc = ke_get_current_processor_number() as usize;
    KI_INTERRUPT_COUNT[proc]
}

/// Reset interrupt counters (for profiling).
pub unsafe fn ki_reset_interrupt_counts() {
    let proc = ke_get_current_processor_number() as usize;
    KI_INTERRUPT_COUNT[proc] = 0;
}

pub unsafe fn ke_enable_interrupt(vector: u32) {
    let _ = vector;
}

pub unsafe fn ke_disable_interrupt(vector: u32) {
    let _ = vector;
}

pub unsafe fn ke_connect_interrupt_vector(
    vector: u32,
    _service_routine: unsafe extern "C" fn(*mut c_void) -> bool,
    _service_context: *mut c_void,
    _shared: bool,
    _spin_lock: u32,
    _synchronize: bool,
    _group: u16,
) -> u32 {
    vector
}

pub unsafe fn ke_disconnect_interrupt(vector: u32) -> bool {
    let _ = vector;
    true
}
