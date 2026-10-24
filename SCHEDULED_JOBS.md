# Scheduled jobs (cron / Task Scheduler)

This project deliberately has no queue/worker infrastructure (no Redis, no
Beanstalkd, no job broker) — for the handful of periodic tasks this app
actually needs, that would be overengineering for its size (Rule 73). Every
background task here is a plain, CLI-only PHP script, meant to be invoked by
the OS's own scheduler. This file is the one place that lists all of them
together; each script's own docblock has the full detail.

| Script | Schedule | What it does |
|---|---|---|
| `database/migrate.php` | On deploy, not periodic | Applies any pending schema migrations. Run this once after pulling new code, not on a timer. Safe to re-run — already-applied migrations are skipped (see `DATABASE.md`). |
| `scripts/check_alerts.php` | Every 30–60 minutes | Fires price-drop and availability notifications for `alerts.php` (Rule 26/27). |
| `scripts/check_trip_disruptions.php` | Daily | Trip Autopilot: for upcoming/ongoing bookings, finds admin-verified disruption notices that apply and stores ONE proposal per event for the customer to approve or decline (never changes anything itself). `--no-ai` for alerts only. See `TRIP_AUTOPILOT.md`. |
| `scripts/expire_pending_bookings.php` | Every 10–15 minutes | Releases seats held by pending, unpaid bookings older than `TMS_BOOKING_HOLD_MINUTES` (off until set). Dry run by default; `--apply` cancels. Skips anything with a paid or in-flight payment. |
| `scripts/expire_inventory_holds.php` | Every 5 minutes | Frees units held by lapsed hotel/activity inventory holds (`InventoryService`). Dry run by default; `--apply` expires them. Needs migration 023. Sync health of inventory feeds is shown on Admin → System Health. |
| `scripts/run_jobs.php --once --rules` | Every 1–5 minutes | Event outbox worker + workflow rules (see "Event outbox and workflow rules" below). Needs migrations 016/017 (`php database/migrate.php`). |
| `scripts/check_wallet_expiry.php` | Daily | Notifies travelers at 30-day and 7-day windows before a Travel Wallet document expires (Rule 28). |
| `scripts/backup_database.php` | Daily | Encrypted database backup with retention pruning and an optional offsite copy (item 60). See `BACKUPS.md`. |
| `scripts/restore_database.php --verify` | Weekly | Restore-tests the newest backup (decrypts + validates, never touches the live DB) so "we have backups" is a tested fact, not a hope. See `BACKUPS.md`. |

## Why these and not a real job queue

None of these need sub-minute latency, retry-with-backoff, or distributed
workers — a booking's price alert firing a few minutes late is a
non-issue. A cron-invoked script that runs, does its work, and exits is the
correct amount of infrastructure for that; a broker would be unused
complexity that itself needs monitoring and could fail silently. If this
app's scale or requirements ever genuinely need more (sub-minute SLAs, retry
semantics, work that must survive a crash mid-job), that's the point to
introduce one — not before.

## Setting it up

**Windows (Task Scheduler)** — one Basic Task per script above, Action =
"Start a program":
```
Program:   C:\xampp\php\php.exe
Arguments: "C:\xampp\htdocs\tms\scripts\check_alerts.php"
```
Repeat the same shape for `check_wallet_expiry.php` with its own daily
trigger, `backup_database.php` daily, and `restore_database.php --verify`
weekly. `database/migrate.php` doesn't need a Task Scheduler entry at all —
run it by hand (`php database/migrate.php`) right after deploying new code.

**Linux / shared hosting (crontab)**:
```
*/30 * * * * php /path/to/tms/scripts/check_alerts.php        >> /path/to/tms/scripts/alerts.log 2>&1
0 8  * * *   php /path/to/tms/scripts/check_wallet_expiry.php >> /path/to/tms/scripts/wallet_expiry.log 2>&1
0 3  * * *   php /path/to/tms/scripts/backup_database.php     >> /path/to/tms/scripts/backup.log 2>&1
0 4  * * 0   php /path/to/tms/scripts/restore_database.php --verify >> /path/to/tms/scripts/restore_test.log 2>&1
```

