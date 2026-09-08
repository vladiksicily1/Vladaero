<?php
/**
 * ShibaLingo - Live 1v1 PvP Duel Room API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user = getCurrentUser();
$db = getDb();
$driver = Database::getDriver();

// Ensure duel_rooms table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS duel_rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_code TEXT NOT NULL UNIQUE,
            host_user_id INTEGER NOT NULL,
            guest_user_id INTEGER,
            language_code TEXT DEFAULT 'vladikish',
            status TEXT DEFAULT 'waiting',
            questions_data TEXT NOT NULL,
            host_score INTEGER DEFAULT 0,
            guest_score INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (Exception $e) {}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// 1. Create a Duel Room
if ($action === 'create_room') {
    $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));

    // Sample 5 random questions from dictionary
    $words = $db->query("SELECT word, translation_ru FROM conlang_dictionary ORDER BY " . ($driver === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($words)) {
        $words = [
            ['word' => 'Barka', 'translation_ru' => 'собака'],
            ['word' => 'Aero', 'translation_ru' => 'небо'],
            ['word' => 'Zora', 'translation_ru' => 'день'],
            ['word' => 'Toro', 'translation_ru' => 'любить'],
            ['word' => 'Sabi', 'translation_ru' => 'знать']
        ];
    }

    $qJson = json_encode($words, JSON_UNESCAPED_UNICODE);

    $stmt = $db->prepare("INSERT INTO duel_rooms (room_code, host_user_id, status, questions_data) VALUES (:code, :uid, 'waiting', :q)");
    $stmt->execute([
        'code' => $code,
        'uid' => $user['id'],
        'q' => $qJson
    ]);

    echo json_encode([
        'success' => true,
        'room_code' => $code,
        'questions' => $words
    ]);
    exit;
}

// 2. Join a Duel Room
if ($action === 'join_room') {
    $code = strtoupper(trim($_POST['room_code'] ?? ''));
    if (empty($code)) {
        echo json_encode(['success' => false, 'error' => 'Введите код комнаты']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM duel_rooms WHERE room_code = :code LIMIT 1");
    $stmt->execute(['code' => $code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        echo json_encode(['success' => false, 'error' => 'Комната с таким кодом не найдена']);
        exit;
    }

    if ($room['host_user_id'] != $user['id'] && empty($room['guest_user_id'])) {
        $db->prepare("UPDATE duel_rooms SET guest_user_id = :gid, status = 'playing' WHERE id = :id")->execute(['gid' => $user['id'], 'id' => $room['id']]);
        
        // Notify host via Telegram
        require_once __DIR__ . '/../includes/telegram.php';
        sendTelegramNotificationToUser((int)$room['host_user_id'], 'duels',
            "⚔️ <b>Дуэль началась!</b>\n\n" .
            "Игрок <b>" . e($user['username']) . "</b> зашел в вашу комнату <code>{$code}</code>! 🐕"
        );
    }

    $questions = json_decode($room['questions_data'], true) ?: [];

    echo json_encode([
        'success' => true,
        'room_code' => $code,
        'status' => $room['status'],
        'is_host' => ($room['host_user_id'] == $user['id']),
        'questions' => $questions
    ]);
    exit;
}

// 3. Sync State / Submit Score
if ($action === 'sync_score') {
    $code = strtoupper(trim($_POST['room_code'] ?? ''));
    $score = (int)($_POST['score'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM duel_rooms WHERE room_code = :code LIMIT 1");
    $stmt->execute(['code' => $code]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($room) {
        if ($room['host_user_id'] == $user['id']) {
            $db->prepare("UPDATE duel_rooms SET host_score = :s WHERE id = :id")->execute(['s' => $score, 'id' => $room['id']]);
        } else {
            $db->prepare("UPDATE duel_rooms SET guest_score = :s, status = 'playing' WHERE id = :id")->execute(['s' => $score, 'id' => $room['id']]);
        }

        // Refetch updated room state
        $stmt->execute(['code' => $code]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'host_score' => (int)$room['host_score'],
            'guest_score' => (int)$room['guest_score'],
            'status' => $room['status'],
            'has_guest' => !empty($room['guest_user_id'])
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Комната не найдена']);
    exit;
}
