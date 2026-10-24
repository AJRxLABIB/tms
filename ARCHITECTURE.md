# Architecture

A top-level map for a new engineer joining this project. This is a plain
PHP 8 / MySQL monolith (no framework, Bootstrap on the frontend) that has
been incrementally restructured and hardened across 80+ documented work
rounds (see `CHANGELOG.md` and `SESSION_SUMMARY_INDEX-1.md`/
`SESSION_SUMMARY_INDEX.md` for the full history). It links to and
summarizes the other root-level docs rather than repeating them — read
those for the details of their own domain.

## Directory structure

```
tms/
├── frontend/     Real implementation of every customer-facing page
│                 (hotels.php, checkout.php, dashboard.php, ...). 71 files.
├── backend/      Shared PHP logic used by frontend/ and admin/: config.php
│                 (bootstrap/session/RBAC/schema), payments/, ai/,
│                 repositories/, services/ (see "Repositories & Services"
│                 below),
│                 phpmailer/, webhooks/, caching, pricing, i18n, mailer,
│                 chatbot, header/footer templates, etc.
├── admin/        The admin panel. Has its OWN small includes/ wrapper
│                 (admin/includes/: config.php, header.php, footer.php,
│                 sidebarmenu.php) — distinct from the project's backend/.
├── _deprecated/  Holds includes/ (Round 115) — the project's original
│                 shared-logic folder, superseded by backend/ at the
│                 Round 49 rename. Moved here (not deleted) once a
│                 project-wide grep confirmed zero live code paths still
│                 reached it — see "The backend/ vs includes/ pair" below.
│                 Safe to delete outright after a release cycle with no
│                 issues traced back to it; kept for now as a rollback net.
├── database/     database/migrate.php (CLI + browser-runnable, superadmin-
│                 gated) + database/migrations/*.sql — the auditable
│                 migration history. See DATABASE.md.
├── api/          JSON API endpoints: index.php (REST v1, also aliased at
│                 api/v1/index.php), ai.php, chat.php, explore.php,
│                 docs.php (Swagger UI), openapi.yaml. See
│                 API_DOCUMENTATION.md.
├── data/         Seed data for sample/demo catalogs (hotels_seed.json,
│                 activities_seed.json, packages_seed.json) and the
│                 generator script that built them.
├── scripts/      Ops scripts: backup_database.php, restore_database.php,
│                 check_alerts.php, check_wallet_expiry.php. See
│                 BACKUPS.md / SCHEDULED_JOBS.md.
├── tests/        tests/run.php (dependency-free test runner — no
│                 PHPUnit) + tests/manual/ (one-off QA/session-simulation
│                 helper scripts, never linked from the live app). See
│                 TESTING.md.
├── css/ js/ fonts/ images/   Static assets, kept at the project root
│                 (not nested under frontend/) so the huge majority of
│                 asset references never had to change during the
│                 restructure.
└── *.php         Root-level "shim" files (e.g. hotels.php) — thin
                  pass-throughs to frontend/. See "The root-shim pattern"
                  below. A handful of root files are not shims: .env,
                  .htaccess, the *.md docs, and top-level scripts like
                  sitemap.php/robots.txt/manifest.json/sw.js, which
                  reference URLs rather than files and so were untouched
                  by the restructure.
```

This layout is the end state of two restructuring rounds (Round 49 and
Round 50 in `SESSION_SUMMARY_INDEX-1.md`) applied to what started as a
flat, single-directory PHP project. `README.md` has a shorter version of
this same diagram.

## The root-shim pattern

Every customer-facing page's *real* code lives under `frontend/`, but its
*public URL* is still the root-level file it always was. The root file is
now a 4-line pass-through, e.g. `hotels.php`:

```php
<?php
// This page's real implementation now lives in frontend/hotels.php.
// Kept here as a thin pass-through so the public URL (/hotels.php), and every
// asset reference, redirect, fetch() call, sitemap entry, and robots.txt
// rule that already points at it, all keep working unchanged (Rule 2:
// never break a working feature). See SESSION_SUMMARY_INDEX-1.md, Round 50.
require __DIR__ . '/frontend/hotels.php';
```

**Why not just move the file and update every reference?** Because the
file's *path* is also the site's *public URL*. Rewriting that would have
meant touching 23+ `header('Location: ...')` redirects, `fetch()` calls,
`sitemap.php`'s URL list, `robots.txt`'s `Disallow` rules, `manifest.json`,
and `sw.js` — all at once, with real regression risk if any one of them
was missed. Keeping the root file in place (as a shim) means none of those
had to change: browsers and PHP `header()` redirects resolve relative URLs
and hrefs against the *requested URL*, not the file's location on disk.

