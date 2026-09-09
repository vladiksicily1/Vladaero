/// Alpc - Advanced Local Procedure Call (Alpc/Alpcp)
use core::ffi::c_void;
use crate::types::*;
use crate::ke::dispatcher::ListEntry;

pub const ALPC_MESSAGE_DATATYPE: u32 = 0;
pub const ALPC_MESSAGE_VIEW_ALL: u32 = 0x1F;

#[repr(C)]
pub struct AlpcPort {
    pub port_id: u32,
    pub flags: u32,
    pub max_message_length: u32,
    pub max_sequence_length: u32,
    pub message_queue: ListEntry,
    pub wait_queue: ListEntry,
    pub connection_port: *mut AlpcPort,
    pub target_port: *mut AlpcPort,
    pub port_context: *mut c_void,
    pub owner_process: Peprocess,
    pub port_handle: Handle,
    pub sequence_number: u32,
    pub message_count: u32,
    pub callback_number: u32,
}

#[repr(C)]
pub struct AlpcMessage {
    pub port_message: [u8; 48],  // PORT_MESSAGE
    pub flags: u32,
    pub data_valid_mask: u32,
    pub message_id: u32,
    pub callback_id: u32,
    pub reserved: u64,
}

pub unsafe fn alpc_initialize() -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn alpc_create_port(
    port_attributes: *mut c_void,
    object_attributes: *mut c_void,
    port_handle: *mut Handle,
) -> NtStatus {
    let port = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(core::mem::size_of::<AlpcPort>()) as *mut AlpcPort;
    if port.is_null() { return STATUS_NO_MEMORY; }

    core::ptr::write_bytes(port as *mut u8, 0, core::mem::size_of::<AlpcPort>());
    (*port).flags = 0;
    (*port).message_queue.initialize();
    (*port).wait_queue.initialize();

    crate::ob::ob_open_object_by_pointer(
        port as *mut c_void,
        0,
        core::ptr::null_mut(),
        0,
        core::ptr::null_mut(),
        0,
        port_handle,
    )
}

pub unsafe fn alpc_connect_port(
    server_port_handle: *mut Handle,
    server_port_name: *const u16,
    object_attributes: *mut c_void,
    port_attributes: *mut c_void,
    flags: u32,
    server_sid: *mut u8,
    message: *mut AlpcMessage,
    buffer_length: *mut u32,
    message_attributes: *mut c_void,
    wait_attributes: *mut c_void,
    timeout: u64,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn alpc_send_wait_receive_port(
    port_handle: Handle,
    flags: u32,
    send_message: *mut AlpcMessage,
    send_attributes: *mut c_void,
    receive_message: *mut AlpcMessage,
    receive_message_size: *mut u32,
    receive_attributes: *mut c_void,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn alpc_receive_message(
    port_handle: Handle,
    buffer: *mut u8,
    buffer_size: u32,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn alpc_complete_message(
    port_handle: Handle,
    message: *mut AlpcMessage,
) -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn alpc_disconnect_port(
    port_handle: Handle,
) -> NtStatus {
    STATUS_SUCCESS
}

// ============================================================
// Win10 ALPC: queued messages, port registry, connect/disconnect
// (Alpc/Alpcp - LPC successor used by CSRSS, LSASS, WMI...)
// ============================================================

pub const ALPC_PORT_TYPE_CONNECTION: u32 = 1;
pub const ALPC_PORT_TYPE_CLIENT: u32 = 2;
pub const ALPC_PORT_TYPE_SERVER: u32 = 3;

pub const ALPC_MSG_FLAG_SYNC_REQUEST: u32 = 0x00020000;
pub const ALPC_MSG_FLAG_WAIT: u32 = 0x00001000;

pub const ALPC_MAX_MESSAGE_SIZE: usize = 65488;
pub const ALPC_MAX_PORTS: usize = 1024;

pub const STATUS_PORT_DISCONNECTED_LOCAL: NtStatus = 0xC0000037;

#[repr(C)]
pub struct AlpcQueuedMessage {
    pub message_id: u32,
    pub callback_id: u32,
    pub flags: u32,
    pub data_len: u32,
    pub data: [u8; 256],
    pub sender_port_id: u32,
    pub next: *mut AlpcQueuedMessage,
}

#[repr(C)]
pub struct AlpcPortFull {
    pub base: AlpcPort,
    pub port_type: u32,
    pub port_name: [u16; 64],
    pub queue_head: *mut AlpcQueuedMessage,
    pub queue_tail: *mut AlpcQueuedMessage,
    pub queue_depth: u32,
    pub max_queue_depth: u32,
    pub peer: *mut AlpcPortFull,
    pub disconnected: bool,
}

static mut ALPC_PORT_LIST: *mut AlpcPortFull = core::ptr::null_mut();
static ALPC_NEXT_PORT_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);
static ALPC_NEXT_MESSAGE_ID: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(1);

unsafe fn alpcp_find_port(id: u32) -> *mut AlpcPortFull {
    let mut cur = ALPC_PORT_LIST;
    while !cur.is_null() {
        if (*cur).base.port_id == id {
            return cur;
        }
        cur = (*cur).base.target_port as *mut AlpcPortFull;
    }
    core::ptr::null_mut()
}

unsafe fn alpcp_copy_name(dst: *mut u16, src: *const u16) {
    if src.is_null() {
        return;
    }
    let mut i = 0;
    while i < 63 && *src.add(i) != 0 {
        *dst.add(i) = *src.add(i);
        i += 1;
    }
    *dst.add(i) = 0;
}

/// AlpcCreatePortFull - named connection port or anonymous comm port.
pub unsafe fn alpc_create_port_full(
    port_name: *const u16,
    port_type: u32,
    max_message_length: u32,
    port_out: *mut *mut AlpcPortFull,
) -> NtStatus {
    if port_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let p = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<AlpcPortFull>(),
    ) as *mut AlpcPortFull;
    if p.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(p as *mut u8, 0, core::mem::size_of::<AlpcPortFull>());
    (*p).base.port_id =
        ALPC_NEXT_PORT_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*p).base.max_message_length = if max_message_length == 0 {
        4096
    } else {
        max_message_length.min(ALPC_MAX_MESSAGE_SIZE as u32)
    };
    (*p).base.message_queue.initialize();
    (*p).base.wait_queue.initialize();
    (*p).port_type = port_type;
    (*p).max_queue_depth = 64;
    alpcp_copy_name((*p).port_name.as_mut_ptr(), port_name);
    // Thread into the global list via target_port link.
    (*p).base.target_port = ALPC_PORT_LIST as *mut AlpcPort;
    ALPC_PORT_LIST = p;
    *port_out = p;
    STATUS_SUCCESS
}

