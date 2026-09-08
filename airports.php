<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/weather_decoder.php';
require_once __DIR__ . '/includes/e6b.php';

$code = strtoupper(trim($_GET['code'] ?? ''));
$search = trim($_GET['q'] ?? '');

$apTable = Database::tableName('airports');
$rwyTable = Database::tableName('runways');
$freqTable = Database::tableName('frequencies');
$spotTable = Database::tableName('spotting_locations');

if (!empty($code)) {
    // Detail View
    $airport = Database::isConfigured() ? Database::fetchOne("SELECT * FROM `{$apTable}` WHERE icao = :code OR iata = :code LIMIT 1", ['code' => $code]) : null;

    if (!$airport) {
        header('Location: ' . url('/airports.php'));
        exit;
    }

    $pageTitle = "Аэропорт {$airport['name_ru']} ({$airport['icao']}) — Схемы ВПП, Частоты и Погода";
    $metaDescription = "Аэронавигационная информация об аэропорте {$airport['name_ru']} ({$airport['icao']} / {$airport['iata']}): полосы ВПП, радиочастоты УВД, превышение, погода METAR и точки споттинга.";
    require_once __DIR__ . '/includes/header.php';

    $runways = Database::isConfigured() ? Database::fetchAll("SELECT * FROM `{$rwyTable}` WHERE airport_id = :id ORDER BY length_m DESC", ['id' => $airport['id']]) : [];
    $frequencies = Database::isConfigured() ? Database::fetchAll("SELECT * FROM `{$freqTable}` WHERE airport_id = :id ORDER BY type ASC", ['id' => $airport['id']]) : [];
    $spottingLocs = Database::isConfigured() ? Database::fetchAll("SELECT * FROM `{$spotTable}` WHERE airport_id = :id", ['id' => $airport['id']]) : [];

    // Fetch Live METAR
    $weather = WeatherDecoder::getMetar($airport['icao']);
    $wData = $weather['success'] ? $weather['data'] : null;
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumbs -->
        <nav class="flex items-center space-x-2 text-xs font-mono text-slate-400 mb-6">
            <a href="<?= url('/') ?>" class="hover:text-sky-400">Главная</a>
            <span>/</span>
            <a href="<?= url('/airports.php') ?>" class="hover:text-sky-400">Аэропорты</a>
            <span>/</span>
            <span class="text-slate-200"><?= e($airport['name_ru']) ?></span>
        </nav>

        <!-- Airport Hero Card -->
        <div class="va-card p-6 sm:p-10 mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 pb-6 border-b border-slate-800">
                <div>
                    <div class="flex items-center space-x-3 mb-2 font-mono">
                        <span class="px-3 py-1 rounded-xl bg-sky-500/10 border border-sky-500/30 text-sky-400 font-bold text-sm">
                            ICAO: <?= e($airport['icao']) ?>
                        </span>
                        <?php if ($airport['iata']): ?>
                            <span class="px-3 py-1 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 font-bold text-sm">
                                IATA: <?= e($airport['iata']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($airport['rf_code']): ?>
                            <span class="px-2.5 py-1 rounded-xl bg-slate-900 border border-slate-800 text-slate-400 text-xs">
                                РФ: <?= e($airport['rf_code']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight"><?= e($airport['name_ru']) ?></h1>
                    <div class="text-sm text-slate-400 mt-1 font-mono">
                        <?= e($airport['name_en']) ?> • г. <?= e($airport['city_ru']) ?>, <?= e($airport['country_ru']) ?>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3 font-mono text-xs">
                    <a href="<?= url('/weather.php?icao=' . $airport['icao']) ?>" class="bg-amber-600/20 hover:bg-amber-600/30 text-amber-300 border border-amber-500/40 py-3 px-5 rounded-xl font-bold transition flex items-center space-x-2">
                        <i data-lucide="cloud-sun" class="w-4 h-4 text-amber-400"></i>
                        <span>METAR Погода</span>
                    </a>
                    <a href="<?= url('/radar.php') ?>" class="bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 border border-emerald-500/40 py-3 px-5 rounded-xl font-bold transition flex items-center space-x-2">
                        <i data-lucide="radar" class="w-4 h-4 text-emerald-400"></i>
                        <span>Радар рейсов</span>
                    </a>
                </div>
            </div>

            <!-- Airport Basic Info Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-6 text-xs font-mono">
                <div class="p-3 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-slate-400">Превышение аэродрома</div>
                    <div class="text-base font-bold text-sky-400 mt-1"><?= $airport['elevation_ft'] ?> ft (<?= $airport['elevation_m'] ?> м)</div>
                </div>
                <div class="p-3 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-slate-400">Координаты GPS</div>
                    <div class="text-slate-200 font-bold mt-1"><?= round($airport['latitude'], 4) ?>°, <?= round($airport['longitude'], 4) ?>°</div>
                </div>
                <div class="p-3 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-slate-400">Часовой пояс</div>
                    <div class="text-slate-200 font-bold mt-1"><?= e($airport['timezone']) ?></div>
                </div>
                <div class="p-3 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-slate-400">Высота перехода</div>
                    <div class="text-emerald-400 font-bold mt-1"><?= $airport['transition_alt_ft'] ?> ft</div>
                </div>
            </div>
        </div>

        <!-- Main Content: Runways & Frequencies -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Col 1 & 2: Runways & SVG Schemes -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Runways Table -->
                <div class="va-card p-6">
                    <h2 class="text-base font-bold text-white uppercase font-mono tracking-wider mb-4 flex items-center space-x-2">
                        <i data-lucide="milestone" class="w-5 h-5 text-sky-400"></i>
                        <span>Взлетно-посадочные полосы (ВПП)</span>
                    </h2>

                    <?php if (empty($runways)): ?>
                        <div class="p-4 bg-slate-950 rounded-xl text-center text-xs text-slate-500 font-mono">
                            Информация о полосах уточняется.
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($runways as $rwy): 
                                // Calculate crosswind for runway if METAR available
                                $windSpd = $wData ? $wData['wind']['speed_kt'] : 0;
                                $windDir = $wData ? $wData['wind']['direction_deg'] : 0;
                                $xw = E6B::calculateWindComponents($rwy['heading_1_deg'], $windDir, $windSpd);
                            ?>
                                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 space-y-3 font-mono text-xs">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-2">
                                            <span class="text-lg font-bold text-sky-400"><?= e($rwy['ident_1']) ?> / <?= e($rwy['ident_2']) ?></span>
                                            <span class="px-2 py-0.5 rounded bg-slate-900 border border-slate-800 text-slate-400 text-[10px]">
                                                <?= ucfirst($rwy['surface_type']) ?>
                                            </span>
                                        </div>
                                        <div class="text-slate-300 font-bold">
                                            <?= $rwy['length_m'] ?> × <?= $rwy['width_m'] ?> м
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-slate-900 text-[11px]">
                                        <div>Курс: <strong class="text-slate-200"><?= str_pad($rwy['heading_1_deg'], 3, '0', STR_PAD_LEFT) ?>° / <?= str_pad($rwy['heading_2_deg'], 3, '0', STR_PAD_LEFT) ?>°</strong></div>
                                        <div>ILS 1: <strong class="text-emerald-400"><?= $rwy['ils_freq_1'] ? $rwy['ils_freq_1'] . ' MHz' : '—' ?></strong></div>
                                        <div>ILS 2: <strong class="text-emerald-400"><?= $rwy['ils_freq_2'] ? $rwy['ils_freq_2'] . ' MHz' : '—' ?></strong></div>
                                        <div>Огни: <strong class="text-amber-400"><?= e($rwy['lighting_type'] ?: 'PAPI, ALS') ?></strong></div>
                                    </div>

                                    <?php if ($wData): ?>
                                        <div class="p-2.5 rounded-lg bg-sky-950/40 border border-sky-900/50 flex items-center justify-between text-[11px]">
                                            <span class="text-slate-400">Боковой ветер для ВПП <?= e($rwy['ident_1']) ?>:</span>
                                            <span class="text-sky-300 font-bold"><?= $xw['crosswind_kt'] ?> узлов (<?= $xw['crosswind_ms'] ?> м/с <?= $xw['cross_side'] ?>)</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Spotting Locations around Airport -->
                <div class="va-card p-6">
                    <h2 class="text-base font-bold text-white uppercase font-mono tracking-wider mb-4 flex items-center space-x-2">
                        <i data-lucide="camera" class="w-5 h-5 text-purple-400"></i>
                        <span>Точки споттинга вокруг аэродрома</span>
                    </h2>

                    <?php if (empty($spottingLocs)): ?>
                        <div class="p-6 bg-slate-950 rounded-xl text-center text-xs text-slate-500 font-mono">
                            Точки споттинга для этого аэропорта пока не добавлены.
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($spottingLocs as $loc): ?>
                                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <h3 class="font-bold text-sm text-slate-100"><?= e($loc['title']) ?></h3>
                                        <span class="text-xs font-mono text-sky-400"><?= round($loc['latitude'], 4) ?>°, <?= round($loc['longitude'], 4) ?>°</span>
                                    </div>
                                    <p class="text-xs text-slate-300 leading-relaxed"><?= e($loc['access_info']) ?></p>
                                    <div class="flex items-center space-x-4 text-[11px] font-mono text-slate-400 pt-1">
                                        <span>Рекомендуемая оптика: <strong class="text-slate-200"><?= e($loc['recommended_lens']) ?></strong></span>
                                        <span>Лучшее время: <strong class="text-slate-200"><?= e($loc['best_time']) ?></strong></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Col 3: Frequencies Table & Live Weather -->
            <div class="space-y-6">

                <!-- Radio Frequencies -->
                <div class="va-card p-6">
                    <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider mb-4 flex items-center space-x-2">
                        <i data-lucide="radio" class="w-4 h-4 text-emerald-400"></i>
                        <span>Радиочастоты УВД</span>
                    </h3>

                    <?php if (empty($frequencies)): ?>
                        <div class="p-4 bg-slate-950 rounded-xl text-center text-xs text-slate-500 font-mono">
                            Частоты уточняются.
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-slate-800 text-xs font-mono">
                            <?php foreach ($frequencies as $f): ?>
                                <div class="py-2.5 flex items-center justify-between">
                                    <div>
                                        <div class="font-bold text-slate-200"><?= e($f['type']) ?> (<?= e($f['callsign'] ?: $f['type']) ?>)</div>
                                        <div class="text-[10px] text-slate-500"><?= e($f['description']) ?></div>
                                    </div>
                                    <span class="font-bold text-emerald-400 text-sm"><?= e($f['frequency_mhz']) ?> MHz</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Weather Status Widget -->
                <?php if ($wData): ?>
                    <div class="va-card p-6 space-y-3">
                        <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Текущая погода METAR</h3>
                        <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 font-mono text-xs space-y-2">
                            <div class="flex justify-between">
                                <span class="text-slate-400">Категория:</span>
                                <span class="font-bold text-emerald-400"><?= e($wData['flight_category']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">Ветер:</span>
                                <span class="text-slate-200"><?= e($wData['wind']['text_ru']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">Видимость:</span>
                                <span class="text-slate-200"><?= e($wData['visibility']['text_ru']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">Температура:</span>
                                <span class="text-slate-200"><?= $wData['temperature_c'] ?>°C / QNH <?= $wData['qnh_hpa'] ?> hPa</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <?php
} else {
    // Airports Directory Grid
    $pageTitle = 'База аэропортов мира и схем ВПП';
    $metaDescription = 'Поиск аэропортов по ICAO и IATA кодам, координаты, схемы взлетно-посадочных полос, частоты вышки и глиссады ILS.';
    require_once __DIR__ . '/includes/header.php';

    $where = ["status = 'active'"];
    $params = [];
    if (!empty($search)) {
        $where[] = "(name_ru LIKE :q OR name_en LIKE :q OR icao LIKE :q OR iata LIKE :q OR city_ru LIKE :q)";
        $params['q'] = "%{$search}%";
    }

    $whereSql = implode(' AND ', $where);
    $airports = Database::isConfigured() ? Database::fetchAll("SELECT * FROM `{$apTable}` WHERE {$whereSql} ORDER BY country_ru ASC, city_ru ASC LIMIT 50") : [];
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Page Header -->
        <div class="va-card p-6 sm:p-8 mb-8">
            <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3 mb-2">
                <i data-lucide="map-pin" class="w-8 h-8 text-sky-400"></i>
                <span>Аэропорты Мира и Схемы ВПП</span>
            </h1>
            <p class="text-xs text-slate-400 mb-6">
                База данных аэропортов: коды ICAO, IATA, длина полос, частоты УВД, схемы захода и точки споттинга
            </p>

            <!-- Search Bar -->
            <form method="GET" class="flex flex-col sm:flex-row gap-3">
                <div class="relative flex-grow">
                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск по городу, названию, ICAO (UUEE, EGLL) или IATA (SVO, LHR)..." class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-10 pr-4 py-2.5 text-xs text-slate-100 placeholder-slate-500 font-mono focus:outline-none focus:border-sky-500">
                </div>
                <button type="submit" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-6 py-2.5 rounded-xl transition">
                    Найти
                </button>
            </form>
        </div>

        <!-- Airports Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($airports as $ap): ?>
                <a href="<?= url('/airports.php?code=' . urlencode($ap['icao'])) ?>" class="va-card p-5 block group">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center space-x-2 font-mono">
                            <span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 border border-sky-800 text-xs font-bold"><?= e($ap['icao']) ?></span>
                            <?php if ($ap['iata']): ?>
                                <span class="px-2 py-0.5 rounded bg-slate-950 text-slate-300 border border-slate-800 text-xs font-bold"><?= e($ap['iata']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs text-slate-500 font-mono"><?= e($ap['country_ru']) ?></span>
                    </div>

                    <h3 class="text-base font-bold text-white group-hover:text-sky-400 transition mb-1"><?= e($ap['name_ru']) ?></h3>
                    <div class="text-xs text-slate-400 font-mono">г. <?= e($ap['city_ru']) ?></div>

                    <div class="mt-4 pt-3 border-t border-slate-800/80 flex items-center justify-between text-[11px] font-mono text-slate-500">
                        <span>Превышение: <strong class="text-slate-300"><?= $ap['elevation_ft'] ?> ft</strong></span>
                        <span class="text-sky-400 font-bold group-hover:underline flex items-center space-x-1">
                            <span>Схема ВПП</span>
                            <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

    </div>

    <?php
}
require_once __DIR__ . '/includes/footer.php';
?>
