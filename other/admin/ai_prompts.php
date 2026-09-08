<?php
/**
 * Admin AI Prompts, Custom Base URL & Dynamic Model Fetcher (/v1/models)
 */

$adminTitle = 'AI Промпты & Модели (API / Base URL)';
require_once __DIR__ . '/header.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $baseUrl = trim($_POST['nvidia_base_url'] ?? 'https://integrate.api.nvidia.com/v1');
    $apiKey = trim($_POST['nvidia_api_key'] ?? '');
    $model = trim($_POST['nvidia_model'] ?? DEFAULT_NVIDIA_MODEL);
    $maxTokens = max(512, (int)($_POST['ai_max_tokens'] ?? 8192));
    $systemPromptCustom = trim($_POST['ai_system_prompt_override'] ?? '');
    $firecrawlKey = trim($_POST['firecrawl_api_key'] ?? '');

    if (empty($baseUrl)) {
        $baseUrl = 'https://integrate.api.nvidia.com/v1';
    }

    setSetting('nvidia_base_url', $baseUrl);
    setSetting('nvidia_api_key', $apiKey);
    setSetting('nvidia_model', $model);
    setSetting('ai_max_tokens', $maxTokens);
    setSetting('ai_system_prompt_override', $systemPromptCustom);
    setSetting('firecrawl_api_key', $firecrawlKey);
    $_SESSION['nvidia_api_key'] = $apiKey;

    $message = 'Настройки ИИ и максимальный лимит токенов успешно сохранены!';
}

$currentBaseUrl = getSetting('nvidia_base_url', 'https://integrate.api.nvidia.com/v1');
$currentKey = getSetting('nvidia_api_key', '');
$currentModel = getSetting('nvidia_model', DEFAULT_NVIDIA_MODEL);
$currentMaxTokens = (int)getSetting('ai_max_tokens', 8192);
$customPrompt = getSetting('ai_system_prompt_override', '');
$firecrawlKey = getSetting('firecrawl_api_key', '');
?>

