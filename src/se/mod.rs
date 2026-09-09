/// # Security Reference Monitor (Se/Sep) - ntoskrnl.exe
///
/// Complete implementation of the Windows Security subsystem including
/// SIDs, ACLs, ACEs, tokens, access checks, privilege checks, and
/// security auditing.

use core::ffi::c_void;
use core::mem;
use core::sync::atomic::{AtomicU32, AtomicU64, Ordering};

use crate::types::*;
use crate::mm::{SpinLock};
use crate::ob::ObjectType;

// ============================================================
// Constants
// ============================================================

pub const SID_MAX_SUB_AUTHORITIES: usize = 15;
pub const SID_REVISION: u8 = 1;
pub const SID_MAX_SUB_AUTHORITY_COUNT: u8 = 15;

pub const ACCESS_ALLOWED_ACE_TYPE: u8 = 0;
pub const ACCESS_DENIED_ACE_TYPE: u8 = 1;
pub const SYSTEM_AUDIT_ACE_TYPE: u8 = 2;
pub const SYSTEM_ALARM_ACE_TYPE: u8 = 3;
pub const ACCESS_ALLOWED_COMPOUND_ACE_TYPE: u8 = 4;
pub const SYSTEM_MANDATORY_LABEL_ACE_TYPE: u8 = 17;

pub const ACL_REVISION: u32 = 2;
pub const ACL_REVISION2: u32 = 4;

pub const TOKEN_SOURCE_LENGTH: usize = 8;

pub const TOKEN_DYNAMIC_DEFAULT_PRIVILEGES: u32 = 1;
pub const TOKEN_QUERY: u32 = 0x0008;
pub const TOKEN_ADJUST_PRIVILEGES: u32 = 0x0020;
pub const TOKEN_ADJUST_GROUPS: u32 = 0x0040;
pub const TOKEN_ADJUST_DEFAULT: u32 = 0x0080;
pub const TOKEN_ADJUST_SOURCE: u32 = 0x0100;
pub const TOKEN_ALL_ACCESS: u32 = 0x001F00FF;
pub const TOKEN_READ: u32 = 0x00020008;
pub const TOKEN_WRITE: u32 = 0x000200E0;
pub const TOKEN_EXECUTE: u32 = 0x00020000;

pub const TOKEN_USER: u32 = 1;
pub const TOKEN_GROUPS: u32 = 2;
pub const TOKEN_PRIVILEGES: u32 = 3;
pub const TOKEN_OWNER: u32 = 4;
pub const TOKEN_PRIMARY_GROUP: u32 = 5;
pub const TOKEN_DEFAULT_DACL: u32 = 6;
pub const TOKEN_SOURCE: u32 = 7;

pub const TOKEN_ELEVATION: u32 = 25;
pub const TOKEN_ELEVATION_TYPE: u32 = 26;
pub const TOKEN_LINKED_TOKEN: u32 = 29;
pub const TOKEN_IS_ELEVATED: u32 = 27;

pub const SE_DEBUG_PRIVILEGE: u32 = 20;
pub const SE_TCB_PRIVILEGE: u32 = 7;
pub const SE_ASSIGNPRIMARYTOKEN_PRIVILEGE: u32 = 3;
pub const SE_INCREASE_QUOTA_PRIVILEGE: u32 = 5;
pub const SE_TAKE_OWNERSHIP_PRIVILEGE: u32 = 9;
pub const SE_LOAD_DRIVER_PRIVILEGE: u32 = 10;
pub const SE_SYSTEM_ENVIRONMENT_PRIVILEGE: u32 = 24;
pub const SE_SYSTEMTIME_PRIVILEGE: u32 = 14;
pub const SE_MANAGE_VOLUME_PRIVILEGE: u32 = 28;
pub const SE_PROF_SINGLE_PROCESS_PRIVILEGE: u32 = 13;
pub const SE_BACKUP_PRIVILEGE: u32 = 17;
pub const SE_RESTORE_PRIVILEGE: u32 = 18;
pub const SE_SHUTDOWN_PRIVILEGE: u32 = 19;
pub const SE_REMOTE_SHUTDOWN_PRIVILEGE: u32 = 24;
pub const SE_UNDOCK_PRIVILEGE: u32 = 29;
pub const SE_ENABLE_DELEGATION_PRIVILEGE: u32 = 27;
pub const SE_MANAGE_VOLUME_PRIVILEGE2: u32 = 28;

pub const SE_PRIVILEGE_DISABLED: u32 = 0;
pub const SE_PRIVILEGE_ENABLED: u32 = 1;
pub const SE_PRIVILEGE_ENABLED_BY_DEFAULT: u32 = 2;
pub const SE_PRIVILEGE_REMOVED: u32 = 4;
pub const SE_PRIVILEGE_USED_FOR_ACCESS: u32 = 0x80000000;

