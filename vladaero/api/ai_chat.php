<?php
declare(strict_types=1);

namespace VladAero;

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/weather_decoder.php';
require_once dirname(__DIR__) . '/includes/e6b.php';
require_once dirname(__DIR__) . '/includes/ai_service.php';

$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
$userMsg = trim($input['message'] ?? '');
$isAdminAgent = !empty($input['is_admin_agent']) && Auth::isAdmin();

if (empty($userMsg)) {
    echo json_encode(['success' => false, 'error' => 'Пустое сообщение.']);
    exit;
}

$messages = [
    ['role' => 'user', 'content' => $userMsg]
];

$response = AIService::chat($messages, $isAdminAgent);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
