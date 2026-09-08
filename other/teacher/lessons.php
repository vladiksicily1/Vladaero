<?php
/**
 * Teacher Portal - Lesson & Curriculum Creator
 */

$teacherTitle = 'Конструктор Уроков';
require_once __DIR__ . '/header.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_lesson') {
        $lid = (int)($_POST['lesson_id'] ?? 0);
        $skillId = (int)$_POST['skill_id'];
        $title = trim($_POST['title']);
        $xp = (int)$_POST['xp_reward'];
        $order = (int)$_POST['order_num'];
        $data = trim($_POST['lesson_data']);

        if (empty($title) || empty($data)) {
            $error = 'Укажите название и данные урока!';
        } else {
            if ($lid > 0) {
                $stmt = $db->prepare("UPDATE " . tbl('lessons') . " SET skill_id = :s, title = :t, xp_reward = :x, order_num = :ord, lesson_data = :d WHERE id = :id");
                $stmt->execute(['s' => $skillId, 't' => $title, 'x' => $xp, 'ord' => $order, 'd' => $data, 'id' => $lid]);
                $message = 'Урок успешно обновлен!';
            } else {
                $stmt = $db->prepare("INSERT INTO " . tbl('lessons') . " (skill_id, title, xp_reward, order_num, lesson_data) VALUES (:s, :t, :x, :ord, :d)");
                $stmt->execute(['s' => $skillId, 't' => $title, 'x' => $xp, 'ord' => $order, 'd' => $data]);
                $message = 'Новый урок успешно создан!';
            }
        }
    }

    if ($act === 'delete_lesson') {
        $delId = (int)$_POST['delete_id'];
        $db->prepare("DELETE FROM " . tbl('lessons') . " WHERE id = :id")->execute(['id' => $delId]);
        $message = 'Урок удален.';
    }
}

$skills = $db->query("SELECT s.*, l.flag as lang_flag, l.name as lang_name FROM " . tbl('skills') . " s JOIN " . tbl('languages') . " l ON s.language_code = l.code ORDER BY s.language_code ASC, s.order_num ASC")->fetchAll();
$lessons = $db->query("SELECT l.*, s.title as skill_title, s.language_code, lang.flag as lang_flag 
                       FROM " . tbl('lessons') . " l 
                       JOIN " . tbl('skills') . " s ON l.skill_id = s.id 
                       JOIN " . tbl('languages') . " lang ON s.language_code = lang.code 
                       ORDER BY l.id DESC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>📚</span> <span>Конструктор интерактивных уроков для учителей</span>
        </h1>
        <p style="color: var(--text-muted);">
            Создание обучающих материалов, упражнений и AI-генерация уроков для вашего класса
        </p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openTeacherAiModal()">
            🤖 Сгенерировать через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openLessonModal()">
            + Создать урок вручную
        </button>
    </div>
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

<!-- Lessons Table -->
<div class="card-duo">
    <table class="dict-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Язык / Раздел</th>
                <th>Название урока</th>
                <th>Награда XP</th>
                <th>Упражнений</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lessons as $les): 
                $exCount = 0;
                $decoded = json_decode($les['lesson_data'], true);
                if (is_array($decoded)) $exCount = count($decoded);
            ?>
                <tr>
                    <td style="font-weight: 800; color: var(--text-muted);">#<?= $les['id'] ?></td>
                    <td>
                        <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow); font-weight: 800;">
                            <?= $les['lang_flag'] ?> <?= e($les['skill_title']) ?>
                        </span>
                    </td>
                    <td style="font-weight: 800; font-size: 1rem;">
                        <?= e($les['title']) ?>
                    </td>
                    <td style="font-weight: 800; color: #eab308;">
                        ⚡ <?= (int)$les['xp_reward'] ?> XP
                    </td>
                    <td style="font-weight: 700;">
                        📝 <?= $exCount ?> упр.
                    </td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <a href="../lesson.php?id=<?= $les['id'] ?>" target="_blank" class="btn-duo btn-outline" style="padding: 6px 12px; font-size: 0.8rem;">
                                👁️ Тест
                            </a>
                            <button class="btn-duo btn-secondary" style="padding: 6px 12px; font-size: 0.8rem;" onclick='editLesson(<?= json_encode($les, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                ✏️
                            </button>
                            <form method="POST" onsubmit="return confirm('Удалить этот урок?');" style="display: inline;">
                                <input type="hidden" name="form_action" value="delete_lesson">
                                <input type="hidden" name="delete_id" value="<?= $les['id'] ?>">
                                <button type="submit" class="btn-duo btn-outline" style="padding: 6px 10px; font-size: 0.8rem; border-color: var(--danger); color: var(--danger);">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Lesson Editor -->
