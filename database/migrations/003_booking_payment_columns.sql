-- Migration: 003_booking_payment_columns
-- Adds payment-tracking columns to the existing tblbooking table without
-- touching any existing column or row. database/migrate.php applies ALTER
-- statements individually and tolerates "duplicate column" errors, so this
-- file is safe to run more than once and safe on a booking table that
-- already has some of these columns from an earlier hand-run migration.
ALTER TABLE `tblbooking` ADD COLUMN `PaymentStatus` VARCHAR(20) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid|pending|paid|failed|refunded|partially_refunded' AFTER `status`;
ALTER TABLE `tblbooking` ADD COLUMN `PaymentId` INT(11) DEFAULT NULL COMMENT 'FK to tblpayments.PaymentId of the payment that most recently changed this booking''s PaymentStatus' AFTER `PaymentStatus`;
ALTER TABLE `tblbooking` ADD INDEX `idx_payment_status` (`PaymentStatus`);
