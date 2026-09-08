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
    `role` ENUM('admin', 'editor', 'moderator', 'screener', 'spotter', 'pilot', 'user') DEFAULT 'user',
    `rank_title` VARCHAR(60) DEFAULT 'Курсант',
    `xp_points` INT DEFAULT 0,
    `reputation` INT DEFAULT 0,
    `is_banned` TINYINT(1) DEFAULT 0,
    `ban_reason` VARCHAR(255) NULL,
    `two_factor_secret` VARCHAR(100) NULL,
    `telegram_id` VARCHAR(60) NULL UNIQUE,
    `telegram_username` VARCHAR(60) NULL,
    `telegram_auth_code` VARCHAR(20) NULL,
    `telegram_auth_expires` DATETIME NULL,
    `units_system` ENUM('metric', 'aviation_imperial') DEFAULT 'aviation_imperial',
    `theme_preference` ENUM('auto', 'dark', 'light') DEFAULT 'auto',
    `sound_enabled` TINYINT(1) DEFAULT 1,
    `privacy_flightlog_public` TINYINT(1) DEFAULT 1,
    `privacy_stats_public` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_role` (`role`),
    INDEX `idx_xp` (`xp_points`),
    INDEX `idx_tg` (`telegram_id`)
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
    `wing_configuration` VARCHAR(50) DEFAULT 'monoplane',
    `generation` VARCHAR(50) NULL,
    `status` ENUM('in_service', 'out_of_production', 'development', 'historic') DEFAULT 'in_service',
    `short_desc` VARCHAR(255) NULL,
    `full_desc` LONGTEXT NULL,
    `history_text` LONGTEXT NULL,
    `combat_record` LONGTEXT NULL,
    `safety_record` LONGTEXT NULL,
    `hero_image` VARCHAR(255) NULL,
    `blueprint_image` VARCHAR(255) NULL,
    `model_3d_file` VARCHAR(255) NULL,
    `cockpit_360_url` VARCHAR(255) NULL,
    `engine_sound_url` VARCHAR(255) NULL,
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
    `thrust_kn_per_engine` DECIMAL(6,1) NULL,
    `fuel_consumption_kg_h` INT NULL,
    `cost_per_flight_hour_usd` INT NULL,
    `passengers_typical` INT NULL,
    `passengers_max` INT NULL,
    `cockpit_crew` INT DEFAULT 2,
    `avionics_description` TEXT NULL,
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Aircraft Modifications / Variants
CREATE TABLE IF NOT EXISTS `va_aircraft_modifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `aircraft_id` INT NOT NULL,
    `variant_name` VARCHAR(100) NOT NULL,
    `description` TEXT NOT NULL,
    `specs_diff_summary` VARCHAR(255) NULL,
    `first_flight_year` INT NULL,
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Airports
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
    `terminal_info` TEXT NULL,
    `transport_info` TEXT NULL,
    `status` ENUM('active', 'military', 'closed') DEFAULT 'active',
    `views_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_icao` (`icao`),
    INDEX `idx_iata` (`iata`),
    INDEX `idx_city` (`city_ru`),
    FULLTEXT INDEX `ft_airports` (`name_ru`, `name_en`, `city_ru`, `city_en`, `country_ru`, `icao`, `iata`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Runways
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

-- 10. Radio Frequencies
CREATE TABLE IF NOT EXISTS `va_frequencies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `airport_id` INT NOT NULL,
    `type` VARCHAR(30) NOT NULL,
    `frequency_mhz` VARCHAR(20) NOT NULL,
    `callsign` VARCHAR(60) NULL,
    `description` VARCHAR(100) NULL,
    `liveatc_stream_url` VARCHAR(255) NULL,
    FOREIGN KEY (`airport_id`) REFERENCES `va_airports`(`id`) ON DELETE CASCADE
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
    `hub_airport_id` INT NULL,
    `logo_url` VARCHAR(255) NULL,
    `baggage_rules` TEXT NULL,
    `description` TEXT NULL,
    `rating_service` DECIMAL(3,2) DEFAULT 4.5,
    `rating_punctuality` DECIMAL(3,2) DEFAULT 4.5,
    `rating_comfort` DECIMAL(3,2) DEFAULT 4.5,
    `rating_food` DECIMAL(3,2) DEFAULT 4.2,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_iata` (`iata`),
    INDEX `idx_icao` (`icao`),
    FOREIGN KEY (`hub_airport_id`) REFERENCES `va_airports`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Spotter Photos & Media
CREATE TABLE IF NOT EXISTS `va_photos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `aircraft_id` INT NULL,
    `airport_id` INT NULL,
    `airline_id` INT NULL,
    `tail_number` VARCHAR(30) NULL,
    `msn` VARCHAR(50) NULL,
    `special_livery` VARCHAR(100) NULL,
    `photo_url` VARCHAR(255) NOT NULL,
    `thumb_url` VARCHAR(255) NOT NULL,
    `medium_url` VARCHAR(255) NOT NULL,
    `original_url` VARCHAR(255) NULL,
    `camera_model` VARCHAR(100) NULL,
    `lens` VARCHAR(100) NULL,
    `focal_length` VARCHAR(20) NULL,
    `shutter_speed` VARCHAR(20) NULL,
    `aperture` VARCHAR(20) NULL,
    `iso` VARCHAR(20) NULL,
    `shot_date` DATE NULL,
    `aircraft_status` ENUM('active', 'stored', 'scrapped', 'preserved') DEFAULT 'active',
    `license_type` ENUM('all_rights_reserved', 'cc_by', 'cc_by_nc', 'free') DEFAULT 'all_rights_reserved',
    `allow_original_request` TINYINT(1) DEFAULT 1,
    `is_photo_of_day` TINYINT(1) DEFAULT 0,
    `is_photo_of_week` TINYINT(1) DEFAULT 0,
    `is_editors_choice` TINYINT(1) DEFAULT 0,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `reject_reason` VARCHAR(255) NULL,
    `views` INT DEFAULT 0,
    `likes_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`airport_id`) REFERENCES `va_airports`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`airline_id`) REFERENCES `va_airlines`(`id`) ON DELETE SET NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_tail` (`tail_number`),
    INDEX `idx_potd` (`is_photo_of_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Spotting Locations around Airports
