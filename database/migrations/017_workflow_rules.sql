-- 017: controlled workflow rules (predefined triggers/actions only) + audit runs (additive, safe to re-run).
CREATE TABLE IF NOT EXISTS `tbl_workflow_rules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `trigger_type` VARCHAR(40) NOT NULL COMMENT 'overdue_refund|incomplete_travel_details|supplier_sync_failure|repeated_failed_job|booking_status_change',
  `action_type` VARCHAR(40) NOT NULL COMMENT 'notify_admin|notify_customer|notify_supplier|escalate',
  `params` TEXT DEFAULT NULL COMMENT 'JSON: thresholds and message template (placeholders only)',
  `window_minutes` INT NOT NULL DEFAULT 1440 COMMENT 'idempotency window per subject',
  `max_attempts` INT NOT NULL DEFAULT 3,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rule_enabled` (`enabled`, `trigger_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_workflow_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id` INT NOT NULL,
  `subject_key` VARCHAR(120) NOT NULL,
  `window_key` VARCHAR(40) NOT NULL,
  `dry_run` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending|done|failed|dry_run',
  `attempts` INT NOT NULL DEFAULT 0,
  `detail` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_run_once` (`rule_id`, `subject_key`, `window_key`, `dry_run`),
  KEY `idx_run_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
