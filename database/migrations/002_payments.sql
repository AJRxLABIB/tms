-- Migration: 002_payments
-- Adds a real payment ledger. Additive only -- no existing table is renamed
-- or dropped, and no existing row is touched. This does NOT connect to any
-- real payment processor by itself; it is the data layer the new
-- PaymentService / PaymentProviderInterface code (includes/payments/) reads
-- and writes. See PAYMENTS.md for the full architecture writeup.
CREATE TABLE IF NOT EXISTS `tblpayments` (
  `PaymentId` INT(11) NOT NULL AUTO_INCREMENT,
  `BookingId` INT(11) NOT NULL,
  `Provider` VARCHAR(30) NOT NULL COMMENT 'sandbox | sslcommerz | bkash | nagad',
  `ProviderMode` VARCHAR(10) NOT NULL DEFAULT 'sandbox' COMMENT 'sandbox | live',
  `ProviderTransactionId` VARCHAR(120) DEFAULT NULL,
  `AmountMinor` BIGINT(20) NOT NULL COMMENT 'integer minor units (poisha for BDT), never float',
  `Currency` VARCHAR(3) NOT NULL DEFAULT 'BDT',
  `Status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|authorized|paid|failed|cancelled|refunded|partially_refunded|expired',
  `IdempotencyKey` VARCHAR(64) NOT NULL,
  `RawRequest` MEDIUMTEXT DEFAULT NULL,
  `RawResponse` MEDIUMTEXT DEFAULT NULL,
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`PaymentId`),
  UNIQUE KEY `uq_idempotency` (`IdempotencyKey`),
  KEY `idx_booking` (`BookingId`),
  KEY `idx_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `tblpayment_events` (
  `EventId` INT(11) NOT NULL AUTO_INCREMENT,
  `PaymentId` INT(11) DEFAULT NULL,
  `Provider` VARCHAR(30) NOT NULL,
  `EventType` VARCHAR(60) NOT NULL,
  `SignatureValid` TINYINT(1) NOT NULL DEFAULT 0,
  `Payload` MEDIUMTEXT DEFAULT NULL,
  `ProcessedAt` TIMESTAMP NULL DEFAULT NULL,
  `ReceivedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`EventId`),
  KEY `idx_payment` (`PaymentId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `tblpayment_refunds` (
  `RefundId` INT(11) NOT NULL AUTO_INCREMENT,
  `PaymentId` INT(11) NOT NULL,
  `AmountMinor` BIGINT(20) NOT NULL,
  `Reason` VARCHAR(255) DEFAULT NULL,
  `Status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|processed|failed',
  `RequestedBy` VARCHAR(100) DEFAULT NULL COMMENT 'admin username or user email',
  `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ProcessedAt` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`RefundId`),
  KEY `idx_payment` (`PaymentId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
