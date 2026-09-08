<?php
/**
 * Duolingo Heart Practice Workout (+1 Heart on completion)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$user = getCurrentUser();
if (!$user) {
    header("Location: login.php");
    exit;
}

$db = getDb();
$pageTitle = 'Тренировка для восстановления сердец';

// Fetch 5 random words from dictionary or lesson items for practice
$dictWords = $db->query("SELECT * FROM " . tbl('conlang_dictionary') . " ORDER BY RANDOM() LIMIT 5")->fetchAll();
if (empty($dictWords)) {
    $dictWords = [
        ['word' => 'Aero', 'translation_ru' => 'Небо / Полет', 'example_sentence' => 'Aero est grandu.'],
        ['word' => 'Mira', 'translation_ru' => 'Привет / Смотри', 'example_sentence' => 'Mira, amigo!'],
        ['word' => 'Danko', 'translation_ru' => 'Спасибо', 'example_sentence' => 'Danko pro cibo.'],
        ['word' => 'Korno', 'translation_ru' => 'Сердце / Душа', 'example_sentence' => 'Korno me vanti.'],
        ['word' => 'Zora', 'translation_ru' => 'День / Свет', 'example_sentence' => 'Zora bonu est.']
    ];
}

$practiceQuestions = [];
foreach ($dictWords as $w) {
    // Generate distractors
    $distractors = ['Кошка', 'Дом', 'Вода', 'Хлеб', 'Друг', 'Звезда', 'Огонь'];
    shuffle($distractors);
    $options = [$w['translation_ru'], $distractors[0], $distractors[1], $distractors[2]];
    shuffle($options);
    $correctIdx = array_search($w['translation_ru'], $options);

    $practiceQuestions[] = [
        'type' => 'multiple_choice',
        'question' => 'Как переводится слово «' . $w['word'] . '»?',
        'prompt' => $w['word'],
        'options' => $options,
        'correct' => $correctIdx,
        'explanation' => "«{$w['word']}» означает «{$w['translation_ru']}»."
    ];
}
$practiceJson = json_encode($practiceQuestions, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тренировка для восстановления сердец — ShibaLingo</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/animations.css">
</head>
<body style="background: var(--bg-card); display: block;">

<div class="lesson-container">
    <div class="lesson-header">
        <a href="index.php" class="close-lesson-btn" title="Выйти из тренировки">✕</a>
        <div class="progress-bar-duo">
            <div class="progress-bar-fill" id="lesson-progress-fill" style="width: 0%; background: #0284c7;"></div>
        </div>
        <div class="stat-badge" style="background: rgba(2, 132, 199, 0.1); color: #0284c7; font-weight: 800;">
            💪 Тренировка
        </div>
    </div>

    <div style="display: flex; gap: 24px; align-items: flex-start;">
        <div id="lesson-shiba-mascot" style="flex-shrink: 0;"></div>
        <div id="question-stage" style="flex-grow: 1;"></div>
    </div>

    <div class="lesson-footer">
        <a href="index.php" class="btn-duo btn-outline" style="border-radius: 12px; padding: 10px 16px;">
            Выйти
        </a>
        <div id="lesson-footer-action"></div>
    </div>
</div>

<div class="feedback-banner" id="feedback-banner">
    <div style="max-width: 600px;">
        <h3 id="feedback-title" style="font-size: 1.4rem; font-weight: 900; margin-bottom: 4px;"></h3>
        <p id="feedback-desc" style="font-weight: 600; font-size: 1rem;"></p>
    </div>
    <button class="btn-duo btn-primary" id="btn-next-step" style="width: 180px;">
        Продолжить
    </button>
</div>

<script src="assets/js/mascot.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/lesson.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const rawData = <?= $practiceJson ?>;
    
    // Custom practice completion
    class PracticeEngine extends LessonEngine {
        async finishLesson() {
            SoundEngine.play('win');
            triggerConfetti();

            const stage = document.getElementById('question-stage');
            const footer = document.querySelector('.lesson-footer');
            if (footer) footer.style.display = 'none';

            // Send reward to API
            try {
                await fetch('api/hearts.php?action=practice_reward', { method: 'POST' });
            } catch (e) {}

            stage.innerHTML = `
                <div style="text-align: center; padding: 40px 20px;" class="anim-bounce">
                    <div id="finish-shiba" style="margin-bottom: 20px;"></div>
                    <h1 style="font-size: 2.4rem; font-weight: 900; color: #0284c7; margin-bottom: 12px;">
                        Тренировка окончена! 🎉
                    </h1>
                    <p style="font-size: 1.2rem; color: var(--text-muted); margin-bottom: 32px;">
                        Вы восстановили <strong>+1 ❤️ сердце</strong> и заработали <strong>+10 XP</strong>!
                    </p>

                    <div style="display: flex; justify-content: center; gap: 24px; margin-bottom: 40px;">
                        <div class="card-duo" style="padding: 20px 32px; text-align: center; border-color: #ef4444;">
                            <div style="font-size: 2rem; font-weight: 900; color: #ef4444;">+1 ❤️</div>
                            <div style="font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Жизнь</div>
                        </div>
                        <div class="card-duo" style="padding: 20px 32px; text-align: center;">
                            <div style="font-size: 2rem; font-weight: 900; color: #eab308;">+10 XP</div>
                            <div style="font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Опыт</div>
                        </div>
                    </div>

                    <a href="index.php" class="btn-duo btn-primary" style="font-size: 1.2rem; padding: 16px 48px;">
                        Вернуться к курсу
                    </a>
                </div>
            `;

            ShibaMascot.update('finish-shiba', 'celebrate', 'Сердечко восстановлено! Продолжаем путь! 🐾');
        }
    }

    new PracticeEngine(rawData, 0, 10, 'vladikish');
});
</script>

</body>
</html>
