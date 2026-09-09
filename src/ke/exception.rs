/// KiException - Windows 10 Exception Dispatching
///
/// Frame-based exception handling, SEH (Structured Exception Handling),
/// exception record/context management, and CPU exception translation
/// matching ntoskrnl.exe behavior.
///
/// References:
///   - Windows Internals 7th Ed. Part 1, Chapter 7
///   - WRK: ntoskrnl/ke/i386/trap.S, ntoskrnl/ke/except.c
///   - ReactOS: ke/i386/trap.S, ke/i386/exp.c
///   - MSDN: EXCEPTION_RECORD, CONTEXT, KNONVOLATILE_CONTEXT_POINTERS

use core::ffi::c_void;
use core::arch::asm;
use core::mem;
use core::sync::atomic::{AtomicBool, AtomicUsize, Ordering};

use crate::types::*;
use super::dispatcher::*;
use super::interrupt::{
    ke_get_current_processor_number,
    ke_enable_interrupt, ke_disable_interrupt,
    ke_connect_interrupt_vector, ke_disconnect_interrupt,
    ki_get_cr2,
};

// ============================================================
// Exception Constants
// ============================================================

/// Maximum number of exception parameters (ExceptionInformation array).
pub const EXCEPTION_MAXIMUM_PARAMETERS: usize = 15;

/// Exception flags
pub const EXCEPTION_NONCONTINUABLE: u32 = 0x00000001;
pub const EXCEPTION_UNWINDING: u32 = 0x00000002;
pub const EXCEPTION_EXIT_UNWIND: u32 = 0x00000004;
pub const EXCEPTION_STACK_INVALID: u32 = 0x00000008;
pub const EXCEPTION_NESTED_CALL: u32 = 0x00000010;
pub const EXCEPTION_TARGET_UNWIND: u32 = 0x00000020;
pub const EXCEPTION_COLLIDED_UNWIND: u32 = 0x00000040;
pub const EXCEPTION_UNWIND_TARGET: u32 = 0x00000080;

/// Exception codes (NTSTATUS values for exceptions)
pub const STATUS_ACCESS_VIOLATION: NtStatus = 0xC0000005;
pub const STATUS_ARRAY_BOUNDS_EXCEEDED: NtStatus = 0xC000008C;
pub const STATUS_BREAKPOINT: NtStatus = 0x80000003;
pub const STATUS_DATATYPE_MISALIGNMENT: NtStatus = 0x80000002;
pub const STATUS_DOUBLE_FAULT: NtStatus = 0xC000001C;
pub const STATUS_FLT_DENORMAL_OPERAND: NtStatus = 0xC000008D;
pub const STATUS_FLT_DIVIDE_BY_ZERO: NtStatus = 0xC000008E;
pub const STATUS_FLT_INEXACT_RESULT: NtStatus = 0xC000008F;
pub const STATUS_FLT_INVALID_OPERATION: NtStatus = 0xC0000090;
pub const STATUS_FLT_OVERFLOW: NtStatus = 0xC0000091;
pub const STATUS_FLT_STACK_CHECK: NtStatus = 0xC0000092;
pub const STATUS_FLT_UNDERFLOW: NtStatus = 0xC0000093;
pub const STATUS_FLOAT_INEXACT_RESULT: NtStatus = 0xC000008F;
pub const STATUS_FLOAT_INVALID_OPERATION: NtStatus = 0xC0000090;
pub const STATUS_GUARD_PAGE: NtStatus = 0x80000001;
pub const STATUS_HARDWARE_BREAK: NtStatus = 0xC0000076;
pub const STATUS_HV_INJECTION_EXCEPTION: NtStatus = 0xC0350080;
pub const STATUS_ILLEGAL_INSTRUCTION: NtStatus = 0xC000001D;
pub const STATUS_IN_PAGE_ERROR: NtStatus = 0xC0000006;
pub const STATUS_INTEGER_DIVIDE_BY_ZERO: NtStatus = 0xC0000094;
pub const STATUS_INTEGER_OVERFLOW: NtStatus = 0xC0000095;
pub const STATUS_INVALID_TSS: NtStatus = 0xC000002A;
pub const STATUS_NONCONTINUABLE_EXCEPTION: NtStatus = 0xC0000025;
pub const STATUS_PRIV_INSTRUCTION: NtStatus = 0xC0000096;
pub const STATUS_SECURITY_EXCEPTION: NtStatus = 0xC000001A;
pub const STATUS_SEGMENT_NOT_PRESENT: NtStatus = 0xC0000045;
pub const STATUS_SINGLE_STEP: NtStatus = 0x80000004;
pub const STATUS_STACK_OVERFLOW: NtStatus = 0xC00000FD;
pub const STATUS_VirtualizationException: NtStatus = 0xC0350005;
pub const STATUS_VMM_COMMUNICATION_EXCEPTION: NtStatus = 0xC0350080;

// ============================================================
// ExceptionDispatchContext
// ============================================================

/// Non-volatile register save area for unwinding.
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KnonvolatileContextPointers {
    pub rdi: u64,
    pub rsi: u64,
    pub rbx: u64,
    pub rbp: u64,
    pub r12: u64,
    pub r13: u64,
    pub r14: u64,
    pub r15: u64,
}

unsafe impl Send for KnonvolatileContextPointers {}
unsafe impl Sync for KnonvolatileContextPointers {}

/// Dispatcher context passed through the unwind chain.
/// Used by RtlVirtualUnwind to locate function table entries.
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct DispatcherContext {
    pub control_pc: u64,
    pub image_base: u64,
    pub function_table: *const RuntimeFunctionEntry,
    pub establisher_frame: u64,
    pub context_record: *mut ContextRecord,
    pub language_handler: Option<extern "C" fn(
        exception_record: *mut ExceptionRecord,
        establisher_frame: u64,
        context_record: *mut ContextRecord,
        dispatcher_context: *mut DispatcherContext,
    ) -> i32>,
    pub handler_data: *mut c_void,
    pub history_table: *mut UniformDispatcherChain,
    pub index: u32,
    pub filler: u32,
}

unsafe impl Send for DispatcherContext {}
unsafe impl Sync for DispatcherContext {}

// ============================================================
// Runtime Function Table Entry (.pdata)
// ============================================================

/// PE RUNTIME_FUNCTION entry for exception/unwind data.
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RuntimeFunctionEntry {
    pub begin_address: u32,
    pub end_address: u32,
    pub unwind_data: u32,
}

unsafe impl Send for RuntimeFunctionEntry {}
unsafe impl Sync for RuntimeFunctionEntry {}

