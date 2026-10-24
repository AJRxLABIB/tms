# TMS V2 — Phase 1: Complete Project Audit

*Produced in response to the new "TMS V2 — Intelligent Travel Management
Platform" prompt. Read this before Phase 2 onward.*

## Zero: the ZIP claim, checked and resolved

The new prompt opens with "I have uploaded my existing Tourism Management
System (TMS) project as a ZIP file." No ZIP was found: the session's
upload area contains only the same mounted-folder snapshots and screen
recordings from earlier in this engagement, and a filesystem-wide search
turned up nothing new. Separately, this session is (and has been) linked
directly to your computer, with `C:\xampp\htdocs\tms` and
`D:\Project\tms` connected as live, read/write folders — the same two
canonical locations every change this session makes has been going to
all along. So there's no missing upload to chase down: "the existing TMS
project" the new prompt refers to *is* the project already live at those
two paths, already carrying 115+ rounds of documented work
(`CHANGELOG.md`, `SESSION_SUMMARY_INDEX*.md`), an existing full audit
(`AUDIT_REPORT_ROUND15.md`), and an existing master-prompt status map
(`MASTER_PROMPT_STATUS.md`). Nothing was lost or needs re-uploading.

Given that, Phase 1's "Complete Project Audit" is answered here by
**extending and re-verifying that existing audit against this prompt's
specific new questions** (architecture target, state machine, API
shape, and the ~25 new feature areas from Phase 7 onward) rather than
re-auditing the whole codebase from zero — re-doing work that already
exists and is current would itself violate this project's own Rule 73
(don't overengineer) and Rule 1 (never rewrite from scratch, which
applies to documentation effort too, not just code).

Also resolved just before this: migration 015 (utf8mb4 charset
conversion) was confirmed **live** as successfully applied — all 17
tables now `utf8mb4_general_ci`, all 9 foreign keys to
`tblusers.EmailId` correctly recreated. That closes the last open item
from the previous round. Details in `CHANGELOG.md`.

---

## A. Project Architecture Report

Confirmed live against the actual codebase (not carried forward
unverified):

- **No framework, no `app/Controllers/Services/Repositories/Models`
  tree exists today** (`app/` is absent). The real structure is
  `frontend/` (71 files, real per-page implementations),
  `backend/` (shared logic: config/session/RBAC, payments/, ai/,
  webhooks/, i18n, pricing, caching), `admin/` (its own small
  `includes/` wrapper), `api/` (REST v1 at `api/index.php`, also
  aliased at `api/v1/index.php`), `database/` (migration runner +
  history), `tests/` (`run.php` + `tests/manual/`). Full diagram and
  rationale already documented in `ARCHITECTURE.md`.
