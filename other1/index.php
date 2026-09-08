<?php
/**
 * VladInc Ecosystem Front-Controller & Clean URL Router (ЧПУ)
 * Domain: vladinc.ru
 */

define('VLADINC_INIT', true);

require_once __DIR__ . '/core/config.php';
require_once CORE_PATH . '/db.php';
require_once CORE_PATH . '/helpers.php';
require_once CORE_PATH . '/auth.php';

// If DB connection is unconfigured, redirect to install.php
$pdo = DB::connect();
if (!$pdo) {
    if (file_exists(__DIR__ . '/install.php')) {
        header('Location: ' . rtrim(BASE_URL, '/') . '/install.php');
        exit;
    } else {
        die("<h3>Экосистема VladInc не установлена. Запустите install.php</h3>");
    }
}

// Request path parsing for ЧПУ
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');

$uriPath = parse_url($requestUri, PHP_URL_PATH) ?? '/';
if ($scriptDir !== '/' && strpos($uriPath, $scriptDir) === 0) {
    $uriPath = substr($uriPath, strlen($scriptDir));
}
$uriPath = trim($uriPath, '/');

// Parse segments
$segments = !empty($uriPath) ? explode('/', $uriPath) : [];
$route = $segments[0] ?? 'feed';

// Route matching
switch ($route) {
    // 1. Social Feed (ЧПУ: / or /feed or /social or /feed/popular)
    case '':
    case 'feed':
    case 'social':
        if (isset($segments[1]) && in_array($segments[1], ['popular', 'following', 'media'], true)) {
            $_GET['filter'] = $segments[1];
        }
        require_once MODULES_PATH . '/social/index.php';
        break;

    // 2. Single Post View (ЧПУ: /post/123)
    case 'post':
        $postId = (int)($segments[1] ?? 0);
        require_once MODULES_PATH . '/social/post.php';
        break;

    // 3. Bookmarks (ЧПУ: /bookmarks)
    case 'bookmarks':
        require_once MODULES_PATH . '/bookmarks/index.php';
        break;

    // 4. Communities (ЧПУ: /communities or /c/slug)
    case 'communities':
        require_once MODULES_PATH . '/communities/index.php';
        break;

    case 'c':
        $_GET['comm'] = $segments[1] ?? '';
        require_once MODULES_PATH . '/communities/index.php';
        break;

    // 5. Messenger (ЧПУ: /messages or /im or /messages/123)
    case 'messages':
    case 'im':
    case 'messenger':
        if (isset($segments[1]) && is_numeric($segments[1])) {
            $_GET['chat_id'] = (int)$segments[1];
        } elseif (isset($segments[1])) {
            $_GET['with'] = $segments[1];
        }
        require_once MODULES_PATH . '/messenger/index.php';
        break;

    // 6. Developer Portal & REST API Keys (ЧПУ: /developers)
    case 'developers':
    case 'dev':
        require_once MODULES_PATH . '/developers/index.php';
        break;

    // 7. External REST API v1 (ЧПУ: /api/v1/...)
    case 'api':
        if (isset($segments[1]) && $segments[1] === 'v1') {
            $_GET['endpoint'] = implode('/', array_slice($segments, 2));
            require_once ROOT_PATH . '/api/v1/index.php';
            exit;
        }
        require_once ROOT_PATH . '/api/index.php';
        exit;

    // 8. User Profile (ЧПУ: /u/username or /profile/@username or /@username)
    case 'u':
    case 'profile':
        $profileUsername = $segments[1] ?? ($_GET['u'] ?? '');
        require_once MODULES_PATH . '/profile/index.php';
        break;

    // 9. VladID Settings (ЧПУ: /settings or /id)
    case 'settings':
    case 'id':
        require_once MODULES_PATH . '/id/index.php';
        break;

    // 10. VladPay Wallet (ЧПУ: /wallet)
    case 'wallet':
        require_once MODULES_PATH . '/wallet/index.php';
        break;

    // 11. Vlad Arcade (ЧПУ: /arcade)
    case 'arcade':
        require_once MODULES_PATH . '/arcade/index.php';
        break;

    // 12. Cloud Box (ЧПУ: /cloud)
    case 'cloud':
        require_once MODULES_PATH . '/cloud/index.php';
        break;

    // 13. Explore & Search (ЧПУ: /explore)
    case 'explore':
        require_once MODULES_PATH . '/explore/index.php';
        break;

    // 14. Notifications (ЧПУ: /notifications)
    case 'notifications':
        require_once MODULES_PATH . '/notifications/index.php';
        break;

    // 15. Master Admin Center (ЧПУ: /admin or /admin/users etc)
    case 'admin':
        if (isset($segments[1])) {
            $_GET['tab'] = $segments[1];
        }
        require_once MODULES_PATH . '/admin/index.php';
        break;

    // 16. Authentication
    case 'login':
        require_once MODULES_PATH . '/auth/login.php';
        break;

    case 'register':
        require_once MODULES_PATH . '/auth/register.php';
        break;

    case 'logout':
        Auth::logout();
        flash_set('info', 'Вы успешно вышли из аккаунта');
        redirect('feed');
        break;

    default:
        // Handle @username directly (e.g. /@alex)
        if (strpos($route, '@') === 0) {
            $profileUsername = $route;
            require_once MODULES_PATH . '/profile/index.php';
            break;
        }

        // 404
        http_response_code(404);
        $pageTitle = '404 — Страница не найдена';
        require_once TEMPLATES_PATH . '/header.php';
        echo '<div style="grid-column: span 2; text-align: center; padding: 60px 20px;">
                <h1 style="font-size: 60px; font-weight: 900; color: #3b82f6;">404</h1>
                <h2 style="font-size: 20px; margin-bottom: 12px;">Страница не найдена в экосистеме VladInc</h2>
                <p style="color: var(--text-muted); margin-bottom: 24px;">Возможно, адрес введен неверно или сервис был перемещен.</p>
                <a href="' . url('feed') . '" class="btn btn-primary">Вернуться в ленту</a>
              </div>';
        require_once TEMPLATES_PATH . '/footer.php';
        break;
}
