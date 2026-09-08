<?php
/**
 * Teacher Portal - Homework & Assignments Manager
 */

$teacherTitle = 'Домашние Задания';
require_once __DIR__ . '/header.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'create_assignment') {
        $classId = (int)$_POST['classroom_id'];
        $title = trim($_POST['title']);
        $desc = trim($_POST['description'] ?? '');
        $lessonId = (int)($_POST['lesson_id'] ?? 0);
        $due = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d H:i:s', strtotime('+7 days'));
        $xp = (int)($_POST['xp_reward'] ?? 30);

        if (empty($title) || $classId <= 0) {
            $error = 'Укажите название и выберите класс!';
        } else {
            $stmt = $db->prepare("INSERT INTO " . tbl('assignments') . " (classroom_id, title, description, lesson_id, due_date, xp_reward) VALUES (:c, :t, :d, :l, :due, :x)");
            $stmt->execute(['c' => $classId, 't' => $title, 'd' => $desc, 'l' => $lessonId, 'due' => $due, 'x' => $xp]);
            $message = "Домашнее задание «{$title}» успешно выдано классу!";
        }
    }

    if ($act === 'delete_assignment') {
        $aid = (int)$_POST['delete_id'];
        $db->prepare("DELETE FROM " . tbl('assignments') . " WHERE id = :id")->execute(['id' => $aid]);
        $message = 'Задание удалено.';
    }
}

$classrooms = $db->query("SELECT * FROM " . tbl('classrooms') . " ORDER BY id DESC")->fetchAll();
$lessons = $db->query("SELECT l.*, s.title as skill_title FROM " . tbl('lessons') . " l JOIN " . tbl('skills') . " s ON l.skill_id = s.id ORDER BY l.id DESC")->fetchAll();

$filterClassId = (int)($_GET['classroom_id'] ?? 0);
$where = $filterClassId > 0 ? "WHERE a.classroom_id = {$filterClassId}" : "";

$assignments = $db->query("SELECT a.*, c.title as class_title, l.title as lesson_title,
                                  (SELECT COUNT(*) FROM " . tbl('classroom_students') . " WHERE classroom_id = a.classroom_id) as total_students,
                                  (SELECT COUNT(*) FROM " . tbl('assignment_submissions') . " WHERE assignment_id = a.id) as submitted_count
                           FROM " . tbl('assignments') . " a 
                           JOIN " . tbl('classrooms') . " c ON a.classroom_id = c.id 
                           LEFT JOIN " . tbl('lessons') . " l ON a.lesson_id = l.id 
                           {$where}
                           ORDER BY a.id DESC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>📝</span> <span>Домашние задания и Контрольные</span>
        </h1>
        <p style="color: var(--text-muted);">
            Назначайте уроки в качестве ДЗ, выставляйте дедлайны и проверяйте статус выполнения
        </p>
    </div>

    <button class="btn-duo btn-primary" onclick="openAssignModal()">
        + Выдать новое ДЗ 📝
    </button>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="background: #fee2e2; color: #991b1b; padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✕ <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Assignments Table -->
<div class="card-duo">
    <?php if (empty($assignments)): ?>
        <div style="text-align: center; padding: 40px 20px;">
            <div style="font-size: 3rem; margin-bottom: 12px;">📝</div>
            <h3 style="font-size: 1.2rem; font-weight: 800;">Пока нет выданных заданий</h3>
            <p style="color: var(--text-muted); margin-bottom: 16px;">Нажмите кнопку выше, чтобы выдать первое домашнее задание!</p>
            <button class="btn-duo btn-primary" onclick="openAssignModal()">+ Выдать ДЗ</button>
        </div>
    <?php else: ?>
        <table class="dict-table">
            <thead>
                <tr>
                    <th>Задание</th>
                    <th>Класс / Группа</th>
                    <th>Урок для прохождения</th>
                    <th>Дедлайн</th>
                    <th>Сдали</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td>
                            <div style="font-weight: 800; font-size: 1rem;"><?= e($a['title']) ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?= e($a['description'] ?: 'Без описания') ?></div>
                        </td>
                        <td>
                            <span class="badge-tag" style="background: #e0f2fe; color: #0284c7; font-weight: 800;">
                                🏫 <?= e($a['class_title']) ?>
                            </span>
                        </td>
                        <td>
                            <?= e($a['lesson_title'] ?: 'Самостоятельная работа') ?>
                        </td>
                        <td style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted);">
                            ⏰ <?= date('d.m.Y H:i', strtotime($a['due_date'])) ?>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-weight: 900; color: var(--primary);">
                                    <?= (int)$a['submitted_count'] ?> / <?= max(1, (int)$a['total_students']) ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Удалить это задание?');" style="display: inline;">
                                <input type="hidden" name="form_action" value="delete_assignment">
                                <input type="hidden" name="delete_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn-duo btn-outline" style="padding: 6px 12px; font-size: 0.8rem; border-color: var(--danger); color: var(--danger);">
                                    🗑️ Удалить
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Modal: Create Assignment -->
<div id="modal-assign-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 550px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">📝 Выдать домашнее задание</h3>
            <button onclick="document.getElementById('modal-assign-edit').style.display='none'" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="create_assignment">

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Класс:</label>
                <select name="classroom_id" class="chat-input" required>
                    <?php foreach ($classrooms as $cl): ?>
                        <option value="<?= $cl['id'] ?>">🏫 <?= e($cl['title']) ?> (<?= e($cl['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Название задания:</label>
                <input type="text" name="title" required class="chat-input" placeholder="Например: ДЗ к уроку 3, Повторение слов...">
            </div>

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Прикрепить урок для прохождения:</label>
                <select name="lesson_id" class="chat-input">
                    <option value="0">-- Без привязки к конкретному уроку --</option>
                    <?php foreach ($lessons as $les): ?>
                        <option value="<?= $les['id'] ?>">📚 <?= e($les['title']) ?> (<?= e($les['skill_title']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Срок сдачи (Дедлайн):</label>
                    <input type="datetime-local" name="due_date" class="chat-input" value="<?= date('Y-m-d\TH:i', strtotime('+7 days')) ?>">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Бонус XP за сдачу:</label>
                    <input type="number" name="xp_reward" value="30" class="chat-input">
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Комментарий преподавателя:</label>
                <textarea name="description" rows="2" class="chat-input" placeholder="Обратите внимание на правильные окончания глаголов..."></textarea>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%; padding: 12px;">
                Опубликовать задание для класса 🚀
            </button>
        </form>
    </div>
</div>

<script>
function openAssignModal() {
    document.getElementById('modal-assign-edit').style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
