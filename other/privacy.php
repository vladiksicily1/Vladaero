<?php
/**
 * ShibaLingo - Privacy Policy / Informativa sulla Privacy (RU & IT)
 * Compliant with GDPR (EU 2016/679) & 152-ФЗ
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Политика конфиденциальности / Privacy Policy';
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
        .legal-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .lang-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
        }
        .lang-tab-btn {
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            border: 2px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            transition: all 0.2s ease;
        }
        .lang-tab-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary-shadow);
        }
        .legal-content h2 {
            font-size: 1.35rem;
            font-weight: 800;
            margin: 28px 0 12px;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 6px;
        }
        .legal-content p, .legal-content li {
            line-height: 1.6;
            color: var(--text-muted);
            margin-bottom: 12px;
            font-size: 0.95rem;
        }
        .legal-content ul {
            padding-left: 24px;
            margin-bottom: 16px;
        }
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
                    📜 Политика конфиденциальности
                </h1>
                <p style="color: var(--text-muted); font-size: 0.9rem;">
                    Редакция от: <?= date('d.m.Y') ?> | GDPR & 152-ФЗ Compliant
                </p>
            </div>

            <div class="lang-tabs">
                <button class="lang-tab-btn active" onclick="switchLegalLang('ru')">🇷🇺 Русский</button>
                <button class="lang-tab-btn" onclick="switchLegalLang('it')">🇮🇹 Italiano</button>
            </div>
        </div>

        <!-- Russian Version -->
        <div id="legal-ru" class="legal-content">
            <h2>1. Общие положения</h2>
            <p>
                Настоящая Политика конфиденциальности определяет порядок обработки и защиты персональных данных пользователей веб-платформы <strong><?= e($siteTitle) ?></strong> (далее — «Сервис»). Мы уважаем право на неприкосновенность частной жизни и соблюдаем Общий регламент по защите данных ЕС (GDPR, Регламент 2016/679) и Федеральный закон РФ № 152-ФЗ «О персональных данных».
            </p>

            <h2>2. Какие данные мы собираем</h2>
            <p>При использовании Сервиса мы можем собирать следующие категории информации:</p>
            <ul>
                <li><strong>Учетные данные:</strong> логин (имя пользователя), адрес электронной почты и зашифрованный пароль (bcrypt-хеш).</li>
                <li><strong>Прогресс обучения:</strong> набранные очки опыта (XP), длительность стрика, количество кристаллов и жизней, пройденные интерактивные уроки.</li>
                <li><strong>Диалоги с AI-маскотом:</strong> текстовые сообщения, отправленные в учебный чат с Сиба-сэнсэем для генерации обучающих подсказок.</li>
                <li><strong>Технические данные и Cookie:</strong> IP-адрес, тип браузера, выбранная языковая локаль и тема оформления (светлая/темная) для корректной работы интерфейса.</li>
            </ul>

            <h2>3. Цели обработки данных</h2>
            <p>Сбор и обработка информации осуществляются исключительно в целях:</p>
            <ul>
                <li>Предоставления персонализированного доступа к учебной программе и геймификации.</li>
                <li>Генерации ответов AI-тьютора через защищенный API NVIDIA NIM.</li>
                <li>Сохранения прогресса в Лигах и Таблице лидеров.</li>
                <li>Обеспечения безопасности учетных записей и предотвращения несанкционированного доступа.</li>
            </ul>

            <h2>4. Использование искусственного интеллекта и сторонних API</h2>
            <p>
                Для работы AI-чата и генерации уроков Сервис взаимодействует с API <strong>NVIDIA NIM (build.nvidia.com)</strong>. В запросы к нейросети передаются только учебные фразы без указания ваших персональных идентифицирующих данных (email, реальное имя, пароли никогда не передаются сторонним сервисам).
            </p>

            <h2>5. Использование файлов Cookie</h2>
            <p>
                Сервис использует технические Cookie-файлы исключительно для поддержания вашей пользовательской сессии (вход в аккаунт) и сохранения пользовательских настроек (тема, язык). Мы не используем сторонние рекламные трекеры и не продаем ваши данные третьим лицам.
            </p>

            <h2>6. Права пользователя (GDPR / 152-ФЗ)</h2>
            <p>Каждый зарегистрированный пользователь имеет право:</p>
            <ul>
                <li><strong>Право на доступ и переносимость:</strong> просматривать свои данные и статистику в Профиле.</li>
                <li><strong>Право на исправление:</strong> изменять логин, фото профиля и пароль.</li>
                <li><strong>Право на забвение (полное удаление):</strong> безвозвратно удалить свой аккаунт и всю связанную историю обучения в любой момент через страницу <a href="delete_account.php" style="color: var(--danger); font-weight: 700;">Удаление аккаунта</a>.</li>
            </ul>

            <h2>7. Контакты администрации</h2>
            <p>
                По любым вопросам, касающимся обработки ваших данных или реализации прав, вы можете обратиться к администрации Сервиса через панель обратной связи или по адресу: <code>privacy@shibalingo.local</code>.
            </p>
        </div>

        <!-- Italian Version -->
        <div id="legal-it" class="legal-content" style="display: none;">
            <h2>1. Disposizioni Generali</h2>
            <p>
                La presente Informativa sulla Privacy descrive le modalità di raccolta, utilizzo e protezione dei dati personali degli utenti della piattaforma <strong><?= e($siteTitle) ?></strong> (di seguito "il Servizio"). Rispettiamo il diritto alla privacy e operiamo in piena conformità con il Regolamento Generale sulla Protezione dei Dati dell'Unione Europea (GDPR, Regolamento UE 2016/679).
            </p>

            <h2>2. Quali dati raccogliamo</h2>
            <p>Durante l'utilizzo del Servizio, possiamo raccogliere le seguenti categorie di dati:</p>
            <ul>
                <li><strong>Dati dell'account:</strong> nome utente, indirizzo e-mail e password crittografata (tramite algoritmo di hashing sicuro bcrypt).</li>
                <li><strong>Dati di apprendimento e progressi:</strong> punti esperienza (XP), giorni di serie (streak), gemme virtuali, cuori e cronologia delle lezioni completate.</li>
                <li><strong>Interazioni con l'AI:</strong> messaggi scambiati nella chat didattica con Shiba-sensei per la correzione grammaticale.</li>
                <li><strong>Dati tecnici e Cookie:</strong> indirizzo IP, tipo di browser, lingua selezionata e preferenze del tema (chiaro/scuro).</li>
            </ul>

            <h2>3. Finalità del trattamento</h2>
            <p>I dati raccolti vengono trattati per le seguenti finalità:</p>
            <ul>
                <li>Fornire accesso alle lezioni interattive e alle funzionalità di apprendimento linguistico.</li>
                <li>Consentire l'interazione con il tutor basato su intelligenza artificiale tramite NVIDIA NIM API.</li>
                <li>Gestire le classifiche di lega e i premi di gioco.</li>
                <li>Garantire la sicurezza e la stabilità dell'applicazione.</li>
            </ul>

            <h2>4. Intelligenza Artificiale e Servizi Terzi</h2>
            <p>
                Il Servizio utilizza le API di <strong>NVIDIA NIM (build.nvidia.com)</strong> per l'elaborazione del linguaggio naturale e la generazione delle lezioni. Nessun dato identificativo sensibile (password, e-mail) viene mai inviato o condiviso con terze parti.
            </p>

            <h2>5. Utilizzo dei Cookie</h2>
            <p>
                Utilizziamo esclusivamente cookie tecnici strettamente necessari per mantenere la sessione di accesso attiva e memorizzare le preferenze dell'interfaccia. Non utilizziamo cookie di profilazione pubblicitaria né traccianti di terze parti a scopo commerciale.
            </p>

            <h2>6. Diritti dell'utente (GDPR)</h2>
            <p>In conformità agli articoli 15-22 del GDPR, l'utente ha diritto di:</p>
            <ul>
                <li><strong>Accesso e rettifica:</strong> consultare e aggiornare i propri dati in qualsiasi momento dal Profilo.</li>
                <li><strong>Diritto alla cancellazione (diritto all'oblio):</strong> eliminare definitivamente il proprio account e tutti i dati correlati tramite l'apposita funzione su <a href="delete_account.php" style="color: var(--danger); font-weight: 700;">Eliminazione Account</a>.</li>
                <li><strong>Revoca del consenso:</strong> disconnettersi ed eliminare la sessione in qualsiasi momento.</li>
            </ul>

            <h2>7. Contatti del Titolare</h2>
            <p>
                Per qualsiasi richiesta relativa all'esercizio dei diritti di protezione dei dati, è possibile contattare l'amministrazione all'indirizzo email: <code>privacy@shibalingo.local</code>.
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
