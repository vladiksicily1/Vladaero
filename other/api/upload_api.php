<?php
/**
 * ShibaLingo - Media Upload API (Images, Audio, Avatars)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Метод запроса должен быть POST']);
    exit;
}

$fileKey = isset($_FILES['file']) ? 'file' : (isset($_FILES['media']) ? 'media' : (isset($_FILES['avatar']) ? 'avatar' : ''));

if (empty($fileKey) || empty($_FILES[$fileKey]['name'])) {
    echo json_encode(['success' => false, 'error' => 'Файл не был передан']);
    exit;
}

$file = $_FILES[$fileKey];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'Размер файла превышает лимит сервера (upload_max_filesize)',
        UPLOAD_ERR_FORM_SIZE => 'Размер файла превышает лимит формы',
        UPLOAD_ERR_PARTIAL => 'Файл был загружен только частично',
        UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
        UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка на сервере',
        UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
        UPLOAD_ERR_EXTENSION => 'Загрузка файла прервана расширением PHP'
    ];
    $errMsg = $uploadErrors[$file['error']] ?? 'Ошибка загрузки файла';
    echo json_encode(['success' => false, 'error' => $errMsg]);
    exit;
}

// 15 MB limit
$maxSizeBytes = 15 * 1024 * 1024;
if ($file['size'] > $maxSizeBytes) {
    echo json_encode(['success' => false, 'error' => 'Размер файла превышает 15 МБ']);
    exit;
}

// Allowed extensions
$allowedExts = [
    // Images
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    // Audio
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'm4a' => 'audio/mp4'
];

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!array_key_exists($ext, $allowedExts)) {
    echo json_encode(['success' => false, 'error' => 'Недопустимый формат файла. Разрешены: JPG, PNG, GIF, WEBP, SVG, MP3, WAV, OGG, M4A']);
    exit;
}

// Ensure uploads folder exists
$uploadsDir = realpath(__DIR__ . '/../uploads');
if (!$uploadsDir) {
    $uploadsDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0755, true);
    }
    $uploadsDir = realpath($uploadsDir);
}

if (!is_writable($uploadsDir)) {
    echo json_encode(['success' => false, 'error' => 'Папка uploads недоступна для записи']);
    exit;
}

// Prefix classification
$type = $_POST['type'] ?? 'media';
$prefix = ($type === 'avatar') ? 'avatar_' : (($type === 'mascot') ? 'mascot_' : (($type === 'audio') ? 'audio_' : 'media_'));
$newFilename = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$targetPath = $uploadsDir . DIRECTORY_SEPARATOR . $newFilename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success' => false, 'error' => 'Не удалось переместить загруженный файл']);
    exit;
}

$relativeUrl = 'uploads/' . $newFilename;
$user = getCurrentUser();

// Handle direct database updates if requested
if ($type === 'avatar' && !empty($user['id'])) {
    $db = getDb();
    $stmt = $db->prepare("UPDATE " . tbl('users') . " SET avatar = :av WHERE id = :id");
    $stmt->execute(['av' => $relativeUrl, 'id' => $user['id']]);
} elseif ($type === 'mascot') {
    setSetting('mascot_custom_avatar', $relativeUrl);
}

echo json_encode([
    'success' => true,
    'url' => $relativeUrl,
    'filename' => $newFilename,
    'original_name' => $file['name'],
    'size_bytes' => $file['size'],
    'type' => $type,
    'message' => 'Файл успешно загружен!'
]);
