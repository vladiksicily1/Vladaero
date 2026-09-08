<?php
/** @var array $aircraft */
/** @var array $photos */
/** @var array $incidents */
/** @var array $liveries */
/** @var array $related */
/** @var array $modifications */
/** @var array $ttxSummary */
/** @var array $specsDetailed */
?>

<?= schemaAircraft($aircraft) ?>

<section class="section">
    <div class="container">
        <!-- Breadcrumb -->
        <nav class="breadcrumb" aria-label="Навигация">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/aircraft') ?>">Самолёты</a> ›
            <span><?= e($aircraft['name']) ?></span>
        </nav>

        <div class="aircraft-detail">
            <div class="aircraft-detail__main">
                <?php if (!empty($aircraft['image'])): ?>
                    <img src="<?= url(e($aircraft['image'])) ?>" alt="<?= e($aircraft['name']) ?>" class="aircraft-detail__hero-img">
                <?php else: ?>
                    <div class="aircraft-detail__hero-placeholder">✈️ <?= e($aircraft['name']) ?></div>
                <?php endif; ?>

                <div class="aircraft-detail__info">
                    <h1><?= e($aircraft['name']) ?></h1>
                    <?php if (!empty($aircraft['type_code'])): ?>
                        <span class="badge badge--accent"><?= e($aircraft['type_code']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($aircraft['manufacturer_name'])): ?>
                        <p class="text-muted">Производитель: <?= e($aircraft['manufacturer_name']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TTX -->
            <?php if (!empty($ttxSummary)): ?>
                <div class="ttx-grid">
                    <?php foreach ($ttxSummary as $label => $value): ?>
                        <div class="ttx-item">
                            <span class="ttx-item__label"><?= e($label) ?></span>
                            <span class="ttx-item__value"><?= e($value) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Description -->
            <?php if (!empty($aircraft['description'])): ?>
                <div class="prose">
                    <?= nl2br(e($aircraft['description'])) ?>
                </div>
            <?php endif; ?>

            <!-- Photos -->
            <?php if (!empty($photos)): ?>
                <h2>📸 Фотографии</h2>
                <div class="grid grid--3">
                    <?php foreach ($photos as $photo): ?>
                        <a href="<?= url('/photos/' . $photo['id']) ?>" class="photo-card">
                            <img src="<?= url(e($photo['file_path'] ?? '')) ?>" alt="" class="photo-card__img" loading="lazy">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Liveries -->
            <?php if (!empty($liveries)): ?>
                <h2>🎨 Ливреи</h2>
                <div class="grid grid--4">
                    <?php foreach ($liveries as $livery): ?>
                        <div class="card card--sm">
                            <div class="card__body">
                                <p class="card__title"><?= e($livery['airline_name'] ?? 'Неизвестно') ?></p>
                                <span class="text-muted">Скачиваний: <?= $livery['downloads_count'] ?? 0 ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Related -->
            <?php if (!empty($related)): ?>
                <h2>🔗 Похожие самолёты</h2>
                <div class="grid grid--4">
                    <?php foreach ($related as $rel): ?>
                        <a href="<?= url('/aircraft/' . e($rel['slug'])) ?>" class="card card--sm">
                            <div class="card__body">
                                <p class="card__title"><?= e($rel['name']) ?></p>
                                <span class="text-muted"><?= e($rel['manufacturer_name'] ?? '') ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
