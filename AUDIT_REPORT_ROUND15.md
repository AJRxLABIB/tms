# AUDIT REPORT — ROUND 15

**Trigger:** user request — a 40-phase master prompt for transforming TMS into
"TMS V1 — Intelligent Travel Management Platform." This document is the
deliverable for **Phase 1 only** ("COMPLETE PROJECT AUDIT — do not make
changes during this audit"). Phases 2–40 are implementation phases and are
explicitly **not started** by this document; they require the user's
go-ahead, phase by phase, per the master prompt's own "IMPLEMENTATION RULES."

**Method:** read-only investigation. No file was created, edited, moved, or
deleted while producing this report (other than temporary diagnostic files
from the immediately preceding round, which were already cleaned up before
this audit began). Every claim below is either (a) read directly from the
project's own code/docs this session, (b) verified with a targeted grep or
`php -l`/size check this session, or (c) explicitly labeled as carried
forward from an earlier round's documentation and not independently
re-verified this pass. Nothing here is invented — where evidence was
inconclusive, that is stated rather than guessed (Rule 3, Rule 4).

**How to read this report:** for each of the user's 40 requested phases, the
roadmap in Section J states plainly whether it is (1) already substantially
or fully built — cite the evidence, (2) partially built — what exists vs.
what's missing, or (3) not built. Per the master prompt's own instruction —
"Do not add features merely to increase feature count... reuse existing
functionality whenever possible" — this report leans hard on documenting
what already works so implementation phases don't re-build it.

---

## CORRECTIONS LOG (added during Round 115 continuation implementation passes)

This document was written as a read-only, single-pass audit and, like any
such snapshot, contained a few claims that later implementation passes
found to be stale or mistaken once verified directly against the live
code/database (Rule 4: verify before asserting). Recorded here rather than
silently edited into the sections below, so the audit's own investigation
trail stays intact. See `CHANGELOG.md` for full detail on each.

- **Phase 13 ("Why this recommendation?" explanations) — already built,
  not NOT BUILT.** `frontend/ai-recommend.php` and
  `frontend/package-details.php`'s match-score widget both already render
  real per-recommendation explanations.
- **Phase 14 (AI feedback loop) — already built.** `tmsSendAiFeedback()`
  is wired into `my-trip.php` and `ai-recommend.php`.
- **Phase 15 (AI Quality Dashboard) — already built, not PARTIAL.**
  `admin/ai-dashboard.php` is a real, 1,059-line dashboard already doing
  everything this section says doesn't exist yet.
- **Phase 5 / Section C, FLOAT-vs-DECIMAL money columns — stale, already
  fixed.** A live `information_schema` scan found zero FLOAT/DOUBLE
  columns anywhere in the database; money columns are already
  int/decimal, per Round 84's convention.
- **Phase 5 / Section C, mixed latin1/utf8 charset — real, now fixed.**
  Migration 015 converts the 17 remaining latin1 tables/columns to
  utf8mb4, after verifying live that the conversion is safe (see
  CHANGELOG.md for the full verification).
- **Phase 5 / Section C, "separate hotel/activity booking tables" —
  not schema debt.** `tblhotel_bookings`/`tblactivity_bookings` are a
  real, deliberate "Marketplace" feature (standalone hotel/activity
  booking, distinct from tour-package booking), not accidental
  duplication. Do not consolidate — that would break a working feature.
- **Phase 36/37 (multilingual) numbering** — this document's own phase
  numbers drift from the master prompt's flat numbered list in a few
  places (e.g. this section's "Phase 30" is master-prompt item 42,
  "Phase 38" is item 37, "Phase 37" is item 36) — not a content error,
  just a numbering mismatch to keep in mind when cross-referencing.

---

## A. PROJECT ARCHITECTURE REPORT

### A.1 Stack and shape

Plain PHP 8 + MySQL, no framework, server-rendered pages with Bootstrap +
a custom design-token CSS layer, vanilla JS (no build step, no bundler,
no npm frontend dependency). This is a deliberate, long-standing choice
(80+ documented rounds), not an oversight — the master prompt's own "CORE
RULE" (preserve existing tech stack unless a strong technical reason
exists) matches what's already the project's own stated policy.

### A.2 Directory structure (top level, as it exists today)

```
tms/
├── *.php                    ← root-level "shim" files (public URLs)
├── frontend/*.php           ← real customer-facing page code
├── backend/                 ← shared logic: config, services, AI, payments
├── includes/                ← ORIGINAL shared-logic folder (see A.4 — nuanced, not simply dead)
├── admin/                   ← admin panel (own includes/ wrapper, own auth)
├── api/                     ← REST API v1 (single-file router pattern)
├── js/, css/                ← frontend assets
├── database/                ← migrations/, seeds/, legacy-sql/, migrate.php
├── tests/                   ← manual/, run.php (see Section H)
├── docs (root *.md)         ← ARCHITECTURE.md, SECURITY.md, DATABASE.md, etc.
└── AUDIT_REPORT_ROUND2.md … ROUND14.md  ← prior audit history
```

### A.3 The root-shim pattern (public URL stability)

