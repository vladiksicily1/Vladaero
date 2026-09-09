/// NVMe driver (stornvme.sys): controller init, IDENTIFY, IO read
///
/// Register-level NVMe 1.x: CC enable, admin queue pair, IDENTIFY
/// CNS=1, IO completion/send queues, READ (opcode 0x02) with PRP.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::bus::pci;
use super::ahci::{STATUS_IO_DEVICE_ERROR, STATUS_IO_TIMEOUT};

// ============================================================
// Registers
// ============================================================

pub const NVME_REG_CAP_LO: usize = 0x00;
pub const NVME_REG_CAP_HI: usize = 0x04;
pub const NVME_REG_VS: usize = 0x08;
pub const NVME_REG_CC: usize = 0x14;
pub const NVME_REG_CSTS: usize = 0x1C;
pub const NVME_REG_AQA: usize = 0x24;
pub const NVME_REG_ASQ_LO: usize = 0x28;
pub const NVME_REG_ASQ_HI: usize = 0x2C;
pub const NVME_REG_ACQ_LO: usize = 0x30;
pub const NVME_REG_ACQ_HI: usize = 0x34;

pub const NVME_CC_EN: u32 = 1 << 0;
pub const NVME_CSTS_RDY: u32 = 1 << 0;

pub const NVME_ADMIN_IDENTIFY: u8 = 0x06;
pub const NVME_ADMIN_SET_FEATURES: u8 = 0x09;
pub const NVME_ADMIN_CREATE_CQ: u8 = 0x05;
pub const NVME_ADMIN_CREATE_SQ: u8 = 0x01;
pub const NVME_IO_READ: u8 = 0x02;
pub const NVME_IO_WRITE: u8 = 0x01;

