<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$slug = trim($_GET['slug'] ?? '');

// Single Article Longread View
if (!empty($slug)) {
    $article = DB::fetchOne("SELECT a.*, u.username, u.full_name, c.name_ru AS category_name FROM `va_articles` a JOIN `va_users` u ON a.author_id = u.id LEFT JOIN `va_article_categories` c ON a.category_id = c.id WHERE a.slug = :s AND a.is_published = 1", ['s' => $slug]);

    if (!$article) {
        header("HTTP/1.0 404 Not Found");
        require_once __DIR__ . '/404.php';
        exit;
    }

    // Increment view count
    DB::execute("UPDATE `va_articles` SET `views_count` = `views_count` + 1 WHERE `id` = :id", ['id' => $article['id']]);

    $blocks = json_decode((string)$article['blocks_json'], true) ?: [];

    $pageTitle = $article['title'];
    $pageDesc = $article['summary'];
    $pageImage = $article['cover_image'] ?: 'assets/images/logo.png';
    require_once __DIR__ . '/includes/header.php';
    ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        
        <!-- Article Header -->
        <div class="space-y-4 mb-8">
            <div class="flex items-center space-x-2 text-xs font-mono text-sky-400">
                <span><?= e($article['category_name'] ?: 'Авиация') ?></span>
                <span>•</span>
                <span><?= (int)$article['reading_time_min'] ?> мин чтения</span>
                <span>•</span>
                <span><?= date('d.m.Y', strtotime($article['published_at'])) ?></span>
            </div>

            <h1 class="text-3xl sm:text-4xl font-extrabold text-white leading-tight font-sans"><?= e($article['title']) ?></h1>
            <p class="text-base text-slate-300 leading-relaxed"><?= e($article['summary']) ?></p>

            <div class="flex items-center space-x-3 pt-2 text-xs font-mono text-slate-400 border-t border-white/5">
                <span>Автор: <strong class="text-white"><?= e($article['full_name'] ?: $article['username']) ?></strong></span>
                <span>•</span>
                <span><?= (int)$article['views_count'] ?> просмотров</span>
            </div>
        </div>

        <?php if ($article['cover_image']): ?>
            <div class="aspect-video rounded-3xl overflow-hidden glass-card border border-white/10 mb-10 shadow-2xl">
                <img src="<?= e($article['cover_image']) ?>" alt="<?= e($article['title']) ?>" class="w-full h-full object-cover">
            </div>
        <?php endif; ?>

        <!-- Content & 15+ Blocks Renderer -->
        <div class="space-y-6 text-sm text-slate-200 leading-relaxed font-sans">
            <div><?= nl2br(e($article['content'])) ?></div>

            <?php foreach ($blocks as $b): ?>
                <?php if ($b['type'] === 'hud_alert'): ?>
                    <div class="p-5 rounded-2xl glass-hud border border-sky-500/30 space-y-1 font-mono text-xs">
                        <div class="font-bold text-sky-400 uppercase">📟 <?= e($b['title'] ?? 'HUD NOTIFICATION') ?></div>
                        <div class="text-slate-300"><?= e($b['text'] ?? '') ?></div>
                    </div>
                <?php elseif ($b['type'] === 'specs_table' && !empty($b['specs'])): ?>
                    <div class="glass-card rounded-2xl p-5 border border-white/5 font-mono text-xs space-y-2">
                        <div class="font-bold text-amber-400 mb-2 border-b border-white/5 pb-1">📊 Спецификации материала</div>
                        <?php foreach ($b['specs'] as $k => $v): ?>
                            <div class="flex justify-between py-1 border-b border-white/5 last:border-0">
                                <span class="text-slate-400"><?= e($k) ?>:</span>
                                <span class="text-white font-bold"><?= e($v) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($b['type'] === 'quote'): ?>
                    <blockquote class="p-5 rounded-2xl bg-slate-900/80 border-l-4 border-amber-500 font-mono text-xs italic text-slate-300 space-y-2">
                        <div>«<?= e($b['text'] ?? '') ?>»</div>
                        <?php if (!empty($b['author'])): ?>
                            <div class="text-right text-amber-400 not-italic font-bold">— <?= e($b['author']) ?></div>
                        <?php endif; ?>
                    </blockquote>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Articles List
$articles = DB::fetchAll("SELECT a.*, u.username, c.name_ru AS category_name FROM `va_articles` a JOIN `va_users` u ON a.author_id = u.id LEFT JOIN `va_article_categories` c ON a.category_id = c.id WHERE a.is_published = 1 ORDER BY a.is_breaking DESC, a.id DESC");

$pageTitle = 'Авиационные новости, лонгриды и аналитика';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-white">Новости авиации и аналитические статьи</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Горячие пресс-релизы, эксклюзивные лонгриды и технические разборы</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($articles as $art): ?>
            <a href="articles.php?slug=<?= e($art['slug']) ?>" class="glass-card rounded-3xl overflow-hidden border border-white/5 hover:border-sky-500/40 transition group flex flex-col justify-between">
                <div>
                    <div class="relative aspect-video bg-slate-950 overflow-hidden">
                        <img src="<?= e($art['cover_image'] ?: 'https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?w=600&q=80') ?>" alt="<?= e($art['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                        <?php if ($art['is_breaking']): ?>
                            <span class="absolute top-3 left-3 px-2.5 py-1 rounded-lg bg-rose-600 text-white font-mono text-[10px] font-bold animate-pulse">
                                🔥 BREAKING NEWS
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="p-5 space-y-2">
                        <div class="text-[10px] font-mono text-sky-400 uppercase"><?= e($art['category_name'] ?: 'Статья') ?></div>
                        <h3 class="font-bold text-white text-base group-hover:text-sky-400 transition leading-snug"><?= e($art['title']) ?></h3>
                        <p class="text-xs text-slate-400 line-clamp-2 leading-relaxed"><?= e($art['summary']) ?></p>
                    </div>
                </div>

                <div class="p-5 pt-0 border-t border-white/5 mt-4 flex items-center justify-between text-[11px] font-mono text-slate-400">
                    <span><?= (int)$art['reading_time_min'] ?> мин</span>
                    <span class="text-sky-400">Читать ➔</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
