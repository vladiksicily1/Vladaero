/// TrueType font parser and rasterizer for bootloader
/// Supports: cmap, glyf, loca, head, hhea, hmtx, maxp tables
/// Renders glyphs to grayscale bitmaps using scanline rasterization

use alloc::vec;
use alloc::vec::Vec;

/// TrueType font file
pub struct TrueTypeFont<'a> {
    data: &'a [u8],
    /// Font metrics
    pub units_per_em: u16,
    pub ascender: i16,
    pub descender: i16,
    pub line_gap: i16,
    /// Table offsets
    head_offset: usize,
    hhea_offset: usize,
    hmtx_offset: usize,
    maxp_offset: usize,
    cmap_offset: usize,
    glyf_offset: usize,
    loca_offset: usize,
    /// Table sizes
    hmtx_size: usize,
    glyf_size: usize,
    loca_size: usize,
    maxp_num_glyphs: u16,
    index_to_loc_format: u16, // 0 = short, 1 = long
}

/// Bounding box of a glyph
#[derive(Debug, Clone, Copy, Default)]
pub struct GlyphBBox {
    pub x_min: i16,
    pub y_min: i16,
    pub x_max: i16,
    pub y_max: i16,
}

impl GlyphBBox {
    pub fn width(&self) -> i16 {
        self.x_max - self.x_min
    }
    pub fn height(&self) -> i16 {
        self.y_max - self.y_min
    }
}

/// A rasterized glyph bitmap
pub struct GlyphBitmap {
    pub width: u32,
    pub height: u32,
    pub bearing_x: i32,
    pub bearing_y: i32,
    pub advance: u32,
    /// Grayscale pixels (0 = transparent, 255 = fully covered)
    pub data: Vec<u8>,
}

impl GlyphBitmap {
    /// Create an empty glyph (space)
    pub fn empty(advance: u32) -> Self {
        Self {
            width: 0,
            height: 0,
            bearing_x: 0,
            bearing_y: 0,
            advance,
            data: Vec::new(),
        }
    }
}

/// TrueType contour point
#[derive(Debug, Clone, Copy)]
struct Point {
    x: f32,
    y: f32,
    on_curve: bool,
}

/// TrueType glyph outline
struct GlyphOutline {
    points: Vec<Point>,
    contours: Vec<usize>, // End point indices for each contour
}

impl<'a> TrueTypeFont<'a> {
    /// Parse a TrueType font from bytes
    pub fn parse(data: &'a [u8]) -> Result<Self, ()> {
        // Validate TrueType signature
        let num_tables = read_u16(data, 4).ok_or(())?;
        let table_dir = 12;

        let mut head_offset = None;
        let mut hhea_offset = None;
        let mut hmtx_offset = None;
        let mut maxp_offset = None;
        let mut cmap_offset = None;
        let mut glyf_offset = None;
        let mut loca_offset = None;
        let mut hmtx_size = 0;
        let mut glyf_size = 0;
        let mut loca_size = 0;

        for i in 0..num_tables as usize {
            let entry = table_dir + i * 16;
            if entry + 16 > data.len() {
                return Err(());
            }

            let tag = &data[entry..entry + 4];
            let offset = read_u32(data, entry + 8).ok_or(())? as usize;
            let length = read_u32(data, entry + 12).ok_or(())? as usize;

            match tag {
                b"head" => head_offset = Some(offset),
                b"hhea" => hhea_offset = Some(offset),
                b"hmtx" => {
                    hmtx_offset = Some(offset);
                    hmtx_size = length;
                }
                b"maxp" => maxp_offset = Some(offset),
                b"cmap" => cmap_offset = Some(offset),
                b"glyf" => {
                    glyf_offset = Some(offset);
                    glyf_size = length;
                }
                b"loca" => {
                    loca_offset = Some(offset);
                    loca_size = length;
                }
                _ => {}
            }
        }

        let head_off = head_offset.ok_or(())?;
        let hhea_off = hhea_offset.ok_or(())?;
        let hmtx_off = hmtx_offset.ok_or(())?;
        let maxp_off = maxp_offset.ok_or(())?;
        let cmap_off = cmap_offset.ok_or(())?;
        let glyf_off = glyf_offset.ok_or(())?;
        let loca_off = loca_offset.ok_or(())?;

        // Parse head table
        let units_per_em = read_u16(data, head_off + 18).ok_or(())?;
        let index_to_loc_format = read_u16(data, head_off + 50).ok_or(())?;

        // Parse hhea table
        let ascender = read_i16(data, hhea_off + 4).ok_or(())?;
        let descender = read_i16(data, hhea_off + 6).ok_or(())?;
        let line_gap = read_i16(data, hhea_off + 8).ok_or(())?;

        // Parse maxp table
        let maxp_num_glyphs = read_u16(data, maxp_off + 4).ok_or(())?;

        Ok(Self {
            data,
            units_per_em,
            ascender,
            descender,
            line_gap,
            head_offset: head_off,
            hhea_offset: hhea_off,
            hmtx_offset: hmtx_off,
            maxp_offset: maxp_off,
            cmap_offset: cmap_off,
            glyf_offset: glyf_off,
            loca_offset: loca_off,
            hmtx_size,
            glyf_size,
            loca_size,
            maxp_num_glyphs,
            index_to_loc_format,
        })
    }

