<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Звуки кабины, реальные предупреждения GPWS/TCAS и LiveATC';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="mb-8">
        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-mono mb-3">
            <i data-lucide="volume-2" class="w-3.5 h-3.5"></i>
            <span>REAL COCKPIT AUDIO & ENGINE SOUNDS</span>
        </div>
        <h1 class="text-3xl font-extrabold text-white">Реальные звуки кабины, предупреждения GPWS/TCAS, двигатели и LiveATC</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Оригинальные записи бортовых речевых информаторов, систем предупреждения и турбовентиляторных двигателей</p>
    </div>

    <!-- LiveATC Stream Player Card -->
    <div class="glass-card rounded-3xl p-6 border border-emerald-500/30 mb-10 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-2xl">
        <div class="flex items-center space-x-4">
            <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-xl">
                📻
            </div>
            <div>
                <span class="text-[10px] font-mono text-emerald-400 uppercase font-bold flex items-center space-x-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                    <span>LIVE AIR TRAFFIC RADIO</span>
                </span>
                <h3 class="text-lg font-bold text-white">Фоновый радиообмен аэропортов (LiveATC)</h3>
                <p class="text-xs text-slate-400 font-mono">Прямая трансляция реальных переговоров диспетчеров и бортов (UUEE / SVO Подход и Вышка)</p>
            </div>
        </div>

        <div class="flex items-center space-x-3">
            <audio id="liveAtcAudio" preload="none">
                <source src="https://s1-fmt2.liveatc.net/uuee2_app" type="audio/mp3">
            </audio>
            <button onclick="toggleLiveAtcStream()" id="liveAtcPlayBtn" class="px-6 py-3 rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-mono text-xs font-bold shadow-lg shadow-emerald-600/30 transition flex items-center space-x-2">
                <i data-lucide="play" class="w-4 h-4" id="liveAtcIcon"></i>
                <span id="liveAtcStatus">Слушать эфир UUEE</span>
            </button>
        </div>
    </div>

    <!-- Real Cockpit Audio Matrix -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
        
        <!-- 1. Real GPWS & Ground Proximity Alerts -->
        <div class="glass-card rounded-3xl p-6 border border-rose-500/30 space-y-4">
            <div class="flex items-center space-x-2 text-rose-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                <span>EGPWS / TAWS (Сближение с землёй)</span>
            </div>

            <div class="space-y-2">
                <button onclick="playRealAudio('terrain-terrain-pull-up.mp3')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-rose-500/20 border border-white/5 hover:border-rose-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-rose-400 transition">«TERRAIN, TERRAIN! PULL UP!»</div>
                        <div class="text-[10px] text-slate-500">Критическая опасность столкновения</div>
                    </div>
                    <span class="text-rose-400">▶</span>
                </button>

                <button onclick="playRealAudio('wshr.wav')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-rose-500/20 border border-white/5 hover:border-rose-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-rose-400 transition">«WINDSHEAR! WINDSHEAR!»</div>
                        <div class="text-[10px] text-slate-500">Сдвиг ветра на глиссаде</div>
                    </div>
                    <span class="text-rose-400">▶</span>
                </button>

                <button onclick="playRealAudio('sink-rate.mp3')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-rose-500/20 border border-white/5 hover:border-rose-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-rose-400 transition">«SINK RATE!»</div>
                        <div class="text-[10px] text-slate-500">Чрезмерная скорость снижения</div>
                    </div>
                    <span class="text-rose-400">▶</span>
                </button>

                <button onclick="playRealAudio('glideslope.wav')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-rose-500/20 border border-white/5 hover:border-rose-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-rose-400 transition">«GLIDE SLOPE!»</div>
                        <div class="text-[10px] text-slate-500">Уход ниже глиссады ILS</div>
                    </div>
                    <span class="text-rose-400">▶</span>
                </button>

                <button onclick="playRealAudio('bank-angle-bank-angle.mp3')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-rose-500/20 border border-white/5 hover:border-rose-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-rose-400 transition">«BANK ANGLE! BANK ANGLE!»</div>
                        <div class="text-[10px] text-slate-500">Превышение предельного крена (>35°)</div>
                    </div>
                    <span class="text-rose-400">▶</span>
                </button>
            </div>
        </div>

        <!-- 2. Real TCAS & Altitude Callouts -->
        <div class="glass-card rounded-3xl p-6 border border-amber-500/30 space-y-4">
            <div class="flex items-center space-x-2 text-amber-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="shield" class="w-4 h-4"></i>
                <span>TCAS II & Речевые высоты</span>
            </div>

            <div class="space-y-2">
                <button onclick="playRealAudio('tcas_climb.mp3')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-amber-500/20 border border-white/5 hover:border-amber-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-amber-400 transition">«CLIMB, CLIMB NOW!»</div>
                        <div class="text-[10px] text-slate-500">Resolution Advisory (RA) — набор высоты</div>
                    </div>
                    <span class="text-amber-400">▶</span>
                </button>

                <button onclick="playRealAudio('tcas_descend.mp3')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-amber-500/20 border border-white/5 hover:border-amber-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-amber-400 transition">«DESCEND, DESCEND NOW!»</div>
                        <div class="text-[10px] text-slate-500">Resolution Advisory (RA) — снижение</div>
                    </div>
                    <span class="text-amber-400">▶</span>
                </button>

                <button onclick="playRealAudio('airbus-retard.mp3')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-amber-500/20 border border-white/5 hover:border-amber-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-amber-400 transition">«RETARD! RETARD!»</div>
                        <div class="text-[10px] text-slate-500">Airbus призыв к установке МГ на посадке</div>
                    </div>
                    <span class="text-amber-400">▶</span>
                </button>

                <button onclick="playRealAudio('500.mp3')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-amber-500/20 border border-white/5 hover:border-amber-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-amber-400 transition">«FIVE HUNDRED» (500 FT)</div>
                        <div class="text-[10px] text-slate-500">Высота стабилизированного захода</div>
                    </div>
                    <span class="text-amber-400">▶</span>
                </button>

                <button onclick="playRealAudio('minimums.mp3')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-amber-500/20 border border-white/5 hover:border-amber-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-amber-400 transition">«MINIMUMS»</div>
                        <div class="text-[10px] text-slate-500">Высота принятия решения (ВПР)</div>
                    </div>
                    <span class="text-amber-400">▶</span>
                </button>
            </div>
        </div>

        <!-- 3. Real Jet Engines & Mechanical Systems -->
        <div class="glass-card rounded-3xl p-6 border border-sky-500/30 space-y-4">
            <div class="flex items-center space-x-2 text-sky-400 font-bold font-mono text-sm border-b border-white/5 pb-3">
                <i data-lucide="fan" class="w-4 h-4"></i>
                <span>Турбины, ВСУ и механика</span>
            </div>

            <div class="space-y-2">
                <button onclick="playRealAudio('jet-trent1.wav')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-sky-500/20 border border-white/5 hover:border-sky-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-sky-400 transition">Турбореактивный двигатель (Rolls-Royce Trent)</div>
                        <div class="text-[10px] text-slate-500">Выход на взлётный режим (TOGA)</div>
                    </div>
                    <span class="text-sky-400">▶</span>
                </button>

                <button onclick="playRealAudio('apu_run.wav')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-sky-500/20 border border-white/5 hover:border-sky-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-sky-400 transition">Вспомогательная силовая установка (ВСУ / APU)</div>
                        <div class="text-[10px] text-slate-500">Генерация электричества и воздуха</div>
                    </div>
                    <span class="text-sky-400">▶</span>
                </button>

                <button onclick="playRealAudio('Flaps.wav')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-sky-500/20 border border-white/5 hover:border-sky-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-sky-400 transition">Гидравлика закрылков (Flaps Extension)</div>
                        <div class="text-[10px] text-slate-500">Выпуск закрылков во взлетное положение</div>
                    </div>
                    <span class="text-sky-400">▶</span>
                </button>

                <button onclick="playRealAudio('gear.wav')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-sky-500/20 border border-white/5 hover:border-sky-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-sky-400 transition">Выпуск шасси (Gear Down & Lock)</div>
                        <div class="text-[10px] text-slate-500">Открытие створок и замок выпущенного положения</div>
                    </div>
                    <span class="text-sky-400">▶</span>
                </button>

                <button onclick="playRealAudio('touchdown.wav')" class="w-full p-3 rounded-2xl bg-slate-900/80 hover:bg-sky-500/20 border border-white/5 hover:border-sky-500/40 text-left transition flex items-center justify-between text-xs font-mono group">
                    <div>
                        <div class="font-bold text-white group-hover:text-sky-400 transition">Касание ВПП (Main Gear Touchdown)</div>
                        <div class="text-[10px] text-slate-500">Визг пневматиков о бетон полосы</div>
                    </div>
                    <span class="text-sky-400">▶</span>
                </button>
            </div>
        </div>

    </div>

</div>

<!-- Real Audio Playback Script -->
<script>
    let activeAudio = null;
    let streamPlaying = false;

    function playRealAudio(filename) {
        if (soundMuted) return;

        if (activeAudio) {
            activeAudio.pause();
            activeAudio.currentTime = 0;
        }

        activeAudio = new Audio('assets/audio/' + filename);
        activeAudio.play().catch(e => {
            console.log('Audio playback error:', e);
        });
    }

    function toggleLiveAtcStream() {
        const audio = document.getElementById('liveAtcAudio');
        const btnText = document.getElementById('liveAtcStatus');
        const icon = document.getElementById('liveAtcIcon');

        if (!streamPlaying) {
            audio.play().then(() => {
                streamPlaying = true;
                btnText.innerText = 'Остановить эфир';
                icon.setAttribute('data-lucide', 'square');
                lucide.createIcons();
            }).catch(() => {
                alert('Не удалось подключиться к открытому потоку LiveATC.');
            });
        } else {
            audio.pause();
            streamPlaying = false;
            btnText.innerText = 'Слушать эфир UUEE';
            icon.setAttribute('data-lucide', 'play');
            lucide.createIcons();
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
