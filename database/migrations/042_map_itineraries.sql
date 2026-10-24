-- Saved multi-stop map itineraries (owned by a signed-in customer). Additive only.
CREATE TABLE IF NOT EXISTS `tbl_map_itineraries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(190) NOT NULL,
  `title` varchar(120) NOT NULL,
  `mode` varchar(12) NOT NULL DEFAULT 'driving',
  `total_distance_m` int(11) DEFAULT NULL COMMENT 'only stored when it came from a successful route result',
  `total_duration_s` int(11) DEFAULT NULL COMMENT 'only stored when it came from a successful route result',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_map_itin_user` (`user_email`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_map_itinerary_stops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `itinerary_id` int(11) NOT NULL,
  `position` smallint(6) NOT NULL,
  `place_id` int(11) DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lng` decimal(9,6) NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_map_itin_pos` (`itinerary_id`, `position`),
  CONSTRAINT `fk_map_itin_stop` FOREIGN KEY (`itinerary_id`) REFERENCES `tbl_map_itineraries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
