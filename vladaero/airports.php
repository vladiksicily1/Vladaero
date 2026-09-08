<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/weather_decoder.php';

$icao = strtoupper(trim($_GET['icao'] ?? ''));

// Single Airport View
if (!empty($icao)) {
    $airport = DB::fetchOne("SELECT * FROM `va_airports` WHERE `icao` = :i", ['i' => $icao]);
    if (!$airport) {
        header("HTTP/1.0 404 Not Found");
        require_once __DIR__ . '/404.php';
        exit;
    }

    $runways = DB::fetchAll("SELECT * FROM `va_runways` WHERE `airport_id` = :id", ['id' => $airport['id']]);
    $frequencies = DB::fetchAll("SELECT * FROM `va_frequencies` WHERE `airport_id` = :id", ['id' => $airport['id']]);
    $spottingSpots = DB::fetchAll("SELECT * FROM `va_spotting_locations` WHERE `airport_id` = :id", ['id' => $airport['id']]);
    $metar = WeatherDecoder::getMetar($airport['icao']);

    $pageTitle = "Аэропорт {$airport['name_ru']} ({$airport['icao']} / {$airport['iata']}) — METAR, полосы и споттинг";
    require_once __DIR__ . '/includes/header.php';
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        
        <!-- Airport Header -->
        <div class="glass-hud p-6 rounded-3xl border border-sky-500/30 mb-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="space-y-2">
                <div class="flex items-center space-x-2 text-xs font-mono text-sky-400">
                    <span><?= e($airport['city_ru']) ?>, <?= e($airport['country_ru']) ?></span>
                    <span>•</span>
                    <span>Эшелон перехода: <?= (int)$airport['transition_alt_ft'] ?> ft</span>
                </div>
                <h1 class="text-3xl sm:text-4xl font-extrabold text-white"><?= e($airport['name_ru']) ?></h1>
                <div class="text-sm text-slate-400 font-mono"><?= e($airport['name_en']) ?></div>
            </div>

            <!-- Codes Badge -->
            <div class="flex items-center space-x-3">
                <div class="p-3.5 rounded-2xl bg-slate-900/90 border border-sky-500/30 text-center font-mono">
                    <span class="text-[9px] text-slate-500 uppercase block">ICAO</span>
                    <span class="text-xl font-bold text-sky-400"><?= e($airport['icao']) ?></span>
                </div>
                <?php if ($airport['iata']): ?>
                    <div class="p-3.5 rounded-2xl bg-slate-900/90 border border-amber-500/30 text-center font-mono">
                        <span class="text-[9px] text-slate-500 uppercase block">IATA</span>
                        <span class="text-xl font-bold text-amber-400"><?= e($airport['iata']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Live METAR Weather Widget -->
        <div class="glass-card rounded-3xl p-6 border border-emerald-500/30 mb-8 space-y-4">
            <div class="flex items-center justify-between border-b border-white/5 pb-3">
                <div class="flex items-center space-x-2">
                    <i data-lucide="cloud-sun" class="w-5 h-5 text-emerald-400"></i>
                    <span class="font-bold text-white font-mono text-sm">Текущая погода METAR / TAF</span>
                </div>
                <span class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 text-xs font-mono font-bold">
                    <?= e($metar['flight_category']) ?>
                </span>
            </div>

            <div class="p-3 rounded-xl bg-slate-950/80 font-mono text-xs text-sky-300 border border-white/5">
                <?= e($metar['raw_metar']) ?>
            </div>

            <div class="text-xs text-slate-300 font-mono leading-relaxed">
                👉 <strong>Расшифровка:</strong> <?= e($metar['summary_ru']) ?>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2 font-mono text-xs">
                <div class="p-2.5 rounded-xl bg-slate-900/80 border border-white/5">
                    <span class="text-slate-500 text-[9px] block">Ветер</span>
                    <span class="text-white font-bold"><?= $metar['wind']['direction'] ?>° / <?= $metar['wind']['speed_mps'] ?> м/с</span>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-900/80 border border-white/5">
                    <span class="text-slate-500 text-[9px] block">Видимость</span>
                    <span class="text-white font-bold"><?= $metar['visibility']['cavok'] ? 'CAVOK (10+ км)' : $metar['visibility']['meters'] . ' м' ?></span>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-900/80 border border-white/5">
                    <span class="text-slate-500 text-[9px] block">Температура</span>
                    <span class="text-white font-bold"><?= $metar['temperature_c'] !== null ? $metar['temperature_c'] . '°C' : '—' ?></span>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-900/80 border border-white/5">
                    <span class="text-slate-500 text-[9px] block">Давление QNH</span>
                    <span class="text-sky-400 font-bold"><?= $metar['qnh_hpa'] ?> hPa</span>
                </div>
            </div>
        </div>

        <!-- Runways Database -->
        <div class="glass-card rounded-3xl p-6 border border-white/5 mb-8">
            <h3 class="text-lg font-bold text-white font-mono mb-4 flex items-center space-x-2">
                <i data-lucide="navigation" class="w-5 h-5 text-sky-400"></i>
                <span>Взлетно-посадочные полосы (ВПП)</span>
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 font-mono text-xs">
                <?php foreach ($runways as $rwy): ?>
                    <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 space-y-2">
                        <div class="flex items-center justify-between border-b border-white/5 pb-2">
                            <span class="font-bold text-white text-base">ВПП <?= e($rwy['ident_1']) ?> / <?= e($rwy['ident_2']) ?></span>
                            <span class="text-slate-400"><?= (int)$rwy['length_m'] ?> x <?= (int)$rwy['width_m'] ?> м</span>
                        </div>
                        <div class="flex justify-between text-slate-300">
                            <span>Покрытие: <?= e($rwy['surface_type']) ?></span>
                            <span>Курсы: <?= $rwy['heading_1_deg'] ?>° / <?= $rwy['heading_2_deg'] ?>°</span>
                        </div>
                        <?php if ($rwy['ils_freq_1']): ?>
                            <div class="p-2 rounded-lg bg-sky-500/10 border border-sky-500/20 text-sky-300 text-[11px] flex justify-between">
                                <span>ILS <?= e($rwy['ident_1']) ?>: <?= $rwy['ils_freq_1'] ?> МГц</span>
                                <span><?= e($rwy['lighting_type']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Spotting Locations around Airport -->
        <?php if (!empty($spottingSpots)): ?>
            <div class="glass-card rounded-3xl p-6 border border-amber-500/20 mb-8">
                <h3 class="text-lg font-bold text-amber-400 font-mono mb-4 flex items-center space-x-2">
                    <i data-lucide="camera" class="w-5 h-5"></i>
                    <span>Споттинг-точки вокруг аэропорта</span>
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs font-mono">
                    <?php foreach ($spottingSpots as $spot): ?>
                        <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 space-y-2">
                            <h4 class="font-bold text-white text-sm"><?= e($spot['title']) ?></h4>
                            <div class="text-slate-400 leading-relaxed"><?= e($spot['access_info']) ?></div>
                            <div class="pt-2 flex items-center justify-between text-[11px] text-sky-400">
                                <span>Реком. объектив: <?= e($spot['recommended_lens']) ?></span>
                                <span><?= e($spot['best_time']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Airports Directory
$search = trim($_GET['q'] ?? '');
$where = "1=1";
$params = [];

if ($search) {
    $where = "(`icao` LIKE :q OR `iata` LIKE :q OR `name_ru` LIKE :q OR `city_ru` LIKE :q)";
    $params['q'] = "%{$search}%";
}

$airports = DB::fetchAll("SELECT * FROM `va_airports` WHERE {$where} ORDER BY `city_ru` ASC", $params);

$pageTitle = 'Каталог аэропортов мира и погода METAR';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-white">Каталог аэропортов и погода METAR</h1>
            <p class="text-xs text-slate-400 font-mono mt-1">Схемы полос, частоты, навигационные средства и актуальная метеорология</p>
        </div>

        <form method="GET" class="flex items-center space-x-2">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск по ICAO, IATA или городу..." 
                   class="bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white font-mono placeholder-slate-500 focus:outline-none focus:border-sky-500">
            <button type="submit" class="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-mono font-bold transition">Найти</button>
        </form>
    </div>

    <!-- Airports Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($airports as $apt): ?>
            <a href="airports.php?icao=<?= e($apt['icao']) ?>" class="glass-card p-5 rounded-2xl border border-white/5 hover:border-sky-500/40 transition flex flex-col justify-between group">
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="px-2.5 py-1 rounded-lg bg-sky-600/30 text-sky-300 font-mono text-xs font-bold border border-sky-500/30">
                            <?= e($apt['icao']) ?> <?= $apt['iata'] ? "/ {$apt['iata']}" : '' ?>
                        </span>
                        <span class="text-[11px] font-mono text-slate-400"><?= e($apt['country_ru']) ?></span>
                    </div>
                    <h3 class="font-bold text-white text-base group-hover:text-sky-400 transition"><?= e($apt['name_ru']) ?></h3>
                    <p class="text-xs text-slate-400 font-mono"><?= e($apt['city_ru']) ?></p>
                </div>

                <div class="pt-4 mt-4 border-t border-white/5 flex items-center justify-between text-xs font-mono text-slate-400">
                    <span>Высота: <?= (int)$apt['elevation_m'] ?> м</span>
                    <span class="text-sky-400">Открыть сводку ➔</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
