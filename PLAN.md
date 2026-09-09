# VladOS — Полный план разработки

## Описание проекта

**Vladaero (VladOS)** — операционная система на Rust, визуально и функционально похожая на Windows 10. Собственный формат VEX/VLL, NT-подобное ядро, полный графический интерфейс.

---

## Архитектурные решения

| Решение | Выбор |
|---------|-------|
| Ядро | NT-подобное микроядро |
| Формат бинарников | Свой VladaPE + совместимость с Windows PE |
| Архитектура | Только x86_64 |
| Файловая система | VladFS (B-tree + Journal) |
| Syscalls | NT-стиль (NtCreateFile, NtReadFile...) |
| Firmware | UEFI + Legacy BIOS |
| Boot screen | Анимированный логотип (как Win10) |
| Secure Boot | Без подписи |
| Разметка диска | GPT |
| Multi-boot | Только VladOS |
| Память | NT-style VAD |
| Планировщик | APC + DPC |
| Копирование данных | KeStackAttachProcess |
| Object namespace | NT-style (\Device\, \DosDevices\) |
| Paging | 4-level PML4 |
| VladFS | B-tree + Journal |
| Drive letters | C:, D:, E:... |
| Storage драйверы | AHCI + NVMe + USB |
| Совместимость FS | FAT32 + exFAT |
| Кэш файлов | Lazy writing + Read cache |
| Графика | Свой compositor (Vulkan-like) |
| Window Manager | Compositing (как DWM) |
| GUI API | Win32-like (CreateWindowEx, SendMessage...) |
| GPU | VGA + Intel iGPU |
| Графика с загрузки | UEFI GOP framebuffer |
| Сеть | TCP/IP + WiFi + Bluetooth |
| Файрвол | Stateful |
| Безопасность | NT Security (токены, SIDs, ACL) |
| UAC | Да, с elevation |
| Реестр | Свой (дерево ключей/значений) |
| Панель задач | Как Win10 |
| Проводник | Как Explorer Win10 |
| Диспетчер задач | Полный |
| Службы | Полный набор как Win10 |
| Аудио | WASAPI-like |
| Приложения | Базовый набор (Блокнот, Калькулятор, CMD, Настройки) |
| Shell | Только CMD |
| Языки | C/Rust + Windows .vex совместимость |
| Установщик | Графический |
| Драйверы | Средний набор |
| USB | 2.0 + 3.0 |
| Драйвер модель | WDM-like (kernel-mode) |
| Обнаружение устройств | PCI + ACPI |
| Hot-plug | USB + PCI |
| Тестирование | QEMU → реальный ПК |
| Процессоры | Intel + AMD |
| Дистрибуция | ISO |
| Языки интерфейса | EN + RU |
| Обновления | OTA |

---

## 12 Фаз разработки

### Фаза 1: Ядро — VladOS Kernel (NT-подобное)

**Цель:** Полностью новое ядро в стиле Windows NT

**Модули ядра:**

