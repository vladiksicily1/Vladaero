<?php
if (!defined('VLADINC_INIT')) exit;

$currentUser = Auth::requireAuth();
$pageTitle = 'Cloud Box — Облачное хранилище';

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cloud_file'])) {
    if (!csrf_validate()) {
        flash_set('error', 'Ошибка проверки безопасности CSRF');
        redirect('cloud');
    }

    $file = $_FILES['cloud_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $origName = basename($file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        
        // Prevent php/phtml execution for shared hosting security
        $forbiddenExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe'];
        if (in_array($ext, $forbiddenExts, true)) {
            flash_set('error', 'Загрузка исполняемых скриптов запрещена в целях безопасности');
            redirect('cloud');
        }

        $cloudDir = UPLOADS_PATH . '/files';
        if (!is_dir($cloudDir)) {
            @mkdir($cloudDir, 0755, true);
        }

        $storedName = 'file_' . uniqid() . '_' . time() . '.' . $ext;
        $target = $cloudDir . '/' . $storedName;

        if (move_uploaded_file($file['tmp_name'], $target)) {
            DB::insert('cloud_files', [
                'user_id' => $currentUser['id'],
                'original_name' => $origName,
                'storage_name' => $storedName,
                'file_size' => filesize($target),
                'mime_type' => mime_content_type($target) ?: 'application/octet-stream'
            ]);
            flash_set('success', 'Файл успешно загружен в Cloud Box!');
        } else {
            flash_set('error', 'Не удалось переместить файл');
        }
    } else {
        flash_set('error', 'Ошибка при загрузке файла');
    }
    redirect('cloud');
}

// Handle delete
if (isset($_GET['delete'])) {
    $fileId = (int)$_GET['delete'];
    $f = DB::fetch("SELECT * FROM cloud_files WHERE id = ? AND user_id = ? LIMIT 1", [$fileId, $currentUser['id']]);
    if ($f) {
        @unlink(UPLOADS_PATH . '/files/' . $f['storage_name']);
        DB::delete('cloud_files', 'id = ?', [$fileId]);
        flash_set('success', 'Файл удален');
    }
    redirect('cloud');
}

// Fetch user's files
$files = DB::fetchAll("SELECT * FROM cloud_files WHERE user_id = ? ORDER BY id DESC", [$currentUser['id']]);
$totalSize = array_sum(array_column($files, 'file_size'));

require_once TEMPLATES_PATH . '/header.php';
?>

<div style="grid-column: span 2; display: flex; flex-direction: column; gap: 24px;">

    <div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 4px;">📁 Cloud Box</h2>
            <p style="color: var(--text-secondary); font-size: 13px;">
                Использовано места: <strong><?= round($totalSize / (1024 * 1024), 2) ?> MB</strong> &bull; Всего файлов: <?= count($files) ?>
            </p>
        </div>

        <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 10px;">
            <?= csrf_field() ?>
            <label class="btn btn-primary" style="cursor: pointer;">
                <span>📤</span> Загрузить файл
                <input type="file" name="cloud_file" style="display: none;" onchange="this.form.submit()">
            </label>
        </form>
    </div>

    <!-- FILES TABLE -->
    <div class="card">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 14px;">Ваши файлы</h3>

        <?php if (empty($files)): ?>
            <div style="text-align: center; color: var(--text-muted); padding: 40px;">
                В вашем облаке пока пусто. Нажмите «Загрузить файл», чтобы сохранить файлы в экосистеме.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($files as $f): 
                    $fileUrl = url('uploads/files/' . $f['storage_name']);
                ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bg-input); border-radius: var(--radius-md);">
                        <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                            <span style="font-size: 24px;">📄</span>
                            <div style="min-width: 0;">
                                <div style="font-size: 14px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= e($f['original_name']) ?>
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <?= round($f['file_size'] / 1024, 1) ?> KB &bull; <?= time_ago($f['created_at']) ?>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 8px;">
                            <a href="<?= $fileUrl ?>" download="<?= e($f['original_name']) ?>" class="btn btn-secondary btn-sm" target="_blank">Скачать</a>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText('<?= $fileUrl ?>'); showToast('Ссылка скопирована!', 'success');">Копировать ссылку</button>
                            <a href="<?= url('cloud?delete=' . $f['id']) ?>" class="btn btn-secondary btn-sm" style="color: #ef4444;" onclick="return confirm('Удалить файл?')">Удалить</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once TEMPLATES_PATH . '/footer.php'; ?>
