<?php
/**
 * VladAero Universal Web Installer & Tech-Admin Console with Browser ZIP Uploader
 * 
 * Features:
 * 1. Browser ZIP Upload & Unpack: Upload vladaero.zip directly through the browser.
 * 2. Multi-step installation wizard (DB connection, schema init, seed data, super admin).
 * 3. Emergency 1-Click Instant Admin Password Reset with Auto-Login.
 * 4. Tech-Admin console (Backups, Updates via ZIP upload, Maintenance Mode, Diagnostics).
 */

define('VLADAERO_ROOT', __DIR__);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$lockFile = VLADAERO_ROOT . '/.installed_lock';
$configFile = VLADAERO_ROOT . '/config.php';
$isInstalled = file_exists($lockFile) && file_exists($configFile);

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 86400 * 30,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

// Handle Emergency Clean Reinstallation Trigger
if (isset($_POST['action']) && $_POST['action'] === 'emergency_reinstall') {
    if (file_exists($lockFile)) @unlink($lockFile);
    if (file_exists($configFile)) @unlink($configFile);
    if (file_exists(VLADAERO_ROOT . '/.maintenance_lock')) @unlink(VLADAERO_ROOT . '/.maintenance_lock');
    $_SESSION = [];
    $_SESSION['flash_msg'] = 'Блокировка снята. Вы можете выполнить установку заново и загрузить архив через браузер.';
    header('Location: install.php');
    exit;
}

// Handle Emergency Admin Password Reset & Auto-Login
if ($isInstalled && isset($_POST['action']) && $_POST['action'] === 'emergency_reset_password') {
    $newPassword = trim($_POST['new_password'] ?? '');
    if (strlen($newPassword) < 3) {
        $_SESSION['flash_error'] = 'Новый пароль должен содержать минимум 3 символа.';
    } else {
        $cfg = require $configFile;
        $host = $cfg['db']['host'] ?? 'localhost';
        $port = $cfg['db']['port'] ?? 3306;
        $dbname = $cfg['db']['dbname'] ?? 'vladaero';
        $user = $cfg['db']['user'] ?? 'root';
        $pass = $cfg['db']['pass'] ?? '';
        $prefix = $cfg['db']['prefix'] ?? 'va_';

        try {
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            // Find existing admin or first user
            $stmt = $pdo->prepare("SELECT id, username, email FROM `{$prefix}users` ORDER BY id ASC LIMIT 1");
            $stmt->execute();
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                $targetId = (int)$targetUser['id'];
                $uname = $targetUser['username'] ?: 'admin';
                $stmt = $pdo->prepare("UPDATE `{$prefix}users` SET `password_hash` = :hash, `role` = 'admin', `is_banned` = 0, `deleted_at` = NULL WHERE `id` = :id");
                $stmt->execute(['hash' => $newHash, 'id' => $targetId]);
            } else {
                // Insert new admin user
                $stmt = $pdo->prepare("INSERT INTO `{$prefix}users` (`email`, `username`, `password_hash`, `full_name`, `role`, `rank_title`, `xp_points`) VALUES ('admin@vladinc.ru', 'admin', :hash, 'Главный Администратор', 'admin', 'Шеф-пилот', 25000)");
                $stmt->execute(['hash' => $newHash]);
                $targetId = (int)$pdo->lastInsertId();
                $uname = 'admin';
            }

            // AUTO-AUTHENTICATE IN BOTH TECH CONSOLE AND PORTAL SESSION
            $_SESSION['tech_auth'] = true;
            $_SESSION['user_id'] = $targetId;
            $_SESSION['user_role'] = 'admin';
            $_SESSION['username'] = $uname;

            $_SESSION['flash_msg'] = "Пароль администратора '{$uname}' успешно обновлен на '{$newPassword}'! Вы автоматически авторизованы.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Ошибка подключения к БД: " . $e->getMessage();
        }
    }
    header('Location: install.php');
    exit;
}

