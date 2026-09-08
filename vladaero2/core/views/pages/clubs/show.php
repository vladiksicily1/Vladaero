<?php
/** @var array $club */
/** @var array $members */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/clubs') ?>">Клубы</a> ›
            <span><?= e($club['name']) ?></span>
        </nav>

        <div class="club-detail">
            <div class="club-detail__header">
                <?php if (!empty($club['cover_image'])): ?>
                    <img src="<?= url(e($club['cover_image'])) ?>" alt="" class="club-detail__cover">
                <?php endif; ?>
                <h1><?= e($club['name']) ?></h1>
                <p class="text-muted"><?= e($club['description'] ?? '') ?></p>
                <?php if (!empty($club['founder_name'])): ?>
                    <p>Основатель: <a href="<?= url('/profile/' . e($club['founder_name'])) ?>"><?= e($club['founder_name']) ?></a></p>
                <?php endif; ?>
            </div>

            <h2>👥 Участники (<?= count($members) ?>)</h2>
            <div class="members-grid">
                <?php foreach ($members as $m): ?>
                    <a href="<?= url('/profile/' . e($m['username'])) ?>" class="member-card">
                        <img src="<?= avatarUrl($m['avatar_url'] ?? null) ?>" alt="" class="avatar">
                        <span><?= e($m['display_name'] ?: $m['username']) ?></span>
                        <small class="text-muted">с <?= formatDate($m['joined_at'] ?? '') ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
