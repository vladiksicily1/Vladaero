<?php
declare(strict_types=1);

/**
 * VladAero - Installation & System Lifecycle Manager
 * Features:
 * 1. Step-by-step installation wizard (MySQL config with custom prefix, migration animation, admin setup)
 * 2. Automatic detection of nearby VladAero ZIP archives (e.g. vladaero.zip) with 1-click extraction
 * 3. Upload and extract ZIP update packages via browser
 * 4. Post-install Admin Management Mode (Wipe ONLY VladAero tables, Update from ZIP, Backups, Maintenance Mode)
 */

namespace VladAero;

use PDO;
use PDOException;
use ZipArchive;

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$configFile = __DIR__ . '/config.php';
$isInstalled = file_exists($configFile);

$step = $_GET['step'] ?? ($isInstalled ? 'manager' : '1');
$error = '';
$success = '';

// Load existing config if available
$currentConfig = $isInstalled ? (require $configFile) : [];

/**
 * Scan for valid VladAero ZIP archives in current and parent directory
 */
function find_local_vladaero_zip(): ?array {
    if (!class_exists('ZipArchive')) return null;

    $searchDirs = [__DIR__, dirname(__DIR__)];
    foreach ($searchDirs as $dir) {
        if (!is_dir($dir)) continue;
        $files = glob($dir . '/*.zip');
        if (!$files) continue;

        foreach ($files as $zipPath) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                // Verify if it contains VladAero core files
                $hasCore = ($zip->locateName('schema.sql') !== false) || 
                           ($zip->locateName('index.php') !== false) || 
                           ($zip->locateName('includes/db.php') !== false);
                $numFiles = $zip->numFiles;
                $zip->close();

                if ($hasCore) {
                    return [
                        'path'      => $zipPath,
                        'filename'  => basename($zipPath),
                        'size_mb'   => round(filesize($zipPath) / (1024 * 1024), 2),
                        'modified'  => date('d.m.Y H:i', filemtime($zipPath)),
                        'num_files' => $numFiles
                    ];
                }
            }
        }
    }
    return null;
}

$detectedZip = find_local_vladaero_zip();

// Handle 1-Click extraction of detected local ZIP archive
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['use_local_zip'])) {
    if ($detectedZip && file_exists($detectedZip['path']) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($detectedZip['path']) === true) {
            $zip->extractTo(__DIR__);
            $zip->close();
            $success = "Архив {$detectedZip['filename']} ({$detectedZip['size_mb']} МБ) успешно распакован и применен!";
            // Re-check installation state
            $isInstalled = file_exists($configFile);
        } else {
            $error = 'Не удалось открыть обнаруженный ZIP-архив.';
        }
    } else {
        $error = 'Локальный ZIP-архив не найден или модуль ZipArchive недоступен.';
    }
}

