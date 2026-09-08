<?php
/**
 * Admin AI Chat Logs & Quality Monitor
 */

$adminTitle = 'Мониторинг AI Диалогов';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_conlang') && !hasPermission($admin, 'manage_lessons')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$driver = Database::getDriver();
$message = '';

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'delete_message') {
        $mid = (int)$_POST['msg_id'];
        $db->exec("DELETE FROM " . tbl('chat_messages') . " WHERE id = {$mid}");
        $message = 'Сообщение удалено.';
    }
    if ($act === 'clear_old_chats') {
        $db->exec("DELETE FROM " . tbl('chat_messages') . " WHERE created_at < datetime('now', '-30 days')");
        $message = 'Сообщения старше 30 дней успешно очищены!';
    }
}

// Filter
$userId = (int)($_GET['user_id'] ?? 0);
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT m.*, u.username, u.email, s.title as session_title
    FROM " . tbl('chat_messages') . " m
    LEFT JOIN " . tbl('chat_sessions') . " s ON m.session_id = s.id
    LEFT JOIN " . tbl('users') . " u ON s.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($userId > 0) {
    $sql .= " AND s.user_id = :uid";
    $params['uid'] = $userId;
}
if (!empty($search)) {
    $sql .= " AND m.content LIKE :q";
    $params['q'] = '%' . $search . '%';
}

$sql .= " ORDER BY m.id DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalMsgs = (int)$db->query("SELECT COUNT(*) FROM " . tbl('chat_messages'))->fetchColumn();
$userMsgs = (int)$db->query("SELECT COUNT(*) FROM " . tbl('chat_messages') . " WHERE role = 'user'")->fetchColumn();
$botMsgs = (int)$db->query("SELECT COUNT(*) FROM " . tbl('chat_messages') . " WHERE role = 'assistant'")->fetchColumn();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>💬</span> Мониторинг AI Диалогов
        </h1>
        <p style="color: var(--text-muted);">Анализ бесед учеников с Сиба-сэнсэем, выявление ошибок бота и улучшение качества обучения</p>
    </div>

    <form method="POST" onsubmit="return confirm('Очистить все сообщения чата старше 30 дней?')">
        <input type="hidden" name="action" value="clear_old_chats">
        <button type="submit" class="btn-duo btn-outline" style="padding: 10px 16px;">
            🧹 Очистить старые (>30 дн.)
        </button>
    </form>
</div>

<?php if (!empty($message)): ?>
    <div class="alert-duo alert-success" style="margin-bottom: 20px;"><?= e($message) ?></div>
<?php endif; ?>

<!-- Quick Metrics -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; color: var(--secondary); font-weight: 900;"><?= $totalMsgs ?></div>
        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Всего сообщений</div>
    </div>
    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; color: var(--primary); font-weight: 900;"><?= $userMsgs ?></div>
        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Вопросов от учеников</div>
    </div>
    <div class="card-duo" style="margin-bottom: 0;">
        <div style="font-size: 2rem; color: #a855f7; font-weight: 900;"><?= $botMsgs ?></div>
        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Ответов Сиба-сэнсэя</div>
    </div>
</div>

<!-- Search Filter -->
<div class="card-duo" style="padding: 16px; margin-bottom: 20px;">
    <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <input type="text" name="q" value="<?= e($search) ?>" class="chat-input" placeholder="🔍 Поиск по тексту сообщений..." style="flex-grow: 1; margin-bottom: 0;">
        <button type="submit" class="btn-duo btn-primary" style="padding: 10px 24px;">Искать</button>
        <?php if (!empty($search) || $userId > 0): ?>
            <a href="chat_logs.php" class="btn-duo btn-outline" style="padding: 10px 16px; text-decoration: none;">Сброс</a>
        <?php endif; ?>
    </form>
</div>

<!-- Chat Logs Feed -->
<div class="card-duo" style="padding: 24px;">
    <h3 style="font-size: 1.25rem; font-weight: 900; margin-bottom: 16px;">
        📜 Лента недавних сообщений (<?= count($messages) ?>)
    </h3>

    <?php if (empty($messages)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            Сообщений не найдено.
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 14px;">
            <?php foreach ($messages as $m): ?>
                <?php 
                $isUser = ($m['role'] === 'user');
                ?>
                <div style="padding: 16px 20px; border-radius: 16px; border: 1.5px solid var(--border-color); background: <?= $isUser ? 'var(--bg-main)' : 'rgba(88,204,2,0.06)' ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 1.4rem;"><?= $isUser ? '👤' : '🐕' ?></span>
                            <span style="font-weight: 900; color: <?= $isUser ? 'var(--secondary)' : 'var(--primary-shadow)' ?>;">
                                <?= $isUser ? e($m['username'] ?? 'Ученик') : 'Сиба-Сэнсэй (AI)' ?>
                            </span>
                            <?php if (!empty($m['session_title'])): ?>
                                <span class="badge-tag" style="font-size: 0.75rem;">Тема: <?= e($m['session_title']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="color: var(--text-muted); font-size: 0.8rem;"><?= date('d.m.Y H:i:s', strtotime($m['created_at'])) ?></span>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить это сообщение?')">
                                <input type="hidden" name="action" value="delete_message">
                                <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                                <button type="submit" style="background: none; border: none; cursor: pointer; color: var(--danger); font-size: 0.9rem;" title="Удалить">✕</button>
                            </form>
                        </div>
                    </div>

                    <div style="font-size: 0.95rem; line-height: 1.5; color: var(--text-main); white-space: pre-wrap;">
                        <?= e($m['content']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