## Safety notes

- Every script above refuses to run outside the CLI (`PHP_SAPI !== 'cli'`
  returns a 403) — none of them are reachable over HTTP, even if someone
  guesses the URL. `restore_database.php --restore` additionally requires an
  explicit `--file=...` and `--confirm` flag before it changes anything.
- `database/` and `backups/` are each blocked from direct web access by their
  own `.htaccess`, as defense in depth on top of that.
- `migrate.php`, `check_alerts.php`, `check_wallet_expiry.php` and
  `backup_database.php` are all safe to re-run: `migrate.php` tracks what's
  already applied (`tblmigrations`), both alert/reminder scripts dedupe by
  marking what they've already notified (`tbl_price_alerts`/
  `tbl_availability_alerts` flip inactive once fired; wallet documents track
  `ExpiryNotifiedAt`), and `backup_database.php` just writes a new
  timestamped file each time — so running one twice, or catching up after
  downtime, never double-notifies a user or corrupts anything.
- If a run fails partway (e.g. the DB instability documented in
  `SESSION_SUMMARY_INDEX.md` Round 17), nothing is left half-applied in a
  way the next run can't recover from — each row is processed and marked
  independently, not as one all-or-nothing batch.

## Event outbox and workflow rules

A MySQL-backed outbox (`tbl_outbox_events`, `backend/services/EventBus.php`) gives slow or retryable work a durable queue
without a message broker. Producers call `EventBus::publish($dbh, 'BookingExpired', 'booking', $id, $payload, $idempotencyKey)`
after their state change (it never throws and does nothing if the tables are not migrated). `scripts/run_jobs.php` claims
batches atomically, runs the registered consumers, retries failures with exponential backoff (30 s, 1 min, 2 min … max 1 h) and
dead-letters an event after `max_attempts`. Each (event, consumer) success is recorded in `tbl_outbox_deliveries`, so a retry
or replay never repeats a consumer that already succeeded. A worker that crashes mid-event is recovered after 10 minutes.

```
php scripts/run_jobs.php --once --rules      # cron / Task Scheduler every 1-5 min
php scripts/run_jobs.php --stats             # counts per status
php scripts/run_jobs.php --replay=123        # re-queue a dead event
php scripts/run_jobs.php --rules --dry-run   # evaluate rules, act on nothing
```

Currently published: `BookingExpired` (from `expire_pending_bookings.php --apply`); the built-in `NotificationConsumer`
turns it (and `BookingCancelled`/`BookingConfirmed`, when published) into the customer's in-app notification. Not yet
published by the main booking/payment code paths.

**Workflow rules** (`tbl_workflow_rules`, `backend/services/RulesEngine.php`): a rule picks one predefined trigger
(`overdue_refund`, `incomplete_travel_details`, `supplier_sync_failure`, `repeated_failed_job`, `booking_status_change`) and one
predefined action (`notify_admin`, `notify_customer`, `notify_supplier`, `escalate`) with a few validated parameters and an
optional text template. There is no shell/SQL/PHP in rules. Rules start disabled; each (rule, subject, window) acts at most once;
failed runs retry up to the rule's `max_attempts`; every evaluation leaves a row in `tbl_workflow_runs`. Admin/supplier
notices are written to `tbl_system_events` (category `workflow`) because there is no admin or supplier inbox yet.
Manage rules (create from predefined options, dry run, enable/disable, run history) at `admin/workflow-rules.php` (super-admin).

**Fraud / anomaly review** (`scripts/scan_fraud_signals.php`, migration 022, `admin/fraud-cases.php`): daily. Dry run by default;
`--apply` opens review cases for five transparent signals (repeated payment attempts, duplicate bookings, coupon/referral clusters,
unusual loyalty credits, abnormal cancellation rates), idempotent per signal + subject + day. Cases carry the exact evidence and a
0-100 priority that is only a queue order, never proof. Nothing is blocked or changed automatically; humans record decisions and
answer customer appeals (`FraudCases::fileAppeal()`; no customer-facing appeal page yet).

Test (needs migrations applied): `php tests/integration/eventbus_test.php`.
