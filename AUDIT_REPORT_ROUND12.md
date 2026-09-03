# TMS — Round 12: customer-facing features — live map, nearby places, weather, seasonal predictions

Scope this round, per direct request: focus on what an ordinary logged-in
customer actually sees and uses — the live map, nearby-places lookups,
weather, and seasonal/best-time predictions — and make sure none of it
looks broken, wrong, or unfinished to them. Same constraint as Round 11: no
PHP/MySQL runtime available in this environment, so every fix was verified
by reading, cross-referencing consuming code, and a brace/paren-balance
check on every touched file, not by executing anything.

## The big one: `BookingDate` was never set by any booking path — ever

This is the highest-value fix this round, and it's the direct answer to
"seasonal activity." Five separate features read `tblbooking.BookingDate`
expecting it to hold the trip's date:

- `includes/ai/CrowdPredictor.php` — the "Live Crowd Insights" widget on
  every package page, meant to predict busiest/quietest months from **real
  booking history** once 8+ bookings exist for a destination.
- `includes/ai/TripCopilot.php` — "you have an upcoming trip" proactive
  detection (`BookingDate >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)`).
- `includes/AchievementEngine.php` — the "Early Planner" achievement
  (`DATEDIFF(BookingDate, RegDate) >= 30`, i.e. booked 30+ days ahead).
- `travel-memory.php` — shows each trip's date in the timeline.
- `expense-tracker.php` / `ai-copilot.php` — trip date shown next to spend.

None of the three places a booking actually gets created
(`checkout.php`, `api/index.php`, `package-details.php`'s direct-booking
form) ever included `BookingDate` in their `INSERT INTO tblbooking(...)`
statement. It's a real column (self-healing, added automatically) — it was
just never written to, so it was `NULL` on **every booking ever made**.
Concretely, that meant:

- Crowd Insights could never enter "real data" mode — it silently always
  fell back to generic AI guessing, forever, no matter how many bookings a
  destination had. (`WHERE b.BookingDate IS NOT NULL` matched zero rows.)
- The Early Planner achievement was unearnable by any user, permanently.
- Trip Copilot's "upcoming trip" proactive nudge never fired.
- Trip dates on `travel-memory.php` rendered blank.

Fixed at the source: all three booking-creation paths now also insert
`BookingDate`, set to the trip's `FromDate` (RegDate already covers "when
the booking record was created" — BookingDate is "when the trip is").
Also added a one-time backfill in `includes/config.php`'s existing
schema-repair pass (`UPDATE tblbooking SET BookingDate = FromDate WHERE
BookingDate IS NULL AND FromDate IS NOT NULL`) — same self-healing style
already used for missing columns, safe to run on every request since it's
a no-op once caught up, so **every booking made before this fix gets
repaired automatically the next time any page loads**, not just new ones.

## Safety fix: fabricated "Emergency Contact" phone numbers for hospitals/police

`includes/PlaceDetailsService.php` builds a realistic-looking detail page
for every nearby place (used by both Explore Mode and Package Details'
"Nearby Essentials"), inventing plausible contact info when OpenStreetMap
doesn't have a real phone number on file — reasonable for a hotel or shop,
but for the `hospital` category it fabricated a specific, real-looking
**"Emergency Contact"** ambulance number, and the shared contact-card logic
did the same for the main phone field on **police** stations too. A
traveler in an actual emergency near a mapped hospital/police station could
see this and dial a number that reaches no one. Fixed: those two
categories no longer get an invented phone number — `buildHospital()` now
shows the same kind of real, generic guidance `buildPolice()` already gave
("call your local emergency number, e.g. 911/112/999"), and the front-end
(`js/explore.js`) shows "No number on file — call your local emergency
number" instead of leaving the field blank.

## Data-accuracy fix: Explore Mode showed USD costs with a Taka symbol

`ExploreService.php`'s AI prompt explicitly asks for and returns
`budget_per_day_usd` / `midrange_per_day_usd` / `luxury_per_day_usd` — real
USD estimates. `js/explore.js` rendered them prefixed with `৳` (Taka) and
no conversion, so a customer would see e.g. "Budget ৳50/day" when the
actual estimate was **$50/day** (~2 orders of magnitude off in real terms).
Relabeled with `$` and an explicit "/day USD" unit instead of silently
misrepresenting the currency.

## UX fixes: dead-end clicks, misleading "0.0 km", dead code paths

- **`tour-map.php`**: clicking a sidebar destination (or a search result)
  for a package with no map coordinates did nothing at all — no message,
  no visual feedback, just a dead click. Now: (1) the map-eligible query
  requires *both* `MapLat` and `MapLng` (it only checked `MapLat`, so a
  package with just one set still slipped through and failed silently),
  (2) sidebar rows without coordinates get a visible "Not mapped" badge and
  a tooltip, and (3) clicking one now shows a friendly toast explaining why
  instead of nothing happening.
- **`package-details.php`**: the "Nearby Essentials" list showed a false
  **"0.0 km"** distance for places where Google Places didn't return
  coordinates (`distance_m: null`, and `null / 1000` is `0` in JS) —
  now shows "Distance unknown" instead, matching the correct guard the
  detail-modal code two dozen lines away already had.
- **`weather.php`**: a dead `error === 'no_key'` check that could never be
  true (grepped `tms_get_weather()` — it never returns that value) meant
  the "Live weather isn't configured yet" message could never actually
  display; replaced with a check against the real error code
  (`no_coords`) so the right message shows for the right reason. Also
  added a missing `?? ''` null-guard on `PackageFetures` before `substr()`
  (present on the identical field in `package-details.php`, missing here) —
  was throwing a PHP 8.1+ deprecation notice into the error log for any
  package with that field left blank.
- **`js/explore.js`**: the AI chat's "context" feature (meant to make
  follow-up questions like "is now a good time to go?" chat-aware of the
  weather/overview the visitor is already looking at) read from
  `document.body.dataset.exploreContext`, which nothing ever set — the
  chatbot was silently context-blind since this was built. Wired it up:
  the attribute is now populated from the Overview and Weather tabs' data
  as each loads.

## Checked and found already correct

`heatmap.php` and `destinations.php` — no external API dependency, no
missing guards, proper empty states, fully escaped output. The
`ExploreService.php` / `api/explore.php` backend for nearby places, geocoding,
and weather already wraps every external call in try/catch with
timeout-bound HTTP requests and JSON-decode validation — no bugs found
there. `CrowdPredictor.php`'s field names line up exactly with what the
front-end expects (checked specifically given this codebase's history of
response-shape mismatches from Phase 2m) — the only actual defect in that
chain was the never-populated `BookingDate` column, fixed above.

## Noted, not changed

`includes/PlaceDetailsService.php` still fabricates plausible-but-fake
phone/email/website/reviews for every other category (hotels, restaurants,
shops, banks) when the live source doesn't have that field. This is
functioning as designed for those (the class's own docblock is explicit
about this being deliberate filler content, not a bug) and isn't a safety
issue the way the hospital/police case was — left as-is rather than
stripping a feature that wasn't reported as a problem.

## Files changed this round

`includes/PlaceDetailsService.php`, `js/explore.js`, `css/explore.css`,
`tour-map.php`, `weather.php`, `package-details.php`, `checkout.php`,
`api/index.php`, `includes/config.php`.
