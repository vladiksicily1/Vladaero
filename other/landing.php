<?php
/**
 * ShibaLingo - Landing Page for Guests
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$siteTitle = getSetting('site_title', 'ShibaLingo');
$mascotName = getSetting('mascot_name', 'Сиба-сэнсэй');
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($siteTitle) ?> — Изучай языки и тайный язык Vladikish с Шиба-Ину!</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐕</text></svg>">
    <style>
        body {
            display: block !important;
            width: 100% !important;
            min-height: 100vh;
            margin: 0 !important;
            padding: 0 !important;
            overflow-x: hidden !important;
            background-color: var(--bg-main);
        }
        .landing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            box-sizing: border-box;
            flex-wrap: wrap;
            gap: 12px;
        }
        .landing-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            align-items: center;
            gap: 40px;
            max-width: 1200px;
            width: 100%;
            margin: 20px auto 40px;
            padding: 0 24px;
            box-sizing: border-box;
            min-height: 60vh;
        }
        .hero-mascot-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            width: 100%;
        }
        .hero-mascot-img {
            font-size: 9.5rem;
            line-height: 1;
            filter: drop-shadow(0 20px 30px rgba(0,0,0,0.12));
            animation: float 4s ease-in-out infinite;
        }
        .hero-speech {
            background: var(--bg-card, #ffffff);
            padding: 14px 20px;
            border-radius: 20px;
            border: 3px solid var(--border-color);
            font-weight: 800;
            color: var(--text-main);
            position: relative;
            box-shadow: 0 10px 25px rgba(0,0,0,0.06);
            margin-bottom: 16px;
            text-align: center;
            max-width: 90%;
        }
        .hero-speech::after {
            content: '';
            position: absolute;
            bottom: -12px;
            left: 50%;
            transform: translateX(-50%);
            border-width: 12px 12px 0;
            border-style: solid;
            border-color: var(--bg-card, #ffffff) transparent;
            display: block;
            width: 0;
        }
        .hero-title {
            font-size: clamp(1.8rem, 3.5vw, 2.7rem);
            font-weight: 900;
            line-height: 1.15;
            color: var(--text-main);
            margin-bottom: 16px;
        }
        .hero-subtitle {
            font-size: 1.15rem;
            color: var(--text-muted);
            line-height: 1.5;
            margin-bottom: 28px;
        }
        .features-section {
            background: var(--bg-card, #ffffff);
            border-top: 2px solid var(--border-color);
            border-bottom: 2px solid var(--border-color);
            padding: 60px 20px;
            width: 100%;
            box-sizing: border-box;
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            box-sizing: border-box;
        }
        .feature-card {
            padding: 24px;
            border-radius: 20px;
            border: 2px solid var(--border-color);
            background: var(--bg-main);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .feature-card:hover {
            transform: translateY(-4px);
            border-color: var(--primary);
        }
        @media (max-width: 900px) {
            .landing-header { padding: 14px 16px; justify-content: center; }
            .landing-hero { grid-template-columns: 1fr; text-align: center; gap: 28px; margin-top: 10px; }
            .hero-mascot-img { font-size: 7.5rem; }
            .hero-content { display: flex; flex-direction: column; align-items: center; }
            .hero-buttons { width: 100%; max-width: 380px; }
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="landing-header">
    <a href="index.php" style="display: flex; align-items: center; gap: 10px; text-decoration: none; font-size: 1.4rem; font-weight: 900; color: var(--primary);">
        <span style="font-size: 1.8rem;">🐕</span>
        <span><?= e($siteTitle) ?></span>
    </a>

    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <a href="admin/login.php" class="btn-duo btn-outline" style="padding: 8px 14px; font-size: 0.85rem;" title="Вход для администратора">
            🛡️ Админка
        </a>
        <a href="login.php" class="btn-duo btn-outline" style="padding: 8px 18px; font-size: 0.9rem;">
            Войти 🔑
        </a>
        <a href="register.php" class="btn-duo btn-primary" style="padding: 8px 18px; font-size: 0.9rem;">
            Регистрация 🚀
        </a>
    </div>
</header>

<!-- Hero Section -->
<section class="landing-hero">
    <div class="hero-mascot-box">
        <div class="hero-speech anim-pop">
            Гав! Давай учить языки весело и с подарками! 🐕✨
        </div>
        <div class="hero-mascot-img">
            🐕
        </div>
        <div style="display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; justify-content: center;">
            <span class="badge-tag" style="background: #fef08a; color: #854d0e; font-size: 0.85rem;">⭐ 100% Бесплатно</span>
            <span class="badge-tag" style="background: #bbf7d0; color: #166534; font-size: 0.85rem;">🤖 NVIDIA NIM AI</span>
            <span class="badge-tag" style="background: #e9d5ff; color: #6b21a8; font-size: 0.85rem;">✨ Vladikish Conlang</span>
        </div>
    </div>

    <div class="hero-content">
        <h1 class="hero-title">
            Бесплатный, весёлый и умный способ учить языки!
        </h1>
        <p class="hero-subtitle">
            Учите <strong>English</strong>, <strong>Italiano</strong>, <strong>Русский</strong> и уникальный выдуманный язык <strong>Vladikish</strong> вместе с дружелюбным <strong><?= e($mascotName) ?></strong>!
        </p>

        <div class="hero-buttons" style="display: flex; flex-direction: column; gap: 12px; width: 100%; max-width: 400px;">
            <a href="register.php" class="btn-duo btn-primary" style="padding: 15px 24px; font-size: 1.1rem; text-align: center; justify-content: center;">
                🚀 Начать обучение бесплатно
            </a>
            <a href="login.php" class="btn-duo btn-outline" style="padding: 13px 24px; font-size: 0.95rem; text-align: center; justify-content: center;">
                🔑 У меня уже есть аккаунт
            </a>
        </div>
    </div>
</section>

<!-- Languages Grid -->
<div style="max-width: 1200px; width: 100%; margin: 0 auto 50px; padding: 0 20px; box-sizing: border-box;">
    <h2 style="text-align: center; font-size: 1.6rem; font-weight: 900; margin-bottom: 24px;">
        🌍 Выберите язык для старта:
    </h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
        <a href="register.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; border-color: var(--secondary); margin-bottom: 0;">
            <div style="font-size: 2.8rem; margin-bottom: 6px;">🐕</div>
            <h3 style="font-size: 1.2rem; font-weight: 900; color: var(--secondary);">Vladikish</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem;">Уникальный conlang с мелодичной фонетикой и AI-наставником</p>
        </a>
        <a href="register.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0;">
            <div style="font-size: 2.8rem; margin-bottom: 6px;">🇬🇧</div>
            <h3 style="font-size: 1.2rem; font-weight: 900; color: var(--primary);">English</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem;">Международный язык для путешествий, общения и IT</p>
        </a>
        <a href="register.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0;">
            <div style="font-size: 2.8rem; margin-bottom: 6px;">🇮🇹</div>
            <h3 style="font-size: 1.2rem; font-weight: 900; color: #a855f7;">Italiano</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem;">Красивый язык искусства, кулинарии и романтики</p>
        </a>
        <a href="register.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0;">
            <div style="font-size: 2.8rem; margin-bottom: 6px;">🇷🇺</div>
            <h3 style="font-size: 1.2rem; font-weight: 900; color: #3b82f6;">Русский</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem;">Богатый и выразительный язык для глубокого изучения</p>
        </a>
    </div>
</div>

<!-- Features Section -->
<section class="features-section">
    <div style="max-width: 1200px; width: 100%; margin: 0 auto;">
        <h2 style="text-align: center; font-size: 2rem; font-weight: 900; margin-bottom: 10px;">
            ✨ Почему учиться с ShibaLingo круто?
        </h2>
        <p style="text-align: center; color: var(--text-muted); font-size: 1.05rem; max-width: 600px; margin: 0 auto 40px;">
            Геймификация, искусственный интеллект и верный пушистый друг делают каждый день продуктивным!
        </p>

        <div class="feature-grid">
            <div class="feature-card">
                <div style="font-size: 2.3rem; margin-bottom: 10px;">🤖</div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">Умный AI-Тьютор</h3>
                <p style="color: var(--text-muted); font-size: 0.88rem; line-height: 1.4;">
                    Сиба-сэнсэй на базе NVIDIA NIM понимает контекст, исправляет грамматику и поддерживает живой диалог.
                </p>
            </div>

            <div class="feature-card">
                <div style="font-size: 2.3rem; margin-bottom: 10px;">🍖</div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">Тамагочи с Сибой</h3>
                <p style="color: var(--text-muted); font-size: 0.88rem; line-height: 1.4;">
                    Зарабатывайте кристаллы за правильные ответы и угощайте маскота раменом и косточками!
                </p>
            </div>

            <div class="feature-card">
                <div style="font-size: 2.3rem; margin-bottom: 10px;">⚔️</div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">1v1 Дуэли и Рейды</h3>
                <p style="color: var(--text-muted); font-size: 0.88rem; line-height: 1.4;">
                    Сражайтесь на скорость в викторинах с друзьями и побеждайте мирового босса Голема Ошибок.
                </p>
            </div>

            <div class="feature-card">
                <div style="font-size: 2.3rem; margin-bottom: 10px;">🏆</div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">Лиги и Стрик</h3>
                <p style="color: var(--text-muted); font-size: 0.88rem; line-height: 1.4;">
                    Защищайте свой огонёк стрика 🔥, продвигайтесь из Бронзовой лиги в Бриллиантовую и получайте скины.
                </p>
            </div>

            <div class="feature-card">
                <div style="font-size: 2.3rem; margin-bottom: 10px;">📜</div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">Сертификаты и Паспорт</h3>
                <p style="color: var(--text-muted); font-size: 0.88rem; line-height: 1.4;">
                    Получите официальный печатный сертификат и паспорт гражданина мира Vladikish с вашим ID!
                </p>
            </div>

            <div class="feature-card">
                <div style="font-size: 2.3rem; margin-bottom: 10px;">🎙️</div>
                <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">Радио и Озвучка</h3>
                <p style="color: var(--text-muted); font-size: 0.88rem; line-height: 1.4;">
                    Слушайте произношение каждого слова через синтез речи и тренируйте акцент с микрофоном.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Footer CTA -->
<div style="padding: 60px 20px; text-align: center; max-width: 700px; width: 100%; margin: 0 auto; box-sizing: border-box;">
    <div style="font-size: 3.5rem; margin-bottom: 10px;">🐕 🎉</div>
    <h2 style="font-size: 1.9rem; font-weight: 900; margin-bottom: 12px;">
        Готовы начать свое языковое приключение?
    </h2>
    <p style="color: var(--text-muted); font-size: 1.05rem; margin-bottom: 24px;">
        Присоединяйтесь бесплатно прямо сейчас и получите 50 кристаллов на покупки в магазине!
    </p>
    <a href="register.php" class="btn-duo btn-primary" style="padding: 16px 36px; font-size: 1.15rem; display: inline-flex;">
        Создать профиль ученика 🚀
    </a>
</div>

<!-- Footer -->
<footer style="text-align: center; padding: 28px 20px; color: var(--text-muted); font-size: 0.88rem; border-top: 1px solid var(--border-color); background: var(--bg-card, #ffffff); width: 100%; box-sizing: border-box;">
    <div style="max-width: 1200px; width: 100%; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            © <?= date('Y') ?> <strong><?= e($siteTitle) ?></strong> — Языки & Vladikish с Шиба-Ину 🐕
        </div>
        <div style="display: flex; gap: 16px; font-weight: 700; flex-wrap: wrap;">
            <a href="privacy.php" style="color: var(--text-muted); text-decoration: none;">Политика конфиденциальности</a>
            <a href="terms.php" style="color: var(--text-muted); text-decoration: none;">Условия использования</a>
            <a href="admin/login.php" style="color: var(--secondary); text-decoration: none;">Панель админа 🛡️</a>
        </div>
    </div>
</footer>

<!-- Cookie Consent Banner -->
<div id="cookie-consent-banner" style="display: none; position: fixed; bottom: 20px; left: 20px; right: 20px; max-width: 600px; margin: 0 auto; background: var(--bg-card, #ffffff); border: 2px solid var(--border-color); border-radius: 16px; padding: 16px 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); z-index: 9999; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
    <div style="font-size: 0.9rem; line-height: 1.4; color: var(--text-main); flex: 1; min-width: 260px;">
        🍪 Мы используем Cookie для персонализации и авторизации. <a href="privacy.php" style="color: var(--secondary); font-weight: 700; text-decoration: none;">Политика конфиденциальности / Privacy</a>
    </div>
    <div style="display: flex; gap: 8px;">
        <button onclick="acceptCookies()" class="btn-duo btn-primary" style="padding: 6px 16px; font-size: 0.85rem;">
            Принять / Accetta ✓
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!localStorage.getItem('shiba_cookie_consent')) {
        const b = document.getElementById('cookie-consent-banner');
        if (b) b.style.display = 'flex';
    }
});
function acceptCookies() {
    localStorage.setItem('shiba_cookie_consent', 'true');
    const b = document.getElementById('cookie-consent-banner');
    if (b) b.style.display = 'none';
}
</script>

</body>
</html>
