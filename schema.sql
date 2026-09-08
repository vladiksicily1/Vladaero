-- VladAero Aviation Portal Database Schema
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- Compatible with MySQL 5.7+, 8.0+, MariaDB 10.3+

SET FOREIGN_KEY_CHECKS = 0;

-- 1. System Settings
CREATE TABLE IF NOT EXISTS `va_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` LONGTEXT NULL,
    `setting_group` VARCHAR(50) DEFAULT 'general',
    `is_secret` TINYINT(1) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Users and Roles
CREATE TABLE IF NOT EXISTS `va_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(191) NOT NULL UNIQUE,
    `username` VARCHAR(60) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NULL,
    `avatar` VARCHAR(255) DEFAULT 'default_avatar.svg',
    `role` ENUM('admin', 'editor', 'moderator', 'spotter', 'pilot', 'user') DEFAULT 'user',
    `rank_title` VARCHAR(60) DEFAULT 'Курсант',
    `xp_points` INT DEFAULT 0,
    `is_banned` TINYINT(1) DEFAULT 0,
    `ban_reason` VARCHAR(255) NULL,
    `two_factor_secret` VARCHAR(100) NULL,
    `units_system` ENUM('metric', 'aviation_imperial') DEFAULT 'aviation_imperial',
    `theme_preference` ENUM('auto', 'dark', 'light') DEFAULT 'auto',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_role` (`role`),
    INDEX `idx_xp` (`xp_points`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Manufacturers
CREATE TABLE IF NOT EXISTS `va_manufacturers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `country` VARCHAR(60) NOT NULL,
    `founded_year` INT NULL,
    `logo_url` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Aircraft Categories
CREATE TABLE IF NOT EXISTS `va_aircraft_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `title_ru` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `icon_svg` VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Aircraft Database
CREATE TABLE IF NOT EXISTS `va_aircraft` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `manufacturer_id` INT NULL,
    `category_code` VARCHAR(50) NOT NULL,
    `slug` VARCHAR(120) NOT NULL UNIQUE,
    `model_name` VARCHAR(120) NOT NULL,
    `icao_code` VARCHAR(10) NULL,
    `iata_code` VARCHAR(10) NULL,
    `first_flight_date` DATE NULL,
    `production_years` VARCHAR(50) NULL,
    `status` ENUM('in_service', 'out_of_production', 'development', 'historic') DEFAULT 'in_service',
    `short_desc` VARCHAR(255) NULL,
    `full_desc` LONGTEXT NULL,
    `hero_image` VARCHAR(255) NULL,
    `blueprint_image` VARCHAR(255) NULL,
    `model_3d_file` VARCHAR(255) NULL,
    `views_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_icao` (`icao_code`),
    INDEX `idx_category` (`category_code`),
    FULLTEXT INDEX `ft_aircraft` (`model_name`, `short_desc`, `full_desc`),
    FOREIGN KEY (`manufacturer_id`) REFERENCES `va_manufacturers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Aircraft Specifications (LTTX)
CREATE TABLE IF NOT EXISTS `va_aircraft_specs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `aircraft_id` INT NOT NULL UNIQUE,
    `length_m` DECIMAL(6,2) NULL,
    `wingspan_m` DECIMAL(6,2) NULL,
    `height_m` DECIMAL(6,2) NULL,
    `wing_area_sqm` DECIMAL(6,2) NULL,
    `mtow_kg` INT NULL,
    `mlw_kg` INT NULL,
    `empty_weight_kg` INT NULL,
    `max_payload_kg` INT NULL,
    `fuel_capacity_liters` INT NULL,
    `max_speed_kmh` INT NULL,
    `cruise_speed_kmh` INT NULL,
    `mach_cruise` DECIMAL(4,2) NULL,
    `service_ceiling_m` INT NULL,
    `max_range_km` INT NULL,
    `takeoff_distance_m` INT NULL,
    `landing_distance_m` INT NULL,
    `engines_count` INT DEFAULT 2,
    `engine_type` VARCHAR(100) NULL,
    `engine_model` VARCHAR(100) NULL,
    `passengers_typical` INT NULL,
    `passengers_max` INT NULL,
    `cockpit_crew` INT DEFAULT 2,
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Airports
CREATE TABLE IF NOT EXISTS `va_airports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `icao` VARCHAR(10) NOT NULL UNIQUE,
    `iata` VARCHAR(10) NULL,
    `rf_code` VARCHAR(10) NULL,
    `name_ru` VARCHAR(150) NOT NULL,
    `name_en` VARCHAR(150) NOT NULL,
    `city_ru` VARCHAR(100) NOT NULL,
    `city_en` VARCHAR(100) NOT NULL,
    `country_ru` VARCHAR(100) NOT NULL,
    `country_iso` VARCHAR(10) NOT NULL,
    `latitude` DECIMAL(10,6) NOT NULL,
    `longitude` DECIMAL(10,6) NOT NULL,
    `elevation_ft` INT DEFAULT 0,
    `elevation_m` INT DEFAULT 0,
    `timezone` VARCHAR(50) DEFAULT 'UTC',
    `transition_alt_ft` INT DEFAULT 5000,
    `status` ENUM('active', 'military', 'closed') DEFAULT 'active',
    `views_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_icao` (`icao`),
    INDEX `idx_iata` (`iata`),
    INDEX `idx_city` (`city_ru`),
    FULLTEXT INDEX `ft_airports` (`name_ru`, `name_en`, `city_ru`, `city_en`, `country_ru`, `icao`, `iata`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Runways
CREATE TABLE IF NOT EXISTS `va_runways` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `airport_id` INT NOT NULL,
    `ident_1` VARCHAR(10) NOT NULL,
    `ident_2` VARCHAR(10) NOT NULL,
    `length_m` INT NOT NULL,
    `width_m` INT NOT NULL,
    `surface_type` ENUM('asphalt', 'concrete', 'grass', 'gravel', 'snow', 'water') DEFAULT 'asphalt',
    `heading_1_deg` INT NOT NULL,
    `heading_2_deg` INT NOT NULL,
    `ils_freq_1` DECIMAL(6,3) NULL,
    `ils_course_1` INT NULL,
    `ils_freq_2` DECIMAL(6,3) NULL,
    `ils_course_2` INT NULL,
    `lighting_type` VARCHAR(100) DEFAULT 'PAPI, ALS',
    FOREIGN KEY (`airport_id`) REFERENCES `va_airports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Radio Frequencies
CREATE TABLE IF NOT EXISTS `va_frequencies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `airport_id` INT NOT NULL,
    `type` VARCHAR(30) NOT NULL,
    `frequency_mhz` VARCHAR(20) NOT NULL,
    `callsign` VARCHAR(60) NULL,
    `description` VARCHAR(100) NULL,
    FOREIGN KEY (`airport_id`) REFERENCES `va_airports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Navigation Aids (NAVAIDs)
CREATE TABLE IF NOT EXISTS `va_navaids` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ident` VARCHAR(10) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('VOR', 'VOR-DME', 'VORTAC', 'NDB', 'TACAN') NOT NULL,
    `frequency` VARCHAR(20) NOT NULL,
    `channel` VARCHAR(10) NULL,
    `morse_code` VARCHAR(20) NULL,
    `latitude` DECIMAL(10,6) NOT NULL,
    `longitude` DECIMAL(10,6) NOT NULL,
    `elevation_ft` INT NULL,
    `associated_airport_icao` VARCHAR(10) NULL,
    `range_nm` INT DEFAULT 50,
    INDEX `idx_ident` (`ident`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Airlines
CREATE TABLE IF NOT EXISTS `va_airlines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name_ru` VARCHAR(120) NOT NULL,
    `name_en` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(120) NOT NULL UNIQUE,
    `iata` VARCHAR(5) NULL,
    `icao` VARCHAR(5) NULL,
    `callsign` VARCHAR(50) NULL,
    `country` VARCHAR(60) NOT NULL,
    `alliance` ENUM('star_alliance', 'skyteam', 'oneworld', 'none') DEFAULT 'none',
    `fleet_size` INT DEFAULT 0,
    `founded_year` INT NULL,
    `logo_url` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_iata` (`iata`),
    INDEX `idx_icao` (`icao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Aviation Engines
CREATE TABLE IF NOT EXISTS `va_engines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `model_name` VARCHAR(100) NOT NULL,
    `manufacturer` VARCHAR(100) NOT NULL,
    `type` ENUM('turbofan', 'turboprop', 'turbojet', 'piston', 'ramjet') NOT NULL,
    `thrust_max_kn` DECIMAL(6,1) NULL,
    `power_max_hp` INT NULL,
    `bypass_ratio` DECIMAL(4,2) NULL,
    `dry_weight_kg` INT NULL,
    `specific_fuel_consumption` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `image_url` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Spotter Photos & Media
CREATE TABLE IF NOT EXISTS `va_photos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `aircraft_id` INT NULL,
    `airport_id` INT NULL,
    `airline_id` INT NULL,
    `tail_number` VARCHAR(30) NULL,
    `photo_url` VARCHAR(255) NOT NULL,
    `thumb_url` VARCHAR(255) NOT NULL,
    `medium_url` VARCHAR(255) NOT NULL,
    `camera_model` VARCHAR(100) NULL,
    `lens` VARCHAR(100) NULL,
    `focal_length` VARCHAR(20) NULL,
    `shutter_speed` VARCHAR(20) NULL,
    `aperture` VARCHAR(20) NULL,
    `iso` VARCHAR(20) NULL,
    `shot_date` DATE NULL,
    `is_photo_of_day` TINYINT(1) DEFAULT 0,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `reject_reason` VARCHAR(255) NULL,
    `views` INT DEFAULT 0,
    `likes_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`airport_id`) REFERENCES `va_airports`(`id`) ON DELETE SET NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_tail` (`tail_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Spotting Locations around Airports
CREATE TABLE IF NOT EXISTS `va_spotting_locations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `airport_id` INT NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `latitude` DECIMAL(10,6) NOT NULL,
    `longitude` DECIMAL(10,6) NOT NULL,
    `recommended_lens` VARCHAR(100) DEFAULT '70-300mm',
    `best_time` VARCHAR(100) DEFAULT 'Вторая половина дня',
    `access_info` TEXT NULL,
    `photo_sample_url` VARCHAR(255) NULL,
    FOREIGN KEY (`airport_id`) REFERENCES `va_airports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Article Categories
CREATE TABLE IF NOT EXISTS `va_article_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(60) NOT NULL UNIQUE,
    `name_ru` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Articles & Aviation News
CREATE TABLE IF NOT EXISTS `va_articles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `author_id` INT NOT NULL,
    `category_id` INT NULL,
    `slug` VARCHAR(150) NOT NULL UNIQUE,
    `title` VARCHAR(200) NOT NULL,
    `summary` TEXT NOT NULL,
    `content` LONGTEXT NOT NULL,
    `cover_image` VARCHAR(255) NULL,
    `is_published` TINYINT(1) DEFAULT 1,
    `published_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `reading_time_min` INT DEFAULT 5,
    `views_count` INT DEFAULT 0,
    `likes_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (`author_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `va_article_categories`(`id`) ON DELETE SET NULL,
    FULLTEXT INDEX `ft_articles` (`title`, `summary`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Comments
CREATE TABLE IF NOT EXISTS `va_comments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `parent_id` INT NULL,
    `entity_type` ENUM('article', 'aircraft', 'airport', 'photo', 'incident') NOT NULL,
    `entity_id` INT NOT NULL,
    `content` TEXT NOT NULL,
    `likes_count` INT DEFAULT 0,
    `status` ENUM('approved', 'pending', 'spam', 'deleted') DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Aviation Incidents and Safety Lessons
CREATE TABLE IF NOT EXISTS `va_incidents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(150) NOT NULL UNIQUE,
    `title` VARCHAR(200) NOT NULL,
    `event_date` DATE NOT NULL,
    `flight_number` VARCHAR(30) NULL,
    `aircraft_type` VARCHAR(100) NOT NULL,
    `airline_name` VARCHAR(100) NULL,
    `location_name` VARCHAR(150) NOT NULL,
    `latitude` DECIMAL(10,6) NULL,
    `longitude` DECIMAL(10,6) NULL,
    `fatalities` INT DEFAULT 0,
    `survivors` INT DEFAULT 0,
    `cause_category` ENUM('pilot_error', 'mechanical', 'weather', 'atc', 'sabotage', 'undetermined') DEFAULT 'undetermined',
    `summary` TEXT NOT NULL,
    `timeline_json` LONGTEXT NULL,
    `official_report_summary` LONGTEXT NULL,
    `safety_lessons` LONGTEXT NOT NULL,
    `cover_image` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FULLTEXT INDEX `ft_incidents` (`title`, `summary`, `safety_lessons`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Personal Flight Logbook
CREATE TABLE IF NOT EXISTS `va_flight_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `flight_date` DATE NOT NULL,
    `flight_number` VARCHAR(30) NOT NULL,
    `airline_id` INT NULL,
    `aircraft_id` INT NULL,
    `tail_number` VARCHAR(30) NULL,
    `dep_airport_id` INT NOT NULL,
    `arr_airport_id` INT NOT NULL,
    `dep_time` TIME NULL,
    `arr_time` TIME NULL,
    `duration_min` INT DEFAULT 0,
    `distance_km` INT DEFAULT 0,
    `seat_number` VARCHAR(10) NULL,
    `seat_class` ENUM('economy', 'premium_economy', 'business', 'first', 'cockpit') DEFAULT 'economy',
    `seat_type` ENUM('window', 'middle', 'aisle') DEFAULT 'window',
    `boarding_pass_url` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `rating` TINYINT DEFAULT 5,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`dep_airport_id`) REFERENCES `va_airports`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`arr_airport_id`) REFERENCES `va_airports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Aviation Glossary & Slang
CREATE TABLE IF NOT EXISTS `va_glossary` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `term` VARCHAR(100) NOT NULL UNIQUE,
    `abbreviation` VARCHAR(30) NULL,
    `category` ENUM('acronym', 'aerodynamics', 'navigation', 'systems', 'weather', 'phraseology', 'slang') DEFAULT 'acronym',
    `short_def` VARCHAR(255) NOT NULL,
    `full_explanation` TEXT NOT NULL,
    `practical_example` TEXT NULL,
    `related_terms` VARCHAR(255) NULL,
    INDEX `idx_abbr` (`abbreviation`),
    FULLTEXT INDEX `ft_glossary` (`term`, `short_def`, `full_explanation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Quizzes & Tests
