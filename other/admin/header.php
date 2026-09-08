<?php
/**
 * Admin Panel Header
 */

require_once __DIR__ . '/auth_check.php';

$admin = requireAdminAuth();
$adminPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($adminTitle ?? 'Панель управления') ?> — ShibaLingo Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/animations.css">
    <script src="../assets/js/markdown-renderer.js"></script>
    <style>
        .admin-sidebar { width: 280px; }
        .admin-main { margin-left: 280px; padding: 32px; width: calc(100% - 280px); min-height: 100vh; }
        .table-responsive { width: 100%; overflow-x: auto; }
        .badge-role { padding: 4px 8px; border-radius: 6px; font-weight: 800; font-size: 0.8rem; }
        @media (max-width: 992px) {
            .admin-main { margin-left: 0 !important; padding: 16px !important; width: 100% !important; }
            body { display: block !important; }
        }
    </style>
    <script>
    function toggleAdminSidebar(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        var sidebar = document.getElementById('admin-sidebar');
        var backdrop = document.getElementById('admin-sidebar-backdrop');
        if (!sidebar) return;
        var isOpen = sidebar.classList.toggle('open');
        if (backdrop) backdrop.classList.toggle('active', isOpen);
        if (window.innerWidth <= 992) {
            document.body.style.overflow = isOpen ? 'hidden' : '';
        }
    }
    </script>
</head>
<body>

