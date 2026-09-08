<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * DashboardController - Админ-панель дашборд
 */
class DashboardController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $prefix = $this->db->prefix();

        $stats = [
            'users'          => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}users"),
            'aircraft'       => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}aircraft"),
            'airports'       => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}airports"),
            'airlines'       => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}airlines"),
            'photos'         => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}photos"),
            'pending_photos' => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}photos WHERE status = 'pending'"),
            'news'           => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}news"),
            'comments'       => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}comments"),
            'quizzes'        => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}quizzes"),
            'events'         => $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}events"),
        ];

        $recentPhotos = $this->db->fetchAll(
            "SELECT p.*, u.username FROM {$prefix}photos p
             LEFT JOIN {$prefix}users u ON p.user_id = u.id
             ORDER BY p.created_at DESC LIMIT 5"
        );

        $recentUsers = $this->db->fetchAll(
            "SELECT * FROM {$prefix}users ORDER BY created_at DESC LIMIT 5"
        );

        $this->view('admin.dashboard', compact('stats', 'recentPhotos', 'recentUsers'));
    }

    private function requireAdmin()
    {
        $this->requireRole('admin', 'moderator');
    }
}
