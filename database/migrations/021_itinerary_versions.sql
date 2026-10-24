-- 021: versioned itinerary editing + disruption event tracking. Additive only: three new tables, nothing existing is altered.
-- Itineraries stay in tbl_ai_itineraries (what the rest of the app reads); versions add history, proposals, diffs and an audit trail.

CREATE TABLE IF NOT EXISTS `tbl_itinerary_versions` (
  `VersionId` int(11) NOT NULL AUTO_INCREMENT,
  `BookingId` int(11) NOT NULL,
  `UserEmail` varchar(100) NOT NULL,
  `VersionNo` int(11) NOT NULL,
  `ParentVersionId` int(11) DEFAULT NULL,
  `Source` varchar(20) NOT NULL DEFAULT 'ai',
  `Status` varchar(12) NOT NULL DEFAULT 'proposed',
  `SourceItineraryId` int(11) DEFAULT NULL,
  `ItineraryJson` longtext NOT NULL,
  `CostJson` text DEFAULT NULL,
  `CheckJson` mediumtext DEFAULT NULL,
  `DiffJson` mediumtext DEFAULT NULL,
  `AffectedJson` text DEFAULT NULL,
  `Note` varchar(500) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `DecidedAt` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`VersionId`),
  UNIQUE KEY `uq_itv_booking_no` (`BookingId`,`VersionNo`),
  KEY `idx_itv_booking_status` (`BookingId`,`Status`),
  KEY `idx_itv_user` (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_itinerary_audit` (
  `AuditId` bigint(20) NOT NULL AUTO_INCREMENT,
  `BookingId` int(11) NOT NULL,
  `VersionId` int(11) DEFAULT NULL,
  `Actor` varchar(100) NOT NULL,
  `Action` varchar(30) NOT NULL,
  `Detail` varchar(500) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AuditId`),
  KEY `idx_ita_booking` (`BookingId`,`AuditId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (booking, disruption signal): label says how sure we are, source/time say where it came from and when.
-- Label: verified (admin-verified notice with a source) | forecast (model/weather forecast) | unconfirmed (report not yet verified).
CREATE TABLE IF NOT EXISTS `tbl_disruption_events` (
  `EventId` int(11) NOT NULL AUTO_INCREMENT,
  `BookingId` int(11) NOT NULL,
  `SignalKey` char(40) NOT NULL,
  `Label` varchar(12) NOT NULL,
  `Severity` varchar(8) NOT NULL,
  `Summary` varchar(300) NOT NULL,
  `SourceName` varchar(120) DEFAULT NULL,
  `SourceUrl` varchar(255) DEFAULT NULL,
  `ObservedAt` datetime NOT NULL,
  `FirstSeenAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `LastSeenAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `Status` varchar(12) NOT NULL DEFAULT 'open',
  `ResolvedAt` timestamp NULL DEFAULT NULL,
  `Resolution` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`EventId`),
  UNIQUE KEY `uq_disr_booking_key` (`BookingId`,`SignalKey`),
  KEY `idx_disr_status` (`Status`,`Severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
