<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Супер-Агент ИИ (Control Agent)';
require_once __DIR__ . '/header.php';

$tokensUsage = DB::fetchAll("SELECT `model_name`, SUM(`prompt_tokens`) as pt, SUM(`completion_tokens`) as ct, SUM(`total_tokens`) as tt, COUNT(*) as cnt FROM `va_ai_usage` GROUP BY `model_name`");
?>

<div class="space-y-6 font-mono text-xs">
    
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans flex items-center space-x-2">
                <span class="w-3 h-3 rounded-full bg-indigo-500 animate-pulse"></span>
                <span>Супер-Агент Владислав (Admin AI Super-Agent)</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">Автономный ассистент с полными правами управления платформой (30+ инструментов CRUD & DevOps)</p>
        </div>
    </div>

    <!-- Quick Tool Action Badges -->
    <div class="glass-card p-4 rounded-2xl border border-indigo-500/30 flex items-center space-x-2 overflow-x-auto text-[11px]">
        <span class="text-indigo-400 font-bold uppercase whitespace-nowrap">Быстрые команды:</span>
        <button onclick="sendAgentQuickPrompt('Очисти кэш системы и проверь логи ошибок')" class="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 whitespace-nowrap">🧹 Очистить кэш & Логи</button>
        <button onclick="sendAgentQuickPrompt('Создай новую викторину по системам Airbus A350 на 5 вопросов')" class="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 whitespace-nowrap">❓ Сгенерировать викторину</button>
        <button onclick="sendAgentQuickPrompt('Сделай аналитический отчет по активности пользователей и топ-самолетам')" class="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 whitespace-nowrap">📊 Аналитика базы</button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <!-- Left: Super Agent Terminal / Chat Window -->
        <div class="lg:col-span-8 glass-hud rounded-3xl border border-indigo-500/40 shadow-2xl p-6 flex flex-col h-[650px] space-y-4">
            
            <!-- Chat Header -->
            <div class="flex items-center justify-between border-b border-white/10 pb-3">
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold">🤖</div>
                    <div>
                        <div class="font-bold text-white text-sm">VladAero Super-Agent v2.0</div>
                        <div class="text-[10px] text-emerald-400">STATUS: READY • TOOL EXECUTION ENABLED</div>
                    </div>
                </div>
                <button onclick="clearAgentTerminal()" class="text-slate-400 hover:text-white text-[10px]">Очистить терминал</button>
            </div>

            <!-- Terminal Output -->
            <div id="agentChatLogs" class="flex-1 overflow-y-auto space-y-4 pr-2">
                <div class="p-4 rounded-2xl bg-slate-900/90 border border-indigo-500/20 text-slate-200 leading-relaxed">
                    👋 Приветствую, Шеф-пилот! Я Супер-Агент портала VladAero.<br><br>
                    Я готов выполнять административные команды:
                    <ul class="list-disc list-inside mt-2 space-y-1 text-slate-400">
                        <li>Автоматически создавать карточки техники с заполнением ТТХ и описаний;</li>
                        <li>Генерировать викторины, статьи и новости;</li>
                        <li>Проводить скрининг споттерских снимков и модерировать пользователей;</li>
                        <li>Очищать кэш, проверять журнал ошибок и делать бэкапы БД.</li>
                    </ul>
                </div>
            </div>

            <!-- Input Bar -->
            <div class="flex items-center space-x-2 pt-2 border-t border-white/10">
                <input type="text" id="agentInput" placeholder="Введите инструкцию для Супер-Агента..." 
                       class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
                       onkeydown="if(event.key==='Enter') executeAgentInstruction()">
                <button onclick="executeAgentInstruction()" class="px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-sky-600 hover:from-indigo-500 hover:to-sky-500 text-white font-bold transition shadow-lg shadow-indigo-600/30">
                    Выполнить
                </button>
            </div>

        </div>

        <!-- Right: Tool Capability Matrix & Token Usage -->
        <div class="lg:col-span-4 space-y-4">
            
            <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-3">
                <h3 class="font-bold text-white text-sm border-b border-white/5 pb-2">Матрица инструментов (Tools)</h3>
                
                <div class="space-y-1.5 text-[11px] text-slate-300">
                    <div class="flex items-center space-x-1.5 text-emerald-400 font-bold">
                        <span>✔</span> <span>admin_create_aircraft</span>
                    </div>
                    <div class="flex items-center space-x-1.5 text-emerald-400 font-bold">
                        <span>✔</span> <span>admin_generate_quiz</span>
                    </div>
                    <div class="flex items-center space-x-1.5 text-emerald-400 font-bold">
                        <span>✔</span> <span>admin_screen_photos</span>
                    </div>
                    <div class="flex items-center space-x-1.5 text-emerald-400 font-bold">
                        <span>✔</span> <span>admin_clear_cache</span>
                    </div>
                    <div class="flex items-center space-x-1.5 text-emerald-400 font-bold">
                        <span>✔</span> <span>admin_read_error_logs</span>
                    </div>
                    <div class="flex items-center space-x-1.5 text-emerald-400 font-bold">
                        <span>✔</span> <span>admin_execute_safe_query</span>
                    </div>
                </div>
            </div>

            <!-- Token Usage Summary -->
            <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-3">
                <h3 class="font-bold text-white text-sm border-b border-white/5 pb-2">Расход токенов ИИ</h3>

                <?php foreach ($tokensUsage as $tu): ?>
                    <div class="p-3 rounded-xl bg-slate-900/80 border border-white/5 space-y-1">
                        <div class="font-bold text-sky-400"><?= e($tu['model_name']) ?></div>
                        <div class="flex justify-between text-slate-400 text-[10px]">
                            <span>Всего вызовов: <?= $tu['cnt'] ?></span>
                            <span><?= number_format((int)$tu['tt'], 0, '', ' ') ?> токенов</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>

    </div>

