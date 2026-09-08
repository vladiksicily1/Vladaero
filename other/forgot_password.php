<?php
/**
 * ShibaLingo - Password Reset via Telegram
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/telegram.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';
$step = 'request'; // 'request', 'verify', 'completed'

$tokenParam = trim($_GET['token'] ?? '');
if (!empty($tokenParam)) {
    $step = 'verify';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_reset') {
        $login = trim($_POST['login'] ?? '');
        if (empty($login)) {
            $error = 'Пожалуйста, введите ваш логин или email.';
        } else {
            $db = getDb();
            $stmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE username = :u OR email = :u LIMIT 1");
            $stmt->execute(['u' => $login]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'Пользователь с таким логином или email не найден.';
            } elseif (empty($user['telegram_chat_id'])) {
                $error = 'К данному аккаунту не привязан Telegram бот. Для восстановления обратитесь к администратору или войдите по паролю.';
            } else {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $domain = $protocol . $_SERVER['HTTP_HOST'];
                $res = createPasswordResetRequest((int)$user['id'], $domain);

                if ($res['success']) {
                    $success = '6-значный код и ссылка для смены пароля отправлены в ваш Telegram бот!';
                    $step = 'verify';
                } else {
                    $error = 'Не удалось отправить сообщение в Telegram: ' . ($res['error'] ?? 'Ошибка API');
                }
            }
        }
    } elseif ($action === 'complete_reset') {
        $codeOrToken = trim($_POST['code_or_token'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($codeOrToken)) {
            $error = 'Пожалуйста, введите 6-значный код из Telegram!';
            $step = 'verify';
        } elseif (mb_strlen($newPassword) < 4) {
            $error = 'Пароль должен содержать минимум 4 символа!';
            $step = 'verify';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Пароли не совпадают!';
            $step = 'verify';
        } else {
            $res = completePasswordReset($codeOrToken, $newPassword);
            if ($res['success']) {
                $success = 'Пароль успешно изменен! Теперь вы можете войти с новым паролем.';
                $step = 'completed';
            } else {
                $error = $res['error'] ?? 'Неверный или устаревший код / ссылка сброса.';
                $step = 'verify';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сброс пароля через Telegram — ShibaLingo</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐕</text></svg>">
</head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-main); padding: 20px;">

<div class="card-duo anim-bounce" style="max-width: 440px; width: 100%; padding: 36px 28px; text-align: center;">
    <div style="font-size: 3.5rem; margin-bottom: 8px;">🔑 ✈️ 🐕</div>
    <h1 style="font-size: 1.7rem; font-weight: 900; color: var(--primary); margin-bottom: 6px;">
        Сброс пароля
    </h1>
    <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 22px;">
        Восстановление доступа через привязанный Telegram-бот
    </p>

    <?php if (!empty($error)): ?>
        <div style="background: var(--danger-light); color: var(--danger-shadow); padding: 12px; border-radius: 12px; font-weight: 700; margin-bottom: 18px; font-size: 0.9rem; text-align: left;">
            ✕ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success) && $step !== 'completed'): ?>
        <div style="background: var(--primary-light); color: var(--primary-shadow); padding: 12px; border-radius: 12px; font-weight: 700; margin-bottom: 18px; font-size: 0.9rem; text-align: left;">
            ✓ <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($step === 'completed'): ?>
        <div style="background: var(--primary-light); color: var(--primary-shadow); padding: 20px; border-radius: 16px; margin-bottom: 24px; text-align: center;">
            <div style="font-size: 2.5rem; margin-bottom: 8px;">🎉</div>
            <div style="font-weight: 900; font-size: 1.1rem; margin-bottom: 6px;">Пароль успешно обновлен!</div>
            <p style="font-size: 0.9rem; margin: 0; color: var(--text-muted);">
                Вы можете войти в аккаунт, используя ваш новый пароль.
            </p>
        </div>
        <a href="login.php" class="btn-duo btn-primary" style="display: block; width: 100%; padding: 14px; font-size: 1.05rem; text-decoration: none;">
            Войти в аккаунт 🐾
        </a>

    <?php elseif ($step === 'verify'): ?>
        <form method="POST" style="text-align: left;">
            <input type="hidden" name="action" value="complete_reset">

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">
                    6-значный код из Telegram (или токен):
                </label>
                <input type="text" name="code_or_token" value="<?= e($tokenParam) ?>" class="chat-input" placeholder="Например: 123456" required autofocus style="text-align: center; font-size: 1.3rem; font-weight: 800; letter-spacing: 2px;">
            </div>

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">
                    Новый пароль:
                </label>
                <input type="password" name="new_password" class="chat-input" placeholder="Минимум 4 символа" required>
            </div>

            <div style="margin-bottom: 24px;">
                <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">
                    Повторите новый пароль:
                </label>
                <input type="password" name="confirm_password" class="chat-input" placeholder="Повторите пароль" required>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%; font-size: 1.05rem; padding: 14px;">
                Сохранить новый пароль 🔒
            </button>
        </form>

        <div style="margin-top: 18px;">
            <a href="forgot_password.php" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none;">
                ← Запросить код повторно
            </a>
        </div>

    <?php else: ?>
        <form method="POST" style="text-align: left;">
            <input type="hidden" name="action" value="request_reset">

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">
                    Ваш логин или Email на ShibaLingo:
                </label>
                <input type="text" name="login" class="chat-input" placeholder="Логин или email" required autofocus>
                <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 6px;">
                    Бот отправит одноразовый код подтверждения в привязанный чат Telegram.
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%; font-size: 1.05rem; padding: 14px;">
                Отправить код в Telegram ✈️
            </button>
        </form>

        <div style="margin-top: 18px;">
            <a href="login.php" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none;">
                ← Вернуться ко входу
            </a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
