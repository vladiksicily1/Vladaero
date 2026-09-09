/// ICMPv4 (tcpip.sys): echo request/reply, errors
///
/// Ping support with identifier/sequence matching and a pending
/// echo table so replies wake the requester.

use crate::types::*;
use super::{IP_PROTO_ICMP, net_checksum};

pub const ICMP_ECHO_REPLY: u8 = 0;
pub const ICMP_DEST_UNREACHABLE: u8 = 3;
pub const ICMP_ECHO_REQUEST: u8 = 8;
pub const ICMP_TIME_EXCEEDED: u8 = 11;

pub const ICMP_MAX_PENDING: usize = 32;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IcmpHeader {
    pub icmp_type: u8,
    pub code: u8,
    pub checksum: u16,
    pub ident: u16,
    pub sequence: u16,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct IcmpPendingEcho {
    pub ident: u16,
    pub sequence: u16,
    pub dst: u32,
    pub sent_ms: u64,
    pub replied: bool,
    pub rtt_ms: u64,
    pub in_use: bool,
}

static mut ICMP_PENDING: [IcmpPendingEcho; ICMP_MAX_PENDING] = [IcmpPendingEcho {
    ident: 0,
    sequence: 0,
    dst: 0,
    sent_ms: 0,
    replied: false,
    rtt_ms: 0,
    in_use: false,
}; ICMP_MAX_PENDING];
static mut ICMP_NOW_MS: u64 = 0;
static ICMP_NEXT_IDENT: core::sync::atomic::AtomicU16 =
    core::sync::atomic::AtomicU16::new(1);

pub unsafe fn icmp_tick(now_ms: u64) {
    ICMP_NOW_MS = now_ms;
}

/// IcmpSendEcho - ping a host (payload optional).
pub unsafe fn icmp_send_echo(
    dst: u32,
    ident: u16,
    sequence: u16,
    payload: *const u8,
    payload_len: usize,
) -> NtStatus {
    let total = 8 + payload_len;
    let mut buf = [0u8; 1500];
    if total > buf.len() {
        return STATUS_INVALID_PARAMETER;
    }
    buf[0] = ICMP_ECHO_REQUEST;
    buf[1] = 0;
    buf[4] = (ident >> 8) as u8;
    buf[5] = (ident & 0xFF) as u8;
    buf[6] = (sequence >> 8) as u8;
    buf[7] = (sequence & 0xFF) as u8;
    if payload_len > 0 && !payload.is_null() {
        core::ptr::copy_nonoverlapping(payload, buf.as_mut_ptr().add(8), payload_len);
    }
    let cks = net_checksum(&buf[..total]);
    buf[2] = (cks >> 8) as u8;
    buf[3] = (cks & 0xFF) as u8;
    // Track.
    let mut i = 0;
    while i < ICMP_MAX_PENDING {
        if !ICMP_PENDING[i].in_use {
            ICMP_PENDING[i] = IcmpPendingEcho {
                ident,
                sequence,
                dst,
                sent_ms: ICMP_NOW_MS,
                replied: false,
                rtt_ms: 0,
                in_use: true,
            };
            break;
        }
        i += 1;
    }
    super::ip::ip_send(0, dst, IP_PROTO_ICMP, 128, buf.as_ptr(), total)
}

/// IcmpReceive - handle an ICMP packet.
pub unsafe fn icmp_receive(src: u32, _dst: u32, payload: *const u8, len: usize) {
    if len < 8 || payload.is_null() {
        return;
    }
    let hdr = payload as *const IcmpHeader;
    let icmp_type = (*hdr).icmp_type;
    match icmp_type {
        ICMP_ECHO_REQUEST => {
            // Verify checksum then echo back.
            let bytes = core::slice::from_raw_parts(payload, len);
            if net_checksum(bytes) != 0 {
                return;
            }
            icmp_send_reply(src, payload, len);
        }
        ICMP_ECHO_REPLY => {
            let bytes = core::slice::from_raw_parts(payload, len);
            if net_checksum(bytes) != 0 {
                return;
            }
            let ident = u16::from_be_bytes([*payload.add(4), *payload.add(5)]);
            let seq = u16::from_be_bytes([*payload.add(6), *payload.add(7)]);
            let mut i = 0;
            while i < ICMP_MAX_PENDING {
                if ICMP_PENDING[i].in_use
                    && ICMP_PENDING[i].ident == ident
                    && ICMP_PENDING[i].sequence == seq
                    && ICMP_PENDING[i].dst == src
                {
                    ICMP_PENDING[i].replied = true;
                    ICMP_PENDING[i].rtt_ms = ICMP_NOW_MS - ICMP_PENDING[i].sent_ms;
                    break;
                }
                i += 1;
            }
        }
        _ => {}
    }
}

unsafe fn icmp_send_reply(to: u32, request: *const u8, len: usize) {
    let mut buf = [0u8; 1500];
    if len > buf.len() || len < 8 {
        return;
    }
    core::ptr::copy_nonoverlapping(request, buf.as_mut_ptr(), len);
    buf[0] = ICMP_ECHO_REPLY;
    buf[1] = 0;
    buf[2] = 0;
    buf[3] = 0;
    let cks = net_checksum(&buf[..len]);
    buf[2] = (cks >> 8) as u8;
    buf[3] = (cks & 0xFF) as u8;
    super::ip::ip_send(0, to, IP_PROTO_ICMP, 128, buf.as_ptr(), len);
}

/// IcmpPollReply - check/wait result for a ping (reaped on read).
pub unsafe fn icmp_poll_reply(
    ident: u16,
    sequence: u16,
    replied_out: *mut bool,
    rtt_ms_out: *mut u64,
) -> NtStatus {
    if replied_out.is_null() || rtt_ms_out.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0;
    while i < ICMP_MAX_PENDING {
        if ICMP_PENDING[i].in_use
            && ICMP_PENDING[i].ident == ident
            && ICMP_PENDING[i].sequence == sequence
        {
            *replied_out = ICMP_PENDING[i].replied;
            *rtt_ms_out = ICMP_PENDING[i].rtt_ms;
            if ICMP_PENDING[i].replied {
                ICMP_PENDING[i].in_use = false;
            }
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_OBJECT_NAME_NOT_FOUND
}

/// Allocate a fresh echo identifier.
pub fn icmp_alloc_ident() -> u16 {
    ICMP_NEXT_IDENT.fetch_add(1, core::sync::atomic::Ordering::Relaxed)
}
