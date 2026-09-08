<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAuth();
$pageTitle = 'Vlad Arcade — Кибер-Майнер';

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <div class="card" style="text-align: center; padding: 40px 20px; background: radial-gradient(circle at center, #1e1b4b, #0f172a); border-color: #8b5cf6;">
        <span style="font-size: 13px; font-weight: 800; color: #c084fc; text-transform: uppercase; letter-spacing: 1px;">
            Vlad Arcade &bull; Генератор VladCoins
        </span>

        <h2 style="font-size: 28px; margin: 10px 0 6px;">Тапайте по монете для добычи 🪙</h2>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 28px;">
            Каждый клик приносит VladCoins и повышает уровень вашего аккаунта в экосистеме!
        </p>

        <!-- Big Glowing Coin Target -->
        <div style="margin: 20px auto; width: 180px; height: 180px; cursor: pointer; user-select: none;" id="arcade-tap-target">
            <div style="width: 100%; height: 100%; border-radius: 50%; background: linear-gradient(135deg, #fbbf24, #d97706); display: flex; align-items: center; justify-content: center; font-size: 90px; box-shadow: 0 0 50px rgba(245, 158, 11, 0.5); transition: transform 0.1s ease; border: 4px solid #fef08a;">
                🪙
            </div>
        </div>

        <div style="margin-top: 18px; font-size: 24px; font-weight: 900; color: #fbbf24;" id="arcade-score">
            +0
        </div>
        <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
            Монеты автоматически сохраняются на вашем балансе
        </div>
    </div>

    <!-- ARCADE LEADERBOARD -->
    <div class="card">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 14px;">Топ майнеров экосистемы</h3>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php 
            $topMiners = DB::fetchAll("SELECT username, display_name, avatar, level, coins FROM users ORDER BY coins DESC LIMIT 5");
            foreach ($topMiners as $idx => $m): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: var(--bg-input); border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="font-weight: 800; color: <?= $idx === 0 ? '#fbbf24' : ($idx === 1 ? '#94a3b8' : ($idx === 2 ? '#b45309' : 'var(--text-muted)')) ?>;">
                            #<?= $idx + 1 ?>
                        </span>
                        <img src="<?= e(url($m['avatar'] ?: 'assets/images/default_avatar.svg')) ?>" style="width: 32px; height: 32px; border-radius: 50%;" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($m['display_name']) ?>&background=3b82f6&color=fff'">
                        <a href="<?= url('profile/@' . $m['username']) ?>" style="font-size: 14px; font-weight: 700; color: var(--text-primary);">
                            <?= e($m['display_name']) ?>
                        </a>
                    </div>
                    <span style="font-size: 14px; font-weight: 800; color: #fbbf24;">🪙 <?= format_coins($m['coins']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
