<?php
/**
 * Save completed lesson and award XP
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$lessonId = (int)($input['lesson_id'] ?? 0);
$xpEarned = (int)($input['xp'] ?? 15);

if ($lessonId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid lesson ID']);
    exit;
}

$user = getCurrentUser();
$db = getDb();

try {
    // Record user progress
    $progStmt = $db->prepare("INSERT INTO user_progress (user_id, lesson_id, score) VALUES (:uid, :lid, 100)");
    $progStmt->execute(['uid' => $user['id'], 'lid' => $lessonId]);

    // Update user XP
    $updateUser = $db->prepare("UPDATE users SET xp = xp + :xp WHERE id = :id");
    $updateUser->execute(['xp' => $xpEarned, 'id' => $user['id']]);

    // 1. Spaced Repetition (SRS) - Record Mistakes
    $mistakes = $input['mistakes'] ?? [];
    if (!empty($mistakes) && is_array($mistakes)) {
        try {
            $driver = Database::getDriver();
            if ($driver === 'sqlite') {
                $db->exec("CREATE TABLE IF NOT EXISTS user_word_stats (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    word TEXT NOT NULL,
                    wrong_count INTEGER DEFAULT 1,
                    next_review_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(user_id, word)
                )");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS `user_word_stats` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT NOT NULL,
                    `word` VARCHAR(100) NOT NULL,
                    `wrong_count` INT DEFAULT 1,
                    `next_review_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `user_word_unique` (`user_id`, `word`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }

            foreach ($mistakes as $mWord) {
                $w = trim(is_string($mWord) ? $mWord : ($mWord['word'] ?? ''));
                if (!empty($w)) {
                    if ($driver === 'sqlite') {
                        $db->prepare("INSERT INTO user_word_stats (user_id, word, wrong_count) VALUES (:uid, :w, 1) ON CONFLICT(user_id, word) DO UPDATE SET wrong_count = wrong_count + 1")->execute(['uid' => $user['id'], 'w' => $w]);
                    } else {
                        $db->prepare("INSERT INTO user_word_stats (user_id, word, wrong_count) VALUES (:uid, :w, 1) ON DUPLICATE KEY UPDATE wrong_count = wrong_count + 1")->execute(['uid' => $user['id'], 'w' => $w]);
                    }
                }
            }
        } catch (Exception $e) {}
    }

    // 2. Global Raid Boss Damage (-50 DMG)
    try {
        $db->exec("UPDATE raid_bosses SET current_hp = CASE WHEN current_hp > 50 THEN current_hp - 50 ELSE 10000 END, total_hits = total_hits + 1, last_hit_user_id = {$user['id']} WHERE id = 1");
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'new_xp' => (int)$user['xp'] + $xpEarned
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
