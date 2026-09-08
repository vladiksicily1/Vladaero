<?php
/**
 * Admin PvP Duels & Matchmaking Monitor
 */

$adminTitle = 'Мониторинг PvP Дуэлей';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$driver = Database::getDriver();
$message = '';

// Ensure duel_rooms table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS duel_rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_code TEXT NOT NULL UNIQUE,
            host_user_id INTEGER NOT NULL,
            guest_user_id INTEGER,
            language_code TEXT DEFAULT 'vladikish',
            status TEXT DEFAULT 'waiting',
            questions_data TEXT NOT NULL,
            host_score INTEGER DEFAULT 0,
            guest_score INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (Exception $e) {}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'close_room') {
        $rid = (int)$_POST['room_id'];
        $db->exec("UPDATE duel_rooms SET status = 'finished' WHERE id = {$rid}");
        $message = 'Комната дуэли завершена.';
    }

    if ($action === 'delete_room') {
        $rid = (int)$_POST['room_id'];
        $db->exec("DELETE FROM duel_rooms WHERE id = {$rid}");
        $message = 'Запись дуэли удалена.';
    }

    if ($action === 'clear_stale') {
        $db->exec("DELETE FROM duel_rooms WHERE status = 'waiting' AND created_at < datetime('now', '-2 hours')");
        $message = 'Все зависшие комнаты ожидания старше 2 часов удалены!';
    }
}

// Fetch Duel Rooms with host and guest user names
$rooms = [];
try {
    $rStmt = $db->query("
        SELECT d.*, 
               u1.username as host_name, 
               u2.username as guest_name
        FROM duel_rooms d
        LEFT JOIN " . tbl('users') . " u1 ON d.host_user_id = u1.id
        LEFT JOIN " . tbl('users') . " u2 ON d.guest_user_id = u2.id
        ORDER BY d.id DESC
        LIMIT 100
    ");
    $rooms = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Stats
$activeCount = count(array_filter($rooms, fn($r) => $r['status'] === 'waiting' || $r['status'] === 'playing'));
$finishedCount = count(array_filter($rooms, fn($r) => $r['status'] === 'finished'));
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>⚔️</span> Мониторинг PvP Дуэлей
        </h1>
        <p style="color: var(--text-muted);">Просмотр лобби, результатов 1v1 сражений и защита от накрутки</p>
    </div>

    <form method="POST" onsubmit="return confirm('Очистить все старые зависшие комнаты?')">
        <input type="hidden" name="action" value="clear_stale">
        <button type="submit" class="btn-duo btn-outline" style="padding: 10px 16px;">
            🧹 Очистить зависшие комнаты
        </button>
    </form>
</div>

<?php if (!empty($message)): ?>
    <div class="alert-duo alert-success" style="margin-bottom: 20px;"><?= e($message) ?></div>
<?php endif; ?>

<!-- Quick Metrics -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; color: var(--primary); font-weight: 900;"><?= $activeCount ?></div>
        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Активных лобби</div>
    </div>
    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; color: #a855f7; font-weight: 900;"><?= $finishedCount ?></div>
        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Завершенных матчей</div>
    </div>
    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; color: var(--secondary); font-weight: 900;"><?= count($rooms) ?></div>
        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Всего в истории</div>
    </div>
</div>

<!-- Duels Table -->
<div class="card-duo" style="padding: 24px;">
    <h3 style="font-size: 1.25rem; font-weight: 900; margin-bottom: 16px;">
        🎮 Журнал дуэлей (Последние 100 матчей)
    </h3>

    <?php if (empty($rooms)): ?>
        <div style="text-align: center; padding: 30px; color: var(--text-muted);">
            Дуэлей пока не проводилось.
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="dict-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Код комнаты</th>
                        <th>Создатель (Хост)</th>
                        <th>Оппонент</th>
                        <th>Счет матча</th>
                        <th>Статус</th>
                        <th>Дата создания</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $r): ?>
                        <tr>
                            <td>
                                <span class="badge-tag" style="background: rgba(168,85,247,0.15); color: #a855f7; font-family: monospace; font-weight: 900;">
                                    <?= e($r['room_code']) ?>
                                </span>
                            </td>
                            <td style="font-weight: 800;">
                                <?= e($r['host_name'] ?? 'ID #' . $r['host_user_id']) ?>
                            </td>
                            <td>
                                <?= !empty($r['guest_name']) ? '<strong>' . e($r['guest_name']) . '</strong>' : '<span style="color: var(--text-muted);">Ожидание игрока...</span>' ?>
                            </td>
                            <td>
                                <span style="font-weight: 900; font-size: 1.05rem;">
                                    <?= (int)$r['host_score'] ?> : <?= (int)$r['guest_score'] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'playing'): ?>
                                    <span class="badge-tag" style="background: rgba(88,204,2,0.15); color: var(--primary); font-weight: 800;">🟢 В процессе</span>
                                <?php elseif ($r['status'] === 'waiting'): ?>
                                    <span class="badge-tag" style="background: rgba(234,179,8,0.15); color: #854d0e; font-weight: 800;">⏳ Ожидание</span>
                                <?php else: ?>
                                    <span class="badge-tag" style="background: rgba(100,116,139,0.15); color: #64748b; font-weight: 800;">✓ Завершен</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></td>
                            <td>
                                <div style="display: flex; gap: 6px;">
                                    <?php if ($r['status'] !== 'finished'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="close_room">
                                            <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" title="Завершить матч">
                                                Закрыть
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить эту запись дуэли?')">
                                        <input type="hidden" name="action" value="delete_room">
                                        <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn-duo" style="padding: 4px 8px; font-size: 0.8rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);" title="Удалить">
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
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
