<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$filter = trim($_GET['filter'] ?? 'all');
$aircraftId = isset($_GET['aircraft_id']) ? (int)$_GET['aircraft_id'] : null;

$where = ["p.status = 'approved'"];
$params = [];

if ($filter === 'potd') {
    $where[] = "p.is_photo_of_day = 1";
} elseif ($filter === 'top') {
    $where[] = "p.likes_count > 5";
}

if ($aircraftId) {
    $where[] = "p.aircraft_id = :aid";
    $params['aid'] = $aircraftId;
}

$whereSql = implode(' AND ', $where);
$photos = DB::fetchAll(
    "SELECT p.*, u.username, u.full_name, a.model_name, apt.name_ru AS airport_name, apt.icao AS airport_icao 
     FROM `va_photos` p 
     JOIN `va_users` u ON p.user_id = u.id 
     LEFT JOIN `va_aircraft` a ON p.aircraft_id = a.id 
     LEFT JOIN `va_airports` apt ON p.airport_id = apt.id 
     WHERE {$whereSql} 
     ORDER BY p.id DESC LIMIT 40",
    $params
);

$allAircraft = DB::fetchAll("SELECT `id`, `model_name` FROM `va_aircraft` ORDER BY `model_name` ASC");
$allAirports = DB::fetchAll("SELECT `id`, `name_ru`, `icao` FROM `va_airports` ORDER BY `name_ru` ASC");

