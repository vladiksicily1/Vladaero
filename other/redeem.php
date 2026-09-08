<?php
/**
 * ShibaLingo - Promo Code Redemption (Feature #29)
 */

$pageTitle = 'Активация промокода';
require_once __DIR__ . '/includes/header.php';

$message = '';
$msgType = 'success';
$rewardActivated = false;

$db = getDb();
$p = defined('DB_PREFIX') ? DB_PREFIX : '';

// Ensure tables exist
try {
    if (Database::getDriver() === 'sqlite') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS {$p}promo_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                reward_type TEXT NOT NULL,
                reward_value TEXT NOT NULL,
                max_uses INTEGER DEFAULT 100,
                used_count INTEGER DEFAULT 0,
                expires_at DATE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS {$p}user_promo_uses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code_id INTEGER NOT NULL,
                used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, code_id)
            );
            INSERT OR IGNORE INTO {$p}promo_codes (id, code, reward_type, reward_value, max_uses, used_count, expires_at) VALUES
            (1, 'SHIBA2026', 'gems', '100', 500, 0, '2027-12-31'),
            (2, 'VLADIKISH', 'xp', '150', 500, 0, '2027-12-31'),
            (3, 'SUPERHEARTS', 'hearts', '5', 500, 0, '2027-12-31'),
            (4, 'GOLDENSHIBA', 'bundle', '{\"gems\":150,\"xp\":200,\"hearts\":5}', 500, 0, '2027-12-31');
        ");
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) {
        $message = 'Для активации промокода необходимо войти в аккаунт или зарегистрироваться!';
        $msgType = 'error';
    } else {
        $codeStr = strtoupper(trim($_POST['promo_code'] ?? ''));

        $stmt = $db->prepare("SELECT * FROM " . tbl('promo_codes') . " WHERE UPPER(code) = :c");
        $stmt->execute(['c' => $codeStr]);
        $promo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$promo) {
            $message = 'Неверный или несуществующий промокод!';
            $msgType = 'error';
        } elseif (!empty($promo['expires_at']) && strtotime($promo['expires_at'] . ' 23:59:59') < time()) {
            $message = 'Срок действия этого промокода истёк.';
            $msgType = 'error';
        } elseif ((int)$promo['used_count'] >= (int)$promo['max_uses']) {
            $message = 'Лимит активаций этого промокода исчерпан.';
            $msgType = 'error';
        } else {
            // Check if user already used this code
            $uCheck = $db->prepare("SELECT COUNT(*) FROM " . tbl('user_promo_uses') . " WHERE user_id = :uid AND code_id = :cid");
            $uCheck->execute(['uid' => $user['id'], 'cid' => $promo['id']]);
            if ($uCheck->fetchColumn() > 0) {
                $message = 'Вы уже активировали этот промокод ранее!';
                $msgType = 'error';
            } else {
                // Apply Reward
                $rewardText = '';
                if ($promo['reward_type'] === 'gems') {
                    $val = (int)$promo['reward_value'];
                    $db->prepare("UPDATE " . tbl('users') . " SET gems = gems + :val WHERE id = :id")->execute(['val' => $val, 'id' => $user['id']]);
                    $rewardText = "+{$val} Кристаллов 💎";
                } elseif ($promo['reward_type'] === 'xp') {
                    $val = (int)$promo['reward_value'];
                    $db->prepare("UPDATE " . tbl('users') . " SET xp = xp + :val WHERE id = :id")->execute(['val' => $val, 'id' => $user['id']]);
                    $rewardText = "+{$val} XP ⚡";
                } elseif ($promo['reward_type'] === 'hearts') {
                    $db->prepare("UPDATE " . tbl('users') . " SET hearts = 5 WHERE id = :id")->execute(['id' => $user['id']]);
                    $rewardText = "Полное восстановление 5 сердечек ❤️";
                } elseif ($promo['reward_type'] === 'skin') {
                    $skinKey = trim($promo['reward_value']);
                    $db->prepare("INSERT OR IGNORE INTO " . tbl('user_inventory') . " (user_id, item_key) VALUES (:uid, :sk)")->execute(['uid' => $user['id'], 'sk' => $skinKey]);
                    $rewardText = "Эксклюзивный скин: {$skinKey} 🎭";
                } elseif ($promo['reward_type'] === 'bundle') {
                    $b = json_decode($promo['reward_value'], true) ?: [];
                    $g = (int)($b['gems'] ?? 0);
                    $x = (int)($b['xp'] ?? 0);
                    $h = (int)($b['hearts'] ?? 0);
                    $db->prepare("UPDATE " . tbl('users') . " SET gems = gems + :g, xp = xp + :x, hearts = MIN(5, hearts + :h) WHERE id = :id")->execute(['g' => $g, 'x' => $x, 'h' => $h, 'id' => $user['id']]);
                    $rewardText = "+{$g} 💎 Кристаллов, +{$x} ⚡ XP, +{$h} ❤️ Жизней";
                }

                // Record use
                $db->prepare("INSERT INTO " . tbl('user_promo_uses') . " (user_id, code_id) VALUES (:uid, :cid)")->execute(['uid' => $user['id'], 'cid' => $promo['id']]);
                $db->prepare("UPDATE " . tbl('promo_codes') . " SET used_count = used_count + 1 WHERE id = :id")->execute(['id' => $promo['id']]);

                // Refresh user in session
                $refUser = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE id = :id");
                $refUser->execute(['id' => $user['id']]);
                $user = $refUser->fetch(PDO::FETCH_ASSOC);
                $_SESSION['user'] = $user;

                $message = "Поздравляем! Промокод успешно активирован! Ваша награда: {$rewardText}";
                $msgType = 'success';
                $rewardActivated = true;
            }
        }
    }
}
?>

