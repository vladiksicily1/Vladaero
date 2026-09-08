-- Complete Enhanced Schema for ShibaLingo with 30 Master Features
-- Compatible with MySQL 5.7+ / 8.0+ / MariaDB and SQLite

-- 1. Roles & RBAC
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `permissions` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Users Table (with Gems currency & Mascot Skins)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role_id` INT DEFAULT 4,
    `avatar` VARCHAR(500) NULL,
    `selected_skin` VARCHAR(50) DEFAULT 'classic', -- classic, pilot, samurai, cyberpunk, wizard, professor
    `xp` INT DEFAULT 0,
    `streak` INT DEFAULT 1,
    `hearts` INT DEFAULT 5,
    `gems` INT DEFAULT 50, -- Shop currency
    `status` ENUM('active', 'banned', 'pending') DEFAULT 'active',
    `last_active_date` DATE NULL,
    `current_language` VARCHAR(20) DEFAULT 'vladikish',
    `telegram_chat_id` VARCHAR(64) NULL,
    `telegram_username` VARCHAR(100) NULL,
    `telegram_link_code` VARCHAR(64) NULL,
    `two_factor_enabled` TINYINT(1) DEFAULT 0,
    `two_factor_code` VARCHAR(10) NULL,
    `two_factor_expires` DATETIME NULL,
    `notify_streak` TINYINT(1) DEFAULT 1,
    `notify_quests` TINYINT(1) DEFAULT 1,
    `notify_friends` TINYINT(1) DEFAULT 1,
    `notify_duels` TINYINT(1) DEFAULT 1,
    `notify_security` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.1 Password Resets via Telegram
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `code` VARCHAR(10) NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `used` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`user_id`),
    INDEX (`token`),
    INDEX (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 Telegram Notification Logs
CREATE TABLE IF NOT EXISTS `telegram_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `chat_id` VARCHAR(64) NOT NULL,
    `user_id` INT NULL,
    `direction` ENUM('in', 'out') DEFAULT 'out',
    `message_type` VARCHAR(50) DEFAULT 'general',
    `message_text` TEXT NULL,
    `status` VARCHAR(50) DEFAULT 'sent',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`chat_id`),
    INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Languages
