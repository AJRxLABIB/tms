

## Round 115, Phase A — Security hardening (TMS V1 master plan)

**Trigger:** the audit's own Phase 2 recommendation (`AUDIT_REPORT_ROUND15.md`,
Section B/J-CRITICAL) — `.env` had `APP_DEBUG=true` set, and while confirmed
inert at audit time, wiring it up was flagged as the right long-term fix
rather than leaving it permanently dead.

**Investigated first (Rule 4 — verify before asserting), no code changed by
these checks:**
- **Git secret-history scan for `.env`.** `git log --all --diff-filter=A --
  .env` and a full `git ls-tree -r <every commit> | grep -x '\.env'` across
  every commit on every branch both returned nothing — `.env` has **never**
  been committed to this repo. No historical leak found. `.gitignore`
  correctly excludes it going forward (already true, unchanged).
- **Frontend/JS secret-exposure sweep.** Grepped `frontend/`, `js/`, `api/`
  for `GEMINI_API_KEY` / `OPENWEATHER_API_KEY` patterns — the only hits were
  in `frontend/health.php`, which lists provider **names** on an admin-
  facing health page, never the key values. Clean. `GOOGLE_MAPS_API_KEY` is
  intentionally visible client-side in `package-details.php`'s Street View
  embed (`<iframe src="...&key=...">`) and `explore-view.php`'s feature-gate
  check — this is how Google's Maps Embed API is designed to be used (the
  key is meant to be restricted by HTTP referrer in Google Cloud Console,
  not hidden), so no code change was needed there. **Recommendation for the
  user (infra/console task, not a code fix):** confirm this key has an HTTP
  referrer restriction set in Google Cloud Console, since it is genuinely
  public-facing by design.
- **A real, previously-undocumented correction while investigating
  `includes/` reachability for this phase:** the 34+ `admin/*.php` files
  that do a bare `include('includes/config.php')` do **not** actually reach
  the root-level `includes/config.php` — because PHP sets the working
  directory to the directory of the *directly-requested* top-level script
  (confirmed via the project's own working admin panel behavior + the
  already-known content of `admin/includes/config.php`), and admin pages
  are requested directly (no root-shim layer for `admin/`), that bare
  include resolves to **`admin/includes/config.php`** — the clean 6-line
  delegating shim to `backend/config.php` documented in the prior audit.
  Net effect: **root-level `includes/config.php` is genuinely unreached by
  any current code path** (distinct from `includes/header.php`/
  `includes/footer.php`, which remain load-bearing via `backend/landing.php`
  — see the audit's Section A.4, unchanged by this finding). This refines
  rather than contradicts the audit; recorded here for Phase B, which will
  handle the actual cleanup.

**Fixed this round:**

- **`backend/config.php`** — `APP_DEBUG` is now actually wired to something:
  when `false`, it forces `display_errors`/`display_startup_errors` off and
  `log_errors` on (errors still always logged server-side, never silently
  lost); when `true` (this local environment's current, correct setting)
  it changes nothing — `php.ini`'s existing local dev behavior is
  untouched, and the pre-existing fatal-error shutdown handler (friendly
  500 page + `error_log`) is unaffected either way. Previously this
  constant was defined but never consulted anywhere in the codebase
  (confirmed via project-wide grep), so setting it in `.env` did nothing at
  all — this closes that gap without changing today's behavior for anyone.
- **`.env.example`** — default flipped from `APP_DEBUG=true` to
  `APP_DEBUG=false`, since this file is the template new environments
  (including any future production/staging deployment) get copied from; it
  should never model a debug-on default. Comment expanded to point at the
  new wiring and explicitly say local-only `true` belongs in a developer's
  own `.env`, never a shared or deployed one.

**Deliberately left unchanged:** the user's own live local `.env` still has
`APP_DEBUG=true`. This is correct for active local development on this
XAMPP install, and now that the flag actually does something, flipping it
here would have started suppressing inline error display during the user's
own local debugging — a workflow regression nobody asked for. The fix
belongs in the *template* (`.env.example`) and in *future* deployment
`.env` files, not in today's dev environment.

**Files touched (all `php -l` clean via a cloud-side lint pass — the
device's own shell has no `php` binary on PATH — and delivered/verified
identical on both canonical locations):** `backend/config.php`,
`.env.example` — `C:\xampp\htdocs\tms` and `D:\Project\tms`.

**Implementation status: complete** for the two code/template changes
above.

**Testing status: `php -l` clean on both copies; not yet live-tested**
(nothing user-visible changes while `APP_DEBUG=true`, so there's no local
click-through that would exercise the new branch — the honest way to test
it would be temporarily setting `APP_DEBUG=false` in a scratch `.env` and
confirming a deliberately-triggered warning stops printing, which wasn't
done this round to avoid touching the live dev `.env` even temporarily).
Git secret-history scan and frontend-exposure sweep are investigative
findings, not code under test — reported as findings, not claimed as
"tested."