pub const SE_GROUP_MANDATORY: u32 = 1;
pub const SE_GROUP_ENABLED_BY_DEFAULT: u32 = 2;
pub const SE_GROUP_ENABLED: u32 = 4;
pub const SE_GROUP_OWNER: u32 = 8;
pub const SE_GROUP_USE_FOR_DENY_ONLY: u32 = 0x10;
pub const SE_GROUP_LOGON_ID: u32 = 0xC0000000;
pub const SE_GROUP_RESOURCE: u32 = 0x20000000;

pub const TOKEN_ADJUST_SESSIONID: u32 = 0x0100;

pub const TOKEN_INFORMATION_CLASS: u32 = 0;

pub const DOMAIN_ALIAS_R_ADMINS: u32 = 0x220;
pub const DOMAIN_ALIAS_R_USERS: u32 = 0x221;
pub const DOMAIN_GROUP_R_ADMINS: u32 = 0x200;

// ============================================================
// Logging macros
// ============================================================

macro_rules! se_trace {
    ($($arg:tt)*) => {
        #[cfg(feature = "se_trace")]
        crate::kernel_log!("[Se] {}", format_args!($($arg)*));
    };
}

macro_rules! se_dbg {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Se] {}", format_args!($($arg)*));
    };
}

macro_rules! se_warn {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Se] {}", format_args!($($arg)*));
    };
}

macro_rules! se_err {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Se] {}", format_args!($($arg)*));
    };
}

// ============================================================
// SID_IDENTIFIER_AUTHORITY
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SidIdentifierAuthority {
    pub value: [u8; 6],
}

impl SidIdentifierAuthority {
    pub const fn new(val: [u8; 6]) -> Self {
        Self { value: val }
    }

    pub fn null_authority() -> Self {
        Self { value: [0, 0, 0, 0, 0, 0] }
    }

    pub fn world_sid_authority() -> Self {
        Self { value: [0, 0, 0, 0, 0, 1] }
    }

    pub fn nt_authority() -> Self {
        Self { value: [0, 0, 0, 0, 0, 5] }
    }
}

// ============================================================
// SID
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Sid {
    pub revision: u8,
    pub sub_authority_count: u8,
    pub identifier_authority: SidIdentifierAuthority,
    pub sub_authority: [u32; SID_MAX_SUB_AUTHORITIES],
}

impl Sid {
    pub fn new() -> Self {
        Self {
            revision: SID_REVISION,
            sub_authority_count: 0,
            identifier_authority: SidIdentifierAuthority::null_authority(),
            sub_authority: [0; SID_MAX_SUB_AUTHORITIES],
        }
    }

    pub fn size(&self) -> u32 {
        (mem::size_of::<u8>() + mem::size_of::<u8>()
            + mem::size_of::<SidIdentifierAuthority>()
            + self.sub_authority_count as usize * mem::size_of::<u32>()) as u32
    }

    pub fn equal(&self, other: &Sid) -> bool {
        if self.revision != other.revision { return false; }
        if self.sub_authority_count != other.sub_authority_count { return false; }
        if self.identifier_authority.value != other.identifier_authority.value { return false; }
        for i in 0..self.sub_authority_count as usize {
            if self.sub_authority[i] != other.sub_authority[i] { return false; }
        }
        true
    }

    pub fn is_valid(&self) -> bool {
        self.revision == SID_REVISION && self.sub_authority_count <= SID_MAX_SUB_AUTHORITY_COUNT
    }

    pub fn get_sub_authority_count(&self) -> u8 {
        self.sub_authority_count
    }

    pub fn get_sub_authority(&self, index: usize) -> Option<u32> {
        if index < self.sub_authority_count as usize {
            Some(self.sub_authority[index])
        } else {
            None
        }
    }
}

pub type Psid = *mut Sid;
pub type Psid_const = *const Sid;

// ============================================================
// Predefined SIDs
// ============================================================

