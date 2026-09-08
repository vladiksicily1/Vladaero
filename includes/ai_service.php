<?php
/**
 * VladAero AI Copilot & Admin Agent Service
 * Integrates with any OpenAI-compatible LLM provider with dynamic /v1/models fetch,
 * Native Tool Calling, and Real-Time SSE Token & Reasoning Streaming (Thinking Process).
 */

if (!defined('VLADAERO_ROOT')) {
    define('VLADAERO_ROOT', dirname(__DIR__));
}
require_once VLADAERO_ROOT . '/includes/db.php';
require_once VLADAERO_ROOT . '/includes/functions.php';
require_once VLADAERO_ROOT . '/includes/weather_decoder.php';
require_once VLADAERO_ROOT . '/includes/e6b.php';

class AIService {
    public static function getBaseUrl(): string {
        $url = getSetting('ai_base_url', 'https://api.openai.com/v1');
        return rtrim($url, '/');
    }

    public static function getApiKey(): string {
        return getSetting('ai_api_key', '');
    }

    public static function getModelId(): string {
        return getSetting('ai_model_id', 'gpt-4o-mini');
    }

    public static function fetchModels(): array {
        $baseUrl = self::getBaseUrl();
        $apiKey = self::getApiKey();

        $ch = curl_init("{$baseUrl}/models");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $res) {
            $data = json_decode($res, true);
            if (isset($data['data']) && is_array($data['data'])) {
                $models = [];
                foreach ($data['data'] as $m) {
                    if (!empty($m['id'])) {
                        $models[] = $m['id'];
                    }
                }
                sort($models);
                return ['success' => true, 'models' => $models];
            }
        }
        return ['success' => false, 'error' => "Не удалось получить список моделей (HTTP {$code})"];
    }

    /**
     * Real-Time Streaming (SSE) with Real-Time Reasoning (Thinking Process)
     */
    public static function streamChat(array $messages, bool $isAdmin = false, callable $sseEmitter = null): void {
        // Disable output buffering for real-time flushing
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        $baseUrl = self::getBaseUrl();
        $apiKey = self::getApiKey();
        $model = self::getModelId();

        if (empty($apiKey) && strpos($baseUrl, 'localhost') === false && strpos($baseUrl, '127.0.0.1') === false) {
            self::emitSse('error', ['error' => 'API-ключ ИИ не настроен в настройках VladAero.'], $sseEmitter);
            return;
        }

        $systemPrompt = $isAdmin
            ? "Вы — Суперадминистративный ИИ-Агент портала VladAero. Вы обладаете глубокими знаниями об авиации, авионике и имеете доступ к управлению контентом и БД. Отвечайте авторитетно, профессионально, на чистом русском языке, с красивым форматированием Markdown."
            : "Вы — Бортовой ИИ-Ассистент авиационного портала VladAero (vladinc.ru/aviation). Вы помогаете пилотам, курсантам, споттерам и пассажирам: рассказываете о самолетах, расшифровываете погоду METAR/TAF, объясняете авиационные правила и физику полета. Отвечайте вежливо, увлекательно, точно и на чистом русском языке в Markdown.";

        $formattedMessages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        foreach ($messages as $msg) {
            $formattedMessages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? ''
            ];
        }

        // Tools Definition
        $tools = self::getToolDefinitions($isAdmin);

        $payload = [
            'model' => $model,
            'messages' => $formattedMessages,
            'temperature' => 0.5,
            'stream' => true
        ];

        // Check if model might need tool execution or direct streaming
        // First check for function calling intent
        $toolCallsDetected = [];
        $accumulatedContent = '';
        $accumulatedReasoning = '';
        $buffer = '';

        $ch = curl_init("{$baseUrl}/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$accumulatedContent, &$accumulatedReasoning, $sseEmitter) {
                $buffer .= $data;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines); // Keep incomplete line in buffer

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, ':') === false) continue;

                    if (strpos($line, 'data:') === 0) {
                        $jsonStr = trim(substr($line, 5));
                        if ($jsonStr === '[DONE]') {
                            continue;
                        }

                        $parsed = json_decode($jsonStr, true);
                        if (!$parsed) continue;

                        $delta = $parsed['choices'][0]['delta'] ?? null;
                        if (!$delta) continue;

                        // 1. Real-time Reasoning / Thinking stream (DeepSeek-R1, OpenAI o1/o3, Gemini Flash Thinking, Qwen-Thinking)
                        $reasoningChunk = $delta['reasoning_content'] ?? $delta['reasoning'] ?? $delta['thought'] ?? $delta['thinking'] ?? null;
                        if ($reasoningChunk !== null && $reasoningChunk !== '') {
                            $accumulatedReasoning .= $reasoningChunk;
                            self::emitSse('reasoning', ['chunk' => $reasoningChunk], $sseEmitter);
                        }

                        // 2. Real-time Standard Content stream
                        $contentChunk = $delta['content'] ?? null;
                        if ($contentChunk !== null && $contentChunk !== '') {
                            $accumulatedContent .= $contentChunk;
                            self::emitSse('content', ['chunk' => $contentChunk], $sseEmitter);
                        }
                    }
                }
                return strlen($data);
            }
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            self::emitSse('error', ['error' => "Ошибка сервера ИИ (HTTP {$httpCode})"], $sseEmitter);
            return;
        }

        self::emitSse('done', [
            'full_content' => $accumulatedContent,
            'full_reasoning' => $accumulatedReasoning
        ], $sseEmitter);
    }

    private static function emitSse(string $event, array $data, ?callable $customEmitter): void {
        if ($customEmitter) {
            $customEmitter($event, $data);
            return;
        }
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (function_exists('flush')) {
            flush();
        }
    }

    public static function chat(array $messages, bool $isAdmin = false): array {
        $baseUrl = self::getBaseUrl();
        $apiKey = self::getApiKey();
        $model = self::getModelId();

        if (empty($apiKey) && strpos($baseUrl, 'localhost') === false && strpos($baseUrl, '127.0.0.1') === false) {
            return [
                'success' => false,
                'error' => 'API-ключ ИИ не настроен в панели управления VladAero.'
            ];
        }

        $systemPrompt = $isAdmin
            ? "Вы — Суперадминистративный ИИ-Агент портала VladAero. Вы обладаете глубокими знаниями об авиации и имеете доступ к администрированию контента, анализу изображений и поиску в сети. Отвечайте авторитетно, профессионально, на чистом русском языке, форматируйте текст в Markdown."
            : "Вы — Бортовой ИИ-Ассистент авиационного портала VladAero (vladinc.ru/aviation). Вы помогаете пилотам, курсантам, споттерам и пассажирам: рассказываете о самолетах, расшифровываете погоду METAR/TAF, объясняете авиационные правила и физику полета. Отвечайте вежливо, увлекательно, точно и на чистом русском языке.";

        $formattedMessages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        foreach ($messages as $msg) {
            $formattedMessages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? ''
            ];
        }

        $tools = self::getToolDefinitions($isAdmin);

        $payload = [
            'model' => $model,
            'messages' => $formattedMessages,
            'temperature' => 0.4
        ];
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $ch = curl_init("{$baseUrl}/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => false, 'error' => "Ошибка ответа LLM сервера (HTTP {$httpCode})"];
        }

        $result = json_decode($response, true);
        $choice = $result['choices'][0]['message'] ?? null;
        if (!$choice) {
            return ['success' => false, 'error' => 'Пустой ответ от нейросети'];
        }

        $reasoning = $choice['reasoning_content'] ?? $choice['reasoning'] ?? $choice['thought'] ?? '';

        if (!empty($choice['tool_calls'])) {
            $toolCalls = $choice['tool_calls'];
            $formattedMessages[] = $choice;

            foreach ($toolCalls as $toolCall) {
                $funcName = $toolCall['function']['name'] ?? '';
                $args = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                $toolResult = self::executeTool($funcName, $args, $isAdmin);

                $formattedMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE)
                ];
            }

            $secondPayload = [
                'model' => $model,
                'messages' => $formattedMessages,
                'temperature' => 0.4
            ];
            $ch = curl_init("{$baseUrl}/chat/completions");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 40,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($secondPayload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $secondResponse = curl_exec($ch);
            curl_close($ch);

            $secondResult = json_decode($secondResponse, true);
            $finalContent = $secondResult['choices'][0]['message']['content'] ?? 'Запрос обработан.';
            $secondReasoning = $secondResult['choices'][0]['message']['reasoning_content'] ?? '';
            return ['success' => true, 'reply' => $finalContent, 'reasoning' => $secondReasoning ?: $reasoning];
        }

        return ['success' => true, 'reply' => $choice['content'] ?? '', 'reasoning' => $reasoning];
    }

    private static function getToolDefinitions(bool $isAdmin): array {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_aircraft',
                    'description' => 'Поиск характеристик и информации о самолете в базе VladAero (Airbus, Boeing, МС-21, Ту, SSJ, Cessna и др.)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Название самолета, код ICAO (например: A20N, B77W) или модель']
                        ],
                        'required' => ['query']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_airport_weather',
                    'description' => 'Получение актуальной метеосводки METAR/TAF и русской расшифровки для аэропорта по 4-буквенному коду ICAO (например: UUEE, UUDD, ULLI, EGLL, KJFK)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'icao' => ['type' => 'string', 'description' => '4-буквенный ICAO код аэропорта']
                        ],
                        'required' => ['icao']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'calculate_crosswind',
                    'description' => 'Расчет бокового и встречного ветра для посадки на полосу',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'runway_heading' => ['type' => 'number', 'description' => 'Магнитный курс ВПП (0-360)'],
                            'wind_dir' => ['type' => 'number', 'description' => 'Направление ветра (0-360)'],
                            'wind_speed_kt' => ['type' => 'number', 'description' => 'Скорость ветра в узлах']
                        ],
                        'required' => ['runway_heading', 'wind_dir', 'wind_speed_kt']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_glossary',
                    'description' => 'Поиск значения авиационного термина, акронима (V1, QNH, TCAS, ETOPS, ILS) или сленга',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'term' => ['type' => 'string', 'description' => 'Авиационный термин или сокращение']
                        ],
                        'required' => ['term']
                    ]
                ]
            ]
        ];
    }

    private static function executeTool(string $name, array $args, bool $isAdmin): array {
        switch ($name) {
            case 'search_aircraft':
                $q = trim($args['query'] ?? '');
                $table = Database::tableName('aircraft');
                $specsTable = Database::tableName('aircraft_specs');
                $sql = "SELECT a.model_name, a.icao_code, a.short_desc, s.* FROM `{$table}` a LEFT JOIN `{$specsTable}` s ON a.id = s.aircraft_id WHERE a.model_name LIKE :q OR a.icao_code LIKE :q LIMIT 3";
                $res = Database::fetchAll($sql, ['q' => "%{$q}%"]);
                return $res ?: ['status' => 'Самолет не найден в локальной базе'];

            case 'get_airport_weather':
                $icao = strtoupper(trim($args['icao'] ?? ''));
                $weather = WeatherDecoder::getMetar($icao);
                return $weather['success'] ? $weather['data'] : ['error' => 'Сводка недоступна'];

            case 'calculate_crosswind':
                return E6B::calculateWindComponents(
                    (float)($args['runway_heading'] ?? 0),
                    (float)($args['wind_dir'] ?? 0),
                    (float)($args['wind_speed_kt'] ?? 0)
                );

            case 'search_glossary':
                $term = trim($args['term'] ?? '');
                $table = Database::tableName('glossary');
                $res = Database::fetchAll("SELECT term, abbreviation, short_def, full_explanation, practical_example FROM `{$table}` WHERE term LIKE :t OR abbreviation LIKE :t LIMIT 3", ['t' => "%{$term}%"]);
                return $res ?: ['status' => 'Термин не найден в словаре'];

            default:
                return ['error' => 'Неизвестная функция'];
        }
    }
}
