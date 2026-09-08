<?php
$adminTitle = 'Журнал Аудита Безопасности';
require_once __DIR__ . '/header.php';

$audTable = Database::tableName('audit_logs');
$uTable = Database::tableName('users');

// Handle Clear Audit Log POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_audit'])) {
    Database::query("TRUNCATE TABLE `{$audTable}`");
    setFlash('success', 'Журнал аудита успешно очищен.');
    header('Location: ' . url('/admin/audit.php'));
    exit;
}

$logs = Database::fetchAll("SELECT a.*, u.username FROM `{$audTable}` a LEFT JOIN `{$uTable}` u ON a.user_id = u.id ORDER BY a.id DESC LIMIT 100");
?>

<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Журнал Аудита Безопасности</h1>
            <p class="text-xs text-slate-400 font-mono">Фиксация административных действий, авторизаций и изменений данных</p>
        </div>

        <form method="POST" onsubmit="return confirm('Очистить весь журнал аудита?');">
            <input type="hidden" name="clear_audit" value="1">
            <button type="submit" class="bg-red-600/80 hover:bg-red-600 text-white font-mono font-bold text-xs px-4 py-2.5 rounded-xl transition flex items-center space-x-1.5">
                <i data-lucide="trash-2" class="w-4 h-4"></i>
                <span>Очистить журнал</span>
            </button>
        </form>
    </div>

    <!-- Table -->
    <div class="va-card overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Время (UTC)</th>
                        <th class="p-4">Пользователь</th>
                        <th class="p-4">Действие</th>
                        <th class="p-4">Сущность</th>
                        <th class="p-4">IP Адрес</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="p-8 text-center text-slate-500">Журнал аудита пуст.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr class="hover:bg-slate-900/60 transition">
                                <td class="p-4 text-slate-500">#<?= $l['id'] ?></td>
                                <td class="p-4 text-slate-400"><?= formatDate($l['created_at'], true) ?></td>
                                <td class="p-4 font-bold text-sky-400"><?= e($l['username'] ?: 'Аноним') ?></td>
                                <td class="p-4 text-slate-200"><?= e($l['action']) ?></td>
                                <td class="p-4 text-slate-400"><?= e($l['entity_type']) ?> <?= $l['entity_id'] ? '#' . $l['entity_id'] : '' ?></td>
                                <td class="p-4 text-slate-500"><?= e($l['ip_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
