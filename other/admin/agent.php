<?php
/**
 * ShibaLingo - SuperAdmin Autonomous AI Agent Control Center
 * Real-time SSE Streaming + Multi-Step Native Tools (up to 10 tools per run) + Web Search & Fetch
 */

$adminTitle = 'Автономный AI Агент & Центр управления';
require_once __DIR__ . '/header.php';

$activeModel = getSetting('nvidia_model', 'meta/llama-3.1-70b-instruct');
$baseUrl = getSetting('nvidia_base_url', 'https://integrate.api.nvidia.com/v1');
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>🤖</span> <span>Автономный AI Агент (Native Tools & Streaming)</span>
        </h1>
        <p style="color: var(--text-muted);">
            Управляйте всем сайтом на естественном языке: Агент вызывает до 10 нативных инструментов за ответ, ищет в интернете, выполняет CRUD и генерирует контент в реальном времени.
        </p>
    </div>

    <div style="display: flex; gap: 10px; align-items: center;">
        <span class="badge-tag" style="background: rgba(88,204,2,0.15); color: var(--primary-shadow); font-weight: 800; font-size: 0.9rem;" id="agent-status-badge">
            🟢 Онлайн • <?= e($activeModel) ?>
        </span>
        <a href="ai_prompts.php" class="btn-duo btn-outline" style="padding: 8px 14px; font-size: 0.85rem;">
            ⚙️ Модель & API
        </a>
    </div>
</div>

<!-- Mission Quick Presets -->
<div style="margin-bottom: 20px;">
    <div style="font-size: 0.85rem; font-weight: 800; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase;">
        Быстрые миссии (Кликните для запуска):
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <button class="badge-tag btn-preset" style="cursor: pointer; background: var(--bg-card); border: 2px solid var(--border-color); font-weight: 700; padding: 8px 14px; font-size: 0.85rem;" onclick="setAgentPreset('Изучи базу знаний языка Vladikish через get_vladikish_knowledge, расскажи о правилах грамматики и придумай 5 новых гармоничных слов с примерами предложений.')">
            🐾 База знаний Vladikish
        </button>
        <button class="badge-tag btn-preset" style="cursor: pointer; background: var(--bg-card); border: 2px solid var(--border-color); font-weight: 700; padding: 8px 14px; font-size: 0.85rem;" onclick="setAgentPreset('Проверь базу данных, собери полную сводку по сайту и составь отчет с рекомендациями по улучшению курса.')">
            📊 Сводка & Анализ базы
        </button>
        <button class="badge-tag btn-preset" style="cursor: pointer; background: var(--bg-card); border: 2px solid var(--border-color); font-weight: 700; padding: 8px 14px; font-size: 0.85rem;" onclick="setAgentPreset('Создай новый раздел курса «В аэропорту и Путешествия» с 3 уроками и всеми упражнениями для языка vladikish.')">
            🗺️ Создать раздел и 3 урока
        </button>
        <button class="badge-tag btn-preset" style="cursor: pointer; background: var(--bg-card); border: 2px solid var(--border-color); font-weight: 700; padding: 8px 14px; font-size: 0.85rem;" onclick="setAgentPreset('Найди в интернете через web_search 5 популярных фраз для путешественников на итальянском и переведи их на Conlang Vladikish, затем добавь эти слова в словарь.')">
            🌐 Поиск в Web + Добавление слов
        </button>
        <button class="badge-tag btn-preset" style="cursor: pointer; background: var(--bg-card); border: 2px solid var(--border-color); font-weight: 700; padding: 8px 14px; font-size: 0.85rem;" onclick="setAgentPreset('Создай 3 праздничных промокода: SHIBA2026, VLADIKVIP и SUPERGEM с бонусами кристаллов и жизней на 30 дней.')">
            🎁 Создать пачку промокодов
        </button>
        <button class="badge-tag btn-preset" style="cursor: pointer; background: var(--bg-card); border: 2px solid var(--border-color); font-weight: 700; padding: 8px 14px; font-size: 0.85rem;" onclick="setAgentPreset('Создай увлекательную интерактивную историю «Тайна летающего замка» с вопросами на понимание для начинающих.')">
            📖 Сгенерировать историю
        </button>
    </div>
