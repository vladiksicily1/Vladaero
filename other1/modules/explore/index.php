<?php
if (!defined('VLADINC_INIT')) exit;

$pageTitle = 'Навигатор и поиск';
$currentUser = Auth::user();

$q = trim($_GET['q'] ?? '');

$foundUsers = [];
$foundPosts = [];

if (!empty($q)) {
    $searchPattern = '%' . $q . '%';
    // Search users
    $foundUsers = DB::fetchAll("SELECT * FROM users WHERE username LIKE ? OR display_name LIKE ? ORDER BY xp DESC LIMIT 20", [$searchPattern, $searchPattern]);
    
    // Search posts
    $foundPosts = DB::fetchAll("SELECT p.*, u.username, u.display_name, u.avatar, u.level FROM posts p JOIN users u ON p.user_id = u.id WHERE p.content LIKE ? ORDER BY p.id DESC LIMIT 30", [$searchPattern]);
}

// Global leaderboard
$leaders = DB::fetchAll("SELECT * FROM users ORDER BY xp DESC LIMIT 20");

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <!-- SEARCH BAR HERO -->
    <div class="card">
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 6px;">🔍 Навигатор по экосистеме VladInc</h2>
        <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 16px;">
            Ищите пользователей, публикации, идеи и хэштеги (#...).
        </p>

        <form action="<?= url('explore') ?>" method="GET" style="display: flex; gap: 10px;">
            <input type="text" name="q" class="form-control" placeholder="Введите поисковый запрос или хэштег..." value="<?= e($q) ?>" required>
            <button type="submit" class="btn btn-primary">Искать</button>
        </form>
    </div>

    <?php if (!empty($q)): ?>
        <!-- SEARCH RESULTS -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <h3 style="font-size: 17px; font-weight: 700;">Результаты поиска по запросу «<?= e($q) ?>»:</h3>

            <!-- Users Found -->
            <?php if (!empty($foundUsers)): ?>
                <div class="card">
                    <h4 style="font-size: 15px; margin-bottom: 12px; color: #60a5fa;">Найденные пользователи (<?= count($foundUsers) ?>)</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <?php foreach ($foundUsers as $u): ?>
                            <a href="<?= url('profile/@' . $u['username']) ?>" style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: var(--bg-input); border-radius: var(--radius-md); transition: var(--transition);">
                                <img src="<?= e(url($u['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($u['display_name']) ?>&background=3b82f6&color=fff'">
                                <div>
                                    <div style="font-size: 14px; font-weight: 700;"><?= e($u['display_name']) ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted);">@<?= e($u['username']) ?> &bull; Ур. <?= $u['level'] ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Posts Found -->
            <?php if (!empty($foundPosts)): ?>
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <h4 style="font-size: 15px; color: #60a5fa;">Публикации (<?= count($foundPosts) ?>)</h4>
                    <?php foreach ($foundPosts as $p): ?>
                        <div class="card post-card">
                            <div class="post-header">
                                <div class="post-author-wrap">
                                    <a href="<?= url('profile/@' . $p['username']) ?>">
                                        <img src="<?= e(url($p['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" class="post-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($p['display_name']) ?>&background=3b82f6&color=fff'">
                                    </a>
                                    <div class="post-author-meta">
                                        <div class="post-author-name"><a href="<?= url('profile/@' . $p['username']) ?>"><?= e($p['display_name']) ?></a></div>
                                        <span class="post-time"><?= time_ago($p['created_at']) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="post-content"><?= parse_content($p['content']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($foundUsers) && empty($foundPosts)): ?>
                <div class="card" style="text-align: center; color: var(--text-muted); padding: 40px;">
                    Ничего не найдено по вашему запросу.
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <!-- GLOBAL LEADERBOARD -->
        <div class="card">
            <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 16px;">🏆 Зал Славы &bull; Топ участников экосистемы</h3>

            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($leaders as $i => $leader): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bg-input); border-radius: var(--radius-md);">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <span style="font-weight: 900; font-size: 16px; width: 24px; color: <?= $i === 0 ? '#fbbf24' : ($i === 1 ? '#94a3b8' : ($i === 2 ? '#b45309' : 'var(--text-muted)')) ?>;">
                                #<?= $i + 1 ?>
                            </span>
                            <img src="<?= e(url($leader['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($leader['display_name']) ?>&background=3b82f6&color=fff'">
                            <div>
                                <a href="<?= url('profile/@' . $leader['username']) ?>" style="font-size: 14px; font-weight: 700; color: inherit;">
                                    <?= e($leader['display_name']) ?>
                                </a>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    @<?= e($leader['username']) ?> &bull; Уровень <?= $leader['level'] ?> (<?= number_format($leader['xp'], 0, '.', ' ') ?> XP)
                                </div>
                            </div>
                        </div>

                        <div style="font-size: 15px; font-weight: 800; color: #fbbf24;">
                            🪙 <?= format_coins($leader['coins']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php endif; ?>

</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
