<?php
declare(strict_types=1);

/**
 * VladAero - Image & Spotting Photo Processing Engine
 * Handles EXIF data extraction, WebP compression, thumbnail creation, and watermark rendering.
 */

namespace VladAero;

class ImageHandler {
    /**
     * Process uploaded spotting photo
     */
    public static function processSpottingPhoto(array $file, string $authorName): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Ошибка загрузки файла. Код: ' . $file['error']];
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedTypes, true)) {
            return ['success' => false, 'error' => 'Разрешены только форматы JPG, PNG и WebP.'];
        }

        if ($file['size'] > 25 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Размер файла превышает лимит в 25 МБ.'];
        }

        // Extract EXIF
        $exifData = self::extractExif($file['tmp_name']);

        // Generate paths
        $uploadBase = dirname(__DIR__) . '/uploads/photos';
        if (!is_dir($uploadBase)) {
            @mkdir($uploadBase, 0775, true);
        }

        $hash = bin2hex(random_bytes(16));
        $fullPath = $uploadBase . '/' . $hash . '_full.webp';
        $mediumPath = $uploadBase . '/' . $hash . '_medium.webp';
        $thumbPath = $uploadBase . '/' . $hash . '_thumb.webp';

        // Load image resource
        $img = self::createImageFromFile($file['tmp_name'], $mime);
        if (!$img) {
            return ['success' => false, 'error' => 'Не удалось обработать изображение.'];
        }

        // Fix EXIF orientation if needed
        $img = self::fixOrientation($img, $file['tmp_name']);

        $origW = imagesx($img);
        $origH = imagesy($img);

        // 1. Full image with Watermark (max 2560px)
        $fullImg = self::resizeImage($img, $origW, $origH, 2560, 1600);
        self::applyWatermark($fullImg, $authorName);
        imagewebp($fullImg, $fullPath, 88);
        imagedestroy($fullImg);

        // 2. Medium image (max 1200px)
        $mediumImg = self::resizeImage($img, $origW, $origH, 1200, 800);
        imagewebp($mediumImg, $mediumPath, 84);
        imagedestroy($mediumImg);

        // 3. Thumb image (400x260 cropped/fitted)
        $thumbImg = self::resizeImage($img, $origW, $origH, 400, 267);
        imagewebp($thumbImg, $thumbPath, 80);
        imagedestroy($thumbImg);

        imagedestroy($img);

        return [
            'success'     => true,
            'photo_url'   => 'uploads/photos/' . $hash . '_full.webp',
            'medium_url'  => 'uploads/photos/' . $hash . '_medium.webp',
            'thumb_url'   => 'uploads/photos/' . $hash . '_thumb.webp',
            'exif'        => $exifData
        ];
    }

    /**
     * Extract EXIF metadata from photo
     */
    public static function extractExif(string $filePath): array {
        $meta = [
            'camera_model'  => null,
            'lens'          => null,
            'focal_length'  => null,
            'shutter_speed' => null,
            'aperture'      => null,
            'iso'           => null,
            'shot_date'     => null
        ];

        if (!function_exists('exif_read_data')) {
            return $meta;
        }

        $exif = @exif_read_data($filePath, 'ANY_TAG', true);
        if (!$exif) return $meta;

        $idf0 = $exif['IFD0'] ?? [];
        $sub = $exif['EXIF'] ?? [];

        // Camera Model
        $make = trim($idf0['Make'] ?? '');
        $model = trim($idf0['Model'] ?? '');
        if ($model) {
            $meta['camera_model'] = str_starts_with($model, $make) ? $model : "$make $model";
        }

        // Lens
        if (!empty($sub['UndefinedTag:0xA434'])) {
            $meta['lens'] = trim($sub['UndefinedTag:0xA434']);
        } elseif (!empty($sub['LensModel'])) {
            $meta['lens'] = trim($sub['LensModel']);
        }

        // Focal Length
        if (!empty($sub['FocalLength'])) {
            $meta['focal_length'] = self::evalFraction($sub['FocalLength']) . ' mm';
        }

        // Shutter Speed
        if (!empty($sub['ExposureTime'])) {
            $meta['shutter_speed'] = $sub['ExposureTime'] . ' s';
        }

        // Aperture (F-Number)
        if (!empty($sub['FNumber'])) {
            $meta['aperture'] = 'f/' . round(self::evalFraction($sub['FNumber']), 1);
        }

        // ISO
        if (!empty($sub['ISOSpeedRatings'])) {
            $meta['iso'] = is_array($sub['ISOSpeedRatings']) ? (string)$sub['ISOSpeedRatings'][0] : (string)$sub['ISOSpeedRatings'];
        }

        // Date Taken
        if (!empty($sub['DateTimeOriginal'])) {
            $dt = strtotime($sub['DateTimeOriginal']);
            if ($dt) $meta['shot_date'] = date('Y-m-d', $dt);
        }

        return $meta;
    }

    private static function evalFraction(mixed $val): float {
        if (is_numeric($val)) return (float)$val;
        if (is_string($val) && str_contains($val, '/')) {
            $parts = explode('/', $val);
            if (!empty($parts[1]) && (float)$parts[1] !== 0.0) {
                return (float)$parts[0] / (float)$parts[1];
            }
        }
        return 0.0;
    }

    /**
     * Create GD image from file
     */
    private static function createImageFromFile(string $path, string $mime): mixed {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };
    }

    /**
     * Resize image proportionally
     */
    private static function resizeImage(mixed $srcImg, int $srcW, int $srcH, int $maxW, int $maxH): mixed {
        $ratio = min($maxW / $srcW, $maxH / $srcH, 1.0);
        $newW = (int)round($srcW * $ratio);
        $newH = (int)round($srcH * $ratio);

        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        return $dst;
    }

    /**
     * Apply stylish aviation watermark with Author Name & VladAero logo
     */
    public static function applyWatermark(mixed $img, string $authorName): void {
        $w = imagesx($img);
        $h = imagesy($img);

        // Watermark background stripe
        $stripeH = (int)round($h * 0.05);
        $stripeH = max(36, min($stripeH, 60));
        $stripeY = $h - $stripeH;

        $barColor = imagecolorallocatealpha($img, 11, 19, 43, 60); // Semi-transparent Navy
        imagefilledrectangle($img, 0, $stripeY, $w, $h, $barColor);

        // Border line on top of stripe
        $lineColor = imagecolorallocatealpha($img, 14, 165, 233, 50); // Sky Cyan
        imageline($img, 0, $stripeY, $w, $stripeY, $lineColor);

        // Text rendering
        $textColor = imagecolorallocate($img, 255, 255, 255);
        $accentColor = imagecolorallocate($img, 56, 189, 248);

        $watermarkText = '✈ VLADAERO.RU  |  © ' . ($authorName ?: 'Spotter') . '  |  ' . date('Y');
        $font = 4; // Built-in GD font
        $textX = 20;
        $textY = $stripeY + (int)(($stripeH - 14) / 2);

        imagestring($img, $font, $textX, $textY, $watermarkText, $textColor);
    }

    private static function fixOrientation(mixed $img, string $path): mixed {
        if (!function_exists('exif_read_data')) return $img;
        $exif = @exif_read_data($path);
        if (empty($exif['Orientation'])) return $img;

        return match ($exif['Orientation']) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img
        };
    }
}
