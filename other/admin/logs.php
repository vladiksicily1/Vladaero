<?php
/**
 * Admin Audit Logs & Security Monitor
 */

$adminTitle = 'Журнал аудита и Логи безопасности';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_settings') && !hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$driver = Database::getDriver();
$message = '';

// Ensure system_logs table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action_type TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS `system_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL,
            `action_type` VARCHAR(64) NOT NULL,
            `details` TEXT,
            `ip_address` VARCHAR(45) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'clear_logs') {
        $db->exec("DELETE FROM system_logs");
        $message = 'Журнал аудита успешно очищен.';
    }
}

// Filter
$category = trim($_GET['cat'] ?? '');
$sql = "SELECT l.*, u.username FROM system_logs l LEFT JOIN " . tbl('users') . " u ON l.user_id = u.id";
$params = [];
if (!empty($category)) {
    $sql .= " WHERE l.action_type LIKE :cat";
    $params['cat'] = '%' . $category . '%';
}
$sql .= " ORDER BY l.id DESC LIMIT 150";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>🛡️</span> Журнал аудита и Логи безопасности
        </h1>
        <p style="color: var(--text-muted);">История действий администраторов, изменений уроков, начисления валюты и событий безопасности</p>
    </div>

    <form method="POST" onsubmit="return confirm('Очистить весь журнал логов?')">
        <input type="hidden" name="action" value="clear_logs">
        <button type="submit" class="btn-duo" style="padding: 10px 16px; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);">
            🗑️ Очистить журнал
        </button>
    </form>
</div>

<?php if (!empty($message)): ?>
    <div class="alert-duo alert-success" style="margin-bottom: 20px;"><?= e($message) ?></div>
<?php endif; ?>

<!-- Quick Filters -->
<div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
    <a href="logs.php" class="btn-duo <?= empty($category) ? 'btn-primary' : 'btn-outline' ?>" style="padding: 6px 14px; font-size: 0.85rem; text-decoration: none;">
        Все события (<?= count($logs) ?>)
    </a>
    <a href="logs.php?cat=auth" class="btn-duo <?= ($category === 'auth') ? 'btn-primary' : 'btn-outline' ?>" style="padding: 6px 14px; font-size: 0.85rem; text-decoration: none;">
        🔑 Вход / Сессии
    </a>
    <a href="logs.php?cat=admin" class="btn-duo <?= ($category === 'admin') ? 'btn-primary' : 'btn-outline' ?>" style="padding: 6px 14px; font-size: 0.85rem; text-decoration: none;">
        ⚙️ Действия админов
    </a>
    <a href="logs.php?cat=lesson" class="btn-duo <?= ($category === 'lesson') ? 'btn-primary' : 'btn-outline' ?>" style="padding: 6px 14px; font-size: 0.85rem; text-decoration: none;">
        📚 Уроки & Задания
    </a>
    <a href="logs.php?cat=ai" class="btn-duo <?= ($category === 'ai') ? 'btn-primary' : 'btn-outline' ?>" style="padding: 6px 14px; font-size: 0.85rem; text-decoration: none;">
        🤖 AI Генерации
    </a>
</div>

<!-- Logs Table -->
<div class="card-duo" style="padding: 24px;">
    <?php if (empty($logs)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 3rem; margin-bottom: 12px;">📋</div>
            <h3>Записей в журнале пока нет</h3>
            <p>Система автоматически логирует входы администраторов и ключевые операции в базе данных.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="dict-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Время</th>
                        <th>Пользователь</th>
                        <th>Категория</th>
                        <th>Подробности</th>
                        <th>IP Адрес</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td>#<?= (int)$l['id'] ?></td>
                            <td style="color: var(--text-muted); font-size: 0.85rem; white-space: nowrap;">
                                <?= date('d.m.Y H:i:s', strtotime($l['created_at'])) ?>
                            </td>
                            <td>
                                <strong><?= e($l['username'] ?? 'Система / Гость') ?></strong>
                            </td>
                            <td>
                                <span class="badge-tag" style="font-family: monospace; font-weight: 800;">
                                    <?= e($l['action_type']) ?>
                                </span>
                            </td>
                            <td style="font-size: 0.9rem; max-width: 380px; word-break: break-word;">
                                <?= e($l['details']) ?>
                            </td>
                            <td style="color: var(--text-muted); font-family: monospace; font-size: 0.85rem;">
                                <?= e($l['ip_address'] ?? '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
