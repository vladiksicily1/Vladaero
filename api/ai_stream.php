<?php
/**
 * VladAero Real-Time AI Streaming & Reasoning API (SSE)
 */

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no'); // Disable Nginx buffering
header('Connection: keep-alive');

require_once dirname(__DIR__) . '/includes/ai_service.php';
require_once dirname(__DIR__) . '/includes/auth.php';

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true) ?: [];

$messages = $input['messages'] ?? [];
if (empty($messages) || !is_array($messages)) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Сообщения не переданы'], JSON_UNESCAPED_UNICODE) . "\n\n";
    exit;
}

$isAdmin = Auth::hasRole('admin') && (!empty($input['is_admin']));

AIService::streamChat($messages, $isAdmin);
