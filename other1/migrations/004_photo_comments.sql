-- Migration 004: Photo Comments Attachment
-- Allows attaching photos and media directly to post comments

ALTER TABLE `post_comments` 
  ADD COLUMN IF NOT EXISTS `media_url` VARCHAR(255) DEFAULT NULL AFTER `content`;
