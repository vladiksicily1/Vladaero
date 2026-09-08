<?php
/**
 * ShibaLingo - Interactive 2D World Map of Vladikish Realm (Feature #39)
 */

$pageTitle = 'Карта Мира Vladikish';
require_once __DIR__ . '/includes/header.php';

$locations = [
    [
        'id' => 'aero',
        'name' => 'Небесная Гавань Aero ✈️',
        'desc' => 'Город летающих кораблей и планеров. Здесь изучают слова о высоте, свободе и ветре.',
        'x' => 20,
        'y' => 25,
        'icon' => '✈️',
        'xp' => '+50 XP'
    ],
    [
        'id' => 'zora',
        'name' => 'Солнечная Долина Zora ☀️',
        'desc' => 'Сердце цивилизации Vladikish, где круглый год светит яркое солнце и цветут сады.',
        'x' => 65,
        'y' => 30,
        'icon' => '☀️',
        'xp' => '+40 XP'
    ],
    [
        'id' => 'barka',
        'name' => 'Заповедник Верных Шиб Barka 🐕',
        'desc' => 'Священный остров собак породы Шиба-Ину и их мудрого наставника Сиба-сэнсэя.',
        'x' => 45,
        'y' => 55,
        'icon' => '🐾',
        'xp' => '+75 XP'
    ],
    [
        'id' => 'nox',
        'name' => 'Ночной Кибер-Порт Nox 🌌',
        'desc' => 'Футуристичный неоновый мегаполис, работающий под сиянием звезд Kaelo.',
        'x' => 80,
        'y' => 75,
        'icon' => '🕶️',
        'xp' => '+60 XP'
    ]
];
?>

<div style="max-width: 900px; margin: 0 auto;">
    <div style="margin-bottom: 20px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>🗺️</span> Интерактивная Карта Мира Vladikish
        </h1>
        <p style="color: var(--text-muted);">
            Исследуйте провинции, кликайте на локации и открывайте тайные легенды и диалекты!
        </p>
    </div>

    <!-- Map Canvas Container -->
    <div class="card-duo anim-bounce" style="position: relative; height: 480px; background: radial-gradient(circle, #e0f2fe, #bae6fd); border: 3px solid #7dd3fc; border-radius: 24px; overflow: hidden; box-shadow: 0 8px 0 #38bdf8;">
        <!-- Background Grid / Islands SVG -->
        <svg style="position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none;">
            <path d="M 50 150 Q 150 50 300 120 T 500 100" stroke="#7dd3fc" stroke-width="4" fill="none" stroke-dasharray="8 8"/>
            <path d="M 300 120 Q 400 300 550 280 T 750 350" stroke="#7dd3fc" stroke-width="4" fill="none" stroke-dasharray="8 8"/>
            <ellipse cx="200" cy="140" rx="90" ry="60" fill="#dcfce7" stroke="#86efac" stroke-width="3"/>
            <ellipse cx="600" cy="180" rx="110" ry="75" fill="#fef9c3" stroke="#fde047" stroke-width="3"/>
            <ellipse cx="420" cy="320" rx="100" ry="70" fill="#fed7aa" stroke="#fdba74" stroke-width="3"/>
            <ellipse cx="720" cy="380" rx="80" ry="50" fill="#f3e8ff" stroke="#d8b4fe" stroke-width="3"/>
        </svg>

        <!-- Interactive Map Pins -->
        <?php foreach ($locations as $loc): ?>
            <div class="map-pin anim-float" style="position: absolute; left: <?= $loc['x'] ?>%; top: <?= $loc['y'] ?>%; transform: translate(-50%, -50%); cursor: pointer; text-align: center; z-index: 10;" onclick='showLocationModal(<?= json_encode($loc) ?>)'>
                <div style="background: var(--bg-card); width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; box-shadow: 0 6px 15px rgba(0,0,0,0.15); border: 3px solid var(--primary);">
                    <?= $loc['icon'] ?>
                </div>
                <div style="background: var(--bg-card); padding: 4px 10px; border-radius: 10px; font-weight: 800; font-size: 0.85rem; margin-top: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); white-space: nowrap;">
                    <?= e($loc['name']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Location Details Modal -->
    <div id="map-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
        <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3 id="loc-modal-title" style="font-size: 1.3rem; font-weight: 900;"></h3>
                <button onclick="closeLocationModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
            </div>
            <p id="loc-modal-desc" style="color: var(--text-muted); font-size: 1rem; line-height: 1.5; margin-bottom: 20px;"></p>
            <a href="index.php" class="btn-duo btn-primary" style="width: 100%;">
                Пройти испытание локации 🚀
            </a>
        </div>
    </div>
</div>

<script>
function showLocationModal(loc) {
    SoundEngine.play('click');
    document.getElementById('loc-modal-title').textContent = loc.name;
    document.getElementById('loc-modal-desc').textContent = loc.desc;
    document.getElementById('map-modal').style.display = 'flex';
}
function closeLocationModal() {
    document.getElementById('map-modal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
