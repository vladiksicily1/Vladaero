<?php
/** @var array $articles */
/** @var array $categories */
/** @var array $topAuthors */
/** @var array $pagination */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">📝 Статьи и блог</h1>

        <div class="grid grid--4-1">
            <div>
                <div class="category-list mb-6">
                    <a href="<?= url('/articles') ?>" class="badge badge--sm">Все</a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="<?= url('/articles?category=' . $cat['id']) ?>" class="badge badge--sm"><?= e($cat['name']) ?> (<?= $cat['count'] ?? 0 ?>)</a>
                    <?php endforeach; ?>
                </div>

                <div class="grid grid--2">
                    <?php foreach ($articles as $article): ?>
                        <a href="<?= url('/articles/' . e($article['slug'])) ?>" class="card card--news">
                            <?php if (!empty($article['cover_image'])): ?>
                                <img src="<?= url(e($article['cover_image'])) ?>" alt="" class="card__img" loading="lazy">
                            <?php endif; ?>
                            <div class="card__body">
                                <div class="card__meta">
                                    <?php if (!empty($article['category_name'])): ?>
                                        <span class="badge badge--accent"><?= e($article['category_name']) ?></span>
                                    <?php endif; ?>
                                    <time><?= formatDate($article['published_at'] ?? '') ?></time>
                                </div>
                                <h3 class="card__title"><?= e($article['title']) ?></h3>
                                <p class="card__desc"><?= e(mb_substr($article['excerpt'] ?? '', 0, 120)) ?></p>
                                <span class="card__author">✍️ <?= e($article['author_display_name'] ?: $article['author_name']) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?= renderPagination($pagination, fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p]))) ?>
            </div>

            <aside class="sidebar-aside">
                <?php if (!empty($topAuthors)): ?>
                    <div class="card card--sm">
                        <div class="card__body">
                            <h3>🏆 Топ авторов</h3>
                            <?php foreach ($topAuthors as $a): ?>
                                <a href="<?= url('/profile/' . e($a['username'])) ?>" class="list-item">
                                    <img src="<?= avatarUrl($a['avatar_url'] ?? null) ?>" alt="" class="avatar avatar--sm">
                                    <span><?= e($a['display_name'] ?: $a['username']) ?></span>
                                    <span class="text-muted"><?= $a['article_count'] ?> ст.</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (Session::getAuth()): ?>
                    <a href="<?= url('/blog/new') ?>" class="btn btn--primary btn--block mt-4">✍️ Написать статью</a>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</section>
