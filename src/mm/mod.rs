//! # Windows 10 Memory Manager (Mm/Mi)
//!
//! Complete implementation of the ntoskrnl.exe memory manager subsystem,
//! covering physical page tracking, virtual address management, paging,
//! working set management, section objects, MDLs, pool allocators,
//! and the cache manager interface.

use core::ffi::c_void;
use core::sync::atomic::{AtomicU32, AtomicU64, AtomicU8, AtomicUsize, Ordering};
use core::cell::UnsafeCell;

use crate::types::*;

// ============================================================
// Logging macros (must be before module declarations)
// ============================================================

macro_rules! mm_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "mm_trace")]
        crate::kernel_log!("[Mm] {}", format_args!($($arg)*));
    };
}

macro_rules! mm_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Mm] {}", format_args!($($arg)*));
    };
}

macro_rules! mm_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Mm] {}", format_args!($($arg)*));
    };
}

macro_rules! mm_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Mm] {}", format_args!($($arg)*));
    };
}

pub(crate) use mm_trace;
pub(crate) use mm_dbg;
pub(crate) use mm_warn;
pub(crate) use mm_err;

// ============================================================
// Module declarations
// ============================================================

pub mod pfn;
pub mod pte;
pub mod vad;
pub mod ws;
pub mod fault;
pub mod phys;
pub mod virt;
pub mod pool;
pub mod mdl;
pub mod section;
pub mod cache;
pub mod pagefile;

pub use pfn::*;
pub use pte::*;
pub use vad::*;
pub use ws::*;
pub use fault::*;
pub use phys::*;
pub use virt::*;
pub use pool::*;
pub use mdl::*;
pub use section::*;
pub use cache::*;
pub use pagefile::*;

// ============================================================
// Global constants matching ntoskrnl.exe
// ============================================================

pub const PAGE_SIZE_X64: usize = 0x1000;
pub const PAGE_SHIFT: usize = 12;
pub const PAGE_MASK_X64: usize = !(PAGE_SIZE_X64 - 1);

pub const MI_PROCESS_PRIVATE_COMMIT_LIMIT: usize = 0x7FFFFFFF_FFFFF;
pub const MM_MAXIMUM_FETCH: usize = 0x80;

pub const MM_SYSTEM_RANGE_START: u64 = 0xFFFF_0800_0000_0000;
pub const MM_SYSTEM_RANGE_END: u64 = 0xFFFF_FFFF_FFFF_FFFF;
pub const MM_USER_RANGE_START: u64 = 0x0000_0000_0000_0000;
pub const MM_USER_RANGE_END: u64 = 0x0000_7FFF_FFFF_FFFF;

pub const MM_LOWEST_USER_ADDRESS: u64 = 0x10000;
pub const MM_HIGHEST_USER_ADDRESS: u64 = 0x0000_7FFF_FFFF_FFFF;
pub const MM_KERNEL_ADDRESS: u64 = 0xFFFF_0800_0000_0000;

pub const MI_HUGE_PTE_BASE: u64 = 0xFFFF_F680_0000_0000;

pub const MM_PTE_VALID_MASK: u64 = 0x0000_0000_0000_0001;
pub const MM_PTE_WRITE_MASK: u64 = 0x0000_0000_0000_0002;
pub const MM_PTE_OWNER_MASK: u64 = 0x0000_0000_0000_0004;
pub const MM_PTE_ACCESSED_MASK: u64 = 0x0000_0000_0000_0020;
pub const MM_PTE_DIRTY_MASK: u64 = 0x0000_0000_0000_0040;
pub const MM_PTE_GLOBAL_MASK: u64 = 0x0000_0000_0000_0100;

pub const MEM_COMMIT: u32 = 0x00001000;
pub const MEM_RESERVE: u32 = 0x00002000;
pub const MEM_FREE: u32 = 0x00010000;
pub const MEM_PRIVATE: u32 = 0x00020000;
pub const MEM_MAPPED: u32 = 0x00040000;
pub const MEM_IMAGE: u32 = 0x01000000;
pub const MEM_RELEASE: u32 = 0x00008000;
pub const MEM_DECOMMIT: u32 = 0x00004000;

