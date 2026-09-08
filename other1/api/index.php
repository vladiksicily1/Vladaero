<?php
/**
 * VladInc AJAX & REST API Endpoint
 * Domain: vladinc.ru
 */

require_once dirname(__DIR__) . '/core/config.php';
require_once CORE_PATH . '/db.php';
require_once CORE_PATH . '/helpers.php';
require_once CORE_PATH . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$currentUser = Auth::user();

// 1. LIKE / MULTI-REACTIONS
if ($action === 'like_post') {
    if (!$currentUser) {
        json_response(['success' => false, 'error' => 'Требуется вход через Vlad ID'], 401);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($input['post_id'] ?? 0);
    $reaction = $input['reaction'] ?? 'like';
    if (!in_array($reaction, ['like', 'fire', 'heart', 'rocket', 'laugh'], true)) {
        $reaction = 'like';
    }

    if (!$postId) json_response(['success' => false, 'error' => 'Не указан post_id'], 400);

    $post = DB::fetch("SELECT id, user_id, likes_count FROM posts WHERE id = ?", [$postId]);
    if (!$post) json_response(['success' => false, 'error' => 'Пост не найден'], 404);

    $existing = DB::fetch("SELECT id, reaction FROM post_likes WHERE post_id = ? AND user_id = ? LIMIT 1", [$postId, $currentUser['id']]);

    if ($existing) {
        if ($existing['reaction'] === $reaction) {
            // Remove reaction
            DB::delete('post_likes', 'id = ?', [$existing['id']]);
            DB::query("UPDATE posts SET likes_count = GREATEST(0, likes_count - 1) WHERE id = ?", [$postId]);
            $isLiked = false;
        } else {
            // Update reaction type
            DB::update('post_likes', ['reaction' => $reaction], 'id = :id', ['id' => $existing['id']]);
            $isLiked = true;
        }
    } else {
        // Add reaction
        DB::insert('post_likes', [
            'post_id' => $postId,
            'user_id' => $currentUser['id'],
            'reaction' => $reaction
        ]);
        DB::query("UPDATE posts SET likes_count = likes_count + 1 WHERE id = ?", [$postId]);
        $isLiked = true;

        if ($post['user_id'] !== $currentUser['id']) {
            Auth::awardCoinsAndXp($post['user_id'], 1, 2, 'bonus', 'Реакция на публикацию');
            create_notification($post['user_id'], $currentUser['id'], 'like', "@{$currentUser['username']} отреагировал(а) на ваш пост", url('post/' . $postId));
        }
    }

    $newCount = (int)DB::fetchColumn("SELECT likes_count FROM posts WHERE id = ?", [$postId]);
    json_response(['success' => true, 'is_liked' => $isLiked, 'reaction' => $reaction, 'likes_count' => $newCount]);
}

// 2. CREATE POST (With Poll & Media Support)
if ($action === 'create_post') {
    if (!$currentUser) {
        json_response(['success' => false, 'error' => 'Требуется вход через Vlad ID'], 401);
    }

    $content = trim($_POST['content'] ?? '');
    $communityId = !empty($_POST['community_id']) ? (int)$_POST['community_id'] : null;
    $mediaUrl = null;
    $mediaType = 'none';

    // Handle media upload (single or multiple for carousel)
    $uploadedMediaFiles = [];
    $postDir = UPLOADS_PATH . '/posts';
    if (!is_dir($postDir)) @mkdir($postDir, 0755, true);

    // Multiple photos
    if (!empty($_FILES['media']['name'])) {
        if (is_array($_FILES['media']['name'])) {
            foreach ($_FILES['media']['name'] as $idx => $name) {
                if ($_FILES['media']['error'][$idx] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                        $fileName = 'post_' . uniqid() . '_' . $idx . '.' . $ext;
                        if (move_uploaded_file($_FILES['media']['tmp_name'][$idx], $postDir . '/' . $fileName)) {
                            $uploadedMediaFiles[] = 'uploads/posts/' . $fileName;
                        }
                    }
                }
            }
        } else if ($_FILES['media']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $fileName = 'post_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['media']['tmp_name'], $postDir . '/' . $fileName)) {
                    $uploadedMediaFiles[] = 'uploads/posts/' . $fileName;
                }
            }
        }
    }

    if (!empty($uploadedMediaFiles)) {
        $mediaUrl = $uploadedMediaFiles[0];
        $mediaType = 'image';
    }

    // Audio upload (VK Music)
    $audioUrl = null;
    $audioTitle = trim($_POST['audio_title'] ?? '');
    $audioArtist = trim($_POST['audio_artist'] ?? '');

    if (!empty($_FILES['audio_file']['name']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'], true)) {
            $musicDir = UPLOADS_PATH . '/music';
            if (!is_dir($musicDir)) @mkdir($musicDir, 0755, true);
            $musicName = 'track_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $musicDir . '/' . $musicName)) {
                $audioUrl = 'uploads/music/' . $musicName;
                if (!$audioTitle) $audioTitle = pathinfo($_FILES['audio_file']['name'], PATHINFO_FILENAME);
                if (!$audioArtist) $audioArtist = $currentUser['display_name'];
            }
        }
    }

    // Feelings & Quotes
    $feeling = trim($_POST['feeling'] ?? '');
    $quotePostId = !empty($_POST['quote_post_id']) ? (int)$_POST['quote_post_id'] : null;

    // Handle Poll options
    $pollQuestion = trim($_POST['poll_question'] ?? '');
    $pollOptionsJson = null;
    $pollVotesJson = null;

    if (!empty($pollQuestion) && !empty($_POST['poll_option']) && is_array($_POST['poll_option'])) {
        $validOptions = array_values(array_filter(array_map('trim', $_POST['poll_option'])));
        if (count($validOptions) >= 2) {
            $pollOptionsJson = json_encode($validOptions, JSON_UNESCAPED_UNICODE);
            $pollVotesJson = json_encode([], JSON_UNESCAPED_UNICODE);
        }
    }

    if (empty($content) && empty($mediaUrl) && empty($pollQuestion) && empty($audioUrl) && empty($quotePostId)) {
        json_response(['success' => false, 'error' => 'Публикация не может быть пустой'], 400);
    }

    $postId = DB::insert('posts', [
        'user_id' => $currentUser['id'],
        'community_id' => $communityId,
        'content' => $content,
        'media_url' => $mediaUrl,
        'media_type' => $mediaType,
        'poll_question' => $pollQuestion ?: null,
        'poll_options' => $pollOptionsJson,
        'poll_votes' => $pollVotesJson,
        'feeling' => $feeling ?: null,
        'quote_post_id' => $quotePostId,
        'audio_url' => $audioUrl,
        'audio_title' => $audioTitle ?: null,
        'audio_artist' => $audioArtist ?: null
    ]);

    // Insert all carousel items if multiple
    if (count($uploadedMediaFiles) > 1) {
        foreach ($uploadedMediaFiles as $order => $path) {
            DB::insert('post_media', [
                'post_id' => $postId,
                'media_url' => $path,
                'media_type' => 'image',
                'sort_order' => $order
            ]);
        }
    }

    Auth::awardCoinsAndXp($currentUser['id'], 10, 15, 'bonus', 'Публикация записи');

    json_response([
        'success' => true,
        'post_id' => $postId,
        'reward_coins' => 10,
        'post_url' => url('post/' . $postId)
    ]);
}

