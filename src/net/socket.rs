/// Sockets (tcpip.sys / ws2_32 edge): BSD-style socket objects
///
/// SOCK_STREAM over TCP TCBs, SOCK_DGRAM over UDP sockets, raw
/// ICMP ping handles. This is the kernel side of the Winsock path.

use core::ffi::c_void;

use crate::types::*;

pub const AF_INET: u32 = 2;
pub const SOCK_STREAM: u32 = 1;
pub const SOCK_DGRAM: u32 = 2;
pub const SOCK_RAW: u32 = 3;
pub const IPPROTO_TCP: u32 = 6;
pub const IPPROTO_UDP: u32 = 17;
pub const IPPROTO_ICMP: u32 = 1;

pub const NET_MAX_SOCKETS: usize = 512;

#[repr(C)]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum NetSocketKind {
    Tcp,
    Udp,
    Icmp,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct NetSocket {
    pub kind: NetSocketKind,
    pub tcp: *mut super::tcp::TcpControlBlock,
    pub udp: *mut super::udp::UdpSocket,
    pub local_port: u16,
    pub nonblocking: bool,
    pub last_error: NtStatus,
    pub in_use: bool,
}

static mut NET_SOCKETS: [NetSocket; NET_MAX_SOCKETS] = [NetSocket {
    kind: NetSocketKind::Tcp,
    tcp: core::ptr::null_mut(),
    udp: core::ptr::null_mut(),
    local_port: 0,
    nonblocking: false,
    last_error: 0,
    in_use: false,
}; NET_MAX_SOCKETS];

