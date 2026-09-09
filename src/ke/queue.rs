/// Ke/Queue - Dispatcher Queue Objects
use alloc::vec::Vec;
use crate::types::*;
use super::dispatcher::{ListEntry, DispatcherHeader};

#[repr(C)]
pub struct Kqueue {
    pub header: DispatcherHeader,
    pub entry_list: ListEntry,
    pub wait_list_head: ListEntry,
    pub current_count: u32,
    pub maximum_count: u32,
    pub thread_list: ListEntry,
}

pub unsafe fn ke_initialize_queue(queue: *mut Kqueue, count: u32) {
    let q = &mut *queue;
    q.header.r#type = 4; // ProcessObject
    q.header.absolute = 0;
    q.header.size = core::mem::size_of::<Kqueue>() as u8;
    q.header.inserted = 0;
    q.header.signal_state = 0;
    q.entry_list.initialize();
    q.wait_list_head.initialize();
    q.thread_list.initialize();
    q.maximum_count = count;
    q.current_count = 0;
}

pub unsafe fn ke_insert_queue(queue: *mut Kqueue, entry: *mut ListEntry) -> ListEntry {
    let q = &mut *queue;
    let signal = q.header.signal_state > 0;

    if signal || q.wait_list_head.flink != &mut q.wait_list_head as *mut ListEntry {
        let mut wake_thread: *mut super::dispatcher::Kthread = core::ptr::null_mut();
        if !q.wait_list_head.is_empty() {
            let wait_entry = q.wait_list_head.flink;
            let wb_ptr = (wait_entry as *mut u8).offset(-(core::mem::offset_of!(super::dispatcher::KwaitBlock, wait_list_entry) as isize)) as *mut super::dispatcher::KwaitBlock;
            let wb = &mut *wb_ptr;
            wake_thread = wb.thread;
            super::dispatcher::ki_ready_thread(wake_thread);
        }

        ListEntry { flink: entry, blink: entry }
    } else {
        q.entry_list.insert_tail(&mut *entry);
        q.current_count += 1;
        ListEntry { flink: entry, blink: entry }
    }
}

pub unsafe fn ke_remove_queue(queue: *mut Kqueue) -> *mut ListEntry {
    let q = &mut *queue;

    if !q.entry_list.is_empty() {
        let entry = q.entry_list.flink;
        q.entry_list.remove();
        q.current_count -= 1;
        return entry;
    }

    if q.header.signal_state > 0 {
        q.header.signal_state -= 1;
        q.current_count -= 1;
        return core::ptr::null_mut();
    }

    q.header.inserted = 0;
    core::ptr::null_mut()
}

pub unsafe fn ke_rundown_queue(queue: *mut Kqueue) -> ListEntry {
    let q = &mut *queue;
    let mut result = ListEntry::new();
    result.initialize();

    while !q.entry_list.is_empty() {
        let entry = q.entry_list.flink;
        (*entry).remove();
        result.insert_tail(&mut *entry);
    }

    q.current_count = 0;
    q.header.signal_state = 0;
    result
}