</div>

<!-- Main Agent Terminal & Chat Container -->
<div class="card-duo" style="padding: 0; overflow: hidden; display: flex; flex-direction: column; height: 680px; box-shadow: 0 8px 24px rgba(0,0,0,0.08);">
    
    <!-- Terminal Header Bar -->
    <div style="background: var(--bg-main); padding: 14px 20px; border-bottom: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div style="display: flex; gap: 6px;">
                <span style="width: 12px; height: 12px; border-radius: 50%; background: #ef4444; display: inline-block;"></span>
                <span style="width: 12px; height: 12px; border-radius: 50%; background: #eab308; display: inline-block;"></span>
                <span style="width: 12px; height: 12px; border-radius: 50%; background: #22c55e; display: inline-block;"></span>
            </div>
            <span style="font-weight: 800; font-size: 0.9rem; font-family: monospace;">AI Autonomous Agent Shell v2.0</span>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <span id="tools-counter-badge" class="badge-tag" style="background: #e0f2fe; color: #0284c7; font-weight: 800; display: none;">
                Инструментов: 0
            </span>
            <button class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.8rem;" onclick="clearAgentTerminal()">
                🗑️ Очистить
            </button>
        </div>
    </div>

    <!-- Messages / Execution Log Stream Area -->
    <div id="agent-stream-box" style="flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; background: var(--bg-card);">
        
        <!-- Welcome Message -->
        <div class="agent-msg agent-msg-assistant" style="display: flex; gap: 14px; align-items: flex-start;">
            <div style="font-size: 2.2rem; flex-shrink: 0;">🐕</div>
            <div style="background: var(--bg-main); border: 2px solid var(--border-color); border-radius: 16px; padding: 18px; max-width: 85%;">
                <div style="font-weight: 800; margin-bottom: 6px; color: var(--primary);">Сиба-Агент готов к работе! 🐾</div>
                <p style="font-size: 0.95rem; line-height: 1.5; margin-bottom: 10px;">
                    Я автономный агент с доступом к <strong>16 нативным инструментам</strong>. Я могу выполнять сложные многошаговые цепочки задач:
                </p>
                <ul style="font-size: 0.9rem; margin-left: 20px; line-height: 1.6; color: var(--text-muted);">
                    <li>🔍 <strong>Web Search & Fetch:</strong> поиск актуальных данных в интернете и чтение страниц.</li>
                    <li>📚 <strong>Курсы и Уроки:</strong> создание глав, уроков, упражнений всех типов.</li>
                    <li>📖 <strong>Conlang Словарь и Грамматика:</strong> генерация и запись слов Vladikish.</li>
                    <li>🎁 <strong>Промокоды, Квесты, Ачивки, Магазин:</strong> мгновенное управление экономикой.</li>
                    <li>👥 <strong>Пользователи & База Данных:</strong> аналитика, начисление наград, блокировка.</li>
                </ul>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 10px; font-style: italic;">
                    Просто напишите задание ниже или выберите готовый пресет!
                </p>
            </div>
        </div>

    </div>

    <!-- Live Execution Status Bar -->
    <div id="agent-exec-bar" style="display: none; background: rgba(28,176,246,0.1); border-top: 2px solid var(--secondary); padding: 10px 20px; font-size: 0.85rem; font-weight: 700; color: var(--secondary); align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span class="anim-spin" style="display: inline-block;">⚙️</span>
            <span id="agent-exec-status-text">Агент выполняет цепочку инструментов...</span>
        </div>
        <button onclick="stopAgentStream()" class="btn-duo btn-outline" style="padding: 2px 8px; font-size: 0.75rem; border-color: var(--danger); color: var(--danger);">
            ✕ Остановить
        </button>
    </div>

    <!-- Input Form Area -->
    <div style="padding: 16px 20px; background: var(--bg-main); border-top: 2px solid var(--border-color); display: flex; gap: 12px; align-items: flex-end;">
        <div style="flex: 1; position: relative;">
            <textarea id="agent-input-task" rows="2" class="chat-input" placeholder="Дайте задание агенту (например: «Найди в интернете и создай 5 уроков по теме Погода»)..." style="width: 100%; border-radius: 14px; padding: 12px 16px; font-size: 1rem; resize: none;"></textarea>
            <div style="position: absolute; right: 12px; bottom: 8px; font-size: 0.75rem; color: var(--text-muted); pointer-events: none;">
                Ctrl+Enter для отправки
            </div>
        </div>

        <button type="button" class="btn-duo btn-primary" id="btn-send-task" style="padding: 14px 28px; font-size: 1.05rem; display: flex; align-items: center; gap: 8px;" onclick="runAgentTask()">
            <span>🚀</span> <span>Выполнить</span>
        </button>
    </div>
