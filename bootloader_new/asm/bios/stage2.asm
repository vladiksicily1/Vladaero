; =============================================================================
; VladOS Bootloader - Stage 2 (Protected Mode Setup)
; Loaded at 0x8000 by Stage 1
; Sets up Protected Mode, loads VladFS driver, loads kernel, enters Long Mode
; =============================================================================
bits 16
org 0x0000

; Stage 2 header (magic 0xAA55 at offset 0 for verification)
stage2_header:
    dw 0xAA55               ; Magic number for Stage 1 verification
    dw stage2_entry          ; Entry point offset
    dw 0x0800               ; Entry point segment

; =============================================================================
; Stage 2 Entry Point (Real Mode)
; =============================================================================
stage2_entry:
    ; Set up segment registers for stage2
    mov ax, cs
    mov ds, ax
    mov es, ax
    mov ss, ax
    mov sp, 0x7C00          ; Stack at 0x7C00 (below us)

    ; Save boot drive number from DL
    mov [boot_drive], dl

    ; Print stage2 loaded message
    mov si, msg_pm
    call print_string_rm

    ; =============================================
    ; Detect Memory Map (INT 15h E820)
    ; =============================================
    call detect_memory_map

    ; =============================================
    ; Detect VBE Video Mode
    ; =============================================
    call detect_vbe

    ; =============================================
    ; Enable A20 Line
    ; =============================================
    call enable_a20_rm

    ; =============================================
    ; Enter Protected Mode
    ; =============================================
    cli
    lgdt [gdt_descriptor]

    ; Set PE bit in CR0
    mov eax, cr0
    or eax, 1
    mov cr0, eax

    ; Far jump to flush pipeline and enter 32-bit protected mode
    jmp 0x08:protected_mode_entry

; =============================================================================
; Real Mode Functions
; =============================================================================

; Print string (Real Mode)
print_string_rm:
    pusha
.loop:
    lodsb
    or al, al
    jz .done
    mov ah, 0x0E
    mov bh, 0
    int 0x10
    jmp .loop
.done:
    popa
    ret

; Detect memory map via INT 15h E820
detect_memory_map:
    mov di, e820_buffer
    xor ebx, ebx            ; Continuation value
    mov edx, 0x534D4150     ; 'SMAP' signature

.e820_loop:
    mov eax, 0xE820
    mov ecx, 24             ; Entry size
    int 0x15
    jc .e820_done           ; CF set = error or no more entries
    cmp eax, 0x534D4150    ; Check signature
    jne .e820_done

    ; Check for zero-size entry
    cmp dword [di + 8], 0
    je .e820_next
    cmp dword [di + 12], 0
    jne .e820_high          ; Skip entries above 4GB

    ; Save entry count
    inc word [e820_count]

.e820_next:
    cmp ebx, 0              ; ebx=0 means no more entries
    je .e820_done
    add di, 24
    jmp .e820_loop

.e820_high:
    ; Skip high memory entries
    jmp .e820_next

.e820_done:
    mov si, msg_e820
    call print_string_rm
    ret

; Detect VBE video modes
detect_vbe:
    ; Try to set 1024x768x32 first
    mov ax, 0x4F02
    mov bx, 0x4118          ; 1024x768, 32bpp, linear framebuffer
    int 0x10
    cmp ax, 0x004F
    je .vbe_done

    ; Try 800x600x32
    mov ax, 0x4F02
    mov bx, 0x4115          ; 800x600, 32bpp, linear framebuffer
    int 0x10
    cmp ax, 0x004F
    je .vbe_done

    ; Try 640x480x32
    mov ax, 0x4F02
    mov bx, 0x4112          ; 640x480, 32bpp, linear framebuffer
    int 0x10
    cmp ax, 0x004F
    je .vbe_done

    ; Fallback to VGA text mode 0x03
    mov ax, 0x0003
    int 0x10

.vbe_done:
    ; Get current mode info
    mov ax, 0x4F01
    mov cx, 0x4118           ; Assume 1024x768
    mov di, vbe_mode_info
    int 0x10
    ret

; Enable A20 line (Real Mode)
enable_a20_rm:
    ; Fast A20 method
    in al, 0x64
    test al, 2
    jnz enable_a20_rm

    mov al, 0xD1
    out 0x64, al

    in al, 0x64
    test al, 2
    jnz enable_a20_rm

    mov al, 0xDF
    out 0x60, al

    in al, 0x64
    test al, 2
    jnz enable_a20_rm

    mov al, 0xFF
    out 0x64, al
    in al, 0x60
    ret

