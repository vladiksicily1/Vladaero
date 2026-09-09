/// Tm - Transaction Manager (Tm/TmTm/TmTx)
use core::ffi::c_void;
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

pub const KTM_OBJECT_TYPE_NONE: u32 = 0;
pub const KTM_OBJECT_TYPE_TRANSACTION: u32 = 1;
pub const KTM_OBJECT_TYPE_RESOURCE_MANAGER: u32 = 2;
pub const KTM_OBJECT_TYPE_ENLISTMENT: u32 = 3;
pub const KTM_OBJECT_TYPE_TRANSACTION_MANAGER: u32 = 4;

#[repr(C)]
pub struct KtmObject {
    pub object_type: u32,
    pub state: u32,
    pub object_id: u64,
    pub next: *mut KtmObject,
}

#[repr(C)]
pub struct TransactionManager {
    pub header: crate::ke::dispatcher::DispatcherHeader,
    pub tm_id: u64,
    pub log_file: *mut c_void,
    pub log_size: u64,
    pub state: u32,
    pub rm_list: ListEntry,
    pub enlistment_count: u32,
}

#[repr(C)]
pub struct ResourceManager {
    pub header: crate::ke::dispatcher::DispatcherHeader,
    pub rm_id: u64,
    pub tm: *mut TransactionManager,
    pub enlistment_list: ListEntry,
    pub protocol: u32,
}

#[repr(C)]
pub struct Enlistment {
    pub header: crate::ke::dispatcher::DispatcherHeader,
    pub enlistment_id: u64,
    pub rm: *mut ResourceManager,
    pub transaction: *mut c_void,
    pub flags: u32,
    pub notification_mask: u32,
    pub prepare_info: u64,
    pub outcome: u32,
}

pub unsafe fn tm_initialize() -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn tm_create_enlistment(
    _rm: *mut ResourceManager,
    _transaction: *mut c_void,
    enlistment: *mut *mut Enlistment,
) -> NtStatus {
    let e = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<Enlistment>()) as *mut Enlistment;
    if e.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::write_bytes(e as *mut u8, 0, core::mem::size_of::<Enlistment>());
    (*e).header.r#type = 6;
    (*e).enlistment_id = crate::rtl::rtl_compute_crc32(0, e as *const u8, core::mem::size_of::<Enlistment>()) as u64;
    (*e).rm = _rm;
    (*e).transaction = _transaction;
    *enlistment = e;
    STATUS_SUCCESS
}

pub unsafe fn tm_prepare_enlistment(
    _enlistment: *mut Enlistment,
    _prepare_info: *mut u8,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn tm_commit_enlistment(
    _enlistment: *mut Enlistment,
    _commit_info: *mut u8,
) -> NtStatus {
    if !_enlistment.is_null() {
        (*_enlistment).outcome = 1; // Committed
    }
    STATUS_SUCCESS
}

pub unsafe fn tm_rollback_enlistment(
    _enlistment: *mut Enlistment,
    _reason: u32,
) -> NtStatus {
    if !_enlistment.is_null() {
        (*_enlistment).outcome = 2; // Rolled back
    }
    STATUS_SUCCESS
}

pub unsafe fn tm_create_transaction_manager(
    _log_file: *mut c_void,
    _tm: *mut *mut TransactionManager,
) -> NtStatus {
    let tm = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<TransactionManager>()) as *mut TransactionManager;
    if tm.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::write_bytes(tm as *mut u8, 0, core::mem::size_of::<TransactionManager>());
    (*tm).header.r#type = 6;
    (*tm).log_file = _log_file;
    (*tm).rm_list.initialize();
    *_tm = tm;
    STATUS_SUCCESS
}

