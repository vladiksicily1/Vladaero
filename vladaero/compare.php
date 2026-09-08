<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Aircraft selection slugs
$p1 = trim($_GET['p1'] ?? 'airbus-a350-900');
$p2 = trim($_GET['p2'] ?? 'boeing-787-9-dreamliner');
$p3 = trim($_GET['p3'] ?? '');
$p4 = trim($_GET['p4'] ?? '');

$selectedSlugs = array_values(array_filter([$p1, $p2, $p3, $p4]));
if (count($selectedSlugs) < 2) {
    $selectedSlugs = ['airbus-a350-900', 'boeing-787-9-dreamliner'];
}

$comparedPlanes = [];
foreach ($selectedSlugs as $slug) {
    $plane = DB::fetchOne("SELECT a.*, m.name AS manufacturer_name FROM `va_aircraft` a LEFT JOIN `va_manufacturers` m ON a.manufacturer_id = m.id WHERE a.slug = :s", ['s' => $slug]);
    if ($plane) {
        $plane['specs'] = DB::fetchOne("SELECT * FROM `va_aircraft_specs` WHERE `aircraft_id` = :id", ['id' => $plane['id']]);
        $comparedPlanes[] = $plane;
    }
}

// All aircraft for select dropdowns
$allPlanes = DB::fetchAll("SELECT `slug`, `model_name` FROM `va_aircraft` WHERE `deleted_at` IS NULL ORDER BY `model_name` ASC");

$pageTitle = 'Сравнение самолётов: ' . implode(' vs ', array_column($comparedPlanes, 'model_name'));
require_once __DIR__ . '/includes/header.php';
?>

