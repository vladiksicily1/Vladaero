<?php
/**
 * ShibaLingo - Friends & Social Hub
 */

$pageTitle = 'Друзья и Сообщество';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$driver = Database::getDriver();

// Ensure friendships table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS friendships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            friend_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, friend_id)
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS `friendships` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `friend_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_friendship` (`user_id`, `friend_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

// Load friends list
$friends = [];
if ($user) {
    $stmt = $db->prepare("
        SELECT u.id, u.username, u.avatar, u.selected_skin, u.xp, u.streak, u.created_at
        FROM friendships f 
        JOIN " . tbl('users') . " u ON f.friend_id = u.id 
        WHERE f.user_id = :uid 
        ORDER BY u.xp DESC
    ");
    $stmt->execute(['uid' => $user['id']]);
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Search users if query provided
$searchQuery = trim($_GET['q'] ?? '');
$searchResults = [];
if (!empty($searchQuery)) {
    $sStmt = $db->prepare("
        SELECT id, username, avatar, selected_skin, xp, streak 
        FROM " . tbl('users') . " 
        WHERE username LIKE :q AND id != :uid 
        LIMIT 10
    ");
    $sStmt->execute([
        'q' => '%' . $searchQuery . '%',
        'uid' => $user ? $user['id'] : 0
    ]);
    $searchResults = $sStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div style="max-width: 860px; margin: 0 auto; padding-bottom: 40px;">
    <!-- Header -->
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                <span>👥</span> Друзья и Сообщество
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Учите языки вместе, соревнуйтесь в XP, вызывайте друзей на PvP дуэли и поддерживайте огоньки стриков! 🔥
            </p>
        </div>

        <a href="referral.php" class="btn-duo btn-primary" style="padding: 10px 18px; font-size: 0.9rem; text-decoration: none;">
            🎁 Пригласить друга (+100 💎)
        </a>
    </div>

    <!-- Search Friends Bar -->
    <div class="card-duo anim-bounce" style="padding: 20px; margin-bottom: 24px;">
        <form method="GET" action="friends.php" style="display: flex; gap: 12px;">
            <input type="text" name="q" value="<?= e($searchQuery) ?>" class="chat-input" placeholder="🔍 Найти друга по никнейму..." style="margin-bottom: 0; font-size: 1rem;" required>
            <button type="submit" class="btn-duo btn-primary" style="padding: 10px 24px; white-space: nowrap;">
                Найти
            </button>
            <?php if (!empty($searchQuery)): ?>
                <a href="friends.php" class="btn-duo btn-outline" style="padding: 10px 16px; text-decoration: none;">
                    Сброс
                </a>
            <?php endif; ?>
        </form>

        <!-- Search Results -->
        <?php if (!empty($searchQuery)): ?>
            <div style="margin-top: 18px; border-top: 1.5px solid var(--border-color); padding-top: 16px;">
                <div style="font-weight: 800; font-size: 0.9rem; color: var(--text-muted); margin-bottom: 12px; text-transform: uppercase;">
                    Результаты поиска (<?= count($searchResults) ?>):
                </div>

                <?php if (empty($searchResults)): ?>
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                        Пользователь с никнеймом «<strong><?= e($searchQuery) ?></strong>» не найден 🐕
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($searchResults as $su): ?>
                            <?php 
                            $isAlreadyFriend = in_array($su['id'], array_column($friends, 'id'));
                            ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bg-main); border-radius: 14px; border: 1.5px solid var(--border-color);">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="font-size: 1.8rem;">🐕</div>
                                    <div>
                                        <a href="profile.php?id=<?= $su['id'] ?>" style="font-weight: 800; color: var(--text-main); text-decoration: none; font-size: 1.05rem;">
                                            <?= e($su['username']) ?>
                                        </a>
                                        <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; gap: 10px;">
                                            <span>⚡ <?= (int)$su['xp'] ?> XP</span>
                                            <span>🔥 <?= (int)$su['streak'] ?> дн.</span>
                                        </div>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <a href="duel.php?vs=<?= $su['id'] ?>" class="btn-duo btn-outline" style="padding: 6px 12px; font-size: 0.85rem; text-decoration: none;">
                                        ⚔️ Дуэль
                                    </a>
                                    <button class="btn-duo <?= $isAlreadyFriend ? 'btn-outline' : 'btn-primary' ?>" onclick="handleFriendAction(<?= $su['id'] ?>, this)" style="padding: 6px 14px; font-size: 0.85rem;">
                                        <?= $isAlreadyFriend ? '✓ В друзьях' : '+ Добавить' ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- My Friends List -->
    <div class="card-duo" style="padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="font-size: 1.25rem; font-weight: 900; display: flex; align-items: center; gap: 8px; margin: 0;">
                <span>⭐</span> Мои друзья (<?= count($friends) ?>)
            </h3>
        </div>

        <?php if (empty($friends)): ?>
            <div style="text-align: center; padding: 40px 20px;">
                <div style="font-size: 3.5rem; margin-bottom: 12px;">🐾</div>
                <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 8px;">У вас пока нет друзей в списке</h3>
                <p style="color: var(--text-muted); font-size: 0.95rem; max-width: 460px; margin: 0 auto 20px auto;">
                    Найдите одногруппников через поиск выше или отправьте реферальную ссылку, чтобы получать кристаллы и соревноваться в дуэлях!
                </p>
                <a href="referral.php" class="btn-duo btn-primary" style="padding: 10px 24px; text-decoration: none;">
                    Получить реферальную ссылку 🎁
                </a>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: 1fr; gap: 12px;">
                <?php foreach ($friends as $idx => $f): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; background: var(--bg-main); border-radius: 16px; border: 1.5px solid var(--border-color); flex-wrap: wrap; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <div style="font-weight: 900; color: var(--text-muted); font-size: 1.1rem; width: 24px;">
                                #<?= $idx + 1 ?>
                            </div>
                            <div style="font-size: 2rem;">🐕</div>
                            <div>
                                <a href="profile.php?id=<?= $f['id'] ?>" style="font-weight: 900; font-size: 1.1rem; color: var(--text-main); text-decoration: none;">
                                    <?= e($f['username']) ?>
                                </a>
                                <div style="font-size: 0.85rem; color: var(--text-muted); display: flex; gap: 14px; margin-top: 2px;">
                                    <span style="color: #eab308; font-weight: 700;">⚡ <?= (int)$f['xp'] ?> XP</span>
                                    <span style="color: var(--streak-color); font-weight: 700;">🔥 <?= (int)$f['streak'] ?> дней</span>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 8px; align-items: center;">
                            <a href="duel.php?vs=<?= $f['id'] ?>" class="btn-duo btn-primary" style="padding: 8px 16px; font-size: 0.85rem; text-decoration: none;">
                                ⚔️ Вызвать на дуэль
                            </a>
                            <a href="profile.php?id=<?= $f['id'] ?>" class="btn-duo btn-outline" style="padding: 8px 12px; font-size: 0.85rem; text-decoration: none;" title="Профиль">
                                👤
                            </a>
                            <button onclick="handleFriendAction(<?= $f['id'] ?>, this, true)" class="btn-duo" style="padding: 8px 12px; font-size: 0.85rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);" title="Удалить из друзей">
                                ✕
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
async function handleFriendAction(friendId, btn, isRemove = false) {
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_friend');
        formData.append('friend_id', friendId);

        const res = await fetch('api/social_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            alert(data.message);
            location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось выполнить действие'));
        }
    } catch(err) {
        alert('Сетевая ошибка при изменении статуса друга');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