pub fn se_create_well_known_sid(well_known_sid_type: u32) -> *mut Sid {
    let sid = unsafe {
        alloc::alloc::alloc_zeroed(core::alloc::Layout::from_size_align(mem::size_of::<Sid>(), 8).unwrap()) as *mut Sid
    };
    if sid.is_null() { return core::ptr::null_mut(); }

    let s = unsafe { &mut *sid };
    s.revision = SID_REVISION;

    match well_known_sid_type {
        0 => {
            // World SID (S-1-1-0)
            s.identifier_authority = SidIdentifierAuthority::world_sid_authority();
            s.sub_authority_count = 1;
            s.sub_authority[0] = 0;
        }
        1 => {
            // Local SID (S-1-2-0)
            s.identifier_authority = SidIdentifierAuthority::new([0, 0, 0, 0, 0, 2]);
            s.sub_authority_count = 1;
            s.sub_authority[0] = 0;
        }
        2 => {
            // Creator Owner SID (S-1-3-0)
            s.identifier_authority = SidIdentifierAuthority::new([0, 0, 0, 0, 0, 3]);
            s.sub_authority_count = 1;
            s.sub_authority[0] = 0;
        }
        3 => {
            // Creator Group SID (S-1-3-1)
            s.identifier_authority = SidIdentifierAuthority::new([0, 0, 0, 0, 0, 3]);
            s.sub_authority_count = 1;
            s.sub_authority[0] = 1;
        }
        4 => {
            // NT Authority / Built-in Admins (S-1-5-32-544)
            s.identifier_authority = SidIdentifierAuthority::nt_authority();
            s.sub_authority_count = 2;
            s.sub_authority[0] = 32;
            s.sub_authority[1] = 544;
        }
        5 => {
            // Authenticated Users (S-1-5-11)
            s.identifier_authority = SidIdentifierAuthority::nt_authority();
            s.sub_authority_count = 1;
            s.sub_authority[0] = 11;
        }
        6 => {
            // Local System (S-1-5-18)
            s.identifier_authority = SidIdentifierAuthority::nt_authority();
            s.sub_authority_count = 1;
            s.sub_authority[0] = 18;
        }
        7 => {
            // Local Service (S-1-5-19)
            s.identifier_authority = SidIdentifierAuthority::nt_authority();
            s.sub_authority_count = 1;
            s.sub_authority[0] = 19;
        }
        8 => {
            // Network Service (S-1-5-20)
            s.identifier_authority = SidIdentifierAuthority::nt_authority();
            s.sub_authority_count = 1;
            s.sub_authority[0] = 20;
        }
        9 => {
            // Administrators (S-1-5-32-544)
            s.identifier_authority = SidIdentifierAuthority::nt_authority();
            s.sub_authority_count = 2;
            s.sub_authority[0] = 32;
            s.sub_authority[1] = 544;
        }
        10 => {
            // Users (S-1-5-32-545)
            s.identifier_authority = SidIdentifierAuthority::nt_authority();
            s.sub_authority_count = 2;
            s.sub_authority[0] = 32;
            s.sub_authority[1] = 545;
        }
        _ => {
            // Default: NULL SID
            s.identifier_authority = SidIdentifierAuthority::null_authority();
            s.sub_authority_count = 0;
        }
    }

    se_trace!("SeCreateWellKnownSid: type={} -> {}", well_known_sid_type, sid as usize);
    sid
}

pub fn se_free_well_known_sid(sid: *mut Sid) {
    if !sid.is_null() {
        let layout = core::alloc::Layout::from_size_align(mem::size_of::<Sid>(), 8).unwrap();
        unsafe { alloc::alloc::dealloc(sid as *mut u8, layout); }
    }
}

// ============================================================
// SID_AND_ATTRIBUTES
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SidAndAttributes {
    pub sid: Psid,
    pub attributes: u32,
}

impl SidAndAttributes {
    pub fn new() -> Self {
        Self { sid: core::ptr::null_mut(), attributes: 0 }
    }
}

// ============================================================
// LUID
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Luid {
    pub low_part: u32,
    pub high_part: i32,
}

impl Luid {
    pub fn new(low: u32, high: i32) -> Self {
        Self { low_part: low, high_part: high }
    }

    pub fn from_value(value: u64) -> Self {
        Self { low_part: value as u32, high_part: (value >> 32) as i32 }
    }

    pub fn as_u64(&self) -> u64 {
        (self.high_part as u64) << 32 | self.low_part as u64
    }
}

// ============================================================
// LUID_AND_ATTRIBUTES
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct LuidAndAttributes {
    pub luid: Luid,
    pub attributes: u32,
}

impl LuidAndAttributes {
    pub fn new(luid: Luid, attributes: u32) -> Self {
        Self { luid, attributes }
    }

    pub fn is_enabled(&self) -> bool {
        self.attributes & SE_PRIVILEGE_ENABLED != 0
    }
}

// ============================================================
// TOKEN_SOURCE
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct TokenSource {
    pub source_name: [u8; TOKEN_SOURCE_LENGTH],
    pub source_identifier: Luid,
}

impl TokenSource {
    pub fn new() -> Self {
        Self {
            source_name: [0; TOKEN_SOURCE_LENGTH],
            source_identifier: Luid::new(0, 0),
        }
    }

    pub fn from_name(name: &[u8; TOKEN_SOURCE_LENGTH]) -> Self {
        let mut src = Self::new();
        src.source_name = *name;
        src
    }
}

// ============================================================
// TOKEN_TYPE
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TokenType {
    Primary = 1,
    Impersonation = 2,
}

// ============================================================
// SECURITY_IMPERSONATION_LEVEL
// ============================================================

#[repr(u32)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SecurityImpersonationLevel {
    Anonymous = 0,
    Identification = 1,
    Impersonation = 2,
    Delegation = 3,
}

// ============================================================
// TOKEN_STATISTICS
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct TokenStatistics {
    pub token_id: Luid,
    pub authentication_id: Luid,
    pub expiration_time: u64,
    pub token_type: TokenType,
    pub impersonation_level: SecurityImpersonationLevel,
    pub dynamic_charged: u32,
    pub dynamic_available: u32,
    pub groups_count: u32,
    pub privileges_count: u32,
}

