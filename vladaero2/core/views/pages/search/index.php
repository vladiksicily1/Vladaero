<?php
/** @var string $query */
/** @var array $results */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🔍 Поиск: <?= e($query) ?></h1>

        <?php if (empty($results)): ?>
            <div class="empty-state">
                <p>Ничего не найдено по запросу «<?= e($query) ?>»</p>
            </div>
        <?php else: ?>
            <p class="results-count">Найдено: <?= count($results) ?> результатов</p>
            <div class="search-results">
                <?php foreach ($results as $r): ?>
                    <a href="<?= url(e($r['url'] ?? '#')) ?>" class="search-result">
                        <span class="search-result__type badge badge--sm"><?= e($r['type'] ?? '') ?></span>
                        <h3 class="search-result__title"><?= e($r['title'] ?? $r['name'] ?? '') ?></h3>
                        <?php if (!empty($r['description'])): ?>
                            <p class="search-result__desc"><?= e(mb_substr(strip_tags($r['description']), 0, 150)) ?></p>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
