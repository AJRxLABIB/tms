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
| `tblusers` / `admin` | Accounts. `admin.Role` (Round 20 — granular RBAC, spec item 46): superadmin \| booking_manager \| package_manager \| finance \| support \| content_manager \| ai_manager \| operations, plus legacy `manager` (broad access, kept working for admins created before this round but not offered for new ones). Enforced per-page via `tms_admin_require_role()`/`tms_admin_can()` in `includes/config.php` — see `admin/manage-admins.php`'s docblock for the full per-role page map. `tblusers` gained `NotificationsEnabled` / `PersonalizationEnabled` / `DeletedAt` (Round 20 — privacy controls, spec item 48; see `privacy.php`) |
| `tblcoupons` | Discount codes |
| `tblloyalty` / `tblloyalty_log` | Points balance + history |
| `tblwishlist` | Saved packages per user |
| `tblactivity_log` | Admin/system audit trail (Actor, Action, Details, IP, timestamp) — this is this project's audit log (Rule 47); no separate `audit_logs` table was added since this already covers the same need |
| `tbl_ai_*`, `tbl_copilot_sessions`, `tbl_explore_*` | AI feature state/cache — see `AI_ARCHITECTURE.md` |
| `tblmigrations` (new) | Tracks which `database/migrations/*.sql` files have run |
| `tblhotels` / `tblactivities` (new, Round 37) | Sample-data catalogs for the hotels & activities marketplace, separate from `tbltourpackages`. Every row has `IsSampleData tinyint(1) DEFAULT 1` and a `Status` column (1=active); seeded from `data/hotels_seed.json` / `data/activities_seed.json` (16 rows each, invented generic names — no real hotel brands) |
| `tblhotel_bookings` / `tblactivity_bookings` (new, Round 37) | One row per hotel/activity booking per traveler. Deliberately separate tables rather than extending `tblbooking` (which hardcodes a single `PackageId` FK) — see the schema-debt note below. Same `status` convention as `tblbooking` (0=Pending, 1=Confirmed, 2=Cancelled); 50 loyalty points per confirmed booking (half of `tblbooking`'s 100, reflecting smaller sample transaction value). Included in `tms_privacy_tables()` |

## Known schema debt (real, not hidden)

- `tblbooking.TotalCost` and `tbltourpackages.PackagePrice` are `float`/
  decimal-as-PHP-float rather than integer minor units. The new payment
  ledger (`tblpayments.AmountMinor`) IS a proper integer; migrating the
  older, widely-read columns is real follow-up work (touches ~15 files),
  not done in this pass to avoid a risky drive-by schema change.
- **Hotels & Activities marketplace is sample data, by explicit decision
  (Round 37, items 12/13):** a real hotel/activities marketplace needs real
  supplier data (names, live prices, live availability) this project has no
  access to, and Rule 3 forbids inventing that as if it were real. Given the
  choice between skipping the feature entirely or building the structure now
  with clearly-labeled sample/demo data, the user chose the latter — build
  it now, swap in a real supplier feed later. Every hotel/activity listing
  carries a persistent "Sample Listing" badge (card + detail page) and every
  browse/detail page shows a banner disclosing this is demo data; the
  `IsSampleData` DB column exists so a future real-data import can
  distinguish rows instead of a risky mass-delete. No transport tables were
  added this round — out of scope for this pass.
- **Admin-side marketplace booking management (Round 41):** built as
  `admin/manage-marketplace-bookings.php` — was deliberately deferred out
  of Round 37 to keep that round's scope bounded (Rule 73), then built as
  its own round. RBAC-gated to the same roles as `manage-bookings.php`
  (`booking_manager`/`operations`/`manager`); lists both
  `tblhotel_bookings` and `tblactivity_bookings` in one normalized,
  filterable, paginated table (search/status/type filters); supports
  Confirm/Cancel/Delete (no payment gateway exists for this feature, so
  no refund workflow — matches the sample-data nature of the underlying
  catalog); notifies the booking owner in-app and by email on
  confirm/cancel via the same `tms_notify()`/`tms_send_email()` pattern
  `manage-bookings.php` uses. Linked from the sidebar under the same
  Bookings & Users section.
- `tblhotel_bookings`/`tblactivity_bookings` were added as fully separate
  tables rather than generalizing `tblbooking` to a polymorphic
  product-type column, because `tblbooking` hardcodes a single `PackageId`
  FK with no product-type discriminator — retrofitting that is a large,
  risky schema change (Rule 8) for a benefit this round didn't need.
- Charset is a mix of `latin1` and `utf8` across tables from different
  migration eras — works today, but a full-`utf8mb4` pass would remove a
  class of "emoji/rare-script breaks a column" bugs if this ever needs it.
- `tbl_ai_analytics` (a `metric_key`/`metric_value` cache table with a
  UNIQUE key per metric) has existed since the earliest AI rounds but
  nothing has ever written to it — found while building Round 34's AI
  telemetry (below), which needed a real per-call event log instead (a
  different shape than a single-row-per-metric cache), so this dead table
  was left alone rather than silently repurposed. Genuinely unused; a
  candidate to drop in a future pass, not done here (Rule 8 — no casual
  schema changes outside a reviewed migration).
- **AI Provider Abstraction (Round 34, item 21):** `includes/ai/AIClient.php`
  no longer talks to Google Gemini directly -- it delegates to a small
  `AIProviderInterface` implementation (`includes/ai/providers/`), selected
  by the `AI_PROVIDER` env var (default `gemini`, unchanged behavior for any
  existing deployment). A second `OpenAIProvider` implementation exists but
  has NOT been exercised against a real OpenAI account in this environment
  (no key configured here, and this session's web access was blocked when
  trying to re-verify the current API shape) -- verify against
  platform.openai.com's docs before relying on it. Every AI call is now also
  logged to `tbl_ai_telemetry` (provider, model, feature, success, cached,
  latency, tokens where reported, error) -- see `admin/ai-dashboard.php`'s
  "AI Telemetry by Feature" panel. Included in both `tms_privacy_tables()`
  (anonymized on account deletion / included in data export) and
  `privacy.php`'s "Clear AI History" table list.
- **No travel-month / seasonal-availability column on `tbltourpackages`**
  (Round 36, item 30): packages are evergreen inventory -- there's no
  per-package "best month to visit" or date-range field anywhere in the
  schema. `includes/ai/NLSearchParser.php`'s structured search deliberately
  does NOT filter by a mentioned month for this reason (Rule 3 -- faking
  it would mean inventing seasonality data), and surfaces an honest note
  to the visitor instead. `PackageDuration` is also worth knowing about
  here: it's free text ("3-4 Days", "Half Day"), not a numeric/range
  column, so duration filtering is done via a `[min,max]`-overlap parse of
  that text (`NLSearchParser::durationOverlaps()`) rather than SQL.

## Running migrations

```
php database/migrate.php            # apply pending migrations
php database/migrate.php --status   # show what's pending, changes nothing
```
