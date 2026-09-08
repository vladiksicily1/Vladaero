<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * EventController - Календарь авиационных событий
 */
class EventController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $month = (int)($this->query('month') ?? date('m'));
        $year = (int)($this->query('year') ?? date('Y'));

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $events = $this->db->fetchAll(
            "SELECT e.*, u.username as author_name, ap.name as airport_name, ap.icao_code as airport_icao
             FROM {$prefix}events e
             LEFT JOIN {$prefix}users u ON e.author_id = u.id
             LEFT JOIN {$prefix}airports ap ON e.airport_id = ap.id
             WHERE e.start_date <= :end AND e.end_date >= :start
             ORDER BY e.start_date ASC",
            ['end' => $end, 'start' => $start]
        );

        $this->view('pages.events.index', compact('events', 'month', 'year'));
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();

        $event = $this->db->fetchOne(
            "SELECT e.*, u.username as author_name,
                    ap.name as airport_name, ap.icao_code as airport_icao,
                    ap.city as airport_city, ap.country as airport_country
             FROM {$prefix}events e
             LEFT JOIN {$prefix}users u ON e.author_id = u.id
             LEFT JOIN {$prefix}airports ap ON e.airport_id = ap.id
             WHERE e.slug = :slug",
            ['slug' => $slug]
        );
        if (!$event) $this->abort(404, 'Событие не найдено');

        $this->view('pages.events.show', compact('event'));
    }
}
