<?php
$pageTitle = 'Обучение пилотов, Авиационная теория и Тренажеры';
$metaDescription = 'Обучающий курс для пилотов и любителей авиации: основы аэродинамики, навигация, симулятор приборов Six-Pack, тренажер радиообмена CRAFT и код Морзе.';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <div class="va-card p-6 sm:p-8 mb-8">
        <div class="flex items-center space-x-3 mb-2">
            <div class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-purple-400">
                <i data-lucide="graduation-cap" class="w-6 h-6"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white">Авиационная Академия и Тренажеры</h1>
                <p class="text-xs text-slate-400 font-mono">Теоретические курсы PPL/CPL, симулятор приборов Six-Pack и радиообмен</p>
            </div>
        </div>

        <!-- Section Navigation Tabs -->
        <div class="flex flex-wrap gap-2 mt-6 pt-4 border-t border-slate-800 text-xs font-mono">
            <button onclick="switchTrainingTab('sixpack')" id="ttab-btn-sixpack" class="training-tab-btn px-4 py-2 rounded-xl border bg-sky-600 border-sky-500 text-white font-bold transition">
                🎛️ Симулятор Six-Pack
            </button>
            <button onclick="switchTrainingTab('lessons')" id="ttab-btn-lessons" class="training-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                📚 Уроки аэродинамики
            </button>
            <button onclick="switchTrainingTab('craft')" id="ttab-btn-craft" class="training-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                🎙️ Тренажер радиообмена (CRAFT)
            </button>
            <button onclick="switchTrainingTab('morse')" id="ttab-btn-morse" class="training-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                📻 Азбука Морзе маяков
            </button>
        </div>
    </div>

    <!-- TAB 1: SIX-PACK FLIGHT INSTRUMENTS SIMULATOR -->
    <div id="ttab-pane-sixpack" class="training-pane space-y-6">
        <div class="va-card p-6 sm:p-8">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-lg font-bold text-white font-mono uppercase">Классический комплект приборов «Basic Six»</h2>
                    <p class="text-xs text-slate-400">Интерактивный тренажер чтения приборной панели</p>
                </div>
            </div>

            <!-- Six Pack Gauges Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 font-mono text-center">

                <!-- 1. Airspeed Indicator (ASI) -->
                <div class="p-6 rounded-2xl bg-slate-950 border-2 border-slate-800 shadow-xl flex flex-col items-center">
                    <div class="text-xs text-slate-400 mb-2">Указатель скорости (ASI)</div>
                    <div class="w-36 h-36 rounded-full border-4 border-slate-700 bg-slate-900 flex flex-col items-center justify-center relative shadow-inner">
                        <div id="sim-speed-val" class="text-2xl font-black text-emerald-400">120</div>
                        <div class="text-[10px] text-slate-500">KNOTS</div>
                    </div>
                    <div class="mt-4 w-full text-xs">
                        <input type="range" id="sim-speed-input" min="40" max="220" value="120" class="w-full" oninput="updateSimInstruments()">
                    </div>
                </div>

                <!-- 2. Attitude Indicator (AI / Авиагоризонт) -->
                <div class="p-6 rounded-2xl bg-slate-950 border-2 border-slate-800 shadow-xl flex flex-col items-center">
                    <div class="text-xs text-slate-400 mb-2">Авиагоризонт (Attitude)</div>
                    <div class="w-36 h-36 rounded-full border-4 border-slate-700 overflow-hidden relative shadow-inner">
                        <div id="sim-horizon-sky" class="w-full h-1/2 bg-sky-600 transition-transform duration-75"></div>
                        <div id="sim-horizon-ground" class="w-full h-1/2 bg-amber-800 transition-transform duration-75"></div>
                        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                            <div class="w-12 h-1 bg-amber-400 rounded"></div>
                        </div>
                    </div>
                    <div class="mt-4 w-full grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <label class="text-[10px] text-slate-500">Крен (°)</label>
                            <input type="range" id="sim-roll-input" min="-45" max="45" value="0" class="w-full" oninput="updateSimInstruments()">
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-500">Тангаж (°)</label>
                            <input type="range" id="sim-pitch-input" min="-20" max="20" value="0" class="w-full" oninput="updateSimInstruments()">
                        </div>
                    </div>
                </div>

                <!-- 3. Altimeter (ALT / Высотомер) -->
                <div class="p-6 rounded-2xl bg-slate-950 border-2 border-slate-800 shadow-xl flex flex-col items-center">
                    <div class="text-xs text-slate-400 mb-2">Высотомер (Altimeter)</div>
                    <div class="w-36 h-36 rounded-full border-4 border-slate-700 bg-slate-900 flex flex-col items-center justify-center relative shadow-inner">
                        <div id="sim-alt-val" class="text-2xl font-black text-sky-400">4,500</div>
                        <div class="text-[10px] text-slate-500">FEET</div>
                        <div id="sim-qnh-badge" class="text-[10px] text-amber-400 font-bold mt-1">QNH 1013</div>
                    </div>
                    <div class="mt-4 w-full text-xs">
                        <input type="range" id="sim-alt-input" min="0" max="15000" step="100" value="4500" class="w-full" oninput="updateSimInstruments()">
                    </div>
                </div>

                <!-- 4. Turn Coordinator (Указатель поворота) -->
                <div class="p-6 rounded-2xl bg-slate-950 border-2 border-slate-800 shadow-xl flex flex-col items-center">
                    <div class="text-xs text-slate-400 mb-2">Координатор поворота</div>
                    <div class="w-36 h-36 rounded-full border-4 border-slate-700 bg-slate-900 flex flex-col items-center justify-center relative">
                        <div class="text-sm font-bold text-slate-200">RATE 1 TURN</div>
                        <div class="w-20 h-3 bg-slate-800 rounded-full border border-slate-700 mt-2 flex items-center justify-center relative">
                            <div id="sim-slip-ball" class="w-2.5 h-2.5 rounded-full bg-slate-100 shadow transition-transform"></div>
                        </div>
                    </div>
                    <div class="text-[10px] text-slate-500 mt-4">Стандартный разворот: 3° в секунду</div>
                </div>

                <!-- 5. Heading Indicator (Гирополукомпас) -->
                <div class="p-6 rounded-2xl bg-slate-950 border-2 border-slate-800 shadow-xl flex flex-col items-center">
                    <div class="text-xs text-slate-400 mb-2">Гирокомпас (Heading)</div>
                    <div class="w-36 h-36 rounded-full border-4 border-slate-700 bg-slate-900 flex flex-col items-center justify-center relative">
                        <div id="sim-hdg-val" class="text-2xl font-black text-amber-400">240°</div>
                        <div class="text-[10px] text-slate-500">WSW</div>
                    </div>
                    <div class="mt-4 w-full text-xs">
                        <input type="range" id="sim-hdg-input" min="0" max="359" value="240" class="w-full" oninput="updateSimInstruments()">
                    </div>
                </div>

                <!-- 6. Vertical Speed (Вариометр VSI) -->
                <div class="p-6 rounded-2xl bg-slate-950 border-2 border-slate-800 shadow-xl flex flex-col items-center">
                    <div class="text-xs text-slate-400 mb-2">Вариометр (VSI)</div>
                    <div class="w-36 h-36 rounded-full border-4 border-slate-700 bg-slate-900 flex flex-col items-center justify-center relative">
                        <div id="sim-vsi-val" class="text-2xl font-black text-purple-400">+500</div>
                        <div class="text-[10px] text-slate-500">FT / MIN</div>
                    </div>
                    <div class="mt-4 w-full text-xs">
                        <input type="range" id="sim-vsi-input" min="-2000" max="2000" step="100" value="500" class="w-full" oninput="updateSimInstruments()">
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- TAB 2: LESSONS -->
    <div id="ttab-pane-lessons" class="training-pane space-y-6 hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Lesson 1 -->
            <div class="va-card p-6 space-y-3">
                <div class="w-8 h-8 rounded-lg bg-sky-500/10 text-sky-400 flex items-center justify-center font-bold font-mono">01</div>
                <h3 class="text-base font-bold text-white">Физика полета и подъемная сила</h3>
                <p class="text-xs text-slate-300 leading-relaxed">
                    Подъемная сила крыла $Y = C_y \cdot \frac{\rho V^2}{2} \cdot S$. Принцип Бернулли и закон сохранения импульса. Почему угол атаки определяет подъемную силу и как избежать срыва потока (Stall).
                </p>
            </div>

            <!-- Lesson 2 -->
            <div class="va-card p-6 space-y-3">
                <div class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center font-bold font-mono">02</div>
                <h3 class="text-base font-bold text-white">Органы управления и устойчивость</h3>
                <p class="text-xs text-slate-300 leading-relaxed">
                    Три оси управления самолетом: продольная (крен — элероны), поперечная (тангаж — руль высоты), вертикальная (рыскание — руль направления). Роль триммеров в снятии нагрузок со штурвала.
                </p>
            </div>

            <!-- Lesson 3 -->
            <div class="va-card p-6 space-y-3">
                <div class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center font-bold font-mono">03</div>
                <h3 class="text-base font-bold text-white">Основы радионавигации</h3>
                <p class="text-xs text-slate-300 leading-relaxed">
                    Полеты по радиомаякам VOR и NDB. Понятие курсового угла радиостанции (КУР), радиалов TO/FROM. Заход на посадку по курсоглиссадной системе ILS категорий CAT I-III.
                </p>
            </div>

            <!-- Lesson 4 -->
            <div class="va-card p-6 space-y-3">
                <div class="w-8 h-8 rounded-lg bg-purple-500/10 text-purple-400 flex items-center justify-center font-bold font-mono">04</div>
                <h3 class="text-base font-bold text-white">Авиационная метеорология</h3>
                <p class="text-xs text-slate-300 leading-relaxed">
                    Опасные метеоявления: сдвиг ветра (Low Level Windshear), микропорывы (Microburst), грозовые очаги CB, обледенение карбюратора и планера. Правила обхода гроз по метеорадару.
                </p>
            </div>
        </div>
    </div>

    <!-- TAB 3: CRAFT PHRASEOLOGY -->
    <div id="ttab-pane-craft" class="training-pane space-y-6 hidden">
        <div class="va-card p-6 sm:p-8 space-y-4">
            <h2 class="text-base font-bold text-white font-mono uppercase">Стандарт IFR-разрешений по акрониму CRAFT</h2>
            <p class="text-xs text-slate-400 leading-relaxed">
                Международный стандарт выдачи диспетчерского разрешения на вылет по приборам:
            </p>

            <div class="space-y-3 font-mono text-xs">
                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-start space-x-3">
                    <span class="px-2 py-1 rounded bg-sky-950 text-sky-400 font-bold">C</span>
                    <div>
                        <strong class="text-slate-100">Clearance Limit:</strong>
                        <div class="text-slate-400">Пункт назначения (например: «Cleared to Saint Petersburg Pulkovo...»)</div>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-start space-x-3">
                    <span class="px-2 py-1 rounded bg-sky-950 text-sky-400 font-bold">R</span>
                    <div>
                        <strong class="text-slate-100">Route:</strong>
                        <div class="text-slate-400">Схема вылета SID и маршрут («...via KN Departure, then as filed...»)</div>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-start space-x-3">
                    <span class="px-2 py-1 rounded bg-sky-950 text-sky-400 font-bold">A</span>
                    <div>
                        <strong class="text-slate-100">Altitude:</strong>
                        <div class="text-slate-400">Начальный эшелон набора («...climb to FL100, expect FL340 10 minutes after departure...»)</div>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-start space-x-3">
                    <span class="px-2 py-1 rounded bg-sky-950 text-sky-400 font-bold">F</span>
                    <div>
                        <strong class="text-slate-100">Frequency:</strong>
                        <div class="text-slate-400">Частота связи после взлета («...contact Radar on 127.300...»)</div>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-start space-x-3">
                    <span class="px-2 py-1 rounded bg-sky-950 text-sky-400 font-bold">T</span>
                    <div>
                        <strong class="text-slate-100">Transponder:</strong>
                        <div class="text-slate-400">Код ответчика Squawk («...Squawk 4215...»)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 4: MORSE CODE TRAINER -->
    <div id="ttab-pane-morse" class="training-pane space-y-6 hidden">
        <div class="va-card p-6 sm:p-8 space-y-4">
            <h2 class="text-base font-bold text-white font-mono uppercase">Аудиотренажер позывных маяков NAVAID (Морзе)</h2>
            <p class="text-xs text-slate-400">
                Каждый радиомаяк VOR и NDB непрерывно транслирует свой 2-3 буквенный идентификатор кодом Морзе на несущей частоте.
            </p>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 font-mono text-xs">
                <button onclick="playMorseCode('MR', '-- ·-·')" class="p-4 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition">
                    <div class="font-bold text-sky-400 text-sm">MR (Шереметьево VOR)</div>
                    <div class="text-[11px] text-slate-400 mt-1">114.10 MHz</div>
                    <div class="text-[10px] text-slate-500 mt-1">-- ·-·</div>
                </button>

                <button onclick="playMorseCode('DMD', '-·· -- -··')" class="p-4 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition">
                    <div class="font-bold text-sky-400 text-sm">DMD (Домодедово VOR)</div>
                    <div class="text-[11px] text-slate-400 mt-1">113.30 MHz</div>
                    <div class="text-[10px] text-slate-500 mt-1">-·· -- -··</div>
                </button>

                <button onclick="playMorseCode('SPB', '··· ·--· -···')" class="p-4 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition">
                    <div class="font-bold text-sky-400 text-sm">SPB (Пулково VOR)</div>
                    <div class="text-[11px] text-slate-400 mt-1">113.40 MHz</div>
                    <div class="text-[10px] text-slate-500 mt-1">··· ·--· -···</div>
                </button>

                <button onclick="playMorseCode('LON', '·-·· --- -·')" class="p-4 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 text-left transition">
                    <div class="font-bold text-sky-400 text-sm">LON (Лондон VOR)</div>
                    <div class="text-[11px] text-slate-400 mt-1">113.60 MHz</div>
                    <div class="text-[10px] text-slate-500 mt-1">·-·· --- -·</div>
                </button>
            </div>
        </div>
    </div>

