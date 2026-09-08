<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Управление самолётами и ТТХ';
require_once __DIR__ . '/header.php';

$action = $_GET['action'] ?? 'list';
$aircraftId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Save Aircraft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $modelName = trim($_POST['model_name'] ?? '');
    $slug = trim($_POST['slug'] ?? '') ?: slugify($modelName);
    $categoryCode = $_POST['category_code'] ?? 'civil';
    $icao = strtoupper(trim($_POST['icao_code'] ?? ''));
    $shortDesc = trim($_POST['short_desc'] ?? '');
    $fullDesc = trim($_POST['full_desc'] ?? '');
    $heroImage = trim($_POST['hero_image'] ?? '');
    $status = $_POST['status'] ?? 'in_service';

    $aircraftData = [
        'model_name'    => $modelName,
        'slug'          => $slug,
        'category_code' => $categoryCode,
        'icao_code'     => $icao,
        'short_desc'    => $shortDesc,
        'full_desc'     => $fullDesc,
        'hero_image'    => $heroImage,
        'status'        => $status
    ];

    if ($aircraftId > 0) {
        DB::update('va_aircraft', $aircraftData, '`id` = :id', ['id' => $aircraftId]);
        record_audit('admin_update_aircraft', 'aircraft', $aircraftId);
    } else {
        $aircraftId = (int)DB::insert('va_aircraft', $aircraftData);
        record_audit('admin_create_aircraft', 'aircraft', $aircraftId);
    }

    // Save Specs
    $specsData = [
        'aircraft_id'          => $aircraftId,
        'cruise_speed_kmh'     => (int)($_POST['cruise_speed_kmh'] ?? 0),
        'max_range_km'         => (int)($_POST['max_range_km'] ?? 0),
        'service_ceiling_m'    => (int)($_POST['service_ceiling_m'] ?? 0),
        'mtow_kg'              => (int)($_POST['mtow_kg'] ?? 0),
        'wingspan_m'           => (float)($_POST['wingspan_m'] ?? 0),
        'length_m'             => (float)($_POST['length_m'] ?? 0),
        'engines_count'        => (int)($_POST['engines_count'] ?? 2),
        'engine_type'          => trim($_POST['engine_type'] ?? 'ТРДД'),
        'avionics_description' => trim($_POST['avionics_description'] ?? '')
    ];

    $existingSpecs = DB::fetchOne("SELECT `id` FROM `va_aircraft_specs` WHERE `aircraft_id` = :id", ['id' => $aircraftId]);
    if ($existingSpecs) {
        DB::update('va_aircraft_specs', $specsData, '`aircraft_id` = :id', ['id' => $aircraftId]);
    } else {
        DB::insert('va_aircraft_specs', $specsData);
    }

    header('Location: aircraft.php');
    exit;
}

// Delete Aircraft (Soft delete)
if ($action === 'delete' && $aircraftId > 0 && verify_csrf($_GET['csrf'] ?? '')) {
    DB::update('va_aircraft', ['deleted_at' => date('Y-m-d H:i:s')], '`id` = :id', ['id' => $aircraftId]);
    record_audit('admin_delete_aircraft', 'aircraft', $aircraftId);
    header('Location: aircraft.php');
    exit;
}

$aircraft = $aircraftId > 0 ? DB::fetchOne("SELECT * FROM `va_aircraft` WHERE `id` = :id", ['id' => $aircraftId]) : null;
$specs = $aircraftId > 0 ? DB::fetchOne("SELECT * FROM `va_aircraft_specs` WHERE `aircraft_id` = :id", ['id' => $aircraftId]) : [];
$categories = DB::fetchAll("SELECT * FROM `va_aircraft_categories`");
$aircraftList = DB::fetchAll("SELECT a.*, c.title_ru AS category_title FROM `va_aircraft` a LEFT JOIN `va_aircraft_categories` c ON a.category_code = c.code WHERE a.deleted_at IS NULL ORDER BY a.model_name ASC");
?>

