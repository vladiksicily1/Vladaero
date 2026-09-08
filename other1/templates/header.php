<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::user();
$currentRoute = $route ?? 'feed';

// Fetch registered ecosystem apps for the 9-dots launcher
$ecosystemApps = [];
try {
    $ecosystemApps = DB::fetchAll("SELECT * FROM ecosystem_apps WHERE is_active = 1 ORDER BY sort_order ASC");
} catch (Exception $e) {}

// Unread notifications count
$unreadNotifs = 0;
if ($currentUser) {
    try {
        $unreadNotifs = (int)DB::fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$currentUser['id']]);
    } catch (Exception $e) {}
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?= e($currentUser['theme_mode'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' . APP_NAME : APP_NAME . ' — ' . APP_TAGLINE ?></title>
    
    <!-- CSS Design System -->
    <link rel="stylesheet" href="<?= asset('css/vladinc.css') ?>?v=<?= APP_VERSION ?>">
    
    <script>
        const baseUrl = "<?= rtrim(BASE_URL, '/') ?>";
    </script>
</head>
<body>

    <!-- TOP NAVBAR -->
    <header class="navbar">
        <div class="nav-left">
            <a href="<?= url('feed') ?>" class="brand-logo">
                <div class="brand-icon">V</div>
                <div class="brand-name">Vlad<span>Inc</span></div>
            </a>
            
            <form action="<?= url('explore') ?>" method="GET" class="nav-search">
                <span class="search-icon">🔍</span>
                <input type="text" name="q" placeholder="Поиск в экосистеме..." value="<?= e($_GET['q'] ?? '') ?>">
            </form>
        </div>

        <div class="nav-right">
            <?php if ($currentUser): ?>
                <!-- VladCoin Balance Pill -->
                <a href="<?= url('wallet') ?>" class="coin-pill" title="Ваш баланс VladCoin. Нажмите для открытия кошелька">
                    <span>🪙</span>
                    <span id="nav-user-coins"><?= format_coins($currentUser['coins']) ?></span>
                </a>

                <!-- Notifications Bell -->
                <a href="<?= url('notifications') ?>" class="icon-btn" title="Уведомления">
                    <span>🔔</span>
                    <?php if ($unreadNotifs > 0): ?>
                        <span class="badge-counter"><?= $unreadNotifs ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <!-- Theme Toggle -->
            <button type="button" id="theme-toggle-btn" class="icon-btn" title="Переключить тему">
                <?= ($currentUser['theme_mode'] ?? 'dark') === 'dark' ? '☀️' : '🌙' ?>
            </button>

            <!-- 9-Dots Ecosystem Launcher Grid Button -->
            <div style="position: relative;">
                <button type="button" id="launcher-btn" class="icon-btn" title="Сервисы экосистемы VladInc">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <circle cx="5" cy="5" r="2.5"/><circle cx="12" cy="5" r="2.5"/><circle cx="19" cy="5" r="2.5"/>
                        <circle cx="5" cy="12" r="2.5"/><circle cx="12" cy="12" r="2.5"/><circle cx="19" cy="12" r="2.5"/>
                        <circle cx="5" cy="19" r="2.5"/><circle cx="12" cy="19" r="2.5"/><circle cx="19" cy="19" r="2.5"/>
                    </svg>
                </button>

                <!-- 9-Dots Launcher Dropdown Grid -->
                <div class="launcher-dropdown" id="launcher-dropdown">
                    <div class="launcher-title">
                        <span>Сервисы VladInc</span>
                        <span style="font-size: 11px; color: var(--accent-primary);">vladinc.ru</span>
                    </div>
                    <div class="apps-grid">
                        <?php foreach ($ecosystemApps as $app): ?>
                            <a href="<?= url(ltrim($app['url'], '/')) ?>" class="app-tile">
                                <?php if (!empty($app['badge'])): ?>
                                    <span class="app-badge-tag"><?= e($app['badge']) ?></span>
                                <?php endif; ?>
                                <div class="app-icon-wrap" style="background: <?= e($app['color']) ?>20; color: <?= e($app['color']) ?>;">
                                    <?php
                                        $iconMap = [
                                            'users' => '🌐',
                                            'message-circle' => '💬',
                                            'compass' => '👥',
                                            'shield' => '🆔',
                                            'wallet' => '🪙',
                                            'gamepad-2' => '🎮',
                                            'cloud' => '📁',
                                            'bookmark' => '📑',
                                            'code' => '⚡',
                                            'search' => '🔍'
                                        ];
                                        echo $iconMap[$app['icon']] ?? '★';
                                    ?>
                                </div>
                                <span class="app-name"><?= e($app['name']) ?></span>
                            </a>
                        <?php endforeach; ?>

                        <?php if (Auth::isAdmin()): ?>
                            <a href="<?= url('admin') ?>" class="app-tile">
                                <span class="app-badge-tag" style="background: #ef4444;">Admin</span>
                                <div class="app-icon-wrap" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">
                                    ⚙️
                                </div>
                                <span class="app-name">Админка</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- User Profile Dropdown or Auth Buttons -->
            <?php if ($currentUser): ?>
                <div class="user-menu-wrap">
                    <button type="button" class="nav-avatar-btn" id="user-menu-btn">
                        <img src="<?= e(url($currentUser['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" alt="Avatar" class="avatar-img" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($currentUser['display_name']) ?>&background=3b82f6&color=fff'">
                    </button>

                    <div class="user-menu-dropdown" id="user-menu-dropdown">
                        <div class="menu-user-info">
                            <div class="menu-display-name"><?= e($currentUser['display_name']) ?></div>
                            <div class="menu-username">@<?= e($currentUser['username']) ?> &bull; Ур. <?= $currentUser['level'] ?></div>
                        </div>
                        <a href="<?= url('u/' . $currentUser['username']) ?>" class="dropdown-link">
                            <span>👤</span> Мой профиль
                        </a>
                        <a href="<?= url('bookmarks') ?>" class="dropdown-link">
                            <span>📑</span> Мои закладки
                        </a>
                        <a href="<?= url('settings') ?>" class="dropdown-link">
                            <span>🆔</span> Настройки Vlad ID
                        </a>
                        <a href="<?= url('developers') ?>" class="dropdown-link">
                            <span>⚡</span> REST API & Ключи
                        </a>
                        <a href="<?= url('wallet') ?>" class="dropdown-link">
                            <span>🪙</span> Кошелек (<?= format_coins($currentUser['coins']) ?> Coins)
                        </a>
                        <?php if (Auth::isAdmin()): ?>
                            <div class="dropdown-divider"></div>
                            <a href="<?= url('admin') ?>" class="dropdown-link" style="color: #f87171;">
                                <span>⚙️</span> Главная Админка
                            </a>
                            <a href="<?= url('install.php') ?>" target="_blank" class="dropdown-link" style="color: #60a5fa;">
                                <span>🛠️</span> Tech Center (install.php)
                            </a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <a href="<?= url('logout') ?>" class="dropdown-link" style="color: var(--text-muted);">
                            <span>🚪</span> Выйти
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= url('login') ?>" class="btn btn-secondary btn-sm">Войти</a>
                <a href="<?= url('register') ?>" class="btn btn-primary btn-sm">Регистрация</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- FLASH MESSAGES -->
    <?php if ($flash): ?>
        <div style="max-width: 1280px; margin: 16px auto 0; padding: 0 20px;">
            <div class="toast toast-<?= e($flash['type']) ?>" style="position: static; animation: none;">
                <span><?= $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'error' ? '❌' : 'ℹ️') ?></span>
                <span><?= e($flash['message']) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- MAIN CONTAINER -->
    <div class="main-wrapper">
        
        <!-- LEFT SIDEBAR -->
        <aside class="sidebar">
            <nav class="sidebar-nav">
                <a href="<?= url('feed') ?>" class="nav-item <?= in_array($currentRoute, ['feed', 'social', 'post'], true) ? 'active' : '' ?>">
                    <span class="nav-item-icon">📰</span>
                    <span class="nav-item-text">Лента</span>
                </a>
                <a href="<?= url('messages') ?>" class="nav-item <?= in_array($currentRoute, ['messages', 'im', 'messenger'], true) ? 'active' : '' ?>">
                    <span class="nav-item-icon">💬</span>
                    <span class="nav-item-text">VladChat</span>
                </a>
                <a href="<?= url('communities') ?>" class="nav-item <?= in_array($currentRoute, ['communities', 'c'], true) ? 'active' : '' ?>">
                    <span class="nav-item-icon">👥</span>
                    <span class="nav-item-text">Сообщества</span>
                </a>
                <a href="<?= url('bookmarks') ?>" class="nav-item <?= $currentRoute === 'bookmarks' ? 'active' : '' ?>">
                    <span class="nav-item-icon">📑</span>
                    <span class="nav-item-text">Закладки</span>
                </a>
                <a href="<?= url('wallet') ?>" class="nav-item <?= $currentRoute === 'wallet' ? 'active' : '' ?>">
                    <span class="nav-item-icon">🪙</span>
                    <span class="nav-item-text">VladPay</span>
                </a>
                <a href="<?= url('arcade') ?>" class="nav-item <?= $currentRoute === 'arcade' ? 'active' : '' ?>">
                    <span class="nav-item-icon">🎮</span>
                    <span class="nav-item-text">Аркада</span>
                </a>
                <a href="<?= url('cloud') ?>" class="nav-item <?= $currentRoute === 'cloud' ? 'active' : '' ?>">
                    <span class="nav-item-icon">📁</span>
                    <span class="nav-item-text">Cloud Box</span>
                </a>
                <a href="<?= url('developers') ?>" class="nav-item <?= in_array($currentRoute, ['developers', 'dev'], true) ? 'active' : '' ?>">
                    <span class="nav-item-icon">⚡</span>
                    <span class="nav-item-text">REST API</span>
                </a>
                <a href="<?= url('explore') ?>" class="nav-item <?= $currentRoute === 'explore' ? 'active' : '' ?>">
                    <span class="nav-item-icon">🔍</span>
                    <span class="nav-item-text">Навигатор</span>
                </a>
                <a href="<?= url('settings') ?>" class="nav-item <?= in_array($currentRoute, ['settings', 'id'], true) ? 'active' : '' ?>">
                    <span class="nav-item-icon">🆔</span>
                    <span class="nav-item-text">Vlad ID</span>
                </a>

                <?php if (Auth::isAdmin()): ?>
                    <div style="margin: 14px 0 6px 16px; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                        Управление
                    </div>
                    <a href="<?= url('admin') ?>" class="nav-item <?= $currentRoute === 'admin' ? 'active' : '' ?>" style="color: #f87171;">
                        <span class="nav-item-icon">⚙️</span>
                        <span class="nav-item-text">Админка</span>
                    </a>
                <?php endif; ?>
            </nav>
        </aside>
