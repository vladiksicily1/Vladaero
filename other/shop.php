<?php
/**
 * ShibaLingo - Shiba Shop & Skins Customizer (Feature #2, #3)
 */

$pageTitle = 'Магазин и Скины Шибы';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$items = $db->query("SELECT * FROM shop_items ORDER BY price_gems ASC")->fetchAll();

$userGems = (int)($user['gems'] ?? 50);
$currentSkin = $user['selected_skin'] ?? 'classic';

// Get user owned items
$invStmt = $db->prepare("SELECT item_key, quantity FROM user_inventory WHERE user_id = :uid");
$invStmt->execute(['uid' => $user['id']]);
$userInventory = $invStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
?>

<div style="max-width: 900px; margin: 0 auto;">
    <!-- Top Header Card -->
    <div class="card-duo anim-bounce" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border: none; box-shadow: 0 6px 0 #1e40af;">
        <div>
            <h1 style="font-size: 1.8rem; font-weight: 900; margin-bottom: 4px;">
                🛍️ Магазин Шиба-Ину
            </h1>
            <p style="font-size: 1rem; opacity: 0.9;">
                Покупайте заморозку стрика, зелья опыта и уникальные костюмы для Сиба-сэнсэя!
            </p>
        </div>
        <div style="background: rgba(255,255,255,0.2); padding: 12px 24px; border-radius: 16px; display: flex; align-items: center; gap: 10px; font-size: 1.4rem; font-weight: 900;">
            <span>💎</span>
            <span id="shop-user-gems"><?= $userGems ?></span>
            <span style="font-size: 0.9rem; font-weight: 700; opacity: 0.9;">Кристаллов</span>
        </div>
    </div>

    <!-- Live Mascot Preview with Current Skin -->
    <div class="card-duo" style="display: flex; align-items: center; justify-content: space-around; flex-wrap: wrap; gap: 20px; text-align: center;">
        <div id="shop-mascot-preview"></div>
        <div>
            <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 6px;">Текущий образ маскота</h3>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 14px;">
                Скин: <strong style="color: var(--primary); text-transform: uppercase;" id="current-skin-name"><?= e($currentSkin) ?></strong>
            </p>
            <button class="btn-duo btn-outline" onclick="equipSkin('classic')">
                Сбросить на классический 🐾
            </button>
        </div>
    </div>

    <!-- Items Grid -->
    <h2 style="font-size: 1.4rem; font-weight: 800; margin: 28px 0 16px 0;">Товары и Усилители</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
        <?php foreach ($items as $it): 
            $isSkin = ($it['category'] === 'skin');
            $skinCode = str_replace('skin_', '', $it['item_key']);
            $isOwned = isset($userInventory[$it['item_key']]);
            $isEquipped = ($currentSkin === $skinCode);
        ?>
            <div class="card-duo" style="display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 0;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <span style="font-size: 2.6rem;"><?= $it['icon'] ?></span>
                        <span class="badge-tag" style="background: #e0f2fe; color: #0369a1; font-size: 0.9rem; font-weight: 800;">
                            💎 <?= (int)$it['price_gems'] ?>
                        </span>
                    </div>
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;"><?= e($it['name']) ?></h3>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 16px;">
                        <?= e($it['description']) ?>
                    </p>
                </div>

                <div>
                    <?php if ($isSkin && $isOwned): ?>
                        <?php if ($isEquipped): ?>
                            <button class="btn-duo btn-outline" style="width: 100%; border-color: var(--primary); color: var(--primary-shadow);" disabled>
                                ✓ Надето
                            </button>
                        <?php else: ?>
                            <button class="btn-duo btn-secondary" style="width: 100%;" onclick="equipSkin('<?= $skinCode ?>')">
                                Надеть скин
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <button class="btn-duo btn-primary" style="width: 100%;" onclick="buyItem('<?= $it['item_key'] ?>', <?= $it['price_gems'] ?>)">
                            Купить за 💎 <?= $it['price_gems'] ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    ShibaMascot.setSkin('<?= $currentSkin ?>');
    ShibaMascot.update('shop-mascot-preview', 'happy', 'Как тебе мой стиль? 🐶');
});

async function buyItem(itemKey, price) {
    try {
        const res = await fetch('api/shop_api.php?action=buy_item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ item_key: itemKey })
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('win');
            triggerConfetti();
            document.getElementById('shop-user-gems').textContent = data.new_gems;
            alert(data.message);
            location.reload();
        } else {
            SoundEngine.play('wrong');
            alert(data.error);
        }
    } catch(e) {
        alert('Ошибка связи с сервером');
    }
}

async function equipSkin(skin) {
    try {
        const res = await fetch('api/shop_api.php?action=equip_skin', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ skin: skin })
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            ShibaMascot.setSkin(skin);
            ShibaMascot.update('shop-mascot-preview', 'happy', 'Новый образ сидит идеально!', skin);
            document.getElementById('current-skin-name').textContent = skin;
            setTimeout(() => location.reload(), 800);
        }
    } catch(e) {
        alert('Ошибка при смене скина');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