Every customer page's real code lives in `frontend/<name>.php`. The
root-level file of the same name (e.g. `/tour-map.php`) is a ~4-line
pass-through: `require __DIR__ . '/frontend/<name>.php';`. This was
introduced in Round 49/50 specifically so the Round 49 restructure could
move real code into `frontend/` **without changing a single public URL**.
This is why `frontend/*.php` files 500 when requested directly by URL —
they use bare relative includes (`include('backend/config.php')`, not
`__DIR__`-based) that only resolve when PHP's working directory is the
project root, which only happens when reached through the root shim. This
was re-verified live this session (Round 114) and is documented,
intentional, pre-existing behavior — not a defect to fix in Phase 3.

### A.4 The `backend/` vs `includes/` situation — corrected finding

An earlier round's notes (inherited into this session before this audit)
concluded root-level `includes/` was simply dead code "awaiting a deletion
decision." That conclusion is **not accurate today** and this audit
corrects it:

- `includes/` was the *original* shared-logic folder. Round 49 renamed it
  to `backend/` for the main restructure, but the old folder was **left in
  place** rather than deleted (no shell access at that time).
- Most of `includes/` genuinely is stale/orphaned today — e.g.
  `includes/payments/PaymentService.php` is 253 lines vs. `backend/`'s 377
  lines; nothing references the `includes/` copy. Confirmed via grep this
  session: no live code path calls into it.
- **But one genuine, currently-load-bearing exception exists**:
  `backend/landing.php` (the code behind the signed-out homepage) still
  contains un-migrated bare `include('includes/header.php')` /
  `include('includes/footer.php')` calls. These resolve to, and actively
  serve from, the **root-level** `includes/header.php` / `includes/footer.php`
  on every guest homepage visit today. This only works because those two
  files have been deliberately kept in sync with their `backend/`
  counterparts across rounds — a fragile-but-functioning arrangement, not
  a bug, but a real piece of technical debt (see Section I).
- Separately, **`includes/ai/providers`** was diffed against
  **`backend/ai/providers`** this session and found to be **byte-identical**
  — those specific files are safe duplicates, not drifted.

