<?php
/**
 * ShibaLingo - Offline Rule-Based Linguistic Translator
 * 100% Real deterministic translator without external AI or network APIs.
 * Supports:
 * - 🇷🇺 Русский <-> 🐕 ShibaLingo
 * - 🇬🇧 English <-> 🐕 ShibaLingo
 * - 🇮🇹 Italiano <-> 🐕 ShibaLingo
 */

$pageTitle = 'Переводчик ShibaLingo (Офлайн)';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/translator_engine.php';

$mergedLexicon = RuleBasedTranslator::getMergedLexicon();
?>

<div style="max-width: 980px; margin: 0 auto; padding-bottom: 40px;">
    <!-- Page Title & Header -->
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="font-size: 1.85rem; font-weight: 900; display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                <span>🌐</span> Переводчик ShibaLingo
                <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow); font-size: 0.8rem; padding: 4px 10px; border-radius: 12px; font-weight: 800;">
                    ⚡ 100% Офлайн движок
                </span>
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Мгновенный двусторонний перевод между <strong>Русским 🇷🇺</strong>, <strong>English 🇬🇧</strong>, <strong>Italiano 🇮🇹</strong> и <strong>ShibaLingo 🐕</strong> на основе правил грамматики и живого словаря.
            </p>
        </div>
    </div>

    <!-- Quick Mode Tabs (3 Dedicated Language Pairs) -->
    <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        <button id="tab-ru-shiba" class="btn-duo btn-primary" style="padding: 10px 18px; font-size: 0.95rem; border-radius: 14px;" onclick="setMode('ru', 'vladikish')">
            🇷🇺 Русский ⇄ 🐕 ShibaLingo
        </button>
        <button id="tab-en-shiba" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; border-radius: 14px;" onclick="setMode('en', 'vladikish')">
            🇬🇧 English ⇄ 🐕 ShibaLingo
        </button>
        <button id="tab-it-shiba" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; border-radius: 14px;" onclick="setMode('it', 'vladikish')">
            🇮🇹 Italiano ⇄ 🐕 ShibaLingo
        </button>
    </div>

    <!-- Quick Preset Phrases Pills (Dynamically populated by JS based on selected pair) -->
    <div style="margin-bottom: 20px;">
        <div style="font-size: 0.85rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.5px;">
            💡 Быстрые фразы для проверки перевода:
        </div>
        <div id="quick-phrases-container" style="display: flex; gap: 8px; flex-wrap: wrap;">
            <!-- Rendered by JS -->
        </div>
    </div>

    <!-- Main Translator Card -->
    <div class="card-duo anim-bounce" style="padding: 24px; margin-bottom: 24px;">
        <!-- Top Toolbar: Language Selector & Swap Button -->
        <div style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 16px; align-items: center; margin-bottom: 18px;">
            <div>
                <label style="font-size: 0.8rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 4px;">Язык оригинала:</label>
                <select id="source-lang" class="chat-input" style="margin-bottom: 0; font-weight: 700;" onchange="onLangChange()">
                    <option value="ru" selected>🇷🇺 Русский</option>
                    <option value="vladikish">🐕 ShibaLingo (Vladikish)</option>
                    <option value="en">🇬🇧 English</option>
                    <option value="it">🇮🇹 Italiano</option>
                </select>
            </div>

            <div style="text-align: center; padding-top: 18px;">
                <button type="button" class="btn-duo btn-outline" onclick="swapLanguages()" style="padding: 10px 16px; border-radius: 50%; font-size: 1.2rem; line-height: 1;" title="Поменять языки местами (⇄)">
                    ⇄
                </button>
            </div>

            <div>
                <label style="font-size: 0.8rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 4px;">Язык перевода:</label>
                <select id="target-lang" class="chat-input" style="margin-bottom: 0; font-weight: 700;" onchange="onLangChange()">
                    <option value="vladikish" selected>🐕 ShibaLingo (Vladikish)</option>
                    <option value="ru">🇷🇺 Русский</option>
                    <option value="en">🇬🇧 English</option>
                    <option value="it">🇮🇹 Italiano</option>
                </select>
            </div>
        </div>

        <!-- Input & Output Textareas -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <!-- Source Input -->
            <div style="position: relative;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 800; font-size: 0.85rem;">Исходный текст:</label>
                    <button type="button" onclick="clearSourceText()" style="background: none; border: none; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); cursor: pointer;" title="Очистить поле">
                        ✕ Очистить
                    </button>
                </div>
                <textarea id="source-text" class="chat-input" rows="7" placeholder="Введите фразу или текст..." style="font-size: 1.1rem; line-height: 1.5; resize: vertical;" oninput="onSourceInput()"></textarea>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px; font-size: 0.8rem; color: var(--text-muted);">
                    <span id="char-counter">0 символов</span>
                    <button type="button" class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.8rem; border-radius: 8px;" onclick="speakSourceText()">
                        🔊 Озвучить
                    </button>
                </div>
            </div>

            <!-- Target Output -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 800; font-size: 0.85rem;">Результат перевода:</label>
                    <button type="button" onclick="copyTranslation()" id="btn-copy" style="background: none; border: none; font-size: 0.8rem; font-weight: 700; color: var(--primary); cursor: pointer;" title="Скопировать в буфер">
                        📋 Скопировать
                    </button>
                </div>
                <div id="target-text" class="chat-input" style="min-height: 172px; background: var(--bg-main); font-size: 1.15rem; font-weight: 700; line-height: 1.5; white-space: pre-wrap; color: var(--text-main); border: 2px solid var(--border-color); overflow-y: auto;"></div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                    <span id="translation-status" style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted);">
                        ⚡ Мгновенный лингвистический перевод
                    </span>
                    <button type="button" class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.8rem; border-radius: 8px;" onclick="speakTranslation()">
                        🔊 Озвучить перевод
                    </button>
                </div>
            </div>
        </div>

        <!-- Action Footer -->
        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1.5px solid var(--border-color); padding-top: 16px;">
            <div style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: var(--text-muted);">
                <span>🐾</span> <span>Словарь синхронизирован с базой данных: <strong><?= count($mergedLexicon) ?></strong> слов</span>
            </div>
            <button type="button" class="btn-duo btn-primary" onclick="executeTranslation()" style="padding: 12px 32px; font-size: 1.05rem;">
                Перевести 🚀
            </button>
        </div>
    </div>

    <!-- Linguistic Breakdown & Word Analyzer Panel -->
    <div id="linguistic-panel" class="card-duo" style="display: none; padding: 24px; margin-bottom: 24px;">
        <h3 style="font-size: 1.15rem; font-weight: 900; display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
            <span>🔍</span> Морфологический разбор предложения
        </h3>
        <div id="tokens-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px;">
            <!-- Rendered by JS -->
        </div>
    </div>

    <!-- Dictionary Quick Reference Search -->
    <div class="card-duo" style="padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
            <h3 style="font-size: 1.2rem; font-weight: 900; display: flex; align-items: center; gap: 8px; margin-bottom: 0;">
                <span>📖</span> Быстрый поиск по словарю ShibaLingo
            </h3>
            <input type="text" id="dict-filter" class="chat-input" placeholder="Поиск слова или перевода..." style="max-width: 280px; margin-bottom: 0; padding: 8px 14px; font-size: 0.9rem;" oninput="filterDictionary()">
        </div>

        <div style="max-height: 280px; overflow-y: auto; border: 1.5px solid var(--border-color); border-radius: 14px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem; text-align: left;">
                <thead style="background: var(--bg-main); position: sticky; top: 0; z-index: 1;">
                    <tr style="border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 10px 14px;">ShibaLingo</th>
                        <th style="padding: 10px 14px;">Часть речи</th>
                        <th style="padding: 10px 14px;">Русский</th>
                        <th style="padding: 10px 14px;">English</th>
                        <th style="padding: 10px 14px;">Italiano</th>
                        <th style="padding: 10px 14px;">Произношение</th>
                        <th style="padding: 10px 14px; text-align: right;">Вставить</th>
                    </tr>
                </thead>
                <tbody id="dict-table-body">
                    <?php foreach ($mergedLexicon as $w): ?>
                        <tr class="dict-row" style="border-bottom: 1px solid var(--border-color);" data-vk="<?= e(strtolower($w['vk'])) ?>" data-ru="<?= e(strtolower(implode(' ', $w['ru']))) ?>" data-en="<?= e(strtolower(implode(' ', $w['en']))) ?>" data-it="<?= e(strtolower(implode(' ', $w['it'] ?? []))) ?>">
                            <td style="padding: 8px 14px; font-weight: 900; color: var(--primary);"><?= e($w['vk']) ?></td>
                            <td style="padding: 8px 14px;"><span class="badge-tag" style="background: var(--bg-main); font-size: 0.75rem;"><?= e($w['pos']) ?></span></td>
                            <td style="padding: 8px 14px; font-weight: 600;"><?= e(implode(', ', $w['ru'])) ?></td>
                            <td style="padding: 8px 14px; color: var(--text-muted);"><?= e(implode(', ', $w['en'])) ?></td>
                            <td style="padding: 8px 14px; color: var(--text-muted);"><?= e(implode(', ', $w['it'] ?? $w['ru'])) ?></td>
                            <td style="padding: 8px 14px; font-family: monospace; font-size: 0.85rem;">[<?= e($w['pr'] ?? $w['vk']) ?>]</td>
                            <td style="padding: 8px 14px; text-align: right;">
                                <button type="button" class="btn-duo btn-outline" style="padding: 3px 8px; font-size: 0.75rem; border-radius: 8px;" onclick="insertWord('<?= e($w['vk']) ?>')">
                                    + Вставить
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Mode presets and sample phrases for each language pair
const PRESETS = {
    'ru-vladikish': [
        { label: '🐕 Я полюбил собак.', text: 'Я полюбил собак.' },
        { label: '🐾 Красивые собаки бегут быстро.', text: 'Красивые собаки бегут быстро.' },
        { label: '✨ Собачка сияет.', text: 'Собачка сияет.' },
        { label: '🍖 Кушай хорошо!', text: 'Кушай хорошо!' },
        { label: '🆘 Помоги мне!', text: 'Помоги мне!' },
        { label: '🚫 Я не знаю.', text: 'Я не знаю.' },
        { label: '📖 Книга от Шибы.', text: 'Книга от Шибы.' },
        { label: '☀️ Солнце светит в небе.', text: 'Солнце светит в небе.' },
        { label: '👋 Привет, друг!', text: 'Привет, друг!' }
    ],
    'en-vladikish': [
        { label: '🐕 I loved dogs.', text: 'I loved dogs.' },
        { label: '🐾 Beautiful dogs run quickly.', text: 'Beautiful dogs run quickly.' },
        { label: '🍖 Eat well!', text: 'Eat well!' },
        { label: '🆘 Help me!', text: 'Help me!' },
        { label: '🚫 I don\'t know.', text: 'I do not know.' },
        { label: '📖 Book of Shiba.', text: 'Book of Shiba.' },
        { label: '☀️ The stars shine in the sky.', text: 'The stars shine in the sky.' },
        { label: '👋 Hello, friend!', text: 'Hello, friend!' }
    ],
    'it-vladikish': [
        { label: '🐕 Ho amato i cani.', text: 'Ho amato i cani.' },
        { label: '🐾 I bei cani corrono rapidamente.', text: 'I bei cani corrono rapidamente.' },
        { label: '🍖 Mangia bene!', text: 'Mangia bene!' },
        { label: '🆘 Aiutami!', text: 'Aiutami!' },
        { label: '🚫 Non lo so.', text: 'Non so.' },
        { label: '📖 Libro di Shiba.', text: 'Libro di Shiba.' },
        { label: '☀️ Le stelle splendono nel cielo.', text: 'Le stelle splendono nel cielo.' },
        { label: '👋 Ciao, amico!', text: 'Ciao, amico!' }
    ],
    'vladikish-ru': [
        { label: '🐕 Barkas velo in aero.', text: 'Barkas velo in aero.' },
        { label: '✨ Kaelos velo.', text: 'Kaelos velo.' },
        { label: '❤️ Me toroti barka.', text: 'Me toroti barka.' },
        { label: '💡 Me saviti lingo.', text: 'Me saviti lingo.' },
        { label: '🚀 Vo-toro ti!', text: 'Vo-toro ti!' },
        { label: '🍖 Komu!', text: 'Komu!' },
        { label: '🆘 Helpu me!', text: 'Helpu me!' },
        { label: '⚡ Bone et rapide.', text: 'Bone et rapide.' },
        { label: '🐾 Barkita et zorita.', text: 'Barkita et zorita.' },
        { label: '📖 Mea libro de Shiba.', text: 'Mea libro de Shiba.' },
        { label: '🚫 Me no sabi.', text: 'Me no sabi.' },
        { label: '✨ Dankon plena!', text: 'Dankon plena!' },
        { label: '👋 Gis la revido!', text: 'Gis la revido!' }
    ]
};

