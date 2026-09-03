# Explore Mode — Setup Guide

Explore Mode lets a logged-in user travel/research any destination **without creating
a booking**: destination search, an AI-written destination guide, a live interactive
map with turn-by-turn navigation, nearby places (hotels, restaurants, hospitals,
pharmacies, police, ATMs, gas stations, malls, tourist info), live weather &
forecasts, and a floating AI travel-assistant chatbot.

It does **not** change any existing booking, authentication, admin, or package
functionality — it is purely additive.

---

## 1. What's new

| File | Purpose |
|---|---|
| `explore.php` | Destination search / landing page |
| `explore-view.php` | Destination hub (Overview, Map, Nearby Places, Weather tabs) |
| `includes/ExploreService.php` | Backend service wrapping every live API integration |
| `includes/explore-chatbot.php` | Floating AI chatbot widget markup |
| `api/explore.php` | AJAX endpoint used by `js/explore.js` |
| `js/explore.js` | All client-side logic (search, map, tabs, chat) |
| `css/explore.css` | Styling (reuses existing CSS variables) |
| `explore_migration.sql` | Standalone SQL for the 4 new tables (also auto-runs via `includes/config.php`) |

**Modified files** (small, additive changes only):
- `includes/config.php` — added Explore Mode DB migrations + optional config constants
- `includes/header.php` — added "Explore" nav link + mega-menu entry
- `includes/footer.php` — added "Explore Mode" link under Features
- `index.php` — added a "Book Package vs Explore" choice section, shown **only to logged-in users**, directly under the existing hero (nothing removed)

No existing table, column, route, or behavior was changed or removed.

---

## 2. Zero-config installation (recommended)

Explore Mode works immediately after you pull these files in — **no new API keys
required** and **no manual SQL needed**:

1. Copy all the new/changed files into your existing TMS project, preserving folder structure.
2. Open the site once while logged in as any user (e.g. visit `index.php`). `includes/config.php` will automatically create the 4 new tables (`tbl_explore_destinations`, `tbl_explore_history`, `tbl_explore_chat`, `tbl_explore_cache`) the same way it already bootstraps the AI tables — no manual migration step is required.
3. Log in and click **Explore** in the navbar (or the new "Explore" card on the homepage).

That's it. Explore Mode uses these free, key-less live data sources by default:

| Data | Provider | Key required? |
|---|---|---|
| Destination search / geocoding | Nominatim (OpenStreetMap) | No |
| Weather, forecast, air quality | Open-Meteo | No |
| Nearby places | Overpass API (OpenStreetMap) | No |
| Routing / navigation | OSRM | No |
| Destination overview, attractions, history, culture, food, costs, safety tips, AI chatbot | Google Gemini (already configured in this project for the AI Copilot) | Already set up |

If you'd rather run the SQL migration manually (e.g. on a host where the app
user lacks `CREATE TABLE` rights), import `explore_migration.sql` once via
phpMyAdmin or the `mysql` CLI, the same way you would `AI_MIGRATION.sql`.

---

## 3. Optional upgrades (not required)

Everything below is optional. Explore Mode is fully "live-data" and fully
functional without any of it — these only make specific pieces richer.

### 3a. Google Places API — adds ratings, photos & "open now" status to Nearby Places
1. Go to https://console.cloud.google.com/ → create/select a project.
2. Enable **Places API**.
3. Create an API key (APIs & Services → Credentials).
4. Open `includes/config.php` and set:
   ```php
   define('GOOGLE_PLACES_API_KEY', 'YOUR_KEY_HERE');
   ```
   If this is left blank (default), Nearby Places automatically uses OpenStreetMap
   data instead — still real, live data, just without ratings/photos.

### 3b. Google Maps Directions API — adds public transit directions
1. In the same Google Cloud project, enable **Directions API**.
2. Reuse or create a key, then set in `includes/config.php`:
   ```php
   define('GOOGLE_MAPS_API_KEY', 'YOUR_KEY_HERE');
   ```
   Without this key, the "Public Transport" navigation option will show a clear
   message explaining a key is required and automatically falls back to
   driving/walking directions (via OSRM) — the page never breaks.

### 3c. OpenWeather API key
Reserved (`OPENWEATHER_API_KEY` constant) for a future alternate weather
provider. Explore Mode currently uses Open-Meteo for weather (free, no key,
already includes current conditions, hourly/7-day forecasts and air quality),
so this is not required.

---

## 4. Rate limits & caching

- Guests get 60 Explore API calls/hour/session; logged-in users are unlimited
  (mirrors the existing `api/ai.php` pattern).
- AI-generated destination info is cached for 14 days per destination (`tbl_explore_destinations`).
- Weather is cached 20 minutes, nearby places 6 hours (`tbl_explore_cache`) — this
  keeps the free OSM/Overpass/Open-Meteo services well within their fair-use limits.
- All external calls have connect/response timeouts and fail gracefully with a
  user-facing message instead of a blank page or fatal error if a provider is
  slow or unavailable.

---

## 5. Verifying the install

1. Log in, click **Explore**, search for any city (e.g. "Kyoto").
2. Pick a suggestion → you land on the destination hub.
3. **Overview** tab should populate with AI-written content within a few seconds.
4. **Map & Navigation** tab → click "Locate Me" (browser will ask for location
   permission) → a route/ETA to the destination appears.
5. **Nearby Places** tab → switch categories (Hotels, Restaurants, etc.) → live
   results appear.
6. **Weather** tab → current conditions, hourly and 7-day forecast appear.
7. Click the floating compass/robot chat button (bottom-right) and ask a question.

If any tab shows an error message instead of data, it means that specific
upstream provider (Nominatim/Open-Meteo/Overpass/OSRM/Gemini) was unreachable
or rate-limited at that moment — the rest of the page and site continue to work
normally, and the tab will succeed on retry.
