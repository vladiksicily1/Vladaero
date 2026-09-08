<?php
$pageTitle = 'Авиакомпании мира — Флот, Базовые Хабы и Позывные';
$metaDescription = 'Каталог мировых и российских авиакомпаний: размеры флота, используемые типы самолетов, базовые аэропорты-хабы, позывные и альянсы.';
require_once __DIR__ . '/includes/header.php';

$alTable = Database::tableName('airlines');
$apTable = Database::tableName('airports');

$airlines = Database::isConfigured() ? Database::fetchAll("SELECT al.*, ap.name_ru as hub_name, ap.icao as hub_icao FROM `{$alTable}` al LEFT JOIN `{$apTable}` ap ON al.hub_airport_id = ap.id ORDER BY al.country_ru ASC, al.fleet_size DESC") : [];
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <div class="va-card p-6 sm:p-8 mb-8">
        <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3 mb-2">
            <i data-lucide="building-2" class="w-8 h-8 text-sky-400"></i>
            <span>Авиакомпании и Воздушный Флот</span>
        </h1>
        <p class="text-xs text-slate-400 font-mono">
            База данных мировых перевозчиков: позывные радиотелефонии, состав флота и ключевые узловые хабы
        </p>
    </div>

    <!-- Airlines Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($airlines as $al): ?>
            <div class="va-card p-6 space-y-4 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-3 font-mono">
                        <div class="flex items-center space-x-2">
                            <span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 border border-sky-800 text-xs font-bold"><?= e($al['icao_code']) ?></span>
                            <?php if ($al['iata_code']): ?>
                                <span class="px-2 py-0.5 rounded bg-slate-950 text-slate-300 border border-slate-800 text-xs font-bold"><?= e($al['iata_code']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs text-slate-500"><?= e($al['country_ru']) ?></span>
                    </div>

                    <h2 class="text-xl font-bold text-white mb-1"><?= e($al['name_ru']) ?></h2>
                    <div class="text-xs text-slate-400 font-mono mb-4">Позывной: <strong class="text-sky-300">«<?= e($al['callsign'] ?: $al['name_en']) ?>»</strong></div>

                    <div class="space-y-2 text-xs font-mono">
                        <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800 flex justify-between">
                            <span class="text-slate-500">Базовый хаб:</span>
                            <span class="text-slate-200"><?= e($al['hub_name'] ?: 'Шереметьево (UUEE)') ?></span>
                        </div>
                        <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800 flex justify-between">
                            <span class="text-slate-500">Размер флота:</span>
                            <span class="text-emerald-400 font-bold"><?= $al['fleet_size'] ?> бортов</span>
                        </div>
                        <?php if ($al['alliance']): ?>
                            <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800 flex justify-between">
                                <span class="text-slate-500">Авиаальянс:</span>
                                <span class="text-amber-400 font-bold"><?= e($al['alliance']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-between text-xs font-mono">
                    <a href="<?= url('/radar.php') ?>" class="text-sky-400 hover:underline flex items-center space-x-1">
                        <span>Найти рейсы на радаре</span>
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
