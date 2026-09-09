/// Intel PRO/1000 driver (e1iexpress.sys, NDIS miniport)
///
/// 8254x/8257x MMIO: EEPROM MAC, RX/TX descriptor rings, link
/// status, TX enqueue + RX poll with NDIS indications.

use core::ffi::c_void;

use crate::types::*;
use crate::drivers::bus::pci;
use super::STATUS_NOT_MINE;

// Registers (BAR0 MMIO).
pub const E1000_CTRL: usize = 0x0000;
pub const E1000_STATUS: usize = 0x0008;
pub const E1000_EERD: usize = 0x0014;
pub const E1000_RCTL: usize = 0x0100;
pub const E1000_TCTL: usize = 0x0400;
pub const E1000_RDLEN: usize = 0x2808;
pub const E1000_RDH: usize = 0x2810;
pub const E1000_RDT: usize = 0x2818;
pub const E1000_RDBAL: usize = 0x2800;
pub const E1000_RDBAH: usize = 0x2804;
pub const E1000_TDBAL: usize = 0x3800;
pub const E1000_TDBAH: usize = 0x3804;
pub const E1000_TDLEN: usize = 0x3808;
pub const E1000_TDH: usize = 0x3810;
pub const E1000_TDT: usize = 0x3818;
pub const E1000_MTA: usize = 0x5200;
pub const E1000_IMS: usize = 0x00D0;
pub const E1000_RAL0: usize = 0x5400;
pub const E1000_RAH0: usize = 0x5404;

pub const E1000_RCTL_EN: u32 = 1 << 1;
pub const E1000_RCTL_BAM: u32 = 1 << 15;
pub const E1000_RCTL_BSIZE_2048: u32 = 0 << 16;
pub const E1000_TCTL_EN: u32 = 1 << 1;
pub const E1000_TCTL_PSP: u32 = 1 << 3;
pub const E1000_STATUS_LU: u32 = 1 << 1;

pub const E1000_RX_DESC_COUNT: usize = 128;
pub const E1000_TX_DESC_COUNT: usize = 128;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct E1000RxDesc {
    pub addr_lo: u32,
    pub addr_hi: u32,
    pub length: u16,
    pub checksum: u16,
    pub status: u8,
    pub errors: u8,
    pub special: u16,
}

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct E1000TxDesc {
    pub addr_lo: u32,
    pub addr_hi: u32,
    pub length: u16,
    pub cso: u8,
    pub cmd: u8,
    pub status: u8,
    pub css: u8,
    pub special: u16,
}

pub const E1000_RXD_STAT_DD: u8 = 0x01;
pub const E1000_TXD_CMD_EOP: u8 = 0x01;
pub const E1000_TXD_CMD_RS: u8 = 0x08;
pub const E1000_TXD_STAT_DD: u8 = 0x01;

#[repr(C)]
#[derive(Debug, Clone, Copy)]
pub struct E1000Nic {
    pub mmio: u64,
    pub adapter_handle: u64,
    pub mac: crate::net::EthAddr,
    pub rx_ring: *mut E1000RxDesc,
    pub tx_ring: *mut E1000TxDesc,
    pub rx_buffers: *mut u8,
    pub tx_head: usize,
    pub link_up: bool,
    pub present: bool,
}

static mut E1000_NICS: [E1000Nic; 4] = [E1000Nic {
    mmio: 0,
    adapter_handle: 0,
    mac: crate::net::EthAddr { bytes: [0; 6] },
    rx_ring: core::ptr::null_mut(),
    tx_ring: core::ptr::null_mut(),
    rx_buffers: core::ptr::null_mut(),
    tx_head: 0,
    link_up: false,
    present: false,
}; 4];
static mut E1000_COUNT: usize = 0;

unsafe fn e1000_read(nic: *mut E1000Nic, off: usize) -> u32 {
    *(((*nic).mmio as *const u8).add(off) as *const u32)
}

unsafe fn e1000_write(nic: *mut E1000Nic, off: usize, val: u32) {
    *(((*nic).mmio as *mut u8).add(off) as *mut u32) = val;
}

/// Read MAC from EEPROM (EERD) or RAL/RAH fallback.
unsafe fn e1000_read_mac(nic: *mut E1000Nic) {
    let mut mac = [0u8; 6];
    // Try EEPROM words 0..2.
    let mut ok = true;
    let mut i = 0u32;
    while i < 3 {
        e1000_write(nic, E1000_EERD, 1 | (i << 8));
        let mut spins = 100_000u32;
        let mut data = 0u32;
        while spins > 0 {
            data = e1000_read(nic, E1000_EERD);
            if data & (1 << 4) != 0 {
                break;
            }
            spins -= 1;
        }
        if spins == 0 {
            ok = false;
            break;
        }
        let w = (data >> 16) as u16;
        mac[(i * 2) as usize] = (w & 0xFF) as u8;
        mac[(i * 2 + 1) as usize] = (w >> 8) as u8;
        i += 1;
    }
    if !ok || (mac[0] == 0 && mac[1] == 0 && mac[2] == 0) {
        // RAL0/RAH0 fallback.
        let ral = e1000_read(nic, E1000_RAL0);
        let rah = e1000_read(nic, E1000_RAH0);
        mac[0] = (ral & 0xFF) as u8;
        mac[1] = ((ral >> 8) & 0xFF) as u8;
        mac[2] = ((ral >> 16) & 0xFF) as u8;
        mac[3] = ((ral >> 24) & 0xFF) as u8;
        mac[4] = (rah & 0xFF) as u8;
        mac[5] = ((rah >> 8) & 0xFF) as u8;
    }
    (*nic).mac.bytes = mac;
}

