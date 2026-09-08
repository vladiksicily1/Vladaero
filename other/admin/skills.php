<?php
/**
 * Admin Skills / Skill Tree Sections Manager
 */

$adminTitle = 'Дерево навыков';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_lessons')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_skill') {
        $sid = (int)($_POST['skill_id'] ?? 0);
        $langCode = trim($_POST['language_code']);
        $title = trim($_POST['title']);
        $icon = trim($_POST['icon']);
        $level = (int)$_POST['level'];
        $order = (int)$_POST['order_num'];
        $desc = trim($_POST['description']);

        if ($sid > 0) {
            $stmt = $db->prepare("UPDATE skills SET language_code = :l, title = :t, icon = :i, level = :lev, order_num = :ord, description = :d WHERE id = :id");
            $stmt->execute(['l' => $langCode, 't' => $title, 'i' => $icon, 'lev' => $level, 'ord' => $order, 'd' => $desc, 'id' => $sid]);
            $message = 'Раздел навыков обновлен!';
        } else {
            $stmt = $db->prepare("INSERT INTO skills (language_code, title, icon, level, order_num, description) VALUES (:l, :t, :i, :lev, :ord, :d)");
            $stmt->execute(['l' => $langCode, 't' => $title, 'i' => $icon, 'lev' => $level, 'ord' => $order, 'd' => $desc]);
            $message = 'Новый раздел успешно добавлен!';
        }
    }

    if ($act === 'delete_skill') {
        $sid = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM skills WHERE id = :id");
        $stmt->execute(['id' => $sid]);
        $message = 'Раздел удален.';
    }
}

// Language filter
$selectedLang = $_GET['lang'] ?? '';
$whereLang = !empty($selectedLang) ? "WHERE s.language_code = " . $db->quote($selectedLang) : "";

