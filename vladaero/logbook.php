<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/e6b.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];

// Handle new flight submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_flight') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $flightNo = strtoupper(trim($_POST['flight_number'] ?? ''));
        $flightDate = $_POST['flight_date'] ?? date('Y-m-d');
        $depId = (int)($_POST['dep_airport_id'] ?? 0);
        $arrId = (int)($_POST['arr_airport_id'] ?? 0);
        $aircraftId = !empty($_POST['aircraft_id']) ? (int)$_POST['aircraft_id'] : null;
        $seat = trim($_POST['seat_number'] ?? '');
        $seatClass = $_POST['seat_class'] ?? 'economy';
        $rating = (int)($_POST['rating'] ?? 5);
        $notes = trim($_POST['notes'] ?? '');

        // Calculate distance
        $depApt = DB::fetchOne("SELECT `latitude`, `longitude` FROM `va_airports` WHERE `id` = :id", ['id' => $depId]);
        $arrApt = DB::fetchOne("SELECT `latitude`, `longitude` FROM `va_airports` WHERE `id` = :id", ['id' => $arrId]);

        $distKm = 1000;
        $durMin = 90;
        if ($depApt && $arrApt) {
            $gc = E6B::calculateGreatCircle((float)$depApt['latitude'], (float)$depApt['longitude'], (float)$arrApt['latitude'], (float)$arrApt['longitude']);
            $distKm = $gc['distance_km'];
            $durMin = $gc['flight_time_min'];
        }

        DB::insert('va_flight_logs', [
            'user_id'        => $userId,
            'flight_date'    => $flightDate,
            'flight_number'  => $flightNo,
            'dep_airport_id' => $depId,
            'arr_airport_id' => $arrId,
            'aircraft_id'    => $aircraftId,
            'distance_km'    => $distKm,
            'duration_min'   => $durMin,
            'seat_number'    => $seat,
            'seat_class'     => $seatClass,
            'rating'         => $rating,
            'notes'          => $notes
        ]);

        Auth::addXp($userId, 100);
        set_flash('success', 'Полёт успешно добавлен в ваш бортовой журнал (+100 XP)!');
        header('Location: logbook.php');
        exit;
    }
}

// Fetch user flights
$flights = DB::fetchAll(
    "SELECT fl.*, dep.name_ru AS dep_name, dep.icao AS dep_icao, arr.name_ru AS arr_name, arr.icao AS arr_icao, a.model_name 
     FROM `va_flight_logs` fl 
     JOIN `va_airports` dep ON fl.dep_airport_id = dep.id 
     JOIN `va_airports` arr ON fl.arr_airport_id = arr.id 
     LEFT JOIN `va_aircraft` a ON fl.aircraft_id = a.id 
     WHERE fl.user_id = :u 
     ORDER BY fl.flight_date DESC",
    ['u' => $userId]
);

$allAirports = DB::fetchAll("SELECT `id`, `name_ru`, `icao` FROM `va_airports` ORDER BY `name_ru` ASC");
$allAircraft = DB::fetchAll("SELECT `id`, `model_name` FROM `va_aircraft` ORDER BY `model_name` ASC");

// Totals
$totalFlights = count($flights);
$totalDistKm = array_sum(array_column($flights, 'distance_km'));
$totalMinutes = array_sum(array_column($flights, 'duration_min'));
$totalHours = round($totalMinutes / 60, 1);

