<?php
/**
 * ShibaLingo - Universal Database & Entity CRUD Control Center
 * Gives the Administrator 100% full CRUD power over every table and record in the system.
 */

$adminTitle = 'База данных & Universal CRUD';
require_once __DIR__ . '/header.php';

$db = getDb();
$driver = Database::getDriver();
$message = '';
$error = '';

// 1. Discover all tables in database
$tables = [];
try {
    if ($driver === 'sqlite') {
        $tblStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC");
        $tables = $tblStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tblStmt = $db->query("SHOW TABLES");
        $tables = $tblStmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    $tables = ['users', 'roles', 'languages', 'skills', 'lessons', 'conlang_dictionary', 'conlang_grammar', 'stories', 'promo_codes', 'settings'];
}

// Filter or select active table
$activeTable = $_GET['table'] ?? ($tables[0] ?? 'users');
if (!in_array($activeTable, $tables) && !empty($tables)) {
    $activeTable = $tables[0];
}

// 2. Handle CRUD Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['crud_action'] ?? '';

    // A. Direct SQL Query Execution
    if ($action === 'exec_sql') {
        $sql = trim($_POST['custom_sql'] ?? '');
        if (!empty($sql)) {
            try {
                $isSelect = preg_match('/^\s*(SELECT|PRAGMA|SHOW|EXPLAIN)\s+/i', $sql);
                if ($isSelect) {
                    $sqlStmt = $db->query($sql);
                    $sqlResultRows = $sqlStmt->fetchAll(PDO::FETCH_ASSOC);
                    $message = "SQL запрос выполнен успешно! Получено строк: " . count($sqlResultRows);
                } else {
                    $affected = $db->exec($sql);
                    $message = "SQL команда выполнена! Затронуто строк: {$affected}";
                }
            } catch (Exception $e) {
                $error = "Ошибка SQL: " . $e->getMessage();
            }
        }
    }

    // B. Save Record (Insert or Update)
    if ($action === 'save_record') {
        $targetTable = $_POST['target_table'] ?? $activeTable;
        $primaryKey = $_POST['primary_key_col'] ?? 'id';
        $recordId = $_POST['record_id'] ?? '';
        $fields = $_POST['fields'] ?? [];

        if (in_array($targetTable, $tables) && is_array($fields)) {
            try {
                if (!empty($recordId) && $recordId !== '0') {
                    // Update
                    $setClauses = [];
                    $params = ['pk_val' => $recordId];
                    foreach ($fields as $k => $v) {
                        if ($k === $primaryKey) continue;
                        $setClauses[] = "`{$k}` = :set_{$k}";
                        $params["set_{$k}"] = ($v === '') ? null : $v;
                    }
                    if (!empty($setClauses)) {
                        $updateSql = "UPDATE `{$targetTable}` SET " . implode(', ', $setClauses) . " WHERE `{$primaryKey}` = :pk_val";
                        $stmt = $db->prepare($updateSql);
                        $stmt->execute($params);
                        $message = "Запись #{$recordId} в таблице `{$targetTable}` успешно обновлена!";
                    }
                } else {
                    // Insert
                    $cols = [];
                    $placeholders = [];
                    $params = [];
                    foreach ($fields as $k => $v) {
                        if ($k === $primaryKey && empty($v)) continue;
                        $cols[] = "`{$k}`";
                        $placeholders[] = ":ins_{$k}";
                        $params["ins_{$k}"] = ($v === '') ? null : $v;
                    }
                    if (!empty($cols)) {
                        $insertSql = "INSERT INTO `{$targetTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                        $stmt = $db->prepare($insertSql);
                        $stmt->execute($params);
                        $newId = $db->lastInsertId();
                        $message = "Новая запись #{$newId} в таблице `{$targetTable}` успешно создана!";
                    }
                }
            } catch (Exception $e) {
                $error = "Ошибка сохранения: " . $e->getMessage();
            }
        }
    }

    // C. Delete Record
    if ($action === 'delete_record') {
        $targetTable = $_POST['target_table'] ?? $activeTable;
        $primaryKey = $_POST['primary_key_col'] ?? 'id';
        $recordId = $_POST['record_id'] ?? '';

        if (in_array($targetTable, $tables) && !empty($recordId)) {
            try {
                $delSql = "DELETE FROM `{$targetTable}` WHERE `{$primaryKey}` = :id";
                $stmt = $db->prepare($delSql);
                $stmt->execute(['id' => $recordId]);
                $message = "Запись #{$recordId} из таблицы `{$targetTable}` успешно удалена!";
            } catch (Exception $e) {
                $error = "Ошибка удаления: " . $e->getMessage();
            }
        }
    }
}

