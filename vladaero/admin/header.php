<?php
declare(strict_types=1);

namespace VladAero;

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (!Auth::check() || (!Auth::isAdmin() && !Auth::isModerator() && !Auth::isScreener())) {
    header('Location: ../login.php');
    exit;
}

$currentUser = Auth::user();
$pendingPhotosCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_photos` WHERE `status` = 'pending'");
?>
<!DOCTYPE html>
<html lang="ru" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($adminTitle ?? 'Панель управления') ?> — VladAero Admin</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        .glass-admin {
            background: rgba(11, 19, 43, 0.9);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(56, 189, 248, 0.15);
        }
        .glass-card {
            background: rgba(28, 37, 65, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 font-sans min-h-screen flex flex-col antialiased">

<!-- Admin Top Bar -->
<header class="sticky top-0 z-40 glass-admin border-b border-sky-500/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            
            <div class="flex items-center space-x-4">
                <a href="index.php" class="flex items-center space-x-2.5">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-amber-500 to-orange-600 flex items-center justify-center text-slate-950 font-bold shadow-lg shadow-amber-500/20">
                        <i data-lucide="shield" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <span class="text-base font-bold font-mono text-white">VLADAERO <span class="text-amber-400">COMMAND</span></span>
                        <div class="text-[9px] font-mono text-amber-400/80">Центр управления полётами</div>
                    </div>
                </a>
            </div>

            <!-- Admin Nav Links -->
            <nav class="hidden lg:flex items-center space-x-1 text-xs font-mono text-slate-300">
                <a href="index.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="layout-dashboard" class="w-4 h-4 text-sky-400"></i>
                    <span>Дашборд</span>
                </a>
                <a href="photos.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-amber-400 transition flex items-center space-x-1.5 relative">
                    <i data-lucide="camera" class="w-4 h-4 text-amber-400"></i>
                    <span>Скрининг фото</span>
                    <?php if ($pendingPhotosCount > 0): ?>
                        <span class="px-1.5 py-0.5 rounded-full bg-rose-500 text-white text-[9px] font-bold"><?= $pendingPhotosCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="aircraft.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="plane" class="w-4 h-4 text-sky-400"></i>
                    <span>Самолёты</span>
                </a>
                <a href="airports.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="building-2" class="w-4 h-4 text-emerald-400"></i>
                    <span>Аэропорты</span>
                </a>
                <a href="articles.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="newspaper" class="w-4 h-4 text-cyan-400"></i>
                    <span>Статьи</span>
                </a>
                <a href="ai_agent.php" class="px-3 py-2 rounded-lg bg-indigo-600/30 hover:bg-indigo-600/50 text-indigo-300 border border-indigo-500/30 transition flex items-center space-x-1.5 font-bold">
                    <i data-lucide="bot" class="w-4 h-4 text-indigo-400"></i>
                    <span>Супер-Агент ИИ</span>
                </a>
                <a href="quizzes.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="help-circle" class="w-4 h-4 text-purple-400"></i>
                    <span>Тесты</span>
                </a>
                <a href="users.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="users" class="w-4 h-4 text-rose-400"></i>
                    <span>Экипаж</span>
                </a>
                <a href="settings.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="settings" class="w-4 h-4 text-slate-400"></i>
                    <span>Настройки</span>
                </a>
            </nav>

            <div class="flex items-center space-x-3">
                <a href="../index.php" target="_blank" class="px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-300 hover:text-white text-xs font-mono transition flex items-center space-x-1">
                    <span>На сайт</span>
                    <i data-lucide="external-link" class="w-3 h-3"></i>
                </a>
            </div>

        </div>
    </div>
</header>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
