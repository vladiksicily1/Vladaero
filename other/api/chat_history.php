<?php
/**
 * Chat history and sessions API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$user = getCurrentUser();
$db = getDb();

if ($action === 'create_session') {
    $title = trim($input['title'] ?? 'Новый диалог');
    $lang = trim($input['lang'] ?? $user['current_language']);

    $stmt = $db->prepare("INSERT INTO chat_sessions (user_id, title, language_code) VALUES (:uid, :title, :lang)");
    $stmt->execute(['uid' => $user['id'], 'title' => $title, 'lang' => $lang]);
    $newId = $db->lastInsertId();

    // Initial greeting from Shiba
    $greeting = ($lang === 'vladikish') 
        ? "Mira, Vladi! Me Shiba-sensei. Plaso, lingo me!" 
        : "Hello! I am Shiba-sensei. Let's practice speaking today!";
    
    $tr = ($lang === 'vladikish') ? "Привет, друг! Я Сиба-сэнсэй. Пожалуйста, поговори со мной!" : "Привет! Я Сиба-сэнсэй. Давай попрактикуемся!";

    $initMsg = $db->prepare("INSERT INTO chat_messages (session_id, sender, message, translation) VALUES (:sid, 'shiba', :msg, :tr)");
    $initMsg->execute(['sid' => $newId, 'msg' => $greeting, 'tr' => $tr]);

    echo json_encode(['success' => true, 'session_id' => $newId]);
    exit;
}

if ($action === 'delete_session') {
    $sessionId = (int)($input['session_id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM chat_sessions WHERE id = :sid AND user_id = :uid");
    $stmt->execute(['sid' => $sessionId, 'uid' => $user['id']]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