pub const NVME_MAX_NAMESPACES: usize = 8;
pub const NVME_SECTOR_SIZE: usize = 512;
pub const NVME_QUEUE_ENTRIES: usize = 64;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct NvmeSubmissionEntry {
    pub cdw0: u32, // opcode | fused | cid
    pub nsid: u32,
    pub cdw2: u32,
    pub cdw3: u32,
    pub mptr: u64,
    pub prp1: u64,
    pub prp2: u64,
    pub cdw10: u32,
    pub cdw11: u32,
    pub cdw12: u32,
    pub cdw13: u32,
    pub cdw14: u32,
    pub cdw15: u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct NvmeCompletionEntry {
    pub dw0: u32,
    pub dw1: u32,
    pub sq_head: u16,
    pub sq_id: u16,
    pub cid: u16,
    pub status: u16,
}

#[repr(C)]
pub struct NvmeQueuePair {
    pub sq: *mut NvmeSubmissionEntry,
    pub cq: *mut NvmeCompletionEntry,
    pub sq_tail: u16,
    pub cq_head: u16,
    pub cq_phase: u16,
    pub qid: u16,
    pub db_sq: *mut u32,
    pub db_cq: *mut u32,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct NvmeNamespace {
    pub nsid: u32,
    pub sectors: u64,
    pub present: bool,
}

#[repr(C)]
pub struct NvmeController {
    pub reg_base: u64,
    pub doorbell_stride: u32,
    pub admin: NvmeQueuePair,
    pub io: NvmeQueuePair,
    pub namespaces: [NvmeNamespace; NVME_MAX_NAMESPACES],
    pub ns_count: usize,
    pub ready: bool,
}

static mut NVME_CTRL: NvmeController = NvmeController {
    reg_base: 0,
    doorbell_stride: 0,
    admin: NvmeQueuePair {
        sq: core::ptr::null_mut(),
        cq: core::ptr::null_mut(),
        sq_tail: 0,
        cq_head: 0,
        cq_phase: 1,
        qid: 0,
        db_sq: core::ptr::null_mut(),
        db_cq: core::ptr::null_mut(),
    },
    io: NvmeQueuePair {
        sq: core::ptr::null_mut(),
        cq: core::ptr::null_mut(),
        sq_tail: 0,
        cq_head: 0,
        cq_phase: 1,
        qid: 1,
        db_sq: core::ptr::null_mut(),
        db_cq: core::ptr::null_mut(),
    },
    namespaces: [NvmeNamespace {
        nsid: 0,
        sectors: 0,
        present: false,
    }; NVME_MAX_NAMESPACES],
    ns_count: 0,
    ready: false,
};

unsafe fn nvme_read32(base: u64, off: usize) -> u32 {
    *((base as *const u8).add(off) as *const u32)
}

unsafe fn nvme_write32(base: u64, off: usize, val: u32) {
    *((base as *mut u8).add(off) as *mut u32) = val;
}

unsafe fn nvme_write64(base: u64, off: usize, val: u64) {
    nvme_write32(base, off, (val & 0xFFFF_FFFF) as u32);
    nvme_write32(base, off + 4, (val >> 32) as u32);
}

unsafe fn nvme_db(q: *mut NvmeQueuePair, is_sq: bool, val: u16) {
    let db = if is_sq { (*q).db_sq } else { (*q).db_cq };
    if !db.is_null() {
        *db = val as u32;
    }
}

/// Submit on a queue pair and poll its completion entry.
unsafe fn nvme_submit(
    ctrl: *mut NvmeController,
    q: *mut NvmeQueuePair,
    opcode: u8,
    nsid: u32,
    prp1: u64,
    cdw10: u32,
    cdw11: u32,
    cdw12: u32,
) -> bool {
    let tail = (*q).sq_tail as usize % NVME_QUEUE_ENTRIES;
    let sqe = (*q).sq.add(tail);
    let cid = (*q).sq_tail;
    (*sqe).cdw0 = (opcode as u32) | ((cid as u32) << 16);
    (*sqe).nsid = nsid;
    (*sqe).cdw2 = 0;
    (*sqe).cdw3 = 0;
    (*sqe).mptr = 0;
    (*sqe).prp1 = prp1;
    (*sqe).prp2 = 0;
    (*sqe).cdw10 = cdw10;
    (*sqe).cdw11 = cdw11;
    (*sqe).cdw12 = cdw12;
    (*sqe).cdw13 = 0;
    (*sqe).cdw14 = 0;
    (*sqe).cdw15 = 0;
    (*q).sq_tail = (*q).sq_tail.wrapping_add(1);
    nvme_db(q, true, (*q).sq_tail);
    // Poll completion.
    let mut spins = 5_000_000u32;
    while spins > 0 {
        let cqe = (*q).cq.add((*q).cq_head as usize % NVME_QUEUE_ENTRIES);
        let status = (*cqe).status;
        if ((status & 1) as u16) == (*q).cq_phase {
            let ok = (status >> 1) == 0 && (*cqe).cid == cid;
            (*q).cq_head = (*q).cq_head.wrapping_add(1);
            if (*q).cq_head as usize % NVME_QUEUE_ENTRIES == 0 {
                (*q).cq_phase ^= 1;
            }
            nvme_db(q, false, (*q).cq_head);
            let _ = ctrl;
            return ok;
        }
        spins -= 1;
    }
    false
}

unsafe fn nvme_alloc_queue(entries: usize, entry_size: usize) -> *mut u8 {
    let layout = core::alloc::Layout::from_size_align(entries * entry_size, 4096);
    match layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l),
        Err(_) => core::ptr::null_mut(),
    }
}

