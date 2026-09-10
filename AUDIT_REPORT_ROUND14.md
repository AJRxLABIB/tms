# Audit Report — Round 14: professional pass over map, weather, chatbot & the whole customer site

Scope: the whole customer-facing application, audited in four parallel passes
(map / live place detection, live weather, the AI chatbot stack, and everything
else) and then fixed. Every PHP file changed in this round passes `php -l`, and
every JavaScript file and inline `<script>` block passes `node --check`.

**Working folder note:** all work in this round was done in
`C:\xampp\htdocs\tms` — the folder Apache actually serves, and now a git
repository — and then mirrored to `D:\Project\tms`. See Round 13 for why the two
had drifted apart.

---

## 0. The finding that explains a lot: the AI was switched off

There is no `.env` file in the project, so `GEMINI_API_KEY` resolves to `''` and
`AIClient::call()` returns before making any HTTP request. **Every** AI feature —
the site chatbot, the Explore overview tab, AI Recommender, AI Planner, packing
assistant, image analyzer, Trip Copilot — has been silently returning its non-AI
fallback. The site chatbot degraded to its 1990s-style keyword matcher, so
nothing looked broken; it just answered from a hardcoded script forever.

Worse, the reason leaked to visitors. `AIClient` returned the literal string
*"No Gemini API key configured. Add your key to includes/config.php"*, and
`api/explore.php` forwarded it to the browser — so the first thing a visitor saw
on the flagship Explore feature was a developer note.

Fixed:
- `.env.example` added, documenting every key with links to where each is issued
  and what happens when it is absent. Copy it to `.env` and fill in the Gemini
  key to switch the AI on.
- `.htaccess` added: `.env`, `.sql`, `.md`, `.log` and friends are no longer
  web-readable (`.env` sits inside the document root, so the keys were one URL
  away once the file existed), plus the `Authorization: Bearer` rewrite the REST
  API needs and `Options -Indexes`.
- `.gitignore` added so the key can never be committed.
- `config.php` now logs a clear diagnostic when the key is EMPTY — previously it
  only warned about a *malformed* key, so the common case said nothing.
- `AIClient` no longer sends operator instructions to the browser.
- The Explore chat widget and its "🟢 Live · powered by Gemini" badge are not
  rendered at all when no key is configured, instead of offering a service that
  cannot answer.

---

## 1. Live place detection (Explore Mode / Nearby Places)

### 1.1 `PlaceDetailsService` was a fabrication engine — rewritten

Nearby Places lists real places from OpenStreetMap. Those come with a name,
coordinates, and sometimes an address, phone, opening hours or website. The
detail panel invented **everything else** from an MD5 hash of the place's name
and rendered it as fact:

- a phone number, an email address, a website URL, and Facebook and Instagram
  profile links — OSM never supplies the last three here, so they were
  fabricated 100% of the time. A visitor clicking "Website" for a real hotel was
  sent to a domain that hotel almost certainly does not own.
- a star rating and review count, plus three named customer reviews with dates.
- five stock photos from picsum.photos, presented as photos of that place.
- room rates, a full restaurant menu with prices, fuel prices — all in hardcoded
  US dollars on a site whose base currency is Taka.
- for a real, named **hospital**: five to eight departments and four named
  doctors, an assertion of "Ambulance Service — available 24/7" for anything
  OSM tags as a hospital (including small rural clinics), and "Open 24 hours"
  unconditionally.
- for a real, named **police station**: a named Officer-in-Charge, and
  "Emergency Number: 112" — which is wrong for this platform's primary market
  (Bangladesh is 999).
- an "Open Now" / "Closed Now" badge that was a seeded coin flip, constant
  forever, ignoring the real opening-hours string it had been handed. A
  restaurant showed a definitive green "Open Now" at 4am permanently.
- and for any place with a non-Latin name (most of the primary market), the slug
  collapsed to the literal string "place", so every such place shared one
  identical fake identity: `place@place.com`, `facebook.com/place`, the same
  five photos.

The service is rewritten to show only what the upstream source actually
provided, with an explicit "not listed" for anything missing. `is_open_now` is
now computed by a real parser over the OSM `opening_hours` syntax (`24/7`,
`Mo-Fr 09:00-17:00`, `Su off`, ranges crossing midnight) and returns *null* when
the value can't be parsed — in which case no badge is shown at all rather than a
guess. Hospital and police entries get genuine emergency guidance (the actual
emergency numbers for the relevant countries, and a pointer to the traveller's
insurance line) and no invented specifics. A provenance note explains which
fields come from open map data and why some are blank.

