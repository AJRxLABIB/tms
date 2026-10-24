-- Single-use 2FA recovery codes for ADMIN accounts (table `admin`). Separate from the customer table because admin ids and
-- customer ids overlap. Only a bcrypt hash of each code is stored; plain codes are shown once. Additive and safe to re-run.
CREATE TABLE IF NOT EXISTS `tbl_admin_2fa_recovery_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_2fa_recovery_user` (`user_id`, `used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
