<?php
$pageTitle = 'Авиационные Тесты и Викторины — Проверь свои знания';
$metaDescription = 'Тесты по авиации, викторины по аэродинамике, правилам полетов VFR/IFR, метеорологии METAR и радиообмену. Зарабатывайте XP и повышайте летный ранг!';
require_once __DIR__ . '/includes/header.php';

$quizTable = Database::tableName('quizzes');
$qTable = Database::tableName('quiz_questions');

// Handle XP reward AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['award_quiz_xp'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (Auth::isLoggedIn()) {
        $xp = (int)($_POST['xp'] ?? 100);
        $user = Auth::getCurrentUser();
        Auth::addXp((int)$user['id'], $xp, 'Победа в авиационной викторине');
        echo json_encode(['success' => true, 'new_xp' => $user['xp_points'] + $xp]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
    }
    exit;
}

$quizId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($quizId > 0) {
    // Single Quiz Screen
    $quiz = Database::isConfigured() ? Database::fetchOne("SELECT * FROM `{$quizTable}` WHERE id = :id", ['id' => $quizId]) : null;
    $rawQuestions = Database::isConfigured() ? Database::fetchAll("SELECT * FROM `{$qTable}` WHERE quiz_id = :id ORDER BY id ASC", ['id' => $quizId]) : [];

    // Format questions for JS
    $questions = [];
    foreach ($rawQuestions as $q) {
        $questions[] = [
            'id' => $q['id'],
            'question' => $q['question_text'],
            'options' => [
                $q['option_a'],
                $q['option_b'],
                $q['option_c'],
                $q['option_d']
            ],
            'correct_index' => ord(strtolower($q['correct_option'])) - ord('a'),
            'explanation' => $q['explanation']
        ];
    }
    ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Header -->
        <div class="va-card p-6 sm:p-8 mb-8">
            <nav class="flex items-center space-x-2 text-xs font-mono text-slate-400 mb-4">
                <a href="<?= url('/quizzes.php') ?>" class="hover:text-sky-400">Викторины</a>
                <span>/</span>
                <span class="text-slate-200"><?= e($quiz['title'] ?? 'Тест') ?></span>
            </nav>

            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white"><?= e($quiz['title'] ?? 'Авиационный экзамен') ?></h1>
                    <p class="text-xs text-slate-400 mt-1"><?= e($quiz['description'] ?? '') ?></p>
                </div>
                <div class="px-4 py-2 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 font-mono text-xs font-bold whitespace-nowrap">
                    +<?= $quiz['reward_xp'] ?? 150 ?> XP за завершение
                </div>
            </div>
        </div>

        <!-- Interactive Question Flow Container -->
        <div id="quiz-container" class="va-card p-6 sm:p-8 space-y-6">
            <div id="question-box">
                <!-- Dynamically populated via JS -->
            </div>
        </div>
    </div>

    <script>
        const questions = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
        const rewardXp = <?= (int)($quiz['reward_xp'] ?? 150) ?>;
        let currentIdx = 0;
        let score = 0;

        function renderQuestion() {
            const box = document.getElementById('question-box');
            if (!questions || questions.length === 0) {
                box.innerHTML = '<div class="text-center py-8 text-slate-400 font-mono">Вопросы для этого теста готовятся инструкторами.</div>';
                return;
            }

            if (currentIdx >= questions.length) {
                // Award XP if user logged in
                const percent = Math.round((score / questions.length) * 100);
                if (score >= Math.ceil(questions.length * 0.7)) {
                    fetch('<?= url('/quizzes.php') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'award_quiz_xp=1&xp=' + rewardXp
                    }).catch(e => console.log(e));
                }

                box.innerHTML = `
                    <div class="text-center py-8 space-y-4">
                        <div class="w-20 h-20 rounded-2xl ${percent >= 70 ? 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400' : 'bg-amber-500/10 border border-amber-500/30 text-amber-400'} flex items-center justify-center mx-auto text-4xl shadow-xl">
                            ${percent >= 70 ? '🏆' : '🛫'}
                        </div>
                        <h2 class="text-2xl font-bold text-white">${percent >= 70 ? 'Экзамен успешно сдан!' : 'Тест завершен'}</h2>
                        <p class="text-sm text-slate-300 font-sans">
                            Правильных ответов: <strong class="text-sky-400 font-mono text-base">${score} из ${questions.length}</strong> (${percent}%)
                        </p>
                        ${percent >= 70 ? `<div class="p-3 bg-emerald-950/60 border border-emerald-500/30 rounded-xl text-emerald-300 font-mono text-xs font-bold inline-block">+${rewardXp} XP начислено в ваш летный профиль!</div>` : '<div class="text-xs text-slate-400 font-sans">Для начисления XP необходимо ответить правильно минимум на 70% вопросов. Попробуйте еще раз!</div>'}
                        <div class="pt-6 flex items-center justify-center space-x-4">
                            <button onclick="currentIdx=0;score=0;renderQuestion();" class="bg-slate-800 hover:bg-slate-700 text-white font-mono font-bold text-xs px-5 py-3 rounded-xl border border-slate-700 transition">
                                Пройти заново
                            </button>
                            <a href="<?= url('/quizzes.php') ?>" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-6 py-3 rounded-xl transition">
                                Все викторины
                            </a>
                        </div>
                    </div>`;
                return;
            }

            const q = questions[currentIdx];
            let optsHtml = '';
            q.options.forEach((opt, idx) => {
                optsHtml += `
                    <button onclick="checkAnswer(${idx})" class="quiz-opt-btn w-full p-4 rounded-xl bg-slate-950 hover:bg-slate-900 border border-slate-800 hover:border-sky-500/50 text-left text-xs font-mono text-slate-200 transition flex items-start space-x-3">
                        <span class="w-6 h-6 rounded bg-slate-900 border border-slate-700 flex items-center justify-center font-bold text-sky-400 flex-shrink-0">${String.fromCharCode(65 + idx)}</span>
                        <span class="pt-0.5 leading-relaxed">${opt.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</span>
                    </button>`;
            });

            box.innerHTML = `
                <div class="space-y-4 font-mono">
                    <div class="flex items-center justify-between text-xs text-slate-400 pb-2 border-b border-slate-800">
                        <span class="flex items-center space-x-1.5 text-sky-400">
                            <i data-lucide="help-circle" class="w-4 h-4"></i>
                            <span>Вопрос ${currentIdx + 1} из ${questions.length}</span>
                        </span>
                        <span class="px-2 py-0.5 bg-slate-950 rounded text-slate-300 font-bold">Счет: ${score}</span>
                    </div>

                    <h3 class="text-base font-bold text-white leading-relaxed font-sans">${q.question.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</h3>

                    <div id="options-grid" class="grid grid-cols-1 gap-3 pt-2">
                        ${optsHtml}
                    </div>

                    <div id="explanation-box" class="hidden p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-300 space-y-2 animate-in fade-in">
                        <div id="expl-verdict" class="font-bold font-mono"></div>
                        <div id="expl-text" class="font-sans leading-relaxed text-slate-400">${q.explanation || ''}</div>
                        <button onclick="nextQuestion()" class="mt-2 bg-sky-600 hover:bg-sky-500 text-white px-5 py-2.5 rounded-xl font-bold font-mono transition">
                            Следующий вопрос →
                        </button>
                    </div>
                </div>`;
            lucide.createIcons();
        }

        function checkAnswer(chosenIdx) {
            const q = questions[currentIdx];
            const isCorrect = (chosenIdx === q.correct_index);
            if (isCorrect) score++;

            const btns = document.querySelectorAll('.quiz-opt-btn');
            btns.forEach((btn, idx) => {
                btn.disabled = true;
                if (idx === q.correct_index) {
                    btn.className = 'quiz-opt-btn w-full p-4 rounded-xl bg-emerald-950/70 border-2 border-emerald-500 text-left text-xs font-mono text-emerald-200 font-bold flex items-start space-x-3';
                } else if (idx === chosenIdx && !isCorrect) {
                    btn.className = 'quiz-opt-btn w-full p-4 rounded-xl bg-red-950/70 border-2 border-red-500 text-left text-xs font-mono text-red-300 flex items-start space-x-3';
                }
            });

            const expl = document.getElementById('explanation-box');
            const verdict = document.getElementById('expl-verdict');
            if (expl) {
                expl.classList.remove('hidden');
                verdict.className = isCorrect ? 'text-emerald-400 font-bold' : 'text-red-400 font-bold';
                verdict.innerText = isCorrect ? '✓ Абсолютно верно!' : '✗ Неверный ответ';
            }
        }

        function nextQuestion() {
            currentIdx++;
            renderQuestion();
        }

        window.addEventListener('DOMContentLoaded', renderQuestion);
    </script>

    <?php
} else {
    // Quizzes Catalog
    $quizzes = Database::isConfigured() ? Database::fetchAll("SELECT q.*, (SELECT COUNT(*) FROM `{$qTable}` WHERE quiz_id = q.id) as q_count FROM `{$quizTable}` q ORDER BY q.id ASC") : [];
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="va-card p-6 sm:p-8 mb-8">
            <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3 mb-2">
                <i data-lucide="award" class="w-8 h-8 text-amber-400"></i>
                <span>Авиационные Викторины и Квалификационные Тесты</span>
            </h1>
            <p class="text-xs text-slate-400 font-mono">
                Проверьте свои знания аэродинамики, метеорологии METAR, радионавигации и систем воздушных судов. Зарабатывайте XP и повышайте летный ранг!
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($quizzes as $qz): ?>
                <a href="?id=<?= $qz['id'] ?>" class="va-card p-6 block group hover:border-sky-500/50 transition">
                    <div class="flex items-center justify-between mb-3 font-mono">
                        <span class="px-2 py-0.5 rounded text-[10px] bg-sky-950 text-sky-400 border border-sky-800 uppercase font-bold">
                            <?= e($qz['difficulty']) ?>
                        </span>
                        <span class="text-amber-400 text-xs font-bold font-mono">+<?= $qz['reward_xp'] ?? 150 ?> XP</span>
                    </div>
                    <h3 class="text-lg font-bold text-white group-hover:text-sky-400 transition mb-2"><?= e($qz['title']) ?></h3>
                    <p class="text-xs text-slate-400 leading-relaxed mb-6 line-clamp-2"><?= e($qz['description']) ?></p>
                    <div class="flex items-center justify-between text-xs font-mono font-bold text-sky-400 pt-3 border-t border-slate-800/80">
                        <span class="text-slate-400 text-[11px]"><?= (int)($qz['q_count'] ?? 0) ?> вопросов</span>
                        <span class="flex items-center space-x-1">
                            <span>НАЧАТЬ ТЕСТ</span>
                            <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition"></i>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
}
require_once __DIR__ . '/includes/footer.php';
?>