impl RuntimeFunctionEntry {
    /// Extract the unwind info flags from unwind_data.
    pub fn unwind_flags(&self) -> u8 {
        (self.unwind_data & 0x1) as u8 // UNW_FLAG_NHANDLER
    }

    /// Get the unwind info address relative to image base.
    pub fn unwind_info_offset(&self) -> u32 {
        self.unwind_data & !0x1u32
    }
}

// ============================================================
// Unwind History Table
// ============================================================

/// Entry in the unwind history table (for fast function table lookups).
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UnwindHistoryTableEntry {
    pub count: u64,
    pub entry: [RuntimeFunctionEntry; 12],
}

unsafe impl Send for UnwindHistoryTableEntry {}

/// Unwind history table (per-thread).
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UnwindHistoryTable {
    pub version: u8,
    pub size: u8,
    pub local_size: u8,
    pub local_hint: u8,
    pub max_depth: u8,
    pub search: u8,
    pub entries: [UnwindHistoryTableEntry; 12],
}

unsafe impl Send for UnwindHistoryTable {}

// ============================================================
// Dispatcher Chain / Exception Chain
// ============================================================

/// Uniform dispatcher chain link (registration node).
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UniformDispatcherChain {
    pub next: *mut UniformDispatcherChain,
    pub function: extern "C" fn(
        exception_record: *mut ExceptionRecord,
        dispatcher_context: *mut DispatcherContext,
        context_record: *mut ContextRecord,
        dispatcher_context_out: *mut DispatcherContext,
    ) -> i32,
}

unsafe impl Send for UniformDispatcherChain {}
unsafe impl Sync for UniformDispatcherChain {}

// ============================================================
// ExceptionRecord (matches ntoskrnl!_EXCEPTION_RECORD)
// ============================================================

/// Exception record describing a single exception.
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ExceptionRecord {
    pub exception_code: NtStatus,
    pub exception_flags: u32,
    pub exception_record: *mut ExceptionRecord,
    pub exception_address: u64,
    pub number_parameters: u32,
    pub exception_information: [u64; EXCEPTION_MAXIMUM_PARAMETERS],
}

unsafe impl Send for ExceptionRecord {}
unsafe impl Sync for ExceptionRecord {}

impl ExceptionRecord {
    pub fn uninitialized() -> Self {
        Self {
            exception_code: 0,
            exception_flags: 0,
            exception_record: core::ptr::null_mut(),
            exception_address: 0,
            number_parameters: 0,
            exception_information: [0; EXCEPTION_MAXIMUM_PARAMETERS],
        }
    }

    /// Check if this is a continuable exception.
    pub fn is_continuable(&self) -> bool {
        self.exception_flags & EXCEPTION_NONCONTINUABLE == 0
    }

    /// Check if we're in the unwind phase.
    pub fn is_unwinding(&self) -> bool {
        self.exception_flags & EXCEPTION_UNWINDING != 0
    }
}

// ============================================================
// ContextRecord (matches ntoskrnl!_CONTEXT for x64)
// ============================================================

/// CONTEXT flags for which register groups are valid.
pub const CONTEXT_AMD64: u32 = 0x00100000;
pub const CONTEXT_CONTROL: u32 = CONTEXT_AMD64 | 0x0001;
pub const CONTEXT_INTEGER: u32 = CONTEXT_AMD64 | 0x0002;
pub const CONTEXT_SEGMENTS: u32 = CONTEXT_AMD64 | 0x0004;
pub const CONTEXT_FLOATING_POINT: u32 = CONTEXT_AMD64 | 0x0008;
pub const CONTEXT_DEBUG_REGISTERS: u32 = CONTEXT_AMD64 | 0x0010;
pub const CONTEXT_FULL: u32 = CONTEXT_CONTROL | CONTEXT_INTEGER | CONTEXT_FLOATING_POINT;
pub const CONTEXT_ALL: u32 = CONTEXT_FULL | CONTEXT_SEGMENTS | CONTEXT_DEBUG_REGISTERS;

/// 64-bit CONTEXT structure saved/restored on exception dispatch.
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ContextRecord {
    // Home parameter area (shadow space)
    pub p1_home: u64,
    pub p2_home: u64,
    pub p3_home: u64,
    pub p4_home: u64,
    pub p5_home: u64,
    pub p6_home: u64,

    // Context flags
    pub context_flags: u32,
    pub mx_csr: u32,

    // Segment registers
    pub cs: u16,
    pub ds: u16,
    pub es: u16,
    pub fs: u16,
    pub gs: u16,
    pub ss: u16,
    pub eflags: u32,

    // Debug registers
    pub dr0: u64,
    pub dr1: u64,
    pub dr2: u64,
    pub dr3: u64,
    pub dr6: u64,
    pub dr7: u64,

    // Integer registers
    pub rax: u64,
    pub rcx: u64,
    pub rdx: u64,
    pub rbx: u64,
    pub rsp: u64,
    pub rbp: u64,
    pub rsi: u64,
    pub rdi: u64,
    pub r8: u64,
    pub r9: u64,
    pub r10: u64,
    pub r11: u64,
    pub r12: u64,
    pub r13: u64,
    pub r14: u64,
    pub r15: u64,

    // Instruction pointer
    pub rip: u64,

    // Floating point / SSE / AVX registers
    pub xmm0: [u64; 2],
    pub xmm1: [u64; 2],
    pub xmm2: [u64; 2],
    pub xmm3: [u64; 2],
    pub xmm4: [u64; 2],
    pub xmm5: [u64; 2],
    pub xmm6: [u64; 2],
    pub xmm7: [u64; 2],
    pub xmm8: [u64; 2],
    pub xmm9: [u64; 2],
    pub xmm10: [u64; 2],
    pub xmm11: [u64; 2],
    pub xmm12: [u64; 2],
    pub xmm13: [u64; 2],
    pub xmm14: [u64; 2],
    pub xmm15: [u64; 2],

    // Vector registers (AVX extended state, 256-bit)
    pub vector_control: [u64; 2],
    pub xmm0_upper: [u64; 2],
    pub xmm1_upper: [u64; 2],
    pub xmm2_upper: [u64; 2],
    pub xmm3_upper: [u64; 2],
    pub xmm4_upper: [u64; 2],
    pub xmm5_upper: [u64; 2],
    pub xmm6_upper: [u64; 2],
    pub xmm7_upper: [u64; 2],

    // Stack bounds
    pub stack_control: u64,
    pub rip_shadow: u64,

    // CR2 for page faults
    pub cr2: u64,
}

unsafe impl Send for ContextRecord {}
unsafe impl Sync for ContextRecord {}

