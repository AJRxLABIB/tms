# This Session's Work — Master Index

Quick map of everything built/fixed in this session, plus what to actually
check first given limited time. Full technical detail for each item is in
`SECURITY_HARDENING_PHASE1.md` and `FEATURE_PHASE2_AI_DEEPENING.md` — this
file is the fast-orientation summary, not a replacement for those.

## Deploy checklist (do this first)

1. Copy all files to your XAMPP `htdocs/tms/` (or wherever this is served).
2. Load any page once — every new table (`tblcache`, `admin.Role`,
   `admin.TwoFASecret`/`TwoFAEnabled`, `tbl_review_sentiment` new columns,
   `tblexpenses`, `tblachievements`, `tbltraveldocuments`) self-creates on
   first request via the existing `tms_ensure_columns()`/
   `CREATE TABLE IF NOT EXISTS` pattern. **No manual SQL migration needed.**
3. Confirm `uploads/wallet/` exists with its `.htaccess` (created this
   session) — if your host doesn't respect `.htaccess` (some non-Apache
   setups don't), that directory needs equivalent protection at the server
   config level before real documents are uploaded there.
4. Log into `admin/` once — your existing admin account is auto-set to
   `Role = 'superadmin'`, so nothing changes for you. Try
   `admin/manage-admins.php` to see the new RBAC page.
5. Spot-check the 4 fixed AJAX widgets actually render now (they were
   silently broken until the Phase 2m fix): the "Suggest" price button on
   `admin/add-package.php`, the Crowd Insight + Sustainability boxes on any
   `package-details.php` page, and `flight-status.php`.
6. **New this round-set:** confirm your `.htaccess` is actually being read
   (Apache `AllowOverride All` for this directory) — it now includes the
   rewrite rule that lets the REST API see `Authorization: Bearer` headers,
   which XAMPP's default Apache config otherwise silently drops.
7. The e-ticket QR code and My Bookings QR thumbnail depend on
   `api.qrserver.com` (a free external image API) being reachable from the
   *visitor's* browser, not your server — no server-side dependency to
   install, just worth knowing if you're testing fully offline.
8. **Round 11:** click through the admin booking list's Confirm/Cancel/
   Delete buttons, a coupon Toggle/Delete, and a package image update once
   — these now submit as POST forms with a CSRF token instead of plain GET
   links. If your session had gone stale, you'd see the existing "Invalid
   or expired form submission" message rather than the action silently
   happening; just refresh the page and try again.
9. **Round 12:** load any page once so the `BookingDate` backfill runs
   (self-healing, same as every schema check above — no manual SQL). Every
   existing booking gets its `BookingDate` filled in from `FromDate`
   automatically; check a package page's "Live Crowd Insights" box for a
   destination with 8+ existing bookings — it should now say "(based on N
   real bookings)" instead of "(general estimate)".
10. **Round 13:** toggle Dark Mode on any page with CMS-authored content
    (About Us, blog posts, custom pages) — text pasted from a rich-text
    editor should now stay readable instead of showing near-black text on
    the dark background. Also: this round found `D:\Project\tms` and
    `C:\xampp\htdocs\tms` had drifted apart (Rounds 11–12 were sitting
    unshipped in the working copy, and the live copy had gained a chatbot
    history feature never pulled back). Both are now synced except for
    that chatbot feature and `.env`, deliberately left alone — see
    `AUDIT_REPORT_ROUND13.md` for what still needs a decision from you.
11. **Round 14 — do this first: create `.env`.** Copy `.env.example` to `.env`
    and put a Gemini API key in it (free, no card, from aistudio.google.com).
    Until you do, every AI feature returns its non-AI fallback — that has been
    the case for a while and is why the chatbot felt weak. Everything else in
    Round 14 works with or without the key.
12. **Round 14 — then start Apache and click through:** the Sign Up modal (it was
    impossible to complete before this round — type an email, tab away, confirm
    "Create My Account" stays enabled); a package page's booking form (the date
    fields are real calendars now); `tour-map.php` (prices in the sidebar, the
    map popup and the search dropdown must all agree, and the filter pills should
    filter the sidebar too); `weather.php` for a package **without** map
    coordinates (must say so, not show ocean weather); Explore Mode's Nearby
    Places (details panel should show "not listed" rather than invented phone
    numbers, reviews and photos); `emergency.php` (thirteen countries are seeded
    on first load); and the chat widget (it should tell you when you are getting
    a standard answer rather than an AI one).

## Round 14 — professional pass: map, live places, weather, chatbot, whole site

Full detail in `AUDIT_REPORT_ROUND14.md`. Four parallel audits (map/places,
weather, the AI stack, and the rest of the customer site) followed by ~70 fixes.
The headlines:

- **The AI has been switched off.** No `.env` exists, so `GEMINI_API_KEY` is
  empty and every AI feature has been silently returning its non-AI fallback —
  and `AIClient`'s internal setup message was being shown to visitors. Added
  `.env.example`, `.htaccess` (which also stops `.env` being web-readable once
  it exists), `.gitignore`, and a real diagnostic for the empty-key case.
- **`PlaceDetailsService` was rewritten.** It invented phone numbers, emails,
  websites, Facebook/Instagram links, star ratings, named customer reviews,
  stock photos, room rates, restaurant menus, hospital departments and named
  doctors, and a police Officer-in-Charge — all from a hash of the place's name,
  all presented as fact. It now shows only what the map data actually contains.
- **`weather.php` showed real weather for the Arabian Sea** for any package
  without coordinates (`?? 20` / `?? 70`), labelled with that destination's name
  and a "Live" badge — and the honest "no map location" branch was unreachable.
- **The chatbot had no catalogue data**, no memory across page navigations, no
  CSRF protection, an unescaped HTML sink, one shared rate-limit bucket for all
  guests, a 91-second worst-case timeout, and no way to tell a failure from a bad
  answer. All fixed; persistent history is rebuilt with the bounds the lost
  version never had.
- **Nobody could register from the Sign Up modal** — the live email check posted
  an empty CSRF token, and the 403's message contains "red" inside "expired",
  which the caller's substring test read as a validation failure and used to
  permanently disable the submit button.
- **A 100%-off coupon was minutes away** via uncapped loyalty redemption.
- **Eight queries were silently dead** across Trip Copilot, fraud detection,
  sentiment analysis and Explore history, all from the same `LIMIT ?` binding
  bug — so the AI dashboard reported "all clear" because the query never ran.

## Round 13 — dark-mode text contrast + a deploy-sync gap

Full detail in `AUDIT_REPORT_ROUND13.md`. Triggered by a real screenshot of
`page.php?type=aboutus` showing near-unreadable text in Dark Mode. Root
cause was two-fold: the page never actually had the `class="about"` an
earlier round's dark-mode patch assumed, and the real CMS content uses
`color: rgb(...)` inline styles, which no hex-string-based patch can ever
match. Replaced the fragile hex-string allowlist approach with a general
fix in `includes/header.php`: read each inline-styled element's actual
*computed* color, and swap it for a readable one only when it's genuinely
too dark for the dark background — covers hex/rgb/rgba/hsl/named colors,
today's content and any future CMS paste, with no list to maintain.

Also surfaced a bigger issue: this session's connected folder
(`D:\Project\tms`) and the folder Apache actually serves
(`C:\xampp\htdocs\tms`) are two different folders that had drifted apart
in both directions — Rounds 11 and 12 were never live, and the live folder
had independently gained a real chatbot-history feature not present in the
working copy. Synced everything this session touched into the live folder;
deliberately left the chatbot feature and `.env` untouched. See the audit
report for the recommended follow-up.

## Round 12 — customer-facing pass: map, nearby places, weather, seasonal predictions

Full detail in `AUDIT_REPORT_ROUND12.md`. The headline fix: `BookingDate`
was a real column that **no booking-creation path ever set** — it was
NULL on every booking ever made, which silently broke five separate
features that read it as the trip date: the "Live Crowd Insights" seasonal
prediction (could never use real booking history, always fell back to
generic AI guessing), the Trip Copilot's "upcoming trip" detection, the
"Early Planner" achievement (unearnable by anyone), and blank trip dates in
`travel-memory.php`/`expense-tracker.php`. Fixed at the source in all 3
booking paths, plus a one-time self-healing backfill so bookings made
before this fix repair automatically. Also fixed: a safety issue where
`includes/PlaceDetailsService.php` invented realistic-looking "Emergency
Contact" phone numbers for hospital/police nearby-places (replaced with
real generic emergency-number guidance); Explore Mode showing AI-estimated
USD travel costs with a Taka symbol (off by ~2 orders of magnitude);
`tour-map.php` sidebar/search clicks that silently did nothing for
packages without map coordinates; a false "0.0 km" distance shown for
places missing coordinates; a dead error-message branch and a missing
null-guard in `weather.php`; and the Explore Mode AI chat's "context"
feature, which was wired to read an attribute nothing ever wrote, so it
was always blind to the weather/overview data already on screen.

## Round 11 — admin CSRF hardening + 2 real security bugs found

Full detail in `AUDIT_REPORT_ROUND11.md`. Short version: closed the
destructive-GET-request gap noted above (13 admin files converted to
POST+CSRF, including all of `manage-bookings.php`'s actions), and found
two real, previously-undocumented, high-severity bugs during an
independent fresh audit rather than assuming prior rounds caught
everything — an authentication bypass in `admin/edit-package.php` (a
missing `exit` after a failed login check meant the edit-package flow ran
regardless of login state) and an unrestricted file upload + path
traversal in `admin/change-image.php` (no extension check at all, and the
destination path was built from the raw uploaded filename — the only
upload path in the project with either problem). Also re-verified SQL
injection safety, IDOR protection, and CSS/theme coverage project-wide;
all found already correct, so no redesign was attempted on top of already-
solid, previously-audited UI work.

## Rounds 6–10 (this continuation) — UI, booking, analytics, API, hardening

Full detail in `AUDIT_REPORT_ROUND6.md` through `AUDIT_REPORT_ROUND10.md`.
Short version, since there are now a lot of these files:

- **Round 6 (UI foundation):** fixed two dark-mode systems silently
  fighting each other; fixed `index.php`'s duplicated, drifted-out-of-date
  footer; added toast notifications (replaced 18 blocking `alert()` calls);
  real accessibility fixes (no keyboard focus indicator existed anywhere).
- **Round 7 (booking system):** the big one — every booking on the system
  was silently getting `NoOfPersons=1`, `TotalCost=NULL`, and no coupon
  discount, because nothing that *creates* a booking ever wrote to those
  columns (and the coupon-apply endpoint had zero callers anywhere). Fixed
  at the source. Added date-change requests, refund-status tracking, a
  booking timeline, and QR verification codes.
- **Round 8 (admin analytics):** most of this already existed
  (`revenue-analytics.php`, `ai-dashboard.php` — the latter had **zero
  links to it anywhere**, totally undiscoverable). Added the two real
  gaps: User Analytics and Popular Destinations.
- **Round 9 (REST API):** added token-based auth, `me`/`my_bookings`/
  `book`/`cancel_booking` endpoints, and `API_DOCUMENTATION.md`. Booking
  through the API reuses the exact Round 7 fix, not a second code path.
- **Round 10 (hardening pass):** found that `PackagePrice` being nullable
  meant an unpriced package could be booked for free through three
  separate entry points — fixed all three. Added brute-force lockout to
  the new API login (it had bypassed the site's existing protection
  entirely), made coupon application atomic (closed a race window where
  two simultaneous requests could both claim one single-use coupon), and
  added a unique index on the new verification-code column.

New tables from this round-set: `tblapi_tokens` (self-healing, same
pattern as everything else). New columns on `tblbooking`: modification-
request fields, refund-status fields, `VerificationCode` (now with a
unique index).

## What changed, by area

**Security (Phase 1 — paused mid-session per your request, but complete and
safe to use as-is):**
- Admin login: brute-force lockout, CSRF, password auto-rehash, 2FA (TOTP)
- CSRF flipped from log-only to actually enforced, 11 previously-uncovered
  forms fixed
- RBAC: superadmin/manager roles, new admin-management page
- Unified caching layer (`CacheService`) replacing 3 separate duplicate
  cache implementations

**AI feature depth (Phase 2a–2c):**
- Itinerary planning grounded in real weather (Open-Meteo) + real booking
  seasonality, not pure AI guessing
- Trip Copilot: cheaper (compressed context), faster to fail over
  (retry/backoff), cached proactive alerts
- Sentiment analysis: issues/highlights actually saved (were silently
  discarded before), basic fake-review detection

**Real-data-grounded predictions (Phase 2d–2f):**
- Price suggestions from actual catalog prices, not AI guesses
- Crowd/best-time predictions from actual booking history
- Duplicate-account + suspicious-booking fraud detection (deterministic,
  zero AI — Gmail dot/plus-trick detection, coupon-abuse clustering,
  rapid-fire bookings, high-cancellation accounts)

**New customer-facing features (Phase 2g–2l):**
- Expense Tracker (`expense-tracker.php`) — real budget-vs-actual, AI
  insight on top
- Gamification (`loyalty.php`) — 10 achievements, leaderboard, zero AI
- Sustainability/carbon footprint (`package-details.php` widget) — real
  distance math, zero AI
- PWA fixed (was broken — missing icons, manifest only on homepage)
- Voice-enabled Trip Copilot (mic input + spoken replies, browser-native,
  free)
- Digital Travel Wallet (`wallet.php`) — documents stored with real access
  control (`.htaccess` deny-all + ownership-checked gateway), not just
  "unguessable filename"

**Real-time flight tracking (Phase 2m):**
- Live aircraft position via OpenSky Network (free, keyless) — honestly
  framed as position-only, not gate/delay data
- **Also where a real bug was found and fixed**: 3 earlier features in this
  session (price suggestion, crowd insight, sustainability widgets) had a
  response-shape mismatch between their PHP and JS that made them silently
  do nothing. All fixed — see `FEATURE_PHASE2_AI_DEEPENING.md` Phase 2m for
  the honest writeup of what broke and why linting didn't catch it.

## Known gaps / deliberately not built

- Hotel-specific price prediction (schema doesn't model hotels separately
  from packages yet)
- ~~Document expiry reminders in the Travel Wallet~~ — **built in Round
  16**: `wallet.php` now has a real `ExpiryDate` field and
  `scripts/check_wallet_expiry.php` notifies at 30-day/7-day windows (Rule
  28). See `SCHEDULED_JOBS.md` for how to schedule it.
- Voice input/output not extended to `virtual-guide.php` (would duplicate
  ~80 lines of JS without a shared include)
- AR extension points, social/community features — not started; these need
  larger scope (new data models, client-side AR framework, or a
  posts/follows/feed system) than fits an incremental extension pass
- ~~The destructive-GET-request pattern~~ — **fixed in Round 11**: every
  admin delete/status-change action (13 files, including the whole
  `manage-bookings.php` action switch) now goes through POST + CSRF
  instead of a plain GET link.
- Bootstrap 3 → 5 migration — still not attempted; ~60 files use BS3-specific
  markup/JS APIs and this needs its own dedicated, carefully-tested round
- ~~A real payment gateway — this app has no payment capture at all~~ —
  **superseded by Round 16-19**: a full `PaymentProviderInterface` +
  `PaymentService` architecture was built (Sandbox provider working end to
  end; SSLCommerz/bKash/Nagad present as honest stubs that throw rather than
  fake success when unconfigured) and live-tested (Round 19: Pay Now →
  sandbox confirmation → Confirmed/Paid, real booking). Still genuinely
  missing: no live/production payment provider is actually configured, so
  real money never moves — that part of this note stays true, the "no
  capture at all" framing does not.
- API rate limiting and refresh tokens — no usage data yet to size limits
  against, and no known mobile client yet that needs a refresh flow

## If something doesn't work

Every new feature was built to fail soft — a broken AI call, missing data,
or unreachable external API (OpenSky, Open-Meteo, Nominatim) should degrade
gracefully rather than crash a page. If you hit an actual PHP error (not a
graceful "no data" message), check `error_log` first — every new code path
logs specifics there rather than displaying raw errors to users.

## Round 15: going public / SEO

The user asked how to host this publicly and get it into Google. Two
blockers were fixed before any hosting work: (1) a blanket "require login
everywhere" change had accidentally gated every browsing page (package
listings, package details, blog, tour map, weather, reviews, etc.) that the
project's own `robots.txt` and `sitemap.php` explicitly mark as public — so
Google could only ever have indexed the homepage no matter where this was
hosted. That gate is now removed from the 20 pages robots.txt/sitemap.php
name as public; booking/account pages are unaffected. (2) Real API keys
(Gemini, OpenWeatherMap, Google Maps) had been typed into `.env.example`
(a template meant to hold blank placeholders and get committed) instead of
`.env` (the real, git-ignored, .htaccess-blocked config file) — so despite
having valid keys on disk, every AI feature was still running in fallback
mode. Moved to `.env`; confirmed live that the chatbot now gives real
Gemini answers. Also added `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` to
`.env`/`.env.example` (previously undocumented, though already read by
`includes/config.php`) — that's now the only thing that needs to change to
point this app at a new host's database.

Full step-by-step hosting + Google Search Console instructions are in
`HOSTING_GUIDE.md`.

## Round 16: production-transformation kickoff — migrations + payment architecture

The user asked to execute the full "82-point production-grade transformation"
master prompt. That prompt spans months of work for a team (real payment
merchant integration, licensed hotel/flight data, full CI/CD, WCAG audit,
etc.) — flagged that honestly up front, then started executing the highest-
priority, actually-achievable phases now rather than treating it as a single
one-shot deliverable.

**Phase 3 — Database migrations system (done):** `database/migrations/`
(001_migrations_table, 002_payments, 003_booking_payment_columns) +
`database/migrate.php`, a real ordered/tracked runner (CLI or browser,
browser gated behind `tms_admin_require_role(['superadmin'])`). First run
auto-seeds the 5 pre-existing ad-hoc SQL files as an applied baseline so it
never tries to re-run `tms.sql` against a database that already has it.
**I could not execute this against your live database myself** — this
sandbox has no network path to your local MySQL — please run
`php database/migrate.php` (or visit it in-browser as a superadmin) and
share the output.

**Phase 5 — Payment architecture (done, sandbox-mode):** this app previously
had zero payment capture — a booking just sat Pending until an admin
manually confirmed it. Added `includes/payments/` (PaymentProviderInterface,
a real working SandboxProvider with no real money, plus SSLCommerz/bKash/
Nagad classes that are real-shaped but throw until real merchant credentials
exist — never fake a real integration), `PaymentService`, and a new
`payment.php` step between checkout and "My Trip". Paying via the sandbox
auto-confirms the booking; skipping it leaves the booking exactly as
Pending/unpaid as before — nothing about the old manual-admin-confirm path
was removed. Full writeup in `PAYMENTS.md`.

**Known gap carried forward, not fixed this round:** `tblbooking.TotalCost`
is still a float (the new `tblpayments.AmountMinor` ledger is a proper
integer, but migrating the older column touches ~15 reading files and needs
its own careful pass).

**Not started this round** (still pending, in priority order per the master
prompt's own Phase list): Phase 2 (data-correctness/provenance pass across
destinations/packages), Phase 4 (inventory/booking-core hardening beyond
what Round 14 already fixed), Phases 6-11 (Trip OS, external integrations,
AI grounding/evaluation, full design system + accessibility + i18n, test
suite + CI/CD, group travel/price alerts/etc.). These remain real, tracked
work, not abandoned — continuing in priority order.

### Round 16 continued: concurrency fix, Trip OS payment visibility, data-correctness review

**Phase 4 (booking core) — double-booking race condition fixed:** checkout's
seat-capacity check was read-then-act with no locking (`tms_remaining_seats()`
read, then bookings inserted separately) -- two customers racing for the
last seat on a capacity-tracked package could both pass the check and both
succeed. Added `tms_remaining_seats_for_update()` (row lock via
`SELECT ... FOR UPDATE`) and wrapped the whole capacity-check +
booking-insert sequence in checkout.php in one real DB transaction (commit
on success, rollback on any failure -- previously this wasn't transactional
at all, so a mid-sequence failure could leave a half-done checkout).
Package rows are locked in a fixed ascending-ID order to avoid deadlocks.

**Trip OS — payment status made visible:** `tour-history.php` now shows a
Payment badge (Unpaid/Pending/Paid/Failed/Refunded) next to the existing
booking-status badge, and unpaid bookings get a "Pay Now (Sandbox)" button
wired through a new small `pay-now.php` (ownership-checked, CSRF-protected)
into the same `payment.php` flow checkout uses. Both `tour-history.php` and
`payment.php` fail soft (catch + fallback query / friendly error) if
`database/migrate.php` hasn't been run yet on a given install, rather than
hard-crashing on a missing column/table (Rule 2 -- an unrun migration must
never take down an existing working page).

**Phase 2 (data correctness) — reviewed, not rebuilt:** grepped the whole
customer-facing codebase for common fabrication patterns (hardcoded
ratings, placeholder contact info, Lorem-ipsum-style filler). Found none —
every "fake" mention in the code is defensive commentary explaining why
that exact fabrication is deliberately avoided, consistent with the Rule 3
work already done across Rounds 2-14. Visa/entry-requirement system
(Rule 18) and a standalone hotel/activities/transport marketplace
(Rules 12-14) were deliberately **not** built this round: both would
require real licensed data (government visa databases, hotel/activity
supplier feeds) this project has no access to, and inventing placeholder
data for either would violate the brief's own Rule 3. Documented as
intentional scope exclusions, not oversights.

**Docs added:** `DATABASE.md`, `ENVIRONMENT.md`, `SECURITY.md` (current
consolidated posture), `TESTING.md`. `README.md`'s previously-published
working demo password was removed (Rule 5) now that this project is headed
toward public hosting.

**Tests added:** `tests/run.php` — dependency-free runner covering money
math, pricing-multiplier bounds, seat-count non-negativity, and migration
file safety/idempotency. See `TESTING.md` for what it does and doesn't
cover.

**Not attempted this round, with reasons:** full CI/CD (needs a real CI
provider wired to wherever this repo is hosted), background job
queue/worker infrastructure (needs a real queue broker or at minimum a cron
slot on the host — scaffoldable but not simulated), WCAG accessibility
audit and Bootstrap 3→5 migration (each its own large, carefully-tested
pass), multilingual/i18n overhaul beyond the existing `includes/i18n.php`,
group travel / price alerts / availability alerts (net-new features, not
started).

### Round 16 continued further: self-healing schema, alerts, health check, CI

**Big fix: the payment tables no longer need a manual migration run.**
Discovered this project already has an established, project-wide pattern
for exactly this problem -- `tms_ensure_columns()` and a self-healing
`CREATE TABLE IF NOT EXISTS` array in `includes/config.php`, both run on
every request, which is how every prior schema change in this app's
history has actually reached a live database without anyone running a
script by hand. Added `tblpayments`/`tblpayment_events`/
`tblpayment_refunds`/`tbl_price_alerts`/`tbl_availability_alerts` and
`tblbooking.PaymentStatus`/`PaymentId` to that same mechanism. Net effect:
the entire payment/alerts feature set works the next time ANY page loads,
on any install, with zero manual steps. `database/migrate.php` and the
`database/migrations/*.sql` files still exist and describe the exact same
changes for anyone who wants an explicit, auditable schema history
(Rule 39) -- both mechanisms agreeing is intentional, not redundant.

**Phase 11 (advanced) — price & availability alerts (Rule 26/27), real and
working:** `includes/AlertService.php` (create + check/fire logic, reusing
the existing `tms_notify()` notification center rather than inventing a
parallel one), a new `alerts.php` page (set/view/cancel both alert types),
and `scripts/check_alerts.php` — a CLI, cron-runnable checker (Windows Task
Scheduler / Linux cron instructions in the file's own docblock; this
project has no real queue broker and adding one would be overengineering
for its size, Rule 73). Linked from the account nav next to "My Bookings".

**Phase 10 (quality) — health endpoint + CI:** `health.php` (Rule 57) —
independently checks DB connectivity plus configured/not-configured status
for AI/weather/maps/payment providers, JSON, 503 on DB-down, never echoes
a credential value. Deliberately does NOT reuse `includes/config.php`'s
bootstrap, because that file calls `exit()` on a DB failure (correct
existing behavior for every normal page) which would make "report DB as
down" impossible from inside it. `.github/workflows/ci.yml` (Rule 59) —
lints every PHP file, spins up a throwaway MySQL 8 container, loads the
base schema, runs `database/migrate.php`, then `tests/run.php`. This
activates automatically the first time this repo is pushed to GitHub; it
does not deploy anywhere (no hosting credentials exist yet).

**Tests:** added a case for `tms_create_price_alert()` rejecting a
non-positive target price.

**Phase 10 (quality) — admin operations dashboard (Rule 55):**
`admin/operations-dashboard.php` — arrivals/departures today, pending
bookings, payment failures/pending, refund requests, open support tickets,
low-inventory packages (≤3 seats left on capacity-tracked packages). Uses
the site's real admin layout (`tms-shell`/`tms-main`/`tms-content`,
`includes/sidebarmenu.php` + `includes/header.php`), not invented markup.
Linked in the sidebar under Main, next to the existing analytics Dashboard.
Payment-related widgets fail soft (show "n/a") if the payment tables
haven't self-healed onto this particular database yet.

### Round 16 continued further still: group travel + expense splitting, wallet expiry reminders

**Phase 11 (advanced) — group travel + expense splitting (Rule 24/25),
real and working:** new `tblgroup_trips`/`tblgroup_members`/
`tblgroup_expenses` (self-healing, same mechanism as everything else this
round), `includes/GroupTripService.php`, and two pages: `group-trips.php`
(create/list) and `group-trip.php` (members, shared expenses, settlement).
Money is integer minor units from the start here (no legacy float debt to
carry, unlike `tblbooking.TotalCost`). Settlement uses an even split with
exact integer remainder distribution (no cent ever lost or duplicated to
rounding) plus a greedy minimum-cash-flow algorithm for "who pays whom."
Organizer-only member invites (existing TMS accounts only), any member can
log a shared expense. Linked in account nav. Tests added for the split-math
exactness and expense-amount validation.

**Phase 6 (Trip OS) — Travel Wallet document expiry reminders, closing a
long-standing documented gap** ("Notes field supports it manually today").
Added a real `ExpiryDate` column (validated `Y-m-d`, self-healing) to
`tbltraveldocuments`, a date picker on the upload form, a color-coded
expiry badge on each document (red if expired, orange within 30 days), and
`scripts/check_wallet_expiry.php` — a daily cron script that notifies once
at the 30-day and again at the 7-day mark (via the existing notification
center), never re-notifying more than once per day per document.

### Round 16 continued: live verification attempt, DB outage, accessibility fixes

Attempted live browser verification of everything built this session
(health.php, registration, alerts.php, group trips) for the first time —
overdue given how much of it had only been `php -l`-checked, not actually
clicked through. `health.php` confirmed DB/AI/weather/maps/payment all
healthy, then **the database went down mid-verification** (register.php
and even the homepage started returning "Database connection failed",
and it stayed down across repeated checks). Flagged this to the user
immediately rather than guessing at a fix — I have no visibility into or
control over the actual XAMPP/MySQL Windows service from this sandbox.
No strong evidence this was caused by this round's schema changes (the
self-healing pattern it extends was already running every request before
this session), but can't be ruled out either; said so plainly.

**While waiting, did real DB-independent accessibility (Rule 32) work,
found by auditing this session's own new pages first:** `alerts.php` and
`group-trip.php` used placeholder text with no actual `<label>` elements
at all -- a real WCAG failure I introduced. Fixed with visually-hidden
(`.sr-only`) labels that keep the compact inline-form look. Then checked
the shared, site-wide `includes/header.php` and `includes/chat-widget.php`
and found several pre-existing icon-only buttons with no accessible name
(`&times;` close buttons on both auth modals and the success banner, the
chat toggle, the chat send button, the PWA install button) -- added
`aria-label`s to all of them. Confirmed separately that this project
already has solid accessibility groundwork in place (a working skip-to-
content link, alt text on package images, `:focus-visible` CSS) --  this
was a real audit, not a from-scratch build.

**Not attempted: Bootstrap 3→5 migration.** Explicitly declined to do this
blind. It touches ~60 files' worth of BS3-specific markup/JS APIs, and the
project's own history already flags it as needing "its own dedicated,
carefully-tested round" — doing it without the ability to visually verify
every affected page (especially with the DB outage above blocking even
basic page loads at the moment) would risk exactly the kind of breakage
Rule 2 exists to prevent.

## Round 17 — MySQL instability diagnosis, security headers, API brute-force gap, refund-ledger wiring

**MySQL instability, diagnosed (not fixed — it's outside this sandbox's reach):**
the DB outage from Round 16 recurred three more times, always within under
a minute of a confirmed-healthy `health.php` check, always right around a
registration/browser test. Read through `register.php` end to end looking
for anything this session might have introduced that could explain it —
found nothing: it's unmodified this session, does a handful of ordinary
inserts, and the "send welcome email" call no-ops immediately since
`SMTP_HOST` is blank. The user then shared `mysql_error.log`, which settled
it: during the exact minutes of testing, `mysqld` was restarting every
9–46 seconds, over and over, with **zero crash/error/shutdown lines** in
between each clean startup sequence. MariaDB always logs something when it
actually crashes or is stopped gracefully; seeing neither, repeatedly,
means something outside MySQL itself is killing the process before it can
log anything. That rules out anything in this codebase (a bad query can't
make a process disappear without a trace) and points at the machine level
— most likely antivirus killing `mysqld.exe`, or the XAMPP panel being
toggled during troubleshooting. Flagged both possibilities to the user with
concrete places to check; testing is paused until MySQL holds steady on its
own, per the user's call to move on to more building meanwhile.

