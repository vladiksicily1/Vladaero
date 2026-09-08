<?php
require_once __DIR__ . '/includes/functions.php';

$slug = trim($_GET['slug'] ?? '');
$artTable = Database::tableName('articles');
$catTable = Database::tableName('article_categories');
$usersTable = Database::tableName('users');

if (!empty($slug)) {
    // Single Article View
    $article = Database::isConfigured() ? Database::fetchOne("SELECT a.*, c.name_ru as category_name, u.username as author_name FROM `{$artTable}` a LEFT JOIN `{$catTable}` c ON a.category_id = c.id LEFT JOIN `{$usersTable}` u ON a.author_id = u.id WHERE a.slug = :s AND a.is_published = 1 LIMIT 1", ['s' => $slug]) : null;

    if (!$article) {
        header('Location: ' . url('/articles.php'));
        exit;
    }

    // Increment view count
    Database::query("UPDATE `{$artTable}` SET views_count = views_count + 1 WHERE id = :id", ['id' => $article['id']]);

    $pageTitle = $article['title'];
    $metaDescription = $article['summary'];
    require_once __DIR__ . '/includes/header.php';
    ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="flex items-center space-x-2 text-xs font-mono text-slate-400 mb-6">
            <a href="<?= url('/') ?>" class="hover:text-sky-400">Главная</a>
            <span>/</span>
            <a href="<?= url('/articles.php') ?>" class="hover:text-sky-400">Статьи</a>
            <span>/</span>
            <span class="text-slate-200"><?= e($article['category_name']) ?></span>
        </nav>

        <article class="va-card p-6 sm:p-10 space-y-6">
            <div class="space-y-3 pb-6 border-b border-slate-800">
                <div class="flex items-center space-x-3 font-mono text-xs">
                    <span class="px-2.5 py-0.5 rounded bg-sky-950 text-sky-400 border border-sky-800 font-bold"><?= e($article['category_name']) ?></span>
                    <span class="text-slate-500"><?= formatDate($article['created_at']) ?></span>
                    <span class="text-slate-500">•</span>
                    <span class="text-slate-400">Автор: <?= e($article['author_name']) ?></span>
                </div>
                <h1 class="text-3xl sm:text-4xl font-extrabold text-white leading-tight"><?= e($article['title']) ?></h1>
                <p class="text-base text-slate-300 font-medium leading-relaxed"><?= e($article['summary']) ?></p>
            </div>

            <?php if ($article['cover_image']): ?>
                <div class="rounded-2xl overflow-hidden aspect-[2/1] bg-slate-950">
                    <img src="<?= e($article['cover_image']) ?>" alt="<?= e($article['title']) ?>" class="w-full h-full object-cover">
                </div>
            <?php endif; ?>

            <div class="text-slate-200 text-sm leading-relaxed space-y-4 font-sans prose prose-invert max-w-none">
                <?= nl2br(e($article['content'])) ?>
            </div>
        </article>
    </div>

    <?php
} else {
    // Articles Catalog Grid
    $pageTitle = 'Статьи и Авиационные Лонгриды';
    $metaDescription = 'Статьи о современной авиации, история легендарных самолетов, принципы работы авионики и аналитические обзоры.';
    require_once __DIR__ . '/includes/header.php';

    $articles = Database::isConfigured() ? Database::fetchAll("SELECT a.*, c.name_ru as category_name, u.username FROM `{$artTable}` a LEFT JOIN `{$catTable}` c ON a.category_id = c.id LEFT JOIN `{$usersTable}` u ON a.author_id = u.id WHERE a.is_published = 1 ORDER BY a.id DESC") : [];
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="va-card p-6 sm:p-8 mb-8">
            <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3 mb-2">
                <i data-lucide="newspaper" class="w-8 h-8 text-sky-400"></i>
                <span>Авиационные Статьи и Лонгриды</span>
            </h1>
            <p class="text-xs text-slate-400 font-mono">
                Аналитика, история самолетостроения, технологии авионики и опыт пилотирования
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($articles as $art): ?>
                <a href="<?= url('/articles.php?slug=' . urlencode($art['slug'])) ?>" class="va-card p-6 block group flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-3 font-mono text-xs">
                            <span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 border border-sky-800 text-[10px]"><?= e($art['category_name']) ?></span>
                            <span class="text-slate-500 text-[11px]"><?= formatDate($art['created_at']) ?></span>
                        </div>
                        <h2 class="text-lg font-bold text-white group-hover:text-sky-400 transition mb-2"><?= e($art['title']) ?></h2>
                        <p class="text-xs text-slate-400 leading-relaxed mb-6 line-clamp-3"><?= e($art['summary']) ?></p>
                    </div>
                    <div class="flex items-center space-x-1 text-xs font-mono font-bold text-sky-400">
                        <span>Читать статью</span>
                        <i data-lucide="arrow-right" class="w-3.5 h-3.5 group-hover:translate-x-1 transition"></i>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
}
require_once __DIR__ . '/includes/footer.php';
?>
