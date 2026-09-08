<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Настройки системы и ИИ';
require_once __DIR__ . '/header.php';

$success = '';

// Handle DB Backup Download
if (isset($_GET['action']) && $_GET['action'] === 'download_db_backup') {
    $tables = ['va_settings', 'va_users', 'va_manufacturers', 'va_aircraft_categories', 'va_aircraft', 'va_aircraft_specs', 'va_aircraft_modifications', 'va_airports', 'va_runways', 'va_frequencies', 'va_airlines', 'va_photos', 'va_spotting_locations', 'va_article_categories', 'va_articles', 'va_comments', 'va_incidents', 'va_flight_logs', 'va_glossary', 'va_quizzes', 'va_quiz_questions', 'va_achievements', 'va_user_achievements', 'va_weather_cache', 'va_ai_usage', 'va_audit_logs'];

    $dump = "-- VladAero Database Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $t) {
        $rows = DB::fetchAll("SELECT * FROM `{$t}`");
        if (!empty($rows)) {
            $dump .= "-- Dumping data for table `{$t}`\n";
            foreach ($rows as $row) {
                $fields = array_keys($row);
                $values = array_map(function($val) {
                    if ($val === null) return 'NULL';
                    return "'" . addslashes((string)$val) . "'";
                }, array_values($row));

                $dump .= sprintf("INSERT INTO `%s` (`%s`) VALUES (%s);\n", $t, implode('`, `', $fields), implode(', ', $values));
            }
            $dump .= "\n";
        }
    }
    $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="vladaero_backup_' . date('Ymd_His') . '.sql"');
    echo $dump;
    exit;
}

// Handle Form Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    set_setting('site_name', trim($_POST['site_name'] ?? 'VladAero'));
    set_setting('site_tagline', trim($_POST['site_tagline'] ?? ''));
    set_setting('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0');
    set_setting('enable_ai_widget', isset($_POST['enable_ai_widget']) ? '1' : '0');
    set_setting('ai_provider', trim($_POST['ai_provider'] ?? 'openai'));
    set_setting('ai_base_url', trim($_POST['ai_base_url'] ?? 'https://api.openai.com/v1'));
    set_setting('ai_model_id', trim($_POST['ai_model_id'] ?? 'gpt-4o-mini'));
    set_setting('ai_temperature', trim($_POST['ai_temperature'] ?? '0.7'));
    set_setting('ai_system_prompt', trim($_POST['ai_system_prompt'] ?? ''));

    if (!empty($_POST['ai_api_key'])) {
        set_setting('ai_api_key', trim($_POST['ai_api_key']), 'ai', true);
    }

    if (!empty($_POST['firecrawl_api_key'])) {
        set_setting('firecrawl_api_key', trim($_POST['firecrawl_api_key']), 'ai', true);
    }

    if (!empty($_POST['telegram_bot_token'])) {
        set_setting('telegram_bot_token', trim($_POST['telegram_bot_token']), 'telegram', true);
    }

    $success = 'Настройки успешно сохранены!';
}

$siteName = get_setting('site_name', 'VladAero');
$siteTagline = get_setting('site_tagline', '');
$maintMode = get_setting('maintenance_mode', '0') === '1';
$aiWidget = get_setting('enable_ai_widget', '1') === '1';
$aiProvider = get_setting('ai_provider', 'openai');
$aiBaseUrl = get_setting('ai_base_url', 'https://api.openai.com/v1');
$aiModelId = get_setting('ai_model_id', 'gpt-4o-mini');
$aiTemp = get_setting('ai_temperature', '0.7');
$aiPrompt = get_setting('ai_system_prompt', '');
?>

