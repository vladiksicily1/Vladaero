<?php
/** @var array|null $airport */
$isEdit = !empty($airport['id']);
?>

<section class="section">
    <div class="container">
        <h1 class="page-title"><?= $isEdit ? '✏️ Редактировать аэропорт' : '➕ Новый аэропорт' ?></h1>
        <form method="POST" action="<?= url('/admin/airports/save') ?>" class="form-card">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $airport['id'] ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group"><label>ICAO *</label><input type="text" name="icao_code" class="form-input" required maxlength="4" value="<?= e($airport['icao_code'] ?? '') ?>"></div>
                <div class="form-group"><label>IATA</label><input type="text" name="iata_code" class="form-input" maxlength="3" value="<?= e($airport['iata_code'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label>Название *</label><input type="text" name="name" class="form-input" required value="<?= e($airport['name'] ?? '') ?>"></div>
            <div class="form-row">
                <div class="form-group"><label>Город</label><input type="text" name="city" class="form-input" value="<?= e($airport['city'] ?? '') ?>"></div>
                <div class="form-group"><label>Страна</label><input type="text" name="country" class="form-input" value="<?= e($airport['country'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Широта</label><input type="number" name="latitude" class="form-input" step="any" value="<?= $airport['latitude'] ?? '' ?>"></div>
                <div class="form-group"><label>Долгота</label><input type="number" name="longitude" class="form-input" step="any" value="<?= $airport['longitude'] ?? '' ?>"></div>
                <div class="form-group"><label>Высота (м)</label><input type="number" name="elevation" class="form-input" value="<?= $airport['elevation'] ?? '' ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>ВПП (м)</label><input type="number" name="runway_length" class="form-input" value="<?= $airport['runway_length'] ?? '' ?>"></div>
                <div class="form-group"><label>Тип</label>
                    <select name="airport_type" class="form-select">
                        <option value="international" <?= ($airport['airport_type'] ?? '') === 'international' ? 'selected' : '' ?>>Международный</option>
                        <option value="domestic" <?= ($airport['airport_type'] ?? '') === 'domestic' ? 'selected' : '' ?>>Внутренний</option>
                        <option value="military" <?= ($airport['airport_type'] ?? '') === 'military' ? 'selected' : '' ?>>Военный</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Описание</label><textarea name="description" class="form-input" rows="4"><?= e($airport['description'] ?? '') ?></textarea></div>
            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
            <a href="<?= url('/admin/airports') ?>" class="btn btn--outline">Отмена</a>
        </form>
    </div>
</section>
