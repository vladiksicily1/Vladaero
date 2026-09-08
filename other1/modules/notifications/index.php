<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAuth();
$pageTitle = 'Центр уведомлений';

// Mark all as read
DB::update('notifications', ['is_read' => 1], 'user_id = :uid', ['uid' => $currentUser['id']]);

$notifs = DB::fetchAll("
    SELECT n.*, u.username as sender_username, u.display_name as sender_name, u.avatar as sender_avatar
    FROM notifications n
    LEFT JOIN users u ON n.sender_id = u.id
    WHERE n.user_id = ?
    ORDER BY n.id DESC LIMIT 50
", [$currentUser['id']]);

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 20px;">

    <div class="card">
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 4px;">🔔 Уведомления экосистемы</h2>
        <p style="color: var(--text-secondary); font-size: 13px;">Все события, реакции, переводы и системные сообщения.</p>
    </div>

    <div class="card" style="padding: 10px;">
        <?php if (empty($notifs)): ?>
            <div style="text-align: center; color: var(--text-muted); padding: 40px;">
                У вас нет новых уведомлений
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column;">
                <?php foreach ($notifs as $n): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border-color);">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--bg-input); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                <?php
                                    $icons = [
                                        'like' => '❤️',
                                        'comment' => '💬',
                                        'follow' => '👤',
                                        'coin_transfer' => '🪙',
                                        'community' => '👥',
                                        'system' => '🎉'
                                    ];
                                    echo $icons[$n['type']] ?? '🔔';
                                ?>
                            </div>
                            <div>
                                <div style="font-size: 14px; font-weight: 600; color: var(--text-primary);">
                                    <?= e($n['message']) ?>
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <?= time_ago($n['created_at']) ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($n['link'])): ?>
                            <a href="<?= e($n['link']) ?>" class="btn btn-secondary btn-sm">Перейти</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
