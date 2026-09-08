<?php
/**
 * VladAero Spotter Photo Upload API Endpoint
 */

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/image_handler.php';
require_once dirname(__DIR__) . '/includes/functions.php';

Auth::startSession();
if (!Auth::isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация для загрузки фотографий']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['photo'])) {
    echo json_encode(['success' => false, 'error' => 'Файл не передан']);
    exit;
}

$user = Auth::getCurrentUser();
$res = ImageHandler::processUpload($_FILES['photo'], 'photos');

if (!$res['success']) {
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

$exif = $res['exif'];
$tailNumber = strtoupper(trim($_POST['tail_number'] ?? ''));
$aircraftId = !empty($_POST['aircraft_id']) ? (int)$_POST['aircraft_id'] : null;
$airportId = !empty($_POST['airport_id']) ? (int)$_POST['airport_id'] : null;

// Determine moderation status: Admins/Moderators approved instantly, standard users pending
$status = Auth::hasRole('admin', 'moderator') ? 'approved' : 'pending';

$photoId = Database::insert('photos', [
    'user_id' => $user['id'],
    'aircraft_id' => $aircraftId,
    'airport_id' => $airportId,
    'tail_number' => $tailNumber,
    'photo_url' => $res['photo_url'],
    'medium_url' => $res['medium_url'],
    'thumb_url' => $res['thumb_url'],
    'camera_model' => $exif['camera_model'] ?: ($_POST['camera_model'] ?? ''),
    'lens' => $exif['lens'] ?: ($_POST['lens'] ?? ''),
    'focal_length' => $exif['focal_length'] ?: '',
    'shutter_speed' => $exif['shutter_speed'] ?: '',
    'aperture' => $exif['aperture'] ?: '',
    'iso' => $exif['iso'] ?: '',
    'shot_date' => $exif['shot_date'] ?: date('Y-m-d'),
    'status' => $status
]);

// Award XP points for photo upload
Auth::addXp($user['id'], 50, 'Загрузка фото споттинга');

echo json_encode([
    'success' => true,
    'photo_id' => $photoId,
    'status' => $status,
    'photo_url' => $res['photo_url'],
    'thumb_url' => $res['thumb_url'],
    'exif' => $exif
], JSON_UNESCAPED_UNICODE);