unsafe fn e1000_reset(nic: *mut E1000Nic) {
    let mut ctrl = e1000_read(nic, E1000_CTRL);
    ctrl |= 1 << 26; // RST
    e1000_write(nic, E1000_CTRL, ctrl);
    let mut spins = 100_000u32;
    while spins > 0 && e1000_read(nic, E1000_CTRL) & (1 << 26) != 0 {
        spins -= 1;
    }
}

unsafe fn e1000_init_rings(nic: *mut E1000Nic) -> bool {
    // RX ring + 2KB buffers.
    let rx_layout =
        core::alloc::Layout::from_size_align(E1000_RX_DESC_COUNT * 16, 16);
    let rx_ring = match rx_layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l) as *mut E1000RxDesc,
        Err(_) => core::ptr::null_mut(),
    };
    let rxb_layout =
        core::alloc::Layout::from_size_align(E1000_RX_DESC_COUNT * 2048, 2048);
    let rx_buffers = match rxb_layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l),
        Err(_) => core::ptr::null_mut(),
    };
    // TX ring.
    let tx_layout =
        core::alloc::Layout::from_size_align(E1000_TX_DESC_COUNT * 16, 16);
    let tx_ring = match tx_layout {
        Ok(l) => alloc::alloc::alloc_zeroed(l) as *mut E1000TxDesc,
        Err(_) => core::ptr::null_mut(),
    };
    if rx_ring.is_null() || rx_buffers.is_null() || tx_ring.is_null() {
        return false;
    }
    let mut i = 0usize;
    while i < E1000_RX_DESC_COUNT {
        let phys = rx_buffers.add(i * 2048) as u64;
        (*rx_ring.add(i)).addr_lo = (phys & 0xFFFF_FFFF) as u32;
        (*rx_ring.add(i)).addr_hi = (phys >> 32) as u32;
        (*rx_ring.add(i)).status = 0;
        i += 1;
    }
    (*nic).rx_ring = rx_ring;
    (*nic).rx_buffers = rx_buffers;
    (*nic).tx_ring = tx_ring;
    // Program registers.
    e1000_write(nic, E1000_RDBAL, (rx_ring as u64 & 0xFFFF_FFFF) as u32);
    e1000_write(nic, E1000_RDBAH, (rx_ring as u64 >> 32) as u32);
    e1000_write(nic, E1000_RDLEN, (E1000_RX_DESC_COUNT * 16) as u32);
    e1000_write(nic, E1000_RDH, 0);
    e1000_write(nic, E1000_RDT, (E1000_RX_DESC_COUNT - 1) as u32);
    e1000_write(nic, E1000_TDBAL, (tx_ring as u64 & 0xFFFF_FFFF) as u32);
    e1000_write(nic, E1000_TDBAH, (tx_ring as u64 >> 32) as u32);
    e1000_write(nic, E1000_TDLEN, (E1000_TX_DESC_COUNT * 16) as u32);
    e1000_write(nic, E1000_TDH, 0);
    e1000_write(nic, E1000_TDT, 0);
    // Receive control: enable, broadcast, 2048B buffers.
    e1000_write(
        nic,
        E1000_RCTL,
        E1000_RCTL_EN | E1000_RCTL_BAM | E1000_RCTL_BSIZE_2048,
    );
    e1000_write(nic, E1000_TCTL, E1000_TCTL_EN | E1000_TCTL_PSP);
    // Program our MAC into RAL0/RAH0 + enable.
    let m = (*nic).mac.bytes;
    e1000_write(
        nic,
        E1000_RAL0,
        (m[0] as u32) | ((m[1] as u32) << 8) | ((m[2] as u32) << 16) | ((m[3] as u32) << 24),
    );
    e1000_write(
        nic,
        E1000_RAH0,
        (m[4] as u32) | ((m[5] as u32) << 8) | (1 << 31),
    );
    true
}

