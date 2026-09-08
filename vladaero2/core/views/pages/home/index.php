<?php
/** @var array $featured_aircraft */
/** @var array $latest_photos */
/** @var array $latest_news */
/** @var array $stats */

$heroBg = setting('hero_background');
$heroStyle = $heroBg ? 'style="background-image: linear-gradient(rgba(10,22,40,0.82), rgba(10,22,40,0.92)), url(' . url(e($heroBg)) . '); background-size: cover; background-position: center;"' : '';
?>
<section class="hero" <?= $heroStyle ?>>
    <div class="hero__bg"></div>
    <div class="hero__content">
        <h1 class="hero__title"><?= setting('hero_title', 'V<span class="text-accent">A</span> VladAero') ?></h1>
        <p class="hero__subtitle"><?= e(setting('hero_subtitle', 'Авиационный портал — энциклопедия, радар, сообщество')) ?></p>
        <div class="hero__search">
            <form action="<?= url('/search') ?>" method="GET" class="hero__search-form">
                <input type="text" name="q" placeholder="Поиск самолётов, аэропортов..." class="hero__search-input" autocomplete="off">
                <button type="submit" class="hero__search-btn">🔍</button>
            </form>
        </div>
        <div class="hero__stats">
            <div class="hero__stat"><span class="hero__stat-num"><?= formatNumber($stats['aircraft'] ?? 0) ?></span><span>Самолётов</span></div>
            <div class="hero__stat"><span class="hero__stat-num"><?= formatNumber($stats['airports'] ?? 0) ?></span><span>Аэропортов</span></div>
            <div class="hero__stat"><span class="hero__stat-num"><?= formatNumber($stats['airlines'] ?? 0) ?></span><span>Авиакомпаний</span></div>
            <div class="hero__stat"><span class="hero__stat-num"><?= formatNumber($stats['photos'] ?? 0) ?></span><span>Фото</span></div>
        </div>
    </div>
</section>

<?php if (!empty($latest_news)): ?>
<section class="section">
    <div class="container">
        <div class="section__header">
            <h2>📰 Новости</h2>
            <a href="<?= url('/news') ?>" class="section__link">Все новости →</a>
        </div>
        <div class="grid grid--3">
            <?php foreach ($latest_news as $article): ?>
                <a href="<?= url('/news/' . e($article['slug'])) ?>" class="card card--news">
                    <?php if (!empty($article['cover_image'])): ?>
                        <img src="<?= url(e($article['cover_image'])) ?>" alt="" class="card__img" loading="lazy">
                    <?php endif; ?>
                    <div class="card__body">
                        <div class="card__meta">
                            <?php if (!empty($article['category_name'])): ?>
                                <span class="badge badge--accent"><?= e($article['category_name']) ?></span>
                            <?php endif; ?>
                            <time><?= formatDate($article['published_at']) ?></time>
                        </div>
                        <h3 class="card__title"><?= e($article['title']) ?></h3>
                        <p class="card__desc"><?= e(mb_substr($article['excerpt'] ?? strip_tags($article['content'] ?? ''), 0, 120)) ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($featured_aircraft)): ?>
<section class="section section--alt">
    <div class="container">
        <div class="section__header">
            <h2>✈️ Самолёты</h2>
            <a href="<?= url('/aircraft') ?>" class="section__link">Энциклопедия →</a>
        </div>
        <div class="grid grid--3">
            <?php foreach ($featured_aircraft as $ac): ?>
                <a href="<?= url('/aircraft/' . e($ac['slug'])) ?>" class="card card--aircraft">
                    <?php if (!empty($ac['image'])): ?>
                        <img src="<?= url(e($ac['image'])) ?>" alt="<?= e($ac['name']) ?>" class="card__img" loading="lazy">
                    <?php else: ?>
                        <div class="card__img card__img--placeholder">✈️</div>
                    <?php endif; ?>
                    <div class="card__body">
                        <h3 class="card__title"><?= e($ac['name']) ?></h3>
                        <div class="card__specs">
                            <?php if (!empty($ac['manufacturer'])): ?>
                                <span class="card__spec">🏭 <?= e($ac['manufacturer']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ac['passengers'])): ?>
                                <span class="card__spec">👥 <?= e($ac['passengers']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($latest_photos)): ?>
<section class="section">
    <div class="container">
        <div class="section__header">
            <h2>📸 Споттинг</h2>
            <a href="<?= url('/photos') ?>" class="section__link">Галерея →</a>
        </div>
        <div class="grid grid--4">
            <?php foreach ($latest_photos as $photo): ?>
                <a href="<?= url('/photos/' . $photo['id']) ?>" class="photo-card">
                    <img src="<?= url(e($photo['file_path'] ?? '')) ?>" alt="" class="photo-card__img" loading="lazy">
                    <div class="photo-card__overlay">
                        <span class="photo-card__user"><?= e($photo['username'] ?? '') ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
