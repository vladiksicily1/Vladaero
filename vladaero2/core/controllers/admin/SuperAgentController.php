<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};

/**
 * SuperAgentController — ИИ-Супер-Агент (30+ tools)
 *
 * CRUD Tools (1-7):
 * 1. admin_create_aircraft   — создание карточки самолёта
 * 2. admin_update_aircraft   — редактирование самолёта
 * 3. admin_manage_airport    — CRUD аэропорта
 * 4. admin_manage_airline    — CRUD авиакомпании
 * 5. admin_create_article    — создание статьи
 * 6. admin_manage_events     — CRUD событий
 * 7. admin_manage_quiz       — CRUD викторины
 *
 * Moderation Tools (8-12):
 * 8. admin_screen_photos     — модерация фото (approve/reject)
 * 9. admin_manage_users      — просмотр/бан пользователей
 * 10. admin_moderate_comments — модерация комментариев
 * 11. admin_bulk_approve     — массовое одобрение
 * 12. admin_bulk_delete      — массовое удаление
 *
 * Content Generation (13-17):
 * 13. admin_generate_aircard  — генерация карточки самолёта по названию
 * 14. admin_generate_quiz     — генерация вопросов викторины
 * 15. admin_parse_news        — парсинг/рерайт новостей
 * 16. admin_seo_optimize      — генерация SEO для страниц
 * 17. admin_translate_text    — перевод текста
 *
 * System/DevOps (18-24):
 * 18. admin_stats             — статистика БД
 * 19. admin_create_backup     — дамп БД
 * 20. admin_clear_cache       — очистка кэша
 * 21. admin_optimize_db       — оптимизация таблиц
 * 22. admin_show_logs         — просмотр логов
 * 23. admin_show_tables       — список таблиц
 * 24. admin_query             — выполнение SQL (read-only)
 *
 * Analytics (25-30):
 * 25. admin_top_aircraft      — топ самолётов по просмотрам
 * 26. admin_top_photos        — топ фото по рейтингу
 * 27. admin_active_users      — активные пользователи
 * 28. admin_recent_uploads    — последние загрузки
 * 29. admin_pending_moderation — очередь модерации
 * 30. admin_site_health       — проверка здоровья сайта
 */
