<?php
/**
 * Admin Stories Management & Interactive Dialog Builder (CRUD)
 */

$adminTitle = 'Управление историями';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_lessons')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_story') {
        $sid = (int)($_POST['story_id'] ?? 0);
        $title = trim($_POST['title']);
        $lang = trim($_POST['language_code']);
        $level = (int)$_POST['level'];
        $cover = trim($_POST['cover_image']);
        $xp = (int)$_POST['xp_reward'];
        $gems = (int)$_POST['gems_reward'];
        $storyData = trim($_POST['story_data']);

        $testJson = json_decode($storyData, true);
        if (!is_array($testJson)) {
            $error = 'Ошибка: Данные истории должны быть валидным JSON-массивом реплик диалога!';
        } else {
            if ($sid > 0) {
                $stmt = $db->prepare("UPDATE " . tbl('stories') . " SET title = :t, language_code = :l, level = :lvl, cover_image = :cov, xp_reward = :xp, gems_reward = :g, story_data = :d WHERE id = :id");
                $stmt->execute(['t' => $title, 'l' => $lang, 'lvl' => $level, 'cov' => $cover, 'xp' => $xp, 'g' => $gems, 'd' => $storyData, 'id' => $sid]);
                $message = 'История успешно обновлена!';
            } else {
                $stmt = $db->prepare("INSERT INTO " . tbl('stories') . " (title, language_code, level, cover_image, xp_reward, gems_reward, story_data) VALUES (:t, :l, :lvl, :cov, :xp, :g, :d)");
                $stmt->execute(['t' => $title, 'l' => $lang, 'lvl' => $level, 'cov' => $cover, 'xp' => $xp, 'g' => $gems, 'd' => $storyData]);
                $message = 'Новая история успешно создана!';
            }
        }
    }

    if ($act === 'delete_story') {
        $sid = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM " . tbl('stories') . " WHERE id = :id");
        $stmt->execute(['id' => $sid]);
        $message = 'История удалена.';
    }
}

$languages = $db->query("SELECT * FROM " . tbl('languages') . " ORDER BY is_conlang DESC, code ASC")->fetchAll();
$stories = $db->query("SELECT s.*, l.name as lang_name, l.flag as lang_flag FROM " . tbl('stories') . " s LEFT JOIN " . tbl('languages') . " l ON s.language_code = l.code ORDER BY s.id DESC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">📖 Управление историями (Stories CRUD & AI)</h1>
        <p style="color: var(--text-muted);">Создание интерактивных диалогов, аудио-сценок и сюжетных историй</p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openAiStoryModal()">
            🤖 Сгенерировать через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openStoryModal()">
            + Создать историю
        </button>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="background: var(--danger-light); color: var(--danger-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✕ <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="card-duo">
    <table class="dict-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Язык</th>
                <th>Название</th>
                <th>Уровень</th>
                <th>Награда</th>
                <th>Реплик</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($stories)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 32px; color: var(--text-muted);">
                        Историй пока нет. Нажмите «+ Создать историю»!
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($stories as $st): 
                $dialogArr = json_decode($st['story_data'], true) ?: [];
            ?>
                <tr>
                    <td>#<?= $st['id'] ?></td>
                    <td><?= $st['lang_flag'] ?? '🐕' ?> <strong><?= e($st['lang_name'] ?? $st['language_code']) ?></strong></td>
                    <td style="font-weight: 800; font-size: 1.05rem;"><?= e($st['title']) ?></td>
                    <td><span class="badge-tag">Уровень <?= (int)$st['level'] ?></span></td>
                    <td style="color: #eab308; font-weight: 800;">⚡ <?= (int)$st['xp_reward'] ?> XP / 💎 <?= (int)$st['gems_reward'] ?></td>
                    <td><span class="badge-tag"><?= count($dialogArr) ?> реплик</span></td>
                    <td style="display: flex; gap: 6px;">
                        <a href="../stories.php?id=<?= $st['id'] ?>" target="_blank" class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" title="Открыть">
                            ▶️ Тест
                        </a>
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editStory(<?= json_encode($st) ?>)' title="Редактировать">
                            ✏️
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить эту историю?');">
                            <input type="hidden" name="form_action" value="delete_story">
                            <input type="hidden" name="delete_id" value="<?= $st['id'] ?>">
                            <button type="submit" class="btn-duo" style="padding: 4px 8px; font-size: 0.8rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);" title="Удалить">
                                🗑️
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Story Editor -->
<div id="modal-story-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 750px; width: 100%; margin-bottom: 0; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-story-title" style="font-size: 1.3rem; font-weight: 800;">Редактор истории</h3>
            <button onclick="closeStoryModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_story">
            <input type="hidden" name="story_id" id="st-id" value="0">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Название истории:</label>
                    <input type="text" name="title" id="st-title" required class="chat-input" placeholder="Таинственная встреча в саду">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Язык:</label>
                    <select name="language_code" id="st-lang" class="chat-input">
                        <?php foreach ($languages as $l): ?>
                            <option value="<?= $l['code'] ?>"><?= $l['flag'] ?> <?= e($l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Сложность (Уровень):</label>
                    <input type="number" name="level" id="st-level" value="1" min="1" max="5" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Награда XP:</label>
                    <input type="number" name="xp_reward" id="st-xp" value="30" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Награда Gems 💎:</label>
                    <input type="number" name="gems_reward" id="st-gems" value="15" class="chat-input">
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Ссылка на обложку (Cover URL):</label>
                <input type="text" name="cover_image" id="st-cover" class="chat-input" placeholder="uploads/story_cover.png или оставить пустым">
            </div>

            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; font-size: 0.85rem;">JSON реплик и вопросов истории:</label>
                    <button type="button" class="badge-tag" style="background: var(--secondary); color: white; border: none; cursor: pointer;" onclick="insertSampleStoryJson()">
                        + Вставить шаблон диалога
                    </button>
                </div>
                <textarea name="story_data" id="st-data" rows="12" class="chat-input" style="font-family: monospace; font-size: 0.85rem; line-height: 1.4;" required></textarea>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить историю 💾
            </button>
        </form>
    </div>
</div>

<script>
function openStoryModal() {
    document.getElementById('modal-story-title').textContent = 'Создание новой истории';
    document.getElementById('st-id').value = '0';
    document.getElementById('st-title').value = '';
    document.getElementById('st-level').value = '1';
    document.getElementById('st-xp').value = '30';
    document.getElementById('st-gems').value = '15';
    document.getElementById('st-cover').value = '';
    insertSampleStoryJson();
    document.getElementById('modal-story-edit').style.display = 'flex';
}

function editStory(st) {
    document.getElementById('modal-story-title').textContent = `Редактирование: ${st.title}`;
    document.getElementById('st-id').value = st.id;
    document.getElementById('st-title').value = st.title;
    document.getElementById('st-lang').value = st.language_code;
    document.getElementById('st-level').value = st.level;
    document.getElementById('st-xp').value = st.xp_reward;
    document.getElementById('st-gems').value = st.gems_reward;
    document.getElementById('st-cover').value = st.cover_image || '';
    
    try {
        const parsed = JSON.parse(st.story_data);
        document.getElementById('st-data').value = JSON.stringify(parsed, null, 2);
    } catch(e) {
        document.getElementById('st-data').value = st.story_data;
    }

    document.getElementById('modal-story-edit').style.display = 'flex';
}

function closeStoryModal() {
    document.getElementById('modal-story-edit').style.display = 'none';
}

function openAiStoryModal() {
    document.getElementById('modal-ai-story').style.display = 'flex';
}

function closeAiStoryModal() {
    document.getElementById('modal-ai-story').style.display = 'none';
}

async function runAiStoryGeneration() {
    const lang = document.getElementById('ai-story-lang').value;
    const topic = document.getElementById('ai-story-topic').value.trim();
    const level = parseInt(document.getElementById('ai-story-level').value);
    const btn = document.getElementById('btn-run-ai-story');
    const statusDiv = document.getElementById('ai-story-status');

    if (!topic) {
        alert('Укажите тему для истории!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ пишет историю и генерирует вопросы...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_story', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lang, topic, level })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = '<span style="color: var(--primary); font-weight: 800;">🎉 История успешно создана! Обновление...</span>';
            setTimeout(() => location.reload(), 1000);
        } else {
            statusDiv.innerHTML = `<span style="color: var(--danger);">Ошибка: ${data.error}</span>`;
            btn.disabled = false;
        }
    } catch (e) {
        statusDiv.innerHTML = '<span style="color: var(--danger);">Ошибка сети.</span>';
        btn.disabled = false;
    }
}

