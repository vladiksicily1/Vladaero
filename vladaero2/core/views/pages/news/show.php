<?php
/** @var array $article */
/** @var array $comments */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/news') ?>">Новости</a> ›
            <?php if (!empty($article['category_name'])): ?>
                <a href="<?= url('/news?category=' . e($article['category_slug'] ?? '')) ?>"><?= e($article['category_name']) ?></a> ›
            <?php endif; ?>
            <span><?= e($article['title']) ?></span>
        </nav>

        <article class="article-detail">
            <div class="article-detail__header">
                <h1><?= e($article['title']) ?></h1>
                <div class="article-meta">
                    <?php if (!empty($article['author_name'])): ?>
                        <span>✍️ <?= e($article['author_name']) ?></span>
                    <?php endif; ?>
                    <time>📅 <?= formatDate($article['published_at'] ?? '') ?></time>
                    <span>👁️ <?= $article['views'] ?? 0 ?></span>
                </div>
            </div>

            <?php if (!empty($article['cover_image'])): ?>
                <img src="<?= url(e($article['cover_image'])) ?>" alt="" class="article-detail__cover" loading="lazy">
            <?php endif; ?>

            <?php if (!empty($article['excerpt'])): ?>
                <p class="article-detail__excerpt"><?= e($article['excerpt']) ?></p>
            <?php endif; ?>

            <div class="prose">
                <?= $article['content'] ?? '' ?>
            </div>

            <?php if (!empty($comments)): ?>
                <h2>💬 Комментарии (<?= count($comments) ?>)</h2>
                <div class="comments">
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment">
                            <div class="comment__header">
                                <strong><?= e($comment['username'] ?? 'Аноним') ?></strong>
                                <time class="text-muted"><?= timeAgo($comment['created_at']) ?></time>
                            </div>
                            <p class="comment__body"><?= nl2br(e($comment['content'] ?? '')) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </div>
</section>
