<?php
$pageTitle = 'Авиационные Калькуляторы E6B и Летные Расчеты';
$metaDescription = 'Комплекс интерактивных авиационных калькуляторов: расчет бокового ветра по полосе, плотностная высота, истинная скорость TAS, число Маха, снижение TOD и топливо.';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/e6b.php';

$defaultQnh = (float)($_GET['qnh'] ?? 1013.25);
$defaultTemp = (float)($_GET['temp'] ?? 15);
$defaultWindDir = (float)($_GET['wind_dir'] ?? 240);
$defaultWindSpd = (float)($_GET['wind_spd'] ?? 15);
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <div class="va-card p-6 sm:p-8 mb-8">
        <div class="flex items-center space-x-3 mb-2">
            <div class="w-10 h-10 rounded-xl bg-sky-500/10 border border-sky-500/30 flex items-center justify-center text-sky-400">
                <i data-lucide="calculator" class="w-6 h-6"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white">Комплекс Летных Калькуляторов E6B</h1>
                <p class="text-xs text-slate-400 font-mono">Навигационные, аэродинамические и метеорологические расчеты полета</p>
            </div>
        </div>

        <!-- Calculator Tabs Navigation -->
        <div class="flex flex-wrap gap-2 mt-6 pt-4 border-t border-slate-800 text-xs font-mono">
            <button onclick="switchTab('crosswind')" id="tab-btn-crosswind" class="calc-tab-btn px-4 py-2 rounded-xl border bg-sky-600 border-sky-500 text-white font-bold transition">
                💨 Боковой ветер
            </button>
            <button onclick="switchTab('density_altitude')" id="tab-btn-density_altitude" class="calc-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                ⛰️ Плотностная высота (DA)
            </button>
            <button onclick="switchTab('tas')" id="tab-btn-tas" class="calc-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                ⚡ Скорость TAS и Число Маха
            </button>
            <button onclick="switchTab('descent')" id="tab-btn-descent" class="calc-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                📉 Расчет снижения (TOD / RoD)
            </button>
            <button onclick="switchTab('glide')" id="tab-btn-glide" class="calc-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                🦅 Планирование без тяги
            </button>
            <button onclick="switchTab('fuel')" id="tab-btn-fuel" class="calc-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                ⛽ Заправка и вес топлива
            </button>
            <button onclick="switchTab('units')" id="tab-btn-units" class="calc-tab-btn px-4 py-2 rounded-xl border bg-slate-950 border-slate-800 text-slate-400 hover:text-white transition">
                🔄 Конвертер единиц
            </button>
        </div>
    </div>

    <!-- CALCULATOR 1: CROSSWIND & HEADWIND -->
    <div id="calc-pane-crosswind" class="calc-pane space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="va-card p-6 space-y-4">
                <h2 class="text-sm font-bold text-white font-mono uppercase tracking-wider flex items-center space-x-2">
                    <i data-lucide="wind" class="w-4 h-4 text-sky-400"></i>
                    <span>Параметры полосы и ветра</span>
                </h2>

                <div class="space-y-3 text-xs font-mono">
                    <div>
                        <label class="block text-slate-400 mb-1">Курс ВПP (Runway Magnetic Heading, 0-360°):</label>
                        <input type="number" id="xw-rwy" value="240" min="0" max="360" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcCrosswind()">
                    </div>

                    <div>
                        <label class="block text-slate-400 mb-1">Направление ветра (Wind Direction, 0-360°):</label>
                        <input type="number" id="xw-wind-dir" value="<?= $defaultWindDir ?>" min="0" max="360" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcCrosswind()">
                    </div>

                    <div>
                        <label class="block text-slate-400 mb-1">Скорость ветра (Wind Speed):</label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" id="xw-wind-spd" value="<?= $defaultWindSpd ?>" min="0" max="100" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcCrosswind()">
                            <select id="xw-wind-unit" onchange="recalcCrosswind()" class="bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-200">
                                <option value="kt">Узлы (Knots / KT)</option>
                                <option value="ms">Метры в секунду (м/с)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Result Box -->
            <div class="va-card p-6 flex flex-col justify-between">
                <div>
                    <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider mb-4">Результат составляющих ветра</h3>
                    
                    <div class="grid grid-cols-2 gap-4 font-mono text-center mb-6">
                        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                            <div class="text-[11px] text-slate-400">Боковой ветер (Crosswind)</div>
                            <div id="res-crosswind-kt" class="text-2xl font-black text-amber-400 mt-1">0 KT</div>
                            <div id="res-crosswind-ms" class="text-xs text-slate-400 mt-0.5">0 м/с</div>
                            <div id="res-cross-side" class="text-[10px] text-slate-500 mt-1">слева</div>
                        </div>

                        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                            <div id="res-head-title" class="text-[11px] text-slate-400">Встречный ветер</div>
                            <div id="res-headwind-kt" class="text-2xl font-black text-emerald-400 mt-1">0 KT</div>
                            <div id="res-headwind-ms" class="text-xs text-slate-400 mt-0.5">0 м/с</div>
                            <div id="res-angle-diff" class="text-[10px] text-slate-500 mt-1">Угол: 0°</div>
                        </div>
                    </div>

                    <div id="res-xw-alert" class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-mono text-center">
                        Условия в пределах нормы для большинства типов ВС.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CALCULATOR 2: DENSITY ALTITUDE -->
    <div id="calc-pane-density_altitude" class="calc-pane space-y-6 hidden">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="va-card p-6 space-y-4">
                <h2 class="text-sm font-bold text-white font-mono uppercase tracking-wider flex items-center space-x-2">
                    <i data-lucide="mountain" class="w-4 h-4 text-emerald-400"></i>
                    <span>Входные метеопараметры</span>
                </h2>

                <div class="space-y-3 text-xs font-mono">
                    <div>
                        <label class="block text-slate-400 mb-1">Высота аэродрома над уровнем моря (Elevation, ft):</label>
                        <input type="number" id="da-elev" value="623" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcDensityAltitude()">
                    </div>

                    <div>
                        <label class="block text-slate-400 mb-1">Барометрическое давление QNH (hPa):</label>
                        <input type="number" id="da-qnh" value="<?= $defaultQnh ?>" step="0.1" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcDensityAltitude()">
                    </div>

                    <div>
                        <label class="block text-slate-400 mb-1">Фактическая температура воздуха OAT (°C):</label>
                        <input type="number" id="da-temp" value="<?= $defaultTemp ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcDensityAltitude()">
                    </div>
                </div>
            </div>

            <!-- Result Box -->
            <div class="va-card p-6 font-mono space-y-4">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Результат высот по плотности</h3>

                <div class="grid grid-cols-2 gap-4 text-center">
                    <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Барометрическая высота (PA)</div>
                        <div id="res-pa-ft" class="text-xl font-bold text-sky-400 mt-1">623 ft</div>
                        <div id="res-pa-m" class="text-xs text-slate-500">190 м</div>
                    </div>

                    <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Плотностная высота (DA)</div>
                        <div id="res-da-ft" class="text-xl font-bold text-amber-400 mt-1">750 ft</div>
                        <div id="res-da-m" class="text-xs text-slate-500">228 м</div>
                    </div>
                </div>

                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 text-xs text-slate-400 space-y-1">
                    <div class="flex justify-between">
                        <span>Стандартная температура ISA:</span>
                        <span id="res-isa-temp" class="text-slate-200 font-bold">13.8 °C</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Отклонение от ISA (ISA Dev):</span>
                        <span id="res-isa-dev" class="text-slate-200 font-bold">+1.2 °C</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CALCULATOR 3: TAS & MACH -->
    <div id="calc-pane-tas" class="calc-pane space-y-6 hidden">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="va-card p-6 space-y-4">
                <h2 class="text-sm font-bold text-white font-mono uppercase tracking-wider flex items-center space-x-2">
                    <i data-lucide="zap" class="w-4 h-4 text-sky-400"></i>
                    <span>Приборная скорость и эшелон</span>
                </h2>

                <div class="space-y-3 text-xs font-mono">
                    <div>
                        <label class="block text-slate-400 mb-1">Приборная скорость (IAS, узлы / KT):</label>
                        <input type="number" id="tas-ias" value="280" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcTas()">
                    </div>

                    <div>
                        <label class="block text-slate-400 mb-1">Высота полета (Altitude, ft):</label>
                        <input type="number" id="tas-alt" value="33000" step="1000" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcTas()">
                    </div>

                    <div>
                        <label class="block text-slate-400 mb-1">Температура на высоте OAT (°C):</label>
                        <input type="number" id="tas-temp" value="-51" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold focus:border-sky-500" oninput="recalcTas()">
                    </div>
                </div>
            </div>

            <!-- Result Box -->
            <div class="va-card p-6 font-mono space-y-4">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Истинная скорость и Мах</h3>

                <div class="grid grid-cols-2 gap-4 text-center">
                    <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Истинная скорость (TAS)</div>
                        <div id="res-tas-kt" class="text-2xl font-black text-sky-400 mt-1">456 KT</div>
                        <div id="res-tas-kmh" class="text-xs text-slate-400 mt-0.5">845 км/ч</div>
                    </div>

                    <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Число Маха (Mach)</div>
                        <div id="res-mach" class="text-2xl font-black text-emerald-400 mt-1">M 0.785</div>
                        <div id="res-sound-speed" class="text-xs text-slate-400 mt-0.5">a = 580 KT</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CALCULATOR 4: TOP OF DESCENT (TOD) -->
    <div id="calc-pane-descent" class="calc-pane space-y-6 hidden">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="va-card p-6 space-y-4">
                <h2 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Параметры профиля снижения (3° Глиссада)</h2>

                <div class="space-y-3 text-xs font-mono">
                    <div>
                        <label class="block text-slate-400 mb-1">Крейсерский эшелон (Cruise Alt, ft):</label>
                        <input type="number" id="tod-cruise" value="35000" step="1000" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold" oninput="recalcDescent()">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1">Целевая высота / Аэродром (Target Alt, ft):</label>
                        <input type="number" id="tod-target" value="3000" step="500" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold" oninput="recalcDescent()">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1">Путевая скорость на снижении (Ground Speed, узлы):</label>
                        <input type="number" id="tod-gs" value="420" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold" oninput="recalcDescent()">
                    </div>
                </div>
            </div>

            <!-- Result Box -->
            <div class="va-card p-6 font-mono space-y-4">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Точка начала снижения (Top of Descent)</h3>
                <div class="grid grid-cols-2 gap-4 text-center">
                    <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Дистанция до точки TOD</div>
                        <div id="res-tod-nm" class="text-2xl font-black text-sky-400 mt-1">96 NM</div>
                        <div id="res-tod-km" class="text-xs text-slate-400 mt-0.5">178 км</div>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Вертикальная скорость (RoD)</div>
                        <div id="res-rod-fpm" class="text-2xl font-black text-emerald-400 mt-1">-2226 FPM</div>
                        <div id="res-rod-ms" class="text-xs text-slate-400 mt-0.5">-11.3 м/с</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CALCULATOR 5: GLIDE RATIO -->
    <div id="calc-pane-glide" class="calc-pane space-y-6 hidden">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="va-card p-6 space-y-4">
                <h2 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Отказ двигателя / Планирование</h2>
                <div class="space-y-3 text-xs font-mono">
                    <div>
                        <label class="block text-slate-400 mb-1">Высота над рельефом AGL (ft):</label>
                        <input type="number" id="glide-alt" value="10000" step="500" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold" oninput="recalcGlide()">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1">Аэродинамическое качество планера (L/D):</label>
                        <select id="glide-ratio" onchange="recalcGlide()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold">
                            <option value="17.0">17:1 (Airbus A320 / Boeing 737 на чистом крыле)</option>
                            <option value="15.0">15:1 (Boeing 777 / 767 Gimli Glider)</option>
                            <option value="9.0">9:1 (Cessna 172 Skyhawk)</option>
                            <option value="11.0">11:1 (Diamond DA40 / Piper PA-28)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Result Box -->
            <div class="va-card p-6 font-mono space-y-4">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Радиус конуса планирования (Glide Cone)</h3>
                <div class="p-6 rounded-xl bg-slate-950 border border-slate-800 text-center">
                    <div class="text-xs text-slate-400">Максимальная дальность без тяги моторов</div>
                    <div id="res-glide-nm" class="text-3xl font-black text-emerald-400 mt-2">27.9 NM</div>
                    <div id="res-glide-km" class="text-sm text-slate-400 mt-1">51.8 километров</div>
                </div>
            </div>
        </div>
    </div>

    <!-- CALCULATOR 6: FUEL UPLIFT -->
    <div id="calc-pane-fuel" class="calc-pane space-y-6 hidden">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="va-card p-6 space-y-4">
                <h2 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Параметры топлива</h2>
                <div class="space-y-3 text-xs font-mono">
                    <div>
                        <label class="block text-slate-400 mb-1">Сорт авиатоплива:</label>
                        <select id="fuel-type" onchange="recalcFuel()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold">
                            <option value="jet_a1">Jet A-1 (Керосин международный, ρ ≈ 0.804 кг/л)</option>
                            <option value="ts1">ТС-1 / РТ (Керосин авиационный ГОСТ, ρ ≈ 0.780 кг/л)</option>
                            <option value="avgas">Avgas 100LL (Бензин поршневой, ρ ≈ 0.720 кг/л)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1">Количество:</label>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="number" id="fuel-amount" value="5000" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold" oninput="recalcFuel()">
                            <select id="fuel-unit" onchange="recalcFuel()" class="bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100">
                                <option value="liters">Литры (L)</option>
                                <option value="kg">Килограммы (KG)</option>
                                <option value="lbs">Фунты (LBS)</option>
                                <option value="us_gallons">Галлоны США (GAL)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Result Box -->
            <div class="va-card p-6 font-mono space-y-4">
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Эквивалентные значения заправки</h3>
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="p-3 bg-slate-950 rounded-xl border border-slate-800">
                        <div class="text-slate-500">Масса в КГ:</div>
                        <div id="res-fuel-kg" class="text-base font-bold text-sky-400 mt-1">4 020 кг</div>
                    </div>
                    <div class="p-3 bg-slate-950 rounded-xl border border-slate-800">
                        <div class="text-slate-500">Масса в Фунтах (LBS):</div>
                        <div id="res-fuel-lbs" class="text-base font-bold text-amber-400 mt-1">8 863 lbs</div>
                    </div>
                    <div class="p-3 bg-slate-950 rounded-xl border border-slate-800">
                        <div class="text-slate-500">Объем в Литрах:</div>
                        <div id="res-fuel-liters" class="text-base font-bold text-emerald-400 mt-1">5 000 л</div>
                    </div>
                    <div class="p-3 bg-slate-950 rounded-xl border border-slate-800">
                        <div class="text-slate-500">Объем в Галлонах (US):</div>
                        <div id="res-fuel-gal" class="text-base font-bold text-purple-400 mt-1">1 321 gal</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CALCULATOR 7: UNITS CONVERTER -->
    <div id="calc-pane-units" class="calc-pane space-y-6 hidden">
        <div class="va-card p-6 sm:p-8 space-y-6">
            <h2 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Универсальный Авиационный Конвертер</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 font-mono text-xs">
                <!-- Speed Converter -->
                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 space-y-2">
                    <div class="font-bold text-sky-400 mb-2">Скорость (Узлы / км/ч / м/с)</div>
                    <label class="block text-slate-500">Узлы (KT):</label>
                    <input type="number" id="uc-kt" value="150" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertSpeed('kt')">
                    <label class="block text-slate-500">Км/ч (km/h):</label>
                    <input type="number" id="uc-kmh" value="278" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertSpeed('kmh')">
                    <label class="block text-slate-500">М/с (m/s):</label>
                    <input type="number" id="uc-ms" value="77" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertSpeed('ms')">
                </div>

                <!-- Altitude Converter -->
                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 space-y-2">
                    <div class="font-bold text-emerald-400 mb-2">Высота (Футы / Метры / FL)</div>
                    <label class="block text-slate-500">Футы (FT):</label>
                    <input type="number" id="uc-ft" value="10000" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertAlt('ft')">
                    <label class="block text-slate-500">Метры (M):</label>
                    <input type="number" id="uc-m" value="3048" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertAlt('m')">
                    <label class="block text-slate-500">Эшелон (FL):</label>
                    <input type="number" id="uc-fl" value="100" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertAlt('fl')">
                </div>

                <!-- Pressure Converter -->
                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 space-y-2">
                    <div class="font-bold text-amber-400 mb-2">Давление (hPa / inHg / mmHg)</div>
                    <label class="block text-slate-500">Гектопаскали (hPa / mbar):</label>
                    <input type="number" id="uc-hpa" value="1013.2" step="0.1" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertPress('hpa')">
                    <label class="block text-slate-500">Дюймы рт. ст. (inHg):</label>
                    <input type="number" id="uc-inhg" value="29.92" step="0.01" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertPress('inhg')">
                    <label class="block text-slate-500">Мм рт. ст. (mmHg):</label>
                    <input type="number" id="uc-mmhg" value="760" step="0.1" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white" oninput="convertPress('mmhg')">
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Client Calculations Script -->
<script>
    function switchTab(name) {
        document.querySelectorAll('.calc-pane').forEach(p => p.classList.add('hidden'));
        document.querySelectorAll('.calc-tab-btn').forEach(b => {
            b.classList.remove('bg-sky-600', 'border-sky-500', 'text-white', 'font-bold');
            b.classList.add('bg-slate-950', 'border-slate-800', 'text-slate-400');
        });

        const activePane = document.getElementById('calc-pane-' + name);
        const activeBtn = document.getElementById('tab-btn-' + name);
        if (activePane) activePane.classList.remove('hidden');
        if (activeBtn) {
            activeBtn.classList.remove('bg-slate-950', 'border-slate-800', 'text-slate-400');
            activeBtn.classList.add('bg-sky-600', 'border-sky-500', 'text-white', 'font-bold');
        }
    }

    function recalcCrosswind() {
        const rwy = parseFloat(document.getElementById('xw-rwy').value) || 0;
        const wdir = parseFloat(document.getElementById('xw-wind-dir').value) || 0;
        let wspd = parseFloat(document.getElementById('xw-wind-spd').value) || 0;
        const unit = document.getElementById('xw-wind-unit').value;

        let wspdKt = (unit === 'ms') ? wspd * 1.94384 : wspd;

        const rad = (wdir - rwy) * Math.PI / 180;
        const xwKt = Math.abs(Math.sin(rad) * wspdKt);
        const hwKt = Math.cos(rad) * wspdKt;
        const isCrossRight = Math.sin(rad) > 0;
        const isTail = hwKt < 0;

        document.getElementById('res-crosswind-kt').innerText = xwKt.toFixed(1) + ' KT';
        document.getElementById('res-crosswind-ms').innerText = (xwKt * 0.514444).toFixed(1) + ' м/с';
        document.getElementById('res-cross-side').innerText = (xwKt > 0.5) ? (isCrossRight ? 'справа' : 'слева') : 'штиль';

        document.getElementById('res-head-title').innerText = isTail ? 'Попутный ветер (Tailwind)' : 'Встречный ветер (Headwind)';
        document.getElementById('res-headwind-kt').innerText = Math.abs(hwKt).toFixed(1) + ' KT';
        document.getElementById('res-headwind-ms').innerText = (Math.abs(hwKt) * 0.514444).toFixed(1) + ' м/с';
        document.getElementById('res-angle-diff').innerText = 'Угол: ' + Math.abs(Math.round(wdir - rwy)) + '°';

        const alertEl = document.getElementById('res-xw-alert');
        if (xwKt > 25) {
            alertEl.className = 'p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs font-mono text-center';
            alertEl.innerText = 'ВНИМАНИЕ: Опасный боковой ветер! Превышает лимит для большинства легких и региональных ВС.';
        } else if (xwKt > 15) {
            alertEl.className = 'p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-mono text-center';
            alertEl.innerText = 'Предупреждение: Умеренный боковой ветер. Требуется парирование сноса (крен на ветер / крабовый заход).';
        } else {
            alertEl.className = 'p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-mono text-center';
            alertEl.innerText = 'Условия в пределах нормы для всех типов ВС.';
        }
    }

    function recalcDensityAltitude() {
        const elev = parseFloat(document.getElementById('da-elev').value) || 0;
        const qnh = parseFloat(document.getElementById('da-qnh').value) || 1013.25;
        const temp = parseFloat(document.getElementById('da-temp').value) || 15;

        const pa = elev + (1013.25 - qnh) * 27.3;
        const isa = 15 - 1.98 * (pa / 1000);
        const da = pa + 118.8 * (temp - isa);

        document.getElementById('res-pa-ft').innerText = Math.round(pa) + ' ft';
        document.getElementById('res-pa-m').innerText = Math.round(pa * 0.3048) + ' м';
        document.getElementById('res-da-ft').innerText = Math.round(da) + ' ft';
        document.getElementById('res-da-m').innerText = Math.round(da * 0.3048) + ' м';
        document.getElementById('res-isa-temp').innerText = isa.toFixed(1) + ' °C';
        document.getElementById('res-isa-dev').innerText = ((temp - isa) >= 0 ? '+' : '') + (temp - isa).toFixed(1) + ' °C';
    }

    function recalcTas() {
        const ias = parseFloat(document.getElementById('tas-ias').value) || 0;
        const alt = parseFloat(document.getElementById('tas-alt').value) || 0;
        const temp = parseFloat(document.getElementById('tas-temp').value) || 15;

        const tempK = temp + 273.15;
        const pRatio = Math.pow(1 - (0.0000068756 * alt), 5.2559);
        const dRatio = pRatio * (288.15 / Math.max(1, tempK));

        const tas = ias / Math.sqrt(Math.max(0.01, dRatio));
        const soundKt = 38.967 * Math.sqrt(Math.max(1, tempK));
        const mach = tas / soundKt;

        document.getElementById('res-tas-kt').innerText = tas.toFixed(1) + ' KT';
        document.getElementById('res-tas-kmh').innerText = (tas * 1.852).toFixed(1) + ' км/ч';
        document.getElementById('res-mach').innerText = 'M ' + mach.toFixed(3);
        document.getElementById('res-sound-speed').innerText = 'a = ' + Math.round(soundKt) + ' KT';
    }

    function recalcDescent() {
        const cruise = parseFloat(document.getElementById('tod-cruise').value) || 35000;
        const target = parseFloat(document.getElementById('tod-target').value) || 3000;
        const gs = parseFloat(document.getElementById('tod-gs').value) || 400;

        const loseFt = Math.max(0, cruise - target);
        const todNm = (loseFt / 1000) * 3;
        const rodFpm = gs * 5.303;

        document.getElementById('res-tod-nm').innerText = todNm.toFixed(1) + ' NM';
        document.getElementById('res-tod-km').innerText = (todNm * 1.852).toFixed(1) + ' км';
        document.getElementById('res-rod-fpm').innerText = '-' + Math.round(rodFpm) + ' FPM';
        document.getElementById('res-rod-ms').innerText = '-' + (rodFpm * 0.00508).toFixed(1) + ' м/с';
    }

    function recalcGlide() {
        const alt = parseFloat(document.getElementById('glide-alt').value) || 0;
        const ratio = parseFloat(document.getElementById('glide-ratio').value) || 15;

        const rangeNm = (alt / 6076.12) * ratio;
        document.getElementById('res-glide-nm').innerText = rangeNm.toFixed(1) + ' NM';
        document.getElementById('res-glide-km').innerText = (rangeNm * 1.852).toFixed(1) + ' км';
    }

    function recalcFuel() {
        const amt = parseFloat(document.getElementById('fuel-amount').value) || 0;
        const type = document.getElementById('fuel-type').value;
        const unit = document.getElementById('fuel-unit').value;

        const densities = { jet_a1: 0.804, ts1: 0.780, avgas: 0.720 };
        const rho = densities[type] || 0.804;

        let liters = 0;
        if (unit === 'kg') liters = amt / rho;
        else if (unit === 'lbs') liters = (amt * 0.453592) / rho;
        else if (unit === 'us_gallons') liters = amt * 3.78541;
        else liters = amt;

        const kg = liters * rho;
        const lbs = kg * 2.20462;
        const gal = liters / 3.78541;

        document.getElementById('res-fuel-kg').innerText = Math.round(kg).toLocaleString() + ' кг';
        document.getElementById('res-fuel-lbs').innerText = Math.round(lbs).toLocaleString() + ' lbs';
        document.getElementById('res-fuel-liters').innerText = Math.round(liters).toLocaleString() + ' л';
        document.getElementById('res-fuel-gal').innerText = Math.round(gal).toLocaleString() + ' gal';
    }

    function convertSpeed(from) {
        if (from === 'kt') {
            const kt = parseFloat(document.getElementById('uc-kt').value) || 0;
            document.getElementById('uc-kmh').value = (kt * 1.852).toFixed(1);
            document.getElementById('uc-ms').value = (kt * 0.514444).toFixed(1);
        } else if (from === 'kmh') {
            const kmh = parseFloat(document.getElementById('uc-kmh').value) || 0;
            document.getElementById('uc-kt').value = (kmh / 1.852).toFixed(1);
            document.getElementById('uc-ms').value = (kmh / 3.6).toFixed(1);
        } else if (from === 'ms') {
            const ms = parseFloat(document.getElementById('uc-ms').value) || 0;
            document.getElementById('uc-kt').value = (ms * 1.94384).toFixed(1);
            document.getElementById('uc-kmh').value = (ms * 3.6).toFixed(1);
        }
    }

    function convertAlt(from) {
        if (from === 'ft') {
            const ft = parseFloat(document.getElementById('uc-ft').value) || 0;
            document.getElementById('uc-m').value = (ft * 0.3048).toFixed(1);
            document.getElementById('uc-fl').value = Math.round(ft / 100);
        } else if (from === 'm') {
            const m = parseFloat(document.getElementById('uc-m').value) || 0;
            document.getElementById('uc-ft').value = (m / 0.3048).toFixed(1);
            document.getElementById('uc-fl').value = Math.round((m / 0.3048) / 100);
        } else if (from === 'fl') {
            const fl = parseFloat(document.getElementById('uc-fl').value) || 0;
            document.getElementById('uc-ft').value = fl * 100;
            document.getElementById('uc-m').value = (fl * 100 * 0.3048).toFixed(1);
        }
    }

    function convertPress(from) {
        if (from === 'hpa') {
            const hpa = parseFloat(document.getElementById('uc-hpa').value) || 0;
            document.getElementById('uc-inhg').value = (hpa * 0.02953).toFixed(2);
            document.getElementById('uc-mmhg').value = (hpa * 0.750062).toFixed(1);
        } else if (from === 'inhg') {
            const inhg = parseFloat(document.getElementById('uc-inhg').value) || 0;
            document.getElementById('uc-hpa').value = (inhg * 33.8639).toFixed(1);
            document.getElementById('uc-mmhg').value = (inhg * 25.4).toFixed(1);
        } else if (from === 'mmhg') {
            const mmhg = parseFloat(document.getElementById('uc-mmhg').value) || 0;
            document.getElementById('uc-hpa').value = (mmhg / 0.750062).toFixed(1);
            document.getElementById('uc-inhg').value = (mmhg / 25.4).toFixed(2);
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        recalcCrosswind();
        recalcDensityAltitude();
        recalcTas();
        recalcDescent();
        recalcGlide();
        recalcFuel();
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
