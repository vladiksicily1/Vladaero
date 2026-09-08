<?php
namespace VladAero\Controllers\Api;

use VladAero\Core\{Controller, Session, Database};

/**
 * AirlineApiController - REST API для авиакомпаний + Radar proxy
 */
class AirlineApiController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $country = $this->query('country') ?? '';
        $limit = min(100, max(1, (int)($this->query('limit') ?? 50)));

        $where = '';
        $params = [];
        if ($country) { $where = 'WHERE country = :c'; $params['c'] = $country; }

        $airlines = $this->db->fetchAll(
            "SELECT * FROM {$prefix}airlines {$where} ORDER BY name LIMIT {$limit}", $params
        );
        $this->corsJson($airlines);
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();
        $airline = $this->db->fetchOne(
            "SELECT * FROM {$prefix}airlines WHERE slug = :slug", ['slug' => $slug]
        );
        if (!$airline) { $this->corsJson(['error' => 'Not found'], 404); return; }

        $fleet = $this->db->fetchAll(
            "SELECT f.*, ac.name as aircraft_name FROM {$prefix}fleet f
             LEFT JOIN {$prefix}aircraft_types ac ON f.aircraft_type_id = ac.id
             WHERE f.airline_id = :id", ['id' => $airline['id']]
        );
        $airline['fleet'] = $fleet;
        $this->corsJson($airline);
    }

    public function radar()
    {
        $minLat = (float)($this->query('min_lat') ?? 30);
        $maxLat = (float)($this->query('max_lat') ?? 72);
        $minLon = (float)($this->query('min_lon') ?? -30);
        $maxLon = (float)($this->query('max_lon') ?? 60);

        $url = "https://opensky-network.org/api/states/all?lamin={$minLat}&lamax={$maxLat}&lomin={$minLon}&lomax={$maxLon}";
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $raw = @file_get_contents($url, false, $ctx);
        $data = json_decode($raw ?? '{}', true);

        $flights = [];
        foreach (array_slice($data['states'] ?? [], 0, 500) as $s) {
            $flights[] = [
                'icao24' => $s[0], 'callsign' => trim($s[1] ?? ''),
                'lat' => $s[6], 'lon' => $s[5], 'alt' => $s[7],
                'heading' => $s[8], 'velocity' => $s[9],
            ];
        }
        $this->corsJson(['states' => $flights]);
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
