/// BMP image decoder for bootloader
/// Supports uncompressed 24-bit and 32-bit BMP files

/// BMP file header (14 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct BmpFileHeader {
    pub signature: [u8; 2],      // "BM"
    pub file_size: u32,
    pub _reserved: u32,
    pub data_offset: u32,
}

/// BMP info header (DIB header, BITMAPINFOHEADER = 40 bytes)
#[repr(C, packed)]
#[derive(Debug, Clone, Copy)]
pub struct BmpInfoHeader {
    pub header_size: u32,
    pub width: i32,
    pub height: i32,
    pub planes: u16,
    pub bits_per_pixel: u16,
    pub compression: u32,
    pub image_size: u32,
    pub x_pixels_per_meter: u32,
    pub y_pixels_per_meter: u32,
    pub colors_used: u32,
    pub colors_important: u32,
}

/// Decoded BMP image
pub struct BmpImage {
    pub width: u32,
    pub height: u32,
    /// Raw pixel data in BGRA format (bottom-up, as stored in BMP)
    pub data: alloc::vec::Vec<u8>,
    /// Bits per pixel (24 or 32)
    pub bpp: u16,
}

impl BmpImage {
    /// Decode a BMP image from bytes
    pub fn decode(data: &[u8]) -> Result<Self, ()> {
        if data.len() < core::mem::size_of::<BmpFileHeader>() {
            return Err(());
        }

        // Parse file header
        let file_header = unsafe { &*(data.as_ptr() as *const BmpFileHeader) };
        if &file_header.signature != b"BM" {
            return Err(());
        }

        let info_offset = core::mem::size_of::<BmpFileHeader>();
        if info_offset + core::mem::size_of::<BmpInfoHeader>() > data.len() {
            return Err(());
        }

        // Parse info header
        let info = unsafe {
            &*(data[info_offset..].as_ptr() as *const BmpInfoHeader)
        };

        // Only support uncompressed BMP
        if info.compression != 0 {
            return Err(()); // RLE or BITFIELDS not supported
        }

        let bpp = info.bits_per_pixel;
        if bpp != 24 && bpp != 32 {
            return Err(());
        }

        let width = info.width as u32;
        let height = info.height.unsigned_abs();
        let bottom_up = info.height > 0;

        let bytes_per_pixel = bpp / 8;
        let row_stride = ((width * bytes_per_pixel as u32 + 3) & !3) as usize; // Align to 4 bytes
        let data_offset = file_header.data_offset as usize;

        if data_offset + row_stride * height as usize > data.len() {
            return Err(());
        }

        // Decode pixels
        let mut pixels = alloc::vec![0u8; (width * height * 4) as usize]; // Always BGRA output

        for y in 0..height as usize {
            let src_row = if bottom_up {
                data_offset + (height as usize - 1 - y) * row_stride
            } else {
                data_offset + y * row_stride
            };

            let dst_row = y * width as usize * 4;

            for x in 0..width as usize {
                let src_offset = src_row + x * bytes_per_pixel as usize;
                let dst_offset = dst_row + x * 4;

                if src_offset + bytes_per_pixel as usize > data.len() {
                    break;
                }

                match bpp {
                    24 => {
                        pixels[dst_offset] = data[src_offset];     // B
                        pixels[dst_offset + 1] = data[src_offset + 1]; // G
                        pixels[dst_offset + 2] = data[src_offset + 2]; // R
                        pixels[dst_offset + 3] = 0xFF;             // A
                    }
                    32 => {
                        pixels[dst_offset] = data[src_offset];     // B
                        pixels[dst_offset + 1] = data[src_offset + 1]; // G
                        pixels[dst_offset + 2] = data[src_offset + 2]; // R
                        pixels[dst_offset + 3] = data[src_offset + 3]; // A
                    }
                    _ => {}
                }
            }
        }

        Ok(BmpImage {
            width,
            height,
            data: pixels,
            bpp,
        })
    }

    /// Get pixel at (x, y) as BGRA
    pub fn pixel(&self, x: u32, y: u32) -> Option<[u8; 4]> {
        if x >= self.width || y >= self.height {
            return None;
        }
        let offset = (y * self.width + x) as usize * 4;
        if offset + 4 > self.data.len() {
            return None;
        }
        Some([
            self.data[offset],
            self.data[offset + 1],
            self.data[offset + 2],
            self.data[offset + 3],
        ])
    }

    /// Render BMP image to a framebuffer
    pub fn render_to_framebuffer(
        &self,
        fb_base: *mut u8,
        fb_width: u32,
        fb_height: u32,
        fb_stride: u32,
        dest_x: i32,
        dest_y: i32,
    ) {
        for y in 0..self.height {
            for x in 0..self.width {
                let px = dest_x + x as i32;
                let py = dest_y + y as i32;

                if px < 0 || py < 0 || px >= fb_width as i32 || py >= fb_height as i32 {
                    continue;
                }

                if let Some([b, g, r, a]) = self.pixel(x, y) {
                    if a == 0 {
                        continue; // Skip transparent pixels
                    }

                    let offset = (py as u32 * fb_stride + px as u32 * 4) as usize;
                    unsafe {
                        let ptr = fb_base.add(offset);
                        ptr.write(b);
                        ptr.add(1).write(g);
                        ptr.add(2).write(r);
                        ptr.add(3).write(a);
                    }
                }
            }
        }
    }
}