// -------------------------------------------------------------
// POST-INSTALLATION MANAGER ACTIONS (Protected by Admin Auth)
// -------------------------------------------------------------
if ($isInstalled && $step === 'manager') {
    $managerAuth = $_SESSION['va_install_auth'] ?? false;

    // Login to manager
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manager_login'])) {
        $enteredPass = $_POST['admin_password'] ?? '';
        
        try {
            $dsn = "mysql:host={$currentConfig['db_host']};port=" . ($currentConfig['db_port'] ?? '3306') . ";dbname={$currentConfig['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $currentConfig['db_user'], $currentConfig['db_pass'] ?? '');
            $prefix = $currentConfig['db_prefix'] ?? 'va_';
            
            $stmt = $pdo->prepare("SELECT `password_hash` FROM `{$prefix}users` WHERE `role` = 'admin' LIMIT 1");
            $stmt->execute();
            $adminUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($adminUser && password_verify($enteredPass, $adminUser['password_hash'])) {
                $_SESSION['va_install_auth'] = true;
                $managerAuth = true;
            } else {
                $error = 'Неверный пароль администратора.';
            }
        } catch (PDOException $e) {
            $error = 'Ошибка соединения с БД: ' . $e->getMessage();
        }
    }

    // Authenticated Manager Actions
    if ($managerAuth && $_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // Action 1: Upload and Extract ZIP
        if (isset($_POST['upload_zip']) && !empty($_FILES['zip_file'])) {
            $file = $_FILES['zip_file'];
            if ($file['error'] === UPLOAD_ERR_OK && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($file['tmp_name']) === true) {
                    $zip->extractTo(__DIR__);
                    $zip->close();
                    $success = 'Пакет обновления успешно загружен и распакован!';
                } else {
                    $error = 'Не удалось открыть ZIP-архив.';
                }
            } else {
                $error = 'Ошибка загрузки ZIP-файла или модуль ZipArchive не установлен.';
            }
        }

        // Action 2: Toggle Maintenance Mode
        if (isset($_POST['toggle_maintenance'])) {
            try {
                $dsn = "mysql:host={$currentConfig['db_host']};port=" . ($currentConfig['db_port'] ?? '3306') . ";dbname={$currentConfig['db_name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $currentConfig['db_user'], $currentConfig['db_pass'] ?? '');
                $prefix = $currentConfig['db_prefix'] ?? 'va_';
                
                $cur = $pdo->query("SELECT `setting_value` FROM `{$prefix}settings` WHERE `setting_key` = 'maintenance_mode'")->fetchColumn();
                $new = ($cur === '1') ? '0' : '1';
                $pdo->prepare("UPDATE `{$prefix}settings` SET `setting_value` = :v WHERE `setting_key` = 'maintenance_mode'")->execute(['v' => $new]);
                $success = ($new === '1') ? 'Режим обслуживания ВКЛЮЧЕН.' : 'Режим обслуживания ВЫКЛЮЧЕН (сайт доступен).';
            } catch (PDOException $e) {
                $error = 'Ошибка обновления: ' . $e->getMessage();
            }
        }

        // Action 3: Wipe ONLY VladAero Tables & Reset
        if (isset($_POST['wipe_database'])) {
            $confirmText = trim($_POST['confirm_wipe'] ?? '');
            if ($confirmText === 'WIPE-VLADAERO') {
                try {
                    $dsn = "mysql:host={$currentConfig['db_host']};port=" . ($currentConfig['db_port'] ?? '3306') . ";dbname={$currentConfig['db_name']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $currentConfig['db_user'], $currentConfig['db_pass'] ?? '');
                    $prefix = $currentConfig['db_prefix'] ?? 'va_';

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}%'");
                    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($tables as $tbl) {
                        $pdo->exec("DROP TABLE IF EXISTS `{$tbl}`");
                    }
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                    // Remove config.php
                    @unlink($configFile);
                    unset($_SESSION['va_install_auth']);
                    header('Location: install.php?step=1');
                    exit;
                } catch (PDOException $e) {
                    $error = 'Ошибка при очистке: ' . $e->getMessage();
                }
            } else {
                $error = 'Для подтверждения очистки введите фразу WIPE-VLADAERO';
            }
        }
    }
}

// -------------------------------------------------------------
// INSTALLATION WIZARD STEPS
// -------------------------------------------------------------

// Step 2: Process Database Configuration & Migration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_db_step'])) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbPrefix = trim($_POST['db_prefix'] ?? 'va_');
    if (!str_ends_with($dbPrefix, '_')) $dbPrefix .= '_';

    try {
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $_SESSION['install_db'] = [
            'db_host'   => $dbHost,
            'db_port'   => $dbPort,
            'db_name'   => $dbName,
            'db_user'   => $dbUser,
            'db_pass'   => $dbPass,
            'db_prefix' => $dbPrefix
        ];

        // Execute Schema Migration
        $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
        if ($dbPrefix !== 'va_') {
            $schemaSql = str_replace('`va_', '`' . $dbPrefix, $schemaSql);
        }
        $pdo->exec($schemaSql);

        // Execute Seed Data
        if (file_exists(__DIR__ . '/seed_data.sql')) {
            $seedSql = file_get_contents(__DIR__ . '/seed_data.sql');
            if ($dbPrefix !== 'va_') {
                $seedSql = str_replace('`va_', '`' . $dbPrefix, $seedSql);
            }
            $pdo->exec($seedSql);
        }

        header('Location: install.php?step=3');
        exit;
    } catch (PDOException $e) {
        $error = 'Ошибка подключения к MySQL: ' . $e->getMessage();
    }
}

