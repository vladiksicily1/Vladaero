# VladOS 10 — Полное руководство для AI-агентов и разработчиков

> Документ предназначен для AI-ассистентов, контрибьюторов и системных инженеров. Содержит исчерпывающую информацию об архитектуре VladOS 10, подсистемах ядра, драйверах, дисковой подсистеме, системных вызовах, процессе сборки и методиках расширения ОС.

---

## 1. Обзор архитектуры VladOS

VladOS — это 64-битная независимая операционная система с графическим интерфейсом в стиле Windows 10 Aero / Fluent Design, построенная на базе микроядерных принципов (с корнями Redox OS) и кастомного пользовательского пространства.

### 1.1. Кольца защиты и модель изоляции
* **Ring 0 (Ядро)**:
  * Расположено в `components/kernel/`.
  * Реализует инициализацию процессора (GDT, IDT, пейджинг 4-уровневый x86_64, APIC), управление физической и виртуальной памятью, планировщик задач, переключение контекста, подсистему системных вызовов (`syscall`), сканирование шины PCI и драйвер файловой системы **VladFS**.
  * Точка входа ядра: `kstart` (загружается через UEFI bootloader).
* **Ring 3 (Пространство пользователя)**:
  * Процессы изолированы через виртуальное адресное пространство и сегменты Ring 3.
  * Первым процессом (PID 1) запускается супервизор инициализации: `/VladOS/System32/vladinit.vex`.
  * `vladinit.vex` инициализирует дескрипторы ввода-вывода и запускает оконный менеджер/композитор `dwm.vex` и графическую оболочку `explorer.vex`.
  * Все системные утилиты и прикладные программы компилируются как статические бинарники формата **VEX** (VladOS Executable, де-факто статический ELF64 со специальным скриптом линковки).

### 1.2. Процесс загрузки (Boot Flow)
1. **UEFI Firmware** загружает с FAT32 EFI-раздела (ESP) файл `\EFI\BOOT\BOOTX64.EFI` (`components/bootloader/`).
2. **Bootloader (`bootloader.efi`)**:
   * Настраивает видеорежим GOP (Graphics Output Protocol) — линейный фреймбуфер `1280x800x32 bpp` (адрес `0x8000_0000`).
   * Считывает карту памяти UEFI (`GetMemoryMap`).
   * Загружает ядро `kernel.elf` и рамдиск/образ корня `vladfs.img`.
   * Выходит из Boot Services (`ExitBootServices`) и передаёт управление ядру.