// 3. VOTE IN POLL
if ($action === 'vote_poll') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);

    $input = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($input['post_id'] ?? 0);
    $optionIndex = (int)($input['option_index'] ?? 0);

    $post = DB::fetch("SELECT id, poll_options, poll_votes FROM posts WHERE id = ?", [$postId]);
    if (!$post || empty($post['poll_options'])) json_response(['success' => false, 'error' => 'Опрос не найден'], 404);

    $options = json_decode($post['poll_options'], true) ?: [];
    $votes = json_decode($post['poll_votes'], true) ?: [];

    // Check if already voted
    if (isset($votes[$currentUser['id']])) {
        json_response(['success' => false, 'error' => 'Вы уже голосовали в этом опросе'], 400);
    }

    $votes[$currentUser['id']] = $optionIndex;
    DB::update('posts', ['poll_votes' => json_encode($votes)], 'id = :id', ['id' => $postId]);

    // Calculate results
    $counts = array_fill(0, count($options), 0);
    foreach ($votes as $v) {
        if (isset($counts[$v])) $counts[$v]++;
    }
    $totalVotes = count($votes);

    $results = [];
    foreach ($options as $idx => $opt) {
        $count = $counts[$idx] ?? 0;
        $pct = $totalVotes > 0 ? round(($count / $totalVotes) * 100) : 0;
        $results[] = ['text' => $opt, 'votes' => $count, 'percentage' => $pct];
    }

    Auth::awardCoinsAndXp($currentUser['id'], 1, 2, 'bonus', 'Участие в опросе');

    json_response(['success' => true, 'total_votes' => $totalVotes, 'results' => $results, 'my_vote' => $optionIndex]);
}