**Conclusion for Phase 3 planning:** root `includes/` cannot be safely
deleted wholesale. The correct, non-destructive sequence (matching the
master prompt's own Phase 3 instructions) is: (1) migrate
`backend/landing.php`'s two bare includes to point at `backend/header.php`
/ `backend/footer.php` (verify they're truly identical to the `includes/`
versions first, byte-for-byte, the same way the AI providers were
verified), (2) grep the entire project once more for any other bare
`include('includes/...)` reference to be certain none remain, (3) only
then move `includes/` to `_deprecated/` (not delete) for one release cycle,
(4) delete only after confirming no regressions. This is a small, safe,
well-scoped Phase 3 task — not the "delete now" instinct.

### A.5 Admin's own `includes/` wrapper — a different, legitimate concept

`admin/includes/` (config.php, header.php, footer.php, sidebarmenu.php) is
**not** the same thing as the root `includes/`/`backend/` pair — it's the
admin panel's own, intentional wrapper. Read in full this session:

```php
<?php
/**
 * Admin config – delegates to the single root config.
 * This eliminates the 100-line duplication that existed before.
 */
require_once dirname(__DIR__, 2) . '/backend/config.php';
```

This is a clean 6-line shim with no drift risk — it always delegates to
the one real `backend/config.php`. No action needed here.

### A.6 Self-healing schema pattern (coexists with real migrations)

`tms_ensure_columns($dbh, $table, $colDefs)` (in `backend/config.php`)
checks `information_schema.COLUMNS` and adds missing columns on relevant
requests; `_tms_repair_schema_drift()` runs on every request for
pre-migration-system tables. This runs **alongside**, not instead of, the
real `database/migrations/*.sql` + `database/migrate.php` system (tracked
via `tblmigrations`). This dual system is documented in ARCHITECTURE.md as
a deliberate belt-and-suspenders approach from before the migration system
existed — it's redundant in places but not actively harmful, and
consolidating it is a Phase 5 candidate, not urgent.

### A.7 RBAC

`tms_require_admin()`, `tms_admin_role($dbh)` (fresh DB lookup every call —
no stale-cache risk, at the cost of one extra query per admin request),
`tms_admin_can()` / `tms_admin_require_role()` enforce 8 granular roles
(superadmin, booking_manager, package_manager, finance, support,
content_manager, ai_manager, operations) plus a legacy `manager` role.
One documented design choice worth flagging for Phase 8: `tms_admin_role()`
**fails open to `superadmin` on a DB error**. This is a deliberate
availability-over-strictness tradeoff (so a DB hiccup doesn't lock every
admin out), but it is exactly the kind of tradeoff Phase 8's "Security
Center" should surface as a known, reviewed decision rather than a silent
default — see Section B.

### A.8 Payment architecture

`PaymentProviderInterface` (name/mode/initiate/verifyWebhook/normalizeStatus)
is implemented by four classes: `SandboxProvider` (real, fully working, no
real money moved — the current default), and `SslcommerzProvider` /
`BkashProvider` / `NagadProvider` (real interface shape, deliberately throw
until real merchant credentials are configured — per Rule 3/4, this project
does not fabricate a "working" integration with a payment gateway it has
never actually been given credentials for).

`PaymentService::handleWebhook()` is the single shared entry point for all
four providers: rejects non-POST, logs the raw body + headers **before**
attempting verification (so a disputed webhook can always be replayed from
the log), dedupes by `(Provider, ProviderEventId)`, only then calls
`verifyWebhook()`, and fails closed — 503 if the provider isn't configured,
400 if the signature is invalid. **A payment is never marked paid from an
unverified request.** This is a genuinely solid piece of architecture and
should be preserved as-is in Phase 7, not replaced.

### A.9 REST API v1

Single-file router pattern (`api/index.php`, action-based, mirrored by
`api/ai.php` / `api/explore.php` for feature-specific endpoints). Auth is
opaque 64-char hex bearer tokens, SHA-256-hashed server-side, 30-day expiry,
stored in `tblapi_tokens`. Real, DB-backed rate limiting
(`tms_api_rate_limit_check`, fails open on DB error — same
availability-over-strictness pattern as A.7). `/api/v1/` is a **zero-drift
alias**: `api/v1/index.php` is a literal 28-line file whose entire body is
a `require` of `api/index.php`, so there is no possibility of the "v1"
routes silently diverging from the real implementation. Swagger UI is live
at `api/docs.php`, reading `api/openapi.yaml`.

### A.10 AI architecture

`AIClient.php` is a shared base handling retry/backoff, rate-limiting,
input sanitization, response caching, and telemetry to `tbl_ai_telemetry`.
It delegates to `AIProviderInterface`, with `GeminiProvider` as the default
and `OpenAIProvider` as an alternate, selected via the `AI_PROVIDER` env
var. On top of this base sit named feature classes: TravelRecommender,
PackageGenerator, ItineraryPlanner, TripCopilot, BudgetOptimizer,
SentimentAnalyser, FraudDetector, NLSearchParser, CrowdPredictor,
ExpenseAnalyst, SiteAssistant, AIEvaluator. See Section D for the important
caveat about `AI_ARCHITECTURE.md` being an outdated planning document that
does not match this real, built system.

### A.11 PWA / offline

`sw.js` implements a genuine dual-cache strategy: a generic `CACHE`
(`'tms-cache-v1'`, normal runtime cache, versioned and evicted on service
worker update) plus a separate `TRIPKIT_CACHE` (`'tms-tripkit'`, **never**
auto-evicted, holding only explicitly-saved-for-offline trip essentials —
itinerary/my-trip.php, ticket.php, emergency.php, wallet.php documents,
packing-assistant.php). This is a deliberate, well-reasoned design (a
normal cache-bust on deploy should never silently delete a traveler's
already-downloaded offline ticket) and should be preserved, not replaced,
in Phase 28.

### A.12 Design system

Token-based since Round 20 (`css/modern-theme.css`, CSS custom properties
under `:root`), a "Boarding Pass" visual identity (Fraunces / Manrope /
Space Mono fonts), dark mode via `html[data-theme="dark"]` attribute
selectors. `DESIGN_SYSTEM.md` itself documents a known inconsistency:
several pages (it cites `frontend/tour-history.php` as a representative
example) use page-local one-off styles instead of the shared tokens. This
is real, admitted technical debt — see Section I — and is exactly what
Phase 34 ("standardize, don't replace") should target.

### A.13 Version control

A real git repository with a GitHub remote (`origin
https://github.com/AJRxLABIB/tms.git`), branch `main`, reported as "up to
date with origin/main" — meaning the repo **has** been pushed to GitHub.
This directly **contradicts** a claim in the project's own `TESTING.md`
that "only a local git repo exists" and that `.github/workflows/ci.yml` has
"never run on GitHub." That CI workflow file is real and already written
(lints all PHP, runs `tests/run.php` against a throwaway MySQL 8
container) — but this audit cannot check GitHub's own Actions run history
from this environment, so whether it has actually executed on GitHub
cannot be confirmed either way here. **Recommendation:** the user should
check the Actions tab on the GitHub repo directly; if it has never run,
that's a one-time push-and-verify, not a code change.

---

## B. SECURITY AUDIT

*Carried forward from `SECURITY.md` (not independently re-verified line by
line this pass, except where marked "verified this session") — the
document itself is explicit that it is a rolling record of specific past
audits, not a from-scratch pen test.*

**Documented, standing protections:**
- CSRF enforcement on state-changing admin actions.
- Admin auth: bcrypt with rehash-on-login, lockout after repeated
  failures, 8-role RBAC (see A.7).
- Audit trail: `tblactivity_log` for admin actions.
- All admin destructive actions require POST + CSRF token.
- Secrets live only in `.env` (see below for one real gap found).
- Travel Wallet documents are gated behind an ownership check, never a
  public URL.
- File-upload, IDOR, SQL injection, and XSS coverage — `SECURITY.md`
  attributes these to specific numbered `AUDIT_REPORT_ROUND*.md` files
  from earlier rounds; this pass did not re-run those checks independently.

**Explicitly documented gaps ("Not done this round, and why" — the
project's own honest self-assessment, not this audit's discovery):** no
fresh OWASP-style re-audit, no automated security regression tests, no
general rate limiting on public read-only endpoints (a reviewed,
deliberate decision, not an oversight).

**New findings, verified this session:**

1. **`.env` has `APP_DEBUG=true` set.** Verified by reading `.env` (key
   names/values for this one non-secret flag only — never secrets).
   `ENVIRONMENT.md` itself states `APP_DEBUG` is "currently not read
   anywhere else in the codebase (confirmed via grep) — kept for forward
   compatibility, not wired to anything yet." So today this is **inert**,
   not an active information-disclosure risk. But the master prompt's
   Phase 40 explicitly requires production to run with `APP_DEBUG=false`,
   and once this flag *is* wired up (e.g. to gate `display_errors`), a
   `true` value left over from development would immediately become a
   real disclosure risk. **Recommendation: flip it to `false` now**, as a
   zero-risk hygiene fix, before it's ever wired to anything.
2. **`.env` correctly excluded from git** — confirmed via `.gitignore`.
   `.env.example` exists with placeholder values only.
3. **Secret key names present and non-blank in `.env`** (confirmed by
   reading key names only, per the standing constraint): `GEMINI_API_KEY`,
   `OPENWEATHER_API_KEY`, `GOOGLE_MAPS_API_KEY`, plus payment-provider
   keys. **No value is reproduced anywhere in this report.** These are
   real, currently-configured secrets — if this project's `.env` is ever
   shared, committed, or pasted anywhere outside this local environment,
   those keys should be rotated. No evidence was found this session that
   they have leaked (git history was not exhaustively scanned this pass —
   a targeted `git log -p -- .env` / secret-scan is a fast, cheap Phase 2
   task worth doing explicitly, since this audit did not do it).
4. **RBAC fail-open behavior** (A.7) and **API rate-limit fail-open
   behavior** (A.9) are both real, deliberate availability-over-strictness
   tradeoffs, not defects — but they are also exactly the kind of decision
   Phase 8's "Security Center" should surface explicitly to the user/admin
   rather than leave undocumented in code comments alone.
5. **Customer-facing 2FA does not exist.** Verified via grep this session
   — TOTP/2FA code exists only in the admin panel. Phase 8/9's "2FA for
   customers" is a genuine gap, not already built.
6. **Social login (Google/Facebook) does not exist anywhere in the
   codebase.** Verified via grep this session (no OAuth client libraries,
   no `google_id`/`facebook_id` columns, no callback routes). Phase 9 is a
   genuine, from-scratch build.

---

## C. DATABASE AUDIT

*Based on `DATABASE.md` plus direct reads of the current migration files
this session.*

### C.1 Referential integrity — corrected finding

An initial grep of the legacy `tms.sql` baseline alone showed **zero**
`FOREIGN KEY` constraints, which in isolation would wrongly suggest this
project has no referential integrity at all. Reading
`database/migrations/004_fk_check_constraints.sql` in full corrected this:
the **current effective schema** (once migrations are applied, which they
are in normal operation) has:

- A real `UNIQUE` constraint on `tblusers.EmailId` — this closes a genuine
  pre-existing `register.php` TOCTOU race (previously only two non-unique
  indexes existed on that column).
- 3 clearly-labeled "Legacy Demo Account" placeholder parent rows,
  backfilled specifically so 2020-era orphaned child rows wouldn't violate
  new foreign keys — labeled as what they are, not silently invented data.
- **14 real `FOREIGN KEY` constraints**, all `ON DELETE RESTRICT ON UPDATE
  CASCADE`: fk_booking_package, fk_booking_user, fk_payments_booking,
  fk_payment_events_payment, fk_payment_refunds_payment, fk_wishlist_user,
  fk_wishlist_package, fk_traveldocs_user, fk_reviews_user,
  fk_reviews_package, fk_loyalty_user, fk_loyalty_log_user, fk_cart_user,
  fk_enquiry_user, fk_issues_user.
- **5 `CHECK` constraints**: chk_booking_status, chk_users_notifications_enabled,
  chk_users_personalization_enabled, chk_package_price_nonneg,
  chk_payment_amount_nonneg.

**Correct framing for the roadmap:** "no foreign keys" describes only the
*legacy baseline* file, not the *current effective schema*. This is
already substantially done — Phase 5's DB-integrity goals are largely met.

### C.2 Known, documented schema debt (from `DATABASE.md`'s own "Known
schema debt (real, not hidden)" section — not this audit's discovery, but
worth restating for the roadmap)

