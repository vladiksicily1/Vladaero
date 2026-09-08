<?php
/** @var array $config */
$siteName = setting('site_name', 'VladAero');
$logoPath = url(setting('site_logo', 'assets/images/logo.png'));
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <img src="<?= $logoPath ?>" alt="<?= e($siteName) ?>" class="auth-logo">
            <h1 class="auth-title">Вход в систему</h1>
            <p class="auth-subtitle">Авиационный портал и бортовой журнал <?= e($siteName) ?></p>
        </div>

        <div class="auth-tabs">
            <a href="<?= url('/auth/login') ?>" class="auth-tab active">Вход</a>
            <a href="<?= url('/auth/register') ?>" class="auth-tab">Регистрация</a>
        </div>

        <form method="POST" action="<?= url('/auth/login') ?>">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="login">Email, логин или Telegram</label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">👤</span>
                    <input type="text" id="login" name="login" class="auth-input" placeholder="pilot@vladaero.ru или @username" required autofocus autocomplete="username">
                </div>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: .25rem;">
                    <label for="password" style="margin-bottom:0;">Пароль</label>
                    <a href="<?= url('/auth/password-reset') ?>" style="font-size: .8rem; color: var(--text-dim);">Забыли?</a>
                </div>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">🔒</span>
                    <input type="password" id="password" name="password" class="auth-input" placeholder="Ваш пароль" required autocomplete="current-password">
                </div>
            </div>

            <button type="submit" class="btn btn--primary btn--block" style="padding: .75rem; font-size: 1rem; margin-top: .5rem;">
                Войти в кабинет ✈️
            </button>
        </form>

        <div class="auth-divider">
            <span>или через соцсети</span>
        </div>

        <a href="<?= url('/auth/telegram') ?>" class="btn btn--telegram btn--block" style="padding: .7rem;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.75-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .37z"/></svg>
            Войти через Telegram
        </a>
    </div>
</div>