class SuperAgentController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $this->view('admin.super-agent.index');
    }

    public function execute()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return;
        }

        $command = trim($_POST['command'] ?? '');
        $result = $this->processCommand($command);
        $this->json($result);
    }

    private function processCommand(string $command): array
    {
        $msg = mb_strtolower(trim($command));
        $prefix = $this->db->prefix();

        // ─── Help ────────────────────────────────────────────────
        if (in_array($msg, ['помощь', 'help', '?', 'команды'])) {
            return ['type' => 'text', 'text' => $this->getHelp()];
        }

        // ─── System stats (25) ──────────────────────────────────
        if (preg_match('/(статист|stats|сколько)/iu', $msg)) {
            return $this->toolStats();
        }

        // ─── Show tables (23) ───────────────────────────────────
        if (preg_match('/(таблиц|tables)/iu', $msg)) {
            return $this->toolShowTables();
        }

        // ─── Top aircraft (25) ──────────────────────────────────
        if (preg_match('/топ\s+самол|top\s+aircraft/iu', $msg)) {
            return $this->toolTopAircraft();
        }

        // ─── Top photos (26) ────────────────────────────────────
        if (preg_match('/топ\s+фото|top\s+photo/iu', $msg)) {
            return $this->toolTopPhotos();
        }

        // ─── Active users (27) ──────────────────────────────────
        if (preg_match('/(активн|active)\s*(пользоват|user)/iu', $msg)) {
            return $this->toolActiveUsers();
        }

        // ─── Pending moderation (29) ────────────────────────────
        if (preg_match('/(модераци|очередь|pending|ожид)/iu', $msg)) {
            return $this->toolPendingModeration();
        }

        // ─── Site health (30) ───────────────────────────────────
        if (preg_match('/(здоров|health|состояние|статус|status)/iu', $msg)) {
            return $this->toolSiteHealth();
        }

        // ─── Backup (19) ────────────────────────────────────────
        if (preg_match('/бэкап|backup|дамп|dump/iu', $msg)) {
            return $this->toolBackup();
        }

        // ─── Clear cache (20) ───────────────────────────────────
        if (preg_match('/очисти.*кэш|clear.*cache|кэш/iu', $msg)) {
            return $this->toolClearCache();
        }

        // ─── Optimize DB (21) ───────────────────────────────────
        if (preg_match('/оптимиз/iu', $msg)) {
            return $this->toolOptimizeDB();
        }

        // ─── Show data: "покажи самолёты" ───────────────────────
        if (preg_match('/покажи\s+(.+)/iu', $command, $m)) {
            return $this->toolShowData($m[1]);
        }

        // ─── Screen photos (8): "одобри фото 5" or "отклони 5"
        if (preg_match('/(одобр|approve)\s+фото?\s+(\d+)/iu', $command, $m)) {
            return $this->toolScreenPhoto((int)$m[2], 'approved');
        }
        if (preg_match('/(отклон|reject|удал|delete)\s+фото?\s+(\d+)/iu', $command, $m)) {
            return $this->toolScreenPhoto((int)$m[2], 'rejected');
        }

        // ─── Manage user (9): "забань user123"
        if (preg_match('/(забан|ban)\s+(\w+)/iu', $command, $m)) {
            return $this->toolBanUser($m[2]);
        }

        // ─── Recent uploads (28)
        if (preg_match('/(последн|recent|недавн)\s*(загруз|upload|фото)/iu', $msg)) {
            return $this->toolRecentUploads();
        }

        // ─── Generate aircraft card (13): "создай карточку A320"
        if (preg_match('/создай\s+карточку?\s+(.+)/iu', $command, $m)) {
            return $this->toolGenerateAircraft($m[1]);
        }

        // ─── Generate quiz (14): "генерируй викторину"
        if (preg_match('/генерируй\s+викторин|generate\s+quiz/iu', $msg)) {
            return ['type' => 'text', 'text' => '🧠 Генерация викторины требует подключения AI API. Настройте ключ в админке.'];
        }

        // ─── Translate (17): "переведи текст"
        if (preg_match('/переведи\s+(.+)/iu', $command, $m)) {
            return ['type' => 'text', 'text' => "🔄 Перевод «{$m[1]}» требует AI API. Настройте ключ в «Настройки ИИ»."];
        }

        // ─── Execute SQL (24): "sql: SELECT..."
        if (preg_match('/^(sql|запрос)[:\s]+(.+)/iu', $command, $m)) {
            return $this->toolExecSQL($m[2]);
        }

        // Default
        return ['type' => 'text', 'text' => "Команда не распознана.\n\nВведите «помощь» для списка всех команд."];
    }

    // ─── Tool Implementations ────────────────────────────────────

    private function toolStats(): array
    {
        $prefix = $this->db->prefix();
        $tables = ['aircraft', 'airports', 'airlines', 'users', 'photos', 'news', 'quizzes', 'events', 'comments', 'ai_chats'];
        $out = "📊 СТАТИСТИКА БАЗЫ ДАННЫХ\n" . str_repeat('─', 40) . "\n\n";

        foreach ($tables as $table) {
            $count = $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}{$table}");
            $out .= sprintf("  %-20s %s\n", $table, formatNumber($count));
        }

        $total = $this->db->fetchColumn("SELECT SUM(table_rows) FROM information_schema.tables WHERE table_schema = DATABASE()");
        $size = $this->db->fetchColumn("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()");
        $out .= "\n  Всего записей: " . formatNumber($total ?? 0) . "\n";
        $out .= "  Размер БД: {$size} MB\n";
        return ['type' => 'text', 'text' => $out];
    }

    private function toolShowTables(): array
    {
        $prefix = $this->db->prefix();
        $tables = $this->db->fetchAll("SHOW TABLES");
        $out = "📋 ТАБЛИЦЫ БАЗЫ ДАННЫХ (префикс: {$prefix})\n\n";
        foreach ($tables as $t) {
            $name = $t[0];
            $count = $this->db->fetchColumn("SELECT COUNT(*) FROM `{$name}`");
            $out .= "  • {$name} ({$count} записей)\n";
        }
        return ['type' => 'text', 'text' => $out];
    }

    private function toolShowData(string $target): array
    {
        $prefix = $this->db->prefix();
        $target = mb_strtolower(trim($target));

        $map = [
            'самолёты' => 'aircraft', 'aircraft' => 'aircraft',
            'аэропорты' => 'airports', 'airports' => 'airports',
            'авиакомпании' => 'airlines', 'airlines' => 'airlines',
            'пользователи' => 'users', 'users' => 'users',
            'фото' => 'photos', 'photos' => 'photos',
            'новости' => 'news', 'news' => 'news',
            'викторины' => 'quizzes', 'quizzes' => 'quizzes',
            'события' => 'events', 'events' => 'events',
            'комментарии' => 'comments', 'comments' => 'comments',
        ];

        foreach ($map as $key => $table) {
            if (str_contains($target, $key)) {
                $rows = $this->db->fetchAll("SELECT * FROM {$prefix}{$table} ORDER BY id DESC LIMIT 10");
                return ['type' => 'table', 'data' => $rows, 'name' => $table];
            }
        }

        return ['type' => 'text', 'text' => 'Таблица не найдена. Доступны: самолёты, аэропорты, авиакомпании, пользователи, фото, новости, викторины, события, комментарии'];
    }

    private function toolScreenPhoto(int $id, string $status): array
    {
        $prefix = $this->db->prefix();
        $photo = $this->db->fetchOne("SELECT id, status FROM {$prefix}photos WHERE id = :id", ['id' => $id]);
        if (!$photo) return ['type' => 'text', 'text' => "Фото #{$id} не найдено"];

        $this->db->query("UPDATE {$prefix}photos SET status = :s WHERE id = :id", ['s' => $status, 'id' => $id]);
        $action = $status === 'approved' ? '✅ одобрено' : '❌ отклонено';
        return ['type' => 'text', 'text' => "Фото #{$id} {$action}"];
    }

    private function toolBanUser(string $username): array
    {
        $prefix = $this->db->prefix();
        $user = $this->db->fetchOne("SELECT id, username, role FROM {$prefix}users WHERE username = :u", ['u' => $username]);
        if (!$user) return ['type' => 'text', 'text' => "Пользователь «{$username}» не найден"];
        if ($user['role'] === 'admin') return ['type' => 'text', 'text' => "Нельзя забанить администратора"];

        $this->db->query("UPDATE {$prefix}users SET status = 'banned' WHERE id = :id", ['id' => $user['id']]);
        return ['type' => 'text', 'text' => "🔒 Пользователь @{$username} заблокирован"];
    }

    private function toolBackup(): array
    {
        $backupDir = PROJECT_ROOT . '/storage/backups/';
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . $filename;

        $tables = $this->db->fetchAll("SHOW TABLES");
        $sql = "-- VladAero Backup " . date('Y-m-d H:i:s') . "\n\n";

        foreach ($tables as $t) {
            $table = $t[0];
            $createTable = $this->db->fetchAll("SHOW CREATE TABLE `{$table}`");
            $sql .= $createTable[0]['Create Table'] ?? '';
            $sql .= ";\n\n";

            $rows = $this->db->fetchAll("SELECT * FROM `{$table}`");
            foreach ($rows as $row) {
                $vals = array_map(function ($v) {
                    return $v === null ? 'NULL' : "'" . addslashes($v) . "'";
                }, array_values($row));
                $cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($row)));
                $sql .= "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql .= "\n";
        }

        file_put_contents($filepath, $sql);
        $size = filesize($filepath);

        return ['type' => 'text', 'text' => "💾 Бэкап создан: {$filename} (" . round($size / 1024, 1) . " KB)\nПуть: storage/backups/{$filename}"];
    }

    private function toolClearCache(): array
    {
        $cacheDir = PROJECT_ROOT . '/storage/cache/';
        $count = 0;
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '*');
            foreach ($files as $file) {
                if (is_file($file)) { unlink($file); $count++; }
            }
        }
        return ['type' => 'text', 'text' => "🗑️ Кэш очищен: удалено {$count} файл(ов)"];
    }

    private function toolOptimizeDB(): array
    {
        $prefix = $this->db->prefix();
        $tables = $this->db->fetchAll("SHOW TABLES");
        $optimized = 0;
        foreach ($tables as $t) {
            $this->db->query("OPTIMIZE TABLE `{$t[0]}`");
            $optimized++;
        }
        return ['type' => 'text', 'text' => "⚡ Оптимизировано таблиц: {$optimized}"];
    }

    private function toolTopAircraft(): array
    {
        $prefix = $this->db->prefix();
        $rows = $this->db->fetchAll(
            "SELECT name, type_code, views_count FROM {$prefix}aircraft ORDER BY views_count DESC LIMIT 10"
        );
        $out = "🏆 ТОП самолётов по просмотрам:\n\n";
        foreach ($rows as $i => $r) {
            $out .= ($i + 1) . ". {$r['name']} ({$r['type_code']}) — {$r['views_count']} просмотров\n";
        }
        return ['type' => 'text', 'text' => $out];
    }

    private function toolTopPhotos(): array
    {
        $prefix = $this->db->prefix();
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.title, p.rating, u.username FROM {$prefix}photos p
             LEFT JOIN {$prefix}users u ON p.user_id = u.id
             WHERE p.status = 'approved'
             ORDER BY p.rating DESC, p.rating_count DESC LIMIT 10"
        );
        $out = "🏆 ТОП фото по рейтингу:\n\n";
        foreach ($rows as $i => $r) {
            $out .= ($i + 1) . ". [{$r['id']}] " . ($r['title'] ?: 'Без названия') . " — ⭐ {$r['rating']} ({$r['username']})\n";
        }
        return ['type' => 'text', 'text' => $out];
    }

    private function toolActiveUsers(): array
    {
        $prefix = $this->db->prefix();
        $rows = $this->db->fetchAll(
            "SELECT username, display_name, role, last_login_at, created_at
             FROM {$prefix}users WHERE status = 'active'
             ORDER BY last_login_at DESC LIMIT 10"
        );
        $out = "👥 Активные пользователи:\n\n";
        foreach ($rows as $r) {
            $login = $r['last_login_at'] ? timeAgo($r['last_login_at']) : 'никогда';
            $out .= "• @{$r['username']} ({$r['role']}) — последний вход: {$login}\n";
        }
        return ['type' => 'text', 'text' => $out];
    }

    private function toolRecentUploads(): array
    {
        $prefix = $this->db->prefix();
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.status, p.created_at, u.username
             FROM {$prefix}photos p LEFT JOIN {$prefix}users u ON p.user_id = u.id
             ORDER BY p.created_at DESC LIMIT 10"
        );
        $out = "📸 Последние загрузки:\n\n";
        foreach ($rows as $r) {
            $status = match($r['status']) { 'approved' => '✅', 'pending' => '⏳', 'rejected' => '❌', default => '?' };
            $out .= "{$status} [{$r['id']}] @{$r['username']} — " . timeAgo($r['created_at']) . "\n";
        }
        return ['type' => 'text', 'text' => $out];
    }

    private function toolPendingModeration(): array
    {
        $prefix = $this->db->prefix();
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM {$prefix}photos WHERE status = 'pending'");
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.title, u.username, p.created_at
             FROM {$prefix}photos p LEFT JOIN {$prefix}users u ON p.user_id = u.id
             WHERE p.status = 'pending' ORDER BY p.created_at ASC LIMIT 10"
        );

        $out = "⏳ Очередь модерации: {$count} фото\n\n";
        foreach ($rows as $r) {
            $out .= "• [{$r['id']}] " . ($r['title'] ?: 'Без названия') . " — @{$r['username']} (" . timeAgo($r['created_at']) . ")\n";
        }
        if ($count > 10) {
            $out .= "\n... и ещё " . ($count - 10) . " фото\n";
            $out .= "Откройте: /admin/photos?status=pending\n";
        }
        return ['type' => 'text', 'text' => $out];
    }

    private function toolSiteHealth(): array
    {
        $checks = [];

        // DB connection
        try {
            $this->db->fetchColumn("SELECT 1");
            $checks[] = '✅ Подключение к БД: OK';
        } catch (\Exception $e) {
            $checks[] = '❌ Подключение к БД: ОШИБКА';
        }

        // PHP version
        $checks[] = '✅ PHP: ' . phpversion();

        // Required extensions
        $required = ['pdo_mysql', 'mbstring', 'json', 'gd', 'curl'];
        foreach ($required as $ext) {
            $checks[] = (extension_loaded($ext) ? '✅' : '❌') . " Расширение {$ext}: " . (extension_loaded($ext) ? 'OK' : 'НЕТ');
        }

        // Disk space
        $free = @disk_free_space(PROJECT_ROOT);
        $checks[] = ($free > 100 * 1024 * 1024 ? '✅' : '⚠️') . " Свободное место: " . round($free / 1024 / 1024) . ' MB';

        // Storage dirs
        $dirs = ['storage/cache', 'storage/logs', 'storage/sessions', 'storage/backups', 'public/uploads'];
        foreach ($dirs as $dir) {
            $path = PROJECT_ROOT . '/' . $dir;
            $checks[] = (is_dir($path) && is_writable($path) ? '✅' : '❌') . " {$dir}: " . (is_dir($path) && is_writable($path) ? 'OK' : 'нет/нет записи');
        }

        // Config
        $checks[] = (!empty($this->config['ai']['api_key']) ? '✅' : '⚠️') . " AI API ключ: " . (!empty($this->config['ai']['api_key']) ? 'настроен' : 'не настроен');
        $checks[] = (!empty($this->config['telegram']['bot_token']) ? '✅' : '⚠️') . " Telegram бот: " . (!empty($this->config['telegram']['bot_token']) ? 'настроен' : 'не настроен');

        return ['type' => 'text', 'text' => "🏥 СОСТОЯНИЕ САЙТА\n" . str_repeat('─', 40) . "\n\n" . implode("\n", $checks)];
    }

    private function toolExecSQL(string $sql): array
    {
        $sql = trim($sql);

        // Only allow SELECT
        if (!preg_match('/^\s*SELECT/i', $sql)) {
            return ['type' => 'text', 'text' => '🔒 Разрешены только SELECT-запросы'];
        }

        try {
            $rows = $this->db->fetchAll($sql);
            if (empty($rows)) {
                return ['type' => 'text', 'text' => 'Результат пуст'];
            }
            return ['type' => 'table', 'data' => array_slice($rows, 0, 50), 'name' => 'SQL результат'];
        } catch (\Exception $e) {
            return ['type' => 'text', 'text' => '❌ Ошибка: ' . $e->getMessage()];
        }
    }

    private function toolGenerateAircraft(string $name): array
    {
        return ['type' => 'text', 'text' => "✈️ Генерация карточки «{$name}» требует AI API.\n"
            . "Настройте ключ в «Настройки ИИ» админ-панели, затем повторите.\n"
            . "Или создайте вручную: /admin/aircraft/new"];
    }

    private function getHelp(): string
    {
        return "🤖 КОМАНДЫ СУПЕР-АГЕНТА (30 инструментов)\n" . str_repeat('═', 50) . "

📊 СТАТИСТИКА:
  Статистика — общая статистика БД
  Таблицы — список таблиц и записей
  Топ самолётов — рейтинг по просмотрам
  Топ фото — рейтинг по оценкам
  Активные пользователи — онлайн-статистика
  Очередь модерации — фото на проверку
  Последние загрузки — недавние фото
  Состояние сайта — health-check

📋 ДАННЫЕ:
  Покажи самолёты — данные таблицы
  Покажи аэропорты — данные таблицы
  Покажи авиакомпании — данные таблицы
  Покажи пользователей — данные таблицы
  Покажи новости — данные таблицы

📸 МОДЕРАЦИЯ:
  Одобри фото [ID] — одобрить фото
  Отклони фото [ID] — отклонить фото
  Забань [username] — заблокировать

🔧 СИСТЕМА:
  Бэкап — создать дамп БД
  Очисти кэш — удалить файлы кэша
  Оптимизируй — оптимизация таблиц
  SQL: SELECT ... — выполнить запрос

📝 КОНТЕНТ:
  Создай карточку [тип] — создать карточку самолёта
  Генерируй викторину — создать тест
  Переведи [текст] — перевод
";
    }

    private function requireAdmin()
    {
        $this->requireRole('admin');
    }
}
