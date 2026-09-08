<?php
/**
 * API Endpoint for Hearts Refill & Regeneration
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Необходима авторизация']);
    exit;
}

$db = getDb();
$action = $_GET['action'] ?? 'status';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($action === 'refill_gems') {
    $cost = 350;
    if ((int)$user['gems'] < $cost) {
        echo json_encode(['success' => false, 'error' => "Недостаточно кристаллов! Нужно {$cost} 💎."]);
        exit;
    }

    $db->prepare("UPDATE " . tbl('users') . " SET hearts = 5, gems = gems - :cost WHERE id = :id")->execute([
        'cost' => $cost,
        'id' => $user['id']
    ]);

    echo json_encode([
        'success' => true,
        'hearts' => 5,
        'gems' => (int)$user['gems'] - $cost,
        'message' => 'Жизни полностью восстановлены! ❤️❤️❤️❤️❤️'
    ]);
    exit;
}

if ($action === 'practice_reward') {
    $newHearts = min(5, (int)$user['hearts'] + 1);
    $db->prepare("UPDATE " . tbl('users') . " SET hearts = :h, xp = xp + 10 WHERE id = :id")->execute([
        'h' => $newHearts,
        'id' => $user['id']
    ]);

    echo json_encode([
        'success' => true,
        'hearts' => $newHearts,
        'message' => '+1 ❤️ Жизнь восстановлена за тренировку!'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'hearts' => (int)$user['hearts'],
    'gems' => (int)$user['gems']
]);
