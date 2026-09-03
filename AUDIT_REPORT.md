# TMS Project Audit & Fix Report — July 2026

## How to apply

1. Replace your project files with this folder (or copy over just the changed
   files listed below — nothing else was touched).
2. Optional but recommended: run `schema_repair_migration.sql` once via
   phpMyAdmin. **Not strictly required** — `includes/config.php` now repairs
   the same schema drift automatically on the very first request after you
   deploy this update, and re-seeds the 400+ package catalog once the columns
   exist.
3. Load any page once (e.g. `destinations.php`). Check your PHP error log —
   you should see lines like `[TMS Schema] Added missing column ...` and
   `[TMS Seed] Inserted 404 new tour package(s)...` confirming the repair ran.
4. Hard-refresh / clear your browser's `localhost` cookies before testing if
   you've been actively developing against this app in the same browser —
   see Root Cause #1 below for why this matters for this specific fix.

---

## Root causes found (in order of impact)

### 1. The 400+ package catalog (incl. Bangladesh/Cox's Bazar) was never actually being seeded — schema drift

**Symptom:** "No packages found" for Cox's Bazar and most destinations;
`destinations.php` blank page; only the original ~9 demo packages ever
appeared.

**Root cause:** `tbltourpackages` (and `tblusers`, `tblbooking`, `tblissues`,
`tblpages`) already existed in your database from the original `tms.sql`
install, with a narrower set of columns. Later code added
`CREATE TABLE IF NOT EXISTS tbltourpackages (... PackageDescription,
PackageDuration, MapLat, MapLng, AvgRating, Status ...)` — but because the
table **already existed**, MySQL silently skips the entire statement. Those
columns were therefore never actually added to a real install.

The 400+ package seeder (`data/packages_seed.json` → `tms_seed_tourism_packages()`)
inserts rows using `PackageDescription`. Since that column didn't exist, every
single insert threw a `PDOException`, the whole seeding transaction rolled
back, and the catalog silently stayed frozen at the original demo rows —
forever, because the old code also only *attempted* the seed once per
**session** (see next point), so it never even got a chance to retry.

Separately, `destinations.php` and `package-list.php` query `Country`,
`Region`, `IsFeatured`, `ViewCount` — those particular columns *were* added
correctly via a real `ALTER TABLE`, so they existed; but `language.php`'s
`UPDATE tblusers SET Language=...` hit the same "phantom column" problem
(`tblusers.Language` was declared only inside a `CREATE TABLE IF NOT EXISTS`
that never fired) and silently failed every time, which is why language
switching never stuck.

**Why it looked so inconsistent/hard to pin down:** all of this schema setup
was gated behind `$_SESSION['tms_ai_tables']` / `$_SESSION['tms_db_init']`
flags — run once per browser session, then never again. If your browser
already had a session cookie from *before* a given column was added to the
code, it would never re-attempt, so the bug could resurface or persist even
after a code fix, until you happened to clear cookies or open a private
window. This explains why the same destination could appear to "work" in one
tab/session and not another.

**Fix (`includes/config.php`):**
- New `tms_ensure_columns()` / `_tms_repair_schema_drift()`: checks
  `information_schema` for the real column list of each affected table and
  adds anything missing, **on every request** (a single fast indexed query,
  not gated by session state — fully self-healing regardless of cookies or
  deploy history).
- Failures are now logged via `error_log()` instead of being silently
  swallowed by an empty `catch (Exception $e) {}`.
- `tms_seed_tourism_packages()`'s "stop if ≥100 rows" guard (which could get
  permanently stuck on a partial/failed seed) now compares against the actual
  seed file size, and re-running it is safe/idempotent thanks to the existing
  per-`PackageName` dedupe check.
- Added `schema_repair_migration.sql` for admins who'd rather apply this
  explicitly via phpMyAdmin.

---

### 2. `destinations.php` blank page