**Why direct access to `frontend/*.php` returns a 500 (this matters for
anyone testing locally or writing a script against these files):** the
moved files use *bare relative* `include`/`require` calls —
`include('backend/config.php')`, `include('backend/header.php')` — not
`__DIR__`-based absolute paths. A bare relative include resolves against
PHP's/the web server's current working directory for the *requested*
script, which for a normal web request is the directory of the file the
browser actually asked for. When a page is reached the intended way
(`GET /hotels.php`), the requested script is the root shim, so the
working directory is the project root, and `backend/config.php` resolves
correctly from inside `frontend/hotels.php` even though that file is one
directory down. Request `frontend/hotels.php` directly, though, and the
working directory becomes `frontend/`, so `backend/config.php` — actually
at the project root — is not found there, and PHP fatals with a missing
file (fatal errors trigger the shutdown handler in `backend/config.php`,
which normally renders a "Something went wrong" page — but since
`backend/config.php` itself is exactly what failed to load in this
scenario, that handler was never registered either, so the visible result
is a raw 500/blank page, not the friendly error screen). This was
deliberately verified in a real PHP sandbox before the Round 50 move (not
just asserted) — see `SESSION_SUMMARY_INDEX-1.md`, Round 50.

One consequence worth knowing: a few `frontend/*.php` files use `__DIR__`
directly (for real filesystem paths, e.g. locating `.env` or a wallet
document) rather than a bare relative include. Because `__DIR__` is
intrinsically tied to *that file's own location on disk* (not the
requested script), those had to be rewritten to `dirname(__DIR__)` when
the files moved one level down into `frontend/` — done for all 9 real
cases found (`ai-recommend.php`, `expense-tracker.php`, `explore.php`,
`health.php`, `loyalty.php`, `my-trip.php`, `package-list.php`,
`transport-compare.php`, `wallet-document.php`) in the same Round 50 move.

## The `backend/` vs `includes/` pair — RESOLVED, Round 115

`includes/` was the project's original shared-logic folder. Round 49
renamed it to `backend/`; the old folder couldn't be deleted at the time
(no shell/file-delete access in that session) so it was left in place.
For a long stretch afterward it genuinely was NOT fully dead: two live
bugs of the same shape kept `includes/` load-bearing —

1. `backend/landing.php` (the signed-out homepage) had bare
   `include('includes/header.php')`/`include('includes/footer.php')`
   calls left over from before the Round 49 rename, which — because
   `frontend/index.php` reaches it through the root shim (working
   directory = project root) — resolved to the real, on-disk root
   `includes/header.php`/`includes/footer.php`, not `backend/`'s copies.
   **This was fixed some time before Round 115** (`backend/landing.php`
   now calls `include('backend/header.php')`/`include('backend/footer.php')`
   directly — confirmed by reading the file's own current content, which
   carries an inline comment documenting the fix).
2. `backend/footer.php` — included by literally every `frontend/*.php`
   page, ~60 files — had its own bare `include('includes/chatbot.php')`,
   the same class of leftover, still live as of the Round 115 audit. Since
   `backend/footer.php` is reached the same way (root shim → project-root
   working directory), this meant **every single page on the site** was
   serving the chat widget from the stale root `includes/chatbot.php`
   rather than its own sibling `backend/chatbot.php`. The two files were
   still byte-identical at the time, so nothing was visibly broken, but a
   future edit to `backend/chatbot.php` alone would never have reached a
   real visitor. **Fixed in Round 115**: `backend/footer.php` now calls
   `include('backend/chatbot.php')`.

**With both of those fixed, Round 115 ran a comprehensive, whole-project
grep for every remaining `include`/`require` (bare, `_once`, with or
without parens, plus `__DIR__`-relative forms) referencing a path
starting with `includes/`, excluding `admin/*.php` (whose own bare
`include('includes/header.php')`/etc. calls resolve to `admin/includes/`,
a separate, legitimate, always-correct wrapper — see the next section —
since admin pages are requested directly and their working directory is
`admin/`, not the project root). The result: zero remaining live
references anywhere in the codebase.** Root `includes/` was therefore
moved (not deleted) to `_deprecated/includes/` in Round 115, exactly as
this document's own prior "don't assume includes/ is dead weight" caution
recommended checking before assuming — it no longer applies, because this
was actually re-verified this round rather than assumed.

