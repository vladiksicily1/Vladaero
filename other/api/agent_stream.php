<?php
/**
 * ShibaLingo - Real-Time Autonomous Admin AI Agent Streaming Endpoint
 * Multi-Step Native Tool Calling (up to 10 tool calls per run) + SSE Streaming + ReAct Fallback
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
require_once __DIR__ . '/../admin/auth_check.php';

function sendSse(array $data): void {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();
}

// 1. Authenticate Admin (from admin session or user session)
$admin = getAdminUser();
if (!$admin) {
    $admin = getCurrentUser();
}

if (!$admin) {
    sendSse(['type' => 'error', 'error' => 'Ошибка: Требуется авторизация в панели администратора. Войдите в аккаунт админа.']);
    sendSse(['type' => 'done']);
    exit;
}

// 2. Read Request Payload
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input && isset($_GET['task'])) {
    $input = ['task' => $_GET['task']];
}

$task = trim($input['task'] ?? '');
$history = $input['history'] ?? [];

if (empty($task)) {
    sendSse(['type' => 'error', 'error' => 'Текст задачи не может быть пустым.']);
    sendSse(['type' => 'done']);
    exit;
}

// 3. Prepare AI Credentials
$baseUrl = getSetting('nvidia_base_url', 'https://integrate.api.nvidia.com/v1');
$apiKey = getSetting('nvidia_api_key', '');
$model = getSetting('nvidia_model', DEFAULT_NVIDIA_MODEL);

if (empty($apiKey)) {
    sendSse(['type' => 'error', 'error' => 'В настройках не указан API ключ для ИИ! Перейдите в Промпты & AI для настройки.']);
    sendSse(['type' => 'done']);
    exit;
}

// 4. Construct System Instruction with Tools Schema
$tools = getAdminAgentToolDefinitions();
$toolsJson = json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$systemPrompt = "Ты — Главный Автономный AI Агент и Архитектор платформы изучения языков ShibaLingo.\n";
$systemPrompt .= "У тебя есть полный набор встроенных инструментов (tools) для управления базой данных, созданием курсов, уроков, историй, слов словаря Vladikish, грамматики, квестов, достижений, промокодов, пользователей, а также доступ к живому поиску в интернете (web_search) и чтению веб-страниц (fetch_url) через Firecrawl.\n\n";

$systemPrompt .= "КРИТИЧЕСКИ ВАЖНЫЕ ПРАВИЛА РАБОТЫ:\n";
$systemPrompt .= "1. ВСЕГДА ПИШИ ТЕКСТ ПЕРЕД ВЫЗОВОМ ИНСТРУМЕНТОВ: Перед тем как вызвать любой инструмент (или группу инструментов), ты ОБЯЗАН сначала написать понятное текстовое пояснение для пользователя (свои мысли, план действий, что ты анализируешь или что собираешься создать). НИКОГДА не вызывай инструменты молча без предварительного текста!\n";
$systemPrompt .= "2. ОБЯЗАТЕЛЬНЫЙ ВЫЗОВ `get_vladikish_knowledge` ПРИ ЛЮБОЙ РАБОТЕ С VLADIKISH (ШАГ №1): Главный язык платформы — искусственный язык 'Vladikish' (Владикиш). Если задача пользователя ХОТЬ КАК-ТО связана с языком Vladikish (создание урока, добавление слов, перевод, грамматика, упражнения, истории, проверка правил) — твоим САМЫМ ПЕРВЫМ ДЕЙСТВИЕМ ВСЕГДА ДОЛЖЕН БЫТЬ вызов инструмента `get_vladikish_knowledge`! Никогда не пытайся отвечать или создавать контент на Vladikish без предварительного вызова `get_vladikish_knowledge`.\n";
$systemPrompt .= "3. Ты можешь вызывать до 10 инструментов подряд в цепочке (ReAct loop), чтобы полностью решить задачу пользователя без лишних вопросов.\n";
$systemPrompt .= "4. Если пользователь просит найти что-то в интернете — сразу используй web_search или fetch_url.\n";
$systemPrompt .= "5. СТРОЖАЙШЕЕ ПРАВИЛО ЧЕСТНОСТИ (ЗАПРЕТ НА ВЫДУМКИ И ГАЛЛЮЦИНАЦИИ): Если тебе не удалось найти или подтвердить какую-либо информацию через инструменты (web_search, fetch_url, sql_query, get_vladikish_knowledge) или в базе данных — ты КАТЕГОРИЧЕСКИ НЕ ИМЕЕШЬ ПРАВА выдумывать факты, сочинять несуществующие слова, данные или врать. Честно и прямо сообщи пользователю: «К сожалению, точной информации по этому запросу найти не удалось», объясни что именно было проверено и предложи реальные альтернативы.\n";
$systemPrompt .= "6. ПОЛНОТА И КАЧЕСТВО СОЗДАНИЯ УРОКОВ И УПРАЖНЕНИЙ (БЕЗ ПУСТЫХ ПОЛЕЙ): При вызове `create_lesson` или `create_story` ты ОБЯЗАН доделывать каждое упражнение ДО КОНЦА! КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО создавать упражнения без правильных ответов! Для `multiple_choice` ОБЯЗАТЕЛЬНО укажи массив 4 вариантов `options` и индекс правильного ответа `correct` (0, 1, 2 или 3). Для `word_bank` ОБЯЗАТЕЛЬНО укажи `correct_sequence` и `word_pool`. Для `match_pairs` укажи `pairs`. Все упражнения должны иметь вопрос, слово-подсказку, перевод и понятное объяснение `explanation`.\n";
$systemPrompt .= "7. ИНСПЕКЦИЯ ДАННЫХ ПЕРЕД РАБОТОЙ: Всегда используй инструменты списка (`list_skills`, `list_lessons`, `list_dictionary_words`, `list_grammar_rules`), чтобы сначала узнать существующие ID и структуру курсов перед созданием новых уроков.\n";
$systemPrompt .= "8. Всегда проверяй результаты выполнения функций и отвечай подробно на русском языке с красивым форматированием Markdown.\n";
$systemPrompt .= "9. Язык общения: Русский.\n";

$messages = [
    ['role' => 'system', 'content' => $systemPrompt]
];

// Append past conversation history if available
if (!empty($history) && is_array($history)) {
    foreach ($history as $h) {
        if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'])) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
    }
}

// Append current user task
$messages[] = ['role' => 'user', 'content' => $task];

sendSse([
    'type' => 'status',
    'status' => 'thinking',
    'message' => '🤖 Агент запустил анализ задачи и планирование действий...'
]);

$maxIterations = 10;
$currentIteration = 0;
$totalToolsExecuted = 0;
$useNativeTools = true;

$endpointUrl = rtrim($baseUrl, '/') . '/chat/completions';

while ($currentIteration < $maxIterations) {
    $currentIteration++;

    $maxOutputTokens = (int)getSetting('ai_max_tokens', 8192);

    // Prepare API Request Payload with maximum output tokens
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.2,
        'max_tokens' => $maxOutputTokens > 0 ? $maxOutputTokens : 8192
    ];

    if ($useNativeTools) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = 'auto';
    }

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
        sendSse(['type' => 'error', 'error' => 'Ошибка cURL: ' . $curlErr]);
        break;
    }

    $data = json_decode($response, true);

    // If HTTP error: check if model rejected native 'tools' parameter or timed out
    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? $data['message'] ?? "HTTP $httpCode: $response";
        
        // If error is 504 Gateway Timeout or tools unsupported, switch to compact ReAct mode and retry
        if ($useNativeTools && (stripos($errMsg, 'tool') !== false || stripos($errMsg, 'TIMEOUT') !== false || $httpCode == 504 || $httpCode == 502 || $httpCode == 400 || $httpCode == 422)) {
            $useNativeTools = false;
            sendSse([
                'type' => 'status',
                'status' => 'thinking',
                'message' => '⚡ Прокси ответил с задержкой (504). Переключаюсь в быстрый компактный режим ReAct...'
            ]);
            continue;
        }

        if ($httpCode == 504 || stripos($errMsg, 'TIMEOUT') !== false) {
            $errMsg = "Таймаут прокси (504 Gateway Timeout): Серверless-шлюз не успел получить ответ от модели за лимит времени. Попробуйте выбрать более быструю модель в Настройках ИИ (например, meta/llama-3.3-70b-instruct или другую легкую модель) или повторите запрос.";
        }

        sendSse(['type' => 'error', 'error' => "Ошибка API модели ($httpCode): $errMsg"]);
        break;
    }

    $choice = $data['choices'][0] ?? null;
    if (!$choice) {
        sendSse(['type' => 'error', 'error' => 'Пустой ответ от модели']);
        break;
    }

    $msg = $choice['message'];
    $rawContent = $msg['content'] ?? '';
    $reasoningContent = $msg['reasoning_content'] ?? '';
    $toolCalls = $msg['tool_calls'] ?? [];

    $reasoningText = '';
    $content = $rawContent;

    if (!empty($reasoningContent)) {
        $reasoningText = trim($reasoningContent);
    } elseif (preg_match('/<think>(.*?)<\/think>/is', $rawContent, $tm)) {
        $reasoningText = trim($tm[1]);
        $content = trim(str_replace($tm[0], '', $rawContent));
    }

    // Stream Reasoning Chunks if present
    if (!empty($reasoningText)) {
        $rWords = preg_split('/(\s+)/u', $reasoningText, -1, PREG_SPLIT_DELIM_CAPTURE);
        $rBuf = '';
        foreach ($rWords as $rw) {
            $rBuf .= $rw;
            if (mb_strlen($rBuf) > 25 || strpos($rw, "\n") !== false) {
                sendSse([
                    'type' => 'reasoning_chunk',
                    'delta' => $rBuf
                ]);
                $rBuf = '';
                usleep(15000);
            }
        }
        if (!empty($rBuf)) {
            sendSse([
                'type' => 'reasoning_chunk',
                'delta' => $rBuf
            ]);
        }
        sendSse(['type' => 'reasoning_done']);
    }

    // Check for JSON ReAct tool call if native tool_calls is empty
    if (empty($toolCalls) && !empty($content)) {
        if (preg_match('/```(?:json)?\s*(\{\s*"tool"\s*:\s*"[^"]+"\s*,\s*"args"\s*:\s*\{.*?\}\s*\})\s*```/is', $content, $m)) {
            $reactJson = json_decode($m[1], true);
            if ($reactJson && !empty($reactJson['tool'])) {
                $toolCalls[] = [
                    'id' => 'react_' . uniqid(),
                    'function' => [
                        'name' => $reactJson['tool'],
                        'arguments' => json_encode($reactJson['args'] ?? [], JSON_UNESCAPED_UNICODE)
                    ]
                ];
                // Clean the tool json call from assistant thought text
                $content = trim(str_replace($m[0], '', $content));
            }
        }
    }

    // Append model response to message trajectory
    $messages[] = [
        'role' => 'assistant',
        'content' => $content
    ];

    // Stream content chunk in realistic fluid chunks
    if (!empty($content)) {
        $cWords = preg_split('/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $cBuf = '';
        foreach ($cWords as $cw) {
            $cBuf .= $cw;
            if (mb_strlen($cBuf) > 25 || strpos($cw, "\n") !== false) {
                sendSse([
                    'type' => 'content_chunk',
                    'delta' => $cBuf
                ]);
                $cBuf = '';
                usleep(15000);
            }
        }
        if (!empty($cBuf)) {
            sendSse([
                'type' => 'content_chunk',
                'delta' => $cBuf
            ]);
        }
    }

    // If no tool calls -> Agent has completely finished the task!
    if (empty($toolCalls)) {
        break;
    }

    // Execute each tool call requested by the model
    foreach ($toolCalls as $tc) {
        $totalToolsExecuted++;
        $tcId = $tc['id'] ?? ('call_' . uniqid());
        $fnName = $tc['function']['name'];
        $fnArgsJson = $tc['function']['arguments'] ?? '{}';
        $fnArgs = is_array($fnArgsJson) ? $fnArgsJson : (json_decode($fnArgsJson, true) ?: []);

        // Notify client tool execution started
        sendSse([
            'type' => 'tool_start',
            'tool_index' => $totalToolsExecuted,
            'call_id' => $tcId,
            'name' => $fnName,
            'args' => $fnArgs
        ]);

        // Execute tool safely
        $toolResult = executeAgentTool($fnName, $fnArgs, $admin);

        // Notify client tool execution finished
        sendSse([
            'type' => 'tool_result',
            'tool_index' => $totalToolsExecuted,
            'call_id' => $tcId,
            'name' => $fnName,
            'result' => $toolResult
        ]);

        // Feed tool result back to model history
        if ($useNativeTools) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tcId,
                'name' => $fnName,
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE)
            ];
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => "[РЕЗУЛЬТАТ ВЫПОЛНЕНИЯ ИНСТРУМЕНТА {$fnName}]:\n" . json_encode($toolResult, JSON_UNESCAPED_UNICODE) . "\nПродолжи выполнение задачи пользователя."
            ];
        }
    }

    sendSse([
        'type' => 'status',
        'status' => 'executing',
        'message' => "⚡ Шаг {$currentIteration}/{$maxIterations} выполнен (инструментов: {$totalToolsExecuted})..."
    ]);
}

// Final completion event
sendSse([
    'type' => 'done',
    'iterations' => $currentIteration,
    'total_tools' => $totalToolsExecuted,
    'model' => $model
]);
