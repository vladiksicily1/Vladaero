<?php
/**
 * Teacher Portal - Students Progress & Gradebook
 */

$teacherTitle = 'Успеваемость Учеников';
require_once __DIR__ . '/header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'reward_student') {
        $studentId = (int)$_POST['student_id'];
        $gems = (int)$_POST['gems_amount'];
        $xp = (int)$_POST['xp_amount'];

        $db->prepare("UPDATE " . tbl('users') . " SET gems = gems + :g, xp = xp + :x WHERE id = :id")->execute([
            'g' => $gems,
            'x' => $xp,
            'id' => $studentId
        ]);
        $message = "Ученику начислена награда от учителя: +{$gems} 💎, +{$xp} ⚡!";
    }
}

$students = $db->query("SELECT u.*, 
                               (SELECT COUNT(*) FROM " . tbl('user_progress') . " WHERE user_id = u.id) as completed_lessons,
                               (SELECT COUNT(*) FROM " . tbl('user_achievements') . " WHERE user_id = u.id) as achievements_count
                        FROM " . tbl('users') . " u 
                        ORDER BY u.xp DESC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>👥</span> <span>Журнал успеваемости учеников (Gradebook)</span>
        </h1>
        <p style="color: var(--text-muted);">
            Отслеживание прогресса, стриков, пройденных уроков и поощрение кристаллов 💎
        </p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<div class="card-duo">
    <table class="dict-table">
        <thead>
            <tr>
                <th>Ученик</th>
                <th>Язык</th>
                <th>Опыт (XP)</th>
                <th>Кристаллы</th>
                <th>Стрик</th>
                <th>Пройдено уроков</th>
                <th>Награды</th>
                <th>Поощрить</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($students as $st): ?>
                <tr>
                    <td>
                        <div style="font-weight: 800; font-size: 1rem;"><?= e($st['username']) ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($st['email'] ?? '') ?></div>
                    </td>
                    <td>
                        <span class="badge-tag" style="background: #f1f5f9; font-weight: 800;">
                            <?= e($st['current_language'] ?? 'vladikish') ?>
                        </span>
                    </td>
                    <td style="font-weight: 800; color: #eab308;">
                        ⚡ <?= (int)$st['xp'] ?>
                    </td>
                    <td style="font-weight: 800; color: #0284c7;">
                        💎 <?= (int)$st['gems'] ?>
                    </td>
                    <td style="font-weight: 700; color: var(--streak-color);">
                        🔥 <?= (int)$st['streak'] ?> дн.
                    </td>
                    <td style="font-weight: 800; color: var(--primary);">
                        📚 <?= (int)$st['completed_lessons'] ?>
                    </td>
                    <td>
                        🏆 <?= (int)$st['achievements_count'] ?>
                    </td>
                    <td>
                        <button class="btn-duo btn-primary" style="padding: 6px 12px; font-size: 0.8rem;" onclick="openRewardModal(<?= $st['id'] ?>, '<?= e($st['username']) ?>')">
                            + Награда 💎
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Reward Student -->
<div id="modal-reward-student" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 440px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🎁 Поощрить ученика</h3>
            <button onclick="document.getElementById('modal-reward-student').style.display='none'" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="reward_student">
            <input type="hidden" name="student_id" id="rew-student-id" value="0">

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 4px;">Ученик:</label>
                <div id="rew-student-name" style="font-weight: 800; color: var(--primary); font-size: 1.1rem;"></div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Кристаллы 💎:</label>
                    <input type="number" name="gems_amount" value="50" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Опыт XP ⚡:</label>
                    <input type="number" name="xp_amount" value="100" class="chat-input">
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%; padding: 12px;">
                Отправить поощрение ✨
            </button>
        </form>
    </div>
</div>

<script>
function openRewardModal(id, name) {
    document.getElementById('rew-student-id').value = id;
    document.getElementById('rew-student-name').textContent = name;
    document.getElementById('modal-reward-student').style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
