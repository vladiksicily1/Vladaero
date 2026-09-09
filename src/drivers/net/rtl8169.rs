/// Realtek RTL8169/8111 driver (rtl8169.sys, NDIS miniport)
///
/// I/O BAR register init, MAC from IDR registers, TX/RX
/// descriptor rings, link detection, TX enqueue + RX poll.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::bus::pci;
use crate::drivers::{port_inb, port_outb};
use super::STATUS_NOT_MINE;

// Registers (I/O BAR offsets).
pub const RTL_IDR0: u16 = 0x00;
pub const RTL_TPPOLL: u16 = 0x38;
pub const RTL_CHIPCMD: u16 = 0x37;
pub const RTL_TXCONFIG: u16 = 0x40;
pub const RTL_RXCONFIG: u16 = 0x44;
pub const RTL_TXDESC_START_LO: u16 = 0x20;
pub const RTL_RXDESC_START_LO: u16 = 0xE4;
pub const RTL_IMR: u16 = 0x3E;
pub const RTL_ISR: u16 = 0x3C;
pub const RTL_PHYSTATUS: u16 = 0x6C;

pub const RTL_CMD_RESET: u8 = 0x10;
pub const RTL_CMD_TX_ENABLE: u8 = 0x04;
pub const RTL_CMD_RX_ENABLE: u8 = 0x08;
pub const RTL_TX_POLL_NPQ: u8 = 0x40;
pub const RTL_RX_ACCEPT_ALL: u32 = 0x000F;

pub const RTL_TX_DESC_COUNT: usize = 128;
pub const RTL_RX_DESC_COUNT: usize = 128;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlDesc {
    pub command: u32, // OWN bit 31 | EOR bit 30 | FS | LS | len[12:0]
    pub vlan: u32,
    pub addr_lo: u32,
    pub addr_hi: u32,
}

pub const RTL_DESC_OWN: u32 = 1 << 31;
pub const RTL_DESC_EOR: u32 = 1 << 30;
pub const RTL_DESC_FS: u32 = 1 << 29;
pub const RTL_DESC_LS: u32 = 1 << 28;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct RtlNic {
    pub iobase: u16,
    pub adapter_handle: u64,
    pub mac: crate::net::EthAddr,
    pub tx_ring: *mut RtlDesc,
    pub rx_ring: *mut RtlDesc,
    pub rx_buffers: *mut u8,
    pub tx_head: usize,
    pub rx_tail: usize,
    pub link_up: bool,
    pub present: bool,
}

static mut RTL_NICS: [RtlNic; 4] = [RtlNic {
    iobase: 0,
    adapter_handle: 0,
    mac: crate::net::EthAddr { bytes: [0; 6] },
    tx_ring: core::ptr::null_mut(),
    rx_ring: core::ptr::null_mut(),
    rx_buffers: core::ptr::null_mut(),
    tx_head: 0,
    rx_tail: 0,
    link_up: false,
    present: false,
}; 4];
static mut RTL_COUNT: usize = 0;

