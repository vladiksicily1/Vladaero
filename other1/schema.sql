-- ===================================================
-- VladInc Ecosystem Database Schema (v2.0 Extreme)
-- Charset: utf8mb4 (Full Emoji & Unicode Support)
-- Includes: External API Keys, Stories, Polls, Bookmarks, Reposts
-- ===================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users & VladID Core
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(40) NOT NULL UNIQUE,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(80) NOT NULL,
  `avatar` VARCHAR(255) DEFAULT 'assets/images/default_avatar.svg',
  `cover` VARCHAR(255) DEFAULT '',
  `bio` TEXT DEFAULT NULL,
  `role` ENUM('user', 'verified', 'creator', 'moderator', 'admin') NOT NULL DEFAULT 'user',
  `coins` BIGINT NOT NULL DEFAULT 150,
  `xp` BIGINT UNSIGNED NOT NULL DEFAULT 50,
  `level` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
  `status_text` VARCHAR(150) DEFAULT 'Участник экосистемы VladInc',
  `theme_mode` ENUM('dark', 'light') NOT NULL DEFAULT 'dark',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_coins` (`coins`),
  KEY `idx_xp` (`xp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. User Sessions
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `session_token` VARCHAR(64) NOT NULL UNIQUE,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_token` (`user_id`, `session_token`),
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. External API Keys (Public REST API)
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `key_name` VARCHAR(80) NOT NULL,
  `api_key` VARCHAR(64) NOT NULL UNIQUE,
  `permissions` VARCHAR(255) NOT NULL DEFAULT 'read,write', -- read,write,wallet,messages
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

-- 4. Communities / Hubs
CREATE TABLE IF NOT EXISTS `communities` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT 'assets/images/default_community.svg',
  `cover` VARCHAR(255) DEFAULT '',
  `creator_id` INT UNSIGNED NOT NULL,
  `is_private` TINYINT(1) NOT NULL DEFAULT 0,
  `members_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slug` (`slug`),
  CONSTRAINT `fk_comm_creator` FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Community Members
CREATE TABLE IF NOT EXISTS `community_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `community_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('member', 'moderator', 'admin') NOT NULL DEFAULT 'member',
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_comm_user` (`community_id`, `user_id`),
  CONSTRAINT `fk_cm_comm` FOREIGN KEY (`community_id`) REFERENCES `communities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Social Posts
CREATE TABLE IF NOT EXISTS `posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `community_id` INT UNSIGNED DEFAULT NULL,
  `content` TEXT NOT NULL,
  `media_url` VARCHAR(255) DEFAULT NULL,
  `media_type` ENUM('image', 'video', 'file', 'none') NOT NULL DEFAULT 'none',
  `poll_question` VARCHAR(255) DEFAULT NULL,
  `poll_options` TEXT DEFAULT NULL, -- JSON encoded options
  `poll_votes` TEXT DEFAULT NULL,   -- JSON encoded vote results
  `repost_of_id` INT UNSIGNED DEFAULT NULL,
  `likes_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `comments_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `reposts_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `views_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_user` (`user_id`),
  KEY `idx_post_comm` (`community_id`),
  KEY `idx_post_created` (`created_at`),
  CONSTRAINT `fk_post_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_post_comm` FOREIGN KEY (`community_id`) REFERENCES `communities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_post_repost` FOREIGN KEY (`repost_of_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Post Reactions & Multi-Emotes (Like, Fire, Rocket, Laugh, Tip)
CREATE TABLE IF NOT EXISTS `post_likes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reaction` ENUM('like', 'fire', 'heart', 'rocket', 'laugh') NOT NULL DEFAULT 'like',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_post_user_like` (`post_id`, `user_id`),
  CONSTRAINT `fk_pl_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Post Comments (Support Nested Replies)
CREATE TABLE IF NOT EXISTS `post_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `content` TEXT NOT NULL,
  `media_url` VARCHAR(255) DEFAULT NULL,
  `likes_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comment_post` (`post_id`),
  KEY `idx_comment_parent` (`parent_id`),
  CONSTRAINT `fk_pc_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pc_parent` FOREIGN KEY (`parent_id`) REFERENCES `post_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Bookmarks / Favorites
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

-- 10. Stories / Моменты (24h temporary stories)
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

-- 11. Follows (Social Graph)
CREATE TABLE IF NOT EXISTS `follows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `follower_id` INT UNSIGNED NOT NULL,
  `following_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_follow_pair` (`follower_id`, `following_id`),
  CONSTRAINT `fk_flw_follower` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_flw_following` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Chats (Direct & Group)
CREATE TABLE IF NOT EXISTS `chats` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('direct', 'group') NOT NULL DEFAULT 'direct',
  `title` VARCHAR(100) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `creator_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Chat Participants
CREATE TABLE IF NOT EXISTS `chat_participants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `last_read_message_id` INT UNSIGNED DEFAULT 0,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_chat_user` (`chat_id`, `user_id`),
  CONSTRAINT `fk_cp_chat` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Chat Messages
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id` INT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `message` TEXT NOT NULL,
  `media_url` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_chat_created` (`chat_id`, `created_at`),
  CONSTRAINT `fk_cm_chat_inst` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. VladCoin Transactions
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_user_id` INT UNSIGNED DEFAULT NULL,
  `to_user_id` INT UNSIGNED NOT NULL,
  `amount` BIGINT NOT NULL,
  `type` ENUM('transfer', 'bonus', 'game_reward', 'tip', 'system', 'api_transfer') NOT NULL DEFAULT 'transfer',
  `note` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tx_to` (`to_user_id`),
  KEY `idx_tx_from` (`from_user_id`),
  CONSTRAINT `fk_tx_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tx_to` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Notifications Center
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED DEFAULT NULL,
  `type` ENUM('like', 'comment', 'follow', 'coin_transfer', 'repost', 'community', 'system') NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_unread` (`user_id`, `is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Cloud Box
CREATE TABLE IF NOT EXISTS `cloud_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `storage_name` VARCHAR(255) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `downloads_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_public` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cloud_user` (`user_id`),
  CONSTRAINT `fk_cf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Ecosystem Apps Registry (9-Dots Launcher Grid)
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

SET FOREIGN_KEY_CHECKS = 1;