</div>

<script>
    function switchTrainingTab(name) {
        document.querySelectorAll('.training-pane').forEach(p => p.classList.add('hidden'));
        document.querySelectorAll('.training-tab-btn').forEach(b => {
            b.classList.remove('bg-sky-600', 'border-sky-500', 'text-white', 'font-bold');
            b.classList.add('bg-slate-950', 'border-slate-800', 'text-slate-400');
        });

        const pane = document.getElementById('ttab-pane-' + name);
        const btn = document.getElementById('ttab-btn-' + name);
        if (pane) pane.classList.remove('hidden');
        if (btn) {
            btn.classList.remove('bg-slate-950', 'border-slate-800', 'text-slate-400');
            btn.classList.add('bg-sky-600', 'border-sky-500', 'text-white', 'font-bold');
        }
    }

    function updateSimInstruments() {
        const spd = document.getElementById('sim-speed-input').value;
        const roll = document.getElementById('sim-roll-input').value;
        const pitch = document.getElementById('sim-pitch-input').value;
        const alt = document.getElementById('sim-alt-input').value;
        const hdg = document.getElementById('sim-hdg-input').value;
        const vsi = document.getElementById('sim-vsi-input').value;

        document.getElementById('sim-speed-val').innerText = spd;
        document.getElementById('sim-alt-val').innerText = parseInt(alt).toLocaleString();
        document.getElementById('sim-hdg-val').innerText = hdg + '°';
        document.getElementById('sim-vsi-val').innerText = (vsi > 0 ? '+' : '') + vsi;

        const sky = document.getElementById('sim-horizon-sky');
        const gnd = document.getElementById('sim-horizon-ground');
        if (sky && gnd) {
            sky.style.transform = `rotate(${roll}deg) translateY(${pitch}px)`;
            gnd.style.transform = `rotate(${roll}deg) translateY(${pitch}px)`;
        }
    }

    function playMorseCode(ident, pattern) {
        const actx = new (window.AudioContext || window.webkitAudioContext)();
        let time = actx.currentTime + 0.1;
        const dot = 0.08;
        const dash = 0.24;

        for (let char of pattern) {
            if (char === '·') {
                playBeep(actx, time, dot);
                time += dot + 0.08;
            } else if (char === '-') {
                playBeep(actx, time, dash);
                time += dash + 0.08;
            } else if (char === ' ') {
                time += 0.2;
            }
        }
    }

    function playBeep(actx, startTime, duration) {
        const osc = actx.createOscillator();
        const gain = actx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(1020, startTime); // Standard 1020 Hz NAVAID tone
        gain.gain.setValueAtTime(0.2, startTime);
        gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
        osc.connect(gain);
        gain.connect(actx.destination);
        osc.start(startTime);
        osc.stop(startTime + duration);
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
