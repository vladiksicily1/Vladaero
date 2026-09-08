<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Борт не найден | VladAero</title>
    <link rel="stylesheet" href="<?= url('public/css/main.css') ?>">
    <style>
        .error-page { text-align: center; padding: 8vh 2rem; }
        .error-code { font-size: 8rem; font-weight: 800; color: var(--accent); opacity: .3; font-family: var(--font-mono); line-height: 1; }
        .error-title { font-size: 1.5rem; font-weight: 600; margin: 1rem 0 .5rem; }
        .error-desc { color: var(--text-dim); margin-bottom: 2rem; max-width: 400px; margin-left: auto; margin-right: auto; }
        .error-radar { width: 200px; height: 200px; margin: 2rem auto; position: relative; }
        .error-radar__ring { position: absolute; inset: 0; border: 2px solid var(--accent); border-radius: 50%; opacity: .2; }
        .error-radar__ring:nth-child(2) { inset: 20px; }
        .error-radar__ring:nth-child(3) { inset: 40px; }
        .error-radar__sweep { position: absolute; top: 50%; left: 50%; width: 50%; height: 2px; background: linear-gradient(90deg, var(--accent), transparent); transform-origin: left center; animation: sweep 3s linear infinite; }
        @keyframes sweep { to { transform: rotate(360deg); } }
        .error-actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
    </style>
</head>
<body class="app-body">
    <div class="error-page">
        <div class="error-radar">
            <div class="error-radar__ring"></div>
            <div class="error-radar__ring"></div>
            <div class="error-radar__ring"></div>
            <div class="error-radar__sweep"></div>
        </div>
        <div class="error-code">404</div>
        <h1 class="error-title">Борт не найден на радаре</h1>
        <p class="error-desc">Запрошенная страница не найдена или была перемещена. Проверьте URL или вернитесь на главную.</p>
        <div class="error-actions">
            <a href="<?= url('/') ?>" class="btn btn--primary">🏠 На главную</a>
            <a href="<?= url('/search') ?>" class="btn btn--outline">🔍 Поиск</a>
        </div>
    </div>
</body>
</html>
