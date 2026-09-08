<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::user();
$pageTitle = 'Сообщества экосистемы';

// Handle create community
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_community'])) {
    $user = Auth::requireAuth();
    if (!csrf_validate()) {
        flash_set('error', 'CSRF ошибка');
        redirect('communities');
    }

    $name = trim($_POST['name'] ?? '');
    $slug = strtolower(trim($_POST['slug'] ?? ''));
    $desc = trim($_POST['description'] ?? '');

    if (!preg_match('/^[a-z0-9_-]{3,40}$/', $slug)) {
        flash_set('error', 'Короткий адрес сообщества (slug) должен содержать от 3 до 40 латинских символов');
        redirect('communities');
    }

    $exists = DB::fetch("SELECT id FROM communities WHERE slug = ? LIMIT 1", [$slug]);
    if ($exists) {
        flash_set('error', 'Сообщество с таким адресом уже существует');
        redirect('communities');
    }

    $commId = DB::insert('communities', [
        'name' => $name,
        'slug' => $slug,
        'description' => $desc,
        'creator_id' => $user['id']
    ]);

    DB::insert('community_members', [
        'community_id' => $commId,
        'user_id' => $user['id'],
        'role' => 'admin'
    ]);

    flash_set('success', 'Сообщество «' . e($name) . '» успешно создано!');
    redirect('c/' . $slug);
}

// Single community view or catalog
$commSlug = trim($_GET['comm'] ?? '');
$activeComm = null;
$commPosts = [];

if (!empty($commSlug)) {
    $activeComm = DB::fetch("SELECT c.*, u.username as creator_user FROM communities c JOIN users u ON c.creator_id = u.id WHERE c.slug = ? LIMIT 1", [$commSlug]);
    if ($activeComm) {
        $pageTitle = $activeComm['name'] . ' — Сообщество';
        $commPosts = DB::fetchAll("SELECT p.*, u.username, u.display_name, u.avatar, u.level FROM posts p JOIN users u ON p.user_id = u.id WHERE p.community_id = ? ORDER BY p.id DESC LIMIT 30", [$activeComm['id']]);
    }
}

$allCommunities = DB::fetchAll("SELECT c.*, (SELECT COUNT(*) FROM community_members WHERE community_id = c.id) as real_members_count FROM communities c ORDER BY c.members_count DESC LIMIT 50");

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <?php if ($activeComm): ?>
        <!-- ACTIVE COMMUNITY HERO -->
        <div class="card" style="padding: 28px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 60px; height: 60px; border-radius: var(--radius-lg); background: linear-gradient(135deg, #8b5cf6, #3b82f6); display: flex; align-items: center; justify-content: center; font-size: 28px; color: #fff; font-weight: 800;">
                        <?= mb_substr($activeComm['name'], 0, 1) ?>
                    </div>
                    <div>
                        <h2 style="font-size: 22px; font-weight: 800; margin-bottom: 4px;"><?= e($activeComm['name']) ?></h2>
                        <div style="font-size: 13px; color: var(--text-muted);">
                            c/<?= e($activeComm['slug']) ?> &bull; Создатель: <a href="<?= url('u/' . $activeComm['creator_user']) ?>" style="color: var(--accent-primary);">@<?= e($activeComm['creator_user']) ?></a> &bull; Участников: <?= $activeComm['members_count'] ?>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <a href="<?= url('communities') ?>" class="btn btn-secondary btn-sm">← Все сообщества</a>
                </div>
            </div>

            <?php if (!empty($activeComm['description'])): ?>
                <div style="margin-top: 16px; font-size: 14px; color: var(--text-secondary); line-height: 1.5; border-top: 1px solid var(--border-color); padding-top: 14px;">
                    <?= nl2br(e($activeComm['description'])) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- COMMUNITY POSTS -->
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <h3 style="font-size: 17px; font-weight: 700;">Публикации в сообществе</h3>
            <?php if (empty($commPosts)): ?>
                <div class="card" style="text-align: center; color: var(--text-muted); padding: 30px;">
                    В этом сообществе пока нет записей.
                </div>
            <?php else: ?>
                <?php foreach ($commPosts as $post): ?>
                    <div class="card post-card">
                        <div class="post-header">
                            <div class="post-author-wrap">
                                <a href="<?= url('u/' . $post['username']) ?>">
                                    <img src="<?= e(url($post['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" alt="Avatar" class="post-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($post['display_name']) ?>&background=3b82f6&color=fff'">
                                </a>
                                <div class="post-author-meta">
                                    <div class="post-author-name">
                                        <a href="<?= url('u/' . $post['username']) ?>"><?= e($post['display_name']) ?></a>
                                    </div>
                                    <span class="post-time"><a href="<?= url('post/' . $post['id']) ?>" style="color: inherit;"><?= time_ago($post['created_at']) ?></a></span>
                                </div>
                            </div>
                        </div>
                        <div class="post-content"><?= parse_content($post['content']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ALL COMMUNITIES CATALOG -->
        <div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 4px;">👥 Сообщества и Клубы VladInc</h2>
                <p style="color: var(--text-secondary); font-size: 13px;">Находите единомышленников, создавайте свои сообщества и делитесь контентом.</p>
            </div>
            <?php if ($currentUser): ?>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('create-comm-modal').style.display = 'block'">+ Создать сообщество</button>
            <?php endif; ?>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <?php foreach ($allCommunities as $c): ?>
                <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #3b82f6); display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; font-weight: 800;">
                                <?= mb_substr($c['name'], 0, 1) ?>
                            </div>
                            <div>
                                <h3 style="font-size: 15px; font-weight: 700;">
                                    <a href="<?= url('c/' . $c['slug']) ?>" style="color: inherit;"><?= e($c['name']) ?></a>
                                </h3>
                                <span style="font-size: 12px; color: var(--text-muted);">c/<?= e($c['slug']) ?> &bull; <?= $c['members_count'] ?> участников</span>
                            </div>
                        </div>
                        <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.4; margin-bottom: 14px;">
                            <?= e(mb_strimwidth($c['description'] ?? 'Тематическое сообщество участников экосистемы', 0, 100, '...')) ?>
                        </p>
                    </div>
                    <a href="<?= url('c/' . $c['slug']) ?>" class="btn btn-secondary btn-sm" style="width: 100%;">Открыть сообщество</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- CREATE COMMUNITY MODAL -->
<div id="create-comm-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
    <div class="card" style="max-width: 480px; margin: 80px auto; position: relative;">
        <button type="button" onclick="document.getElementById('create-comm-modal').style.display='none'" style="position: absolute; right: 16px; top: 16px; background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;">✕</button>
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px;">Создать новое сообщество</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="create_community" value="1">
            <div class="form-group" style="margin-bottom: 14px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Название сообщества</label>
                <input type="text" name="name" class="form-control" placeholder="Клуб IT & Стартапы" required>
            </div>
            <div class="form-group" style="margin-bottom: 14px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Короткий адрес (slug: только латиница и цифры)</label>
                <input type="text" name="slug" class="form-control" placeholder="it-startups" required>
            </div>
            <div class="form-group" style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Описание</label>
                <textarea name="description" class="form-control" rows="3" placeholder="О чем это сообщество?"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Создать сообщество</button>
        </form>
    </div>
</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
