<?php
declare(strict_types=1);

namespace VladAero;

$adminTitle = 'Скрининг споттерских фото';
require_once __DIR__ . '/header.php';

// Handle Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $photoId = (int)($_POST['photo_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reject_reason'] ?? '');

    if ($action === 'approve') {
        DB::update('va_photos', ['status' => 'approved', 'reject_reason' => null], '`id` = :id', ['id' => $photoId]);
        
        // Award XP to author
        $p = DB::fetchOne("SELECT `user_id` FROM `va_photos` WHERE `id` = :id", ['id' => $photoId]);
        if ($p) Auth::addXp((int)$p['user_id'], 50);

        record_audit('screener_approve_photo', 'photo', $photoId);
    } elseif ($action === 'reject') {
        DB::update('va_photos', ['status' => 'rejected', 'reject_reason' => $reason], '`id` = :id', ['id' => $photoId]);
        record_audit('screener_reject_photo', 'photo', $photoId, ['reason' => $reason]);
    }

    header('Location: photos.php');
    exit;
}

// Fetch pending photos
$pendingList = DB::fetchAll(
    "SELECT p.*, u.username, u.full_name, a.model_name, apt.name_ru AS airport_name 
     FROM `va_photos` p 
     JOIN `va_users` u ON p.user_id = u.id 
     LEFT JOIN `va_aircraft` a ON p.aircraft_id = a.id 
     LEFT JOIN `va_airports` apt ON p.airport_id = apt.id 
     WHERE p.status = 'pending' 
     ORDER BY p.id ASC"
);

$current = $pendingList[0] ?? null;
?>

