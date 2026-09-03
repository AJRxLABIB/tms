# UI Pass: Glassmorphism + Theme Coverage Fixes

Scope of this pass, done properly rather than promised broadly: fix the real
gaps in the existing "Boarding Pass" design system (`css/modern-theme.css`)
rather than re-skin the whole site in one unverifiable sweep.

## What was actually wrong (verified in code, not assumed)

1. **3 real pages were silently stuck on the legacy Bootstrap-only look:**
   `change-password.php`, `forgot-password.php`, `ticket.php` never linked
   `css/modern-theme.css`. Worse than just "looking dated" — their own
   inline `<style>` blocks reference `var(--secondary)` / `var(--primary)`,
   which are *defined inside* `modern-theme.css`'s `:root`. Without that
   file loaded, those CSS variables were undefined, so `.btn-recover`
   (forgot-password) and the ticket header/print button were rendering with
   **no background at all** — not just unstyled, actually broken.
   (`sitemap.php` was also flagged by the earlier grep but is XML output,
   not a page — correctly excluded.)

2. **No real glassmorphism anywhere**, despite the reviewer note asking for
   it — the existing navbar/hero-search were solid white, just with shadows
   and border-radius.

## What was fixed

- `change-password.php`, `forgot-password.php`, `ticket.php`: added the
  missing `modern-theme.css` + font links. Replaced the old flat
  `.banner-1` page header with a proper hero gradient + frosted-glass card
  for the form itself (`backdrop-filter: blur(18px)`), consistent with the
  rest of the account pages.
- `css/modern-theme.css`: added a real glassmorphism module —
  - Sticky navbar now genuinely frosts (`blur(16px) saturate(160%)`) once
    scrolled, instead of just gaining a heavier shadow. Wired to the
    existing `.scrolled` class toggle in `includes/header.php` — no new JS
    needed, that listener already existed.
  - Hero search bar is a true frosted panel over the hero photo, not flat
    white.
  - Package-card wishlist button is now a glass chip.
  - Added `.tms-pkg-quickglance`: a glass strip that slides up over the
    photo on hover showing duration + rating — deliberately *not* a
    duplicate of the price (which already lives in the card footer), so
    hovering surfaces new information instead of clutter.
- `index.php`: wired the new quick-glance strip into the homepage package
  cards.

## What I checked and left alone (already solid, don't fix what isn't broken)

- The package-listing page (`package-list.php`) already had a
  deliberately-designed "ticket stub" card treatment (`.rom-btm`, with a
  rotated brass postmark badge) from a prior round — this is good, on-brand
  design work, not a generic Bootstrap row. Left as-is rather than replaced
  with something generic.
- The hero section (search bar, stats counters, promo strip) was already a
  genuinely custom build, not a template — the reviewer's "still feels like
  Bootstrap" note is understandable given the *inconsistent* coverage above,
  but the underlying design system itself was already good.

## Round 2 — orphaned/neglected pages

Checked every customer-facing `.php` file site-wide (not just the ones
already flagged) for `modern-theme.css` coverage, including cross-checking
whether each "missing" page is actually still reachable in the current user
flow before touching it — no point polishing dead code.

- **`thankyou.php`**: confirmed via grep that **nothing in the codebase
  links or redirects to this file anymore** — `checkout.php` redirects to
  `tour-history.php` (already fully themed) after a real booking. This page
  is orphaned template leftover, not a live gap. Left untouched rather than
  spending effort on unreachable code.
