-- Unified saved places (destinations, places, food, services, stays) owned by a signed-in customer. Additive only.
-- The owner is identified by user_email, exactly like tbl_map_itineraries. place_key is a stable key chosen by the app.
CREATE TABLE IF NOT EXISTS `tbl_saved_places` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(190) NOT NULL,
  `place_key` varchar(120) NOT NULL,
  `kind` varchar(12) NOT NULL DEFAULT 'place',
  `name` varchar(160) NOT NULL,
  `category` varchar(30) NOT NULL DEFAULT 'other',
  `lat` decimal(9,6) NOT NULL,
  `lng` decimal(9,6) NOT NULL,
  `country` varchar(100) DEFAULT NULL,
  `locality` varchar(150) DEFAULT NULL,
  `destination_name` varchar(160) DEFAULT NULL,
  `note` varchar(300) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_saved_place_user_key` (`user_email`, `place_key`),
  KEY `idx_saved_place_user_created` (`user_email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
