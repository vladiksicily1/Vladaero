/**
 * ShibaLingo - Floating Sensei AI Chat Widget
 * Smooth Animated popup with live AI Chat and Audio pronunciation
 */

const SenseiWidget = {
    isOpen: false,
    history: [],

    init() {
        const floatBtn = document.getElementById('sensei-floating-btn');
        const widgetWindow = document.getElementById('sensei-chat-widget');
        const closeBtn = document.getElementById('sensei-widget-close');
        const sendBtn = document.getElementById('sensei-widget-send');
        const inputEl = document.getElementById('sensei-widget-input');

        if (!floatBtn || !widgetWindow) return;

        floatBtn.addEventListener('click', () => this.toggle());
        if (closeBtn) closeBtn.addEventListener('click', () => this.close());
        
        if (sendBtn && inputEl) {
            sendBtn.addEventListener('click', () => this.sendMessage());
            inputEl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }

        // Preset chips
        document.querySelectorAll('.sensei-chip-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const text = btn.dataset.prompt || btn.textContent.trim();
                if (inputEl) {
                    inputEl.value = text;
                    this.sendMessage();
                }
            });
        });
    },

    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    },

    open() {
        const widgetWindow = document.getElementById('sensei-chat-widget');
        const floatBtn = document.getElementById('sensei-floating-btn');
        if (!widgetWindow) return;

        widgetWindow.style.display = 'flex';
        setTimeout(() => {
            widgetWindow.classList.add('open');
            widgetWindow.style.transform = 'scale(1) translateY(0)';
            widgetWindow.style.opacity = '1';
        }, 10);

        if (floatBtn) {
            floatBtn.classList.add('active');
        }
        this.isOpen = true;
        SoundEngine.play('click');

        const inputEl = document.getElementById('sensei-widget-input');
        if (inputEl) inputEl.focus();

        const msgBox = document.getElementById('sensei-widget-messages');
        if (msgBox) msgBox.scrollTop = msgBox.scrollHeight;
    },

    close() {
        const widgetWindow = document.getElementById('sensei-chat-widget');
        const floatBtn = document.getElementById('sensei-floating-btn');
        if (!widgetWindow) return;

        widgetWindow.style.transform = 'scale(0.85) translateY(20px)';
        widgetWindow.style.opacity = '0';
        setTimeout(() => {
            widgetWindow.style.display = 'none';
            widgetWindow.classList.remove('open');
        }, 250);

        if (floatBtn) {
            floatBtn.classList.remove('active');
        }
        this.isOpen = false;
    },

    async sendMessage() {
        const inputEl = document.getElementById('sensei-widget-input');
        const text = inputEl ? inputEl.value.trim() : '';
        if (!text) return;

        inputEl.value = '';
        const msgBox = document.getElementById('sensei-widget-messages');
        if (!msgBox) return;

        // 1. Render User message
        const userDiv = document.createElement('div');
        userDiv.className = 'sensei-msg user-msg';
        userDiv.innerHTML = `<div class="msg-bubble user-bubble">${this.escapeHtml(text)}</div>`;
        msgBox.appendChild(userDiv);
        msgBox.scrollTop = msgBox.scrollHeight;

        SoundEngine.play('click');

        // 2. Prepare Assistant Bubble with live Reasoning Container
        const botDiv = document.createElement('div');
        botDiv.className = 'sensei-msg sensei-bot-msg anim-bounce';
        
        const reasoningId = 's-reasoning-' + Date.now();
        const contentId = 's-content-' + Date.now();
        const audioBtnId = 's-audio-' + Date.now();

        botDiv.innerHTML = `
            <div class="sensei-avatar">🐕</div>
            <div class="msg-bubble sensei-bubble" style="width: 100%; max-width: 90%;">
                <div id="${reasoningId}" style="display: none; margin-bottom: 8px;">
                    <details open style="background: rgba(168, 85, 247, 0.08); border: 1.5px solid #c084fc; border-radius: 10px; padding: 6px 10px; font-size: 0.78rem;">
                        <summary style="cursor: pointer; font-weight: 800; color: #9333ea; display: flex; justify-content: space-between; align-items: center; user-select: none;">
                            <span style="display: flex; align-items: center; gap: 4px;">🧠 Ход мыслей</span>
                            <span class="sr-badge" style="font-size: 0.7rem; background: #f3e8ff; color: #7e22ce; padding: 1px 6px; border-radius: 8px; font-weight: 700;">Думает... 🐾</span>
                        </summary>
                        <div class="sr-body" style="font-family: monospace; font-size: 0.75rem; color: var(--text-color); margin-top: 6px; line-height: 1.4; white-space: pre-wrap; max-height: 140px; overflow-y: auto; background: rgba(0,0,0,0.03); padding: 6px 8px; border-radius: 6px;"></div>
                    </details>
                </div>
                <div id="${contentId}" style="min-height: 20px; line-height: 1.5;"><span class="anim-pulse" style="color: var(--text-muted);">Сиба слушает... 🐾</span></div>
                <div id="${audioBtnId}" style="display: none; margin-top: 8px; justify-content: flex-end;">
                    <button class="btn-char-speak" style="background: var(--bg-main); border: 1px solid var(--border-color); border-radius: 8px; padding: 3px 8px; cursor: pointer; font-size: 0.8rem; font-weight: 700;">
                        🔊 Озвучить
                    </button>
                </div>
            </div>
        `;
        msgBox.appendChild(botDiv);
        msgBox.scrollTop = msgBox.scrollHeight;

        const reasoningContainer = document.getElementById(reasoningId);
        const reasoningBody = botDiv.querySelector('.sr-body');
        const reasoningBadge = botDiv.querySelector('.sr-badge');
        const contentEl = document.getElementById(contentId);
        const audioBtnContainer = document.getElementById(audioBtnId);

        let accumulatedReasoning = '';
        let accumulatedContent = '';
        let reasoningStartTime = Date.now();
        let isFirstContent = true;

        // 3. Send request to SSE streaming endpoint
        try {
            const response = await fetch('api/ai_widget_stream.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: text,
                    lang: window.currentLangCode || 'vladikish',
                    history: this.history.slice(-4)
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
                buffer = lines.pop();

                for (const line of lines) {
                    if (line.startsWith('data: ')) {
                        const jsonStr = line.replace(/^data: /, '').trim();
                        if (!jsonStr) continue;

                        try {
                            const event = JSON.parse(jsonStr);

                            if (event.type === 'reasoning_chunk') {
                                if (reasoningContainer && reasoningContainer.style.display === 'none') {
                                    reasoningContainer.style.display = 'block';
                                    reasoningStartTime = Date.now();
                                }
                                accumulatedReasoning += event.delta;
                                if (reasoningBody) {
                                    reasoningBody.textContent = accumulatedReasoning;
                                    reasoningBody.scrollTop = reasoningBody.scrollHeight;
                                }
                                if (reasoningBadge) {
                                    const elapsed = ((Date.now() - reasoningStartTime) / 1000).toFixed(1);
                                    reasoningBadge.textContent = `Размышляет • ${elapsed}с`;
                                }
                                msgBox.scrollTop = msgBox.scrollHeight;
                            } else if (event.type === 'reasoning_done') {
                                if (reasoningBadge) {
                                    const elapsed = ((Date.now() - reasoningStartTime) / 1000).toFixed(1);
                                    reasoningBadge.style.background = '#e0e7ff';
                                    reasoningBadge.style.color = '#4338ca';
                                    reasoningBadge.textContent = `✓ Размышления (${elapsed}с)`;
                                }
                            } else if (event.type === 'content_chunk') {
                                if (isFirstContent) {
                                    isFirstContent = false;
                                    contentEl.innerHTML = '';
                                }
                                accumulatedContent += event.delta;
                                contentEl.innerHTML = (typeof renderMarkdown === 'function') ? renderMarkdown(accumulatedContent) : this.escapeHtml(accumulatedContent).replace(/\n/g, '<br>');
                                msgBox.scrollTop = msgBox.scrollHeight;
                            } else if (event.type === 'error') {
                                contentEl.innerHTML = `<span style="color: var(--danger); font-weight: 700;">✕ ${this.escapeHtml(event.error)}</span>`;
                            } else if (event.type === 'done') {
                                SoundEngine.play('correct');
                                contentEl.innerHTML = (typeof renderMarkdown === 'function') ? renderMarkdown(accumulatedContent) : this.escapeHtml(accumulatedContent).replace(/\n/g, '<br>');
                                this.history.push({ role: 'user', content: text });
                                this.history.push({ role: 'assistant', content: accumulatedContent || event.reply });

                                if (audioBtnContainer && accumulatedContent) {
                                    audioBtnContainer.style.display = 'flex';
                                    const speakBtn = audioBtnContainer.querySelector('button');
                                    if (speakBtn) {
                                        speakBtn.onclick = () => {
                                            speakText(accumulatedContent, window.currentLangCode || 'vladikish');
                                        };
                                    }
                                }
                            }
                        } catch (e) {
                            console.error('Sensei SSE error', e);
                        }
                    }
                }
            }
        } catch (e) {
            contentEl.innerHTML = `<span style="color: var(--danger);">✕ Не удалось соединиться с Сибой. Попробуйте еще раз.</span>`;
        }

        msgBox.scrollTop = msgBox.scrollHeight;
    },

    escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
};

document.addEventListener('DOMContentLoaded', () => {
    SenseiWidget.init();
});

window.SenseiWidget = SenseiWidget;
