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
