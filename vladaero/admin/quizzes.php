<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Конструктор викторин и тестов';
require_once __DIR__ . '/header.php';

$action = $_GET['action'] ?? 'list';
$quizId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '') ?: slugify($title);
    $cat = trim($_POST['category'] ?? 'Теория полета');
    $diff = $_POST['difficulty'] ?? 'cadet';
    $desc = trim($_POST['description'] ?? '');
    $xp = (int)($_POST['reward_xp'] ?? 100);

    $quizData = [
        'title'       => $title,
        'slug'        => $slug,
        'category'    => $cat,
        'difficulty'  => $diff,
        'description' => $desc,
        'reward_xp'   => $xp,
        'status'      => 'approved'
    ];

    if ($quizId > 0) {
        DB::update('va_quizzes', $quizData, '`id` = :id', ['id' => $quizId]);
        record_audit('admin_update_quiz', 'quiz', $quizId);
    } else {
        $quizId = (int)DB::insert('va_quizzes', $quizData);
        record_audit('admin_create_quiz', 'quiz', $quizId);
    }

    header('Location: quizzes.php');
    exit;
}

$quiz = $quizId > 0 ? DB::fetchOne("SELECT * FROM `va_quizzes` WHERE `id` = :id", ['id' => $quizId]) : null;
$quizzes = DB::fetchAll("SELECT * FROM `va_quizzes` ORDER BY `id` ASC");
?>

<div class="space-y-6 font-mono text-xs">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans">Конструктор викторин и экзаменов</h1>
            <p class="text-xs text-slate-400 mt-1">Создание тестов по аэродинамике, метеорологии и распознаванию ВС</p>
        </div>

        <?php if ($action !== 'add' && $action !== 'edit'): ?>
            <a href="quizzes.php?action=add" class="px-5 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold transition flex items-center space-x-1.5 shadow-lg shadow-sky-600/30">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Создать викторину</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($action === 'add' || $action === 'edit'): ?>
        <form method="POST" class="glass-card rounded-3xl p-6 border border-sky-500/30 shadow-2xl space-y-6">
            <?= csrf_field() ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Название викторины</label>
                    <input type="text" name="title" value="<?= e($quiz['title'] ?? '') ?>" required class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-bold text-sm">
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Категория</label>
                    <input type="text" name="category" value="<?= e($quiz['category'] ?? 'Теория полета') ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Описание для пользователей</label>
                <textarea name="description" rows="3" class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 text-white"><?= e($quiz['description'] ?? '') ?></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Сложность</label>
                    <select name="difficulty" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <option value="cadet" <?= ($quiz['difficulty'] ?? '') === 'cadet' ? 'selected' : '' ?>>Курсант (Начальный)</option>
                        <option value="ppl" <?= ($quiz['difficulty'] ?? '') === 'ppl' ? 'selected' : '' ?>>PPL (Средний)</option>
                        <option value="cpl_atpl" <?= ($quiz['difficulty'] ?? '') === 'cpl_atpl' ? 'selected' : '' ?>>CPL / ATPL (Профи)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Награда (XP)</label>
                    <input type="number" name="reward_xp" value="<?= $quiz['reward_xp'] ?? 100 ?>" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                </div>
            </div>

            <div class="flex justify-end space-x-3">
                <a href="quizzes.php" class="px-5 py-2.5 rounded-xl bg-slate-800 text-slate-300">Отмена</a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold shadow-lg shadow-sky-600/30">
                    Сохранить викторину
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="glass-card rounded-3xl border border-white/5 overflow-hidden shadow-2xl">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-900/90 text-slate-400 border-b border-white/10 uppercase text-[10px]">
                    <tr>
                        <th class="p-4">Название</th>
                        <th class="p-4">Категория</th>
                        <th class="p-4">Сложность</th>
                        <th class="p-4">Награда XP</th>
                        <th class="p-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($quizzes as $qz): ?>
                        <tr class="hover:bg-sky-500/5 transition">
                            <td class="p-4 font-bold text-white"><?= e($qz['title']) ?></td>
                            <td class="p-4 text-sky-400"><?= e($qz['category']) ?></td>
                            <td class="p-4 text-slate-300"><?= e($qz['difficulty']) ?></td>
                            <td class="p-4 text-amber-400 font-bold">+<?= (int)$qz['reward_xp'] ?> XP</td>
                            <td class="p-4 text-right">
                                <a href="quizzes.php?action=edit&id=<?= $qz['id'] ?>" class="text-sky-400 hover:underline">Изменить</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
