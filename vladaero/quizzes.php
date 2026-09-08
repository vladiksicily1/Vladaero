<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$quizSlug = trim($_GET['slug'] ?? '');

// Active Quiz Taking View
if (!empty($quizSlug)) {
    $quiz = DB::fetchOne("SELECT * FROM `va_quizzes` WHERE `slug` = :s AND `status` = 'approved'", ['s' => $quizSlug]);
    if (!$quiz) {
        header("HTTP/1.0 404 Not Found");
        require_once __DIR__ . '/404.php';
        exit;
    }

    $questions = DB::fetchAll("SELECT * FROM `va_quiz_questions` WHERE `quiz_id` = :id ORDER BY `id` ASC", ['id' => $quiz['id']]);

    $pageTitle = "Тест: {$quiz['title']}";
    require_once __DIR__ . '/includes/header.php';
    ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        
        <!-- Quiz Header -->
        <div class="glass-hud p-6 rounded-3xl border border-sky-500/30 mb-8 flex items-center justify-between">
            <div>
                <span class="text-[10px] font-mono text-sky-400 uppercase font-bold"><?= e($quiz['category']) ?></span>
                <h1 class="text-2xl font-bold text-white"><?= e($quiz['title']) ?></h1>
                <p class="text-xs text-slate-400 font-mono mt-1"><?= e($quiz['description']) ?></p>
            </div>
            <div class="text-right font-mono">
                <span class="text-amber-400 font-bold text-lg">+<?= (int)$quiz['reward_xp'] ?> XP</span>
                <div class="text-[10px] text-slate-500 uppercase">Награда за сдачу</div>
            </div>
        </div>

        <!-- Questions Form -->
        <form id="quizForm" onsubmit="submitQuizAnswers(event)" class="space-y-6">
            <?php foreach ($questions as $idx => $q): ?>
                <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-4 font-mono text-xs" id="q_card_<?= $q['id'] ?>">
                    <div class="flex items-center justify-between border-b border-white/5 pb-2">
                        <span class="text-sky-400 font-bold">Вопрос <?= $idx + 1 ?> из <?= count($questions) ?></span>
                    </div>
                    
                    <div class="text-sm font-bold text-white font-sans"><?= e($q['question_text']) ?></div>

                    <div class="space-y-2 pt-2">
                        <?php foreach (['a', 'b', 'c', 'd'] as $opt): 
                            $optText = $q['option_' . $opt];
                            if (empty($optText)) continue;
                        ?>
                            <label class="flex items-center space-x-3 p-3 rounded-xl bg-slate-900/60 hover:bg-sky-500/10 border border-white/5 cursor-pointer transition">
                                <input type="radio" name="answer_<?= $q['id'] ?>" value="<?= $opt ?>" class="text-sky-500 focus:ring-0 bg-slate-800" required>
                                <span class="text-slate-200"><?= strtoupper($opt) ?>) <?= e($optText) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Hidden Explanation (revealed after submit) -->
                    <div class="hidden p-3 rounded-xl bg-sky-500/10 border border-sky-500/20 text-sky-300 text-[11px] leading-relaxed explanation-box">
                        💡 <strong>Разбор:</strong> <?= e($q['explanation']) ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="flex justify-end pt-4">
                <button type="submit" id="quizSubmitBtn" class="px-8 py-3.5 rounded-2xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white font-mono text-sm font-bold shadow-xl shadow-sky-600/30 transition">
                    Завершить тест и проверить результаты
                </button>
            </div>
        </form>

        <!-- Results Card (Hidden) -->
        <div id="quizResultSummary" class="hidden glass-hud p-8 rounded-3xl border border-emerald-500/30 text-center space-y-4 mt-8">
            <div class="w-16 h-16 bg-emerald-500/10 text-emerald-400 rounded-full flex items-center justify-center mx-auto text-3xl">🏆</div>
            <h2 class="text-2xl font-bold text-white">Тест успешно завершён!</h2>
            <div class="text-sm font-mono text-emerald-400" id="quizScoreText">Ваш результат: 3 из 3 (100%)</div>
            <p class="text-xs text-slate-300 font-mono">Очки опыта (+<?= (int)$quiz['reward_xp'] ?> XP) начислены в ваш личный профиль.</p>
            <div class="pt-4 flex justify-center space-x-3">
                <a href="quizzes.php" class="px-5 py-2.5 rounded-xl bg-slate-800 text-white font-mono text-xs">К списку тестов</a>
                <a href="profile.php" class="px-5 py-2.5 rounded-xl bg-sky-600 text-white font-mono text-xs font-bold">Мой профиль</a>
            </div>
        </div>

    </div>

    <script>
        const correctAnswers = <?= json_encode(array_combine(array_column($questions, 'id'), array_column($questions, 'correct_option'))) ?>;

        function submitQuizAnswers(e) {
            e.preventDefault();
            let score = 0;
            const total = Object.keys(correctAnswers).length;

            for (const [qid, correctOpt] of Object.entries(correctAnswers)) {
                const selected = document.querySelector(`input[name="answer_${qid}"]:checked`);
                const card = document.getElementById(`q_card_${qid}`);
                const exp = card.querySelector('.explanation-box');
                exp.classList.remove('hidden');

                if (selected && selected.value === correctOpt) {
                    score++;
                    card.classList.add('border-emerald-500/40', 'bg-emerald-950/10');
                } else {
                    card.classList.add('border-rose-500/40', 'bg-rose-950/10');
                }
            }

            document.getElementById('quizSubmitBtn').classList.add('hidden');
            const resBox = document.getElementById('quizResultSummary');
            resBox.classList.remove('hidden');
            document.getElementById('quizScoreText').innerText = `Ваш результат: ${score} из ${total} (${Math.round((score/total)*100)}%)`;

            resBox.scrollIntoView({ behavior: 'smooth' });
        }
    </script>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Quizzes Directory
$quizzes = DB::fetchAll("SELECT * FROM `va_quizzes` WHERE `status` = 'approved' ORDER BY `id` ASC");

$pageTitle = 'Авиационные викторины, экзамены и тесты';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-white">Авиационные викторины и экзамены</h1>
        <p class="text-xs text-slate-400 font-mono mt-1">Проверьте свои теоретические знания и получите очки опыта (XP) и авиационные ранги</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php foreach ($quizzes as $qz): ?>
            <div class="glass-card rounded-3xl p-6 border border-white/5 hover:border-amber-500/40 transition flex flex-col justify-between group">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-400 font-mono text-[10px] font-bold border border-amber-500/20">
                            <?= e($qz['category']) ?>
                        </span>
                        <span class="text-xs font-mono font-bold text-sky-400">+<?= (int)$qz['reward_xp'] ?> XP</span>
                    </div>

                    <h3 class="text-lg font-bold text-white group-hover:text-amber-400 transition"><?= e($qz['title']) ?></h3>
                    <p class="text-xs text-slate-400 leading-relaxed"><?= e($qz['description']) ?></p>
                </div>

                <div class="pt-6 mt-4 border-t border-white/5">
                    <a href="quizzes.php?slug=<?= e($qz['slug']) ?>" class="w-full py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-mono text-xs font-bold text-center block shadow-lg shadow-sky-600/20 transition">
                        Начать тестирование ➔
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
