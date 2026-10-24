-- 043_map_admin.sql
-- Admin tooling for the tourism map: an audit trail for every change to map data and a list of place pairs
-- an administrator has confirmed are NOT duplicates. Additive only, two new tables, safe to re-run.
-- NOTE: the migration runner splits on semicolons, so no comment or string below contains one.

CREATE TABLE IF NOT EXISTS `tbl_map_audit` (
  `AuditId` int(11) NOT NULL AUTO_INCREMENT,
  `Actor` varchar(100) NOT NULL,
  `Action` varchar(40) NOT NULL,
  `PlaceId` int(11) DEFAULT NULL,
  `Detail` varchar(255) DEFAULT NULL,
  `BeforeJson` mediumtext DEFAULT NULL,
  `AfterJson` mediumtext DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AuditId`),
  KEY `idx_map_audit_place` (`PlaceId`,`CreatedAt`),
  KEY `idx_map_audit_time` (`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_map_duplicate_dismissals` (
  `DismissalId` int(11) NOT NULL AUTO_INCREMENT,
  `PlaceLow` int(11) NOT NULL,
  `PlaceHigh` int(11) NOT NULL,
  `DismissedBy` varchar(100) NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`DismissalId`),
  UNIQUE KEY `uq_map_dup_pair` (`PlaceLow`,`PlaceHigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
