<?php
$adminTitle = 'Super Admin ИИ-Агент — Real-Time Streaming & Reasoning';
require_once __DIR__ . '/header.php';
?>

<div class="space-y-6">

    <!-- Header -->
    <div class="va-card p-6 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <div class="w-12 h-12 rounded-2xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-purple-400 shadow-xl shadow-purple-500/10">
                <i data-lucide="bot" class="w-7 h-7"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold text-white flex items-center space-x-2">
                    <span>Суперадминистративный ИИ-Агент VladAero</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-purple-950 text-purple-300 border border-purple-800">REAL-TIME REASONING STREAM</span>
                </h1>
                <p class="text-xs text-slate-400 font-mono">
                    Модель: <strong class="text-sky-400"><?= e(getSetting('ai_model_id', 'gpt-4o-mini')) ?></strong> • Инструменты: <span class="text-emerald-400">База данных, Поиск, Метеодекодер, E6B</span>
                </p>
            </div>
        </div>

        <button onclick="clearAdminAiChat()" class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-400 hover:text-white transition" title="Очистить терминал">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
        </button>
    </div>

    <!-- Main Chat Terminal -->
    <div class="va-card h-[640px] flex flex-col overflow-hidden shadow-2xl">
        <!-- Messages Stream -->
        <div id="admin-chat-messages" class="flex-grow overflow-y-auto p-6 space-y-4 font-mono text-xs leading-relaxed">
            <div class="flex items-start space-x-3">
                <div class="w-7 h-7 rounded-lg bg-purple-600/30 text-purple-400 flex items-center justify-center flex-shrink-0 mt-0.5">
                    <i data-lucide="bot" class="w-4 h-4"></i>
                </div>
                <div class="p-4 rounded-2xl bg-slate-950 border border-purple-500/20 text-slate-200 max-w-3xl">
                    Инициализирован терминал Суперадминистративного ИИ-Агента VladAero с поддержкой <strong>потоковых рассуждений в реальном времени</strong> (DeepSeek R1 / OpenAI o1 / o3 / Gemini Thinking).
                    <br><br>
                    Вы можете поручить агенту:
                    <ul class="list-disc pl-5 mt-2 space-y-1 text-slate-400 font-sans">
                        <li>«Напиши подробную статью об истории создания Ту-144 и его сравнении с Конкордом»</li>
                        <li>«Проанализируй текущую погоду в Шереметьево (UUEE) и рассчитай плотностную высоту»</li>
                        <li>«Сделай сравнительный анализ расходов топлива между A320neo и B737-800»</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Input Form -->
        <form onsubmit="sendAdminAiStreamMessage(event)" class="p-4 bg-slate-950 border-t border-slate-800 flex items-center space-x-3">
            <input type="text" id="admin-chat-input" placeholder="Введите команду или задачу для ИИ-Агента..." class="w-full bg-slate-900 border border-slate-800 rounded-xl px-4 py-3.5 text-xs text-slate-100 placeholder-slate-500 font-mono focus:outline-none focus:border-purple-500">
            <button type="submit" id="admin-chat-submit" class="bg-purple-600 hover:bg-purple-500 text-white font-mono font-bold px-6 py-3.5 rounded-xl shadow-lg transition flex items-center space-x-2 flex-shrink-0">
                <i data-lucide="send" class="w-4 h-4"></i>
                <span>Выполнить</span>
            </button>
        </form>
    </div>

</div>

