<?php
/**
 * Admin Living Conlang & Slang Proposals Moderation (CRUD)
 */

$adminTitle = 'Модерация сленга Vladikish';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_conlang') && !hasPermission($admin, 'manage_lessons')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'approve_slang') {
        $pid = (int)$_POST['proposal_id'];
        $prop = $db->query("SELECT * FROM " . tbl('slang_proposals') . " WHERE id = {$pid}")->fetch();
        if ($prop) {
            // Update proposal status
            $db->prepare("UPDATE " . tbl('slang_proposals') . " SET status = 'approved' WHERE id = :id")->execute(['id' => $pid]);
            // Add directly to conlang_dictionary
            $addDict = $db->prepare("INSERT INTO " . tbl('conlang_dictionary') . " (language_code, word, part_of_speech, translation_ru, translation_en, example_sentence, created_by) VALUES ('vladikish', :w, 'slang', :tr, :te, :ex, 'community')");
            $addDict->execute([
                'w' => $prop['word'],
                'tr' => $prop['translation'],
                'te' => $prop['translation'],
                'ex' => $prop['example']
            ]);
            $message = 'Слово «' . $prop['word'] . '» успешно одобрено и добавлено в официальный словарь Vladikish!';
        }
    }

    if ($act === 'reject_slang') {
        $pid = (int)$_POST['proposal_id'];
        $db->prepare("UPDATE " . tbl('slang_proposals') . " SET status = 'rejected' WHERE id = :id")->execute(['id' => $pid]);
        $message = 'Предложение отклонено.';
    }

    if ($act === 'delete_slang') {
        $pid = (int)$_POST['proposal_id'];
        $db->prepare("DELETE FROM " . tbl('slang_proposals') . " WHERE id = :id")->execute(['id' => $pid]);
        $message = 'Запись удалена.';
    }
}

$proposals = $db->query("SELECT p.*, u.username as author_name FROM " . tbl('slang_proposals') . " p LEFT JOIN " . tbl('users') . " u ON p.author_id = u.id ORDER BY p.id DESC")->fetchAll();
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">🗣️ Модерация предложений сленга (Living Conlang)</h1>
        <p style="color: var(--text-muted);">Проверка и одобрение новых слов от сообщества в официальный словарь Vladikish</p>
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
                <th>ID</th>
                <th>Слово</th>
                <th>Перевод</th>
                <th>Пример</th>
                <th>Автор</th>
                <th>Голоса</th>
                <th>Статус</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($proposals)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 32px; color: var(--text-muted);">
                        Предложений от сообщества пока нет.
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($proposals as $pr): ?>
                <tr>
                    <td>#<?= $pr['id'] ?></td>
                    <td style="font-weight: 900; font-size: 1.1rem; color: var(--secondary);"><?= e($pr['word']) ?></td>
                    <td style="font-weight: 700;"><?= e($pr['translation']) ?></td>
                    <td style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;"><?= e($pr['example'] ?? '—') ?></td>
                    <td><span class="badge-tag"><?= e($pr['author_name'] ?? 'Аноним') ?></span></td>
                    <td style="font-weight: 800;">👍 <?= (int)$pr['upvotes'] ?> / 👎 <?= (int)$pr['downvotes'] ?></td>
                    <td>
                        <span class="badge-tag" style="background: <?= ($pr['status'] === 'approved') ? 'var(--primary-light); color: var(--primary-shadow);' : (($pr['status'] === 'rejected') ? 'var(--danger-light); color: var(--danger-shadow);' : '#fef08a; color: #854d0e;') ?>">
                            <?= e($pr['status']) ?>
                        </span>
                    </td>
                    <td style="display: flex; gap: 6px;">
                        <?php if ($pr['status'] === 'pending'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="form_action" value="approve_slang">
                                <input type="hidden" name="proposal_id" value="<?= $pr['id'] ?>">
                                <button type="submit" class="btn-duo btn-primary" style="padding: 4px 8px; font-size: 0.8rem;" title="Одобрить в словарь">
                                    ✓ Одобрить
                                </button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="form_action" value="reject_slang">
                                <input type="hidden" name="proposal_id" value="<?= $pr['id'] ?>">
                                <button type="submit" class="btn-duo btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" title="Отклонить">
                                    ✕
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить?');">
                            <input type="hidden" name="form_action" value="delete_slang">
                            <input type="hidden" name="proposal_id" value="<?= $pr['id'] ?>">
                            <button type="submit" class="btn-duo" style="padding: 4px 8px; font-size: 0.8rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);" title="Удалить">
                                🗑️
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
