<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Генератор авиационных посадочных талонов (Boarding Pass)';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="mb-8">
        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-purple-500/10 border border-purple-500/30 text-purple-400 text-xs font-mono mb-3">
            <i data-lucide="ticket" class="w-3.5 h-3.5"></i>
            <span>BOARDING PASS ENGINE</span>
        </div>
        <h1 class="text-3xl font-extrabold text-white">Генератор памятных посадочных талонов</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Создайте стильный сувенирный посадочный талон в ретро или современном дизайне</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <!-- Left: Form Controls -->
        <div class="lg:col-span-5 glass-card rounded-3xl p-6 border border-white/5 space-y-4 font-mono text-xs">
            <h3 class="text-sm font-bold text-white border-b border-white/5 pb-2">Параметры талона</h3>

            <div>
                <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Имя пассажира</label>
                <input type="text" id="passName" value="<?= e(Auth::user()['full_name'] ?? 'VLADIMIR / PILOT') ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase" oninput="updatePass()">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Номер рейса</label>
                    <input type="text" id="passFlight" value="VA 102" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase" oninput="updatePass()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Дата</label>
                    <input type="date" id="passDate" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="updatePass()">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Вылет (ICAO / Город)</label>
                    <input type="text" id="passDep" value="SVO (MOSCOW)" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase" oninput="updatePass()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Прилет (ICAO / Город)</label>
                    <input type="text" id="passArr" value="VVO (VLADIVOSTOK)" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase" oninput="updatePass()">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Гейт</label>
                    <input type="text" id="passGate" value="B14" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase" oninput="updatePass()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Место</label>
                    <input type="text" id="passSeat" value="01A" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase" oninput="updatePass()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Класс</label>
                    <select id="passClass" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white uppercase" onchange="updatePass()">
                        <option value="FIRST CLASS">FIRST</option>
                        <option value="BUSINESS">BUSINESS</option>
                        <option value="ECONOMY">ECONOMY</option>
                    </select>
                </div>
            </div>

            <div class="pt-2">
                <button onclick="window.print()" class="w-full py-3 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-bold shadow-lg shadow-purple-600/30 transition flex items-center justify-center space-x-2">
                    <i data-lucide="printer" class="w-4 h-4"></i>
                    <span>Распечатать / Сохранить в PDF</span>
                </button>
            </div>
        </div>

        <!-- Right: Live Boarding Pass Preview -->
        <div class="lg:col-span-7 flex justify-center">
            
            <div id="boardingPassCard" class="w-full max-w-xl bg-gradient-to-r from-slate-900 via-slate-800 to-indigo-950 rounded-3xl border border-sky-500/40 shadow-2xl p-6 text-white font-mono relative overflow-hidden">
                
                <!-- Background Airline Watermark -->
                <div class="absolute -right-10 -bottom-10 opacity-5 pointer-events-none">
                    <i data-lucide="plane" class="w-72 h-72"></i>
                </div>

                <!-- Top Pass Header -->
                <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-5">
                    <div class="flex items-center space-x-2.5">
                        <div class="w-8 h-8 rounded-lg bg-sky-600 flex items-center justify-center text-white font-bold">✈</div>
                        <div>
                            <div class="font-bold text-base tracking-wider">VLADAERO AIRWAYS</div>
                            <div class="text-[9px] text-sky-400 uppercase tracking-widest" id="previewClass">FIRST CLASS</div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-[10px] text-slate-400 uppercase">BOARDING PASS</div>
                        <div class="text-sm font-bold text-amber-400" id="previewFlight">VA 102</div>
                    </div>
                </div>

                <!-- Passenger & Route Row -->
                <div class="grid grid-cols-2 gap-4 mb-5">
                    <div>
                        <div class="text-[9px] text-slate-400 uppercase">PASSENGER NAME</div>
                        <div class="font-bold text-sm text-white truncate" id="previewName">VLADIMIR / PILOT</div>
                    </div>
                    <div class="text-right">
                        <div class="text-[9px] text-slate-400 uppercase">DATE / FLIGHT</div>
                        <div class="font-bold text-xs text-white" id="previewDate"><?= date('d M Y') ?></div>
                    </div>
                </div>

                <!-- Big Route Code -->
                <div class="p-4 rounded-2xl bg-black/40 border border-white/5 flex items-center justify-between mb-5">
                    <div>
                        <div class="text-2xl font-extrabold text-sky-400" id="previewDep">SVO</div>
                        <div class="text-[9px] text-slate-400">ORIGIN</div>
                    </div>
                    <div class="flex flex-col items-center">
                        <i data-lucide="plane" class="w-5 h-5 text-amber-400 transform rotate-90"></i>
                        <span class="text-[8px] text-slate-500">NON-STOP</span>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-extrabold text-emerald-400" id="previewArr">VVO</div>
                        <div class="text-[9px] text-slate-400">DESTINATION</div>
                    </div>
                </div>

                <!-- Boarding Details -->
                <div class="grid grid-cols-3 gap-3 border-t border-white/10 pt-4 mb-4 text-center">
                    <div>
                        <div class="text-[9px] text-slate-400 uppercase">GATE</div>
                        <div class="text-lg font-extrabold text-amber-400" id="previewGate">B14</div>
                    </div>
                    <div>
                        <div class="text-[9px] text-slate-400 uppercase">BOARDING TIME</div>
                        <div class="text-lg font-extrabold text-white">40 MIN BEFORE</div>
                    </div>
                    <div>
                        <div class="text-[9px] text-slate-400 uppercase">SEAT</div>
                        <div class="text-lg font-extrabold text-sky-400" id="previewSeat">01A</div>
                    </div>
                </div>

                <!-- Barcode Simulation -->
                <div class="p-3 rounded-xl bg-white text-black text-center flex items-center justify-center space-x-1 tracking-widest font-mono text-xs overflow-hidden select-none">
                    ||| | | || ||| || |||| | ||| | || |||| ||| | | || ||| || |||| | ||| | || ||||
                </div>

            </div>

        </div>

    </div>

</div>

<script>
    function updatePass() {
        document.getElementById('previewName').innerText = document.getElementById('passName').value.toUpperCase();
        document.getElementById('previewFlight').innerText = document.getElementById('passFlight').value.toUpperCase();
        document.getElementById('previewDate').innerText = document.getElementById('passDate').value;
        document.getElementById('previewDep').innerText = document.getElementById('passDep').value.toUpperCase();
        document.getElementById('previewArr').innerText = document.getElementById('passArr').value.toUpperCase();
        document.getElementById('previewGate').innerText = document.getElementById('passGate').value.toUpperCase();
        document.getElementById('previewSeat').innerText = document.getElementById('passSeat').value.toUpperCase();
        document.getElementById('previewClass').innerText = document.getElementById('passClass').value;
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