pub const SEC_IMAGE: u32 = 0x1000000;
pub const SEC_FILE: u32 = 0x800000;
pub const SEC_COMMIT: u32 = 0x0800000;
pub const SEC_RESERVE: u32 = 0x0400000;
pub const SEC_NOCACHE: u32 = 0x2000000;
pub const SEC_WRITECOMBINE: u32 = 0x4000000;
pub const SEC_LARGE_PAGES: u32 = 0x8000000;
pub const SEC_PAGEFILE: u32 = 0x10000000;

pub const PAGE_TOSS: u8 = 0;
pub const PAGE_SAVE: u8 = 1;
pub const PAGE_RELAX: u8 = 2;

pub const MI_WS_TRIM_CALL_AGAIN: u32 = 0;
pub const MI_WS_TRIM_SUCCESS: u32 = 1;

// ============================================================
// MmPteFlags - Page Table Entry flags
// ============================================================

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct MmPteFlags(pub u64);

impl MmPteFlags {
    pub const VALID: MmPteFlags = MmPteFlags(1 << 0);
    pub const WRITE: MmPteFlags = MmPteFlags(1 << 1);
    pub const OWNER_USER: MmPteFlags = MmPteFlags(1 << 2);
    pub const WRITE_THROUGH: MmPteFlags = MmPteFlags(1 << 3);
    pub const CACHE_DISABLE: MmPteFlags = MmPteFlags(1 << 4);
    pub const ACCESSED: MmPteFlags = MmPteFlags(1 << 5);
    pub const DIRTY: MmPteFlags = MmPteFlags(1 << 6);
    pub const PAT: MmPteFlags = MmPteFlags(1 << 7);
    pub const GLOBAL: MmPteFlags = MmPteFlags(1 << 8);
    pub const GUARD: MmPteFlags = MmPteFlags(1 << 9);
    pub const COPY_ON_WRITE: MmPteFlags = MmPteFlags(1 << 10);
    pub const PROTOTYPE: MmPteFlags = MmPteFlags(1 << 11);
    pub const PAGE_FILE: MmPteFlags = MmPteFlags(1 << 12);
    pub const TRANSITION: MmPteFlags = MmPteFlags(1 << 13);

    pub const fn empty() -> Self { MmPteFlags(0) }
    pub const fn bits(self) -> u64 { self.0 }
    pub const fn contains(self, other: MmPteFlags) -> bool { (self.0 & other.0) == other.0 }
    pub fn insert(&mut self, other: MmPteFlags) { self.0 |= other.0; }
    pub fn remove(&mut self, other: MmPteFlags) { self.0 &= !other.0; }
    pub const fn is_empty(self) -> bool { self.0 == 0 }
}

impl core::ops::BitOr for MmPteFlags {
    type Output = Self;
    fn bitor(self, rhs: Self) -> Self { MmPteFlags(self.0 | rhs.0) }
}

impl core::ops::BitOrAssign for MmPteFlags {
    fn bitor_assign(&mut self, rhs: Self) { self.0 |= rhs.0; }
}

impl core::ops::BitAnd for MmPteFlags {
    type Output = Self;
    fn bitand(self, rhs: Self) -> Self { MmPteFlags(self.0 & rhs.0) }
}

impl core::ops::BitAndAssign for MmPteFlags {
    fn bitand_assign(&mut self, rhs: Self) { self.0 &= rhs.0; }
}

impl core::ops::Not for MmPteFlags {
    type Output = Self;
    fn not(self) -> Self { MmPteFlags(!self.0) }
}

// ============================================================
// MmProtectionMask2 - Protection field values
// ============================================================

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct MmProtectionMask2(pub u32);

