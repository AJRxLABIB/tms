-- Migration 014: customer-facing two-factor authentication (master prompt
-- item 8 -- Auth & Security Center. AUDIT_REPORT_ROUND15.md Section 8:
-- "Customer-facing 2FA... are genuine gaps").
--
-- Purely additive, and deliberately mirrors the admin table's own existing
-- TwoFASecret/TwoFAEnabled columns exactly (same types, same defaults) --
-- this is the same, already-working RFC 6238 TOTP implementation
-- (backend/totp.php) admin accounts already use, now available to
-- customer accounts too, via the same tms_verify_password()/table-
-- parameter pattern already used everywhere else in this codebase.
-- NULL/0 for every existing customer account -- 2FA stays fully opt-in,
-- nobody is silently required to set it up.
ALTER TABLE `tblusers`
    ADD COLUMN IF NOT EXISTS `TwoFASecret` VARCHAR(64) DEFAULT NULL AFTER `Password`,
    ADD COLUMN IF NOT EXISTS `TwoFAEnabled` TINYINT(1) DEFAULT 0 AFTER `TwoFASecret`;
