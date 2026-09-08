<?php
if (!defined('VLADAERO_ROOT')) {
    define('VLADAERO_ROOT', dirname(__DIR__));
}
$siteName = getSetting('site_name', 'VladAero');
?>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 border-t border-slate-900 mt-20 text-sm text-slate-400">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-8 mb-12">
                <!-- Col 1: Brand -->
                <div class="col-span-2">
                    <div class="flex items-center space-x-2.5 mb-4">
                        <div class="w-8 h-8 rounded-lg bg-sky-600 flex items-center justify-center text-white">
                            <i data-lucide="plane" class="w-4 h-4 transform -rotate-45"></i>
                        </div>
                        <span class="font-bold text-lg text-white tracking-tight">Vlad<span class="text-sky-400">Aero</span></span>
                    </div>
                    <p class="text-xs text-slate-400 leading-relaxed max-w-sm mb-4">
                        Всемирный авиационный портал и база знаний. Создан для пилотов, курсантов, авиаспоттеров и влюбленных в небо.
                    </p>
                    <div class="flex items-center space-x-3 text-xs text-slate-400 font-mono">
                        <span class="inline-flex items-center px-2 py-1 rounded bg-slate-900 border border-slate-800 text-sky-400">
                            ICAO / IATA / NOAA Ready
                        </span>
                        <span class="inline-flex items-center px-2 py-1 rounded bg-slate-900 border border-slate-800 text-emerald-400">
                            152-ФЗ Compliant
                        </span>
                    </div>
                </div>

                <!-- Col 2: Navigation & Weather -->
                <div>
                    <div class="font-semibold text-slate-200 text-xs uppercase tracking-wider mb-4 font-mono">Навигация</div>
                    <ul class="space-y-2 text-xs">
                        <li><a href="<?= url('/radar.php') ?>" class="hover:text-sky-400 transition">📡 Радар полетов (Live ADS-B)</a></li>
                        <li><a href="<?= url('/weather.php') ?>" class="hover:text-sky-400 transition">⛅ METAR / TAF Декодер</a></li>
                        <li><a href="<?= url('/airports.php') ?>" class="hover:text-sky-400 transition">📍 База аэропортов</a></li>
                        <li><a href="<?= url('/airlines.php') ?>" class="hover:text-sky-400 transition">🏢 Авиакомпании</a></li>
                    </ul>
                </div>

                <!-- Col 3: Tools & Encyclopedia -->
                <div>
                    <div class="font-semibold text-slate-200 text-xs uppercase tracking-wider mb-4 font-mono">Инструменты</div>
                    <ul class="space-y-2 text-xs">
                        <li><a href="<?= url('/calculators.php') ?>" class="hover:text-sky-400 transition">🧮 Калькуляторы E6B</a></li>
                        <li><a href="<?= url('/compare.php') ?>" class="hover:text-sky-400 transition">⚖️ Сравнение самолетов</a></li>
                        <li><a href="<?= url('/3d.php') ?>" class="hover:text-sky-400 transition">🧊 3D Модели WebGL</a></li>
                        <li><a href="<?= url('/soundboard.php') ?>" class="hover:text-sky-400 transition">🔊 Cockpit Soundboard</a></li>
                        <li><a href="<?= url('/boarding_pass.php') ?>" class="hover:text-sky-400 transition">🎫 Генератор посадочных</a></li>
                    </ul>
                </div>

                <!-- Col 4: Training & Community -->
                <div>
                    <div class="font-semibold text-slate-200 text-xs uppercase tracking-wider mb-4 font-mono">Сообщество</div>
                    <ul class="space-y-2 text-xs">
                        <li><a href="<?= url('/spotting.php') ?>" class="hover:text-sky-400 transition">📷 Авиаспоттинг</a></li>
                        <li><a href="<?= url('/training.php') ?>" class="hover:text-sky-400 transition">🎓 Теория и тренажеры</a></li>
                        <li><a href="<?= url('/quizzes.php') ?>" class="hover:text-sky-400 transition">🏆 Викторины и тесты</a></li>
                        <li><a href="<?= url('/incidents.php') ?>" class="hover:text-sky-400 transition">🛡️ Уроки безопасности</a></li>
                        <li><a href="<?= url('/glossary.php') ?>" class="hover:text-sky-400 transition">📖 Словарь терминов</a></li>
                    </ul>
                </div>
            </div>

            <!-- Bottom Legal Bar -->
            <div class="border-t border-slate-900 pt-6 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-400">
                <div>
                    &copy; <?= date('Y') ?> <?= e($siteName) ?>. Все права защищены.
                </div>
                <div class="flex items-center space-x-6 mt-4 sm:mt-0">
                    <a href="<?= url('/privacy.php') ?>" class="hover:text-slate-300 transition">Конфиденциальность (152-ФЗ)</a>
                    <a href="<?= url('/terms.php') ?>" class="hover:text-slate-300 transition">Пользовательское соглашение</a>
                    <a href="<?= url('/install.php') ?>" class="text-slate-400 hover:text-sky-400 transition">Консоль тех-админа</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Spotlight Search Modal (Cmd+K / Ctrl+K) -->
    <div id="spotlight-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-start justify-center pt-20 px-4">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div class="p-4 border-b border-slate-800 flex items-center space-x-3">
                <i data-lucide="search" class="w-5 h-5 text-sky-400"></i>
                <input type="text" id="spotlight-input" placeholder="Поиск самолета, ICAO/IATA кода аэропорта, термина или статьи..." class="bg-transparent text-slate-100 placeholder-slate-400 text-base focus:outline-none w-full font-sans">
                <button onclick="closeSpotlightSearch()" class="text-slate-400 hover:text-white p-1">
                    <kbd class="font-mono text-xs bg-slate-800 px-2 py-1 rounded">ESC</kbd>
                </button>
            </div>
            <div id="spotlight-results" class="max-h-96 overflow-y-auto p-3 space-y-1">
                <div class="p-4 text-center text-xs text-slate-400">
                    Введите поисковый запрос (например: <code class="text-sky-400 font-mono">A320</code>, <code class="text-sky-400 font-mono">UUEE</code>, <code class="text-sky-400 font-mono">V1</code>, <code class="text-sky-400 font-mono">Пулково</code>)
                </div>
            </div>
            <div class="p-3 bg-slate-950/80 border-t border-slate-800 text-[11px] text-slate-400 flex items-center justify-between">
                <span>Быстрый переход по базе данных VladAero</span>
                <span class="font-mono text-sky-400">Spotlight Live v1.0</span>
            </div>
        </div>
    </div>

    <!-- Floating AI Aviation Copilot Widget with Real-Time Streaming & Reasoning -->
    <div id="ai-widget-container" class="fixed bottom-6 right-6 z-50">
        <!-- Floating Trigger Button -->
        <button id="ai-widget-toggle" onclick="toggleAiWidget()" class="w-14 h-14 rounded-full bg-gradient-to-tr from-sky-600 to-cyan-400 text-white shadow-2xl shadow-sky-500/30 flex items-center justify-center hover:scale-110 active:scale-95 transition group" title="Бортовой ИИ-Ассистент VladAero">
            <i data-lucide="bot" class="w-7 h-7 group-hover:rotate-12 transition"></i>
            <span class="absolute -top-1 -right-1 w-4 h-4 bg-emerald-500 border-2 border-slate-950 rounded-full animate-ping"></span>
            <span class="absolute -top-1 -right-1 w-4 h-4 bg-emerald-500 border-2 border-slate-950 rounded-full"></span>
        </button>

        <!-- Chat Window Modal -->
        <div id="ai-widget-window" class="hidden absolute bottom-16 right-0 w-96 sm:w-[440px] h-[580px] bg-slate-900/95 backdrop-blur-xl border border-sky-500/30 rounded-2xl shadow-2xl flex flex-col overflow-hidden animate-in slide-in-from-bottom-5 duration-200">
            <!-- Header -->
            <div class="p-3.5 bg-slate-950 border-b border-slate-800 flex items-center justify-between">
                <div class="flex items-center space-x-2.5">
                    <div class="w-8 h-8 rounded-lg bg-sky-500/20 border border-sky-500/40 flex items-center justify-center text-sky-400">
                        <i data-lucide="bot" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-100 flex items-center space-x-1">
                            <span>Бортовой ИИ-Ассистент</span>
                            <span class="px-1.5 py-0.2 rounded bg-sky-950 text-sky-400 text-[10px] border border-sky-800 font-mono">Stream & Reasoning</span>
                        </div>
                        <div class="text-[10px] text-slate-400 font-mono">VladAero AI Engine</div>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <button onclick="clearAiChat()" class="text-slate-400 hover:text-white p-1 rounded hover:bg-slate-800" title="Очистить диалог">
                        <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                    </button>
                    <button onclick="toggleAiWidget()" class="text-slate-400 hover:text-white p-1 rounded hover:bg-slate-800">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <!-- Messages Stream -->
            <div id="ai-chat-messages" class="flex-grow overflow-y-auto p-4 space-y-3 text-xs leading-relaxed">
                <div class="flex items-start space-x-2">
                    <div class="w-6 h-6 rounded bg-sky-600/30 text-sky-400 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="bot" class="w-3.5 h-3.5"></i>
                    </div>
                    <div class="bg-slate-800 border border-slate-700/80 rounded-2xl rounded-tl-none p-3 text-slate-200 shadow-sm">
                        Приветствую! Я бортовой ИИ-ассистент <strong>VladAero</strong> с поддержкой потоковых рассуждений в реальном времени. Спросите меня о характеристиках самолетов, погоде METAR или навигационных расчетах!
                    </div>
                </div>
            </div>

            <!-- Input Bar -->
            <form id="ai-chat-form" onsubmit="sendAiMessage(event)" class="p-3 bg-slate-950 border-t border-slate-800 flex items-center space-x-2">
                <input type="text" id="ai-chat-input" placeholder="Задайте вопрос об авиации..." class="bg-slate-900 border border-slate-700/80 rounded-xl px-3 py-2 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-sky-500 w-full font-sans">
                <button type="submit" id="ai-chat-submit" class="p-2 bg-sky-600 hover:bg-sky-500 text-white rounded-xl shadow-sm transition flex-shrink-0">
                    <i data-lucide="send" class="w-4 h-4"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Core Scripts -->
    <script>
        // Initialize Icons
        lucide.createIcons();

        // Zulu Time Clock Updater
        function updateZuluClock() {
            const now = new Date();
            const h = String(now.getUTCHours()).padStart(2, '0');
            const m = String(now.getUTCMinutes()).padStart(2, '0');
            const s = String(now.getUTCSeconds()).padStart(2, '0');
            const el = document.getElementById('zulu-clock');
            if (el) {
                el.innerText = `Z-TIME ${h}:${m}:${s} UTC`;
            }
        }
        setInterval(updateZuluClock, 1000);
        updateZuluClock();

        // Theme Toggle (Dark / Light)
        function toggleTheme() {
            const html = document.documentElement;
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                html.classList.add('light');
                localStorage.setItem('va_theme', 'light');
            } else {
                html.classList.remove('light');
                html.classList.add('dark');
                localStorage.setItem('va_theme', 'dark');
            }
        }
        if (localStorage.getItem('va_theme') === 'light') {
            document.documentElement.classList.remove('dark');
            document.documentElement.classList.add('light');
        }

        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-menu');
            if (menu) menu.classList.toggle('hidden');
        }

        // Spotlight Search Keybinding (Cmd+K / Ctrl+K)
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openSpotlightSearch();
            }
            if (e.key === 'Escape') {
                closeSpotlightSearch();
            }
        });

        function openSpotlightSearch() {
            const modal = document.getElementById('spotlight-modal');
            const input = document.getElementById('spotlight-input');
            if (modal && input) {
                modal.classList.remove('hidden');
                input.focus();
            }
        }

        function closeSpotlightSearch() {
            const modal = document.getElementById('spotlight-modal');
            if (modal) modal.classList.add('hidden');
        }

        // Live Spotlight Search AJAX
        const spotlightInput = document.getElementById('spotlight-input');
        let searchTimer = null;
        if (spotlightInput) {
            spotlightInput.addEventListener('input', (e) => {
                clearTimeout(searchTimer);
                const q = e.target.value.trim();
                if (q.length < 2) return;
                searchTimer = setTimeout(() => {
                    fetch('<?= url('/api/search.php') ?>?q=' + encodeURIComponent(q))
                        .then(r => r.json())
                        .then(data => {
                            const resContainer = document.getElementById('spotlight-results');
                            if (!data.results || data.results.length === 0) {
                                resContainer.innerHTML = '<div class="p-4 text-center text-xs text-slate-500">Ничего не найдено по запросу «' + q + '»</div>';
                                return;
                            }
                            let html = '';
                            data.results.forEach(item => {
                                html += `<a href="${item.url}" class="block p-3 rounded-xl hover:bg-slate-800 border border-transparent hover:border-slate-700 transition flex items-center justify-between">
                                    <div>
                                        <div class="font-semibold text-slate-100 text-xs flex items-center space-x-1.5">
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-sky-950 text-sky-400 border border-sky-800">${item.badge}</span>
                                            <span>${item.title}</span>
                                        </div>
                                        <div class="text-[11px] text-slate-400 mt-0.5">${item.subtitle}</div>
                                    </div>
                                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-500"></i>
                                </a>`;
                            });
                            resContainer.innerHTML = html;
                            lucide.createIcons();
                        });
                }, 200);
            });
        }

        // AI Copilot Widget
        function toggleAiWidget() {
            const win = document.getElementById('ai-widget-window');
            if (win) {
                win.classList.toggle('hidden');
                if (!win.classList.contains('hidden')) {
                    document.getElementById('ai-chat-input').focus();
                }
            }
        }

        function clearAiChat() {
            const container = document.getElementById('ai-chat-messages');
            container.innerHTML = `
                <div class="flex items-start space-x-2">
                    <div class="w-6 h-6 rounded bg-sky-600/30 text-sky-400 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="bot" class="w-3.5 h-3.5"></i>
                    </div>
                    <div class="bg-slate-800 border border-slate-700/80 rounded-2xl rounded-tl-none p-3 text-slate-200 shadow-sm">
                        Диалог очищен. Чем я могу помочь вам сейчас?
                    </div>
                </div>`;
            aiConversationHistory = [];
            lucide.createIcons();
        }

        let aiConversationHistory = [];

        // Real-Time SSE Token & Reasoning Stream Handler
        async function sendAiMessage(e) {
            e.preventDefault();
            const input = document.getElementById('ai-chat-input');
            const msg = input.value.trim();
            if (!msg) return;

            input.value = '';
            const msgContainer = document.getElementById('ai-chat-messages');

            // Add user message
            msgContainer.innerHTML += `
                <div class="flex items-start justify-end space-x-2">
                    <div class="bg-sky-600 text-white rounded-2xl rounded-tr-none p-3 shadow-sm max-w-[85%]">
                        ${msg.replace(/</g, '&lt;').replace(/>/g, '&gt;')}
                    </div>
                </div>`;
            
            // Create Assistant Message Placeholder
            const msgId = 'ai-msg-' + Date.now();
            const reasoningBoxId = 'reasoning-' + msgId;
            const contentBoxId = 'content-' + msgId;

            msgContainer.innerHTML += `
                <div id="${msgId}" class="flex items-start space-x-2">
                    <div class="w-6 h-6 rounded bg-sky-600/30 text-sky-400 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="bot" class="w-3.5 h-3.5"></i>
                    </div>
                    <div class="bg-slate-800 border border-slate-700/80 rounded-2xl rounded-tl-none p-3 text-slate-200 shadow-sm leading-relaxed max-w-[88%] w-full">
                        <!-- Reasoning / Thinking Box -->
                        <details id="${reasoningBoxId}" class="hidden mb-2 bg-slate-950/80 border border-purple-500/30 rounded-xl p-2.5 font-mono text-[11px]" open>
                            <summary class="text-purple-300 font-bold cursor-pointer flex items-center space-x-1.5 select-none">
                                <i data-lucide="brain" class="w-3.5 h-3.5 text-purple-400 animate-pulse"></i>
                                <span>Бортовой анализ (Рассуждения ИИ)...</span>
                            </summary>
                            <div class="reasoning-text text-slate-400 mt-2 font-mono whitespace-pre-wrap leading-tight text-[10px]"></div>
                        </details>
                        <!-- Main Content -->
                        <div id="${contentBoxId}" class="content-text text-slate-200 font-sans">
                            <span class="inline-block w-2 h-3 bg-sky-400 animate-pulse"></span>
                        </div>
                    </div>
                </div>`;
            lucide.createIcons();
            msgContainer.scrollTop = msgContainer.scrollHeight;

            aiConversationHistory.push({ role: 'user', content: msg });

            let fullContent = '';
            let fullReasoning = '';

            try {
                const response = await fetch('<?= url('/api/ai_stream.php') ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ messages: aiConversationHistory })
                });

                const reader = response.body.getReader();
                const decoder = new TextDecoder('utf-8');
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const events = buffer.split("\n\n");
                    buffer = events.pop(); // keep last partial

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
                                msgContainer.scrollTop = msgContainer.scrollHeight;
                            }
                        } else if (eventType === 'content') {
                            fullContent += data.chunk;
                            const cBox = document.getElementById(contentBoxId);
                            if (cBox) {
                                cBox.innerHTML = renderMarkdown(fullContent) + '<span class="inline-block w-1.5 h-3 bg-sky-400 animate-pulse ml-0.5"></span>';
                                msgContainer.scrollTop = msgContainer.scrollHeight;
                            }
                        } else if (eventType === 'done') {
                            const cBox = document.getElementById(contentBoxId);
                            if (cBox) {
                                cBox.innerHTML = renderMarkdown(fullContent || data.full_content || 'Ответ сформирован.');
                            }
                            // Auto-collapse reasoning after done
                            const rBox = document.getElementById(reasoningBoxId);
                            if (rBox && fullReasoning) {
                                rBox.removeAttribute('open');
                            }
                            aiConversationHistory.push({ role: 'assistant', content: fullContent });
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

        function renderMarkdown(txt) {
            return txt
                .replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/`([^`]+)`/g, '<code class="bg-slate-900 px-1 rounded text-sky-400 font-mono">$1</code>')
                .replace(/\n/g, '<br>');
        }

        // PWA Service Worker Registration
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('<?= asset('/service-worker.js') ?>')
                    .then(reg => console.log('VladAero PWA SW ready:', reg.scope))
                    .catch(err => console.log('SW registration error:', err));
            });
        }
    </script>
</body>
</html>
