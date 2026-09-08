<?php
/**
 * Admin Achievements & Badges Management (CRUD)
 */

$adminTitle = 'Достижения и бейджи';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_lessons') && !hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_achievement') {
        $achId = (int)($_POST['ach_id'] ?? 0);
        $slug = trim($_POST['slug']);
        $title = trim($_POST['title']);
        $desc = trim($_POST['description']);
        $icon = trim($_POST['icon']);
        $xp = (int)$_POST['xp_reward'];
        $gems = (int)$_POST['gems_reward'];
        $reqType = trim($_POST['req_type']);
        $reqVal = (int)$_POST['req_value'];

        if (empty($slug) || empty($title)) {
            $error = 'Укажите уникальный slug и название достижения!';
        } else {
            if ($achId > 0) {
                $stmt = $db->prepare("UPDATE " . tbl('achievements') . " SET slug = :s, title = :t, description = :d, icon = :i, xp_reward = :x, gems_reward = :g, req_type = :rt, req_value = :rv WHERE id = :id");
                $stmt->execute(['s' => $slug, 't' => $title, 'd' => $desc, 'i' => $icon, 'x' => $xp, 'g' => $gems, 'rt' => $reqType, 'rv' => $reqVal, 'id' => $achId]);
                $message = 'Достижение успешно обновлено!';
            } else {
                $stmt = $db->prepare("INSERT INTO " . tbl('achievements') . " (slug, title, description, icon, xp_reward, gems_reward, req_type, req_value) VALUES (:s, :t, :d, :i, :x, :g, :rt, :rv)");
                $stmt->execute(['s' => $slug, 't' => $title, 'd' => $desc, 'i' => $icon, 'x' => $xp, 'g' => $gems, 'rt' => $reqType, 'rv' => $reqVal]);
                $message = 'Новое достижение успешно создано!';
            }
        }
    }

    if ($act === 'delete_achievement') {
        $achId = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM " . tbl('achievements') . " WHERE id = :id");
        $stmt->execute(['id' => $achId]);
        $message = 'Достижение удалено.';
    }
}

$achievements = $db->query("SELECT a.*, (SELECT COUNT(*) FROM " . tbl('user_achievements') . " WHERE achievement_id = a.id) as unlock_count FROM " . tbl('achievements') . " a ORDER BY a.id ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">🏅 Управление достижениями (Achievements CRUD & AI)</h1>
        <p style="color: var(--text-muted);">Настройка наград, условий разблокировки и AI-генерация бейджей</p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openAiAchModal()">
            🤖 Сгенерировать через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openAchModal()">
            + Создать достижение
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
                <th>Иконка</th>
                <th>Slug</th>
                <th>Название</th>
                <th>Условие (Требование)</th>
                <th>Награда</th>
                <th>Разблокировано</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($achievements as $ach): ?>
                <tr>
                    <td style="font-size: 1.8rem; text-align: center;"><?= $ach['icon'] ?></td>
                    <td><code><?= e($ach['slug']) ?></code></td>
                    <td style="font-weight: 800; font-size: 1.05rem;"><?= e($ach['title']) ?><br><span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);"><?= e($ach['description']) ?></span></td>
                    <td><span class="badge-tag"><?= e($ach['req_type']) ?> ≥ <?= (int)$ach['req_value'] ?></span></td>
                    <td style="color: #eab308; font-weight: 800;">⚡ +<?= (int)$ach['xp_reward'] ?> XP / 💎 +<?= (int)$ach['gems_reward'] ?></td>
                    <td><span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);"><?= (int)$ach['unlock_count'] ?> чел.</span></td>
                    <td style="display: flex; gap: 6px;">
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editAch(<?= json_encode($ach) ?>)' title="Редактировать">
                            ✏️
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить достижение?');">
                            <input type="hidden" name="form_action" value="delete_achievement">
                            <input type="hidden" name="delete_id" value="<?= $ach['id'] ?>">
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

