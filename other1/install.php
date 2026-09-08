<?php
/**
 * VladInc Tech-Admin & Ecosystem Deployment Center (install.php)
 * Self-contained Single-file Setup, Backup, Base64-ZIP Updater & Auto-Migrations
 *
 * Designed for vladinc.ru on Shared Hosting
 * PROTECTED: Never modifies or touches 'mama' or 'shibalingo' folders.
 */

// Basic error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '300');
@ini_set('upload_max_filesize', '64M');
@ini_set('post_max_size', '64M');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('INSTALL_ROOT', __DIR__);
define('BACKUP_DIR', INSTALL_ROOT . '/backups');
define('MIGRATIONS_DIR', INSTALL_ROOT . '/migrations');
define('CONFIG_FILE', INSTALL_ROOT . '/core/config.php');
define('SCHEMA_FILE', INSTALL_ROOT . '/schema.sql');

if (!is_dir(BACKUP_DIR)) {
    @mkdir(BACKUP_DIR, 0755, true);
    @file_put_contents(BACKUP_DIR . '/.htaccess', "Deny from all\n");
}
if (!is_dir(MIGRATIONS_DIR)) {
    @mkdir(MIGRATIONS_DIR, 0755, true);
}

define('TECH_PASS_FILE', BACKUP_DIR . '/.tech_auth');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function is_tech_authenticated(): bool {
    if (!file_exists(TECH_PASS_FILE)) {
        return true;
    }
    return !empty($_SESSION['vladinc_tech_auth']) && $_SESSION['vladinc_tech_auth'] === true;
}

if ($action === 'tech_login') {
    $password = $_POST['tech_password'] ?? '';
    if (file_exists(TECH_PASS_FILE)) {
        $hash = trim(file_get_contents(TECH_PASS_FILE));
        if (password_verify($password, $hash)) {
            $_SESSION['vladinc_tech_auth'] = true;
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Неверный пароль тех-администратора']);
            exit;
        }
    }
}

if ($action === 'tech_logout') {
    unset($_SESSION['vladinc_tech_auth']);
    header('Location: install.php');
    exit;
}

function set_tech_password(string $password): void {
    @file_put_contents(TECH_PASS_FILE, password_hash($password, PASSWORD_BCRYPT));
}

// ROBUST SQL SCRIPT EXECUTION
function execute_sql_script(PDO $pdo, string $sql): void {
    $trimmed = trim($sql);
    if (empty($trimmed)) return;
    try {
        $pdo->exec($trimmed);
    } catch (Throwable $e) {
        // Fallback: split by semicolon, strip comments, run statement by statement
        $lines = explode("\n", $trimmed);
        $cleanSql = '';
        foreach ($lines as $line) {
            $t = trim($line);
            if (strpos($t, '--') === 0 || strpos($t, '#') === 0) continue;
            $cleanSql .= $line . "\n";
        }
        $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try {
                    $pdo->exec($stmt);
                } catch (Throwable $ignored) {}
            }
        }
    }
}

