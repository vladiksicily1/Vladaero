<?php
/** @var array $phrases */
/** @var array $phases */
/** @var string $phase */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🎙️ Фразеология радиообмена</h1>

        <div class="tab-nav mb-6">
            <a href="<?= url('/phraseology') ?>" class="tab <?= empty($phase) ? 'tab--active' : '' ?>">Все</a>
            <?php foreach ($phases as $p): ?>
                <a href="<?= url('/phraseology?phase=' . urlencode($p['phase'])) ?>" class="tab <?= $phase === $p['phase'] ? 'tab--active' : '' ?>"><?= e($p['phase']) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="phraseology-list">
            <?php $currentPhase = ''; ?>
            <?php foreach ($phrases as $phrase): ?>
                <?php if ($phrase['phase'] !== $currentPhase): ?>
                    <?php $currentPhase = $phrase['phase']; ?>
                    <h2 class="phraseology-phase"><?= e($currentPhase) ?></h2>
                <?php endif; ?>
                <div class="phrase-card">
                    <div class="phrase-card__ru">
                        <strong>🇷🇺 <?= e($phrase['russian']) ?></strong>
                        <span class="text-muted"><?= e($phrase['context'] ?? '') ?></span>
                    </div>
                    <div class="phrase-card__en">
                        <strong>🇬🇧 <?= e($phrase['english']) ?></strong>
                        <span class="text-muted"><?= e($phrase['phonetic'] ?? '') ?></span>
                    </div>
                    <?php if (!empty($phrase['usage'])): ?>
                        <div class="phrase-card__usage text-muted">
                            <em><?= e($phrase['usage']) ?></em>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