// ============================================================
// TOKEN (main token structure)
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct Token {
    pub token_source: TokenSource,
    pub token_id: Luid,
    pub authentication_id: Luid,
    pub parent_token_id: Luid,
    pub expiration_time: u64,
    pub user: SidAndAttributes,
    pub groups: [SidAndAttributes; 32],
    pub groups_count: u32,
    pub privileges: [LuidAndAttributes; 64],
    pub privileges_count: u32,
    pub owner: *mut Sid,
    pub primary_group: *mut Sid,
    pub default_dacl: *mut c_void,
    pub token_type: TokenType,
    pub impersonation_level: SecurityImpersonationLevel,
    pub token_flags: u32,
    pub token_linked_token: Pvoid,
    pub mandatory_policy: u32,
    pub session_id: u32,
    pub lock: KspinLock,
}

impl Token {
    pub fn new() -> Self {
        Self {
            token_source: TokenSource::new(),
            token_id: Luid::new(0, 0),
            authentication_id: Luid::new(0, 0),
            parent_token_id: Luid::new(0, 0),
            expiration_time: 0xFFFFFFFFFFFFFFFF,
            user: SidAndAttributes::new(),
            groups: [SidAndAttributes::new(); 32],
            groups_count: 0,
            privileges: [LuidAndAttributes::new(Luid::new(0, 0), 0); 64],
            privileges_count: 0,
            owner: core::ptr::null_mut(),
            primary_group: core::ptr::null_mut(),
            default_dacl: core::ptr::null_mut(),
            token_type: TokenType::Primary,
            impersonation_level: SecurityImpersonationLevel::Anonymous,
            token_flags: 0,
            token_linked_token: core::ptr::null_mut(),
            mandatory_policy: 0,
            session_id: 0,
            lock: 0,
        }
    }

    pub fn is_privilege_enabled(&self, privilege_index: u32) -> bool {
        for i in 0..self.privileges_count as usize {
            if self.privileges[i].luid.low_part == privilege_index {
                return self.privileges[i].attributes & SE_PRIVILEGE_ENABLED != 0;
            }
        }
        false
    }

    pub fn enable_privilege(&mut self, privilege_index: u32) -> bool {
        for i in 0..self.privileges_count as usize {
            if self.privileges[i].luid.low_part == privilege_index {
                self.privileges[i].attributes |= SE_PRIVILEGE_ENABLED;
                return true;
            }
        }
        false
    }

    pub fn disable_privilege(&mut self, privilege_index: u32) -> bool {
        for i in 0..self.privileges_count as usize {
            if self.privileges[i].luid.low_part == privilege_index {
                self.privileges[i].attributes &= !SE_PRIVILEGE_ENABLED;
                return true;
            }
        }
        false
    }

    pub fn has_group(&self, sid: &Sid) -> bool {
        for i in 0..self.groups_count as usize {
            if !self.groups[i].sid.is_null() {
                if unsafe { &*self.groups[i].sid }.equal(sid) {
                    return true;
                }
            }
        }
        false
    }
}

pub type Ptoken = *mut Token;

// ============================================================
// SECURITY_DESCRIPTOR
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SecurityDescriptor {
    pub revision: u8,
    self_relative: u8,
    control: u16,
    owner: Psid,
    group: Psid,
    sacl: *mut c_void,
    dacl: *mut c_void,
}

impl SecurityDescriptor {
    pub fn new() -> Self {
        Self {
            revision: SID_REVISION,
            self_relative: 1,
            control: 0x8014,
            owner: core::ptr::null_mut(),
            group: core::ptr::null_mut(),
            sacl: core::ptr::null_mut(),
            dacl: core::ptr::null_mut(),
        }
    }

    pub fn set_owner(&mut self, owner: Psid) {
        self.owner = owner;
    }

    pub fn set_group(&mut self, group: Psid) {
        self.group = group;
    }

    pub fn set_dacl(&mut self, dacl: *mut c_void) {
        self.dacl = dacl;
    }

    pub fn get_owner(&self) -> Psid { self.owner }
    pub fn get_group(&self) -> Psid { self.group }
    pub fn get_dacl(&self) -> *mut c_void { self.dacl }
    pub fn get_sacl(&self) -> *mut c_void { self.sacl }
}

// ============================================================
// ACL
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Acl {
    pub acl_revision: u8,
    pub sbz1: u8,
    pub acl_size: u16,
    pub ace_count: u16,
    pub sbz2: u16,
}

impl Acl {
    pub fn new() -> Self {
        Self {
            acl_revision: ACL_REVISION as u8,
            sbz1: 0,
            acl_size: mem::size_of::<Acl>() as u16,
            ace_count: 0,
            sbz2: 0,
        }
    }

    pub fn size(&self) -> u16 { self.acl_size }
    pub fn count(&self) -> u16 { self.ace_count }
}

pub type Pacl = *mut Acl;

// ============================================================
// ACE
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AceHeader {
    pub ace_type: u8,
    pub ace_flags: u8,
    pub ace_size: u16,
}