    /// Get glyph index for a character using cmap
    pub fn glyph_index(&self, ch: char) -> Option<u16> {
        let cmap = self.cmap_offset;
        let num_subtables = read_u16(self.data, cmap + 2)?;

        let mut subtable_offset = cmap + 4;
        for _ in 0..num_subtables {
            if subtable_offset + 8 > self.data.len() {
                return None;
            }
            let platform = read_u16(self.data, subtable_offset)?;
            let encoding = read_u16(self.data, subtable_offset + 2)?;
            let offset = read_u32(self.data, subtable_offset + 4)? as usize;
            let table_offset = cmap + offset;

            match (platform, encoding) {
                // Unicode platform, BMP encoding
                (3, 1) | (0, 3) => {
                    return self.cmap_format_4(table_offset, ch);
                }
                // Unicode platform, full repertoire
                (3, 10) | (0, 4) => {
                    return self.cmap_format_12(table_offset, ch);
                }
                _ => {}
            }

            subtable_offset += 8;
        }

        None
    }

    /// Parse cmap format 4 (segment mapping)
    fn cmap_format_4(&self, offset: usize, ch: char) -> Option<u16> {
        let format = read_u16(self.data, offset)?;
        if format != 4 {
            return None;
        }

        let _length = read_u16(self.data, offset + 2)? as usize;
        let num_segs = read_u16(self.data, offset + 6)? / 2;

        let seg_count = num_segs as usize;
        let search_range = offset + 14;
        let end_codes = search_range;
        let start_codes = end_codes + seg_count * 2;
        let id_deltas = start_codes + seg_count * 2;
        let id_range_offsets = id_deltas + seg_count * 2;

        let code = ch as u32;
        if code > 0xFFFF {
            return None;
        }
        let code16 = code as u16;

        for i in 0..seg_count {
            let end_code = read_u16(self.data, end_codes + i * 2)?;
            let start_code = read_u16(self.data, start_codes + i * 2)?;

            if code16 >= start_code && code16 <= end_code {
                let id_delta = read_i16(self.data, id_deltas + i * 2)?;
                let id_range_offset = read_u16(self.data, id_range_offsets + i * 2)?;

                if id_range_offset == 0 {
                    let glyph_id = (code16 as i32 + id_delta as i32) as u16;
                    return Some(glyph_id);
                } else {
                    let _offset = id_range_offset as usize + (i + (code16 - start_code) as usize) * 2;
                    let glyph_id = read_u16(self.data, id_range_offset as usize + (code16 - start_code) as usize * 2)?;
                    if glyph_id != 0 {
                        return Some((glyph_id as i32 + id_delta as i32) as u16);
                    }
                    return None;
                }
            }
        }

        None
    }

    /// Parse cmap format 12 (segmented coverage)
    fn cmap_format_12(&self, offset: usize, ch: char) -> Option<u16> {
        let format = read_u16(self.data, offset)?;
        if format != 12 {
            return None;
        }

        let num_groups = read_u32(self.data, offset + 12)?;
        let groups_start = offset + 16;
        let code = ch as u32;

        for i in 0..num_groups {
            let group = groups_start + i as usize * 12;
            let start_char = read_u32(self.data, group)?;
            let end_char = read_u32(self.data, group + 4)?;
            let start_glyph = read_u32(self.data, group + 8)?;

            if code >= start_char && code <= end_char {
                return Some((start_glyph + (code - start_char)) as u16);
            }
        }

        None
    }

