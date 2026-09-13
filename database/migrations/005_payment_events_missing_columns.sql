-- Migration: 005_payment_events_missing_columns
-- Round 84 (master prompt items 8/37) -- discovered while live-testing the
-- money-arithmetic fix in checkout.php/cart.php/ticket.php/payment.php/
-- api/index.php, not something set out to change. 002_payments.sql (and the
-- matching CREATE TABLE IF NOT EXISTS fallback in backend/config.php /
-- includes/config.php) never declared four columns that
-- backend/payments/PaymentService.php's own code has always required:
-- ProviderEventId and Signature (read/written by handleWebhook() for
-- provider-event dedup and audit), ProcessingStatus (read/written by
-- confirmSandboxPayment(), handleWebhook(), markWebhookEvent()), and
-- RetryCount (incremented on a failed webhook). Every sandbox payment
-- confirmation was silently failing as a result -- confirmed live via a
-- real PDOException: "SQLSTATE[42S22]: Column not found: 1054 Unknown
-- column 'ProcessingStatus' in 'field list'", caught internally by
-- confirmSandboxPayment()'s try/catch and surfaced to the customer only as
-- a generic "Payment could not be processed right now."
--
-- Left 002_payments.sql itself unedited (an applied migration is a
-- historical record, not something to rewrite -- Rule 39); this is the
-- additive follow-up migration. database/migrate.php applies ALTER
-- statements individually and tolerates "duplicate column" errors, so this
-- file is safe to run more than once and safe against an install that
-- already got these columns from the self-healing tms_ensure_columns() call
-- added the same round in backend/config.php / includes/config.php.
ALTER TABLE `tblpayment_events` ADD COLUMN `ProviderEventId` VARCHAR(150) DEFAULT NULL AFTER `Provider`;
ALTER TABLE `tblpayment_events` ADD COLUMN `Signature` VARCHAR(255) DEFAULT NULL AFTER `SignatureValid`;
ALTER TABLE `tblpayment_events` ADD COLUMN `ProcessingStatus` VARCHAR(20) NOT NULL DEFAULT 'received' AFTER `ProcessedAt`;
ALTER TABLE `tblpayment_events` ADD COLUMN `RetryCount` INT(11) NOT NULL DEFAULT 0 AFTER `ProcessingStatus`;
