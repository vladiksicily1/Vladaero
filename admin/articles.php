<?php
$adminTitle = 'Управление Статьями и Новостями';
require_once __DIR__ . '/header.php';

$artTable = Database::tableName('articles');
$catTable = Database::tableName('article_categories');

// Handle Add/Edit Article POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article'])) {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $catId = (int)($_POST['category_id'] ?? 1);
    $summary = trim($_POST['summary'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $cover = trim($_POST['cover_image'] ?? '');
    $isPub = !empty($_POST['is_published']) ? 1 : 0;
    $slug = slugify($title);

    if ($id > 0) {
        Database::update('articles', [
            'title' => $title,
            'category_id' => $catId,
            'summary' => $summary,
            'content' => $content,
            'cover_image' => $cover,
            'is_published' => $isPub
        ], 'id = :id', ['id' => $id]);
        setFlash('success', 'Статья успешно обновлена!');
    } else {
        Database::insert('articles', [
            'author_id' => $currentUser['id'],
            'category_id' => $catId,
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'content' => $content,
            'cover_image' => $cover,
            'is_published' => $isPub
        ]);
        setFlash('success', 'Новая статья успешно опубликована!');
    }

    header('Location: ' . url('/admin/articles.php'));
    exit;
}

$articles = Database::fetchAll("SELECT a.*, c.name_ru as category_name FROM `{$artTable}` a LEFT JOIN `{$catTable}` c ON a.category_id = c.id ORDER BY a.id DESC");
$categories = Database::fetchAll("SELECT * FROM `{$catTable}`");
?>

<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Управление Статьями</h1>
            <p class="text-xs text-slate-400 font-mono">Публикация лонгридов, аналитических материалов и новостей</p>
        </div>

        <button onclick="document.getElementById('art-modal').classList.remove('hidden')" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-4 py-2.5 rounded-xl shadow-lg transition flex items-center space-x-1.5">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Написать статью</span>
        </button>
    </div>

    <!-- Table -->
    <div class="va-card overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Заголовок</th>
                        <th class="p-4">Категория</th>
                        <th class="p-4">Просмотры</th>
                        <th class="p-4">Статус</th>
                        <th class="p-4">Дата</th>
                        <th class="p-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php foreach ($articles as $art): ?>
                        <tr class="hover:bg-slate-900/60 transition">
                            <td class="p-4 text-slate-500">#<?= $art['id'] ?></td>
                            <td class="p-4 font-bold text-white max-w-sm truncate"><?= e($art['title']) ?></td>
                            <td class="p-4 text-sky-400"><?= e($art['category_name']) ?></td>
                            <td class="p-4 text-slate-300"><?= $art['views_count'] ?></td>
                            <td class="p-4">
                                <?php if ($art['is_published']): ?>
                                    <span class="px-2 py-0.5 rounded bg-emerald-950 text-emerald-400 border border-emerald-800 text-[10px]">Опубликовано</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded bg-slate-950 text-slate-400 border border-slate-800 text-[10px]">Черновик</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-slate-500"><?= formatDate($art['created_at']) ?></td>
                            <td class="p-4 text-right">
                                <a href="<?= url('/articles.php?slug=' . urlencode($art['slug'])) ?>" target="_blank" class="text-slate-400 hover:text-white">
                                    <i data-lucide="external-link" class="w-4 h-4 inline"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal -->
<div id="art-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl shadow-2xl p-6 relative max-h-[90vh] overflow-y-auto">
        <button onclick="document.getElementById('art-modal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-white">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <h2 class="text-lg font-bold text-white mb-4">Написать Статью</h2>

        <form method="POST" class="space-y-4 font-mono text-xs">
            <input type="hidden" name="save_article" value="1">
            <input type="hidden" name="id" value="0">

            <div>
                <label class="block text-slate-400 mb-1">Заголовок статьи:</label>
                <input type="text" name="title" required placeholder="Эволюция крыла: от стреловидности до законцовок Sharklet" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-bold font-sans">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Категория:</label>
                    <select name="category_id" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name_ru']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">URL обложки (Cover Image):</label>
                    <input type="text" name="cover_image" placeholder="https://..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Краткий анонс / лид (Summary):</label>
                <textarea name="summary" rows="2" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-slate-100 font-sans"></textarea>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Текст статьи (Markdown / HTML):</label>
                <textarea name="content" rows="8" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-slate-100 font-sans"></textarea>
            </div>

            <div class="flex items-center space-x-2">
                <input type="checkbox" name="is_published" id="is_pub" value="1" checked class="rounded bg-slate-950 border-slate-800 text-sky-600">
                <label for="is_pub" class="text-slate-300">Опубликовать сразу</label>
            </div>

            <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl shadow-lg transition">
                Сохранить и Опубликовать
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