/// AlpcConnectFull - client connects to a named connection port.
///
/// Creates the client+server communication port pair and links peers.
pub unsafe fn alpc_connect_full(
    server_port_name: *const u16,
    client_out: *mut *mut AlpcPortFull,
    server_out: *mut *mut AlpcPortFull,
) -> NtStatus {
    if server_port_name.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Find the named connection port.
    let mut cur = ALPC_PORT_LIST;
    let mut server_conn: *mut AlpcPortFull = core::ptr::null_mut();
    while !cur.is_null() {
        if (*cur).port_type == ALPC_PORT_TYPE_CONNECTION {
            let mut i = 0;
            let mut name_match = true;
            loop {
                let a = if i < 64 { (*cur).port_name[i] } else { 0 };
                let b = *server_port_name.add(i);
                if a == 0 && b == 0 {
                    break;
                }
                if crate::nls::nls_upcase_full(a) != crate::nls::nls_upcase_full(b) {
                    name_match = false;
                    break;
                }
                i += 1;
                if i >= 64 {
                    break;
                }
            }
            if name_match {
                server_conn = cur;
                break;
            }
        }
        cur = (*cur).base.target_port as *mut AlpcPortFull;
    }
    if server_conn.is_null() || (*server_conn).disconnected {
        return STATUS_OBJECT_NAME_NOT_FOUND;
    }
    let mut client: *mut AlpcPortFull = core::ptr::null_mut();
    let mut server: *mut AlpcPortFull = core::ptr::null_mut();
    let mut st = alpc_create_port_full(
        core::ptr::null(),
        ALPC_PORT_TYPE_CLIENT,
        (*server_conn).base.max_message_length,
        &mut client,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    st = alpc_create_port_full(
        core::ptr::null(),
        ALPC_PORT_TYPE_SERVER,
        (*server_conn).base.max_message_length,
        &mut server,
    );
    if st != STATUS_SUCCESS {
        return st;
    }
    (*client).peer = server;
    (*server).peer = client;
    (*client).base.connection_port = server_conn as *mut AlpcPort;
    (*server).base.connection_port = server_conn as *mut AlpcPort;
    (*server_conn).base.message_count += 1;
    if !client_out.is_null() {
        *client_out = client;
    }
    if !server_out.is_null() {
        *server_out = server;
    }
    STATUS_SUCCESS
}

/// AlpcSendFull - enqueue a message on the peer's queue.
pub unsafe fn alpc_send_full(
    sender: *mut AlpcPortFull,
    data: *const u8,
    data_len: u32,
    flags: u32,
    message_id_out: *mut u32,
) -> NtStatus {
    if sender.is_null() || (data.is_null() && data_len > 0) {
        return STATUS_INVALID_PARAMETER;
    }
    let peer = (*sender).peer;
    if peer.is_null() || (*peer).disconnected {
        return STATUS_PORT_DISCONNECTED_LOCAL;
    }
    if (*peer).queue_depth >= (*peer).max_queue_depth {
        return STATUS_INSUFFICIENT_RESOURCES;
    }
    let max = (*peer).base.max_message_length as usize;
    if data_len as usize > max || data_len as usize > 256 {
        return STATUS_BUFFER_OVERFLOW;
    }
    let m = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<AlpcQueuedMessage>(),
    ) as *mut AlpcQueuedMessage;
    if m.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(m as *mut u8, 0, core::mem::size_of::<AlpcQueuedMessage>());
    (*m).message_id =
        ALPC_NEXT_MESSAGE_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*m).flags = flags;
    (*m).data_len = data_len;
    if data_len > 0 {
        core::ptr::copy_nonoverlapping(data, (*m).data.as_mut_ptr(), data_len as usize);
    }
    (*m).sender_port_id = (*sender).base.port_id;
    (*m).next = core::ptr::null_mut();
    if (*peer).queue_tail.is_null() {
        (*peer).queue_head = m;
        (*peer).queue_tail = m;
    } else {
        (*(*peer).queue_tail).next = m;
        (*peer).queue_tail = m;
    }
    (*peer).queue_depth += 1;
    (*peer).base.message_count += 1;
    if !message_id_out.is_null() {
        *message_id_out = (*m).message_id;
    }
    STATUS_SUCCESS
}

