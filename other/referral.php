<?php
/**
 * ShibaLingo - Referral Program (Feature #53)
 */

$pageTitle = 'Пригласить друга';
require_once __DIR__ . '/includes/header.php';

$refCode = 'VLAD' . $user['id'] . 'REF';
$refUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/register.php?ref=' . $refCode;

$db = getDb();
$invitedFriendsCount = 0;
$totalGemsEarned = 0;
try {
    $rStmt = $db->prepare("SELECT COUNT(*) as total_cnt, COALESCE(SUM(reward_gems), 0) as total_gems FROM referrals WHERE referrer_id = :uid");
    $rStmt->execute(['uid' => $user['id']]);
    $refData = $rStmt->fetch(PDO::FETCH_ASSOC);
    if ($refData) {
        $invitedFriendsCount = (int)$refData['total_cnt'];
        $totalGemsEarned = (int)$refData['total_gems'];
    }
} catch (Exception $e) {}
?>

<div style="max-width: 700px; margin: 0 auto; text-align: center;">
    <div class="card-duo anim-bounce" style="padding: 40px 24px;">
        <div style="font-size: 4rem; margin-bottom: 12px;">🤝 🎁</div>
        <h1 style="font-size: 1.8rem; font-weight: 900; margin-bottom: 8px;">Пригласите друзей в ShibaLingo!</h1>
        <p style="color: var(--text-muted); font-size: 1.05rem; margin-bottom: 24px;">
            Поделитесь персональной ссылкой с другом. Когда друг зарегистрируется, вы оба получите <strong>100 Кристаллов 💎</strong> и <strong>эксклюзивный бейдж</strong>!
        </p>

        <!-- Referral Box -->
        <div style="background: var(--bg-main); padding: 16px; border-radius: 16px; border: 2px dashed var(--secondary); max-width: 500px; margin: 0 auto 24px auto;">
            <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 6px; font-weight: 700;">Ваша реферальная ссылка:</div>
            <input type="text" value="<?= e($refUrl) ?>" id="ref-link-input" readonly class="chat-input" style="text-align: center; font-weight: 800; font-size: 1rem; margin-bottom: 10px;">
            <button class="btn-duo btn-primary" onclick="copyRefLink()" style="width: 100%;">
                📋 Скопировать ссылку
            </button>
        </div>

        <div style="display: flex; justify-content: center; gap: 24px; font-weight: 800; color: var(--text-muted);">
            <div>👥 Приглашено друзей: <span style="color: var(--primary);"><?= $invitedFriendsCount ?></span></div>
            <div>💎 Заработано: <span style="color: var(--secondary);"><?= $totalGemsEarned ?></span></div>
        </div>
    </div>
</div>

<script>
function copyRefLink() {
    const input = document.getElementById('ref-link-input');
    input.select();
    document.execCommand('copy');
    SoundEngine.play('correct');
    alert('Ссылка скопирована в буфер обмена! 🐾');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
