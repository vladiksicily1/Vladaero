//! # Virtual Address Descriptors (VAD)
//!
//! AVL tree-based VAD management matching ntoskrnl.exe's vad tree.
//! Tracks virtual address ranges per-process with balance factors.

use core::ffi::c_void;
use alloc::boxed::Box;
use core::sync::atomic::{AtomicU32, AtomicU64, Ordering};

use crate::types::*;
use super::{
    MmPteFlags, MmProtectionMask2, ListEntry, RtlBalancedNode, RtlAvlTree, RtlAvlNode,
    SpinLock, PoolTag, TAG_MMVAD, TAG_MMVA,
    PAGE_SHIFT, PAGE_SIZE_X64, PAGE_MASK_X64,
    mm_warn, mm_dbg, mm_err,
};

pub type MiAddressFlags = u32;
pub const MI_ADDRESS_FILE: MiAddressFlags = 0x0001;
pub const MI_ADDRESS_IMAGE: MiAddressFlags = 0x0002;
pub const MI_ADDRESS_DATA: MiAddressFlags = 0x0004;
pub const MI_ADDRESS_PRIVATE: MiAddressFlags = 0x0008;
pub const MI_ADDRESS_HEADER: MiAddressFlags = 0x0010;

// ============================================================
// VAD Node (AVL tree node)
// ============================================================

#[repr(C)]
#[derive(Debug)]
pub struct MiVadNode {
    pub balanced_node: RtlBalancedNode,
    pub parent: *mut MiVadNode,
    pub start_address: u64,
    pub end_address: u64,
    pub reference_count: u32,
    pub flags: MiAddressFlags,
    pub protection: MmProtectionMask2,
    pub committed: u64,
    pub creating_process: *mut c_void,
    pub first_pte: *mut u64,
    pub last_pte: *mut u64,
    pub lock: SpinLock,
    pub pushed_node: ListEntry,
}

impl MiVadNode {
    pub fn new(
        start_address: u64,
        end_address: u64,
        flags: MiAddressFlags,
        protection: MmProtectionMask2,
    ) -> Self {
        Self {
            balanced_node: RtlBalancedNode {
                children: [core::ptr::null_mut(); 2],
                red: 0,
            },
            parent: core::ptr::null_mut(),
            start_address,
            end_address,
            reference_count: 1,
            flags,
            protection,
            committed: 0,
            creating_process: core::ptr::null_mut(),
            first_pte: core::ptr::null_mut(),
            last_pte: core::ptr::null_mut(),
            lock: SpinLock::new(),
            pushed_node: ListEntry::new(),
        }
    }

    pub fn size_in_bytes(&self) -> u64 {
        self.end_address - self.start_address + 1
    }

    pub fn is_committed(&self) -> bool {
        self.flags & super::MEM_COMMIT != 0
    }

    pub fn is_reserved(&self) -> bool {
        self.flags & super::MEM_RESERVE != 0
    }

    pub fn is_free(&self) -> bool {
        self.flags & super::MEM_FREE != 0
    }
}

unsafe impl Send for MiVadNode {}
unsafe impl Sync for MiVadNode {}

// ============================================================
// VAD Tree
// ============================================================

#[repr(C)]
pub struct MiVadTree {
    pub root: RtlAvlTree,
    pub VadCount: u32,
    pub VadHint: *mut MiVadNode,
    pub VadNodes: u32,
    pub vad_lock: SpinLock,
}

unsafe impl Send for MiVadTree {}
unsafe impl Sync for MiVadTree {}

impl MiVadTree {
    pub const fn new() -> Self {
        Self {
            root: RtlAvlTree::new(),
            VadCount: 0,
            VadHint: core::ptr::null_mut(),
            VadNodes: 0,
            vad_lock: SpinLock::new(),
        }
    }
}

// ============================================================
// VAD split result
// ============================================================

