#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/workspaces/Vladaero/vlados"
BOOTLOADER_EFI="$REPO_DIR/components/bootloader/bootloader.efi"
KERNEL_ELF="$REPO_DIR/components/kernel/build/x86_64/kernel_stripped.elf"
OUTPUT_IMG="$REPO_DIR/build/vlados.img"
OUTPUT_ISO="$REPO_DIR/build/vlados.iso"
WEB_INDEX="$REPO_DIR/web/index.html"

mkdir -p "$REPO_DIR/build"

if [ ! -f "$BOOTLOADER_EFI" ]; then
    echo "Error: $BOOTLOADER_EFI not found! Build bootloader first."
    exit 1
fi
if [ ! -f "$KERNEL_ELF" ]; then
    echo "Error: $KERNEL_ELF not found! Build kernel first."
    exit 1
fi

MKFS_VLADFS="$REPO_DIR/components/vladfs/target/release/mkfs_vladfs"
SYSROOT_DIR="$REPO_DIR/build/sysroot"
VLADFS_IMG="$REPO_DIR/build/vladfs.img"

if [ ! -f "$MKFS_VLADFS" ]; then
    echo "Compiling mkfs_vladfs..."
    cargo build --manifest-path "$REPO_DIR/components/vladfs/Cargo.toml" --release
fi

echo "1. Building VladOS Binaries (cmd, explorer, vladinit, notepad, and calc)..."
RUSTFLAGS="-C relocation-model=static -C link-arg=-T$REPO_DIR/components/cmd/link.ld -C link-arg=-z -C link-arg=max-page-size=0x1000" \
    cargo build --manifest-path "$REPO_DIR/components/cmd/Cargo.toml" --target x86_64-unknown-none --release
CMD_BIN="$REPO_DIR/components/cmd/target/x86_64-unknown-none/release/cmd_bin"

RUSTFLAGS="-C relocation-model=static -C link-arg=-T$REPO_DIR/components/explorer/link.ld -C link-arg=-z -C link-arg=max-page-size=0x1000" \
    cargo build --manifest-path "$REPO_DIR/components/explorer/Cargo.toml" --target x86_64-unknown-none --release
EXPLORER_BIN="$REPO_DIR/components/explorer/target/x86_64-unknown-none/release/explorer_bin"

RUSTFLAGS="-C relocation-model=static -C link-arg=-T$REPO_DIR/components/vladinit/link.ld -C link-arg=-z -C link-arg=max-page-size=0x1000" \
    cargo build --manifest-path "$REPO_DIR/components/vladinit/Cargo.toml" --target x86_64-unknown-none --release
VLADINIT_BIN="$REPO_DIR/components/vladinit/target/x86_64-unknown-none/release/vladinit_bin"

RUSTFLAGS="-C relocation-model=static -C link-arg=-T$REPO_DIR/components/notepad/link.ld -C link-arg=-z -C link-arg=max-page-size=0x1000" \
    cargo build --manifest-path "$REPO_DIR/components/notepad/Cargo.toml" --target x86_64-unknown-none --release
NOTEPAD_BIN="$REPO_DIR/components/notepad/target/x86_64-unknown-none/release/notepad_bin"

RUSTFLAGS="-C relocation-model=static -C link-arg=-T$REPO_DIR/components/calc/link.ld -C link-arg=-z -C link-arg=max-page-size=0x1000" \
    cargo build --manifest-path "$REPO_DIR/components/calc/Cargo.toml" --target x86_64-unknown-none --release
CALC_BIN="$REPO_DIR/components/calc/target/x86_64-unknown-none/release/calc_bin"

echo "2. Preparing VladOS system root ($SYSROOT_DIR)..."
mkdir -p "$SYSROOT_DIR/VladOS/System32/config"
mkdir -p "$SYSROOT_DIR/VladOS/System32/drivers"
cp -r "$REPO_DIR/resources/drivers/"* "$SYSROOT_DIR/VladOS/System32/drivers/"
mkdir -p "$SYSROOT_DIR/VladOS/Resources"
cp -r "$REPO_DIR/resources/"* "$SYSROOT_DIR/VladOS/Resources/"
mkdir -p "$SYSROOT_DIR/Users/Default"
mkdir -p "$SYSROOT_DIR/Users/Vlad/Desktop"
mkdir -p "$SYSROOT_DIR/Users/Vlad/Documents"
mkdir -p "$SYSROOT_DIR/Users/Vlad/Downloads"
mkdir -p "$SYSROOT_DIR/Users/Vlad/Pictures"
mkdir -p "$SYSROOT_DIR/Users/Vlad/Music"
mkdir -p "$SYSROOT_DIR/Users/Vlad/Videos"
mkdir -p "$SYSROOT_DIR/Programs"