impl MmProtectionMask2 {
    pub const NOACCESS: MmProtectionMask2 = MmProtectionMask2(0x01);
    pub const READONLY: MmProtectionMask2 = MmProtectionMask2(0x02);
    pub const READWRITE: MmProtectionMask2 = MmProtectionMask2(0x04);
    pub const WRITECOPY: MmProtectionMask2 = MmProtectionMask2(0x08);
    pub const EXECUTE: MmProtectionMask2 = MmProtectionMask2(0x10);
    pub const EXECUTE_READ: MmProtectionMask2 = MmProtectionMask2(0x20);
    pub const EXECUTE_READWRITE: MmProtectionMask2 = MmProtectionMask2(0x40);
    pub const EXECUTE_WRITECOPY: MmProtectionMask2 = MmProtectionMask2(0x80);
    pub const GUARD: MmProtectionMask2 = MmProtectionMask2(0x100);
    pub const NOCACHE: MmProtectionMask2 = MmProtectionMask2(0x200);
    pub const WRITECOMBINE: MmProtectionMask2 = MmProtectionMask2(0x400);
    pub const USER: MmProtectionMask2 = MmProtectionMask2(0x800);

    pub const fn empty() -> Self { MmProtectionMask2(0) }
    pub const fn bits(self) -> u32 { self.0 }
    pub const fn contains(self, other: MmProtectionMask2) -> bool { (self.0 & other.0) == other.0 }
    pub fn insert(&mut self, other: MmProtectionMask2) { self.0 |= other.0; }
    pub fn remove(&mut self, other: MmProtectionMask2) { self.0 &= !other.0; }
    pub const fn is_empty(self) -> bool { self.0 == 0 }
}

impl core::ops::BitOr for MmProtectionMask2 {
    type Output = Self;
    fn bitor(self, rhs: Self) -> Self { MmProtectionMask2(self.0 | rhs.0) }
}

impl core::ops::BitOrAssign for MmProtectionMask2 {
    fn bitor_assign(&mut self, rhs: Self) { self.0 |= rhs.0; }
}

impl core::ops::BitAnd for MmProtectionMask2 {
    type Output = Self;
    fn bitand(self, rhs: Self) -> Self { MmProtectionMask2(self.0 & rhs.0) }
}

impl core::ops::BitAndAssign for MmProtectionMask2 {
    fn bitand_assign(&mut self, rhs: Self) { self.0 &= rhs.0; }
}

impl core::ops::Not for MmProtectionMask2 {
    type Output = Self;
    fn not(self) -> Self { MmProtectionMask2(!self.0) }
}

// ============================================================
// MmPte - Page Table Entry (matches ntoskrnl layout)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MmPte {
    pub flags: MmPteFlags,
    pub page_frame_number: u64,
    pub protection: MmProtectionMask2,
}

impl MmPte {
    pub fn new() -> Self {
        Self {
            flags: MmPteFlags::empty(),
            page_frame_number: 0,
            protection: MmProtectionMask2::empty(),
        }
    }

    pub fn is_valid(&self) -> bool {
        self.flags.contains(MmPteFlags::VALID)
    }

    pub fn is_write(&self) -> bool {
        self.flags.contains(MmPteFlags::WRITE)
    }

    pub fn is_user(&self) -> bool {
        self.flags.contains(MmPteFlags::OWNER_USER)
    }

    pub fn hardware_encoded(&self) -> u64 {
        let mut val = 0u64;
        if self.flags.contains(MmPteFlags::VALID) { val |= 1; }
        if self.flags.contains(MmPteFlags::WRITE) { val |= 2; }
        if self.flags.contains(MmPteFlags::OWNER_USER) { val |= 4; }
        if self.flags.contains(MmPteFlags::WRITE_THROUGH) { val |= 8; }
        if self.flags.contains(MmPteFlags::CACHE_DISABLE) { val |= 0x10; }
        if self.flags.contains(MmPteFlags::ACCESSED) { val |= 0x20; }
        if self.flags.contains(MmPteFlags::DIRTY) { val |= 0x40; }
        if self.flags.contains(MmPteFlags::GLOBAL) { val |= 0x100; }
        val |= (self.page_frame_number << 12) & 0x000F_FFFF_FFFF_F000;
        val
    }

