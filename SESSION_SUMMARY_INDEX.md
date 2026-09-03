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
- Document expiry reminders in the Travel Wallet (structured date parsing
  not built — `Notes` field supports it manually today)
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
- A real payment gateway — this app has no payment capture at all (booking
  confirmation is a manual admin action); refund tracking added this round
  is honest about that rather than faking an auto-refund of money that was
  never actually captured through the app
- API rate limiting and refresh tokens — no usage data yet to size limits
  against, and no known mobile client yet that needs a refresh flow

## If something doesn't work

Every new feature was built to fail soft — a broken AI call, missing data,
or unreachable external API (OpenSky, Open-Meteo, Nominatim) should degrade
gracefully rather than crash a page. If you hit an actual PHP error (not a
graceful "no data" message), check `error_log` first — every new code path
logs specifics there rather than displaying raw errors to users.