```
kernel/src/
├── main.rs                 # Точка входа ядра
├── arch/
│   ├── x86_64/
│   │   ├── gdt.rs          # Global Descriptor Table
│   │   ├── idt.rs          # Interrupt Descriptor Table
│   │   ├── tss.rs          # Task State Segment
│   │   ├── paging.rs       # 4-level PML4 paging
│   │   ├── syscall.rs      # Syscall entry/exit (SYSCALL/SYSRET)
│   │   ├── context.rs      # Контекст переключения
│   │   ├── io.rs           # In/Out порты
│   │   └── cpu.rs          # CPU feature detection
│   └── mod.rs
├── executive/
│   ├── mod.rs              # Executive dispatcher
│   ├── io_manager.rs       # I/O Manager (IRP-based)
│   ├── memory_manager.rs   # Memory Manager (VAD, paging, working set)
│   ├── process_manager.rs  # Process/Thread Manager (APC, DPC, handles)
│   ├── object_manager.rs   # Object Manager (\Device\, \DosDevices\)
│   ├── security_refmon.rs  # Security Reference Monitor (tokens, SIDs, ACL)
│   ├── configuration_manager.rs  # Configuration Manager (Registry)
│   ├── pnp_manager.rs      # Plug and Play Manager
│   ├── power_manager.rs    # Power Manager
│   └── io_completion.rs    # I/O Completion Ports
├── kernel/
│   ├── mod.rs
│   ├── scheduler.rs        # CFS-like scheduler with APC/DPC
│   ├── dispatcher.rs       # Kernel dispatcher (events, mutants, semaphores)
│   ├── dpc.rs              # Deferred Procedure Calls
│   ├── apc.rs              # Asynchronous Procedure Calls
│   ├── timer.rs            # Kernel timers
│   └──BugCheck.rs         # Bug Check (blue screen)
├── hal/
│   ├── mod.rs              # Hardware Abstraction Layer
│   ├── timer.rs            # HPET/ACPI timer
│   ├── dma.rs              # DMA management
│   ├── bus.rs              # Bus abstraction (PCI, ISA)
│   └── acpi.rs             # ACPI tables
├── drivers/
│   ├── bootvid.rs          # Boot video (VGA/VESA)
│   ├── serial.rs           # Serial port (16550)
│   ├── keyboard.rs         # PS/2 + USB HID keyboard
│   ├── mouse.rs            # PS/2 + USB HID mouse
│   ├── disk/
│   │   ├── ahci.rs         # AHCI (SATA)
│   │   ├── nvme.rs         # NVMe
│   │   └── usb_storage.rs  # USB mass storage
│   ├── net/
│   │   ├── e1000.rs        # Intel Ethernet
│   │   └── rtl8169.rs      # Realtek Ethernet
│   ├── gpu/
│   │   └── intel_gpu.rs    # Intel integrated GPU
│   ├── usb/
│   │   ├── ehci.rs         # USB 2.0
│   │   └── xhci.rs         # USB 3.0
│   ├── acpi/
│   │   └── battery.rs      # ACPI battery
│   └── pnp/
│       └── pci.rs          # PCI bus enumeration
├── memory/
│   ├── mod.rs              # Memory subsystem
│   ├── physical.rs         # Physical page allocator (buddy)
│   ├── virtual.rs          # Virtual address management (VAD)
│   ├── page_fault.rs       # Page fault handler
│   ├── section.rs          # Sections (memory-mapped files)
│   ├── pool.rs             # Kernel pool (NonPaged/Paged)
│   ├── pagefile.rs         # Pagefile support
│   └── working_set.rs      # Working set management
├── fs/
│   ├── mod.rs              # Filesystem subsystem
│   ├── vladfs/
│   │   ├── mod.rs          # VladFS driver
│   │   ├── mft.rs          # Master File Table
│   │   ├── index.rs        # B-tree index
│   │   ├── journal.rs      # Journal (transaction logging)
│   │   ├── attrs.rs        # Attribute parsing
│   │   └── alloc.rs        # Block allocation
│   ├── fat32.rs            # FAT32 read/write
│   ├── exfat.rs            # exFAT read/write
│   ├── ntfs_read.rs        # NTFS read-only (для разделов Windows)
│   └── cache.rs            # File cache (lazy writer)
├── net/
│   ├── mod.rs              # Network subsystem
│   ├── tcpip/
│   │   ├── mod.rs          # TCP/IP stack
│   │   ├── ip.rs           # IP layer
│   │   ├── tcp.rs          # TCP
│   │   ├── udp.rs          # UDP
│   │   ├── icmp.rs         # ICMP
│   │   ├── arp.rs          # ARP
│   │   └── socket.rs       # Socket layer
│   ├── wifi.rs             # WiFi support
│   └── bluetooth.rs        # Bluetooth support
├── security/
│   ├── mod.rs              # Security subsystem
│   ├── token.rs            # Access tokens
│   ├── sid.rs              # Security Identifiers
│   ├── acl.rs              # Access Control Lists
│   ├── descriptor.rs       # Security Descriptors
│   └── audit.rs            # Security auditing
├── registry/
│   ├── mod.rs              # Registry subsystem
│   ├── hive.rs             # Hive file format
│   ├── key.rs              # Registry keys
│   ├── value.rs            # Registry values
│   └── transaction.rs      # Transactional registry
├── ipc/
│   ├── mod.rs              # Inter-Process Communication
│   ├── LPC.rs              # Local Procedure Call (как NT)
│   ├── named_pipe.rs       # Named Pipes
│   └── mailslot.rs         # Mailslots
├── display/
│   ├── mod.rs              # Display subsystem
│   ├── compositor.rs       # Desktop Window Manager (DWM)
│   ├── surface.rs          # Surface management
│   ├── cursor.rs           # Cursor management
│   └── font.rs             # Font rendering
├── console.rs              # Console subsystem
├── env.rs                  # Environment variables
└── sysinfo.rs              # System information
```

**Syscalls (NT-стиль):**

```rust
// Файлы
NtCreateFile
NtOpenFile
NtReadFile
NtWriteFile
NtDeleteFile
NtRenameFile
NtSetInformationFile
NtQueryInformationFile
NtDeviceIoControlFile
NtFlushBuffersFile

// Процессы/потоки
NtCreateUserProcess
NtCreateThread
NtOpenProcess
NtTerminateProcess
NtSuspendProcess
NtResumeProcess
NtQueryInformationProcess
NtSetInformationThread
NtWaitForSingleObject
NtWaitForMultipleObjects

// Память
NtAllocateVirtualMemory
NtFreeVirtualMemory
NtProtectVirtualMemory
NtQueryVirtualMemory
NtCreateSection
NtOpenSection
NtMapViewOfSection
NtUnmapViewOfSection

// Объекты
NtOpenDirectoryObject
NtCreateDirectoryObject
NtOpenSymbolicLinkObject
NtCreateSymbolicLinkObject
NtQueryDirectoryObject

// Реестр
NtOpenKey
NtCreateKey
NtDeleteKey
NtSetValueKey
NtQueryValueKey
NtEnumerateKey

// Безопасность
NtOpenProcessToken
NtQueryInformationToken
NtAdjustPrivilegesToken

// Синхронизация
NtCreateEvent
NtSetEvent
NtClearEvent
NtCreateMutant
NtCreateSemaphore
NtDelayExecution
NtYieldExecution
```

---

### Фаза 2: Загрузчик — VladBoot

**Цель:** Загрузчик с анимированным boot screen

**Структура:**

```
bootloader_new/
├── Cargo.toml
├── Makefile
├── src/
│   ├── main.rs              # UEFI + BIOS entry points
│   ├── boot_args.rs         # KernelArgs
│   ├── boot_screen.rs       # Анимированный логотип
│   ├── memory.rs            # Bump allocator
│   ├── fs.rs                # VladFS driver
│   ├── gpt.rs               # GPT parser
│   ├── pe.rs                # PE loader
│   └── arch/
│       ├── uefi/mod.rs      # UEFI: GOP, Boot Services
│       └── bios/mod.rs      # BIOS: INT 0x13/0x15/0x10
├── asm/bios/
│   ├── bootloader.asm       # Stage 1 (MBR)
│   ├── stage2.asm           # Stage 2 (Protected Mode)
│   └── long_mode.asm        # Stage 3 (Long Mode)
├── linkers/
│   └── bios.ld
└── targets/
    └── i686-unknown-none.json
```

