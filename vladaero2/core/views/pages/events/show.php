<?php /** @var array $event */ ?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/events') ?>">События</a> ›
            <span><?= e($event['name']) ?></span>
        </nav>

        <article class="event-detail">
            <h1><?= e($event['name']) ?></h1>
            <div class="info-grid">
                <div class="info-item"><span class="info-label">Начало</span><span><?= formatDate($event['start_date'], 'd.m.Y') ?></span></div>
                <div class="info-item"><span class="info-label">Окончание</span><span><?= formatDate($event['end_date'], 'd.m.Y') ?></span></div>
                <?php if (!empty($event['airport_name'])): ?>
                    <div class="info-item"><span class="info-label">Место</span><span><?= e($event['airport_name']) ?> (<?= e($event['airport_icao'] ?? '') ?>)</span></div>
                <?php endif; ?>
                <?php if (!empty($event['author_name'])): ?>
                    <div class="info-item"><span class="info-label">Автор</span><span><?= e($event['author_name']) ?></span></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($event['description'])): ?>
                <div class="prose mt-6"><?= nl2br(e($event['description'])) ?></div>
            <?php endif; ?>
        </article>
    </div>
</section>
