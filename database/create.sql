CREATE DATABASE IF NOT EXISTS `test`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `test`;

CREATE TABLE IF NOT EXISTS `news` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `guid` VARCHAR(500) NOT NULL,
  `title` VARCHAR(500) NOT NULL,
  `link` TEXT NOT NULL,
  `author` VARCHAR(255) DEFAULT NULL,
  `description` MEDIUMTEXT,
  `image_url` TEXT,
  `published_at` DATETIME DEFAULT NULL,
  `source` VARCHAR(100) NOT NULL DEFAULT 'xataka',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_news_guid` (`guid`),
  KEY `idx_news_published_at` (`published_at`),
  KEY `idx_news_title` (`title`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
