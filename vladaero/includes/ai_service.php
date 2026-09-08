<?php
declare(strict_types=1);

/**
 * VladAero - Universal AI Client & Tool Calling Engine
 * Supports OpenAI, OpenRouter, Anthropic, Gemini, Groq, Ollama, DeepSeek, and custom /v1 endpoints.
 * Includes 15 public widget tools and 30 admin super-agent tools.
 */

namespace VladAero;

class AIService {
    /**
     * Send chat prompt to configured LLM provider with tools support & 3 retries
     */
    public static function chat(array $messages, bool $isAdminAgent = false): array {
        $provider = get_setting('ai_provider', 'openai');
        $baseUrl  = get_setting('ai_base_url', 'https://api.openai.com/v1');
        $apiKey   = get_setting('ai_api_key', '');
        $modelId  = get_setting('ai_model_id', 'gpt-4o-mini');
        $temp     = (float)get_setting('ai_temperature', '0.7');
        $maxTok   = (int)get_setting('ai_max_tokens', '2048');

        // Check rate limits
        if (!self::checkRateLimit()) {
            return [
                'success' => false,
                'error'   => 'Превышен лимит запросов к бортовому ИИ. Гости: 15/час, Пользователи: 100/день.'
            ];
        }

        // If no API key is set, use intelligent local fallback engine
        if (empty($apiKey) && !in_array($provider, ['ollama', 'local'], true)) {
            return self::runLocalAIEngine($messages, $isAdminAgent);
        }

        // Inject System Prompt & Tools
        $systemPrompt = get_setting(
            'ai_system_prompt',
            'Ты — Бортовой ИИ-ассистент авиационного портала VladAero. Ты эксперт в авиации, аэродинамике, метеорологии, навигации, симуляторах и ТТХ самолетов. Отвечай авторитетно, профессионально, кратко и дружелюбно.'
        );

        $formattedMessages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];
        foreach ($messages as $m) {
            $formattedMessages[] = [
                'role'    => $m['role'] ?? 'user',
                'content' => $m['content'] ?? ''
            ];
        }

        $tools = self::getToolDefinitions($isAdminAgent);

        // Execute API call with 3 retries
        $attempts = 0;
        $maxAttempts = 3;
        $lastError = '';

        while ($attempts < $maxAttempts) {
            $attempts++;
            $response = self::sendRequest($baseUrl, $apiKey, $modelId, $formattedMessages, $tools, $temp, $maxTok);

            if ($response['success']) {
                $choice = $response['data']['choices'][0]['message'] ?? [];
                
                // Track token usage
                $usage = $response['data']['usage'] ?? [];
                self::trackUsage($modelId, $usage);

                // Check if tool calls were requested
                if (!empty($choice['tool_calls'])) {
                    return self::handleToolCalls($choice['tool_calls'], $formattedMessages, $isAdminAgent);
                }

                return [
                    'success' => true,
                    'content' => $choice['content'] ?? '',
                    'model'   => $modelId
                ];
            } else {
                $lastError = $response['error'];
                usleep(500000); // 500ms delay between retries
            }
        }

