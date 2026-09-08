<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::user();
$username = ltrim($profileUsername ?? '', '@');

if (empty($username)) {
    redirect('feed');
}

$profile = DB::fetch("SELECT * FROM users WHERE username = ? LIMIT 1", [$username]);
if (!$profile) {
    flash_set('error', 'Пользователь @' . e($username) . ' не найден в экосистеме');
    redirect('feed');
}

$pageTitle = $profile['display_name'] . ' (@' . $profile['username'] . ')';

// Check follow status
$isFollowing = false;
if ($currentUser && $currentUser['id'] !== $profile['id']) {
    $check = DB::fetch("SELECT id FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1", [$currentUser['id'], $profile['id']]);
    $isFollowing = (bool)$check;
}

// User stats
$postsCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM posts WHERE user_id = ?", [$profile['id']]);
$followersCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM follows WHERE following_id = ?", [$profile['id']]);
$followingCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM follows WHERE follower_id = ?", [$profile['id']]);

// User gifts (VK Gifts)
$userGifts = DB::fetchAll("
    SELECT ug.*, g.name as gift_name, g.icon as gift_icon, u.username as from_user, u.display_name as from_name
    FROM user_gifts ug
    JOIN gifts g ON ug.gift_id = g.id
    JOIN users u ON ug.from_user_id = u.id
    WHERE ug.to_user_id = ?
    ORDER BY ug.id DESC LIMIT 12
", [$profile['id']]);

$catalogGifts = DB::fetchAll("SELECT * FROM gifts WHERE is_active = 1 ORDER BY price_coins ASC");

// User's posts
$posts = DB::fetchAll("
    SELECT p.*, u.username, u.display_name, u.avatar, u.role, u.level 
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.user_id = ? 
    ORDER BY p.id DESC LIMIT 30
", [$profile['id']]);

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <!-- PROFILE HERO BANNER & INFO -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <!-- Banner Cover -->
        <div style="height: 160px; background: linear-gradient(135deg, #1e3a8a, #4c1d95, #312e81); position: relative;"></div>

        <!-- Avatar & Actions -->
        <div style="padding: 0 28px 24px; position: relative;">
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: -50px; margin-bottom: 16px; flex-wrap: wrap; gap: 16px;">
                <img src="<?= e(url($profile['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 4px solid var(--bg-surface);" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($profile['display_name']) ?>&background=3b82f6&color=fff'">

                <div style="display: flex; gap: 10px;">
                    <?php if ($currentUser && $currentUser['id'] === $profile['id']): ?>
                        <a href="<?= url('settings') ?>" class="btn btn-secondary btn-sm">⚙️ Редактировать профиль</a>
                    <?php elseif ($currentUser): ?>
                        <button type="button" class="btn btn-sm <?= $isFollowing ? 'btn-secondary' : 'btn-primary' ?>" onclick="toggleFollow(<?= $profile['id'] ?>, this)">
                            <?= $isFollowing ? '✓ В подписках' : '+ Подписаться' ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('gift-modal').style.display='block'">
                            🎁 Подарок
                        </button>
                        <a href="<?= url('messages/' . $profile['username']) ?>" class="btn btn-secondary btn-sm">💬 Сообщение</a>
                        <a href="<?= url('wallet') ?>" class="btn btn-secondary btn-sm" style="color: #fbbf24;">🪙 Перевести</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile Info -->
            <div style="margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                    <h2 style="font-size: 22px; font-weight: 800;"><?= e($profile['display_name']) ?></h2>
                    <?php if ($profile['role'] === 'admin'): ?>
                        <span style="background: rgba(239, 68, 68, 0.2); color: #f87171; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 12px;">Admin</span>
                    <?php endif; ?>
                    <span class="level-badge">LVL <?= $profile['level'] ?></span>
                </div>
                <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">
                    @<?= e($profile['username']) ?> &bull; в экосистеме с <?= date('d.m.Y', strtotime($profile['created_at'])) ?>
                </div>
                <p style="font-size: 14px; line-height: 1.5; color: var(--text-secondary);">
                    <?= nl2br(e($profile['bio'] ?: $profile['status_text'])) ?>
                </p>
            </div>

            <!-- Stats Bar -->
            <div style="display: flex; gap: 24px; border-top: 1px solid var(--border-color); padding-top: 14px; font-size: 14px;">
                <div><strong><?= $postsCount ?></strong> <span style="color: var(--text-muted);">постов</span></div>
                <div><strong><?= $followersCount ?></strong> <span style="color: var(--text-muted);">подписчиков</span></div>
                <div><strong><?= $followingCount ?></strong> <span style="color: var(--text-muted);">подписок</span></div>
                <div><strong style="color: #fbbf24;">🪙 <?= format_coins($profile['coins']) ?></strong> <span style="color: var(--text-muted);">VladCoins</span></div>
            </div>
        </div>
    </div>

    <!-- GIFTS SHOWCASE (VK Gifts) -->
    <?php if (!empty($userGifts)): ?>
        <div class="card">
            <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 12px;">🎁 Подарки профиля (<?= count($userGifts) ?>)</h3>
            <div class="gifts-container">
                <?php foreach ($userGifts as $ug): ?>
                    <div class="gift-bubble" title="<?= e($ug['gift_name']) ?> от @<?= e($ug['from_user']) ?>: «<?= e($ug['message']) ?>»">
                        <?= $ug['gift_icon'] ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- POSTS WALL -->
    <div style="display: flex; flex-direction: column; gap: 16px;">
        <h3 style="font-size: 17px; font-weight: 700;">Публикации автора</h3>

        <?php if (empty($posts)): ?>
            <div class="card" style="text-align: center; color: var(--text-muted); padding: 40px;">
                Пользователь пока ничего не опубликовал
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <div class="card post-card">
                    <div class="post-header">
                        <div class="post-author-wrap">
                            <img src="<?= e(url($profile['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" alt="Avatar" class="post-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($profile['display_name']) ?>&background=3b82f6&color=fff'">
                            <div class="post-author-meta">
                                <div class="post-author-name"><?= e($profile['display_name']) ?></div>
                                <span class="post-time"><a href="<?= url('post/' . $post['id']) ?>" style="color: inherit;"><?= time_ago($post['created_at']) ?></a></span>
                            </div>
                        </div>
                    </div>
                    <div class="post-content"><?= parse_content($post['content']) ?></div>
                    <?php if (!empty($post['media_url'])): ?>
                        <div class="post-media-wrap">
                            <a href="<?= url('post/' . $post['id']) ?>">
                                <img src="<?= e(url($post['media_url'])) ?>" alt="Media">
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- GIFT PICKER MODAL -->
<?php if ($currentUser && $currentUser['id'] !== $profile['id']): ?>
    <div id="gift-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
        <div class="card" style="max-width: 480px; margin: 80px auto; position: relative;">
            <button type="button" onclick="document.getElementById('gift-modal').style.display='none'" style="position: absolute; right: 16px; top: 16px; background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;">✕</button>
            <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 6px;">Отправить виртуальный подарок</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">Подарок будет красоваться на стене профиля @<?= e($profile['username']) ?>.</p>

            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px;">
                <?php foreach ($catalogGifts as $cg): ?>
                    <button type="button" class="card" style="padding: 12px 6px; text-align: center; cursor: pointer; border: 1px solid var(--border-color); background: var(--bg-input);" onclick="sendGift(<?= $profile['id'] ?>, '<?= e($profile['username']) ?>', <?= $cg['id'] ?>, '<?= e($cg['name']) ?>', <?= $cg['price_coins'] ?>)">
                        <div style="font-size: 32px; margin-bottom: 4px;"><?= $cg['icon'] ?></div>
                        <div style="font-size: 11px; font-weight: 700;"><?= e($cg['name']) ?></div>
                        <div style="font-size: 11px; color: #fbbf24; font-weight: 800;"><?= $cg['price_coins'] ?> 🪙</div>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
async function toggleFollow(userId, btn) {
    try {
        const res = await fetch(baseUrl + '/api/index.php?action=follow_user', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId })
        });
        const data = await res.json();
        if (data.success) {
            btn.innerText = data.is_following ? '✓ В подписках' : '+ Подписаться';
            btn.className = data.is_following ? 'btn btn-secondary btn-sm' : 'btn btn-primary btn-sm';
            showToast(data.message, 'success');
        } else {
            showToast(data.error, 'error');
        }
    } catch (e) {
        showToast('Ошибка сети', 'error');
    }
}
</script>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
