<?php
/**
 * Teacher Portal - Classrooms & Groups Manager
 */

$teacherTitle = 'Мои Классы и Группы';
require_once __DIR__ . '/header.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'create_class') {
        $title = trim($_POST['title']);
        $lang = trim($_POST['language_code'] ?? 'vladikish');
        $desc = trim($_POST['description'] ?? '');
        $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

        if (empty($title)) {
            $error = 'Укажите название класса!';
        } else {
            $stmt = $db->prepare("INSERT INTO " . tbl('classrooms') . " (teacher_id, title, code, language_code, description) VALUES (1, :t, :c, :l, :d)");
            $stmt->execute(['t' => $title, 'c' => $code, 'l' => $lang, 'd' => $desc]);
            $message = "Класс «{$title}» успешно создан! Код приглашения: {$code}";
        }
    }

    if ($act === 'delete_class') {
        $cid = (int)$_POST['delete_id'];
        $db->prepare("DELETE FROM " . tbl('classrooms') . " WHERE id = :id")->execute(['id' => $cid]);
        $message = 'Класс удален.';
    }
}

$languages = $db->query("SELECT * FROM " . tbl('languages') . " ORDER BY is_conlang DESC, code ASC")->fetchAll();
$classes = $db->query("SELECT c.*, (SELECT COUNT(*) FROM " . tbl('classroom_students') . " WHERE classroom_id = c.id) as student_count, l.name as lang_name, l.flag as lang_flag 
                       FROM " . tbl('classrooms') . " c 
                       LEFT JOIN " . tbl('languages') . " l ON c.language_code = l.code 
                       ORDER BY c.id DESC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>🏫</span> <span>Мои учебные классы</span>
        </h1>
        <p style="color: var(--text-muted);">
            Создавайте группы учеников, делитесь кодом приглашения и отслеживайте командный прогресс
        </p>
    </div>

    <button class="btn-duo btn-primary" onclick="openClassModal()">
        + Создать новый класс
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

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
    <?php if (empty($classes)): ?>
        <div class="card-duo" style="grid-column: 1 / -1; text-align: center; padding: 48px 20px;">
            <div style="font-size: 3rem; margin-bottom: 12px;">🏫</div>
            <h3 style="font-size: 1.3rem; font-weight: 800;">У вас пока нет созданных классов</h3>
            <p style="color: var(--text-muted); margin-bottom: 20px;">Создайте первый класс и отправьте ученикам код для подключения!</p>
            <button class="btn-duo btn-primary" onclick="openClassModal()">+ Создать класс</button>
        </div>
    <?php else: ?>
        <?php foreach ($classes as $c): ?>
            <div class="card-duo" style="display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 0;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow); font-weight: 800;">
                            <?= $c['lang_flag'] ?> <?= e($c['lang_name']) ?>
                        </span>
                        <div style="background: #f1f5f9; padding: 4px 10px; border-radius: 8px; font-family: monospace; font-weight: 800; font-size: 0.95rem; color: #0284c7;" title="Код для вступления учеников">
                            Код: <strong><?= e($c['code']) ?></strong>
                        </div>
                    </div>

                    <h3 style="font-size: 1.3rem; font-weight: 900; margin-bottom: 6px;">
                        <?= e($c['title']) ?>
                    </h3>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 16px;">
                        <?= e($c['description'] ?: 'Без описания') ?>
                    </p>
                </div>

                <div style="border-top: 1px solid var(--border-color); padding-top: 14px; display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted);">
                        👥 Учеников: <strong><?= (int)$c['student_count'] ?></strong>
                    </div>

                    <div style="display: flex; gap: 8px;">
                        <a href="assignments.php?classroom_id=<?= $c['id'] ?>" class="btn-duo btn-secondary" style="padding: 6px 12px; font-size: 0.8rem;">
                            Задания 📝
                        </a>
                        <form method="POST" onsubmit="return confirm('Удалить этот класс?');" style="display: inline;">
                            <input type="hidden" name="form_action" value="delete_class">
                            <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn-duo btn-outline" style="padding: 6px 10px; font-size: 0.8rem; border-color: var(--danger); color: var(--danger);">
                                🗑️
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal: Create Classroom -->
<div id="modal-class-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🏫 Создание нового класса</h3>
            <button onclick="document.getElementById('modal-class-edit').style.display='none'" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="create_class">

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Название группы/класса:</label>
                <input type="text" name="title" required class="chat-input" placeholder="Например: Vladikish 10-А класс, Итальянский A1-Утро">
            </div>

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Язык изучения:</label>
                <select name="language_code" class="chat-input">
                    <?php foreach ($languages as $l): ?>
                        <option value="<?= $l['code'] ?>"><?= $l['flag'] ?> <?= e($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Описание / Расписание:</label>
                <textarea name="description" rows="2" class="chat-input" placeholder="Понедельник/Среда 18:00..."></textarea>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%; padding: 12px;">
                Создать класс и получить код 🚀
            </button>
        </form>
    </div>
</div>

<script>
function openClassModal() {
    document.getElementById('modal-class-edit').style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