#[derive(Debug, Clone, Copy)]
pub struct VadSplit {
    pub left: *mut MiVadNode,
    pub middle: *mut MiVadNode,
    pub right: *mut MiVadNode,
}

// ============================================================
// AVL Rotation helpers
// ============================================================

unsafe fn mi_vad_rotate_right(tree: &mut MiVadTree, node: *mut MiVadNode) -> *mut MiVadNode {
    unsafe {
        let left = (*node).balanced_node.children[0];
        if left.is_null() {
            return node;
        }
        let left_node = left as *mut MiVadNode;
        (*node).balanced_node.children[0] = (*left_node).balanced_node.children[1];
        let child0 = (*node).balanced_node.children[0];
        if !child0.is_null() {
            (*(child0 as *mut MiVadNode)).parent = node;
        }
        (*left_node).balanced_node.children[1] = &mut (*node).balanced_node as *mut RtlBalancedNode;
        (*left_node).parent = (*node).parent;
        (*node).parent = left_node;

        if (*left_node).parent.is_null() {
            tree.root.root = left;
        } else {
            let parent = (*left_node).parent;
            if (*parent).balanced_node.children[0] == &mut (*node).balanced_node as *mut RtlBalancedNode {
                (*parent).balanced_node.children[0] = left;
            } else {
                (*parent).balanced_node.children[1] = left;
            }
        }

        left_node
    }
}

unsafe fn mi_vad_rotate_left(tree: &mut MiVadTree, node: *mut MiVadNode) -> *mut MiVadNode {
    unsafe {
        let right = (*node).balanced_node.children[1];
        if right.is_null() {
            return node;
        }
        let right_node = right as *mut MiVadNode;
        (*node).balanced_node.children[1] = (*right_node).balanced_node.children[0];
        let child1 = (*node).balanced_node.children[1];
        if !child1.is_null() {
            (*(child1 as *mut MiVadNode)).parent = node;
        }
        (*right_node).balanced_node.children[0] = &mut (*node).balanced_node as *mut RtlBalancedNode;
        (*right_node).parent = (*node).parent;
        (*node).parent = right_node;

        if (*right_node).parent.is_null() {
            tree.root.root = right;
        } else {
            let parent = (*right_node).parent;
            if (*parent).balanced_node.children[0] == &mut (*node).balanced_node as *mut RtlBalancedNode {
                (*parent).balanced_node.children[0] = right;
            } else {
                (*parent).balanced_node.children[1] = right;
            }
        }

        right_node
    }
}

// ============================================================
// MmCreateVad
// ============================================================

pub fn mm_create_vad(
    start_address: u64,
    end_address: u64,
    flags: MiAddressFlags,
    protection: MmProtectionMask2,
    creating_process: *mut c_void,
) -> *mut MiVadNode {
    if start_address >= end_address {
        mm_warn!("MmCreateVad: invalid range {:#x}..{:#x}", start_address, end_address);
        return core::ptr::null_mut();
    }

    let aligned_start = start_address & !(PAGE_SIZE_X64 as u64 - 1);
    let aligned_end = (end_address + PAGE_SIZE_X64 as u64 - 1) & !(PAGE_SIZE_X64 as u64 - 1);

    let mut vad = Box::new(MiVadNode::new(
        aligned_start,
        aligned_end - 1,
        flags,
        protection,
    ));

    vad.creating_process = creating_process;

    mm_trace!("MmCreateVad: created VAD {:#x}..{:#x} flags={:?}",
        aligned_start, aligned_end - 1, flags);

    Box::into_raw(vad)
}

// ============================================================
// MmInsertVad (insert into AVL tree)
// ============================================================

