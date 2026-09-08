<?php
if (!defined('VLADAERO_ROOT')) {
    define('VLADAERO_ROOT', dirname(__DIR__));
}
require_once VLADAERO_ROOT . '/includes/functions.php';
require_once VLADAERO_ROOT . '/includes/auth.php';

Auth::requireRole('admin', 'editor', 'moderator');
$currentUser = Auth::getCurrentUser();
$siteName = getSetting('site_name', 'VladAero');
$adminTitle = isset($adminTitle) ? "{$adminTitle} — Панель управления" : "Панель управления — {$siteName}";
?>
<!DOCTYPE html>
<html lang="ru" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($adminTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: { 500: '#0ea5e9', 600: '#0284c7' },
                        cockpit: { bg: '#06090e', card: '#0d131f', border: '#1e293b' }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= asset('/assets/css/styles.css') ?>">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col font-sans">

    <!-- Top Admin Bar -->
    <header class="bg-slate-900 border-b border-slate-800 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Brand -->
                <div class="flex items-center space-x-4">
                    <a href="<?= url('/admin/') ?>" class="flex items-center space-x-2 text-white font-bold text-base">
                        <div class="w-8 h-8 rounded-lg bg-sky-600 flex items-center justify-center text-white">
                            <i data-lucide="shield" class="w-4 h-4"></i>
                        </div>
                        <span>Vlad<span class="text-sky-400">Aero</span> <span class="text-xs text-amber-400 font-mono font-bold px-1.5 py-0.5 rounded bg-amber-950 border border-amber-800 ml-1">ADMIN CMS</span></span>
                    </a>
                </div>

                <!-- Admin Navigation Links -->
                <nav class="hidden md:flex items-center space-x-1 text-xs font-mono">
                    <a href="<?= url('/admin/') ?>" class="px-3 py-2 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">Дашборд</a>
                    <a href="<?= url('/admin/aircraft.php') ?>" class="px-3 py-2 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">Самолеты</a>
                    <a href="<?= url('/admin/airports.php') ?>" class="px-3 py-2 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">Аэропорты</a>
                    <a href="<?= url('/admin/photos.php') ?>" class="px-3 py-2 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">Споттинг</a>
                    <a href="<?= url('/admin/articles.php') ?>" class="px-3 py-2 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">Статьи</a>
                    <a href="<?= url('/admin/quizzes.php') ?>" class="px-3 py-2 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">Викторины</a>
                    <a href="<?= url('/admin/users.php') ?>" class="px-3 py-2 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">Экипаж</a>
                    <a href="<?= url('/admin/ai_agent.php') ?>" class="px-3 py-2 rounded-lg bg-purple-950/60 border border-purple-800 text-purple-300 hover:text-white transition flex items-center space-x-1">
                        <i data-lucide="bot" class="w-3.5 h-3.5 text-purple-400"></i>
                        <span>AI Agent</span>
                    </a>
                    <a href="<?= url('/admin/settings.php') ?>" class="px-3 py-2 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">Настройки</a>
                </nav>

                <!-- Right Return link -->
                <div class="flex items-center space-x-3">
                    <a href="<?= url('/') ?>" class="text-xs font-mono bg-slate-800 hover:bg-slate-700 text-sky-400 px-3 py-1.5 rounded-lg border border-slate-700 transition flex items-center space-x-1">
                        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">На публичный сайт</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?= renderFlash() ?>
