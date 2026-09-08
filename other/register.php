<?php
/**
 * ShibaLingo - User Registration Page
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (empty($username) || empty($email) || empty($password)) {
        $error = 'Пожалуйста, заполните все обязательные поля!';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Пароли не совпадают!';
    } elseif (strlen($password) < 4) {
        $error = 'Пароль должен быть не менее 4 символов!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Укажите корректный адрес электронной почты!';
    } else {
        $db = getDb();
        
        // Check uniqueness
        $check = $db->prepare("SELECT id FROM " . tbl('users') . " WHERE username = :u OR email = :e");
        $check->execute(['u' => $username, 'e' => $email]);
        if ($check->fetch()) {
            $error = 'Пользователь с таким логином или Email уже существует!';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO " . tbl('users') . " (username, email, password_hash, role_id, xp, streak, hearts, gems, status, current_language) 
                                  VALUES (:u, :e, :p, 4, 0, 1, 5, 50, 'active', 'vladikish')");
            $stmt->execute([
                'u' => $username,
                'e' => $email,
                'p' => $hash
            ]);

            $newId = $db->lastInsertId();
            $_SESSION['user_id'] = $newId;
            $_SESSION['username'] = $username;
            $_SESSION['current_language'] = 'vladikish';

            // Check & Process Referral Bonus
            $refCode = trim($_POST['ref_code'] ?? ($_GET['ref'] ?? ($_SESSION['pending_ref'] ?? '')));
            if (!empty($refCode)) {
                if (preg_match('/VLAD(\d+)REF/i', $refCode, $matches)) {
                    $referrerId = (int)$matches[1];
                    if ($referrerId > 0 && $referrerId != $newId) {
                        try {
                            $driver = Database::getDriver();
                            if ($driver === 'sqlite') {
                                $db->exec("CREATE TABLE IF NOT EXISTS referrals (id INTEGER PRIMARY KEY AUTOINCREMENT, referrer_id INT, referred_user_id INT, reward_gems INT DEFAULT 100, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
                            } else {
                                $db->exec("CREATE TABLE IF NOT EXISTS `referrals` (`id` INT AUTO_INCREMENT PRIMARY KEY, `referrer_id` INT, `referred_user_id` INT, `reward_gems` INT DEFAULT 100, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
                            }
                            $db->prepare("INSERT INTO referrals (referrer_id, referred_user_id, reward_gems) VALUES (:rid, :uid, 100)")->execute(['rid' => $referrerId, 'uid' => $newId]);
                            $db->prepare("UPDATE " . tbl('users') . " SET gems = gems + 100 WHERE id = :rid")->execute(['rid' => $referrerId]);
                            // Also give new user +50 bonus gems
                            $db->prepare("UPDATE " . tbl('users') . " SET gems = gems + 50 WHERE id = :uid")->execute(['uid' => $newId]);
                        } catch (Exception $e) {}
                    }
                }
            }

            header("Location: index.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация — ShibaLingo</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐕</text></svg>">
</head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-main); padding: 20px;">

<div class="card-duo anim-bounce" style="max-width: 460px; width: 100%; padding: 40px 32px; text-align: center;">
    <div style="font-size: 3.5rem; margin-bottom: 8px;">✨ 🐕</div>
    <h1 style="font-size: 1.8rem; font-weight: 900; color: var(--primary); margin-bottom: 6px;">
        Создать профиль
    </h1>
    <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 24px;">
        Присоединяйтесь к изучению языков и получите <strong>50 💎 Кристаллов</strong> в подарок!
    </p>

    <?php if (!empty($error)): ?>
        <div style="background: var(--danger-light); color: var(--danger-shadow); padding: 12px; border-radius: 12px; font-weight: 700; margin-bottom: 18px; font-size: 0.9rem;">
            ✕ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" style="text-align: left;">
        <div style="margin-bottom: 16px;">
            <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">Имя пользователя (Логин):</label>
            <input type="text" name="username" class="chat-input" placeholder="Например: vladik" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">Email:</label>
            <input type="email" name="email" class="chat-input" placeholder="vladik@example.com" required value="<?= e($_POST['email'] ?? '') ?>">
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">Пароль:</label>
            <div style="position: relative;">
                <input type="password" name="password" id="reg-pass" class="chat-input" placeholder="Минимум 4 символа" required style="padding-right: 42px;">
                <button type="button" onclick="togglePassVisibility('reg-pass', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 1.1rem; color: var(--text-muted);">👁️</button>
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-weight: 700; font-size: 0.9rem; display: block; margin-bottom: 6px;">Повторите пароль:</label>
            <div style="position: relative;">
                <input type="password" name="password_confirm" id="reg-pass-confirm" class="chat-input" placeholder="Повторите пароль" required style="padding-right: 42px;">
                <button type="button" onclick="togglePassVisibility('reg-pass-confirm', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 1.1rem; color: var(--text-muted);">👁️</button>
            </div>
        </div>

        <div style="margin-bottom: 20px; font-size: 0.8rem; color: var(--text-muted); line-height: 1.4;">
            Нажимая кнопку, вы соглашаетесь с <a href="terms.php" target="_blank" style="color: var(--secondary); font-weight: 700;">Условиями использования</a> и <a href="privacy.php" target="_blank" style="color: var(--secondary); font-weight: 700;">Политикой конфиденциальности</a> (GDPR / 152-ФЗ).
        </div>

        <button type="submit" class="btn-duo btn-primary" style="width: 100%; font-size: 1.1rem; padding: 14px;">
            Зарегистрироваться 🚀
        </button>
    </form>

    <script>
    function togglePassVisibility(inputId, btn) {
        const inp = document.getElementById(inputId);
        if (inp.type === 'password') {
            inp.type = 'text';
            btn.innerText = '🙈';
        } else {
            inp.type = 'password';
            btn.innerText = '👁️';
        }
    }
    </script>

    <div style="margin-top: 24px; padding-top: 18px; border-top: 2px solid var(--border-color); display: flex; justify-content: space-between; font-size: 0.9rem;">
        <span style="color: var(--text-muted);">Уже есть аккаунт?</span>
        <a href="login.php" style="color: var(--secondary); font-weight: 800; text-decoration: none;">
            Войти 🔑
        </a>
    </div>

    <div style="margin-top: 12px;">
        <a href="index.php" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none;">
            ← На главную
        </a>
    </div>
</div>

</body>
</html>
