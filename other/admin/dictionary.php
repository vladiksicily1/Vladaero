<?php
/**
 * Admin Conlang Dictionary CRUD
 */

$adminTitle = 'Словарь Conlang';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_conlang')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_word') {
        $wid = (int)($_POST['word_id'] ?? 0);
        $langCode = trim($_POST['language_code'] ?? 'vladikish');
        $word = trim($_POST['word']);
        $pos = trim($_POST['part_of_speech']);
        $ru = trim($_POST['translation_ru']);
        $en = trim($_POST['translation_en']);
        $it = trim($_POST['translation_it']);
        $pron = trim($_POST['pronunciation']);
        $ex = trim($_POST['example_sentence']);

        if ($wid > 0) {
            $stmt = $db->prepare("UPDATE conlang_dictionary SET language_code = :l, word = :w, part_of_speech = :pos, translation_ru = :ru, translation_en = :en, translation_it = :it, pronunciation = :pron, example_sentence = :ex WHERE id = :id");
            $stmt->execute(['l' => $langCode, 'w' => $word, 'pos' => $pos, 'ru' => $ru, 'en' => $en, 'it' => $it, 'pron' => $pron, 'ex' => $ex, 'id' => $wid]);
            $message = 'Слово успешно обновлено!';
        } else {
            $stmt = $db->prepare("INSERT INTO conlang_dictionary (language_code, word, part_of_speech, translation_ru, translation_en, translation_it, pronunciation, example_sentence, created_by) VALUES (:l, :w, :pos, :ru, :en, :it, :pron, :ex, 'admin')");
            $stmt->execute(['l' => $langCode, 'w' => $word, 'pos' => $pos, 'ru' => $ru, 'en' => $en, 'it' => $it, 'pron' => $pron, 'ex' => $ex]);
            $message = 'Новое слово добавлено в словарь!';
        }
    }

    if ($act === 'delete_word') {
        $delId = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM conlang_dictionary WHERE id = :id");
        $stmt->execute(['id' => $delId]);
        $message = 'Слово удалено.';
    }
}

