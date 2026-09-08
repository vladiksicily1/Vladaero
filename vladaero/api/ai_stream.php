<?php
declare(strict_types=1);

namespace VladAero;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/weather_decoder.php';
require_once dirname(__DIR__) . '/includes/e6b.php';
require_once dirname(__DIR__) . '/includes/ai_service.php';

$userMsg = trim($_GET['message'] ?? '');
$isAdmin = !empty($_GET['admin']) && Auth::isAdmin();

if (empty($userMsg)) {
    echo "data: " . json_encode(['error' => 'Пустое сообщение']) . "\n\n";
    exit;
}

$messages = [['role' => 'user', 'content' => $userMsg]];
$response = AIService::chat($messages, $isAdmin);

$text = $response['content'] ?? ($response['error'] ?? 'Нет ответа');

// Stream words one by one for typing effect
$words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
foreach ($words as $w) {
    echo "data: " . json_encode(['chunk' => $w], JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    @flush();
    usleep(15000); // 15ms per chunk
}

echo "data: [DONE]\n\n";
@ob_flush();
@flush();
