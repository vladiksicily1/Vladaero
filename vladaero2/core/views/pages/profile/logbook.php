<?php /** @var array $user */ ?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/profile/' . e($user['username'])) ?>"><?= e($user['display_name'] ?: $user['username']) ?></a> ›
            <span>Бортжурнал</span>
        </nav>

        <h1>📋 Бортжурнал <?= e($user['display_name'] ?: $user['username']) ?></h1>

        <div class="logbook-stats grid grid--4">
            <div class="stat-card"><span class="stat-card__num">0</span><span class="stat-card__label">Всего полётов</span></div>
            <div class="stat-card"><span class="stat-card__num">0</span><span class="stat-card__label">Часов налёта</span></div>
            <div class="stat-card"><span class="stat-card__num">0</span><span class="stat-card__label">Аэропортов</span></div>
            <div class="stat-card"><span class="stat-card__num">0</span><span class="stat-card__label">Стран</span></div>
        </div>

        <div class="empty-state mt-6">
            <p>Flight Logbook — ведите дневник полётов, отмечайте посещённые аэропорты и собирайте статистику.</p>
        </div>
    </div>
</section>
