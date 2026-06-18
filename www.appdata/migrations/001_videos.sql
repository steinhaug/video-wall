CREATE TABLE IF NOT EXISTS `videos` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `youtube_url`   VARCHAR(500) NOT NULL,
  `video_id`      VARCHAR(20)  NOT NULL,
  `title`         VARCHAR(500) DEFAULT NULL,
  `category`      VARCHAR(100) NOT NULL DEFAULT 'Uncategorized',
  `status`        ENUM('pending','downloading','transcribing','done','error') NOT NULL DEFAULT 'pending',
  `assemblyai_id` VARCHAR(64)  DEFAULT NULL,
  `error_message` TEXT         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_video_id` (`video_id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
