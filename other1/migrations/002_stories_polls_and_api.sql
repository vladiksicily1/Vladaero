-- Migration 002: Stories, Polls, Bookmarks, and External API Keys
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `key_name` VARCHAR(80) NOT NULL,
  `api_key` VARCHAR(64) NOT NULL UNIQUE,
  `permissions` VARCHAR(255) NOT NULL DEFAULT 'read,write',
  `requests_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `rate_limit_per_min` INT UNSIGNED NOT NULL DEFAULT 120,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_key` (`api_key`),
  KEY `idx_user_keys` (`user_id`),
  CONSTRAINT `fk_apikey_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bookmarks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `post_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_post_bm` (`user_id`, `post_id`),
  CONSTRAINT `fk_bm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bm_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `media_url` VARCHAR(255) NOT NULL,
  `caption` VARCHAR(255) DEFAULT NULL,
  `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_story_user` (`user_id`),
  KEY `idx_story_exp` (`expires_at`),
  CONSTRAINT `fk_story_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ecosystem_apps` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `icon` VARCHAR(50) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT '#3b82f6',
  `url` VARCHAR(255) NOT NULL,
  `badge` VARCHAR(30) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Seed Data: Ecosystem Apps Registry
INSERT INTO `ecosystem_apps` (`slug`, `name`, `description`, `icon`, `color`, `url`, `badge`, `sort_order`, `is_active`) VALUES
('social', 'VladInc Social', 'Лента публикаций, медиа, мнения и тренды', 'users', '#3b82f6', '/feed', 'Core', 1, 1),
('messenger', 'VladChat', 'Мгновенные сообщения и диалоги', 'message-circle', '#10b981', '/messages', 'Online', 2, 1),
('communities', 'Сообщества', 'Клубы по интересам и паблики', 'compass', '#8b5cf6', '/communities', 'Hubs', 3, 1),
('developers', 'Dev & API', 'Внешний REST API и ключи разработчика', 'code', '#0ea5e9', '/developers', 'REST API', 4, 1),
('id', 'Vlad ID', 'Единый профиль, безопасность и сессии', 'shield', '#6366f1', '/settings', 'SSO', 5, 1),
('wallet', 'VladPay', 'Внутренний кошелек, переводы и бонусы', 'wallet', '#f59e0b', '/wallet', 'Coins', 6, 1),
('arcade', 'Vlad Arcade', 'Мини-игры и добыча VladCoin', 'gamepad-2', '#ec4899', '/arcade', 'Mini-App', 7, 1),
('cloud', 'Cloud Box', 'Личное облачное хранилище файлов', 'cloud', '#06b6d4', '/cloud', 'Storage', 8, 1),
('bookmarks', 'Закладки', 'Сохраненные публикации и статьи', 'bookmark', '#f43f5e', '/bookmarks', '', 9, 1),
('explore', 'Навигатор', 'Поиск пользователей, трендов и хэштегов', 'search', '#14b8a6', '/explore', '', 10, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `url`=VALUES(`url`);
