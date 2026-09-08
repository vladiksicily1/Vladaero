<?php /** @var array $terms */ ?>
<section class="section"><div class="container">
    <div class="section__header">
        <h1>📖 Глоссарий</h1>
        <div>
            <a href="<?= url('/admin/glossary/generate') ?>" class="btn btn--accent">🤖 Генерировать (AI)</a>
            <a href="<?= url('/admin/glossary/new') ?>" class="btn btn--primary">+ Добавить</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>ID</th><th>Термин</th><th>Категория</th><th>Определение</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($terms as $t): ?>
                <tr>
                    <td><?= $t['id'] ?></td>
                    <td><strong><?= e($t['term']) ?></strong></td>
                    <td><span class="badge badge--sm"><?= e($t['category'] ?? '—') ?></span></td>
                    <td><?= e(mb_substr($t['definition'], 0, 80)) ?></td>
                    <td>
                        <a href="<?= url('/admin/glossary/' . $t['id'] . '/edit') ?>" class="btn btn--sm btn--outline">✏️</a>
                        <form method="POST" action="<?= url('/admin/glossary/' . $t['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Удалить?')">
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