// 3. Inspect Schema of Active Table
$columns = [];
$primaryKey = 'id';
if (!empty($activeTable)) {
    if ($driver === 'sqlite') {
        $colStmt = $db->query("PRAGMA table_info(`{$activeTable}`)");
        $rawCols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawCols as $rc) {
            $columns[] = [
                'name' => $rc['name'],
                'type' => $rc['type'],
                'pk' => (bool)$rc['pk']
            ];
            if ($rc['pk']) $primaryKey = $rc['name'];
        }
    } else {
        $colStmt = $db->query("SHOW COLUMNS FROM `{$activeTable}`");
        $rawCols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawCols as $rc) {
            $isPk = ($rc['Key'] === 'PRI');
            $columns[] = [
                'name' => $rc['Field'],
                'type' => $rc['Type'],
                'pk' => $isPk
            ];
            if ($isPk) $primaryKey = $rc['Field'];
        }
    }
}

// 4. Fetch Table Data with Search & Pagination
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$whereSql = "1=1";
$params = [];
if (!empty($searchQuery) && !empty($columns)) {
    $searchConds = [];
    foreach ($columns as $idx => $c) {
        $searchConds[] = "`{$c['name']}` LIKE :search_{$idx}";
        $params["search_{$idx}"] = "%{$searchQuery}%";
    }
    $whereSql .= " AND (" . implode(' OR ', $searchConds) . ")";
}

// Count total
$countSql = "SELECT COUNT(*) FROM `{$activeTable}` WHERE {$whereSql}";
$cStmt = $db->prepare($countSql);
$cStmt->execute($params);
$totalRows = (int)$cStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

// Fetch Rows
$dataSql = "SELECT * FROM `{$activeTable}` WHERE {$whereSql} ORDER BY `{$primaryKey}` DESC LIMIT {$perPage} OFFSET {$offset}";
$dStmt = $db->prepare($dataSql);
$dStmt->execute($params);
$tableRows = $dStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.85rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>🗄️</span> База данных & Universal CRUD
            <span class="badge-tag" style="background: <?= ($driver === 'mysql') ? 'rgba(88,204,2,0.15); color: #166534;' : 'rgba(28,176,246,0.15); color: #0369a1;' ?> font-size: 0.8rem; vertical-align: middle;">
                <?= ($driver === 'mysql') ? '🟢 MySQL (' . e(DB_NAME) . ')' : '🔵 SQLite (' . e(basename(SQLITE_PATH)) . ')' ?>
            </span>
        </h1>
        <p style="color: var(--text-muted);">Прямой и полный CRUD-контроль над всеми <strong><?= count($tables) ?></strong> таблицами платформы</p>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <button class="btn-duo btn-secondary" onclick="openSqlModal()">
            ⚡ Выполнить SQL запрос
        </button>
        <button class="btn-duo btn-primary" onclick="openCreateRecordModal()">
            + Добавить запись в `<?= e($activeTable) ?>`
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

<!-- Table Navigation Pills -->
<div style="display: flex; gap: 8px; overflow-x: auto; padding-bottom: 12px; margin-bottom: 20px; border-bottom: 2px solid var(--border-color);">
    <?php foreach ($tables as $t): ?>
        <?php
        $count = (int)$db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $isActive = ($t === $activeTable);
        ?>
        <a href="database.php?table=<?= urlencode($t) ?>" 
           class="btn-duo <?= $isActive ? 'btn-primary' : 'btn-outline' ?>" 
           style="padding: 6px 14px; font-size: 0.85rem; border-radius: 12px; white-space: nowrap; display: flex; align-items: center; gap: 6px; text-decoration: none;">
            <span><?= e($t) ?></span>
            <span style="background: rgba(0,0,0,0.12); padding: 1px 6px; border-radius: 8px; font-size: 0.75rem; font-weight: 800;"><?= $count ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Search & Action Toolbar -->