- Money columns are stored as `FLOAT` in places rather than a fixed-point
  type — a real precision-risk pattern worth fixing in Phase 5/38.
- The hotels/activities marketplace is deliberately labeled sample data
  (a Round 37 decision, contrasted explicitly with the excluded
  visa/entry-requirement feature in `SCOPE_EXCLUSIONS.md` — see D.3-style
  reasoning).
- Admin marketplace booking management (Round 41) uses separate hotel/
  activity booking tables rather than one generalized bookings table —
  a real duplication, flagged as a Phase 5 candidate for consolidation.
- Mixed `latin1`/`utf8` charset across tables — a real migration-risk item,
  not yet unified.
- A dead `tbl_ai_analytics` table exists (superseded by `tbl_ai_telemetry`)
  — safe to drop after confirming no lingering references.
- No seasonal-availability column exists — this is a **deliberate**
  omission (Rule 3: no licensed seasonal-data source), not a bug.
- `tbl_user_profile.travel_mode` was dead, then revived in Round 42 — now
  live, not debt.

### C.3 Currency / multi-currency support — genuine gap

Verified via grep this session: no `exchange_rate`, `converted_amount`, or
`rate_timestamp` columns exist anywhere in the schema. Bookings/payments
are stored BDT-native only (`AmountMinor`). **Phase 38 (multi-currency
storage) is a genuine, from-scratch schema change**, not already built —
and per the master prompt's own instruction, must be additive (never
overwrite the original transaction currency), which today's schema doesn't
yet have the columns to even attempt.

