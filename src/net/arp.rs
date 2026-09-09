/// ARP (tcpip.sys): cache, requests, replies, pending queue
///
/// Ethernet/IPv4 address resolution with a fixed cache, request
/// retransmission counting, and a pending-packet hook (callers get
/// STATUS_PENDING_NB and retry after the reply arrives).

use core::ffi::c_void;

use crate::types::*;
use super::{
    ndis, EthAddr, ETH_ADDR_LEN, net_htons, net_ntohs, net_ntohl, net_htonl,
};

pub const ARP_HW_ETHERNET: u16 = 1;
pub const ARP_PROTO_IPV4: u16 = 0x0800;
pub const ARP_OP_REQUEST: u16 = 1;
pub const ARP_OP_REPLY: u16 = 2;

pub const ARP_CACHE_SIZE: usize = 64;
pub const ARP_ENTRY_TIMEOUT_S: u64 = 300;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct ArpEntry {
    pub ip: u32,
    pub mac: EthAddr,
    pub adapter_handle: u64,
    pub timestamp_s: u64,
    pub valid: bool,
    pub pending: bool,
}

static mut ARP_CACHE: [ArpEntry; ARP_CACHE_SIZE] = [ArpEntry {
    ip: 0,
    mac: EthAddr { bytes: [0; ETH_ADDR_LEN] },
    adapter_handle: 0,
    timestamp_s: 0,
    valid: false,
    pending: false,
}; ARP_CACHE_SIZE];
static mut ARP_TICK_S: u64 = 0;

pub unsafe fn arp_initialize() -> NtStatus {
    ARP_TICK_S = 0;
    STATUS_SUCCESS
}

pub unsafe fn arp_tick(seconds: u64) {
    ARP_TICK_S = seconds;
    // Expire old entries.
    let mut i = 0;
    while i < ARP_CACHE_SIZE {
        if ARP_CACHE[i].valid && seconds - ARP_CACHE[i].timestamp_s > ARP_ENTRY_TIMEOUT_S {
            ARP_CACHE[i].valid = false;
        }
        i += 1;
    }
}

