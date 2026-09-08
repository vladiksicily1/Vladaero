<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Режим обслуживания | VladAero</title>
    <link rel="stylesheet" href="<?= url('public/css/main.css') ?>">
    <style>
        .maintenance { text-align: center; padding: 10vh 2rem; }
        .maintenance__icon { font-size: 4rem; margin-bottom: 1.5rem; animation: float 3s ease-in-out infinite; }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
        .maintenance h1 { font-size: 1.8rem; margin-bottom: .75rem; }
        .maintenance p { color: var(--text-dim); max-width: 450px; margin: 0 auto; line-height: 1.6; }
        .maintenance__bar {
            margin-top: 2rem;
            width: 200px;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
            margin-left: auto;
            margin-right: auto;
        }
        .maintenance__bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--amber));
            border-radius: 2px;
            width: 60%;
            animation: progress 2s ease-in-out infinite;
        }
        @keyframes progress { 0%, 100% { width: 30%; } 50% { width: 80%; } }
    </style>
</head>
<body>
    <div class="maintenance">
        <div class="maintenance__icon">🔧</div>
        <h1>Режим регламентных работ</h1>
        <p>Сайт временно недоступен для профилактики. Мы работаем над улучшениями. Попробуйте вернуться позже.</p>
        <div class="maintenance__bar"><div class="maintenance__bar-fill"></div></div>
    </div>
</body>
</html>
