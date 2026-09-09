/// Boot screen - Windows 10-style animated loading screen
/// Uses TrueType font and BMP logo for real rendering

use crate::font_data;
use crate::logo_data;
use crate::truetype;
use crate::math;

/// Framebuffer descriptor
pub struct Framebuffer {
    pub base: *mut u8,
    pub width: u32,
    pub height: u32,
    pub stride: u32,
}

impl Framebuffer {
    pub unsafe fn put_pixel(&mut self, x: u32, y: u32, color: u32) {
        if x >= self.width || y >= self.height {
            return;
        }
        let offset = (y * self.stride + x * 4) as usize;
        let ptr = self.base.add(offset);
        ptr.write((color & 0xFF) as u8);
        ptr.add(1).write(((color >> 8) & 0xFF) as u8);
        ptr.add(2).write(((color >> 16) & 0xFF) as u8);
        ptr.add(3).write(((color >> 24) & 0xFF) as u8);
    }

    pub fn fill(&mut self, color: u32) {
        for y in 0..self.height {
            for x in 0..self.width {
                unsafe {
                    self.put_pixel(x, y, color);
                }
            }
        }
    }

    pub fn fill_rect(&mut self, x: u32, y: u32, w: u32, h: u32, color: u32) {
        let x2 = core::cmp::min(x + w, self.width);
        let y2 = core::cmp::min(y + h, self.height);
        for py in y..y2 {
            for px in x..x2 {
                unsafe {
                    self.put_pixel(px, py, color);
                }
            }
        }
    }

    pub fn fill_circle(&mut self, cx: u32, cy: u32, radius: u32, color: u32) {
        let r2 = radius as i64 * radius as i64;
        let y_start = cy.saturating_sub(radius);
        let y_end = core::cmp::min(cy + radius, self.height - 1);
        let x_start = cx.saturating_sub(radius);
        let x_end = core::cmp::min(cx + radius, self.width - 1);

        for y in y_start..=y_end {
            for x in x_start..=x_end {
                let dx = x as i64 - cx as i64;
                let dy = y as i64 - cy as i64;
                if dx * dx + dy * dy <= r2 {
                    unsafe {
                        self.put_pixel(x, y, color);
                    }
                }
            }
        }
    }

    /// Draw a single character using TrueType font with alpha blending
    pub fn draw_char_tt(
        &mut self,
        font: &truetype::TrueTypeFont,
        x: i32,
        y: i32,
        ch: char,
        pixel_size: f32,
        color: u32,
    ) -> i32 {
        if let Some(glyph_id) = font.glyph_index(ch) {
            if let Some(bitmap) = font.rasterize(glyph_id, pixel_size) {
                let r = (color & 0xFF) as f32;
                let g = ((color >> 8) & 0xFF) as f32;
                let b = ((color >> 16) & 0xFF) as f32;

                for gy in 0..bitmap.height {
                    for gx in 0..bitmap.width {
                        let alpha = bitmap.data[(gy * bitmap.width + gx) as usize] as f32 / 255.0;
                        if alpha < 0.01 {
                            continue;
                        }

                        let px = x + bitmap.bearing_x as i32 + gx as i32;
                        let py = y + bitmap.bearing_y as i32 + gy as i32;

                        if px < 0 || py < 0 || px >= self.width as i32 || py >= self.height as i32 {
                            continue;
                        }

                        let offset = (py as u32 * self.stride + px as u32 * 4) as usize;
                        unsafe {
                            let ptr = self.base.add(offset);
                            let bg_b = ptr.read() as f32;
                            let bg_g = ptr.add(1).read() as f32;
                            let bg_r = ptr.add(2).read() as f32;

                            let new_r = bg_r * (1.0 - alpha) + r * alpha;
                            let new_g = bg_g * (1.0 - alpha) + g * alpha;
                            let new_b = bg_b * (1.0 - alpha) + b * alpha;

                            ptr.write(new_b as u8);
                            ptr.add(1).write(new_g as u8);
                            ptr.add(2).write(new_r as u8);
                        }
                    }
                }

                return bitmap.advance as i32;
            }
        }
        (pixel_size * 0.5) as i32
    }

