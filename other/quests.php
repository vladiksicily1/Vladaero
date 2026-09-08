<?php
/**
 * ShibaLingo - Daily Quests Hub (Feature #5)
 */

$pageTitle = 'Ежедневные квесты';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$quests = $db->query("SELECT * FROM daily_quests ORDER BY id ASC")->fetchAll();
?>

<div style="max-width: 750px; margin: 0 auto;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>🎯</span> Ежедневные квесты
        </h1>
        <p style="color: var(--text-muted);">Квесты обновляются каждый день в полночь. Выполняйте их и собирайте награды!</p>
    </div>

    <div style="display: flex; flex-direction: column; gap: 16px;">
        <?php foreach ($quests as $idx => $q): 
            $progress = ($idx === 0) ? 1 : 0; // Example progress
            $target = $q['req_target'];
            $isDone = ($progress >= $target);
        ?>
            <div class="card-duo anim-bounce" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0; flex-wrap: wrap; gap: 16px;">
                <div style="flex-grow: 1;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                        <h3 style="font-size: 1.15rem; font-weight: 800;"><?= e($q['title']) ?></h3>
                        <?php if ($isDone): ?>
                            <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);">✓ Выполнено</span>
                        <?php endif; ?>
                    </div>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 10px;">
                        <?= e($q['description']) ?>
                    </p>

                    <div style="max-width: 300px;">
                        <div class="progress-bar-duo" style="height: 10px;">
                            <div class="progress-bar-fill" style="width: <?= ($progress / $target) * 100 ?>%;"></div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="text-align: right; font-weight: 800;">
                        <div style="color: #eab308; font-size: 0.95rem;">⚡ +<?= $q['xp_reward'] ?> XP</div>
                        <div style="color: var(--secondary); font-size: 0.9rem;">💎 +<?= $q['gems_reward'] ?></div>
                    </div>

                    <?php if ($isDone): ?>
                        <button class="btn-duo btn-primary" onclick="claimQuest(<?= $q['xp_reward'] ?>, <?= $q['gems_reward'] ?>, this)">
                            Забрать 🎁
                        </button>
                    <?php else: ?>
                        <a href="index.php" class="btn-duo btn-outline" style="padding: 10px 16px; font-size: 0.85rem;">
                            К урокам →
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function claimQuest(xp, gems, btn) {
    SoundEngine.play('win');
    triggerConfetti();
    btn.disabled = true;
    btn.textContent = 'Получено ✓';
    btn.style.background = 'var(--border-color)';
    btn.style.color = 'var(--text-muted)';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
