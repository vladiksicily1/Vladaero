<?php
/** @var array $quizzes */
/** @var array $categories */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🧠 Викторины и тесты</h1>

        <?php if (!empty($categories)): ?>
            <div class="category-list mb-6">
                <?php foreach ($categories as $cat): ?>
                    <a href="<?= url('/quizzes?category=' . e($cat['slug'])) ?>" class="badge badge--sm"><?= e($cat['name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid--3">
            <?php foreach ($quizzes as $quiz): ?>
                <a href="<?= url('/quizzes/' . e($quiz['slug'])) ?>" class="card">
                    <div class="card__body">
                        <h3 class="card__title"><?= e($quiz['title']) ?></h3>
                        <p class="card__desc"><?= e($quiz['description'] ?? '') ?></p>
                        <div class="card__specs">
                            <span class="card__spec">❓ <?= $quiz['question_count'] ?? '—' ?> вопросов</span>
                            <span class="card__spec">🎯 <?= $quiz['attempts'] ?? 0 ?> прохождений</span>
                        </div>
                        <?php if (!empty($quiz['author_name'])): ?>
                            <span class="card__author text-muted">✍️ <?= e($quiz['author_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
