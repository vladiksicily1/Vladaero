<?php /** @var array $airports */ ?>

<section class="section">
    <div class="container">
        <div class="section__header">
            <h1>🛫 Управление аэропортами</h1>
            <a href="<?= url('/admin/airports/new') ?>" class="btn btn--primary">+ Добавить</a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>ID</th><th>ICAO</th><th>IATA</th><th>Название</th><th>Город</th><th>Страна</th><th>Действия</th></tr></thead>
                <tbody>
                    <?php foreach ($airports as $ap): ?>
                        <tr>
                            <td><?= $ap['id'] ?></td>
                            <td><strong><?= e($ap['icao_code']) ?></strong></td>
                            <td><?= e($ap['iata_code']) ?></td>
                            <td><?= e($ap['name']) ?></td>
                            <td><?= e($ap['city']) ?></td>
                            <td><?= e($ap['country']) ?></td>
                            <td>
                                <a href="<?= url('/admin/airports/' . $ap['id'] . '/edit') ?>" class="btn btn--sm btn--outline">✏️</a>
                                <form method="POST" action="<?= url('/admin/airports/' . $ap['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Удалить?')">
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