impl ContextRecord {
    /// Create an uninitialized context record.
    pub fn uninitialized() -> Self {
        unsafe { mem::zeroed() }
    }

    /// Initialize with CONTEXT_ALL flags set.
    pub fn initialize() -> Self {
        let mut ctx = Self::uninitialized();
        ctx.context_flags = CONTEXT_ALL;
        ctx
    }

    /// Get the flags indicating which register groups are valid.
    pub fn is_control_valid(&self) -> bool {
        self.context_flags & CONTEXT_CONTROL != 0
    }

    pub fn is_integer_valid(&self) -> bool {
        self.context_flags & CONTEXT_INTEGER != 0
    }

    pub fn is_segments_valid(&self) -> bool {
        self.context_flags & CONTEXT_SEGMENTS != 0
    }

    pub fn is_floating_point_valid(&self) -> bool {
        self.context_flags & CONTEXT_FLOATING_POINT != 0
    }

    pub fn is_debug_valid(&self) -> bool {
        self.context_flags & CONTEXT_DEBUG_REGISTERS != 0
    }

    /// Save volatile registers from the current execution context.
    /// This is called from exception stubs before dispatching.
    pub unsafe fn save_from_trap_frame(&mut self) {
        // The exception stub pushes a trap frame; this fills the context
        // from the hardware state at exception time.
        let mut rax: u64;
        let mut rcx: u64;
        let mut rdx: u64;
        let mut rbx: u64;
        let mut rsi: u64;
        let mut rdi: u64;
        let mut r8: u64;
        let mut r9: u64;
        let mut r10: u64;
        let mut r11: u64;
        let mut r12: u64;
        let mut r13: u64;
        let mut r14: u64;
        let mut r15: u64;

        asm!(
            "mov {0}, rbx",
            out(reg) rbx,
            out("rax") rax,
            out("rcx") rcx,
            out("rdx") rdx,
            out("rsi") rsi,
            out("rdi") rdi,
            out("r8") r8,
            out("r9") r9,
            out("r10") r10,
            out("r11") r11,
            out("r12") r12,
            out("r13") r13,
            out("r14") r14,
            out("r15") r15,
            options(nostack, nomem),
        );

        self.rax = rax;
        self.rcx = rcx;
        self.rdx = rdx;
        self.rbx = rbx;
        self.rsi = rsi;
        self.rdi = rdi;
        self.r8 = r8;
        self.r9 = r9;
        self.r10 = r10;
        self.r11 = r11;
        self.r12 = r12;
        self.r13 = r13;
        self.r14 = r14;
        self.r15 = r15;

        // Read RSP and RIP from the stack (trap frame)
        let mut rsp: u64;
        let mut rbp: u64;
        asm!("mov {0}, rsp", out(reg) rsp, options(nostack, nomem));
        asm!("mov {0}, rbp", out(reg) rbp, options(nostack, nomem));
        self.rsp = rsp;
        self.rbp = rbp;

        // Read RFLAGS
        let mut rflags: u64;
        asm!("pushfq; pop {0}", out(reg) rflags, options(nostack, nomem));
        self.eflags = rflags as u32;

        // Read segment registers
        self.cs = 0x10; // Kernel CS
        self.ds = 0x2B; // Typical kernel DS
        self.es = 0x2B;
        self.ss = 0x18; // Kernel SS
        self.fs = 0;
        self.gs = 0;

        // CR2 (page fault address)
        let cr2: u64;
        asm!("mov {0}, cr2", out(reg) cr2, options(nostack, nomem));
        self.cr2 = cr2;
    }
}

// ============================================================
// Exception Dispatch Context (internal)
// ============================================================

/// Per-processor exception dispatch state.
#[repr(C)]
pub struct KiExceptionDispatchContext {
    pub exception_record: ExceptionRecord,
    pub context_record: ContextRecord,
    pub dispatcher_context: DispatcherContext,
    pub phase: i32,       // 0=search, 1=unwind
    pub exception_index: i32,
    pub handler: u64,     // Address of handler function
    pub dispatcher_cookie: u64,
    pub unwind_cookie: u64,
    pub exception_token: u64,
    pub previous_exception: *mut ExceptionRecord,
}

unsafe impl Send for KiExceptionDispatchContext {}
unsafe impl Sync for KiExceptionDispatchContext {}

/// Per-processor exception dispatch stacks.
static mut KI_EXCEPTION_DISPATCH_CONTEXTS: [KiExceptionDispatchContext; 64] =
    unsafe { mem::zeroed() };

/// Exception nesting depth per processor.
static mut KI_EXCEPTION_DEPTH: [u32; 64] = [0u32; 64];

/// Stack overflow exception in progress flag.
static mut KI_STACK_OVERFLOW_IN_PROGRESS: [bool; 64] = [false; 64];

/// Exception dispatch lock (serializes concurrent exceptions on same proc).
static KI_EXCEPTION_LOCK: AtomicUsize = AtomicUsize::new(0);

// ============================================================
// Exception Dispatch - KiExceptionDispatch
// ============================================================

