CREATE TABLE IF NOT EXISTS `past_events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poster_image` VARCHAR(255) NOT NULL,
  `title` JSON NOT NULL,
  `date` DATE NOT NULL,
  `categories` VARCHAR(255) NOT NULL,
  `youtube_video_links` JSON DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_past_events_deleted` (`deleted_at`),
  INDEX `idx_past_events_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
