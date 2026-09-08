<?php
/** @var array $airlines */
/** @var array $countries */
/** @var array $pagination */
/** @var string $search */
/** @var string $country */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🏢 Каталог авиакомпаний</h1>

        <form class="filters" method="GET">
            <div class="filters__row">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск..." class="filter-input">
                <select name="country" class="filter-select">
                    <option value="">Все страны</option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?= e($c['country']) ?>" <?= $country === $c['country'] ? 'selected' : '' ?>><?= e($c['country']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn--primary">Найти</button>
            </div>
        </form>

        <p class="results-count">Найдено: <?= formatNumber($total ?? count($airlines)) ?> авиакомпаний</p>

        <div class="grid grid--3">
            <?php foreach ($airlines as $al): ?>
                <a href="<?= url('/airlines/' . e($al['slug'])) ?>" class="card card--airline">
                    <?php if (!empty($al['logo_url'])): ?>
                        <img src="<?= url(e($al['logo_url'])) ?>" alt="" class="card__logo">
                    <?php endif; ?>
                    <div class="card__body">
                        <h3 class="card__title"><?= e($al['name']) ?></h3>
                        <div class="card__specs">
                            <?php if (!empty($al['iata_code'])): ?>
                                <span class="badge badge--accent"><?= e($al['iata_code']) ?></span>
                            <?php endif; ?>
                            <span class="card__spec">✈️ <?= $al['fleet_count'] ?? 0 ?> в флоте</span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?= renderPagination($pagination, fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p]))) ?>
    </div>
</section>