// Handle Browser ZIP Upload & Auto-Extraction
if (isset($_POST['action']) && $_POST['action'] === 'upload_zip_package') {
    if (!empty($_FILES['zip_file']['tmp_name'])) {
        $tmpFile = $_FILES['zip_file']['tmp_name'];
        $origName = $_FILES['zip_file']['name'] ?? 'vladaero.zip';

        if (!class_exists('ZipArchive')) {
            $_SESSION['flash_error'] = 'Расширение PHP ZipArchive не установлено на сервере!';
        } else {
            $zip = new ZipArchive();
            $res = $zip->open($tmpFile);

            if ($res === true) {
                $extractedCount = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if (strpos($entryName, 'other/') === 0 || strpos($entryName, '../') !== false) {
                        continue;
                    }
                    $zip->extractTo(VLADAERO_ROOT, $entryName);
                    $extractedCount++;
                }
                $zip->close();
                $_SESSION['flash_msg'] = "Архив {$origName} успешно загружен и распакован через браузер! (Извлечено файлов: {$extractedCount})";
            } else {
                $_SESSION['flash_error'] = "Не удалось открыть ZIP архив (Код ошибки: {$res})";
            }
        }
    } else {
        $_SESSION['flash_error'] = 'Файл архива не был передан через браузер.';
    }
    header('Location: install.php');
    exit;
}

// Handle Maintenance Toggle from Console
if ($isInstalled && isset($_POST['action']) && $_POST['action'] === 'toggle_maintenance') {
    if (!empty($_SESSION['tech_auth'])) {
        $mLock = VLADAERO_ROOT . '/.maintenance_lock';
        if (file_exists($mLock)) {
            unlink($mLock);
            $msg = 'Режим обслуживания отключен. Сайт доступен всем пользователям.';
        } else {
            file_put_contents($mLock, date('c'));
            $msg = 'Режим обслуживания включен. Посетители видят заглушку 503.';
        }
        $_SESSION['flash_msg'] = $msg;
        header('Location: install.php');
        exit;
    }
}

// Handle Backup Creation from Console
if ($isInstalled && isset($_POST['action']) && $_POST['action'] === 'create_db_backup') {
    if (!empty($_SESSION['tech_auth'])) {
        $cfg = require $configFile;
        $host = $cfg['db']['host'] ?? 'localhost';
        $port = $cfg['db']['port'] ?? 3306;
        $dbname = $cfg['db']['dbname'] ?? 'vladaero';
        $user = $cfg['db']['user'] ?? 'root';
        $pass = $cfg['db']['pass'] ?? '';
        $prefix = $cfg['db']['prefix'] ?? 'va_';

        $backupDir = VLADAERO_ROOT . '/uploads/backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

        try {
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            $tables = [];
            $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}%'");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            $dump = "-- VladAero Database Backup\n-- Date: " . date('c') . "\n\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

            foreach ($tables as $tbl) {
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_ASSOC);
                $dump .= "DROP TABLE IF EXISTS `{$tbl}`;\n" . $createStmt['Create Table'] . ";\n\n";

                $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $keys = array_keys($r);
                        $fields = implode('`, `', $keys);
                        $values = array_map(function($v) use ($pdo) {
                            if ($v === null) return 'NULL';
                            return $pdo->quote($v);
                        }, array_values($r));
                        $dump .= "INSERT INTO `{$tbl}` (`{$fields}`) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $dump .= "\n";
                }
            }
            $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

            $filename = "db_backup_" . date('Y-m-d_H-i-s') . ".sql";
            file_put_contents("{$backupDir}/{$filename}", $dump);
            $_SESSION['flash_msg'] = "Резервная копия базы данных успешно создана: {$filename}";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Ошибка создания дампа: " . $e->getMessage();
        }
        header('Location: install.php');
        exit;
    }
}

// Handle Full Zip Backup
if ($isInstalled && isset($_POST['action']) && $_POST['action'] === 'create_zip_backup') {
    if (!empty($_SESSION['tech_auth']) && class_exists('ZipArchive')) {
        $backupDir = VLADAERO_ROOT . '/uploads/backups';
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

        $zipName = "full_backup_" . date('Y-m-d_H-i-s') . ".zip";
        $zipPath = "{$backupDir}/{$zipName}";

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(VLADAERO_ROOT, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relPath = substr($filePath, strlen(VLADAERO_ROOT) + 1);
                    if ((strpos($relPath, 'uploads/backups') === 0 && strpos($relPath, '.zip') !== false) || strpos($relPath, 'other/') === 0) {
                        continue;
                    }
                    $zip->addFile($filePath, $relPath);
                }
            }
            $zip->close();
            $_SESSION['flash_msg'] = "Полный архив сайта успешно создан: {$zipName}";
        } else {
            $_SESSION['flash_error'] = "Не удалось инициализировать ZipArchive";
        }
        header('Location: install.php');
        exit;
    }
}

