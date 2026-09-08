<?php
http_response_code(404);
$pageTitle = '404 — Потеря радиоконтакта (Страница не найдена)';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto px-4 py-24 text-center">
    <div class="va-card p-8 shadow-2xl space-y-6">
        <div class="w-16 h-16 bg-sky-500/10 border border-sky-500/30 rounded-2xl flex items-center justify-center mx-auto text-sky-400 text-3xl">
            📡
        </div>
        <div class="font-mono text-sky-400 text-xs font-bold tracking-widest uppercase">
            LOST RADIO CONTACT // SQUAWK 7600
        </div>
        <h1 class="text-4xl font-black text-white">404</h1>
        <p class="text-xs text-slate-400 leading-relaxed font-sans">
            Запрошенная страница ушла за пределы зоны действия радиовысотомера или была перемещена на другой эшелон.
        </p>
        <div class="pt-2">
            <a href="<?= url('/') ?>" class="inline-block bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-6 py-3 rounded-xl shadow-lg transition">
                Вернуться на глиссаду (Главная)
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
