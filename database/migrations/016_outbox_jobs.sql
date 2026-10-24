-- 016: durable internal event outbox + idempotent consumer deliveries (additive, safe to re-run).
CREATE TABLE IF NOT EXISTS `tbl_outbox_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(80) NOT NULL,
  `aggregate_type` VARCHAR(60) NOT NULL DEFAULT '',
  `aggregate_id` VARCHAR(64) NOT NULL DEFAULT '',
  `payload` MEDIUMTEXT DEFAULT NULL,
  `idempotency_key` VARCHAR(190) NOT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|done|failed|dead',
  `attempts` INT NOT NULL DEFAULT 0,
  `max_attempts` INT NOT NULL DEFAULT 6,
  `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` DATETIME DEFAULT NULL,
  `locked_by` VARCHAR(64) DEFAULT NULL,
  `last_error` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outbox_idem` (`idempotency_key`),
  KEY `idx_outbox_claim` (`status`, `available_at`),
  KEY `idx_outbox_agg` (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_outbox_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` BIGINT UNSIGNED NOT NULL,
  `consumer` VARCHAR(80) NOT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'done' COMMENT 'done|failed',
  `error` VARCHAR(500) DEFAULT NULL,
  `delivered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery` (`event_id`, `consumer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
