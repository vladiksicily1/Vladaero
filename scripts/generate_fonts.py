#!/usr/bin/env python3
"""
Generate custom fonts for VladOS:
1. Font 1: Large Bold Segoe-UI style font for 'VladOS' title on bootscreen (Anti-aliased)
2. Font 2: Sleek, modern sans-serif for 'Starting system...' subtitle (Anti-aliased)
3. Font 3: Custom Windows 10 Consolas / Lucida Console style 8x16 bitmap font for CMD
"""
import struct
import zlib
from PIL import Image, ImageFont, ImageDraw

def generate_title_glyph():
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 28)
    text = "VladOS"
    bbox = font.getbbox(text) # (left, top, right, bottom)
    w = bbox[2] - bbox[0] + 4
    h = bbox[3] - bbox[1] + 6
    
    im = Image.new('L', (w, h), 0)
    draw = ImageDraw.Draw(im)
    draw.text((2 - bbox[0], 2 - bbox[1]), text, font=font, fill=255)
    return im

def generate_subtext_glyph():
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 13)
    text = "Starting system..."
    bbox = font.getbbox(text)
    w = bbox[2] - bbox[0] + 4
    h = bbox[3] - bbox[1] + 4
    
    im = Image.new('L', (w, h), 0)
    draw = ImageDraw.Draw(im)
    draw.text((2 - bbox[0], 2 - bbox[1]), text, font=font, fill=255)
    return im

