-- Personal trip planner: extends the saved itineraries. Additive only, no existing column is changed or dropped.
-- A personal trip is a plan. It never creates a booking, a payment or a package record.
-- One column per statement so a re-run reports the column as already present and carries on.
ALTER TABLE `tbl_map_itineraries` ADD COLUMN `destination_name` varchar(160) DEFAULT NULL;
ALTER TABLE `tbl_map_itineraries` ADD COLUMN `start_date` date DEFAULT NULL;
ALTER TABLE `tbl_map_itineraries` ADD COLUMN `end_date` date DEFAULT NULL;
ALTER TABLE `tbl_map_itineraries` ADD COLUMN `day_count` smallint(6) DEFAULT NULL COMMENT 'number of days when no dates are set (1 to 60)';
ALTER TABLE `tbl_map_itineraries` ADD COLUMN `notes` varchar(2000) DEFAULT NULL;
ALTER TABLE `tbl_map_itineraries` ADD COLUMN `status` varchar(12) NOT NULL DEFAULT 'planned' COMMENT 'planned or completed';
ALTER TABLE `tbl_map_itinerary_stops` ADD COLUMN `day_index` smallint(6) DEFAULT NULL COMMENT '1-based day of the trip, NULL means day 1';
ALTER TABLE `tbl_map_itinerary_stops` ADD COLUMN `arrive_time` time DEFAULT NULL COMMENT 'planned arrival entered by the user';
ALTER TABLE `tbl_map_itinerary_stops` ADD COLUMN `depart_time` time DEFAULT NULL COMMENT 'planned departure entered by the user';
ALTER TABLE `tbl_map_itinerary_stops` ADD COLUMN `est_cost` decimal(10,2) DEFAULT NULL COMMENT 'estimate entered by the user, NULL means unknown';
ALTER TABLE `tbl_map_itinerary_stops` ADD COLUMN `is_done` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `tbl_map_itinerary_stops` ADD COLUMN `place_key` varchar(120) DEFAULT NULL COMMENT 'same key as tbl_saved_places.place_key when known';
