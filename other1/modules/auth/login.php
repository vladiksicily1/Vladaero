<?php
if (!defined('VLADINC_INIT')) exit;

if (Auth::check()) {
    redirect('social');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    $res = Auth::login($login, $password, $remember);
    if ($res['success']) {
        flash_set('success', 'С возвращением в VladInc, ' . $res['user']['display_name'] . '!');
        redirect('social');
    } else {
        $error = $res['error'];
    }
}

$pageTitle = 'Вход через Vlad ID';
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

    <div class="card" style="max-width: 420px; width: 100%; padding: 36px;">
        <div style="text-align: center; margin-bottom: 28px;">
            <div class="brand-icon" style="margin: 0 auto 14px; width: 48px; height: 48px; font-size: 24px;">V</div>
            <h1 style="font-size: 22px; font-weight: 800; margin-bottom: 6px;">Вход через Vlad ID</h1>
            <p style="color: var(--text-muted); font-size: 13px;">Единая учетная запись в экосистеме <?= APP_DOMAIN ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="toast toast-error" style="position: static; margin-bottom: 20px; animation: none;">
                <span>❌</span> <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group" style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Логин или Email</label>
                <input type="text" name="login" class="form-control" placeholder="username или email" value="<?= e($_POST['login'] ?? '') ?>" required autofocus>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px;">Пароль</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; font-size: 13px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--text-secondary);">
                    <input type="checkbox" name="remember" checked>
                    <span>Запомнить меня</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 15px;">
                Войти в аккаунт
            </button>
        </form>

        <div style="text-align: center; margin-top: 24px; font-size: 13px; color: var(--text-secondary);">
            Нет аккаунта? <a href="<?= url('register') ?>" style="color: var(--accent-primary); font-weight: 700;">Создать Vlad ID</a>
        </div>
    </div>

</body>
</html>
