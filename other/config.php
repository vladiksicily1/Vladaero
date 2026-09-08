<?php
/**
 * ShibaLingo - Configuration
 * Project: ShibaLingo (Language Learning Platform with Shiba Inu Mascot & Vladikish Conlang)
 */

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Credentials (Configure for your Shared Hosting MySQL)
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'shibalingo_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX', 'sl_'); // Customizable table prefix for shared MySQL DBs

// SQLite Fallback (Allows running instantly without MySQL setup)
define('USE_SQLITE_FALLBACK', true);
define('SQLITE_PATH', __DIR__ . '/shibalingo.sqlite');

// NVIDIA NIM AI API Settings (build.nvidia.com)
// You can enter your API key here or via the web Settings page (stored in DB/session)
define('NVIDIA_API_URL', 'https://integrate.api.nvidia.com/v1/chat/completions');
define('DEFAULT_NVIDIA_MODEL', 'meta/llama-3.3-70b-instruct');
// Alternative models on build.nvidia.com:
// 'deepseek-ai/deepseek-r1'
// 'qwen/qwen2.5-coder-32b-instruct'
// 'meta/llama-3.2-3b-instruct'

// App Constants
define('APP_NAME', 'ShibaLingo');
define('APP_TAGLINE', 'Учи языки и тайный язык Vladikish вместе с Шиба-Ину!');
define('DEFAULT_HEARTS', 5);
define('MAX_HEARTS', 5);
define('DEFAULT_LANGUAGE', 'vladikish');
