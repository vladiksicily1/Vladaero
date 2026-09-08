<?php /** @var array|null $row */ $isEdit = !empty($row['id']); ?>
<section class="section"><div class="container">
    <h1 class="page-title"><?= $isEdit ? '✏️ Редактировать фразу' : '➕ Новая фраза' ?></h1>
    <form method="POST" action="<?= url('/admin/phraseology/save') ?>" class="form-card">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $row['id'] ?>"><?php endif; ?>
        <div class="form-group"><label>Категория *</label>
            <select name="category" class="form-select">
                <?php foreach (['general'=>'Общая','taxi'=>'Taxi','takeoff'=>'Takeoff','cruise'=>'Cruise','approach'=>'Approach','landing'=>'Landing','emergency'=>'Emergency'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= ($row['category'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group"><label>🇷🇺 Русский *</label><textarea name="russian" class="form-input" rows="3" required><?= e($row['russian'] ?? $row['pilot_phrase_ru'] ?? '') ?></textarea></div>
            <div class="form-group"><label>🇬🇧 English</label><textarea name="english" class="form-input" rows="3"><?= e($row['english'] ?? $row['pilot_phrase_en'] ?? '') ?></textarea></div>
        </div>
        <div class="form-group"><label>Фонетика</label><input type="text" name="phonetic" class="form-input" value="<?= e($row['phonetic'] ?? '') ?>" placeholder="ВINGTON ONE EIGHT"></div>
        <div class="form-group"><label>Контекст / Пример использования</label><textarea name="context" class="form-input" rows="2"><?= e($row['context'] ?? '') ?></textarea></div>
        <div class="form-group"><label>Порядок</label><input type="number" name="sort_order" class="form-input" value="<?= $row['sort_order'] ?? 0 ?>"></div>
        <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
        <a href="<?= url('/admin/phraseology') ?>" class="btn btn--outline">Отмена</a>
    </form>
</div></section>
