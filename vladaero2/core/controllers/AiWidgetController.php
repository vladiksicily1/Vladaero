<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};
use VladAero\Helpers\FirecrawlService;

/**
 * AiWidgetController — ИИ-виджет с 20+ tools
 *
 * Aviation DB Tools (1-8):
 *  1. search_aircraft       — поиск самолётов
 *  2. search_airports       — поиск аэропортов
 *  3. get_airport_metar     — METAR/TAF
 *  4. search_articles       — поиск статей/новостей
 *  5. get_airline_info      — инфо об авиакомпании
 *  6. get_aircraft_details  — детали самолёта (ТТХ, история)
 *  7. get_glossary_term     — термин из глоссария
 *  8. get_event_info        — инфо о событии
 *
 * Calculation Tools (9-13):
 *  9. calculate_distance    — ортодромия
 * 10. convert_units         — конвертер единиц
 * 11. calculate_crosswind   — боковой ветер
 * 12. calculate_descent     — TOD расчёт
 * 13. calculate_compensation — компенсация EC261
 *
 * Firecrawl Tools (14-17):
 * 14. web_search_firecrawl  — поиск в интернете
 * 15. fetch_url_firecrawl   — извлечение контента со страницы
 * 16. map_site_firecrawl    — карта ссылок сайта
 * 17. extract_content       — извлечение и обрезка контента
 *
 * UI Tools (18-20):
 * 18. show_flight_on_radar  — ссылка на радар
 * 19. start_quiz            — мини-викторина
 * 20. suggest_spotting_spots — споттинг-точки аэропорта
 */
class AiWidgetController extends Controller
{
    private ?FirecrawlService $firecrawl = null;

    public function chat()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return;
        }

        $message = trim($_POST['message'] ?? '');
        if (mb_strlen($message) < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Пустое сообщение']);
            return;
        }

        $prefix = $this->db->prefix();
        $userId = Session::getAuth()['id'] ?? null;

        $this->db->query(
            "INSERT INTO {$prefix}ai_chats (user_id, session_id, message, role) VALUES (:uid, :sid, :msg, 'user')",
            ['uid' => $userId, 'sid' => session_id(), 'msg' => $message]
        );

        $systemPrompt = $this->buildSystemPrompt();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $apiKey = $this->config['ai']['api_key'] ?? '';
        $apiUrl = ($this->config['ai']['base_url'] ?? 'https://api.openai.com/v1') . '/chat/completions';
        $model = $this->config['ai']['model_id'] ?? 'gpt-4o-mini';

        if ($apiKey) {
            $this->streamFromAPI($apiUrl, $apiKey, $model, $systemPrompt, $message, $userId);
        } else {
            $this->streamLocal($message, $userId);
        }
    }

    public function apiChat()
    {
        header('Content-Type: application/json');
        $message = trim($this->query('message') ?? $_POST['message'] ?? '');
        if (mb_strlen($message) < 1) {
            echo json_encode(['error' => 'Empty message']);
            return;
        }
        echo json_encode(['reply' => 'Используйте SSE эндпоинт /api/ai/chat', 'status' => 'ok']);
    }

    private function getFirecrawl(): FirecrawlService
    {
        if ($this->firecrawl === null) {
            $this->firecrawl = new FirecrawlService();
        }
        return $this->firecrawl;
    }

    private function buildSystemPrompt(): string
    {
        $prefix = $this->db->prefix();
        $aircraftCount = $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}aircraft");
        $airportCount = $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}airports");
        $airlineCount = $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}airlines");

        $fc = $this->getFirecrawl();
        $firecrawlNote = $fc->isConfigured()
            ? "✅ Веб-поиск Firecrawl доступен"
            : "❌ Веб-поиск Firecrawl не настроен (нужен ключ в админке)";

        return "Ты — AI-ассистент авиационного портала VladAero.
Отвечай на русском языке. Будь точным, дружелюбным и информативным.

БАЗА ДАННЫХ САЙТА:
- Самолётов: {$aircraftCount}
- Аэропортов: {$airportCount}
- Авиакомпаний: {$airlineCount}

ВЕБ-ДОСТУП: {$firecrawlNote}

У тебя есть инструменты (tools). Вызывай их когда нужна информация.

ДОСТУПНЫЕ ИНСТРУМЕНТЫ:

📦 БАЗА ДАННЫХ САЙТА:
1. [TOOL:search_aircraft(query)] — поиск самолётов по названию/коду
2. [TOOL:search_airports(query)] — поиск аэропортов по ICAO/IATA/городу
3. [TOOL:get_airport_metar(icao)] — METAR/TAF аэропорта
4. [TOOL:search_articles(query)] — поиск статей и новостей
5. [TOOL:get_airline_info(query)] — данные об авиакомпании
6. [TOOL:get_aircraft_details(query)] — полные ТТХ самолёта
7. [TOOL:get_glossary_term(query)] — термин из глоссария
8. [TOOL:get_event_info(query)] — информация о событии

🧮 РАСЧЁТЫ:
9. [TOOL:calculate_distance(from,to)] — расстояние между аэропортами
10. [TOOL:convert_units(value,from,to)] — конвертер единиц
11. [TOOL:calculate_crosswind(rwy,dir,speed)] — боковой ветер
12. [TOOL:calculate_descent(alt,target,speed)] — TOD
13. [TOOL:calculate_compensation(km,min)] — компенсация EC261

🌐 ВЕБ (Firecrawl):
14. [TOOL:web_search_firecrawl(query)] — поиск в интернете
15. [TOOL:fetch_url_firecrawl(url)] — извлечение контента страницы
16. [TOOL:map_site_firecrawl(url)] — карта ссылок сайта
17. [TOOL:extract_content(url)] — извлечение и обрезка контента

🎯 UI:
18. [TOOL:show_flight_on_radar(query)] — показать на радаре
19. [TOOL:start_quiz()] — запустить викторину
20. [TOOL:suggest_spotting_spots(icao)] — споттинг-точки