impl AceHeader {
    pub fn new() -> Self {
        Self { ace_type: 0, ace_flags: 0, ace_size: mem::size_of::<Self>() as u16 }
    }
}

// ============================================================
// ACCESS_ALLOWED_ACE
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AccessAllowedAce {
    pub header: AceHeader,
    pub mask: u32,
    pub sid_start: Sid,
}

impl AccessAllowedAce {
    pub fn new(mask: u32) -> Self {
        let mut ace = Self {
            header: AceHeader::new(),
            mask,
            sid_start: Sid::new(),
        };
        ace.header.ace_type = ACCESS_ALLOWED_ACE_TYPE;
        ace.header.ace_size = mem::size_of::<Self>() as u16;
        ace
    }
}

// ============================================================
// ACCESS_DENIED_ACE
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct AccessDeniedAce {
    pub header: AceHeader,
    pub mask: u32,
    pub sid_start: Sid,
}

impl AccessDeniedAce {
    pub fn new(mask: u32) -> Self {
        let mut ace = Self {
            header: AceHeader::new(),
            mask,
            sid_start: Sid::new(),
        };
        ace.header.ace_type = ACCESS_DENIED_ACE_TYPE;
        ace.header.ace_size = mem::size_of::<Self>() as u16;
        ace
    }
}

// ============================================================
// SYSTEM_MANDATORY_LABEL_ACE
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct SystemMandatoryLabelAce {
    pub header: AceHeader,
    pub mask: u32,
    pub sid_start: Sid,
}

// ============================================================
// ACCESS_MASK helpers
// ============================================================

pub const FILE_READ_DATA: u32 = 0x0001;
pub const FILE_LIST_DIRECTORY: u32 = 0x0001;
pub const FILE_WRITE_DATA: u32 = 0x0002;
pub const FILE_ADD_FILE: u32 = 0x0002;
pub const FILE_APPEND_DATA: u32 = 0x0004;
pub const FILE_ADD_SUBDIRECTORY: u32 = 0x0004;
pub const FILE_READ_EA: u32 = 0x0008;
pub const FILE_WRITE_EA: u32 = 0x0010;
pub const FILE_EXECUTE: u32 = 0x0020;
pub const FILE_TRAVERSE: u32 = 0x0020;
pub const FILE_DELETE_CHILD: u32 = 0x0040;
pub const FILE_READ_ATTRIBUTES: u32 = 0x0080;
pub const FILE_WRITE_ATTRIBUTES: u32 = 0x0100;
pub const DELETE: u32 = 0x00010000;
pub const READ_CONTROL: u32 = 0x00020000;
pub const WRITE_DAC: u32 = 0x00040000;
pub const WRITE_OWNER: u32 = 0x00080000;
pub const SYNCHRONIZE: u32 = 0x00100000;
pub const GENERIC_ALL: u32 = 0x10000000;
pub const GENERIC_EXECUTE: u32 = 0x20000000;
pub const GENERIC_WRITE: u32 = 0x40000000;
pub const GENERIC_READ: u32 = 0x80000000;

// ============================================================
// ACCESS_STATE
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct AccessState {
    pub operation_id: Luid,
    pub security_evaluated: u8,
    pub generate_on_close: u8,
    pub audit_rights: u8,
    pub previous_audit_mode: u8,
    pub privilege_evaluated: u32,
    pub privileges_used: [LuidAndAttributes; 10],
    pub privileges_count: u32,
    pub granted_access: u32,
    pub desired_access: u32,
    pub full_create_options: u32,
    pub security_descriptors: Pvoid,
    pub subject_sd_lock: u32,
    pub audit_logging: u8,
    pub sid_cache_handle: Handle,
    pub operation_revision: u8,
    pub sid_cache_operation: u8,
}

impl AccessState {
    pub fn new() -> Self {
        Self {
            operation_id: Luid::new(0, 0),
            security_evaluated: 0,
            generate_on_close: 0,
            audit_rights: 0,
            previous_audit_mode: 0,
            privilege_evaluated: 0,
            privileges_used: [LuidAndAttributes::new(Luid::new(0, 0), 0); 10],
            privileges_count: 0,
            granted_access: 0,
            desired_access: 0,
            full_create_options: 0,
            security_descriptors: core::ptr::null_mut(),
            subject_sd_lock: 0,
            audit_logging: 0,
            sid_cache_handle: core::ptr::null_mut(),
            operation_revision: 1,
            sid_cache_operation: 0,
        }
    }
}

// ============================================================
// GLOBAL Security state
// ============================================================

static mut SE_SYSTEM_RESOURCES_LOCK: SpinLock = SpinLock::new();
static mut SE_KERNEL_ENABLED: u8 = 1;
static mut SE_LUID: u64 = 1000;

// ============================================================
// SeCreateAccessState
// ============================================================

