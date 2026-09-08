<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$category = trim($_GET['cat'] ?? 'all');
$where = "1=1";
$params = [];

if ($category !== 'all') {
    $where = "`category` = :c";
    $params['c'] = $category;
}

$terms = DB::fetchAll("SELECT * FROM `va_glossary` WHERE {$where} ORDER BY `term` ASC", $params);

$pageTitle = 'Авиационный глоссарий и фразеология радиообмена';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="mb-8">
        <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-indigo-500/10 border border-indigo-500/30 text-indigo-400 text-xs font-mono mb-3">
            <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
            <span>AVIATION DICTIONARY & PHRASEOLOGY</span>
        </div>
        <h1 class="text-3xl font-extrabold text-white">Авиационный словарь, термины и радиообмен</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Аббревиатуры ICAO/IATA, аэродинамика, системы самолетов и радиофразеология</p>
    </div>

    <!-- Category Filter Tabs -->
    <div class="flex items-center space-x-2 overflow-x-auto pb-3 mb-8 text-xs font-mono">
        <a href="glossary.php?cat=all" class="px-3.5 py-2 rounded-xl <?= $category === 'all' ? 'bg-sky-600 text-white font-bold' : 'glass-card text-slate-400 hover:text-white' ?> transition">Все термины</a>
        <a href="glossary.php?cat=acronym" class="px-3.5 py-2 rounded-xl <?= $category === 'acronym' ? 'bg-sky-600 text-white font-bold' : 'glass-card text-slate-400 hover:text-white' ?> transition">Аббревиатуры</a>
        <a href="glossary.php?cat=aerodynamics" class="px-3.5 py-2 rounded-xl <?= $category === 'aerodynamics' ? 'bg-sky-600 text-white font-bold' : 'glass-card text-slate-400 hover:text-white' ?> transition">Аэродинамика</a>
        <a href="glossary.php?cat=navigation" class="px-3.5 py-2 rounded-xl <?= $category === 'navigation' ? 'bg-sky-600 text-white font-bold' : 'glass-card text-slate-400 hover:text-white' ?> transition">Навигация & RVSM</a>
        <a href="glossary.php?cat=systems" class="px-3.5 py-2 rounded-xl <?= $category === 'systems' ? 'bg-sky-600 text-white font-bold' : 'glass-card text-slate-400 hover:text-white' ?> transition">Системы ВС</a>
        <a href="glossary.php?cat=phraseology" class="px-3.5 py-2 rounded-xl <?= $category === 'phraseology' ? 'bg-sky-600 text-white font-bold' : 'glass-card text-slate-400 hover:text-white' ?> transition">Радиообмен</a>
        <a href="glossary.php?cat=slang" class="px-3.5 py-2 rounded-xl <?= $category === 'slang' ? 'bg-sky-600 text-white font-bold' : 'glass-card text-slate-400 hover:text-white' ?> transition">Жаргон & Сленг</a>
    </div>

    <!-- Radio Phraseology Interactive Dialogue Box -->
    <div class="glass-card rounded-3xl p-6 border border-sky-500/20 mb-10 space-y-4 font-mono text-xs">
        <div class="flex items-center space-x-2 text-sky-400 font-bold border-b border-white/5 pb-3">
            <i data-lucide="mic" class="w-4 h-4"></i>
            <span>Пример типового радиообмена при вылете (Tower / Ground)</span>
        </div>

        <div class="space-y-3">
            <div class="p-3 rounded-xl bg-slate-900/80 border border-sky-500/20">
                <span class="text-sky-400 font-bold block">🎙️ Пилот (VladAero 102):</span>
                <span class="text-white">«Шереметьево-Вышка, VladAero 102, на предварительном ВПП 24L, к взлету готов.»</span>
                <div class="text-[10px] text-slate-500 mt-0.5">EN: "Sheremetyevo Tower, VladAero 102, holding point runway 24L, ready for departure."</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900/80 border border-emerald-500/20">
                <span class="text-emerald-400 font-bold block">📻 Диспетчер (Tower):</span>
                <span class="text-white">«VladAero 102, ветер 230 градусов, 6 метров в секунду, ВПП 24L взлет разрешаю.»</span>
                <div class="text-[10px] text-slate-500 mt-0.5">EN: "VladAero 102, wind 230 degrees 12 knots, runway 24L cleared for takeoff."</div>
            </div>

            <div class="p-3 rounded-xl bg-slate-900/80 border border-sky-500/20">
                <span class="text-sky-400 font-bold block">🎙️ Пилот (VladAero 102):</span>
                <span class="text-white">«ВПП 24L взлетаю, VladAero 102.»</span>
                <div class="text-[10px] text-slate-500 mt-0.5">EN: "Runway 24L cleared for takeoff, VladAero 102."</div>
            </div>
        </div>
    </div>

    <!-- Terms Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php foreach ($terms as $t): ?>
            <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-3 font-mono text-xs">
                <div class="flex items-center justify-between border-b border-white/5 pb-2">
                    <h3 class="text-base font-bold text-white font-sans"><?= e($t['term']) ?></h3>
                    <?php if ($t['abbreviation']): ?>
                        <span class="px-2 py-0.5 rounded bg-sky-600/30 text-sky-300 font-bold text-[10px]">
                            <?= e($t['abbreviation']) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="text-amber-400 font-bold"><?= e($t['short_def']) ?></div>
                <p class="text-slate-300 leading-relaxed font-sans text-xs"><?= e($t['full_explanation']) ?></p>

                <?php if ($t['practical_example']): ?>
                    <div class="p-3 rounded-xl bg-slate-950/60 border border-white/5 text-[11px] text-slate-400">
                        <span class="text-sky-400 font-bold">Пример:</span> <?= e($t['practical_example']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
