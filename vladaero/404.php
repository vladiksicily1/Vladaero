<?php
declare(strict_types=1);

namespace VladAero;

$pageTitle = '404: Борт потерян на радаре';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto px-4 py-20 text-center font-mono">
    <div class="glass-hud rounded-3xl p-8 border border-rose-500/30 shadow-2xl space-y-4">
        <div class="w-16 h-16 bg-rose-500/10 text-rose-400 rounded-full flex items-center justify-center mx-auto text-3xl font-bold">
            404
        </div>
        <h1 class="text-2xl font-bold text-white">Борт не найден на радаре</h1>
        <p class="text-xs text-slate-400 leading-relaxed">
            Запрошенная страница или карточка самолета была перемещена, списана или никогда не существовала в диспетчерском плане.
        </p>
        <div class="pt-4 flex justify-center space-x-3">
            <a href="index.php" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold transition">
                Вернуться на базу
            </a>
            <a href="aircraft.php" class="px-5 py-2.5 rounded-xl bg-slate-800 text-slate-300 text-xs transition">
                Каталог ВС
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
