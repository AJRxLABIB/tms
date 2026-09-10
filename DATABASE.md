# Database

MySQL/MariaDB, `latin1`/`utf8` mixed charset (inherited — not changed here,
since a charset migration touches every table and is its own risky project).

## Schema history (how it got here)

Applied in this order, historically by hand in phpMyAdmin:
1. `tms.sql` — base schema + seed data (`admin`, `tblbooking`, `tblenquiry`,
   `tblissues`, `tblpages`, `tbltourpackages`, `tblusers`)
2. `v6_migration.sql` — `tblactivity_log`, `tblblog`, `tblcoupons`,
   `tblloyalty`, `tblloyalty_log`, `tblwishlist`
3. `AI_MIGRATION.sql` — `tbl_ai_*`, `tbl_copilot_sessions`,
   `tbl_review_sentiment`, `tbl_safety_alerts`, `tbl_travel_memories`,
   `tbl_user_profile`
4. `explore_migration.sql` — `tbl_explore_*`
5. `schema_repair_migration.sql` — assorted column additions/fixes to
   already-shipped tables (e.g. `tblbooking.NoOfPersons/TotalCost/
   VerificationCode/BookingDate`, added incrementally as features needed
   them but not tracked anywhere until now)

**As of this round**, schema changes go through `database/migrations/`
instead — see `database/migrate.php` and its own docblock. The 5 files
above are recorded as an already-applied baseline the first time that
runner touches a database, so they are never re-run.

## Core tables (selected — see the .sql files for full column lists)

| Table | Purpose |
|---|---|
| `tbltourpackages` | The product catalog — packages, price, location, capacity (`SeatsAvailable`, nullable = uncapped) |
| `tblbooking` | One row per booked package per traveler group. `status`: 0=Pending, 1=Confirmed, 2=Cancelled. `PaymentStatus` (new): unpaid\|pending\|paid\|failed\|refunded\|partially_refunded |
| `tblpayments` / `tblpayment_events` / `tblpayment_refunds` | New (this round) — the payment ledger. See `PAYMENTS.md` |
| `tblusers` / `admin` | Accounts. `admin.Role`: superadmin \| manager |
| `tblcoupons` | Discount codes |
| `tblloyalty` / `tblloyalty_log` | Points balance + history |
| `tblwishlist` | Saved packages per user |
| `tblactivity_log` | Admin/system audit trail (Actor, Action, Details, IP, timestamp) — this is this project's audit log (Rule 47); no separate `audit_logs` table was added since this already covers the same need |
| `tbl_ai_*`, `tbl_copilot_sessions`, `tbl_explore_*` | AI feature state/cache — see `AI_ARCHITECTURE.md` |
| `tblmigrations` (new) | Tracks which `database/migrations/*.sql` files have run |

## Known schema debt (real, not hidden)

- `tblbooking.TotalCost` and `tbltourpackages.PackagePrice` are `float`/
  decimal-as-PHP-float rather than integer minor units. The new payment
  ledger (`tblpayments.AmountMinor`) IS a proper integer; migrating the
  older, widely-read columns is real follow-up work (touches ~15 files),
  not done in this pass to avoid a risky drive-by schema change.
- No hotel/activity/transport tables exist separately from
  `tbltourpackages` — packages are still the only bookable product. Adding
  a real hotel/activities/transport marketplace needs real supplier data
  (Rule 3 forbids inventing hotel names/prices/availability), which this
  project doesn't have access to; not built this round for that reason.
- Charset is a mix of `latin1` and `utf8` across tables from different
  migration eras — works today, but a full-`utf8mb4` pass would remove a
  class of "emoji/rare-script breaks a column" bugs if this ever needs it.

## Running migrations

```
php database/migrate.php            # apply pending migrations
php database/migrate.php --status   # show what's pending, changes nothing
```