**Boot flow:**

```
[BIOS]                        [UEFI]
  │                             │
  ├─ MBR (stage1.asm)          ├─ EFI stub (bootx64.efi)
  ├─ Protected Mode            ├─ GOP framebuffer
  ├─ Long Mode                 ├─ ExitBootServices
  ├─ VladFS driver             ├─ VladFS driver
  ├─ GPT parser                ├─ GPT parser
  ├─ PE loader                 ├─ PE loader
  ├─ Boot screen               ├─ Boot screen
  └─ Jump to kernel            └─ Jump to kernel
```

---

### Фаза 3: VladFS — Файловая система

**Цель:** Собственная файловая система

**Структура данных:**

```
Superblock (сектор 0)
├── Magic: "VLFS"
├── Version: 1.0
├── Block size: 4096
├── Total blocks
├── MFT start block
├── Journal start block
├── Root inode
├── UUID
└── Free blocks bitmap

MFT Record (1024 bytes)
├── Signature: "FILE"
├── Inode number
├── Sequence number
├── Flags (file/directory)
├── Attributes:
│   ├── $STANDARD_INFORMATION (0x10)
│   │   ├── Creation time
│   │   ├── Modification time
│   │   ├── File attributes
│   │   └── Owner SID
│   ├── $FILE_NAME (0x30)
│   │   ├── Parent inode
│   │   ├── Name (UTF-16)
│   │   └── Namespace (POSIX/Win32/DOS)
│   ├── $DATA (0x80)
│   │   ├── Resident: inline data
│   │   └── Non-resident: extent list (LCN runs)
│   ├── $INDEX_ROOT (0x90) [directories]
│   │   ├── B-tree root node
│   │   └── Index entries
│   └── $INDEX_ALLOCATION (0xA0) [directories]
│       └── B-tree child nodes
└── Free space

Journal
├── Start LBA
├── Size (blocks)
├── Current position
├── Sequence numbers
└── Transaction records:
    ├── Transaction ID
    ├── Redo operations
    └── Undo operations
```

**Drive letters:**
- `C:` — системный раздел VladOS
- `D:`, `E:`, ... — данные

---

### Фаза 4: Консоль (текстовый режим)

**Цель:** Консоль до графики

- VGA text mode 80x25
- VGA text buffer (0xB8000)
- Цвета: 16 цветов (4 бита)
- Курсор
- Scroll
- ANSI escape codes
- Serial debug (COM1)

---

### Фаза 5: Графика — Compositor и GUI

**Цель:** Полноценная графическая подсистема

**Слои:**

```
┌─────────────────────────────────────┐
│         Applications                │
│  (Explorer, Notepad, Calculator)    │
├─────────────────────────────────────┤
│         Win32 API Layer             │
│  (kernel32, user32, gdi32, ntdll)   │
├─────────────────────────────────────┤
│      Desktop Window Manager         │
│      (Compositor, DWM)              │
├─────────────────────────────────────┤
│      GPU Driver (Intel iGPU)        │
├─────────────────────────────────────┤
│      Framebuffer (UEFI GOP)         │
└─────────────────────────────────────┘
```

**Compositor:**
- Hardware-accelerated rendering (Intel iGPU)
- Перекомпозиция при изменении окон
- V-Sync
- Прозрачность, тени, анимации

**Window Manager:**
- CreateWindowEx / DestroyWindow
- ShowWindow / SetWindowPos
- Message queue (WndProc)
- Z-order, focus management
- Minimize/Maximize/Restore

**GDI API:**
- CreatePen, CreateSolidBrush
- BitBlt, StretchBlt
- TextOut, DrawText
- CreateFont, SelectObject
- GetDeviceCaps

**Input:**
- Keyboard (PS/2 + USB HID)
- Mouse (PS/2 + USB HID)
- Message loop: WM_KEYDOWN, WM_MOUSEMOVE, WM_LBUTTONDOWN
- Raw Input

---

### Фаза 6: Win32 API (User-space)

**Цель:** Библиотеки Win32 API

**VLL:**

```
system32/
├── kernel32.vll      # CreateFile, ReadFile, VirtualAlloc, CreateProcess
├── user32.vll        # CreateWindowEx, GetMessage, MessageBox
├── gdi32.vll         # CreatePen, BitBlt, TextOut
├── ntdll.vll         # Nt* syscall stubs
├── advapi32.vll      # RegOpenKey, OpenProcessToken
├── shell32.vll       # ShellExecute, SHGetFolderPath
├── comctl32.vll      # ListView, TreeView
├── ole32.vll         # COM base
├── ws2_32.vll        # Winsock (TCP/IP)
├── winmm.vll         # Multimedia (audio)
├── msvcrt.vll        # C runtime
└── vlad32.vll        # VladOS extensions
```

---

### Фаза 7: VladaPE — Загрузчик PE файлов

**Цель:** Загрузка .vex/.vll

**Формат VladaPE:**

```
MZ Header (DOS stub)
├── e_magic: "MZ"
├── e_lfanew: offset to PE header

PE Header
├── Signature: "PE\0\0"
├── Machine: 0x8664 (AMD64)
├── NumberOfSections
├── OptionalHeader:
│   ├── Magic: 0x20b (PE32+)
│   ├── ImageBase
│   ├── SectionAlignment: 0x1000
│   ├── FileAlignment: 0x200
│   ├── SizeOfImage
│   ├── AddressOfEntryPoint
│   └── DataDirectory[16]:
│       ├── Export table
│       ├── Import table
│       ├── Resource table
│       ├── Exception table
│       ├── Security table
│       └── Relocation table

Section Headers
├── .text     (код)
├── .data     (данные)
├── .rdata    (read-only данные)
├── .bss      (неинициализированные)
├── .rsrc     (ресурсы: иконки, диалоги)
├── .reloc    (релокации)
└── .vlad     (расширения VladOS)

Import Table
├── VLL name
├── Function name → RVA
└── Thunk table

Export Table
├── Function name
├── Ordinal
└── RVA

Relocation Table
├── Page RVA
├── Block size
└── Relocation entries:
    ├── Type (HIGHLOW, DIR64)
    └── Offset
```

