<?php
/**
 * Admin Languages Management
 */

$adminTitle = 'Управление языками';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_languages')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save_lang') {
        $code = trim($_POST['code']);
        $name = trim($_POST['name']);
        $native = trim($_POST['native_name']);
        $flag = trim($_POST['flag']);
        $desc = trim($_POST['description']);
        $isConlang = isset($_POST['is_conlang']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $checkStmt = $db->prepare("SELECT COUNT(*) FROM languages WHERE code = :c");
        $checkStmt->execute(['c' => $code]);
        $exists = $checkStmt->fetchColumn() > 0;

        if ($exists) {
            $stmt = $db->prepare("UPDATE languages SET name = :n, native_name = :nat, flag = :f, description = :d, is_conlang = :con, is_active = :act WHERE code = :c");
            $stmt->execute(['c' => $code, 'n' => $name, 'nat' => $native, 'f' => $flag, 'd' => $desc, 'con' => $isConlang, 'act' => $isActive]);
        } else {
            $stmt = $db->prepare("INSERT INTO languages (code, name, native_name, flag, description, is_conlang, is_active) VALUES (:c, :n, :nat, :f, :d, :con, :act)");
            $stmt->execute(['c' => $code, 'n' => $name, 'nat' => $native, 'f' => $flag, 'd' => $desc, 'con' => $isConlang, 'act' => $isActive]);
        }
        $message = 'Язык успешно сохранен!';
    }

    if ($act === 'delete_lang') {
        $code = $_POST['delete_code'];
        $stmt = $db->prepare("DELETE FROM languages WHERE code = :c");
        $stmt->execute(['c' => $code]);
        $message = 'Язык удален.';
    }
}

$languages = $db->query("SELECT * FROM languages ORDER BY is_conlang DESC, code ASC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">🌐 Управление языками обучения</h1>
        <p style="color: var(--text-muted);">Добавление новых языков, вымышленных Conlang и настройка флагов</p>
    </div>

    <button class="btn-duo btn-primary" onclick="openLangModal()">
        + Добавить язык
    </button>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
    <?php foreach ($languages as $l): ?>
        <div class="card-duo" style="margin-bottom: 0;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 2.2rem;"><?= $l['flag'] ?></span>
                    <div>
                        <h3 style="font-size: 1.2rem; font-weight: 800;"><?= e($l['name']) ?></h3>
                        <span style="font-size: 0.85rem; color: var(--text-muted);"><?= e($l['native_name']) ?> (<code><?= e($l['code']) ?></code>)</span>
                    </div>
                </div>
                <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editLang(<?= json_encode($l) ?>)'>
                    ✏️
                </button>
            </div>

            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 14px;">
                <?= e($l['description']) ?>
            </p>

            <div style="display: flex; gap: 8px;">
                <?php if ($l['is_conlang']): ?>
                    <span class="badge-tag" style="background: #f3e8ff; color: #7e22ce;">✨ Вымышленный язык (Conlang)</span>
                <?php endif; ?>
                <span class="badge-tag" style="background: <?= ($l['is_active']) ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;' ?>">
                    <?= ($l['is_active']) ? '🟢 Активен' : '🔴 Отключен' ?>
                </span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal: Language Editor -->
<div id="modal-lang-edit" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 500px; width: 100%; margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modal-lang-title" style="font-size: 1.3rem; font-weight: 800;">Редактирование языка</h3>
            <button onclick="closeLangModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="form_action" value="save_lang">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Код языка (code):</label>
                    <input type="text" name="code" id="lang-code" required class="chat-input" placeholder="es">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Иконка / Флаг (эмодзи):</label>
                    <input type="text" name="flag" id="lang-flag" required class="chat-input" placeholder="🇪🇸">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Название (RU):</label>
                    <input type="text" name="name" id="lang-name" required class="chat-input" placeholder="Испанский">
                </div>
                <div>
                    <label style="font-weight: 700; font-size: 0.85rem;">Родное название (Native):</label>
                    <input type="text" name="native_name" id="lang-native" required class="chat-input" placeholder="Español">
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.85rem;">Краткое описание:</label>
                <textarea name="description" id="lang-desc" rows="2" class="chat-input"></textarea>
            </div>

            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="is_conlang" id="lang-conlang" value="1">
                    <span>Вымышленный Conlang</span>
                </label>
                <label style="font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="is_active" id="lang-active" value="1" checked>
                    <span>Активен</span>
                </label>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить язык 💾
            </button>
        </form>
    </div>
</div>

<script>
function openLangModal() {
    document.getElementById('modal-lang-title').textContent = 'Добавление нового языка';
    document.getElementById('lang-code').value = '';
    document.getElementById('lang-flag').value = '🌐';
    document.getElementById('lang-name').value = '';
    document.getElementById('lang-native').value = '';
    document.getElementById('lang-desc').value = '';
    document.getElementById('lang-conlang').checked = false;
    document.getElementById('lang-active').checked = true;
    document.getElementById('modal-lang-edit').style.display = 'flex';
}

function editLang(lang) {
    document.getElementById('modal-lang-title').textContent = `Редактирование: ${lang.name}`;
    document.getElementById('lang-code').value = lang.code;
    document.getElementById('lang-flag').value = lang.flag;
    document.getElementById('lang-name').value = lang.name;
    document.getElementById('lang-native').value = lang.native_name;
    document.getElementById('lang-desc').value = lang.description || '';
    document.getElementById('lang-conlang').checked = (lang.is_conlang == 1);
    document.getElementById('lang-active').checked = (lang.is_active == 1);
    document.getElementById('modal-lang-edit').style.display = 'flex';
}

function closeLangModal() {
    document.getElementById('modal-lang-edit').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
