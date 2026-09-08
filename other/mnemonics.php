<?php
/**
 * ShibaLingo - AI Mnemonics & Association Generator (Feature #45)
 */

$pageTitle = 'AI Мнемоники и Ассоциации';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 750px; margin: 0 auto;">
    <div style="margin-bottom: 24px; text-align: center;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span>💡</span> AI Генератор Мнемоник и Ассоциаций
        </h1>
        <p style="color: var(--text-muted);">
            Слово трудно запомнить? NVIDIA AI придумает забавную рифму, ассоциацию и зрительный образ!
        </p>
    </div>

    <!-- Input Card -->
    <div class="card-duo anim-bounce" style="margin-bottom: 24px;">
        <div style="display: flex; gap: 12px; margin-bottom: 12px;">
            <input type="text" id="mnem-word" class="chat-input" placeholder="Слово (напр. Korno или Danko)" style="margin-bottom: 0; font-weight: 800; font-size: 1.1rem;" value="Korno">
            <button class="btn-duo btn-primary" id="btn-gen-mnem" style="padding: 12px 28px; white-space: nowrap;">
                Придумать образ ✨
            </button>
        </div>
    </div>

    <!-- Output Card -->
    <div class="card-duo" id="mnem-result-card" style="display: none;">
        <h3 style="font-size: 1.2rem; font-weight: 800; color: var(--secondary); margin-bottom: 14px;">
            🧠 Мнемоническая карточка от Сиба-сэнсэя:
        </h3>
        <div id="mnem-output" style="font-size: 1.05rem; line-height: 1.6; color: var(--text-main); white-space: pre-wrap;"></div>
    </div>
</div>

<script>
document.getElementById('btn-gen-mnem').addEventListener('click', async () => {
    const word = document.getElementById('mnem-word').value.trim();
    if (!word) return;

    const out = document.getElementById('mnem-output');
    const card = document.getElementById('mnem-result-card');
    card.style.display = 'block';
    out.textContent = 'Сиба-сэнсэй придумывает яркую ассоциацию... 🐾';
    SoundEngine.play('click');

    try {
        const res = await fetch('api/ai.php?action=chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: 1,
                message: `[TASK: Language Mnemonic Generator]. Create a fun, catchy Russian mnemonic rhyme and vivid visual memory trick to easily remember the Vladikish/foreign word "${word}". Include phonetic hook, funny image, and mini rhyme.`,
                lang: 'ru'
            })
        });
        const data = await res.json();
        if (data.success) {
            out.textContent = data.reply;
            SoundEngine.play('correct');
        } else {
            out.textContent = 'Ошибка: ' + data.error;
        }
    } catch(e) {
        out.textContent = 'Ошибка связи с сервером.';
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
