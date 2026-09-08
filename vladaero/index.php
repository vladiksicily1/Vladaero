<?php
declare(strict_types=1);

namespace VladAero;

$pageTitle = 'Главный авиационный портал и энциклопедия';
require_once __DIR__ . '/includes/header.php';

// Fetch stats & featured items
$planesCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_aircraft` WHERE `deleted_at` IS NULL");
$airportsCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_airports`");
$photosCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_photos` WHERE `status` = 'approved'");
$usersCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_users`");

$featuredPlanes = DB::fetchAll("SELECT * FROM `va_aircraft` WHERE `deleted_at` IS NULL ORDER BY `views_count` DESC LIMIT 4");
$photoOfDay = DB::fetchOne("SELECT p.*, u.username, u.full_name, a.model_name, apt.name_ru AS airport_name FROM `va_photos` p JOIN `va_users` u ON p.user_id = u.id LEFT JOIN `va_aircraft` a ON p.aircraft_id = a.id LEFT JOIN `va_airports` apt ON p.airport_id = apt.id WHERE p.status = 'approved' ORDER BY p.is_photo_of_day DESC, p.likes_count DESC, p.id DESC LIMIT 1");
$breakingNews = DB::fetchAll("SELECT * FROM `va_articles` WHERE `is_published` = 1 ORDER BY `is_breaking` DESC, `id` DESC LIMIT 3");
$recentPhotos = DB::fetchAll("SELECT p.*, u.username, a.model_name FROM `va_photos` p JOIN `va_users` u ON p.user_id = u.id LEFT JOIN `va_aircraft` a ON p.aircraft_id = a.id WHERE p.status = 'approved' ORDER BY p.id DESC LIMIT 6");
?>

