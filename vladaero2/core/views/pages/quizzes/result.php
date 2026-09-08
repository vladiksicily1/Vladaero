<?php
/** @var array $quiz */
/** @var int $score */
/** @var int $correct */
/** @var int $total */
/** @var array $answers */
/** @var array $questions */
?>

<section class="section">
    <div class="container">
        <div class="quiz-result">
            <h1>🎯 Результат викторины</h1>
            <h2><?= e($quiz['title']) ?></h2>

            <div class="quiz-score">
                <div class="quiz-score__circle" style="--score: <?= $score ?>%">
                    <span class="quiz-score__num"><?= $score ?>%</span>
                </div>
                <p><?= $correct ?> из <?= $total ?> правильных ответов</p>
            </div>

            <div class="quiz-answers">
                <?php foreach ($answers as $i => $a): ?>
                    <?php $q = $questions[$i]; ?>
                    <div class="quiz-answer <?= $a['is_correct'] ? 'quiz-answer--correct' : 'quiz-answer--wrong' ?>">
                        <h4><?= ($i + 1) ?>. <?= e($q['question_text']) ?></h4>
                        <p>Ваш ответ: <strong><?= e($a['user_answer'] ?: '—') ?></strong></p>
                        <?php if (!$a['is_correct']): ?>
                            <p>Правильный ответ: <strong class="text-success"><?= e($a['correct_answer']) ?></strong></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <a href="<?= url('/quizzes/' . e($quiz['slug'])) ?>" class="btn btn--primary mt-4">Пройти ещё раз</a>
            <a href="<?= url('/quizzes') ?>" class="btn btn--outline mt-4">Другие викторины</a>
        </div>
    </div>
</section>
