<?php
$adminTitle = 'Управление Викторинами и Тестами';
require_once __DIR__ . '/header.php';

$qzTable = Database::tableName('quizzes');
$qTable = Database::tableName('quiz_questions');

// Handle Add Quiz POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quiz'])) {
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $diff = trim($_POST['difficulty'] ?? 'novice');
    $xp = (int)($_POST['xp_reward'] ?? 100);

    Database::insert('quizzes', [
        'title' => $title,
        'description' => $desc,
        'difficulty' => $diff,
        'xp_reward' => $xp
    ]);

    setFlash('success', 'Новая викторина успешно создана!');
    header('Location: ' . url('/admin/quizzes.php'));
    exit;
}

$quizzes = Database::fetchAll("SELECT q.*, COUNT(qq.id) as q_count FROM `{$qzTable}` q LEFT JOIN `{$qTable}` qq ON q.id = qq.quiz_id GROUP BY q.id ORDER BY q.id DESC");
?>

<div class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Управление Викторинами</h1>
            <p class="text-xs text-slate-400 font-mono">Создание тестов, редактирование вопросов и настройка наград XP</p>
        </div>

        <button onclick="document.getElementById('quiz-modal').classList.remove('hidden')" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-4 py-2.5 rounded-xl shadow-lg transition flex items-center space-x-1.5">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Создать викторину</span>
        </button>
    </div>

    <!-- Table -->
    <div class="va-card overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono">
                <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Название</th>
                        <th class="p-4">Сложность</th>
                        <th class="p-4">Вопросов</th>
                        <th class="p-4">Награда XP</th>
                        <th class="p-4 text-right">Ссылка</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php foreach ($quizzes as $qz): ?>
                        <tr class="hover:bg-slate-900/60 transition">
                            <td class="p-4 text-slate-500">#<?= $qz['id'] ?></td>
                            <td class="p-4 font-bold text-white"><?= e($qz['title']) ?></td>
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 border border-sky-800 uppercase text-[10px]">
                                    <?= e($qz['difficulty']) ?>
                                </span>
                            </td>
                            <td class="p-4 text-slate-200"><?= $qz['q_count'] ?> шт.</td>
                            <td class="p-4 text-amber-400 font-bold">+<?= $qz['xp_reward'] ?> XP</td>
                            <td class="p-4 text-right">
                                <a href="<?= url('/quizzes.php?id=' . $qz['id']) ?>" target="_blank" class="text-slate-400 hover:text-white">
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
<div id="quiz-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl p-6 relative">
        <button onclick="document.getElementById('quiz-modal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-white">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <h2 class="text-lg font-bold text-white mb-4">Создать Викторину</h2>

        <form method="POST" class="space-y-4 font-mono text-xs">
            <input type="hidden" name="save_quiz" value="1">

            <div>
                <label class="block text-slate-400 mb-1">Название викторины:</label>
                <input type="text" name="title" required placeholder="Радионавигация VOR/DME и заходы ILS" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-bold font-sans">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-400 mb-1">Уровень сложности:</label>
                    <select name="difficulty" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                        <option value="novice">Новичок (Любитель)</option>
                        <option value="intermediate">Курсант PPL</option>
                        <option value="advanced">Пилот CPL / ATPL</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Награда XP:</label>
                    <input type="number" name="xp_reward" value="150" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-amber-400 font-bold">
                </div>
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Описание:</label>
                <textarea name="description" rows="3" required placeholder="Проверьте знание правил полетов по приборам..." class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-slate-100 font-sans"></textarea>
            </div>

            <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl shadow-lg transition">
                Сохранить викторину
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
