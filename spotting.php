<?php
$pageTitle = 'Авиаспоттинг — Галерея фотографий и Карта точек';
$metaDescription = 'Галерея авиационной фотографии, база споттерских снимков с подробными EXIF данными камеры, объектива и экспозиции. Карта лучших точек споттинга.';
require_once __DIR__ . '/includes/header.php';

$photosTable = Database::tableName('photos');
$aircraftTable = Database::tableName('aircraft');
$airportsTable = Database::tableName('airports');
$usersTable = Database::tableName('users');

$photos = Database::isConfigured() ? Database::fetchAll("SELECT p.*, u.username, a.model_name, ap.name_ru as airport_name, ap.icao FROM `{$photosTable}` p LEFT JOIN `{$usersTable}` u ON p.user_id = u.id LEFT JOIN `{$aircraftTable}` a ON p.aircraft_id = a.id LEFT JOIN `{$airportsTable}` ap ON p.airport_id = ap.id WHERE p.status = 'approved' ORDER BY p.id DESC LIMIT 24") : [];

$allAircraft = Database::isConfigured() ? Database::fetchAll("SELECT id, model_name FROM `{$aircraftTable}` ORDER BY model_name ASC") : [];
$allAirports = Database::isConfigured() ? Database::fetchAll("SELECT id, icao, name_ru FROM `{$airportsTable}` ORDER BY name_ru ASC") : [];
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header & Upload Trigger -->
    <div class="va-card p-6 sm:p-8 mb-8 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center space-x-3">
                <i data-lucide="camera" class="w-8 h-8 text-sky-400"></i>
                <span>Авиаспоттинг и Медиа-Хаб</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1 font-mono">
                Каталог авторских авиафотографий с автоматическим считыванием EXIF-параметров съемки
            </p>
        </div>

        <?php if (Auth::isLoggedIn()): ?>
            <button onclick="toggleUploadModal()" class="bg-sky-600 hover:bg-sky-500 text-white font-mono font-bold text-xs px-5 py-3 rounded-xl shadow-lg transition flex items-center space-x-2">
                <i data-lucide="upload" class="w-4 h-4"></i>
                <span>Загрузить фото (+50 XP)</span>
            </button>
        <?php else: ?>
            <a href="<?= url('/login.php') ?>" class="bg-slate-800 hover:bg-slate-700 text-sky-400 border border-slate-700 font-mono font-bold text-xs px-5 py-3 rounded-xl transition flex items-center space-x-2">
                <i data-lucide="log-in" class="w-4 h-4"></i>
                <span>Войдите для загрузки</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- Spotting Photos Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
        <?php if (empty($photos)): ?>
            <div class="col-span-full va-card p-12 text-center text-slate-500 font-mono text-xs">
                Галерея споттинга готова к приему первых потрясающих снимков.
            </div>
        <?php else: ?>
            <?php foreach ($photos as $p): ?>
                <div class="va-card overflow-hidden group flex flex-col justify-between">
                    <div class="relative overflow-hidden aspect-[3/2] bg-slate-950">
                        <img src="<?= e($p['medium_url'] ?: $p['photo_url']) ?>" alt="<?= e($p['model_name']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                        <?php if ($p['tail_number']): ?>
                            <div class="absolute top-3 right-3 px-2 py-0.5 rounded bg-slate-950/80 backdrop-blur-md text-[10px] font-mono font-bold text-sky-400 border border-slate-800">
                                <?= e($p['tail_number']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="p-4 space-y-3 font-mono text-xs">
                        <div class="flex items-center justify-between">
                            <h3 class="font-bold text-slate-100 truncate"><?= e($p['model_name'] ?? 'Воздушное судно') ?></h3>
                            <span class="text-[10px] text-slate-500"><?= formatDate($p['shot_date']) ?></span>
                        </div>

                        <div class="flex items-center justify-between text-[11px] text-slate-400">
                            <span><?= e($p['airport_name'] ?: ($p['icao'] ?? 'Аэродром')) ?></span>
                            <span class="text-sky-400">@<?= e($p['username']) ?></span>
                        </div>

                        <!-- EXIF Pill -->
                        <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800 text-[10px] text-slate-400 truncate">
                            📷 <?= e($p['camera_model'] ?: 'DSLR') ?> <?= $p['shutter_speed'] ? "• {$p['shutter_speed']}" : '' ?> <?= $p['aperture'] ? "• {$p['aperture']}" : '' ?> <?= $p['iso'] ? "• {$p['iso']}" : '' ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Upload Modal -->
<?php if (Auth::isLoggedIn()): ?>
    <div id="upload-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl p-6 relative">
            <button onclick="toggleUploadModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>

            <h2 class="text-lg font-bold text-white mb-1 flex items-center space-x-2">
                <i data-lucide="camera" class="w-5 h-5 text-sky-400"></i>
                <span>Загрузка фото в галерею</span>
            </h2>
            <p class="text-xs text-slate-400 mb-4 font-mono">EXIF-параметры камеры и оптики считаются автоматически</p>

            <form id="spotting-upload-form" onsubmit="handlePhotoUpload(event)" class="space-y-4 text-xs font-mono">
                <div>
                    <label class="block text-slate-300 mb-1">Файл изображения (JPG, PNG, WebP):</label>
                    <input type="file" id="photo-file" accept="image/*" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2 text-slate-300">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-slate-400 mb-1">Бортовой номер (Tail #):</label>
                        <input type="text" id="photo-tail" placeholder="RA-89010, VP-BOS..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 uppercase">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1">Модель самолета:</label>
                        <select id="photo-aircraft" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                            <option value="">— Выбрать ВС —</option>
                            <?php foreach ($allAircraft as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= e($a['model_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-slate-400 mb-1">Аэропорт съёмки:</label>
                    <select id="photo-airport" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                        <option value="">— Выбрать аэродром —</option>
                        <?php foreach ($allAirports as $ap): ?>
                            <option value="<?= $ap['id'] ?>"><?= e($ap['icao']) ?> — <?= e($ap['name_ru']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" id="upload-submit-btn" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3 rounded-xl shadow-lg transition">
                    Опубликовать снимок
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
    function toggleUploadModal() {
        const modal = document.getElementById('upload-modal');
        if (modal) modal.classList.toggle('hidden');
    }

    function handlePhotoUpload(e) {
        e.preventDefault();
        const fileInput = document.getElementById('photo-file');
        if (!fileInput.files[0]) return;

        const btn = document.getElementById('upload-submit-btn');
        btn.disabled = true;
        btn.innerText = 'Считывание EXIF и загрузка...';

        const formData = new FormData();
        formData.append('photo', fileInput.files[0]);
        formData.append('tail_number', document.getElementById('photo-tail').value);
        formData.append('aircraft_id', document.getElementById('photo-aircraft').value);
        formData.append('airport_id', document.getElementById('photo-airport').value);

        fetch('<?= url('/api/upload_photo.php') ?>', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Фотография успешно загружена и отправлена в галерею!');
                location.reload();
            } else {
                alert('Ошибка: ' + (data.error || 'Не удалось загрузить фото'));
                btn.disabled = false;
                btn.innerText = 'Опубликовать снимок';
            }
        })
        .catch(err => {
            alert('Ошибка сети при загрузке');
            btn.disabled = false;
            btn.innerText = 'Опубликовать снимок';
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
