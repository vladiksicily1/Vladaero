<?php
$adminTitle = 'Управление Экипажем и Пользователями';
require_once __DIR__ . '/header.php';

$uTable = Database::tableName('users');

// Handle Role/XP Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $uid = (int)$_POST['user_id'];
    $newRole = trim($_POST['role']);
    $newXp = (int)$_POST['xp_points'];
    $rank = Auth::calculateRankTitle($newXp);

    Database::update('users', [
        'role' => $newRole,
        'xp_points' => $newXp,
        'rank_title' => $rank
    ], 'id = :id', ['id' => $uid]);

    setFlash('success', "Пользователь #{$uid} успешно обновлен!");
    header('Location: ' . url('/admin/users.php'));
    exit;
}

$users = Database::fetchAll("SELECT * FROM `{$uTable}` WHERE deleted_at IS NULL ORDER BY id DESC");
?>

<div class="space-y-6">

    <div>
        <h1 class="text-2xl font-bold text-white">Управление Пользователями и Экипажем</h1>
        <p class="text-xs text-slate-400 font-mono">Назначение ролей, корректировка очков опыта XP и рангов</p>
    </div>

    <!-- Table -->
    <div class="va-card overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Логин</th>
                        <th class="p-4">Email</th>
                        <th class="p-4">Роль</th>
                        <th class="p-4">Ранг</th>
                        <th class="p-4">XP Очки</th>
                        <th class="p-4 text-right">Управление</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-slate-900/60 transition">
                            <td class="p-4 text-slate-500">#<?= $u['id'] ?></td>
                            <td class="p-4 font-bold text-white"><?= e($u['username']) ?></td>
                            <td class="p-4 text-slate-400"><?= e($u['email']) ?></td>
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= ($u['role'] === 'admin') ? 'bg-amber-950 text-amber-400 border border-amber-800' : 'bg-slate-950 text-slate-300 border border-slate-800' ?>">
                                    <?= e($u['role']) ?>
                                </span>
                            </td>
                            <td class="p-4 text-sky-400 font-bold"><?= e($u['rank_title']) ?></td>
                            <td class="p-4 text-emerald-400 font-bold"><?= formatNumber($u['xp_points']) ?> XP</td>
                            <td class="p-4 text-right">
                                <button onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)" class="text-sky-400 hover:text-white font-bold">
                                    Изменить
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal -->
<div id="user-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl p-6 relative">
        <button onclick="document.getElementById('user-modal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-white">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <h2 class="text-lg font-bold text-white mb-4">Редактирование Пользователя</h2>

        <form method="POST" class="space-y-4 font-mono text-xs">
            <input type="hidden" name="update_user" value="1">
            <input type="hidden" name="user_id" id="edit-uid">

            <div>
                <label class="block text-slate-400 mb-1">Пользователь:</label>
                <div id="edit-uname" class="p-2.5 bg-slate-950 rounded-xl border border-slate-800 font-bold text-white"></div>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Роль в системе:</label>
                <select name="role" id="edit-role" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                    <option value="user">Пользователь (User)</option>
                    <option value="moderator">Модератор споттинга (Moderator)</option>
                    <option value="editor">Редактор статей (Editor)</option>
                    <option value="admin">Главный Администратор (Admin)</option>
                </select>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Очки опыта (XP):</label>
                <input type="number" name="xp_points" id="edit-xp" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-emerald-400 font-bold">
            </div>

            <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl shadow-lg transition">
                Применить изменения
            </button>
        </form>
    </div>
</div>

<script>
    function editUser(u) {
        document.getElementById('edit-uid').value = u.id;
        document.getElementById('edit-uname').innerText = u.username + ' (' + u.email + ')';
        document.getElementById('edit-role').value = u.role;
        document.getElementById('edit-xp').value = u.xp_points;
        document.getElementById('user-modal').classList.remove('hidden');
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
