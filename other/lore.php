<?php
/**
 * ShibaLingo - AI Folklore, Myths & Proverbs (Feature #42)
 */

$pageTitle = 'Мифы и Пословицы Vladikish';
require_once __DIR__ . '/includes/header.php';

$proverbs = [
    [
        'vladikish' => 'Korno bonu zora velo.',
        'translation' => 'Доброе сердце светит ярче солнца.',
        'meaning' => 'Искренность и доброта рассеивают любую тьму.'
    ],
    [
        'vladikish' => 'Barka vanti in Aero non cadit.',
        'translation' => 'Счастливый Шиба в небесах не падает.',
        'meaning' => 'Уверенность в друзьях дает крылья для любых высот.'
    ],
    [
        'vladikish' => 'Nox longa, sed zora semper venit.',
        'translation' => 'Ночь долгая, но рассвет всегда приходит.',
        'meaning' => 'Любые трудности временны, если продолжать свой путь.'
    ]
];
?>

<div style="max-width: 860px; margin: 0 auto; padding-bottom: 40px;">
    <div style="margin-bottom: 20px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
            <span>📜</span> Мудрость и Пословицы цивилизации Vladikish
        </h1>
        <p style="color: var(--text-muted); font-size: 1rem; margin-bottom: 16px;">
            Крылатые выражения, легенды и философские поговорки на языке Vladikish
        </p>

        <!-- Sub Navigation Tabs -->
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="conlang.php" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
                📚 Словарь и Правила
            </a>
            <a href="conjugator.php" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
                🔄 Спрягатель глаголов
            </a>
            <a href="lore.php" class="btn-duo btn-primary" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
                📜 Лор и История
            </a>
            <a href="translator.php" class="btn-duo btn-outline" style="padding: 10px 18px; font-size: 0.95rem; text-decoration: none;">
                🌐 Переводчик
            </a>
        </div>
    </div>

    <!-- Proverbs List -->
    <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 28px;">
        <?php foreach ($proverbs as $p): ?>
            <div class="card-duo anim-bounce" style="margin-bottom: 0;">
                <div style="font-size: 1.3rem; font-weight: 900; color: var(--primary-shadow); margin-bottom: 6px;">
                    «<?= e($p['vladikish']) ?>»
                </div>
                <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">
                    💬 <?= e($p['translation']) ?>
                </div>
                <div style="font-size: 0.9rem; color: var(--text-muted); border-top: 1px solid var(--border-color); padding-top: 8px;">
                    💡 <em>Значение:</em> <?= e($p['meaning']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- AI Generate New Myth -->
    <div class="card-duo" style="text-align: center;">
        <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 8px;">Хотите новую пословицу от AI?</h3>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 16px;">
            NVIDIA AI сгенерирует уникальную философскую мысль по правилам Vladikish
        </p>
        <button class="btn-duo btn-secondary" id="btn-gen-lore" style="padding: 12px 32px;">
            ✨ Сгенерировать мудрость дня
        </button>
        <div id="ai-lore-box" style="display: none; margin-top: 16px; text-align: left; background: var(--bg-main); padding: 18px; border-radius: 14px; border: 2px solid var(--border-color);"></div>
    </div>
</div>

<script>
document.getElementById('btn-gen-lore').addEventListener('click', async () => {
    const box = document.getElementById('ai-lore-box');
    box.style.display = 'block';
    box.textContent = 'Сиба-сэнсэй обращается к мудрости древних свитков... 🐾';
    SoundEngine.play('click');

    try {
        const res = await fetch('api/ai.php?action=chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: 1,
                message: '[TASK: Vladikish Proverbs Generator]. Invent 1 deep philosophical proverb in Vladikish using official vocabulary (Aero, Zora, Nox, Barka, Korno, Vanti, Toro). Format: 1) Proverb in Vladikish, 2) Russian translation, 3) Deep philosophical explanation.',
                lang: 'vladikish'
            })
        });
        const data = await res.json();
        if (data.success) {
            box.innerHTML = `<div style="font-size: 1.15rem; font-weight: 800; color: var(--primary-shadow);">${data.reply}</div>` + (data.translation ? `<div style="margin-top: 8px; color: var(--text-muted);">💬 ${data.translation}</div>` : '');
            SoundEngine.play('correct');
        }
    } catch(e) {
        box.textContent = 'Ошибка связи с сервером.';
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
