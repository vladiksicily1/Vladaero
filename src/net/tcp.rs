/// TCP (tcpip.sys): TCBs, state machine, streams, retransmit
///
/// RFC 793 state machine (LISTEN/SYN_SENT/SYN_RCVD/ESTABLISHED/
/// FIN_WAIT/CLOSE_WAIT/LAST_ACK/TIME_WAIT), byte-stream buffers,
/// single-outstanding-segment retransmission, RST handling.

use core::ffi::c_void;

use crate::types::*;
use super::{IP_PROTO_TCP, net_htons, net_ntohs, net_ntohl, net_htonl, net_checksum};

// ============================================================
// TCP states + flags
// ============================================================

pub const TCP_STATE_CLOSED: u8 = 0;
pub const TCP_STATE_LISTEN: u8 = 1;
pub const TCP_STATE_SYN_SENT: u8 = 2;
pub const TCP_STATE_SYN_RCVD: u8 = 3;
pub const TCP_STATE_ESTABLISHED: u8 = 4;
pub const TCP_STATE_FIN_WAIT_1: u8 = 5;
pub const TCP_STATE_FIN_WAIT_2: u8 = 6;
pub const TCP_STATE_CLOSE_WAIT: u8 = 7;
pub const TCP_STATE_LAST_ACK: u8 = 8;
pub const TCP_STATE_TIME_WAIT: u8 = 9;

pub const TCP_FIN: u8 = 0x01;
pub const TCP_SYN: u8 = 0x02;
pub const TCP_RST: u8 = 0x04;
pub const TCP_PSH: u8 = 0x08;
pub const TCP_ACK: u8 = 0x10;

pub const TCP_MAX_TCBS: usize = 256;
pub const TCP_RECV_BUF: usize = 65536;
pub const TCP_SEND_BUF: usize = 65536;
pub const TCP_MSS: usize = 1460;
pub const TCP_RTO_MS: u64 = 1000;
pub const TCP_TIME_WAIT_MS: u64 = 60_000;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct TcpControlBlock {
    pub local_addr: u32,
    pub local_port: u16,
    pub remote_addr: u32,
    pub remote_port: u16,
    pub state: u8,
    pub snd_una: u32,
    pub snd_nxt: u32,
    pub snd_wnd: u32,
    pub rcv_nxt: u32,
    pub rcv_wnd: u32,
    pub iss: u32,
    pub irs: u32,
    // Stream buffers (pool-backed).
    pub recv_buf: *mut u8,
    pub recv_len: usize,
    pub recv_cap: usize,
    pub send_buf: *mut u8,
    pub send_len: usize,
    pub send_cap: usize,
    // Retransmit: one outstanding segment.
    pub rtx_buf: [u8; TCP_MSS],
    pub rtx_len: usize,
    pub rtx_seq: u32,
    pub rtx_flags: u8,
    pub rtx_time_ms: u64,
    pub rtx_active: bool,
    // Accept queue (listener only).
    pub backlog: *mut TcpControlBlock,
    pub backlog_count: u32,
    pub backlog_max: u32,
    pub parent_listener: *mut TcpControlBlock,
    pub time_wait_until_ms: u64,
    pub in_use: bool,
}

static mut TCP_TCBS: [TcpControlBlock; TCP_MAX_TCBS] = [TcpControlBlock {
    local_addr: 0,
    local_port: 0,
    remote_addr: 0,
    remote_port: 0,
    state: TCP_STATE_CLOSED,
    snd_una: 0,
    snd_nxt: 0,
    snd_wnd: 65535,
    rcv_nxt: 0,
    rcv_wnd: TCP_RECV_BUF as u32,
    iss: 0,
    irs: 0,
    recv_buf: core::ptr::null_mut(),
    recv_len: 0,
    recv_cap: 0,
    send_buf: core::ptr::null_mut(),
    send_len: 0,
    send_cap: 0,
    rtx_buf: [0; TCP_MSS],
    rtx_len: 0,
    rtx_seq: 0,
    rtx_flags: 0,
    rtx_time_ms: 0,
    rtx_active: false,
    backlog: core::ptr::null_mut(),
    backlog_count: 0,
    backlog_max: 0,
    parent_listener: core::ptr::null_mut(),
    time_wait_until_ms: 0,
    in_use: false,
}; TCP_MAX_TCBS];

