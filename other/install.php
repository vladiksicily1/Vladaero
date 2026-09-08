<?php
/**
 * ShibaLingo - Universal Installer, Updater, Backup & Maintenance Manager
 * Completely self-contained: upload this single file to your hosting and open in browser!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isInstalled = file_exists(__DIR__ . '/config.php');
$action = $_GET['action'] ?? ($_POST['action'] ?? 'index');
$error = '';
$success = '';

// Find local ZIP files in directory
$localZips = glob(__DIR__ . '/*.zip');
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

// -------------------------------------------------------------------------
// Helper: Run SQL Dump file with dynamic Table Prefix
// -------------------------------------------------------------------------
function executeSqlFile(PDO $pdo, string $filePath, string $prefix = ''): void {
    if (!file_exists($filePath)) return;
    $sql = file_get_contents($filePath);
    // Remove comments
    $sql = preg_replace('/--.*$/m', '', $sql);
    
    // Apply Table Prefix to table names if specified
    if (!empty($prefix)) {
        $tableList = [
            'roles', 'users', 'languages', 'conlang_dictionary', 'conlang_grammar',
            'skills', 'lessons', 'user_progress', 'chat_sessions', 'chat_messages',
            'shop_items', 'user_inventory', 'achievements', 'user_achievements',
            'daily_quests', 'user_quests', 'slang_proposals', 'stories', 'clubs',
            'club_members', 'promo_codes', 'user_promo_uses', 'duel_rooms',
            'ai_logs', 'settings'
        ];
        foreach ($tableList as $tblName) {
            $sql = preg_replace('/`' . preg_quote($tblName, '/') . '`/i', '`' . $prefix . $tblName . '`', $sql);
        }
    }

    $queries = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($queries as $q) {
        if (!empty($q)) {
            try {
                $pdo->exec($q);
            } catch (Exception $e) {
                // Continue on non-critical errors during migration
            }
        }
    }
}

// -------------------------------------------------------------------------
// Helper: Create Full Database Dump (.sql) compatible with MySQL and SQLite
// -------------------------------------------------------------------------
function generateDbDump(PDO $pdo, array $tables): string {
    $driver = 'mysql';
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql';
    } catch (Exception $de) {
        if (class_exists('Database')) {
            $driver = Database::getDriver();
        }
    }

    $dump = "-- ShibaLingo Database Backup ({$driver})\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($tables as $table) {
        try {
            // Table structure
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = :t");
                $stmt->execute(['t' => $table]);
                $createSql = $stmt->fetchColumn();
                if ($createSql) {
                    $dump .= "DROP TABLE IF EXISTS `{$table}`;\n" . $createSql . ";\n\n";
                }
            } else {
                $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                if ($row && isset($row[1])) {
                    $dump .= "DROP TABLE IF EXISTS `{$table}`;\n" . $row[1] . ";\n\n";
                }
            }

            // Table data
            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                foreach ($rows as $r) {
                    $keys = array_map(function($k) { return "`$k`"; }, array_keys($r));
                    $values = array_map(function($v) use ($pdo) {
                        return $v === null ? 'NULL' : $pdo->quote($v);
                    }, array_values($r));
                    $dump .= "INSERT INTO `{$table}` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $values) . ");\n";
                }
                $dump .= "\n";
            }
        } catch (Exception $e) {
            // Skip non-existent tables gracefully
            continue;
        }
    }
    return $dump;
}

// -------------------------------------------------------------------------
// Helper: Unpack ZIP Archive
// -------------------------------------------------------------------------
function extractZip(string $zipPath, string $destDir): bool {
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive extension is not enabled on this server.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        $zip->extractTo($destDir);
        $zip->close();
        return true;
    }
    return false;
}

// -------------------------------------------------------------------------
// Helper: Create ZIP from directory/files
// -------------------------------------------------------------------------
function createZipArchive(string $destZipPath, array $filesAndFolders, ?string $extraSqlDump = null): bool {
    $zip = new ZipArchive();
    if ($zip->open($destZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    if ($extraSqlDump !== null) {
        $zip->addFromString('database_backup.sql', $extraSqlDump);
    }

    foreach ($filesAndFolders as $item) {
        if (is_file($item)) {
            $zip->addFile($item, basename($item));
        } elseif (is_dir($item)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($item, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen(__DIR__) + 1);
                // Exclude backups folder itself to avoid recursion
                if (strpos($relativePath, 'backups') === 0) continue;
                if ($file->isDir()) {
                    $zip->addEmptyDir($relativePath);
                } else {
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }
    }

    $zip->close();
    return true;
}

// -------------------------------------------------------------------------
// ACTION: RUN FULL INSTALLATION
// -------------------------------------------------------------------------
if ($action === 'do_install') {
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbPrefix = trim($_POST['db_prefix'] ?? 'sl_');

    $siteTitle = trim($_POST['site_title'] ?? 'ShibaLingo');
    $mascotName = trim($_POST['mascot_name'] ?? 'Сиба-сэнсэй');
    $nvidiaKey = trim($_POST['nvidia_key'] ?? '');

    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminEmail = trim($_POST['admin_email'] ?? 'admin@shibalingo.local');
    $adminPass = $_POST['admin_pass'] ?? 'admin123';

    $zipSource = $_POST['zip_source'] ?? 'local';
    $selectedZip = $_POST['selected_zip'] ?? '';

    try {
        // Step 1: Unpack ZIP if provided
        $zipToExtract = null;
        if ($zipSource === 'upload' && isset($_FILES['zip_file']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
            $zipToExtract = $_FILES['zip_file']['tmp_name'];
        } elseif ($zipSource === 'local' && !empty($selectedZip) && file_exists($selectedZip)) {
            $zipToExtract = $selectedZip;
        }

        if ($zipToExtract) {
            extractZip($zipToExtract, __DIR__);
        }

        // Step 2: Connect to MySQL
        $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // Create Database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        // Step 3: Run Schema SQL with dynamic Table Prefix
        $schemaPath = __DIR__ . '/schema.sql';
        if (file_exists($schemaPath)) {
            executeSqlFile($pdo, $schemaPath, $dbPrefix);
        }

        // Step 4: Create Admin User & Update Settings with Prefix
        $adminHash = password_hash($adminPass, PASSWORD_BCRYPT);
        $userStmt = $pdo->prepare("INSERT INTO `{$dbPrefix}users` (`username`, `email`, `password_hash`, `role_id`, `xp`, `streak`, `hearts`, `gems`) 
                                   VALUES (:u, :e, :p, 1, 100, 1, 5, 100) 
                                   ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`), `role_id` = 1");
        $userStmt->execute(['u' => $adminUser, 'e' => $adminEmail, 'p' => $adminHash]);

        // Save Settings
        $setStmt = $pdo->prepare("INSERT INTO `{$dbPrefix}settings` (`setting_key`, `setting_value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");
        $setStmt->execute(['k' => 'site_title', 'v' => $siteTitle]);
        $setStmt->execute(['k' => 'mascot_name', 'v' => $mascotName]);
        $setStmt->execute(['k' => 'nvidia_api_key', 'v' => $nvidiaKey]);

        // Step 5: Write config.php with DB_PREFIX
        $configContent = "<?php\n"
            . "// Generated by ShibaLingo Installer on " . date('Y-m-d H:i:s') . "\n"
            . "error_reporting(E_ALL);\n"
            . "ini_set('display_errors', 1);\n\n"
            . "if (session_status() === PHP_SESSION_NONE) {\n"
            . "    session_start();\n"
            . "}\n\n"
            . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
            . "define('DB_PORT', " . var_export($dbPort, true) . ");\n"
            . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
            . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
            . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_PREFIX', " . var_export($dbPrefix, true) . ");\n\n"
            . "define('USE_SQLITE_FALLBACK', false);\n"
            . "define('SQLITE_PATH', __DIR__ . '/shibalingo.sqlite');\n\n"
            . "define('NVIDIA_API_URL', 'https://integrate.api.nvidia.com/v1/chat/completions');\n"
            . "define('DEFAULT_NVIDIA_MODEL', 'meta/llama-3.3-70b-instruct');\n\n"
            . "define('APP_NAME', " . var_export($siteTitle, true) . ");\n"
            . "define('APP_TAGLINE', 'Учи языки и тайный язык Vladikish вместе с Шиба-Ину!');\n"
            . "define('DEFAULT_HEARTS', 5);\n"
            . "define('MAX_HEARTS', 5);\n"
            . "define('DEFAULT_LANGUAGE', 'vladikish');\n";

        file_put_contents(__DIR__ . '/config.php', $configContent);

        // Ensure uploads directory exists
        if (!is_dir(__DIR__ . '/uploads')) {
            @mkdir(__DIR__ . '/uploads', 0755, true);
        }

        $success = 'Поздравляем! Платформа ShibaLingo успешно установлена и готова к работе! 🐕🎉';
        $isInstalled = true;
    } catch (Exception $e) {
        $error = 'Ошибка установки: ' . $e->getMessage();
    }
}

// -------------------------------------------------------------------------
// ACTION: UPDATE SYSTEM WITH DUAL BACKUPS
// -------------------------------------------------------------------------
if ($action === 'do_update') {
    if (!$isInstalled) {
        $error = 'Система еще не установлена.';
    } else {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/db.php';

        try {
            $pdo = Database::getConnection();
            $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
            $baseTables = ['roles', 'users', 'languages', 'conlang_dictionary', 'conlang_grammar', 'skills', 'lessons', 'user_progress', 'chat_sessions', 'chat_messages', 'shop_items', 'user_inventory', 'achievements', 'user_achievements', 'daily_quests', 'user_quests', 'slang_proposals', 'stories', 'clubs', 'club_members', 'promo_codes', 'user_promo_uses', 'duel_rooms', 'ai_logs', 'settings'];
            $tables = array_map(fn($t) => $prefix . $t, $baseTables);
            $timestamp = date('Y-m-d_H-i-s');

            // 1. Generate SQL dump
            $sqlDump = generateDbDump($pdo, $tables);

            // 2. Backup 1: Data + Media (uploads folder)
            $backupDataMediaZip = $backupDir . "/backup_data_media_{$timestamp}.zip";
            $mediaFolders = array_filter([__DIR__ . '/uploads', __DIR__ . '/assets/uploads', __DIR__ . '/shibalingo.sqlite'], 'file_exists');
            createZipArchive($backupDataMediaZip, $mediaFolders, $sqlDump);

            // 3. Backup 2: Full System Snapshot (Code + DB + Media)
            $backupFullZip = $backupDir . "/backup_full_system_{$timestamp}.zip";
            createZipArchive($backupFullZip, [__DIR__ . '/includes', __DIR__ . '/assets', __DIR__ . '/api', __DIR__ . '/admin', __DIR__ . '/uploads', __DIR__ . '/config.php', __DIR__ . '/index.php', __DIR__ . '/lesson.php', __DIR__ . '/chat.php', __DIR__ . '/conlang.php', __DIR__ . '/settings.php', __DIR__ . '/shop.php', __DIR__ . '/leaderboard.php', __DIR__ . '/blitz.php', __DIR__ . '/quests.php', __DIR__ . '/achievements.php', __DIR__ . '/roleplay.php', __DIR__ . '/stories.php', __DIR__ . '/translator.php', __DIR__ . '/conjugator.php', __DIR__ . '/redeem.php', __DIR__ . '/tamagotchi.php', __DIR__ . '/duel.php', __DIR__ . '/raid.php', __DIR__ . '/worldmap.php', __DIR__ . '/certificate.php', __DIR__ . '/review.php', __DIR__ . '/mnemonics.php', __DIR__ . '/crossword.php', __DIR__ . '/podcast.php', __DIR__ . '/camera_scanner.php', __DIR__ . '/teacher.php', __DIR__ . '/referral.php', __DIR__ . '/lore.php'], $sqlDump);

            // 4. Extract new ZIP
            $zipSource = $_POST['zip_source'] ?? 'local';
            $selectedZip = $_POST['selected_zip'] ?? '';
            $zipToExtract = null;

            if ($zipSource === 'upload' && isset($_FILES['zip_file']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
                $zipToExtract = $_FILES['zip_file']['tmp_name'];
            } elseif ($zipSource === 'local' && !empty($selectedZip) && file_exists($selectedZip)) {
                $zipToExtract = $selectedZip;
            }

            if ($zipToExtract) {
                extractZip($zipToExtract, __DIR__);
            }

            // 5. Run Migrations with prefix
            if (file_exists(__DIR__ . '/schema.sql')) {
                executeSqlFile($pdo, __DIR__ . '/schema.sql', $prefix);
            }

            $success = "Обновление успешно завершено! Создано 2 резервных копии в папке /backups/:<br>1) 📦 <code>backup_data_media_{$timestamp}.zip</code><br>2) 🛡️ <code>backup_full_system_{$timestamp}.zip</code>";
        } catch (Exception $e) {
            $error = 'Ошибка при обновлении: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------------------
// ACTION: UNINSTALL (Drop only ShibaLingo tables matching prefix)
// -------------------------------------------------------------------------
if ($action === 'do_uninstall') {
    if (!$isInstalled) {
        $error = 'Система не установлена.';
    } else {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/db.php';

        try {
            $pdo = Database::getConnection();
            $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
            $shibaTables = ['chat_messages', 'chat_sessions', 'user_progress', 'lessons', 'skills', 'conlang_grammar', 'conlang_dictionary', 'shop_items', 'user_inventory', 'achievements', 'user_achievements', 'daily_quests', 'user_quests', 'slang_proposals', 'stories', 'clubs', 'club_members', 'promo_codes', 'user_promo_uses', 'duel_rooms', 'ai_logs', 'users', 'roles', 'languages', 'settings'];

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            foreach ($shibaTables as $t) {
                $pdo->exec("DROP TABLE IF EXISTS `{$prefix}{$t}`;");
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            // Remove config.php
            if (file_exists(__DIR__ . '/config.php')) {
                @unlink(__DIR__ . '/config.php');
            }
            if (file_exists(__DIR__ . '/shibalingo.sqlite')) {
                @unlink(__DIR__ . '/shibalingo.sqlite');
            }

            $success = 'ShibaLingo успешно деинсталлирован! Все таблицы с префиксом «' . $prefix . '» удалены из базы данных (сторонние таблицы не затронуты).';
            $isInstalled = false;
        } catch (Exception $e) {
            $error = 'Ошибка деинсталляции: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------------------
// ACTION: TOGGLE MAINTENANCE MODE
// -------------------------------------------------------------------------
if ($action === 'toggle_maintenance') {
    if ($isInstalled) {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/db.php';
        require_once __DIR__ . '/includes/functions.php';

        $mode = (int)($_POST['maintenance_mode'] ?? 0);
        $msg = trim($_POST['maintenance_message'] ?? 'Сиба-сэнсэй проводит техническое обслуживание!');
        $roles = json_encode($_POST['allowed_roles'] ?? ['superadmin', 'admin']);

        setSetting('maintenance_mode', (string)$mode);
        setSetting('maintenance_message', $msg);
        setSetting('maintenance_allowed_roles', $roles);

        $success = ($mode === 1) ? '🚧 Режим техобслуживания ВКЛЮЧЕН.' : '🟢 Режим техобслуживания ВЫКЛЮЧЕН.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShibaLingo — Центр Установки, Обновления и Обслуживания</title>
    <style>
        :root {
            --primary: #58cc02;
            --primary-shadow: #58a700;
            --secondary: #1cb0f6;
            --secondary-shadow: #1899d6;
            --danger: #ff4b4b;
            --danger-shadow: #ea2b2b;
            --bg: #f7f9fa;
            --card: #ffffff;
            --border: #e5e7eb;
            --text: #1f2937;
            --muted: #6b7280;
            --radius: 16px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Nunito', 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); padding: 40px 20px; line-height: 1.6; }
        .wrapper { max-width: 800px; margin: 0 auto; }
        .card { background: var(--card); border: 2px solid var(--border); border-radius: var(--radius); padding: 32px; box-shadow: 0 4px 0 var(--border); margin-bottom: 24px; }
        .logo { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
        .logo-icon { font-size: 2.8rem; }
        .logo-title { font-size: 1.8rem; font-weight: 900; color: var(--primary); }
        h2 { font-size: 1.4rem; font-weight: 800; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        label { display: block; font-weight: 700; font-size: 0.95rem; margin-bottom: 6px; }
        input, select, textarea { width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 12px; font-size: 1rem; font-family: inherit; margin-bottom: 16px; outline: none; transition: border-color 0.2s; }
        input:focus, select:focus, textarea:focus { border-color: var(--secondary); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit; font-weight: 800; font-size: 1rem; text-transform: uppercase; padding: 14px 28px; border-radius: 12px; border: none; cursor: pointer; text-decoration: none; transition: all 0.1s; width: 100%; user-select: none; }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 4px 0 var(--primary-shadow); }
        .btn-primary:active { transform: translateY(4px); box-shadow: none; }
        .btn-secondary { background: var(--secondary); color: white; box-shadow: 0 4px 0 var(--secondary-shadow); }
        .btn-secondary:active { transform: translateY(4px); box-shadow: none; }
        .btn-danger { background: var(--danger); color: white; box-shadow: 0 4px 0 var(--danger-shadow); }
        .btn-danger:active { transform: translateY(4px); box-shadow: none; }
        .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 700; font-size: 0.95rem; }
        .alert-success { background: #d7ffb8; color: #2e6900; border: 2px solid #58cc02; }
        .alert-error { background: #ffdfe0; color: #a31212; border: 2px solid #ff4b4b; }
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid var(--border); padding-bottom: 12px; }
        .tab-btn { padding: 10px 18px; border-radius: 10px; font-weight: 800; cursor: pointer; border: none; background: transparent; color: var(--muted); }
        .tab-btn.active { background: #d7ffb8; color: #2e6900; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 800; background: #e5e7eb; }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="card" style="text-align: center;">
        <div class="logo" style="justify-content: center;">
            <span class="logo-icon">🐕</span>
            <div>
                <div class="logo-title">ShibaLingo Master Installer</div>
                <div style="font-size: 0.9rem; color: var(--muted); font-weight: 700;">Установка, Обновление, Резервные копии и Режим Обслуживания</div>
            </div>
        </div>

        <div style="display: flex; justify-content: center; gap: 12px;">
            <span class="badge" style="background: <?= $isInstalled ? '#d7ffb8; color: #2e6900;' : '#ffdfe0; color: #a31212;' ?>">
                Статус: <?= $isInstalled ? '🟢 Установлено' : '🟡 Не установлено' ?>
            </span>
            <span class="badge">PHP: <?= phpversion() ?></span>
            <span class="badge">ZipArchive: <?= class_exists('ZipArchive') ? '✅ Включен' : '❌ Отключен' ?></span>
        </div>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">✓ <?= $success ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error">✕ <?= $error ?></div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="tabs">
        <button class="tab-btn <?= (!$isInstalled || $action === 'install') ? 'active' : '' ?>" onclick="switchTab('install')">
            🚀 <?= $isInstalled ? 'Переустановка' : 'Установка' ?>
        </button>
        <?php if ($isInstalled): ?>
            <button class="tab-btn <?= ($action === 'update') ? 'active' : '' ?>" onclick="switchTab('update')">
                🔄 Обновление (с бэкапами)
            </button>
            <button class="tab-btn <?= ($action === 'maintenance') ? 'active' : '' ?>" onclick="switchTab('maintenance')">
                🚧 Режим обслуживания
            </button>
            <button class="tab-btn <?= ($action === 'uninstall') ? 'active' : '' ?>" onclick="switchTab('uninstall')">
                🗑️ Деинсталляция
            </button>
        <?php endif; ?>
    </div>

    <!-- TAB 1: INSTALL -->
    <div id="tab-install" class="tab-content" style="<?= ($isInstalled && $action !== 'install') ? 'display:none;' : '' ?>">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="do_install">

            <div class="card">
                <h2>📦 1. Исходные файлы проекта (ZIP)</h2>
                <?php if (!empty($localZips)): ?>
                    <div style="background: #f0fdf4; border: 2px solid #bbf7d0; padding: 14px; border-radius: 12px; margin-bottom: 16px;">
                        <label style="color: #166534;">✓ Найден локальный ZIP-архив в этой папке:</label>
                        <select name="selected_zip">
                            <?php foreach ($localZips as $zip): ?>
                                <option value="<?= htmlspecialchars($zip) ?>"><?= basename($zip) ?> (<?= round(filesize($zip) / 1024 / 1024, 2) ?> MB)</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="zip_source" value="local">
                    </div>
                <?php else: ?>
                    <label>Загрузить архив проекта (.zip):</label>
                    <input type="file" name="zip_file" accept=".zip">
                    <input type="hidden" name="zip_source" value="upload">
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>🗄️ 2. База данных MySQL</h2>
                <div class="grid-2">
                    <div>
                        <label>Сервер MySQL (Host):</label>
                        <input type="text" name="db_host" value="127.0.0.1" required>
                    </div>
                    <div>
                        <label>Порт:</label>
                        <input type="text" name="db_port" value="3306" required>
                    </div>
                </div>
                <div class="grid-2">
                    <div>
                        <label>Имя базы данных (DB Name):</label>
                        <input type="text" name="db_name" value="shibalingo_db" required>
                    </div>
                    <div>
                        <label>Префикс таблиц (Table Prefix):</label>
                        <input type="text" name="db_prefix" value="sl_" placeholder="sl_ или shiba_" style="font-weight: 800; color: var(--secondary);">
                    </div>
                </div>
                <div class="grid-2">
                    <div>
                        <label>Пользователь БД (DB User):</label>
                        <input type="text" name="db_user" value="root" required>
                    </div>
                    <div>
                        <label>Пароль БД (DB Password):</label>
                        <input type="password" name="db_pass" placeholder="Оставьте пустым, если нет пароля">
                    </div>
                </div>
                <div style="font-size: 0.85rem; color: var(--muted);">
                    💡 <em>Префикс таблиц</em> позволяет безопасно использовать одну базу данных для нескольких сайтов (например: <code>sl_users</code>, <code>sl_lessons</code>).
                </div>
            </div>

            <div class="card">
                <h2>⚙️ 3. Основные настройки и NVIDIA AI</h2>
                <div class="grid-2">
                    <div>
                        <label>Название сайта:</label>
                        <input type="text" name="site_title" value="ShibaLingo">
                    </div>
                    <div>
                        <label>Имя маскота:</label>
                        <input type="text" name="mascot_name" value="Сиба-сэнсэй">
                    </div>
                </div>
                <div>
                    <label>NVIDIA NIM API Key (build.nvidia.com):</label>
                    <input type="password" name="nvidia_key" placeholder="nvapi-xxxxxxxxxxxxxxxxxxxxxxxx">
                </div>
            </div>

            <div class="card">
                <h2>👤 4. Учетная запись Главного Администратора (SuperAdmin)</h2>
                <div class="grid-2">
                    <div>
                        <label>Логин администратора:</label>
                        <input type="text" name="admin_user" value="admin" required>
                    </div>
                    <div>
                        <label>Email:</label>
                        <input type="email" name="admin_email" value="admin@shibalingo.local" required>
                    </div>
                </div>
                <div>
                    <label>Пароль администратора:</label>
                    <input type="password" name="admin_pass" value="admin123" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="font-size: 1.15rem; padding: 18px;">
                🚀 Запустить установку ShibaLingo
            </button>
        </form>
    </div>

    <!-- TAB 2: UPDATE -->
    <?php if ($isInstalled): ?>
    <div id="tab-update" class="tab-content" style="display:none;">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="do_update">
            <div class="card">
                <h2>🔄 Обновление из нового ZIP-архива</h2>
                <p style="color: var(--muted); margin-bottom: 20px;">
                    Перед обновлением система <strong>автоматически создаст 2 независимых бэкапа</strong>:
                    <br>1) 📦 <em>Резервная копия базы данных и медиа-файлов</em>
                    <br>2) 🛡️ <em>Полный снимок системы (код + база + медиа)</em>
                </p>

                <?php if (!empty($localZips)): ?>
                    <label>Выберите локальный ZIP-файл:</label>
                    <select name="selected_zip">
                        <?php foreach ($localZips as $zip): ?>
                            <option value="<?= htmlspecialchars($zip) ?>"><?= basename($zip) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="zip_source" value="local">
                <?php else: ?>
                    <label>Загрузите новый ZIP-архив с обновлением:</label>
                    <input type="file" name="zip_file" accept=".zip" required>
                    <input type="hidden" name="zip_source" value="upload">
                <?php endif; ?>

                <button type="submit" class="btn btn-secondary" style="margin-top: 16px;">
                    🛡️ Создать 2 бэкапа и Обновить систему
                </button>
            </div>
        </form>
    </div>

    <!-- TAB 3: MAINTENANCE -->
    <div id="tab-maintenance" class="tab-content" style="display:none;">
        <form method="POST">
            <input type="hidden" name="action" value="toggle_maintenance">
            <div class="card">
                <h2>🚧 Управление режимом технического обслуживания</h2>
                <p style="color: var(--muted); margin-bottom: 20px;">
                    Во время обслуживания обычные пользователи видят заглушку с отдыхающим Шибой, а выбранные роли имеют полный доступ.
                </p>

                <label>Состояние режима обслуживания:</label>
                <select name="maintenance_mode">
                    <option value="0">🟢 Выключен (Сайт доступен всем)</option>
                    <option value="1">🚧 Включен (Доступ только по ролям)</option>
                </select>

                <label>Сообщение для посетителей:</label>
                <textarea name="maintenance_message" rows="3">Сиба-сэнсэй проводит техническое обслуживание! Скоро вернемся 🐾</textarea>

                <label>Разрешенные роли для входа:</label>
                <div style="display: flex; gap: 16px; margin-bottom: 20px;">
                    <label style="font-weight: normal;"><input type="checkbox" name="allowed_roles[]" value="superadmin" checked> SuperAdmin</label>
                    <label style="font-weight: normal;"><input type="checkbox" name="allowed_roles[]" value="admin" checked> Admin</label>
                    <label style="font-weight: normal;"><input type="checkbox" name="allowed_roles[]" value="beta_tester" checked> Beta Tester</label>
                </div>

                <button type="submit" class="btn btn-primary">
                    💾 Сохранить режим обслуживания
                </button>
            </div>
        </form>
    </div>

    <!-- TAB 4: UNINSTALL -->
    <div id="tab-uninstall" class="tab-content" style="display:none;">
        <form method="POST" onsubmit="return confirm('ВНИМАНИЕ! Это действие удалит ВСЕ таблицы ShibaLingo из базы данных. Вы уверены?');">
            <input type="hidden" name="action" value="do_uninstall">
            <div class="card" style="border-color: var(--danger);">
                <h2 style="color: var(--danger);">🗑️ Безопасная деинсталляция</h2>
                <p style="color: var(--muted); margin-bottom: 20px;">
                    Мастер деинсталляции удалит <strong>исключительно таблицы ShibaLingo</strong> из базы данных. Если в этой же базе находятся другие сайты или таблицы, они останутся нетронутыми.
                </p>
                <button type="submit" class="btn btn-danger">
                    ⚠️ Стереть таблицы ShibaLingo и сбросить установку
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
    
    event.target.classList.add('active');
    const content = document.getElementById('tab-' + tabId);
    if (content) content.style.display = 'block';
}
</script>

</body>
</html>
