<?php /** @var array $articles */ ?>

<section class="section">
    <div class="container">
        <div class="section__header">
            <h1>📰 Управление новостями</h1>
            <a href="<?= url('/admin/news/new') ?>" class="btn btn--primary">+ Написать</a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>ID</th><th>Заголовок</th><th>Категория</th><th>Автор</th><th>Статус</th><th>Дата</th><th>Действия</th></tr></thead>
                <tbody>
                    <?php foreach ($articles as $a): ?>
                        <tr>
                            <td><?= $a['id'] ?></td>
                            <td><?= e(mb_substr($a['title'], 0, 50)) ?></td>
                            <td><?= e($a['category_name'] ?? '—') ?></td>
                            <td><?= e($a['author_name'] ?? '—') ?></td>
                            <td><span class="badge badge--sm badge--<?= $a['status'] === 'published' ? 'success' : 'warning' ?>"><?= e($a['status']) ?></span></td>
                            <td><?= formatDate($a['created_at']) ?></td>
                            <td>
                                <a href="<?= url('/admin/news/' . $a['id'] . '/edit') ?>" class="btn btn--sm btn--outline">✏️</a>
                                <form method="POST" action="<?= url('/admin/news/' . $a['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Удалить?')">
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
