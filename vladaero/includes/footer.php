<?php
declare(strict_types=1);
namespace VladAero;
?>
</main>

<!-- Footer -->
<footer class="glass-hud border-t border-sky-500/20 mt-16 text-slate-400 text-xs">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
            
            <!-- Col 1: Brand -->
            <div class="space-y-4">
                <div class="flex items-center space-x-2.5">
                    <div class="w-8 h-8 rounded-lg bg-sky-600 flex items-center justify-center text-white shadow-lg shadow-sky-600/30">
                        <i data-lucide="plane" class="w-4 h-4 transform -rotate-45"></i>
                    </div>
                    <span class="text-lg font-bold font-mono text-white tracking-wider">VLAD<span class="text-amber-500">AERO</span></span>
                </div>
                <p class="text-slate-400 text-xs leading-relaxed">
                    Главный независимый авиационный портал: интерактивная энциклопедия, база ТТХ, споттерская галерея, радар полетов, лётные калькуляторы и бортовой ИИ-ассистент.
                </p>
                <div class="flex items-center space-x-2 text-[11px] font-mono text-emerald-400">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                    <span>ALL SYSTEMS NORMAL • QNH 1013</span>
                </div>
            </div>

            <!-- Col 2: Navigation -->
            <div>
                <div class="font-mono text-xs uppercase tracking-wider text-slate-200 font-bold mb-3 text-sky-400">Навигация</div>
                <ul class="space-y-2">
                    <li><a href="aircraft.php" class="hover:text-sky-400 transition">Энциклопедия ВС</a></li>
                    <li><a href="compare.php" class="hover:text-sky-400 transition">Сравнение самолётов</a></li>
                    <li><a href="radar.php" class="hover:text-sky-400 transition">Интерактивный радар</a></li>
                    <li><a href="spotting.php" class="hover:text-sky-400 transition">Споттерская фотогалерея</a></li>
                    <li><a href="airports.php" class="hover:text-sky-400 transition">Каталог аэропортов & METAR</a></li>
                </ul>
            </div>

            <!-- Col 3: Pilot & Simmer Tools -->
            <div>
                <div class="font-mono text-xs uppercase tracking-wider text-slate-200 font-bold mb-3 text-sky-400">Пилотам и симмерам</div>
                <ul class="space-y-2">
                    <li><a href="calculators.php" class="hover:text-sky-400 transition">E6B Калькуляторы & Ветры</a></li>
                    <li><a href="training.php" class="hover:text-sky-400 transition">Лётная школа & ВАК</a></li>
                    <li><a href="quizzes.php" class="hover:text-sky-400 transition">Авиационные викторины</a></li>
                    <li><a href="soundboard.php" class="hover:text-sky-400 transition">GPWS & TCAS Звуки кабины</a></li>
                    <li><a href="logbook.php" class="hover:text-sky-400 transition">Flight Logbook</a></li>
                </ul>
            </div>

            <!-- Col 4: Info & Legal -->
            <div>
                <div class="font-mono text-xs uppercase tracking-wider text-slate-200 font-bold mb-3 text-sky-400">Информация</div>
                <ul class="space-y-2">
                    <li><a href="articles.php" class="hover:text-sky-400 transition">Новости и статьи</a></li>
                    <li><a href="incidents.php" class="hover:text-sky-400 transition">Безопасность полетов</a></li>
                    <li><a href="glossary.php" class="hover:text-sky-400 transition">Глоссарий и радиообмен</a></li>
                    <li><a href="privacy.php" class="hover:text-sky-400 transition">Конфиденциальность</a></li>
                    <li><a href="terms.php" class="hover:text-sky-400 transition">Условия использования</a></li>
                </ul>
            </div>

        </div>

        <div class="border-t border-white/5 mt-10 pt-6 flex flex-col sm:flex-row items-center justify-between text-[11px] font-mono text-slate-500">
            <div>© <?= date('Y') ?> VladAero. Не для реальной аэронавигации.</div>
            <div class="mt-2 sm:mt-0 flex items-center space-x-4">
                <span>PHP 8.x + MySQL</span>
                <span>•</span>
                <span>Glass HUD Theme</span>
            </div>
        </div>
    </div>
