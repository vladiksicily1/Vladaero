<?php
/**
 * ShibaLingo - User Login Page with Telegram 2FA Support
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/telegram.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$step2fa = false;

// Check if currently waiting for 2FA verification
$pendingUserId = $_SESSION['2fa_pending_user_id'] ?? null;
if ($pendingUserId) {
    $step2fa = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'login') {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($login) || empty($password)) {
            $error = 'Пожалуйста, заполните все поля!';
        } else {
            $db = getDb();
            $stmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE (username = :u OR email = :u) AND status = 'active'");
            $stmt->execute(['u' => $login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Check if Two-Factor Authentication is enabled
                if (!empty($user['two_factor_enabled']) && !empty($user['telegram_chat_id'])) {
                    $sendRes = generateAndSend2FACode((int)$user['id']);
                    if ($sendRes['success']) {
                        $_SESSION['2fa_pending_user_id'] = $user['id'];
                        $step2fa = true;
                        $success = '6-значный код двухфакторной аутентификации отправлен в ваш Telegram!';
                    } else {
                        // Fallback if telegram send failed
                        $error = 'Ошибка отправки 2FA в Telegram: ' . ($sendRes['error'] ?? 'Неизвестная ошибка');
                    }
                } else {
                    // Standard Login
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['current_language'] = $user['current_language'] ?? 'vladikish';

                    // Security alert if user has telegram linked
                    if (!empty($user['telegram_chat_id'])) {
                        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Неизвестно';
                        sendTelegramNotificationToUser((int)$user['id'], 'security',
                            "🔔 <b>Вход в аккаунт ShibaLingo</b>\n\n" .
                            "Выполнен вход в аккаунт <b>{$user['username']}</b>.\n" .
                            "IP: <code>{$clientIp}</code>\n" .
                            "Время: " . date('d.m.Y H:i')
                        );
                    }

                    header("Location: index.php");
                    exit;
                }
            } else {
                $error = 'Неверное имя пользователя или пароль!';
            }
        }
    } elseif ($action === 'verify_2fa') {
        $code = trim($_POST['two_factor_code'] ?? '');
        if (!$pendingUserId) {
            $error = 'Сессия 2FA истекла. Пожалуйста, войдите снова.';
            $step2fa = false;
        } elseif (empty($code)) {
            $error = 'Пожалуйста, введите 6-значный код из Telegram!';
            $step2fa = true;
        } else {
            $isValid = verify2FACode((int)$pendingUserId, $code);
            if ($isValid) {
                $db = getDb();
                $stmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE id = :id");
                $stmt->execute(['id' => $pendingUserId]);
                $user = $stmt->fetch();

                unset($_SESSION['2fa_pending_user_id']);

                if ($user) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['current_language'] = $user['current_language'] ?? 'vladikish';

                    // Security alert
                    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Неизвестно';
                    sendTelegramNotificationToUser((int)$user['id'], 'security',
                        "🛡️ <b>Успешный 2FA вход в аккаунт</b>\n\n" .
                        "IP адрес: <code>{$clientIp}</code>\n" .
                        "Время: " . date('d.m.Y H:i')
                    );

                    header("Location: index.php");
                    exit;
                } else {
                    $error = 'Пользователь не найден.';
                }
            } else {
                $error = 'Неверный или истекший код 2FA! Проверьте сообщение в Telegram.';
                $step2fa = true;
            }
        }
    } elseif ($action === 'resend_2fa') {
        if ($pendingUserId) {
            $sendRes = generateAndSend2FACode((int)$pendingUserId);
            if ($sendRes['success']) {
                $success = 'Новый код 2FA успешно отправлен в Telegram!';
            } else {
                $error = 'Ошибка отправки: ' . ($sendRes['error'] ?? 'Повторите позже');
            }
            $step2fa = true;
        }
    } elseif ($action === 'cancel_2fa') {
        unset($_SESSION['2fa_pending_user_id']);
        $step2fa = false;
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в аккаунт — ShibaLingo</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐕</text></svg>">
</head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-main); padding: 20px;">

<div class="card-duo anim-bounce" style="max-width: 440px; width: 100%; padding: 40px 32px; text-align: center;">
    <div style="font-size: 3.5rem; margin-bottom: 8px;">🐕 🎓</div>
    <h1 style="font-size: 1.8rem; font-weight: 900; color: var(--primary); margin-bottom: 6px;">
        <?= $step2fa ? 'Подтверждение 2FA' : 'Вход в ShibaLingo' ?>
    </h1>
    <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 24px;">
        <?= $step2fa ? 'Введите 6-значный код подтверждения из Telegram-бота' : 'Продолжайте изучать языки и тайный язык Vladikish!' ?>
    </p>

    <?php if (!empty($error)): ?>
        <div style="background: var(--danger-light); color: var(--danger-shadow); padding: 12px; border-radius: 12px; font-weight: 700; margin-bottom: 18px; font-size: 0.9rem; text-align: left;">
            ✕ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div style="background: var(--primary-light); color: var(--primary-shadow); padding: 12px; border-radius: 12px; font-weight: 700; margin-bottom: 18px; font-size: 0.9rem; text-align: left;">
            ✓ <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($step2fa): ?>
        <!-- 2FA Form -->
        <form method="POST" style="text-align: left;">
            <input type="hidden" name="action" value="verify_2fa">

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 8px; text-align: center;">
                    Одноразовый код 2FA:
                </label>
                <input type="text" name="two_factor_code" class="chat-input" placeholder="••••••" maxlength="10" required autofocus style="text-align: center; font-size: 1.6rem; font-weight: 900; letter-spacing: 4px;">
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%; font-size: 1.1rem; padding: 14px; margin-bottom: 12px;">
                Подтвердить вход 🛡️
            </button>
        </form>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 0.85rem;">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="resend_2fa">
                <button type="submit" style="background: none; border: none; color: var(--secondary); font-weight: 700; cursor: pointer; padding: 0;">
                    🔄 Отправить код повторно
                </button>
            </form>

            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="cancel_2fa">
                <button type="submit" style="background: none; border: none; color: var(--text-muted); font-weight: 600; cursor: pointer; padding: 0;">
                    ← Отмена
                </button>
            </form>
        </div>

    <?php else: ?>
        <!-- Standard Login Form -->
        <form method="POST" style="text-align: left;">
            <input type="hidden" name="action" value="login">

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">Имя пользователя или Email:</label>
                <input type="text" name="login" class="chat-input" placeholder="Ваш логин или email" required autofocus>
            </div>

            <div style="margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; font-size: 0.9rem; margin: 0;">Пароль:</label>
                    <a href="forgot_password.php" style="font-size: 0.85rem; color: var(--secondary); font-weight: 700; text-decoration: none;">
                        Забыли пароль? 🔑
                    </a>
                </div>
                <input type="password" name="password" class="chat-input" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%; font-size: 1.1rem; padding: 14px; margin-top: 12px;">
                Войти в аккаунт 🐾
            </button>
        </form>

        <div style="margin-top: 24px; padding-top: 18px; border-top: 2px solid var(--border-color); display: flex; justify-content: space-between; font-size: 0.9rem;">
            <span style="color: var(--text-muted);">Нет аккаунта?</span>
            <a href="register.php" style="color: var(--secondary); font-weight: 800; text-decoration: none;">
                Зарегистрироваться 🚀
            </a>
        </div>

        <div style="margin-top: 12px;">
            <a href="index.php" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none;">
                ← На главную
            </a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
