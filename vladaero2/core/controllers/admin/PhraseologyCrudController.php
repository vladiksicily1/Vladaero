<?php
namespace VladAero\Controllers\Admin;
use VladAero\Core\{Controller, Session, Database};

class PhraseologyCrudController extends Controller
{
    public function index() { $this->requireAdmin(); $rows = $this->db->fetchAll("SELECT * FROM {$this->db->prefix()}phraseology ORDER BY category, sort_order"); $this->view('admin.phraseology.index', compact('rows')); }
    public function create() { $this->requireAdmin(); $this->view('admin.phraseology.form', ['row' => null]); }
    public function edit(string $id) { $this->requireAdmin(); $row = $this->db->fetchOne("SELECT * FROM {$this->db->prefix()}phraseology WHERE id = :id", ['id' => (int)$id]); if (!$row) $this->abort(404); $this->view('admin.phraseology.form', compact('row')); }
    public function save() {
        $this->requireAdmin(); if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->redirect('/admin/phraseology'); $this->verifyCsrf();
        $prefix = $this->db->prefix(); $id = (int)($this->input('id') ?? 0);
        $data = ['category' => $this->input('category') ?? 'general', 'russian' => $_POST['russian'] ?? '', 'english' => $_POST['english'] ?? '', 'phonetic' => $this->input('phonetic') ?? '', 'context' => $_POST['context'] ?? '', 'sort_order' => (int)($this->input('sort_order') ?? 0)];
        if ($id) { $sets = []; $params = ['id' => $id]; foreach ($data as $k => $v) { $sets[] = "{$k} = :{$k}"; $params[$k] = $v; } $this->db->query("UPDATE {$prefix}phraseology SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        } else { $this->db->insert('phraseology', $data); }
        $this->redirect('/admin/phraseology');
    }
    public function delete(string $id) { $this->requireAdmin(); $this->db->query("DELETE FROM {$this->db->prefix()}phraseology WHERE id = :id", ['id' => (int)$id]); $this->redirect('/admin/phraseology'); }
    private function requireAdmin() { $this->requireRole('admin'); }
}
