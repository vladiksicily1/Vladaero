<?php
if (!defined('VLADAERO_ROOT')) {
    define('VLADAERO_ROOT', dirname(__DIR__));
}
require_once VLADAERO_ROOT . '/includes/functions.php';
checkAndHandleMaintenance();

$currentUser = Auth::getCurrentUser();
$siteName = getSetting('site_name', 'VladAero');
$siteTagline = getSetting('site_tagline', 'Всемирный Авиационный Портал');
$pageTitle = isset($pageTitle) ? "{$pageTitle} — {$siteName}" : "{$siteName} — {$siteTagline}";
$metaDescription = $metaDescription ?? 'Всемирная авиационная база знаний: самолеты, радар полетов в реальном времени, метеорологические сводки METAR/TAF, летные калькуляторы E6B, 3D-модели и споттинг.';
?>
<!DOCTYPE html>
<html lang="ru" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
    
    <!-- Open Graph / Social Media -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:url" content="<?= e($_SERVER['REQUEST_URI'] ?? url('/')) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">

    <!-- PWA -->
    <link rel="manifest" href="<?= asset('/manifest.json') ?>">
    <meta name="theme-color" content="#0284c7">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>✈️</text></svg>">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            900: '#0c4a6e',
                            950: '#082f49',
                        },
                        cockpit: {
                            bg: '#06090e',
                            card: '#0d131f',
                            border: '#1e293b',
                            cyan: '#00f2fe',
                            amber: '#ffb703',
                            green: '#10b981'
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= asset('/assets/css/styles.css') ?>">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-cockpit-bg text-slate-100 flex flex-col min-h-screen">

    <!-- Top Zulu Status Bar -->
    <div class="bg-slate-950 border-b border-slate-900/80 text-xs py-1 px-4 text-slate-400">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="flex items-center space-x-1.5 font-mono text-sky-400 font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span id="zulu-clock">Z-TIME --:--:-- UTC</span>
                </div>
                <span class="text-slate-600 hidden sm:inline">|</span>
                <div class="hidden sm:flex items-center space-x-2 text-slate-400">
                    <span>SVO: <strong class="text-slate-300">UUEE</strong></span>
                    <span>LED: <strong class="text-slate-300">ULLI</strong></span>
                    <span>LHR: <strong class="text-slate-300">EGLL</strong></span>
                    <span>JFK: <strong class="text-slate-300">KJFK</strong></span>
                </div>
            </div>
            <div class="flex items-center space-x-3">
                <button onclick="toggleTheme()" class="hover:text-sky-400 transition flex items-center space-x-1" title="Сменить тему">
                    <i data-lucide="sun-moon" class="w-3.5 h-3.5"></i>
                    <span class="hidden md:inline">Тема</span>
                </button>
                <span class="text-slate-600">|</span>
                <button onclick="openSpotlightSearch()" class="flex items-center space-x-1 text-slate-400 hover:text-sky-400 transition">
                    <kbd class="font-mono bg-slate-900 px-1.5 py-0.5 rounded border border-slate-800 text-[10px] text-sky-400">⌘K</kbd>
                    <span class="hidden md:inline">Поиск</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Main Navigation Header -->
    <header class="sticky top-0 z-40 bg-slate-950/90 backdrop-blur-md border-b border-slate-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <a href="<?= url('/') ?>" class="flex items-center space-x-3 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-sky-600 to-cyan-400 flex items-center justify-center shadow-lg shadow-sky-500/20 group-hover:scale-105 transition">
                        <i data-lucide="plane" class="w-5 h-5 text-white transform -rotate-45"></i>
                    </div>
                    <div>
                        <div class="text-lg font-bold tracking-tight text-white flex items-center">
                            Vlad<span class="text-sky-400">Aero</span>
                        </div>
                        <div class="text-[10px] text-slate-400 tracking-wider uppercase font-mono">Aviation Portal</div>
                    </div>
                </a>

                <!-- Desktop Nav -->
                <nav class="hidden lg:flex items-center space-x-1 text-sm font-medium text-slate-300">
                    <a href="<?= url('/aircraft.php') ?>" class="px-3 py-2 rounded-lg hover:text-sky-400 hover:bg-slate-900/60 transition flex items-center space-x-1.5">
                        <i data-lucide="plane" class="w-4 h-4"></i>
                        <span>Самолеты</span>
                    </a>
                    <a href="<?= url('/radar.php') ?>" class="px-3 py-2 rounded-lg hover:text-sky-400 hover:bg-slate-900/60 transition flex items-center space-x-1.5">
                        <i data-lucide="radar" class="w-4 h-4 text-emerald-400"></i>
                        <span>Радар рейсов</span>
                    </a>
                    <a href="<?= url('/weather.php') ?>" class="px-3 py-2 rounded-lg hover:text-sky-400 hover:bg-slate-900/60 transition flex items-center space-x-1.5">
                        <i data-lucide="cloud-sun" class="w-4 h-4 text-amber-400"></i>
                        <span>METAR / Погода</span>
                    </a>
                    <a href="<?= url('/airports.php') ?>" class="px-3 py-2 rounded-lg hover:text-sky-400 hover:bg-slate-900/60 transition flex items-center space-x-1.5">
                        <i data-lucide="map-pin" class="w-4 h-4"></i>
                        <span>Аэропорты</span>
                    </a>
                    <a href="<?= url('/calculators.php') ?>" class="px-3 py-2 rounded-lg hover:text-sky-400 hover:bg-slate-900/60 transition flex items-center space-x-1.5">
                        <i data-lucide="calculator" class="w-4 h-4 text-cyan-400"></i>
                        <span>E6B Калькуляторы</span>
                    </a>
                    <a href="<?= url('/3d.php') ?>" class="px-3 py-2 rounded-lg hover:text-sky-400 hover:bg-slate-900/60 transition flex items-center space-x-1.5">
                        <i data-lucide="box" class="w-4 h-4 text-purple-400"></i>
                        <span>3D Модели</span>
                    </a>
                    <a href="<?= url('/spotting.php') ?>" class="px-3 py-2 rounded-lg hover:text-sky-400 hover:bg-slate-900/60 transition flex items-center space-x-1.5">
                        <i data-lucide="camera" class="w-4 h-4"></i>
                        <span>Споттинг</span>
                    </a>
                    <a href="<?= url('/training.php') ?>" class="px-3 py-2 rounded-lg hover:text-sky-400 hover:bg-slate-900/60 transition flex items-center space-x-1.5">
                        <i data-lucide="graduation-cap" class="w-4 h-4"></i>
                        <span>Обучение</span>
                    </a>
                </nav>

                <!-- User & Auth Area -->
                <div class="flex items-center space-x-3">
                    <?php if ($currentUser): ?>
                        <div class="relative group">
                            <button class="flex items-center space-x-2.5 p-1.5 pr-3 rounded-xl bg-slate-900 border border-slate-800 hover:border-sky-500/50 transition">
                                <div class="w-7 h-7 rounded-lg bg-sky-600/30 border border-sky-500/40 flex items-center justify-center font-bold text-sky-400 text-xs">
                                    <?= mb_strtoupper(mb_substr($currentUser['username'], 0, 1)) ?>
                                </div>
                                <div class="text-left hidden sm:block">
                                    <div class="text-xs font-semibold text-slate-200"><?= e($currentUser['username']) ?></div>
                                    <div class="text-[10px] text-sky-400 font-mono"><?= e($currentUser['rank_title']) ?> (<?= formatNumber($currentUser['xp_points']) ?> XP)</div>
                                </div>
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-slate-400"></i>
                            </button>
                            <!-- Dropdown -->
                            <div class="absolute right-0 mt-2 w-56 bg-slate-900 border border-slate-800 rounded-xl shadow-2xl py-2 hidden group-hover:block group-focus-within:block z-50">
                                <div class="px-4 py-2 border-b border-slate-800 text-xs">
                                    <div class="text-slate-400">Вход выполнен как</div>
                                    <div class="font-bold text-slate-100"><?= e($currentUser['email']) ?></div>
                                </div>
                                <a href="<?= url('/profile.php') ?>" class="flex items-center space-x-2 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-sky-400">
                                    <i data-lucide="user" class="w-4 h-4"></i>
                                    <span>Мой профиль</span>
                                </a>
                                <a href="<?= url('/logbook.php') ?>" class="flex items-center space-x-2 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-sky-400">
                                    <i data-lucide="book-open" class="w-4 h-4"></i>
                                    <span>Мой логбук полетов</span>
                                </a>
                                <a href="<?= url('/soundboard.php') ?>" class="flex items-center space-x-2 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-sky-400">
                                    <i data-lucide="volume-2" class="w-4 h-4"></i>
                                    <span>Cockpit Soundboard</span>
                                </a>
                                <?php if (Auth::hasRole('admin', 'editor', 'moderator')): ?>
                                    <a href="<?= url('/admin/') ?>" class="flex items-center space-x-2 px-4 py-2 text-sm text-amber-400 hover:bg-slate-800 font-semibold border-t border-slate-800 mt-1">
                                        <i data-lucide="shield" class="w-4 h-4"></i>
                                        <span>Панель управления</span>
                                    </a>
                                <?php endif; ?>
                                <a href="<?= url('/logout.php') ?>" class="flex items-center space-x-2 px-4 py-2 text-sm text-red-400 hover:bg-slate-800 border-t border-slate-800 mt-1">
                                    <i data-lucide="log-out" class="w-4 h-4"></i>
                                    <span>Выйти</span>
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?= url('/login.php') ?>" class="text-sm font-medium text-slate-300 hover:text-white px-3 py-2">Вход</a>
                        <a href="<?= url('/register.php') ?>" class="text-sm font-medium bg-sky-600 hover:bg-sky-500 text-white px-3.5 py-1.5 rounded-lg shadow-sm shadow-sky-600/30 transition">Регистрация</a>
                    <?php endif; ?>

                    <!-- Mobile Menu Button -->
                    <button onclick="toggleMobileMenu()" class="lg:hidden p-2 rounded-lg bg-slate-900 text-slate-400 hover:text-white">
                        <i data-lucide="menu" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Nav Menu -->
        <div id="mobile-menu" class="hidden lg:hidden bg-slate-950 border-b border-slate-800 px-4 py-4 space-y-2 text-sm">
            <a href="<?= url('/aircraft.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-slate-200">✈️ Самолеты</a>
            <a href="<?= url('/radar.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-emerald-400">📡 Радар полетов</a>
            <a href="<?= url('/weather.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-amber-400">⛅ Метеосводки METAR</a>
            <a href="<?= url('/airports.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-slate-200">📍 Аэропорты</a>
            <a href="<?= url('/calculators.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-cyan-400">🧮 Калькуляторы E6B</a>
            <a href="<?= url('/3d.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-purple-400">🧊 3D Модели</a>
            <a href="<?= url('/soundboard.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-slate-200">🔊 Cockpit Soundboard</a>
            <a href="<?= url('/spotting.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-slate-200">📷 Авиаспоттинг</a>
            <a href="<?= url('/training.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-slate-200">🎓 Обучение и теория</a>
            <a href="<?= url('/quizzes.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-slate-200">🏆 Викторины и тесты</a>
            <a href="<?= url('/glossary.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-slate-200">📖 Словарь терминов</a>
            <a href="<?= url('/logbook.php') ?>" class="block px-3 py-2 rounded-lg hover:bg-slate-900 text-slate-200">📋 Личный логбук</a>
        </div>
    </header>

    <main class="flex-grow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <?= renderFlash() ?>
        </div>
