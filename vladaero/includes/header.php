<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

// Check maintenance mode
if (get_setting('maintenance_mode', '0') === '1' && !Auth::isAdmin()) {
    if (!str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'login.php') && !str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'install.php')) {
        ?>
        <!DOCTYPE html>
        <html lang="ru" class="dark">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Регламентные работы — VladAero</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-950 text-slate-100 flex items-center justify-center min-h-screen p-4 font-mono">
            <div class="max-w-md w-full bg-slate-900/80 border border-amber-500/30 rounded-2xl p-8 text-center shadow-2xl backdrop-blur-xl">
                <div class="w-16 h-16 bg-amber-500/10 text-amber-400 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">⚠️</div>
                <h1 class="text-2xl font-bold text-amber-400 mb-2">Техническое обслуживание</h1>
                <p class="text-slate-400 text-sm mb-6">Портал VladAero находится на плановом регламентном обслуживании. Бортовые системы скоро вернутся в строй.</p>
                <a href="login.php" class="text-xs text-sky-400 hover:underline">Вход для экипажа (Администрация)</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

$currentUser = Auth::user();
$pageTitle = $pageTitle ?? get_setting('site_name', 'VladAero');
$pageDesc = $pageDesc ?? get_setting('site_description', 'Главный авиационный портал и энциклопедия');
$pageImage = $pageImage ?? 'assets/images/logo.png';
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
?>
<!DOCTYPE html>
<html lang="ru" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — VladAero</title>
    <meta name="description" content="<?= e($pageDesc) ?>">
    
    <!-- OpenGraph -->
    <meta property="og:title" content="<?= e($pageTitle) ?> — VladAero">
    <meta property="og:description" content="<?= e($pageDesc) ?>">
    <meta property="og:image" content="<?= e($pageImage) ?>">
    <meta property="og:url" content="<?= e($currentUrl) ?>">
    <meta property="og:type" content="website">

    <!-- PWA -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b132b">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        navy: {
                            800: '#1c2541',
                            900: '#0b132b',
                            950: '#060a17'
                        },
                        amber: {
                            500: '#f59e0b',
                            600: '#d97706'
                        },
                        hud: {
                            green: '#10b981',
                            cyan: '#0ea5e9',
                            amber: '#f59e0b',
                            red: '#ef4444'
                        }
                    },
                    fontFamily: {
                        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
                        sans: ['Inter', 'system-ui', 'sans-serif']
                    }
                }
            }
        }
    </script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Custom Aviation Styles -->
    <style>
        .glass-hud {
            background: rgba(11, 19, 43, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(56, 189, 248, 0.15);
        }
        .glass-card {
            background: rgba(28, 37, 65, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .hud-border {
            border: 1px solid rgba(14, 165, 233, 0.25);
            box-shadow: 0 0 15px rgba(14, 165, 233, 0.08);
        }
        .hud-text-glow {
            text-shadow: 0 0 8px rgba(56, 189, 248, 0.6);
        }
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #0b132b;
        }
        ::-webkit-scrollbar-thumb {
            background: #1c2541;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #0ea5e9;
        }
    </style>
</head>
<body class="bg-navy-950 text-slate-100 font-sans min-h-screen flex flex-col antialiased selection:bg-sky-500 selection:text-white">

<!-- Top HUD Navigation Bar -->
<header class="sticky top-0 z-40 glass-hud border-b border-sky-500/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            
            <!-- Brand & Logo -->
            <div class="flex items-center space-x-3">
                <a href="index.php" class="flex items-center space-x-2.5 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-sky-600 to-indigo-600 flex items-center justify-center shadow-lg shadow-sky-500/20 group-hover:scale-105 transition">
                        <i data-lucide="plane" class="w-5 h-5 text-white transform -rotate-45"></i>
                    </div>
                    <div>
                        <span class="text-xl font-bold font-mono tracking-wider text-white group-hover:text-sky-400 transition">VLAD<span class="text-amber-500">AERO</span></span>
                        <div class="text-[9px] font-mono text-sky-400/70 tracking-widest uppercase">Aviation Portal</div>
                    </div>
                </a>
            </div>

            <!-- Desktop Nav Links -->
            <nav class="hidden lg:flex items-center space-x-1 text-xs font-medium text-slate-300">
                <a href="aircraft.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="plane" class="w-3.5 h-3.5 text-sky-400"></i>
                    <span>Самолёты</span>
                </a>
                <a href="compare.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="scale" class="w-3.5 h-3.5 text-indigo-400"></i>
                    <span>Сравнение</span>
                </a>
                <a href="radar.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="radar" class="w-3.5 h-3.5 text-emerald-400 animate-pulse"></i>
                    <span>Радар</span>
                </a>
                <a href="spotting.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="camera" class="w-3.5 h-3.5 text-amber-400"></i>
                    <span>Споттинг</span>
                </a>
                <a href="airports.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="building-2" class="w-3.5 h-3.5 text-blue-400"></i>
                    <span>Аэропорты & METAR</span>
                </a>
                <a href="calculators.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="calculator" class="w-3.5 h-3.5 text-purple-400"></i>
                    <span>E6B Калькуляторы</span>
                </a>
                <a href="training.php" class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1.5">
                    <i data-lucide="graduation-cap" class="w-3.5 h-3.5 text-rose-400"></i>
                    <span>ВАК & Школа</span>
                </a>
                
                <!-- More Dropdown -->
                <div class="relative group">
                    <button class="px-3 py-2 rounded-lg hover:bg-white/5 hover:text-sky-400 transition flex items-center space-x-1">
                        <span>Ещё</span>
                        <i data-lucide="chevron-down" class="w-3 h-3"></i>
                    </button>
                    <div class="absolute right-0 mt-2 w-48 py-2 glass-hud rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 border border-sky-500/20">
                        <a href="quizzes.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                            <i data-lucide="help-circle" class="w-4 h-4 mr-2 text-amber-400"></i> Викторины
                        </a>
                        <a href="soundboard.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                            <i data-lucide="volume-2" class="w-4 h-4 mr-2 text-emerald-400"></i> Звуки & GPWS
                        </a>
                        <a href="logbook.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                            <i data-lucide="book-open" class="w-4 h-4 mr-2 text-sky-400"></i> Журнал полетов
                        </a>
                        <a href="boarding_pass.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                            <i data-lucide="ticket" class="w-4 h-4 mr-2 text-purple-400"></i> Посадочный талон
                        </a>
                        <a href="incidents.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                            <i data-lucide="alert-triangle" class="w-4 h-4 mr-2 text-rose-400"></i> Безопасность
                        </a>
                        <a href="glossary.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                            <i data-lucide="file-text" class="w-4 h-4 mr-2 text-indigo-400"></i> Глоссарий
                        </a>
                        <a href="articles.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                            <i data-lucide="newspaper" class="w-4 h-4 mr-2 text-cyan-400"></i> Новости & Статьи
                        </a>
                    </div>
                </div>
            </nav>

            <!-- Actions Right -->
            <div class="flex items-center space-x-3">
                <!-- Search Button (Ctrl+K) -->
                <button onclick="openGlobalSearch()" class="hidden sm:flex items-center space-x-2 px-3 py-1.5 rounded-lg bg-slate-900/60 border border-slate-700/60 text-slate-400 hover:text-white hover:border-sky-500/50 transition text-xs font-mono">
                    <i data-lucide="search" class="w-3.5 h-3.5"></i>
                    <span>Поиск...</span>
                    <kbd class="px-1.5 py-0.5 text-[10px] bg-slate-800 rounded border border-slate-700">Ctrl K</kbd>
                </button>

                <!-- Audio Switch / Mute -->
                <button id="soundToggleBtn" onclick="toggleGlobalSound()" class="p-2 rounded-lg bg-slate-900/60 border border-slate-700/60 text-slate-400 hover:text-amber-400 transition" title="Звуковые эффекты кабины">
                    <i data-lucide="volume-2" class="w-4 h-4" id="soundIcon"></i>
                </button>

                <!-- User Profile / Login -->
                <?php if ($currentUser): ?>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 p-1.5 rounded-xl bg-slate-900/80 border border-sky-500/30 hover:border-sky-400 transition">
                            <div class="w-7 h-7 rounded-lg bg-sky-600/30 border border-sky-400/40 flex items-center justify-center text-xs font-bold text-sky-300 font-mono">
                                <?= strtoupper(substr($currentUser['username'], 0, 2)) ?>
                            </div>
                            <span class="text-xs font-mono font-medium hidden md:inline text-slate-200"><?= e($currentUser['username']) ?></span>
                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20 font-mono hidden md:inline"><?= e($currentUser['rank_title']) ?></span>
                        </button>

                        <div class="absolute right-0 mt-2 w-56 py-2 glass-hud rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 border border-sky-500/20">
                            <div class="px-4 py-2 border-b border-white/5">
                                <div class="text-xs font-bold text-white"><?= e($currentUser['full_name'] ?: $currentUser['username']) ?></div>
                                <div class="text-[10px] font-mono text-sky-400"><?= e($currentUser['rank_title']) ?> • <?= (int)$currentUser['xp_points'] ?> XP</div>
                            </div>
                            
                            <a href="profile.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                                <i data-lucide="user" class="w-4 h-4 mr-2 text-sky-400"></i> Мой профиль & Ангар
                            </a>
                            <a href="logbook.php" class="flex items-center px-4 py-2 text-xs text-slate-300 hover:bg-sky-500/10 hover:text-sky-400">
                                <i data-lucide="book-open" class="w-4 h-4 mr-2 text-emerald-400"></i> Мой Flight Log
                            </a>

                            <?php if (Auth::isAdmin() || Auth::isModerator() || Auth::isScreener()): ?>
                                <div class="border-t border-white/5 my-1"></div>
                                <a href="admin/index.php" class="flex items-center px-4 py-2 text-xs text-amber-400 hover:bg-amber-500/10 font-bold">
                                    <i data-lucide="shield-check" class="w-4 h-4 mr-2 text-amber-400"></i> Панель управления
                                </a>
                            <?php endif; ?>

                            <div class="border-t border-white/5 my-1"></div>
                            <a href="logout.php" class="flex items-center px-4 py-2 text-xs text-rose-400 hover:bg-rose-500/10">
                                <i data-lucide="log-out" class="w-4 h-4 mr-2 text-rose-400"></i> Выход
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white font-medium text-xs shadow-lg shadow-sky-600/30 transition flex items-center space-x-1.5">
                        <i data-lucide="log-in" class="w-3.5 h-3.5"></i>
                        <span>Вход</span>
                    </a>
                <?php endif; ?>

                <!-- Mobile Menu Trigger -->
                <button onclick="toggleMobileNav()" class="lg:hidden p-2 rounded-lg bg-slate-900/60 border border-slate-700/60 text-slate-400 hover:text-white">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Nav Drawer -->
    <div id="mobileNav" class="hidden lg:hidden border-t border-sky-500/20 bg-navy-900/95 backdrop-blur-xl px-4 py-4 space-y-2 text-sm">
        <a href="aircraft.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="plane" class="w-4 h-4 text-sky-400"></i> <span>Энциклопедия самолётов</span>
        </a>
        <a href="compare.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="scale" class="w-4 h-4 text-indigo-400"></i> <span>Сравнение ВС</span>
        </a>
        <a href="radar.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="radar" class="w-4 h-4 text-emerald-400"></i> <span>Интерактивный радар</span>
        </a>
        <a href="spotting.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="camera" class="w-4 h-4 text-amber-400"></i> <span>Фотогалерея & Споттинг</span>
        </a>
        <a href="airports.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="building-2" class="w-4 h-4 text-blue-400"></i> <span>Аэропорты & METAR</span>
        </a>
        <a href="calculators.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="calculator" class="w-4 h-4 text-purple-400"></i> <span>E6B Калькуляторы</span>
        </a>
        <a href="training.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="graduation-cap" class="w-4 h-4 text-rose-400"></i> <span>Лётная школа & ВАК</span>
        </a>
        <a href="quizzes.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="help-circle" class="w-4 h-4 text-amber-400"></i> <span>Викторины & Тесты</span>
        </a>
        <a href="soundboard.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="volume-2" class="w-4 h-4 text-emerald-400"></i> <span>Звуки кабины & GPWS</span>
        </a>
        <a href="articles.php" class="flex items-center space-x-2 py-2 text-slate-300 hover:text-sky-400">
            <i data-lucide="newspaper" class="w-4 h-4 text-cyan-400"></i> <span>Статьи & Новости</span>
        </a>
    </div>
</header>

<!-- Main Wrapper -->
<main class="flex-1">