# Install binaries into /VladOS/System32/
cp "$VLADINIT_BIN" "$SYSROOT_DIR/VladOS/System32/vladinit.vex"
chmod +x "$SYSROOT_DIR/VladOS/System32/vladinit.vex"
cp "$EXPLORER_BIN" "$SYSROOT_DIR/VladOS/System32/explorer.vex"
chmod +x "$SYSROOT_DIR/VladOS/System32/explorer.vex"
cp "$CMD_BIN" "$SYSROOT_DIR/VladOS/System32/cmd.vex"
chmod +x "$SYSROOT_DIR/VladOS/System32/cmd.vex"
cp "$NOTEPAD_BIN" "$SYSROOT_DIR/VladOS/System32/notepad.vex"
chmod +x "$SYSROOT_DIR/VladOS/System32/notepad.vex"
cp "$CALC_BIN" "$SYSROOT_DIR/VladOS/System32/calc.vex"
chmod +x "$SYSROOT_DIR/VladOS/System32/calc.vex"
cp "$EXPLORER_BIN" "$SYSROOT_DIR/VladOS/System32/player.vex"
chmod +x "$SYSROOT_DIR/VladOS/System32/player.vex"
cp "$EXPLORER_BIN" "$SYSROOT_DIR/VladOS/System32/photos.vex"
chmod +x "$SYSROOT_DIR/VladOS/System32/photos.vex"

# Desktop Shortcuts (.lnk)
cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/Command Prompt.lnk"
[Shortcut]
Target=/VladOS/System32/cmd.vex
Icon=cmd
EOF

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/File Explorer.lnk"
[Shortcut]
Target=/VladOS/System32/explorer.vex
Icon=explorer
EOF

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/Notepad.lnk"
[Shortcut]
Target=/VladOS/System32/notepad.vex
Icon=notepad
EOF

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/Calculator.lnk"
[Shortcut]
Target=/VladOS/System32/calc.vex
Icon=calc
EOF

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/Media Player.lnk"
[Shortcut]
Target=/VladOS/System32/player.vex
Icon=player
EOF

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/Photos.lnk"
[Shortcut]
Target=/VladOS/System32/photos.vex
Icon=photos
EOF

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/Control Panel.lnk"
[Shortcut]
Target=ControlPanel
Icon=control
EOF

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/This PC.lnk"
[Shortcut]
Target=ThisPC
Icon=computer
EOF

# Install standard media files
cp "$REPO_DIR/resources/media/wallpaper.bmp" "$SYSROOT_DIR/Users/Vlad/Pictures/"
cp "$REPO_DIR/resources/media/logo.png" "$SYSROOT_DIR/Users/Vlad/Pictures/"
cp "$REPO_DIR/resources/media/photo.jpg" "$SYSROOT_DIR/Users/Vlad/Pictures/"
cp "$REPO_DIR/resources/media/ambient.mp3" "$SYSROOT_DIR/Users/Vlad/Music/"
cp "$REPO_DIR/resources/media/startup.wav" "$SYSROOT_DIR/Users/Vlad/Music/"
cp "$REPO_DIR/resources/media/sample.mp4" "$SYSROOT_DIR/Users/Vlad/Videos/"

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Desktop/Welcome.txt"
Welcome to VladOS 10 Professional!
- Modern Windows 10 Aero User Interface
- Real Hardware PCI and USB Bus Auto-detection (disks & flash drives)
- Notepad (notepad.vex), Calculator (calc.vex), Media Player, and Photos
- Dynamic Multi-Drive Storage: VladFS (C:), FAT32 (D:), ISO9660 (E:), USB Flash (U:)
EOF

cat << 'EOF' > "$SYSROOT_DIR/Users/Vlad/Documents/Notes.txt"
VladOS Project Notes:
All mock objects and stubs removed.
Real hardware storage scanning and dynamic VFS active.
Standard image and audio playback enabled.
EOF

cat << 'EOF' > "$SYSROOT_DIR/VladOS/System32/config/system.ini"
; VladOS Configuration
[System]
OSName=VladOS
Version=1.0.0
Architecture=x86_64
BuildDate=2026-09-05
Kernel=kernel.elf
Init=/VladOS/System32/vladinit.vex
Shell=/VladOS/System32/explorer.vex
Terminal=/VladOS/System32/cmd.vex
LogonUI=enabled
AccountPortal=https://vladinc.ru/vlados

