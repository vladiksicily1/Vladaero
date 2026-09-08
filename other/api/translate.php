<?php
/**
 * ShibaLingo - Offline Rule-Based Translation API Endpoint
 * Zero API keys, zero network lag, 100% deterministic local linguistic transfer.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/translator_engine.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$text = trim($input['text'] ?? ($input['message'] ?? ''));
$from = strtolower(trim($input['from'] ?? 'ru'));
$to = strtolower(trim($input['to'] ?? 'vladikish'));

if (empty($text)) {
    echo json_encode([
        'success' => true,
        'translated_text' => '',
        'tokens' => [],
        'recognized_count' => 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = RuleBasedTranslator::translate($text, $from, $to);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