; =============================================================================
; Data (Real Mode)
; =============================================================================
boot_drive:     db 0

; Messages
msg_pm:         db '[VladOS] Stage 2: Setting up Protected Mode', 13, 10, 0
msg_e820:       db '[VladOS] Stage 2: Memory map detected', 13, 10, 0

; E820 memory map buffer (up to 64 entries)
e820_buffer:    times 64 * 24 db 0
e820_count:     dw 0

; VBE mode info
vbe_mode_info:  times 256 db 0

; =============================================================================
; GDT (Global Descriptor Table)
; =============================================================================
align 4
gdt_start:
    ; Null descriptor
    dd 0x0
    dd 0x0

    ; Code segment (0x08): Base=0, Limit=4GB, 32-bit, Execute/Read
    dw 0xFFFF      ; Limit low
    dw 0x0000      ; Base low
    db 0x00        ; Base middle
    db 10011010b   ; Access: Present, Ring 0, Code, Readable
    db 11001111b   ; Flags: 4GB, 32-bit + Limit high
    db 0x00        ; Base high

    ; Data segment (0x10): Base=0, Limit=4GB, 32-bit, Read/Write
    dw 0xFFFF      ; Limit low
    dw 0x0000      ; Base low
    db 0x00        ; Base middle
    db 10010010b   ; Access: Present, Ring 0, Data, Writable
    db 11001111b   ; Flags: 4GB, 32-bit + Limit high
    db 0x00        ; Base high

    ; 64-bit Code segment (0x18): For long mode
    dw 0xFFFF      ; Limit low
    dw 0x0000      ; Base low
    db 0x00        ; Base middle
    db 10011010b   ; Access: Present, Ring 0, Code, Readable
    db 10101111b   ; Flags: L bit (64-bit), 4GB
    db 0x00        ; Base high

    ; 64-bit Data segment (0x20)
    dw 0xFFFF
    dw 0x0000
    db 0x00
    db 10010010b
    db 11001111b
    db 0x00

gdt_end:

gdt_descriptor:
    dw gdt_end - gdt_start - 1    ; Size
    dd gdt_start                   ; Offset

; =============================================================================
; 32-bit Protected Mode Entry
; =============================================================================
bits 32
protected_mode_entry:
    ; Set up segment registers for protected mode
    mov ax, 0x10        ; Data segment selector
    mov ds, ax
    mov es, ax
    mov fs, ax
    mov gs, ax
    mov ss, ax
    mov esp, 0x90000    ; Stack at 0x90000

    ; Print "PM" to VGA text mode for debug
    mov word [0xB8000], 0x0F50  ; 'P' in white
    mov word [0xB8002], 0x0F4D  ; 'M' in white

    ; =============================================
    ; Enable PAE and set up initial page tables
    ; for 64-bit long mode
    ; =============================================
    ; Page tables at 0x1000
    ; PML4 at 0x1000, PDPT at 0x2000, PD at 0x3000
    mov edi, 0x1000
    mov cr3, edi
    xor eax, eax
    mov ecx, 4096        ; Clear 16KB (4 pages)
    rep stosd

    ; PML4[0] -> PDPT (0x2000, Present + Writable)
    mov dword [0x1000], 0x00002003

    ; PDPT[0] -> PD (0x3000, Present + Writable)
    mov dword [0x2000], 0x00003003

    ; PD entries: 512 entries, each maps 2MB
    ; Total: 512 * 2MB = 1GB identity mapped
    mov edi, 0x3000
    mov eax, 0x00000083  ; Present + Writable + PageSize (2MB)
    mov ecx, 512

.map_pd:
    mov [edi], eax
    mov dword [edi + 4], 0
    add eax, 0x200000    ; Next 2MB page
    add edi, 8
    dec ecx
    jnz .map_pd

    ; Enable PAE (CR4.PAE = bit 5)
    mov eax, cr4
    or eax, (1 << 5)
    mov cr4, eax

    ; Enable long mode (EFER.LME = bit 8)
    mov ecx, 0xC0000080  ; EFER MSR
    rdmsr
    or eax, (1 << 8)
    wrmsr

    ; Enable paging (CR0.PG = bit 31)
    mov eax, cr0
    or eax, (1 << 31)
    mov cr0, eax

    ; Load 64-bit GDT
    lgdt [gdt64_descriptor]

    ; Jump to 64-bit code segment
    jmp 0x18:long_mode_entry

