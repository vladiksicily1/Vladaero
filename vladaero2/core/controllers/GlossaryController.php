<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * GlossaryController — Авиационный глоссарий
 */
class GlossaryController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $letter = $this->query('letter') ?? '';
        $search = $this->query('q') ?? '';

        $where = [];
        $params = [];

        if ($letter) {
            $where[] = "g.term LIKE :letter";
            $params['letter'] = $letter . '%';
        }
        if ($search) {
            $where[] = "(g.term LIKE :q OR g.definition LIKE :q)";
            $params['q'] = "%{$search}%";
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $terms = $this->db->fetchAll(
            "SELECT g.* FROM {$prefix}glossary g {$whereStr} ORDER BY g.term ASC",
            $params
        );

        // Get available letters
        $letters = $this->db->fetchAll(
            "SELECT DISTINCT UPPER(LEFT(term, 1)) as letter FROM {$prefix}glossary ORDER BY letter"
        );

        $this->view('pages.glossary.index', compact('terms', 'letters', 'letter', 'search'));
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();
        $term = $this->db->fetchOne(
            "SELECT * FROM {$prefix}glossary WHERE slug = :slug",
            ['slug' => $slug]
        );
        if (!$term) $this->abort(404);

        $related = $this->db->fetchAll(
            "SELECT * FROM {$prefix}glossary WHERE id != :id AND category = :cat ORDER BY term LIMIT 5",
            ['id' => $term['id'], 'cat' => $term['category'] ?? '']
        );

        $this->view('pages.glossary.show', compact('term', 'related'));
    }
}
