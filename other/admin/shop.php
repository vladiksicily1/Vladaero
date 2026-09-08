<?php
/**
 * Admin Shop Items & Skins Management (CRUD)
 */

$adminTitle = 'Товары магазина';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_lessons') && !hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $key = trim($_POST['item_key']);
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $icon = trim($_POST['icon']);
        $price = (int)$_POST['price_gems'];
        $cat = trim($_POST['category']);
        $effect = trim($_POST['effect_value']);

        if (empty($key) || empty($name)) {
            $error = 'Укажите уникальный ключ и название товара!';
        } else {
            if ($itemId > 0) {
                $stmt = $db->prepare("UPDATE " . tbl('shop_items') . " SET item_key = :k, name = :n, description = :d, icon = :i, price_gems = :p, category = :c, effect_value = :e WHERE id = :id");
                $stmt->execute(['k' => $key, 'n' => $name, 'd' => $desc, 'i' => $icon, 'p' => $price, 'c' => $cat, 'e' => $effect, 'id' => $itemId]);
                $message = 'Товар успешно обновлен!';
            } else {
                $stmt = $db->prepare("INSERT INTO " . tbl('shop_items') . " (item_key, name, description, icon, price_gems, category, effect_value) VALUES (:k, :n, :d, :i, :p, :c, :e)");
                $stmt->execute(['k' => $key, 'n' => $name, 'd' => $desc, 'i' => $icon, 'p' => $price, 'c' => $cat, 'e' => $effect]);
                $message = 'Новый товар успешно добавлен!';
            }
        }
    }

    if ($act === 'delete_item') {
        $itemId = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM " . tbl('shop_items') . " WHERE id = :id");
        $stmt->execute(['id' => $itemId]);
        $message = 'Товар удален из магазина.';
    }
}

$items = $db->query("SELECT * FROM " . tbl('shop_items') . " ORDER BY id ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">🛍️ Управление товарами магазина и скинами (Shop CRUD & AI)</h1>
        <p style="color: var(--text-muted);">Настройка цен в кристаллах 💎, добавление бустеров и AI-генерация новых образов Сибы</p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openAiShopModal()">
            🤖 Сгенерировать через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openShopModal()">
            + Добавить товар
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
                <th>Ключ (Key)</th>
                <th>Название</th>
                <th>Категория</th>
                <th>Цена (Gems)</th>
                <th>Эффект / Значение</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td style="font-size: 1.8rem; text-align: center;"><?= $it['icon'] ?></td>
                    <td><code><?= e($it['item_key']) ?></code></td>
                    <td style="font-weight: 800; font-size: 1.05rem;"><?= e($it['name']) ?><br><span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);"><?= e($it['description']) ?></span></td>
                    <td><span class="badge-tag"><?= e($it['category']) ?></span></td>
                    <td style="color: var(--secondary); font-weight: 900;">💎 <?= (int)$it['price_gems'] ?></td>
                    <td><code><?= e($it['effect_value'] ?? '—') ?></code></td>
                    <td style="display: flex; gap: 6px;">
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editShopItem(<?= json_encode($it) ?>)' title="Редактировать">
                            ✏️
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить товар?');">
                            <input type="hidden" name="form_action" value="delete_item">
                            <input type="hidden" name="delete_id" value="<?= $it['id'] ?>">
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

<!-- Modal: Shop Item Editor -->
<div id="modal-shop-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 600px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-shop-title" style="font-size: 1.3rem; font-weight: 800;">Редактор товара</h3>
            <button onclick="closeShopModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_item">
            <input type="hidden" name="item_id" id="sh-id" value="0">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Уникальный ключ (Slug):</label>
                    <input type="text" name="item_key" id="sh-key" required class="chat-input" placeholder="skin_wizard">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Иконка (Emoji / Символ):</label>
                    <input type="text" name="icon" id="sh-icon" required class="chat-input" placeholder="🧙‍♂️">
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Название товара:</label>
                <input type="text" name="name" id="sh-name" required class="chat-input" placeholder="Скин: Шиба-Волшебник 🧙‍♂️">
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Описание:</label>
                <input type="text" name="description" id="sh-desc" required class="chat-input" placeholder="Магическая мантия и колдовской посох">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Цена (Gems 💎):</label>
                    <input type="number" name="price_gems" id="sh-price" value="100" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Категория:</label>
                    <select name="category" id="sh-cat" class="chat-input">
                        <option value="booster">Бустер (booster)</option>
                        <option value="skin">Скин (skin)</option>
                        <option value="potion">Зелье (potion)</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Значение эффекта:</label>
                    <input type="text" name="effect_value" id="sh-effect" class="chat-input" placeholder="wizard или freeze_1d">
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить товар 💾
            </button>
        </form>
    </div>
</div>

<script>
function openShopModal() {
    document.getElementById('modal-shop-title').textContent = 'Добавление товара в магазин';
    document.getElementById('sh-id').value = '0';
    document.getElementById('sh-key').value = '';
    document.getElementById('sh-icon').value = '🎁';
    document.getElementById('sh-name').value = '';
    document.getElementById('sh-desc').value = '';
    document.getElementById('sh-price').value = '50';
    document.getElementById('sh-cat').value = 'skin';
    document.getElementById('sh-effect').value = '';
    document.getElementById('modal-shop-edit').style.display = 'flex';
}

function editShopItem(it) {
    document.getElementById('modal-shop-title').textContent = `Редактирование: ${it.name}`;
    document.getElementById('sh-id').value = it.id;
    document.getElementById('sh-key').value = it.item_key;
    document.getElementById('sh-icon').value = it.icon;
    document.getElementById('sh-name').value = it.name;
    document.getElementById('sh-desc').value = it.description;
    document.getElementById('sh-price').value = it.price_gems;
    document.getElementById('sh-cat').value = it.category;
    document.getElementById('sh-effect').value = it.effect_value || '';
    document.getElementById('modal-shop-edit').style.display = 'flex';
}

function closeShopModal() {
    document.getElementById('modal-shop-edit').style.display = 'none';
}

function openAiShopModal() {
    document.getElementById('modal-ai-shop').style.display = 'flex';
}

function closeAiShopModal() {
    document.getElementById('modal-ai-shop').style.display = 'none';
}

async function runAiShopGeneration() {
    const theme = document.getElementById('ai-shop-theme').value.trim();
    const count = parseInt(document.getElementById('ai-shop-count').value);
    const btn = document.getElementById('btn-run-ai-shop');
    const statusDiv = document.getElementById('ai-shop-status');

    if (!theme) {
        alert('Укажите тему для коллекции товаров!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ создает скины, цены и эффекты...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_shop_items', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme, count })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = `<span style="color: var(--primary); font-weight: 800;">🎉 Создано ${data.added_count} товаров! Обновление...</span>`;
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

<!-- Modal: AI Shop Generator -->
<div id="modal-ai-shop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 AI Генератор товаров и скинов</h3>
            <button onclick="closeAiShopModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема коллекции:</label>
            <input type="text" id="ai-shop-theme" class="chat-input" placeholder="Например: Самураи, Киберпанк, Зимний праздник...">
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Количество:</label>
            <select id="ai-shop-count" class="chat-input">
                <option value="3" selected>3 товара</option>
                <option value="5">5 товаров</option>
            </select>
        </div>

        <div id="ai-shop-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-shop" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiShopGeneration()">
            Сгенерировать и добавить товары ✨
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