// Step 3: Process Admin & Portal Settings Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_admin_step'])) {
    $adminUser = trim($_POST['admin_username'] ?? 'admin');
    $adminEmail = trim($_POST['admin_email'] ?? 'admin@vladaero.ru');
    $adminPass = $_POST['admin_password'] ?? 'admin123';
    $siteName = trim($_POST['site_name'] ?? 'VladAero');
    $aiKey = trim($_POST['ai_api_key'] ?? '');
    $firecrawlKey = trim($_POST['firecrawl_api_key'] ?? '');

    $dbInfo = $_SESSION['install_db'] ?? null;
    if (!$dbInfo) {
        header('Location: install.php?step=2');
        exit;
    }

    try {
        $dsn = "mysql:host={$dbInfo['db_host']};port={$dbInfo['db_port']};dbname={$dbInfo['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbInfo['db_user'], $dbInfo['db_pass']);
        $prefix = $dbInfo['db_prefix'];

        $passHash = password_hash($adminPass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO `{$prefix}users` (`id`, `username`, `email`, `password_hash`, `full_name`, `avatar`, `role`, `rank_title`, `xp_points`, `reputation`, `units_system`, `theme_preference`) 
                               VALUES (1, :u, :e, :p, 'Шеф-пилот VladAero', 'default_avatar.svg', 'admin', 'Шеф-пилот', 5000, 100, 'aviation_imperial', 'dark')
                               ON DUPLICATE KEY UPDATE `username` = VALUES(`username`), `email` = VALUES(`email`), `password_hash` = VALUES(`password_hash`), `role` = 'admin'");
        $stmt->execute(['u' => $adminUser, 'e' => $adminEmail, 'p' => $passHash]);

        // Update settings
        $stmt = $pdo->prepare("UPDATE `{$prefix}settings` SET `setting_value` = :v WHERE `setting_key` = :k");
        $stmt->execute(['k' => 'site_name', 'v' => $siteName]);
        if ($aiKey) {
            $stmt->execute(['k' => 'ai_api_key', 'v' => $aiKey]);
        }
        if ($firecrawlKey) {
            $stmt->execute(['k' => 'firecrawl_api_key', 'v' => $firecrawlKey]);
        }

        // Write config.php
        $configCode = "<?php\ndeclare(strict_types=1);\n\nreturn [\n" .
            "    'db_host'   => '" . addslashes($dbInfo['db_host']) . "',\n" .
            "    'db_port'   => '" . addslashes($dbInfo['db_port']) . "',\n" .
            "    'db_name'   => '" . addslashes($dbInfo['db_name']) . "',\n" .
            "    'db_user'   => '" . addslashes($dbInfo['db_user']) . "',\n" .
            "    'db_pass'   => '" . addslashes($dbInfo['db_pass']) . "',\n" .
            "    'db_prefix' => '" . addslashes($dbInfo['db_prefix']) . "',\n" .
            "];\n";

        file_put_contents($configFile, $configCode);
        unset($_SESSION['install_db']);

        header('Location: install.php?step=complete');
        exit;
    } catch (PDOException $e) {
        $error = 'Ошибка сохранения настроек: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка и управление — VladAero</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .glass-install {
            background: rgba(11, 19, 43, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(56, 189, 248, 0.2);
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 font-sans min-h-screen flex items-center justify-center p-4 selection:bg-sky-500 selection:text-white">

<div class="max-w-2xl w-full">
    
    <!-- Branding Header -->
    <div class="text-center mb-8 space-y-2">
        <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-sky-600 to-indigo-600 flex items-center justify-center text-white mx-auto shadow-2xl shadow-sky-600/30">
            <i data-lucide="plane" class="w-8 h-8 transform -rotate-45"></i>
        </div>
        <h1 class="text-3xl font-extrabold font-mono tracking-wider text-white">VLAD<span class="text-amber-500">AERO</span></h1>
        <div class="text-xs font-mono text-sky-400">Мастер установки и диспетчер обновлений портала</div>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-mono">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="mb-6 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-mono">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- STEP 1: Requirements Check & ZIP Detection / Upload -->
    <?php if ($step === '1'): ?>
        <div class="glass-install rounded-3xl p-8 shadow-2xl space-y-6">
            <div class="border-b border-white/10 pb-4">
                <h2 class="text-xl font-bold text-white font-mono">Этап 1: Проверка готовности хостинга</h2>
                <p class="text-xs text-slate-400 font-mono mt-1">Проверка версии PHP, расширений и локальных архивов обновления</p>
            </div>

            <div class="space-y-2.5 font-mono text-xs">
                <div class="flex items-center justify-between p-3 rounded-xl bg-slate-900/60 border border-white/5">
                    <span>Версия PHP (>= 8.0):</span>
                    <span class="text-emerald-400 font-bold"><?= PHP_VERSION ?> ✔</span>
                </div>
                <div class="flex items-center justify-between p-3 rounded-xl bg-slate-900/60 border border-white/5">
                    <span>Расширение PDO & MySQL:</span>
                    <span class="text-emerald-400 font-bold"><?= extension_loaded('pdo_mysql') ? 'Установлено ✔' : 'Отсутствует ✘' ?></span>
                </div>
                <div class="flex items-center justify-between p-3 rounded-xl bg-slate-900/60 border border-white/5">
                    <span>Расширение GD (WebP):</span>
                    <span class="text-emerald-400 font-bold"><?= extension_loaded('gd') ? 'Доступно ✔' : 'Отсутствует ✘' ?></span>
                </div>
                <div class="flex items-center justify-between p-3 rounded-xl bg-slate-900/60 border border-white/5">
                    <span>Расширение ZipArchive:</span>
                    <span class="text-emerald-400 font-bold"><?= class_exists('ZipArchive') ? 'Доступно ✔' : 'Отсутствует ✘' ?></span>
                </div>
            </div>

            <!-- AUTO-DETECTED LOCAL ZIP CARD -->
            <?php if ($detectedZip): ?>
                <div class="p-5 rounded-2xl bg-gradient-to-r from-sky-950/80 to-indigo-950/80 border border-sky-500/40 space-y-3 font-mono text-xs shadow-xl">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2 text-sky-400 font-bold">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                            <span>✨ Обнаружен локальный архив VladAero!</span>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-sky-500/20 text-sky-300 font-bold"><?= $detectedZip['size_mb'] ?> МБ</span>
                    </div>
                    <div class="text-slate-300 text-[11px]">
                        Файл <code><?= htmlspecialchars($detectedZip['filename']) ?></code> найден в каталоге (файлов в архиве: <?= $detectedZip['num_files'] ?>, изменён: <?= $detectedZip['modified'] ?>).
                    </div>
                    <form method="POST">
                        <input type="hidden" name="use_local_zip" value="1">
                        <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-slate-950 font-bold transition shadow-lg shadow-amber-500/20 flex items-center justify-center space-x-2">
                            <i data-lucide="package-check" class="w-4 h-4"></i>
                            <span>Распаковать и применить найденный <?= htmlspecialchars($detectedZip['filename']) ?></span>
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Manual ZIP Upload Box -->
            <div class="p-4 rounded-2xl bg-slate-900/90 border border-white/5 space-y-3 font-mono text-xs">
                <div class="font-bold text-slate-300">📦 Загрузить другой ZIP-архив через браузер:</div>
                <form method="POST" enctype="multipart/form-data" class="flex items-center space-x-2">
                    <input type="hidden" name="upload_zip" value="1">
                    <input type="file" name="zip_file" accept=".zip" class="text-xs text-slate-400 file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:bg-sky-600 file:text-white file:text-xs">
                    <button type="submit" class="px-4 py-1.5 rounded-lg bg-sky-600 text-white font-bold">Распаковать</button>
                </form>
            </div>

            <div class="flex justify-end pt-2">
                <a href="install.php?step=2" class="px-8 py-3 rounded-2xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 text-white font-mono text-sm font-bold shadow-xl shadow-sky-600/30 transition">
                    Перейти к настройке MySQL ➔
                </a>
            </div>
        </div>

    <!-- STEP 2: Database Connection & Table Prefix -->
    <?php elseif ($step === '2'): ?>
        <form method="POST" class="glass-install rounded-3xl p-8 shadow-2xl space-y-6 font-mono text-xs">
            <input type="hidden" name="submit_db_step" value="1">

            <div class="border-b border-white/10 pb-4">
                <h2 class="text-xl font-bold text-white">Этап 2: Параметры базы данных MySQL</h2>
                <p class="text-xs text-slate-400 mt-1">Введите реквизиты доступа к вашей базе данных MySQL</p>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Хост MySQL</label>
                    <input type="text" name="db_host" value="localhost" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Порт</label>
                    <input type="text" name="db_port" value="3306" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Имя базы данных (Database Name)</label>
                <input type="text" name="db_name" required placeholder="vladaero_db" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-bold">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Пользователь (User)</label>
                    <input type="text" name="db_user" required placeholder="root / username" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Пароль (Password)</label>
                    <input type="password" name="db_pass" placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/90 border border-amber-500/30 space-y-2">
                <label class="block text-amber-400 uppercase font-bold text-[10px]">Префикс таблиц (любой префикс)</label>
                <input type="text" name="db_prefix" value="va_" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white font-bold">
                <div class="text-[10px] text-slate-400">Позволяет размещать несколько проектов в одной БД (например: va_, myflight_).</div>
            </div>

            <div class="flex justify-between items-center pt-2">
                <a href="install.php?step=1" class="text-slate-400 hover:text-white">Назад</a>
                <button type="submit" class="px-8 py-3 rounded-2xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 text-white font-bold text-sm shadow-xl shadow-sky-600/30 transition">
                    Проверить и мигрировать таблицы ➔
                </button>
            </div>
        </form>

    <!-- STEP 3: Admin & Settings -->
    <?php elseif ($step === '3'): ?>
        <form method="POST" class="glass-install rounded-3xl p-8 shadow-2xl space-y-6 font-mono text-xs">
            <input type="hidden" name="submit_admin_step" value="1">

            <div class="border-b border-white/10 pb-4">
                <h2 class="text-xl font-bold text-white">Этап 3: Создание учетной записи Администратора</h2>
                <p class="text-xs text-slate-400 mt-1">Шеф-пилот и главные настройки системы</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Имя администратора</label>
                    <input type="text" name="admin_username" value="admin" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-bold">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Email</label>
                    <input type="email" name="admin_email" value="admin@vladaero.ru" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Пароль администратора</label>
                <input type="password" name="admin_password" required value="admin123" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-bold">
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Название сайта</label>
                <input type="text" name="site_name" value="VladAero" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">API-ключ ИИ (OpenAI / OpenRouter - опционально)</label>
                    <input type="password" name="ai_api_key" placeholder="sk-..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Firecrawl API Key (Web Search & Scrape)</label>
                    <input type="password" name="firecrawl_api_key" placeholder="fc-..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="px-8 py-3 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 text-white font-bold text-sm shadow-xl shadow-emerald-600/30 transition">
                    Завершить установку портала ➔
                </button>
            </div>
        </form>

    <!-- COMPLETE SCREEN -->
    <?php elseif ($step === 'complete'): ?>
        <div class="glass-install rounded-3xl p-8 shadow-2xl text-center space-y-6 font-mono">
            <div class="w-16 h-16 bg-emerald-500/10 text-emerald-400 rounded-full flex items-center justify-center mx-auto text-3xl font-bold">
                ✔
            </div>
            <h2 class="text-2xl font-bold text-white">VladAero успешно установлен!</h2>
            <p class="text-xs text-slate-300 leading-relaxed max-w-md mx-auto">
                База данных мигрирована, конфигурационный файл <code>config.php</code> создан, аккаунт администратора активирован.
            </p>

            <div class="flex justify-center space-x-4 pt-4 text-xs">
                <a href="index.php" class="px-6 py-3 rounded-2xl bg-sky-600 hover:bg-sky-500 text-white font-bold shadow-lg shadow-sky-600/30 transition">
                    Открыть главную страницу
                </a>
                <a href="admin/index.php" class="px-6 py-3 rounded-2xl bg-slate-800 hover:bg-slate-700 text-white font-bold transition">
                    Панель управления
                </a>
            </div>
        </div>

    <!-- POST-INSTALLATION MANAGER -->
    <?php elseif ($step === 'manager'): ?>
        <div class="glass-install rounded-3xl p-8 shadow-2xl space-y-6 font-mono text-xs">
            
            <?php if (!$managerAuth): ?>
                <form method="POST" class="space-y-4 text-center">
                    <div class="text-sm font-bold text-amber-400">Вход в сервисный диспетчер VladAero</div>
                    <p class="text-slate-400 text-[11px]">Введите пароль администратора портала для доступа к сервисным функциям</p>
                    <input type="password" name="admin_password" placeholder="Пароль администратора..." required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-white text-center">
                    <button type="submit" name="manager_login" value="1" class="w-full py-3 rounded-2xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold transition">
                        Войти в диспетчер
                    </button>
                </form>
            <?php else: ?>
                <!-- Manager Dashboard -->
                <div class="border-b border-white/10 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-white">Сервисный центр VladAero</h2>
                        <div class="text-[10px] text-emerald-400">АВТОРИЗОВАН КАК АДМИНИСТРАТОР</div>
                    </div>
                    <a href="index.php" class="text-sky-400 hover:underline">На сайт ➔</a>
                </div>

                <!-- AUTO-DETECTED LOCAL ZIP UPDATE OPTION -->
                <?php if ($detectedZip): ?>
                    <div class="p-5 rounded-2xl bg-gradient-to-r from-sky-950/80 to-indigo-950/80 border border-sky-500/40 space-y-3 font-mono text-xs shadow-xl">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2 text-sky-400 font-bold">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                                <span>✨ Обнаружен локальный архив обновления: <?= htmlspecialchars($detectedZip['filename']) ?></span>
                            </div>
                            <span class="px-2 py-0.5 rounded bg-sky-500/20 text-sky-300 font-bold"><?= $detectedZip['size_mb'] ?> МБ</span>
                        </div>
                        <div class="text-slate-300 text-[11px]">
                            Архив содержит <?= $detectedZip['num_files'] ?> файлов (изменён: <?= $detectedZip['modified'] ?>). Вы можете применить его для мгновенного обновления сайта.
                        </div>
                        <form method="POST">
                            <input type="hidden" name="use_local_zip" value="1">
                            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-slate-950 font-bold transition shadow-lg shadow-amber-500/20 flex items-center justify-center space-x-2">
                                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                <span>Обновить сайт из найденного архива <?= htmlspecialchars($detectedZip['filename']) ?></span>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Manual Upload & Update from ZIP -->
                <div class="p-5 rounded-2xl bg-slate-900/80 border border-white/5 space-y-3">
                    <div class="font-bold text-slate-300">📦 Загрузить другой ZIP-пакет обновления:</div>
                    <form method="POST" enctype="multipart/form-data" class="flex items-center space-x-2">
                        <input type="hidden" name="upload_zip" value="1">
                        <input type="file" name="zip_file" accept=".zip" required class="text-xs text-slate-400 file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:bg-sky-600 file:text-white file:text-xs">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold">Распаковать и обновить</button>
                    </form>
                </div>

                <!-- Toggle Maintenance -->
                <div class="p-5 rounded-2xl bg-slate-900/80 border border-amber-500/30 flex items-center justify-between">
                    <div>
                        <div class="font-bold text-amber-400">Режим регламентных работ</div>
                        <div class="text-[10px] text-slate-400">Переключение заглушки техобслуживания для гостей</div>
                    </div>
                    <form method="POST">
                        <button type="submit" name="toggle_maintenance" value="1" class="px-4 py-2 rounded-xl bg-amber-500 text-slate-950 font-bold">
                            Переключить статус
                        </button>
                    </form>
                </div>

                <!-- Danger Zone: Wipe ONLY VladAero Tables -->
                <div class="p-5 rounded-2xl bg-rose-950/20 border border-rose-500/40 space-y-3">
                    <div class="font-bold text-rose-400">⚠️ Полная переустановка (Wipe VladAero)</div>
                    <p class="text-[11px] text-slate-400">
                        Удаляет ТОЛЬКО таблицы с префиксом <code><?= htmlspecialchars($currentConfig['db_prefix'] ?? 'va_') ?></code> и сбрасывает <code>config.php</code>. Чужие таблицы в базе НЕ затрагиваются.
                    </p>
                    <form method="POST" class="flex items-center space-x-2">
                        <input type="text" name="confirm_wipe" placeholder="Введите WIPE-VLADAERO" required class="flex-1 bg-slate-950 border border-rose-500/40 rounded-xl px-3 py-2 text-white">
                        <button type="submit" name="wipe_database" value="1" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold" onclick="return confirm('Вы уверены? Таблицы VladAero будут удалены!')">
                            Стереть и начать заново
                        </button>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</div>

<script>
    lucide.createIcons();
</script>
</body>
</html>
