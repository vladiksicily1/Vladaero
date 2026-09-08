<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$airlines = DB::fetchAll("SELECT a.*, apt.name_ru AS hub_name, apt.icao AS hub_icao FROM `va_airlines` a LEFT JOIN `va_airports` apt ON a.hub_airport_id = apt.id ORDER BY a.name_ru ASC");

$pageTitle = 'Каталог авиакомпаний мира, флот и правила багажа';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-white">Авиакомпании мира и справочник флота</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Оценки пассажиров, правила провоза багажа, позывные и альянсы</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($airlines as $al): ?>
            <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-4 flex flex-col justify-between">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2.5 py-1 rounded-lg bg-sky-600/30 text-sky-300 font-mono text-xs font-bold border border-sky-500/30">
                            <?= e($al['iata'] ?: '—') ?> / <?= e($al['icao'] ?: '—') ?>
                        </span>
                        <span class="text-[11px] font-mono text-slate-400"><?= e($al['country']) ?></span>
                    </div>

                    <h3 class="text-xl font-bold text-white"><?= e($al['name_ru']) ?></h3>
                    <div class="text-xs text-slate-400 font-mono">Позывной: <strong class="text-white"><?= e($al['callsign'] ?: '—') ?></strong></div>

                    <p class="text-xs text-slate-300 leading-relaxed"><?= e($al['description']) ?></p>

                    <?php if ($al['baggage_rules']): ?>
                        <div class="p-3 rounded-xl bg-slate-900/80 border border-white/5 font-mono text-[11px] text-slate-300 space-y-1">
                            <span class="text-amber-400 font-bold block">🧳 Нормы багажа:</span>
                            <div><?= e($al['baggage_rules']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pt-4 border-t border-white/5 grid grid-cols-2 gap-2 text-xs font-mono text-slate-400">
                    <div>Флот: <strong class="text-white"><?= (int)$al['fleet_size'] ?> ВС</strong></div>
                    <div>Хаб: <strong class="text-sky-400"><?= e($al['hub_icao'] ?: '—') ?></strong></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
