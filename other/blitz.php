<?php
/**
 * ShibaLingo - Speed Match Blitz Arcade Game (Feature #6)
 */

$pageTitle = 'Блиц-сопоставление слов';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$dictWords = $db->query("SELECT word, translation_ru FROM conlang_dictionary ORDER BY " . (Database::getDriver() === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT 12")->fetchAll();
$dictJson = json_encode($dictWords, JSON_UNESCAPED_UNICODE);
?>

<div style="max-width: 700px; margin: 0 auto; text-align: center;">
    <!-- Blitz Top Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div style="font-weight: 800; font-size: 1.2rem; color: var(--streak-color);">
            🔥 Комбо: <span id="blitz-combo">x1</span>
        </div>
        <div style="font-size: 1.6rem; font-weight: 900; background: var(--bg-card); border: 2px solid var(--border-color); padding: 6px 20px; border-radius: 20px;" id="blitz-timer-badge">
            ⏱️ <span id="blitz-timer">60</span>s
        </div>
        <div style="font-weight: 800; font-size: 1.2rem; color: #eab308;">
            ⚡ Очки: <span id="blitz-score">0</span>
        </div>
    </div>

    <!-- Blitz Game Stage -->
    <div class="card-duo" id="blitz-stage" style="padding: 32px;">
        <div id="blitz-start-screen">
            <div style="font-size: 3.5rem; margin-bottom: 12px;">⚡ 🐾</div>
            <h1 style="font-size: 1.8rem; font-weight: 900; margin-bottom: 8px;">Блиц-сопоставление (Speed Match)</h1>
            <p style="color: var(--text-muted); font-size: 1rem; margin-bottom: 24px;">
                Соедините как можно больше пар слов на Vladikish за 60 секунд! Не сбивайте серию комбо!
            </p>
            <button class="btn-duo btn-primary" id="btn-start-blitz" style="font-size: 1.2rem; padding: 16px 48px;">
                Погнали! 🚀
            </button>
        </div>

        <div id="blitz-play-screen" style="display: none;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;" id="blitz-grid">
                <div id="blitz-col-left" style="display: flex; flex-direction: column; gap: 10px;"></div>
                <div id="blitz-col-right" style="display: flex; flex-direction: column; gap: 10px;"></div>
            </div>
        </div>

        <div id="blitz-finish-screen" style="display: none;" class="anim-bounce">
            <div style="font-size: 4rem; margin-bottom: 12px;">🏆 🎉</div>
            <h1 style="font-size: 2rem; font-weight: 900; color: var(--primary); margin-bottom: 8px;">Время вышло!</h1>
            <p style="font-size: 1.2rem; color: var(--text-muted); margin-bottom: 20px;">
                Ваш финальный результат: <strong style="color: #eab308;" id="final-score">0</strong> очков!
            </p>
            <div style="display: flex; justify-content: center; gap: 16px;">
                <button class="btn-duo btn-primary" onclick="location.reload()">
                    Играть снова 🔄
                </button>
                <a href="index.php" class="btn-duo btn-outline">
                    В меню
                </a>
            </div>
        </div>
    </div>
</div>

<script>
const wordsPool = <?= $dictJson ?>;
let timeLeft = 60;
let score = 0;
let combo = 1;
let timerInterval = null;
let selectedLeft = null;
let matchedCount = 0;

document.getElementById('btn-start-blitz').addEventListener('click', startBlitz);

function startBlitz() {
    document.getElementById('blitz-start-screen').style.display = 'none';
    document.getElementById('blitz-play-screen').style.display = 'block';
    SoundEngine.play('correct');

    renderPairs();

    timerInterval = setInterval(() => {
        timeLeft--;
        document.getElementById('blitz-timer').textContent = timeLeft;
        if (timeLeft <= 10) {
            document.getElementById('blitz-timer-badge').style.borderColor = 'var(--danger)';
            document.getElementById('blitz-timer-badge').style.color = 'var(--danger)';
        }
        if (timeLeft <= 0) {
            finishBlitz();
        }
    }, 1000);
}

function renderPairs() {
    const leftCol = document.getElementById('blitz-col-left');
    const rightCol = document.getElementById('blitz-col-right');
    leftCol.innerHTML = '';
    rightCol.innerHTML = '';

    const currentSlice = wordsPool.slice(0, 6);
    let leftItems = currentSlice.map((w, idx) => ({ text: w.word, id: idx }));
    let rightItems = currentSlice.map((w, idx) => ({ text: w.translation_ru, id: idx }));

    rightItems.sort(() => Math.random() - 0.5);

    leftItems.forEach(item => {
        const div = document.createElement('div');
        div.className = 'choice-card blitz-tile';
        div.textContent = item.text;
        div.dataset.id = item.id;
        div.onclick = () => selectLeft(div, item.id);
        leftCol.appendChild(div);
    });

    rightItems.forEach(item => {
        const div = document.createElement('div');
        div.className = 'choice-card blitz-tile';
        div.textContent = item.text;
        div.dataset.id = item.id;
        div.onclick = () => selectRight(div, item.id);
        rightCol.appendChild(div);
    });
}

function selectLeft(el, id) {
    if (el.classList.contains('matched')) return;
    document.querySelectorAll('#blitz-col-left .blitz-tile').forEach(t => t.classList.remove('selected'));
    el.classList.add('selected');
    selectedLeft = { el, id };
    SoundEngine.play('click');
}

function selectRight(el, id) {
    if (!selectedLeft || el.classList.contains('matched')) return;

    if (selectedLeft.id === id) {
        // MATCH!
        SoundEngine.play('correct');
        selectedLeft.el.classList.add('matched');
        el.classList.add('matched');
        selectedLeft.el.style.opacity = '0.3';
        el.style.opacity = '0.3';

        score += 10 * combo;
        combo++;
        matchedCount++;

        document.getElementById('blitz-score').textContent = score;
        document.getElementById('blitz-combo').textContent = 'x' + combo;

        selectedLeft = null;

        if (matchedCount >= 6) {
            matchedCount = 0;
            renderPairs();
        }
    } else {
        // ERROR
        SoundEngine.play('wrong');
        combo = 1;
        document.getElementById('blitz-combo').textContent = 'x1';
        el.classList.add('anim-shake');
        selectedLeft.el.classList.add('anim-shake');
        setTimeout(() => {
            el.classList.remove('anim-shake', 'selected');
            if (selectedLeft) selectedLeft.el.classList.remove('anim-shake', 'selected');
            selectedLeft = null;
        }, 400);
    }
}

function finishBlitz() {
    clearInterval(timerInterval);
    SoundEngine.play('win');
    triggerConfetti();
    document.getElementById('blitz-play-screen').style.display = 'none';
    document.getElementById('blitz-finish-screen').style.display = 'block';
    document.getElementById('final-score').textContent = score;

    // Award XP to user
    fetch('api/lesson_complete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lesson_id: 1, xp: Math.min(50, Math.floor(score / 5)) })
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