---

### Фаза 8: Реестр — Configuration Manager

**Цель:** Собственный реестр

**Структура:**

```
Registry Hive File
├── Header
│   ├── Magic: "vreg"
│   ├── Version
│   ├── Sequence numbers
│   └── Checksum
├── Bins
│   ├── Bin header
│   └── Cells:
│       ├── Key cell:
│       │   ├── Name (UTF-16)
│       │   ├── Class name
│       │   ├── Parent key
│       │   ├── Subkeys count
│       │   ├── Values count
│       │   ├── Security descriptor
│       │   └── Timestamp
│       ├── Value cell:
│       │   ├── Name (UTF-16)
│       │   ├── Type (REG_SZ, REG_DWORD, REG_BINARY...)
│       │   └── Data
│       └── Free cell

Registry Paths:
├── HKLM (HKEY_LOCAL_MACHINE)
│   ├── SYSTEM
│   ├── SOFTWARE
│   ├── HARDWARE
│   └── SECURITY
├── HKCU (HKEY_CURRENT_USER)
│   ├── Software
│   └── Environment
├── HKCR (HKEY_CLASSES_ROOT)
│   ├── .vex
│   └── ...
└── HKU (HKEY_USERS)
```

---

### Фаза 9: Драйверы

**Цель:** Драйверы устройств

| Драйвер | Описание |
|---------|----------|
| `kbdclass` | Keyboard class driver |
| `mouclass` | Mouse class driver |
| `kbdhid` | USB HID keyboard |
| `mouhid` | USB HID mouse |
| `usbport` | USB hub driver |
| `usbstor` | USB mass storage |
| `ehci` | USB 2.0 controller |
| `xhci` | USB 3.0 controller |
| `storahci` | AHCI (SATA) |
| `stornvme` | NVMe |
| `e1000` | Intel Ethernet |
| `rtl8169` | Realtek Ethernet |
| `igdkmd64` | Intel GPU kernel mode driver |
| `ndis` | Network Driver Interface |
| `acpi` | ACPI driver |
| `pci` | PCI bus driver |
| `partmgr` | Partition manager |
| `volmgr` | Volume manager |
| `ftdisk` | Fault-tolerant disk driver |

---

### Фаза 10: Системные сервисы

**Цель:** Фоновые службы

| Служба | Описание |
|--------|----------|
| Service Control Manager | Управление службами |
| RPC | Remote Procedure Call |
| Event Log | Логирование событий |
| Task Scheduler | Планировщик задач |
| Print Spooler | Очередь печати |
| Windows Update | OTA обновления |
| DHCP Client | Автоматический IP |
| DNS Client | Кэш DNS |
| Windows Firewall | Stateful файрвол |
| Audio Service | WASAPI |
| Power Service | Управление питанием |
| Plug and Play | Обнаружение устройств |

---

### Фаза 11: Desktop Environment — ВладЕксплорер

**Цель:** Полный GUI как Windows 10

**Компоненты:**

```
explorer.vex
├── Desktop
│   ├── Рабочий стол (иконки)
│   ├── Контекстное меню
│   └── Фоновое изображение
├── Taskbar
│   ├── Кнопка "Пуск"
│   ├── Панель задач (закреплённые приложения)
│   ├── Системный трей
│   │   ├── Громкость
│   │   ├── Сеть
│   │   ├── Язык
│   │   └── Уведомления
│   ├── Часы
│   └── Show Desktop button
├── Start Menu
│   ├── Список всех программ
│   ├── Закреплённые программы
│   ├── Живые плитки
│   ├── Пользователь
│   ├── Настройки
│   └── Выключение
├── File Explorer
│   ├── Дерево (Quick Access, This PC)
│   ├── Содержимое
│   ├── Адресная строка
│   ├── Панель поиска
│   ├── Ribbon (File, Home, View)
│   └── Status bar
├── Task Manager
│   ├── Процессы
│   ├── Производительность (CPU, RAM, Disk, Network)
│   ├── Автозагрузка
│   ├── Службы
│   └── Подробности
├── Settings
│   ├── Система
│   ├── Персонализация
│   ├── Сеть
│   ├── Устройства
│   └── Конфиденциальность
├── Control Panel (legacy)
│   ├── Программы
│   ├── Учётные записи
│   └── Система
└── Системные приложения
    ├── notepad.vex (Блокнот)
    ├── calc.vex (Калькулятор)
    ├── cmd.vex (Command Prompt)
    ├── mspaint.vex (Paint) [будущее]
    └── osk.vex (Экранная клавиатура) [будущее]
```

**ALT+TAB:** Переключатель окон с превью

**Законопатить окна:** Snap, Aero Snap

---

### Фаза 12: Тестирование и полировка

**Цель:** Стабильная релизная версия

- QEMU тестирование (все компоненты)
- Тест на реальном ПК (Intel + AMD)
- ISO сборка (bootable)
- OTA обновления
- RU + EN локализация
- Оптимизация производительности
- Исправление багов

---

## Полный процесс загрузки VladOS (как Windows)

### Фаза 0: Firmware (BIOS/UEFI)

**Что делает Windows:**
- BIOS: POST → поиск MBR → загрузка boot sector
- UEFI: SEC → PEI → DXE → BDS → ExitBootServices