    pub fn from_hardware(val: u64) -> Self {
        let mut flags = MmPteFlags::empty();
        if val & 1 != 0 { flags |= MmPteFlags::VALID; }
        if val & 2 != 0 { flags |= MmPteFlags::WRITE; }
        if val & 4 != 0 { flags |= MmPteFlags::OWNER_USER; }
        if val & 8 != 0 { flags |= MmPteFlags::WRITE_THROUGH; }
        if val & 0x10 != 0 { flags |= MmPteFlags::CACHE_DISABLE; }
        if val & 0x20 != 0 { flags |= MmPteFlags::ACCESSED; }
        if val & 0x40 != 0 { flags |= MmPteFlags::DIRTY; }
        if val & 0x100 != 0 { flags |= MmPteFlags::GLOBAL; }
        Self {
            flags,
            page_frame_number: (val >> 12) & 0xFFFFF,
            protection: MmProtectionMask2::empty(),
        }
    }
}

// ============================================================
// MmPfn - Physical Frame Number descriptor
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MmPfnUnion0 {
    pub fields0: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MmPfnUnion1 {
    pub fields1: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct MmPfn {
    pub flags2: u32,
    pub pfn_number: u64,
    pub used_by1: MmPfnUnion0,
    pub used_by2: u64,
    pub used_by3: u64,
    pub used_by4: MmPfnUnion1,
    pub reference_count_encoded: u32,
    pub creating_process: *mut c_void,
    pub section_object_ptr: *mut c_void,
    pub swap_entry: u64,
    pub color: u32,
    /// Embedded ListEntry used to link PFNs into
    /// free/standby/modified lists. Not present in real
    /// Windows MmPfn, but necessary for correct list
    /// operations in our implementation.
    pub list_entry: ListEntry,
}

pub const MMPFN_VALID_PFN_MASK: u32 = 0x80000000;

// ============================================================
// MmMdl - Memory Descriptor List
// ============================================================

#[repr(C)]
pub struct MmMdl {
    pub next: *mut MmMdl,
    pub size: i32,
    pub mdl_flags: i32,
    pub process: *mut c_void,
    pub start_va: *mut c_void,
    pub byte_offset: u32,
    pub byte_count: u64,
    pub mapped_system_va: *mut c_void,
    pub start_weapon: *mut c_void,
}

pub const MDL_MAPPED_TO_SYSTEM_VA: i32 = 0x0001;
pub const MDL_SOURCE_IS_NONPAGED_POOL: i32 = 0x0002;
pub const MDL_PAGES_LOCKED: i32 = 0x0004;
pub const MDL_MAPPED_TO_SYSTEM_VA_SI: i32 = 0x0008;
pub const MDL_IO_PAGE_READ: i32 = 0x0010;
pub const MDL_WRITE_OPERATION: i32 = 0x0020;
pub const MDL_PARENT_MAPPED_SYSTEM_VA: i32 = 0x0040;
pub const MDL_FREE_EXTRA_PTES: i32 = 0x0080;
pub const MDL_DESCRIBES_AWE: i32 = 0x0100;

// ============================================================
// Spin lock abstraction
// ============================================================

pub struct SpinLock {
    value: AtomicU64,
}

impl core::fmt::Debug for SpinLock {
    fn fmt(&self, f: &mut core::fmt::Formatter<'_>) -> core::fmt::Result {
        f.debug_struct("SpinLock").finish()
    }
}

impl SpinLock {
    pub const fn new() -> Self {
        Self { value: AtomicU64::new(0) }
    }

    pub fn lock(&self) {
        while self.value.compare_exchange_weak(0, 1, Ordering::Acquire, Ordering::Relaxed).is_err() {
            core::hint::spin_loop();
        }
    }

    pub fn acquire(&self) -> SpinLockGuard<'_> {
        self.lock();
        SpinLockGuard { lock: self }
    }

    pub fn release(&self) {
        self.value.store(0, Ordering::Release);
    }
}

pub struct SpinLockGuard<'a> {
    lock: &'a SpinLock,
}

