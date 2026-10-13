

## Round 115, Phase F — AI Dynamic Trip Replanning (weather-triggered, explicit approval only)

**Trigger:** master prompt Phase 12 — propose itinerary changes when
weather conditions change, but the traveler must explicitly approve every
change, and a confirmed booking must never be auto-modified.

**Investigated first:** confirmed the prerequisite pieces already exist —
a real weather-provider abstraction (`WeatherProviderInterface`,
`OpenWeatherMapProvider`/`OpenMeteoProvider`) and `ItineraryPlanner`
already builds a live weather context (`buildWeatherContext()`) when
*generating* an itinerary, including a `risk_alerts` field. What did NOT
exist: any way to re-check an *already-saved* itinerary against *current*
weather, or any approval step before a regenerated itinerary gets saved —
`generate()`/`regenerate()` always persisted immediately, which is fine
for their existing callers (`ai-planner.php`, `custom-trip.php` — ad-hoc
drafts, nothing to overwrite) but would have been exactly the "silently
changes something the traveler already saw" problem Phase 12 explicitly
warns against if reused as-is for a real saved trip itinerary.

**Design — three separate, explicit steps, so the approval requirement is
true by construction rather than by a comment promising it:**

1. **Check** (`api/ai.php?action=weather_replan_check` →
   `ItineraryPlanner::checkWeatherRisk()`, new) — pure read-only detection,
   **no AI call, nothing written anywhere**. Reuses the exact same live-
   forecast fetch `buildWeatherContext()` already uses for generation
   (exposed its raw per-day forecast via a new `relevant_days` key, purely
   additive), cross-references it against the saved itinerary's day
   numbers by date, and flags any day with a forecast rain chance ≥ 60%
   or a live severe-weather alert. Deliberately does **not** try to guess
   whether that day's planned activities are "outdoor" — that would be an
   AI/heuristic guess presented as fact (Rule 3); instead it shows the
   day's own planned activity alongside the forecast and lets the
   traveler judge for themselves.
2. **Preview** (`action=weather_replan_preview` → `regenerate(..., save:
   false)`, new optional parameter threaded through `generate()` and
   `regenerate()`, defaulting to `true` everywhere so every existing
   caller's behavior is unchanged) — generates one proposed alternative
   itinerary, scoped by feedback naming the specific flagged day, and
   returns it **without saving it**.
3. **Apply** (`action=weather_replan_apply` →
   `ItineraryPlanner::saveApprovedItinerary()`, new) — persists exactly
   the itinerary JSON the traveler already saw and clicked "Apply This
   Update" on. No second AI call, no chance of saving something different
   from what was reviewed.

**Frontend (`frontend/my-trip.php`):** a new "Weather Impact Check" card,
shown only when a saved itinerary exists and the trip is upcoming or in
progress (never for a completed or cancelled trip). "Check Weather Impact
on My Itinerary" → flagged days (if any) each get their own "Suggest an
alternative" button → a full day-by-day proposal renders inline, clearly
labeled **"PROPOSED CHANGE — not saved yet"** in an amber box, with
side-by-side **Apply** / **Discard** buttons. Nothing is written until
"Apply" is clicked; "Discard" just clears the preview.

**What this deliberately does NOT touch, by construction:** `tblbooking`
(dates, status, payment, everything that makes a booking "confirmed") is
never referenced by any of the three new handlers — only
`tbl_ai_itineraries`, the same AI-suggestion layer `my-trip.php`'s own
existing disclosure text already tells the traveler is "not a confirmed
itinerary." A weather check or an applied itinerary update can never
change what was actually booked or paid for.

**Files touched (all `php -l` clean; the embedded JS was independently
verified with `node --check` after stripping the PHP interpolation
points; delivered/verified identical on both canonical locations):**
`backend/ai/ItineraryPlanner.php` (new `$save` parameter on
`generate()`/`regenerate()`, new `saveApprovedItinerary()` and
`checkWeatherRisk()` methods, `buildWeatherContext()` now also returns
`relevant_days`), `api/ai.php` (three new actions/handlers),
`frontend/my-trip.php` (new card + JS) — `C:\xampp\htdocs\tms` and
`D:\Project\tms`.

**Implementation status: complete.**

**Testing status: `php -l`/`node --check` clean; not live-tested.** This
genuinely can't be exercised meaningfully in this environment — a real
test needs a booking with a trip start date inside the ~7-day forecast
window at the moment of testing (weather forecasts are time-relative), a
saved itinerary, and a live Gemini/OpenWeatherMap call for the preview
step. The honest next step is trying it against a real near-term booking
on your own site: click "Check Weather Impact," and if any day is
flagged, confirm the full check→preview→apply→reload cycle behaves as
described and that a discarded proposal never appears after a page
refresh.