**Что делает VladOS:**
```
BIOS:
  1. bootloader/src/arch/bios/bootloader.asm (Stage 1 — MBR)
     - Загружается по 0x7C00
     - Читает 512 байт MBR
     - Ищет активный раздел (partition type 0x83)
     - Загружает Stage 2 (stage2.asm)
  
  2. bootloader/src/arch/bios/stage2.asm (Stage 2 — Protected Mode)
     - Включает A20
     - Загружает GDT
     - Переключается в Protected Mode (Ring 0)
     - Загружает GDT (Global Descriptor Table)
     - Читает ядро с диска
     - Загружает Stage 3

  3. bootloader/src/arch/bios/long_mode.asm (Stage 3 — Long Mode)
     - Включает PAE + PGE
     - Загружает PML4 (4-level paging)
     - Переключается в Long Mode (64-bit)
     - Устанавливает стек
     - Вызывает kernel_main()

UEFI:
  1. bootloader/src/arch/uefi/main.rs
     - Получает UEFI System Table
     - Инициализирует GOP (Graphics Output Protocol)
     - Получает карту памяти (EFI Memory Map)
     - Получает ACPI RSDP
     - Находит ядро на ESP (\EFI\vlados\kernel.elf)
     - Читает ядро в память
     - Вызывает ExitBootServices()
     - Передаёт boot parameters в ядро
```

**Boot Parameters (передаются в ядро):**
```rust
pub struct BootParams {
    pub magic: u32,               // "VLAD"
    pub memory_map: *mut MemoryMap,
    pub acpi_rsdp: *mut AcpiTable,
    pub framebuffer: Framebuffer,
    pub ramdisk: Option<Ramdisk>,
    pub cmdline: [u8; 256],
}
```

---

### Фаза 1: Kernel Initialization (ntoskrnl.exe)

**Что делает Windows:**
1. Phase 0: базовая инициализация
2. Phase 1: загрузка Executive subsystems
3. Загрузка SYSTEM hive
4. Загрузка boot-start драйверов
5. Запуск SMSS

**Что делает VladOS (kernel/src/main.rs):**
```
Phase 0:
  1. kernel/src/arch/x86_64/idt.rs
     - Инициализация IDT (Interrupt Descriptor Table)
     - Установка обработчиков:divide_error, debug, nmi, breakpoint,
       overflow, bound_range, invalid_opcode, device_not_available,
       double_fault, invalid_tss, segment_not_present,
       stack_segment_fault, general_protection, page_fault,
       x87_floating_point, alignment_check, machine_check,
       simd_floating_point, virtualization, security_exception
     - IRQ 0-15: keyboard, timer, cascade, COM1, hard disk
  
  2. kernel/src/arch/x86_64/gdt.rs
     - GDT: null, kernel code, kernel data, user code, user data, TSS
     - TSS: стеки для Ring 0-3, IST для double fault
  
  3. kernel/src/arch/x86_64/paging.rs
     - PML4 (4-level page tables)
     - Маппинг ядра (higher half: 0xFFFF_FFFF_8000_0000)
     - Identity map для первых 2MB
     - Page fault handler
  
  4. kernel/src/mm/physical.rs
     - Buddy allocator для физической памяти
     - Инициализация из UEFI memory map / E820

Phase 1:
  5. kernel/src/ob/mod.rs
     - Object Manager: создаёт \Device, \DosDevices, \BaseNamedObjects
     - Handle table для процесса System
  
  6. kernel/src/mm/mod.rs + virt.rs
     - Memory Manager: VAD (Virtual Address Descriptor) дерево
     - VirtualAlloc/VirtualFree
     - Page fault handler
     - Working set management
  
  7. kernel/src/ps/mod.rs
     - Process Manager: создаёт System процесс (PID=4)
     - EPROCESS, ETHREAD, TEB, PEB
     - Creates first thread (System)
  
  8. kernel/src/se/mod.rs
     - Security: SID, ACL, Token
     - System token для System process
  
  9. kernel/src/cm/mod.rs
     - Configuration Manager: загружает SYSTEM hive
     - HKLM\SYSTEM, HKLM\SOFTWARE, HKLM\BCD
     - System Control Sets (ControlSet001, ControlSet002)
  
  10. kernel/src/io/mod.rs
      - I/O Manager: IRP subsystem
      - Device objects, driver objects
      - IrpCreate, IrpRead, IrpWrite, IrpDeviceControl
  
  11. kernel/src/ldr/mod.rs
      - PE Loader: загружает boot drivers
      - Resolves imports (VLL → kernel)
      - Applies relocations
  
  12. kernel/src/kernel/scheduler.rs
      - Системный планировщик (CFS-like)
      - APC/DPC queues
  
  13. kernel/src/kernel/dispatcher.rs
      - Kernel dispatcher: events, mutants, semaphores
      - Wait functions
  
  14. kernel/src/hal/mod.rs
      - HAL: hardware abstraction
      - Timer (HPET/APIC)
      - DMA management
      - Bus abstraction (PCI)
      - ACPI tables
```

**Boot drivers (загружаются первыми):**
```
kernel/src/drivers/disk/ahci.rs    — AHCI (SATA) controller
kernel/src/drivers/disk/nvme.rs    — NVMe controller
kernel/src/drivers/pnp/pci.rs      — PCI bus enumerator
kernel/src/drivers/acpi/acpi.rs    — ACPI driver
```

**System drivers (загружаются после System):**
```
kernel/src/drivers/disk/usb_storage.rs  — USB mass storage
kernel/src/drivers/net/e1000.rs         — Intel Ethernet
kernel/src/drivers/net/rtl8169.rs       — Realtek Ethernet
kernel/src/drivers/usb/ehci.rs          — USB 2.0
kernel/src/drivers/usb/xhci.rs          — USB 3.0
kernel/src/drivers/gpu/intel_gpu.rs     — Intel GPU
```