/// Main exception dispatch routine.
///
/// Called from CPU exception stubs. Saves the full register context,
/// builds an EXCEPTION_RECORD, walks the exception handler chain,
/// and dispatches to the appropriate handler (SEH, vectored, etc.).
///
/// # Arguments
/// * `vector` - CPU exception vector number
/// * `error_code` - Error code pushed by CPU (0 for exceptions that don't push one)
/// * `rip` - Faulting instruction pointer
/// * `rsp` - Stack pointer at time of exception
///
/// # Returns
/// The exception disposition:
/// - `ExceptionContinueExecution` (0) - handler fixed the problem, resume at RIP
/// - `ExceptionContinueSearch` (1) - handler didn't handle it, continue unwinding
/// - `ExceptionNestedException` (2) - exception during exception handling
#[no_mangle]
pub unsafe extern "C" fn ki_exception_dispatch(
    vector: u32,
    error_code: u64,
    rip: u64,
    rsp: u64,
) -> i32 {
    let proc = ke_get_current_processor_number() as usize;

    // Prevent re-entrant exceptions on the same processor
    KI_EXCEPTION_DEPTH[proc] += 1;
    if KI_EXCEPTION_DEPTH[proc] > 4 {
        // Too many nested exceptions - triple fault territory
        KI_EXCEPTION_DEPTH[proc] -= 1;
        return 1; // ContinueSearch - let it crash
    }

    let dispatch_ctx = &mut KI_EXCEPTION_DISPATCH_CONTEXTS[proc];

    // Build the exception record
    let exception_record = &mut dispatch_ctx.exception_record;
    core::ptr::write_bytes(exception_record as *mut ExceptionRecord, 0,
        mem::size_of::<ExceptionRecord>());

    exception_record.exception_address = rip;
    exception_record.exception_flags = 0;

    // Set exception code and parameters based on vector
    let (code, param_count, param0, param1) = match vector {
        0x00 => (STATUS_INTEGER_DIVIDE_BY_ZERO, 0, 0, 0),                // #DE
        0x01 => (STATUS_SINGLE_STEP, 0, 0, 0),                           // #DB
        0x02 => (STATUS_HARDWARE_BREAK, 0, 0, 0),                        // NMI
        0x03 => (STATUS_BREAKPOINT, 0, 0, 0),                            // #BP
        0x04 => (STATUS_INTEGER_OVERFLOW, 0, 0, 0),                      // #OF
        0x05 => (STATUS_ARRAY_BOUNDS_EXCEEDED, 0, 0, 0),                 // #BR
        0x06 => (STATUS_ILLEGAL_INSTRUCTION, 0, 0, 0),                   // #UD
        0x07 => (STATUS_ILLEGAL_INSTRUCTION, 0, 0, 0),                   // #NM
        0x08 => (STATUS_DOUBLE_FAULT, 1, error_code, 0),                 // #DF
        0x0A => (STATUS_INVALID_TSS, 1, error_code, 0),                  // #TS
        0x0B => (STATUS_SEGMENT_NOT_PRESENT, 1, error_code, 0),          // #NP
        0x0D => (STATUS_ACCESS_VIOLATION, 2, error_code, 0),             // #GP
        0x0E => (STATUS_ACCESS_VIOLATION, 0, 0, 0),                      // #PF (handled specially)
        0x10 => (STATUS_FLOAT_INEXACT_RESULT, 0, 0, 0),                  // #MF
        0x11 => (STATUS_DATATYPE_MISALIGNMENT, 0, 0, 0),                 // #AC
        0x13 => (STATUS_FLOAT_INVALID_OPERATION, 0, 0, 0),               // #XM
        0x14 => (STATUS_VirtualizationException, 0, 0, 0),               // #VE
        0x1C => (STATUS_HV_INJECTION_EXCEPTION, 0, 0, 0),                // #HV
        0x1D => (STATUS_VMM_COMMUNICATION_EXCEPTION, 1, error_code, 0),  // #VC
        0x1E => (STATUS_SECURITY_EXCEPTION, 1, error_code, 0),           // #SX
        _ => (STATUS_ACCESS_VIOLATION, 0, 0, 0),
    };

    // Special handling for Page Fault (#PF, vector 14)
    // CR2 contains the faulting address; error_code has R/W and user/supervisor bits.
    if vector == 0x0E {
        let fault_address = ki_get_cr2();
        let write = (error_code & 0x02) != 0;       // Bit 1: Write access
        let user = (error_code & 0x04) != 0;        // Bit 2: User-mode
        let instruction_fetch = (error_code & 0x10) != 0; // Bit 4: Instruction fetch
        let reserved_bit_violation = (error_code & 0x08) != 0; // Bit 3: Reserved bit

        // Determine the access type
        let access_type = if instruction_fetch {
            0 // Instruction fetch
        } else if write {
            1 // Write
        } else {
            8 // Read
        };

        // Translate to appropriate NTSTATUS
        let (ex_code, param_count, param0, param1) = ki_translate_page_fault(
            fault_address,
            error_code,
            access_type,
        );

        exception_record.exception_code = ex_code;
        exception_record.number_parameters = param_count;
        exception_record.exception_information[0] = param0;
        exception_record.exception_information[1] = param1;

        // Set CR2 in context for debugger inspection
        dispatch_ctx.context_record.cr2 = fault_address;
    } else {
        exception_record.exception_code = code;
        exception_record.number_parameters = param_count;
        if param_count >= 1 {
            exception_record.exception_information[0] = param0;
        }
        if param_count >= 2 {
            exception_record.exception_information[1] = param1;
        }
    }

    // Save full register context
    let context = &mut dispatch_ctx.context_record;
    core::ptr::write_bytes(context as *mut ContextRecord, 0,
        mem::size_of::<ContextRecord>());
    context.context_flags = CONTEXT_ALL;
    context.rip = rip;
    context.rsp = rsp;

    // Save general-purpose registers
    asm!(
        "mov {0}, rbx",
        out(reg) context.rbx,
        out("rax") context.rax,
        out("rcx") context.rcx,
        out("rdx") context.rdx,
        out("rsi") context.rsi,
        out("rdi") context.rdi,
        out("r8") context.r8,
        out("r9") context.r9,
        out("r10") context.r10,
        out("r11") context.r11,
        out("r12") context.r12,
        out("r13") context.r13,
        out("r14") context.r14,
        out("r15") context.r15,
        options(nostack, nomem),
    );

    // Read RFLAGS
    let mut rflags: u64;
    asm!("pushfq; pop {0}", out(reg) rflags, options(nostack, nomem));
    context.eflags = rflags as u32;

    // Segment registers
    context.cs = 0x10;
    context.ds = 0x2B;
    context.es = 0x2B;
    context.ss = 0x18;
    context.fs = 0;
    context.gs = 0;

    // CR2
    if vector == 0x0E {
        context.cr2 = ki_get_cr2();
    }

    // ---- Exception Dispatch Phases ----

    // Phase 1: Search - find an exception handler
    dispatch_ctx.phase = 0;
    dispatch_ctx.exception_index = 0;

    let result = ki_dispatch_exception_search(
        exception_record,
        context,
        &mut dispatch_ctx.dispatcher_context,
    );

    match result {
        0 => {
            // ExceptionContinueExecution - handler fixed the problem
            KI_EXCEPTION_DEPTH[proc] -= 1;

            // Restore context from the (potentially modified) context record
            ki_restore_context(context);
        }
        1 => {
            // ExceptionContinueSearch - no handler found, system crash
            KI_EXCEPTION_DEPTH[proc] -= 1;
            ki_fatal_exception(exception_record, context);
        }
        2 => {
            // ExceptionNestedException - exception during dispatch
            KI_EXCEPTION_DEPTH[proc] -= 1;
        }
        _ => {
            KI_EXCEPTION_DEPTH[proc] -= 1;
        }
    }

    result
}

// ============================================================
// Exception Search
// ============================================================