[Executables]
Extension=.vex
BinaryFormat=ELF64

[Display]
BootAnimation=true
QuietBoot=true
DefaultResolution=1024x768x32
Theme=FluentDark

[Storage]
RootFS=VladFS
VolumeLabel=VLADOS_SYS
EOF

cat << 'EOF' > "$SYSROOT_DIR/VladOS/System32/config/account.cfg"
; VladOS Account Settings
[Account]
AllowOfflineLogin=true
WebPortalSync=true
AuthURL=https://vladinc.ru/vlados/api/auth
DefaultUser=Vlad
EOF

echo "3. Formatting VladFS partition image ($VLADFS_IMG)..."
"$MKFS_VLADFS" "$VLADFS_IMG" --size-mb 32 --label "VLADOS_SYS" --root-dir "$SYSROOT_DIR"

echo "3. Creating FAT32 EFI System Partition..."
ESP_TMP="$REPO_DIR/build/esp.img"
rm -f "$ESP_TMP" "$OUTPUT_IMG" "$OUTPUT_ISO"

truncate -s 64M "$ESP_TMP"
mkfs.vfat -F 32 -n "VLADOS" "$ESP_TMP"

mmd -i "$ESP_TMP" ::EFI
mmd -i "$ESP_TMP" ::EFI/BOOT
mcopy -o -i "$ESP_TMP" "$BOOTLOADER_EFI" ::EFI/BOOT/BOOTX64.EFI
mcopy -o -i "$ESP_TMP" "$KERNEL_ELF" ::kernel.elf
mcopy -o -i "$ESP_TMP" "$KERNEL_ELF" ::EFI/BOOT/kernel.elf
mcopy -o -i "$ESP_TMP" "$VLADFS_IMG" ::vladfs.img
echo -ne '\\EFI\\BOOT\\BOOTX64.EFI\r\n' > /tmp/startup.nsh
mcopy -o -i "$ESP_TMP" /tmp/startup.nsh ::startup.nsh

echo "4. Creating two-partition GPT disk image ($OUTPUT_IMG)..."
# 1MiB GPT header + 64MiB ESP + 32MiB VladFS + 1MiB backup GPT = 98MiB
truncate -s 98M "$OUTPUT_IMG"
parted -s "$OUTPUT_IMG" mklabel gpt
parted -s "$OUTPUT_IMG" mkpart ESP fat32 1MiB 65MiB
parted -s "$OUTPUT_IMG" set 1 esp on
parted -s "$OUTPUT_IMG" mkpart VLADOS_SYS 65MiB 97MiB

dd if="$ESP_TMP" of="$OUTPUT_IMG" bs=1M seek=1 conv=notrunc status=none
dd if="$VLADFS_IMG" of="$OUTPUT_IMG" bs=1M seek=65 conv=notrunc status=none

echo "5. Creating bootable UEFI ISO image ($OUTPUT_ISO)..."
ISO_ROOT="$REPO_DIR/build/iso_root"
rm -rf "$ISO_ROOT"
mkdir -p "$ISO_ROOT"
cp "$ESP_TMP" "$ISO_ROOT/EFI.IMG"
cp "$VLADFS_IMG" "$ISO_ROOT/VLADFS.IMG"

xorriso -as mkisofs \
    -iso-level 3 \
    -full-iso9660-filenames \
    -volid "VLADOS" \
    -eltorito-alt-boot \
    -e EFI.IMG \
    -no-emul-boot \
    -isohybrid-gpt-basdat \
    -o "$OUTPUT_ISO" \
    "$ISO_ROOT/" >/dev/null 2>&1

rm -rf "$ISO_ROOT" "$ESP_TMP"

echo "Build Artifacts:"
ls -lh "$OUTPUT_IMG" "$OUTPUT_ISO"

echo ""
echo "6. Syncing web portal to FTP (vladinc.ru/vlados)..."
python3 "$REPO_DIR/scripts/upload_ftp.py" "$WEB_INDEX" || true

echo "7. Uploading release ISO to GitHub Releases (vladiksicily1/vlados)..."
GITHUB_TOKEN="${VLADOS_GH_TOKEN:-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}" \
    gh release upload v1.0.0 "$OUTPUT_ISO" "$OUTPUT_IMG" --repo vladiksicily1/vlados --clobber || true