<div style="max-width: 540px; margin: 40px auto;">
    <div class="card-duo anim-bounce" style="text-align: center; padding: 36px 28px;">
        <div style="font-size: 3.5rem; margin-bottom: 12px;">🎁 🐾</div>
        <h1 style="font-size: 1.7rem; font-weight: 900; margin-bottom: 8px;">Активация промокода</h1>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 24px;">
            Введите секретный промокод, чтобы получить кристаллы 💎, очки опыта или восстановление сердечек!
        </p>

        <?php if (!empty($message)): ?>
            <div class="alert <?= ($msgType === 'success') ? 'alert-success' : 'alert-error' ?>" style="background: <?= ($msgType === 'success') ? 'var(--primary-light)' : 'var(--danger-light)' ?>; color: <?= ($msgType === 'success') ? 'var(--primary-shadow)' : 'var(--danger-shadow)' ?>; padding: 16px; border-radius: 14px; font-weight: 800; margin-bottom: 24px; font-size: 1.05rem;">
                <?= ($msgType === 'success') ? '🎉 ' : '✕ ' ?><?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!$user): ?>
            <div style="background: var(--bg-main); border: 2px dashed var(--border-color); border-radius: 16px; padding: 20px; margin-bottom: 20px;">
                <p style="font-weight: 800; margin-bottom: 12px;">Войдите, чтобы активировать промокод:</p>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <a href="login.php" class="btn-duo btn-outline">Войти 🔑</a>
                    <a href="register.php" class="btn-duo btn-primary">Регистрация 🚀</a>
                </div>
            </div>
        <?php else: ?>
            <form method="POST">
                <div style="margin-bottom: 16px;">
                    <input type="text" name="promo_code" class="chat-input" placeholder="Например: SHIBA2026" required style="text-align: center; font-weight: 900; font-size: 1.3rem; letter-spacing: 2px; text-transform: uppercase; padding: 14px;">
                </div>
                <button type="submit" class="btn-duo btn-primary" style="width: 100%; font-size: 1.1rem; padding: 14px;">
                    Активировать подарок 🎉
                </button>
            </form>

            <div style="margin-top: 24px; padding-top: 18px; border-top: 1.5px solid var(--border-color); display: flex; justify-content: center; gap: 16px; font-size: 0.85rem; color: var(--text-muted);">
                <span>💎 <strong><?= (int)($user['gems'] ?? 0) ?></strong></span>
                <span>⚡ <strong><?= (int)($user['xp'] ?? 0) ?> XP</strong></span>
                <span>❤️ <strong><?= (int)($user['hearts'] ?? 5) ?>/5</strong></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($rewardActivated): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof SoundEngine !== 'undefined') SoundEngine.play('win');
    if (typeof triggerConfetti !== 'undefined') triggerConfetti();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
