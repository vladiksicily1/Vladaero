<?php
namespace VladAero\Controllers;
use VladAero\Core\{Controller, Session, Database};

/**
 * NotificationController — уведомления
 */
class NotificationController extends Controller
{
    public function index()
    {
        $user = $this->requireAuth();
        $prefix = $this->db->prefix();

        $notifications = $this->db->fetchAll(
            "SELECT * FROM {$prefix}notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50",
            ['uid' => $user['id']]
        );

        $this->view('pages.notifications.index', compact('notifications'));
    }

    public function count()
    {
        if (!Session::getAuth()) {
            header('Content-Type: application/json');
            echo json_encode(['count' => 0]);
            return;
        }
        $prefix = $this->db->prefix();
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$prefix}notifications WHERE user_id = :uid AND is_read = 0",
            ['uid' => Session::getAuth()['id']]
        );
        header('Content-Type: application/json');
        echo json_encode(['count' => (int)$count]);
    }

    public function markRead(string $id)
    {
        $user = $this->requireAuth();
        $this->db->query(
            "UPDATE {$this->db->prefix()}notifications SET is_read = 1 WHERE id = :id AND user_id = :uid",
            ['id' => (int)$id, 'uid' => $user['id']]
        );
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    public function markAllRead()
    {
        $user = $this->requireAuth();
        $this->db->query(
            "UPDATE {$this->db->prefix()}notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0",
            ['uid' => $user['id']]
        );
        $this->redirect('/notifications');
    }

    /**
     * Helper: create notification
     */
    public static function create(Database $db, int $userId, string $type, string $title, string $message, string $url = '', ?int $entityId = null, string $entityType = '')
    {
        $prefix = $db->prefix();
        $db->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'url' => $url,
        ]);
    }
}