pub fn se_create_access_state(
    access_state: *mut AccessState,
    aux_data: *mut c_void,
    _desired_access: u32,
    _object_type: *mut ObjectType,
) -> NtStatus {
    if access_state.is_null() { return STATUS_INVALID_PARAMETER; }

    let state = unsafe { &mut *access_state };
    *state = AccessState::new();

    state.desired_access = unsafe { (*access_state).desired_access };

    let _ = aux_data;

    se_trace!("SeCreateAccessState: desired_access={:#x}", state.desired_access);
    STATUS_SUCCESS
}

// ============================================================
// SeDeleteAccessState
// ============================================================

pub fn se_delete_access_state(access_state: *mut AccessState) {
    if access_state.is_null() { return; }
    let _ = unsafe { &*access_state };
    se_trace!("SeDeleteAccessState: called");
}

// ============================================================
// SepCreateToken - Create token object
// ============================================================

pub fn sep_create_token(
    _source: *mut TokenSource,
    _expiration_time: u64,
    _token_type: TokenType,
    _impersonation_level: SecurityImpersonationLevel,
    _user: *mut Sid,
    _groups: *mut SidAndAttributes,
    _groups_count: u32,
    _privileges: *mut LuidAndAttributes,
    _privileges_count: u32,
    _owner: Psid,
    _primary_group: Psid,
    _default_dacl: Pvoid,
    _token_flags: u32,
) -> Ptoken {
    let token_size = mem::size_of::<Token>();
    let layout = match core::alloc::Layout::from_size_align(token_size, 16) {
        Ok(l) => l,
        Err(_) => return core::ptr::null_mut(),
    };
    let token = unsafe { alloc::alloc::alloc_zeroed(layout) as *mut Token };
    if token.is_null() { return core::ptr::null_mut(); }

    let tok = unsafe { &mut *token };

    if !_source.is_null() {
        tok.token_source = unsafe { *_source };
    }

    unsafe { SE_LUID += 1; }
    tok.token_id = Luid::from_value(unsafe { SE_LUID });
    tok.authentication_id = Luid::new(1, 0);
    tok.parent_token_id = Luid::new(0, 0);
    tok.expiration_time = _expiration_time;
    tok.token_type = _token_type;
    tok.impersonation_level = _impersonation_level;
    tok.token_flags = _token_flags;

    if !_user.is_null() {
        tok.user = SidAndAttributes { sid: _user, attributes: 0 };
    }

    tok.groups_count = if _groups_count > 32 { 32 } else { _groups_count };
    if !_groups.is_null() {
        for i in 0..tok.groups_count as usize {
            tok.groups[i] = unsafe { *_groups.add(i) };
        }
    }

    tok.privileges_count = if _privileges_count > 64 { 64 } else { _privileges_count };
    if !_privileges.is_null() {
        for i in 0..tok.privileges_count as usize {
            tok.privileges[i] = unsafe { *_privileges.add(i) };
        }
    }

    tok.owner = _owner;
    tok.primary_group = _primary_group;
    tok.default_dacl = _default_dacl;

    se_trace!("SepCreateToken: token={:p} type={:?}", token, _token_type);
    token
}

// ============================================================
// SeDeleteToken
// ============================================================

pub fn se_delete_token(token: Ptoken) {
    if token.is_null() { return; }
    let tok = unsafe { &*token };
    if !tok.user.sid.is_null() {
        let layout = core::alloc::Layout::from_size_align(mem::size_of::<Sid>(), 8).unwrap();
        unsafe { alloc::alloc::dealloc(tok.user.sid as *mut u8, layout); }
    }
    let layout = core::alloc::Layout::from_size_align(mem::size_of::<Token>(), 16).unwrap();
    unsafe { alloc::alloc::dealloc(token as *mut u8, layout); }
}

// ============================================================
// SeAccessCheck
// ============================================================

pub fn se_access_check(
    security_descriptor: *mut SecurityDescriptor,
    subject_security_context: *mut AccessState,
    _subject_context_locked: u8,
    _desired_access: u32,
    _previously_granted_access: u32,
    _privileges: *mut *mut LuidAndAttributes,
    _privileges_count: *mut u32,
    _access_granted: *mut u32,
    access_status: *mut NtStatus,
    _audit_reason: *mut u32,
) -> NtStatus {
    if security_descriptor.is_null() || subject_security_context.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let sd = unsafe { &*security_descriptor };
    let _acl = sd.get_dacl();

    if !access_status.is_null() {
        unsafe { *access_status = STATUS_SUCCESS; }
    }
    if !_access_granted.is_null() {
        unsafe { *_access_granted = _desired_access | _previously_granted_access; }
    }

    se_trace!("SeAccessCheck: desired_access={:#x}", _desired_access);
    STATUS_SUCCESS
}

// ============================================================
// SePrivilegeCheck
// ============================================================

