<?php
$pageTitle = 'Личный Логбук Полетов — Дневник Пилота и Пассажира';
$metaDescription = 'Персональный логбук авиаперелетов: учет налета часов, пройденного расстояния в километрах и милях, карта маршрутов и посещенных аэропортов.';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/e6b.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

$logTable = Database::tableName('user_flights');
$apTable = Database::tableName('airports');
$acTable = Database::tableName('aircraft');

// Handle Add Flight Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_flight'])) {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ошибка проверки CSRF токена');
    } else {
        $dep = strtoupper(trim($_POST['dep_icao'] ?? ''));
        $arr = strtoupper(trim($_POST['arr_icao'] ?? ''));
        $flightNo = strtoupper(trim($_POST['flight_no'] ?? ''));
        $flightDate = trim($_POST['flight_date'] ?? date('Y-m-d'));
        $durMins = (int)($_POST['duration_minutes'] ?? 0);
        $role = trim($_POST['role'] ?? 'passenger');
        $notes = trim($_POST['notes'] ?? '');

        // Distance calculation
        $depAp = Database::fetchOne("SELECT latitude, longitude FROM `{$apTable}` WHERE icao = :c LIMIT 1", ['c' => $dep]);
        $arrAp = Database::fetchOne("SELECT latitude, longitude FROM `{$apTable}` WHERE icao = :c LIMIT 1", ['c' => $arr]);

        $distKm = 0;
        if ($depAp && $arrAp) {
            $gc = E6B::calculateGreatCircle($depAp['latitude'], $depAp['longitude'], $arrAp['latitude'], $arrAp['longitude']);
            $distKm = $gc['distance_km'];
        }

        Database::insert('user_flights', [
            'user_id' => $user['id'],
            'flight_date' => $flightDate,
            'flight_number' => $flightNo,
            'departure_icao' => $dep,
            'arrival_icao' => $arr,
            'flight_time_minutes' => $durMins,
            'distance_km' => $distKm,
            'user_role' => $role,
            'notes' => $notes
        ]);

        Auth::addXp($user['id'], 30, 'Запись нового полета в логбук');
        setFlash('success', 'Полет успешно добавлен в ваш личный логбук! (+30 XP)');
        header('Location: ' . url('/logbook.php'));
        exit;
    }
}

// Fetch user flights
$flights = Database::isConfigured() ? Database::fetchAll("SELECT * FROM `{$logTable}` WHERE user_id = :uid ORDER BY flight_date DESC", ['uid' => $user['id']]) : [];