<div id="modal-lesson-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 650px; width: 100%; max-height: 90vh; overflow-y: auto; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-lesson-title" style="font-size: 1.3rem; font-weight: 800;">Редактор урока</h3>
            <button onclick="document.getElementById('modal-lesson-edit').style.display='none'" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_lesson">
            <input type="hidden" name="lesson_id" id="les-id" value="0">

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Раздел навыка (Skill):</label>
                <select name="skill_id" id="les-skill" class="chat-input">
                    <?php foreach ($skills as $sk): ?>
                        <option value="<?= $sk['id'] ?>"><?= $sk['lang_flag'] ?> <?= e($sk['title']) ?> (<?= e($sk['lang_name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Название урока:</label>
                <input type="text" name="title" id="les-title" required class="chat-input" placeholder="Урок 1: Знакомство">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Награда XP:</label>
                    <input type="number" name="xp_reward" id="les-xp" value="20" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Порядковый номер:</label>
                    <input type="number" name="order_num" id="les-order" value="1" class="chat-input">
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; font-size: 0.85rem;">Данные упражнений (JSON):</label>
                    <button type="button" onclick="insertTeacherSampleJson()" style="background: none; border: none; color: var(--secondary); font-size: 0.8rem; font-weight: 700; cursor: pointer;">
                        📋 Вставить шаблон
                    </button>
                </div>
                <textarea name="lesson_data" id="les-data" rows="8" required class="chat-input" style="font-family: monospace; font-size: 0.85rem;"></textarea>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%; padding: 12px;">
                Сохранить урок 💾
            </button>
        </form>
    </div>
</div>

<!-- Modal: Teacher AI Generator -->
<div id="modal-teacher-ai" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 AI Генератор урока для учителя</h3>
            <button onclick="document.getElementById('modal-teacher-ai').style.display='none'" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Раздел:</label>
            <select id="tai-skill" class="chat-input">
                <?php foreach ($skills as $sk): ?>
                    <option value="<?= $sk['id'] ?>" data-lang="<?= e($sk['language_code']) ?>"><?= $sk['lang_flag'] ?> <?= e($sk['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема урока:</label>
            <input type="text" id="tai-topic" class="chat-input" placeholder="Например: В кафе и заказ еды, Числительные и время...">
        </div>

        <div id="tai-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-tai-run" style="width: 100%; padding: 12px;" onclick="runTeacherAiLesson()">
            Сгенерировать урок ✨
        </button>
    </div>
</div>

<script>
function openLessonModal() {
    document.getElementById('modal-lesson-title').textContent = 'Создание нового урока';
    document.getElementById('les-id').value = '0';
    document.getElementById('les-title').value = '';
    document.getElementById('les-xp').value = '20';
    document.getElementById('les-order').value = '1';
    insertTeacherSampleJson();
    document.getElementById('modal-lesson-edit').style.display = 'flex';
}

function editLesson(l) {
    document.getElementById('modal-lesson-title').textContent = `Редактирование: ${l.title}`;
    document.getElementById('les-id').value = l.id;
    document.getElementById('les-skill').value = l.skill_id;
    document.getElementById('les-title').value = l.title;
    document.getElementById('les-xp').value = l.xp_reward;
    document.getElementById('les-order').value = l.order_num || 1;
    
    try {
        const parsed = JSON.parse(l.lesson_data);
        document.getElementById('les-data').value = JSON.stringify(parsed, null, 2);
    } catch(e) {
        document.getElementById('les-data').value = l.lesson_data;
    }

    document.getElementById('modal-lesson-edit').style.display = 'flex';
}

function openTeacherAiModal() {
    document.getElementById('modal-teacher-ai').style.display = 'flex';
}

async function runTeacherAiLesson() {
    const skillEl = document.getElementById('tai-skill');
    const skillId = skillEl.value;
    const lang = skillEl.options[skillEl.selectedIndex].getAttribute('data-lang');
    const topic = document.getElementById('tai-topic').value.trim();
    const btn = document.getElementById('btn-tai-run');
    const statusDiv = document.getElementById('tai-status');

    if (!topic) {
        alert('Укажите тему урока!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ генерирует урок и упражнения...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_lesson', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ skill_id: skillId, topic, lang })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = '<span style="color: var(--primary); font-weight: 800;">🎉 Урок успешно создан! Обновление...</span>';
            setTimeout(() => location.reload(), 1000);
        } else {
            statusDiv.innerHTML = `<span style="color: var(--danger);">Ошибка: ${data.error}</span>`;
            btn.disabled = false;
        }
    } catch(e) {
        statusDiv.innerHTML = '<span style="color: var(--danger);">Ошибка сети.</span>';
        btn.disabled = false;
    }
}

function insertTeacherSampleJson() {
    const sample = [
        {
            "type": "multiple_choice",
            "question": "Выберите правильный перевод фразы «Mira, amico»:",
            "prompt": "Mira, amico",
            "options": ["Привет, друг", "Спокойной ночи", "Вкусная еда", "Где маяк?"],
            "correct": 0,
            "explanation": "Mira = Привет/Смотри, Amico = Друг"
        },
        {
            "type": "word_bank",
            "question": "Соберите фразу: «Мое сердце радуется»",
            "prompt": "Мое сердце радуется",
            "correct_sequence": ["Korno", "me", "vanti"],
            "word_pool": ["Korno", "me", "vanti", "Nox", "Barka"],
            "explanation": "Korno = Сердце, Me = Мое, Vanti = Радуется/Поет"
        }
    ];
    document.getElementById('les-data').value = JSON.stringify(sample, null, 2);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