- **`issuetickets.php`** (My Support Tickets — real, reachable page, linked
  from `dashboard.php` and site nav): this one *was* a real gap — raw
  `<table border="1">` HTML, generic copy-paste hero text ("TMS-Tourism
  Management System" instead of anything about tickets), no status
  indication. Rebuilt as a themed glass-panel list: each ticket now shows a
  status pill (Answered / Awaiting Response, derived from whether
  `AdminRemark` is set — no schema change needed), the admin's reply in a
  highlighted callout, and a proper empty state. Note: ticket *creation*
  was already handled correctly by the separate `includes/write-us.php`
  modal (confirmed by tracing the `INSERT INTO tblissues` call) — this page
  only ever displayed history, so no functional gap there, just a visual
  one.
- **Admin panel** (`admin/*.php`): initially flagged as "missing
  modern-theme.css" by a blanket grep, but checked `admin/css/admin-v6.css`
  directly — it's a genuinely separate, well-built dark-sidebar admin
  design system (own color tokens, card system, etc.), not raw Bootstrap.
  Customer-facing and admin panels intentionally use different visual
  languages, same as most real products (e.g. Airbnb's guest site vs. host
  dashboard don't share a theme either). Not a gap — left alone.

## Round 3 — modern booking steps (the reviewer's specific #8 ask)

Traced the real booking flow before designing anything: `cart.php` →
`checkout.php` → (on submit) → `tour-history.php`. There is no separate
payment step in this app (confirmed in `SESSION_SUMMARY_INDEX.md` — booking
confirmation is a manual admin action, no payment gateway exists), so the
step indicator reflects the flow that's actually there — Cart → Checkout →
Confirmed — rather than inventing a "Payment" step that doesn't exist.

- Added a reusable `.tms-steps` component to `modern-theme.css`: a 3-node
  progress indicator with connecting line, `.done` (checkmark, teal) and
  `.active` (accent-colored, glowing ring) states, plus a `.light` variant
  for use on white backgrounds later if needed.
- `cart.php`: step indicator in the page-title band, step 1 active.
- `checkout.php`: step indicator, step 1 done / step 2 active.
- `tour-history.php`: replaced the flat legacy `.banner-1` header with the
  glass `.tms-page-hero`, and — only when the user has just completed a
  checkout (`$_SESSION['checkout_success']` is set, not on every visit) —
  shows all 3 steps as done plus a proper celebration card instead of a
  plain "SUCCESS:" text line.

## Round 4 — package comparison depth (reviewer's #4 ask)

`compare.php` already existed and worked (add/remove up to 3 packages,
session-based) but only compared Name/Type/Location/Price/Description —
much shallower than the reviewer's "Days, Hotels, Food, Transport, Ratings,
Weather, Distance" list. Checked the real schema
(`_tms_repair_schema_drift()` in `includes/config.php`) before adding
anything: **Hotels/Food/Transport aren't modeled as separate data anywhere**
in this app (same known gap noted in `SESSION_SUMMARY_INDEX.md` — packages
don't have a hotel relationship yet), so faking those fields would be
exactly the placeholder behavior called out as the problem with sloppy AI
output. Added only what's honestly backed by real data:

- **Duration** and **Rating** (star display, "No reviews yet" when
  `AvgRating` is 0 — not hidden, not faked).
- **Highlights** (the existing `PackageFetures` column, previously only
  shown in the plain package-list rows).
- **Live current weather** — reused the exact `tms_get_weather()` helper
  already proven on `weather.php`/`package-details.php` (OpenWeatherMap if
  a key is configured, otherwise the free Open-Meteo fallback — never
  simulated). Only shown for packages that actually have `MapLat`/`MapLng`
  set; packages without coordinates honestly show "Live weather not
  available for this destination yet" rather than a guess.
- Restyled the whole table from raw HTML borders to match the glass design
  system (rounded corners, gradient header, hover row highlight).

## Round 5 — inline interactive map + nearby essentials (reviewer's #3 ask)

The reviewer specifically wanted "Live Route, Hotel Locations, Attractions,
Restaurants, Nearby Hospitals, Nearby Police Stations" — this already
existed as real, working infrastructure (`ExploreService::getNearbyPlaces()`
— Overpass/OpenStreetMap, or Google Places if a key is configured — plus a
public endpoint at `api/explore.php?action=places`), but it was only
reachable from **Explore Mode**, a separate free-roam feature. A package's
own details page only had a "View on Map" link that navigated away to
`tour-map.php` with no nearby-places data at all.

- Added a **"Nearby Essentials" widget directly on `package-details.php`**:
  a real embedded Leaflet map centered on the package's destination, plus
  category tabs (Hotels / Restaurants / Attractions / Hospitals / Police)
  that call the *existing* `api/explore.php?action=places` endpoint — no
  new backend code needed, this reuses infrastructure already proven and
  cached elsewhere in the app.
- Each result shows name, address (when OSM/Google has it), and live
  distance from the destination; markers drop onto the map per category.
- Only rendered for packages with `MapLat`/`MapLng` set — no fake pins for
  packages without coordinates.
- Left the original "View on Map" link in place as a secondary option for
  the full-page `tour-map.php` view — this widget is a quick-glance
  addition, not a replacement.

## Round 6 — map view on the package listing page

Round 5 added an inline map to a single package's details page. The
listing/grid page (`package-list.php`) — where people actually browse many
packages at once — still had no spatial view at all, so this closes that
gap directly per the "do all in order" instruction.

- Added a **List / Map toggle** next to the results count on
  `package-list.php`.
- Map view renders a real Leaflet map with a pin for every package **on
  the current page** that has `MapLat`/`MapLng` set (uses the existing
  `SELECT *` query — no extra DB call needed), auto-fits bounds to the
  visible pins, and each pin's popup links straight to that package's
  details page with its live price.
- If none of the packages on the current page/filter have coordinates set,
  the map view says so honestly instead of rendering an empty map.
- Verified the injected JS is syntactically valid by substituting real JSON
  for the PHP-rendered `TMS_MAP_POINTS` value and parsing it with Node
  (the raw template naturally fails a naive parse because of the literal
  `<?php ... ?>` tag — confirmed that's not a real bug, just an artifact of
  testing unrendered PHP).

## Round 7 — sitewide modal restyle (highest-leverage fix in this pass)

Investigated the "~60 files use BS3" gap directly rather than starting a
blind file-by-file migration. Findings, checked with grep, not assumed:

- Only **5 pages** actually use BS3-specific dialog markup
  (`budget-planner.php`, `custom-trip.php`, `forgot-password.php`,
  `package-details.php`, `reviews.php`) — plus two **global includes** used
  on nearly every page: `includes/write-us.php` and `includes/signin.php`.
- Bootstrap 3's own JS (`bootstrap.min.js`) is what's still loaded
  sitewide, and it correctly drives every modal's open/close/backdrop
  behavior — so these were never actually *broken*, just visually stuck on
  BS3's flat gray default look while the rest of the site moved on.
- **No modal CSS override existed anywhere** — meaning literally every
  modal on the site (write-us, sign-in, and all 5 page-specific dialogs)
  was unstyled BS3 default.

Added one CSS module to `modern-theme.css` — gradient glass header, frosted
blurred body, rounded corners, styled close button and form inputs — which
covers **every modal across the entire site** in a single change, since
`.modal`/`.modal-content` markup is shared. This is disproportionately
higher-leverage than the file count suggests, because two of the affected
files (`write-us.php`, `signin.php`) render on nearly every page.

Also did a final full sweep: confirmed the remaining pages without
`modern-theme.css` (`apply-coupon.php`, `check_availability.php`,
`coupon-check.php`, `logout.php`, `mark-notifications-read.php`,
`price-preview.php`, `user-register-ajax.php`, `wallet-document.php`) are
all pure AJAX/action endpoints with no HTML output — **theme coverage is
now complete across every real page on the site**, not just the ones
flagged in earlier rounds.

## Round 8 — found and fixed a real functional bug, then built Recently Viewed

While tracing the wishlist button to model the new feature on it, found a
genuine, previously-undiscovered bug rather than one I went looking for:

**Bug:** the homepage's `addToWishlist()` JS (`index.php`) POSTed
`action=add&pkgid=X` to `wishlist.php` — but `wishlist.php` only ever
checks for a `toggle_wish` POST key, and (as of the CSRF hardening pass
documented in `includes/config.php`) now hard-rejects any POST missing a
valid `csrf_token` with an HTTP 403, which this call never sent. Every
click on the homepage's wishlist heart was silently 403'd — while the JS
`onload` handler repainted the heart red regardless of the response status,
so **it looked like it worked and never actually saved anything.** The
same button on `package-list.php` was unaffected — it uses a real
CSRF-protected `<form>` POST, not this broken AJAX path.

**Fix:** corrected the POST field name, added the CSRF token (now exposed
to the page's JS via `TMS_CSRF_TOKEN`), and made the response handler
actually check `xhr.status` instead of assuming success.

**Then, Recently Viewed** (reviewer's #17 ask) — genuinely not built
anywhere before this:
- New `tblrecentlyviewed` table added via the same self-healing migration
  pattern already used for `tblwishlist` in `includes/config.php` — safe
  for existing installs, no manual SQL needed.
- `tms_track_recently_viewed()`: records a view on `package-details.php`,
  scoped to **signed-in users only** — deliberately not cookie/IP based,
  since that would leak browsing history across shared devices. Bumps the
  timestamp on repeat views (via `ON DUPLICATE KEY UPDATE`) instead of
  creating duplicate rows, and trims each user to their most recent 12.
- A horizontal "Recently Viewed" strip now shows at the bottom of
  `package-details.php`, excluding the package currently being viewed,
  using the same `tms_package_image_src()`/`tms_currency()` helpers as the
  rest of the app for consistency.

## Round 9 — Smart Search quick filters (reviewer's #18 ask) + a bug audit

Before building anything new, audited every other AJAX POST call in the app
for the same bug class found in Round 8 (wrong field name / missing CSRF).
Checked all remaining files with raw `fetch`/`XMLHttpRequest` POSTs
(`ai-copilot.php`, `ai-packages.php`, `ai-planner.php`, `flight-status.php`,
`packing-assistant.php`, `travel-memory.php`, `virtual-guide.php`) — all of
them post to `api/ai.php`, which never calls `tms_csrf_validate()` at all
(a deliberate design choice for that JSON API, not an oversight), so none
of them have the bug. No further instances found.

**Smart Search**: the reviewer wanted quick tag filters ("Cheap", "Family",
"Under 30,000", "Luxury", "Weekend"). Checked the real seed data
(`tms.sql`) before choosing categories — the actual `PackageType` values in
use are Family/Couple/Group/Domestic, and duration data is often unset for
older rows, so a fabricated "Weekend Getaway" tag would silently return
zero results for most of the catalog. Built quick-filter chips backed only
by real, always-present columns instead:

- **Budget-Friendly / Premium** — computed against this catalog's *live*
  average price (`AVG(PackagePrice)`), not a guessed fixed number, so the
  split stays meaningful as prices change.
- **Family / Couple / Group** — real `PackageType` matches.
- **Highly Rated** — `AvgRating >= 4`.
- Chips carry through pagination and combine with the existing
  search/type/sort filters (`pageUrl()` already forwards the full
  `$_GET`, so no extra wiring needed there).

## Round 10 — Notification Center: wired up the missing trigger events

The reviewer's #15 ask was "Booking confirmed, Wallet updated, Review
received, New offer, Referral bonus" notifications. The **infrastructure**
already existed and worked — bell dropdown in `includes/header.php`,
`tbl_notifications` table, `tms_notify()` helper, `mark-notifications-read.php`
— but checking every call site of `tms_notify()` showed only 2 of the 5
event types were actually wired: Referral bonus (`register.php`,
`user-register-ajax.php`) and booking status changes
(`admin/manage-bookings.php`). Wallet, Review, and Offer events never
called it, so those notifications simply never fired — the UI to show them
worked perfectly, there was just nothing to show.

Also clarified something the reviewer's list conflated: this app's
"Wallet" (`wallet.php`) is a **Digital Travel Wallet for documents**
(passport/visa/ticket uploads) — there's no monetary balance wallet
anywhere in the codebase (checked — no `WalletBalance` column, no top-up
flow exists). So "Wallet updated" is wired to document uploads, which is
the real feature that exists, not a feature that doesn't.

- `wallet.php`: notifies on a successful document upload.
- `reviews.php`: notifies on review submission, distinguishing "posted"
  vs. "awaiting approval" for photo reviews that need moderation.
- `admin/manage-coupons.php`: notifies **every registered user** when a
  new coupon is published — added a `tms_notify_all()` helper (single
  `INSERT...SELECT`, not a PHP loop per user, so it stays cheap as the user
  base grows) since no broadcast mechanism existed before.

## Round 11 — checked 3 more reviewer items, built the one that was missing

Checked "QR Ticket", "Digital Boarding Pass", and "Emergency Assistance"
before building anything — all three already exist and are solid:
`ticket.php` (already re-themed in Round 1) generates a real client-side
QR code encoding the booking's actual verification code — no third-party
QR service ever sees the booking data — with a proper boarding-pass-stub
layout. `emergency.php` is a complete, themed safety/emergency-contacts
page. Nothing to do there.

**Currency Converter** (reviewer's #21 ask) — genuinely didn't exist
anywhere. Built it following the exact pattern already proven by the
weather feature rather than inventing a new approach:

- `tms_get_fx_rates()` in `includes/config.php` fetches live BDT-based
  rates from `open.er-api.com` (free, no API key, 166 currencies, daily
  updates) using the same `_tms_http_get()` cURL wrapper and the same
  `CacheService` the weather integration already uses — cached 12h, so
  normal traffic never re-hits the API. Returns `['ok'=>false]` on any
  failure, and the widget simply doesn't render rather than showing a
  broken converter.
- Added a "See price in…" dropdown on `package-details.php`'s price box
  (10 common currencies) showing the converted estimate, with clear
  attribution to exchangerate-api.com per their free-tier terms.
- Wired it to the *existing* dynamic-price-preview JS (date/traveler-count
  changes) via a `data-bdt` attribute + `MutationObserver`, so picking new
  dates updates the converted price automatically instead of showing a
  stale conversion.
- Verified the injected JS parses correctly by substituting real JSON for
  the PHP-rendered rates and running it through Node, same check used in
  Round 6.

Note: this integration is untestable from my sandbox (no outbound network
access here), so while it's built correctly against the documented API
contract, it's worth a quick live check after deploying.

## Round 12 — verified more items, built the Travel Heat Map

Checked "Live Route" (reviewer's map wishlist) before assuming it was
missing: `tour-map.php` already does real point-to-point routing via OSRM
(driving/cycling/walking, actual road geometry, not straight lines) —
that item was already done, just not documented as such earlier in this
pass. Also checked `virtual-guide.php` for the "Virtual Tour / 360° / VR"
ask — confirmed it's a genuinely text/AI-based destination guide with no
panoramic imagery anywhere, so that one stays a real, sized gap (would
need actual 360° photo assets per destination, which don't exist in this
catalog — not something to fake).

**Travel Heat Map** ("stand out" feature #4) — `admin/popular-destinations.php`
already existed with 3 solid real-data signals (Most Explored, Most
Wishlisted, Most Booked) but was table-only, no map, despite the reviewer
specifically asking for an interactive map view. Added one:

- Extended the existing "Most Booked" query to also pull each
  destination's representative coordinates (`AVG(MapLat)`/`AVG(MapLng)`
  across that destination's packages).
- New Leaflet bubble map: circle size and opacity scale with real
  confirmed-booking counts (relative to the busiest destination, not a
  fixed guess), popup shows the exact count. Only destinations with
  coordinates set are plotted — no estimated/fake pins for the rest,
  matching the same honesty rule used for every map feature in this pass.
- Verified the injected JS parses correctly with substituted test data,
  same check used in every round that adds inline JS.

## Round 13 — AI Image Analyzer (reviewer's #11 ask), built for real

This was one of the bigger genuine gaps left — no vision capability existed
anywhere in the app. Checked what's actually available before building:
`includes/ai/AIClient.php` already wraps Gemini 2.5 Flash, which is
natively multimodal — no separate vision API/model/key needed, just a
different payload shape.

- **`AIClient::askWithImage()`** — new method, reuses the exact same
  rate-limiting, retry/backoff, and usage-logging infrastructure as the
  existing `call()`, just adds an `inline_data` image part to the Gemini
  payload. Lower temperature (0.4 vs 0.7) since this is classification, not
  creative writing.
- **`api/ai.php?action=image_analyze`** — validates the upload with the
  *exact* two-layer check already proven in `wallet.php` (extension
  allow-list AND real `finfo` content-type sniffing, so a renamed non-image
  file can't reach the AI call), sends it to Gemini for scene
  classification (beach/mountain/forest/city/snow/desert/historical/adventure),
  then matches the result against **real catalog data** — `PackageType`,
  `PackageFetures`, `PackageLocation`, `PackageName`, `PackageDetails` — via
  keyword search, not a fixed scene→package-ID table that would go stale as
  packages are added/removed. Honestly reports "no matches yet" when the
  catalog has nothing for that scene, rather than padding results.
- **`image-analyzer.php`** — new page: drag-and-drop upload, live preview,
  scene banner with confidence level, results grid linking straight to
  matching packages. Linked from the AI hub sidebar (`ai-copilot.php`) and
  added to `sitemap.php` — not left orphaned like `thankyou.php` was found
  to be in Round 2.
- Verified: brace balance on both PHP files, and the page's JS parses
  clean via `node -c`.

Same caveat as the Currency Converter (Round 11): this calls a live external
API (Google's Gemini), which I can't hit from this sandbox — built correctly
against Gemini's documented multimodal request format, but worth a live
smoke test (upload a real beach photo, confirm it comes back "beach") after
deploying with a real `GEMINI_API_KEY`.

## Round 14 — Voice Search on the actual search bar (reviewer's #12 ask)

The reviewer's exact example was: *"Find me Cox's Bazar packages under
20,000 taka — speak it."* Checked what existed first: voice input was only
wired into the Trip Copilot chat (`ai-copilot.php`), not the site's actual
package search — so saying a destination-plus-budget query anywhere else
did nothing.

- Added a mic button to the homepage hero search, reusing the exact same
  browser-native Web Speech API pattern already proven in
  `ai-copilot.php` (100% client-side, no server cost, hides itself
  automatically on browsers without support like desktop Firefox).
- Added **honest, simple regex parsing** for a spoken budget — "under
  20,000 taka", "below 15000 tk", "less than 5000 BDT" — extracts the
  number and strips filler words ("find me", "show me", "please") from the
  destination, rather than pretending to run full NLU. This is exactly
  the reviewer's literal example, tested against that phrasing pattern.
- **`package-list.php` didn't actually have a max-price filter at all**
  before this — only the "Budget-Friendly"/"Premium" quick filters from
  Round 9, which compare against catalog average, not a specific number.
  Added a real `max_price` GET parameter so the voice-parsed budget (or
  anyone typing a URL) actually filters results, not just a cosmetic
  addition for voice search alone.
- Verified the new JS in isolation with `node -c` (the full script block
  can't be parsed standalone because of pre-existing inline `<?php ?>`
  branches elsewhere in the same tag — confirmed that's original code, not
  something introduced here — so checked the new function block on its
  own).

## Round 15 — AI Review Summarizer was built but never shown to customers

Checked where `SentimentAnalyser.php` (the review sentiment/highlight
engine — reviewer ask #14) was actually used: only
`admin/ai-dashboard.php`. The reviewer's ask was explicitly customer-facing
("instead of showing hundreds of reviews, AI summarizes...") — a browsing
customer deciding whether to book never saw any of this, only admins did.

- Added `SentimentAnalyser::getPackageHighlights()` — the existing
  site-wide `getTopIssuesAndHighlights()` phrase-aggregation logic, scoped
  to one package via a JOIN on `PackageId`, since nothing exposed a
  per-package version before.
- Added an "AI Review Summary" card to `package-details.php`: a
  positive-sentiment bar, "People loved" phrase chips, and "Worth knowing"
  phrase chips — directly matching the reviewer's requested format
  (short phrases, not paragraphs).
- **Deliberately gated behind a minimum sample size** (3+ reviews with
  analyzed sentiment) — a "summary" of 1-2 reviews isn't a summary, it's
  just restating them with extra visual confidence, so the card stays
  hidden below that threshold rather than showing something misleading.
- Noted but did not change: sentiment analysis only actually runs when an
  admin opens `admin/ai-dashboard.php` (it lazily batch-processes 20
  unanalyzed reviews per visit). That means a brand-new review may not
  have a summary yet until that next happens — worth turning into a proper
  queue/cron trigger at some point, but that's a pipeline-timing decision
  for you to make, not something to silently change as part of a UI pass.

## Round 16 — Admin AI Insights: the 4 specific predictive metrics were missing

Checked `admin/ai-dashboard.php` against the reviewer's exact wording
("Predicted revenue, Most likely next bookings, Packages at risk of low
occupancy, Customers likely to return, Customers likely to cancel") —
the dashboard already had real sentiment analysis, fraud detection, and an
AI-generated text summary, but none of these 4 specific predictive metrics
existed anywhere.

Built them as **transparent, auditable heuristics over real booking data**,
not an opaque ML score nobody (including you) could explain to a
stakeholder if asked "why does it say that":

- **Predicted revenue**: a moving-average trend over the last 3 full
  months of confirmed bookings, projected across the days remaining in the
  current month. Explicitly labeled as a trend estimate, not a guarantee,
  and honestly shows "not enough history yet" instead of a number when
  there's under 2 months of data.
- **Packages at risk**: packages with 2+ lifetime confirmed bookings (so
  they're proven, not brand-new) but zero bookings in the last 30 days —
  flags genuinely-gone-quiet packages without punishing new listings that
  haven't had a chance yet.
- **Cancellation risk**: customers with a 30%+ cancellation rate across at
  least 2 bookings — a real historical pattern from `tblbooking.status`,
  not a guess about intent.
- **Likely to return**: customers with 2+ confirmed bookings, most recent
  within the last 6 months — active repeat customers, not one-time
  visitors from years ago.

All 4 render in a new "Business Forecasts" panel using the dashboard's
existing `.panel` component styling, so it looks native rather than
bolted-on. Skipped "Most likely next bookings" specifically — a genuine
next-booking prediction needs either a trained recommender (the `TravelRecommender.php` module exists but predicts for a *given* user, not
"who's about to book" platform-wide) or enough interaction data to build
one honestly; faking it with a plausible-looking list wasn't worth doing.

## Round 17 — Dynamic Pricing Engine: checked against the reviewer's exact factor list

The reviewer wanted pricing driven by "Remaining seats, Season, Demand,
Holidays, Weather, Booking date — exactly like airline pricing."
`includes/PricingEngine.php` already existed and handled 2 of these
honestly (Demand — real recent-booking counts; Booking date — real
lead-time-to-departure) and was already wired end-to-end into every price
display (`price-preview.php`, `package-details.php`, `checkout.php`,
`compare.php` from Round 4) — no changes needed there.

Added a 3rd real factor: **weekend departure** (Thu/Fri/Sat — the
Bangladesh weekend is Fri–Sat, so Thursday is weekend-adjacent travel)
gets a modest +5%, the same leisure-demand pattern hotels/airlines already
price for. Pure date math, no extra DB query, so it stays cheap even
rendering many cards on a listing page. Because the price *label* (the
human-readable reason shown next to the price) was already wired through
`price-preview.php` → `package-details.php`'s pricing badge, this new
factor shows up automatically everywhere the price already displays — no
UI changes needed.

**Deliberately did not add** the other 3 requested factors, and documented
exactly why directly in the code so a future reader (or you) doesn't
wonder if it was an oversight:
- **Remaining seats** — there's no capacity/seat-limit column anywhere in
  this schema; packages don't model a maximum at all. Faking a seat count
  to price against would be inventing data.
- **Weather** — forecasts are only reliable ~16 days out, but most
  bookings happen further ahead than that, so a "weather-based" price
  would be silently wrong for most departures — worse than the signal not
  existing.
- **Fixed holiday calendar** — would need a maintained, year-by-year list
  of lunar-calendar holidays (Eid, Puja, etc.) that would silently go
  stale without upkeep. The weekend-departure signal captures most of the
  same leisure-demand pattern without that maintenance burden.

## Round 18 — Sustainability Calculator: closed the "carbon offset" gap, caught a real JS bug while doing it

Checked `SustainabilityCalculator.php` against the reviewer's exact
"stand out" ask ("Suggest Greener transport, Eco hotels, Carbon offset
suggestions") before building anything:

- **Greener transport**: already fully implemented — `lowest_footprint_mode`
  picks the lowest-emission *realistic* option and the UI already tells the
  visitor to consider it. Nothing to do there.
- **Eco hotels**: genuinely can't be added honestly — hotels aren't modeled
  as their own entity anywhere in this schema (same gap already documented
  for the package-comparison table in Round 4). Not faking it.
- **Carbon offset suggestions**: was completely missing. Added it —
  estimated offset cost using a conservative, publicly-documented
  voluntary-offset price range (~$10–20/tonne CO2e) rather than a single
  falsely-precise number, plus a tree-equivalent figure. Explicitly
  informational, not a purchase flow: it points to real third-party
  verified registries (Gold Standard, Verra) rather than pretending this
  app can sell or verify an actual offset, since no payment/verification
  integration for that exists.

**While validating the new JS with the same node-syntax-check process used
in every prior round, actually caught a real bug this time** (rather than
just confirming things pass): a double-escaped apostrophe (`flight\\'s`
instead of `flight\'s`) in the new offset text would have thrown a JS
error in every visitor's browser the moment this code shipped, breaking
the whole sustainability box silently. Fixed and re-verified clean.

Caveat worth flagging: sustainability data is cached for 7 days per
destination (`api/ai.php`'s `handleSustainability()`), so already-cached
destinations won't show the new offset estimate until that cache
naturally expires — not something worth adding cache-busting complexity
for, but good to know if you check a destination and don't see it
immediately.

## Round 19 — found and fixed a serious pre-existing bug: coupon creation was 100% broken

While spot-checking `coupon-check.php` for the same class of bug found in
Round 8 (wrong param names / mismatched columns), traced it back to the
real `tblcoupons` schema (`includes/config.php`: `Code`, `DiscountPercent`,
`UserEmail`, `ExpiryDate`, `IsUsed`) and compared it against
`admin/manage-coupons.php`.

**The bug**: every query in `admin/manage-coupons.php` — the Add form's
INSERT, the Toggle action's UPDATE, and the listing's display — used
columns that **do not exist in the schema at all**: `CouponCode`,
`Discount`, `status`. This isn't a naming quirk that degrades gracefully;
the INSERT would throw a real SQL error (`Unknown column 'CouponCode'`)
every single time an admin tried to add a coupon. It's wrapped in a
try/catch, so the admin would just see "Error: SQLSTATE[42S22]..." instead
of a crash — **coupon creation has never worked**, in this codebase, at
all. This also means the "New offer" broadcast notification built in
Round 10 never had a chance to fire in practice, since the INSERT it
depends on always threw first.

Fixed all three: INSERT now uses the real columns; Toggle now flips the
real `IsUsed` column (repurposed as active/inactive — there's no separate
status concept in this schema, and "used" already meant "not available")
instead of a column that doesn't exist; the table display reads the real
`Code`/`DiscountPercent`/`ExpiryDate` fields instead of falling back to
`—`/`0%` for every row.

**Found a second, related bug while fixing the first**: coupons with no
expiry date set (which the admin form allows — expiry isn't required)
would then NEVER validate anywhere in the app, because SQL's
`ExpiryDate >= CURDATE()` is never true when `ExpiryDate` is `NULL` — a
"no expiry" coupon would silently reject every attempt to use it. Fixed
in all three places that run this check: `coupon-check.php` (the live
preview), the actual live application path inside
`package-details.php`'s booking handler, and `apply-coupon.php` (confirmed
still orphaned per the Round 2 note already in that file — fixed anyway
for consistency in case something starts calling it later).

This is the second real, previously-undiscovered functional bug found in
this pass (after the wishlist CSRF bug in Round 8) — both found by
tracing a feature end-to-end against its real schema rather than trusting
that existing code worked because it looked complete.

## Round 20 — systematic audit of every admin CRUD page, found 4 more real bugs

After finding the coupon bug in Round 19, did a full sweep: cross-checked
every `admin/manage-*.php` page's INSERT/UPDATE/SELECT statements against
the real table schemas (both the original `tms.sql` base tables and the
self-healing migrations in `includes/config.php`) rather than assuming the
coupon bug was a one-off. It wasn't — found 4 more, of varying severity:

1. **`admin/manage-emergency.php`** — targeted a table (`tblemergency`)
   that doesn't exist anywhere in the schema, with a completely different
   data model (flat contact list) than the real `tblemergencycontacts`
   table (per-country structured info) that the *live* customer-facing
   `emergency.php` page actually reads from. Admins had **zero way** to
   manage what customers see on that page. Rewritten from scratch against
   the real per-country schema (Country/Police/Ambulance/Fire/Helpline/
   Embassy/SafetyTips).

2. **`admin/manage-custom-trips.php`** — wrong table name entirely
   (`tblcustomtrip` singular vs. the real `tblcustomtrips` plural) plus
   wrong column names (`FullName`/`EmailId`/`Travelers`/`PostingDate`
   instead of `UserEmail`/`TravelersCount`/`CreatedAt`). The SELECT was
   silently swallowed by a try/catch, so this page has always shown "No
   custom trip requests yet" regardless of how many customers actually
   submitted one via `custom-trip.php` — admins had no visibility into
   this feature at all. Fixed table name and every column reference.

3. **`admin/manage-issues.php`** — referenced `Subject`/`Message` columns
   that don't exist (real: `Issue`/`Description`), so every row showed
   blank content; and its "Resolve" action only flipped a `status` column
   that the customer-facing `issuetickets.php` page (built in Round 2)
   **never reads** — so marking a ticket resolved had zero visible effect
   for the customer, and there was no way to actually reply to a ticket at
   all. Fixed the column references and **added a real reply form** that
   writes to `AdminRemark`/`AdminremarkDate` (what the customer page
   actually displays) and fires a notification via the `tms_notify()`
   infrastructure from Round 10.

4. **`admin/manage-pages.php`** (Terms/Privacy/About/Contact content) —
   referenced `PageTitle`/`PageSlug`/`UpdatedDate` columns that don't
   exist (real schema: just `type`/`detail`/`UpdatedAt`), and had **no
   edit capability whatsoever** — only list and delete, meaning there was
   no way to actually update the Terms of Service or Privacy Policy text
   through the admin panel at all. Fixed the column references and added
   an inline edit form.

5. **`admin/manage-enquiries.php`** — one smaller mismatch (`Message`
   instead of the real `Description` column) fixed for consistency; the
   rest of that page was already correct.

Verified brace/table/tr/form balance on all 5 files before packaging.
Deliberately did NOT touch `manage-reviews.php`, `manage-admins.php`,
`manage-translations.php`, `manage-users.php`, or `manage-packages.php` —
checked each against its real schema and confirmed they're already
correct, so no changes made there.

## Round 21 — CRITICAL: checkout was fatal-crashing after every successful booking

While spot-checking the loyalty points flow for the same bug class as
Round 19/20, found the most severe issue in this entire pass.

**The bug**: `tblloyalty_log` — referenced by INSERT/SELECT statements in
**8 different places** (`checkout.php`, `package-details.php`'s direct
booking path, `register.php`, `user-register-ajax.php`, `reviews.php`
[built in Round 15], plus read by `loyalty.php` and
`loyalty-history.php`) — was **never created anywhere** in the schema. No
`CREATE TABLE`, no `tms_ensure_columns` entry, nothing.

None of these INSERTs were wrapped in try/catch, and this app's PDO
connection is configured with `PDO::ERRMODE_EXCEPTION` (confirmed directly
in the PDO constructor in `includes/config.php`). That combination means:
every single completed checkout was hitting an **uncaught fatal PHP
error** on this INSERT — *after* the real booking row had already been
created and loyalty points already credited to `tblloyalty`, but *before*
the cart was cleared or the success page was shown. A customer completing
a real booking would land on a broken/blank page instead of the
confirmation screen, with their cart still full of items they'd already
booked — a genuine risk of confusing them into re-booking, on top of just
being broken. The same fatal-crash risk existed on referral signups
(`register.php`/`user-register-ajax.php`) and on review submissions
(`reviews.php`, from Round 15 of this very pass).

**Fixed two ways**:
1. Added the missing `tblloyalty_log` table to the self-healing migration
   list in `includes/config.php`, using the exact columns every call site
   already assumed (`UserEmail`, `Points`, `Description`).
2. **Defense in depth**: wrapped all 6 write call sites in try/catch, so a
   future schema hiccup (a failed migration, a permissions issue, a
   half-finished deploy) can never again take down checkout, registration,
   or review submission just because a non-critical activity-log insert
   failed. The booking/registration/review itself now always completes
   even if the log entry doesn't.

This is the most severe issue found across all 21 rounds — a core,
frequently-hit user flow (checkout) was silently broken for every
customer, not a missing feature or a cosmetic gap.

## Round 22 — systematic table audit, found 2 more missing tables (one more crash-class bug)

After Round 21's severity, did this properly instead of hoping it was the
only one: extracted every distinct table name referenced in an
INSERT/UPDATE/DELETE anywhere in the codebase, and cross-checked each
against every actual `CREATE TABLE` definition (both `tms.sql`'s base
schema and every migration in `includes/config.php`). Found 2 more
genuinely missing tables out of ~37 referenced (a few other candidates
turned out to be false positives — `tblcache` and the AI clients'
per-service caches are created inline by `CacheService.php`, not in the
central list, and `tblachievements`/`tblexpenses`/`tbltraveldocuments`
were just missed by an early regex, not actually missing).

1. **`tbl_ai_packages`** (`includes/ai/PackageGenerator.php`) — both the
   save and the read were already wrapped in try/catch, so this wasn't
   crashing anything, just silently discarding every AI-generated package
   the moment it was created. "My saved AI packages" was permanently
   empty. Added the table.

2. **`tblrecommendations`** (`ai-recommend.php`) — **no try/catch at
   all**, same crash class as Round 21: any logged-in user using the AI
   recommendation feature hit an uncaught fatal error the instant it tried
   to save their search preference, right after recommendations had
   already been computed and displayed on screen. Added the table and
   wrapped the insert defensively, same pattern as Round 21.

Both fixes follow the same two-part approach as the checkout bug: create
the missing table via the existing self-healing migration system (no
manual SQL required on deploy), and wrap the write in try/catch so a
future schema issue degrades gracefully instead of crashing a page that
had already done its real work.

## Round 23 — closed the loop: admins had no way to feed the map/duration features

While verifying the admin package forms weren't hiding another schema-name
bug (they weren't — `add-package.php`/`edit-package.php` use the correct
columns), noticed a real gap instead: **neither form exposed Duration or
Map Lat/Lng fields at all.** Every map feature built in this pass —
package-details' Nearby Essentials (Round 5), the listing page's map
toggle (Round 6), the admin heat map (Round 12) — and the Duration field
shown in package comparison (Round 4) all depend on `PackageDuration`/
`MapLat`/`MapLng` being set, but there was no way to actually set them
through the admin panel. Any package added or edited going forward would
simply never appear on any of those maps.

Added Duration and Map Coordinate fields to both `add-package.php` and
`edit-package.php`, wired into the existing INSERT/UPDATE statements
(nullable — admins can leave coordinates blank for a package without
known coordinates, same "no fake pins" rule followed everywhere else in
this pass), plus a quick link to a coordinate lookup tool since most
admins won't have lat/lng memorized.

## Round 24 — Hotels/Food/Transport comparison + Remaining Seats pricing (previously declined, now genuinely solvable)

Both were explicitly declined earlier in this pass for lacking schema
support. Rather than leave them declined, added the schema support itself
and then wired the features on top of it properly:

- Added `HotelName`, `HotelRating`, `MealPlan`, `TransportMode`, and
  `SeatsAvailable` to `tbltourpackages` via the same safe self-healing
  migration pattern used throughout this pass. `SeatsAvailable` is
  nullable by design — NULL means "not tracked," preserving every
  existing package's current unlimited-looking behavior exactly; only
  packages an admin explicitly sets a number for get scarcity pricing or
  seat-limit enforcement.
- `admin/add-package.php` and `admin/edit-package.php` now expose all 5
  fields, so this isn't a schema-only change — admins can actually use it.
- `compare.php`: Hotel (with star rating), Meal Plan, Transport, and a
  live Seats Available row now show real data when set, and honestly say
  "Not specified"/"Not tracked" when a package hasn't set them — never
  fabricated.
- `PricingEngine.php`: added a 4th real pricing factor — seat scarcity
  (+18% at ≤2 seats left, +10% at 80%+ filled, +4% at 50%+ filled),
  computed live from `SUM(NoOfPersons)` on confirmed/pending bookings via
  a new `tms_remaining_seats()` helper, never a stored counter that could
  drift out of sync.
- `package-details.php`: an urgency badge ("Only 2 seats left!") next to
  the price, only for packages that track capacity.
- **Actually enforced, not just displayed**: added a live capacity check
  to both real booking paths — `checkout.php` (sums requested seats across
  the whole cart per package, in case the same package appears more than
  once) and `package-details.php`'s direct booking flow — blocking
  checkout with a clear message if the request exceeds what's actually
  left. Without this, the seat displays/pricing above would have been
  purely cosmetic and could have actively misled a customer into thinking
  a seat was reserved when it wasn't.

## Round 25 — real-time chat, built honestly (polling, not fake WebSockets)

Previously declined for lacking a persistent-connection server. Rather
than leave it declined, built a genuinely working version using the
approach that's actually honest for this stack: short-interval polling
(~3s), which is a standard, real way to deliver near-real-time chat on
plain PHP/Apache hosting — not a "real-time" label slapped on something
that doesn't work.

- Two new tables: `tblchat_messages` (the conversation) and
  `tblchat_threads` (per-thread typing/read-receipt state — genuinely
  tracked, not decorative).
- `api/chat.php`: poll / send / typing / mark_read / threads (admin-only)
  endpoints. One continuous thread per customer (not per-booking), since
  customers often have pre-sales questions before any booking exists.
- `includes/chat-widget.php`: a site-wide floating chat bubble for
  logged-in customers (positioned clear of the existing AI chatbot bubble
  so they don't overlap), with a real typing indicator and unread badge.
- `admin/live-chat.php`: thread list + conversation panel for the agency
  side, linked into the admin nav sidebar so it's reachable.

**Caught and fixed a real bug the same way as Round 18** — while
validating the admin panel's JS with the same node-syntax-check used
throughout this pass, found genuinely broken quote-escaping in a
string-built inline `onclick` handler that would have thrown a browser
syntax error the moment an admin tried to open any conversation. Fixed by
switching to `addEventListener` with a closure instead of building an
inline handler string — safer and avoids this entire class of escaping
bug rather than just fixing this one instance of it.

**Also caught a second bug before it shipped**: the "new message"
notification sent to customers linked to `live-chat.php` — the *admin*
page path, which would have bounced any customer who clicked it straight
into `tms_require_admin()`. Fixed to link to the homepage, where the
site-wide chat widget is always present.

## Round 26 — admin panel visual pass

The admin theme (`admin-v6.css`) was already a genuinely well-built,
separate design system (dark teal sidebar, clean cards, consistent
tokens) from a prior round — not a full redesign candidate, but it hadn't
received the glassmorphism treatment that was the centerpiece of this
whole pass on the customer side. Closed that gap specifically:

- Sticky topbar now genuinely frosts (`backdrop-filter: blur`) once the
  page scrolls, matching the customer navbar's Round 1 treatment — instead
  of just sitting flat white.
- Checked the actual layout CSS before wiring the scroll listener (rather
  than assuming): neither `.tms-content` nor `.tms-main` has
  `overflow-y` set, so this layout scrolls at the window level, not an
  inner container — the listener attaches to `window`, not a div that
  would never actually fire a scroll event.
- Checked for admin-side Bootstrap modals needing the same restyle as
  Round 7 — there are none; the admin panel uses inline expand/collapse
  patterns (like the reply boxes built in Rounds 20/25) instead of real
  modals, so nothing to do there.

**Bootstrap 3 grid classes** (`col-md-*` etc.) sitewide: reaffirming the
position from Round 7 rather than silently dropping it — this remains not
a bug. BS3's grid and JS are fully functional as loaded; the actual
visual problem this created (unstyled modals) was fixed in Round 7.
Renaming grid classes across ~60 files for zero functional or visual
change isn't "solving" something, it's pure risk with no user-facing
benefit — happy to do it if you specifically want the class names
modernized for its own sake, but it doesn't belong in a "fix what's
actually broken or missing" pass.

## Round 27 — Virtual Tour, solved honestly instead of left declined

Previously declined because faking 360°/VR without real panoramic assets
would just be a slideshow with a VR label on it. Found a genuinely honest
way to deliver it instead: this app already has a documented, optional
`GOOGLE_MAPS_API_KEY` pattern (silently used to upgrade route directions
in `ExploreService.php`) — the same key also unlocks Google's Street View
Embed API, which shows **real 360° imagery of the actual destination**,
not a fake substitute.

- Added a "Virtual Preview" panel to `package-details.php` using the
  Street View Embed iframe, positioned right above the Nearby Essentials
  widget.
- Only renders when **both** the package has coordinates **and** the key
  is configured — no broken iframe shown when either is missing.
- Street View coverage itself isn't guaranteed everywhere (remote
  destinations may have none) — left that to Google's own iframe to
  handle gracefully rather than trying to guess coverage ourselves.
- **Also fixed a real, separate gap while doing this**: `GOOGLE_MAPS_API_KEY`
  was already functional in the code but never documented in
  `.env.example` — meaning nobody deploying this app would have known the
  option existed at all. Added it with a clear explanation of exactly what
  it upgrades (and confirms nothing breaks or is lost by leaving it blank).

## Round 28 — "Most likely next bookings" — the one admin insight I'd explicitly skipped

Round 16 deliberately left this out rather than fake it with a trained
model this app has no data to support. Revisited now that cart contents
are a real, queryable signal: a package sitting in someone's cart is the
single strongest available purchase-intent signal, more honest than any
prediction model would be with this little interaction data.

Added `tms_likely_next_bookings()`: customers with a package in their
cart added in the last 14 days, excluding anyone who already has a
confirmed booking for that exact package (already converted, not "next"
anymore). Shows estimated value using the cart's locked-in dynamic price
when set, falling back to the current package price otherwise. Rendered
as a 4th column in the Business Forecasts panel from Round 16.

This closes every item from the original gap list except Social Login
(set aside per your instruction) and the Bootstrap 3 grid class rename
(still deliberately not done — reaffirmed three times now across this
pass as pure technical debt with no functional or visual benefit, not a
bug to fix).

## Round 29 — final consistency pass

Rather than stop after Round 28, did a holistic check for interaction
effects between features built in different rounds — the kind of gap
that's easy to miss when working incrementally:

- **Comprehensive re-verification**: re-ran a brace-balance check across
  all 43 files touched anywhere in this entire pass in one sweep. All
  clean.
- Verified every admin nav link added across all 29 rounds
  (`admin/includes/sidebarmenu.php`) actually resolves to a real file —
  no orphaned links.
- **Found and closed a real UX gap**: Round 24 enforced seat capacity at
  checkout, and showed a scarcity badge on `package-details.php`, but
  `cart.php` itself showed nothing — a customer could add an item while
  seats were available, then get rejected at checkout days later with no
  warning on the page in between. Added the same live capacity check to
  each cart row, so a customer sees "Only 2 seats left — you're
  requesting 4" (or a softer "filling up" note) right on the cart page,
  before they ever reach checkout.

This is a genuinely complete stopping point: every item from the original
review has been verified against the real codebase and either built,
confirmed already working, or explicitly and specifically declined with
documented reasoning — nothing left unaddressed by omission.

## Real remaining gaps (not done, sized honestly)

- **Bootstrap 3 grid classes** (`col-md-*`, etc.) are still used for page
  layout sitewide. Reaffirmed multiple times in this pass: not a bug — BS3's
  own JS/CSS grid is fully loaded and functions correctly. The actual
  visual gap this created (unstyled modals) is fixed (Round 7). A pure
  class rename across ~60 files, with no functional or visual benefit,
  isn't something I did — happy to if you want it specifically, but it's
  pure risk with no user-facing upside.
- Interactive maps: present on package-details, the listing page, the
  admin Popular Destinations heat map, and now a real Street View preview.
  Live routing already existed on tour-map.php. Not on the homepage's
  featured-packages grid specifically (a deliberate scope choice — that
  page is a marketing showcase, not a browsing tool).
- Social login (Google/Facebook/GitHub) — set aside per your instruction,
  needs real OAuth credentials from each provider to be more than
  unusable placeholder code.
