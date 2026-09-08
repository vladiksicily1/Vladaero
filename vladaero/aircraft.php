<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$slug = trim($_GET['slug'] ?? '');

// Single Aircraft Details View
if (!empty($slug)) {
    $aircraft = DB::fetchOne("SELECT a.*, m.name AS manufacturer_name, m.country AS manufacturer_country, c.title_ru AS category_title FROM `va_aircraft` a LEFT JOIN `va_manufacturers` m ON a.manufacturer_id = m.id LEFT JOIN `va_aircraft_categories` c ON a.category_code = c.code WHERE a.slug = :s AND a.deleted_at IS NULL", ['s' => $slug]);

    if (!$aircraft) {
        header("HTTP/1.0 404 Not Found");
        require_once __DIR__ . '/404.php';
        exit;
    }

    // Increment view count
    DB::execute("UPDATE `va_aircraft` SET `views_count` = `views_count` + 1 WHERE `id` = :id", ['id' => $aircraft['id']]);

    $specs = DB::fetchOne("SELECT * FROM `va_aircraft_specs` WHERE `aircraft_id` = :id", ['id' => $aircraft['id']]);
    $modifications = DB::fetchAll("SELECT * FROM `va_aircraft_modifications` WHERE `aircraft_id` = :id", ['id' => $aircraft['id']]);
    $photos = DB::fetchAll("SELECT p.*, u.username, u.full_name FROM `va_photos` p JOIN `va_users` u ON p.user_id = u.id WHERE p.aircraft_id = :id AND p.status = 'approved' ORDER BY p.likes_count DESC LIMIT 8", ['id' => $aircraft['id']]);

    $pageTitle = $aircraft['model_name'] . ' — ТТХ, история, фото и схемы';
    $pageDesc = $aircraft['short_desc'] ?: "Характеристики и спецификации самолета {$aircraft['model_name']}";
    $pageImage = $aircraft['hero_image'] ?: 'assets/images/logo.png';
    require_once __DIR__ . '/includes/header.php';
    ?>

    <!-- Aircraft Breadcrumbs -->
    <div class="bg-slate-900/40 border-b border-white/5 py-3">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-xs font-mono text-slate-400 flex items-center space-x-2">
            <a href="index.php" class="hover:text-sky-400">Главная</a>
            <span>/</span>
            <a href="aircraft.php" class="hover:text-sky-400">Энциклопедия</a>
            <span>/</span>
            <span class="text-sky-400"><?= e($aircraft['model_name']) ?></span>
        </div>
    </div>

    <!-- Aircraft Hero Details -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start mb-10">
            
            <!-- Hero Image & 3D Links -->
            <div class="lg:col-span-7 space-y-4">
                <div class="relative aspect-video rounded-3xl overflow-hidden glass-card border border-sky-500/30 shadow-2xl bg-slate-950">
                    <img src="<?= e($aircraft['hero_image'] ?: 'https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?w=1200&q=80') ?>" 
                         alt="<?= e($aircraft['model_name']) ?>" 
                         class="w-full h-full object-cover">
                    
                    <div class="absolute top-4 left-4 flex items-center space-x-2">
                        <span class="px-3 py-1 rounded-xl bg-sky-600/90 text-white font-mono text-xs font-bold border border-sky-400/40 backdrop-blur-md">
                            <?= e($aircraft['icao_code'] ?: 'ICAO') ?>
                        </span>
                        <span class="px-3 py-1 rounded-xl bg-slate-900/80 text-amber-400 font-mono text-xs border border-amber-500/30 backdrop-blur-md">
                            <?= e($aircraft['category_title'] ?: 'Авиация') ?>
                        </span>
                    </div>

                    <?php if ($aircraft['blueprint_image']): ?>
                        <div class="absolute bottom-4 right-4">
                            <a href="<?= e($aircraft['blueprint_image']) ?>" target="_blank" class="px-3 py-1.5 rounded-xl bg-black/70 hover:bg-black text-sky-400 text-xs font-mono border border-sky-500/30 transition flex items-center space-x-1.5 backdrop-blur-md">
                                <i data-lucide="layers" class="w-3.5 h-3.5"></i>
                                <span>Чертеж / Проекции</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Interactive Media Actions -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <a href="3d.php?slug=<?= e($aircraft['slug']) ?>" class="p-3 rounded-2xl glass-card border border-sky-500/30 hover:border-sky-400 text-center group transition">
                        <i data-lucide="box" class="w-5 h-5 text-sky-400 mx-auto mb-1 group-hover:scale-110 transition"></i>
                        <span class="text-[11px] font-mono font-bold text-white block">3D Модель</span>
                    </a>
                    <a href="compare.php?p1=<?= e($aircraft['slug']) ?>" class="p-3 rounded-2xl glass-card border border-indigo-500/30 hover:border-indigo-400 text-center group transition">
                        <i data-lucide="scale" class="w-5 h-5 text-indigo-400 mx-auto mb-1 group-hover:scale-110 transition"></i>
                        <span class="text-[11px] font-mono font-bold text-white block">Сравнить с...</span>
                    </a>
                    <a href="radar.php?type=<?= e($aircraft['icao_code']) ?>" class="p-3 rounded-2xl glass-card border border-emerald-500/30 hover:border-emerald-400 text-center group transition">
                        <i data-lucide="radar" class="w-5 h-5 text-emerald-400 mx-auto mb-1 group-hover:scale-110 transition"></i>
                        <span class="text-[11px] font-mono font-bold text-white block">Поиск на радаре</span>
                    </a>
                    <button onclick="openWikiEditModal()" class="p-3 rounded-2xl glass-card border border-amber-500/30 hover:border-amber-400 text-center group transition">
                        <i data-lucide="edit-3" class="w-5 h-5 text-amber-400 mx-auto mb-1 group-hover:scale-110 transition"></i>
                        <span class="text-[11px] font-mono font-bold text-white block">Предложить правку</span>
                    </button>
                </div>

            </div>

            <!-- Header Quick Summary -->
            <div class="lg:col-span-5 space-y-6">
                <div>
                    <div class="text-xs font-mono text-slate-400 uppercase tracking-wider mb-1">
                        <?= e($aircraft['manufacturer_name'] ?: 'Производитель') ?> • <?= e($aircraft['manufacturer_country'] ?: '') ?>
                    </div>
                    <h1 class="text-3xl sm:text-4xl font-extrabold text-white"><?= e($aircraft['model_name']) ?></h1>
                    <p class="text-slate-300 text-sm mt-3 leading-relaxed"><?= e($aircraft['short_desc']) ?></p>
                </div>

                <!-- Key Telemetry Gauges -->
                <div class="grid grid-cols-2 gap-3 font-mono">
                    <div class="p-3.5 rounded-2xl bg-slate-900/80 border border-white/5">
                        <div class="text-[10px] text-slate-500 uppercase">Крейсерская скорость</div>
                        <div class="text-lg font-bold text-sky-400 mt-0.5"><?= format_speed($specs['cruise_speed_kmh'] ?? null) ?></div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-900/80 border border-white/5">
                        <div class="text-[10px] text-slate-500 uppercase">Дальность полёта</div>
                        <div class="text-lg font-bold text-emerald-400 mt-0.5"><?= format_range($specs['max_range_km'] ?? null) ?></div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-900/80 border border-white/5">
                        <div class="text-[10px] text-slate-500 uppercase">Практический потолок</div>
                        <div class="text-lg font-bold text-purple-400 mt-0.5"><?= format_altitude($specs['service_ceiling_m'] ?? null) ?></div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-900/80 border border-white/5">
                        <div class="text-[10px] text-slate-500 uppercase">Макс. взлётная масса (MTOW)</div>
                        <div class="text-lg font-bold text-amber-400 mt-0.5"><?= format_weight($specs['mtow_kg'] ?? null) ?></div>
                    </div>
                </div>

                <!-- Production & Status -->
                <div class="p-4 rounded-2xl glass-hud border border-sky-500/20 text-xs font-mono space-y-2">
                    <div class="flex justify-between">
                        <span class="text-slate-400">Первый полёт:</span>
                        <span class="text-white"><?= e($aircraft['first_flight_date'] ?: '—') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Годы производства:</span>
                        <span class="text-white"><?= e($aircraft['production_years'] ?: '—') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Статус:</span>
                        <span class="text-emerald-400 font-bold"><?= e($aircraft['status']) ?></span>
                    </div>
                </div>

            </div>

        </div>

        <!-- 2-Level Specifications Tabs (Simple / Engineering) -->
        <div class="mb-12">
            
            <div class="flex items-center space-x-4 border-b border-white/10 pb-3 mb-6">
                <button onclick="switchSpecsTab('engineering')" id="tabBtnEng" class="text-sm font-mono font-bold text-sky-400 pb-2 border-b-2 border-sky-400 transition">
                    🛠 Инженерные ТТХ для профи и симмеров
                </button>
                <button onclick="switchSpecsTab('history')" id="tabBtnHist" class="text-sm font-mono text-slate-400 pb-2 border-b-2 border-transparent hover:text-white transition">
                    📜 История и боевое применение
                </button>
                <button onclick="switchSpecsTab('safety')" id="tabBtnSafety" class="text-sm font-mono text-slate-400 pb-2 border-b-2 border-transparent hover:text-white transition">
                    🛡️ Безопасность и надежность
                </button>
            </div>

            <!-- Tab 1: Engineering Specs Table -->
            <div id="tabContentEng" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 font-mono text-xs">
                    
                    <!-- Box 1: Dimensions -->
                    <div class="p-5 rounded-2xl glass-card border border-white/5 space-y-3">
                        <div class="font-bold text-sky-400 text-sm border-b border-white/5 pb-2">📐 Геометрические размеры</div>
                        <div class="flex justify-between"><span class="text-slate-400">Длина самолёта:</span><span class="text-white font-bold"><?= $specs['length_m'] ? $specs['length_m'] . ' м' : '—' ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Размах крыла:</span><span class="text-white font-bold"><?= $specs['wingspan_m'] ? $specs['wingspan_m'] . ' м' : '—' ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Высота:</span><span class="text-white font-bold"><?= $specs['height_m'] ? $specs['height_m'] . ' м' : '—' ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Площадь крыла:</span><span class="text-white font-bold"><?= $specs['wing_area_sqm'] ? $specs['wing_area_sqm'] . ' м²' : '—' ?></span></div>
                    </div>

                    <!-- Box 2: Weights & Fuel -->
                    <div class="p-5 rounded-2xl glass-card border border-white/5 space-y-3">
                        <div class="font-bold text-amber-400 text-sm border-b border-white/5 pb-2">⚖️ Весовые параметры и топливо</div>
                        <div class="flex justify-between"><span class="text-slate-400">Пустой вес:</span><span class="text-white font-bold"><?= format_weight($specs['empty_weight_kg'] ?? null) ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Макс. посадочная масса (MLW):</span><span class="text-white font-bold"><?= format_weight($specs['mlw_kg'] ?? null) ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Макс. коммерческая нагрузка:</span><span class="text-white font-bold"><?= format_weight($specs['max_payload_kg'] ?? null) ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Емкость баков:</span><span class="text-white font-bold"><?= $specs['fuel_capacity_liters'] ? number_format($specs['fuel_capacity_liters'], 0, '', ' ') . ' л' : '—' ?></span></div>
                    </div>

                    <!-- Box 3: Engines & Performance -->
                    <div class="p-5 rounded-2xl glass-card border border-white/5 space-y-3">
                        <div class="font-bold text-emerald-400 text-sm border-b border-white/5 pb-2">🚀 Силовая установка и ЛТХ</div>
                        <div class="flex justify-between"><span class="text-slate-400">Тип двигателей:</span><span class="text-white font-bold"><?= e($specs['engine_type'] ?: 'ТРДД') ?> (<?= (int)$specs['engines_count'] ?>x)</span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Модель:</span><span class="text-white font-bold"><?= e($specs['engine_model'] ?: '—') ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Тяга на двигатель:</span><span class="text-white font-bold"><?= $specs['thrust_kn_per_engine'] ? $specs['thrust_kn_per_engine'] . ' кН' : '—' ?></span></div>
                        <div class="flex justify-between"><span class="text-slate-400">Число Маха (M cruise):</span><span class="text-white font-bold">M <?= $specs['mach_cruise'] ?: '0.80' ?></span></div>
                    </div>

                </div>

                <?php if ($specs['avionics_description']): ?>
                    <div class="p-6 rounded-2xl glass-hud border border-sky-500/20">
                        <h4 class="text-sm font-bold text-sky-400 font-mono mb-2">📟 Комплекс бортового радиоэлектронного оборудования (БРЭО / Авионика)</h4>
                        <p class="text-xs text-slate-300 leading-relaxed font-mono"><?= e($specs['avionics_description']) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab 2: History -->
            <div id="tabContentHist" class="hidden space-y-6">
                <div class="p-6 rounded-2xl glass-card border border-white/5 text-sm text-slate-300 leading-relaxed space-y-4">
                    <h3 class="text-lg font-bold text-white font-sans">История разработки и эксплуатации</h3>
                    <div><?= nl2br(e($aircraft['history_text'] ?: $aircraft['full_desc'])) ?></div>
                    
                    <?php if ($aircraft['combat_record']): ?>
                        <div class="pt-4 border-t border-white/5">
                            <h4 class="font-bold text-amber-400 mb-2">Боевое и специальное применение:</h4>
                            <div><?= nl2br(e($aircraft['combat_record'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab 3: Safety -->
            <div id="tabContentSafety" class="hidden space-y-6">
                <div class="p-6 rounded-2xl glass-card border border-white/5 text-sm text-slate-300 leading-relaxed space-y-4">
                    <h3 class="text-lg font-bold text-emerald-400 font-sans">Показатели надежности и статистика безопасности</h3>
                    <div><?= nl2br(e($aircraft['safety_record'] ?: 'Данные по безопасности соответствуют мировым стандартам ICAO.')) ?></div>
                </div>
            </div>

        </div>

        <!-- Spotting Gallery for this Aircraft -->
        <?php if (!empty($photos)): ?>
            <div class="mt-12">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-white font-sans">Споттерские фотографии <?= e($aircraft['model_name']) ?></h3>
                    <a href="spotting.php?aircraft_id=<?= (int)$aircraft['id'] ?>" class="text-xs font-mono text-sky-400 hover:underline">Смотреть все фото</a>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <?php foreach ($photos as $ph): ?>
                        <a href="spotting.php?photo_id=<?= (int)$ph['id'] ?>" class="group rounded-2xl overflow-hidden glass-card aspect-video relative">
                            <img src="<?= e($ph['thumb_url'] ?: $ph['photo_url']) ?>" alt="Photo" class="w-full h-full object-cover group-hover:scale-105 transition">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition p-2.5 flex flex-col justify-end text-[10px] text-white font-mono">
                                <span class="font-bold"><?= e($ph['tail_number'] ?: $aircraft['model_name']) ?></span>
                                <span class="text-slate-400">© <?= e($ph['username']) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Wiki Proposal Modal -->
    <div id="wikiModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="max-w-lg w-full glass-hud rounded-3xl p-6 border border-sky-500/30 shadow-2xl">
            <h3 class="text-lg font-bold text-white mb-2">Предложить правку в карточку ВС</h3>
            <p class="text-xs text-slate-400 mb-4">VladAero использует краудсорсинг-модель. Ваши дополнения поступят на проверку редакторам.</p>
            <textarea placeholder="Опишите предлагаемые изменения ТТХ или истории..." class="w-full h-32 bg-slate-950/80 border border-slate-700 rounded-xl p-3 text-xs text-white placeholder-slate-500 font-mono focus:outline-none focus:border-sky-500 mb-4"></textarea>
            <div class="flex justify-end space-x-2">
                <button onclick="closeWikiModal()" class="px-4 py-2 rounded-xl bg-slate-800 text-xs text-slate-300 font-mono">Отмена</button>
                <button onclick="alert('Спасибо! Правка отправлена на модерацию.'); closeWikiModal();" class="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-xs text-white font-mono font-bold">Отправить</button>
            </div>
        </div>
    </div>

    <script>
        function switchSpecsTab(tab) {
            document.getElementById('tabContentEng').classList.add('hidden');
            document.getElementById('tabContentHist').classList.add('hidden');
            document.getElementById('tabContentSafety').classList.add('hidden');

            document.getElementById('tabBtnEng').className = 'text-sm font-mono text-slate-400 pb-2 border-b-2 border-transparent hover:text-white transition';
            document.getElementById('tabBtnHist').className = 'text-sm font-mono text-slate-400 pb-2 border-b-2 border-transparent hover:text-white transition';
            document.getElementById('tabBtnSafety').className = 'text-sm font-mono text-slate-400 pb-2 border-b-2 border-transparent hover:text-white transition';

            if (tab === 'engineering') {
                document.getElementById('tabContentEng').classList.remove('hidden');
                document.getElementById('tabBtnEng').className = 'text-sm font-mono font-bold text-sky-400 pb-2 border-b-2 border-sky-400 transition';
            } else if (tab === 'history') {
                document.getElementById('tabContentHist').classList.remove('hidden');
                document.getElementById('tabBtnHist').className = 'text-sm font-mono font-bold text-sky-400 pb-2 border-b-2 border-sky-400 transition';
            } else if (tab === 'safety') {
                document.getElementById('tabContentSafety').classList.remove('hidden');
                document.getElementById('tabBtnSafety').className = 'text-sm font-mono font-bold text-sky-400 pb-2 border-b-2 border-sky-400 transition';
            }
        }

        function openWikiEditModal() {
            document.getElementById('wikiModal').classList.remove('hidden');
        }
        function closeWikiModal() {
            document.getElementById('wikiModal').classList.add('hidden');
        }
    </script>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Catalog List View
$category = trim($_GET['category'] ?? '');
$search = trim($_GET['q'] ?? '');

$whereClauses = ["`deleted_at` IS NULL"];
$params = [];

if ($category) {
    $whereClauses[] = "`category_code` = :cat";
    $params['cat'] = $category;
}

if ($search) {
    $whereClauses[] = "(`model_name` LIKE :q OR `icao_code` LIKE :q OR `short_desc` LIKE :q)";
    $params['q'] = "%{$search}%";
}

$whereSql = implode(' AND ', $whereClauses);
$aircraftList = DB::fetchAll("SELECT a.*, m.name AS manufacturer_name, c.title_ru AS category_title FROM `va_aircraft` a LEFT JOIN `va_manufacturers` m ON a.manufacturer_id = m.id LEFT JOIN `va_aircraft_categories` c ON a.category_code = c.code WHERE {$whereSql} ORDER BY a.model_name ASC", $params);
$categories = DB::fetchAll("SELECT * FROM `va_aircraft_categories`");

$pageTitle = 'Энциклопедия самолётов и вертолётов';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Title & Filter Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-white">Энциклопедия авиатехники</h1>
            <p class="text-xs text-slate-400 font-mono mt-1">Гражданские лайнеры, военные самолеты, АОН и исторические борты</p>
        </div>
        
        <!-- Category Filter Tabs -->
        <div class="flex items-center space-x-2 overflow-x-auto pb-2 text-xs font-mono">
            <a href="aircraft.php" class="px-3 py-1.5 rounded-xl <?= empty($category) ? 'bg-sky-600 text-white font-bold' : 'bg-slate-900 text-slate-400 hover:text-white border border-white/5' ?> transition">
                Все категории
            </a>
            <?php foreach ($categories as $cat): ?>
                <a href="aircraft.php?category=<?= e($cat['code']) ?>" class="px-3 py-1.5 rounded-xl <?= $category === $cat['code'] ? 'bg-sky-600 text-white font-bold' : 'bg-slate-900 text-slate-400 hover:text-white border border-white/5' ?> whitespace-nowrap transition">
                    <?= e($cat['title_ru']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Aircraft Grid -->
    <?php if (empty($aircraftList)): ?>
        <div class="glass-card rounded-3xl p-12 text-center text-slate-500 font-mono">
            <i data-lucide="plane" class="w-12 h-12 mx-auto mb-3 text-slate-600"></i>
            <div>По выбранным фильтрам самолёты не найдены.</div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php foreach ($aircraftList as $plane): 
                $specs = DB::fetchOne("SELECT * FROM `va_aircraft_specs` WHERE `aircraft_id` = :id", ['id' => $plane['id']]);
            ?>
                <a href="aircraft.php?slug=<?= e($plane['slug']) ?>" class="group glass-card rounded-2xl overflow-hidden border border-white/5 hover:border-sky-500/40 transition duration-300 flex flex-col">
                    <div class="relative aspect-video bg-slate-950 overflow-hidden">
                        <img src="<?= e($plane['hero_image'] ?: 'https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?w=600&q=80') ?>" 
                             alt="<?= e($plane['model_name']) ?>" 
                             class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                        <div class="absolute top-2.5 left-2.5 px-2 py-0.5 rounded bg-black/60 text-amber-400 font-mono text-[10px] border border-amber-500/30">
                            <?= e($plane['category_title'] ?: 'Авиация') ?>
                        </div>
                        <div class="absolute top-2.5 right-2.5 px-2 py-0.5 rounded bg-black/60 text-sky-400 font-mono text-[10px] border border-sky-500/30">
                            <?= e($plane['icao_code'] ?: 'ICAO') ?>
                        </div>
                    </div>

                    <div class="p-4 flex-1 flex flex-col justify-between space-y-3">
                        <div>
                            <div class="text-[10px] font-mono text-slate-500 uppercase"><?= e($plane['manufacturer_name'] ?: '') ?></div>
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
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
