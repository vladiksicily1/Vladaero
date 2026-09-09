/// NDIS 6 miniport framework (ndis.sys)
///
/// NET_BUFFER / NET_BUFFER_LIST packaging, NB pools, miniport
/// registration, send path with ownership transfer, receive
/// indications with RESOURCES semantics, and a loopback miniport.

use core::ffi::c_void;

use crate::types::*;
use super::{EthAddr, ETH_ADDR_LEN};

// ============================================================
// NET_BUFFER / NET_BUFFER_LIST (ndis/nbl.h subset)
// ============================================================

pub const NDIS_RECEIVE_FLAGS_RESOURCES: u32 = 0x00000001;
pub const NDIS_SEND_FLAGS_CHECK_FOR_LOOPBACK: u32 = 0x00000001;

pub const NBL_FLAGS_MINIPORT_RESERVED: u32 = 0x00000F00;

pub const MAX_NET_BUFFER_LIST_INFO: usize = 16;

#[repr(C)]
pub struct NetBufferMdl {
    pub next: *mut NetBufferMdl,
    pub byte_count: u32,
    pub data: *mut u8,
}

#[repr(C)]
pub struct NetBuffer {
    pub next: *mut NetBuffer,
    pub mdl_chain: *mut NetBufferMdl,
    pub data_length: u32,
    pub data_offset: u32,
    pub protocol_reserved: [*mut c_void; 4],
    pub miniport_reserved: [*mut c_void; 2],
}

#[repr(C)]
pub struct NetBufferList {
    pub next: *mut NetBufferList,
    pub first_net_buffer: *mut NetBuffer,
    pub context: *mut c_void,
    pub parent: *mut NetBufferList,
    pub pool_handle: u64,
    pub ndis_reserved: [*mut c_void; 2],
    pub protocol_reserved: [*mut c_void; 4],
    pub miniport_reserved: [*mut c_void; 2],
    pub source_handle: u64,
    pub nbl_flags: u32,
    pub child_ref_count: i32,
    pub status: NtStatus,
    pub info: [u64; MAX_NET_BUFFER_LIST_INFO],
}

#[repr(C)]
pub struct NdisPool {
    pub pool_id: u64,
    pub tag: u32,
    pub nbl_count: u32,
}

static NDIS_NEXT_POOL_ID: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(1);

/// NdisAllocateNetBufferListPool
pub unsafe fn ndis_allocate_nbl_pool(tag: u32) -> *mut NdisPool {
    let p = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<NdisPool>(),
    ) as *mut NdisPool;
    if p.is_null() {
        return core::ptr::null_mut();
    }
    (*p).pool_id =
        NDIS_NEXT_POOL_ID.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*p).tag = tag;
    (*p).nbl_count = 0;
    p
}

/// NdisAllocateNetBufferList - one NBL + one NB + one MDL + data.
pub unsafe fn ndis_allocate_nbl(
    pool: *mut NdisPool,
    data_len: u32,
    headroom: u32,
) -> *mut NetBufferList {
    if pool.is_null() {
        return core::ptr::null_mut();
    }
    let nbl = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<NetBufferList>(),
    ) as *mut NetBufferList;
    if nbl.is_null() {
        return core::ptr::null_mut();
    }
    core::ptr::write_bytes(nbl as *mut u8, 0, core::mem::size_of::<NetBufferList>());
    let nb = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<NetBuffer>(),
    ) as *mut NetBuffer;
    if nb.is_null() {
        crate::mm::pool::ex_free_pool(nbl as *mut c_void);
        return core::ptr::null_mut();
    }
    core::ptr::write_bytes(nb as *mut u8, 0, core::mem::size_of::<NetBuffer>());
    let mdl = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<NetBufferMdl>(),
    ) as *mut NetBufferMdl;
    if mdl.is_null() {
        crate::mm::pool::ex_free_pool(nb as *mut c_void);
        crate::mm::pool::ex_free_pool(nbl as *mut c_void);
        return core::ptr::null_mut();
    }
    let total = headroom as usize + data_len as usize;
    let data = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(total.max(1)) as *mut u8;
    if data.is_null() {
        crate::mm::pool::ex_free_pool(mdl as *mut c_void);
        crate::mm::pool::ex_free_pool(nb as *mut c_void);
        crate::mm::pool::ex_free_pool(nbl as *mut c_void);
        return core::ptr::null_mut();
    }
    (*mdl).next = core::ptr::null_mut();
    (*mdl).byte_count = total as u32;
    (*mdl).data = data;
    (*nb).mdl_chain = mdl;
    (*nb).data_length = data_len;
    (*nb).data_offset = headroom;
    (*nbl).first_net_buffer = nb;
    (*nbl).pool_handle = (*pool).pool_id;
    (*pool).nbl_count += 1;
    nbl
}

