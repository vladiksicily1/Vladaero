<?php /** @var array|null $result */ ?>

<section class="section">
    <div class="container">
        <h1 class="page-title">⛽ Калькулятор топлива</h1>
        <form method="POST" class="form-card">
            <div class="form-group">
                <label>Расстояние (км)</label>
                <input type="number" name="distance" class="form-input" step="any" required value="<?= e($_POST['distance'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Расход (литров/100км)</label>
                <input type="number" name="consumption" class="form-input" step="any" required value="<?= e($_POST['consumption'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Запас (%)</label>
                <input type="number" name="reserve" class="form-input" step="any" value="<?= e($_POST['reserve'] ?? '15') ?>">
            </div>
            <button type="submit" class="btn btn--primary">Рассчитать</button>
        </form>
        <?php if ($result): ?>
            <div class="result-card mt-4">
                <div class="ttx-grid">
                    <div class="ttx-item"><span class="ttx-item__label">Галлоны</span><span class="ttx-item__value"><?= $result['gallons'] ?></span></div>
                    <div class="ttx-item"><span class="ttx-item__label">Литры</span><span class="ttx-item__value"><?= $result['liters'] ?></span></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
