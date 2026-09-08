<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Редактор статей и новостей';
require_once __DIR__ . '/header.php';

$action = $_GET['action'] ?? 'list';
$articleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '') ?: slugify($title);
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $summary = trim($_POST['summary'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $cover = trim($_POST['cover_image'] ?? '');
    $isBreaking = isset($_POST['is_breaking']) ? 1 : 0;
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    $artData = [
        'author_id'    => Auth::id(),
        'title'        => $title,
        'slug'         => $slug,
        'category_id'  => $categoryId,
        'summary'      => $summary,
        'content'      => $content,
        'cover_image'  => $cover,
        'is_breaking'  => $isBreaking,
        'is_published' => $isPublished
    ];

    if ($articleId > 0) {
        DB::update('va_articles', $artData, '`id` = :id', ['id' => $articleId]);
        record_audit('admin_update_article', 'article', $articleId);
    } else {
        $articleId = (int)DB::insert('va_articles', $artData);
        record_audit('admin_create_article', 'article', $articleId);
    }

    header('Location: articles.php');
    exit;
}

$article = $articleId > 0 ? DB::fetchOne("SELECT * FROM `va_articles` WHERE `id` = :id", ['id' => $articleId]) : null;
$articlesList = DB::fetchAll("SELECT a.*, c.name_ru AS category_name FROM `va_articles` a LEFT JOIN `va_article_categories` c ON a.category_id = c.id WHERE a.deleted_at IS NULL ORDER BY a.id DESC");
$categories = DB::fetchAll("SELECT * FROM `va_article_categories`");
?>

<div class="space-y-6 font-mono text-xs">
    
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans">Редактор статей и новостной ленты</h1>
            <p class="text-xs text-slate-400 mt-1">Публикация авиационных новостей, лонгридов и пресс-релизов</p>
        </div>

        <?php if ($action !== 'add' && $action !== 'edit'): ?>
            <a href="articles.php?action=add" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold transition flex items-center space-x-1.5 shadow-lg shadow-sky-600/30">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Написать статью</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($action === 'add' || $action === 'edit'): ?>
        <form method="POST" class="glass-card rounded-3xl p-6 border border-sky-500/30 shadow-2xl space-y-6">
            <?= csrf_field() ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Заголовок статьи</label>
                    <input type="text" name="title" value="<?= e($article['title'] ?? '') ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-bold text-sm">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Категория</label>
                    <select name="category_id" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <option value="">-- Без категории --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($article['category_id'] ?? 0) === $cat['id'] ? 'selected' : '' ?>><?= e($cat['name_ru']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Краткий лид / Анонс</label>
                <textarea name="summary" rows="2" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 text-white"><?= e($article['summary'] ?? '') ?></textarea>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Основной текст материала (с поддержкой Markdown и разметки)</label>
                <textarea name="content" rows="10" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 text-white"><?= e($article['content'] ?? '') ?></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Обложка статьи (URL)</label>
                    <input type="text" name="cover_image" value="<?= e($article['cover_image'] ?? '') ?>" placeholder="https://..." class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
                <div class="flex items-center space-x-6 pt-5">
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="is_breaking" value="1" <?= !empty($article['is_breaking']) ? 'checked' : '' ?> class="rounded bg-slate-800 text-rose-500">
                        <span class="text-rose-400 font-bold">🔥 Срочная новость</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="is_published" value="1" <?= (!isset($article['is_published']) || $article['is_published']) ? 'checked' : '' ?> class="rounded bg-slate-800 text-emerald-500">
                        <span class="text-emerald-400 font-bold">Опубликовано</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end space-x-3">
                <a href="articles.php" class="px-5 py-2.5 rounded-xl bg-slate-800 text-slate-300">Отмена</a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold shadow-lg shadow-sky-600/30">
                    Сохранить статью
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="glass-card rounded-3xl border border-white/5 overflow-hidden shadow-2xl">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-900/90 text-slate-400 border-b border-white/10 uppercase text-[10px]">
                    <tr>
                        <th class="p-4">Заголовок</th>
                        <th class="p-4">Категория</th>
                        <th class="p-4">Просмотры</th>
                        <th class="p-4">Статус</th>
                        <th class="p-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($articlesList as $art): ?>
                        <tr class="hover:bg-sky-500/5 transition">
                            <td class="p-4 font-bold text-white"><?= e($art['title']) ?></td>
                            <td class="p-4 text-sky-400"><?= e($art['category_name'] ?: '—') ?></td>
                            <td class="p-4 text-slate-400"><?= (int)$art['views_count'] ?></td>
                            <td class="p-4">
                                <?php if ($art['is_published']): ?>
                                    <span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 font-bold">Опубликовано</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 font-bold">Черновик</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-right">
                                <a href="articles.php?action=edit&id=<?= $art['id'] ?>" class="text-sky-400 hover:underline">Изменить</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
