<?php
/**
 * Admin Daily Quests Management (CRUD)
 */

$adminTitle = 'Ежедневные квесты';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_lessons') && !hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_quest') {
        $qid = (int)($_POST['quest_id'] ?? 0);
        $title = trim($_POST['title']);
        $desc = trim($_POST['description']);
        $reqType = trim($_POST['req_type']);
        $reqTarget = (int)$_POST['req_target'];
        $xp = (int)$_POST['xp_reward'];
        $gems = (int)$_POST['gems_reward'];

        if (empty($title)) {
            $error = 'Укажите название квеста!';
        } else {
            if ($qid > 0) {
                $stmt = $db->prepare("UPDATE " . tbl('daily_quests') . " SET title = :t, description = :d, req_type = :rt, req_target = :tar, xp_reward = :x, gems_reward = :g WHERE id = :id");
                $stmt->execute(['t' => $title, 'd' => $desc, 'rt' => $reqType, 'tar' => $reqTarget, 'x' => $xp, 'g' => $gems, 'id' => $qid]);
                $message = 'Квест успешно обновлен!';
            } else {
                $stmt = $db->prepare("INSERT INTO " . tbl('daily_quests') . " (title, description, req_type, req_target, xp_reward, gems_reward) VALUES (:t, :d, :rt, :tar, :x, :g)");
                $stmt->execute(['t' => $title, 'd' => $desc, 'rt' => $reqType, 'tar' => $reqTarget, 'x' => $xp, 'g' => $gems]);
                $message = 'Новый ежедневный квест создан!';
            }
        }
    }

    if ($act === 'delete_quest') {
        $qid = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM " . tbl('daily_quests') . " WHERE id = :id");
        $stmt->execute(['id' => $qid]);
        $message = 'Квест удален.';
    }
}

$quests = $db->query("SELECT * FROM " . tbl('daily_quests') . " ORDER BY id ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">🎯 Управление ежедневными квестами (Quests CRUD & AI)</h1>
        <p style="color: var(--text-muted);">Настройка заданий для учеников, ежедневных целей и AI-генерация квестов</p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openAiQuestModal()">
            🤖 Сгенерировать через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openQuestModal()">
            + Создать квест
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
                <th>Название</th>
                <th>Описание</th>
                <th>Тип цели</th>
                <th>Цель (Target)</th>
                <th>Награда</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($quests as $q): ?>
                <tr>
                    <td>#<?= $q['id'] ?></td>
                    <td style="font-weight: 800; font-size: 1.05rem;"><?= e($q['title']) ?></td>
                    <td style="color: var(--text-muted);"><?= e($q['description']) ?></td>
                    <td><span class="badge-tag"><?= e($q['req_type']) ?></span></td>
                    <td style="font-weight: 800; color: var(--primary);"><?= (int)$q['req_target'] ?> раз</td>
                    <td style="color: #eab308; font-weight: 800;">⚡ +<?= (int)$q['xp_reward'] ?> XP / 💎 +<?= (int)$q['gems_reward'] ?></td>
                    <td style="display: flex; gap: 6px;">
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editQuest(<?= json_encode($q) ?>)' title="Редактировать">
                            ✏️
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить квест?');">
                            <input type="hidden" name="form_action" value="delete_quest">
                            <input type="hidden" name="delete_id" value="<?= $q['id'] ?>">
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

<!-- Modal: Quest Editor -->
<div id="modal-quest-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 580px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-quest-title" style="font-size: 1.3rem; font-weight: 800;">Редактор квеста</h3>
            <button onclick="closeQuestModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_quest">
            <input type="hidden" name="quest_id" id="q-id" value="0">

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Название квеста:</label>
                <input type="text" name="title" id="q-title" required class="chat-input" placeholder="Пройти 2 урока">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Описание:</label>
                <input type="text" name="description" id="q-desc" required class="chat-input" placeholder="Завершите любые два интерактивных урока сегодня">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Тип действия (req_type):</label>
                    <select name="req_type" id="q-type" class="chat-input">
                        <option value="complete_lesson">Пройти уроки (complete_lesson)</option>
                        <option value="earn_xp">Набрать XP (earn_xp)</option>
                        <option value="chat_message">Сообщение в чат (chat_message)</option>
                        <option value="match_blitz">Сыграть в Блиц (match_blitz)</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Целевое число повторений:</label>
                    <input type="number" name="req_target" id="q-target" value="2" class="chat-input">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Награда XP:</label>
                    <input type="number" name="xp_reward" id="q-xp" value="25" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Награда Gems 💎:</label>
                    <input type="number" name="gems_reward" id="q-gems" value="10" class="chat-input">
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить квест 💾
            </button>
        </form>
    </div>
</div>

<script>
function openQuestModal() {
    document.getElementById('modal-quest-title').textContent = 'Создание нового квеста';
    document.getElementById('q-id').value = '0';
    document.getElementById('q-title').value = '';
    document.getElementById('q-desc').value = '';
    document.getElementById('q-type').value = 'complete_lesson';
    document.getElementById('q-target').value = '1';
    document.getElementById('q-xp').value = '25';
    document.getElementById('q-gems').value = '10';
    document.getElementById('modal-quest-edit').style.display = 'flex';
}

function editQuest(q) {
    document.getElementById('modal-quest-title').textContent = `Редактирование: ${q.title}`;
    document.getElementById('q-id').value = q.id;
    document.getElementById('q-title').value = q.title;
    document.getElementById('q-desc').value = q.description;
    document.getElementById('q-type').value = q.req_type;
    document.getElementById('q-target').value = q.req_target;
    document.getElementById('q-xp').value = q.xp_reward;
    document.getElementById('q-gems').value = q.gems_reward;
    document.getElementById('modal-quest-edit').style.display = 'flex';
}

function closeQuestModal() {
    document.getElementById('modal-quest-edit').style.display = 'none';
}

function openAiQuestModal() {
    document.getElementById('modal-ai-quest').style.display = 'flex';
}

function closeAiQuestModal() {
    document.getElementById('modal-ai-quest').style.display = 'none';
}

async function runAiQuestGeneration() {
    const count = parseInt(document.getElementById('ai-quest-count').value);
    const btn = document.getElementById('btn-run-ai-quest');
    const statusDiv = document.getElementById('ai-quest-status');

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ генерирует задания и цели...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_quests', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ count })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = `<span style="color: var(--primary); font-weight: 800;">🎉 Создано ${data.added_count} квестов! Обновление...</span>`;
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
</script>

<!-- Modal: AI Quest Generator -->
<div id="modal-ai-quest" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 480px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 AI Генератор ежедневных квестов</h3>
            <button onclick="closeAiQuestModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Количество квестов:</label>
            <select id="ai-quest-count" class="chat-input">
                <option value="3" selected>3 квеста</option>
                <option value="5">5 квестов</option>
            </select>
        </div>

        <div id="ai-quest-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-quest" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiQuestGeneration()">
            Сгенерировать и добавить квесты ✨
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