// 4. TOGGLE BOOKMARK
if ($action === 'toggle_bookmark') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);

    $input = json_decode(file_get_contents('php://input'), true);
    $postId = (int)($input['post_id'] ?? 0);

    $bm = DB::fetch("SELECT id FROM bookmarks WHERE post_id = ? AND user_id = ?", [$postId, $currentUser['id']]);
    if ($bm) {
        DB::delete('bookmarks', 'id = ?', [$bm['id']]);
        json_response(['success' => true, 'is_bookmarked' => false, 'message' => 'Удалено из закладок']);
    } else {
        DB::insert('bookmarks', ['post_id' => $postId, 'user_id' => $currentUser['id']]);
        json_response(['success' => true, 'is_bookmarked' => true, 'message' => 'Сохранено в закладки']);
    }
}

// 5. CREATE STORY (24-hour Momenta)
if ($action === 'create_story') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);

    if (empty($_FILES['story_media']['name']) || $_FILES['story_media']['error'] !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'error' => 'Выберите изображение для истории'], 400);
    }

    $ext = strtolower(pathinfo($_FILES['story_media']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        json_response(['success' => false, 'error' => 'Формат должен быть JPG, PNG или WEBP'], 400);
    }

    $storyDir = UPLOADS_PATH . '/stories';
    if (!is_dir($storyDir)) @mkdir($storyDir, 0755, true);
    $fileName = 'story_' . uniqid() . '.' . $ext;

    if (move_uploaded_file($_FILES['story_media']['tmp_name'], $storyDir . '/' . $fileName)) {
        $caption = trim($_POST['story_caption'] ?? '');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours

        $storyId = DB::insert('stories', [
            'user_id' => $currentUser['id'],
            'media_url' => 'uploads/stories/' . $fileName,
            'caption' => $caption ?: null,
            'expires_at' => $expiresAt
        ]);

        Auth::awardCoinsAndXp($currentUser['id'], 5, 10, 'bonus', 'Публикация истории');
        json_response(['success' => true, 'message' => 'История опубликована на 24 часа!']);
    } else {
        json_response(['success' => false, 'error' => 'Ошибка сохранения файла'], 500);
    }
}

// 6. ADD COMMENT
if ($action === 'add_comment') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);

    $postId = (int)($_POST['post_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    
    if (!$postId && !isset($_POST['post_id'])) {
        $input = json_decode(file_get_contents('php://input'), true);
        $postId = (int)($input['post_id'] ?? 0);
        $content = trim($input['content'] ?? '');
    }

    $mediaUrl = null;
    if (!empty($_FILES['comment_media']['name']) && $_FILES['comment_media']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['comment_media']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $commDir = UPLOADS_PATH . '/comments';
            if (!is_dir($commDir)) @mkdir($commDir, 0755, true);
            $fileName = 'comm_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['comment_media']['tmp_name'], $commDir . '/' . $fileName)) {
                $mediaUrl = 'uploads/comments/' . $fileName;
            }
        }
    }

    if (!$postId || (empty($content) && empty($mediaUrl))) {
        json_response(['success' => false, 'error' => 'Введите комментарий или прикрепите фото'], 400);
    }

    $post = DB::fetch("SELECT id, user_id FROM posts WHERE id = ?", [$postId]);
    if (!$post) json_response(['success' => false, 'error' => 'Пост не найден'], 404);

    $commentId = DB::insert('post_comments', [
        'post_id' => $postId,
        'user_id' => $currentUser['id'],
        'content' => $content ?: '📷 [Фото]',
        'media_url' => $mediaUrl
    ]);

    DB::query("UPDATE posts SET comments_count = comments_count + 1 WHERE id = ?", [$postId]);
    $newCommentsCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM post_comments WHERE post_id = ?", [$postId]);

    Auth::awardCoinsAndXp($currentUser['id'], 2, 5, 'bonus', 'Комментарий');

    if ($post['user_id'] !== $currentUser['id']) {
        create_notification($post['user_id'], $currentUser['id'], 'comment', "@{$currentUser['username']} оставил(а) комментарий к вашему посту", url('post/' . $postId));
    }

    $imgHtml = '';
    if ($mediaUrl) {
        $imgHtml = '<div style="margin-top: 6px;"><a href="' . e(url($mediaUrl)) . '" target="_blank"><img src="' . e(url($mediaUrl)) . '" style="max-width: 240px; max-height: 180px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border-color);" loading="lazy"></a></div>';
    }

    $commentHtml = '
    <div style="display: flex; gap: 10px; font-size: 13px; background: var(--bg-input); padding: 10px; border-radius: var(--radius-sm);">
        <img src="' . e(url($currentUser['avatar'] ?: 'assets/images/default_avatar.svg')) . '" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover;">
        <div style="flex: 1;">
            <div style="font-weight: 700; margin-bottom: 2px;">
                <a href="' . url('profile/@' . $currentUser['username']) . '">' . e($currentUser['display_name']) . '</a>
                <span style="font-size: 11px; color: var(--text-muted); font-weight: normal; margin-left: 6px;">только что</span>
            </div>
            <div>' . parse_content($content) . '</div>
            ' . $imgHtml . '
        </div>
    </div>';

    json_response(['success' => true, 'comment_id' => $commentId, 'comment_html' => $commentHtml, 'comments_count' => $newCommentsCount]);
}