/// Initialize one NVMe controller at BAR0.
unsafe fn nvme_init_controller(bar0: u64) -> bool {
    let ctrl = &mut NVME_CTRL as *mut NvmeController;
    (*ctrl).reg_base = bar0;
    // Disable first.
    let mut cc = nvme_read32(bar0, NVME_REG_CC);
    cc &= !NVME_CC_EN;
    nvme_write32(bar0, NVME_REG_CC, cc);
    let mut spins = 1_000_000u32;
    while spins > 0 && nvme_read32(bar0, NVME_REG_CSTS) & NVME_CSTS_RDY != 0 {
        spins -= 1;
    }
    // Doorbell stride from CAP.DSTRD (bits 39:36).
    let cap_lo = nvme_read32(bar0, NVME_REG_CAP_LO);
    let cap_hi = nvme_read32(bar0, NVME_REG_CAP_HI);
    let cap64 = (cap_hi as u64) << 32 | cap_lo as u64;
    (*ctrl).doorbell_stride = 4 << (((cap64 >> 36) & 0xF) as u32);
    // Admin queues.
    let asq = nvme_alloc_queue(NVME_QUEUE_ENTRIES, 64) as *mut NvmeSubmissionEntry;
    let acq = nvme_alloc_queue(NVME_QUEUE_ENTRIES, 16) as *mut NvmeCompletionEntry;
    if asq.is_null() || acq.is_null() {
        return false;
    }
    (*ctrl).admin.sq = asq;
    (*ctrl).admin.cq = acq;
    nvme_write32(bar0, NVME_REG_AQA, ((NVME_QUEUE_ENTRIES as u32 - 1) << 16) | (NVME_QUEUE_ENTRIES as u32 - 1));
    nvme_write64(bar0, NVME_REG_ASQ_LO, asq as u64);
    nvme_write64(bar0, NVME_REG_ACQ_LO, acq as u64);
    // Doorbell 0 (admin): base + 0x1000.
    let db_base = bar0 + 0x1000;
    (*ctrl).admin.db_sq = db_base as *mut u32;
    (*ctrl).admin.db_cq = (db_base + (*ctrl).doorbell_stride as u64) as *mut u32;
    // Enable: IOSQES=6 (64B), IOCQES=4 (16B), AMS=RR.
    cc = NVME_CC_EN | (6 << 16) | (4 << 20);
    nvme_write32(bar0, NVME_REG_CC, cc);
    spins = 2_000_000;
    while spins > 0 && nvme_read32(bar0, NVME_REG_CSTS) & NVME_CSTS_RDY == 0 {
        spins -= 1;
    }
    if nvme_read32(bar0, NVME_REG_CSTS) & NVME_CSTS_RDY == 0 {
        return false;
    }
    // IDENTIFY controller (CNS=1) to learn NN.
    let id_buf = nvme_alloc_queue(1, 4096);
    if id_buf.is_null() {
        return false;
    }
    if !nvme_submit(ctrl, &mut (*ctrl).admin, NVME_ADMIN_IDENTIFY, 0, id_buf as u64, 1, 0, 0) {
        return false;
    }
    let nn = *(id_buf.add(516) as *const u32);
    // IO queues (qid 1).
    let isq = nvme_alloc_queue(NVME_QUEUE_ENTRIES, 64) as *mut NvmeSubmissionEntry;
    let icq = nvme_alloc_queue(NVME_QUEUE_ENTRIES, 16) as *mut NvmeCompletionEntry;
    if isq.is_null() || icq.is_null() {
        return false;
    }
    (*ctrl).io.sq = isq;
    (*ctrl).io.cq = icq;
    // Create IO CQ then SQ.
    if !nvme_submit(ctrl, &mut (*ctrl).admin, NVME_ADMIN_CREATE_CQ, 0, icq as u64, ((NVME_QUEUE_ENTRIES as u32 - 1) << 16) | 1, 1, 0) {
        return false;
    }
    // Create IO SQ: CDW10=QID|QSIZE, CDW11=CQID|PC, CDW12=0.
    if !nvme_submit(ctrl, &mut (*ctrl).admin, NVME_ADMIN_CREATE_SQ, 0, isq as u64, ((NVME_QUEUE_ENTRIES as u32 - 1) << 16) | 1, (1 << 16) | 0x01, 0) {
        return false;
    }
    let qdb = db_base + 2 * 1 * (*ctrl).doorbell_stride as u64;
    (*ctrl).io.db_sq = qdb as *mut u32;
    (*ctrl).io.db_cq = (qdb + (*ctrl).doorbell_stride as u64) as *mut u32;
    // IDENTIFY namespaces.
    let mut ns = 0u32;
    while ns < nn.min(NVME_MAX_NAMESPACES as u32) {
        let nsid = ns + 1;
        let ns_buf = nvme_alloc_queue(1, 4096);
        if ns_buf.is_null() {
            break;
        }
        if nvme_submit(ctrl, &mut (*ctrl).admin, NVME_ADMIN_IDENTIFY, nsid, ns_buf as u64, 0, 0, 0) {
            let ncap = *(ns_buf as *const u64);
            if ncap > 0 {
                (*ctrl).namespaces[ns as usize].nsid = nsid;
                (*ctrl).namespaces[ns as usize].sectors = ncap;
                (*ctrl).namespaces[ns as usize].present = true;
                (*ctrl).ns_count += 1;
            }
        }
        ns += 1;
    }
    (*ctrl).ready = true;
    crate::kernel_log!("[NVMe] Ready: {} namespaces\n", (*ctrl).ns_count);
    true
}

