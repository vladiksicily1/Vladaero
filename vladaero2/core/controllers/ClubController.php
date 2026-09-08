<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * ClubController — Тематические клубы и сообщества
 */
class ClubController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $search = $this->query('q') ?? '';

        $where = '';
        $params = [];
        if ($search) {
            $where = "WHERE c.name LIKE :q OR c.description LIKE :q";
            $params['q'] = "%{$search}%";
        }

        $clubs = $this->db->fetchAll(
            "SELECT c.*, u.username as founder_name,
                    (SELECT COUNT(*) FROM {$prefix}club_members WHERE club_id = c.id) as member_count
             FROM {$prefix}clubs c
             LEFT JOIN {$prefix}users u ON c.founder_id = u.id
             {$where}
             ORDER BY member_count DESC",
            $params
        );

        $this->view('pages.clubs.index', compact('clubs', 'search'));
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();
        $club = $this->db->fetchOne(
            "SELECT c.*, u.username as founder_name
             FROM {$prefix}clubs c
             LEFT JOIN {$prefix}users u ON c.founder_id = u.id
             WHERE c.slug = :slug",
            ['slug' => $slug]
        );
        if (!$club) $this->abort(404);

        $members = $this->db->fetchAll(
            "SELECT u.username, u.display_name, u.avatar_url, cm.joined_at
             FROM {$prefix}club_members cm
             JOIN {$prefix}users u ON cm.user_id = u.id
             WHERE cm.club_id = :cid
             ORDER BY cm.joined_at ASC",
            ['cid' => $club['id']]
        );

        $this->view('pages.clubs.show', compact('club', 'members'));
    }
}
