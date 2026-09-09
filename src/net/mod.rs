/// Net - kernel network stack (netio.sys + tcpip.sys + ndis.sys)
///
/// NDIS 6 miniport framework with NET_BUFFER/NET_BUFFER_LIST data
/// packaging, a protocol edge (TCPIP), ARP/IPv4/ICMP/UDP/TCP, and a
/// loopback miniport. Ownership follows NDIS send/receive semantics.
///
/// References:
///   - MS Learn: NDIS Driver Stack, NET_BUFFER Architecture
///   - Windows Internals 7th Ed. Part 2, Chapter 10 (Networking)

use core::ffi::c_void;

use crate::types::*;

pub mod ndis;
pub mod arp;
pub mod ip;
pub mod icmp;
pub mod udp;
pub mod tcp;
pub mod socket;

#[macro_export]
macro_rules! net_trace {
    ($($arg:tt)*) => {
        crate::kernel_log!("[Net] {}", format_args!($($arg)*));
    };
}

// ============================================================
// Ethernet + IPv4 constants
// ============================================================

pub const ETH_ADDR_LEN: usize = 6;
pub const ETH_TYPE_ARP: u16 = 0x0806;
pub const ETH_TYPE_IPV4: u16 = 0x0800;
pub const ETH_TYPE_IPV6: u16 = 0x86DD;

pub const IP_PROTO_ICMP: u8 = 1;
pub const IP_PROTO_TCP: u8 = 6;
pub const IP_PROTO_UDP: u8 = 17;

pub const IPV4_LOOPBACK: u32 = 0x7F00_0001; // 127.0.0.1 (host order)

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct EthAddr {
    pub bytes: [u8; ETH_ADDR_LEN],
}

impl EthAddr {
    pub const fn broadcast() -> Self {
        Self { bytes: [0xFF; ETH_ADDR_LEN] }
    }
    pub fn is_broadcast(&self) -> bool {
        self.bytes == [0xFF; ETH_ADDR_LEN]
    }
    pub fn is_multicast(&self) -> bool {
        self.bytes[0] & 0x01 != 0
    }
}

pub fn net_htons(v: u16) -> u16 {
    v.swap_bytes()
}
pub fn net_htonl(v: u32) -> u32 {
    v.swap_bytes()
}
pub fn net_ntohs(v: u16) -> u16 {
    v.swap_bytes()
}
pub fn net_ntohl(v: u32) -> u32 {
    v.swap_bytes()
}

/// Internet checksum (RFC 1071) over a byte buffer.
pub fn net_checksum(data: &[u8]) -> u16 {
    let mut sum = 0u32;
    let mut i = 0;
    while i + 1 < data.len() {
        sum += ((data[i] as u32) << 8) | (data[i + 1] as u32);
        i += 2;
    }
    if i < data.len() {
        sum += (data[i] as u32) << 8;
    }
    while (sum >> 16) != 0 {
        sum = (sum & 0xFFFF) + (sum >> 16);
    }
    !(sum as u16)
}

// ============================================================
// Stack init
// ============================================================

static mut NET_INITIALIZED: bool = false;

pub unsafe fn net_initialize() -> NtStatus {
    if NET_INITIALIZED {
        return STATUS_SUCCESS;
    }
    let mut st = ndis::ndis_initialize();
    if st != STATUS_SUCCESS {
        return st;
    }
    st = arp::arp_initialize();
    if st != STATUS_SUCCESS {
        return st;
    }
    st = ip::ip_initialize();
    if st != STATUS_SUCCESS {
        return st;
    }
    st = udp::udp_initialize();
    if st != STATUS_SUCCESS {
        return st;
    }
    st = tcp::tcp_initialize();
    if st != STATUS_SUCCESS {
        return st;
    }
    // Loopback miniport so 127.0.0.1 works with no NIC.
    st = ndis::ndis_register_loopback();
    if st != STATUS_SUCCESS {
        return st;
    }
    NET_INITIALIZED = true;
    net_trace!("Network stack initialized (NDIS+TCPIP+loopback)");
    STATUS_SUCCESS
}