pub fn mm_insert_vad(vad: *mut MiVadNode, tree: &mut MiVadTree) -> NtStatus {
    unsafe {
        tree.vad_lock.lock();

        if tree.root.root.is_null() {
            tree.root.root = &mut (*vad).balanced_node as *mut RtlBalancedNode;
            tree.VadCount = 1;
            tree.VadHint = vad;
            tree.vad_lock.release();
            return STATUS_SUCCESS;
        }

        let mut current = tree.root.root as *mut MiVadNode;

        loop {
            if (*vad).start_address < (*current).start_address {
                if (*current).balanced_node.children[0].is_null() {
                    (*current).balanced_node.children[0] = &mut (*vad).balanced_node as *mut RtlBalancedNode;
                    (*vad).parent = current;

                    // Rebalance
                    mi_vad_rebalance_insert(tree, current);

                    tree.VadCount += 1;
                    tree.VadHint = vad;
                    tree.vad_lock.release();
                    return STATUS_SUCCESS;
                }
                current = (*current).balanced_node.children[0] as *mut MiVadNode;
            } else if (*vad).start_address > (*current).start_address {
                if (*current).balanced_node.children[1].is_null() {
                    (*current).balanced_node.children[1] = &mut (*vad).balanced_node as *mut RtlBalancedNode;
                    (*vad).parent = current;

                    mi_vad_rebalance_insert(tree, current);

                    tree.VadCount += 1;
                    tree.VadHint = vad;
                    tree.vad_lock.release();
                    return STATUS_SUCCESS;
                }
                current = (*current).balanced_node.children[1] as *mut MiVadNode;
            } else {
                mm_warn!("MmInsertVad: overlapping VAD at {:#x}", (*vad).start_address);
                tree.vad_lock.release();
                return STATUS_INVALID_PARAMETER;
            }
        }
    }
}

unsafe fn mi_vad_rebalance_insert(tree: &mut MiVadTree, mut node: *mut MiVadNode) {
    unsafe {
        loop {
            let parent = (*node).parent;
            if parent.is_null() {
                break;
            }

            let grandparent = (*parent).parent;
            if grandparent.is_null() {
                break;
            }

            let is_left = (*grandparent).balanced_node.children[0] as *mut MiVadNode == parent;
            let uncle = if is_left {
                (*grandparent).balanced_node.children[1] as *mut MiVadNode
            } else {
                (*grandparent).balanced_node.children[0] as *mut MiVadNode
            };

            if !uncle.is_null() && (*uncle).balanced_node.red == 1 {
                // Red uncle: recolor
                (*parent).balanced_node.red = 0;
                (*uncle).balanced_node.red = 0;
                (*grandparent).balanced_node.red = 1;
                node = grandparent;
            } else {
                // Black uncle: rotate
                if is_left && (*parent).balanced_node.children[0] as *mut MiVadNode == node {
                    mi_vad_rotate_right(tree, grandparent);
                    core::mem::swap(&mut (*grandparent).balanced_node.red,
                                    &mut (*parent).balanced_node.red);
                } else if !is_left && (*parent).balanced_node.children[1] as *mut MiVadNode == node {
                    mi_vad_rotate_left(tree, grandparent);
                    core::mem::swap(&mut (*grandparent).balanced_node.red,
                                    &mut (*parent).balanced_node.red);
                } else if is_left {
                    mi_vad_rotate_left(tree, parent);
                    mi_vad_rotate_right(tree, grandparent);
                    core::mem::swap(&mut (*grandparent).balanced_node.red,
                                    &mut (*node).balanced_node.red);
                } else {
                    mi_vad_rotate_right(tree, parent);
                    mi_vad_rotate_left(tree, grandparent);
                    core::mem::swap(&mut (*grandparent).balanced_node.red,
                                    &mut (*node).balanced_node.red);
                }
                break;
            }
        }
    }
}

// ============================================================
// MiLocateVad (find VAD containing address)
// ============================================================

