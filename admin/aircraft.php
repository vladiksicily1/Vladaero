<?php
$adminTitle = 'Управление Базой Самолетов';
require_once __DIR__ . '/header.php';

$acTable = Database::tableName('aircraft');
$specsTable = Database::tableName('aircraft_specs');
$mfgTable = Database::tableName('manufacturers');

// Handle Soft Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    Database::update('aircraft', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    setFlash('success', "Самолет #{$id} успешно удален.");
    header('Location: ' . url('/admin/aircraft.php'));
    exit;
}

// Handle Add / Edit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_aircraft'])) {
    $id = (int)($_POST['id'] ?? 0);
    $modelName = trim($_POST['model_name'] ?? '');
    $icaoCode = strtoupper(trim($_POST['icao_code'] ?? ''));
    $catCode = trim($_POST['category_code'] ?? 'airliner');
    $shortDesc = trim($_POST['short_desc'] ?? '');
    $fullDesc = trim($_POST['full_desc'] ?? '');
    $slug = slugify($modelName);

    // Specs
    $range = (float)($_POST['max_range_km'] ?? 0);
    $speed = (float)($_POST['cruise_speed_kmh'] ?? 0);
    $passengers = (int)($_POST['passengers_max'] ?? 0);
    $mtow = (float)($_POST['mtow_kg'] ?? 0);
    $wingspan = (float)($_POST['wingspan_m'] ?? 0);
    $length = (float)($_POST['length_m'] ?? 0);
    $enginesCount = (int)($_POST['engines_count'] ?? 2);
    $engineModel = trim($_POST['engine_model'] ?? '');

    if ($id > 0) {
        // Update
        Database::update('aircraft', [
            'model_name' => $modelName,
            'icao_code' => $icaoCode,
            'category_code' => $catCode,
            'short_desc' => $shortDesc,
            'full_desc' => $fullDesc
        ], 'id = :id', ['id' => $id]);

        Database::update('aircraft_specs', [
            'max_range_km' => $range,
            'cruise_speed_kmh' => $speed,
            'passengers_max' => $passengers,
            'mtow_kg' => $mtow,
            'wingspan_m' => $wingspan,
            'length_m' => $length,
            'engines_count' => $enginesCount,
            'engine_model' => $engineModel
        ], 'aircraft_id = :id', ['id' => $id]);

        setFlash('success', 'Характеристики самолета успешно обновлены!');
    } else {
        // Insert
        $newId = Database::insert('aircraft', [
            'model_name' => $modelName,
            'slug' => $slug,
            'icao_code' => $icaoCode,
            'category_code' => $catCode,
            'short_desc' => $shortDesc,
            'full_desc' => $fullDesc
        ]);

        Database::insert('aircraft_specs', [
            'aircraft_id' => $newId,
            'max_range_km' => $range,
            'cruise_speed_kmh' => $speed,
            'passengers_max' => $passengers,
            'mtow_kg' => $mtow,
            'wingspan_m' => $wingspan,
            'length_m' => $length,
            'engines_count' => $enginesCount,
            'engine_model' => $engineModel
        ]);

        setFlash('success', 'Новый самолет успешно добавлен в базу!');
    }

    header('Location: ' . url('/admin/aircraft.php'));
    exit;
}

$aircraftList = Database::fetchAll("SELECT a.*, s.max_range_km, s.cruise_speed_kmh, s.passengers_max FROM `{$acTable}` a LEFT JOIN `{$specsTable}` s ON a.id = s.aircraft_id WHERE a.deleted_at IS NULL ORDER BY a.id DESC");
?>