### C.4 Indexing (Phase 33's named columns)

Not exhaustively re-audited index-by-index this pass (that requires a live
`SHOW INDEX` sweep against the actual running database, which this
read-only-audit session did not perform). `PERFORMANCE.md` /
`PERFORMANCE_NOTES.md` document that indexing work has already happened in
prior rounds (N+1 batching, `ORDER BY RAND()` replaced with an indexed
fetch + PHP shuffle, new indexes added, persistent PDO connections). A
live `EXPLAIN`-based pass against the specific columns the master prompt
names (user_id, package_id, booking_id, created_at, status, destination,
email, payment_status, from_date, to_date) is recommended as a concrete,
low-risk Phase 33/5 task — this audit did not fabricate index coverage
numbers it didn't measure.

---

## D. AI SYSTEM AUDIT

### D.1 `AI_ARCHITECTURE.md` is outdated — do not use it as a spec

Read in full this session and confirmed to be an **early planning
document**, not a description of what was actually built: it references
"Claude/Anthropic API" and an `ANTHROPIC_API_KEY` env var that don't exist
in the real `.env`, and names classes (VirtualGuide, PackingAssistant,
HotelAdvisor, MemoryGenerator, Analytics, GroupPlanner, SafetyMonitor)
that do not match the real `backend/ai/` file list. `ARCHITECTURE.md`
itself already flags this document as outdated — this audit independently
cross-checked that flag against the real file list and confirms it's
accurate. **Recommendation:** either update `AI_ARCHITECTURE.md` to match
reality or clearly mark it "historical/superseded" so a future round
doesn't mistake it for a live spec.

### D.2 What's actually built (confirmed against real files)

The real architecture is Section A.10's `AIClient.php` +
`AIProviderInterface` (Gemini default, OpenAI alternate) + the 12 named
feature classes listed there. This is a coherent, already-unified design —
Phase 11's "unify existing AI modules rather than treat as disconnected"
goal is **largely already met** by this architecture; there isn't a set of
disconnected AI silos to merge.

### D.3 Real vs. fabricated data discipline

`SCOPE_EXCLUSIONS.md` documents a deliberate exclusion: a Visa/Entry-
Requirement system was **not built** because no licensed, reliable data
source exists for it and getting it wrong carries real travel-disruption
risk for a user (Rule 3). This is contrasted explicitly against the Round
37 hotels/activities marketplace, which *was* built — but only because it
is clearly labeled sample data and the project owner explicitly chose to
proceed knowing that. This same reasoning should govern Phase 20's
Bangladesh Travel Intelligence content: real, verifiable facts (bus/train/
flight routes, general geography) are fine; anything requiring a licensed
or time-sensitive data feed (live seasonal pricing, live weather-driven
route closures) should either be sourced live (already possible — the
project has a working `OPENWEATHER_API_KEY` integration) or explicitly
scoped out the same way visa data was.

### D.4 Genuinely missing AI-adjacent features (per the 40-phase list)

- **Phase 13 (recommendation explanations / "Why this recommendation?")**:
  not found in the current UI via this pass's greps — a genuine, additive
  feature.
- **Phase 14 (thumbs up/down feedback loop)**: not found — genuine gap.
- **Phase 15 (AI Quality Dashboard with real measured metrics)**: partial —
  `tbl_ai_telemetry` already captures real usage data (Section A.10), so
  the data plumbing exists; a dedicated dashboard surfacing it does not
  yet exist as far as this pass found. This is a much smaller lift than
  building from zero, since the metrics collection is already there.
- **Phase 12 (weather-triggered replanning with mandatory user approval)**:
  not found as a built feature. The prerequisite pieces (working weather
  integration, `TripCopilot`/`ItineraryPlanner` AI classes) already exist,
  so this is a genuine but well-supported addition — not a from-scratch AI
  build.

---

## E. API AUDIT

*Based on `API_DOCUMENTATION.md` plus direct reads of `api/index.php`
(grepped for auth logic) and `api/v1/index.php` (read in full) this
session.*

### E.1 What's real and working today

- Base URL structure, consistent JSON response shape, and a documented
  endpoint set: packages, package, types, check_email, login, logout, me,
  my_bookings, book, cancel_booking.
- **Bearer-token auth**: opaque 64-char hex tokens, SHA-256-hashed at rest
  in `tblapi_tokens`, 30-day expiry. Verified via grep: `hash('sha256',
  ...)` and the `tblapi_tokens` table are real, not aspirational.
