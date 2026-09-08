<?php
/**
 * VladAero METAR / TAF Weather Decoder API Endpoint
 */

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/weather_decoder.php';

$icao = trim($_GET['icao'] ?? $_POST['icao'] ?? 'UUEE');
$result = WeatherDecoder::getMetar($icao);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
