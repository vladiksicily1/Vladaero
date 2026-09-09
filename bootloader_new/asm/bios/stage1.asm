; =============================================================================
; VladOS Bootloader - Stage 1 (MBR)
; 512-byte Master Boot Record
; Loads Stage 2 from disk into memory and jumps to it
; =============================================================================
bits 16
org 0x7C00

; Constants
STAGE2_LOAD_SEG    equ 0x0800       ; Segment to load stage2 (0x8000 physical)
STAGE2_LOAD_OFF    equ 0x0000       ; Offset within segment
STAGE2_SECTORS     equ 16           ; Number of sectors to read (8KB)
STAGE2_START_LBA   equ 2            ; LBA of stage2 on disk (after MBR + gap)
DISK_NUMBER        equ 0x80         ; First hard disk

; =============================================================================
; Entry Point
; =============================================================================
entry:
    ; Set up segment registers
    xor ax, ax
    mov ds, ax
    mov es, ax
    mov ss, ax
    mov sp, 0x7C00       ; Stack grows down from 0x7C00

    ; Save boot drive number
    mov [boot_drive], dl

    ; Enable A20 line (fast method)
    call enable_a20

    ; Print boot message
    mov si, msg_boot
    call print_string

    ; Read disk parameters
    mov ah, 0x08
    mov dl, [boot_drive]
    xor di, di
    int 0x13
    jc .disk_error
    mov [max_heads], dh

    ; Load Stage 2 from disk using INT 13h Extended Read
    mov si, DAP
    mov ah, 0x42
    mov dl, [boot_drive]
    int 0x13
    jc .disk_error

    ; Verify stage2 loaded correctly (check magic number at start)
    mov ax, [STAGE2_LOAD_SEG * 16 + STAGE2_LOAD_OFF]
    cmp ax, 0xAA55
    jne .stage2_error

    ; Print success message
    mov si, msg_stage2
    call print_string

    ; Jump to Stage 2
    jmp STAGE2_LOAD_SEG:STAGE2_LOAD_OFF

; =============================================================================
; Error Handlers
; =============================================================================
.disk_error:
    mov si, msg_disk_err
    call print_string
    jmp halt

.stage2_error:
    mov si, msg_stage2_err
    call print_string
    jmp halt

halt:
    cli
    hlt
    jmp halt

; =============================================================================
; Enable A20 Line (Fast Method)
; =============================================================================
enable_a20:
    ; Wait for keyboard controller to be ready
    in al, 0x64
    test al, 2
    jnz enable_a20

    ; Send write command
    mov al, 0xD1
    out 0x64, al

    ; Wait again
    in al, 0x64
    test al, 2
    jnz enable_a20

    ; Send data (enable A20)
    mov al, 0xDF
    out 0x60, al

    ; Wait
    in al, 0x64
    test al, 2
    jnz enable_a20

    ; Acknowledge
    mov al, 0xFF
    out 0x64, al

    ; Read status
    in al, 0x60

    ret

; =============================================================================
; Print String (null-terminated)
; =============================================================================
print_string:
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

; =============================================================================
; Data
; =============================================================================
boot_drive:    db 0
max_heads:     db 0

; Disk Address Packet for INT 13h Extended Read
DAP:
    db 0x10               ; Size of DAP
    db 0                   ; Reserved
    dw STAGE2_SECTORS      ; Sector count
    dw STAGE2_LOAD_OFF     ; Buffer offset
    dw STAGE2_LOAD_SEG     ; Buffer segment
    dd STAGE2_START_LBA    ; LBA low 32 bits
    dd 0                   ; LBA high 32 bits

; Messages
msg_boot:       db '[VladOS] Stage 1: MBR loaded', 13, 10, 0
msg_stage2:     db '[VladOS] Stage 1: Stage 2 loaded', 13, 10, 0
msg_disk_err:   db '[VladOS] Stage 1: Disk read error', 13, 10, 0
msg_stage2_err: db '[VladOS] Stage 1: Invalid stage2', 13, 10, 0

; Pad to 510 bytes and add boot signature
times 510 - ($ - $$) db 0
dw 0xAA55
