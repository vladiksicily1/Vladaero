<?php
/**
 * Database connection handler for ShibaLingo
 * Automatically handles MySQL connection or graceful SQLite fallback
 */

require_once __DIR__ . '/../config.php';

class ShibaPDO extends PDO {
    private static array $tables = [
        'roles', 'users', 'languages', 'conlang_dictionary', 'conlang_grammar',
        'skills', 'lessons', 'user_progress', 'chat_sessions', 'chat_messages',
        'shop_items', 'user_inventory', 'achievements', 'user_achievements',
        'daily_quests', 'user_quests', 'slang_proposals', 'stories', 'clubs',
        'club_members', 'promo_codes', 'user_promo_uses', 'duel_rooms',
        'ai_logs', 'settings', 'classrooms', 'classroom_students', 'assignments', 'assignment_submissions',
        'password_resets', 'telegram_logs'
    ];

    public static function prefixSql(string $sql): string {
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
        if (empty($prefix)) return $sql;

        foreach (self::$tables as $t) {
            $sql = preg_replace_callback('/(?<=\bFROM|\bJOIN|\bINTO|\bUPDATE|\bTABLE)\s+(`?' . preg_quote($t, '/') . '`?)/i', function($m) use ($prefix, $t) {
                if (strpos($m[1], $prefix . $t) !== false) {
                    return ' ' . $m[1];
                }
                return ' `' . $prefix . $t . '`';
            }, $sql);
        }
        return $sql;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(self::prefixSql($query), $options);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetch_mode_args): PDOStatement|false {
        $prefixed = self::prefixSql($query);
        if ($fetchMode !== null) {
            return parent::query($prefixed, $fetchMode, ...$fetch_mode_args);
        }
        return parent::query($prefixed);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false {
        return parent::exec(self::prefixSql($statement));
    }
}

class Database {
    private static ?ShibaPDO $instance = null;
    private static string $driver = 'mysql';

    public static function getConnection(): ShibaPDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Try MySQL First
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new ShibaPDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$instance = $pdo;
            self::$driver = 'mysql';
            return self::$instance;
        } catch (PDOException $e) {
            // If MySQL fails and SQLite fallback is enabled, use SQLite
            if (defined('USE_SQLITE_FALLBACK') && USE_SQLITE_FALLBACK) {
                try {
                    $sqlitePath = SQLITE_PATH;
                    $isNew = !file_exists($sqlitePath);
                    $pdo = new ShibaPDO("sqlite:" . $sqlitePath, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                    self::$instance = $pdo;
                    self::$driver = 'sqlite';

                    if ($isNew) {
                        self::initSqliteTables($pdo);
                    }

                    return self::$instance;
                } catch (Exception $sqe) {
                    die("Database Connection Error (both MySQL and SQLite failed): " . $sqe->getMessage());
                }
            } else {
                die("MySQL Connection Error: " . $e->getMessage() . "<br>Please check config.php or import schema.sql.");
            }
        }
    }

    public static function getDriver(): string {
        return self::$driver;
    }