/// NdisFreeNetBufferList - release NBL + NB + MDL + data.
pub unsafe fn ndis_free_nbl(nbl: *mut NetBufferList) {
    if nbl.is_null() {
        return;
    }
    let mut nb = (*nbl).first_net_buffer;
    while !nb.is_null() {
        let next_nb = (*nb).next;
        let mut mdl = (*nb).mdl_chain;
        while !mdl.is_null() {
            let next_mdl = (*mdl).next;
            if !(*mdl).data.is_null() {
                crate::mm::pool::ex_free_pool((*mdl).data as *mut c_void);
            }
            crate::mm::pool::ex_free_pool(mdl as *mut c_void);
            mdl = next_mdl;
        }
        crate::mm::pool::ex_free_pool(nb as *mut c_void);
        nb = next_nb;
    }
    crate::mm::pool::ex_free_pool(nbl as *mut c_void);
}

/// NdisGetDataBuffer - contiguous data pointer for an NB.
pub unsafe fn ndis_get_data_buffer(nb: *mut NetBuffer) -> *mut u8 {
    if nb.is_null() {
        return core::ptr::null_mut();
    }
    let mdl = (*nb).mdl_chain;
    if mdl.is_null() || (*mdl).data.is_null() {
        return core::ptr::null_mut();
    }
    (*mdl).data.add((*nb).data_offset as usize)
}

/// NdisRetreatNetBufferDataStart - prepend header space.
pub unsafe fn ndis_retreat_data_start(
    nb: *mut NetBuffer,
    bytes: u32,
) -> NtStatus {
    if nb.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    if (*nb).data_offset < bytes {
        return STATUS_BUFFER_TOO_SMALL;
    }
    (*nb).data_offset -= bytes;
    (*nb).data_length += bytes;
    STATUS_SUCCESS
}

/// NdisAdvanceNetBufferDataStart - strip headers.
pub unsafe fn ndis_advance_data_start(nb: *mut NetBuffer, bytes: u32) {
    if nb.is_null() {
        return;
    }
    let take = bytes.min((*nb).data_length);
    (*nb).data_offset += take;
    (*nb).data_length -= take;
}

// ============================================================
// Miniports
// ============================================================

pub const NDIS_MINIPORT_STATE_HALTED: u32 = 0;
pub const NDIS_MINIPORT_STATE_INITIALIZED: u32 = 1;
pub const NDIS_MINIPORT_STATE_RUNNING: u32 = 2;
pub const NDIS_MINIPORT_STATE_PAUSED: u32 = 3;

pub const NDIS_MEDIA_ETHERNET: u32 = 0;
pub const NDIS_MEDIA_LOOPBACK: u32 = 1;

#[repr(C)]
pub struct NdisMiniport {
    pub adapter_handle: u64,
    pub driver_name: [u16; 64],
    pub mac_address: EthAddr,
    pub media_type: u32,
    pub mtu: u32,
    pub link_speed_mbps: u64,
    pub state: u32,
    pub tx_packets: u64,
    pub rx_packets: u64,
    pub tx_bytes: u64,
    pub rx_bytes: u64,
    pub is_loopback: bool,
    pub next: *mut NdisMiniport,
}