</div>

<script>
    function sendAgentQuickPrompt(text) {
        document.getElementById('agentInput').value = text;
        executeAgentInstruction();
    }

    async function executeAgentInstruction() {
        const input = document.getElementById('agentInput');
        const text = input.value.trim();
        if (!text) return;

        input.value = '';
        const logs = document.getElementById('agentChatLogs');

        // Add user command
        logs.innerHTML += `
            <div class="flex justify-end">
                <div class="bg-indigo-600 text-white p-3 rounded-2xl rounded-tr-none max-w-lg leading-relaxed shadow-sm">
                    ${escapeHtml(text)}
                </div>
            </div>
        `;
        logs.scrollTop = logs.scrollHeight;

        // Placeholder
        const botId = 'agent_resp_' + Date.now();
        logs.innerHTML += `
            <div class="flex justify-start" id="${botId}">
                <div class="p-4 rounded-2xl bg-slate-900/90 border border-indigo-500/30 text-slate-200 leading-relaxed max-w-xl">
                    <span class="text-indigo-400 animate-pulse font-bold">⚡ Агент выполняет анализ и запуск инструментов...</span>
                </div>
            </div>
        `;
        logs.scrollTop = logs.scrollHeight;

        try {
            const res = await fetch('../api/ai_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text, is_admin_agent: 1 })
            });
            const data = await res.json();
            const reply = data.content || data.error || 'Команда выполнена.';

            document.getElementById(botId).querySelector('div').innerHTML = reply.replace(/\n/g, '<br>');
        } catch (e) {
            document.getElementById(botId).querySelector('div').innerText = 'Ошибка соединения с API.';
        }
        logs.scrollTop = logs.scrollHeight;
    }

    function clearAgentTerminal() {
        document.getElementById('agentChatLogs').innerHTML = '<div class="text-slate-500 text-center py-4">Терминал очищен.</div>';
    }

    function escapeHtml(str) {
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
