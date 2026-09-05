# VladOS 🪟

**VladOS** is a modern 64-bit operating system inspired by Windows 10 architecture and aesthetics, written in Rust.

---

## 🌟 Key Features

* **Windows 10 Visual Identity & Quiet Boot:**
  * Clean, minimal bootscreen with iconic blue 4-tile logo and animated spinner.
  * Completely quiet boot (`quiet: true`) hiding technical kernel logs on the monitor while preserving background COM1 diagnostic traces.
  * Custom Windows 10 style Blue Screen of Death (`:(` `CRITICAL_PROCESS_DIED`).
* **VladFS File System:**
  * Custom structured filesystem inspired by NTFS MFT (Record Table, Extents, Resident Data).
  * High performance resident small file storage and extent mapping.
  * Clean directory hierarchy (`/VladOS/System32/`, `/Users/Vlad/`, `/Programs/`).
* **`.vex` Executable Standard:**
  * All userland and system programs use the **`.vex`** (Vlad EXecutable) format.
  * Native Ring 3 system supervisor `vladinit.vex`.
* **UEFI Bootloader:**
  * Native pure UEFI bootloader with zero legacy dependencies, booting directly to GOP framebuffer.

---

## 🚀 Downloads

Download the latest bootable ISO from [GitHub Releases](https://github.com/vladiksicily1/vlados/releases) or the [VladOS Web Portal](https://vladinc.ru/vlados/).

```bash
qemu-system-x86_64 -m 2048 -bios /usr/share/ovmf/OVMF.fd -cdrom vlados.iso
```

---

## 📜 License
Licensed under the MIT License.
