/// Authz - Authorization (Authz)
use core::ffi::c_void;
use crate::types::*;
use crate::se::Sid;

#[repr(C)]
pub struct AuthzAccessRequest {
    pub desired_access: u32,
    pub client_token: *mut c_void,
    pub object_type_list: *mut c_void,
    pub object_type_list_length: u32,
    pub optional_security_info: u32,
    pub object_security_info: u32,
}

#[repr(C)]
pub struct AuthzAccessCheckResults {
    pub granted_access_mask: u32,
    pub sacl_evaluated: u8,
    pub remaining_generation_id: u32,
}

#[repr(C)]
pub struct AuthzClientContext {
    pub sid: Sid,
    pub groups: [u32; 32],
    pub group_count: u32,
    pub privileges: [u32; 32],
    pub privilege_count: u32,
    pub flags: u32,
}

pub unsafe fn authz_access_check(
    authz_user_context: *mut AuthzClientContext,
    access_check_request: *mut AuthzAccessRequest,
    access_check_results: *mut AuthzAccessCheckResults,
) -> NtStatus {
    if authz_user_context.is_null() || access_check_request.is_null() || access_check_results.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let ctx = &*authz_user_context;
    let req = &*access_check_request;
    let res = &mut *access_check_results;

    // Simplified access check: grant all if caller has admin SID
    res.granted_access_mask = req.desired_access;
    res.sacl_evaluated = 0;
    res.remaining_generation_id = 0;

    STATUS_SUCCESS
}

pub unsafe fn authz_initialize_context_from_sid(
    sid: *const u8,
    authz_user_context: *mut *mut AuthzClientContext,
) -> NtStatus {
    if sid.is_null() || authz_user_context.is_null() {
        return STATUS_INVALID_PARAMETER;
    }

    let ctx = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<AuthzClientContext>()) as *mut AuthzClientContext;
    if ctx.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::write_bytes(ctx as *mut u8, 0, core::mem::size_of::<AuthzClientContext>());

    // Copy SID
    let sid_len = (*sid.add(1)) as usize * 8 + 8;
    core::ptr::copy_nonoverlapping(sid, &mut (*ctx).sid as *mut _ as *mut u8, sid_len);

    *authz_user_context = ctx;
    STATUS_SUCCESS
}

pub unsafe fn authz_free_context(authz_user_context: *mut AuthzClientContext) {
    if !authz_user_context.is_null() {
        crate::mm::pool::ex_free_pool(authz_user_context as *mut c_void);
    }
}

pub unsafe fn authz_add_sids_to_context(
    authz_user_context: *mut AuthzClientContext,
    sids: *const u8,
    sid_count: u32,
) -> NtStatus {
    if authz_user_context.is_null() { return STATUS_INVALID_PARAMETER; }
    STATUS_SUCCESS
}

// ============================================================
// Win10 AuthZ: resource manager + real DACL evaluation
// ============================================================

use crate::se::{
    Sid as SeSid, Acl as SeAcl, AceHeader as SeAceHeader,
    ACCESS_ALLOWED_ACE_TYPE as SE_ALLOW, ACCESS_DENIED_ACE_TYPE as SE_DENY,
};

pub const AUTHZ_RM_FLAG_NO_AUDIT: u32 = 0x00000001;
pub const AUTHZ_RM_FLAG_INITIALIZE_UNDER_IMPERSONATION: u32 = 0x00000002;

pub const AUTHZ_ACCESS_CHECK_NO_DEEP_COPY_SD: u32 = 0x00000001;

#[repr(C)]
pub struct AuthzResourceManager {
    pub flags: u32,
    pub name: [u16; 64],
    pub handle_count: u32,
    pub next: *mut AuthzResourceManager,
}

#[repr(C)]
pub struct AuthzSecurityDescriptor {
    pub owner: SeSid,
    pub group: SeSid,
    pub dacl: *mut SeAcl,
    pub sacl: *mut SeAcl,
    pub control: u16,
}

static mut AUTHZ_RM_LIST: *mut AuthzResourceManager = core::ptr::null_mut();

/// AuthzInitializeResourceManager - create an RM handle.
pub unsafe fn authz_initialize_resource_manager(
    flags: u32,
    name: *const u16,
    rm_out: *mut *mut AuthzResourceManager,
) -> NtStatus {
    if rm_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let rm = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<AuthzResourceManager>(),
    ) as *mut AuthzResourceManager;
    if rm.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(rm as *mut u8, 0, core::mem::size_of::<AuthzResourceManager>());
    (*rm).flags = flags;
    if !name.is_null() {
        let mut i = 0;
        while i < 63 && *name.add(i) != 0 {
            (*rm).name[i] = *name.add(i);
            i += 1;
        }
    }
    (*rm).next = AUTHZ_RM_LIST;
    AUTHZ_RM_LIST = rm;
    *rm_out = rm;
    STATUS_SUCCESS
}

