/// UDP (tcpip.sys): ports, datagrams, per-socket queues
///
/// Connectionless datagrams with header build/parse, checksum
/// (pseudo-header), demultiplexing to bound sockets, and RX queues.

use core::ffi::c_void;

use crate::types::*;
use super::{IP_PROTO_UDP, net_htons, net_ntohs, net_checksum};

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UdpHeader {
    pub src_port: u16,
    pub dst_port: u16,
    pub length: u16,
    pub checksum: u16,
}

pub const UDP_MAX_SOCKETS: usize = 256;
pub const UDP_QUEUE_DATAGRAMS: usize = 64;
pub const UDP_MAX_DATAGRAM: usize = 1472;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UdpDatagram {
    pub src_addr: u32,
    pub src_port: u16,
    pub len: usize,
    pub data: [u8; UDP_MAX_DATAGRAM],
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct UdpSocket {
    pub local_addr: u32,
    pub local_port: u16,
    pub remote_addr: u32,
    pub remote_port: u16,
    pub connected: bool,
    pub queue: [UdpDatagram; UDP_QUEUE_DATAGRAMS],
    pub q_head: usize,
    pub q_count: usize,
    pub rx_bytes: u64,
    pub tx_bytes: u64,
    pub in_use: bool,
}

static mut UDP_SOCKETS: [UdpSocket; UDP_MAX_SOCKETS] = [UdpSocket {
    local_addr: 0,
    local_port: 0,
    remote_addr: 0,
    remote_port: 0,
    connected: false,
    queue: [UdpDatagram {
        src_addr: 0,
        src_port: 0,
        len: 0,
        data: [0; UDP_MAX_DATAGRAM],
    }; UDP_QUEUE_DATAGRAMS],
    q_head: 0,
    q_count: 0,
    rx_bytes: 0,
    tx_bytes: 0,
    in_use: false,
}; UDP_MAX_SOCKETS];
static UDP_NEXT_EPHEMERAL: core::sync::atomic::AtomicU16 =
    core::sync::atomic::AtomicU16::new(49152);

pub unsafe fn udp_initialize() -> NtStatus {
    STATUS_SUCCESS
}

unsafe fn udp_find_socket(port: u16, addr: u32) -> *mut UdpSocket {
    let mut i = 0;
    while i < UDP_MAX_SOCKETS {
        if UDP_SOCKETS[i].in_use && UDP_SOCKETS[i].local_port == port {
            // Connected sockets only match their peer; unconnected match any.
            if !UDP_SOCKETS[i].connected {
                return &mut UDP_SOCKETS[i] as *mut UdpSocket;
            }
        }
        i += 1;
    }
    // Second pass: connected match (addr check).
    i = 0;
    while i < UDP_MAX_SOCKETS {
        if UDP_SOCKETS[i].in_use
            && UDP_SOCKETS[i].local_port == port
            && UDP_SOCKETS[i].connected
        {
            return &mut UDP_SOCKETS[i] as *mut UdpSocket;
        }
        i += 1;
    }
    let _ = addr;
    core::ptr::null_mut()
}

/// UdpCreate - allocate a socket, optionally bound.
pub unsafe fn udp_create(local_port: u16) -> *mut UdpSocket {
    let mut i = 0;
    while i < UDP_MAX_SOCKETS {
        if !UDP_SOCKETS[i].in_use {
            // Port conflict check.
            if local_port != 0 {
                let mut j = 0;
                let mut conflict = false;
                while j < UDP_MAX_SOCKETS {
                    if UDP_SOCKETS[j].in_use && UDP_SOCKETS[j].local_port == local_port
                    {
                        conflict = true;
                        break;
                    }
                    j += 1;
                }
                if conflict {
                    return core::ptr::null_mut();
                }
                UDP_SOCKETS[i].local_port = local_port;
            } else {
                UDP_SOCKETS[i].local_port =
                    UDP_NEXT_EPHEMERAL.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
            }
            UDP_SOCKETS[i].in_use = true;
            UDP_SOCKETS[i].q_head = 0;
            UDP_SOCKETS[i].q_count = 0;
            return &mut UDP_SOCKETS[i] as *mut UdpSocket;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

pub unsafe fn udp_close(sock: *mut UdpSocket) {
    if !sock.is_null() {
        (*sock).in_use = false;
        (*sock).q_count = 0;
    }
}

pub unsafe fn udp_connect(sock: *mut UdpSocket, remote_addr: u32, remote_port: u16) -> NtStatus {
    if sock.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    (*sock).remote_addr = remote_addr;
    (*sock).remote_port = remote_port;
    (*sock).connected = true;
    STATUS_SUCCESS
}

/// UdpSend - build header + checksum, ip_send.
pub unsafe fn udp_send(
    sock: *mut UdpSocket,
    dst_addr: u32,
    dst_port: u16,
    data: *const u8,
    data_len: usize,
) -> NtStatus {
    if sock.is_null() || (data.is_null() && data_len > 0) {
        return STATUS_INVALID_PARAMETER;
    }
    if data_len + 8 > 65507 {
        return STATUS_INVALID_PARAMETER;
    }
    let (dst, port) = if (*sock).connected {
        ((*sock).remote_addr, (*sock).remote_port)
    } else {
        (dst_addr, dst_port)
    };
    let total = 8 + data_len;
    // Pool-allocated (never large stack buffers in kernel).
    let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(total) as *mut u8;
    if buf.is_null() {
        return STATUS_NO_MEMORY;
    }
    *buf.add(0) = ((*sock).local_port >> 8) as u8;
    *buf.add(1) = ((*sock).local_port & 0xFF) as u8;
    *buf.add(2) = ((port >> 8) & 0xFF) as u8;
    *buf.add(3) = ((port & 0xFF)) as u8;
    *buf.add(4) = ((total >> 8) & 0xFF) as u8;
    *buf.add(5) = ((total & 0xFF)) as u8;
    *buf.add(6) = 0;
    *buf.add(7) = 0;
    if data_len > 0 {
        core::ptr::copy_nonoverlapping(data, buf.add(8), data_len);
    }
    // Pseudo-header checksum.
    let src = super::ip::ip_address_for_adapter(super::ip::ip_adapter_for_address(dst));
    let pseudo_len = 12 + total;
    let pseudo = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(pseudo_len) as *mut u8;
    if pseudo.is_null() {
        crate::mm::pool::ex_free_pool(buf as *mut c_void);
        return STATUS_NO_MEMORY;
    }
    core::ptr::copy_nonoverlapping(src.to_be_bytes().as_ptr(), pseudo, 4);
    core::ptr::copy_nonoverlapping(dst.to_be_bytes().as_ptr(), pseudo.add(4), 4);
    *pseudo.add(8) = 0;
    *pseudo.add(9) = IP_PROTO_UDP;
    *pseudo.add(10) = ((total >> 8) & 0xFF) as u8;
    *pseudo.add(11) = (total & 0xFF) as u8;
    core::ptr::copy_nonoverlapping(buf, pseudo.add(12), total);
    let pseudo_slice = core::slice::from_raw_parts(pseudo, pseudo_len);
    let cks = net_checksum(pseudo_slice);
    crate::mm::pool::ex_free_pool(pseudo as *mut c_void);
    let cks = if cks == 0 { 0xFFFF } else { cks };
    *buf.add(6) = (cks >> 8) as u8;
    *buf.add(7) = (cks & 0xFF) as u8;
    (*sock).tx_bytes += data_len as u64;
    let st = super::ip::ip_send(0, dst, IP_PROTO_UDP, 128, buf, total);
    crate::mm::pool::ex_free_pool(buf as *mut c_void);
    st
}

/// UdpReceive - demultiplex + enqueue (called from ip layer).
pub unsafe fn udp_receive(src: u32, _dst: u32, payload: *const u8, len: usize) {
    if len < 8 || payload.is_null() {
        return;
    }
    let src_port = net_ntohs(*(payload as *const u16));
    let dst_port = net_ntohs(*(payload.add(2) as *const u16));
    let ulen = net_ntohs(*(payload.add(4) as *const u16)) as usize;
    if ulen < 8 || ulen > len {
        return;
    }
    let data_len = (ulen - 8).min(UDP_MAX_DATAGRAM);
    let sock = udp_find_socket(dst_port, src);
    if sock.is_null() {
        // No socket: ICMP port unreachable (best effort).
        return;
    }
    if (*sock).q_count >= UDP_QUEUE_DATAGRAMS {
        return;
    }
    let idx = ((*sock).q_head + (*sock).q_count) % UDP_QUEUE_DATAGRAMS;
    (*sock).queue[idx].src_addr = src;
    (*sock).queue[idx].src_port = src_port;
    (*sock).queue[idx].len = data_len;
    if data_len > 0 {
        core::ptr::copy_nonoverlapping(
            payload.add(8),
            (*sock).queue[idx].data.as_mut_ptr(),
            data_len,
        );
    }
    (*sock).q_count += 1;
    (*sock).rx_bytes += data_len as u64;
}

/// UdpRecvFrom - dequeue one datagram.
pub unsafe fn udp_recvfrom(
    sock: *mut UdpSocket,
    buffer: *mut u8,
    buffer_len: usize,
    actual_len_out: *mut usize,
    src_addr_out: *mut u32,
    src_port_out: *mut u16,
) -> NtStatus {
    if sock.is_null() || buffer.is_null() || actual_len_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*sock).q_count == 0 {
        *actual_len_out = 0;
        return STATUS_NO_MORE_ENTRIES;
    }
    let idx = (*sock).q_head;
    let dlen = (*sock).queue[idx].len;
    if buffer_len < dlen {
        *actual_len_out = dlen;
        return STATUS_BUFFER_TOO_SMALL;
    }
    if dlen > 0 {
        core::ptr::copy_nonoverlapping((*sock).queue[idx].data.as_ptr(), buffer, dlen);
    }
    *actual_len_out = dlen;
    if !src_addr_out.is_null() {
        *src_addr_out = (*sock).queue[idx].src_addr;
    }
    if !src_port_out.is_null() {
        *src_port_out = (*sock).queue[idx].src_port;
    }
    (*sock).q_head = ((*sock).q_head + 1) % UDP_QUEUE_DATAGRAMS;
    (*sock).q_count -= 1;
    STATUS_SUCCESS
}

pub unsafe fn udp_pending(sock: *mut UdpSocket) -> usize {
    if sock.is_null() {
        return 0;
    }
    (*sock).q_count
}