pub fn mi_locate_vad(virtual_address: u64, tree: &MiVadTree) -> Option<*mut MiVadNode> {
    unsafe {
        let mut current = tree.root.root as *mut MiVadNode;

        while !current.is_null() {
            let vad = &*current;

            if virtual_address >= vad.start_address && virtual_address <= vad.end_address {
                return Some(current);
            }

            if virtual_address < vad.start_address {
                current = vad.balanced_node.children[0] as *mut MiVadNode;
            } else {
                current = vad.balanced_node.children[1] as *mut MiVadNode;
            }
        }

        None
    }
}

// ============================================================
// MiLocateVadHint
// ============================================================

pub fn mi_locate_vad_hint(
    virtual_address: u64,
    tree: &MiVadTree,
    largest_vad: bool,
) -> Option<*mut MiVadNode> {
    if largest_vad {
        if !tree.VadHint.is_null() {
            unsafe {
                let hint = &*tree.VadHint;
                if virtual_address >= hint.start_address && virtual_address <= hint.end_address {
                    return Some(tree.VadHint);
                }
            }
        }
    }
    mi_locate_vad(virtual_address, tree)
}

// ============================================================
// MmRemoveVad (remove from tree)
// ============================================================

pub fn mm_remove_vad(vad: *mut MiVadNode, tree: &mut MiVadTree) -> NtStatus {
    unsafe {
        tree.vad_lock.lock();

        let vad_node = &*vad;

        // Remove from AVL tree
        if !vad_node.balanced_node.children[0].is_null() &&
           !vad_node.balanced_node.children[1].is_null() {
            // Two children: find in-order successor
            let mut successor = vad_node.balanced_node.children[1] as *mut MiVadNode;
            while !(*successor).balanced_node.children[0].is_null() {
                successor = (*successor).balanced_node.children[0] as *mut MiVadNode;
            }
            // Swap values
            core::ptr::swap_nonoverlapping(vad as *mut u8, successor as *mut u8,
                                           core::mem::size_of::<MiVadNode>());
            // Now remove successor (which has at most one child)
            mi_vad_remove_node(tree, successor);
        } else {
            mi_vad_remove_node(tree, vad);
        }

        tree.VadCount -= 1;

        mm_trace!("MmRemoveVad: removed VAD at {:#x}", vad_node.start_address);
        tree.vad_lock.release();
        STATUS_SUCCESS
    }
}

unsafe fn mi_vad_remove_node(tree: &mut MiVadTree, node: *mut MiVadNode) {
    unsafe {
        let child = if !(*node).balanced_node.children[0].is_null() {
            (*node).balanced_node.children[0] as *mut MiVadNode
        } else {
            (*node).balanced_node.children[1] as *mut MiVadNode
        };

        if (*node).parent.is_null() {
            tree.root.root = child as *mut RtlBalancedNode;
            if !child.is_null() {
                (*child).parent = core::ptr::null_mut();
            }
        } else {
            let parent = (*node).parent;
            if (*parent).balanced_node.children[0] as *mut MiVadNode == node {
                (*parent).balanced_node.children[0] = child as *mut RtlBalancedNode;
            } else {
                (*parent).balanced_node.children[1] = child as *mut RtlBalancedNode;
            }
            if !child.is_null() {
                (*child).parent = parent;
            }
            mi_vad_rebalance_remove(tree, parent);
        }
    }
}