<div class="app-container">
    <!-- Admin Sidebar -->
    <aside class="sidebar admin-sidebar" id="admin-sidebar">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0 4px 16px 4px;">
            <a href="index.php" class="logo-brand" style="color: var(--secondary); padding: 0;">
                <span class="logo-icon">🐕</span>
                <span>Shiba Admin</span>
            </a>
            <button type="button" class="sidebar-close-btn" onclick="toggleAdminSidebar(event)" aria-label="Закрыть" title="Закрыть меню">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div style="padding: 0 16px 16px 16px; margin-bottom: 12px; border-bottom: 2px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-weight: 800; font-size: 0.95rem;"><?= e($admin['username']) ?></div>
                <div style="font-size: 0.75rem; color: var(--primary); font-weight: 800; text-transform: uppercase;">
                    <?= e($admin['role_name'] ?? 'SuperAdmin') ?>
                </div>
            </div>
            <a href="logout.php" style="color: var(--danger); text-decoration: none; font-size: 1.1rem;" title="Выйти">
                🚪
            </a>
        </div>

        <?php
        $dbDriver = Database::getDriver();
        $isMySql = ($dbDriver === 'mysql');
        ?>
        <div style="margin: 0 16px 12px 16px; padding: 6px 10px; background: <?= $isMySql ? 'rgba(88,204,2,0.12)' : 'rgba(28,176,246,0.12)' ?>; border: 1px solid <?= $isMySql ? 'rgba(88,204,2,0.3)' : 'rgba(28,176,246,0.3)' ?>; border-radius: 8px; font-size: 0.75rem; font-weight: 800; display: flex; align-items: center; justify-content: space-between;" title="<?= $isMySql ? 'Подключена основная база данных MySQL (' . e(DB_NAME) . ')' : 'Работает локальная файловая база данных SQLite (' . e(basename(SQLITE_PATH)) . ')' ?>">
            <span style="color: var(--text-muted); display: flex; align-items: center; gap: 4px;">🗄️ СУБД:</span>
            <span style="color: <?= $isMySql ? '#166534' : '#0369a1' ?>; display: inline-flex; align-items: center; gap: 5px;">
                <span style="width: 7px; height: 7px; border-radius: 50%; background: <?= $isMySql ? '#22c55e' : '#0284c7' ?>; display: inline-block; box-shadow: 0 0 6px <?= $isMySql ? '#22c55e' : '#0284c7' ?>;"></span>
                <?= $isMySql ? 'MySQL' : 'SQLite' ?>
            </span>
        </div>

        <ul class="nav-menu" style="overflow-y: auto;">
            <li class="nav-item <?= ($adminPage === 'index.php') ? 'active' : '' ?>">
                <a href="index.php"><span class="nav-icon">📊</span><span>Дашборд</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'agent.php') ? 'active' : '' ?>" style="background: linear-gradient(135deg, rgba(88,204,2,0.15), rgba(28,176,246,0.15)); border-radius: 12px; margin-bottom: 4px;">
                <a href="agent.php" style="color: var(--primary); font-weight: 900;"><span class="nav-icon">🤖</span><span>Автономный AI Агент</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'ai_studio.php') ? 'active' : '' ?>" style="background: linear-gradient(135deg, rgba(28,176,246,0.1), rgba(88,204,2,0.1)); border-radius: 12px; margin-bottom: 6px;">
                <a href="ai_studio.php" style="color: var(--secondary); font-weight: 800;"><span class="nav-icon">🏭</span><span>AI Фабрика контента</span></a>
            </li>
            
            <?php if (hasPermission($admin, 'manage_users')): ?>
            <li class="nav-item <?= ($adminPage === 'users.php') ? 'active' : '' ?>">
                <a href="users.php"><span class="nav-icon">👥</span><span>Пользователи</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'roles.php') ? 'active' : '' ?>">
                <a href="roles.php"><span class="nav-icon">🛡️</span><span>Роли & RBAC</span></a>
            </li>
            <?php endif; ?>

            <?php if (hasPermission($admin, 'manage_languages')): ?>
            <li class="nav-item <?= ($adminPage === 'languages.php') ? 'active' : '' ?>">
                <a href="languages.php"><span class="nav-icon">🌐</span><span>Языки</span></a>
            </li>
            <?php endif; ?>

            <?php if (hasPermission($admin, 'manage_lessons')): ?>
            <li class="nav-item <?= ($adminPage === 'skills.php') ? 'active' : '' ?>">
                <a href="skills.php"><span class="nav-icon">🗺️</span><span>Дерево навыков</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'lessons.php') ? 'active' : '' ?>">
                <a href="lessons.php"><span class="nav-icon">📚</span><span>Уроки (CRUD & AI)</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'stories.php') ? 'active' : '' ?>">
                <a href="stories.php"><span class="nav-icon">📖</span><span>Истории (Stories)</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'quests.php') ? 'active' : '' ?>">
                <a href="quests.php"><span class="nav-icon">🎯</span><span>Квесты</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'achievements.php') ? 'active' : '' ?>">
                <a href="achievements.php"><span class="nav-icon">🏅</span><span>Достижения</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'shop.php') ? 'active' : '' ?>">
                <a href="shop.php"><span class="nav-icon">🛍️</span><span>Товары & Скины</span></a>
            </li>
            <?php endif; ?>

            <?php if (hasPermission($admin, 'manage_conlang')): ?>
            <li class="nav-item <?= ($adminPage === 'dictionary.php') ? 'active' : '' ?>">
                <a href="dictionary.php"><span class="nav-icon">📖</span><span>Словарь Conlang</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'grammar.php') ? 'active' : '' ?>">
                <a href="grammar.php"><span class="nav-icon">📐</span><span>Грамматика</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'slang.php') ? 'active' : '' ?>">
                <a href="slang.php"><span class="nav-icon">🗣️</span><span>Сленг сообщества</span></a>
            </li>
            <?php endif; ?>

            <li class="nav-item <?= ($adminPage === 'raids.php') ? 'active' : '' ?>">
                <a href="raids.php"><span class="nav-icon">🐉</span><span>Босс-рейды</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'classrooms.php') ? 'active' : '' ?>">
                <a href="classrooms.php"><span class="nav-icon">🧑‍🏫</span><span>Классы & Учителя</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'duels.php') ? 'active' : '' ?>">
                <a href="duels.php"><span class="nav-icon">⚔️</span><span>PvP Дуэли</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'broadcast.php') ? 'active' : '' ?>">
                <a href="broadcast.php"><span class="nav-icon">📢</span><span>Объявления</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'chat_logs.php') ? 'active' : '' ?>">
                <a href="chat_logs.php"><span class="nav-icon">💬</span><span>Логи AI Чата</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'reports.php') ? 'active' : '' ?>">
                <a href="reports.php"><span class="nav-icon">🚩</span><span>Репорты ошибок</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'media.php') ? 'active' : '' ?>">
                <a href="media.php"><span class="nav-icon">📁</span><span>Медиатека (Uploads)</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'telegram.php') ? 'active' : '' ?>">
                <a href="telegram.php"><span class="nav-icon">✈️</span><span>Telegram Бот</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'promocodes.php') ? 'active' : '' ?>">
                <a href="promocodes.php"><span class="nav-icon">🎁</span><span>Промокоды</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'ai_analytics.php') ? 'active' : '' ?>">
                <a href="ai_analytics.php"><span class="nav-icon">📊</span><span>AI Аналитика</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'import_export.php') ? 'active' : '' ?>">
                <a href="import_export.php"><span class="nav-icon">📦</span><span>Импорт/Экспорт</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'ai_prompts.php') ? 'active' : '' ?>">
                <a href="ai_prompts.php"><span class="nav-icon">🤖</span><span>Промпты & AI</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'logs.php') ? 'active' : '' ?>">
                <a href="logs.php"><span class="nav-icon">🛡️</span><span>Журнал аудита</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'maintenance.php') ? 'active' : '' ?>">
                <a href="maintenance.php"><span class="nav-icon">🚧</span><span>Техобслуживание</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'database.php') ? 'active' : '' ?>" style="background: rgba(28,176,246,0.1); border-radius: 10px;">
                <a href="database.php" style="color: var(--secondary); font-weight: 800;"><span class="nav-icon">🗄️</span><span>База данных & CRUD</span></a>
            </li>
            <li class="nav-item <?= ($adminPage === 'settings.php') ? 'active' : '' ?>">
                <a href="settings.php"><span class="nav-icon">⚙️</span><span>Настройки сайта</span></a>
            </li>
        </ul>

        <div style="margin-top: auto; padding-top: 16px; display: flex; flex-direction: column; gap: 8px;">
            <a href="../teacher/index.php" target="_blank" class="btn-duo btn-secondary" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; text-align: center;">
                🧑‍🏫 Портал Учителя ↗
            </a>
            <a href="../index.php" target="_blank" class="btn-duo btn-outline" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; text-align: center;">
                🌐 Открыть сайт ↗
            </a>
    </aside>

    <!-- Admin Sidebar Mobile Backdrop -->
    <div class="sidebar-backdrop" id="admin-sidebar-backdrop" onclick="toggleAdminSidebar()"></div>

    <div class="admin-main">
        <!-- Admin Mobile Top Bar -->
        <div class="admin-mobile-topbar">
            <button type="button" class="mobile-menu-toggle" onclick="toggleAdminSidebar(event)" aria-label="Меню" title="Меню">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.4rem;">🐕</span>
                <span style="font-weight: 900; color: var(--secondary); font-size: 1.1rem;">Shiba Admin</span>
            </div>
            <span style="font-size: 0.75rem; font-weight: 800; padding: 4px 8px; border-radius: 6px; background: <?= $isMySql ? 'rgba(88,204,2,0.15)' : 'rgba(28,176,246,0.15)' ?>; color: <?= $isMySql ? '#166534' : '#0369a1' ?>;">
                <?= $isMySql ? 'MySQL' : 'SQLite' ?>
            </span>
        </div>
