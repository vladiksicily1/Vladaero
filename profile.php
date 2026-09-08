<?php
$pageTitle = 'Личный Профиль Пилота';
require_once __DIR__ . '/includes/header.php';

Auth::requireLogin();
$user = Auth::getCurrentUser();

// Handle 152-FZ Account Deletion POST ("Right to be Forgotten")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account_152fz'])) {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ошибка CSRF проверки');
    } else {
        $confirm = trim($_POST['confirm_phrase'] ?? '');
        if ($confirm === 'УДАЛИТЬ МОЙ АККАУНТ') {
            Auth::deleteAccount152Fz($user['id']);
            setFlash('success', 'Ваш аккаунт и персональные данные полностью удалены в соответствии с 152-ФЗ.');
            header('Location: ' . url('/'));
            exit;
        } else {
            setFlash('error', 'Неверная фраза подтверждения удаления!');
        }
    }
}

// Handle Profile Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (Auth::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $fullName = trim($_POST['full_name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $uTable = Database::tableName('users');
        Database::update('users', ['full_name' => $fullName, 'bio' => $bio], 'id = :id', ['id' => $user['id']]);
        setFlash('success', 'Профиль успешно обновлен!');
        header('Location: ' . url('/profile.php'));
        exit;
    }
}
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- Profile Hero Card -->
    <div class="va-card p-6 sm:p-8">
        <div class="flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-6">
            <div class="w-20 h-20 rounded-2xl bg-gradient-to-tr from-sky-600 to-cyan-400 flex items-center justify-center text-white text-3xl font-black shadow-xl shadow-sky-500/20">
                <?= mb_strtoupper(mb_substr($user['username'], 0, 1)) ?>
            </div>
            <div class="text-center sm:text-left flex-grow">
                <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 mb-1">
                    <h1 class="text-2xl font-bold text-white"><?= e($user['full_name'] ?: $user['username']) ?></h1>
                    <span class="px-2.5 py-0.5 rounded-lg bg-sky-500/10 border border-sky-500/30 text-sky-400 font-mono text-xs font-bold">
                        <?= e($user['rank_title']) ?>
                    </span>
                </div>
                <div class="text-xs text-slate-400 font-mono">@<?= e($user['username']) ?> • <?= e($user['email']) ?></div>
                <div class="text-xs text-slate-500 font-mono mt-1">В клубе с <?= formatDate($user['created_at']) ?></div>
            </div>
        </div>

        <!-- Rank XP Progress Bar -->
        <div class="mt-6 pt-6 border-t border-slate-800 space-y-2 font-mono text-xs">
            <div class="flex justify-between">
                <span class="text-slate-400">Очки опыта летного мастерства:</span>
                <span class="text-sky-400 font-bold"><?= formatNumber($user['xp_points']) ?> XP</span>
            </div>
            <div class="w-full h-2.5 bg-slate-950 rounded-full overflow-hidden border border-slate-800">
                <div class="h-full bg-gradient-to-r from-sky-500 to-cyan-400 rounded-full" style="width: <?= min(100, max(5, ($user['xp_points'] % 1000) / 10)) ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Edit Profile Form -->
    <div class="va-card p-6 sm:p-8 space-y-4">
        <h2 class="text-base font-bold text-white font-mono uppercase">Редактирование профиля</h2>
        <form method="POST" class="space-y-4 text-xs font-mono">
            <input type="hidden" name="update_profile" value="1">
            <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">

            <div>
                <label class="block text-slate-400 mb-1">ФИО / Отображаемое имя:</label>
                <input type="text" name="full_name" value="<?= e($user['full_name']) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-sans">
            </div>

            <div>
                <label class="block text-slate-400 mb-1">О себе / Домашний аэродром:</label>
                <textarea name="bio" rows="3" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-slate-100 font-sans"><?= e($user['bio'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="bg-sky-600 hover:bg-sky-500 text-white font-bold py-2.5 px-5 rounded-xl shadow-md transition">
                Сохранить изменения
            </button>
        </form>
    </div>

    <!-- 152-FZ Compliance: 1-Click "Right to be Forgotten" Account Erasure -->
    <div class="bg-red-950/20 border border-red-500/30 rounded-2xl p-6 sm:p-8 space-y-4">
        <div class="flex items-center space-x-2 text-red-400 font-bold font-mono text-sm">
            <i data-lucide="shield-alert" class="w-5 h-5"></i>
            <span>152-ФЗ: Право на забвение (Полное удаление аккаунта)</span>
        </div>
        <p class="text-xs text-slate-400 leading-relaxed">
            В соответствии с Федеральным законом № 152-ФЗ «О персональных данных», вы можете в 1 клик отозвать согласие на обработку персональных данных и безвозвратно стереть свой аккаунт, историю логбука и сессии.
        </p>

        <form method="POST" onsubmit="return confirm('Вы абсолютно уверены, что хотите навсегда стереть свой профиль и персональные данные?');" class="space-y-3 font-mono text-xs">
            <input type="hidden" name="delete_account_152fz" value="1">
            <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">

            <div>
                <label class="block text-slate-400 mb-1">Для подтверждения введите точную фразу: <strong class="text-red-400">УДАЛИТЬ МОЙ АККАУНТ</strong></label>
                <input type="text" name="confirm_phrase" required placeholder="УДАЛИТЬ МОЙ АККАУНТ" class="w-full sm:w-96 bg-slate-950 border border-red-500/40 rounded-xl px-3 py-2 text-red-400">
            </div>

            <button type="submit" class="bg-red-600 hover:bg-red-500 text-white font-bold py-2.5 px-5 rounded-xl transition">
                Безвозвратно удалить аккаунт (152-ФЗ)
            </button>
        </form>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