$pageTitle = 'Мой бортовой журнал полетов (Flight Logbook)';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Title & Add Button -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-white">Бортовой журнал перелётов (Flight Logbook)</h1>
            <p class="text-xs text-slate-400 font-mono mt-1">Личная статистика посещенных аэропортов, налета часов и пройденных километров</p>
        </div>

        <button onclick="openAddFlightModal()" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-mono text-xs font-bold shadow-lg shadow-sky-600/30 transition flex items-center space-x-2">
            <i data-lucide="plus-circle" class="w-4 h-4"></i>
            <span>Записать новый полёт</span>
        </button>
    </div>

    <!-- Statistics Dashboard -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 font-mono mb-10">
        <div class="p-5 rounded-2xl glass-card border border-white/5">
            <div class="text-xs text-slate-400 uppercase">Всего полётов</div>
            <div class="text-2xl font-bold text-sky-400 mt-1"><?= $totalFlights ?></div>
        </div>
        <div class="p-5 rounded-2xl glass-card border border-white/5">
            <div class="text-xs text-slate-400 uppercase">Общая дистанция</div>
            <div class="text-2xl font-bold text-emerald-400 mt-1"><?= number_format($totalDistKm, 0, '', ' ') ?> км</div>
        </div>
        <div class="p-5 rounded-2xl glass-card border border-white/5">
            <div class="text-xs text-slate-400 uppercase">Время в воздухе</div>
            <div class="text-2xl font-bold text-amber-400 mt-1"><?= $totalHours ?> ч</div>
        </div>
        <div class="p-5 rounded-2xl glass-card border border-white/5">
            <div class="text-xs text-slate-400 uppercase">Эквивалент Земли</div>
            <div class="text-2xl font-bold text-purple-400 mt-1"><?= round($totalDistKm / 40075, 2) ?>x</div>
        </div>
    </div>

    <!-- Flights History Table -->
    <?php if (empty($flights)): ?>
        <div class="glass-card rounded-3xl p-16 text-center text-slate-500 font-mono">
            <i data-lucide="book-open" class="w-12 h-12 mx-auto mb-3 text-slate-600"></i>
            <div>В вашем журнале пока нет записей. Добавьте свой первый совершённый рейс!</div>
        </div>
    <?php else: ?>
        <div class="glass-card rounded-3xl border border-white/5 overflow-hidden shadow-2xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-slate-900/90 text-slate-400 border-b border-white/10 uppercase font-bold text-[10px]">
                        <tr>
                            <th class="p-4">Дата</th>
                            <th class="p-4">Рейс</th>
                            <th class="p-4">Маршрут</th>
                            <th class="p-4">Самолёт</th>
                            <th class="p-4">Дистанция</th>
                            <th class="p-4">Место</th>
                            <th class="p-4">Оценка</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($flights as $fl): ?>
                            <tr class="hover:bg-sky-500/5 transition">
                                <td class="p-4 text-slate-400"><?= e($fl['flight_date']) ?></td>
                                <td class="p-4 font-bold text-white"><?= e($fl['flight_number']) ?></td>
                                <td class="p-4 text-sky-400 font-bold">
                                    <?= e($fl['dep_icao']) ?> → <?= e($fl['arr_icao']) ?>
                                    <span class="block text-[10px] text-slate-400 font-normal"><?= e($fl['dep_name']) ?> — <?= e($fl['arr_name']) ?></span>
                                </td>
                                <td class="p-4 text-slate-300"><?= e($fl['model_name'] ?: '—') ?></td>
                                <td class="p-4 text-slate-300"><?= number_format((int)$fl['distance_km'], 0, '', ' ') ?> км</td>
                                <td class="p-4 text-slate-400"><?= e($fl['seat_number'] ?: '—') ?> (<?= e($fl['seat_class']) ?>)</td>
                                <td class="p-4 text-amber-400 font-bold"><?= str_repeat('★', (int)$fl['rating']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Add Flight Modal -->
<div id="addFlightModal" class="fixed inset-0 z-50 bg-black/75 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="max-w-xl w-full glass-hud rounded-3xl p-6 border border-sky-500/30 shadow-2xl overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-4">
            <h3 class="text-lg font-bold text-white font-mono flex items-center space-x-2">
                <i data-lucide="plus-circle" class="w-5 h-5 text-sky-400"></i>
                <span>Запись полёта в журнал</span>
            </h3>
            <button onclick="closeAddFlightModal()" class="p-1 rounded hover:bg-white/10 text-slate-400">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4 font-mono text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_flight">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Номер рейса</label>
                    <input type="text" name="flight_number" placeholder="например: SU102, S72504" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-mono uppercase">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Дата вылета</label>
                    <input type="date" name="flight_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Аэропорт вылета</label>
                    <select name="dep_airport_id" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <?php foreach ($allAirports as $apt): ?>
                            <option value="<?= $apt['id'] ?>"><?= e($apt['icao']) ?> - <?= e($apt['name_ru']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Аэропорт прилета</label>
                    <select name="arr_airport_id" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <?php foreach ($allAirports as $apt): ?>
                            <option value="<?= $apt['id'] ?>"><?= e($apt['icao']) ?> - <?= e($apt['name_ru']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Тип самолёта</label>
                    <select name="aircraft_id" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <option value="">-- Не указан --</option>
                        <?php foreach ($allAircraft as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= e($a['model_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Место (Seat)</label>
                    <input type="text" name="seat_number" placeholder="например: 12A" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-mono uppercase">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Заметки о рейсе</label>
                <textarea name="notes" placeholder="Впечатления от сервиса, питание, турбулентность..." class="w-full h-20 bg-slate-900 border border-slate-700 rounded-xl p-3 text-white placeholder-slate-600"></textarea>
            </div>

            <div class="flex justify-end space-x-2 pt-2">
                <button type="button" onclick="closeAddFlightModal()" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300">Отмена</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold">Сохранить полёт</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddFlightModal() {
        document.getElementById('addFlightModal').classList.remove('hidden');
    }
    function closeAddFlightModal() {
        document.getElementById('addFlightModal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