    /// Draw text string using TrueType font
    pub fn draw_str_tt(
        &mut self,
        font: &truetype::TrueTypeFont,
        x: i32,
        y: i32,
        text: &str,
        pixel_size: f32,
        color: u32,
    ) -> i32 {
        let mut cx = x;
        for ch in text.chars() {
            let advance = self.draw_char_tt(font, cx, y, ch, pixel_size, color);
            cx += advance;
        }
        cx - x
    }

    /// Draw centered text
    pub fn draw_str_centered_tt(
        &mut self,
        font: &truetype::TrueTypeFont,
        y: i32,
        text: &str,
        pixel_size: f32,
        color: u32,
    ) {
        let mut total_width = 0i32;
        for ch in text.chars() {
            if let Some(glyph_id) = font.glyph_index(ch) {
                total_width += font.advance_width(glyph_id) as i32;
            }
        }

        let scale = pixel_size / font.units_per_em as f32;
        let text_pixel_width = (total_width as f32 * scale) as i32;
        let x = (self.width as i32 - text_pixel_width) / 2;

        self.draw_str_tt(font, x, y, text, pixel_size, color);
    }
}

/// Show the full boot screen animation with TrueType font and BMP logo
pub fn show_boot_screen(fb: &mut Framebuffer) {
    // Parse TrueType font
    let font = match truetype::TrueTypeFont::parse(&font_data::DEJAVU_SANS_TTF) {
        Ok(f) => f,
        Err(_) => return,
    };

    // Parse BMP logo
    let logo = match crate::bmp::BmpImage::decode(&logo_data::VLADOS_LOGO_DATA) {
        Ok(img) => img,
        Err(_) => return,
    };

    // Windows 10 dark blue background
    let bg_color = 0x00D47800u32;
    fb.fill(bg_color);

    // Render logo centered
    let logo_x = (fb.width as i32 - logo.width as i32) / 2;
    let logo_y = fb.height as i32 / 2 - 100;
    logo.render_to_framebuffer(fb.base, fb.width, fb.height, fb.stride, logo_x, logo_y);

    // Draw "VladOS" text below logo using TrueType font
    let text_y = logo_y + logo.height as i32 + 20;
    let text_color = 0x00FFFFFFu32;
    let pixel_size = 48.0;
    fb.draw_str_centered_tt(&font, text_y, "VladOS", pixel_size, text_color);

    // Animated spinning dots
    let spinner_y = (text_y + 80) as u32;
    let cx = fb.width / 2;
    let spinner_scale = core::cmp::max(fb.width / 80, 4);
    let total_frames = 120u32;

    for frame in 0..total_frames {
        // Clear spinner area
        let clear_size = spinner_scale * 8;
        let clear_x = cx.saturating_sub(clear_size);
        let clear_y = spinner_y.saturating_sub(clear_size);
        fb.fill_rect(clear_x, clear_y, clear_size * 2, clear_size * 2, bg_color);

        // Draw spinner dots
        let dot_count: u32 = 5;
        let radius = core::cmp::max(spinner_scale / 3, 2);
        let ring_radius = spinner_scale * 6;
        let angle_step = core::f32::consts::PI * 2.0 / dot_count as f32;
        let anim_offset = frame as f32 * 0.2;

        for i in 0..dot_count {
            let base_angle = i as f32 * angle_step;
            let angle = base_angle + anim_offset;

            let x = cx as f32 + math::cos(angle) * ring_radius as f32;
            let y = spinner_y as f32 + math::sin(angle) * ring_radius as f32;

            let brightness = 255u32 - (i * 255 / dot_count) as u32;
            let color = brightness | (brightness << 8) | (brightness << 16);
            fb.fill_circle(x as u32, y as u32, radius, color);
        }

        // Busy-wait delay
        for _ in 0..2_000_000u32 {
            unsafe {
                core::arch::asm!("nop");
            }
        }
    }

    // Show "Loading VladOS..." text after spinner
    let loading_y = spinner_y + spinner_scale * 12;
    let clear_w = 600u32;
    let clear_h = 60u32;
    fb.fill_rect(
        cx.saturating_sub(clear_w / 2),
        loading_y.saturating_sub(4),
        clear_w,
        clear_h,
        bg_color,
    );
    fb.draw_str_centered_tt(&font, loading_y as i32, "Loading VladOS...", 24.0, text_color);

    // Brief delay
    for _ in 0..5_000_000u32 {
        unsafe {
            core::arch::asm!("nop");
        }
    }
}