<script>
    let adminAiHistory = [];

    function clearAdminAiChat() {
        document.getElementById('admin-chat-messages').innerHTML = `
            <div class="flex items-start space-x-3">
                <div class="w-7 h-7 rounded-lg bg-purple-600/30 text-purple-400 flex items-center justify-center flex-shrink-0 mt-0.5">
                    <i data-lucide="bot" class="w-4 h-4"></i>
                </div>
                <div class="p-4 rounded-2xl bg-slate-950 border border-purple-500/20 text-slate-200">
                    Терминал очищен. Готов к выполнению административных задач.
                </div>
            </div>`;
        adminAiHistory = [];
        lucide.createIcons();
    }

    async function sendAdminAiStreamMessage(e) {
        e.preventDefault();
        const input = document.getElementById('admin-chat-input');
        const msg = input.value.trim();
        if (!msg) return;

        input.value = '';
        const container = document.getElementById('admin-chat-messages');

        // Render User Command
        container.innerHTML += `
            <div class="flex items-start justify-end space-x-2">
                <div class="bg-purple-600 text-white rounded-2xl p-4 shadow-md max-w-2xl font-sans text-xs">
                    ${msg.replace(/</g, '&lt;').replace(/>/g, '&gt;')}
                </div>
            </div>`;

        // Create Assistant Message Placeholder with Real-Time Thinking Box
        const msgId = 'admin-msg-' + Date.now();
        const reasoningBoxId = 'admin-reasoning-' + msgId;
        const contentBoxId = 'admin-content-' + msgId;

        container.innerHTML += `
            <div id="${msgId}" class="flex items-start space-x-3">
                <div class="w-7 h-7 rounded-lg bg-purple-600/30 text-purple-400 flex items-center justify-center flex-shrink-0 mt-0.5">
                    <i data-lucide="bot" class="w-4 h-4"></i>
                </div>
                <div class="p-4 rounded-2xl bg-slate-950 border border-purple-500/30 text-slate-200 max-w-3xl w-full leading-relaxed font-sans text-xs">
                    <!-- Real-Time Reasoning Box -->
                    <details id="${reasoningBoxId}" class="hidden mb-3 bg-slate-900/90 border border-purple-500/40 rounded-xl p-3 font-mono text-[11px]" open>
                        <summary class="text-purple-300 font-bold cursor-pointer flex items-center space-x-2 select-none">
                            <i data-lucide="brain" class="w-4 h-4 text-purple-400 animate-pulse"></i>
                            <span>Анализ и рассуждения ИИ-Агента (Thinking Process)...</span>
                        </summary>
                        <div class="reasoning-text text-slate-400 mt-2 font-mono whitespace-pre-wrap leading-tight text-[11px] bg-slate-950 p-2 rounded border border-slate-800"></div>
                    </details>

                    <!-- Real-Time Content Stream -->
                    <div id="${contentBoxId}" class="content-text text-slate-200">
                        <span class="inline-block w-2 h-3.5 bg-purple-400 animate-pulse"></span>
                    </div>
                </div>
            </div>`;
        lucide.createIcons();
        container.scrollTop = container.scrollHeight;

        adminAiHistory.push({ role: 'user', content: msg });

        let fullContent = '';
        let fullReasoning = '';

        try {
            const response = await fetch('<?= url('/api/ai_stream.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ messages: adminAiHistory, is_admin: true })
            });

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const events = buffer.split("\n\n");
                buffer = events.pop();

                for (const evt of events) {
                    const lines = evt.split("\n");
                    let eventType = 'message';
                    let dataStr = '';

                    for (const line of lines) {
                        if (line.startsWith('event:')) {
                            eventType = line.substring(6).trim();
                        } else if (line.startsWith('data:')) {
                            dataStr = line.substring(5).trim();
                        }
                    }

                    if (!dataStr) continue;
                    const data = JSON.parse(dataStr);

                    if (eventType === 'reasoning') {
                        const rBox = document.getElementById(reasoningBoxId);
                        if (rBox) {
                            rBox.classList.remove('hidden');
                            const rText = rBox.querySelector('.reasoning-text');
                            fullReasoning += data.chunk;
                            rText.textContent = fullReasoning;
                            container.scrollTop = container.scrollHeight;
                        }
                    } else if (eventType === 'content') {
                        fullContent += data.chunk;
                        const cBox = document.getElementById(contentBoxId);
                        if (cBox) {
                            cBox.innerHTML = renderAdminMarkdown(fullContent) + '<span class="inline-block w-1.5 h-3.5 bg-purple-400 animate-pulse ml-0.5"></span>';
                            container.scrollTop = container.scrollHeight;
                        }
                    } else if (eventType === 'done') {
                        const cBox = document.getElementById(contentBoxId);
                        if (cBox) {
                            cBox.innerHTML = renderAdminMarkdown(fullContent || data.full_content || 'Запрос обработан.');
                        }
                        const rBox = document.getElementById(reasoningBoxId);
                        if (rBox && fullReasoning) {
                            rBox.removeAttribute('open');
                        }
                        adminAiHistory.push({ role: 'assistant', content: fullContent });
                        lucide.createIcons();
                    } else if (eventType === 'error') {
                        const cBox = document.getElementById(contentBoxId);
                        if (cBox) {
                            cBox.innerHTML = `<span class="text-red-400 font-mono">${data.error}</span>`;
                        }
                    }
                }
            }
        } catch (err) {
            const cBox = document.getElementById(contentBoxId);
            if (cBox) {
                cBox.innerHTML = `<span class="text-red-400 font-mono">Ошибка связи с потоковым сервером ИИ.</span>`;
            }
        }
    }

    function renderAdminMarkdown(txt) {
        return txt
            .replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/`([^`]+)`/g, '<code class="bg-slate-900 px-1 rounded text-purple-300 font-mono">$1</code>')
            .replace(/\n/g, '<br>');
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
