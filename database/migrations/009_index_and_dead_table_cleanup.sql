-- 009_index_and_dead_table_cleanup.sql
-- Round 115, Phase C (master prompt item 33 — index the columns it names;
-- item 5 — general database improvement / cleanup).
--
-- SCOPE NOTE: this migration is deliberately narrow. The master prompt's
-- Phase 5 also names FLOAT-vs-DECIMAL money columns, mixed latin1/utf8
-- charset, and the separate (not generalized) hotel/activity booking
-- tables as schema debt. All three are real, but each is a data-type or
-- structural change that could alter how already-stored values are read
-- back by existing application code (Rule 8: never casually break the
-- database) — each deserves its own dedicated round with its own testing
-- pass, the same proportionality call this project already made for map-
-- system items like clustering/pagination (see CHANGELOG.md Round 114).
-- This migration only adds indexes (purely additive, cannot change any
-- existing query result) and drops one table already confirmed to have
-- zero reads or writes anywhere in the codebase.
--
-- ── Step 1: index the master-prompt-named columns that are genuinely
--    missing an index today ──
--
-- Checked first, not assumed: tblbooking.PackageId and .UserEmail already
-- have an index (added implicitly by migration 004's foreign keys —
-- InnoDB auto-indexes an FK's referencing column). tblusers.EmailId
-- already has a UNIQUE index (migration 004). tblpayments.BookingId and
-- .Status were indexed from the day that table was created (migration
-- 002: idx_booking, idx_status). tbltourpackages' destination proxies
-- (Country, Region) were already indexed by the pre-existing
-- schema_repair_migration.sql (idx_pkg_country, idx_pkg_region). None of
-- those are repeated here. What's left, verified against the live
-- tblbooking column list, genuinely has no index yet:
--
ALTER TABLE tblbooking ADD INDEX idx_booking_regdate (RegDate);
ALTER TABLE tblbooking ADD INDEX idx_booking_status (status);
ALTER TABLE tblbooking ADD INDEX idx_booking_fromdate (FromDate);
ALTER TABLE tblbooking ADD INDEX idx_booking_todate (ToDate);

-- ── Step 2: drop the confirmed-dead tbl_ai_analytics table ──
--
-- A metric_key/metric_value cache table, self-documented in
-- backend/config.php's own Round 34 comment as "still unused by anything;
-- flagged as its own dead-schema cleanup candidate" — confirmed again
-- this round via a project-wide grep for any INSERT/SELECT/UPDATE/DELETE
-- against it (none found; the only hits were the table's own
-- self-healing CREATE TABLE IF NOT EXISTS and explanatory comments). Its
-- actual job (an AI response cache) is handled by the shared
-- CacheService/tblcache instead (see AIClient.php's getCached()/setCached()).
-- backend/config.php's self-healing migration list no longer recreates
-- this table as of this same round, so this drop is permanent rather than
-- something the next request would silently undo.
DROP TABLE IF EXISTS tbl_ai_analytics;
