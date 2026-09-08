<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Управление аэропортами и ВПП';
require_once __DIR__ . '/header.php';

$action = $_GET['action'] ?? 'list';
$airportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $icao = strtoupper(trim($_POST['icao'] ?? ''));
    $iata = strtoupper(trim($_POST['iata'] ?? ''));
    $nameRu = trim($_POST['name_ru'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $cityRu = trim($_POST['city_ru'] ?? '');
    $cityEn = trim($_POST['city_en'] ?? '');
    $countryRu = trim($_POST['country_ru'] ?? 'Россия');
    $countryIso = strtoupper(trim($_POST['country_iso'] ?? 'RU'));
    $lat = (float)($_POST['latitude'] ?? 0);
    $lon = (float)($_POST['longitude'] ?? 0);
    $elevM = (int)($_POST['elevation_m'] ?? 0);
    $transAlt = (int)($_POST['transition_alt_ft'] ?? 5000);

    $aptData = [
        'icao'              => $icao,
        'iata'              => $iata,
        'name_ru'           => $nameRu,
        'name_en'           => $nameEn,
        'city_ru'           => $cityRu,
        'city_en'           => $cityEn,
        'country_ru'        => $countryRu,
        'country_iso'       => $countryIso,
        'latitude'          => $lat,
        'longitude'         => $lon,
        'elevation_m'       => $elevM,
        'elevation_ft'      => (int)round($elevM * 3.28084),
        'transition_alt_ft' => $transAlt
    ];

    if ($airportId > 0) {
        DB::update('va_airports', $aptData, '`id` = :id', ['id' => $airportId]);
        record_audit('admin_update_airport', 'airport', $airportId);
    } else {
        $airportId = (int)DB::insert('va_airports', $aptData);
        record_audit('admin_create_airport', 'airport', $airportId);
    }

    header('Location: airports.php');
    exit;
}

$airport = $airportId > 0 ? DB::fetchOne("SELECT * FROM `va_airports` WHERE `id` = :id", ['id' => $airportId]) : null;
$airportsList = DB::fetchAll("SELECT * FROM `va_airports` ORDER BY `city_ru` ASC");
?>

<div class="space-y-6 font-mono text-xs">
    
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans">Управление базой аэропортов</h1>
            <p class="text-xs text-slate-400 mt-1">Редактирование ICAO/IATA кодов, координат, полос и споттинг-локаций</p>
        </div>

        <?php if ($action !== 'add' && $action !== 'edit'): ?>
            <a href="airports.php?action=add" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold transition flex items-center space-x-1.5 shadow-lg shadow-sky-600/30">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Добавить аэропорт</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($action === 'add' || $action === 'edit'): ?>
        <form method="POST" class="glass-card rounded-3xl p-6 border border-sky-500/30 shadow-2xl space-y-6">
            <?= csrf_field() ?>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Код ICAO</label>
                    <input type="text" name="icao" value="<?= e($airport['icao'] ?? '') ?>" required maxlength="4" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase font-bold">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Код IATA</label>
                    <input type="text" name="iata" value="<?= e($airport['iata'] ?? '') ?>" maxlength="3" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Широта (Latitude)</label>
                    <input type="number" step="0.000001" name="latitude" value="<?= $airport['latitude'] ?? 55.9726 ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Долгота (Longitude)</label>
                    <input type="number" step="0.000001" name="longitude" value="<?= $airport['longitude'] ?? 37.4145 ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Название (RU)</label>
                    <input type="text" name="name_ru" value="<?= e($airport['name_ru'] ?? '') ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-bold">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Название (EN)</label>
                    <input type="text" name="name_en" value="<?= e($airport['name_en'] ?? '') ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Город (RU)</label>
                    <input type="text" name="city_ru" value="<?= e($airport['city_ru'] ?? '') ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Город (EN)</label>
                    <input type="text" name="city_en" value="<?= e($airport['city_en'] ?? '') ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Высота (м)</label>
                    <input type="number" name="elevation_m" value="<?= $airport['elevation_m'] ?? 190 ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div class="flex justify-end space-x-3">
                <a href="airports.php" class="px-5 py-2.5 rounded-xl bg-slate-800 text-slate-300">Отмена</a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold shadow-lg shadow-sky-600/30">
                    Сохранить аэропорт
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="glass-card rounded-3xl border border-white/5 overflow-hidden shadow-2xl">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-900/90 text-slate-400 border-b border-white/10 uppercase text-[10px]">
                    <tr>
                        <th class="p-4">ICAO</th>
                        <th class="p-4">IATA</th>
                        <th class="p-4">Аэропорт</th>
                        <th class="p-4">Город</th>
                        <th class="p-4">Координаты</th>
                        <th class="p-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($airportsList as $apt): ?>
                        <tr class="hover:bg-sky-500/5 transition">
                            <td class="p-4 font-bold text-sky-400"><?= e($apt['icao']) ?></td>
                            <td class="p-4 text-amber-400 font-bold"><?= e($apt['iata'] ?: '—') ?></td>
                            <td class="p-4 font-bold text-white"><?= e($apt['name_ru']) ?></td>
                            <td class="p-4 text-slate-300"><?= e($apt['city_ru']) ?></td>
                            <td class="p-4 text-slate-400"><?= round((float)$apt['latitude'], 2) ?>, <?= round((float)$apt['longitude'], 2) ?></td>
                            <td class="p-4 text-right">
                                <a href="airports.php?action=edit&id=<?= $apt['id'] ?>" class="text-sky-400 hover:underline">Изменить</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
