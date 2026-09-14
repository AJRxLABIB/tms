-- ============================================================================
-- TMS — Schema Drift Repair Migration
-- ============================================================================
-- WHY THIS EXISTS
-- ----------------------------------------------------------------------------
-- tbltourpackages, tblusers, tblbooking, tblissues and tblpages already
-- existed (from the original tms.sql install) with an OLDER, NARROWER set of
-- columns. Newer PHP code added `CREATE TABLE IF NOT EXISTS ...` statements
-- with additional columns, but because those tables already existed, MySQL
-- silently skipped the whole CREATE TABLE — the extra columns were never
-- actually added to a real install.
--
-- This is the ROOT CAUSE of several audit findings:
--   * "No packages found" for Cox's Bazar / Bangladesh / most destinations
--     -> the 400+ package seeder's INSERT referenced tbltourpackages.
--        PackageDescription, which didn't exist -> every insert threw ->
--        the whole seed transaction rolled back -> the catalog silently
--        stayed at the original 9 demo rows.
--   * destinations.php blank page -> a query against a missing column threw
--     an uncaught PDOException with error display suppressed.
--   * Language switching not persisting -> language.php's
--     "UPDATE tblusers SET Language=..." silently failed (no Language column).
--
-- The app now also repairs this automatically at runtime (see
-- includes/config.php -> _tms_repair_schema_drift()), so running this file is
-- OPTIONAL — it exists for admins who prefer applying schema changes
-- explicitly/manually via phpMyAdmin rather than relying on the app to do it
-- on first request.
--
-- COMPATIBILITY: uses "ADD COLUMN IF NOT EXISTS", supported on MySQL 8.0.29+
-- and MariaDB 10.0.2+ (i.e. any current XAMPP install). If your server is
-- older than that, remove "IF NOT EXISTS" from each line below — you may then
-- see a harmless "Duplicate column" error on columns that already exist,
-- which is safe to ignore.
-- ============================================================================

ALTER TABLE `tbltourpackages`
  ADD COLUMN IF NOT EXISTS `PackageDescription` MEDIUMTEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `PackageDuration`    VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `MapLat`              VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `MapLng`              VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `AvgRating`           DECIMAL(3,1) DEFAULT 0.0,
  ADD COLUMN IF NOT EXISTS `Status`              INT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `Country`             VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `Region`              VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `IsFeatured`          TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `ViewCount`           INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `PackageMeta`         LONGTEXT DEFAULT NULL;

ALTER TABLE `tblusers`
  ADD COLUMN IF NOT EXISTS `Language` VARCHAR(10) DEFAULT 'en';

ALTER TABLE `tblbooking`
  ADD COLUMN IF NOT EXISTS `MobileNumber`    VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `NoOfPersons`     INT DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `BookingDate`     TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `TotalCost`       INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `CouponCode`      VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `DiscountApplied` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `CancelledBy`     VARCHAR(10) DEFAULT NULL;

ALTER TABLE `tblissues`
  ADD COLUMN IF NOT EXISTS `BookingId`    INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `IssueDetails` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `status`       INT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `AdminReply`   TEXT DEFAULT NULL;

ALTER TABLE `tblpages`
  ADD COLUMN IF NOT EXISTS `UpdatedAt` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- Helpful indexes for the new destination/country filters used by
-- destinations.php, package-list.php and the Explore Mode search.
CREATE INDEX IF NOT EXISTS idx_pkg_country   ON `tbltourpackages`(`Country`);
CREATE INDEX IF NOT EXISTS idx_pkg_region    ON `tbltourpackages`(`Region`);
CREATE INDEX IF NOT EXISTS idx_pkg_featured  ON `tbltourpackages`(`IsFeatured`);
CREATE INDEX IF NOT EXISTS idx_pkg_viewcount ON `tbltourpackages`(`ViewCount`);
CREATE INDEX IF NOT EXISTS idx_pkg_type      ON `tbltourpackages`(`PackageType`);

-- ----------------------------------------------------------------------------
-- After running this, reload any page once (e.g. destinations.php) — the app
-- will detect the columns now exist and automatically (re)run the 400+
-- package seed from data/packages_seed.json to populate Bangladesh and all
-- other destinations. You do not need to insert package data by hand.
-- ----------------------------------------------------------------------------
