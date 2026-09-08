<?php
/** @var array $user */
$siteName = setting('site_name', 'VladAero');
$logoPath = url(setting('site_logo', 'assets/images/logo.png'));
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <img src="<?= $logoPath ?>" alt="<?= e($siteName) ?>" class="auth-logo">
            <h1 class="auth-title">🔐 Двухфакторная защита</h1>
            <p class="auth-subtitle">
                Мы отправили 6-значный код подтверждения в Telegram для аккаунта <strong><?= e($user['display_name'] ?? $user['username'] ?? '') ?></strong>.
            </p>
        </div>

        <form method="POST" action="<?= url('/auth/2fa') ?>">
            <?= csrf_field() ?>

            <div class="form-group" style="text-align: center; margin: 1.5rem 0;">
                <label for="code" style="display: block; margin-bottom: .5rem; font-weight: 600;">Введите 6-значный код:</label>
                <input type="text" id="code" name="code" class="two-factor-code-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="••••••" autofocus required autocomplete="one-time-code">
            </div>

            <button type="submit" class="btn btn--primary btn--block" style="padding: .75rem; font-size: 1rem;">
                Подтвердить вход 🚀
            </button>
        </form>

        <div style="text-align: center; margin-top: 1.5rem; display: flex; flex-direction: column; gap: .75rem;">
            <form method="POST" action="<?= url('/auth/2fa/resend') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--outline btn--sm" style="font-size: .85rem;">
                    🔄 Отправить код повторно
                </button>
            </form>

            <a href="<?= url('/auth/login') ?>" style="font-size: .85rem; color: var(--text-dim); text-decoration: none;">
                ← Вернуться ко входу
            </a>
        </div>
    </div>
</div>
