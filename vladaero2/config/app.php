<?php
/**
 * VladAero — Main Configuration
 * Aviation Portal on PHP 8.x / MySQL
 */

return [
    // ─── Site Identity ──────────────────────────────────────────
    'name'        => 'VladAero',
    'tagline'     => 'Авиационный портал',
    'version'     => '1.0.0',
    'logo'        => 'assets/images/logo.png',
    'favicon'     => 'assets/images/favicon.ico',

    // ─── URLs ───────────────────────────────────────────────────
    'base_url'    => '',  // auto-detected
    'admin_path'  => 'admin',
    'api_path'    => 'api/v1',

    // ─── Locale / i18n ─────────────────────────────────────────
    'default_lang'   => 'ru',
    'available_langs' => ['ru', 'en'],

    // ─── Database (filled by installer) ─────────────────────────
    'db' => [
        'host'     => '',
        'name'     => '',
        'user'     => '',
        'pass'     => '',
        'charset'  => 'utf8mb4',
        'prefix'   => 'vld_',
    ],

    // ─── Paths (relative to project root) ───────────────────────
    'paths' => [
        'uploads'    => 'public/uploads',
        'cache'      => 'storage/cache',
        'logs'       => 'storage/logs',
        'sessions'   => 'storage/sessions',
        'backups'    => 'storage/backups',
    ],

    // ─── Security ──────────────────────────────────────────────
    'secret_key'     => '',  // random 64-char hex, set at install
    'csrf_enabled'   => true,
    'session_lifetime' => 7200, // 2 hours
    'rate_limit'     => [
        'enabled'  => true,
        'max_requests' => 60,
        'per_seconds'  => 60,
    ],

    // ─── Upload Limits ─────────────────────────────────────────
    'upload' => [
        'max_photo_size'   => 20 * 1024 * 1024, // 20 MB
        'max_photo_width'  => 8000,
        'max_photo_height' => 8000,
        'allowed_photo_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
        'thumbnail_width'  => 400,
        'thumbnail_height' => 300,
        'watermark_enabled' => true,
        'convert_to_webp'  => true,
    ],

    // ─── Pagination ─────────────────────────────────────────────
    'per_page' => [
        'default' => 24,
        'admin'   => 50,
        'search'  => 20,
    ],

    // ─── Telegram Bot ──────────────────────────────────────────
    'telegram' => [
        'bot_token' => '',
        'bot_username' => '',
        'webhook_url'  => '',
    ],

    // ─── AI Settings ───────────────────────────────────────────
    'ai' => [
        'enabled'        => true,
        'provider'       => 'openai_compatible',
        'base_url'       => '',
        'api_key'        => '',
        'model_id'       => '',
        'temperature'    => 0.7,
        'max_tokens'     => 4096,
        'streaming'      => true,
    ],

    // ─── Firecrawl (web search & URL extraction) ───────────────
    'firecrawl' => [
        'api_key'   => '',
        'base_url'  => 'https://api.firecrawl.dev/v1',
    ],

    // ─── Mail (no SMTP — Telegram only) ────────────────────────
    'mail' => [
        'enabled' => false,
    ],

    // ─── Caching ───────────────────────────────────────────────
    'cache' => [
        'driver'    => 'file',
        'ttl'       => 3600,    // 1 hour default
        'metar_ttl' => 300,     // 5 minutes for weather
    ],

    // ─── Page Blocks / Editor ──────────────────────────────────
    'editor' => [
        'autosave_interval' => 30, // seconds
        'block_types' => [
            'text', 'heading', 'image', 'gallery', 'video', 'embed',
            'aircraft_card', 'airport_card', 'airline_card',
            'metar_widget', 'map_widget', '3d_viewer', 'panorama_viewer',
            'ttx_table', 'timeline', 'quote', 'spoiler', 'audio_player',
            'quiz_inline', 'poll', 'cta_button', 'separator',
            'code_block', 'raw_html', 'comparison_card',
        ],
    ],

    // ─── Maintenance Mode ──────────────────────────────────────
    'maintenance' => false,

    // ─── Debug (set to false in production) ─────────────────────
    'debug' => false,
];