<div class="space-y-6 font-mono text-xs">
    
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans">Управление базой самолётов</h1>
            <p class="text-xs text-slate-400 mt-1">Редактирование карточек техники, инженерных ТТХ и 3D-моделей</p>
        </div>

        <?php if ($action !== 'edit' && $action !== 'add'): ?>
            <a href="aircraft.php?action=add" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold transition flex items-center space-x-1.5 shadow-lg shadow-sky-600/30">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Добавить самолёт</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($action === 'edit' || $action === 'add'): ?>
        <!-- Edit / Create Form -->
        <form method="POST" class="glass-card rounded-3xl p-6 border border-sky-500/30 shadow-2xl space-y-6">
            <?= csrf_field() ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Модель самолёта</label>
                    <input type="text" name="model_name" value="<?= e($aircraft['model_name'] ?? '') ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-bold text-sm">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Код ICAO</label>
                    <input type="text" name="icao_code" value="<?= e($aircraft['icao_code'] ?? '') ?>" placeholder="T154, A359" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Категория</label>
                    <select name="category_code" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['code'] ?>" <?= ($aircraft['category_code'] ?? '') === $cat['code'] ? 'selected' : '' ?>><?= e($cat['title_ru']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Краткое описание (для превью)</label>
                <input type="text" name="short_desc" value="<?= e($aircraft['short_desc'] ?? '') ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Ссылка на фото (Hero Image)</label>
                <input type="text" name="hero_image" value="<?= e($aircraft['hero_image'] ?? '') ?>" placeholder="https://..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <!-- Engineering Specs Row -->
            <div class="p-5 rounded-2xl bg-slate-900/80 border border-white/5 space-y-4">
                <h4 class="font-bold text-sky-400 text-sm">Лётно-технические характеристики (ТТХ)</h4>
                
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-slate-400 text-[10px] mb-1">Крейсерская скор. (км/ч)</label>
                        <input type="number" name="cruise_speed_kmh" value="<?= $specs['cruise_speed_kmh'] ?? 850 ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-slate-400 text-[10px] mb-1">Дальность (км)</label>
                        <input type="number" name="max_range_km" value="<?= $specs['max_range_km'] ?? 6000 ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-slate-400 text-[10px] mb-1">Потолок (м)</label>
                        <input type="number" name="service_ceiling_m" value="<?= $specs['service_ceiling_m'] ?? 12000 ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-slate-400 text-[10px] mb-1">MTOW (кг)</label>
                        <input type="number" name="mtow_kg" value="<?= $specs['mtow_kg'] ?? 80000 ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white">
                    </div>
                </div>
            </div>

            <div class="flex justify-end space-x-3">
                <a href="aircraft.php" class="px-5 py-2.5 rounded-xl bg-slate-800 text-slate-300">Отмена</a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold shadow-lg shadow-sky-600/30">
                    Сохранить самолёт
                </button>
            </div>
        </form>
    <?php else: ?>
        <!-- List View -->
        <div class="glass-card rounded-3xl border border-white/5 overflow-hidden shadow-2xl">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-900/90 text-slate-400 border-b border-white/10 uppercase text-[10px]">
                    <tr>
                        <th class="p-4">Модель</th>
                        <th class="p-4">ICAO</th>
                        <th class="p-4">Категория</th>
                        <th class="p-4">Просмотры</th>
                        <th class="p-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($aircraftList as $p): ?>
                        <tr class="hover:bg-sky-500/5 transition">
                            <td class="p-4 font-bold text-white"><?= e($p['model_name']) ?></td>
                            <td class="p-4 text-sky-400 font-bold"><?= e($p['icao_code'] ?: '—') ?></td>
                            <td class="p-4 text-slate-300"><?= e($p['category_title'] ?: '—') ?></td>
                            <td class="p-4 text-slate-400"><?= (int)$p['views_count'] ?></td>
                            <td class="p-4 text-right space-x-2">
                                <a href="aircraft.php?action=edit&id=<?= $p['id'] ?>" class="text-sky-400 hover:underline">Изменить</a>
                                <a href="aircraft.php?action=delete&id=<?= $p['id'] ?>&csrf=<?= csrf_token() ?>" onclick="return confirm('Удалить этот самолет?')" class="text-rose-400 hover:underline">Удалить</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
