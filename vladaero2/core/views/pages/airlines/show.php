<?php
/** @var array $airline */
/** @var array $fleet */
/** @var array $photos */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/airlines') ?>">Авиакомпании</a> ›
            <span><?= e($airline['name']) ?></span>
        </nav>

        <div class="airline-detail">
            <div class="airline-detail__header">
                <?php if (!empty($airline['logo_url'])): ?>
                    <img src="<?= url(e($airline['logo_url'])) ?>" alt="" class="airline-detail__logo">
                <?php endif; ?>
                <div>
                    <h1><?= e($airline['name']) ?></h1>
                    <div class="badge-group">
                        <?php if (!empty($airline['iata_code'])): ?>
                            <span class="badge badge--accent"><?= e($airline['iata_code']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($airline['icao_code'])): ?>
                            <span class="badge badge--sm"><?= e($airline['icao_code']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item"><span class="info-label">Страна</span><span><?= e($airline['country']) ?></span></div>
                <?php if (!empty($airline['founded'])): ?>
                    <div class="info-item"><span class="info-label">Основана</span><span><?= e($airline['founded']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($airline['hub_airport'])): ?>
                    <div class="info-item"><span class="info-label">Хаб</span><span><?= e($airline['hub_airport']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($airline['alliance'])): ?>
                    <div class="info-item"><span class="info-label">Альянс</span><span><?= e($airline['alliance']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($airline['website'])): ?>
                    <div class="info-item"><span class="info-label">Сайт</span><a href="<?= e($airline['website']) ?>" target="_blank"><?= e($airline['website']) ?></a></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($fleet)): ?>
                <h2>✈️ Флот</h2>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Тип</th><th>Модель</th><th>Кол-во</th></tr></thead>
                        <tbody>
                            <?php foreach ($fleet as $f): ?>
                                <tr>
                                    <td><a href="<?= url('/aircraft/' . e($f['type_code'] ?? '')) ?>"><?= e($f['aircraft_name'] ?? 'N/A') ?></a></td>
                                    <td><?= e($f['type_code'] ?? '') ?></td>
                                    <td><?= $f['count'] ?? 1 ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
