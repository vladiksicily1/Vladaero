<?php
/**
 * Admin Classrooms & Teacher Moderation
 */

$adminTitle = 'Классы и Преподаватели';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_users') && !hasPermission($admin, 'manage_lessons')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$driver = Database::getDriver();
$message = '';
$error = '';

// Ensure classrooms and teacher tables exist
try {
    if ($driver === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('classrooms') . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER DEFAULT 1,
            title TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE,
            language_code TEXT DEFAULT 'vladikish',
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('classroom_students') . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            classroom_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(classroom_id, user_id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('assignments') . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            classroom_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            lesson_id INTEGER,
            due_date DATETIME,
            xp_reward INTEGER DEFAULT 30,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (Exception $e) {}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_teacher') {
        $uid = (int)$_POST['user_id'];
        $newStatus = (int)$_POST['is_teacher'];
        $stmt = $db->prepare("UPDATE " . tbl('users') . " SET is_teacher = :t WHERE id = :id");
        $stmt->execute(['t' => $newStatus, 'id' => $uid]);
        $message = $newStatus ? 'Пользователю присвоен статус Учителя!' : 'Статус Учителя отозван.';
    }

    if ($action === 'delete_classroom') {
        $cid = (int)$_POST['classroom_id'];
        $db->exec("DELETE FROM " . tbl('classrooms') . " WHERE id = {$cid}");
        $db->exec("DELETE FROM " . tbl('classroom_students') . " WHERE classroom_id = {$cid}");
        $db->exec("DELETE FROM " . tbl('assignments') . " WHERE classroom_id = {$cid}");
        $message = 'Класс и все связанные задания удалены.';
    }
}

// Fetch all classrooms with teacher and student count
$classrooms = [];
try {
    $cStmt = $db->query("
        SELECT c.*, u.username as teacher_name, u.email as teacher_email,
               (SELECT COUNT(*) FROM " . tbl('classroom_students') . " cs WHERE cs.classroom_id = c.id) as students_count,
               (SELECT COUNT(*) FROM " . tbl('assignments') . " a WHERE a.classroom_id = c.id) as assignments_count
        FROM " . tbl('classrooms') . " c
        LEFT JOIN " . tbl('users') . " u ON c.teacher_id = u.id
        ORDER BY c.id DESC
    ");
    $classrooms = $cStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch all teachers
$teachers = [];
try {
    $tStmt = $db->query("
        SELECT u.id, u.username, u.email, u.created_at, u.xp,
               (SELECT COUNT(*) FROM " . tbl('classrooms') . " c WHERE c.teacher_id = u.id) as classrooms_count
        FROM " . tbl('users') . " u
        WHERE u.is_teacher = 1 OR u.role_id IN (SELECT id FROM roles WHERE name IN ('teacher', 'admin', 'superadmin'))
        ORDER BY u.id DESC
    ");
    $teachers = $tStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>🧑‍🏫</span> Классы и Преподаватели
        </h1>
        <p style="color: var(--text-muted);">Мониторинг виртуальных школьных классов, учителей и домашних заданий</p>
    </div>

    <a href="../teacher/index.php" target="_blank" class="btn-duo btn-secondary" style="padding: 10px 18px; text-decoration: none;">
        🧑‍🏫 Открыть Портал Учителя ↗
    </a>
</div>

<?php if (!empty($message)): ?>
    <div class="alert-duo alert-success" style="margin-bottom: 20px;"><?= e($message) ?></div>
<?php endif; ?>

<!-- Classrooms Table -->
<div class="card-duo" style="padding: 24px; margin-bottom: 24px;">
    <h3 style="font-size: 1.25rem; font-weight: 900; margin-bottom: 16px;">
        🏫 Все активные классы (<?= count($classrooms) ?>)
    </h3>

    <?php if (empty($classrooms)): ?>
        <div style="text-align: center; padding: 30px; color: var(--text-muted);">
            Учителя пока не создали ни одного класса.
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="dict-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Код</th>
                        <th>Название класса</th>
                        <th>Преподаватель</th>
                        <th>Язык</th>
                        <th>Учеников</th>
                        <th>Заданий</th>
                        <th>Дата создания</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classrooms as $c): ?>
                        <tr>
                            <td>
                                <span class="badge-tag" style="background: rgba(28,176,246,0.15); color: var(--secondary); font-weight: 900; font-family: monospace; font-size: 0.95rem;">
                                    <?= e($c['code']) ?>
                                </span>
                            </td>
                            <td style="font-weight: 800; font-size: 1.05rem;"><?= e($c['title']) ?></td>
                            <td>
                                <strong><?= e($c['teacher_name'] ?? 'Учитель #' . $c['teacher_id']) ?></strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($c['teacher_email'] ?? '') ?></div>
                            </td>
                            <td><span class="badge-tag"><?= e($c['language_code']) ?></span></td>
                            <td><strong style="color: var(--primary);"><?= (int)$c['students_count'] ?></strong> чел.</td>
                            <td><strong><?= (int)$c['assignments_count'] ?></strong> шт.</td>
                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить этот класс и отвязать учеников?')">
                                    <input type="hidden" name="action" value="delete_classroom">
                                    <input type="hidden" name="classroom_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn-duo" style="padding: 4px 8px; font-size: 0.8rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);">
                                        Удалить 🗑️
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Teachers List & Management -->
<div class="card-duo" style="padding: 24px;">
    <h3 style="font-size: 1.25rem; font-weight: 900; margin-bottom: 16px;">
        🎓 Список преподавателей платформы (<?= count($teachers) ?>)
    </h3>

    <div style="overflow-x: auto;">
        <table class="dict-table" style="width: 100%;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Преподаватель</th>
                    <th>Email</th>
                    <th>Классов создано</th>
                    <th>Дата регистрации</th>
                    <th>Управление статусом</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teachers as $t): ?>
                    <tr>
                        <td>#<?= $t['id'] ?></td>
                        <td style="font-weight: 800; font-size: 1.05rem;">
                            <?= e($t['username']) ?>
                        </td>
                        <td style="color: var(--text-muted);"><?= e($t['email']) ?></td>
                        <td><span class="badge-tag" style="background: rgba(88,204,2,0.15); color: var(--primary); font-weight: 800;"><?= (int)$t['classrooms_count'] ?> классов</span></td>
                        <td style="color: var(--text-muted); font-size: 0.85rem;"><?= date('d.m.Y', strtotime($t['created_at'])) ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_teacher">
                                <input type="hidden" name="user_id" value="<?= $t['id'] ?>">
                                <input type="hidden" name="is_teacher" value="0">
                                <button type="submit" class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.8rem;" onclick="return confirm('Отозвать учительский доступ у пользователя?')">
                                    Отозвать статус ✕
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
