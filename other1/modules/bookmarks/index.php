<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAuth();
$pageTitle = 'Мои закладки';

$bookmarks = DB::fetchAll("
    SELECT b.created_at as saved_at, p.*, u.username, u.display_name, u.avatar, u.level
    FROM bookmarks b
    JOIN posts p ON b.post_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE b.user_id = ?
    ORDER BY b.id DESC
", [$currentUser['id']]);

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 20px;">

    <div class="card" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 4px;">📑 Закладки</h2>
            <p style="color: var(--text-secondary); font-size: 13px;">Сохраненные публикации для быстрого доступа.</p>
        </div>
        <span class="level-badge" style="font-size: 13px; padding: 4px 12px;"><?= count($bookmarks) ?> записей</span>
    </div>

    <?php if (empty($bookmarks)): ?>
        <div class="card" style="text-align: center; color: var(--text-muted); padding: 50px;">
            У вас пока нет закладок. Нажмите «📑 Сохранить» на любом посте в ленте.
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <?php foreach ($bookmarks as $post): ?>
                <div class="card post-card" id="post-<?= $post['id'] ?>">
                    <div class="post-header">
                        <div class="post-author-wrap">
                            <a href="<?= url('profile/@' . $post['username']) ?>">
                                <img src="<?= e(url($post['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" class="post-avatar">
                            </a>
                            <div class="post-author-meta">
                                <div class="post-author-name">
                                    <a href="<?= url('profile/@' . $post['username']) ?>"><?= e($post['display_name']) ?></a>
                                    <span class="level-badge">LVL <?= $post['level'] ?></span>
                                </div>
                                <span class="post-time">Сохранено <?= time_ago($post['saved_at']) ?></span>
                            </div>
                        </div>

                        <div>
                            <a href="<?= url('post/' . $post['id']) ?>" class="btn btn-secondary btn-sm">Открыть пост &rarr;</a>
                        </div>
                    </div>

                    <div class="post-content">
                        <?= parse_content($post['content']) ?>
                    </div>

                    <?php if (!empty($post['media_url'])): ?>
                        <div class="post-media-wrap">
                            <img src="<?= e(url($post['media_url'])) ?>" alt="Media">
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
