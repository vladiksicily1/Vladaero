<?php
/** @var array $ai */
/** @var array $firecrawl */
/** @var bool $fcOk */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🤖 Настройки ИИ</h1>

        <form method="POST" action="<?= url('/admin/ai-settings/save') ?>" class="form-card">
            <?= csrf_field() ?>

            <h2>🧠 AI Provider (OpenAI-compatible)</h2>
            <div class="form-group">
                <label>Base URL API</label>
                <input type="url" name="ai_base_url" class="form-input" value="<?= e($ai['base_url'] ?? 'https://api.openai.com/v1') ?>" placeholder="https://api.openai.com/v1">
                <small class="text-muted">Любой OpenAI-совместимый API: OpenAI, OpenRouter, Together, vLLM, Ollama и т.д.</small>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="ai_api_key" class="form-input" value="<?= e($ai['api_key'] ?? '') ?>" placeholder="sk-...">
                </div>
                <div class="form-group">
                    <label>Model ID</label>
                    <div class="input-with-btn">
                        <input type="text" name="ai_model_id" id="aiModelInput" class="form-input" value="<?= e($ai['model_id'] ?? '') ?>" placeholder="gpt-4o-mini">
                        <button type="button" class="btn btn--outline btn--sm" onclick="fetchModels()">🔄 Загрузить модели</button>
                    </div>
                    <div id="modelsList" class="models-list" style="display:none"></div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Temperature (0.0 – 2.0)</label>
                    <input type="number" name="ai_temperature" class="form-input" step="0.1" min="0" max="2" value="<?= $ai['temperature'] ?? 0.7 ?>">
                </div>
                <div class="form-group">
                    <label>Max Tokens</label>
                    <input type="number" name="ai_max_tokens" class="form-input" value="<?= $ai['max_tokens'] ?? 4096 ?>">
                </div>
            </div>
            <button type="button" class="btn btn--outline" onclick="testAi()">🧪 Тест AI</button>
            <div id="aiTestResult" class="mt-2"></div>

            <hr style="border-color:var(--border);margin:2rem 0;">

            <h2>🌐 Firecrawl (Web Search & URL Extraction)</h2>
            <div class="form-row">
                <div class="form-group">
                    <label>Firecrawl API Key</label>
                    <input type="password" name="fc_api_key" class="form-input" value="<?= e($firecrawl['api_key'] ?? '') ?>" placeholder="fc-...">
                </div>
                <div class="form-group">
                    <label>Base URL</label>
                    <input type="url" name="fc_base_url" class="form-input" value="<?= e($firecrawl['base_url'] ?? 'https://api.firecrawl.dev/v1') ?>">
                </div>
            </div>
            <button type="button" class="btn btn--outline" onclick="testFirecrawl()">🧪 Тест Firecrawl</button>
            <div id="fcTestResult" class="mt-2"></div>

            <div class="mt-4">
                <button type="submit" class="btn btn--primary">💾 Сохранить настройки</button>
            </div>
        </form>

        <div class="card mt-6">
            <div class="card__body">
                <h3>📋 Инструменты виджета (20 tools)</h3>
                <div class="grid grid--2 mt-4">
                    <div>
                        <h4>📦 База данных (8)</h4>
                        <ul class="list">
                            <li>search_aircraft</li>
                            <li>search_airports</li>
                            <li>get_airport_metar</li>
                            <li>search_articles</li>
                            <li>get_airline_info</li>
                            <li>get_aircraft_details</li>
                            <li>get_glossary_term</li>
                            <li>get_event_info</li>
                        </ul>
                    </div>
                    <div>
                        <h4>🧮 Расчёты (5)</h4>
                        <ul class="list">
                            <li>calculate_distance (Great Circle)</li>
                            <li>convert_units</li>
                            <li>calculate_crosswind</li>
                            <li>calculate_descent (TOD)</li>
                            <li>calculate_compensation (EC261)</li>
                        </ul>
                    </div>
                    <div>
                        <h4>🌐 Веб — Firecrawl (4)</h4>
                        <ul class="list">
                            <li>web_search_firecrawl</li>
                            <li>fetch_url_firecrawl</li>
                            <li>map_site_firecrawl</li>
                            <li>extract_content</li>
                        </ul>
                    </div>
                    <div>
                        <h4>🎯 UI (3)</h4>
                        <ul class="list">
                            <li>show_flight_on_radar</li>
                            <li>start_quiz</li>
                            <li>suggest_spotting_spots</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
async function testAi() {
    const el = document.getElementById('aiTestResult');
    el.innerHTML = '<span class="loader"></span> Тестирование...';
    const formData = new FormData(document.querySelector('form'));
    const resp = await fetch('/admin/ai-settings/test-ai', {method:'POST', body: formData});
    const data = await resp.json();
    el.innerHTML = '<p style="color:' + (data.success ? 'var(--success)' : 'var(--error)') + '">' + data.message + '</p>';
}

async function testFirecrawl() {
    const el = document.getElementById('fcTestResult');
    el.innerHTML = '<span class="loader"></span> Тестирование Firecrawl...';
    const formData = new FormData(document.querySelector('form'));
    const resp = await fetch('/admin/ai-settings/test-firecrawl', {method:'POST', body: formData});
    const data = await resp.json();
    el.innerHTML = '<p style="color:' + (data.success ? 'var(--success)' : 'var(--error)') + '">' + data.message + '</p>';
}

async function fetchModels() {
    const el = document.getElementById('modelsList');
    el.innerHTML = '<span class="loader"></span> Загрузка моделей...';
    el.style.display = 'block';
    const formData = new FormData(document.querySelector('form'));
    const resp = await fetch('/admin/ai-settings/fetch-models', {method:'POST', body: formData});
    const data = await resp.json();
    if (data.success && data.models.length) {
        el.innerHTML = data.models.map(m =>
            '<button type="button" class="model-chip" onclick="selectModel(this)" data-model="' + m + '">' + m + '</button>'
        ).join('');
    } else {
        el.innerHTML = '<p class="text-muted">' + (data.message || 'Модели не найдены') + '</p>';
    }
}

function selectModel(btn) {
    document.getElementById('aiModelInput').value = btn.dataset.model;
    document.getElementById('modelsList').style.display = 'none';
}
</script>

<style>
.models-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; max-height: 200px; overflow-y: auto; }
.model-chip { padding: 0.3rem 0.7rem; background: var(--surface-2, #162240); border: 1px solid var(--border); border-radius: 6px; color: var(--text); cursor: pointer; font-size: 0.8rem; font-family: monospace; }
.model-chip:hover { border-color: var(--accent); color: var(--accent); }
.list { list-style: none; }
.list li { padding: 0.2rem 0; font-size: 0.85rem; font-family: monospace; }
.list li::before { content: "• "; color: var(--accent); }
</style>
