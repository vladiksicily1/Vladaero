/**
 * ShibaLingo - AI Chat with Shiba Inu Mascot
 */

class ShibaChat {
    constructor(sessionId, langCode) {
        this.sessionId = sessionId;
        this.langCode = langCode;
        this.isWaiting = false;
        
        this.init();
    }

    init() {
        const input = document.getElementById('chat-user-input');
        const sendBtn = document.getElementById('btn-send-msg');
        
        if (input && sendBtn) {
            sendBtn.addEventListener('click', () => this.sendMessage());
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }

        this.scrollToBottom();
    }

    scrollToBottom() {
        const box = document.getElementById('chat-messages-box');
        if (box) box.scrollTop = box.scrollHeight;
    }

    async sendMessage() {
        const input = document.getElementById('chat-user-input');
        const text = input.value.trim();
        if (!text || this.isWaiting) return;

        input.value = '';
        this.isWaiting = true;
        SoundEngine.play('click');

        // Append user bubble to UI immediately
        this.appendMessage('user', text);

        // Shiba typing indicator
        const typingId = this.showTypingIndicator();

        try {
            const res = await fetch('api/ai.php?action=chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: this.sessionId,
                    message: text,
                    lang: this.langCode
                })
            });

            const data = await res.json();
            this.removeTypingIndicator(typingId);

            if (data.success) {
                SoundEngine.play('correct');
                this.appendMessage('shiba', data.reply, data.translation, data.corrections);
            } else {
                SoundEngine.play('wrong');
                this.appendMessage('shiba', `Гав! Ошибка: ${data.error}`, 'Пожалуйста, проверьте настройки API-ключа NVIDIA.');
            }
        } catch (e) {
            this.removeTypingIndicator(typingId);
            this.appendMessage('shiba', 'Гав! Произошла ошибка связи с сервером.', 'Проверьте интернет-соединение.');
        } finally {
            this.isWaiting = false;
        }
    }

    appendMessage(sender, message, translation = null, corrections = null) {
        const box = document.getElementById('chat-messages-box');
        if (!box) return;

        const msgEl = document.createElement('div');
        msgEl.className = `chat-msg ${sender} anim-bounce`;

        const avatar = sender === 'shiba' 
            ? `<div style="flex-shrink: 0;">${ShibaMascot.renderSVG('happy', 44)}</div>` 
            : `<div style="flex-shrink: 0; font-size: 1.8rem; background: var(--bg-main); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--border-color);">👤</div>`;

        let translationHtml = '';
        if (translation) {
            translationHtml = `<div class="msg-translation">💬 <em>${translation}</em></div>`;
        }

        let correctionsHtml = '';
        if (corrections) {
            correctionsHtml = `<div class="msg-corrections">💡 <strong>Совет Сиба-сэнсэя:</strong> ${corrections}</div>`;
        }

        msgEl.innerHTML = `
            ${avatar}
            <div>
                <div class="msg-bubble">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                        <div>${(typeof renderMarkdown === 'function') ? renderMarkdown(message) : message}</div>
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem; border-radius: 8px;" onclick="speakText('${message.replace(/'/g, "\\'")}', '${this.langCode}')">
                            🔊
                        </button>
                    </div>
                    ${translationHtml}
                </div>
                ${correctionsHtml}
            </div>
        `;

        box.appendChild(msgEl);
        this.scrollToBottom();
    }

    showTypingIndicator() {
        const box = document.getElementById('chat-messages-box');
        const id = 'typing-' + Date.now();
        const typingEl = document.createElement('div');
        typingEl.id = id;
        typingEl.className = 'chat-msg shiba';
        typingEl.innerHTML = `
            <div style="flex-shrink: 0;">${ShibaMascot.renderSVG('thinking', 44)}</div>
            <div class="msg-bubble" style="color: var(--text-muted); font-style: italic;">
                Сиба-сэнсэй думает и печатает ответ... 🐾
            </div>
        `;
        box.appendChild(typingEl);
        this.scrollToBottom();
        return id;
    }

    removeTypingIndicator(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }
}

window.ShibaChat = ShibaChat;