// AUTO-MIGRATIONS ENGINE
function run_database_migrations(PDO $pdo): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `migration` VARCHAR(255) NOT NULL UNIQUE,
        `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $executed = $pdo->query("SELECT migration FROM `migrations`")->fetchAll(PDO::FETCH_COLUMN);

    $applied = [];
    if (is_dir(MIGRATIONS_DIR)) {
        $files = scandir(MIGRATIONS_DIR);
        sort($files);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || pathinfo($file, PATHINFO_EXTENSION) !== 'sql') {
                continue;
            }
            if (!in_array($file, $executed, true)) {
                $sqlContent = file_get_contents(MIGRATIONS_DIR . '/' . $file);
                if (!empty(trim($sqlContent))) {
                    execute_sql_script($pdo, $sqlContent);
                    $ins = $pdo->prepare("INSERT INTO `migrations` (`migration`) VALUES (?)");
                    $ins->execute([$file]);
                    $applied[] = $file;
                }
            }
        }
    }

    // Safe Alter checks for older schema upgrades
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM posts LIKE 'poll_question'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE posts 
                ADD COLUMN `poll_question` VARCHAR(255) DEFAULT NULL AFTER `media_type`,
                ADD COLUMN `poll_options` TEXT DEFAULT NULL AFTER `poll_question`,
                ADD COLUMN `poll_votes` TEXT DEFAULT NULL AFTER `poll_options`");
            $applied[] = "auto_add_poll_columns";
        }
        
        $pCols = $pdo->query("SHOW COLUMNS FROM posts LIKE 'quote_post_id'")->fetchAll();
        if (empty($pCols)) {
            $pdo->exec("ALTER TABLE posts 
                ADD COLUMN `quote_post_id` INT UNSIGNED DEFAULT NULL,
                ADD COLUMN `feeling` VARCHAR(80) DEFAULT NULL,
                ADD COLUMN `audio_url` VARCHAR(255) DEFAULT NULL,
                ADD COLUMN `audio_title` VARCHAR(120) DEFAULT NULL,
                ADD COLUMN `audio_artist` VARCHAR(120) DEFAULT NULL,
                ADD COLUMN `thread_parent_id` INT UNSIGNED DEFAULT NULL");
            $applied[] = "auto_add_post_ultimate_columns";
        }

        $commCols = $pdo->query("SHOW COLUMNS FROM post_comments LIKE 'media_url'")->fetchAll();
        if (empty($commCols)) {
            $pdo->exec("ALTER TABLE post_comments ADD COLUMN `media_url` VARCHAR(255) DEFAULT NULL AFTER `content`");
            $applied[] = "auto_add_comment_media";
        }

        $chatCols = $pdo->query("SHOW COLUMNS FROM chat_messages LIKE 'voice_url'")->fetchAll();
        if (empty($chatCols)) {
            $pdo->exec("ALTER TABLE chat_messages 
                ADD COLUMN `voice_url` VARCHAR(255) DEFAULT NULL,
                ADD COLUMN `voice_duration` INT UNSIGNED DEFAULT 0");
            $applied[] = "auto_add_chat_voice";
        }
    } catch (Exception $e) {}

    return ['count' => count($applied), 'details' => $applied];
}

// -------------------------------------------------------------
// AJAX API ENDPOINTS
// -------------------------------------------------------------
if ($action === 'test_db') {
    header('Content-Type: application/json');
    $host = $_POST['db_host'] ?? '127.0.0.1';
    $port = $_POST['db_port'] ?? '3306';
    $name = $_POST['db_name'] ?? '';
    $user = $_POST['db_user'] ?? '';
    $pass = $_POST['db_pass'] ?? '';

    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        echo json_encode(['success' => true, 'message' => 'Соединение с MySQL успешно установлено!']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Ошибка подключения: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'install_system') {
    header('Content-Type: application/json');
    $host = trim($_POST['db_host'] ?? '127.0.0.1');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminEmail = trim($_POST['admin_email'] ?? 'admin@vladinc.ru');
    $adminPass = $_POST['admin_pass'] ?? '';
    $techPass = $_POST['tech_pass'] ?? '';

    if (empty($name) || empty($user) || empty($adminPass) || empty($techPass)) {
        echo json_encode(['success' => false, 'error' => 'Заполните все обязательные поля']);
        exit;
    }

    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // 1. Run Schema
        if (file_exists(SCHEMA_FILE)) {
            $sql = file_get_contents(SCHEMA_FILE);
            execute_sql_script($pdo, $sql);
        }

        // 2. Run Auto-Migrations
        $migs = run_database_migrations($pdo);

        // 3. Create/Update Super Administrator
        $adminHash = password_hash($adminPass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$adminUser, $adminEmail]);
        $existingAdmin = $stmt->fetch();

        if ($existingAdmin) {
            $upd = $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', coins = 5000, xp = 1000, level = 10 WHERE id = ?");
            $upd->execute([$adminHash, $existingAdmin['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO users (username, email, password_hash, display_name, role, coins, xp, level, status_text) 
                VALUES (?, ?, ?, 'VladInc Creator', 'admin', 5000, 1000, 10, 'Создатель и основатель экосистемы VladInc')");
            $ins->execute([$adminUser, $adminEmail, $adminHash]);
        }

        // 4. Write core/config.php
        $configContent = "<?php\n"
            . "/**\n * VladInc Ecosystem - Configuration\n * Auto-generated by install.php\n */\n\n"
            . "if (!defined('VLADINC_INIT')) define('VLADINC_INIT', true);\n\n"
            . "error_reporting(E_ALL);\nini_set('display_errors', 1);\n\n"
            . "if (session_status() === PHP_SESSION_NONE) {\n"
            . "    session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/', 'domain' => '', 'secure' => isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on', 'httponly' => true, 'samesite' => 'Lax']);\n"
            . "    session_start();\n"
            . "}\n\n"
            . "define('DB_HOST', '" . addslashes($host) . "');\n"
            . "define('DB_PORT', '" . addslashes($port) . "');\n"
            . "define('DB_NAME', '" . addslashes($name) . "');\n"
            . "define('DB_USER', '" . addslashes($user) . "');\n"
            . "define('DB_PASS', '" . addslashes($pass) . "');\n"
            . "define('DB_CHARSET', 'utf8mb4');\n\n"
            . "define('APP_NAME', 'VladInc');\n"
            . "define('APP_TAGLINE', 'Единая экосистема сервисов и общения');\n"
            . "define('APP_DOMAIN', 'vladinc.ru');\n"
            . "define('APP_VERSION', '2.0.0');\n\n"
            . "\$protocol = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off' || (isset(\$_SERVER['SERVER_PORT']) && \$_SERVER['SERVER_PORT'] == 443)) ? 'https://' : 'http://';\n"
            . "\$hostName = \$_SERVER['HTTP_HOST'] ?? 'vladinc.ru';\n"
            . "\$dir = rtrim(dirname(\$_SERVER['SCRIPT_NAME'] ?? ''), '/\\\\');\n"
            . "define('BASE_URL', \$protocol . \$hostName . \$dir);\n\n"
            . "define('ROOT_PATH', dirname(__DIR__));\n"
            . "define('UPLOADS_PATH', ROOT_PATH . '/uploads');\n"
            . "define('CORE_PATH', ROOT_PATH . '/core');\n"
            . "define('MODULES_PATH', ROOT_PATH . '/modules');\n"
            . "define('TEMPLATES_PATH', ROOT_PATH . '/templates');\n\n"
            . "date_default_timezone_set('Europe/Moscow');\n";

        @file_put_contents(CONFIG_FILE, $configContent);

        set_tech_password($techPass);
        $_SESSION['vladinc_tech_auth'] = true;

        echo json_encode(['success' => true, 'message' => 'Экосистема VladInc v2.0 успешно установлена! Применено миграций: ' . $migs['count']]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// RUN MANUAL MIGRATIONS
if ($action === 'run_migrations') {
    header('Content-Type: application/json');
    if (!is_tech_authenticated()) {
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    if (file_exists(CONFIG_FILE)) {
        try {
            require_once CONFIG_FILE;
            $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $res = run_database_migrations($pdo);
            echo json_encode([
                'success' => true,
                'message' => $res['count'] > 0 
                    ? "Успешно применено новых миграций: {$res['count']} (" . implode(', ', $res['details']) . ")" 
                    : "База данных уже в актуальном состоянии, новых миграций нет."
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Ошибка миграции: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Конфигурация не найдена. Запустите установку.']);
    }
    exit;
}

// ZIP UPDATE DEPLOYER VIA BASE64 WITH AUTO-MIGRATIONS
if ($action === 'deploy_zip') {
    header('Content-Type: application/json');
    if (!is_tech_authenticated()) {
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация тех-администратора']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    $base64Data = $payload['base64'] ?? '';

    if (empty($base64Data)) {
        echo json_encode(['success' => false, 'error' => 'Архив не передан или пуст']);
        exit;
    }

    if (strpos($base64Data, ',') !== false) {
        $base64Data = explode(',', $base64Data)[1];
    }

    $binaryData = base64_decode($base64Data);
    if (!$binaryData || !class_exists('ZipArchive')) {
        echo json_encode(['success' => false, 'error' => 'Не удалось декодировать ZIP или ZipArchive не поддерживается']);
        exit;
    }

    $tempZipPath = BACKUP_DIR . '/tmp_update_' . time() . '.zip';
    if (@file_put_contents($tempZipPath, $binaryData) === false) {
        echo json_encode(['success' => false, 'error' => 'Не удалось сохранить временный zip архив на сервере']);
        exit;
    }

    $zip = new ZipArchive();
    $res = $zip->open($tempZipPath);
    if ($res !== true) {
        @unlink($tempZipPath);
        echo json_encode(['success' => false, 'error' => 'Некорректный ZIP архив']);
        exit;
    }

    $extractedCount = 0;
    $skippedCount = 0;
    $protectedList = ['mama', 'shibalingo'];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        $cleanEntry = ltrim($entryName, '/\\');
        $parts = explode('/', str_replace('\\', '/', $cleanEntry));
        $topDir = strtolower($parts[0] ?? '');

        if (in_array($topDir, $protectedList, true) || strpos($cleanEntry, '../') !== false) {
            $skippedCount++;
            continue;
        }

        $targetPath = INSTALL_ROOT . '/' . $cleanEntry;

        if (substr($entryName, -1) === '/') {
            if (!is_dir($targetPath)) @mkdir($targetPath, 0755, true);
        } else {
            $dir = dirname($targetPath);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $stream = $zip->getStream($entryName);
            if ($stream) {
                file_put_contents($targetPath, stream_get_contents($stream));
                fclose($stream);
                $extractedCount++;
            }
        }
    }

    $zip->close();
    @unlink($tempZipPath);

    // AUTO-MIGRATIONS EXECUTION AFTER ZIP EXTRACTION
    $migResultText = "База данных актуальна";
    if (file_exists(CONFIG_FILE)) {
        try {
            require_once CONFIG_FILE;
            if (defined('DB_NAME') && DB_NAME) {
                $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $migRes = run_database_migrations($pdo);
                if ($migRes['count'] > 0) {
                    $migResultText = "Автоматически применено новых миграций БД: " . $migRes['count'] . " (" . implode(', ', $migRes['details']) . ")";
                }
            }
        } catch (Exception $e) {
            $migResultText = "Ошибка выполнения миграций: " . $e->getMessage();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Обновление успешно распаковано! Обновлено файлов: {$extractedCount}. {$migResultText}. Защищено папок (mama/shibalingo): {$skippedCount}."
    ]);
    exit;
}

// BACKUPS ENGINE
if ($action === 'create_backup') {
    header('Content-Type: application/json');
    if (!is_tech_authenticated()) {
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }

    $backupType = $_POST['type'] ?? 'both';
    $timestamp = date('Y-m-d_H-i-s');
    $results = [];

    if ($backupType === 'sql' || $backupType === 'both') {
        if (file_exists(CONFIG_FILE)) {
            try {
                require_once CONFIG_FILE;
                $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $dump = "-- VladInc Ecosystem Database Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
                $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

                foreach ($tables as $table) {
                    $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
                    $dump .= "DROP TABLE IF EXISTS `{$table}`;\n" . $createStmt['Create Table'] . ";\n\n";
                    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $dump .= "INSERT INTO `{$table}` VALUES\n";
                        $values = [];
                        foreach ($rows as $row) {
                            $escaped = array_map(function($v) use ($pdo) {
                                return $v === null ? 'NULL' : $pdo->quote($v);
                            }, array_values($row));
                            $values[] = "(" . implode(', ', $escaped) . ")";
                        }
                        $dump .= implode(",\n", $values) . ";\n\n";
                    }
                }
                $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

                $sqlFile = BACKUP_DIR . "/db_backup_{$timestamp}.sql";
                file_put_contents($sqlFile, $dump);
                $results[] = "База данных: db_backup_{$timestamp}.sql (" . round(filesize($sqlFile) / 1024, 1) . " KB)";
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Ошибка бэкапа БД: ' . $e->getMessage()]);
                exit;
            }
        }
    }

    if ($backupType === 'files' || $backupType === 'both') {
        if (class_exists('ZipArchive')) {
            $zipFile = BACKUP_DIR . "/files_backup_{$timestamp}.zip";
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(INSTALL_ROOT, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                $skipped = ['mama', 'shibalingo', 'backups'];

                foreach ($files as $file) {
                    $relativePath = substr($file->getPathname(), strlen(INSTALL_ROOT) + 1);
                    $parts = explode('/', str_replace('\\', '/', $relativePath));
                    $top = strtolower($parts[0] ?? '');

                    if (in_array($top, $skipped, true)) continue;

                    if ($file->isDir()) {
                        $zip->addEmptyDir($relativePath);
                    } elseif ($file->isFile()) {
                        $zip->addFile($file->getPathname(), $relativePath);
                    }
                }
                $zip->close();
                $results[] = "Файлы: files_backup_{$timestamp}.zip (" . round(filesize($zipFile) / (1024 * 1024), 2) . " MB)";
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Резервная копия создана успешно!', 'details' => $results]);
    exit;
}

if ($action === 'download_backup') {
    if (!is_tech_authenticated()) die("Доступ запрещен");
    $file = basename($_GET['file'] ?? '');
    $filePath = BACKUP_DIR . '/' . $file;
    if (file_exists($filePath) && is_file($filePath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        readfile($filePath);
        exit;
    }
    die("Файл не найден");
}

if ($action === 'delete_backup') {
    header('Content-Type: application/json');
    if (!is_tech_authenticated()) exit;
    $file = basename($_POST['file'] ?? '');
    $filePath = BACKUP_DIR . '/' . $file;
    if (file_exists($filePath) && unlink($filePath)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Не удалось удалить файл']);
    }
    exit;
}

$envChecks = [
    'PHP Version (>= 7.4)' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO & PDO_MySQL' => extension_loaded('pdo') && extension_loaded('pdo_mysql'),
    'ZipArchive Extension' => class_exists('ZipArchive'),
    'cURL Extension' => extension_loaded('curl'),
    'mbstring Extension' => extension_loaded('mbstring'),
    'JSON Support' => function_exists('json_encode'),
    'Root Dir Writable' => is_writable(INSTALL_ROOT),
    'Uploads Dir Writable' => is_writable(INSTALL_ROOT . '/uploads') || @mkdir(INSTALL_ROOT . '/uploads', 0777, true),
    'Migrations Dir Present' => is_dir(MIGRATIONS_DIR)
];

$backupFiles = [];
if (is_dir(BACKUP_DIR)) {
    $scanned = scandir(BACKUP_DIR, SCANDIR_SORT_DESCENDING);
    foreach ($scanned as $f) {
        if ($f !== '.' && $f !== '..' && $f !== '.htaccess' && $f !== '.tech_auth') {
            $backupFiles[] = [
                'name' => $f,
                'size' => round(filesize(BACKUP_DIR . '/' . $f) / 1024, 1) . ' KB',
                'date' => date('d.m.Y H:i:s', filemtime(BACKUP_DIR . '/' . $f))
            ];
        }
    }
}

$isInstalled = file_exists(CONFIG_FILE) && file_exists(TECH_PASS_FILE);
$isAuth = is_tech_authenticated();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VladInc Deployment & Auto-Migrations Center | vladinc.ru</title>
    <style>
        :root {
            --bg-base: #0a0e17;
            --bg-card: #121826;
            --bg-input: #1a2235;
            --accent: #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.4);
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --border: #243048;
            --border-hover: #3b82f6;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: var(--bg-base); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
        .header { background: var(--bg-card); border-bottom: 1px solid var(--border); padding: 18px 32px; display: flex; justify-content: space-between; align-items: center; }
        .logo-wrap { display: flex; align-items: center; gap: 14px; }
        .logo-badge { background: linear-gradient(135deg, #3b82f6, #8b5cf6); width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 20px; box-shadow: 0 0 20px var(--accent-glow); }
        .logo-text h1 { font-size: 19px; font-weight: 700; }
        .logo-text span { font-size: 12px; color: var(--accent); font-weight: 600; text-transform: uppercase; }
        .domain-tag { background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); color: #60a5fa; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .container { max-width: 1000px; margin: 36px auto; padding: 0 20px; flex: 1; width: 100%; }
        .nav-tabs { display: flex; gap: 10px; border-bottom: 1px solid var(--border); margin-bottom: 28px; }
        .nav-tab { background: transparent; border: none; color: var(--text-muted); font-size: 15px; font-weight: 600; padding: 12px 20px; cursor: pointer; position: relative; border-radius: 8px 8px 0 0; transition: all 0.2s; }
        .nav-tab:hover { color: var(--text); background: rgba(255,255,255,0.03); }
        .nav-tab.active { color: var(--accent); }
        .nav-tab.active::after { content: ''; position: absolute; bottom: -1px; left: 0; right: 0; height: 3px; background: var(--accent); border-radius: 3px 3px 0 0; box-shadow: 0 0 10px var(--accent); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 28px; margin-bottom: 24px; }
        .card-title { font-size: 18px; font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
        .card-desc { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.5; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; }
        .form-control { width: 100%; background: var(--bg-input); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; color: var(--text); font-size: 14px; }
        .form-control:focus { outline: none; border-color: var(--accent); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; font-weight: 600; padding: 12px 24px; border-radius: 10px; border: none; cursor: pointer; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; box-shadow: 0 4px 15px var(--accent-glow); }
        .btn-secondary { background: var(--bg-input); color: var(--text); border: 1px solid var(--border); }
        .btn-danger { background: var(--accent-red); color: #fff; }
        .check-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-radius: 8px; background: var(--bg-input); margin-bottom: 8px; font-size: 14px; }
        .badge { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .badge-pass { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-fail { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .dropzone { border: 2px dashed var(--border); border-radius: 16px; padding: 48px 24px; text-align: center; cursor: pointer; background: rgba(26, 34, 53, 0.5); }
        .dropzone:hover, .dropzone.dragover { border-color: var(--accent); background: rgba(59, 130, 246, 0.05); }
        .dropzone-icon { font-size: 48px; margin-bottom: 12px; }
        .progress-bar-wrap { width: 100%; height: 12px; background: var(--bg-input); border-radius: 6px; overflow: hidden; margin-top: 18px; display: none; }
        .progress-bar { width: 0%; height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.2s; }
        .alert { padding: 16px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; display: none; line-height: 1.5; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; }
        .alert-info { background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); color: #60a5fa; }
        .notice-box { background: rgba(245, 158, 11, 0.1); border-left: 4px solid #f59e0b; padding: 14px 18px; border-radius: 0 10px 10px 0; margin-bottom: 20px; font-size: 13px; color: #fbbf24; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 14px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; font-weight: 600; }
        .footer { text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px; border-top: 1px solid var(--border); }
    </style>
</head>
<body>

    <header class="header">
        <div class="logo-wrap">
            <div class="logo-badge">V</div>
            <div class="logo-text">
                <h1>VladInc Deployment & Auto-Migrations</h1>
                <span>Автономный мастер управления</span>
            </div>
        </div>
        <div style="display: flex; gap: 12px; align-items: center;">
            <div class="domain-tag">Домен: vladinc.ru</div>
            <?php if ($isAuth): ?>
                <a href="?action=tech_logout" class="btn btn-secondary" style="padding: 6px 14px; font-size: 12px;">Выйти</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">
        
        <div class="notice-box">
            🛡️ <strong>Изоляция и авто-миграции:</strong> При загрузке ZIP архива обновления скрипт автоматически распаковывает файлы, <strong>применяет новые миграции БД из папки <code>migrations/</code></strong> и строго пропускает папки <code>mama</code> и <code>shibalingo</code>.
        </div>

        <div id="global-alert" class="alert"></div>

        <?php if (!$isAuth && file_exists(TECH_PASS_FILE)): ?>
            <div class="card" style="max-width: 480px; margin: 40px auto;">
                <div class="card-title">🔐 Авторизация тех-администратора</div>
                <div class="card-desc">Введите мастер-пароль тех-администратора для доступа.</div>
                <form id="tech-login-form">
                    <div class="form-group">
                        <label>Мастер-пароль</label>
                        <input type="password" id="tech_login_password" class="form-control" required placeholder="••••••••">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Войти в Tech Center</button>
                </form>
            </div>
        <?php else: ?>

            <div class="nav-tabs">
                <button class="nav-tab active" onclick="switchTab('wizard')">🚀 Мастер настройки</button>
                <button class="nav-tab" onclick="switchTab('updater')">📦 Обновление через ZIP + Миграции</button>
                <button class="nav-tab" onclick="switchTab('backups')">💾 Бэкапы и дампы</button>
                <button class="nav-tab" onclick="switchTab('diag')">⚡ Диагностика сервера</button>
                <a href="feed" target="_blank" class="nav-tab" style="margin-left: auto; text-decoration: none;">🌐 Перейти на сайт</a>
            </div>

            <!-- TAB 1: SETUP WIZARD -->
            <div id="tab-wizard" class="tab-content active">
                <div class="card">
                    <div class="card-title">⚙️ Первичная конфигурация экосистемы VladInc</div>
                    <div class="card-desc">Автоматическое создание базы данных, таблиц, накатка миграций и создание супер-администратора.</div>

                    <form id="install-form">
                        <h4 style="margin-bottom: 16px; color: #60a5fa; font-size: 15px;">1. Параметры базы данных MySQL</h4>
                        <div class="grid-2">
                            <div class="form-group">
                                <label>MySQL Host</label>
                                <input type="text" name="db_host" class="form-control" value="127.0.0.1" required>
                            </div>
                            <div class="form-group">
                                <label>MySQL Port</label>
                                <input type="text" name="db_port" class="form-control" value="3306" required>
                            </div>
                            <div class="form-group">
                                <label>Имя базы данных (Database Name)</label>
                                <input type="text" name="db_name" class="form-control" placeholder="vladinc_db" required>
                            </div>
                            <div class="form-group">
                                <label>Пользователь БД (DB User)</label>
                                <input type="text" name="db_user" class="form-control" placeholder="root / u12345_user" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Пароль пользователя БД (DB Password)</label>
                            <input type="password" name="db_pass" class="form-control" placeholder="Оставьте пустым или введите пароль">
                        </div>
                        <div style="margin-bottom: 24px; display: flex; gap: 10px;">
                            <button type="button" class="btn btn-secondary" onclick="testDB()">🔍 Проверить подключение к MySQL</button>
                            <button type="button" class="btn btn-secondary" onclick="runManualMigrations()">⚡ Применить миграции БД вручную</button>
                        </div>

                        <h4 style="margin-bottom: 16px; color: #60a5fa; font-size: 15px; border-top: 1px solid var(--border); padding-top: 20px;">2. Главный аккаунт супер-администратора (VladID)</h4>
                        <div class="grid-2">
                            <div class="form-group">
                                <label>Логин администратора</label>
                                <input type="text" name="admin_user" class="form-control" value="admin" required>
                            </div>
                            <div class="form-group">
                                <label>Email администратора</label>
                                <input type="email" name="admin_email" class="form-control" value="admin@vladinc.ru" required>
                            </div>
                            <div class="form-group">
                                <label>Пароль администратора VladID</label>
                                <input type="password" name="admin_pass" class="form-control" required placeholder="••••••••">
                            </div>
                            <div class="form-group">
                                <label>Мастер-пароль тех-админа (для install.php)</label>
                                <input type="password" name="tech_pass" class="form-control" required placeholder="••••••••">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 10px;">💾 Установить и активировать VladInc</button>
                    </form>
                </div>
            </div>

            <!-- TAB 2: ZIP UPDATER VIA BASE64 WITH AUTO-MIGRATIONS -->
            <div id="tab-updater" class="tab-content">
                <div class="card">
                    <div class="card-title">📦 Установка обновлений через ZIP + Авто-миграции</div>
                    <div class="card-desc">
                        Перетащите сюда или выберите ZIP-архив с новой версией. Браузер на JavaScript конвертирует его в Base64 и передаст на сервер, где PHP аккуратно распакует файлы в корень сайта, <strong>автоматически накатит все новые SQL-миграции базы данных</strong> и изолирует папки <code>mama</code> и <code>shibalingo</code>.
                    </div>

                    <div class="dropzone" id="dropzone" onclick="document.getElementById('zip-file-input').click()">
                        <div class="dropzone-icon">📁</div>
                        <div class="dropzone-text" id="dropzone-label">Нажмите или перетащите файл обновления (.zip) сюда</div>
                        <div class="dropzone-sub">Архивы до 64MB &bull; Авто-миграции включаются автоматически</div>
                        <input type="file" id="zip-file-input" accept=".zip" style="display: none;" onchange="handleFileSelected(this.files[0])">
                    </div>

                    <div class="progress-bar-wrap" id="deploy-progress-wrap">
                        <div class="progress-bar" id="deploy-progress-bar"></div>
                    </div>
                    <div id="deploy-status" style="margin-top: 14px; font-size: 14px; color: var(--text-muted); text-align: center;"></div>

                    <div style="margin-top: 24px; text-align: right;">
                        <button type="button" id="deploy-btn" class="btn btn-primary" onclick="startDeployment()" disabled>🚀 Запустить установку обновления и авто-миграции</button>
                    </div>
                </div>
            </div>

            <!-- TAB 3: BACKUPS -->
            <div id="tab-backups" class="tab-content">
                <div class="card">
                    <div class="card-title">💾 Резервные копии (Бэкапы)</div>
                    <div class="card-desc">Создание полных дампов базы данных MySQL и архива файлов экосистемы прямо средствами PHP.</div>

                    <div style="display: flex; gap: 12px; margin-bottom: 24px;">
                        <button class="btn btn-primary" onclick="createBackup('both')">⚡ Создать полный бэкап (БД + Файлы)</button>
                        <button class="btn btn-secondary" onclick="createBackup('sql')">🗄️ Только дамп БД (.sql)</button>
                        <button class="btn btn-secondary" onclick="createBackup('files')">📁 Только файлы (.zip)</button>
                    </div>

                    <h4 style="font-size: 15px; color: #60a5fa; margin-bottom: 12px;">Существующие резервные копии:</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Имя файла</th>
                                <th>Размер</th>
                                <th>Дата создания</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($backupFiles)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">Бэкапов пока нет</td></tr>
                            <?php else: ?>
                                <?php foreach ($backupFiles as $b): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
                                        <td><?= $b['size'] ?></td>
                                        <td><?= $b['date'] ?></td>
                                        <td>
                                            <a href="?action=download_backup&file=<?= urlencode($b['name']) ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;">Скачать</a>
                                            <button class="btn btn-danger" style="padding: 4px 10px; font-size: 12px;" onclick="deleteBackup('<?= htmlspecialchars($b['name']) ?>')">Удалить</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 4: DIAGNOSTICS -->
            <div id="tab-diag" class="tab-content">
                <div class="card">
                    <div class="card-title">⚡ Системная диагностика сервера</div>
                    <div class="card-desc">Проверка окружения хостинга для корректной работы VladInc.</div>

                    <?php foreach ($envChecks as $title => $passed): ?>
                        <div class="check-item">
                            <span><?= htmlspecialchars($title) ?></span>
                            <span class="badge <?= $passed ? 'badge-pass' : 'badge-fail' ?>">
                                <?= $passed ? '✓ Доступно' : '✗ Требуется внимание' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php endif; ?>

    </div>

    <footer class="footer">
        VladInc Deployment Center &copy; <?= date('Y') ?> | vladinc.ru | Авто-миграции активны
    </footer>

    <script>
        function showAlert(type, msg) {
            const el = document.getElementById('global-alert');
            el.className = 'alert alert-' + type;
            el.innerHTML = msg;
            el.style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.nav-tab').forEach(el => el.classList.remove('active'));
            const target = document.getElementById('tab-' + tabId);
            if (target) target.classList.add('active');
            event.target.classList.add('active');
        }

        const loginForm = document.getElementById('tech-login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const pass = document.getElementById('tech_login_password').value;
                const fd = new FormData();
                fd.append('action', 'tech_login');
                fd.append('tech_password', pass);
                const res = await fetch('install.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) location.reload();
                else showAlert('error', data.error);
            });
        }

        async function testDB() {
            const form = document.getElementById('install-form');
            const fd = new FormData(form);
            fd.append('action', 'test_db');
            showAlert('info', 'Проверка соединения с базой данных MySQL...');
            try {
                const res = await fetch('install.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) showAlert('success', '✓ ' + data.message);
                else showAlert('error', '✗ ' + data.error);
            } catch (err) {
                showAlert('error', 'Ошибка сети: ' + err.message);
            }
        }

        async function runManualMigrations() {
            showAlert('info', 'Проверка и применение новых миграций...');
            const fd = new FormData();
            fd.append('action', 'run_migrations');
            try {
                const res = await fetch('install.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) showAlert('success', '🎉 ' + data.message);
                else showAlert('error', 'Ошибка миграций: ' + data.error);
            } catch (err) {
                showAlert('error', 'Ошибка: ' + err.message);
            }
        }

        const installForm = document.getElementById('install-form');
        if (installForm) {
            installForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(installForm);
                fd.append('action', 'install_system');
                showAlert('info', 'Инициализация таблиц базы данных и создание администратора...');
                try {
                    const res = await fetch('install.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        showAlert('success', '🎉 ' + data.message + ' <a href="feed" style="color:#fff; text-decoration:underline; font-weight:700;">Перейти в ленту VladInc</a>');
                    } else {
                        showAlert('error', 'Ошибка установки: ' + data.error);
                    }
                } catch (err) {
                    showAlert('error', 'Сетевая ошибка: ' + err.message);
                }
            });
        }

        let selectedZipFile = null;
        function handleFileSelected(file) {
            if (!file) return;
            selectedZipFile = file;
            document.getElementById('dropzone-label').innerHTML = '✅ Выбран архив: <strong>' + file.name + '</strong> (' + (file.size / (1024 * 1024)).toFixed(2) + ' MB)';
            document.getElementById('deploy-btn').removeAttribute('disabled');
        }

        const dropzone = document.getElementById('dropzone');
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); }, false);
            });
            ['dragleave', 'drop'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); }, false);
            });
            dropzone.addEventListener('drop', (e) => {
                if (e.dataTransfer.files.length) handleFileSelected(e.dataTransfer.files[0]);
            });
        }

        async function startDeployment() {
            if (!selectedZipFile) return;
            const progressWrap = document.getElementById('deploy-progress-wrap');
            const progressBar = document.getElementById('deploy-progress-bar');
            const statusText = document.getElementById('deploy-status');
            const deployBtn = document.getElementById('deploy-btn');

            deployBtn.disabled = true;
            progressWrap.style.display = 'block';
            progressBar.style.width = '20%';
            statusText.innerText = 'Конвертация ZIP-файла в Base64 через JavaScript...';

            const reader = new FileReader();
            reader.onload = async function() {
                progressBar.style.width = '55%';
                statusText.innerText = 'Передача архива на сервер, распаковка и применение новых миграций БД...';

                try {
                    const res = await fetch('install.php?action=deploy_zip', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            filename: selectedZipFile.name,
                            base64: reader.result
                        })
                    });
                    progressBar.style.width = '95%';
                    const data = await res.json();
                    progressBar.style.width = '100%';

                    if (data.success) {
                        statusText.innerText = 'Обновление и миграции успешно завершены!';
                        showAlert('success', '🎉 ' + data.message);
                    } else {
                        statusText.innerText = 'Ошибка!';
                        showAlert('error', 'Ошибка обновления: ' + data.error);
                    }
                } catch (err) {
                    showAlert('error', 'Сетевой сбой: ' + err.message);
                } finally {
                    deployBtn.disabled = false;
                }
            };
            reader.readAsDataURL(selectedZipFile);
        }

        async function createBackup(type) {
            showAlert('info', 'Создание резервной копии... Пожалуйста, подождите.');
            const fd = new FormData();
            fd.append('action', 'create_backup');
            fd.append('type', type);
            try {
                const res = await fetch('install.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    showAlert('success', '✓ ' + data.message + '<br>' + (data.details ? data.details.join('<br>') : ''));
                    setTimeout(() => location.reload(), 2000);
                } else showAlert('error', data.error);
            } catch (err) {
                showAlert('error', 'Ошибка бэкапа: ' + err.message);
            }
        }

        async function deleteBackup(filename) {
            if (!confirm('Удалить бэкап ' + filename + '?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_backup');
            fd.append('file', filename);
            const res = await fetch('install.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) location.reload();
            else showAlert('error', data.error);
        }
    </script>
</body>
</html>