// 7. SEND MESSAGE
if ($action === 'send_message') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);

    $input = json_decode(file_get_contents('php://input'), true);
    $chatId = (int)($input['chat_id'] ?? 0);
    $text = trim($input['message'] ?? '');

    if (!$chatId || empty($text)) json_response(['success' => false, 'error' => 'Текст сообщения пуст'], 400);

    $isMember = DB::fetch("SELECT id FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1", [$chatId, $currentUser['id']]);
    if (!$isMember) json_response(['success' => false, 'error' => 'Вы не участник диалога'], 403);

    $msgId = DB::insert('chat_messages', [
        'chat_id' => $chatId,
        'sender_id' => $currentUser['id'],
        'message' => $text
    ]);
    DB::update('chats', ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $chatId]);

    $msgHtml = '
    <div class="chat-msg" data-msg-id="' . $msgId . '" style="display: flex; justify-content: flex-end;">
        <div style="max-width: 70%; padding: 10px 16px; border-radius: 14px; font-size: 14px; line-height: 1.5; background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; box-shadow: var(--shadow-card);">
            <div>' . nl2br(e($text)) . '</div>
            <div style="font-size: 10px; opacity: 0.7; text-align: right; margin-top: 4px;">' . date('H:i') . '</div>
        </div>
    </div>';

    json_response(['success' => true, 'message_id' => $msgId, 'message_html' => $msgHtml]);
}

// 8. GET NEW MESSAGES
if ($action === 'get_new_messages') {
    if (!$currentUser) exit;
    $chatId = (int)($_GET['chat_id'] ?? 0);
    $lastId = (int)($_GET['last_id'] ?? 0);

    $newMessages = DB::fetchAll("SELECT m.*, u.username, u.display_name FROM chat_messages m JOIN users u ON m.sender_id = u.id WHERE m.chat_id = ? AND m.id > ? AND m.sender_id != ? ORDER BY m.id ASC", [$chatId, $lastId, $currentUser['id']]);
    $res = [];
    foreach ($newMessages as $m) {
        $html = '<div class="chat-msg" data-msg-id="' . $m['id'] . '" style="display: flex; justify-content: flex-start;"><div style="max-width: 70%; padding: 10px 16px; border-radius: 14px; font-size: 14px; line-height: 1.5; background: var(--bg-surface); color: var(--text-primary); border: 1px solid var(--border-color); box-shadow: var(--shadow-card);"><div>' . nl2br(e($m['message'])) . '</div><div style="font-size: 10px; opacity: 0.7; text-align: right; margin-top: 4px;">' . date('H:i', strtotime($m['created_at'])) . '</div></div></div>';
        $res[] = ['id' => $m['id'], 'html' => $html];
    }
    json_response(['success' => true, 'messages' => $res]);
}