<div style="max-width: 850px;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900;">🤖 Настройки ИИ, Base URL и Моделей</h1>
        <p style="color: var(--text-muted);">Кастомный Base URL, загрузка списка моделей через <code>/v1/models</code> и системные промпты</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
            ✓ <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" style="background: var(--danger-light); color: var(--danger-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
            ✕ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="ai-settings-form">
        <div class="card-duo">
            <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 16px;">🔑 Параметры подключения к API (OpenAI-совместимому)</h3>
            
            <!-- Base URL Field & Presets -->
            <div style="margin-bottom: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; font-size: 0.9rem;">Custom API Base URL:</label>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">Конечный путь без /chat/completions</span>
                </div>
                <input type="text" name="nvidia_base_url" id="api-base-url" value="<?= e($currentBaseUrl) ?>" class="chat-input" placeholder="https://integrate.api.nvidia.com/v1" required>
                
                <!-- Quick Preset URL Buttons -->
                <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); align-self: center;">Быстрый выбор:</span>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--border-color);" onclick="setPresetUrl('https://integrate.api.nvidia.com/v1')">
                        🟢 NVIDIA NIM
                    </button>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--border-color);" onclick="setPresetUrl('https://api.openai.com/v1')">
                        ⚪ OpenAI
                    </button>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--border-color);" onclick="setPresetUrl('https://openrouter.ai/api/v1')">
                        🔵 OpenRouter
                    </button>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--border-color);" onclick="setPresetUrl('https://api.groq.com/openai/v1')">
                        🟠 Groq
                    </button>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--border-color);" onclick="setPresetUrl('http://localhost:11434/v1')">
                        🦙 Ollama (Local)
                    </button>
                </div>
            </div>

            <!-- API Key Field -->
            <div style="margin-bottom: 18px;">
                <label style="font-weight: 700; font-size: 0.9rem; margin-bottom: 6px; display: block;">API Key (Bearer Token):</label>
                <div style="position: relative;">
                    <input type="password" name="nvidia_api_key" id="api-key-input" value="<?= e($currentKey) ?>" class="chat-input" placeholder="nvapi-xxxxxxxx или sk-xxxxxxxx" style="padding-right: 40px;">
                    <button type="button" onclick="togglePasswordVisibility()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 1.1rem;" title="Показать/скрыть">
                        👁️
                    </button>
                </div>
            </div>

            <!-- Model Selection & Fetch /v1/models -->
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; font-size: 0.9rem;">Идентификатор модели (Model ID):</label>
                    <button type="button" id="btn-fetch-models" class="btn-duo btn-secondary" style="padding: 4px 12px; font-size: 0.82rem;" onclick="fetchModelsList()">
                        🔄 Загрузить модели из API (/v1/models)
                    </button>
                </div>

                <input type="text" name="nvidia_model" id="api-model-input" value="<?= e($currentModel) ?>" class="chat-input" placeholder="meta/llama-3.3-70b-instruct" required style="margin-bottom: 8px;">

                <!-- Container for fetched models select dropdown -->
                <div id="fetched-models-container" style="display: none; background: var(--bg-main); padding: 12px; border-radius: 12px; border: 2px solid var(--border-color); margin-top: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 0.85rem; font-weight: 800; color: var(--text-color);" id="models-count-label">Доступные модели:</span>
                        <span id="models-fetch-badge" class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);">0 моделей</span>
                    </div>
                    <select id="models-select-dropdown" class="chat-input" style="margin-bottom: 6px;" onchange="applySelectedModel(this.value)">
                        <option value="">-- Выберите модель из списка --</option>
                    </select>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        Кликните по модели выше, чтобы автоматически подставить её в поле Model ID.
                    </div>
                </div>

                <!-- Status message box for AJAX operations -->
                <div id="fetch-status-msg" style="margin-top: 8px; font-size: 0.85rem; font-weight: 700; display: none;"></div>
            </div>

            <!-- Max Output Tokens Field & Presets -->
            <div style="margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; font-size: 0.9rem;">Максимум токенов ответа (Max Output Tokens):</label>
                    <span style="font-size: 0.8rem; color: var(--primary); font-weight: 800;">Максимум: 8192 – 32768</span>
                </div>
                <input type="number" name="ai_max_tokens" id="ai-max-tokens-inp" value="<?= $currentMaxTokens ?>" class="chat-input" min="512" max="65536" step="512" required>
                
                <!-- Quick Token Presets -->
                <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); align-self: center;">Быстрые пресеты:</span>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--border-color);" onclick="document.getElementById('ai-max-tokens-inp').value = 4096">
                        4096 (Стандарт)
                    </button>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--primary); background: var(--primary-light); color: var(--primary-shadow); font-weight: 800;" onclick="document.getElementById('ai-max-tokens-inp').value = 8192">
                        ⭐ 8192 (Максимум NIM / Llama 3.3)
                    </button>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--secondary); background: #e0f2fe; color: #0369a1; font-weight: 800;" onclick="document.getElementById('ai-max-tokens-inp').value = 16384">
                        🚀 16384 (DeepSeek R1 / V3)
                    </button>
                    <button type="button" class="badge-tag" style="cursor: pointer; border: 1px solid var(--border-color);" onclick="document.getElementById('ai-max-tokens-inp').value = 32768">
                        ⚡ 32768 (Ultra)
                    </button>
                </div>
            </div>
        </div>

        <!-- Firecrawl Web Search & Scrape Configuration -->
        <div class="card-duo" style="border-left: 4px solid #f97316;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <h3 style="font-size: 1.2rem; font-weight: 800; display: flex; align-items: center; gap: 8px; margin: 0;">
                    <span>🔥</span> <span>Firecrawl API (Web Search & Page Scraper)</span>
                </h3>
                <a href="https://www.firecrawl.dev" target="_blank" class="badge-tag" style="background: #ffedd5; color: #c2410c; text-decoration: none; font-weight: 800;">
                    firecrawl.dev ↗
                </a>
            </div>
            
            <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 14px;">
                Используется <strong>Автономным AI Агентом</strong> и <strong>Сиба-Сэнсэем</strong> для живого поиска информации в интернете (<code>web_search</code>) и чтения любых веб-страниц/статей в чистом Markdown (<code>fetch_url</code>).
            </p>

            <div style="margin-bottom: 8px;">
                <label style="font-weight: 700; font-size: 0.88rem; margin-bottom: 6px; display: block;">Firecrawl API Key:</label>
                <input type="password" name="firecrawl_api_key" value="<?= e($firecrawlKey) ?>" class="chat-input" placeholder="fc-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted);">
                Если ключ не указан, система автоматически переключается на встроенный HTTP парсинг и поисковый шлюз.
            </div>
        </div>

        <div class="card-duo">
            <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 16px;">🧠 Системный промпт маскота Сиба-сэнсэя</h3>
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 12px;">
                Вы можете добавить кастомные инструкции для характера Шиба-Ину (например, характер, любимые фразы, строгость проверки):
            </p>

            <textarea name="ai_system_prompt_override" rows="6" class="chat-input" placeholder="Дополнительные инструкции для маскота (например: будь очень милым, используй собачьи звуки 'Гав!', хвали за каждое слово)..."><?= e($customPrompt) ?></textarea>
        </div>

        <button type="submit" class="btn-duo btn-primary" style="width: 100%; font-size: 1.1rem; padding: 16px;">
            Сохранить настройки ИИ и Firecrawl 💾
        </button>
    </form>
