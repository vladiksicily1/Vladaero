<?php
/** @var array $user */
/** @var array $photos */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/profile/' . e($user['username'])) ?>"><?= e($user['display_name'] ?: $user['username']) ?></a> ›
            <span>Фотографии</span>
        </nav>

        <h1>📸 Фотографии <?= e($user['display_name'] ?: $user['username']) ?></h1>

        <?php if (empty($photos)): ?>
            <div class="empty-state"><p>Пока нет фотографий</p></div>
        <?php else: ?>
            <div class="grid grid--4">
                <?php foreach ($photos as $photo): ?>
                    <a href="<?= url('/photos/' . $photo['id']) ?>" class="photo-card">
                        <img src="<?= url(e($photo['file_path'] ?? '')) ?>" alt="" class="photo-card__img" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
