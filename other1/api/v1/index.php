<?php
/**
 * VladInc Public REST API v1
 * Authentication: Header 'X-API-Key' or 'Authorization: Bearer <token>'
 * Domain: vladinc.ru
 */

require_once dirname(__DIR__, 2) . '/core/config.php';
require_once CORE_PATH . '/db.php';
require_once CORE_PATH . '/helpers.php';
require_once CORE_PATH . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Helper: send API response
function api_response(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 1. Authenticate API Key
$apiKeyHeader = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($apiKeyHeader) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        $apiKeyHeader = $matches[1];
    }
}

// Public Ping Endpoint does not require API key
$requestMethod = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_GET['endpoint'] ?? '';
$endpointParts = explode('/', trim($pathInfo, '/'));
$resource = $endpointParts[0] ?? 'ping';

if ($resource === 'ping') {
    api_response([
        'status' => 'ok',
        'app' => APP_NAME,
        'domain' => APP_DOMAIN,
        'api_version' => 'v1',
        'timestamp' => date('c')
    ]);
}

if (empty($apiKeyHeader)) {
    api_response([
        'error' => 'Unauthorized',
        'message' => 'API ключ отсутствует. Передайте заголовок X-API-Key: <ваш_ключ> или Authorization: Bearer <ваш_ключ>'
    ], 401);
}

// Lookup Key
$keyRecord = DB::fetch("
    SELECT k.*, u.id as user_id, u.username, u.display_name, u.role, u.coins, u.is_banned
    FROM api_keys k
    JOIN users u ON k.user_id = u.id
    WHERE k.api_key = ? AND k.is_active = 1
    LIMIT 1
", [$apiKeyHeader]);

if (!$keyRecord || $keyRecord['is_banned']) {
    api_response([
        'error' => 'Forbidden',
        'message' => 'Недействительный или заблокированный API-ключ'
    ], 403);
}

// Update stats
DB::query("UPDATE api_keys SET requests_count = requests_count + 1, last_used_at = NOW() WHERE id = ?", [$keyRecord['id']]);

$apiUser = [
    'id' => (int)$keyRecord['user_id'],
    'username' => $keyRecord['username'],
    'display_name' => $keyRecord['display_name'],
    'role' => $keyRecord['role'],
    'coins' => (int)$keyRecord['coins']
];

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true) ?: $_POST;

// -------------------------------------------------------------
// REST API ROUTING
// -------------------------------------------------------------

// GET /api/v1/me
if ($resource === 'me' && $requestMethod === 'GET') {
    $fullUser = DB::fetch("SELECT id, username, email, display_name, avatar, bio, status_text, role, coins, xp, level, created_at FROM users WHERE id = ?", [$apiUser['id']]);
    api_response(['success' => true, 'user' => $fullUser]);
}

// GET /api/v1/users/{username}
if ($resource === 'users' && $requestMethod === 'GET') {
    $targetUsername = $endpointParts[1] ?? '';
    if (empty($targetUsername)) {
        api_response(['error' => 'Bad Request', 'message' => 'Укажите никнейм: /api/v1/users/{username}'], 400);
    }
    $target = DB::fetch("SELECT id, username, display_name, avatar, bio, status_text, role, level, created_at, (SELECT COUNT(*) FROM posts WHERE user_id = users.id) as posts_count, (SELECT COUNT(*) FROM follows WHERE following_id = users.id) as followers_count FROM users WHERE username = ? LIMIT 1", [$targetUsername]);
    if (!$target) {
        api_response(['error' => 'Not Found', 'message' => 'Пользователь не найден'], 404);
    }
    api_response(['success' => true, 'user' => $target]);
}

