-- Structured trip state for the Trip Copilot (one row per booking and owner). Additive only.
CREATE TABLE IF NOT EXISTS `tbl_trip_state` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `user_email` varchar(100) NOT NULL,
  `state_json` mediumtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_state_owner` (`booking_id`, `user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
