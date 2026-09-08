<?php
/**
 * VladAero AI Copilot & Admin Agent Chat API Endpoint
 */

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/ai_service.php';
require_once dirname(__DIR__) . '/includes/auth.php';

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true) ?: [];

$messages = $input['messages'] ?? [];
if (empty($messages) || !is_array($messages)) {
    echo json_encode(['success' => false, 'error' => 'Сообщения не переданы']);
    exit;
}

$isAdmin = Auth::hasRole('admin') && (!empty($input['is_admin']));

$result = AIService::chat($messages, $isAdmin);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