<div class="card-duo" style="padding: 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <form method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 500px;">
        <input type="hidden" name="table" value="<?= e($activeTable) ?>">
        <input type="text" name="q" value="<?= e($searchQuery) ?>" class="chat-input" placeholder="Поиск по всем полям `<?= e($activeTable) ?>`..." style="padding: 10px 14px;">
        <button type="submit" class="btn-duo btn-secondary" style="padding: 10px 16px;">🔍 Найти</button>
        <?php if (!empty($searchQuery)): ?>
            <a href="database.php?table=<?= urlencode($activeTable) ?>" class="btn-duo btn-outline" style="padding: 10px 14px; text-decoration: none;">Сброс</a>
        <?php endif; ?>
    </form>

    <div style="font-size: 0.9rem; color: var(--text-muted); font-weight: 700;">
        Всего записей: <span style="color: var(--primary-shadow); font-weight: 900;"><?= $totalRows ?></span> (Стр. <?= $page ?> из <?= $totalPages ?>)
    </div>
</div>

<!-- Main Records Data Grid -->
<div class="card-duo" style="padding: 0; overflow: hidden;">
    <div style="overflow-x: auto; max-height: 650px;">
        <table class="dict-table" style="margin: 0; width: 100%; border-collapse: collapse;">
            <thead style="position: sticky; top: 0; background: var(--bg-sidebar); z-index: 10;">
                <tr>
                    <th style="width: 110px; text-align: center;">Действия</th>
                    <?php foreach ($columns as $c): ?>
                        <th style="white-space: nowrap;">
                            <?= e($c['name']) ?>
                            <?php if ($c['pk']): ?><span style="color: var(--primary); font-size: 0.75rem;">🔑</span><?php endif; ?>
                            <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: normal;">(<?= e($c['type']) ?>)</span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tableRows)): ?>
                    <tr>
                        <td colspan="<?= count($columns) + 1 ?>" style="text-align: center; padding: 32px; color: var(--text-muted);">
                            Записи не найдены в таблице `<?= e($activeTable) ?>`.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tableRows as $row): ?>
                        <tr>
                            <td style="text-align: center; white-space: nowrap;">
                                <button class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" onclick='editRow(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)' title="Редактировать">
                                    ✏️
                                </button>
                                <form method="POST" style="display: inline-block;" onsubmit="return confirm('Вы уверены, что хотите удалить запись #<?= e($row[$primaryKey] ?? '') ?>?');">
                                    <input type="hidden" name="crud_action" value="delete_record">
                                    <input type="hidden" name="target_table" value="<?= e($activeTable) ?>">
                                    <input type="hidden" name="primary_key_col" value="<?= e($primaryKey) ?>">
                                    <input type="hidden" name="record_id" value="<?= e($row[$primaryKey] ?? '') ?>">
                                    <button type="submit" class="btn-duo btn-danger" style="padding: 4px 8px; font-size: 0.8rem;" title="Удалить">
                                        🗑️
                                    </button>
                                </form>
                            </td>
                            <?php foreach ($columns as $c): ?>
                                <?php
                                $val = $row[$c['name']] ?? '';
                                $isLong = is_string($val) && strlen($val) > 60;
                                ?>
                                <td style="max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85rem;" title="<?= e(is_scalar($val) ? (string)$val : '') ?>">
                                    <?php if ($val === null): ?>
                                        <span style="color: var(--text-muted); font-style: italic;">NULL</span>
                                    <?php elseif ($isLong): ?>
                                        <code><?= e(mb_substr($val, 0, 60)) ?>...</code>
                                    <?php else: ?>
                                        <?= e((string)$val) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="database.php?table=<?= urlencode($activeTable) ?>&q=<?= urlencode($searchQuery) ?>&p=<?= $i ?>" 
               class="btn-duo <?= ($i === $page) ? 'btn-primary' : 'btn-outline' ?>" 
               style="padding: 6px 12px; font-size: 0.85rem; text-decoration: none;">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<!-- Modal: Dynamic Record Editor (Create / Update) -->