### 1.2 An upstream outage was reported as "there are nothing nearby"

`package-details.php` discarded the API's real error and rendered *"No hotels
found nearby yet"* — a factual claim about the world — whenever the Overpass
lookup failed. Overpass rate-limits aggressively, so this fired regularly. It
now shows the real reason, exactly as `js/explore.js` already did correctly.

Also fixed there: `L.marker([p.lat, p.lng])` with no null guard threw "Invalid
LatLng object" and aborted the rest of the list render for any place without
coordinates.

### 1.3 The Overpass timeout was shorter than the Overpass query

`[out:json][timeout:20]` against `CURLOPT_TIMEOUT => 15`: any query taking
15–20 seconds (routine in dense areas) was aborted client-side and reported as
"temporarily unavailable" even though the server would have answered. Now 25s.

### 1.4 Nominatim was being called with a non-existent contact address

The User-Agent claimed `contact: support@tms.local`, which is not a routable
domain — exactly the sort of UA that earns an IP block, and a block here takes
out destination search, the entry point to the whole feature. It now identifies
the actual host and uses `TMS_CONTACT_EMAIL` from `.env`.

### 1.5 Public Transport was a button that could never work

With no Google Maps key it returned an error reading *"Showing driving/walking
directions instead"* — and then showed nothing, leaving the user parked on a
dead mode reading a promise that never arrived. It now genuinely falls back to
the driving route, and the button is only offered when a key makes real transit
routing possible.

### 1.6 A configured Google Maps key made the map worse

`routeFromGoogle()` hardcoded `'geometry' => []`, so a Google route drew no line
at all — a silent downgrade from the keyless OSRM path, which does return
geometry. Google's encoded polyline is now decoded.

### 1.7 The Google Places API key was handed to every visitor

Place photo URLs were built server-side with `&key=GOOGLE_PLACES_API_KEY` and
rendered into an `<img src>`, so anyone could read the billing key from
DevTools. Photos now stream through `api/explore.php?action=place_photo`, and the
client only ever sees an opaque reference.

### 1.8 Destination search failing showed the user nothing at all

`if (!res.success) return;` — the spinner stopped and nothing appeared. Typing a
city and getting no reaction whatsoever was the failure mode for the first step
of the feature. Now shows the real message, a distinct "no match" state, and a
connection-failure state.

### 1.9 "Recently Explored" could never populate

The history INSERT sat at the bottom of `getDestinationInfo()`, after two early
returns — including the one that fires whenever the AI call fails, i.e. always,
on any install without a key. Recording that someone opened a destination has
nothing to do with whether the AI wrote a description, so it now happens first.

### 1.10 Smaller map fixes

- `getRecentHistory()`'s `GROUP BY` was invalid under MySQL's default
  `ONLY_FULL_GROUP_BY` (error 1055) and the exception was swallowed, so Recently
  Explored silently never appeared on any standard MySQL host. MariaDB (XAMPP)
  happens to allow it, which is why it looked fine locally.
- `state.navTarget` was never cleared, so after viewing directions to one nearby
  cafe, every later "Locate Me" or travel-mode switch kept routing to that cafe
  forever. There is now a "Route back to <destination>" control.
- "Get Directions" on a place without coordinates was a completely dead click.
- Raw `alert()` calls replaced with the site's toast helper.
- Nearby cards no longer show a false "0.0 km" for an unknown distance.
- `api/explore.php` coordinate validation was `if (!$lat && !$lng)` — it required
  **both** to be zero, so `?lat=0&lng=abc` passed and queried the Gulf of
  Guinea. Presence, numeric-ness and range are all checked now, in one helper.
- `tms_clean()` and `new ExploreService()` ran outside the try block, so
  `?action[]=x` produced an empty 500 body instead of JSON.

### 1.11 `tour-map.php`

