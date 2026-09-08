<?php
/** @var array|null $checklist */
/** @var array $aircraft */
/** @var array $items (optional) */
$isEdit = !empty($checklist['id']);
?>
<section class="section"><div class="container">
    <h1 class="page-title"><?= $isEdit ? '✏️ Редактировать чек-лист' : '➕ Новый чек-лист' ?></h1>
    <form method="POST" action="<?= url('/admin/checklists/save') ?>" class="form-card">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $checklist['id'] ?>"><?php endif; ?>

        <div class="form-group"><label>Название *</label><input type="text" name="title" class="form-input" required value="<?= e($checklist['title'] ?? '') ?>"></div>
        <div class="form-row">
            <div class="form-group">
                <label>Самолёт</label>
                <select name="aircraft_id" class="form-select">
                    <option value="0">Общий</option>
                    <?php foreach ($aircraft as $ac): ?>
                        <option value="<?= $ac['id'] ?>" <?= ($checklist['aircraft_id'] ?? 0) == $ac['id'] ? 'selected' : '' ?>><?= e($ac['name']) ?> (<?= e($ac['type_code'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Фаза</label>
                <select name="phase" class="form-select">
                    <?php foreach (['pre_flight'=>'Pre-flight','taxi'=>'Taxi','takeoff'=>'Takeoff','cruise'=>'Cruise','approach'=>'Approach','landing'=>'Landing','emergency'=>'Emergency'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= ($checklist['phase'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <label class="checkbox-label"><input type="checkbox" name="voice_enabled" value="1" <?= ($checklist['voice_enabled'] ?? 0) ? 'checked' : '' ?>> 🔊 Озвучка (Web Speech API)</label>

        <h3 class="mt-4">📋 Пункты чек-листа</h3>
        <div id="itemsList">
            <?php $items = $items ?? [];
            foreach ($items as $i => $item): ?>
                <div class="form-row checklist-item-row">
                    <input type="text" name="item_action[]" class="form-input" placeholder="Действие (напр. Set)" value="<?= e($item['action'] ?? '') ?>">
                    <input type="text" name="item_item[]" class="form-input" placeholder="Пункт (напр. Flaps 5)" value="<?= e($item['item'] ?? '') ?>">
                    <input type="text" name="item_setting[]" class="form-input" placeholder="Настройка" value="<?= e($item['setting'] ?? '') ?>">
                    <button type="button" class="btn btn--sm btn--danger" onclick="this.parentElement.remove()">✕</button>
                </div>
            <?php endforeach; ?>
            <?php if (empty($items)): for ($i = 0; $i < 5; $i++): ?>
                <div class="form-row checklist-item-row">
                    <input type="text" name="item_action[]" class="form-input" placeholder="Действие">
                    <input type="text" name="item_item[]" class="form-input" placeholder="Пункт">
                    <input type="text" name="item_setting[]" class="form-input" placeholder="Настройка">
                    <button type="button" class="btn btn--sm btn--danger" onclick="this.parentElement.remove()">✕</button>
                </div>
            <?php endfor; endif; ?>
        </div>
        <button type="button" class="btn btn--outline btn--sm mt-2" onclick="addItem()">+ Добавить пункт</button>

        <div class="mt-4">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button>
            <a href="<?= url('/admin/checklists') ?>" class="btn btn--outline">Отмена</a>
        </div>
    </form>
</div></section>

<script>
function addItem() {
    const row = document.createElement('div');
    row.className = 'form-row checklist-item-row';
    row.innerHTML = '<input type="text" name="item_action[]" class="form-input" placeholder="Действие"><input type="text" name="item_item[]" class="form-input" placeholder="Пункт"><input type="text" name="item_setting[]" class="form-input" placeholder="Настройка"><button type="button" class="btn btn--sm btn--danger" onclick="this.parentElement.remove()">✕</button>';
    document.getElementById('itemsList').appendChild(row);
}
</script>
