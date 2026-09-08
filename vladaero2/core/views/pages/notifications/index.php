<?php /** @var array $notifications */ ?>
<section class="section"><div class="container">
    <div class="section__header">
        <h1>🔔 Уведомления</h1>
        <a href="<?= url('/notifications/read-all') ?>" class="btn btn--outline btn--sm">✓ Прочитать все</a>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="empty-state"><p>Нет уведомлений</p></div>
    <?php else: ?>
        <div class="notifications-list">
            <?php foreach ($notifications as $n): ?>
                <div class="notification <?= $n['is_read'] ? '' : 'notification--unread' ?>" data-id="<?= $n['id'] ?>">
                    <div class="notification__icon">
                        <?= match($n['type'] ?? '') {
                            'comment' => '💬',
                            'like' => '❤️',
                            'follow' => '👤',
                            'mod_status' => '📸',
                            'achievement' => '🏆',
                            default => '🔔',
                        } ?>
                    </div>
                    <div class="notification__body">
                        <strong><?= e($n['title'] ?? '') ?></strong>
                        <p><?= e($n['message'] ?? '') ?></p>
                        <?php if (!empty($n['url'])): ?>
                            <a href="<?= url(e($n['url'])) ?>" class="notification__link">Перейти →</a>
                        <?php endif; ?>
                        <time class="text-muted"><?= timeAgo($n['created_at']) ?></time>
                    </div>
                    <?php if (!$n['is_read']): ?>
                        <button class="notification__read" onclick="markRead(<?= $n['id'] ?>)">✓</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div></section>

<script>
async function markRead(id) {
    await fetch('/notifications/' + id + '/read');
    const el = document.querySelector('[data-id="' + id + '"]');
    if (el) el.classList.remove('notification--unread');
}
</script>
