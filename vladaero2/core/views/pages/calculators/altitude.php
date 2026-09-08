<?php /** @var array|null $result */ ?>

<section class="section">
    <div class="container">
        <h1 class="page-title">⛰️ Конвертер высоты</h1>
        <form method="POST" class="form-card">
            <div class="form-group">
                <label>Значение</label>
                <input type="number" name="value" class="form-input" step="any" required value="<?= e($_POST['value'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Из единицы</label>
                <select name="from" class="form-select">
                    <option value="feet">Футы</option>
                    <option value="meters">Метры</option>
                    <option value="fl">Flight Level</option>
                </select>
            </div>
            <button type="submit" class="btn btn--primary">Конвертировать</button>
        </form>
        <?php if ($result): ?>
            <div class="result-card mt-4">
                <div class="ttx-grid">
                    <div class="ttx-item"><span class="ttx-item__label">Футы</span><span class="ttx-item__value"><?= $result['feet'] ?></span></div>
                    <div class="ttx-item"><span class="ttx-item__label">Метры</span><span class="ttx-item__value"><?= $result['meters'] ?></span></div>
                    <div class="ttx-item"><span class="ttx-item__label">FL</span><span class="ttx-item__value"><?= $result['fl'] ?></span></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