<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">База Данных Самолетов</h1>
            <p class="text-xs text-slate-400 font-mono">Добавление, редактирование ЛТХ и управление моделями</p>
        </div>

        <button onclick="openAircraftModal(0)" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-4 py-2.5 rounded-xl shadow-lg transition flex items-center space-x-1.5">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Добавить модель</span>
        </button>
    </div>

    <!-- Table -->
    <div class="va-card overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Код ICAO</th>
                        <th class="p-4">Модель</th>
                        <th class="p-4">Категория</th>
                        <th class="p-4">Дальность</th>
                        <th class="p-4">Скорость</th>
                        <th class="p-4">Мест</th>
                        <th class="p-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php foreach ($aircraftList as $ac): ?>
                        <tr class="hover:bg-slate-900/60 transition">
                            <td class="p-4 text-slate-500">#<?= $ac['id'] ?></td>
                            <td class="p-4 font-bold text-sky-400"><?= e($ac['icao_code']) ?></td>
                            <td class="p-4 font-bold text-white"><?= e($ac['model_name']) ?></td>
                            <td class="p-4 text-slate-400"><?= e($ac['category_code']) ?></td>
                            <td class="p-4 text-emerald-400"><?= formatNumber($ac['max_range_km']) ?> км</td>
                            <td class="p-4 text-slate-200"><?= formatNumber($ac['cruise_speed_kmh']) ?> км/ч</td>
                            <td class="p-4 text-amber-400"><?= $ac['passengers_max'] ?: '—' ?></td>
                            <td class="p-4 text-right space-x-2">
                                <a href="<?= url('/aircraft.php?slug=' . urlencode($ac['slug'])) ?>" target="_blank" class="text-slate-400 hover:text-white" title="Просмотр">
                                    <i data-lucide="external-link" class="w-4 h-4 inline"></i>
                                </a>
                                <a href="?delete=<?= $ac['id'] ?>" onclick="return confirm('Удалить самолет?');" class="text-red-400 hover:text-red-300" title="Удалить">
                                    <i data-lucide="trash-2" class="w-4 h-4 inline"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal Form -->
<div id="aircraft-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl shadow-2xl p-6 relative max-h-[90vh] overflow-y-auto">
        <button onclick="closeAircraftModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <h2 id="modal-title" class="text-lg font-bold text-white mb-4 flex items-center space-x-2">
            <i data-lucide="plane" class="w-5 h-5 text-sky-400"></i>
            <span>Добавление Воздушного Судна</span>
        </h2>

        <form method="POST" class="space-y-4 font-mono text-xs">
            <input type="hidden" name="save_aircraft" value="1">
            <input type="hidden" name="id" id="form-id" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-slate-400 mb-1">Название модели:</label>
                    <input type="text" name="model_name" id="form-model" required placeholder="МС-21-310" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-bold">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Код ICAO:</label>
                    <input type="text" name="icao_code" id="form-icao" required placeholder="MC21" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sky-400 font-bold uppercase">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Категория:</label>
                    <select name="category_code" id="form-cat" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                        <option value="airliner">Пассажирский магистральный</option>
                        <option value="regional">Региональный лайнер</option>
                        <option value="ga">Общая авиация (GA)</option>
                        <option value="military">Военный / Транспортный</option>
                        <option value="historic">Исторический</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Макс. мест (Passengers):</label>
                    <input type="number" name="passengers_max" id="form-pass" value="211" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
            </div>

            <!-- Specs Row -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Дальность (км):</label>
                    <input type="number" name="max_range_km" id="form-range" value="6000" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Скорость (км/ч):</label>
                    <input type="number" name="cruise_speed_kmh" id="form-speed" value="870" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">MTOW (кг):</label>
                    <input type="number" name="mtow_kg" id="form-mtow" value="79250" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Размах крыла (м):</label>
                    <input type="number" step="0.1" name="wingspan_m" id="form-wingspan" value="35.9" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Кол-во моторов:</label>
                    <input type="number" name="engines_count" id="form-engines-cnt" value="2" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Модель двигателя:</label>
                    <input type="text" name="engine_model" id="form-engine-mod" placeholder="ПД-14 / PW1400G" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Краткое описание:</label>
                <textarea name="short_desc" id="form-short" rows="2" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-slate-100 font-sans"></textarea>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Полный текст истории и конструкции:</label>
                <textarea name="full_desc" id="form-full" rows="4" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-slate-100 font-sans"></textarea>
            </div>

            <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl shadow-lg transition">
                Сохранить самолет в базу
            </button>
        </form>
    </div>
</div>

<script>
    function openAircraftModal(id) {
        document.getElementById('form-id').value = id;
        document.getElementById('aircraft-modal').classList.remove('hidden');
    }

    function closeAircraftModal() {
        document.getElementById('aircraft-modal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
