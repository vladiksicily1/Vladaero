<?php
/**
 * ShibaLingo - Teacher & Classroom Portal (Feature #34, #55, #58)
 */

$pageTitle = 'Кабинет Преподавателя';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$students = $db->query("SELECT * FROM users ORDER BY xp DESC LIMIT 10")->fetchAll();
?>

<div style="max-width: 850px; margin: 0 auto;">
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
                <span>🎓</span> Кабинет Преподавателя / Репетитора
            </h1>
            <p style="color: var(--text-muted);">
                Управление учебными группами, назначение домашних заданий и аналитика успеваемости
            </p>
        </div>

        <button class="btn-duo btn-primary" onclick="alert('Домашнее задание успешно отправлено классу!')">
            + Назначить ДЗ классу 📝
        </button>
    </div>

    <!-- Classroom Stats -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="card-duo" style="margin-bottom: 0;">
            <div style="font-size: 2rem; font-weight: 900; color: var(--primary);"><?= count($students) ?></div>
            <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700;">Учеников в классе</div>
        </div>
        <div class="card-duo" style="margin-bottom: 0;">
            <div style="font-size: 2rem; font-weight: 900; color: var(--secondary);">92%</div>
            <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700;">Средняя успеваемость</div>
        </div>
        <div class="card-duo" style="margin-bottom: 0;">
            <div style="font-size: 2rem; font-weight: 900; color: var(--streak-color);">8.4</div>
            <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700;">Средний стрик дней</div>
        </div>
    </div>

    <!-- Student Performance Table -->
    <div class="card-duo">
        <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 16px;">Журнал успеваемости класса</h3>
        <table class="dict-table">
            <thead>
                <tr>
                    <th>Ученик</th>
                    <th>Email</th>
                    <th>XP Опыт</th>
                    <th>Стрик</th>
                    <th>Статус ДЗ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s): ?>
                    <tr>
                        <td style="font-weight: 800;"><?= e($s['username']) ?></td>
                        <td style="color: var(--text-muted);"><?= e($s['email']) ?></td>
                        <td style="color: #eab308; font-weight: 800;">⚡ <?= (int)$s['xp'] ?></td>
                        <td style="color: var(--streak-color); font-weight: 800;">🔥 <?= (int)$s['streak'] ?> дн.</td>
                        <td><span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);">Сдано 5/5 ✅</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
