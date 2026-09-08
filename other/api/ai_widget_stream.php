<?php
/**
 * ShibaLingo - Sensei AI Chat Real-Time SSE Streaming Endpoint with Reasoning
 * Streams Reasoning (<think>...</think>) + Live Content Response + Audio triggers
 */

// Disable all output buffering for real-time SSE streaming
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', 1);
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/agent_tools.php';

function sendSenseiSse(array $data): void {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();
}

$user = getCurrentUser();
$db = getDb();

// 1. Read Request Payload
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input && isset($_GET['message'])) {
    $input = ['message' => $_GET['message'], 'lang' => $_GET['lang'] ?? 'vladikish'];
}

$userMsg = trim($input['message'] ?? '');
$langCode = trim($input['lang'] ?? 'vladikish');
$history = $input['history'] ?? [];
$sessionId = (int)($input['session_id'] ?? 1);

if (empty($userMsg)) {
    sendSenseiSse(['type' => 'error', 'error' => 'Пустое сообщение']);
    sendSenseiSse(['type' => 'done']);
    exit;
}

// 2. Prepare AI Credentials
$baseUrl = getSetting('nvidia_base_url', 'https://integrate.api.nvidia.com/v1');
$apiKey = getSetting('nvidia_api_key', '');
$model = getSetting('nvidia_model', DEFAULT_NVIDIA_MODEL);

if (empty($apiKey)) {
    sendSenseiSse(['type' => 'error', 'error' => 'API-ключ не настроен в админ-панели (Промпты & AI).']);
    sendSenseiSse(['type' => 'done']);
    exit;
}

// 3. Build Conlang & Web Context
$conlangContext = '';
if ($langCode === 'vladikish') {
    $conlangContext = getVladikishContext();
}

// Check for Web Search or URL fetch intent
$webContext = '';
if (preg_match('/https?:\/\/[^\s]+/i', $userMsg, $urlMatch)) {
    $foundUrl = $urlMatch[0];
    $fetchRes = runAgentFetchUrl($foundUrl);
    if (!empty($fetchRes['content'])) {
        $webContext .= "\n[ДАННЫЕ ИЗ ИНТЕРНЕТА ЧЕРЕЗ FIRECRAWL ПО ССЫЛКЕ {$foundUrl}]:\n";
        $webContext .= ($fetchRes['title'] ?? '') . "\n" . $fetchRes['content'] . "\n[КОНЕЦ ДАННЫХ]\n";
    }
} elseif (preg_match('/(найди|поищи|интернет|новости|гугл|поиск|search|стать|факты|кто так|что так)/ui', $userMsg)) {
    $cleanSearch = trim(preg_replace('/(найди|поищи|в интернете|пожалуйста|сиба|поищи в интернете)/ui', '', $userMsg)) ?: $userMsg;
    $searchRes = runAgentWebSearch($cleanSearch, 3);
    if (!empty($searchRes['results'])) {
        $webContext .= "\n[РЕЗУЛЬТАТЫ ПОИСКА В ИНТЕРНЕТЕ]:\n";
        foreach ($searchRes['results'] as $sr) {
            $webContext .= "- " . ($sr['title'] ?? '') . ": " . ($sr['snippet'] ?? '') . "\n";
        }
        $webContext .= "[КОНЕЦ РЕЗУЛЬТАТОВ]\n";
    }
}

// 4. Construct System Instruction with Reasoning Directive
$systemPrompt = "Ты — 'Сиба-сэнсэй' (Shiba-sensei), милый, мудрый и ободряющий пес породы Сиба-ину, обучающий языкам на платформе ShibaLingo.\n";
$systemPrompt .= "Ты общаешься с учеником на целевом языке: '{$langCode}'. Твой стиль: дружелюбный, теплый, с легкими собачьими нотками (Гав!, Лапку! 🐾).\n\n";

$systemPrompt .= "КРИТИЧЕСКОЕ ПРАВИЛО РАЗМЫШЛЕНИЙ (REASONING):\n";
$systemPrompt .= "Перед тем как дать ответ ученику, ты ВСЕГДА сначала пишешь свои глубокие размышления в теге <think>...</think> на русском языке:\n";
$systemPrompt .= "- Анализ сообщения ученика и грамматической точности\n";
$systemPrompt .= "- Проверка слов и грамматики языка Vladikish\n";
$systemPrompt .= "- План дружелюбного ответа и подбор похвалы\n";
$systemPrompt .= "Пример:\n<think>\nУченик поздоровался. Проверяю слово 'Mira' в словаре Vladikish. Отвечу приветствием и спрошу как дела.\n</think>\n";
$systemPrompt .= "Mira, Vladi! Zora bonu est 🐕 (Привет, друг! Хорошего дня!)\n\n";

$systemPrompt .= "СТРОГОЕ ПРАВИЛО ЧЕСТНОСТИ (ANTI-HALLUCINATION):\n";
$systemPrompt .= "Если ты не знаешь ответа на вопрос ученика, если слова нет в словаре Vladikish или если поиск в интернете не дал результатов — ты КАТЕГОРИЧЕСКИ НЕ ИМЕЕШЬ ПРАВА выдумывать факты, сочинять несуществующие слова или врать. Честно скажи в стиле доброго Сибы: «Гав! К сожалению, мне не удалось найти точную информацию об этом 🐾» и поясни, что известно точно.\n\n";

