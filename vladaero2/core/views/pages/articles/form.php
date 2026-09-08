<?php
/** @var array|null $article */
/** @var array $categories */
$isEdit = !empty($article['id']);
?>

<section class="section">
    <div class="container">
        <h1 class="page-title"><?= $isEdit ? '✏️ Редактировать статью' : '✍️ Новая статья' ?></h1>

        <form method="POST" action="<?= url($isEdit ? '/blog/' . $article['id'] . '/edit' : '/blog/new') ?>" class="form-card">
            <?= csrf_field() ?>

            <div class="form-group">
                <label>Заголовок *</label>
                <input type="text" name="title" class="form-input" required value="<?= e($article['title'] ?? '') ?>">
            </div>

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
                <label>Краткое описание</label>
                <textarea name="excerpt" class="form-input" rows="2" placeholder="Краткое описание для превью"><?= e($article['excerpt'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Содержание (HTML) *</label>
                <textarea name="content" class="form-input form-input--code" rows="20" required placeholder="Полный текст статьи..."><?= e($article['content'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>URL обложки</label>
                <input type="url" name="cover_image" class="form-input" value="<?= e($article['cover_image'] ?? '') ?>" placeholder="https://example.com/image.jpg">
            </div>

            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Сохранить' : 'Опубликовать' ?></button>
            <a href="<?= url('/articles') ?>" class="btn btn--outline">Отмена</a>
        </form>
    </div>
</section>
