<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Дашборд и мониторинг';
require_once __DIR__ . '/header.php';

$planesCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_aircraft` WHERE `deleted_at` IS NULL");
$photosCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_photos`");
$pendingPhotos = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_photos` WHERE `status` = 'pending'");
$usersCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_users`");
$aiTokensTotal = (int)DB::fetchValue("SELECT SUM(`total_tokens`) FROM `va_ai_usage`");

$recentUsers = DB::fetchAll("SELECT * FROM `va_users` ORDER BY `id` DESC LIMIT 5");
$recentAudit = DB::fetchAll("SELECT a.*, u.username FROM `va_audit_logs` a LEFT JOIN `va_users` u ON a.user_id = u.id ORDER BY a.id DESC LIMIT 6");
?>

<div class="space-y-8">
    
    <!-- Top Stats Row -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 font-mono">
        <div class="glass-card p-5 rounded-2xl border border-white/5 space-y-1">
            <div class="text-[10px] text-slate-400 uppercase">Самолётов в базе</div>
            <div class="text-3xl font-extrabold text-sky-400"><?= $planesCount ?></div>
        </div>
        <div class="glass-card p-5 rounded-2xl border border-white/5 space-y-1">
            <div class="text-[10px] text-slate-400 uppercase">Споттерских фото</div>
            <div class="text-3xl font-extrabold text-amber-400"><?= $photosCount ?></div>
            <?php if ($pendingPhotos > 0): ?>
                <div class="text-[10px] text-rose-400 font-bold"><?= $pendingPhotos ?> на скрининге</div>
            <?php endif; ?>
        </div>
        <div class="glass-card p-5 rounded-2xl border border-white/5 space-y-1">
            <div class="text-[10px] text-slate-400 uppercase">Пользователей</div>
            <div class="text-3xl font-extrabold text-emerald-400"><?= $usersCount ?></div>
        </div>
        <div class="glass-card p-5 rounded-2xl border border-white/5 space-y-1">
            <div class="text-[10px] text-slate-400 uppercase">ИИ-токенов израсходовано</div>
            <div class="text-3xl font-extrabold text-purple-400"><?= number_format($aiTokensTotal, 0, '', ' ') ?></div>
        </div>
    </div>

    <!-- Quick Super-Agent Launcher -->
    <div class="glass-card p-6 rounded-3xl border border-indigo-500/30 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center space-x-4">
            <div class="w-12 h-12 rounded-2xl bg-indigo-600/30 border border-indigo-500/40 text-indigo-300 flex items-center justify-center text-2xl">
                🤖
            </div>
            <div>
                <h3 class="text-lg font-bold text-white font-mono">Супер-Агент VladAero (30+ инструментов)</h3>
                <p class="text-xs text-slate-400 font-mono">Автоматическое наполнение базы ВС, генерация викторин, аудит SEO и анализ логов</p>
            </div>
        </div>
        <a href="ai_agent.php" class="px-6 py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-mono text-xs font-bold shadow-lg shadow-indigo-600/30 transition whitespace-nowrap">
            Запустить Супер-Агента ➔
        </a>
    </div>

    <!-- 2 Column Analytics & Activity -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 font-mono text-xs">
        
        <!-- Recent Users -->
        <div class="glass-card p-6 rounded-3xl border border-white/5 space-y-4">
            <div class="flex items-center justify-between border-b border-white/5 pb-3">
                <h3 class="text-sm font-bold text-white uppercase">Новые пилоты и споттеры</h3>
                <a href="users.php" class="text-sky-400 hover:underline">Все пользователи</a>
            </div>

            <div class="space-y-2">
                <?php foreach ($recentUsers as $u): ?>
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5 flex items-center justify-between">
                        <div>
                            <div class="font-bold text-white"><?= e($u['username']) ?> (<?= e($u['full_name']) ?>)</div>
                            <div class="text-[10px] text-slate-500"><?= e($u['email']) ?></div>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400 font-bold"><?= e($u['rank_title']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Audit Log Stream -->
        <div class="glass-card p-6 rounded-3xl border border-white/5 space-y-4">
            <div class="flex items-center justify-between border-b border-white/5 pb-3">
                <h3 class="text-sm font-bold text-white uppercase">Журнал действий и безопасности</h3>
                <a href="audit.php" class="text-sky-400 hover:underline">Полный лог</a>
            </div>

            <div class="space-y-2">
                <?php foreach ($recentAudit as $log): ?>
                    <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5 flex items-center justify-between">
                        <div>
                            <div class="font-bold text-white"><?= e($log['action']) ?></div>
                            <div class="text-[10px] text-slate-400"><?= e($log['username'] ?: 'Система') ?> • IP: <?= e($log['ip_address']) ?></div>
                        </div>
                        <span class="text-[10px] text-slate-500"><?= time_ago($log['created_at']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