function insertSampleStoryJson() {
    const sample = [
        {
            "character": "Shiba",
            "text": "Mira, Vladi! Zora bonu est.",
            "translation": "Привет, Влади! Сегодня хороший день.",
            "avatar": "🐕"
        },
        {
            "character": "Vladi",
            "text": "Mira, Shiba-sensei! Korno me vanti.",
            "translation": "Привет, Сиба-сэнсэй! Мое сердце радуется.",
            "avatar": "🧑"
        },
        {
            "type": "question",
            "prompt": "Что сказал Сиба в начале диалога?",
            "options": ["Пожелал доброго дня", "Попросил еды", "Сказал спокойной ночи"],
            "correct": 0
        },
        {
            "character": "Shiba",
            "text": "Danko! Vo-velo in Aero juntos!",
            "translation": "Спасибо! Давай полетим в небо вместе!",
            "avatar": "🐕"
        }
    ];
    document.getElementById('st-data').value = JSON.stringify(sample, null, 2);
}
</script>

<!-- Modal: AI Story Generator -->
<div id="modal-ai-story" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 550px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 Генератор историй через AI</h3>
            <button onclick="closeAiStoryModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Язык истории:</label>
            <select id="ai-story-lang" class="chat-input">
                <?php foreach ($languages as $l): ?>
                    <option value="<?= $l['code'] ?>"><?= $l['flag'] ?> <?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема / Сюжет:</label>
            <input type="text" id="ai-story-topic" class="chat-input" placeholder="Например: Загадка на рыночной площади, Полет на дирижабле...">
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Уровень сложности:</label>
            <select id="ai-story-level" class="chat-input">
                <option value="1">Уровень 1 (A1 - Начинающий)</option>
                <option value="2">Уровень 2 (A2 - Базовый)</option>
                <option value="3">Уровень 3 (B1 - Средний)</option>
            </select>
        </div>

        <div id="ai-story-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-story" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiStoryGeneration()">
            Сгенерировать и создать историю ✨
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
