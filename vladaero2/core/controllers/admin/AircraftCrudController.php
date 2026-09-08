<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * AircraftCrudController - CRUD самолётов в админке
 */
class AircraftCrudController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();

        $aircraft = $this->db->fetchAll(
            "SELECT ac.*,
                    (SELECT COUNT(*) FROM {$prefix}photos WHERE aircraft_id = ac.id) as photo_count
             FROM {$prefix}aircraft ac ORDER BY ac.manufacturer, ac.name"
        );

        $this->view('admin.aircraft.index', compact('aircraft'));
    }

    public function create()
    {
        $this->requireAdmin();
        $this->view('admin.aircraft.form', ['aircraft' => null, 'title' => 'Новый самолёт']);
    }

    public function edit(string $id)
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $aircraft = $this->db->fetchOne(
            "SELECT * FROM {$prefix}aircraft WHERE id = :id", ['id' => (int)$id]
        );
        if (!$aircraft) $this->abort(404);
        $this->view('admin.aircraft.form', compact('aircraft'));
    }

    public function save()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/aircraft'); }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $id = (int)($this->input('id') ?? 0);
        $data = [
            'name' => $this->input('name') ?? '',
            'slug' => $this->slugify($this->input('name') ?? ''),
            'type_code' => strtoupper($this->input('type_code') ?? ''),
            'manufacturer' => $this->input('manufacturer') ?? '',
            'category' => $this->input('category') ?? 'airliner',
            'engine_type' => $this->input('engine_type') ?? 'jet',
            'engine_count' => (int)($this->input('engine_count') ?? 2),
            'max_speed_knots' => (int)($this->input('max_speed') ?? 0),
            'range_km' => (int)($this->input('range_km') ?? 0),
            'passengers' => (int)($this->input('passengers') ?? 0),
            'first_flight' => $this->input('first_flight') ?? null,
            'description' => $_POST['description'] ?? '',
            'wikipedia_url' => $this->input('wikipedia_url') ?? '',
            'is_published' => 1,
        ];

        if ($id) {
            $sets = [];
            $params = ['id' => $id];
            foreach ($data as $k => $v) { $sets[] = "{$k} = :{$k}"; $params[$k] = $v; }
            $this->db->query("UPDATE {$prefix}aircraft SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        } else {
            $this->db->insert('aircraft', $data);
        }

        $this->redirect('/admin/aircraft');
    }

    public function delete(string $id)
    {
        $this->requireAdmin();
        $this->db->query("DELETE FROM {$this->db->prefix()}aircraft WHERE id = :id", ['id' => (int)$id]);
        $this->redirect('/admin/aircraft');
    }

    private function requireAdmin()
    {
        $this->requireRole('admin');
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        return preg_replace('/-+/', '-', trim($text, '-'));
    }
}
