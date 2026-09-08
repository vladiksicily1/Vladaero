<?php
/**
 * Admin Roles & Permissions (RBAC) Manager
 */

$adminTitle = 'Роли и Права (RBAC)';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_users')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';

$allPermissions = [
    '*' => 'Полный доступ (Суперадмин)',
    'manage_lessons' => 'Управление уроками и навыками (CRUD)',
    'manage_conlang' => 'Управление словарем и грамматикой Conlang',
    'manage_users' => 'Управление пользователями и ролями',
    'manage_languages' => 'Добавление и настройка языков',
    'view_analytics' => 'Просмотр аналитики и логов',
    'access_maintenance' => 'Доступ к сайту во время техобслуживания',
    'study' => 'Доступ к прохождению уроков',
    'chat' => 'Доступ к AI-чату с Сибой'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_role') {
        $rid = (int)($_POST['role_id'] ?? 0);
        $slug = trim($_POST['slug']);
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $perms = json_encode($_POST['perms'] ?? []);

        if ($rid > 0) {
            $stmt = $db->prepare("UPDATE " . tbl('roles') . " SET slug = :s, name = :n, description = :d, permissions = :p WHERE id = :id");
            $stmt->execute(['s' => $slug, 'n' => $name, 'd' => $desc, 'p' => $perms, 'id' => $rid]);
            $message = 'Роль успешно обновлена!';
        } else {
            $stmt = $db->prepare("INSERT INTO " . tbl('roles') . " (slug, name, description, permissions) VALUES (:s, :n, :d, :p)");
            $stmt->execute(['s' => $slug, 'n' => $name, 'd' => $desc, 'p' => $perms]);
            $message = 'Новая роль успешно создана!';
        }
    }

    if ($act === 'delete_role') {
        $rid = (int)$_POST['delete_id'];
        if ($rid === 1 || $rid === 4) {
            $message = 'Ошибка: Системные роли (SuperAdmin, Student) нельзя удалять!';
        } else {
            $db->prepare("DELETE FROM " . tbl('roles') . " WHERE id = :id")->execute(['id' => $rid]);
            $message = 'Роль успешно удалена.';
        }
    }
}

$roles = $db->query("SELECT * FROM " . tbl('roles') . " ORDER BY id ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">🛡️ Роли и Разрешения (RBAC)</h1>
        <p style="color: var(--text-muted);">Настройка гранулярных прав доступа для каждой группы пользователей</p>
    </div>

    <button class="btn-duo btn-primary" onclick="openRoleModal()">
        + Создать роль
    </button>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
    <?php foreach ($roles as $r): 
        $permsList = json_decode($r['permissions'] ?? '[]', true) ?: [];
    ?>
        <div class="card-duo" style="margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                <div>
                    <h3 style="font-size: 1.2rem; font-weight: 800; color: var(--secondary);"><?= e($r['name']) ?></h3>
                    <code style="font-size: 0.85rem; color: var(--text-muted);"><?= e($r['slug']) ?></code>
                </div>
                <button class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.8rem;" onclick='editRole(<?= json_encode($r) ?>)'>
                    ✏️ Настроить
                </button>
            </div>

            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 16px;">
                <?= e($r['description'] ?: 'Нет описания') ?>
            </p>

            <div style="border-top: 1px solid var(--border-color); padding-top: 12px;">
                <div style="font-weight: 800; font-size: 0.85rem; margin-bottom: 8px; text-transform: uppercase; color: var(--text-muted);">Разрешения:</div>
                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                    <?php if (in_array('*', $permsList)): ?>
                        <span class="badge-tag" style="background: #fef08a; color: #854d0e; font-weight: 900;">⭐ Полный доступ (*)</span>
                    <?php else: ?>
                        <?php foreach ($permsList as $p): ?>
                            <span class="badge-tag"><?= e($allPermissions[$p] ?? $p) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal: Role Editor -->
<div id="modal-role-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 600px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-role-title" style="font-size: 1.3rem; font-weight: 800;">Редактирование роли</h3>
            <button onclick="closeRoleModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_role">
            <input type="hidden" name="role_id" id="edit-role-id" value="0">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Название роли:</label>
                    <input type="text" name="name" id="edit-role-name" required class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Системный Slug:</label>
                    <input type="text" name="slug" id="edit-role-slug" required class="chat-input" placeholder="moderator">
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Описание роли:</label>
                <input type="text" name="description" id="edit-role-desc" class="chat-input">
            </div>

            <div style="margin-bottom: 24px;">
                <label style="font-weight: 800; font-size: 0.9rem; margin-bottom: 10px; display: block;">Права и разрешения:</label>
                <div style="display: flex; flex-direction: column; gap: 8px; max-height: 220px; overflow-y: auto; background: var(--bg-main); padding: 12px; border-radius: 12px; border: 2px solid var(--border-color);">
                    <?php foreach ($allPermissions as $pKey => $pLabel): ?>
                        <label style="font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="perms[]" value="<?= $pKey ?>" class="perm-checkbox" id="perm-<?= $pKey ?>">
                            <span><?= e($pLabel) ?> <code>(<?= $pKey ?>)</code></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить роль 💾
            </button>
        </form>
    </div>
</div>

<script>
function openRoleModal() {
    document.getElementById('modal-role-title').textContent = 'Создание новой роли';
    document.getElementById('edit-role-id').value = '0';
    document.getElementById('edit-role-name').value = '';
    document.getElementById('edit-role-slug').value = '';
    document.getElementById('edit-role-desc').value = '';
    document.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('modal-role-edit').style.display = 'flex';
}

function editRole(role) {
    document.getElementById('modal-role-title').textContent = `Редактирование: ${role.name}`;
    document.getElementById('edit-role-id').value = role.id;
    document.getElementById('edit-role-name').value = role.name;
    document.getElementById('edit-role-slug').value = role.slug;
    document.getElementById('edit-role-desc').value = role.description || '';
    
    const perms = JSON.parse(role.permissions || '[]');
    document.querySelectorAll('.perm-checkbox').forEach(cb => {
        cb.checked = perms.includes(cb.value);
    });

    document.getElementById('modal-role-edit').style.display = 'flex';
}

function closeRoleModal() {
    document.getElementById('modal-role-edit').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
