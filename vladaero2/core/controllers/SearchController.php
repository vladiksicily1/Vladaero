<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * SearchController — Глобальный поиск (FULLTEXT MySQL)
 */
class SearchController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $q = trim($this->query('q') ?? '');

        if (mb_strlen($q) < 2) {
            $this->view('pages.search.index', ['query' => $q, 'results' => []]);
            return;
        }

        $results = [];
        $lq = "%{$q}%";

        // Aircraft — FULLTEXT + LIKE fallback
        $aircraft = $this->db->fetchAll(
            "SELECT *, 'aircraft' as type, name as title
             FROM {$prefix}aircraft
             WHERE name LIKE :lq OR type_code LIKE :lq OR manufacturer LIKE :lq
             LIMIT 5",
            ['lq' => $lq]
        );
        foreach ($aircraft as &$r) { $r['url'] = '/aircraft/' . ($r['slug'] ?? $r['type_code'] ?? ''); }
        $results = array_merge($results, $aircraft);

        // Airports — FULLTEXT + LIKE fallback
        $airports = $this->db->fetchAll(
            "SELECT *, 'airport' as type, name as title
             FROM {$prefix}airports
             WHERE name LIKE :lq OR iata_code LIKE :lq OR icao_code LIKE :lq OR city LIKE :lq
             LIMIT 5",
            ['lq' => $lq]
        );
        foreach ($airports as &$r) { $r['url'] = '/airports/' . ($r['icao_code'] ?? ''); }
        $results = array_merge($results, $airports);

        // Airlines — FULLTEXT + LIKE fallback
        $airlines = $this->db->fetchAll(
            "SELECT *, 'airline' as type, name as title
             FROM {$prefix}airlines
             WHERE name LIKE :lq OR iata_code LIKE :lq OR icao_code LIKE :lq
             LIMIT 5",
            ['lq' => $lq]
        );
        foreach ($airlines as &$r) { $r['url'] = '/airlines/' . ($r['slug'] ?? ''); }
        $results = array_merge($results, $airlines);

        // News — FULLTEXT + LIKE fallback
        $articles = $this->db->fetchAll(
            "SELECT n.*, 'news' as type, n.title
             FROM {$prefix}news n
             WHERE (n.title LIKE :lq OR n.content LIKE :lq) AND n.status = 'published'
             ORDER BY n.published_at DESC
             LIMIT 5",
            ['lq' => $lq]
        );
        foreach ($articles as &$r) { $r['url'] = '/news/' . ($r['slug'] ?? ''); }
        $results = array_merge($results, $articles);

        // Glossary
        $terms = $this->db->fetchAll(
            "SELECT *, 'glossary' as type, term as title
             FROM {$prefix}glossary
             WHERE term LIKE :lq OR definition LIKE :lq
             LIMIT 5",
            ['lq' => $lq]
        );
        foreach ($terms as &$r) { $r['url'] = '/glossary/' . ($r['slug'] ?? ''); }
        $results = array_merge($results, $terms);

        $this->view('pages.search.index', ['query' => $q, 'results' => $results, 'title' => "Поиск: {$q}"]);
    }
}