$words = $db->query("SELECT * FROM conlang_dictionary ORDER BY word ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">📖 Словарь вымышленных языков (Conlang CRUD & AI)</h1>
        <p style="color: var(--text-muted);">Управление базой слов Vladikish, транскрипциями и AI-генерация новых слов</p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openAiDictModal()">
            🤖 Сгенерировать слова через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openWordModal()">
            + Добавить слово
        </button>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<div class="card-duo">
    <table class="dict-table">
        <thead>
            <tr>
                <th>Слово</th>
                <th>Часть речи</th>
                <th>Перевод (RU)</th>
                <th>Перевод (EN)</th>
                <th>Произношение</th>
                <th>Пример</th>
                <th>Источник</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($words as $w): ?>
                <tr>
                    <td style="font-weight: 800; font-size: 1.1rem; color: var(--primary-shadow);"><?= e($w['word']) ?></td>
                    <td><span class="badge-tag"><?= e($w['part_of_speech']) ?></span></td>
                    <td style="font-weight: 700;"><?= e($w['translation_ru']) ?></td>
                    <td style="color: var(--text-muted);"><?= e($w['translation_en']) ?></td>
                    <td><em>[<?= e($w['pronunciation'] ?? $w['word']) ?>]</em></td>
                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?= e($w['example_sentence']) ?></td>
                    <td><span class="badge-tag"><?= e($w['created_by']) ?></span></td>
                    <td>
                        <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editWord(<?= json_encode($w) ?>)'>
                            ✏️
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal: Word Editor -->
<div id="modal-word-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 550px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-word-title" style="font-size: 1.3rem; font-weight: 800;">Редактор слова</h3>
            <button onclick="closeWordModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_word">
            <input type="hidden" name="word_id" id="w-id" value="0">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Слово:</label>
                    <input type="text" name="word" id="w-word" required class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Часть речи:</label>
                    <select name="part_of_speech" id="w-pos" class="chat-input">
                        <option value="noun">noun (сущ.)</option>
                        <option value="verb">verb (глагол)</option>
                        <option value="adjective">adjective (прил.)</option>
                        <option value="greeting">greeting (приветствие)</option>
                        <option value="phrase">phrase (фраза)</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Перевод (RU):</label>
                    <input type="text" name="translation_ru" id="w-ru" required class="chat-input">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Перевод (EN):</label>
                    <input type="text" name="translation_en" id="w-en" class="chat-input">
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Произношение / ударение:</label>
                <input type="text" name="pronunciation" id="w-pron" class="chat-input">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Пример использования в фразе:</label>
                <input type="text" name="example_sentence" id="w-ex" class="chat-input">
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить слово 💾
            </button>
        </form>
    </div>
</div>

<script>
function openWordModal() {
    document.getElementById('modal-word-title').textContent = 'Добавление нового слова';
    document.getElementById('w-id').value = '0';
    document.getElementById('w-word').value = '';
    document.getElementById('w-ru').value = '';
    document.getElementById('w-en').value = '';
    document.getElementById('w-pron').value = '';
    document.getElementById('w-ex').value = '';
    document.getElementById('modal-word-edit').style.display = 'flex';
}

function editWord(w) {
    document.getElementById('modal-word-title').textContent = `Редактирование: ${w.word}`;
    document.getElementById('w-id').value = w.id;
    document.getElementById('w-word').value = w.word;
    document.getElementById('w-pos').value = w.part_of_speech || 'noun';
    document.getElementById('w-ru').value = w.translation_ru;
    document.getElementById('w-en').value = w.translation_en || '';
    document.getElementById('w-pron').value = w.pronunciation || '';
    document.getElementById('w-ex').value = w.example_sentence || '';
    document.getElementById('modal-word-edit').style.display = 'flex';
}

function closeWordModal() {
    document.getElementById('modal-word-edit').style.display = 'none';
}

function openAiDictModal() {
    document.getElementById('modal-ai-dict').style.display = 'flex';
}

function closeAiDictModal() {
    document.getElementById('modal-ai-dict').style.display = 'none';
}

async function runAiDictGeneration() {
    const topic = document.getElementById('ai-dict-topic').value.trim();
    const count = parseInt(document.getElementById('ai-dict-count').value);
    const btn = document.getElementById('btn-run-ai-dict');
    const statusDiv = document.getElementById('ai-dict-status');

    if (!topic) {
        alert('Укажите тему для генерации слов!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ создает слова, транскрипции и примеры...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_words', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ topic, count })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = `<span style="color: var(--primary); font-weight: 800;">🎉 Добавлено ${data.added_count} новых слов! Обновление...</span>`;
            setTimeout(() => location.reload(), 1000);
        } else {
            statusDiv.innerHTML = `<span style="color: var(--danger);">Ошибка: ${data.error}</span>`;
            btn.disabled = false;
        }
    } catch (e) {
        statusDiv.innerHTML = '<span style="color: var(--danger);">Ошибка сети.</span>';
        btn.disabled = false;
    }
}
</script>

<!-- Modal: AI Dictionary Generator -->
<div id="modal-ai-dict" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 AI Генератор слов Vladikish</h3>
            <button onclick="closeAiDictModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема слов:</label>
            <input type="text" id="ai-dict-topic" class="chat-input" placeholder="Например: Архитектура, Чувства, Кулинария, Космос...">
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Количество слов:</label>
            <select id="ai-dict-count" class="chat-input">
                <option value="5">5 слов</option>
                <option value="10" selected>10 слов</option>
                <option value="20">20 слов</option>
            </select>
        </div>

        <div id="ai-dict-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-dict" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiDictGeneration()">
            Сгенерировать и добавить в словарь ✨
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