// 9. TRANSFER COINS
if ($action === 'transfer_coins') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);
    $input = json_decode(file_get_contents('php://input'), true);
    $toUsername = strtolower(trim(str_replace('@', '', $input['to_user'] ?? '')));
    $amount = (int)($input['amount'] ?? 0);
    $note = trim($input['note'] ?? '');

    if ($amount <= 0) json_response(['success' => false, 'error' => 'Сумма перевода должна быть > 0'], 400);
    if ($currentUser['coins'] < $amount) json_response(['success' => false, 'error' => 'Недостаточно VladCoins'], 400);

    $recipient = DB::fetch("SELECT id, username, coins FROM users WHERE username = ? LIMIT 1", [$toUsername]);
    if (!$recipient) json_response(['success' => false, 'error' => 'Получатель не найден'], 404);
    if ($recipient['id'] === $currentUser['id']) json_response(['success' => false, 'error' => 'Нельзя переводить себе'], 400);

    DB::query("UPDATE users SET coins = coins - ? WHERE id = ?", [$amount, $currentUser['id']]);
    DB::query("UPDATE users SET coins = coins + ? WHERE id = ?", [$amount, $recipient['id']]);

    DB::insert('transactions', [
        'from_user_id' => $currentUser['id'],
        'to_user_id' => $recipient['id'],
        'amount' => $amount,
        'type' => 'transfer',
        'note' => $note ?: "Перевод от @{$currentUser['username']}"
    ]);

    create_notification($recipient['id'], $currentUser['id'], 'coin_transfer', "🪙 @{$currentUser['username']} перевел(а) вам {$amount} VladCoins!", url('wallet'));
    json_response(['success' => true, 'amount' => $amount, 'new_balance' => $currentUser['coins'] - $amount]);
}

// 10. DAILY BONUS
if ($action === 'claim_daily_bonus') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);

    $lastBonus = DB::fetch("SELECT created_at FROM transactions WHERE to_user_id = ? AND type = 'bonus' AND note LIKE '%Ежедневный бонус%' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1", [$currentUser['id']]);
    if ($lastBonus) json_response(['success' => false, 'error' => 'Бонус уже получен за последние 24 часа'], 400);

    $bonusAmount = 50;
    Auth::awardCoinsAndXp($currentUser['id'], $bonusAmount, 25, 'bonus', 'Ежедневный бонус экосистемы VladInc');
    $newBalance = (int)DB::fetchColumn("SELECT coins FROM users WHERE id = ?", [$currentUser['id']]);
    json_response(['success' => true, 'amount' => $bonusAmount, 'new_balance' => format_coins($newBalance)]);
}

// 11. ARCADE TAP
if ($action === 'arcade_tap') {
    if (!$currentUser) exit;
    $input = json_decode(file_get_contents('php://input'), true);
    $clicks = min(50, max(1, (int)($input['clicks'] ?? 1)));
    Auth::awardCoinsAndXp($currentUser['id'], $clicks, $clicks, 'game_reward', 'Добыча в Vlad Arcade');
    $newBalance = (int)DB::fetchColumn("SELECT coins FROM users WHERE id = ?", [$currentUser['id']]);
    json_response(['success' => true, 'earned' => $clicks, 'new_balance' => format_coins($newBalance)]);
}

// 12. FOLLOW USER
if ($action === 'follow_user') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);
    $input = json_decode(file_get_contents('php://input'), true);
    $targetId = (int)($input['user_id'] ?? 0);
    if (!$targetId || $targetId === $currentUser['id']) json_response(['success' => false, 'error' => 'Некорректный пользователь'], 400);

    $exists = DB::fetch("SELECT id FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1", [$currentUser['id'], $targetId]);
    if ($exists) {
        DB::delete('follows', 'id = ?', [$exists['id']]);
        $isFollowing = false;
        $msg = 'Вы отписались';
    } else {
        DB::insert('follows', ['follower_id' => $currentUser['id'], 'following_id' => $targetId]);
        $isFollowing = true;
        $msg = 'Вы подписались!';
        create_notification($targetId, $currentUser['id'], 'follow', "@{$currentUser['username']} подписался на ваши публикации", url('profile/@' . $currentUser['username']));
    }
    json_response(['success' => true, 'is_following' => $isFollowing, 'message' => $msg]);
}

