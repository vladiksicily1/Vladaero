<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * ProfileController - Профиль пользователя
 */
class ProfileController extends Controller
{
    public function show(string $username)
    {
        $prefix = $this->db->prefix();

        $profileUser = $this->db->fetchOne(
            "SELECT id, username, display_name, avatar_url, bio, role, created_at
             FROM {$prefix}users WHERE username = :u AND status = 'active'",
            ['u' => $username]
        );
        if (!$profileUser) $this->abort(404, 'Пользователь не найден');

        $photos = $this->db->fetchAll(
            "SELECT * FROM {$prefix}photos WHERE user_id = :uid AND status = 'approved' ORDER BY created_at DESC LIMIT 12",
            ['uid' => $profileUser['id']]
        );

        $stats = [
            'photos' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM {$prefix}photos WHERE user_id = :uid AND status = 'approved'",
                ['uid' => $profileUser['id']]
            ),
            'comments' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM {$prefix}comments WHERE user_id = :uid",
                ['uid' => $profileUser['id']]
            ),
        ];

        $this->view('pages.profile.show', compact('profileUser', 'photos', 'stats'));
    }

    public function photos(string $username)
    {
        $prefix = $this->db->prefix();
        $user = $this->db->fetchOne(
            "SELECT id, username, display_name FROM {$prefix}users WHERE username = :u",
            ['u' => $username]
        );
        if (!$user) $this->abort(404);

        $photos = $this->db->fetchAll(
            "SELECT * FROM {$prefix}photos WHERE user_id = :uid AND status = 'approved' ORDER BY created_at DESC",
            ['uid' => $user['id']]
        );

        $this->view('pages.profile.photos', compact('user', 'photos'));
    }

    public function articles(string $username)
    {
        $prefix = $this->db->prefix();
        $user = $this->db->fetchOne(
            "SELECT id, username, display_name FROM {$prefix}users WHERE username = :u",
            ['u' => $username]
        );
        if (!$user) $this->abort(404);

        $articles = $this->db->fetchAll(
            "SELECT * FROM {$prefix}news WHERE author_id = :uid AND status = 'published' ORDER BY published_at DESC",
            ['uid' => $user['id']]
        );

        $this->view('pages.profile.articles', compact('user', 'articles'));
    }

    public function logbook(string $username)
    {
        $prefix = $this->db->prefix();
        $user = $this->db->fetchOne(
            "SELECT id, username, display_name FROM {$prefix}users WHERE username = :u",
            ['u' => $username]
        );
        if (!$user) $this->abort(404);

        $this->view('pages.profile.logbook', compact('user'));
    }

    public function favorites(string $username)
    {
        $prefix = $this->db->prefix();
        $user = $this->db->fetchOne(
            "SELECT id, username, display_name FROM {$prefix}users WHERE username = :u",
            ['u' => $username]
        );
        if (!$user) $this->abort(404);

        $this->view('pages.profile.favorites', compact('user'));
    }
}
