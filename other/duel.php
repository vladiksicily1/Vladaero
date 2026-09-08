<?php
/**
 * ShibaLingo - Live 1v1 Friend Duels (Feature #31, #23)
 */

$pageTitle = '1 на 1 Онлайн Дуэль';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$dictSample = $db->query("SELECT word, translation_ru FROM conlang_dictionary ORDER BY " . (Database::getDriver() === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT 5")->fetchAll();
$duelQuestionsJson = json_encode($dictSample, JSON_UNESCAPED_UNICODE);
?>

<div style="max-width: 750px; margin: 0 auto; text-align: center;">
    <!-- Duel Header -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span>⚔️</span> Битва 1 на 1: Дуэль Знаний
        </h1>
        <p style="color: var(--text-muted);">
            Сразитесь с другом в реальном времени: 5 быстрых вопросов, кто быстрее и точнее ответит!
        </p>
    </div>

    <!-- Duel Lobby Card -->
    <div class="card-duo anim-bounce" id="duel-lobby-card" style="padding: 36px 24px;">
        <div style="font-size: 3.5rem; margin-bottom: 12px;">🥊 🐕</div>
        <h2 style="font-size: 1.4rem; font-weight: 900; margin-bottom: 16px;">Создать дуэль или войти по коду</h2>

        <div style="display: flex; gap: 12px; max-width: 440px; margin: 0 auto 16px auto;">
            <input type="text" id="duel-room-code" class="chat-input" placeholder="Код комнаты (напр. A1B2C3)" style="margin-bottom: 0; text-transform: uppercase; font-weight: 800; text-align: center;">
            <button class="btn-duo btn-secondary" onclick="joinRealRoom()">
                Войти по коду ⚔️
            </button>
        </div>

        <div style="display: flex; justify-content: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
            <button class="btn-duo btn-outline" onclick="createRealRoom()" style="font-weight: 800;">
                🔑 Создать комнату для друга
            </button>
            <button class="btn-duo btn-primary" onclick="createBotDuel()">
                🐕 Играть с ботом Сибой
            </button>
        </div>

        <div id="duel-room-share-box" style="display: none; background: var(--bg-main); padding: 16px; border-radius: 12px; border: 2px dashed var(--primary); max-width: 440px; margin: 0 auto;">
            <div style="font-weight: 700; color: var(--text-muted); font-size: 0.85rem; margin-bottom: 4px;">Ваш код комнаты:</div>
            <div style="font-size: 2rem; font-weight: 900; color: var(--primary); letter-spacing: 4px;" id="duel-generated-code">------</div>
            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 6px;">Отправьте код другу и нажмите кнопку «Начать битву»!</div>
            <button class="btn-duo btn-primary" onclick="startHostDuel()" style="margin-top: 12px; width: 100%;">
                Начать дуэль ⚔️
            </button>
        </div>
    </div>

    <!-- Active Duel Arena -->
    <div id="duel-arena-card" style="display: none;">
        <div class="card-duo">
            <!-- Opponents Bar -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid var(--border-color);">
                <div style="text-align: left;">
                    <div style="font-weight: 900; font-size: 1.1rem;">👤 Вы</div>
                    <div style="color: var(--primary); font-weight: 800; font-size: 1.2rem;" id="duel-my-score">0 очков</div>
                </div>

                <div style="font-size: 1.6rem; font-weight: 900; color: var(--danger);">VS</div>

                <div style="text-align: right;">
                    <div style="font-weight: 900; font-size: 1.1rem;" id="duel-opponent-name">🐕 Соперник</div>
                    <div style="color: var(--secondary); font-weight: 800; font-size: 1.2rem;" id="duel-bot-score">0 очков</div>
                </div>
            </div>

            <!-- Question Area -->
            <div id="duel-q-container" style="margin-bottom: 24px;"></div>

            <!-- Answer Options -->
            <div id="duel-opts-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;"></div>
        </div>
    </div>
</div>

<script>
let duelPool = <?= $duelQuestionsJson ?>;
let duelIndex = 0;
let myScore = 0;
let botScore = 0;
let activeRoomCode = null;
let isBotMode = true;
let syncInterval = null;

async function createRealRoom() {
    try {
        const res = await fetch('api/duel_api.php?action=create_room', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            activeRoomCode = data.room_code;
            duelPool = data.questions;
            isBotMode = false;
            document.getElementById('duel-generated-code').textContent = data.room_code;
            document.getElementById('duel-room-share-box').style.display = 'block';
            SoundEngine.play('click');
        }
    } catch (e) {
        console.error('Room create error', e);
    }
}

async function joinRealRoom() {
    const code = document.getElementById('duel-room-code').value.trim().toUpperCase();
    if (!code) {
        alert('Пожалуйста, введите код комнаты!');
        return;
    }
    try {
        const formData = new FormData();
        formData.append('room_code', code);
        const res = await fetch('api/duel_api.php?action=join_room', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            activeRoomCode = code;
            duelPool = data.questions;
            isBotMode = false;
            document.getElementById('duel-opponent-name').textContent = '👤 Игрок 1';
            document.getElementById('duel-lobby-card').style.display = 'none';
            document.getElementById('duel-arena-card').style.display = 'block';
            SoundEngine.play('correct');
            startDuelSync();
            startDuelRound();
        } else {
            alert(data.error || 'Комната не найдена');
        }
    } catch (e) {
        console.error('Room join error', e);
    }
}

function startHostDuel() {
    document.getElementById('duel-opponent-name').textContent = '👤 Друг (Игрок 2)';
    document.getElementById('duel-lobby-card').style.display = 'none';
    document.getElementById('duel-arena-card').style.display = 'block';
    SoundEngine.play('correct');
    startDuelSync();
    startDuelRound();
}

function createBotDuel() {
    isBotMode = true;
    activeRoomCode = null;
    document.getElementById('duel-opponent-name').textContent = '🐕 Бот Сиба';
    document.getElementById('duel-lobby-card').style.display = 'none';
    document.getElementById('duel-arena-card').style.display = 'block';
    SoundEngine.play('correct');
    startDuelRound();
}

function startDuelSync() {
    if (isBotMode || !activeRoomCode) return;
    syncInterval = setInterval(async () => {
        try {
            const formData = new FormData();
            formData.append('room_code', activeRoomCode);
            formData.append('score', myScore);
            const res = await fetch('api/duel_api.php?action=sync_score', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                // If we are guest, opponent is host_score, else guest_score
                const opponentScore = (data.guest_score !== undefined && myScore === data.host_score) ? data.guest_score : data.host_score;
                document.getElementById('duel-bot-score').textContent = `${opponentScore} очков`;
            }
        } catch (e) {}
    }, 2000);
}

function startDuelRound() {
    if (duelIndex >= duelPool.length) {
        finishDuel();
        return;
    }

    const currentQ = duelPool[duelIndex];
    document.getElementById('duel-q-container').innerHTML = `
        <div style="font-size: 0.9rem; color: var(--text-muted); font-weight: 700; margin-bottom: 4px;">Раунд ${duelIndex + 1} из ${duelPool.length}</div>
        <h2 style="font-size: 1.6rem; font-weight: 900;">Как переводится: «<span style="color: var(--primary-shadow);">${currentQ.word}</span>»?</h2>
    `;

    const optsGrid = document.getElementById('duel-opts-grid');
    optsGrid.innerHTML = '';

    let options = [
        currentQ.translation_ru,
        'Ночь / Сон',
        'Быстрый полет',
        'Вкусная косточка'
    ];
    options.sort(() => Math.random() - 0.5);

    options.forEach(opt => {
        const btn = document.createElement('div');
        btn.className = 'choice-card';
        btn.textContent = opt;
        btn.onclick = () => answerDuel(opt === currentQ.translation_ru);
        optsGrid.appendChild(btn);
    });
}

function answerDuel(isCorrect) {
    if (isCorrect) {
        SoundEngine.play('correct');
        myScore += 100;
    } else {
        SoundEngine.play('wrong');
    }

    if (isBotMode) {
        if (Math.random() > 0.35) {
            botScore += 100;
        }
        document.getElementById('duel-bot-score').textContent = `${botScore} очков`;
    }

    document.getElementById('duel-my-score').textContent = `${myScore} очков`;

    duelIndex++;
    setTimeout(() => startDuelRound(), 800);
}

function finishDuel() {
    if (syncInterval) clearInterval(syncInterval);
    SoundEngine.play('win');
    triggerConfetti();

    const isWin = myScore >= (isBotMode ? botScore : parseInt(document.getElementById('duel-bot-score').textContent || '0'));
    document.getElementById('duel-arena-card').innerHTML = `
        <div class="card-duo anim-bounce" style="padding: 40px 24px; text-align: center;">
            <div style="font-size: 4rem; margin-bottom: 12px;">${isWin ? '🏆' : '🥈'}</div>
            <h2 style="font-size: 1.8rem; font-weight: 900; margin-bottom: 8px;">
                ${isWin ? 'Победа в Дуэли!' : 'Отличный бой!'}
            </h2>
            <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 24px;">
                Ваш результат: <strong>${myScore}</strong> очков
            </p>
            <a href="duel.php" class="btn-duo btn-primary" style="padding: 12px 32px; font-size: 1rem; text-decoration: none;">
                Сыграть снова ⚔️
            </a>
        </div>
    `;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
