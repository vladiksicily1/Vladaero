<?php
namespace VladAero\Controllers;
use VladAero\Core\{Controller, Session, Database};

/**
 * LikeController — лайки фото
 */
class LikeController extends Controller
{
    public function toggle(string $id)
    {
        $user = $this->requireAuth();
        $prefix = $this->db->prefix();
        $photoId = (int)$id;

        // Check if already liked
        $existing = $this->db->fetchOne(
            "SELECT user_id FROM {$prefix}photo_likes WHERE user_id = :uid AND photo_id = :pid",
            ['uid' => $user['id'], 'pid' => $photoId]
        );

        if ($existing) {
            $this->db->query("DELETE FROM {$prefix}photo_likes WHERE user_id = :uid AND photo_id = :pid", ['uid' => $user['id'], 'pid' => $photoId]);
            $this->db->query("UPDATE {$prefix}photos SET likes_count = GREATEST(likes_count - 1, 0) WHERE id = :id", ['id' => $photoId]);
            $liked = false;
        } else {
            $this->db->insert('photo_likes', ['user_id' => $user['id'], 'photo_id' => $photoId]);
            $this->db->query("UPDATE {$prefix}photos SET likes_count = likes_count + 1 WHERE id = :id", ['id' => $photoId]);
            $liked = true;
        }

        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}photo_likes WHERE photo_id = :pid", ['pid' => $photoId]);

        header('Content-Type: application/json');
        echo json_encode(['liked' => $liked, 'count' => $count]);
    }
}
