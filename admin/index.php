<?php
$adminTitle = 'Дашборд Администратора';
require_once __DIR__ . '/header.php';

$acCount = Database::fetchValue("SELECT COUNT(*) FROM `" . Database::tableName('aircraft') . "` WHERE deleted_at IS NULL") ?: 0;
$apCount = Database::fetchValue("SELECT COUNT(*) FROM `" . Database::tableName('airports') . "`") ?: 0;
$photoCount = Database::fetchValue("SELECT COUNT(*) FROM `" . Database::tableName('photos') . "` WHERE status = 'approved'") ?: 0;
$pendingPhotos = Database::fetchValue("SELECT COUNT(*) FROM `" . Database::tableName('photos') . "` WHERE status = 'pending'") ?: 0;
$userCount = Database::fetchValue("SELECT COUNT(*) FROM `" . Database::tableName('users') . "` WHERE deleted_at IS NULL") ?: 0;
$articleCount = Database::fetchValue("SELECT COUNT(*) FROM `" . Database::tableName('articles') . "` WHERE is_published = 1") ?: 0;

$auditLogs = Database::fetchAll("SELECT a.*, u.username FROM `" . Database::tableName('audit_logs') . "` a LEFT JOIN `" . Database::tableName('users') . "` u ON a.user_id = u.id ORDER BY a.id DESC LIMIT 8");
?>

<div class="space-y-8">

    <!-- Welcome Banner -->
    <div class="va-card p-6 sm:p-8 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white flex items-center space-x-2">
                <span>Центр Управления VladAero</span>
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
            </h1>
            <p class="text-xs text-slate-400 font-mono mt-1">
                Администратор: <strong class="text-sky-400"><?= e($currentUser['full_name'] ?: $currentUser['username']) ?></strong> • База данных: <span class="text-emerald-400">В норме</span>
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="<?= url('/admin/ai_agent.php') ?>" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-mono font-bold text-xs px-4 py-2.5 rounded-xl shadow-lg transition flex items-center space-x-1.5">
                <i data-lucide="bot" class="w-4 h-4"></i>
                <span>Super Admin AI</span>
            </a>
            <a href="<?= url('/admin/aircraft.php?action=add') ?>" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-4 py-2.5 rounded-xl shadow-lg transition flex items-center space-x-1.5">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Добавить ВС</span>
            </a>
        </div>
    </div>

    <!-- Statistics Metrics Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 font-mono text-xs">
        <a href="<?= url('/admin/aircraft.php') ?>" class="va-card p-4 block hover:border-sky-500/50 transition">
            <div class="text-slate-400 text-[11px]">Самолеты</div>
            <div class="text-2xl font-black text-sky-400 mt-1"><?= $acCount ?></div>
        </a>

        <a href="<?= url('/admin/airports.php') ?>" class="va-card p-4 block hover:border-sky-500/50 transition">
            <div class="text-slate-400 text-[11px]">Аэропорты</div>
            <div class="text-2xl font-black text-emerald-400 mt-1"><?= $apCount ?></div>
        </a>

        <a href="<?= url('/admin/photos.php') ?>" class="va-card p-4 block hover:border-sky-500/50 transition relative">
            <div class="text-slate-400 text-[11px]">Споттинг фото</div>
            <div class="text-2xl font-black text-amber-400 mt-1"><?= $photoCount ?></div>
            <?php if ($pendingPhotos > 0): ?>
                <span class="absolute top-3 right-3 px-1.5 py-0.5 rounded-full bg-red-600 text-white text-[9px] font-bold">
                    +<?= $pendingPhotos ?> на модерации
                </span>
            <?php endif; ?>
        </a>

        <a href="<?= url('/admin/articles.php') ?>" class="va-card p-4 block hover:border-sky-500/50 transition">
            <div class="text-slate-400 text-[11px]">Статьи</div>
            <div class="text-2xl font-black text-purple-400 mt-1"><?= $articleCount ?></div>
        </a>

        <a href="<?= url('/admin/users.php') ?>" class="va-card p-4 block hover:border-sky-500/50 transition">
            <div class="text-slate-400 text-[11px]">Пользователи</div>
            <div class="text-2xl font-black text-slate-100 mt-1"><?= $userCount ?></div>
        </a>

        <a href="<?= url('/admin/settings.php') ?>" class="va-card p-4 block hover:border-sky-500/50 transition">
            <div class="text-slate-400 text-[11px]">ИИ Модель</div>
            <div class="text-xs font-bold text-sky-300 mt-2 truncate"><?= e(getSetting('ai_model_id', 'gpt-4o-mini')) ?></div>
        </a>
    </div>

    <!-- Recent Audit Logs Table -->
    <div class="va-card overflow-hidden shadow-xl">
        <div class="p-6 border-b border-slate-800 flex items-center justify-between">
            <h2 class="text-sm font-bold text-white uppercase font-mono tracking-wider flex items-center space-x-2">
                <i data-lucide="shield" class="w-4 h-4 text-sky-400"></i>
                <span>Последние события безопасности и действия</span>
            </h2>
            <a href="<?= url('/admin/audit.php') ?>" class="text-xs font-mono text-sky-400 hover:underline">Полный журнал</a>
        </div>

        <?php if (empty($auditLogs)): ?>
            <div class="p-8 text-center text-slate-500 font-mono text-xs">
                Журнал действий пока чист.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="p-4">Время</th>
                            <th class="p-4">Пользователь</th>
                            <th class="p-4">Действие</th>
                            <th class="p-4">Сущность</th>
                            <th class="p-4">IP Адрес</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($auditLogs as $log): ?>
                            <tr>
                                <td class="p-4 text-slate-400"><?= formatDate($log['created_at'], true) ?></td>
                                <td class="p-4 font-bold text-sky-400"><?= e($log['username'] ?: 'Система') ?></td>
                                <td class="p-4 text-slate-200"><?= e($log['action']) ?></td>
                                <td class="p-4 text-slate-400"><?= e($log['entity_type']) ?> #<?= e($log['entity_id']) ?></td>
                                <td class="p-4 text-slate-500"><?= e($log['ip_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
