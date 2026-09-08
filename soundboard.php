<?php
$pageTitle = 'Cockpit Audio Board — Звуки Авиации, Сигналы GPWS и LiveATC';
$metaDescription = 'Интерактивная звуковая панель кабины пилотов: предупреждения систем GPWS («Pull Up», «Bank Angle»), оповещения TCAS, звуки двигателей GE90, Trent и стримы LiveATC.';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <div class="va-card p-6 sm:p-8 mb-8">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3">
                    <i data-lucide="volume-2" class="w-8 h-8 text-sky-400"></i>
                    <span>Cockpit Audio Board & Звуки Авиации</span>
                </h1>
                <p class="text-xs text-slate-400 mt-1 font-mono">
                    Аварийные речевые информаторы EGPWS / TCAS, сигналы авионики и аудио двигателей
                </p>
            </div>

            <!-- Waveform Visualizer Canvas -->
            <div class="w-full md:w-64 h-12 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-center p-2">
                <canvas id="waveform-canvas" class="w-full h-full"></canvas>
            </div>
        </div>
    </div>

    <!-- Soundboard Categories -->
    <div class="space-y-8">

        <!-- 1. GPWS & EGPWS Terrain Warnings -->
        <div class="va-card p-6 space-y-4">
            <div class="flex items-center space-x-2 text-red-400 font-mono font-bold text-sm">
                <i data-lucide="alert-octagon" class="w-5 h-5"></i>
                <span>Система предупреждения о сближении с землей (GPWS / TAWS)</span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                <button onclick="playCockpitSound('pull_up', 'PULL UP! TERRAIN!')" class="p-4 rounded-xl bg-slate-950 hover:bg-red-950/40 border border-red-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-red-400 font-mono font-bold">WARNING [Mode 2]</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-red-300">«Terrain, Pull Up!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Опасная скорость сближения</div>
                </button>

                <button onclick="playCockpitSound('bank_angle', 'BANK ANGLE!')" class="p-4 rounded-xl bg-slate-950 hover:bg-amber-950/40 border border-amber-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-amber-400 font-mono font-bold">CAUTION [Mode 6]</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-amber-300">«Bank Angle!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Превышение крена > 35°-45°</div>
                </button>

                <button onclick="playCockpitSound('windshear', 'WINDSHEAR! WINDSHEAR!')" class="p-4 rounded-xl bg-slate-950 hover:bg-red-950/40 border border-red-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-red-400 font-mono font-bold">WARNING</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-red-300">«Windshear!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Сдвиг ветра на взлете/посадке</div>
                </button>

                <button onclick="playCockpitSound('sink_rate', 'SINK RATE!')" class="p-4 rounded-xl bg-slate-950 hover:bg-amber-950/40 border border-amber-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-amber-400 font-mono font-bold">CAUTION [Mode 1]</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-amber-300">«Sink Rate!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Чрезмерная скорость снижения</div>
                </button>

                <button onclick="playCockpitSound('minimums', 'MINIMUMS!')" class="p-4 rounded-xl bg-slate-950 hover:bg-sky-950/40 border border-sky-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-sky-400 font-mono font-bold">ADVISORY</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-sky-300">«Minimums!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Высота принятия решения DA/DH</div>
                </button>

                <button onclick="playCockpitSound('too_low_gear', 'TOO LOW, GEAR!')" class="p-4 rounded-xl bg-slate-950 hover:bg-red-950/40 border border-red-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-red-400 font-mono font-bold">WARNING [Mode 4]</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-red-300">«Too Low, Gear!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Шасси не выпущено при заходе</div>
                </button>

                <button onclick="playCockpitSound('glideslope', 'GLIDE SLOPE!')" class="p-4 rounded-xl bg-slate-950 hover:bg-amber-950/40 border border-amber-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-amber-400 font-mono font-bold">CAUTION [Mode 5]</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-amber-300">«Glide Slope!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Уход под глиссаду ILS</div>
                </button>

                <button onclick="playCockpitSound('retard', 'RETARD! RETARD!')" class="p-4 rounded-xl bg-slate-950 hover:bg-sky-950/40 border border-sky-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-sky-400 font-mono font-bold">AIRBUS AUTOTHRUST</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-sky-300">«Retard!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Команда перевода РУД на малый газ</div>
                </button>
            </div>
        </div>

        <!-- 2. TCAS Resolution Advisory Alerts -->
        <div class="va-card p-6 space-y-4">
            <div class="flex items-center space-x-2 text-amber-400 font-mono font-bold text-sm">
                <i data-lucide="shield-alert" class="w-5 h-5"></i>
                <span>Система предотвращения столкновений в воздухе (TCAS II)</span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                <button onclick="playCockpitSound('tcas_traffic', 'TRAFFIC, TRAFFIC!')" class="p-4 rounded-xl bg-slate-950 hover:bg-amber-950/40 border border-amber-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-amber-400 font-mono font-bold">TRAFFIC ADVISORY (TA)</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-amber-300">«Traffic, Traffic!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Опасное сближение (45 сек)</div>
                </button>

                <button onclick="playCockpitSound('tcas_climb', 'CLIMB! CLIMB NOW!')" class="p-4 rounded-xl bg-slate-950 hover:bg-red-950/40 border border-red-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-red-400 font-mono font-bold">RESOLUTION ADVISORY (RA)</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-red-300">«Climb, Climb!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Команда немедленного набора высоты</div>
                </button>

                <button onclick="playCockpitSound('tcas_descend', 'DESCEND! DESCEND NOW!')" class="p-4 rounded-xl bg-slate-950 hover:bg-red-950/40 border border-red-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-red-400 font-mono font-bold">RESOLUTION ADVISORY (RA)</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-red-300">«Descend, Descend!»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Команда немедленного снижения</div>
                </button>

                <button onclick="playCockpitSound('tcas_clear', 'CLEAR OF CONFLICT.')" class="p-4 rounded-xl bg-slate-950 hover:bg-emerald-950/40 border border-emerald-500/40 text-left transition group active:scale-95">
                    <div class="text-[10px] text-emerald-400 font-mono font-bold">CONFLICT RESOLVED</div>
                    <div class="text-sm font-bold text-white mt-1 group-hover:text-emerald-300">«Clear of Conflict»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Угроза столкновения устранена</div>
                </button>
            </div>
        </div>

        <!-- 3. Master Alarms & Chimes -->
        <div class="va-card p-6 space-y-4">
            <div class="flex items-center space-x-2 text-sky-400 font-mono font-bold text-sm">
                <i data-lucide="bell" class="w-5 h-5"></i>
                <span>Сигналы тревоги, колокола и салонные гонги</span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                <button onclick="playToneAlarm('master_warning')" class="p-4 rounded-xl bg-slate-950 hover:bg-red-950/40 border border-red-500/40 text-left transition active:scale-95">
                    <div class="text-[10px] text-red-400 font-mono font-bold">MASTER WARNING</div>
                    <div class="text-sm font-bold text-white mt-1">🚨 Кавалерийская тревога</div>
                    <div class="text-[10px] text-slate-500 mt-1">Критический отказ систем</div>
                </button>

                <button onclick="playToneAlarm('master_caution')" class="p-4 rounded-xl bg-slate-950 hover:bg-amber-950/40 border border-amber-500/40 text-left transition active:scale-95">
                    <div class="text-[10px] text-amber-400 font-mono font-bold">MASTER CAUTION</div>
                    <div class="text-sm font-bold text-white mt-1">🔔 Янтарный сигнал внимания</div>
                    <div class="text-[10px] text-slate-500 mt-1">Предупреждение пилотов</div>
                </button>

                <button onclick="playToneAlarm('seatbelt_chime')" class="p-4 rounded-xl bg-slate-950 hover:bg-sky-950/40 border border-sky-500/40 text-left transition active:scale-95">
                    <div class="text-[10px] text-sky-400 font-mono font-bold">CABIN CHIME</div>
                    <div class="text-sm font-bold text-white mt-1">🛎️ «Пристегните ремни»</div>
                    <div class="text-[10px] text-slate-500 mt-1">Двухтональный гонг салона</div>
                </button>

                <button onclick="playToneAlarm('ap_disconnect')" class="p-4 rounded-xl bg-slate-950 hover:bg-purple-950/40 border border-purple-500/40 text-left transition active:scale-95">
                    <div class="text-[10px] text-purple-400 font-mono font-bold">AUTOPILOT</div>
                    <div class="text-sm font-bold text-white mt-1">📯 Отключение автопилота</div>
                    <div class="text-[10px] text-slate-500 mt-1">Wailer tone сирены</div>
                </button>
            </div>
        </div>

    </div>
