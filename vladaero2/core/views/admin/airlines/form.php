<?php
/** @var array|null $airline */
$isEdit = !empty($airline['id']);
?>

<section class="section">
    <div class="container">
        <h1 class="page-title"><?= $isEdit ? '✏️ Редактировать авиакомпанию' : '➕ Новая авиакомпания' ?></h1>
        <form method="POST" action="<?= url('/admin/airlines/save') ?>" class="form-card">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $airline['id'] ?>"><?php endif; ?>
            <div class="form-group"><label>Название *</label><input type="text" name="name" class="form-input" required value="<?= e($airline['name'] ?? '') ?>"></div>
            <div class="form-row">
                <div class="form-group"><label>IATA</label><input type="text" name="iata_code" class="form-input" maxlength="3" value="<?= e($airline['iata_code'] ?? '') ?>"></div>
                <div class="form-group"><label>ICAO</label><input type="text" name="icao_code" class="form-input" maxlength="4" value="<?= e($airline['icao_code'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Страна</label><input type="text" name="country" class="form-input" value="<?= e($airline['country'] ?? '') ?>"></div>
                <div class="form-group"><label>Основана</label><input type="text" name="founded" class="form-input" value="<?= e($airline['founded'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Хаб</label><input type="text" name="hub_airport" class="form-input" value="<?= e($airline['hub_airport'] ?? '') ?>"></div>
                <div class="form-group"><label>Альянс</label><input type="text" name="alliance" class="form-input" value="<?= e($airline['alliance'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label>Сайт</label><input type="url" name="website" class="form-input" value="<?= e($airline['website'] ?? '') ?>"></div>
            <div class="form-group"><label>Описание</label><textarea name="description" class="form-input" rows="4"><?= e($airline['description'] ?? '') ?></textarea></div>
            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
            <a href="<?= url('/admin/airlines') ?>" class="btn btn--outline">Отмена</a>
        </form>
    </div>
</section>