- **Real rate limiting**: `tms_api_rate_limit_check()`, DB-backed, 120
  requests/60s globally and a tighter 20/60s specifically on
  `check_email` (an anti-enumeration measure). Fails open on a DB error
  (see B.4's fail-open note).
- **Versioning that cannot drift**: `api/v1/index.php` (28 lines, read in
  full) is purely `require __DIR__ . '/../index.php';` with a docblock
  explaining the rationale. There is no way for `/api/v1/` to silently
  diverge from the real implementation — this is a genuinely good pattern,
  not busywork, and Phase 29's "RESTful `/api/v1/...` shape, backward
  compatible" is **already done**.
- Swagger UI at `api/docs.php` / `api/openapi.yaml` — live API docs already
  exist.

### E.2 Genuine gaps (Phase 30)

Verified via grep this session: tokens today are a single opaque bearer
token per login, with no refresh-token concept, no scoped permissions, and
no per-device session listing/revocation at the API layer (device/session
management exists for the *web* session layer per `SECURITY.md`'s
"logout-all-devices" mention under Phase 8, but not for API tokens
specifically). **Phase 30 (access/refresh tokens, revocation, device/
session management, scopes) is a genuine, from-scratch addition to the API
layer** — the existing single-bearer-token design is solid for what it is,
but doesn't have the pieces Phase 30 asks for.

---

## F. FRONTEND/UX AUDIT

### F.1 Design system consistency — known, admitted gap

`DESIGN_SYSTEM.md`'s own "Known inconsistency" section (not this audit's
discovery) names `frontend/tour-history.php` as a representative example
of a page using page-local one-off styles instead of the shared design
tokens (`css/modern-theme.css`). This is real, scoped debt for Phase 34 —
"standardize, don't replace" is exactly the right framing already.

### F.2 My Trip Command Center — already substantially built

Verified via size check this session: `frontend/my-trip.php` already
exists at **524 lines**. This pass did not read the file's full content
line-by-line to confirm every one of Phase 10's requested data sources
(itinerary/bookings/hotel/transport/flight/weather/budget/expenses/alerts/
wallet/emergency/documents/AI/notifications) is unified into it, but a
524-line page is not a stub — this is a strong signal Phase 10's goal is
**already largely met**, and Phase 10 work should start from "what's
missing from my-trip.php" rather than building a new dashboard from
scratch, per the master prompt's own anti-duplication instruction.

### F.3 Language persistence (Phase 37's explicit requirement)

Verified via grep this session: the i18n system already persists the
selected language via a cookie fallback, which is the specific behavior
Phase 37 requires ("must not silently reset on reload"). This appears to
already satisfy Phase 37's core requirement — a full click-through
re-verification (refresh/login/logout/navigation/new-session, exactly as
the master prompt lists) is a cheap, worthwhile confirmation step before
declaring Phase 37 fully closed, but the mechanism is not missing.

### F.4 PWA / offline (Phase 28)

Section A.11's dual-cache `sw.js` architecture is a real, sophisticated,
already-working implementation — Phase 28 should extend and audit it
(e.g., confirm every page the user would reasonably want offline is in
`TRIPKIT_CACHE`), not replace it.

---

## G. PERFORMANCE AUDIT

*From `PERFORMANCE.md` / `PERFORMANCE_NOTES.md`, read in full — these are
the project's own honest running record of fixes already made and items
still open, not fabricated for this report.*

**Already fixed (prior rounds):** N+1 query batching, `ORDER BY RAND()`
replaced with an indexed fetch + PHP-side shuffle, new indexes added,
persistent PDO connections, weather-response caching, lazy-loading on
image-heavy pages, gzip for JSON API responses.

**Explicitly out of code's reach (infra-level, documented as such rather
than silently ignored):** OPcache configuration, HTTP/2, a CDN or WebP
image pipeline, MySQL server tuning. These require server/hosting access
this environment doesn't have — correctly scoped as deployment tasks
(Phase 40), not code changes.

**This session's addition:** F1/F2/F14/F15 from Round 114's map-system
audit remain open and relevant to general performance/reliability posture
(marker clustering and list virtualization for the ~413-package map, and
the CDN-hosted-Leaflet / free-OSRM-demo-server reliability risks) — see
that round's own changelog entry for full detail; restated here because
they're genuinely part of the performance/reliability picture the roadmap
should account for.

---

## H. TESTING AUDIT

*From `TESTING.md`, read in full.*

`tests/run.php` has real, working coverage: money math, dynamic pricing,
availability, migration validity, `migrate.php --status`, login lockout,
refund ledger, `NLSearchParser`, privacy-table consistency, hotel/activity
sample-data flagging, and RBAC fail-open behavior. Two real bugs were
found and fixed via this suite in an earlier round (Round 53): a missing
`CREATE TABLE IF NOT EXISTS` for `tblcustomtrips` in `AI_MIGRATION.sql`,
and a `require` (should have been `require_once`) causing a redeclare
fatal in the test runner itself — both fixed, 16/16 tests passing in a
faithful local CI replica.

**Honest, self-documented gaps:** no E2E tests, no security fuzzing, no
admin-workflow tests. **Phase 39 (unit/integration/E2E via Playwright or
compatible) is a genuine, substantial gap** — unit/integration coverage
exists and is real, but E2E coverage does not exist at all today.

