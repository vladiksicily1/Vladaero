<?php
declare(strict_types=1);

namespace VladAero;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (Auth::check()) {
    header('Location: profile.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности (CSRF). Повторите попытку.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');

        $res = Auth::register($username, $email, $pass, $fullName);
        if ($res['success']) {
            header('Location: profile.php');
            exit;
        } else {
            $error = $res['error'];
        }
    }
}

$pageTitle = 'Регистрация курсанта';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto px-4 py-16">
    <div class="glass-hud rounded-3xl p-8 border border-sky-500/30 shadow-2xl space-y-6">
        
        <div class="text-center space-y-2">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-sky-600 to-indigo-600 flex items-center justify-center text-white mx-auto shadow-lg shadow-sky-600/30">
                <i data-lucide="user-plus" class="w-6 h-6"></i>
            </div>
            <h1 class="text-2xl font-bold text-white font-sans">Регистрация в VladAero</h1>
            <p class="text-xs text-slate-400 font-mono">Получите звание «Курсант» и начните вести личный Flight Log</p>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-mono">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4 font-mono text-xs">
            <?= csrf_field() ?>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Позывной / Имя пользователя (латиница)</label>
                <input type="text" name="username" required placeholder="pilot_vladimir" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Полное имя (для сертификатов и фото)</label>
                <input type="text" name="full_name" placeholder="Владимир Иванов" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Email адрес</label>
                <input type="email" name="email" required placeholder="pilot@example.com" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Пароль (минимум 6 символов)</label>
                <input type="password" name="password" required placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white font-bold transition shadow-lg shadow-sky-600/30">
                Зарегистрироваться
            </button>
        </form>

        <div class="text-center pt-2 text-xs font-mono text-slate-400">
            Уже есть аккаунт? <a href="login.php" class="text-sky-400 hover:underline">Вход</a>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
