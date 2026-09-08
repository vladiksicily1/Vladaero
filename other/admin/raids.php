<?php
/**
 * Admin Boss Raids Management
 */

$adminTitle = 'Управление Босс-рейдами';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_lessons') && !hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$driver = Database::getDriver();
$message = '';
$error = '';

// Ensure raid_bosses table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS raid_bosses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            boss_name TEXT NOT NULL,
            level INTEGER DEFAULT 50,
            max_hp INTEGER DEFAULT 10000,
            current_hp INTEGER DEFAULT 4250,
            total_hits INTEGER DEFAULT 0,
            last_hit_user_id INTEGER,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS `raid_bosses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `boss_name` VARCHAR(150) NOT NULL,
            `level` INT DEFAULT 50,
            `max_hp` INT DEFAULT 10000,
            `current_hp` INT DEFAULT 4250,
            `total_hits` INT DEFAULT 0,
            `last_hit_user_id` INT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_boss') {
        $bid = (int)($_POST['boss_id'] ?? 0);
        $name = trim($_POST['boss_name'] ?? '');
        $lvl = (int)($_POST['level'] ?? 50);
        $maxHp = (int)($_POST['max_hp'] ?? 10000);
        $curHp = (int)($_POST['current_hp'] ?? $maxHp);

        if (empty($name)) {
            $error = 'Укажите имя босса!';
        } else {
            if ($curHp > $maxHp) $curHp = $maxHp;
            if ($curHp < 0) $curHp = 0;

            if ($bid > 0) {
                $stmt = $db->prepare("UPDATE raid_bosses SET boss_name = :n, level = :l, max_hp = :m, current_hp = :c WHERE id = :id");
                $stmt->execute(['n' => $name, 'l' => $lvl, 'm' => $maxHp, 'c' => $curHp, 'id' => $bid]);
                $message = 'Параметры босса успешно обновлены!';
            } else {
                $stmt = $db->prepare("INSERT INTO raid_bosses (boss_name, level, max_hp, current_hp) VALUES (:n, :l, :m, :c)");
                $stmt->execute(['n' => $name, 'l' => $lvl, 'm' => $maxHp, 'c' => $curHp]);
                $message = 'Новый мировой босс создан!';
            }
        }
    }

    if ($action === 'reset_hp') {
        $bid = (int)$_POST['boss_id'];
        $db->exec("UPDATE raid_bosses SET current_hp = max_hp, total_hits = 0 WHERE id = {$bid}");
        $message = 'Здоровье босса полностью восстановлено (Рейд перезапущен)!';
    }

    if ($action === 'deal_damage') {
        $bid = (int)$_POST['boss_id'];
        $dmg = (int)$_POST['damage_amount'];
        $db->exec("UPDATE raid_bosses SET current_hp = MAX(0, current_hp - {$dmg}), total_hits = total_hits + 1 WHERE id = {$bid}");
        $message = "Боссу нанесено {$dmg} ед. урона!";
    }

    if ($action === 'delete_boss') {
        $bid = (int)$_POST['boss_id'];
        $db->exec("DELETE FROM raid_bosses WHERE id = {$bid}");
        $message = 'Босс удален.';
    }
}

$bosses = $db->query("SELECT * FROM raid_bosses ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>🐉</span> Управление Босс-рейдами
        </h1>
        <p style="color: var(--text-muted);">Настройка мировых рейдовых боссов, уровней здоровья и наград для учеников</p>
    </div>

    <button onclick="openBossModal(0, '', 50, 10000, 10000)" class="btn-duo btn-primary" style="padding: 10px 20px;">
        + Создать нового босса 🐲
    </button>
</div>

<?php if (!empty($message)): ?>
    <div class="alert-duo alert-success" style="margin-bottom: 20px;"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert-duo alert-danger" style="margin-bottom: 20px;"><?= e($error) ?></div>
<?php endif; ?>

<!-- Bosses Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px;">
    <?php if (empty($bosses)): ?>
        <div class="card-duo" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
            <div style="font-size: 3rem; margin-bottom: 12px;">🐉</div>
            <h3>Нет активных рейдовых боссов</h3>
            <p style="color: var(--text-muted);">Создайте первого босса, чтобы запустить глобальный рейд для учеников!</p>
        </div>
    <?php else: ?>
        <?php foreach ($bosses as $b): ?>
            <?php 
            $percent = round(($b['current_hp'] / max(1, $b['max_hp'])) * 100);
            $isDefeated = ($b['current_hp'] <= 0);
            ?>
            <div class="card-duo" style="border: 2px solid <?= $isDefeated ? 'var(--danger)' : 'var(--border-color)' ?>;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <div>
                        <span class="badge-tag" style="background: rgba(239,68,68,0.15); color: #ef4444; font-weight: 900;">
                            УРОВЕНЬ <?= (int)$b['level'] ?>
                        </span>
                        <h3 style="font-size: 1.3rem; font-weight: 900; margin-top: 6px;"><?= e($b['boss_name']) ?></h3>
                    </div>
                    <div style="font-size: 2.5rem;"><?= $isDefeated ? '💀' : '🐉' ?></div>
                </div>

                <!-- HP Bar -->
                <div style="margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 0.85rem; margin-bottom: 4px;">
                        <span>Здоровье (HP):</span>
                        <span><?= number_format($b['current_hp']) ?> / <?= number_format($b['max_hp']) ?> (<?= $percent ?>%)</span>
                    </div>
                    <div style="width: 100%; height: 14px; background: var(--border-color); border-radius: 10px; overflow: hidden;">
                        <div style="width: <?= $percent ?>%; height: 100%; background: <?= $isDefeated ? '#94a3b8' : 'linear-gradient(90deg, #ef4444, #f97316)' ?>; transition: width 0.3s;"></div>
                    </div>
                </div>

                <div style="font-size: 0.85rem; color: var(--text-muted); display: flex; gap: 16px; margin-bottom: 18px;">
                    <span>⚔️ Ударов нанесено: <strong><?= (int)$b['total_hits'] ?></strong></span>
                    <span>ID: <strong>#<?= (int)$b['id'] ?></strong></span>
                </div>

                <!-- Action Controls -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button onclick="openBossModal(<?= $b['id'] ?>, '<?= addslashes(e($b['boss_name'])) ?>', <?= (int)$b['level'] ?>, <?= (int)$b['max_hp'] ?>, <?= (int)$b['current_hp'] ?>)" class="btn-duo btn-outline" style="padding: 6px 12px; font-size: 0.85rem;">
                        ✏️ Изменить
                    </button>
                    
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Восстановить здоровье босса на 100%?')">
                        <input type="hidden" name="action" value="reset_hp">
                        <input type="hidden" name="boss_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="btn-duo btn-secondary" style="padding: 6px 12px; font-size: 0.85rem;">
                            🔄 Рестарт (100% HP)
                        </button>
                    </form>

                    <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить этого босса?')">
                        <input type="hidden" name="action" value="delete_boss">
                        <input type="hidden" name="boss_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="btn-duo" style="padding: 6px 12px; font-size: 0.85rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);">
                            🗑️
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal: Create / Edit Boss -->
<div id="boss-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; padding: 28px; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 id="modal-title" style="font-size: 1.3rem; font-weight: 900; margin: 0;">🐉 Рейдовый Босс</h3>
            <button onclick="closeBossModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--text-muted);">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save_boss">
            <input type="hidden" name="boss_id" id="modal-boss-id" value="0">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-weight: 800; font-size: 0.85rem; margin-bottom: 4px;">Имя босса:</label>
                <input type="text" name="boss_name" id="modal-boss-name" class="chat-input" placeholder="Например: Древний Дракон Ошибок" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-weight: 800; font-size: 0.85rem; margin-bottom: 4px;">Уровень босса:</label>
                    <input type="number" name="level" id="modal-boss-level" class="chat-input" min="1" value="50" required>
                </div>
                <div>
                    <label style="display: block; font-weight: 800; font-size: 0.85rem; margin-bottom: 4px;">Макс. HP:</label>
                    <input type="number" name="max_hp" id="modal-boss-max-hp" class="chat-input" min="100" value="10000" required>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 800; font-size: 0.85rem; margin-bottom: 4px;">Текущее HP:</label>
                <input type="number" name="current_hp" id="modal-boss-cur-hp" class="chat-input" min="0" value="10000" required>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeBossModal()" class="btn-duo btn-outline" style="padding: 10px 18px;">Отмена</button>
                <button type="submit" class="btn-duo btn-primary" style="padding: 10px 24px;">Сохранить босса 🚀</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBossModal(id, name, level, maxHp, curHp) {
    document.getElementById('modal-boss-id').value = id;
    document.getElementById('modal-boss-name').value = name;
    document.getElementById('modal-boss-level').value = level || 50;
    document.getElementById('modal-boss-max-hp').value = maxHp || 10000;
    document.getElementById('modal-boss-cur-hp').value = curHp || maxHp || 10000;
    document.getElementById('modal-title').textContent = id > 0 ? '✏️ Редактировать босса' : '🐲 Создать нового босса';
    document.getElementById('boss-modal').style.display = 'flex';
}
function closeBossModal() {
    document.getElementById('boss-modal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
