# Changelog

A condensed, chronological index of the work done across this project's
rounds. The primary sources are `SESSION_SUMMARY_INDEX.md` (Rounds 1–18,
now superseded/frozen) and `SESSION_SUMMARY_INDEX-1.md` (the actively
maintained index, Rounds 6–84 — it repeats 6–18 before continuing). Read
either of those for full implementation/testing detail; this file is the
skimmable index, not a replacement.

**Ordering: oldest → newest (chronological)**, since there's no
version-number scheme in this project — "Round N" is the heading unit.
Entries are compressed to what shipped; the detailed "what was tried,
what broke, how it was verified" narrative for each round lives only in
the two source files. Nothing below is asserted beyond what those files
say — where a round's own text flags something as untested/blocked, this
file says so too rather than smoothing it over.

**A numbering quirk worth knowing before reading further:** round numbers
are not perfectly sequential or one-topic-per-number. Rounds 1–5 are not
individually headed in either source file — their work is described only
qualitatively (Security Phase 1, AI-deepening Phase 2, see the "Earliest
work" entry below). "Round 20" alone spans a full 82-point master-prompt
recovery/re-audit plus 16 numbered backlog items (#21–#36) closed one by
one in a single long session, each summarized as its own bullet here.
Round 56 is title "Root-level test-script cleanup" in the index headings,
distinct from a "Round 56" code comment inside `PaymentService.php` that
refers to the webhook work actually shipped in Round 57 — the numbering
in code comments and doc headings has small drifts like this; this file
follows the doc headings.

---

## Earliest work (pre-Round-6, described qualitatively — "Phase 1"/"Phase 2")

Security Phase 1: admin login brute-force lockout, CSRF (log-only at
first), password auto-rehash, TOTP 2FA, superadmin/manager RBAC, a unified
`CacheService` replacing three duplicate cache implementations. AI feature
depth (Phase 2a–2l): itinerary planning grounded in real weather/booking
seasonality, a faster/cheaper Trip Copilot, real sentiment analysis,
price/crowd/fraud signals grounded in real catalog and booking data
(zero-AI, deterministic), Expense Tracker, gamification/achievements,
sustainability/carbon widget, PWA fixes, voice-enabled copilot, and the
Digital Travel Wallet with real access control. Real-time flight tracking
(Phase 2m) via OpenSky, framed honestly as position-only.

## Round 6 — UI foundation

Fixed two dark-mode systems fighting each other, `index.php`'s duplicated
footer, added toast notifications (replacing 18 blocking `alert()` calls)
and real keyboard-focus indicators.

## Round 7 — booking system

Fixed every booking silently getting `NoOfPersons=1`/`TotalCost=NULL`/no
coupon discount (nothing that created a booking wrote those columns).
Added date-change requests, refund-status tracking, a booking timeline,
and QR verification codes.

## Round 8 — admin analytics

Added User Analytics and Popular Destinations pages; found
`admin/ai-dashboard.php` already existed but had zero links to it anywhere.

## Round 9 — REST API

Added token-based auth and `me`/`my_bookings`/`book`/`cancel_booking`
endpoints, plus `API_DOCUMENTATION.md`. Booking through the API reuses the
Round 7 fix, not a second code path.

## Round 10 — hardening pass

Fixed three entry points where a nullable `PackagePrice` let a package be
booked for free. Added brute-force lockout to the API login (had bypassed
the site's own protection), made coupon application atomic, added a
unique index on the verification-code column.

## Round 11 — admin CSRF hardening

Converted 13 admin files (including all of `manage-bookings.php`) from
destructive GET links to POST+CSRF. Found and fixed two real, previously
undocumented bugs: an auth-bypass in `edit-package.php` (missing `exit`
after a failed login check) and an unrestricted file upload + path
traversal in `change-image.php`.

## Round 12 — customer-facing pass

Found `BookingDate` was never set by any booking path (NULL on every
booking ever made), silently breaking 5 features that read it as the trip
date; fixed at the source plus a self-healing backfill. Also fixed invented
"Emergency Contact" phone numbers in nearby-places data, a USD/Taka
currency-symbol mismatch, dead map-sidebar clicks for uncoordinated
packages, and a blind Explore-chat context bug.

## Round 13 — dark-mode contrast + deploy-sync gap

Replaced a fragile hex-string dark-mode color allowlist with a general
computed-color check. Discovered the session's connected folder and the
folder Apache actually serves had drifted apart in both directions; synced
what this session touched, left `.env` and one unrelated feature alone.

## Round 14 — professional pass (map/places/weather/AI/whole site)

~70 fixes from four parallel audits. Headlines: AI was fully off (no
`.env`) and silently returning fallback text; `PlaceDetailsService`
invented phone numbers, reviews, ratings, and photos wholesale — rewritten
to show only real map data; `weather.php` showed real Arabian Sea weather
for any package missing coordinates; the chatbot had no catalog data, no
CSRF, an XSS sink, and a shared rate-limit bucket; the Sign Up modal was
uncompletable due to a substring-matching bug; a 100%-off coupon was
reachable via uncapped loyalty redemption; 8 queries were silently dead
from one `LIMIT ?` binding bug.

## Round 15 — going public / SEO

Removed an accidental "require login" gate from 20 pages robots.txt/
sitemap.php mark as public (Google could only ever have indexed the
homepage). Found real API keys had been typed into `.env.example` instead
of `.env`; moved them, confirmed the chatbot gives real Gemini answers.

## Round 16 (4 continuations) — production-transformation kickoff

Built `database/migrations/` + `database/migrate.php` (real tracked
runner). Built the payment architecture from zero: `PaymentProviderInterface`,
a real working `SandboxProvider`, honest SSLCommerz/bKash/Nagad stubs that
throw until real credentials exist, `PaymentService`, and a `payment.php`
step. Fixed a double-booking race condition with row-level locking; made
payment status visible on `tour-history.php`. Reviewed for fabricated data
(found none) and formally scoped out Visa/entry-requirements and a
hotel/transport marketplace as needing real data this project doesn't
have. Added `DATABASE.md`/`ENVIRONMENT.md`/`SECURITY.md`/`TESTING.md`,
`tests/run.php`. Made the new payment/alert tables self-healing (no manual
migration needed). Built price/availability alerts, `health.php`, CI
(`.github/workflows/ci.yml`), an operations dashboard, group travel +
expense splitting, and Travel Wallet expiry reminders. Attempted live
verification — MySQL went down mid-session (flagged, not fixed, outside
this session's reach) — and fixed two real accessibility gaps found by
self-audit along the way.

## Round 17 (2 continuations) — MySQL instability, security headers, API lockout, refund ledger

Diagnosed (not fixed — outside this sandbox's reach) `mysqld` restarting
every 9–46 seconds with no crash logged, pointing at the machine level
(antivirus or manual toggling), not the app. Added `.htaccess` security
headers (deliberately no CSP yet). Found and fixed a real gap: the REST
API's login had no brute-force lockout at all, unlike the website's own
form. Wired the existing (unused) `tblpayment_refunds` table into the
site's existing cancellation/admin-approval flow. Added `SCHEDULED_JOBS.md`,
fixed unbounded `tbl_login_attempts` growth, fixed two more accessibility
gaps, added two regression tests (written but not yet run — MySQL still
unstable).

## Round 18 — "MY TRIP" hub (Phase 6 Trip OS)

Built `my-trip.php` as a connective hub over existing pages (expenses,
e-ticket, wallet, weather, AI copilot, map, emergency contacts) — computes
a real pre/during/post/cancelled phase from booking dates, IDOR-checked.
Closed a dead-code gap: `tbl_ai_itineraries.booking_id` had never been
populated by anything; wired a real booking-aware itinerary generation
path. Implementation was `php -l`-clean; testing was blocked by the
ongoing MySQL instability.

## Round 19 — MySQL crash-loop root-caused; My Trip hub live-tested end to end

Root-caused the crash to `EXCEPTION_ACCESS_VIOLATION` at address 0x0 in
`mysqld.exe` — a stale bundled InnoDB buffer-pool snapshot (fixed via
`my.ini` config) plus a separate, still-outstanding EOL-related crash bug
in this MariaDB 10.4.32 build (durable fix: upgrade the binary — the
user's to run). With the database stable, live-tested the full
registration → booking → My Trip hub flow end to end for the first time,
and found/fixed 3 real bugs in AI itinerary generation: the fallback path
never actually saved an itinerary, the AI call's timeout budget was too
short for this workload, and `AI_MAX_TOKENS` was too low for a full 7-day
itinerary.

## Round 20 — master prompt recovered; full 82-point re-audit; 16 backlog items (#21–#36) closed

The literal 82-point master prompt (previously surviving only in a
disposable sandbox file) was saved permanently as `MASTER_PROMPT.md`. A
new MySQL crash-loop was correctly diagnosed as a recurrence of Round 19's
known EOL bug, not a new issue. A full, verify-don't-trust re-audit against
all 82 points found Phases 1–5 solid but Phases 6–11 substantially
incomplete (corrected an earlier "assume it's mostly done" framing). The
~20-item backlog was then closed one by one:
- **#21** booking cancellation/date-change — live-verified, one real bug
  fixed (cancelling didn't clear a pending date-change request).
- **#22** AI Copilot/Recommender/admin dashboards — confirmed working.
- **#26** re-diagnosed the AI-recommender fallback cause (network
  timeouts, not a missing key) and connected `ai-recommend.php` to the
  real `TravelRecommender` class instead of a shallow scorer.
- **#27** SEO metadata (canonical/OG/structured data).
- **#28** expanded dark-mode design tokens.
- **#29** automated encrypted database backup/restore scripts (CLI-only;
  not yet live-executed this round).
- **#30** privacy controls: account export, preference toggles, and a real
  anonymize-not-delete account-deletion path (live-verified via direct
  function call, never a typed password).
- **#31** granular 8-role admin RBAC (superadmin/booking_manager/
  package_manager/finance/support/content_manager/ai_manager/operations),
  replacing the old 2-role system; fixed a privilege-escalation footgun in
  the process.
- **#32** DB-level FK/CHECK constraints written as a tracked migration
  (not yet executed this round — needs the user or a superadmin session).
- **#33** wired the dead Travel Alert System's "+ Add Alert" button (had
  posted to nowhere) to a real handler.
- **#34** AI Provider Abstraction: `AIProviderInterface` +
  `GeminiProvider`/`OpenAIProvider`, plus new `tbl_ai_telemetry`.
- **#35** an AI Evaluation suite (`AIEvaluator.php`) that found two real
  bugs on its first run: a telemetry-email mismatch, and a Gemini 429
  daily-quota response that was never actually recognized as such.
- **#36** structured natural-language search (`NLSearchParser.php`),
  honestly declining to fabricate a "month" filter since packages have no
  seasonality data.

## Round 37 — Hotel System + Activities Marketplace (items 12/13)

After putting the "no real supplier data exists" problem to the user
directly, built a full sample/demo hotel + activities marketplace
(`tblhotels`/`tblactivities`/bookings, 16 seeded rows each) — deliberately
separate from `tblbooking` — with a persistent "Sample Listing" badge and
banner on every card and detail page, never presented as real live
inventory.

## Round 38 — i18n sweep beyond header/footer (item 36)

Found the site's REAL sign-in/sign-up modal (the one actually wired to
the topbar) is a second, separate modal in `header.php`, distinct from the
`signin.php` file that looks like "the" modal — translated both, plus the
guest-landing topbar buttons and the contact modal, to all 8 languages.
Disclosed the remaining ~60+ pages as still hardcoded English.

## Round 39 — expand automated test coverage (item 58)

Added 4 targeted regression tests for recently-shipped, previously
uncovered logic (NL search duration parsing, privacy-table schema
consistency, the new marketplace tables' sample-data flagging, RBAC
fail-open behavior).

## Round 40 — formally flag the Visa/Entry-Requirement exclusion (item 18)

Added `SCOPE_EXCLUSIONS.md`, explaining why this item stays fully excluded
with zero placeholder (unlike the marketplace's labeled-sample-data
approach) — regulated legal/immigration information carries real
consequences if wrong.

## Round 41 — admin-side hotel/activity booking management

Built `admin/manage-marketplace-bookings.php` (the piece deliberately
deferred from Round 37), combining two structurally different booking
tables into one filterable, paginated view via a `UNION ALL`.

## Round 42 — CRITICAL: unresolved git merge-conflict markers found in `includes/config.php`

Found and fixed two leftover `<<<<<<< HEAD` conflict blocks in the one
file every page includes (would have fatal-errored every page). Swept the
entire project (262+ files) for any other hidden conflict markers — found
none. Flagged (not yet acted on) the `SESSION_SUMMARY_INDEX.md`/`-1.md`
and `DATABASE.md`/`DATABASE-1.md` stale-duplicate-doc pairs.

## Round 43 — Travel Modes (item 67)

Wired the `travel_mode` column all the way through: `ai-recommend.php`'s
dropdown, AI prompt guidance per mode, a deterministic-fallback scorer
adjustment that explicitly does NOT guess at accessibility support, and a
brand-new "Travel Preferences" card on `profile.php` (closing a
long-dead-table gap). Also removed a duplicated block inside `DATABASE.md`
itself.

## Round 44 — MySQL back up; Travel Modes live-verified; migrations 001–004 applied

Live-tested Round 43 end to end for the first time. Ran
`database/migrate.php` for real — all 4 migrations applied cleanly. Backup/
restore scripts remained blocked (this session's device-shell access was
broken by a platform-side issue).

## Round 45 — Transport System (item 14)

Built a 9-mode transport comparison reusing the existing routing
abstraction (`ExploreService::getRoute()`) for real distance/duration on
4 modes; the rest are honestly labeled estimates. Cost is shown only as a
relative 1–5 tier, never a fabricated currency figure. Found and fixed a
real bug live-testing revealed: a routing API returning HTTP 200 with an
implausible walking speed was being badged "Live Route" anyway; added a
plausible-speed sanity check.

## Round 46 — File Upload Security (item 45)

Generalized `wallet.php`'s real magic-byte content-type validation
(`tms_validate_upload_signature()`) to all 7 upload endpoints. Found and
fixed a real logic bug surfaced while doing this: `add-blog.php`/
`edit-blog.php` would show a validation error banner AND a contradictory
success banner on the same rejected upload.

## Round 47 — SEO meta broadening + cache-busting

Extended `tms_seo_meta()` to `blog.php`/`hotels.php`/`activities.php`/
detail pages — deliberately no Product/Offer JSON-LD on the sample
hotel/activity pages, since that would falsely claim real bookable
inventory. Added `tms_asset_ver()` (filemtime-based `?v=` cache-busting)
to the two actively-edited theme stylesheets across 90 files.

## Round 48 — resolved the two stale/duplicate doc pairs

Added a superseded-banner to `SESSION_SUMMARY_INDEX.md` pointing at the
`-1` file, and replaced `DATABASE-1.md` with a pointer stub to
`DATABASE.md` (its content was a strict, information-free subset).

## Round 49 — project restructure phase 1: `includes/` → `backend/` rename

Renamed the internal, never-URL-accessed logic folder from `includes/` to
`backend/` across 138 files. The old `includes/` folder could not be
deleted (no delete capability at the time) and was left as what looked
like an inert duplicate — see `ARCHITECTURE.md` for how that assumption
did not fully hold up in later rounds.

## Round 50 — project restructure phase 2: the `frontend/` folder via URL-preserving shims

Moved ~90 customer-facing pages into `frontend/`, replacing each with a
4-line root-level shim so every public URL, redirect, and sitemap entry
kept working byte-for-byte unchanged. Verified in a real PHP sandbox
first that a bare relative include resolves against the requested
script's directory, not the moved file's own location. Fixed the 9 real
`__DIR__`-dependent files this move affected. Also moved 27 leftover
root-level QA/test scripts into `tests/manual/`.

## Round 51 — backup/restore disaster-recovery verification (item 60)

Actually ran the full backup → verify → wipe → restore → data-parity
cycle for the first time and found 3 real bugs: a backup-verification
false negative, a failed dump left as plaintext on disk, and — the
important one — the `tblbooking` self-healing fallback schema was
missing 3 core columns (`FromDate`/`ToDate`/`Comment`), invisible until
tested against a genuinely empty database. This is the origin of the one
remaining accepted divergence between `backend/config.php` and
`includes/config.php` (see `ARCHITECTURE.md`).

## Round 52 — audit log coverage across the admin panel + a critical security fix

Extended `tms_log_activity()` coverage from 4 to 25 of 34 admin pages.
While auditing, found a live, unauthenticated endpoint
(`test-round42-admin-session-helper.php`) had been granting a full
superadmin session to anyone who requested it since Round 42, despite its
own docblock claiming it was disabled — fixed immediately, ahead of the
rest of this round's work.

## Round 53 — CI pipeline actually run for the first time (item 59)

Replicated `ci.yml`'s exact steps by hand in an isolated sandbox since the
repo isn't pushed to GitHub. Failed on the first two attempts, surfacing
two real, previously-invisible bugs: `AI_MIGRATION.sql` assumed a table
that only self-healing ever creates, and `tests/run.php` used a plain
`require` (not `require_once`) on a file `config.php` already loads,
fatal-erroring before a single test could run — meaning the test runner
had silently never completed successfully before this round. Fixed both;
16/16 tests then passed.

## Round 54 — responsive design audit across breakpoints (item 61)

Systematic overflow testing (not eyeballing) at the master prompt's named
breakpoints found and fixed 5 real bugs: an inline style bypassing a
working responsive class on `backend/landing.php`'s reviews grid, a
non-wrapping hero-stats row, a main-nav overflow gap at 769–1179px, a
logged-in-only topbar overflow at 993–1300px, and — the largest find —
the admin panel had essentially no phone-width responsiveness at all
(a flex/grid min-width trap plus an inline grid style, both fixed).

## Round 55 — accessibility audit begun: sign-in/sign-up dialog + auth pages (item 32)

Found the sign-in/sign-up modal had no dialog semantics or focus
management at all (opening it never moved focus in; Tab could escape it;
closing never restored focus) — fixed with a real focus trap and ARIA.
Fixed 14 unassociated `<label>`/`<input>` pairs across the modals and
`login.php`/`register.php`. Disclosed 125 more unassociated labels found
elsewhere, not yet fixed.

## Round 56 — root-level test-script cleanup (user-requested)

Deleted 29 leftover root-level test/diagnostic files whose targets were
already dead stubs. Executed via a PowerShell command handed to the user
(this session's device shell was non-functional); confirmed removed from
one of the two project locations, not yet the mirror.

## Round 57 — webhook infrastructure for SSLCommerz/bKash/Nagad (item 43)

Built the first real inbound webhook receivers: a migration adding
`ProviderEventId`/`Signature`/`ProcessingStatus`/`RetryCount` plus a
unique-key idempotency guard, a rewritten shared `PaymentService::handleWebhook()`
(log-before-verify, dedup, fail-closed when unconfigured), and three
receiver files + root shims. Real signature verification per provider
remains deliberately unimplemented (would require guessing at a real
provider's secret/algorithm). Testing partially blocked — no live path to
the user's real MySQL/Apache this round.

## Round 58 — accessibility completion: label/input association (item 32)

Completed the sitewide sweep begun in Round 55 across 36 more files (25
customer-facing, 11 admin), including two real radio-group/multi-input
label patterns. Found and deliberately left alone two dead files
(`includes/signup.php`/`backend/signup.php`) confirmed never actually
included anywhere.

## Round 59 — security review of the newest code surfaces (item 44)

A targeted review of everything added since the last general security
pass (marketplace, `backend/` restructure, webhooks) found no exploitable
vulnerability. Flagged one existing design tradeoff for the user's
awareness without changing it: `tms_admin_role()` fails open to
superadmin on a DB error.

## Round 60 — responsive design audit continuation; first live browser path this session

Discovered the Browser-pane tools (distinct from the broken device shell)
could reach the live site. Found and fixed 2 bugs: `profile.php`'s
sidebar wasn't wrapping to a new row on narrow screens despite
`flex-wrap:wrap` being present (a flex-basis subtlety), and the AI chat
widget's mobile-width CSS override was being silently shadowed by a
duplicate, unconditional rule later in the same file.

## Round 61 — RTL layout for Arabic (items 36/61)

Added a `dir="rtl"` flip script to `header.php` when the active language
is Arabic — the language list had carried `dir=>'rtl'` metadata since it
was built, but nothing ever read it back out at render time.

## Round 62 — error/empty/loading states (item 62)

A subagent audit corrected an earlier "entirely unstarted" claim — most
of this was already handled site-wide. Fixed 5 real gaps: a silently
swallowed chat-history load failure, an unguarded Chart.js CDN failure
with no "no data yet" state, a map view with no loading/error affordance,
and two admin pages where a real DB error was indistinguishable from a
genuinely empty result.

## Round 63 — source & photo provenance (items 64/65)

Confirmed recommendation transparency (item 66) already done. Fixed the
Flight Tracker returning live data with no source/timestamp field at all.
Added an honest disclosure note on package pages whose photo gallery mixes
in stock photos, rather than building a full images/license schema.

## Round 64 — monitoring: a consolidated admin System Health page (item 56)

Found most of item 56 already existed, scattered. Built the two genuinely
missing pieces — a generic `tbl_system_events` log and instrumentation for
email-send and booking-checkout failures — plus a new
`admin/system-health.php` aggregating everything (explicitly labeled
"not implemented yet" for the parts it doesn't cover, rather than
fabricating a number).

## Round 65 — pre/during/post-trip organization (item 68)

Reorganized `my-trip.php`'s quick-links panel by the phase it already
computed, surfacing 6 already-built but never-linked features (budget
planner, packing assistant, travel memories, reviews, achievements,
next-trip recommendations). Deliberately did not add a "Visa" link, per
Round 40's exclusion decision.

## Round 66 — security re-review of the foundational auth/checkout/payment surface (item 44); 1 CRITICAL + 1 HIGH fixed

**CRITICAL:** `forgot-password.php` allowed full account takeover via
just an email + phone-number match, no token, no rate limit. Rewrote it
as a real token-based two-step flow (single-use, hashed, rate-limited,
enumeration-proof). **HIGH:** `admin/index.php` recorded failed-login
attempts but never actually checked the lockout before accepting a guess
— fixed to match `login.php`'s existing behavior. Also found and fixed,
while live-testing the fix: a several-hour PHP/MySQL clock mismatch that
was silently multiplying every lockout's stated wait time, and an
unsupported repeated SQL placeholder.

## Round 67 — accessibility: keyboard navigation & focus (item 32)

Fixed missing `aria-expanded`/Escape-to-close on the mega-menu and
notification dropdown, an inaccessible bare-`<span>` toast-dismiss
control, and a missing `aria-live` region on the (found to be dead-code,
never-included) human chat widget. Added the one remaining
reduced-motion gap page.

## Round 68 — accessibility: ARIA on complex widgets (item 32)

Gave the admin booking-status donut chart a server-computed, always-accurate
`aria-label` (works even if Chart.js's CDN fails to load), and the
customer-facing Leaflet map an accessible name via `role="region"`.

## Round 69 — accessibility: touch/pointer target size (item 32)

Fixed 4 categories of undersized (<24×24px) controls reached via shared
CSS: Bootstrap modal close buttons, the chat widget's mic toggle,
`.btn-xs` icon-only buttons, and password show/hide toggles — closing out
the 4-category audit Round 67 opened.

## Round 70 — accessibility: real focus trap on the classic Bootstrap modals

Closed the gap Rounds 67/69 both named and deferred: the legacy
`data-toggle="modal"` dialogs relied only on Bootstrap 3's crude
`enforceFocus()` fallback, with no real Tab-wrap and no focus-return on
close. Fixed with one generic, ID-agnostic handler in the shared footer,
reaching both shared modal IDs (and any future one) for free.

## Round 71 — API architecture: a real `/api/v1/` path, rate limiting, OpenAPI docs (item 42)

Added `api/v1/index.php` as a one-line `require` of the existing
`api/index.php` (zero duplicated routing, zero drift risk), real
per-endpoint IP rate limiting (deliberately computed via MySQL's own
clock only, per Round 66's clock-mismatch lesson), and a Swagger-UI-served
`openapi.yaml`.

## Round 72 — monitoring: per-call error tracking for Weather and Maps (item 56)

Closed the gap `system-health.php`'s own docblock had disclosed —
instrumented the weather providers and `ExploreService`'s shared HTTP call
point so both now have real, live failure-rate tracking, not just
configured/not-configured status.

## Round 73 — photo/content provenance: Google Places attribution (item 65)

Added the required attribution line whenever a Google Places photo is
shown (captured straight from Google's own API response, never
fabricated) — genuinely untestable in this environment since no
`GOOGLE_PLACES_API_KEY` is configured here, disclosed as such. Also fixed
two empty `alt=""` attributes on the homepage.

## Round 74 — multilingual system: registration AJAX messages (item 36)

Ran the sign-up AJAX handler's 10 hardcoded English validation/success/
error strings through the existing `tms_t()` translation system, in all 8
languages — one concrete piece of the larger, still-disclosed i18n gap.

## Round 75 — admin RBAC: closed a real broken-access-control gap

Found `admin/manage-bookings.php` called only `tms_require_admin()` — any
authenticated admin, any role — despite a sibling page's comment falsely
claiming the same gate. Fixed by adding the missing
`tms_admin_require_role()` call.

## Round 76 — admin RBAC: full verification sweep

Cross-referenced all 22 remaining role-gated admin pages against the
sidebar's own role lists. No further drift found — Round 75's gap was the
one outlier, not the start of a pattern.

## Round 77 — personalization: food/dietary preference (item 23)

Added a real, persisted dietary-preference field to the profile and
recommendation pipeline, following the same honest-disclosure pattern
already used for accessibility (never claims a package attribute the
inventory data doesn't actually contain).

## Round 78 — availability/inventory: seats-remaining badge on the listing page (item 9)

Found the detail page already implemented this correctly; the one real
gap was the browse/listing page never surfacing the same already-computed
number. Added the identical badge/thresholds there, reusing the existing
pricing pipeline with no new query.

## Round 79 — destination system: real inventory inside Explore Mode (item 6)

Connected Explore Mode's AI-written destination overview to the site's
actual bookable catalog — a new `getLocalInventory()` surfaces real
packages/hotels/activities for that country on every response path,
including both AI-failure paths (so real catalog data isn't lost just
because the AI overview call failed, which it does on every install
without a configured key).

## Round 80 — real reviews for hotels and activities (items 12/13)

Extended the existing package review system (rather than building a
second one) to also accept hotel and activity reviews — two new nullable
FK columns, a generalized write/moderation/AvgRating-recalculation path.

## Round 81 — SEO breadcrumbs (item 53)

Added a visible breadcrumb trail plus `BreadcrumbList` JSON-LD to all 8
pages that already call `tms_seo_meta()`. Found and fixed, while testing,
that breadcrumb schema URLs were being emitted un-absolutized.

## Round 82 — content/blog metadata (item 54)

Added the 4 missing fields the spec names for every article (updated
date, sources, related destination, related package) to `tblblog` and its
admin forms/public page. Found and fixed, in passing, a pre-existing bug
where the blog listing referenced two columns that don't exist on
`tblblog` at all, silently falling back to hardcoded defaults for every
post.

## Round 83 — PWA/offline: a real per-trip "Save for Offline" kit (item 52)

Replaced the generic whatever-was-cached service worker behavior with a
deliberate, named per-trip cache (itinerary, ticket, emergency contacts,
documents, packing list) that a traveler can explicitly save. Live
testing of the actual browser-level service-worker registration step was
blocked by this session's test environment; the caching logic itself was
verified directly against the real Cache API as the closest available
substitute.

## Round 84 — money arithmetic: remove unnecessary float casts (items 8/37)

Found the underlying money columns were already integer at the schema
level (an earlier "known schema debt" note describing them as float was
itself imprecise) — the real, narrower issue was float casts/arithmetic
applied to those integers in PHP across 5 files; changed to int
throughout. Found and fixed, while live-testing, an unrelated pre-existing
bug: `tblpayment_events` was missing 4 columns the payment code had always
required, so every sandbox payment confirmation was silently failing
despite the page's own "fully working test-mode flow" banner.

## Round 85 — documentation: ARCHITECTURE.md + CHANGELOG.md (item 75)

Added a top-level architecture map and this changelog. While verifying
the architecture doc's claims against the live code, found and fixed a
real, live bug: the guest homepage's stale `includes/header.php` include
was missing the Round 61 RTL flip script, so a signed-out Arabic visitor
got translated text but the wrong (LTR) page direction. Also re-synced
`includes/payments/PaymentService.php`, which had drifted well past the
documented "kept in sync" set (missing the Round 56/57 webhook handling),
though it has no live callers today.

## Round 86 — Document Wallet: encryption at rest + audit trail (item 28)

Added AES-256-GCM encryption for new wallet document uploads (mirroring
the project's existing backup-encryption pattern) and wired upload/
download/delete/denied-access events into the existing `tblactivity_log`
audit mechanism rather than a new table. Also had `wallet.php` write its
own `uploads/wallet/.htaccess` if missing. Requires the operator to add a
`WALLET_ENCRYPTION_KEY` to `.env` (blocked from remote write this
session) before encrypted uploads activate; until then, uploads correctly
fail closed instead of storing anything unencrypted.

## Round 87 — Package System: structured Inclusions/Exclusions/Cancellation Policy + add-ons (item 7)

Corrected a prior gap-audit's imprecise framing: packages had no
inclusions/exclusions/cancellation-policy concept anywhere at all (not
free text, not a `PackageMeta` JSON key) — a fully additive fix, not a
restructuring. Added three new text fields to packages, a per-package
add-on catalog (`tblpackageaddons`) with admin CRUD
(`manage-package-addons.php`), and add-on selection wired into the direct
"Book Now" booking path and displayed on the e-ticket (deliberately not
into Add to Cart/checkout this round, matching that path's existing
reduced feature parity). Found and fixed, while testing, an unrelated
pre-existing bug: `manage-packages.php`'s "Edit" link used the wrong query
param, so the package list's own "Edit" button had been silently broken.

## Round 88 — Operations Dashboard: live weather-alert widget + flight-disruption disclosure (item 55)

Found `admin/operations-dashboard.php` already had real, live
implementations of every operational metric the master prompt lists except
two. Added a weather-alert widget: finds destinations with confirmed
bookings arriving in the next 7 days and checks them against this
platform's existing live weather integration, flagging severe conditions
via a keyword check that works across both weather providers' text shapes.
Flight disruptions were left undone deliberately and disclosed as such —
this platform has no flight-booking model at all, so a real widget isn't
possible and a fake one isn't allowed.

## Round 89 — Weather integration: Provider abstraction (items 41/71)

Refactored `tms_get_weather()` from one function containing two inline
provider implementations (OpenWeatherMap + Open-Meteo fallback, ~287
lines) into the same `ProviderInterface` + `Service` pattern already
established for AI (`backend/ai/`) and payments (`backend/payments/`):
new `backend/weather/WeatherProviderInterface.php`,
`OpenWeatherMapProvider.php`, and `OpenMeteoProvider.php`, with
`tms_get_weather()` reduced to an orchestrator (cache, provider order,
telemetry). Pure refactor — same cache keys/TTLs, same fallback order,
same telemetry, same returned field shapes — verified live: the primary
provider through `admin/operations-dashboard.php`'s existing weather-alert
widget (Round 88), the fallback provider tested directly in isolation, and
`admin/system-health.php`'s Weather telemetry card confirmed still logging
real success/failure counts under the same event-type strings as before.

## Round 90 — Architecture: Repository layer (items 40/71) + Design System doc (item 34)

Added `BookingRepository` (`backend/repositories/`), the project's first
Repository-layer class, following `PaymentService`'s established
constructor-injected-PDO style. Wired into one real call site,
`frontend/tour-history.php`, replacing ~40 lines of inline booking-history
and ownership-check SQL with repository calls — behavior kept identical,
including the existing PaymentStatus-column migration-resilience fallback.
Verified live: bookings list renders correctly for a real QA account, and
the refactored `findActiveForUser()` guard was exercised through an actual
date-change-request submission (then cleaned up). Deliberately scoped to
one call site, not a rewrite of every booking query in the app — other
callers (`admin/manage-bookings.php`, `frontend/ticket.php`, etc.) keep
their own inline SQL until touched for another reason, the same
incremental-adoption approach used for `PaymentService`.

Also added `DESIGN_SYSTEM.md`, documenting the token system and shared
component classes that already existed in `css/modern-theme.css` since
Round 20 but were never written down anywhere as a system. Includes an
honest note on the actual gap: many pages define their own page-scoped
card/badge/modal styles instead of reusing the centralized tokens —
documented as a known inconsistency to fix incrementally, not rewritten
in this round.

## Round 91 — Booking Engine: Add to Cart now carries add-ons through to checkout (items 10/81)

Closed a scope gap Round 87 deliberately left open: package add-ons could
only be selected on the direct "Book Now" path, not "Add to Cart" —
cart.php/checkout.php silently ignored any add-ons a customer had checked.
Added `tblcartaddons` (a live-read junction table, not snapshotted like
`tblbookingaddons`, since a cart is transient scratch state) and wired
selection through `package-details.php`'s existing add-on checkboxes (one
form already served both buttons) into `cart.php` (display + running
total) and `checkout.php` (review step, `TotalCost`, and a real
`tblbookingaddons` snapshot at the moment a booking is actually created).
Verified live end-to-end: added a real package to cart with an add-on
selected, watched the total agree at every step (cart, checkout review,
payment, tour history), paid via sandbox, and confirmed the e-ticket
showed the correct itemized add-on line — the exact same rendering
`frontend/ticket.php` already had from Round 87, now exercised through a
different booking-creation path with no changes needed there.

## Round 92 — Price Alerts: real price-history tracking (item 26)

Item 26 asked to "track historical prices where legitimate data is
available" — Round 26's original price-alert build never recorded any
history at all, so an alert's target price had nothing to be compared
against in context, and there was no way to see whether a package's price
was trending up or down. Added `tbl_price_history` (one row per package
per calendar day, upserted via `INSERT ... ON DUPLICATE KEY UPDATE` so the
30–60-minute cron that already exists for alerts can't create duplicates)
and three functions in `PricingEngine.php`: `tms_record_price_snapshot()`,
`tms_snapshot_all_package_prices()` (loops every active, priced package),
and `tms_get_price_history()`. Wired the snapshot step into
`scripts/check_alerts.php`'s existing cron run rather than adding a new
scheduled job — that script already runs every 30–60 minutes, which is
exactly the cadence this needs (Rule 73).

Deliberately not backfilled or fabricated (Rule 3): the table starts empty
on every install and only ever grows real snapshots forward from whenever
the cron next runs. `frontend/package-details.php` renders a small inline
SVG sparkline (no charting library) plus a "Set a price alert" link next
to the live price, but only once a package has 2+ recorded days — a
single point isn't a "history", so it stays hidden rather than showing a
placeholder chart or a fake flat line.

Also fixed an unrelated drift bug found while editing this same file: `includes/config.php`'s `tblbooking` fallback `CREATE TABLE`
was missing the `FromDate`/`ToDate`/`Comment` columns that Round 51 had
added to `backend/config.php`'s copy (the one actually `require`'d by live
code) but never mirrored to `includes/config.php`. Dormant either way
(`CREATE TABLE IF NOT EXISTS` is a no-op on any database that already has
the table) but this project's own convention is to keep the two
directories in sync, so it's fixed now rather than left to surprise a
future from-scratch install that happens to load the `includes/` copy.

Verified live: confirmed the gap first (grepped `AlertService.php`,
`PricingEngine.php`, `config.php` for any existing price-history table —
none existed). After shipping, force-ran the migration (this QA browser
session's `tms_db_init` flag was already set true from earlier rounds, so
the once-per-session migration gate had to be bypassed for this one
session only — real users hit it fresh), ran the snapshot function live
(413 packages snapshotted in one call), re-ran it and confirmed the same
row updates in place rather than duplicating (`Id` unchanged, `RecordedAt`
advanced), and confirmed `package-details.php` correctly shows nothing
with only 1 recorded day. Seeded one clearly-labeled synthetic second-day
point for the test package only, confirmed the sparkline + Low/High text
+ alert link render (and are readable — the first version used slate-gray
text that was nearly invisible against the page's green price-panel
background, fixed to white/rgba(255,255,255,*) to match how the rest of
that panel already handles text-on-color), then deleted the synthetic
point immediately (Rule 3 — a fabricated point must not persist as if
real) and reconfirmed the honest empty state returned. Testing status:
`scripts/check_alerts.php`'s CLI entry point itself was not directly
executed this round — this device's local shell was unavailable this
session (a known issue on this Windows build) — but the exact function it
calls (`tms_snapshot_all_package_prices()`) was exercised live via the
same code path through a temporary admin-only HTTP endpoint, disabled to
a 404 stub immediately after and confirmed dead.

## Round 93 — Notification Center: full history page (item 29)

The topbar bell (`backend/header.php`) has shown notifications since
Round ~30, but only ever the latest 8 in a dropdown — nothing linked to
anything older, so once a customer had more than 8 notifications the rest
were simply unreachable. Verified this was real (not just an old-title
assumption) by reading `header.php` directly: `LIMIT 8`, no "view all"
link anywhere, and no `notifications.php` file existed at all.

Added `frontend/notifications.php` (+ a root-level pass-through shim, the
same pattern every other root page in this project uses): a paginated
(20/page) list of a signed-in user's full notification history, reusing
`tbl_notifications` and the exact same mark-all-read behavior the
dropdown already has — no new table, no new notification-creation code
path. Added a "View all notifications" link at the bottom of the
dropdown in both `backend/header.php` and `includes/header.php`.

Item 29 also asks to "support user preferences for channels" — this round
deliberately did not touch that. `tbl_notifications` has no
category/event-type column to filter by, and per-channel (email vs
in-app) delivery preferences would need new preference storage and new
delivery code this project doesn't have yet; that's a separate, larger
piece of work than "let a customer see their older notifications,"
left as an honest, disclosed gap rather than a half-built preferences UI.

Verified live: confirmed the gap by reading `header.php` before writing
any code. After shipping, signed in as the existing disposable QA
customer account (`SELECT`-verified before setting `$_SESSION['login']`
directly, no password typed) and loaded `notifications.php` directly —
it correctly rendered that account's 5 real notifications (booking
confirmations/cancellations from earlier rounds' own testing), newest
first, mark-all-read fired on load same as the dropdown. Tested the
pagination clamp with `?page=99` against only 1 real page of data —
correctly served page 1 rather than an empty result or an error.
Confirmed the "View all notifications" link renders in the dropdown with
the correct `href` on a normal page load. Temporary QA session helper
(`test-round93-user-session.php`) disabled to a 404 stub and confirmed
dead on both device paths.

## Round 94 — Privacy: account anonymization now actually deletes Travel Wallet documents (item 48)

`tms_anonymize_user()` (`backend/config.php` / `includes/config.php`) is
the function `frontend/privacy.php`'s "delete my account" flow calls. It
walks a generic `tms_privacy_tables()` list and anonymizes each
table/column pair by repointing it from the user's real email to a
randomly generated `deleted-user-<id>-<hex>@deleted.invalid` address —
the right approach for most tables (bookings, reviews, etc., where the
*fact* a transaction happened should survive even after the person is
gone). `tbltraveldocuments` (Travel Wallet passport/visa/ID scans) was
being run through that exact same generic path, which only rewrote the
`UserEmail` column — the actual uploaded document files were left sitting
on disk under `uploads/wallet/` untouched and still fully decryptable,
with the DB rows that reference them still intact (just re-pointed to a
fake address no one can sign in as). For a feature whose entire purpose
is "let a user make their personal data go away," silently keeping their
passport scan on disk forever is a real, live privacy gap, not a cosmetic
one.

Fixed by rewriting `tms_anonymize_user()` so `tbltraveldocuments` is
handled as an explicit exception before the generic anonymize loop runs:
it now reads every `StoredFilename` still on file for that user's email,
`DELETE`s the corresponding `tbltraveldocuments` rows outright (inside
the same transaction as the rest of the anonymization), and only once
that transaction has committed successfully, unlinks each file from
`uploads/wallet/` on disk. `tbltraveldocuments` was deliberately *not*
removed from `tms_privacy_tables()` — that same list is also read by
`tms_export_user_data()` (the "export my data" feature), which still
needs to know this table exists so wallet-document metadata is included
in a user's data export; after the explicit delete runs, the generic
loop's `UPDATE ... WHERE UserEmail = :old` on that table simply matches
zero rows, a harmless no-op.

Two unrelated things surfaced while building the QA harness for this,
both worth recording. First, `admin/` turns out to have its own local
`admin/includes/` shim folder containing only a `config.php` forwarder —
no mirror of `includes/wallet-crypto.php` — so a script living in
`admin/` that does a bare `include('includes/wallet-crypto.php')` 404s
(PHP resolves a bare relative include against the *including script's
own* directory when no include_path match exists elsewhere, not against
the project root). Confirmed live via the exact fatal error in
`error.log`, fixed by anchoring with `__DIR__` and `chdir()`ing to the
project root at the top of the test script, matching the cwd real
production code always runs under. Second, and unrelated to this round's
actual bug: the real `.env` on this install has no `WALLET_ENCRYPTION_KEY`
line at all (only `.env.example` documents it, marked "REQUIRED for
Travel Wallet uploads") — meaning `tms_wallet_encryption_key()` currently
returns null and `frontend/wallet.php`'s real upload handler is refusing
every new wallet-document upload on this box right now. That is a
genuine, currently-live, pre-existing bug, entirely separate from
anything this round touched — `.env` cannot be edited through this
session's file-delivery tools, so it's disclosed here rather than
silently worked around: whoever has direct access to this machine needs
to add a real `WALLET_ENCRYPTION_KEY` (a 64-character hex string, i.e. 32
random bytes) to `.env` before new Travel Wallet uploads will work. The
test itself sidestepped this by generating its own throwaway encryption
key locally, used only to build a realistic encrypted fixture file
on disk (via the same `tms_wallet_encrypt_bytes()` function
`wallet.php` uses) — the actual thing under test, whether
`tms_anonymize_user()` deletes an *existing* file, doesn't depend on new
uploads working.

Verified live end-to-end with a brand-new, single-use throwaway test
account (never one of the reusable shared QA accounts, since this
specific test destroys the account by design): created directly via SQL
with a locked random password hash (never typed into any form), one real
encrypted fixture file written to `uploads/wallet/` through the same
encrypt-and-store logic the real upload handler uses, and a matching
`tbltraveldocuments` row. Confirmed via a `check` step that the file
genuinely existed on disk and the DB row genuinely referenced it before
anonymizing. Called `tms_anonymize_user()` for real (not a simulation) —
it returned `true`. A follow-up `check` then confirmed: the user row was
anonymized (`EmailId` rewritten to a `deleted-user-26-...@deleted.invalid`
address, `DeletedAt` set), zero `tbltraveldocuments` rows remained for the
original email, and the file was gone from disk — independently
reconfirmed by listing `uploads/wallet/` directly, which showed nothing
left but the folder's own `.htaccess`. QA helper script
(`admin/test-round94-diag.php`) disabled to a 404 stub and confirmed dead
on both device paths. This round did not touch `.env` (outside available
tools) and did not remove `tbltraveldocuments` from
`tms_privacy_tables()`, for the reasons above.

## Round 95 — AI Evaluation suite: real content checks, plus a critical fix uncovered while building them (item 22)

Set out to extend `AIEvaluator.php` (item 22, "AI Evaluation suite") beyond
its existing "did the AI call itself succeed" telemetry check to actually
test some of the specific things item 22 names: factual accuracy,
hallucination, package accuracy, refusal behavior. Added three new eval
cases with real, independently-verifiable ground truth (never a second AI
call judging the first, which would just be one unreliable model grading
another): `package_accuracy` cross-checks every package TravelRecommender
recommends against real `tbltourpackages` rows (a `package_id` that
doesn't exist, or a `package_name` that doesn't match the real row, is a
hallucinated claim about actual inventory); `factual_accuracy_policy` asks
the site assistant chatbot a cancellation-refund question whose correct
answer ("25%") is stated verbatim in the assistant's own system prompt;
`refusal_offtopic` asks the chatbot to solve an unrelated coding request
and checks it declines and redirects rather than doing the homework, per
the system prompt's own "never discuss unrelated topics" instruction. A
new opt-in `gate_on_sanity` flag on a case makes its content check an
actual pass/fail gate — every case that existed before this round keeps
its original shape-only, non-gating behavior exactly as it was (the
dashboard's existing "(⚠ succeeded but response shape looked off)"
warning annotation is untouched for those).

While standing up a QA harness to run these new cases live, the harness
kept reporting real, successful-looking responses with **no** telemetry
row at all — for every one of the 9 pre-existing cases too, not just the
3 new ones. Digging into why turned up something much bigger than
anything this round set out to fix: `AIClient::call()`,
`::askWithImage()`, and the private `httpPost()` behind them were still
verbatim the pre-Round-34 Gemini-hardcoded implementation, referencing
`$this->apiKey`, `$this->model`, and `self::GEMINI_BASE` — none of which
exist anywhere on the class after Round 34's provider-abstraction
refactor (the constructor only ever sets `$this->provider`). That meant
`empty($this->apiKey)` was always true, so **every single real AI
feature** — the travel recommender, sentiment analyser, itinerary
planner, both budget tools, the crowd predictor, the AI package
generator, the site assistant chatbot, and the natural-language search
parser — has been silently returning the generic "AI assistant is not
switched on for this site yet" fallback since Round 34, regardless of
having a valid `GEMINI_API_KEY` configured in `.env`. It also masked a
second, worse bug one line further down: `self::GEMINI_BASE` isn't a
defined constant anywhere in the file, so a real API key would have hit
a fatal "Undefined constant" error the moment the first bug was fixed in
isolation. Separately, `AIClient::askWithImage()`'s signature had no
`$feature` parameter at all, while `api/ai.php`'s image-classifier call
site calls it with a named `feature: 'image_classifier'` argument — a
guaranteed fatal "Unknown named parameter" error, not a graceful
fallback, for every AI Image Analyzer request.

This is exactly the kind of gap Round 34/35's own telemetry-plus-eval
system was built to catch, and it worked: nobody had actually looked at
what the eval suite's ground-truth telemetry check was reporting until
this round ran it end-to-end for real. Fixed `call()` and
`askWithImage()` to build their request through
`$this->provider->buildTextRequest()` / `buildImageRequest()` and
interpret the raw HTTP response through `$this->provider->parseResponse()`,
exactly as the Round 34 docblock always claimed they did; genericized
`httpPost()` to a provider-agnostic retry/backoff loop that trusts each
provider's own `retriable` classification instead of a hardcoded
Gemini-specific status-code list; added the missing `$feature` parameter
to `askWithImage()`. `GeminiProvider.php` and `OpenAIProvider.php`
themselves were already correct and fully implemented — only the wiring
in `AIClient.php` was left behind mid-refactor.

A second, smaller bug turned up once real calls started succeeding: the
3 new eval cases were looking up their own telemetry row by their own
case key (`package_accuracy`, `factual_accuracy_policy`,
`refusal_offtopic`), but `TravelRecommender`/`SiteAssistant` tag their
own internal `AIClient` calls with their own feature names
(`recommender`, `site_assistant`) — so the lookup could never match.
Fixed with a `telemetry_feature` override field on those three cases
(the same pattern the existing `sentiment` case already used for its own
email-vs-identity mismatch).

Verified live end-to-end via the Browser pane, session-simulated as the
existing shared `qa_test_admin` account (superadmin role — no password
typed, `$_SESSION['alogin']` set directly after a `SELECT`-verify) against
the real AI Evaluation dashboard. The first real post-fix run produced
genuine successful Gemini responses with real token counts (sentiment:
104 prompt / 99 completion tokens; itinerary: 892 / 6417, a real
multi-day plan) before the account's free daily quota was exhausted
partway through (correctly classified by Gemini Provider's existing
quota-detection logic, not a new bug) — real, expected free-tier
behavior, not a defect. A second full run confirmed the `telemetry_feature`
fix: all 12 cases, including the 3 new ones, now correctly show a real
telemetry row (this time uniformly "today's usage limit" once the quota
was spent), where every one of them showed `telemetry: null` before the
fix. Both runs returned clean, well-formed JSON with no PHP fatal errors.
Could not observe an actual PASS on the 3 new content-accuracy cases
today, honestly, because the shared key's free daily quota ran out mid
first run — that will need a re-run on a day with quota headroom to see
green, distinct from confirming the mechanism itself works, which this
round did confirm. QA helper (`test-round95-admin-session.php`) disabled
to a 404 stub and confirmed dead live.

## Round 96 — Structured audit-log columns (item 47)

Added `ObjectType`/`ObjectId`/`OldValue`/`NewValue`/`RequestMethod`/
`RequestUri`/`UserAgent` columns to `tblactivity_log` via the existing
self-healing schema mechanism, extended `tms_log_activity()` with
optional before/after parameters plus automatic request-metadata
capture, and retrofitted the sensitive/stateful mutation call sites in
`manage-admins.php`, `edit-package.php`, `manage-coupons.php`,
`manage-package-addons.php`, `manage-bookings.php`, and
`manage-pages.php` to pass real old/new values. `admin/activity-log.php`
now shows an Object and a Change (`old → new`) column, falling back to
`—` for rows/sites that don't have the data.

Live testing on `manage-coupons.php` surfaced two real problems, both
fixed the same round: a `$dbh->lastInsertId()` call placed after
`tms_notify_all()` (which does its own insert) was logging the wrong
coupon id — fixed by capturing the id immediately after the coupon
`INSERT`; and, separately, a genuine testing mistake where the coupon
table's cosmetic `#` row counter was used instead of the real database
id, accidentally toggling the real `WELCOME10` coupon inactive — caught
by reading the actual hidden `id` field on each row, and corrected
in-round (`WELCOME10` restored to Active, the real QA test coupon
properly deleted, list confirmed restored). QA helper
(`test-round96-admin-session.php`) disabled to a 404 stub and confirmed
dead live.

## Round 97 — RBAC fail-open-to-superadmin fixed (items 44/46)

A fresh cross-check of the master prompt against actual code found
`tms_admin_role()` returning `'superadmin'` (the highest privilege level)
whenever its Role-lookup query threw for any reason — a transient DB
hiccup could silently promote any logged-in admin, including a
deliberately low-privileged one, to full access. Fixed the exception
branch to fail closed (`return ''`) instead, which correctly makes
`tms_admin_require_role()` deny the request with the existing 403 page.
Left the separate, deliberate NULL-Role fallback (for the pre-RBAC seed
`admin` account) untouched — that one is legacy compatibility, not a
bug. Verified live via a disposable QA harness that exercised both the
unchanged happy path and the fixed exception path side by side, plus a
plain dashboard-load regression check; QA helper disabled to a 404 stub
and confirmed dead.

## Round 98 — AI Evaluation suite gains a real weather-accuracy check (item 22)

Added a `weather_accuracy` eval case that independently re-fetches the
same real Open-Meteo forecast `ItineraryPlanner` uses internally, and
checks that when it shows a strong rain signal, the AI's own itinerary
narrative actually acknowledges it — closing one of the three gaps
Round 95 left explicitly open. "Booking accuracy" was investigated and
deliberately left undone: testing it safely would need a synthetic row
in the core `tblbooking` table, which several admin dashboards count
unconditionally with no way to exclude it — a bigger, riskier change
than this round's scope. Verified the new case runs cleanly end-to-end
(13 cases total, no crashes); the shared eval key's daily quota was
already exhausted so no case could show a live green pass today (a
known, recurring, external constraint, not a new bug) — separately
confirmed the new logic itself is correct via an isolated unit-test
harness (5/5 scenarios passed) and a live check of the real weather
fetch. QA helpers disabled to 404 stubs and confirmed dead.

## Round 99 — Fixed a real broken "View & Book" link in the AI Recommender (items 19/31/66)

While auditing the recommendation-transparency items, found that
`TravelRecommender.php` built every recommended package's link as
`?pid=` while `package-details.php` only ever reads `?pkgid=` — and a
missing `pkgid` clamps to package id 1 rather than erroring. Confirmed
live: every "View & Book" click from the AI Recommender silently landed
on the wrong package (always #1) regardless of what was actually
recommended. Fixed both occurrences (the real AI path and its
deterministic fallback) in both `backend/` and `includes/` copies,
verified live that recommendation links now carry distinct, correct
package ids and land on the right page. `getMatchScore()`'s separate
dead-code/fabricated-fallback issue and item 31's literal 7-factor
weighted scoring are noted as good next candidates, not yet built.
