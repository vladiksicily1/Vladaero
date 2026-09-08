<?php
/** @var array $quiz */
/** @var array $questions */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/quizzes') ?>">Викторины</a> ›
            <span><?= e($quiz['title']) ?></span>
        </nav>

        <div class="quiz-play">
            <h1><?= e($quiz['title']) ?></h1>
            <?php if (!empty($quiz['description'])): ?>
                <p class="text-muted"><?= e($quiz['description']) ?></p>
            <?php endif; ?>

            <form method="POST" action="<?= url('/quizzes/' . e($quiz['slug']) . '/submit') ?>" class="quiz-form">
                <?= csrf_field() ?>

                <?php foreach ($questions as $i => $q): ?>
                    <div class="quiz-question">
                        <h3><?= ($i + 1) ?>. <?= e($q['question_text']) ?></h3>
                        <?php
                        $options = json_decode($q['options_json'] ?? '[]', true);
                        foreach ($options as $opt):
                        ?>
                            <label class="quiz-option">
                                <input type="radio" name="q_<?= $q['id'] ?>" value="<?= e($opt) ?>" required>
                                <span><?= e($opt) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn--primary btn--lg">Ответить</button>
            </form>
        </div>
    </div>
</section>
