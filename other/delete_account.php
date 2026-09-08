<?php
/**
 * ShibaLingo - Delete Account Endpoint
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = getCurrentUser();
if (!$user) {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_text'] ?? '';

    if (empty($password)) {
        $error = 'Введите ваш текущий пароль для подтверждения!';
    } elseif ($confirm !== 'УДАЛИТЬ') {
        $error = 'Введите слово «УДАЛИТЬ» заглавными буквами для подтверждения!';
    } elseif (!password_verify($password, $user['password_hash'])) {
        $error = 'Неверный пароль!';
    } else {
        $db = getDb();
        $uid = $user['id'];

        // Delete user's sessions, progress, inventory and user record
        $db->prepare("DELETE FROM " . tbl('user_progress') . " WHERE user_id = :id")->execute(['id' => $uid]);
        $db->prepare("DELETE FROM " . tbl('chat_sessions') . " WHERE user_id = :id")->execute(['id' => $uid]);
        $db->prepare("DELETE FROM " . tbl('user_inventory') . " WHERE user_id = :id")->execute(['id' => $uid]);
        $db->prepare("DELETE FROM " . tbl('user_achievements') . " WHERE user_id = :id")->execute(['id' => $uid]);
        $db->prepare("DELETE FROM " . tbl('user_quests') . " WHERE user_id = :id")->execute(['id' => $uid]);
        $db->prepare("DELETE FROM " . tbl('user_promo_uses') . " WHERE user_id = :id")->execute(['id' => $uid]);
        $db->prepare("DELETE FROM " . tbl('users') . " WHERE id = :id")->execute(['id' => $uid]);

        // Clear session
        unset($_SESSION['user_id']);
        unset($_SESSION['username']);
        unset($_SESSION['current_language']);

        header("Location: index.php?msg=account_deleted");
        exit;
    }
}

$pageTitle = 'Удаление аккаунта';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 600px; margin: 40px auto;">
    <div class="card-duo" style="border: 3px solid var(--danger); padding: 36px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="font-size: 3.5rem;">⚠️ 🗑️</div>
            <h1 style="font-size: 1.8rem; font-weight: 900; color: var(--danger);">
                Удаление аккаунта
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 8px;">
                Это действие необратимо. Все ваши достижения, кристаллы, стрик и прогресс в изучении языков будут навсегда стёрты.
            </p>
        </div>

        <?php if (!empty($error)): ?>
            <div style="background: var(--danger-light); color: var(--danger-shadow); padding: 12px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
                ✕ <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom: 18px;">
                <label style="font-weight: 800; display: block; margin-bottom: 6px;">Введите ваш текущий пароль:</label>
                <input type="password" name="password" class="chat-input" placeholder="••••••••" required>
            </div>

            <div style="margin-bottom: 24px;">
                <label style="font-weight: 800; display: block; margin-bottom: 6px;">Напишите слово <code style="color: var(--danger);">УДАЛИТЬ</code> для подтверждения:</label>
                <input type="text" name="confirm_text" class="chat-input" placeholder="УДАЛИТЬ" required>
            </div>

            <div style="display: flex; gap: 14px;">
                <a href="profile.php" class="btn-duo btn-outline" style="flex: 1; text-align: center;">
                    Отмена
                </a>
                <button type="submit" class="btn-duo" style="flex: 1; background: var(--danger); border-bottom-color: var(--danger-shadow); color: white;">
                    Удалить навсегда 🗑️
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
