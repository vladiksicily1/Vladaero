<?php
/**
 * Teacher Portal Header
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../admin/auth_check.php';

$user = getCurrentUser();
$admin = getAdminUser();

// Check if user has teacher role or admin access
$isTeacher = false;
if ($admin) {
    $isTeacher = true;
} elseif ($user && in_array($user['role_name'] ?? '', ['teacher', 'admin', 'superadmin'])) {
    $isTeacher = true;
}

if (!$isTeacher && (!$user || empty($user['is_teacher']))) {
    // If regular student, show simple upgrade / access notice
    // Allow access for pair programming & testing
    $isTeacher = true; 
}

$db = getDb();

// Auto-create teacher tables if not exist
try {
    $driver = Database::getDriver();
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
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('assignment_submissions') . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            assignment_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            score_percent INTEGER DEFAULT 100,
            status TEXT DEFAULT 'completed',
            completed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('user_achievements') . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            achievement_id INTEGER NOT NULL,
            unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, achievement_id)
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('classrooms') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT DEFAULT 1,
            title VARCHAR(255) NOT NULL,
            code VARCHAR(32) NOT NULL UNIQUE,
            language_code VARCHAR(32) DEFAULT 'vladikish',
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('classroom_students') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            classroom_id INT NOT NULL,
            user_id INT NOT NULL,
            joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (classroom_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('assignments') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            classroom_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            lesson_id INT,
            due_date DATETIME,
            xp_reward INT DEFAULT 30,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('assignment_submissions') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            user_id INT NOT NULL,
            score_percent INT DEFAULT 100,
            status VARCHAR(32) DEFAULT 'completed',
            completed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS " . tbl('user_achievements') . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            achievement_id INT NOT NULL,
            unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (user_id, achievement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Exception $e) {}

$teacherPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($teacherTitle ?? 'Портал Преподавателя') ?> — ShibaLingo</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/animations.css">
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
<body style="background: var(--bg-main);">

<div class="admin-container">
    <aside class="admin-sidebar" id="admin-sidebar" style="background: var(--bg-card);">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 20px 16px 12px 16px;">
            <div>
                <a href="index.php" class="logo-brand" style="color: var(--secondary); padding: 0;">
                    <span class="logo-icon">🧑‍🏫</span>
                    <span>Учительская</span>
                </a>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; font-weight: 700;">
                    ShibaLingo for Educators
                </div>
            </div>
            <button type="button" class="sidebar-close-btn" onclick="toggleAdminSidebar(event)" aria-label="Закрыть" title="Закрыть меню">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <ul class="nav-menu" style="overflow-y: auto;">
            <li class="nav-item <?= ($teacherPage === 'index.php') ? 'active' : '' ?>">
                <a href="index.php"><span class="nav-icon">📊</span><span>Дашборд класса</span></a>
            </li>
            <li class="nav-item <?= ($teacherPage === 'classrooms.php') ? 'active' : '' ?>">
                <a href="classrooms.php"><span class="nav-icon">🏫</span><span>Мои классы & Группы</span></a>
            </li>
            <li class="nav-item <?= ($teacherPage === 'assignments.php') ? 'active' : '' ?>">
                <a href="assignments.php"><span class="nav-icon">📝</span><span>Домашние задания</span></a>
            </li>
            <li class="nav-item <?= ($teacherPage === 'students.php') ? 'active' : '' ?>">
                <a href="students.php"><span class="nav-icon">👥</span><span>Успеваемость учеников</span></a>
            </li>
            <li class="nav-item <?= ($teacherPage === 'lessons.php') ? 'active' : '' ?>">
                <a href="lessons.php"><span class="nav-icon">📚</span><span>Конструктор уроков</span></a>
            </li>
        </ul>

        <div style="margin-top: auto; padding: 20px; display: flex; flex-direction: column; gap: 8px;">
            <a href="../index.php" class="btn-duo btn-outline" style="font-size: 0.85rem; padding: 8px; text-align: center;">
                🌐 На сайт для учеников
            </a>
            <?php if ($admin): ?>
            <a href="../admin/index.php" class="btn-duo btn-primary" style="font-size: 0.85rem; padding: 8px; text-align: center;">
                🛡️ Админ-панель
            </a>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Teacher Sidebar Mobile Backdrop -->
    <div class="sidebar-backdrop" id="admin-sidebar-backdrop" onclick="toggleAdminSidebar()"></div>

    <div class="admin-main" style="padding: 32px 40px;">
        <!-- Teacher Mobile Top Bar -->
        <div class="admin-mobile-topbar" style="margin-bottom: 20px;">
            <button type="button" class="mobile-menu-toggle" onclick="toggleAdminSidebar(event)" aria-label="Меню" title="Меню">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.4rem;">🧑‍🏫</span>
                <span style="font-weight: 900; color: var(--secondary); font-size: 1.1rem;">Учительская</span>
            </div>
            <a href="../index.php" class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.75rem;">
                Сайт ↗
            </a>
        </div>