<!-- Modal: Achievement Editor -->
<div id="modal-ach-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 600px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-ach-title" style="font-size: 1.3rem; font-weight: 800;">Редактор достижения</h3>
            <button onclick="closeAchModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_achievement">
            <input type="hidden" name="ach_id" id="ach-id" value="0">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Уникальный Slug:</label>
                    <input type="text" name="slug" id="ach-slug" required class="chat-input" placeholder="streak_7">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Иконка:</label>
                    <input type="text" name="icon" id="ach-icon" required class="chat-input" placeholder="🔥">
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Название достижения:</label>
                <input type="text" name="title" id="ach-title" required class="chat-input" placeholder="Огненная неделя">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Описание:</label>
                <input type="text" name="description" id="ach-desc" required class="chat-input" placeholder="Занимайтесь 7 дней подряд без перерыва">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Тип требования (req_type):</label>
                    <select name="req_type" id="ach-req-type" class="chat-input">
                        <option value="streak">Дней стрика (streak)</option>
                        <option value="lessons_count">Пройдено уроков (lessons_count)</option>
                        <option value="xp_total">Всего XP (xp_total)</option>
                        <option value="words_count">Выучено слов (words_count)</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Целевое число:</label>
                    <input type="number" name="req_value" id="ach-req-val" value="7" class="chat-input">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Награда XP:</label>
                    <input type="number" name="xp_reward" id="ach-xp" value="50" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Награда Gems 💎:</label>
                    <input type="number" name="gems_reward" id="ach-gems" value="20" class="chat-input">
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить достижение 💾
            </button>
        </form>
    </div>
</div>

<script>
function openAchModal() {
    document.getElementById('modal-ach-title').textContent = 'Создание нового достижения';
    document.getElementById('ach-id').value = '0';
    document.getElementById('ach-slug').value = '';
    document.getElementById('ach-icon').value = '🏆';
    document.getElementById('ach-title').value = '';
    document.getElementById('ach-desc').value = '';
    document.getElementById('ach-req-type').value = 'lessons_count';
    document.getElementById('ach-req-val').value = '5';
    document.getElementById('ach-xp').value = '50';
    document.getElementById('ach-gems').value = '20';
    document.getElementById('modal-ach-edit').style.display = 'flex';
}

function editAch(ach) {
    document.getElementById('modal-ach-title').textContent = `Редактирование: ${ach.title}`;
    document.getElementById('ach-id').value = ach.id;
    document.getElementById('ach-slug').value = ach.slug;
    document.getElementById('ach-icon').value = ach.icon;
    document.getElementById('ach-title').value = ach.title;
    document.getElementById('ach-desc').value = ach.description;
    document.getElementById('ach-req-type').value = ach.req_type;
    document.getElementById('ach-req-val').value = ach.req_value;
    document.getElementById('ach-xp').value = ach.xp_reward;
    document.getElementById('ach-gems').value = ach.gems_reward;
    document.getElementById('modal-ach-edit').style.display = 'flex';
}

function closeAchModal() {
    document.getElementById('modal-ach-edit').style.display = 'none';
}

function openAiAchModal() {
    document.getElementById('modal-ai-ach').style.display = 'flex';
}

function closeAiAchModal() {
    document.getElementById('modal-ai-ach').style.display = 'none';
}

async function runAiAchGeneration() {
    const theme = document.getElementById('ai-ach-theme').value.trim();
    const count = parseInt(document.getElementById('ai-ach-count').value);
    const btn = document.getElementById('btn-run-ai-ach');
    const statusDiv = document.getElementById('ai-ach-status');

    if (!theme) {
        alert('Укажите тематику для достижений!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ придумывает бейджи и условия...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_achievements', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme, count })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = `<span style="color: var(--primary); font-weight: 800;">🎉 Создано ${data.added_count} достижений! Обновление...</span>`;
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

<!-- Modal: AI Achievement Generator -->
<div id="modal-ai-ach" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 AI Генератор достижений</h3>
            <button onclick="closeAiAchModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тематика ачивок:</label>
            <input type="text" id="ai-ach-theme" class="chat-input" placeholder="Например: Магия conlang, Непобедимый дуэлянт, Дружба...">
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Количество:</label>
            <select id="ai-ach-count" class="chat-input">
                <option value="3" selected>3 достижения</option>
                <option value="5">5 достижений</option>
            </select>
        </div>

        <div id="ai-ach-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-ach" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiAchGeneration()">
            Сгенерировать и добавить достижения ✨
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
