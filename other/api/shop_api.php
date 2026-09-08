<?php
/**
 * Shop API (Purchase items, Equip skins, Refill hearts)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$user = getCurrentUser();
$db = getDb();

if ($action === 'buy_item') {
    $itemKey = trim($input['item_key'] ?? '');
    
    // Fetch item
    $stmt = $db->prepare("SELECT * FROM shop_items WHERE item_key = :k");
    $stmt->execute(['k' => $itemKey]);
    $item = $stmt->fetch();

    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Предмет не найден']);
        exit;
    }

    $price = (int)$item['price_gems'];
    $userGems = (int)($user['gems'] ?? 0);

    if ($userGems < $price) {
        echo json_encode(['success' => false, 'error' => "Недостаточно кристаллов 💎! Нужно: {$price}, у вас: {$userGems}"]);
        exit;
    }

    // Deduct gems
    $deduct = $db->prepare("UPDATE " . tbl('users') . " SET gems = gems - :p WHERE id = :id");
    $deduct->execute(['p' => $price, 'id' => $user['id']]);

    // Apply immediate item effects
    if ($item['item_key'] === 'heart_refill') {
        $db->prepare("UPDATE " . tbl('users') . " SET hearts = 5 WHERE id = :id")->execute(['id' => $user['id']]);
    } elseif ($item['item_key'] === 'streak_freeze') {
        $db->prepare("UPDATE " . tbl('users') . " SET streak_freeze = COALESCE(streak_freeze, 0) + 1 WHERE id = :id")->execute(['id' => $user['id']]);
    } elseif ($item['item_key'] === 'super_shiba') {
        $db->prepare("UPDATE " . tbl('users') . " SET hearts = 999 WHERE id = :id")->execute(['id' => $user['id']]);
    } elseif ($item['category'] === 'skin') {
        $skinCode = str_replace('skin_', '', $item['item_key']);
        $db->prepare("UPDATE " . tbl('users') . " SET selected_skin = :s WHERE id = :id")->execute(['s' => $skinCode, 'id' => $user['id']]);
    }

    // Add to inventory
    if (Database::getDriver() === 'sqlite') {
        $inv = $db->prepare("INSERT INTO " . tbl('user_inventory') . " (user_id, item_key, quantity) VALUES (:u, :k, 1) ON CONFLICT(user_id, item_key) DO UPDATE SET quantity = quantity + 1");
    } else {
        $inv = $db->prepare("INSERT INTO " . tbl('user_inventory') . " (user_id, item_key, quantity) VALUES (:u, :k, 1) ON DUPLICATE KEY UPDATE quantity = quantity + 1");
    }
    $inv->execute(['u' => $user['id'], 'k' => $itemKey]);

    echo json_encode([
        'success' => true,
        'new_gems' => $userGems - $price,
        'message' => "Вы успешно приобрели «{$item['name']}»!"
    ]);
    exit;
}

if ($action === 'equip_skin') {
    $skin = trim($input['skin'] ?? 'classic');
    $db->prepare("UPDATE users SET selected_skin = :s WHERE id = :id")->execute(['s' => $skin, 'id' => $user['id']]);
    echo json_encode(['success' => true, 'selected_skin' => $skin]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
