.PHONY: all check

SOURCE:=$(dir $(realpath $(lastword $(MAKEFILE_LIST))))
export RUST_TARGET_PATH=$(SOURCE)/targets

ifeq ($(TARGET),)
	ARCH?=$(shell uname -m)
else
	ARCH?=$(shell echo "$(TARGET)" | cut -d - -f1)
endif

BUILD?=$(CURDIR)/build/$(ARCH)
DESTDIR?=./sysroot

ifeq ($(ARCH),riscv64gc)
	override ARCH:=riscv64
else ifeq ($(ARCH),i686)
	override ARCH:=i586
endif

# Use LLVM tools instead of Redox-specific ones
OBJCOPY?=$(shell which llvm-objcopy 2>/dev/null || which objcopy 2>/dev/null || echo objcopy)

all: $(BUILD)/kernel $(BUILD)/kernel.sym

LD_SCRIPT=$(SOURCE)/linkers/$(ARCH).ld
LOCKFILE=$(SOURCE)/Cargo.lock
MANIFEST=$(SOURCE)/Cargo.toml
TARGET_SPEC=$(RUST_TARGET_PATH)/$(ARCH)-unknown-kernel.json

KERNEL_CARGO_FEATURES?=

$(BUILD):
	mkdir -p "$@"

$(BUILD)/kernel.all: $(LD_SCRIPT) $(LOCKFILE) $(MANIFEST) $(TARGET_SPEC) $(shell find $(SOURCE) -name "*.rs" -type f) | $(BUILD)
	cargo rustc \
		--lib \
		--manifest-path "$(MANIFEST)" \
		--target "$(TARGET_SPEC)" \
		--release \
		-Z build-std=core,alloc -Zbuild-std-features=compiler-builtins-mem \
		-Z json-target-spec \
		--features=$(KERNEL_CARGO_FEATURES) \
		-- \
		-C link-arg=-T -Clink-arg="$(LD_SCRIPT)" \
		-C link-arg=-z -Clink-arg=max-page-size=0x1000 \
		--emit link="$(BUILD)/kernel.all"

$(BUILD)/kernel.sym: $(BUILD)/kernel.all
	$(OBJCOPY) \
		--only-keep-debug \
		"$(BUILD)/kernel.all" \
		"$(BUILD)/kernel.sym"

$(BUILD)/kernel: $(BUILD)/kernel.all
	$(OBJCOPY) \
		--strip-debug \
		"$(BUILD)/kernel.all" \
		"$(BUILD)/kernel"

KERNEL_CHECK_FEATURES?=

check:
	cargo check \
		--lib \
		--manifest-path "$(MANIFEST)" \
		--target "$(TARGET_SPEC)" \
		-Z build-std=core,alloc -Zbuild-std-features=compiler-builtins-mem \
		-Z json-target-spec \
		--features=$(KERNEL_CHECK_FEATURES)

clean:
	rm -rf build sysroot target config.toml

install: all
	@mkdir -pv "$(DESTDIR)/usr/lib/boot/"
	cp -v $(BUILD)/kernel.all "$(DESTDIR)/usr/lib/boot/"
	cp -v $(BUILD)/kernel.sym "$(DESTDIR)/usr/lib/boot/"
	cp -v $(BUILD)/kernel "$(DESTDIR)/usr/lib/boot/"

# test if booting
# to ensure it's using this kernel, set COOKBOOK_SOURCE_IDENT env before build
test: all
	$(MAKE) install
	REDOXER_SYSROOT=$(DESTDIR) redoxer exec uname -a

# test with interactive gui
test-gui: all
	$(MAKE) install
	REDOXER_SYSROOT=$(DESTDIR) redoxer exec --gui ion

# test with relibc tests
test-relibc: all
	$(MAKE) install
	REDOXER_SYSROOT=$(DESTDIR) redoxer pkg relibc-tests-bins
	REDOXER_SYSROOT=$(DESTDIR) redoxer exec relibc-tests-runner
