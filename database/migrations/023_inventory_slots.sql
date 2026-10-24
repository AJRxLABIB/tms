-- 023: date/time inventory slots, expiring holds, synchronisation log (hotels and activities).
-- Additive only: three new tables, no ALTER/DROP of existing tables. Safe to re-run.
-- An entity with NO rows in tbl_inventory_slots is "unmanaged": existing booking flows are unchanged for it.

CREATE TABLE IF NOT EXISTS `tbl_inventory_slots` (
  `SlotId` int(11) NOT NULL AUTO_INCREMENT,
  `EntityType` enum('hotel','activity') NOT NULL,
  `EntityId` int(11) NOT NULL,
  `SlotDate` date NOT NULL,
  `StartTime` time NOT NULL DEFAULT '00:00:00' COMMENT '00:00:00 = all-day slot (hotel night, day pass)',
  `Capacity` int(11) NOT NULL DEFAULT 0,
  `Booked` int(11) NOT NULL DEFAULT 0 COMMENT 'units held (active holds) + converted bookings',
  `PriceOverride` int(11) DEFAULT NULL COMMENT 'seasonal/special price per unit, NULL = use the listing price',
  `CutoffMinutes` int(11) NOT NULL DEFAULT 0 COMMENT 'no new holds within this many minutes of the slot start',
  `IsBlackout` tinyint(1) NOT NULL DEFAULT 0,
  `Source` enum('live_sync','manual','imported','demo') NOT NULL DEFAULT 'manual',
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`SlotId`),
  UNIQUE KEY `uq_inv_slot` (`EntityType`,`EntityId`,`SlotDate`,`StartTime`),
  KEY `idx_inv_slot_date` (`SlotDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_inventory_holds` (
  `HoldId` int(11) NOT NULL AUTO_INCREMENT,
  `SlotId` int(11) NOT NULL,
  `Holder` varchar(120) NOT NULL COMMENT 'customer email, or an opaque session token for guests',
  `Qty` int(11) NOT NULL DEFAULT 1,
  `Status` enum('active','converted','expired','released') NOT NULL DEFAULT 'active',
  `ActiveFlag` tinyint(1) DEFAULT 1 COMMENT '1 while active, NULL otherwise: lets UNIQUE allow only one active hold per holder per slot',
  `ExpiresAt` datetime NOT NULL,
  `ConvertedRef` varchar(40) DEFAULT NULL COMMENT 'e.g. HB123 / AB45 once turned into a booking',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`HoldId`),
  UNIQUE KEY `uq_inv_hold_active` (`SlotId`,`Holder`,`ActiveFlag`),
  KEY `idx_inv_hold_exp` (`Status`,`ExpiresAt`),
  CONSTRAINT `fk_inv_hold_slot` FOREIGN KEY (`SlotId`) REFERENCES `tbl_inventory_slots` (`SlotId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_inventory_sync_log` (
  `SyncId` int(11) NOT NULL AUTO_INCREMENT,
  `Source` varchar(60) NOT NULL COMMENT 'feed / supplier / importer name',
  `EntityType` varchar(20) DEFAULT NULL,
  `Status` enum('running','ok','partial','failed') NOT NULL DEFAULT 'running',
  `ItemsSeen` int(11) NOT NULL DEFAULT 0,
  `ItemsChanged` int(11) NOT NULL DEFAULT 0,
  `Error` varchar(255) DEFAULT NULL,
  `StartedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `FinishedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`SyncId`),
  KEY `idx_inv_sync_source` (`Source`,`StartedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
