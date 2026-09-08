<?php
/**
 * Admin AI Universal Content Generator Studio & Playground
 */

$adminTitle = 'AI Генератор Контента & Студия';
require_once __DIR__ . '/header.php';

$db = getDb();
$skills = $db->query("SELECT s.*, l.flag as lang_flag, l.name as lang_name FROM " . tbl('skills') . " s JOIN " . tbl('languages') . " l ON s.language_code = l.code ORDER BY s.language_code ASC, s.order_num ASC")->fetchAll();
$languages = $db->query("SELECT * FROM " . tbl('languages') . " ORDER BY is_conlang DESC, code ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>🤖</span> <span>AI Фабрика & Студия генерации контента</span>
        </h1>
        <p style="color: var(--text-muted);">
            Мгновенная генерация любых сущностей сайта через OpenAI / NVIDIA NIM API с авто-сохранением в БД
        </p>
    </div>

    <a href="ai_prompts.php" class="btn-duo btn-outline" style="padding: 8px 16px; font-size: 0.9rem;">
        ⚙️ Настройки API & Модели
    </a>
</div>

<!-- Tabs Navigation -->
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;">
    <button class="btn-duo btn-primary studio-tab-btn" onclick="openStudioTab('lessons')">📚 Уроки</button>
    <button class="btn-duo btn-outline studio-tab-btn" onclick="openStudioTab('stories')">📖 Истории</button>
    <button class="btn-duo btn-outline studio-tab-btn" onclick="openStudioTab('dictionary')">📖 Словарь</button>
    <button class="btn-duo btn-outline studio-tab-btn" onclick="openStudioTab('grammar')">📐 Грамматика</button>
    <button class="btn-duo btn-outline studio-tab-btn" onclick="openStudioTab('quests')">🎯 Квесты</button>
    <button class="btn-duo btn-outline studio-tab-btn" onclick="openStudioTab('achievements')">🏅 Достижения</button>
    <button class="btn-duo btn-outline studio-tab-btn" onclick="openStudioTab('shop')">🛍️ Товары & Скины</button>
    <button class="btn-duo btn-outline studio-tab-btn" onclick="openStudioTab('promocodes')">🎁 Промокоды</button>
    <button class="btn-duo btn-secondary studio-tab-btn" onclick="openStudioTab('playground')">🧪 AI Playground</button>
</div>

<!-- Tab: Lessons Generator -->
<div id="tab-lessons" class="studio-tab-content card-duo">
    <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 16px;">📚 Генератор интерактивных уроков</h3>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
        <div>
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Раздел навыка (Skill):</label>
            <select id="gen-lesson-skill" class="chat-input">
                <?php foreach ($skills as $sk): ?>
                    <option value="<?= $sk['id'] ?>" data-lang="<?= e($sk['language_code']) ?>">
                        <?= $sk['lang_flag'] ?> <?= e($sk['title']) ?> (<?= e($sk['lang_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема урока:</label>
            <input type="text" id="gen-lesson-topic" class="chat-input" placeholder="Например: Знакомство в аэропорту, Еда и напитки...">
        </div>
    </div>
    <button type="button" class="btn-duo btn-primary" style="padding: 12px 24px;" onclick="runStudioGeneration('generate_lesson', { skill_id: document.getElementById('gen-lesson-skill').value, topic: document.getElementById('gen-lesson-topic').value, lang: document.getElementById('gen-lesson-skill').options[document.getElementById('gen-lesson-skill').selectedIndex].getAttribute('data-lang') })">
        ✨ Сгенерировать и добавить урок
    </button>
</div>

<!-- Tab: Stories Generator -->
<div id="tab-stories" class="studio-tab-content card-duo" style="display: none;">
    <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 16px;">📖 Генератор сюжетных интерактивных историй</h3>
    <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 16px; margin-bottom: 16px;">
        <div>
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Язык:</label>
            <select id="gen-story-lang" class="chat-input">
                <?php foreach ($languages as $l): ?>
                    <option value="<?= $l['code'] ?>"><?= $l['flag'] ?> <?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Сюжет / Тема истории:</label>
            <input type="text" id="gen-story-topic" class="chat-input" placeholder="Тайна старого маяка, Встреча в космическом кафе...">
        </div>
        <div>
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Уровень сложности:</label>
            <select id="gen-story-level" class="chat-input">
                <option value="1">Уровень 1 (A1)</option>
                <option value="2">Уровень 2 (A2)</option>
                <option value="3">Уровень 3 (B1)</option>
            </select>
        </div>
    </div>
    <button type="button" class="btn-duo btn-primary" style="padding: 12px 24px;" onclick="runStudioGeneration('generate_story', { lang: document.getElementById('gen-story-lang').value, topic: document.getElementById('gen-story-topic').value, level: parseInt(document.getElementById('gen-story-level').value) })">
        ✨ Сгенерировать историю
    </button>
</div>

<!-- Tab: Dictionary Generator -->
<div id="tab-dictionary" class="studio-tab-content card-duo" style="display: none;">
    <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 16px;">📖 Массовая генерация слов для словаря Conlang</h3>
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 16px;">
        <div>
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тематическая область:</label>
            <input type="text" id="gen-dict-topic" class="chat-input" placeholder="Космос, Погода, Музыка, Эмоции...">
        </div>
        <div>
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Количество слов:</label>
            <select id="gen-dict-count" class="chat-input">
                <option value="5">5 слов</option>
                <option value="10" selected>10 слов</option>
                <option value="20">20 слов</option>
                <option value="30">30 слов</option>
            </select>
        </div>
    </div>
    <button type="button" class="btn-duo btn-primary" style="padding: 12px 24px;" onclick="runStudioGeneration('generate_words', { topic: document.getElementById('gen-dict-topic').value, count: parseInt(document.getElementById('gen-dict-count').value) })">
        ✨ Сгенерировать и записать слова в словарь
    </button>
</div>

<!-- Tab: Grammar Generator -->
<div id="tab-grammar" class="studio-tab-content card-duo" style="display: none;">
    <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 16px;">📐 Генератор грамматических правил</h3>
    <div style="margin-bottom: 16px;">
        <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема грамматики:</label>
        <input type="text" id="gen-gram-topic" class="chat-input" placeholder="Прошедшее время глаголов, Прилагательные и степени сравнения...">
    </div>
    <button type="button" class="btn-duo btn-primary" style="padding: 12px 24px;" onclick="runStudioGeneration('generate_grammar', { topic: document.getElementById('gen-gram-topic').value, count: 2 })">
        ✨ Сгенерировать правила
    </button>
</div>

<!-- Tab: Quests Generator -->
<div id="tab-quests" class="studio-tab-content card-duo" style="display: none;">
    <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 16px;">🎯 Генератор ежедневных квестов</h3>
    <div style="margin-bottom: 16px;">
        <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Количество квестов для генерации:</label>
        <select id="gen-quest-count" class="chat-input" style="max-width: 250px;">
            <option value="3">3 квеста</option>
            <option value="5">5 квестов</option>
        </select>
    </div>
    <button type="button" class="btn-duo btn-primary" style="padding: 12px 24px;" onclick="runStudioGeneration('generate_quests', { count: parseInt(document.getElementById('gen-quest-count').value) })">
        ✨ Сгенерировать квесты
    </button>
</div>

<!-- Tab: Achievements Generator -->
<div id="tab-achievements" class="studio-tab-content card-duo" style="display: none;">
    <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 16px;">🏅 Генератор достижений и бейджей</h3>
    <div style="margin-bottom: 16px;">
        <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тематика ачивок:</label>
        <input type="text" id="gen-ach-theme" class="chat-input" placeholder="Магия conlang, Непобедимый стрик, Дуэлянт...">
    </div>
    <button type="button" class="btn-duo btn-primary" style="padding: 12px 24px;" onclick="runStudioGeneration('generate_achievements', { theme: document.getElementById('gen-ach-theme').value, count: 3 })">
        ✨ Сгенерировать достижения
    </button>
</div>

<!-- Tab: Shop Generator -->
<div id="tab-shop" class="studio-tab-content card-duo" style="display: none;">
    <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 16px;">🛍️ Генератор товаров магазина и скинов Сибы</h3>
    <div style="margin-bottom: 16px;">
        <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема коллекции:</label>
        <input type="text" id="gen-shop-theme" class="chat-input" placeholder="Киберпанк, Самураи, Зимний фестиваль, Хэллоуин...">
    </div>
    <button type="button" class="btn-duo btn-primary" style="padding: 12px 24px;" onclick="runStudioGeneration('generate_shop_items', { theme: document.getElementById('gen-shop-theme').value, count: 3 })">
        ✨ Сгенерировать товары
    </button>
</div>

<!-- Tab: Promocodes Generator -->
<div id="tab-promocodes" class="studio-tab-content card-duo" style="display: none;">
    <h3 style="font-size: 1.3rem; font-weight: 800; margin-bottom: 16px;">🎁 Генератор промокодов</h3>
    <div style="margin-bottom: 16px;">
        <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Повод / Ивент:</label>
        <input type="text" id="gen-promo-theme" class="chat-input" placeholder="Запуск Vladikish 2.0, Праздничный бонус, Стрим с Сибой...">
    </div>
    <button type="button" class="btn-duo btn-primary" style="padding: 12px 24px;" onclick="runStudioGeneration('generate_promocodes', { theme: document.getElementById('gen-promo-theme').value, count: 3 })">
        ✨ Сгенерировать промокоды
    </button>
</div>

<!-- Tab: Playground -->
<div id="tab-playground" class="studio-tab-content card-duo" style="display: none;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3 style="font-size: 1.3rem; font-weight: 800;">🧪 Интерактивный AI Playground (Тестирование ответов и задержки)</h3>
        <span id="playground-ping-badge" class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);">Готов к тесту</span>
    </div>
    <div style="margin-bottom: 12px;">
        <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тестовый запрос:</label>
        <textarea id="playground-prompt-input" rows="3" class="chat-input">Привет! Назови 3 самых красивых слова на Vladikish и их перевод на русский.</textarea>
    </div>
    <button type="button" class="btn-duo btn-secondary" style="padding: 10px 20px;" onclick="runPlaygroundTest()">
        🚀 Отправить запрос (Ping Test)
    </button>

    <div id="playground-output-box" style="display: none; margin-top: 16px; background: var(--bg-main); padding: 16px; border-radius: 12px; border: 2px solid var(--border-color);">
        <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 0.85rem; margin-bottom: 8px;">
            <span>Ответ модели:</span>
            <span id="playground-latency-label" style="color: var(--secondary);">0 ms</span>
        </div>
        <div id="playground-response-text" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.5;"></div>
    </div>
</div>

<!-- Global Studio Generation Result Box -->
<div id="studio-result-card" class="card-duo anim-bounce" style="display: none; margin-top: 24px; border-color: var(--primary);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <h4 id="studio-result-title" style="font-size: 1.1rem; font-weight: 800; color: var(--primary);">🎉 Результат генерации</h4>
        <button onclick="document.getElementById('studio-result-card').style.display='none'" style="background: none; border: none; cursor: pointer; font-size: 1.2rem;">✕</button>
    </div>
    <div id="studio-result-body" style="font-size: 0.95rem; color: var(--text-color); margin-bottom: 12px;"></div>
    <div id="studio-result-raw" style="background: var(--bg-main); padding: 12px; border-radius: 10px; font-family: monospace; font-size: 0.8rem; max-height: 250px; overflow-y: auto;"></div>
</div>

<script>
function openStudioTab(tabName) {
    document.querySelectorAll('.studio-tab-btn').forEach(btn => {
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-outline');
    });
    event.target.classList.remove('btn-outline');
    event.target.classList.add('btn-primary');

    document.querySelectorAll('.studio-tab-content').forEach(c => c.style.display = 'none');
    const target = document.getElementById('tab-' + tabName);
    if (target) target.style.display = 'block';
}

async function runStudioGeneration(endpoint, payload) {
    const resultCard = document.getElementById('studio-result-card');
    const resultTitle = document.getElementById('studio-result-title');
    const resultBody = document.getElementById('studio-result-body');
    const resultRaw = document.getElementById('studio-result-raw');

    resultCard.style.display = 'block';
    resultTitle.style.color = 'var(--secondary)';
    resultTitle.textContent = '⏳ ИИ генерирует контент и записывает в БД...';
    resultBody.innerHTML = 'Пожалуйста, подождите несколько секунд...';
    resultRaw.textContent = JSON.stringify(payload, null, 2);

    try {
        const res = await fetch(`../api/ai.php?action=${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await res.json();

        if (data.success) {
            resultTitle.style.color = 'var(--primary)';
            resultTitle.textContent = '🎉 Успешно создано и сохранено в базу данных!';
            resultBody.innerHTML = `<strong>Добавлено записей:</strong> ${data.added_count ?? 1}<br><span style="color: var(--text-muted); font-size: 0.85rem;">Данные сразу доступны в соответствующем разделе сайта и админки.</span>`;
            resultRaw.textContent = JSON.stringify(data, null, 2);
        } else {
            resultTitle.style.color = 'var(--danger)';
            resultTitle.textContent = '✕ Ошибка при генерации';
            resultBody.textContent = data.error || 'Неизвестная ошибка';
            resultRaw.textContent = JSON.stringify(data, null, 2);
        }
    } catch (e) {
        resultTitle.style.color = 'var(--danger)';
        resultTitle.textContent = '✕ Ошибка сетевого запроса';
        resultBody.textContent = 'Не удалось связаться с API сервером.';
    }
}

async function runPlaygroundTest() {
    const prompt = document.getElementById('playground-prompt-input').value.trim();
    const box = document.getElementById('playground-output-box');
    const textDiv = document.getElementById('playground-response-text');
    const latencyLabel = document.getElementById('playground-latency-label');
    const badge = document.getElementById('playground-ping-badge');

    if (!prompt) return;

    box.style.display = 'block';
    textDiv.innerHTML = '<span style="color: var(--text-muted);">Запрос отправлен...</span>';
    badge.textContent = 'Ожидание ответа...';

    try {
        const res = await fetch('../api/ai.php?action=ai_ping', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ prompt: prompt })
        });

        const data = await res.json();
        if (data.success) {
            latencyLabel.textContent = `⚡ ${data.latency_ms} ms (${data.model})`;
            textDiv.textContent = data.reply;
            badge.textContent = `✓ ${data.latency_ms} ms`;
        } else {
            latencyLabel.textContent = 'Ошибка';
            textDiv.innerHTML = `<span style="color: var(--danger);">${data.error}</span>`;
            badge.textContent = '✕ Ошибка';
        }
    } catch (e) {
        textDiv.innerHTML = '<span style="color: var(--danger);">Ошибка запроса к серверу.</span>';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
