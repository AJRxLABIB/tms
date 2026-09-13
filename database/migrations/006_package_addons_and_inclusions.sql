-- Migration: 006_package_addons_and_inclusions
-- Round 87 (master prompt item 7, "Package System"). Direct research against
-- the code and schema confirmed a prior gap-audit's framing was imprecise:
-- tbltourpackages had no Inclusions/Exclusions/CancellationPolicy concept
-- anywhere -- not a column, not free text, not even a key inside the
-- existing PackageMeta JSON blob (which only ever held destination-guide
-- data: history, climate, best_time_to_visit, etc., seeded once from
-- data/packages_seed.json). This is a fully additive fix, not a
-- restructuring of existing unstructured data.
--
-- This file is the historical-record companion to the same, already-applied
-- change made live via tms_ensure_columns() / CREATE TABLE IF NOT EXISTS in
-- backend/config.php and includes/config.php -- safe to run again on an
-- install that already has these objects (database/migrate.php tolerates
-- "duplicate column"/"table already exists" errors).

ALTER TABLE `tbltourpackages` ADD COLUMN `Inclusions` MEDIUMTEXT DEFAULT NULL AFTER `PackageDetails`;
ALTER TABLE `tbltourpackages` ADD COLUMN `Exclusions` MEDIUMTEXT DEFAULT NULL AFTER `Inclusions`;
ALTER TABLE `tbltourpackages` ADD COLUMN `CancellationPolicy` MEDIUMTEXT DEFAULT NULL AFTER `Exclusions`;

-- Add-on catalog, scoped per package. Managed from
-- admin/manage-package-addons.php (linked from admin/edit-package.php).
CREATE TABLE IF NOT EXISTS `tblpackageaddons` (
    `AddOnId` INT(11) NOT NULL AUTO_INCREMENT,
    `PackageId` INT(11) NOT NULL,
    `Name` VARCHAR(150) NOT NULL,
    `Description` VARCHAR(500) DEFAULT NULL,
    `Price` INT(11) NOT NULL DEFAULT 0,
    `IsActive` TINYINT(1) NOT NULL DEFAULT 1,
    `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`AddOnId`),
    INDEX `idx_addon_package` (`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-booking snapshot of selected add-ons. AddOnId is nullable and Name/
-- Price are copied at booking time on purpose: a later edit or delete of the
-- catalog entry in tblpackageaddons must never rewrite a past booking's
-- receipt (frontend/ticket.php reads only these snapshotted columns, never
-- joining back to tblpackageaddons).
CREATE TABLE IF NOT EXISTS `tblbookingaddons` (
    `Id` INT(11) NOT NULL AUTO_INCREMENT,
    `BookingId` INT(11) NOT NULL,
    `AddOnId` INT(11) DEFAULT NULL,
    `Name` VARCHAR(150) NOT NULL,
    `Price` INT(11) NOT NULL DEFAULT 0,
    `Quantity` INT(11) NOT NULL DEFAULT 1,
    `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`Id`),
    INDEX `idx_bookingaddons_booking` (`BookingId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
