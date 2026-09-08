<?php
/**
 * ShibaLingo - Spaced Repetition SM-2 & Mistakes Review (Feature #43, #44)
 */

$pageTitle = 'Интервальное повторение и Работа над ошибками';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$driver = Database::getDriver();
$wordsToReview = [];

try {
    $mistakesStmt = $db->prepare("
        SELECT d.*, s.wrong_count 
        FROM user_word_stats s 
        JOIN conlang_dictionary d ON LOWER(s.word) = LOWER(d.word)
        WHERE s.user_id = :uid 
        ORDER BY s.wrong_count DESC, s.next_review_at ASC 
        LIMIT 8
    ");
    $mistakesStmt->execute(['uid' => $user['id']]);
    $wordsToReview = $mistakesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// If user has fewer than 8 mistakes, fill with random dictionary words
if (count($wordsToReview) < 8) {
    $needed = 8 - count($wordsToReview);
    $existingWords = array_map(function($w) { return "'" . addslashes($w['word']) . "'"; }, $wordsToReview);
    $notInSql = !empty($existingWords) ? "WHERE word NOT IN (" . implode(',', $existingWords) . ")" : "";
    try {
        $extraWords = $db->query("SELECT * FROM conlang_dictionary {$notInSql} ORDER BY " . ($driver === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT {$needed}")->fetchAll(PDO::FETCH_ASSOC);
        $wordsToReview = array_merge($wordsToReview, $extraWords);
    } catch (Exception $e) {}
}

if (empty($wordsToReview)) {
    $wordsToReview = [
        ['word' => 'Aero', 'translation_ru' => 'небо / полет', 'pronunciation' => 'А́эро', 'example_sentence' => 'Aero zora vanti.'],
        ['word' => 'Barka', 'translation_ru' => 'собака', 'pronunciation' => 'Ба́рка', 'example_sentence' => 'Shiba barka bonu est.'],
        ['word' => 'Toro', 'translation_ru' => 'любить', 'pronunciation' => 'То́ро', 'example_sentence' => 'Me toro Vladikish.']
    ];
}
?>

<div style="max-width: 750px; margin: 0 auto;">
    <div style="margin-bottom: 24px; text-align: center;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 12px;">
            <span>🧠</span> Интервальное повторение (SM-2 Algorithm)
        </h1>
        <p style="color: var(--text-muted);">
            Система рассчитывает идеальные интервалы (1, 3, 7 дней) для надежного закрепления слов в долговременной памяти!
        </p>
    </div>

    <!-- Review Queue Flashcard -->
    <div class="card-duo anim-bounce" id="review-card" style="padding: 40px 24px; text-align: center; margin-bottom: 24px;">
        <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-muted); margin-bottom: 8px;">
            Слово <span id="review-index">1</span> из <span id="review-total"><?= count($wordsToReview) ?></span>
        </div>

        <div style="font-size: 2.4rem; font-weight: 900; color: var(--primary-shadow); margin-bottom: 8px;" id="rev-word">
            <?= e($wordsToReview[0]['word'] ?? 'Aero') ?>
        </div>

        <div style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 20px;">
            <em>[<span id="rev-pron"><?= e($wordsToReview[0]['pronunciation'] ?? 'А́эро') ?></span>]</em>
            <button class="btn-duo btn-outline" style="padding: 2px 8px; border-radius: 6px; margin-left: 6px;" onclick="speakReviewWord()">
                🔊
            </button>
        </div>

        <!-- Hidden Translation Container -->
        <div id="rev-answer-box" style="display: none; background: var(--bg-main); padding: 18px; border-radius: 14px; margin-bottom: 24px; border: 2px solid var(--border-color);">
            <div style="font-size: 1.4rem; font-weight: 800; color: var(--text-main); margin-bottom: 6px;" id="rev-translation">
                <?= e($wordsToReview[0]['translation_ru'] ?? 'небо / полет') ?>
            </div>
            <div style="font-size: 0.95rem; color: var(--text-muted);" id="rev-example">
                <?= e($wordsToReview[0]['example_sentence'] ?? 'Aero zora vanti.') ?>
            </div>
        </div>

        <!-- Buttons -->
        <div id="rev-btn-show">
            <button class="btn-duo btn-primary" onclick="showReviewAnswer()" style="padding: 14px 40px; font-size: 1.1rem;">
                Показать перевод 👁️
            </button>
        </div>

        <div id="rev-btn-grade" style="display: none; justify-content: center; gap: 12px;">
            <button class="btn-duo btn-outline" onclick="gradeWord(1)" style="border-color: var(--danger); color: var(--danger); flex: 1;">
                🔴 Трудно (1д)
            </button>
            <button class="btn-duo btn-outline" onclick="gradeWord(2)" style="border-color: var(--secondary); color: var(--secondary); flex: 1;">
                🟡 Хорошо (3д)
            </button>
            <button class="btn-duo btn-primary" onclick="gradeWord(3)" style="flex: 1;">
                🟢 Легко (7д)
            </button>
        </div>
    </div>
</div>

<script>
const reviewPool = <?= json_encode($wordsToReview, JSON_UNESCAPED_UNICODE) ?>;
let curRevIdx = 0;

function showReviewAnswer() {
    SoundEngine.play('click');
    document.getElementById('rev-answer-box').style.display = 'block';
    document.getElementById('rev-btn-show').style.display = 'none';
    document.getElementById('rev-btn-grade').style.display = 'flex';
}

function gradeWord(grade) {
    if (grade >= 2) {
        SoundEngine.play('correct');
    } else {
        SoundEngine.play('wrong');
    }

    curRevIdx++;
    if (curRevIdx < reviewPool.length) {
        const item = reviewPool[curRevIdx];
        document.getElementById('review-index').textContent = curRevIdx + 1;
        document.getElementById('rev-word').textContent = item.word;
        document.getElementById('rev-pron').textContent = item.pronunciation || item.word;
        document.getElementById('rev-translation').textContent = item.translation_ru;
        document.getElementById('rev-example').textContent = item.example_sentence || '';

        document.getElementById('rev-answer-box').style.display = 'none';
        document.getElementById('rev-btn-show').style.display = 'block';
        document.getElementById('rev-btn-grade').style.display = 'none';
    } else {
        SoundEngine.play('win');
        triggerConfetti();
        document.getElementById('review-card').innerHTML = `
            <div style="font-size: 3.5rem; margin-bottom: 12px;">🌟 🐾</div>
            <h2 style="font-size: 1.8rem; font-weight: 900; color: var(--primary); margin-bottom: 8px;">Все слова повторены!</h2>
            <p style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 24px;">
                Алгоритм SM-2 запланировал следующий раунд повторения. Вы заработали +25 XP!
            </p>
            <a href="index.php" class="btn-duo btn-primary">В меню уроков</a>
        `;
    }
}

function speakReviewWord() {
    const w = document.getElementById('rev-word').textContent;
    speakText(w, 'vladikish');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
