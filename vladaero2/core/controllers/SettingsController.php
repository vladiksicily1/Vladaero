<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * SettingsController - Настройки профиля
 */
class SettingsController extends Controller
{
    public function index()
    {
        $user = $this->requireAuth();
        $prefix = $this->db->prefix();

        $fullUser = $this->db->fetchOne(
            "SELECT * FROM {$prefix}users WHERE id = :id", ['id' => $user['id']]
        );

        $this->view('pages.settings.index', ['user' => $fullUser]);
    }

    public function update()
    {
        $user = $this->requireAuth();
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $displayName = $this->input('display_name') ?? '';
        $email = $this->input('email') ?? '';
        $bio = $this->input('bio') ?? '';

        $updates = [];
        $params = ['id' => $user['id']];

        if ($displayName) { $updates[] = 'display_name = :dn'; $params['dn'] = $displayName; }
        if ($email) { $updates[] = 'email = :email'; $params['email'] = $email; }
        $telegramUsername = trim($this->input('telegram_username') ?? '');
        $telegramId = trim($this->input('telegram_id') ?? '');
        if ($telegramUsername !== '') {
            $telegramUsername = ltrim($telegramUsername, '@');
            $updates[] = 'telegram_username = :tu';
            $params['tu'] = $telegramUsername;
        }
        if ($telegramId !== '') {
            $updates[] = 'telegram_id = :ti, telegram_linked = 1';
            $params['ti'] = (int)$telegramId;
        }

        // 2FA toggle
        $tfaEnabled = !empty($_POST['tfa_enabled']) ? 1 : 0;
        $updates[] = 'tfa_enabled = :tfa';
        $params['tfa'] = $tfaEnabled;

        if (!empty($updates)) {
            $this->db->query(
                "UPDATE {$prefix}users SET " . implode(', ', $updates) . " WHERE id = :id", $params
            );
        }

        // Update current auth session
        $freshUser = $this->db->fetchOne("SELECT * FROM {$prefix}users WHERE id = :id", ['id' => $user['id']]);
        if ($freshUser) {
            Session::setAuth($freshUser);
        }

        Session::setFlash('success', 'Настройки сохранены');
        $this->redirect('/settings');
    }

    public function updatePrivacy()
    {
        $user = $this->requireAuth();
        $this->verifyCsrf();
        $prefix = $this->db->prefix();

        $this->db->query(
            "UPDATE {$prefix}users SET show_email = :se, show_profile = :sp WHERE id = :id",
            [
                'se' => (int)($_POST['show_email'] ?? 0),
                'sp' => (int)($_POST['show_profile'] ?? 1),
                'id' => $user['id'],
            ]
        );

        Session::setFlash('success', 'Настройки приватности сохранены');
        $this->redirect('/settings');
    }

    public function exportData()
    {
        $user = $this->requireAuth();
        $prefix = $this->db->prefix();

        $myPhotos = $this->db->fetchAll(
            "SELECT id, title, description, file_path, created_at FROM {$prefix}photos WHERE user_id = :uid",
            ['uid' => $user['id']]
        );
        $myArticles = $this->db->fetchAll(
            "SELECT id, title, slug, content, created_at FROM {$prefix}news WHERE author_id = :uid",
            ['uid' => $user['id']]
        );
        $myComments = $this->db->fetchAll(
            "SELECT id, content, created_at FROM {$prefix}comments WHERE user_id = :uid",
            ['uid' => $user['id']]
        );

        $export = [
            'user' => [
                'username' => $user['username'],
                'email' => $user['email'],
                'display_name' => $user['display_name'] ?? '',
                'bio' => $user['bio'] ?? '',
                'role' => $user['role'],
                'created_at' => $user['created_at'],
            ],
            'photos' => $myPhotos,
            'articles' => $myArticles,
            'comments' => $myComments,
            'exported_at' => date('Y-m-d H:i:s'),
        ];

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="vladaero-export-' . date('Y-m-d') . '.json"');
        echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public function deleteAccount()
    {
        $user = $this->requireAuth();
        $this->verifyCsrf();
        $prefix = $this->db->prefix();

        $this->db->query(
            "UPDATE {$prefix}users SET status = 'deleted', username = CONCAT('deleted_', id), email = '' WHERE id = :id",
            ['id' => $user['id']]
        );
        Session::logout();
        Session::setFlash('success', 'Аккаунт удалён');
        $this->redirect('/');
    }
}
