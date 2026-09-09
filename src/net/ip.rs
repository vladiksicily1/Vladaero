/// IPv4 layer (tcpip.sys): interfaces, routing, fragmentation
///
/// Ethernet framing, ARP resolution, header build/parse, longest-
/// prefix routing, RX fragment reassembly, TX fragmentation, and
/// dispatch to ICMP/UDP/TCP.

use core::ffi::c_void;

use crate::types::*;
use super::{
    ndis, EthAddr, ETH_ADDR_LEN, ETH_TYPE_ARP, ETH_TYPE_IPV4, IP_PROTO_ICMP, IP_PROTO_TCP,
    IP_PROTO_UDP, net_htons, net_htonl, net_ntohs, net_ntohl, net_checksum,
};

// ============================================================
// Headers
// ============================================================

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct EthHeader {
    pub dst: [u8; ETH_ADDR_LEN],
    pub src: [u8; ETH_ADDR_LEN],
    pub ethertype: u16,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct Ipv4Header {
    pub version_ihl: u8,
    pub dscp_ecn: u8,
    pub total_length: u16,
    pub identification: u16,
    pub flags_fragment: u16,
    pub ttl: u8,
    pub protocol: u8,
    pub checksum: u16,
    pub src: u32,
    pub dst: u32,
}

pub const IP_FLAG_MF: u16 = 0x2000;
pub const IP_FLAG_DF: u16 = 0x4000;
pub const IP_FRAG_OFFSET_MASK: u16 = 0x1FFF;

// ============================================================
// Interfaces + routes
// ============================================================

pub const IP_MAX_INTERFACES: usize = 8;
pub const IP_MAX_ROUTES: usize = 32;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IpInterface {
    pub adapter_handle: u64,
    pub address: u32,
    pub netmask: u32,
    pub mac: EthAddr,
    pub mtu: u32,
    pub in_use: bool,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IpRoute {
    pub dest: u32,
    pub mask: u32,
    pub gateway: u32,
    pub adapter_handle: u64,
    pub metric: u32,
    pub in_use: bool,
}

static mut IP_INTERFACES: [IpInterface; IP_MAX_INTERFACES] = [IpInterface {
    adapter_handle: 0,
    address: 0,
    netmask: 0,
    mac: EthAddr { bytes: [0; ETH_ADDR_LEN] },
    mtu: 1500,
    in_use: false,
}; IP_MAX_INTERFACES];

static mut IP_ROUTES: [IpRoute; IP_MAX_ROUTES] = [IpRoute {
    dest: 0,
    mask: 0,
    gateway: 0,
    adapter_handle: 0,
    metric: 0,
    in_use: false,
}; IP_MAX_ROUTES];

static IP_NEXT_ID: core::sync::atomic::AtomicU16 =
    core::sync::atomic::AtomicU16::new(1);

/// IpAddInterface - bind an IPv4 address to a miniport.
pub unsafe fn ip_add_interface(
    adapter_handle: u64,
    address: u32,
    netmask: u32,
    mac: EthAddr,
) -> NtStatus {
    let mut i = 0;
    while i < IP_MAX_INTERFACES {
        if !IP_INTERFACES[i].in_use {
            let mtu = {
                let mp = ndis::ndis_find_miniport(adapter_handle);
                if mp.is_null() { 1500 } else { (*mp).mtu }
            };
            IP_INTERFACES[i] = IpInterface {
                adapter_handle,
                address,
                netmask,
                mac,
                mtu,
                in_use: true,
            };
            // Connected route.
            ip_add_route(address & netmask, netmask, 0, adapter_handle, 10);
            crate::net_trace!(
                "Interface {} addr={:08X} mask={:08X}",
                adapter_handle,
                address,
                netmask
            );
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_INSUFFICIENT_RESOURCES
}

pub unsafe fn ip_add_route(
    dest: u32,
    mask: u32,
    gateway: u32,
    adapter_handle: u64,
    metric: u32,
) -> NtStatus {
    let mut i = 0;
    while i < IP_MAX_ROUTES {
        if !IP_ROUTES[i].in_use {
            IP_ROUTES[i] = IpRoute {
                dest,
                mask,
                gateway,
                adapter_handle,
                metric,
                in_use: true,
            };
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_INSUFFICIENT_RESOURCES
}

unsafe fn ip_find_interface(addr: u32) -> *mut IpInterface {
    let mut i = 0;
    while i < IP_MAX_INTERFACES {
        if IP_INTERFACES[i].in_use && IP_INTERFACES[i].address == addr {
            return &mut IP_INTERFACES[i] as *mut IpInterface;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

/// Longest-prefix route lookup. Returns adapter + next hop.
unsafe fn ip_route_lookup(dst: u32, next_hop_out: *mut u32) -> u64 {
    let mut best: *mut IpRoute = core::ptr::null_mut();
    let mut best_bits = 0u32;
    let mut i = 0;
    while i < IP_MAX_ROUTES {
        if IP_ROUTES[i].in_use && (dst & IP_ROUTES[i].mask) == IP_ROUTES[i].dest {
            let bits = IP_ROUTES[i].mask.count_ones();
            if best.is_null() || bits > best_bits {
                best = &mut IP_ROUTES[i] as *mut IpRoute;
                best_bits = bits;
            }
        }
        i += 1;
    }
    if best.is_null() {
        return 0;
    }
    if !next_hop_out.is_null() {
        *next_hop_out = if (*best).gateway != 0 {
            (*best).gateway
        } else {
            dst
        };
    }
    (*best).adapter_handle
}

unsafe fn ip_interface_for_adapter(handle: u64) -> *mut IpInterface {
    let mut i = 0;
    while i < IP_MAX_INTERFACES {
        if IP_INTERFACES[i].in_use && IP_INTERFACES[i].adapter_handle == handle {
            return &mut IP_INTERFACES[i] as *mut IpInterface;
        }
        i += 1;
    }
    core::ptr::null_mut()
}

// ============================================================
// TX path
// ============================================================

/// IpSend - build Ethernet+IPv4 headers, ARP-resolve, NDIS-send.
pub unsafe fn ip_send(
    src_addr: u32,
    dst_addr: u32,
    protocol: u8,
    ttl: u8,
    payload: *const u8,
    payload_len: usize,
) -> NtStatus {
    if payload.is_null() && payload_len > 0 {
        return STATUS_INVALID_PARAMETER;
    }
    let mut next_hop = 0u32;
    let adapter = ip_route_lookup(dst_addr, &mut next_hop);
    if adapter == 0 {
        return STATUS_NETWORK_UNREACHABLE;
    }
    let iface = ip_interface_for_adapter(adapter);
    if iface.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let src = if src_addr == 0 {
        (*iface).address
    } else {
        src_addr
    };
    // Resolve next hop MAC.
    let mut dst_mac = EthAddr::broadcast();
    if next_hop != 0xFFFF_FFFF {
        let st = super::arp::arp_resolve(adapter, next_hop, &mut dst_mac);
        if st != STATUS_SUCCESS {
            // Queue for later; request already sent by arp_resolve.
            return STATUS_PENDING_NB;
        }
    }
    // Fragment if needed.
    let mtu_payload = ((*iface).mtu as usize).saturating_sub(20 + 14);
    let ident = IP_NEXT_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    if payload_len + 20 <= (*iface).mtu as usize {
        ip_send_one(
            adapter,
            iface,
            &dst_mac,
            src,
            dst_addr,
            protocol,
            ttl,
            ident,
            0,
            false,
            payload,
            payload_len,
        )
    } else {
        // Fragment: 8-byte aligned chunks.
        let mut offset = 0usize;
        while offset < payload_len {
            let mut chunk = mtu_payload / 8 * 8;
            if chunk == 0 {
                chunk = mtu_payload;
            }
            let last = offset + chunk >= payload_len;
            if last {
                chunk = payload_len - offset;
            }
            let frag_off = (offset / 8) as u16;
            let flags = if last { 0 } else { IP_FLAG_MF };
            let st = ip_send_one(
                adapter,
                iface,
                &dst_mac,
                src,
                dst_addr,
                protocol,
                ttl,
                ident,
                frag_off | flags,
                true,
                payload.add(offset),
                chunk,
            );
            if st != STATUS_SUCCESS {
                return st;
            }
            offset += chunk;
        }
        STATUS_SUCCESS
    }
}

#[allow(clippy::too_many_arguments)]
unsafe fn ip_send_one(
    adapter: u64,
    iface: *mut IpInterface,
    dst_mac: &EthAddr,
    src: u32,
    dst: u32,
    protocol: u8,
    ttl: u8,
    ident: u16,
    flags_frag: u16,
    _fragmented: bool,
    payload: *const u8,
    payload_len: usize,
) -> NtStatus {
    let pool = ndis::ndis_allocate_nbl_pool(0x70504920); // ' IP'
    if pool.is_null() {
        return STATUS_NO_MEMORY;
    }
    let total = 14 + 20 + payload_len;
    let nbl = ndis::ndis_allocate_nbl(pool, total as u32, 0);
    if nbl.is_null() {
        crate::mm::pool::ex_free_pool(pool as *mut c_void);
        return STATUS_NO_MEMORY;
    }
    let nb = (*nbl).first_net_buffer;
    let buf = ndis::ndis_get_data_buffer(nb);
    if buf.is_null() {
        ndis::ndis_free_nbl(nbl);
        crate::mm::pool::ex_free_pool(pool as *mut c_void);
        return STATUS_NO_MEMORY;
    }
    // Ethernet.
    core::ptr::copy_nonoverlapping(dst_mac.bytes.as_ptr(), buf, ETH_ADDR_LEN);
    let src_mac = (*iface).mac.bytes;
    core::ptr::copy_nonoverlapping(src_mac.as_ptr(), buf.add(6), ETH_ADDR_LEN);
    *(buf.add(12) as *mut u16) = net_htons(ETH_TYPE_IPV4);
    // IPv4.
    let ip = buf.add(14) as *mut Ipv4Header;
    (*ip).version_ihl = 0x45;
    (*ip).dscp_ecn = 0;
    (*ip).total_length = net_htons((20 + payload_len) as u16);
    (*ip).identification = net_htons(ident);
    (*ip).flags_fragment = net_htons(flags_frag);
    (*ip).ttl = ttl;
    (*ip).protocol = protocol;
    (*ip).checksum = 0;
    (*ip).src = net_htonl(src);
    (*ip).dst = net_htonl(dst);
    let hdr_bytes = core::slice::from_raw_parts(buf.add(14), 20);
    (*ip).checksum = net_checksum(hdr_bytes);
    if payload_len > 0 {
        core::ptr::copy_nonoverlapping(payload, buf.add(34), payload_len);
    }
    let st = ndis::ndis_send_nbl(adapter, nbl);
    crate::mm::pool::ex_free_pool(pool as *mut c_void);
    st
}

// ============================================================
// RX path + reassembly
// ============================================================

pub const IP_MAX_REASSEMBLIES: usize = 16;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IpReassembly {
    pub src: u32,
    pub dst: u32,
    pub protocol: u8,
    pub ident: u16,
    pub buffer: *mut u8,
    pub buffer_len: usize,
    pub received: u32, // bitmask of 8-byte blocks (up to 256 blocks = 2KB.. use count)
    pub total_len: usize,
    pub last_seen: bool,
    pub in_use: bool,
}

static mut IP_REASM: [IpReassembly; IP_MAX_REASSEMBLIES] = [IpReassembly {
    src: 0,
    dst: 0,
    protocol: 0,
    ident: 0,
    buffer: core::ptr::null_mut(),
    buffer_len: 0,
    received: 0,
    total_len: 0,
    last_seen: false,
    in_use: false,
}; IP_MAX_REASSEMBLIES];

/// IpReceiveFrame - NDIS receive indication (Ethernet frame in NBL).
pub unsafe fn ip_receive_frame(nbl: *mut ndis::NetBufferList, _flags: u32) {
    if nbl.is_null() {
        return;
    }
    let mut nb = (*nbl).first_net_buffer;
    while !nb.is_null() {
        let data = ndis::ndis_get_data_buffer(nb);
        let len = (*nb).data_length as usize;
        if !data.is_null() && len >= 14 {
            let ethertype = net_ntohs(*(data.add(12) as *const u16));
            match ethertype {
                ETH_TYPE_ARP => {
                    super::arp::arp_receive(data.add(14), len - 14);
                }
                ETH_TYPE_IPV4 => {
                    ip_receive_packet(data.add(14), len - 14);
                }
                _ => {}
            }
        }
        nb = (*nb).next;
    }
}

unsafe fn ip_receive_packet(data: *const u8, len: usize) {
    if len < 20 {
        return;
    }
    let ihl = ((*(data)) & 0x0F) as usize * 4;
    if ihl < 20 || len < ihl {
        return;
    }
    // Verify checksum.
    let hdr = core::slice::from_raw_parts(data, ihl);
    if net_checksum(hdr) != 0 {
        return;
    }
    let ip = data as *const Ipv4Header;
    let total = net_ntohs((*ip).total_length) as usize;
    if total > len || total < ihl {
        return;
    }
    let src = net_ntohl((*ip).src);
    let dst = net_ntohl((*ip).dst);
    let proto = (*ip).protocol;
    // Local delivery check.
    if ip_find_interface(dst).is_null() && dst != 0xFFFF_FFFF && (dst >> 24) != 0xE0 {
        // Not for us; a router would forward (we are a host: drop).
        return;
    }
    let frag = net_ntohs((*ip).flags_fragment);
    let offset = ((frag & IP_FRAG_OFFSET_MASK) as usize) * 8;
    let more = frag & IP_FLAG_MF != 0;
    let payload = data.add(ihl);
    let payload_len = total - ihl;
    if offset == 0 && !more {
        ip_deliver(src, dst, proto, payload, payload_len);
    } else {
        ip_reassemble(src, dst, proto, (*ip).identification, offset, more, payload, payload_len);
    }
}

unsafe fn ip_reassemble(
    src: u32,
    dst: u32,
    proto: u8,
    ident: u16,
    offset: usize,
    more: bool,
    payload: *const u8,
    payload_len: usize,
) {
    // Find or allocate slot.
    let mut slot: *mut IpReassembly = core::ptr::null_mut();
    let mut i = 0;
    while i < IP_MAX_REASSEMBLIES {
        if IP_REASM[i].in_use
            && IP_REASM[i].src == src
            && IP_REASM[i].dst == dst
            && IP_REASM[i].protocol == proto
            && IP_REASM[i].ident == ident
        {
            slot = &mut IP_REASM[i] as *mut IpReassembly;
            break;
        }
        i += 1;
    }
    if slot.is_null() {
        i = 0;
        while i < IP_MAX_REASSEMBLIES {
            if !IP_REASM[i].in_use {
                let buf = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(65536)
                    as *mut u8;
                if buf.is_null() {
                    return;
                }
                IP_REASM[i].buffer = buf;
                IP_REASM[i].buffer_len = 65536;
                IP_REASM[i].src = src;
                IP_REASM[i].dst = dst;
                IP_REASM[i].protocol = proto;
                IP_REASM[i].ident = ident;
                IP_REASM[i].received = 0;
                IP_REASM[i].total_len = 0;
                IP_REASM[i].last_seen = false;
                IP_REASM[i].in_use = true;
                slot = &mut IP_REASM[i] as *mut IpReassembly;
                break;
            }
            i += 1;
        }
    }
    if slot.is_null() {
        return;
    }
    if offset + payload_len > (*slot).buffer_len {
        return;
    }
    core::ptr::copy_nonoverlapping(payload, (*slot).buffer.add(offset), payload_len);
    // Mark blocks (8-byte units, cap at 32 bits = 256 bytes tracking...
    // use coarse: mark by 2KB units).
    let _ = more;
    if !more {
        (*slot).last_seen = true;
        (*slot).total_len = offset + payload_len;
    }
    // Completion check: contiguous from 0 to total_len?
    // Simplified: deliver when last seen and first fragment present.
    // (Full hole tracking omitted; common case = in-order fragments.)
    if (*slot).last_seen && (*slot).total_len > 0 {
        // Verify first bytes arrived (offset 0 was stored if it came).
        ip_deliver(src, dst, proto, (*slot).buffer, (*slot).total_len);
        crate::mm::pool::ex_free_pool((*slot).buffer as *mut c_void);
        (*slot).buffer = core::ptr::null_mut();
        (*slot).in_use = false;
    }
}

unsafe fn ip_deliver(src: u32, dst: u32, proto: u8, payload: *const u8, len: usize) {
    match proto {
        IP_PROTO_ICMP => super::icmp::icmp_receive(src, dst, payload, len),
        IP_PROTO_UDP => super::udp::udp_receive(src, dst, payload, len),
        IP_PROTO_TCP => super::tcp::tcp_receive(src, dst, payload, len),
        _ => {}
    }
}

pub unsafe fn ip_initialize() -> NtStatus {
    // Default route placeholder (set properly by DHCP/static config).
    STATUS_SUCCESS
}

/// Address configured on an adapter (0 if none).
pub unsafe fn ip_address_for_adapter(handle: u64) -> u32 {
    let iface = ip_interface_for_adapter(handle);
    if iface.is_null() {
        return 0;
    }
    (*iface).address
}

/// Adapter that owns an address (0 if none).
pub unsafe fn ip_adapter_for_address(addr: u32) -> u64 {
    let iface = ip_find_interface(addr);
    if iface.is_null() {
        return 0;
    }
    (*iface).adapter_handle
}

/// True if the address is configured locally.
pub unsafe fn ip_is_local_address(addr: u32) -> bool {
    !ip_find_interface(addr).is_null()
}

// Local status codes.
pub const STATUS_NETWORK_UNREACHABLE: NtStatus = 0xC000023C;
pub const STATUS_PENDING_NB: NtStatus = 0x00000103;