impl Drop for SpinLockGuard<'_> {
    fn drop(&mut self) {
        self.lock.release();
    }
}

// ============================================================
// Mutex abstraction
// ============================================================

pub struct Mutex<T> {
    locked: AtomicU32,
    value: core::cell::UnsafeCell<T>,
}

unsafe impl<T: Send> Send for Mutex<T> {}
unsafe impl<T: Send> Sync for Mutex<T> {}

impl<T> Mutex<T> {
    pub const fn new(value: T) -> Self {
        Self {
            locked: AtomicU32::new(0),
            value: core::cell::UnsafeCell::new(value),
        }
    }

    pub fn lock(&self) -> MutexGuard<'_, T> {
        while self.locked.compare_exchange_weak(0, 1, Ordering::Acquire, Ordering::Relaxed).is_err() {
            core::hint::spin_loop();
        }
        MutexGuard { mutex: self }
    }

    fn get(&self) -> &T {
        unsafe { &*self.value.get() }
    }

    fn get_mut(&self) -> &mut T {
        unsafe { &mut *self.value.get() }
    }
}

pub struct MutexGuard<'a, T> {
    mutex: &'a Mutex<T>,
}

impl<T> core::ops::Deref for MutexGuard<'_, T> {
    type Target = T;
    fn deref(&self) -> &T {
        self.mutex.get()
    }
}

impl<T> core::ops::DerefMut for MutexGuard<'_, T> {
    fn deref_mut(&mut self) -> &mut T {
        self.mutex.get_mut()
    }
}

impl<T> Drop for MutexGuard<'_, T> {
    fn drop(&mut self) {
        self.mutex.locked.store(0, Ordering::Release);
    }
}

// ============================================================
// ListEntry (NT-style doubly linked list)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ListEntry {
    pub flink: *mut ListEntry,
    pub blink: *mut ListEntry,
}

impl ListEntry {
    pub const fn new() -> Self {
        Self {
            flink: core::ptr::null_mut(),
            blink: core::ptr::null_mut(),
        }
    }

    pub fn is_empty(&self) -> bool {
        self.flink == self as *const Self as *mut Self
    }

    pub fn initialize(&mut self) {
        self.flink = self as *mut Self;
        self.blink = self as *mut Self;
    }

    pub fn insert_head(&mut self, entry: &mut ListEntry) {
        entry.flink = self.flink;
        entry.blink = self;
        unsafe { (*self.flink).blink = entry as *mut ListEntry; }
        self.flink = entry as *mut ListEntry;
    }

    pub fn insert_tail(&mut self, entry: &mut ListEntry) {
        entry.flink = self;
        entry.blink = self.blink;
        unsafe { (*self.blink).flink = entry as *mut ListEntry; }
        self.blink = entry as *mut ListEntry;
    }

    pub fn remove_entry(&mut self) {
        unsafe {
            (*self.blink).flink = self.flink;
            (*self.flink).blink = self.blink;
        }
        self.flink = self as *mut Self;
        self.blink = self as *mut Self;
    }

    pub unsafe fn remove(&self) {
        unsafe {
            (*self.blink).flink = self.flink;
            (*self.flink).blink = self.blink;
        }
    }
}

unsafe impl Send for ListEntry {}
unsafe impl Sync for ListEntry {}

// ============================================================
// AVL tree helpers (used by VAD tree)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlBalancedNode {
    pub children: [*mut RtlBalancedNode; 2],
    pub red: u32,
}

unsafe impl Send for RtlBalancedNode {}
unsafe impl Sync for RtlBalancedNode {}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlAvlTree {
    pub root: *mut RtlBalancedNode,
}

unsafe impl Send for RtlAvlTree {}
unsafe impl Sync for RtlAvlTree {}

