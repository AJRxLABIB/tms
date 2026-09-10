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
| `scripts/check_wallet_expiry.php` | Daily | Notifies travelers at 30-day and 7-day windows before a Travel Wallet document expires (Rule 28). |

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
trigger. `database/migrate.php` doesn't need a Task Scheduler entry at all —
run it by hand (`php database/migrate.php`) right after deploying new code.

**Linux / shared hosting (crontab)**:
```
*/30 * * * * php /path/to/tms/scripts/check_alerts.php        >> /path/to/tms/scripts/alerts.log 2>&1
0 8  * * *   php /path/to/tms/scripts/check_wallet_expiry.php >> /path/to/tms/scripts/wallet_expiry.log 2>&1
```

## Safety notes

- All three scripts refuse to run outside the CLI (`PHP_SAPI !== 'cli'`
  returns a 403) — none of them are reachable over HTTP, even if someone
  guesses the URL.
- `database/` itself is blocked from direct web access by `database/.htaccess`
  as defense in depth on top of that.
- All three are safe to re-run: `migrate.php` tracks what's already applied
  (`tblmigrations`), and both alert/reminder scripts dedupe by marking what
  they've already notified (`tbl_price_alerts`/`tbl_availability_alerts`
  flip inactive once fired; wallet documents track `ExpiryNotifiedAt`) — so
  running one twice, or catching up after downtime, never double-notifies
  a user.
- If a run fails partway (e.g. the DB instability documented in
  `SESSION_SUMMARY_INDEX.md` Round 17), nothing is left half-applied in a
  way the next run can't recover from — each row is processed and marked
  independently, not as one all-or-nothing batch.
