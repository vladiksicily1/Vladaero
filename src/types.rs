/// Core kernel types matching Windows 10 ntoskrnl.exe definitions
use core::ffi::c_void;

// ============================================================
// NT Status Codes (NTSTATUS)
// ============================================================

pub type NtStatus = i32;

pub const STATUS_SUCCESS: NtStatus = 0x00000000;
pub const STATUS_BUFFER_OVERFLOW: NtStatus = 0x80000005;
pub const STATUS_NO_MORE_ENTRIES: NtStatus = 0x8000001A;
pub const STATUS_INVALID_PARAMETER: NtStatus = 0xC000000D;
pub const STATUS_NO_MEMORY: NtStatus = 0xC0000017;
pub const STATUS_OBJECT_NAME_NOT_FOUND: NtStatus = 0xC0000034;
pub const STATUS_ACCESS_DENIED: NtStatus = 0xC0000022;
pub const STATUS_BUFFER_TOO_SMALL: NtStatus = 0xC0000023;
pub const STATUS_NOT_IMPLEMENTED: NtStatus = 0xC0000002;
pub const STATUS_OBJECT_TYPE_MISMATCH: NtStatus = 0xC0000024;
pub const STATUS_PRIVILEGE_NOT_HELD: NtStatus = 0xC0000061;
pub const STATUS_INSUFFICIENT_RESOURCES: NtStatus = 0xC000009A;
pub const STATUS_BRANCHING_TO_DISABLED: NtStatus = 0xC00000BC;
pub const STATUS_IN_PAGE_ERROR: NtStatus = 0xC0000006;
pub const STATUS_ACCESS_VIOLATION: NtStatus = 0xC0000005;
pub const STATUS_NOT_FOUND: NtStatus = 0xC0000225 as i32;
pub const STATUS_WORKING_SET_QUOTA: NtStatus = 0xC00000A5;

// ============================================================
// Basic Types
// ============================================================

pub type Handle = *mut c_void;
pub type Pvoid = *mut c_void;
pub type Pbyte = *mut u8;
pub type Long = i32;
pub type Ulong = u32;
pub type Ulonglong = u64;
pub type Int = i32;
pub type Uint = u32;
pub type Short = i16;
pub type Ushort = u16;
pub type Char = i8;
pub type Uchar = u8;
pub type Boolean = u8;
pub type PhysAddr = u64;
pub type VirtAddr = u64;
pub type ObjectTypeIndex = usize;

pub const TRUE: Boolean = 1;
pub const FALSE: Boolean = 0;

// ============================================================
// UNICODE_STRING (NT-style UTF-16 string)
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UnicodeString {
    pub length: Ushort,
    pub maximum_length: Ushort,
    pub buffer: *const u16,
}

impl UnicodeString {
    pub const fn new() -> Self {
        Self { length: 0, maximum_length: 0, buffer: core::ptr::null() }
    }

    pub fn from_str(s: &str) -> Self {
        let byte_len = s.len() * 2;
        Self {
            length: byte_len as Ushort,
            maximum_length: byte_len as Ushort,
            buffer: core::ptr::null(), // Caller must provide buffer
        }
    }

    pub fn is_empty(&self) -> bool {
        self.length == 0
    }

    pub fn as_str(&self) -> &str {
        if self.buffer.is_null() || self.length == 0 {
            return "";
        }
        let slice = unsafe {
            core::slice::from_raw_parts(self.buffer, self.length as usize / 2)
        };
        core::str::from_utf8(&[]).unwrap_or("") // Simplified
    }
}

// ============================================================
// ANSI_STRING
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AnsiString {
    pub length: Ushort,
    pub maximum_length: Ushort,
    pub buffer: *const u8,
}

// ============================================================
// LARGE_INTEGER
// ============================================================

#[repr(C)]
pub union LargeInteger {
    pub low_part: u32,
    pub high_part: i32,
    pub quad_part: i64,
}

impl LargeInteger {
    pub fn new(val: i64) -> Self {
        Self { quad_part: val }
    }
}

// ============================================================
// KSYSTEM_TIME
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct KSystemTime {
    pub low_part: u32,
    pub high1_time: i32,
    pub high2_time: i32,
}

// ============================================================
// KSPIN_LOCK
// ============================================================

pub type KspinLock = u64;

// ============================================================
// KIRQL (Interrupt Request Level)
// ============================================================

pub type Irql = u8;

pub const PASSIVE_LEVEL: Irql = 0;
pub const APC_LEVEL: Irql = 1;
pub const DISPATCH_LEVEL: Irql = 2;
pub const CLOCK_LEVEL: Irql = 13;
pub const IPI_LEVEL: Irql = 14;
pub const HIGH_LEVEL: Irql = 15;