let debounceTimer = null;

document.addEventListener('DOMContentLoaded', () => {
    updateModeTabs();
    renderQuickPhrases();
});

function setMode(from, to) {
    document.getElementById('source-lang').value = from;
    document.getElementById('target-lang').value = to;
    updateModeTabs();
    renderQuickPhrases();
    executeTranslation();
}

function onLangChange() {
    updateModeTabs();
    renderQuickPhrases();
    executeTranslation();
}

function updateModeTabs() {
    const from = document.getElementById('source-lang').value;
    const to = document.getElementById('target-lang').value;

    const btnRu = document.getElementById('tab-ru-shiba');
    const btnEn = document.getElementById('tab-en-shiba');
    const btnIt = document.getElementById('tab-it-shiba');

    btnRu.className = (from === 'ru' || to === 'ru') ? 'btn-duo btn-primary' : 'btn-duo btn-outline';
    btnEn.className = (from === 'en' || to === 'en') ? 'btn-duo btn-primary' : 'btn-duo btn-outline';
    btnIt.className = (from === 'it' || to === 'it') ? 'btn-duo btn-primary' : 'btn-duo btn-outline';
}

function renderQuickPhrases() {
    const from = document.getElementById('source-lang').value;
    const to = document.getElementById('target-lang').value;
    const container = document.getElementById('quick-phrases-container');
    container.innerHTML = '';

    const key = `${from}-${to}`;
    const fallbackKey = (from === 'vladikish') ? 'vladikish-ru' : `${from}-vladikish`;
    const list = PRESETS[key] || PRESETS[fallbackKey] || PRESETS['ru-vladikish'];

    list.forEach(p => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-duo btn-outline';
        btn.style.cssText = 'padding: 6px 12px; font-size: 0.85rem; border-radius: 20px;';
        btn.innerText = p.label;
        btn.onclick = () => {
            document.getElementById('source-text').value = p.text;
            document.getElementById('char-counter').innerText = `${p.text.length} символов`;
            executeTranslation();
        };
        container.appendChild(btn);
    });
}