3. **Kernel Initialization**:
   * Настраивает структуры x86_64, аллокаторы памяти, драйверы прерываний.
   * Выполняет сканирование PCI-устройств (контроллеры накопителей IDE, SATA AHCI, NVMe, USB, VirtIO).
   * Монтирует корневую файловую систему VladFS (`C:\`).
   * Создаёт пространство Ring 3 и запускает `/VladOS/System32/vladinit.vex`.
4. **Userland Launch**:
   * `vladinit` -> `dwm.vex` (композитор окон) -> `explorer.vex` (рабочий стол, панель задач, This PC).

---

## 2. Структура файловой системы и пути

В ОС поддерживается как стиль путей DOS/Windows (`C:\VladOS\System32\...`), так и POSIX-стиль (`/VladOS/System32/...`). Пути без буквы диска или начинающиеся с `C:` автоматически адресуются к корневому тому **VladFS**.

```
C:\
├── VladOS\
│   ├── System32\
│   │   ├── vladinit.vex       # Системный супервизор (PID 1)
│   │   ├── dwm.vex            # Оконный менеджер и композитор (Desktop Window Manager)
│   │   ├── explorer.vex       # Графическая оболочка (Desktop, Taskbar, This PC)
│   │   ├── cmd.vex            # Командная строка Windows NT CMD
│   │   ├── diskpart.vex       # Консольная утилита разметки и управления дисками
│   │   ├── diskutil.vex       # Управление дисками (diskmgmt.msc / графическая таблица)
│   │   ├── format.vex         # Автономная утилита форматирования
│   │   ├── mount.vex          # Автономная утилита монтирования
│   │   ├── chkdsk.vex         # Проверка целостности файловой системы
│   │   ├── notepad.vex        # Текстовый редактор Блокнот
│   │   ├── calc.vex           # Калькулятор
│   │   ├── player.vex         # Медиаплеер с аудиосинтезом
│   │   ├── photos.vex         # Просмотр изображений (BMP/PNG/JPEG)
│   │   ├── pathedit.vex       # Редактор системных путей (PATH)
│   │   ├── whoami.vex         # Утилита отображения текущего пользователя
│   │   ├── sysinfo.vex        # Сведения о системе
│   │   ├── net.vex            # Сетевые службы и ping
│   │   ├── shutdown.vex       # Завершение работы и перезагрузка
│   │   │
│   │   ├── drivers\           # КАТАЛОГ СИСТЕМНЫХ ДРАЙВЕРОВ (.sys)
│   │   │   ├── drivers.ini    # Манифест и реестр конфигурации драйверов
│   │   │   ├── vladfs.sys     # Драйвер файловой системы VladFS
│   │   │   ├── fat32.sys      # Драйвер FAT12/16/32
│   │   │   ├── ext2.sys       # Драйвер Linux ext2/ext3/ext4
│   │   │   ├── iso9660.sys    # Драйвер оптических дисков CD/DVD
│   │   │   ├── ahci.sys       # Драйвер SATA AHCI Host Controller
│   │   │   ├── nvme.sys       # Драйвер NVM Express накопителей
│   │   │   ├── virtiovga.sys  # Видеодрайвер ускоренного фреймбуфера VirtIO
│   │   │   ├── usbtablet.sys  # Драйвер абсолютного указателя USB HID
│   │   │   ├── ps2mouse.sys   # Драйвер мыши PS/2
│   │   │   └── acpi.sys       # Драйвер управления питанием ACPI
│   │   │
│   │   └── config\
│   │       ├── system.ini     # Системная конфигурация ОС (версия, тема, службы)
│   │       ├── path.cfg       # Список путей переменной PATH
│   │       └── account.cfg    # Настройки учетных записей пользователей
│   │
│   └── Resources\
│       ├── Fonts\
│       │   └── CascadiaMono.fnt  # Сглаженный системный шрифт Cascadia Code
│       ├── Themes\
│       └── Icons\
│
├── Users\
│   ├── Default\
│   └── Vlad\
│       ├── Desktop\           # Рабочий стол (.lnk ярлыки программ)
│       ├── Documents\         # Документы пользователя
│       ├── Downloads\         # Загрузки
│       ├── Pictures\          # Изображения и обои
│       ├── Music\             # Аудиозаписи
│       └── Videos\            # Видеозаписи
│
└── Programs\                  # Каталог установки сторонних программ
```

---

## 3. Подсистема драйверов (`System32/drivers/*.sys`)

В полном соответствии с архитектурой VladOS все драйверы располагаются в каталоге `C:\VladOS\System32\drivers` в виде исполняемых модулей с расширением `.sys`.

### 3.1. Манифест `drivers.ini`
Каталог содержит файл манифеста `drivers.ini`, регламентирующий типы драйверов, их классы и порядок загрузки:
* `Class=Storage`: `ahci.sys`, `nvme.sys`
* `Class=FileSystem`: `vladfs.sys`, `fat32.sys`, `ext2.sys`, `iso9660.sys`
* `Class=Display`: `virtiovga.sys`
* `Class=Input`: `usbtablet.sys`, `ps2mouse.sys`
* `Class=System`: `acpi.sys`
* `Start`: `Boot` (инициализируются ядром при старте) либо `System` (динамическая загрузка).

### 3.2. Аппаратное сканирование шин PCI / Storage
Ядро опрашивает конфигурационное пространство PCI (порты I/O `0xCF8` / `0xCFC`) в реальном времени (`scan_pci_storage()` в `components/kernel/src/vladfs_mount.rs`):
* Класс `0x01` (Mass Storage):
  * Subclass `0x01`: IDE Controller
  * Subclass `0x06`: SATA Controller (AHCI)
  * Subclass `0x08`: NVMe Controller
  * Subclass `0x00`: SCSI Bus Controller
* Класс `0x0C` (Serial Bus):
  * Subclass `0x03`: USB Host Controller (xHCI/EHCI/UHCI)
* Vendor `0x1AF4`: VirtIO Block / SCSI

---

## 4. Дисковая подсистема и динамические буквы дисков

### 4.1. Реальная геометрия накопителей (без фейковых/статических данных)
* **Диск 0 (Boot Disk)**:
  * Ёмкость вычисляется на основе реальной разметки диска: 64 МБ (EFI System Partition) + 32 МБ (VladFS) + 2 МБ (GPT служебные заголовки) = **98 МБ**.
  * Свободное место и объём раздела C: читаются из реального суперблока файловой системы VladFS (`vfs.total_mb()` и `vfs.free_mb()`).
  * Фактический тип контроллера определяется сканированием PCI (например, `PCI 00:01.1 IDE Primary (Boot)` или `PCI AHCI SATA`).
* **Том 0 (Volume 0)**:
  * Всегда получает статус `System (Boot)` и букву `C:`.
  * Метка тома: `VLADOS_SYS`, файловая система: `VladFS`.

### 4.2. Динамическое выделение букв дисков (Dynamic Drive Letter Assignment)
В ядре реализован динамический пул букв:
`DRIVE_LETTER_CANDIDATES = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z']`.

* При монтировании нового тома (`mount`) или подключении накопителя, если буква не указана явно или задано `drive=auto`:
  * Ядро находит первую свободную букву из списка.
  * Буква динамически присваивается созданному тому.
* При размонтировании тома (`unmount`):
  * Томовая запись освобождается.
  * Буква немедленно возвращается в пул свободных букв и может быть заново назначена следующим операциям.

### 4.3. Управление дисками внутри `diskpart` и `diskutil`
Обе утилиты имеют встроенную прямую поддержку операций `format` и `mount`:

#### `diskpart.vex`:
* `LIST DISK` — список физических дисков и контроллеров.
* `LIST VOLUME` — список логических томов с их динамическими буквами, метками, ФС и статусом.
* `SELECT DISK <n>` / `SELECT VOLUME <n>`.
* `FORMAT [drive=<LTR>] [fs=<FS>] [label=<LABEL>] [quick]` — прямое форматирование указанного или выбранного тома (поддерживаются `VladFS`, `FAT32`, `exFAT`, `NTFS`, `ext4`).
* `MOUNT [letter=<auto|LTR>] [disk=<n>] [size=<MB>] [fs=<FS>] [label=<LABEL>]` — прямое монтирование нового логического тома с динамическим или фиксированным назначением буквы.
* `ASSIGN [letter=<LTR>]` — привязка буквы к тому.
* `REMOVE [letter=<LTR>]` / `UNMOUNT [letter=<LTR>]` — размонтирование и освобождение буквы.
* `RESCAN` — аппаратное пересканирование шины PCI.

#### `diskutil.vex` (diskmgmt.msc):
* Графическое ASCII-представление разделов и динамических дисков в реальном времени.
* `format <drive> [fs] [label]` — форматирование тома.
* `mount [drive|auto] [size_mb] [fs] [label] [layout]` — создание и монтирование тома.
* `unmount <drive>` — размонтирование.
* `extend <drive> <size_mb>` / `shrink <drive> <size_mb>`.
* `rescan` — аппаратный опрос шин.

#### Интеграция в Проводник (`explorer.vex`):
* В режиме **"This PC"** проводник опрашивает ядро через системный вызов `sys_drives(0x5652)`.
* Динамически генерируются интерактивные карточки для каждого активного диска с его буквой (`C:`, `D:`, `E:`...), меткой тома, типом ФС и индикатором заполненности.
* Двойной клик на карточку открывает корень соответствующего диска.

---

## 5. Справочник системных вызовов (Kernel Syscalls)

Все системные вызовы осуществляются через инструкцию x86_64 `syscall`. Аргументы передаются в регистрах: `rax` (номер вызова), `rdi` (arg 1), `rsi` (arg 2), `rdx` (arg 3), `r10` (arg 4), `r8` (arg 5), `r9` (arg 6).

| Syscall | Hex ID | Название | Назначение |
|---|---|---|---|
| `SYS_WRITE` | `0x21000004` | Запись в дескриптор | Вывод в STDOUT (fd=1), STDERR (fd=2) |
| `SYS_READ` | `0x22000003` | Чтение из дескриптора | Ввод с STDIN (fd=0) |
| `SYS_FCNTL` | `0x20000037` | Управление дескриптором | Настройка `O_NONBLOCK` для неблокирующего ввода |
| `SYS_YIELD` | `158` | Передача кванта времени | Кооперативная уступка планировщику CPU |
| `SYS_NANOSLEEP` | `0x01000010` | Сон | Приостановка потока на заданное время |
| `SYS_VLADOS_READ` | `0x5646` | Чтение файла VladFS | Считывание байт из файла по пути |
| `SYS_VLADOS_WRITE` | `0x5647` | Запись файла VladFS | Создание или перезапись файла |
| `SYS_VLADOS_MKDIR` | `0x5648` | Создание директории | Создание узла каталога |
| `SYS_VLADOS_UNLINK` | `0x5649` | Удаление файла | Удаление записи из директории |
| `SYS_VLADOS_LIST` | `0x564A` | Список каталога | Возврат списка файлов и подпапок с признаком `is_dir` |
| `SYS_VLADOS_WHOAMI` | `0x564B` | Текущий пользователь | Возврат имени активной учетной записи |
| `SYS_VLADOS_SU` | `0x564C` | Смена пользователя | Переключение контекста UID |
| `SYS_VLADOS_RENAME` | `0x564D` | Переименование | Перемещение/переименование файла или папки |
| `SYS_VLADOS_CHMOD` | `0x564E` | Права и атрибуты | Установка прав доступа и флагов (hidden/readonly) |
| `SYS_VLADOS_BEEP` | `0x5650` | PC Speaker Beep | Генерация звука (частота Hz, длительность ms) |
| `SYS_VLADOS_POWER` | `0x5651` | Управление питанием | Выключение (1) / Перезагрузка (2) через ACPI/i8042 |
| `SYS_VLADOS_DRIVES` | `0x5652` | Сведения о дисках | Чтение таблицы `=== DISKS ===` и `=== VOLUMES ===` |
| `SYS_VLADOS_RESCAN` | `0x5653` | Пересканирование дисков | Принудительный опрос контроллеров накопителей |
| `SYS_VLADOS_DISK_OP` | `0x5654` | Дисковые операции | Исполнение `format`, `mount`, `unmount`, `extend`, `shrink` |

---

## 6. Как компилировать и собирать систему

### 6.1. Необходимый инструментарий
* Rust (nightly или stable) с установленным таргетом:
  ```bash
  rustup target add x86_64-unknown-none
  ```
* Системные утилиты сборки образов:
  ```bash
  sudo apt-get update && sudo apt-get install -y \
      parted dosfstools mtools xorriso nasm qemu-system-x86 ovmf
  ```

### 6.2. Компиляция ядра
Ядро собирается через собственный Makefile с поддержкой кросс-платформенного `objcopy`:
```bash
make -C components/kernel all OBJCOPY=objcopy
```
Результаты сборки:
* `components/kernel/build/x86_64/kernel` — отладочный ELF ядра
* `components/kernel/build/x86_64/kernel_stripped.elf` — стрипнутый ELF ядра

### 6.3. Компиляция прикладных программ (`.vex`)
Каждая программа в `components/` собирается со статическим скриптом линковки `link.ld` и размером страницы `0x1000`:
```bash
RUSTFLAGS="-C relocation-model=static -C link-arg=-T$PWD/components/<name>/link.ld -C link-arg=-z -C link-arg=max-page-size=0x1000" \
    cargo build --manifest-path "$PWD/components/<name>/Cargo.toml" --target x86_64-unknown-none --release
```

### 6.4. Полная сборка ISO и образов диска
Сборка полностью автоматизирована скриптом:
```bash
./scripts/build_img.sh
```
Скрипт выполняет:
1. Компиляцию утилиты `mkfs_vladfs` для хоста.
2. Компиляцию всех бинарников `components/*` под `x86_64-unknown-none`.
3. Копирование файлов в корневую файловую систему `$SYSROOT_DIR`:
   * Драйверы `.sys` и `drivers.ini` в `/VladOS/System32/drivers/`.
   * Бинарники в `/VladOS/System32/*.vex`.
   * Ресурсы, шрифты, медиафайлы, ярлыки рабочего стола.
4. Форматирование корневого раздела `vladfs.img` через `mkfs_vladfs`.
5. Создание загрузочного раздела ESP `esp.img` с `bootloader.efi` и `kernel.elf`.
6. Сборку GPT-образа `build/vlados.img` (98 МБ: ESP + VladFS).
7. Сборку загрузочного гибридного ISO `build/vlados.iso`.

### 6.5. Запуск в эмуляторе QEMU
```bash
./scripts/run_qemu.sh
```
Либо напрямую:
```bash
qemu-system-x86_64 \
    -enable-kvm -m 2048 -smp 4 \
    -bios /usr/share/ovmf/OVMF.fd \
    -drive file=build/vlados.img,format=raw \
    -vga std \
    -device nec-usb-xhci \
    -device usb-tablet
```

---

## 7. Руководство: как добавлять новое в ОС

### 7.1. Как добавить новую пользовательскую программу (App / Command)
1. **Создайте каталог компонента**:
   Создайте `components/<app_name>/` со следующими файлами:
   * `Cargo.toml`:
     ```toml
     [package]
     name = "<app_name>"
     version = "1.0.0"
     edition = "2021"

     [[bin]]
     name = "<app_name>_bin"
     path = "src/main.rs"

     [profile.release]
     opt-level = "z"
     lto = true
     panic = "abort"
     codegen-units = 1
     ```
   * `link.ld`:
     ```ld
     ENTRY(_start)
     SECTIONS {
         . = 0x400000;
         .text : { *(.text .text.*) }
         .rodata : { *(.rodata .rodata.*) }
         .data : { *(.data .data.*) }
         .bss : { *(.bss .bss.*) }
     }
     ```
   * `src/main.rs`:
     ```rust
     #![no_std]
     #![no_main]

     use core::panic::PanicInfo;

     #[inline(always)]
     unsafe fn sys_write(fd: usize, data: &[u8]) -> usize {
         let ret: usize;
         core::arch::asm!(
             "syscall",
             inlateout("rax") 0x21000004usize => ret,
             in("rdi") fd,
             in("rsi") data.as_ptr(),
             in("rdx") data.len(),
             out("rcx") _,
             out("r11") _,
             options(nostack)
         );
         ret
     }

     #[no_mangle]
     pub extern "C" fn _start() -> ! {
         let msg = b"Hello from new VladOS App!\r\n";
         unsafe { sys_write(1, msg) };
         loop {
             core::arch::asm!("syscall", in("rax") 158usize, out("rcx") _, out("r11") _, options(nostack));
         }
     }

     #[panic_handler]
     fn panic(_info: &PanicInfo) -> ! {
         loop {}
     }
     ```
2. **Зарегистрируйте в `scripts/build_img.sh`**:
   * Добавьте шаг компиляции `cargo build ...` с соответствующим `RUSTFLAGS`.
   * Добавьте копирование:
     ```bash
     cp "$APP_BIN" "$SYSROOT_DIR/VladOS/System32/<app_name>.vex"
     chmod +x "$SYSROOT_DIR/VladOS/System32/<app_name>.vex"
     ```
   * При необходимости добавьте ярлык в `$SYSROOT_DIR/Users/Vlad/Desktop/<app_name>.lnk`.
3. **Регистрация в командной строке `cmd` и проводнике `explorer`**:
   * В `components/cmd/src/main.rs`: добавьте команду в диспетчер команд `execute_command()`.
   * В `components/explorer/src/main.rs`: добавьте пункт в Start Menu (`draw_start_menu()`).

### 7.2. Как добавить новый драйвер устройства (`.sys`)
1. **Файл драйвера**:
   * Разместите скомпилированный бинарник драйвера в `resources/drivers/<driver_name>.sys`.
2. **Регистрация в манифесте**:
   * Откройте `resources/drivers/drivers.ini` и добавьте секцию:
     ```ini
     [Driver.X]
     Name=<driver_name>.sys
     Class=Storage | FileSystem | Display | Input | Network | System
     Type=KernelMode
     Start=Boot | System
     DisplayName=My Hardware Driver
     Description=Description of the driver functionality
     ```
   * Увеличьте параметр `DriverCount` в секции `[Drivers]`.
3. **Ядерная реализация**:
   * Если драйвер требует прямого взаимодействия с портами ввода-вывода или памятью MMIO ядра, реализуйте обработчик в `components/kernel/src/vladfs_mount.rs` или соответствующем модуле устройств `components/kernel/src/devices/`.

### 7.3. Как добавить новый системный вызов
1. **В ядре (`components/kernel/src/syscall/mod.rs`)**:
   * Задайте константу вызова:
     ```rust
     pub const SYS_VLADOS_MYFEATURE: usize = 0x5657;
     ```
   * Добавьте ветку в диспетчер вызовов `dispatch_syscall()`:
     ```rust
     SYS_VLADOS_MYFEATURE => {
         // Реализация логики ядра
         0
     }
     ```
2. **В пространстве пользователя**:
   * Оберните вызов в функцию с ассемблерной вставкой:
     ```rust
     const SYS_VLADOS_MYFEATURE: usize = 0x5657;

     #[inline(always)]
     unsafe fn sys_myfeature(arg1: usize) -> usize {
         let ret: usize;
         core::arch::asm!(
             "syscall",
             inlateout("rax") SYS_VLADOS_MYFEATURE => ret,
             in("rdi") arg1,
             out("rcx") _,
             out("r11") _,
             options(nostack)
         );
         ret
     }
     ```

---

## 8. Важные соглашения и правила кодовой базы

1. **Никаких фиктивных заглушек и жестко закодированных данных о дисках**:
   Все данные о дисках, контроллерах, емкостях и томах должны формироваться динамически через опрос железа (PCI/VFS) и системный вызов `sys_drives(0x5652)`.
2. **Автоматическое динамическое именование томов**:
   Буквы вторичных томов назначаются строго динамически из пула `D:` ... `Z:`. При размонтировании буква должна освобождаться.
3. **Драйверы — это `.sys` в `System32/drivers`**:
   Все драйверы файловых систем и оборудования хранятся в `C:\VladOS\System32\drivers` и описаны в `drivers.ini`.
4. **Безопасность и `no_std`**:
   Все бинарники пространства пользователя компилируются с флагом `#![no_std]` и `#![no_main]`, не имеют стандартной библиотеки libc/libstd и общаются с внешним миром исключительно через `syscall`.