/// MiniportInitializeEx for one 8254x function.
unsafe fn e1000_init_nic(mmio: u64) -> bool {
    if E1000_COUNT >= E1000_NICS.len() {
        return false;
    }
    let idx = E1000_COUNT;
    E1000_NICS[idx].mmio = mmio;
    let nic = &mut E1000_NICS[idx] as *mut E1000Nic;
    e1000_reset(nic);
    e1000_read_mac(nic);
    if !e1000_init_rings(nic) {
        return false;
    }
    (*nic).link_up = e1000_read(nic, E1000_STATUS) & E1000_STATUS_LU != 0;
    // Register with NDIS.
    let name: [u16; 5] = [0x65, 0x31, 0x30, 0x30, 0]; // e100
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
    E1000_COUNT += 1;
    // Configure IP on first NIC (QEMU user-net default).
    crate::net::ip::ip_add_interface(h, 0x0A00_020F, 0xFFFF_FF00, (*nic).mac);
    crate::net::ip::ip_add_route(0, 0, 0x0A00_0202, h, 20);
    crate::kernel_log!(
        "[e1000] NIC {} link={}\n",
        h,
        (*nic).link_up as u8
    );
    true
}

/// MiniportSendNetBufferLists for our adapters.
pub unsafe fn e1000_transmit(
    adapter_handle: u64,
    nb: *mut crate::net::ndis::NetBuffer,
) -> NtStatus {
    let mut i = 0usize;
    while i < E1000_COUNT {
        if E1000_NICS[i].present && E1000_NICS[i].adapter_handle == adapter_handle {
            let nic = &mut E1000_NICS[i] as *mut E1000Nic;
            let data = crate::net::ndis::ndis_get_data_buffer(nb);
            let len = (*nb).data_length as usize;
            if data.is_null() || len == 0 || len > 9018 {
                return STATUS_INVALID_PARAMETER;
            }
            // TX descriptor at head.
            let head = (*nic).tx_head % E1000_TX_DESC_COUNT;
            let txd = (*nic).tx_ring.add(head);
            // Wait for DD on the descriptor we are about to reuse.
            let mut spins = 100_000u32;
            while spins > 0 && (*txd).status & E1000_TXD_STAT_DD == 0 && (*nic).tx_head >= E1000_TX_DESC_COUNT {
                spins -= 1;
            }
            let phys = data as u64;
            (*txd).addr_lo = (phys & 0xFFFF_FFFF) as u32;
            (*txd).addr_hi = (phys >> 32) as u32;
            (*txd).length = len as u16;
            (*txd).cmd = E1000_TXD_CMD_EOP | E1000_TXD_CMD_RS;
            (*txd).status = 0;
            (*nic).tx_head += 1;
            e1000_write(nic, E1000_TDT, ((*nic).tx_head % E1000_TX_DESC_COUNT) as u32);
            return STATUS_SUCCESS;
        }
        i += 1;
    }
    STATUS_NOT_MINE
}

/// Poll RX descriptors; indicate completed frames to NDIS.
pub unsafe fn e1000_poll_rx() {
    let mut i = 0usize;
    while i < E1000_COUNT {
        if E1000_NICS[i].present {
            let nic = &mut E1000_NICS[i] as *mut E1000Nic;
            let mut tail = e1000_read(nic, E1000_RDT) as usize;
            loop {
                let next = (tail + 1) % E1000_RX_DESC_COUNT;
                let rxd = (*nic).rx_ring.add(next);
                if (*rxd).status & E1000_RXD_STAT_DD == 0 {
                    break;
                }
                let len = (*rxd).length as usize;
                if len >= 14 && (*rxd).errors == 0 {
                    let buf = (*nic).rx_buffers.add(next * 2048);
                    // Build an RX NBL (copy; RESOURCES semantics).
                    let pool = crate::net::ndis::ndis_allocate_nbl_pool(0x30303165);
                    if !pool.is_null() {
                        let nbl = crate::net::ndis::ndis_allocate_nbl(pool, len as u32, 0);
                        if !nbl.is_null() {
                            let nb = (*nbl).first_net_buffer;
                            let dst = crate::net::ndis::ndis_get_data_buffer(nb);
                            if !dst.is_null() {
                                core::ptr::copy_nonoverlapping(buf, dst, len);
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
                (*rxd).status = 0;
                tail = next;
                e1000_write(nic, E1000_RDT, tail as u32);
            }
        }
        i += 1;
    }
}

pub unsafe extern "C" fn e1000_driver_entry(
    driver_object: *mut crate::io::DriverObject,
    _registry_path: Pvoid,
) -> NtStatus {
    if driver_object.is_null() {
        return STATUS_INVALID_PARAMETER;
    }
    let mut i = 0usize;
    while i < pci::pci_device_count() {
        if let Some(dev) = pci::pci_get_device(i) {
            if (*dev).vendor_id == pci::PCI_VID_INTEL
                && ((*dev).device_id == pci::PCI_DID_E1000
                    || (*dev).device_id == pci::PCI_DID_E1000E)
            {
                pci::pci_enable_device((*dev).bus, (*dev).dev, (*dev).func);
                if (*dev).bar[0] & 1 == 0 {
                    e1000_init_nic((*dev).bar[0]);
                }
            }
        }
        i += 1;
    }
    let mut dev_obj: *mut crate::io::IoDeviceObject = core::ptr::null_mut();
    let mut name = crate::ob::create_unicode_string(b"\\Device\\E1000\0");
    crate::io::io_create_device(driver_object, 0, &mut name, 0x0000001F, 0, 0, &mut dev_obj)
}
