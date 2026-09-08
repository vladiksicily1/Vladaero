<?php
/**
 * Admin Conlang Grammar Rules CRUD
 */

$adminTitle = 'Грамматика Conlang';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_conlang')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_grammar') {
        $gid = (int)($_POST['grammar_id'] ?? 0);
        $title = trim($_POST['rule_title']);
        $desc = trim($_POST['rule_description']);
        $ex = trim($_POST['rule_examples']);
        $order = (int)$_POST['order_num'];

        if ($gid > 0) {
            $stmt = $db->prepare("UPDATE conlang_grammar SET rule_title = :t, rule_description = :d, rule_examples = :e, order_num = :ord WHERE id = :id");
            $stmt->execute(['t' => $title, 'd' => $desc, 'e' => $ex, 'ord' => $order, 'id' => $gid]);
            $message = 'Правило грамматики успешно обновлено!';
        } else {
            $stmt = $db->prepare("INSERT INTO conlang_grammar (rule_title, rule_description, rule_examples, order_num) VALUES (:t, :d, :e, :ord)");
            $stmt->execute(['t' => $title, 'd' => $desc, 'e' => $ex, 'ord' => $order]);
            $message = 'Новое правило добавлено!';
        }
    }

    if ($act === 'delete_grammar') {
        $delId = (int)$_POST['delete_id'];
        $stmt = $db->prepare("DELETE FROM conlang_grammar WHERE id = :id");
        $stmt->execute(['id' => $delId]);
        $message = 'Правило удалено.';
    }
}

$rules = $db->query("SELECT * FROM conlang_grammar ORDER BY order_num ASC, id ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">📐 Правила грамматики Vladikish (CRUD & AI)</h1>
        <p style="color: var(--text-muted);">Правила, которые AI и студенты используют для построения фраз</p>
    </div>

    <div style="display: flex; gap: 10px;">
        <button class="btn-duo btn-secondary" onclick="openAiGramModal()">
            🤖 Сгенерировать через AI
        </button>
        <button class="btn-duo btn-primary" onclick="openGrammarModal()">
            + Добавить правило
        </button>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
    <?php foreach ($rules as $r): ?>
        <div class="card-duo" style="margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--secondary);">
                    #<?= $r['order_num'] ?>. <?= e($r['rule_title']) ?>
                </h3>
                <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editGrammar(<?= json_encode($r) ?>)'>
                    ✏️
                </button>
            </div>

            <p style="font-size: 0.95rem; margin-bottom: 14px;">
                <?= e($r['rule_description']) ?>
            </p>

            <?php if (!empty($r['rule_examples'])): ?>
                <div style="background: var(--bg-main); border-left: 3px solid var(--primary); padding: 10px 14px; border-radius: 8px; font-size: 0.85rem;">
                    <strong>Примеры:</strong> <?= e($r['rule_examples']) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal: Grammar Editor -->
<div id="modal-grammar-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 550px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-grammar-title" style="font-size: 1.3rem; font-weight: 800;">Редактор грамматики</h3>
            <button onclick="closeGrammarModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_grammar">
            <input type="hidden" name="grammar_id" id="g-id" value="0">

            <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Название правила:</label>
                    <input type="text" name="rule_title" id="g-title" required class="chat-input" placeholder="Порядок слов SVO">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Порядок:</label>
                    <input type="number" name="order_num" id="g-order" value="1" class="chat-input">
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Описание и грамматическая логика:</label>
                <textarea name="rule_description" id="g-desc" rows="4" required class="chat-input"></textarea>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Примеры фраз на Vladikish:</label>
                <input type="text" name="rule_examples" id="g-ex" class="chat-input" placeholder="Me toro Aero = Я люблю небо">
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить правило 💾
            </button>
        </form>
    </div>
</div>

<script>
function openGrammarModal() {
    document.getElementById('modal-grammar-title').textContent = 'Добавление правила';
    document.getElementById('g-id').value = '0';
    document.getElementById('g-title').value = '';
    document.getElementById('g-desc').value = '';
    document.getElementById('g-ex').value = '';
    document.getElementById('g-order').value = '1';
    document.getElementById('modal-grammar-edit').style.display = 'flex';
}

function editGrammar(g) {
    document.getElementById('modal-grammar-title').textContent = `Редактирование: ${g.rule_title}`;
    document.getElementById('g-id').value = g.id;
    document.getElementById('g-title').value = g.rule_title;
    document.getElementById('g-desc').value = g.rule_description;
    document.getElementById('g-ex').value = g.rule_examples || '';
    document.getElementById('g-order').value = g.order_num || 1;
    document.getElementById('modal-grammar-edit').style.display = 'flex';
}

function closeGrammarModal() {
    document.getElementById('modal-grammar-edit').style.display = 'none';
}

function openAiGramModal() {
    document.getElementById('modal-ai-gram').style.display = 'flex';
}

function closeAiGramModal() {
    document.getElementById('modal-ai-gram').style.display = 'none';
}

async function runAiGramGeneration() {
    const topic = document.getElementById('ai-gram-topic').value.trim();
    const btn = document.getElementById('btn-run-ai-gram');
    const statusDiv = document.getElementById('ai-gram-status');

    if (!topic) {
        alert('Укажите тему для грамматических правил!');
        return;
    }

    btn.disabled = true;
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = '<span style="color: var(--secondary); font-weight: 700;">🤖 ИИ формулирует правила и примеры...</span>';

    try {
        const res = await fetch('../api/ai.php?action=generate_grammar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ topic, count: 2 })
        });
        const data = await res.json();
        if (data.success) {
            statusDiv.innerHTML = `<span style="color: var(--primary); font-weight: 800;">🎉 Успешно создано ${data.added_count} правил! Обновление...</span>`;
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

<!-- Modal: AI Grammar Generator -->
<div id="modal-ai-gram" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.3rem; font-weight: 800;">🤖 AI Генератор грамматики Vladikish</h3>
            <button onclick="closeAiGramModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <div style="margin-bottom: 18px;">
            <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 6px;">Тема правила:</label>
            <input type="text" id="ai-gram-topic" class="chat-input" placeholder="Например: Будущее время, Предлоги места, Отрицание...">
        </div>

        <div id="ai-gram-status" style="margin-bottom: 14px; display: none;"></div>

        <button type="button" class="btn-duo btn-secondary" id="btn-run-ai-gram" style="width: 100%; font-size: 1rem; padding: 12px;" onclick="runAiGramGeneration()">
            Сгенерировать и добавить правила ✨
        </button>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
