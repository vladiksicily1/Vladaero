<?php
/**
 * Admin Media Manager (Upload, Browse, Copy Links, Delete)
 */

$adminTitle = 'Медиатека & Файлы';
require_once __DIR__ . '/header.php';

$message = '';
$uploadsDir = realpath(__DIR__ . '/../uploads');
if (!$uploadsDir) {
    $uploadsDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0755, true);
    }
    $uploadsDir = realpath($uploadsDir);
}

// Handle file deletion
if (isset($_POST['delete_file'])) {
    $fileToDelete = basename($_POST['delete_file']);
    $fullPath = $uploadsDir . DIRECTORY_SEPARATOR . $fileToDelete;
    if ($fileToDelete !== '.htaccess' && file_exists($fullPath) && is_file($fullPath)) {
        @unlink($fullPath);
        $message = "Файл «{$fileToDelete}» успешно удален!";
    }
}

// Read files in uploads directory
$filesList = [];
if ($uploadsDir && is_dir($uploadsDir)) {
    $scanned = scandir($uploadsDir);
    foreach ($scanned as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
        $path = $uploadsDir . DIRECTORY_SEPARATOR . $f;
        if (is_file($path)) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
            $isAudio = in_array($ext, ['mp3', 'wav', 'ogg', 'm4a']);
            $filesList[] = [
                'name' => $f,
                'url' => '../uploads/' . $f,
                'public_url' => 'uploads/' . $f,
                'size' => round(filesize($path) / 1024, 1),
                'mtime' => filemtime($path),
                'is_image' => $isImage,
                'is_audio' => $isAudio,
                'ext' => $ext
            ];
        }
    }
}

// Sort by newest first
usort($filesList, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
?>

<div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 1.8rem; font-weight: 900;">📁 Медиатека и Загрузка файлов</h1>
        <p style="color: var(--text-muted);">Загружайте изображения маскотов, обложки уроков, иконки и аудио-файлы в папку <code>uploads/</code></p>
    </div>

    <button class="btn-duo btn-primary" onclick="document.getElementById('file-upload-input').click()">
        + Загрузить файл 📤
    </button>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
        ✓ <?= e($message) ?>
    </div>
<?php endif; ?>

<!-- Drag & Drop Upload Zone -->
<div class="card-duo" id="drop-zone" style="border: 3px dashed var(--primary); text-align: center; padding: 36px 20px; cursor: pointer; transition: background 0.2s;" onclick="document.getElementById('file-upload-input').click()">
    <input type="file" id="file-upload-input" style="display: none;" accept="image/*,audio/*" onchange="handleFileUpload(this.files)">
    <div style="font-size: 3rem; margin-bottom: 8px;">📤 🎨</div>
    <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 4px;">Перетащите сюда файлы или кликните для выбора</h3>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 0;">
        Поддерживаются: <strong>PNG, JPG, WEBP, SVG, GIF, MP3, WAV, OGG</strong> (до 15 МБ)
    </p>
    <div id="upload-progress-text" style="font-weight: 800; color: var(--primary); margin-top: 12px; display: none;">Загрузка файла... 🐾</div>
</div>

<!-- Media Gallery Grid -->
<div style="margin-top: 28px;">
    <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 16px;">Загруженные файлы (<?= count($filesList) ?>)</h3>

    <?php if (empty($filesList)): ?>
        <div class="card-duo" style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 3rem; margin-bottom: 8px;">📂</div>
            В папке <code>uploads/</code> пока нет загруженных файлов.
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px;">
            <?php foreach ($filesList as $f): ?>
                <div class="card-duo anim-bounce" style="padding: 0; overflow: hidden; margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
                    <div style="height: 140px; background: var(--bg-main); display: flex; align-items: center; justify-content: center; overflow: hidden;">
                        <?php if ($f['is_image']): ?>
                            <img src="<?= e($f['url']) ?>" alt="Media" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php elseif ($f['is_audio']): ?>
                            <div style="text-align: center;">
                                <span style="font-size: 3rem;">🎵</span>
                                <div style="font-size: 0.8rem; font-weight: 800; color: var(--text-muted);"><?= strtoupper($f['ext']) ?> Audio</div>
                            </div>
                        <?php else: ?>
                            <span style="font-size: 3rem;">📄</span>
                        <?php endif; ?>
                    </div>

                    <div style="padding: 12px;">
                        <div style="font-weight: 800; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-bottom: 4px;" title="<?= e($f['name']) ?>">
                            <?= e($f['name']) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span><?= $f['size'] ?> KB</span>
                            <span><?= date('d.m.Y H:i', $f['mtime']) ?></span>
                        </div>

                        <div style="display: flex; gap: 6px;">
                            <button type="button" class="btn-duo btn-outline" style="flex: 1; padding: 6px 10px; font-size: 0.8rem;" onclick="copyMediaUrl('<?= e($f['public_url']) ?>')">
                                📋 URL
                            </button>
                            <form method="POST" onsubmit="return confirm('Удалить файл?');" style="margin: 0;">
                                <input type="hidden" name="delete_file" value="<?= e($f['name']) ?>">
                                <button type="submit" class="btn-duo btn-outline" style="padding: 6px 10px; font-size: 0.8rem; border-color: var(--danger); color: var(--danger);">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function copyMediaUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        SoundEngine.play('correct');
        alert('URL скопирован в буфер обмена: ' + url);
    });
}

// Drag & Drop handlers
const dropZone = document.getElementById('drop-zone');
['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropZone.style.background = 'var(--primary-light)';
    }, false);
});

['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropZone.style.background = 'transparent';
    }, false);
});

dropZone.addEventListener('drop', (e) => {
    const dt = e.dataTransfer;
    const files = dt.files;
    handleFileUpload(files);
});

async function handleFileUpload(files) {
    if (!files || !files.length) return;
    const file = files[0];
    const progress = document.getElementById('upload-progress-text');
    progress.style.display = 'block';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', 'media');

    try {
        const res = await fetch('../api/upload_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            alert('✓ ' + data.message);
            location.reload();
        } else {
            SoundEngine.play('wrong');
            alert('Ошибка: ' + data.error);
        }
    } catch(err) {
        alert('Ошибка отправки файла на сервер.');
    } finally {
        progress.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
