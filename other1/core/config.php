<?php
/**
 * VladInc Ecosystem - Global Configuration
 * Domain: vladinc.ru
 */

if (!defined('VLADINC_INIT')) {
    define('VLADINC_INIT', true);
}

// Set error reporting (switch to 0 in heavy production if preferred)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 days
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Database Credentials (Auto-detected or configure for your shared hosting)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'vladinc_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Ecosystem Branding & Metadata
define('APP_NAME', 'VladInc');
define('APP_TAGLINE', 'Единая экосистема сервисов и общения');
define('APP_DOMAIN', 'vladinc.ru');
define('APP_VERSION', '1.0.0');

// Base URL Auto-detection
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'vladinc.ru';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$baseUrl = rtrim($protocol . $host . $scriptDir, '/\\');
// If script is in root or rewrite
define('BASE_URL', $baseUrl);

// Paths
define('ROOT_PATH', dirname(__DIR__));
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('CORE_PATH', ROOT_PATH . '/core');
define('MODULES_PATH', ROOT_PATH . '/modules');
define('TEMPLATES_PATH', ROOT_PATH . '/templates');

// Timezone
date_default_timezone_set('Europe/Moscow');