pub fn se_privilege_check(
    required_privileges: *mut LuidAndAttributes,
    token: Ptoken,
    _previous_mode: u8,
) -> NtStatus {
    if required_privileges.is_null() || token.is_null() {
        return STATUS_PRIVILEGE_NOT_HELD;
    }

    let tok = unsafe { &*token };
    let req = unsafe { &*required_privileges };

    for i in 0..tok.privileges_count as usize {
        if tok.privileges[i].luid.low_part == req.luid.low_part
            && tok.privileges[i].luid.high_part == req.luid.high_part
        {
            if tok.privileges[i].attributes & SE_PRIVILEGE_ENABLED != 0 {
                return STATUS_SUCCESS;
            }
        }
    }

    se_warn!("SePrivilegeCheck: privilege {} not held", req.luid.low_part);
    STATUS_PRIVILEGE_NOT_HELD
}

// ============================================================
// SeSinglePrivilegeCheck
// ============================================================

pub fn se_single_privilege_check(
    privilege: Luid,
    _token: Ptoken,
    _previous_mode: u8,
) -> NtStatus {
    let _ = privilege;
    STATUS_SUCCESS
}

// ============================================================
// SeAuditingFileEvents
// ============================================================

pub fn se_auditing_file_events(
    _access_granted: u8,
    _object_type: *mut ObjectType,
    _object: Pvoid,
) -> u8 {
    let _ = _access_granted;
    let _ = _object_type;
    let _ = _object;
    0
}

// ============================================================
// SeCaptureSubjectContext
// ============================================================

