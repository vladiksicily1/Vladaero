<?php
declare(strict_types=1);

namespace VladAero;

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/weather_decoder.php';

$icao = strtoupper(trim($_GET['icao'] ?? ''));

if (strlen($icao) !== 4) {
    echo json_encode(['success' => false, 'error' => 'Укажите корректный 4-значный ICAO код аэропорта.']);
    exit;
}

$decoded = WeatherDecoder::getMetar($icao);
echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
