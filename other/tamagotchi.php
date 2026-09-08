<?php
/**
 * ShibaLingo - Tamagotchi Pet Hub (Feature #60)
 */

$pageTitle = 'Тамагочи с Сибой';
require_once __DIR__ . '/includes/header.php';

$userGems = (int)($user['gems'] ?? 50);
?>

<div style="max-width: 700px; margin: 0 auto; text-align: center;">
    <div style="margin-bottom: 20px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span>🍖</span> Тамагочи с Сиба-сэнсэем
        </h1>
        <p style="color: var(--text-muted);">
            Заботьтесь о вашем питомце: кормите лакомствами за кристаллы 💎, гладьте и повышайте уровень счастья!
        </p>
    </div>

    <!-- Main Pet Arena -->
    <div class="card-duo anim-bounce" style="padding: 36px 20px; background: linear-gradient(180deg, var(--bg-card), var(--bg-main));">
        <!-- Status Bars -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; max-width: 450px; margin: 0 auto 24px auto; text-align: left;">
            <div>
                <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 800; margin-bottom: 4px;">
                    <span>🍖 Сытость</span>
                    <span id="pet-hunger-val">85%</span>
                </div>
                <div class="progress-bar-duo" style="height: 12px;">
                    <div class="progress-bar-fill" id="pet-hunger-bar" style="width: 85%; background: var(--accent);"></div>
                </div>
            </div>

            <div>
                <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 800; margin-bottom: 4px;">
                    <span>💖 Счастье</span>
                    <span id="pet-happy-val">95%</span>
                </div>
                <div class="progress-bar-duo" style="height: 12px;">
                    <div class="progress-bar-fill" id="pet-happy-bar" style="width: 95%; background: var(--primary);"></div>
                </div>
            </div>
        </div>

        <!-- Interactive Mascot Body -->
        <div id="tamagotchi-pet-body" style="cursor: pointer; display: inline-block; margin-bottom: 16px;" onclick="petShiba()" title="Кликните, чтобы погладить Шибу!"></div>
        
        <div id="pet-speech-bubble" style="font-size: 1.1rem; font-weight: 700; color: var(--primary-shadow); min-height: 28px; margin-bottom: 24px;">
            «Гав! Почеши мне за ушком или угости косточкой! 🐾»
        </div>

        <!-- Treat Buttons -->
        <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
            <button class="btn-duo btn-outline" onclick="feedTreat('cookie', 5)" style="padding: 10px 18px;">
                🍪 Печенька (💎 5)
            </button>
            <button class="btn-duo btn-outline" onclick="feedTreat('bone', 10)" style="padding: 10px 18px;">
                🦴 Косточка (💎 10)
            </button>
            <button class="btn-duo btn-outline" onclick="feedTreat('ramen', 25)" style="padding: 10px 18px;">
                🍜 Рамен (💎 25)
            </button>
            <button class="btn-duo btn-primary" onclick="petShiba()" style="padding: 10px 20px;">
                👋 Погладить
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    ShibaMascot.setSkin('<?= $user['selected_skin'] ?? 'classic' ?>');
    ShibaMascot.update('tamagotchi-pet-body', 'happy');
});

async function feedTreat(treat, cost) {
    try {
        const res = await fetch('api/tamagotchi_api.php?action=feed_pet', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ treat: treat })
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            triggerConfetti();
            document.getElementById('pet-speech-bubble').textContent = data.message;
            ShibaMascot.update('tamagotchi-pet-body', 'celebrate');
            setTimeout(() => ShibaMascot.update('tamagotchi-pet-body', 'happy'), 1500);
        } else {
            SoundEngine.play('wrong');
            alert(data.error);
        }
    } catch(e) {
        alert('Ошибка связи с сервером');
    }
}

async function petShiba() {
    SoundEngine.play('click');
    try {
        const res = await fetch('api/tamagotchi_api.php?action=pet_shiba');
        const data = await res.json();
        if (data.success) {
            document.getElementById('pet-speech-bubble').textContent = data.message;
            const el = document.getElementById('tamagotchi-pet-body');
            el.classList.add('anim-bounce');
            setTimeout(() => el.classList.remove('anim-bounce'), 500);
        }
    } catch(e) {}
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
