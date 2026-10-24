-- 041_map_places.sql
-- Tourism map places (points of interest) with aliases, images and a review queue for suggested corrections.
-- Additive only: four new tables, nothing existing is altered. Safe to re-run.
-- Data rules: no coordinates are invented. Rows come from administrators, from the package catalogue
-- (scripts/map_sync_places.php) or from OpenStreetMap (scripts/map_import_osm.php, ODbL). Imported rows start unverified.
-- NOTE: the migration runner splits on semicolons, so no comment or string below contains one.

CREATE TABLE IF NOT EXISTS `tbl_map_places` (
  `PlaceId` int(11) NOT NULL AUTO_INCREMENT,
  `Slug` varchar(160) NOT NULL,
  `Name` varchar(200) NOT NULL,
  `NameBn` varchar(200) DEFAULT NULL,
  `NameNorm` varchar(420) NOT NULL DEFAULT '' COMMENT 'normalised name and Bangla name for matching',
  `Category` varchar(40) NOT NULL DEFAULT 'attraction',
  `Subcategory` varchar(60) DEFAULT NULL,
  `Summary` varchar(500) DEFAULT NULL,
  `Description` mediumtext DEFAULT NULL,
  `Locality` varchar(150) DEFAULT NULL,
  `District` varchar(100) DEFAULT NULL,
  `Region` varchar(100) DEFAULT NULL,
  `Country` varchar(100) DEFAULT NULL,
  `Lat` decimal(9,6) NOT NULL,
  `Lng` decimal(9,6) NOT NULL,
  `Website` varchar(255) DEFAULT NULL,
  `Phone` varchar(40) DEFAULT NULL,
  `OpeningHours` text DEFAULT NULL COMMENT 'JSON, NULL when unknown',
  `FeeInfo` text DEFAULT NULL COMMENT 'JSON or text, NULL when unknown',
  `Facilities` text DEFAULT NULL COMMENT 'JSON list',
  `Accessibility` text DEFAULT NULL COMMENT 'JSON, NULL when unknown',
  `FamilyFriendly` tinyint(1) DEFAULT NULL COMMENT 'NULL means unknown, not no',
  `IsVerified` tinyint(1) NOT NULL DEFAULT 0,
  `VerifiedBy` varchar(100) DEFAULT NULL,
  `VerifiedAt` datetime DEFAULT NULL,
  `Source` enum('admin','osm','package','guide','user') NOT NULL DEFAULT 'admin',
  `SourceRef` varchar(100) DEFAULT NULL,
  `Attribution` varchar(255) DEFAULT NULL,
  `IsDemo` tinyint(1) NOT NULL DEFAULT 0,
  `Status` enum('active','pending','archived') NOT NULL DEFAULT 'pending',
  `PackageId` int(11) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`PlaceId`),
  UNIQUE KEY `uq_map_place_slug` (`Slug`),
  UNIQUE KEY `uq_map_place_srcref` (`Source`,`SourceRef`),
  KEY `idx_map_place_status_cat` (`Status`,`Category`),
  KEY `idx_map_place_coords` (`Lat`,`Lng`),
  KEY `idx_map_place_name` (`NameNorm`(100)),
  KEY `idx_map_place_package` (`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_map_place_aliases` (
  `AliasId` int(11) NOT NULL AUTO_INCREMENT,
  `PlaceId` int(11) NOT NULL,
  `Alias` varchar(200) NOT NULL,
  `Lang` varchar(10) NOT NULL DEFAULT 'en',
  `Normalised` varchar(220) NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AliasId`),
  UNIQUE KEY `uq_map_alias` (`PlaceId`,`Normalised`),
  KEY `idx_map_alias_norm` (`Normalised`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_map_place_images` (
  `ImageId` int(11) NOT NULL AUTO_INCREMENT,
  `PlaceId` int(11) NOT NULL,
  `File` varchar(255) NOT NULL,
  `Thumb` varchar(255) DEFAULT NULL,
  `Alt` varchar(200) DEFAULT NULL,
  `Credit` varchar(200) DEFAULT NULL,
  `License` varchar(100) DEFAULT NULL,
  `SourceUrl` varchar(255) DEFAULT NULL,
  `Sort` int(11) NOT NULL DEFAULT 0,
  `IsPrimary` tinyint(1) NOT NULL DEFAULT 0,
  `Status` enum('active','hidden') NOT NULL DEFAULT 'active',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ImageId`),
  KEY `idx_map_img_place` (`PlaceId`,`Status`,`Sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_map_corrections` (
  `CorrectionId` int(11) NOT NULL AUTO_INCREMENT,
  `PlaceId` int(11) DEFAULT NULL,
  `SubmittedBy` varchar(100) DEFAULT NULL,
  `SubmitterIp` varchar(64) DEFAULT NULL,
  `Field` varchar(40) NOT NULL,
  `Proposed` text DEFAULT NULL COMMENT 'JSON',
  `Note` varchar(1000) DEFAULT NULL,
  `Status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `ReviewedBy` varchar(100) DEFAULT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`CorrectionId`),
  KEY `idx_map_corr_status` (`Status`,`CreatedAt`),
  KEY `idx_map_corr_place` (`PlaceId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
