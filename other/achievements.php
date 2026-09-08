<?php
/**
 * ShibaLingo - Achievements & Badges (Feature #4)
 */

$pageTitle = 'Достижения и Награды';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$achievements = $db->query("SELECT * FROM achievements ORDER BY xp_reward ASC")->fetchAll();

$userAchievements = $db->prepare("SELECT achievement_id FROM user_achievements WHERE user_id = :uid");
$userAchievements->execute(['uid' => $user['id']]);
$unlockedIds = $userAchievements->fetchAll(PDO::FETCH_COLUMN) ?: [];
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>🏅</span> Достижения и Бейджи
        </h1>
        <p style="color: var(--text-muted);">Выполняйте испытания, открывайте бейджи и получайте кристаллы 💎 и XP ⚡!</p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
        <?php foreach ($achievements as $ach): 
            $isUnlocked = in_array($ach['id'], $unlockedIds) || ($ach['req_type'] === 'first_lesson' && $user['xp'] > 0);
        ?>
            <div class="card-duo anim-bounce" style="display: flex; gap: 16px; align-items: flex-start; margin-bottom: 0; opacity: <?= $isUnlocked ? '1' : '0.6' ?>; border-color: <?= $isUnlocked ? 'var(--primary)' : 'var(--border-color)' ?>;">
                <div style="font-size: 3rem; background: var(--bg-main); width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <?= $ach['icon'] ?>
                </div>

                <div style="flex-grow: 1;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <h3 style="font-size: 1.15rem; font-weight: 800;"><?= e($ach['title']) ?></h3>
                        <?php if ($isUnlocked): ?>
                            <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);">✓ Получено</span>
                        <?php else: ?>
                            <span class="badge-tag">🔒 Закрыто</span>
                        <?php endif; ?>
                    </div>

                    <p style="font-size: 0.9rem; color: var(--text-muted); margin: 6px 0 12px 0;">
                        <?= e($ach['description']) ?>
                    </p>

                    <div style="display: flex; gap: 12px; font-weight: 800; font-size: 0.85rem;">
                        <span style="color: #eab308;">⚡ +<?= (int)$ach['xp_reward'] ?> XP</span>
                        <span style="color: var(--secondary);">💎 +<?= (int)$ach['gems_reward'] ?> Кристаллов</span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
