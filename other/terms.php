<?php
/**
 * ShibaLingo - Terms of Service / Termini di Servizio (RU & IT)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Условия использования / Terms of Service';
$siteTitle = getSetting('site_title', 'ShibaLingo');
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($siteTitle) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐕</text></svg>">
    <style>
        .legal-container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .lang-tabs { display: flex; gap: 10px; margin-bottom: 24px; }
        .lang-tab-btn { padding: 10px 20px; border-radius: 12px; font-weight: 800; cursor: pointer; border: 2px solid var(--border-color); background: var(--card-bg); color: var(--text-color); transition: all 0.2s ease; }
        .lang-tab-btn.active { background: var(--primary); color: white; border-color: var(--primary-shadow); }
        .legal-content h2 { font-size: 1.35rem; font-weight: 800; margin: 28px 0 12px; color: var(--text-color); border-bottom: 2px solid var(--border-color); padding-bottom: 6px; }
        .legal-content p, .legal-content li { line-height: 1.6; color: var(--text-muted); margin-bottom: 12px; font-size: 0.95rem; }
        .legal-content ul { padding-left: 24px; margin-bottom: 16px; }
    </style>
</head>
<body style="background: var(--bg-main);">

<div style="background: var(--card-bg); border-bottom: 2px solid var(--border-color); padding: 16px 24px;">
    <div style="max-width: 900px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center;">
        <a href="index.php" style="display: flex; align-items: center; gap: 8px; text-decoration: none; font-size: 1.3rem; font-weight: 900; color: var(--primary);">
            <span>🐕</span> <span><?= e($siteTitle) ?></span>
        </a>
        <a href="index.php" class="btn-duo btn-outline" style="padding: 6px 14px; font-size: 0.85rem;">
            ← На главную
        </a>
    </div>
</div>

<div class="legal-container">
    <div class="card-duo" style="padding: 40px 32px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
            <div>
                <h1 style="font-size: 2rem; font-weight: 900; color: var(--text-color); margin-bottom: 4px;">
                    ⚖️ Условия использования
                </h1>
                <p style="color: var(--text-muted); font-size: 0.9rem;">
                    Редакция от: <?= date('d.m.Y') ?>
                </p>
            </div>

            <div class="lang-tabs">
                <button class="lang-tab-btn active" onclick="switchLegalLang('ru')">🇷🇺 Русский</button>
                <button class="lang-tab-btn" onclick="switchLegalLang('it')">🇮🇹 Italiano</button>
            </div>
        </div>

        <!-- Russian Version -->
        <div id="legal-ru" class="legal-content">
            <h2>1. Принятие условий</h2>
            <p>
                Регистрируясь или используя платформу <strong><?= e($siteTitle) ?></strong>, вы подтверждаете свое полное и безоговорочное согласие с настоящими Условиями использования.
            </p>

            <h2>2. Описание сервиса</h2>
            <p>
                <?= e($siteTitle) ?> — это интерактивная платформа для изучения иностранных языков и вымышленного языка Vladikish с элементами геймификации, системой питомца Тамагочи и искусственным интеллектом на базе NVIDIA NIM.
            </p>

            <h2>3. Виртуальная валюта и предметы</h2>
            <p>
                Все внутриигровые ценности (Кристаллы 💎, Жизни ❤️, Бустеры ⚡ и Скины) являются виртуальными игровыми элементами, не имеют денежного эквивалента в реальном мире и не подлежат обмену на реальные деньги. Платформа не принимает реальные платежи.
            </p>

            <h2>4. Правила поведения и сообщество</h2>
            <p>Пользователям запрещается:</p>
            <ul>
                <li>Публиковать оскорбительные, нецензурные или нарушающие законодательство материалы при предложении сленга в Living Conlang.</li>
                <li>Использовать автоматизированные скрипты или ботов для искусственной накрутки опыта (XP) в таблице лидеров.</li>
                <li>Предпринимать попытки взлома или нарушения стабильности работы сервера.</li>
            </ul>

            <h2>5. Интеллектуальная собственность</h2>
            <p>
                Все материалы платформы, включая художественный образ маскота Сибы, грамматику и словарь языка Vladikish, графику и звуковые эффекты, охраняются авторским правом.
            </p>
        </div>

        <!-- Italian Version -->
        <div id="legal-it" class="legal-content" style="display: none;">
            <h2>1. Accettazione dei Termini</h2>
            <p>
                Registrandosi o accedendo alla piattaforma <strong><?= e($siteTitle) ?></strong>, l'utente accetta integralmente e senza riserve i presenti Termini di Servizio.
            </p>

            <h2>2. Descrizione del Servizio</h2>
            <p>
                <?= e($siteTitle) ?> è un'applicazione educativa gamificata per l'apprendimento delle lingue e del linguaggio conlang "Vladikish", arricchita da funzionalità interattive come il Tamagotchi e l'assistente basato su NVIDIA NIM AI.
            </p>

            <h2>3. Valuta Virtuale ed Elementi di Gioco</h2>
            <p>
                Tutti i beni virtuali (gemme 💎, cuori ❤️, potenziamenti e costumi estetici) sono esclusivamente oggetti di gioco virtuali senza alcun valore monetario nel mondo reale. Non è presente alcun sistema di acquisto in denaro reale.
            </p>

            <h2>4. Condotta dell'utente</h2>
            <p>È severamente vietato:</p>
            <ul>
                <li>Inviare contenuti offensivi o inappropriati nelle proposte della community o nella chat.</li>
                <li>Utilizzare bot o sistemi automatizzati per alterare i punteggi XP nelle classifiche di lega.</li>
                <li>Tentare di compromettere la sicurezza o l'infrastruttura del sito.</li>
            </ul>

            <h2>5. Proprietà Intellettuale</h2>
            <p>
                Tutti i contenuti multimediali, il design della mascotte Shiba e la linguistica del Vladikish sono protetti dalle leggi vigenti sul diritto d'autore.
            </p>
        </div>
    </div>
</div>

<script>
function switchLegalLang(lang) {
    document.querySelectorAll('.lang-tab-btn').forEach(btn => btn.classList.remove('active'));
    if (lang === 'ru') {
        document.getElementById('legal-ru').style.display = 'block';
        document.getElementById('legal-it').style.display = 'none';
        event.target.classList.add('active');
    } else {
        document.getElementById('legal-ru').style.display = 'none';
        document.getElementById('legal-it').style.display = 'block';
        event.target.classList.add('active');
    }
}
</script>

</body>
</html>
