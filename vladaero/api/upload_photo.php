<?php
declare(strict_types=1);

namespace VladAero;

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/image_handler.php';

if (!Auth::check()) {
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация для загрузки фотографий.']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Ошибка безопасности (неверный CSRF токен).']);
    exit;
}

if (empty($_FILES['photo'])) {
    echo json_encode(['success' => false, 'error' => 'Файл фотографии не выбран.']);
    exit;
}

$user = Auth::user();
$authorName = $user['full_name'] ?: $user['username'];

$processed = ImageHandler::processSpottingPhoto($_FILES['photo'], $authorName);
if (!$processed['success']) {
    echo json_encode($processed);
    exit;
}

// Check auto-approve rule: Trusted spotters & staff get instant approve
$isAutoApproved = in_array($user['role'], ['admin', 'moderator', 'screener', 'spotter'], true) || ((int)$user['xp_points'] >= 1500);

$photoId = DB::insert('va_photos', [
    'user_id'         => $user['id'],
    'aircraft_id'     => !empty($_POST['aircraft_id']) ? (int)$_POST['aircraft_id'] : null,
    'airport_id'      => !empty($_POST['airport_id']) ? (int)$_POST['airport_id'] : null,
    'airline_id'      => !empty($_POST['airline_id']) ? (int)$_POST['airline_id'] : null,
    'tail_number'     => strtoupper(trim($_POST['tail_number'] ?? '')),
    'msn'             => trim($_POST['msn'] ?? ''),
    'special_livery'  => trim($_POST['special_livery'] ?? ''),
    'photo_url'       => $processed['photo_url'],
    'medium_url'      => $processed['medium_url'],
    'thumb_url'       => $processed['thumb_url'],
    'camera_model'    => $processed['exif']['camera_model'] ?? null,
    'lens'            => $processed['exif']['lens'] ?? null,
    'focal_length'    => $processed['exif']['focal_length'] ?? null,
    'shutter_speed'   => $processed['exif']['shutter_speed'] ?? null,
    'aperture'        => $processed['exif']['aperture'] ?? null,
    'iso'             => $processed['exif']['iso'] ?? null,
    'shot_date'       => $processed['exif']['shot_date'] ?? date('Y-m-d'),
    'aircraft_status' => $_POST['aircraft_status'] ?? 'active',
    'license_type'    => $_POST['license_type'] ?? 'all_rights_reserved',
    'status'          => $isAutoApproved ? 'approved' : 'pending'
]);

if ($photoId) {
    Auth::addXp((int)$user['id'], $isAutoApproved ? 50 : 20);
    echo json_encode([
        'success'        => true,
        'message'        => $isAutoApproved ? 'Фотография успешно опубликована!' : 'Фото отправлено в очередь скрининга модераторам.',
        'photo_id'       => $photoId,
        'thumb_url'      => $processed['thumb_url'],
        'auto_approved'  => $isAutoApproved
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Ошибка сохранения записи в базу данных.']);
}
