-- Migration 013: per-booking display-currency / FX record (master prompt
-- item 37 -- Currency. Tracked in AUDIT_REPORT_ROUND15.md's roadmap under
-- the audit's own label "Phase 38 -- Multi-currency storage").
--
-- Purely additive. tblbooking's existing money column (TotalCost) is
-- untouched -- every booking is still stored and charged in BDT exactly
-- as before, and every column below is nullable so a booking made before
-- this migration (or made while browsing in BDT, where there's nothing
-- to convert) simply has NULL here. This does not attempt real
-- multi-currency SETTLEMENT -- the payment providers
-- (SslcommerzProvider et al.) are BDT-only gateways and unimplemented
-- pending real credentials (see backend/payments/SslcommerzProvider.php's
-- own docblock) -- it records what the customer actually saw (source,
-- rate, timestamp -- exactly what item 37 asks for) at the moment they
-- booked, for their invoice and for support to reference later.
ALTER TABLE `tblbooking`
    ADD COLUMN IF NOT EXISTS `DisplayCurrency` VARCHAR(3) DEFAULT NULL COMMENT 'ISO 4217 code the customer was viewing prices in at booking time, NULL if BDT (no conversion)' AFTER `TotalCost`,
    ADD COLUMN IF NOT EXISTS `DisplayAmount` DECIMAL(14,2) DEFAULT NULL COMMENT 'TotalCost converted to DisplayCurrency, as shown to the customer' AFTER `DisplayCurrency`,
    ADD COLUMN IF NOT EXISTS `FxRate` DECIMAL(18,6) DEFAULT NULL COMMENT 'BDT-to-DisplayCurrency rate used' AFTER `DisplayAmount`,
    ADD COLUMN IF NOT EXISTS `FxRateSource` VARCHAR(100) DEFAULT NULL COMMENT 'e.g. open.er-api.com' AFTER `FxRate`,
    ADD COLUMN IF NOT EXISTS `FxRateTimestamp` VARCHAR(64) DEFAULT NULL COMMENT 'the rate feed''s own as-of timestamp string, stored verbatim (not all formats round-trip cleanly through DATETIME)' AFTER `FxRateSource`;
