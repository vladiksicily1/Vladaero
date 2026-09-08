<?php /** @var array $user */ ?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/profile/' . e($user['username'])) ?>"><?= e($user['display_name'] ?: $user['username']) ?></a> ›
            <span>Избранное</span>
        </nav>

        <h1>⭐ Избранное <?= e($user['display_name'] ?: $user['username']) ?></h1>

        <div class="empty-state">
            <p>Добавляйте самолёты, аэропорты и статьи в избранное — они появятся здесь.</p>
        </div>
    </div>
</section>
