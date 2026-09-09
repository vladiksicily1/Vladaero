/* VladOS Bootloader - BIOS Linker Script */
/* Used by cargo to link the BIOS Stage 3 (Rust code at 0x10000) */

OUTPUT_FORMAT("elf64-x86-64")
OUTPUT_ARCH(i386:x86-64)
ENTRY(bios_entry)

SECTIONS
{
    /* Stage 3 loaded at 1MB (0x100000) by stage2 */
    . = 0x100000;

    .text : {
        *(.text .text.*)
    }

    .rodata : {
        *(.rodata .rodata.*)
    }

    .data : {
        *(.data .data.*)
    }

    .bss : {
        *(.bss .bss.*)
        *(COMMON)
    }

    /* Kernel args at fixed address 0x81000 */
    .boot_args (NOLOAD) : {
        *(.boot_args)
    }

    /DISCARD/ : {
        *(.eh_frame)
        *(.comment)
        *(.note*)
    }
}
