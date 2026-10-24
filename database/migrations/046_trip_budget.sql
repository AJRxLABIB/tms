-- Trip budget: an optional total the traveller sets for a personal trip. Additive only, no existing column is changed or dropped.
-- It is a number the user typed. Nothing is estimated or looked up. NULL means no budget has been set.
-- One column per statement so a re-run reports the column as already present and carries on.
ALTER TABLE `tbl_map_itineraries` ADD COLUMN `budget_total` decimal(12,2) DEFAULT NULL COMMENT 'budget entered by the user, NULL means not set';
ALTER TABLE `tbl_map_itineraries` ADD COLUMN `budget_currency` varchar(3) NOT NULL DEFAULT 'BDT' COMMENT 'ISO 4217 style code chosen by the user';
