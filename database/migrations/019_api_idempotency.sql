-- 019: Idempotency-Key store for REST API POST creates (see backend/ApiSupport.php).
-- Additive only. Rows expire after 24 h (ExpiresAt); the API deletes expired rows opportunistically.
CREATE TABLE IF NOT EXISTS `tbl_api_idempotency` (
  `Id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `UserEmail` VARCHAR(100) NOT NULL,
  `IdemKey` VARCHAR(128) NOT NULL,
  `Endpoint` VARCHAR(60) NOT NULL,
  `RequestHash` CHAR(64) NOT NULL,
  `State` ENUM('in_progress','done') NOT NULL DEFAULT 'in_progress',
  `ResponseStatus` SMALLINT UNSIGNED DEFAULT NULL,
  `ResponseBody` MEDIUMTEXT DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ExpiresAt` DATETIME NOT NULL,
  PRIMARY KEY (`Id`),
  UNIQUE KEY `uq_idem` (`UserEmail`, `IdemKey`, `Endpoint`),
  KEY `idx_idem_expiry` (`ExpiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
