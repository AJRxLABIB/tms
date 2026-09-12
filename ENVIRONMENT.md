# Environment variables

All configuration lives in `.env` (real values, git-ignored, blocked from
web access by `.htaccess`, read by `backend/env-loader.php`). `.env.example`
is the template — safe to commit, holds blank placeholders only. Copy it to
`.env` and fill in real values; leaving everything blank keeps the app
running against local XAMPP defaults exactly as it always has.

| Variable | Required? | Purpose |
|---|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Only on a real host | Blank = local XAMPP defaults (`localhost` / `root` / no password / `tms`). Any real host gives you its own values. |
| `GEMINI_API_KEY` | For AI features | Google Gemini key. Without it, AI features (chatbot, itinerary planner, trip copilot, recommendations) run on their honest offline fallback instead of failing. |
| `GEMINI_MODEL` | No | Defaults sensibly if blank. |
| `AI_MAX_TOKENS`, `AI_CACHE_TTL`, `AI_RATE_LIMIT` | No | Tuning knobs for AI cost/latency. |
| `OPENWEATHER_API_KEY` | For live weather | Without it, `weather.php` shows an honest "no live forecast available" message rather than inventing numbers. |
| `WEATHER_CACHE_TTL` | No | Seconds to cache a weather lookup. |
| `GOOGLE_MAPS_API_KEY` | For maps/Street View | Must have the specific Google Cloud APIs you use enabled per-key (Maps Embed API, Places API, etc.) — a valid key doesn't mean every API under it is turned on. |
| `GOOGLE_PLACES_API_KEY` | For place details | Same per-API-activation caveat as above. |
| `SMTP_HOST/PORT/USER/PASS/FROM/FROM_NAME` | For real email | Without these, the app logs what it would have emailed instead of failing (many free hosts block outgoing mail anyway — see `HOSTING_GUIDE.md`). |
| `APP_DEBUG` | No | `true`/`false`. Currently not read anywhere else in the codebase (confirmed via grep) — kept for forward compatibility, not wired to anything yet. |
| `PAYMENT_PROVIDER` | No | Which payment provider `PaymentService` should use. Only `sandbox` actually works today — see `PAYMENTS.md`. |
| `SSLCOMMERZ_*`, `BKASH_*`, `NAGAD_*` | Only once you have a real merchant account | Leave blank. Each provider class refuses to run without ALL of its own required keys present — see `PAYMENTS.md` before filling these in. |

## Rules for this file and `.env`

- Never put a real secret in `.env.example` — it's meant to be committed.
  (This project's history includes exactly this mistake once — see
  `SESSION_SUMMARY_INDEX.md` Round 15 — fixed, but worth remembering why
  this rule exists.)
- `.env` must never be committed — confirm it's in `.gitignore` (it is) and
  never attach/upload it anywhere a stranger could read it.
- If a real secret is ever accidentally exposed (committed, pasted, logged),
  rotate it at the provider immediately — assume it's compromised the
  moment it left `.env`.
