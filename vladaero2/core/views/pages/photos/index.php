<?php
/** @var array $photos */
/** @var array $pagination */
/** @var string $search */
/** @var string $sort */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">📸 Фотогалерея споттинга</h1>

        <form class="filters" method="GET">
            <div class="filters__row">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск фото..." class="filter-input">
                <select name="sort" class="filter-select">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Новые</option>
                    <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>По рейтингу</option>
                    <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Популярные</option>
                </select>
                <button type="submit" class="btn btn--primary">Найти</button>
            </div>
        </form>

        <div class="grid grid--4">
            <?php foreach ($photos as $photo): ?>
                <a href="<?= url('/photos/' . $photo['id']) ?>" class="photo-card">
                    <img src="<?= url(e($photo['file_path'] ?? '')) ?>" alt="" class="photo-card__img" loading="lazy">
                    <div class="photo-card__overlay">
                        <span class="photo-card__user"><?= e($photo['username'] ?? '') ?></span>
                        <?php if (!empty($photo['aircraft_name'])): ?>
                            <span class="photo-card__aircraft">✈️ <?= e($photo['aircraft_name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($photo['airport_icao'])): ?>
                            <span class="photo-card__airport">📍 <?= e($photo['airport_icao']) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?= renderPagination($pagination, fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p]))) ?>
    </div>
</section>
