<?php /** @var array|null $result */ ?>

<section class="section">
    <div class="container">
        <h1 class="page-title">⚖️ Конвертер массы</h1>
        <form method="POST" class="form-card">
            <div class="form-group">
                <label>Значение</label>
                <input type="number" name="value" class="form-input" step="any" required value="<?= e($_POST['value'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Из единицы</label>
                <select name="from" class="form-select">
                    <option value="kg">Килограммы</option>
                    <option value="lbs">Фунты</option>
                </select>
            </div>
            <button type="submit" class="btn btn--primary">Конвертировать</button>
        </form>
        <?php if ($result): ?>
            <div class="result-card mt-4">
                <div class="ttx-grid">
                    <div class="ttx-item"><span class="ttx-item__label">Кг</span><span class="ttx-item__value"><?= $result['kg'] ?></span></div>
                    <div class="ttx-item"><span class="ttx-item__label">Фунты</span><span class="ttx-item__value"><?= $result['lbs'] ?></span></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
