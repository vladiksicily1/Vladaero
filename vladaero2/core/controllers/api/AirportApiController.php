<?php
namespace VladAero\Controllers\Api;

use VladAero\Core\{Controller, Session, Database};

/**
 * AirportApiController - REST API для аэропортов
 */
class AirportApiController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $country = $this->query('country') ?? '';
        $limit = min(100, max(1, (int)($this->query('limit') ?? 50)));

        $where = '';
        $params = [];
        if ($country) { $where = 'WHERE country = :c'; $params['c'] = $country; }

        $airports = $this->db->fetchAll(
            "SELECT * FROM {$prefix}airports {$where} ORDER BY name LIMIT {$limit}", $params
        );
        $this->corsJson($airports);
    }

    public function show(string $icao)
    {
        $prefix = $this->db->prefix();
        $airport = $this->db->fetchOne(
            "SELECT * FROM {$prefix}airports WHERE icao_code = :icao OR iata_code = :iata",
            ['icao' => strtoupper($icao), 'iata' => strtoupper($icao)]
        );
        if (!$airport) { $this->corsJson(['error' => 'Not found'], 404); return; }
        $this->corsJson($airport);
    }

    public function metar(string $icao)
    {
        $url = "https://aviationweather.gov/api/data/metar?ids=" . strtoupper($icao) . "&format=raw";
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $raw = @file_get_contents($url, false, $ctx);
        $this->corsJson(['icao' => strtoupper($icao), 'metar' => $raw ?: null]);
    }

    private function corsJson(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