/// NetSocketCreate - socket(family, type, protocol).
pub unsafe fn net_socket_create(
    family: u32,
    sock_type: u32,
    _protocol: u32,
) -> *mut NetSocket {
    if family != AF_INET {
        return core::ptr::null_mut();
    }
    let mut i = 0;
    while i < NET_MAX_SOCKETS {
        if !NET_SOCKETS[i].in_use {
            let s = &mut NET_SOCKETS[i] as *mut NetSocket;
            match sock_type {
                SOCK_STREAM => {
                    (*s).kind = NetSocketKind::Tcp;
                    (*s).tcp = core::ptr::null_mut();
                }
                SOCK_DGRAM => {
                    (*s).kind = NetSocketKind::Udp;
                    let u = super::udp::udp_create(0);
                    if u.is_null() {
                        return core::ptr::null_mut();
                    }
                    (*s).udp = u;
                    (*s).local_port = (*u).local_port;
                }
                SOCK_RAW => {
                    (*s).kind = NetSocketKind::Icmp;
                }
                _ => return core::ptr::null_mut(),
            }
            (*s).in_use = true;
            (*s).last_error = STATUS_SUCCESS;
            return s;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

pub unsafe fn net_socket_close(sock: *mut NetSocket) {
    if sock.is_null() || !(*sock).in_use {
        return;
    }
    match (*sock).kind {
        NetSocketKind::Tcp => {
            if !(*sock).tcp.is_null() {
                super::tcp::tcp_close((*sock).tcp);
            }
        }
        NetSocketKind::Udp => {
            super::udp::udp_close((*sock).udp);
        }
        NetSocketKind::Icmp => {}
    }
    (*sock).in_use = false;
    (*sock).tcp = core::ptr::null_mut();
    (*sock).udp = core::ptr::null_mut();
}

/// NetSocketBind - bind local port (TCP listen or UDP bind).
pub unsafe fn net_socket_bind(
    sock: *mut NetSocket,
    port: u16,
    backlog: u32,
) -> NtStatus {
    if sock.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    match (*sock).kind {
        NetSocketKind::Tcp => {
            let t = super::tcp::tcp_listen(port, backlog.max(1));
            if t.is_null() {
                return STATUS_ADDRESS_ALREADY_EXISTS;
            }
            (*sock).tcp = t;
            (*sock).local_port = port;
            STATUS_SUCCESS
        }
        NetSocketKind::Udp => {
            super::udp::udp_close((*sock).udp);
            let u = super::udp::udp_create(port);
            if u.is_null() {
                return STATUS_ADDRESS_ALREADY_EXISTS;
            }
            (*sock).udp = u;
            (*sock).local_port = port;
            STATUS_SUCCESS
        }
        NetSocketKind::Icmp => STATUS_SUCCESS,
    }
}

/// NetSocketConnect - TCP active open / UDP connect / ping target.
pub unsafe fn net_socket_connect(
    sock: *mut NetSocket,
    addr: u32,
    port: u16,
) -> NtStatus {
    if sock.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    match (*sock).kind {
        NetSocketKind::Tcp => {
            let t = super::tcp::tcp_connect(addr, port, (*sock).local_port);
            if t.is_null() {
                return STATUS_NO_MEMORY;
            }
            (*sock).tcp = t;
            STATUS_SUCCESS
        }
        NetSocketKind::Udp => super::udp::udp_connect((*sock).udp, addr, port),
        NetSocketKind::Icmp => {
            let ident = super::icmp::icmp_alloc_ident();
            super::icmp::icmp_send_echo(addr, ident, 1, core::ptr::null(), 0)
        }
    }
}

/// NetSocketAccept - accept a TCP child into a new socket.
pub unsafe fn net_socket_accept(listener: *mut NetSocket) -> *mut NetSocket {
    if listener.is_null() || (*listener).kind != NetSocketKind::Tcp {
        return core::ptr::null_mut();
    }
    let child = super::tcp::tcp_accept((*listener).tcp);
    if child.is_null() {
        return core::ptr::null_mut();
    }
    let mut i = 0;
    while i < NET_MAX_SOCKETS {
        if !NET_SOCKETS[i].in_use {
            NET_SOCKETS[i].kind = NetSocketKind::Tcp;
            NET_SOCKETS[i].tcp = child;
            NET_SOCKETS[i].local_port = (*listener).local_port;
            NET_SOCKETS[i].in_use = true;
            return &mut NET_SOCKETS[i] as *mut NetSocket;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

/// NetSocketSend - stream/datagram send.
pub unsafe fn net_socket_send(
    sock: *mut NetSocket,
    data: *const u8,
    data_len: usize,
    addr: u32,
    port: u16,
    sent_out: *mut usize,
) -> NtStatus {
    if sock.is_null() || sent_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    *sent_out = 0;
    match (*sock).kind {
        NetSocketKind::Tcp => {
            let st = super::tcp::tcp_send((*sock).tcp, data, data_len);
            if st == STATUS_SUCCESS {
                *sent_out = data_len;
            }
            st
        }
        NetSocketKind::Udp => {
            let st = super::udp::udp_send((*sock).udp, addr, port, data, data_len);
            if st == STATUS_SUCCESS {
                *sent_out = data_len;
            }
            st
        }
        NetSocketKind::Icmp => STATUS_INVALID_PARAMETER,
    }
}

/// NetSocketRecv - stream/datagram receive.
pub unsafe fn net_socket_recv(
    sock: *mut NetSocket,
    buffer: *mut u8,
    buffer_len: usize,
    actual_out: *mut usize,
    src_addr_out: *mut u32,
    src_port_out: *mut u16,
) -> NtStatus {
    if sock.is_null() || actual_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    *actual_out = 0;
    match (*sock).kind {
        NetSocketKind::Tcp => {
            super::tcp::tcp_recv((*sock).tcp, buffer, buffer_len, actual_out)
        }
        NetSocketKind::Udp => {
            super::udp::udp_recvfrom(
                (*sock).udp,
                buffer,
                buffer_len,
                actual_out,
                src_addr_out,
                src_port_out,
            )
        }
        NetSocketKind::Icmp => STATUS_INVALID_PARAMETER,
    }
}

pub unsafe fn net_socket_state(sock: *mut NetSocket) -> u8 {
    if sock.is_null() {
        return super::tcp::TCP_STATE_CLOSED;
    }
    match (*sock).kind {
        NetSocketKind::Tcp => super::tcp::tcp_state((*sock).tcp),
        _ => super::tcp::TCP_STATE_ESTABLISHED,
    }
}

// Local status codes.
pub const STATUS_ADDRESS_ALREADY_EXISTS: NtStatus = 0xC000020A;