CREATE TABLE IF NOT EXISTS `languages` (
    `code` VARCHAR(20) PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `native_name` VARCHAR(100) NOT NULL,
    `flag` VARCHAR(10) NOT NULL,
    `description` TEXT NULL,
    `is_conlang` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `order_num` INT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Conlang Dictionary
CREATE TABLE IF NOT EXISTS `conlang_dictionary` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `language_code` VARCHAR(20) DEFAULT 'vladikish',
    `word` VARCHAR(100) NOT NULL,
    `part_of_speech` VARCHAR(50) DEFAULT 'noun',
    `translation_ru` VARCHAR(255) NOT NULL,
    `translation_en` VARCHAR(255) NOT NULL,
    `translation_it` VARCHAR(255) NULL,
    `pronunciation` VARCHAR(100) NULL,
    `example_sentence` TEXT NULL,
    `root_word` VARCHAR(100) NULL,
    `created_by` VARCHAR(50) DEFAULT 'manual',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`word`),
    INDEX (`language_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Conlang Grammar
CREATE TABLE IF NOT EXISTS `conlang_grammar` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `language_code` VARCHAR(20) DEFAULT 'vladikish',
    `rule_title` VARCHAR(255) NOT NULL,
    `rule_description` TEXT NOT NULL,
    `rule_examples` TEXT NULL,
    `order_num` INT DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Skills
CREATE TABLE IF NOT EXISTS `skills` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `language_code` VARCHAR(20) NOT NULL,
    `title` VARCHAR(100) NOT NULL,
    `icon` VARCHAR(50) DEFAULT 'paw',
    `level` INT DEFAULT 1,
    `order_num` INT DEFAULT 1,
    `description` VARCHAR(255) NULL,
    FOREIGN KEY (`language_code`) REFERENCES `languages`(`code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Lessons
CREATE TABLE IF NOT EXISTS `lessons` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `skill_id` INT NOT NULL,
    `title` VARCHAR(100) NOT NULL,
    `xp_reward` INT DEFAULT 15,
    `gems_reward` INT DEFAULT 5,
    `order_num` INT DEFAULT 1,
    `lesson_data` LONGTEXT NOT NULL,
    `is_ai_generated` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. User Progress
CREATE TABLE IF NOT EXISTS `user_progress` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `lesson_id` INT NOT NULL,
    `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `score` INT DEFAULT 100,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Chat Sessions & Messages
CREATE TABLE IF NOT EXISTS `chat_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `language_code` VARCHAR(20) DEFAULT 'vladikish',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` INT NOT NULL,
    `sender` ENUM('user', 'shiba') NOT NULL,
    `message` TEXT NOT NULL,
    `translation` TEXT NULL,
    `corrections` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`session_id`) REFERENCES `chat_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Shop Items & User Inventory (Feature #2, #3)
CREATE TABLE IF NOT EXISTS `shop_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_key` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NOT NULL,
    `icon` VARCHAR(50) NOT NULL,
    `price_gems` INT NOT NULL,
    `category` ENUM('booster', 'skin', 'potion') DEFAULT 'booster',
    `effect_value` VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `item_key` VARCHAR(50) NOT NULL,
    `quantity` INT DEFAULT 1,
    `is_equipped` TINYINT(1) DEFAULT 0,
    UNIQUE KEY `uk_user_item` (`user_id`, `item_key`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Achievements & Badges (Feature #4)
CREATE TABLE IF NOT EXISTS `achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `title` VARCHAR(100) NOT NULL,
    `description` TEXT NOT NULL,
    `icon` VARCHAR(50) NOT NULL,
    `xp_reward` INT DEFAULT 50,
    `gems_reward` INT DEFAULT 20,
    `req_type` VARCHAR(50) NOT NULL, -- streak, lessons_count, xp_total, words_count
    `req_value` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `achievement_id` INT NOT NULL,
    `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_ach` (`user_id`, `achievement_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`achievement_id`) REFERENCES `achievements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Daily Quests (Feature #5)
CREATE TABLE IF NOT EXISTS `daily_quests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NOT NULL,
    `req_type` VARCHAR(50) NOT NULL, -- complete_lesson, earn_xp, chat_message, match_blitz
    `req_target` INT NOT NULL,
    `xp_reward` INT DEFAULT 25,
    `gems_reward` INT DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_quests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `quest_id` INT NOT NULL,
    `current_progress` INT DEFAULT 0,
    `is_completed` TINYINT(1) DEFAULT 0,
    `is_claimed` TINYINT(1) DEFAULT 0,
    `quest_date` DATE NOT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`quest_id`) REFERENCES `daily_quests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Living Conlang & Slang Proposals (Feature #11)
CREATE TABLE IF NOT EXISTS `slang_proposals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `word` VARCHAR(100) NOT NULL,
    `translation` VARCHAR(255) NOT NULL,
    `example` TEXT NULL,
    `author_id` INT NOT NULL,
    `upvotes` INT DEFAULT 0,
    `downvotes` INT DEFAULT 0,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Interactive Stories (Feature #9)
CREATE TABLE IF NOT EXISTS `stories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `language_code` VARCHAR(20) DEFAULT 'vladikish',
    `level` INT DEFAULT 1,
    `cover_image` VARCHAR(500) NULL,
    `story_data` LONGTEXT NOT NULL,
    `xp_reward` INT DEFAULT 30,
    `gems_reward` INT DEFAULT 15,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Clubs & Classrooms (Feature #24)
CREATE TABLE IF NOT EXISTS `clubs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `icon` VARCHAR(50) DEFAULT '🐕',
    `leader_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`leader_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `club_members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `club_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `role` ENUM('leader', 'member') DEFAULT 'member',
    `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_club_user` (`club_id`, `user_id`),
    FOREIGN KEY (`club_id`) REFERENCES `clubs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Promo Codes (Feature #29)
CREATE TABLE IF NOT EXISTS `promo_codes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `reward_type` ENUM('gems', 'xp', 'hearts', 'skin') NOT NULL,
    `reward_value` VARCHAR(100) NOT NULL,
    `max_uses` INT DEFAULT 100,
    `used_count` INT DEFAULT 0,
    `expires_at` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_promo_uses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `code_id` INT NOT NULL,
    `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_promo` (`user_id`, `code_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`code_id`) REFERENCES `promo_codes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. 1vs1 Duel Rooms (Feature #23)
CREATE TABLE IF NOT EXISTS `duel_rooms` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `room_code` VARCHAR(10) NOT NULL UNIQUE,
    `host_user_id` INT NOT NULL,
    `guest_user_id` INT NULL,
    `language_code` VARCHAR(20) DEFAULT 'vladikish',
    `status` ENUM('waiting', 'playing', 'finished') DEFAULT 'waiting',
    `questions_data` LONGTEXT NOT NULL,
    `host_score` INT DEFAULT 0,
    `guest_score` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. AI Logs
CREATE TABLE IF NOT EXISTS `ai_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action_type` VARCHAR(50) NOT NULL,
    `model` VARCHAR(100) NOT NULL,
    `prompt_tokens` INT DEFAULT 0,
    `completion_tokens` INT DEFAULT 0,
    `latency_ms` INT DEFAULT 0,
    `status` VARCHAR(20) DEFAULT 'success',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Settings
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Default Roles
INSERT INTO `roles` (`id`, `slug`, `name`, `description`, `permissions`) VALUES
(1, 'superadmin', 'Главный Администратор', 'Полный неограниченный доступ ко всем разделам и системным настройкам', '["*"]'),
(2, 'admin', 'Администратор', 'Управление контентом, пользователями, словарем и уроками', '["manage_lessons","manage_conlang","manage_users","view_analytics","manage_languages","access_maintenance"]'),
(3, 'teacher', 'Преподаватель / Контент-мейкер', 'Создание и редактирование уроков, словарей и грамматики', '["manage_lessons","manage_conlang","view_analytics"]'),
(4, 'student', 'Ученик', 'Изучение языков, прохождение уроков и общение с AI', '["study","chat"]'),
(5, 'beta_tester', 'Бета-тестер', 'Доступ к экспериментальным функциям и техобслуживанию', '["study","chat","access_maintenance"]')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Seed Default Admin User
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role_id`, `xp`, `streak`, `hearts`, `gems`, `selected_skin`, `status`, `last_active_date`, `current_language`) VALUES
(1, 'admin', 'admin@shibalingo.local', '$2y$12$F.liuM1n8rc8BkxA78Fgje5SVEzsZZIwZ5LZW4T1PnU9q2KMMcW.S', 1, 350, 5, 5, 200, 'classic', 'active', CURDATE(), 'vladikish')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Seed Languages
INSERT INTO `languages` (`code`, `name`, `native_name`, `flag`, `description`, `is_conlang`, `is_active`, `order_num`) VALUES
('vladikish', 'Vladikish', 'Vladíkish', '🐕', 'Уникальный выдуманный язык с мелодичным звучанием и живой AI-грамматикой', 1, 1, 1),
('en', 'English', 'English', '🇬🇧', 'Международный язык для путешествий, общения и IT', 0, 1, 2),
('it', 'Italiano', 'Italiano', '🇮🇹', 'Мелодичный язык искусства, кулинарии и путешествий', 0, 1, 3),
('ru', 'Русский', 'Русский', '🇷🇺', 'Богатый и выразительный язык для глубокого изучения', 0, 1, 4)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Seed Shop Items
INSERT INTO `shop_items` (`id`, `item_key`, `name`, `description`, `icon`, `price_gems`, `category`, `effect_value`) VALUES
(1, 'streak_freeze', 'Заморозка Стрика', 'Сохраняет ваш огонёк стрика 🔥, если вы пропустите один день занятий.', '❄️', 50, 'booster', 'freeze_1d'),
(2, 'heart_refill', 'Полное Сердце', 'Мгновенно восстанавливает все 5 сердечек ❤️ до максимума.', '💖', 30, 'potion', 'hearts_5'),
(3, 'double_xp', 'Зелье 2x XP (15 мин)', 'Удваивает все получаемые очки опыта за уроки на 15 минут!', '⚡', 40, 'potion', 'xp_2x_15m'),
(4, 'skin_pilot', 'Скин: Шиба-Пилот ✈️', 'Одевает вашего маскота в винтажные летные очки и белый шелковый шарф.', '✈️', 100, 'skin', 'pilot'),
(5, 'skin_samurai', 'Скин: Шиба-Самурай ⚔️', 'Легендарные легкие доспехи самурая и бамбуковый меч для Сиба-сэнсэя.', '⚔️', 120, 'skin', 'samurai'),
(6, 'skin_cyberpunk', 'Скин: Киберпанк Шиба 🕶️', 'Неоновый козырек и голографическая куртка будущего.', '🕶️', 150, 'skin', 'cyberpunk')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Seed Achievements
INSERT INTO `achievements` (`id`, `slug`, `title`, `description`, `icon`, `xp_reward`, `gems_reward`, `req_type`, `req_value`) VALUES
(1, 'first_lesson', 'Первый шаг к цели', 'Успешно завершите свой самый первый урок в ShibaLingo!', '🌱', 20, 10, 'lessons_count', 1),
(2, 'streak_7', 'Огненная неделя', 'Поддерживайте стрик занятий 7 дней подряд!', '🔥', 50, 25, 'streak', 7),
(3, 'vocab_50', 'Знаток Vladikish', 'Изучите более 20 слов в официальном словаре!', '📚', 75, 30, 'words_count', 20),
(4, 'xp_500', 'Мастер языка', 'Наберите суммарно 500 очков опыта XP!', '⚡', 100, 50, 'xp_total', 500),
(5, 'blitz_master', 'Молниеносный Шиба', 'Наберите 10 верных пар в блиц-режиме!', '⚡', 40, 15, 'blitz_score', 10)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- Seed Daily Quests
INSERT INTO `daily_quests` (`id`, `title`, `description`, `req_type`, `req_target`, `xp_reward`, `gems_reward`) VALUES
(1, 'Пройти 1 урок', 'Завершите любой урок на дереве навыков', 'complete_lesson', 1, 20, 10),
(2, 'Набрать 30 XP', 'Заработайте 30 очков опыта за сегодня', 'earn_xp', 30, 25, 10),
(3, 'Поговорить с Сиба-сэнсэем', 'Отправьте хотя бы 2 сообщения в AI-чате', 'chat_message', 2, 15, 5)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- Seed Sample Promo Codes
INSERT INTO `promo_codes` (`id`, `code`, `reward_type`, `reward_value`, `max_uses`, `used_count`, `expires_at`) VALUES
(1, 'SHIBA2026', 'gems', '100', 500, 0, '2027-12-31'),
(2, 'VLADIKISH', 'xp', '150', 500, 0, '2027-12-31')
ON DUPLICATE KEY UPDATE `code` = `code`;

-- Seed Sample Story
INSERT INTO `stories` (`id`, `title`, `language_code`, `level`, `cover_image`, `story_data`, `xp_reward`, `gems_reward`) VALUES
(1, 'Тайна Золотого Неба (Kaelo zora)', 'vladikish', 1, 'https://images.unsplash.com/photo-1534447677768-be436bb09401?w=800&auto=format&fit=crop&q=80', '[
  {
    "narrative": "Zora vanti est. Barka Shiba velo in Aero.",
    "translation": "Прекрасный день. Собака Шиба летит в небесах.",
    "question": "Куда летит Шиба?",
    "options": ["В небеса (in Aero)", "Под землю", "Спать"],
    "correct": 0
  },
  {
    "narrative": "Vladi mira! Korno vanti toro Vladikish lingo.",
    "translation": "Привет, друг! Радостное сердце любит слова Vladikish.",
    "question": "Что чувствует сердце (Korno)?",
    "options": ["Радость и счастье (vanti)", "Печаль", "Голод"],
    "correct": 0
  }
]', 35, 15)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Seed Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_title', 'ShibaLingo'),
('site_tagline', 'Учи языки и тайный язык Vladikish вместе с Шиба-Ину!'),
('mascot_name', 'Сиба-сэнсэй'),
('mascot_custom_avatar', ''),
('nvidia_api_key', ''),
('nvidia_model', 'meta/llama-3.3-70b-instruct'),
('maintenance_mode', '0'),
('maintenance_message', 'Сиба-сэнсэй проводит техническое обслуживание! Скоро вернемся 🐾'),
('maintenance_allowed_roles', '["superadmin","admin","beta_tester"]'),
('telegram_bot_token', ''),
('telegram_bot_enabled', '0'),
('telegram_welcome_msg', 'Привет! Я бот Сиба-сэнсэй 🐕. Готов учить слова и напоминать о стрике!')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Seed Vladikish Dictionary
INSERT INTO `conlang_dictionary` (`language_code`, `word`, `part_of_speech`, `translation_ru`, `translation_en`, `translation_it`, `pronunciation`, `example_sentence`, `created_by`) VALUES
('vladikish', 'Aero', 'noun', 'небо / полет', 'sky / flight', 'cielo / volo', 'А́эро', 'Aero zora vanti. (Небо сегодня прекрасное.)', 'manual'),
('vladikish', 'Vladi', 'noun', 'друг / правитель', 'friend / ruler', 'amico / sovrano', 'Вла́ди', 'Vladi, zora mira! (Друг, доброе утро!)', 'manual'),
('vladikish', 'Zora', 'noun', 'день / солнце', 'day / sun', 'giorno / sole', 'Зо́ра', 'Zora velo. (Солнце светит.)', 'manual'),
('vladikish', 'Barka', 'noun', 'собака / верность', 'dog / loyalty', 'cane / lealtà', 'Ба́рка', 'Shiba barka bonu. (Шиба — хорошая собака.)', 'manual'),
('vladikish', 'Bonu', 'adjective', 'хороший / добрый', 'good / kind', 'buono / gentile', 'Бо́ну', 'Vladi bonu est. (Друг хороший.)', 'manual'),
('vladikish', 'Mira', 'greeting', 'привет / мир', 'hello / peace', 'ciao / pace', 'Ми́ра', 'Mira, Shiba! (Привет, Шиба!)', 'manual'),
('vladikish', 'Korno', 'noun', 'сердце / душа', 'heart / soul', 'cuore / anima', 'Ко́рно', 'Korno vanti. (Сердце радостно.)', 'manual'),
('vladikish', 'Vanti', 'adjective', 'счастливый / прекрасный', 'happy / beautiful', 'felice / bello', 'Ва́нти', 'Vanti zora! (Прекрасный день!)', 'manual'),
('vladikish', 'Nox', 'noun', 'ночь / сон', 'night / sleep', 'notte / sonno', 'Нокс', 'Nox mira, Vladi. (Спокойной ночи, Влади.)', 'manual'),
('vladikish', 'Lingo', 'noun', 'слово / язык', 'word / language', 'parola / lingua', 'Ли́нго', 'Vladikish lingo vanti. (Язык Владикиш прекрасен.)', 'manual'),
('vladikish', 'Toro', 'verb', 'любить / ценить', 'to love / appreciate', 'amare', 'То́ро', 'Me toro Barka. (Я люблю собаку.)', 'manual'),
('vladikish', 'Velo', 'verb', 'сиять / лететь', 'to shine / fly', 'volare / splendere', 'Ве́ло', 'Aero velo. (Небо сияет.)', 'manual'),
('vladikish', 'Danko', 'phrase', 'спасибо / благодарю', 'thank you', 'grazie', 'Да́нко', 'Danko, Shiba-sensei! (Спасибо, Сиба-сэнсэй!)', 'manual'),
('vladikish', 'Plaso', 'phrase', 'пожалуйста', 'please', 'per favore', 'Пла́со', 'Plaso, lingo me. (Пожалуйста, поговори со мной.)', 'manual')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Seed Vladikish Grammar
INSERT INTO `conlang_grammar` (`language_code`, `rule_title`, `rule_description`, `rule_examples`, `order_num`) VALUES
('vladikish', 'Базовый порядок слов: SVO (Субъект - Глагол - Объект)', 'В языке Vladikish предложения строятся по структуре: Сначала кто делает, затем действие, затем над чем совершается действие.', 'Me (Я) toro (люблю) Vladikish (Владикиш).', 1),
('vladikish', 'Прилагательные согласуются по мягкости', 'Описательные слова часто оканчиваются на -u (bonu, vanti).', 'Zora vanti = прекрасный день. Barka bonu = хорошая собака.', 2),
('vladikish', 'Множественное число: окончание -s / -i', 'Для образования множественного числа к существительным добавляется суффикс -s или -i.', 'Barka (собака) -> Barkas (собаки).', 3),
('vladikish', 'Прошедшее и будущее время глаголов', 'Прошедшее время образуется добавлением суффикса -ti (toro -> toroti). Будущее время с помощью префикса vo- (vo-toro).', 'Me toroti Aero = Я полюбил небо.', 4)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Seed Skills
INSERT INTO `skills` (`id`, `language_code`, `title`, `icon`, `level`, `order_num`, `description`) VALUES
(1, 'vladikish', 'Приветствия & Знакомство', 'hand', 1, 1, 'Первые слова на Vladikish: Mira, Vladi, Danko'),
(2, 'vladikish', 'Шиба и Природа', 'paw', 1, 2, 'Слова о небе, собаке, солнце и радости'),
(3, 'vladikish', 'Эмоции & Сердце', 'heart', 1, 3, 'Выражаем чувства: любовь, красота, счастье'),
(4, 'en', 'Basics 1: Greetings', 'hand', 1, 1, 'Hello, Good morning, Thank you and friends'),
(5, 'it', 'Le Basi: Saluti', 'sun', 1, 1, 'Ciao, Buongiorno, Grazie e come stai')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Seed Lessons
INSERT INTO `lessons` (`id`, `skill_id`, `title`, `xp_reward`, `order_num`, `lesson_data`) VALUES
(1, 1, 'Урок 1: Первые приветствия', 15, 1, '[
  {
    "type": "multiple_choice",
    "question": "Как переводится слово «Mira» на русский?",
    "prompt": "Mira",
    "options": ["Привет / Мир", "Собака", "Ночь", "Спасибо"],
    "correct": 0,
    "explanation": "«Mira» — главное дружеское приветствие на языке Vladikish!"
  },
  {
    "type": "word_bank",
    "question": "Собери фразу: «Привет, друг!»",
    "prompt": "Привет, друг!",
    "correct_sequence": ["Mira,", "Vladi!"],
    "word_pool": ["Mira,", "Barka", "Vladi!", "Nox", "Bonu", "Aero"],
    "explanation": "Mira = Привет, Vladi = Друг."
  },
  {
    "type": "match_pairs",
    "question": "Сопоставь пары слов",
    "pairs": [
      {"left": "Danko", "right": "Спасибо"},
      {"left": "Vladi", "right": "Друг"},
      {"left": "Mira", "right": "Привет"},
      {"left": "Bonu", "right": "Хороший"}
    ]
  },
  {
    "type": "translate",
    "question": "Переведи на русский:",
    "prompt": "Danko, Vladi!",
    "correct_answers": ["Спасибо, друг!", "Спасибо, друг", "Спасибо друг"],
    "explanation": "Danko = Спасибо, Vladi = Друг."
  }
]'),
(2, 2, 'Урок 2: Небо и верный Шиба', 15, 2, '[
  {
    "type": "multiple_choice",
    "question": "Что означает слово «Barka»?",
    "prompt": "Barka",
    "options": ["Собака / Верность", "Небо", "Солнце", "День"],
    "correct": 0,
    "explanation": "«Barka» в языке Vladikish означает собаку и верного друга."
  },
  {
    "type": "word_bank",
    "question": "Собери предложение: «Собака хорошая»",
    "prompt": "Собака хорошая",
    "correct_sequence": ["Barka", "bonu"],
    "word_pool": ["Barka", "bonu", "Aero", "Nox", "velo"],
    "explanation": "Barka = собака, bonu = хорошая."
  }
]'),
(3, 4, 'Lesson 1: Hello & Thanks', 15, 1, '[
  {
    "type": "multiple_choice",
    "question": "Choose the correct translation for «Hello»:",
    "prompt": "Hello",
    "options": ["Привет", "Пока", "Спасибо", "Пожалуйста"],
    "correct": 0,
    "explanation": "Hello means Привет."
  },
  {
    "type": "word_bank",
    "question": "Assemble: «Thank you, friend»",
    "prompt": "Спасибо, друг",
    "correct_sequence": ["Thank", "you,", "friend"],
    "word_pool": ["Thank", "you,", "dog", "friend", "morning"],
    "explanation": "Thank you = Спасибо, friend = друг."
  }
]'),
(4, 5, 'Lezione 1: Ciao e Grazie', 15, 1, '[
  {
    "type": "multiple_choice",
    "question": "Cosa significa «Buongiorno»?",
    "prompt": "Buongiorno",
    "options": ["Доброе утро / Добрый день", "Спокойной ночи", "Спасибо", "Пока"],
    "correct": 0,
    "explanation": "Buongiorno significa Добрый день."
  }
]')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Seed Chat Session
INSERT INTO `chat_sessions` (`id`, `user_id`, `title`, `language_code`) VALUES
(1, 1, 'Знакомство с Сиба-сэнсэем на Vladikish', 'vladikish')
ON DUPLICATE KEY UPDATE `id` = `id`;

INSERT INTO `chat_messages` (`session_id`, `sender`, `message`, `translation`, `corrections`) VALUES
(1, 'shiba', 'Mira, Vladi! Me Shiba-sensei. Zora vanti est! Como sta korno tu?', 'Привет, Влад! Я Сиба-сэнсэй. Сегодня прекрасный день! Как твое сердечко/настроение?', NULL),
(1, 'user', 'Mira, Shiba! Korno me vanti!', 'Привет, Шиба! Мое сердце радостно!', 'Отличная грамматика! Вы правильно использовали «Mira» и «vanti».')
ON DUPLICATE KEY UPDATE `id` = `id`;