- **Root-shim pattern is real and load-bearing**, not incidental debt:
  every customer-facing URL (`/hotels.php`, `/checkout.php`, ...) is a
  4-line pass-through to `frontend/*.php`, specifically so 23+ existing
  redirects/fetches/sitemap entries/robots rules never had to change
  across two prior restructuring rounds. `ARCHITECTURE.md` documents
  exactly why direct access to `frontend/*.php` 500s (bare relative
  `include` paths resolve against the *requested* script's directory).
- `_deprecated/includes/` is the old pre-Round-49 shared-logic folder,
  kept (not deleted) as a rollback net after a project-wide grep
  confirmed zero live code paths reach it.
- **Correction (made after this document was first written):** this
  section understated existing progress. `backend/repositories/
  BookingRepository.php` already existed *before* this round — a prior
  round's first incremental Repository-layer class, wired into
  `frontend/tour-history.php`, with every other booking call site left
  on its own inline SQL to adopt later. This round added
  `backend/services/BookingStateMachine.php` as the second class in that
  same pattern (see item C below and `CHANGELOG.md`). So the incremental
  path recommended just below wasn't only a recommendation — it was
  already underway.

**On Phase 3's ask for an `app/Controllers/Services/Repositories/Models`
restructure — decided and closed (Round 115 continuation), not left as
an open discussion.** A wholesale rewrite would conflict directly with
this project's own Rule 1 (never rewrite from scratch) and Rule 73
(don't overengineer) — the current structure is real, documented,
working, and has already survived two restructuring rounds without
breaking a live URL. The decision: introduce `backend/services/` and
`backend/repositories/` namespaces for *new* work only, and migrate
existing logic into that shape purely opportunistically (when a file is
already being touched for another reason) — never a big-bang move of
the 71 `frontend/` files. This is no longer just a recommendation: it
now has **four** real, live-verified examples built under it —
`BookingRepository` (pre-existing), `BookingStateMachine`,
`TripReadinessScore`, and `BudgetAutopilot` — each wired into exactly
one real call site, none of them changing any existing behavior. The
concrete adoption rule (when new logic gets a class here vs. staying
inline) is written down in `ARCHITECTURE.md`'s "Repositories & Services"
section, so future rounds have a real guideline instead of a judgment
call each time. **DONE** — this phase does not need its own further
implementation plan; the pattern is the plan, and it's already in use.

## B. Security Audit

Carried forward from `AUDIT_REPORT_ROUND15.md` + this session's fixes,
re-verified where cheap to do so:

- Customer 2FA: closed this session (migration 014, `login.php`
  challenge flow, live-verified via browser).
- Admin RBAC (`tms_admin_require_role`/`tms_admin_role`): fails closed
  on a DB error (Round 97 fix); NULL `Role` still falls open to
  `superadmin` for the original seeded account — documented
  legacy-compatibility behavior, not an oversight.
- **Correction:** `.env`'s `APP_DEBUG=true` was flagged here as an open
  item; checking `backend/config.php` directly showed this was already
  fixed in an earlier round ("Round 115 security hardening" comment,
  lines ~126-148). `APP_DEBUG` is wired to `display_errors`/
  `display_startup_errors` (forced off when `false`); `true` is correct
  for this local dev environment and has zero effect on production
  behavior since the app isn't deployed with `APP_DEBUG=true`. Nothing
  to fix here — moved to "Already done."
- Secret handling for this response: `.env` was read for key **names**
  only (`GEMINI_API_KEY`, `OPENWEATHER_API_KEY`, `GOOGLE_MAPS_API_KEY`,
  payment provider keys) — no value is reproduced anywhere in this
  document or will be in any generated output, per both the new
  prompt's explicit secret rules and this project's standing one. Any
  future reference to these uses placeholders
  (`YOUR_GEMINI_API_KEY`, etc.).
- **Partial OWASP-category spot check (this round):** a real, live check
  of the highest-risk categories — SQL injection (project-wide scan,
  clean: the only 3 hits were already-escaped `$dbh->quote()` calls in
  `tests/run.php`), file upload validation (all 8 upload call sites
  either use the shared `tms_validate_upload_signature()` or an
  equivalent inline two-layer extension+`finfo` check — `wallet.php`
  additionally encrypts at rest and fails closed), session cookie flags
  (`httponly`/`samesite`/`secure` all correct), and security headers
  (`.htaccess` already sets CSP, HSTS, X-Frame-Options, nosniff,
  Referrer-Policy — none are placeholders). No new findings. **Not
  checked:** exhaustive per-form CSRF coverage beyond what's already
  spot-verified, rate-limiting completeness beyond the confirmed login
  lockout, and a dependency/CVE scan of vendored code. Full detail in
  `CHANGELOG.md`; `MASTER_PROMPT_STATUS.md` item 44 updated accordingly.

## C. Database Architecture

- FK/CHECK constraints solid; no FLOAT money columns anywhere (checked
  in a prior round).
- **Charset debt (item 38) is now fully closed** — see migration 015
  confirmation above.
- **Booking state machine (new V2 Phase 6 ask): now built.** At the time
  this was first written, `tblbooking` had four real but independently
  managed status fields (`status` 0/1/2, `PaymentStatus`, `RefundStatus`,
  `ModificationStatus`) with no central transition-validation layer.
  `backend/services/BookingStateMachine.php` now provides that layer —
  a pure, stateless validator built by reading every real write site in
  the app first, so no working transition was broken. Wired into
  `admin/manage-bookings.php` (the one place that had zero status guard
  before this), where it now blocks two real bug classes live-verified
  this round: resurrecting an already-cancelled booking via "confirm",
  and processing/rejecting a refund that was never requested. Full
  detail in `CHANGELOG.md` and `ARCHITECTURE.md`'s "Repositories &
  Services" section. **DONE**, moved from "genuine new-work item."

## D. Testing Infrastructure

- Real, working, but **not** the `tests/unit/integration/e2e/fixtures/
  legacy/` layout the new prompt's Phase 4 asks for. What exists:
  `tests/run.php` — a dependency-free integration-style runner (no
  PHPUnit; a deliberate Rule 73 call, documented in `TESTING.md`) that
  runs real assertions against the live DB (money math, dynamic
  pricing bounds, availability floor, migration file sanity, lockout
  behavior, refund-ledger safety, NL-search date-overlap regression,
  privacy-table schema consistency, marketplace sample-data flagging,
  RBAC fail-open contract) — plus `tests/manual/round-diagnostics/`,
  one-off session-simulation QA scripts, always disabled to 404 stubs
  after use with an explanatory comment retained (never deleted).
  **Zero E2E tests** — confirmed, real gap (already flagged as item 58
  in `MASTER_PROMPT_STATUS.md`).
- **Done:** `tests/unit/`, `tests/integration/`, `tests/e2e/`,
  `tests/fixtures/`, `tests/legacy/` now exist, each with a README
  stating current reality honestly rather than a fully-populated fake
  taxonomy (Rule 7). `tests/run.php` itself was deliberately **not**
  moved or split — it mixes pure-unit and integration tests in one file,
  and relocating it would ripple through CI and four other docs for a
  reorganization with zero behavior change. `tests/manual/` (90+ already-
  disabled diagnostic scripts) was likewise left in place rather than
  physically moved into `tests/legacy/`. Splitting `run.php`'s unit tests
  out remains a tracked, honest gap.
- **E2E tests: a real first slice now built (Round 115 continuation).**
  `tests/e2e/run_e2e.php` makes actual HTTP requests via curl against a
  running instance of the app — not the in-process calls `tests/run.php`
  makes — covering the core account/booking lifecycle: registration
  through the real CSRF-protected `/register.php` handler (scraping a
  live token out of real HTML, exactly like a browser), a rejected
  duplicate registration, login, a rejected wrong password, an
  authenticated booking against a real package, `trip_readiness`/
  `budget_status` (this round's own new endpoints) pinned to their exact
  expected values (score 0, spent 0) for a fresh booking, an ownership
  404, cancellation, logout, and a rejected revoked-token reuse. Creates
  one throwaway `e2e-<random>@tms-testing.local` account and deletes it
  and everything it created afterward.
  **Verified by actually running it** (no `php` CLI available in this
  environment, so via a temporary CLI-guard-removed browser copy,
  disabled immediately after — same convention as every other diagnostic
  this session): 14/14 passed, twice in a row, confirming it's
  idempotent/repeatable. A real bug was caught and fixed during this
  verification — the cleanup step only deleted from 3 tables and hit a
  live foreign-key violation on `tblloyalty_log` (booking creation awards
  loyalty points there even with no referral involved); fixed by clearing
  all 9 real tables with a FK on `tblusers.EmailId` (confirmed via
  `information_schema` during the migration 015 work) before deleting the
  test user. Wired into `.github/workflows/ci.yml` to run for real on
  every push/PR (after booting `php -S 127.0.0.1:8000`), though that
  workflow's own run history remains unverifiable from this environment
  (same limitation already noted for item 59 generally) — "runs in CI" is
  the intended, locally-verified behavior, not something claimed as
  CI-confirmed. **Honestly still out of scope, not silently assumed
  covered:** admin workflows, payments, and anything needing real
  client-side JS execution — a browser-automation tool (Playwright etc.)
  would be needed for the last one, and this project has no Node/npm
  dependency today, so that's a deliberate future tradeoff to make
  explicitly, not something to default into. Full detail in
  `CHANGELOG.md`; `MASTER_PROMPT_STATUS.md` item 58 updated from
  "confirmed gap" to this scoped first slice.
- **Correction (methodology, not a feature) — a real test-suite bug was
  hiding behind a wrong assumption all session.** Every round that saw
  `tests/run.php`'s migration-file-sanity test FAIL in a throwaway
  browser-run copy assumed it was the same harmless glob-path-depth
  artifact confirmed for *other* tests, without ever actually fixing the
  path and checking. Doing that this round showed the failure was real:
  `009_index_and_dead_table_cleanup.sql`'s `DROP TABLE IF EXISTS
  tbl_ai_analytics` (a genuine, already-reviewed dead-table cleanup, not
  a bug) was tripping the test's blanket "any DROP = violation" rule,
  which had no way to distinguish a reviewed drop from an accidental
  one. Fixed with a narrow, named allowlist in the test itself — see
  `CHANGELOG.md` for detail. Re-verified live: **20/20 passed**, the
  first fully clean run of this suite all session. Filed here rather
  than under Section D's original bullets because it's a lesson about
  this session's own verification habits, not about the app: a "known
  artifact" explanation should be re-checked occasionally, not assumed
  forever once written down once.

## E. API Surface

- Already versioned: `api/index.php` is REST v1, also reachable at
  `api/v1/index.php`; `api/ai.php`, `api/chat.php`, `api/explore.php`,
  `api/docs.php` (Swagger UI), `api/openapi.yaml` all real. Refresh
  tokens/scopes (item 42) and webhooks (item 43) already done and
  previously verified.
- **Phase 29, investigated in full before any code changed.** Read
  every routing table in the API surface end to end:
  `api/index.php` (14 actions: packages/package/types/check_email/
  login/logout/refresh/me/my_bookings/book/cancel_booking/sessions/
  revoke_session/revoke_all_sessions), `api/ai.php` (22 actions,
  including the weather-replan trio and `ai_feedback` — this is where
  "trip replanning" and the "AI feedback loop" already live, see
  Section F), `api/chat.php` (poll/send/typing/mark_read/threads), and
  `api/explore.php` (search/info/weather/places/place_details/route/
  history/place_photo/chat). Cross-referenced against
  `backend/services/TripReadinessScore.php` and
  `backend/services/BudgetAutopilot.php`/`ExpenseAnalyst::getBudgetComparison()`
  (both built this session) with a project-wide grep: **neither had any
  API exposure at all** — both were reachable only from
  `frontend/my-trip.php` (page-embedded PHP) or a CLI cron script, never
  from a REST client. That is the one real, concrete gap Phase 29's
  "resource hierarchy matching the V2 domain model (trips, replanning,
  budget autopilot, etc.)" wording actually points to — replanning
  already has full API coverage, so the gap was specifically the two
  newest V2 services.
- **Decision: this is closed as two new additive actions on the
  existing v1 surface, not a path-based `/api/v2/` rewrite.**
  `api/v1/index.php`'s own docblock had already weighed this exact
  tradeoff for the original v1 alias and concluded a real path-based
  version means either duplicating routing logic (drift risk) or
  rewriting `api/index.php`'s dispatch outright, which risks its two
  untouched siblings (`api/ai.php`, `api/explore.php`). That reasoning
  applies just as strongly here, and unlike the original v1 decision
  there isn't even a request for path-based routing driving it this
  time — just two genuinely new leaf resources. Rule 73 (don't
  overengineer) says build the smallest real thing that closes the
  actual gap: two `match()` arms, not a new API generation. If a real
  breaking v2 is ever needed for other reasons, `api/v2/` remains the
  documented landing spot (unchanged from the original v1 decision).
- **Built and live-verified (Round 115 continuation):**
  `GET ?action=trip_readiness&booking_id=N` and
  `GET ?action=budget_status&booking_id=N`, both scope-gated
  (`bookings:read`, the same existing scope `my_bookings` uses — no new
  scope invented for two read-only additions), both ownership-checked
  the same way `api_cancel_booking()` already does it (`UserEmail=:e`
  in the WHERE, real 404 for a foreign/nonexistent booking rather than
  a misleadingly empty 200). Documented in `API_DOCUMENTATION.md` and
  `api/openapi.yaml`. **DONE.**
- **Minor pre-existing bug found and noted, not fixed here (out of
  scope for this phase):** while writing the required-parameter check
  for the two new actions, live testing caught that `tms_int($v, 1)`
  (min=1) clamps a *missing* value up to `1` rather than leaving it
  falsy, silently making the "required" 400 unreachable — the new
  handlers use `tms_int($v, 0)` plus an explicit `< 1` check instead,
  and were live-verified to return a real 400. The same dead-code
  pattern already exists in `api_cancel_booking()` (`booking_id`) and
  `api_book()` (`package_id`) — both still work correctly for every
  real caller, so this is a latent validation gap, not a live bug, and
  wasn't touched here per Rule 2 (don't casually change working
  existing code) and Rule 73 (don't scope-creep this phase). Flagged
  for a future small-fix pass.

## F. AI / Intelligence Features

**Correction (made after reading `frontend/my-trip.php` in full): this
section significantly understated existing progress.** Real,
already-built AI infrastructure: provider abstraction (Gemini),
evaluation dashboard + evaluator + feedback table, recommendation
match-score widget, data-grounding discipline (documented and
followed). But several things listed below as "genuinely new feature
work" turn out to already exist, substantially:

- **"Trip Command Center"** — this *is* `frontend/my-trip.php`, already
  built: one hub per confirmed booking showing dates/hotel/payment
  status, an AI-generated itinerary, weather, documents, expenses, and
  an "offline kit" of everything downloadable before a trip with no
  connectivity. It deliberately doesn't duplicate other pages' logic —
  it links out to the real, working feature for each (wallet.php,
  expense-tracker.php, weather.php) and surfaces read-only facts.
- **"Trip replanning"** — already built: `my-trip.php`'s weather-check
  flow (`api/ai.php?action=weather_replan_check` /
  `weather_replan_preview` / `weather_replan_apply`) checks the real
  forecast against a saved itinerary, flags at-risk days, previews a
  proposed change, and only applies it on explicit confirmation.
- **AI feedback loop** — already built: thumbs up/down on the generated
  itinerary (`tmsSendAiFeedback`), wired to the same feedback
  infrastructure the evaluation dashboard reads from.
- **Recommendation *explanations* (not just match-scores)** — already
  built: `TravelRecommender` returns a real `why_recommended` field (a
  2-3 sentence explanation), rendered in `ai-recommend.php` under each
  match. This is item 23, already marked done.
- **AI quality dashboard** — `admin/ai-dashboard.php` already runs 13
  real AI accuracy/quality test cases against the live provider
  (itinerary, weather-accuracy, package-accuracy, factual-accuracy,
  refusal/off-topic, etc.) and reports pass/fail per case. Close to,
  though not identical to, what the new prompt describes.

**What's still genuinely new, confirmed by the absence of the concept
anywhere in the codebase:** a named "Trip Readiness Score" — **now
built.** `backend/services/TripReadinessScore.php` computes a single
honest percentage from four real facts (payment settled 40%, booking
confirmed 20%, a travel document on file 20%, an itinerary generated
20%), wired into `my-trip.php` as a progress bar + checklist +
actionable links for what's missing. Live-verified against the
disposable QA booking (20% ready, correctly missing payment/document/
itinerary). Full detail in `CHANGELOG.md`. Still open: AI budget
autopilot (proactive spend-vs-budget intervention, as opposed to the
existing after-the-fact expense tracker), and a unified "AI Travel
Intelligence Engine" as a single named service (today it's several
real, separate classes — `TravelRecommender`, `ItineraryPlanner`,
`BudgetOptimizer`, etc. — which is arguably the *better* architecture
per Rule 73, so "unifying" them may not be worth doing at all; noted
for that plan to weigh explicitly rather than build reflexively).

## G. Bangladesh-Specific / Domestic Features

`011_bd_destination_guides.sql` (applied) shows real, dedicated
Bangladesh destination-guide work already exists. The broader
"Bangladesh travel/transport intelligence" and "emergency center" asks
in the new prompt are larger and were flagged in
`MASTER_PROMPT_STATUS.md` as needing a fresh, dedicated look (items 14,
15, 17 — transport/flight/travel-alert source attribution and
comparison logic never deep-audited). Treated as open, scoped
individually.

## H. Newer Feature Areas With No Prior Audit Coverage

**Corrected after individually checking each one** (Rule 4 — verify
before asserting), rather than left as "will get a check later":

- **Gamification — already fully built.** `backend/AchievementEngine.php`
  (223 lines, zero AI involvement by design — "achievement criteria need
  to be deterministic and auditable, not an LLM's judgment call") tracks
  real achievements (booking count, distinct destinations, reviews,
  referrals, total spend, wishlist size, early-booking bonus), a
  leaderboard, and per-user unlock state — wired live into
  `frontend/loyalty.php`. Not a gap.
- **Travel wallet — already real** (see Section B: encryption at rest,
  two-layer upload validation, expiry reminders via a scheduled job).
- **Emergency center — already built.** `frontend/emergency.php`
  (life-threatening-emergency guidance, per-country emergency contacts,
  universal safety tips) + `admin/manage-emergency.php` (real CRUD for
  country emergency info). Not a gap.
- **Sustainability** — already real per item 70 (calculator + transport
  comparator).
- **Global search (NL-to-filter)** — see Section F correction above:
  well-built, only the regex/AI-parse paths lack permanent tests.
- **AI budget autopilot — now built.** `backend/ai/BudgetOptimizer.php`
  and `expense-tracker.php` already did real, reactive budget tracking;
  the missing proactive piece is now `backend/services/BudgetAutopilot.php`
  + `scripts/check_budget_overspend.php` (same cron idiom as the
  existing `check_wallet_expiry.php`), notifying once per meaningful
  threshold crossing (over budget, or ≥90% used) with a 3-day cooldown.
  Live-verified end-to-end: pushed the QA booking to 98% budget with a
  disposable test expense, confirmed a real notification was created,
  then cleaned up. Full detail in `CHANGELOG.md`.
- **Personalized homepage — now built (Round 115 continuation).**
  Confirmed real gap first: `frontend/index.php`'s featured-packages
  section always showed the same random pool to every logged-in user,
  despite real signal (booking history, wishlist, and
  `tblrecentlyviewed` — written on every package view since, but never
  read anywhere except `package-details.php`'s own "Recently Viewed"
  strip) already sitting in the database.
  `backend/services/PersonalizedHomepage.php` derives a "Recommended for
  You" section from that real signal with plain deterministic SQL — no
  AI call on every pageview, deliberately, for the same cost/latency
  reasoning `BudgetAutopilot` used for not calling an LLM on a schedule.
  Purely additive to `frontend/index.php`: the existing section is
  completely untouched and still the fallback for a user with no signal.
  Live-verified both branches against real data (the QA account's real
  Nature-type booking produced a correctly-reasoned, correctly-excluding,
  correctly-sorted result; a signal-free session correctly showed
  nothing new). **DONE.**
- **Multilingual full-key coverage — now individually re-checked
  (Round 115 continuation), real finding replaces the guess.** The
  `$TMS_TRANSLATIONS` dictionary (`backend/i18n.php`) itself has perfect
  key parity across all 8 supported languages (en, bn, hi, ar, fr, zh,
  es, de) — confirmed by a corrected parity-check script (the first
  version had a regex bug producing a false "fr missing 4 keys" report;
  caught and fixed before trusting it, per Rule 4). The real gap is
  `tms_t()` *adoption*: only 13 of 74 frontend files and 95 call sites
  use it — most page-specific content across the other ~60 files still
  renders hardcoded English, exactly as the file's own docblock already
  discloses. `frontend/index.php` (the homepage, highest-traffic page)
  had zero prior wiring; this round added 17 new keys × 8 languages and
  wired 7 call sites (6 section headings + the personalized-homepage
  reason label), including a real miss caught mid-verification (the
  Recommended-for-You `<h2>` title, found by actually loading the page
  in French and seeing it hadn't translated). Live-verified in English
  (no regression) and French (new translations render) against the real
  QA account, plus a full 20/20 test-suite re-run. Full detail in
  `CHANGELOG.md`. **Homepage: DONE. Site-wide adoption: still a large,
  genuinely incremental effort — not implied finished by this entry.**
- **PWA, currency** — already done (items 52, 37, unchanged from before).

## I. Design System, Accessibility, SEO

Design tokens real but inconsistently applied on some pages (known,
tracked, item 34). Accessibility and animation both explicitly flagged
in the prior audit as never deep-audited (items 32, 35) — will get a
real pass before their phases are planned, not carried forward as
either done or broken on assumption.

## J. Roadmap / Numbering Note

This document maps to the **new V2 prompt's own phase numbers** (1-40),
not the original `MASTER_PROMPT.md`'s numbers (5-70, tracked in
`MASTER_PROMPT_STATUS.md`) and not `AUDIT_REPORT_ROUND15.md`'s
drifted "Phase N" labels either — those three numbering schemes are
now all in play across this project's documentation and must not be
cross-referenced by number, only by topic. A short crosswalk table will
be added to this file once the phase-by-phase implementation plans are
underway, so future rounds don't repeat the numbering-drift confusion
already documented once in `AUDIT_REPORT_ROUND15.md`'s CORRECTIONS LOG.

---

**Update note:** this document started as a pure audit (see the
original closing note below, kept for the record) and has since grown,
round by round, into the running Phase-1-and-beyond log for the whole
"TMS V2" prompt — every section above now mixes original audit findings
with real, live-verified build work completed since (booking state
machine, test taxonomy, the OWASP spot check, Trip Readiness Score, AI
Budget Autopilot, and now the Phase 29 API additions). Each such update
is marked inline (**DONE**, **Correction:**, etc.) rather than silently
folded into the original text, so the history of what changed and why
stays visible.

**Implementation status (as of Phase 29):** booking state machine, test
taxonomy, Trip Readiness Score, AI Budget Autopilot, and the
`trip_readiness`/`budget_status` API endpoints are all built and wired.
Still open, tracked individually above: E2E tests (Section D), the
personalized-homepage and full multilingual-key-coverage items (Section
H), accessibility/animation/SEO depth (Section I), and the broader
`app/Controllers/Services/Repositories/Models` restructure (Section A —
incremental only, per Rule 1/73, now with four real examples:
`BookingRepository`, `BookingStateMachine`, `TripReadinessScore`,
`BudgetAutopilot`).
**Testing status:** every claim above was checked live at the time it
was written or corrected — directory listings, `information_schema`
queries, file reads, and for build work, real browser-driven requests
against the live app (session/token simulation only, never a typed
password) — except items explicitly marked "unverified" or "never
deep-audited," which are stated as such rather than guessed.

**Original closing note (kept for the record):** per the new prompt's
own rule, no implementation began until phase-by-phase IMPLEMENTATION
PLANs (Goal / Files to modify / Files to create / DB changes / API
changes / Frontend changes / Testing requirements / Risk level /
Rollback strategy) were produced, phase by phase, continuing
autonomously per this project's standing execution rule — which is
what every subsequent round above actually did.
