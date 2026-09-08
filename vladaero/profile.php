<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];

// Handle GDPR Data Export
if (isset($_GET['export_gdpr'])) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="vladaero_userdata_' . $user['username'] . '.json"');
    $exportData = [
        'user'         => $user,
        'flight_logs'  => DB::fetchAll("SELECT * FROM `va_flight_logs` WHERE `user_id` = :id", ['id' => $userId]),
        'photos'       => DB::fetchAll("SELECT * FROM `va_photos` WHERE `user_id` = :id", ['id' => $userId]),
        'achievements' => DB::fetchAll("SELECT a.*, ua.unlocked_at FROM `va_user_achievements` ua JOIN `va_achievements` a ON ua.achievement_id = a.id WHERE ua.user_id = :id", ['id' => $userId])
    ];
    echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Generate Telegram Code if requested
$tgCode = null;
if (isset($_GET['generate_tg_code'])) {
    $tgCode = Auth::generateTelegramAuthCode($userId);
}

// Fetch user stats & achievements
$achievements = DB::fetchAll("SELECT a.*, ua.unlocked_at FROM `va_user_achievements` ua JOIN `va_achievements` a ON ua.achievement_id = a.id WHERE ua.user_id = :id ORDER BY ua.unlocked_at DESC", ['id' => $userId]);
$userPhotos = DB::fetchAll("SELECT * FROM `va_photos` WHERE `user_id` = :id ORDER BY `id` DESC LIMIT 6", ['id' => $userId]);
$userFlightsCount = (int)DB::fetchValue("SELECT COUNT(*) FROM `va_flight_logs` WHERE `user_id` = :id", ['id' => $userId]);

$pageTitle = "Личный кабинет пилота: {$user['username']}";
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Profile Header Card -->
    <div class="glass-hud rounded-3xl p-8 border border-sky-500/30 mb-10 shadow-2xl flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div class="flex items-center space-x-5">
            <div class="w-20 h-20 rounded-2xl bg-gradient-to-tr from-sky-600 to-indigo-600 flex items-center justify-center text-2xl font-bold text-white font-mono shadow-xl shadow-sky-600/30">
                <?= strtoupper(substr($user['username'], 0, 2)) ?>
            </div>
            <div class="space-y-1">
                <div class="flex items-center space-x-2">
                    <h1 class="text-2xl font-bold text-white"><?= e($user['full_name'] ?: $user['username']) ?></h1>
                    <span class="px-2.5 py-0.5 rounded-lg bg-amber-500/10 text-amber-400 font-mono text-xs font-bold border border-amber-500/30">
                        <?= e($user['rank_title']) ?>
                    </span>
                </div>
                <div class="text-xs text-slate-400 font-mono">@<?= e($user['username']) ?> • Репутация: <?= (int)$user['reputation'] ?></div>
                <div class="text-xs text-sky-400 font-mono font-bold"><?= (int)$user['xp_points'] ?> Очков Опыта (XP)</div>
            </div>
        </div>

        <div class="flex items-center space-x-3">
            <a href="profile.php?export_gdpr=1" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-mono transition flex items-center space-x-1.5">
                <i data-lucide="download" class="w-3.5 h-3.5"></i>
                <span>GDPR Выгрузка данных</span>
            </a>
            <a href="logout.php" class="px-4 py-2.5 rounded-xl bg-rose-600/20 hover:bg-rose-600/30 text-rose-300 text-xs font-mono border border-rose-500/30 transition">
                Выход
            </a>
        </div>
    </div>

    <!-- Telegram Sync Card -->
    <div class="glass-card rounded-3xl p-6 border border-sky-500/20 mb-10 space-y-3 font-mono text-xs">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2 text-sky-400 font-bold">
                <i data-lucide="send" class="w-4 h-4"></i>
                <span>Привязка Telegram аккаунта</span>
            </div>
            <?php if ($user['telegram_id']): ?>
                <span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 font-bold">Привязан (ID: <?= e($user['telegram_id']) ?>)</span>
            <?php endif; ?>
        </div>

        <p class="text-slate-300 leading-relaxed">
            Привязка к Telegram позволяет мгновенно входить на сайт по одноразовому коду без пароля, а также получать алерты Squawk 7700 и уведомления о модерации фото.
        </p>

        <?php if ($tgCode): ?>
            <div class="p-4 rounded-2xl bg-slate-900 border border-sky-500/30 text-center space-y-1">
                <div class="text-slate-400 text-[10px] uppercase">Ваш одноразовый код:</div>
                <div class="text-2xl font-bold text-amber-400 tracking-widest"><?= e($tgCode) ?></div>
                <div class="text-[10px] text-slate-500">Отправьте команду /login в нашем боте @VladAeroBot</div>
            </div>
        <?php else: ?>
            <a href="profile.php?generate_tg_code=1" class="inline-block px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold transition">
                Сгенерировать код привязки
            </a>
        <?php endif; ?>
    </div>

    <!-- Unlocked Achievements -->
    <div class="mb-10">
        <h3 class="text-xl font-bold text-white mb-4">Награды и достижения</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ($achievements as $ach): ?>
                <div class="glass-card p-5 rounded-2xl border border-white/5 space-y-2 flex flex-col justify-between">
                    <div class="space-y-1">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center mb-2">
                            <i data-lucide="award" class="w-5 h-5"></i>
                        </div>
                        <h4 class="font-bold text-white text-sm"><?= e($ach['title']) ?></h4>
                        <p class="text-xs text-slate-400 leading-relaxed"><?= e($ach['description']) ?></p>
                    </div>
                    <div class="text-[10px] font-mono text-emerald-400 pt-2 border-t border-white/5">
                        Открыто: <?= date('d.m.Y', strtotime($ach['unlocked_at'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
