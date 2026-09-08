<?php /** @var array|null $term */ $isEdit = !empty($term['id']); ?>
<section class="section"><div class="container">
    <h1 class="page-title"><?= $isEdit ? '✏️ Редактировать термин' : '➕ Новый термин' ?></h1>
    <form method="POST" action="<?= url('/admin/glossary/save') ?>" class="form-card">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $term['id'] ?>"><?php endif; ?>
        <div class="form-group"><label>Термин *</label><input type="text" name="term" class="form-input" required value="<?= e($term['term'] ?? '') ?>"></div>
        <div class="form-group"><label>Определение *</label><textarea name="definition" class="form-input" rows="4" required><?= e($term['definition'] ?? '') ?></textarea></div>
        <div class="form-row">
            <div class="form-group"><label>Категория</label><input type="text" name="category" class="form-input" value="<?= e($term['category'] ?? '') ?>" placeholder="aerodynamics, meteorology, avionics..."></div>
            <div class="form-group"><label>Связанные термины</label><input type="text" name="related_terms" class="form-input" value="<?= e($term['related_terms'] ?? '') ?>" placeholder="через запятую"></div>
        </div>
        <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
        <a href="<?= url('/admin/glossary') ?>" class="btn btn--outline">Отмена</a>
    </form>
</div></section>
