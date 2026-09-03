-- ============================================================
-- TMS Explore Mode Migration SQL
-- Run this ONCE on your existing TMS database (optional — these
-- tables are also created automatically by includes/config.php
-- the first time the app runs, same as AI_MIGRATION.sql).
-- ============================================================

-- Cached AI-generated destination info (overview, attractions, history, etc.)
CREATE TABLE IF NOT EXISTS `tbl_explore_destinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `place_key` varchar(190) DEFAULT NULL,
  `display_name` varchar(300) DEFAULT NULL,
  `country` varchar(150) DEFAULT NULL,
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `info_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_explore_place` (`place_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user "recently explored" history
CREATE TABLE IF NOT EXISTS `tbl_explore_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(100) DEFAULT NULL,
  `display_name` varchar(300) DEFAULT NULL,
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_exphist_email` (`user_email`),
  INDEX `idx_exphist_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI travel-assistant chat log (per Explore session)
CREATE TABLE IF NOT EXISTS `tbl_explore_chat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(100) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `destination` varchar(300) DEFAULT NULL,
  `role` enum('user','assistant') NOT NULL,
  `message` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_expchat_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Generic short-lived cache for weather / nearby-places / search API responses
CREATE TABLE IF NOT EXISTS `tbl_explore_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(190) DEFAULT NULL,
  `cache_value` longtext DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_explore_cache_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
