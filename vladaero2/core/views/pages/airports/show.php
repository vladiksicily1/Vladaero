<?php
/** @var array $airport */
/** @var string|null $metar */
/** @var string|null $taf */
/** @var array $spotting */
/** @var array $photos */
?>

<?= schemaAirport($airport) ?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/airports') ?>">Аэропорты</a> ›
            <span><?= e($airport['name']) ?></span>
        </nav>

        <div class="airport-detail">
            <div class="airport-detail__header">
                <h1><?= e($airport['name']) ?></h1>
                <div class="badge-group">
                    <span class="badge badge--accent"><?= e($airport['icao_code']) ?></span>
                    <?php if (!empty($airport['iata_code'])): ?>
                        <span class="badge badge--sm"><?= e($airport['iata_code']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item"><span class="info-label">Город</span><span><?= e($airport['city']) ?></span></div>
                <div class="info-item"><span class="info-label">Страна</span><span><?= e($airport['country']) ?></span></div>
                <div class="info-item"><span class="info-label">Координаты</span><span><?= $airport['latitude'] ?>°, <?= $airport['longitude'] ?>°</span></div>
                <div class="info-item"><span class="info-label">Высота</span><span><?= $airport['elevation'] ?> м</span></div>
                <?php if (!empty($airport['runway_length'])): ?>
                    <div class="info-item"><span class="info-label">ВПП</span><span><?= formatNumber($airport['runway_length']) ?> м</span></div>
                <?php endif; ?>
            </div>

            <!-- METAR -->
            <?php if ($metar): ?>
                <div class="metar-box">
                    <h3>🌤️ METAR</h3>
                    <code class="metar-code"><?= e($metar) ?></code>
                </div>
            <?php endif; ?>

            <?php if ($taf): ?>
                <div class="metar-box">
                    <h3>📋 TAF</h3>
                    <code class="metar-code"><?= e($taf) ?></code>
                </div>
            <?php endif; ?>

            <?php if (!empty($airport['description'])): ?>
                <div class="prose"><?= nl2br(e($airport['description'])) ?></div>
            <?php endif; ?>

            <?php if (!empty($spotting)): ?>
                <h2>📍 Споттинг-точки</h2>
                <div class="grid grid--2">
                    <?php foreach ($spotting as $point): ?>
                        <div class="card card--sm">
                            <div class="card__body">
                                <h3><?= e($point['name']) ?></h3>
                                <p class="text-muted"><?= e($point['description'] ?? '') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($photos)): ?>
                <h2>📸 Фотографии</h2>
                <div class="grid grid--3">
                    <?php foreach ($photos as $photo): ?>
                        <a href="<?= url('/photos/' . $photo['id']) ?>" class="photo-card">
                            <img src="<?= url(e($photo['file_path'] ?? '')) ?>" alt="" class="photo-card__img" loading="lazy">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