<div class="space-y-8 font-mono text-xs max-w-4xl">
    
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans">Параметры портала и конфигурация ИИ</h1>
            <p class="text-xs text-slate-400 mt-1">Управление провайдерами моделей, API-ключами, резервным копированием и режимом обслуживания</p>
        </div>

        <a href="settings.php?action=download_db_backup" class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold transition flex items-center space-x-2 shadow-lg shadow-emerald-600/20">
            <i data-lucide="database" class="w-4 h-4"></i>
            <span>Скачать SQL Бэкап БД</span>
        </a>
    </div>

    <?php if ($success): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-bold">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <?= csrf_field() ?>

        <!-- System & Branding Settings -->
        <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-4">
            <h3 class="font-bold text-white text-sm border-b border-white/5 pb-2 text-sky-400">1. Основные параметры и брендинг</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Название портала</label>
                    <input type="text" name="site_name" value="<?= e($siteName) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-sans text-sm">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Слоган</label>
                    <input type="text" name="site_tagline" value="<?= e($siteTagline) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div class="pt-2 flex flex-col sm:flex-row gap-4">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= $maintMode ? 'checked' : '' ?> class="rounded bg-slate-800 text-amber-500">
                    <span class="text-amber-400 font-bold">Режим регламентных работ (Maintenance Mode)</span>
                </label>
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="enable_ai_widget" value="1" <?= $aiWidget ? 'checked' : '' ?> class="rounded bg-slate-800 text-sky-500">
                    <span class="text-sky-400 font-bold">Включить плавающий ИИ-виджет в правом нижнем углу</span>
                </label>
            </div>
        </div>

        <!-- AI Configuration Card with Fetch v1 Models -->
        <div class="glass-card rounded-3xl p-6 border border-indigo-500/30 space-y-4">
            <div class="flex items-center justify-between border-b border-white/5 pb-2">
                <h3 class="font-bold text-white text-sm text-indigo-400">2. Настройки Бортового ИИ (LLM Provider & Tools)</h3>
                <span class="text-[10px] text-slate-400">OpenAI / OpenRouter / Gemini / Groq / DeepSeek / Ollama</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Провайдер</label>
                    <select name="ai_provider" id="aiProviderSelect" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white" onchange="updateProviderDefaults(this.value)">
                        <option value="openai" <?= $aiProvider === 'openai' ? 'selected' : '' ?>>OpenAI (Official)</option>
                        <option value="openrouter" <?= $aiProvider === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                        <option value="groq" <?= $aiProvider === 'groq' ? 'selected' : '' ?>>Groq Cloud</option>
                        <option value="deepseek" <?= $aiProvider === 'deepseek' ? 'selected' : '' ?>>DeepSeek API</option>
                        <option value="ollama" <?= $aiProvider === 'ollama' ? 'selected' : '' ?>>Ollama (Local / Self-hosted)</option>
                        <option value="custom" <?= $aiProvider === 'custom' ? 'selected' : '' ?>>Custom OpenAI-compatible URL</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Base URL (API Endpoint)</label>
                    <input type="text" id="aiBaseUrlInput" name="ai_base_url" value="<?= e($aiBaseUrl) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">API Ключ (Bearer Token)</label>
                    <input type="password" id="aiApiKeyInput" name="ai_api_key" placeholder="Оставьте пустым для сохранения текущего..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-slate-400 uppercase font-bold text-[10px]">Model ID</label>
                        <button type="button" onclick="fetchAvailableModels()" class="text-sky-400 hover:underline text-[10px]">
                            🔍 Получить список моделей (Fetch v1 Models)
                        </button>
                    </div>
                    <input type="text" id="aiModelIdInput" name="ai_model_id" value="<?= e($aiModelId) ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                    <div id="modelsDropdownList" class="hidden mt-2 p-2 bg-slate-900 rounded-xl border border-sky-500/30 max-h-32 overflow-y-auto space-y-1"></div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Firecrawl API Ключ (для Web Search & Scraper)</label>
                    <input type="password" name="firecrawl_api_key" placeholder="fc-..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Статус парсера Firecrawl</label>
                    <div class="p-2 rounded-xl bg-slate-950 border border-white/5 text-[11px] text-slate-300">
                        <?= !empty(get_setting('firecrawl_api_key', '')) ? '<span class="text-emerald-400 font-bold">✔ Активен</span> (Firecrawl Search & Scrape API)' : '<span class="text-amber-400">Резервный режим</span> (DuckDuckGo + Native HTTP Scraper)' ?>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Системный Промпт (Persona)</label>
                <textarea name="ai_system_prompt" rows="3" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 text-white"><?= e($aiPrompt) ?></textarea>
            </div>
        </div>

        <!-- Telegram Bot Settings -->
        <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-4">
            <h3 class="font-bold text-white text-sm border-b border-white/5 pb-2 text-sky-400">3. Telegram Бот и Уведомления</h3>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Telegram Bot Token (из @BotFather)</label>
                <input type="password" name="telegram_bot_token" placeholder="Оставьте пустым для сохранения текущего..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="px-8 py-3.5 rounded-2xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white font-bold text-sm shadow-xl shadow-sky-600/30 transition">
                Сохранить все настройки
            </button>
        </div>

    </form>

</div>

<script>
    function updateProviderDefaults(p) {
        const urlInput = document.getElementById('aiBaseUrlInput');
        const modelInput = document.getElementById('aiModelIdInput');

        if (p === 'openai') {
            urlInput.value = 'https://api.openai.com/v1';
            modelInput.value = 'gpt-4o-mini';
        } else if (p === 'openrouter') {
            urlInput.value = 'https://openrouter.ai/api/v1';
            modelInput.value = 'anthropic/claude-3.5-sonnet';
        } else if (p === 'groq') {
            urlInput.value = 'https://api.groq.com/openai/v1';
            modelInput.value = 'llama-3.3-70b-versatile';
        } else if (p === 'deepseek') {
            urlInput.value = 'https://api.deepseek.com/v1';
            modelInput.value = 'deepseek-chat';
        } else if (p === 'ollama') {
            urlInput.value = 'http://localhost:11434/v1';
            modelInput.value = 'llama3';
        }
    }

    async function fetchAvailableModels() {
        const baseUrl = document.getElementById('aiBaseUrlInput').value.trim();
        const apiKey = document.getElementById('aiApiKeyInput').value.trim();
        const dropdown = document.getElementById('modelsDropdownList');

        dropdown.innerHTML = '<div class="text-slate-400 text-center py-2">Запрос к /v1/models...</div>';
        dropdown.classList.remove('hidden');

        try {
            const url = baseUrl.replace(/\/+$/, '') + '/models';
            const res = await fetch(url, {
                headers: apiKey ? { 'Authorization': 'Bearer ' + apiKey } : {}
            });
            const data = await res.json();

            if (data.data && Array.isArray(data.data)) {
                let html = '';
                data.data.forEach(m => {
                    html += `<div class="p-1.5 hover:bg-sky-500/20 text-slate-200 cursor-pointer rounded" onclick="selectModel('${m.id}')">${m.id}</div>`;
                });
                dropdown.innerHTML = html;
            } else {
                dropdown.innerHTML = '<div class="text-rose-400 text-center py-2">Список моделей пуст или защищен ключом</div>';
            }
        } catch (e) {
            dropdown.innerHTML = '<div class="text-rose-400 text-center py-2">Не удалось подключиться к эндпоинту</div>';
        }
    }

    function selectModel(id) {
        document.getElementById('aiModelIdInput').value = id;
        document.getElementById('modelsDropdownList').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
