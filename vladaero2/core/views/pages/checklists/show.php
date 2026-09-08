<?php
/** @var array $checklist */
/** @var array $items */
?>

<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="<?= url('/') ?>">Главная</a> ›
            <a href="<?= url('/checklists') ?>">Чек-листы</a> ›
            <span><?= e($checklist['title']) ?></span>
        </nav>

        <div class="checklist-page">
            <div class="checklist-page__header">
                <h1><?= e($checklist['title']) ?></h1>
                <div class="badge-group">
                    <?php if (!empty($checklist['aircraft_name'])): ?>
                        <span class="badge badge--accent"><?= e($checklist['aircraft_name']) ?></span>
                    <?php endif; ?>
                    <span class="badge badge--sm"><?= e($checklist['phase']) ?></span>
                </div>
                <p class="text-muted"><?= e($checklist['description'] ?? '') ?></p>

                <div class="checklist-progress mt-4">
                    <span id="checkProgress">0</span> / <?= count($items) ?> выполнено
                    <div class="progress-bar">
                        <div class="progress-bar__fill" id="progressFill" style="width: 0%"></div>
                    </div>
                </div>
            </div>

            <div class="checklist-items" id="checklistItems">
                <?php foreach ($items as $item): ?>
                    <label class="checklist-item" data-id="<?= $item['id'] ?>">
                        <input type="checkbox" class="checklist-item__checkbox" onchange="updateProgress()">
                        <span class="checklist-item__check">☑️</span>
                        <span class="checklist-item__action"><?= e($item['action']) ?></span>
                        <span class="checklist-item__item"><?= e($item['item']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="mt-4">
                <button onclick="resetChecklist()" class="btn btn--outline">🔄 Сбросить</button>
                <button onclick="checkAll()" class="btn btn--primary">✅ Выбрать все</button>
            </div>
        </div>
    </div>
</section>

<script>
function updateProgress() {
    const checkboxes = document.querySelectorAll('.checklist-item__checkbox');
    const checked = document.querySelectorAll('.checklist-item__checkbox:checked').length;
    const total = checkboxes.length;
    document.getElementById('checkProgress').textContent = checked;
    document.getElementById('progressFill').style.width = (checked / total * 100) + '%';

    checkboxes.forEach(cb => {
        cb.closest('.checklist-item').classList.toggle('checked', cb.checked);
    });

    // Play click sound
    if (typeof playClick === 'function') playClick();
}

function resetChecklist() {
    document.querySelectorAll('.checklist-item__checkbox').forEach(cb => cb.checked = false);
    updateProgress();
}

function checkAll() {
    document.querySelectorAll('.checklist-item__checkbox').forEach(cb => cb.checked = true);
    updateProgress();
}
</script>
