-- 022: fraud/anomaly review cases (investigation aid only: nothing here bans, blocks or penalises anyone).
-- Additive and safe to re-run.
CREATE TABLE IF NOT EXISTS `tbl_fraud_cases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `signal` VARCHAR(40) NOT NULL COMMENT 'repeated_payment_attempts|duplicate_booking|coupon_referral_abuse|loyalty_anomaly|cancellation_pattern',
  `subject_type` VARCHAR(12) NOT NULL DEFAULT 'user',
  `subject_key` VARCHAR(150) NOT NULL COMMENT 'customer email or other stable identifier',
  `day_key` DATE NOT NULL COMMENT 'scan day: one case per signal+subject+day',
  `score` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 priority for investigation, NOT proof',
  `severity` VARCHAR(8) NOT NULL DEFAULT 'low',
  `reason` VARCHAR(500) NOT NULL,
  `evidence` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON: the exact figures/ids the signal was computed from',
  `status` VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open|reviewing|cleared|confirmed',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_once` (`signal`, `subject_key`, `day_key`),
  KEY `idx_case_status` (`status`, `score`),
  KEY `idx_case_subject` (`subject_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_fraud_case_decisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `reviewer` VARCHAR(100) NOT NULL,
  `decision` VARCHAR(10) NOT NULL COMMENT 'reviewing|cleared|confirmed|note',
  `note` VARCHAR(1000) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dec_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_fraud_appeals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` BIGINT UNSIGNED NOT NULL,
  `user_email` VARCHAR(100) NOT NULL,
  `message` VARCHAR(2000) NOT NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open|upheld|dismissed',
  `response` VARCHAR(1000) DEFAULT NULL,
  `responded_by` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_appeal_case` (`case_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
