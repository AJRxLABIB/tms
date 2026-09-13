-- Migration: 008_price_history
-- Round 92 (master prompt item 26 -- Price Alerts: "Track historical prices
-- where legitimate data is available").
--
-- Round 26's original price-alert build (tbl_price_alerts / tbl_availability_alerts)
-- let a customer be notified when a package's price drops, but never
-- recorded a price history anywhere -- there was nothing to show a trend
-- against, and nothing an alert's target price could be compared to in
-- context. This adds that missing history table.
--
-- This file is the historical-record companion to the same, already-applied
-- change made live via CREATE TABLE IF NOT EXISTS in backend/config.php and
-- includes/config.php -- safe to run again on an install that already has
-- this table (database/migrate.php tolerates "table already exists" errors).

-- One row per package per calendar day. RecordedDate is a plain DATE column
-- (not a DATE() expression over RecordedAt) so the UNIQUE KEY below can
-- dedupe "already snapshotted today" with a portable
-- INSERT ... ON DUPLICATE KEY UPDATE from tms_record_price_snapshot() --
-- called from scripts/check_alerts.php's existing cron run, not a new job
-- (Rule 73: this project already runs that script every 30-60 min, which is
-- exactly the cadence price history needs).
--
-- Deliberately NOT backfilled (Rule 3: never fabricate real-world data) --
-- this table starts empty on every install and only ever grows real
-- snapshots forward from whenever the cron next runs. tms_get_price_history()
-- returns an empty array for a package with no snapshots yet, and
-- frontend/package-details.php shows nothing (not a fake flat line or
-- placeholder chart) until at least two real data points exist.
CREATE TABLE IF NOT EXISTS `tbl_price_history` (
    `Id` INT(11) NOT NULL AUTO_INCREMENT,
    `PackageId` INT(11) NOT NULL,
    `Price` INT(11) NOT NULL,
    `RecordedDate` DATE NOT NULL,
    `RecordedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`Id`),
    UNIQUE KEY `idx_pkg_date` (`PackageId`, `RecordedDate`),
    KEY `idx_package` (`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