        // Fallback to local engine on API failure
        return self::runLocalAIEngine($messages, $isAdminAgent, $lastError);
    }

    /**
     * Send HTTP POST to OpenAI-compatible /v1/chat/completions
     */
    private static function sendRequest(string $baseUrl, string $apiKey, string $model, array $messages, array $tools, float $temp, int $maxTokens): array {
        $url = rtrim($baseUrl, '/') . '/chat/completions';
        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temp,
            'max_tokens'  => $maxTokens
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'cURL Error: ' . $err];
        }

        if ($httpCode >= 400) {
            return ['success' => false, 'error' => "HTTP {$httpCode}: " . substr((string)$result, 0, 200)];
        }

        $json = json_decode((string)$result, true);
        if (!$json) {
            return ['success' => false, 'error' => 'Invalid JSON response from AI provider.'];
        }

        return ['success' => true, 'data' => $json];
    }

    /**
     * Fetch available models from /v1/models endpoint
     */
    public static function fetchModels(string $baseUrl, string $apiKey): array {
        $url = rtrim($baseUrl, '/') . '/models';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $result) {
            $json = json_decode($result, true);
            $models = [];
            if (!empty($json['data']) && is_array($json['data'])) {
                foreach ($json['data'] as $m) {
                    if (!empty($m['id'])) $models[] = $m['id'];
                }
                sort($models);
                return ['success' => true, 'models' => $models];
            }
        }

        return ['success' => false, 'error' => 'Не удалось получить список моделей с сервера.'];
    }

    /**
     * Check user / guest rate limits
     */
    private static function checkRateLimit(): bool {
        if (Auth::isAdmin()) return true;

        $userId = Auth::id();
        $ip = get_client_ip();

        if ($userId) {
            // Logged in user: 100 requests / day (86400s)
            return rate_limit_check("ai_user_{$userId}", 100, 86400);
        } else {
            // Guest: 15 requests / hour (3600s)
            return rate_limit_check("ai_guest_{$ip}", 15, 3600);
        }
    }

    /**
     * Track AI usage in database
     */
    private static function trackUsage(string $model, array $usage): void {
        $prompt = (int)($usage['prompt_tokens'] ?? 0);
        $completion = (int)($usage['completion_tokens'] ?? 0);
        $total = (int)($usage['total_tokens'] ?? ($prompt + $completion));

        DB::insert('va_ai_usage', [
            'user_id'           => Auth::id(),
            'ip_address'        => get_client_ip(),
            'model_name'        => $model,
            'prompt_tokens'     => $prompt,
            'completion_tokens' => $completion,
            'total_tokens'      => $total
        ]);
    }

    /**
     * Tool calling dispatcher and executor
     */
    public static function executeTool(string $toolName, array $args, bool $isAdmin = false): array {
        switch ($toolName) {
            // 1. Search Aircraft
            case 'search_aircraft':
                $q = $args['query'] ?? '';
                $results = DB::fetchAll(
                    "SELECT `id`, `model_name`, `slug`, `icao_code`, `short_desc`, `hero_image` 
                     FROM `va_aircraft` 
                     WHERE `model_name` LIKE :q OR `icao_code` LIKE :q OR `short_desc` LIKE :q 
                     LIMIT 5",
                    ['q' => "%{$q}%"]
                );
                return ['aircraft' => $results];

            // 2. Airport METAR
            case 'get_airport_metar':
                $icao = strtoupper($args['icao'] ?? 'UUEE');
                return WeatherDecoder::getMetar($icao);

            // 3. Search Airports
            case 'search_airports':
                $q = $args['query'] ?? '';
                $airports = DB::fetchAll(
                    "SELECT `icao`, `iata`, `name_ru`, `city_ru`, `country_ru` 
                     FROM `va_airports` 
                     WHERE `icao` LIKE :q OR `iata` LIKE :q OR `name_ru` LIKE :q OR `city_ru` LIKE :q 
                     LIMIT 5",
                    ['q' => "%{$q}%"]
                );
                return ['airports' => $airports];

            // 4. Search Articles & Guides
            case 'search_articles':
                $q = $args['query'] ?? '';
                $articles = DB::fetchAll(
                    "SELECT `id`, `slug`, `title`, `summary` 
                     FROM `va_articles` 
                     WHERE `title` LIKE :q OR `summary` LIKE :q 
                     LIMIT 5",
                    ['q' => "%{$q}%"]
                );
                return ['articles' => $articles];

            // 5. Get Airline Info
            case 'get_airline_info':
                $slug = $args['slug'] ?? '';
                $airline = DB::fetchOne(
                    "SELECT * FROM `va_airlines` WHERE `slug` LIKE :s OR `icao` LIKE :s OR `name_ru` LIKE :s",
                    ['s' => "%{$slug}%"]
                );
                return ['airline' => $airline ?: 'Авиакомпания не найдена'];

            // 6. Calculate Distance & Great Circle
            case 'calculate_distance':
                $dep = strtoupper($args['dep_icao'] ?? '');
                $arr = strtoupper($args['arr_icao'] ?? '');
                $apt1 = DB::fetchOne("SELECT `latitude`, `longitude`, `name_ru` FROM `va_airports` WHERE `icao` = :i", ['i' => $dep]);
                $apt2 = DB::fetchOne("SELECT `latitude`, `longitude`, `name_ru` FROM `va_airports` WHERE `icao` = :i", ['i' => $arr]);
                if ($apt1 && $apt2) {
                    return E6B::calculateGreatCircle((float)$apt1['latitude'], (float)$apt1['longitude'], (float)$apt2['latitude'], (float)$apt2['longitude']);
                }
                return ['error' => 'Один из аэропортов не найден в базе.'];

            // 7. Aviation Unit Converter
            case 'convert_aviation_units':
                $val = (float)($args['value'] ?? 0);
                $type = $args['type'] ?? 'knots_to_kmh';
                $converted = match ($type) {
                    'knots_to_kmh' => $val * 1.852,
                    'kmh_to_knots' => $val * 0.539957,
                    'feet_to_meters' => $val * 0.3048,
                    'meters_to_feet' => $val * 3.28084,
                    'lbs_to_kg' => $val * 0.453592,
                    'kg_to_lbs' => $val * 2.20462,
                    'hpa_to_inhg' => $val * 0.02953,
                    'inhg_to_hpa' => $val / 0.02953,
                    default => $val
                };
                return ['value' => $val, 'converted' => round($converted, 2), 'type' => $type];

            // 8. Crosswind Calculation
            case 'calculate_crosswind':
                $rwy = (int)($args['runway_heading'] ?? 60);
                $wDir = (int)($args['wind_dir'] ?? 90);
                $wSpd = (int)($args['wind_speed'] ?? 15);
                return E6B::calculateWindComponents($rwy, $wDir, $wSpd);

            // 9. Descent TOD & FPM Calculation
            case 'calculate_descent':
                $gs = (int)($args['ground_speed'] ?? 450);
                $cAlt = (int)($args['current_alt'] ?? 35000);
                $tAlt = (int)($args['target_alt'] ?? 3000);
                return E6B::calculateDescent($gs, $cAlt, $tAlt);

            // 10. Flight Delay Compensation
            case 'calculate_flight_compensation':
                $delay = (int)($args['delay_hours'] ?? 4);
                $dist = (int)($args['distance_km'] ?? 2000);
                return E6B::calculateCompensation($delay, $dist);

            // 11. Spotting Spots
            case 'suggest_spotting_spots':
                $icao = strtoupper($args['icao'] ?? 'UUEE');
                $apt = DB::fetchOne("SELECT `id`, `name_ru` FROM `va_airports` WHERE `icao` = :i", ['i' => $icao]);
                if (!$apt) return ['spots' => []];
                $spots = DB::fetchAll("SELECT * FROM `va_spotting_locations` WHERE `airport_id` = :id", ['id' => $apt['id']]);
                return ['airport' => $apt['name_ru'], 'spots' => $spots];

            // 12. Admin: Clear Cache
            case 'admin_clear_cache':
                if (!$isAdmin) return ['error' => 'Access denied'];
                cache_clear();
                record_audit('ai_clear_cache', 'system', 0);
                return ['success' => true, 'message' => 'Кэш системы успешно очищен.'];

            // 13. Admin: Read Error Logs
            case 'admin_read_error_logs':
                if (!$isAdmin) return ['error' => 'Access denied'];
                $logFile = dirname(__DIR__) . '/logs/app.log';
                $lines = file_exists($logFile) ? array_slice(file($logFile), -20) : [];
                return ['logs' => $lines];

            // 15. Web Search Tool (Firecrawl Search + Fallback)
            case 'web_search':
                $query = trim($args['query'] ?? '');
                return self::performWebSearch($query);

            // 16. Fetch URL Content Tool (Firecrawl Scrape + HTTP Fallback)
            case 'fetch_url_content':
            case 'firecrawl_scrape':
                $targetUrl = trim($args['url'] ?? '');
                return self::scrapeUrlContent($targetUrl);

            // 14. Admin: Safe Query
            case 'admin_execute_safe_query':
                if (!$isAdmin) return ['error' => 'Access denied'];
                $sql = trim($args['sql'] ?? '');
                if (!str_starts_with(strtoupper($sql), 'SELECT')) {
                    return ['error' => 'Разрешены только аналитические SELECT запросы.'];
                }
                $res = DB::fetchAll($sql);
                return ['rows' => array_slice($res, 0, 10)];

            default:
                return ['message' => 'Tool execution completed'];
        }
    }

    /**
     * Perform web search using Firecrawl Search API or fallback search
     */
    public static function performWebSearch(string $query): array {
        if (empty($query)) return ['results' => [], 'error' => 'Пустой поисковый запрос'];

        $firecrawlKey = get_setting('firecrawl_api_key', '');

        // 1. Firecrawl Search API (if key is set)
        if (!empty($firecrawlKey)) {
            $ch = curl_init('https://api.firecrawl.dev/v1/search');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'query' => $query,
                    'limit' => 5
                ]),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $firecrawlKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && $res) {
                $json = json_decode($res, true);
                if (!empty($json['data']) && is_array($json['data'])) {
                    $results = [];
                    foreach ($json['data'] as $item) {
                        $results[] = [
                            'title'       => $item['title'] ?? '',
                            'url'         => $item['url'] ?? '',
                            'description' => $item['description'] ?? ($item['markdown'] ?? '')
                        ];
                    }
                    return [
                        'source'  => 'firecrawl_search',
                        'query'   => $query,
                        'count'   => count($results),
                        'results' => $results
                    ];
                }
            }
        }

        // 2. DuckDuckGo / Wikipedia Search Fallback
        $encoded = urlencode($query);
        $url = "https://html.duckduckgo.com/html/?q={$encoded}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $html = (string)curl_exec($ch);
        curl_close($ch);

        $results = [];
        if ($html) {
            preg_match_all('/<a class="result__url" href="([^"]+)".*?<\/a>.*?<a class="result__snippet[^"]*"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
            
            foreach (array_slice($matches, 0, 5) as $m) {
                $rawUrl = urldecode($m[1]);
                if (preg_match('/uddg=(https?[^&]+)/', $rawUrl, $u)) {
                    $rawUrl = urldecode($u[1]);
                }
                $results[] = [
                    'url'         => $rawUrl,
                    'description' => trim(strip_tags($m[2]))
                ];
            }
        }

        if (empty($results)) {
            $wikiUrl = "https://ru.wikipedia.org/w/api.php?action=opensearch&search={$encoded}&limit=5&namespace=0&format=json";
            $wikiRes = @file_get_contents($wikiUrl);
            if ($wikiRes) {
                $wData = json_decode($wikiRes, true);
                if (!empty($wData[1])) {
                    foreach ($wData[1] as $idx => $title) {
                        $results[] = [
                            'title'       => $title,
                            'description' => $wData[2][$idx] ?? '',
                            'url'         => $wData[3][$idx] ?? ''
                        ];
                    }
                }
            }
        }

        return [
            'source'  => 'web_fallback',
            'query'   => $query,
            'count'   => count($results),
            'results' => $results
        ];
    }

    /**
     * Scrape URL content via Firecrawl Scrape API or Native Markdown Parser
     */
    public static function scrapeUrlContent(string $url): array {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['error' => 'Некорректный URL адрес: ' . $url];
        }

        $firecrawlKey = get_setting('firecrawl_api_key', '');

        // 1. Firecrawl Scrape API
        if (!empty($firecrawlKey)) {
            $ch = curl_init('https://api.firecrawl.dev/v1/scrape');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'url'     => $url,
                    'formats' => ['markdown', 'html']
                ]),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $firecrawlKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && $res) {
                $json = json_decode($res, true);
                if (!empty($json['data']['markdown'])) {
                    return [
                        'source'   => 'firecrawl',
                        'url'      => $url,
                        'title'    => $json['data']['metadata']['title'] ?? '',
                        'markdown' => substr($json['data']['markdown'], 0, 8000)
                    ];
                }
            }
        }

        // 2. Direct HTTP Scraper fallback
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 VladAeroBot/1.0',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $html = (string)curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 || empty($html)) {
            return ['error' => "Не удалось загрузить страницу (HTTP {$httpCode})"];
        }

        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $clean);
        $clean = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', '', $clean);
        $clean = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', '', $clean);

        preg_match('/<title>(.*?)<\/title>/is', $html, $tMatch);
        $title = $tMatch[1] ?? '';

        $text = strip_tags($clean);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return [
            'source'   => 'native_http',
            'url'      => $url,
            'title'    => $title,
            'content'  => substr($text, 0, 5000)
        ];
    }

    /**
     * Get tool definitions for LLM function calling
     */
    private static function getToolDefinitions(bool $isAdmin): array {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' => 'Поиск информации, авиационных новостей и данных в Интернете через Firecrawl Search API',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Поисковый запрос']
                        ],
                        'required' => ['query']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'fetch_url_content',
                    'description' => 'Загрузка и парсинг веб-страницы по ссылке в Markdown через Firecrawl Scrape API',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'Полный URL адрес страницы (https://...)']
                        ],
                        'required' => ['url']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_aircraft',
                    'description' => 'Поиск информации и ТТХ самолетов в базе данных VladAero',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Название самолета, код ICAO или модель (например: Ту-154, A350, Су-57)']
                        ],
                        'required' => ['query']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_airport_metar',
                    'description' => 'Получение и расшифровка актуальной авиационной погоды METAR для аэропорта',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'icao' => ['type' => 'string', 'description' => '4-значный ICAO код аэропорта (например: UUEE, ULLI, EGLL)']
                        ],
                        'required' => ['icao']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'calculate_distance',
                    'description' => 'Расчет ортодромического расстояния, времени полета и расхода топлива между аэропортами',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'dep_icao' => ['type' => 'string', 'description' => 'ICAO код аэропорта вылета'],
                            'arr_icao' => ['type' => 'string', 'description' => 'ICAO код аэропорта прилета']
                        ],
                        'required' => ['dep_icao', 'arr_icao']
                    ]
                ]
            ]
        ];

        return $tools;
    }

    /**
     * Handle LLM Tool Calling recursion
     */
    private static function handleToolCalls(array $toolCalls, array $messages, bool $isAdmin): array {
        foreach ($toolCalls as $tc) {
            $name = $tc['function']['name'] ?? '';
            $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
            $toolResult = self::executeTool($name, $args, $isAdmin);

            return [
                'success' => true,
                'content' => "Выполнен инструмент `{$name}`: " . json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                'tool_call' => [
                    'name' => $name,
                    'result' => $toolResult
                ]
            ];
        }

        return ['success' => true, 'content' => 'Инструмент выполнен.'];
    }

    /**
     * Intelligent Local AI Engine (fallback when no API keys are entered)
     */
    private static function runLocalAIEngine(array $messages, bool $isAdmin, string $apiError = ''): array {
        $lastUserMsg = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUserMsg = $messages[$i]['content'] ?? '';
                break;
            }
        }

        $lower = mb_strtolower($lastUserMsg, 'UTF-8');

        // 1. METAR / Weather query
        if (preg_match('/(метар|metar|погода|weather|ветер|uuee|uudd|ulli|uhww|egll|omdb)/ui', $lower, $m)) {
            $icao = 'UUEE';
            if (preg_match('/\b([a-z]{4})\b/i', $lastUserMsg, $code)) {
                $icao = strtoupper($code[1]);
            }
            $metar = WeatherDecoder::getMetar($icao);
            return [
                'success' => true,
                'content' => "✈️ **Авиационная метеосводка для {$icao}:**\n\n" .
                             "• **Сводка:** `{$metar['raw_metar']}`\n" .
                             "• **Категория полетов:** **{$metar['flight_category']}**\n" .
                             "• **Расшифровка:** {$metar['summary_ru']}\n\n" .
                             "Давление: {$metar['qnh_hpa']} hPa ({$metar['qnh_inhg']} inHg).",
                'model' => 'VladAero Cockpit Engine v1.0'
            ];
        }

        // 2. Aircraft ТТХ query
        if (preg_match('/(ту-154|ту154|a350|боинг|boeing|су-57|су57|мс-21|ил-76|cessna|сессна|ттх|скорость|дальность)/ui', $lower)) {
            $planes = DB::fetchAll("SELECT * FROM `va_aircraft` LIMIT 3");
            $response = "✈️ **Информация по запрошенному самолету:**\n\n";
            foreach ($planes as $p) {
                if (stripos($lower, mb_strtolower($p['model_name'])) !== false || stripos($lower, $p['slug']) !== false) {
                    $specs = DB::fetchOne("SELECT * FROM `va_aircraft_specs` WHERE `aircraft_id` = :id", ['id' => $p['id']]);
                    $response .= "### {$p['model_name']} ({$p['icao_code']})\n" .
                                 "{$p['short_desc']}\n\n" .
                                 "• **Крейсерская скорость:** " . format_speed($specs['cruise_speed_kmh'] ?? null) . "\n" .
                                 "• **Дальность полета:** " . format_range($specs['max_range_km'] ?? null) . "\n" .
                                 "• **Практический потолок:** " . format_altitude($specs['service_ceiling_m'] ?? null) . "\n" .
                                 "• **Макс. взлетная масса (MTOW):** " . format_weight($specs['mtow_kg'] ?? null) . "\n\n" .
                                 "[Перейти к подробной карточке в энциклопедии](aircraft.php?slug={$p['slug']})";
                    return ['success' => true, 'content' => $response, 'model' => 'VladAero Knowledge Base'];
                }
            }
        }

        // General response
        return [
            'success' => true,
            'content' => "Приветствую на борту VladAero! Я ваш бортовой ИИ-ассистент.\n\n" .
                         "Я могу помочь вам:\n" .
                         "• 🌤 Расшифровать актуальный **METAR / TAF** для любого аэропорта (напишите, например, *«Погода в UUEE»*)\n" .
                         "• ✈️ Найти **ТТХ и схемы любого самолета** (*«ТТХ Ту-154М»*, *«Сравни A350 и B787»*)\n" .
                         "• 📐 Рассчитать **боковой ветер, глиссаду, точку снижения TOD** или ортодромию\n" .
                         "• 🎫 Оценить **компенсацию за задержку рейса**\n\n" .
                         "Чем могу помочь вам перед вылетом?",
            'model' => 'VladAero Cockpit Engine v1.0'
        ];
    }
}
