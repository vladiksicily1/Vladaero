-- Migration 003: Social Ultimate (Instagram + Twitter + VK + Facebook features)
-- Multi-media carousels, Quote reposts, Threads, Audio player, Gifts, Voice messages, Feelings

-- 1. Post Media (Carousels & Multiple attachments: images, audio, docs)
CREATE TABLE IF NOT EXISTS `post_media` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT UNSIGNED NOT NULL,
  `media_url` VARCHAR(255) NOT NULL,
  `media_type` ENUM('image', 'audio', 'document', 'video') NOT NULL DEFAULT 'image',
  `file_name` VARCHAR(255) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pm_post` (`post_id`),
  CONSTRAINT `fk_pm_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Virtual Gifts Catalog (VK Gifts)
CREATE TABLE IF NOT EXISTS `gifts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `icon` VARCHAR(20) NOT NULL,
  `price_coins` BIGINT NOT NULL DEFAULT 50,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Gifts
INSERT INTO `gifts` (`name`, `icon`, `price_coins`, `sort_order`) VALUES
('Золотая Корона', '👑', 500, 1),
('Сверкающий Бриллиант', '💎', 250, 2),
('Космическая Ракета', '🚀', 100, 3),
('Кубок Чемпиона', '🏆', 50, 4),
('Пылающее Сердце', '💖', 25, 5),
('Сияющая Звезда', '⭐', 10, 6),
('Праздничный Торт', '🎂', 30, 7),
('Огненный Дракон', '🐉', 300, 8)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 3. User Received Gifts
CREATE TABLE IF NOT EXISTS `user_gifts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gift_id` INT UNSIGNED NOT NULL,
  `from_user_id` INT UNSIGNED NOT NULL,
  `to_user_id` INT UNSIGNED NOT NULL,
  `message` VARCHAR(255) DEFAULT NULL,
  `is_private` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ug_to` (`to_user_id`),
  CONSTRAINT `fk_ug_gift` FOREIGN KEY (`gift_id`) REFERENCES `gifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ug_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ug_to` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Highlights (Instagram Permanent Stories)
CREATE TABLE IF NOT EXISTS `highlights` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(50) NOT NULL,
  `cover_url` VARCHAR(255) NOT NULL,
  `media_urls_json` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hl_user` (`user_id`),
  CONSTRAINT `fk_hl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
