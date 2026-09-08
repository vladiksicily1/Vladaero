<?php
$pageTitle = 'Регистрация нового пилота — VladAero';
require_once __DIR__ . '/includes/header.php';

if (Auth::isLoggedIn()) {
    header('Location: ' . url('/profile.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка проверки CSRF токена';
    } elseif (empty($_POST['consent_152fz'])) {
        $error = 'Для регистрации необходимо дать согласие на обработку персональных данных (152-ФЗ)';
    } else {
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');

        $res = Auth::register($email, $username, $password, $fullName, true);
        if ($res['success']) {
            Auth::login($username, $password);
            setFlash('success', 'Добро пожаловать в экипаж VladAero! Вам начислено 100 XP.');
            header('Location: ' . url('/profile.php'));
            exit;
        } else {
            $error = $res['error'];
        }
    }
}
?>

<div class="max-w-md mx-auto px-4 py-12">
    <div class="va-card p-8 shadow-2xl">
        <div class="text-center mb-8">
            <div class="w-12 h-12 bg-sky-500/10 border border-sky-500/30 rounded-xl flex items-center justify-center mx-auto mb-3 text-sky-400">
                <i data-lucide="user-plus" class="w-6 h-6"></i>
            </div>
            <h1 class="text-2xl font-bold text-white">Регистрация в экипаж</h1>
            <p class="text-xs text-slate-400 mt-1 font-mono">Создайте профиль и начните копить летный опыт (+100 XP)</p>
        </div>

        <?php if ($error): ?>
            <div class="p-3 mb-6 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs font-mono">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4 text-xs font-mono">
            <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">

            <div>
                <label class="block text-slate-400 mb-1">Email адрес:</label>
                <input type="email" name="email" required placeholder="pilot@vladinc.ru" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-600 focus:border-sky-500">
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Позывной / Логин (Латиницей):</label>
                <input type="text" name="username" required placeholder="pilot_vlad" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-600 focus:border-sky-500">
            </div>

            <div>
                <label class="block text-slate-400 mb-1">ФИО (Отображаемое имя):</label>
                <input type="text" name="full_name" placeholder="Владислав Иванов" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-600 focus:border-sky-500 font-sans">
            </div>

            <div>
                <label class="block text-slate-400 mb-1">Пароль (от 6 символов):</label>
                <input type="password" name="password" required minlength="6" placeholder="••••••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-600 focus:border-sky-500">
            </div>

            <!-- 152-FZ Mandatory Consent Checkbox -->
            <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-start space-x-3">
                <input type="checkbox" name="consent_152fz" id="consent_152fz" value="1" required checked class="mt-1 rounded bg-slate-900 border-slate-700 text-sky-600 focus:ring-0">
                <label for="consent_152fz" class="text-[11px] text-slate-400 leading-tight">
                    Я даю согласие на обработку персональных данных в соответствии с <a href="<?= url('/privacy.php') ?>" target="_blank" class="text-sky-400 hover:underline">152-ФЗ РФ</a> и согласен с <a href="<?= url('/terms.php') ?>" target="_blank" class="text-sky-400 hover:underline">правилами сервиса</a>.
                </label>
            </div>

            <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3.5 rounded-xl shadow-lg transition">
                Завершить регистрацию
            </button>
        </form>

        <div class="mt-6 pt-6 border-t border-slate-800 text-center text-xs text-slate-400 font-mono">
            Уже зарегистрированы? <a href="<?= url('/login.php') ?>" class="text-sky-400 hover:underline">Войти</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
