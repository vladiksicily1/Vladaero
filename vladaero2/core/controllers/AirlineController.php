<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * AirlineController - Каталог авиакомпаний, флот, ливреи
 */
class AirlineController extends Controller
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
            $where[] = "(al.name LIKE :q OR al.iata_code LIKE :q OR al.icao_code LIKE :q)";
            $params['q'] = "%{$search}%";
        }
        if ($country) {
            $where[] = "al.country = :country";
            $params['country'] = $country;
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$prefix}airlines al {$whereStr}", $params
        );
        $pagination = paginate($total, $perPage, $page);

        $airlines = $this->db->fetchAll(
            "SELECT al.*,
                    (SELECT COUNT(*) FROM {$prefix}fleet f WHERE f.airline_id = al.id) as fleet_count
             FROM {$prefix}airlines al {$whereStr}
             ORDER BY al.name ASC
             LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
            $params
        );

        $countries = $this->db->fetchAll(
            "SELECT DISTINCT country FROM {$prefix}airlines WHERE country != '' ORDER BY country"
        );

        $this->view('pages.airlines.index', compact('airlines', 'countries', 'search', 'country', 'pagination', 'total'));
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();
        $slug = strtolower(trim($slug));

        $airline = $this->db->fetchOne(
            "SELECT * FROM {$prefix}airlines WHERE slug = :slug", ['slug' => $slug]
        );
        if (!$airline) $this->abort(404, 'Авиакомпания не найдена');

        $fleet = $this->db->fetchAll(
            "SELECT f.*, ac.name as aircraft_name, ac.type_code, ac.manufacturer
             FROM {$prefix}fleet f
             LEFT JOIN {$prefix}aircraft_types ac ON f.aircraft_type_id = ac.id
             WHERE f.airline_id = :id
             ORDER BY ac.manufacturer, ac.name",
            ['id' => $airline['id']]
        );

        $photos = $this->db->fetchAll(
            "SELECT p.*, u.username FROM {$prefix}photos p
             LEFT JOIN {$prefix}users u ON p.user_id = u.id
             WHERE p.airline_id = :id AND p.status = 'approved'
             ORDER BY p.created_at DESC LIMIT 12",
            ['id' => $airline['id']]
        );

        $this->view('pages.airlines.show', compact('airline', 'fleet', 'photos'));
    }
}
