<?php
declare(strict_types=1);

namespace VladAero;

$pageTitle = 'Пользовательское соглашение';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="glass-card rounded-3xl p-8 border border-white/5 space-y-6 text-slate-300 text-sm leading-relaxed font-sans">
        <h1 class="text-3xl font-bold text-white font-mono">Условия использования портала VladAero</h1>
        <p>Используя сайт VladAero, вы подтверждаете согласие со следующими правилами:</p>

        <h3 class="text-lg font-bold text-amber-400 font-mono">1. Отказ от ответственности в реальной аэронавигации</h3>
        <p class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs font-mono">
            ⚠️ ВНИМАНИЕ: Все данные, метеосводки METAR, аэронавигационные расчеты и схемы, опубликованные на портале VladAero, носят исключительно информационный и симуляторный характер и НЕ МОГУТ быть использованы для реального планирования и выполнения полетов в авиации.
        </p>

        <h3 class="text-lg font-bold text-sky-400 font-mono">2. Авторские права на споттерские фотографии</h3>
        <p>Все права на опубликованные фотографии принадлежат их авторам. Водяной знак накладывается порталом для защиты авторства от несанкционированного использования третьими лицами.</p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
