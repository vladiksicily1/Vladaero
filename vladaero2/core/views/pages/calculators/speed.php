<?php /** @var array|null $result */ ?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🚀 Конвертер скорости</h1>
        <form method="POST" class="form-card">
            <div class="form-group">
                <label>Значение</label>
                <input type="number" name="value" class="form-input" step="any" required value="<?= e($_POST['value'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Из единицы</label>
                <select name="from" class="form-select">
                    <option value="knots">Узлы (knots)</option>
                    <option value="kmh">Км/ч</option>
                    <option value="mph">Миль/ч</option>
                    <option value="ms">М/с</option>
                </select>
            </div>
            <button type="submit" class="btn btn--primary">Конвертировать</button>
        </form>

        <?php if ($result): ?>
            <div class="result-card mt-4">
                <h3>Результат:</h3>
                <div class="ttx-grid">
                    <div class="ttx-item"><span class="ttx-item__label">Узлы</span><span class="ttx-item__value"><?= $result['knots'] ?></span></div>
                    <div class="ttx-item"><span class="ttx-item__label">Км/ч</span><span class="ttx-item__value"><?= $result['kmh'] ?></span></div>
                    <div class="ttx-item"><span class="ttx-item__label">Миль/ч</span><span class="ttx-item__value"><?= $result['mph'] ?></span></div>
                    <div class="ttx-item"><span class="ttx-item__label">М/с</span><span class="ttx-item__value"><?= $result['ms'] ?></span></div>
                    <div class="ttx-item"><span class="ttx-item__label">Мах</span><span class="ttx-item__value"><?= $result['mach'] ?></span></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
