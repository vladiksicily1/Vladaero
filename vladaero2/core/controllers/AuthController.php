<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

class AuthController extends Controller
{
    public function loginForm()
    {
        if (Session::isLoggedIn()) $this->redirect('/');
        $this->view('pages.auth.login');
    }

    public function registerForm()
    {
        if (Session::isLoggedIn()) $this->redirect('/');
        $this->view('pages.auth.register');
    }

    public function login()
    {
        $this->verifyCsrf();

        $login = $this->input('login');
        $password = $_POST['password'] ?? '';

        if (empty($login) || empty($password)) {
            Session::setFlash('error', 'Заполните все поля');
            $this->redirect('/auth/login');
        }

        $db = $this->db;
        $prefix = $db->prefix();
        $user = $db->fetchOne(
            "SELECT * FROM {$prefix}users WHERE (email = :l1 OR username = :l2 OR telegram_username = :l3) AND status = 'active'",
            ['l1' => $login, 'l2' => $login, 'l3' => $login]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Session::setFlash('error', 'Неверный логин или пароль');
            $this->redirect('/auth/login');
        }

        // Check if 2FA is enabled and Telegram is linked
        if (!empty($user['tfa_enabled']) && !empty($user['telegram_id'])) {
            $code = sprintf('%06d', random_int(100000, 999999));
            $db->update('users', [
                'tfa_secret' => password_hash($code, PASSWORD_DEFAULT),
            ], 'id = :id', ['id' => $user['id']]);

            Session::set('2fa_user_id', (int)$user['id']);
            Session::set('2fa_expires', time() + 300);

            $sent = sendTelegramMessage(
                $user['telegram_id'],
                "🔐 <b>Код двухфакторной аутентификации VladAero:</b>\n\n<code>{$code}</code>\n\nКод действителен 5 минут. Если это были не вы, срочно смените пароль."
            );

            if ($sent) {
                Session::setFlash('info', 'Код подтверждения отправлен в ваш Telegram');
            } else {
                Session::setFlash('warning', 'Не удалось отправить сообщение в Telegram. Проверьте настройки бота.');
            }

            $this->redirect('/auth/2fa');
        }

        // Update last login
        $db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $user['id']]);

        Session::setAuth($user);
        Session::setFlash('success', 'Добро пожаловать, ' . e($user['display_name']) . '!');
        $this->redirect('/');
    }

    public function twoFactorForm()
    {
        $userId = Session::get('2fa_user_id');
        $expires = Session::get('2fa_expires');

        if (!$userId || time() > $expires) {
            Session::remove('2fa_user_id');
            Session::remove('2fa_expires');
            Session::setFlash('error', 'Сессия 2FA истекла. Войдите заново.');
            $this->redirect('/auth/login');
        }

        $prefix = $this->db->prefix();
        $user = $this->db->fetchOne("SELECT id, username, display_name, telegram_username FROM {$prefix}users WHERE id = :id", ['id' => $userId]);

        $this->view('pages.auth.2fa', ['user' => $user]);
    }

    public function twoFactorVerify()
    {
        $this->verifyCsrf();

        $userId = Session::get('2fa_user_id');
        $expires = Session::get('2fa_expires');
        $code = trim($this->input('code') ?? '');

        if (!$userId || time() > $expires) {
            Session::remove('2fa_user_id');
            Session::remove('2fa_expires');
            Session::setFlash('error', 'Срок действия кода истек. Войдите заново.');
            $this->redirect('/auth/login');
        }

        if (empty($code)) {
            Session::setFlash('error', 'Введите 6-значный код из Telegram');
            $this->redirect('/auth/2fa');
        }

        $prefix = $this->db->prefix();
        $user = $this->db->fetchOne("SELECT * FROM {$prefix}users WHERE id = :id", ['id' => $userId]);

        if (!$user || empty($user['tfa_secret']) || !password_verify($code, $user['tfa_secret'])) {
            Session::setFlash('error', 'Неверный код подтверждения');
            $this->redirect('/auth/2fa');
        }

        // Clear 2FA temporary secret
        $this->db->update('users', [
            'tfa_secret' => null,
            'last_login_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);

        Session::remove('2fa_user_id');
        Session::remove('2fa_expires');

        Session::setAuth($user);
        Session::setFlash('success', 'Двухфакторная проверка пройдена! Добро пожаловать, ' . e($user['display_name']) . '!');
        $this->redirect('/');
    }

    public function twoFactorResend()
    {
        $this->verifyCsrf();

        $userId = Session::get('2fa_user_id');
        if (!$userId) {
            $this->redirect('/auth/login');
        }

        $prefix = $this->db->prefix();
        $user = $this->db->fetchOne("SELECT * FROM {$prefix}users WHERE id = :id", ['id' => $userId]);

        if ($user && !empty($user['telegram_id'])) {
            $code = sprintf('%06d', random_int(100000, 999999));
            $this->db->update('users', [
                'tfa_secret' => password_hash($code, PASSWORD_DEFAULT),
            ], 'id = :id', ['id' => $user['id']]);

            Session::set('2fa_expires', time() + 300);

            sendTelegramMessage(
                $user['telegram_id'],
                "🔄 <b>Новый код подтверждения входа VladAero:</b>\n\n<code>{$code}</code>\n\nКод действителен 5 минут."
            );

            Session::setFlash('success', 'Новый код отправлен в ваш Telegram');
        }

        $this->redirect('/auth/2fa');
    }

    public function register()
    {
        $this->verifyCsrf();

        $username = $this->input('username');
        $email = $this->input('email');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $displayName = $this->input('display_name') ?: $username;

        // Validate
        $errors = $this->validate($_POST, [
            'username' => 'required|min:3|max:50',
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Пароли не совпадают';
        }

        if ($errors) {
            foreach ($errors as $msg) {
                Session::setFlash('error', $msg);
            }
            $this->redirect('/auth/register');
        }

        $db = $this->db;
        $prefix = $db->prefix();

        // Check unique
        $exists = $db->fetchOne("SELECT id FROM {$prefix}users WHERE username = :u OR email = :e", ['u' => $username, 'e' => $email]);
        if ($exists) {
            Session::setFlash('error', 'Пользователь с таким логином или email уже существует');
            $this->redirect('/auth/register');
        }

        $userId = $db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $displayName,
            'role' => 'user',
            'rank' => 'Курсант',
            'status' => 'active',
        ]);

        $user = $db->fetchOne("SELECT * FROM {$prefix}users WHERE id = :id", ['id' => $userId]);
        Session::setAuth($user);
        Session::setFlash('success', 'Регистрация прошла успешно! Добро пожаловать!');
        $this->redirect('/');
    }

    public function logout()
    {
        Session::logout();
        Session::setFlash('success', 'Вы вышли из аккаунта');
        $this->redirect('/');
    }

    public function telegramAuth()
    {
        $botToken = setting('telegram_bot_token', $this->config['telegram']['bot_token'] ?? '');
        if (empty($botToken)) {
            Session::setFlash('error', 'Telegram авторизация не настроена в настройках сайта.');
            $this->redirect('/auth/login');
        }

        $state = bin2hex(random_bytes(16));
        Session::set('tg_auth_state', $state);

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $origin = $proto . $host;
        $returnTo = $proto . $host . url('/auth/telegram/callback?state=' . $state);

        $botId = explode(':', $botToken)[0];
        $authUrl = "https://oauth.telegram.org/auth?bot_id={$botId}"
                 . "&origin=" . urlencode($origin)
                 . "&return_to=" . urlencode($returnTo)
                 . "&state={$state}";

        $this->redirect($authUrl);
    }

    public function telegramCallback()
    {
        $state = $_GET['state'] ?? '';
        $savedState = Session::get('tg_auth_state');

        if ($state !== $savedState) {
            Session::setFlash('error', 'Невалидный state авторизации');
            $this->redirect('/auth/login');
        }
        Session::remove('tg_auth_state');

        // Get user data from Telegram
        $tgUser = $_GET['user'] ?? null;
        if (!$tgUser) {
            Session::setFlash('error', 'Не удалось получить данные Telegram');
            $this->redirect('/auth/login');
        }

        $tgData = json_decode(urldecode($tgUser), true);
        $tgId = $tgData['id'] ?? null;
        $tgUsername = $tgData['username'] ?? '';

        if (!$tgId) {
            Session::setFlash('error', 'Невалидные данные Telegram');
            $this->redirect('/auth/login');
        }

        $db = $this->db;
        $prefix = $db->prefix();

        // Find user by telegram_id
        $user = $db->fetchOne("SELECT * FROM {$prefix}users WHERE telegram_id = :tg_id", ['tg_id' => $tgId]);

        if ($user) {
            // Login existing user
            Session::setAuth($user);
            Session::setFlash('success', 'Вы вошли через Telegram!');
            $this->redirect('/');
        }

        // Check if linking to existing account
        $currentUser = Session::getAuth();
        if ($currentUser) {
            // Link Telegram to existing account
            $db->update('users', [
                'telegram_id' => $tgId,
                'telegram_username' => $tgUsername,
                'telegram_linked' => 1,
            ], 'id = :id', ['id' => $currentUser['id']]);

            $currentUser['telegram_id'] = $tgId;
            $currentUser['telegram_username'] = $tgUsername;
            $currentUser['telegram_linked'] = 1;
            Session::setAuth($currentUser);
            Session::setFlash('success', 'Telegram привязан к аккаунту!');
            $this->redirect('/settings');
        }

        // Create new user from Telegram
        $username = $tgUsername ?: 'tg_' . $tgId;
        $displayName = $tgData['first_name'] ?? $username;

        // Ensure unique username
        $existing = $db->fetchOne("SELECT id FROM {$prefix}users WHERE username = :u", ['u' => $username]);
        if ($existing) {
            $username = $username . '_' . $tgId;
        }

        $userId = $db->insert('users', [
            'username' => $username,
            'display_name' => $displayName,
            'telegram_id' => $tgId,
            'telegram_username' => $tgUsername,
            'telegram_linked' => 1,
            'role' => 'user',
            'rank' => 'Курсант',
            'status' => 'active',
        ]);

        $user = $db->fetchOne("SELECT * FROM {$prefix}users WHERE id = :id", ['id' => $userId]);
        Session::setAuth($user);
        Session::setFlash('success', 'Аккаунт создан через Telegram! Добро пожаловать!');
        $this->redirect('/');
    }

    public function passwordResetForm()
    {
        $this->view('pages.auth.password_reset');
    }

    public function passwordReset()
    {
        $this->verifyCsrf();
        // Telegram-based password reset (no email)
        $login = $this->input('login');
        $db = $this->db;
        $prefix = $db->prefix();

        $user = $db->fetchOne(
            "SELECT * FROM {$prefix}users WHERE (email = :l1 OR username = :l2) AND telegram_id IS NOT NULL AND status = 'active'",
            ['l1' => $login, 'l2' => $login]
        );

        if ($user) {
            Session::setFlash('info', 'Сброс пароля доступен через Telegram. Используйте авторизацию через Telegram.');
        } else {
            Session::setFlash('info', 'Если аккаунт существует и привязан к Telegram, используйте вход через Telegram.');
        }
        $this->redirect('/auth/login');
    }
}