/// Walk the SEH chain and vectored exception handlers to find one
/// that will handle this exception.
///
/// Returns:
/// - 0: ExceptionContinueExecution (handler accepted)
/// - 1: ExceptionContinueSearch (no handler found)
/// - 2: ExceptionNestedException (new exception during dispatch)
unsafe fn ki_dispatch_exception_search(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
    dispatcher_context: *mut DispatcherContext,
) -> i32 {
    let thread = ke_get_current_thread();
    if thread.is_null() {
        return 1; // ContinueSearch - no thread, can't find handlers
    }

    let t = &*thread;

    // Phase 1a: Check vectored exception handlers (VEH)
    let veh_result = ki_dispatch_vectored_handlers(exception_record, context);
    if veh_result != 1 {
        return veh_result;
    }

    // Phase 1b: Walk the SEH chain (stack-based handlers)
    let seh_result = ki_dispatch_seh_chain(exception_record, context, dispatcher_context);
    if seh_result != 1 {
        return seh_result;
    }

    // Phase 1c: Check if the kernel debugger is connected
    // If a debugger is attached, give it a first-chance exception
    if ki_is_debugger_present() {
        let dbg_result = ki_dispatch_debugger_exception(exception_record, context);
        if dbg_result != 1 {
            return dbg_result;
        }
    }

    // Phase 1d: No handler found
    1 // ExceptionContinueSearch
}

// ============================================================
// Vectored Exception Handlers (VEH)
// ============================================================

/// VEH handler list entry.
#[repr(C)]
pub struct VectoredHandlerEntry {
    pub list_entry: ListEntry,
    pub handler: extern "C" fn(*mut ExceptionRecord) -> i32,
    pub context: Pvoid,
}

unsafe impl Send for VectoredHandlerEntry {}
unsafe impl Sync for VectoredHandlerEntry {}

/// Global VEH handler list head.
static mut KI_VEH_LIST: ListEntry = ListEntry::uninitialized();

/// Initialize the VEH list.
pub unsafe fn ki_initialize_veh() {
    KI_VEH_LIST.initialize();
}

/// Add a vectored exception handler.
pub unsafe fn ki_add_vectored_exception_handler(
    handler: extern "C" fn(*mut ExceptionRecord) -> i32,
    context: Pvoid,
) -> Pvoid {
    let entry = alloc::alloc::alloc(
        core::alloc::Layout::from_size_align_unchecked(
            mem::size_of::<VectoredHandlerEntry>(),
            mem::align_of::<VectoredHandlerEntry>(),
        ),
    ) as *mut VectoredHandlerEntry;

    if entry.is_null() {
        return core::ptr::null_mut();
    }

    (*entry).handler = handler;
    (*entry).context = context;
    (*entry).list_entry.initialize();

    KI_EXCEPTION_LOCK.fetch_add(1, Ordering::Acquire);
    KI_VEH_LIST.insert_head(&mut (*entry).list_entry);
    KI_EXCEPTION_LOCK.fetch_sub(1, Ordering::Release);

    entry as Pvoid
}

/// Remove a vectored exception handler.
pub unsafe fn ki_remove_vectored_exception_handler(cookie: Pvoid) -> usize {
    if cookie.is_null() {
        return 0;
    }

    let entry = cookie as *mut VectoredHandlerEntry;

    KI_EXCEPTION_LOCK.fetch_add(1, Ordering::Acquire);
    (*entry).list_entry.remove();
    KI_EXCEPTION_LOCK.fetch_sub(1, Ordering::Release);

    alloc::alloc::dealloc(
        entry as *mut u8,
        core::alloc::Layout::from_size_align_unchecked(
            mem::size_of::<VectoredHandlerEntry>(),
            mem::align_of::<VectoredHandlerEntry>(),
        ),
    );

    1
}

/// Dispatch to all registered VEH handlers.
unsafe fn ki_dispatch_vectored_handlers(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
) -> i32 {
    let mut current = KI_VEH_LIST.flink;

    while current != &KI_VEH_LIST as *const ListEntry as *mut ListEntry {
        let dummy: VectoredHandlerEntry = mem::zeroed();
        let base = &dummy as *const VectoredHandlerEntry as usize;
        let field = &dummy.list_entry as *const ListEntry as usize;
        let offset = field - base;
        mem::forget(dummy);
        let raw = (current as usize) - offset;
        let entry = raw as *mut VectoredHandlerEntry;
        let next = (*current).flink;

        let result = ((*entry).handler)(exception_record);
        match result {
            0 => return 0,   // ExceptionContinueExecution
            1 => {}          // ExceptionContinueSearch - try next
            _ => {}
        }

        current = next;
    }

    1 // No VEH handler handled the exception
}

// ============================================================
// SEH Chain Dispatch
// ============================================================

/// SEH registration frame on the stack.
#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SehRegistrationFrame {
    pub next: *mut SehRegistrationFrame,
    pub handler: extern "C" fn(*mut ExceptionRecord, *mut SehRegistrationFrame, *mut ContextRecord, *mut DispatcherContext) -> i32,
}

unsafe impl Send for SehRegistrationFrame {}
unsafe impl Sync for SehRegistrationFrame {}

/// Exception handler dispatch result codes.
pub const EXCEPTION_EXECUTE_HANDLER: i32 = 1;
pub const EXCEPTION_CONTINUE_SEARCH: i32 = 0;
pub const EXCEPTION_CONTINUE_EXECUTION: i32 = -1;

/// Walk the SEH handler chain and dispatch to each handler.
unsafe fn ki_dispatch_seh_chain(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
    dispatcher_context: *mut DispatcherContext,
) -> i32 {
    let thread = ke_get_current_thread();
    if thread.is_null() {
        return 1;
    }

    // Get the SEH chain head from the TEB (Thread Environment Block).
    // On x64 Windows, the SEH chain head is at TEB+0x30 (Self pointer).
    // The exception chain head is at FS:[0x08] in 32-bit or GS:[0x08] in 64-bit.
    // For our kernel, we track it per-thread.
    let t = &*thread;

    // In a kernel context, the SEH chain is embedded in the kernel stack.
    // We look for the first SEHRegistrationFrame.
    // For simplicity, we check if there's a frame on the stack.
    let stack_top = t.initial_stack as u64;
    let stack_bottom = t.kernel_stack as u64;

    if stack_top == 0 || stack_bottom == 0 {
        return 1;
    }

    // Walk the stack looking for SEH frames
    // In a real implementation, we'd use RtlLookupFunctionTable to find
    // function entries and use the unwind info to walk frames.
    // Here we use a simplified approach: scan the stack for known patterns.

    // Try RtlVirtualUnwind-based approach
    let mut control_pc = (*exception_record).exception_address;
    let mut establisher_frame = 0u64;
    let mut handler_entry: *mut UniformDispatcherChain = core::ptr::null_mut();

    let unwind_result = rtl_virtual_unwind(
        0, // ImageBase (0 = use current)
        control_pc,
        core::ptr::null_mut(),
        &mut establisher_frame,
        &mut handler_entry as *mut *mut UniformDispatcherChain as Pvoid,
        context,
        dispatcher_context,
    );

    if unwind_result != 0 && !handler_entry.is_null() {
        // Found a handler frame
        let handler_func = (*handler_entry).function;

        // Set up the dispatcher context for the handler
        (*dispatcher_context).control_pc = control_pc;
        (*dispatcher_context).context_record = context;
        (*dispatcher_context).establisher_frame = establisher_frame;

        // Call the language handler
        let disposition = handler_func(
            exception_record,
            dispatcher_context,
            context,
            dispatcher_context,
        );

        return match disposition {
            EXCEPTION_EXECUTE_HANDLER => 0,   // ContinueExecution
            EXCEPTION_CONTINUE_SEARCH => 1,   // ContinueSearch
            EXCEPTION_CONTINUE_EXECUTION => 0,
            _ => 1,
        };
    }

    // No SEH handler found
    1
}

