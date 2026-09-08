<?php
/**
 * ShibaLingo - Cooperative Boss Raid: Error Golem (Feature #33)
 */

$pageTitle = 'Рейд на Босса Ошибок';
// Load initial boss status
$db = getDb();
try {
    $boss = $db->query("SELECT * FROM raid_bosses ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $boss = null;
}
$bossName = $boss['boss_name'] ?? 'Древний Голем Ошибок';
$bossLevel = $boss['level'] ?? 50;
$bossMaxHp = (int)($boss['max_hp'] ?? 10000);
$bossCurrentHp = (int)($boss['current_hp'] ?? 4250);
$hpPercent = round(($bossCurrentHp / $bossMaxHp) * 100);
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto; text-align: center;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span>👾</span> Мировой Рейд: «<?= e($bossName) ?>»
        </h1>
        <p style="color: var(--text-muted);">
            Кооперативное событие: здоровье босса синхронизировано для всех учеников платформы в реальном времени!
        </p>
    </div>

    <!-- Boss Arena Card -->
    <div class="card-duo anim-bounce" style="padding: 36px 24px; background: linear-gradient(180deg, #1e1b4b, #0f172a); color: white; border: none; box-shadow: 0 8px 0 #020617;">
        <div style="font-size: 5rem; margin-bottom: 8px; filter: drop-shadow(0 0 20px #8b5cf6);">
            👾 ⚡
        </div>
        <h2 style="font-size: 1.6rem; font-weight: 900; color: #c084fc; margin-bottom: 6px;" id="boss-title">
            <?= e($bossName) ?> (Уровень <span id="boss-lvl"><?= $bossLevel ?></span>)
        </h2>
        <p style="font-size: 0.95rem; color: #cbd5e1; margin-bottom: 20px;">
            Осталось здоровья: <strong id="boss-hp-text"><?= $bossCurrentHp ?> / <?= $bossMaxHp ?> HP</strong> (<span id="boss-percent"><?= $hpPercent ?></span>%)
        </p>

        <!-- Boss Health Bar -->
        <div style="max-width: 500px; margin: 0 auto 28px auto;">
            <div class="progress-bar-duo" style="height: 20px; background: rgba(255,255,255,0.1); border-radius: 10px;">
                <div class="progress-bar-fill" id="boss-hp-bar" style="width: <?= $hpPercent ?>%; background: linear-gradient(90deg, #ec4899, #8b5cf6);"></div>
            </div>
        </div>

        <!-- Attack Button -->
        <button class="btn-duo btn-primary" id="btn-attack-boss" onclick="attackBoss()" style="font-size: 1.15rem; padding: 16px 40px; box-shadow: 0 4px 0 #15803d;">
            ⚔️ Атаковать правильным ответом (+50 DMG)
        </button>
        <div id="raid-dmg-indicator" style="font-size: 1.3rem; font-weight: 900; color: #facc15; margin-top: 14px; min-height: 30px;"></div>
    </div>
</div>

<script>
let bossMax = <?= $bossMaxHp ?>;
let isAttacking = false;

async function attackBoss() {
    if (isAttacking) return;
    isAttacking = true;
    const btn = document.getElementById('btn-attack-boss');
    btn.disabled = true;

    try {
        const res = await fetch('api/raid_api.php?action=attack', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            const percent = Math.round((data.new_hp / data.max_hp) * 100);
            document.getElementById('boss-hp-bar').style.width = percent + '%';
            document.getElementById('boss-hp-text').textContent = data.new_hp + ' / ' + data.max_hp + ' HP';
            document.getElementById('boss-percent').textContent = percent;

            const dmgEl = document.getElementById('raid-dmg-indicator');
            dmgEl.textContent = `💥 УДАР! -${data.damage} HP Боссу! Заработано +10 XP!`;
            dmgEl.classList.add('anim-bounce');
            setTimeout(() => dmgEl.classList.remove('anim-bounce'), 500);

            if (data.is_defeated) {
                SoundEngine.play('win');
                triggerConfetti();
                dmgEl.textContent = '🎉 БОСС ПОВЕРЖЕН! Уровень повышен! Все ученики получают награду 💎!';
            }
        }
    } catch (e) {
        console.error('Raid attack error', e);
    } finally {
        btn.disabled = false;
        isAttacking = false;
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