CREATE TABLE IF NOT EXISTS `va_spotting_locations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `airport_id` INT NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `latitude` DECIMAL(10,6) NOT NULL,
    `longitude` DECIMAL(10,6) NOT NULL,
    `recommended_lens` VARCHAR(100) DEFAULT '70-300mm',
    `best_time` VARCHAR(100) DEFAULT 'Вторая половина дня',
    `access_info` TEXT NULL,
    `runway_view` VARCHAR(100) NULL,
    `photo_sample_url` VARCHAR(255) NULL,
    FOREIGN KEY (`airport_id`) REFERENCES `va_airports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Article Categories
CREATE TABLE IF NOT EXISTS `va_article_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(60) NOT NULL UNIQUE,
    `name_ru` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Articles & Aviation News (with 15+ block layout support)
CREATE TABLE IF NOT EXISTS `va_articles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `author_id` INT NOT NULL,
    `category_id` INT NULL,
    `slug` VARCHAR(150) NOT NULL UNIQUE,
    `title` VARCHAR(200) NOT NULL,
    `summary` TEXT NOT NULL,
    `content` LONGTEXT NOT NULL,
    `blocks_json` LONGTEXT NULL,
    `cover_image` VARCHAR(255) NULL,
    `is_breaking` TINYINT(1) DEFAULT 0,
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

-- 16. Comments
CREATE TABLE IF NOT EXISTS `va_comments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `parent_id` INT NULL,
    `entity_type` ENUM('article', 'aircraft', 'airport', 'photo', 'incident', 'compare') NOT NULL,
    `entity_id` INT NOT NULL,
    `content` TEXT NOT NULL,
    `likes_count` INT DEFAULT 0,
    `status` ENUM('approved', 'pending', 'spam', 'deleted') DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    INDEX `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Aircraft Comparison Votes (Battle of Birds)
CREATE TABLE IF NOT EXISTS `va_compare_votes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `aircraft_pair_key` VARCHAR(100) NOT NULL,
    `chosen_aircraft_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pair` (`aircraft_pair_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Aviation Incidents & Safety Database
CREATE TABLE IF NOT EXISTS `va_incidents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(150) NOT NULL UNIQUE,
    `title` VARCHAR(200) NOT NULL,
    `event_date` DATE NOT NULL,
    `flight_number` VARCHAR(30) NULL,
    `aircraft_type` VARCHAR(100) NOT NULL,
    `aircraft_id` INT NULL,
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
    `cvr_transcript` LONGTEXT NULL,
    `cover_image` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FULLTEXT INDEX `ft_incidents` (`title`, `summary`, `safety_lessons`),
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE SET NULL
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
    FOREIGN KEY (`arr_airport_id`) REFERENCES `va_airports`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`airline_id`) REFERENCES `va_airlines`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Aviation Glossary & Radio Phraseology
CREATE TABLE IF NOT EXISTS `va_glossary` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `term` VARCHAR(100) NOT NULL UNIQUE,
    `abbreviation` VARCHAR(30) NULL,
    `category` ENUM('acronym', 'aerodynamics', 'navigation', 'systems', 'weather', 'phraseology', 'slang') DEFAULT 'acronym',
    `short_def` VARCHAR(255) NOT NULL,
    `full_explanation` TEXT NOT NULL,
    `practical_example` TEXT NULL,
    `audio_example_url` VARCHAR(255) NULL,
    `related_terms` VARCHAR(255) NULL,
    INDEX `idx_abbr` (`abbreviation`),
    FULLTEXT INDEX `ft_glossary` (`term`, `short_def`, `full_explanation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Quizzes & Tests