unsafe fn rtl_init_nic(iobase: u16) -> bool {
    if RTL_COUNT >= RTL_NICS.len() || iobase == 0 {
        return false;
    }
    let idx = RTL_COUNT;
    RTL_NICS[idx].iobase = iobase;
    let nic = &mut RTL_NICS[idx] as *mut RtlNic;
    // Reset.
    port_outb(iobase + RTL_CHIPCMD, RTL_CMD_RESET);
    let mut spins = 100_000u32;
    while spins > 0 && port_inb(iobase + RTL_CHIPCMD) & RTL_CMD_RESET != 0 {
        spins -= 1;
    }
    // MAC from IDR0..5.
    let mut i = 0;
    while i < 6 {
        (*nic).mac.bytes[i] = port_inb(iobase + RTL_IDR0 + i as u16);
        i += 1;
    }
    // Rings.
    let tx_layout =
        core::alloc::Layout::from_size_align(RTL_TX_DESC_COUNT * 16, 256);
    let tx_ring = match tx_layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l) as *mut RtlDesc,
        Err(_) => core::ptr::null_mut(),
    };
    let rx_layout =
        core::alloc::Layout::from_size_align(RTL_RX_DESC_COUNT * 16, 256);
    let rx_ring = match rx_layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l) as *mut RtlDesc,
        Err(_) => core::ptr::null_mut(),
    };
    let rxb_layout =
        core::alloc::Layout::from_size_align(RTL_RX_DESC_COUNT * 2048, 2048);
    let rx_buffers = match rxb_layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l),
        Err(_) => core::ptr::null_mut(),
    };
    if tx_ring.is_null() || rx_ring.is_null() || rx_buffers.is_null() {
        return false;
    }
    let mut j = 0usize;
    while j < RTL_RX_DESC_COUNT {
        let phys = rx_buffers.add(j * 2048) as u64;
        let d = rx_ring.add(j);
        (*d).command = RTL_DESC_OWN | 2048;
        if j + 1 == RTL_RX_DESC_COUNT {
            (*d).command |= RTL_DESC_EOR;
        }
        (*d).addr_lo = (phys & 0xFFFF_FFFF) as u32;
        (*d).addr_hi = (phys >> 32) as u32;
        j += 1;
    }
    // Mark TX ring end.
    (*tx_ring.add(RTL_TX_DESC_COUNT - 1)).command |= RTL_DESC_EOR;
    (*nic).tx_ring = tx_ring;
    (*nic).rx_ring = rx_ring;
    (*nic).rx_buffers = rx_buffers;
    // Program descriptor base (low 32; assume <4GB DMA window).
    for_reg32(iobase, RTL_TXDESC_START_LO, tx_ring as u64);
    for_reg32(iobase, RTL_RXDESC_START_LO, rx_ring as u64);
    // RX config: accept broadcast/multicast/my-phys/all-phys.
    for_reg32(iobase, RTL_RXCONFIG, RTL_RX_ACCEPT_ALL);
    // Enable TX+RX.
    port_outb(iobase + RTL_CHIPCMD, RTL_CMD_TX_ENABLE | RTL_CMD_RX_ENABLE);
    (*nic).link_up = port_inb(iobase + RTL_PHYSTATUS) & 0x02 != 0;
    // NDIS registration.
    let name: [u16; 4] = [0x72, 0x74, 0x6C, 0]; // rtl
    let mut h = 0u64;
    if crate::net::ndis::ndis_register_miniport(
        name.as_ptr(),
        &(*nic).mac,
        crate::net::ndis::NDIS_MEDIA_ETHERNET,
        1500,
        false,
        &mut h,
    ) != STATUS_SUCCESS
    {
        return false;
    }
    (*nic).adapter_handle = h;
    (*nic).present = true;
    RTL_COUNT += 1;
    crate::kernel_log!("[rtl8169] NIC {} link={}\n", h, (*nic).link_up as u8);
    true
}

unsafe fn for_reg32(iobase: u16, off: u16, val: u64) {
    // 32-bit I/O via RB/RW pairs (RTL8169 I/O BAR is byte-wide):
    // use two 16-bit + byte writes through the data ports.
    let lo = (val & 0xFFFF_FFFF) as u32;
    port_outb(iobase + off, (lo & 0xFF) as u8);
    port_outb(iobase + off + 1, ((lo >> 8) & 0xFF) as u8);
    port_outb(iobase + off + 2, ((lo >> 16) & 0xFF) as u8);
    port_outb(iobase + off + 3, ((lo >> 24) & 0xFF) as u8);
}

