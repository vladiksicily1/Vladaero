<?php
/**
 * ShibaLingo - Learning Path / Skill Tree (Duolingo-style)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Show Landing page if guest
if (!isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/landing.php';
    exit;
}

$pageTitle = 'Обучение и уровни';
require_once __DIR__ . '/includes/header.php';

$skills = getSkillsWithProgress($currentLangCode, $user['id']);
?>

<div style="display: flex; gap: 32px; align-items: flex-start; justify-content: center;">
    <!-- Center Skill Tree Path -->
    <div style="flex-grow: 1; max-width: 650px;">
        <!-- Top Section Banner -->
        <div class="section-banner anim-bounce">
            <div>
                <h1 style="font-size: 1.6rem; font-weight: 900; margin-bottom: 4px;">
                    <?= e($currentLangObj['name']) ?> — Раздел 1
                </h1>
                <p style="font-size: 0.95rem; opacity: 0.9;">
                    <?= e($currentLangObj['description']) ?>
                </p>
            </div>
            <div style="font-size: 2.8rem;">
                <?= $currentLangObj['flag'] ?>
            </div>
        </div>

        <!-- Path Nodes (Duolingo S-Curve Snake) -->
        <div class="path-container">
            <?php if (empty($skills)): ?>
                <div class="card-duo" style="text-align: center; padding: 40px;">
                    <div style="font-size: 3rem; margin-bottom: 12px;">🐾</div>
                    <h3>Уроков пока нет для этого языка</h3>
                    <p style="color: var(--text-muted); margin-bottom: 20px;">Преподаватель скоро добавит новые интерактивные уроки!</p>
                </div>
            <?php else: ?>
                <?php 
                $sOffsets = ['0px', '45px', '75px', '45px', '0px', '-45px', '-75px', '-45px'];
                $foundActive = false;

                foreach ($skills as $index => $skill): 
                    $offset = $sOffsets[$index % count($sOffsets)];
                    $isCompleted = ($skill['completed_lessons'] >= $skill['total_lessons'] && $skill['total_lessons'] > 0);
                    $isActive = (!$foundActive && !$isCompleted);
                    if ($isActive) { $foundActive = true; }

                    // Find first uncompleted lesson in this skill
                    $firstUncompletedLesson = null;
                    foreach ($skill['lessons'] as $les) {
                        if ((int)($les['is_completed'] ?? 0) === 0) {
                            $firstUncompletedLesson = $les;
                            break;
                        }
                    }
                    $targetLesson = $firstUncompletedLesson ?? ($skill['lessons'][0] ?? null);
                    $lessonUrl = $targetLesson ? "lesson.php?id=" . $targetLesson['id'] : "#";
                    $icon = !empty($skill['icon']) ? $skill['icon'] : '🐾';
                ?>
                    <!-- Optional Section Gate Checkpoint -->
                    <?php if ($index > 0 && $index % 4 === 0): ?>
                        <div class="section-checkpoint-gate">
                            <div class="checkpoint-badge">
                                <span>🏰</span>
                                <span>Раздел <?= (int)($index / 4) + 1 ?>: Прорыв</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="skill-node-wrapper" style="transform: translateX(<?= $offset ?>);">
                        <?php if ($isActive): ?>
                            <a href="<?= $lessonUrl ?>" class="node-start-tooltip" style="text-decoration: none; color: inherit;">
                                <span>🐾</span>
                                <span>НАЧАТЬ +20 XP</span>
                            </a>
                        <?php endif; ?>

                        <button type="button" class="skill-node <?= $isCompleted ? 'completed' : ($isActive ? 'active-node' : '') ?>" onclick='openSkillDetailsModal(<?= json_encode($skill, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($targetLesson, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="<?= e($skill['title']) ?>">
                            <span><?= $isCompleted ? '👑' : $icon ?></span>
                        </button>

                        <div class="skill-title" onclick='openSkillDetailsModal(<?= json_encode($skill, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($targetLesson, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' style="cursor: pointer;">
                            <?= e($skill['title']) ?>
                        </div>
                        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700;">
                            <?= $isCompleted ? '✓ Пройдено' : ($skill['completed_lessons'] . ' / ' . $skill['total_lessons']) ?>
                        </div>
                    </div>

                    <!-- Milestone Treasure Chest between sections -->
                    <?php if ($index % 2 === 1 && $index < count($skills) - 1): ?>
                        <div class="path-chest-wrapper" style="transform: translateX(<?= $sOffsets[($index + 1) % count($sOffsets)] ?>);" onclick="openChestModal(<?= $index ?>)" title="Открыть сундук с кристаллами!">
                            <div class="path-chest-node anim-bounce">
                                <span>🎁</span>
                            </div>
                            <span style="font-size: 0.75rem; font-weight: 800; color: #d97706; margin-top: 4px;">БОНУС</span>
                        </div>
                    <?php endif; ?>

                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Side Duolingo Widgets Area -->
    <div style="width: 330px; display: flex; flex-direction: column; gap: 20px; position: sticky; top: 96px;">
        
        <!-- Widget 1: Daily XP Goal Widget -->
        <div class="card-duo" style="padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h4 style="font-weight: 800; font-size: 1.05rem; display: flex; align-items: center; gap: 6px;">
                    <span>🎯</span> <span>Дневная цель</span>
                </h4>
                <span style="font-weight: 800; color: var(--primary); font-size: 0.9rem;">
                    <?= (int)$user['xp'] % 50 ?> / 50 XP
                </span>
            </div>
            <div class="progress-bar-duo" style="height: 14px; margin-bottom: 10px;">
                <div class="progress-bar-fill" style="width: <?= min(100, (((int)$user['xp'] % 50) / 50) * 100) ?>%;"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-muted); font-weight: 700;">
                <span>🔥 Стрик: <?= (int)$user['streak'] ?> дней</span>
                <span>❄️ Заморозка: <?= ((int)($user['streak_freeze'] ?? 0) > 0) ? 'Активна' : 'Нет' ?></span>
            </div>
        </div>

        <!-- Widget: Spaced Repetition / Mistakes Review SRS -->
        <div class="card-duo" style="padding: 20px; background: linear-gradient(135deg, rgba(168,85,247,0.1), rgba(28,176,246,0.1)); border-color: #a855f7;">
            <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 10px;">
                <div style="font-size: 2.2rem;">🧠</div>
                <div>
                    <div style="font-weight: 900; font-size: 1rem; color: #7e22ce;">Интервальное повторение</div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">SM-2 алгоритм запоминания</div>
                </div>
            </div>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">
                Закрепите изученные слова в долговременной памяти за 3 минуты!
            </p>
            <a href="review.php" class="btn-duo btn-secondary" style="width: 100%; padding: 10px; font-size: 0.9rem; text-align: center; text-decoration: none; display: block; border-radius: 12px; font-weight: 800;">
                🧠 Повторить слова (8 слов) →
            </a>
        </div>

        <!-- Widget 2: Leaderboard League Mini-Standings -->
        <div class="card-duo" style="padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                <h4 style="font-weight: 800; font-size: 1.05rem; display: flex; align-items: center; gap: 6px;">
                    <span>🏆</span> <span>Золотая лига</span>
                </h4>
                <a href="leaderboard.php" style="font-size: 0.85rem; font-weight: 700; color: var(--secondary); text-decoration: none;">ТАБЛИЦА →</a>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.9rem; font-weight: 800;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span>🥇</span> <span>Vladik_Master</span>
                    </div>
                    <span style="color: #eab308;">1 420 XP</span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.9rem; font-weight: 800; background: var(--primary-light); padding: 6px 10px; border-radius: 10px; border-left: 4px solid var(--primary);">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span>🥈</span> <span><?= e($user['username']) ?> (Вы)</span>
                    </div>
                    <span style="color: var(--primary);"><?= (int)$user['xp'] ?> XP</span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.9rem; font-weight: 700; color: var(--text-muted);">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span>🥉</span> <span>ShibaFan_99</span>
                    </div>
                    <span>890 XP</span>
                </div>
            </div>

            <div style="background: rgba(88, 204, 2, 0.1); color: var(--primary-shadow); padding: 8px 12px; border-radius: 10px; font-size: 0.8rem; font-weight: 800; text-align: center;">
                🟢 Вы в топ-3! Зона перехода в Сапфировую лигу
            </div>
        </div>

        <!-- Widget 3: Monthly Shiba Quest Graphic Banner -->
        <div class="card-duo" style="padding: 20px; background: linear-gradient(135deg, rgba(28,176,246,0.1), rgba(88,204,2,0.1)); border-color: var(--secondary);">
            <div style="display: flex; gap: 14px; align-items: center; margin-bottom: 12px;">
                <div style="font-size: 2.4rem;">🎖️</div>
                <div>
                    <div style="font-weight: 900; font-size: 1rem;">Августовский значок Сибы</div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">Завершите 20 квестов в августе</div>
                </div>
            </div>
            <div class="progress-bar-duo" style="height: 10px; margin-bottom: 8px;">
                <div class="progress-bar-fill" style="width: 70%; background: var(--secondary);"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; font-weight: 800; color: var(--secondary);">
                <span>14 / 20 квестов</span>
                <span>Осталось 2 дня</span>
            </div>
        </div>

        <!-- Widget 4: Shiba Mascot Quick Chat -->
        <div class="card-duo" style="text-align: center; padding: 20px;">
            <div id="home-shiba-mascot" style="margin-bottom: 12px;"></div>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">
                «Гав! Попробуй поговорить со мной в AI-чате на Vladikish!»
            </p>
            <a href="chat.php" class="btn-duo btn-outline" style="width: 100%; padding: 8px; font-size: 0.9rem;">
                Чат с Сибой 💬
            </a>
        </div>
    </div>
</div>

<!-- Modal: Skill / Chapter Details Drawer (All Lessons Picker) -->
<div id="modal-skill-details" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 520px; width: 100%; margin-bottom: 0; max-height: 90vh; overflow-y: auto; padding: 28px 24px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div style="display: flex; gap: 14px; align-items: center;">
                <div id="skill-modal-icon" style="font-size: 2.5rem; background: var(--bg-main); width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; border-radius: 18px; border: 2px solid var(--border-color);">🐾</div>
                <div>
                    <h3 id="skill-modal-title" style="font-size: 1.35rem; font-weight: 900; margin-bottom: 4px;">Раздел</h3>
                    <div id="skill-modal-progress-text" style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted);">0 / 0 уроков пройдено</div>
                </div>
            </div>
            <button onclick="closeSkillModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--text-muted);">✕</button>
        </div>

        <p id="skill-modal-desc" style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 16px;"></p>

        <div style="margin-bottom: 20px;">
            <div class="progress-bar-duo" style="height: 10px;">
                <div class="progress-bar-fill" id="skill-modal-progress-bar" style="width: 0%;"></div>
            </div>
        </div>

        <h4 style="font-size: 0.95rem; font-weight: 800; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.5px;">
            📚 Занятия в этом разделе:
        </h4>

        <div id="skill-modal-lessons-list" style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 24px;">
            <!-- Rendered by JS -->
        </div>

        <div id="skill-modal-main-action">
            <!-- Rendered by JS -->
        </div>
    </div>
</div>

<!-- Modal: Treasure Chest Bonus -->
<div id="modal-chest-reward" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 400px; width: 100%; text-align: center; margin-bottom: 0; padding: 32px 24px;">
        <div style="font-size: 4rem; margin-bottom: 12px;">🎁</div>
        <h3 style="font-size: 1.5rem; font-weight: 900; color: #d97706; margin-bottom: 8px;">Сундук с сокровищами!</h3>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 20px;">
            Вы нашли сундучок на пути обучения и получаете:
        </p>
        <div style="font-size: 2.2rem; font-weight: 900; color: #0284c7; margin-bottom: 24px;">
            +15 💎 Кристаллов
        </div>
        <button class="btn-duo btn-primary" style="width: 100%;" onclick="claimChestReward()">
            Забрать награду! 🐾
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    ShibaMascot.update('home-shiba-mascot', 'happy');
});

function openSkillDetailsModal(skill, targetLesson) {
    document.getElementById('skill-modal-icon').innerText = skill.icon || '🐾';
    document.getElementById('skill-modal-title').innerText = skill.title || 'Раздел';
    document.getElementById('skill-modal-desc').innerText = skill.description || 'Увлекательные практические задания для освоения темы.';
    
    const total = skill.total_lessons || (skill.lessons ? skill.lessons.length : 0);
    const completed = skill.completed_lessons || 0;
    const percent = total > 0 ? Math.round((completed / total) * 100) : 0;

    document.getElementById('skill-modal-progress-text').innerText = `${completed} из ${total} уроков пройдено (${percent}%)`;
    document.getElementById('skill-modal-progress-bar').style.width = `${percent}%`;

    const list = document.getElementById('skill-modal-lessons-list');
    list.innerHTML = '';

    if (skill.lessons && skill.lessons.length > 0) {
        skill.lessons.forEach((les, idx) => {
            const isDone = parseInt(les.is_completed || 0) > 0;
            const isCurrent = targetLesson && les.id === targetLesson.id;
            
            const item = document.createElement('a');
            item.href = `lesson.php?id=${les.id}`;
            item.className = 'card-duo';
            item.style.cssText = `
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 16px;
                margin-bottom: 0;
                text-decoration: none;
                color: inherit;
                border: 2px solid ${isCurrent ? 'var(--primary)' : 'var(--border-color)'};
                background: ${isCurrent ? 'var(--primary-light)' : 'var(--bg-card)'};
                transition: transform 0.15s ease;
            `;

            item.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 0.9rem; background: ${isDone ? 'var(--primary)' : (isCurrent ? 'var(--secondary)' : 'var(--bg-main)')}; color: ${isDone || isCurrent ? '#fff' : 'var(--text-muted)'};">
                        ${isDone ? '✓' : (idx + 1)}
                    </div>
                    <div>
                        <div style="font-weight: 800; font-size: 0.95rem;">${les.title}</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">+${les.xp_reward || 20} XP</div>
                    </div>
                </div>
                <div>
                    <span class="btn-duo ${isDone ? 'btn-outline' : 'btn-primary'}" style="padding: 6px 12px; font-size: 0.8rem; border-radius: 10px;">
                        ${isDone ? 'Повторить' : (isCurrent ? '▶️ Начать' : 'Начать')}
                    </span>
                </div>
            `;
            list.appendChild(item);
        });
    } else {
        list.innerHTML = `<div style="text-align: center; color: var(--text-muted); padding: 16px;">В этом разделе пока нет уроков</div>`;
    }

    const mainAction = document.getElementById('skill-modal-main-action');
    if (targetLesson) {
        const isAllDone = completed >= total && total > 0;
        mainAction.innerHTML = `
            <a href="lesson.php?id=${targetLesson.id}" class="btn-duo btn-primary anim-bounce" style="width: 100%; font-size: 1.1rem; padding: 14px; display: flex; align-items: center; justify-content: center; gap: 10px; text-decoration: none;">
                <span>${isAllDone ? '🔄' : '▶️'}</span>
                <span>${isAllDone ? 'Повторить раздел' : 'Следующее занятие'}: <strong>${targetLesson.title}</strong></span>
            </a>
        `;
    } else {
        mainAction.innerHTML = '';
    }

    document.getElementById('modal-skill-details').style.display = 'flex';
}

function closeSkillModal() {
    document.getElementById('modal-skill-details').style.display = 'none';
}

function openChestModal(id) {
    SoundEngine.play('correct');
    triggerConfetti();
    document.getElementById('modal-chest-reward').style.display = 'flex';
}

function claimChestReward() {
    SoundEngine.play('win');
    document.getElementById('modal-chest-reward').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