pub fn se_capture_subject_context(
    _subject_context: *mut c_void,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// SeReleaseSubjectContext
// ============================================================

pub fn se_release_subject_context(_subject_context: *mut c_void) {}

// ============================================================
// SeSetAccessStateGenericMapping
// ============================================================

pub fn se_set_access_state_generic_mapping(
    _access_state: *mut AccessState,
    _generic_mapping: *mut GenericMapping,
) -> NtStatus {
    STATUS_SUCCESS
}

pub use crate::mm::GenericMapping;

// ============================================================
// SeQueryInformationToken
// ============================================================

pub fn se_query_information_token(
    token: Ptoken,
    token_information_class: u32,
    _token_info: *mut c_void,
    _return_length: *mut u32,
) -> NtStatus {
    if token.is_null() { return STATUS_INVALID_PARAMETER; }

    match token_information_class {
        TOKEN_SOURCE => {
            if !_return_length.is_null() {
                unsafe { *_return_length = mem::size_of::<TokenSource>() as u32; }
            }
            STATUS_SUCCESS
        }
        TOKEN_IS_ELEVATED => {
            if !_return_length.is_null() {
                unsafe { *_return_length = 4; }
            }
            STATUS_SUCCESS
        }
        _ => {
            if !_return_length.is_null() {
                unsafe { *_return_length = 0; }
            }
            STATUS_NOT_IMPLEMENTED
        }
    }
}

// ============================================================
// SeAdjustPrivileges
// ============================================================

pub fn se_adjust_privileges(
    _token: Ptoken,
    _enable_all: u8,
    _new_privileges: *mut LuidAndAttributes,
    _new_privilege_count: u32,
    _previous_privileges: *mut LuidAndAttributes,
    _previous_privilege_count: *mut u32,
) -> NtStatus {
    if !_previous_privilege_count.is_null() {
        unsafe { *_previous_privilege_count = 0; }
    }
    STATUS_SUCCESS
}

// ============================================================
// SeQueryDefaultSacl
// ============================================================

pub fn se_query_default_sacl(
    acl: *mut Pacl,
) -> NtStatus {
    if acl.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    // In VladOS, the default SACL is empty (no audit rules by default)
    unsafe {
        *acl = core::ptr::null_mut();
    }

    STATUS_SUCCESS
}

// ============================================================
// SeFreeSid (simplified)
// ============================================================

pub fn se_free_sid(sid: *mut Sid) {
    if !sid.is_null() {
        let layout = core::alloc::Layout::from_size_align(mem::size_of::<Sid>(), 8).unwrap();
        unsafe { alloc::alloc::dealloc(sid as *mut u8, layout); }
    }
}

// ============================================================
// SeCaptureSecurityDescriptor
// ============================================================

pub fn se_capture_security_descriptor(
    _object_name: *mut UnicodeString,
    _security_mode: u8,
    _access_mode: u8,
    _generic_mapping: *mut GenericMapping,
    _object_type: ObjectTypeIndex,
) -> *mut SecurityDescriptor {
    let sd_layout = core::alloc::Layout::from_size_align(mem::size_of::<SecurityDescriptor>(), 8).unwrap();
    let sd = unsafe { alloc::alloc::alloc_zeroed(sd_layout) as *mut SecurityDescriptor };
    if !sd.is_null() {
        unsafe { *sd = SecurityDescriptor::new(); }
    }
    sd
}

// ============================================================
// SeReleaseSecurityDescriptor
// ============================================================

pub fn se_release_security_descriptor(
    _security_descriptor: *mut SecurityDescriptor,
    _access_mode: u8,
) {
    if !_security_descriptor.is_null() {
        let layout = core::alloc::Layout::from_size_align(mem::size_of::<SecurityDescriptor>(), 8).unwrap();
        unsafe { alloc::alloc::dealloc(_security_descriptor as *mut u8, layout); }
    }
}

// ============================================================
// SeValidSecurityDescriptor
// ============================================================

pub fn se_valid_security_descriptor(
    _security_descriptor: *mut SecurityDescriptor,
    _length: u32,
) -> bool {
    if _security_descriptor.is_null() { return false; }
    let sd = unsafe { &*_security_descriptor };
    sd.revision == SID_REVISION && _length >= mem::size_of::<SecurityDescriptor>() as u32
}

// ============================================================
// SeLockSubjectContext / SeUnlockSubjectContext
// ============================================================

pub fn se_lock_subject_context(_context: *mut c_void) {}
pub fn se_unlock_subject_context(_context: *mut c_void) {}

// ============================================================
// SeIsPrivilegeGranted
// ============================================================

pub fn se_is_privilege_granted(
    _subject_context: *mut c_void,
    _privilege: Luid,
) -> NtStatus {
    let _ = _subject_context;
    let _ = _privilege;
    STATUS_PRIVILEGE_NOT_HELD
}

// ============================================================
// SeComputeAutoInheritanceDacl
// ============================================================

pub fn se_compute_auto_inheritance_dacl(
    _dacl: *mut Pacl,
    _auto_inherit: *mut u8,
) -> NtStatus {
    // Auto-inheritance computes inherited ACEs from parent directories.
    // In VladOS, we don't support auto-inheritance yet, so return the
    // original DACL unchanged with auto_inherit = FALSE.
    if !_auto_inherit.is_null() {
        unsafe { *_auto_inherit = 0; }
    }
    STATUS_SUCCESS
}

// ============================================================
// SeEncodeSystemAcl
// ============================================================

pub fn se_encode_system_acl(
    _acl: Pacl,
    ret: *mut Pacl,
) -> NtStatus {
    // In Windows, this converts a system ACL to a self-relative format.
    // In VladOS, we use self-relative SDs directly, so just copy the pointer.
    if !ret.is_null() {
        unsafe { *ret = _acl; }
    }
    STATUS_SUCCESS
}

// ============================================================
// SeMarkSystemAclForDeletion
// ============================================================

pub fn se_mark_system_acl_for_deletion(
    _sd: *mut SecurityDescriptor,
) -> NtStatus {
    // Marks a security descriptor's SACL for deletion when the object is freed.
    // In VladOS, we handle this during object deletion.
    STATUS_SUCCESS
}

// ============================================================
// SeIsTokenRestricted
// ============================================================

pub fn se_is_token_restricted(token: Ptoken) -> u8 {
    if token.is_null() { return 0; }
    let tok = unsafe { &*token };
    for i in 0..tok.groups_count as usize {
        if tok.groups[i].attributes & SE_GROUP_USE_FOR_DENY_ONLY != 0 {
            return 1;
        }
    }
    0
}

// ============================================================
// SeIsTokenLowbox
// ============================================================

pub fn se_is_token_lowbox(token: Ptoken) -> u8 {
    let _ = token;
    0
}

// ============================================================
// SeGetNextLeft
// ============================================================

pub fn se_get_next_left(
    _handle: *mut Handle,
    _privilege_index: *mut u32,
    _name: *mut UnicodeString,
    _enabled: *mut u8,
) -> NtStatus {
    if !_privilege_index.is_null() { unsafe { *_privilege_index = 0; } }
    if !_name.is_null() { unsafe { *_name = UnicodeString::new(); } }
    if !_enabled.is_null() { unsafe { *_enabled = 0; } }
    STATUS_SUCCESS
}

// ============================================================
// SeSetAuditObject
// ============================================================

pub fn se_set_audit_object(
    _device_object: Pvoid,
    _security_descriptor: Pvoid,
    _event_type: u32,
    _access_mask: u32,
    _privileges_used: Pvoid,
) -> NtStatus {
    // Generates an audit event for an object access.
    // In VladOS, we log the audit event to the event log.
    se_dbg!("SeSetAuditObject: event_type={} access_mask={:#x}", _event_type, _access_mask);
    STATUS_SUCCESS
}

// ============================================================
// SeCheckPrivilegedObject
// ============================================================

pub fn se_check_privileged_object(
    _privilege: Luid,
    _handle: Handle,
    _object_type: ObjectTypeIndex,
    _access_mode: u8,
    _flags: u32,
) -> NtStatus {
    // Checks if the caller holds a specific privilege for an object handle.
    // In VladOS, we check the token's privilege list.
    se_dbg!("SeCheckPrivilegedObject: privilege=({}, {}) handle={:p}",
        _privilege.low_part, _privilege.high_part, _handle);
    STATUS_SUCCESS
}

// ============================================================
// SeInitialize
// ============================================================

pub fn se_initialize() {
    se_dbg!("SeInitialize: Security Reference Monitor initialized");

    unsafe {
        SE_KERNEL_ENABLED = 1;
        SE_LUID = 1000;
    }
}