pub unsafe fn tm_query_enlistment(
    _enlistment: *mut Enlistment,
    _info_class: u32,
    _info: *mut u8,
    _info_size: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 KTM: transaction state machine + 2-phase commit
// ============================================================

pub const TRANSACTION_STATE_ACTIVE: u32 = 1;
pub const TRANSACTION_STATE_PREPARED: u32 = 2;
pub const TRANSACTION_STATE_PREPARE_FAILED: u32 = 3;
pub const TRANSACTION_STATE_COMMITTED: u32 = 4;
pub const TRANSACTION_STATE_ABORTED: u32 = 5;
pub const TRANSACTION_STATE_INDOUBT: u32 = 6;

pub const ENLISTMENT_STATE_ACTIVE: u32 = 1;
pub const ENLISTMENT_STATE_PREPARED: u32 = 2;
pub const ENLISTMENT_STATE_PREPARE_FAILED: u32 = 3;
pub const ENLISTMENT_STATE_COMMITTED: u32 = 4;
pub const ENLISTMENT_STATE_ABORTED: u32 = 5;

pub const TRANSACTION_NOTIFY_PREPARE: u32 = 0x00000001;
pub const TRANSACTION_NOTIFY_COMMIT: u32 = 0x00000002;
pub const TRANSACTION_NOTIFY_ROLLBACK: u32 = 0x00000004;
pub const TRANSACTION_NOTIFY_SINGLE_PHASE_COMMIT: u32 = 0x00000008;

pub const STATUS_TRANSACTION_NOT_ACTIVE_LOCAL: NtStatus = 0xC0190003;
pub const STATUS_TRANSACTION_REQUEST_NOT_VALID_LOCAL: NtStatus = 0xC019000C;

#[repr(C)]
pub struct KtmTransaction {
    pub header: crate::ke::dispatcher::DispatcherHeader,
    pub transaction_id: u64,
    pub state: u32,
    pub outcome: u32,
    pub enlistments: *mut KtmEnlistmentFull,
    pub enlistment_count: u32,
    pub tm: *mut TransactionManager,
    pub isolation_level: u32,
    pub timeout_ms: u32,
    pub description: [u16; 64],
    pub next: *mut KtmTransaction,
}

#[repr(C)]
pub struct KtmEnlistmentFull {
    pub header: crate::ke::dispatcher::DispatcherHeader,
    pub enlistment_id: u64,
    pub transaction: *mut KtmTransaction,
    pub rm: *mut ResourceManager,
    pub state: u32,
    pub outcome: u32,
    pub notification_mask: u32,
    pub next_in_tx: *mut KtmEnlistmentFull,
    pub next_in_rm: *mut KtmEnlistmentFull,
}

static mut KTM_TX_LIST: *mut KtmTransaction = core::ptr::null_mut();
static KTM_NEXT_TX_ID: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(1);
static KTM_NEXT_ENLISTMENT_ID: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(1);

/// TmCreateTransaction - begin a new transaction on a TM.
pub unsafe fn tm_create_transaction(
    tm: *mut TransactionManager,
    isolation_level: u32,
    timeout_ms: u32,
    description: *const u16,
    tx_out: *mut *mut KtmTransaction,
) -> NtStatus {
    if tx_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let tx = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<KtmTransaction>(),
    ) as *mut KtmTransaction;
    if tx.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(tx as *mut u8, 0, core::mem::size_of::<KtmTransaction>());
    (*tx).header.r#type = 6;
    (*tx).transaction_id =
        KTM_NEXT_TX_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*tx).state = TRANSACTION_STATE_ACTIVE;
    (*tx).tm = tm;
    (*tx).isolation_level = isolation_level;
    (*tx).timeout_ms = timeout_ms;
    if !description.is_null() {
        let mut i = 0;
        while i < 63 && *description.add(i) != 0 {
            (*tx).description[i] = *description.add(i);
            i += 1;
        }
    }
    (*tx).next = KTM_TX_LIST;
    KTM_TX_LIST = tx;
    *tx_out = tx;
    STATUS_SUCCESS
}

/// TmCreateEnlistmentFull - enlist an RM in a transaction.
pub unsafe fn tm_create_enlistment_full(
    tx: *mut KtmTransaction,
    rm: *mut ResourceManager,
    notification_mask: u32,
    enlistment_out: *mut *mut KtmEnlistmentFull,
) -> NtStatus {
    if tx.is_null() || enlistment_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*tx).state != TRANSACTION_STATE_ACTIVE {
        return STATUS_TRANSACTION_NOT_ACTIVE_LOCAL;
    }
    let e = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<KtmEnlistmentFull>(),
    ) as *mut KtmEnlistmentFull;
    if e.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(e as *mut u8, 0, core::mem::size_of::<KtmEnlistmentFull>());
    (*e).header.r#type = 6;
    (*e).enlistment_id =
        KTM_NEXT_ENLISTMENT_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*e).transaction = tx;
    (*e).rm = rm;
    (*e).state = ENLISTMENT_STATE_ACTIVE;
    (*e).notification_mask = notification_mask;
    (*e).next_in_tx = (*tx).enlistments;
    (*tx).enlistments = e;
    (*tx).enlistment_count += 1;
    if !rm.is_null() {
        (*e).next_in_rm = (*rm).enlistment_list.flink as *mut KtmEnlistmentFull;
        (*rm).enlistment_count += 1;
    }
    *enlistment_out = e;
    STATUS_SUCCESS
}

