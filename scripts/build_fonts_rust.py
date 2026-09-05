#!/usr/bin/env python3
"""
Build and integrate 3 custom fonts for VladOS:
1. VLADOS_TITLE: Smooth anti-aliased Segoe UI Bold glyphs for 'VladOS' on bootscreen
2. SUBTEXT_STARTING: Smooth anti-aliased Segoe UI clean glyphs for 'Starting system...' on bootscreen
3. CMD_FONT: 4096-byte 8x16 Windows 10 Consolas / Lucida Console monospace font for CMD
"""
import os
from PIL import Image, ImageFont, ImageDraw

def generate_title_data():
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 28)
    text = "VladOS"
    bbox = font.getbbox(text)
    w = bbox[2] - bbox[0] + 4
    h = bbox[3] - bbox[1] + 6
    
    im = Image.new('L', (w, h), 0)
    draw = ImageDraw.Draw(im)
    draw.text((2 - bbox[0], 2 - bbox[1]), text, font=font, fill=255)
    
    alpha_bytes = list(im.getdata())
    return w, h, alpha_bytes

def generate_subtext_data():
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 13)
    text = "Starting system..."
    bbox = font.getbbox(text)
    w = bbox[2] - bbox[0] + 4
    h = bbox[3] - bbox[1] + 4
    
    im = Image.new('L', (w, h), 0)
    draw = ImageDraw.Draw(im)
    draw.text((2 - bbox[0], 2 - bbox[1]), text, font=font, fill=255)
    
    alpha_bytes = list(im.getdata())
    return w, h, alpha_bytes

def generate_cmd_font_bin():
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf', 12)
    
    font_bytes = bytearray(256 * 16)
    
    for c in range(256):
        char_im = Image.new('L', (8, 16), 0)
        draw = ImageDraw.Draw(char_im)
        
        if 32 <= c <= 126:
            ch = chr(c)
            # Natural font baseline and metrics
            draw.text((0, 0), ch, font=font, fill=255)
            
            # Consolas dotted zero '0'
            if ch == '0':
                draw.point((3, 7), fill=255)
                        
        pix = char_im.load()
        for row in range(16):
            row_byte = 0
            for col in range(8):
                if pix[col, row] > 70:
                    row_byte |= (1 << (7 - col))
            font_bytes[c * 16 + row] = row_byte
            
    return font_bytes

print("Generating font assets...")
tw, th, title_alpha = generate_title_data()
sw, sh, subtext_alpha = generate_subtext_data()
cmd_font = generate_cmd_font_bin()

# Write cmd font binary to replace unifont.font
res_font_path = "/workspaces/Vladaero/vlados/components/kernel/res/unifont.font"
with open(res_font_path, "wb") as f:
    f.write(cmd_font)
print(f"Updated kernel font: {res_font_path} (4096 bytes)")

# Generate custom_fonts.rs
rust_code = f"""// Auto-generated custom fonts for VladOS
// 1. Title font: 'VladOS' (Anti-aliased Segoe UI style)
// 2. Subtitle font: 'Starting system...' (Anti-aliased Segoe UI sans-serif)

pub const TITLE_W: usize = {tw};
pub const TITLE_H: usize = {th};
pub static TITLE_ALPHA: [u8; {len(title_alpha)}] = [
    {", ".join(map(str, title_alpha))}
];

pub const SUBTEXT_W: usize = {sw};
pub const SUBTEXT_H: usize = {sh};
pub static SUBTEXT_ALPHA: [u8; {len(subtext_alpha)}] = [
    {", ".join(map(str, subtext_alpha))}
];
"""

rust_file_path = "/workspaces/Vladaero/vlados/components/kernel/src/devices/graphical_debug/custom_fonts.rs"
with open(rust_file_path, "w") as f:
    f.write(rust_code)
print(f"Generated Rust font module: {rust_file_path}")