// ============================================================
// Debug / Kernel Debugger Dispatch
// ============================================================

/// Check if a kernel debugger is connected.
unsafe fn ki_is_debugger_present() -> bool {
    // In a real kernel, this checks KdDebuggerEnabled and KdDebuggerNotPresent
    false
}

/// Dispatch a first-chance exception to the kernel debugger.
unsafe fn ki_dispatch_debugger_exception(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
) -> i32 {
    // If debugger is attached, it gets first-chance exceptions.
    // The debugger can:
    // - Handle the exception (return ExceptionContinueExecution)
    // - Pass it to the application (return ExceptionContinueSearch)
    // - Terminate the process
    1 // Not handled - continue search
}

// ============================================================
// KiPageFault - Page Fault Translation
// ============================================================

/// Translate a page fault into the appropriate NTSTATUS code.
///
/// # Arguments
/// * `fault_address` - The virtual address that faulted (from CR2)
/// * `error_code` - CPU error code (R/W, U/S, I/D, RSVD, P-key bits)
/// * `access_type` - 0=execute, 1=write, 8=read
///
/// # Returns
/// `(NTSTATUS, (param_count, ...))` - The exception code and parameter tuple.
unsafe fn ki_translate_page_fault(
    fault_address: u64,
    error_code: u64,
    access_type: u64,
) -> (NtStatus, u32, u64, u64) {
    // Bit 0 of error code: P (0=not present, 1=protection violation)
    let not_present = (error_code & 0x01) == 0;
    let protection_violation = (error_code & 0x01) != 0;

    if not_present {
        let instruction_fetch = (error_code & 0x10) != 0;
        if instruction_fetch {
            (STATUS_IN_PAGE_ERROR, 3, access_type, fault_address)
        } else {
            (STATUS_ACCESS_VIOLATION, 2, access_type, fault_address)
        }
    } else if protection_violation {
        let write = (error_code & 0x02) != 0;

        if write {
            (STATUS_ACCESS_VIOLATION, 2, 1, fault_address)
        } else {
            (STATUS_ACCESS_VIOLATION, 2, access_type, fault_address)
        }
    } else {
        (STATUS_ACCESS_VIOLATION, 2, access_type, fault_address)
    }
}

// ============================================================
// Exception Unwinding
// ============================================================

/// Unwind exception dispatch context. Called when unwinding the stack.
pub unsafe fn ki_unwind_exception_dispatch(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
    dispatcher_context: *mut DispatcherContext,
) -> i32 {
    let proc = ke_get_current_processor_number() as usize;

    let mut control_pc = (*dispatcher_context).control_pc;
    let mut establisher_frame = 0u64;
    let mut handler_entry: *mut UniformDispatcherChain = core::ptr::null_mut();
    let mut context_out = *context;

    loop {
        // Look up the unwind info for the current control PC
        let unwind_result = rtl_virtual_unwind(
            0,
            control_pc,
            core::ptr::null_mut(),
            &mut establisher_frame,
            &mut handler_entry as *mut *mut UniformDispatcherChain as Pvoid,
            &mut context_out,
            dispatcher_context,
        );

        if unwind_result == 0 || handler_entry.is_null() {
            // No more unwind frames
            break;
        }

        // Check if this frame has an exception handler
        let handler_func = (*handler_entry).function;

        // Call the handler
        let disposition = handler_func(
            exception_record,
            dispatcher_context,
            &mut context_out,
            dispatcher_context,
        );

        match disposition {
            EXCEPTION_EXECUTE_HANDLER => {
                // Handler accepted, restore context
                *context = context_out;
                return 0;
            }
            EXCEPTION_CONTINUE_SEARCH => {
                // Continue unwinding
                control_pc = (*dispatcher_context).control_pc;
                continue;
            }
            EXCEPTION_CONTINUE_EXECUTION => {
                // Resume at the (possibly modified) context
                *context = context_out;
                return 0;
            }
            _ => {
                // Unknown disposition
                break;
            }
        }
    }

    // No handler found during unwind
    1
}

/// Unwind to a specific frame.
pub unsafe fn ki_unwind_to_frame(
    exception_record: *mut ExceptionRecord,
    target_frame: u64,
    context: *mut ContextRecord,
) -> i32 {
    let mut establisher_frame = 0u64;
    let mut handler_entry: *mut UniformDispatcherChain = core::ptr::null_mut();
    let mut dispatcher_context: DispatcherContext = mem::zeroed();

    loop {
        let unwind_result = rtl_virtual_unwind(
            0,
            (*context).rip,
            core::ptr::null_mut(),
            &mut establisher_frame,
            &mut handler_entry as *mut *mut UniformDispatcherChain as Pvoid,
            context,
            &mut dispatcher_context,
        );

        if unwind_result == 0 || handler_entry.is_null() {
            break;
        }

        // If we've reached the target frame, stop
        if establisher_frame >= target_frame && target_frame != 0 {
            break;
        }

        // Unwind this frame
        (*context).rip = dispatcher_context.control_pc;
        (*context).rsp = establisher_frame + 16; // Skip saved RIP/RBP
    }

    0
}

// ============================================================
// Restore Context
// ============================================================

