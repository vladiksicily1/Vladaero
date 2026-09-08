<?php
namespace VladAero\Controllers;
use VladAero\Core\{Controller, Session, Database};

/**
 * CommentController — обработка комментариев
 */
class CommentController extends Controller
{
    public function store()
    {
        $user = $this->requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); return; }
        $this->verifyCsrf();

        $prefix = $this->db->prefix();
        $entityType = $this->input('entity_type') ?? '';
        $entityId = (int)($this->input('entity_id') ?? 0);
        $content = trim($_POST['content'] ?? '');

        if (empty($content) || $entityId < 1) {
            Session::setFlash('error', 'Пустой комментарий');
            $this->redirect($_SERVER['HTTP_REFERER'] ?? '/');
        }

        $this->db->insert('comments', [
            'user_id' => $user['id'],
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'content' => $content,
        ]);

        // Update count
        if ($entityType === 'news' || $entityType === 'article') {
            $this->db->query("UPDATE {$prefix}news SET comments_count = comments_count + 1 WHERE id = :id", ['id' => $entityId]);
        } elseif ($entityType === 'photo') {
            $this->db->query("UPDATE {$prefix}photos SET comments_count = comments_count + 1 WHERE id = :id", ['id' => $entityId]);
        }

        // Referrer redirect
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        $this->redirect($referer);
    }

    public function delete(string $id)
    {
        $user = $this->requireAuth();
        $prefix = $this->db->prefix();
        $comment = $this->db->fetchOne("SELECT * FROM {$prefix}comments WHERE id = :id", ['id' => (int)$id]);

        if (!$comment) { $this->abort(404); }
        if ($comment['user_id'] != $user['id'] && $user['role'] !== 'admin') {
            $this->abort(403);
        }

        $this->db->query("DELETE FROM {$prefix}comments WHERE id = :id", ['id' => (int)$id]);

        // Decrement count
        if ($comment['entity_type'] === 'news') {
            $this->db->query("UPDATE {$prefix}news SET comments_count = GREATEST(comments_count - 1, 0) WHERE id = :id", ['id' => $comment['entity_id']]);
        }

        $this->redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
}
