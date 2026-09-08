<?php
/**
 * Admin Users Management (Full RBAC & Profile Booster)
 */

$adminTitle = 'Управление пользователями';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';

// Handle Create / Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $roleId = (int)$_POST['role_id'];
        $xp = (int)$_POST['xp'];
        $streak = (int)$_POST['streak'];
        $hearts = (int)$_POST['hearts'];
        $status = $_POST['status'] ?? 'active';
        $newPass = $_POST['password'] ?? '';

        if ($uid > 0) {
            // Update
            if (!empty($newPass)) {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET username = :u, email = :e, role_id = :r, xp = :xp, streak = :s, hearts = :h, status = :st, password_hash = :p WHERE id = :id");
                $stmt->execute(['u' => $username, 'e' => $email, 'r' => $roleId, 'xp' => $xp, 's' => $streak, 'h' => $hearts, 'st' => $status, 'p' => $hash, 'id' => $uid]);
            } else {
                $stmt = $db->prepare("UPDATE users SET username = :u, email = :e, role_id = :r, xp = :xp, streak = :s, hearts = :h, status = :st WHERE id = :id");
                $stmt->execute(['u' => $username, 'e' => $email, 'r' => $roleId, 'xp' => $xp, 's' => $streak, 'h' => $hearts, 'st' => $status, 'id' => $uid]);
            }
            $message = 'Данные пользователя успешно обновлены!';
        } else {
            // Create
            $hash = password_hash(!empty($newPass) ? $newPass : 'student123', PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role_id, xp, streak, hearts, status) VALUES (:u, :e, :p, :r, :xp, :s, :h, :st)");
            $stmt->execute(['u' => $username, 'e' => $email, 'p' => $hash, 'r' => $roleId, 'xp' => $xp, 's' => $streak, 'h' => $hearts, 'st' => $status]);
            $message = 'Новый пользователь успешно создан!';
        }
    }

    if ($act === 'delete_user') {
        $delId = (int)$_POST['delete_id'];
        if ($delId !== $admin['id']) {
            $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $delId]);
            $message = 'Пользователь удален.';
        } else {
            $message = 'Вы не можете удалить свою собственную учетную запись!';
        }
    }
}

// Fetch all roles
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

// Fetch all users
$search = trim($_GET['search'] ?? '');
if (!empty($search)) {
    $uStmt = $db->prepare("SELECT u.*, r.name as role_name, r.slug as role_slug 
                           FROM users u 
                           LEFT JOIN roles r ON u.role_id = r.id 
                           WHERE u.username LIKE :q OR u.email LIKE :q 
                           ORDER BY u.id DESC");
    $uStmt->execute(['q' => "%$search%"]);
} else {
    $uStmt = $db->query("SELECT u.*, r.name as role_name, r.slug as role_slug 
                         FROM users u 
                         LEFT JOIN roles r ON u.role_id = r.id 
                         ORDER BY u.id DESC");
}
$users = $uStmt->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">👥 Управление пользователями и RBAC</h1>
        <p style="color: var(--text-muted);">Создание, редактирование ролей, прокачка XP и блокировка</p>
    </div>

    <button class="btn-duo btn-primary" onclick="openUserModal()">
        + Добавить пользователя
    </button>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<div class="card-duo">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3 style="font-weight: 800; font-size: 1.15rem;">Список пользователей (Всего: <?= count($users) ?>)</h3>
        <form method="GET" style="display: flex; gap: 8px;">
            <input type="text" name="search" class="chat-input" placeholder="Поиск по имени/email..." value="<?= e($search) ?>" style="margin-bottom: 0; padding: 8px 14px;">
            <button type="submit" class="btn-duo btn-outline" style="padding: 8px 14px;">Найти</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="dict-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Имя пользователя</th>
                    <th>Email</th>
                    <th>Роль</th>
                    <th>XP / Стрик / Жизни</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>#<?= $u['id'] ?></td>
                        <td style="font-weight: 800; font-size: 1.05rem;"><?= e($u['username']) ?></td>
                        <td style="color: var(--text-muted);"><?= e($u['email']) ?></td>
                        <td>
                            <span class="badge-tag" style="background: var(--secondary); color: white;">
                                <?= e($u['role_name'] ?? 'Student') ?>
                            </span>
                        </td>
                        <td style="font-weight: 700;">
                            <span style="color: #eab308;">⚡ <?= (int)$u['xp'] ?></span> | 
                            <span style="color: var(--streak-color);">🔥 <?= (int)$u['streak'] ?></span> | 
                            <span style="color: var(--danger);">❤️ <?= (int)$u['hearts'] ?></span>
                        </td>
                        <td>
                            <span class="badge-tag" style="background: <?= ($u['status'] === 'active') ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;' ?>">
                                <?= e($u['status']) ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.8rem;" onclick='editUser(<?= json_encode($u) ?>)'>
                                ✏️ Изменить
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: User Editor -->
<div id="modal-user-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 550px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-user-title" style="font-size: 1.3rem; font-weight: 800;">Редактирование пользователя</h3>
            <button onclick="closeUserModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_user">
            <input type="hidden" name="user_id" id="edit-user-id" value="0">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Имя пользователя:</label>
                    <input type="text" name="username" id="edit-username" required class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Email:</label>
                    <input type="email" name="email" id="edit-email" required class="chat-input">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Роль в системе (RBAC):</label>
                    <select name="role_id" id="edit-role-id" class="chat-input">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= e($r['name']) ?> (<?= e($r['slug']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Статус аккаунта:</label>
                    <select name="status" id="edit-status" class="chat-input">
                        <option value="active">🟢 Активен (Active)</option>
                        <option value="banned">🔴 Заблокирован (Banned)</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">XP Очки:</label>
                    <input type="number" name="xp" id="edit-xp" value="0" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Стрик (дней):</label>
                    <input type="number" name="streak" id="edit-streak" value="1" class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Жизни (Сердечки):</label>
                    <input type="number" name="hearts" id="edit-hearts" value="5" class="chat-input">
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Новый пароль (оставьте пустым, если не меняется):</label>
                <input type="password" name="password" class="chat-input" placeholder="••••••••">
            </div>

            <div style="display: flex; gap: 12px;">
                <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                    Сохранить пользователя 💾
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openUserModal() {
    document.getElementById('modal-user-title').textContent = 'Создание нового пользователя';
    document.getElementById('edit-user-id').value = '0';
    document.getElementById('edit-username').value = '';
    document.getElementById('edit-email').value = '';
    document.getElementById('edit-xp').value = '100';
    document.getElementById('edit-streak').value = '1';
    document.getElementById('edit-hearts').value = '5';
    document.getElementById('modal-user-edit').style.display = 'flex';
}

function editUser(user) {
    document.getElementById('modal-user-title').textContent = `Редактирование: ${user.username}`;
    document.getElementById('edit-user-id').value = user.id;
    document.getElementById('edit-username').value = user.username;
    document.getElementById('edit-email').value = user.email;
    document.getElementById('edit-role-id').value = user.role_id || '4';
    document.getElementById('edit-status').value = user.status || 'active';
    document.getElementById('edit-xp').value = user.xp || '0';
    document.getElementById('edit-streak').value = user.streak || '1';
    document.getElementById('edit-hearts').value = user.hearts || '5';
    document.getElementById('modal-user-edit').style.display = 'flex';
}

function closeUserModal() {
    document.getElementById('modal-user-edit').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