**Root cause:** same schema-drift class as above — an uncaught
`PDOException` from a query touching a column that (depending on your
session's migration history) hadn't actually been created, combined with
`error_reporting(0)` at the top of the page, which suppresses *all* error
output. Result: PHP halts before printing anything → a truly blank white
page, with nothing in the console or network body to explain why.

**Fix:**
- Schema drift fixed (above), removing the underlying trigger.
- **Defense in depth:** `includes/config.php` now registers a
  `register_shutdown_function()` fatal-error handler. If any uncaught fatal
  ever happens again (from this bug or a future one) *before* any real output
  has been sent, the visitor now sees a clean, on-brand "Something went
  wrong" page instead of blank white space — and the real error is always
  written to the PHP error log (`error_log()`), satisfying "no blank pages"
  and "check PHP logs" from the audit brief. It safely skips JSON API
  responses (those already return proper JSON error payloads).
- Every top-level page (37 files) was switched from `error_reporting(0)` —
  which suppresses errors from being logged at all, not just displayed — to
  `error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');`.
  This is the standard production-safe setting: nothing embarrassing is shown
  to visitors, but every warning/notice/fatal is now actually written to your
  PHP error log so problems like this are diagnosable going forward instead
  of invisible.

---

### 3. Explore Mode: "WOW is not defined"

**Root cause:** `explore.php` and `explore-view.php` loaded `wow.min.js` with
the `defer` attribute, but then called `new WOW().init();` from a plain
(non-deferred) inline `<script>` tag. Deferred scripts run *after* the whole
document is parsed; a non-deferred inline `<script>` runs immediately, in
document order. So the inline call executed **before** `wow.min.js` had
loaded, throwing `ReferenceError: WOW is not defined`. Every other page in
the project already used the correct, safe pattern
(`window.addEventListener('load', ...)` + a `typeof WOW !== 'undefined'`
guard) — only these two pages, added more recently, missed it.

**Fix:** both files now use the same safe pattern as the rest of the site.

---

### 4. Explore Mode: "missing ) after argument list" / "Could not parse destination information" (e.g. clicking the Cox's Bazar chip)

**Root cause:** the popular-destination chips were rendered as:
```php
onclick="goToDestination('<?php echo htmlspecialchars($p[0], ENT_QUOTES); ?>', ...)"
```
`htmlspecialchars(..., ENT_QUOTES)` turns a literal apostrophe into the HTML
entity `&#039;`. That's correct for plain HTML text, but here it sits inside
a **JavaScript string literal inside an HTML attribute**. The browser decodes
HTML entities in the attribute *before* handing the text to the JS parser —
so `&#039;` becomes a literal `'` again, which immediately closes the JS
string early. For "Cox's Bazar, Bangladesh" this produced:
```js
goToDestination('Cox's Bazar, Bangladesh', 21.4272, 92.0058, '')
                      ^ string closes here — everything after is a syntax error
```
— exactly the `missing ) after argument list` error from the console, on the
exact chip (Cox's Bazar) shown highlighted/just-clicked in your screenshot.
The same "recently explored" chip block had the identical bug. The same
class of bug also existed in `js/explore.js`'s search-suggestion dropdown
(manual `.replace(/'/g, "\\'")` escaping is fragile and doesn't cover
backslashes or double quotes).

**Fix:**
- `explore.php`: chips now build the onclick string with `json_encode()`
  (produces a properly-escaped JS string literal) and *then*
  `htmlspecialchars(..., ENT_QUOTES)` for the HTML-attribute layer — the
  standard, correct way to embed dynamic data inside inline JS attributes.
- `js/explore.js`: search suggestions no longer build an `onclick="..."`
  string by hand at all. They now use `data-*` attributes plus a single
  delegated `click` listener that reads the original (unescaped) values from
  a JS array — eliminating this entire bug class rather than just patching
  the escaping.

---

### 5. Explore Mode API returning `502 Bad Gateway`

**Root cause (partly code, partly environment):**
- The API was using HTTP `502` for *any* internal failure (AI call failed,
  weather API down, etc.), which is semantically wrong — 502 means "the
  upstream gateway itself returned an invalid response," not "our optional
  AI enrichment step didn't succeed." This made it impossible to tell a real
  outage apart from a soft/expected failure from the browser console alone.
- Separately, your `.env` `GEMINI_API_KEY` (`AQ.Ab8RN6...`) does not match the
  format of a standard Google AI Studio key (those start with `AIza...`). If
  it's invalid/expired, every live AI call to enrich a destination (Bangkok,
  in your screenshot) will fail, while a destination that happens to already
  be cached from an earlier successful call (Tokyo, in your screenshot) keeps
  "working" instantly from cache without ever calling the AI again. That
  fully explains "Tokyo works, Bangkok fails" without there being a
  Bangkok-specific bug in the code — I could not verify this against Google's
  API from this environment (no network access to `googleapis.com` here), so
  please double-check/regenerate the key at aistudio.google.com and confirm.

**Fix:**
- `api/explore.php`: soft/service-level failures now return `503 Service
  Unavailable` (the correct code for "temporarily can't fulfill this"), `502`
  is no longer used incorrectly.
- `includes/config.php`: logs a clear warning at boot if `GEMINI_API_KEY`
  doesn't look like a valid key format, so this is diagnosable from the error
  log instead of a mysterious blanket failure.
- The frontend already had try/catch + user-facing fallback messaging for
  failed API calls (`explore.js`) — verified this remains intact.

---

### 6. Explore Mode: URL always had `country=` empty

**Root cause:** the popular-destination chips passed a hardcoded `''` for
country regardless of which destination was clicked (`explore.php` line 57,
old code): `onclick="goToDestination('...', lat, lng, '')"`.

**Fix:** the `$popularDestinations` array now carries a real country per
entry, and it's passed through correctly.

---

### 7. Search: apostrophe/quote handling

**Root cause:** PDO parameterized queries already handle apostrophes safely
at the SQL level (no injection/escaping issue there). The practical failure
mode is a **character mismatch** between a straight apostrophe (`'`, typed by
most people) and a "smart" curly apostrophe (`'`, commonly auto-inserted by
iOS/Word/some Android keyboards) — a search for one will not `LIKE`-match
data stored with the other.

**Fix:** added `tms_normalize_quotes()` in `includes/config.php`, applied to
the search term in both `destinations.php` and `package-list.php`, so curly
and straight apostrophes/quotes are treated as equivalent regardless of which
variant the visitor typed or which variant is in the database.

*(Once Root Cause #1 is fixed and the seed data is actually in your
database, "Cox's Bazar" — which is stored with a plain straight apostrophe in
`data/packages_seed.json` — will match a plain search out of the box; this
normalization is extra robustness for admin-entered custom packages and
mobile-typed search terms.)*

---

### 8. Package images "missing"

**Verified, not a code bug:** every package grid/detail page already has
`onerror="this.src='images/no-image-placeholder.jpg'"` fallback handling
(`destinations.php`, `package-list.php`, `package-details.php`, `index.php`),
and the placeholder file exists. What *is* true: `admin/pacakgeimages/` (the
upload folder for the original 9 demo packages) is currently empty on disk —
those original packages have no real photo files, so they correctly fall
back to the placeholder. This is a missing-asset/content issue, not a code
defect — re-upload cover photos for those packages from the admin panel if
you want real photos instead of the placeholder. The newly-seeded 400+
packages intentionally use the placeholder image too (no stock photos were
bundled with the seed data) — same fix applies if you want real images there.

---

## Files changed (38)

| File | What changed |
|---|---|
| `includes/config.php` | Schema-drift self-repair, fatal-error safety net, seed-guard fix, quote-normalizer, API-key format check, logging instead of silent `catch(){}` |
| `explore.php` | JSON-safe onclick escaping, real country per chip, WOW timing fix |
| `explore-view.php` | WOW timing fix |
| `js/explore.js` | Search suggestions rewritten to use `data-*` attrs + delegated click instead of hand-escaped inline `onclick` strings |
| `api/explore.php` | `502` → `503` for soft/service failures |
| `destinations.php` | Search term quote-normalization |
| `package-list.php` | Search term quote-normalization |
| `schema_repair_migration.sql` *(new)* | Optional standalone SQL version of the schema repair |
| 31 other top-level pages (`index.php`, `package-details.php`, `blog.php`, `profile.php`, `wishlist.php`, `weather.php`, `tour-map.php`, `virtual-guide.php`, `ai-*.php`, `admin/ai-dashboard.php`, etc.) | `error_reporting(0)` → `error_reporting(E_ALL)` + `display_errors=0` + `log_errors=1`, so errors are logged (for real debugging) but never shown to visitors |

Nothing else was touched: no UI/UX, styling, animations, or existing feature
behavior was changed or removed.

---

## What I could verify vs. what I could not (please read this)

This audit was done by reading the actual source code and cross-referencing
it against your screenshots and description — I don't have access to your
live server, database, or a browser to click through the running app, so I
want to be precise about confidence levels:

**High confidence (root cause identified in code, fix is directly targeted at it):**
Items 1–7 above. Each is backed by a specific line of code that contradicts
what the rest of the app expects (e.g. a column referenced in one file that's
never actually created by any migration path).

**Verified by inspection, no code change needed:** image fallback handling
(item 8), JS/PHP syntax across the entire codebase (ran `php -l` and
`node --check` on every file — all clean).

**Could not fully verify (would need your live DB/server/browser):**
- The exact current state of your production database (which columns already
  exist, how many packages are currently seeded) — the fixes are written to
  self-heal regardless of current state, but I can't confirm your before/after
  row counts from here.
- Whether your `GEMINI_API_KEY` is actually valid — I flagged the format
  concern and added logging, but couldn't call Google's API from this
  environment to confirm.
- A full line-by-line localization audit of every string in every page (blog,
  compare, wishlist, booking flow, etc.) — the specific bug I found and fixed
  (`tblusers.Language` never persisting) is the most likely reason "language
  switching is incomplete," but confirming *zero* remaining hardcoded strings
  across the whole app is a large manual sweep I'd recommend doing as a
  follow-up pass now that persistence itself is fixed.
- Full responsive/theme QA across every breakpoint and every page, and a full
  duplicate/orphan-record database integrity audit — these need visual
  browser testing and direct DB access respectively, which I don't have here.

I focused this pass on finding and fixing genuine, evidenced root causes
rather than guessing at things I can't verify. If you can share PHP error
log output after deploying this, or specific pages/screenshots where issues
remain, I can keep going from there with the same level of rigor.