pub unsafe fn rtl8169_transmit(
    adapter_handle: u64,
    nb: *mut crate::net::ndis::NetBuffer,
) -> NtStatus {
    let mut i = 0usize;
    while i < RTL_COUNT {
        if RTL_NICS[i].present && RTL_NICS[i].adapter_handle == adapter_handle {
            let nic = &mut RTL_NICS[i] as *mut RtlNic;
            let data = crate::net::ndis::ndis_get_data_buffer(nb);
            let len = (*nb).data_length as usize;
            if data.is_null() || len == 0 || len > 9022 {
                return STATUS_INVALID_PARAMETER;
            }
            let head = (*nic).tx_head % RTL_TX_DESC_COUNT;
            let txd = (*nic).tx_ring.add(head);
            if (*txd).command & RTL_DESC_OWN != 0 {
                return STATUS_INSUFFICIENT_RESOURCES; // ring full
            }
            let phys = data as u64;
            (*txd).addr_lo = (phys & 0xFFFF_FFFF) as u32;
            (*txd).addr_hi = (phys >> 32) as u32;
            let mut cmd = RTL_DESC_FS | RTL_DESC_LS | ((len & 0x1FFF) as u32);
            if head + 1 == RTL_TX_DESC_COUNT {
                cmd |= RTL_DESC_EOR;
            }
            (*txd).command = cmd | RTL_DESC_OWN;
            (*nic).tx_head += 1;
            port_outb((*nic).iobase + RTL_TPPOLL, RTL_TX_POLL_NPQ);
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_NOT_MINE
}

pub unsafe fn rtl8169_poll_rx() {
    let mut i = 0usize;
    while i < RTL_COUNT {
        if RTL_NICS[i].present {
            let nic = &mut RTL_NICS[i] as *mut RtlNic;
            loop {
                let rxd = (*nic).rx_ring.add((*nic).rx_tail);
                if (*rxd).command & RTL_DESC_OWN != 0 {
                    break;
                }
                let len = ((*rxd).command & 0x1FFF) as usize;
                if len >= 14 {
                    let buf = (*nic).rx_buffers.add((*nic).rx_tail * 2048);
                    let pool = crate::net::ndis::ndis_allocate_nbl_pool(0x366C7472);
                    if !pool.is_null() {
                        let nbl = crate::net::ndis::ndis_allocate_nbl(pool, len as u32, 0);
                        if !nbl.is_null() {
                            let nb = (*nbl).first_net_buffer;
                            let dst = crate::net::ndis::ndis_get_data_buffer(nb);
                            if !dst.is_null() {
                                // Strip 4-byte Rx CRC appended by hardware.
                                let copy = len.saturating_sub(4);
                                core::ptr::copy_nonoverlapping(buf, dst, copy);
                                (*nb).data_length = copy as u32;
                            }
                            (*nbl).source_handle = (*nic).adapter_handle;
                            crate::net::ndis::ndis_indicate_receive(
                                (*nic).adapter_handle,
                                nbl,
                                crate::net::ndis::NDIS_RECEIVE_FLAGS_RESOURCES,
                            );
                            crate::net::ndis::ndis_free_nbl(nbl);
                        }
                        crate::mm::pool::ex_free_pool(pool as *mut c_void);
                    }
                }
                // Return descriptor to hardware.
                let mut cmd = RTL_DESC_OWN | 2048;
                if (*nic).rx_tail + 1 == RTL_RX_DESC_COUNT {
                    cmd |= RTL_DESC_EOR;
                }
                (*rxd).command = cmd;
                (*nic).rx_tail = ((*nic).rx_tail + 1) % RTL_RX_DESC_COUNT;
            }
        }
        i += 1;
    }
}

pub unsafe extern "C" fn rtl8169_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0usize;
    while i < pci::pci_device_count() {
        if let Some(dev) = pci::pci_get_device(i) {
            if (*dev).vendor_id == pci::PCI_VID_REALTEK
                && (*dev).device_id == pci::PCI_DID_RTL8169
            {
                pci::pci_enable_device((*dev).bus, (*dev).dev, (*dev).func);
                let mut b = 0usize;
                while b < 6 {
                    if (*dev).bar_is_io[b] {
                        rtl_init_nic((*dev).bar[b] as u16 & 0xFFFC);
                        break;
                    }
                    b += 1;
                }
            }
        }
        i += 1;
    }
    let mut dev_obj: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\RTL8169\0");
    crate::io::io_create_device(driver_object, 0, &mut name, 0x0000001F, 0, 0, &mut dev_obj)
}