---

### Фаза 2: Session Manager (smss.exe)

**Что делает Windows:**
1. Создаёт pagefile.sys
2. Устанавливает переменные окружения
3. Создаёт device mappings (CON, NUL, COM1-4, drive letters)
4. Запускает csrss.exe + wininit.exe

**Что делает VladOS (userspace/system/vladss/):**
```
userspace/system/vladss/src/main.rs
  1. Читает реестр:
     - HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Memory Management
       → CreatePageFile (pagefile.sys)
     - HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment
       → Path, SystemRoot, ComSpec
     - HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\DOS Devices
       → CON: → \Device\Keyboard, NUL: → \Device\Null
       → C: → \Device\HarddiskVolume1, D: → \Device\HarddiskVolume2

  2. Запускает подсистемы (Session 0):
     ntdll::NtCreateUserProcess("csrss.vex")
     ntdll::NtCreateUserProcess("wininit.vex")
  
  3. Ожидает завершения csrss/wininit
     - Если процесс завершился → перезагрузка
     - Если завис → BugCheck (BSOD)
```

---

### Фаза 3: WinInit (wininit.exe)

**Что делает Windows:**
1. Запускает services.exe (SCM)
2. Запускает lsass.exe (LSA)
3. Запускает lsm.exe (Local Session Manager)

**Что делает VladOS (userspace/system/wininit/):**
```
userspace/system/wininit/src/main.rs
  1. ntdll::NtCreateUserProcess("services.vex")
     → Service Control Manager
     → Читает HKLM\SYSTEM\CurrentControlSet\Services
     → Запускает Auto-start сервисы
     → Запускает Delayed-start сервисы
  
  2. ntdll::NtCreateUserProcess("lsass.vex")
     → Local Security Authority
     → Аутентификация, безопасность
  
  3. ntdll::NtCreateUserProcess("lsm.vex")
     → Local Session Manager
     → Управление терминальными сессиями
```

---

### Фаза 4: Session 1 (User Login)

**Что делает Windows:**
1. SMSS создаёт новую сессию
2. CSRSS запускается для Session 1
3. Winlogon показывает LogonUI
4. Пользователь вводит credentials
5. LSA проверяет учётную запись
6. Создаётся access token
7. Winlogon запускает userinit.exe

**Что делает VladOS (userspace/system/winlogon/):**
```
userspace/system/winlogon/src/main.rs
  1. SMSS создаёт Session 1
     ntdll::NtCreateUserProcess("csrss.vex") — для Session 1
     ntdll::NtCreateUserProcess("winlogon.vex") — для Session 1
  
  2. WinLogon показывает экран входа
     → LogonUI.exe
     → Credential providers (password, PIN)
  
  3. Пользователь вводит credentials
     → Проверка через lsass.exe
     → Создание access token
  
  4. WinLogon запускает shell
     ntdll::NtCreateUserProcess("userinit.vex")
```

---

### Фаза 5: Userinit + Explorer (Shell)

**Что делает Windows:**
1. userinit.exe выполняет скрипты из реестра
2. userinit.exe запускает explorer.exe
3. Explorer загружает рабочий стол, панель задач
4. Автозагрузка (Run keys, Startup folders)

**Что делает VladOS (userspace/desktop/):**
```
userspace/desktop/userinit/src/main.rs
  1. Читает реестр:
     HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\Userinit
     → Выполняет скрипты
  
  2. ntdll::NtCreateUserProcess("explorer.vex")
     → Главная оболочка

userspace/desktop/explorer/src/main.rs
  1. Создаёт Desktop window
     user32::CreateWindowExW("Desktop", WS_POPUP)
     → Фоновое изображение
     → Иконки на рабочем столе
  
  2. Создаёт Taskbar
     user32::CreateWindowExW("Shell_TrayWnd", WS_EX_APPWINDOW)
     → Кнопка "Пуск"
     → Панель задач
     → Системный трей
     → Часы
  
  3. Создаёт Start Menu
     user32::CreateWindowExW("DVNStartMenuWnd", WS_POPUP)
  
  4. Запускает автозагрузку
     HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
     HKCU\SOFTWARE\Microsoft\Windows\CurrentVersion\Run
  
  5. Главный цикл сообщений
     loop {
         user32::GetMessage(&mut msg, null_mut(), 0, 0);
         user32::TranslateMessage(&msg);
         user32::DispatchMessage(&msg);
     }
```

---

### Фаза 6: System Services

**Что делает Windows:**
1. services.exe (SCM) управляет службами
2. svchost.exe hosting DLL-сервисы
3. Запуск networking, audio, power, PnP сервисов

**Что делает VladOS (userspace/system/services/):**
```
userspace/system/services/src/main.rs
  1. Service Control Manager
     → Читает HKLM\SYSTEM\CurrentControlSet\Services
     → Управляет жизненным циклом сервисов
  
  2. svchost.exe (service host)
     → Host для DLL-сервисов
     → netsvcs, rpcss, termsvcs
  
  3. Сервисы:
     services.exe        — SCM
     svchost.exe         — Service host
     rpcss.exe           — RPC subsystem
     taskeng.exe         — Task Scheduler
     spoolsv.exe         — Print Spooler
     dhcp.exe            — DHCP client
     dns.exe             — DNS client
     mpssvc.exe          — Windows Firewall
     audiosrv.exe        — Audio service
     power.exe           — Power service
     plugplay.exe        — PnP service
```

---

### Фаза 7: Drivers (Kernel-Mode)

