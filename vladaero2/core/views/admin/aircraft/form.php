<?php
/** @var array|null $aircraft */
$isEdit = !empty($aircraft['id']);
?>

<section class="section">
    <div class="container">
        <h1 class="page-title"><?= $isEdit ? '✏️ Редактировать самолёт' : '➕ Новый самолёт' ?></h1>

        <form method="POST" action="<?= url('/admin/aircraft/save') ?>" class="form-card">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= $aircraft['id'] ?>">
            <?php endif; ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Название *</label>
                    <input type="text" name="name" class="form-input" required value="<?= e($aircraft['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Код типа</label>
                    <input type="text" name="type_code" class="form-input" value="<?= e($aircraft['type_code'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Производитель</label>
                    <input type="text" name="manufacturer" class="form-input" value="<?= e($aircraft['manufacturer'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Категория</label>
                    <select name="category" class="form-select">
                        <option value="airliner" <?= ($aircraft['category'] ?? '') === 'airliner' ? 'selected' : '' ?>>Пассажирский</option>
                        <option value="military" <?= ($aircraft['category'] ?? '') === 'military' ? 'selected' : '' ?>>Военный</option>
                        <option value="cargo" <?= ($aircraft['category'] ?? '') === 'cargo' ? 'selected' : '' ?>>Грузовой</option>
                        <option value="ga" <?= ($aircraft['category'] ?? '') === 'ga' ? 'selected' : '' ?>>Малая авиация</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Тип двигателя</label>
                    <select name="engine_type" class="form-select">
                        <option value="jet" <?= ($aircraft['engine_type'] ?? '') === 'jet' ? 'selected' : '' ?>>Реактивный</option>
                        <option value="turboprop" <?= ($aircraft['engine_type'] ?? '') === 'turboprop' ? 'selected' : '' ?>>Турбовинтовой</option>
                        <option value="piston" <?= ($aircraft['engine_type'] ?? '') === 'piston' ? 'selected' : '' ?>>Поршневой</option>
                        <option value="electric" <?= ($aircraft['engine_type'] ?? '') === 'electric' ? 'selected' : '' ?>>Электрический</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Кол-во двигателей</label>
                    <input type="number" name="engine_count" class="form-input" value="<?= $aircraft['engine_count'] ?? 2 ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Макс. скорость (узлы)</label>
                    <input type="number" name="max_speed" class="form-input" value="<?= $aircraft['max_speed_knots'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label>Дальность (км)</label>
                    <input type="number" name="range_km" class="form-input" value="<?= $aircraft['range_km'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label>Пассажиров</label>
                    <input type="number" name="passengers" class="form-input" value="<?= $aircraft['passengers'] ?? '' ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Первый полёт</label>
                <input type="date" name="first_flight" class="form-input" value="<?= e($aircraft['first_flight'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Описание</label>
                <textarea name="description" class="form-input" rows="6"><?= e($aircraft['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Wikipedia URL</label>
                <input type="url" name="wikipedia_url" class="form-input" value="<?= e($aircraft['wikipedia_url'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
            <a href="<?= url('/admin/aircraft') ?>" class="btn btn--outline">Отмена</a>
        </form>
    </div>
</section>
