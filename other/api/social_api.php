<?php
/**
 * ShibaLingo - Social & Friends API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user = getCurrentUser();
$db = getDb();
$driver = Database::getDriver();

// Ensure friendships table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS friendships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            friend_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, friend_id)
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS `friendships` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `friend_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_friendship` (`user_id`, `friend_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'toggle_friend') {
    $targetId = (int)($_POST['friend_id'] ?? 0);
    if ($targetId <= 0 || $targetId === (int)$user['id']) {
        echo json_encode(['success' => false, 'error' => 'Некорректный пользователь']);
        exit;
    }

    $check = $db->prepare("SELECT id FROM friendships WHERE user_id = :uid AND friend_id = :fid");
    $check->execute(['uid' => $user['id'], 'fid' => $targetId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Remove friend
        $db->prepare("DELETE FROM friendships WHERE user_id = :uid AND friend_id = :fid")->execute(['uid' => $user['id'], 'fid' => $targetId]);
        echo json_encode(['success' => true, 'is_friend' => false, 'message' => 'Удален из друзей']);
    } else {
        // Add friend
        $db->prepare("INSERT INTO friendships (user_id, friend_id) VALUES (:uid, :fid)")->execute(['uid' => $user['id'], 'fid' => $targetId]);
        
        // Notify via Telegram
        require_once __DIR__ . '/../includes/telegram.php';
        sendTelegramNotificationToUser($targetId, 'friends',
            "👥 <b>Новый друг в ShibaLingo!</b>\n\n" .
            "Пользователь <b>" . e($user['username']) . "</b> добавил вас в друзья! 🐾"
        );

        echo json_encode(['success' => true, 'is_friend' => true, 'message' => 'Добавлен в друзья! 🐾']);
    }
    exit;
}

if ($action === 'list_friends') {
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.avatar, u.selected_skin, u.xp, u.streak 
        FROM friendships f 
        JOIN users u ON f.friend_id = u.id 
        WHERE f.user_id = :uid 
        ORDER BY u.xp DESC
    ");
    $stmt->execute(['uid' => $user['id']]);
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'friends' => $friends]);
    exit;
}
