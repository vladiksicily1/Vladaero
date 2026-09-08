<?php /** @var array $airlines */ ?>

<section class="section">
    <div class="container">
        <div class="section__header">
            <h1>🏢 Управление авиакомпаниями</h1>
            <a href="<?= url('/admin/airlines/new') ?>" class="btn btn--primary">+ Добавить</a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>ID</th><th>Название</th><th>IATA</th><th>ICAO</th><th>Страна</th><th>Флот</th><th>Действия</th></tr></thead>
                <tbody>
                    <?php foreach ($airlines as $al): ?>
                        <tr>
                            <td><?= $al['id'] ?></td>
                            <td><a href="<?= url('/airlines/' . e($al['slug'])) ?>"><?= e($al['name']) ?></a></td>
                            <td><?= e($al['iata_code']) ?></td>
                            <td><?= e($al['icao_code']) ?></td>
                            <td><?= e($al['country']) ?></td>
                            <td><?= $al['fleet_count'] ?? 0 ?></td>
                            <td>
                                <a href="<?= url('/admin/airlines/' . $al['id'] . '/edit') ?>" class="btn btn--sm btn--outline">✏️</a>
                                <form method="POST" action="<?= url('/admin/airlines/' . $al['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Удалить?')">
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
