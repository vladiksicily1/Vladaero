<?php
$pageTitle = 'Метеосводки METAR / TAF и Авиационная Погода';
$metaDescription = 'Русскоязычный декодер авиационных сводок погоды METAR, TAF, NOTAM. Оценка VFR/IFR категорий, порывы ветра, облачность и голосовой информатор ATIS.';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/weather_decoder.php';

$icao = strtoupper(trim($_GET['icao'] ?? 'UUEE'));
$weatherData = WeatherDecoder::getMetar($icao);
$data = $weatherData['success'] ? $weatherData['data'] : null;
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Search & Hub Selector -->
    <div class="va-card p-6 mb-8">
        <form method="GET" class="flex flex-col sm:flex-row items-center gap-4">
            <div class="relative flex-grow w-full">
                <i data-lucide="search" class="w-5 h-5 text-sky-400 absolute left-4 top-1/2 -translate-y-1/2"></i>
                <input type="text" name="icao" value="<?= e($icao) ?>" placeholder="Введите 4-буквенный ICAO код (например: UUEE, ULLI, EGLL, KJFK)..." class="w-full bg-slate-950 border border-slate-700/80 rounded-xl pl-12 pr-4 py-3.5 text-sm text-slate-100 placeholder-slate-500 font-mono uppercase focus:outline-none focus:border-sky-500">
            </div>
            <button type="submit" class="w-full sm:w-auto bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold px-6 py-3.5 rounded-xl shadow-lg transition flex items-center justify-center space-x-2">
                <i data-lucide="cloud-sun" class="w-4 h-4"></i>
                <span>Декодировать METAR</span>
            </button>
        </form>

        <!-- Quick Hub Tags -->
        <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-slate-800 text-xs font-mono text-slate-400">
            <span>Популярные аэродромы:</span>
            <a href="?icao=UUEE" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Шереметьево (UUEE)</a>
            <a href="?icao=UUDD" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Домодедово (UUDD)</a>
            <a href="?icao=UUWW" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Внуково (UUWW)</a>
            <a href="?icao=ULLI" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Пулково (ULLI)</a>
            <a href="?icao=URSS" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Сочи (URSS)</a>
            <a href="?icao=UNNT" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Толмачево (UNNT)</a>
            <a href="?icao=EGLL" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Хитроу (EGLL)</a>
            <a href="?icao=KJFK" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Кеннеди (KJFK)</a>
            <a href="?icao=OMDB" class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 hover:border-sky-500 text-sky-400">Дубай (OMDB)</a>
        </div>
    </div>

    <?php if ($data): 
        $catColors = [
            'VFR' => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400',
            'MVFR' => 'bg-sky-500/10 border-sky-500/30 text-sky-400',
            'IFR' => 'bg-red-500/10 border-red-500/30 text-red-400',
            'LIFR' => 'bg-purple-500/10 border-purple-500/30 text-purple-400'
        ];
        $catClass = $catColors[$data['flight_category']] ?? $catColors['VFR'];
    ?>
        <!-- Main Weather Dashboard Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Col 1 & 2: Decoded Summary -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Raw METAR & Category Badge Card -->
                <div class="va-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <div class="text-xs text-slate-400 font-mono">АЭРОДРОМ</div>
                            <h2 class="text-2xl font-bold text-white font-mono"><?= e($icao) ?></h2>
                        </div>
                        <div class="px-4 py-2 rounded-xl border font-mono font-bold text-sm tracking-wider <?= $catClass ?>">
                            <?= e($data['flight_category']) ?> CONDITIONS
                        </div>
                    </div>

                    <!-- Raw METAR String Box -->
                    <div class="p-4 bg-slate-950 rounded-xl border border-slate-800 font-mono text-xs text-sky-400 break-all select-all mb-4">
                        <?= e($data['raw']) ?>
                    </div>

                    <!-- ATIS Audio Synthesizer Bar -->
                    <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <button onclick="playAtisSpeech('<?= addslashes($data['atis_speech']) ?>')" class="w-10 h-10 rounded-xl bg-sky-600 hover:bg-sky-500 text-white flex items-center justify-center shadow-lg transition">
                                <i data-lucide="volume-2" class="w-5 h-5"></i>
                            </button>
                            <div>
                                <div class="text-xs font-bold text-slate-100">Голосовой информатор ATIS</div>
                                <div class="text-[11px] text-slate-400 font-mono">Озвучка роботом-диктором на русском языке</div>
                            </div>
                        </div>
                        <button onclick="window.speechSynthesis.cancel()" class="text-xs text-slate-400 hover:text-red-400 font-mono">
                            Стоп
                        </button>
                    </div>
                </div>

                <!-- Parameters Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    <!-- Wind Parameter -->
                    <div class="va-card p-5 space-y-2">
                        <div class="flex items-center justify-between text-xs font-mono text-slate-400">
                            <span class="flex items-center space-x-1.5">
                                <i data-lucide="wind" class="w-4 h-4 text-sky-400"></i>
                                <span>ВЕТЕР У ЗЕМЛИ</span>
                            </span>
                            <span class="font-bold text-sky-400"><?= $data['wind']['speed_kt'] ?> KT</span>
                        </div>
                        <div class="text-lg font-bold text-slate-100"><?= e($data['wind']['text_ru']) ?></div>
                        <div class="text-xs text-slate-400 font-mono">
                            Скорость: <?= $data['wind']['speed_ms'] ?> м/с (<?= $data['wind']['speed_kt'] ?> узлов)
                        </div>
                    </div>

                    <!-- Visibility Parameter -->
                    <div class="va-card p-5 space-y-2">
                        <div class="flex items-center justify-between text-xs font-mono text-slate-400">
                            <span class="flex items-center space-x-1.5">
                                <i data-lucide="eye" class="w-4 h-4 text-emerald-400"></i>
                                <span>ВИДИМОСТЬ</span>
                            </span>
                            <span class="font-bold text-emerald-400"><?= $data['visibility']['km'] ?> КМ</span>
                        </div>
                        <div class="text-lg font-bold text-slate-100"><?= e($data['visibility']['text_ru']) ?></div>
                        <div class="text-xs text-slate-400 font-mono">
                            <?= $data['visibility']['meters'] ?> метров
                        </div>
                    </div>

                    <!-- Temperature / Dewpoint -->
                    <div class="va-card p-5 space-y-2">
                        <div class="flex items-center justify-between text-xs font-mono text-slate-400">
                            <span class="flex items-center space-x-1.5">
                                <i data-lucide="thermometer" class="w-4 h-4 text-amber-400"></i>
                                <span>ТЕМПЕРАТУРА / РОСА</span>
                            </span>
                            <span class="font-bold text-amber-400"><?= $data['temperature_c'] ?>°C</span>
                        </div>
                        <div class="text-lg font-bold text-slate-100">
                            <?= $data['temperature_c'] ?>°C (Точка росы: <?= $data['dewpoint_c'] ?>°C)
                        </div>
                        <div class="text-xs text-slate-400 font-mono">
                            Относительная влажность: ~<?= $data['humidity_percent'] ?>%
                        </div>
                    </div>

                    <!-- QNH Altimeter -->
                    <div class="va-card p-5 space-y-2">
                        <div class="flex items-center justify-between text-xs font-mono text-slate-400">
                            <span class="flex items-center space-x-1.5">
                                <i data-lucide="gauge" class="w-4 h-4 text-purple-400"></i>
                                <span>ДАВЛЕНИЕ QNH</span>
                            </span>
                            <span class="font-bold text-purple-400"><?= $data['qnh_hpa'] ?> hPa</span>
                        </div>
                        <div class="text-lg font-bold text-slate-100"><?= $data['qnh_hpa'] ?> гПа</div>
                        <div class="text-xs text-slate-400 font-mono">
                            <?= $data['qnh_inhg'] ?> inHg | <?= $data['qnh_mmhg'] ?> мм рт. ст.
                        </div>
                    </div>
                </div>

                <!-- Cloud Layers & Weather Phenomena -->
                <div class="va-card p-6 space-y-4">
                    <h3 class="text-sm font-bold text-slate-200 font-mono uppercase">Облачность и явления погоды</h3>
                    
                    <?php if (!empty($data['clouds'])): ?>
                        <div class="space-y-2">
                            <?php foreach ($data['clouds'] as $cl): ?>
                                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between text-xs font-mono">
                                    <span class="text-slate-300"><?= e($cl['text_ru']) ?></span>
                                    <span class="text-sky-400 font-bold"><?= $cl['code'] ?><?= str_pad($cl['altitude_ft'] / 100, 3, '0', STR_PAD_LEFT) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 text-xs text-emerald-400 font-mono">
                            Облачность отсутствует или выше 5 000 футов (CAVOK / SKC).
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($data['weather_phenomena'])): ?>
                        <div class="pt-2">
                            <div class="text-xs font-bold text-amber-400 font-mono mb-2">Особые явления:</div>
                            <?php foreach ($data['weather_phenomena'] as $ph): ?>
                                <div class="p-2.5 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs font-mono mb-1">
                                    <strong><?= e($ph['code']) ?>:</strong> <?= e($ph['text_ru']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Col 3: Flight Conditions Explainer & E6B Link -->
            <div class="space-y-6">
                <!-- Flight Categories Guide -->
                <div class="va-card p-6 space-y-4">
                    <h3 class="text-sm font-bold text-slate-200 font-mono uppercase">Критерии категорий полетов</h3>
                    <div class="space-y-2.5 text-xs font-mono">
                        <div class="p-3 rounded-xl border bg-emerald-500/10 border-emerald-500/30 text-emerald-300">
                            <div class="font-bold">VFR (Visual Flight Rules)</div>
                            <div class="text-[11px] text-slate-400 mt-0.5">Край облаков > 3000 ft и видимость > 8 км</div>
                        </div>
                        <div class="p-3 rounded-xl border bg-sky-500/10 border-sky-500/30 text-sky-300">
                            <div class="font-bold">MVFR (Marginal VFR)</div>
                            <div class="text-[11px] text-slate-400 mt-0.5">Край 1000-3000 ft или видимость 5-8 км</div>
                        </div>
                        <div class="p-3 rounded-xl border bg-red-500/10 border-red-500/30 text-red-300">
                            <div class="font-bold">IFR (Instrument Flight Rules)</div>
                            <div class="text-[11px] text-slate-400 mt-0.5">Край 500-1000 ft или видимость 1.6-5 км</div>
                        </div>
                        <div class="p-3 rounded-xl border bg-purple-500/10 border-purple-500/30 text-purple-300">
                            <div class="font-bold">LIFR (Low IFR)</div>
                            <div class="text-[11px] text-slate-400 mt-0.5">Край < 500 ft или видимость < 1.6 км</div>
                        </div>
                    </div>
                </div>

                <!-- Crosswind Calculation Quick Link -->
                <div class="va-card p-6 space-y-3">
                    <h3 class="text-sm font-bold text-slate-200 font-mono uppercase">Летные расчеты по погоде</h3>
                    <p class="text-xs text-slate-400 leading-relaxed">
                        Рассчитайте составляющую бокового ветра относительно активных ВПП аэродрома и плотностную высоту.
                    </p>
                    <a href="<?= url('/calculators.php?qnh=' . $data['qnh_hpa'] . '&temp=' . $data['temperature_c'] . '&wind_dir=' . $data['wind']['direction_deg'] . '&wind_spd=' . $data['wind']['speed_kt']) ?>" class="block w-full text-center bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold py-3 rounded-xl text-xs shadow-lg transition">
                        Рассчитать боковой ветер и DA
                    </a>
                </div>
            </div>

        </div>
    <?php endif; ?>

</div>

<script>
    function playAtisSpeech(text) {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'ru-RU';
            utterance.rate = 0.9;
            window.speechSynthesis.speak(utterance);
        } else {
            alert('Синтез речи не поддерживается данным браузером');
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