</footer>

<!-- Global Search Modal (Ctrl+K) -->
<div id="searchModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden flex items-start justify-center pt-20 px-4">
    <div class="max-w-2xl w-full glass-hud rounded-2xl shadow-2xl border border-sky-500/30 overflow-hidden" onclick="event.stopPropagation()">
        <div class="p-4 border-b border-sky-500/20 flex items-center space-x-3">
            <i data-lucide="search" class="w-5 h-5 text-sky-400"></i>
            <input type="text" id="globalSearchInput" placeholder="Поиск самолетов, кодов ICAO/IATA, аэропортов, статей..." 
                   class="w-full bg-transparent text-slate-100 placeholder-slate-500 focus:outline-none text-sm font-mono"
                   oninput="handleGlobalSearch(this.value)">
            <button onclick="closeGlobalSearch()" class="p-1 rounded hover:bg-white/10 text-slate-400">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <div id="searchResults" class="max-h-96 overflow-y-auto p-4 space-y-2 text-sm">
            <div class="text-xs text-slate-500 font-mono text-center py-8">Введите запрос для мгновенного поиска по всей базе VladAero</div>
        </div>
    </div>
</div>

<!-- Floating AI Co-Pilot Widget -->
<?php if (get_setting('enable_ai_widget', '1') === '1'): ?>
<div id="aiWidgetContainer" class="fixed bottom-6 right-6 z-40">
    
    <!-- Widget Chat Panel -->
    <div id="aiChatPanel" class="hidden mb-4 w-96 max-w-[calc(100vw-2rem)] glass-hud rounded-2xl shadow-2xl border border-sky-500/30 overflow-hidden flex flex-col h-[520px] transition-all">
        
        <!-- Chat Header -->
        <div class="p-3.5 bg-slate-900/90 border-b border-sky-500/20 flex items-center justify-between">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center text-white shadow-md">
                    <i data-lucide="bot" class="w-4 h-4"></i>
                </div>
                <div>
                    <div class="text-xs font-bold text-white font-mono flex items-center space-x-1.5">
                        <span>БОРТОВОЙ ИИ</span>
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                    </div>
                    <div class="text-[10px] text-sky-400 font-mono">VladAero Co-Pilot</div>
                </div>
            </div>
            
            <div class="flex items-center space-x-1">
                <button onclick="toggleVoiceResponse()" id="voiceResponseBtn" class="p-1.5 rounded-lg text-slate-400 hover:text-sky-400 hover:bg-white/5 transition" title="Озвучивать ответы">
                    <i data-lucide="volume-2" class="w-4 h-4"></i>
                </button>
                <button onclick="toggleAIChat()" class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/5 transition">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        <!-- Messages Body -->
        <div id="aiChatMessages" class="flex-1 p-4 overflow-y-auto space-y-3 text-xs">
            <div class="flex items-start space-x-2">
                <div class="w-6 h-6 rounded-lg bg-sky-600/30 border border-sky-500/30 flex items-center justify-center text-sky-400 flex-shrink-0">
                    <i data-lucide="bot" class="w-3.5 h-3.5"></i>
                </div>
                <div class="bg-slate-900/80 border border-sky-500/20 p-3 rounded-2xl rounded-tl-none text-slate-200 leading-relaxed shadow-sm">
                    Приветствую на борту VladAero! Я ваш бортовой ИИ-ассистент. Могу расшифровать METAR, показать ТТХ самолетов или рассчитать снижение.
                </div>
            </div>
        </div>

        <!-- Quick Chips -->
        <div class="px-3 py-1.5 bg-slate-950/40 border-t border-white/5 flex items-center space-x-1.5 overflow-x-auto text-[11px]">
            <button onclick="sendQuickPrompt('Погода в Шереметьево UUEE')" class="px-2 py-1 rounded bg-sky-500/10 hover:bg-sky-500/20 text-sky-400 whitespace-nowrap border border-sky-500/20 transition">🌤 METAR UUEE</button>
            <button onclick="sendQuickPrompt('ТТХ самолета Ту-154М')" class="px-2 py-1 rounded bg-sky-500/10 hover:bg-sky-500/20 text-sky-400 whitespace-nowrap border border-sky-500/20 transition">✈️ Ту-154М</button>
            <button onclick="sendQuickPrompt('Расчет снижения: скорость 450 узлов, высота 35000')" class="px-2 py-1 rounded bg-sky-500/10 hover:bg-sky-500/20 text-sky-400 whitespace-nowrap border border-sky-500/20 transition">📐 Расчет TOD</button>
        </div>

        <!-- Input Box -->
        <div class="p-3 bg-slate-900/90 border-t border-sky-500/20 flex items-center space-x-2">
            <button onclick="toggleVoiceInput()" id="voiceInputBtn" class="p-2 rounded-xl bg-slate-800 text-slate-400 hover:text-amber-400 border border-slate-700 transition" title="Голосовой ввод">
                <i data-lucide="mic" class="w-4 h-4"></i>
            </button>
            <input type="text" id="aiUserInput" placeholder="Задайте вопрос ассистенту..." 
                   class="flex-1 bg-slate-950/80 border border-slate-700/80 rounded-xl px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-sky-500 font-mono"
                   onkeydown="if(event.key==='Enter') sendAIMessage()">
            <button onclick="sendAIMessage()" class="p-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white shadow-md shadow-sky-600/30 transition">
                <i data-lucide="send" class="w-4 h-4"></i>
            </button>
        </div>

    </div>

    <!-- Floating Trigger Button -->
    <button onclick="toggleAIChat()" class="relative group w-14 h-14 rounded-2xl bg-gradient-to-tr from-sky-600 to-indigo-600 flex items-center justify-center text-white shadow-2xl shadow-sky-500/30 hover:scale-105 transition-all">
        <span class="absolute -top-1 -right-1 w-3.5 h-3.5 bg-emerald-400 rounded-full border-2 border-slate-950 animate-pulse"></span>
        <i data-lucide="bot" class="w-7 h-7"></i>
    </button>
