<?php
namespace App\Core;

class Schema {
    public static function getTableDefinitions(): array {
        return [
            'settings' => "CREATE TABLE IF NOT EXISTS `{prefix}settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `key_name` VARCHAR(100) NOT NULL UNIQUE,
                `value_text` LONGTEXT NULL,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'users' => "CREATE TABLE IF NOT EXISTS `{prefix}users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(60) NOT NULL UNIQUE,
                `email` VARCHAR(120) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `role` ENUM('admin', 'moderator', 'screener', 'editor', 'user') DEFAULT 'user',
                `rank_title` VARCHAR(50) DEFAULT 'Курсант',
                `flight_hours` DECIMAL(8,1) DEFAULT 0.0,
                `reputation_points` INT DEFAULT 0,
                `avatar` VARCHAR(255) DEFAULT NULL,
                `telegram_id` VARCHAR(50) DEFAULT NULL,
                `telegram_username` VARCHAR(60) DEFAULT NULL,
                `telegram_auth_code` VARCHAR(20) DEFAULT NULL,
                `bio` TEXT DEFAULT NULL,
                `is_banned` TINYINT(1) DEFAULT 0,
                `ban_reason` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_telegram (`telegram_id`),
                INDEX idx_role (`role`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'aircraft' => "CREATE TABLE IF NOT EXISTS `{prefix}aircraft` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `slug` VARCHAR(100) NOT NULL UNIQUE,
                `model_name` VARCHAR(150) NOT NULL,
                `manufacturer` VARCHAR(100) NOT NULL,
                `country` VARCHAR(80) NOT NULL,
                `icao_code` VARCHAR(10) DEFAULT NULL,
                `iata_code` VARCHAR(10) DEFAULT NULL,
                `era` VARCHAR(50) DEFAULT 'Modern',
                `category` ENUM('civil', 'military', 'ga', 'retro', 'uav') DEFAULT 'civil',
                `generation` VARCHAR(30) DEFAULT NULL,
                `max_speed_kmh` INT DEFAULT NULL,
                `cruise_speed_kmh` INT DEFAULT NULL,
                `max_range_km` INT DEFAULT NULL,
                `service_ceiling_m` INT DEFAULT NULL,
                `passenger_capacity` INT DEFAULT NULL,
                `max_takeoff_weight_kg` INT DEFAULT NULL,
                `engine_count` INT DEFAULT 2,
                `engine_type` VARCHAR(50) DEFAULT 'Турбовентиляторный',
                `engine_model` VARCHAR(150) DEFAULT NULL,
                `first_flight_year` INT DEFAULT NULL,
                `status` ENUM('active', 'museum', 'concept', 'retired') DEFAULT 'active',
                `overview_short` TEXT DEFAULT NULL,
                `overview_engineering` LONGTEXT DEFAULT NULL,
                `history` LONGTEXT DEFAULT NULL,
                `modifications` LONGTEXT DEFAULT NULL,
                `safety_record` LONGTEXT DEFAULT NULL,
                `cover_image` VARCHAR(255) DEFAULT NULL,
                `model_3d_file` VARCHAR(255) DEFAULT NULL,
                `cockpit_panorama_image` VARCHAR(255) DEFAULT NULL,
                `engine_sound_file` VARCHAR(255) DEFAULT NULL,
                `views_count` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FULLTEXT KEY ft_aircraft (`model_name`, `manufacturer`, `overview_short`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'airports' => "CREATE TABLE IF NOT EXISTS `{prefix}airports` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `slug` VARCHAR(100) NOT NULL UNIQUE,
                `icao` VARCHAR(4) NOT NULL UNIQUE,
                `iata` VARCHAR(3) DEFAULT NULL,
                `name` VARCHAR(150) NOT NULL,
                `city` VARCHAR(100) NOT NULL,
                `country` VARCHAR(80) NOT NULL,
                `latitude` DECIMAL(10, 6) NOT NULL,
                `longitude` DECIMAL(10, 6) NOT NULL,
                `elevation_m` INT DEFAULT 0,
                `timezone` VARCHAR(50) DEFAULT 'UTC',
                `runways_json` LONGTEXT DEFAULT NULL,
                `frequencies_json` LONGTEXT DEFAULT NULL,
                `terminal_info` LONGTEXT DEFAULT NULL,
                `transport_info` LONGTEXT DEFAULT NULL,
                `spotting_spots_json` LONGTEXT DEFAULT NULL,
                `liveatc_stream_url` VARCHAR(255) DEFAULT NULL,
                `webcam_stream_url` VARCHAR(255) DEFAULT NULL,
                `views_count` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FULLTEXT KEY ft_airports (`icao`, `iata`, `name`, `city`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'airlines' => "CREATE TABLE IF NOT EXISTS `{prefix}airlines` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `slug` VARCHAR(100) NOT NULL UNIQUE,
                `icao` VARCHAR(3) NOT NULL UNIQUE,
                `iata` VARCHAR(2) DEFAULT NULL,
                `callsign` VARCHAR(60) DEFAULT NULL,
                `name` VARCHAR(120) NOT NULL,
                `country` VARCHAR(80) NOT NULL,
                `founded_year` INT DEFAULT NULL,
                `fleet_size` INT DEFAULT 0,
                `website` VARCHAR(255) DEFAULT NULL,
                `logo_url` VARCHAR(255) DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `rating_service` DECIMAL(3,1) DEFAULT 4.5,
                `rating_punctuality` DECIMAL(3,1) DEFAULT 4.7,
                `rating_comfort` DECIMAL(3,1) DEFAULT 4.4,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'photos' => "CREATE TABLE IF NOT EXISTS `{prefix}photos` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `aircraft_id` INT DEFAULT NULL,
                `airport_id` INT DEFAULT NULL,
                `airline_id` INT DEFAULT NULL,
                `tail_number` VARCHAR(30) NOT NULL,
                `msn` VARCHAR(30) DEFAULT NULL,
                `photo_url` VARCHAR(255) NOT NULL,
                `thumb_url` VARCHAR(255) NOT NULL,
                `original_url` VARCHAR(255) DEFAULT NULL,
                `camera_model` VARCHAR(100) DEFAULT NULL,
                `lens_model` VARCHAR(100) DEFAULT NULL,
                `focal_length` VARCHAR(30) DEFAULT NULL,
                `shutter_speed` VARCHAR(30) DEFAULT NULL,
                `aperture` VARCHAR(20) DEFAULT NULL,
                `iso` INT DEFAULT NULL,
                `shot_date` DATE DEFAULT NULL,
                `spotting_location` VARCHAR(150) DEFAULT NULL,
                `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                `rejection_reason` VARCHAR(255) DEFAULT NULL,
                `screener_id` INT DEFAULT NULL,
                `screened_at` DATETIME DEFAULT NULL,
                `is_photo_of_day` TINYINT(1) DEFAULT 0,
                `is_photo_of_week` TINYINT(1) DEFAULT 0,
                `views_count` INT DEFAULT 0,
                `likes_count` INT DEFAULT 0,
                `license_type` VARCHAR(50) DEFAULT 'All rights reserved',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (`status`),
                INDEX idx_tail (`tail_number`),
                INDEX idx_user (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'articles' => "CREATE TABLE IF NOT EXISTS `{prefix}articles` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `slug` VARCHAR(120) NOT NULL UNIQUE,
                `title` VARCHAR(200) NOT NULL,
                `category` ENUM('news', 'civil', 'military', 'tech', 'history', 'safety', 'guide') DEFAULT 'news',
                `summary` TEXT DEFAULT NULL,
                `content_blocks_json` LONGTEXT NOT NULL,
                `cover_image` VARCHAR(255) DEFAULT NULL,
                `author_id` INT NOT NULL,
                `is_published` TINYINT(1) DEFAULT 1,
                `views_count` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FULLTEXT KEY ft_articles (`title`, `summary`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'flight_logbook' => "CREATE TABLE IF NOT EXISTS `{prefix}flight_logbook` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `flight_number` VARCHAR(20) NOT NULL,
                `flight_date` DATE NOT NULL,
                `departure_icao` VARCHAR(4) NOT NULL,
                `arrival_icao` VARCHAR(4) NOT NULL,
                `aircraft_type` VARCHAR(50) NOT NULL,
                `seat` VARCHAR(10) DEFAULT NULL,
                `flight_duration_min` INT DEFAULT 0,
                `notes` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_log (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'checklists' => "CREATE TABLE IF NOT EXISTS `{prefix}checklists` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `aircraft_slug` VARCHAR(100) NOT NULL,
                `simulator` VARCHAR(30) DEFAULT 'MSFS / X-Plane',
                `phase` VARCHAR(50) NOT NULL,
                `title` VARCHAR(100) NOT NULL,
                `items_json` LONGTEXT NOT NULL,
                `sort_order` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_aircraft_phase (`aircraft_slug`, `phase`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'quiz_questions' => "CREATE TABLE IF NOT EXISTS `{prefix}quiz_questions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `category` VARCHAR(50) DEFAULT 'aerodynamics',
                `question` TEXT NOT NULL,
                `options_json` LONGTEXT NOT NULL,
                `correct_option_index` INT NOT NULL,
                `explanation` TEXT DEFAULT NULL,
                `difficulty` ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'glossary' => "CREATE TABLE IF NOT EXISTS `{prefix}glossary` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `term` VARCHAR(100) NOT NULL UNIQUE,
                `acronym` VARCHAR(30) DEFAULT NULL,
                `definition` TEXT NOT NULL,
                `category` VARCHAR(50) DEFAULT 'general',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_acronym (`acronym`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'clubs' => "CREATE TABLE IF NOT EXISTS `{prefix}clubs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `slug` VARCHAR(100) NOT NULL UNIQUE,
                `name` VARCHAR(120) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `category` VARCHAR(50) DEFAULT 'general',
                `creator_id` INT NOT NULL,
                `members_count` INT DEFAULT 1,
                `logo_url` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'club_posts' => "CREATE TABLE IF NOT EXISTS `{prefix}club_posts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `club_id` INT NOT NULL,
                `user_id` INT NOT NULL,
                `title` VARCHAR(200) NOT NULL,
                `content` LONGTEXT NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_club (`club_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'comments' => "CREATE TABLE IF NOT EXISTS `{prefix}comments` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `entity_type` ENUM('photo', 'article', 'aircraft', 'club_post') NOT NULL,
                `entity_id` INT NOT NULL,
                `user_id` INT NOT NULL,
                `parent_id` INT DEFAULT NULL,
                `content` TEXT NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `is_deleted` TINYINT(1) DEFAULT 0,
                INDEX idx_entity (`entity_type`, `entity_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'messages' => "CREATE TABLE IF NOT EXISTS `{prefix}messages` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `sender_id` INT NOT NULL,
                `receiver_id` INT NOT NULL,
                `content` TEXT NOT NULL,
                `is_read` TINYINT(1) DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dialog (`sender_id`, `receiver_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'audit_logs' => "CREATE TABLE IF NOT EXISTS `{prefix}audit_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `admin_id` INT NOT NULL,
                `action` VARCHAR(100) NOT NULL,
                `target_type` VARCHAR(50) NOT NULL,
                `target_id` INT DEFAULT NULL,
                `details_json` LONGTEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_action (`action`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'ai_conversations' => "CREATE TABLE IF NOT EXISTS `{prefix}ai_conversations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `session_id` VARCHAR(64) NOT NULL,
                `user_id` INT DEFAULT NULL,
                `role_persona` VARCHAR(50) DEFAULT 'copilot',
                `messages_json` LONGTEXT NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_session (`session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'api_cache' => "CREATE TABLE IF NOT EXISTS `{prefix}api_cache` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `cache_key` VARCHAR(150) NOT NULL UNIQUE,
                `response_data` LONGTEXT NOT NULL,
                `expires_at` DATETIME NOT NULL,
                INDEX idx_exp (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'fids_cache' => "CREATE TABLE IF NOT EXISTS `{prefix}fids_cache` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `airport_icao` VARCHAR(4) NOT NULL,
                `flight_type` ENUM('departure', 'arrival') NOT NULL,
                `flight_data_json` LONGTEXT NOT NULL,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fids (`airport_icao`, `flight_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'adsb_cache' => "CREATE TABLE IF NOT EXISTS `{prefix}adsb_cache` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `icao24` VARCHAR(10) NOT NULL UNIQUE,
                `callsign` VARCHAR(20) DEFAULT NULL,
                `lat` DECIMAL(10, 6) NOT NULL,
                `lon` DECIMAL(10, 6) NOT NULL,
                `altitude` INT DEFAULT 0,
                `speed` INT DEFAULT 0,
                `heading` INT DEFAULT 0,
                `squawk` VARCHAR(10) DEFAULT '1200',
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ];
    }

    public static function createTables(string $prefix = 'va_'): void {
        $pdo = Database::getPdo();
        $definitions = self::getTableDefinitions();

        foreach ($definitions as $table => $sql) {
            $query = str_replace('{prefix}', $prefix, $sql);
            $pdo->exec($query);
        }
    }

    public static function dropTables(string $prefix = 'va_'): void {
        $pdo = Database::getPdo();
        $definitions = array_keys(self::getTableDefinitions());

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        foreach ($definitions as $table) {
            $tableName = $prefix . $table;
            $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`;");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    }

    public static function checkTablesExist(string $prefix = 'va_'): array {
        $pdo = Database::getPdo();
        $definitions = array_keys(self::getTableDefinitions());
        $existing = [];

        $stmt = $pdo->query("SHOW TABLES");
        $allTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($definitions as $table) {
            $t = $prefix . $table;
            if (in_array($t, $allTables)) {
                $existing[] = $t;
            }
        }
        return $existing;
    }

    public static function seedDemoData(string $prefix = 'va_', int $adminId = 1): void {
        $pdo = Database::getPdo();

        // 1. Settings default
        $settings = [
            'site_name' => 'VladAero',
            'site_tagline' => 'Главный авиационный портал и бортовой интеллект',
            'site_description' => 'Энциклопедия авиации, интерактивный радар полетов, споттинг-галерея, аэродромные метеоданные METAR/TAF, симуляторы и ИИ-штурман.',
            'base_url' => '',
            'maintenance_mode' => '0',
            'default_theme' => 'dark',
            'ui_sound_enabled' => '1',
            'radar_refresh_seconds' => '8',
            'ai_active_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'ai_base_url' => 'https://api.openai.com/v1',
            'ai_api_key' => '',
            'firecrawl_api_key' => '',
            'telegram_bot_token' => '',
            'telegram_bot_username' => 'VladAeroBot',
            'hero_title' => 'Почувствуйте пульс мировой авиации',
            'hero_subtitle' => 'Сверхточные ТТХ, онлайн-радар ADS-B, живые табло FIDS и бортовой ИИ-штурман VladAero Copilot.'
        ];

        foreach ($settings as $k => $v) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}settings` (`key_name`, `value_text`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value_text` = :v2");
            $stmt->execute(['k' => $k, 'v' => $v, 'v2' => $v]);
        }

        // 2. Demo Aircraft
        $aircraftList = [
            [
                'slug' => 'boeing-787-9-dreamliner',
                'model_name' => 'Boeing 787-9 Dreamliner',
                'manufacturer' => 'Boeing',
                'country' => 'США',
                'icao_code' => 'B789',
                'iata_code' => '789',
                'era' => 'Современная (Modern)',
                'category' => 'civil',
                'generation' => '4-е поколение',
                'max_speed_kmh' => 954,
                'cruise_speed_kmh' => 903,
                'max_range_km' => 14140,
                'service_ceiling_m' => 13100,
                'passenger_capacity' => 290,
                'max_takeoff_weight_kg' => 254000,
                'engine_count' => 2,
                'engine_type' => 'Турбовентиляторный ТРДД',
                'engine_model' => 'GEnx-1B / Rolls-Royce Trent 1000',
                'first_flight_year' => 2013,
                'status' => 'active',
                'overview_short' => 'Широкофюзеляжный дальнемагистральный лайнер с композитным планером более 50%, снижающим расход топлива на 20-25%.',
                'overview_engineering' => 'Конструкция фюзеляжа выполнена из углепластика методом намотки цельных секций. Электрическая архитектура без отбора воздуха от двигателей (Bleedless System), крыло с гибкими законцовками Raked Wingtips.',
                'history' => 'Программа 787 была запущена компанией Boeing в 2004 году как 7E7. Первый полет модификации 787-9 состоялся 17 сентября 2013 года, а эксплуатация началась авиакомпанией Air New Zealand в 2014 году.',
                'modifications' => '787-8 (базовая версия), 787-9 (удлиненная версия повышенной дальности), 787-10 (максимальная вместимость).',
                'safety_record' => 'Один из самых надежных современных авиалайнеров мира. Эксплуатируется сотнями авиакомпаний на трансконтинентальных трассах без катастрофических происшествий с потерей борта.',
                'cover_image' => 'assets/images/aircraft/b787.jpg'
            ],
            [
                'slug' => 'sukhoi-su-57-felon',
                'model_name' => 'Су-57 (ПАК ФА / Felon)',
                'manufacturer' => 'ОКБ Сухого / ОАК',
                'country' => 'Россия',
                'icao_code' => 'SU57',
                'iata_code' => 'S57',
                'era' => 'Современная (Modern)',
                'category' => 'military',
                'generation' => '5-е поколение',
                'max_speed_kmh' => 2600,
                'cruise_speed_kmh' => 1800,
                'max_range_km' => 3500,
                'service_ceiling_m' => 20000,
                'passenger_capacity' => 1,
                'max_takeoff_weight_kg' => 35500,
                'engine_count' => 2,
                'engine_type' => 'ТРДДФ с УВТ',
                'engine_model' => 'АЛ-41Ф1 / «Изделие 30»',
                'first_flight_year' => 2010,
                'status' => 'active',
                'overview_short' => 'Многофункциональный истребитель пятого поколения с технологией малозаметности (Stealth), сверхманевренностью и всеракурсным радарным комплексом Ш-121.',
                'overview_engineering' => 'Интегральная аэродинамическая компоновка с поворотными наплывами (LEVCON). Радиопоглощающие покрытия, внутренние отсеки вооружения, оптико-электронная система ОЛС-50М и радар с АФАР.',
                'history' => 'Разрабатывался по программе ПАК ФА (Т-50). Первый полет 29 января 2010 года в Комсомольске-на-Амуре под управлением Героя России Сергея Богдана.',
                'modifications' => 'Т-50 (прототипы), Су-57 серийный, Су-57Э (экспортный), двухместная модификация для управления БПЛА «Охотник».',
                'safety_record' => 'Высокая живучесть конструкции, дублированные электродистанционные каналы управления (ЭДСУ).',
                'cover_image' => 'assets/images/aircraft/su57.jpg'
            ],
            [
                'slug' => 'airbus-a350-1000',
                'model_name' => 'Airbus A350-1000 XWB',
                'manufacturer' => 'Airbus',
                'country' => 'Евросоюз',
                'icao_code' => 'A35K',
                'iata_code' => '351',
                'era' => 'Современная (Modern)',
                'category' => 'civil',
                'generation' => '4-е поколение',
                'max_speed_kmh' => 945,
                'cruise_speed_kmh' => 903,
                'max_range_km' => 16100,
                'service_ceiling_m' => 12600,
                'passenger_capacity' => 410,
                'max_takeoff_weight_kg' => 319000,
                'engine_count' => 2,
                'engine_type' => 'Турбовентиляторный ТРДД',
                'engine_model' => 'Rolls-Royce Trent XWB-97',
                'first_flight_year' => 2016,
                'status' => 'active',
                'overview_short' => 'Флагман дальнемагистрального флота Airbus Extra Wide Body с непревзойденным комфортом Airspace и шестью тележками основного шасси.',
                'overview_engineering' => 'Крыло из углекомпозита площадью 442 м², адаптивная механизация с автоматическим отклонением закрылков на крейсерском эшелоне для оптимизации подъемной силы.',
                'history' => 'Создан как конкурент Boeing 777-300ER и 777-9. Первый полет совершил 24 ноября 2016 года в Тулузе.',
                'modifications' => 'A350-900, A350-900ULR (сверхдальний), A350-1000, A350F (грузовой).',
                'safety_record' => 'Безупречная статистика надежности на мировых маршрутах.',
                'cover_image' => 'assets/images/aircraft/a350.jpg'
            ],
            [
                'slug' => 'tu-154m-careless',
                'model_name' => 'Ту-154М (Careless)',
                'manufacturer' => 'ОКБ Туполева',
                'country' => 'СССР / Россия',
                'icao_code' => 'T154',
                'iata_code' => '154',
                'era' => 'Реактивная эра (Jet Age)',
                'category' => 'retro',
                'generation' => '2-е поколение',
                'max_speed_kmh' => 950,
                'cruise_speed_kmh' => 900,
                'max_range_km' => 3900,
                'service_ceiling_m' => 12100,
                'passenger_capacity' => 180,
                'max_takeoff_weight_kg' => 102000,
                'engine_count' => 3,
                'engine_type' => 'ТРДД',
                'engine_model' => 'Д-30КУ-154',
                'first_flight_year' => 1982,
                'status' => 'museum',
                'overview_short' => 'Легендарный советский трехдвигательный среднемагистральный авиалайнер со стреловидным крылом 35° и непревзойденной скоростью полета.',
                'overview_engineering' => 'Т-образное хвостовое оперение, три двигателя Д-30КУ-154 с реверсом тяги на внешних силовых установках, мощное 6-колесное шасси для грунтовых аэродромов.',
                'history' => 'Главная «рабочая лошадка» Аэрофлота на протяжении четырех десятилетий. Выпущено свыше 1000 экземпляров.',
                'modifications' => 'Ту-154, Ту-154А, Ту-154Б/Б-2, Ту-154М, Ту-155 (криогенный), Ту-154М-ЛК-1.',
                'safety_record' => 'Один из самых массовых лайнеров отечественной авиации.',
                'cover_image' => 'assets/images/aircraft/tu154.jpg'
            ]
        ];

        foreach ($aircraftList as $ac) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}aircraft` 
                (`slug`, `model_name`, `manufacturer`, `country`, `icao_code`, `iata_code`, `era`, `category`, `generation`, 
                 `max_speed_kmh`, `cruise_speed_kmh`, `max_range_km`, `service_ceiling_m`, `passenger_capacity`, `max_takeoff_weight_kg`, 
                 `engine_count`, `engine_type`, `engine_model`, `first_flight_year`, `status`, `overview_short`, `overview_engineering`, 
                 `history`, `modifications`, `safety_record`, `cover_image`)
                VALUES (:slug, :model_name, :manufacturer, :country, :icao_code, :iata_code, :era, :category, :generation,
                 :max_speed_kmh, :cruise_speed_kmh, :max_range_km, :service_ceiling_m, :passenger_capacity, :max_takeoff_weight_kg,
                 :engine_count, :engine_type, :engine_model, :first_flight_year, :status, :overview_short, :overview_engineering,
                 :history, :modifications, :safety_record, :cover_image)
                ON DUPLICATE KEY UPDATE `model_name` = VALUES(`model_name`);");
            $stmt->execute($ac);
        }

        // 3. Demo Airports
        $airportsList = [
            [
                'slug' => 'uuee-sheremetyevo',
                'icao' => 'UUEE',
                'iata' => 'SVO',
                'name' => 'Международный аэропорт Шереметьево имени А.С. Пушкина',
                'city' => 'Москва',
                'country' => 'Россия',
                'latitude' => 55.972642,
                'longitude' => 37.414581,
                'elevation_m' => 190,
                'timezone' => 'Europe/Moscow',
                'runways_json' => json_encode([
                    ['ident' => '06L/24R', 'length_m' => 3700, 'width_m' => 60, 'surface' => 'Бетон', 'ils' => 'Cat IIIb'],
                    ['ident' => '06C/24C', 'length_m' => 3500, 'width_m' => 60, 'surface' => 'Бетон', 'ils' => 'Cat IIIb'],
                    ['ident' => '06R/24L', 'length_m' => 3200, 'width_m' => 60, 'surface' => 'Бетон', 'ils' => 'Cat IIIb']
                ]),
                'frequencies_json' => json_encode([
                    ['name' => 'Sheremetyevo ATIS', 'freq' => '126.375'],
                    ['name' => 'Sheremetyevo Tower', 'freq' => '131.500'],
                    ['name' => 'Sheremetyevo Ground', 'freq' => '119.000'],
                    ['name' => 'Moscow Approach', 'freq' => '127.200']
                ]),
                'terminal_info' => 'Терминалы B и C (Северный терминальный комплекс для внутренних и международных рейсов), Терминалы D, E, F (Южный комплекс). Межтерминальный подземный поезд.',
                'transport_info' => 'Поезд Аэроэкспресс от Белорусского вокзала, скоростная платная трасса М-11, автобусы от станций метро Ховрино и Планерная.',
                'spotting_spots_json' => json_encode([
                    ['name' => 'Труба (ВПП 06R)', 'coords' => '55.9812, 37.4321', 'lens' => '70-200mm', 'best_time' => 'Утро / Первая половина дня', 'description' => 'Классическая точка для съемки взлетов с полосы 06R.'],
                    ['name' => 'Терминал B (Паркинг)', 'coords' => '55.9835, 37.4110', 'lens' => '100-400mm', 'best_time' => 'После полудня', 'description' => 'Отличный вид на рулежные дорожки и перрон северного комплекса.']
                ]),
                'liveatc_stream_url' => 'https://www.liveatc.net/play/uuee_twr.pls',
                'webcam_stream_url' => 'https://api.aviationwx.org/v1/airports/uuee/webcams'
            ],
            [
                'slug' => 'kjfk-john-f-kennedy',
                'icao' => 'KJFK',
                'iata' => 'JFK',
                'name' => 'John F. Kennedy International Airport',
                'city' => 'Нью-Йорк',
                'country' => 'США',
                'latitude' => 40.641311,
                'longitude' => -73.778139,
                'elevation_m' => 4,
                'timezone' => 'America/New_York',
                'runways_json' => json_encode([
                    ['ident' => '04L/22R', 'length_m' => 3682, 'width_m' => 61, 'surface' => 'Асфальт/Бетон', 'ils' => 'Cat III'],
                    ['ident' => '13L/31R', 'length_m' => 4423, 'width_m' => 61, 'surface' => 'Бетон', 'ils' => 'Cat I'],
                    ['ident' => '13R/31L', 'length_m' => 3048, 'width_m' => 61, 'surface' => 'Бетон', 'ils' => 'Cat II']
                ]),
                'frequencies_json' => json_encode([
                    ['name' => 'Kennedy ATIS', 'freq' => '128.725'],
                    ['name' => 'Kennedy Tower', 'freq' => '119.100'],
                    ['name' => 'Kennedy Ground', 'freq' => '121.900'],
                    ['name' => 'New York Approach', 'freq' => '125.700']
                ]),
                'terminal_info' => '6 действующих терминалов (1, 4, 5, 7, 8). AirTrain JFK соединяет терминалы со станциями метро Howard Beach и Jamaica.',
                'transport_info' => 'AirTrain JFK, метро New York Subway, Long Island Rail Road (LIRR), такси NYC Yellow Cabs.',
                'spotting_spots_json' => json_encode([
                    ['name' => 'The Mounds (Brookville Park)', 'coords' => '40.6620, -73.7480', 'lens' => '70-200mm', 'best_time' => 'После полудня', 'description' => 'Знаменитый холм с видом на глиссаду полос 22L и 22R.'],
                    ['name' => 'TWA Hotel Observation Deck', 'coords' => '40.6450, -73.7770', 'lens' => '24-105mm', 'best_time' => 'Весь день', 'description' => 'Смотровая площадка на крыше отеля TWA с видом на полосу 04L/22R.']
                ]),
                'liveatc_stream_url' => 'https://www.liveatc.net/play/kjfk_twr.pls',
                'webcam_stream_url' => 'https://api.aviationwx.org/v1/airports/kjfk/webcams'
            ],
            [
                'slug' => 'egll-london-heathrow',
                'icao' => 'EGLL',
                'iata' => 'LHR',
                'name' => 'London Heathrow Airport',
                'city' => 'Лондон',
                'country' => 'Великобритания',
                'latitude' => 51.470022,
                'longitude' => -0.454295,
                'elevation_m' => 25,
                'timezone' => 'Europe/London',
                'runways_json' => json_encode([
                    ['ident' => '09L/27R', 'length_m' => 3902, 'width_m' => 50, 'surface' => 'Асфальт', 'ils' => 'Cat III'],
                    ['ident' => '09R/27L', 'length_m' => 3658, 'width_m' => 50, 'surface' => 'Асфальт', 'ils' => 'Cat III']
                ]),
                'frequencies_json' => json_encode([
                    ['name' => 'Heathrow ATIS', 'freq' => '128.075'],
                    ['name' => 'Heathrow Tower', 'freq' => '118.500'],
                    ['name' => 'Heathrow Ground', 'freq' => '121.900']
                ]),
                'terminal_info' => 'Терминалы 2, 3, 4, 5. Самый загруженный аэропорт Европы.',
                'transport_info' => 'Heathrow Express до вокзала Paddington, линия метро Piccadilly Line, линия Elizabeth Line.',
                'spotting_spots_json' => json_encode([
                    ['name' => 'Myrtle Avenue', 'coords' => '51.4680, -0.4280', 'lens' => '24-70mm / 70-200mm', 'best_time' => 'Утро', 'description' => 'Всемирно известная поляна у торца полосы 27L для потрясающих посадочных кадров.']
                ]),
                'liveatc_stream_url' => 'https://www.liveatc.net/play/egll_twr.pls',
                'webcam_stream_url' => 'https://api.aviationwx.org/v1/airports/egll/webcams'
            ]
        ];

        foreach ($airportsList as $ap) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}airports`
                (`slug`, `icao`, `iata`, `name`, `city`, `country`, `latitude`, `longitude`, `elevation_m`, `timezone`, 
                 `runways_json`, `frequencies_json`, `terminal_info`, `transport_info`, `spotting_spots_json`, 
                 `liveatc_stream_url`, `webcam_stream_url`)
                VALUES (:slug, :icao, :iata, :name, :city, :country, :latitude, :longitude, :elevation_m, :timezone,
                 :runways_json, :frequencies_json, :terminal_info, :transport_info, :spotting_spots_json,
                 :liveatc_stream_url, :webcam_stream_url)
                ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);");
            $stmt->execute($ap);
        }

        // 4. Demo Airlines
        $airlinesList = [
            [
                'slug' => 'aeroflot',
                'icao' => 'AFL',
                'iata' => 'SU',
                'callsign' => 'AEROFLOT',
                'name' => 'Аэрофлот — Российские авиалинии',
                'country' => 'Россия',
                'founded_year' => 1923,
                'fleet_size' => 170,
                'website' => 'https://www.aeroflot.ru',
                'logo_url' => 'assets/images/airlines/afl.png',
                'description' => 'Национальный авиаперевозчик России, одна из старейших авиакомпаний мира. Базовый хаб — Международный аэропорт Шереметьево (Москва).',
                'rating_service' => 4.6,
                'rating_punctuality' => 4.8,
                'rating_comfort' => 4.5
            ],
            [
                'slug' => 'emirates',
                'icao' => 'UAE',
                'iata' => 'EK',
                'callsign' => 'EMIRATES',
                'name' => 'Emirates Airline',
                'country' => 'ОАЭ',
                'founded_year' => 1985,
                'fleet_size' => 260,
                'website' => 'https://www.emirates.com',
                'logo_url' => 'assets/images/airlines/uae.png',
                'description' => 'Крупнейший в мире оператор Airbus A380 и Boeing 777. Базовый хаб — Международный аэропорт Дубай (DXB).',
                'rating_service' => 4.9,
                'rating_punctuality' => 4.7,
                'rating_comfort' => 4.9
            ]
        ];

        foreach ($airlinesList as $al) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}airlines`
                (`slug`, `icao`, `iata`, `callsign`, `name`, `country`, `founded_year`, `fleet_size`, `website`, `logo_url`, `description`, `rating_service`, `rating_punctuality`, `rating_comfort`)
                VALUES (:slug, :icao, :iata, :callsign, :name, :country, :founded_year, :fleet_size, :website, :logo_url, :description, :rating_service, :rating_punctuality, :rating_comfort)
                ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);");
            $stmt->execute($al);
        }

        // 5. Demo Glossary
        $glossary = [
            ['term' => 'V1 (Decision Speed)', 'acronym' => 'V1', 'definition' => 'Скорость принятия решения на разбеге. До достижения V1 взлет может быть безопасно прерван при отказе двигателя. После V1 взлет должен быть продолжен в любом случае.', 'category' => 'takeoff'],
            ['term' => 'Vr (Rotate Speed)', 'acronym' => 'Vr', 'definition' => 'Скорость подъема передней стойки шасси для создания взлетного угла тангажа.', 'category' => 'takeoff'],
            ['term' => 'ETOPS', 'acronym' => 'ETOPS', 'definition' => 'Extended-range Twin-engine Operational Performance Standards — стандарты выполнения дальних полетов двухдвигательными самолетами над безориентирной местностью и океанами на удалении до запасного аэродрома.', 'category' => 'regulations'],
            ['term' => 'TCAS', 'acronym' => 'TCAS', 'definition' => 'Traffic Collision Avoidance System — бортовая система предупреждения опасных сближений в воздухе, выдающая пилотам команды вертикального маневра (Resolution Advisory).', 'category' => 'avionics'],
            ['term' => 'APU (ВСУ)', 'acronym' => 'APU', 'definition' => 'Auxiliary Power Unit (Вспомогательная силовая установка) — автономный газотурбинный двигатель для питания бортовых систем электроэнергией и сжатым воздухом для запуска основных двигателей и кондиционирования на стоянке.', 'category' => 'engineering'],
            ['term' => 'NOTAM', 'acronym' => 'NOTAM', 'definition' => 'Notice to Air Missions / Notice to Airmen — оперативные извещения для экипажей и наземного персонала о состоянии аэродромов, радионавигационных средств, опасных зонах и ограничениях полетов.', 'category' => 'navigation'],
            ['term' => 'METAR', 'acronym' => 'METAR', 'definition' => 'Meteorological Aerodrome Report — стандартизированный регулярный код фактической авиационной погоды на аэродроме, выпускаемый каждые 30 минут.', 'category' => 'weather']
        ];

        foreach ($glossary as $g) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}glossary` (`term`, `acronym`, `definition`, `category`) 
                VALUES (:term, :acronym, :definition, :category) ON DUPLICATE KEY UPDATE `definition` = VALUES(`definition`);");
            $stmt->execute($g);
        }

        // 6. Demo Quizzes
        $quizzes = [
            [
                'category' => 'aerodynamics',
                'question' => 'Что означает скорость V1 при выполнении взлета самолета?',
                'options_json' => json_encode([
                    'Скорость отрыва основных стоек шасси от полосы',
                    'Скорость принятия решения (после нее взлет прерывать запрещено)',
                    'Скорость уборки механизации крыла',
                    'Максимально допустимая скорость колес по прочности шин'
                ]),
                'correct_option_index' => 1,
                'explanation' => 'V1 — это скорость принятия решения. Если отказ происходит до V1, взлет прерывается. После V1 самолет обязан продолжить взлет.',
                'difficulty' => 'easy'
            ],
            [
                'category' => 'weather',
                'question' => 'Что означает группа «27015G25KT» в метеосводке METAR?',
                'options_json' => json_encode([
                    'Ветер направлением 270°, скорость 15 узлов, порывы до 25 узлов',
                    'Температура +27°C, точка росы +15°C, влажность 25%',
                    'Видимость 2700 метров с туманом на 15 полосе',
                    'Курс посадки 270, глиссада 15 градусов, эшелон 25'
                ]),
                'correct_option_index' => 0,
                'explanation' => 'Первые три цифры (270) — направление ветра в градусах, следующие две (15) — постоянная скорость в узлах (KT), литера G означает Gusts (порывы) до 25 узлов.',
                'difficulty' => 'easy'
            ],
            [
                'category' => 'navigation',
                'question' => 'Какой международный код ответчика (Squawk) выставляется экипажем при отказе радиосвязи?',
                'options_json' => json_encode([
                    'Squawk 7700',
                    'Squawk 7600',
                    'Squawk 7500',
                    'Squawk 1200'
                ]),
                'correct_option_index' => 1,
                'explanation' => '7600 — радиоотказ (Radio Failure). 7700 — общая аварийная ситуация (General Emergency), 7500 — незаконное вмешательство / захват судна (Hijack).',
                'difficulty' => 'medium'
            ]
        ];

        foreach ($quizzes as $q) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}quiz_questions` (`category`, `question`, `options_json`, `correct_option_index`, `explanation`, `difficulty`)
                VALUES (:category, :question, :options_json, :correct_option_index, :explanation, :difficulty)");
            $stmt->execute($q);
        }

        // 7. Demo Checklists
        $checklists = [
            [
                'aircraft_slug' => 'boeing-787-9-dreamliner',
                'simulator' => 'MSFS 2024 / X-Plane 12',
                'phase' => 'before_start',
                'title' => 'Before Start Checklist (Перед запуском)',
                'items_json' => json_encode([
                    ['item' => 'Flight Deck Access Door', 'status' => 'LOCKED & ARMED'],
                    ['item' => 'Passenger Signs', 'status' => 'ON'],
                    ['item' => 'MCP & Flight Directors', 'status' => 'SET, F/D ON'],
                    ['item' => 'Takeoff Speeds (V1, Vr, V2)', 'status' => 'SET IN PFD'],
                    ['item' => 'CDU Preflight', 'status' => 'COMPLETED'],
                    ['item' => 'Altimeters (QNH)', 'status' => 'CROSS-CHECKED & SET'],
                    ['item' => 'Fuel Pumps', 'status' => 'ON'],
                    ['item' => 'Beacon Light (Anti-Collision)', 'status' => 'ON'],
                    ['item' => 'Parking Brake', 'status' => 'SET']
                ]),
                'sort_order' => 1
            ],
            [
                'aircraft_slug' => 'boeing-787-9-dreamliner',
                'simulator' => 'MSFS 2024 / X-Plane 12',
                'phase' => 'before_takeoff',
                'title' => 'Before Takeoff Checklist (Перед взлетом)',
                'items_json' => json_encode([
                    ['item' => 'Flaps', 'status' => 'SET FOR TAKEOFF, GREEN'],
                    ['item' => 'Flight Controls', 'status' => 'FREE & FULL TRAVEL'],
                    ['item' => 'Autothrottle', 'status' => 'ARMED'],
                    ['item' => 'Transponder (TCAS)', 'status' => 'TA/RA, TAIL ASSIGNED'],
                    ['item' => 'Strobe Lights & Landing Lights', 'status' => 'ON'],
                    ['item' => 'Cabin Crew', 'status' => 'ADVISED FOR DEPARTURE'],
                    ['item' => 'Weather Radar & Terrain Display', 'status' => 'TESTED & ON']
                ]),
                'sort_order' => 2
            ]
        ];

        foreach ($checklists as $cl) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}checklists` (`aircraft_slug`, `simulator`, `phase`, `title`, `items_json`, `sort_order`)
                VALUES (:aircraft_slug, :simulator, :phase, :title, :items_json, :sort_order)");
            $stmt->execute($cl);
        }

        // 8. Demo Articles
        $articles = [
            [
                'slug' => 'welcome-to-vladaero-portal',
                'title' => 'Добро пожаловать на авиационный портал VladAero!',
                'category' => 'news',
                'summary' => 'Открытие новой цифровой экосистемы для пилотов, споттеров, симмеров и энтузиастов авиации со встроенным AI-штурманом.',
                'content_blocks_json' => json_encode([
                    ['type' => 'hud_callout', 'title' => 'FLIGHT CLEARANCE GRANTED', 'text' => 'VladAero открывает свои двери для всех, кто влюблен в небо и крылья.'],
                    ['type' => 'paragraph', 'text' => 'Портал VladAero спроектирован как единая интеллектуальная платформа. Здесь объединены детальная техническая база знаний гражданской и военной авиации, интерактивный трекер воздушного пространства на базе ADS-B данных, споттинг-галерея со строгим и честным скринингом, аэродромные FIDS-табло, инструменты расчета полетов и встроенный AI Copilot.'],
                    ['type' => 'quote', 'author' => 'Андрей Николаевич Туполев', 'text' => 'Хорошо летать могут только красивые самолеты.'],
                    ['type' => 'paragraph', 'text' => 'Изучайте каталоги бортов, слушайте живой радиообмен диспетчеров LiveATC, тренируйтесь в интерактивной летной школе и общайтесь с единомышленниками в клубах по интересам. Желаем чистого неба и мягких посадок!']
                ]),
                'cover_image' => 'assets/images/articles/welcome.jpg',
                'author_id' => $adminId,
                'is_published' => 1
            ],
            [
                'slug' => 'metar-taf-complete-aviation-weather-guide',
                'title' => 'Как читать метеосводки METAR и прогнозы TAF как профессиональный пилот',
                'category' => 'guide',
                'summary' => 'Полное практическое руководство по расшифровке авиационной метеорологической информации: ветер, видимость, явления, облачность и QNH.',
                'content_blocks_json' => json_encode([
                    ['type' => 'hud_callout', 'title' => 'WEATHER BRIEFING ESSENTIALS', 'text' => 'Погода — ключевой фактор безопасности в авиации. Грамотное чтение METAR спасает жизни.'],
                    ['type' => 'paragraph', 'text' => 'Каждые 30 минут все аэропорты мира выпускают код METAR. Стандартный формат выглядит так: METAR UUEE 041130Z 24005MPS 9999 BKN020 18/11 Q1018 R24C/090070 NOSIG.'],
                    ['type' => 'accordion', 'title' => 'Разбор полей по элементам', 'content' => '1. UUEE — код ICAO аэродрома.\n2. 041130Z — 4 число месяца, 11:30 по времени UTC (Zulu).\n3. 24005MPS — ветер 240 градусов, 5 м/с.\n4. 9999 — видимость более 10 км.\n5. BKN020 — значительная облачность (5-7 октантов) с нижней кромкой 2000 футов (600 м).\n6. 18/11 — температура +18°C, точка росы +11°C.\n7. Q1018 — давление QNH 1018 гПа.\n8. NOSIG — без существенных изменений в ближайшие 2 часа.'],
                    ['type' => 'paragraph', 'text' => 'Воспользуйтесь нашим интерактивным декодером METAR/TAF в разделе «Калькуляторы», чтобы мгновенно расшифровывать сводки любых аэропортов мира с наглядной подсказкой опасных явлений.']
                ]),
                'cover_image' => 'assets/images/articles/weather.jpg',
                'author_id' => $adminId,
                'is_published' => 1
            ]
        ];

        foreach ($articles as $art) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}articles` 
                (`slug`, `title`, `category`, `summary`, `content_blocks_json`, `cover_image`, `author_id`, `is_published`)
                VALUES (:slug, :title, :category, :summary, :content_blocks_json, :cover_image, :author_id, :is_published)
                ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);");
            $stmt->execute($art);
        }

        // 9. Demo Clubs
        $clubs = [
            [
                'slug' => 'heavy-metal-spotting',
                'name' => 'Шереметьево & Heavy Jets Споттеры',
                'description' => 'Клуб любителей споттинга широкофюзеляжных бортов (B777, A350, B747, Ил-96) в московском авиаузле и регионах.',
                'category' => 'spotting',
                'creator_id' => $adminId,
                'members_count' => 18
            ],
            [
                'slug' => 'vatsim-ifr-aviators',
                'name' => 'VATSIM & IVAO Виртуальные Пилоты',
                'description' => 'Сообщество симмеров, выполняющих регулярные полеты по приборам IFR с живым диспетчерским контролем.',
                'category' => 'simulation',
                'creator_id' => $adminId,
                'members_count' => 42
            ]
        ];

        foreach ($clubs as $cl) {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}clubs` (`slug`, `name`, `description`, `category`, `creator_id`, `members_count`)
                VALUES (:slug, :name, :description, :category, :creator_id, :members_count)
                ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);");
            $stmt->execute($cl);
        }
    }
}