</div>

<script>
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

    function drawWaveform() {
        const canvas = document.getElementById('waveform-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 2;
        ctx.beginPath();

        const sliceWidth = canvas.width / 20;
        let x = 0;

        for (let i = 0; i < 20; i++) {
            const v = Math.random() * 0.8 + 0.1;
            const y = (v * canvas.height) / 2 + canvas.height / 4;
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
            x += sliceWidth;
        }
        ctx.stroke();
    }
    setInterval(drawWaveform, 150);

    function playCockpitSound(type, text) {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'en-US';
            utterance.pitch = 0.8;
            utterance.rate = 1.1;
            window.speechSynthesis.speak(utterance);
        }
    }

    function playToneAlarm(type) {
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }

        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);

        const now = audioCtx.currentTime;

        if (type === 'master_warning') {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(800, now);
            osc.frequency.exponentialRampToValueAtTime(400, now + 0.2);
            osc.frequency.exponentialRampToValueAtTime(800, now + 0.4);
            gain.gain.setValueAtTime(0.3, now);
            gain.gain.exponentialRampToValueAtTime(0.01, now + 0.8);
            osc.start(now);
            osc.stop(now + 0.8);
        } else if (type === 'seatbelt_chime') {
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, now); // D5
            osc.frequency.setValueAtTime(440.00, now + 0.25); // A4
            gain.gain.setValueAtTime(0.4, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 1.5);
            osc.start(now);
            osc.stop(now + 1.5);
        } else if (type === 'master_caution') {
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(1000, now);
            gain.gain.setValueAtTime(0.3, now);
            gain.gain.exponentialRampToValueAtTime(0.01, now + 0.3);
            osc.start(now);
            osc.stop(now + 0.3);
        } else if (type === 'ap_disconnect') {
            osc.type = 'square';
            osc.frequency.setValueAtTime(600, now);
            osc.frequency.setValueAtTime(900, now + 0.15);
            gain.gain.setValueAtTime(0.2, now);
            gain.gain.exponentialRampToValueAtTime(0.01, now + 0.5);
            osc.start(now);
            osc.stop(now + 0.5);
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