/// Restore the CPU context from a ContextRecord and resume execution.
///
/// This does not return.
pub unsafe fn ki_restore_context(context: *const ContextRecord) -> ! {
    let ctx = &*context;

    // Restore general-purpose registers
    asm!(
        "mov rax, {0}",
        "mov rcx, {1}",
        "mov rdx, {2}",
        "mov rbx, {3}",
        "mov rsi, {4}",
        "mov rdi, {5}",
        "mov r8,  {6}",
        "mov r9,  {7}",
        "mov r10, {8}",
        "mov r11, {9}",
        "mov r12, {10}",
        "mov r13, {11}",
        "mov r14, {12}",
        "mov r15, {13}",
        in(reg) ctx.rax,
        in(reg) ctx.rcx,
        in(reg) ctx.rdx,
        in(reg) ctx.rbx,
        in(reg) ctx.rsi,
        in(reg) ctx.rdi,
        in(reg) ctx.r8,
        in(reg) ctx.r9,
        in(reg) ctx.r10,
        in(reg) ctx.r11,
        in(reg) ctx.r12,
        in(reg) ctx.r13,
        in(reg) ctx.r14,
        in(reg) ctx.r15,
        options(nostack, nomem),
    );

    // Restore RFLAGS
    let rflags = ctx.eflags as u64;
    asm!("push {0}; popfq", in(reg) rflags, options(nostack, nomem));

    // Restore RSP and jump to RIP
    asm!(
        "mov rsp, {0}",
        "jmp *{1}",
        in(reg) ctx.rsp,
        in(reg) ctx.rip,
        options(nostack, nomem),
    );
    loop {} // Unreachable
}

// ============================================================
// Fatal Exception
// ============================================================

/// Handle a fatal (unhandled) exception by bugchecking.
///
/// This is called when no exception handler is found and the
/// exception cannot be continued.
pub unsafe fn ki_fatal_exception(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
) -> ! {
    // In a real kernel, this triggers a bugcheck (blue screen):
    //   bugcheckcode = UNEXPECTED_KERNEL_MODE_TRAP (0x7F)
    //   or REFERENCE_BY_POINTER (0x18) etc.

    // For now, we enter an infinite loop with interrupts disabled.
    // A debugger can examine the exception_record and context to diagnose.
    loop {
        asm!("cli; hlt", options(nostack, nomem));
    }
}

/// Issue a bugcheck (system crash).
pub unsafe fn ki_bugcheck(code: u32, param1: u64, param2: u64, param3: u64) -> ! {
    // In a real kernel, this writes to the bugcheck screen and reboots.
    loop {
        asm!("cli; hlt", options(nostack, nomem));
    }
}

// ============================================================
// RtlLookupFunctionTable (Stub)
// ============================================================

/// Look up the RUNTIME_FUNCTION table for a given address.
///
/// In a real kernel, this searches the PE .pdata section for
/// the function table entry containing the given address.
///
/// # Arguments
/// * `control_pc` - The instruction address to look up
/// * `image_base` - Base address of the module (0 = search all)
///
/// # Returns
/// `(table_base, table_size)` - Pointer to RUNTIME_FUNCTION array and entry count.
pub unsafe fn rtl_lookup_function_table(
    control_pc: u64,
    image_base: u64,
) -> (*const RuntimeFunctionEntry, u32) {
    // In a full implementation:
    // 1. If image_base == 0, iterate the loaded module list (PsLoadedModuleList)
    // 2. For each module, check if control_pc is within [ImageBase, ImageBase+SizeOfImage)
    // 3. If found, read the .pdata directory entry from the PE headers
    // 4. Return the RUNTIME_FUNCTION array and count

    // Stub: return null (no unwind info available)
    (core::ptr::null(), 0)
}

// ============================================================
// RtlVirtualUnwind (Stub)
// ============================================================

/// Perform a virtual unwind (stack walk) one frame.
///
/// Uses the RUNTIME_FUNCTION table to determine how to unwind
/// the current frame: which registers are saved, the frame pointer
/// chain, and whether there's an exception handler.
///
/// # Arguments
/// * `image_base` - Base of the module (0 = use lookup)
/// * `control_pc` - Current instruction pointer
/// * `history_table` - Optional unwind history cache
/// * `establisher_frame` - [out] Frame pointer of the calling function
/// * `handler_data` - [out] Exception handler data (SEH registration)
/// * `context_record` - [in/out] Context to unwind
/// * `dispatcher_context` - [out] Dispatcher context for handler dispatch
///
/// # Returns
/// Nonzero on success, 0 on failure.
pub unsafe fn rtl_virtual_unwind(
    image_base: u64,
    control_pc: u64,
    history_table: *mut UnwindHistoryTable,
    establisher_frame: *mut u64,
    handler_data: Pvoid,
    context_record: *mut ContextRecord,
    dispatcher_context: *mut DispatcherContext,
) -> i32 {
    // In a full implementation:
    // 1. Call RtlLookupFunctionTable to find the RUNTIME_FUNCTION entry
    // 2. From the RUNTIME_FUNCTION entry, read the unwind info (.xdata)
    // 3. Process unwind codes: save/restore registers, adjust RSP
    // 4. If there's an exception handler (UNW_FLAG_EHANDLER), store its address
    // 5. Update establisher_frame = RBP chain or as computed from unwind codes
    // 6. Update context_record with the caller's register state
    // 7. Return the handler_data (stack-based SEH handler address)

    // Stub: do a simple frame walk using RBP chain
    let ctx = &mut *context_record;

    // Try the RBP-based frame chain
    let saved_rbp = *(ctx.rbp as *const u64);
    let saved_rip = *((ctx.rbp + 8) as *const u64);

    if saved_rbp > ctx.rbp && saved_rbp < ctx.rsp + 0x10000 {
        *establisher_frame = saved_rbp;
        ctx.rip = saved_rip;
        ctx.rsp = ctx.rbp + 16;
        ctx.rbp = saved_rbp;

        // Fill dispatcher context
        if !dispatcher_context.is_null() {
            (*dispatcher_context).control_pc = saved_rip;
            (*dispatcher_context).establisher_frame = saved_rbp;
            (*dispatcher_context).context_record = context_record;
        }

        return 1;
    }

    0 // Unwind failed
}

/// Walk the stack to find exception handlers (unwind-based SEH).
pub unsafe fn rtl_lookup_exception_handler(
    control_pc: u64,
) -> *mut UniformDispatcherChain {
    let mut establisher_frame = 0u64;
    let mut handler_entry: *mut UniformDispatcherChain = core::ptr::null_mut();
    let mut context = ContextRecord::initialize();
    let mut dispatcher_context: DispatcherContext = mem::zeroed();

    let result = rtl_virtual_unwind(
        0,
        control_pc,
        core::ptr::null_mut(),
        &mut establisher_frame,
        &mut handler_entry as *mut *mut UniformDispatcherChain as Pvoid,
        &mut context,
        &mut dispatcher_context,
    );

    if result != 0 {
        handler_entry
    } else {
        core::ptr::null_mut()
    }
}

