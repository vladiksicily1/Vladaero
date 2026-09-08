<?php
$pageTitle = 'Сравнение самолетов (Side-by-Side Comparison)';
$metaDescription = 'Интерактивный инструмент сравнения летно-технических характеристик самолетов. Сопоставление дальности, скорости, полезной нагрузки и расхода топлива.';
require_once __DIR__ . '/includes/header.php';

$aircraftTable = Database::tableName('aircraft');
$specsTable = Database::tableName('aircraft_specs');
$mfgTable = Database::tableName('manufacturers');

// All available aircraft for select dropdowns
$allAircraft = Database::isConfigured() ? Database::fetchAll("SELECT id, model_name, icao_code FROM `{$aircraftTable}` WHERE deleted_at IS NULL ORDER BY model_name ASC") : [];

$ac1_id = (int)($_GET['ac1'] ?? ($allAircraft[0]['id'] ?? 1));
$ac2_id = (int)($_GET['ac2'] ?? ($allAircraft[1]['id'] ?? 2));
$ac3_id = (int)($_GET['ac3'] ?? 0);

function getAcDetails($id) {
    if (!$id) return null;
    $aircraftTable = Database::tableName('aircraft');
    $specsTable = Database::tableName('aircraft_specs');
    $mfgTable = Database::tableName('manufacturers');
    return Database::fetchOne("SELECT a.*, m.name as manufacturer_name, s.* FROM `{$aircraftTable}` a LEFT JOIN `{$mfgTable}` m ON a.manufacturer_id = m.id LEFT JOIN `{$specsTable}` s ON a.id = s.aircraft_id WHERE a.id = :id", ['id' => $id]);
}

$ac1 = getAcDetails($ac1_id);
$ac2 = getAcDetails($ac2_id);
$ac3 = getAcDetails($ac3_id);

$compared = array_filter([$ac1, $ac2, $ac3]);
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <div class="va-card p-6 sm:p-8 mb-8">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3">
                    <i data-lucide="scale" class="w-8 h-8 text-amber-400"></i>
                    <span>Сравнительный анализ самолетов</span>
                </h1>
                <p class="text-xs text-slate-400 mt-1">
                    Сопоставление летно-технических характеристик, дальности и весовых параметров бок о бок
                </p>
            </div>
        </div>

        <!-- Aircraft Selection Form -->
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-4 border-t border-slate-800">
            <div>
                <label class="block text-xs font-mono text-slate-400 mb-1">Самолет №1</label>
                <select name="ac1" onchange="this.form.submit()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-slate-100 font-mono focus:border-sky-500">
                    <?php foreach ($allAircraft as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= ($a['id'] == $ac1_id) ? 'selected' : '' ?>><?= e($a['model_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-mono text-slate-400 mb-1">Самолет №2</label>
                <select name="ac2" onchange="this.form.submit()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-slate-100 font-mono focus:border-sky-500">
                    <?php foreach ($allAircraft as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= ($a['id'] == $ac2_id) ? 'selected' : '' ?>><?= e($a['model_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-mono text-slate-400 mb-1">Самолет №3 (Опционально)</label>
                <select name="ac3" onchange="this.form.submit()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-slate-100 font-mono focus:border-sky-500">
                    <option value="0">— Не выбрано —</option>
                    <?php foreach ($allAircraft as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= ($a['id'] == $ac3_id) ? 'selected' : '' ?>><?= e($a['model_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if (!empty($compared)): ?>
        <!-- Comparison Table -->
        <div class="va-card overflow-hidden mb-12 shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <!-- Table Head -->
                    <thead>
                        <tr class="bg-slate-950 border-b border-slate-800 text-slate-400">
                            <th class="p-4 w-1/4">Параметр ЛТХ</th>
                            <?php foreach ($compared as $c): ?>
                                <th class="p-4 text-center">
                                    <div class="text-sm font-bold text-white"><?= e($c['model_name']) ?></div>
                                    <div class="text-[10px] text-sky-400"><?= e($c['icao_code'] ?: '—') ?></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>

                    <!-- Table Body -->
                    <tbody class="divide-y divide-slate-800/80">
                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Производитель</td>
                            <?php foreach ($compared as $c): ?>
                                <td class="p-4 text-center text-slate-200"><?= e($c['manufacturer_name'] ?? '—') ?></td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Макс. дальность полета</td>
                            <?php 
                            $maxRange = max(array_column($compared, 'max_range_km'));
                            foreach ($compared as $c): 
                                $isBest = ($c['max_range_km'] == $maxRange);
                            ?>
                                <td class="p-4 text-center font-bold <?= $isBest ? 'text-emerald-400 bg-emerald-500/5' : 'text-slate-200' ?>">
                                    <?= formatNumber($c['max_range_km']) ?> км <?= $isBest ? '★' : '' ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Крейсерская скорость</td>
                            <?php 
                            $maxSpeed = max(array_column($compared, 'cruise_speed_kmh'));
                            foreach ($compared as $c): 
                                $isBest = ($c['cruise_speed_kmh'] == $maxSpeed);
                            ?>
                                <td class="p-4 text-center font-bold <?= $isBest ? 'text-emerald-400 bg-emerald-500/5' : 'text-slate-200' ?>">
                                    <?= formatNumber($c['cruise_speed_kmh']) ?> км/ч (M <?= $c['mach_cruise'] ?: '—' ?>)
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Пассажировместимость (макс.)</td>
                            <?php 
                            $maxPass = max(array_column($compared, 'passengers_max'));
                            foreach ($compared as $c): 
                                $isBest = ($c['passengers_max'] == $maxPass && $maxPass > 0);
                            ?>
                                <td class="p-4 text-center font-bold <?= $isBest ? 'text-amber-400 bg-amber-500/5' : 'text-slate-200' ?>">
                                    <?= formatNumber($c['passengers_max']) ?> чел.
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Макс. взлетная масса (MTOW)</td>
                            <?php foreach ($compared as $c): ?>
                                <td class="p-4 text-center text-slate-200"><?= formatNumber($c['mtow_kg']) ?> кг</td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Полезная нагрузка</td>
                            <?php foreach ($compared as $c): ?>
                                <td class="p-4 text-center text-slate-200"><?= formatNumber($c['max_payload_kg']) ?> кг</td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Размах крыла</td>
                            <?php foreach ($compared as $c): ?>
                                <td class="p-4 text-center text-slate-200"><?= $c['wingspan_m'] ?> м</td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Длина самолета</td>
                            <?php foreach ($compared as $c): ?>
                                <td class="p-4 text-center text-slate-200"><?= $c['length_m'] ?> м</td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Двигатели</td>
                            <?php foreach ($compared as $c): ?>
                                <td class="p-4 text-center text-slate-200"><?= e($c['engines_count']) ?> × <?= e($c['engine_model'] ?: $c['engine_type']) ?></td>
                            <?php endforeach; ?>
                        </tr>

                        <tr>
                            <td class="p-4 text-slate-400 font-semibold">Практический потолок</td>
                            <?php foreach ($compared as $c): ?>
                                <td class="p-4 text-center text-slate-200"><?= formatNumber($c['service_ceiling_m']) ?> м</td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