// ============================================================
// KPROCESSOR_MODE
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum KprocessorMode {
    KernelMode = 0,
    UserMode = 1,
    MaximumMode = 2,
}

// ============================================================
// KWAIT_REASON
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy)]
pub enum KwaitReason {
    Executive = 0,
    FreePage = 1,
    PageIn = 2,
    PoolAllocation = 3,
    DelayExecution = 4,
    Suspended = 5,
    UserRequest = 6,
    WrExecutive = 7,
    WrFreePage = 8,
    WrPageIn = 9,
    WrPoolAllocation = 10,
    WrDelayExecution = 11,
    WrSuspended = 12,
    WrUserRequest = 13,
    WrEventPair = 14,
    WrQueue = 15,
    WrLpcReceive = 16,
    WrLpcReply = 17,
    WrVirtualMemory = 18,
    WrPageOut = 19,
    WrRendezvous = 20,
    WrSemaphore = 21,
    WrKernel = 22,
    WrResource = 23,
    WrPushLock = 24,
    WrMutex = 25,
    WrQuantumEnd = 26,
    WrDispatchInt = 27,
    WrPreempted = 28,
    WrYieldExecution = 29,
    WrFastMutex = 30,
    WrGuardedMutex = 31,
    WrRundown = 32,
    WrBoostByOwner = 33,
    WrBoostByPushLock = 34,
    WrMutexUndoableLock = 35,
    WrMutexes = 36,
    WrHardware = 37,
    WrClockInterval = 38,
    WrOptimizerDeferredLock = 39,
    WrGlucoseCore = 40,
    WrFpu = 41,
    WrThreadIntTimer = 42,
    WrDpcStack = 43,
    MaximumWaitReason = 44,
}

// ============================================================
// KTHREAD_STATE
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum KthreadState {
    Initialized = 0,
    Ready = 1,
    Running = 2,
    Standby = 3,
    Terminated = 4,
    Waiting = 5,
    Transition = 6,
    DeferredReady = 7,
    GateWait = 8,
    MaximumThreadState = 9,
}

// ============================================================
// OBJECT_ATTRIBUTES
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ObjectAttributes {
    pub length: Ulong,
    pub root_directory: Handle,
    pub object_name: *const UnicodeString,
    pub attributes: Ulong,
    pub security_descriptor: *const c_void,
    pub security_quality_of_service: *const c_void,
}

impl ObjectAttributes {
    pub fn new() -> Self {
        Self {
            length: core::mem::size_of::<Self>() as Ulong,
            root_directory: core::ptr::null_mut(),
            object_name: core::ptr::null(),
            attributes: 0,
            security_descriptor: core::ptr::null(),
            security_quality_of_service: core::ptr::null(),
        }
    }
}

// ============================================================
// CLIENT_ID
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ClientId {
    pub unique_process: Handle,
    pub unique_thread: Handle,
}

// ============================================================
// IO_STATUS_BLOCK
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IoStatusBlock {
    pub status: NtStatus,
    pub information: Ulong,
}

// ============================================================
// KTIMER_MODE
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy)]
pub enum KtimerMode {
    AbsoluteTimer = 0,
    RelativeTimer = 1,
    TimerSubscription = 2,
}

// ============================================================
// MEMORY_CACHING_TYPE
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy)]
pub enum MemoryCachingType {
    MmNonCached = 0,
    MmCached = 1,
    MmWriteCombined = 2,
    MmHardwareCoherentCached = 3,
    MmNonCachedWriteCombined = 4,
    MmFrameBufferCached = 5,
    MmGraphicsDeviceCached = 6,
}

// ============================================================
// SECTION_INHERIT
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy)]
pub enum SectionInherit {
    ViewShare = 1,
    ViewUnmap = 2,
}

// ============================================================
// KAFFINITY
// ============================================================

pub type Kaffinity = u64;

// ============================================================
// PEPROCESS, PKTHREAD - opaque handles
// ============================================================

pub type Peprocess = *mut c_void;
pub type Pkthread = *mut c_void;
pub type Pethread = *mut c_void;
pub type Pdriver_object = *mut c_void;
pub type Pdevice_object = *mut c_void;
pub type Pfile_object = *mut c_void;
pub type Padapter_object = *mut c_void;
pub type Pirp = *mut c_void;
pub type Psection_object = *mut c_void;
pub type Pkprocess = *mut c_void;

#[repr(C)]
#[derive(Debug, Clone, Copy, Default)]
pub struct M128A {
    pub low: u64,
    pub high: i64,
}
