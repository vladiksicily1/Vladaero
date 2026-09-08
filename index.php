<?php
$pageTitle = 'Всемирный Авиационный Портал';
require_once __DIR__ . '/includes/header.php';

// Fetch Aircraft of the day
$aircraftTable = Database::tableName('aircraft');
$featuredAircraft = Database::isConfigured() ? Database::fetchOne("SELECT a.*, m.name as manufacturer_name, s.max_range_km, s.cruise_speed_kmh, s.passengers_max FROM `{$aircraftTable}` a LEFT JOIN `" . Database::tableName('manufacturers') . "` m ON a.manufacturer_id = m.id LEFT JOIN `" . Database::tableName('aircraft_specs') . "` s ON a.id = s.aircraft_id ORDER BY a.id ASC LIMIT 1") : null;

// Fetch Recent Spotter Photos
$photosTable = Database::tableName('photos');
$photos = Database::isConfigured() ? Database::fetchAll("SELECT p.*, u.username, a.model_name FROM `{$photosTable}` p LEFT JOIN `" . Database::tableName('users') . "` u ON p.user_id = u.id LEFT JOIN `{$aircraftTable}` a ON p.aircraft_id = a.id WHERE p.status = 'approved' ORDER BY p.id DESC LIMIT 6") : [];

// Fetch Recent Articles
$articlesTable = Database::tableName('articles');
$articles = Database::isConfigured() ? Database::fetchAll("SELECT a.*, c.name_ru as category_name FROM `{$articlesTable}` a LEFT JOIN `" . Database::tableName('article_categories') . "` c ON a.category_id = c.id WHERE a.is_published = 1 ORDER BY a.id DESC LIMIT 3") : [];
?>

