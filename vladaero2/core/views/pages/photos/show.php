<?php
/** @var array $photo */
/** @var array $comments */
/** @var array $related */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/photos') ?>">Фотогалерея</a> ›
            <span>Фото #<?= $photo['id'] ?></span>
        </nav>

        <div class="photo-detail">
            <div class="photo-detail__image">
                <img src="<?= url(e($photo['file_path'] ?? '')) ?>" alt="<?= e($photo['title'] ?? 'Фото споттинга') ?>" class="photo-detail__img">
            </div>

            <div class="photo-detail__sidebar">
                <h1><?= e($photo['title'] ?? 'Фото споттинга #' . $photo['id']) ?></h1>

                <div class="photo-meta">
                    <?php if (!empty($photo['username'])): ?>
                        <p>📸 <a href="<?= url('/profile/' . e($photo['username'])) ?>"><?= e($photo['username']) ?></a></p>
                    <?php endif; ?>
                    <p>📅 <?= formatDate($photo['created_at'] ?? '') ?></p>
                    <p>👁️ <?= $photo['views'] ?? 0 ?> просмотров</p>
                </div>

                <?php if (!empty($photo['aircraft_name'])): ?>
                    <div class="photo-meta-item">
                        <span class="info-label">Самолёт</span>
                        <a href="<?= url('/aircraft/' . e($photo['type_code'] ?? '')) ?>"><?= e($photo['aircraft_name']) ?></a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($photo['airport_name'])): ?>
                    <div class="photo-meta-item">
                        <span class="info-label">Аэропорт</span>
                        <a href="<?= url('/airports/' . e($photo['airport_icao'] ?? '')) ?>"><?= e($photo['airport_name']) ?></a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($photo['airline_name'])): ?>
                    <div class="photo-meta-item">
                        <span class="info-label">Авиакомпания</span>
                        <span><?= e($photo['airline_name']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($photo['description'])): ?>
                    <div class="prose mt-4"><?= nl2br(e($photo['description'])) ?></div>
                <?php endif; ?>

                <div class="photo-rating">
                    <span>⭐ <?= number_format($photo['rating'] ?? 0, 1) ?> (<?= $photo['rating_count'] ?? 0 ?> оценок)</span>
                </div>
            </div>
        </div>

        <?php if (!empty($related)): ?>
            <h2 class="mt-8">🔗 Похожие фото</h2>
            <div class="grid grid--3">
                <?php foreach ($related as $rel): ?>
                    <a href="<?= url('/photos/' . $rel['id']) ?>" class="photo-card">
                        <img src="<?= url(e($rel['file_path'] ?? '')) ?>" alt="" class="photo-card__img" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($comments)): ?>
            <h2 class="mt-8">💬 Комментарии (<?= count($comments) ?>)</h2>
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
    </div>
</section>