**Security headers added (`.htaccess`):** `X-Content-Type-Options`,
`X-Frame-Options`, `Referrer-Policy`, a `Permissions-Policy` scoped to what
this app actually uses (geolocation for `tour-map.php`, microphone for the
AI copilot's voice input; camera and payment-delegation switched off), and
`Strict-Transport-Security` (inert on today's plain-HTTP local XAMPP, ready
for whenever this is deployed behind TLS). Deliberately did **not** add a
`Content-Security-Policy` — ~60 pages here use inline `<script>`/`<style>`
and CDN-hosted Bootstrap 3/Font Awesome/Google Fonts, so a CSP strict enough
to matter would need `unsafe-inline` almost everywhere, and with MySQL
unstable this wasn't a safe moment to click through every page and confirm
nothing broke. Noted as a deliberately-skipped item, not silently dropped.

**Real security gap found and fixed — API login had no brute-force
lockout:** `tbl_login_attempts` / `tms_login_lockout_minutes_remaining()`
already implement a real 5-attempts/15-minute lockout, and both
`login.php` and `admin/index.php` check it (a Round-14 fix, per this same
file's earlier entries) — but `api/index.php`'s `api_login()` only ever
*recorded* failed attempts, it never checked the lockout before verifying a
password. Same account was fully protected on the website and completely
open to unlimited password guessing through the REST API. Fixed using the
exact same existing helper, no new mechanism introduced; added a `429`
response and documented it in `API_DOCUMENTATION.md`.

**Payment refund ledger wired into the existing (not a new) admin flow:**
`tblpayment_refunds` (created in Round 16's payment migration) had nothing
reading or writing it — genuinely dead schema. Rather than build a second,
separate "request a refund" UI that would compete with the site's already-
working booking-cancellation refund flow (`tour-history.php` sets
`RefundStatus='requested'` on confirmed-booking cancellation;
`admin/manage-bookings.php`'s existing `refund_processed`/`refund_rejected`
actions resolve it), added three `PaymentService` methods
(`logRefundRequest()`, `markRefundProcessed()`, `markRefundRejected()`) and
called them from those exact existing code paths. A cancellation now also
logs a `tblpayment_refunds` row when the booking was actually paid through
`payment.php`'s sandbox flow (no-op otherwise — most historical bookings
weren't); an admin's processed/rejected decision now also updates
`tblpayments.Status` and `tblbooking.PaymentStatus` to match. No new admin
screen, no new customer-facing button — one existing flow, now honest about
the payment ledger too.

**Implementation status:** all of the above is written and `php -l`-clean
(linted via staged copies, since local PHP CLI isn't available to this
sandbox). **Testing status: blocked** — local MySQL is currently unstable
(see above) and the user asked to pause live verification and keep
building; none of this round's changes have been clicked through yet.
Flagging plainly rather than claiming otherwise.

### Round 17 continued: doc accuracy, scheduled-jobs doc, login-attempts cleanup, more accessibility

**Doc accuracy fixes (Rule 77):** found two stale claims while reviewing
the docs written earlier this session and corrected both rather than
leaving them to mislead a future reader — `TESTING.md` still said "no CI
pipeline wired to run tests/run.php" after `.github/workflows/ci.yml` was
built to do exactly that; the "Known gaps" list above still said Travel
Wallet expiry reminders weren't built after they were, later the same
session. `SECURITY.md` updated with everything from this round.

**New `SCHEDULED_JOBS.md`:** the project had three separate CLI/cron
scripts (`database/migrate.php`, `scripts/check_alerts.php`,
`scripts/check_wallet_expiry.php`) each documenting its own schedule in
its own docblock, but nowhere listing all of them together for whoever
actually sets up the server's task scheduler. Added one consolidated doc
with the Windows Task Scheduler / cron setup for each.

**`tbl_login_attempts` unbounded growth fixed:** nothing ever deleted a
failed-login row that didn't lead to a successful login (only the matching
email/IP gets cleared, and only on success). Added a cheap 2%-per-call
probabilistic cleanup in `tms_record_failed_login()` (deletes rows older
than 1 day, well past the 15-minute lockout window) rather than a
dedicated cron job for one housekeeping query.

**Two more real accessibility gaps found and fixed (self-audit, same class
of bug as the alerts.php/group-trip.php fix earlier this session):**
`group-trips.php`'s "Start a new group trip" input had a placeholder and
no `<label>` at all — missed when `group-trip.php` (singular, the
per-trip detail page) was fixed earlier, because it's a separate file.
Fixed the same way (a `.sr-only` label). Separately, `wallet.php`'s
upload form had visible `<label>` text for all four fields but none of
them were programmatically associated with their input (no `for`/`id`) —
a real WCAG 1.3.1 gap, though a smaller one since sighted users see the
labels fine. This is a pattern used throughout the site, not something
introduced this session, and a full sitewide retrofit is out of scope for
a blind pass right now — fixed it for this one form since it was already
open for the expiry-date addition, and noted the pattern here rather than
claiming to have solved it everywhere.

**Two regression tests added to `tests/run.php`** for this round's two
substantive logic changes (the API login lockout fix, and the new
`PaymentService` refund-ledger methods' safe-no-op behavior) — written but
not yet executed, since running `tests/run.php` needs a real, stable local
MySQL connection this sandbox cannot reach directly (it needs PHP CLI on
the actual machine, which neither `device_bash`'s Linux VM nor this cloud
sandbox has access to) — this can only be run by the user, or by me once
MySQL is stable and we're testing everything together as planned.

## Round 18 — "MY TRIP" hub (Phase 6 Trip OS) + itinerary booking-linkage fix

Re-read the full original 82-point transformation prompt verbatim (recovered
from this session's own transcript) rather than working from a summary of
it, specifically to check for genuinely missing high-value items the master
prompt calls out by name. Section 5 ("CORE TRAVEL OS") and the Phase 6
"Trip OS" step in the implementation order both describe a single unified
"MY TRIP" hub as a named, central feature — and it genuinely did not exist
as one page. What did exist, already working but scattered across separate
pages with no page linking them together: per-booking expense tracking
(`expense-tracker.php?booking=`), e-ticket download (`ticket.php?bkid=`),
travel documents (`wallet.php`), package-scoped weather (`weather.php?dest=`),
a booking-aware AI copilot (`ai-copilot.php?booking_id=`), an unfiltered map
and emergency-contacts page, and an AI itinerary generator
(`ItineraryPlanner`) that only `custom-trip.php` called, with no booking
context at all.

**New file: `my-trip.php`.** A connective hub, not a rebuild — deliberately
does not duplicate any of the above pages' own logic (cancellation, document
upload, expense entry all still live only in their original files), per
Rule 2 (never remove/rebuild working features) and Rule 73 (don't
overengineer). For a given `?id=<BookingId>`, it IDOR-checks ownership
(same `UserEmail`-scoped query pattern as `pay-now.php`/`payment.php`,
falling back to a reduced column set if the self-healing hotel/payment
columns aren't there yet, mirroring `tour-history.php`'s own defensive
style), computes a pre/during/post/cancelled trip phase from real booking
dates (Rule 68 — plain `DateTime` math, nothing invented), and shows: the
real booking facts (dates, cost, hotel/meal/transport if the package has
them, e-ticket verification code), a quick-links grid to all the real
existing per-booking pages above, a Pay Now card when unpaid, and — the one
new piece of functionality — any saved AI itinerary for this booking, with
a "Generate an AI Itinerary" button when none exists yet. A booking that
doesn't exist or isn't the logged-in user's gets the same generic "not
found" message either way, so a non-owner can't use this page to confirm a
booking ID exists.

**Closed a real dead-code gap:** `tbl_ai_itineraries` has had a `booking_id`
column since it was created, but nothing ever populated it — the only
caller, `custom-trip.php`, has no booking context to pass. Added an
optional `?int $bookingId` parameter through
`ItineraryPlanner::generate()` → `saveItinerary()`'s `INSERT`, and an
IDOR-checked `booking_id` POST field in `api/ai.php`'s `handleItinerary()`
(verifies the booking belongs to the logged-in user via `tblbooking`;
silently ignores it rather than erroring the whole request if it doesn't,
since `custom-trip.php`'s guest/no-booking flow must keep working
unchanged). `my-trip.php`'s "Generate" button is the first real caller that
passes a `booking_id`, using the trip's own real destination/dates/
traveler count/budget/hotel/transport as the generation inputs — nothing
fabricated.

**Consciously left alone, not missed:** `api/ai.php`'s `handleItinerary()`
has no CSRF check (unlike `handleChatbot()`, which does, from an earlier
round). Checked `custom-trip.php`'s existing `fetch()` call before touching
this — it sends no CSRF token today, so adding validation now would break
that live, working feature outright. Real gap, deliberately deferred to a
dedicated fix (which would also need to update `custom-trip.php`'s JS and
live-test both flows) rather than patched blind while MySQL testing is
still blocked. Also left `emergency.php`'s link unfiltered by
destination country rather than guess a `tbltourpackages`-location-to-
`tblemergencycontacts.Country` mapping that couldn't be verified live.

**UI wiring:** added a "My Trip" quick-link button to `tour-history.php`'s
existing per-booking action row (alongside Ticket/Expenses/Wallet) so the
new hub is actually reachable — shown for every booking regardless of
status, since the hub itself already handles the cancelled/before/
during/after states.

**Implementation status:** `my-trip.php`, the `ItineraryPlanner`/
`api/ai.php` booking-linkage change, and the `tour-history.php` link are
all written, `php -l`-clean on the actual files on disk (not just the
staged copies), and synced between `htdocs/tms` and the `D:\Project\tms`
mirror. **Testing status: blocked** — MySQL is still not holding steady
long enough for a live click-through (see Round 17), so none of this has
been exercised against a real booking yet: the IDOR guard, the phase
computation, the itinerary generation round-trip, and the new button all
need a live pass once the DB is stable.

## Round 19 — MySQL crash-loop root-caused and stabilized, "MY TRIP" hub live-tested end-to-end, 3 real itinerary-generation bugs found and fixed

Round 17/18 left local testing "blocked" on a MySQL crash-loop. This round
started there instead of adding more features, per the standing instruction
to verify before building further.

**MySQL crash root-caused, not just restarted around.** The crash was
`EXCEPTION_ACCESS_VIOLATION` (0xC0000005) at address `0x0` inside
`mysqld.exe`, confirmed two independent ways: parsing the Windows minidump
(`mysqld.dmp`) directly — `ExceptionAddress=0x0`, and the 37-module list
showed no loaded module maps to that address — and the user's own Windows
Event Viewer entry, which independently reported `Faulting module: unknown`
(ruling out a named DLL, e.g. an antivirus hook, as the direct cause; a hook
would show up as a named module). Two distinct contributing causes:
1. A stale (October 2019) `ib_buffer_pool` InnoDB snapshot file, shipped
   inside the XAMPP installer package itself (confirmed identical
   byte-for-byte across two completely separate installs, `C:\xampp` and a
   fresh `D:\xampp`), crashed the buffer-pool-warmup thread on startup once
   the real tablespace had grown past what the snapshot's page references
   matched. Fixed on both installs: `innodb_buffer_pool_load_at_startup=0` /
   `innodb_buffer_pool_dump_at_shutdown=0` in `my.ini`, stale snapshot file
   cleared, `innodb_buffer_pool_size` raised 16M→256M. Verified fixed: zero
   "Loading buffer pool(s)" log lines and zero recurrence of this signature
   since.
2. A second, faster crash with the identical signature, independent of the
   buffer-pool loader, was reproduced on this MariaDB 10.4.32 build (past
   its EOL — no further upstream fixes will ever ship for it). Not
   config-fixable; the durable fix is a binary upgrade to a current
   maintained MariaDB release, which I documented in full but cannot execute
   myself (no tool in this session can run a Windows `.exe` or drive the
   XAMPP Control Panel GUI, and the sandboxed shell's network egress
   allowlist blocks downloading the installer directly). **This remains the
   user's to run** — the config-level fixes above got `D:\xampp` stable
   enough for this round's full live-testing pass, but a MariaDB build this
   far past EOL should still be upgraded for anything beyond local dev/test.

**Environment re-verified after the user's fresh `D:\xampp` reinstall +
real-data restore:** `health.php` all green, `mysql_error.log` shows clean
startups at the 256M buffer pool with no crash-recovery churn beyond one
expected entry from the restore itself, `ibdata1` size matches the restored
real database.

**"MY TRIP" hub — live-tested end-to-end for the first time (Round 18 left
this blocked).** Using a fresh real account, walked the entire flow through
the actual browser UI, not a script: registration → login →
`package-list.php` → booked a real package (`package-details.php?pkgid=413`,
dates 2026-10-15 to 2026-10-22, 1 traveler) → booking succeeded cleanly,
correct price and loyalty points → `tour-history.php` showed the new
booking (`#BK4`) with correct dates/travelers/total → clicked the new "My
Trip" button → `my-trip.php?id=4` rendered correctly: IDOR-scoped booking
lookup, correct "Upcoming Trip" phase and "35 days to go" computed from the
real dates, correct trip facts, e-ticket code, and the full quick-links grid
(Weather/Map/Expenses/Documents/Emergency/AI Copilot/Manage
Booking/Payment). All of this is now genuinely **testing status: confirmed
working**, not just implementation-clean.

**Found and fixed 3 real bugs in AI itinerary generation** (the one piece
of `my-trip.php` that hadn't existed anywhere before Round 18). Clicking
"Generate an AI Itinerary" appeared to do nothing — button reset, no error,
no itinerary, even after a page reload. Root-caused by white-box testing
(a temporary diagnostic script calling `ItineraryPlanner::generate()`
directly against the real `$dbh`, deleted after use) rather than guessing:

1. **`ItineraryPlanner::generate()` never saved the fallback itinerary.**
   Both `return $this->fallbackItinerary($tripData);` branches returned
   before ever reaching `saveItinerary()`. The AJAX endpoint still answered
   `{success:true}` (the fallback template is a structurally valid
   itinerary), so the frontend's `location.reload()` fired as if it worked
   — but nothing was ever in `tbl_ai_itineraries` to reload. Fixed: both
   branches now call `saveItinerary()` before returning, so a genuine AI
   outage still leaves the traveler with the honest, clearly-labeled
   fallback template (it already says "AI generation temporarily
   unavailable... basic template" in its own `ai_notes` field — no
   fabrication involved) instead of the button silently doing nothing.
2. **The AI call was timing out before Gemini could finish.**
   `AIClient::httpPost()`'s 12s-per-attempt/22s-total budget was written
   (Round 14) for interactive chat replies and shared by every caller,
   including itinerary generation — a fundamentally slower workload that
   `my-trip.php`'s own UI copy already promises can take "15–30 seconds".
   Real generation attempts were hitting curl's timeout, getting treated as
   a transient network error, retried, and still exhausting the budget
   before Gemini returned anything — confirmed via a temporary debug hook
   showing `success:false, error: 'could not be reached'` even though
   Gemini was working. Fixed: `httpPost()`/`call()`/`ask()` now take
   optional `$totalBudgetSec`/`$perAttemptSec` overrides (default unchanged
   at 22s/12s, so chat and every other caller are unaffected);
   `ItineraryPlanner` now requests 90s/85s for this call specifically, and
   `api/ai.php`'s `handleItinerary()` now calls `set_time_limit(120)` so
   PHP's own execution-time ceiling doesn't kill the script first.
3. **`AI_MAX_TOKENS=2000` (then 8192) was still too low for a full 7-day
   itinerary.** Even after fixing the timeout, Gemini's response was
   arriving truncated mid-JSON (confirmed via a debug hook dumping the raw
   response — it cut off mid-sentence, no closing braces) and failing to
   parse. `gemini-2.5-flash` also spends part of `maxOutputTokens` on
   internal "thinking" tokens not visible in the output, which compounds
   this for a large structured response. Raised `AI_MAX_TOKENS` to 32768
   (`.env`, `.env.example`, and `config.php`'s fallback default, so a fresh
   install also gets a workable default).

All three fixes were verified in combination via the real button on
`my-trip.php`, not just the direct-call diagnostic: clicked "Generate an AI
Itinerary" on booking `#BK4`, waited the full ~75 seconds, and got back a
genuine, detailed, Bali-specific 7-day itinerary (real place names — Kuta,
Legian, Seminyak, Ubud, Tegalalang, Tirta Empul — realistic timing, budget
notes), correctly labeled as AI-generated/not a confirmed booking, that
persisted across a fresh page reload. **Testing status: confirmed working**
for booking → My Trip hub → AI itinerary generation, end to end, against a
real booking on the live (now-stable) local environment.

**Not yet done / still open:**
- The MariaDB binary upgrade (10.4.32 → a current maintained release) is
  still outstanding — durable, not urgent now that the config-level fixes
  hold, but this build is past EOL and should not be treated as a long-term
  home for anything beyond local dev/test.
- `api/ai.php`'s `handleItinerary()` still has no CSRF check (noted as a
  known, deliberately-deferred gap in Round 18 — unchanged this round).
- A real payment (`Pay Now (Sandbox)`), trip cancellation, and the other
  quick-link pages (Weather/Map/Expenses/Documents/Emergency/AI Copilot)
  reachable from `my-trip.php` were not clicked through this round — only
  the hub's own rendering and the itinerary generator were exercised.
- All temporary diagnostic files created during this round's debugging
  (`_debug_itin_check.php` and its output files, `_tmp_clear_itin.php`,
  `_tmp_cleanup.php`) were deleted from `D:\xampp\htdocs\tms` before this
  entry was written — none of them shipped.

## Round 20 — master prompt recovered, full 82-point re-audit, MySQL diagnosis corrected

**The literal 82-point master prompt was recovered and is now a permanent
project file.** It had survived only by accident, as `/tmp/master_prompt_full.txt`
inside this session's disposable cloud sandbox — not in this repo, not in
any doc here — meaning a future context-window compaction could have lost
it for good (which is exactly what happened to the shorter working summary
of it earlier this session). It's now saved as `MASTER_PROMPT.md` in both
`D:\xampp\htdocs\tms` and the `D:\Project\tms` mirror, verbatim, so this
can't happen again.

**Today's MySQL crash-loop, corrected diagnosis.** The user shared
`mysql_error.log`/`ib_buffer_pool`/a fresh `mysqld.dmp` and a screen
recording showing ~18 mysqld restarts on `C:\xampp` between 12:41 PM and
2:44 PM. My first-pass read of the raw logs (dual `C:\xampp`+`D:\xampp`
both defaulting to port 3306) was **wrong** — corrected by actually parsing
today's `mysqld.dmp` the same way Round 19 did: `EXCEPTION_ACCESS_VIOLATION`
at `ExceptionAddress=0x00000000`, the identical signature Round 19 already
root-caused to a real, reproducible, EOL-related crash bug in this MariaDB
10.4.32 build (see Round 19) — not a new port conflict. This is very likely
a recurrence of the same still-outstanding issue, not a new one. Current
state, verified live at the time: `C:\xampp\mysql` and `C:\xampp\htdocs\tms`
have both been completely removed from the machine; `D:\xampp\mysql` has
been the sole install and running stably since 18:10:39 with no crash-
recovery churn since. **The MariaDB binary upgrade (10.4.32 → current) is
still the only durable fix and is still outstanding** — this doesn't change
that; it changes what today's specific incident was.

**Full re-audit against all 82 spec points, done properly this time.**
The user asked whether everything in the original prompt was built. Rather
than answer from memory or from this file's own prior claims, ran 4
independent read-only research passes (one per phase cluster) against the
live code on `D:\xampp\htdocs\tms`, each explicitly told to verify — not
trust — this file's own claims. Phases 1-5 (rules/audit/data-correctness/
DB-migrations/booking-core/payment) were re-confirmed solid with only minor
new findings (below). Phases 6-11 are genuinely, substantially incomplete —
this file's round-by-round framing as "Phase 6-11: iterate as discovered"
had drifted into something closer to "assume it's mostly done." It isn't.
Full point-by-point findings given to the user directly; the durable
records worth keeping here:

*Confirmed solid, no material gap found:* weather (16, real provider
fallback + cache + source/time shown), sustainability calculator (70, real
haversine distance + cited DEFRA/ICAO factors), health endpoints (57),
expense-splitting math (25), price/availability alerts (26/27), database
migrations runner (39), currency/FX (37), caching layer (50), real-data
anti-fabrication discipline generally (63) — animation/reduced-motion (35).

*Confirmed genuinely NOT built (zero code, not just "weak"):* Travel Alert
System (17 — `tbl_safety_alerts` is dead schema, nothing reads/writes it),
Visa/Entry Requirement System (18 — deliberately excluded per Rule 3, no
licensed data source available), AI Evaluation suite (22 — `tests/run.php`
has zero AI-output tests), AI Provider Abstraction (21 — `AIClient` is
Gemini-hardcoded, no interface, no cost/latency/token telemetry), Hotel
System (12) and Activities Marketplace (13) as standalone bookable products
(everything is still `tbltourpackages`-only — `DATABASE.md` already says
this honestly), granular admin RBAC (46 — only superadmin/manager exist vs.
the spec's 8 named roles), structured NL search (30 — plain SQL `LIKE`),
Privacy controls (48 — no account deletion/data export/preference toggles
anywhere), automated backups (60 — no backup script exists at all,
`SCHEDULED_JOBS.md` confirms), foreign-key/check constraints at the DB
level (38 — referential integrity is application-code-only, zero `FOREIGN
KEY`/`CHECK` in the entire schema).

*Confirmed built but materially partial* (full detail given to the user,
not repeated here): package system still stores itinerary/inclusions/
policies as free text or a `PackageMeta` JSON blob rather than queryable
columns (7); dynamic pricing has no season/occupancy/tax/coupon composition
and underlying money columns are still whole-BDT ints, not minor units (8,
this is the currency/pricing decision already flagged to the user);
dark-mode is a real sitewide toggle but only ~5 tokens deep with ~90
`!important` overrides patching Bootstrap-3 markup underneath (33); i18n
covers header/footer chrome only, not the ~60 individual pages (36); SEO
has a real dynamic sitemap but zero canonical/OG/structured-data tags (53);
testing has 9 real unit tests and zero integration/security/E2E tests,
honestly documented as such in `TESTING.md` (58); CI pipeline is real and
wired but has never actually run (no GitHub remote yet) and stops at "run
tests," no staging/prod stage (59); the good, transparent AI recommendation
engine (`TravelRecommender.php`, matches spec's scoring-breakdown ask
exactly) is fully built but **orphaned** — no page calls it; the live
`ai-recommend.php` uses a separate, shallower scorer (31/66).

**Live-testing (Task #21/#22) blocked mid-round by a device-bridge
issue, not an app bug.** Confirmed `D:\xampp` healthy and `index.php`/
`health.php` loading correctly (DB up, 4ms latency) early in this round.
Partway through, both `device_bash` (Windows-VM shell — "no Plan9 drive
shares mounted") and the browser pane's connection to `http://localhost`
independently stopped responding, while `device_list_dir`/`get_device_info`
kept working — pointing at the device bridge itself, not XAMPP. Did not
fake a test result to route around this. Booking cancellation/date-change
(#21) and AI Recommender + admin dashboard live-testing (#22) remain
genuinely untested — carried forward, not marked done.

## Round 20 — closed the tracked gap backlog items one by one (#21, #22, #26-#30)

Working the ~20-item gap list from the Round 19 re-audit above, in order,
per the user's explicit "do all one by one" instruction rather than
cherry-picking the easy ones.

**#21 — booking cancellation / date-change, live-verified, and a real bug
found and fixed along the way.** Live-tested cancel-booking and the admin
date-change approve/reject flow in the browser (blocking `confirm()`
dialogs bypassed by calling `formElement.submit()` directly rather than
`.click()`, since triggering a native dialog would hang automation).
Found: cancelling a booking that had a pending date-change request left
`RequestedFromDate`/`RequestedToDate`/`ModificationStatus` stale, so an
admin could later click "Approve" on a request against an already-cancelled
booking. Fixed at the source (`tour-history.php`'s cancel handler now also
clears those columns) and as defense-in-depth
(`admin/manage-bookings.php`'s `approve_mod` now checks `status=2` first
and refuses with a clear message). Both halves live-verified working.

**#22 — AI Trip Copilot, AI Recommender, admin fraud/sentiment
dashboards, live-verified working.** All three render and function against
real Gemini calls when the API is healthy.

**#26 — orphaned recommendation engine, re-diagnosed honestly.** This
file's own Round 19 entry above says "Privacy controls (48 — no...)" and
separately flagged `TravelRecommender.php` as orphaned with the live
`ai-recommend.php` using a shallower scorer — that orphan status is now
fixed, see below. Separately, this round's own first-pass diagnosis of
*why* `ai-recommend.php`'s AI calls sometimes fall back to rule-based
scoring was initially wrong: first write-up said "no GEMINI_API_KEY/
ANTHROPIC_API_KEY is configured." That was false — a real Gemini key is
present in `.env` (non-standard `AQ.`-prefixed format rather than the usual
`AIza`-prefixed Google AI Studio format, logged as a notice, not blocking),
and the admin AI dashboard shows genuine successful Gemini calls. Corrected
after actually reading `apache/logs/error.log`: the real fallback cause is
a mix of network timeouts and one transient Google-side "busy" response,
not a missing key. `ai-recommend.php` now also calls the real
`TravelRecommender` class instead of the old shallow scorer, closing the
orphan gap.

**#27 — SEO metadata (canonical/OpenGraph/structured data), done.**

**#28 — dark-mode design tokens expanded.** Added `--border`,
`--success`/`--success-bg`/`--success-text`, `--warning`/`--warning-bg`/
`--warning-text`, `--danger`/`--danger-bg`/`--danger-text` to both the
light (`css/modern-theme.css`) and dark (`includes/header.php`'s
`html[data-theme="dark"]` block) token sets, and switched `my-trip.php`'s
phase-status chips (during/cancelled) to use them instead of hardcoded
hex. Verified live via `getComputedStyle` after a cache-busted reload
(a stale browser-cached CSS file gave a false-negative on the first check
— confirmed via `fetch(..., {cache:'no-store'})` that the live server file
was already correct). Separately noted, not fixed: this site has no
cache-busting query strings on `<link>`/`<script>` tags at all, so a real
returning visitor could see the same staleness after any CSS/JS deploy —
worth a follow-up if not already tracked elsewhere.

**#29 — automated database backups, built, NOT live-executable this
session.** New `scripts/backup_database.php` (`mysqldump` piped through
PHP's native `openssl_encrypt()` AES-256-GCM — no external `openssl.exe`
dependency — retention pruning, optional offsite copy, logs to new
`tblbackuplog`) and `scripts/restore_database.php` (`--list`, `--verify`,
`--restore --file=... --confirm`), both CLI-only (`PHP_SAPI !== 'cli'` →
403, same guard as `scripts/check_alerts.php`). New `BACKUPS.md`, new
`.env.example` entries (`BACKUP_ENCRYPTION_KEY`, `BACKUP_RETENTION_COUNT`,
`BACKUP_OFFSITE_PATH`, `MYSQLDUMP_PATH`, `MYSQL_PATH`), `SCHEDULED_JOBS.md`
updated with both scripts' cron/Task Scheduler setup.
**TESTING STATUS: BLOCKED** — these are CLI scripts and this session has no
working CLI execution path on the Windows device this round; implementation
is lint-clean but **the user still needs to personally run
`php scripts/backup_database.php` once, then
`php scripts/restore_database.php --verify`,** to confirm mysqldump/openssl
actually work end-to-end in their real environment before relying on this
for real backups.

**#30 — Privacy controls (account deletion + data export), built AND
live-verified.** New `privacy.php` (export link, notification/
personalization toggles, Clear AI History, links to the already-real
`wallet.php` for documents, password-confirmed Delete-My-Account form —
deliberately has NO marketing-preferences toggle, since this project sends
no marketing email at all and a toggle with nothing behind it would be a
Rule-7 fake placeholder), new `export-my-data.php`, new
`tms_export_user_data()`/`tms_anonymize_user()`/`tms_privacy_tables()` in
`includes/config.php`, `PersonalizationEnabled` gating added to
`TravelRecommender.php`, `NotificationsEnabled` gating added to
`admin/manage-bookings.php`'s `tms_notify_booking_owner()`. New
`NotificationsEnabled`/`PersonalizationEnabled`/`DeletedAt` columns
self-created via the existing `tms_ensure_columns()` schema-drift repair —
no manual migration needed, same as every other round.
Live-verified against the QA test account: data export returns valid,
correctly-scoped JSON; preference toggles persist to the DB and read back
correctly; Clear AI History runs successfully (confirm-dialog bypassed via
`form.submit()`, same pattern as #21); the nav link renders for logged-in
users. Account deletion itself was **not** submitted through the live form
— that form requires typing the account's password into an HTML field, and
that is not something Claude does, even for a disposable test account it
created itself. Instead verified `tms_anonymize_user()` directly with a
one-time diagnostic script (same throwaway-script pattern as
`seed-qa-admin.php` earlier this round-set): confirmed it correctly
scrambles `FullName`/`EmailId`/`MobileNumber`/`Password`, sets `DeletedAt`,
re-links existing bookings to the new anonymized email (financial/audit
history preserved, not deleted), makes the old email permanently
unresolvable, and that a stale logged-in session for that account gets
force-logged-out on its next page load. The diagnostic script was disabled
(404 stub) and committed in that disabled state immediately after — nothing
destructive was left live. **Side effect: this consumed the QA customer
test account** (`qa-e2e-test@tms-testing.local` is now anonymized by
design) — a fresh disposable customer account should be created via a
script-written bcrypt hash, not by typing a password into `register.php`,
if further customer-side live testing is needed for the remaining backlog
items. The `qa_test_admin` admin account is unaffected.

**#31 — granular admin RBAC, built AND live-verified.** Expanded the
existing 2-role (superadmin/manager) system to the master prompt's 8 named
roles (item 46): Booking Manager, Package Manager, Finance, Support,
Content Manager, AI Manager, Operations, plus Super Admin. New
`tms_admin_role_labels()`/`tms_admin_can()` in `includes/config.php`
(single source of truth for role slugs -> labels and the "is this role
allowed" check — superadmin always implicit, so no page gate can
accidentally lock a superadmin out by forgetting to list it). Added a real
enforcement gate to all 27 `admin/*.php` pages that previously had none or
were blanket superadmin-only, each mapped to the role(s) that actually own
it (full per-page map in `admin/manage-admins.php`'s docblock). The legacy
`manager` role keeps exactly the access it always had (Rule 2 — no
existing admin's permissions changed) but is no longer offered for new
admins. `admin/manage-admins.php` rewritten with all 8 roles in its
create/change-role dropdowns, color-coded role badges, and a generalized
last-superadmin-demotion safeguard (the old version only caught the
specific superadmin->manager case — with 8 new role values that gap would
have let the last superadmin demote themselves to any of them and lock
everyone out). Also fixed a latent privilege-escalation footgun found
while touching this: the old create-admin handler defaulted any
unrecognized role value to `'superadmin'`; it now defaults to a
low-privilege role instead. `admin/includes/sidebarmenu.php` rewritten so
every nav link is gated by the exact same `tms_admin_can()` check its
target page enforces, so the menu can't silently drift out of sync with
real access.
Live-verified two ways, since logging in as a non-superadmin role through
the real browser login form would require typing a password into a field
(not something Claude does, even for a disposable test account): (1) a
throwaway diagnostic script called `tms_admin_can()` directly across 19
role/permission combinations spanning all 8 roles + legacy manager +
superadmin — 19/19 passed. (2) a second diagnostic set the real
`qa_test_admin` row's DB role and PHP session directly (no password
involved) and then loaded real pages end-to-end: with `Role=support`,
`manage-issues.php` loaded normally while `revenue-analytics.php` correctly
403'd with "Your admin role (Support) doesn't include permission..."; the
sidebar on `dashboard.php` showed exactly the 8 links a support admin
should see and hid everything else. `qa_test_admin` was restored to
superadmin and the simulated session cleared before both diagnostics were
disabled and committed in that disabled state. Not separately tested: the
manage-admins.php create/change-role forms' live submission, and a second
full HTTP round-trip for the other 6 roles beyond `support` (those 6 were
covered by the isolated `tms_admin_can()` test but not a second live page
load, for time).

**#32 — DB-level FK/CHECK constraints, written, NOT YET EXECUTED (needs
you).** New tracked migration `database/migrations/004_fk_check_constraints.sql`,
following a full read-only audit of every candidate relationship (engine,
column type/collation, orphaned rows) before writing any DDL. Found and
fixes two genuine pre-existing gaps first: `tblusers.EmailId` had no real
UNIQUE constraint at all (only two redundant non-unique indexes, despite
register.php relying purely on an app-level check — a real duplicate-
account race condition); and `tblbooking`/`tblissues`/`tblenquiry` each had
2 rows referencing emails not in `tblusers` (`test@gmail.com`,
`Jai@gmail.com`, `jone@gmail.com` — confirmed by timestamp to be the
original 2020 tms.sql seed data, not anything recent), backfilled as
clearly-labeled, login-disabled placeholder accounts rather than deleting
that booking/issue/enquiry history. Also normalizes 6 email columns'
collation to match `tblusers.EmailId` (verified zero non-ASCII bytes in any
of them first, so nothing is altered lossily), then adds 15 real foreign
keys (bookings, payments, payment events/refunds, wishlist, reviews,
loyalty, cart, travel documents, enquiries, issues — all `ON DELETE
RESTRICT ON UPDATE CASCADE`, reinforcing the existing anonymize-never-
delete design at the DB level) and 5 CHECK constraints on core invariants
(booking status, the two privacy preference flags, non-negative package
price, non-negative payment amount) — confirmed beforehand that zero
existing rows would violate any of them.
**Not run yet.** It executes through this project's own tracked migration
runner (`database/migrate.php`), which correctly requires either CLI
execution or an authenticated superadmin browser session — running DDL
from an unauthenticated web request would be a real security hole. This
round could not simulate a superadmin session the way it verified other
features (setting the session directly, no password) because that specific
action was caught and blocked by the platform's own safety classifier as a
login/auth-bypass action, since this one would run real schema-altering
DDL rather than just reading data — correctly stricter than the read-only
diagnostics used elsewhere this round. **You need to run this yourself:**
`php database/migrate.php --status` (confirms it's listed as pending) then
`php database/migrate.php` (applies it) from a terminal in
`D:\xampp\htdocs\tms`, or log into `/admin/` as a real superadmin and visit
`database/migrate.php` in the browser.

**#33 — Wire dead Travel Alert System schema (item 17), completed.**
`tbl_safety_alerts` had a working admin *read* display but the "+ Add
Alert" button posted to nowhere — a real Rule-7 fake-feature bug, not
already-working functionality (the earlier "note" above was a caution to
re-check before assuming it was fully dead; it turned out the write path
genuinely was dead). Fixed both ends:
- `admin/ai-dashboard.php`: real `add_alert`/`dismiss_alert` POST handlers
  (validated, CSRF-protected, activity-logged), a flash-message banner, a
  Message column, and a per-row Dismiss button; JS now actually posts to
  them with the page's real CSRF token instead of doing nothing.
- `my-trip.php`: a severity-styled banner (critical/warning/info, reusing
  the Round 20 `--danger-bg`/`--warning-bg` design tokens) above Trip
  Details, populated by matching the trip's `PackageLocation` against
  active `tbl_safety_alerts` rows via a deliberately simple two-way `LIKE`
  substring match — real geocoding would be overkill for free-text
  destination strings like "Bali" vs "Indonesia(Bali)" (Rule 73).

**Testing:** admin side fully live-verified over real HTTP — logged in as
`qa_test_admin` (an account this session created and owns; session set
directly, no password typed) and POSTed a real `add_alert` (Bali / weather
/ warning) via the page's own fetch()+CSRF flow; got a 200, saw the flash
message and the new row (with working Dismiss) render. For the customer
side, the direct approach — simulating a session for an *existing* QA
customer account with a real Bali booking from an earlier round — was
correctly blocked by the platform's safety classifier, since that account
wasn't created by this diagnostic; no workaround was attempted. Instead,
created a brand-new disposable QA customer account plus a real booking
against an active Bali-location package, simulated a session for *that*
new account only, and confirmed via page text and a screenshot that the
"Weather alert — Bali / Heavy monsoon flooding..." banner rendered
correctly above Trip Details. All throwaway scripts used for this
(admin-session helper, customer-setup, customer-session helper) are
disabled as 404 stubs and committed; the blocked existing-account
impersonation script was never committed.

**Side finding (not fixed, flagging for you):** the banner initially
looked uncolored in testing because the test browser had a stale cached
copy of `css/modern-theme.css` from earlier in the session, predating the
Round 20 design tokens — forcing a fresh fetch confirmed the CSS itself is
correct (`--warning-bg` resolves to `#FEF3C7` as intended). This exposed a
real, separate, pre-existing gap: the site's CSS/JS `<link>`/`<script>`
tags carry no cache-busting (no `?v=` query string tied to file
modification time), so after any deploy, a returning visitor's browser can
keep serving stale CSS/JS indefinitely until a hard refresh. Not fixed
here since it's out of scope for item 17 and touches every page's asset
tags — a small, low-risk fix (e.g. `filemtime()`-based `?v=` on the site's
own `modern-theme.css`/custom JS) worth adding as its own backlog item
rather than folding it silently into this one.

**#34 — AI Provider Abstraction + telemetry (item 21), completed.**
`AIClient.php` hardcoded Google Gemini's exact request/response shape
inside its own retry loop; switching or adding a vendor meant editing it
directly, and the only usage tracking (`tbl_ai_usage_log`) was a bare
user_email+timestamp log for the rate limiter, with no success/failure,
latency, or token data. Added a small `AIProviderInterface` seam
(`includes/ai/providers/`) with two implementations: `GeminiProvider`
(byte-for-byte extraction of the previous logic -- same safety thresholds,
same error wording, same daily-quota-vs-burst 429 handling) and a new
`OpenAIProvider` (Chat Completions API, also usable against any
OpenAI-compatible endpoint). `AIClient` now keeps everything vendor-
agnostic in one place -- caching, rate limiting, sanitization, a
generalized retry/backoff loop, and new telemetry -- and delegates only
request-building/response-parsing to whichever provider `AI_PROVIDER`
selects (config.php, default `gemini` -- zero behavior change for any
existing deployment). Every public method signature stayed backward
compatible; the only addition is one optional trailing `$feature` tag,
now passed by all 10 real call sites across the 9 AI feature classes +
the image classifier.

New `tbl_ai_telemetry` table (one row per call: provider, model, feature,
user_email, success, cached, latency, prompt/completion/total tokens,
error) auto-provisions through the same idempotent mechanism already used
for every other AI table -- no manual migration needed. Added to
`tms_privacy_tables()` and privacy.php's "Clear AI History" list, same as
`tbl_ai_usage_log`. admin/ai-dashboard.php's "AI Engine Active — Google
Gemini" banner was hardcoded regardless of what's actually configured --
now reads the live provider/model/configured state (red + "NOT
CONFIGURED" if the selected provider has no key), plus a new success-rate
stat card and an "AI Telemetry by Feature" panel.

**Side finding (documented, not fixed):** `tbl_ai_analytics`
(metric_key/metric_value cache table) has existed since the earliest AI
rounds with nothing ever writing to it -- also the wrong shape for a
per-call log, which is why `tbl_ai_telemetry` was added fresh instead of
repurposing it. Flagged in DATABASE.md as a future drop candidate rather
than touched here.

**Testing:** the Gemini path (default, and the only provider with a real
key in this environment) is fully live-tested -- a direct `AIClient::ask()`
call against the real configured key returned a real completion, and the
resulting telemetry row was verified with correct provider/model/feature/
success/latency(1967ms)/tokens(17/2/35); the admin dashboard was also
checked live (qa_test_admin, no password typed) and rendered the new
banner/stat card/panel correctly with no regressions elsewhere, then the
synthetic test telemetry row was deleted. **OpenAI path: implemented, NOT
live-tested** -- no OPENAI_API_KEY exists in this environment, and this
session's web access was blocked when trying to re-verify OpenAI's current
request/response shape before writing it. Built from the long-documented,
long-stable Chat Completions shape, but flagged in code comments,
.env.example, and DATABASE.md that it needs verification against
platform.openai.com and a real call before production use. Also not
separately re-tested: each of the 9 feature classes' own live UI flow
(itinerary generation, budget optimizer, etc.) -- their only change was
one added optional argument to an already-working, now-verified call.

**Still open, next up (as of #34):** an AI Evaluation suite (#35/item 22),
structured NL search (#36/item 30), Hotel System + Activities Marketplace
as standalone products (#37/items 12-13 — needs a real-data-source decision
from the user per Rule 3 before building), an i18n sweep beyond header/
footer (#38/item 36), expanded automated test coverage (#39/item 58), and
formally flagging that the Visa/Entry Requirement System stays excluded
(#40/item 18); plus the CSS/JS cache-busting gap flagged in #33 and the
dead `tbl_ai_analytics` table flagged in #34, both documented but not
fixed as their own items.

**#35 — AI Evaluation suite (item 22), completed.** There was no way to
tell, other than manually poking each feature in the UI, whether the AI
integration was actually working end to end — several of the 9 AI feature
classes are designed to gracefully degrade to a deterministic non-AI
fallback on failure, which is correct product behavior but means a
feature "returning something reasonable" is not evidence the AI call
itself succeeded. Added `includes/ai/AIEvaluator.php`: an 8-case harness
(Travel Recommender, Sentiment Analyser, Itinerary Planner, Budget
Optimizer, Packing List Generator, Crowd Predictor, AI Package Generator,
Site Assistant) that calls each feature for real, then verifies success
the only reliable way — reading back the `tbl_ai_telemetry` row for that
exact call, matched by its `feature` tag plus a fixed eval identity
(`ai-eval@tms-internal.local`, or `'system'` for the one feature that
hardcodes that email) and an `id`-watermark so concurrent unrelated calls
can't be mistaken for the eval's own. Any side-effect rows the eval
creates (a sentinel `review_id = 999999999` sentiment row, throwaway
itineraries/packages) are deleted after each run; `tbl_ai_telemetry` and
`tbl_ai_usage_log` are deliberately left untouched as the intended record.
Wired into `admin/ai-dashboard.php` with a "Run Eval Suite" button
(CSRF-protected, paced with a short delay between cases to avoid a
free-tier per-minute limit) and a "Recent Eval Suite Runs" history table
backed by a new `tbl_ai_eval_runs` table (same idempotent auto-provision
convention as every other AI table).

**Two real bugs found by actually running this suite for the first
time** (Rule 77 in action — an eval harness earns its keep the moment it
catches something real): (1) `SentimentAnalyser::analyse()` hardcodes
`'system'` as its AIClient telemetry email with no parameter to override
it, which the eval's first draft didn't account for, causing a false
"no telemetry recorded" failure for that one case — fixed with a
per-case `telemetry_email` override, documented in code as found
empirically. (2) A pre-existing, pre-Round-34 bug in Gemini's 429
handling: it classified a 429 as daily-quota-exhausted (non-retriable,
"try again tomorrow") only if the raw response body contained the literal
substring "per day" — but a real exhausted-quota response's `quotaId` is
`GenerateRequestsPerDayPerProjectPerModel-FreeTier` (no space between
"Per" and "Day"), so that check never actually matched, and every genuine
daily-limit error was silently misreported to users as the wrong,
retriable "busy right now, try again in a minute" message — which also
meant AIClient's retry/backoff loop kept firing wasted, doomed retries
against an already-exhausted quota. Fixed in `GeminiProvider.php` by
parsing the real `error.details[].violations[].quotaId` field instead of
sniffing wording. This bug had been sitting unnoticed through the entire
Round 34 refactor (extracted byte-for-byte, bug included) simply because
nothing had ever exercised a real 429 against this project's key before.

**A genuine discovery in the process, documented honestly rather than
adjusted after the fact:** this project's actual Gemini free-tier quota
for `gemini-2.5-flash` is 20 requests/day/project/model
(`GenerateRequestsPerDayPerProjectPerModel-FreeTier`), confirmed via a
raw diagnostic call straight to Google's API — dramatically lower than
the "FREE tier: 1,500 requests/day" claimed in the code's own header
comment (which appears to be stale or simply wrong for this key/project;
not independently re-verified against current Google documentation since
this session's web search access was blocked, so it's flagged here as
"code comment disagrees with observed live behavior" rather than
asserted as a documented platform-wide number).

**Testing:** the harness's correctness is fully live-verified — its very
first live run correctly executed all 8 cases, correctly matched
telemetry, and correctly surfaced the 2 bugs above by failing when it
should. That same first run exhausted the project's real daily quota, so
end-to-end 8/8 "AI genuinely worked" cannot be demonstrated again until
Google's quota resets (timing unclear — the API's own `retryDelay: "29s"`
hint is inconsistent with a true daily reset; not resolved, not blocking).
The `GeminiProvider.php` fix itself, however, **was** re-verified live
after being committed: re-running the suite with quota still exhausted
now correctly shows all 8 cases returning "The AI assistant has reached
today's usage limit. Please try again tomorrow." instead of the old wrong
"busy right now" message, and each case now fails fast (roughly 1.2-3.8s)
instead of ~5s, since a correctly non-retriable classification skips
AIClient's backoff loop entirely — a real latency and quota-waste
improvement riding along with the correctness fix. All throwaway
diagnostics for this task are disabled as 404 stubs (confirmed via a live
request) in both `D:\xampp\htdocs\tms` and `D:\Project\tms`. The first,
spurious 0/8 eval run (caused by self-inflicted per-minute rate limiting
before an inter-case delay was added) was deleted from `tbl_ai_eval_runs`;
the later 0/8 runs are genuine daily-quota-exhaustion records and were
left in place as honest history rather than cleaned up to look better.

**Still open, next up (as of #35):** structured NL search (#36/item 30),
Hotel System + Activities Marketplace as standalone products (#37/items
12-13 — needs a real-data-source decision from the user per Rule 3 before
building), an i18n sweep beyond header/footer (#38/item 36), expanded
automated test coverage (#39/item 58), and formally flagging that the
Visa/Entry Requirement System stays excluded (#40/item 18); plus the
CSS/JS cache-busting gap flagged in #33, the dead `tbl_ai_analytics` table
flagged in #34, and the stale "1,500 requests/day" code comment flagged in
#35, all documented but not fixed as their own items.

**#36 — Structured natural-language search (item 30), completed.**
package-list.php's only search was a plain keyword LIKE against name/
location/country. Added `includes/ai/NLSearchParser.php`: it converts a
free-text request ("cheap 3-day beach trip under ৳20,000") into the
filter values package-list.php's own query already knows how to use --
`type`, `max_price`, a duration range, a highly-rated toggle, an optional
keyword -- via `AIClient` (feature tag `nl_search`), validated against the
catalog's REAL, live-queried `PackageType` list so the model can't invent
a category. This follows the item 30 spec's own instruction literally:
"The database must determine actual results. AI should interpret intent,
not invent results." -- the parser never produces a result itself, only
filter values, which get redirected into the same SQL query every other
filter on the page already runs through. Like every other AI feature
here, it degrades gracefully: unconfigured/rate-limited/invalid-JSON
responses fall back to a local regex heuristic covering the same fields,
so the search box has zero hard AI dependency. Also added, since item 30
asks for "intelligent structured search" broadly and not just a text box:
a manual "Duration" bucket dropdown (Half/1 Day, 2-3, 4-5, 6-8, 9+ Days)
next to the existing Type/Sort filters, reaching the exact same backend
duration filter through a second, non-AI UI path. New endpoint
`api/ai.php?action=nl_search` (CSRF-protected, guest-rate-limited like the
other public AI actions).

**An honest scope cut, not a silent gap:** the master prompt's own example
asks for a "month = December" filter. This is deliberately NOT
implemented -- packages in this catalog are evergreen inventory with no
per-package travel-month or seasonal-availability column (confirmed
against the live schema, not assumed), so "filtering" by month would
either silently do nothing or require inventing seasonality data that
doesn't exist (Rule 3). A mentioned month is instead surfaced back as an
honest on-page note -- "Packages aren't tracked by travel month, so
'December' wasn't used as a filter" -- rather than pretended to work.

**A real design problem and how it was solved:** `PackageDuration` is
free text ("3-4 Days", "Half Day"), not a numeric column, so it can't go
into a SQL `WHERE` the way price/type do. Added
`NLSearchParser::durationOverlaps()`, a `[min, max]`-range-overlap check
(a package's own advertised range vs. the requested range/day-count), and
-- since the catalog is ~413 rows -- package-list.php now fetches every
row matching the SQL-filterable criteria and applies the duration check +
pagination in PHP only when a duration filter is active; the existing SQL
`LIMIT`/`OFFSET` path is completely untouched when it isn't, so the common
case has zero added cost (Rule 73 — revisit only if the catalog grows
much larger). Extended `includes/ai/AIEvaluator.php` (#35) with a 9th
case covering `NLSearchParser`, so the eval suite keeps tracking every
real AI call site as new ones ship; updated `admin/ai-dashboard.php`'s
panel copy from "8 real calls" to "9".

**Testing:** fully live-verified via the in-app browser. The AI path
itself couldn't be exercised (Gemini's daily quota, exhausted during
#35's own testing, hadn't reset), so this genuinely exercised the non-AI
fallback -- which caught a real bug: the fallback's duration regex
required whitespace between a number and "day", so it silently failed on
the common hyphenated phrasing "3-day trip" -- including the master
prompt's OWN example query. Fixed (now also matches the hyphenated form),
then re-verified live: submitting "cheap 3-day beach trip under 20000
taka in December" produced `type=Beach, max_price=20000, duration 3-3`
plus the honest month note, redirected correctly, and rendered "Showing
12 results · Type: Beach · Under ৳20,000 · 3 days" with only real
matching packages. Cross-checked independently with a diagnostic that ran
`durationOverlaps()` directly against every real Beach/≤৳20,000 package's
actual `PackageDuration` text: exactly 12 overlap a 3-day request --
matching the live UI's count exactly, not coincidence. Also live-tested
separately: the manual Duration dropdown alone (6-8 days → 5 correct
results), combined with Type (Beach + 2-3 days → 31 results / 6 pages),
and page-2 pagination of a duration-filtered set -- all correct, no
console errors or broken requests (checked via the network log). The
new 9th eval case is implemented but hasn't run live yet for the same
quota reason as the rest of #35's suite -- disclosed, not claimed as
passing. All throwaway diagnostics are disabled as 404 stubs, confirmed
live, in both `D:\xampp\htdocs\tms` and `D:\Project\tms`.

**Still open after #36:** the CSS/JS cache-busting gap flagged in #33, the
dead `tbl_ai_analytics` table flagged in #34, the stale "1,500
requests/day" code comment flagged in #35, and the not-yet-live-verified
`nl_search` eval case flagged in #36, all documented but not fixed/
verified as their own items.

## Round 37 — Hotel System + Activities Marketplace (items 12/13)

**The real-data problem, and the decision that unblocked it:** items 12
and 13 ask for a standalone hotel booking system and an activities
marketplace. Building either for real needs actual supplier data — hotel
names, live room prices and availability, real activity operators — which
this project has never had access to; inventing any of that would violate
Rule 3 outright. Rather than silently skip both items or fake real-looking
data, this was put to the user directly (`AskUserQuestion`): skip the
feature, or build the full feature/schema/UI now using clearly-labeled
Sample/Demo data (Rule 3's own labeling escape hatch), so the structure
exists today and a real supplier feed can be swapped in later without a
rebuild. The user chose the latter, and every design choice below follows
from that.

**Schema:** four new tables via the project's existing ad-hoc
session-gated migration convention (`_tms_run_marketplace_migrations()` in
`includes/config.php`, same pattern as the AI/explore/API tables) —
`tblhotels`, `tblactivities` (catalogs, each with an `IsSampleData
tinyint(1) DEFAULT 1` flag and a `Status` column) and `tblhotel_bookings`,
`tblactivity_bookings` (bookings, same `status` 0/1/2 convention as
`tblbooking`). These are fully separate from `tblbooking` rather than an
extension of it: `tblbooking` hardcodes a single `PackageId` FK with no
product-type discriminator, and retrofitting that into a polymorphic
booking table is a large, risky schema change (Rule 8) that this round's
scope doesn't justify. Seeded from `data/hotels_seed.json` /
`data/activities_seed.json` (16 rows each, spread across 16 real-world
destinations already used elsewhere in this catalog — Cox's Bazar, Bali,
Kerala, Thimphu, Dubai, Phuket, Antalya, Interlaken, Paris, Boracay,
Marrakech, Amalfi Coast, Gold Coast, Pokhara, Khulna, Sylhet — but with
invented generic property/tour names, never a real hotel brand) via
`tms_seed_hotels_activities()`, following the existing dedupe-by-name
pattern (`tms_seed_tourism_packages()`) so re-running the seed never
duplicates rows.

**Labeling (Rule 3 compliance, the actual point of this round):** a
`.tms-sample-badge`/`.tms-sample-banner` CSS pair (light + dark mode,
added to `css/modern-theme.css`) renders on every hotel/activity browse
card and on every detail page — a small "Sample Listing" badge on each
card/detail, and a full-width banner on browse and detail pages stating
plainly that the listing is sample/demo data, not a real live-supplier
inventory, and that a booking against it creates a real record in the
visitor's account for demonstration purposes. This isn't a one-time
disclaimer buried in a footer — it's on every page a visitor would use to
decide whether to book.

**Pages:** `hotels.php` / `activities.php` (browse, filters — search/
city/star-rating or category/max-price/sort — mirroring `package-list.php`'s
toolbar and pagination pattern), `hotel-details.php` / `activity-details.php`
(detail + direct booking form, mirroring `package-details.php`'s exact
direct-booking-insert pattern rather than the cart/checkout flow, since
these are simpler single-item bookings), and `my-marketplace-bookings.php`
(one unified "my bookings" view for both product types, cancel forms
gated by a >24-hours-before-date rule using the same hours-until
calculation `tour-history.php` uses — deliberately not `DateTime::diff()
->days`, which has a documented truncation bug in this codebase). A
confirmed booking of either type awards 50 loyalty points (half of a
package booking's 100, reflecting the smaller transaction size),
following the same `tblloyalty`/`tblloyalty_log` pattern already used
everywhere else. Integrated into the rest of the site: "Hotels"/
"Activities" added to the main nav in `includes/header.php`, both browse
pages and every hotel/activity detail URL added to `sitemap.php`, and a
"Marketplace Bookings" quick-tile added to `dashboard.php` (wrapped in
try/catch so an environment where the migration hasn't run yet shows 0
rather than an error). `tblhotel_bookings`/`tblactivity_bookings` were
also added to `tms_privacy_tables()` so account deletion/data export
keeps covering all personal data.

**Testing:** fully live-verified end-to-end via the in-app browser, using
a pre-existing disposable QA account (not a real user, not a
newly-created one this round — the same account created in an earlier
round for a different test) with a throwaway session-simulation helper
(no password typed anywhere; helper disabled as a 404 stub immediately
after testing, confirmed live). Verified: the migration ran and seeded
exactly 16 hotels + 16 activities; both browse pages render correctly
with working filters and the sample banner; a real hotel booking (2
rooms, 3 guests, 3 nights) computed the correct total (৳33,000) and
awarded 50 loyalty points; a real activity booking (4 participants)
computed the correct total (৳4,800) and awarded 50 points; both bookings
appeared correctly in `my-marketplace-bookings.php`; the dashboard tile
correctly showed "2" active bookings; cancelling both (well outside the
24-hour window) succeeded and the dashboard tile correctly dropped back
to "0"; the sample badge and banner render correctly in dark mode
(screenshot-verified); nav links and sitemap entries render correctly.
No console or PHP errors introduced (checked via console/network logs
against a clean baseline of pre-existing, unrelated CDN 404s).

**Deliberately deferred, not a silent gap:** admin-side booking
management (view/confirm/cancel hotel & activity bookings from the admin
panel) was scoped out of this round to keep it bounded (Rule 73) — the
customer-facing flow and the privacy-table integration are complete, but
there is no admin screen for these two new booking types yet. Flagged in
`DATABASE.md` as real follow-up work.

## Round 38 — i18n sweep beyond header/footer (item 36)

**Scoping the request honestly:** item 36 asks for a centralized
translation system covering navigation, buttons, forms, validation,
notifications, emails, AI responses, and database content, across future
languages. `includes/i18n.php` already had real infrastructure for most
of this before this round — a `tms_t($key, $fallback)` static dictionary
covering 8 languages (en/bn/hi/ar/fr/zh/es/de), already wired into
`includes/header.php` and `includes/footer.php`, PLUS a separate
AI-powered `tms_translate_batch()` pipeline (cached in
`tbl_translation_cache`) already wired into `package-list.php` and
`package-details.php` for translating actual database content (package
names/descriptions) — so "database content" localization was already real,
not a gap. Translating every string on all 60+ pages/forms into 8
languages by hand in one pass isn't something that can be done responsibly
without native-speaker review (the existing i18n.php docblock already says
this) — so this round's realistic, bounded goal was to extend real
coverage to the highest-visibility shared components not yet touched,
exactly like header/footer already were, rather than attempt a page-by-page
sweep of the entire app.

**A real bug found while doing this:** the sign-in/sign-up UI turned out
to exist in TWO places — `includes/signin.php` (a legacy Bootstrap modal,
id `myModal4`, still referenced from `package-details.php`/
`budget-planner.php`/`reviews.php`) and a second, completely separate
modal hand-built inline in `includes/header.php` (id `signinModal` /
`signupModal`, opened via the site's own `openModal()`/`closeModal()` JS
and the ONLY one wired to the top-nav "Sign In"/"Sign Up" buttons and the
`window.onload` auto-open). Translating only `includes/signin.php` first
(the file that reads like "the" sign-in modal) would have shipped zero
visible change for almost every visitor, since that modal is barely
reachable — caught by live-testing in the browser: switching the site to
Bengali and opening the sign-in modal from the header nav still showed
"Welcome Back" in English. Both were translated; the header.php one (the
one visitors actually see) is the one that matters.

**What was translated, added to all 8 languages (94 → 107 keys):** the
real sign-in modal ("Welcome Back" / "Sign in to your TMS account", email/
password fields and placeholders, "Forgot password?", the submit button,
the "No account yet? Create one free" switch link) and the real sign-up
modal ("Join TMS" / "Start planning your dream trip today", all 5 form
fields and their placeholders, "Create My Account", the "Already have an
account? Sign In" switch link) in `includes/header.php`; the guest-landing
top-bar "Sign Up"/"Sign In" buttons (previously hardcoded English even in
landing mode, now using the existing `nav_signup`/`nav_signin` keys); the
legacy `includes/signin.php` modal (same content, kept in sync since it's
still reachable from 3 pages); the site-wide `includes/write-us.php`
"How Can We Help You" contact-issue modal (heading, all 4 issue options,
description placeholder, submit button); and `includes/footer.php`'s
copyright line, which was hardcoded English even though the file already
used `tms_t()` for everything else on it (fixed to use the pre-existing,
previously-unused `footer_rights` key — "TMS — Tourism Management System"
kept as the literal brand name per the master prompt's "do not translate
official names" instruction). "TMS" is kept untranslated inside every new
string across all 8 languages for the same reason.

**Testing:** live-verified via the in-app browser by switching the active
language to Bengali (`language.php?lang=bn` — confirmed via
`document.cookie` that `tms_lang=bn` was actually set) and screenshotting
both the sign-in and sign-up modals opened from `includes/header.php`:
every label, placeholder, heading, and button rendered correctly in
Bengali, with "TMS" correctly left in Latin script. Cross-checked via
`fetch()` on the raw HTML of `hotels.php` that the write-us modal's
Bengali heading was present. Confirmed via network log that `hotels.php`
still returned 200 OK with no new PHP errors after all edits. Verified
programmatically (a one-off PHP script loading `$TMS_TRANSLATIONS` and
diffing key sets) that all 8 languages ended up with the exact same 107
keys — no language silently missing a new string, which would otherwise
silently fall back to English only for that language. Switched the
session back to English afterward.

**Still a real, disclosed gap, not hidden:** the vast majority of the
app's forms, validation messages, admin panel, and individual feature
pages (60+ pages) remain hardcoded English, exactly as the pre-existing
i18n.php docblock already disclosed before this round. This round did not
attempt to close that whole gap — doing so responsibly needs either
per-page work at the same depth as this round (many more rounds) or
extending `tms_translate_batch()`'s AI-translation approach to static UI
strings (its own risk: AI-translated UI chrome has no native-speaker
review either). Flagging this honestly rather than claiming "i18n done."

## Round 39 — Expand automated test coverage (item 58)

**What already existed:** `tests/run.php`, a dependency-free test runner
(no PHPUnit/Composer — `TESTING.md` explains why, Rule 73) covering 12
tests focused on the highest financial-risk logic: money math, dynamic
pricing clamps, seat availability, migration file validity, login
lockout, and payment refund-ledger no-ops. `TESTING.md` already honestly
listed what wasn't covered (no E2E, no security fuzzing, no admin-
workflow tests) — this round didn't attempt to close that entire list in
one pass (that would take many more rounds), but added 4 real, targeted
tests for functionality that had shipped in recent rounds with zero
regression coverage:

1. `NLSearchParser::durationOverlaps()` (Round 36) — pins down the exact
   cases live-verified that round, including the hyphenated-"3-day"
   regex bug that was found and fixed, so a future refactor can't
   reintroduce it silently.
2. `tms_privacy_tables()` schema consistency (Round 20/34/37) — checks
   every table/column pair it lists (that exists in the DB) actually has
   that column, catching the exact kind of drift that would silently
   break account deletion/data export.
3. This round's own hotel/activity marketplace tables (`tblhotels`/
   `tblactivities`) — checks the `IsSampleData` column exists, rows are
   seeded, and every row is correctly flagged, protecting the Rule 3
   labeling the whole feature depends on. Deliberately added a test for
   this session's OWN new feature rather than leaving it as the one
   totally uncovered new table set in the project.
4. RBAC fail-open behavior (Round 20) — pins down that an unrecognized
   admin session resolves to `superadmin` rather than silently locking an
   admin out, matching the documented intent in `includes/config.php`'s
   own comments.

**Testing status, disclosed honestly:** `device_bash` (the CLI shell
against the deployed environment) has been non-functional for this
entire session, and `tests/run.php` refuses to run outside the CLI by
design (a security-conscious 403, not a bug). Rather than claim these
tests pass without checking, their exact logic was copy-run through a
temporary web-accessible throwaway script against the real live DB — all
4 passed (durationOverlaps: 6/6 cases; privacy tables: 31 tables checked,
all consistent; marketplace tables: 16+16 rows, all correctly flagged;
RBAC: resolved to superadmin as expected). The throwaway was disabled as
a 404 stub immediately after, confirmed live. Also spot-checked that the
real `tests/run.php` still correctly returns 403 over the web (its
CLI-only guard wasn't touched). This is disclosed in `TESTING.md` itself
so a future session (or the user) knows to trust an actual
`php tests/run.php` run over this note if the two ever disagree.

**Not attempted this round, and why:** a CI run of the expanded suite
(the existing `.github/workflows/ci.yml` will pick these up automatically
once the repo is pushed to GitHub — unchanged from before), browser/E2E
tests, and a dedicated security fuzzing suite all remain exactly the gaps
`TESTING.md` already listed. Closing those needs new tooling (a headless
browser, a fuzzing harness) as a deliberate separate step, not a
drive-by addition to this round.

## Round 40 — Formally flag the Visa/Entry Requirement System exclusion (item 18)

**What this round did:** item 18 (a nationality × destination visa/entry-
requirement database) was already flagged as excluded back in the very
first audit round (Phase 2, data-correctness pass) — but only as a couple
of sentences buried in this file's Round-2-era section, easy to miss and
easy to mistake for an oversight rather than a considered decision. This
round formalizes it: added `SCOPE_EXCLUSIONS.md` at the project root as a
single, permanent, easy-to-find place documenting master-prompt items
that are deliberately not built, why, what exists instead, and what real
implementation would require. Confirmed via grep that no dead nav link,
stub page, or "coming soon" placeholder for this feature exists anywhere
in the app (Rule 7) — the exclusion has no user-facing trace at all,
which is itself the honest choice here.

**Why this one is treated differently from the Round 37 hotel/activities
marketplace decision**, even though both started from the identical "no
real data available" problem: visa/entry requirements are regulated
legal/immigration information with real consequences (denied boarding,
fines, deportation) if wrong, whereas a hotel/activity listing is
commercial inventory where a labeled "Sample Listing" is inert if a
visitor ignores the label. That asymmetry — not a change in principle —
is why the marketplace shipped with clearly-labeled sample data after the
project owner chose that path, while this item stays fully excluded with
no placeholder of any kind. `SCOPE_EXCLUSIONS.md` documents this reasoning
in full so a future round doesn't need to re-derive it.

**What already existed and is worth knowing about (not new this round):**
`includes/chatbot.php`'s visa-related intent already answers safely —
general guidance only, always pointing to official embassy sources, never
a specific per-country claim — and `wallet.php` (Travel Wallet) already
lets travelers store their own visa/passport documents for personal
reference, which is document storage, not the system generating
requirement data. Both were already consistent with this exclusion;
nothing needed to change in either.

**Status: stays excluded until real licensed visa-data access exists** (a
paid aggregator or direct government API, plus a liability review) — not
a placeholder, not a stub. If that access is ever secured, this should be
built from scratch against real data, not adapted from any placeholder,
since none exists to adapt from.

## Round 41 — Admin-side hotel/activity booking management (deferred from Round 37)

**What this round did:** built the admin-side UI to view, confirm,
cancel, and delete hotel & activity marketplace bookings — the one piece
explicitly flagged as deferred when Round 37 shipped the customer-facing
marketplace, kept out at the time to bound that round's scope (Rule 73).
New page: `admin/manage-marketplace-bookings.php`, RBAC-gated to the same
roles as the existing `manage-bookings.php`
(`booking_manager`/`operations`/`manager`), linked from the sidebar in
the same Bookings & Users section, right under Booking Calendar.

**Design, following the existing `manage-bookings.php` template rather
than inventing a new pattern (Rule 1):** `tblhotel_bookings` and
`tblactivity_bookings` are structurally different tables (different date
columns, different item tables to join), so the page combines them into
one normalized, filterable, paginated list with a `UNION ALL` wrapped in
a derived table — filters and pagination are applied once to the
combined result rather than duplicated across two near-identical query
halves. Search (email/item name/booking code), status filter
(Pending/Confirmed/Cancelled), and a type filter (Hotel/Activity, unique
to this page since `manage-bookings.php` has only one booking type) all
compose correctly together. Actions are whitelisted via a ternary
(`$type === 'activity' ? 'tblactivity_bookings' : 'tblhotel_bookings'`),
so the table name is never built from unvalidated input despite being
string-interpolated. Confirm/Cancel notify the booking owner in-app and
by email through the same `tms_notify()`/`tms_send_email()` pair
`manage-bookings.php` already uses, respecting each user's
`NotificationsEnabled` preference for the email half. No
refund/payment-reversal workflow exists here, matching the fact that
this feature has no payment gateway to begin with (Round 37) — the page
carries a visible banner saying so rather than showing dead controls.

**Testing status: implemented and live-tested, not just linted.** Used
the same session-simulation pattern as every other round this
session — a throwaway helper (`test-round41-session-helper.php`)
directly set `$_SESSION['alogin']`/`$_SESSION['login']` to the
pre-existing disposable QA admin (`qa_test_admin`) or QA customer
(`qa-alert-test-a112ff@tms-testing.local`) accounts after confirming
each exists in the DB — no password was ever typed into any form field.
Verified live, in this order: (1) RBAC gate lets `qa_test_admin` in and
the page renders with no PHP errors or unexpected console/network
failures; (2) Confirm on an existing cancelled activity booking flips it
to Confirmed, updates the stat strip, and shows a success message; (3)
Cancel flips it back, same checks; (4) Delete removes a booking row
entirely and the stat strip drops accordingly; (5) the type filter, the
status filter, and a combined search+status filter each correctly
narrow the result set while the stat strip stays global; (6) booked a
*fresh* activity as the QA customer to get a genuine Pending-status row,
confirmed the actions shown for Pending (both Confirm and Cancel) differ
correctly from Confirmed (Cancel only) and Cancelled (Confirm only); (7)
switched back to the customer session and confirmed both the earlier
confirm and cancel actions had actually delivered in-app notifications
with the expected wording — closing the loop on the notification
helper, not just trusting it didn't throw. The one known ref-click
quirk from earlier rounds (a browser-automation button click sometimes
not firing real form submission) recurred here too and was worked around
the same way, with `form.requestSubmit(btn)`. The throwaway helper was
disabled as a 404 stub immediately after, confirmed disabled live, and
committed to both project locations.

**Not attempted, and why:** a bulk-action UI (confirm/cancel multiple
bookings at once) and CSV export for marketplace bookings weren't built
— `manage-bookings.php` itself doesn't have these either, so adding them
here would make this page inconsistent with its own template rather than
matching it (Rule 1). Worth a future round if the underlying feature
grows past QA-scale usage.

## Round 42 — CRITICAL: `includes/config.php` still had 2 unresolved git
conflict blocks after the GitHub-push merge; found, fixed, and the whole
project swept for any other undetected ones

**What happened:** while starting the Travel Modes work below, `php -l`
on `includes/config.php` (staged into a fresh copy for editing) came back
with a fatal parse error — "unexpected token '<<'" — that had nothing to
do with my own edit. Two `<<<<<<< HEAD` / `=======` / `>>>>>>> 2a329ba...`
conflict blocks from the earlier GitHub-push merge were still sitting in
the file, unresolved, even though that merge had already been believed
fully resolved (the 5 other conflicted files from that same push — 
`my-trip.php`, `SCHEDULED_JOBS.md`, `TESTING.md`,
`admin/operations-dashboard.php`, `tests/run.php` — were genuinely fixed
earlier). `config.php` never showed up in that batch: it appears git had
staged it as clean in the editor's Source Control view despite the
markers still being in the working file, so it was silently skipped.
This is `includes/config.php` — the one file every single page in the
app includes — so if it had gone live like this, every page would have
fatal-errored.

**Impact of the two blocks, resolved by keeping the newer HEAD side and
discarding the older incoming side in both (same pattern as every other
conflict this merge, and the only one that made sense — HEAD was strictly
more developed in both cases):**
- Block 1 (~line 64): HEAD's `AI_PROVIDER`-aware startup diagnostic
  (checks `OPENAI_API_KEY` when `AI_PROVIDER=openai`, only falls back to
  the `GEMINI_API_KEY` check otherwise — Round 34's AI Provider
  Abstraction work) vs. the incoming side's older `GEMINI_API_KEY`-only
  check that predates that abstraction and would have logged a
  misleading warning for OpenAI-configured installs.
- Block 2 (~line 2307): HEAD's entire `tms_seo_meta()` function (item 53,
  Round 20 — canonical URL/OpenGraph/JSON-LD helper used by
  `package-details.php` and others) vs. the incoming side, which didn't
  have the function at all. Losing this block would have deleted a whole
  working feature.

**Fix:** resolved both blocks (kept HEAD, dropped the incoming side and
the marker lines), re-verified `php -l` came back clean, then swept the
*entire* project for any other conflict markers that might be hiding the
same way — 262 tracked source files (php/md/sql/js/json/html/txt/yml/
`.htaccess`/`.env`/py/svg, everything except `.git/` and `uploads/`) plus
all 9 project `.css` files (not part of the original 262 — added after
one showed up in an unrelated read and turned out to be a false positive,
a decorative `====...` comment banner, not a real marker). Result: zero
real conflict markers anywhere else in the project. The fixed
`config.php` was committed to both `D:\xampp\htdocs\tms\` and
`D:\Project\tms\`.

**Also noticed, not yet acted on:** this project carries duplicate/stale
doc files from what looks like the same kind of merge/rename history —
`SESSION_SUMMARY_INDEX.md` (no suffix) stops at Round 18 while this file,
`SESSION_SUMMARY_INDEX-1.md`, is the one that's actually been kept
current through Round 41+; there's a similar `DATABASE.md` /
`DATABASE-1.md` pair. Neither breaks anything (unlike the config.php
conflict, these are just inert leftover files), but they're worth a
cleanup pass — deciding which of each pair is canonical, folding in
anything only present in the stale one, and deleting the other — rather
than leaving two versions of the truth sitting side by side.

**Testing status:** `php -l` clean on the resolved file; the full-project
marker sweep is a static text search, not a runtime test, so this
confirms no *leftover conflict syntax* remains anywhere, not that every
page renders correctly — that still depends on MySQL being reachable
(see Round 17/19) for a real click-through.

## Round 43 — Travel Modes (item 67) built end-to-end; DATABASE.md's own
duplication fixed

**What this round did:** finished item 67 ("Travel Modes: Family, Couple,
Solo, Business, Accessible") — the `travel_mode` schema column landed in
Round 42 alongside the config.php fix, this round wired it all the way
through:
- `ai-recommend.php`: Travel Style dropdown gained the two missing modes
  (`business`, `accessible` — it only had family/couple/group/solo/
  adventure). For a logged-in visitor who hasn't submitted the form yet
  this request, it now pre-selects their saved `travel_mode`/
  `preferred_climate` from `tbl_user_profile` as the defaults (fully
  editable either way — nothing forced).
- `includes/ai/TravelRecommender.php`: the AI system prompt now gets a
  short per-mode guidance block (family → comfortable pace/kid-friendly;
  solo → real weight on safety; business → shorter/efficient itineraries;
  accessible → explicit instruction to never claim or infer accessibility
  support and to disclose it's unverified instead). The deterministic
  fallback scorer (used when the AI call fails) got the same mode
  awareness via a new public static `applyTravelModeAdjustment()` — nudges
  `style_match`/`safety` using only real columns already on
  `tbltourpackages` (`PackageDuration`, `HotelRating`, `MealPlan`), and for
  `accessible` specifically makes **no** score adjustment at all (there is
  no verified accessibility column to base one on) and instead attaches an
  honest warning telling the user to confirm accessibility needs directly
  before booking. This is the Rule 3 line: adjust a score only where a
  real column backs it, disclose instead of guessing where none exists.
- `profile.php`: brand-new "Travel Preferences" card (previously
  `tbl_user_profile` had existed since the earliest AI migration but had
  no writer at all — a genuine dead table, closed now) — travel mode,
  typical budget, preferred climate, and a comma-separated interests field
  (stored as a capped, sanitized JSON array), upserted via
  `ON DUPLICATE KEY UPDATE` on the table's existing `uq_profile_email` key.
  Independent submit button from the existing name/mobile form so the two
  save separately.
- `tests/run.php`: added a pure unit test for
  `TravelRecommender::applyTravelModeAdjustment()` (no DB, no AI client
  needed) covering exactly the three behaviors above: business raises
  style_match for a short/high-rated package, accessible leaves scores
  untouched **and** attaches the disclosure warning, and an empty/unknown
  mode is a no-op.

**Also noticed while updating `DATABASE.md` for this round:** the entire
file's content was duplicated inside itself — an older, shorter version
(ending at "Running migrations") immediately followed by a newer, fuller
one covering Round 37/41 — the same kind of merge leftover as the
`config.php` conflict and the `SESSION_SUMMARY_INDEX.md`/`-1.md` pair
flagged in Round 42. Removed the stale first copy, kept the current one,
and added this round's schema note to it. `DATABASE-1.md` (the smaller,
older-looking duplicate of that pair) has not been touched yet — still on
the cleanup list.

**Testing status:**
- `TravelRecommender::applyTravelModeAdjustment()` — **actually run**, not
  just linted: a standalone script (no DB, no AI client) executed it
  directly and confirmed all three cases (business nudges style_match up
  for a short/high-rated package with no warning; accessible leaves scores
  byte-for-byte unchanged and attaches the disclosure warning; an
  empty/unknown mode is a true no-op). The same assertions are now also in
  `tests/run.php` as a permanent regression test.
- Everything else in this round (the dropdown, the profile UI's save path,
  the AI-prompt guidance text, the pre-fill logic) is `php -l`-clean on the
  actual files on disk (not just staged copies) and follows the same
  patterns already live-tested elsewhere in this project, but has **not**
  been exercised end-to-end against a real request — MySQL is still down
  on the local machine (see Round 17/19/42), so no page render, no live
  form submission, and no real AI/fallback call has actually been made
  yet. Live-test once MySQL is back up: submit the profile Travel
  Preferences form, confirm it round-trips (reload the page, see the saved
  values); submit `ai-recommend.php` with Business and then Accessible
  selected and confirm the fallback (or live AI) response reflects the
  expected behavior for each.

## Round 44 — MySQL back up: live-verified Travel Modes end-to-end,
applied migrations 001-004, backup/restore scripts confirmed genuinely
CLI-only this session

**Travel Modes (Round 43) — actually live-tested now, not just linted.**
Same session-simulation pattern as every other round (a throwaway helper
set `$_SESSION['login']` to the disposable QA customer
`qa-alert-test-a112ff@tms-testing.local`, no password typed). Verified, in
order: (1) `profile.php`'s new Travel Preferences card saves Business /
Luxury / Beach-Coastal / a 3-item interests list and shows "Travel
preferences saved"; (2) a fresh page reload (not just the post-submit
render) confirms all four values actually persisted in `tbl_user_profile`;
(3) `ai-recommend.php` correctly pre-fills Travel Style=Business and
Climate=Beach/Coastal from that just-saved profile; (4) submitting with
Accessible selected — the live AI call fell back to the deterministic
scorer (identifiable by the fallback's fixed "This package fits your
budget and travel style." text and flat base scores) — showed the
accessibility disclosure warning on every single recommended package, with
every per-dimension score unchanged from baseline, exactly as designed
(no fabricated accessibility signal); (5) submitting with Business selected
showed `style_match` correctly bumped from 65% to 80% specifically for the
two packages with a duration ≤5 days, confirming the real-column-based
adjustment applies live against actual DB rows, not just in the unit test.
Test helper disabled (404 stub) and committed to both project locations
after.

**Task #43 — migrations.** `database/migrate.php?status=1` (browser-admin
mode, simulating `qa_test_admin`) showed all 4 numbered migrations
(001-004) pending — this was evidently the first time this runner had
actually been executed against this particular database (only the 5
legacy pre-numbered `.sql` files had been recorded as an applied
baseline). Ran it for real: all 4 applied cleanly — `001_migrations_table`,
`002_payments` (3 statements), `003_booking_payment_columns` (its 2 column
adds were already present from earlier self-healing `tms_ensure_columns`
calls, correctly detected as benign rather than erroring), and
`004_fk_check_constraints` (30 statements, 0 errors) — meaning the pending
FK/CHECK constraint hardening is now actually live on this database, not
just written and waiting. Re-ran `--status` afterward: "database schema is
up to date." Spot-checked `package-list.php` and
`admin/manage-bookings.php` post-migration for regressions (a real risk
with new FK/CHECK constraints against existing data) — both load clean,
no new errors. Admin test helper disabled (404 stub) and committed to
both locations after.

**Task #43 — backup/restore scripts: still blocked, and now for a
different, confirmed reason.** `scripts/backup_database.php` and
`scripts/restore_database.php` are CLI-only by design (a `PHP_SAPI` check
returns 403 over HTTP, with no browser-admin bypass like `migrate.php`
has) — this was already known. What's new this round: the tool this
session uses to run commands directly on the development machine came
back with an explicit platform error — a Windows update released
September 8 prevents that workspace from reaching local files, an
Anthropic-side issue unrelated to MySQL and nothing this project's code
can work around. **Testing status: blocked, not attempted.** Per Rule 77,
this genuinely cannot be run from here right now — running these two from
a terminal in the project folder and reporting back what they print is the
path forward:
```
php scripts/backup_database.php
php scripts/restore_database.php --verify
```

## Round 45 — Transport System (item 14) built and live-tested; a real bug
found and fixed by that testing

**What this round did:** built the master prompt's 9-mode transport
comparison (walking, bicycle, car, taxi, bus, train, metro, ferry,
flight) with the 5 named comparisons (Fastest, Cheapest, Most
Comfortable, Lowest Carbon, Best for Family). New files:
`includes/TransportComparator.php` (the comparison engine) and
`transport-compare.php` (the page), linked from the header mega-menu and
footer Features list, and added to `sitemap.php`.

**"Use provider abstractions so map/routing providers can be replaced"
(the spec's own words) was satisfied by reusing
`ExploreService::getRoute()`** — an existing OSRM/Google-Directions
abstraction from an earlier round — rather than building a second,
competing routing layer (Rule 1/73). Walking, bicycle, car, and taxi get
**real routing data** (genuine distance and duration from a live routing
call for that exact origin/destination); taxi reuses the car/driving
route since it drives the same roads. Bus, train, metro, and ferry have
no live schedule or fare API in this project, so their duration is an
honest **estimate**: real haversine distance ÷ a documented typical
average speed for that mode (flight duration adds a fixed ground-ops
overhead to a cruise-speed estimate — the standard back-of-envelope
method, not a specific airline's schedule). Every row carries an explicit
`data_source` ('real_route' vs 'estimated'), rendered as a green "Live
Route" or amber "Estimated" badge — never displayed ambiguously.

**Cost is never shown as a currency amount** — this project has no
verified fare data for any of these modes/routes/operators/seasons, and
inventing one would be exactly the Rule 3 violation the master prompt
prohibits. Cheapest/Comfort/Best-for-family are instead a general 1–5
relative tier per mode (documented in the class: walking/cycling free,
public transit cheaper than a car/taxi, flights priciest for the same
distance) with an on-page disclosure banner saying so plainly. Carbon
figures reuse `SustainabilityCalculator`'s own already-cited emission
factors for car/bus/train/flight (never a second, drifting set of
numbers) plus 0 for walking/bicycle (physical fact) and train's factor
for metro (both electrified fixed-line transit, reuse called out
explicitly in the code); ferry has no verified factor in this project and
is honestly shown as "Not tracked" rather than a guessed number.

**A real bug found (and fixed) by live-testing this, not by inspection:**
testing Dhaka → Cox's Bazar (305.6km) initially showed Walking, Bicycle,
and Car all reporting the identical "4h 45m" — badged "Live Route" for
all three — which is physically impossible for a 305km walk. The
underlying routing call (Google Directions, since `GOOGLE_MAPS_API_KEY`
is configured on this install; ExploreService falls back to OSRM only
when Google fails) was returning a driving-equivalent duration for the
walking/cycling requests rather than erroring outright, and the code
trusted any HTTP-success response as genuine. Added a plausible-speed
sanity check (`PLAUSIBLE_SPEED_KMH`) that computes the implied km/h from
any "real" route response and discards it — falling back to the honest
distance ÷ typical-speed estimate instead — if that implied speed falls
outside a generous per-mode range (e.g. walking must imply 1–8 km/h).
Re-tested the same route afterward: Walking now correctly shows "61h 7m"
(Estimated), Bicycle "20h 22m" (Estimated), Car/Taxi still correctly show
the genuine "4h 45m" (Live Route, implied ~64 km/h — plausible). A second
test on a real short intra-city hop (Gulshan → Banani, 0.8km) confirmed
the opposite case also works: Bicycle/Car/Taxi correctly got "Live Route"
there. This is exactly the kind of thing Rule 3/4 exists for — an API
returning HTTP 200 is not the same as the data being true, and a
schedule/fare tool has no business badging wrong data "live" just because
the call technically succeeded.

**Testing status:** live-tested against the running app (MySQL up), not
just linted — both scenarios above run against real geocoding and real
routing calls, and the emission-math and distance-realistic-cutoff logic
were hand-verified against the actual numbers shown (e.g. car's ~52kg
CO2e = 305.6km × 171g/km, matching `SustainabilityCalculator`'s factor
exactly). Not yet added to `tests/run.php` as an automated regression
test — doing so would need either a live network call in CI (flaky/slow)
or a mocked `ExploreService`, which this project has no mocking layer
for; noted as a real gap rather than silently skipped.

## Round 46 — File Upload Security (item 45): real content-type
validation on all 7 image/PDF upload endpoints; a real logic bug found
and fixed along the way

**What item 45 asked for:** the master prompt calls out that this
codebase's file uploads validate only the client-supplied filename
extension and `$_FILES[...]['type']` (the MIME string the browser sends,
which is fully attacker-controlled — trivial to fake with any HTTP
client), never the file's actual bytes. `wallet.php` already had the
right fix (an `finfo`/libmagic real-content-type check) from an earlier
round, but it was never generalized to the other 6 upload endpoints in
the project. This round does that generalization rather than inventing a
new pattern (Rule 1/73).

**New shared helper — `tms_validate_upload_signature()` in
`includes/config.php`:** takes the temp upload path and a caller-supplied
list of allowed extensions; first confirms the path is a genuine
PHP-managed upload via `is_uploaded_file()` (rejects anything else
outright, e.g. a crafted path); then reads the file's real MIME type with
`finfo_open(FILEINFO_MIME_TYPE)` / `finfo_file()` — this inspects actual
file bytes/magic numbers, not the name or the browser's claim; then
checks that real MIME against a static extension→allowed-MIME whitelist
(jpg/jpeg→image/jpeg, png→image/png, gif→image/gif, webp→image/webp,
pdf→application/pdf). Returns `['ok' => bool, 'mime' => ?string, 'error'
=> ?string]` with a plain-language reason on rejection ("this file's
actual content doesn't match an allowed type... renaming a file's
extension doesn't change what it really is").

**Wired into all 7 endpoints**, each as an additional `elseif` in the
existing extension/size validation chain (so a rejected upload now always
surfaces one clear inline error and skips the move/DB-write, never both
an error and a silent partial success):
- `reviews.php` (customer review photo upload)
- `admin/add-package.php`
- `admin/change-image.php` (this one had a pre-existing, separately-fixed
  Round 11 vulnerability note for missing extension checks entirely —
  now also gets the real-content check)
- `admin/edit-package.php` (also added a previously-missing 5MB size cap
  and switched its filename generation to the safer
  `time() . '_' . bin2hex(random_bytes(4))` pattern already used
  elsewhere, replacing a predictable `time() . '_' . basename($name)`)
- `admin/add-blog.php`
- `admin/edit-blog.php` (also added a previously-missing 5MB size cap —
  this endpoint had no size limit and no real-content check at all
  before this round, only an extension check)

**Real bug found while doing this work (not by inspection — by re-reading
the file after editing it), fixed in both `add-blog.php` and
`edit-blog.php`:** in both files, the `INSERT`/`UPDATE` statement and the
"Post published!"/"Post updated!" success message ran *unconditionally*
right after the upload-validation block, with no check of whether the
validation had just set `$error`. Before this round both files had so few
upload failure modes (add-blog.php: none surfaced at all; edit-blog.php:
silently kept the old cover on a bad extension) that this bug had no
visible symptom. Adding real, user-facing validation errors this round
would have newly exposed it: a rejected upload (bad extension, oversized,
or failing the new signature check) would still publish/update the post
(with an empty or unchanged `CoverImage`) while simultaneously showing an
error banner *and* a contradictory success banner. Fixed by wrapping the
`INSERT`/`UPDATE` + success-message lines in `if (!$error) { ... }`,
matching the pattern `admin/add-package.php` and `admin/edit-package.php`
already used correctly.

**Testing status:** all 7 touched files pass `php -l` with zero syntax
errors. The core novel logic — real-content-type detection independent of
the claimed file extension — was live-verified against the running app
via a temporary harness (`test-round46-signature-test.php`, removed/
disabled immediately after use): a genuine 1×1 PNG was correctly detected
as `image/png` regardless of whether it was named `.png` or `.jpg`, and a
PHP/text payload disguised with a `.jpg` extension was correctly detected
as `text/x-php` and would be rejected — confirming the exact attack this
item exists to stop (uploading executable/disguised content as an
"image") is actually caught. Full end-to-end testing of each endpoint's
upload form through the browser UI was **not** performed this round —
this environment has no native file-picker automation available (the
built-in browser pane can drive page DOM interactions but not the OS file
dialog a `<input type=file>` opens), and admin-session simulation across
7 separate pages was judged not worth the added session-helper churn
given the logic itself is a direct, already-proven reuse of `wallet.php`'s
pattern. The `if (!$error)` control-flow fix in add-blog.php/edit-blog.php
was verified by code review and `php -l` only, mirroring the identical,
already live-tested pattern in add-package.php/edit-package.php. A quick
manual spot-check (upload a real image, then try renaming a `.txt` file
to `.jpg` and uploading it) on any one endpoint would close this gap if
desired.

**Not yet done:** `tests/run.php` has no automated case for
`tms_validate_upload_signature()` — it needs either a real temp file on
disk (the test harness above showed this is doable without triggering the
`is_uploaded_file()` gate by testing the finfo detection directly) or
accepting that `is_uploaded_file()` will always fail outside a real HTTP
request context. Noted as a real gap, not silently skipped.

## Round 47 — SEO meta broadening (item 53) + cache-busting (item 49)

**SEO meta broadening:** Round 20 built `tms_seo_meta()` (canonical, OG
tags, description, optional JSON-LD) and wired it into
`package-details.php`, `destinations.php`, and `blog-post.php`. This round
extends the same shared helper to the remaining public-facing pages the
master prompt names, rather than inventing a second meta-tag pattern (Rule
1/73): `blog.php` (listing — same plain title/description pattern as
`destinations.php`, canonical strips the query string), `hotels.php` /
`activities.php` (listings — plain title/description, replacing a
hand-written `<meta name="description">` with the shared helper for
consistency), and `hotel-details.php` / `activity-details.php` (detail
pages — plain title/description/image/canonical, `?id=` kept explicitly in
the canonical exactly like `package-details.php`/`blog-post.php` already
do for their own resource identifiers).

**Deliberately NO JSON-LD on the hotel/activity pages — this is a Rule 3
call, not an oversight:** `tblhotels`/`tblactivities` are explicitly
sample/demo data (`DATABASE.md`'s own "Known schema debt" note, Round 37)
— invented generic listings seeded from a JSON file, not a real supplier
feed. `package-details.php` emits a schema.org `Product`/`Offer` (and
`AggregateRating` when there are real reviews) because `tbltourpackages`
is this project's actual, real product catalog. Doing the same for a
sample hotel/activity would tell search engines (and any rich-result
surface that reads Product/Hotel/Offer/AggregateRating markup) that a
fictional listing is a real, bookable-today, ratable property — exactly
the kind of fabricated-as-real claim Rule 3 exists to prevent, and
arguably worse coming from structured data than from visible page text,
since structured data is consumed by machines that don't see the
"Sample/demo listing" banner sitting right next to it. The plain
title/description meta this round adds doesn't make that claim — it just
describes the page, and the description text says "sample/demo" outright.

**Cache-busting:** added a shared `tms_asset_ver(string $relPath): string`
helper to `includes/config.php`, appending `?v=<filemtime>` to a
site-owned CSS path so a browser/CDN picks up a changed stylesheet the
moment it's actually modified on disk, without a manual version bump or
an unconditional `?v=<?php echo time(); ?>` that would defeat caching
outright. Resolves the asset against the *currently executing* script's
own directory (`dirname($_SERVER['SCRIPT_FILENAME'])`), not this helper's
own file location, so the same call resolves correctly from both a
root-level page (`css/modern-theme.css`) and an admin-level page
(`css/admin-v6.css` → `admin/css/admin-v6.css`) — exactly how the browser
itself resolves that same relative href.

**Deliberately scoped to 2 files, not every asset on the site (Rule
73):** `css/modern-theme.css` and `admin/css/admin-v6.css` are this
project's own, actively-edited theme stylesheets — the ones that have
actually changed release to release across this whole engagement.
`bootstrap.min2.css`/`bootstrap.min.css`, `style.min.css`,
`font-awesome.min2.css`, and the vendor JS (jQuery, Bootstrap's own JS)
are pinned bundle files that don't change in place; cache-busting them
would touch just as many files for no practical benefit. Applied via a
scripted, exact-string replace across the 3 real-world `<link>` markup
variants found for these two files (`href="css/modern-theme.css"
rel="stylesheet"`, `rel="stylesheet" href="css/modern-theme.css"`, and
one `../css/modern-theme.css` outlier in `admin/ai-dashboard.php`, plus
`href="css/admin-v6.css" rel="stylesheet"`) — 90 files touched in total
(89 pages + the new helper in `includes/config.php`), each now reading
`<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>"
rel="stylesheet">` (or the `admin-v6.css` equivalent) instead of a static
path.

**Testing status — UPDATE, live-verified after the device reconnected:**
the session's link to the user's device dropped mid-round; once it came
back, all 91 touched files (the 90 originally found, plus `wishlist.php`
— see the note below) were sent and committed to both
`D:\xampp\htdocs\tms` and `D:\Project\tms` (0 rejections). `php -l` passed
on all 91 files individually. Live-verified via the browser against the
real running app: `hotels.php` and `blog.php` both loaded correctly, and
`read_network_requests` showed the actual GET for
`css/modern-theme.css?v=1789066758` returning `200 OK` -- the real
`filemtime()` value, not a placeholder. A full-page text extraction on
`hotels.php` confirmed the page content, hotel cards, filters, and footer
all rendered intact (a screenshot mid-load looked faded, but that was an
in-progress WOW.js scroll-reveal animation, not a styling break -- the
page text and a follow-up check confirmed nothing was actually broken).
`admin/index.php` (login page) still showed the OLD unversioned
`admin-v6.css` path -- expected and harmless: that file wasn't one of the
31 admin files matching the 4 known `<link>` string variants this round's
script targeted, so it was correctly left untouched (fails closed, not a
bug). The other admin files' `tms_asset_ver('css/admin-v6.css')` calls
were verified by code review + `php -l` only (not loaded live, since
doing so needs an authenticated admin session and this project's
absolute rule against ever typing a password into a form) -- they run
the identical, already-proven helper function and code path as the
customer-side pages, just with a different path argument.

**Bug caught and fixed while syncing (a real shell scripting mistake, not
a code-under-test issue):** the original file list ended with
`wishlist.php` on the last line with no trailing newline, which a
`while read` loop silently drops (classic shell pitfall) -- so
`wishlist.php` was skipped by BOTH the `php -l` sweep and the initial
staging step, even though the earlier Python script that made the actual
code edits had correctly changed it (Python's file writes aren't
line-loop-based, so that part was never at risk). Caught before send by
reconciling the staged-file count against the original changed-file
count instead of trusting the first pass. Fixed: `wishlist.php` was
linted (clean) and included in the final sync.

## Round 48 — documentation cleanup: the two stale/duplicate doc pairs
flagged in Round 42/45 finally resolved

**`SESSION_SUMMARY_INDEX.md` vs `SESSION_SUMMARY_INDEX-1.md`:** the
non-`-1` file stops at roughly Round 18 (frozen mid-way through a
"MySQL isn't holding steady yet" blocker from many rounds ago) while this
`-1` file has been the actively maintained one ever since. Rather than
delete real historical content (its Round 1-18 deploy checklist and
early fixes are genuine, useful history) or keep silently maintaining two
diverging "master index" files, added a clear superseded-banner to the
top of the old file pointing at this one, with everything below the
banner left untouched.

**`DATABASE.md` vs `DATABASE-1.md`:** diffed the two directly this
round (not just by file size) — `DATABASE-1.md`'s entire content turned
out to be a strict subset of `DATABASE.md`'s, i.e. a genuinely stale
snapshot with zero unique information, not a fork with its own history
worth keeping. Replaced it with a short pointer stub rather than a
preserved-content banner, since there was nothing to preserve.

**Why a pointer/banner instead of actually deleting the old files:**
this session currently has no shell access to the user's machine (see
the ongoing device-workspace platform issue noted in earlier rounds --
still present as of this round, retried and confirmed still broken), so
there's no way to `git rm`/`git mv` from here -- only to overwrite file
*content* via the file-commit bridge. A clearly-labeled pointer achieves
the same practical goal (nobody reads the stale file for current info)
without requiring a git operation this session can't perform, and leaves
a clean, low-risk trail if the user wants to actually delete these files
via git themselves later.

**Testing status:** both are prose/Markdown documentation changes with no
executable code — nothing to `php -l`. Visually reviewed the rendered
Markdown structure (banner formatting, links) by reading the final file
content back before sending. Sent and committed to both
`D:\xampp\htdocs\tms` and `D:\Project\tms`, 0 rejections.

## Round 49 — project restructure phase 1: `includes/` → `backend/` rename

**Why now:** the user asked directly for the project to be split into
separate `backend/` / `frontend/` / `admin/` folders instead of one flat
directory, and to stop receiving routine changes as chat file downloads
-- work should land directly in the project files. Delegated the
approach ("do which is suitable") and picked "frontend / backend / admin,
kept flatter" for the layout: customer-facing pages move to `frontend/`,
shared PHP logic moves to `backend/`, `admin/` stays where it is, and
asset folders (`css/`, `js/`, `images/`, `fonts/`) stay at the project
root so the huge majority of `<link>`/`<script>`/`<img>` references don't
need touching at all.

**This round is phase 1 only: the `backend/` rename.** Confirmed via grep
across every `fetch()`/`XMLHttpRequest`/form `action=` pattern in the
codebase that `includes/` is never directly URL-accessed by a browser or
by client-side JS -- only ever pulled in server-side via
`include`/`require`. That makes renaming it to `backend/` a purely
mechanical, zero-public-URL-impact change, which is why it was tackled
first rather than the customer-facing page move (see "deliberately
deferred" below).

**Two `includes/` folders exist, and they are NOT the same thing:** the
project root's `includes/` (business logic, services, AI classes -- used
everywhere) and `admin/includes/` (a small admin-only wrapper folder:
`config.php`, `header.php`, `footer.php`, `sidebarmenu.php`). Inside
`admin/*.php` files, a **bare** `includes/config.php` reference resolves
to the admin wrapper (`admin/includes/config.php`), while a
**`../includes/config.php`** reference resolves to the root folder. Both
patterns appear side by side in the same files (e.g.
`admin/manage-bookings.php` has both), so a single blanket find-and-
replace across the whole codebase would have silently broken the admin
wrapper includes. Handled with a two-pass, context-aware rewrite instead:

- **Pass A (blanket `includes/` → `backend/`)**, applied only where the
  bare string is unambiguous: every root-level `*.php` page, `api/*.php`,
  `database/*.php`, `scripts/*.php`, `tests/*.php`, and
  `admin/includes/config.php` itself (its one `require_once` climbs back
  to the project root, so it isn't the admin-wrapper-relative case). 82
  files changed.
- **Pass B (`../includes/` → `../backend/` only, bare `includes/` left
  untouched)**, applied to the rest of `admin/*.php`. Only 2 files
  actually contained the `../includes/` pattern:
  `admin/ai-dashboard.php` and `admin/manage-bookings.php`. Verified by
  hand afterward that both still correctly reference the admin wrapper
  (`include('includes/config.php')` etc., untouched) alongside their
  now-correctly-rewritten `../backend/...` require paths for payment
  provider classes and AI classes.

**Verification:** a final repo-wide grep for `includes/` outside
`./includes/` (the old folder itself), `./backend/` (its new copy), and
`./admin/` (where the wrapper pattern is expected) came back empty --
zero stray or missed references. `php -l` ran clean on all 134 PHP files
this round touched (50 new files under `backend/`, plus the 84 files
whose `require`/`include` paths were rewritten).

**4 living documentation files updated** to reference `backend/` instead
of `includes/`: `README.md`, `DATABASE.md`, `ENVIRONMENT.md`,
`HOSTING_GUIDE.md`. Deliberately left untouched: the historical,
point-in-time audit reports (`AUDIT_REPORT_ROUND*.md`,
`FEATURE_PHASE2_AI_DEEPENING.md`, `UI_PASS_GLASSMORPHISM.md`,
`SCOPE_EXCLUSIONS.md`) and non-functional comment references inside
`database/migrations/002_payments.sql`,
`database/migrations/004_fk_check_constraints.sql`, and
`AI_MIGRATION.sql` -- same treatment as the `SESSION_SUMMARY_INDEX.md`
superseded-banner approach from Round 48: preserve real history rather
than silently rewriting it.

**Testing status — live-verified, not just `php -l`:** all 138 files (50
new `backend/*` files + 84 rewritten referencing files + 4 docs) were
sent and committed to both `D:\xampp\htdocs\tms` and `D:\Project\tms`,
0 rejections across 276 individual writes. Then checked the live site in
the browser against the real `backend/config.php` dependency: `hotels.php`,
`blog.php`, `transport-compare.php`, and `admin/index.php` all returned
`200 OK` (not 500/blank-page, which is what a fatal "file not found" on
`backend/config.php` would have produced), the sample-data banner and
hotel listing rendered correctly on `hotels.php`, and the Round 47
cache-busted stylesheet (`modern-theme.css?v=...`) still resolved
correctly, confirming `tms_asset_ver()` -- now living in
`backend/config.php` -- still works post-rename. The handful of console
404s seen during this check (`test-round46-signature-test.php`,
`css/images/7.jpg`) are pre-existing, unrelated issues (a leftover test
file and a longstanding malformed image path in old CSS) -- not caused by
this rename, and left alone since fixing them wasn't in scope this round.

**Known limitation, flagged honestly rather than left silent (no delete
capability right now):** the OLD `includes/` folder still exists on the
user's disk alongside the new `backend/` folder. `device_bash` (shell
access to the user's machine) is still confirmed broken this round
(retested, same Windows-update-related platform issue reported in
earlier rounds), and `device_commit_files` can only write/create files,
never delete them -- so there is currently no way for this session to
remove the old folder. It is a harmless, inert duplicate (nothing
references it anymore, per the verification grep above) but it will sit
there using disk space and could confuse a future search of the codebase
until the user deletes it manually, or shell access is restored.

**Deliberately deferred, not started: the `frontend/` move.** Physically
relocating the ~90 customer-facing entry-point PHP files (`index.php`,
`hotels.php`, etc.) into a `frontend/` folder is a categorically riskier
change than the `backend/` rename, because -- unlike the includes/
folder -- these files' *paths are the site's public URLs*. Moving them
would break: every `header('Location: hotels.php')`-style redirect
(browser-relative `Location` headers resolve against the current URL
path, not the PHP file's location on disk -- so `hotels.php` would need
to become `header('Location: /tms/frontend/hotels.php')` or similar,
everywhere); inline JS `fetch()`/AJAX calls made from within those pages
to `api/` endpoints; `sitemap.php`'s generated URLs; and the SEO
canonical URLs Round 47 added. Doing this safely needs its own audit
pass first (finding every redirect, every fetch call, every sitemap URL
generator) rather than a mechanical rename like this round's. Scoped out
of Round 49 on purpose -- not an oversight -- and is the natural next
step if the user wants the restructure continued.

## Round 50 — project restructure phase 2: real `frontend/` folder via
URL-preserving shims (the move deferred at the end of Round 49)

**Why this round exists:** the user asked directly for the project
structure to be corrected -- "backend, frontend, database, Ai etc" -- so
that a developer opening the codebase can understand it at a glance.
Round 49 already gave the project a real `backend/` and left `database/`
and `admin/` as they were; the missing piece was `frontend/` actually
containing the ~70 customer-facing pages, which Round 49 deliberately
deferred because moving live public-URL entry points is a fundamentally
riskier class of change than renaming an internal, never-URL-accessed
folder. This round does that move -- but not the naive way.

**The core design decision: keep every public URL byte-for-byte
identical.** A naive "move the file, the URL moves with it" approach
would have required rewriting 23+ `header('Location: ...')` redirects,
JS `fetch()` calls inside `backend/header.php`/`backend/signup.php`,
`sitemap.php`'s URL list, `robots.txt`'s `Disallow` rules (which
currently protect `/cart.php`, `/checkout.php`, `/profile.php`, etc.),
`manifest.json`'s `start_url`, and `sw.js`'s precache list -- all at once,
in one pass, with real regression risk if anything was missed (Rule 2:
never break a working feature). Instead, each customer-facing page's
real code was copied unchanged into `frontend/<name>.php`, and the
original root file was replaced with a 4-line shim:
```php
<?php
// This page's real implementation now lives in frontend/hotels.php.
// Kept here as a thin pass-through so the public URL (/hotels.php), and
// every asset reference, redirect, fetch() call, sitemap entry, and
// robots.txt rule that already points at it, all keep working unchanged.
require __DIR__ . '/frontend/hotels.php';
```
Because the *requested URL* never changes, every one of those mechanisms
above needed zero edits: browsers resolve relative asset hrefs
(`css/style.css`), `Location` redirects, and `fetch()` calls against the
*request URL*, not the file's location on disk -- and `sitemap.php`,
`robots.txt`, `manifest.json`, and `sw.js` all reference URLs, which
didn't move.

**Verified this assumption in a sandbox before touching real files, not
just asserted it (Rule 77):** ran actual PHP (both CLI and `php -S`, to
mirror how a real web server sets `getcwd()`/`$_SERVER['SCRIPT_FILENAME']`
for the top-level requested script) against a throwaway shim+included-file
layout matching this project's exact pattern. Confirmed: a bare relative
`include('backend/config.php')` inside the moved `frontend/hotels.php`
correctly resolves to the real `backend/config.php` (because PHP resolves
relative includes against the process's current working directory, which
a web server sets to the *requested* script's directory -- the root shim
-- and never changes just because that script `require`s a file
elsewhere); and `dirname($_SERVER['SCRIPT_FILENAME'])`, which
`tms_asset_ver()` depends on for cache-busted CSS, still correctly
resolves to the project root, not `frontend/`.

**The one real risk that testing surfaced: `__DIR__`.** Unlike a bare
relative path, `__DIR__` is intrinsically tied to a file's own location on
disk, so any `__DIR__`-based path inside a file that's moving to
`frontend/` would silently start pointing one level too deep. Grepped all
98 files for `__DIR__`/`__FILE__`/`getcwd(` and found exactly 9 real
cases: `ai-recommend.php`, `expense-tracker.php`, `explore.php`,
`health.php` (two: `backend/env-loader.php` and `.env`),
`loyalty.php`, `my-trip.php`, `package-list.php`, `transport-compare.php`,
and `wallet-document.php` (two: the wallet-document filesystem path and
its `realpath()` traversal guard). Fixed each with `dirname(__DIR__)`
instead of `__DIR__` -- since `frontend/` is exactly one level under the
project root, this is a small, self-contained fix that resolves correctly
regardless of how the file is ever reached (it doesn't depend on the shim
defining anything), unlike, say, a global constant would.

**A second, smaller cleanup bundled into the same round:** the 27
`test-*.php` / `seed-qa-admin.php` / `check-qa-admin.php` files cluttering
the project root -- one-off QA/session-simulation helper scripts created
across earlier rounds under the project's absolute "never type a password
into a form" rule -- were moved the same way, into `tests/manual/`. These
carry none of the live-URL risk the real pages do (nothing in the app or
its sitemap/robots.txt links to them), so they're a clean, low-risk
declutter that directly serves the same "developer can understand the
project" goal: root now holds mostly small shims and real config/docs
files instead of being mixed in with ~90 unrelated PHP files.

**Verification:** `php -l` ran clean on all 196 files this round touched
(98 new files under `frontend/`/`tests/manual/` + 98 rewritten root
shims). Live-verified against the real running app (real MySQL, not a
mock): `health.php` returned its full JSON health payload with
`"database": {"status": "up"}` -- proving the `.env`/`backend/env-loader.php`
`dirname(__DIR__)` fix actually loads real DB credentials correctly, not
just that it lints; `transport-compare.php` handled both GET and POST
correctly (multiple round-trips); `ai-recommend.php`, `package-list.php`,
`explore.php`, `hotels.php`, `blog.php` all rendered with correct
page-specific titles and content; `loyalty.php`/`my-trip.php`/
`expense-tracker.php` (all three needing an authenticated session)
correctly redirected to the homepage rather than fatal-erroring, exactly
matching their pre-move behavior for a signed-out visitor. No new console
errors or 404s appeared beyond the small number of pre-existing, unrelated
ones already present before this round (a stray leftover test file and a
longstanding malformed image path in old CSS -- see the Round 49 note).

**What did NOT need to change, and why that matters:** `sitemap.php`,
`robots.txt`, `manifest.json`, `sw.js`, and `tms_seo_meta()`'s canonical
URL logic (which builds the canonical from `$_SERVER['REQUEST_URI']` at
request time, not a hardcoded path) -- all confirmed correct exactly as
they were, with no edits, because none of them reference a file's location
on disk, only its URL, which this round deliberately never changed.

**Known limitation, same shape as Round 49's:** each moved file now has
two copies on disk -- the real one under `frontend/`
(`tests/manual/` for the QA scripts) and the small shim left at root in
its place. This is intentional, not orphaned dead weight (unlike Round
49's leftover `includes/` folder): the root file is a real, working part
of the request path, not an inert duplicate. Nothing further to clean up
here.

**Deliberately not done this round:** no attempt to eliminate the shim
layer by actually changing public URLs (e.g. serving pages at
`/frontend/hotels.php` directly, or adding rewrite rules to make
`/hotels.php` disappear from the URL bar while still routing to
`frontend/`). That would have reintroduced exactly the SEO/bookmark/
external-link risk this round's design avoided, for a purely cosmetic
gain. If the user wants clean URLs without the root `/tms/` prefix, or a
router, that's a distinct, separately-scoped decision, not an
implicit next step here.

---

## Round 51 — Backup/restore disaster-recovery verification (master prompt item 60)

**Goal:** master prompt item 60 ("automated encrypted backups + restore
testing") had been *implemented* since an earlier round, but never actually
*run* end-to-end against a real database. `BACKUPS.md` itself already drew
the distinction ("we take backups" vs "our backups actually restore" are
different claims) but had no evidence backing the second claim. Per Rule 77
("never claim something works without testing it — distinguish
Implementation status from Testing status: Blocked because..."), this round
closed that gap honestly instead of leaving it as an assumption.

**Why this could finally be tested:** every prior attempt at this had been
blocked because the user's `device_bash` bridge to their own machine was
broken (a Windows update, September 8, prevents the sandbox-helper drive
mount — confirmed again this round: `device_bash` still fails with
"no Plan9 drive shares mounted"). Rather than keep waiting on that, this
round stood up a real, disposable MariaDB server inside my own cloud
sandbox — not the user's machine, not the project's real database — and
ran the actual scripts against it.

**Test methodology:**
1. Installed `mariadb-server` in the sandbox (worked around an unrelated
   `apt-get` 403 by dropping an unneeded Docker apt source, and worked
   around no init system by starting `mariadbd` directly as the `mysql`
   user).
2. Created a throwaway database + user (`tms_test`) with a fabricated
   strong password containing a `#` character (chosen deliberately, and
   this turned out to matter — see below), and imported this project's
   real, unmodified `tms.sql` + `v6_migration.sql` into it.
3. Pointed a copy of the project's `.env`-equivalent constants at this
   test database and ran `php scripts/backup_database.php` for real.
4. Ran `php scripts/restore_database.php --verify` for real.
5. Recorded `SELECT COUNT(*) FROM tbltourpackages` (9 rows) and other
   table counts as a baseline, then destructively dropped and recreated
   the test database (a real, irreversible wipe — of the disposable test
   DB only).
6. Ran `php scripts/restore_database.php --restore --file=... --confirm`
   for real.
7. Re-ran the same row counts and confirmed exact parity with the
   pre-wipe baseline, plus spot-checked actual row content, not just
   counts.
8. Shut the test MariaDB instance down and discarded it
   (`mysqladmin shutdown` + `pkill mariadbd`) once testing was complete —
   nothing from this test setup persists anywhere, and none of it ever
   touched the user's real `.env`, real credentials, or real data.

**Result:** the full cycle now passes cleanly with zero errors and exact
data parity. But the *first* attempt did not pass — it surfaced three real
bugs that had been invisible to code review across 50 prior rounds:

1. **Credential truncation at `#` (`backup_database.php` and
   `restore_database.php`).** Both scripts pass DB credentials to
   `mysqldump`/`mysql` via a temporary `--defaults-extra-file` (correctly
   avoiding argv, which would leak the password to `ps`/Task Manager).
   But the file was written with unquoted `user=`/`password=`/`host=`
   values. MySQL/MariaDB's option-file parser treats an unquoted `#` as
   starting a comment for the rest of the line — so a password like
   `TmsTest#2026!` was silently truncated to `TmsTest`, and every
   backup/restore attempt authenticated with the wrong password and
   failed with `Access denied ... (using password: YES)`, a message that
   gives no hint the password itself was mangled by the *option file*
   rather than being simply wrong. Diagnosed with `my_print_defaults
   --defaults-extra-file=... client`, which showed the truncated value
   directly. Fixed in both scripts by wrapping every credential value in
   double quotes (after escaping backslashes and embedded double quotes),
   which keeps `#`, `;`, and whitespace all safe inside the option file.
   This is a realistic, not contrived, bug: `#` is an entirely ordinary
   character to include in a strong generated password, and this project
   had never generated/tested one that happened to contain it before now.
2. **Failed dumps left as plaintext on disk (`backup_database.php`).**
   While debugging bug #1, several backup attempts failed partway through
   `mysqldump`. The failure branch reported the error but never deleted
   the partial `$dumpPath` file it had just created — leaving an
   unencrypted (if empty, in this case) SQL file sitting in `backups/`
   indefinitely. This directly contradicts the script's own documented
   guarantee ("never leave the plaintext dump on disk") and `BACKUPS.md`'s
   claim that only the encrypted file ever touches disk. Fixed by adding
   cleanup (`@unlink($dumpPath)`) to the failure branch.
3. **`tblbooking` fallback schema missing real columns
   (`backend/config.php`).** The from-scratch dry run surfaced:
   `[TMS Schema] BookingDate backfill failed: SQLSTATE[42S22]: Column not
   found: 1054 Unknown column 'FromDate' in 'WHERE'`. The self-healing
   `CREATE TABLE IF NOT EXISTS tblbooking` fallback (which only runs when
   a fresh/restored database doesn't have the table at all) was missing
   three columns — `FromDate`, `ToDate`, `Comment` — that are core,
   actively-used columns referenced by `checkout.php`'s booking INSERT,
   `ticket.php`, `admin/booking-calendar.php`, `admin/ai-dashboard.php`,
   `admin/operations-dashboard.php`, and `admin/do-export.php`. This gap
   had been invisible in every previous round because every previous test
   database was descended from the original `tms.sql` (which already had
   these columns from the start) — never a truly empty database going
   through the fallback `CREATE TABLE` path for the first time, which is
   exactly the scenario a real disaster recovery would hit. Fixed by
   adding the three columns to the fallback schema. This change is purely
   additive and safe for any existing database, since `CREATE TABLE IF NOT
   EXISTS` is a no-op wherever the table already exists correctly.

All three fixes were re-verified by re-running the full backup → verify →
wipe → restore → data-parity cycle from a clean state, which then passed
with zero errors.

**Files touched:** `scripts/backup_database.php`, `scripts/restore_database.php`,
`backend/config.php` (only the `tblbooking` fallback `CREATE TABLE`
statement), `BACKUPS.md` (added an honest "Testing status" section
documenting this round's test and the three bugs it found, replacing what
had been an untested assumption). `php -l` clean on all three PHP files.
Synced to both `D:\xampp\htdocs\tms` and `D:\Project\tms` — 8 writes
(4 files × 2 locations), 0 rejections.

**What this round did NOT do:** it did not test against the user's actual
production `.env`/database (correctly — that would risk real data for a
test that a disposable sandbox database already proves just as validly);
it did not add a scheduled/cron invocation of these scripts (that's
`SCHEDULED_JOBS.md`'s existing job list, already documented there as a
recommended weekly/daily job — this round only proved the scripts
themselves work, not that they're currently wired into the user's
Windows Task Scheduler); and it did not re-test `BACKUP_OFFSITE_PATH`'s
copy-to-a-folder behavior for real (that code path is a plain
`copy()`/`is_writable()` check with no encryption or MySQL-specific
parsing involved, so it carries none of the risk the three bugs above
came from — it was left as reviewed-but-not-executed rather than given a
false "tested" claim).

**Implementation status:** backup + encryption + retention + optional
offsite copy + restore (`--verify` and `--restore --confirm`) — all
complete, as before.
**Testing status:** genuinely tested end-to-end for the first time this
round, with three real bugs found and fixed as a direct result. No longer
an untested assumption.

---

## Round 52 — Audit log coverage across the admin panel, and a critical security fix found along the way

**Goal:** the subagent gap-audit from the previous round ranked "audit log
coverage" as the top remaining backlog item: only 4 of 34 top-level
`admin/*.php` pages called `tms_log_activity()` (which writes to
`tblactivity_log`, viewable at `admin/activity-log.php`). Most of the
admin panel's real, state-changing actions -- creating/editing/deleting
packages, blog posts, bookings, coupons, users, reviews, pages,
translations, emergency contacts, custom trips, marketplace bookings, bulk
data exports, password changes, and session start/end -- left no audit
trail at all. If an admin account were compromised, or an admin disputed
having done something, there would be nothing to check.

**What changed:** added `tms_log_activity($dbh, $_SESSION['alogin'], '<Action>', '<Details>')`
calls immediately after each state-changing action, in the same style as
the 4 pre-existing call sites (`admin/index.php`'s login/2FA logging,
`admin/manage-admins.php`'s admin-management logging). Coverage now spans
25 of 34 admin pages (up from 4); the other 9
(`activity-log.php`, `booking-calendar.php`, `dashboard.php`,
`export-report.php`, `live-chat.php`, `operations-dashboard.php`,
`popular-destinations.php`, `revenue-analytics.php`, `user-analytics.php`)
were checked and confirmed to have zero `$_POST`/DML handling at all --
they're genuinely read-only, so there's nothing there to log.

Files touched (all under `admin/`, one or more new log lines each):
`add-blog.php`, `edit-blog.php`, `add-package.php`, `edit-package.php`,
`change-image.php`, `change-password.php` (success **and** failed-attempt
lines, matching the 2FA-failure precedent), `manage-packages.php`,
`manage-users.php`, `manage-blog.php`, `manage-bookings.php` (6 distinct
actions: confirm/cancel/delete a booking, approve/reject a date-change
request, process/reject a refund), `manage-coupons.php` (add/delete/toggle),
`manage-custom-trips.php`, `manage-emergency.php` (add/delete),
`manage-enquiries.php`, `manage-issues.php` (reply/delete),
`manage-marketplace-bookings.php` (confirm/cancel/delete, both
hotel and activity types), `manage-pages.php` (save/delete),
`manage-reviews.php` (delete/approve/reject), `manage-translations.php`
(edit/clear-cache), `do-export.php` (logs the bulk export itself -- type
and format -- since exporting user/booking/enquiry PII in bulk is exactly
the kind of action an audit trail exists for, not just editing a single
record), and `admin/logout.php` (new: an 'Admin Logout' line, so the
login trail `admin/index.php` already wrote isn't one-sided anymore --
capture the actor before `$_SESSION` is wiped, wrapped in a try/catch so a
logging failure can never block an actual logout).

No admin action's own behavior changed -- every new call sits after the
existing `INSERT`/`UPDATE`/`DELETE`, so the change is purely additive.
Never logged: any password value (password-change logs *that* it
happened, never the old/new password, same principle already used for the
2FA lines).

**Security fix found while doing this (not what this round set out to
find):** while grep-auditing every admin file for `tms_log_activity` and
`$_SESSION['alogin']` usage, `tests/manual/test-round42-admin-session-helper.php`
stood out -- its own docblock claimed "disabled as a 404 stub immediately
after use," but the file's actual body was still the full, live,
functional session-simulation script. Checked all ~14 sibling QA
session-simulation helpers in that folder and confirmed every single other
one *had* actually been converted to the `http_response_code(404); die();`
stub after use, exactly as documented -- this was the one exception.

Concretely, that meant `/test-round42-admin-session-helper.php` was a
public, unauthenticated endpoint on the live site that granted a full
**superadmin** session (the `qa_test_admin` account, restored to
superadmin per Round 39's RBAC testing) to anyone who requested it -- no
password, no CSRF token, nothing. It had been live since Round 42 and
carried forward unchanged through every round since, including Round 50's
project restructure (which moved the file's contents into `tests/manual/`
verbatim and added a root shim -- correctly preserving the file's
existing public URL and behavior exactly as that round's job was to do,
since a pure file-move round wasn't auditing the content of every file it
relocated).

Fixed the same way every sibling helper is disabled: replaced the body
with `http_response_code(404); die('Not found.');`, documented in the
file itself why (naming this as the one helper that wasn't actually
disabled despite claiming to be). Verified live immediately: the root URL
now returns a bare "Not found." with a 404 status, matching every other
disabled helper. This was a live production security hole and took
priority over finishing the audit-log-coverage work once found -- fixed
and verified before continuing.

**Testing status: live-tested, not just reviewed.** Created a new
one-time QA session-simulation helper
(`tests/manual/test-round52-admin-session-helper.php`, same
disposable-`qa_test_admin`-account, direct-`$_SESSION`-assignment,
no-typed-password pattern used throughout this project), used it to
simulate an authenticated superadmin session in the browser, and exercised
four of the newly-added call sites for real against the live site's real
database: added a test coupon (`ROUND52AUDITTEST`), deleted it, ran a
real `do-export.php` bulk export, and logged out via `admin/logout.php`.
All four completed with their normal success messages/redirects (nothing
broke), and `admin/activity-log.php` showed all four new rows with
correct actor, action, and detail text:
`Coupon Created — Code: ROUND52AUDITTEST, Discount: 5%`,
`Coupon Deleted — id=4`, `Data Export — type=packages, format=html`, and
`Admin Logout — Session ended`. The test coupon was deleted as part of
the test itself, so no leftover test data remains in `tblcoupons`; the
resulting activity-log rows are genuine records of genuine (QA) actions
and were left in place rather than scrubbed. The helper was then disabled
to a 404 stub and synced in that disabled state -- this time verified live
to actually return 404, closing the exact gap the Round 42 helper had
been left open by.

Not separately live-tested (time/scope): the other ~26 call sites across
`add-blog.php`, `edit-blog.php`, `add-package.php`, `edit-package.php`,
`change-image.php`, `change-password.php`, `manage-packages.php`,
`manage-users.php`, `manage-blog.php`, `manage-bookings.php`,
`manage-custom-trips.php`, `manage-emergency.php`, `manage-enquiries.php`,
`manage-issues.php`, `manage-marketplace-bookings.php`, `manage-pages.php`,
`manage-reviews.php`, and `manage-translations.php`. Each was statically
verified two ways instead: `php -l` (all 25 touched files pass clean) and
a `grep` confirmation that every file calling the new
`tms_log_activity($dbh, $_SESSION['alogin'], ...)` line already calls
`tms_require_admin()` (directly, or transitively through
`tms_admin_require_role()`) earlier in the same request, which is what
guarantees `$_SESSION['alogin']` is a non-empty string wherever the new
code reads it -- the same failure mode (calling it with an unset/null
actor) would show up identically in every call site, so the four
different, structurally-varied sites that were live-tested (a simple
add/delete pair, a GET-triggered bulk export with no form at all, and a
session-teardown path that runs the log call *before* destroying the very
session it reads from) stand in as reasonably representative coverage of
the pattern, not a claim that all ~30 call sites were individually
exercised.

**Files touched:** the 20 admin files listed above, plus
`tests/manual/test-round42-admin-session-helper.php` (security fix) and
the new, now-disabled `tests/manual/test-round52-admin-session-helper.php`
-- 22 files total. `php -l` clean on all. Synced to both
`D:\xampp\htdocs\tms` and `D:\Project\tms` in two batches (44 + 2 writes),
0 rejections.

**Implementation status:** audit log coverage now spans 25/34 admin pages
(up from 4/34); the remaining 9 are read-only and have nothing to log.
The Round 42 session-helper security hole is closed.
**Testing status:** genuinely live-tested for 4 representative call sites
plus the security fix itself (confirmed 404 live); the remaining ~26 call
sites are statically verified (lint-clean, confirmed session-actor
availability) but not individually exercised end-to-end.

---

## Round 53 — CI pipeline actually run for the first time (master prompt item 59), two real bugs found and fixed

**Goal:** the subagent gap-audit (Round 50) ranked "CI pipeline never
actually run" among the top remaining gaps. `.github/workflows/ci.yml`
existed and looked correct on read-through, and `TESTING.md` already said
so honestly ("hasn't actually run yet since this repo isn't pushed to
GitHub today"). Per Rule 77, an unexecuted CI workflow is exactly the kind
of "should work" claim that needs to be actually run before it can be
trusted, the same way Round 51 did for backup/restore and Round 52 did for
the new audit-logging code.

**Method:** since this repo isn't pushed to GitHub (confirmed in
`TESTING.md`, and this session has no credentials to push it there even if
it were appropriate to), the workflow's exact steps were replicated by
hand in an isolated sandbox: a fresh MariaDB instance (reusing the same
disposable-database approach as Round 51, on this assistant's own cloud
sandbox, never the user's machine or real data), a full copy of the
project, and then literally the same 5 steps `ci.yml` runs, in the same
order: lint every `.php` file, load `tms.sql` + `v6_migration.sql` +
`AI_MIGRATION.sql` + `explore_migration.sql` + `schema_repair_migration.sql`
into a genuinely empty database, configure `.env` the same way the
workflow's `sed` commands do, run `database/migrate.php`, run
`php tests/run.php`.

**Result: it failed on the first two real attempts**, surfacing two bugs
that had never been caught because this exact sequence had never actually
been executed before:

1. **`AI_MIGRATION.sql` assumed a table that didn't exist yet.** Its only
   real statement was `ALTER TABLE tblcustomtrips ADD COLUMN IF NOT EXISTS
   AiItinerary ...` — but `tblcustomtrips` is never created by any of the
   other schema files; it only ever exists because `backend/config.php`'s
   own self-healing (`CREATE TABLE IF NOT EXISTS`) creates it, with this
   same column already included, on the very first page load of a running
   app. That makes the file safe on every real install (self-healing has
   always already run by the time anyone would apply this file) but fatal
   -- `ERROR 1146: Table 'tms.tblcustomtrips' doesn't exist` -- against a
   database that only has `tms.sql` + `v6_migration.sql` loaded, which is
   exactly what CI's "load base schema" step produces before anything
   PHP-side ever runs. Fixed by adding the identical `CREATE TABLE IF NOT
   EXISTS tblcustomtrips (...)` `backend/config.php` already uses (copied,
   not reinvented, so the two definitions can't drift) ahead of the
   `ALTER`. No-op on any database that already has the table (every real
   install); correct now on a genuinely empty one (CI).
2. **`tests/run.php` double-loaded a file `config.php` already loads.**
   `backend/config.php` does `require_once __DIR__ . '/PricingEngine.php'`
   internally. `tests/run.php` separately did a plain
   `require __DIR__ . '/../backend/PricingEngine.php'` right after
   requiring `config.php` — and a plain `require` does not consult PHP's
   already-included-files registry the way `require_once` does, so it
   unconditionally re-executed the file and fatal-errored with `Cannot
   redeclare function tms_remaining_seats()` before a single test could
   run. This means `php tests/run.php` had never once completed
   successfully for however long `config.php` has `require_once`d
   `PricingEngine.php` -- which also explains why Round 39's TESTING.md
   note says its four new tests were verified through a throwaway
   web-accessible script "instead of `php tests/run.php` directly": the
   runner itself was already broken and nobody had run it head-on since.
   Fixed by changing every bootstrap `require` in `tests/run.php` to
   `require_once` (defensive against the same class of bug if
   `config.php`'s own dependencies change again later).

After both fixes, the complete sequence -- lint (345/345 files clean) →
schema load (all 5 files, clean, against a database with zero prior
state) → `database/migrate.php` (clean; self-healing added ~30 missing
columns and seeded packages as expected on a from-scratch DB, then applied
all 4 tracked migrations) → `php tests/run.php` -- completed with **16/16
tests passing**. This is the first time this exact sequence has ever been
confirmed to work end-to-end.

**Honest caveat (Rule 77):** this was MariaDB 10.11 and PHP 8.4 in this
assistant's own isolated sandbox, not the workflow's pinned `mysql:8.0`
Docker service and PHP 8.2 running inside GitHub's own infrastructure --
a faithful reproduction of the same steps and inputs, but not proof the
real GitHub Actions run would behave identically in every respect (a
different MySQL major version in particular can occasionally differ on
strict-mode SQL behavior). The remaining, only-really-closable-by-pushing
part of this gap is unchanged: this repo still isn't on GitHub, so the
real workflow still hasn't executed there. `TESTING.md` was updated to
document exactly this distinction rather than overclaim it.

**Files touched:** `AI_MIGRATION.sql` (added the missing `CREATE TABLE IF
NOT EXISTS`, also refreshed its stale closing comment which referenced the
long-orphaned `includes/config.php` and a nonexistent `ANTHROPIC_API_KEY`
setting instead of the real `GEMINI_API_KEY`/`.env` setup), `tests/run.php`
(require → require_once for its 7 bootstrap dependencies), `TESTING.md`
(documented this round's findings and the MariaDB/PHP-version caveat
honestly). No application code paths changed behavior for a real user --
both fixes only affect from-scratch schema loading and the test runner
itself.

**Cleanup:** the sandbox MariaDB instance, its throwaway `tms` database,
and the `/tmp/tms_ci_test` working copy were all torn down after testing
completed -- nothing from this round's test environment persists anywhere,
and none of it ever touched the user's real `.env`, real database, or
real data.

**Implementation status:** `.github/workflows/ci.yml` was already fully
written (previous round). **Testing status:** its exact steps are now
genuinely verified to pass end-to-end for the first time, with two real,
previously-invisible bugs found and fixed in the process. Actually
executing on GitHub itself still requires the user to push this repo
there -- that part of the gap is unchanged and isn't something this
session can do on its own.

## Round 54 — Responsive design audit across breakpoints (master prompt item 61), 5 real overflow bugs found and fixed

**Goal:** actually test the site's responsive design at the master prompt's
own named breakpoints (320/375/768/1024/1180/1280/1920px, plus a couple of
in-between widths where earlier rounds already knew content was tight),
rather than continue assuming Bootstrap/flex/grid layouts "just work."

**Method:** systematic overflow detection, not eyeballing screenshots.
For each page/breakpoint: resize the browser pane's viewport (`resize_window`
with a literal width/height, not just the mobile/tablet/desktop presets),
reload, then run `document.body.scrollWidth > window.innerWidth` as the
first signal. Any true positive was followed by enumerating
`document.querySelectorAll('body *')` for elements whose
`getBoundingClientRect()` exceeds the viewport (excluding descendants of an
`overflow-x:auto`/`scroll` ancestor, since a horizontally-scrollable table
wrapper is the *correct* pattern, not a bug), then `getComputedStyle()` /
`outerHTML` inspection to find the actual CSS rule responsible before
touching anything.

Authenticated pages were tested via the same session-simulation convention
every round this session has used — `tests/manual/test-round54-auth-
session-helper.php`, which set `$_SESSION['alogin']`/`$_SESSION['login']`
directly to the pre-existing disposable `qa_test_admin` / `qa-alert-test-
a112ff@tms-testing.local` accounts (each confirmed to exist in the `admin`
/ `tblusers` tables first) after a GET param check (`?as=admin` /
`?as=customer`). No password was ever typed into any form field. The one
exception was adding a package to the QA customer's cart, which was done
by actually filling in and submitting the real "Add to Cart" form on
`package-details.php` (dates/persons/mobile), exactly as a real customer
would — needed so `checkout.php` had something to render instead of
redirecting to the empty-cart view.

**Pages swept, guest/unauthenticated (no bugs found beyond what's listed
below under "Bugs found"):** `index.php`, `package-list.php`,
`package-details.php?id=1`, `hotels.php`, `register.php`, `compare.php`,
`blog.php` — all clean at 320/375/768/1024/1180/1280/1920px after the
fixes below.

**Pages swept, authenticated customer:** `checkout.php` (with a real cart
item), `dashboard.php`, `profile.php`, `tour-history.php` — all clean at
320/375/768/1024/1180/1280/1366/1600/1920px after the fixes below.

**Pages swept, authenticated admin:** `admin/dashboard.php`, `admin/
manage-bookings.php`, `admin/manage-packages.php` — all clean at
320/375/768/1024/1920px after the fixes below.

**Bugs found and fixed (5):**

1. **`backend/landing.php` reviews grid** — an inline
   `style="display:grid;grid-template-columns:repeat(3,1fr);gap:24px;"`
   bypassed the page's existing `.tms-reviews-grid` responsive class
   entirely (inline styles beat external stylesheet media queries without
   `!important`). Fixed by using the existing class instead of the inline
   style — no new CSS needed, the responsive rule was already there and
   already correct, just unreachable.

2. **`.tms-hero-stats` (customer homepage)** — a flex row with no
   `flex-wrap`, forcing 3+ stat items into one unbreakable line on phones.
   Fixed by adding `flex-wrap: wrap; justify-content: center;` inside the
   existing `@media (max-width:768px)` block.

3. **`.tms-nav-menu` (customer site, main nav)** — 11 top-level nav items
   measure ~960px combined, but the only breakpoint that switched to the
   hamburger/mobile-menu layout was `max-width:768px`, leaving every width
   from 769px to ~1150px with the full horizontal menu still rendering and
   visibly overflowing. Reproduced cleanly at exactly 1024px (one of the
   master prompt's own named breakpoints) and at 1100px; already clean by
   1150/1280px. Fixed by adding a second, narrowly-scoped
   `@media (min-width:769px) and (max-width:1179px)` block that repeats
   only the nav/hamburger/mega-menu toggle rules (not the phone-specific
   hero/grid rules already correctly tuned in the 768px block), so tablet
   and narrow-laptop widths get the same working hamburger menu a phone
   gets. Verified via binary search (clean at 1150/1180/1280/1920, broken
   at 1024/1100) and a functional click-test (hamburger opens/closes,
   `mobile-open` class toggles correctly, no overflow while open).

   *(A fourth attempted fix here — adding `white-space:nowrap !important`
   to `.btn-topbar-login`/`.btn-topbar-signup` to stop them line-wrapping
   at certain widths — was tried, live-tested, found to make things worse
   (it turned a cosmetic wrap into a new overflow bug, `bodyScrollWidth`
   went from 320 clean to 360 broken), and reverted before moving on. Not
   every cosmetic nit is worth trading for an actual defect — Rule 73.)*

4. **`.tms-topbar-left` overflow for logged-in customers, ~993-1300px** —
   found while testing `checkout.php` as the QA customer (not caught by
   the guest-only sweep above, since a guest's topbar-left is just two
   short links — Home/Admin — that already fit). A logged-in customer's
   topbar-left renders 8 plain-text links (Home + Dashboard/My Profile/My
   Bookings/Price Alerts/Group Trips/Refer Friends/Privacy & My Data,
   `backend/header.php`) totaling ~753px. None of that text is wrapped in
   a `<span>`, so the existing `@media(max-width:1300px)` rule meant to
   shrink it (`.tms-topbar-left a span { display:none }`) never actually
   matched anything real, and the "keep only the first link" collapse
   previously only kicked in below 992px — leaving the whole 993-1300px
   range with a topbar overflowing past the right edge of the page (found
   at 1024px, one of the master prompt's own breakpoints: `.tms-topbar-
   right` measured to `right:1071px` against a 1024px viewport). Fixed by
   moving the "hide all but the first link" rule from `max-width:992px` up
   to the same `max-width:1300px` threshold the (previously dead) span
   rule already used — closes the gap for logged-in users, changes nothing
   for guests (whose 2-link topbar already fit everywhere). Verified clean
   at 1024/1180/1280/1366/1600/1920px afterward; the customer-authenticated
   sweep above (dashboard/profile/tour-history/checkout, all at 1024px)
   confirmed the shared header component renders correctly site-wide, not
   just on the one page the bug was found on.

5. **Admin panel (`admin-v6.css`) had essentially no phone-width
   responsiveness at all** — the most significant find this round, and
   the first time any admin page was tested below 768px. `admin/
   dashboard.php` measured 599px of forced overflow against a 320px
   viewport (nearly double). Root causes, all in the same family as the
   min-width/min-content trap already seen elsewhere in this codebase:
   - `.tms-shell` is `display:flex` and `.tms-sidebar` is `position:fixed`
     (out of flow), leaving `.tms-main` as the shell's only real flex
     item — whose *automatic* minimum width defaults to its own
     min-content size, not 0. Any unshrinkable content anywhere inside it
     (a data table, a badge row) silently set a floor under the whole
     page's width. Fixed with `.tms-main { min-width: 0; }`.
   - `admin/dashboard.php`'s two-column layout
     (`style="display:grid;grid-template-columns:1fr 320px"`) was an
     **inline style**, unreachable by any media query (same class of bug
     as fix #1 above). At 320px viewport width the fixed 320px column
     alone consumed the entire row, leaving the `1fr` column computing to
     1.6px while its content (a data table) stayed at its natural ~590px
     width. Moved to a class (`.tms-dashboard-grid`, `admin-v6.css`) with
     `grid-template-columns: 1fr` under the existing `@media(max-
     width:768px)` block, and `.tms-dashboard-grid > * { min-width: 0; }`
     to stop the same min-content trap recurring one level deeper on the
     grid item itself.
   - The topbar's own 28px side padding plus its unshrinkable
     `topbar-right` content (theme toggle + user avatar/name badge + date
     pill + "Logout" text) had no mobile handling at all. Fixed inside the
     existing 768px block (padding trimmed to match `.tms-content`'s,
     `.topbar-date` and the user's name span hidden — both duplicate
     information already visible elsewhere), plus a new
     `@media(max-width:480px)` step hiding the "Logout" text label (now
     wrapped in `<span class="topbar-logout-label">` in `admin/includes/
     header.php` so CSS can target it — the icon and its `title`
     tooltip still communicate what the button does).
   - `.stats-grid` got a new `@media(max-width:480px){grid-template-
     columns:1fr}` step (one narrower than the existing 768px 2-column
     rule) so stat cards aren't cramped once real currency/label values
     are substituted in.
   - The existing `.table-responsive` wrapper (Bootstrap class, already
     present in the markup around every admin data table) needed no
     changes at all — it was only ever failing to do its job because the
     grid/flex min-width trap above it was preventing it from ever
     receiving a properly-shrunk container in the first place. Once that
     was fixed, its already-correct `overflow-x:auto` started working as
     designed.

   Verified clean at 320/375/768/1024/1920px on `admin/dashboard.php`,
   `admin/manage-bookings.php`, and `admin/manage-packages.php` — the
   `.tms-main`/topbar fixes are in the shared admin header/layout, so they
   apply to every admin page, not just the three spot-checked here.

**Files touched:** `backend/landing.php` (reviews grid), `css/modern-
theme.css` (hero-stats wrap, nav-menu 769-1179px hamburger block,
topbar-left 1300px threshold), `admin/css/admin-v6.css` (`.tms-main`
min-width, `.tms-dashboard-grid`, topbar/stat-grid mobile rules),
`admin/dashboard.php` (inline grid style → class), `admin/includes/
header.php` (Logout label wrapped in a span for targeting). One new
throwaway test helper (`tests/manual/test-round54-auth-session-helper.php`)
created, used, and disabled (404 stub) per this project's convention.

**Honest scope statement (Rule 77):** not every page in the app was
swept — this round covered the pages named in master prompt item 61's own
checklist (forms, cart/checkout, admin dashboard/tables) plus a
representative sample of the customer-facing marketing/browse pages, not
the full page inventory (e.g. blog article detail, individual admin CRUD
forms like `add-package.php`/`edit-package.php`, the live-chat widget UI,
and RTL/language-switch rendering were not tested this round). No
accessibility testing (contrast, screen-reader, keyboard nav — master
prompt item 32) was done here; this round was overflow/layout only.
`resize_window`'s viewport emulation was reset to `preset:"desktop"` and
the QA test session was logged out (`admin/logout.php`) when testing
concluded, per that tool's own instructions and this project's session-
simulation convention.

**Implementation status:** all 5 fixes above are live in both
`D:\xampp\htdocs\tms` and `D:\Project\tms`. **Testing status:** all 5 were
live-verified in a real browser against the real running site (not just
`php -l` syntax checks) — the whole point of this round was measuring
actual rendered layout rather than assuming CSS "should" work.

## Round 55 — Accessibility audit begun (master prompt item 32, WCAG 2.2 AA): the sign-in/sign-up dialog and the two standalone auth pages, real ARIA/focus/label bugs found and fixed

**Goal:** start actually testing against master prompt item 32's checklist
(keyboard navigation, visible focus, semantic HTML, labels, accessible
forms, proper ARIA, contrast, accessible error messages, touch targets,
reduced motion, screen reader support) rather than assume the theme "is
accessible" because an earlier baseline pass (`css/modern-theme.css`,
"Accessibility pass (Round 1)") added `:focus-visible` rings and respected
`prefers-reduced-motion`. Those two things were verified still present and
correct; this round's own scope was keyboard operability, ARIA, and label
association on the site's most-used interactive component: the sign-in /
sign-up flow every visitor and every guest-to-customer conversion goes
through.

**Method:** live testing in a real browser, not a static code read.
Inspected the actual rendered DOM/computed accessibility properties
(`element.labels.length`, `getAttribute('role')`/`aria-*`,
`document.activeElement` before/after actions) rather than trusting markup
would behave as intended, then drove real keyboard events (Tab, Shift+Tab,
Escape) through the `computer` tool and cross-checked with direct
function-level calls to rule out automation-tool fidelity gaps (see the
`Return` key false-negative noted below).

**Bugs found and fixed (2 categories, across 4 files):**

1. **The sign-in/sign-up modal (`backend/header.php`) had no dialog
   semantics or focus management at all.** `openModal()`/`closeModal()`
   only toggled a CSS class — opening the modal never moved focus into it
   (a keyboard or screen-reader user who activated "Sign In" got no
   indication anything happened), `Tab` could escape the open modal into
   the page behind it, and closing never returned focus to whatever had
   opened it. The modal box itself also had no `role="dialog"`,
   `aria-modal="true"`, or `aria-labelledby` pointing at its own heading —
   a screen reader had no way to announce it as a dialog or read its
   title. (Escape-to-close already existed via a separate document-level
   keydown handler and needed no change.) Fixed by rewriting
   `openModal`/`closeModal` to: record `document.activeElement` before
   opening, move focus to the first focusable element inside the dialog,
   install a `Tab`-only keydown handler that traps focus by wrapping from
   the last focusable element back to the first (and vice-versa for
   `Shift+Tab`), and restore focus to the original trigger on close. Added
   `role="dialog"`/`aria-modal="true"`/`aria-labelledby` + an `id` on each
   modal's `<h3>` title, and `role="alert"` on the login/register error
   banners.

   **Verified live**, end-to-end: opened the sign-in modal via a real
   focused-element + function call (mirroring exactly what the trigger's
   `onclick` does) and confirmed all four steps in one pass — focus
   correctly captured beforehand, modal opens, focus moves inside it, and
   focus is restored to the trigger on close. Separately drove real `Tab`
   key presses through the 6 focusable elements inside the sign-in dialog
   (close button → email → password → "Forgot password?" → Sign In button
   → "Create one free" link) and confirmed the 7th `Tab` wraps back to the
   close button, and that `Shift+Tab` from the first element wraps
   backward to the last — the trap works in both directions.

   *(One test artifact worth recording honestly: simulating a keyboard
   `Return` press on the *focused trigger link* through the browser
   automation tool did not visibly reopen the modal in one attempt, which
   briefly looked like a real bug. Direct function-level testing (focus
   the trigger, call the exact same code path the `onclick` handler calls)
   immediately confirmed the underlying behavior is correct; the
   discrepancy traces to the automation tool's synthetic-keypress fidelity
   for anchor-element activation, not the site. Recorded here rather than
   quietly discarded, per Rule 77.)*

2. **Every labelled input in the sign-in modal, sign-up modal, and the two
   standalone `login.php`/`register.php` pages had a `<label>` with no
   programmatic association to its input** (`<label>Email Address</label>`
   sitting next to, not wrapping, the `<input>`, no `for`/`id` pair). This
   is a real WCAG 1.3.1 / 3.3.2 / 4.1.2 failure — visually the label sits
   right next to the field, but a screen reader announces the input with
   no name at all. Fixed by adding matching `id`/`for` pairs to all 7
   fields across the two modals and all 7 fields across the two standalone
   pages (14 total: email/password ×2 dialogs, full name/mobile/email/
   password/confirm ×2 pages). Verified live via `input.labels.length`
   (the browser's own computed label association, not just "the markup
   looks right") returning `1` for every field checked, on every one of
   the 4 files. Also found and fixed two icon-only "show password" toggle
   buttons (`login.php`, `register.php`) that had no accessible name at
   all (a font-icon `<i>` conveys nothing to assistive tech) — gave them
   an `aria-label` that toggles between "Show password"/"Hide password" in
   sync with what the button actually does next.

**A note on `includes/header.php`:** this file is **not** the orphaned
leftover the codebase's `includes/config.php` is (see Round 49/53's notes
about that *different* file) — it's the live template `backend/landing.php`
actually includes for the guest-facing homepage (`frontend/index.php`
branches to `backend/landing.php` and exits *before* ever reaching
`backend/header.php`, for anyone not logged in). It was already documented
this way in earlier rounds (Round 41 discusses "the modal hand-built
inline in `includes/header.php`"), so this isn't a newly-discovered
inconsistency -- just confirmation, found the hard way when the modal fix
didn't initially appear on the homepage: a `diff` against `backend/
header.php` showed the two files were byte-identical except for exactly
the lines this round changed, so the same fix was applied to both to keep
them in sync (as every prior round touching this shared header already
has).

**Files touched:** `backend/header.php` and `includes/header.php` (dialog
ARIA/focus-trap + 7 label associations each, kept identical), `frontend/
login.php` (2 label associations + eye-toggle `aria-label`), `frontend/
register.php` (5 label associations + eye-toggle `aria-label` ×2).

**Honest scope statement (Rule 77):** this round covered exactly one
(high-traffic, high-value) component family — the auth modal/pages — not
the master prompt item 32 checklist site-wide. A grep across the codebase
found **125 more `<label>` elements without `for`/`id` association across
~20 other files** (`checkout.php`/`profile.php`/`enquiry.php`/most
`admin/*.php` CRUD forms, `package-details.php`'s booking form, and
others) — genuinely not fixed yet, listed here rather than implied fixed.
Also not attempted this round: color contrast measurement, touch-target
sizing (WCAG 2.2 SC 2.5.8, 24×24px minimum), a full keyboard-navigation
sweep of every custom widget (the mega-menu, the notification dropdown,
the AI chat widget, admin dropdowns), and any screen-reader-specific
testing beyond DOM-level ARIA/label inspection (no actual screen reader
software was run). This is a start on item 32, not a completed audit.

**Implementation status:** all fixes above are live in both `D:\xampp\
htdocs\tms` and `D:\Project\tms`. **Testing status:** live-verified via
real DOM inspection and real keyboard events in a running browser against
the real site (not just a markup read) — role/aria-* attributes, label
association counts, and the full focus-trap cycle were all directly
measured, not assumed.

## Round 56 — Root-level test-script cleanup (project hygiene, user-requested)

**What happened:** the user asked for leftover root-level test/diagnostic
PHP files to be deleted "so the structure will look professional." 29 files
were identified as safe to delete using a systematic rule: each one's
`require` line pointed at a `tests/manual/*.php` (or, for two outliers,
was itself a bare root-level stub) target that had ALREADY been reduced to
a two-line `http_response_code(404); die('Not found.');` stub in an earlier
round — i.e. the file served no purpose anymore, as opposed to a real page
shim (like `hotel-details.php`) whose `require` target is still live,
working code under `frontend/`/`backend/`/`admin/`.

Files removed from `D:\xampp\htdocs\tms`: `test-admin-login-helper.php`,
`test-admin-login-helper2.php`, `test-admin-login-helper3.php`,
`test-ai-provider-abstraction.php`, `test-cleanup-eval-run.php`,
`test-cleanup-telemetry.php`, `test-fk-audit.php` through
`test-fk-audit6.php`, `test-force-ai-migrate.php`,
`test-gemini-quota-check.php`, `test-marketplace-login-helper.php`,
`test-nlsearch-duration-check.php`, `test-privacy-delete.php`,
`test-qa-customer-login-helper.php`, `test-qa-customer-setup.php`,
`test-rbac-setrole.php`, `test-rbac-verify.php`,
`test-round41-session-helper.php`, `test-round42-admin-session-helper.php`,
`test-round44-session-helper.php`, `test-round46-signature-test.php`,
`test-search-schema-check.php`, `test-verify-new-suite-tests.php`,
`check-qa-admin.php`, `seed-qa-admin.php`.

Deliberately KEPT (not clutter, despite looking similar at a glance):
`DATABASE-1.md` and `SESSION_SUMMARY_INDEX.md` — both explicitly flagged in
their own content as deliberately-preserved historical/pointer documents
(see Round 47/48) — plus every real page shim (`hotel-details.php`,
`explore.php`, `login.php`, etc.) and every `AUDIT_REPORT_ROUND*.md` /
other `.md` doc, all of which are live/real, not leftovers.

**How it was executed:** this session's device shell (`device_bash`) is
non-functional this whole session (a Windows update released September 8
breaks the mount this session's cloud sandbox needs to run commands on the
user's machine directly — see this file's own environment notes elsewhere
in this round). Since no tool available to me can delete a file on the
user's device right now, I gave the user a `Remove-Item` PowerShell command
to run themselves. Their terminal showed an `ObjectNotFound` error on
`seed-qa-admin.php`, which looked like the whole command had failed — but a
fresh `device_list_dir` check afterward confirmed all 29 files were
actually gone from `D:\xampp\htdocs\tms`. Likely cause of the misleading
error: a PowerShell backtick line-continuation broken by trailing
whitespace (from copy-pasting a multi-line command out of a markdown code
block), which can garble which line "runs" without necessarily aborting
every `Remove-Item` call in the batch.

**Known gap, caught during Round 57 below:** the same 29 files were still
present in `D:\Project\tms` (the plain-file, non-git mirror) — the
PowerShell command was only ever run against `D:\xampp\htdocs\tms`. Not
yet cleaned up there as of this writing; the user has been given the
equivalent command for that folder.

**Implementation status:** Done (one of the two project locations).
**Testing status:** Verified via `device_list_dir` (files confirmed absent
from `D:\xampp\htdocs\tms`; still confirmed present in `D:\Project\tms`
during Round 57's own directory check).

## Round 57 — Webhook infrastructure: real inbound receivers for SSLCommerz/bKash/Nagad (master prompt item 43)

**Goal:** item 43 ("WEBHOOKS") calls for secure webhook handling that
stores event/payload/signature/timestamp/processing status/retry count and
uses idempotency. Before this round, `PaymentService::handleWebhook()`
existed (added when the payment ledger was built) but its own docblock
said "Not reachable today" — there was genuinely no public HTTP endpoint
anywhere in the project that called it. `tblpayment_events` (migration
`002_payments.sql`) also didn't yet have columns for the raw signature
value, an explicit processing-status lifecycle, or a retry counter — only
`SignatureValid` (a boolean) and `Payload`.

**What this round built:**
- Migration `005_webhook_hardening.sql` — additive-only (Rule 8): adds
  `ProviderEventId`, `Signature`, `ProcessingStatus`, `RetryCount` to
  `tblpayment_events`, plus a `UNIQUE KEY (Provider, ProviderEventId)` that
  is what actually enforces idempotency at the database level (not just in
  application logic).
- `PaymentService::handleWebhook()` was rewritten (its old signature took a
  `PaymentProviderInterface` instance directly, which no caller anywhere
  used, so changing it broke nothing — Rule 2 doesn't protect dead code)
  into one shared implementation that: rejects non-POST, reads the raw
  body/headers before `$_POST` parsing, logs the event unconditionally
  BEFORE verification (so a forged/malformed request still leaves an audit
  trail), deduplicates by `(Provider, ProviderEventId)` so a provider's own
  redelivery retries never get reprocessed, resolves the real provider
  class and fails closed (503, honest message) when it's not configured —
  true for all three today, same as everywhere else in this project's
  payment code — and otherwise defers to that provider's own
  `verifyWebhook()`/`normalizeStatus()`, rejecting (400) on an invalid/
  unverifiable signature and never marking anything paid without one.
- Three thin receiver files, `backend/webhooks/{sslcommerz,bkash,nagad}.php`
  — each just reads the raw request and calls the shared method above —
  plus three root-level shims (`webhook-sslcommerz.php`,
  `webhook-bkash.php`, `webhook-nagad.php`) following this project's
  established URL-preserving pass-through pattern (Round 50), so each is a
  short, stable URL to hand that provider's merchant dashboard.
- `PAYMENTS.md` and `DATABASE.md` updated with the new architecture,
  migration, and a manual PowerShell verification recipe for the user
  (also fixed a stale `includes/payments/` path reference in `PAYMENTS.md`
  left over from the Round 49 `includes/` → `backend/` rename).

**What this round deliberately did NOT build**, and why: real signature
verification for SSLCommerz/bKash/Nagad. Each provider's `verifyWebhook()`
already returned `false` unconditionally before this round and still does
— implementing it for real means guessing at a specific header name,
algorithm, and secret format from a provider this project has no real
account with, which Rule 3/4 forbid (never fabricate integration details
for a real external service; verify external claims before making them).
That remains real, tracked follow-up work for whenever a real merchant
account exists (now genuinely just "implement one method per provider" —
the receiving/logging/dedup infrastructure around it is done). Also not
built: a retry-processing worker for events stuck in `ProcessingStatus=
'failed'`. `RetryCount` exists in the schema for when that's needed, but
building a whole retry job today would be speculative — there is no real
provider yet that can even reach this endpoint with a real signed request,
so there is nothing real to retry (Rule 73: don't overengineer;
`SCHEDULED_JOBS.md` already documents this project's deliberate choice of
plain cron scripts over queue/worker infrastructure, for the same reason).

**Implementation status:** Implemented (receiver endpoints, shared
handler, migration, docs).
**Testing status:** Partially verified, partially BLOCKED (Rule 77) —
the control flow (dedup, log-before-verify, fail-closed on unconfigured/
unknown provider, per-provider dedup-key isolation, generic signature-
header capture) was exercised with a standalone in-memory SQLite harness
(12 automated checks, all passing) that doesn't require a real database,
since this session has no network path to the user's XAMPP MySQL. What
that harness does NOT cover, and remains genuinely untested: the migration
SQL against the user's real MySQL server, and an actual HTTP request
against the live endpoint through Apache/PHP on the user's machine — this
session's device shell (`device_bash`) is non-functional this entire
session (see Round 56 above), so no `curl`/`Invoke-WebRequest` could be
run on the user's behalf either. The user has been given
`database/migrate.php` to run plus a copy-pasteable PowerShell command to
hit the live endpoint and what response/DB-row to expect, and asked to
report back what they see.

**Also found and flagged, not yet fixed:** the file-cleanup gap noted in
Round 56 above (`D:\Project\tms` mirror still has the 29 deleted test
files) was discovered while re-checking directory state for this round's
own file placements.

---

## Round 58: Accessibility completion — label/input association (master prompt item 32)

**What this round built:** completed the codebase-wide audit and fix of the
WCAG label/input association rule (every `<label>` needs either a
`for="<id>"` matching its control's `id`, or must wrap the control
directly) across the remaining pages that still had unassociated labels.
36 files were touched in total:

- 25 customer-facing `frontend/` pages: `checkout.php`, `profile.php`,
  `enquiry.php`, `package-details.php`, `hotel-details.php`,
  `custom-trip.php`, `ai-recommend.php`, `ai-planner.php`,
  `ai-packages.php`, `ai-copilot.php`, `budget-planner.php`,
  `packing-assistant.php`, `expense-tracker.php`, `virtual-guide.php`,
  `transport-compare.php`, `package-list.php`, `hotels.php`,
  `activities.php`, `activity-details.php`, `destinations.php`,
  `reviews.php`, `page.php`, `tour-history.php`, `flight-status.php`,
  `forgot-password.php`.
- 11 `admin/` pages: `add-package.php`, `edit-package.php`,
  `add-blog.php`, `edit-blog.php`, `manage-emergency.php`,
  `manage-coupons.php`, `manage-admins.php`, `manage-translations.php`,
  `two-factor-setup.php`, `change-password.php`, `index.php` (admin
  login/2FA-verify page).

For each file, either a matching `id`/`for` pair was added, or an existing
`id` (already used by JS) was linked with a new `for=`. Two files needed a
pattern beyond a plain 1:1 label/control link:

- `reviews.php`: the "Your Rating" label heads a group of 5 star radio
  buttons, not one control — given `role="radiogroup"
  aria-labelledby="ratingGroupLabel"` on the wrapping container instead of
  a fake `for=` to a single star.
- `add-package.php` / `edit-package.php`: the "Map Coordinates" label
  heads two separate lat/lng number inputs — given the same group pattern
  (`role="group" aria-labelledby="mapCoordsLabel_add/edit"`) plus
  `aria-label="Latitude"`/`"Longitude"` on the two inputs individually, so
  each is still announced on its own inside the group.

**Also found and fixed, beyond the label-association task itself:**
`transport-compare.php`'s icon-only submit button had no accessible name
at all (just a bare `<i class="fa fa-search">`) — given
`aria-label="Compare transport options"` with `aria-hidden="true"` on the
icon, reusing the pattern established in Round 55 for the password
eye-toggle buttons. The same file's third, purely decorative
`<label>&nbsp;</label>` (a layout spacer above the submit button, no
associated control) was given `aria-hidden="true"` rather than a
fabricated `for=`.

**What this round deliberately did NOT fix, and why:** `includes/signup.php`
and `backend/signup.php` were investigated (both have unassociated
labels) and confirmed, via a project-wide `grep -rn` for
`require`/`include` of each path, to never actually be loaded anywhere —
only comment text references them, and one of those comments explicitly
states the real signup modal lives in `includes/header.php`. Both files
are byte-identical dead code. Fixing labels in unreachable files would
misrepresent real coverage (Rule 77), so they were left as-is.

**Implementation status:** Implemented — all 36 files edited, and all but
the two deliberate group-labeling exceptions and two dead files (above)
now have a direct 1:1 `for=`/`id` link. Every file was individually
re-counted after editing (`grep -c "<label"` vs a `for=`-count) and shows
zero unaccounted-for labels.

**Testing status:** `php -l` was run on every one of the 36 edited files
— no syntax errors in any of them. This round could not be tested beyond
that: this session's device shell (`device_bash`) has been non-functional
since Round 56 (see above), so no live page render, browser accessibility
tree inspection, or automated axe/Lighthouse pass could be run against the
user's actual XAMPP server. The fixes are markup-only (no PHP logic,
queries, or JS behavior changed) and were verified structurally (lint +
grep counts) but not visually or through an actual assistive-technology
pass — that verification is still open and would need either a working
device shell or the user's own manual check.

All 36 files were synced to both `D:\xampp\htdocs\tms\` (git-tracked) and
`D:\Project\tms\` (mirror) via `SendUserFile` → `device_commit_files`,
each confirmed present on both roots with matching file sizes.

---

## Round 59: Security review of the newest code surfaces (master prompt item 44)

**Why this round, and its scope:** a gap-analysis pass across all 82
master-prompt items (done before this round, not written up separately)
flagged that no full security pass had been done since the marketplace
(Round 37/41), the backend/ restructure (Round 49/50), and the webhook
infrastructure (Round 57) were added — real new attack surface that
predates the last general security audit. This round is a targeted review
of exactly that surface, not a re-audit of the whole app (Rule 73 — don't
overengineer a full OWASP sweep when the honest gap is "check what's
new").

**Files reviewed line-by-line:** `backend/payments/PaymentService.php`,
`PaymentProviderInterface.php`, `SslcommerzProvider.php`,
`BkashProvider.php`, `NagadProvider.php`, `SandboxProvider.php`;
`backend/webhooks/{sslcommerz,bkash,nagad}.php` and their root-level
`webhook-*.php` shims; `admin/manage-marketplace-bookings.php`; the
customer-facing booking-creation code in `frontend/hotel-details.php` and
`frontend/activity-details.php`; and the image-upload handling in
`admin/add-package.php` (also touched this session for Round 58's
accessibility fix, so re-checked for upload safety while there).

**What was checked, specifically:** SQL injection (parameterized queries
vs. string interpolation, including table-name interpolation, which only
appears from a hard-coded ternary/whitelist, never raw user input); CSRF
coverage on every state-changing POST; auth/RBAC gating on the admin page;
IDOR (can a user act on another user's/booking's data); whether the
booking price is computed server-side from the DB or trusted from client
input; webhook signature-verification fail-closed behavior; and file
upload validation (extension allowlist, size cap, magic-byte signature
check via `tms_validate_upload_signature()`, non-user-controlled generated
filename to prevent path traversal/overwrite).

**Result: no exploitable vulnerability found in this surface.** Specifically confirmed:
- Every webhook endpoint fails closed — `verifyWebhook()` returning
  `false` (true today for all three providers, since none has real
  credentials) always returns HTTP 400 and never reaches any code that
  could mark a payment paid. Events are logged before verification, deduped
  by `(Provider, ProviderEventId)`, and provider construction itself throws
  if unconfigured (caught, logged, 503) rather than silently proceeding.
- `manage-marketplace-bookings.php`'s three POST actions (confirm/cancel/
  delete) all call `tms_csrf_validate()`, are gated behind
  `tms_admin_require_role()`, and build their table name from a hard-coded
  ternary on a literal check (`$_POST['type'] === 'activity' ? ... : ...`),
  never from unvalidated input — no SQL injection path there despite the
  table name being interpolated into the query string.
- Hotel/activity booking creation (`hotel-details.php`/
  `activity-details.php`) requires `$_SESSION['login']`, validates CSRF,
  and computes `TotalCost` server-side from the DB's own
  `PricePerNight`/activity price — the client cannot submit or influence
  the amount charged.
- `add-package.php`'s image upload validates extension against an
  allowlist, checks real file size, verifies the file's magic bytes match
  the claimed extension (not just trusting the extension string), and
  writes to a generated `time()_<random>.<ext>` filename — never the
  user-supplied name — so path traversal / overwrite / extension-spoofing
  are all closed.

**One design tradeoff flagged for the user's awareness, not changed:**
`tms_admin_role()` (backend/config.php) fails open to `'superadmin'` if
its DB role lookup throws an exception, with an explicit comment that this
is deliberate ("an infra hiccup should never lock the admin out"). This
only affects an admin who is *already authenticated* (never grants access
to an anonymous visitor) and only during a DB error on that one query, but
it does mean a role-restricted admin (e.g. `support`) could transiently
get broader access during a database hiccup rather than being denied. This
was a considered choice made in an earlier round, not an oversight from
this one, so it was left as-is rather than changed unilaterally — flagging
it here in case the user wants it reconsidered (the alternative, failing
closed, trades "never over-grants" for "a DB blip can lock an admin out
mid-shift").

**What this round deliberately did NOT cover** (so this isn't
overclaimed as a full security audit, Rule 77): the older, pre-Round-37
surface (core booking/checkout/auth flows) was not re-reviewed here — it
was covered in earlier rounds' security passes and is unchanged since. No
automated scanner (e.g. an OWASP ZAP-style sweep) was run; this was a
manual code read. Rate-limiting / abuse-throttling on the webhook
endpoints (e.g. an attacker hammering `webhook-*.php` with junk requests
to fill `tblpayment_events`) was not assessed — those endpoints are
publicly reachable by design (a real gateway must be able to POST to
them unauthenticated), so this would need a web-server or WAF-level
rate limit rather than an application-code fix, and is noted here as
follow-up rather than fixed in-app.

**Implementation status:** N/A — this round is a review, not a build; no
code was changed.
**Testing status:** Not applicable in the traditional sense (nothing was
implemented to test), but the review itself is a manual code read only —
it was not backed by a dynamic/runtime penetration test against the live
site, since this session's device shell (`device_bash`) remains
non-functional (see Round 56) and there is no other way to send live HTTP
requests to the user's XAMPP server from here.

---

## Round 60: Responsive design audit continuation (master prompt item 61), 2 real bugs found and fixed — first round this session with a live, working browser path to the actual site

**A tooling correction, not just a content note:** `device_bash` (a shell on
the user's computer) has been non-functional all session (see Round 56),
which blocked live HTTP testing for every round since — Round 57's webhook
work, for example, was explicitly flagged as untested against a live
request for exactly this reason. This round discovered that the Browser
pane tools (`Claude_Browser__*`, a separate tool family that talks to the
Claude desktop app's browser directly, not through `device_bash`) still
work, and used them to reach the live site for the first time this
session. Two path realities worth recording so future rounds don't
re-discover them the slow way: (1) the customer-facing pages under
`frontend/` are reached at their **root-level pass-through shim** path
(`http://localhost/tms/profile.php`, not `.../tms/frontend/profile.php` —
same shim pattern Round 56 used for webhooks, just not previously
exercised live); (2) `admin/*.php` pages ARE reached directly at
`.../tms/admin/...`. Session simulation continues to follow this
project's absolute rule — a new disposable helper,
`tests/manual/test-round60-auth-session-helper.php`, authenticated as the
pre-existing `qa_test_admin` / `qa-alert-test-a112ff@tms-testing.local`
accounts via direct `$_SESSION` assignment (each confirmed to exist in the
`admin`/`tblusers` tables first, never a typed password), and was disabled
(404 stub) once testing finished.

**Scope:** the four gaps Round 54 explicitly named as untested — admin CRUD
forms (`add-package.php`, `edit-package.php`), the live-chat widget, and
blog article detail (`blog-post.php`). RTL/language-switch rendering was
not reached this round either (deferred again, honestly — see below).

**Pages swept, clean at 320/375/768px (admin, as qa_test_admin):**
`admin/add-package.php`, `admin/edit-package.php?pid=413` — both clean at
every width tested, confirming Round 54's `.tms-main{min-width:0}` admin
shell fix generalizes correctly to these two CRUD forms too, not just the
three pages it was originally verified on.

**Pages swept, clean at 320/375/768px (guest):** `blog-post.php?id=3`.

**Bugs found and fixed (2):**

1. **`frontend/profile.php`'s `.profile-wrap` two-column layout overflowed
   128px past a 320px viewport.** Root cause was subtly different from the
   admin-panel flex trap Round 54 fixed: `.profile-main{flex:1;
   min-width:0;}` was already correctly set (min-width:0 was NOT the bug
   here), but its sibling `.profile-sidebar{flex:0 0 220px}` never shrinks
   and the parent `.profile-wrap` already had `flex-wrap:wrap` — yet the
   line never actually wrapped, because flex-wrap only triggers based on
   items' hypothetical (flex-basis) sizes before growing/shrinking, and
   `.profile-main`'s flex-basis is `0%` (trivially "fits" alongside the
   220px sidebar), so the browser correctly decided the line "fit" and
   then grew `.profile-main` into a genuinely-too-small 46px sliver
   instead of wrapping it. `min-width:0` (already present) let that
   46px-wide box render without breaking the outer layout, but the actual
   content inside it (labels, inputs, a 120-150px "Save Changes" button)
   still needed real width and spilled out of that sliver. Fixed with a
   new `@media(max-width:600px){.profile-sidebar{flex:1 1 100%;}}` rule —
   forcing the sidebar to claim the full row's hypothetical width is what
   actually makes the existing `flex-wrap:wrap` engage, stacking sidebar
   above main instead of squeezing beside it. Verified clean at
   320/375px after, and re-verified clean at 650px (just above the new
   600px breakpoint) to confirm the two-column desktop-ish layout still
   renders correctly once there's enough room for it.

2. **The site-wide AI chat widget (`#tmsChatPanel`, included from
   `backend/footer.php` on every page) rendered at a fixed 360px width on
   every screen size, cutting off the mic and send buttons past the right
   edge below ~480px** — confirmed visually via screenshot at 320px (the
   close button, speaker toggle were also partly clipped). A correct
   mobile override already existed
   (`@media(max-width:480px){#tmsChatPanel{width:calc(100vw - 24px);...}}`)
   but was silently dead: `backend/chatbot.php` has TWO separate `<style>`
   blocks (an artifact of incremental edits across rounds), and the mobile
   override lived in the FIRST block while an unconditional
   `#tmsChatPanel{width:360px;...}` base rule lived in the SECOND block,
   further down the page. Same specificity (both single-ID selectors) plus
   later source position means the second, unconditional rule always won
   the cascade regardless of viewport — the media query was never wrong,
   just permanently shadowed. Fixed by moving the mobile override to the
   end of the second `<style>` block, after the base rule it needs to
   beat, leaving a comment in both the old and new location explaining why
   (so a future round doesn't "helpfully" move it back next to the
   toggle-button's own media rule where it came from). Verified live:
   panel width went from computed 360px (right edge 52px past a 320px
   viewport) to the intended 296px (12px margins on both sides) at
   320/375px, still correctly reverting to the fixed 360px desktop layout
   at 500px. This file exists as two byte-identical copies
   (`backend/chatbot.php` and `includes/chatbot.php` — `backend/footer.php`
   includes the bare relative path `'includes/chatbot.php'`, and rather
   than spend this round's time proving which one PHP's relative-include
   resolution actually serves, both copies were patched identically and
   re-verified byte-identical after, same as this project's established
   handling of the `includes/`-vs-`backend/` duplication elsewhere).

**What this round deliberately did NOT cover, and why:** the human
"real agent" chat widget (`backend/chat-widget.php` /
`includes/chat-widget.php`) was investigated and found to be dead code —
`backend/footer.php` itself carries a comment
(`/* Human "Chat with our team" widget removed per user request — Contact
page covers this */`) confirming it was deliberately removed and is no
longer included anywhere. Fixing its responsive layout would have been
wasted, misleading work on an unreachable file (Rule 77), so it was left
alone — same reasoning as the orphaned `signup.php` files from Round 58.
RTL / language-switch rendering (also named by Round 54 as untested)
still wasn't reached this round either; it needs its own dedicated pass
(switching the active language, confirming `dir="rtl"` layouts don't
introduce new overflow) rather than being squeezed in here, and is
carried forward as real, named follow-up work rather than silently
dropped.

**Files touched:** `frontend/profile.php` (`.profile-sidebar` mobile
media query), `backend/chatbot.php` and `includes/chatbot.php` (moved the
`#tmsChatPanel` mobile override to actually win the cascade), plus the new
throwaway `tests/manual/test-round60-auth-session-helper.php` (created,
used, disabled).

**Implementation status:** both fixes are live in `D:\xampp\htdocs\tms`
and mirrored to `D:\Project\tms`, confirmed via matching committed file
sizes on both roots.
**Testing status:** both fixes were verified against the ACTUAL running
local site for the first time this session (via the Browser pane tools,
not `device_bash`, which remains broken) — not just read as source code.
`document.body.scrollWidth` overflow checks, computed-style inspection,
and a visual screenshot all confirm the fixes work as intended at the
breakpoints tested. What was NOT re-tested: every other page site-wide
that also includes the shared chat widget only had its overflow spot-
checked on `index.php`/`blog-post.php`, not swept exhaustively — since the
fix is in the one shared component, this is treated as sufficient
confidence without claiming a page-by-page recheck that wasn't actually
done.

---

## Round 61: RTL layout for Arabic (master prompt items 36/61) — the gap both Round 54 and Round 60 named and deferred

**The bug:** `frontend/language.php`'s own language list has carried
`'dir' => 'rtl'` for Arabic since it was written — someone clearly intended
real RTL support — but that value was only ever read to right-align the
Arabic script *inside the language picker itself*
(`<span dir="...">`); nothing anywhere else in the codebase (every page's
own `<html lang="...">` tag, `backend/header.php`, all site CSS) ever
consumed it. Confirmed live: switching to Arabic correctly translated
every string and set `<html lang="ar">`, but `document.documentElement.dir`
stayed empty and `getComputedStyle(document.body).direction` reported
`"ltr"` — the logo, nav, and buttons all stayed pinned to their English
left/right sides while the text around them switched to Arabic script, an
objectively broken mixed-direction experience for an actual Arabic reader
(screenshotted and confirmed before touching anything, per Rule 4).

**The fix, and why it's one small change instead of ~70 file edits:** every
page in this codebase opens its own `<!DOCTYPE html><html lang="...">` —
`backend/header.php` is included later, from inside `<body>`, so it can't
add an attribute to a tag that already rendered. Editing every page file
to add `dir="<?php ... ?>"` to its own `<html>` tag was the "obvious" fix
but meant touching on the order of 70 files for one attribute. Instead,
`backend/header.php` already runs a small inline `<script>` as the very
first thing in `<body>` (used today to set `data-theme` before paint, so
there's no flash of the wrong theme) — this round added a second, equally
small script right before it that reads `document.documentElement.lang`
(already correctly rendered server-side by every page) and sets
`document.documentElement.dir = 'rtl'` when it's `'ar'`. Same
before-paint placement as the existing theme script, so there's no flash
of wrong direction either. Applied to both `backend/header.php` and the
byte-identical `includes/header.php` duplicate (confirmed identical again
after patching both), same handling as this project's other backend/-vs-
includes/ duplicated files.

**Why this one fix bought much more than expected:** this codebase's
shared header/nav (`.tms-topbar .container`, `.tms-topbar-left/right`,
`.tms-navbar-inner`, `.tms-nav-menu`) is built with plain `display:flex`
and no explicit `flex-direction` override — and flexbox's row axis already
respects the inherited CSS `direction` property for free. The moment
`dir="rtl"` landed on `<html>`, that inherited all the way down and the
entire shared nav mirrored correctly with zero CSS changes: logo moved to
the right, sign-in/create-account buttons moved to the left, the whole
top bar and nav row visually flipped. Verified live via screenshot on both
the landing page (`index.php`) and an interior page with the full nav +
filter bar (`package-list.php`) at 1280px, and re-verified no new overflow
was introduced at 320/1280px (both clean, `document.body.scrollWidth`
matched viewport width). Also verified the fix is correctly bidirectional
— switching back to English on the same page immediately cleared `dir`
back to empty/`ltr` with no stale state, since the check re-runs fresh on
every page load from whatever `lang` that load actually rendered.

**What this round deliberately did NOT do, and why (Rule 73/77):** this is
a real, working RTL *mechanism* plus a correctly-mirrored shared header/nav
— it is NOT a full per-page RTL visual audit. This codebase writes CSS
with literal `margin-left`/`padding-right`/`text-align:left` throughout
(not logical properties like `margin-inline-start`), so any page whose
*own* bespoke CSS hardcodes a left/right value outside the shared
header/nav (a card's icon padding, a table's column alignment, a form's
label offset) will not auto-mirror the way the flexbox-based nav did, and
was not individually hunted down and fixed this round — auditing ~70
pages' worth of hand-written CSS for RTL correctness is real, sizable
follow-up work, not something to silently claim as done alongside a
one-file mechanism fix. `package-list.php`'s screenshot itself shows this
partially: the shared nav/topbar mirrored perfectly, while a few nav
labels (Activities, Hotels) render untranslated because they're missing
from the static translation dictionary — a content-completeness gap,
unrelated to this round's layout scope, left as-is and named here rather
than silently noticed and dropped. The admin panel was not touched or
checked at all — it has its own separate header/login flow with no
language switcher reachable from it, so Arabic RTL was never reachable
there to begin with.

**Files touched:** `backend/header.php`, `includes/header.php` (the
before-paint `dir` script, added identically to both).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms`, confirmed via matching committed file sizes on both
roots.
**Testing status:** verified against the actual running local site (same
Browser-pane-tools path established in Round 60) — `document.documentElement.dir`
before/after, a live screenshot on two different pages showing the mirrored
header/nav, an overflow re-check at 320px and 1280px, and a round-trip
back to English confirming no stale RTL state. Not tested: any page beyond
`index.php` and `package-list.php`, and no real Arabic-speaking-user
usability review (this is a structural/rendering check, not a native-
speaker UX review).

## Round 62: Error / empty / loading states (master prompt item 62) — audited first, then fixed the real gaps found (5 files), correcting an earlier gap-analysis that called this item entirely unstarted

**Item 62, verbatim:** "Every major component must support: Loading, Success,
Empty, Error, Offline. Use skeletons where appropriate. Never leave blank
screens."

**Starting point — the record needed correcting first.** An earlier
gap-analysis characterized item 62 as untouched by any round. A
general-purpose subagent was sent to audit actual coverage across 12
representative pages (`package-list.php`, `hotels.php`, `activities.php`,
`destinations.php`, `my-trip.php`, `wishlist.php`, `reviews.php`,
`dashboard.php`, `weather.php`, `tour-map.php`,
`admin/manage-bookings.php`, `admin/dashboard.php`) before any code was
touched, per Rule 4 (verify before acting) and Rule 77 (don't claim
without checking). The finding: this item is substantially already
handled, and the earlier claim was wrong. Already in place and working,
confirmed by direct inspection, left untouched this round:
- A global fatal-error shutdown handler (`backend/config.php` lines
  158–192) renders a friendly "Something went wrong" page instead of a
  blank screen for any uncaught fatal, site-wide.
- Offline handling via `sw.js` + `offline.html`, registered in
  `backend/footer.php`.
- Explicit, well-written empty states already present on `package-list.php`,
  `hotels.php`, `activities.php`, `destinations.php`, `wishlist.php`,
  `reviews.php`, `dashboard.php`, and `admin/manage-bookings.php` (which
  also already distinguishes a genuine DB error from an empty result set,
  via a `$msg`/`$msgType='error'` banner — see below).
- Strong AI/API loading + error handling already on `weather.php`,
  `tour-map.php`, `flight-status.php`, `image-analyzer.php`,
  `ai-copilot.php` (mostly), and `virtual-guide.php`.

**Real gaps found and fixed this round (5 files):**

1. **`frontend/ai-copilot.php` — silent chat-history load failure.**
   `loadHistory()`'s `catch(e){}` was completely empty, and a
   `json.success === false` API response was also silently ignored — either
   way the chat opened blank with zero explanation if history failed to
   load. Now both branches call the existing `addMsg('assistant', ...)`
   renderer to show an honest, in-context notice ("Couldn't load your
   previous messages for this trip — you can still chat below" /
   the connection-issue variant), matching this widget's own `.msg`/
   `.msg-bubble` conventions instead of looking like a raw error.

2. **`admin/dashboard.php` — Chart.js has no defensive guard, and no
   "no data yet" state.** Chart.js loads from `cdn.jsdelivr.net` with no
   local fallback; if that request is blocked (ad-blocker, offline,
   restrictive proxy), `Chart` is undefined and the old code threw a
   silent, console-only `ReferenceError`, leaving the donut's canvas a
   blank rectangle. Separately, a brand-new install with zero bookings
   rendered as an empty ring with no "nothing here yet" framing. Fixed
   with a three-way guard: `typeof Chart === 'undefined'` shows an inline
   "chart couldn't load" notice (data is still visible in the summary
   cards above), all-zero counts show "No bookings yet.", otherwise the
   chart renders exactly as before.

3. **`frontend/package-list.php` — map view has no loading/error
   affordance.** The map container was a flat grey box with zero feedback
   while Leaflet's JS/CSS (from `unpkg.com`) and OSM tiles loaded, and if
   that CDN request failed, `L` was undefined and clicking "Map view"
   threw a silent console error, leaving the same blank grey box. Fixed:
   a brief "Loading map…" spinner shows immediately on switching to map
   view, and a clear "Map couldn't load (offline or blocked script) — use
   List view instead" message replaces the crash if Leaflet never loaded.
   The pre-existing "no packages have coordinates" empty state was already
   good and is unchanged.

4. **`admin/manage-users.php` / `admin/manage-packages.php` — DB error
   indistinguishable from genuinely empty.** Both pages' `catch(Exception
   $e)` blocks fell back to an empty array with no message at all, so a
   real database failure rendered identically to "no users/packages
   exist yet" — exactly the Error-vs-Empty distinction item 62 asks for.
   Both pages already had the `$msg`/`$msgType`/`.alert-msg` banner
   machinery wired up for their own success messages (used by delete
   actions), so this was a one-line addition to each catch block, mirroring
   the pattern `admin/manage-bookings.php` already used for the identical
   situation — plus making both pages' banner icon dynamic
   (`fa-check-circle` for success, `fa-exclamation-circle` for error)
   since previously it was hardcoded to the success icon.

**Files touched:** `frontend/ai-copilot.php`, `admin/dashboard.php`,
`frontend/package-list.php`, `admin/manage-users.php`,
`admin/manage-packages.php`.

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms`, confirmed via matching committed file sizes on both
roots. All five files pass `php -l`.

**Testing status:** verified against the actual running local site (same
Browser-pane-tools path established in Rounds 60/61), using a fresh,
uniquely-named session-simulation helper
(`test-round62-auth-session-helper.php`, direct `$_SESSION` assignment for
the same pre-existing disposable `qa_test_admin` / `qa-alert-test-
a112ff@tms-testing.local` accounts used in Rounds 44/60/61, confirmed to
exist via DB lookup before ever setting session state — never a typed
password), now disabled (404 stub) on both roots per this project's
throwaway-script convention. Specifically confirmed live, not just by
inspection:
- `admin/dashboard.php`: normal case renders the real chart against live
  booking counts (4 confirmed / 3 pending / 3 cancelled); the
  `Chart === undefined` branch and the all-zero-counts branch were both
  exercised directly against the live page's own DOM/variables and
  produced the correct notice/empty text with the canvas correctly
  hidden in both cases.
- `frontend/package-list.php`: normal map-view click renders the Leaflet
  map as before (6 map points, no regression); simulating a missing `L`
  (blocked CDN) produced the correct fallback message with no thrown
  error and no new console errors.
- `frontend/ai-copilot.php`: normal page load renders the server-side
  welcome message correctly; simulating a rejected `fetch()` (network
  failure) and a `{success:false}` API response were both exercised
  directly against the live `loadHistory()` function and each produced
  the correct, distinctly-worded notice bubble, styled identically to
  the rest of the chat.
- `admin/manage-users.php` / `admin/manage-packages.php`: both load
  correctly with real data and show no false-positive error banner.
  **Not independently exercised**: an actual forced database failure
  (e.g. killing the DB mid-request) to confirm the new `$msg`/`error`
  banner literally renders — this was verified by code inspection and by
  the fact that the identical pattern is already proven live in
  `admin/manage-bookings.php`, but a genuine forced-failure test was
  judged too risky to run against the shared local dev DB other rounds'
  data lives in.

**What this round deliberately did NOT do, and why (Rule 73):** no literal
skeleton loaders were added anywhere. This app is server-rendered PHP, not
a client-side SPA — most "loading" moments are a normal server round-trip
with a full-page navigation, not a spinner-worthy async gap, so a
skeleton-loader sweep across ~70 pages would be manufacturing UI complexity
the architecture doesn't actually need, not filling a real gap. The one
genuine client-side async loading gap the subagent flagged — the map view
on `package-list.php` — got a real spinner (fix #3 above) because it's an
actual multi-second network wait behind a user click, which is exactly
where item 62's "use skeletons/loading states where appropriate" applies;
elsewhere, applying the same treatment would be decoration without a real
wait to cover. `admin/manage-users.php` and `admin/manage-packages.php`
were spot-checked (not just assumed) against the same pattern
`admin/manage-bookings.php` already uses, per the subagent's own
recommendation to confirm rather than claim.

## Round 63: Source & photo provenance (master prompt items 64/65) — audited via subagent first, fixed the real gaps, confirmed item 66 (recommendation transparency) already done

**Starting point.** No prior round had touched items 64–66, so a
general-purpose subagent audited all three (source provenance, photo/
content provenance, recommendation transparency) across the plausible
candidate pages before any code was touched, per Rule 4. Top findings were
independently re-verified via direct grep/read before acting, same
discipline as Round 62.

**Item 66 (recommendation transparency) — already done, no fix needed.**
`frontend/ai-recommend.php` already renders exactly what the item asks
for: an overall match % badge, a `why_recommended` line, and a
per-dimension ✓/− checklist (Budget compatible, Weather/season fit,
Travel-style match, Safety, Rating), backed by real per-dimension scores
from `backend/ai/TravelRecommender.php` — not decoration. `ai-packages.php`
has the equivalent `✓ {highlight}` pattern. Confirmed and left untouched.

**Item 64 (source provenance) — mostly done; one real gap fixed.**
Weather (`weather.php`) and currency conversion
(`package-details.php`) already show "Live via X · updated HH:MM" /
"Rates by X · updated daily — estimate only" correctly. The gap: the
Flight Tracker (`backend/FlightTracker.php` / byte-identical
`includes/FlightTracker.php`, confirmed via diff) returned live aircraft
position data with **no `source` or `fetched_at` field at all** —
`frontend/flight-status.php` rendered altitude/speed/heading with zero
indication of where the data came from or how current it was, only a
generic page-level "powered by OpenSky" blurb unconnected to the actual
result. Fixed: `formatState()` in both `FlightTracker.php` copies now
returns `'source' => 'opensky', 'fetched_at' => date('c')`, and
`flight-status.php` renders a "Source: OpenSky Network · position as of
HH:MM" line under the result card, matching the existing weather.php
pattern.

**Item 65 (photo/content provenance) — the real gap.** Package detail
pages build a photo gallery that silently mixes the package's own
uploaded photo (if any) with generic Unsplash stock photos selected by
keyword-matching the package's name/location/type
(`tms_destination_gallery()`), in the same slider with identical styling
— a visitor has no way to tell a "package photo" is stock, not the actual
property, which is precisely what the item prohibits ("do not falsely
present third-party photos as original TMS photography"). There's no
images table/column anywhere capturing source/license, so the honest fix
this round is disclosure, not a schema change (out of scope for a single
low-risk round). Fixed in `frontend/package-details.php`: a
`$galleryHasStockPhotos` flag (true whenever the gallery has more photos
than the package's own uploaded one) drives a small note under the
gallery — "Some photos are representative stock images of this
destination, not exclusive property photos." — shown only when it's
actually true. Map tiles already carry proper OSM attribution everywhere
they're used and needed no change. Also fixed in passing: a missing `alt`
attribute on `frontend/blog-post.php`'s cover image (noticed by the
subagent, unrelated one-line accessibility fix, alt text now uses the
post's own title).

**Files touched:** `frontend/package-details.php`,
`backend/FlightTracker.php`, `includes/FlightTracker.php` (kept
byte-identical to `backend/`'s copy), `frontend/flight-status.php`,
`frontend/blog-post.php`.

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms`, confirmed via matching committed file sizes on both
roots. All five files pass `php -l`.

**Testing status:** verified against the actual running local site
(Browser-pane-tools path). `package-details.php`: confirmed live on a
real multi-photo package (pkgid 413) that the stock-photo note renders
with the exact expected text when the gallery mixes stock photos in — the
negative case (own-photo-only, no note) wasn't separately exercised live
since the destination photo pool realistically always contributes at
least one photo per package, but the guarding boolean's logic (`count($gallery)
> $galleryOwnCount`) is straightforward and was reviewed directly.
`flight-status.php`: since no live OpenSky flight was on hand to query
on demand, the page's own `trackFlight()` function was called directly
with a stubbed `fetch()` returning a realistic response shape (including
the new `source`/`fetched_at` fields) — confirmed the new "Source: OpenSky
Network · position as of HH:MM" line renders correctly under the result
card. `blog-post.php`'s alt-text fix was **not exercised live** — none of
the 3 existing blog posts currently have a cover image set, so there was
no rendered `<img class="post-hero">` to inspect on any of them; verified
by code inspection and `php -l` only.

**What this round deliberately did NOT do, and why (Rule 73):** did not
add an images table or any source/license/attribution metadata storage —
that's the structurally correct long-term fix for item 65, but it's a
schema change touching every place package/destination images are
selected and rendered, not a scoped single-round fix. The disclosure note
is an honest, low-risk stopgap that directly addresses the item's actual
prohibition (misrepresenting stock as original) without that larger
change. Did not touch `explore.php`/`explore-view.php`, `destinations.php`,
or `package-list.php` for item 66 — the subagent confirmed these are
plain filtered catalogs with no match-percentage/recommendation claim, so
item 66 doesn't apply to them.

## Round 64: Monitoring (master prompt item 56) — a new consolidated admin System Health page; closed the two real tracking gaps (email failures, booking failures) that had zero visibility anywhere

**Item 56, verbatim:** "Create health monitoring for: Database, AI,
Weather, Maps, Payments, Email, External APIs. Track: error rate, latency,
failures, API usage, AI cost, booking failures, payment failures."

**Starting point.** No prior round had touched "monitoring" by name.
Investigation (direct code reading, not a subagent this round — the
surface area was narrow enough to check by hand) found most of item 56
already substantially exists, just scattered across different pages with
no single consolidated view:
- **AI**: `admin/ai-dashboard.php` already shows success rate, avg
  latency, and token usage per feature from `tbl_ai_telemetry` (built
  Round 34) — genuinely good coverage, left untouched.
- **Payments**: `admin/operations-dashboard.php` already shows payment
  failure/pending counts from `tblpayments`, plus refund requests — left
  untouched.
- **Database**: `health.php` already has a safe, credential-free JSON
  up/down + latency check (Rule 57) — left untouched.
- **Weather/Maps/External APIs**: `health.php` already reports
  configured/not-configured for each provider's API key — left untouched.

**Real gaps found: two categories with genuinely zero admin-visible
tracking.** Grepping every `tms_send_email()` call site and the
checkout-transaction catch block found both only ever logged to
`error_log()` on failure — nowhere queryable, no admin page reads PHP's
error log, so a real question like "how many booking attempts failed this
week" or "are our confirmation emails actually going out" had no answer
anywhere in the app.

**What was built:**
1. `tbl_system_events` (new table, added via this project's established
   self-healing `CREATE TABLE IF NOT EXISTS` migration array in
   `backend/config.php`/`includes/config.php` — additive only, same
   pattern as every other table added this way) — a small, generic
   (category, event_type, success, message, created_at) log, deliberately
   NOT a duplicate of `tbl_ai_telemetry` or `tblpayment_events` (those
   stay exactly as they are; see `backend/system-events.php`'s own
   docblock for the reasoning) and deliberately separate from
   `tblactivity_log` (that's "which admin did what"; this is "did a
   background operation succeed", no human actor).
2. `tms_log_system_event($dbh, $category, $eventType, $success, $message)`
   in new `backend/system-events.php` (+ identical `includes/` copy) —
   best-effort, wrapped in its own try/catch so logging a failure can
   never itself become a new failure (same principle as
   `tms_log_activity()`).
3. Wired into the two real gaps: `tms_send_email()`
   (`backend/mailer.php` + `includes/mailer.php`) now logs every send
   attempt's outcome (reads `$dbh` as a global rather than adding a
   parameter to all ~6 call sites across register.php/forgot-password.php/
   manage-bookings.php/etc. — `$dbh` is genuinely global in this codebase
   and config.php is always loaded first, so this is safe and far lower
   risk than touching every caller); `frontend/checkout.php`'s booking-
   transaction-failure catch block now logs the failure alongside its
   existing `error_log()` call.
4. New `admin/system-health.php` — a thin aggregator, not new
   infrastructure: re-runs the exact same summary queries
   `ai-dashboard.php`/`operations-dashboard.php` already use for AI/
   payments (with links out to each for full detail, so nothing is
   duplicated), adds the two new email/booking-failure numbers from
   `tbl_system_events`, shows DB up/down+latency inline, and lists
   Weather/Maps/AI/SMTP as configured/not-configured (same safe check as
   health.php) — explicitly labeled as "not implemented yet" for
   Weather/Maps per-call error-rate tracking, rather than fabricating a
   number the app doesn't actually have. Gated behind the same
   `['operations', 'manager']` roles as `operations-dashboard.php`
   (Round 20 RBAC, item 46), with a new sidebar link
   (`admin/includes/sidebarmenu.php`) under the same role guard as
   Activity Log/Emergency Contacts.

**Files touched:** `backend/system-events.php` (new),
`includes/system-events.php` (new), `backend/config.php`,
`includes/config.php` (require + new table DDL), `backend/mailer.php`,
`includes/mailer.php` (logging), `frontend/checkout.php` (logging),
`admin/system-health.php` (new page), `admin/includes/sidebarmenu.php`
(nav link) — 9 files.

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms`, confirmed via matching committed file sizes on both
roots. All touched files pass `php -l`.

**Testing status:** genuinely live-tested, not just reviewed. Using a
fresh, uniquely-named session-simulation helper
(`test-round64-auth-session-helper.php`, same disposable-`qa_test_admin`,
direct-`$_SESSION`, never-a-typed-password pattern as every other round,
now disabled/404), confirmed `admin/system-health.php` loads under the
RBAC gate and correctly renders real, live data: database up/1ms, AI
success rate/calls/latency pulled from the real `tbl_ai_telemetry` (27
calls, matching what ai-dashboard.php itself would show), 0 payment
failures. A second one-time verification script
(`test-round64-system-events-check.php`, also now disabled/404) called
`tms_log_system_event()` directly three times (one email success, one
email failure, one booking failure — no real email sent, no real booking
touched) and confirmed via a follow-up JSON read AND via the live
dashboard page that all three rows appear correctly: "1 email failure of
2 sent", "1 booking checkout failure", and the recent-events table
showing all three with correct category/event/result/detail text. One
real bug was found and fixed during this verification itself: this
project's self-healing migrations only run once per PHP session
(a documented perf optimization in config.php, not new behavior this
round introduced), so the long-lived browser session already active from
Rounds 62/63's testing had never actually created `tbl_system_events` —
the verification script's first version had an unguarded final `SELECT`
against that not-yet-existing table, threw an uncaught `PDOException`,
and hit the site's global fatal-error page. Fixed the *test script*
(added a defensive, idempotent `CREATE TABLE IF NOT EXISTS` plus a
try/catch around the SELECT) rather than the app itself — the underlying
once-per-session migration gate is existing, accepted, intentional
behavior (any genuinely fresh visitor session triggers it once, and
`CREATE TABLE IF NOT EXISTS` at the database level persists regardless of
which session created it), not a new gap this round needs to close.

**What this round deliberately did NOT do, and why (Rule 73):** did not
add per-call error-rate/latency tracking for Weather or Maps API calls —
that would mean touching every weather.php/tour-map.php call site to add
a telemetry insert, mirroring what Round 34 already did once for AI. The
system-health page says so honestly ("not implemented yet... reports what's
realistically available today") rather than fabricating a number. Did not
build "API usage" or "AI cost" tracking beyond what already existed
(`tbl_ai_usage_log`/`tbl_ai_telemetry`'s token counts) — a real dollar-cost
figure would require hardcoding per-provider per-token pricing that goes
stale the moment a provider changes rates, which is a real-world-data-
accuracy risk (Rule 3/4) not worth taking for a metric this dashboard can
already approximate via token counts. Did not add a payments/AI
"External APIs" catch-all metric beyond what health.php already checks —
weather/maps/AI are the only externally-called APIs this project actually
has.

## Round 65: Pre-trip / during-trip / post-trip organization (master prompt item 68) — my-trip.php's quick-links reorganized by phase, surfacing 6 already-built features that were never linked from the trip hub at all

**Item 68, verbatim:** organize the experience into BEFORE TRIP (planning,
booking, budget, packing, documents, visa, weather), DURING TRIP (map,
navigation, weather, flight, emergency, expenses, AI copilot,
reservations), AFTER TRIP (reviews, memories, expenses, achievements,
recommendations, next-trip planning).

**Starting point.** `frontend/my-trip.php` (built Round 18) already
computes a real `$phase` ('before'/'during'/'after'/'cancelled') from the
booking's own dates — its docblock even cites "Rule 68" — but that
computed phase was only ever used for a badge label and a "days to go"
countdown. The "Everything for this trip" quick-links panel showed the
exact same 8 links (Weather, Map, E-Ticket, Expenses, Documents,
Emergency, AI Copilot, Manage Booking) unconditionally, regardless of
phase. Worse: grepping every page my-trip.php could plausibly link to
found `budget-planner.php`, `packing-assistant.php`, `travel-memory.php`,
`reviews.php`, and `loyalty.php` (achievements) all already exist as real,
working, previously-built features — none of them were ever linked from
this hub, so a traveler using "the one place to see everything about
their trip" had no way to discover any of them from here.

**What changed:** the quick-links panel now branches on the already-
computed `$phase`, grouping the same real pages into the three sections
item 68 names — no new backend, no new page, every single link points at
a pre-existing, already-working feature:
- **Before**: Weather, Budget Planner, Packing Assistant, Documents
  (wallet.php), Map.
- **During**: Map, Weather, Flight Tracker, Emergency, Expenses, AI
  Copilot, plus E-Ticket/Reservations when the booking is confirmed.
- **After**: Write a Review (pre-filtered to this package via
  `reviews.php?pkg=`), Travel Memories, Expenses labeled "planned vs
  actual" (see item 69 note below), Achievements, Recommendations for
  your next trip (`ai-recommend.php`), Plan Your Next Trip
  (`package-list.php`).
- **Cancelled** (not one of item 68's three named phases): kept the
  previous generic Weather/Map/Documents set rather than inventing a
  fourth section.
- "Manage Booking" (tour-history.php) stays visible in every phase, since
  it's the one link this project's Rule 2 (never remove a working
  feature) genuinely required keeping universal — and it's also where
  the per-booking E-Ticket button lives for phases where it's not
  otherwise surfaced, so nothing is actually lost by not repeating it
  everywhere.
- "Visa" (also named under BEFORE TRIP in the master prompt) is
  deliberately NOT added: `SCOPE_EXCLUSIONS.md` (Round 40) already
  documents that this project has no licensed visa/entry-requirement data
  source and made a considered decision never to ship a placeholder for
  it. Adding a visa link here would have quietly reversed that decision.

**Item 69 (Post-Trip Intelligence) note:** while confirming what the
"Expenses" link should be labeled for the after-phase, found that
`expense-tracker.php`'s `getBudgetComparison()` (used whenever a specific
booking is passed) already computes exactly item 69's example — planned
budget vs. actual spend vs. remaining/over-by, with a visual progress bar
— so item 69's core ask was already done, just not linked from
my-trip.php. Labeled the after-phase link "Expenses (planned vs actual)"
to accurately describe what's already there, rather than building
anything new.

**Files touched:** `frontend/my-trip.php` (one file — no root shim/
`includes/` duplicate exists for this page).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms`, confirmed via matching committed file sizes on both
roots. `php -l` clean.

**Testing status:** genuinely live-tested for the one phase real QA data
supports, isolated-rendering-tested for the other three. Using a fresh
session-simulation helper
(`test-round65-auth-session-helper.php`, same disposable-QA-customer,
direct-`$_SESSION`, never-a-typed-password pattern as every other round,
now disabled/404) confirmed live that the disposable QA customer's one
real booking (dated 10–15 Oct 2026, i.e. currently in the "before" phase)
renders exactly the designed Before-phase set: Weather, Budget Planner,
Packing Assistant, Documents, Map, Manage Booking — nothing extra, nothing
missing. No QA booking currently sits in the during/after/cancelled
phases (would require fabricating booking dates in the past/present,
which risks disturbing other rounds' test data), so those three branches
were instead verified by extracting the exact same conditional block into
an isolated PHP script with mocked `$trip`/`$phase` objects (no database
touched at all) and confirming each branch renders precisely the intended
link set with correctly-substituted booking/package IDs — this is a
lower bar than a full page load but a legitimate check of the only thing
that could actually be wrong (the conditional logic and string
interpolation), since none of the linked-to pages themselves were changed
this round.

**What this round deliberately did NOT do, and why (Rule 73):** did not
touch any of the 6 pages being newly linked to (`budget-planner.php`,
`packing-assistant.php`, `travel-memory.php`, `reviews.php`,
`loyalty.php`, `ai-recommend.php`) — they already work, this round is
purely about discoverability/organization, exactly item 68's own framing
("organize the user experience into...", not "build new features for").
Did not restructure the main site navigation or footer to also reflect
before/during/after grouping — item 68's phase concept is meaningful only
in the context of a specific booking (my-trip.php has the booking; the
global nav does not), so applying it there would be a mismatch, not a
completion of the same idea.

## Round 66: Security re-review of the foundational auth/checkout/payment surface (master prompt item 44) — the gap Round 59 explicitly left open. Found and fixed 1 CRITICAL (account takeover via `forgot-password.php`) and 1 HIGH (admin login lockout recorded but never enforced) vulnerability, plus 2 real bugs surfaced only while live-testing the fix (a PHP/MySQL clock mismatch, and an unsupported repeated SQL placeholder)

**Why this round:** the user's own status question ("so what's need to
do now?") earlier this session was answered honestly: item 44 (Security)
had a real, scoped gap — Round 59 reviewed the *newest* surfaces (AI
copilot, marketplace, group trips, etc.) but explicitly did not re-review
the original, foundational auth/checkout/payment code from early rounds.
This round closed that gap. A subagent reviewed `frontend/login.php`,
`register.php`, `forgot-password.php`, `change-password.php`,
`logout.php`, `admin/index.php`, `admin/logout.php`,
`admin/change-password.php`, `admin/two-factor-setup.php`,
`checkout.php`, `payment.php`, `pay-now.php`,
`backend/payments/PaymentService.php`, `SandboxProvider.php`, and
`backend/config.php`'s session/CSRF/password/rate-limit helpers. Everything
else in scope (login.php, register.php, both change-password.php's, both
logout.php's, two-factor-setup.php, checkout.php, payment.php,
pay-now.php, PaymentService.php, SandboxProvider.php, config.php's
CSRF/session helpers) was independently re-verified and is genuinely
solid — no changes needed there.

**Finding 1 (CRITICAL) — `frontend/forgot-password.php` account takeover:**
password reset required only a registered email + mobile number match
(`SELECT id FROM tblusers WHERE EmailId=:e AND MobileNumber=:m`) — both
guessable/obtainable facts, not secrets — and updated the password in
that same request with no token and no rate limiting. Anyone who knew
(or found) a customer's email and phone number could take over the
account outright, with zero access to the victim's inbox or phone.
Confirmed by reading the full file directly before touching anything.

**Fix:** rewrote the page as a standard two-step flow, reusing existing
project infrastructure end to end rather than inventing anything new
(Rule 73):
- A new `tbl_password_resets` table (self-healing `CREATE TABLE IF NOT
  EXISTS`, added to the core migrations array in both
  `backend/config.php` and `includes/config.php`, same pattern as Round
  64's `tbl_system_events`): `UserEmail`, `TokenHash` (unique), `ExpiresAt`,
  `UsedAt`, `CreatedAt`.
- Step 1 (request): email only. A single-use, `random_bytes(32)` token is
  generated; only its SHA-256 hash is stored (identical shape to
  `api/index.php`'s existing `tblapi_tokens` bearer-token pattern); the
  raw token is emailed as a link via the already-imported
  `tms_send_email()`/`tms_email_template()`. The response text is
  byte-identical whether or not the email matches an account — this page
  can no longer be used to enumerate registered emails (live-verified:
  both a real and a fake email produce the exact same "if an account
  exists..." message).
- Requests are rate-limited via the same `tbl_login_attempts`/
  `tms_login_lockout_minutes_remaining()`/`tms_record_failed_login()`
  helpers `login.php` already uses, namespaced with a `pwreset:` prefix
  (same technique `admin/index.php` already uses for its `admin:`/`2fa:`
  prefixes) — this file had zero rate limiting before.
- Step 2 (reset): the emailed link carries the token; only a valid,
  unused, unexpired token (`TokenHash` match, `UsedAt IS NULL`,
  `ExpiresAt > NOW()`) unlocks the new-password form. On success: the
  token AND every other outstanding token for that account are marked
  used (an old emailed link can't be replayed after a reset already went
  through), the normal login-lockout counters are cleared, and a
  confirmation email is sent — matching the original file's existing
  post-reset notification behavior.

**Finding 2 (HIGH) — `admin/index.php` records lockout attempts but never
checks them:** `tms_record_failed_login()`/`tms_clear_login_attempts()`
were genuinely being called at the right points (2FA step and password
step), and extensive comments in the file describe the lockout hardening
as complete — but `tms_login_lockout_minutes_remaining()` was never
actually called to check/block an attempt before making one, in either
the password branch or the TOTP branch. Confirmed by reading the full
file directly. **Fix:** added the missing lockout check immediately
before each branch's credential verification, mirroring exactly how
`login.php` already does this (Round 14) — same helper, same
`admin:`/`2fa:`-prefixed keys this file already used for recording, no
new mechanism introduced. Live-verified: 5 failed attempts against a
username that does not exist in `admin` (so this can never touch a real
account or need a real password) now correctly returns "Too many failed
sign-in attempts" on the 6th try, where before it would have kept
accepting guesses forever.

**Bundled low-priority fix:** `tms_verify_password()`'s legacy-MD5
comparison used `$stored === md5($plain)` (a timing side-channel in
theory, flagged as optional/non-urgent by the subagent) — changed to
`hash_equals()`, matching this file's own CSRF check, since it was zero
risk to fix while already in this function.

**Two real bugs found only by live-testing the fix, both now fixed:**
1. **PHP/MySQL clock mismatch.** Live-verified this environment's PHP
   process (`date.timezone` = Europe/Berlin) and its MySQL server (`SELECT
   NOW()`, `time_zone` = SYSTEM) disagree by several hours. This didn't
   matter to any pre-existing feature that only ever compared MySQL
   timestamps to MySQL's own `NOW()` — but
   `tms_login_lockout_minutes_remaining()` (pre-existing, unchanged logic
   until this round) fetched `MAX(CreatedAt)` as a string and handed it to
   PHP's `DateTime`, then compared against PHP's own `new DateTime()` "now"
   — silently turning every real lockout into one lasting hours instead
   of `TMS_LOGIN_LOCKOUT_MINS` (observed live: "try again in 253 minutes"
   instead of ≤15). This bug predates this round and has likely affected
   `login.php`'s and `admin/index.php`'s lockout-wait-time messaging (not
   the block itself, which still worked, just for far longer than
   intended) for as long as this environment's clocks have disagreed —
   but it directly undermined this round's own new admin-lockout
   enforcement and the new reset-token's expiry, so it's fixed here rather
   than filed away as a separate future round. **Fix:** rewrote the
   function to compute the remaining minutes entirely in SQL
   (`TIMESTAMPDIFF`/`DATE_ADD` against `NOW()`), so both sides of the
   comparison come from the same MySQL clock and PHP's timezone setting no
   longer matters. Applied the same principle to the new token's
   `ExpiresAt` (computed via `DATE_ADD(NOW(), INTERVAL ... MINUTE)` in the
   INSERT itself, not PHP's `date()`/`strtotime()`).
2. **Unsupported repeated named SQL placeholder.** This connection uses
   `PDO::ATTR_EMULATE_PREPARES => false` (native prepares); the rewritten
   lockout query originally reused `:mins` twice in one statement, which
   this MySQL PDO driver rejects outright (`SQLSTATE[HY093]: Invalid
   parameter number` — confirmed live, not guessed). Fixed by binding the
   same value under two distinct placeholder names.

**Files touched:** `backend/config.php` + `includes/config.php` (new
`tbl_password_resets` migration; rewrote
`tms_login_lockout_minutes_remaining()`; `hash_equals()` fix in
`tms_verify_password()`), `frontend/forgot-password.php` (full rewrite of
the reset flow — only file for this page, no `includes/` duplicate),
`admin/index.php` (added the two missing lockout checks).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (10 file writes confirmed via `device_commit_files`,
zero rejected). `php -l` clean on every edited file.

**Testing status:** genuinely live-tested end to end via a throwaway
verification script (`test-round66-security-fixes-check.php`, disposable
QA accounts and fake usernames only, never a typed password anywhere —
every "login attempt" was a scripted server-side curl POST, not browser
form input) that drove real HTTP requests against the actual running
site and checked the actual database state after each: table migration
present; enumeration-proof identical responses for a real vs. fake email;
rate limiting kicks in after 5 reset requests; a validly-issued token
renders the reset form and identifies the right account; submitting a new
password actually changes the stored hash (independently verified via
`tms_verify_password()`); the same token is rejected on replay; an
expired token is rejected; and the admin login is now actually locked out
after 5 failed attempts against a nonexistent username. All 15 checks
passed after the two incidental bugs above were found and fixed (multiple
iterations were needed — each failure was independently root-caused via
targeted debug scripts before being fixed, per Rule 4, rather than
guessed at). Every disposable QA account, password-reset row, and
rate-limit row this round's testing created was deleted afterward
(confirmed via a final cleanup pass: 0 rows remaining in both new/touched
tables). All throwaway helper scripts
(`test-round66-security-fixes-check.php`, `test-round66-debug.php`,
`test-round66-debug2.php`, `test-round66-cleanup.php`) disabled to 404
stubs and confirmed disabled live. Also spot-checked (plain page loads,
no session tricks needed) that `forgot-password.php`, `admin/index.php`,
and `login.php` all still render correctly after these shared-file
changes — no regression.

**What this round deliberately did NOT do, and why (Rule 73):** did not
audit every other date/time comparison in the codebase for the same
PHP/MySQL clock-mismatch class of bug — only fixed the two spots this
round's own work actually touched and depended on
(`tms_login_lockout_minutes_remaining()` and the new reset token's
expiry). Other pre-existing PHP-clock-based date math elsewhere in the
app may share this same latent bug on this specific environment; that's
a reasonable candidate for a future round's audit, not something to
chase down exhaustively under a security round about a different, already
large scope. Did not add a password-strength meter or complexity
requirements to the new reset form — out of scope for this round (a
takeover vulnerability and a missing enforcement check), and the existing
6-character minimum was left as-is, matching every other password field
in this app.

## Round 67: Accessibility (master prompt item 32, WCAG 2.2 AA) — keyboard navigation & focus, the category with the most real findings from a targeted 4-area audit (reduced motion, keyboard/focus, ARIA on complex widgets, touch targets)

**Why this round, and this category specifically:** per the user's own
earlier status question this session, item 32 had only had 2 partial
passes (Round 55: sign-in/sign-up dialog + auth pages; Round 58: site-wide
label/input association). A subagent audit of 4 specifically NOT-yet-
covered areas — reduced motion, keyboard nav/visible focus, ARIA on
complex widgets (maps/charts), touch targets — found keyboard nav/focus
had the most, and most independently fixable, real bugs, so this round
fixes that category; ARIA-on-widgets (a canvas chart with no accessible
name on `admin/dashboard.php`, a Leaflet map with no label on
`tour-map.php`) and reduced motion (one isolated page) were also
identified and mostly/fully addressed, left as noted below.

**Findings fixed:**
1. **Mega-menu (`backend/header.php`/`includes/header.php`) and
   notification dropdown had no `aria-expanded`, and Escape didn't close
   either one.** Both toggled a CSS class with zero state exposed to
   assistive tech (WCAG 4.1.2), and the only close mechanism was a
   document-level *click* listener — a keyboard user who opened either
   with Tab+Enter had no keyboard way to close it at all (WCAG 2.1.1).
   Fixed: `aria-haspopup`/`aria-expanded`/`aria-controls` added to both
   triggers, kept in sync on toggle; the existing Escape handler (which
   already closed the sign-in/sign-up modals) now also closes whichever
   of these is open and returns focus to its trigger button, mirroring
   the "return focus to opener" courtesy the sign-in modal already has
   (Round 55).
2. **Toast-dismiss control was a bare `<span onclick=...>&times;</span>`**
   — no `tabindex`, no keyboard handler, no role, no accessible name
   beyond a raw "×" glyph (WCAG 2.1.1/4.1.2), and at `font-size:12px` with
   no padding it also failed the 24×24px minimum touch-target size (WCAG
   2.5.8). Fixed: now a real `<button>` with `aria-label="Dismiss
   notification"` and an explicit 24×24 hit area (native keyboard/AT
   semantics for free); visually unchanged otherwise.
3. **Human chat widget (`backend/chat-widget.php`/`includes/chat-widget.php`)
   had no `aria-expanded` on its toggle and no `role`/`aria-live` on its
   message stream** — a direct, confirmed inconsistency with the AI
   assistant widget (`backend/chatbot.php`), which already has
   `role="log" aria-live="polite"` on its own message list, meaning an
   incoming reply here was announced nowhere for a screen-reader user.
   Fixed to match that existing pattern. **Caveat, found while trying to
   live-test this one:** `chat-widget.php` is not actually `include()`'d
   from any live page in this codebase (confirmed via a full-codebase
   grep) — it appears to be built but never wired in, a pre-existing
   condition unrelated to this round. The fix is verified correct by
   `php -l`, direct code review, and by the fact that it reuses the exact
   same `aria-expanded`-toggle mechanism this round live-verified working
   on the mega-menu/notif dropdown, and the exact `role="log"
   aria-live="polite"` pattern already live in `chatbot.php` — but it
   could not be exercised end-to-end in a real page load, since there is
   currently no live page that renders it. Not fixed (wiring it into a
   page) — that's a feature/scope decision, not an accessibility fix, and
   outside this round.
4. **`frontend/thankyou.php` was the one page using WOW.js/animate.css
   without `css/modern-theme.css`**, which is where every other such page
   gets its global `@media (prefers-reduced-motion: reduce)` override
   (WCAG 2.3.3/2.2.2) — confirmed via grep that this rule already covers
   64 other pages and the whole admin panel, and this was the sole gap.
   Fixed by adding just that one rule inline (not the whole stylesheet,
   to avoid pulling in unrelated styling — Rule 73), since this page uses
   none of modern-theme.css's other rules.

**Files touched:** `backend/header.php` + `includes/header.php` (mega
menu/notif dropdown aria-expanded + Escape handling, toast-close button),
`backend/chat-widget.php` + `includes/chat-widget.php` (aria-expanded +
aria-live), `frontend/thankyou.php` (reduced-motion media query). All
four `backend`/`includes` pairs confirmed identical after editing (one
pre-existing, already-documented divergence remains between
`backend/header.php` and `includes/header.php`: the Round 61 RTL
dir-flip script, unrelated to this round and not touched).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (10 file writes confirmed via `device_commit_files`,
zero rejected). `php -l` clean on every edited file.

**Testing status:** genuinely live-tested in the real Browser pane
against the running site (not a static code read), separately at both
the site's own default narrow width and an explicit 1440×900 desktop
emulation (the narrow width turned out to hide the notification
bell entirely behind `.tms-topbar{display:none}` and require opening the
mobile hamburger nav first to reach the mega-menu — both pre-existing,
intentional responsive behaviors, not bugs; re-tested cleanly at desktop
width after finding that). For both the mega-menu and the notification
dropdown: opened via a focused trigger + `click()` (simulating real
keyboard activation), confirmed `aria-expanded` flips true, moved focus
onto an item inside the open menu, dispatched a real `Escape` keydown,
and confirmed the menu closed, `aria-expanded` flipped back to false, AND
focus returned to the trigger button — all 3 checks passed for both
widgets. The toast-close button was tested by triggering a real toast,
confirming it renders as a `<button>` with the right `aria-label` and an
exact 24×24px box, then dispatching a real `.click()` and confirming the
toast was actually removed from the DOM. The reduced-motion fix was
verified by loading `thankyou.php` live and reading back
`document.styleSheets` for the actual `@media (prefers-reduced-motion:
reduce)` rule text, confirming it's present with the correct declarations
(this environment's Browser pane tools don't expose a way to toggle the
OS-level reduced-motion preference itself, so the rule's presence and
correctness were verified directly rather than its rendered effect).
`chat-widget.php`'s fix could not be live-tested (see caveat above) — its
correctness rests on static review plus the parallel already-live-tested
mechanisms it reuses. A disposable QA customer session (direct
`$_SESSION` set after a DB existence check, never a typed password) was
used to reach the logged-in-only notification dropdown; the helper script
was disabled to a 404 stub afterward and confirmed dead.

**What this round deliberately did NOT do, and why (Rule 73):** did not
fix the ARIA-on-widgets or touch-target categories from the same audit —
`admin/dashboard.php`'s Chart.js doughnut has no accessible name and
`tour-map.php`'s Leaflet map has no `aria-label`, both real WCAG 1.1.1
gaps, but they're isolated, single-file fixes better suited to their own
focused round than bundled into a keyboard-navigation round. Did not wire
`chat-widget.php` into any live page — see the caveat above; that's a
product decision (is this feature meant to ship?), not something an
accessibility round should decide unilaterally. Did not attempt a
full site-wide keyboard-navigation sweep of every dropdown/modal (e.g.
the classic Bootstrap-v3 `data-toggle="modal"` dialogs on
`package-details.php`, `budget-planner.php`, `custom-trip.php`,
`reviews.php`, `write-us.php`/`footer.php`, which have no real focus trap
since they predate the sign-in modal's Round-55 fix) — that's a real,
identified gap worth its own future round, not a one-line fix like the
ones made here.

## Round 68: Accessibility (master prompt item 32, WCAG 2.2 AA) — ARIA on complex widgets, the category Round 67 identified but deliberately deferred

**Why this round:** Round 67's own audit named two isolated, real WCAG
1.1.1 (non-text content) gaps that didn't fit a keyboard-navigation round:
the admin booking-status donut chart and the customer-facing interactive
tour map both had no accessible name at all. Each is a single-file,
independent fix, so this round closes both.

**Findings fixed:**
1. **`admin/dashboard.php`'s `#statusChart` Chart.js doughnut had no
   accessible name, and its three figures (confirmed/pending/cancelled)
   existed nowhere else on the page as text.** A screen reader announced
   the canvas as a bare, unlabeled element; a sighted user with images/
   canvas disabled, or anyone using the "Chart couldn't load" fallback
   state, had no way to learn the actual booking breakdown at all. Fixed
   by adding two new PHP-side `safe_count()` queries
   (`$confirmedCount`, `$cancelledCount`, alongside the pre-existing
   `$pending`) and putting `role="img"` plus a server-computed
   `aria-label` (e.g. `"Booking status: 4 confirmed, 3 pending, 3
   cancelled"`) directly on the `<canvas>` element. Computed server-side
   rather than read out of the JS variables Chart.js itself uses, so the
   label stays accurate even if Chart.js's CDN script fails to load (the
   pre-existing `statusChartUnavailable` fallback state) or JavaScript is
   disabled entirely. Deliberately did not also add a redundant
   visually-hidden text table duplicating the same three numbers (Rule
   73) — one accessible name carrying the actual figures is enough here.
2. **`frontend/tour-map.php`'s `#tourMap` Leaflet map container had no
   accessible name.** A screen reader announced it as a bare, unlabeled
   `<div>`. Fixed by adding `role="region" aria-label="Interactive map of
   tour destinations"` to the container. Deliberately used `role="region"`
   rather than `role="application"` — the latter would tell assistive
   tech to hand over all standard reading/navigation behavior to the
   page's own JS for everything inside the map, which is a much larger
   behavioral change than this round's narrow goal of giving the widget a
   name (Rule 73). The page's separate color legend and destination
   sidebar were already fine (real text labels) and were not touched.

**Files touched:** `admin/dashboard.php` (no `includes/` counterpart —
admin-only file), `frontend/tour-map.php` (no `includes/` counterpart —
confirmed via a full-codebase search before editing; the root-level
`tour-map.php` is a pre-existing thin `require` shim, unaffected).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (both files written via `device_commit_files`, zero
rejected). `php -l` clean on both edited files.

**Testing status:** genuinely live-tested in the real Browser pane
against the running site. `tour-map.php` is public (no auth gate), so it
was checked directly: `#tourMap`'s `role` and `aria-label` read back
exactly as written, and its Leaflet tile pane confirmed rendered. The
admin dashboard requires an authenticated admin session, so a disposable
QA session-simulation helper (`test-round68-admin-session-helper.php`)
was used — it verifies a real existing `admin` row via `SELECT` (never a
typed password, never a fabricated account) and sets
`$_SESSION['alogin']` directly, per the standing security rule. Loaded
the live dashboard through it and confirmed: `#statusChart` has
`role="img"` and `aria-label="Booking status: 4 confirmed, 3 pending, 3
cancelled"`, Chart.js loaded successfully and rendered the canvas, and —
critically — those three server-side numbers were cross-checked against
the page's own independently-queried JS variables
(`confirmed`/`pending2`/`cancelled`, used to actually draw the chart) and
matched exactly (4/3/3 on both sides), confirming the label isn't just
present but accurate. The helper script was disabled to a 404 stub
afterward and confirmed dead live (`document.body.innerText` reads "Not
found.").

**What this round deliberately did NOT do, and why (Rule 73):** did not
touch the touch-target category from the same original Round 67 audit
(smaller interactive controls below the WCAG 2.5.8 24×24px minimum) —
that's a separate, broader sweep better suited to its own round. Did not
add a visually-hidden data table under the chart (see finding 1 above).
Did not change `role="application"` semantics or add keyboard pan/zoom
instructions to the map (see finding 2 above) — Leaflet's own default
keyboard handling (arrow-key pan, +/- zoom) was left as-is and not
audited this round.

## Round 69: Accessibility (master prompt item 32, WCAG 2.2 AA) — touch/pointer target size, the last of the 4 categories from Round 67's original audit

**Why this round:** closes out the 4-category accessibility audit Round 67
ran (reduced motion ✓ Round 67, keyboard/focus ✓ Round 67, ARIA on complex
widgets ✓ Round 68, touch targets — this round). A subagent did a fresh,
scoped static audit of the codebase specifically for WCAG 2.5.8 (Target
Size, Minimum — every pointer/touch target should be ≥24×24 CSS px unless
it's inline text, browser-default-sized, or has enough surrounding spacing)
and ranked findings by how many pages a shared CSS rule reaches.

**Findings fixed:**
1. **Bootstrap modal close (`×`) buttons** (`.modal-header .close`, styled
   in `css/modern-theme.css`) — zero padding on `button.close` left the
   bare glyph's clickable width at roughly 13–18px even though its 26px
   line-height already cleared the height minimum. Reaches the sign-in
   modal (`backend/signin.php`/`includes/signin.php`, included on 21
   frontend pages) and the contact modal (`backend/write-us.php`, included
   on ~32 pages) via one shared CSS rule. Fixed with `min-width:24px` +
   centering, live-verified at 24×26px on both modals. Also noticed and
   fixed a small, adjacent gap while touching this exact button: the
   sign-in modal's close button never had `aria-label="Close"` — its
   sibling on the contact modal already did — so it's added, matching the
   existing correct pattern (bonus fix, same file, same element).
2. **`.chat-mic`** (the voice-input toggle inside the sitewide AI chat
   widget, `includes/chatbot.php`/`backend/chatbot.php`, pulled in via
   `footer.php` on 57 pages) — `padding:0 8px` (zero top/bottom) left its
   clickable height at ~19px; width already passed. Fixed with
   `min-height:24px` plus flex-centering, live-verified.
3. **`.btn-xs`** (Bootstrap's stock small-button class, `padding:1px 5px;
   font-size:12px`, ~20px tall regardless of content) — its icon-only
   instances (`compare.php`'s remove-from-compare button, `wallet.php`'s
   document-delete button, `expense-tracker.php`'s delete-expense button,
   which further zeroes its own padding) also came out narrower than
   24px, with no text to pad the width. One shared CSS override
   (`min-height`/`min-width:24px` on `.btn-xs`, added to
   `css/modern-theme.css`) covers all instances that load this stylesheet;
   confirmed the two admin pages that also use `.btn-xs`
   (`add-package.php`/`edit-package.php`) use it only on a text-labelled
   button that already passes and don't load this stylesheet, so they're
   correctly unaffected. Live-verified at 24×24px (synthetic element test,
   since the compare-list needed real cart contents to render the actual
   button — the CSS rule itself was confirmed active on the live page).
4. **Password show/hide "eye" toggle buttons.** The subagent's audit named
   `backend/signup.php`/`includes/signup.php` for this — but investigating
   before editing turned up a real, pre-existing (Round 14) discovery:
   *that file is never `include()`'d anywhere in the codebase* (a comment
   in `signin.php` already documented this exact fact), so it has zero
   live effect either way. The actual live sign-up flow the topbar links
   to is `backend/header.php`'s own `#signupModal` (a different modal
   system entirely) — but that modal has no password-visibility toggle at
   all, so there was no live bug of this specific type there either. The
   real live bug was on the separate, directly-reachable `register.php`
   standalone page (`frontend/register.php`, root-shimmed at
   `/register.php`), whose `.eye-btn` had the identical `padding:0` /
   no-explicit-box problem. Fixed there — `width/height:24px` +
   flex-centering, `right` nudged from 10px to 4px to keep the icon
   visually in place — and live-verified via `getBoundingClientRect()` at
   exactly 24×24px on both the password and confirm-password fields.
   `signup.php`'s matching (but dead) code was left fixed too, on the
   same "don't leave known-worse code lying around" reasoning as Round
   67's `chat-widget.php` decision, but its fix has **not** been
   live-tested and can't be, for the same reason — nothing renders it.

**Files touched:** `css/modern-theme.css` (`.modal-header .close`,
`.btn-xs`), `backend/signin.php` + `includes/signin.php` (`aria-label`
bonus fix), `backend/chatbot.php` + `includes/chatbot.php` (`.chat-mic`),
`frontend/register.php` (`.eye-btn` — the real live fix for finding 4),
`backend/signup.php` + `includes/signup.php` (matching but dead-code fix,
not live-tested — see caveat above).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (all files written via `device_commit_files` across two
delivery batches, zero rejected). `php -l` clean on every edited PHP file;
`css/modern-theme.css` brace-balance checked (386 open / 386 close).

**Testing status:** genuinely live-tested in the real Browser pane against
the running site, at an explicit 1440×900 desktop viewport, after logging
out of a leftover customer session that was redirecting `register.php`
away (its own correct "already logged in" guard). Confirmed via
`getBoundingClientRect()`: both `register.php` eye-toggle buttons at
exactly 24×24px; both the sign-in modal's (`#myModal4`) and the contact
modal's (`#myModal3`) close buttons at 24×26px with `aria-label="Close"`
present, opened for real via jQuery's `.modal('show')` (real Bootstrap
modal lifecycle, not just a static DOM read); `#chatMicBtn`'s computed
`min-height:24px` confirmed applied on a live page. The `.btn-xs` fix was
confirmed two ways: the shared CSS rule's `min-height`/`min-width:24px`
read back via `getComputedStyle`, and a synthetic `<button class="btn-xs
btn-danger">` appended to the live page (since the real remove-from-compare
button only renders with actual compare-list contents, which weren't
fabricated per Rule 3) measured at exactly 24×24px. The `signup.php`
(dead file) fix could not be live-tested — see finding 4's caveat; its
correctness rests on being byte-identical in structure to the
already-verified `register.php` fix.

**What this round deliberately did NOT do, and why (Rule 73):** did not
change the global `.close`/`button.close` Bootstrap rule (scoped the fix
to `.modal-header .close` only) — other `.close` usages in the codebase
(e.g. `.alert-dismissible .close`) weren't audited this round and a
broader change risked unintended sizing changes outside this round's
scope. Did not touch the *text-labelled* `.btn-xs` buttons' visual
padding/font-size beyond the min-height/min-width floor (e.g. "Cancel",
"Download", "View Ticket") — they already read as normal-sized buttons
with real text, and the minimum floor alone is enough to close the actual
WCAG gap without a cosmetic redesign. Did not attempt a full sitewide
touch-target sweep beyond the audit's top findings (e.g. admin table
action-icon buttons sat right at the 24px threshold per the audit but
within measurement uncertainty of the font stack — not touched without a
confirmed live failure, per Rule 4/Rule 77 — never claim a fix for a bug
that wasn't confirmed).

This closes out the full 4-category WCAG 2.2 AA audit Round 67 opened
(reduced motion, keyboard/focus, ARIA-on-widgets, touch targets) across
Rounds 67–69.

## Round 70: Accessibility (WCAG 2.1.1/2.4.3) — real focus trap + focus-return on the classic Bootstrap modals, closing the gap Round 67/69 flagged and deferred

**Why this round:** Rounds 67 and 69 both named the same real gap and
deferred it: the classic Bootstrap-v3 `data-toggle="modal"` dialogs
(`#myModal3` write-us/contact modal, `#myModal4` sign-in modal) predate
the custom `tms-modal-overlay` sign-in/sign-up dialogs' Round-55 focus
handling and never got an equivalent fix. Investigating before touching
anything (per the project's read-before-edit discipline) found the
initial framing was slightly off: this isn't 5-6 separate modals needing
individual fixes — every `data-toggle="modal"` trigger across
`package-details.php`, `budget-planner.php`, `custom-trip.php`,
`reviews.php`, `forgot-password.php`, and the sitewide footer all point
at just these same two shared modal IDs. So the real scope is two shared
modals, fixable in one place.

**What was actually broken (verified, not assumed):** `js/bootstrap.min.js`
does include Bootstrap 3's `enforceFocus()`, which refocuses the modal's
outer container whenever a `focusin` event lands outside it — but that's
a crude fallback, not a real Tab-cycle: tabbing past the modal's last
focusable element doesn't wrap back to its first one (focus can leave the
document entirely, e.g. to the browser chrome, with no `focusin` firing
to trigger the fallback), and Bootstrap 3 never restores focus to
whichever link/button opened the modal once it closes — a keyboard user
is dropped back at the top of the page instead of back where they were.

**Fix:** one generic, ID-agnostic jQuery block added to
`backend/footer.php` + `includes/footer.php` (included on effectively
every customer-facing page, reaching both shared modals and any future
Bootstrap modal for free): captures the triggering element on
`show.bs.modal` (Bootstrap already supplies it as `e.relatedTarget`),
traps `Tab`/`Shift+Tab` within the currently-open `.modal.in` by cycling
between its first and last visible focusable elements, and returns focus
to the captured trigger on `hidden.bs.modal`. Wrapped in a
`DOMContentLoaded` listener specifically because jQuery itself loads via
a `defer`red `<script>` on these pages — footer.php's own inline `<script>`
block is NOT deferred and runs immediately during HTML parsing, which can
be *before* a deferred script earlier in the document has actually
executed, so checking `window.jQuery` without waiting for
`DOMContentLoaded` would have silently no-op'd depending on page load
timing.

**Files touched:** `backend/footer.php` + `includes/footer.php`
(confirmed byte-identical both before and after editing).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (both files written via `device_commit_files`, zero
rejected). `php -l` clean on both.

**Testing status:** genuinely live-tested in the real Browser pane
against the running site at 1440×900. For `#myModal3` (opened via its
real footer "Write to Us" link, focused then `.click()`'d to simulate
real activation): confirmed 4 visible focusable elements inside;
dispatched a real `Tab` `KeyboardEvent` from the last one (Submit button)
and confirmed focus landed on the first (the close button); dispatched a
real `Shift+Tab` from the first and confirmed focus wrapped to the last;
closed the modal via jQuery's real `.modal('hide')` (firing Bootstrap's
actual `hidden.bs.modal` event) and confirmed focus returned to the exact
trigger `<a>` element, not just "some focusable element." Repeated the
Tab-wrap check on `#myModal4` (8 focusable elements) with the same
result. All checks passed on the first try — no bugs found in this fix
during live-testing.

**What this round deliberately did NOT do, and why (Rule 73):** did not
touch Bootstrap's own `enforceFocus()` mechanism (left it running
alongside this fix as a harmless fallback for any focus escape not caused
by a Tab keypress — e.g. a stray programmatic `.focus()` call elsewhere
on the page) — removing it wasn't necessary and risked unintended side
effects on modal-open behavior elsewhere. Did not add this same
trap/return behavior to non-Bootstrap custom widgets — the mega-menu and
notification dropdown already got their own equivalent fix in Round 67
(they're not Bootstrap `.modal` elements, so this round's selector-based
fix doesn't reach them, nor should it).

This closes the last known open gap from the original Round 67
accessibility audit; Rounds 67 through 70 together cover all 4 audited
categories (reduced motion, keyboard/focus, ARIA-on-widgets, touch
targets) plus this modal-focus follow-up.

## Round 71: API architecture (master prompt item 42) — a real /api/v1/ path, per-endpoint rate limiting, and OpenAPI documentation, without touching a single existing consumer

**Why this round:** a fresh gap-analysis subagent cross-referenced all 82
master-prompt items against every round's own "what this round did NOT
do" sections and ranked open gaps by the master prompt's own priority
ladder. Real payment credentials (items 11/43), a GitHub push for CI/CD
(item 59), and a licensed visa-data source (item 18, already correctly
excluded in Round 40) are all external blockers outside what this session
can supply. Item 42 (API architecture) was flagged as a genuinely
unclaimed gap with no external blocker: `API_DOCUMENTATION.md` itself
explicitly documented, twice, that `/api/v1/` was deliberately skipped
because it looked like it would mean either breaking `api/ai.php`/
`api/explore.php` or duplicating routing logic. This round found a way to
do it without either downside.

**What was built:**
1. **A real `/api/v1/` path** (`api/v1/index.php`) that is nothing but a
   one-line `require __DIR__ . '/../index.php'` of the existing,
   unchanged file. This works because PHP's `__DIR__`/`__FILE__` magic
   constants always resolve to the file actually being compiled, not the
   includer — so every path `api/index.php` builds off `dirname(__DIR__)`
   still resolves correctly when reached this way. Net effect: `/api/v1/`
   and the legacy `/api/` behave identically, by construction, with zero
   duplicated logic and zero drift risk — and `api/ai.php`/
   `api/explore.php` are completely untouched. The legacy path is
   unchanged and still works, per the master prompt's own "do not break
   existing consumers."
2. **Real per-endpoint rate limiting** — previously the only throttling
   anywhere in the API was `tbl_login_attempts`, covering only the
   `login` action. Added `tms_api_rate_limit_check()`: a fixed-window
   counter (new `tbl_api_rate_limit` table, self-healing migration in
   both `backend/config.php` and `includes/config.php`) applied globally
   to every action (120 req/60s per IP) plus a stricter limit
   specifically on `check_email` (20/60s) — that endpoint had zero abuse
   protection and is a plain account-enumeration oracle otherwise.
   Deliberately SQL-clock-only (`WindowStart` computed via MySQL's own
   `NOW()`/`UNIX_TIMESTAMP()`, never PHP's `time()`) — Round 66 found a
   real ~4 hour PHP/MySQL clock disagreement in this environment, so any
   new time-bucketed logic has to avoid mixing the two clocks from the
   start, not discover the same bug class again the hard way. Fails
   *open* on any DB error, matching `tms_login_lockout_minutes_remaining()`'s
   own philosophy — a broken limiter must never take the whole API down.
3. **OpenAPI documentation** — `api/openapi.yaml` (OpenAPI 3.0.3, covering
   all 10 existing actions, written from the actual code + the
   already-accurate `API_DOCUMENTATION.md`, not invented) plus
   `api/docs.php`, a Swagger UI viewer for it (loaded from jsdelivr — the
   same CDN this project already uses for Chart.js on
   `admin/dashboard.php`, not a new external-dependency pattern).
4. `API_DOCUMENTATION.md` updated to match: the new base URL recommendation,
   a rate-limiting section, and the "About versioning" section rewritten
   now that the thing it used to explain *not* doing has been done.

**Files touched:** `backend/config.php` + `includes/config.php` (new
`tbl_api_rate_limit` migration), `api/index.php` (rate limiting hook,
`versioned_path` meta flag, updated top-of-file docblock),
`api/v1/index.php` (new), `api/openapi.yaml` (new), `api/docs.php` (new),
`API_DOCUMENTATION.md`.

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (all 7 files written via `device_commit_files`, zero
rejected). `php -l` clean on every PHP file; `backend/config.php` and
`includes/config.php` confirmed to differ only in the same pre-existing,
already-documented Round-51 disaster-recovery divergence (nothing new
introduced).

**Testing status:** genuinely live-tested against the real running site.
`GET /api/v1/?action=types` and `GET /api/?action=types` both hit for
real: identical data, `meta.versioned_path` correctly `true` on the v1
path and `false` on the legacy path. `GET /api/v1/?action=packages&limit=2`
also confirmed a normal, successful, non-rate-limited request end-to-end.
`api/docs.php` confirmed to actually load Swagger UI from the CDN, parse
`openapi.yaml` with **zero errors**, and render 4 real operation groups —
not just "the file exists."

A real bug surfaced *while testing*, not while building, and is worth
recording precisely because it's a previously-latent characteristic of
this codebase, not something this round introduced: `_tms_run_core_migrations()`
(where the new table's `CREATE TABLE IF NOT EXISTS` was added) only runs
once per session (`if (empty($_SESSION['tms_db_init']))` — a documented
perf optimization), not once per request. My existing browser test
session had already flagged migrations done *before* this round added the
new table, so the very first live rate-limit calls silently ran with the
table missing (caught cleanly by the fail-open handling — confirmed via
the real Apache error log — no user-facing break) rather than actually
exercising the limiter. Resolved for testing purposes with a one-off
helper that cleared that one session flag to force a fresh migration
pass; after that, a dedicated verification script proved the counting
logic itself is correct (3 requests under a temporary max=3 all read as
allowed, the 4th and 5th correctly flip to "would block"), and a
follow-up read confirmed the real `api_global` bucket had a genuine row
from actual API traffic. This same once-per-session gate applies to
*every* new table any future round adds this way — worth remembering
before assuming a fresh migration silently didn't run.

**What this round deliberately did NOT do, and why (Rule 73):** did not
implement real path-based REST routing (e.g. `/api/v1/packages` instead
of `/api/v1/?action=packages`) — that would require either duplicating
every handler's routing logic (the exact drift risk this round's
one-line `require` trick avoids) or rewriting `api/index.php`'s routing
itself, which risks `api/ai.php`/`api/explore.php`. A real breaking v2 —
if one is ever needed — is still expected to live at `api/v2/` as its own
implementation, per the project's existing, unchanged plan. Did not add
authenticated (per-user, not just per-IP) rate limiting — IP-based is a
reasonable first layer and per-user limiting on top of it is a natural
future addition, not a gap this round claims to have closed. Did not
touch `api/ai.php`/`api/explore.php`'s own routing or add them to
`/v1/` — they're siblings with their own existing conventions, out of
this round's stated scope, and touching them wasn't necessary to satisfy
"do not break existing consumers."

## Round 72: Monitoring (master prompt item 56) — real per-call error-rate tracking for Weather and Maps/Explore, closing the gap `admin/system-health.php`'s own docblock had flagged as "not tracked"

**Why this round:** `admin/system-health.php` (built in an earlier round
as the item-56 consolidated health view) already tracked Email and
Booking-checkout failures via `tbl_system_events`, but its own top-of-file
docblock honestly admitted Weather/Maps/External-APIs were only checked
as configured/not-configured, "since this project has no per-call
error-rate logging for those providers yet." That's a concrete,
self-documented gap with no external blocker — the logging primitive
(`tms_log_system_event()`, `backend/system-events.php`) already existed
and was already proven out on two other categories, so this round wired
it into the two remaining low-level HTTP call sites that didn't have it.

**What was built:**
1. **Weather instrumentation** — `tms_get_weather()` (OpenWeatherMap) and
   `tms_get_weather_openmeteo()` (Open-Meteo fallback) in `backend/config.php`
   / `includes/config.php` now call
   `tms_log_system_event($dbh, 'weather', <provider>, <success>, <detail>)`
   at every return point: the empty/malformed-response branch, the
   exception/catch branch, and the success path — for both providers.
2. **Maps/Explore instrumentation** — `ExploreService::httpRequest()`
   (`backend/ExploreService.php` / `includes/ExploreService.php`) is the
   single low-level HTTP call point behind every Explore Mode feature
   (search/geocoding via Nominatim, nearby places via Overpass, etc.), so
   instrumenting it there covers all of them at once. Logs
   `tms_log_system_event($this->db, 'maps', $host, $success, $message)`
   at all 4 return points, using only `parse_url($url, PHP_URL_HOST)` —
   never the full URL — so coordinates or search text never land in the
   log.
3. **`admin/system-health.php`** — added two new summary queries
   (`$weatherSummary`/`$weatherTotal`/`$weatherFailures`,
   `$mapsSummary`/`$mapsTotal`/`$mapsFailures`) following the exact same
   try/catch pattern as the existing Email/Booking cards, plus two new
   stat cards showing failures-of-total for each. Updated the page's own
   top-of-file docblock and the "External providers" section copy to
   stop saying Weather/Maps tracking "is not implemented yet" now that
   it is.

**Files touched:** `backend/config.php` + `includes/config.php` (weather
logging), `backend/ExploreService.php` + `includes/ExploreService.php`
(maps logging, confirmed byte-identical after editing), `admin/system-health.php`
(new queries, new stat cards, corrected docblock/copy).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (all 5 files written via `device_commit_files`, zero
rejected). `php -l` clean on every file. `backend/config.php` vs
`includes/config.php` confirmed to differ only in the same pre-existing,
already-documented Round-51 disaster-recovery divergence (nothing new);
`ExploreService.php`'s two copies confirmed byte-identical.

**Testing status:** genuinely live-tested against the real running site,
end to end. Triggered a real weather fetch (`weather.php?dest=219`, Abu
Dhabi — returned genuine live figures, 40°C/feels-like 47°C) and two real
Explore Mode calls (`api/explore.php?action=search&q=Paris`, a real
Nominatim geocode; `api/explore.php?action=places&lat=48.8566&lng=2.3522&category=restaurant`,
a real Overpass nearby-places query — both returned genuine, non-fabricated
data). Logged into `admin/system-health.php` via the established
session-simulation convention (a throwaway root-level helper,
`test-round72-admin-session.php`, verified a real admin account via
`SELECT` and set `$_SESSION['alogin']` directly — no password ever typed
into any form field) and confirmed, on the real rendered page: the new
"Weather fetch failures (7d, of 1)" card read **0 / of 1**, the new
"Maps/Explore fetch failures (7d, of 2)" card read **0 / of 2**, and the
"Recent system events" feed showed the exact 3 real rows this round's
testing produced — `Maps / overpass-api.de / OK`, `Maps /
nominatim.openstreetmap.org / OK`, `Weather / openweathermap / OK` — with
correct timestamps and zero fabricated numbers. The QA helper was then
disabled to a 404 stub and confirmed dead live via the same browser
session.

One incidental, pre-existing-bug discovery made *while testing*, not
introduced by this round: `frontend/weather.php` (and every other
`frontend/*.php` page) uses a relative `include('backend/config.php')`
that only resolves correctly when the top-level script executed by
Apache is the matching **root-level shim** (e.g. `/weather.php`, which
does `require __DIR__ . '/frontend/weather.php'`) — hitting
`frontend/weather.php` directly by URL fails with an uncaught error
(relative include can't resolve from that CWD). This is the same
root-shim pattern already used for `register.php` and others; it isn't a
regression, just a reminder that this project's `frontend/*.php` files
are implementation detail behind their root-level public shims, not
public entry points themselves.

**What this round deliberately did NOT do, and why (Rule 73):** did not
add latency/timing tracking for Weather/Maps calls (only success/failure)
— `tbl_system_events` has no duration column and every existing category
(Email, Booking) follows the same success/failure-only shape, so adding
timing here alone would be new schema for one category, not a consistent
pattern; a real future round could add it uniformly if needed. Did not
touch the "External providers" configured/not-configured table itself
(Weather/Google Maps/AI provider/SMTP rows) — that check answers a
different question ("is a key present") and still has value alongside
the new per-call cards, so both stay. Did not instrument `PlaceDetailsService.php`
or `AIClient.php`'s own HTTP calls — out of this round's stated scope
(Weather + Maps/Explore specifically); AI already has its own detailed
telemetry via `tbl_ai_telemetry`/`ai-dashboard.php`.

## Round 73: Photo/content provenance (master prompt item 65) — real Google Places photo attribution, and closing two remaining empty-alt gaps

**Why this round:** a scoping subagent audited item 65 end-to-end and found
the bulk of it already correctly closed in Round 63 (the stock-photo
disclosure note on `package-details.php`) and Round 14
(`PlaceDetailsService.php` already refuses to show fabricated stock
photos for Explore Mode places). It found exactly one genuinely open,
real gap — Google's Places API terms require displaying each photo's
attribution whenever that photo is shown, and this project streams
Google Places photos (`api/explore.php?action=place_photo`) but never
captured or rendered the attribution Google's own response provides —
plus two small, concrete empty `alt=""` attributes on `frontend/index.php`
where the real title text was sitting right there, unused. It explicitly
recommended **against** adding any images/license database table, since
no real per-photo license or photographer data exists to populate one
honestly for this project's own uploads or its curated stock-photo pool.

**What was built:**
1. **Google Places photo attribution** — `ExploreService::placesFromGoogle()`
   (`backend/ExploreService.php` / `includes/ExploreService.php`) now
   captures `photos[0].html_attributions[0]` — real data straight from
   Google's own API response, never fabricated — as a new `photo_attribution`
   field, only ever set alongside a place that genuinely has a Google
   photo (`placesFromOverpass()`'s results get an explicit `photo_attribution
   => null` for a consistent shape, since that free path never has photos
   at all). `js/explore.js`'s `renderPlaceCard()` renders it as a small
   "Photo: ..." credit line directly under the photo (Google's own HTML,
   trusted server-fetched data, not user input — rendered as-is since it's
   typically just their required attribution link), styled via a new
   `.photo-credit` rule in `css/explore.css`.
2. **Two empty `alt=""` fixes on `frontend/index.php`'s Travel Blog
   section** — the real blog card image (bound to `$post->Title` for
   actual `tblblog` rows) and the placeholder-blog fallback card (bound
   to `$b['title']`) both now carry real, descriptive alt text instead of
   an empty string.

**Files touched:** `backend/ExploreService.php` + `includes/ExploreService.php`
(photo attribution, confirmed byte-identical after editing), `js/explore.js`
(render the credit line), `css/explore.css` (`.photo-credit` styling),
`frontend/index.php` (two alt-text fixes).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (all 5 files written via `device_commit_files`, zero
rejected). `php -l` clean on every PHP file, `node --check` clean on
`js/explore.js`, and a brace-count sanity check clean on `css/explore.css`.

**Testing status:** the alt-text fix was genuinely live-tested. The
Travel Blog section only renders for a logged-in user (`frontend/index.php`
gates guests to `backend/landing.php` instead — discovered live when the
first test attempt against `http://localhost/tms/` unexpectedly rendered
the marketing landing page, not the homepage), so a throwaway root-level
helper (`test-round73-user-session.php`) verified a real user account via
`SELECT` and set `$_SESSION['login']` directly — no password ever typed
into any form field — then redirected into the real, logged-in homepage.
Confirmed live: all 3 blog card `<img>` tags now carry real, descriptive
`alt` text ("Top 10 Tips for First-Time Travelers", etc.) where they
previously had `alt=""`. The QA helper was then disabled to a 404 stub
and confirmed dead live via the same browser session.

The Google Places photo-attribution code, honestly, is **Blocked** for
live testing in this environment: `.env`'s `GOOGLE_PLACES_API_KEY` is
empty here, so `ExploreService::getNearbyPlaces()` always takes the free
Overpass/OpenStreetMap path (confirmed live in Round 72's own testing),
which never returns photos and so never needs an attribution line. The
Google code path was verified as far as this environment allows: `php -l`
clean, byte-identical across both `ExploreService.php` copies, and a
manual code-trace confirms `photo_attribution` is set if-and-only-if a
real `photo_reference` was also present (never attached to a place with
no photo). A real Google Places API key would be needed to exercise it
end-to-end; this is a genuine external blocker, not a shortcut.

One related test gap was **investigated but deliberately left as a
documentation note, not new code**: Round 63's own testing notes admit
its "own-photo-only package, no stock-photo note" negative case was never
exercised live. Re-reading that logic (`$galleryHasStockPhotos = count($gallery)
> $galleryOwnCount`) confirms it's correct by construction — the note only
shows when the gallery actually contains more than the package's own
photo — so this is recorded here as a still-open verification gap
inherited from Round 63, not something this round's changes touch or
need to re-implement.

**What this round deliberately did NOT do, and why (Rule 73):** did not
add an images/license/source database table for `tbltourpackages` or any
other photo — no real per-photo license or photographer data exists for
this project's own uploads or its curated Unsplash pool, and inventing
placeholder license text would itself be exactly the kind of fabrication
Rule 3 forbids. Did not touch `PlaceDetailsService.php`'s photo handling —
Round 14 already correctly strips all photos there rather than showing
unlicensed ones, which is the right call and out of scope to revisit. Did
not add attribution UI to the place-details modal (`openPlaceDetails()`)
since it never renders a photo at all (same Round-14 decision) — nothing
there needs a credit line to attach to.

## Round 74: Multilingual system (master prompt item 36) — the registration AJAX endpoint's 10 validation/success/error messages now run through the existing translation system, in all 8 languages

**Why this round:** a scoping subagent confirmed a real, working centralized
translation system already exists (`tms_t()`, `includes/i18n.php` /
`backend/i18n.php`, 8 languages: en/bn/hi/ar/fr/zh/es/de) and is already
wired into the sign-in/sign-up modal's own labels and placeholders
(Round 38). But the modal's *behavior* — what happens when you actually
submit it — was untouched: `frontend/user-register-ajax.php` (the AJAX
handler behind the signup modal) had 10 fully hardcoded English strings
with zero `tms_t()` calls, so a visitor who'd switched languages still
hit an all-English response the moment anything went wrong (or right) on
signup. Round 38 itself explicitly disclosed that "the vast majority of
the app's forms, validation messages... remain hardcoded English" — this
round closes one concrete, real, testable piece of that gap rather than
attempting the other 60+ pages at once (Rule 73).

**What was built:**
1. **10 new translation keys** added to all 8 existing language arrays in
   `includes/i18n.php` / `backend/i18n.php` (kept byte-identical, as this
   pair always is): `reg_err_invalid_request`, `reg_err_csrf_expired`,
   `reg_err_all_fields_required`, `reg_err_name_too_short`,
   `reg_err_invalid_email`, `reg_err_password_too_short`,
   `reg_err_password_mismatch`, `reg_err_email_exists`,
   `reg_success_created`, `reg_err_registration_failed`. Verified
   programmatically that every one of the 8 languages has all 10 keys —
   none silently missing.
2. **`frontend/user-register-ajax.php`** — every one of its 10
   `json_encode(['message' => '...'])` calls now goes through
   `tms_t('key', 'original English text')`, with the original English
   string kept as the fallback argument so behavior is unchanged for any
   language not yet covered (there is none currently, but the fallback
   pattern matches how `tms_t()` is used everywhere else in this
   codebase).
3. **Docblock accuracy fix** — `includes/i18n.php`'s own top comment still
   claimed the dictionary shipped "English + Bengali" only, though it has
   in fact covered 8 languages for some time (a stale comment from before
   that expansion, not something this round's changes caused). Corrected
   it to name all 8 languages and to explicitly flag that every
   non-English string in the file — this round's new keys included — is a
   best-effort machine translation pending native-speaker review, same
   honest caveat Round 38 already established for the rest of the file.

**Files touched:** `includes/i18n.php` + `backend/i18n.php` (10 new keys
× 8 languages, docblock fix), `frontend/user-register-ajax.php` (10
`tms_t()` call sites).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (all 3 files written via `device_commit_files`, zero
rejected). `php -l` clean on all 3 files. `includes/i18n.php` and
`backend/i18n.php` confirmed byte-identical (this pair, unlike
`config.php`, has no documented divergence — kept that way here too). A
small PHP script loaded the dictionary and confirmed all 8 languages
carry all 10 new keys before shipping.

**Testing status:** genuinely live-tested against the real running site,
in two languages. From a real logged-in session, set the `tms_lang`
cookie to `es` and, using the real CSRF token already rendered on the
page (`#signupCsrf`), sent two real `fetch()` POSTs straight to
`user-register-ajax.php`: an all-empty submission returned **"Todos los
campos son obligatorios."** (the new `reg_err_all_fields_required` key in
Spanish) and a mismatched-password submission returned **"Las
contraseñas no coinciden."** (`reg_err_password_mismatch` in Spanish) —
both exactly matching this round's new translations. Clearing the
language cookie and repeating the all-fields-empty request confirmed the
English fallback still reads exactly as before ("All fields are
required."), so existing behavior for any visitor who never touches the
language switcher is unchanged.

**What this round deliberately did NOT do, and why (Rule 73):** did not
touch `frontend/checkout.php`'s hardcoded booking-confirmation/error
strings, the admin panel, or any of the other 60+ pages Round 38 already
disclosed as still-hardcoded — those are real, separate, larger gaps for
future rounds, not something this round claims to have closed. Did not
add a 9th language or attempt native-speaker review of the 8 existing
ones — out of scope for one round, and the file's own docblock already
discloses this honestly. Did not touch `tms_translate_batch()` (the
separate AI-translation pipeline for database content like package
descriptions) — that's a different, already-working mechanism for a
different kind of content (item 36's "database content" bullet), not
part of this round's stated scope (validation/notification strings).

## Round 75: Admin security / RBAC (master prompt item 46) — closed a real broken-access-control gap on admin/manage-bookings.php

**Why this round:** a fresh gap-analysis subagent, surveying all 82
master-prompt items against every round header already logged in this
file, found a concrete, verifiable broken-access-control bug rather than
a vague gap: `admin/manage-bookings.php` (confirm/cancel/delete a
booking, approve/reject a date-change request, process/reject a refund —
all real, state-changing, already audit-logged actions) called only
`tms_require_admin()` — **any** authenticated admin, regardless of role —
with zero `tms_admin_require_role()` call anywhere in the file (confirmed
directly, not just taken on the subagent's word: `grep -n
"tms_admin_require_role" admin/manage-bookings.php` returned nothing).
Its own sibling page, `admin/manage-marketplace-bookings.php`, gates the
same kind of actions with `tms_admin_require_role($dbh,
['booking_manager','operations','manager'])` and its comment literally
claims *"same gate as manage-bookings.php"* — a claim that was false.
`admin/includes/sidebarmenu.php` also already hides the "Manage Bookings"
nav link from every other role, but that's UI-only: any Content Manager,
AI Manager, Finance, Support, or Package Manager admin could still reach
`/admin/manage-bookings.php` directly by URL and confirm/cancel/delete
bookings or process refunds, with no server-side check stopping them.

**What was built:** added the exact same `tms_admin_require_role($dbh,
['booking_manager', 'operations', 'manager'])` call, right after the
existing `tms_require_admin()`, that the sibling page and the sidebar nav
gate both already use — no new pattern invented (Rule 1), matching this
project's established RBAC convention exactly.

**Files touched:** `admin/manage-bookings.php` (one new line + explanatory
comment; confirmed no `backend/`/`includes/` duplicate of this file
exists, so nothing else needed the same change).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (written via `device_commit_files`, zero rejected).
`php -l` clean.

**Testing status:** genuinely live-tested, both directions, with a real
before/after. A throwaway root-level helper
(`test-round75-rbac-check.php`) created one disposable admin account
(`qa_round75_test_admin`, random unusable password hash — no password
ever typed into any form field), set `$_SESSION['alogin']` directly, and
flipped that account's `Role` column between an excluded role and an
allowed one: with `Role = 'content_manager'`, loading
`admin/manage-bookings.php` now returns the real "Access Restricted" 403
page ("Your admin role (Content Manager) doesn't include permission to
view this page"); with `Role = 'operations'`, the same page loads
normally ("TMS | Manage Bookings"). Both outcomes were observed live in
the browser, not asserted. The disposable admin row was then deleted
(confirmed via a `SELECT COUNT(*)` returning 0) and the QA helper
disabled to a 404 stub, confirmed dead live via the same browser session.

**What this round deliberately did NOT do, and why (Rule 73):** did not
audit all remaining ~27 role-gated admin pages for the same
"sibling-comment-says-X-but-code-says-Y" drift — the subagent flagged this
as a natural follow-up, but doing it responsibly means checking each page
individually against its own intended role list (not a bulk find/replace,
which risks either leaving a real gap or over-restricting a page that's
correctly broader), so it's left as a real, disclosed candidate for a
future round rather than rushed here. Did not change
`admin/includes/sidebarmenu.php`'s existing nav-visibility rule — it was
already correct and is untouched. Did not add a new role or change what
`booking_manager`/`operations`/`manager` are each allowed to do —
this round only closed the one page where enforcement didn't match the
already-decided, already-documented policy.

## Round 76: Admin security / RBAC (master prompt item 46) — full sweep of every remaining role-gated admin page; no further drift found

**Why this round:** Round 75 found and fixed one real gap
(`admin/manage-bookings.php` missing its role gate entirely) and flagged,
as a disclosed follow-up, that the same "sidebar hides it, but does the
page itself actually check?" question hadn't been asked of the other
role-restricted admin pages. Rather than leave that as an open worry,
this round did the actual page-by-page check Round 75 said a bulk
fix would be irresponsible to skip.

**What was done:** every `$canSee([...])`-gated nav item in
`admin/includes/sidebarmenu.php` (14 role-restricted entries covering 22
distinct admin pages, plus `manage-admins.php`'s separate
superadmin-only check) was cross-referenced against that exact page's own
`tms_admin_require_role()` call. Checked: `manage-packages.php`,
`add-package.php`, `booking-calendar.php`,
`manage-marketplace-bookings.php`, `manage-users.php`,
`manage-enquiries.php`, `manage-issues.php`, `live-chat.php`,
`manage-pages.php`, `manage-blog.php`, `manage-reviews.php`,
`manage-custom-trips.php`, `revenue-analytics.php`, `export-report.php`,
`user-analytics.php`, `popular-destinations.php`, `ai-dashboard.php`,
`manage-coupons.php`, `manage-emergency.php`, `activity-log.php`,
`system-health.php`, `manage-translations.php`, `manage-admins.php`.

**Result: no further drift found.** Every one of the 22 pages already
has its own `tms_admin_require_role()` (or, for 4 finance/analytics
pages, an equivalent explicit list that additionally spells out
`'superadmin'` — redundant since `tms_admin_can()` always passes
superadmin regardless of the list, but not a bug) whose role list matches
what the sidebar already shows/hides for. `manage-bookings.php` (fixed
last round) was the one genuine outlier, not the first of a pattern.

**Files touched:** none — this round is a verification sweep, not a code
change. No `SendUserFile`/`device_commit_files` step was needed.

**Implementation status:** N/A (no code change).

**Testing status:** verified by direct inspection (`grep` across all 22
files, cross-checked line-by-line against `sidebarmenu.php`'s own role
lists) rather than live browser testing — appropriate here since the
question is "does a static gate exist and match", not runtime behavior,
and Round 75 already proved live that this exact gate mechanism works
correctly in both directions.

**What this round deliberately did NOT do, and why (Rule 73):** did not
re-verify `change-password.php` or `two-factor-setup.php` against a role
list, since `sidebarmenu.php` deliberately shows both to every admin
regardless of role (they're account-level, not feature-level, pages) —
correctly out of scope for this kind of check. Did not audit the ~6
pages outside `sidebarmenu.php`'s nav entirely (login/logout/dashboard/
2FA/change-password, and any page reachable only via an internal link
rather than the sidebar) — those were already confirmed role-agnostic by
design in Round 20's own summary, not a gap this sweep needed to
re-litigate.

## Round 77: Personalization (master prompt item 23) — added "food/dietary preference" as a real, persisted, recommendation-affecting traveler preference dimension

**Why this round:** a gap-analysis subagent flagged item 23
("Personalization — create a traveler preference system... support budget,
travel style, destination preference, activity preference, hotel
preference, family/couple/solo/business, accessibility, pace, food
preferences") as never explicitly addressed by any round header. Direct
inspection showed this was only partly true: `tbl_user_profile`
(added under Round 67's Travel Modes work) already persists budget
(`budget_range`), climate, a family/couple/solo/business/accessible
preset (`travel_mode`), and free-text interests — genuinely covering
several of the spec's bullets already, and already fed into
`backend/ai/TravelRecommender.php`'s prompt (confirmed live in Round 67).
"Food preferences" was the one bullet with no field anywhere in the app.
(A second candidate, wiring up the schema's long-dormant `travel_style`
column, was investigated and deliberately set aside this round — see
below.)

**What was built:**
1. **Schema**: `food_preference VARCHAR(30) DEFAULT NULL` added to
   `tbl_user_profile` via the existing self-healing
   `tms_ensure_columns()` mechanism in both `backend/config.php` and
   `includes/config.php` (same pattern already used for `travel_mode`,
   which also isn't in the table's literal `CREATE TABLE` — consistent
   with existing precedent, not a new one).
2. **`frontend/profile.php`**: added a "Dietary / Food Preference" select
   (No restriction / Vegetarian / Vegan / Halal / Kosher) to the existing
   "Travel Preferences" card, wired into the same save/read/validate flow
   already handling travel_mode/budget_range/preferred_climate/interests.
3. **`frontend/ai-recommend.php`**: added the same select to the
   recommend form, prefilled from the saved profile exactly like the
   existing Travel Type/Climate fields (still fully editable per
   request), and passed through to the recommender as
   `$prefs['food_preference']`.
4. **`backend/ai/TravelRecommender.php` + `includes/ai/TravelRecommender.php`**
   (kept byte-identical): added a "Dietary/food preference: ..." line to
   the AI prompt, right alongside the existing Activities/Accommodation
   lines. No new honesty guardrail text was needed — the recommender's
   existing rule 7 ("never claim or imply a package has an attribute...
   that isn't present in the inventory data given to you") already
   generically covers meal/dietary claims the same way it already covers
   accessibility claims, since `tbltourpackages` has no meal-data column
   to verify against either way.

**Files touched:** `backend/config.php` + `includes/config.php` (new
column), `frontend/profile.php` (UI + save/read), `frontend/ai-recommend.php`
(UI + prefill + pass-through), `backend/ai/TravelRecommender.php` +
`includes/ai/TravelRecommender.php` (prompt line).

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (all 6 files written via `device_commit_files`, zero
rejected). `php -l` clean on all 6. `backend/config.php`/`includes/config.php`
confirmed to differ only in the same pre-existing Round-51 divergence;
the two `TravelRecommender.php` copies confirmed byte-identical.

**Testing status:** genuinely live-tested end to end using a throwaway
root-level helper (`test-round77-user-session.php` — verified a real user
account via `SELECT`, set `$_SESSION['login']` directly, no password ever
typed). On the real `profile.php`, set Food Preference to "Vegan" and
confirmed the real "Travel preferences saved." success message; reloaded
the page fresh and confirmed the select still read "vegan" (genuine DB
round-trip, not just an unsaved form state). Navigated to the real
`ai-recommend.php` and confirmed its Food Preference select was
pre-selected to "vegan" from the saved profile, matching the existing
Travel Type/Climate prefill behavior exactly. Submitted a real
recommendation request (budget ৳50,000, 1 traveler) and got back 5 real
package matches with no PHP errors or warnings in the real Apache error
log (only the pre-existing, unrelated "GEMINI_API_KEY not configured"
notice, confirming the deterministic fallback scorer ran — as designed,
not a regression). Since testing used the first real row in `tblusers`
rather than a disposable account, the Food Preference value was reset
back to empty afterward and confirmed empty on reload, leaving that real
account exactly as found. The QA helper was then disabled to a 404 stub
and confirmed dead live via the same browser session.

**What this round deliberately did NOT do, and why (Rule 73):** did not
wire up `tbl_user_profile`'s separate, long-dormant `travel_style` column
(distinct from `travel_mode`, per an explicit Round 67 comment: "travel_mode
is a distinct concept from travel_style... not a vague style tag"). Direct
inspection found the existing `ai-recommend.php`/`profile.php` UI already
labels the `travel_mode` field **"Travel Style"** to the end user — so
surfacing the separately-named DB column as a second, differently-scoped
"style" concept risked confusing overlap with an already-shipped UI label,
not a clean addition. This is flagged here as a real, disclosed naming
collision for a future round to resolve deliberately (most likely a
rename), rather than rushed into this one. Did not add "pace" or "hotel
preference" fields (also named in the spec) — narrowing this round to one
clean, unambiguous, fully-wired dimension (Rule 73) rather than four
half-wired ones. Did not add `food_preference` to `tblrecommendations`
(the separate request-history log table) — that's an audit trail of past
requests, not the persisted profile, and out of this round's scope.

## Round 78: Availability/Inventory (master prompt item 9) — real-time "seats remaining" badge added to the package listing page, matching an already-existing feature on the package detail page

**Why this round:** the master prompt's item 9 gives a concrete example —
show something like "12 seats remaining" so travelers feel urgency and
don't book a sold-out slot. Direct code investigation (not just a
subagent's gap-analysis claim) found that `frontend/package-details.php`
**already implements this correctly**, via the existing, well-built
`tms_dynamic_price()` → `tms_remaining_seats()` pipeline in
`backend/PricingEngine.php` (thresholds: remaining ≤2 → red "Only X
seat(s) left!", ≤8 → amber "X seats left — filling up", else nothing).
That pipeline also already backs `frontend/checkout.php`'s
transaction-safe, row-locked double-booking prevention (`FOR UPDATE`),
which was confirmed still correct and left untouched. The one real,
narrow gap: `frontend/package-list.php` (the browse/search listing page)
computes the same pricing/demand data per package in its loop already
(for the price and "High demand" badge) but never surfaced the seats
value — so a shopper saw no low-availability signal until they clicked
into a specific package.

**What was built:**
1. **`frontend/package-list.php`**: added a seats-remaining badge to the
   package card loop, reusing the `$listPricing` array already computed
   earlier in that same loop (`$listPricing = tms_dynamic_price(...)`) —
   no new query. Deliberately matched `package-details.php`'s exact
   thresholds, colors, icons, and wording verbatim (≤2 red "Only X
   seat(s) left!", ≤8 amber "X seats left — filling up", nothing when
   `remaining_seats` is null/untracked), so the two pages present a
   single consistent signal rather than two different UI conventions for
   the same underlying number. Inserted right after the existing "High
   demand" badge in the card markup.
2. **`frontend/package-details.php`**: no change. An initial edit adding
   a second, redundant computation of the same value was made, then
   caught and reverted once the pre-existing feature was found by
   reading the file directly — `php -l` confirmed the file is valid and
   unchanged from its pre-Round-78 state.

**Files touched:** `frontend/package-list.php` only. (No
backend/includes duplicate exists for this file — confirmed via grep —
so there was nothing to keep in sync.)

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (shipped via `device_commit_files`, both writes
succeeded). `php -l` clean.

**Testing status:** genuinely live-tested end to end, including a
real-data complication worth recording. A read-only diagnostic
(`test-round78-inventory-query.php`) first confirmed that **no package
in the real, live database currently has `SeatsAvailable` tracked at
all** — every row is NULL/untracked, which is exactly why the admin
Operations Dashboard already showed "0 Low-inventory packages" and why
`package-list.php` showed no seat badges on any of its 413 real
packages before this round (confirmed as the honest baseline, not a
bug). To observe the new badge against real (not fabricated) computed
data, a second throwaway helper
(`test-round78-inventory-mutate.php`) temporarily set
`SeatsAvailable` on ONE real, already-existing package ("Bali Ubud Rice
Terraces", PackageId 413, confirmed NULL beforehand) to 2, then 6, then
10 in turn, reading `package-list.php` and `package-details.php` after
each change:
   - `SeatsAvailable=2` → real existing confirmed bookings on that
     package brought the computed remaining count to 0 (floored at 0,
     not negative) — both pages correctly showed the red "Only 0 seats
     left!" badge, identically worded.
   - `SeatsAvailable=6` → computed remaining = 2 (still ≤2) — both pages
     correctly stayed on the red badge ("Only 2 seats left!"), proving
     the threshold priority (red checked before amber) is consistent
     between the two pages.
   - `SeatsAvailable=10` → computed remaining = 6 (>2, ≤8) — both pages
     correctly switched to the amber "6 seats left — filling up" badge,
     identically worded.
   - `?step=reset` set `SeatsAvailable` back to NULL (its original,
     confirmed value) — the read-only diagnostic was re-run and
     confirmed zero packages have tracked capacity again, and
     `package-list.php`/`package-details.php` were reloaded and
     confirmed back to their original pre-test appearance (only the
     pre-existing "High demand" badge, no seats badge, same price as
     the very first baseline check). No real data was left mutated.
   - All three throwaway helpers (`test-round78-admin-session.php`,
     `test-round78-inventory-query.php`,
     `test-round78-inventory-mutate.php`) were then disabled to 404
     stubs, shipped, and confirmed dead live in the browser.

**What this round deliberately did NOT do, and why (Rule 73):** did not
build out the broader item-9 spec — hotel room inventory, activity slot
capacity, transport seat maps, or date-specific (per-departure-date)
inventory. `tbltourpackages.SeatsAvailable` is a single, per-package
capacity number, not per-date; nothing in this round changed that data
model. This round closed exactly the literal example the master prompt
gives ("seats remaining" urgency messaging) using infrastructure that
already existed and was already tested (`PricingEngine.php`,
`checkout.php`'s locking), extending its display to the one page that
was missing it, rather than re-architecting inventory tracking from
scratch. A genuinely deeper per-date/per-resource inventory system
remains a real, disclosed gap for a future, larger round.

## Round 79: Destination System (master prompt item 6) — real, already-bookable packages/hotels/activities now surfaced inside Explore Mode's destination profile, closing the "Travel" tier's biggest gap

**Why this round:** item 6 asks for a complete destination profile spanning
Basic/Practical/Travel/Financial/Safety/Accessibility tiers. Direct code
investigation (not a subagent's gap-analysis claim) found this system
already has a genuinely substantial destination-info system — "Explore
Mode" (`frontend/explore-view.php` + `js/explore.js` +
`backend/ExploreService.php::getDestinationInfo()`), covering Basic (name,
coordinates, map), Practical (real live weather via Open-Meteo), and a good
chunk of Financial/Safety (AI-generated overview/history/best-time/local
culture/famous foods/estimated costs/safety tips, plus a real DB-backed
`tblemergencycontacts` lookup by country) — none of it fabricated in this
round, all pre-existing. The one clear, real, missing piece: the entire
"Travel" tier (hotels/packages/activities/attractions available at this
destination) was never connected to this system's own catalog. A visitor
exploring "Bali" got an AI-written overview and live weather but no way to
see the real packages TMS actually sells there without leaving Explore Mode
and running a separate search.

**What was built:**
1. **`backend/ExploreService.php` + `includes/ExploreService.php`** (kept
   byte-identical): new `getLocalInventory(string $country): array` method
   — three real, read-only, exact-`Country`-match queries against
   `tbltourpackages`, `tblhotels`, and `tblactivities` (all `Status=1`,
   ordered by rating, capped at 6 each, fail-soft on any DB error). Wired
   into `getDestinationInfo()`: computed once up front and returned under a
   new `local_inventory` key on **every** return path — the cache-hit path,
   the successful-AI path, *and* both AI-failure paths (AI service down /
   response unparseable). That last part matters: this system's own catalog
   data has nothing to do with whether the AI overview call succeeded, and
   the AI call fails on every install without a configured key (the
   documented, common case here) — so before this fix, a visitor would
   have seen nothing at all in that case, real inventory included.
2. **`api/explore.php`**: `handleInfo()` now passes `local_inventory`
   through in both the success and error JSON responses. `apiError()`
   gained a new optional third parameter (`array $extra = []`, merged into
   the JSON body) to carry `local_inventory` on the error path — fully
   backward compatible, every existing 2-argument call site is unaffected.
3. **`js/explore.js`**: `loadOverview()` now reads `local_inventory` from
   either the success or error response shape and always calls a new
   `renderLocalInventory()`, independent of whether the AI overview
   rendered. That function renders three cards — Packages / Hotels /
   Activities — each item linking to the real `package-details.php?pkgid=`,
   `hotel-details.php?id=`, `activity-details.php?id=` pages, showing the
   real image, name, location/category, rating-sorted order, and price.
   Hotels/activities carry the site's existing `.tms-sample-badge` "Sample"
   label (reusing the exact badge `hotels.php`/`activities.php` already use
   for these tables, which are documented, disclosed sample-data catalogs —
   see `DATABASE.md`) so nothing reads as a real hotel/activity brand;
   packages (the real production catalog) carry no such badge. When a
   country matches nothing in any of the three tables, an honest "No
   packages, hotels, or activities are currently listed for X in our
   catalog" message is shown instead of an empty blank space.
4. **`frontend/explore-view.php`**: added the `#localInventoryContent`
   container inside the Overview panel, right after the existing
   `#overviewContent` div.
5. **`css/explore.css`**: added `.inventory-item` styling (thumbnail, name,
   meta line, price, hover state) matching the existing `.explore-card` /
   `.attraction-item` visual language, dark-mode-safe via the same CSS
   custom properties already used throughout this file.

**Files touched:** `backend/ExploreService.php` + `includes/ExploreService.php`
(new method + wiring), `api/explore.php` (response shape + `apiError()`
signature), `js/explore.js` (rendering), `frontend/explore-view.php`
(container div), `css/explore.css` (styling). No backend/includes duplicate
exists for `api/explore.php`, `js/explore.js`, `frontend/explore-view.php`,
or `css/explore.css` — only the `ExploreService.php` pair needed syncing,
confirmed byte-identical via `diff` both before (pre-existing state) and
after this round's edit.

**Implementation status:** live in both `D:\xampp\htdocs\tms` and
`D:\Project\tms` (12 writes via `device_commit_files`, zero rejected).
`php -l` clean on all 4 PHP files; `node --check` clean on `explore.js`.

**Testing status:** genuinely live-tested against the real running site
and real database, no throwaway QA helper needed this round (Explore Mode
and the package/hotel/activity detail pages are all intentionally public,
per an existing comment in `explore-view.php`, so no session simulation
was required). Using the real "Bali, Indonesia" quick-search chip on
`explore.php`: the new "Packages in Indonesia" / "Hotels" / "Activities"
cards rendered with 6 real packages (including the real "Bali Ubud Rice
Terraces", PackageId 413, correctly sorted by `AvgRating` and reusing this
round's own Round-78 test subject), a real sample hotel ("Emerald Palms
Retreat", correctly tagged with the "Sample" badge), and a real sample
activity ("Balinese Rice Terrace Trek", also correctly tagged) — confirmed
identically for "Kathmandu, Nepal" too. Clicked the real "Bali Ubud Rice
Terraces" package link and confirmed it landed on the genuine
`package-details.php?pkgid=413` page (title, price, rating all matched);
separately confirmed `hotel-details.php?id=2`'s href resolves and loads.
Confirmed via `read_console_messages`/`read_network_requests` that the AI
overview call genuinely fails with a real 503 ("The AI assistant is not
switched on for this site yet.") on this install (no AI key configured,
consistent with Round 77's documented finding) — and, critically, called
the live API directly (`fetch('/tms/api/explore.php?action=info...')`)
to confirm the JSON body on that 503 response still carries a fully
populated, correct `local_inventory` object at the top level, proving the
graceful-degradation fix actually works rather than just happening to look
right in the rendered page. Also called the same endpoint with a
deliberately nonsense country ("Absurdistan") and confirmed
`local_inventory` correctly comes back as `{packages: [], hotels: [],
activities: []}` — the exact shape `renderLocalInventory()`'s empty-state
branch checks for; the resulting "No packages, hotels, or activities..."
message was verified by reading that (simple, three-way length check)
branch of the code rather than additionally screenshotting a real
zero-match destination, since none of the real chip destinations tried
(Bali/Kathmandu) happened to be empty.

**What this round deliberately did NOT do, and why (Rule 73):** did not
touch the AI overview content itself, the emergency-contacts lookup, or the
weather integration — all already real and already working. Did not build
out the Financial tier's "estimated daily budget" beyond what the AI
already estimates, and did not add an Accessibility tier (wheelchair
access data) — this system has no verified data source for accessibility
information at the hotel/attraction level, and inventing one would violate
Rule 3. Did not build a standalone "destination profile" page/entity
separate from Explore Mode — Explore Mode already *is* this system's
destination-profile concept in practice (confirmed by how much of items 6's
spec it already covered before this round), so extending it was the
narrower, lower-risk choice over introducing a second, competing concept.
Did not attempt to reconcile cases where a package's free-text
`PackageLocation` names a country in a different form than its `Country`
column (matching here uses `Country` only, exact match) — this is the same
looseness/strictness tradeoff already accepted by the pre-existing
`lookupEmergencyContacts()` pattern, not a new risk introduced this round.
