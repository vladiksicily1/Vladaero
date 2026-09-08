<?php /** @var array $checklists */ ?>
<section class="section"><div class="container">
    <div class="section__header">
        <h1>✅ Чек-листы</h1>
        <a href="<?= url('/admin/checklists/new') ?>" class="btn btn--primary">+ Добавить</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>ID</th><th>Название</th><th>Самолёт</th><th>Фаза</th><th>Голос</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($checklists as $cl): ?>
                <tr>
                    <td><?= $cl['id'] ?></td>
                    <td><?= e($cl['title']) ?></td>
                    <td><?= e($cl['aircraft_name'] ?? '—') ?></td>
                    <td><span class="badge badge--sm"><?= e($cl['phase']) ?></span></td>
                    <td><?= $cl['voice_enabled'] ? '🔊' : '—' ?></td>
                    <td>
                        <a href="<?= url('/admin/checklists/' . $cl['id'] . '/edit') ?>" class="btn btn--sm btn--outline">✏️</a>
                        <form method="POST" action="<?= url('/admin/checklists/' . $cl['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Удалить?')">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--sm btn--danger">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div></section>
