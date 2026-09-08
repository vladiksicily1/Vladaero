<?php
/** @var array $airports */
/** @var array $countries */
/** @var array $pagination */
/** @var string $search */
/** @var string $country */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🛫 Каталог аэропортов</h1>

        <form class="filters" method="GET">
            <div class="filters__row">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск по названию, IATA, ICAO..." class="filter-input">
                <select name="country" class="filter-select">
                    <option value="">Все страны</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?= e($c['country']) ?>" <?= $country === $c['country'] ? 'selected' : '' ?>><?= e($c['country']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn--primary">Найти</button>
            </div>
        </form>

        <p class="results-count">Найдено: <?= formatNumber($total ?? count($airports)) ?> аэропортов</p>

        <div class="grid grid--3">
            <?php foreach ($airports as $ap): ?>
                <a href="<?= url('/airports/' . e($ap['icao_code'])) ?>" class="card card--airport">
                    <div class="card__body">
                        <h3 class="card__title"><?= e($ap['name']) ?></h3>
                        <div class="card__specs">
                            <span class="badge badge--accent"><?= e($ap['icao_code']) ?></span>
                            <?php if (!empty($ap['iata_code'])): ?>
                                <span class="badge badge--sm"><?= e($ap['iata_code']) ?></span>
                            <?php endif; ?>
                            <span class="card__spec">📍 <?= e($ap['city']) ?>, <?= e($ap['country']) ?></span>
                        </div>
                        <div class="card__meta">
                            <span>📸 <?= $ap['photo_count'] ?? 0 ?> фото</span>
                            <span>📍 <?= $ap['spot_count'] ?? 0 ?> споттинг-точек</span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?= renderPagination($pagination, fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p]))) ?>
    </div>
</section>
