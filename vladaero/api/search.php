<?php
declare(strict_types=1);

namespace VladAero;

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['aircraft' => [], 'airports' => [], 'articles' => []]);
    exit;
}

$aircraft = DB::fetchAll(
    "SELECT `id`, `model_name`, `slug`, `icao_code`, `short_desc`, `hero_image` 
     FROM `va_aircraft` 
     WHERE `model_name` LIKE :q OR `icao_code` LIKE :q OR `iata_code` LIKE :q OR `short_desc` LIKE :q 
     LIMIT 5",
    ['q' => "%{$q}%"]
);

$airports = DB::fetchAll(
    "SELECT `icao`, `iata`, `name_ru`, `city_ru`, `country_ru` 
     FROM `va_airports` 
     WHERE `icao` LIKE :q OR `iata` LIKE :q OR `name_ru` LIKE :q OR `name_en` LIKE :q OR `city_ru` LIKE :q 
     LIMIT 5",
    ['q' => "%{$q}%"]
);

$articles = DB::fetchAll(
    "SELECT `id`, `slug`, `title`, `summary` 
     FROM `va_articles` 
     WHERE (`title` LIKE :q OR `summary` LIKE :q) AND `is_published` = 1 
     LIMIT 5",
    ['q' => "%{$q}%"]
);

echo json_encode([
    'aircraft' => $aircraft,
    'airports' => $airports,
    'articles' => $articles
], JSON_UNESCAPED_UNICODE);