    /// Get advance width for a glyph
    pub fn advance_width(&self, glyph_id: u16) -> u16 {
        if glyph_id >= self.maxp_num_glyphs {
            return 0;
        }

        let offset = self.hmtx_offset + glyph_id as usize * 4;
        if offset + 4 <= self.data.len() {
            read_u16(self.data, offset).unwrap_or(0)
        } else {
            // Use last advance width
            let last = self.hmtx_offset + (self.maxp_num_glyphs as usize - 1) * 4;
            read_u16(self.data, last).unwrap_or(0)
        }
    }

    /// Get glyph bounding box
    pub fn glyph_bbox(&self, glyph_id: u16) -> Option<GlyphBBox> {
        if glyph_id == 0 {
            return None; // .notdef glyph
        }

        let offset = self.glyph_offset(glyph_id)?;
        if offset >= self.data.len() {
            return None;
        }

        let number_of_contours = read_i16(self.data, offset)?;
        let x_min = read_i16(self.data, offset + 2)?;
        let y_min = read_i16(self.data, offset + 4)?;
        let x_max = read_i16(self.data, offset + 6)?;
        let y_max = read_i16(self.data, offset + 8)?;

        if number_of_contours >= 0 {
            // Simple glyph
            Some(GlyphBBox {
                x_min,
                y_min,
                x_max,
                y_max,
            })
        } else {
            // Compound glyph - not fully handled here
            None
        }
    }

    /// Get glyph offset from loca table
    fn glyph_offset(&self, glyph_id: u16) -> Option<usize> {
        let loca = self.loca_offset;

        if self.index_to_loc_format == 0 {
            // Short format: offsets in words (2 bytes)
            let offset1 = loca + glyph_id as usize * 2;
            let offset2 = loca + (glyph_id + 1) as usize * 2;
            if offset2 + 2 > self.data.len() {
                return None;
            }
            let o1 = read_u16(self.data, offset1)? as usize * 2;
            let o2 = read_u16(self.data, offset2)? as usize * 2;
            if o1 == o2 {
                return None; // Empty glyph
            }
            Some(self.glyf_offset + o1)
        } else {
            // Long format: offsets in bytes (4 bytes)
            let offset1 = loca + glyph_id as usize * 4;
            let offset2 = loca + (glyph_id + 1) as usize * 4;
            if offset2 + 4 > self.data.len() {
                return None;
            }
            let o1 = read_u32(self.data, offset1)? as usize;
            let o2 = read_u32(self.data, offset2)? as usize;
            if o1 == o2 {
                return None; // Empty glyph
            }
            Some(self.glyf_offset + o1)
        }
    }

