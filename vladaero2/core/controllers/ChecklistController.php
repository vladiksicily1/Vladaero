<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * ChecklistController — Интерактивные чек-листы
 */
class ChecklistController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $checklists = $this->db->fetchAll(
            "SELECT cl.*, ac.name as aircraft_name, ac.type_code
             FROM {$prefix}checklists cl
             LEFT JOIN {$prefix}aircraft ac ON cl.aircraft_id = ac.id
             ORDER BY ac.name, cl.phase"
        );

        $aircraft = $this->db->fetchAll(
            "SELECT DISTINCT ac.id, ac.name, ac.type_code
             FROM {$prefix}checklists cl
             JOIN {$prefix}aircraft ac ON cl.aircraft_id = ac.id
             ORDER BY ac.name"
        );

        $this->view('pages.checklists.index', compact('checklists', 'aircraft'));
    }

    public function show(string $id)
    {
        $prefix = $this->db->prefix();
        $checklist = $this->db->fetchOne(
            "SELECT cl.*, ac.name as aircraft_name, ac.type_code
             FROM {$prefix}checklists cl
             LEFT JOIN {$prefix}aircraft ac ON cl.aircraft_id = ac.id
             WHERE cl.id = :id",
            ['id' => (int)$id]
        );
        if (!$checklist) $this->abort(404);

        $items = $this->db->fetchAll(
            "SELECT * FROM {$prefix}checklist_items WHERE checklist_id = :cid ORDER BY sort_order",
            ['cid' => $checklist['id']]
        );

        $this->view('pages.checklists.show', compact('checklist', 'items'));
    }
}
