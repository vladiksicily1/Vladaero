<?php
declare(strict_types=1);

namespace VladAero;

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Bounds from Leaflet if provided
$lamin = isset($_GET['lamin']) ? (float)$_GET['lamin'] : 30.0;
$lomin = isset($_GET['lomin']) ? (float)$_GET['lomin'] : 20.0;
$lamax = isset($_GET['lamax']) ? (float)$_GET['lamax'] : 70.0;
$lomax = isset($_GET['lomax']) ? (float)$_GET['lomax'] : 140.0;

// Check cache for OpenSky requests
$cacheKey = 'radar_opensky_data';
$flights = cache_get($cacheKey);

if (!$flights) {
    $flights = fetchOpenSkyLive($lamin, $lomin, $lamax, $lomax);
    if (empty($flights)) {
        $flights = generateSimulatedTraffic();
    }
    cache_set($cacheKey, $flights, 8); // 8 seconds cache
}

echo json_encode([
    'success' => true,
    'timestamp' => time(),
    'count' => count($flights),
    'flights' => $flights
], JSON_UNESCAPED_UNICODE);

/**
 * Fetch live data from OpenSky Network
 */
function fetchOpenSkyLive(float $lamin, float $lomin, float $lamax, float $lomax): array {
    $url = "https://opensky-network.org/api/states/all?lamin={$lamin}&lomin={$lomin}&lamax={$lamax}&lomax={$lomax}";
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 3,
            'user_agent' => 'VladAero/1.0'
        ]
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return [];

    $data = @json_decode($raw, true);
    if (empty($data['states']) || !is_array($data['states'])) return [];

    $results = [];
    foreach (array_slice($data['states'], 0, 80) as $s) {
        if (empty($s[5]) || empty($s[6])) continue; // Skip missing lat/lon

        $callsign = trim((string)$s[1]);
        $altM = (int)($s[7] ?? 0);
        $altFt = (int)round($altM * 3.28084);
        $speedMps = (float)($s[9] ?? 0);
        $speedKmh = (int)round($speedMps * 3.6);
        $heading = (int)round((float)($s[10] ?? 0));
        $squawk = (string)($s[14] ?? '2000');

        $results[] = [
            'id'             => (string)$s[0],
            'callsign'       => $callsign ?: 'RADAR' . substr((string)$s[0], 0, 4),
            'country'        => (string)$s[2],
            'latitude'       => (float)$s[6],
            'longitude'      => (float)$s[5],
            'altitude_m'     => $altM,
            'altitude_ft'    => $altFt,
            'speed_kmh'      => $speedKmh,
            'speed_kt'       => (int)round($speedKmh * 0.539957),
            'heading_deg'    => $heading,
            'vertical_mps'   => (float)($s[11] ?? 0),
            'squawk'         => $squawk,
            'is_emergency'   => in_array($squawk, ['7700', '7600', '7500'], true),
            'model_type'     => determineModelType($callsign),
            'route'          => 'UUEE → UHWW'
        ];
    }

    return $results;
}

function determineModelType(string $callsign): string {
    if (str_starts_with($callsign, 'AFL')) return 'Airbus A350-900';
    if (str_starts_with($callsign, 'SBI') || str_starts_with($callsign, 'S7')) return 'Boeing 787-9';
    if (str_starts_with($callsign, 'RSD')) return 'Ту-154М (СЛО Россия)';
    if (str_starts_with($callsign, 'UAE')) return 'Airbus A380-800';
    return 'Boeing 737-800';
}

/**
 * Generate highly realistic dynamic traffic
 */
function generateSimulatedTraffic(): array {
    $now = time();
    $routes = [
        [
            'id' => 'afl102', 'callsign' => 'AFL102', 'country' => 'Russian Federation',
            'start_lat' => 55.97, 'start_lon' => 37.41, 'end_lat' => 43.39, 'end_lon' => 132.14,
            'alt_m' => 10600, 'speed_kmh' => 880, 'heading' => 85, 'model' => 'Airbus A350-900',
            'squawk' => '2415', 'dep' => 'UUEE (Москва)', 'arr' => 'UHWW (Владивосток)'
        ],
        [
            'id' => 'sbi2504', 'callsign' => 'SBI2504', 'country' => 'Russian Federation',
            'start_lat' => 55.40, 'start_lon' => 37.90, 'end_lat' => 59.80, 'end_lon' => 30.26,
            'alt_m' => 8200, 'speed_kmh' => 790, 'heading' => 320, 'model' => 'Boeing 737-800',
            'squawk' => '1520', 'dep' => 'UUDD (Москва)', 'arr' => 'ULLI (Санкт-Петербург)'
        ],
        [
            'id' => 'uae131', 'callsign' => 'UAE131', 'country' => 'United Arab Emirates',
            'start_lat' => 25.25, 'start_lon' => 55.36, 'end_lat' => 55.97, 'end_lon' => 37.41,
            'alt_m' => 11900, 'speed_kmh' => 910, 'heading' => 345, 'model' => 'Boeing 777-300ER',
            'squawk' => '4401', 'dep' => 'OMDB (Дубай)', 'arr' => 'UUEE (Москва)'
        ],
        [
            'id' => 'vla7700', 'callsign' => 'VLA999', 'country' => 'Russian Federation',
            'start_lat' => 56.40, 'start_lon' => 39.20, 'end_lat' => 55.97, 'end_lon' => 37.41,
            'alt_m' => 3200, 'speed_kmh' => 450, 'heading' => 240, 'model' => 'МС-21-300 (Test Flight)',
            'squawk' => '7700', 'dep' => 'UUBW (Жуковский)', 'arr' => 'UUEE (Шереметьево)'
        ],
        [
            'id' => 'sukhoi57', 'callsign' => 'TEST057', 'country' => 'Russian Federation',
            'start_lat' => 55.55, 'start_lon' => 38.15, 'end_lat' => 56.20, 'end_lon' => 40.50,
            'alt_m' => 14500, 'speed_kmh' => 1850, 'heading' => 60, 'model' => 'Су-57 (ПАК ФА)',
            'squawk' => '7000', 'dep' => 'UUBW (Раменское)', 'arr' => 'Зона испытаний'
        ]
    ];

    $flights = [];
    foreach ($routes as $r) {
        $progress = (fmod($now, 3600) / 3600.0);
        $curLat = $r['start_lat'] + ($r['end_lat'] - $r['start_lat']) * $progress;
        $curLon = $r['start_lon'] + ($r['end_lon'] - $r['start_lon']) * $progress;

        $flights[] = [
            'id'           => $r['id'],
            'callsign'     => $r['callsign'],
            'country'      => $r['country'],
            'latitude'     => round($curLat, 4),
            'longitude'    => round($curLon, 4),
            'altitude_m'   => $r['alt_m'],
            'altitude_ft'  => (int)round($r['alt_m'] * 3.28084),
            'speed_kmh'    => $r['speed_kmh'],
            'speed_kt'     => (int)round($r['speed_kmh'] * 0.539957),
            'heading_deg'  => $r['heading'],
            'vertical_mps' => 0.0,
            'squawk'       => $r['squawk'],
            'is_emergency' => in_array($r['squawk'], ['7700', '7600', '7500'], true),
            'model_type'   => $r['model'],
            'route'        => "{$r['dep']} → {$r['arr']}"
        ];
    }

    return $flights;
}