static mut NDIS_MINIPORT_LIST: *mut NdisMiniport = core::ptr::null_mut();
static NDIS_NEXT_ADAPTER_HANDLE: core::sync::atomic::AtomicU64 =
    core::sync::atomic::AtomicU64::new(1);

/// NdisMRegisterMiniport - MiniportInitializeEx equivalent.
pub unsafe fn ndis_register_miniport(
    driver_name: *const u16,
    mac: *const EthAddr,
    media_type: u32,
    mtu: u32,
    is_loopback: bool,
    handle_out: *mut u64,
) -> NtStatus {
    if handle_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mp = crate::mm::pool::ex_allocate_nonpaged_cache_aligned(
        core::mem::size_of::<NdisMiniport>(),
    ) as *mut NdisMiniport;
    if mp.is_null() {
        return STATUS_NO_MEMORY;
    }
    core::ptr::write_bytes(mp as *mut u8, 0, core::mem::size_of::<NdisMiniport>());
    if !driver_name.is_null() {
        let mut i = 0;
        while i < 63 && *driver_name.add(i) != 0 {
            (*mp).driver_name[i] = *driver_name.add(i);
            i += 1;
        }
    }
    if !mac.is_null() {
        (*mp).mac_address = *mac;
    }
    (*mp).media_type = media_type;
    (*mp).mtu = if mtu == 0 { 1500 } else { mtu };
    (*mp).link_speed_mbps = if is_loopback { 10_000 } else { 1000 };
    (*mp).state = NDIS_MINIPORT_STATE_RUNNING;
    (*mp).is_loopback = is_loopback;
    (*mp).adapter_handle =
        NDIS_NEXT_ADAPTER_HANDLE.fetch_add(1, core::sync::atomic::Ordering::Relaxed);
    (*mp).next = NDIS_MINIPORT_LIST;
    NDIS_MINIPORT_LIST = mp;
    *handle_out = (*mp).adapter_handle;
    crate::net_trace!(
        "Miniport registered h={} mtu={} loopback={}",
        (*mp).adapter_handle,
        (*mp).mtu,
        is_loopback as u8
    );
    STATUS_SUCCESS
}

pub unsafe fn ndis_find_miniport(handle: u64) -> *mut NdisMiniport {
    let mut cur = NDIS_MINIPORT_LIST;
    while !cur.is_null() {
        if (*cur).adapter_handle == handle {
            return cur;
        }
        cur = (*cur).next;
    }
    core::ptr::null_mut()
}

/// NdisMSendNetBufferLists - protocol -> miniport send path.
///
/// Ownership transfers to NDIS; completion is synchronous here
/// (deserialized miniport processes inline, then completes).
pub unsafe fn ndis_send_nbl(
    adapter_handle: u64,
    nbl_chain: *mut NetBufferList,
) -> NtStatus {
    let mp = ndis_find_miniport(adapter_handle);
    if mp.is_null() || (*mp).state != NDIS_MINIPORT_STATE_RUNNING {
        return STATUS_INVALID_PARAMETER;
    }
    let mut nbl = nbl_chain;
    let mut overall = STATUS_SUCCESS;
    while !nbl.is_null() {
        let next = (*nbl).next;
        (*nbl).source_handle = adapter_handle;
        let st = ndis_miniport_send(mp, nbl);
        if st != STATUS_SUCCESS {
            overall = st;
        }
        // Synchronous completion (MiniportSendNetBufferListsComplete).
        ndis_free_nbl(nbl);
        nbl = next;
    }
    overall
}