static mut TCP_NOW_MS: u64 = 0;
static TCP_NEXT_ISS: core::sync::atomic::AtomicU32 =
    core::sync::atomic::AtomicU32::new(0x1000);
static TCP_NEXT_EPHEMERAL: core::sync::atomic::AtomicU16 =
    core::sync::atomic::AtomicU16::new(49152);

pub unsafe fn tcp_initialize() -> NtStatus {
    STATUS_SUCCESS
}

pub unsafe fn tcp_tick(now_ms: u64) {
    TCP_NOW_MS = now_ms;
    // Retransmit + TIME_WAIT expiry.
    let mut i = 0;
    while i < TCP_MAX_TCBS {
        if TCP_TCBS[i].in_use {
            let t = &mut TCP_TCBS[i] as *mut TcpControlBlock;
            if (*t).rtx_active && now_ms - (*t).rtx_time_ms >= TCP_RTO_MS {
                // Retransmit the outstanding segment.
                tcp_send_segment(
                    t,
                    (*t).rtx_seq,
                    (*t).rtx_flags,
                    (*t).rtx_buf.as_ptr(),
                    (*t).rtx_len,
                );
                (*t).rtx_time_ms = now_ms;
            }
            if (*t).state == TCP_STATE_TIME_WAIT && now_ms >= (*t).time_wait_until_ms {
                tcp_free_tcb(t);
            }
        }
        i += 1;
    }
}