/// Get the address of the function table for the kernel image.
pub unsafe fn rtl_get_kernel_function_table() -> *const RuntimeFunctionEntry {
    // In a real kernel, this returns the address of the ntoskrnl .pdata section.
    core::ptr::null()
}

/// Compute the size of the function table.
pub unsafe fn rtl_get_function_table_size(table: *const RuntimeFunctionEntry) -> u32 {
    // Stub: return 0 (unknown)
    0
}

// ============================================================
// Exception Helper Functions
// ============================================================

/// Copy an exception record to a new one (for nested exceptions).
pub unsafe fn ki_copy_exception_record(
    dest: *mut ExceptionRecord,
    source: *const ExceptionRecord,
) {
    let dest = &mut *dest;
    let source = &*source;

    dest.exception_code = source.exception_code;
    dest.exception_flags = source.exception_flags;
    dest.exception_address = source.exception_address;
    dest.number_parameters = source.number_parameters;

    let count = source.number_parameters as usize;
    if count > EXCEPTION_MAXIMUM_PARAMETERS {
        dest.number_parameters = EXCEPTION_MAXIMUM_PARAMETERS as u32;
    }
    for i in 0..dest.number_parameters as usize {
        dest.exception_information[i] = source.exception_information[i];
    }
}

/// Check if an exception code represents a continuable exception.
pub fn ki_is_continuable_exception(code: NtStatus) -> bool {
    // Non-continuable exceptions
    match code {
        STATUS_ACCESS_VIOLATION |
        STATUS_ARRAY_BOUNDS_EXCEEDED |
        STATUS_STACK_OVERFLOW |
        STATUS_ILLEGAL_INSTRUCTION |
        STATUS_PRIV_INSTRUCTION |
        STATUS_IN_PAGE_ERROR |
        STATUS_DATATYPE_MISALIGNMENT => false,
        _ => true,
    }
}

/// Check if an exception is a hardware exception (vs. software/int3).
pub fn ki_is_hardware_exception(vector: u32) -> bool {
    // Vectors 0-31 are CPU exceptions
    vector < 32
}

/// Check if an exception is a software interrupt (int3, int2d, etc.).
pub fn ki_is_software_exception(vector: u32) -> bool {
    // Int3 (vector 3), Int2D (vector 0x2D = 45), etc.
    vector == 3 || vector == 45
}

/// Get the exception name for debugging.
pub fn ki_exception_name(vector: u32) -> &'static str {
    match vector {
        0x00 => "#DE Divide Error",
        0x01 => "#DB Debug Exception",
        0x02 => "NMI Non-Maskable Interrupt",
        0x03 => "#BP Breakpoint",
        0x04 => "#OF Overflow",
        0x05 => "#BR Bound Range",
        0x06 => "#UD Invalid Opcode",
        0x07 => "#NM Device Not Available",
        0x08 => "#DF Double Fault",
        0x09 => "Coprocessor Segment Overrun",
        0x0A => "#TS Invalid TSS",
        0x0B => "#NP Segment Not Present",
        0x0C => "#SS Stack Segment Fault",
        0x0D => "#GP General Protection",
        0x0E => "#PF Page Fault",
        0x10 => "#MF x87 FP Exception",
        0x11 => "#AC Alignment Check",
        0x13 => "#XM SIMD FP Exception",
        0x14 => "#VE Virtualization Exception",
        0x1C => "#HV Hypervisor Injection",
        0x1D => "#VC VMM Communication",
        0x1E => "#SX Security Exception",
        _ => "Unknown Exception",
    }
}

/// Format an exception record for debugging output.
pub unsafe fn ki_dump_exception_record(record: *const ExceptionRecord) {
    if record.is_null() {
        return;
    }

    let r = &*record;
    let name = ki_exception_name(0); // Would need vector lookup

    // In a real kernel, this would print to the debug log.
    // For now, we just ensure the data is accessible.
    let _code = r.exception_code;
    let _flags = r.exception_flags;
    let _address = r.exception_address;
    let _params = r.number_parameters;
}

// ============================================================
// Stack Overflow Exception Handling
// ============================================================

/// Handle a stack overflow exception.
///
/// This is a special case because the stack is nearly exhausted,
/// so we must switch to an alternate stack before handling.
pub unsafe fn ki_handle_stack_overflow(
    exception_record: *mut ExceptionRecord,
    context: *mut ContextRecord,
) -> i32 {
    let proc = ke_get_current_processor_number() as usize;

    if KI_STACK_OVERFLOW_IN_PROGRESS[proc] {
        // Already handling a stack overflow - can't recurse
        return 1; // ContinueSearch
    }

    KI_STACK_OVERFLOW_IN_PROGRESS[proc] = true;

    // In a real kernel:
    // 1. Switch to the DPC stack (KiDpcStack)
    // 2. Issue a bugcheck or handle the exception
    // 3. Restore the original stack

    // For now, just mark the stack overflow and continue search
    KI_STACK_OVERFLOW_IN_PROGRESS[proc] = false;

    1 // ContinueSearch
}

/// Check if the current stack is nearly exhausted.
pub unsafe fn ki_check_stack_overflow() -> bool {
    let thread = ke_get_current_thread();
    if thread.is_null() {
        return false;
    }

    let t = &*thread;
    let stack_limit = t.stack_limit as u64;
    let current_rsp: u64;
    asm!("mov {0}, rsp", out(reg) current_rsp, options(nostack, nomem));

    // If RSP is within 4KB of stack limit, it's nearly exhausted
    if current_rsp <= stack_limit + 4096 {
        return true;
    }

    false
}

/// Get the alternate DPC stack for stack overflow recovery.
pub unsafe fn ki_get_dpc_stack() -> Pvoid {
    // In a real kernel, this is KiDpcStack allocated during boot.
    // For now, return null (no alternate stack available).
    core::ptr::null_mut()
}

// ============================================================
// Exception Initialization
// ============================================================

/// Initialize exception handling subsystem.
///
/// Called once during kernel startup.
pub unsafe fn ki_initialize_exception() {
    // Initialize VEH list
    ki_initialize_veh();

    // Initialize per-processor exception contexts
    for i in 0..64 {
        KI_EXCEPTION_DEPTH[i] = 0;
        KI_STACK_OVERFLOW_IN_PROGRESS[i] = false;
    }

    // The IDT is set up by ki_initialize_idt() in interrupt.rs.
    // Exception vectors (0-31) point to our exception stubs.
}

/// Finalize exception handling (cleanup on shutdown).
pub unsafe fn ki_finalize_exception() {
    // Nothing to clean up currently.
}