<!-- Hero Section -->
<section class="relative overflow-hidden pt-8 pb-16 lg:py-24">
    <!-- Ambient Background Glow -->
    <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[350px] bg-sky-600/15 blur-[120px] rounded-full pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div class="text-center max-w-3xl mx-auto">
            <!-- HUD Status Pill -->
            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-slate-900/80 border border-sky-500/30 text-xs font-mono text-sky-400 mb-6 shadow-inner">
                <span class="w-2 h-2 rounded-full bg-sky-400 animate-pulse"></span>
                <span>VLADAERO AVIATION NETWORK // ONLINE</span>
            </div>

            <h1 class="text-4xl sm:text-6xl font-black text-white tracking-tight leading-tight sm:leading-none mb-6">
                Вселенная авиации в <span class="text-transparent bg-clip-text bg-gradient-to-r from-sky-400 via-cyan-300 to-blue-500">одном клике</span>
            </h1>

            <p class="text-base sm:text-lg text-slate-300 leading-relaxed mb-8">
                Интерактивная энциклопедия самолетов, живой радар полетов ADS-B, русскоязычный декодер METAR/TAF, летные калькуляторы E6B и сообщество авиаторов.
            </p>

            <!-- Search Bar Form Trigger -->
            <div class="max-w-xl mx-auto mb-10">
                <button onclick="openSpotlightSearch()" class="w-full bg-slate-900/90 border border-slate-700 hover:border-sky-500/80 rounded-2xl p-4 text-left shadow-2xl flex items-center justify-between text-slate-400 group transition">
                    <div class="flex items-center space-x-3">
                        <i data-lucide="search" class="w-5 h-5 text-sky-400 group-hover:scale-110 transition"></i>
                        <span class="text-sm">Поиск по модели, ICAO, аэропорту или термину...</span>
                    </div>
                    <kbd class="hidden sm:inline-block font-mono text-xs bg-slate-800 border border-slate-700 px-2 py-1 rounded text-sky-400">Ctrl+K</kbd>
                </button>
            </div>

            <!-- Quick Hubs Weather Ticker -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 max-w-3xl mx-auto text-xs">
                <a href="<?= url('/weather.php?icao=UUEE') ?>" class="va-card p-3 text-left hover:border-sky-500/50 transition block">
                    <div class="flex items-center justify-between font-mono font-bold">
                        <span class="text-slate-200">SVO / UUEE</span>
                        <span class="text-emerald-400">VFR</span>
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1 truncate">Москва Шереметьево</div>
                </a>
                <a href="<?= url('/weather.php?icao=ULLI') ?>" class="va-card p-3 text-left hover:border-sky-500/50 transition block">
                    <div class="flex items-center justify-between font-mono font-bold">
                        <span class="text-slate-200">LED / ULLI</span>
                        <span class="text-emerald-400">VFR</span>
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1 truncate">Санкт-Петербург Пулково</div>
                </a>
                <a href="<?= url('/weather.php?icao=EGLL') ?>" class="va-card p-3 text-left hover:border-sky-500/50 transition block">
                    <div class="flex items-center justify-between font-mono font-bold">
                        <span class="text-slate-200">LHR / EGLL</span>
                        <span class="text-sky-400">MVFR</span>
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1 truncate">Лондон Хитроу</div>
                </a>
                <a href="<?= url('/weather.php?icao=KJFK') ?>" class="va-card p-3 text-left hover:border-sky-500/50 transition block">
                    <div class="flex items-center justify-between font-mono font-bold">
                        <span class="text-slate-200">JFK / KJFK</span>
                        <span class="text-emerald-400">VFR</span>
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1 truncate">Нью-Йорк Кеннеди</div>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Core Portals Grid -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- Card 1: Live Radar -->
        <a href="<?= url('/radar.php') ?>" class="va-card p-6 flex flex-col justify-between group relative overflow-hidden">
            <div class="absolute -top-12 -right-12 w-36 h-36 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition"></div>
            <div>
                <div class="w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 mb-4 group-hover:scale-110 transition">
                    <i data-lucide="radar" class="w-6 h-6"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Радар рейсов в реальном времени</h3>
                <p class="text-xs text-slate-400 leading-relaxed mb-4">
                    Отслеживание гражданских бортов на карте мира, эшелоны, путевая скорость, траектории полета и позывные рейсов по данным ADS-B.
                </p>
            </div>
            <div class="flex items-center space-x-2 text-xs font-mono font-bold text-emerald-400">
                <span>ОТКРЫТЬ РАДАР</span>
                <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition"></i>
            </div>
        </a>

        <!-- Card 2: METAR Weather Decoder -->
        <a href="<?= url('/weather.php') ?>" class="va-card p-6 flex flex-col justify-between group relative overflow-hidden">
            <div class="absolute -top-12 -right-12 w-36 h-36 bg-amber-500/10 rounded-full blur-2xl group-hover:bg-amber-500/20 transition"></div>
            <div>
                <div class="w-12 h-12 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-amber-400 mb-4 group-hover:scale-110 transition">
                    <i data-lucide="cloud-lightning" class="w-6 h-6"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Авиационная погода и METAR</h3>
                <p class="text-xs text-slate-400 leading-relaxed mb-4">
                    Моментальный перевод сводок NOAA METAR/TAF на русский язык: порывы ветра, нижний край облачности, VFR/IFR минимумы и озвучка ATIS.
                </p>
            </div>
            <div class="flex items-center space-x-2 text-xs font-mono font-bold text-amber-400">
                <span>ДЕКОДИРОВАТЬ СВОДКУ</span>
                <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition"></i>
            </div>
        </a>

        <!-- Card 3: E6B Flight Computers -->
        <a href="<?= url('/calculators.php') ?>" class="va-card p-6 flex flex-col justify-between group relative overflow-hidden">
            <div class="absolute -top-12 -right-12 w-36 h-36 bg-sky-500/10 rounded-full blur-2xl group-hover:bg-sky-500/20 transition"></div>
            <div>
                <div class="w-12 h-12 rounded-xl bg-sky-500/10 border border-sky-500/30 flex items-center justify-center text-sky-400 mb-4 group-hover:scale-110 transition">
                    <i data-lucide="calculator" class="w-6 h-6"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Комплекс калькуляторов E6B</h3>
                <p class="text-xs text-slate-400 leading-relaxed mb-4">
                    Расчет бокового ветра по полосе, плотностная высота, истинная скорость TAS, число Маха, снижение TOD и конвертер авиатоплива.
                </p>
            </div>
            <div class="flex items-center space-x-2 text-xs font-mono font-bold text-sky-400">
                <span>ПЕРЕЙТИ К РАСЧЕТАМ</span>
                <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition"></i>
            </div>
        </a>

    </div>
</section>