</div>
<?php endif; ?>

<!-- Sound Synthesizer & Audio Engine -->
<script>
    lucide.createIcons();

    // Global Audio Context & Synthesizer
    let audioCtx = null;
    let soundMuted = localStorage.getItem('va_sound_muted') === '1';

    function initAudio() {
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
    }

    const realClickAudio = new Audio('assets/audio/switch_click.wav');
    const realCautionAudio = new Audio('assets/audio/master_caution.wav');

    function playSwitchSound() {
        if (soundMuted) return;
        try {
            realClickAudio.currentTime = 0;
            realClickAudio.volume = 0.3;
            realClickAudio.play().catch(() => {
                initAudio();
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(800, audioCtx.currentTime);
                gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.03);
            });
        } catch (e) {}
    }

    function playMasterCautionSound() {
        if (soundMuted) return;
        try {
            realCautionAudio.currentTime = 0;
            realCautionAudio.volume = 0.5;
            realCautionAudio.play().catch(() => {});
        } catch (e) {}
    }

    function toggleGlobalSound() {
        soundMuted = !soundMuted;
        localStorage.setItem('va_sound_muted', soundMuted ? '1' : '0');
        updateSoundIcon();
        if (!soundMuted) playSwitchSound();
    }

    function updateSoundIcon() {
        const icon = document.getElementById('soundIcon');
        const btn = document.getElementById('soundToggleBtn');
        if (icon && btn) {
            if (soundMuted) {
                icon.setAttribute('data-lucide', 'volume-x');
                btn.classList.add('text-slate-600');
                btn.classList.remove('text-amber-400');
            } else {
                icon.setAttribute('data-lucide', 'volume-2');
                btn.classList.remove('text-slate-600');
                btn.classList.add('text-amber-400');
            }
            lucide.createIcons();
        }
    }
    updateSoundIcon();

    // Attach switch sound to buttons
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('button, a.btn, input[type="radio"], input[type="checkbox"]').forEach(el => {
            el.addEventListener('click', () => playSwitchSound());
        });
    });

    // Mobile Navigation Drawer
    function toggleMobileNav() {
        const nav = document.getElementById('mobileNav');
        nav.classList.toggle('hidden');
        playSwitchSound();
    }

    // Global Search Modal (Ctrl+K)
    window.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            openGlobalSearch();
        }
        if (e.key === 'Escape') {
            closeGlobalSearch();
        }
    });

    function openGlobalSearch() {
        const modal = document.getElementById('searchModal');
        modal.classList.remove('hidden');
        document.getElementById('globalSearchInput').focus();
        playSwitchSound();
    }

    function closeGlobalSearch() {
        document.getElementById('searchModal').classList.add('hidden');
    }

    let searchTimeout = null;
    function handleGlobalSearch(query) {
        clearTimeout(searchTimeout);
        if (query.trim().length < 2) {
            document.getElementById('searchResults').innerHTML = '<div class="text-xs text-slate-500 font-mono text-center py-8">Введите минимум 2 символа...</div>';
            return;
        }

        searchTimeout = setTimeout(async () => {
            try {
                const res = await fetch(`api/search.php?q=${encodeURIComponent(query)}`);
                const data = await res.json();
                renderSearchResults(data);
            } catch (e) {
                document.getElementById('searchResults').innerHTML = '<div class="text-xs text-rose-400 text-center py-4">Ошибка поиска</div>';
            }
        }, 200);
    }

    function renderSearchResults(data) {
        const container = document.getElementById('searchResults');
        if (!data || (!data.aircraft?.length && !data.airports?.length && !data.articles?.length)) {
            container.innerHTML = '<div class="text-xs text-slate-500 text-center py-6">Ничего не найдено</div>';
            return;
        }

        let html = '';
        if (data.aircraft?.length) {
            html += '<div class="text-[10px] font-mono text-sky-400 uppercase font-bold mb-1">Самолёты</div>';
            data.aircraft.forEach(p => {
                html += `<a href="aircraft.php?slug=${p.slug}" class="flex items-center justify-between p-2 rounded-lg bg-slate-900/60 hover:bg-sky-500/10 border border-white/5 hover:border-sky-500/30 transition">
                    <span class="font-bold text-white">${p.model_name}</span>
                    <span class="font-mono text-xs text-slate-400">${p.icao_code || ''}</span>
                </a>`;
            });
        }
        if (data.airports?.length) {
            html += '<div class="text-[10px] font-mono text-emerald-400 uppercase font-bold mt-3 mb-1">Аэропорты</div>';
            data.airports.forEach(a => {
                html += `<a href="airports.php?icao=${a.icao}" class="flex items-center justify-between p-2 rounded-lg bg-slate-900/60 hover:bg-emerald-500/10 border border-white/5 hover:border-emerald-500/30 transition">
                    <span class="font-bold text-white">${a.name_ru} (${a.city_ru})</span>
                    <span class="font-mono text-xs text-emerald-400">${a.icao} / ${a.iata || ''}</span>
                </a>`;
            });
        }
        container.innerHTML = html;
    }

    // AI Chat Panel Controller
    let voiceSynthesisActive = false;
    function toggleAIChat() {
        const panel = document.getElementById('aiChatPanel');
        panel.classList.toggle('hidden');
        playSwitchSound();
    }

    function toggleVoiceResponse() {
        voiceSynthesisActive = !voiceSynthesisActive;
        const btn = document.getElementById('voiceResponseBtn');
        btn.classList.toggle('text-sky-400', voiceSynthesisActive);
    }

    function sendQuickPrompt(prompt) {
        document.getElementById('aiUserInput').value = prompt;
        sendAIMessage();
    }

    async function sendAIMessage() {
        const input = document.getElementById('aiUserInput');
        const text = input.value.trim();
        if (!text) return;

        input.value = '';
        const msgContainer = document.getElementById('aiChatMessages');

        // Add user message
        msgContainer.innerHTML += `
            <div class="flex items-start justify-end space-x-2">
                <div class="bg-sky-600 text-white p-3 rounded-2xl rounded-tr-none leading-relaxed shadow-sm">
                    ${escapeHtml(text)}
                </div>
            </div>
        `;
        msgContainer.scrollTop = msgContainer.scrollHeight;

        // Bot typing placeholder
        const botMsgId = 'bot_msg_' + Date.now();
        msgContainer.innerHTML += `
            <div class="flex items-start space-x-2" id="${botMsgId}">
                <div class="w-6 h-6 rounded-lg bg-sky-600/30 border border-sky-500/30 flex items-center justify-center text-sky-400 flex-shrink-0">
                    <i data-lucide="bot" class="w-3.5 h-3.5"></i>
                </div>
                <div class="bg-slate-900/80 border border-sky-500/20 p-3 rounded-2xl rounded-tl-none text-slate-200 leading-relaxed shadow-sm flex items-center space-x-1">
                    <span class="w-1.5 h-1.5 bg-sky-400 rounded-full animate-bounce"></span>
                    <span class="w-1.5 h-1.5 bg-sky-400 rounded-full animate-bounce [animation-delay:0.2s]"></span>
                    <span class="w-1.5 h-1.5 bg-sky-400 rounded-full animate-bounce [animation-delay:0.4s]"></span>
                </div>
            </div>
        `;
        lucide.createIcons();
        msgContainer.scrollTop = msgContainer.scrollHeight;

        try {
            const res = await fetch('api/ai_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text })
            });
            const data = await res.json();
            const reply = data.content || data.error || 'Ошибка связи с бортовым ИИ.';
            
            const botEl = document.getElementById(botMsgId);
            if (botEl) {
                botEl.querySelector('div:last-child').innerHTML = reply.replace(/\n/g, '<br>');
                if (voiceSynthesisActive && 'speechSynthesis' in window) {
                    const utter = new SpeechSynthesisUtterance(reply.replace(/[*#`]/g, ''));
                    utter.lang = 'ru-RU';
                    window.speechSynthesis.speak(utter);
                }
            }
        } catch (e) {
            const botEl = document.getElementById(botMsgId);
            if (botEl) botEl.querySelector('div:last-child').innerText = 'Ошибка соединения с сервером.';
        }
        msgContainer.scrollTop = msgContainer.scrollHeight;
    }

    // Voice Microphone Input (Web Speech API)
    function toggleVoiceInput() {
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            alert('Голосовой ввод не поддерживается вашим браузером.');
            return;
        }

        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        const rec = new SpeechRec();
        rec.lang = 'ru-RU';
        rec.start();

        const btn = document.getElementById('voiceInputBtn');
        btn.classList.add('text-rose-400', 'animate-pulse');

        rec.onresult = (e) => {
            const transcript = e.results[0][0].transcript;
            document.getElementById('aiUserInput').value = transcript;
            btn.classList.remove('text-rose-400', 'animate-pulse');
            sendAIMessage();
        };

        rec.onerror = () => {
            btn.classList.remove('text-rose-400', 'animate-pulse');
        };
    }

    function escapeHtml(str) {
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
</script>

</body>
</html>
