<?php
$adminTitle = 'Настройки Портала и Конфигурация ИИ';
require_once __DIR__ . '/header.php';
require_once VLADAERO_ROOT . '/includes/ai_service.php';

// Handle Fetch Models AJAX request
if (isset($_GET['action']) && $_GET['action'] === 'fetch_models') {
    header('Content-Type: application/json; charset=utf-8');
    $res = AIService::fetchModels();
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle Save Settings POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    setSetting('site_name', trim($_POST['site_name'] ?? 'VladAero'), 'general');
    setSetting('site_tagline', trim($_POST['site_tagline'] ?? ''), 'general');
    setSetting('base_url', trim($_POST['base_url'] ?? '/aviation'), 'general');
    setSetting('maintenance_ip_whitelist', trim($_POST['maintenance_ip_whitelist'] ?? ''), 'maintenance');

    // AI Settings
    setSetting('ai_base_url', trim($_POST['ai_base_url'] ?? 'https://api.openai.com/v1'), 'ai');
    setSetting('ai_api_key', trim($_POST['ai_api_key'] ?? ''), 'ai');
    setSetting('ai_model_id', trim($_POST['ai_model_id'] ?? 'gpt-4o-mini'), 'ai');

    setFlash('success', 'Настройки портала и параметры ИИ успешно сохранены!');
    header('Location: ' . url('/admin/settings.php'));
    exit;
}

$siteName = getSetting('site_name', 'VladAero');
$siteTagline = getSetting('site_tagline', 'Всемирный Авиационный Портал');
$baseUrl = getSetting('base_url', '/aviation');
$aiBaseUrl = getSetting('ai_base_url', 'https://api.openai.com/v1');
$aiApiKey = getSetting('ai_api_key', '');
$aiModelId = getSetting('ai_model_id', 'gpt-4o-mini');
$ipWhitelist = getSetting('maintenance_ip_whitelist', '');
?>

<div class="space-y-8 max-w-4xl">

    <div>
        <h1 class="text-2xl font-bold text-white">Настройки Портала и Конфигурация ИИ</h1>
        <p class="text-xs text-slate-400 font-mono">Глобальные параметры портала, путь развертывания и интеграция с нейросетями</p>
    </div>

    <form method="POST" class="space-y-6">
        <input type="hidden" name="save_settings" value="1">

        <!-- 1. General Settings Card -->
        <div class="va-card p-6 sm:p-8 space-y-4 font-mono text-xs">
            <h2 class="text-sm font-bold text-white uppercase tracking-wider flex items-center space-x-2">
                <i data-lucide="globe" class="w-4 h-4 text-sky-400"></i>
                <span>1. Основные параметры и путь сайта</span>
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 mb-1">Название портала:</label>
                    <input type="text" name="site_name" value="<?= e($siteName) ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100 font-bold">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Слоган:</label>
                    <input type="text" name="site_tagline" value="<?= e($siteTagline) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Базовый путь подпапки (Base URL Path):</label>
                <input type="text" name="base_url" value="<?= e($baseUrl) ?>" required placeholder="/aviation" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-sky-400 font-bold">
                <p class="text-[11px] text-slate-500 mt-1 font-sans">
                    Для домена <code>vladinc.ru/aviation</code> укажите <code>/aviation</code>. Для корня сайта оставьте пустым или <code>/</code>.
                </p>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Белый список IP для режима обслуживания (через запятую):</label>
                <input type="text" name="maintenance_ip_whitelist" value="<?= e($ipWhitelist) ?>" placeholder="127.0.0.1, 192.168.1.10" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100">
            </div>
        </div>

        <!-- 2. AI & OpenAI-Compatible LLM Settings -->
        <div class="va-card p-6 sm:p-8 space-y-4 font-mono text-xs border border-purple-500/30">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-white uppercase tracking-wider flex items-center space-x-2">
                    <i data-lucide="cpu" class="w-4 h-4 text-purple-400"></i>
                    <span>2. Интеграция с ИИ (OpenAI-совместимый API)</span>
                </h2>
                <span class="px-2 py-0.5 rounded bg-purple-950 text-purple-300 border border-purple-800 text-[10px]">v1/models ready</span>
            </div>

            <p class="text-[11px] text-slate-400 font-sans leading-relaxed">
                Поддерживает любых провайдеров (OpenAI, OpenRouter, Together AI, Groq, Ollama, vLLM, DeepSeek). Нажмите кнопку <strong>«Получить список моделей»</strong> для автоматической загрузки доступных нейросетей.
            </p>

            <div>
                <label class="block text-slate-400 mb-1">Base URL API:</label>
                <input type="text" name="ai_base_url" id="ai-base-url" value="<?= e($aiBaseUrl) ?>" required placeholder="https://api.openai.com/v1" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100">
            </div>

            <div>
                <label class="block text-slate-400 mb-1">API Key (Секретный ключ):</label>
                <input type="password" name="ai_api_key" id="ai-api-key" value="<?= e($aiApiKey) ?>" placeholder="sk-..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-100">
            </div>

            <!-- Model Selector & Dynamic Fetch Button -->
            <div class="space-y-2 pt-2">
                <div class="flex items-center justify-between">
                    <label class="block text-slate-400">Идентификатор модели (Model ID):</label>
                    <button type="button" onclick="fetchLiveModels()" id="btn-fetch-models" class="px-3 py-1 bg-purple-600/30 hover:bg-purple-600/50 text-purple-300 border border-purple-500/40 rounded-lg text-[11px] font-bold transition flex items-center space-x-1">
                        <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                        <span>Опросить /v1/models</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <input type="text" name="ai_model_id" id="ai-model-input" value="<?= e($aiModelId) ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-sky-400 font-bold">
                    
                    <select id="ai-models-dropdown" onchange="document.getElementById('ai-model-input').value = this.value" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-slate-300">
                        <option value="<?= e($aiModelId) ?>">Текущая: <?= e($aiModelId) ?></option>
                    </select>
                </div>
                <div id="models-fetch-status" class="text-[11px] text-slate-500 font-sans"></div>
            </div>
        </div>

        <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold py-4 rounded-xl shadow-xl shadow-sky-500/20 text-sm tracking-wide uppercase transition">
            💾 Сохранить все настройки
        </button>
    </form>

</div>

<script>
    function fetchLiveModels() {
        const btn = document.getElementById('btn-fetch-models');
        const statusEl = document.getElementById('models-fetch-status');
        btn.disabled = true;
        statusEl.innerText = 'Запрос к серверу /v1/models...';

        fetch('?action=fetch_models')
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (data.success && data.models) {
                    const select = document.getElementById('ai-models-dropdown');
                    select.innerHTML = '<option value="">— Выберите полученную модель (' + data.models.length + ') —</option>';
                    data.models.forEach(m => {
                        const opt = document.createElement('option');
                        opt.value = m;
                        opt.innerText = m;
                        select.appendChild(opt);
                    });
                    statusEl.className = 'text-[11px] text-emerald-400 font-sans';
                    statusEl.innerText = 'Успешно загружено ' + data.models.length + ' моделей!';
                } else {
                    statusEl.className = 'text-[11px] text-red-400 font-sans';
                    statusEl.innerText = 'Ошибка: ' + (data.error || 'Не удалось получить список моделей');
                }
            })
            .catch(err => {
                btn.disabled = false;
                statusEl.className = 'text-[11px] text-red-400 font-sans';
                statusEl.innerText = 'Ошибка сети при обращении к /v1/models';
            });
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