unsafe fn ndis_miniport_send(mp: *mut NdisMiniport, nbl: *mut NetBufferList) -> NtStatus {
    (*mp).tx_packets += 1;
    let mut nb = (*nbl).first_net_buffer;
    while !nb.is_null() {
        (*mp).tx_bytes += (*nb).data_length as u64;
        if (*mp).is_loopback {
            // Loopback: re-indicate to the protocol edge.
            ndis_loopback_indicate(mp, nb);
        } else {
            // Real NIC: hand to the driver's TX ring (drivers/ layer).
            crate::drivers::net::nic_transmit((*mp).adapter_handle, nb);
        }
        nb = (*nb).next;
    }
    (*nbl).status = STATUS_SUCCESS;
    STATUS_SUCCESS
}

unsafe fn ndis_loopback_indicate(mp: *mut NdisMiniport, nb: *mut NetBuffer) {
    // Build a receive NBL wrapping a copy (RESOURCES semantics:
    // protocol must copy; NDIS reclaims on return).
    let pool = ndis_allocate_nbl_pool(0x626F6F4C); // 'LooB'
    if pool.is_null() {
        return;
    }
    let rx = ndis_allocate_nbl(pool, (*nb).data_length, 0);
    if rx.is_null() {
        crate::mm::pool::ex_free_pool(pool as *mut c_void);
        return;
    }
    let src = ndis_get_data_buffer(nb);
    let dst_nb = (*rx).first_net_buffer;
    let dst = ndis_get_data_buffer(dst_nb);
    if !src.is_null() && !dst.is_null() {
        core::ptr::copy_nonoverlapping(src, dst, (*nb).data_length as usize);
    }
    (*rx).source_handle = (*mp).adapter_handle;
    (*mp).rx_packets += 1;
    (*mp).rx_bytes += (*nb).data_length as u64;
    // Indicate up to TCPIP with RESOURCES flag.
    super::ip::ip_receive_frame(rx, NDIS_RECEIVE_FLAGS_RESOURCES);
    // Reclaim (RESOURCES semantics).
    ndis_free_nbl(rx);
    crate::mm::pool::ex_free_pool(pool as *mut c_void);
}

/// NdisMIndicateReceiveNetBufferLists - miniport -> protocol.
pub unsafe fn ndis_indicate_receive(
    adapter_handle: u64,
    nbl_chain: *mut NetBufferList,
    flags: u32,
) {
    let mp = ndis_find_miniport(adapter_handle);
    if mp.is_null() {
        return;
    }
    let mut nbl = nbl_chain;
    while !nbl.is_null() {
        let next = (*nbl).next;
        (*mp).rx_packets += 1;
        super::ip::ip_receive_frame(nbl, flags);
        if flags & NDIS_RECEIVE_FLAGS_RESOURCES != 0 {
            ndis_free_nbl(nbl);
        }
        // else: protocol owns it and returns via ndis_return_nbl
        nbl = next;
    }
}

/// NdisReturnNetBufferLists - protocol gives NBLs back.
pub unsafe fn ndis_return_nbl(nbl_chain: *mut NetBufferList) {
    let mut nbl = nbl_chain;
    while !nbl.is_null() {
        let next = (*nbl).next;
        ndis_free_nbl(nbl);
        nbl = next;
    }
}

/// Register the loopback miniport (127.0.0.1 path).
pub unsafe fn ndis_register_loopback() -> NtStatus {
    let name: [u16; 9] = [0x4C, 0x6F, 0x6F, 0x70, 0x62, 0x61, 0x63, 0x6B, 0]; // Loopback
    let mac = EthAddr {
        bytes: [0x02, 0x00, 0x4C, 0x4F, 0x4F, 0x50],
    };
    let mut h = 0u64;
    let st = ndis_register_miniport(
        name.as_ptr(),
        &mac,
        NDIS_MEDIA_LOOPBACK,
        65536,
        true,
        &mut h,
    );
    if st == STATUS_SUCCESS {
        super::ip::ip_add_interface(h, super::IPV4_LOOPBACK, 0xFF00_0000, mac);
    }
    st
}

pub unsafe fn ndis_initialize() -> NtStatus {
    STATUS_SUCCESS
}