<!-- Featured Aircraft & 3D Showcase -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="va-card p-6 sm:p-10 relative overflow-hidden">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-center">
            <div>
                <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-lg bg-sky-500/10 border border-sky-500/30 text-xs font-mono text-sky-400 mb-4">
                    <span>САМОЛЕТ ДНЯ // FEATURED AIRCRAFT</span>
                </div>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-white mb-4">
                    <?= e($featuredAircraft['model_name'] ?? 'Airbus A320neo') ?>
                </h2>
                <p class="text-sm text-slate-300 leading-relaxed mb-6">
                    <?= e($featuredAircraft['short_desc'] ?? 'Узкофюзеляжный лайнер нового поколения с двигателями CFM LEAP-1A и законцовками Sharklets.') ?>
                </p>

                <!-- Specs Grid -->
                <div class="grid grid-cols-3 gap-4 mb-8 font-mono">
                    <div class="p-3 rounded-xl bg-slate-950/80 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Дальность</div>
                        <div class="text-base font-bold text-sky-400"><?= formatNumber($featuredAircraft['max_range_km'] ?? 6500) ?> км</div>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-950/80 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Скорость</div>
                        <div class="text-base font-bold text-emerald-400"><?= formatNumber($featuredAircraft['cruise_speed_kmh'] ?? 828) ?> км/ч</div>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-950/80 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Вместимость</div>
                        <div class="text-base font-bold text-amber-400"><?= formatNumber($featuredAircraft['passengers_max'] ?? 194) ?> пасс.</div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-4">
                    <a href="<?= url('/aircraft.php?slug=' . ($featuredAircraft['slug'] ?? 'airbus-a320neo')) ?>" class="bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 px-6 rounded-xl text-xs font-mono transition shadow-lg">
                        Полные характеристики ЛТХ
                    </a>
                    <a href="<?= url('/3d.php') ?>" class="bg-slate-800 hover:bg-slate-700 text-white font-bold py-3 px-6 rounded-xl text-xs font-mono border border-slate-700 transition flex items-center space-x-2">
                        <i data-lucide="box" class="w-4 h-4 text-purple-400"></i>
                        <span>3D Просмотр модели</span>
                    </a>
                </div>
            </div>

            <!-- Visual 3D Preview Box -->
            <div class="relative h-72 sm:h-96 rounded-2xl bg-gradient-to-b from-slate-900 to-slate-950 border border-slate-800 flex items-center justify-center p-6 text-center overflow-hidden group">
                <div class="text-7xl sm:text-9xl transform -rotate-12 group-hover:scale-110 group-hover:rotate-0 transition duration-500">
                    ✈️
                </div>
                <div class="absolute bottom-4 left-4 right-4 p-3 bg-slate-900/90 backdrop-blur-md rounded-xl border border-slate-800 text-xs text-slate-300 flex items-center justify-between font-mono">
                    <span>Интерактивный WebGL Viewer</span>
                    <span class="text-sky-400 font-bold">Three.js Engine</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Spotting Hub Carousel -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center space-x-2">
                <i data-lucide="camera" class="w-6 h-6 text-sky-400"></i>
                <span>Галерея Авиаспоттинга</span>
            </h2>
            <p class="text-xs text-slate-400 mt-1">Лучшие кадры мировых и отечественных авиационных фотографов с EXIF данными</p>
        </div>
        <a href="<?= url('/spotting.php') ?>" class="text-xs font-mono text-sky-400 hover:underline flex items-center space-x-1">
            <span>Все фотографии</span>
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
        </a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Sample Spotting Card 1 -->
        <div class="va-card overflow-hidden group">
            <div class="h-48 bg-slate-900 relative overflow-hidden flex items-center justify-center">
                <div class="text-5xl text-slate-600">🛫</div>
                <div class="absolute top-3 right-3 px-2 py-1 rounded bg-slate-950/80 text-[10px] font-mono text-sky-400 border border-slate-800">
                    RA-89010
                </div>
            </div>
            <div class="p-4">
                <div class="font-bold text-sm text-slate-100 mb-1">Superjet 100 на глиссаде ВПП 24L</div>
                <div class="text-xs text-slate-400 flex items-center justify-between font-mono">
                    <span>Шереметьево (SVO)</span>
                    <span class="text-slate-500">1/640s • f/5.6 • ISO 200</span>
                </div>
            </div>
        </div>

        <!-- Sample Spotting Card 2 -->
        <div class="va-card overflow-hidden group">
            <div class="h-48 bg-slate-900 relative overflow-hidden flex items-center justify-center">
                <div class="text-5xl text-slate-600">🛬</div>
                <div class="absolute top-3 right-3 px-2 py-1 rounded bg-slate-950/80 text-[10px] font-mono text-sky-400 border border-slate-800">
                    VP-BOS
                </div>
            </div>
            <div class="p-4">
                <div class="font-bold text-sm text-slate-100 mb-1">Boeing 777-300ER на касании</div>
                <div class="text-xs text-slate-400 flex items-center justify-between font-mono">
                    <span>Пулково (LED)</span>
                    <span class="text-slate-500">1/1000s • f/6.3 • ISO 160</span>
                </div>
            </div>
        </div>

        <!-- Sample Spotting Card 3 -->
        <div class="va-card overflow-hidden group">
            <div class="h-48 bg-slate-900 relative overflow-hidden flex items-center justify-center">
                <div class="text-5xl text-slate-600">✈️</div>
                <div class="absolute top-3 right-3 px-2 py-1 rounded bg-slate-950/80 text-[10px] font-mono text-sky-400 border border-slate-800">
                    F-WWOW
                </div>
            </div>
            <div class="p-4">
                <div class="font-bold text-sm text-slate-100 mb-1">Airbus A350-900 на закате</div>
                <div class="text-xs text-slate-400 flex items-center justify-between font-mono">
                    <span>Домодедово (DME)</span>
                    <span class="text-slate-500">1/320s • f/4.0 • ISO 400</span>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