CREATE TABLE IF NOT EXISTS `va_quizzes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `author_id` INT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NOT NULL,
    `category` VARCHAR(50) NOT NULL,
    `difficulty` ENUM('cadet', 'ppl', 'cpl_atpl') DEFAULT 'cadet',
    `time_limit_sec` INT DEFAULT 600,
    `reward_xp` INT DEFAULT 100,
    `badge_code` VARCHAR(50) NULL,
    `status` ENUM('approved', 'pending', 'rejected') DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`author_id`) REFERENCES `va_users`(`id`) ON DELETE SET NULL
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
    `related_aircraft_id` INT NULL,
    FOREIGN KEY (`quiz_id`) REFERENCES `va_quizzes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`related_aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. User Quiz Attempts & Leaderboard
CREATE TABLE IF NOT EXISTS `va_quiz_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `quiz_id` INT NOT NULL,
    `score` INT NOT NULL,
    `total_questions` INT NOT NULL,
    `xp_earned` INT DEFAULT 0,
    `time_spent_sec` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`quiz_id`) REFERENCES `va_quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. Badges and Achievements
CREATE TABLE IF NOT EXISTS `va_achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `title` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `icon_svg` VARCHAR(100) DEFAULT 'award',
    `required_xp` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. User Achievements Mapping
CREATE TABLE IF NOT EXISTS `va_user_achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `achievement_id` INT NOT NULL,
    `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `user_achievement_unique` (`user_id`, `achievement_id`),
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`achievement_id`) REFERENCES `va_achievements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. Virtual Airline (ВАК) & Simmer Liveries/Mods
CREATE TABLE IF NOT EXISTS `va_sim_mods` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `aircraft_id` INT NULL,
    `simulator` ENUM('msfs', 'xplane', 'dcs', 'prepar3d') NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `version` VARCHAR(20) DEFAULT '1.0.0',
    `description` TEXT NOT NULL,
    `download_url` VARCHAR(255) NULL,
    `external_link` VARCHAR(255) NULL,
    `thumbnail_url` VARCHAR(255) NULL,
    `downloads_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. Interactive Checklists (SOP / QRH)
CREATE TABLE IF NOT EXISTS `va_checklists` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `aircraft_id` INT NULL,
    `title` VARCHAR(120) NOT NULL,
    `category` ENUM('normal', 'abnormal', 'emergency') DEFAULT 'normal',
    `items_json` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`aircraft_id`) REFERENCES `va_aircraft`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28. Aviation Museums Directory
CREATE TABLE IF NOT EXISTS `va_museums` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name_ru` VARCHAR(150) NOT NULL,
    `name_en` VARCHAR(150) NOT NULL,
    `country` VARCHAR(80) NOT NULL,
    `city` VARCHAR(80) NOT NULL,
    `latitude` DECIMAL(10,6) NOT NULL,
    `longitude` DECIMAL(10,6) NOT NULL,
    `website_url` VARCHAR(255) NULL,
    `photo_url` VARCHAR(255) NULL,
    `description` TEXT NOT NULL,
    `notable_exhibits` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 29. Weather Cache Table
CREATE TABLE IF NOT EXISTS `va_weather_cache` (
    `icao` VARCHAR(10) PRIMARY KEY,
    `metar_raw` TEXT NULL,
    `taf_raw` TEXT NULL,
    `parsed_json` LONGTEXT NULL,
    `flight_category` VARCHAR(10) DEFAULT 'VFR',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 30. AI Chat Conversations & Messages
CREATE TABLE IF NOT EXISTS `va_ai_chats` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `session_token` VARCHAR(64) NOT NULL,
    `is_admin_agent` TINYINT(1) DEFAULT 0,
    `title` VARCHAR(150) DEFAULT 'Диалог с бортовым ИИ',
    `messages_json` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_session` (`session_token`),
    FOREIGN KEY (`user_id`) REFERENCES `va_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 31. AI Usage & Token Tracking
CREATE TABLE IF NOT EXISTS `va_ai_usage` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `model_name` VARCHAR(80) NOT NULL,
    `prompt_tokens` INT DEFAULT 0,
    `completion_tokens` INT DEFAULT 0,
    `total_tokens` INT DEFAULT 0,
    `tools_called` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_ai` (`user_id`),
    INDEX `idx_ip_ai` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 32. Admin & Security Audit Logs
CREATE TABLE IF NOT EXISTS `va_audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(80) NOT NULL,
    `entity_type` VARCHAR(50) NULL,
    `entity_id` INT NULL,
    `ip_address` VARCHAR(50) NULL,
    `user_agent` VARCHAR(255) NULL,
    `details_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
