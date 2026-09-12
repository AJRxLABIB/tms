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

-- BUG FIX (found by actually running the CI pipeline end-to-end for the
-- first time, master prompt item 59 -- "CI pipeline never actually run"):
-- this ALTER assumed `tblcustomtrips` already existed, which is true on
-- any live install (backend/config.php self-heals it into existence, with
-- this exact same column already included, on the very first page load)
-- but false on a genuinely fresh database that only has tms.sql +
-- v6_migration.sql applied -- exactly what a from-scratch CI run does.
-- That made this file fail outright with "Table 'tblcustomtrips' doesn't
-- exist" the moment it was actually executed against a clean database,
-- which would have failed the GitHub Actions workflow's "Load base
-- schema" step on every single push, before the test suite ever ran.
-- Adding the same CREATE TABLE IF NOT EXISTS backend/config.php already
-- uses makes this file safe to run standalone: a no-op against any
-- database that already has the table (i.e. every real install), and now
-- correct against a genuinely empty one (i.e. CI).
CREATE TABLE IF NOT EXISTS `tblcustomtrips` (
  `id` int(11) NOT NULL AUTO_INCREMENT, `UserEmail` varchar(100) DEFAULT NULL,
  `TripName` varchar(200) DEFAULT NULL, `Destination` varchar(200) DEFAULT NULL,
  `StartDate` date DEFAULT NULL, `EndDate` date DEFAULT NULL,
  `Budget` int(11) DEFAULT NULL, `TravelersCount` int(11) DEFAULT NULL,
  `Accommodation` varchar(100) DEFAULT NULL, `Transport` varchar(100) DEFAULT NULL,
  `Activities` mediumtext DEFAULT NULL, `MealPlan` varchar(100) DEFAULT NULL,
  `SpecialRequests` mediumtext DEFAULT NULL, `Status` varchar(50) DEFAULT 'Pending',
  `AdminNote` mediumtext DEFAULT NULL, `AiItinerary` longtext DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add AiItinerary column to custom trips if missing (redundant with the
-- CREATE TABLE above on a fresh run, but kept -- and still needed -- for
-- any database where tblcustomtrips already existed without this column).
ALTER TABLE `tblcustomtrips` ADD COLUMN IF NOT EXISTS `AiItinerary` longtext DEFAULT NULL;

-- ============================================================
-- Done. AI provider keys live in .env (GEMINI_API_KEY / OPENAI_API_KEY /
-- AI_PROVIDER) -- see .env.example and backend/config.php, not
-- includes/config.php (that file is an unused leftover; see
-- SESSION_SUMMARY_INDEX-1.md, Round 49).
-- ============================================================