/// AlpcReceiveFull - dequeue the oldest message from a port.
pub unsafe fn alpc_receive_full(
    port: *mut AlpcPortFull,
    buffer: *mut u8,
    buffer_len: u32,
    actual_len_out: *mut u32,
    message_id_out: *mut u32,
) -> NtStatus {
    if port.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*port).queue_head.is_null() {
        if !actual_len_out.is_null() {
            *actual_len_out = 0;
        }
        return STATUS_NO_MORE_ENTRIES;
    }
    let m = (*port).queue_head;
    (*port).queue_head = (*m).next;
    if (*port).queue_head.is_null() {
        (*port).queue_tail = core::ptr::null_mut();
    }
    (*port).queue_depth -= 1;
    if buffer.is_null() || buffer_len < (*m).data_len {
        if !actual_len_out.is_null() {
            *actual_len_out = (*m).data_len;
        }
        crate::mm::pool::ex_free_pool(m as *mut core::ffi::c_void);
        return STATUS_BUFFER_TOO_SMALL;
    }
    if (*m).data_len > 0 {
        core::ptr::copy_nonoverlapping(
            (*m).data.as_ptr(),
            buffer,
            (*m).data_len as usize,
        );
    }
    if !actual_len_out.is_null() {
        *actual_len_out = (*m).data_len;
    }
    if !message_id_out.is_null() {
        *message_id_out = (*m).message_id;
    }
    crate::mm::pool::ex_free_pool(m as *mut core::ffi::c_void);
    STATUS_SUCCESS
}

/// AlpcDisconnectFull - tear down a port and drain its queue.
pub unsafe fn alpc_disconnect_full(port: *mut AlpcPortFull) -> NtStatus {
    if port.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    (*port).disconnected = true;
    // Drain queue.
    let mut m = (*port).queue_head;
    while !m.is_null() {
        let next = (*m).next;
        crate::mm::pool::ex_free_pool(m as *mut core::ffi::c_void);
        m = next;
    }
    (*port).queue_head = core::ptr::null_mut();
    (*port).queue_tail = core::ptr::null_mut();
    (*port).queue_depth = 0;
    // Break the peer link.
    let peer = (*port).peer;
    if !peer.is_null() {
        (*peer).peer = core::ptr::null_mut();
    }
    (*port).peer = core::ptr::null_mut();
    STATUS_SUCCESS
}

/// AlpcQueryQueueDepth - pending message count.
pub unsafe fn alpc_query_queue_depth(port: *mut AlpcPortFull) -> u32 {
    if port.is_null() {
        return 0;
    }
    (*port).queue_depth
}
