<?php
/**
 * Admin Lessons Management, Full CRUD & Visual AI Generator
 */

$adminTitle = 'Управление уроками';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_lessons')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_lesson') {
        $lid = (int)($_POST['lesson_id'] ?? 0);
        $skillId = (int)$_POST['skill_id'];
        $title = trim($_POST['title']);
        $xp = (int)$_POST['xp_reward'];
        $order = (int)$_POST['order_num'];
        $data = trim($_POST['lesson_data']);

        // Validate JSON
        $testJson = json_decode($data, true);
        if (!is_array($testJson)) {
            $error = 'Ошибка: Данные урока должны быть валидным JSON-массивом упражнений!';
        } else {
            if ($lid > 0) {
                $stmt = $db->prepare("UPDATE " . tbl('lessons') . " SET skill_id = :sid, title = :t, xp_reward = :xp, order_num = :ord, lesson_data = :d WHERE id = :id");
                $stmt->execute(['sid' => $skillId, 't' => $title, 'xp' => $xp, 'ord' => $order, 'd' => $data, 'id' => $lid]);
                $message = 'Урок успешно обновлен!';
            } else {
                $stmt = $db->prepare("INSERT INTO " . tbl('lessons') . " (skill_id, title, xp_reward, order_num, lesson_data) VALUES (:sid, :t, :xp, :ord, :d)");
                $stmt->execute(['sid' => $skillId, 't' => $title, 'xp' => $xp, 'ord' => $order, 'd' => $data]);
                $message = 'Новый урок успешно создан!';
            }
        }
    }

    if ($act === 'delete_lesson') {
        $lid = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM " . tbl('lessons') . " WHERE id = :id");
        $stmt->execute(['id' => $lid]);
        $message = 'Урок успешно удален.';
    }
}

