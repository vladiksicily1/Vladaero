<?php
/**
 * ShibaLingo - Interactive Story Reader (Feature #9)
 */

$pageTitle = 'Интерактивные Рассказы';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$stories = $db->query("SELECT * FROM stories ORDER BY id ASC")->fetchAll();
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
            <span>📖</span> Интерактивные Истории
        </h1>
        <p style="color: var(--text-muted);">Короткие иллюстрированные рассказы на Vladikish с вопросами по ходу сюжета</p>
    </div>

    <!-- Stories List -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;" id="stories-grid">
        <?php foreach ($stories as $st): 
            $storyJson = $st['story_data'];
        ?>
            <div class="card-duo anim-bounce" style="padding: 0; overflow: hidden; margin-bottom: 0;">
                <img src="<?= e($st['cover_image']) ?>" style="width: 100%; height: 160px; object-fit: cover;" alt="Cover">
                <div style="padding: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);">Уровень <?= $st['level'] ?></span>
                        <span style="font-weight: 800; font-size: 0.9rem; color: #eab308;">⚡ +<?= $st['xp_reward'] ?> XP</span>
                    </div>
                    <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 12px;"><?= e($st['title']) ?></h3>
                    <button class="btn-duo btn-primary" style="width: 100%;" onclick='openStory(<?= json_encode($st) ?>)'>
                        Читать историю 📖
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Story Player Stage -->
    <div id="story-reader-stage" style="display: none;">
        <div class="card-duo anim-bounce">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h2 id="story-player-title" style="font-size: 1.4rem; font-weight: 900;"></h2>
                <button class="btn-duo btn-outline" onclick="closeStory()" style="padding: 6px 12px; font-size: 0.85rem;">
                    ✕ Выйти
                </button>
            </div>

            <div id="story-step-box" style="margin-bottom: 24px;"></div>
            <div id="story-question-box" style="margin-bottom: 20px;"></div>
            
            <div id="story-feedback-banner" style="display: none; padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 16px;"></div>

            <button class="btn-duo btn-primary" id="btn-next-story-step" style="width: 100%; display: none;">
                Далее 🐾
            </button>
        </div>
    </div>
</div>

<script>
let currentStoryData = [];
let currentStoryIndex = 0;
let activeStoryMeta = null;

function openStory(story) {
    activeStoryMeta = story;
    currentStoryData = JSON.parse(story.story_data);
    currentStoryIndex = 0;

    document.getElementById('stories-grid').style.display = 'none';
    document.getElementById('story-reader-stage').style.display = 'block';
    document.getElementById('story-player-title').textContent = story.title;

    renderStoryStep();
}

function closeStory() {
    document.getElementById('stories-grid').style.display = 'grid';
    document.getElementById('story-reader-stage').style.display = 'none';
}

function renderStoryStep() {
    const step = currentStoryData[currentStoryIndex];
    const stepBox = document.getElementById('story-step-box');
    const qBox = document.getElementById('story-question-box');
    const feedback = document.getElementById('story-feedback-banner');
    const btnNext = document.getElementById('btn-next-story-step');

    feedback.style.display = 'none';
    btnNext.style.display = 'none';

    stepBox.innerHTML = `
        <div style="background: var(--bg-main); padding: 20px; border-radius: 16px; margin-bottom: 16px; border: 2px solid var(--border-color);">
            <div style="font-size: 1.4rem; font-weight: 800; color: var(--primary-shadow); margin-bottom: 6px;">
                ${step.narrative}
            </div>
            <div style="color: var(--text-muted); font-size: 1rem;">
                <em>💬 ${step.translation}</em>
            </div>
        </div>
    `;

    let optsHtml = '';
    step.options.forEach((opt, idx) => {
        optsHtml += `
            <div class="choice-card story-opt" onclick="checkStoryAnswer(${idx}, ${step.correct})">
                ${opt}
            </div>
        `;
    });

    qBox.innerHTML = `
        <h4 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 12px;">❓ ${step.question}</h4>
        <div style="display: flex; flex-direction: column; gap: 10px;">${optsHtml}</div>
    `;
}

function checkStoryAnswer(chosen, correct) {
    const feedback = document.getElementById('story-feedback-banner');
    const btnNext = document.getElementById('btn-next-story-step');

    if (chosen === correct) {
        SoundEngine.play('correct');
        feedback.style.display = 'block';
        feedback.style.background = 'var(--primary-light)';
        feedback.style.color = 'var(--primary-shadow)';
        feedback.textContent = '🎉 Верно! Отличное понимание сюжета!';
        btnNext.style.display = 'block';
        btnNext.onclick = nextStoryStep;
    } else {
        SoundEngine.play('wrong');
        feedback.style.display = 'block';
        feedback.style.background = 'var(--danger-light)';
        feedback.style.color = 'var(--danger-shadow)';
        feedback.textContent = '💔 Попробуйте другой вариант!';
    }
}

function nextStoryStep() {
    currentStoryIndex++;
    if (currentStoryIndex < currentStoryData.length) {
        renderStoryStep();
    } else {
        SoundEngine.play('win');
        triggerConfetti();
        document.getElementById('story-step-box').innerHTML = `
            <div style="text-align: center; padding: 30px;">
                <div style="font-size: 3.5rem; margin-bottom: 12px;">🌟 🐾</div>
                <h2 style="font-size: 1.8rem; font-weight: 900; color: var(--primary);">Рассказ прочитан!</h2>
                <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 20px;">
                    Вы заработали +${activeStoryMeta.xp_reward} XP и +${activeStoryMeta.gems_reward} Кристаллов!
                </p>
                <button class="btn-duo btn-primary" onclick="closeStory()">
                    Вернуться к рассказам
                </button>
            </div>
        `;
        document.getElementById('story-question-box').innerHTML = '';
        document.getElementById('story-feedback-banner').style.display = 'none';
        document.getElementById('btn-next-story-step').style.display = 'none';

        // Award XP
        fetch('api/lesson_complete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lesson_id: 1, xp: activeStoryMeta.xp_reward })
        });
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