def generate_cmd_font():
    # 256 characters, each 8x16
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf', 12)
    font_reg = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf', 12)
    
    font_bytes = bytearray(256 * 16)
    
    for c in range(256):
        char_im = Image.new('L', (8, 16), 0)
        draw = ImageDraw.Draw(char_im)
        
        if 32 <= c <= 126:
            ch = chr(c)
            # Use regular font for lowercase / text, bold for operators
            f = font if ch in "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ:[]\\>=" else font_reg
            # Center character horizontally in 8px box
            cb = f.getbbox(ch)
            cw = cb[2] - cb[0]
            cx = max(0, (8 - cw) // 2 - cb[0])
            draw.text((cx, 1), ch, font=f, fill=255)
            
            # If zero '0', add slashed dot in center to look like Consolas
            if ch == '0':
                # Slashed zero: add diagonal line from (2, 5) to (5, 9)
                pixels = char_im.load()
                pixels[3, 6] = 255
                pixels[4, 7] = 255
                pixels[3, 8] = 255
                
        elif c == 0: # NUL
            pass
        
        # Convert char_im to 16 bytes
        pix = char_im.load()
        for row in range(16):
            row_byte = 0
            for col in range(8):
                if pix[col, row] > 100:
                    row_byte |= (1 << (7 - col))
            font_bytes[c * 16 + row] = row_byte
            
    return font_bytes

# Generate previews
title_im = generate_title_glyph()
subtext_im = generate_subtext_glyph()
cmd_font = generate_cmd_font()

print(f"Title 'VladOS': {title_im.width}x{title_im.height} px")
print(f"Subtext 'Starting system...': {subtext_im.width}x{subtext_im.height} px")
print(f"CMD Font: {len(cmd_font)} bytes ({len(cmd_font)//16} glyphs)")

# Render Bootscreen Preview
bs_w, bs_h = 1280, 800
bs_im = Image.new('RGB', (bs_w, bs_h), (0, 0, 0))
bs_draw = ImageDraw.Draw(bs_im)

cx = bs_w // 2
cy = (bs_h // 2) - 60

# 4 Tiles
tile = 40
gap = 6
hgap = gap // 2
blue = (0, 120, 215)
bs_draw.rectangle([cx - hgap - tile, cy - hgap - tile, cx - hgap, cy - hgap], fill=blue)
bs_draw.rectangle([cx + hgap, cy - hgap - tile, cx + hgap + tile, cy - hgap], fill=blue)
bs_draw.rectangle([cx - hgap - tile, cy + hgap, cx - hgap, cy + hgap + tile], fill=blue)
bs_draw.rectangle([cx + hgap, cy + hgap, cx + hgap + tile, cy + hgap + tile], fill=blue)

# VladOS Title
tx = cx - title_im.width // 2
ty = cy + tile + hgap + 28
for y in range(title_im.height):
    for x in range(title_im.width):
        a = title_im.getpixel((x, y))
        if a > 0:
            bs_im.putpixel((tx + x, ty + y), (a, a, a))

# Spinner placeholder
spin_y = ty + title_im.height + 20
# Dots
for i in range(5):
    import math
    angle = i * (math.pi / 8)
    dx = int(cx + 18 * math.cos(angle))
    dy = int(spin_y + 18 * math.sin(angle))
    cval = int(255 * (1 - i * 0.18))
    bs_draw.ellipse([dx-2, dy-2, dx+2, dy+2], fill=(cval, cval, cval))

# Subtext
sx = cx - subtext_im.width // 2
sy = spin_y + 36
for y in range(subtext_im.height):
    for x in range(subtext_im.width):
        a = subtext_im.getpixel((x, y))
        if a > 0:
            v = int(a * 0.7) # 70% brightness for elegant subtitle
            bs_im.putpixel((sx + x, sy + y), (v, v, v))

bs_preview_path = "/workspaces/Vladaero/vlados/build/preview_bootscreen_new_fonts.png"
bs_im.save(bs_preview_path)
print(f"Saved bootscreen preview: {bs_preview_path}")

# Render CMD Preview
cmd_w, cmd_h = 1280, 800
cmd_im = Image.new('RGB', (cmd_w, cmd_h), (12, 12, 12)) # 0x0C0C0C
cmd_draw = ImageDraw.Draw(cmd_im)

lines = [
    "=================================================",
    "   VladOS System Supervisor: vladinit.vex",
    "   Architecture: x86_64 | Ring 3 Userspace (PID 1)",
    "   Filesystem: VladFS (/VladOS/System32/)",
    "=================================================",
    "[vladinit] Full control transferred from kernel to vladinit.vex.",
    "[vladinit] Loading system configuration (/VladOS/System32/config/system.ini)...",
    "[vladinit] Storage subsystem active: VladFS mounted on C:\\",
    "[vladinit] Spawning shell: /VladOS/System32/cmd.vex...",
    "-------------------------------------------------",
    "",
    "VladOS [Version 10.0.22000.1]",
    "(c) 2026 Vlad Corporation. All rights reserved.",
    "",
    "C:\\VladOS\\System32> ver",
    "VladOS [Version 10.0.22000.1] - 64-bit Hybrid Kernel (Ring 3)",
    "",
    "C:\\VladOS\\System32> sysinfo",
    "Host Name:                 VLADOS-PC",
    "OS Name:                   VladOS 10 Professional",
    "OS Version:                1.0.0 Build 2026.09.05",
    "OS Architecture:           x86_64 Long Mode (64-bit)",
    "Executable Standard:       .vex (Vlad EXecutable)",
    "Root Filesystem:           VladFS (Volume: VLADOS_SYS)",
    "Display:                   1280x800x32 Linear GOP Framebuffer",
    "Theme Engine:              Windows 10 Fluent Dark",
    "Cloud Portal:              https://vladinc.ru/vlados/",
    "",
    "C:\\VladOS\\System32> dir",
    " Volume in drive C is VLADOS_SYS",
    " Volume Serial Number is 564C-4144",
    "",
    " Directory of C:\\VladOS\\System32",
    "",
    "09/05/2026  01:00 PM    <DIR>          .",
    "09/05/2026  01:00 PM    <DIR>          ..",
    "09/05/2026  01:00 PM    <DIR>          config",
    "09/05/2026  01:00 PM    <DIR>          drivers",
    "09/05/2026  01:00 PM             1,350 vladinit.vex",
    "09/05/2026  01:00 PM             2,480 cmd.vex",
    "               2 File(s)          3,830 bytes",
    "               4 Dir(s)      31,457,280 bytes free",
    "",
    "C:\\VladOS\\System32> "
]

def draw_cmd_char(im, x, y, ch, color=(240, 240, 240)):
    code = ord(ch) if ord(ch) < 256 else ord('?')
    font_offset = code * 16
    for row in range(16):
        row_byte = cmd_font[font_offset + row]
        for col in range(8):
            if (row_byte >> (7 - col)) & 1:
                im.putpixel((x + col, y + row), color)

for row_idx, line in enumerate(lines):
    y = row_idx * 16 + 10
    for col_idx, ch in enumerate(line):
        x = col_idx * 8 + 10
        draw_cmd_char(cmd_im, x, y, ch)

cmd_preview_path = "/workspaces/Vladaero/vlados/build/preview_cmd_new_font.png"
cmd_im.save(cmd_preview_path)
print(f"Saved CMD preview: {cmd_preview_path}")
