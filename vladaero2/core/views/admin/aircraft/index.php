<?php /** @var array $aircraft */ ?>

<section class="section">
    <div class="container">
        <div class="section__header">
            <h1>✈️ Управление самолётами</h1>
            <a href="<?= url('/admin/aircraft/new') ?>" class="btn btn--primary">+ Добавить</a>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>ID</th><th>Название</th><th>Код</th><th>Производитель</th><th>Фото</th><th>Действия</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($aircraft as $ac): ?>
                        <tr>
                            <td><?= $ac['id'] ?></td>
                            <td><a href="<?= url('/aircraft/' . e($ac['slug'] ?? $ac['type_code'])) ?>"><?= e($ac['name']) ?></a></td>
                            <td><?= e($ac['type_code']) ?></td>
                            <td><?= e($ac['manufacturer']) ?></td>
                            <td><?= $ac['photo_count'] ?? 0 ?></td>
                            <td>
                                <a href="<?= url('/admin/aircraft/' . $ac['id'] . '/edit') ?>" class="btn btn--sm btn--outline">✏️</a>
                                <form method="POST" action="<?= url('/admin/aircraft/' . $ac['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Удалить?')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn--sm btn--danger">🗑️</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
