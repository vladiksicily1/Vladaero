<?php /** @var array $rows */ ?>
<section class="section"><div class="container">
    <div class="section__header">
        <h1>🎙️ Фразеология</h1>
        <a href="<?= url('/admin/phraseology/new') ?>" class="btn btn--primary">+ Добавить</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>ID</th><th>Категория</th><th>🇷🇺 Русский</th><th>🇬🇧 English</th><th>Фонетика</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><span class="badge badge--sm"><?= e($r['category']) ?></span></td>
                    <td><?= e($r['russian']) ?></td>
                    <td><?= e($r['english'] ?? '') ?></td>
                    <td class="text-muted"><?= e($r['phonetic'] ?? '') ?></td>
                    <td>
                        <a href="<?= url('/admin/phraseology/' . $r['id'] . '/edit') ?>" class="btn btn--sm btn--outline">✏️</a>
                        <form method="POST" action="<?= url('/admin/phraseology/' . $r['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Удалить?')">
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
