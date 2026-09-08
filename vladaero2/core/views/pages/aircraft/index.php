<?php
/** @var array $aircraft */
/** @var array $manufacturers */
/** @var array $pagination */
/** @var string|null $search */
/** @var string|null $type */
/** @var string|null $manufacturer */
/** @var string $sort */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">✈️ Энциклопедия самолётов</h1>

        <!-- Filters -->
        <form class="filters" method="GET">
            <div class="filters__row">
                <input type="text" name="q" value="<?= e($search ?? '') ?>" placeholder="Поиск..." class="filter-input">
                <select name="manufacturer" class="filter-select">
                    <option value="">Все производители</option>
                    <?php foreach ($manufacturers as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $manufacturer == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="category" class="filter-select">
                    <option value="">Все категории</option>
                    <option value="airliner" <?= ($type ?? '') === 'airliner' ? 'selected' : '' ?>>Пассажирские</option>
                    <option value="military" <?= ($type ?? '') === 'military' ? 'selected' : '' ?>>Военные</option>
                    <option value="cargo" <?= ($type ?? '') === 'cargo' ? 'selected' : '' ?>>Грузовые</option>
                    <option value="ga" <?= ($type ?? '') === 'ga' ? 'selected' : '' ?>>Малая авиация</option>
                </select>
                <select name="sort" class="filter-select">
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>По названию</option>
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>По дате</option>
                    <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>По популярности</option>
                    <option value="speed" <?= $sort === 'speed' ? 'selected' : '' ?>>По скорости</option>
                </select>
                <button type="submit" class="btn btn--primary">Найти</button>
            </div>
        </form>

        <p class="results-count">Найдено: <?= formatNumber($total ?? count($aircraft)) ?> самолётов</p>

        <div class="grid grid--3">
            <?php foreach ($aircraft as $ac): ?>
                <a href="<?= url('/aircraft/' . e($ac['slug'])) ?>" class="card card--aircraft">
                    <?php if (!empty($ac['image'])): ?>
                        <img src="<?= url(e($ac['image'])) ?>" alt="<?= e($ac['name']) ?>" class="card__img" loading="lazy">
                    <?php else: ?>
                        <div class="card__img card__img--placeholder">✈️</div>
                    <?php endif; ?>
                    <div class="card__body">
                        <h3 class="card__title"><?= e($ac['name']) ?></h3>
                        <div class="card__specs">
                            <?php if (!empty($ac['manufacturer_name'])): ?>
                                <span class="card__spec">🏭 <?= e($ac['manufacturer_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ac['category'])): ?>
                                <span class="badge badge--sm"><?= e($ac['category']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?= renderPagination($pagination, fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p]))) ?>
    </div>
</section>