    /// Parse a simple TrueType glyph outline
    fn parse_simple_glyph(&self, glyph_id: u16) -> Option<GlyphOutline> {
        let offset = self.glyph_offset(glyph_id)?;
        if offset + 10 > self.data.len() {
            return None;
        }

        let number_of_contours = read_i16(self.data, offset)?;
        if number_of_contours <= 0 {
            return None; // Not a simple glyph
        }

        let instruction_length = read_u16(self.data, offset + 10).unwrap_or(0) as usize;
        let instructions_end = offset + 12 + instruction_length;

        // Read contour end points
        let num_points = if number_of_contours > 0 {
            let last_end = read_u16(self.data, instructions_end - 2 + number_of_contours as usize * 2)?;
            last_end as usize + 1
        } else {
            0
        };

        let mut contours = Vec::new();
        for i in 0..number_of_contours as usize {
            let end_point = read_u16(self.data, instructions_end + i * 2)? as usize;
            contours.push(end_point);
        }

        // Skip instructions
        let flags_offset = instructions_end + number_of_contours as usize * 2;

        // Read flags for each point
        let mut flags = Vec::with_capacity(num_points);
        let mut pos = flags_offset;
        while flags.len() < num_points && pos < self.data.len() {
            let flag = self.data[pos];
            pos += 1;
            flags.push(flag);

            if flag & 0x08 != 0 {
                // REPEAT flag
                if pos >= self.data.len() {
                    break;
                }
                let repeat_count = self.data[pos] as usize;
                pos += 1;
                for _ in 0..repeat_count {
                    if flags.len() >= num_points {
                        break;
                    }
                    flags.push(flag);
                }
            }
        }

        // Read x coordinates (with delta encoding)
        let mut x_coords = Vec::with_capacity(num_points);
        let mut x: i32 = 0;
        for &flag in &flags {
            if flag & 0x02 != 0 {
                // XSHORT: 1 byte
                if pos >= self.data.len() {
                    break;
                }
                let dx = self.data[pos] as i32;
                pos += 1;
                if flag & 0x10 != 0 {
                    x += dx;
                } else {
                    x -= dx;
                }
            } else if flag & 0x10 == 0 {
                // LONG: 2 bytes
                if pos + 2 > self.data.len() {
                    break;
                }
                let dx = read_i16(self.data, pos)? as i32;
                pos += 2;
                x += dx;
            }
            // else: 0 bytes, x stays the same
            x_coords.push(x);
        }

        // Read y coordinates (with delta encoding)
        let mut y_coords = Vec::with_capacity(num_points);
        let mut y: i32 = 0;
        for &flag in &flags {
            if flag & 0x04 != 0 {
                // YSHORT: 1 byte
                if pos >= self.data.len() {
                    break;
                }
                let dy = self.data[pos] as i32;
                pos += 1;
                if flag & 0x20 != 0 {
                    y += dy;
                } else {
                    y -= dy;
                }
            } else if flag & 0x20 == 0 {
                // LONG: 2 bytes
                if pos + 2 > self.data.len() {
                    break;
                }
                let dy = read_i16(self.data, pos)? as i32;
                pos += 2;
                y += dy;
            }
            // else: 0 bytes, y stays the same
            y_coords.push(y);
        }

        // Build points
        let mut points = Vec::with_capacity(num_points);
        for i in 0..num_points {
            points.push(Point {
                x: x_coords[i] as f32,
                y: y_coords[i] as f32,
                on_curve: flags[i] & 0x01 != 0,
            });
        }

        Some(GlyphOutline { points, contours })
    }

    /// Rasterize a glyph at the given pixel size
    pub fn rasterize(&self, glyph_id: u16, pixel_size: f32) -> Option<GlyphBitmap> {
        if glyph_id >= self.maxp_num_glyphs {
            return None;
        }

        let advance = self.advance_width(glyph_id);
        let bbox = self.glyph_bbox(glyph_id)?;

        let scale = pixel_size / self.units_per_em as f32;

        // Parse outline
        let outline = self.parse_simple_glyph(glyph_id)?;

        if outline.points.is_empty() {
            return Some(GlyphBitmap::empty((advance as f32 * scale) as u32));
        }

        // Scale points
        let _scaled_points: Vec<Point> = outline
            .points
            .iter()
            .map(|p| Point {
                x: p.x * scale,
                y: -p.y * scale, // Flip Y (TrueType Y goes up)
                on_curve: p.on_curve,
            })
            .collect();

        // Calculate bitmap dimensions
        let width = ((bbox.width() as f32 * scale) as i32).max(1) as u32;
        let height = ((bbox.height() as f32 * scale) as i32).max(1) as u32;

        let bearing_x = (bbox.x_min as f32 * scale) as i32;
        let bearing_y = (-bbox.y_max as f32 * scale) as i32;

        // Create bitmap
        let mut bitmap = vec![0u8; (width * height) as usize];

        // Rasterize each contour
        let mut point_idx = 0;
        for &end_idx in &outline.contours {
            let contour_points: Vec<Point> = outline.points[point_idx..=end_idx].to_vec();
            point_idx = end_idx + 1;

            if contour_points.is_empty() {
                continue;
            }

            // Convert curves to line segments
            let segments = self.tessellate_contour(&contour_points);

            // Rasterize segments using scanline algorithm
            self.rasterize_segments(
                &segments,
                &mut bitmap,
                width,
                height,
                bbox.x_min as f32 * scale,
                bbox.y_max as f32 * scale,
            );
        }

        Some(GlyphBitmap {
            width,
            height,
            bearing_x,
            bearing_y,
            advance: (advance as f32 * scale) as u32,
            data: bitmap,
        })
    }