$pageTitle = 'Галерея авиационного споттинга';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Title & Action Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-white">Споттерская фотогалерея</h1>
            <p class="text-xs text-slate-400 font-mono mt-1">Авторские снимки бортов со всего мира с полными EXIF метаданными</p>
        </div>

        <div class="flex items-center space-x-3">
            <!-- Filter Tabs -->
            <div class="flex items-center space-x-1 glass-card p-1 rounded-xl text-xs font-mono">
                <a href="spotting.php?filter=all" class="px-3 py-1.5 rounded-lg <?= $filter === 'all' ? 'bg-sky-600 text-white font-bold' : 'text-slate-400 hover:text-white' ?> transition">Все</a>
                <a href="spotting.php?filter=potd" class="px-3 py-1.5 rounded-lg <?= $filter === 'potd' ? 'bg-sky-600 text-white font-bold' : 'text-slate-400 hover:text-white' ?> transition">Фото дня</a>
                <a href="spotting.php?filter=top" class="px-3 py-1.5 rounded-lg <?= $filter === 'top' ? 'bg-sky-600 text-white font-bold' : 'text-slate-400 hover:text-white' ?> transition">Топ недели</a>
            </div>

            <!-- Upload Button -->
            <?php if (Auth::check()): ?>
                <button onclick="openUploadModal()" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs font-mono shadow-lg shadow-amber-500/20 transition flex items-center space-x-1.5">
                    <i data-lucide="upload-cloud" class="w-4 h-4"></i>
                    <span>Загрузить фото</span>
                </button>
            <?php else: ?>
                <a href="login.php" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-mono transition">
                    Вход для загрузки
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Photos Grid -->
    <?php if (empty($photos)): ?>
        <div class="glass-card rounded-3xl p-16 text-center text-slate-500 font-mono">
            <i data-lucide="camera" class="w-12 h-12 mx-auto mb-3 text-slate-600"></i>
            <div>В этой категории пока нет опубликованных снимков.</div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($photos as $ph): ?>
                <div class="glass-card rounded-3xl overflow-hidden border border-white/5 hover:border-sky-500/40 transition group flex flex-col">
                    <div class="relative aspect-[16/10] bg-slate-950 overflow-hidden">
                        <img src="<?= e($ph['medium_url'] ?: $ph['photo_url']) ?>" alt="Spotting" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                        
                        <!-- Badges -->
                        <div class="absolute top-3 left-3 flex items-center space-x-2">
                            <?php if ($ph['tail_number']): ?>
                                <span class="px-2.5 py-1 rounded-lg bg-black/70 text-sky-400 font-mono text-[11px] font-bold border border-sky-500/30 backdrop-blur-md">
                                    <?= e($ph['tail_number']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($ph['is_photo_of_day']): ?>
                                <span class="px-2 py-0.5 rounded-lg bg-amber-500 text-slate-950 font-mono text-[10px] font-bold">
                                    ★ ФОТО ДНЯ
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="p-5 flex-1 flex flex-col justify-between space-y-4">
                        <div>
                            <h3 class="font-bold text-white text-base"><?= e($ph['model_name'] ?: 'Неизвестный борт') ?></h3>
                            <div class="text-xs text-slate-400 mt-0.5 font-mono">
                                📍 <?= e($ph['airport_name'] ? "{$ph['airport_name']} ({$ph['airport_icao']})" : 'Локация не указана') ?>
                            </div>
                        </div>

                        <!-- EXIF Box -->
                        <?php if ($ph['camera_model'] || $ph['focal_length']): ?>
                            <div class="p-3 rounded-xl bg-slate-900/80 border border-white/5 text-[11px] font-mono text-slate-400 space-y-1">
                                <?php if ($ph['camera_model']): ?>
                                    <div class="flex justify-between truncate">
                                        <span>Камера:</span>
                                        <span class="text-slate-200"><?= e($ph['camera_model']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="flex justify-between text-slate-400">
                                    <span><?= e($ph['focal_length'] ?: '') ?> <?= e($ph['aperture'] ?: '') ?></span>
                                    <span>ISO <?= e($ph['iso'] ?: '—') ?> • <?= e($ph['shutter_speed'] ?: '—') ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Footer Info -->
                        <div class="flex items-center justify-between pt-2 border-t border-white/5 text-xs font-mono text-slate-400">
                            <span class="text-sky-400">© <?= e($ph['full_name'] ?: $ph['username']) ?></span>
                            <span><?= e($ph['shot_date'] ?: date('d.m.Y', strtotime($ph['created_at']))) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Upload Photo Modal -->
<?php if (Auth::check()): ?>
<div id="uploadModal" class="fixed inset-0 z-50 bg-black/75 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="max-w-2xl w-full glass-hud rounded-3xl p-6 border border-sky-500/30 shadow-2xl overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-5">
            <h3 class="text-lg font-bold text-white font-mono flex items-center space-x-2">
                <i data-lucide="camera" class="w-5 h-5 text-amber-400"></i>
                <span>Загрузка споттерской фотографии</span>
            </h3>
            <button onclick="closeUploadModal()" class="p-1 rounded hover:bg-white/10 text-slate-400">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <form id="spottingUploadForm" onsubmit="submitPhoto(event)" class="space-y-4 font-mono text-xs">
            <?= csrf_field() ?>

            <!-- File Drag & Drop Box -->
            <div class="border-2 border-dashed border-sky-500/30 hover:border-sky-500 rounded-2xl p-6 text-center cursor-pointer bg-slate-900/40 transition" onclick="document.getElementById('photoFileInput').click()">
                <input type="file" id="photoFileInput" name="photo" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="previewSelectedImage(this)">
                <i data-lucide="image-plus" class="w-10 h-10 text-sky-400 mx-auto mb-2"></i>
                <div class="text-slate-300 font-bold text-sm" id="uploadFileLabel">Выберите или перетащите фото сюда</div>
                <div class="text-[10px] text-slate-500 mt-1">Поддерживаются JPG, PNG, WebP до 25 МБ. EXIF извлекается автоматически.</div>
                <img id="imagePreview" class="hidden max-h-48 mx-auto mt-4 rounded-xl shadow-lg border border-white/10">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Самолёт / Модель</label>
                    <select name="aircraft_id" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <option value="">-- Выберите самолёт --</option>
                        <?php foreach ($allAircraft as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= e($a['model_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Бортовой номер (Регистрация / Tail)</label>
                    <input type="text" name="tail_number" placeholder="например: RA-85757, VP-BOS" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white placeholder-slate-600 uppercase">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Аэропорт съемки</label>
                    <select name="airport_id" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <option value="">-- Выберите аэропорт --</option>
                        <?php foreach ($allAirports as $apt): ?>
                            <option value="<?= $apt['id'] ?>"><?= e($apt['name_ru']) ?> (<?= e($apt['icao']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Лицензия использования</label>
                    <select name="license_type" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
                        <option value="all_rights_reserved">All Rights Reserved (Все права защищены)</option>
                        <option value="cc_by">Creative Commons (CC BY 4.0)</option>
                        <option value="free">Свободное использование</option>
                    </select>
                </div>
            </div>

            <div class="p-3 rounded-xl bg-sky-500/10 border border-sky-500/20 text-sky-300 text-[11px] leading-relaxed">
                ℹ️ На фотографию будет автоматически наложен фирменный водяной знак с вашим именем автора (© <?= e(Auth::user()['full_name'] ?: Auth::user()['username']) ?>).
            </div>

            <div class="flex justify-end space-x-3 pt-3">
                <button type="button" onclick="closeUploadModal()" class="px-5 py-2.5 rounded-xl bg-slate-800 text-slate-300 hover:bg-slate-700">Отмена</button>
                <button type="submit" id="uploadSubmitBtn" class="px-6 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold shadow-lg shadow-amber-500/20">Загрузить фото</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openUploadModal() {
        document.getElementById('uploadModal').classList.remove('hidden');
    }
    function closeUploadModal() {
        document.getElementById('uploadModal').classList.add('hidden');
    }

    function previewSelectedImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const prev = document.getElementById('imagePreview');
                prev.src = e.target.result;
                prev.classList.remove('hidden');
                document.getElementById('uploadFileLabel').innerText = input.files[0].name;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    async function submitPhoto(e) {
        e.preventDefault();
        const form = document.getElementById('spottingUploadForm');
        const formData = new FormData(form);
        const btn = document.getElementById('uploadSubmitBtn');

        btn.disabled = true;
        btn.innerText = 'Обработка и сжатие...';

        try {
            const res = await fetch('api/upload_photo.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert('Ошибка: ' + (data.error || 'Сбой при загрузке'));
            }
        } catch (err) {
            alert('Ошибка отправки файла на сервер.');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Загрузить фото';
        }
    }
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
