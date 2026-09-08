<?php
/** @var array $term */
/** @var array $related */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/glossary') ?>">Глоссарий</a> ›
            <span><?= e($term['term']) ?></span>
        </nav>

        <div class="glossary-detail">
            <h1><?= e($term['term']) ?></h1>

            <?php if (!empty($term['category'])): ?>
                <span class="badge badge--accent"><?= e($term['category']) ?></span>
            <?php endif; ?>
            <?php if (!empty($term['abbreviation'])): ?>
                <span class="badge badge--sm"><?= e($term['abbreviation']) ?></span>
            <?php endif; ?>

            <div class="prose mt-4">
                <?= nl2br(e($term['definition'])) ?>
            </div>

            <?php if (!empty($term['source'])): ?>
                <p class="text-muted mt-4">Источник: <?= e($term['source']) ?></p>
            <?php endif; ?>

            <?php if (!empty($term['related_terms'])): ?>
                <div class="mt-4">
                    <h3>Связанные термины:</h3>
                    <div class="tag-list">
                        <?php foreach (explode(',', $term['related_terms']) as $rt): ?>
                            <a href="<?= url('/glossary/' . e(trim($rt))) ?>" class="badge badge--sm"><?= e(trim($rt)) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($related)): ?>
                <h2 class="mt-8">📖 Похожие термины</h2>
                <div class="grid grid--3">
                    <?php foreach ($related as $r): ?>
                        <a href="<?= url('/glossary/' . e($r['slug'])) ?>" class="card card--sm">
                            <div class="card__body">
                                <h3><?= e($r['term']) ?></h3>
                                <p class="text-muted"><?= e(mb_substr($r['definition'], 0, 100)) ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
