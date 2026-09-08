<?php
/** @var array $profileUser */
/** @var array $photos */
/** @var array $stats */
?>

<section class="section">
    <div class="container">
        <div class="profile">
            <div class="profile__header">
                <img src="<?= avatarUrl($profileUser['avatar_url'] ?? null) ?>" alt="" class="profile__avatar" width="80" height="80">
                <div>
                    <h1><?= e($profileUser['display_name'] ?: $profileUser['username']) ?></h1>
                    <span class="badge badge--sm"><?= e($profileUser['role'] ?? 'user') ?></span>
                    <p class="text-muted">На сайте с <?= formatDate($profileUser['created_at']) ?></p>
                </div>
            </div>

            <?php if (!empty($profileUser['bio'])): ?>
                <div class="prose mt-4"><?= nl2br(e($profileUser['bio'])) ?></div>
            <?php endif; ?>

            <div class="profile__stats">
                <span>📸 <?= $stats['photos'] ?> фото</span>
                <span>💬 <?= $stats['comments'] ?> комментариев</span>
            </div>

            <?php if (!empty($photos)): ?>
                <h2>📸 Фотографии</h2>
                <div class="grid grid--4">
                    <?php foreach ($photos as $photo): ?>
                        <a href="<?= url('/photos/' . $photo['id']) ?>" class="photo-card">
                            <img src="<?= url(e($photo['file_path'] ?? '')) ?>" alt="" class="photo-card__img" loading="lazy">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
