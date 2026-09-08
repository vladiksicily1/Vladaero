<?php
$pageTitle = 'Вход в систему — VladAero';
require_once __DIR__ . '/includes/header.php';

if (Auth::isLoggedIn()) {
    header('Location: ' . url('/profile.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $res = Auth::login($login, $password);
    if ($res['success']) {
        setFlash('success', 'С возвращением на борт, ' . htmlspecialchars($res['user']['username']) . '!');
        if ($res['user']['role'] === 'admin') {
            header('Location: ' . url('/admin/'));
        } else {
            header('Location: ' . url('/profile.php'));
        }
        exit;
    } else {
        $error = $res['error'];
    }
}
?>

<div class="max-w-md mx-auto px-4 py-16">
    <div class="va-card p-8 shadow-2xl">
        <div class="text-center mb-8">
            <div class="w-12 h-12 bg-sky-500/10 border border-sky-500/30 rounded-xl flex items-center justify-center mx-auto mb-3 text-sky-400">
                <i data-lucide="log-in" class="w-6 h-6"></i>
            </div>
            <h1 class="text-2xl font-bold text-white">Вход в VladAero</h1>
            <p class="text-xs text-slate-400 mt-1 font-mono">Доступ к логбуку, споттингу и панели управления</p>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 mb-6 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs font-mono space-y-2">
                <div><?= e($error) ?></div>
                <div class="pt-1 border-t border-red-500/20 text-[11px]">
                    Забыли пароль администратора? <a href="<?= url('/install.php') ?>" class="text-amber-400 font-bold hover:underline">Сбросить в 1 клик через install.php →</a>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4 text-xs font-mono">
            <?= Auth::csrfField() ?>

            <div>
                <label class="block text-slate-400 mb-1">Логин или Email:</label>
                <input type="text" name="login" required autofocus placeholder="admin или pilot@vladinc.ru" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-500 focus:border-sky-500">
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-slate-400">Пароль:</label>
                    <a href="<?= url('/install.php') ?>" class="text-[11px] text-sky-400 hover:underline">Сброс пароля</a>
                </div>
                <input type="password" name="password" required placeholder="Ваш пароль" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-500 focus:border-sky-500">
            </div>

            <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white font-bold py-3.5 rounded-xl shadow-lg transition">
                Войти на борт
            </button>
        </form>

        <div class="mt-6 pt-6 border-t border-slate-800 text-center text-xs text-slate-400 font-mono">
            Еще нет учетной записи? <a href="<?= url('/register.php') ?>" class="text-sky-400 hover:underline">Зарегистрироваться</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
