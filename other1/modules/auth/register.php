<?php
if (!defined('VLADINC_INIT')) exit;

if (Auth::check()) {
    redirect('social');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $displayName = trim($_POST['display_name'] ?? '');

    $res = Auth::register($username, $email, $password, $displayName);
    if ($res['success']) {
        flash_set('success', 'Добро пожаловать в VladInc! Вам начислен приветственный бонус +150 VladCoins 🪙');
        redirect('social');
    } else {
        $error = $res['error'];
    }
}

$pageTitle = 'Регистрация Vlad ID';
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= asset('css/vladinc.css') ?>?v=<?= APP_VERSION ?>">
</head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px;">

    <div class="card" style="max-width: 440px; width: 100%; padding: 36px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="brand-icon" style="margin: 0 auto 14px; width: 48px; height: 48px; font-size: 24px;">V</div>
            <h1 style="font-size: 22px; font-weight: 800; margin-bottom: 6px;">Регистрация Vlad ID</h1>
            <p style="color: var(--text-muted); font-size: 13px;">Получите доступ ко всем сервисам экосистемы</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="toast toast-error" style="position: static; margin-bottom: 20px; animation: none;">
                <span>❌</span> <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group" style="margin-bottom: 14px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Логин (@username, только латиница)</label>
                <input type="text" name="username" class="form-control" placeholder="alex_dev" value="<?= e($_POST['username'] ?? '') ?>" required autofocus pattern="[a-zA-Z0-9_]{3,30}">
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Ваше имя</label>
                <input type="text" name="display_name" class="form-control" placeholder="Алексей" value="<?= e($_POST['display_name'] ?? '') ?>" required>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Email адрес</label>
                <input type="email" name="email" class="form-control" placeholder="alex@mail.ru" value="<?= e($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Пароль (мин. 6 символов)</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" minlength="6" required>
            </div>

            <div style="background: rgba(245, 158, 11, 0.1); border-left: 3px solid #f59e0b; padding: 10px 14px; border-radius: 6px; font-size: 12px; color: #fbbf24; margin-bottom: 20px;">
                🎁 При регистрации вы получаете <strong>150 VladCoins</strong> на ваш кошелек!
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 15px;">
                Зарегистрироваться
            </button>
        </form>

        <div style="text-align: center; margin-top: 24px; font-size: 13px; color: var(--text-secondary);">
            Уже есть Vlad ID? <a href="<?= url('login') ?>" style="color: var(--accent-primary); font-weight: 700;">Войти</a>
        </div>
    </div>

</body>
</html>
