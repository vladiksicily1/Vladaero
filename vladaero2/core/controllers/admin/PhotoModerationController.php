<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * PhotoModerationController - Модерация фото
 */
class PhotoModerationController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();
        $status = $this->query('status') ?? 'pending';
        $valid = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $valid)) $status = 'pending';

        $photos = $this->db->fetchAll(
            "SELECT p.*, u.username,
                    ac.name as aircraft_name,
                    ap.icao_code as airport_icao, ap.name as airport_name
             FROM {$prefix}photos p
             LEFT JOIN {$prefix}users u ON p.user_id = u.id
             LEFT JOIN {$prefix}aircraft_types ac ON p.aircraft_id = ac.id
             LEFT JOIN {$prefix}airports ap ON p.airport_id = ap.id
             WHERE p.status = :st ORDER BY p.created_at DESC",
            ['st' => $status]
        );

        $counts = [
            'pending' => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}photos WHERE status = 'pending'"),
            'approved' => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}photos WHERE status = 'approved'"),
            'rejected' => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}photos WHERE status = 'rejected'"),
        ];

        $this->view('admin.photos.index', compact('photos', 'status', 'counts'));
    }

    public function approve(string $id)
    {
        $this->requireAdmin();
        $this->db->query(
            "UPDATE {$this->db->prefix()}photos SET status = 'approved' WHERE id = :id",
            ['id' => (int)$id]
        );
        $this->redirect('/admin/photos?status=pending');
    }

    public function reject(string $id)
    {
        $this->requireAdmin();
        $this->db->query(
            "UPDATE {$this->db->prefix()}photos SET status = 'rejected' WHERE id = :id",
            ['id' => (int)$id]
        );
        $this->redirect('/admin/photos?status=pending');
    }

    public function bulk()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/photos'); }
        $this->verifyCsrf();

        $action = $this->input('bulk_action') ?? '';
        $ids = $_POST['ids'] ?? [];
        if (empty($ids) || !in_array($action, ['approve', 'reject', 'delete'])) {
            $this->redirect('/admin/photos');
        }

        $prefix = $this->db->prefix();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'delete') {
            $this->db->query("DELETE FROM {$prefix}photos WHERE id IN ({$placeholders})", $ids);
        } else {
            $this->db->query(
                "UPDATE {$prefix}photos SET status = ? WHERE id IN ({$placeholders})",
                array_merge([$action], $ids)
            );
        }
        $this->redirect('/admin/photos');
    }

    private function requireAdmin() { $this->requireRole('admin'); }
}
