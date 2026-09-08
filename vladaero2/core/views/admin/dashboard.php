<?php
/** @var array $stats */
/** @var array $recentPhotos */
/** @var array $recentUsers */
?>

<section class="section">
    <div class="container">
        <h1 class="page-title">🛡️ Админ-панель</h1>

        <div class="admin-stats grid grid--4">
            <div class="stat-card"><span class="stat-card__num"><?= formatNumber($stats['users'] ?? 0) ?></span><span class="stat-card__label">Пользователей</span></div>
            <div class="stat-card"><span class="stat-card__num"><?= formatNumber($stats['aircraft'] ?? 0) ?></span><span class="stat-card__label">Самолётов</span></div>
            <div class="stat-card"><span class="stat-card__num"><?= formatNumber($stats['airports'] ?? 0) ?></span><span class="stat-card__label">Аэропортов</span></div>
            <div class="stat-card"><span class="stat-card__num"><?= formatNumber($stats['photos'] ?? 0) ?></span><span class="stat-card__label">Фото</span></div>
            <div class="stat-card stat-card--warning"><span class="stat-card__num"><?= $stats['pending_photos'] ?? 0 ?></span><span class="stat-card__label">На модерации</span></div>
            <div class="stat-card"><span class="stat-card__num"><?= formatNumber($stats['news'] ?? 0) ?></span><span class="stat-card__label">Новостей</span></div>
            <div class="stat-card"><span class="stat-card__num"><?= formatNumber($stats['airlines'] ?? 0) ?></span><span class="stat-card__label">Авиакомпаний</span></div>
            <div class="stat-card"><span class="stat-card__num"><?= formatNumber($stats['comments'] ?? 0) ?></span><span class="stat-card__label">Комментариев</span></div>
        </div>

        <h3 class="mt-6">📋 Управление контентом</h3>
        <div class="admin-nav grid grid--4 mt-4">
            <a href="<?= url('/admin/aircraft') ?>" class="card card--center card--sm"><div class="card__body">✈️ Самолёты</div></a>
            <a href="<?= url('/admin/airports') ?>" class="card card--center card--sm"><div class="card__body">🛫 Аэропорты</div></a>
            <a href="<?= url('/admin/airlines') ?>" class="card card--center card--sm"><div class="card__body">🏢 Авиакомпании</div></a>
            <a href="<?= url('/admin/news') ?>" class="card card--center card--sm"><div class="card__body">📰 Новости</div></a>
            <a href="<?= url('/admin/photos') ?>" class="card card--center card--sm"><div class="card__body">📸 Фото</div></a>
            <a href="<?= url('/admin/checklists') ?>" class="card card--center card--sm"><div class="card__body">✅ Чек-листы</div></a>
            <a href="<?= url('/admin/glossary') ?>" class="card card--center card--sm"><div class="card__body">📖 Глоссарий</div></a>
            <a href="<?= url('/admin/phraseology') ?>" class="card card--center card--sm"><div class="card__body">🎙️ Фразеология</div></a>
        </div>

        <h3 class="mt-6">🤖 ИИ и системное управление</h3>
        <div class="admin-nav grid grid--4 mt-4">
            <a href="<?= url('/admin/ai-settings') ?>" class="card card--center card--sm card--accent"><div class="card__body">⚙️ Настройки ИИ</div></a>
            <a href="<?= url('/admin/ai') ?>" class="card card--center card--sm card--accent"><div class="card__body">🤖 Супер-Агент</div></a>
            <a href="<?= url('/admin/settings') ?>" class="card card--center card--sm card--accent"><div class="card__body">🎨 Настройки сайта & Медиа</div></a>
        </div>

        <div class="grid grid--2 mt-6">
            <div class="card">
                <div class="card__body">
                    <h3>Последние пользователи</h3>
                    <?php foreach ($recentUsers as $u): ?>
                        <div class="list-item">
                            <span><?= e($u['username']) ?></span>
                            <span class="text-muted"><?= timeAgo($u['created_at']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card">
                <div class="card__body">
                    <h3>Последние фото</h3>
                    <?php foreach ($recentPhotos as $p): ?>
                        <div class="list-item">
                            <span><?= e($p['username'] ?? '—') ?></span>
                            <span class="badge badge--sm"><?= e($p['status']) ?></span>
                            <span class="text-muted"><?= timeAgo($p['created_at']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
