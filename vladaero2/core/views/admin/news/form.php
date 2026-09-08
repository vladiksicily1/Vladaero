<?php
/** @var array|null $article */
/** @var array $categories */
$isEdit = !empty($article['id']);
?>

<section class="section">
    <div class="container">
        <h1 class="page-title"><?= $isEdit ? '✏️ Редактировать статью' : '➕ Новая статья' ?></h1>
        <form method="POST" action="<?= url('/admin/news/save') ?>" class="form-card">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $article['id'] ?>"><?php endif; ?>
            <div class="form-group"><label>Заголовок *</label><input type="text" name="title" class="form-input" required value="<?= e($article['title'] ?? '') ?>"></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Категория</label>
                    <select name="category_id" class="form-select">
                        <option value="0">Без категории</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($article['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Статус</label>
                    <select name="status" class="form-select">
                        <option value="draft" <?= ($article['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Черновик</option>
                        <option value="published" <?= ($article['status'] ?? '') === 'published' ? 'selected' : '' ?>>Опубликовано</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Краткое описание</label><textarea name="excerpt" class="form-input" rows="2"><?= e($article['excerpt'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Контент (HTML)</label><textarea name="content" class="form-input" rows="15"><?= e($article['content'] ?? '') ?></textarea></div>
            <div class="form-group"><label>Обложка URL</label><input type="url" name="cover_image" class="form-input" value="<?= e($article['cover_image'] ?? '') ?>"></div>
            <label class="checkbox-label"><input type="checkbox" name="is_pinned" value="1" <?= ($article['is_pinned'] ?? 0) ? 'checked' : '' ?>> Закрепить</label>
            <div class="mt-4">
                <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Сохранить' : 'Опубликовать' ?></button>
                <a href="<?= url('/admin/news') ?>" class="btn btn--outline">Отмена</a>
            </div>
        </form>
    </div>
</section>
