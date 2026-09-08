<?php
/**
 * ShibaLingo - AI Roleplay Scenarios (Feature #7)
 */

$pageTitle = 'AI Ролевые Сценарии';
require_once __DIR__ . '/includes/header.php';

$scenarios = [
    [
        'id' => 'cafe',
        'title' => 'В кафе Vladikish ☕',
        'icon' => '☕',
        'desc' => 'Закажите кофе, десерт и пообщайтесь с бариста Сибой на языке Vladikish.',
        'prompt' => 'You are Barista Shiba at a cozy cafe. Greet the customer in Vladikish (Mira! Plaso, cofi velo?) and help them order breakfast.'
    ],
    [
        'id' => 'airport',
        'title' => 'Регистрация в Аэропорту ✈️',
        'icon' => '✈️',
        'desc' => 'Зарегистрируйтесь на рейс в небесный город и проверьте посадочный талон.',
        'prompt' => 'You are Flight Officer Shiba at Aero Terminal. Check the user ticket and ask for their destination in Vladikish/English.'
    ],
    [
        'id' => 'alien',
        'title' => 'Первый контакт с Пришельцем 🛸',
        'icon' => '🛸',
        'desc' => 'Вы приземлились на неизведанной планете и учите инопланетянина земным словам.',
        'prompt' => 'You are an alien visiting from constellation Kaelo. You speak Vladikish and are curious about Earth.'
    ],
    [
        'id' => 'interview',
        'title' => 'Собеседование на работу 💼',
        'icon' => '💼',
        'desc' => 'Пройдите дружелюбное собеседование в Академию Исследователей.',
        'prompt' => 'You are Headmaster Shiba conducting a fun job interview for Explorer Academy.'
    ]
];
?>

<div style="max-width: 850px; margin: 0 auto;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>🎭</span> AI Ролевые Сценарии (NVIDIA AI)
        </h1>
        <p style="color: var(--text-muted);">Практикуйте реальные диалоги в разных жизненных ситуациях с маскотом</p>
    </div>

    <!-- Scenarios Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;" id="scenarios-list">
        <?php foreach ($scenarios as $sc): ?>
            <div class="card-duo anim-bounce" style="display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 0;">
                <div>
                    <div style="font-size: 3rem; margin-bottom: 12px;"><?= $sc['icon'] ?></div>
                    <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 8px;"><?= e($sc['title']) ?></h3>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 20px;">
                        <?= e($sc['desc']) ?>
                    </p>
                </div>
                <button class="btn-duo btn-primary" onclick='startScenario(<?= json_encode($sc) ?>)'>
                    Начать сценарий ▶️
                </button>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Live Scenario Chat Stage -->
    <div id="scenario-chat-stage" style="display: none; margin-top: 20px;">
        <div class="card-duo">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 2px solid var(--border-color); padding-bottom: 12px;">
                <h3 id="scenario-active-title" style="font-size: 1.3rem; font-weight: 800;"></h3>
                <button class="btn-duo btn-outline" onclick="exitScenario()" style="padding: 6px 14px; font-size: 0.85rem;">
                    ✕ Выйти
                </button>
            </div>

            <div id="scenario-messages-box" style="height: 350px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; margin-bottom: 16px; padding: 12px; background: var(--bg-main); border-radius: 16px;"></div>

            <div style="display: flex; gap: 10px;">
                <input type="text" id="scenario-user-input" class="chat-input" placeholder="Ответьте персонажу на Vladikish или русском..." style="margin-bottom: 0;">
                <button class="btn-duo btn-primary" id="btn-send-scenario" style="padding: 12px 24px;">
                    Ответить 🐾
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentScenario = null;

function startScenario(sc) {
    currentScenario = sc;
    document.getElementById('scenarios-list').style.display = 'none';
    document.getElementById('scenario-chat-stage').style.display = 'block';
    document.getElementById('scenario-active-title').textContent = sc.title;
    
    const box = document.getElementById('scenario-messages-box');
    box.innerHTML = '';
    
    appendScenarioMsg('shiba', `Mira, Vladi! Добро пожаловать в сценарий «${sc.title}»! Начнем диалог:`);
}

function exitScenario() {
    document.getElementById('scenarios-list').style.display = 'grid';
    document.getElementById('scenario-chat-stage').style.display = 'none';
}

function appendScenarioMsg(sender, text) {
    const box = document.getElementById('scenario-messages-box');
    const msg = document.createElement('div');
    msg.className = `chat-msg ${sender} anim-bounce`;
    msg.innerHTML = `<div class="msg-bubble">${text}</div>`;
    box.appendChild(msg);
    box.scrollTop = box.scrollHeight;
}

document.getElementById('btn-send-scenario').addEventListener('click', sendScenarioMsg);
document.getElementById('scenario-user-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') sendScenarioMsg();
});

async function sendScenarioMsg() {
    const input = document.getElementById('scenario-user-input');
    const text = input.value.trim();
    if (!text || !currentScenario) return;

    input.value = '';
    appendScenarioMsg('user', text);

    try {
        const res = await fetch('api/ai.php?action=chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: 1,
                message: `[ROLEPLAY SCENARIO: ${currentScenario.title}]. User said: ${text}`,
                lang: 'vladikish'
            })
        });
        const data = await res.json();
        if (data.success) {
            appendScenarioMsg('shiba', data.reply + (data.translation ? `<br><small style="color: var(--text-muted);">💬 ${data.translation}</small>` : ''));
            SoundEngine.play('correct');
        }
    } catch(e) {
        appendScenarioMsg('shiba', 'Гав! Ошибка связи.');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
