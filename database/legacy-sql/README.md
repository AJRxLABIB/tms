# Legacy baseline SQL files

These 5 files are this project's original, pre-migration-system schema
history — applied in this order, historically by hand in phpMyAdmin, before
`database/migrate.php` existed:

1. `tms.sql` — base schema + seed data (`admin`, `tblbooking`, `tblenquiry`,
   `tblissues`, `tblpages`, `tbltourpackages`, `tblusers`)
2. `v6_migration.sql` — `tblactivity_log`, `tblblog`, `tblcoupons`,
   `tblloyalty`, `tblloyalty_log`, `tblwishlist`
3. `AI_MIGRATION.sql` — `tbl_ai_*`, `tbl_copilot_sessions`,
   `tbl_review_sentiment`, `tbl_safety_alerts`, `tbl_travel_memories`,
   `tbl_user_profile`
4. `explore_migration.sql` — `tbl_explore_*`
5. `schema_repair_migration.sql` — assorted column additions/fixes to
   already-shipped tables

They were moved into this dedicated folder (out of the project root) so
every `.sql` file in this project lives under `database/` — either here
(legacy, one-time baseline) or in `database/migrations/` (the current,
tracked migration system; see `database/migrate.php`).

**This move is safe and changes no behavior:**

- `database/migrate.php` never opens these 5 files by path. It only inserts
  fixed label strings (`000_baseline_tms.sql`, etc.) into the
  `tblmigrations` tracking table the first time it runs, to record that
  this schema history was already applied by hand. Those strings are pure
  bookkeeping labels, not file references — verified by reading
  `database/migrate.php` in full before this move.
- `scripts/backup_database.php` and `scripts/restore_database.php` don't
  reference these filenames at all.
- The only real references were in documentation (`README.md`,
  `INSTALL.html`, `DATABASE.md`, `BACKUPS.md`), which were updated in the
  same change to point here.

## For a brand-new install

If you're setting up this project from scratch on a machine with an empty
database, import these two files, in order, via phpMyAdmin:

1. `database/legacy-sql/tms.sql`
2. `database/legacy-sql/v6_migration.sql`

Then run `php database/migrate.php` to apply everything else
(`AI_MIGRATION.sql`, `explore_migration.sql`, `schema_repair_migration.sql`
are **not** needed for a fresh install — `database/migrations/` already
covers everything they used to do, plus everything since).

If you're restoring an *existing* database that already has all of this
project's tables (e.g. moving the project to a new machine, XAMPP
reinstall), you don't need these files at all — just re-import your actual
database backup/dump and run `php database/migrate.php --status` to confirm
nothing is pending.
