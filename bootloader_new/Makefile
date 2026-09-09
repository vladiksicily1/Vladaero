# VladOS Bootloader Makefile
# Builds UEFI and BIOS bootloader images

# Directories
SRC_DIR = src
ASM_DIR = asm
BUILD_DIR = build
RES_DIR = res
LINKER_DIR = linkers

# Tools
CARGO = cargo
NASM = nasm
LLVM_OBJCOPY ?= $(shell which llvm-objcopy 2>/dev/null || which objcopy 2>/dev/null)
QEMU = qemu-system-x86_64
OVMF_PATH ?= /usr/share/OVMF/OVMF_CODE.fd

# Output files
UEFI_BOOTX64 = $(BUILD_DIR)/BOOTX64.EFI
BIOS_MBR = $(BUILD_DIR)/mbr.bin
BIOS_STAGE2 = $(BUILD_DIR)/stage2.bin
BIOS_BOOT = $(BUILD_DIR)/vlados_boot.bin
DISK_IMG = $(BUILD_DIR)/vlados.img

# ==============================================================================
# Default target
# ==============================================================================
all: $(BUILD_DIR) uefi bios

$(BUILD_DIR):
	mkdir -p $(BUILD_DIR)

# ==============================================================================
# UEFI Bootloader
# ==============================================================================
uefi: $(UEFI_BOOTX64)

$(UEFI_BOOTX64): $(shell find $(SRC_DIR) -name "*.rs" -type f)
	@echo "=== Building UEFI Bootloader ==="
	CARGO_TARGET_DIR=$(BUILD_DIR)/target $(CARGO) build \
		--bin uefi_entry \
		--no-default-features \
		--target x86_64-unknown-uefi \
		-Zbuild-std=core,alloc \
		-Zbuild-std-features=compiler-builtins-mem \
		--release
	@echo "UEFI bootloader built"
	# Copy the PE/COFF EFI binary directly
	cp $(BUILD_DIR)/target/x86_64-unknown-uefi/release/uefi_entry.efi $(UEFI_BOOTX64) 2>/dev/null || \
	$(LLVM_OBJCOPY) -O pe-i386 --change-section-address .text=0x10000 \
		$(BUILD_DIR)/target/x86_64-unknown-uefi/release/uefi_entry \
		$(UEFI_BOOTX64) 2>/dev/null || \
	cp $(BUILD_DIR)/target/x86_64-unknown-uefi/release/uefi_entry $(UEFI_BOOTX64)
	@echo "UEFI bootloader: $(UEFI_BOOTX64)"

# ==============================================================================
# BIOS Bootloader
# ==============================================================================
bios: $(BIOS_MBR) $(BIOS_STAGE2) $(BIOS_BOOT)

$(BIOS_MBR): $(ASM_DIR)/bios/stage1.asm
	@echo "=== Building Stage 1 (MBR) ==="
	$(NASM) -f bin -o $(BIOS_MBR) $(ASM_DIR)/bios/stage1.asm
	@echo "MBR: $(BIOS_MBR)"

$(BIOS_STAGE2): $(ASM_DIR)/bios/stage2.asm
	@echo "=== Building Stage 2 ==="
	$(NASM) -f bin -o $(BIOS_STAGE2) $(ASM_DIR)/bios/stage2.asm
	@echo "Stage 2: $(BIOS_STAGE2)"

$(BIOS_BOOT): $(shell find $(SRC_DIR) -name "*.rs" -type f)
	@echo "=== Building Stage 3 (BIOS Rust) ==="
	CARGO_TARGET_DIR=$(BUILD_DIR)/target $(CARGO) build \
		--bin bios_entry \
		--no-default-features \
		--target x86_64-unknown-none \
		-Zbuild-std=core,alloc \
		-Zbuild-std-features=compiler-builtins-mem \
		--release
	$(LLVM_OBJCOPY) -O binary \
		$(BUILD_DIR)/target/x86_64-unknown-none/release/bios_entry \
		$(BIOS_BOOT)
	@echo "BIOS Stage 3: $(BIOS_BOOT)"

# ==============================================================================
# Disk Image
# ==============================================================================
disk: $(DISK_IMG)

$(DISK_IMG): $(BIOS_MBR) $(BIOS_STAGE2) $(BIOS_BOOT) $(UEFI_BOOTX64)
	@echo "=== Creating Disk Image ==="
	dd if=/dev/zero of=$(DISK_IMG) bs=1M count=32
	dd if=$(BIOS_MBR) of=$(DISK_IMG) bs=512 count=1 conv=notrunc
	dd if=$(BIOS_STAGE2) of=$(DISK_IMG) bs=512 seek=2 conv=notrunc
	dd if=$(BIOS_BOOT) of=$(DISK_IMG) bs=512 seek=18 conv=notrunc
	@echo "Disk image: $(DISK_IMG)"

# ==============================================================================
# QEMU Testing
# ==============================================================================
run-bios: disk
	@echo "=== Running in QEMU (BIOS) ==="
	$(QEMU) -drive format=raw,file=$(DISK_IMG) \
		-m 256M \
		-serial stdio \
		-display none

run-bios-debug: disk
	@echo "=== Running in QEMU (BIOS, Debug) ==="
	$(QEMU) -drive format=raw,file=$(DISK_IMG) \
		-m 256M \
		-serial stdio \
		-display none \
		-d int,cpu_reset \
		-D $(BUILD_DIR)/qemu.log

run-uefi: $(UEFI_BOOTX64)
	@echo "=== Running in QEMU (UEFI) ==="
	$(QEMU) \
		-drive if=pflash,format=raw,readonly=on,file=$(OVMF_PATH) \
		-drive format=raw,file=$(UEFI_BOOTX64),if=none,id=boot \
		-device virtio-blk-pci,drive=boot \
		-m 256M \
		-serial stdio \
		-display none

run-uefi-debug: $(UEFI_BOOTX64)
	@echo "=== Running in QEMU (UEFI, Debug) ==="
	$(QEMU) \
		-drive if=pflash,format=raw,readonly=on,file=$(OVMF_PATH) \
		-drive format=raw,file=$(UEFI_BOOTX64),if=none,id=boot \
		-device virtio-blk-pci,drive=boot \
		-m 256M \
		-serial stdio \
		-display none \
		-d int,cpu_reset \
		-D $(BUILD_DIR)/qemu.log

# ==============================================================================
# Clean
# ==============================================================================
clean:
	rm -rf $(BUILD_DIR)

.PHONY: all uefi bios disk run-bios run-bios-debug run-uefi run-uefi-debug clean
