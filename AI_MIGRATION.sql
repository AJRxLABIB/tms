-- ============================================================
-- TMS AI Migration SQL
-- Run this ONCE on your existing TMS database
-- ============================================================

-- AI usage log (rate limiting)
CREATE TABLE IF NOT EXISTS `tbl_ai_usage_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_usage_email` (`user_email`),
  INDEX `idx_usage_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI analytics / cache
CREATE TABLE IF NOT EXISTS `tbl_ai_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `metric_key` varchar(150) DEFAULT NULL,
  `metric_value` longtext DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_metric_key` (`metric_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI generated packages (personalized)
CREATE TABLE IF NOT EXISTS `tbl_ai_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(100) DEFAULT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `prompt_hash` varchar(64) DEFAULT NULL,
  `package_json` longtext DEFAULT NULL,
  `match_score` int(11) DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_aipkg_email` (`user_email`),
  INDEX `idx_aipkg_hash` (`prompt_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI generated itineraries
CREATE TABLE IF NOT EXISTS `tbl_ai_itineraries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `itinerary_json` longtext DEFAULT NULL,
  `packing_list` text DEFAULT NULL,
  `ai_version` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_itin_booking` (`booking_id`),
  INDEX `idx_itin_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trip Copilot conversation history
CREATE TABLE IF NOT EXISTS `tbl_copilot_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `role` enum('user','assistant') NOT NULL,
  `message` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_cop_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Travel memories (post-trip AI journal)
CREATE TABLE IF NOT EXISTS `tbl_travel_memories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  `journal_text` longtext DEFAULT NULL,
  `social_captions` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI review sentiment analysis
CREATE TABLE IF NOT EXISTS `tbl_review_sentiment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `review_id` int(11) DEFAULT NULL,
  `sentiment` enum('positive','neutral','negative') DEFAULT NULL,
  `score` decimal(4,3) DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `analysed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_review_sentiment` (`review_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User AI preference profile (learned over time)
CREATE TABLE IF NOT EXISTS `tbl_user_profile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(100) DEFAULT NULL,
  `interests` json DEFAULT NULL,
  `budget_range` varchar(50) DEFAULT NULL,
  `preferred_climate` varchar(50) DEFAULT NULL,
  `travel_style` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_profile_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Safety and weather alerts
CREATE TABLE IF NOT EXISTS `tbl_safety_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `destination` varchar(200) DEFAULT NULL,
  `alert_type` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `severity` enum('info','warning','critical') DEFAULT 'info',
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add AiItinerary column to custom trips if missing
ALTER TABLE `tblcustomtrips` ADD COLUMN IF NOT EXISTS `AiItinerary` longtext DEFAULT NULL;

-- ============================================================
-- Done. Set ANTHROPIC_API_KEY in includes/config.php next.
-- ============================================================
