<?php
/**
 * ShibaLingo - Real Server-Synced Global Raid Boss API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user = getCurrentUser();
$db = getDb();
$driver = Database::getDriver();

// Ensure raid_bosses table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS raid_bosses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            boss_name TEXT NOT NULL,
            level INTEGER DEFAULT 50,
            max_hp INTEGER DEFAULT 10000,
            current_hp INTEGER DEFAULT 4250,
            total_hits INTEGER DEFAULT 0,
            last_hit_user_id INTEGER,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS `raid_bosses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `boss_name` VARCHAR(150) NOT NULL,
            `level` INT DEFAULT 50,
            `max_hp` INT DEFAULT 10000,
            `current_hp` INT DEFAULT 4250,
            `total_hits` INT DEFAULT 0,
            `last_hit_user_id` INT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Seed default boss if empty
    $count = (int)$db->query("SELECT COUNT(*) FROM raid_bosses")->fetchColumn();
    if ($count === 0) {
        $db->exec("INSERT INTO raid_bosses (boss_name, level, max_hp, current_hp) VALUES ('Древний Голем Грамматических Ошибок', 50, 10000, 7850)");
    }
} catch (Exception $e) {}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'status');

if ($action === 'status') {
    $boss = $db->query("SELECT * FROM raid_bosses ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$boss) {
        $boss = ['boss_name' => 'Голем Ошибок', 'max_hp' => 10000, 'current_hp' => 5000, 'level' => 50];
    }
    echo json_encode([
        'success' => true,
        'boss' => $boss
    ]);
    exit;
}

if ($action === 'attack') {
    $dmg = rand(40, 85);
    $boss = $db->query("SELECT * FROM raid_bosses ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$boss) {
        $boss = ['id' => 1, 'max_hp' => 10000, 'current_hp' => 5000];
    }

    $newHp = max(0, (int)$boss['current_hp'] - $dmg);

    // If defeated, respawn with higher level
    if ($newHp <= 0) {
        $newHp = (int)$boss['max_hp'] + 2000;
        $db->exec("UPDATE raid_bosses SET current_hp = {$newHp}, max_hp = {$newHp}, level = level + 1, total_hits = total_hits + 1, last_hit_user_id = {$user['id']} WHERE id = {$boss['id']}");
        $isDefeated = true;
    } else {
        $db->exec("UPDATE raid_bosses SET current_hp = {$newHp}, total_hits = total_hits + 1, last_hit_user_id = {$user['id']} WHERE id = {$boss['id']}");
        $isDefeated = false;
    }

    // Award user XP
    $db->exec("UPDATE users SET xp = xp + 10 WHERE id = {$user['id']}");

    echo json_encode([
        'success' => true,
        'damage' => $dmg,
        'new_hp' => $newHp,
        'max_hp' => (int)$boss['max_hp'],
        'is_defeated' => $isDefeated,
        'xp_gained' => 10
    ]);
    exit;
}