function onSourceInput() {
    const text = document.getElementById('source-text').value;
    document.getElementById('char-counter').innerText = `${text.length} символов`;

    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        executeTranslation();
    }, 150);
}

async function executeTranslation() {
    const text = document.getElementById('source-text').value.trim();
    const from = document.getElementById('source-lang').value;
    const to = document.getElementById('target-lang').value;
    const targetEl = document.getElementById('target-text');
    const panel = document.getElementById('linguistic-panel');
    const tokensContainer = document.getElementById('tokens-container');

    if (!text) {
        targetEl.textContent = '';
        panel.style.display = 'none';
        return;
    }

    try {
        const response = await fetch('api/translate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text, from, to })
        });

        const res = await response.json();
        if (res.success) {
            targetEl.textContent = res.translated_text;

            // Render Tokens Breakdown
            if (res.tokens && res.tokens.length > 0) {
                tokensContainer.innerHTML = '';
                res.tokens.forEach(tok => {
                    const card = document.createElement('div');
                    card.style.cssText = `
                        background: var(--bg-main);
                        border: 1.5px solid var(--border-color);
                        border-radius: 12px;
                        padding: 12px 14px;
                        display: flex;
                        flex-direction: column;
                        gap: 4px;
                    `;
                    card.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 900; font-size: 1rem; color: var(--primary);">${tok.target}</span>
                            <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow); font-size: 0.7rem; padding: 2px 6px;">${tok.pos}</span>
                        </div>
                        <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-main);">
                            Исходное: <em>«${tok.source}»</em> [${tok.pronunciation}]
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                            Значение: ${tok.ru_desc}
                        </div>
                        ${tok.example ? `<div style="font-size: 0.75rem; color: var(--text-muted); font-style: italic; border-top: 1px dashed var(--border-color); padding-top: 4px; margin-top: 2px;">Пример: ${tok.example}</div>` : ''}
                    `;
                    tokensContainer.appendChild(card);
                });
                panel.style.display = 'block';
            } else {
                panel.style.display = 'none';
            }
        } else {
            targetEl.textContent = 'Ошибка перевода: ' + (res.error || 'Неизвестная ошибка');
            panel.style.display = 'none';
        }
    } catch (e) {
        console.error('Translation error', e);
    }
}

function swapLanguages() {
    const s = document.getElementById('source-lang');
    const t = document.getElementById('target-lang');
    const temp = s.value;
    s.value = t.value;
    t.value = temp;

    // Swap text as well if translated text exists
    const srcText = document.getElementById('source-text');
    const trgText = document.getElementById('target-text').textContent;
    if (trgText) {
        srcText.value = trgText;
    }

    updateModeTabs();
    renderQuickPhrases();
    executeTranslation();
}

function clearSourceText() {
    document.getElementById('source-text').value = '';
    document.getElementById('target-text').textContent = '';
    document.getElementById('char-counter').innerText = '0 символов';
    document.getElementById('linguistic-panel').style.display = 'none';
}

function copyTranslation() {
    const text = document.getElementById('target-text').textContent;
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('btn-copy');
        const oldText = btn.innerHTML;
        btn.innerHTML = '✓ Скопировано!';
        setTimeout(() => { btn.innerHTML = oldText; }, 2000);
    });
}

function speakSourceText() {
    const text = document.getElementById('source-text').value.trim();
    const from = document.getElementById('source-lang').value;
    if (text && typeof speakText === 'function') {
        speakText(text, from);
    }
}

function speakTranslation() {
    const text = document.getElementById('target-text').textContent.trim();
    const to = document.getElementById('target-lang').value;
    if (text && typeof speakText === 'function') {
        speakText(text, to);
    }
}

function insertWord(word) {
    const src = document.getElementById('source-text');
    src.value = (src.value.trim() ? src.value.trim() + ' ' : '') + word;
    onSourceInput();
}

function filterDictionary() {
    const q = document.getElementById('dict-filter').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.dict-row');
    rows.forEach(row => {
        const vk = row.getAttribute('data-vk') || '';
        const ru = row.getAttribute('data-ru') || '';
        const en = row.getAttribute('data-en') || '';
        const it = row.getAttribute('data-it') || '';
        if (!q || vk.includes(q) || ru.includes(q) || en.includes(q) || it.includes(q)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
