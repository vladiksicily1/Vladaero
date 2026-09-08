<?php
/** @var array $checklists */
/** @var array $aircraft */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">✅ Интерактивные чек-листы</h1>

        <div class="filters mb-6">
            <div class="filters__row">
                <select id="aircraftFilter" class="filter-select" onchange="filterChecklists(this.value)">
                    <option value="">Все самолёты</option>
                    <?php foreach ($aircraft as $ac): ?>
                        <option value="<?= $ac['id'] ?>"><?= e($ac['name']) ?> (<?= e($ac['type_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid--2" id="checklistGrid">
            <?php foreach ($checklists as $cl): ?>
                <div class="card card--checklist" data-aircraft="<?= $cl['aircraft_id'] ?>">
                    <div class="card__body">
                        <h3><a href="<?= url('/checklists/' . $cl['id']) ?>"><?= e($cl['title']) ?></a></h3>
                        <div class="card__specs">
                            <span class="badge badge--accent"><?= e($cl['aircraft_name'] ?? 'General') ?></span>
                            <span class="badge badge--sm"><?= e($cl['phase']) ?></span>
                        </div>
                        <p class="text-muted"><?= e($cl['description'] ?? '') ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<script>
function filterChecklists(aircraftId) {
    document.querySelectorAll('.card--checklist').forEach(card => {
        card.style.display = (!aircraftId || card.dataset.aircraft === aircraftId) ? '' : 'none';
    });
}
</script>
