<?php
$adminTitle = 'Управление Аэропортами и ВПП';
require_once __DIR__ . '/header.php';

$apTable = Database::tableName('airports');
$rwyTable = Database::tableName('runways');

// Handle Add Airport
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_airport'])) {
    $icao = strtoupper(trim($_POST['icao'] ?? ''));
    $iata = strtoupper(trim($_POST['iata'] ?? ''));
    $nameRu = trim($_POST['name_ru'] ?? '');
    $cityRu = trim($_POST['city_ru'] ?? '');
    $countryRu = trim($_POST['country_ru'] ?? 'Россия');
    $lat = (float)($_POST['latitude'] ?? 0);
    $lon = (float)($_POST['longitude'] ?? 0);
    $elev = (int)($_POST['elevation_ft'] ?? 0);

    Database::insert('airports', [
        'icao' => $icao,
        'iata' => $iata,
        'name_ru' => $nameRu,
        'name_en' => $nameRu,
        'city_ru' => $cityRu,
        'country_ru' => $countryRu,
        'latitude' => $lat,
        'longitude' => $lon,
        'elevation_ft' => $elev,
        'elevation_m' => round($elev * 0.3048),
        'timezone' => 'UTC+3'
    ]);

    setFlash('success', "Аэропорт {$icao} успешно добавлен!");
    header('Location: ' . url('/admin/airports.php'));
    exit;
}

$airports = Database::fetchAll("SELECT * FROM `{$apTable}` ORDER BY id DESC");
?>

<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">База Аэропортов и ВПП</h1>
            <p class="text-xs text-slate-400 font-mono">Добавление аэродромов, радиочастот и схем захода</p>
        </div>

        <button onclick="document.getElementById('ap-modal').classList.remove('hidden')" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-4 py-2.5 rounded-xl shadow-lg transition flex items-center space-x-1.5">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Добавить аэропорт</span>
        </button>
    </div>

    <!-- Table -->
    <div class="va-card overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ICAO</th>
                        <th class="p-4">IATA</th>
                        <th class="p-4">Название</th>
                        <th class="p-4">Город</th>
                        <th class="p-4">Страна</th>
                        <th class="p-4">Превышение</th>
                        <th class="p-4 text-right">Ссылка</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php foreach ($airports as $ap): ?>
                        <tr class="hover:bg-slate-900/60 transition">
                            <td class="p-4 font-bold text-sky-400"><?= e($ap['icao']) ?></td>
                            <td class="p-4 font-bold text-slate-200"><?= e($ap['iata'] ?: '—') ?></td>
                            <td class="p-4 font-bold text-white"><?= e($ap['name_ru']) ?></td>
                            <td class="p-4 text-slate-400"><?= e($ap['city_ru']) ?></td>
                            <td class="p-4 text-slate-500"><?= e($ap['country_ru']) ?></td>
                            <td class="p-4 text-emerald-400"><?= $ap['elevation_ft'] ?> ft</td>
                            <td class="p-4 text-right">
                                <a href="<?= url('/airports.php?code=' . urlencode($ap['icao'])) ?>" target="_blank" class="text-slate-400 hover:text-white">
                                    <i data-lucide="external-link" class="w-4 h-4 inline"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal -->
<div id="ap-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl p-6 relative">
        <button onclick="document.getElementById('ap-modal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-white">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <h2 class="text-lg font-bold text-white mb-4">Добавить Аэропорт</h2>

        <form method="POST" class="space-y-4 font-mono text-xs">
            <input type="hidden" name="save_airport" value="1">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">ICAO код:</label>
                    <input type="text" name="icao" required placeholder="UUEE" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sky-400 font-bold uppercase">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">IATA код:</label>
                    <input type="text" name="iata" placeholder="SVO" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-bold uppercase">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Название (рус):</label>
                <input type="text" name="name_ru" required placeholder="Шереметьево им. А.С. Пушкина" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-sans">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Город:</label>
                    <input type="text" name="city_ru" required placeholder="Москва" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-sans">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Превышение (ft):</label>
                    <input type="number" name="elevation_ft" value="623" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Широта (Lat):</label>
                    <input type="number" step="0.0001" name="latitude" value="55.9726" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Долгота (Lon):</label>
                    <input type="number" step="0.0001" name="longitude" value="37.4145" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
            </div>

            <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl shadow-lg transition">
                Сохранить аэропорт
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