**What's preserved in `_deprecated/includes/` for reference:** the
folder's 56 files are untouched, in case any of this reasoning missed an
edge case. Two real, pre-existing content differences from their
`backend/` counterparts are worth knowing if anyone ever does dig through
it: `includes/config.php` lacks a Round 51 `tblbooking` fallback-schema
fix that `backend/config.php` has, and `includes/payments/PaymentService.php`
(253 lines) predates the Round 56 `handleWebhook()` shared entry point
that `backend/payments/PaymentService.php` (377 lines) has — neither
matters anymore since nothing references the `includes/` copies, but
they're recorded here rather than silently dropped from the history.

**Recommendation:** safe to delete `_deprecated/includes/` outright after
one full release cycle with no issues traced back to it. Not deleted
outright in Round 115 itself, per this engagement's standing preference
for reversible steps over irreversible ones when the two cost about the
same.

## Admin's own `includes/` wrapper — a second, unrelated `includes/`

`admin/includes/` (`config.php`, `header.php`, `footer.php`,
`sidebarmenu.php`) is a *separate*, admin-only concept, not to be confused
with the project-root `includes/`/`backend/` pair above. Inside
`admin/*.php` files:
- a **bare** `include('includes/config.php')` resolves to
  `admin/includes/config.php` (the working directory is `admin/`, since
  admin pages are requested directly, with no shim layer);
- a `require_once(__DIR__ . '/../backend/payments/...')` resolves to the
  project-root `backend/` folder.

Both patterns appear side by side in the same file (e.g.
`admin/manage-bookings.php`), so this is intentional, not accidental
inconsistency — verified file-by-file during the Round 49 rename rather
than handled with a single blanket find-and-replace, which would have
broken the admin wrapper.

## Self-healing schema pattern

Most schema changes in this project go through two complementary
mechanisms rather than one:

1. **`tms_ensure_columns($dbh, $table, $colDefs)`** (defined in
   `backend/config.php`, mirrored in `includes/config.php` — see above).
   Given a table name and an associative array of `columnName => "column
   definition SQL"`, it does one `information_schema.COLUMNS` lookup for
   that table, then issues `ALTER TABLE ... ADD COLUMN ...` only for
   columns that don't already exist (failures are logged, never silently
   swallowed). This is what lets a page like `frontend/hotels.php` add a
   column it needs (e.g. a new booking field) without a separate deploy
   step — the very next request that loads `config.php` repairs the
   schema if it's behind.
2. **Bulk `CREATE TABLE IF NOT EXISTS` migrations**, grouped into a
   handful of functions (`_tms_run_core_migrations()`,
   `_tms_run_ai_migrations()`, `_tms_run_explore_migrations()`,
   `_tms_run_api_migrations()`, `_tms_run_marketplace_migrations()`),
   each gated to run once per session via a `$_SESSION['tms_*_tables']`
   flag (e.g. `$_SESSION['tms_db_init']`) — a performance optimization
   only, since `CREATE TABLE IF NOT EXISTS` is itself idempotent. A
   separate, NOT session-gated function, `_tms_repair_schema_drift()`,
   runs `tms_ensure_columns()` calls against tables that pre-date this
   pattern (`tbltourpackages`, `tblusers`, etc.) on every single request —
   deliberately not session-gated, because an existing session cookie
   from before a repair was added would otherwise never see it applied.

Both mechanisms run in `backend/config.php` on every request (module
load), so a fresh checkout of the code plus an old/empty database will
self-repair to the current expected schema simply by being visited.

**This runs alongside, not instead of, `database/migrations/*.sql` +
`database/migrate.php`** (see `DATABASE.md`), which is the auditable,
one-time-applied history — tracked in a `tblmigrations` table, runnable
via CLI (`php database/migrate.php`) or browser (superadmin-gated). The
project's own documentation is explicit that both mechanisms agreeing is
intentional: the ad hoc `ALTER TABLE ADD COLUMN` in `config.php` is itself
guarded by the same existing-column check `tms_ensure_columns()` always
does, so re-declaring the same column in a numbered migration file is
safe, not a conflict.

