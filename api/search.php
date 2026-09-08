<?php
/**
 * VladAero Spotlight Search API
 * Fast full-text & pattern searching across all aviation entities.
 */

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/functions.php';

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$results = [];

if (Database::isConfigured()) {
    $param = "%{$q}%";

    // 1. Search Aircraft
    $acTable = Database::tableName('aircraft');
    $acRows = Database::fetchAll("SELECT model_name, slug, icao_code, short_desc FROM `{$acTable}` WHERE model_name LIKE :q OR icao_code LIKE :q OR short_desc LIKE :q LIMIT 5", ['q' => $param]);
    foreach ($acRows as $r) {
        $results[] = [
            'badge' => $r['icao_code'] ?: 'ВС',
            'title' => $r['model_name'],
            'subtitle' => $r['short_desc'] ?: 'Воздушное судно',
            'url' => url('/aircraft.php?slug=' . urlencode($r['slug']))
        ];
    }

    // 2. Search Airports
    $apTable = Database::tableName('airports');
    $apRows = Database::fetchAll("SELECT name_ru, icao, iata, city_ru FROM `{$apTable}` WHERE name_ru LIKE :q OR icao LIKE :q OR iata LIKE :q OR city_ru LIKE :q LIMIT 5", ['q' => $param]);
    foreach ($apRows as $r) {
        $codes = $r['icao'] . ($r['iata'] ? " / {$r['iata']}" : '');
        $results[] = [
            'badge' => $codes,
            'title' => $r['name_ru'],
            'subtitle' => "Аэропорт, г. {$r['city_ru']}",
            'url' => url('/airports.php?code=' . urlencode($r['icao']))
        ];
    }

    // 3. Search Glossary Terms
    $glTable = Database::tableName('glossary');
    $glRows = Database::fetchAll("SELECT term, abbreviation, short_def FROM `{$glTable}` WHERE term LIKE :q OR abbreviation LIKE :q OR short_def LIKE :q LIMIT 4", ['q' => $param]);
    foreach ($glRows as $r) {
        $results[] = [
            'badge' => $r['abbreviation'] ?: 'ТЕРМИН',
            'title' => $r['term'],
            'subtitle' => $r['short_def'],
            'url' => url('/glossary.php?search=' . urlencode($r['term']))
        ];
    }

    // 4. Search Articles
    $artTable = Database::tableName('articles');
    $artRows = Database::fetchAll("SELECT title, slug, summary FROM `{$artTable}` WHERE is_published = 1 AND (title LIKE :q OR summary LIKE :q) LIMIT 3", ['q' => $param]);
    foreach ($artRows as $r) {
        $results[] = [
            'badge' => 'СТАТЬЯ',
            'title' => $r['title'],
            'subtitle' => $r['summary'],
            'url' => url('/articles.php?slug=' . urlencode($r['slug']))
        ];
    }
}

// Fallback hardcoded matching if database is empty
if (empty($results)) {
    $hardcoded = [
        ['badge' => 'A20N', 'title' => 'Airbus A320neo', 'subtitle' => 'Самый популярный узкофюзеляжный лайнер', 'url' => url('/aircraft.php?slug=airbus-a320neo')],
        ['badge' => 'B738', 'title' => 'Boeing 737-800 Next Gen', 'subtitle' => 'Базовый лайнер мирового флота', 'url' => url('/aircraft.php?slug=boeing-737-800')],
        ['badge' => 'UUEE', 'title' => 'Шереметьево (SVO)', 'subtitle' => 'Международный аэропорт, Москва', 'url' => url('/airports.php?code=UUEE')],
        ['badge' => 'E6B', 'title' => 'Калькулятор бокового ветра', 'subtitle' => 'Интерактивный расчет кроссвинда и ВПП', 'url' => url('/calculators.php')]
    ];
    foreach ($hardcoded as $item) {
        if (stripos($item['title'], $q) !== false || stripos($item['badge'], $q) !== false || stripos($item['subtitle'], $q) !== false) {
            $results[] = $item;
        }
    }
}

echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
