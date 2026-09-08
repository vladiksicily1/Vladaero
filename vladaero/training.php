<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Лётная школа, ВАК и чеклисты кабины';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Title -->
    <div class="mb-8">
        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-mono mb-3">
            <i data-lucide="graduation-cap" class="w-3.5 h-3.5"></i>
            <span>FLIGHT TRAINING & VIRTUAL AIRLINE</span>
        </div>
        <h1 class="text-3xl font-extrabold text-white">Лётная академия и Виртуальная Авиакомпания (ВАК)</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Интерактивные чеклисты со звуковой озвучкой второго пилота, гайды по авионике и ВАК</p>
    </div>

    <!-- 3 Main Tabs: Checklists / Academy / Liveries & Mods -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Left: Interactive Cockpit Checklist with Voice Callouts -->
        <div class="lg:col-span-7 space-y-6">
            <div class="glass-card rounded-3xl p-6 border border-sky-500/30 shadow-2xl space-y-5">
                <div class="flex items-center justify-between border-b border-white/5 pb-4">
                    <div>
                        <span class="text-[10px] font-mono text-sky-400 uppercase font-bold">Standard Operating Procedures</span>
                        <h2 class="text-xl font-bold text-white">Интерактивный электронный чек-лист: Ту-154М / A350</h2>
                    </div>
                    <button onclick="toggleVoiceCallouts()" id="voiceCalloutsBtn" class="px-3 py-1.5 rounded-xl bg-sky-600/30 text-sky-300 hover:bg-sky-600/50 border border-sky-500/30 text-xs font-mono transition flex items-center space-x-1.5">
                        <i data-lucide="volume-2" class="w-3.5 h-3.5"></i>
                        <span>Голос 2-го пилота: ВКЛ</span>
                    </button>
                </div>

                <!-- Checklist Stages -->
                <div class="space-y-4 font-mono text-xs">
                    
                    <!-- Pre-Flight -->
                    <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 space-y-3">
                        <div class="font-bold text-sky-400 text-sm border-b border-white/5 pb-2 flex justify-between">
                            <span>BEFORE START / ПЕРЕД ЗАПУСКОМ</span>
                            <span class="text-[10px] text-slate-400">4 пункта</span>
                        </div>
                        <label class="flex items-center justify-between p-2 rounded-lg hover:bg-white/5 cursor-pointer">
                            <span class="text-slate-300">Парковочный тормоз (Parking Brake)</span>
                            <span class="text-emerald-400 font-bold flex items-center space-x-2">
                                <span>SET (УСТАНОВЛЕН)</span>
                                <input type="checkbox" class="rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-0" onchange="handleChecklistClick('Parking Brake Set', this)">
                            </span>
                        </label>
                        <label class="flex items-center justify-between p-2 rounded-lg hover:bg-white/5 cursor-pointer">
                            <span class="text-slate-300">Топливные насосы (Fuel Pumps)</span>
                            <span class="text-emerald-400 font-bold flex items-center space-x-2">
                                <span>ON (ВКЛЮЧЕНЫ)</span>
                                <input type="checkbox" class="rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-0" onchange="handleChecklistClick('Fuel Pumps On', this)">
                            </span>
                        </label>
                        <label class="flex items-center justify-between p-2 rounded-lg hover:bg-white/5 cursor-pointer">
                            <span class="text-slate-300">Маяки и стробы (Beacon Light)</span>
                            <span class="text-emerald-400 font-bold flex items-center space-x-2">
                                <span>ON (ВКЛЮЧЕН)</span>
                                <input type="checkbox" class="rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-0" onchange="handleChecklistClick('Beacon On', this)">
                            </span>
                        </label>
                    </div>

                    <!-- Before Takeoff -->
                    <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 space-y-3">
                        <div class="font-bold text-amber-400 text-sm border-b border-white/5 pb-2 flex justify-between">
                            <span>BEFORE TAKEOFF / ПЕРЕД ВЗЛЁТОМ</span>
                            <span class="text-[10px] text-slate-400">3 пункта</span>
                        </div>
                        <label class="flex items-center justify-between p-2 rounded-lg hover:bg-white/5 cursor-pointer">
                            <span class="text-slate-300">Закрылки (Flaps)</span>
                            <span class="text-amber-400 font-bold flex items-center space-x-2">
                                <span>CONFIG 1+F / 15° (ВЫПУЩЕНЫ)</span>
                                <input type="checkbox" class="rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-0" onchange="handleChecklistClick('Flaps Set', this)">
                            </span>
                        </label>
                        <label class="flex items-center justify-between p-2 rounded-lg hover:bg-white/5 cursor-pointer">
                            <span class="text-slate-300">Автоторможение (Autobrake)</span>
                            <span class="text-amber-400 font-bold flex items-center space-x-2">
                                <span>RTO (MAX)</span>
                                <input type="checkbox" class="rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-0" onchange="handleChecklistClick('Autobrake RTO', this)">
                            </span>
                        </label>
                        <label class="flex items-center justify-between p-2 rounded-lg hover:bg-white/5 cursor-pointer">
                            <span class="text-slate-300">Ответчик (Transponder / TCAS)</span>
                            <span class="text-amber-400 font-bold flex items-center space-x-2">
                                <span>TA/RA ON</span>
                                <input type="checkbox" class="rounded bg-slate-800 border-slate-700 text-sky-500 focus:ring-0" onchange="handleChecklistClick('Transponder TA/RA', this)">
                            </span>
                        </label>
                    </div>

                </div>
            </div>
        </div>

        <!-- Right: Virtual Airline & Flight Academy Guides -->
        <div class="lg:col-span-5 space-y-6">
            
            <!-- Virtual Airline Hangar Card -->
            <div class="glass-card rounded-3xl p-6 border border-rose-500/30 space-y-4">
                <div class="flex items-center space-x-2 text-rose-400 text-xs font-mono font-bold uppercase">
                    <i data-lucide="plane-takeoff" class="w-4 h-4"></i>
                    <span>Платформа ВАК «VladAero Virtual»</span>
                </div>
                <h3 class="text-lg font-bold text-white">Карьера виртуального пилота</h3>
                <p class="text-xs text-slate-400 font-mono leading-relaxed">
                    Выполняйте регулярные рейсы, накапливайте лётные часы в сетях VATSIM / IVAO, повышайте звание от Курсанта до Шеф-пилота и открывайте тяжелые широкофюзеляжные лайнеры.
                </p>
                <div class="pt-2">
                    <a href="logbook.php" class="w-full py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-mono text-xs font-bold text-center block shadow-lg shadow-rose-600/30 transition">
                        Открыть личный журнал налёта
                    </a>
                </div>
            </div>

            <!-- Training Academy Guides -->
            <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-3 font-mono text-xs">
                <h3 class="text-sm font-bold text-white border-b border-white/5 pb-2">📚 Учебные пособия и гайды</h3>
                
                <a href="glossary.php" class="p-3 rounded-xl bg-slate-900/80 hover:bg-sky-500/10 border border-white/5 flex items-center justify-between text-slate-300 hover:text-sky-400 transition">
                    <span>🎙️ Фразеология радиообмена (RU/EN)</span>
                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </a>
                <a href="quizzes.php" class="p-3 rounded-xl bg-slate-900/80 hover:bg-sky-500/10 border border-white/5 flex items-center justify-between text-slate-300 hover:text-sky-400 transition">
                    <span>🧭 Экзамен по теории аэродинамики</span>
                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </a>
                <a href="calculators.php" class="p-3 rounded-xl bg-slate-900/80 hover:bg-sky-500/10 border border-white/5 flex items-center justify-between text-slate-300 hover:text-sky-400 transition">
                    <span>📐 Практический расчет посадочной глиссады</span>
                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </a>
            </div>

        </div>

    </div>

</div>

<!-- Voice Callouts & Checklist Audio Script -->
<script>
    let voiceCalloutsEnabled = true;

    function toggleVoiceCallouts() {
        voiceCalloutsEnabled = !voiceCalloutsEnabled;
        const btn = document.getElementById('voiceCalloutsBtn');
        btn.innerText = 'Голос 2-го пилота: ' + (voiceCalloutsEnabled ? 'ВКЛ' : 'ВЫКЛ');
    }

    function handleChecklistClick(calloutText, checkbox) {
        if (checkbox.checked && voiceCalloutsEnabled && 'speechSynthesis' in window) {
            const utter = new SpeechSynthesisUtterance(calloutText);
            utter.lang = 'en-US';
            utter.rate = 1.0;
            window.speechSynthesis.speak(utter);
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
