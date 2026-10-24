# Trip Autopilot

Spot a disruption, propose a revised itinerary, and let the customer approve before anything changes.

## What it does

1. **Detect.** For a booking that is not cancelled and not finished, it looks for:
   - an **admin-verified travel notice** of kind *Disruption* that is active and not expired (see `TRAVEL_NOTICES.md`) and whose
     country name appears as a whole word in the booking's package location (`Paris and Switzerland` matches a *Switzerland* notice);
   - optionally, forecast days flagged by `ItineraryPlanner::checkWeatherRisk()` (real forecast, 60%+ rain chance).
2. **Propose.** If the customer has a saved day-by-day itinerary, the planner is asked for a revised one (preview only, never saved).
   The proposal is stored in `tbl_autopilot_proposals` together with the notice text, source link and checked-on date.
3. **Approve or decline.** The customer sees the notice, its source, and which days would change, on **My Trip**. 
   *Approve* saves the stored proposal as a new itinerary version. *Decline* closes it. Neither touches the booking.

## Guarantees (each covered by `tests/autopilot/`)

- Nothing changes without the customer's click; proposing never writes an itinerary.
- Approve applies the **server-stored** proposal; the browser never sends the plan back.
- Approving changes the saved AI itinerary only. Booking dates, price, payment, refunds and suppliers are never touched.
  The previous itinerary stays as history.
- Only the booking's owner can see, approve or decline it; guests get 401, missing CSRF 403.
- Approving twice saves once. The same event is never proposed again after approve or decline; a re-verified notice counts as a new event.
- A proposal is closed as *expired* if its notice is archived/expired or the trip is cancelled/finished, and as *superseded* if the
  saved itinerary changed after it was made.
- If the AI is unavailable or returns the built-in template, the customer gets an **alert only** (the verified notice, no fake "revised plan").
  An alert cannot be approved, only dismissed.
- Notice text is escaped on the page. The AI prompt quotes it as data and tells the model not to invent facts.

## Running it

- Customers: **Check my trip for disruptions** on My Trip (one check per booking per 5 minutes per session; needs sign-in and CSRF).
- Automatically: `php scripts/check_trip_disruptions.php [--days=30] [--limit=50] [--no-ai]` once a day (Windows Task Scheduler / cron;
  see `SCHEDULED_JOBS.md`). It notifies the customer once per event. `--no-ai` skips the planner and produces alerts only.
- Tests: `php tests/autopilot/run.php` (database logic, AI stubbed) and `php tests/autopilot/http.php [base-url]` (real HTTP, AI not called).

## Honest limits

- Disruptions only exist if an admin has entered a verified notice. There is **no live feed**, no flight-status or route-closure data.
  "No disruption found" means "none our team has verified", and the page says so.
- Country matching is by name in the package location. A package located only at a city (`Paris`) will not match a *France* notice.
- The quality of the revised itinerary depends on the model and is **not** tested by the suite (the planner is stubbed). It is labelled
  an AI suggestion, and prices and venues in it are not part of the booking.
- Approval does not rebook hotels, transport or activities. Real booking changes still go through the normal modification flow.
