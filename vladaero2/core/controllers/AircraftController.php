<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Cache};

class AircraftController extends Controller
{
    public function index()
    {
        $db = $this->db;
        $prefix = $db->prefix();
        $cache = new Cache();
        $perPage = $this->config['per_page']['default'] ?? 24;
        $page = max(1, (int)($this->query('page') ?? 1));

        // Filters
        $type = $this->query('type') ?: null;
        $category = $this->query('category') ?: null;
        $manufacturer = $this->query('manufacturer') ?: null;
        $country = $this->query('country') ?: null;
        $search = $this->query('q') ?: null;
        $sort = $this->query('sort') ?: 'name';

        $where = ['a.is_published = 1'];
        $params = [];

        if ($type) { $where[] = 'a.type = :type'; $params['type'] = $type; }
        if ($category) { $where[] = 'a.category = :category'; $params['category'] = $category; }
        if ($manufacturer) { $where[] = 'a.manufacturer_id = :mfr'; $params['mfr'] = $manufacturer; }
        if ($country) { $where[] = 'a.country_code = :country'; $params['country'] = $country; }
        if ($search) {
            $where[] = '(a.name LIKE :search OR a.name_en LIKE :search OR a.icao_code LIKE :search OR a.iata_code LIKE :search)';
            $params['search'] = "%{$search}%";
        }

        $whereStr = implode(' AND ', $where);

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM {$prefix}aircraft a WHERE {$whereStr}", $params);
        $pagination = paginate($total, $perPage, $page);

        $orderBy = match($sort) {
            'newest' => 'a.created_at DESC',
            'popular' => 'a.views_count DESC',
            'speed' => 'a.max_speed_knots DESC',
            'range' => 'a.range_km DESC',
            default => 'a.name ASC',
        };

        $aircraft = $db->fetchAll(
            "SELECT a.*, m.name as manufacturer_name, m.slug as manufacturer_slug
             FROM {$prefix}aircraft a
             LEFT JOIN {$prefix}aircraft_manufacturers m ON a.manufacturer_id = m.id
             WHERE {$whereStr}
             ORDER BY {$orderBy}
             LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
            $params
        );

        // Get manufacturers for filter
        $manufacturers = $db->fetchAll("SELECT id, name, slug FROM {$prefix}aircraft_manufacturers ORDER BY name");

        $this->view('pages.aircraft.index', compact('aircraft', 'pagination', 'manufacturers', 'type', 'category', 'manufacturer', 'country', 'search', 'sort', 'total'));
    }

    public function show(string $slug)
    {
        $db = $this->db;
        $prefix = $db->prefix();

        $aircraft = $db->fetchOne(
            "SELECT a.*, m.name as manufacturer_name, m.slug as manufacturer_slug, m.country as manufacturer_country
             FROM {$prefix}aircraft a
             LEFT JOIN {$prefix}aircraft_manufacturers m ON a.manufacturer_id = m.id
             WHERE a.slug = :slug AND a.is_published = 1",
            ['slug' => $slug]
        );

        if (!$aircraft) $this->abort(404, 'Самолёт не найден');

        // Increment views
        $db->query("UPDATE {$prefix}aircraft SET views_count = views_count + 1 WHERE id = :id", ['id' => $aircraft['id']]);

        // Get photos
        $photos = $db->fetchAll(
            "SELECT p.* FROM {$prefix}photos p
             JOIN {$prefix}aircraft_photos ap ON ap.photo_id = p.id
             WHERE ap.aircraft_id = :id AND p.status = 'approved'
             ORDER BY p.shot_date DESC
             LIMIT 20",
            ['id' => $aircraft['id']]
        );

        // Get incidents
        $incidents = $db->fetchAll(
            "SELECT * FROM {$prefix}aircraft_incidents WHERE aircraft_id = :id ORDER BY date DESC LIMIT 10",
            ['id' => $aircraft['id']]
        );

        // Get liveries
        $liveries = $db->fetchAll(
            "SELECT l.*, al.name as airline_name FROM {$prefix}liveries l
             LEFT JOIN {$prefix}airlines al ON l.airline_id = al.id
             WHERE l.aircraft_id = :id AND l.is_published = 1
             ORDER BY l.downloads_count DESC LIMIT 10",
            ['id' => $aircraft['id']]
        );

        // Related aircraft (same manufacturer)
        $related = $db->fetchAll(
            "SELECT a.*, m.name as manufacturer_name FROM {$prefix}aircraft a
             LEFT JOIN {$prefix}aircraft_manufacturers m ON a.manufacturer_id = m.id
             WHERE a.manufacturer_id = :mfr AND a.id != :id AND a.is_published = 1
             ORDER BY a.name LIMIT 4",
            ['mfr' => $aircraft['manufacturer_id'], 'id' => $aircraft['id']]
        );

        // Parse modifications
        $modifications = json_decode($aircraft['modifications'] ?? '[]', true);
        $ttxSummary = json_decode($aircraft['ttx_summary'] ?? '{}', true);
        $specsDetailed = json_decode($aircraft['specs_detailed'] ?? '{}', true);

        $this->view('pages.aircraft.show', compact(
            'aircraft', 'photos', 'incidents', 'liveries', 'related',
            'modifications', 'ttxSummary', 'specsDetailed'
        ));
    }

    public function compare()
    {
        $db = $this->db;
        $prefix = $db->prefix();

        // Get aircraft selected for comparison
        $ids = explode(',', $this->query('ids') ?? '');
        $ids = array_filter(array_map('intval', $ids));
        $ids = array_slice($ids, 0, 4);

        $aircraft = [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $aircraft = $db->fetchAll(
                "SELECT a.*, m.name as manufacturer_name
                 FROM {$prefix}aircraft a
                 LEFT JOIN {$prefix}aircraft_manufacturers m ON a.manufacturer_id = m.id
                 WHERE a.id IN ({$placeholders}) AND a.is_published = 1",
                $ids
            );
        }

        $this->view('pages.aircraft.compare', compact('aircraft'));
    }

    public function compareView(string $slug)
    {
        $db = $this->db;
        $prefix = $db->prefix();

        $comparison = $db->fetchOne(
            "SELECT * FROM {$prefix}comparisons WHERE slug = :slug",
            ['slug' => $slug]
        );

        if (!$comparison) {
            // Parse slug like a320neo-vs-b737max
            $parts = explode('-vs-', $slug);
            if (count($parts) === 2) {
                $a1 = $db->fetchOne("SELECT * FROM {$prefix}aircraft WHERE slug LIKE :s AND is_published = 1", ['s' => '%' . $parts[0] . '%']);
                $a2 = $db->fetchOne("SELECT * FROM {$prefix}aircraft WHERE slug LIKE :s AND is_published = 1", ['s' => '%' . $parts[1] . '%']);
                if ($a1 && $a2) {
                    $aircraft = [$a1, $a2];
                    $this->view('pages.aircraft.compare', compact('aircraft'));
                    return;
                }
            }
            $this->abort(404, 'Сравнение не найдено');
        }

        $ids = json_decode($comparison['aircraft_ids'] ?? '[]', true);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $aircraft = $db->fetchAll(
            "SELECT a.*, m.name as manufacturer_name FROM {$prefix}aircraft a
             LEFT JOIN {$prefix}aircraft_manufacturers m ON a.manufacturer_id = m.id
             WHERE a.id IN ({$placeholders})",
            $ids
        );

        $this->view('pages.aircraft.compare', compact('aircraft', 'comparison'));
    }
}