if (!empty($webContext)) {
    $systemPrompt .= "ДАННЫЕ ИЗ ИНТЕРНЕТА:\n{$webContext}\n\n";
}

if ($langCode === 'vladikish') {
    $systemPrompt .= "ОФИЦИАЛЬНЫЕ ЗНАНИЯ О ВЫДУМАННОМ ЯЗЫКЕ VLADIKISH:\n";
    $systemPrompt .= $conlangContext . "\n";
    $systemPrompt .= "Всегда используй официальные слова Vladikish и давай перевод реплики на русском языке в скобках.\n";
}

$messages = [
    ['role' => 'system', 'content' => $systemPrompt]
];

// Append history
if (!empty($history) && is_array($history)) {
    foreach ($history as $h) {
        if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'])) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
    }
}

$messages[] = ['role' => 'user', 'content' => $userMsg];

sendSenseiSse([
    'type' => 'status',
    'status' => 'thinking',
    'message' => '🧠 Сиба-сэнсэй анализирует контекст и грамматику...'
]);

$endpointUrl = rtrim($baseUrl, '/') . '/chat/completions';

$maxOutputTokens = (int)getSetting('ai_max_tokens', 8192);

$payload = [
    'model' => $model,
    'messages' => $messages,
    'temperature' => 0.4,
    'max_tokens' => $maxOutputTokens > 0 ? $maxOutputTokens : 8192
];

$ch = curl_init($endpointUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . trim($apiKey)
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    sendSenseiSse(['type' => 'error', 'error' => 'Ошибка сети cURL: ' . $curlErr]);
    sendSenseiSse(['type' => 'done']);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $data['error']['message'] ?? $data['message'] ?? "HTTP $httpCode: $response";
    sendSenseiSse(['type' => 'error', 'error' => "Ошибка модели ($httpCode): $errMsg"]);
    sendSenseiSse(['type' => 'done']);
    exit;
}

$rawText = $data['choices'][0]['message']['content'] ?? '';
$reasoningContent = $data['choices'][0]['message']['reasoning_content'] ?? '';

// 1. Check if model provided reasoning_content natively (DeepSeek R1 / o1 / o3 / Qwen)
$reasoningText = '';
$finalReplyText = $rawText;

if (!empty($reasoningContent)) {
    $reasoningText = trim($reasoningContent);
} elseif (preg_match('/<think>(.*?)<\/think>/is', $rawText, $thinkMatch)) {
    $reasoningText = trim($thinkMatch[1]);
    $finalReplyText = trim(str_replace($thinkMatch[0], '', $rawText));
}

// 2. Stream Reasoning block if present
if (!empty($reasoningText)) {
    // Stream reasoning in smooth realistic chunks
    $reasoningWords = preg_split('/(\s+)/u', $reasoningText, -1, PREG_SPLIT_DELIM_CAPTURE);
    $chunkBuffer = '';
    
    foreach ($reasoningWords as $w) {
        $chunkBuffer .= $w;
        if (mb_strlen($chunkBuffer) > 25 || strpos($w, "\n") !== false) {
            sendSenseiSse([
                'type' => 'reasoning_chunk',
                'delta' => $chunkBuffer
            ]);
            $chunkBuffer = '';
            usleep(15000); // 15ms delay for visual stream effect
        }
    }
    if (!empty($chunkBuffer)) {
        sendSenseiSse([
            'type' => 'reasoning_chunk',
            'delta' => $chunkBuffer
        ]);
    }

    sendSenseiSse(['type' => 'reasoning_done']);
}

// 3. Stream Main Response text in realistic character chunks
$replyWords = preg_split('/(\s+)/u', $finalReplyText, -1, PREG_SPLIT_DELIM_CAPTURE);
$replyBuffer = '';

foreach ($replyWords as $rw) {
    $replyBuffer .= $rw;
    if (mb_strlen($replyBuffer) > 20 || strpos($rw, "\n") !== false) {
        sendSenseiSse([
            'type' => 'content_chunk',
            'delta' => $replyBuffer
        ]);
        $replyBuffer = '';
        usleep(20000); // 20ms delay for ultra-smooth fluid typing effect
    }
}
if (!empty($replyBuffer)) {
    sendSenseiSse([
        'type' => 'content_chunk',
        'delta' => $replyBuffer
    ]);
}

// 4. Save to Database
try {
    $saveAi = $db->prepare("INSERT INTO " . tbl('chat_messages') . " (session_id, sender, message) VALUES (:sid, 'shiba', :msg)");
    $saveAi->execute([
        'sid' => $sessionId,
        'msg' => $finalReplyText
    ]);
} catch (Exception $e) {}

// 5. Final completion event
sendSenseiSse([
    'type' => 'done',
    'reply' => $finalReplyText,
    'reasoning' => $reasoningText,
    'model' => $model
]);