/// NvmeRead - READ ns 1, LBA48-style 64-bit SLBA, polled.
pub unsafe fn nvme_read(lba: u64, buffer: *mut u8, sectors: u16) -> NtStatus {
    let ctrl = &mut NVME_CTRL as *mut NvmeController;
    if !(*ctrl).ready || buffer.is_null() || sectors == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    if (*ctrl).ns_count == 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let nsid = (*ctrl).namespaces[0].nsid;
    if lba + sectors as u64 > (*ctrl).namespaces[0].sectors {
        return STATUS_INVALID_PARAMETER;
    }
    let tail = (*ctrl).io.sq_tail as usize % NVME_QUEUE_ENTRIES;
    let sqe = (*ctrl).io.sq.add(tail);
    let cid = (*ctrl).io.sq_tail;
    (*sqe).cdw0 = (NVME_IO_READ as u32) | ((cid as u32) << 16);
    (*sqe).nsid = nsid;
    (*sqe).cdw2 = 0;
    (*sqe).cdw3 = 0;
    (*sqe).mptr = 0;
    (*sqe).prp1 = buffer as u64;
    (*sqe).prp2 = 0;
    (*sqe).cdw10 = (lba & 0xFFFF_FFFF) as u32;
    (*sqe).cdw11 = (lba >> 32) as u32;
    (*sqe).cdw12 = (sectors as u32) - 1;
    (*sqe).cdw13 = 0;
    (*sqe).cdw14 = 0;
    (*sqe).cdw15 = 0;
    (*ctrl).io.sq_tail = (*ctrl).io.sq_tail.wrapping_add(1);
    nvme_db(&mut (*ctrl).io, true, (*ctrl).io.sq_tail);
    let mut spins = 10_000_000u32;
    while spins > 0 {
        let cqe = (*ctrl).io.cq.add((*ctrl).io.cq_head as usize % NVME_QUEUE_ENTRIES);
        if (((*cqe).status & 1) as u16) == (*ctrl).io.cq_phase {
            let ok = ((*cqe).status >> 1) == 0 && (*cqe).cid == cid;
            (*ctrl).io.cq_head = (*ctrl).io.cq_head.wrapping_add(1);
            if (*ctrl).io.cq_head as usize % NVME_QUEUE_ENTRIES == 0 {
                (*ctrl).io.cq_phase ^= 1;
            }
            nvme_db(&mut (*ctrl).io, false, (*ctrl).io.cq_head);
            return if ok { STATUS_SUCCESS } else { STATUS_IO_DEVICE_ERROR };
        }
        spins -= 1;
    }
    STATUS_IO_TIMEOUT
}

pub unsafe fn nvme_namespace_sectors() -> u64 {
    if NVME_CTRL.ns_count == 0 {
        return 0;
    }
    NVME_CTRL.namespaces[0].sectors
}

pub unsafe fn nvme_is_ready() -> bool {
    NVME_CTRL.ready
}

// ============================================================
// DriverEntry
// ============================================================

pub unsafe extern "C" fn nvme_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut controllers = 0u32;
    let mut i = 0usize;
    while i < pci::pci_device_count() {
        if let Some(dev) = pci::pci_get_device(i) {
            if (*dev).class_code == pci::PCI_CLASS_STORAGE
                && (*dev).subclass == pci::PCI_SUBCLASS_NVME
            {
                pci::pci_enable_device((*dev).bus, (*dev).dev, (*dev).func);
                if nvme_init_controller((*dev).bar[0]) {
                    controllers += 1;
                }
            }
        }
        i += 1;
    }
    let mut dev_obj: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\StorNVMe\0");
    let st = crate::io::io_create_device(
        driver_object,
        0,
        &mut name,
        0x0000002D,
        0,
        0,
        &mut dev_obj,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    crate::kernel_log!("[NVMe] {} controllers\n", controllers);
    STATUS_SUCCESS
}