</div>

<script>
function setPresetUrl(url) {
    document.getElementById('api-base-url').value = url;
}

function togglePasswordVisibility() {
    const input = document.getElementById('api-key-input');
    input.type = input.type === 'password' ? 'text' : 'password';
}

function applySelectedModel(val) {
    if (val) {
        document.getElementById('api-model-input').value = val;
    }
}

async function fetchModelsList() {
    const baseUrl = document.getElementById('api-base-url').value.trim();
    const apiKey = document.getElementById('api-key-input').value.trim();
    const btn = document.getElementById('btn-fetch-models');
    const statusDiv = document.getElementById('fetch-status-msg');
    const container = document.getElementById('fetched-models-container');
    const select = document.getElementById('models-select-dropdown');
    const badge = document.getElementById('models-fetch-badge');

    if (!apiKey) {
        alert('Пожалуйста, введите API Key перед загрузкой списка моделей!');
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Загрузка...';
    statusDiv.style.display = 'block';
    statusDiv.style.color = 'var(--secondary)';
    statusDiv.textContent = `Запрос к ${baseUrl}/models ...`;

    try {
        const res = await fetch('../api/ai.php?action=fetch_models', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                base_url: baseUrl,
                api_key: apiKey
            })
        });

        const data = await res.json();

        if (data.success && Array.isArray(data.models) && data.models.length > 0) {
            select.innerHTML = '<option value="">-- Выберите модель из списка (' + data.models.length + ') --</option>';
            data.models.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m;
                opt.textContent = m;
                if (m === document.getElementById('api-model-input').value) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });

            container.style.display = 'block';
            badge.textContent = `${data.models.length} моделей`;
            statusDiv.style.color = 'var(--primary)';
            statusDiv.textContent = `✓ Успешно получено ${data.models.length} моделей из /v1/models!`;
        } else {
            statusDiv.style.color = 'var(--danger)';
            statusDiv.textContent = '✕ Ошибка: ' + (data.error || 'Не удалось загрузить список моделей');
        }
    } catch (e) {
        statusDiv.style.color = 'var(--danger)';
        statusDiv.textContent = '✕ Ошибка сетевого запроса к серверу.';
    } finally {
        btn.disabled = false;
        btn.textContent = '🔄 Загрузить модели из API (/v1/models)';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
