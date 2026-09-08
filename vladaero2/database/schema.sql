-- ============================================================
-- VladAero — Full Database Schema
-- MySQL 5.7+ / MariaDB 10.3+
-- Prefix: {prefix} (default: vld_)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- 0. SYSTEM / SETTINGS
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `setting_group` VARCHAR(50) DEFAULT 'general',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}languages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(5) NOT NULL UNIQUE,
    `name` VARCHAR(50) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `is_default` TINYINT(1) DEFAULT 0,
    `sort_order` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}admin_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50),
    `entity_id` INT UNSIGNED,
    `old_value` TEXT,
    `new_value` TEXT,
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 1. USERS & AUTH
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(255),
    `password_hash` VARCHAR(255),
    `display_name` VARCHAR(100) NOT NULL,
    `avatar` VARCHAR(500) DEFAULT NULL,
    `bio` TEXT,
    `role` ENUM('user','spotter','editor','moderator','admin') DEFAULT 'user',
    `rank` VARCHAR(50) DEFAULT 'Курсант',
    `reputation` INT UNSIGNED DEFAULT 0,
    `telegram_id` BIGINT,
    `telegram_username` VARCHAR(100),
    `telegram_linked` TINYINT(1) DEFAULT 0,
    `tfa_enabled` TINYINT(1) DEFAULT 0,
    `tfa_secret` VARCHAR(255),
    `language` VARCHAR(5) DEFAULT 'ru',
    `theme` ENUM('light','dark','system') DEFAULT 'system',
    `sound_enabled` TINYINT(1) DEFAULT 1,
    `email_notifications` TINYINT(1) DEFAULT 0,
    `privacy_logbook` TINYINT(1) DEFAULT 1,
    `privacy_stats` TINYINT(1) DEFAULT 1,
    `privacy_albums` TINYINT(1) DEFAULT 1,
    `status` ENUM('active','banned','deleted') DEFAULT 'active',
    `email_verified_at` TIMESTAMP NULL,
    `last_login_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_role` (`role`),
    INDEX `idx_tg` (`telegram_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}user_sessions` (
    `id` VARCHAR(128) PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `payload` TEXT,
    `last_activity` INT UNSIGNED NOT NULL,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}password_resets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `used` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}user_follows` (
    `follower_id` INT UNSIGNED NOT NULL,
    `following_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`follower_id`, `following_id`),
    FOREIGN KEY (`follower_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`following_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}user_bookmarks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL, -- aircraft, airport, article, photo, event
    `entity_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_bookmark` (`user_id`, `entity_type`, `entity_id`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}user_achievements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `achievement_key` VARCHAR(100) NOT NULL,
    `earned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_achievement` (`user_id`, `achievement_key`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}user_favorite_aircraft` (
    `user_id` INT UNSIGNED NOT NULL,
    `aircraft_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `aircraft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 2. AIRCRAFT (Энциклопедия самолётов)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}aircraft_manufacturers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `name_en` VARCHAR(200),
    `country` VARCHAR(100),
    `country_code` CHAR(2),
    `icao_code` VARCHAR(10),
    `logo` VARCHAR(500),
    `slug` VARCHAR(200) NOT NULL UNIQUE,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}aircraft` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `manufacturer_id` INT UNSIGNED,
    `name` VARCHAR(200) NOT NULL,
    `name_en` VARCHAR(200),
    `icao_code` VARCHAR(10),
    `iata_code` VARCHAR(10),
    `slug` VARCHAR(300) NOT NULL UNIQUE,
    `type` ENUM('civilian','military','general_aviation','uav','historic') DEFAULT 'civilian',
    `category` ENUM('jet','turboprop','piston','helicopter','glider','balloon') DEFAULT 'jet',
    `generation` VARCHAR(50),
    `era` VARCHAR(100),
    `first_flight` DATE,
    `introduction_year` SMALLINT,
    `production_status` ENUM('in_production','ended','prototype','concept') DEFAULT 'ended',
    `country_of_origin` VARCHAR(100),
    `country_code` CHAR(2),
    `image` VARCHAR(500),
    `silhouette` VARCHAR(500),
    `model_3d` VARCHAR(500), -- GLB/glTF path
    `seatmap` VARCHAR(500),
    `blueprint` VARCHAR(500),

    -- Simple TTX (summary for regular users)
    `ttx_summary` JSON,

    -- Detailed TTX (for professionals / simmerers)
    `max_speed_knots` SMALLINT,
    `cruise_speed_knots` SMALLINT,
    `range_km` SMALLINT,
    `ceiling_ft` SMALLINT,
    `passengers_min` SMALLINT,
    `passengers_max` SMALLINT,
    `crew` SMALLINT,
    `engines_count` TINYINT DEFAULT 1,
    `engine_type` VARCHAR(100),
    `engine_model` VARCHAR(200),
    `length_m` DECIMAL(6,2),
    `wingspan_m` DECIMAL(6,2),
    `height_m` DECIMAL(6,2),
    `wing_area_m2` DECIMAL(7,2),
    `mtow_kg` INT UNSIGNED,
    `max_fuel_kg` INT UNSIGNED,
    `range_km_max` SMALLINT,
    `v1_knots` SMALLINT,
    `vr_knots` SMALLINT,
    `v2_knots` SMALLINT,
    `approach_speed_knots` SMALLINT,

    -- Extended JSON for all additional specs
    `specs_detailed` JSON,

    `history` TEXT,
    `combat_use` TEXT,
    `safety_record` TEXT,
    `modifications` JSON, -- array of modification names/descriptions
    `cost_usd` BIGINT UNSIGNED,
    `hourly_cost_usd` INT UNSIGNED,
    `fuel_per_pax_km` DECIMAL(5,2),

    `seo_title` VARCHAR(200),
    `seo_description` VARCHAR(500),
    `og_image` VARCHAR(500),

    `views_count` INT UNSIGNED DEFAULT 0,
    `is_published` TINYINT(1) DEFAULT 0,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_type` (`type`),
    INDEX `idx_category` (`category`),
    INDEX `idx_manufacturer` (`manufacturer_id`),
    INDEX `idx_slug` (`slug`),
    FULLTEXT KEY `ft_search` (`name`, `name_en`, `icao_code`, `iata_code`),
    FOREIGN KEY (`manufacturer_id`) REFERENCES `{prefix}aircraft_manufacturers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}aircraft_i18n` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `aircraft_id` INT UNSIGNED NOT NULL,
    `lang` VARCHAR(5) NOT NULL,
    `name` VARCHAR(200),
    `history` TEXT,
    `combat_use` TEXT,
    `safety_record` TEXT,
    `seo_title` VARCHAR(200),
    `seo_description` VARCHAR(500),
    UNIQUE KEY `uk_i18n` (`aircraft_id`, `lang`),
    FOREIGN KEY (`aircraft_id`) REFERENCES `{prefix}aircraft`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}aircraft_photos` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `aircraft_id` INT UNSIGNED NOT NULL,
    `photo_id` INT UNSIGNED,
    `sort_order` SMALLINT DEFAULT 0,
    FOREIGN KEY (`aircraft_id`) REFERENCES `{prefix}aircraft`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}aircraft_incidents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `aircraft_id` INT UNSIGNED NOT NULL,
    `date` DATE,
    `description` TEXT NOT NULL,
    `location` VARCHAR(200),
    `fatalities` SMALLINT DEFAULT 0,
    `survivors` SMALLINT DEFAULT 0,
    `airline` VARCHAR(200),
    `registration` VARCHAR(50),
    `source_url` VARCHAR(500),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_aircraft` (`aircraft_id`),
    FOREIGN KEY (`aircraft_id`) REFERENCES `{prefix}aircraft`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 3. AIRLINES
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}airlines` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `name_en` VARCHAR(200),
    `iata_code` CHAR(2),
    `icao_code` CHAR(3),
    `country` VARCHAR(100),
    `country_code` CHAR(2),
    `hub_airports` JSON, -- array of airport_ids
    `fleet` JSON, -- summary fleet info
    `logo` VARCHAR(500),
    `slug` VARCHAR(200) NOT NULL UNIQUE,
    `founded_year` SMALLINT,
    `website` VARCHAR(500),
    `description` TEXT,
    `seo_title` VARCHAR(200),
    `seo_description` VARCHAR(500),
    `views_count` INT UNSIGNED DEFAULT 0,
    `is_published` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FULLTEXT KEY `ft_search` (`name`, `name_en`, `iata_code`, `icao_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}airline_fleet` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `airline_id` INT UNSIGNED NOT NULL,
    `aircraft_id` INT UNSIGNED,
    `aircraft_type_name` VARCHAR(200),
    `count` SMALLINT DEFAULT 1,
    `config` VARCHAR(200), -- e.g. "C12/Y180"
    `livery_image` VARCHAR(500),
    FOREIGN KEY (`airline_id`) REFERENCES `{prefix}airlines`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 4. AIRPORTS
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}airports` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(300) NOT NULL,
    `name_en` VARCHAR(300),
    `city` VARCHAR(200),
    `city_en` VARCHAR(200),
    `country` VARCHAR(100),
    `country_code` CHAR(2),
    `continent` VARCHAR(50),
    `iata_code` CHAR(3),
    `icao_code` CHAR(4),
    `elevation_ft` INT,
    `latitude` DECIMAL(10, 7),
    `longitude` DECIMAL(10, 7),
    `timezone` VARCHAR(50),
    `runways` JSON, -- [{name, length_m, width_m, surface, lighted, ils_cat}]
    `terminals` JSON,
    `frequencies` JSON, -- [{name, freq_mhz}]
    `image` VARCHAR(500),
    `slug` VARCHAR(300) NOT NULL UNIQUE,
    `description` TEXT,
    `transport_info` TEXT,
    `spotting_guide` TEXT,
    `metar_cache` TEXT,
    `metar_cache_time` TIMESTAMP NULL,
    `seo_title` VARCHAR(200),
    `seo_description` VARCHAR(500),
    `views_count` INT UNSIGNED DEFAULT 0,
    `is_published` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_iata` (`iata_code`),
    INDEX `idx_icao` (`icao_code`),
    INDEX `idx_coords` (`latitude`, `longitude`),
    FULLTEXT KEY `ft_search` (`name`, `name_en`, `city`, `city_en`, `iata_code`, `icao_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}airport_spotting_spots` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `airport_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `latitude` DECIMAL(10, 7),
    `longitude` DECIMAL(10, 7),
    `runway_side` VARCHAR(50),
    `recommended_focal_lengths` VARCHAR(200),
    `access_info` TEXT,
    `rating_avg` DECIMAL(2,1) DEFAULT 0,
    `rating_count` INT UNSIGNED DEFAULT 0,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`airport_id`) REFERENCES `{prefix}airports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 5. PHOTOS (Spotting)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}photos` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(300),
    `file_path` VARCHAR(500) NOT NULL,
    `thumb_path` VARCHAR(500),
    `webp_path` VARCHAR(500),
    `file_size` INT UNSIGNED,
    `width` SMALLINT,
    `height` SMALLINT,

    -- Aviation metadata
    `registration` VARCHAR(50),
    `serial_number` VARCHAR(50),
    `airline_id` INT UNSIGNED,
    `aircraft_id` INT UNSIGNED,
    `airport_id` INT UNSIGNED,
    `livery_name` VARCHAR(200),

    -- EXIF
    `camera` VARCHAR(200),
    `lens` VARCHAR(200),
    `focal_length` VARCHAR(50),
    `aperture` VARCHAR(20),
    `shutter_speed` VARCHAR(50),
    `iso` SMALLINT,
    `shot_date` DATE,

    -- Spotting location
    `spotting_spot_id` INT UNSIGNED,
    `latitude` DECIMAL(10, 7),
    `longitude` DECIMAL(10, 7),

    -- Aircraft status at photo time
    `aircraft_status` ENUM('active','stored','scrapped','wreck','preserved') DEFAULT 'active',

    -- Rights / License
    `license` ENUM('all_rights','cc_by','cc_by_sa','cc_by_nc','cc_by_nc_sa','free_use') DEFAULT 'all_rights',
    `watermark_applied` TINYINT(1) DEFAULT 0,
    `allow_download_original` TINYINT(1) DEFAULT 0,

    -- Moderation
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `reject_reason` VARCHAR(500),
    `moderated_by` INT UNSIGNED,
    `moderated_at` TIMESTAMP NULL,

    -- Stats
    `views_count` INT UNSIGNED DEFAULT 0,
    `likes_count` INT UNSIGNED DEFAULT 0,
    `comments_count` INT UNSIGNED DEFAULT 0,

    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_user` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_aircraft` (`aircraft_id`),
    INDEX `idx_airport` (`airport_id`),
    INDEX `idx_date` (`shot_date`),
    FULLTEXT KEY `ft_search` (`title`, `registration`, `livery_name`),
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}photo_likes` (
    `user_id` INT UNSIGNED NOT NULL,
    `photo_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `photo_id`),
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`photo_id`) REFERENCES `{prefix}photos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 6. ARTICLES & BLOG
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}articles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(500) NOT NULL,
    `slug` VARCHAR(500) NOT NULL UNIQUE,
    `excerpt` TEXT,
    `content` LONGTEXT,
    `content_type` ENUM('longread','post') DEFAULT 'longread',
    `cover_image` VARCHAR(500),
    `category` VARCHAR(100),
    `tags` JSON,
    `related_aircraft` JSON,
    `related_airports` JSON,

    `seo_title` VARCHAR(200),
    `seo_description` VARCHAR(500),
    `og_image` VARCHAR(500),

    `status` ENUM('draft','pending','published','archived') DEFAULT 'draft',
    `published_at` TIMESTAMP NULL,
    `views_count` INT UNSIGNED DEFAULT 0,
    `likes_count` INT UNSIGNED DEFAULT 0,
    `comments_count` INT UNSIGNED DEFAULT 0,

    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_user` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_category` (`category`),
    INDEX `idx_published` (`published_at`),
    FULLTEXT KEY `ft_search` (`title`, `excerpt`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}article_drafts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `article_id` INT UNSIGNED NOT NULL,
    `content` LONGTEXT,
    `saved_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_article` (`article_id`),
    FOREIGN KEY (`article_id`) REFERENCES `{prefix}articles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 7. NEWS
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}news` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(500) NOT NULL,
    `slug` VARCHAR(500) NOT NULL UNIQUE,
    `excerpt` TEXT,
    `content` LONGTEXT,
    `cover_image` VARCHAR(500),
    `category` ENUM('civilian','military','engines','incidents','innovations','other') DEFAULT 'civilian',
    `source_url` VARCHAR(500),
    `source_name` VARCHAR(200),
    `is_breaking` TINYINT(1) DEFAULT 0,

    `seo_title` VARCHAR(200),
    `seo_description` VARCHAR(500),
    `og_image` VARCHAR(500),

    `published_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `views_count` INT UNSIGNED DEFAULT 0,
    `comments_count` INT UNSIGNED DEFAULT 0,

    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_category` (`category`),
    INDEX `idx_published` (`published_at`),
    FULLTEXT KEY `ft_search` (`title`, `excerpt`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 8. COMMENTS
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}comments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL, -- photo, article, news, aircraft, airport
    `entity_id` INT UNSIGNED NOT NULL,
    `parent_id` INT UNSIGNED DEFAULT NULL,
    `content` TEXT NOT NULL,
    `status` ENUM('visible','hidden','deleted') DEFAULT 'visible',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_parent` (`parent_id`),
    INDEX `idx_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 9. EVENTS (Calendar)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}events` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(300) NOT NULL,
    `slug` VARCHAR(300) NOT NULL UNIQUE,
    `description` TEXT,
    `cover_image` VARCHAR(500),
    `category` ENUM('airshow','spotting_day','conference','virtual','meetup','other') DEFAULT 'other',
    `start_date` DATE NOT NULL,
    `end_date` DATE,
    `start_time` TIME,
    `latitude` DECIMAL(10, 7),
    `longitude` DECIMAL(10, 7),
    `location_name` VARCHAR(300),
    `country` VARCHAR(100),
    `country_code` CHAR(2),
    `airport_id` INT UNSIGNED,
    `external_url` VARCHAR(500),

    `seo_title` VARCHAR(200),
    `seo_description` VARCHAR(500),

    `status` ENUM('upcoming','ongoing','past','cancelled') DEFAULT 'upcoming',
    `created_by` INT UNSIGNED,
    `is_published` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_dates` (`start_date`, `end_date`),
    INDEX `idx_category` (`category`),
    FULLTEXT KEY `ft_search` (`title`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}event_attendees` (
    `event_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `status` ENUM('going','maybe','interested') DEFAULT 'going',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`, `user_id`),
    FOREIGN KEY (`event_id`) REFERENCES `{prefix}events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 10. QUIZZES
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}quizzes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(300) NOT NULL,
    `slug` VARCHAR(300) NOT NULL UNIQUE,
    `description` TEXT,
    `cover_image` VARCHAR(500),
    `category` ENUM('guess_aircraft','theory','entertainment','situational') DEFAULT 'entertainment',
    `difficulty` ENUM('easy','medium','hard','expert') DEFAULT 'medium',
    `time_mode` ENUM('timed','untimed','per_question') DEFAULT 'untimed',
    `time_limit` SMALLINT DEFAULT 0, -- seconds, 0 = unlimited
    `question_time` SMALLINT DEFAULT 30,
    `created_by` INT UNSIGNED,
    `is_published` TINYINT(1) DEFAULT 0,
    `plays_count` INT UNSIGNED DEFAULT 0,
    `avg_rating` DECIMAL(3,2) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FULLTEXT KEY `ft_search` (`title`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}quiz_questions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quiz_id` INT UNSIGNED NOT NULL,
    `question_text` TEXT NOT NULL,
    `question_image` VARCHAR(500),
    `question_type` ENUM('single_choice','multiple_choice','text_input','image_guess') DEFAULT 'single_choice',
    `options` JSON, -- [{text, image, is_correct}]
    `correct_answer` TEXT,
    `explanation` TEXT,
    `explanation_image` VARCHAR(500),
    `sort_order` SMALLINT DEFAULT 0,
    `points` SMALLINT DEFAULT 10,
    FOREIGN KEY (`quiz_id`) REFERENCES `{prefix}quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}quiz_results` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quiz_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `score` SMALLINT NOT NULL,
    `total_points` SMALLINT NOT NULL,
    `time_taken` SMALLINT, -- seconds
    `answers` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_quiz_user` (`quiz_id`, `user_id`),
    FOREIGN KEY (`quiz_id`) REFERENCES `{prefix}quizzes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}quiz_leaderboard` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quiz_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `best_score` SMALLINT NOT NULL,
    `best_time` SMALLINT,
    `attempts_count` SMALLINT DEFAULT 1,
    `last_played` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_lb` (`quiz_id`, `user_id`),
    FOREIGN KEY (`quiz_id`) REFERENCES `{prefix}quizzes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 11. COMPARE (Versus)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}comparisons` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(500) NOT NULL UNIQUE,
    `aircraft_ids` JSON NOT NULL, -- [1, 2, 3, 4]
    `title` VARCHAR(500),
    `votes` JSON, -- {aircraft_id: count}
    `views_count` INT UNSIGNED DEFAULT 0,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}comparison_votes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `comparison_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `aircraft_id` INT UNSIGNED NOT NULL,
    `argument` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_vote` (`comparison_id`, `user_id`),
    FOREIGN KEY (`comparison_id`) REFERENCES `{prefix}comparisons`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 12. FLIGHT LOGBOOK (Personal)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}flight_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `flight_date` DATE NOT NULL,
    `airline` VARCHAR(200),
    `flight_number` VARCHAR(20),
    `aircraft_type` VARCHAR(100),
    `aircraft_registration` VARCHAR(50),
    `departure_airport` VARCHAR(10),
    `arrival_airport` VARCHAR(10),
    `departure_name` VARCHAR(200),
    `arrival_name` VARCHAR(200),
    `seat` VARCHAR(20),
    `seat_class` ENUM('economy','premium','business','first') DEFAULT 'economy',
    `flight_time_min` SMALLINT,
    `distance_km` SMALLINT,
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_date` (`flight_date`),
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 13. AIRLINE REVIEWS (by passengers)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}airline_reviews` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `airline_id` INT UNSIGNED NOT NULL,
    `flight_number` VARCHAR(20),
    `rating_service` TINYINT CHECK (rating_service BETWEEN 1 AND 10),
    `rating_punctuality` TINYINT CHECK (rating_punctuality BETWEEN 1 AND 10),
    `rating_comfort` TINYINT CHECK (rating_comfort BETWEEN 1 AND 10),
    `rating_food` TINYINT CHECK (rating_food BETWEEN 1 AND 10),
    `rating_overall` TINYINT CHECK (rating_overall BETWEEN 1 AND 10),
    `title` VARCHAR(300),
    `content` TEXT,
    `aircraft_type` VARCHAR(100),
    `route` VARCHAR(200),
    `flight_date` DATE,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_airline` (`airline_id`),
    INDEX `idx_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`airline_id`) REFERENCES `{prefix}airlines`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 14. CLUBS / Communities
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}clubs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `slug` VARCHAR(200) NOT NULL UNIQUE,
    `description` TEXT,
    `cover_image` VARCHAR(500),
    `logo` VARCHAR(500),
    `category` VARCHAR(100), -- airport, aircraft_type, simulator, general
    `topic` VARCHAR(200),
    `members_count` INT UNSIGNED DEFAULT 0,
    `created_by` INT UNSIGNED,
    `is_published` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FULLTEXT KEY `ft_search` (`name`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}club_members` (
    `club_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `role` ENUM('member','moderator','admin') DEFAULT 'member',
    `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`club_id`, `user_id`),
    FOREIGN KEY (`club_id`) REFERENCES `{prefix}clubs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 15. CHECKLISTS
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}checklists` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(300) NOT NULL,
    `aircraft_id` INT UNSIGNED,
    `phase` ENUM('pre_flight','taxi','takeoff','cruise','approach','landing','emergency') DEFAULT 'pre_flight',
    `items` JSON NOT NULL, -- [{item: "Flaps", setting: "5", checked: false}]
    `voice_enabled` TINYINT(1) DEFAULT 0,
    `created_by` INT UNSIGNED,
    `is_published` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`aircraft_id`) REFERENCES `{prefix}aircraft`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 16. GLOSSARY
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}glossary` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `term` VARCHAR(200) NOT NULL,
    `term_en` VARCHAR(200),
    `slug` VARCHAR(200) NOT NULL UNIQUE,
    `definition` TEXT NOT NULL,
    `definition_en` TEXT,
    `category` VARCHAR(100), -- aerodynamics, meteorology, avionics, construction, radio
    `related_terms` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FULLTEXT KEY `ft_search` (`term`, `term_en`, `definition`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 17. RADIO PHRASEOLOGY
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}phraseology` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category` ENUM('taxi','takeoff','cruise','approach','landing','emergency','general') DEFAULT 'general',
    `phase_ru` VARCHAR(200),
    `phase_en` VARCHAR(200),
    `pilot_phrase_ru` TEXT NOT NULL,
    `pilot_phrase_en` TEXT,
    `controller_phrase_ru` TEXT,
    `controller_phrase_en` TEXT,
    `context` TEXT,
    `sort_order` SMALLINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 18. MEDIA (Videos, Audio, 360 panoramas)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}media` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `type` ENUM('video_youtube','video_vimeo','video_upload','audio_liveatc','audio_engines','panorama_360') NOT NULL,
    `title` VARCHAR(300),
    `url` VARCHAR(500),
    `file_path` VARCHAR(500),
    `entity_type` VARCHAR(50), -- aircraft, airport
    `entity_id` INT UNSIGNED,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 19. LIVERIES (for simmers)
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}liveries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `aircraft_id` INT UNSIGNED NOT NULL,
    `airline_id` INT UNSIGNED,
    `airline_name` VARCHAR(200),
    `name` VARCHAR(300) NOT NULL,
    `description` TEXT,
    `simulator` ENUM('msfs','xplane','dcs','other') DEFAULT 'msfs',
    `version` VARCHAR(50),
    `file_path` VARCHAR(500),
    `file_size` INT UNSIGNED,
    `external_url` VARCHAR(500),
    `preview_image` VARCHAR(500),
    `downloads_count` INT UNSIGNED DEFAULT 0,
    `rating_avg` DECIMAL(3,2) DEFAULT 0,
    `rating_count` INT UNSIGNED DEFAULT 0,
    `created_by` INT UNSIGNED,
    `is_published` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`aircraft_id`) REFERENCES `{prefix}aircraft`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 20. VIRTUAL AIRLINE (VA) — Flight tracking for simmers
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}va_companies` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `slug` VARCHAR(200) NOT NULL UNIQUE,
    `logo` VARCHAR(500),
    `description` TEXT,
    `created_by` INT UNSIGNED,
    `pilots_count` INT UNSIGNED DEFAULT 0,
    `is_published` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}va_pilots` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `va_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `callsign` VARCHAR(20),
    `rank` VARCHAR(50) DEFAULT 'Новичок',
    `total_flights` INT UNSIGNED DEFAULT 0,
    `total_hours` DECIMAL(7,2) DEFAULT 0,
    `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_va_user` (`va_id`, `user_id`),
    FOREIGN KEY (`va_id`) REFERENCES `{prefix}va_companies`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}va_flights` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `pilot_id` INT UNSIGNED NOT NULL,
    `flight_number` VARCHAR(20),
    `departure` CHAR(4),
    `arrival` CHAR(4),
    `aircraft_type` VARCHAR(100),
    `route` TEXT,
    `distance_km` SMALLINT,
    `flight_time_min` SMALLINT,
    `fuel_used_kg` SMALLINT,
    `network` ENUM('vatsim','ivao','poscon','offline') DEFAULT 'offline',
    `pilot_report` TEXT,
    `status` ENUM('planned','in_progress','completed','diverted') DEFAULT 'completed',
    `filed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pilot` (`pilot_id`),
    FOREIGN KEY (`pilot_id`) REFERENCES `{prefix}va_pilots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 21. GALLERY / Photo of the Day
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}photo_of_day` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `photo_id` INT UNSIGNED NOT NULL,
    `date` DATE NOT NULL UNIQUE,
    `chosen_by` ENUM('community','editorial') DEFAULT 'editorial',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`photo_id`) REFERENCES `{prefix}photos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 22. RADAR / Flight Tracking
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}radar_subscriptions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `subscription_type` ENUM('aircraft','flight','squawk','airport') NOT NULL,
    `subscription_value` VARCHAR(100) NOT NULL, -- registration, flight number, squawk code, ICAO
    `notify_squawk_emergency` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}radar_track_archive` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `icao24` VARCHAR(10),
    `callsign` VARCHAR(20),
    `registration` VARCHAR(20),
    `timestamp` INT UNSIGNED,
    `latitude` DECIMAL(10, 7),
    `longitude` DECIMAL(10, 7),
    `altitude_ft` INT,
    `ground_speed_knots` SMALLINT,
    `heading` SMALLINT,
    `vertical_speed` SMALLINT,
    `squawk` VARCHAR(4),
    INDEX `idx_icao` (`icao24`),
    INDEX `idx_ts` (`timestamp`),
    INDEX `idx_reg` (`registration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 23. NOTIFICATIONS
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL, -- comment, like, follow, mod_status, achievement, radar_alert
    `title` VARCHAR(300),
    `message` TEXT,
    `entity_type` VARCHAR(50),
    `entity_id` INT UNSIGNED,
    `url` VARCHAR(500),
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_read` (`user_id`, `is_read`),
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 24. AI Chat History
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}ai_conversations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `session_id` VARCHAR(100),
    `entity_type` VARCHAR(50), -- widget or agent
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}ai_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `conversation_id` INT UNSIGNED NOT NULL,
    `role` ENUM('user','assistant','system','tool') NOT NULL,
    `content` LONGTEXT,
    `tool_calls` JSON,
    `tool_results` JSON,
    `tokens_used` INT UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_conv` (`conversation_id`),
    FOREIGN KEY (`conversation_id`) REFERENCES `{prefix}ai_conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 25. POLL / Voting within articles
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}polls` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entity_type` VARCHAR(50),
    `entity_id` INT UNSIGNED,
    `question` VARCHAR(500) NOT NULL,
    `options` JSON NOT NULL, -- [{text, votes_count}]
    `allow_multiple` TINYINT(1) DEFAULT 0,
    `ends_at` TIMESTAMP NULL,
    `created_by` INT UNSIGNED,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}poll_votes` (
    `poll_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `option_index` TINYINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`poll_id`, `user_id`),
    FOREIGN KEY (`poll_id`) REFERENCES `{prefix}polls`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 26. CACHE METADATA
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}cache_metadata` (
    `cache_key` VARCHAR(255) PRIMARY KEY,
    `expires_at` TIMESTAMP NOT NULL,
    `size_bytes` INT UNSIGNED DEFAULT 0,
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 27. SITEMAP
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `{prefix}sitemap_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `url` VARCHAR(1000) NOT NULL,
    `priority` DECIMAL(2,1) DEFAULT 0.5,
    `changefreq` ENUM('always','hourly','daily','weekly','monthly','yearly','never') DEFAULT 'weekly',
    `lastmod` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `generated` TINYINT(1) DEFAULT 0,
    INDEX `idx_generated` (`generated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ────────────────────────────────────────────────────────────
-- COMPATIBILITY TABLES (used by controllers)
-- ────────────────────────────────────────────────────────────

-- AI Chat (simple version used by AiWidgetController)
CREATE TABLE IF NOT EXISTS `{prefix}ai_chats` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `session_id` VARCHAR(100),
    `message` TEXT NOT NULL,
    `role` ENUM('user','assistant') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- News Categories
CREATE TABLE IF NOT EXISTS `{prefix}news_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `sort_order` SMALLINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photo Ratings
CREATE TABLE IF NOT EXISTS `{prefix}photo_ratings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `photo_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_rating` (`photo_id`, `user_id`),
    FOREIGN KEY (`photo_id`) REFERENCES `{prefix}photos`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checklist Items (separate table for interactive checklists)
CREATE TABLE IF NOT EXISTS `{prefix}checklist_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `checklist_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(300) NOT NULL,
    `item` VARCHAR(300) NOT NULL,
    `setting` VARCHAR(100),
    `sort_order` SMALLINT DEFAULT 0,
    FOREIGN KEY (`checklist_id`) REFERENCES `{prefix}checklists`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Spelling compatibility aliases
-- Controllers use: spotting_points -> airport_spotting_spots
-- Controllers use: fleet -> airline_fleet
-- Controllers use: manufacturer (string) -> aircraft_manufacturers

-- Make news table compatible: add type, category_id, author_id, is_pinned, views, status columns
ALTER TABLE `{prefix}news`
    ADD COLUMN IF NOT EXISTS `type` ENUM('news','article') DEFAULT 'news' AFTER `content`,
    ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED AFTER `type`,
    ADD COLUMN IF NOT EXISTS `author_id` INT UNSIGNED AFTER `category_id`,
    ADD COLUMN IF NOT EXISTS `is_pinned` TINYINT(1) DEFAULT 0 AFTER `author_id`,
    ADD COLUMN IF NOT EXISTS `status` ENUM('draft','published','archived') DEFAULT 'draft' AFTER `is_pinned`,
    ADD COLUMN IF NOT EXISTS `views` INT UNSIGNED DEFAULT 0 AFTER `status`;

-- Make photos table compatible
ALTER TABLE `{prefix}photos`
    ADD COLUMN IF NOT EXISTS `description` TEXT AFTER `title`,
    ADD COLUMN IF NOT EXISTS `file_path` VARCHAR(500) AFTER `description`,
    ADD COLUMN IF NOT EXISTS `airport_id` INT UNSIGNED AFTER `airport_id`,
    ADD COLUMN IF NOT EXISTS `views` INT UNSIGNED DEFAULT 0 AFTER `views_count`,
    ADD COLUMN IF NOT EXISTS `rating` DECIMAL(3,2) DEFAULT 0 AFTER `views`,
    ADD COLUMN IF NOT EXISTS `rating_count` INT UNSIGNED DEFAULT 0 AFTER `rating`;

-- Make users table compatible
ALTER TABLE `{prefix}users`
    ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(100) NOT NULL DEFAULT '' AFTER `email`,
    ADD COLUMN IF NOT EXISTS `avatar_url` VARCHAR(500) AFTER `avatar`,
    ADD COLUMN IF NOT EXISTS `rank` VARCHAR(50) DEFAULT 'Курсант' AFTER `role`,
    ADD COLUMN IF NOT EXISTS `show_email` TINYINT(1) DEFAULT 0 AFTER `email_notifications`,
    ADD COLUMN IF NOT EXISTS `show_profile` TINYINT(1) DEFAULT 1 AFTER `show_email`;

-- Make airports table compatible
ALTER TABLE `{prefix}airports`
    ADD COLUMN IF NOT EXISTS `runway_length` INT AFTER `elevation_ft`,
    ADD COLUMN IF NOT EXISTS `airport_type` VARCHAR(50) DEFAULT 'international' AFTER `runway_length`,
    ADD COLUMN IF NOT EXISTS `elevation` INT AFTER `elevation_ft`;

-- Make airlines table compatible
ALTER TABLE `{prefix}airlines`
    ADD COLUMN IF NOT EXISTS `founded` VARCHAR(20) AFTER `founded_year`,
    ADD COLUMN IF NOT EXISTS `hub_airport` VARCHAR(100) AFTER `founded`,
    ADD COLUMN IF NOT EXISTS `alliance` VARCHAR(50) AFTER `hub_airport`,
    ADD COLUMN IF NOT EXISTS `fleet_size` INT UNSIGNED DEFAULT 0 AFTER `alliance`,
    ADD COLUMN IF NOT EXISTS `logo_url` VARCHAR(500) AFTER `logo`;

-- Make aircraft table compatible
ALTER TABLE `{prefix}aircraft`
    ADD COLUMN IF NOT EXISTS `manufacturer` VARCHAR(200) AFTER `name`,
    ADD COLUMN IF NOT EXISTS `type_code` VARCHAR(20) AFTER `manufacturer`,
    ADD COLUMN IF NOT EXISTS `engine_type` VARCHAR(50) AFTER `type_code`,
    ADD COLUMN IF NOT EXISTS `engine_count` TINYINT DEFAULT 2 AFTER `engine_type`,
    ADD COLUMN IF NOT EXISTS `passengers` SMALLINT AFTER `engine_count`,
    ADD COLUMN IF NOT EXISTS `description` TEXT AFTER `history`,
    ADD COLUMN IF NOT EXISTS `wikipedia_url` VARCHAR(500) AFTER `description`;

-- Spelling compatibility view
CREATE OR REPLACE VIEW `{prefix}spotting_points` AS
    SELECT id, airport_id, name, description, latitude, longitude,
           runway_side as `position`, recommended_focal_lengths,
           access_info, rating_avg, rating_count, created_by, created_at
    FROM `{prefix}airport_spotting_spots`;

-- Fleet compatibility view
CREATE OR REPLACE VIEW `{prefix}fleet` AS
    SELECT id, airline_id, aircraft_id, aircraft_type_name, count, config, livery_image
    FROM `{prefix}airline_fleet`;

-- ────────────────────────────────────────────────────────────
-- SEED DATA
-- ────────────────────────────────────────────────────────────

INSERT INTO `{prefix}languages` (`code`, `name`, `is_active`, `is_default`, `sort_order`) VALUES
('ru', 'Русский', 1, 1, 1),
('en', 'English', 1, 0, 2);

INSERT INTO `{prefix}settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('site_name', 'VladAero', 'general'),
('site_tagline', 'Авиационный портал', 'general'),
('default_theme', 'dark', 'appearance'),
('maintenance_mode', '0', 'system'),
('installed', '1', 'system');
