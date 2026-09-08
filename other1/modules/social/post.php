<?php
if (!defined('VLADINC_INIT')) exit;

$postId = (int)($postId ?? ($_GET['id'] ?? 0));
$currentUser = Auth::user();

if (!$postId) {
    redirect('feed');
}

// Increment view count
DB::query("UPDATE posts SET views_count = views_count + 1 WHERE id = ?", [$postId]);

$post = DB::fetch("
    SELECT p.*, u.username, u.display_name, u.avatar, u.role, u.level,
           c.name as community_name, c.slug as community_slug
    FROM posts p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN communities c ON p.community_id = c.id
    WHERE p.id = ? LIMIT 1
", [$postId]);

if (!$post) {
    flash_set('error', 'Публикация не найдена');
    redirect('feed');
}

$pageTitle = e(mb_strimwidth($post['content'], 0, 60, '...')) . ' — ' . e($post['display_name']);

// Check liked and bookmarked status
$isLiked = false;
$isBookmarked = false;
if ($currentUser) {
    $isLiked = (bool)DB::fetch("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?", [$postId, $currentUser['id']]);
    $isBookmarked = (bool)DB::fetch("SELECT id FROM bookmarks WHERE post_id = ? AND user_id = ?", [$postId, $currentUser['id']]);
}

// Fetch comments
$comments = DB::fetchAll("
    SELECT c.*, u.username, u.display_name, u.avatar, u.level 
    FROM post_comments c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.post_id = ? 
    ORDER BY c.id ASC
", [$postId]);

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 20px;">

    <div style="margin-bottom: 4px;">
        <a href="<?= url('feed') ?>" class="btn btn-secondary btn-sm">← Назад в ленту</a>
    </div>

    <!-- MAIN POST CARD -->
    <article class="card post-card" id="post-<?= $post['id'] ?>">
        
        <!-- Header -->
        <div class="post-header">
            <div class="post-author-wrap">
                <a href="<?= url('profile/@' . $post['username']) ?>">
                    <img src="<?= e(url($post['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" class="post-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($post['display_name']) ?>&background=3b82f6&color=fff'">
                </a>
                <div class="post-author-meta">
                    <div class="post-author-name">
                        <a href="<?= url('profile/@' . $post['username']) ?>"><?= e($post['display_name']) ?></a>
                        <?php if ($post['role'] === 'admin' || $post['role'] === 'verified'): ?>
                            <span class="verified-badge">✓</span>
                        <?php endif; ?>
                        <span class="level-badge">LVL <?= $post['level'] ?></span>
                    </div>
                    <span class="post-time">
                        @<?= e($post['username']) ?> &bull; <?= date('d.m.Y в H:i', strtotime($post['created_at'])) ?>
                        <?php if (!empty($post['community_name'])): ?>
                            &bull; в <a href="<?= url('c/' . $post['community_slug']) ?>" style="color: var(--accent-primary); font-weight: 600;"><?= e($post['community_name']) ?></a>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <?php if ($post['is_pinned']): ?>
                <span style="font-size: 12px; color: var(--accent-primary); font-weight: 700;">📌 Закреплено</span>
            <?php endif; ?>
        </div>

        <!-- Content -->
        <div class="post-content" style="font-size: 17px; margin: 10px 0;">
            <?= parse_content($post['content']) ?>
        </div>

        <!-- Media -->
        <?php if (!empty($post['media_url'])): ?>
            <div class="post-media-wrap" style="max-height: 600px;">
                <img src="<?= e(url($post['media_url'])) ?>" alt="Media" style="width: 100%; border-radius: 12px;">
            </div>
        <?php endif; ?>

        <!-- Footer Bar with Multi-reactions & Tip -->
        <div class="post-footer" style="padding-top: 16px; margin-top: 10px;">
            <div class="post-reactions">
                <button type="button" class="react-btn <?= $isLiked ? 'liked' : '' ?>" data-post-id="<?= $post['id'] ?>">
                    <span><?= $isLiked ? '❤️' : '🤍' ?></span>
                    <span class="like-count"><?= $post['likes_count'] ?></span>
                </button>

                <button type="button" class="react-btn" onclick="toggleBookmark(<?= $post['id'] ?>, this)" title="Добавить в закладки">
                    <span><?= $isBookmarked ? '🔖' : '📑' ?></span>
                    <span id="bm-text"><?= $isBookmarked ? 'В закладках' : 'Сохранить' ?></span>
                </button>

                <?php if ($currentUser && $currentUser['id'] !== $post['user_id']): ?>
                    <button type="button" class="react-btn" style="color: #fbbf24;" onclick="quickTipAuthor(<?= $post['user_id'] ?>, '<?= e($post['username']) ?>')" title="Отправить чаевые автору">
                        <span>🪙 Чаевые</span>
                    </button>
                <?php endif; ?>

                <button type="button" class="react-btn" onclick="navigator.clipboard.writeText(window.location.href); showToast('Ссылка скопирована в буфер!', 'success');">
                    <span>🔗 Поделиться</span>
                </button>
            </div>

            <div style="font-size: 13px; color: var(--text-muted);">
                👁️ <?= $post['views_count'] ?> просмотров
            </div>
        </div>

    </article>

    <!-- COMMENTS SECTION -->
    <div class="card">
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px;">
            💬 Комментарии (<span class="comments-count-<?= $post['id'] ?>"><?= count($comments) ?></span>)
        </h3>

        <!-- Comment Composer Form -->
        <?php if ($currentUser): ?>
            <form class="comment-form" data-post-id="<?= $post['id'] ?>" style="display: flex; gap: 10px; align-items: center; margin-bottom: 24px;" enctype="multipart/form-data">
                <input type="text" name="content" class="form-control" placeholder="Напишите ваш комментарий к публикации..." style="flex: 1;">
                <label style="cursor: pointer; margin: 0; padding: 10px 14px; border-radius: 8px; background: var(--bg-input); border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; font-size: 18px;" title="Прикрепить фото к комментарию">
                    📷
                    <input type="file" name="comment_media" accept="image/*" style="display: none;" onchange="if(this.files[0]) this.parentElement.style.borderColor='var(--accent-primary)';">
                </label>
                <button type="submit" class="btn btn-primary">Отправить</button>
            </form>
        <?php else: ?>
            <div style="padding: 14px; background: var(--bg-input); border-radius: 8px; font-size: 13px; margin-bottom: 20px; text-align: center;">
                <a href="<?= url('login') ?>" style="color: var(--accent-primary); font-weight: 700;">Войдите через Vlad ID</a>, чтобы оставлять комментарии.
            </div>
        <?php endif; ?>

        <!-- Comments Stream -->
        <div id="comments-list-<?= $post['id'] ?>" style="display: flex; flex-direction: column; gap: 14px;">
            <?php if (empty($comments)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 30px;">
                    Будьте первым, кто оставит комментарий!
                </div>
            <?php else: ?>
                <?php foreach ($comments as $c): ?>
                    <div style="display: flex; gap: 12px; padding: 12px 16px; background: var(--bg-input); border-radius: var(--radius-md);">
                        <a href="<?= url('profile/@' . $c['username']) ?>">
                            <img src="<?= e(url($c['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($c['display_name']) ?>&background=3b82f6&color=fff'">
                        </a>
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <div style="font-weight: 700; font-size: 14px;">
                                    <a href="<?= url('profile/@' . $c['username']) ?>"><?= e($c['display_name']) ?></a>
                                    <span style="font-size: 11px; color: var(--text-muted); font-weight: normal; margin-left: 6px;">@<?= e($c['username']) ?></span>
                                    <span class="level-badge" style="margin-left: 6px;">LVL <?= $c['level'] ?></span>
                                </div>
                                <span style="font-size: 11px; color: var(--text-muted);"><?= time_ago($c['created_at']) ?></span>
                            </div>
                            <div style="font-size: 14px; line-height: 1.5; color: var(--text-primary);">
                                <?= parse_content($c['content']) ?>
                            </div>
                            <?php if (!empty($c['media_url'])): ?>
                                <div style="margin-top: 8px;">
                                    <a href="<?= e(url($c['media_url'])) ?>" target="_blank">
                                        <img src="<?= e(url($c['media_url'])) ?>" style="max-width: 300px; max-height: 220px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border-color);" loading="lazy">
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

</div>

<script>
async function toggleBookmark(postId, btn) {
    try {
        const res = await fetch(baseUrl + '/api/index.php?action=toggle_bookmark', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId })
        });
        const data = await res.json();
        if (data.success) {
            btn.querySelector('span:first-child').innerText = data.is_bookmarked ? '🔖' : '📑';
            document.getElementById('bm-text').innerText = data.is_bookmarked ? 'В закладках' : 'Сохранить';
            showToast(data.message, 'success');
        } else {
            showToast(data.error, 'error');
        }
    } catch(e) {
        showToast('Ошибка сети', 'error');
    }
}

async function quickTipAuthor(userId, username) {
    const amount = prompt("Сколько VladCoins отправить автору @" + username + "?", "10");
    if (!amount || isNaN(amount) || amount <= 0) return;

    try {
        const res = await fetch(baseUrl + '/api/index.php?action=transfer_coins', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ to_user: username, amount: parseInt(amount, 10), note: 'Чаевые за публикацию' })
        });
        const data = await res.json();
        if (data.success) {
            showToast('🪙 Вы отправили ' + amount + ' VladCoins автору!', 'success');
        } else {
            showToast(data.error, 'error');
        }
    } catch(e) {
        showToast('Сетевой сбой', 'error');
    }
}
</script>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
