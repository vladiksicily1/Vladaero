<?php
/**
 * ShibaLingo - Interactive Lesson Player (Duolingo Mechanics)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$lessonId = (int)($_GET['id'] ?? 1);
$db = getDb();
$user = getCurrentUser();

$stmt = $db->prepare("
    SELECT l.*, s.language_code, s.title as skill_title, s.level as skill_level, s.order_num as skill_order 
    FROM " . tbl('lessons') . " l 
    JOIN " . tbl('skills') . " s ON l.skill_id = s.id 
    WHERE l.id = :id
");
$stmt->execute(['id' => $lessonId]);
$lesson = $stmt->fetch();

if (!$lesson) {
    header("Location: index.php");
    exit;
}

// 1. Next lesson in SAME skill
$nextInSkillStmt = $db->prepare("
    SELECT id, title, xp_reward 
    FROM " . tbl('lessons') . " 
    WHERE skill_id = :sid AND order_num > :ord 
    ORDER BY order_num ASC 
    LIMIT 1
");
$nextInSkillStmt->execute(['sid' => $lesson['skill_id'], 'ord' => $lesson['order_num']]);
$nextLessonRow = $nextInSkillStmt->fetch(PDO::FETCH_ASSOC);

$nextLessonData = null;
if ($nextLessonRow) {
    $nextLessonData = [
        'id' => (int)$nextLessonRow['id'],
        'title' => $nextLessonRow['title'],
        'is_same_skill' => true,
        'skill_title' => $lesson['skill_title']
    ];
} else {
    // 2. Next lesson in the NEXT skill in this language
    $nextSkillLessonStmt = $db->prepare("
        SELECT l.id, l.title, s.title as skill_title 
        FROM " . tbl('lessons') . " l 
        JOIN " . tbl('skills') . " s ON l.skill_id = s.id 
        WHERE s.language_code = :lang 
          AND (s.level > :lvl OR (s.level = :lvl AND s.order_num > :s_ord))
        ORDER BY s.level ASC, s.order_num ASC, l.order_num ASC 
        LIMIT 1
    ");
    $nextSkillLessonStmt->execute([
        'lang' => $lesson['language_code'],
        'lvl' => (int)$lesson['skill_level'],
        's_ord' => (int)$lesson['skill_order']
    ]);
    $nextSkillRow = $nextSkillLessonStmt->fetch(PDO::FETCH_ASSOC);
    if ($nextSkillRow) {
        $nextLessonData = [
            'id' => (int)$nextSkillRow['id'],
            'title' => $nextSkillRow['title'],
            'is_same_skill' => false,
            'skill_title' => $nextSkillRow['skill_title']
        ];
    }
}

$lessonDataJson = $lesson['lesson_data'];
$pageTitle = $lesson['title'];
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($lesson['title']) ?> — ShibaLingo</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/animations.css">
</head>
<body style="background: var(--bg-card); display: block;">

<div class="lesson-container">
    <!-- Header with Progress and Hearts -->
    <div class="lesson-header">
        <a href="index.php" class="close-lesson-btn" title="Выйти из урока">✕</a>
        <div class="progress-bar-duo">
            <div class="progress-bar-fill" id="lesson-progress-fill" style="width: 0%;"></div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <button type="button" class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.9rem; border-radius: 12px;" onclick="openHotkeyModal()" title="Горячие клавиши">
                ⌨️
            </button>
            <div class="stat-badge hearts" id="lesson-hearts-badge">
                ❤️ <?= (int)$user['hearts'] ?>
            </div>
        </div>
    </div>

    <!-- Modal: Hotkeys Cheat Sheet -->
    <div id="modal-hotkeys" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
        <div class="card-duo anim-bounce" style="max-width: 440px; width: 100%; margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="font-size: 1.3rem; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                    <span>⌨️</span> <span>Горячие клавиши</span>
                </h3>
                <button onclick="closeHotkeyModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
            </div>

            <div style="display: flex; flex-direction: column; gap: 12px; font-size: 0.95rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Выбор вариантов ответа:</span>
                    <span style="background: var(--bg-main); padding: 4px 10px; border-radius: 8px; font-weight: 800; font-family: monospace;">1, 2, 3, 4</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Проверить / Продолжить:</span>
                    <span style="background: var(--bg-main); padding: 4px 10px; border-radius: 8px; font-weight: 800; font-family: monospace;">Enter ↵</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span>Повторить озвучку фразы:</span>
                    <span style="background: var(--bg-main); padding: 4px 10px; border-radius: 8px; font-weight: 800; font-family: monospace;">Space (Пробел)</span>
                </div>
            </div>

            <button type="button" class="btn-duo btn-primary" style="width: 100%; margin-top: 20px;" onclick="closeHotkeyModal()">
                Понятно! 🐾
            </button>
        </div>
    </div>

    <script>
    function openHotkeyModal() { document.getElementById('modal-hotkeys').style.display = 'flex'; }
    function closeHotkeyModal() { document.getElementById('modal-hotkeys').style.display = 'none'; }
    </script>

    <!-- Question Stage -->
    <div style="display: flex; gap: 24px; align-items: flex-start;">
        <div id="lesson-shiba-mascot" style="flex-shrink: 0;"></div>
        <div id="question-stage" style="flex-grow: 1;">
            <!-- Rendered by lesson.js -->
        </div>
    </div>

    <!-- Footer Action Bar -->
    <div class="lesson-footer">
        <a href="index.php" class="btn-duo btn-outline" style="border-radius: 12px; padding: 10px 16px;">
            Пропустить
        </a>
        <div id="lesson-footer-action">
            <!-- Dynamic button inserted by lesson.js -->
        </div>
    </div>
</div>

<!-- Sliding Feedback Banner -->
<div class="feedback-banner" id="feedback-banner">
    <div style="max-width: 600px;">
        <h3 id="feedback-title" style="font-size: 1.4rem; font-weight: 900; margin-bottom: 4px;"></h3>
        <p id="feedback-desc" style="font-weight: 600; font-size: 1rem;"></p>
    </div>
    <button class="btn-duo btn-primary" id="btn-next-step" style="width: 180px;">
        Продолжить
    </button>
</div>

<script src="assets/js/mascot.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/vladikish-voice.js"></script>
<script src="assets/js/lesson.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const rawData = <?= $lessonDataJson ?>;
    const nextLessonData = <?= json_encode($nextLessonData, JSON_UNESCAPED_UNICODE) ?>;
    const lessonEngine = new LessonEngine(
        rawData,
        <?= $lesson['id'] ?>,
        <?= (int)$lesson['xp_reward'] ?>,
        '<?= $lesson['language_code'] ?>',
        nextLessonData
    );
});
</script>

</body>
</html>
