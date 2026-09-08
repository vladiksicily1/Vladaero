<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/e6b.php';

$pageTitle = 'Авиационные калькуляторы E6B и навигационные инструменты';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="mb-8">
        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-purple-500/10 border border-purple-500/30 text-purple-400 text-xs font-mono mb-3">
            <i data-lucide="calculator" class="w-3.5 h-3.5"></i>
            <span>E6B FLIGHT COMPUTER</span>
        </div>
        <h1 class="text-3xl font-extrabold text-white">Авиационные калькуляторы и навигационные расчеты</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Точные инженерные расчеты для пилотов, штурманов и любителей симуляторов</p>
    </div>

    <!-- 6 Calculators Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        
        <!-- 1. Crosswind & Headwind Calculator -->
        <div class="glass-card rounded-3xl p-6 border border-sky-500/20 space-y-4">
            <div class="flex items-center space-x-2 text-sky-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="wind" class="w-4 h-4"></i>
                <span>1. Компоненты ветра (Crosswind & Headwind)</span>
            </div>

            <div class="grid grid-cols-3 gap-3 font-mono text-xs">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Курс ВПП (°)</label>
                    <input type="number" id="calcRwyHeading" value="60" min="0" max="360" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runWindCalc()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Направление ветра (°)</label>
                    <input type="number" id="calcWindDir" value="90" min="0" max="360" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runWindCalc()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Скорость ветра (kts)</label>
                    <input type="number" id="calcWindSpeed" value="18" min="0" max="100" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runWindCalc()">
                </div>
            </div>

            <div id="windResultBox" class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 font-mono text-xs space-y-2">
                <div class="flex justify-between">
                    <span class="text-slate-400">Встречный ветер (Headwind):</span>
                    <span class="text-emerald-400 font-bold text-sm" id="resHeadwind">15.6 kts</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Боковой ветер (Crosswind):</span>
                    <span class="text-amber-400 font-bold text-sm" id="resCrosswind">9.0 kts (справа)</span>
                </div>
                <div class="text-[11px] text-slate-500" id="resWindLimits">В пределах стандартных ограничений гражданских ВС (30 kts).</div>
            </div>
        </div>

        <!-- 2. Top of Descent (TOD) & Glide Slope FPM -->
        <div class="glass-card rounded-3xl p-6 border border-emerald-500/20 space-y-4">
            <div class="flex items-center space-x-2 text-emerald-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="trending-down" class="w-4 h-4"></i>
                <span>2. Точка начала снижения (TOD) и вертикальная скорость</span>
            </div>

            <div class="grid grid-cols-3 gap-3 font-mono text-xs">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Скорость GS (kts)</label>
                    <input type="number" id="calcDescentGs" value="450" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runDescentCalc()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Текущая высота (ft)</label>
                    <input type="number" id="calcDescentCurAlt" value="35000" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runDescentCalc()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Высота выхода (ft)</label>
                    <input type="number" id="calcDescentTgtAlt" value="3000" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runDescentCalc()">
                </div>
            </div>

            <div id="descentResultBox" class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 font-mono text-xs space-y-2">
                <div class="flex justify-between">
                    <span class="text-slate-400">Дистанция до TOD:</span>
                    <span class="text-sky-400 font-bold text-sm" id="resTodDist">100.5 NM (186.1 км)</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Требуемая вертикальная (3°):</span>
                    <span class="text-emerald-400 font-bold text-sm" id="resVsi">-2 384 ft/min (-12.1 м/с)</span>
                </div>
                <div class="flex justify-between text-slate-400">
                    <span>Время снижения:</span>
                    <span class="text-white" id="resDescentTime">13.4 мин</span>
                </div>
            </div>
        </div>

        <!-- 3. Density Altitude & ISA Atmosphere -->
        <div class="glass-card rounded-3xl p-6 border border-amber-500/20 space-y-4">
            <div class="flex items-center space-x-2 text-amber-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="gauge" class="w-4 h-4"></i>
                <span>3. Плоттностная высота (Density Altitude) & ISA</span>
            </div>

            <div class="grid grid-cols-3 gap-3 font-mono text-xs">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Высота по давлению (ft)</label>
                    <input type="number" id="calcDaAlt" value="2000" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runDaCalc()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Температура OAT (°C)</label>
                    <input type="number" id="calcDaTemp" value="30" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runDaCalc()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Давление QNH (hPa)</label>
                    <input type="number" id="calcDaQnh" value="1013" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runDaCalc()">
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 font-mono text-xs space-y-2">
                <div class="flex justify-between">
                    <span class="text-slate-400">Плоттностная высота:</span>
                    <span class="text-amber-400 font-bold text-sm" id="resDaVal">4 250 ft (1 295 м)</span>
                </div>
                <div class="flex justify-between text-slate-400">
                    <span>Отклонение от ISA:</span>
                    <span class="text-white" id="resIsaDev">+19.0 °C</span>
                </div>
                <div class="flex justify-between text-slate-400">
                    <span>Потеря тяги / подъемной силы:</span>
                    <span class="text-rose-400 font-bold" id="resPerfLoss">~7.8%</span>
                </div>
            </div>
        </div>

        <!-- 4. Great Circle Distance & Fuel Burn -->
        <div class="glass-card rounded-3xl p-6 border border-purple-500/20 space-y-4">
            <div class="flex items-center space-x-2 text-purple-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="globe" class="w-4 h-4"></i>
                <span>4. Ортодромия (Great Circle) и расчет топлива</span>
            </div>

            <div class="grid grid-cols-2 gap-3 font-mono text-xs">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Пункт вылета (Москва UUEE)</label>
                    <input type="text" id="calcGcDep" value="55.97, 37.41" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runGcCalc()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Пункт посадки (Владивосток UHWW)</label>
                    <input type="text" id="calcGcArr" value="43.39, 132.14" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runGcCalc()">
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 font-mono text-xs space-y-2">
                <div class="flex justify-between">
                    <span class="text-slate-400">Расстояние по ортодромии:</span>
                    <span class="text-purple-400 font-bold text-sm" id="resGcDist">6 420 км (3 466 NM)</span>
                </div>
                <div class="flex justify-between text-slate-400">
                    <span>Расчетное время полета:</span>
                    <span class="text-white" id="resGcTime">7 ч 48 мин</span>
                </div>
                <div class="flex justify-between text-slate-400">
                    <span>Потребное топливо (A350/B787):</span>
                    <span class="text-emerald-400 font-bold" id="resGcFuel">~23.4 т (с резервом)</span>
                </div>
            </div>
        </div>

        <!-- 5. Universal Aviation Unit Converter -->
        <div class="glass-card rounded-3xl p-6 border border-cyan-500/20 space-y-4">
            <div class="flex items-center space-x-2 text-cyan-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="repeat" class="w-4 h-4"></i>
                <span>5. Универсальный авиационный конвертер величин</span>
            </div>

            <div class="grid grid-cols-2 gap-3 font-mono text-xs">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Значение</label>
                    <input type="number" id="calcConvVal" value="250" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runUnitConvert()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Тип перевода</label>
                    <select id="calcConvType" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" onchange="runUnitConvert()">
                        <option value="knots_to_kmh">Узлы (kts) ➔ км/ч</option>
                        <option value="kmh_to_knots">км/ч ➔ Узлы (kts)</option>
                        <option value="feet_to_meters">Футы (ft) ➔ Метры (м)</option>
                        <option value="meters_to_feet">Метры (м) ➔ Футы (ft)</option>
                        <option value="lbs_to_kg">Фунты (lbs) ➔ Килограммы (кг)</option>
                        <option value="kg_to_lbs">Килограммы (кг) ➔ Фунты (lbs)</option>
                        <option value="hpa_to_inhg">гПа (hPa) ➔ inHg</option>
                    </select>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 font-mono text-xs flex justify-between items-center">
                <span class="text-slate-400">Результат:</span>
                <span class="text-cyan-400 font-bold text-base" id="resUnitConverted">463.0 км/ч</span>
            </div>
        </div>

        <!-- 6. Passenger Delay Compensation Calculator -->
        <div class="glass-card rounded-3xl p-6 border border-rose-500/20 space-y-4">
            <div class="flex items-center space-x-2 text-rose-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="shield-alert" class="w-4 h-4"></i>
                <span>6. Калькулятор компенсации за задержку рейса</span>
            </div>

            <div class="grid grid-cols-2 gap-3 font-mono text-xs">
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Время задержки (часов)</label>
                    <input type="number" id="calcCompDelay" value="4" min="1" max="48" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" oninput="runCompCalc()">
                </div>
                <div>
                    <label class="block text-slate-400 text-[10px] uppercase font-bold mb-1">Юрисдикция / Правила</label>
                    <select id="calcCompJur" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" onchange="runCompCalc()">
                        <option value="eu261">Евросоюз (EU261 / до 600€)</option>
                        <option value="rf">Российская Федерация (ВК РФ)</option>
                    </select>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 font-mono text-xs space-y-2">
                <div class="flex justify-between">
                    <span class="text-slate-400">Положенная выплата:</span>
                    <span class="text-rose-400 font-bold text-sm" id="resCompAmount">400 € на пассажира</span>
                </div>
                <div class="text-[11px] text-slate-500" id="resCompLaw">Регламент ЕС № 261/2004 при рейсах в/из ЕС по вине авиакомпании.</div>
            </div>
        </div>

    </div>

