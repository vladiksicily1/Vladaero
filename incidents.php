<?php
$pageTitle = 'Уроки Безопасности Полетов — Анализ Авиационных Инцидентов';
$metaDescription = 'Разбор ключевых авиационных происшествий и инцидентов: причины, цепочка ошибок, выводы комиссий МАК / NTSB и внедренные системы безопасности.';
require_once __DIR__ . '/includes/header.php';

$incTable = Database::tableName('incidents');
$acTable = Database::tableName('aircraft');

$incidents = Database::isConfigured() ? Database::fetchAll("SELECT i.*, a.model_name FROM `{$incTable}` i LEFT JOIN `{$acTable}` a ON i.aircraft_id = a.id ORDER BY i.incident_date DESC") : [];
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <div class="va-card p-6 sm:p-8 mb-8">
        <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3 mb-2">
            <i data-lucide="shield-alert" class="w-8 h-8 text-sky-400"></i>
            <span>Анализ Безопасности Полетов и Уроки Истории</span>
        </h1>
        <p class="text-xs text-slate-400 font-mono">
            «Правила авиации написаны кровью». Разбор инцидентов, концепция CRM и эволюция систем предотвращения катастроф
        </p>
    </div>

    <!-- Incidents List -->
    <div class="space-y-6">
        <?php if (empty($incidents)): ?>
            <!-- Hardcoded Iconic Cases -->
            <div class="va-card p-6 sm:p-8 space-y-4">
                <div class="flex items-center justify-between font-mono text-xs">
                    <span class="px-2.5 py-1 rounded bg-sky-950 text-sky-400 border border-sky-800 font-bold">15 ЯНВАРЯ 2009</span>
                    <span class="text-emerald-400 font-bold">Успешная посадка на воду (155 спасены)</span>
                </div>
                <h2 class="text-xl font-bold text-white">US Airways 1549 — «Чудо на Гудзоне» (Airbus A320)</h2>
                <p class="text-xs text-slate-300 leading-relaxed">
                    Столкновение со стаей канадских гусей на высоте 850 метров после вылета из Нью-Йорка привело к мгновенному отказу обоих двигателей CFM56. Капитан Чесли Салленбергер и второй пилот Джеффри Скайлз выполнили безупречное приводнение на реку Гудзон без единой жертвы.
                </p>
                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs font-mono text-slate-400 space-y-1">
                    <strong class="text-amber-400">Внедренные уроки:</strong>
                    <div>Пересмотрены процедуры чек-листов при двойном отказе двигателей на малых высотах, модернизирована защита от птиц у турбин новых поколений LEAP-1A и PurePower.</div>
                </div>
            </div>

            <div class="va-card p-6 sm:p-8 space-y-4">
                <div class="flex items-center justify-between font-mono text-xs">
                    <span class="px-2.5 py-1 rounded bg-sky-950 text-sky-400 border border-sky-800 font-bold">23 ИЮЛЯ 1983</span>
                    <span class="text-emerald-400 font-bold">Успешное планирование (69 спасены)</span>
                </div>
                <h2 class="text-xl font-bold text-white">Air Canada 143 — «Планёр Гимли» (Boeing 767-200)</h2>
                <p class="text-xs text-slate-300 leading-relaxed">
                    Из-за путаницы между метрической системой (килограммы) и имперской (фунты) при ручной заправке новейшего Boeing 767 в баки было залито менее половины нужного топлива. На эшелоне FL410 оба двигателя выключились. Пилоты спланировали на заброшенную авиабазу Гимли, используя аварийную ветронасосную турбину RAT.
                </p>
                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs font-mono text-slate-400 space-y-1">
                    <strong class="text-amber-400">Внедренные уроки:</strong>
                    <div>Введен строгий регламент перекрестной проверки массы заправки топливозаправщиком и бортовым компьютером FMC, улучшена подготовка экипажей к пилотированию без тяги.</div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($incidents as $inc): ?>
                <div class="va-card p-6 sm:p-8 space-y-4">
                    <div class="flex items-center justify-between font-mono text-xs">
                        <span class="px-2.5 py-1 rounded bg-sky-950 text-sky-400 border border-sky-800 font-bold"><?= formatDate($inc['incident_date']) ?></span>
                        <span class="text-slate-400"><?= e($inc['location']) ?></span>
                    </div>
                    <h2 class="text-xl font-bold text-white"><?= e($inc['title']) ?></h2>
                    <p class="text-xs text-slate-300 leading-relaxed"><?= nl2br(e($inc['description'])) ?></p>
                    <?php if ($inc['lessons_learned']): ?>
                        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs font-mono text-slate-400">
                            <strong class="text-amber-400">Уроки безопасности:</strong>
                            <div class="mt-1"><?= nl2br(e($inc['lessons_learned'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