The tradeoff (documented directly in `backend/config.php`'s comments): a
`CREATE TABLE IF NOT EXISTS` is a silent no-op if a table with that name
already exists from an older install — even if the older table is missing
columns the new `CREATE TABLE` string declares. This is exactly the class
of bug the July 2026 audit found (`_tms_repair_schema_drift()`'s own
docblock) and again the class of bug Round 51's from-scratch
disaster-recovery test found in the `tblbooking` fallback (see above) —
both fixed the same way, by explicitly listing the missing columns
through `tms_ensure_columns()` rather than trusting the `CREATE TABLE`
string.

## Key domain concepts

- **Bookings** (`tblbooking`) — one row per booked package per traveler
  group; `status` 0=Pending/1=Confirmed/2=Cancelled; `PaymentStatus`
  (unpaid/pending/paid/failed/refunded/partially_refunded). Separate,
  parallel tables (`tblhotel_bookings`, `tblactivity_bookings`) exist for
  the hotel/activities marketplace rather than generalizing `tblbooking`
  (which hardcodes a single `PackageId` FK) — see `DATABASE.md`'s "Known
  schema debt" section for why.
- **Packages** (`tbltourpackages`) — the core product catalog: price,
  location, capacity (`SeatsAvailable`, nullable = uncapped), plus
  columns added incrementally across rounds (`AvgRating`, `Country`,
  `Region`, `IsFeatured`, etc. — repaired into pre-existing installs by
  `_tms_repair_schema_drift()`, see above).
- **Repositories & Services** (`backend/repositories/`,
  `backend/services/`) — the incrementally-adoptable pattern this project
  uses instead of a big-bang MVC rewrite (Rule 1/73): a class is built
  once, fully tested, and wired into exactly one real call site as a
  demonstration, with every other call site left on its existing inline
  SQL to adopt later. Four real examples exist now, in build order:
    1. `BookingRepository` — query layer for `tblbooking`, wired into
       `frontend/tour-history.php`.
    2. `BookingStateMachine` — validates transitions across `status`/
       `PaymentStatus`/`RefundStatus`/`ModificationStatus` (pure,
       stateless, no DB access), wired into `admin/manage-bookings.php`'s
       `confirm` (blocks resurrecting a cancelled booking) and
       `refund_processed`/`refund_rejected` actions (blocks acting on a
       refund that was never requested) — live-verified: confirming an
       already-cancelled booking now returns "Booking #BK3 is cancelled
       and can no longer be confirmed." instead of silently flipping its
       status.
    3. `TripReadinessScore` — a pure `computeScore()` (no DB) plus a thin
       `forBooking()` DB-gathering wrapper, wired into
       `frontend/my-trip.php` as a progress bar + checklist.
    4. `BudgetAutopilot` — a pure `shouldNotify()` decision function (no
       DB), wired into the new `scripts/check_budget_overspend.php` cron
       script alongside the pre-existing `ExpenseAnalyst`.
  None of these changed any existing call site's behavior for something
  the app already did — each is either a brand-new capability or a guard
  added at exactly one call site that had none before.

  **Adoption guideline (TMS V2 prompt Phase 3, decided and closed —
  not an open discussion):** new business logic gets a `services/` or
  `repositories/` class when it has real rules to enforce (a state
  machine, a scoring/decision function) or is reused across more than
  one call site; simple single-site CRUD stays as inline SQL, matching
  the rest of the codebase. Existing working inline SQL is **never**
  migrated proactively just to "modernize" it — only opportunistically,
  if a file is already being touched for another reason. There is no
  planned big-bang move of the 71 `frontend/` files into an
  `app/Controllers/Services/Repositories/Models` tree; the four examples
  above are the intended shape of *new* work going forward, not a
  migration target for old work.
- **Payments** (`backend/payments/`) — `PaymentProviderInterface`
  (`name()`, `mode()`, `initiate()`, `verifyWebhook()`,
  `normalizeStatus()`) implemented by `SandboxProvider` (real, working,
  no real money — today's active default), `SslcommerzProvider`,
  `BkashProvider`, `NagadProvider` (real shape, throw until real merchant
  credentials exist — Rule 3/4: never fabricate a real integration).
  `PaymentService` is the single call site the rest of the app talks to;
  it also owns the shared webhook entry point
  (`PaymentService::handleWebhook()`, Round 56) that
  `backend/webhooks/{bkash,nagad,sslcommerz}.php` each thinly dispatch
  into. Full detail in `PAYMENTS.md` — note that file still describes the
  provider classes as living under `includes/payments/`, a path that
  predates the Round 49 rename to `backend/payments/`; the code itself
  is at `backend/payments/`.
- **AI features** (`backend/ai/`) — `AIClient.php` is the shared base
  (retry/backoff, rate limiting, input sanitization, caching, telemetry
  to `tbl_ai_telemetry`); it delegates vendor-specific request-building
  and response-parsing to a small `AIProviderInterface`
  (`backend/ai/providers/`: `GeminiProvider` default, `OpenAIProvider`
  alternative, selected by the `AI_PROVIDER` env var). Feature classes
  built on top of `AIClient` include `TravelRecommender`,
  `PackageGenerator`, `ItineraryPlanner`, `TripCopilot`, `BudgetOptimizer`,
  `SentimentAnalyser`, `FraudDetector`, `NLSearchParser`, `CrowdPredictor`,
  `ExpenseAnalyst`, `SiteAssistant`, `AIEvaluator`. Full detail (including
  the DB tables and a caveat that `OpenAIProvider` has never been
  exercised against a real OpenAI account) in `AI_ARCHITECTURE.md` — note
  that document is an early planning/design doc (paths and class names
  like `VirtualGuide`/`PackingAssistant`/`HotelAdvisor` in it don't all
  match what was actually built; the class list above reflects what
  exists in `backend/ai/` today).
- **RBAC** (`backend/config.php`) — `tms_require_admin()` gates a page to
  any logged-in admin (`$_SESSION['alogin']`). `tms_admin_role($dbh)`
  looks up the admin's role fresh from the `admin` table on every call
  (not trusted from session), failing open to `'superadmin'` on any
  lookup error so an infra hiccup never locks an admin out.
  `tms_admin_can($dbh, $allowedRoles)` / `tms_admin_require_role($dbh,
  $allowedRoles)` enforce one of 8 granular roles (`superadmin`,
  `booking_manager`, `package_manager`, `finance`, `support`,
  `content_manager`, `ai_manager`, `operations`) plus a legacy `manager`
  role kept working for pre-existing admins but not offered for new ones;
  `superadmin` always passes every check regardless of what's listed.
  `tms_admin_require_role()` shows a real 403 page (not a blank screen or
  silent redirect) naming the admin's actual role. See `DATABASE.md`'s
  `tblusers`/`admin` row and `SECURITY.md` for more.

## Request flow for a typical page

1. Browser requests a root-level URL, e.g. `GET /hotels.php`.
2. The root shim (`hotels.php`) runs, `require`ing `frontend/hotels.php`.
   Because the *requested* script is the root shim, the working directory
   used for every bare relative include inside `frontend/hotels.php` is
   the project root — see "The root-shim pattern" above.
3. `frontend/hotels.php` starts with `session_start()`, sets error
   reporting, then `include('backend/config.php')` — the shared
   bootstrap:
   - loads `.env` (`env-loader.php`), defines AI/payment/DB constants,
   - opens the PDO connection (persistent, `ERRMODE_EXCEPTION`),
   - registers a shutdown handler that turns any uncaught fatal into a
     friendly HTML error page (unless the response is already JSON),
   - starts/configures the session (secure cookie flags detected from the
     request, not hardcoded),
   - runs the self-healing migration functions described above (session-
     gated) plus `_tms_repair_schema_drift()` (not session-gated),
   - `require_once`s `log-activity.php`, `system-events.php`, `i18n.php`.
4. Page-specific PHP logic runs (queries, form handling, etc.).
5. The page includes shared chrome — `include('backend/header.php')` /
   `include('backend/footer.php')` (the signed-out homepage,
   `backend/landing.php`, uses these same two directly as of Round 115 —
   see "The backend/ vs includes/ pair" for the fix history) — plus, on
   many pages, `write-us.php` and `signin.php` for the shared contact form
   and sign-in modal.

Admin pages follow the same shape but are requested directly (no shim
layer) and bootstrap via `admin/includes/config.php`, which itself climbs
back up to the project's real `backend/config.php`.

## Where to go for more detail

This document is the map; each of these is the authority for its own
area — read them directly rather than expecting this file to repeat them:

- **`DATABASE.md`** — full schema history, core tables, known schema debt.
- **`PAYMENTS.md`** — payment provider architecture and status (see the
  path caveat above — the code has since moved to `backend/payments/`).
- **`AI_ARCHITECTURE.md`** — AI feature design (an early planning
  document; cross-check against `backend/ai/` for what actually shipped).
- **`SECURITY.md`** — current security posture, what's verified vs.
  attributed to a specific past audit round.
- **`API_DOCUMENTATION.md`** — the JSON REST API (`api/index.php` /
  `api/v1/`), authentication, endpoints, rate limiting.
- **`BACKUPS.md`** / **`SCHEDULED_JOBS.md`** — backup/restore and cron-job
  scripts under `scripts/`.
- **`TESTING.md`** — what `tests/run.php` actually covers.
- **`ENVIRONMENT.md`** — every `.env` variable and what happens when it's
  left blank.
- **`CHANGELOG.md`** — condensed, chronological history of every round
  this project's work has been organized into, distilled from
  `SESSION_SUMMARY_INDEX-1.md`/`SESSION_SUMMARY_INDEX.md`.
