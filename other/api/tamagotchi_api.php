<?php
/**
 * Tamagotchi API (Feed treats, Pet, Increase happiness)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$user = getCurrentUser();
$db = getDb();

if ($action === 'feed_pet') {
    $treat = trim($input['treat'] ?? 'cookie');
    $cost = ($treat === 'bone') ? 10 : (($treat === 'ramen') ? 25 : 5);
    $userGems = (int)($user['gems'] ?? 50);

    if ($userGems < $cost) {
        echo json_encode(['success' => false, 'error' => "Недостаточно кристаллов 💎 (Нужно: {$cost})"]);
        exit;
    }

    // Deduct gems
    $db->prepare("UPDATE users SET gems = gems - :c WHERE id = :id")->execute(['c' => $cost, 'id' => $user['id']]);

    $messages = [
        'cookie' => 'Хрусь! Сиба с удовольствием слопал печеньку! 🍪 (+20 Сытости)',
        'bone' => 'Гав! Золотая косточка пришлась по вкусу! 🦴 (+50 Сытости)',
        'ramen' => 'Чавк-чавк! Горячий рамен поднял настроение на максимум! 🍜 (+100 Радости)'
    ];

    echo json_encode([
        'success' => true,
        'new_gems' => $userGems - $cost,
        'message' => $messages[$treat] ?? 'Сиба доволен!'
    ]);
    exit;
}

if ($action === 'pet_shiba') {
    $barks = [
        'Гав-гав! Люблю, когда меня чешут за ушком! 🐾',
        'Виль-виль хвостиком! Ты мой лучший друг, Влад! 💖',
        'Тяф! Давай учить новые слова Vladikish вместе! ✨'
    ];
    $randomBark = $barks[array_rand($barks)];

    echo json_encode([
        'success' => true,
        'message' => $randomBark
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
