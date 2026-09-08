<?php /** photo upload */ ?>

<section class="section">
    <div class="container">
        <h1 class="page-title">📸 Загрузить фото</h1>

        <form method="POST" action="<?= url('/photos/upload') ?>" enctype="multipart/form-data" class="form-card">
            <?= csrf_field() ?>

            <div class="form-group">
                <label>Фото *</label>
                <input type="file" name="photo" class="form-input" accept="image/*" required>
                <small class="text-muted">JPEG, PNG, WebP. Макс. 20 МБ.</small>
            </div>

            <div class="form-group">
                <label>Заголовок</label>
                <input type="text" name="title" class="form-input" placeholder="A320neo в небе над Шереметьево">
            </div>

            <div class="form-group">
                <label>Описание</label>
                <textarea name="description" class="form-input" rows="4" placeholder="Дополнительная информация о фото..."></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Аэропорт</label>
                    <input type="text" name="airport" class="form-input" placeholder="ICAO код">
                </div>
                <div class="form-group">
                    <label>Дата съёмки</label>
                    <input type="date" name="shot_date" class="form-input">
                </div>
            </div>

            <button type="submit" class="btn btn--primary">📤 Загрузить</button>
        </form>
    </div>
</section>