// GET /api/v1/posts
if ($resource === 'posts' && $requestMethod === 'GET') {
    $postId = $endpointParts[1] ?? null;

    // Single post
    if ($postId && is_numeric($postId)) {
        $post = DB::fetch("
            SELECT p.*, u.username, u.display_name, u.avatar, u.level 
            FROM posts p JOIN users u ON p.user_id = u.id 
            WHERE p.id = ? LIMIT 1
        ", [(int)$postId]);

        if (!$post) {
            api_response(['error' => 'Not Found', 'message' => 'Пост не найден'], 404);
        }

        $comments = DB::fetchAll("
            SELECT c.*, u.username, u.display_name, u.avatar 
            FROM post_comments c JOIN users u ON c.user_id = u.id 
            WHERE c.post_id = ? ORDER BY c.id ASC
        ", [(int)$postId]);

        api_response(['success' => true, 'post' => $post, 'comments' => $comments]);
    }

    // Feed listing
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $posts = DB::fetchAll("
        SELECT p.id, p.content, p.media_url, p.media_type, p.likes_count, p.comments_count, p.views_count, p.created_at,
               u.username, u.display_name, u.avatar, u.level
        FROM posts p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");

    $total = (int)DB::fetchColumn("SELECT COUNT(*) FROM posts");

    api_response([
        'success' => true,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => ceil($total / $limit),
        'posts' => $posts
    ]);
}

// POST /api/v1/posts (Create Post)
if ($resource === 'posts' && $requestMethod === 'POST') {
    $content = trim($body['content'] ?? '');
    $mediaUrl = trim($body['media_url'] ?? '');

    if (empty($content) && empty($mediaUrl)) {
        api_response(['error' => 'Bad Request', 'message' => 'Поле content не может быть пустым'], 400);
    }

    $postId = DB::insert('posts', [
        'user_id' => $apiUser['id'],
        'content' => $content,
        'media_url' => $mediaUrl ?: null,
        'media_type' => $mediaUrl ? 'image' : 'none'
    ]);

    Auth::awardCoinsAndXp($apiUser['id'], 10, 15, 'bonus', 'API: публикация поста');

    api_response([
        'success' => true,
        'message' => 'Публикация успешно создана',
        'post_id' => $postId,
        'post_url' => url('post/' . $postId)
    ], 201);
}

// POST /api/v1/posts/{id}/like
if ($resource === 'posts' && isset($endpointParts[2]) && $endpointParts[2] === 'like' && $requestMethod === 'POST') {
    $postId = (int)$endpointParts[1];
    $post = DB::fetch("SELECT id, user_id, likes_count FROM posts WHERE id = ?", [$postId]);
    if (!$post) api_response(['error' => 'Not Found', 'message' => 'Пост не найден'], 404);

    $liked = DB::fetch("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?", [$postId, $apiUser['id']]);
    if ($liked) {
        DB::delete('post_likes', 'id = ?', [$liked['id']]);
        DB::query("UPDATE posts SET likes_count = GREATEST(0, likes_count - 1) WHERE id = ?", [$postId]);
        $status = 'unliked';
    } else {
        DB::insert('post_likes', ['post_id' => $postId, 'user_id' => $apiUser['id'], 'reaction' => 'like']);
        DB::query("UPDATE posts SET likes_count = likes_count + 1 WHERE id = ?", [$postId]);
        $status = 'liked';
    }
    $newCount = (int)DB::fetchColumn("SELECT likes_count FROM posts WHERE id = ?", [$postId]);
    api_response(['success' => true, 'status' => $status, 'likes_count' => $newCount]);
}

// POST /api/v1/posts/{id}/comments
if ($resource === 'posts' && isset($endpointParts[2]) && $endpointParts[2] === 'comments' && $requestMethod === 'POST') {
    $postId = (int)$endpointParts[1];
    $content = trim($body['content'] ?? '');
    if (!$content) api_response(['error' => 'Bad Request', 'message' => 'Текст комментария пуст'], 400);

    $post = DB::fetch("SELECT id, user_id FROM posts WHERE id = ?", [$postId]);
    if (!$post) api_response(['error' => 'Not Found', 'message' => 'Пост не найден'], 404);

    $commentId = DB::insert('post_comments', [
        'post_id' => $postId,
        'user_id' => $apiUser['id'],
        'content' => $content
    ]);
    DB::query("UPDATE posts SET comments_count = comments_count + 1 WHERE id = ?", [$postId]);

    api_response(['success' => true, 'comment_id' => $commentId, 'message' => 'Комментарий успешно добавлен'], 201);
}

// POST /api/v1/wallet/transfer
if ($resource === 'wallet' && isset($endpointParts[1]) && $endpointParts[1] === 'transfer' && $requestMethod === 'POST') {
    $toUsername = strtolower(trim(str_replace('@', '', $body['recipient'] ?? '')));
    $amount = (int)($body['amount'] ?? 0);
    $note = trim($body['note'] ?? 'Перевод через REST API');

    if ($amount <= 0) api_response(['error' => 'Bad Request', 'message' => 'Сумма перевода должна быть > 0'], 400);

    $senderBalance = (int)DB::fetchColumn("SELECT coins FROM users WHERE id = ?", [$apiUser['id']]);
    if ($senderBalance < $amount) {
        api_response(['error' => 'Payment Required', 'message' => 'Недостаточно средств на счете VladCoins'], 402);
    }

    $target = DB::fetch("SELECT id, username FROM users WHERE username = ? LIMIT 1", [$toUsername]);
    if (!$target) api_response(['error' => 'Not Found', 'message' => 'Получатель @' . $toUsername . ' не найден'], 404);
    if ($target['id'] === $apiUser['id']) api_response(['error' => 'Bad Request', 'message' => 'Нельзя переводить себе'], 400);

    DB::query("UPDATE users SET coins = coins - ? WHERE id = ?", [$amount, $apiUser['id']]);
    DB::query("UPDATE users SET coins = coins + ? WHERE id = ?", [$amount, $target['id']]);

    $txId = DB::insert('transactions', [
        'from_user_id' => $apiUser['id'],
        'to_user_id' => $target['id'],
        'amount' => $amount,
        'type' => 'api_transfer',
        'note' => $note
    ]);

    create_notification($target['id'], $apiUser['id'], 'coin_transfer', "🪙 Перевод {$amount} VladCoins через API от @{$apiUser['username']}", url('wallet'));

    api_response([
        'success' => true,
        'transaction_id' => $txId,
        'amount' => $amount,
        'recipient' => $target['username'],
        'remaining_balance' => $senderBalance - $amount
    ]);
}

// GET /api/v1/wallet/balance
if ($resource === 'wallet' && isset($endpointParts[1]) && $endpointParts[1] === 'balance' && $requestMethod === 'GET') {
    $bal = (int)DB::fetchColumn("SELECT coins FROM users WHERE id = ?", [$apiUser['id']]);
    $recentTx = DB::fetchAll("SELECT * FROM transactions WHERE from_user_id = ? OR to_user_id = ? ORDER BY id DESC LIMIT 10", [$apiUser['id'], $apiUser['id']]);
    api_response(['success' => true, 'balance' => $bal, 'currency' => 'VladCoins', 'transactions' => $recentTx]);
}

// Unknown endpoint
api_response([
    'error' => 'Not Found',
    'message' => 'Эндпоинт ' . $requestMethod . ' /api/v1/' . $pathInfo . ' не найден',
    'available_endpoints' => [
        'GET /api/v1/ping',
        'GET /api/v1/me',
        'GET /api/v1/users/{username}',
        'GET /api/v1/posts',
        'GET /api/v1/posts/{id}',
        'POST /api/v1/posts',
        'POST /api/v1/posts/{id}/like',
        'POST /api/v1/posts/{id}/comments',
        'POST /api/v1/wallet/transfer',
        'GET /api/v1/wallet/balance'
    ]
], 404);
