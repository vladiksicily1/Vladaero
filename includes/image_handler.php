<?php
/**
 * VladAero Image Processing & EXIF Extraction Engine
 * Handles image optimization, WebP conversion, orientation correction, and metadata extraction.
 */

if (!defined('VLADAERO_ROOT')) {
    define('VLADAERO_ROOT', dirname(__DIR__));
}

class ImageHandler {
    public static function processUpload(array $file, string $subfolder = 'photos'): array {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'error' => 'Некорректный запрос загрузки'];
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'error' => 'Размер файла превышает допустимый лимит'];
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'error' => 'Файл не был выбран'];
            default:
                return ['success' => false, 'error' => 'Ошибка загрузки файла'];
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($allowedMimes[$mime])) {
            return ['success' => false, 'error' => 'Разрешены только форматы JPG, PNG и WebP'];
        }

        // Extract EXIF before resizing/converting
        $exifData = self::extractExif($file['tmp_name']);

        // Generate safe random unique filename
        $fileHash = bin2hex(random_bytes(16));
        $destDir = VLADAERO_ROOT . "/uploads/{$subfolder}";
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $origPath = "{$destDir}/{$fileHash}_orig.webp";
        $medPath  = "{$destDir}/{$fileHash}_medium.webp";
        $thumbPath = "{$destDir}/{$fileHash}_thumb.webp";

        // Load image resource
        $srcImage = self::createImageFromFile($file['tmp_name'], $mime);
        if (!$srcImage) {
            return ['success' => false, 'error' => 'Не удалось обработать изображение'];
        }

        // Fix Orientation if EXIF orientation tag exists
        if (!empty($exifData['orientation'])) {
            $srcImage = self::correctOrientation($srcImage, $exifData['orientation']);
        }

        $width = imagesx($srcImage);
        $height = imagesy($srcImage);

        // 1. Save Full/Original (max 2560px width)
        self::saveResizedWebp($srcImage, $width, $height, min(2560, $width), $origPath, 88);

        // 2. Save Medium (max 1200px width)
        self::saveResizedWebp($srcImage, $width, $height, min(1200, $width), $medPath, 82);

        // 3. Save Thumb (300x200 smart crop)
        self::saveCropThumb($srcImage, $width, $height, 360, 240, $thumbPath, 80);

        imagedestroy($srcImage);

        return [
            'success' => true,
            'photo_url' => "/uploads/{$subfolder}/{$fileHash}_orig.webp",
            'medium_url' => "/uploads/{$subfolder}/{$fileHash}_medium.webp",
            'thumb_url' => "/uploads/{$subfolder}/{$fileHash}_thumb.webp",
            'exif' => $exifData
        ];
    }

    public static function extractExif(string $filePath): array {
        $data = [
            'camera_model' => '',
            'lens' => '',
            'focal_length' => '',
            'shutter_speed' => '',
            'aperture' => '',
            'iso' => '',
            'shot_date' => null,
            'orientation' => 1
        ];

        if (!function_exists('exif_read_data')) {
            return $data;
        }

        $exif = @exif_read_data($filePath, 'ANY_TAG', true);
        if (!$exif) return $data;

        // Camera Model
        $make = $exif['IFD0']['Make'] ?? '';
        $model = $exif['IFD0']['Model'] ?? '';
        if ($model) {
            $data['camera_model'] = trim("{$make} {$model}");
            // remove duplicates like "Canon Canon EOS"
            $data['camera_model'] = preg_replace('/^(\b\w+\b)\s+\1/i', '$1', $data['camera_model']);
        }

        // Lens
        $data['lens'] = $exif['EXIF']['UndefinedTag:0xA434'] ?? ($exif['EXIF']['LensModel'] ?? '');

        // Focal Length (e.g. 70/1 -> 70mm)
        if (!empty($exif['EXIF']['FocalLength'])) {
            $fl = $exif['EXIF']['FocalLength'];
            if (is_string($fl) && strpos($fl, '/') !== false) {
                $parts = explode('/', $fl);
                $fl = ($parts[1] > 0) ? round($parts[0] / $parts[1]) : $parts[0];
            }
            $data['focal_length'] = "{$fl} mm";
        }

        // Exposure Time / Shutter Speed (e.g. 1/500s)
        if (!empty($exif['EXIF']['ExposureTime'])) {
            $data['shutter_speed'] = $exif['EXIF']['ExposureTime'] . ' s';
        }

        // Aperture / F-Number
        if (!empty($exif['EXIF']['FNumber'])) {
            $fnum = $exif['EXIF']['FNumber'];
            if (is_string($fnum) && strpos($fnum, '/') !== false) {
                $parts = explode('/', $fnum);
                $fnum = ($parts[1] > 0) ? round($parts[0] / $parts[1], 1) : $parts[0];
            }
            $data['aperture'] = "f/{$fnum}";
        }

        // ISO
        if (!empty($exif['EXIF']['ISOSpeedRatings'])) {
            $iso = is_array($exif['EXIF']['ISOSpeedRatings']) ? $exif['EXIF']['ISOSpeedRatings'][0] : $exif['EXIF']['ISOSpeedRatings'];
            $data['iso'] = "ISO {$iso}";
        }

        // Date Taken
        if (!empty($exif['EXIF']['DateTimeOriginal'])) {
            $time = strtotime($exif['EXIF']['DateTimeOriginal']);
            if ($time) $data['shot_date'] = date('Y-m-d', $time);
        }

        // Orientation
        if (!empty($exif['IFD0']['Orientation'])) {
            $data['orientation'] = (int)$exif['IFD0']['Orientation'];
        }

        return $data;
    }

    private static function createImageFromFile(string $path, string $mime) {
        if (!extension_loaded('gd')) return null;

        switch ($mime) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($path);
            case 'image/png':
                $img = @imagecreatefrompng($path);
                if ($img) imagepalettetotruecolor($img);
                return $img;
            case 'image/webp':
                return @imagecreatefromwebp($path);
            default:
                return null;
        }
    }

    private static function correctOrientation($image, int $orientation) {
        switch ($orientation) {
            case 3:
                return imagerotate($image, 180, 0);
            case 6:
                return imagerotate($image, -90, 0);
            case 8:
                return imagerotate($image, 90, 0);
            default:
                return $image;
        }
    }

    private static function saveResizedWebp($srcImage, int $srcW, int $srcH, int $targetW, string $destPath, int $quality = 85): bool {
        if ($srcW <= $targetW) {
            $newW = $srcW;
            $newH = $srcH;
        } else {
            $ratio = $targetW / $srcW;
            $newW = $targetW;
            $newH = round($srcH * $ratio);
        }

        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $srcImage, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        if (function_exists('imagewebp')) {
            $result = imagewebp($dst, $destPath, $quality);
        } else {
            $result = imagejpeg($dst, $destPath, $quality);
        }
        imagedestroy($dst);
        return $result;
    }

    private static function saveCropThumb($srcImage, int $srcW, int $srcH, int $thumbW, int $thumbH, string $destPath, int $quality = 80): bool {
        $srcRatio = $srcW / $srcH;
        $thumbRatio = $thumbW / $thumbH;

        if ($srcRatio > $thumbRatio) {
            $cropW = round($srcH * $thumbRatio);
            $cropH = $srcH;
            $cropX = round(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $srcW;
            $cropH = round($srcW / $thumbRatio);
            $cropX = 0;
            $cropY = round(($srcH - $cropH) / 2);
        }

        $dst = imagecreatetruecolor($thumbW, $thumbH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $srcImage, 0, 0, $cropX, $cropY, $thumbW, $thumbH, $cropW, $cropH);

        if (function_exists('imagewebp')) {
            $result = imagewebp($dst, $destPath, $quality);
        } else {
            $result = imagejpeg($dst, $destPath, $quality);
        }
        imagedestroy($dst);
        return $result;
    }
}
