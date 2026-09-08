<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$slug = trim($_GET['slug'] ?? '');

// Single Incident Investigation View
if (!empty($slug)) {
    $incident = DB::fetchOne("SELECT * FROM `va_incidents` WHERE `slug` = :s", ['s' => $slug]);
    if (!$incident) {
        header("HTTP/1.0 404 Not Found");
        require_once __DIR__ . '/404.php';
        exit;
    }

    $pageTitle = "Расследование: {$incident['title']}";
    require_once __DIR__ . '/includes/header.php';
    ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="glass-card rounded-3xl p-8 border border-white/5 space-y-6">
            
            <div class="flex items-center space-x-2 text-xs font-mono text-rose-400">
                <i data-lucide="shield-alert" class="w-4 h-4"></i>
                <span>AVIATION SAFETY REPORT • <?= e($incident['event_date']) ?></span>
            </div>

            <h1 class="text-3xl font-bold text-white"><?= e($incident['title']) ?></h1>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 font-mono text-xs">
                <div class="p-3 rounded-xl bg-slate-900/80 border border-white/5">
                    <span class="text-slate-500 text-[9px] block">Тип ВС</span>
                    <span class="text-white font-bold"><?= e($incident['aircraft_type']) ?></span>
                </div>
                <div class="p-3 rounded-xl bg-slate-900/80 border border-white/5">
                    <span class="text-slate-500 text-[9px] block">Авиакомпания</span>
                    <span class="text-white font-bold"><?= e($incident['airline_name'] ?: '—') ?></span>
                </div>
                <div class="p-3 rounded-xl bg-slate-900/80 border border-white/5">
                    <span class="text-slate-500 text-[9px] block">Погибшие</span>
                    <span class="text-rose-400 font-bold"><?= (int)$incident['fatalities'] ?></span>
                </div>
                <div class="p-3 rounded-xl bg-slate-900/80 border border-white/5">
                    <span class="text-slate-500 text-[9px] block">Выжившие</span>
                    <span class="text-emerald-400 font-bold"><?= (int)$incident['survivors'] ?></span>
                </div>
            </div>

            <div class="space-y-3 text-sm text-slate-300 leading-relaxed font-sans">
                <h3 class="text-base font-bold text-white font-mono">Хроника происшествия</h3>
                <p><?= nl2br(e($incident['summary'])) ?></p>
            </div>

            <div class="p-5 rounded-2xl glass-hud border border-emerald-500/30 space-y-2">
                <h3 class="text-sm font-bold text-emerald-400 font-mono">🛡️ Уроки безопасности и выводы для авиаиндустрии</h3>
                <div class="text-xs text-slate-200 font-mono leading-relaxed"><?= nl2br(e($incident['safety_lessons'])) ?></div>
            </div>

        </div>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Incidents List
$incidents = DB::fetchAll("SELECT * FROM `va_incidents` ORDER BY `event_date` DESC");

$pageTitle = 'Безопасность полетов и база авиационных происшествий';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-white">Безопасность полетов и разборы инцидентов</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Официальные отчеты МАК/NTSB, анализ причин и уроки безопасности для авиапрома</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php foreach ($incidents as $inc): ?>
            <a href="incidents.php?slug=<?= e($inc['slug']) ?>" class="glass-card p-6 rounded-3xl border border-white/5 hover:border-rose-500/40 transition flex flex-col justify-between group">
                <div class="space-y-3">
                    <div class="flex items-center justify-between text-xs font-mono">
                        <span class="text-slate-400"><?= e($inc['event_date']) ?></span>
                        <span class="px-2 py-0.5 rounded bg-slate-800 text-sky-400 font-bold"><?= e($inc['aircraft_type']) ?></span>
                    </div>

                    <h3 class="text-lg font-bold text-white group-hover:text-rose-400 transition"><?= e($inc['title']) ?></h3>
                    <p class="text-xs text-slate-400 line-clamp-3 leading-relaxed"><?= e($inc['summary']) ?></p>
                </div>

                <div class="pt-4 mt-4 border-t border-white/5 flex items-center justify-between text-xs font-mono text-slate-400">
                    <span>Выжившие: <?= (int)$inc['survivors'] ?></span>
                    <span class="text-rose-400">Читать разбор ➔</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
