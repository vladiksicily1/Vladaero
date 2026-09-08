<?php
/** @var array $articles */
/** @var array $categories */
/** @var array $pagination */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">📰 Новости</h1>

        <?php if (!empty($categories)): ?>
            <div class="category-list">
                <?php foreach ($categories as $cat): ?>
                    <a href="<?= url('/news?category=' . e($cat['slug'])) ?>" class="badge badge--sm"><?= e($cat['name']) ?> (<?= $cat['count'] ?? 0 ?>)</a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid--2">
            <?php foreach ($articles as $i => $article): ?>
                <a href="<?= url('/news/' . e($article['slug'])) ?>" class="card card--news <?= $i === 0 ? 'card--featured' : '' ?>">
                    <?php if (!empty($article['cover_image'])): ?>
                        <img src="<?= url(e($article['cover_image'])) ?>" alt="" class="card__img" loading="lazy">
                    <?php endif; ?>
                    <div class="card__body">
                        <div class="card__meta">
                            <?php if (!empty($article['category_name'])): ?>
                                <span class="badge badge--accent"><?= e($article['category_name']) ?></span>
                            <?php endif; ?>
                            <time><?= formatDate($article['published_at'] ?? $article['created_at']) ?></time>
                            <span>👁️ <?= $article['views'] ?? 0 ?></span>
                        </div>
                        <h2 class="card__title"><?= e($article['title']) ?></h2>
                        <p class="card__desc"><?= e(mb_substr($article['excerpt'] ?? strip_tags($article['content'] ?? ''), 0, 200)) ?></p>
                        <?php if (!empty($article['author_name'])): ?>
                            <span class="card__author">✍️ <?= e($article['author_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?= renderPagination($pagination, fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p]))) ?>
    </div>
</section>