CREATE TABLE IF NOT EXISTS `va_quizzes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NOT NULL,
    `category` VARCHAR(50) NOT NULL,
    `difficulty` ENUM('cadet', 'ppl', 'cpl_atpl') DEFAULT 'cadet',
    `time_limit_sec` INT DEFAULT 600,
    `reward_xp` INT DEFAULT 100,
    `badge_code` VARCHAR(50) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Quiz Questions
CREATE TABLE IF NOT EXISTS `va_quiz_questions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `quiz_id` INT NOT NULL,
    `question_text` TEXT NOT NULL,
    `image_url` VARCHAR(255) NULL,
    `audio_url` VARCHAR(255) NULL,
    `option_a` VARCHAR(255) NOT NULL,
    `option_b` VARCHAR(255) NOT NULL,
    `option_c` VARCHAR(255) NOT NULL,
    `option_d` VARCHAR(255) NOT NULL,
    `correct_option` ENUM('a', 'b', 'c', 'd') NOT NULL,
    `explanation` TEXT NOT NULL,
    FOREIGN KEY (`quiz_id`) REFERENCES `va_quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. Badges and Achievements
CREATE TABLE IF NOT EXISTS `va_achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `title` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `icon_svg` VARCHAR(100) DEFAULT 'award',
    `required_xp` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. User Achievements Mapping
CREATE TABLE IF NOT EXISTS `va_user_achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `achievement_id` INT NOT NULL,
    `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `user_achievement_unique` (`user_id`, `achievement_id`),
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`achievement_id`) REFERENCES `va_achievements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. Weather Cache Table
CREATE TABLE IF NOT EXISTS `va_weather_cache` (
    `icao` VARCHAR(10) PRIMARY KEY,
    `metar_raw` TEXT NULL,
    `taf_raw` TEXT NULL,
    `parsed_json` LONGTEXT NULL,
    `flight_category` VARCHAR(10) DEFAULT 'VFR',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. Admin & Security Audit Logs
CREATE TABLE IF NOT EXISTS `va_audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(80) NOT NULL,
    `entity_type` VARCHAR(50) NULL,
    `entity_id` INT NULL,
    `ip_address` VARCHAR(50) NULL,
    `user_agent` VARCHAR(255) NULL,
    `details_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
