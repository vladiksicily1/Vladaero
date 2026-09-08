<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Управление экипажем и пользователями';
require_once __DIR__ . '/header.php';

// Handle Role/Ban Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $newRole = $_POST['role'] ?? 'user';
    $isBanned = isset($_POST['is_banned']) ? 1 : 0;
    $banReason = trim($_POST['ban_reason'] ?? '');
    $addXp = (int)($_POST['add_xp'] ?? 0);

    if ($targetUserId > 0) {
        DB::update('va_users', [
            'role'       => $newRole,
            'is_banned'  => $isBanned,
            'ban_reason' => $isBanned ? $banReason : null
        ], '`id` = :id', ['id' => $targetUserId]);

        if ($addXp > 0) {
            Auth::addXp($targetUserId, $addXp);
        }

        record_audit('admin_update_user', 'user', $targetUserId, ['role' => $newRole, 'banned' => $isBanned]);
    }

    header('Location: users.php');
    exit;
}

$users = DB::fetchAll("SELECT * FROM `va_users` ORDER BY `id` DESC");
?>

<div class="space-y-6 font-mono text-xs">
    
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans">Экипаж и пользователи портала</h1>
            <p class="text-xs text-slate-400 mt-1">Управление рангами, начисление XP, модерация и бан нарушителей</p>
        </div>
    </div>

    <div class="glass-card rounded-3xl border border-white/5 overflow-hidden shadow-2xl">
        <table class="w-full text-left text-xs font-mono">
            <thead class="bg-slate-900/90 text-slate-400 border-b border-white/10 uppercase text-[10px]">
                <tr>
                    <th class="p-4">Пользователь</th>
                    <th class="p-4">Email</th>
                    <th class="p-4">Роль</th>
                    <th class="p-4">Ранг</th>
                    <th class="p-4">Очки XP</th>
                    <th class="p-4">Статус</th>
                    <th class="p-4 text-right">Действия</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($users as $u): ?>
                    <tr class="hover:bg-sky-500/5 transition">
                        <td class="p-4 font-bold text-white"><?= e($u['username']) ?> (<?= e($u['full_name']) ?>)</td>
                        <td class="p-4 text-slate-400"><?= e($u['email']) ?></td>
                        <td class="p-4">
                            <span class="px-2 py-0.5 rounded bg-slate-800 text-sky-300 font-bold"><?= e($u['role']) ?></span>
                        </td>
                        <td class="p-4 text-amber-400 font-bold"><?= e($u['rank_title']) ?></td>
                        <td class="p-4 text-slate-200"><?= (int)$u['xp_points'] ?></td>
                        <td class="p-4">
                            <?php if ($u['is_banned']): ?>
                                <span class="px-2 py-0.5 rounded bg-rose-500/20 text-rose-400 font-bold">Забанен</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold">Активен</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4 text-right">
                            <button onclick="openUserEditModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') ?>)" class="text-sky-400 hover:underline">Управление</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- User Edit Modal -->
<div id="userModal" class="fixed inset-0 z-50 bg-black/75 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="max-w-md w-full glass-hud rounded-3xl p-6 border border-sky-500/30 shadow-2xl space-y-4 font-mono text-xs">
        <h3 class="text-base font-bold text-white">Редактирование профиля: <span id="modalUsername" class="text-sky-400"></span></h3>

        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" id="modalUserId">

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Роль доступа</label>
                <select name="role" id="modalRole" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                    <option value="user">Пользователь (User)</option>
                    <option value="spotter">Споттер (Spotter - мгновенная публикация)</option>
                    <option value="screener">Скринер фото (Screener)</option>
                    <option value="moderator">Модератор (Moderator)</option>
                    <option value="editor">Редактор (Editor)</option>
                    <option value="admin">Администратор (Admin)</option>
                </select>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Начислить XP</label>
                <input type="number" name="add_xp" value="0" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <div class="p-3 rounded-xl bg-slate-900/80 border border-white/5 space-y-2">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="is_banned" id="modalBanned" value="1" class="rounded bg-slate-800 text-rose-500">
                    <span class="text-rose-400 font-bold">Заблокировать аккаунт (Бан)</span>
                </label>
                <input type="text" name="ban_reason" id="modalBanReason" placeholder="Причина бана..." class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-1.5 text-white">
            </div>

            <div class="flex justify-end space-x-2 pt-2">
                <button type="button" onclick="closeUserEditModal()" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300">Отмена</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openUserEditModal(user) {
        document.getElementById('modalUserId').value = user.id;
        document.getElementById('modalUsername').innerText = user.username;
        document.getElementById('modalRole').value = user.role;
        document.getElementById('modalBanned').checked = user.is_banned == 1;
        document.getElementById('modalBanReason').value = user.ban_reason || '';
        document.getElementById('userModal').classList.remove('hidden');
    }
    function closeUserEditModal() {
        document.getElementById('userModal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