Формат: оберни вызов в [TOOL:name(params)] в тексте ответа.
Если инструмент не нужен — просто ответь текстом.
Отвечай кратко и по существу.";
    }

    private function streamFromAPI(string $url, string $apiKey, string $model, string $system, string $userMsg, ?int $userId): void
    {
        $prefix = $this->db->prefix();

        $history = [];
        if ($userId) {
            $history = $this->db->fetchAll(
                "SELECT message, role FROM {$prefix}ai_chats WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10",
                ['uid' => $userId]
            );
            $history = array_reverse($history);
        }

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'] === 'user' ? 'user' : 'assistant', 'content' => $h['message']];
        }
        $messages[] = ['role' => 'user', 'content' => $userMsg];

        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'max_tokens' => (int)($this->config['ai']['max_tokens'] ?? 4096),
            'temperature' => (float)($this->config['ai']['temperature'] ?? 0.7),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $fullReply = '';
        if ($httpCode === 200 && $response) {
            foreach (explode("\n", $response) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $data = substr($line, 6);
                if ($data === '[DONE]') break;
                $json = json_decode($data, true);
                $token = $json['choices'][0]['delta']['content'] ?? '';
                if ($token) {
                    $fullReply .= $token;
                    echo "data: " . json_encode(['token' => $token]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }
        } else {
            $fullReply = "Ошибка API (HTTP {$httpCode}). Проверьте настройки ключа и URL.";
            echo "data: " . json_encode(['token' => $fullReply]) . "\n\n";
            ob_flush();
            flush();
        }

        $processedReply = $this->processTools($fullReply);

        $this->db->query(
            "INSERT INTO {$prefix}ai_chats (user_id, session_id, message, role) VALUES (:uid, :sid, :msg, 'assistant')",
            ['uid' => $userId, 'sid' => session_id(), 'msg' => $fullReply]
        );

        echo "data: " . json_encode(['done' => true, 'full' => $processedReply]) . "\n\n";
    }

    private function processTools(string $text): string
    {
        $pattern = '/\[TOOL:(\w+)\(([^)]*)\)\]/';
        return preg_replace_callback($pattern, function ($matches) {
            $toolName = $matches[1];
            $raw = $matches[2];
            $args = array_map('trim', explode(',', $raw));
            return $this->executeTool($toolName, $args);
        }, $text);
    }

    private function executeTool(string $name, array $args): string
    {
        $prefix = $this->db->prefix();

        return match ($name) {
            'search_aircraft'        => $this->toolSearchAircraft($args[0] ?? '', $prefix),
            'search_airports'        => $this->toolSearchAirports($args[0] ?? '', $prefix),
            'get_airport_metar'      => $this->toolGetMetar($args[0] ?? ''),
            'search_articles'        => $this->toolSearchArticles($args[0] ?? '', $prefix),
            'get_airline_info'       => $this->toolGetAirline($args[0] ?? '', $prefix),
            'get_aircraft_details'   => $this->toolGetAircraftDetails($args[0] ?? '', $prefix),
            'get_glossary_term'      => $this->toolGetGlossary($args[0] ?? '', $prefix),
            'get_event_info'         => $this->toolGetEvent($args[0] ?? '', $prefix),
            'calculate_distance'     => $this->toolCalcDistance($args[0] ?? '', $args[1] ?? '', $prefix),
            'convert_units'          => $this->toolConvertUnits((float)($args[0] ?? 0), $args[1] ?? '', $args[2] ?? ''),
            'calculate_crosswind'    => $this->toolCalcCrosswind((int)($args[0] ?? 0), (int)($args[1] ?? 0), (int)($args[2] ?? 0)),
            'calculate_descent'      => $this->toolCalcDescent((int)($args[0] ?? 0), (int)($args[1] ?? 0), (int)($args[2] ?? 250)),
            'calculate_compensation' => $this->toolCalcCompensation((int)($args[0] ?? 0), (int)($args[1] ?? 0)),
            'web_search_firecrawl'   => $this->toolWebSearch($args[0] ?? ''),
            'fetch_url_firecrawl'    => $this->toolFetchUrl($args[0] ?? ''),
            'map_site_firecrawl'     => $this->toolMapSite($args[0] ?? ''),
            'extract_content'        => $this->toolExtractContent($args[0] ?? ''),
            'show_flight_on_radar'   => "📍 Откройте радар: /radar",
            'start_quiz'             => "🧠 Викторины: /quizzes",
            'suggest_spotting_spots' => $this->toolSpottingSpots($args[0] ?? '', $prefix),
            default                  => "[Tool {$name} not found]",
        };
    }

    // ═══════════════════════════════════════════════════════════
    // DATABASE TOOLS
    // ═══════════════════════════════════════════════════════════

    private function toolSearchAircraft(string $query, string $prefix): string
    {
        if (!$query) return "Укажите название или код самолёта";
        $results = $this->db->fetchAll(
            "SELECT name, type_code, manufacturer, category, passengers, max_speed_knots, range_km
             FROM {$prefix}aircraft
             WHERE name LIKE :q OR type_code LIKE :q OR manufacturer LIKE :q LIMIT 5",
            ['q' => "%{$query}%"]
        );
        if (!$results) return "Самолёты по запросу «{$query}» не найдены";
        $out = "Найдено " . count($results) . ":\n";
        foreach ($results as $r) {
            $out .= "• {$r['name']} ({$r['type_code']}) — {$r['manufacturer']}";
            if ($r['passengers']) $out .= ", {$r['passengers']} пасс.";
            if ($r['max_speed_knots']) $out .= ", {$r['max_speed_knots']}кн";
            if ($r['range_km']) $out .= ", {$r['range_km']}км";
            $out .= "\n";
        }
        return $out;
    }

    private function toolGetAircraftDetails(string $query, string $prefix): string
    {
        if (!$query) return "Укажите самолёт";
        $ac = $this->db->fetchOne(
            "SELECT * FROM {$prefix}aircraft WHERE name LIKE :q OR type_code LIKE :q LIMIT 1",
            ['q' => "%{$query}%"]
        );
        if (!$ac) return "Самолёт «{$query}» не найден";

        $out = "✈️ {$ac['name']} ({$ac['type_code']})\n";
        $out .= str_repeat('─', 40) . "\n";
        $out .= "🏭 Производитель: " . ($ac['manufacturer'] ?? '—') . "\n";
        $out .= "📋 Тип: " . ($ac['category'] ?? '—') . "\n";
        if ($ac['passengers']) $out .= "👥 Пассажиров: {$ac['passengers']}\n";
        if ($ac['crew']) $out .= "👨‍✈️ Экипаж: {$ac['crew']}\n";
        if ($ac['engines_count']) $out .= "⚙️ Двигатели: {$ac['engines_count']}×" . ($ac['engine_type'] ?? '') . "\n";
        if ($ac['engine_model']) $out .= "   Модель: {$ac['engine_model']}\n";
        if ($ac['max_speed_knots']) $out .= "🚀 Макс. скорость: {$ac['max_speed_knots']} кн (" . round(knotsToKmh($ac['max_speed_knots'])) . " км/ч)\n";
        if ($ac['cruise_speed_knots']) $out .= "✈️ Крейсерская: {$ac['cruise_speed_knots']} кн\n";
        if ($ac['range_km']) $out .= "📏 Дальность: " . formatDistance($ac['range_km']) . "\n";
        if ($ac['ceiling_ft']) $out .= "⛰️ Потолок: " . formatNumber($ac['ceiling_ft']) . " фт (" . formatNumber(feetToMeters($ac['ceiling_ft'])) . " м)\n";
        if ($ac['mtow_kg']) $out .= "⚖️ MTOW: " . formatNumber($ac['mtow_kg']) . " кг\n";
        if ($ac['length_m']) $out .= "📐 Длина: {$ac['length_m']} м\n";
        if ($ac['wingspan_m']) $out .= "📐 Размах: {$ac['wingspan_m']} м\n";
        if ($ac['first_flight']) $out .= "📅 Первый полёт: " . formatDate($ac['first_flight']) . "\n";
        if ($ac['introduction_year']) $out .= "📅 Эксплуатация с: {$ac['introduction_year']}\n";
        if ($ac['production_status']) $out .= "📊 Статус: {$ac['production_status']}\n";
        if ($ac['history']) $out .= "\n📖 История:\n" . mb_substr($ac['history'], 0, 500) . "\n";
        return $out;
    }

    private function toolSearchAirports(string $query, string $prefix): string
    {
        if (!$query) return "Укажите ICAO, IATA или город";
        $results = $this->db->fetchAll(
            "SELECT name, icao_code, iata_code, city, country, elevation_ft
             FROM {$prefix}airports
             WHERE name LIKE :q OR icao_code LIKE :q OR iata_code LIKE :q OR city LIKE :q LIMIT 5",
            ['q' => "%{$query}%"]
        );
        if (!$results) return "Аэропорты по запросу «{$query}» не найдены";
        $out = "Найдено " . count($results) . ":\n";
        foreach ($results as $r) {
            $out .= "• {$r['name']} ({$r['icao_code']}";
            if ($r['iata_code']) $out .= "/{$r['iata_code']}";
            $out .= ") — {$r['city']}, {$r['country']}";
            if ($r['elevation_ft']) $out .= ", высота {$r['elevation_ft']}фт";
            $out .= "\n";
        }
        return $out;
    }

    private function toolGetMetar(string $icao): string
    {
        $icao = strtoupper(trim($icao));
        if (!$icao || strlen($icao) !== 4) return "Укажите 4-буквенный ICAO код (напр. UUEE)";
        $url = "https://aviationweather.gov/api/data/metar?ids={$icao}&format=raw";
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) return "METAR для {$icao} недоступен";
        $decoded = decodeMetar($raw);

        $tafUrl = "https://aviationweather.gov/api/data/taf?ids={$icao}&format=raw";
        $taf = @file_get_contents($tafUrl, false, $ctx);

        $out = "🌤 METAR {$icao}:\n{$raw}\n\n📊 Расшифровка: {$decoded['decoded']}";
        if ($taf) $out .= "\n\n📋 TAF:\n{$taf}";
        return $out;
    }

    private function toolSearchArticles(string $query, string $prefix): string
    {
        if (!$query) return "Укажите ключевое слово";
        $results = $this->db->fetchAll(
            "SELECT title, slug, published_at, views FROM {$prefix}news
             WHERE (title LIKE :q OR content LIKE :q) AND status = 'published'
             ORDER BY published_at DESC LIMIT 5",
            ['q' => "%{$query}%"]
        );
        if (!$results) return "Статьи по запросу «{$query}» не найдены";
        $out = "Найдено " . count($results) . ":\n";
        foreach ($results as $r) {
            $out .= "• {$r['title']} (" . formatDate($r['published_at'] ?? '') . ", 👁{$r['views']})\n  /news/{$r['slug']}\n";
        }
        return $out;
    }

    private function toolGetAirline(string $query, string $prefix): string
    {
        if (!$query) return "Укажите название авиакомпании";
        $al = $this->db->fetchOne(
            "SELECT * FROM {$prefix}airlines WHERE name LIKE :q OR iata_code LIKE :q OR icao_code LIKE :q LIMIT 1",
            ['q' => "%{$query}%"]
        );
        if (!$al) return "Авиакомпания «{$query}» не найдена";

        $fleetCount = $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}airline_fleet WHERE airline_id = :id", ['id' => $al['id']]);
        $out = "🏢 {$al['name']}\n";
        if ($al['iata_code']) $out .= "IATA: {$al['iata_code']} | ";
        if ($al['icao_code']) $out .= "ICAO: {$al['icao_code']}\n";
        if ($al['country']) $out .= "🌍 Страна: {$al['country']}\n";
        if ($al['hub_airports']) $out .= "🏠 Хаб: {$al['hub_airports']}\n";
        if ($al['alliance']) $out .= "🤝 Альянс: {$al['alliance']}\n";
        if ($al['founded']) $out .= "📅 Основана: {$al['founded']}\n";
        if ($al['website']) $out .= "🌐 Сайт: {$al['website']}\n";
        $out .= "✈️ Флот: {$fleetCount} типов ВС\n";
        if ($al['description']) $out .= "\n" . mb_substr($al['description'], 0, 300) . "\n";
        return $out;
    }

    private function toolGetGlossary(string $query, string $prefix): string
    {
        if (!$query) return "Укажите термин";
        $term = $this->db->fetchOne(
            "SELECT * FROM {$prefix}glossary WHERE term LIKE :q OR definition LIKE :q LIMIT 1",
            ['q' => "%{$query}%"]
        );
        if (!$term) return "Термин «{$query}» не найден в глоссарии";
        $out = "📖 {$term['term']}\n";
        if ($term['category']) $out .= "Категория: {$term['category']}\n";
        $out .= "\n{$term['definition']}\n";
        return $out;
    }

    private function toolGetEvent(string $query, string $prefix): string
    {
        if (!$query) return "Укажите название события";
        $event = $this->db->fetchOne(
            "SELECT * FROM {$prefix}events WHERE title LIKE :q OR description LIKE :q LIMIT 1",
            ['q' => "%{$query}%"]
        );
        if (!$event) return "Событие «{$query}» не найдено";
        $out = "📅 {$event['title']}\n";
        $out .= "📆 {$event['start_date']}";
        if ($event['end_date']) $out .= " — {$event['end_date']}";
        $out .= "\n";
        if ($event['location_name']) $out .= "📍 {$event['location_name']}\n";
        if ($event['country']) $out .= "🌍 {$event['country']}\n";
        if ($event['description']) $out .= "\n" . mb_substr($event['description'], 0, 300) . "\n";
        return $out;
    }

    private function toolSpottingSpots(string $icao, string $prefix): string
    {
        $icao = strtoupper(trim($icao));
        if (!$icao) return "Укажите ICAO код аэропорта";
        $airport = $this->db->fetchOne(
            "SELECT id, name FROM {$prefix}airports WHERE icao_code = :icao LIMIT 1",
            ['icao' => $icao]
        );
        if (!$airport) return "Аэропорт {$icao} не найден";
        $spots = $this->db->fetchAll(
            "SELECT * FROM {$prefix}airport_spotting_spots WHERE airport_id = :id ORDER BY rating_avg DESC",
            ['id' => $airport['id']]
        );
        if (!$spots) return "Споттинг-точки для {$icao} пока не добавлены";
        $out = "📍 Споттинг-точки {$airport['name']} ({$icao}):\n\n";
        foreach ($spots as $s) {
            $out .= "• {$s['name']} — ⭐ {$s['rating_avg']}/5 ({$s['rating_count']} оценок)\n";
            if ($s['description']) $out .= "  " . mb_substr($s['description'], 0, 120) . "\n";
            if ($s['recommended_focal_lengths']) $out .= "  📸 Объективы: {$s['recommended_focal_lengths']}\n";
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════
    // CALCULATION TOOLS
    // ═══════════════════════════════════════════════════════════

    private function toolCalcDistance(string $from, string $to, string $prefix): string
    {
        $ap1 = $this->db->fetchOne(
            "SELECT name, latitude, longitude FROM {$prefix}airports WHERE icao_code = :q OR iata_code = :q LIMIT 1",
            ['q' => strtoupper($from)]
        );
        $ap2 = $this->db->fetchOne(
            "SELECT name, latitude, longitude FROM {$prefix}airports WHERE icao_code = :q OR iata_code = :q LIMIT 1",
            ['q' => strtoupper($to)]
        );
        if (!$ap1 || !$ap2) return "Не найден один из аэропортов ({$from} / {$to})";
        $distKm = greatCircleDistance($ap1['latitude'], $ap1['longitude'], $ap2['latitude'], $ap2['longitude']);
        $distNm = round($distKm * 0.5399568);
        $flightTimeMin = round(($distNm / 450) * 60);
        return "📏 {$ap1['name']} → {$ap2['name']}:\nРасстояние: {$distKm} км ({$distNm} NM)\nВремя полёта: ~" . formatDuration($flightTimeMin) . "\nРасход топлива: ~" . round($distKm * 2.5) . " кг (оценка)";
    }

    private function toolConvertUnits(float $value, string $from, string $to): string
    {
        $conv = [
            'knots' => ['kmh' => 1.852, 'mph' => 1.15078, 'ms' => 0.514444],
            'kmh' => ['knots' => 0.539957, 'mph' => 0.621371, 'ms' => 0.277778],
            'ft' => ['m' => 0.3048, 'fl' => 0.01], 'm' => ['ft' => 3.28084, 'fl' => 0.0328084],
            'kg' => ['lbs' => 2.20462], 'lbs' => ['kg' => 0.453592],
            'hpa' => ['inhg' => 0.02953], 'inhg' => ['hpa' => 33.8639],
        ];
        $f = strtolower($from); $t = strtolower($to);
        if ($f === $t) return "{$value} {$f} = {$value} {$t}";
        if (isset($conv[$f][$t])) return "{$value} {$f} = " . round($value * $conv[$f][$t], 4) . " {$t}";
        return "Конвертация {$f} → {$t} не поддерживается";
    }

    private function toolCalcCrosswind(int $runway, int $windDir, int $windSpeed): string
    {
        $r = calculateCrosswind($runway, $windDir, $windSpeed);
        return "🌬 ВПП {$runway}° | Ветер {$windDir}°/{$windSpeed}кн:\nБоковой: {$r['crosswind']} кн\n" . ($r['is_tailwind'] ? 'Попутный' : 'Встречный') . ": {$r['headwind']} кн";
    }

    private function toolCalcDescent(int $alt, int $target, int $gs): string
    {
        $r = calculateDescentPoint($alt, $target, $gs);
        return " descent {$alt}фт → {$target}фт (GS {$gs}кн):\nTOD: {$r['distance_nm']} NM (~{$r['tod_minutes']} мин)\nV/S: ~{$r['fpm']} ft/min";
    }

    private function toolCalcCompensation(int $km, int $min): string
    {
        $r = calculateCompensation($km, $min);
        if (!$r['eligible']) return "Задержка {$min} мин при {$km} км: компенсация не положена (менее 3ч)";
        return "✈️ EC261/2004:\n{$km} км, {$min} мин задержки\nСумма: €{$r['amount']}";
    }

    // ═══════════════════════════════════════════════════════════
    // FIRECRAWL TOOLS
    // ═══════════════════════════════════════════════════════════

    private function toolWebSearch(string $query): string
    {
        $fc = $this->getFirecrawl();
        if (!$fc->isConfigured()) return "❌ Веб-поиск не настроен. Добавьте Firecrawl API ключ в «Настройки ИИ» админки.";

        $result = $fc->search($query, 5);
        if (isset($result['error'])) return "❌ {$result['error']}";

        $results = $result['results'] ?? [];
        if (empty($results)) return "Ничего не найдено по запросу «{$query}»";

        $out = "🌐 Результаты поиска «{$query}»:\n\n";
        foreach ($results as $i => $r) {
            $out .= ($i + 1) . ". {$r['title']}\n";
            $out .= "   🔗 {$r['url']}\n";
            if ($r['snippet']) $out .= "   " . mb_substr($r['snippet'], 0, 200) . "\n";
            $out .= "\n";
        }
        return $out;
    }

    private function toolFetchUrl(string $url): string
    {
        $fc = $this->getFirecrawl();
        if (!$fc->isConfigured()) return "❌ Извлечение URL не настроено. Добавьте Firecrawl API ключ.";

        if (!filter_var($url, FILTER_VALIDATE_URL)) return "Невалидный URL: {$url}";

        $result = $fc->extractContent($url, 6000);
        if (isset($result['error'])) return "❌ {$result['error']}";

        $out = "📄 {$result['title']}\n🔗 {$result['url']}\n\n{$result['content']}";
        return $out;
    }

    private function toolMapSite(string $url): string
    {
        $fc = $this->getFirecrawl();
        if (!$fc->isConfigured()) return "❌ Карта сайта не настроена. Добавьте Firecrawl API ключ.";

        $result = $fc->map($url, 30);
        if (isset($result['error'])) return "❌ {$result['error']}";

        $links = $result['links'] ?? [];
        if (empty($links)) return "Карта ссылок для {$url} пуста";

        $out = "🗺 Карта сайта {$url} (найдено: " . count($links) . "):\n\n";
        foreach (array_slice($links, 0, 20) as $link) {
            $out .= "• {$link}\n";
        }
        return $out;
    }

    private function toolExtractContent(string $url): string
    {
        return $this->toolFetchUrl($url);
    }

    // ═══════════════════════════════════════════════════════════
    // LOCAL FALLBACK (без AI API)
    // ═══════════════════════════════════════════════════════════

    private function streamLocal(string $message, ?int $userId): void
    {
        $prefix = $this->db->prefix();
        $toolResult = $this->tryDirectToolMatch($message);
        $reply = $toolResult ?? ("👋 Я — AI-ассистент VladAero.\n"
            . "Для полноценной работы настройте AI API ключ в «Настройки ИИ».\n\n"
            . "Пока доступны прямые команды:\n"
            . "• метар UUEE — погода\n"
            . "• расстояние SVO LED — расстояние\n"
            . "• самолёт A320 — поиск\n"
            . "• аэропорт UUEE — поиск\n"
            . "• авиакомпания Аэрофлот — поиск\n"
            . "• глоссарий глиссада — термин\n"
            . "• ветер 27 330 15 — боковой ветер\n"
            . "• компенсация 2000 240 — EC261");

        $words = explode(' ', $reply);
        foreach ($words as $word) {
            echo "data: " . json_encode(['token' => $word . ' ']) . "\n\n";
            ob_flush();
            flush();
            usleep(30000);
        }

        $this->db->query(
            "INSERT INTO {$prefix}ai_chats (user_id, session_id, message, role) VALUES (:uid, :sid, :msg, 'assistant')",
            ['uid' => $userId, 'sid' => session_id(), 'msg' => $reply]
        );
        echo "data: " . json_encode(['done' => true]) . "\n\n";
    }

    private function tryDirectToolMatch(string $message): ?string
    {
        $msg = mb_strtolower(trim($message));
        $prefix = $this->db->prefix();

        if (preg_match('/(метар|metar|погода|weather)\s+([A-Za-z]{4})/i', $message, $m))
            return $this->toolGetMetar($m[2]);

        if (preg_match('/(расстояние|distance|маршрут|route)\s+(.+?)\s+(и|and|->|—|–|to)\s+(.+)/i', $message, $m))
            return $this->toolCalcDistance(trim($m[2]), trim($m[4]), $prefix);

        if (preg_match('/ветер.*?(\d{3}).*?(\d{3})\s+(\d+)/i', $message, $m))
            return $this->toolCalcCrosswind((int)$m[1], (int)$m[2], (int)$m[3]);

        if (preg_match('/компенсаци[яю]\s+.+?(\d+)\s*к?м.*?(\d+)\s*(ч|min|мин)/i', $message, $m)) {
            $delay = (int)$m[2];
            if (str_contains($m[3], 'ч')) $delay *= 60;
            return $this->toolCalcCompensation((int)$m[1], $delay);
        }

        if (preg_match('/(самол[её]т|aircraft|детали|подробно)\s+(.+)/i', $message, $m))
            return $this->toolGetAircraftDetails(trim($m[2]), $prefix);

        if (preg_match('/(аэропорт|airport)\s+(.+)/i', $message, $m))
            return $this->toolSearchAirports(trim($m[2]), $prefix);

        if (preg_match('/(авиакомпани[яю]|airline)\s+(.+)/i', $message, $m))
            return $this->toolGetAirline(trim($m[2]), $prefix);

        if (preg_match('/(глоссарий|термин|что такое)\s+(.+)/i', $message, $m))
            return $this->toolGetGlossary(trim($m[2]), $prefix);

        if (preg_match('/(споттинг|точки)\s+(.+)/i', $message, $m))
            return $this->toolSpottingSpots(trim($m[2]), $prefix);

        if (preg_match('/(поиск|search|найди)\s+(.+)/i', $message, $m))
            return $this->toolSearchAircraft(trim($m[2]), $prefix);

        return null;
    }
}
