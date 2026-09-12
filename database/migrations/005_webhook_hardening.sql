-- Migration: 005_webhook_hardening
-- Master prompt item 43 ("WEBHOOKS"): store event / payload / signature /
-- timestamp / processing status / retry count for every inbound payment
-- webhook, and support idempotency. `tblpayment_events` (from migration
-- 002_payments.sql) already stored Provider/EventType/Payload/SignatureValid/
-- ReceivedAt/ProcessedAt -- this adds the columns item 43 asks for that were
-- still missing: the raw signature value (not just a valid/invalid flag), an
-- explicit processing-status lifecycle, a retry counter, and a
-- provider-scoped event id to dedupe a provider's own redelivery retries.
-- Purely additive (Rule 8) -- no existing column, row, or table is touched.
ALTER TABLE `tblpayment_events`
  ADD COLUMN `ProviderEventId` VARCHAR(150) DEFAULT NULL
    COMMENT 'provider-supplied event/transaction id, or sha256:<hash of raw body> when a provider gives none -- used to dedupe retried deliveries'
    AFTER `Provider`,
  ADD COLUMN `Signature` TEXT DEFAULT NULL
    COMMENT 'raw signature/sign header value as received, for audit -- which header is authoritative is provider-specific (see backend/payments/*.php verifyWebhook())'
    AFTER `Payload`,
  ADD COLUMN `ProcessingStatus` VARCHAR(20) NOT NULL DEFAULT 'received'
    COMMENT 'received|processed|rejected|duplicate|failed'
    AFTER `SignatureValid`,
  ADD COLUMN `RetryCount` INT(11) NOT NULL DEFAULT 0
    COMMENT 'incremented each time re-processing this event was attempted after a failure'
    AFTER `ProcessingStatus`;

-- Dedupe key: the same provider redelivering the same event (every major
-- gateway retries a webhook until it gets a 2xx) must not be logged/
-- processed twice. NULL ProviderEventId (e.g. the existing internal
-- 'sandbox.confirmed' rows written by PaymentService::confirmSandboxPayment(),
-- which isn't an inbound webhook at all) is exempt -- MySQL unique indexes
-- allow any number of NULLs.
ALTER TABLE `tblpayment_events`
  ADD UNIQUE KEY `uq_provider_event` (`Provider`, `ProviderEventId`);

-- Backfill existing rows (if any) to a sane processing status so the new
-- column is never blank for historical data: a row that already has
-- ProcessedAt set was clearly processed; everything else predates this
-- column and is labeled accordingly rather than guessed at.
UPDATE `tblpayment_events` SET `ProcessingStatus` = 'processed' WHERE `ProcessedAt` IS NOT NULL AND `ProcessingStatus` = 'received';
