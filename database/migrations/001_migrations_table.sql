-- Migration: 001_migrations_table
-- Creates the tracking table the migration runner (database/migrate.php) uses
-- to know which migrations have already been applied. This is the first
-- "real" migration; everything before it (tms.sql, v6_migration.sql,
-- AI_MIGRATION.sql, explore_migration.sql, schema_repair_migration.sql) is
-- recorded as an already-applied baseline by database/migrate.php itself the
-- first time it runs, so it is never re-run against a database that already
-- has that schema.
CREATE TABLE IF NOT EXISTS `tblmigrations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(190) NOT NULL,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `checksum` CHAR(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
