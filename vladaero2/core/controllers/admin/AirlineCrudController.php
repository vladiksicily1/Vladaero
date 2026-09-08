<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * AirlineCrudController - CRUD авиакомпаний в админке
 */
class AirlineCrudController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $airlines = $this->db->fetchAll(
            "SELECT al.*, (SELECT COUNT(*) FROM {$prefix}fleet WHERE airline_id = al.id) as fleet_count
             FROM {$prefix}airlines al ORDER BY al.name"
        );
        $this->view('admin.airlines.index', compact('airlines'));
    }

    public function create()
    {
        $this->requireAdmin();
        $this->view('admin.airlines.form', ['airline' => null, 'title' => 'Новая авиакомпания']);
    }

    public function edit(string $id)
    {
        $this->requireAdmin();
        $airline = $this->db->fetchOne(
            "SELECT * FROM {$this->db->prefix()}airlines WHERE id = :id", ['id' => (int)$id]
        );
        if (!$airline) $this->abort(404);
        $this->view('admin.airlines.form', compact('airline'));
    }

    public function save()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/airlines'); }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $id = (int)($this->input('id') ?? 0);
        $data = [
            'name' => $this->input('name') ?? '',
            'slug' => $this->slugify($this->input('name') ?? ''),
            'iata_code' => strtoupper($this->input('iata_code') ?? ''),
            'icao_code' => strtoupper($this->input('icao_code') ?? ''),
            'country' => $this->input('country') ?? '',
            'founded' => $this->input('founded') ?? null,
            'hub_airport' => $this->input('hub_airport') ?? '',
            'alliance' => $this->input('alliance') ?? null,
            'website' => $this->input('website') ?? '',
            'logo_url' => $this->input('logo_url') ?? '',
            'description' => $_POST['description'] ?? '',
        ];

        if ($id) {
            $sets = [];
            $params = ['id' => $id];
            foreach ($data as $k => $v) { $sets[] = "{$k} = :{$k}"; $params[$k] = $v; }
            $this->db->query("UPDATE {$prefix}airlines SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        } else {
            $this->db->insert('airlines', $data);
        }
        $this->redirect('/admin/airlines');
    }

    public function delete(string $id)
    {
        $this->requireAdmin();
        $this->db->query("DELETE FROM {$this->db->prefix()}airlines WHERE id = :id", ['id' => (int)$id]);
        $this->redirect('/admin/airlines');
    }

    private function requireAdmin() { $this->requireRole('admin'); }
    private function slugify(string $text): string {
        return preg_replace('/-+/', '-', preg_replace('/[^a-z0-9-]/', '-', strtolower(trim($text))) . '');
    }
}