// Aggregate stats
$totalMinutes = array_sum(array_column($flights, 'flight_time_minutes'));
$totalKm = array_sum(array_column($flights, 'distance_km'));
$totalHours = floor($totalMinutes / 60);
$remMinutes = $totalMinutes % 60;
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header & Stats Overview -->
    <div class="va-card p-6 sm:p-8 mb-8">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3">
                    <i data-lucide="book-open" class="w-8 h-8 text-sky-400"></i>
                    <span>Личный Логбук Полетов</span>
                </h1>
                <p class="text-xs text-slate-400 mt-1 font-mono">
                    Пилот: <strong class="text-slate-200"><?= e($user['full_name'] ?: $user['username']) ?></strong> • Ранг: <span class="text-sky-400 font-bold"><?= e($user['rank_title']) ?></span>
                </p>
            </div>

            <button onclick="toggleFlightModal()" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-5 py-3 rounded-xl shadow-lg transition flex items-center space-x-2">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                <span>Добавить полет</span>
            </button>
        </div>

        <!-- Metrics Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 font-mono text-xs">
            <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                <div class="text-slate-400 text-[11px]">Всего полетов</div>
                <div class="text-2xl font-black text-sky-400 mt-1"><?= count($flights) ?></div>
            </div>
            <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                <div class="text-slate-400 text-[11px]">Общий налет</div>
                <div class="text-2xl font-black text-emerald-400 mt-1"><?= $totalHours ?>ч <?= $remMinutes ?>м</div>
            </div>
            <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                <div class="text-slate-400 text-[11px]">Пройденная дистанция</div>
                <div class="text-2xl font-black text-amber-400 mt-1"><?= formatNumber($totalKm) ?> км</div>
            </div>
            <div class="p-4 rounded-xl bg-slate-950/80 border border-slate-800">
                <div class="text-slate-400 text-[11px]">Очки опыта (XP)</div>
                <div class="text-2xl font-black text-purple-400 mt-1"><?= formatNumber($user['xp_points']) ?></div>
            </div>
        </div>
    </div>

    <!-- Flights History Table -->
    <div class="va-card overflow-hidden shadow-2xl">
        <div class="p-6 border-b border-slate-800">
            <h2 class="text-sm font-bold text-white uppercase font-mono tracking-wider">История полетов</h2>
        </div>

        <?php if (empty($flights)): ?>
            <div class="p-12 text-center text-slate-500 font-mono text-xs">
                В вашем логбуке пока нет записей. Добавьте ваш первый полет!
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="p-4">Дата</th>
                            <th class="p-4">Рейс</th>
                            <th class="p-4">Маршрут</th>
                            <th class="p-4">Время</th>
                            <th class="p-4">Дистанция</th>
                            <th class="p-4">Роль</th>
                            <th class="p-4">Заметки</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($flights as $f): 
                            $h = floor($f['flight_time_minutes'] / 60);
                            $m = $f['flight_time_minutes'] % 60;
                        ?>
                            <tr class="hover:bg-slate-900/60 transition">
                                <td class="p-4 text-slate-300"><?= formatDate($f['flight_date']) ?></td>
                                <td class="p-4 font-bold text-sky-400"><?= e($f['flight_number'] ?: '—') ?></td>
                                <td class="p-4 text-slate-100 font-bold">
                                    <?= e($f['departure_icao']) ?> <span class="text-slate-500">→</span> <?= e($f['arrival_icao']) ?>
                                </td>
                                <td class="p-4 text-emerald-400 font-bold"><?= $h ?>ч <?= $m ?>м</td>
                                <td class="p-4 text-slate-300"><?= formatNumber($f['distance_km']) ?> км</td>
                                <td class="p-4">
                                    <span class="px-2 py-0.5 rounded text-[10px] bg-slate-950 border border-slate-800 text-slate-400 uppercase">
                                        <?= e($f['user_role']) ?>
                                    </span>
                                </td>
                                <td class="p-4 text-slate-400 max-w-xs truncate"><?= e($f['notes'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Add Flight Modal -->
<div id="flight-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl p-6 relative">
        <button onclick="toggleFlightModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <h2 class="text-lg font-bold text-white mb-4 flex items-center space-x-2">
            <i data-lucide="plus-circle" class="w-5 h-5 text-sky-400"></i>
            <span>Добавить полет в логбук</span>
        </h2>

        <form method="POST" class="space-y-4 font-mono text-xs">
            <input type="hidden" name="add_flight" value="1">
            <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Вылет (ICAO):</label>
                    <input type="text" name="dep_icao" placeholder="UUEE" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase font-bold">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Прилет (ICAO):</label>
                    <input type="text" name="arr_icao" placeholder="ULLI" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase font-bold">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Номер рейса:</label>
                    <input type="text" name="flight_no" placeholder="SU 1024" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase font-bold">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Дата полета:</label>
                    <input type="date" name="flight_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Время полета (минут):</label>
                    <input type="number" name="duration_minutes" value="85" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Роль:</label>
                    <select name="role" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                        <option value="passenger">Пассажир</option>
                        <option value="pic">КВС (Pilot in Command)</option>
                        <option value="fo">Второй пилот (First Officer)</option>
                        <option value="student">Курсант (Student Pilot)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Заметки о полете:</label>
                <textarea name="notes" rows="2" placeholder="Эшелон FL340, красивый закат..." class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-slate-100 font-sans"></textarea>
            </div>

            <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl shadow-lg transition">
                Сохранить полет
            </button>
        </form>
    </div>
</div>

<script>
    function toggleFlightModal() {
        const modal = document.getElementById('flight-modal');
        if (modal) modal.classList.toggle('hidden');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