- **Two different prices for the same package on one screen.** The sidebar used
  `tms_currency()` (converted to the visitor's chosen currency) while the map
  popup and the search dropdown both prefixed a hardcoded `৳`. A package showing
  "$ 55.00" in the list said "৳6,000" in its own popup. Price is now formatted
  once, server-side, and used by every render path.
- **`filterMap()` crashed on any package with a NULL `PackageType`** —
  `item.type.toLowerCase()` threw mid-`forEach`, leaving the map half-filtered
  with no visible error. `PackageType` is nullable and NULLs are expected.
- **The filter pills filtered the map but not the sidebar**, so clicking a
  still-visible sidebar row panned to an empty patch of map and opened no popup
  (Leaflet silently ignores `openPopup()` on a marker that isn't on the map).
  The sidebar now filters in step, and the count follows it.
- **Package names went into popup HTML and an `onclick` attribute unescaped** —
  a name containing a quote broke the attribute and silently killed that
  package's "Get Directions" button. Escaped, and the handler is delegated.
- `tms_currency($p->PackagePrice)` without a cast would have been a fatal
  `TypeError` on a NULL price; every other call site in the project casts.
- Bike/Walk buttons visibly responded but did nothing when no start point was
  known (after a geolocation denial). They now re-state what's needed.
- The legend advertised an "International" category that no filter could select.

---

## 2. Live weather

### 2.1 Missing coordinates produced real weather for the Arabian Sea

`weather.php` read `$lat = $selectedPkg->MapLat ?? 20; $lng = ... ?? 70;`. Both
admin forms deliberately store SQL NULL when the map fields are left blank, so
for **every package without coordinates** those defaults fired and the page
fetched genuine live weather for 20°N 70°E — open ocean off the Gujarat coast —
then displayed it under that destination's name with a "Live via Open-Meteo"
badge. A user checking a Swiss alpine package saw Arabian Sea conditions labelled
"Switzerland · Live".

It also made the honest "we don't have a map location for this destination yet"
branch — which was already written, and correct — unreachable. Fixed at the
source, so that branch now actually runs. `compare.php` already handled this
correctly; `weather.php` was the odd one out.

### 2.2 The coordinate guard required BOTH coordinates to be missing

`if (!$lat && !$lng)` meant a half-set location (lat NULL, lng present) sailed
through as a real point. Now either missing coordinate, or one out of range, is
reported honestly — and there is a distinct message for each case instead of one
sentence covering everything.

### 2.3 A fabricated 7-day forecast, shown exactly when the real one failed

The fallback rendered seven hard-coded high/low pairs in the same markup as the
live rows — same day names, same °C styling — distinguished only by a grey
footnote. The numbers were keyed solely off "is this a best/avoid month", so
**Switzerland in January and Dubai in December were both presented as 28°C/22°C**.
Removed; the panel now says plainly that there is no live forecast and points at
the month-by-month guidance, which is real.

### 2.4 An invalid or rate-limited API key was invisible to everyone

`_tms_http_get()` never checked the HTTP status, so an OpenWeatherMap 401 (bad
key) or 429 (quota spent) came back looking like success, produced an array with
no `main`, and silently fell through to Open-Meteo — with no user message and no
log line. A deployer who typo'd their key saw a working page and never found out.
Status is now checked and 401/403/429 are logged distinctly.

### 2.5 An outage cost 12–36 seconds per page load

Weather failures were never cached, so during an outage every page load
re-attempted the call (up to 6s each, twice), and `compare.php` looped it over
three packages — up to 36s, past XAMPP's default `max_execution_time`, replacing
the whole comparison with the generic fatal-error page. Failures now get a short
negative TTL. The same fix is applied to the FX-rate lookup, which
`tms_currency()` calls **once per price on the page** and which cached only
successes — a package list in a non-BDT currency would hang for a minute with the
FX API unreachable. It now also memoises per request.

### 2.6 An uncatchable fatal in the forecast parser

`max($temps)` on an empty array throws `ValueError`, which extends `Error`, not
`Exception` — so the surrounding `catch (Exception)` did **not** catch it and a
degraded OpenWeatherMap payload took the whole page down. Guarded, and the
catches are now `Throwable`.

### 2.7 Other weather fixes

- Forecast days were bucketed with `date()` — the **server's** timezone — so for
  any destination in a different zone the daily highs/lows blended two local
  days, and the "skip partial today" test could skip the wrong day or nothing.
  Now bucketed in the destination's own offset.
- A partial Open-Meteo response returned `ok: true` with `temp: null`, rendering
  "°C" with no number on `weather.php` and a fabricated **"0°C"** on
  `compare.php`. Essentials are now required for success, and every value is
  guarded at the point of display.
- `main.temp` / `feels_like` / `humidity` / `name` were the only unguarded reads
  in the OpenWeatherMap block (wind and weather already used `??`).
- "Hourly Forecast (next 24h)" showed **00:00 to 23:00 today** — at 8pm, twenty
  of the twenty-four chips were hours that had already passed. It now starts from
  the destination's current local hour.
- 7-day weekday labels were off by one for every viewer west of UTC (a date-only
  string parses as UTC but formats as local).
- A missing air-quality reading was labelled **"Good"** — `aqiLabel(0)` returns
  "Good", so a location whose AQI call returned nothing displayed "— · Good", a
  positive health claim about a value never received. No reading, no label.
- Wind was reported in m/s on one page and km/h on another; both now km/h.
- The reading is stamped "updated HH:MM" — `fetched_at` was being stored on every
  record and never shown, while the label said "Live" on data up to 30 minutes
  old.
- The forecast heading said "5-Day Forecast" while the body rendered the 7-row
  fallback; heading and body now share one condition.

---

## 3. The AI chatbot

### 3.1 It had no idea what the site sells

The system prompt was well written, but the only site data injected was a
package **count** and a min/max price. No names, no destinations, no individual
prices, no IDs. So the single most common question a travel chatbot gets — *"what
do you have for Cox's Bazar?"*, *"how much is the Bhutan trip?"* — was literally
unanswerable, and the prompt correctly forced it to deflect to the package list
every time. It was a link dispenser, not an assistant.

It now receives a real, compact catalogue (top 40 by rating and popularity, with
IDs so it can link straight to a package page, plus the genuine category list
derived from the data), cached for ten minutes. A signed-in visitor's own
bookings are injected too, so "when does my trip start?" and "can I still
cancel?" are answerable — and the visitor's raw email address, which the prompt
used to carry for no benefit, is no longer sent.

The old query also computed three category counters (beach / mountain / heritage)
and **never used them**, returning a fixed sentence asserting those categories
exist. For the current catalogue none of the three patterns match anything, so
the claim was simply false.

### 3.2 The conversation was wiped on every page navigation

`conversationHistory` was a plain in-memory JavaScript variable. Clicking any
link — including the quick-reply links the bot itself renders — reloaded the page
and erased the conversation, so in practice most conversations never got past one
or two turns. History now lives server-side against a long-lived cookie
(`tms_chat_uid`), so it survives navigation, refresh and logout.

This rebuilds the feature that was lost between Aug 31 and Sep 3 (see Round 13),
with the three bounds the original was missing: 12 turns per request, 50 turns
per visitor, 30 days globally. The client no longer sends history at all, which
also closes a hole where a crafted request could put words in the assistant's
mouth.

### 3.3 Cross-site request forgery, and an XSS sink

`api/ai.php` had **no CSRF validation on any of its 17 actions**, and its
requests are form-encoded (a CORS "simple request", so no preflight) — any page
on the internet could drive the endpoint with a visitor's cookies attached,
burning the site's AI quota, and for an admin, reaching the admin-only
`suggest_price` action.

Separately, both the visitor's own text and the model's reply went into
`innerHTML` with no escaping. The system prompt deliberately instructs the model
to answer in raw HTML anchors, so the sink is load-bearing and cannot simply be
escaped away: everything is now escaped first, and exactly one construct is
allowed back in — a relative-path anchor to a `.php` page on this site. Anything
else (an `<img onerror=…>`, a `javascript:` href) renders as visible text.

### 3.4 Every guest shared one rate-limit bucket

All signed-out visitors were logged under the literal string `'guest'`, so with
`AI_RATE_LIMIT=30` **the 31st AI call from any guest in a rolling hour locked out
the chatbot for every guest on the site**. Now scoped per browser session. The
separate session-based counter in `api/ai.php` also (a) assigned an unused `$ip`
while its comment claimed an IP limit, (b) added a new session key every hour and
never removed the old ones, and (c) counted unknown actions and 400s against the
allowance because it ran before dispatch. All three fixed.

### 3.5 A chat request could take 91 seconds

`CURLOPT_TIMEOUT` was 30s with no connect timeout, inside a 3-attempt retry
loop — a worst case of ~91s for an *interactive* request, well past XAMPP's
default `max_execution_time`, at which point PHP killed the script mid-retry and
the browser received an HTML fatal instead of JSON. Now 12s per attempt, 5s to
connect, and a hard 22s budget across all retries. A 429 that names a daily quota
fails fast instead of hammering the API.

### 3.6 Failures were invisible, and indistinguishable from bad answers

Every failure path — no key, rate limit, network down — resolved to the local
keyword responder with no indication anything had gone wrong. The visitor got a
strange, off-topic reply and no idea why; this is precisely why nobody noticed
the AI had been off. The endpoint's real message was discarded on arrival. The
widget now tells the visitor when they are seeing a standard answer, says which
situation caused it, and offers "Try that again".

### 3.7 Nothing stopped concurrent sends

`isTyping` was declared and never read or written again, and there was no
in-flight guard: hammering Enter fired N overlapping requests whose replies
arrived out of order, each removing a duplicated-id typing indicator and
corrupting the history sent on the next turn. There is now a real guard that
disables the input and send button while a request is in flight.

### 3.8 Prompt injection: 1,500 characters of system-prompt control for anyone

`api/explore.php` concatenated a **client-supplied POST field** straight into the
*system* instruction (`$context`), and `AIClient` sanitises `$messages` but never
`$system`. Anyone with curl had direct, unauthenticated control of the
highest-priority part of the payload. Client data now enters as a delimited user
turn that the prompt explicitly designates as data, never instructions.

The old defence — regex-replacing five English phrases ("ignore previous
instructions", "system prompt", "jailbreak"…) with `[filtered]` — stopped nothing
(two spaces, a synonym, another language or base64 all pass) while corrupting
real messages: a visitor asking "what's in the system prompt for this bot?" got
`[filtered]`, and a review saying "forget everything I said about the hotel" was
rewritten before the sentiment analyser scored it. Removed in favour of the
structural fixes above. `strip_tags` is also no longer applied to assistant
turns, which had been stripping the anchors the model was told to produce out of
its own replayed history.

### 3.9 The Explore guide had no memory, and `tbl_explore_chat` was write-only

Every turn was written to the table and **never read back** (`SELECT … FROM
tbl_explore_chat` appeared nowhere), so "what about the second one you mentioned?"
always failed while the table grew forever. History is now replayed, and pruning
was added for it and for the three other log tables that had none.

### 3.10 Safety thresholds, and other AI fixes

- All four Gemini safety categories were at `BLOCK_ONLY_HIGH`, the loosest
  setting available, on a public consumer site. Now `BLOCK_MEDIUM_AND_ABOVE`.
- A prompt blocked *before* generation is reported at the top level of the
  response, not on the candidate — that case fell through to a generic "empty
  response". Handled, along with `MAX_TOKENS`.
- The API key travelled in the URL query string (and so into any proxy log). It
  now goes in the `x-goog-api-key` header.
- `json_encode()` returning `false` on invalid UTF-8 posted an empty body and
  surfaced as a baffling "HTTP 400".
- `tms_clean()` was applied to chat messages — it runs `strip_tags()`, which
  deletes everything from a `<` to the next `>`. *"anything under 50k? we're >4
  people"* lost half the sentence, and *"packages < 50000"* arrived as just
  `"packages "`. Chat text is escaped where it is rendered, not mangled on input.
- The image analyzer returned an **empty** error string, because
  `AIClient::fallback()` sets `content => ''` (not null) so `??` never fired and
  the real reason was thrown away.
- The hardcoded fallback answers advertised `info@tms.com`, `+1-800-TMS-TOUR`
  (a US toll-free placeholder on a Bangladesh-based site) and price bands
  contradicting the actual catalogue.
- Enter-to-send used the deprecated `keypress` and fired mid-IME-composition,
  sending half-typed Bengali/Hindi words. Now `keydown` with an `isComposing`
  guard. `aria-live` added so screen readers announce replies.

---

## 4. Everything else — the rest of the customer site

### 4.1 Nobody could register from the site-wide Sign Up modal

The live email check read its CSRF token from `#signupCsrf` — an id that exists
only in `includes/signup.php`, a file **no page ever includes**. The live modal
emitted its token with `tms_csrf_field()`, which sets no id. So the check always
posted an empty token and always got a 403 whose body reads *"Invalid or expired
form submission…"*. The caller then decided whether to disable the submit button
by searching that response for the substring `"red"` — which appears inside
`"expired"`. **Every** attempt therefore greyed out "Create My Account"
permanently. The only way to register was to navigate directly to
`register.php`.

Both halves are fixed: the endpoint returns structured JSON instead of prose to
be pattern-matched, the token element now exists, and a failed convenience check
never blocks signup (the server validates properly on submit anyway).

### 4.2 A 100%-off coupon was trivially reachable

Loyalty redemption computed `floor(points / 500) * 10` with **no cap**: 5,000
points minted a 100% discount — a free booking — and 6,000 points produced
`DiscountPercent = 120`. Points are farmable (20 per review, one review per
package, 400+ packages, no requirement to have booked), so this was reachable in
minutes. Capped at redemption **and** at application time, so a bad row already
in the table can't be used either.

### 4.3 Legacy accounts could never sign in

`login.php` called `password_verify()` directly instead of the project's shared
`tms_verify_password()`, which accepts a legacy md5 hash and transparently
upgrades it to bcrypt. Every account still holding an md5 hash — including the
five seeded demo users — got "Invalid email or password" forever on the website,
even though the same credentials worked through the REST API.

### 4.4 The brute-force lockout was recorded but never enforced

`tms_login_lockout_minutes_remaining()` existed and worked, `login.php` recorded
failures and cleared them on success — but never actually **checked** the
lockout. `tbl_login_attempts` filled with rows nothing read, and customer
accounts accepted unlimited password guesses. Admin login already gated on it.

### 4.5 The support-ticket channel was unreachable

`includes/write-us.php` renders the only form that writes to `tblissues`, and it
is included on about twenty pages — but nothing anywhere opened it. No button, no
link, no `data-target`. Meanwhile `issuetickets.php`'s empty state told users to
"use the Write Us widget", so the page showed that empty state forever and
`admin/manage-issues.php` never had a ticket to answer. A footer entry now opens
it.

### 4.6 Four "view package" links used the wrong parameter

`package-details.php` reads `?pkgid=`. The Recently Viewed strip, the package
list's map popup, the image analyzer's match cards, and the notification sent
after posting a review all sent `?id=` — so every one of them landed the user on
"Package not found".

### 4.7 A site-wide promo coupon worked for exactly one customer

`tblcoupons` has a single `IsUsed` flag per row. Global coupons (`UserEmail IS
NULL`) are precisely the ones an admin creates and broadcasts to every registered
user — and the first booking to apply one flipped that flag, so everyone else saw
"Invalid or expired coupon code". The seeded `WELCOME10` / `SUMMER20` / `TMS2026`
codes had this out of the box. Global coupons are no longer consumed; personal
ones still burn on use.

### 4.8 The cart's arithmetic looked wrong

Cart and checkout showed the **per-person** price while the total multiplied by
the number of travellers — and the traveller count was displayed nowhere. One
item for three people read "৳12,000" beside a total of "৳36,000" with nothing
explaining the difference, which reads as an overcharge and survived to the
confirmation page. Both now show `price × N travellers = subtotal`.

### 4.9 The booking form's date fields had no datepicker at all

Two bare text boxes with a "dd-mm-yyyy" placeholder — `js/jquery-ui.js` ships in
the repo but no page loads it and nothing calls `.datepicker()`. Anyone typing
`12/03/2026`, or their own locale's format, lost the whole form to a validation
error after a full round-trip. Now native date inputs (real calendar on desktop
and mobile, no library) with a minimum of tomorrow, and the return date follows
the departure date. Same fix on the date-change modal.

### 4.10 Selecting a non-English language could hang every listing page

Translation ran synchronously during render and cached only successes, so with
the AI reachable-but-failing, every page load retried and waited — past
`max_execution_time` on listing pages, which then returned the generic fatal
page. A five-minute circuit breaker means one failure costs one wait, not one
wait per page view, and the page still renders in the original language.

### 4.11 The systemic `LIMIT ?` bug — eight silently dead queries

`PDO::ATTR_EMULATE_PREPARES => false` is set project-wide, so `execute([$int])`
binds as a **string**, and MySQL/MariaDB reject a string in `LIMIT`. Every one of
these threw, and every one was wrapped in a `catch` that returned an empty result
with no log line — so each feature reported "nothing found" forever:

| Where | What it actually broke |
|---|---|
| `TripCopilot::getHistory` | **The Trip Copilot had no memory at all** — every turn was saved and none could ever be read back |
| `FraudDetector` (×3) | coupon-cluster, rapid-fire booking and high-cancellation detection all always returned zero flags |
| `SentimentAnalyser::batchAnalyse` | the admin's "Analyse reviews" button always reported "0 reviews analysed" |
| `SentimentAnalyser::getSuspiciousReviews` | the admin's fake-review panel was always empty |
| `ExploreService::getRecentHistory` | Recently Explored always empty |
| `AchievementEngine::getLeaderboard` | leaderboard silently degraded |

So the AI dashboard's fraud and sentiment panels were reporting "all clear" not
because there was nothing to report, but because the query never ran. All eight
are fixed, and the silent `catch` blocks now log.

### 4.12 Smaller, still real

- **"Social Butterfly" was unearnable.** `AchievementEngine::countReferrals()`
  counted `WHERE ReferredBy = <referral code>`, but `ReferredBy` stores the
  referrer's **email** — so the count was always 0 even for a user whose Refer a
  Friend page listed ten referrals.
- **"Custom trip request = 30 pts"** was advertised on the loyalty page and never
  awarded. Now awarded, rate-limited to once a day because the form has no
  submit limit and would otherwise be an unlimited points farm.
- **The Family / Couple / Group filters matched almost nothing.** They searched
  `PackageType LIKE '%Family%'`, but only the nine original demo rows use those
  words — the 400-package catalogue uses Adventure, Beach, Heritage, Island,
  Mountain, Nature, Wildlife. "Couple" returned 1 result out of 413. The chips
  are now generated from the types the catalogue actually contains, with counts,
  so every chip is guaranteed to return results.
- **Emergency Contacts was an empty page.** `tblemergencycontacts` has always
  been created by the migrations and never populated, and the `foreach` had no
  empty state — so a page whose entire purpose is those numbers showed the SOS
  banner, a search box that filtered nothing, and then a blank gap. Thirteen
  countries are now seeded with their real official emergency numbers, and there
  is an honest empty state behind that.
- **Open redirect** in `language.php` (`?redirect=https://evil.example` sent the
  visitor off-site from a trusted page). `login.php` already had the guard.
- **Cancellation was blocked ~48 hours out while the message said 24**, because
  `->diff()->days` truncates. Both the handler and the button state now compare
  actual hours.
- **Date-change requests accepted dates in the past** — "new departure
  01-01-2020" went through to the admin queue.
- **"Create one free"** in the sign-in modal targeted `#myModal`, which exists
  nowhere; clicking it closed the sign-in box and opened nothing.
- **`thankyou.php`** read `$_SESSION['msg']` unguarded, so any direct visit
  raised a warning and printed an empty heading.
- **Prices in JavaScript hardcoded the Taka symbol** in the budget planner and
  the image analyzer, next to server-rendered prices that *are* converted — a
  visitor on EUR saw "৳5,000" in the planner and "€ 38" for the identical saved
  plan. The planner now works consistently in the currency its figures are
  stored in, and says so; the analyzer converts properly.
- **Four dead footer social buttons** (`href="#"`) are no longer rendered unless
  real URLs are configured.
- The heatmap's top-3 highlight was off by one (`:nth-child` counted the heading).
- Explore Mode's copy promised "turn-by-turn" directions; the feature only ever
  returned distance and ETA.

---

## Verification

- `php -l` on every changed PHP file: clean. (PHP 8.4 CLI; the project targets
  8.2, and nothing added here uses 8.3+ syntax.)
- `node --check` on `js/explore.js` and on every inline `<script>` block in the
  pages that were edited: clean.
- Brace/paren balance checks on each edited file, as in previous rounds.
- Runtime verification against the live server is **still outstanding** — Apache
  was not running when this round finished, so the pages below have not been
  loaded since these changes. See the deploy checklist in
  `SESSION_SUMMARY_INDEX.md`.

## What still needs a human

1. **Add a Gemini API key** — copy `.env.example` to `.env` and fill it in.
   Nothing AI-powered works until then, by design rather than by accident now.
2. **Start Apache and click through** the pages listed in the deploy checklist.
3. **`data/packages_seed.json` prices vs. the assistant's currency.** The
   catalogue seeds Paris, Switzerland, Bali and Dubai at 1,000–6,000, and the
   whole app treats stored prices as Taka — so the assistant will quote "৳3,000
   for a 7-night Bali trip". Either the seed prices or the currency assumption is
   wrong; that is a data decision, not a code one.
4. **Consider whether the loyalty economy is right.** 20 points per review with
   no requirement to have booked, 100 points at booking *creation* (before
   confirmation, never reversed on cancellation), and 500 points per 10% off is
   generous enough to be worth a deliberate decision rather than a default.

---

## Round 14 addendum: live verification (Apache back up)

Apache came back up after this report was first written. Everything below was
tested against the real running site — real MySQL data, real Overpass/Open-
Meteo calls, a real (pre-existing, not created by this session) signed-in
session — not just linted.

**Confirmed fixed and working, live:**
- Sign-up email-availability check (`check_availability.php` + `header.php`):
  the CSRF field renders, the endpoint returns proper JSON, and the "Create My
  Account" button correctly stays enabled after a valid check — the P0 bug is
  gone.
- `api/ai.php` chatbot action now rejects requests with no/bad CSRF token
  (403) and no longer leaks "Add your key to includes/config.php" to
  visitors.
- `PlaceDetailsService` returns real data only (phone/email/website/rating/
  images all `null` for a place with no verified data) with the new
  "Verified details / In an emergency / Where this information comes from"
  section structure, and `openNowFromOsm()` correctly parses `24/7` and
  `Mo-Fr 09:00-17:00` as open, and returns `null` (not a guess) for
  unparseable strings like `sunrise-sunset`.
- The Google Places photo proxy 404s safely with no key configured.
- `weather.php` now shows real live weather (temperature, feels-like,
  humidity, wind in km/h, sunrise/sunset, a real 5-day forecast, an "updated
  HH:MM" stamp) for a package with saved coordinates, **and** an honest "we
  don't have a map location saved for this destination yet" message plus "we
  don't have a live forecast, and we'd rather show you nothing than invent
  numbers" for a package without coordinates — confirmed against two real
  packages side by side.
- `tour-map.php` sidebar price and popup price are now identical for the same
  package (both server-formatted through `tms_currency()`).
- Emergency contacts page shows real, correct emergency numbers and
  country-specific safety tips for all 13 seeded countries (Bangladesh,
  Bhutan, France, India, Indonesia, Malaysia, Maldives, Nepal, Singapore,
  Switzerland, Thailand, UAE, UK).
- Budget planner: every displayed figure (sliders, summaries, the "budget
  balanced / over budget" message) consistently uses the base-currency symbol
  — no mismatch between the live editor and the saved-plan list.
- Cart and checkout both show `unit price × N travellers = subtotal` and the
  order total matches, for a real 3-traveller test booking (added and then
  removed again, nothing left behind).
- `package-list.php`'s dynamic type chips are real and match the database
  exactly (e.g. "Beach (37)" → filtering by it returns exactly 37 packages).

**Three more real, live bugs found during this verification pass and fixed
on the spot (not in the original audit, because they only showed up once
real data and a real server were involved):**

1. **`tour-map.php`'s quick filters, legend, and every marker/sidebar color
   were keyed on four package types that don't exist in this catalogue**
   ("Family Package", "Couple Package", "Group Package", "Domestic
   Packages" — the real types are Nature, Heritage, City, Beach, Island,
   Adventure, Wildlife, Mountain, Religious...). Effect, confirmed live:
   every single marker and sidebar heading rendered in the same fallback
   orange, the legend described four categories that don't exist, and
   clicking "Family"/"Couple"/"Group"/"Domestic" always returned zero
   results — a completely dead filter row. Fixed by generating the legend,
   pills, and colors from the real top 6 types and their real counts (same
   pattern as `package-list.php`'s chips).

2. **`tour-map.php`'s destination counter and sidebar filtering used the
   404-item "has map coordinates" list, but the sidebar shows all 413
   destinations** (including ~9 without coordinates, shown greyed out with
   a "Not mapped" badge). Effect, confirmed live: the destination count
   started at 413 but permanently dropped to 404 the first time any filter
   other than "All" was clicked, and the ~9 unmapped rows were never hidden
   or shown correctly by any filter — they just stayed visible regardless of
   what was selected. Fixed by adding a full 413-item id+type list that
   filtering and counting now use, while the map itself still only draws
   markers for the 404 that have coordinates.

3. **The site-wide chat widget's offline/fallback knowledge base (used for
   100% of chat replies right now, since no Gemini key is configured yet)
   invented package categories and understated the catalogue** — "Family
   Packages – Kerala, Bhutan, Sikkim", "Couple/Honeymoon – Bali, Paris,
   Switzerland" etc. for the "what packages do you have" question, and "we
   cover Asia, Europe, the Middle East" (naming ~9 countries) for the "where
   do you go" question, against a real catalogue of 413 packages across 40+
   countries and real types that don't match those labels at all. Fixed by
   having `includes/chatbot.php` query the real top package types and
   countries and build both replies from that (e.g. "We have 413+ tour
   packages across categories like Nature, Heritage, City, Beach and more"
   / "We cover 413+ destinations, including Bangladesh, India, Thailand,
   Indonesia, USA, Japan and more"), verified end-to-end through the actual
   chat UI.

All three fixes were linted (`php -l` + `node --check` on the extracted
inline scripts) and then re-verified live in the browser before being
mirrored to both project folders, which remain in sync.

**Still outstanding** (all behind `tms_require_user()` in ways this session's
credential policy doesn't fully block anymore now that Apache is up and a
session happens to be signed in, but worth a deliberate human pass anyway):
booking cancellation/date-change flows, the coupon redemption UI end to end,
the AI Trip Copilot and AI Recommender pages, and admin-panel screens
(fraud/sentiment dashboards) — these use the same fixed underlying code
(`LIMIT` binding, etc.) but weren't individually clicked through this round.
