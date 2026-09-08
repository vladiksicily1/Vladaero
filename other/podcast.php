<?php
/**
 * ShibaLingo - Radio Vladikish & Audio Podcasts (Feature #36)
 */

$pageTitle = 'Радио Vladikish & Подкасты';
require_once __DIR__ . '/includes/header.php';

$podcasts = [
    [
        'title' => 'Эпизод 1: Утро в Небесной Гавани',
        'desc' => 'Сиба-сэнсэй рассказывает о начале дня, солнце Zora и полетах над облаками Aero.',
        'duration' => '2:15',
        'transcript' => 'Mira, caros amigos! Zora vanti velo in Aero. Nos toromos lingo Vladikish. Barka bonu canta mira.'
    ],
    [
        'title' => 'Эпизод 2: Легенда о Звездном Сердце (Kaelo Korno)',
        'desc' => 'Вечерняя сказка о созвездиях и тайном языке исследователей.',
        'duration' => '3:10',
        'transcript' => 'Nox mira, Vladi. Kaelos velon in nox. Korno vanti est quando nos soniamus.'
    ]
];
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>📻</span> Радио «Эфир Vladikish» & Подкасты
        </h1>
        <p style="color: var(--text-muted);">
            Слушайте короткие аудиопередачи с живой озвучкой для тренировки восприятия речи на слух
        </p>
    </div>

    <div style="display: flex; flex-direction: column; gap: 18px;">
        <?php foreach ($podcasts as $p): ?>
            <div class="card-duo anim-bounce" style="margin-bottom: 0;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                    <h3 style="font-size: 1.2rem; font-weight: 800; color: var(--primary-shadow);"><?= e($p['title']) ?></h3>
                    <span class="badge-tag">⏱️ <?= $p['duration'] ?></span>
                </div>

                <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 16px;">
                    <?= e($p['desc']) ?>
                </p>

                <!-- Audio Controls -->
                <div style="background: var(--bg-main); padding: 14px 18px; border-radius: 14px; border: 2px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <button class="btn-duo btn-primary" onclick="speakText('<?= addslashes($p['transcript']) ?>', 'vladikish')" style="padding: 10px 20px; font-size: 0.9rem;">
                        ▶️ Слушать выпуск
                    </button>
                    <div style="font-size: 0.85rem; color: var(--text-muted); font-style: italic; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        «<?= e($p['transcript']) ?>»
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