**Что делает Windows:**
- Bus drivers: ACPI, PCI, PnP
- Function drivers: storage, network, display
- Filter drivers: class filters
- Minifilters: file system filters

**Что делает VladOS (kernel/src/drivers/):**
```
Bus drivers:
  kernel/src/drivers/pnp/pci.rs       — PCI bus enumerator
  kernel/src/drivers/acpi/acpi.rs     — ACPI driver
  kernel/src/drivers/pnp/pnp.rs       — PnP manager

Storage drivers:
  kernel/src/drivers/disk/ahci.rs     — AHCI (SATA)
  kernel/src/drivers/disk/nvme.rs     — NVMe
  kernel/src/drivers/disk/usb_storage.rs — USB mass storage
  kernel/src/drivers/disk/partition.rs — Partition manager

Display drivers:
  kernel/src/drivers/gpu/intel_gpu.rs — Intel integrated GPU
  kernel/src/drivers/bootvid.rs       — Boot video (VGA/VESA)

Network drivers:
  kernel/src/drivers/net/e1000.rs     — Intel Ethernet
  kernel/src/drivers/net/rtl8169.rs   — Realtek Ethernet
  kernel/src/drivers/net/ndis.rs      — NDIS wrapper

USB drivers:
  kernel/src/drivers/usb/ehci.rs      — USB 2.0
  kernel/src/drivers/usb/xhci.rs      — USB 3.0
  kernel/src/drivers/usb/usbhid.rs    — USB HID (keyboard/mouse)
  kernel/src/drivers/usb/usbd.rs      — USB hub driver

Input drivers:
  kernel/src/drivers/keyboard.rs      — PS/2 keyboard
  kernel/src/drivers/mouse.rs         — PS/2 mouse
```

---

### Фаза 8: Win32 Subsystem

**Что делает Windows:**
1. CSRSS загружает Win32 subsystem
2. Win32k.sys (kernel-mode Windows)
3. User32.dll, GDI32.dll (user-mode)

**Что делает VladOS:**
```
Kernel-mode:
  kernel/src/display/compositor.rs    — Desktop Window Manager
  kernel/src/display/surface.rs       — Surface management
  kernel/src/display/cursor.rs        — Cursor management
  kernel/src/display/font.rs          — Font rendering
  kernel/src/console.rs               — Console subsystem

User-space libraries:
  userspace/ntdll/src/lib.rs          — Nt* syscall stubs
  userspace/kernel32/src/lib.rs       — CreateFile, VirtualAlloc, etc.
  userspace/user32/src/lib.rs         — CreateWindowEx, GetMessage, etc.
  userspace/gdi32/src/lib.rs          — CreatePen, BitBlt, TextOut
```

---

### Полная последовательность загрузки

```
Время    | Компонент           | Описание
---------|---------------------|-------------------------------------------
0.0 сек  | Firmware            | POST / UEFI SEC+PEI+DXE
0.5 сек  | Boot Manager        | BIOS: NTLDR/BOOTMGR | UEFI: bootmgfw.efi
1.0 сек  | OS Loader           | winload.exe / winload.efi
1.5 сек  | Kernel Phase 0      | IDT, GDT, paging, buddy allocator
2.0 сек  | Kernel Phase 1      | Executive subsystems, drivers
3.0 сек  | System Process      | PID=4, kernel threads
4.0 сек  | SMSS                | pagefile, env vars, device mappings
4.5 sec  | CSRSS (Session 0)   | Win32 subsystem
4.5 sec  | WinInit             | starts services.exe, lsass.exe
5.0 sec  | Services            | Auto-start services
5.5 sec  | SMSS (Session 1)    | User session
6.0 sec  | WinLogon            | Login screen (LogonUI)
  ...    | User Login          | Credentials, token creation
  ...    | Userinit            | User scripts
  ...    | Explorer            | Desktop, Taskbar, Start Menu
  ...    | Auto-start          | Startup programs
```

---

## Что реализовано в VladOS

### Ядро (90%)
- Object Manager (NT namespace, handles)
- Process Manager (EPROCESS, ETHREAD, TEB, PEB)
- Memory Manager (VAD, virtual alloc, sections, pool)
- I/O Manager (IRP, device/driver objects)
- Security (SIDs, ACLs, tokens)
- Configuration Manager (registry hive)
- PE Loader (VladaPE format)
- NT Syscalls (30+ Nt* functions)

### Загрузчик (100%)
- UEFI bootloader (boot screen, GOP)
- BIOS bootloader (MBR, Stage 1-3)
- GPT parser
- VladFS driver in bootloader

### Userspace (40%)
- ntdll.vll (syscall stubs)
- kernel32.vll (Win32 API)
- user32.vll (window API)
- gdi32.vll (GDI API)
- cmd.vex (command shell)

---

## Что нужно реализовать (полная загрузка Windows)

### 1. SMSS (Session Manager) — userspace/system/vladss/
```
[] Создание pagefile.sys (NTFS-like файл)
[] Переменные окружения (Path, SystemRoot)
[] Device mappings (CON, NUL, COM1-4, drive letters)
[] PendingFileRenameOperations
[] Запуск csrss.exe (Session 0)
[] Запуск wininit.exe (Session 0)
[] Создание Session 1 (новый SMSS)
[] Запуск csrss.exe (Session 1)
[] Запуск winlogon.exe (Session 1)
[] Мониторинг csrss/winlogon → BugCheck если завершились
```

### 2. WinInit (Windows Startup) — userspace/system/wininit/
```
[] Запуск services.exe (SCM)
[] Запуск lsass.exe (LSA)
[] Запуск lsm.exe (Local Session Manager)
[] Обработка ошибок запуска
```