**One claim in `TESTING.md` this audit found reason to question, not
confirm or deny outright:** it states the CI workflow "has never run on
GitHub because only a local git repo exists." Section A.13 above found a
real GitHub remote and a repo reported as up to date with `origin/main` —
directly contradicting the "only a local repo exists" premise. Whether the
CI workflow has actually executed on GitHub cannot be checked from this
environment; the user should check the Actions tab directly. This is
flagged rather than asserted either way (Rule 4).

---

## I. TECHNICAL DEBT REPORT

Ranked by how directly each item blocks or complicates the 40-phase
roadmap, not by discovery order.

1. **`backend/landing.php`'s two un-migrated bare `include('includes/...)`
   calls** (A.4). Low effort, but must be resolved *before* Phase 3 can
   safely relocate/delete root `includes/` — currently the one thing
   standing between "includes/ is dead" and reality.
2. **98 stray root/admin-level diagnostic files**
   (`test-round*.php`/`cleanup-round*.php`/`_diag_db_check.php`), quantified
   this session: 85 root-level `test-round*.php` files (12,228 bytes
   total) + 1 `_diag_db_check.php` (156 bytes) + 12 stray
   `admin/test-round*`/`admin/cleanup-round*` files. These sit outside the
   project's own already-proper `tests/manual/` convention (~29 files,
   well-organized — **should not be touched/reorganized**, only the 98
   stray ones need consolidating). This is exactly Phase 4's job, already
   fully scoped by this audit.
3. **Design-token inconsistency** (F.1) — admitted, scoped, Phase 34.
4. **`FLOAT` money columns** (C.2) — real precision risk, worth fixing
   alongside Phase 38's currency-column work since both touch the same
   tables.
5. **Mixed `latin1`/`utf8` charset** (C.2) — real but lower urgency than
   the above; a migration-risk item to schedule deliberately, not rush.
6. **Dead `tbl_ai_analytics` table** (C.2) — trivial to drop once
   double-confirmed unused.
7. **`APP_DEBUG=true` in `.env`** (B.1) — currently inert but should be
   flipped to `false` now, before it's ever wired to anything, as
   zero-risk hygiene.
8. **CDN-hosted Leaflet with no SRI/fallback, and reliance on a free OSRM
   demo server** (Round 114's F14/F15, restated in Section G) — real
   reliability debt, deliberately deferred, not forgotten.
9. **Duplicate hotel/activity booking tables instead of one generalized
   bookings table** (C.2) — real duplication, Phase 5 candidate.

None of these represent "the project is broken" — every item above is
either already fully scoped with a clear fix path, or a deliberate,
documented tradeoff. This is a mature, well-documented codebase with real,
manageable debt, not a codebase in crisis.

---

## J. PRIORITIZED UPGRADE ROADMAP

Format: **[STATUS]** Phase — one-line disposition. STATUS is one of
`ALREADY BUILT`, `MOSTLY BUILT`, `PARTIAL`, or `NOT BUILT`, per the master
prompt's own "reuse existing functionality... do not add features merely
to increase feature count" instruction.

### CRITICAL

- **[PARTIAL] Phase 2 — Critical security.** `.env` correctly git-ignored;
  the one real, immediately-actionable item is flipping `APP_DEBUG` to
  `false` (currently inert, but a real future risk). No leaked secrets
  found this pass, but a dedicated `git log -p -- .env`-style secret scan
  was not performed and is recommended as an explicit, cheap next step.
- **[NOT BUILT] Phase 38 — Multi-currency storage.** No `exchange_rate`/
  `converted_amount`/`rate_timestamp` columns exist; BDT-native only
  today. Genuine schema + service-layer work, and must be additive per
  the user's own instruction (never overwrite original transaction
  currency).
- **[MOSTLY BUILT] Phase 6 — Booking state machine.** Not independently
  re-verified state-by-state this pass (would require reading the full
  booking-lifecycle code, which this audit's time budget went toward the
  broader 21-dimension sweep instead) — flagged for a focused follow-up
  read before implementation, rather than assumed complete or incomplete.

### HIGH

- **[ALREADY BUILT] Phase 7 — Payment reliability core.** The
  `PaymentProviderInterface` + `PaymentService::handleWebhook()`
  architecture (A.8) already implements dedup, log-before-verify, and
  fail-closed behavior correctly. Remaining work is real credential
  configuration for SSLCommerz/bKash/Nagad (a business/ops task, not a
  code task) and an admin reconciliation dashboard (not confirmed to
  exist this pass — worth a targeted check before building a new one).
- **[PARTIAL] Phase 8 — Auth & Security Center.** Admin-side lockout,
  RBAC, and 2FA already exist. Customer-facing 2FA, a dedicated "Security
  Center" UI, and explicit surfacing of the fail-open RBAC/rate-limit
  decisions (B.4) are genuine gaps.
- **[NOT BUILT] Phase 9 — Social login.** Confirmed via grep: no OAuth
  code, no provider-ID columns, no callback routes exist. From-scratch
  build, including the account-linking logic the phase requires.
- **[MOSTLY BUILT] Phase 10 — My Trip Command Center.** `my-trip.php`
  already exists at 524 lines (F.2) — start from "what's missing" rather
  than building new.
- **[MOSTLY BUILT] Phase 11 — Travel Intelligence Engine (unify AI).** The
  real `AIClient`/`AIProviderInterface`/feature-class architecture (A.10,
  D.2) is already a unified design, not disconnected silos.
- **[NOT BUILT] Phase 30 — API refresh tokens/scopes/device revocation.**
  Confirmed via grep (E.2): today's API auth is single opaque bearer
  tokens only.
- **[PARTIAL] Phase 39 — Testing.** Real unit/integration coverage exists
  and passes (H); E2E coverage does not exist at all. Playwright is
  already the environment's available tooling (confirmed present in this
  session's own environment), making this a lower-friction addition than
  it might otherwise be.

### MEDIUM

- **[NOT BUILT] Phase 12 — AI dynamic trip replanning.** Prerequisite
  pieces (weather integration, TripCopilot/ItineraryPlanner) already
  exist; the replanning-proposal + explicit-approval flow itself does not.
- **[NOT BUILT] Phase 13 — "Why this recommendation?" explanations.**
  Confirmed not present via this pass's review.
- **[NOT BUILT] Phase 14 — AI feedback loop (thumbs up/down).** Confirmed
  not present.
- **[PARTIAL] Phase 15 — AI Quality Dashboard.** `tbl_ai_telemetry` already
  captures real metrics (A.10); a dashboard surfacing them does not yet
  exist as far as this pass found — smaller lift than ground-up.
- **[PARTIAL] Phase 37 — Multilingual persistence.** Cookie-based language
  persistence already exists (F.3) and appears to meet the phase's core
  "must not silently reset" requirement; needs a full click-through
  re-verification across every listed scenario before being called fully
  closed.
- **[ALREADY BUILT] Phase 28 — PWA/offline.** The dual-cache `sw.js`
  design (A.11) is sophisticated and already working; extend, don't
  replace.
- **[ALREADY BUILT] Phase 29 — API v2/RESTful shape.** `/api/v1/` zero-
  drift alias (A.9/E.1) already satisfies this.
- **[ALREADY BUILT] Phase 34 groundwork — Design system.** Token
  architecture already exists (A.12); remaining work is consistency
  cleanup (F.1), not building a system from zero.
- **[PARTIAL] Phase 5 — Database improvement.** Referential integrity is
  already substantially done via migration 004 (C.1); remaining items are
  the documented schema debt list (C.2) — `FLOAT` money columns, mixed
  charsets, duplicate hotel/activity tables, dead table cleanup.
- **[MEDIUM, SCOPED] Phase 4 — Test file cleanup.** Fully quantified this
  session (I.2): 98 stray files, exact byte counts known, exact
  destination convention (`tests/manual/`) already exists and should not
  itself be touched.
- **[MEDIUM, SCOPED] Phase 3 — Architecture cleanup (`includes/` vs
  `backend/`).** Fully scoped this session (A.4) with an explicit, safe,
  4-step non-destructive sequence.

### LOW

- **Phase 16 (Trip Readiness Score), Phase 17 (unify alerts), Phase 18
  (price tracking), Phase 19 (AI budget autopilot), Phase 20/21
  (Bangladesh travel/transport intelligence), Phase 22 (Emergency Travel
  Center), Phase 23 (Wallet improvements), Phase 24 (gamification), Phase
  25 (sustainability), Phase 26 (global search), Phase 27 (personalized
  homepage), Phase 31 (observability/system health), Phase 32 (Admin
  Command Center), Phase 35 (accessibility), Phase 36 (SEO), Phase 40
  (deployment prep)** — not individually deep-audited this pass; several
  already have partial infrastructure this audit surfaced in passing
  (e.g., `admin/system-health.php`, `admin/operations-dashboard.php`,
  `admin/revenue-analytics.php`, `admin/user-analytics.php` all confirmed
  to exist via this session's greps, suggesting Phase 31/32 have more of a
  head start than "not built"). A dedicated, focused audit pass per phase
  is recommended immediately before that phase's implementation work
  begins, rather than scoping all of them speculatively in this document.

**Overall recommendation:** implementation should proceed in the order
CRITICAL → HIGH → MEDIUM → LOW as scoped above, phase by phase, with the
master prompt's own IMPLEMENTATION RULES (explain changes/affected files/
DB changes before implementing, test, check regressions, report) applied
to each phase individually — never as one large batch. Given how much of
the 40-phase list is already substantially built, the highest-leverage
next step is likely **Phase 2's `.env` hygiene fix** (trivial, zero risk)
followed by **Phase 3's `includes/` cleanup** (fully scoped, low risk,
unblocks future confusion) and **Phase 4's diagnostic-file consolidation**
(fully quantified, zero functional risk) — three genuinely quick wins
before tackling the larger genuinely-missing builds (social login,
multi-currency, API refresh tokens, customer 2FA, AI feedback loop).

---

**Implementation status of this report: N/A — this is an audit document,
not a code change. No file was modified, moved, or deleted as part of
producing it.**

**Testing status: N/A for the same reason.** Every factual claim above is
sourced from a direct read, grep, or size/lint check performed this
session (or explicitly labeled as carried forward from the project's own
prior documentation and not re-verified this pass) — none is inferred or
assumed. Where this pass's evidence was incomplete (e.g., Phase 6's
booking state machine, Phases 16–27/31/32/35/36/40's individual depth),
that is stated plainly above rather than guessed at.
