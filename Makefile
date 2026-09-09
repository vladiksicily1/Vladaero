# VladOS Build System
# Builds kernel, bootloader, and ISO image

.PHONY: all kernel bootloader userspace iso clean release

# Directories
KERNEL_DIR = kernel
BOOT_DIR = bootloader_new
USERSPACE_DIR = userspace
SYSROOT_DIR = sysroot
BUILD_DIR = build
ISO_DIR = $(BUILD_DIR)/iso

# Tools
QEMU = qemu-system-x86_64
OVMF_PATH ?= /usr/share/OVMF/OVMF_CODE.fd
XORRISO = xorriso

# ==============================================================================
# Default target
# ==============================================================================

all: kernel bootloader userspace iso

# ==============================================================================
# Kernel
# ==============================================================================

kernel:
	@echo "=== Building VladOS Kernel ==="
	$(MAKE) -C $(KERNEL_DIR) ARCH=x86_64 all

# ==============================================================================
# Bootloader
# ==============================================================================

bootloader:
	@echo "=== Building VladOS Bootloader ==="
	$(MAKE) -C $(BOOT_DIR) all

# ==============================================================================
# Userspace Libraries & Apps
# ==============================================================================

USERSPACE_LIBS = ntdll kernel32 user32 gdi32
USERSPACE_APPS = cmd vladss wininit winlogon services csrss
USERSPACE_BUILD = $(USERSPACE_DIR)/target/x86_64-unknown-none/release
USERSPACE_TARGET = x86_64-unknown-none

userspace:
	@echo "=== Building VladOS Userspace Libraries ==="
	cd $(USERSPACE_DIR) && cargo build --target $(USERSPACE_TARGET) --release -Zbuild-std=core,alloc --lib
	@for lib in $(USERSPACE_LIBS); do \
		if [ -f "$(USERSPACE_BUILD)/lib$${lib}.a" ]; then \
			echo "  Built: $${lib}.vll"; \
		fi; \
	done
	@echo "=== Building VladOS Userspace Apps ==="
	@for app in $(USERSPACE_APPS); do \
		if [ -d "$(USERSPACE_DIR)/$$app" ]; then \
			cd $(USERSPACE_DIR)/$$app && cargo build --target $(USERSPACE_TARGET) --release -Zbuild-std=core,alloc 2>/dev/null && \
			echo "  Built: $$app.vex"; \
			cd $(CURDIR); \
		elif [ -d "$(USERSPACE_DIR)/system/$$app" ]; then \
			cd $(USERSPACE_DIR)/system/$$app && cargo build --target $(USERSPACE_TARGET) --release -Zbuild-std=core,alloc 2>/dev/null && \
			echo "  Built: $$app.vex"; \
			cd $(CURDIR); \
		fi; \
	done

# ==============================================================================
# ISO Image
# ==============================================================================

iso: kernel bootloader userspace
	@echo "=== Creating VladOS ISO ==="
	mkdir -p $(ISO_DIR)
	# Copy sysroot to ISO
	cp -r $(SYSROOT_DIR)/* $(ISO_DIR)/
	# Copy kernel
	cp $(KERNEL_DIR)/build/x86_64/kernel $(ISO_DIR)/C/VladOS/System32/vlados.vex
	cp $(KERNEL_DIR)/build/x86_64/kernel.sym $(ISO_DIR)/C/VladOS/System32/vlados.sym
	# Copy bootloader
	cp $(BOOT_DIR)/build/BOOTX64.EFI $(ISO_DIR)/C/VladOS/Boot/BOOTX64.EFI
	mkdir -p $(ISO_DIR)/efi/boot
	cp $(BOOT_DIR)/build/BOOTX64.EFI $(ISO_DIR)/efi/boot/BOOTX64.EFI
	# Copy userspace libraries as .vll
	@for lib in $(USERSPACE_LIBS); do \
		if [ -f "$(USERSPACE_BUILD)/lib$${lib}.a" ]; then \
			cp "$(USERSPACE_BUILD)/lib$${lib}.a" "$(ISO_DIR)/C/VladOS/System32/$${lib}.vll"; \
			echo "  Installed: $${lib}.vll"; \
		fi; \
	done
	# Copy userspace apps as .vex
	@for app in $(USERSPACE_APPS); do \
		if [ -f "$(USERSPACE_BUILD)/$$app" ]; then \
			cp "$(USERSPACE_BUILD)/$$app" "$(ISO_DIR)/C/VladOS/System32/$$app.vex"; \
			echo "  Installed: $$app.vex"; \
		fi; \
	done
	# Create ISO
	cd $(ISO_DIR) && $(XORRISO) -as mkisofs \
		-r -V "VladOS" \
		-o $(CURDIR)/$(BUILD_DIR)/vlados.iso \
		-eltorito-boot efi/boot/BOOTX64.EFI \
		-no-emul-boot \
		-boot-load-size 4 \
		-boot-info-table \
		.
	@echo "ISO created: $(BUILD_DIR)/vlados.iso"

# ==============================================================================
# QEMU Testing
# ==============================================================================

run: iso
	@echo "=== Running VladOS in QEMU (UEFI) ==="
	$(QEMU) \
		-drive if=pflash,format=raw,readonly=on,file=$(OVMF_PATH) \
		-cdrom $(BUILD_DIR)/vlados.iso \
		-m 512M \
		-serial stdio \
		-display none

run-bios: kernel bootloader userspace
	@echo "=== Running VladOS in QEMU (BIOS) ==="
	$(MAKE) -C $(BOOT_DIR) disk
	$(QEMU) \
		-drive format=raw,file=$(BOOT_DIR)/build/vlados.img \
		-m 512M \
		-serial stdio \
		-display none

# ==============================================================================
# GitHub Release
# ==============================================================================

release: iso
	@echo "=== Creating GitHub Release ==="
	@echo "ISO: $(BUILD_DIR)/vlados.iso"
	@echo "Upload to GitHub Releases with:"
	@echo "  gh release create v0.1.0 $(BUILD_DIR)/vlados.iso --title 'VladOS v0.1.0' --notes 'Initial release'"

# ==============================================================================
# Clean
# ==============================================================================

clean:
	$(MAKE) -C $(KERNEL_DIR) clean
	$(MAKE) -C $(BOOT_DIR) clean
	cd $(USERSPACE_DIR) && cargo clean
	rm -rf $(BUILD_DIR)
