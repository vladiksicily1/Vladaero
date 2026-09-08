<?php /** @var array $user */ ?>

<section class="section">
    <div class="container" style="max-width: 800px;">
        <h1 class="page-title">⚙️ Настройки аккаунта</h1>

        <form method="POST" class="card" action="<?= url('/settings') ?>" style="margin-bottom: 2rem;">
            <?= csrf_field() ?>
            <div class="card__body">
                <h3 style="margin-bottom: 1.25rem;">👤 Личные данные</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="display_name">Отображаемое имя / Позывной</label>
                        <input type="text" id="display_name" name="display_name" class="form-control" value="<?= e($user['display_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email адрес</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="bio">О себе (информация в профиле)</label>
                    <textarea id="bio" name="bio" class="form-control" rows="3" placeholder="Расскажите о своем опыте в авиации, любимых самолётах..."><?= e($user['bio'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="password">Новый пароль (оставьте пустым, если не меняете)</label>
                    <input type="password" id="password" name="password" class="form-control" minlength="6" placeholder="••••••••">
                </div>

                <hr style="border: none; border-top: 1px solid var(--border); margin: 1.75rem 0;">

                <h3 style="margin-bottom: .5rem;">🔐 Безопасность и Telegram 2FA</h3>
                <p class="text-muted" style="font-size: .85rem; margin-bottom: 1.25rem;">
                    Подключите Telegram для получения кодов двухфакторной аутентификации (2FA) при каждом входе в аккаунт.
                </p>

                <div class="form-row">
                    <div class="form-group">
                        <label for="telegram_username">Telegram Username</label>
                        <input type="text" id="telegram_username" name="telegram_username" class="form-control" value="<?= e($user['telegram_username'] ?? '') ?>" placeholder="username">
                    </div>
                    <div class="form-group">
                        <label for="telegram_id">Telegram Chat ID</label>
                        <input type="text" id="telegram_id" name="telegram_id" class="form-control font-mono" value="<?= e($user['telegram_id'] ?? '') ?>" placeholder="123456789">
                    </div>
                </div>

                <div class="form-group" style="padding: 1rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius); margin-top: .5rem;">
                    <label class="checkbox-label" style="display: flex; align-items: center; gap: .75rem; cursor: pointer; text-transform: none;">
                        <input type="checkbox" name="tfa_enabled" value="1" <?= !empty($user['tfa_enabled']) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                        <div>
                            <strong style="font-size: .95rem; display: flex; align-items: center; gap: .5rem;">
                                🛡️ Включить двухфакторную аутентификацию (2FA)
                                <?php if (!empty($user['tfa_enabled'])): ?>
                                    <span class="badge badge--success" style="font-size: .7rem;">Активна</span>
                                <?php else: ?>
                                    <span class="badge" style="font-size: .7rem; background: var(--surface-3);">Отключена</span>
                                <?php endif; ?>
                            </strong>
                            <span class="text-muted" style="font-size: .8rem; display: block; margin-top: .15rem;">
                                При входе с логином и паролем бот будет отправлять одноразовый 6-значный код в ваш Telegram.
                            </span>
                        </div>
                    </label>
                </div>

                <div style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn--primary">💾 Сохранить настройки</button>
                </div>
            </div>
        </form>
    </div>
</section>