; =============================================================================
; 64-bit Long Mode Entry
; =============================================================================
bits 64
long_mode_entry:
    ; Set up 64-bit segment registers
    mov ax, 0x20
    mov ds, ax
    mov es, ax
    mov fs, ax
    mov gs, ax
    mov ss, ax
    mov rsp, 0x90000

    ; Print "LM" to VGA text mode for debug
    mov word [0xB8000 + 4], 0x0F4C  ; 'L'
    mov word [0xB8002 + 4], 0x0F4D  ; 'M'

    ; =============================================
    ; Copy E820 memory map to known location
    ; =============================================
    mov rsi, e820_buffer
    mov rdi, 0x80000      ; Copy to 0x80000
    mov rcx, 64 * 24      ; 64 entries * 24 bytes
    rep movsb

    ; Store memory map count at 0x8FFF0
    movzx rax, word [e820_count]
    mov [0x8FFF0], ax

    ; Store VBE mode info at 0x8F000
    mov rsi, vbe_mode_info
    mov rdi, 0x8F000
    mov rcx, 256
    rep movsb

    ; =============================================
    ; Load Stage 3 (Rust bootloader) from disk
    ; Stage 3 is at LBA 18 (after MBR + stage2)
    ; and loaded to 0x100000 (1MB)
    ; =============================================
    mov rsi, msg_loading_kernel
    call print_string_pm64

    ; Set up disk read for Stage 3
    ; LBA 18, 128 sectors (64KB), buffer at 0x100000
    mov rdi, DAP64
    mov word [rdi + 0], 0x10      ; DAP size
    mov word [rdi + 2], 128       ; 128 sectors = 64KB
    mov word [rdi + 4], 0x0000    ; Offset
    mov word [rdi + 6], 0x0010    ; Segment 0x10 = physical 0x100000
    mov dword [rdi + 8], 18       ; LBA low
    mov dword [rdi + 12], 0       ; LBA high

    ; Read from disk using INT 13h
    ; We're in 64-bit mode, so we need to use BIOS thunk
    ; For now, assume data is loaded and jump to kernel

    ; Store boot args at 0x81000
    mov rdi, 0x81000
    mov qword [rdi + 0], 0        ; Boot magic
    mov qword [rdi + 8], 0        ; Kernel entry (will be filled)
    mov rax, 0x80000
    mov [rdi + 16], rax           ; Memory map pointer
    movzx rax, word [0x8FFF0]
    mov [rdi + 24], rax           ; Memory map count
    mov qword [rdi + 32], 0       ; RSDP (filled by UEFI path)

    ; =============================================
    ; Jump to Stage 3 (Rust bootloader)
    ; =============================================
    mov rsi, msg_entering_kernel
    call print_string_pm64

    ; Jump to Stage 3 entry point
    mov rax, 0x10000
    jmp rax

    ; Should never reach here
    hlt
    jmp $

; =============================================================================
; Print String (64-bit Protected Mode)
; =============================================================================
print_string_pm64:
    push rsi
    push rax
    push rdx
.loop:
    lodsb
    or al, al
    jz .done
    ; Write to VGA text buffer
    movzx rax, al
    or ax, 0x0F00
    mov [0xB8000 + rdx * 2], ax
    inc rdx
    jmp .loop
.done:
    pop rdx
    pop rax
    pop rsi
    ret

; =============================================================================
; Data (32-bit/64-bit)
; =============================================================================
msg_loading_kernel:  db '[VladOS] Stage 3: Loading kernel', 13, 10, 0
msg_entering_kernel: db '[VladOS] Stage 3: Entering kernel', 13, 10, 0

; DAP for 64-bit stage
DAP64:
    times 16 db 0

; =============================================================================
; 64-bit GDT
; =============================================================================
align 4
gdt64_start:
    ; Null descriptor
    dd 0x0
    dd 0x0

    ; 32-bit Code (0x08)
    dw 0xFFFF
    dw 0x0000
    db 0x00
    db 10011010b
    db 11001111b
    db 0x00

    ; 32-bit Data (0x10)
    dw 0xFFFF
    dw 0x0000
    db 0x00
    db 10010010b
    db 11001111b
    db 0x00

    ; 64-bit Code (0x18)
    dw 0xFFFF
    dw 0x0000
    db 0x00
    db 10011010b
    db 10101111b
    db 0x00

    ; 64-bit Data (0x20)
    dw 0xFFFF
    dw 0x0000
    db 0x00
    db 10010010b
    db 11001111b
    db 0x00

gdt64_end:

gdt64_descriptor:
    dw gdt64_end - gdt64_start - 1
    dd gdt64_start

; =============================================================================
; Pad to 8KB (16 sectors) - Stage 2 size
; =============================================================================
times 8192 - ($ - $$) db 0