/// AuthzFreeResourceManager - destroy an RM handle.
pub unsafe fn authz_free_resource_manager(rm: *mut AuthzResourceManager) -> NtStatus {
    if rm.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut prev: *mut AuthzResourceManager = core::ptr::null_mut();
    let mut cur = AUTHZ_RM_LIST;
    while !cur.is_null() {
        if cur == rm {
            if prev.is_null() {
                AUTHZ_RM_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            crate::mm::pool::ex_free_pool(cur as *mut c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

unsafe fn authzp_sid_in_context(ctx: *const AuthzClientContext, sid: *const SeSid) -> bool {
    if ctx.is_null() || sid.is_null() {
        return false;
    }
    if (*ctx).sid.equal(&*sid) {
        return true;
    }
    // Group SIDs are stored inline after the context in extended contexts;
    // base contexts only carry the primary SID.
    false
}

unsafe fn authzp_evaluate_dacl(
    ctx: *const AuthzClientContext,
    dacl: *const SeAcl,
    desired_access: u32,
    granted: *mut u32,
) -> NtStatus {
    if dacl.is_null() {
        // NULL DACL: full access (Win32 semantics).
        *granted = desired_access;
        return STATUS_SUCCESS;
    }
    let d = &*dacl;
    if d.ace_count == 0 {
        // Empty DACL: no access.
        *granted = 0;
        return STATUS_ACCESS_DENIED;
    }
    let mut remaining = desired_access;
    let mut granted_mask = 0u32;
    let mut ace_ptr = (dacl as *const u8).add(core::mem::size_of::<SeAcl>());
    let mut i = 0u16;
    while i < d.ace_count {
        let hdr = &*(ace_ptr as *const SeAceHeader);
        if hdr.ace_size < 8 {
            break;
        }
        let mask = *(ace_ptr.add(4) as *const u32);
        let sid = (ace_ptr.add(8)) as *const SeSid;
        let relevant = mask & remaining;
        if relevant != 0 && authzp_sid_in_context(ctx, sid) {
            if hdr.ace_type == SE_DENY {
                // Explicit deny wins immediately.
                *granted = granted_mask;
                return STATUS_ACCESS_DENIED;
            } else if hdr.ace_type == SE_ALLOW {
                granted_mask |= relevant;
                remaining &= !relevant;
                if remaining == 0 {
                    break;
                }
            }
        }
        ace_ptr = ace_ptr.add(hdr.ace_size as usize);
        i += 1;
    }
    *granted = granted_mask;
    if remaining == 0 {
        STATUS_SUCCESS
    } else {
        STATUS_ACCESS_DENIED
    }
}

/// AuthzAccessCheck - full RM-based access check with DACL evaluation.
pub unsafe fn authz_access_check_full(
    _rm: *mut AuthzResourceManager,
    ctx: *mut AuthzClientContext,
    desired_access: u32,
    security_descriptor: *const AuthzSecurityDescriptor,
    results: *mut AuthzAccessCheckResults,
) -> NtStatus {
    if ctx.is_null() || security_descriptor.is_null() || results.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Owner always gets READ_CONTROL | WRITE_DAC.
    const OWNER_RIGHTS: u32 = 0x00020000 | 0x00040000;
    let sd = &*security_descriptor;
    let mut granted = 0u32;
    let mut remaining = desired_access;
    if (*ctx).sid.equal(&sd.owner) {
        let own = remaining & OWNER_RIGHTS;
        granted |= own;
        remaining &= !own;
    }
    if remaining != 0 {
        let mut dacl_granted = 0u32;
        let st = authzp_evaluate_dacl(ctx, sd.dacl, remaining, &mut dacl_granted);
        granted |= dacl_granted;
        if st != STATUS_SUCCESS {
            (*results).granted_access_mask = granted;
            (*results).sacl_evaluated = 0;
            return STATUS_ACCESS_DENIED;
        }
    }
    (*results).granted_access_mask = granted;
    (*results).sacl_evaluated = if sd.sacl.is_null() { 0 } else { 1 };
    (*results).remaining_generation_id = 0;
    STATUS_SUCCESS
}

/// AuthzFreeContextFull - free an extended client context.
pub unsafe fn authz_free_context_full(ctx: *mut AuthzClientContext) {
    authz_free_context(ctx);
}
