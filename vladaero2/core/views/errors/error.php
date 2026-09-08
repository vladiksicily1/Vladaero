<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($code ?? 500) ?> — <?= e($message ?? 'Ошибка') ?> | VladAero</title>
    <link rel="stylesheet" href="<?= url('public/css/main.css') ?>">
    <style>
        .error-page { text-align: center; padding: 8vh 2rem; }
        .error-code { font-size: 8rem; font-weight: 800; color: var(--error); opacity: .3; font-family: var(--font-mono); line-height: 1; }
        .error-title { font-size: 1.5rem; font-weight: 600; margin: 1rem 0 .5rem; }
        .error-desc { color: var(--text-dim); margin-bottom: 2rem; }
        .error-actions { display: flex; gap: 1rem; justify-content: center; }
        .error-cause { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; max-width: 500px; margin: 1.5rem auto; font-family: var(--font-mono); font-size: .8rem; color: var(--error); text-align: left; }
    </style>
</head>
<body>
    <div class="error-page">
        <div class="error-code"><?= e((string)($code ?? 500)) ?></div>
        <h1 class="error-title">
            <?php if (($code ?? 500) == 500): ?>
                ⚠️ Отказ систем
            <?php elseif (($code ?? 500) == 403): ?>
                🚫 Доступ запрещён
            <?php elseif (($code ?? 500) == 401): ?>
                🔐 Необходима авторизация
            <?php else: ?>
                <?= e($message ?? 'Ошибка') ?>
            <?php endif; ?>
        </h1>
        <p class="error-desc"><?= e($message ?? 'Произошла непредвиденная ошибка. Попробуйте позже или вернитесь на главную.') ?></p>
        <div class="error-actions">
            <a href="<?= url('/') ?>" class="btn btn--primary">🏠 На главную</a>
            <a href="javascript:history.back()" class="btn btn--outline">← Назад</a>
        </div>
    </div>
</body>
</html>
