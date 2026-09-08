<?php
/**
 * VladAero — Мастер установки и обновления
 * ZIP-загрузка → распаковка → проверка → БД → миграция → админ
 */

@session_start();
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

// Try raising upload limits (works only on some configs)
@ini_set('max_file_uploads', '20');

// Allow larger uploads via .htaccess fallback info
$uploadLimit = @ini_get('upload_max_filesize');
$postLimit = @ini_get('post_max_size');

define('PROJECT_ROOT', __DIR__);
define('VLD_VERSION', '1.0.0');

// Simple JSON response helper
function jr($data) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Simple HTML escape helper
function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// Subfolder-aware URL helper for install.php
function getSiteUrl(string $path = ''): string {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') ? '' : rtrim($scriptDir, '/');
    $p = '/' . ltrim($path, '/');
    if ($path === '' || $path === '/') {
        return $base !== '' ? $base : '/';
    }
    return $base . $p;
}

// Tech Admin Auth helpers
function isTechAdmin(): bool {
    return !empty($_SESSION['vld_tech_admin']);
}

function getInstalledConfig(): ?array {
    $file = PROJECT_ROOT . '/config/installed.php';
    if (!file_exists($file)) return null;
    $cfg = require $file;
    return is_array($cfg) ? $cfg : null;
}

function getPdoConnection(): ?PDO {
    $cfg = getInstalledConfig();
    if (!$cfg || empty($cfg['db'])) return null;
    $db = $cfg['db'];
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$charset}";
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// ─── Detect post_max_size exceeded (POST data lost) ──────────
// Only check when Content-Type is form data (not JSON — $_POST is always empty for JSON)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isFormRequest = str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'x-www-form-urlencoded');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isFormRequest && empty($_POST) && empty($_FILES)) {
    jr([
        'success' => false,
        'message' => 'Сервер не принял данные. Лимиты PHP: upload_max_filesize=' . $uploadLimit . ', post_max_size=' . $postLimit . '. Проверьте php.ini или используйте ZIP-файл на сервере (см. ниже).'
    ]);
}

// ─── Validate a ZIP file as valid VladAero archive ───────────
function validateVladAeroZip(string $zipPath): array {
    if (!file_exists($zipPath) || filesize($zipPath) < 100) {
        return ['valid' => false, 'reason' => 'Файл не найден или слишком мал'];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['valid' => false, 'reason' => 'Не удалось открыть ZIP'];
    }
    // Look for VladAero markers: config/app.php, core/Controller.php, index.php
    $found = ['index' => false, 'config' => false, 'core' => false];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        // Strip root dir if present
        $parts = array_filter(explode('/', $name));
        $rel = implode('/', array_slice($parts, 1));
        if ($rel === 'index.php' || preg_match('#/index\.php$#', $name)) $found['index'] = true;
        if (str_contains($name, 'config/app.php')) $found['config'] = true;
        if (str_contains($name, 'core/Controller.php')) $found['core'] = true;
    }
    $zip->close();
    $allFound = $found['index'] && $found['config'] && $found['core'];
    return [
        'valid' => $allFound,
        'reason' => $allFound ? '' : 'Не найдены ключевые файлы VladAero',
        'markers' => $found,
    ];
}

// ─── Extract a local ZIP file to project root ────────────────
function extractZipToProject(string $zipPath): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['success' => false, 'message' => 'Не удалось открыть ZIP (код: ' . $zip->open($zipPath) . ')'];
    }

    // Detect common root dir: only strip if ALL entries share the same top-level folder
    $rootDir = '';
    $topLevelDirs = [];
    $hasTopLevelFiles = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === '' || $name === '.' || str_starts_with($name, '__MACOSX')) continue;
        $parts = array_filter(explode('/', $name));
        $parts = array_values($parts);
        if (count($parts) === 1 && !str_ends_with($name, '/')) {
            // Top-level file (not a directory) — no common root
            $hasTopLevelFiles = true;
        } elseif (count($parts) >= 1) {
            $topLevelDirs[$parts[0]] = true;
        }
    }
    // Only strip root dir if there's exactly ONE top-level folder and NO top-level files
    if (!$hasTopLevelFiles && count($topLevelDirs) === 1) {
        $rootDir = array_key_first($topLevelDirs);
    }

    // Extract to temp
    $extractTo = sys_get_temp_dir() . '/vld_extract_' . bin2hex(random_bytes(4));
    @mkdir($extractTo, 0755, true);
    $zip->extractTo($extractTo);
    $zip->close();
    $src = $rootDir ? ($extractTo . '/' . $rootDir) : $extractTo;

    // Copy files to project root
    $copied = 0;
    $skip = ['upload.zip', 'install.php'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($src) + 1);
        if (in_array(basename($rel), $skip)) continue;
        if (str_starts_with($rel, '__MACOSX')) continue;
        $dest = PROJECT_ROOT . '/' . $rel;
        if (is_file($f->getPathname())) {
            @mkdir(dirname($dest), 0755, true);
            @copy($f->getPathname(), $dest);
            $copied++;
        }
    }
    @delTree($extractTo);
    return ['success' => true, 'message' => "Распаковано {$copied} файл(ов)", 'file_count' => $copied];
}

