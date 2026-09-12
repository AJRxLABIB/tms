# Testing

## What exists today

`tests/run.php` — a small, dependency-free test runner (no PHPUnit/Composer
setup exists in this project, and adding a full framework for a handful of
checks would be overengineering for its current size — Rule 73). Covers the
highest financial-risk logic first:

- Money math (`PaymentService::toMinorUnits`) — no float-rounding drift.
- Dynamic pricing (`tms_dynamic_price`) — multiplier always stays inside its
  documented clamp, never goes negative.
- Availability (`tms_remaining_seats`) — never returns a negative seat count.
- Every `database/migrations/*.sql` file is valid, non-empty, and contains
  no `DROP TABLE`/`DROP DATABASE` (migrations here must be additive only).
- `database/migrate.php --status` runs cleanly against your actual DB.
- Login brute-force lockout (`tms_login_lockout_minutes_remaining`) actually
  activates after repeated failures and clears on success — the exact
  behavior `api/index.php`'s `api_login()` was missing before Round 17.
- The new payment refund-ledger methods (`PaymentService::logRefundRequest`/
  `markRefundProcessed`/`markRefundRejected`) never throw when a booking was
  never paid through this system (the common case for most bookings).
- **(Round 39)** `NLSearchParser::durationOverlaps()` — the exact cases
  live-verified in Round 36, including the hyphenated-duration regex bug
  found and fixed that round, pinned down as a permanent regression test.
- **(Round 39)** `tms_privacy_tables()` schema consistency — every
  table/column pair it lists (that exists in the current DB) actually has
  that column, so a future rename/drop can't silently break account
  deletion or data export without a test failing.
- **(Round 39)** The Round 37 hotel/activity marketplace tables
  (`tblhotels`/`tblactivities`) have their `IsSampleData` column, have
  seeded rows, and every row is correctly flagged as sample data — guards
  the Rule 3 labeling this feature depends on.
- **(Round 39)** RBAC fail-open behavior (`tms_admin_role()` /
  `tms_admin_can()`) — an unrecognized admin session resolves to
  `superadmin` rather than silently locking an admin out, pinning down the
  documented fail-open contract in `includes/config.php`.

Run it:
```
php tests/run.php
```
It needs your local database configured (same as running the app itself) —
these are integration tests against real tables, since this project has no
mocking layer, and a bare assertion runner isn't the place to build one.

*(Round 39 testing note: this assistant's session had no working CLI shell
against the deployed environment, so the 4 new tests above were verified by
running their exact logic through a temporary web-accessible script instead
of `php tests/run.php` directly — all 4 passed against the live DB. That
throwaway script was disabled (404 stub) immediately after, per this
project's usual cleanup convention. If you run `php tests/run.php` from a
real shell and see a different result, trust that over this note.)*

## What is NOT covered yet (honest gap list, Rule 77)

- No browser/end-to-end tests (register → login → search → book → pay →
  confirm). This would need a headless-browser tool (e.g. Playwright) added
  to the project — a real, separate piece of setup, not attempted here.
- No dedicated security test suite (CSRF/XSS/SQLi/IDOR fuzzing) — the
  audit rounds in `SESSION_SUMMARY_INDEX.md`/`AUDIT_REPORT_ROUND*.md`
  covered these by manual review + live verification, not automated
  regression tests that run on every change.
- No admin-workflow tests.
- ~~No CI pipeline wired to run `tests/run.php` automatically~~ — **built**:
  `.github/workflows/ci.yml` lints every PHP file and runs `tests/run.php`
  against a real throwaway MySQL 8 service container on every push/PR to
  `main`/`develop`. It activates automatically the first time this repo is
  pushed to GitHub — nothing else to configure. It still hasn't actually
  run on GitHub itself, since this repo isn't pushed there today (only a
  local git repo exists so far) — that part of this gap is unchanged.

  What *did* change (Round 53, master prompt item 59, "CI pipeline never
  actually run"): rather than leave that as an untested assumption, this
  round replicated the workflow's exact steps by hand — lint every PHP
  file, load `tms.sql` + `v6_migration.sql` + `AI_MIGRATION.sql` +
  `explore_migration.sql` + `schema_repair_migration.sql` into a genuinely
  fresh database, configure `.env`, run `database/migrate.php`, run
  `php tests/run.php` — in an isolated sandbox database, matching the
  workflow file line for line. **It failed, twice, on the first two real
  attempts**, before this round's fixes:
  1. `AI_MIGRATION.sql` fatal-errored (`Table 'tblcustomtrips' doesn't
     exist`) loading into a genuinely empty database, because it only ever
     `ALTER`ed that table, never created it — safe on any real install
     (`backend/config.php`'s self-healing creates the table on first page
     load, long before anyone runs this file) but fatal on the from-scratch
     database a CI run starts with. Fixed by adding the same
     `CREATE TABLE IF NOT EXISTS` self-healing already uses.
  2. `tests/run.php` then fatal-errored (`Cannot redeclare function
     tms_remaining_seats()`) because it `require`d `PricingEngine.php`
     directly on top of `backend/config.php`'s own internal
     `require_once` of the same file — a plain `require` doesn't check
     PHP's already-included-file registry the way `require_once` does, so
     it loaded (and re-declared) the file a second time. This means
     `php tests/run.php` had never actually completed a run before this
     round, for however long `config.php` has `require_once`d
     `PricingEngine.php` — which is also why Round 39's four new tests
     were verified through a throwaway script instead (see the note
     above): the runner itself was already broken and nobody had hit it
     head-on. Fixed by switching every bootstrap `require` in
     `tests/run.php` to `require_once`.

  After both fixes, the full sequence — lint (345/345 files clean) →
  schema load (all 5 files, clean) → `database/migrate.php` (clean) →
  `php tests/run.php` — completes with **16/16 tests passing**, the first
  time this exact sequence has ever been confirmed to work end-to-end.
  Caveat: this was MariaDB 10.11 and PHP 8.4 in an isolated sandbox, not
  the workflow's pinned MySQL 8.0 container and PHP 8.2 inside GitHub's
  own infrastructure — a faithful, but not identical, reproduction. The
  one way to close that remaining gap for real is the next section's step 1.

## Recommended next step if you want to build this out further

1. Push this repo to GitHub so `.github/workflows/ci.yml` actually starts
   running (it's already written and waiting — see above; Round 53 also
   confirmed its steps genuinely pass end-to-end in a faithful local
   replica, not just that the file parses).
2. Add Playwright (or similar) for the one end-to-end path that matters
   most commercially: register → book → pay → confirm.
3. Only then consider PHPUnit, once there are enough tests that the plain
   runner's lack of features (fixtures, mocking, parallelism) actually
   starts to hurt.
