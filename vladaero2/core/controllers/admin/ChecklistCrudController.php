<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * ChecklistCrudController — CRUD чек-листов в админке
 */
class ChecklistCrudController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $checklists = $this->db->fetchAll(
            "SELECT cl.*, ac.name as aircraft_name
             FROM {$prefix}checklists cl
             LEFT JOIN {$prefix}aircraft ac ON cl.aircraft_id = ac.id
             ORDER BY cl.phase, cl.title"
        );
        $this->view('admin.checklists.index', compact('checklists'));
    }

    public function create()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $aircraft = $this->db->fetchAll("SELECT id, name, type_code FROM {$prefix}aircraft ORDER BY name");
        $this->view('admin.checklists.form', ['checklist' => null, 'aircraft' => $aircraft]);
    }

    public function edit(string $id)
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $checklist = $this->db->fetchOne("SELECT * FROM {$prefix}checklists WHERE id = :id", ['id' => (int)$id]);
        if (!$checklist) $this->abort(404);
        $aircraft = $this->db->fetchAll("SELECT id, name, type_code FROM {$prefix}aircraft ORDER BY name");
        $items = $this->db->fetchAll(
            "SELECT * FROM {$prefix}checklist_items WHERE checklist_id = :cid ORDER BY sort_order",
            ['cid' => $checklist['id']]
        );
        $this->view('admin.checklists.form', compact('checklist', 'aircraft', 'items'));
    }

    public function save()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/checklists'); }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $id = (int)($this->input('id') ?? 0);
        $data = [
            'title' => $this->input('title') ?? '',
            'aircraft_id' => (int)($this->input('aircraft_id') ?? 0) ?: null,
            'phase' => $this->input('phase') ?? 'pre_flight',
            'voice_enabled' => (int)($this->input('voice_enabled') ?? 0),
        ];

        if ($id) {
            $sets = []; $params = ['id' => $id];
            foreach ($data as $k => $v) { $sets[] = "{$k} = :{$k}"; $params[$k] = $v; }
            $this->db->query("UPDATE {$prefix}checklists SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        } else {
            $data['is_published'] = 1;
            $id = $this->db->insert('checklists', $data);
        }

        // Save items
        $this->db->query("DELETE FROM {$prefix}checklist_items WHERE checklist_id = :cid", ['cid' => $id]);
        $actions = $_POST['item_action'] ?? [];
        $items = $_POST['item_item'] ?? [];
        $settings = $_POST['item_setting'] ?? [];
        foreach ($actions as $i => $action) {
            if (!trim($action)) continue;
            $this->db->insert('checklist_items', [
                'checklist_id' => $id,
                'action' => trim($action),
                'item' => trim($items[$i] ?? ''),
                'setting' => trim($settings[$i] ?? ''),
                'sort_order' => $i,
            ]);
        }

        $this->redirect('/admin/checklists');
    }

    public function delete(string $id)
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $this->db->query("DELETE FROM {$prefix}checklist_items WHERE checklist_id = :id", ['id' => (int)$id]);
        $this->db->query("DELETE FROM {$prefix}checklists WHERE id = :id", ['id' => (int)$id]);
        $this->redirect('/admin/checklists');
    }

    private function requireAdmin() { $this->requireRole('admin'); }
}
