/// Network drivers: Intel e1000 (e1iexpress.sys), Realtek RTL8169
pub mod e1000;
pub mod rtl8169;

use crate::types::*;

/// NicTransmit - NDIS send path hands the NET_BUFFER to the NIC.
///
/// Dispatches to the owning miniport's TX ring by adapter handle.
pub unsafe fn nic_transmit(adapter_handle: u64, nb: *mut crate::net::ndis::NetBuffer) -> NtStatus {
    // Try e1000 first, then RTL8169.
    let st = e1000::e1000_transmit(adapter_handle, nb);
    if st != STATUS_NOT_MINE {
        return st;
    }
    rtl8169::rtl8169_transmit(adapter_handle, nb)
}

// Local sentinel: miniport does not own this adapter.
pub const STATUS_NOT_MINE: NtStatus = 0x40000000;