</div>

<!-- Client-side Calculation Script -->
<script>
    function runWindCalc() {
        const rwy = parseInt(document.getElementById('calcRwyHeading').value) || 0;
        const wDir = parseInt(document.getElementById('calcWindDir').value) || 0;
        const wSpd = parseInt(document.getElementById('calcWindSpeed').value) || 0;

        let diff = Math.abs(rwy - wDir) % 360;
        if (diff > 180) diff = 360 - diff;
        const rad = diff * Math.PI / 180;

        const hw = (wSpd * Math.cos(rad)).toFixed(1);
        const cw = (wSpd * Math.sin(rad)).toFixed(1);

        document.getElementById('resHeadwind').innerText = hw + ' kts';
        document.getElementById('resCrosswind').innerText = cw + ' kts';
    }

    function runDescentCalc() {
        const gs = parseInt(document.getElementById('calcDescentGs').value) || 400;
        const cAlt = parseInt(document.getElementById('calcDescentCurAlt').value) || 35000;
        const tAlt = parseInt(document.getElementById('calcDescentTgtAlt').value) || 3000;

        const altLoss = Math.max(0, cAlt - tAlt);
        const todNm = (altLoss / 1000 * 3.1).toFixed(1);
        const todKm = (todNm * 1.852).toFixed(1);
        const fpm = Math.round(gs * 5.2);

        document.getElementById('resTodDist').innerText = `${todNm} NM (${todKm} км)`;
        document.getElementById('resVsi').innerText = `-${fpm} ft/min`;
        document.getElementById('resDescentTime').innerText = ((todNm / gs) * 60).toFixed(1) + ' мин';
    }

    function runDaCalc() {
        const pAlt = parseInt(document.getElementById('calcDaAlt').value) || 0;
        const temp = parseFloat(document.getElementById('calcDaTemp').value) || 15;
        const isa = 15 - (2 * (pAlt / 1000));
        const dev = temp - isa;
        const da = Math.round(pAlt + (120 * dev));

        document.getElementById('resDaVal').innerText = `${da} ft (${Math.round(da * 0.3048)} м)`;
        document.getElementById('resIsaDev').innerText = (dev >= 0 ? '+' : '') + dev.toFixed(1) + ' °C';
    }

    function runUnitConvert() {
        const val = parseFloat(document.getElementById('calcConvVal').value) || 0;
        const type = document.getElementById('calcConvType').value;
        let res = 0;
        let unit = '';

        if (type === 'knots_to_kmh') { res = val * 1.852; unit = 'км/ч'; }
        if (type === 'kmh_to_knots') { res = val * 0.539957; unit = 'kts'; }
        if (type === 'feet_to_meters') { res = val * 0.3048; unit = 'м'; }
        if (type === 'meters_to_feet') { res = val * 3.28084; unit = 'ft'; }
        if (type === 'lbs_to_kg') { res = val * 0.453592; unit = 'кг'; }
        if (type === 'kg_to_lbs') { res = val * 2.20462; unit = 'lbs'; }
        if (type === 'hpa_to_inhg') { res = val * 0.02953; unit = 'inHg'; }

        document.getElementById('resUnitConverted').innerText = res.toFixed(1) + ' ' + unit;
    }

    function runCompCalc() {
        const delay = parseInt(document.getElementById('calcCompDelay').value) || 3;
        const jur = document.getElementById('calcCompJur').value;

        if (jur === 'eu261') {
            const amt = delay >= 4 ? '600 €' : (delay >= 3 ? '400 €' : '250 €');
            document.getElementById('resCompAmount').innerText = amt + ' на пассажира';
            document.getElementById('resCompLaw').innerText = 'Регламент ЕС № 261/2004 при рейсах в/из ЕС по вине авиакомпании.';
        } else {
            document.getElementById('resCompAmount').innerText = 'от ' + (delay * 100) + ' ₽ + возврат питания';
            document.getElementById('resCompLaw').innerText = 'Статья 120 Воздушного Кодекса РФ.';
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