$languages = $db->query("SELECT * FROM languages ORDER BY is_conlang DESC")->fetchAll();
$skills = $db->query("SELECT s.*, l.name as lang_name, l.flag as lang_flag, (SELECT COUNT(*) FROM lessons WHERE skill_id = s.id) as lessons_count 
                      FROM skills s 
                      LEFT JOIN languages l ON s.language_code = l.code 
                      {$whereLang}
                      ORDER BY s.language_code ASC, s.order_num ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">🗺️ Дерево навыков и Разделы (Full CRUD & AI)</h1>
        <p style="color: var(--text-muted);">Управление секциями обучения, уроками и AI-генерация целых глав</p>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <button class="btn-duo btn-secondary" onclick="openAiSkillModal()">
            🤖 Сгенерировать главу через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openSkillModal()">
            + Добавить раздел
        </button>
    </div>
</div>

<!-- Language Filter Tabs -->
<div style="display: flex; gap: 8px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 6px;">
    <a href="skills.php" class="btn-duo <?= empty($selectedLang) ? 'btn-primary' : 'btn-outline' ?>" style="padding: 6px 14px; font-size: 0.85rem; border-radius: 12px; text-decoration: none;">
        Все языки (<?= count($skills) ?>)
    </a>
    <?php foreach ($languages as $l): ?>
        <a href="skills.php?lang=<?= urlencode($l['code']) ?>" class="btn-duo <?= ($selectedLang === $l['code']) ? 'btn-primary' : 'btn-outline' ?>" style="padding: 6px 14px; font-size: 0.85rem; border-radius: 12px; text-decoration: none;">
            <?= $l['flag'] ?> <?= e($l['name']) ?>
        </a>
    <?php endforeach; ?>
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
                <th>Порядок</th>
                <th>Язык</th>
                <th>Иконка</th>
                <th>Название раздела</th>
                <th>Описание</th>
                <th>Уроков</th>
                <th style="text-align: right;">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($skills)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 32px; color: var(--text-muted);">
                        Разделы не найдены. Создайте первый раздел с помощью кнопки выше!
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($skills as $s): ?>
                    <tr>
                        <td style="font-weight: 800;">#<?= $s['order_num'] ?></td>
                        <td><?= $s['lang_flag'] ?> <strong><?= e($s['lang_name']) ?></strong></td>
                        <td style="font-size: 1.4rem;">
                            <?php 
                                $icons = ['hand' => '👋', 'paw' => '🐾', 'heart' => '💖', 'sun' => '☀️', 'star' => '⭐'];
                                echo $icons[$s['icon']] ?? '🐾';
                            ?>
                        </td>
                        <td style="font-weight: 800; font-size: 1.05rem;"><?= e($s['title']) ?></td>
                        <td style="color: var(--text-muted);"><?= e($s['description']) ?></td>
                        <td>
                            <a href="lessons.php?skill_id=<?= $s['id'] ?>" class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow); text-decoration: none; font-weight: 800;" title="Перейти к урокам раздела">
                                📚 <?= $s['lessons_count'] ?> уроков ↗
                            </a>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <a href="lessons.php?skill_id=<?= $s['id'] ?>" class="btn-duo btn-secondary" style="padding: 4px 8px; font-size: 0.8rem; text-decoration: none;" title="Уроки раздела">
                                📚 Уроки
                            </a>
                            <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editSkill(<?= json_encode($s) ?>)' title="Редактировать">
                                ✏️
                            </button>
                            <form method="POST" style="display: inline-block;" onsubmit="return confirm('Удалить раздел «<?= e($s['title']) ?>»? Все уроки внутри также будут удалены.');">
                                <input type="hidden" name="form_action" value="delete_skill">
                                <input type="hidden" name="delete_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn-duo btn-danger" style="padding: 4px 8px; font-size: 0.8rem;" title="Удалить">
                                    🗑️
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Skill Editor -->
<div id="modal-skill-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-skill-title" style="font-size: 1.3rem; font-weight: 800;">Редактирование раздела</h3>
            <button onclick="closeSkillModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_skill">
            <input type="hidden" name="skill_id" id="skill-id" value="0">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Язык:</label>
                    <select name="language_code" id="skill-lang" class="chat-input">
                        <?php foreach ($languages as $l): ?>
                            <option value="<?= $l['code'] ?>"><?= $l['flag'] ?> <?= e($l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Иконка:</label>
                    <select name="icon" id="skill-icon" class="chat-input">
                        <option value="paw">🐾 Лапка (paw)</option>
                        <option value="hand">👋 Рука (hand)</option>
                        <option value="heart">💖 Сердце (heart)</option>
                        <option value="sun">☀️ Солнце (sun)</option>
                        <option value="star">⭐ Звезда (star)</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Название раздела:</label>
                <input type="text" name="title" id="skill-title-input" required class="chat-input" placeholder="Приветствия & Знакомство">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Краткое описание:</label>
                <input type="text" name="description" id="skill-desc" class="chat-input">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Уровень (Level):</label>
                    <input type="number" name="level" id="skill-level" value="1" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Порядок сортировки:</label>
                    <input type="number" name="order_num" id="skill-order" value="1" class="chat-input">
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить раздел 💾
            </button>
        </form>
    </div>
</div>

<script>
function openSkillModal() {
    document.getElementById('modal-skill-title').textContent = 'Добавление нового раздела';
    document.getElementById('skill-id').value = '0';
    document.getElementById('skill-title-input').value = '';
    document.getElementById('skill-desc').value = '';
    document.getElementById('skill-order').value = '1';
    document.getElementById('modal-skill-edit').style.display = 'flex';
}

function editSkill(skill) {
    document.getElementById('modal-skill-title').textContent = `Редактирование: ${skill.title}`;
    document.getElementById('skill-id').value = skill.id;
    document.getElementById('skill-lang').value = skill.language_code;
    document.getElementById('skill-icon').value = skill.icon;
    document.getElementById('skill-title-input').value = skill.title;
    document.getElementById('skill-desc').value = skill.description || '';
    document.getElementById('skill-level').value = skill.level || 1;
    document.getElementById('skill-order').value = skill.order_num || 1;
    document.getElementById('modal-skill-edit').style.display = 'flex';
}

function closeSkillModal() {
    document.getElementById('modal-skill-edit').style.display = 'none';
}

function openAiSkillModal() {
    document.getElementById('modal-ai-skill').style.display = 'flex';
}

function closeAiSkillModal() {
    document.getElementById('modal-ai-skill').style.display = 'none';
}

async function runAiSkillGeneration() {
    const lang = document.getElementById('ai-skill-lang').value;
    const topic = document.getElementById('ai-skill-topic').value.trim();
    const level = parseInt(document.getElementById('ai-skill-level').value);
    const btn = document.getElementById('btn-run-ai-skill');
    const statusDiv = document.getElementById('ai-skill-status');

    if (!topic) {
        alert('Укажите тему для нового раздела!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ разрабатывает учебный план раздела и 3 урока...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_skill_with_lessons', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lang, topic, level })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = `<span style="color: var(--primary); font-weight: 800;">🎉 Создан раздел «${data.skill_title}» с ${data.lessons_created} уроками! Обновление...</span>`;
            setTimeout(() => location.reload(), 1200);
        } else {
            statusDiv.innerHTML = `<span style="color: var(--danger);">Ошибка: ${data.error}</span>`;
            btn.disabled = false;
        }
    } catch (e) {
        statusDiv.innerHTML = '<span style="color: var(--danger);">Ошибка сети.</span>';
        btn.disabled = false;
    }
}
</script>

<!-- Modal: AI Skill Generator -->
<div id="modal-ai-skill" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 AI Генератор раздела и уроков</h3>
            <button onclick="closeAiSkillModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Язык:</label>
            <select id="ai-skill-lang" class="chat-input">
                <?php foreach ($languages as $l): ?>
                    <option value="<?= $l['code'] ?>"><?= $l['flag'] ?> <?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема раздела (Сфера жизни):</label>
            <input type="text" id="ai-skill-topic" class="chat-input" placeholder="Например: Покупки в магазине, В ресторане, Медицина...">
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Уровень сложности:</label>
            <select id="ai-skill-level" class="chat-input">
                <option value="1">Уровень 1 (Базовый)</option>
                <option value="2">Уровень 2 (Средний)</option>
                <option value="3">Уровень 3 (Продвинутый)</option>
            </select>
        </div>

        <div id="ai-skill-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-skill" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiSkillGeneration()">
            Сгенерировать раздел и 3 урока ✨
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
