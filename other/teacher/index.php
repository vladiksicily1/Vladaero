<?php
/**
 * Teacher Portal - Educator Dashboard
 */

$teacherTitle = 'Дашборд Преподавателя';
require_once __DIR__ . '/header.php';

$classCount = $db->query("SELECT COUNT(*) FROM " . tbl('classrooms'))->fetchColumn();
$studentsCount = $db->query("SELECT COUNT(*) FROM " . tbl('classroom_students'))->fetchColumn();
if ($studentsCount == 0) {
    $studentsCount = $db->query("SELECT COUNT(*) FROM " . tbl('users') . " WHERE status = 'active'")->fetchColumn();
}
$assignmentsCount = $db->query("SELECT COUNT(*) FROM " . tbl('assignments'))->fetchColumn();
$recentStudents = $db->query("SELECT u.*, (SELECT COUNT(*) FROM " . tbl('user_progress') . " WHERE user_id = u.id) as completed_lessons FROM " . tbl('users') . " u ORDER BY u.xp DESC LIMIT 10")->fetchAll();
?>

<div style="margin-bottom: 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>🧑‍🏫</span> <span>Панель управления обучением (Teacher Dashboard)</span>
        </h1>
        <p style="color: var(--text-muted);">
            Контроль успеваемости классов, выдача заданий и статистика активности учеников
        </p>
    </div>

    <div style="display: flex; gap: 10px;">
        <a href="classrooms.php" class="btn-duo btn-secondary" style="padding: 10px 18px; font-size: 0.95rem;">
            + Создать класс
        </a>
        <a href="assignments.php" class="btn-duo btn-primary" style="padding: 10px 18px; font-size: 0.95rem;">
            + Назначить ДЗ 📝
        </a>
    </div>
</div>

<!-- Key Stat Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 32px;">
    <div class="card-duo" style="display: flex; align-items: center; gap: 18px; margin-bottom: 0; padding: 20px;">
        <div style="font-size: 2.8rem; background: #e0f2fe; width: 64px; height: 64px; border-radius: 18px; display: flex; align-items: center; justify-content: center;">
            🏫
        </div>
        <div>
            <div style="font-size: 1.8rem; font-weight: 900; color: #0284c7;"><?= (int)$classCount ?></div>
            <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Моих классов</div>
        </div>
    </div>

    <div class="card-duo" style="display: flex; align-items: center; gap: 18px; margin-bottom: 0; padding: 20px;">
        <div style="font-size: 2.8rem; background: rgba(88,204,2,0.15); width: 64px; height: 64px; border-radius: 18px; display: flex; align-items: center; justify-content: center;">
            👥
        </div>
        <div>
            <div style="font-size: 1.8rem; font-weight: 900; color: var(--primary);"><?= (int)$studentsCount ?></div>
            <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Учеников в группе</div>
        </div>
    </div>

    <div class="card-duo" style="display: flex; align-items: center; gap: 18px; margin-bottom: 0; padding: 20px;">
        <div style="font-size: 2.8rem; background: #fef3c7; width: 64px; height: 64px; border-radius: 18px; display: flex; align-items: center; justify-content: center;">
            📝
        </div>
        <div>
            <div style="font-size: 1.8rem; font-weight: 900; color: #d97706;"><?= (int)$assignmentsCount ?></div>
            <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Заданий выдано</div>
        </div>
    </div>

    <div class="card-duo" style="display: flex; align-items: center; gap: 18px; margin-bottom: 0; padding: 20px;">
        <div style="font-size: 2.8rem; background: #f3e8ff; width: 64px; height: 64px; border-radius: 18px; display: flex; align-items: center; justify-content: center;">
            🎯
        </div>
        <div>
            <div style="font-size: 1.8rem; font-weight: 900; color: #9333ea;">94.2%</div>
            <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Средняя точность</div>
        </div>
    </div>
</div>

<!-- Class Progress Table -->
<div class="card-duo">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 style="font-size: 1.25rem; font-weight: 800;">🏆 Успеваемость учеников</h3>
        <a href="students.php" class="btn-duo btn-outline" style="font-size: 0.85rem; padding: 6px 14px;">
            Полный журнал →
        </a>
    </div>

    <table class="dict-table">
        <thead>
            <tr>
                <th>Место</th>
                <th>Ученик</th>
                <th>Опыт (XP)</th>
                <th>Стрик дней</th>
                <th>Уроков пройдено</th>
                <th>Статус</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentStudents as $idx => $st): ?>
                <tr>
                    <td style="font-weight: 900; color: <?= ($idx === 0) ? '#eab308' : 'var(--text-muted)' ?>;">
                        #<?= $idx + 1 ?>
                    </td>
                    <td>
                        <div style="font-weight: 800;"><?= e($st['username']) ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($st['email'] ?? 'student@shibalingo.ru') ?></div>
                    </td>
                    <td style="font-weight: 800; color: #eab308;">
                        ⚡ <?= (int)$st['xp'] ?> XP
                    </td>
                    <td style="font-weight: 700; color: var(--streak-color);">
                        🔥 <?= (int)$st['streak'] ?> дн.
                    </td>
                    <td style="font-weight: 800; color: var(--primary);">
                        📚 <?= (int)$st['completed_lessons'] ?>
                    </td>
                    <td>
                        <span class="badge-tag" style="background: rgba(88,204,2,0.15); color: var(--primary-shadow);">
                            Активен ✓
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
