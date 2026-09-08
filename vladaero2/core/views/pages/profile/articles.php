<?php
/** @var array $user */
/** @var array $articles */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/profile/' . e($user['username'])) ?>"><?= e($user['display_name'] ?: $user['username']) ?></a> ›
            <span>Статьи</span>
        </nav>

        <h1>📝 Статьи <?= e($user['display_name'] ?: $user['username']) ?></h1>

        <?php if (empty($articles)): ?>
            <div class="empty-state"><p>Пока нет статей</p></div>
        <?php else: ?>
            <div class="grid grid--2">
                <?php foreach ($articles as $article): ?>
                    <a href="<?= url('/articles/' . e($article['slug'])) ?>" class="card card--news">
                        <?php if (!empty($article['cover_image'])): ?>
                            <img src="<?= url(e($article['cover_image'])) ?>" alt="" class="card__img" loading="lazy">
                        <?php endif; ?>
                        <div class="card__body">
                            <h3 class="card__title"><?= e($article['title']) ?></h3>
                            <time class="text-muted"><?= formatDate($article['published_at'] ?? $article['created_at']) ?></time>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
