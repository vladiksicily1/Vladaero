<?php
$pageTitle = 'Авиационный Словарь Терминов, Акронимов и Сленга';
$metaDescription = 'Большой словарь авиационных терминов: расшифровка сокращений V1, Vr, QNH, TCAS, ETOPS, ILS, авиационный сленг и фонетический алфавит ICAO.';
require_once __DIR__ . '/includes/header.php';

$search = trim($_GET['search'] ?? '');
$letter = trim($_GET['letter'] ?? '');

$table = Database::tableName('glossary');
$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "(term LIKE :q OR abbreviation LIKE :q OR short_def LIKE :q OR full_explanation LIKE :q)";
    $params['q'] = "%{$search}%";
}
if (!empty($letter)) {
    $where[] = "term LIKE :l";
    $params['l'] = "{$letter}%";
}

$whereSql = implode(' AND ', $where);
$terms = Database::isConfigured() ? Database::fetchAll("SELECT * FROM `{$table}` WHERE {$whereSql} ORDER BY term ASC") : [];
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <div class="va-card p-6 sm:p-8 mb-8">
        <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3 mb-2">
            <i data-lucide="book-open" class="w-8 h-8 text-sky-400"></i>
            <span>Словарь Авиационных Терминов и Акронимов</span>
        </h1>
        <p class="text-xs text-slate-400 font-mono mb-6">
            Терминология ICAO, сокращения, скорости полета, радионавигация и профессиональный жаргон
        </p>

        <!-- Search Bar -->
        <form method="GET" class="flex gap-3">
            <div class="relative flex-grow">
                <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Поиск термина (V1, QNH, ETOPS, Глиссада, Сваливание, TCAS)..." class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-10 pr-4 py-2.5 text-xs text-slate-100 placeholder-slate-500 font-mono focus:border-sky-500">
            </div>
            <button type="submit" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-6 py-2.5 rounded-xl transition">
                Найти
            </button>
        </form>
    </div>

    <!-- Terms Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php if (empty($terms)): ?>
            <div class="col-span-full va-card p-12 text-center text-slate-500 font-mono text-xs">
                По вашему запросу терминов не найдено. Попробуйте спросить у бортового ИИ-ассистента!
            </div>
        <?php else: ?>
            <?php foreach ($terms as $t): ?>
                <div class="va-card p-6 space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold text-white"><?= e($t['term']) ?></h2>
                        <?php if ($t['abbreviation']): ?>
                            <span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 border border-sky-800 text-xs font-mono font-bold">
                                <?= e($t['abbreviation']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="text-xs font-bold text-sky-300 font-mono">
                        <?= e($t['short_def']) ?>
                    </div>

                    <?php if ($t['full_explanation']): ?>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            <?= nl2br(e($t['full_explanation'])) ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($t['practical_example']): ?>
                        <div class="p-3 rounded-lg bg-slate-950 border border-slate-800 text-[11px] font-mono text-slate-400">
                            <strong class="text-amber-400">Пример в радиообмене:</strong> «<?= e($t['practical_example']) ?>»
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