$skills = $db->query("SELECT s.*, COALESCE(l.flag, '🌐') as lang_flag, COALESCE(l.name, s.language_code) as lang_name FROM " . tbl('skills') . " s LEFT JOIN " . tbl('languages') . " l ON s.language_code = l.code ORDER BY s.language_code ASC, s.order_num ASC")->fetchAll();
$lessons = $db->query("SELECT l.*, COALESCE(s.title, 'Без раздела') as skill_title, COALESCE(s.language_code, 'vladikish') as language_code, COALESCE(lang.flag, '🌐') as lang_flag, (SELECT COUNT(*) FROM " . tbl('user_progress') . " WHERE lesson_id = l.id) as completions_count 
                       FROM " . tbl('lessons') . " l 
                       LEFT JOIN " . tbl('skills') . " s ON l.skill_id = s.id 
                       LEFT JOIN " . tbl('languages') . " lang ON s.language_code = lang.code 
                       ORDER BY s.language_code ASC, s.order_num ASC, l.order_num ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">📚 Управление уроками (CRUD & AI Generator)</h1>
        <p style="color: var(--text-muted);">Создание, редактирование, удаление и ИИ-генерация уроков через NVIDIA NIM</p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openAiGeneratorModal()">
            🤖 Сгенерировать через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openLessonModal()">
            + Создать урок вручную
        </button>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="background: var(--danger-light); color: var(--danger-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✕ <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="card-duo">
    <table class="dict-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Язык / Раздел</th>
                <th>Название урока</th>
                <th>Награда XP</th>
                <th>Упражнений</th>
                <th>Прохождений</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($lessons)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 32px; color: var(--text-muted);">
                        Уроков пока нет. Нажмите «Сгенерировать через AI» или «Создать урок вручную»!
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($lessons as $les): 
                $dataArr = json_decode($les['lesson_data'], true) ?: [];
                $qCount = count($dataArr);
            ?>
                <tr>
                    <td>#<?= $les['id'] ?></td>
                    <td><?= $les['lang_flag'] ?> <strong><?= e($les['skill_title']) ?></strong></td>
                    <td style="font-weight: 800; font-size: 1.05rem;"><?= e($les['title']) ?></td>
                    <td style="color: #eab308; font-weight: 800;">⚡ +<?= (int)$les['xp_reward'] ?> XP</td>
                    <td><span class="badge-tag"><?= $qCount ?> заданий</span></td>
                    <td><span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);"><?= $les['completions_count'] ?> раз</span></td>
                    <td style="display: flex; gap: 6px;">
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='previewLesson(<?= json_encode($les, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Интерактивный предпросмотр">
                            👁️ Превью
                        </button>
                        <a href="../lesson.php?id=<?= $les['id'] ?>" target="_blank" class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" title="Открыть как ученик">
                            ▶️ Тест
                        </a>
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editLesson(<?= json_encode($les) ?>)' title="Редактировать">
                            ✏️
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Вы действительно хотите удалить этот урок?');">
                            <input type="hidden" name="form_action" value="delete_lesson">
                            <input type="hidden" name="delete_id" value="<?= $les['id'] ?>">
                            <button type="submit" class="btn-duo" style="padding: 4px 8px; font-size: 0.8rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);" title="Удалить урок">
                                🗑️
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Lesson Editor -->
<div id="modal-lesson-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 750px; width: 100%; margin-bottom: 0; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-lesson-title" style="font-size: 1.3rem; font-weight: 800;">Редактор урока</h3>
            <button onclick="closeLessonModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_lesson">
            <input type="hidden" name="lesson_id" id="les-id" value="0">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Раздел навыков (Skill):</label>
                    <select name="skill_id" id="les-skill" class="chat-input">
                        <?php foreach ($skills as $sk): ?>
                            <option value="<?= $sk['id'] ?>"><?= $sk['lang_flag'] ?> <?= e($sk['title']) ?> (<?= e($sk['lang_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Награда XP:</label>
                    <input type="number" name="xp_reward" id="les-xp" value="15" class="chat-input">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Название урока:</label>
                    <input type="text" name="title" id="les-title" required class="chat-input" placeholder="Урок 1: Базовые фразы">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Порядок:</label>
                    <input type="number" name="order_num" id="les-order" value="1" class="chat-input">
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; font-size: 0.85rem;">JSON структура упражнений урока:</label>
                    <button type="button" class="badge-tag" style="background: var(--secondary); color: white; border: none; cursor: pointer;" onclick="insertSampleJson()">
                        + Вставить шаблон
                    </button>
                </div>
                <textarea name="lesson_data" id="les-data" rows="12" class="chat-input" style="font-family: monospace; font-size: 0.85rem; line-height: 1.4;" required></textarea>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить урок 💾
            </button>
        </form>
    </div>
</div>

<!-- Modal: AI Lesson Generator -->
<div id="modal-ai-generator" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 550px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <span>🤖</span> Генератор уроков через NVIDIA AI
            </h3>
            <button onclick="closeAiGeneratorModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Выберите раздел и язык:</label>
            <select id="ai-skill-id" class="chat-input">
                <?php foreach ($skills as $sk): ?>
                    <option value="<?= $sk['id'] ?>" data-lang="<?= e($sk['language_code']) ?>">
                        <?= $sk['lang_flag'] ?> <?= e($sk['title']) ?> (<?= e($sk['lang_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема урока для нейросети:</label>
            <input type="text" id="ai-lesson-topic" class="chat-input" placeholder="Например: Знакомство в аэропорту, Числа и время, В ресторане...">
        </div>

        <div id="ai-gen-status" style="margin-bottom: 16px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-gen" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiLessonGeneration()">
            Сгенерировать и создать урок ✨
        </button>
    </div>
</div>

<script>
function openLessonModal() {
    document.getElementById('modal-lesson-title').textContent = 'Создание нового урока';
    document.getElementById('les-id').value = '0';
    document.getElementById('les-title').value = '';
    document.getElementById('les-xp').value = '15';
    document.getElementById('les-order').value = '1';
    insertSampleJson();
    document.getElementById('modal-lesson-edit').style.display = 'flex';
}

function editLesson(les) {
    document.getElementById('modal-lesson-title').textContent = `Редактирование: ${les.title}`;
    document.getElementById('les-id').value = les.id;
    document.getElementById('les-skill').value = les.skill_id;
    document.getElementById('les-title').value = les.title;
    document.getElementById('les-xp').value = les.xp_reward;
    document.getElementById('les-order').value = les.order_num;
    
    try {
        const parsed = JSON.parse(les.lesson_data);
        document.getElementById('les-data').value = JSON.stringify(parsed, null, 2);
    } catch(e) {
        document.getElementById('les-data').value = les.lesson_data;
    }

    document.getElementById('modal-lesson-edit').style.display = 'flex';
}

function closeLessonModal() {
    document.getElementById('modal-lesson-edit').style.display = 'none';
}

function openAiGeneratorModal() {
    document.getElementById('modal-ai-generator').style.display = 'flex';
}

function closeAiGeneratorModal() {
    document.getElementById('modal-ai-generator').style.display = 'none';
}

async function runAiLessonGeneration() {
    const topic = document.getElementById('ai-lesson-topic').value.trim();
    const skillSelect = document.getElementById('ai-skill-id');
    const skillId = skillSelect.value;
    const langCode = skillSelect.options[skillSelect.selectedIndex].getAttribute('data-lang') || 'vladikish';
    const statusDiv = document.getElementById('ai-gen-status');
    const btn = document.getElementById('btn-run-ai-gen');

    if (!topic) {
        alert('Пожалуйста, укажите тему урока!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = `<span style="color: var(--secondary); font-weight: 700;">🤖 NVIDIA AI генерирует упражнения по теме «${topic}»...</span>`;

    try {
        const res = await fetch('../api/ai.php?action=generate_lesson', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                topic: topic,
                lang: langCode,
                skill_id: parseInt(skillId)
            })
        });

        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = `<span style="color: var(--primary); font-weight: 800;">🎉 Урок успешно создан! Перезагрузка страницы...</span>`;
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            statusDiv.innerHTML = `<span style="color: var(--danger); font-weight: 700;">Ошибка: ${data.error}</span>`;
            btn.disabled = false;
        }
    } catch (e) {
        statusDiv.innerHTML = `<span style="color: var(--danger);">Ошибка запроса к серверу.</span>`;
        btn.disabled = false;
    }
}

function insertSampleJson() {
    const sample = [
        {
            "type": "multiple_choice",
            "question": "Выберите правильный перевод слова «Mira»:",
            "prompt": "Mira",
            "options": ["Привет / Мир", "Собака", "Ночь", "Спасибо"],
            "correct": 0,
            "explanation": "Mira = Привет на Vladikish."
        },
        {
            "type": "word_bank",
            "question": "Соберите фразу: «Привет, друг!»",
            "prompt": "Привет, друг!",
            "correct_sequence": ["Mira,", "Vladi!"],
            "word_pool": ["Mira,", "Vladi!", "Barka", "Aero", "Nox"],
            "explanation": "Mira = Привет, Vladi = Друг."
        }
    ];
    document.getElementById('les-data').value = JSON.stringify(sample, null, 2);
}

function previewLesson(les) {
    document.getElementById('prev-title').textContent = les.title;
    const body = document.getElementById('prev-body');
    body.innerHTML = '';

    let questions = [];
    try {
        questions = JSON.parse(les.lesson_data);
    } catch(e) {
        body.innerHTML = '<div style="color: var(--danger);">Ошибка парсинга JSON данных урока.</div>';
        document.getElementById('modal-lesson-preview').style.display = 'flex';
        return;
    }

    if (!Array.isArray(questions) || questions.length === 0) {
        body.innerHTML = '<div style="color: var(--text-muted);">В этом уроке нет вопросов.</div>';
    } else {
        questions.forEach((q, idx) => {
            const card = document.createElement('div');
            card.style = 'background: var(--bg-main); border: 2px solid var(--border-color); border-radius: 14px; padding: 16px; margin-bottom: 14px;';
            
            let innerHtml = `<div style="font-weight: 800; font-size: 1rem; margin-bottom: 6px;">Вопрос ${idx + 1}: ${q.question}</div>`;
            if (q.prompt) {
                innerHtml += `<div style="background: var(--bg-card); padding: 8px 12px; border-radius: 8px; margin-bottom: 10px; font-weight: 700; color: var(--secondary);">🔊 ${q.prompt}</div>`;
            }

            if (q.type === 'multiple_choice' && Array.isArray(q.options)) {
                innerHtml += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">';
                q.options.forEach((opt, oIdx) => {
                    const isCorrect = (oIdx === q.correct);
                    innerHtml += `<div style="padding: 8px 12px; border-radius: 10px; border: 2px solid ${isCorrect ? 'var(--primary)' : 'var(--border-color)'}; background: ${isCorrect ? 'var(--primary-light)' : 'var(--bg-card)'}; font-weight: 700;">${isCorrect ? '✓ ' : ''}${opt}</div>`;
                });
                innerHtml += '</div>';
            } else if (q.type === 'word_bank' && Array.isArray(q.word_pool)) {
                innerHtml += '<div style="display: flex; gap: 6px; flex-wrap: wrap;">';
                q.word_pool.forEach(w => {
                    innerHtml += `<span class="badge-tag" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 6px 12px; font-weight: 700;">${w}</span>`;
                });
                innerHtml += '</div>';
            } else if (q.type === 'translate') {
                innerHtml += `<div style="font-style: italic; color: var(--text-muted);">Перевод в свободное поле ввода (Ответ: ${q.correct_answers ? q.correct_answers.join(', ') : '...'})</div>`;
            }

            if (q.explanation) {
                innerHtml += `<div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px; border-top: 1px dashed var(--border-color); padding-top: 6px;">💡 Пояснение: ${q.explanation}</div>`;
            }

            card.innerHTML = innerHtml;
            body.appendChild(card);
        });
    }

    document.getElementById('modal-lesson-preview').style.display = 'flex';
}
</script>

<!-- Modal: Interactive Lesson Visual Preview -->
<div id="modal-lesson-preview" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 650px; width: 100%; max-height: 85vh; overflow-y: auto; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <span>👁️</span> <span id="prev-title">Превью урока</span>
            </h3>
            <button onclick="document.getElementById('modal-lesson-preview').style.display='none'" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div id="prev-body"></div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