// Handle Tech-Console Login
if ($isInstalled && isset($_POST['action']) && $_POST['action'] === 'tech_login') {
    $keyOrPass = trim($_POST['tech_key'] ?? '');
    $cfg = require $configFile;
    $masterKey = $cfg['security']['recovery_key'] ?? '';
    
    $authenticated = false;
    if (!empty($masterKey) && hash_equals($masterKey, $keyOrPass)) {
        $authenticated = true;
    } else {
        try {
            $pdo = new PDO("mysql:host={$cfg['db']['host']};port={$cfg['db']['port']};dbname={$cfg['db']['dbname']};charset=utf8mb4", $cfg['db']['user'], $cfg['db']['pass']);
            $stmt = $pdo->prepare("SELECT * FROM `{$cfg['db']['prefix']}users` WHERE role = 'admin' OR id = 1");
            $stmt->execute();
            $adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($adminUsers as $adminUser) {
                $hash = (string)$adminUser['password_hash'];
                if (password_verify($keyOrPass, $hash) || 
                    password_verify(trim($keyOrPass), $hash) ||
                    hash_equals($hash, hash('sha256', $keyOrPass)) || 
                    hash_equals($hash, md5($keyOrPass)) || 
                    hash_equals($hash, $keyOrPass)) {
                    $authenticated = true;
                    $_SESSION['user_id'] = (int)$adminUser['id'];
                    $_SESSION['user_role'] = 'admin';
                    $_SESSION['username'] = $adminUser['username'];
                    break;
                }
            }
        } catch (Exception $e) {}
    }

    if ($authenticated) {
        $_SESSION['tech_auth'] = true;
    } else {
        $_SESSION['flash_error'] = 'Неверный пароль или Мастер-ключ. Вы можете мгновенно сбросить пароль ниже.';
    }
    header('Location: install.php');
    exit;
}

// Handle Installation Wizard POST
$installErrors = [];

