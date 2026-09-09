/// Ke/Context - Processor Context Save/Restore
use crate::types::*;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Context {
    pub p1_home: u64,
    pub p2_home: u64,
    pub p3_home: u64,
    pub p4_home: u64,
    pub p5_home: u64,
    pub p6_home: u64,
    pub context_flags: u32,
    pub mx_csr: u32,
    pub cs: u16,
    pub ds: u16,
    pub es: u16,
    pub fs: u16,
    pub gs: u16,
    pub ss: u16,
    pub eflags: u64,
    pub dr0: u64,
    pub dr1: u64,
    pub dr2: u64,
    pub dr3: u64,
    pub dr6: u64,
    pub dr7: u64,
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
    pub rip: u64,
    pub xmm0: [u8; 16],
    pub xmm1: [u8; 16],
    pub xmm2: [u8; 16],
    pub xmm3: [u8; 16],
    pub xmm4: [u8; 16],
    pub xmm5: [u8; 16],
    pub xmm6: [u8; 16],
    pub xmm7: [u8; 16],
    pub xmm8: [u8; 16],
    pub xmm9: [u8; 16],
    pub xmm10: [u8; 16],
    pub xmm11: [u8; 16],
    pub xmm12: [u8; 16],
    pub xmm13: [u8; 16],
    pub xmm14: [u8; 16],
    pub xmm15: [u8; 16],
    pub vector_control: u64,
    pub xcr0: u64,
    pub xsave: [u8; 512],
}

pub const CONTEXT_AMD64: u32 = 0x00100000;
pub const CONTEXT_CONTROL: u32 = 0x00100001;
pub const CONTEXT_INTEGER: u32 = 0x00100002;
pub const CONTEXT_SEGMENTS: u32 = 0x00100004;
pub const CONTEXT_FLOATING_POINT: u32 = 0x00100008;
pub const CONTEXT_DEBUG_REGISTERS: u32 = 0x00100010;
pub const CONTEXT_FULL: u32 = CONTEXT_AMD64 | CONTEXT_CONTROL | CONTEXT_INTEGER | CONTEXT_FLOATING_POINT;
pub const CONTEXT_ALL: u32 = CONTEXT_FULL | CONTEXT_SEGMENTS | CONTEXT_DEBUG_REGISTERS;

pub unsafe fn ki_save_processor_state() {
    // Save current context into current thread
    let thread = super::dispatcher::ki_get_current_thread();
    if thread.is_null() { return; }
    let t = &mut *thread;
    // The actual context save uses inline assembly to capture registers
    // For now we store a placeholder; real implementation saves via swapgs
    let ctx = t.kernel_stack.add(4096 - core::mem::size_of::<Context>()) as *mut Context;
    core::ptr::write_bytes(ctx as *mut u8, 0, core::mem::size_of::<Context>());
    (*ctx).context_flags = CONTEXT_ALL;
}

pub unsafe fn ki_restore_processor_state() {
    let thread = super::dispatcher::ki_get_current_thread();
    if thread.is_null() { return; }
    let t = &mut *thread;
    let ctx = t.kernel_stack.add(4096 - core::mem::size_of::<Context>()) as *const Context;
    if !ctx.is_null() {
        core::arch::asm!(
            "mov rsp, [{0}]",
            "pop rax",
            in(reg) &(*ctx).rsp as *const u64,
            options(nostack)
        );
    }
}

pub unsafe fn ki_save_context(
    ctx: *mut Context,
    _rip: u64,
    _rsp: u64,
) {
    core::ptr::write_bytes(ctx as *mut u8, 0, core::mem::size_of::<Context>());
    (*ctx).context_flags = CONTEXT_ALL;
    (*ctx).rip = _rip;
    (*ctx).rsp = _rsp;
}

pub unsafe fn ki_restore_context(
    ctx: *const Context,
) -> ! {
    core::arch::asm!(
        "mov rax, [{ctx} + 0x38]",
        "mov rbx, [{ctx} + 0x30]",
        "mov rcx, [{ctx} + 0x18]",
        "mov rdx, [{ctx} + 0x20]",
        "mov rbp, [{ctx} + 0x28]",
        "mov rdi, [{ctx} + 0x48]",
        "mov rsi, [{ctx} + 0x50]",
        "mov rsp, [{ctx} + 0x40]",
        "jmp [{ctx} + 0xF8]",
        ctx = in(reg) ctx,
        options(nostack, noreturn)
    );
}

pub unsafe fn ke_switch_context(
    _current: *mut super::dispatcher::Kthread,
    _next: *mut super::dispatcher::Kthread,
) {
    // Swap kernel stack pointers
    let current_ctx = (*_current).kernel_stack;
    let next_ctx = (*_next).kernel_stack;
    (*_current).kernel_stack = next_ctx;
    (*_next).kernel_stack = current_ctx;
}

pub unsafe fn ke_get_context_thread(
    thread: *mut super::dispatcher::Kthread,
    ctx: *mut Context,
) -> NtStatus {
    if thread.is_null() || ctx.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    ki_save_context(ctx, 0, 0);
    STATUS_SUCCESS
}

pub unsafe fn ke_set_context_thread(
    thread: *mut super::dispatcher::Kthread,
    ctx: *const Context,
) -> NtStatus {
    if thread.is_null() || ctx.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Store context for next context switch
    let dest = (*thread).kernel_stack.add(4096 - core::mem::size_of::<Context>()) as *mut Context;
    core::ptr::copy_nonoverlapping(ctx, dest, 1);
    STATUS_SUCCESS
}

pub unsafe fn ke_initialize_context_thread(
    _thread: *mut super::dispatcher::Kthread,
    _start: u64,
    _stack_base: *mut u8,
    _stack_size: u64,
) {
    // Set up initial context so thread starts at _start when first scheduled
}