</div>

<script>
let activeEventSource = null;
let conversationHistory = [];
let totalToolsCount = 0;

function setAgentPreset(promptText) {
    document.getElementById('agent-input-task').value = promptText;
    document.getElementById('agent-input-task').focus();
}

function clearAgentTerminal() {
    document.getElementById('agent-stream-box').innerHTML = `
        <div class="agent-msg agent-msg-assistant" style="display: flex; gap: 14px; align-items: flex-start;">
            <div style="font-size: 2.2rem; flex-shrink: 0;">🐕</div>
            <div style="background: var(--bg-main); border: 2px solid var(--border-color); border-radius: 16px; padding: 14px; font-size: 0.95rem;">
                Терминал очищен. Готов к новым командам! 🐾
            </div>
        </div>
    `;
    conversationHistory = [];
    totalToolsCount = 0;
    document.getElementById('tools-counter-badge').style.display = 'none';
}

function stopAgentStream() {
    if (activeEventSource) {
        activeEventSource.close();
        activeEventSource = null;
    }
    document.getElementById('agent-exec-bar').style.display = 'none';
    document.getElementById('btn-send-task').disabled = false;
    document.getElementById('agent-status-badge').textContent = '🟢 Онлайн • Готов к работе';
}

async function runAgentTask() {
    const inputEl = document.getElementById('agent-input-task');
    const task = inputEl.value.trim();
    if (!task) return;

    inputEl.value = '';
    const streamBox = document.getElementById('agent-stream-box');
    const execBar = document.getElementById('agent-exec-bar');
    const execStatusText = document.getElementById('agent-exec-status-text');
    const sendBtn = document.getElementById('btn-send-task');
    const toolsBadge = document.getElementById('tools-counter-badge');
    const statusBadge = document.getElementById('agent-status-badge');

    // 1. Render User Message
    const userMsgDiv = document.createElement('div');
    userMsgDiv.className = 'agent-msg agent-msg-user';
    userMsgDiv.style = 'display: flex; gap: 14px; align-items: flex-start; justify-content: flex-end;';
    userMsgDiv.innerHTML = `
        <div style="background: var(--primary-light); border: 2px solid var(--primary); border-radius: 16px; padding: 14px 18px; max-width: 85%; font-weight: 700;">
            ${escapeHtml(task)}
        </div>
        <div style="font-size: 2.2rem; flex-shrink: 0;">🧑‍💼</div>
    `;
    streamBox.appendChild(userMsgDiv);

    // 2. Prepare Assistant Stream Response Box
    const assistMsgDiv = document.createElement('div');
    assistMsgDiv.className = 'agent-msg agent-msg-assistant anim-bounce';
    assistMsgDiv.style = 'display: flex; gap: 14px; align-items: flex-start;';

    const contentContainerId = 'content-' + Date.now();
    const toolsContainerId = 'tools-' + Date.now();
    const reasoningContainerId = 'reasoning-' + Date.now();

    assistMsgDiv.innerHTML = `
        <div style="font-size: 2.2rem; flex-shrink: 0;">🐕</div>
        <div style="background: var(--bg-main); border: 2px solid var(--border-color); border-radius: 16px; padding: 18px; max-width: 88%; width: 100%;">
            <div id="${reasoningContainerId}" style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;"></div>
            <div id="${toolsContainerId}" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px;"></div>
            <div id="${contentContainerId}" style="font-size: 0.95rem; line-height: 1.6;"></div>
        </div>
    `;
    streamBox.appendChild(assistMsgDiv);
    streamBox.scrollTop = streamBox.scrollHeight;

    const contentDiv = document.getElementById(contentContainerId);
    const toolsDiv = document.getElementById(toolsContainerId);
    const reasoningDiv = document.getElementById(reasoningContainerId);

    // 3. Update UI to Executing
    sendBtn.disabled = true;
    execBar.style.display = 'flex';
    execStatusText.textContent = '🤖 Агент запустил анализ задачи и выбор инструментов...';
    statusBadge.textContent = '⚡ Выполняет задачу...';

    totalToolsCount = 0;
    toolsBadge.style.display = 'inline-block';
    toolsBadge.textContent = 'Инструментов: 0';

    let accumulatedContent = '';
    let accumulatedReasoning = '';
    let reasoningStartTime = Date.now();
    let reasoningBlockCreated = false;
    let reasoningBodyEl = null;
    let reasoningBadgeEl = null;

    // 4. Send Request via Fetch Stream
    try {
        const response = await fetch('../api/agent_stream.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                task: task,
                history: conversationHistory.slice(-6)
            })
        });

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop(); // keep last incomplete line

            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    const jsonStr = line.replace(/^data: /, '').trim();
                    if (!jsonStr) continue;

                    try {
                        const event = JSON.parse(jsonStr);

                        if (event.type === 'status') {
                            execStatusText.textContent = event.message;
                        } else if (event.type === 'reasoning_chunk') {
                            if (!reasoningBlockCreated) {
                                reasoningBlockCreated = true;
                                reasoningStartTime = Date.now();
                                
                                const details = document.createElement('details');
                                details.open = true;
                                details.className = 'anim-bounce';
                                details.style = 'background: rgba(168, 85, 247, 0.07); border: 1.5px solid #c084fc; border-radius: 12px; padding: 10px 14px; font-size: 0.85rem;';
                                details.innerHTML = `
                                    <summary style="cursor: pointer; font-weight: 800; color: #9333ea; display: flex; justify-content: space-between; align-items: center; user-select: none;">
                                        <span style="display: flex; align-items: center; gap: 6px;">🧠 Ход мыслей (Reasoning)</span>
                                        <span class="reasoning-badge" style="font-size: 0.75rem; background: #f3e8ff; color: #7e22ce; padding: 2px 8px; border-radius: 10px; font-weight: 700;">Думает... 🐾</span>
                                    </summary>
                                    <div class="reasoning-body" style="font-family: monospace; font-size: 0.8rem; color: var(--text-color); margin-top: 8px; line-height: 1.5; white-space: pre-wrap; max-height: 220px; overflow-y: auto; background: rgba(0,0,0,0.03); padding: 8px 10px; border-radius: 8px;"></div>
                                `;
                                reasoningDiv.appendChild(details);
                                reasoningBodyEl = details.querySelector('.reasoning-body');
                                reasoningBadgeEl = details.querySelector('.reasoning-badge');
                            }
                            
                            accumulatedReasoning += event.delta;
                            if (reasoningBodyEl) {
                                reasoningBodyEl.textContent = accumulatedReasoning;
                                reasoningBodyEl.scrollTop = reasoningBodyEl.scrollHeight;
                            }
                            if (reasoningBadgeEl) {
                                const elapsed = ((Date.now() - reasoningStartTime) / 1000).toFixed(1);
                                reasoningBadgeEl.textContent = `Анализирует • ${elapsed}с`;
                            }
                            streamBox.scrollTop = streamBox.scrollHeight;
                        } else if (event.type === 'reasoning_done') {
                            if (reasoningBadgeEl) {
                                const elapsed = ((Date.now() - reasoningStartTime) / 1000).toFixed(1);
                                reasoningBadgeEl.style.background = '#e0e7ff';
                                reasoningBadgeEl.style.color = '#4338ca';
                                reasoningBadgeEl.textContent = `✓ Мышление завершено (${elapsed}с)`;
                            }
                        } else if (event.type === 'tool_start') {
                            totalToolsCount++;
                            toolsBadge.textContent = `Инструментов: ${totalToolsCount}`;
                            
                            const toolCard = document.createElement('div');
                            toolCard.id = 'tc-' + event.call_id;
                            toolCard.className = 'anim-bounce';
                            toolCard.style = 'background: var(--bg-card); border: 2px solid #38bdf8; border-radius: 12px; padding: 10px 14px; font-size: 0.85rem;';
                            toolCard.innerHTML = `
                                <div style="display: flex; justify-content: space-between; align-items: center; font-weight: 800; color: #0284c7;">
                                    <span>🔧 Вызов инструмента #${event.tool_index}: <code>${event.name}</code></span>
                                    <span style="font-size: 0.75rem;" class="anim-spin">⚙️</span>
                                </div>
                                <div style="font-family: monospace; font-size: 0.75rem; color: var(--text-muted); margin-top: 4px; max-height: 80px; overflow-y: auto;">
                                    ${escapeHtml(JSON.stringify(event.args, null, 2))}
                                </div>
                            `;
                            toolsDiv.appendChild(toolCard);
                            streamBox.scrollTop = streamBox.scrollHeight;
                        } else if (event.type === 'tool_result') {
                            const targetCard = document.getElementById('tc-' + event.call_id);
                            if (targetCard) {
                                targetCard.style.borderColor = 'var(--primary)';
                                targetCard.innerHTML = `
                                    <div style="display: flex; justify-content: space-between; align-items: center; font-weight: 800; color: var(--primary);">
                                        <span>✓ Выполнен #${event.tool_index}: <code>${event.name}</code></span>
                                        <span style="font-size: 0.8rem; color: var(--primary);">Готово</span>
                                    </div>
                                    <div style="background: var(--bg-main); padding: 6px 10px; border-radius: 8px; font-family: monospace; font-size: 0.75rem; color: var(--text-color); margin-top: 6px; max-height: 100px; overflow-y: auto;">
                                        ${escapeHtml(JSON.stringify(event.result, null, 2))}
                                    </div>
                                `;
                            }
                            streamBox.scrollTop = streamBox.scrollHeight;
                        } else if (event.type === 'content_chunk') {
                            accumulatedContent += event.delta;
                            contentDiv.innerHTML = renderMarkdown(accumulatedContent);
                            streamBox.scrollTop = streamBox.scrollHeight;
                        } else if (event.type === 'error') {
                            contentDiv.innerHTML += `<div style="color: var(--danger); font-weight: 800; margin-top: 10px;">✕ ${escapeHtml(event.error)}</div>`;
                        } else if (event.type === 'done') {
                            execBar.style.display = 'none';
                            sendBtn.disabled = false;
                            statusBadge.textContent = '🟢 Онлайн • Готов к работе';
                            contentDiv.innerHTML = renderMarkdown(accumulatedContent);
                            
                            // Save to conversation history
                            conversationHistory.push({ role: 'user', content: task });
                            conversationHistory.push({ role: 'assistant', content: accumulatedContent });
                        }
                    } catch (e) {
                        console.error('SSE JSON error', e);
                    }
                }
            }
        }
    } catch (e) {
        contentDiv.innerHTML += `<div style="color: var(--danger); font-weight: 800; margin-top: 10px;">✕ Ошибка сетевого соединения с агентом.</div>`;
    } finally {
        execBar.style.display = 'none';
        sendBtn.disabled = false;
        statusBadge.textContent = '🟢 Онлайн • Готов к работе';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

document.getElementById('agent-input-task').addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        runAgentTask();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