    /**
     * Initializes SQLite tables and seed data automatically on first launch
     */
    private static function initSqliteTables(PDO $pdo): void {
        $p = defined('DB_PREFIX') ? DB_PREFIX : '';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$p}settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS {$p}roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description TEXT,
                permissions TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT,
                password_hash TEXT,
                role_id INTEGER DEFAULT 4,
                avatar TEXT,
                selected_skin TEXT DEFAULT 'classic',
                xp INTEGER DEFAULT 0,
                streak INTEGER DEFAULT 1,
                hearts INTEGER DEFAULT 5,
                gems INTEGER DEFAULT 50,
                status TEXT DEFAULT 'active',
                last_active_date DATE,
                current_language TEXT DEFAULT 'vladikish',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}languages (
                code TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                native_name TEXT NOT NULL,
                flag TEXT NOT NULL,
                description TEXT,
                is_conlang INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                order_num INTEGER DEFAULT 1
            );

            CREATE TABLE IF NOT EXISTS {$p}conlang_dictionary (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                language_code TEXT DEFAULT 'vladikish',
                word TEXT NOT NULL,
                part_of_speech TEXT DEFAULT 'noun',
                translation_ru TEXT NOT NULL,
                translation_en TEXT NOT NULL,
                translation_it TEXT,
                pronunciation TEXT,
                example_sentence TEXT,
                created_by TEXT DEFAULT 'manual',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}conlang_grammar (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                language_code TEXT DEFAULT 'vladikish',
                rule_title TEXT NOT NULL,
                rule_description TEXT NOT NULL,
                rule_examples TEXT,
                order_num INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}skills (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                language_code TEXT NOT NULL,
                title TEXT NOT NULL,
                icon TEXT DEFAULT 'paw',
                level INTEGER DEFAULT 1,
                order_num INTEGER DEFAULT 1,
                description TEXT
            );

            CREATE TABLE IF NOT EXISTS {$p}lessons (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                skill_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                xp_reward INTEGER DEFAULT 15,
                gems_reward INTEGER DEFAULT 5,
                order_num INTEGER DEFAULT 1,
                lesson_data TEXT NOT NULL,
                is_ai_generated INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}user_progress (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                lesson_id INTEGER NOT NULL,
                completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                score INTEGER DEFAULT 100
            );

            CREATE TABLE IF NOT EXISTS {$p}chat_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                language_code TEXT DEFAULT 'vladikish',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL,
                sender TEXT NOT NULL,
                message TEXT NOT NULL,
                translation TEXT,
                corrections TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}shop_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_key TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description TEXT NOT NULL,
                icon TEXT NOT NULL,
                price_gems INTEGER NOT NULL,
                category TEXT DEFAULT 'booster',
                effect_value TEXT
            );

            CREATE TABLE IF NOT EXISTS {$p}user_inventory (
                user_id INTEGER NOT NULL,
                item_key TEXT NOT NULL,
                quantity INTEGER DEFAULT 1,
                is_equipped INTEGER DEFAULT 0,
                PRIMARY KEY (user_id, item_key)
            );

            CREATE TABLE IF NOT EXISTS {$p}achievements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                icon TEXT NOT NULL,
                xp_reward INTEGER DEFAULT 50,
                gems_reward INTEGER DEFAULT 20,
                req_type TEXT NOT NULL,
                req_value INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS {$p}user_achievements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                achievement_id INTEGER NOT NULL,
                unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, achievement_id)
            );

            CREATE TABLE IF NOT EXISTS {$p}daily_quests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                req_type TEXT NOT NULL,
                req_target INTEGER NOT NULL,
                xp_reward INTEGER DEFAULT 25,
                gems_reward INTEGER DEFAULT 10
            );

            CREATE TABLE IF NOT EXISTS {$p}stories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                language_code TEXT DEFAULT 'vladikish',
                level INTEGER DEFAULT 1,
                cover_image TEXT,
                story_data TEXT NOT NULL,
                xp_reward INTEGER DEFAULT 30,
                gems_reward INTEGER DEFAULT 15,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}promo_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                reward_type TEXT NOT NULL,
                reward_value TEXT NOT NULL,
                max_uses INTEGER DEFAULT 100,
                used_count INTEGER DEFAULT 0,
                expires_at DATE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS {$p}user_promo_uses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code_id INTEGER NOT NULL,
                used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, code_id)
            );
        ");

        // Seed Data for SQLite
        $pdo->exec("
            INSERT OR IGNORE INTO {$p}settings (setting_key, setting_value) VALUES
            ('nvidia_api_key', ''),
            ('nvidia_model', 'meta/llama-3.3-70b-instruct'),
            ('site_title', 'ShibaLingo'),
            ('mascot_name', 'Сиба-сэнсэй'),
            ('maintenance_mode', '0'),
            ('maintenance_message', 'Сиба-сэнсэй проводит техническое обслуживание! Скоро вернемся 🐾'),
            ('maintenance_allowed_roles', '[\"superadmin\",\"admin\",\"beta_tester\"]');

            INSERT OR IGNORE INTO {$p}roles (id, slug, name, description, permissions) VALUES
            (1, 'superadmin', 'Главный Администратор', 'Полный доступ', '[\"*\"]'),
            (2, 'admin', 'Администратор', 'Управление контентом', '[\"manage_lessons\",\"manage_conlang\",\"manage_users\",\"view_analytics\",\"manage_languages\",\"access_maintenance\"]'),
            (4, 'student', 'Ученик', 'Обучение', '[\"study\",\"chat\"]');

            INSERT OR IGNORE INTO {$p}users (id, username, email, password_hash, role_id, xp, streak, hearts, gems, selected_skin, status, last_active_date, current_language)
            VALUES (1, 'admin', 'admin@shibalingo.local', '\$2y\$12\$F.liuM1n8rc8BkxA78Fgje5SVEzsZZIwZ5LZW4T1PnU9q2KMMcW.S', 1, 350, 5, 5, 200, 'classic', 'active', DATE('now'), 'vladikish');

            INSERT OR IGNORE INTO {$p}languages (code, name, native_name, flag, description, is_conlang) VALUES
            ('vladikish', 'Vladikish', 'Vladíkish', '🐕', 'Уникальный выдуманный язык с мелодичным звучанием и живой AI-грамматикой', 1),
            ('en', 'English', 'English', '🇬🇧', 'Международный язык для путешествий, общения и IT', 0),
            ('it', 'Italiano', 'Italiano', '🇮🇹', 'Мелодичный язык искусства, кулинарии и путешествий', 0),
            ('ru', 'Русский', 'Русский', '🇷🇺', 'Богатый и выразительный язык для глубокого изучения', 0);

            INSERT OR IGNORE INTO {$p}conlang_dictionary (word, part_of_speech, translation_ru, translation_en, translation_it, pronunciation, example_sentence, created_by) VALUES
            ('Aero', 'noun', 'небо / полет', 'sky / flight', 'cielo / volo', 'А́эро', 'Aero zora vanti. (Небо сегодня прекрасное.)', 'manual'),
            ('Vladi', 'noun', 'друг / правитель', 'friend / ruler', 'amico / sovrano', 'Вла́ди', 'Vladi, zora mira! (Друг, доброе утро!)', 'manual'),
            ('Zora', 'noun', 'день / солнце', 'day / sun', 'giorno / sole', 'Зо́ра', 'Zora velo. (Солнце светит.)', 'manual'),
            ('Barka', 'noun', 'собака / верность', 'dog / loyalty', 'cane / lealtà', 'Ба́рка', 'Shiba barka bonu. (Шиба — хорошая собака.)', 'manual'),
            ('Bonu', 'adjective', 'хороший / добрый', 'good / kind', 'buono / gentile', 'Бо́ну', 'Vladi bonu est. (Друг хороший.)', 'manual'),
            ('Mira', 'greeting', 'привет / мир', 'hello / peace', 'ciao / pace', 'Ми́ра', 'Mira, Shiba! (Привет, Шиба!)', 'manual'),
            ('Korno', 'noun', 'сердце / душа', 'heart / soul', 'cuore / anima', 'Ко́рно', 'Korno vanti. (Сердце радостно.)', 'manual'),
            ('Vanti', 'adjective', 'счастливый / прекрасный', 'happy / beautiful', 'felice / bello', 'Ва́нти', 'Vanti zora! (Прекрасный день!)', 'manual'),
            ('Nox', 'noun', 'ночь / сон', 'night / sleep', 'notte / sonno', 'Нокс', 'Nox mira, Vladi. (Спокойной ночи, Влади.)', 'manual'),
            ('Lingo', 'noun', 'слово / язык', 'word / language', 'parola / lingua', 'Ли́нго', 'Vladikish lingo vanti. (Язык Владикиш прекрасен.)', 'manual'),
            ('Toro', 'verb', 'любить / ценить', 'to love / appreciate', 'amare', 'То́ро', 'Me toro Barka. (Я люблю собаку.)', 'manual'),
            ('Velo', 'verb', 'сиять / лететь', 'to shine / fly', 'volare / splendere', 'Ве́ло', 'Aero velo. (Небо сияет.)', 'manual'),
            ('Danko', 'phrase', 'спасибо / благодарю', 'thank you', 'grazie', 'Да́нко', 'Danko, Shiba-sensei! (Спасибо, Сиба-сэнсэй!)', 'manual'),
            ('Plaso', 'phrase', 'пожалуйста', 'please', 'per favore', 'Пла́со', 'Plaso, lingo me. (Пожалуйста, поговори со мной.)', 'manual');

            INSERT OR IGNORE INTO {$p}conlang_grammar (rule_title, rule_description, rule_examples) VALUES
            ('Базовый порядок слов: SVO (Субъект - Глагол - Объект)', 'В языке Vladikish предложения строятся по классической структуре: Сначала кто делает, затем действие, затем над чем совершается действие.', 'Me (Я) toro (люблю) Vladikish (Владикиш).'),
            ('Прилагательные согласуются по мягкости', 'Описательные слова часто оканчиваются на -u (bonu, vanti).', 'Zora vanti = прекрасный день. Barka bonu = хорошая собака.'),
            ('Множественное число: окончание -s / -i', 'Для образования множественного числа к существительным добавляется суффикс -s или -i.', 'Barka (собака) -> Barkas (собаки).'),
            ('Прошедшее и будущее время глаголов', 'Прошедшее время образуется добавлением суффикса -ti (toro -> toroti). Будущее время с помощью префикса vo- (vo-toro).', 'Me toroti Aero = Я полюбил небо.');

            INSERT OR IGNORE INTO {$p}skills (id, language_code, title, icon, level, order_num, description) VALUES
            (1, 'vladikish', 'Приветствия & Знакомство', 'hand', 1, 1, 'Первые слова на Vladikish: Mira, Vladi, Danko'),
            (2, 'vladikish', 'Шиба и Природа', 'paw', 1, 2, 'Слова о небе, собаке, солнце и радости'),
            (3, 'vladikish', 'Эмоции & Сердце', 'heart', 1, 3, 'Выражаем чувства: любовь, красота, счастье'),
            (4, 'en', 'Basics 1: Greetings', 'hand', 1, 1, 'Hello, Good morning, Thank you and friends'),
            (5, 'it', 'Le Basi: Saluti', 'sun', 1, 1, 'Ciao, Buongiorno, Grazie e come stai');

            INSERT OR IGNORE INTO {$p}lessons (id, skill_id, title, xp_reward, order_num, lesson_data) VALUES
            (1, 1, 'Урок 1: Первые приветствия', 15, 1, '[
              {
                \"type\": \"multiple_choice\",
                \"question\": \"Как переводится слово «Mira» на русский?\",
                \"prompt\": \"Mira\",
                \"options\": [\"Привет / Мир\", \"Собака\", \"Ночь\", \"Спасибо\"],
                \"correct\": 0,
                \"explanation\": \"«Mira» — главное дружеское приветствие на языке Vladikish!\"
              },
              {
                \"type\": \"word_bank\",
                \"question\": \"Собери фразу: «Привет, друг!»\",
                \"prompt\": \"Привет, друг!\",
                \"correct_sequence\": [\"Mira,\", \"Vladi!\"],
                \"word_pool\": [\"Mira,\", \"Barka\", \"Vladi!\", \"Nox\", \"Bonu\", \"Aero\"],
                \"explanation\": \"Mira = Привет, Vladi = Друг.\"
              },
              {
                \"type\": \"match_pairs\",
                \"question\": \"Сопоставь пары слов\",
                \"pairs\": [
                  {\"left\": \"Danko\", \"right\": \"Спасибо\"},
                  {\"left\": \"Vladi\", \"right\": \"Друг\"},
                  {\"left\": \"Mira\", \"right\": \"Привет\"},
                  {\"left\": \"Bonu\", \"right\": \"Хороший\"}
                ]
              },
              {
                \"type\": \"translate\",
                \"question\": \"Переведи на русский:\",
                \"prompt\": \"Danko, Vladi!\",
                \"correct_answers\": [\"Спасибо, друг!\", \"Спасибо, друг\", \"Спасибо друг\"],
                \"explanation\": \"Danko = Спасибо, Vladi = Друг.\"
              }
            ]');

            INSERT OR IGNORE INTO {$p}shop_items (id, item_key, name, description, icon, price_gems, category, effect_value) VALUES
            (1, 'streak_freeze', 'Заморозка Стрика', 'Сохраняет ваш огонёк стрика 🔥', '❄️', 50, 'booster', 'freeze_1d'),
            (2, 'heart_refill', 'Полное Сердце', 'Восстанавливает 5 сердечек ❤️', '💖', 30, 'potion', 'hearts_5'),
            (3, 'double_xp', 'Зелье 2x XP (15 мин)', 'Удваивает XP за уроки на 15 минут!', '⚡', 40, 'potion', 'xp_2x_15m'),
            (4, 'skin_pilot', 'Скин: Шиба-Пилот ✈️', 'Летные очки и белый шарф', '✈️', 100, 'skin', 'pilot'),
            (5, 'skin_samurai', 'Скин: Шиба-Самурай ⚔️', 'Легендарные доспехи самурая', '⚔️', 120, 'skin', 'samurai'),
            (6, 'skin_cyberpunk', 'Скин: Киберпанк Шиба 🕶️', 'Неоновый козырек будущего', '🕶️', 150, 'skin', 'cyberpunk');

            INSERT OR IGNORE INTO {$p}promo_codes (id, code, reward_type, reward_value, max_uses, used_count, expires_at) VALUES
            (1, 'SHIBA2026', 'gems', '100', 500, 0, '2027-12-31'),
            (2, 'VLADIKISH', 'xp', '150', 500, 0, '2027-12-31'),
            (3, 'SUPERHEARTS', 'hearts', '5', 500, 0, '2027-12-31'),
            (4, 'GOLDENSHIBA', 'bundle', '{\"gems\":150,\"xp\":200,\"hearts\":5}', 500, 0, '2027-12-31');
        ");
    }
}
