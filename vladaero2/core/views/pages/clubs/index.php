<?php
/** @var array $clubs */
/** @var string $search */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">👥 Авиационные клубы</h1>

        <form class="filters mb-6" method="GET">
            <div class="filters__row">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск клуба..." class="filter-input">
                <button type="submit" class="btn btn--primary">Найти</button>
            </div>
        </form>

        <?php if (empty($clubs)): ?>
            <div class="empty-state"><p>Клубов пока нет. Будьте первым!</p></div>
        <?php else: ?>
            <div class="grid grid--3">
                <?php foreach ($clubs as $club): ?>
                    <a href="<?= url('/clubs/' . e($club['slug'])) ?>" class="card card--club">
                        <?php if (!empty($club['cover_image'])): ?>
                            <img src="<?= url(e($club['cover_image'])) ?>" alt="" class="card__img" loading="lazy">
                        <?php endif; ?>
                        <div class="card__body">
                            <h3 class="card__title"><?= e($club['name']) ?></h3>
                            <p class="card__desc"><?= e(mb_substr($club['description'] ?? '', 0, 120)) ?></p>
                            <div class="card__specs">
                                <span class="card__spec">👥 <?= $club['member_count'] ?? 0 ?> участников</span>
                                <?php if (!empty($club['founder_name'])): ?>
                                    <span class="card__spec">Основатель: <?= e($club['founder_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
