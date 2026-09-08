/**
 * VladAero — AI Widget (SSE Streaming Chat)
 */
(function() {
    'use strict';

    const AIWidget = {
        panel: null,
        messages: null,
        input: null,
        sendBtn: null,
        conversationId: null,
        isStreaming: false,

        init() {
            this.panel = document.getElementById('aiPanel');
            this.messages = document.getElementById('aiMessages');
            this.input = document.getElementById('aiInput');
            this.sendBtn = document.getElementById('aiSend');
            const trigger = document.getElementById('aiTrigger');
            const closeBtn = document.getElementById('aiClose');
            const minBtn = document.getElementById('aiMinimize');
            const widget = document.getElementById('aiWidget');

            if (!widget || !this.panel) return;

            // Toggle open/close
            if (trigger) {
                trigger.addEventListener('click', () => widget.classList.add('open'));
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', () => widget.classList.remove('open'));
            }
            if (minBtn) {
                minBtn.addEventListener('click', () => widget.classList.remove('open'));
            }

            // Send message
            if (this.sendBtn) {
                this.sendBtn.addEventListener('click', () => this.sendMessage());
            }
            if (this.input) {
                this.input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.sendMessage();
                    }
                });
                // Auto-resize
                this.input.addEventListener('input', () => {
                    this.input.style.height = 'auto';
                    this.input.style.height = Math.min(this.input.scrollHeight, 100) + 'px';
                });
            }

            // Quick chips
            document.querySelectorAll('.ai-chip').forEach(chip => {
                chip.addEventListener('click', () => {
                    this.input.value = chip.dataset.prompt;
                    this.sendMessage();
                });
            });
        },

        async sendMessage() {
            const text = this.input.value.trim();
            if (!text || this.isStreaming) return;

            // Clear welcome
            const welcome = this.messages.querySelector('.ai-widget__welcome');
            if (welcome) welcome.remove();

            // Add user message
            this.addMessage('user', text);
            this.input.value = '';
            this.input.style.height = 'auto';

            // Typing indicator
            const typing = this.showTyping();

            this.isStreaming = true;

            try {
                const resp = await fetch('/api/ai/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        message: text,
                        conversation_id: this.conversationId
                    })
                });

                typing.remove();

                if (!resp.ok) {
                    this.addMessage('assistant', '⚠️ Ошибка соединения с AI. Попробуйте позже.');
                    this.isStreaming = false;
                    return;
                }

                // Check if streaming
                const contentType = resp.headers.get('content-type') || '';

                if (contentType.includes('text/event-stream')) {
                    // SSE streaming
                    await this.handleSSE(resp);
                } else {
                    // Regular JSON response
                    const data = await resp.json();
                    this.conversationId = data.conversation_id;
                    if (data.tool_calls) {
                        await this.handleToolCalls(data.tool_calls);
                    }
                    this.addMessage('assistant', data.response || 'Нет ответа');
                }
            } catch(e) {
                typing.remove();
                this.addMessage('assistant', '⚠️ Ошибка: ' + e.message);
            }

            this.isStreaming = false;
        },

        async handleSSE(resp) {
            const reader = resp.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let assistantMsg = null;
            let msgEl = null;

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;
                    const data = line.slice(6).trim();
                    if (data === '[DONE]') break;

                    try {
                        const parsed = JSON.parse(data);

                        if (parsed.type === 'conversation_id') {
                            this.conversationId = parsed.id;
                            continue;
                        }

                        if (parsed.type === 'tool_call') {
                            await this.handleToolCalls([parsed]);
                            continue;
                        }

                        if (parsed.type === 'chunk' || parsed.type === 'text') {
                            if (!msgEl) {
                                msgEl = this.createMessageEl('assistant', '');
                                this.messages.appendChild(msgEl);
                            }
                            const text = parsed.content || parsed.text || '';
                            msgEl.querySelector('.ai-msg__text').innerHTML += this.formatText(text);
                            this.scrollToBottom();
                        }
                    } catch(e) {}
                }
            }
        },

        async handleToolCalls(tools) {
            for (const tool of tools) {
                const name = tool.function?.name || tool.name;
                const args = tool.function?.arguments || tool.args || {};

                let resultHtml = '';
                switch(name) {
                    case 'render_aircraft_card':
                        resultHtml = this.renderAircraftCardMini(args);
                        break;
                    case 'show_flight_on_radar':
                        resultHtml = `<div class="ai-msg--tool">📍 Показываю на радаре: ${args.registration || args.callsign || ''}</div>`;
                        break;
                    case 'start_quiz':
                        resultHtml = `<div class="ai-msg--tool">🧠 Запускаю викторину...</div>`;
                        break;
                    default:
                        resultHtml = `<div class="ai-msg--tool">🔧 ${name}(${JSON.stringify(args).substring(0, 100)})</div>`;
                }
                if (resultHtml) {
                    const el = document.createElement('div');
                    el.innerHTML = resultHtml;
                    this.messages.appendChild(el.firstElementChild);
                }
            }
            this.scrollToBottom();
        },

        renderAircraftCardMini(args) {
            const specs = args.specs || {};
            return `<div class="ai-aircraft-card">
                ${args.image ? `<img class="ai-aircraft-card__img" src="${args.image}" alt="${args.name}">` : ''}
                <div class="ai-aircraft-card__info">
                    <div class="ai-aircraft-card__name">${args.name || 'Самолёт'}</div>
                    <div class="ai-aircraft-card__specs">
                        ${specs.speed ? `<span>🛫 ${specs.speed}</span>` : ''}
                        ${specs.range ? `<span>📏 ${specs.range}</span>` : ''}
                        ${specs.passengers ? `<span>👥 ${specs.passengers} пасс.</span>` : ''}
                        ${specs.ceiling ? `<span>⬆️ ${specs.ceiling}</span>` : ''}
                    </div>
                    ${args.url ? `<a href="${args.url}" class="btn btn--sm btn--outline" style="margin-top:.5rem">Подробнее →</a>` : ''}
                </div>
            </div>`;
        },

        addMessage(role, text) {
            const el = this.createMessageEl(role, text);
            this.messages.appendChild(el);
            this.scrollToBottom();
        },

        createMessageEl(role, text) {
            const el = document.createElement('div');
            el.className = `ai-msg ai-msg--${role}`;
            el.innerHTML = `<div class="ai-msg__text">${this.formatText(text)}</div>`;
            return el;
        },

        formatText(text) {
            if (!text) return '';
            // Basic markdown-like formatting
            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\n/g, '<br>');
        },

        showTyping() {
            const el = document.createElement('div');
            el.className = 'ai-typing';
            el.innerHTML = '<div class="ai-typing__dot"></div><div class="ai-typing__dot"></div><div class="ai-typing__dot"></div>';
            this.messages.appendChild(el);
            this.scrollToBottom();
            return el;
        },

        scrollToBottom() {
            if (this.messages) {
                this.messages.scrollTop = this.messages.scrollHeight;
            }
        }
    };

    document.addEventListener('DOMContentLoaded', () => AIWidget.init());

})();