impl RtlAvlTree {
    pub const fn new() -> Self {
        Self { root: core::ptr::null_mut() }
    }
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlAvlNode {
    pub balanced_node: RtlBalancedNode,
    pub parent: *mut RtlAvlNode,
    pub virtual_address: u64,
    pub size_in_bytes: u64,
}

unsafe impl Send for RtlAvlNode {}
unsafe impl Sync for RtlAvlNode {}

// ============================================================
// Pool tag
// ============================================================

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct PoolTag(pub [u8; 4]);

impl PoolTag {
    pub fn new(bytes: [u8; 4]) -> Self {
        Self(bytes)
    }
}

pub const TAG_MSPA: PoolTag = PoolTag(*b"MmSp");
pub const TAG_MMPF: PoolTag = PoolTag(*b"MmPf");
pub const TAG_MMDL: PoolTag = PoolTag(*b"MmDl");
pub const TAG_MMVA: PoolTag = PoolTag(*b"MmVa");
pub const TAG_MMVAD: PoolTag = PoolTag(*b"MmVd");
pub const TAG_MMWS: PoolTag = PoolTag(*b"MmWs");
pub const TAG_MMSB: PoolTag = PoolTag(*b"MmSb");
pub const TAG_MMSO: PoolTag = PoolTag(*b"MmSo");
pub const TAG_POOL: PoolTag = PoolTag(*b"Pool");
pub const TAG_CC: PoolTag = PoolTag(*b" Cc ");

// ============================================================
// Modified / Standby / Free / Zeroing page list heads
// ============================================================

#[repr(C)]
pub struct ModifiedPageListHead {
    pub mu: SpinLock,
    pub list_heads: [ListEntry; 1],
    pub number_of_entries: u32,
}

impl ModifiedPageListHead {
    pub const fn new() -> Self {
        Self {
            mu: SpinLock::new(),
            list_heads: [ListEntry::new(); 1],
            number_of_entries: 0,
        }
    }
}

#[repr(C)]
pub struct StandbyPageListHead {
    pub mu: SpinLock,
    pub list_heads: [ListEntry; 8],
    pub number_of_entries: u32,
}

impl StandbyPageListHead {
    pub const fn new() -> Self {
        Self {
            mu: SpinLock::new(),
            list_heads: [ListEntry::new(); 8],
            number_of_entries: 0,
        }
    }
}

#[repr(C)]
pub struct FreePageListHead {
    pub mu: SpinLock,
    pub list_heads: [ListEntry; 1],
    pub number_of_entries: u32,
}

impl FreePageListHead {
    pub const fn new() -> Self {
        Self {
            mu: SpinLock::new(),
            list_heads: [ListEntry::new(); 1],
            number_of_entries: 0,
        }
    }
}

#[repr(C)]
pub struct ZeroingPageListHead {
    pub mu: SpinLock,
    pub list_heads: [ListEntry; 1],
    pub number_of_entries: u32,
}

impl ZeroingPageListHead {
    pub const fn new() -> Self {
        Self {
            mu: SpinLock::new(),
            list_heads: [ListEntry::new(); 1],
            number_of_entries: 0,
        }
    }
}

#[repr(C)]
pub struct SystemSpaceHall {
    pub mu: SpinLock,
    pub count: u32,
}

impl SystemSpaceHall {
    pub const fn new() -> Self {
        Self {
            mu: SpinLock::new(),
            count: 0,
        }
    }
}

// ============================================================
// Global state
// ============================================================

pub static MmAvailablePages: AtomicUsize = AtomicUsize::new(0);
pub static MmResidentAvailablePages: AtomicUsize = AtomicUsize::new(0);
pub static MmModifiedPageListHead: Mutex<ModifiedPageListHead> = Mutex::new(ModifiedPageListHead::new());
pub static MmStandbyPageListHead: Mutex<StandbyPageListHead> = Mutex::new(StandbyPageListHead::new());
pub static MmFreePageListHead: Mutex<FreePageListHead> = Mutex::new(FreePageListHead::new());
pub static MmZeroingPageListHead: Mutex<ZeroingPageListHead> = Mutex::new(ZeroingPageListHead::new());
pub static MmSystemSpaceHall: Mutex<SystemSpaceHall> = Mutex::new(SystemSpaceHall::new());
pub static MmWorkingSetManagerRunning: AtomicU32 = AtomicU32::new(0);

pub struct SyncUnsafeCellWrapper<T: ?Sized>(UnsafeCell<T>);
unsafe impl<T: ?Sized> Send for SyncUnsafeCellWrapper<T> {}
unsafe impl<T: ?Sized> Sync for SyncUnsafeCellWrapper<T> {}
impl<T> SyncUnsafeCellWrapper<T> {
    pub const fn new(val: T) -> Self { Self(UnsafeCell::new(val)) }
}
impl<T: ?Sized> SyncUnsafeCellWrapper<T> {
    pub fn get(&self) -> *mut T { self.0.get() }
}

pub static MmPfnDatabase: SyncUnsafeCellWrapper<*mut MmPfn> = SyncUnsafeCellWrapper::new(core::ptr::null_mut());
pub static MmPfnDatabaseLength: AtomicUsize = AtomicUsize::new(0);

// ============================================================
// Logging macros
// ============================================================
// Utility functions
// ============================================================

#[inline]
pub fn mi_bytes_to_pages(bytes: usize) -> usize {
    (bytes + PAGE_SIZE_X64 - 1) >> PAGE_SHIFT
}

#[inline]
pub fn mi_page_align_address(virtual_address: u64) -> u64 {
    virtual_address & (PAGE_MASK_X64 as u64)
}

#[inline]
pub fn mi_page_offset(virtual_address: u64) -> usize {
    virtual_address as usize & 0xFFF
}

#[inline]
pub fn mi_get_pfn_element(pfn_number: usize) -> &'static mut MmPfn {
    unsafe {
        let base = *MmPfnDatabase.get();
        assert!(!base.is_null(), "PFN database not initialized");
        assert!(pfn_number < MmPfnDatabaseLength.load(Ordering::Relaxed),
            "PFN number {} out of range", pfn_number);
        &mut *base.add(pfn_number)
    }
}