// ─── Handle AJAX ───────────────────────────────────────────────
// Support form data ($_POST), GET action, raw binary, and JSON (php://input)
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$jsonInput = null;
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $jsonInput = json_decode($raw, true);
    if (is_array($jsonInput) && isset($jsonInput['action'])) {
        $action = $jsonInput['action'];
    }
}
if ($action) {
    switch ($action) {

        // ── Upload Raw Binary Stream ────────────────────────────
        case 'upload_raw':
            try {
                if (!extension_loaded('zip')) {
                    jr(['success' => false, 'message' => 'PHP не поддерживает ZipArchive']);
                }
                $zipData = file_get_contents('php://input');
                if (empty($zipData) || strlen($zipData) < 100) {
                    jr(['success' => false, 'message' => 'Данные архива не получены или файл пустой']);
                }
                $tmpFile = tempnam(sys_get_temp_dir(), 'vld_raw_');
                file_put_contents($tmpFile, $zipData);

                $validation = validateVladAeroZip($tmpFile);
                if (!$validation['valid']) {
                    @unlink($tmpFile);
                    jr(['success' => false, 'message' => 'ZIP не распознан как VladAero: ' . $validation['reason']]);
                }

                $result = extractZipToProject($tmpFile);
                @unlink($tmpFile);
                if ($result['success']) {
                    $result['message'] .= ' в ' . PROJECT_ROOT;
                }
                jr($result);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            break;

        // ── Upload ZIP ──────────────────────────────────────────
        case 'upload_zip':
            try {
                if (!extension_loaded('zip')) {
                    jr(['success' => false, 'message' => 'PHP не поддерживает ZipArchive']);
                }

                if (empty($_FILES['zip_file']['tmp_name']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
                    $errCode = $_FILES['zip_file']['error'] ?? -1;
                    $hints = [
                        UPLOAD_ERR_INI_SIZE   => 'Файл превышает upload_max_filesize (текущий лимит: ' . ini_get('upload_max_filesize') . ', см. php.ini)',
                        UPLOAD_ERR_FORM_SIZE  => 'Файл превышает MAX_FILE_SIZE в форме',
                        UPLOAD_ERR_PARTIAL    => 'Файл загружен частично',
                        UPLOAD_ERR_NO_FILE    => 'Файл не был загружен',
                        UPLOAD_ERR_NO_TMP_DIR => 'Не найдена временная папка',
                        UPLOAD_ERR_CANT_WRITE => 'Ошибка записи на диск',
                    ];
                    $msg = $hints[$errCode] ?? ('Файл не загружен (ошибка №' . $errCode . ')');
                    $msg .= '💡 Если загрузка не работает — положите ZIP-файл VladAero в корень проекта (рядом с install.php) и используйте автодетект.';
                    jr(['success' => false, 'message' => $msg]);
                }

                $file = $_FILES['zip_file'];
                if ($file['size'] > 100 * 1024 * 1024) {
                    jr(['success' => false, 'message' => 'Файл слишком большой (макс. 100 МБ)']);
                }

                // Save uploaded file to temp
                $tmpFile = tempnam(sys_get_temp_dir(), 'vld_');
                if (!move_uploaded_file($file['tmp_name'], $tmpFile)) {
                    jr(['success' => false, 'message' => 'Ошибка сохранения во временную папку']);
                }

                // Validate it's a VladAero ZIP
                $validation = validateVladAeroZip($tmpFile);
                if (!$validation['valid']) {
                    @unlink($tmpFile);
                    jr(['success' => false, 'message' => 'ZIP не распознан как VladAero: ' . $validation['reason']]);
                }

                // Extract to project root
                $result = extractZipToProject($tmpFile);
                @unlink($tmpFile);
                if ($result['success']) {
                    $result['message'] .= ' в ' . PROJECT_ROOT;
                }
                jr($result);

            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            exit;

        // ── Detect local ZIP files in project root ──────────────
        case 'detect_local_zips':
            $found = [];
            $glob = glob(PROJECT_ROOT . '/*.zip');
            if ($glob) {
                foreach ($glob as $zipPath) {
                    $name = basename($zipPath);
                    $size = filesize($zipPath);
                    $sizeStr = $size > 1024 * 1024
                        ? round($size / 1024 / 1024, 1) . ' МБ'
                        : round($size / 1024) . ' КБ';
                    $validation = validateVladAeroZip($zipPath);
                    $found[] = [
                        'name' => $name,
                        'path' => $zipPath,
                        'size' => $sizeStr,
                        'valid' => $validation['valid'],
                        'reason' => $validation['reason'],
                    ];
                }
            }
            jr(['success' => true, 'zips' => $found]);
            exit;

        // ── Use a local ZIP file (already on server) ────────────
        case 'use_local_zip':
            try {
                if (!extension_loaded('zip')) {
                    jr(['success' => false, 'message' => 'PHP не поддерживает ZipArchive']);
                }
                $zipPath = $_POST['zip_path'] ?? '';
                if (!$zipPath || !file_exists($zipPath)) {
                    jr(['success' => false, 'message' => 'ZIP-файл не найден: ' . $zipPath]);
                }
                // Security: must be inside project root and end with .zip
                $realPath = realpath($zipPath);
                $realRoot = realpath(PROJECT_ROOT);
                if (!$realPath || !str_starts_with($realPath, $realRoot) || strtolower(substr($realPath, -4)) !== '.zip') {
                    jr(['success' => false, 'message' => 'Недопустимый путь к ZIP']);
                }
                // Validate VladAero structure
                $validation = validateVladAeroZip($realPath);
                if (!$validation['valid']) {
                    jr(['success' => false, 'message' => 'ZIP не распознан как VladAero: ' . $validation['reason']]);
                }
                // Extract
                $result = extractZipToProject($realPath);
                jr($result);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            exit;

        // ── Check Requirements ──────────────────────────────────
        case 'check_requirements':
            jr([
                'php_version' => version_compare(PHP_VERSION, '8.0.0', '>='),
                'pdo' => extension_loaded('pdo'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'mbstring' => extension_loaded('mbstring'),
                'json' => extension_loaded('json'),
                'gd' => extension_loaded('gd') || extension_loaded('imagick'),
                'fileinfo' => extension_loaded('fileinfo'),
                'curl' => extension_loaded('curl'),
                'zip' => extension_loaded('zip'),
                'writable_config' => is_writable(PROJECT_ROOT . '/config'),
                'writable_storage' => is_writable(PROJECT_ROOT . '/storage'),
                'writable_uploads' => is_writable(PROJECT_ROOT . '/public/uploads'),
            ]);
            break; // jr() calls exit, but break is needed as a safety net

        // ── Test Database ───────────────────────────────────────
        case 'test_database':
            try {
                $host = $_POST['db_host'] ?? 'localhost';
                $name = $_POST['db_name'] ?? '';
                $user = $_POST['db_user'] ?? '';
                $pass = $_POST['db_pass'] ?? '';
                $dsn = "mysql:host={$host};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$name}`");
                jr(['success' => true, 'message' => 'Подключение успешно!']);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            break;

        // ── Install (create tables + admin) ─────────────────────
        case 'install':
            try {
                $data = $jsonInput ?? json_decode(file_get_contents('php://input'), true);
                $dbHost = $data['db_host'] ?? 'localhost';
                $dbName = $data['db_name'] ?? '';
                $dbUser = $data['db_user'] ?? '';
                $dbPass = $data['db_pass'] ?? '';
                $dbPrefix = $data['db_prefix'] ?? 'vld_';
                $adminUser = $data['admin_user'] ?? 'admin';
                $adminEmail = $data['admin_email'] ?? '';
                $adminPass = $data['admin_pass'] ?? '';
                $adminName = $data['admin_name'] ?? 'Администратор';
                $siteName = $data['site_name'] ?? 'VladAero';
                $baseUrl = $data['base_url'] ?? '';
                $secretKey = bin2hex(random_bytes(32));

                $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $schemaFile = PROJECT_ROOT . '/database/schema.sql';
                if (!file_exists($schemaFile)) {
                    jr(['success' => false, 'message' => 'Файл schema.sql не найден: ' . $schemaFile]);
                }
                $schema = file_get_contents($schemaFile);
                if ($schema === false || strlen($schema) < 100) {
                    jr(['success' => false, 'message' => 'Не удалось прочитать schema.sql (размер: ' . strlen($schema ?: '') . ' байт)']);
                }
                $schema = str_replace('{prefix}', $dbPrefix, $schema);

                // Strip ALL SQL comments: full-line (-- ...) and inline (-- ... at end of line)
                // This prevents inline comments containing special chars from breaking the SQL
                $schema = preg_replace('/--.*$/m', '', $schema);

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $statements = array_filter(array_map('trim', explode(';', $schema)));
                $schemaErrors = [];
                $executed = 0;
                foreach ($statements as $stmt) {
                    if (!empty($stmt) && !preg_match('/^SET\b/i', $stmt)) {
                        try {
                            $pdo->exec($stmt);
                            $executed++;
                        } catch (Throwable $e) {
                            // Ignore "already exists" / "duplicate column" errors for idempotency
                            $errMsg = $e->getMessage();
                            if (stripos($errMsg, 'already exists') === false
                                && stripos($errMsg, 'Duplicate column') === false
                                && stripos($errMsg, 'Duplicate key') === false) {
                                $schemaErrors[] = mb_substr($errMsg, 0, 200);
                            }
                        }
                    }
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                // Verify critical tables exist; if not — create inline as fallback
                $checkUsers = $pdo->query("SHOW TABLES LIKE '{$dbPrefix}users'");
                if ($checkUsers->rowCount() === 0) {
                    // Fallback: create users table inline
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbPrefix}users` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `username` VARCHAR(50) NOT NULL UNIQUE,
                        `email` VARCHAR(255),
                        `password_hash` VARCHAR(255),
                        `display_name` VARCHAR(100) NOT NULL,
                        `avatar` VARCHAR(500) DEFAULT NULL,
                        `bio` TEXT,
                        `role` ENUM('user','spotter','editor','moderator','admin') DEFAULT 'user',
                        `rank` VARCHAR(50) DEFAULT 'Курсант',
                        `reputation` INT UNSIGNED DEFAULT 0,
                        `telegram_id` BIGINT,
                        `telegram_username` VARCHAR(100),
                        `telegram_linked` TINYINT(1) DEFAULT 0,
                        `tfa_enabled` TINYINT(1) DEFAULT 0,
                        `tfa_secret` VARCHAR(255),
                        `language` VARCHAR(5) DEFAULT 'ru',
                        `theme` ENUM('light','dark','system') DEFAULT 'system',
                        `sound_enabled` TINYINT(1) DEFAULT 1,
                        `email_notifications` TINYINT(1) DEFAULT 0,
                        `privacy_logbook` TINYINT(1) DEFAULT 1,
                        `privacy_stats` TINYINT(1) DEFAULT 1,
                        `privacy_albums` TINYINT(1) DEFAULT 1,
                        `status` ENUM('active','banned','deleted') DEFAULT 'active',
                        `email_verified_at` TIMESTAMP NULL,
                        `last_login_at` TIMESTAMP NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX `idx_role` (`role`),
                        INDEX `idx_tg` (`telegram_id`),
                        INDEX `idx_status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                $checkSettings = $pdo->query("SHOW TABLES LIKE '{$dbPrefix}settings'");
                if ($checkSettings->rowCount() === 0) {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbPrefix}settings` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
                        `setting_value` TEXT,
                        `setting_group` VARCHAR(50) DEFAULT 'general',
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // Seed settings if empty
                $settingsCount = $pdo->query("SELECT COUNT(*) FROM `{$dbPrefix}settings`")->fetchColumn();
                if ($settingsCount == 0) {
                    $pdo->exec("INSERT INTO `{$dbPrefix}settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
                        ('site_name', 'VladAero', 'general'),
                        ('site_tagline', 'Авиационный портал', 'general'),
                        ('default_theme', 'dark', 'appearance'),
                        ('maintenance_mode', '0', 'system'),
                        ('installed', '1', 'system')");
                }

                // Final verification
                $criticalTables = ['users', 'settings'];
                foreach ($criticalTables as $tbl) {
                    $check = $pdo->query("SHOW TABLES LIKE '{$dbPrefix}{$tbl}'");
                    if ($check->rowCount() === 0) {
                        $errDetail = !empty($schemaErrors)
                            ? ' Ошибки SQL: ' . implode('; ', array_slice($schemaErrors, 0, 5))
                            : ' (выполнено ' . $executed . ' запросов из ' . count($statements) . ', schema.sql: ' . strlen($schema) . ' байт)';
                        jr(['success' => false, 'message' => "Таблица {$dbPrefix}{$tbl} не создана.{$errDetail}"]);
                    }
                }

                $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO `{$dbPrefix}users` (username, email, password_hash, display_name, role, rank, status, email_verified_at) VALUES (?, ?, ?, ?, 'admin', 'КВС', 'active', NOW())")
                    ->execute([$adminUser, $adminEmail, $passwordHash, $adminName]);

                $pdo->prepare("UPDATE `{$dbPrefix}settings` SET setting_value = ? WHERE setting_key = 'site_name'")->execute([$siteName]);

                $config = [
                    'db' => ['host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass, 'charset' => 'utf8mb4', 'prefix' => $dbPrefix],
                    'secret_key' => $secretKey, 'base_url' => $baseUrl, 'maintenance' => false,
                    'ai' => ['enabled' => true, 'provider' => 'openai_compatible', 'base_url' => '', 'api_key' => '', 'model_id' => '', 'temperature' => 0.7, 'max_tokens' => 4096, 'streaming' => true],
                    'firecrawl' => ['api_key' => '', 'base_url' => 'https://api.firecrawl.dev/v1'],
                    'telegram' => ['bot_token' => '', 'bot_username' => '', 'webhook_url' => ''],
                ];
                file_put_contents(PROJECT_ROOT . '/config/installed.php', "<?php\nreturn " . var_export($config, true) . ";\n");
                file_put_contents(PROJECT_ROOT . '/config/.htaccess', "Deny from all\n");

                jr(['success' => true, 'message' => 'Установка завершена!']);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            break;

        // ── Update (migrate new tables) ─────────────────────────
        case 'update':
            try {
                $data = $jsonInput ?? json_decode(file_get_contents('php://input'), true);
                $dbPrefix = $data['db_prefix'] ?? 'vld_';
                $installedFile = PROJECT_ROOT . '/config/installed.php';
                if (!file_exists($installedFile)) {
                    jr(['success' => false, 'message' => 'Конфиг не найден']);
                }
                $cfg = require $installedFile;
                $dsn = "mysql:host={$cfg['db']['host']};dbname={$cfg['db']['name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $schema = file_get_contents(PROJECT_ROOT . '/database/schema.sql');
                $schema = str_replace('{prefix}', $dbPrefix, $schema);
                $schema = preg_replace('/--.*$/m', '', $schema);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $statements = array_filter(array_map('trim', explode(';', $schema)));
                $created = 0;
                foreach ($statements as $stmt) {
                    if (!empty($stmt) && !preg_match('/^SET\b/i', $stmt)) {
                        $stmt = str_replace('{prefix}', $dbPrefix, $stmt);
                        try { $pdo->exec($stmt); if (stripos($stmt, 'CREATE TABLE') !== false) $created++; } catch (Throwable $e) {}
                    }
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                jr(['success' => true, 'message' => "Обновлено таблиц: {$created}"]);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            break;

        // ── Tech Admin Auth ──────────────────────────────────────
        case 'auth_tech':
            try {
                $login = trim($_POST['login'] ?? '');
                $pass = trim($_POST['password'] ?? '');
                $cfg = getInstalledConfig();
                if (!$cfg) {
                    jr(['success' => false, 'message' => 'Система еще не установлена']);
                }

                // Check secret key first
                if (!empty($cfg['secret_key']) && ($login === $cfg['secret_key'] || $pass === $cfg['secret_key'])) {
                    $_SESSION['vld_tech_admin'] = true;
                    jr(['success' => true, 'message' => 'Авторизован по Secret Key']);
                }

                // Check DB admin user
                $pdo = getPdoConnection();
                if (!$pdo) {
                    jr(['success' => false, 'message' => 'Не удалось подключиться к базе данных']);
                }
                $prefix = $cfg['db']['prefix'] ?? 'vld_';
                $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM `{$prefix}users` WHERE (username = ? OR email = ?) AND role IN ('admin', 'moderator') LIMIT 1");
                $stmt->execute([$login, $login]);
                $user = $stmt->fetch();
                if ($user && password_verify($pass, $user['password_hash'])) {
                    $_SESSION['vld_tech_admin'] = true;
                    $_SESSION['vld_tech_user'] = $user['username'];
                    jr(['success' => true, 'message' => 'Авторизация успешна']);
                }

                jr(['success' => false, 'message' => 'Неверный логин/пароль или Secret Key']);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка авторизации: ' . $e->getMessage()]);
            }
            break;

        case 'auth_logout':
            unset($_SESSION['vld_tech_admin'], $_SESSION['vld_tech_user']);
            jr(['success' => true, 'message' => 'Вы вышли из панели тех. админа']);
            break;

        // ── Maintenance Mode Toggle ──────────────────────────────
        case 'toggle_maintenance':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            try {
                $cfgFile = PROJECT_ROOT . '/config/installed.php';
                $cfg = getInstalledConfig();
                if (!$cfg) jr(['success' => false, 'message' => 'Конфиг не найден']);
                $enable = !empty($_POST['enable']) && $_POST['enable'] === '1';
                $cfg['maintenance'] = $enable;
                file_put_contents($cfgFile, "<?php\nreturn " . var_export($cfg, true) . ";\n");
                jr(['success' => true, 'maintenance' => $enable, 'message' => $enable ? 'Режим обслуживания ВКЛЮЧЕН' : 'Режим обслуживания ВЫКЛЮЧЕН']);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            break;

        // ── Backups Management ───────────────────────────────────
        case 'create_backup':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            try {
                $pdo = getPdoConnection();
                if (!$pdo) jr(['success' => false, 'message' => 'Нет подключения к БД']);
                $backupDir = PROJECT_ROOT . '/storage/backups';
                if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

                $cfg = getInstalledConfig();
                $prefix = $cfg['db']['prefix'] ?? 'vld_';
                $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
                $filePath = $backupDir . '/' . $filename;

                $tables = [];
                $res = $pdo->query("SHOW TABLES LIKE '{$prefix}%'");
                while ($row = $res->fetch(PDO::FETCH_NUM)) {
                    $tables[] = $row[0];
                }

                $dump = "-- VladAero Database Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n-- Prefix: {$prefix}\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

                foreach ($tables as $tbl) {
                    $cRes = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
                    $dump .= "DROP TABLE IF EXISTS `{$tbl}`;\n" . $cRes[1] . ";\n\n";

                    $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $dump .= "INSERT INTO `{$tbl}` VALUES\n";
                        $values = [];
                        foreach ($rows as $r) {
                            $escaped = array_map(function($v) use ($pdo) {
                                return $v === null ? 'NULL' : $pdo->quote($v);
                            }, array_values($r));
                            $values[] = '(' . implode(', ', $escaped) . ')';
                        }
                        $dump .= implode(",\n", $values) . ";\n\n";
                    }
                }
                $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
                file_put_contents($filePath, $dump);

                jr(['success' => true, 'filename' => $filename, 'size' => round(filesize($filePath) / 1024, 1) . ' КБ', 'message' => "Резервная копия {$filename} создана"]);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка бэкапа: ' . $e->getMessage()]);
            }
            break;

        case 'list_backups':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            $backupDir = PROJECT_ROOT . '/storage/backups';
            $backups = [];
            if (is_dir($backupDir)) {
                $files = glob($backupDir . '/*.sql');
                if ($files) {
                    foreach ($files as $f) {
                        $backups[] = [
                            'name' => basename($f),
                            'size' => round(filesize($f) / 1024, 1) . ' КБ',
                            'date' => date('d.m.Y H:i:s', filemtime($f)),
                        ];
                    }
                    usort($backups, fn($a, $b) => strcmp($b['name'], $a['name']));
                }
            }
            jr(['success' => true, 'backups' => $backups]);
            break;

        case 'restore_backup':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            try {
                $file = basename($_POST['file'] ?? '');
                $filePath = PROJECT_ROOT . '/storage/backups/' . $file;
                if (!file_exists($filePath)) jr(['success' => false, 'message' => 'Файл бэкапа не найден']);

                $pdo = getPdoConnection();
                if (!$pdo) jr(['success' => false, 'message' => 'Нет подключения к БД']);

                $sql = file_get_contents($filePath);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $pdo->exec($sql);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                jr(['success' => true, 'message' => "База данных успешно восстановлена из {$file}"]);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка восстановления: ' . $e->getMessage()]);
            }
            break;

        case 'delete_backup':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            $file = basename($_POST['file'] ?? '');
            $filePath = PROJECT_ROOT . '/storage/backups/' . $file;
            if (file_exists($filePath)) {
                @unlink($filePath);
                jr(['success' => true, 'message' => "Файл {$file} удален"]);
            }
            jr(['success' => false, 'message' => 'Файл не найден']);
            break;

        // ── Logs & Diagnostics ───────────────────────────────────
        case 'get_logs':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            $errorLog = PROJECT_ROOT . '/storage/logs/error.log';
            $appLog = PROJECT_ROOT . '/storage/logs/app.log';
            $errContent = file_exists($errorLog) ? substr(file_get_contents($errorLog), -15000) : 'Логов ошибок нет';
            $appContent = file_exists($appLog) ? substr(file_get_contents($appLog), -15000) : 'Логов приложения нет';
            jr(['success' => true, 'error_log' => $errContent ?: 'Пусто', 'app_log' => $appContent ?: 'Пусто']);
            break;

        case 'clear_logs':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            $errorLog = PROJECT_ROOT . '/storage/logs/error.log';
            $appLog = PROJECT_ROOT . '/storage/logs/app.log';
            if (file_exists($errorLog)) file_put_contents($errorLog, '');
            if (file_exists($appLog)) file_put_contents($appLog, '');
            jr(['success' => true, 'message' => 'Логи успешно очищены']);
            break;

        case 'clear_cache':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            $cacheDir = PROJECT_ROOT . '/storage/cache';
            $count = 0;
            if (is_dir($cacheDir)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    if (!$f->isDir()) { @unlink($f->getPathname()); $count++; }
                }
            }
            jr(['success' => true, 'message' => "Кэш очищен (удалено {$count} файлов)"]);
            break;

        case 'optimize_db':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            try {
                $pdo = getPdoConnection();
                if (!$pdo) jr(['success' => false, 'message' => 'Нет подключения к БД']);
                $cfg = getInstalledConfig();
                $prefix = $cfg['db']['prefix'] ?? 'vld_';
                $tables = $pdo->query("SHOW TABLES LIKE '{$prefix}%'")->fetchAll(PDO::FETCH_NUM);
                $opt = 0;
                foreach ($tables as $t) {
                    $pdo->query("OPTIMIZE TABLE `{$t[0]}`");
                    $opt++;
                }
                jr(['success' => true, 'message' => "Оптимизировано {$opt} таблиц БД"]);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            break;

        // ── Uninstallation / Reset ───────────────────────────────
        case 'uninstall':
            if (!isTechAdmin()) jr(['success' => false, 'message' => 'Доступ запрещен']);
            $confirm = trim($_POST['confirm'] ?? '');
            if ($confirm !== 'UNINSTALL') {
                jr(['success' => false, 'message' => 'Для подтверждения введите UNINSTALL']);
            }
            try {
                $pdo = getPdoConnection();
                $cfg = getInstalledConfig();
                if ($pdo && $cfg) {
                    $prefix = $cfg['db']['prefix'] ?? 'vld_';
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $tables = $pdo->query("SHOW TABLES LIKE '{$prefix}%'")->fetchAll(PDO::FETCH_NUM);
                    foreach ($tables as $t) {
                        $pdo->exec("DROP TABLE IF EXISTS `{$t[0]}`");
                        $pdo->exec("DROP VIEW IF EXISTS `{$t[0]}`");
                    }
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                }
                $installedFile = PROJECT_ROOT . '/config/installed.php';
                if (file_exists($installedFile)) @unlink($installedFile);
                unset($_SESSION['vld_tech_admin'], $_SESSION['vld_tech_user']);
                jr(['success' => true, 'message' => 'Система полностью деинсталлирована. База очищена.']);
            } catch (Throwable $e) {
                jr(['success' => false, 'message' => 'Ошибка деинсталляции: ' . $e->getMessage()]);
            }
            break;
    }
}

/**
 * Recursive directory delete
 */
function delTree($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) @rmdir($f->getPathname());
        else @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// Handle direct backup download
if (isset($_GET['download_backup'])) {
    if (!isTechAdmin()) { die('Доступ запрещен'); }
    $file = basename($_GET['download_backup']);
    $path = PROJECT_ROOT . '/storage/backups/' . $file;
    if (file_exists($path)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    die('Файл не найден');
}

$isInstalled = file_exists(PROJECT_ROOT . '/config/installed.php');
$isLoggedIn = isTechAdmin();
$cfg = getInstalledConfig();
$isMaintenance = !empty($cfg['maintenance']);
$pageTitle = $isInstalled ? 'Центр технического администрирования' : 'Установка VladAero';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VladAero — <?= $pageTitle ?></title>
    <style>
        :root {
            --bg: #0a1628; --surface: #111d33; --surface-2: #162240;
            --border: #1e3a5f; --text: #e0e8f0; --text-dim: #8899aa;
            --accent: #3b82f6; --accent-glow: rgba(59,130,246,.25);
            --success: #22c55e; --error: #ef4444; --warning: #f59e0b;
            --font: 'Inter', -apple-system, sans-serif;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: var(--font); background: var(--bg); color: var(--text);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem;
        }
        .installer {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 16px; max-width: 680px; width: 100%;
            overflow: hidden; box-shadow: 0 25px 60px rgba(0,0,0,.4);
        }
        .installer--wide { max-width: 800px; }
        .installer__header {
            background: var(--surface-2); padding: 1.5rem 2rem; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .installer__header h1 { font-size: 1.3rem; font-weight: 600; }
        .installer__header p { color: var(--text-dim); font-size: .85rem; margin-top: .2rem; }
        .installer__body { padding: 2rem; }
        .installer__footer {
            padding: 1.25rem 2rem; border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; gap: 1rem;
        }
        .steps { display: flex; gap: .5rem; justify-content: center; margin-bottom: 2rem; }
        .step-dot { width: 32px; height: 4px; border-radius: 2px; background: var(--border); transition: background .3s; }
        .step-dot.active { background: var(--accent); box-shadow: 0 0 8px var(--accent-glow); }
        .step-dot.done { background: var(--success); }
        .step-panel { display: none; }
        .step-panel.active { display: block; animation: fadeIn .3s ease; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
        .step-panel h2 { font-size: 1.2rem; margin-bottom: 1rem; }
        .step-panel p { color: var(--text-dim); margin-bottom: 1.25rem; font-size: .9rem; line-height: 1.6; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-size: .8rem; font-weight: 500; color: var(--text-dim); margin-bottom: .4rem; text-transform: uppercase; letter-spacing: .5px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: .7rem 1rem; background: var(--bg); border: 1px solid var(--border);
            border-radius: 8px; color: var(--text); font-size: .95rem; font-family: var(--font); transition: border-color .2s;
        }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .btn {
            padding: .65rem 1.25rem; border: none; border-radius: 8px; font-family: var(--font);
            font-size: .9rem; font-weight: 500; cursor: pointer; transition: all .2s;
            display: inline-flex; align-items: center; gap: .5rem; text-decoration: none;
        }
        .btn--primary { background: var(--accent); color: #fff; box-shadow: 0 4px 12px var(--accent-glow); }
        .btn--primary:hover { background: #2563eb; transform: translateY(-1px); }
        .btn--primary:disabled { opacity: .5; cursor: not-allowed; transform: none; }
        .btn--outline { background: transparent; color: var(--text); border: 1px solid var(--border); }
        .btn--outline:hover { border-color: var(--accent); color: var(--accent); }
        .btn--success { background: var(--success); color: #fff; }
        .btn--danger { background: var(--error); color: #fff; }
        .btn--warning { background: var(--warning); color: #111; }
        .btn--sm { padding: .4rem .8rem; font-size: .8rem; }
        .btn--block { width: 100%; justify-content: center; }
        .req-list { list-style: none; }
        .req-item { display: flex; align-items: center; gap: .75rem; padding: .5rem .8rem; border-radius: 8px; margin-bottom: .2rem; font-size: .85rem; }
        .req-item:nth-child(odd) { background: rgba(255,255,255,.02); }
        .req-item.pass { color: var(--success); }
        .req-item.fail { color: var(--error); }
        .progress { margin: 1.5rem 0; }
        .progress__bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
        .progress__fill { height: 100%; background: linear-gradient(90deg, var(--accent), var(--success)); border-radius: 3px; width: 0; transition: width .5s ease; }
        .progress__text { text-align: center; margin-top: .75rem; color: var(--text-dim); font-size: .85rem; }
        .success-box { text-align: center; padding: 2rem 1rem; }
        .success-icon { font-size: 3rem; margin-bottom: 1rem; }
        .success-box h2 { margin-bottom: .5rem; }
        .success-box p { color: var(--text-dim); margin-bottom: 1rem; line-height: 1.6; }
        .success-box .credentials { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; text-align: left; font-family: monospace; font-size: .85rem; color: var(--success); white-space: pre-wrap; }
        .loader { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
        /* Drop zone */
        .drop-zone {
            border: 2px dashed var(--border); border-radius: 12px; padding: 2.5rem 2rem;
            text-align: center; cursor: pointer; transition: all .2s; position: relative;
        }
        .drop-zone:hover, .drop-zone.dragover {
            border-color: var(--accent); background: rgba(59,130,246,.05);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }
        .drop-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .drop-zone__icon { font-size: 2.5rem; margin-bottom: .5rem; }
        .drop-zone__text { color: var(--text-dim); font-size: .9rem; }
        .drop-zone__file { color: var(--accent); font-weight: 600; margin-top: .5rem; display: none; }
        .upload-result { margin-top: 1rem; padding: .8rem 1rem; border-radius: 8px; display: none; font-size: .9rem; }
        .upload-result.ok { background: rgba(34,197,94,.1); border: 1px solid var(--success); color: var(--success); display: block; }
        .upload-result.err { background: rgba(239,68,68,.1); border: 1px solid var(--error); color: var(--error); display: block; }
        /* Local ZIP list */
        .local-zips { margin-top: 1rem; }
        .local-zips__title { font-size: .8rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: .5px; margin-bottom: .5rem; }
        .local-zip-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: .6rem .8rem; background: var(--bg); border: 1px solid var(--border);
            border-radius: 8px; margin-bottom: .4rem; cursor: pointer; transition: all .2s;
        }
        .local-zip-item:hover { border-color: var(--accent); background: rgba(59,130,246,.05); }
        .local-zip-item.invalid { opacity: .5; cursor: not-allowed; }
        .local-zip-name { font-size: .9rem; font-weight: 500; }
        .local-zip-meta { font-size: .8rem; color: var(--text-dim); }
        .local-zip-badge { font-size: .75rem; padding: .15rem .5rem; border-radius: 4px; }
        .local-zip-badge.ok { background: rgba(34,197,94,.15); color: var(--success); }
        .local-zip-badge.err { background: rgba(239,68,68,.15); color: var(--error); }
        /* Tabs for Tech Admin */
        .tabs-nav { display: flex; gap: .25rem; border-bottom: 1px solid var(--border); padding: 0 1.5rem; background: var(--surface-2); overflow-x: auto; }
        .tab-btn {
            padding: .85rem 1.1rem; background: none; border: none; border-bottom: 2px solid transparent;
            color: var(--text-dim); font-family: var(--font); font-size: .85rem; font-weight: 500;
            cursor: pointer; transition: all .2s; display: flex; align-items: center; gap: .4rem; white-space: nowrap;
        }
        .tab-btn:hover { color: var(--text); }
        .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
        .tab-content { display: none; padding: 1.5rem; }
        .tab-content.active { display: block; animation: fadeIn .2s ease; }
        .badge { display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .6rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; }
        .badge--success { background: rgba(34,197,94,.15); color: var(--success); }
        .badge--warning { background: rgba(245,158,11,.15); color: var(--warning); }
        .badge--danger { background: rgba(239,68,68,.15); color: var(--error); }
        .log-box {
            background: #060d17; border: 1px solid var(--border); border-radius: 8px;
            padding: 1rem; font-family: monospace; font-size: .8rem; color: #a0aec0;
            max-height: 240px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;
        }
        .table-simple { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .table-simple th, .table-simple td { padding: .6rem .8rem; text-align: left; border-bottom: 1px solid var(--border); }
        .table-simple th { color: var(--text-dim); font-size: .75rem; text-transform: uppercase; }
        .card-box { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1rem; }
    </style>
</head>
<body>

<?php if ($isInstalled && !$isLoggedIn): ?>
<!-- ══════════════════════════════════════════════════════════════
     TECH ADMIN LOGIN SCREEN
════════════════════════════════════════════════════════════════ -->
<div class="installer">
    <div class="installer__header">
        <div>
            <h1>🛡️ VladAero — Центр тех. админа</h1>
            <p>Система установлена. Авторизуйтесь для доступа к управлению.</p>
        </div>
        <span class="badge badge--success">v<?= VLD_VERSION ?></span>
    </div>
    <div class="installer__body">
        <form id="techAuthForm" onsubmit="handleTechLogin(event)">
            <div class="form-group">
                <label>Логин администратора / Email / Secret Key</label>
                <input type="text" id="authLogin" placeholder="admin или Secret Key" required autofocus>
            </div>
            <div class="form-group">
                <label>Пароль (не требуется при входе по Secret Key)</label>
                <input type="password" id="authPassword" placeholder="Пароль">
            </div>
            <div id="authError" style="display:none;margin-bottom:1rem;color:var(--error);font-size:.85rem;"></div>
            <button type="submit" class="btn btn--primary btn--block" id="btnAuth">🔐 Войти в панель</button>
        </form>
    </div>
    <div class="installer__footer">
        <a href="<?= getSiteUrl('/') ?>" class="btn btn--outline btn--sm">🏠 На сайт</a>
        <a href="<?= getSiteUrl('/admin') ?>" class="btn btn--outline btn--sm">🛡️ В админку сайта</a>
    </div>
</div>

<?php elseif ($isInstalled && $isLoggedIn): ?>
<!-- ══════════════════════════════════════════════════════════════
     TECH ADMIN CENTER (INSTALLED & LOGGED IN)
════════════════════════════════════════════════════════════════ -->
<div class="installer installer--wide">
    <div class="installer__header">
        <div>
            <h1>✈️ Центр технического администрирования</h1>
            <p>VladAero v<?= VLD_VERSION ?> | PHP <?= PHP_VERSION ?> | БД: <?= e($cfg['db']['name'] ?? '') ?></p>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem;">
            <a href="<?= getSiteUrl('/') ?>" target="_blank" class="btn btn--outline btn--sm">🌐 Сайт</a>
            <button onclick="handleTechLogout()" class="btn btn--danger btn--sm">Выйти 🚪</button>
        </div>
    </div>

    <div class="tabs-nav">
        <button class="tab-btn active" onclick="showTab('tab-update', this)">📦 Обновление</button>
        <button class="tab-btn" onclick="showTab('tab-backups', this)">💾 Резервные копии</button>
        <button class="tab-btn" onclick="showTab('tab-maintenance', this)">🚧 Обслуживание</button>
        <button class="tab-btn" onclick="showTab('tab-logs', this)">📋 Логи & Сервер</button>
        <button class="tab-btn" onclick="showTab('tab-uninstall', this)">⚠️ Деинсталляция</button>
    </div>

    <!-- ── TAB: UPDATE ── -->
    <div class="tab-content active" id="tab-update">
        <div class="card-box">
            <h3 style="margin-bottom:.5rem;">📦 Обновление системы через ZIP</h3>
            <p style="color:var(--text-dim);font-size:.85rem;margin-bottom:1rem;">
                Загрузите ZIP-архив новой версии или выберите ZIP-файл на сервере. Файлы будут обновлены, база данных — автоматически мигрирована.
            </p>
            <div class="drop-zone" id="dropZone">
                <input type="file" accept=".zip" id="zipInput">
                <div class="drop-zone__icon">📦</div>
                <div class="drop-zone__text">Перетащите ZIP-архив сюда или кликните для выбора</div>
                <div class="drop-zone__file" id="dropFileName"></div>
            </div>
            <div class="upload-result" id="uploadResult"></div>
            <div class="local-zips" id="localZips" style="display:none;">
                <div class="local-zips__title">📂 ZIP-файлы в корне сервера</div>
                <div id="localZipsList"></div>
            </div>
            <div class="progress" id="updateProgressBox" style="display:none;">
                <div class="progress__bar"><div class="progress__fill" id="updateProgressBar"></div></div>
                <div class="progress__text" id="updateProgressText">Подготовка...</div>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button class="btn btn--primary" id="btnStartUpdate" onclick="startUpdateProcess()">🚀 Начать обновление</button>
            </div>
        </div>
    </div>

    <!-- ── TAB: BACKUPS ── -->
    <div class="tab-content" id="tab-backups">
        <div class="card-box">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <div>
                    <h3>💾 Резервные копии базы данных</h3>
                    <p style="color:var(--text-dim);font-size:.85rem;">Создание дампа таблиц VladAero в storage/backups/</p>
                </div>
                <button class="btn btn--success btn--sm" onclick="createBackup(this)">➕ Создать бэкап</button>
            </div>
            <div id="backupStatus" style="margin-bottom:.5rem;"></div>
            <div id="backupsListTable">Загрузка списка...</div>
        </div>
    </div>

    <!-- ── TAB: MAINTENANCE ── -->
    <div class="tab-content" id="tab-maintenance">
        <div class="card-box">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <div>
                    <h3>🚧 Режим технического обслуживания</h3>
                    <p style="color:var(--text-dim);font-size:.85rem;">В этом режиме сайт доступен только администраторам</p>
                </div>
                <span id="maintBadge" class="badge <?= $isMaintenance ? 'badge--danger' : 'badge--success' ?>">
                    <?= $isMaintenance ? '🔴 ВКЛЮЧЕН' : '🟢 ВЫКЛЮЧЕН' ?>
                </span>
            </div>
            <p style="font-size:.85rem;color:var(--text-dim);margin-bottom:1rem;">
                При включенном режиме посетители видят страницу-заглушку с сообщением о технических работах.
            </p>
            <button class="btn <?= $isMaintenance ? 'btn--success' : 'btn--warning' ?>" id="btnToggleMaint" onclick="toggleMaintenance(<?= $isMaintenance ? '0' : '1' ?>)">
                <?= $isMaintenance ? '🟢 Выключить режим обслуживания' : '🔴 Включить режим обслуживания' ?>
            </button>
        </div>
    </div>

    <!-- ── TAB: LOGS & DIAGNOSTICS ── -->
    <div class="tab-content" id="tab-logs">
        <div class="card-box">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;">
                <h3>⚙️ Системные операции</h3>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
                <button class="btn btn--outline btn--sm" onclick="clearCache(this)">🧹 Очистить кэш</button>
                <button class="btn btn--outline btn--sm" onclick="optimizeDb(this)">⚡ Оптимизировать БД</button>
                <button class="btn btn--outline btn--sm" onclick="loadLogs()">🔄 Обновить логи</button>
                <button class="btn btn--danger btn--sm" onclick="clearLogs(this)">🗑️ Очистить логи</button>
            </div>
            <div id="diagStatus" style="margin-bottom:.5rem;"></div>
            <div class="form-row">
                <div>
                    <label style="font-size:.75rem;color:var(--text-dim);text-transform:uppercase;">Лог ошибок (storage/logs/error.log)</label>
                    <div class="log-box" id="errorLogBox">Загрузка...</div>
                </div>
                <div>
                    <label style="font-size:.75rem;color:var(--text-dim);text-transform:uppercase;">Лог событий (storage/logs/app.log)</label>
                    <div class="log-box" id="appLogBox">Загрузка...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: UNINSTALL ── -->
    <div class="tab-content" id="tab-uninstall">
        <div class="card-box" style="border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.03);">
            <h3 style="color:var(--error);margin-bottom:.5rem;">⚠️ Опасная зона: Деинсталляция</h3>
            <p style="color:var(--text-dim);font-size:.85rem;margin-bottom:1rem;">
                Полное удаление таблиц VladAero из базы данных и сброс конфигурации. После этого сайт вернется в режим чистой установки.
            </p>
            <div class="form-group" style="max-width:300px;">
                <label>Введите слово <strong>UNINSTALL</strong> для подтверждения:</label>
                <input type="text" id="uninstallConfirm" placeholder="UNINSTALL">
            </div>
            <button class="btn btn--danger" onclick="runUninstall(this)">💥 Полная деинсталляция</button>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════
     INSTALL WIZARD (NOT INSTALLED)
════════════════════════════════════════════════════════════════ -->
<div class="installer">
    <div class="installer__header">
        <h1>✈️ VladAero — Установка</h1>
        <p>v<?= VLD_VERSION ?> | PHP <?= PHP_VERSION ?></p>
    </div>

    <div class="installer__body">
        <div class="steps" id="stepsDots"></div>

        <div class="step-panel active" id="step1">
            <h2>📦 Загрузите ZIP-архив</h2>
            <p>Загрузите ZIP-архив VladAero. Если файлы уже распакованы — пропустите шаг.</p>
            <div class="drop-zone" id="dropZone">
                <input type="file" accept=".zip" id="zipInput">
                <div class="drop-zone__icon">📦</div>
                <div class="drop-zone__text">Перетащите ZIP-файл сюда<br>или нажмите для выбора</div>
                <div class="drop-zone__file" id="dropFileName"></div>
            </div>
            <div class="upload-result" id="uploadResult"></div>
            <div class="local-zips" id="localZips" style="display:none;">
                <div class="local-zips__title">📂 ZIP-файлы на сервере</div>
                <div id="localZipsList"></div>
            </div>
        </div>

        <div class="step-panel" id="step2">
            <h2>📋 Проверка сервера</h2>
            <ul class="req-list" id="reqList"></ul>
        </div>

        <div class="step-panel" id="step3">
            <h2>🗄️ Подключение к БД</h2>
            <p>Введите данные MySQL. БД будет создана автоматически если не существует.</p>
            <div class="form-row">
                <div class="form-group"><label>Хост</label><input type="text" id="dbHost" value="localhost"></div>
                <div class="form-group"><label>Префикс</label><input type="text" id="dbPrefix" value="vld_"></div>
            </div>
            <div class="form-group"><label>Имя БД</label><input type="text" id="dbName" value="vladaero"></div>
            <div class="form-row">
                <div class="form-group"><label>Пользователь</label><input type="text" id="dbUser" value="root"></div>
                <div class="form-group"><label>Пароль</label><input type="password" id="dbPass" value=""></div>
            </div>
            <div id="dbStatus" style="margin-top:1rem;font-size:.85rem;"></div>
        </div>

        <div class="step-panel" id="step4">
            <h2>📋 Создание таблиц</h2>
            <div class="progress">
                <div class="progress__bar"><div class="progress__fill" id="migProgress"></div></div>
                <div class="progress__text" id="migText">Подготовка...</div>
            </div>
        </div>

        <div class="step-panel" id="step5">
            <h2>🛡️ Администратор</h2>
            <div class="form-group"><label>Название сайта</label><input type="text" id="siteName" value="VladAero"></div>
            <div class="form-group"><label>Базовый URL</label><input type="text" id="baseUrl" placeholder="https://example.com"></div>
            <hr style="border-color:var(--border);margin:1.5rem 0;">
            <div class="form-row">
                <div class="form-group"><label>Логин</label><input type="text" id="adminUser" value="admin"></div>
                <div class="form-group"><label>Email</label><input type="email" id="adminEmail" placeholder="admin@example.com"></div>
            </div>
            <div class="form-group"><label>Имя</label><input type="text" id="adminName" value="Администратор"></div>
            <div class="form-group"><label>Пароль</label><input type="password" id="adminPass" value=""></div>
        </div>

        <div class="step-panel" id="step6">
            <div class="success-box">
                <div class="success-icon">✅</div>
                <h2>Установка завершена!</h2>
                <div class="credentials" id="credBox"></div>
                <a href="<?= getSiteUrl('/admin/ai-settings') ?>" class="btn btn--primary" style="margin-right:.5rem;">🤖 Настроить ИИ</a>
                <a href="install.php" class="btn btn--outline" style="margin-right:.5rem;">🛡️ Центр управления</a>
                <a href="<?= getSiteUrl('/') ?>" class="btn btn--outline">🏠 На сайт</a>
            </div>
        </div>
    </div>

    <div class="installer__footer" id="installerFooter">
        <button class="btn btn--outline" id="btnPrev" style="display:none;">← Назад</button>
        <div style="flex:1"></div>
        <button class="btn btn--primary" id="btnNext">Далее →</button>
    </div>
</div>
<?php endif; ?>

<script>
// ═══ Global Helpers ═════════════════════════════════════════════
const IS_INSTALLED = <?= $isInstalled ? 'true' : 'false' ?>;
const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;

function showTab(tabId, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const target = document.getElementById(tabId);
    if (target) target.classList.add('active');
    if (tabId === 'tab-backups') loadBackups();
    if (tabId === 'tab-logs') loadLogs();
}

// ─── Tech Admin Auth ────────────────────────────────────────────
async function handleTechLogin(e) {
    e.preventDefault();
    const btn = document.getElementById('btnAuth');
    const err = document.getElementById('authError');
    btn.disabled = true;
    btn.innerHTML = '<span class="loader"></span> Проверка...';
    err.style.display = 'none';

    try {
        const resp = await fetch('install.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'auth_tech',
                login: document.getElementById('authLogin').value,
                password: document.getElementById('authPassword').value
            })
        });
        const data = await resp.json();
        if (data.success) {
            location.reload();
        } else {
            err.textContent = data.message;
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '🔐 Войти в панель';
        }
    } catch (e) {
        err.textContent = 'Ошибка сети: ' + e.message;
        err.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '🔐 Войти в панель';
    }
}

async function handleTechLogout() {
    await fetch('install.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=auth_logout'
    });
    location.reload();
}

// ─── Maintenance Mode ───────────────────────────────────────────
async function toggleMaintenance(enable) {
    const btn = document.getElementById('btnToggleMaint');
    btn.disabled = true;
    const resp = await fetch('install.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'toggle_maintenance', enable: enable })
    });
    const data = await resp.json();
    location.reload();
}

// ─── Backups ────────────────────────────────────────────────────
async function loadBackups() {
    const tableEl = document.getElementById('backupsListTable');
    try {
        const resp = await fetch('install.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=list_backups'
        });
        const data = await resp.json();
        if (!data.success || !data.backups || data.backups.length === 0) {
            tableEl.innerHTML = '<p style="color:var(--text-dim);font-size:.85rem;">Резервных копий пока нет.</p>';
            return;
        }
        let html = '<table class="table-simple"><thead><tr><th>Файл</th><th>Размер</th><th>Дата</th><th>Действия</th></tr></thead><tbody>';
        data.backups.forEach(b => {
            html += `<tr>
                <td><strong>${b.name}</strong></td>
                <td>${b.size}</td>
                <td>${b.date}</td>
                <td>
                    <a href="install.php?download_backup=${encodeURIComponent(b.name)}" class="btn btn--outline btn--sm">⬇️ Скачать</a>
                    <button onclick="restoreBackup('${b.name}', this)" class="btn btn--warning btn--sm" style="margin-left:.25rem;">🔄 Восстановить</button>
                    <button onclick="deleteBackup('${b.name}', this)" class="btn btn--danger btn--sm" style="margin-left:.25rem;">🗑️</button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        tableEl.innerHTML = html;
    } catch (e) {
        tableEl.innerHTML = '<p style="color:var(--error);">Ошибка загрузки списка бэкапов</p>';
    }
}

async function createBackup(btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="loader"></span> Создание...';
    const statusEl = document.getElementById('backupStatus');
    const resp = await fetch('install.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=create_backup'
    });
    const data = await resp.json();
    btn.disabled = false;
    btn.innerHTML = '➕ Создать бэкап';
    if (data.success) {
        statusEl.innerHTML = `<span style="color:var(--success)">✅ ${data.message} (${data.size})</span>`;
        loadBackups();
    } else {
        statusEl.innerHTML = `<span style="color:var(--error)">❌ ${data.message}</span>`;
    }
}

async function restoreBackup(filename, btn) {
    if (!confirm(`Восстановить базу данных из ${filename}? Текущие данные будут перезаписаны.`)) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="loader"></span>';
    const resp = await fetch('install.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'restore_backup', file: filename })
    });
    const data = await resp.json();
    btn.disabled = false;
    btn.innerHTML = '🔄 Восстановить';
    alert(data.message);
}

async function deleteBackup(filename, btn) {
    if (!confirm(`Удалить бэкап ${filename}?`)) return;
    btn.disabled = true;
    await fetch('install.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'delete_backup', file: filename })
    });
    loadBackups();
}

// ─── Diagnostics & Logs ─────────────────────────────────────────
async function loadLogs() {
    try {
        const resp = await fetch('install.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_logs'
        });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('errorLogBox').textContent = data.error_log;
            document.getElementById('appLogBox').textContent = data.app_log;
        }
    } catch(e) {}
}

async function clearLogs(btn) {
    btn.disabled = true;
    await fetch('install.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=clear_logs' });
    btn.disabled = false;
    loadLogs();
}

async function clearCache(btn) {
    btn.disabled = true;
    const resp = await fetch('install.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=clear_cache' });
    const data = await resp.json();
    btn.disabled = false;
    document.getElementById('diagStatus').innerHTML = `<span style="color:var(--success)">✅ ${data.message}</span>`;
}

async function optimizeDb(btn) {
    btn.disabled = true;
    const resp = await fetch('install.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=optimize_db' });
    const data = await resp.json();
    btn.disabled = false;
    document.getElementById('diagStatus').innerHTML = `<span style="color:var(--success)">✅ ${data.message}</span>`;
}

// ─── Uninstall ──────────────────────────────────────────────────
async function runUninstall(btn) {
    const confirmVal = document.getElementById('uninstallConfirm').value;
    if (confirmVal !== 'UNINSTALL') {
        alert('Для подтверждения введите UNINSTALL');
        return;
    }
    if (!confirm('Вы уверены? База данных будет полностью очищена, сайт перейдет в режим чистой установки.')) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="loader"></span> Удаление...';
    const resp = await fetch('install.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'uninstall', confirm: 'UNINSTALL' })
    });
    const data = await resp.json();
    if (data.success) {
        alert(data.message);
        location.reload();
    } else {
        alert('Ошибка: ' + data.message);
        btn.disabled = false;
        btn.innerHTML = '💥 Полная деинсталляция';
    }
}

// Shared ZIP upload with automatic binary fallback
async function sendZipArchive(file) {
    // 1. Try standard FormData upload
    try {
        const formData = new FormData();
        formData.append('action', 'upload_zip');
        formData.append('zip_file', file);
        const resp = await fetch('install.php?action=upload_zip', { method: 'POST', body: formData });
        const text = await resp.text();
        let data;
        try { data = JSON.parse(text); } catch(e) { data = null; }
        if (data && data.success) {
            return data;
        }
    } catch(e) {}

    // 2. Fallback: Raw binary stream upload (bypasses PHP post/upload limits)
    try {
        const buffer = await file.arrayBuffer();
        const resp = await fetch('install.php?action=upload_raw', {
            method: 'POST',
            headers: { 'Content-Type': 'application/octet-stream' },
            body: buffer
        });
        const data = await resp.json();
        return data;
    } catch (e) {
        return { success: false, message: 'Ошибка передачи файла: ' + e.message };
    }
}

// ─── Update via ZIP (used in Tech Admin Center) ─────────────────
async function startUpdateProcess() {
    const btn = document.getElementById('btnStartUpdate');
    const pBox = document.getElementById('updateProgressBox');
    const pBar = document.getElementById('updateProgressBar');
    const pTxt = document.getElementById('updateProgressText');
    const resEl = document.getElementById('uploadResult');

    btn.disabled = true;
    pBox.style.display = 'block';
    pBar.style.width = '20%';
    pTxt.textContent = 'Загрузка и распаковка файлов...';

    // Check if zip input has file
    const zipInput = document.getElementById('zipInput');
    if (zipInput && zipInput.files.length) {
        const data = await sendZipArchive(zipInput.files[0]);
        if (!data.success) {
            resEl.className = 'upload-result err';
            resEl.textContent = '❌ ' + data.message;
            btn.disabled = false;
            pBox.style.display = 'none';
            return;
        }
    }

    pBar.style.width = '60%';
    pTxt.textContent = 'Миграция базы данных...';

    const respMig = await fetch('install.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', db_prefix: 'vld_' })
    });
    const dataMig = await respMig.json();

    pBar.style.width = '100%';
    if (dataMig.success) {
        pTxt.textContent = '✅ ' + dataMig.message;
        resEl.className = 'upload-result ok';
        resEl.textContent = '✅ Обновление успешно завершено! Файлы обновлены, БД синхронизирована.';
        btn.disabled = false;
    } else {
        pTxt.textContent = '❌ ' + dataMig.message;
        btn.disabled = false;
    }
}

// ═════════════════════════════════════════════════════════════════
// INSTALLER WIZARD LOGIC (IF NOT INSTALLED)
// ═════════════════════════════════════════════════════════════════
let currentStep = 1;
let zipUploaded = false;
const TOTAL_STEPS = 6;

function buildDots() {
    const el = document.getElementById('stepsDots');
    if (!el) return;
    el.innerHTML = '';
    for (let i = 1; i <= TOTAL_STEPS; i++) {
        el.innerHTML += `<div class="step-dot ${i === currentStep ? 'active' : ''}" data-step="${i}"></div>`;
    }
}

function setStep(step) {
    currentStep = step;
    document.querySelectorAll('.step-dot').forEach(d => {
        const s = parseInt(d.dataset.step);
        d.classList.toggle('active', s === step);
        d.classList.toggle('done', s < step);
    });
    document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
    const target = document.getElementById('step' + step);
    if (target) target.classList.add('active');
    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');
    if (btnPrev) btnPrev.style.display = (step > 1 && step < TOTAL_STEPS) ? '' : 'none';
    if (btnNext) btnNext.style.display = step < TOTAL_STEPS ? '' : 'none';
    const footer = document.getElementById('installerFooter');
    if (step === TOTAL_STEPS && footer) footer.style.display = 'none';
    if (btnNext) btnNext.disabled = false;
}

// Dropzone handling
const dropZone = document.getElementById('dropZone');
const zipInput = document.getElementById('zipInput');

if (dropZone && zipInput) {
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) { zipInput.files = e.dataTransfer.files; handleFile(e.dataTransfer.files[0]); }
    });
    zipInput.addEventListener('change', () => { if (zipInput.files.length) handleFile(zipInput.files[0]); });
}

function handleFile(file) {
    const fn = document.getElementById('dropFileName');
    if (fn) {
        fn.textContent = '📎 ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + ' МБ)';
        fn.style.display = 'block';
    }
}

async function detectLocalZips() {
    try {
        const resp = await fetch('install.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=detect_local_zips'
        });
        const data = await resp.json();
        if (data.success && data.zips.length > 0) {
            const container = document.getElementById('localZips');
            const list = document.getElementById('localZipsList');
            if (list && container) {
                list.innerHTML = '';
                data.zips.forEach(zip => {
                    const div = document.createElement('div');
                    div.className = 'local-zip-item' + (zip.valid ? '' : ' invalid');
                    div.innerHTML = `
                        <div>
                            <div class="local-zip-name">📦 ${zip.name}</div>
                            <div class="local-zip-meta">${zip.size} ${zip.valid ? '' : '— ' + zip.reason}</div>
                        </div>
                        <span class="local-zip-badge ${zip.valid ? 'ok' : 'err'}">${zip.valid ? '✅ VladAero' : '❌'}</span>
                    `;
                    if (zip.valid) div.addEventListener('click', () => useLocalZip(zip.path, div));
                    list.appendChild(div);
                });
                container.style.display = '';
            }
        }
    } catch (e) {}
}

async function useLocalZip(zipPath, element) {
    const resultEl = document.getElementById('uploadResult');
    try {
        const resp = await fetch('install.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'use_local_zip', zip_path: zipPath })
        });
        const data = await resp.json();
        if (data.success) {
            resultEl.className = 'upload-result ok';
            resultEl.textContent = '✅ ' + data.message;
            zipUploaded = true;
        } else {
            resultEl.className = 'upload-result err';
            resultEl.textContent = '❌ ' + data.message;
        }
    } catch(e) {}
}

async function uploadZip() {
    if (!zipInput || !zipInput.files.length) { zipUploaded = true; return true; }
    const file = zipInput.files[0];
    const resultEl = document.getElementById('uploadResult');
    try {
        const data = await sendZipArchive(file);
        if (data.success) {
            resultEl.className = 'upload-result ok';
            resultEl.textContent = '✅ ' + data.message;
            zipUploaded = true;
            return true;
        } else {
            resultEl.className = 'upload-result err';
            resultEl.textContent = '❌ ' + data.message;
            return false;
        }
    } catch (e) {
        resultEl.className = 'upload-result err';
        resultEl.textContent = '❌ ' + e.message;
        return false;
    }
}

async function checkRequirements() {
    const resp = await fetch('install.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=check_requirements' });
    const data = await resp.json();
    const labels = { php_version: 'PHP 8.0+', pdo: 'PDO', pdo_mysql: 'PDO MySQL', mbstring: 'mbstring', json: 'JSON', gd: 'GD / ImageMagick', fileinfo: 'Fileinfo', curl: 'cURL', zip: 'ZipArchive', writable_config: '/config writable', writable_storage: '/storage writable', writable_uploads: '/public/uploads writable' };
    const list = document.getElementById('reqList');
    if (!list) return true;
    list.innerHTML = '';
    let allPass = true;
    for (const [key, ok] of Object.entries(data)) {
        if (!ok) allPass = false;
        list.innerHTML += `<li class="req-item ${ok?'pass':'fail'}">${ok?'✅':'❌'} ${labels[key]||key}</li>`;
    }
    return allPass;
}

async function testDatabase() {
    const btn = document.getElementById('btnNext');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loader"></span> Подключение...'; }
    const resp = await fetch('install.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'test_database',
            db_host: document.getElementById('dbHost').value,
            db_name: document.getElementById('dbName').value,
            db_user: document.getElementById('dbUser').value,
            db_pass: document.getElementById('dbPass').value,
        })
    });
    const data = await resp.json();
    const el = document.getElementById('dbStatus');
    if (btn) btn.disabled = false;
    if (data.success) {
        if (el) el.innerHTML = `<span style="color:var(--success)">✅ ${data.message}</span>`;
        if (btn) btn.innerHTML = 'Далее →';
        return true;
    } else {
        if (el) el.innerHTML = `<span style="color:var(--error)">❌ ${data.message}</span>`;
        if (btn) btn.innerHTML = 'Повторить';
        return false;
    }
}

async function runMigration() {
    const bar = document.getElementById('migProgress');
    const txt = document.getElementById('migText');
    const tables = ['settings','languages','users','aircraft','airlines','airports','photos','articles','news','quizzes','events','clubs','radar','ai'];
    for (let i = 0; i < tables.length; i++) {
        if (bar) bar.style.width = Math.round(((i+1)/tables.length)*100) + '%';
        if (txt) txt.textContent = `${i+1}/${tables.length}: ${tables[i]}`;
        await new Promise(r => setTimeout(r, 40));
    }
}

async function runInstall() {
    const btn = document.getElementById('btnNext');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loader"></span> Сохранение...'; }
    const payload = {
        action: 'install',
        db_host: document.getElementById('dbHost').value,
        db_name: document.getElementById('dbName').value,
        db_user: document.getElementById('dbUser').value,
        db_pass: document.getElementById('dbPass').value,
        db_prefix: document.getElementById('dbPrefix').value,
        site_name: document.getElementById('siteName').value,
        base_url: document.getElementById('baseUrl').value,
        admin_user: document.getElementById('adminUser').value,
        admin_email: document.getElementById('adminEmail').value,
        admin_name: document.getElementById('adminName').value,
        admin_pass: document.getElementById('adminPass').value,
    };
    const resp = await fetch('install.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const data = await resp.json();
    if (data.success) {
        const cred = document.getElementById('credBox');
        if (cred) cred.textContent = `Логин: ${payload.admin_user}\nПароль: ${payload.admin_pass}\nБД: ${payload.db_name} (${payload.db_prefix})`;
        setStep(TOTAL_STEPS);
    } else {
        alert('Ошибка: ' + data.message);
        if (btn) { btn.disabled = false; btn.innerHTML = 'Повторить'; }
    }
}

const btnNext = document.getElementById('btnNext');
if (btnNext) {
    btnNext.addEventListener('click', async function() {
        const btn = this;
        switch (currentStep) {
            case 1:
                btn.innerHTML = '<span class="loader"></span>';
                btn.disabled = true;
                const uploaded = await uploadZip();
                if (uploaded) {
                    setStep(2);
                    const ok = await checkRequirements();
                    btn.innerHTML = ok ? 'Далее →' : '❌ Исправьте ошибки';
                    btn.disabled = false;
                }
                break;
            case 2:
                setStep(3);
                break;
            case 3:
                const dbOk = await testDatabase();
                if (dbOk) {
                    setStep(4);
                    await runMigration();
                    await new Promise(r => setTimeout(r, 400));
                    setStep(5);
                }
                break;
            case 5:
                await runInstall();
                break;
        }
    });
}

const btnPrev = document.getElementById('btnPrev');
if (btnPrev) {
    btnPrev.addEventListener('click', () => { if (currentStep > 1) setStep(currentStep - 1); });
}

document.addEventListener('DOMContentLoaded', () => {
    if (!IS_INSTALLED) {
        buildDots();
        detectLocalZips();
    } else if (IS_LOGGED_IN) {
        detectLocalZips();
    }
});
</script>
</body>
</html>

