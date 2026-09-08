<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAuth();
$pageTitle = 'Vlad ID — Единый аккаунт и безопасность';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        flash_set('error', 'Ошибка CSRF');
        redirect('id');
    }

    $actionType = $_POST['action_type'] ?? '';

    // 1. Profile details
    if ($actionType === 'update_profile') {
        $displayName = trim($_POST['display_name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $statusText = trim($_POST['status_text'] ?? '');

        $upd = [
            'display_name' => $displayName ?: $currentUser['username'],
            'bio' => $bio,
            'status_text' => $statusText
        ];

        // Avatar upload
        if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $avatarDir = UPLOADS_PATH . '/avatars';
                if (!is_dir($avatarDir)) @mkdir($avatarDir, 0755, true);
                $avatarName = 'avatar_' . $currentUser['id'] . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $avatarDir . '/' . $avatarName)) {
                    $upd['avatar'] = 'uploads/avatars/' . $avatarName;
                }
            }
        }

        DB::update('users', $upd, 'id = :id', ['id' => $currentUser['id']]);
        flash_set('success', 'Профиль Vlad ID успешно обновлен!');
        redirect('id');
    }

    // 2. Change password
    if ($actionType === 'change_password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (!password_verify($oldPass, $currentUser['password_hash'])) {
            flash_set('error', 'Текущий пароль указан неверно');
        } elseif (mb_strlen($newPass) < 6) {
            flash_set('error', 'Новый пароль должен содержать минимум 6 символов');
        } elseif ($newPass !== $confirmPass) {
            flash_set('error', 'Новые пароли не совпадают');
        } else {
            DB::update('users', ['password_hash' => password_hash($newPass, PASSWORD_BCRYPT)], 'id = :id', ['id' => $currentUser['id']]);
            flash_set('success', 'Пароль успешно изменен!');
        }
        redirect('id');
    }

    // 3. Regenerate API Token
    if ($actionType === 'regen_token') {
        $newToken = bin2hex(random_bytes(32));
        DB::update('users', ['api_token' => $newToken], 'id = :id', ['id' => $currentUser['id']]);
        flash_set('success', 'Новый API Token сгенерирован!');
        redirect('id');
    }
}

// Active user sessions
$sessions = DB::fetchAll("SELECT * FROM user_sessions WHERE user_id = ? ORDER BY id DESC", [$currentUser['id']]);

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <!-- VLAD ID HERO CARD -->
    <div class="card" style="background: linear-gradient(135deg, #1e1b4b, #0f172a); border-color: #6366f1; padding: 32px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;">
        <div style="display: flex; align-items: center; gap: 20px;">
            <img src="<?= e(url($currentUser['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #6366f1;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($currentUser['display_name']) ?>&background=3b82f6&color=fff'">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h2 style="font-size: 24px; font-weight: 800;"><?= e($currentUser['display_name']) ?></h2>
                    <span class="level-badge" style="font-size: 12px; padding: 2px 8px;">LVL <?= $currentUser['level'] ?></span>
                </div>
                <div style="color: var(--text-muted); font-size: 14px; margin-bottom: 6px;">@<?= e($currentUser['username']) ?> &bull; <?= e($currentUser['email']) ?></div>
                <div style="font-size: 13px; color: #a5b4fc;"><?= e($currentUser['status_text']) ?></div>
            </div>
        </div>

        <div>
            <span style="background: rgba(99, 102, 241, 0.2); border: 1px solid #6366f1; color: #c7d2fe; padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 700;">
                🆔 Vlad ID: #<?= $currentUser['id'] ?>
            </span>
        </div>
    </div>

    <!-- PROFILE SETTINGS FORM -->
    <div class="card">
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px;">Данные профиля</h3>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action_type" value="update_profile">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Отображаемое имя</label>
                    <input type="text" name="display_name" class="form-control" value="<?= e($currentUser['display_name']) ?>" required>
                </div>
                <div>
                    <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Статус / Подпись</label>
                    <input type="text" name="status_text" class="form-control" value="<?= e($currentUser['status_text']) ?>">
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">О себе (Био)</label>
                <textarea name="bio" class="form-control" rows="3" placeholder="Расскажите об интересах в экосистеме..."><?= e($currentUser['bio']) ?></textarea>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Сменить аватар (JPG, PNG, WEBP)</label>
                <input type="file" name="avatar" class="form-control" accept="image/*">
            </div>

            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
        </form>
    </div>

    <!-- SECURITY & PASSWORD -->
    <div class="card">
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px;">Безопасность и смена пароля</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action_type" value="change_password">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Текущий пароль</label>
                <input type="password" name="old_password" class="form-control" required placeholder="••••••••">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                <div>
                    <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Новый пароль (мин. 6 симв.)</label>
                    <input type="password" name="new_password" class="form-control" required placeholder="••••••••">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Подтверждение нового пароля</label>
                    <input type="password" name="confirm_password" class="form-control" required placeholder="••••••••">
                </div>
            </div>

            <button type="submit" class="btn btn-secondary">Обновить пароль</button>
        </form>
    </div>

    <!-- DEVELOPER API TOKEN -->
    <div class="card">
        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 8px;">🔑 API Token разработчика</h3>
        <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 16px;">
            Используется для интеграции будущих сторонних сервисов и ботов с вашим профилем Vlad ID.
        </p>

        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 16px;">
            <input type="text" class="form-control" value="<?= e($currentUser['api_token']) ?>" readonly style="font-family: monospace;">
            <button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText('<?= e($currentUser['api_token']) ?>'); showToast('Токен скопирован!', 'success');">Копировать</button>
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action_type" value="regen_token">
            <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Сгенерировать новый токен?')">Сгенерировать новый токен</button>
        </form>
    </div>

</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
