<?php
declare(strict_types=1);

namespace VladAero;

$pageTitle = 'Политика конфиденциальности и GDPR';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="glass-card rounded-3xl p-8 border border-white/5 space-y-6 text-slate-300 text-sm leading-relaxed font-sans">
        <h1 class="text-3xl font-bold text-white font-mono">Политика конфиденциальности и защита данных</h1>
        <p>Портал VladAero уважает неприкосновенность частной жизни пользователей и обеспечивает безопасность хранения всех персональных данных.</p>
        
        <h3 class="text-lg font-bold text-sky-400 font-mono">1. Сбор и использование данных</h3>
        <p>Мы собираем только необходимые данные для обеспечения работы сервисов: имя пользователя, адрес электронной почты, параметры загружаемых фотографий и записи журнала налёта.</p>

        <h3 class="text-lg font-bold text-sky-400 font-mono">2. Соответствие GDPR</h3>
        <p>Каждый зарегистрированный пользователь имеет право в любой момент выгрузить полный архив своих персональных данных в формате JSON из личного кабинета, а также запросить полное удаление аккаунта.</p>

        <h3 class="text-lg font-bold text-sky-400 font-mono">3. Безопасность</h3>
        <p>Пароли пользователей хранятся в зашифрованном виде с использованием алгоритма BCrypt. Для модераторов и администраторов доступна двухфакторная аутентификация TOTP.</p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