<!-- Hero Section with Glass Cockpit HUD Style -->
<div class="relative overflow-hidden pt-12 pb-20 border-b border-sky-500/20 bg-gradient-to-b from-navy-900 via-navy-950 to-navy-950">
    <div class="absolute inset-0 bg-[linear-gradient(to_right,#0ea5e910_1px,transparent_1px),linear-gradient(to_bottom,#0ea5e910_1px,transparent_1px)] bg-[size:4rem_4rem] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,#000_70%,transparent_100%)]"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        
        <!-- Live Alert / Ticker -->
        <div class="inline-flex items-center space-x-2 px-3 py-1.5 rounded-full bg-sky-500/10 border border-sky-500/30 text-sky-400 text-xs font-mono mb-6 backdrop-blur-md">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
            <span class="font-bold">RADAR ACTIVE:</span>
            <span class="text-slate-300">Мониторинг воздушного пространства в реальном времени</span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
            
            <!-- Hero Left -->
            <div class="lg:col-span-7 space-y-6">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight text-white leading-tight font-sans">
                    Энциклопедия, споттинг и <span class="text-transparent bg-clip-text bg-gradient-to-r from-sky-400 to-indigo-400">радар полетов</span>
                </h1>
                
                <p class="text-base sm:text-lg text-slate-300 max-w-2xl leading-relaxed">
                    Полная база гражданской и военной авиации с детальными инженерными ТТХ, споттерской медиатекой, расчетами E6B, живой погодой METAR и бортовым ИИ-ассистентом.
                </p>

                <!-- Search Input on Hero -->
                <div class="max-w-xl">
                    <div class="relative flex items-center glass-hud rounded-2xl p-1.5 border border-sky-500/30 shadow-2xl">
                        <i data-lucide="search" class="w-5 h-5 text-sky-400 ml-3"></i>
                        <input type="text" placeholder="Поиск самолёта (Ту-154, A350, Су-57) или ICAO (UUEE, EGLL)..." 
                               class="w-full bg-transparent px-3 py-2.5 text-sm text-white placeholder-slate-400 focus:outline-none font-mono"
                               onkeydown="if(event.key==='Enter') window.location.href='aircraft.php?q='+encodeURIComponent(this.value)">
                        <button onclick="window.location.href='aircraft.php?q='+encodeURIComponent(this.previousElementSibling.value)" 
                                class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold font-mono transition shadow-lg shadow-sky-600/30">
                            Найти
                        </button>
                    </div>
                </div>

                <!-- Quick Telemetry Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 font-mono">
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5">
                        <div class="text-2xl font-bold text-sky-400"><?= $planesCount ?: '150+' ?></div>
                        <div class="text-[11px] text-slate-400 uppercase">Самолётов в базе</div>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5">
                        <div class="text-2xl font-bold text-emerald-400"><?= $airportsCount ?: '2 500+' ?></div>
                        <div class="text-[11px] text-slate-400 uppercase">Аэропортов мира</div>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5">
                        <div class="text-2xl font-bold text-amber-400"><?= $photosCount ?: '1 200+' ?></div>
                        <div class="text-[11px] text-slate-400 uppercase">Споттерских фото</div>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5">
                        <div class="text-2xl font-bold text-purple-400">100%</div>
                        <div class="text-[11px] text-slate-400 uppercase">Открытый доступ</div>
                    </div>
                </div>

            </div>

            <!-- Hero Right: Photo of the Day Card -->
            <div class="lg:col-span-5">
                <?php if ($photoOfDay): ?>
                    <div class="relative group rounded-3xl overflow-hidden glass-card p-2 border border-sky-500/30 shadow-2xl">
                        <div class="relative aspect-[4/3] rounded-2xl overflow-hidden bg-slate-950">
                            <img src="<?= e($photoOfDay['photo_url']) ?>" alt="Photo of the Day" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-transparent to-black/30"></div>
                            
                            <!-- Badges -->
                            <div class="absolute top-3 left-3 flex items-center space-x-2">
                                <span class="px-2.5 py-1 rounded-lg bg-amber-500/90 text-slate-950 text-[10px] font-bold font-mono uppercase tracking-wider flex items-center space-x-1 shadow-lg">
                                    <i data-lucide="star" class="w-3 h-3 fill-current"></i>
                                    <span>Фото дня</span>
                                </span>
                                <?php if ($photoOfDay['tail_number']): ?>
                                    <span class="px-2 py-1 rounded-lg bg-black/60 text-sky-400 text-[10px] font-mono border border-sky-500/30">
                                        <?= e($photoOfDay['tail_number']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Bottom Info -->
                            <div class="absolute bottom-3 left-3 right-3 text-white">
                                <div class="text-base font-bold font-sans"><?= e($photoOfDay['model_name'] ?: 'Споттинг') ?></div>
                                <div class="text-xs text-slate-300 flex items-center justify-between mt-1 font-mono">
                                    <span><?= e($photoOfDay['airport_name'] ?: 'Аэропорт') ?></span>
                                    <span>© <?= e($photoOfDay['full_name'] ?: $photoOfDay['username']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Interactive Radar Teaser -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="glass-card rounded-3xl p-8 border border-emerald-500/20 relative overflow-hidden">
        <div class="flex flex-col md:flex-row items-center justify-between gap-6 relative z-10">
            <div class="space-y-2">
                <div class="flex items-center space-x-2 text-xs font-mono text-emerald-400">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                    <span>LIVE ADS-B FLIGHT RADAR</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-bold text-white">Интерактивный радар полетов</h2>
                <p class="text-sm text-slate-300 max-w-xl">
                    Отслеживайте движение сотен самолетов в реальном времени с точным курсом, высотой эшелона, squawk-кодами и аварийными предупреждениями 7700.
                </p>
            </div>
            <a href="radar.php" class="px-6 py-3.5 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-mono text-sm font-bold shadow-xl shadow-emerald-600/30 transition flex items-center space-x-2 whitespace-nowrap">
                <i data-lucide="radar" class="w-4 h-4"></i>
                <span>Открыть радар на весь экран</span>
            </a>
        </div>
    </div>
</section>

<!-- Featured Aircraft Grid -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h2 class="text-2xl font-bold text-white font-sans">Популярные самолёты в энциклопедии</h2>
            <p class="text-xs text-slate-400 font-mono mt-1">Детальные летные характеристики, 3D-модели и история создания</p>
        </div>
        <a href="aircraft.php" class="text-xs font-mono text-sky-400 hover:underline flex items-center space-x-1">
            <span>Все самолёты</span>
            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
        </a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <?php foreach ($featuredPlanes as $plane): 
            $specs = DB::fetchOne("SELECT * FROM `va_aircraft_specs` WHERE `aircraft_id` = :id", ['id' => $plane['id']]);
        ?>
            <a href="aircraft.php?slug=<?= e($plane['slug']) ?>" class="group glass-card rounded-2xl overflow-hidden border border-white/5 hover:border-sky-500/40 transition duration-300 flex flex-col">
                <div class="relative aspect-video bg-slate-950 overflow-hidden">
                    <img src="<?= e($plane['hero_image'] ?: 'https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?w=600&q=80') ?>" 
                         alt="<?= e($plane['model_name']) ?>" 
                         class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                    <div class="absolute top-2.5 right-2.5 px-2 py-0.5 rounded bg-black/60 text-sky-400 font-mono text-[10px] border border-sky-500/30">
                        <?= e($plane['icao_code'] ?: 'ICAO') ?>
                    </div>
                </div>
                
                <div class="p-4 flex-1 flex flex-col justify-between space-y-3">
                    <div>
                        <h3 class="font-bold text-white group-hover:text-sky-400 transition text-base"><?= e($plane['model_name']) ?></h3>
                        <p class="text-xs text-slate-400 line-clamp-2 mt-1"><?= e($plane['short_desc']) ?></p>
                    </div>

                    <div class="grid grid-cols-2 gap-2 pt-2 border-t border-white/5 text-[11px] font-mono text-slate-300">
                        <div>
                            <span class="text-slate-500 block text-[9px] uppercase">Скорость</span>
                            <?= format_speed($specs['cruise_speed_kmh'] ?? null) ?>
                        </div>
                        <div>
                            <span class="text-slate-500 block text-[9px] uppercase">Дальность</span>
                            <?= format_range($specs['max_range_km'] ?? null) ?>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- Tools & Simmer Modules Showcase -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="text-center max-w-2xl mx-auto mb-12">
        <h2 class="text-3xl font-bold text-white">Инструменты пилота и виртуального авиатора</h2>
        <p class="text-xs text-slate-400 font-mono mt-2">Профессиональные калькуляторы E6B, интерактивные чеклисты и тренажеры</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        
        <!-- Tool 1: Calculators -->
        <a href="calculators.php" class="p-6 rounded-3xl glass-card border border-purple-500/20 hover:border-purple-500/40 transition group">
            <div class="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-400 flex items-center justify-center mb-4 group-hover:scale-110 transition">
                <i data-lucide="calculator" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-bold text-white mb-2">E6B Лётные калькуляторы</h3>
            <p class="text-xs text-slate-400 leading-relaxed mb-4">
                Расчет бокового и встречного ветра для ВПП, плотностная высота, глиссада 3°, точка начала снижения TOD и ортодромия.
            </p>
            <span class="text-xs font-mono text-purple-400 flex items-center space-x-1">
                <span>Перейти к калькуляторам</span>
                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </span>
        </a>

        <!-- Tool 2: Checklists & Training -->
        <a href="training.php" class="p-6 rounded-3xl glass-card border border-rose-500/20 hover:border-rose-500/40 transition group">
            <div class="w-12 h-12 rounded-2xl bg-rose-500/10 text-rose-400 flex items-center justify-center mb-4 group-hover:scale-110 transition">
                <i data-lucide="check-square" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-bold text-white mb-2">Чеклисты и Лётная школа</h3>
            <p class="text-xs text-slate-400 leading-relaxed mb-4">
                Интерактивные электронные чек-листы с голосовой озвучкой второго пилота (Callouts), разборы карт SID/STAR и ВАК.
            </p>
            <span class="text-xs font-mono text-rose-400 flex items-center space-x-1">
                <span>Открыть лётную школу</span>
                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </span>
        </a>

        <!-- Tool 3: Cockpit Soundboard -->
        <a href="soundboard.php" class="p-6 rounded-3xl glass-card border border-emerald-500/20 hover:border-emerald-500/40 transition group">
            <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center mb-4 group-hover:scale-110 transition">
                <i data-lucide="volume-2" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-bold text-white mb-2">Звуки кабины & GPWS / TCAS</h3>
            <p class="text-xs text-slate-400 leading-relaxed mb-4">
                Сэмплер реальных предупреждений TCAS «Climb!», GPWS «Terrain! Pull Up!», звуки запуска ВСУ и фоновый стрим LiveATC.
            </p>
            <span class="text-xs font-mono text-emerald-400 flex items-center space-x-1">
                <span>Слушать звуки кабины</span>
                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </span>
        </a>

    </div>
</section>

<!-- Recent Spotting Photos Strip -->
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-white">Свежие споттерские кадры</h2>
            <p class="text-xs text-slate-400 font-mono mt-1">Авторские фотографии от авиационных энтузиастов</p>
        </div>
        <a href="spotting.php" class="text-xs font-mono text-amber-400 hover:underline flex items-center space-x-1">
            <span>Галерея споттинга</span>
            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
        </a>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
        <?php foreach ($recentPhotos as $photo): ?>
            <a href="spotting.php?photo_id=<?= (int)$photo['id'] ?>" class="group rounded-xl overflow-hidden glass-card border border-white/5 hover:border-amber-500/40 transition aspect-square relative">
                <img src="<?= e($photo['thumb_url'] ?: $photo['photo_url']) ?>" alt="Spotting" class="w-full h-full object-cover group-hover:scale-110 transition duration-300">
                <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition p-2 flex flex-col justify-end text-[10px] text-white font-mono">
                    <span class="font-bold truncate"><?= e($photo['model_name'] ?: $photo['tail_number'] ?: 'Spotting') ?></span>
                    <span class="text-slate-400">© <?= e($photo['username']) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
