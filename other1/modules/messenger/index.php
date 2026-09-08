<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAuth();
$pageTitle = 'VladChat — Мессенджер';

$activeChatId = (int)($_GET['chat_id'] ?? 0);
$recipientUsername = trim($_GET['with'] ?? '');

// If recipient username is passed, find or create direct chat
if (!empty($recipientUsername)) {
    $targetUser = DB::fetch("SELECT id, username, display_name FROM users WHERE username = ? LIMIT 1", [$recipientUsername]);
    if ($targetUser && $targetUser['id'] !== $currentUser['id']) {
        // Look for existing direct chat between these two
        $existingChat = DB::fetch("
            SELECT c.id FROM chats c
            JOIN chat_participants cp1 ON c.id = cp1.chat_id AND cp1.user_id = ?
            JOIN chat_participants cp2 ON c.id = cp2.chat_id AND cp2.user_id = ?
            WHERE c.type = 'direct' LIMIT 1
        ", [$currentUser['id'], $targetUser['id']]);

        if ($existingChat) {
            $activeChatId = (int)$existingChat['id'];
        } else {
            // Create new direct chat
            $newChatId = DB::insert('chats', ['type' => 'direct']);
            DB::insert('chat_participants', ['chat_id' => $newChatId, 'user_id' => $currentUser['id']]);
            DB::insert('chat_participants', ['chat_id' => $newChatId, 'user_id' => $targetUser['id']]);
            $activeChatId = $newChatId;
        }
        redirect('messenger?chat_id=' . $activeChatId);
    }
}

// Fetch user's active conversations list
$conversations = DB::fetchAll("
    SELECT c.id, c.type, c.updated_at,
           u.id as other_user_id, u.username, u.display_name, u.avatar, u.last_seen,
           (SELECT message FROM chat_messages WHERE chat_id = c.id ORDER BY id DESC LIMIT 1) as last_message,
           (SELECT created_at FROM chat_messages WHERE chat_id = c.id ORDER BY id DESC LIMIT 1) as last_message_time
    FROM chats c
    JOIN chat_participants cp_me ON c.id = cp_me.chat_id AND cp_me.user_id = ?
    JOIN chat_participants cp_other ON c.id = cp_other.chat_id AND cp_other.user_id != ?
    JOIN users u ON cp_other.user_id = u.id
    ORDER BY c.updated_at DESC
", [$currentUser['id'], $currentUser['id']]);

// If no chat selected, select the first one if exists
if (!$activeChatId && !empty($conversations)) {
    $activeChatId = (int)$conversations[0]['id'];
}

// Active chat details & messages
$activeChatDetails = null;
$messages = [];

if ($activeChatId) {
    $activeChatDetails = DB::fetch("
        SELECT c.*, u.id as other_user_id, u.username, u.display_name, u.avatar, u.last_seen
        FROM chats c
        JOIN chat_participants cp_me ON c.id = cp_me.chat_id AND cp_me.user_id = ?
        JOIN chat_participants cp_other ON c.id = cp_other.chat_id AND cp_other.user_id != ?
        JOIN users u ON cp_other.user_id = u.id
        WHERE c.id = ? LIMIT 1
    ", [$currentUser['id'], $currentUser['id'], $activeChatId]);

    if ($activeChatDetails) {
        $messages = DB::fetchAll("
            SELECT m.*, u.username, u.display_name, u.avatar
            FROM chat_messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.chat_id = ?
            ORDER BY m.id ASC LIMIT 100
        ", [$activeChatId]);
    }
}

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 20px;">
    <div class="card" style="padding: 0; overflow: hidden; display: flex; height: 75vh; border-radius: 16px;">
        
        <!-- CHAT CONVERSATIONS LIST (LEFT SIDE) -->
        <div style="width: 320px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; background: var(--bg-surface);">
            <div style="padding: 16px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 16px; font-weight: 700;">Диалоги</h3>
                <button type="button" class="btn btn-secondary btn-sm" onclick="promptNewChat()">+ Чат</button>
            </div>

            <div style="flex: 1; overflow-y: auto;">
                <?php if (empty($conversations)): ?>
                    <div style="padding: 30px 20px; text-align: center; color: var(--text-muted); font-size: 13px;">
                        У вас пока нет начатых диалогов.<br>Нажмите «+ Чат» или перейдите в профиль пользователя.
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $c): 
                        $isSelected = ($activeChatId === (int)$c['id']);
                    ?>
                        <a href="<?= url('messenger?chat_id=' . $c['id']) ?>" style="display: flex; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border-color); background: <?= $isSelected ? 'var(--bg-surface-elevated)' : 'transparent' ?>; transition: var(--transition);">
                            <img src="<?= e(url($c['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($c['display_name']) ?>&background=3b82f6&color=fff'">
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <div style="font-size: 14px; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= e($c['display_name']) ?></div>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= time_ago($c['last_message_time']) ?></div>
                                </div>
                                <div style="font-size: 12px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= e($c['last_message'] ?: 'Диалог создан') ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- CHAT WINDOW (RIGHT SIDE) -->
        <div style="flex: 1; display: flex; flex-direction: column; background: var(--bg-body);" id="active-chat-window" data-chat-id="<?= $activeChatId ?>">
            <?php if ($activeChatDetails): ?>
                <!-- Header -->
                <div style="padding: 14px 20px; border-bottom: 1px solid var(--border-color); background: var(--bg-surface); display: flex; align-items: center; gap: 12px;">
                    <img src="<?= e(url($activeChatDetails['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($activeChatDetails['display_name']) ?>&background=3b82f6&color=fff'">
                    <div>
                        <div style="font-size: 15px; font-weight: 700;">
                            <a href="<?= url('profile/@' . $activeChatDetails['username']) ?>" style="color: inherit;"><?= e($activeChatDetails['display_name']) ?></a>
                        </div>
                        <div style="font-size: 11px; color: var(--text-muted);">
                            @<?= e($activeChatDetails['username']) ?> &bull; был(а) <?= time_ago($activeChatDetails['last_seen']) ?>
                        </div>
                    </div>
                </div>

                <!-- Messages List -->
                <div id="chat-messages-scroll" style="flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($messages as $m): 
                        $isMine = ($m['sender_id'] == $currentUser['id']);
                    ?>
                        <div class="chat-msg" data-msg-id="<?= $m['id'] ?>" style="display: flex; justify-content: <?= $isMine ? 'flex-end' : 'flex-start' ?>;">
                            <div style="max-width: 70%; padding: 10px 16px; border-radius: 14px; font-size: 14px; line-height: 1.5; background: <?= $isMine ? 'linear-gradient(135deg, #3b82f6, #2563eb)' : 'var(--bg-surface)' ?>; color: <?= $isMine ? '#fff' : 'var(--text-primary)' ?>; border: <?= $isMine ? 'none' : '1px solid var(--border-color)' ?>; box-shadow: var(--shadow-card);">
                                <?php if (!empty($m['voice_url'])): ?>
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                                        <span style="font-size: 18px;">🎤</span>
                                        <audio controls src="<?= e(url($m['voice_url'])) ?>" style="height: 32px; max-width: 220px;"></audio>
                                    </div>
                                    <div style="font-size: 12px; opacity: 0.9;"><?= nl2br(e($m['message'])) ?></div>
                                <?php else: ?>
                                    <div><?= nl2br(e($m['message'])) ?></div>
                                <?php endif; ?>
                                <div style="font-size: 10px; opacity: 0.7; text-align: right; margin-top: 4px;">
                                    <?= date('H:i', strtotime($m['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Input Form -->
                <div style="padding: 14px 20px; background: var(--bg-surface); border-top: 1px solid var(--border-color);">
                    <form id="chat-send-form" style="display: flex; gap: 10px; align-items: center;">
                        <input type="text" id="chat-message-input" class="form-control" placeholder="Напишите сообщение..." autocomplete="off" style="flex: 1;">
                        <span id="voice-timer-label" style="display: none; font-size: 13px; font-weight: bold; color: #ef4444; padding: 0 4px;">0:00</span>
                        <button type="button" id="voice-record-btn" class="btn btn-secondary" style="border-radius: 50%; width: 42px; height: 42px; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;" onclick="toggleVoiceRecording(<?= (int)$activeChatId ?>)" title="Голосовое сообщение (нажмите для записи / повторно для отправки)">🎙️</button>
                        <button type="submit" class="btn btn-primary" style="flex-shrink: 0;">Отправить</button>
                    </form>
                </div>
            <?php else: ?>
                <div style="flex: 1; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 15px;">
                    Выберите диалог слева или начните новый 💬
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
function promptNewChat() {
    const user = prompt("Введите никнейм пользователя для начала диалога (например: admin):");
    if (user) {
        window.location.href = baseUrl + '/messenger?with=' + encodeURIComponent(user.replace('@', ''));
    }
}
</script>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
