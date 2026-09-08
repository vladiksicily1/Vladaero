<?php
/**
 * ShibaLingo - AI Chat with Shiba Inu Mascot Tutor
 */

$pageTitle = 'Чат с Сиба-сэнсэем';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$userId = $user['id'];

// Get user chat sessions
$sessionsStmt = $db->prepare("SELECT * FROM chat_sessions WHERE user_id = :uid ORDER BY updated_at DESC");
$sessionsStmt->execute(['uid' => $userId]);
$sessions = $sessionsStmt->fetchAll();

// Determine active session
$activeSessionId = (int)($_GET['session_id'] ?? 0);
if ($activeSessionId === 0 && !empty($sessions)) {
    $activeSessionId = $sessions[0]['id'];
}

// If no sessions exist, create default
if (empty($sessions)) {
    $createStmt = $db->prepare("INSERT INTO chat_sessions (user_id, title, language_code) VALUES (:uid, 'Разговор на Vladikish', :lang)");
    $createStmt->execute(['uid' => $userId, 'lang' => $currentLangCode]);
    $activeSessionId = $db->lastInsertId();

    $initMsg = $db->prepare("INSERT INTO chat_messages (session_id, sender, message, translation) VALUES (:sid, 'shiba', 'Mira, Vladi! Me Shiba-sensei. Zora vanti est! Como sta korno tu?', 'Привет, Влад! Я Сиба-сэнсэй. Сегодня прекрасный день! Как настроение/сердце?')");
    $initMsg->execute(['sid' => $activeSessionId]);

    // Re-fetch
    $sessionsStmt->execute(['uid' => $userId]);
    $sessions = $sessionsStmt->fetchAll();
}

// Get messages for active session
$msgStmt = $db->prepare("SELECT * FROM chat_messages WHERE session_id = :sid ORDER BY created_at ASC");
$msgStmt->execute(['sid' => $activeSessionId]);
$messages = $msgStmt->fetchAll();
?>

<div class="chat-container">
    <!-- Sessions Sidebar -->
    <div class="chat-sidebar">
        <div style="padding: 16px; border-bottom: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <span style="font-weight: 800; font-size: 1.05rem;">История чатов</span>
            <button class="btn-duo btn-primary" id="btn-new-chat" style="padding: 8px 12px; font-size: 0.85rem; border-radius: 10px;">
                + Новый чат
            </button>
        </div>

        <div class="chat-sessions-list">
            <?php foreach ($sessions as $s): ?>
                <a href="chat.php?session_id=<?= $s['id'] ?>" class="chat-session-item <?= ($s['id'] == $activeSessionId) ? 'active' : '' ?>">
                    <div style="display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <span>💬</span>
                        <span style="overflow: hidden; text-overflow: ellipsis;"><?= e($s['title']) ?></span>
                    </div>
                    <button class="btn-delete-session" data-id="<?= $s['id'] ?>" style="background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 2px 6px;" title="Удалить чат">
                        ✕
                    </button>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Chat Window -->
    <div class="chat-main">
        <!-- Chat Header -->
        <div style="padding: 14px 24px; border-bottom: 2px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: var(--bg-sidebar);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div id="chat-header-shiba" style="width: 44px; height: 44px;"></div>
                <div>
                    <div style="font-weight: 800; font-size: 1.1rem;">Сиба-сэнсэй 🐕</div>
                    <div style="font-size: 0.8rem; color: var(--primary); font-weight: 700;">
                        ● Готов общаться на <?= e($currentLangObj['name']) ?>
                    </div>
                </div>
            </div>

            <div style="font-size: 0.9rem; color: var(--text-muted); font-weight: 700;">
                Модель: <span style="color: var(--secondary);"><?= e(getSetting('nvidia_model', 'Llama 3.3 70B')) ?></span>
            </div>
        </div>

        <!-- Messages Box -->
        <div class="chat-messages" id="chat-messages-box">
            <?php foreach ($messages as $m): ?>
                <div class="chat-msg <?= e($m['sender']) ?>">
                    <div style="flex-shrink: 0;">
                        <?php if ($m['sender'] === 'shiba'): ?>
                            <div class="shiba-header-mini"><?= $currentLangObj['flag'] ?></div>
                        <?php else: ?>
                            <div style="font-size: 1.8rem; background: var(--bg-main); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--border-color);">👤</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="msg-bubble">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                                <div><?= nl2br(e($m['message'])) ?></div>
                                <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem; border-radius: 8px;" onclick="speakText('<?= addslashes($m['message']) ?>', '<?= $currentLangCode ?>')">
                                    🔊
                                </button>
                            </div>
                            <?php if (!empty($m['translation'])): ?>
                                <div class="msg-translation">💬 <em><?= e($m['translation']) ?></em></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($m['corrections'])): ?>
                            <div class="msg-corrections">💡 <strong>Совет Сиба-сэнсэя:</strong> <?= e($m['corrections']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Chat Input Bar -->
        <div class="chat-input-bar">
            <input type="text" id="chat-user-input" class="chat-input" placeholder="Напишите сообщение на <?= e($currentLangObj['name']) ?>..." autocomplete="off">
            <button class="btn-duo btn-primary" id="btn-send-msg" style="border-radius: 16px; padding: 12px 24px;">
                Отправить 🐾
            </button>
        </div>
    </div>
</div>

<script src="assets/js/chat.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    ShibaMascot.update('chat-header-shiba', 'happy');
    const chatEngine = new ShibaChat(<?= $activeSessionId ?>, '<?= $currentLangCode ?>');

    // New Chat button
    const btnNew = document.getElementById('btn-new-chat');
    if (btnNew) {
        btnNew.addEventListener('click', async () => {
            const title = prompt('Название темы диалога:', 'Разговор о путешествиях');
            if (!title) return;

            const res = await fetch('api/chat_history.php?action=create_session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: title, lang: '<?= $currentLangCode ?>' })
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = `chat.php?session_id=${data.session_id}`;
            }
        });
    }

    // Delete chat button
    document.querySelectorAll('.btn-delete-session').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('Удалить этот чат?')) return;

            const sid = btn.dataset.id;
            const res = await fetch('api/chat_history.php?action=delete_session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: sid })
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = 'chat.php';
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