#[inline]
pub fn mi_get_pfn_number_from_address(physical_address: u64) -> usize {
    physical_address as usize >> PAGE_SHIFT
}

// ============================================================
// IRQL simulation
// ============================================================

static CURRENT_IRQL: AtomicU8 = AtomicU8::new(0);

#[inline]
pub fn ke_raise_irql(new_irql: Irql) -> Irql {
    CURRENT_IRQL.swap(new_irql, Ordering::SeqCst)
}

#[inline]
pub fn ke_lower_irql(old_irql: Irql) {
    CURRENT_IRQL.store(old_irql, Ordering::SeqCst);
}

// ============================================================
// Missing functions and types
// ============================================================

pub type GenericMapping = u32;
pub const TAG_PFLF: PoolTag = PoolTag(*b"PfLF");

pub fn mi_get_virtual_address_pxe(virtual_address: u64) -> u64 {
    0xFFFF_F680_0000_0000 | ((virtual_address >> 39) & 0x1FF) << 3
}

pub fn mi_get_virtual_address_pde(virtual_address: u64) -> u64 {
    0xFFFF_F680_0000_0000 | ((virtual_address >> 30) & 0x1FF) << 3
}

pub fn mi_get_virtual_address_pte(virtual_address: u64) -> u64 {
    0xFFFF_F680_0000_0000 | ((virtual_address >> 12) & 0x1FF) << 3
}

pub fn mi_get_physical_address_from_pfn(pfn_number: usize) -> u64 {
    (pfn_number as u64) << PAGE_SHIFT
}

// ============================================================
// MmInitialize
// ============================================================

pub fn mm_initialize() {
    mm_dbg!("MmInitialize: beginning memory manager initialization");

    unsafe {
        MmAvailablePages.store(0, Ordering::Relaxed);
        MmResidentAvailablePages.store(0, Ordering::Relaxed);
    }

    pagefile::mm_page_file_init();
    vad::mm_vad_init();
    ws::mm_ws_init();
    cache::cc_initialize_cache_manager();
    pool::mm_pool_init();

    mm_dbg!("MmInitialize: memory manager initialization complete");
}
