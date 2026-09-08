<?php
/** @var array $article */
/** @var array $comments */
/** @var array $related */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/articles') ?>">Статьи</a> ›
            <?php if (!empty($article['category_name'])): ?>
                <a href="<?= url('/articles?category=' . e($article['category_slug'] ?? '')) ?>"><?= e($article['category_name']) ?></a> ›
            <?php endif; ?>
            <span><?= e($article['title']) ?></span>
        </nav>

        <article class="article-detail">
            <div class="article-detail__header">
                <h1><?= e($article['title']) ?></h1>
                <div class="article-meta">
                    <a href="<?= url('/profile/' . e($article['author_name'])) ?>" class="article-author">
                        <img src="<?= avatarUrl($article['author_avatar'] ?? null) ?>" alt="" class="avatar avatar--sm">
                        <span><?= e($article['author_display_name'] ?: $article['author_name']) ?></span>
                    </a>
                    <time>📅 <?= formatDate($article['published_at'] ?? '') ?></time>
                    <span>👁️ <?= $article['views'] ?? 0 ?></span>
                </div>
            </div>

            <?php if (!empty($article['cover_image'])): ?>
                <img src="<?= url(e($article['cover_image'])) ?>" alt="" class="article-detail__cover" loading="lazy">
            <?php endif; ?>

            <div class="prose">
                <?= $article['content'] ?? '' ?>
            </div>

            <!-- Comments -->
            <div class="comments-section mt-8">
                <h2>💬 Комментарии (<?= count($comments) ?>)</h2>

                <?php if (Session::getAuth()): ?>
                    <form method="POST" class="comment-form">
                        <?= csrf_field() ?>
                        <textarea name="content" class="form-input" rows="3" placeholder="Напишите комментарий..." required></textarea>
                        <button type="submit" class="btn btn--primary btn--sm mt-2">Отправить</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted"><a href="<?= url('/auth/login') ?>">Войдите</a>, чтобы комментировать</p>
                <?php endif; ?>

                <div class="comments mt-4">
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment">
                            <div class="comment__header">
                                <a href="<?= url('/profile/' . e($comment['username'] ?? '')) ?>" class="comment__author">
                                    <img src="<?= avatarUrl($comment['avatar_url'] ?? null) ?>" alt="" class="avatar avatar--sm">
                                    <strong><?= e($comment['display_name'] ?: $comment['username'] ?? 'Аноним') ?></strong>
                                </a>
                                <time class="text-muted"><?= timeAgo($comment['created_at']) ?></time>
                            </div>
                            <div class="comment__body"><?= nl2br(e($comment['content'] ?? '')) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <?php if (!empty($related)): ?>
            <h2 class="mt-8">📖 Похожие статьи</h2>
            <div class="grid grid--3">
                <?php foreach ($related as $rel): ?>
                    <a href="<?= url('/articles/' . e($rel['slug'])) ?>" class="card card--sm">
                        <?php if (!empty($rel['cover_image'])): ?>
                            <img src="<?= url(e($rel['cover_image'])) ?>" alt="" class="card__img" loading="lazy">
                        <?php endif; ?>
                        <div class="card__body">
                            <h3 class="card__title"><?= e($rel['title']) ?></h3>
                            <time class="text-muted"><?= formatDate($rel['published_at'] ?? '') ?></time>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