<div class="space-y-6">
    
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white font-sans">Экран скринера споттерских фото</h1>
            <p class="text-xs text-slate-400 font-mono mt-1">Очередь премодерации: <?= count($pendingList) ?> фото. Горячие клавиши: <kbd class="px-1.5 py-0.5 rounded bg-slate-800 text-sky-400 border border-slate-700">A</kbd> — Принять, <kbd class="px-1.5 py-0.5 rounded bg-slate-800 text-rose-400 border border-slate-700">R</kbd> — Отклонить</p>
        </div>
    </div>

    <?php if (!$current): ?>
        <div class="glass-card rounded-3xl p-16 text-center text-slate-500 font-mono">
            <i data-lucide="check-circle-2" class="w-16 h-16 mx-auto mb-4 text-emerald-400"></i>
            <div class="text-lg font-bold text-white mb-1">Все фотографии проверены!</div>
            <div class="text-xs">Очередь скрининга пуста. Все новые кадры обработаны.</div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            
            <!-- Big Photo with Zoom Loupe -->
            <div class="lg:col-span-8 space-y-4">
                <div class="relative aspect-[16/10] rounded-3xl overflow-hidden glass-card border border-white/10 bg-slate-950 flex items-center justify-center">
                    <img id="screenerPhoto" src="../<?= e($current['photo_url']) ?>" alt="Screener" class="max-w-full max-h-full object-contain">
                </div>

                <!-- Screener Controls -->
                <div class="flex items-center justify-between p-4 glass-card rounded-2xl font-mono text-xs">
                    <div class="text-slate-400">
                        Фото 1 из <?= count($pendingList) ?> (ID: #<?= (int)$current['id'] ?>)
                    </div>

                    <div class="flex items-center space-x-3">
                        <form method="POST" id="approveForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="photo_id" value="<?= (int)$current['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold transition shadow-lg shadow-emerald-600/30 flex items-center space-x-1.5">
                                <i data-lucide="check" class="w-4 h-4"></i>
                                <span>Принять (A)</span>
                            </button>
                        </form>

                        <button onclick="openRejectModal()" class="px-6 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold transition shadow-lg shadow-rose-600/30 flex items-center space-x-1.5">
                            <i data-lucide="x" class="w-4 h-4"></i>
                            <span>Отклонить (R)</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- EXIF & Metadata Sidebar -->
            <div class="lg:col-span-4 space-y-4 font-mono text-xs">
                <div class="glass-card rounded-3xl p-6 border border-white/5 space-y-4">
                    <h3 class="font-bold text-white text-sm border-b border-white/5 pb-2">Параметры снимка</h3>

                    <div>
                        <span class="text-[10px] text-slate-500 uppercase block">Автор:</span>
                        <span class="text-sky-400 font-bold text-sm"><?= e($current['full_name'] ?: $current['username']) ?></span>
                    </div>

                    <div>
                        <span class="text-[10px] text-slate-500 uppercase block">Самолёт:</span>
                        <span class="text-white font-bold"><?= e($current['model_name'] ?: '—') ?></span>
                    </div>

                    <div>
                        <span class="text-[10px] text-slate-500 uppercase block">Бортовой номер (Tail):</span>
                        <span class="text-amber-400 font-bold text-sm"><?= e($current['tail_number'] ?: '—') ?></span>
                    </div>

                    <div>
                        <span class="text-[10px] text-slate-500 uppercase block">Аэропорт:</span>
                        <span class="text-white"><?= e($current['airport_name'] ?: '—') ?></span>
                    </div>

                    <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/5 space-y-1.5 text-[11px] text-slate-300">
                        <div class="font-bold text-sky-400 mb-1">EXIF телеметрия:</div>
                        <div>Камера: <span class="text-white"><?= e($current['camera_model'] ?: '—') ?></span></div>
                        <div>Объектив: <span class="text-white"><?= e($current['lens'] ?: '—') ?></span></div>
                        <div>Параметры: <span class="text-white"><?= e($current['focal_length'] ?: '') ?> <?= e($current['aperture'] ?: '') ?> ISO <?= e($current['iso'] ?: '') ?></span></div>
                        <div>Выдержка: <span class="text-white"><?= e($current['shutter_speed'] ?: '—') ?></span></div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Reject Preset Modal -->
        <div id="rejectModal" class="fixed inset-0 z-50 bg-black/75 backdrop-blur-sm hidden flex items-center justify-center p-4">
            <div class="max-w-md w-full glass-hud rounded-3xl p-6 border border-rose-500/30 shadow-2xl font-mono text-xs space-y-4">
                <h3 class="text-base font-bold text-rose-400">Укажите причину отклонения</h3>

                <form method="POST" id="rejectForm" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="photo_id" value="<?= (int)$current['id'] ?>">
                    <input type="hidden" name="action" value="reject">

                    <div class="space-y-2">
                        <?php 
                        $presets = [
                            'Завален горизонт / наклон кадра',
                            'Смаз / нерезкость / плохой фокус',
                            'Высокий уровень цифрового шума / артефакты',
                            'Переэкспозиция / глубокий недосвет',
                            'Неудачное кадрирование (обрезаны законцовки крыла / хвост)',
                            'Неавиационный контент'
                        ];
                        foreach ($presets as $p): ?>
                            <label class="flex items-center space-x-2 p-2 rounded-lg bg-slate-900 hover:bg-rose-500/10 cursor-pointer">
                                <input type="radio" name="reject_reason" value="<?= e($p) ?>" required class="text-rose-500">
                                <span class="text-slate-200"><?= e($p) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex justify-end space-x-2 pt-2">
                        <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300">Отмена</button>
                        <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold">Отклонить фото</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Hotkeys Listener -->
        <script>
            window.addEventListener('keydown', (e) => {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                if (e.key === 'a' || e.key === 'A' || e.key === 'ф' || e.key === 'Ф') {
                    document.getElementById('approveForm').submit();
                }
                if (e.key === 'r' || e.key === 'R' || e.key === 'к' || e.key === 'К') {
                    openRejectModal();
                }
            });

            function openRejectModal() {
                document.getElementById('rejectModal').classList.remove('hidden');
            }
            function closeRejectModal() {
                document.getElementById('rejectModal').classList.add('hidden');
            }
        </script>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