<div id="modal-record-editor" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 300; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 680px; width: 100%; max-height: 90vh; overflow-y: auto; margin-bottom: 0; padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 2px solid var(--border-color); padding-bottom: 12px;">
            <h3 id="rec-modal-title" style="font-size: 1.35rem; font-weight: 800;">Редактирование записи</h3>
            <button onclick="closeRecordModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST" id="rec-editor-form">
            <input type="hidden" name="crud_action" value="save_record">
            <input type="hidden" name="target_table" value="<?= e($activeTable) ?>">
            <input type="hidden" name="primary_key_col" value="<?= e($primaryKey) ?>">
            <input type="hidden" name="record_id" id="rec-id-input" value="0">

            <div style="display: flex; flex-direction: column; gap: 14px;">
                <?php foreach ($columns as $c): ?>
                    <?php
                    $cName = $c['name'];
                    $cType = strtolower($c['type']);
                    $isTextarea = (strpos($cName, 'data') !== false || strpos($cName, 'json') !== false || strpos($cName, 'description') !== false || strpos($cName, 'examples') !== false || strpos($cType, 'text') !== false);
                    ?>
                    <div>
                        <label style="font-weight: 800; font-size: 0.85rem; display: flex; justify-content: space-between;">
                            <span><?= e($cName) ?> <?= $c['pk'] ? '🔑 (Primary Key)' : '' ?></span>
                            <span style="color: var(--text-muted); font-weight: normal; font-size: 0.75rem;"><?= e($c['type']) ?></span>
                        </label>
                        <?php if ($isTextarea): ?>
                            <textarea name="fields[<?= e($cName) ?>]" id="inp-<?= e($cName) ?>" class="chat-input" rows="4" style="font-family: monospace; font-size: 0.85rem;"></textarea>
                        <?php else: ?>
                            <input type="text" name="fields[<?= e($cName) ?>]" id="inp-<?= e($cName) ?>" class="chat-input" <?= $c['pk'] ? 'readonly style="background: var(--bg-main);"' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn-duo btn-outline" onclick="closeRecordModal()">Отмена</button>
                <button type="submit" class="btn-duo btn-primary" id="rec-submit-btn">Сохранить запись 💾</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: SQL Query Workbench -->
<div id="modal-sql-workbench" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 300; align-items: center; justify-content: center; padding: 20px;">
    <div class="card-duo anim-bounce" style="max-width: 750px; width: 100%; max-height: 90vh; overflow-y: auto; margin-bottom: 0; padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-size: 1.35rem; font-weight: 800;">⚡ SQL Консоль Администратора</h3>
            <button onclick="closeSqlModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="crud_action" value="exec_sql">
            <div style="margin-bottom: 14px;">
                <label style="font-weight: 800; font-size: 0.85rem;">Введите SQL запрос:</label>
                <textarea name="custom_sql" class="chat-input" rows="5" style="font-family: monospace; font-size: 0.9rem;" placeholder="SELECT * FROM users ORDER BY xp DESC LIMIT 10;">SELECT * FROM `<?= e($activeTable) ?>` LIMIT 20;</textarea>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
                <button type="button" class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.75rem;" onclick="document.querySelector('textarea[name=custom_sql]').value = 'SELECT * FROM `<?= e($activeTable) ?>` LIMIT 20;'">SELECT *</button>
                <button type="button" class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.75rem;" onclick="document.querySelector('textarea[name=custom_sql]').value = 'SELECT COUNT(*) as total FROM `<?= e($activeTable) ?>`;'">COUNT(*)</button>
                <button type="button" class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.75rem;" onclick="document.querySelector('textarea[name=custom_sql]').value = 'SELECT * FROM users ORDER BY xp DESC LIMIT 10;'">Топ пользователей</button>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-duo btn-outline" onclick="closeSqlModal()">Закрыть</button>
                <button type="submit" class="btn-duo btn-primary">Выполнить SQL 🚀</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateRecordModal() {
    document.getElementById('rec-modal-title').textContent = 'Добавить запись в `<?= e($activeTable) ?>`';
    document.getElementById('rec-id-input').value = '0';
    document.getElementById('rec-submit-btn').textContent = 'Создать запись ✨';

    // Clear inputs
    const inputs = document.querySelectorAll('#rec-editor-form input[type=text], #rec-editor-form textarea');
    inputs.forEach(inp => inp.value = '');

    document.getElementById('modal-record-editor').style.display = 'flex';
}

function closeRecordModal() {
    document.getElementById('modal-record-editor').style.display = 'none';
}

function editRow(row) {
    document.getElementById('rec-modal-title').textContent = 'Редактировать запись #' + (row['<?= e($primaryKey) ?>'] || '') + ' (`<?= e($activeTable) ?>`)';
    document.getElementById('rec-id-input').value = row['<?= e($primaryKey) ?>'] || '';
    document.getElementById('rec-submit-btn').textContent = 'Сохранить изменения 💾';

    for (const [key, val] of Object.entries(row)) {
        const inp = document.getElementById('inp-' + key);
        if (inp) {
            inp.value = (val !== null) ? val : '';
        }
    }

    document.getElementById('modal-record-editor').style.display = 'flex';
}

function openSqlModal() { document.getElementById('modal-sql-workbench').style.display = 'flex'; }
function closeSqlModal() { document.getElementById('modal-sql-workbench').style.display = 'none'; }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
