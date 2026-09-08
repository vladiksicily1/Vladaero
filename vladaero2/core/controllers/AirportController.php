<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * AirportController - Каталог аэропортов, METAR/TAF, споттинг
 */
class AirportController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $perPage = $this->config['per_page']['default'] ?? 24;
        $page = max(1, (int)($this->query('page') ?? 1));
        $search = $this->query('q') ?: '';
        $country = $this->query('country') ?: '';

        $where = [];
        $params = [];

        if ($search) {
            $where[] = "(a.name LIKE :q OR a.iata_code LIKE :q OR a.icao_code LIKE :q OR a.city LIKE :q)";
            $params['q'] = "%{$search}%";
        }
        if ($country) {
            $where[] = "a.country = :country";
            $params['country'] = $country;
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$prefix}airports a {$whereStr}", $params
        );
        $pagination = paginate($total, $perPage, $page);

        $airports = $this->db->fetchAll(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM {$prefix}photos p WHERE p.airport_id = a.id) as photo_count,
                    (SELECT COUNT(*) FROM {$prefix}spotting_points sp WHERE sp.airport_id = a.id) as spot_count
             FROM {$prefix}airports a {$whereStr}
             ORDER BY a.name ASC
             LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
            $params
        );

        $countries = $this->db->fetchAll(
            "SELECT DISTINCT country FROM {$prefix}airports WHERE country != '' ORDER BY country"
        );

        $this->view('pages.airports.index', compact('airports', 'countries', 'search', 'country', 'pagination', 'total'));
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();
        $icao = strtoupper(trim($slug));

        $airport = $this->db->fetchOne(
            "SELECT * FROM {$prefix}airports WHERE icao_code = :icao OR iata_code = :iata",
            ['icao' => $icao, 'iata' => $icao]
        );
        if (!$airport) $this->abort(404, 'Аэропорт не найден');

        $metar = $this->fetchMetar($airport['icao_code']);
        $taf = $this->fetchTaf($airport['icao_code']);

        $spotting = $this->db->fetchAll(
            "SELECT * FROM {$prefix}spotting_points WHERE airport_id = :id ORDER BY name",
            ['id' => $airport['id']]
        );

        $photos = $this->db->fetchAll(
            "SELECT p.*, u.username FROM {$prefix}photos p
             LEFT JOIN {$prefix}users u ON p.user_id = u.id
             WHERE p.airport_id = :id AND p.status = 'approved'
             ORDER BY p.created_at DESC LIMIT 12",
            ['id' => $airport['id']]
        );

        $this->view('pages.airports.show', compact('airport', 'metar', 'taf', 'spotting', 'photos'));
    }

    private function fetchMetar(string $icao): ?string
    {
        $url = "https://aviationweather.gov/api/data/metar?ids={$icao}&format=raw";
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $result = @file_get_contents($url, false, $ctx);
        return $result ?: null;
    }

    private function fetchTaf(string $icao): ?string
    {
        $url = "https://aviationweather.gov/api/data/taf?ids={$icao}&format=raw";
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $result = @file_get_contents($url, false, $ctx);
        return $result ?: null;
    }
}
