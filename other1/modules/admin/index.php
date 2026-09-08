<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAdmin();
$pageTitle = 'Главная панель администратора VladInc';

// Handle Admin Actions
$tab = $_GET['tab'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        flash_set('error', 'CSRF ошибка');
        redirect('admin?tab=' . $tab);
    }

    $adminAction = $_POST['admin_action'] ?? '';

    // 1. Toggle Ban
    if ($adminAction === 'toggle_ban') {
        $targetId = (int)$_POST['user_id'];
        if ($targetId !== $currentUser['id']) {
            $target = DB::fetch("SELECT is_banned FROM users WHERE id = ?", [$targetId]);
            if ($target) {
                $newStatus = $target['is_banned'] ? 0 : 1;
                DB::update('users', ['is_banned' => $newStatus], 'id = :id', ['id' => $targetId]);
                flash_set('success', $newStatus ? 'Пользователь заблокирован' : 'Пользователь разблокирован');
            }
        }
    }

    // 2. Change Role
    if ($adminAction === 'change_role') {
        $targetId = (int)$_POST['user_id'];
        $newRole = $_POST['role'] ?? 'user';
        if (in_array($newRole, ['user', 'verified', 'creator', 'admin'], true)) {
            DB::update('users', ['role' => $newRole], 'id = :id', ['id' => $targetId]);
            flash_set('success', 'Роль пользователя обновлена');
        }
    }

    // 3. Grant VladCoins / XP
    if ($adminAction === 'adjust_balance') {
        $targetId = (int)$_POST['user_id'];
        $coinsDiff = (int)($_POST['coins'] ?? 0);
        $xpDiff = (int)($_POST['xp'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Корректировка администратором');

        Auth::awardCoinsAndXp($targetId, $coinsDiff, $xpDiff, 'system', $reason);
        flash_set('success', 'Баланс и опыт пользователя успешно изменены!');
    }

    // 4. Delete Post
    if ($adminAction === 'delete_post') {
        $postId = (int)$_POST['post_id'];
        DB::delete('posts', 'id = ?', [$postId]);
        flash_set('success', 'Публикация удалена модератором');
    }

    // 5. Pin / Unpin Post
    if ($adminAction === 'toggle_pin') {
        $postId = (int)$_POST['post_id'];
        $p = DB::fetch("SELECT is_pinned FROM posts WHERE id = ?", [$postId]);
        if ($p) {
            DB::update('posts', ['is_pinned' => $p['is_pinned'] ? 0 : 1], 'id = :id', ['id' => $postId]);
            flash_set('success', 'Статус закрепления обновлен');
        }
    }

    // 6. Add Ecosystem App to 9-Dots Launcher
    if ($adminAction === 'add_ecosystem_app') {
        $name = trim($_POST['app_name'] ?? '');
        $slug = strtolower(trim($_POST['app_slug'] ?? ''));
        $desc = trim($_POST['app_desc'] ?? '');
        $url = trim($_POST['app_url'] ?? '');
        $icon = trim($_POST['app_icon'] ?? 'star');
        $color = trim($_POST['app_color'] ?? '#3b82f6');
        $badge = trim($_POST['app_badge'] ?? '');

        if ($name && $slug && $url) {
            DB::insert('ecosystem_apps', [
                'name' => $name,
                'slug' => $slug,
                'description' => $desc,
                'url' => $url,
                'icon' => $icon,
                'color' => $color,
                'badge' => $badge,
                'sort_order' => 10,
                'is_active' => 1
            ]);
            flash_set('success', 'Новый сервис «' . e($name) . '» добавлен в меню приложений экосистемы!');
        }
    }

    // 7. Toggle Ecosystem App
    if ($adminAction === 'toggle_app') {
        $appId = (int)$_POST['app_id'];
        $app = DB::fetch("SELECT is_active FROM ecosystem_apps WHERE id = ?", [$appId]);
        if ($app) {
            DB::update('ecosystem_apps', ['is_active' => $app['is_active'] ? 0 : 1], 'id = :id', ['id' => $appId]);
            flash_set('success', 'Статус приложения обновлен');
        }
    }

    redirect('admin?tab=' . $tab);
}

// Global Metrics
$stats = [
    'users' => (int)DB::fetchColumn("SELECT COUNT(*) FROM users"),
    'posts' => (int)DB::fetchColumn("SELECT COUNT(*) FROM posts"),
    'coins_total' => (int)DB::fetchColumn("SELECT SUM(coins) FROM users"),
    'communities' => (int)DB::fetchColumn("SELECT COUNT(*) FROM communities"),
    'files' => (int)DB::fetchColumn("SELECT COUNT(*) FROM cloud_files"),
    'transactions' => (int)DB::fetchColumn("SELECT COUNT(*) FROM transactions")
];

// Tab queries
$usersList = [];
if ($tab === 'users') {
    $search = trim($_GET['search'] ?? '');
    if ($search) {
        $s = '%' . $search . '%';
        $usersList = DB::fetchAll("SELECT * FROM users WHERE username LIKE ? OR email LIKE ? OR display_name LIKE ? ORDER BY id DESC LIMIT 50", [$s, $s, $s]);
    } else {
        $usersList = DB::fetchAll("SELECT * FROM users ORDER BY id DESC LIMIT 50");
    }
}

$postsList = [];
if ($tab === 'posts') {
    $postsList = DB::fetchAll("SELECT p.*, u.username, u.display_name FROM posts p JOIN users u ON p.user_id = u.id ORDER BY p.id DESC LIMIT 50");
}

$appsList = [];
if ($tab === 'apps') {
    $appsList = DB::fetchAll("SELECT * FROM ecosystem_apps ORDER BY sort_order ASC");
}

$txList = [];
if ($tab === 'finances') {
    $txList = DB::fetchAll("
        SELECT t.*, u_from.username as from_user, u_to.username as to_user
        FROM transactions t
        LEFT JOIN users u_from ON t.from_user_id = u_from.id
        LEFT JOIN users u_to ON t.to_user_id = u_to.id
        ORDER BY t.id DESC LIMIT 50
    ");
}

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <!-- ADMIN TOP HERO -->
    <div class="card" style="background: linear-gradient(135deg, #1e1b4b, #18181b); border-color: #ef4444; padding: 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <h1 style="font-size: 22px; font-weight: 800; color: #f87171;">🛡️ Главный центр управления VladInc</h1>
                <span style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 10px;">Master SuperAdmin</span>
            </div>
            <p style="color: var(--text-secondary); font-size: 13px;">Полный контроль над пользователями, контентом, финансами и приложениями экосистемы vladinc.ru</p>
        </div>

        <div style="display: flex; gap: 10px;">
            <a href="<?= url('install.php') ?>" target="_blank" class="btn btn-secondary" style="border-color: #3b82f6; color: #60a5fa;">
                🛠️ Открыть Tech Center (install.php)
            </a>
        </div>
    </div>

    <!-- METRICS CARDS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
        <div class="card" style="text-align: center; padding: 18px;">
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Пользователей</div>
            <div style="font-size: 26px; font-weight: 900; color: #60a5fa; margin-top: 4px;"><?= number_format($stats['users']) ?></div>
        </div>
        <div class="card" style="text-align: center; padding: 18px;">
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Публикаций</div>
            <div style="font-size: 26px; font-weight: 900; color: #a78bfa; margin-top: 4px;"><?= number_format($stats['posts']) ?></div>
        </div>
        <div class="card" style="text-align: center; padding: 18px;">
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Coins в обороте</div>
            <div style="font-size: 26px; font-weight: 900; color: #fbbf24; margin-top: 4px;">🪙 <?= format_coins($stats['coins_total']) ?></div>
        </div>
        <div class="card" style="text-align: center; padding: 18px;">
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Сообществ</div>
            <div style="font-size: 26px; font-weight: 900; color: #34d399; margin-top: 4px;"><?= number_format($stats['communities']) ?></div>
        </div>
        <div class="card" style="text-align: center; padding: 18px;">
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Файлов в Cloud</div>
            <div style="font-size: 26px; font-weight: 900; color: #38bdf8; margin-top: 4px;"><?= number_format($stats['files']) ?></div>
        </div>
    </div>

    <!-- ADMIN TABS -->
    <div style="display: flex; gap: 8px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; flex-wrap: wrap;">
        <a href="<?= url('admin?tab=dashboard') ?>" class="btn btn-sm <?= $tab === 'dashboard' ? 'btn-primary' : 'btn-secondary' ?>">📊 Обзор</a>
        <a href="<?= url('admin?tab=users') ?>" class="btn btn-sm <?= $tab === 'users' ? 'btn-primary' : 'btn-secondary' ?>">👥 Пользователи</a>
        <a href="<?= url('admin?tab=posts') ?>" class="btn btn-sm <?= $tab === 'posts' ? 'btn-primary' : 'btn-secondary' ?>">📰 Модерация постов</a>
        <a href="<?= url('admin?tab=apps') ?>" class="btn btn-sm <?= $tab === 'apps' ? 'btn-primary' : 'btn-secondary' ?>">📱 Сервисы & 9-Dots</a>
        <a href="<?= url('admin?tab=finances') ?>" class="btn btn-sm <?= $tab === 'finances' ? 'btn-primary' : 'btn-secondary' ?>">🪙 Финансовый аудит</a>
    </div>

    <!-- TAB: DASHBOARD -->
    <?php if ($tab === 'dashboard'): ?>
        <div class="card">
            <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 14px;">Системная сводка</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div style="background: var(--bg-input); padding: 16px; border-radius: 12px;">
                    <h4 style="font-size: 14px; margin-bottom: 10px; color: #60a5fa;">Информация о сервере</h4>
                    <div style="font-size: 13px; line-height: 1.8; color: var(--text-secondary);">
                        <div><strong>PHP Версия:</strong> <?= PHP_VERSION ?></div>
                        <div><strong>Веб-сервер:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Apache/Nginx' ?></div>
                        <div><strong>Домен:</strong> <?= APP_DOMAIN ?></div>
                        <div><strong>Папка uploads:</strong> <?= is_writable(UPLOADS_PATH) ? '✓ Доступна для записи' : '✗ Ошибка прав' ?></div>
                    </div>
                </div>

                <div style="background: var(--bg-input); padding: 16px; border-radius: 12px;">
                    <h4 style="font-size: 14px; margin-bottom: 10px; color: #a78bfa;">Изоляция сервисов хостинга</h4>
                    <div style="font-size: 13px; line-height: 1.8; color: var(--text-secondary);">
                        <div><strong>Папка /mama:</strong> Защищена в .htaccess и updater</div>
                        <div><strong>Папка /shibalingo:</strong> Защищена в .htaccess и updater</div>
                        <div><strong>Автономный Tech Center:</strong> <code>install.php</code> в корне</div>
                        <div><strong>Бэкапы:</strong> Доступны в один клик</div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB: USERS -->
    <?php if ($tab === 'users'): ?>
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px;">
                <h3 style="font-size: 17px; font-weight: 700;">Управление пользователями</h3>
                <form method="GET" action="<?= url('admin') ?>" style="display: flex; gap: 8px;">
                    <input type="hidden" name="tab" value="users">
                    <input type="text" name="search" class="form-control" placeholder="Поиск пользователя..." value="<?= e($_GET['search'] ?? '') ?>" style="padding: 6px 12px; font-size: 13px;">
                    <button type="submit" class="btn btn-secondary btn-sm">Найти</button>
                </form>
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color); text-align: left; color: var(--text-muted);">
                            <th style="padding: 10px;">ID</th>
                            <th style="padding: 10px;">Пользователь</th>
                            <th style="padding: 10px;">Email</th>
                            <th style="padding: 10px;">Роль</th>
                            <th style="padding: 10px;">Coins / XP</th>
                            <th style="padding: 10px;">Статус</th>
                            <th style="padding: 10px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersList as $u): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px;">#<?= $u['id'] ?></td>
                                <td style="padding: 10px; font-weight: 700;">
                                    <a href="<?= url('profile/@' . $u['username']) ?>" target="_blank" style="color: var(--accent-primary);">
                                        <?= e($u['display_name']) ?> (@<?= e($u['username']) ?>)
                                    </a>
                                </td>
                                <td style="padding: 10px; color: var(--text-muted);"><?= e($u['email']) ?></td>
                                <td style="padding: 10px;">
                                    <form method="POST" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="admin_action" value="change_role">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <select name="role" onchange="this.form.submit()" style="background: var(--bg-input); color: var(--text-primary); border: 1px solid var(--border-color); border-radius: 4px; padding: 2px 6px;">
                                            <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                            <option value="verified" <?= $u['role'] === 'verified' ? 'selected' : '' ?>>Verified</option>
                                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>
                                    </form>
                                </td>
                                <td style="padding: 10px;">
                                    <span style="color: #fbbf24; font-weight: 700;">🪙 <?= format_coins($u['coins']) ?></span><br>
                                    <span style="color: #c084fc; font-size: 11px;">LVL <?= $u['level'] ?> (<?= $u['xp'] ?> XP)</span>
                                </td>
                                <td style="padding: 10px;">
                                    <span style="color: <?= $u['is_banned'] ? '#f87171' : '#34d399' ?>; font-weight: 700;">
                                        <?= $u['is_banned'] ? 'Забанен' : 'Активен' ?>
                                    </span>
                                </td>
                                <td style="padding: 10px;">
                                    <div style="display: flex; gap: 6px;">
                                        <!-- Ban/Unban -->
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="admin_action" value="toggle_ban">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="padding: 3px 8px; font-size: 11px; color: <?= $u['is_banned'] ? '#34d399' : '#f87171' ?>;">
                                                <?= $u['is_banned'] ? 'Разбан' : 'Бан' ?>
                                            </button>
                                        </form>

                                        <!-- Give Coins -->
                                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 3px 8px; font-size: 11px; color: #fbbf24;" onclick="promptAdjust(<?= $u['id'] ?>, '<?= e($u['username']) ?>')">
                                            +Coins
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB: POSTS -->
    <?php if ($tab === 'posts'): ?>
        <div class="card">
            <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 16px;">Модерация публикаций</h3>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($postsList as $p): ?>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; padding: 12px 16px; background: var(--bg-input); border-radius: var(--radius-md);">
                        <div style="flex: 1; min-width: 0; margin-right: 16px;">
                            <div style="font-size: 13px; font-weight: 700; margin-bottom: 4px;">
                                <?= e($p['display_name']) ?> (@<?= e($p['username']) ?>) &bull; <span style="color: var(--text-muted); font-weight: normal;"><?= time_ago($p['created_at']) ?></span>
                                <?php if ($p['is_pinned']): ?>
                                    <span style="color: var(--accent-primary); margin-left: 8px;">📌 Закреплен</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 14px; color: var(--text-secondary);"><?= e(mb_strimwidth($p['content'], 0, 160, '...')) ?></div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                                Лайков: <?= $p['likes_count'] ?> &bull; Комментариев: <?= $p['comments_count'] ?>
                            </div>
                        </div>

                        <div style="display: flex; gap: 6px;">
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="admin_action" value="toggle_pin">
                                <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size: 11px;">
                                    <?= $p['is_pinned'] ? 'Открепить' : 'Закрепить' ?>
                                </button>
                            </form>

                            <form method="POST" onsubmit="return confirm('Удалить этот пост навсегда?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="admin_action" value="delete_post">
                                <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size: 11px; color: #ef4444;">
                                    Удалить
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB: APPS REGISTRY -->
    <?php if ($tab === 'apps'): ?>
        <div class="card">
            <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 6px;">Сервисы экосистемы & 9-Dots Меню</h3>
            <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">
                Здесь вы можете подключать любые новые проекты, внешние ссылки или модули к общему выпадающему меню 9 точек в шапке.
            </p>

            <form method="POST" style="background: var(--bg-input); padding: 18px; border-radius: 12px; margin-bottom: 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="admin_action" value="add_ecosystem_app">
                <h4 style="font-size: 14px; margin-bottom: 12px; color: #60a5fa;">+ Добавить новый сервис в меню</h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Название</label>
                        <input type="text" name="app_name" class="form-control" placeholder="VladPay / Игры" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Идентификатор (slug)</label>
                        <input type="text" name="app_slug" class="form-control" placeholder="my-service" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">URL (относительный или http)</label>
                        <input type="text" name="app_url" class="form-control" placeholder="/games или https://..." required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Иконка (users, wallet, cloud, game...)</label>
                        <input type="text" name="app_icon" class="form-control" value="star">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Цвет иконки (HEX)</label>
                        <input type="color" name="app_color" class="form-control" value="#3b82f6" style="height: 42px; padding: 2px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Бейдж (New, Core, Beta...)</label>
                        <input type="text" name="app_badge" class="form-control" placeholder="New">
                    </div>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Краткое описание</label>
                    <input type="text" name="app_desc" class="form-control" placeholder="Описание сервиса">
                </div>

                <button type="submit" class="btn btn-primary btn-sm">Добавить в меню экосистемы</button>
            </form>

            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($appsList as $app): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bg-input); border-radius: var(--radius-md);">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: <?= e($app['color']) ?>25; color: <?= e($app['color']) ?>; display: flex; align-items: center; justify-content: center; font-weight: 800;">
                                ★
                            </div>
                            <div>
                                <div style="font-size: 14px; font-weight: 700;">
                                    <?= e($app['name']) ?>
                                    <?php if (!empty($app['badge'])): ?>
                                        <span class="level-badge"><?= e($app['badge']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <?= e($app['url']) ?> &bull; <?= e($app['description']) ?>
                                </div>
                            </div>
                        </div>

                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="admin_action" value="toggle_app">
                            <input type="hidden" name="app_id" value="<?= $app['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" style="color: <?= $app['is_active'] ? '#34d399' : '#f87171' ?>;">
                                <?= $app['is_active'] ? '✓ Активен' : '✗ Отключен' ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB: FINANCES -->
    <?php if ($tab === 'finances'): ?>
        <div class="card">
            <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 16px;">Финансовый аудит экосистемы</h3>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($txList as $tx): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: var(--bg-input); border-radius: 8px; font-size: 13px;">
                        <div>
                            <strong>#<?= $tx['id'] ?></strong> &bull;
                            От: <?= e($tx['from_user'] ?: 'Система/Бонус') ?> &rarr;
                            Кому: <strong><?= e($tx['to_user']) ?></strong> &bull;
                            <span style="color: var(--text-muted);"><?= e($tx['note'] ?: $tx['type']) ?></span>
                        </div>
                        <div style="font-weight: 800; color: #fbbf24;">
                            <?= number_format($tx['amount'], 0, '.', ' ') ?> 🪙
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- ADJUST BALANCE FORM MODAL -->
<div id="adjust-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
    <div class="card" style="max-width: 440px; margin: 100px auto; position: relative;">
        <button type="button" onclick="document.getElementById('adjust-modal').style.display='none'" style="position: absolute; right: 16px; top: 16px; background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;">✕</button>
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 6px;">Изменить баланс пользователя</h3>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;" id="adjust-user-label"></p>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="admin_action" value="adjust_balance">
            <input type="hidden" name="user_id" id="adjust_user_id" value="">

            <div class="form-group" style="margin-bottom: 12px;">
                <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Добавить/списать VladCoins (+/-)</label>
                <input type="number" name="coins" class="form-control" placeholder="+500 или -200" required>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Добавить/списать опыт XP (+/-)</label>
                <input type="number" name="xp" class="form-control" placeholder="+100" value="0">
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Причина / Примечание</label>
                <input type="text" name="reason" class="form-control" placeholder="Бонус за активность">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">Применить изменение</button>
        </form>
    </div>
</div>

<script>
function promptAdjust(userId, username) {
    document.getElementById('adjust_user_id').value = userId;
    document.getElementById('adjust-user-label').innerText = 'Пользователь: @' + username;
    document.getElementById('adjust-modal').style.display = 'block';
}
</script>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