// 13. SEND VOICE MESSAGE (Instagram Direct)
if ($action === 'send_voice_message') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);

    $chatId = (int)($_POST['chat_id'] ?? 0);
    $duration = (int)($_POST['duration'] ?? 0);

    if (!$chatId || empty($_FILES['voice_audio']['name'])) {
        json_response(['success' => false, 'error' => 'Аудиозапись не передана'], 400);
    }

    $isMember = DB::fetch("SELECT id FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1", [$chatId, $currentUser['id']]);
    if (!$isMember) json_response(['success' => false, 'error' => 'Вы не участник диалога'], 403);

    $voiceDir = UPLOADS_PATH . '/voice';
    if (!is_dir($voiceDir)) @mkdir($voiceDir, 0755, true);

    $fileName = 'voice_' . uniqid() . '.webm';
    $targetPath = $voiceDir . '/' . $fileName;

    if (move_uploaded_file($_FILES['voice_audio']['tmp_name'], $targetPath)) {
        $voiceUrl = 'uploads/voice/' . $fileName;
        $msgId = DB::insert('chat_messages', [
            'chat_id' => $chatId,
            'sender_id' => $currentUser['id'],
            'message' => '🎤 Голосовое сообщение (' . $duration . ' сек.)',
            'voice_url' => $voiceUrl,
            'voice_duration' => $duration
        ]);

        DB::update('chats', ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $chatId]);

        $msgHtml = '
        <div class="chat-msg" data-msg-id="' . $msgId . '" style="display: flex; justify-content: flex-end;">
            <div style="max-width: 70%; padding: 10px 16px; border-radius: 14px; background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; box-shadow: var(--shadow-card);">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span>🎤</span>
                    <audio controls src="' . url($voiceUrl) . '" style="height: 32px; max-width: 220px;"></audio>
                </div>
                <div style="font-size: 10px; opacity: 0.7; text-align: right; margin-top: 4px;">' . date('H:i') . ' &bull; ' . $duration . ' сек.</div>
            </div>
        </div>';

        json_response(['success' => true, 'message_id' => $msgId, 'message_html' => $msgHtml]);
    } else {
        json_response(['success' => false, 'error' => 'Не удалось сохранить аудиозапись'], 500);
    }
}

// 14. SEND VIRTUAL GIFT (VK Gifts)
if ($action === 'send_gift') {
    if (!$currentUser) json_response(['success' => false, 'error' => 'Требуется вход'], 401);

    $input = json_decode(file_get_contents('php://input'), true);
    $targetId = (int)($input['user_id'] ?? 0);
    $giftId = (int)($input['gift_id'] ?? 0);
    $message = trim($input['message'] ?? '');

    $gift = DB::fetch("SELECT * FROM gifts WHERE id = ? AND is_active = 1 LIMIT 1", [$giftId]);
    if (!$gift) json_response(['success' => false, 'error' => 'Подарок не найден'], 404);

    if ($currentUser['coins'] < $gift['price_coins']) {
        json_response(['success' => false, 'error' => 'Недостаточно VladCoins для отправки подарка'], 400);
    }

    $targetUser = DB::fetch("SELECT id, username FROM users WHERE id = ?", [$targetId]);
    if (!$targetUser) json_response(['success' => false, 'error' => 'Пользователь не найден'], 404);

    // Deduct coins & record transaction
    DB::query("UPDATE users SET coins = coins - ? WHERE id = ?", [$gift['price_coins'], $currentUser['id']]);
    DB::insert('transactions', [
        'from_user_id' => $currentUser['id'],
        'to_user_id' => $targetId,
        'amount' => $gift['price_coins'],
        'type' => 'tip',
        'note' => "Подарок {$gift['icon']} {$gift['name']}"
    ]);

    // Insert user gift
    DB::insert('user_gifts', [
        'gift_id' => $gift['id'],
        'from_user_id' => $currentUser['id'],
        'to_user_id' => $targetId,
        'message' => $message
    ]);

    create_notification($targetId, $currentUser['id'], 'system', "🎁 @{$currentUser['username']} отправил(а) вам подарок «{$gift['icon']} {$gift['name']}»!", url('u/' . $targetUser['username']));

    json_response([
        'success' => true,
        'message' => "Подарок {$gift['icon']} успешно отправлен!",
        'new_balance' => $currentUser['coins'] - $gift['price_coins']
    ]);
}

json_response(['success' => false, 'error' => 'Неизвестное действие API'], 404);

