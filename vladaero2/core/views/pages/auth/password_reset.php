<?php
/** @var array $config */
$siteName = setting('site_name', 'VladAero');
$logoPath = url(setting('site_logo', 'assets/images/logo.png'));
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <img src="<?= $logoPath ?>" alt="<?= e($siteName) ?>" class="auth-logo">
            <h1 class="auth-title">Сброс пароля</h1>
            <p class="auth-subtitle">Восстановление доступа к аккаунту пилота <?= e($siteName) ?></p>
        </div>

        <form method="POST" action="<?= url('/auth/password-reset') ?>">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="login">Email или логин</label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">👤</span>
                    <input type="text" id="login" name="login" class="auth-input" placeholder="pilot@vladaero.ru или @username" required autofocus>
                </div>
            </div>

            <button type="submit" class="btn btn--primary btn--block" style="padding: .75rem; font-size: 1rem; margin-top: .5rem;">
                Отправить инструкцию 📨
            </button>
        </form>

        <div class="auth-links" style="justify-content: center; margin-top: 1.5rem;">
            <a href="<?= url('/auth/login') ?>">← Вернуться к входу</a>
        </div>
    </div>
</div>
