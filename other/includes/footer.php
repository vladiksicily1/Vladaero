        </main>

        <footer style="text-align: center; padding: 24px 20px; color: var(--text-muted); font-size: 0.85rem; border-top: 1px solid var(--border-color); margin-top: 40px; width: 100%;">
            <div>
                © <?= date('Y') ?> <strong><?= e(defined('APP_NAME') ? APP_NAME : 'ShibaLingo') ?></strong> — Платформа для изучения языков и Vladikish 🐕
            </div>
            <div style="margin-top: 8px; display: flex; justify-content: center; gap: 14px; font-weight: 700; flex-wrap: wrap;">
                <a href="faq.php" style="color: var(--text-muted); text-decoration: none;">База знаний & FAQ</a>
                <span>•</span>
                <a href="teacher.php" style="color: var(--text-muted); text-decoration: none;">Для учителей</a>
                <span>•</span>
                <a href="referral.php" style="color: var(--text-muted); text-decoration: none;">Пригласить друга</a>
                <span>•</span>
                <a href="certificate.php" style="color: var(--text-muted); text-decoration: none;">Диплом Vladikish</a>
                <span>•</span>
                <a href="privacy.php" style="color: var(--text-muted); text-decoration: none;">Политика конфиденциальности</a>
                <span>•</span>
                <a href="terms.php" style="color: var(--text-muted); text-decoration: none;">Условия использования</a>
            </div>
        </footer>
    </div>
</div>

<!-- Cookie Consent Banner (GDPR & 152-ФЗ) -->
<div id="cookie-consent-banner" style="display: none; position: fixed; bottom: 20px; left: 20px; right: 20px; max-width: 600px; margin: 0 auto; background: var(--bg-card, #ffffff); border: 2px solid var(--border-color); border-radius: 16px; padding: 16px 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); z-index: 9999; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
    <div style="font-size: 0.9rem; line-height: 1.4; color: var(--text-main); flex: 1; min-width: 260px;">
        🍪 Мы используем Cookie для авторизации и сохранения вашего прогресса. <a href="privacy.php" style="color: var(--secondary); font-weight: 700; text-decoration: none;">Политика конфиденциальности / Privacy</a>
    </div>
    <div style="display: flex; gap: 8px;">
        <button onclick="acceptCookies()" class="btn-duo btn-primary" style="padding: 6px 16px; font-size: 0.85rem;">
            Принять / Accetta ✓
        </button>
    </div>
</div>

<!-- Floating Sensei AI Chat Button (Bottom Right) -->
<div class="sensei-floating-btn anim-bounce" id="sensei-floating-btn" title="Спросить Сиба-сэнсэя 🐕">
    <span>🐕</span>
    <span class="pulse-dot"></span>
</div>

<!-- Floating Sensei AI Chat Widget Popup -->
<div class="sensei-chat-widget" id="sensei-chat-widget" style="display: none;">
    <!-- Header -->
    <div class="sensei-widget-header">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 1.6rem;">🐕</div>
            <div>
                <div style="font-weight: 900; font-size: 0.95rem;">Сиба-Сэнсэй (AI Тьютор)</div>
                <div style="font-size: 0.75rem; opacity: 0.9;">🟢 Онлайн • Всегда на связи</div>
            </div>
        </div>
        <button id="sensei-widget-close" style="background: none; border: none; color: white; font-size: 1.3rem; cursor: pointer; opacity: 0.9;">✕</button>
    </div>

    <!-- Messages -->
    <div class="sensei-widget-messages" id="sensei-widget-messages">
        <div class="sensei-msg sensei-bot-msg">
            <div class="sensei-avatar" style="font-size: 1.6rem;">🐕</div>
            <div class="msg-bubble sensei-bubble">
                Привет! Я твой личный Сиба-Сэнсэй 🐾 Чем помочь? Могу объяснить грамматику, потренировать диалог или перевести незнакомое слово!
            </div>
        </div>
    </div>

    <!-- Quick Prompt Chips -->
    <div class="sensei-chips-bar">
        <button type="button" class="sensei-chip-btn" data-prompt="Поговори со мной на Vladikish простыми фразами">🗣️ Диалог</button>
        <button type="button" class="sensei-chip-btn" data-prompt="Объясни правила прошедшего времени во Vladikish">📐 Грамматика</button>
        <button type="button" class="sensei-chip-btn" data-prompt="Дай совет, как лучше учить слова каждый день">💡 Совет</button>
        <button type="button" class="sensei-chip-btn" data-prompt="Назови 5 красивых слов на Conlang и их перевод">✨ 5 слов</button>
    </div>

    <!-- Input Bar -->
    <div class="sensei-widget-input-bar">
        <input type="text" id="sensei-widget-input" class="chat-input" placeholder="Напиши вопрос Сибе..." style="padding: 10px 14px; font-size: 0.9rem; border-radius: 14px;">
        <button type="button" class="btn-duo btn-primary" id="sensei-widget-send" style="padding: 10px 16px; font-size: 1.1rem; border-radius: 14px;">
            ➤
        </button>
    </div>
</div>

<script>
window.currentLangCode = '<?= e($currentLangCode ?? 'vladikish') ?>';
</script>
<script src="assets/js/mascot.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/vladikish-voice.js"></script>
<script src="assets/js/sensei-widget.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!localStorage.getItem('shiba_cookie_consent')) {
        const b = document.getElementById('cookie-consent-banner');
        if (b) b.style.display = 'flex';
    }
});
function acceptCookies() {
    localStorage.setItem('shiba_cookie_consent', 'true');
    const b = document.getElementById('cookie-consent-banner');
    if (b) b.style.display = 'none';
}

// PWA Service Worker Registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('./service-worker.js').catch(err => {
            console.log('PWA ServiceWorker not registered:', err);
        });
    });
}
</script>

</body>
</html>
