<?php
/**
 * Admin Lesson Bug Reports & User Feedback
 */

$adminTitle = 'Репорты об ошибках в уроках';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_lessons')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$driver = Database::getDriver();
$message = '';

// Ensure lesson_reports table exists
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS lesson_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            lesson_id INTEGER,
            exercise_index INTEGER DEFAULT 0,
            report_type TEXT DEFAULT 'typo',
            comment TEXT NOT NULL,
            status TEXT DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS `lesson_reports` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL,
            `lesson_id` INT NULL,
            `exercise_index` INT DEFAULT 0,
            `report_type` VARCHAR(64) DEFAULT 'typo',
            `comment` TEXT NOT NULL,
            `status` VARCHAR(32) DEFAULT 'open',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'update_status') {
        $rid = (int)$_POST['report_id'];
        $newStatus = trim($_POST['status']);
        $stmt = $db->prepare("UPDATE lesson_reports SET status = :s WHERE id = :id");
        $stmt->execute(['s' => $newStatus, 'id' => $rid]);
        $message = 'Статус репорта обновлен!';
    }

    if ($act === 'delete_report') {
        $rid = (int)$_POST['report_id'];
        $db->exec("DELETE FROM lesson_reports WHERE id = {$rid}");
        $message = 'Репорт удален.';
    }
}

// Fetch Reports with user and lesson titles
$reports = [];
try {
    $rStmt = $db->query("
        SELECT r.*, u.username, u.email, l.title as lesson_title
        FROM lesson_reports r
        LEFT JOIN " . tbl('users') . " u ON r.user_id = u.id
        LEFT JOIN " . tbl('lessons') . " l ON r.lesson_id = l.id
        ORDER BY r.id DESC
        LIMIT 100
    ");
    $reports = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$openCount = count(array_filter($reports, fn($r) => $r['status'] === 'open'));
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>🚩</span> Репорты об ошибках в уроках
        </h1>
        <p style="color: var(--text-muted);">Жалобы учеников на опечатки, спорные переводы и неточности в упражнениях</p>
    </div>

    <span class="badge-tag" style="background: <?= $openCount > 0 ? 'var(--danger-light); color: var(--danger-shadow);' : 'var(--primary-light); color: var(--primary-shadow);' ?> font-size: 0.95rem; padding: 8px 16px; font-weight: 800;">
        <?= $openCount > 0 ? "⚠️ Открытых жалоб: {$openCount}" : "✓ Все жалобы решены" ?>
    </span>
</div>

<?php if (!empty($message)): ?>
    <div class="alert-duo alert-success" style="margin-bottom: 20px;"><?= e($message) ?></div>
<?php endif; ?>

<!-- Reports Table -->
<div class="card-duo" style="padding: 24px;">
    <?php if (empty($reports)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 3.5rem; margin-bottom: 12px;">🎉</div>
            <h3>Нет зарегистрированных ошибок</h3>
            <p>Ученики пока не отправляли жалоб на содержание уроков или все вопросы успешно закрыты.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="dict-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Урок</th>
                        <th>Ученик</th>
                        <th>Тип проблемы</th>
                        <th>Комментарий ученика</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $r): ?>
                        <tr>
                            <td>#<?= (int)$r['id'] ?></td>
                            <td>
                                <strong><?= e($r['lesson_title'] ?? 'Урок #' . $r['lesson_id']) ?></strong>
                                <?php if ($r['exercise_index'] > 0): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Задание #<?= (int)$r['exercise_index'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= e($r['username'] ?? 'Аноним') ?></strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($r['email'] ?? '') ?></div>
                            </td>
                            <td>
                                <span class="badge-tag"><?= e($r['report_type']) ?></span>
                            </td>
                            <td style="max-width: 320px; font-size: 0.9rem;">
                                <?= e($r['comment']) ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'open'): ?>
                                    <span class="badge-tag" style="background: rgba(239,68,68,0.15); color: #ef4444; font-weight: 800;">🔴 Открыт</span>
                                <?php elseif ($r['status'] === 'in_progress'): ?>
                                    <span class="badge-tag" style="background: rgba(234,179,8,0.15); color: #854d0e; font-weight: 800;">🟡 В работе</span>
                                <?php else: ?>
                                    <span class="badge-tag" style="background: rgba(88,204,2,0.15); color: var(--primary); font-weight: 800;">🟢 Решено</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                        <?php if ($r['status'] !== 'resolved'): ?>
                                            <input type="hidden" name="status" value="resolved">
                                            <button type="submit" class="btn-duo btn-primary" style="padding: 4px 8px; font-size: 0.8rem;" title="Пометить как исправленное">
                                                ✓ Решено
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="status" value="open">
                                            <button type="submit" class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" title="Открыть заново">
                                                Переоткрыть
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить этот репорт?')">
                                        <input type="hidden" name="action" value="delete_report">
                                        <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn-duo" style="padding: 4px 8px; font-size: 0.8rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);">
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
