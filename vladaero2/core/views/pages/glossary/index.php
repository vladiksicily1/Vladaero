<?php
/** @var array $terms */
/** @var array $letters */
/** @var string $letter */
/** @var string $search */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">📖 Авиационный глоссарий</h1>

        <form class="filters mb-4" method="GET">
            <div class="filters__row">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск термина..." class="filter-input">
                <button type="submit" class="btn btn--primary">Найти</button>
            </div>
        </form>

        <div class="letter-nav mb-6">
            <?php for ($c = ord('А'); $c <= ord('Я'); $c++): ?>
                <a href="?letter=<?= chr($c) ?>" class="letter-btn <?= $letter === chr($c) ? 'letter-btn--active' : '' ?>"><?= chr($c) ?></a>
            <?php endfor; ?>
            <a href="?" class="letter-btn <?= empty($letter) ? 'letter-btn--active' : '' ?>">Все</a>
        </div>

        <?php if (empty($terms)): ?>
            <div class="empty-state"><p>Термины не найдены</p></div>
        <?php else: ?>
            <div class="glossary-list">
                <?php $currentLetter = ''; ?>
                <?php foreach ($terms as $term): ?>
                    <?php $firstLetter = mb_strtoupper(mb_substr($term['term'], 0, 1)); ?>
                    <?php if ($firstLetter !== $currentLetter): ?>
                        <?php $currentLetter = $firstLetter; ?>
                        <h2 class="glossary-letter" id="letter-<?= $firstLetter ?>"><?= $firstLetter ?></h2>
                    <?php endif; ?>
                    <a href="<?= url('/glossary/' . e($term['slug'])) ?>" class="glossary-item">
                        <strong class="glossary-item__term"><?= e($term['term']) ?></strong>
                        <span class="glossary-item__def"><?= e(mb_substr($term['definition'], 0, 120)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