if (!$isInstalled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_install'])) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? 'vladaero');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = trim($_POST['db_pass'] ?? '');
    $dbPrefix = trim($_POST['db_prefix'] ?? 'va_');
    $basePath = trim($_POST['base_path'] ?? '/aviation');

    $adminEmail = strtolower(trim($_POST['admin_email'] ?? 'admin@vladinc.ru'));
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass = trim($_POST['admin_pass'] ?? 'admin123');
    $importDemo = !empty($_POST['import_demo']);

    if (empty($dbName) || empty($dbUser)) {
        $installErrors[] = 'Заполните обязательные поля имени базы данных и пользователя.';
    }

    if (empty($installErrors)) {
        try {
            $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Create DB if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // Execute schema.sql if exists
            if (file_exists(VLADAERO_ROOT . '/schema.sql')) {
                $schemaSql = file_get_contents(VLADAERO_ROOT . '/schema.sql');
                if ($dbPrefix !== 'va_') {
                    $schemaSql = str_replace('`va_', "`{$dbPrefix}", $schemaSql);
                }
                $pdo->exec($schemaSql);
            }

            // Import Demo Data if selected
            if ($importDemo && file_exists(VLADAERO_ROOT . '/seed_data.sql')) {
                $seedSql = file_get_contents(VLADAERO_ROOT . '/seed_data.sql');
                if ($dbPrefix !== 'va_') {
                    $seedSql = str_replace('`va_', "`{$dbPrefix}", $seedSql);
                }
                $pdo->exec($seedSql);
            }

            // Create Super Admin User
            $recoveryKey = bin2hex(random_bytes(16)); // 32 chars
            $adminPassHash = password_hash($adminPass, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO `{$dbPrefix}users` (`email`, `username`, `password_hash`, `full_name`, `role`, `rank_title`, `xp_points`) VALUES (:email, :username, :hash, 'Главный Администратор', 'admin', 'Шеф-пилот', 25000) ON DUPLICATE KEY UPDATE `password_hash`=:hash, `role`='admin', `is_banned`=0, `deleted_at`=NULL");
            $stmt->execute([
                'email' => $adminEmail,
                'username' => $adminUser,
                'hash' => $adminPassHash
            ]);

            // Save Base Path in settings
            $stmt = $pdo->prepare("INSERT INTO `{$dbPrefix}settings` (`setting_key`, `setting_value`, `setting_group`) VALUES ('base_url', :base, 'general') ON DUPLICATE KEY UPDATE `setting_value`=:base");
            $stmt->execute(['base' => $basePath]);

            // Generate config.php
            $configContent = "<?php\nreturn [\n    'db' => [\n        'driver' => 'mysql',\n        'host' => '{$dbHost}',\n        'port' => {$dbPort},\n        'dbname' => '{$dbName}',\n        'user' => '{$dbUser}',\n        'pass' => '{$dbPass}',\n        'prefix' => '{$dbPrefix}'\n    ],\n    'app' => [\n        'name' => 'VladAero',\n        'base_url' => '{$basePath}',\n        'installed_at' => '" . date('c') . "'\n    ],\n    'security' => [\n        'recovery_key' => '{$recoveryKey}'\n    ]\n];\n";
            file_put_contents($configFile, $configContent);
            file_put_contents($lockFile, json_encode([
                'installed_at' => date('c'),
                'version' => '1.0.0',
                'lock_hash' => hash('sha256', $recoveryKey)
            ], JSON_PRETTY_PRINT));

            $_SESSION['just_installed'] = [
                'email' => $adminEmail,
                'user' => $adminUser,
                'pass' => $adminPass,
                'recovery_key' => $recoveryKey
            ];

            // Auto-login admin
            $_SESSION['tech_auth'] = true;
            $_SESSION['user_id'] = 1;
            $_SESSION['user_role'] = 'admin';
            $_SESSION['username'] = $adminUser;

            header('Location: install.php?step=success');
            exit;
        } catch (Exception $e) {
            $installErrors[] = "Ошибка базы данных: " . $e->getMessage();
        }
    }
}

// System Requirements Check
$reqs = [
    'PHP Version >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO Extension' => extension_loaded('pdo'),
    'PDO MySQL Extension' => extension_loaded('pdo_mysql'),
    'cURL Extension' => extension_loaded('curl'),
    'GD / Image Processing' => extension_loaded('gd') || extension_loaded('imagick'),
    'MBString Extension' => extension_loaded('mbstring'),
    'Exif Extension (Споттинг)' => extension_loaded('exif'),
    'OpenSSL Extension' => extension_loaded('openssl'),
    'JSON Extension' => extension_loaded('json'),
    'ZipArchive (Загрузка & Бэкап ZIP)' => class_exists('ZipArchive'),
    'Права на запись /uploads' => is_writable(VLADAERO_ROOT . '/uploads') || @mkdir(VLADAERO_ROOT . '/uploads', 0755, true),
    'Права на запись в корень' => is_writable(VLADAERO_ROOT)
];
?>
<!DOCTYPE html>
<html lang="ru" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VladAero — <?= $isInstalled ? 'Техническая Панель Управления' : 'Установка и Загрузчик ZIP' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .va-card {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(56, 189, 248, 0.15);
            border-radius: 1rem;
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col items-center justify-center p-4 sm:p-8 font-sans">

<div class="max-w-4xl w-full">

    <!-- Header Brand -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-tr from-sky-600 to-cyan-400 text-white shadow-xl shadow-sky-500/20 mb-4">
            <i data-lucide="plane" class="w-8 h-8 transform -rotate-45"></i>
        </div>
        <h1 class="text-3xl font-extrabold text-white tracking-tight">Vlad<span class="text-sky-400">Aero</span></h1>
        <p class="text-sm text-slate-400 mt-1 font-mono">
            <?= $isInstalled ? '🛡️ Техническая Консоль & Управление Сервером' : '✈️ Установщик & Загрузчик Архива Дистрибутива (ZIP)' ?>
        </p>
    </div>

    <!-- Flash Messages -->
    <?php if (!empty($_SESSION['flash_msg'])): ?>
        <div class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm flex items-center justify-between">
            <span><?= htmlspecialchars($_SESSION['flash_msg']) ?></span>
            <button onclick="this.parentElement.remove()" class="text-slate-400">&times;</button>
        </div>
        <?php unset($_SESSION['flash_msg']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm flex items-center justify-between">
            <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
            <button onclick="this.parentElement.remove()" class="text-slate-400">&times;</button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($installErrors)): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm space-y-1">
            <?php foreach ($installErrors as $err): ?>
                <div>• <?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- MODE 1: SUCCESS SCREEN -->
    <?php if (isset($_GET['step']) && $_GET['step'] === 'success' && !empty($_SESSION['just_installed'])): 
        $info = $_SESSION['just_installed'];
        $cfg = file_exists($configFile) ? require $configFile : [];
        $base = $cfg['app']['base_url'] ?? '/aviation';
    ?>
        <div class="va-card p-8 space-y-6">
            <div class="flex items-center space-x-3 text-emerald-400">
                <i data-lucide="check-circle-2" class="w-8 h-8"></i>
                <h2 class="text-2xl font-bold text-white">Портал VladAero успешно установлен!</h2>
            </div>
            <p class="text-sm text-slate-300">
                База данных создана, структура таблиц инициализирована. Ваши учетные данные:
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs font-mono bg-slate-950 p-4 rounded-xl border border-slate-800">
                <div>Email: <strong class="text-sky-400"><?= htmlspecialchars($info['email']) ?></strong></div>
                <div>Логин: <strong class="text-sky-400"><?= htmlspecialchars($info['user']) ?></strong></div>
                <div>Пароль: <strong class="text-emerald-400 font-bold"><?= htmlspecialchars($info['pass'] ?? '******') ?></strong></div>
                <div>Путь сайта (Base Path): <strong class="text-sky-400"><?= htmlspecialchars($base) ?></strong></div>
            </div>

            <div class="p-4 bg-slate-950 rounded-xl border border-sky-500/30 font-mono text-sm">
                <div class="text-xs text-slate-400 uppercase tracking-wider mb-1">Секретный Master Recovery Key:</div>
                <div class="text-lg font-bold text-amber-400 select-all"><?= htmlspecialchars($info['recovery_key']) ?></div>
                <div class="text-xs text-slate-500 mt-2">Используйте этот ключ для аварийного доступа к консоли `install.php` в любое время.</div>
            </div>

            <div class="flex items-center space-x-4 pt-4">
                <a href="<?= htmlspecialchars(rtrim($base, '/') . '/') ?>" class="flex-1 bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 px-6 rounded-xl text-center shadow-lg transition">
                    Перейти на Главную страницу VladAero
                </a>
                <a href="<?= htmlspecialchars(rtrim($base, '/') . '/login.php') ?>" class="flex-1 bg-slate-800 hover:bg-slate-700 text-white font-bold py-3 px-6 rounded-xl text-center border border-slate-700 transition">
                    Войти в аккаунт (/login)
                </a>
            </div>
        </div>

    <!-- MODE 2: TECH-ADMIN CONSOLE -->
    <?php elseif ($isInstalled): ?>

        <?php if (empty($_SESSION['tech_auth'])): ?>
            <!-- Login & Emergency Reset Box -->
            <div class="max-w-md mx-auto space-y-6">
                <!-- Emergency 1-Click Instant Password Reset Box (TOP PRIORITY) -->
                <div class="va-card p-6 border-2 border-amber-500/50 shadow-2xl space-y-3 font-mono text-xs">
                    <div class="flex items-center space-x-2 text-amber-400 font-bold text-sm">
                        <i data-lucide="key" class="w-5 h-5"></i>
                        <span>Мгновенный сброс пароля Администратора</span>
                    </div>
                    <p class="text-xs text-slate-300 font-sans leading-relaxed">
                        Укажите любой новый пароль ниже и нажмите кнопку. Пароль администратора обновится в базе данных, и вы <strong>сразу будете авторизованы</strong>.
                    </p>
                    <form method="POST" class="space-y-3 pt-2">
                        <input type="hidden" name="action" value="emergency_reset_password">
                        <div>
                            <input type="text" name="new_password" required value="admin123" placeholder="Новый пароль (например: admin123)" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-sm text-emerald-400 font-bold font-mono focus:border-amber-500">
                        </div>
                        <button type="submit" class="w-full bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 text-slate-950 font-bold py-3 px-4 rounded-xl text-xs uppercase tracking-wide transition shadow-lg">
                            🔑 Установить пароль и Войти
                        </button>
                    </form>
                </div>

                <!-- Standard Login Box -->
                <div class="va-card p-6 shadow-xl space-y-4">
                    <div class="text-center">
                        <h2 class="text-base font-bold text-white">Вход по Мастер-ключу или Паролю</h2>
                    </div>

                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="tech_login">
                        <div>
                            <input type="password" name="tech_key" required placeholder="Пароль или Мастер-ключ..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-sky-500 font-mono">
                        </div>
                        <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-2.5 rounded-xl text-xs transition">
                            Войти в Консоль
                        </button>
                    </form>
                </div>

                <!-- Emergency Reinstall Button -->
                <div class="text-center pt-2">
                    <form method="POST" onsubmit="return confirm('Снять блокировку и открыть чистую установку заново?');">
                        <input type="hidden" name="action" value="emergency_reinstall">
                        <button type="submit" class="text-xs font-mono text-slate-400 hover:text-slate-200 underline">
                            🔄 Запустить мастер чистой переустановки заново
                        </button>
                    </form>
                </div>
            </div>
        <?php else: 
            $mLock = VLADAERO_ROOT . '/.maintenance_lock';
            $isMaintenance = file_exists($mLock);
            $backupDir = VLADAERO_ROOT . '/uploads/backups';
            $backups = [];
            if (is_dir($backupDir)) {
                $files = scandir($backupDir);
                foreach ($files as $f) {
                    if ($f !== '.' && $f !== '..' && $f !== '.htaccess') {
                        $backups[] = [
                            'name' => $f,
                            'size' => round(filesize("{$backupDir}/{$f}") / 1024, 1) . ' KB',
                            'date' => date('Y-m-d H:i:s', filemtime("{$backupDir}/{$f}"))
                        ];
                    }
                }
            }
            $cfg = require $configFile;
            $base = $cfg['app']['base_url'] ?? '/aviation';
        ?>
            <!-- Authenticated Dashboard -->
            <div class="space-y-6">
                <!-- Status Bar -->
                <div class="va-card p-6 flex flex-col md:flex-row items-center justify-between gap-4">
                    <div>
                        <div class="text-xs text-slate-400 font-mono uppercase">Текущий статус сайта</div>
                        <div class="text-xl font-bold flex items-center space-x-2 mt-1">
                            <?php if ($isMaintenance): ?>
                                <span class="w-3 h-3 rounded-full bg-amber-500 animate-ping"></span>
                                <span class="text-amber-400">РЕЖИМ ОБСЛУЖИВАНИЯ (503)</span>
                            <?php else: ?>
                                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                                <span class="text-emerald-400">ОНЛАЙН В ПОЛЕТЕ</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex items-center space-x-3">
                        <form method="POST">
                            <input type="hidden" name="action" value="toggle_maintenance">
                            <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold font-mono transition <?= $isMaintenance ? 'bg-emerald-600 hover:bg-emerald-500 text-white' : 'bg-amber-600 hover:bg-amber-500 text-white' ?>">
                                <?= $isMaintenance ? '✈️ Выключить заглушку (Сайт онлайн)' : '⚠️ Включить режим обслуживания' ?>
                            </button>
                        </form>
                        <a href="<?= htmlspecialchars(rtrim($base, '/') . '/') ?>" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold border border-slate-700 transition">
                            На сайт
                        </a>
                        <a href="<?= htmlspecialchars(rtrim($base, '/') . '/admin/') ?>" class="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold transition">
                            В Панель /admin
                        </a>
                    </div>
                </div>

                <!-- BROWSER ZIP UPLOAD & UPDATE ENGINE IN CONSOLE -->
                <div class="va-card p-6 border border-sky-500/30 space-y-4">
                    <div class="flex items-center space-x-2 text-sky-400 font-bold text-sm">
                        <i data-lucide="upload-cloud" class="w-5 h-5"></i>
                        <span>Загрузка обновления / Дистрибутива через браузер (ZIP)</span>
                    </div>
                    <p class="text-xs text-slate-400">Загрузите новый архив <code>vladaero.zip</code> для мгновенного обновления файлов сайта на хостинге через веб-интерфейс.</p>

                    <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-center gap-3">
                        <input type="hidden" name="action" value="upload_zip_package">
                        <input type="file" name="zip_file" accept=".zip" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-300 font-mono">
                        <button type="submit" class="w-full sm:w-auto bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold py-2.5 px-6 rounded-xl text-xs shadow-lg transition whitespace-nowrap">
                            Распаковать ZIP
                        </button>
                    </form>
                </div>

                <!-- Backups & Diagnostics Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Backup -->
                    <div class="va-card p-6 space-y-4">
                        <div class="flex items-center space-x-2 text-sky-400 font-bold text-sm">
                            <i data-lucide="database" class="w-5 h-5"></i>
                            <span>Резервное копирование и дампы</span>
                        </div>
                        <p class="text-xs text-slate-400">Создание дампов базы данных MySQL и полных ZIP-архивов сайта в 1 клик.</p>
                        
                        <div class="flex items-center space-x-3 pt-2">
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="action" value="create_db_backup">
                                <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white py-2.5 px-3 rounded-xl text-xs font-bold transition flex items-center justify-center space-x-1.5">
                                    <i data-lucide="download" class="w-4 h-4"></i>
                                    <span>Дамп БД (.sql)</span>
                                </button>
                            </form>
                            <form method="POST" class="flex-1">
                                <input type="hidden" name="action" value="create_zip_backup">
                                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-700 text-white py-2.5 px-3 rounded-xl text-xs font-bold border border-slate-700 transition flex items-center justify-center space-x-1.5">
                                    <i data-lucide="archive" class="w-4 h-4"></i>
                                    <span>Полный ZIP</span>
                                </button>
                            </form>
                        </div>

                        <!-- Backups List -->
                        <div class="mt-4 pt-4 border-t border-slate-800">
                            <div class="text-xs font-semibold text-slate-300 mb-2">Существующие бэкапы:</div>
                            <?php if (empty($backups)): ?>
                                <div class="text-xs text-slate-500">Бэкапов пока нет.</div>
                            <?php else: ?>
                                <div class="space-y-1.5 max-h-40 overflow-y-auto font-mono text-xs">
                                    <?php foreach ($backups as $b): ?>
                                        <div class="p-2 rounded bg-slate-950 border border-slate-800/80 flex items-center justify-between">
                                            <span class="text-sky-400 truncate max-w-[180px]"><?= htmlspecialchars($b['name']) ?></span>
                                            <span class="text-slate-400"><?= $b['size'] ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Diagnostics -->
                    <div class="va-card p-6 space-y-3">
                        <div class="flex items-center space-x-2 text-emerald-400 font-bold text-sm">
                            <i data-lucide="activity" class="w-5 h-5"></i>
                            <span>Диагностика сервера и хостинга</span>
                        </div>
                        <div class="space-y-2 text-xs font-mono">
                            <div class="flex justify-between p-2 rounded bg-slate-950 border border-slate-800">
                                <span class="text-slate-400">PHP Версия:</span>
                                <span class="text-slate-200"><?= PHP_VERSION ?></span>
                            </div>
                            <div class="flex justify-between p-2 rounded bg-slate-950 border border-slate-800">
                                <span class="text-slate-400">Memory Limit:</span>
                                <span class="text-slate-200"><?= ini_get('memory_limit') ?></span>
                            </div>
                            <div class="flex justify-between p-2 rounded bg-slate-950 border border-slate-800">
                                <span class="text-slate-400">Max Upload Size:</span>
                                <span class="text-slate-200"><?= ini_get('upload_max_filesize') ?></span>
                            </div>
                            <div class="flex justify-between p-2 rounded bg-slate-950 border border-slate-800">
                                <span class="text-slate-400">Префикс таблиц БД:</span>
                                <span class="text-sky-400"><?= htmlspecialchars($cfg['db']['prefix'] ?? 'va_') ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Danger Zone -->
                <div class="bg-red-950/20 border border-red-500/30 rounded-2xl p-6 space-y-4">
                    <div class="flex items-center space-x-2 text-red-400 font-bold text-sm">
                        <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                        <span>Опасная зона: Сброс или Полная Деинсталляция</span>
                    </div>
                    <form method="POST" onsubmit="return confirm('ВНИМАНИЕ! Это действие удалит настройки и таблицы. Продолжить?');" class="flex flex-col sm:flex-row items-center gap-3">
                        <input type="hidden" name="action" value="emergency_reinstall">
                        <button type="submit" class="w-full sm:w-auto bg-red-600 hover:bg-red-500 text-white font-bold py-2.5 px-6 rounded-xl text-xs transition">
                            Сбросить установку и открыть мастер заново
                        </button>
                    </form>
                </div>
            </div>

        <?php endif; ?>

    <!-- MODE 3: NEW INSTALLATION WIZARD WITH ZIP UPLOADER -->
    <?php else: ?>
        <div class="va-card p-6 sm:p-8 space-y-6">

            <!-- BROWSER ZIP UPLOADER CARD -->
            <div class="p-6 rounded-2xl bg-sky-950/30 border border-sky-500/30 space-y-3">
                <div class="flex items-center space-x-2.5 text-sky-400 font-bold text-sm">
                    <i data-lucide="upload-cloud" class="w-5 h-5"></i>
                    <span>Загрузка архива дистрибутива VladAero (ZIP) через браузер</span>
                </div>
                <p class="text-xs text-slate-300 leading-relaxed">
                    Если вы загрузили только один файл <code>install.php</code> на хостинг, выберите архив <code>vladaero.zip</code> ниже — установщик распакует все файлы портала прямо на сервере.
                </p>

                <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-center gap-3 pt-2">
                    <input type="hidden" name="action" value="upload_zip_package">
                    <input type="file" name="zip_file" accept=".zip" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-300 font-mono">
                    <button type="submit" class="w-full sm:w-auto bg-gradient-to-r from-sky-600 to-cyan-500 hover:from-sky-500 hover:to-cyan-400 text-white font-mono font-bold py-2.5 px-6 rounded-xl text-xs shadow-lg transition whitespace-nowrap">
                        Загрузить & Распаковать
                    </button>
                </form>
            </div>

            <!-- Requirements Check -->
            <div>
                <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider font-mono mb-3 flex items-center space-x-2">
                    <i data-lucide="check-square" class="w-4 h-4 text-sky-400"></i>
                    <span>1. Проверка системных требований хостинга</span>
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs font-mono">
                    <?php foreach ($reqs as $name => $ok): ?>
                        <div class="p-2.5 rounded-xl bg-slate-950 border border-slate-800/80 flex items-center justify-between">
                            <span class="text-slate-300"><?= $name ?></span>
                            <?php if ($ok): ?>
                                <span class="text-emerald-400 font-bold flex items-center space-x-1">
                                    <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                    <span>OK</span>
                                </span>
                            <?php else: ?>
                                <span class="text-red-400 font-bold flex items-center space-x-1">
                                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                    <span>НЕТ</span>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Install Form -->
            <form method="POST" class="space-y-6 pt-4 border-t border-slate-800">
                <input type="hidden" name="do_install" value="1">

                <!-- DB Config -->
                <div>
                    <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider font-mono mb-3 flex items-center space-x-2">
                        <i data-lucide="database" class="w-4 h-4 text-sky-400"></i>
                        <span>2. Подключение к базе данных MySQL</span>
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                        <div>
                            <label class="block text-slate-400 mb-1">Сервер БД (Host)</label>
                            <input type="text" name="db_host" value="localhost" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-mono">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Порт</label>
                            <input type="number" name="db_port" value="3306" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-mono">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Имя базы данных</label>
                            <input type="text" name="db_name" value="vladaero" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-mono">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Пользователь БД</label>
                            <input type="text" name="db_user" value="root" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-mono">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Пароль БД</label>
                            <input type="password" name="db_pass" placeholder="Пароль пользователя БД" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-mono">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Префикс таблиц</label>
                            <input type="text" name="db_prefix" value="va_" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-sky-400 font-mono font-bold">
                        </div>
                    </div>
                </div>

                <!-- Admin & Path -->
                <div>
                    <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider font-mono mb-3 flex items-center space-x-2">
                        <i data-lucide="user-check" class="w-4 h-4 text-sky-400"></i>
                        <span>3. Учетная запись Администратора и Путь</span>
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                        <div>
                            <label class="block text-slate-400 mb-1">Email Администратора</label>
                            <input type="email" name="admin_email" value="admin@vladinc.ru" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-mono">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Логин Администратора</label>
                            <input type="text" name="admin_user" value="admin" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-mono">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Пароль Администратора</label>
                            <input type="password" name="admin_pass" required value="admin123" placeholder="Надежный пароль" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-mono font-bold text-emerald-400">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="block text-slate-400 mb-1 text-xs">Базовый URL путь (например: /aviation для vladinc.ru/aviation)</label>
                        <input type="text" name="base_path" value="/aviation" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-sky-400 font-mono text-xs font-bold">
                    </div>
                </div>

                <!-- Demo Data Option -->
                <div class="p-4 bg-slate-950/80 rounded-xl border border-slate-800 flex items-center justify-between">
                    <div>
                        <div class="font-bold text-xs text-slate-200">Импортировать мастер-пакет авиационных данных?</div>
                        <div class="text-[11px] text-slate-400">16 самолетов, ВПП с ILS, аэропорты (SVO, DME, LED, LHR, JFK), частоты, викторины и словарь терминов.</div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="import_demo" value="1" checked class="sr-only peer">
                        <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-sky-600"></div>
                    </label>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-sky-600 to-cyan-500 hover:from-sky-500 hover:to-cyan-400 text-white font-bold py-4 rounded-xl shadow-xl shadow-sky-500/20 text-sm tracking-wide uppercase font-mono transition">
                    🚀 Завершить установку VladAero
                </button>
            </form>
        </div>
    <?php endif; ?>

</div>

<script>
    lucide.createIcons();
</script>
</body>
</html>