unsafe fn arp_lookup(adapter: u64, ip: u32) -> *mut ArpEntry {
    let mut i = 0;
    while i < ARP_CACHE_SIZE {
        if ARP_CACHE[i].valid && ARP_CACHE[i].ip == ip && ARP_CACHE[i].adapter_handle == adapter
        {
            return &mut ARP_CACHE[i] as *mut ArpEntry;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

unsafe fn arp_insert(adapter: u64, ip: u32, mac: &EthAddr) {
    let e = arp_lookup(adapter, ip);
    if !e.is_null() {
        (*e).mac = *mac;
        (*e).timestamp_s = ARP_TICK_S;
        (*e).pending = false;
        return;
    }
    // Evict oldest / first free.
    let mut slot = 0usize;
    let mut oldest = u64::MAX;
    let mut i = 0;
    while i < ARP_CACHE_SIZE {
        if !ARP_CACHE[i].valid {
            slot = i;
            break;
        }
        if ARP_CACHE[i].timestamp_s < oldest {
            oldest = ARP_CACHE[i].timestamp_s;
            slot = i;
        }
        i += 1;
    }
    ARP_CACHE[slot] = ArpEntry {
        ip,
        mac: *mac,
        adapter_handle: adapter,
        timestamp_s: ARP_TICK_S,
        valid: true,
        pending: false,
    };
}

/// ArpResolve - MAC for an IPv4 next hop.
///
/// Returns SUCCESS + mac on hit; on miss sends a request and
/// returns STATUS_PENDING_NB (caller retries / queues).
pub unsafe fn arp_resolve(adapter: u64, ip: u32, mac_out: *mut EthAddr) -> NtStatus {
    if mac_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    // Broadcast / multicast need no resolution.
    if ip == 0xFFFF_FFFF {
        *mac_out = EthAddr::broadcast();
        return STATUS_SUCCESS;
    }
    if (ip >> 24) == 0xE0 {
        let mut m = [0u8; 6];
        m[0] = 0x01;
        m[1] = 0x00;
        m[2] = 0x5E;
        m[3] = ((ip >> 16) & 0x7F) as u8;
        m[4] = ((ip >> 8) & 0xFF) as u8;
        m[5] = (ip & 0xFF) as u8;
        *mac_out = EthAddr { bytes: m };
        return STATUS_SUCCESS;
    }
    let e = arp_lookup(adapter, ip);
    if !e.is_null() && !(*e).pending {
        *mac_out = (*e).mac;
        return STATUS_SUCCESS;
    }
    arp_send_request(adapter, ip);
    super::ip::STATUS_PENDING_NB
}

unsafe fn arp_send_request(adapter: u64, target_ip: u32) {
    let mp = ndis::ndis_find_miniport(adapter);
    if mp.is_null() {
        return;
    }
    // Sender IP = interface address.
    let sender_ip = super::ip::ip_address_for_adapter(adapter);
    let pool = ndis::ndis_allocate_nbl_pool(0x70724120); // 'AR P'
    if pool.is_null() {
        return;
    }
    let nbl = ndis::ndis_allocate_nbl(pool, 14 + 28, 0);
    if nbl.is_null() {
        crate::mm::pool::ex_free_pool(pool as *mut c_void);
        return;
    }
    let nb = (*nbl).first_net_buffer;
    let buf = ndis::ndis_get_data_buffer(nb);
    if buf.is_null() {
        ndis::ndis_free_nbl(nbl);
        crate::mm::pool::ex_free_pool(pool as *mut c_void);
        return;
    }
    let bcast = EthAddr::broadcast();
    core::ptr::copy_nonoverlapping(bcast.bytes.as_ptr(), buf, 6);
    core::ptr::copy_nonoverlapping((*mp).mac_address.bytes.as_ptr(), buf.add(6), 6);
    *(buf.add(12) as *mut u16) = net_htons(super::ETH_TYPE_ARP);
    let a = buf.add(14);
    *(a.add(0) as *mut u16) = net_htons(ARP_HW_ETHERNET);
    *(a.add(2) as *mut u16) = net_htons(ARP_PROTO_IPV4);
    *a.add(4) = 6;
    *a.add(5) = 4;
    *(a.add(6) as *mut u16) = net_htons(ARP_OP_REQUEST);
    core::ptr::copy_nonoverlapping((*mp).mac_address.bytes.as_ptr(), a.add(8), 6);
    *(a.add(14) as *mut u32) = net_htonl(sender_ip);
    core::ptr::write_bytes(a.add(18), 0, 6);
    *(a.add(24) as *mut u32) = net_htonl(target_ip);
    ndis::ndis_send_nbl(adapter, nbl);
    crate::mm::pool::ex_free_pool(pool as *mut c_void);
    crate::net_trace!("ARP who-has {:08X}", target_ip);
}

/// ArpReceive - handle an ARP packet (called from ip layer).
pub unsafe fn arp_receive(data: *const u8, len: usize) {
    if len < 28 {
        return;
    }
    let hw = net_ntohs(*(data as *const u16));
    let proto = net_ntohs(*(data.add(2) as *const u16));
    if hw != ARP_HW_ETHERNET || proto != ARP_PROTO_IPV4 {
        return;
    }
    let op = net_ntohs(*(data.add(6) as *const u16));
    let mut sender_mac = EthAddr { bytes: [0; 6] };
    core::ptr::copy_nonoverlapping(data.add(8), sender_mac.bytes.as_mut_ptr(), 6);
    let sender_ip = net_ntohl(*(data.add(14) as *const u32));
    let target_ip = net_ntohl(*(data.add(24) as *const u32));
    // Learn the sender on every packet (request or reply).
    // Find adapter by matching target... use first interface match.
    let adapter = super::ip::ip_adapter_for_address(target_ip);
    if adapter == 0 {
        return;
    }
    arp_insert(adapter, sender_ip, &sender_mac);
    if op == ARP_OP_REQUEST {
        // Reply if the target is one of our addresses.
        if super::ip::ip_is_local_address(target_ip) {
            arp_send_reply(adapter, &sender_mac, sender_ip, target_ip);
        }
    }
}

unsafe fn arp_send_reply(
    adapter: u64,
    target_mac: &EthAddr,
    target_ip: u32,
    sender_ip: u32,
) {
    let mp = ndis::ndis_find_miniport(adapter);
    if mp.is_null() {
        return;
    }
    let pool = ndis::ndis_allocate_nbl_pool(0x70724120);
    if pool.is_null() {
        return;
    }
    let nbl = ndis::ndis_allocate_nbl(pool, 14 + 28, 0);
    if nbl.is_null() {
        crate::mm::pool::ex_free_pool(pool as *mut c_void);
        return;
    }
    let nb = (*nbl).first_net_buffer;
    let buf = ndis::ndis_get_data_buffer(nb);
    if buf.is_null() {
        ndis::ndis_free_nbl(nbl);
        crate::mm::pool::ex_free_pool(pool as *mut c_void);
        return;
    }
    core::ptr::copy_nonoverlapping(target_mac.bytes.as_ptr(), buf, 6);
    core::ptr::copy_nonoverlapping((*mp).mac_address.bytes.as_ptr(), buf.add(6), 6);
    *(buf.add(12) as *mut u16) = net_htons(super::ETH_TYPE_ARP);
    let a = buf.add(14);
    *(a.add(0) as *mut u16) = net_htons(ARP_HW_ETHERNET);
    *(a.add(2) as *mut u16) = net_htons(ARP_PROTO_IPV4);
    *a.add(4) = 6;
    *a.add(5) = 4;
    *(a.add(6) as *mut u16) = net_htons(ARP_OP_REPLY);
    core::ptr::copy_nonoverlapping((*mp).mac_address.bytes.as_ptr(), a.add(8), 6);
    *(a.add(14) as *mut u32) = net_htonl(sender_ip);
    core::ptr::copy_nonoverlapping(target_mac.bytes.as_ptr(), a.add(18), 6);
    *(a.add(24) as *mut u32) = net_htonl(target_ip);
    ndis::ndis_send_nbl(adapter, nbl);
    crate::mm::pool::ex_free_pool(pool as *mut c_void);
}
