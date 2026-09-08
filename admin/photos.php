<?php
$adminTitle = 'Модерация Авиаспоттинга';
require_once __DIR__ . '/header.php';

$pTable = Database::tableName('photos');
$uTable = Database::tableName('users');
$acTable = Database::tableName('aircraft');

// Handle Action POST (approve/reject)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $act = $_GET['action'];

    if ($act === 'approve') {
        Database::update('photos', ['status' => 'approved'], 'id = :id', ['id' => $id]);
        setFlash('success', "Фотография #{$id} успешно одобрена!");
    } elseif ($act === 'reject') {
        Database::update('photos', ['status' => 'rejected'], 'id = :id', ['id' => $id]);
        setFlash('success', "Фотография #{$id} отклонена.");
    }
    header('Location: ' . url('/admin/photos.php'));
    exit;
}

$pending = Database::fetchAll("SELECT p.*, u.username, a.model_name FROM `{$pTable}` p LEFT JOIN `{$uTable}` u ON p.user_id = u.id LEFT JOIN `{$acTable}` a ON p.aircraft_id = a.id WHERE p.status = 'pending' ORDER BY p.id ASC");
$approved = Database::fetchAll("SELECT p.*, u.username, a.model_name FROM `{$pTable}` p LEFT JOIN `{$uTable}` u ON p.user_id = u.id LEFT JOIN `{$acTable}` a ON p.aircraft_id = a.id WHERE p.status = 'approved' ORDER BY p.id DESC LIMIT 20");
?>

<div class="space-y-8">

    <div>
        <h1 class="text-2xl font-bold text-white">Модерация Споттерских Снимков</h1>
        <p class="text-xs text-slate-400 font-mono">Проверка качества, корректности бортовых номеров и EXIF-данных</p>
    </div>

    <!-- Pending Queue -->
    <div class="va-card p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold text-white uppercase font-mono tracking-wider flex items-center space-x-2">
                <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                <span>Очередь на модерацию (<?= count($pending) ?>)</span>
            </h2>
        </div>

        <?php if (empty($pending)): ?>
            <div class="p-8 text-center text-slate-500 font-mono text-xs bg-slate-950 rounded-xl">
                В очереди нет ожидающих снимков. Все фото проверены!
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($pending as $p): ?>
                    <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 space-y-3 font-mono text-xs">
                        <div class="aspect-[3/2] rounded-lg overflow-hidden bg-slate-900">
                            <img src="<?= e($p['thumb_url'] ?: $p['photo_url']) ?>" alt="Photo" class="w-full h-full object-cover">
                        </div>

                        <div class="flex justify-between">
                            <span class="font-bold text-sky-400"><?= e($p['tail_number'] ?: 'Без номера') ?></span>
                            <span class="text-slate-400">@<?= e($p['username']) ?></span>
                        </div>

                        <div class="text-[11px] text-slate-300">
                            ВС: <?= e($p['model_name'] ?? '—') ?>
                        </div>

                        <div class="text-[10px] text-slate-500 truncate">
                            📷 <?= e($p['camera_model']) ?> • <?= e($p['lens']) ?>
                        </div>

                        <div class="pt-2 flex items-center space-x-2">
                            <a href="?action=approve&id=<?= $p['id'] ?>" class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white text-center py-2 rounded-lg font-bold transition">
                                Одобрить
                            </a>
                            <a href="?action=reject&id=<?= $p['id'] ?>" onclick="return confirm('Отклонить снимок?');" class="flex-1 bg-red-600/80 hover:bg-red-600 text-white text-center py-2 rounded-lg font-bold transition">
                                Отклонить
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Approved Photos History -->
    <div class="va-card p-6 space-y-4">
        <h2 class="text-sm font-bold text-white uppercase font-mono tracking-wider">Одобренные снимки в галерее</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
            <?php foreach ($approved as $ap): ?>
                <div class="aspect-square rounded-xl overflow-hidden bg-slate-950 border border-slate-800 relative group">
                    <img src="<?= e($ap['thumb_url'] ?: $ap['photo_url']) ?>" class="w-full h-full object-cover">
                    <div class="absolute inset-0 bg-slate-950/80 opacity-0 group-hover:opacity-100 transition p-2 flex flex-col justify-between text-[10px] font-mono text-white">
                        <span><?= e($ap['tail_number']) ?></span>
                        <span class="text-sky-400">@<?= e($ap['username']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
