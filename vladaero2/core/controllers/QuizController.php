<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * QuizController - Викторины и тесты
 */
class QuizController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();

        $quizzes = $this->db->fetchAll(
            "SELECT q.*, u.username as author_name,
                    (SELECT COUNT(*) FROM {$prefix}quiz_results WHERE quiz_id = q.id) as attempts
             FROM {$prefix}quizzes q LEFT JOIN {$prefix}users u ON q.author_id = u.id
             WHERE q.status = 'published'
             ORDER BY q.created_at DESC"
        );

        $categories = $this->db->fetchAll(
            "SELECT name, slug FROM {$prefix}quiz_categories ORDER BY name"
        );

        $this->view('pages.quizzes.index', compact('quizzes', 'categories'));
    }

    public function show(string $slug)
    {
        $prefix = $this->db->prefix();
        $quiz = $this->db->fetchOne(
            "SELECT q.*, u.username as author_name FROM {$prefix}quizzes q
             LEFT JOIN {$prefix}users u ON q.author_id = u.id
             WHERE q.slug = :slug AND q.status = 'published'",
            ['slug' => $slug]
        );
        if (!$quiz) $this->abort(404);

        $questions = $this->db->fetchAll(
            "SELECT * FROM {$prefix}quiz_questions WHERE quiz_id = :qid ORDER BY sort_order",
            ['qid' => $quiz['id']]
        );

        $this->view('pages.quizzes.show', compact('quiz', 'questions'));
    }

    public function submit(string $slug)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect("/quizzes/{$slug}");
        }

        $prefix = $this->db->prefix();
        $quiz = $this->db->fetchOne(
            "SELECT * FROM {$prefix}quizzes WHERE slug = :slug", ['slug' => $slug]
        );
        if (!$quiz) $this->abort(404);

        $questions = $this->db->fetchAll(
            "SELECT * FROM {$prefix}quiz_questions WHERE quiz_id = :qid ORDER BY sort_order",
            ['qid' => $quiz['id']]
        );

        $correct = 0;
        $total = count($questions);
        $answers = [];

        foreach ($questions as $q) {
            $userAnswer = $_POST["q_{$q['id']}"] ?? '';
            $isCorrect = ($userAnswer === $q['correct_answer']);
            if ($isCorrect) $correct++;
            $answers[] = [
                'question_id' => $q['id'],
                'user_answer' => $userAnswer,
                'correct_answer' => $q['correct_answer'],
                'is_correct' => $isCorrect,
            ];
        }

        $score = $total > 0 ? round(($correct / $total) * 100) : 0;

        if ($user = Session::getAuth()) {
            $this->db->query(
                "INSERT INTO {$prefix}quiz_results (quiz_id, user_id, score, total, correct_answers, answers_json)
                 VALUES (:qid, :uid, :score, :total, :correct, :answers)",
                [
                    'qid' => $quiz['id'], 'uid' => $user['id'],
                    'score' => $score, 'total' => $total,
                    'correct' => $correct, 'answers' => json_encode($answers),
                ]
            );
        }

        $this->view('pages.quizzes.result', compact('quiz', 'score', 'correct', 'total', 'answers', 'questions'));
    }
}
