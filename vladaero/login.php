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
        $tgCode = trim($_POST['tg_code'] ?? '');
        $login = trim($_POST['login'] ?? '');
        $pass = $_POST['password'] ?? '';

        if (!empty($tgCode)) {
            $res = Auth::loginByTelegramCode($tgCode);
            if ($res['success']) {
                header('Location: profile.php');
                exit;
            } else {
                $error = $res['error'];
            }
        } elseif (!empty($login) && !empty($pass)) {
            $res = Auth::attempt($login, $pass);
            if ($res['success']) {
                header('Location: profile.php');
                exit;
            } else {
                $error = $res['error'];
            }
        } else {
            $error = 'Заполните поля для входа.';
        }
    }
}

$pageTitle = 'Авторизация экипажа';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto px-4 py-16">
    <div class="glass-hud rounded-3xl p-8 border border-sky-500/30 shadow-2xl space-y-6">
        
        <div class="text-center space-y-2">
            <div class="w-12 h-12 rounded-2xl bg-sky-600 flex items-center justify-center text-white mx-auto shadow-lg shadow-sky-600/30">
                <i data-lucide="lock" class="w-6 h-6"></i>
            </div>
            <h1 class="text-2xl font-bold text-white font-sans">Вход в систему VladAero</h1>
            <p class="text-xs text-slate-400 font-mono">Авторизация по логину или одноразовому коду Telegram</p>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-mono">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Telegram Auth Code Box -->
        <form method="POST" class="p-4 rounded-2xl bg-slate-900/90 border border-sky-500/30 space-y-3 font-mono text-xs">
            <?= csrf_field() ?>
            <label class="block text-sky-400 uppercase font-bold text-[10px]">Вход по коду из Telegram</label>
            <input type="text" name="tg_code" placeholder="6-значный код из @VladAeroBot" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white text-center tracking-widest text-base font-bold uppercase placeholder-slate-600">
            <button type="submit" class="w-full py-2.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold transition shadow-md shadow-sky-600/20">
                Войти по коду Telegram
            </button>
        </form>

        <div class="flex items-center space-x-2 my-2 text-slate-500 text-xs font-mono">
            <div class="flex-1 border-t border-white/5"></div>
            <span>ИЛИ</span>
            <div class="flex-1 border-t border-white/5"></div>
        </div>

        <!-- Classic Login/Password -->
        <form method="POST" class="space-y-4 font-mono text-xs">
            <?= csrf_field() ?>
            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Email или Имя пользователя</label>
                <input type="text" name="login" required placeholder="admin@vladaero.ru" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <div>
                <label class="block text-slate-400 uppercase font-bold text-[10px] mb-1">Пароль</label>
                <input type="password" name="password" required placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white">
            </div>

            <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white font-bold transition shadow-lg shadow-sky-600/30">
                Войти по паролю
            </button>
        </form>

        <div class="text-center pt-2 text-xs font-mono text-slate-400">
            Нет аккаунта? <a href="register.php" class="text-sky-400 hover:underline">Регистрация</a>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