unsafe fn tcp_alloc_tcb() -> *mut TcpControlBlock {
    let mut i = 0;
    while i < TCP_MAX_TCBS {
        if !TCP_TCBS[i].in_use {
            let t = &mut TCP_TCBS[i] as *mut TcpControlBlock;
            // Preserve nothing; zero + fresh buffers.
            let recv = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(TCP_RECV_BUF)
                as *mut u8;
            let send = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(TCP_SEND_BUF)
                as *mut u8;
            if recv.is_null() || send.is_null() {
                if !recv.is_null() {
                    crate::mm::pool::ex_free_pool(recv as *mut c_void);
                }
                if !send.is_null() {
                    crate::mm::pool::ex_free_pool(send as *mut c_void);
                }
                return core::ptr::null_mut();
            }
            core::ptr::write_bytes(t as *mut u8, 0, core::mem::size_of::<TcpControlBlock>());
            (*t).recv_buf = recv;
            (*t).recv_cap = TCP_RECV_BUF;
            (*t).send_buf = send;
            (*t).send_cap = TCP_SEND_BUF;
            (*t).snd_wnd = 65535;
            (*t).rcv_wnd = TCP_RECV_BUF as u32;
            (*t).in_use = true;
            return t;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

unsafe fn tcp_free_tcb(t: *mut TcpControlBlock) {
    if t.is_null() {
        return;
    }
    if !(*t).recv_buf.is_null() {
        crate::mm::pool::ex_free_pool((*t).recv_buf as *mut c_void);
    }
    if !(*t).send_buf.is_null() {
        crate::mm::pool::ex_free_pool((*t).send_buf as *mut c_void);
    }
    core::ptr::write_bytes(t as *mut u8, 0, core::mem::size_of::<TcpControlBlock>());
}

unsafe fn tcp_find_tcb(
    local_port: u16,
    remote_addr: u32,
    remote_port: u16,
) -> *mut TcpControlBlock {
    let mut i = 0;
    while i < TCP_MAX_TCBS {
        if TCP_TCBS[i].in_use
            && TCP_TCBS[i].local_port == local_port
            && TCP_TCBS[i].remote_addr == remote_addr
            && TCP_TCBS[i].remote_port == remote_port
            && TCP_TCBS[i].state != TCP_STATE_LISTEN
        {
            return &mut TCP_TCBS[i] as *mut TcpControlBlock;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

unsafe fn tcp_find_listener(local_port: u16) -> *mut TcpControlBlock {
    let mut i = 0;
    while i < TCP_MAX_TCBS {
        if TCP_TCBS[i].in_use
            && TCP_TCBS[i].local_port == local_port
            && TCP_TCBS[i].state == TCP_STATE_LISTEN
        {
            return &mut TCP_TCBS[i] as *mut TcpControlBlock;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

// ============================================================
// Segment TX
// ============================================================

unsafe fn tcp_checksum(
    src: u32,
    dst: u32,
    header: *const u8,
    header_len: usize,
    payload: *const u8,
    payload_len: usize,
) -> u16 {
    let total = 12 + header_len + payload_len;
    let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(total) as *mut u8;
    if buf.is_null() {
        return 0;
    }
    core::ptr::copy_nonoverlapping(src.to_be_bytes().as_ptr(), buf, 4);
    core::ptr::copy_nonoverlapping(dst.to_be_bytes().as_ptr(), buf.add(4), 4);
    *buf.add(8) = 0;
    *buf.add(9) = IP_PROTO_TCP;
    let tcp_len = (header_len + payload_len) as u16;
    *buf.add(10) = (tcp_len >> 8) as u8;
    *buf.add(11) = (tcp_len & 0xFF) as u8;
    core::ptr::copy_nonoverlapping(header, buf.add(12), header_len);
    if payload_len > 0 {
        core::ptr::copy_nonoverlapping(payload, buf.add(12 + header_len), payload_len);
    }
    let s = core::slice::from_raw_parts(buf, total);
    let cks = net_checksum(s);
    crate::mm::pool::ex_free_pool(buf as *mut c_void);
    cks
}

unsafe fn tcp_send_segment(
    t: *mut TcpControlBlock,
    seq: u32,
    flags: u8,
    payload: *const u8,
    payload_len: usize,
) -> NtStatus {
    let mut hdr = [0u8; 20];
    hdr[0] = ((*t).local_port >> 8) as u8;
    hdr[1] = ((*t).local_port & 0xFF) as u8;
    hdr[2] = ((*t).remote_port >> 8) as u8;
    hdr[3] = ((*t).remote_port & 0xFF) as u8;
    hdr[4..8].copy_from_slice(&seq.to_be_bytes());
    hdr[8..12].copy_from_slice(&(*t).rcv_nxt.to_be_bytes());
    hdr[12] = 0x50; // data offset 5
    hdr[13] = flags;
    let wnd = ((*t).rcv_wnd.min(65535)) as u16;
    hdr[14..16].copy_from_slice(&wnd.to_be_bytes());
    let src = super::ip::ip_address_for_adapter(super::ip::ip_adapter_for_address(
        (*t).remote_addr,
    ));
    let cks = tcp_checksum(src, (*t).remote_addr, hdr.as_ptr(), 20, payload, payload_len);
    hdr[16] = (cks >> 8) as u8;
    hdr[17] = (cks & 0xFF) as u8;
    // Merge header+payload into one ip_send buffer.
    let total = 20 + payload_len;
    let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(total) as *mut u8;
    if buf.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::copy_nonoverlapping(hdr.as_ptr(), buf, 20);
    if payload_len > 0 {
        core::ptr::copy_nonoverlapping(payload, buf.add(20), payload_len);
    }
    let st = super::ip::ip_send(
        (*t).local_addr,
        (*t).remote_addr,
        IP_PROTO_TCP,
        128,
        buf,
        total,
    );
    crate::mm::pool::ex_free_pool(buf as *mut c_void);
    st
}

unsafe fn tcp_send_empty(t: *mut TcpControlBlock, flags: u8) -> NtStatus {
    tcp_send_segment(t, (*t).snd_nxt, flags, core::ptr::null(), 0)
}

// ============================================================
// API: listen / connect / accept / send / recv / close
// ============================================================

/// TcpListen - passive open on a port.
pub unsafe fn tcp_listen(local_port: u16, backlog_max: u32) -> *mut TcpControlBlock {
    if !tcp_find_listener(local_port).is_null() {
        return core::ptr::null_mut();
    }
    let t = tcp_alloc_tcb();
    if t.is_null() {
        return core::ptr::null_mut();
    }
    (*t).local_port = local_port;
    (*t).state = TCP_STATE_LISTEN;
    (*t).backlog_max = backlog_max.max(1);
    t
}

/// TcpConnect - active open (SYN).
pub unsafe fn tcp_connect(
    remote_addr: u32,
    remote_port: u16,
    local_port: u16,
) -> *mut TcpControlBlock {
    let t = tcp_alloc_tcb();
    if t.is_null() {
        return core::ptr::null_mut();
    }
    (*t).remote_addr = remote_addr;
    (*t).remote_port = remote_port;
    (*t).local_port = if local_port == 0 {
        TCP_NEXT_EPHEMERAL.fetch_add(1, core::sync::atomic::Ordering::Relaxed)
    } else {
        local_port
    };
    (*t).local_addr = 0; // filled by ip layer route
    (*t).iss = TCP_NEXT_ISS.fetch_add(0x10000, core::sync::atomic::Ordering::Relaxed);
    (*t).snd_una = (*t).iss;
    (*t).snd_nxt = (*t).iss + 1;
    (*t).state = TCP_STATE_SYN_SENT;
    // SYN consumes one sequence number; arm retransmit.
    tcp_send_segment(t, (*t).iss, TCP_SYN, core::ptr::null(), 0);
    (*t).rtx_active = true;
    (*t).rtx_seq = (*t).iss;
    (*t).rtx_flags = TCP_SYN;
    (*t).rtx_len = 0;
    (*t).rtx_time_ms = TCP_NOW_MS;
    t
}

/// TcpAccept - dequeue an established child from a listener.
pub unsafe fn tcp_accept(listener: *mut TcpControlBlock) -> *mut TcpControlBlock {
    if listener.is_null() || (*listener).state != TCP_STATE_LISTEN {
        return core::ptr::null_mut();
    }
    let child = (*listener).backlog;
    if child.is_null() {
        return core::ptr::null_mut();
    }
    (*listener).backlog = (*child).backlog;
    (*child).backlog = core::ptr::null_mut();
    (*listener).backlog_count -= 1;
    child
}

/// TcpSend - queue bytes into the send stream, push one segment.
pub unsafe fn tcp_send(
    t: *mut TcpControlBlock,
    data: *const u8,
    data_len: usize,
) -> NtStatus {
    if t.is_null() || (data.is_null() && data_len > 0) {
        return STATUS_INVALID_PARAMETER;
    }
    if (*t).state != TCP_STATE_ESTABLISHED && (*t).state != TCP_STATE_CLOSE_WAIT {
        return STATUS_INVALID_PARAMETER;
    }
    if (*t).send_len + data_len > (*t).send_cap {
        return STATUS_BUFFER_OVERFLOW;
    }
    core::ptr::copy_nonoverlapping(data, (*t).send_buf.add((*t).send_len), data_len);
    (*t).send_len += data_len;
    // Push while window allows and nothing outstanding.
    while (*t).send_len > 0 && !(*t).rtx_active {
        let n = (*t).send_len.min(TCP_MSS).min((*t).snd_wnd as usize);
        if n == 0 {
            break;
        }
        tcp_send_segment(t, (*t).snd_nxt, TCP_ACK | TCP_PSH, (*t).send_buf, n);
        // Retain for retransmit.
        core::ptr::copy_nonoverlapping((*t).send_buf, (*t).rtx_buf.as_mut_ptr(), n);
        (*t).rtx_len = n;
        (*t).rtx_seq = (*t).snd_nxt;
        (*t).rtx_flags = TCP_ACK | TCP_PSH;
        (*t).rtx_active = true;
        (*t).rtx_time_ms = TCP_NOW_MS;
        (*t).snd_nxt = (*t).snd_nxt.wrapping_add(n as u32);
        // Slide the send buffer.
        let remain = (*t).send_len - n;
        core::ptr::copy((*t).send_buf.add(n), (*t).send_buf, remain);
        (*t).send_len = remain;
    }
    STATUS_SUCCESS
}

/// TcpRecv - read bytes from the receive stream.
pub unsafe fn tcp_recv(
    t: *mut TcpControlBlock,
    buffer: *mut u8,
    buffer_len: usize,
    actual_out: *mut usize,
) -> NtStatus {
    if t.is_null() || buffer.is_null() || actual_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*t).recv_len == 0 {
        *actual_out = 0;
        // Closed + drained = orderly EOF.
        if (*t).state == TCP_STATE_CLOSED
            || (*t).state == TCP_STATE_TIME_WAIT
            || (*t).state == TCP_STATE_LAST_ACK
        {
            return STATUS_SUCCESS;
        }
        return STATUS_NO_MORE_ENTRIES;
    }
    let n = (*t).recv_len.min(buffer_len);
    core::ptr::copy_nonoverlapping((*t).recv_buf, buffer, n);
    let remain = (*t).recv_len - n;
    core::ptr::copy((*t).recv_buf.add(n), (*t).recv_buf, remain);
    (*t).recv_len = remain;
    (*t).rcv_wnd = ((*t).recv_cap - (*t).recv_len) as u32;
    *actual_out = n;
    STATUS_SUCCESS
}

/// TcpClose - FIN exchange initiation.
pub unsafe fn tcp_close(t: *mut TcpControlBlock) -> NtStatus {
    if t.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    match (*t).state {
        TCP_STATE_ESTABLISHED => {
            // Flush then FIN.
            tcp_send_segment(t, (*t).snd_nxt, TCP_FIN | TCP_ACK, core::ptr::null(), 0);
            (*t).snd_nxt = (*t).snd_nxt.wrapping_add(1);
            (*t).state = TCP_STATE_FIN_WAIT_1;
            STATUS_SUCCESS
        }
        TCP_STATE_CLOSE_WAIT => {
            tcp_send_segment(t, (*t).snd_nxt, TCP_FIN | TCP_ACK, core::ptr::null(), 0);
            (*t).snd_nxt = (*t).snd_nxt.wrapping_add(1);
            (*t).state = TCP_STATE_LAST_ACK;
            STATUS_SUCCESS
        }
        TCP_STATE_LISTEN | TCP_STATE_SYN_SENT => {
            tcp_free_tcb(t);
            STATUS_SUCCESS
        }
        _ => STATUS_SUCCESS,
    }
}

pub unsafe fn tcp_abort(t: *mut TcpControlBlock) {
    if t.is_null() {
        return;
    }
    if (*t).state != TCP_STATE_CLOSED && (*t).state != TCP_STATE_LISTEN {
        tcp_send_segment(t, (*t).snd_nxt, TCP_RST | TCP_ACK, core::ptr::null(), 0);
    }
    // Unlink from listener backlog if present.
    if !(*t).parent_listener.is_null() {
        let l = (*t).parent_listener;
        let mut prev: *mut TcpControlBlock = core::ptr::null_mut();
        let mut cur = (*l).backlog;
        while !cur.is_null() {
            if cur == t {
                if prev.is_null() {
                    (*l).backlog = (*cur).backlog;
                } else {
                    (*prev).backlog = (*cur).backlog;
                }
                (*l).backlog_count -= 1;
                break;
            }
            prev = cur;
            cur = (*cur).backlog;
        }
    }
    tcp_free_tcb(t);
}

// ============================================================
// RX path
// ============================================================

/// TcpReceive - process an inbound TCP segment.
pub unsafe fn tcp_receive(src: u32, _dst: u32, payload: *const u8, len: usize) {
    if len < 20 || payload.is_null() {
        return;
    }
    let src_port = net_ntohs(*(payload as *const u16));
    let dst_port = net_ntohs(*(payload.add(2) as *const u16));
    let seq = net_ntohl(*(payload.add(4) as *const u32));
    let ack = net_ntohl(*(payload.add(8) as *const u32));
    let data_off = ((*(payload.add(12)) >> 4) as usize) * 4;
    let flags = *(payload.add(13));
    let _window = net_ntohs(*(payload.add(14) as *const u16));
    if data_off < 20 || data_off > len {
        return;
    }
    // Checksum verify.
    let total = len;
    let ckbuf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(12 + total)
        as *mut u8;
    if ckbuf.is_null() {
        return;
    }
    let local = super::ip::ip_address_for_adapter(super::ip::ip_adapter_for_address(src));
    core::ptr::copy_nonoverlapping(src.to_be_bytes().as_ptr(), ckbuf, 4);
    core::ptr::copy_nonoverlapping(local.to_be_bytes().as_ptr(), ckbuf.add(4), 4);
    *ckbuf.add(8) = 0;
    *ckbuf.add(9) = IP_PROTO_TCP;
    *ckbuf.add(10) = ((total >> 8) & 0xFF) as u8;
    *ckbuf.add(11) = (total & 0xFF) as u8;
    core::ptr::copy_nonoverlapping(payload, ckbuf.add(12), total);
    let ck_slice = core::slice::from_raw_parts(ckbuf, 12 + total);
    let cks_ok = net_checksum(ck_slice) == 0;
    crate::mm::pool::ex_free_pool(ckbuf as *mut c_void);
    if !cks_ok {
        return;
    }
    let seg_data = payload.add(data_off);
    let seg_len = len - data_off;

    // RST: kill the connection immediately.
    if flags & TCP_RST != 0 {
        let t = tcp_find_tcb(dst_port, src, src_port);
        if !t.is_null() {
            tcp_abort(t);
        }
        return;
    }

    // Listener: SYN -> create child in SYN_RCVD, SYN+ACK.
    if flags & TCP_SYN != 0 {
        let listener = tcp_find_listener(dst_port);
        if !listener.is_null() && tcp_find_tcb(dst_port, src, src_port).is_null() {
            tcp_accept_syn(listener, src, src_port, seq);
            return;
        }
    }

    let t = tcp_find_tcb(dst_port, src, src_port);
    if t.is_null() {
        // No TCB: RST back (unless RST itself).
        return;
    }

    // ACK processing: advance snd_una, clear retransmit.
    if flags & TCP_ACK != 0 {
        // Accept ACKs in [snd_una, snd_nxt].
        let forward = ack.wrapping_sub((*t).snd_una);
        let outstanding = (*t).snd_nxt.wrapping_sub((*t).snd_una);
        if forward <= outstanding {
            (*t).snd_una = ack;
            if (*t).rtx_active && ack.wrapping_sub((*t).rtx_seq) as usize >= (*t).rtx_len {
                (*t).rtx_active = false;
            }
        }
    }

    match (*t).state {
        TCP_STATE_SYN_SENT => {
            if flags & TCP_SYN != 0 && flags & TCP_ACK != 0 {
                (*t).irs = seq;
                (*t).rcv_nxt = seq.wrapping_add(1);
                (*t).snd_una = ack;
                (*t).rtx_active = false;
                (*t).state = TCP_STATE_ESTABLISHED;
                tcp_send_empty(t, TCP_ACK);
            }
        }
        TCP_STATE_SYN_RCVD => {
            if flags & TCP_ACK != 0 {
                (*t).state = TCP_STATE_ESTABLISHED;
                (*t).rtx_active = false;
            }
        }
        TCP_STATE_ESTABLISHED | TCP_STATE_FIN_WAIT_1 | TCP_STATE_FIN_WAIT_2
        | TCP_STATE_CLOSE_WAIT => {
            // In-order data?
            if seg_len > 0 && seq == (*t).rcv_nxt {
                let space = (*t).recv_cap - (*t).recv_len;
                let n = seg_len.min(space);
                if n > 0 {
                    core::ptr::copy_nonoverlapping(
                        seg_data,
                        (*t).recv_buf.add((*t).recv_len),
                        n,
                    );
                    (*t).recv_len += n;
                    (*t).rcv_nxt = (*t).rcv_nxt.wrapping_add(n as u32);
                    (*t).rcv_wnd = ((*t).recv_cap - (*t).recv_len) as u32;
                }
                tcp_send_empty(t, TCP_ACK);
            } else if seg_len == 0 && seq == (*t).rcv_nxt {
                // Pure ACK already handled above.
            }
            // FIN?
            if flags & TCP_FIN != 0 {
                (*t).rcv_nxt = (*t).rcv_nxt.wrapping_add(1);
                tcp_send_empty(t, TCP_ACK);
                match (*t).state {
                    TCP_STATE_ESTABLISHED => (*t).state = TCP_STATE_CLOSE_WAIT,
                    TCP_STATE_FIN_WAIT_1 => (*t).state = TCP_STATE_FIN_WAIT_2,
                    TCP_STATE_FIN_WAIT_2 => {
                        (*t).state = TCP_STATE_TIME_WAIT;
                        (*t).time_wait_until_ms = TCP_NOW_MS + TCP_TIME_WAIT_MS;
                    }
                    _ => {}
                }
            }
            // Our FIN acked?
            if (*t).state == TCP_STATE_FIN_WAIT_1 && flags & TCP_ACK != 0 {
                // If the ACK covers our FIN (snd_nxt-1), move on.
                if ack == (*t).snd_nxt {
                    (*t).state = TCP_STATE_FIN_WAIT_2;
                }
            }
            if (*t).state == TCP_STATE_LAST_ACK && flags & TCP_ACK != 0 {
                if ack == (*t).snd_nxt {
                    (*t).state = TCP_STATE_CLOSED;
                    tcp_free_tcb(t);
                    return;
                }
            }
        }
        _ => {}
    }
}

unsafe fn tcp_accept_syn(
    listener: *mut TcpControlBlock,
    src: u32,
    src_port: u16,
    seq: u32,
) {
    if (*listener).backlog_count >= (*listener).backlog_max {
        return; // drop; client retransmits SYN
    }
    let t = tcp_alloc_tcb();
    if t.is_null() {
        return;
    }
    (*t).local_addr = (*listener).local_addr;
    (*t).local_port = (*listener).local_port;
    (*t).remote_addr = src;
    (*t).remote_port = src_port;
    (*t).irs = seq;
    (*t).rcv_nxt = seq.wrapping_add(1);
    (*t).iss = TCP_NEXT_ISS.fetch_add(0x10000, core::sync::atomic::Ordering::Relaxed);
    (*t).snd_una = (*t).iss;
    (*t).snd_nxt = (*t).iss + 1;
    (*t).state = TCP_STATE_SYN_RCVD;
    (*t).parent_listener = listener;
    // SYN+ACK + arm retransmit.
    tcp_send_segment(t, (*t).iss, TCP_SYN | TCP_ACK, core::ptr::null(), 0);
    (*t).rtx_active = true;
    (*t).rtx_seq = (*t).iss;
    (*t).rtx_flags = TCP_SYN | TCP_ACK;
    (*t).rtx_len = 0;
    (*t).rtx_time_ms = TCP_NOW_MS;
    // Queue on backlog (accept completes on final ACK -> ESTABLISHED).
    (*t).backlog = (*listener).backlog;
    (*listener).backlog = t;
    (*listener).backlog_count += 1;
}

pub unsafe fn tcp_state(t: *mut TcpControlBlock) -> u8 {
    if t.is_null() {
        return TCP_STATE_CLOSED;
    }
    (*t).state
}

pub unsafe fn tcp_recv_available(t: *mut TcpControlBlock) -> usize {
    if t.is_null() {
        return 0;
    }
    (*t).recv_len
}