### 3. WinLogon (Login) — userspace/system/winlogon/
```
[] LogonUI.exe (экран входа)
[] Credential providers (password, PIN)
[] Аутентификация через lsass.exe
[] Создание access token
[] Управление сессиями (Session 0, 1, 2...)
[] Запуск userinit.exe
```

### 4. Services (Service Control Manager) — userspace/system/services/
```
[] services.exe (SCM)
[] svchost.exe (service host)
[] Управление жизненным циклом сервисов
[] Auto-start, Delayed-start, Boot, System, Manual, Disabled
[] Recovery options (Restart service, Restart computer)
[] Сервисы:
    - rpcss.exe (RPC subsystem)
    - taskeng.exe (Task Scheduler)
    - spoolsv.exe (Print Spooler)
    - dhcp.exe (DHCP client)
    - dns.exe (DNS client)
    - mpssvc.exe (Windows Firewall)
    - audiosrv.exe (Audio service)
    - power.exe (Power service)
    - plugplay.exe (PnP service)
```

### 5. CSRSS (Client/Server Runtime) — userspace/system/csrss/
```
[] Win32 subsystem initialization
[] Console management
[] Process/thread creation support
[] Exception handling
[] Остальная часть Win32 API в user-mode
```

### 6. LSA (Local Security Authority) — userspace/system/lsass/
```
[] Аутентификация (MSV1_0, Kerberos)
[] Security policies
[] Auditing
[] Trust relationships
[] Доменная аутентификация
```

### 7. Drivers (Kernel-Mode)
```
Bus drivers:
  [] PCI bus enumerator (kernel/src/drivers/pnp/pci.rs)
  [] ACPI driver (kernel/src/drivers/acpi/acpi.rs)
  [] PnP manager

Storage:
  [] AHCI (kernel/src/drivers/disk/ahci.rs)
  [] NVMe (kernel/src/drivers/disk/nvme.rs)
  [] USB mass storage (kernel/src/drivers/disk/usb_storage.rs)
  [] Partition manager
  [] Volume manager

Display:
  [] Intel GPU (kernel/src/drivers/gpu/intel_gpu.rs)
  [] Boot video (VGA/VESA)

Network:
  [] Intel e1000 (kernel/src/drivers/net/e1000.rs)
  [] Realtek RTL8169 (kernel/src/drivers/net/rtl8169.rs)
  [] NDIS wrapper

USB:
  [] USB 2.0 (EHCI)
  [] USB 3.0 (xHCI)
  [] USB HID (keyboard/mouse)
  [] USB hub driver

Input:
  [] PS/2 keyboard
  [] PS/2 mouse
```

### 8. Userinit + Explorer
```
[] userinit.exe (scripts, startup programs)
[] explorer.vex (оболочка):
    - Desktop window
    - Taskbar (Shell_TrayWnd)
    - Start Menu (DVNStartMenuWnd)
    - System tray
    - Clock
    - Show Desktop button
    - File Explorer
    - Context menus
```

### 9. Desktop Applications
```
[] notepad.vex (Блокнот)
[] calc.vex (Калькулятор)
[] mspaint.vex (Paint)
[] osk.vex (Экранная клавиатура)
[] explorer.vex (Проводник)
[] taskmgr.vex (Диспетчер задач)
```

---

## Компоненты загрузки (связь с Windows)

| Компонент Windows | VladOS Equivalent | Тип |
|-------------------|-------------------|-----|
| ntoskrnl.exe | kernel (Rust) | Kernel |
| hal.dll | kernel/src/hal/ | Kernel |
| smss.exe | userspace/system/vladss/ | User-mode |
| csrss.exe | userspace/system/csrss/ | User-mode |
| wininit.exe | userspace/system/wininit/ | User-mode |
| services.exe | userspace/system/services/ | User-mode |
| lsass.exe | userspace/system/lsass/ | User-mode |
| lsm.exe | userspace/system/lsm/ | User-mode |
| winlogon.exe | userspace/system/winlogon/ | User-mode |
| logonui.exe | userspace/system/logonui/ | User-mode |
| userinit.exe | userspace/desktop/userinit/ | User-mode |
| explorer.exe | userspace/desktop/explorer/ | User-mode |
| svchost.exe | userspace/system/svchost/ | User-mode |
| taskeng.exe | userspace/system/taskeng/ | User-mode |
| cmd.exe | userspace/cmd/ | User-mode |
| notepad.exe | userspace/desktop/notepad/ | User-mode |
| calc.exe | userspace/desktop/calc/ | User-mode |

---

## Порядок реализации

```
Фаза 2 (Bootloader)
    ↓
Фаза 1 (Ядро)
    ↓
Фаза 3 (VladFS)
    ↓
Фаза 4 (Консоль)
    ↓
Фаза 5 (Графика)
    ↓
Фаза 6 (Win32 API)
    ↓
Фаза 7 (PE Loader)
    ↓
Фаза 8 (Реестр)
    ↓
Фаза 9 (Драйверы)
    ↓
Фаза 10 (Сервисы)
    ↓
Фаза 11 (Desktop)
    ↓
Фаза 12 (Тестирование)
```

---

## Команды сборки

```bash
# Bootloader (UEFI)
cd bootloader_new
make qemu-uefi

# Bootloader (BIOS)
cd bootloader_new
make qemu-bios

# Ядро
cd kernel
make ARCH=x86_64 all
make ARCH=x86_64 test

# Полная ISO
cd ..
make iso
```

---

## Требования

- Rust nightly (с `-Z build-std`)
- NASM (для BIOS assembly)
- QEMU (для тестирования)
- xorriso (для ISO)
- mtools (для FAT/ESP)
- parted (для GPT)

---

*Последнее обновление: 8 Сентябрь 2026*
