<?php
/** @var array $photos */
/** @var string $status */
/** @var array $counts */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">📸 Модерация фото</h1>

        <div class="tab-nav">
            <a href="?status=pending" class="tab <?= $status === 'pending' ? 'tab--active' : '' ?>">⏳ Ожидающие (<?= $counts['pending'] ?>)</a>
            <a href="?status=approved" class="tab <?= $status === 'approved' ? 'tab--active' : '' ?>">✅ Одобренные (<?= $counts['approved'] ?>)</a>
            <a href="?status=rejected" class="tab <?= $status === 'rejected' ? 'tab--active' : '' ?>">❌ Отклонённые (<?= $counts['rejected'] ?>)</a>
        </div>

        <?php if (empty($photos)): ?>
            <div class="empty-state"><p>Нет фото в этой категории</p></div>
        <?php else: ?>
            <form method="POST" action="<?= url('/admin/photos/bulk') ?>">
                <?= csrf_field() ?>
                <div class="grid grid--3">
                    <?php foreach ($photos as $photo): ?>
                        <div class="card card--photo-moderation">
                            <img src="<?= url(e($photo['file_path'] ?? '')) ?>" alt="" class="card__img" loading="lazy">
                            <div class="card__body">
                                <p><strong><?= e($photo['username'] ?? '—') ?></strong></p>
                                <p class="text-muted"><?= e($photo['aircraft_name'] ?? '') ?> <?= e($photo['airport_icao'] ?? '') ?></p>
                                <p class="text-muted"><?= timeAgo($photo['created_at']) ?></p>
                                <div class="btn-group">
                                    <label class="checkbox-label"><input type="checkbox" name="ids[]" value="<?= $photo['id'] ?>"></label>
                                    <?php if ($status === 'pending'): ?>
                                        <form method="POST" action="<?= url('/admin/photos/' . $photo['id'] . '/approve') ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn--sm btn--success">✅</button>
                                        </form>
                                        <form method="POST" action="<?= url('/admin/photos/' . $photo['id'] . '/reject') ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn--sm btn--danger">❌</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4">
                    <select name="bulk_action" class="form-select" style="display:inline-block;width:auto">
                        <option value="approve">Одобрить выбранные</option>
                        <option value="reject">Отклонить выбранные</option>
                        <option value="delete">Удалить выбранные</option>
                    </select>
                    <button type="submit" class="btn btn--primary">Применить</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