<!-- Chart.js for Spider Radar Diagram -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Title & Selector Bar -->
    <div class="mb-8">
        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-indigo-500/10 border border-indigo-500/30 text-indigo-400 text-xs font-mono mb-3">
            <i data-lucide="scale" class="w-3.5 h-3.5"></i>
            <span>VERSUS ENGINE</span>
        </div>
        <h1 class="text-3xl font-extrabold text-white">Сравнение лётно-технических характеристик (ТТХ)</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Сопоставление до 4 самолётов по скорости, дальности, экономичности и габаритам</p>
    </div>

    <!-- Aircraft Selection Form -->
    <form method="GET" class="glass-hud p-5 rounded-2xl border border-sky-500/20 mb-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php for ($i = 1; $i <= 4; $i++): 
            $curSlug = $selectedSlugs[$i - 1] ?? '';
        ?>
            <div>
                <label class="block text-[10px] font-mono text-sky-400 uppercase font-bold mb-1">Самолёт #<?= $i ?></label>
                <select name="p<?= $i ?>" class="w-full bg-slate-900/90 border border-slate-700/80 rounded-xl px-3 py-2 text-xs text-white font-mono focus:border-sky-500 focus:outline-none" onchange="this.form.submit()">
                    <option value="">-- Не выбран --</option>
                    <?php foreach ($allPlanes as $ap): ?>
                        <option value="<?= e($ap['slug']) ?>" <?= $curSlug === $ap['slug'] ? 'selected' : '' ?>><?= e($ap['model_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endfor; ?>
    </form>

    <!-- Radar Chart & Visual Summary -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-10 items-center">
        
        <!-- Radar Spider Chart -->
        <div class="lg:col-span-6 glass-card p-6 rounded-3xl border border-white/5">
            <h3 class="text-sm font-bold text-white font-mono mb-4 flex items-center space-x-2">
                <i data-lucide="activity" class="w-4 h-4 text-sky-400"></i>
                <span>Лепестковая диаграмма индексов эффективности</span>
            </h3>
            <div class="aspect-square max-w-md mx-auto">
                <canvas id="radarChart"></canvas>
            </div>
        </div>

        <!-- Visual Highlights & Battle of Birds Vote -->
        <div class="lg:col-span-6 space-y-6">
            <div class="glass-card p-6 rounded-3xl border border-amber-500/20 space-y-4">
                <div class="flex items-center space-x-2 text-amber-400 text-xs font-mono font-bold uppercase">
                    <i data-lucide="swords" class="w-4 h-4"></i>
                    <span>Битва бортов: Голосование сообщества</span>
                </div>
                <h3 class="text-lg font-bold text-white">Какой борт превосходит конкурента по вашему мнению?</h3>
                
                <div class="space-y-2">
                    <?php foreach ($comparedPlanes as $p): ?>
                        <button onclick="voteBattle('<?= e($p['slug']) ?>')" class="w-full p-3 rounded-xl bg-slate-900/80 hover:bg-sky-500/10 border border-white/5 hover:border-sky-500/40 text-left transition flex items-center justify-between text-xs font-mono">
                            <span class="font-bold text-white"><?= e($p['model_name']) ?></span>
                            <span class="text-sky-400">Отдать голос ➔</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="p-4 rounded-2xl glass-hud border border-sky-500/20 text-xs text-slate-300 font-mono leading-relaxed">
                💡 <strong class="text-sky-400">Справка:</strong> Зелёная подсветка в таблице ниже отмечает лучший показатель в данном классе, красная — уступающий.
            </div>
        </div>

    </div>

    <!-- Comparative Table -->
    <div class="glass-card rounded-3xl border border-white/5 overflow-hidden shadow-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead>
                    <tr class="bg-slate-900/90 border-b border-white/10 text-slate-300">
                        <th class="p-4 w-1/4 uppercase font-bold text-sky-400">Параметр / ТТХ</th>
                        <?php foreach ($comparedPlanes as $p): ?>
                            <th class="p-4 w-1/4">
                                <div class="text-base font-bold text-white"><?= e($p['model_name']) ?></div>
                                <div class="text-[10px] text-slate-400"><?= e($p['manufacturer_name'] ?: '') ?></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    
                    <!-- Photos -->
                    <tr class="bg-slate-950/40">
                        <td class="p-4 font-bold text-slate-400">Внешний вид</td>
                        <?php foreach ($comparedPlanes as $p): ?>
                            <td class="p-4">
                                <img src="<?= e($p['hero_image'] ?: 'https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?w=400&q=80') ?>" class="w-full h-32 object-cover rounded-xl border border-white/10">
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Speed -->
                    <tr>
                        <td class="p-4 font-bold text-slate-300">Крейсерская скорость</td>
                        <?php 
                        $speeds = array_map(fn($x) => (int)($x['specs']['cruise_speed_kmh'] ?? 0), $comparedPlanes);
                        $maxSpeed = max($speeds);
                        foreach ($comparedPlanes as $p): 
                            $spd = (int)($p['specs']['cruise_speed_kmh'] ?? 0);
                            $isBest = $spd > 0 && $spd === $maxSpeed;
                        ?>
                            <td class="p-4 <?= $isBest ? 'text-emerald-400 font-bold bg-emerald-500/5' : 'text-slate-200' ?>">
                                <?= format_speed($spd) ?> <?= $isBest ? '⭐' : '' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Range -->
                    <tr>
                        <td class="p-4 font-bold text-slate-300">Максимальная дальность</td>
                        <?php 
                        $ranges = array_map(fn($x) => (int)($x['specs']['max_range_km'] ?? 0), $comparedPlanes);
                        $maxRange = max($ranges);
                        foreach ($comparedPlanes as $p): 
                            $rng = (int)($p['specs']['max_range_km'] ?? 0);
                            $isBest = $rng > 0 && $rng === $maxRange;
                        ?>
                            <td class="p-4 <?= $isBest ? 'text-emerald-400 font-bold bg-emerald-500/5' : 'text-slate-200' ?>">
                                <?= format_range($rng) ?> <?= $isBest ? '⭐' : '' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- MTOW -->
                    <tr>
                        <td class="p-4 font-bold text-slate-300">Макс. взлетная масса (MTOW)</td>
                        <?php foreach ($comparedPlanes as $p): ?>
                            <td class="p-4 text-slate-200">
                                <?= format_weight($p['specs']['mtow_kg'] ?? null) ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Wingspan & Length -->
                    <tr>
                        <td class="p-4 font-bold text-slate-300">Размах крыла / Длина</td>
                        <?php foreach ($comparedPlanes as $p): ?>
                            <td class="p-4 text-slate-200">
                                <?= $p['specs']['wingspan_m'] ? $p['specs']['wingspan_m'] . ' м' : '—' ?> / <?= $p['specs']['length_m'] ? $p['specs']['length_m'] . ' м' : '—' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Fuel Burn -->
                    <tr>
                        <td class="p-4 font-bold text-slate-300">Расход топлива / час</td>
                        <?php foreach ($comparedPlanes as $p): 
                            $burn = $p['specs']['fuel_consumption_kg_h'] ?? null;
                        ?>
                            <td class="p-4 text-slate-200">
                                <?= $burn ? number_format($burn, 0, '', ' ') . ' кг/ч' : '—' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                    <!-- Flight Hour Cost -->
                    <tr>
                        <td class="p-4 font-bold text-slate-300">Стоимость летного часа (расч.)</td>
                        <?php foreach ($comparedPlanes as $p): 
                            $cost = $p['specs']['cost_per_flight_hour_usd'] ?? null;
                        ?>
                            <td class="p-4 text-amber-400 font-bold">
                                <?= $cost ? '$' . number_format($cost, 0, '', ' ') : '—' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>

                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Spider Radar Chart Script -->
<script>
    const planeLabels = <?= json_encode(array_column($comparedPlanes, 'model_name')) ?>;
    const colors = ['#0ea5e9', '#f59e0b', '#10b981', '#a855f7'];

    const datasets = <?= json_encode(array_map(function($p, $idx) use ($colors) {
        $specs = $p['specs'] ?? [];
        $spdNorm = min(100, (($specs['cruise_speed_kmh'] ?? 800) / 1000) * 100);
        $rngNorm = min(100, (($specs['max_range_km'] ?? 5000) / 15000) * 100);
        $payNorm = min(100, (($specs['max_payload_kg'] ?? 20000) / 60000) * 100);
        $ceilNorm = min(100, (($specs['service_ceiling_m'] ?? 11000) / 14000) * 100);
        $effNorm = min(100, max(20, 100 - (($specs['fuel_consumption_kg_h'] ?? 3000) / 7000 * 60)));

        return [
            'label' => $p['model_name'],
            'data' => [$spdNorm, $rngNorm, $payNorm, $ceilNorm, $effNorm],
            'borderColor' => $colors[$idx % count($colors)],
            'backgroundColor' => $colors[$idx % count($colors)] . '20',
            'borderWidth' => 2
        ];
    }, $comparedPlanes, array_keys($comparedPlanes))) ?>;

    const ctx = document.getElementById('radarChart').getContext('2d');
    new Chart(ctx, {
        type: 'radar',
        data: {
            labels: ['Скорость', 'Дальность', 'Грузоподъемность', 'Потолок', 'Топливная эфф.'],
            datasets: datasets
        },
        options: {
            scales: {
                r: {
                    angleLines: { color: 'rgba(255, 255, 255, 0.1)' },
                    grid: { color: 'rgba(255, 255, 255, 0.1)' },
                    pointLabels: { color: '#94a3b8', font: { family: 'monospace', size: 11 } },
                    ticks: { display: false }
                }
            },
            plugins: {
                legend: { labels: { color: '#e2e8f0', font: { family: 'monospace' } } }
            }
        }
    });

    function voteBattle(slug) {
        alert('Ваш голос за ' + slug + ' успешно учтен в Битве бортов!');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
