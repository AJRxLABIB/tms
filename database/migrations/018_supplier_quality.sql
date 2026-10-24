-- 018: supplier quality, human review, disputes and inventory-source labels. Additive only (no ALTER of existing tables).
CREATE TABLE IF NOT EXISTS `tbl_supplier_quality_snapshots` (
  `SnapshotId` int(11) NOT NULL AUTO_INCREMENT,
  `SupplierId` int(11) NOT NULL,
  `Score` decimal(5,2) DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'insufficient_data',
  `NeedsReview` tinyint(1) NOT NULL DEFAULT 0,
  `MetricsJson` mediumtext DEFAULT NULL,
  `ComputedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`SnapshotId`),
  KEY `idx_sqs_supplier` (`SupplierId`, `ComputedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_supplier_reviews_log` (
  `ReviewLogId` int(11) NOT NULL AUTO_INCREMENT,
  `SupplierId` int(11) NOT NULL,
  `Reviewer` varchar(80) NOT NULL,
  `Decision` varchar(30) NOT NULL,
  `Note` varchar(500) NOT NULL,
  `SnapshotId` int(11) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ReviewLogId`),
  KEY `idx_srl_supplier` (`SupplierId`, `CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_supplier_disputes` (
  `DisputeId` int(11) NOT NULL AUTO_INCREMENT,
  `SupplierId` int(11) NOT NULL,
  `Subject` varchar(160) NOT NULL,
  `Body` text NOT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'open',
  `Resolution` text DEFAULT NULL,
  `ResolvedBy` varchar(80) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`DisputeId`),
  KEY `idx_sd_supplier` (`SupplierId`, `Status`),
  KEY `idx_sd_status` (`Status`, `CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_inventory_source` (
  `EntityType` varchar(20) NOT NULL,
  `EntityId` int(11) NOT NULL,
  `Source` enum('live_sync','manual','imported','demo') NOT NULL DEFAULT 'manual',
  `LastSyncedAt` datetime DEFAULT NULL,
  `LastSyncStatus` varchar(20) DEFAULT NULL,
  `LastSyncError` varchar(255) DEFAULT NULL,
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`EntityType`, `EntityId`),
  KEY `idx_inv_source` (`Source`, `LastSyncedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
