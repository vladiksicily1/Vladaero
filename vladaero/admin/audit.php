<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Журнал аудита и безопасности';
require_once __DIR__ . '/header.php';

$logs = DB::fetchAll("SELECT a.*, u.username FROM `va_audit_logs` a LEFT JOIN `va_users` u ON a.user_id = u.id ORDER BY a.id DESC LIMIT 100");
?>

<div class="space-y-6 font-mono text-xs">
    
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans">Журнал аудита действий (Audit Log)</h1>
            <p class="text-xs text-slate-400 mt-1">Неизменяемая хронология действий администрации, скринеров и системных событий</p>
        </div>
    </div>

    <div class="glass-card rounded-3xl border border-white/5 overflow-hidden shadow-2xl">
        <table class="w-full text-left text-xs font-mono">
            <thead class="bg-slate-900/90 text-slate-400 border-b border-white/10 uppercase text-[10px]">
                <tr>
                    <th class="p-4">Время</th>
                    <th class="p-4">Действие</th>
                    <th class="p-4">Пользователь</th>
                    <th class="p-4">IP Адрес</th>
                    <th class="p-4">Детали</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($logs as $l): ?>
                    <tr class="hover:bg-sky-500/5 transition">
                        <td class="p-4 text-slate-400 whitespace-nowrap"><?= e($l['created_at']) ?></td>
                        <td class="p-4 font-bold text-sky-400"><?= e($l['action']) ?></td>
                        <td class="p-4 text-white"><?= e($l['username'] ?: 'Система / Гость') ?></td>
                        <td class="p-4 text-slate-400"><?= e($l['ip_address']) ?></td>
                        <td class="p-4 text-slate-300 font-sans truncate max-w-xs"><?= e($l['details_json'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
