<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * AirportCrudController - CRUD аэропортов в админке
 */
class AirportCrudController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $airports = $this->db->fetchAll("SELECT * FROM {$prefix}airports ORDER BY country, name");
        $this->view('admin.airports.index', compact('airports'));
    }

    public function create()
    {
        $this->requireAdmin();
        $this->view('admin.airports.form', ['airport' => null, 'title' => 'Новый аэропорт']);
    }

    public function edit(string $id)
    {
        $this->requireAdmin();
        $airport = $this->db->fetchOne(
            "SELECT * FROM {$this->db->prefix()}airports WHERE id = :id", ['id' => (int)$id]
        );
        if (!$airport) $this->abort(404);
        $this->view('admin.airports.form', compact('airport'));
    }

    public function save()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/airports'); }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $id = (int)($this->input('id') ?? 0);
        $data = [
            'iata_code' => strtoupper($this->input('iata_code') ?? ''),
            'icao_code' => strtoupper($this->input('icao_code') ?? ''),
            'name' => $this->input('name') ?? '',
            'slug' => strtolower($this->input('iata_code') ?: $this->input('icao_code') ?: ''),
            'city' => $this->input('city') ?? '',
            'country' => $this->input('country') ?? '',
            'latitude' => (float)($this->input('latitude') ?? 0),
            'longitude' => (float)($this->input('longitude') ?? 0),
            'elevation' => (int)($this->input('elevation') ?? 0),
            'runway_length' => (int)($this->input('runway_length') ?? 0),
            'airport_type' => $this->input('airport_type') ?? 'international',
            'timezone' => $this->input('timezone') ?? 'UTC',
            'description' => $_POST['description'] ?? '',
        ];

        if ($id) {
            $sets = [];
            $params = ['id' => $id];
            foreach ($data as $k => $v) { $sets[] = "{$k} = :{$k}"; $params[$k] = $v; }
            $this->db->query("UPDATE {$prefix}airports SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        } else {
            $this->db->insert('airports', $data);
        }
        $this->redirect('/admin/airports');
    }

    public function delete(string $id)
    {
        $this->requireAdmin();
        $this->db->query("DELETE FROM {$this->db->prefix()}airports WHERE id = :id", ['id' => (int)$id]);
        $this->redirect('/admin/airports');
    }

    private function requireAdmin() { $this->requireRole('admin'); }
}