    /// Tessellate a contour into line segments
    #[allow(unused_assignments)]
    fn tessellate_contour(&self, points: &[Point]) -> Vec<(Point, Point)> {
        let mut segments = Vec::new();
        let n = points.len();
        if n < 2 {
            return segments;
        }

        let mut i = 0;
        while i < n {
            let start = points[i];

            if start.on_curve {
                // Find next on-curve point
                let mut j = (i + 1) % n;
                let mut current = start;

                while j != i {
                    if points[j].on_curve {
                        segments.push((current, points[j]));
                        current = points[j];
                        i = j;
                        break;
                    } else {
                        // Off-curve point = quadratic control point
                        let control = points[j];
                        let k = (j + 1) % n;
                        if points[k].on_curve {
                            segments.push((current, control));
                            segments.push((control, points[k]));
                            current = points[k];
                            i = k;
                            break;
                        } else {
                            // Implicit on-curve point
                            let mid = Point {
                                x: (control.x + points[k].x) / 2.0,
                                y: (control.y + points[k].y) / 2.0,
                                on_curve: true,
                            };
                            segments.push((current, control));
                            segments.push((control, mid));
                            current = mid;
                            j = k;
                        }
                    }
                }
                i += 1;
            } else {
                i += 1;
            }
        }

        segments
    }

    /// Rasterize line segments into a bitmap using scanline fill
    fn rasterize_segments(
        &self,
        segments: &[(Point, Point)],
        bitmap: &mut [u8],
        width: u32,
        height: u32,
        x_offset: f32,
        y_offset: f32,
    ) {
        let w = width as i32;
        let h = height as i32;

        for &(p0, p1) in segments {
            let x0 = (p0.x - x_offset) as f32;
            let y0 = (p0.y - y_offset) as f32;
            let x1 = (p1.x - x_offset) as f32;
            let y1 = (p1.y - y_offset) as f32;

            // Rasterize line using Bresenham-like algorithm
            let dx = (x1 - x0).abs();
            let dy = (y1 - y0).abs();
            let steps = dx.max(dy) as i32;

            if steps == 0 {
                continue;
            }

            let sx = dx / steps as f32;
            let sy = dy / steps as f32;

            let mut px = x0;
            let mut py = y0;

            for _ in 0..=steps {
                let ix = px as i32;
                let iy = py as i32;

                if ix >= 0 && ix < w && iy >= 0 && iy < h {
                    let idx = (iy * w + ix) as usize;
                    if idx < bitmap.len() {
                        bitmap[idx] = 255;
                    }
                }

                px += sx * if x1 > x0 { 1.0 } else { -1.0 };
                py += sy * if y1 > y0 { 1.0 } else { -1.0 };
            }
        }

        // Fill using scanline
        for y in 0..h {
            let mut intersections = Vec::new();

            for &(p0, p1) in segments {
                let x0 = p0.x - x_offset;
                let y0 = p0.y - y_offset;
                let x1 = p1.x - x_offset;
                let y1 = p1.y - y_offset;

                // Check if line crosses this scanline
                if (y0 <= y as f32 && y1 > y as f32) || (y1 <= y as f32 && y0 > y as f32) {
                    let t = (y as f32 - y0) / (y1 - y0);
                    let x_intersect = x0 + t * (x1 - x0);
                    intersections.push(x_intersect);
                }
            }

            intersections.sort_by(|a, b| a.partial_cmp(b).unwrap());

            // Fill between pairs of intersections
            let mut i = 0;
            while i + 1 < intersections.len() {
                let x_start = (intersections[i] as i32).max(0) as u32;
                let x_end = (intersections[i + 1] as i32).min(w) as u32;

                for x in x_start..x_end {
                    let idx = (y as u32 * width + x) as usize;
                    if idx < bitmap.len() {
                        bitmap[idx] = 255;
                    }
                }

                i += 2;
            }
        }
    }
}

// Helper functions to read big-endian values
fn read_u16(data: &[u8], offset: usize) -> Option<u16> {
    if offset + 2 > data.len() {
        return None;
    }
    Some(u16::from_be_bytes([data[offset], data[offset + 1]]))
}

fn read_i16(data: &[u8], offset: usize) -> Option<i16> {
    if offset + 2 > data.len() {
        return None;
    }
    Some(i16::from_be_bytes([data[offset], data[offset + 1]]))
}

fn read_u32(data: &[u8], offset: usize) -> Option<u32> {
    if offset + 4 > data.len() {
        return None;
    }
    Some(u32::from_be_bytes([
        data[offset],
        data[offset + 1],
        data[offset + 2],
        data[offset + 3],
    ]))
}
