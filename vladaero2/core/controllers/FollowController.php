<?php
namespace VladAero\Controllers;
use VladAero\Core\{Controller, Session, Database};

/**
 * FollowController — подписки на пользователей
 */
class FollowController extends Controller
{
    public function toggle(string $username)
    {
        $user = $this->requireAuth();
        $prefix = $this->db->prefix();

        $target = $this->db->fetchOne("SELECT id FROM {$prefix}users WHERE username = :u", ['u' => $username]);
        if (!$target || $target['id'] == $user['id']) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Нельзя подписаться']);
            return;
        }

        $existing = $this->db->fetchOne(
            "SELECT follower_id FROM {$prefix}user_follows WHERE follower_id = :fid AND following_id = :tid",
            ['fid' => $user['id'], 'tid' => $target['id']]
        );

        if ($existing) {
            $this->db->query("DELETE FROM {$prefix}user_follows WHERE follower_id = :fid AND following_id = :tid", ['fid' => $user['id'], 'tid' => $target['id']]);
            $following = false;
        } else {
            $this->db->insert('user_follows', ['follower_id' => $user['id'], 'following_id' => $target['id']]);
            $following = true;
        }

        header('Content-Type: application/json');
        echo json_encode(['following' => $following]);
    }
}