/// TmPrepareTransaction - phase 1: ask every enlisted RM to prepare.
pub unsafe fn tm_prepare_transaction(tx: *mut KtmTransaction) -> NtStatus {
    if tx.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*tx).state != TRANSACTION_STATE_ACTIVE {
        return STATUS_TRANSACTION_NOT_ACTIVE_LOCAL;
    }
    let mut cur = (*tx).enlistments;
    while !cur.is_null() {
        if (*cur).notification_mask & TRANSACTION_NOTIFY_PREPARE != 0 {
            (*cur).state = ENLISTMENT_STATE_PREPARED;
        } else {
            // RM opted out of prepare: single-phase commit path.
            (*cur).state = ENLISTMENT_STATE_PREPARED;
        }
        cur = (*cur).next_in_tx;
    }
    (*tx).state = TRANSACTION_STATE_PREPARED;
    STATUS_SUCCESS
}

/// TmCommitTransaction - phase 2: commit (prepares first if needed).
pub unsafe fn tm_commit_transaction(tx: *mut KtmTransaction) -> NtStatus {
    if tx.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*tx).state == TRANSACTION_STATE_ACTIVE {
        let st = tm_prepare_transaction(tx);
        if st != STATUS_SUCCESS {
            return st;
        }
    }
    if (*tx).state != TRANSACTION_STATE_PREPARED {
        return STATUS_TRANSACTION_REQUEST_NOT_VALID_LOCAL;
    }
    let mut cur = (*tx).enlistments;
    while !cur.is_null() {
        (*cur).state = ENLISTMENT_STATE_COMMITTED;
        (*cur).outcome = 1;
        cur = (*cur).next_in_tx;
    }
    (*tx).state = TRANSACTION_STATE_COMMITTED;
    (*tx).outcome = 1;
    STATUS_SUCCESS
}

/// TmRollbackTransaction - abort: notify every RM.
pub unsafe fn tm_rollback_transaction(tx: *mut KtmTransaction) -> NtStatus {
    if tx.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*tx).state == TRANSACTION_STATE_COMMITTED {
        return STATUS_TRANSACTION_REQUEST_NOT_VALID_LOCAL;
    }
    let mut cur = (*tx).enlistments;
    while !cur.is_null() {
        (*cur).state = ENLISTMENT_STATE_ABORTED;
        (*cur).outcome = 2;
        cur = (*cur).next_in_tx;
    }
    (*tx).state = TRANSACTION_STATE_ABORTED;
    (*tx).outcome = 2;
    STATUS_SUCCESS
}

/// TmQueryTransaction - state + outcome + enlistment count.
pub unsafe fn tm_query_transaction(
    tx: *mut KtmTransaction,
    state_out: *mut u32,
    outcome_out: *mut u32,
    enlistment_count_out: *mut u32,
) -> NtStatus {
    if tx.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if !state_out.is_null() {
        *state_out = (*tx).state;
    }
    if !outcome_out.is_null() {
        *outcome_out = (*tx).outcome;
    }
    if !enlistment_count_out.is_null() {
        *enlistment_count_out = (*tx).enlistment_count;
    }
    STATUS_SUCCESS
}

/// TmCloseTransaction - destroy a completed transaction.
pub unsafe fn tm_close_transaction(tx: *mut KtmTransaction) -> NtStatus {
    if tx.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*tx).state == TRANSACTION_STATE_ACTIVE
        || (*tx).state == TRANSACTION_STATE_PREPARED
    {
        tm_rollback_transaction(tx);
    }
    // Free enlistments.
    let mut e = (*tx).enlistments;
    while !e.is_null() {
        let next = (*e).next_in_tx;
        crate::mm::pool::ex_free_pool(e as *mut core::ffi::c_void);
        e = next;
    }
    // Unlink.
    let mut prev: *mut KtmTransaction = core::ptr::null_mut();
    let mut cur = KTM_TX_LIST;
    while !cur.is_null() {
        if cur == tx {
            if prev.is_null() {
                KTM_TX_LIST = (*cur).next;
            } else {
                (*prev).next = (*cur).next;
            }
            crate::mm::pool::ex_free_pool(cur as *mut core::ffi::c_void);
            return STATUS_SUCCESS;
        }
        prev = cur;
        cur = (*cur).next;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}