unsafe fn mi_vad_rebalance_remove(tree: &mut MiVadTree, mut node: *mut MiVadNode) {
    unsafe {
        loop {
            if (*node).balanced_node.red == 1 {
                (*node).balanced_node.red = 0;
                break;
            }

            let parent = (*node).parent;
            if parent.is_null() {
                break;
            }

            let is_left = (*parent).balanced_node.children[0] as *mut MiVadNode == node;
            let sibling = if is_left {
                (*parent).balanced_node.children[1] as *mut MiVadNode
            } else {
                (*parent).balanced_node.children[0] as *mut MiVadNode
            };

            if sibling.is_null() {
                break;
            }

            if (*sibling).balanced_node.red == 1 {
                (*sibling).balanced_node.red = 0;
                (*parent).balanced_node.red = 1;
                if is_left {
                    mi_vad_rotate_left(tree, parent);
                } else {
                    mi_vad_rotate_right(tree, parent);
                }
                continue;
            }

            let left_nephew = (*sibling).balanced_node.children[0];
            let right_nephew = (*sibling).balanced_node.children[1];

            let left_red = !left_nephew.is_null() && (*left_nephew).red == 1;
            let right_red = !right_nephew.is_null() && (*right_nephew).red == 1;

            if is_left && right_red {
                (*sibling).balanced_node.red = (*parent).balanced_node.red;
                (*parent).balanced_node.red = 0;
                (*right_nephew).red = 0;
                mi_vad_rotate_left(tree, parent);
                break;
            } else if is_left && left_red {
                (*sibling).balanced_node.red = 1;
                (*left_nephew).red = 0;
                mi_vad_rotate_right(tree, sibling);
                continue;
            } else if !is_left && left_red {
                (*sibling).balanced_node.red = (*parent).balanced_node.red;
                (*parent).balanced_node.red = 0;
                (*left_nephew).red = 0;
                mi_vad_rotate_right(tree, parent);
                break;
            } else if !is_left && right_red {
                (*sibling).balanced_node.red = 1;
                (*right_nephew).red = 0;
                mi_vad_rotate_left(tree, sibling);
                continue;
            } else {
                (*sibling).balanced_node.red = 1;
                node = parent;
            }
        }
    }
}

// ============================================================
// MmQueryVad
// ============================================================

pub fn mm_query_vad(vad: &MiVadNode) -> VadInformation {
    VadInformation {
        start_address: vad.start_address,
        end_address: vad.end_address,
        size: vad.size_in_bytes(),
        committed: vad.committed,
        protection: vad.protection,
        flags: vad.flags,
    }
}

// ============================================================
// VadInformation output
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct VadInformation {
    pub start_address: u64,
    pub end_address: u64,
    pub size: u64,
    pub committed: u64,
    pub protection: MmProtectionMask2,
    pub flags: MiAddressFlags,
}

// ============================================================
// MmVadIterate
// ============================================================

pub unsafe fn mm_vad_iterate<F: FnMut(&MiVadNode) -> bool>(tree: &MiVadTree, mut callback: F) {
    unsafe {
        let mut stack: [(*mut MiVadNode, u8); 128] = [(core::ptr::null_mut(), 0); 128];
        let mut sp: usize = 0;

        if !tree.root.root.is_null() {
            stack[sp] = (tree.root.root as *mut MiVadNode, 0);
            sp += 1;
        }

        while sp > 0 {
            sp -= 1;
            let (node, state) = stack[sp];
            if node.is_null() {
                continue;
            }

            let vad = &*node;

            if state == 0 && !callback(vad) {
                return;
            }

            if state == 0 {
                if sp < 127 {
                    stack[sp] = (node, 1);
                    sp += 1;
                }
                if !vad.balanced_node.children[0].is_null() && sp < 128 {
                    stack[sp] = (vad.balanced_node.children[0] as *mut MiVadNode, 0);
                    sp += 1;
                }
            } else {
                if !vad.balanced_node.children[1].is_null() && sp < 128 {
                    stack[sp] = (vad.balanced_node.children[1] as *mut MiVadNode, 0);
                    sp += 1;
                }
            }
        }
    }
}

// ============================================================
// MiGetTopLevelVad
// ============================================================

pub fn mi_get_top_level_vad(tree: &MiVadTree) -> *mut MiVadNode {
    if tree.root.root.is_null() {
        core::ptr::null_mut()
    } else {
        tree.root.root as *mut MiVadNode
    }
}

// ============================================================
// VAD init
// ============================================================

pub fn mm_vad_init() {
    mm_dbg!("MmVadInit: VAD subsystem initialized");
}
