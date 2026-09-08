<?php
/**
 * Admin Promo Codes Manager (Feature #29)
 */

$adminTitle = 'Промокоды и Подарки';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? 'save_promo';

    if ($act === 'save_promo') {
        $pid = (int)($_POST['promo_id'] ?? 0);
        $code = strtoupper(trim($_POST['code']));
        $type = trim($_POST['reward_type']);
        $val = trim($_POST['reward_value']);
        $maxUses = (int)$_POST['max_uses'];
        $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

        if ($pid > 0) {
            $stmt = $db->prepare("UPDATE promo_codes SET code = :c, reward_type = :t, reward_value = :v, max_uses = :m, expires_at = :e WHERE id = :id");
            $stmt->execute(['c' => $code, 't' => $type, 'v' => $val, 'm' => $maxUses, 'e' => $expires, 'id' => $pid]);
            $message = "Промокод «{$code}» успешно обновлен!";
        } else {
            $stmt = $db->prepare("INSERT INTO promo_codes (code, reward_type, reward_value, max_uses, expires_at) VALUES (:c, :t, :v, :m, :e)");
            $stmt->execute(['c' => $code, 't' => $type, 'v' => $val, 'm' => $maxUses, 'e' => $expires]);
            $message = "Промокод «{$code}» успешно создан!";
        }
    }

    if ($act === 'delete_promo') {
        $delId = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM promo_codes WHERE id = :id");
        $stmt->execute(['id' => $delId]);
        $message = 'Промокод успешно удален.';
    }
}

$promos = $db->query("SELECT * FROM promo_codes ORDER BY id DESC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">🎁 Управление промокодами (Full CRUD & AI)</h1>
        <p style="color: var(--text-muted);">Создание, редактирование и удаление подарочных промокодов</p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openAiPromoModal()">
            🤖 Сгенерировать через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openPromoModal()">
            + Создать промокод
        </button>
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
                <th>Промокод</th>
                <th>Тип награды</th>
                <th>Значение</th>
                <th>Использовано</th>
                <th>Действителен до</th>
                <th style="text-align: right;">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($promos as $p): ?>
                <tr>
                    <td><code style="font-weight: 900; font-size: 1.05rem; color: var(--primary-shadow);"><?= e($p['code']) ?></code></td>
                    <td><span class="badge-tag"><?= e($p['reward_type']) ?></span></td>
                    <td style="font-weight: 800;">+<?= e($p['reward_value']) ?></td>
                    <td><?= $p['used_count'] ?> / <?= $p['max_uses'] ?></td>
                    <td style="color: var(--text-muted);"><?= $p['expires_at'] ? date('d.m.Y', strtotime($p['expires_at'])) : 'Бессрочно' ?></td>
                    <td style="text-align: right;">
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editPromo(<?= json_encode($p) ?>)'>✏️</button>
                        <form method="POST" style="display: inline-block;" onsubmit="return confirm('Удалить промокод <?= e($p['code']) ?>?');">
                            <input type="hidden" name="form_action" value="delete_promo">
                            <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn-duo btn-danger" style="padding: 4px 8px; font-size: 0.8rem;">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Create / Edit Promo -->
<div id="modal-promo-create" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="promo-modal-title" style="font-size: 1.3rem; font-weight: 800;">Новый промокод</h3>
            <button onclick="closePromoModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_promo">
            <input type="hidden" name="promo_id" id="p-id" value="0">

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Код промокода:</label>
                <input type="text" name="code" id="p-code" required class="chat-input" placeholder="SUMMER2026" style="text-transform: uppercase;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Тип награды:</label>
                    <select name="reward_type" id="p-type" class="chat-input">
                        <option value="gems">Кристаллы 💎</option>
                        <option value="xp">XP Очки ⚡</option>
                        <option value="hearts">Сердечки ❤️</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Количество / Значение:</label>
                    <input type="text" name="reward_value" id="p-val" value="100" required class="chat-input">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Лимит активаций:</label>
                    <input type="number" name="max_uses" id="p-max" value="100" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Дата окончания:</label>
                    <input type="date" name="expires_at" id="p-exp" class="chat-input">
                </div>
            </div>

            <button type="submit" id="p-submit-btn" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить промокод 🎁
            </button>
        </form>
    </div>
</div>

<script>
function openPromoModal() {
    document.getElementById('promo-modal-title').textContent = 'Новый промокод';
    document.getElementById('p-id').value = '0';
    document.getElementById('p-code').value = '';
    document.getElementById('p-type').value = 'gems';
    document.getElementById('p-val').value = '100';
    document.getElementById('p-max').value = '100';
    document.getElementById('p-exp').value = '';
    document.getElementById('p-submit-btn').textContent = 'Создать промокод 🎁';
    document.getElementById('modal-promo-create').style.display = 'flex';
}
function closePromoModal() { document.getElementById('modal-promo-create').style.display = 'none'; }
function openAiPromoModal() { document.getElementById('modal-ai-promo').style.display = 'flex'; }
function closeAiPromoModal() { document.getElementById('modal-ai-promo').style.display = 'none'; }

function editPromo(p) {
    document.getElementById('promo-modal-title').textContent = 'Редактировать промокод';
    document.getElementById('p-id').value = p.id;
    document.getElementById('p-code').value = p.code;
    document.getElementById('p-type').value = p.reward_type;
    document.getElementById('p-val').value = p.reward_value;
    document.getElementById('p-max').value = p.max_uses;
    document.getElementById('p-exp').value = p.expires_at ? p.expires_at.split(' ')[0] : '';
    document.getElementById('p-submit-btn').textContent = 'Сохранить изменения 💾';
    document.getElementById('modal-promo-create').style.display = 'flex';
}

async function runAiPromoGeneration() {
    const theme = document.getElementById('ai-promo-theme').value.trim();
    const count = parseInt(document.getElementById('ai-promo-count').value);
    const btn = document.getElementById('btn-run-ai-promo');
    const statusDiv = document.getElementById('ai-promo-status');

    if (!theme) {
        alert('Укажите повод для промокодов!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ генерирует промокоды и награды...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_promocodes', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme, count })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = `<span style="color: var(--primary); font-weight: 800;">🎉 Создано ${data.added_count} промокодов! Обновление...</span>`;
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

<!-- Modal: AI Promo Generator -->
<div id="modal-ai-promo" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 480px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 AI Генератор промокодов</h3>
            <button onclick="closeAiPromoModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Повод / Ивент:</label>
            <input type="text" id="ai-promo-theme" class="chat-input" placeholder="Например: Запуск нового языка, Весенний бонус, Стрим...">
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Количество кодов:</label>
            <select id="ai-promo-count" class="chat-input">
                <option value="3" selected>3 промокода</option>
                <option value="5">5 промокодов</option>
            </select>
        </div>

        <div id="ai-promo-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-promo" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiPromoGeneration()">
            Сгенерировать и активировать промокоды ✨
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
