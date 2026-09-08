<?php
require_once __DIR__ . '/includes/functions.php';

$slug = trim($_GET['slug'] ?? '');
$categoryFilter = trim($_GET['cat'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');

$aircraftTable = Database::tableName('aircraft');
$specsTable = Database::tableName('aircraft_specs');
$mfgTable = Database::tableName('manufacturers');
$photosTable = Database::tableName('photos');

if (!empty($slug)) {
    // Detail View
    $aircraft = Database::isConfigured() ? Database::fetchOne("SELECT a.*, m.name as manufacturer_name, m.country as manufacturer_country, s.* FROM `{$aircraftTable}` a LEFT JOIN `{$mfgTable}` m ON a.manufacturer_id = m.id LEFT JOIN `{$specsTable}` s ON a.id = s.aircraft_id WHERE a.slug = :slug LIMIT 1", ['slug' => $slug]) : null;
    
    if (!$aircraft) {
        header('Location: ' . url('/aircraft.php'));
        exit;
    }

    $pageTitle = $aircraft['model_name'] . ' — ЛТХ, Характеристики и Фото';
    $metaDescription = "Летно-технические характеристики {$aircraft['model_name']} ({$aircraft['icao_code']}): дальность {$aircraft['max_range_km']} км, скорость {$aircraft['cruise_speed_kmh']} км/ч, двигатели, чертежи и фотографии.";
    require_once __DIR__ . '/includes/header.php';

    // Fetch Spotter Photos for this aircraft
    $spotterPhotos = Database::isConfigured() ? Database::fetchAll("SELECT p.*, u.username FROM `{$photosTable}` p LEFT JOIN `" . Database::tableName('users') . "` u ON p.user_id = u.id WHERE p.aircraft_id = :id AND p.status = 'approved' ORDER BY p.id DESC LIMIT 6", ['id' => $aircraft['id']]) : [];
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumbs -->
        <nav class="flex items-center space-x-2 text-xs font-mono text-slate-400 mb-6">
            <a href="<?= url('/') ?>" class="hover:text-sky-400">Главная</a>
            <span>/</span>
            <a href="<?= url('/aircraft.php') ?>" class="hover:text-sky-400">Самолеты</a>
            <span>/</span>
            <span class="text-slate-200"><?= e($aircraft['model_name']) ?></span>
        </nav>

        <!-- Hero Card -->
        <div class="va-card p-6 sm:p-10 mb-8 relative overflow-hidden">
            <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6 pb-8 border-b border-slate-800">
                <div>
                    <div class="flex items-center space-x-3 mb-2">
                        <span class="px-2.5 py-1 rounded-lg bg-sky-500/10 border border-sky-500/30 text-sky-400 text-xs font-mono font-bold">
                            <?= e($aircraft['icao_code'] ?: 'AIRCRAFT') ?>
                        </span>
                        <span class="text-xs text-slate-400 font-mono"><?= e($aircraft['manufacturer_name'] ?? 'Авиапром') ?></span>
                    </div>
                    <h1 class="text-3xl sm:text-5xl font-black text-white tracking-tight"><?= e($aircraft['model_name']) ?></h1>
                    <p class="text-sm text-slate-300 mt-2 max-w-2xl leading-relaxed"><?= e($aircraft['short_desc']) ?></p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="<?= url('/compare.php?ac1=' . $aircraft['id']) ?>" class="bg-slate-800 hover:bg-slate-700 text-white font-mono text-xs font-bold py-3 px-5 rounded-xl border border-slate-700 transition flex items-center space-x-2">
                        <i data-lucide="scale" class="w-4 h-4 text-amber-400"></i>
                        <span>Сравнить с другим ВС</span>
                    </a>
                    <a href="<?= url('/3d.php') ?>" class="bg-sky-600 hover:bg-sky-500 text-white font-mono text-xs font-bold py-3 px-5 rounded-xl shadow-lg transition flex items-center space-x-2">
                        <i data-lucide="box" class="w-4 h-4"></i>
                        <span>3D Просмотр</span>
                    </a>
                </div>
            </div>

            <!-- Key Metrics Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-8 font-mono">
                <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-[11px] text-slate-400">Макс. Дальность</div>
                    <div class="text-xl font-extrabold text-sky-400 mt-1"><?= formatNumber($aircraft['max_range_km']) ?> км</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">~<?= formatNumber(($aircraft['max_range_km'] ?? 0) / 1.852) ?> NM</div>
                </div>

                <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-[11px] text-slate-400">Крейсерская Скорость</div>
                    <div class="text-xl font-extrabold text-emerald-400 mt-1"><?= formatNumber($aircraft['cruise_speed_kmh']) ?> км/ч</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">M <?= $aircraft['mach_cruise'] ?: '0.78' ?></div>
                </div>

                <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-[11px] text-slate-400">Вместимость</div>
                    <div class="text-xl font-extrabold text-amber-400 mt-1"><?= formatNumber($aircraft['passengers_max']) ?> чел.</div>
                    <div class="text-[10px] text-slate-500 mt-0.5"><?= $aircraft['passengers_typical'] ?: '—' ?> (типовая)</div>
                </div>

                <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                    <div class="text-[11px] text-slate-400">Практический Потолок</div>
                    <div class="text-xl font-extrabold text-purple-400 mt-1"><?= formatNumber($aircraft['service_ceiling_m']) ?> м</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">FL <?= round(($aircraft['service_ceiling_m'] ?? 0) * 3.28084 / 100) ?></div>
                </div>
            </div>
        </div>

        <!-- Detailed Specifications & Description Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-12">

            <!-- Col 1 & 2: Technical Specifications Table -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Specs Table -->
                <div class="va-card p-6">
                    <h2 class="text-base font-bold text-white uppercase font-mono tracking-wider mb-6 flex items-center space-x-2">
                        <i data-lucide="cpu" class="w-5 h-5 text-sky-400"></i>
                        <span>Летно-Технические Характеристики (ЛТХ)</span>
                    </h2>

                    <div class="divide-y divide-slate-800/80 text-xs font-mono">
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Длина самолета</span>
                            <span class="text-slate-100 font-bold"><?= $aircraft['length_m'] ?> м</span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Размах крыла</span>
                            <span class="text-slate-100 font-bold"><?= $aircraft['wingspan_m'] ?> м</span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Высота</span>
                            <span class="text-slate-100 font-bold"><?= $aircraft['height_m'] ?> м</span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Площадь крыла</span>
                            <span class="text-slate-100 font-bold"><?= $aircraft['wing_area_sqm'] ?> м²</span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Максимальная взлетная масса (MTOW)</span>
                            <span class="text-sky-400 font-bold"><?= formatNumber($aircraft['mtow_kg']) ?> кг</span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Максимальная полезная нагрузка</span>
                            <span class="text-slate-100 font-bold"><?= formatNumber($aircraft['max_payload_kg']) ?> кг</span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Емкость топливных баков</span>
                            <span class="text-slate-100 font-bold"><?= formatNumber($aircraft['fuel_capacity_liters']) ?> л</span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Двигатели (Силовая установка)</span>
                            <span class="text-amber-400 font-bold"><?= e($aircraft['engines_count']) ?> × <?= e($aircraft['engine_model'] ?: $aircraft['engine_type']) ?></span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Потребная длина ВПП для взлета</span>
                            <span class="text-slate-100 font-bold"><?= formatNumber($aircraft['takeoff_distance_m']) ?> м</span>
                        </div>
                        <div class="py-3 flex justify-between">
                            <span class="text-slate-400">Экипаж кабины</span>
                            <span class="text-slate-100 font-bold"><?= $aircraft['cockpit_crew'] ?> чел. (КВС + Второй пилот)</span>
                        </div>
                    </div>
                </div>

                <!-- Description Article -->
                <div class="va-card p-6">
                    <h2 class="text-base font-bold text-white uppercase font-mono tracking-wider mb-4 flex items-center space-x-2">
                        <i data-lucide="book-open" class="w-5 h-5 text-emerald-400"></i>
                        <span>История создания и особенности конструкции</span>
                    </h2>
                    <div class="text-sm text-slate-300 leading-relaxed space-y-4">
                        <p><?= nl2br(e($aircraft['full_desc'] ?: $aircraft['short_desc'])) ?></p>
                    </div>
                </div>
            </div>

            <!-- Col 3: Side Widget -->
            <div class="space-y-6">
                <!-- Spotter Photos Widget -->
                <div class="va-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xs font-bold text-slate-200 font-mono uppercase">Фотографии споттеров</h3>
                        <a href="<?= url('/spotting.php') ?>" class="text-[11px] font-mono text-sky-400 hover:underline">Добавить</a>
                    </div>
                    <?php if (empty($spotterPhotos)): ?>
                        <div class="p-6 rounded-xl bg-slate-950 text-center text-xs text-slate-500 font-mono">
                            Будьте первым, кто опубликует споттерское фото этого самолета!
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-2 gap-2">
                            <?php foreach ($spotterPhotos as $sp): ?>
                                <a href="<?= e($sp['photo_url']) ?>" target="_blank" class="block rounded-lg overflow-hidden bg-slate-950 border border-slate-800 hover:border-sky-500 transition">
                                    <img src="<?= e($sp['thumb_url']) ?>" alt="<?= e($aircraft['model_name']) ?>" class="w-full h-24 object-cover">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Ask AI About Aircraft -->
                <div class="va-card p-6 space-y-3">
                    <div class="w-10 h-10 rounded-xl bg-sky-500/10 border border-sky-500/30 flex items-center justify-center text-sky-400">
                        <i data-lucide="bot" class="w-5 h-5"></i>
                    </div>
                    <h3 class="text-sm font-bold text-white">Вопрос ИИ-Ассистенту</h3>
                    <p class="text-xs text-slate-400">
                        Хотите узнать сравнительный расход топлива, особенности пилотирования или аварийную статистику <?= e($aircraft['model_name']) ?>?
                    </p>
                    <button onclick="toggleAiWidget(); document.getElementById('ai-chat-input').value = 'Расскажи подробнее о самолете <?= e($aircraft['model_name']) ?>';" class="w-full py-2.5 bg-slate-800 hover:bg-slate-700 text-sky-400 font-mono font-bold rounded-xl text-xs border border-slate-700 transition">
                        Спросить у VladAero AI
                    </button>
                </div>
            </div>

        </div>
    </div>

    <?php
} else {
    // Catalog Grid View
    $pageTitle = 'Энциклопедия самолетов — База знаний';
    $metaDescription = 'Полный каталог пассажирских, региональных, военных и легкомоторных самолетов. Технические характеристики, дальность, чертежи и 3D-модели.';
    require_once __DIR__ . '/includes/header.php';

    // Fetch categories
    $catTable = Database::tableName('aircraft_categories');
    $categories = Database::isConfigured() ? Database::fetchAll("SELECT * FROM `{$catTable}`") : [];

    // Query builder
    $where = ["a.deleted_at IS NULL"];
    $params = [];

    if (!empty($categoryFilter)) {
        $where[] = "a.category_code = :cat";
        $params['cat'] = $categoryFilter;
    }
    if (!empty($searchQuery)) {
        $where[] = "(a.model_name LIKE :q OR a.icao_code LIKE :q OR a.short_desc LIKE :q)";
        $params['q'] = "%{$searchQuery}%";
    }

    $whereSql = implode(' AND ', $where);
    $aircraftList = Database::isConfigured() ? Database::fetchAll("SELECT a.*, m.name as manufacturer_name, s.max_range_km, s.cruise_speed_kmh, s.passengers_max FROM `{$aircraftTable}` a LEFT JOIN `{$mfgTable}` m ON a.manufacturer_id = m.id LEFT JOIN `{$specsTable}` s ON a.id = s.aircraft_id WHERE {$whereSql} ORDER BY a.id ASC") : [];
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Page Header -->
        <div class="va-card p-6 sm:p-8 mb-8">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3">
                        <i data-lucide="plane" class="w-8 h-8 text-sky-400"></i>
                        <span>Энциклопедия Воздушных Судов</span>
                    </h1>
                    <p class="text-xs text-slate-400 mt-1">
                        Каталог самолетов мира: детальные летно-технические характеристики (ЛТХ), дальность, вместимость и чертежи
                    </p>
                </div>

                <a href="<?= url('/compare.php') ?>" class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-400 text-xs font-mono font-bold border border-slate-700 transition">
                    <i data-lucide="scale" class="w-4 h-4 text-amber-400"></i>
                    <span>Инструмент сравнения ВС</span>
                </a>
            </div>

            <!-- Search and Filter Bar -->
            <form method="GET" class="flex flex-col sm:flex-row items-center gap-3">
                <div class="relative flex-grow w-full">
                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                    <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Поиск по названию или коду ICAO (A320, B777, МС-21, C172)..." class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-10 pr-4 py-2.5 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-sky-500 font-mono">
                </div>
                <button type="submit" class="w-full sm:w-auto bg-sky-600 hover:bg-sky-500 text-white font-mono text-xs font-bold px-5 py-2.5 rounded-xl transition">
                    Найти
                </button>
            </form>

            <!-- Category Pills -->
            <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-slate-800 text-xs font-mono">
                <a href="<?= url('/aircraft.php') ?>" class="px-3 py-1.5 rounded-lg border transition <?= empty($categoryFilter) ? 'bg-sky-600 border-sky-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-400 hover:text-white' ?>">
                    Все категории
                </a>
                <a href="?cat=airliner" class="px-3 py-1.5 rounded-lg border transition <?= ($categoryFilter === 'airliner') ? 'bg-sky-600 border-sky-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-400 hover:text-white' ?>">
                    Пассажирские
                </a>
                <a href="?cat=regional" class="px-3 py-1.5 rounded-lg border transition <?= ($categoryFilter === 'regional') ? 'bg-sky-600 border-sky-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-400 hover:text-white' ?>">
                    Региональные
                </a>
                <a href="?cat=ga" class="px-3 py-1.5 rounded-lg border transition <?= ($categoryFilter === 'ga') ? 'bg-sky-600 border-sky-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-400 hover:text-white' ?>">
                    Общая авиация (GA)
                </a>
                <a href="?cat=historic" class="px-3 py-1.5 rounded-lg border transition <?= ($categoryFilter === 'historic') ? 'bg-sky-600 border-sky-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-400 hover:text-white' ?>">
                    Исторические
                </a>
            </div>
        </div>

        <!-- Aircraft Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($aircraftList as $ac): ?>
                <div class="va-card p-6 flex flex-col justify-between group">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-sky-950 text-sky-400 border border-sky-800">
                                <?= e($ac['icao_code'] ?: 'ВС') ?>
                            </span>
                            <span class="text-xs text-slate-500 font-mono"><?= e($ac['manufacturer_name'] ?? '') ?></span>
                        </div>

                        <h3 class="text-xl font-bold text-white group-hover:text-sky-400 transition mb-2">
                            <a href="<?= url('/aircraft.php?slug=' . urlencode($ac['slug'])) ?>"><?= e($ac['model_name']) ?></a>
                        </h3>
                        <p class="text-xs text-slate-400 leading-relaxed mb-6 line-clamp-2">
                            <?= e($ac['short_desc']) ?>
                        </p>

                        <!-- Specs preview -->
                        <div class="grid grid-cols-3 gap-2 py-3 border-y border-slate-800 text-center font-mono text-xs mb-6">
                            <div>
                                <div class="text-[10px] text-slate-500">Дальность</div>
                                <div class="font-bold text-sky-400"><?= formatNumber($ac['max_range_km']) ?> км</div>
                            </div>
                            <div>
                                <div class="text-[10px] text-slate-500">Скорость</div>
                                <div class="font-bold text-emerald-400"><?= formatNumber($ac['cruise_speed_kmh']) ?> км/ч</div>
                            </div>
                            <div>
                                <div class="text-[10px] text-slate-500">Мест</div>
                                <div class="font-bold text-amber-400"><?= $ac['passengers_max'] ?: '—' ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <a href="<?= url('/aircraft.php?slug=' . urlencode($ac['slug'])) ?>" class="text-xs font-mono font-bold text-sky-400 flex items-center space-x-1 hover:underline">
                            <span>Характеристики</span>
                            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                        </a>
                        <a href="<?= url('/compare.php?ac1=' . $ac['id']) ?>" class="p-2 rounded-lg bg-slate-950 text-slate-400 hover:text-amber-400 border border-slate-800 transition" title="Сравнить">
                            <i data-lucide="scale" class="w-4 h-4"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <?php
}
require_once __DIR__ . '/includes/footer.php';
?>
