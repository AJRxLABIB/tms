<?php
/**
 * ItineraryPlanner.php — AI-powered complete trip plan generator
 *
 * Generates a full day-by-day itinerary including transport, meals,
 * accommodation schedule, attraction visits, time optimization, costs.
 */

require_once __DIR__ . '/AIClient.php';
require_once __DIR__ . '/../ExploreService.php';
require_once __DIR__ . '/CrowdPredictor.php';
require_once __DIR__ . '/BudgetOptimizer.php';

class ItineraryPlanner {

    private AIClient $ai;
    private PDO      $db;
    private ExploreService $explore;
    private CrowdPredictor $crowd;
    private BudgetOptimizer $budget;

    public function __construct(PDO $db) {
        $this->db      = $db;
        $this->ai      = new AIClient($db);
        // Reuses the exact same Open-Meteo/Nominatim integration Explore Mode
        // already uses — no second geocoding or weather implementation.
        $this->explore = new ExploreService($db);
        $this->crowd   = new CrowdPredictor($db);
        // Round 113 (AI-upgrade priority, quality pass): reuses
        // BudgetOptimizer::fetchComparablePackages() for real market-price
        // grounding — see buildMarketPriceContext() below.
        $this->budget  = new BudgetOptimizer($db);
    }

    /**
     * Generate a complete trip itinerary.
     *
     * @param array  $tripData  Destination, dates, group, budget, preferences
     * @param string $email     User email
     * @return array            Full structured itinerary
     */
    public function generate(array $tripData, string $email = 'guest', ?int $bookingId = null): array {
        $dest      = $tripData['destination'] ?? 'Unknown';
        $duration  = $tripData['duration'] ?? 5;
        $startDate = $tripData['start_date'] ?? date('Y-m-d', strtotime('+7 days'));

        $weatherContext = $this->buildWeatherContext($dest, $startDate, $duration);
        $crowdContext   = $this->buildCrowdContext($dest);
        $marketContext  = $this->buildMarketPriceContext($dest);
        // NEW (AI-trip-planning round): real, verified nearby hotels/restaurants
        // from the same live map data Explore Mode and PlaceDetailsService
        // already use -- see buildNearbyPlacesContext() below for why this
        // exists (hotel_recommendations/restaurant_recommendations were pure
        // AI invention with nothing to check them against).
        $nearbyContext  = $this->buildNearbyPlacesContext($weatherContext['lat'] ?? null, $weatherContext['lng'] ?? null, $dest);

        $system = <<<SYSTEM
You are an expert travel planner at TMS with deep knowledge of Bangladesh, South Asia, and international destinations.

You create hyper-detailed, realistic, time-optimized travel itineraries that feel hand-crafted by a local expert.

Guidelines:
- Be specific: real place names, actual opening hours, realistic travel times
- Balance must-see attractions with hidden gems
- Include meal suggestions (local restaurants where relevant)
- Account for realistic travel times between locations
- Suggest budget alternatives and premium options
- Include emergency contacts for the destination
- Think about group type: family with kids need different pacing than solo adventurers
- You will be given real, live weather data for this destination and trip dates where available.
  Use it concretely: if rain or extreme heat is forecast on a specific day, move outdoor
  activities to a better day or add an indoor alternative, and say so explicitly in that
  day's weather_note and pro_tip — don't just restate the forecast, actually replan around it.
- You may also be given a list of REAL, verified nearby hotels/restaurants (from live map
  data). When it's provided, strongly prefer those exact names for hotel_recommendations
  and restaurant_recommendations over ones from your own general knowledge — they are
  confirmed to actually exist at this location. Only suggest a place NOT on that list if
  none of the listed ones genuinely fit the requested tier, cuisine, or budget.
- You may also be given REAL PLATFORM PRICING DATA (actual prices of packages this
  platform already sells for this destination). When given, ground estimated_cost and
  daily_budget in that real range instead of inventing numbers with no anchor to it —
  don't wildly contradict it without a stated reason (e.g. a much longer/shorter trip).
- Return ONLY valid JSON
SYSTEM;

        $budget    = $tripData['budget'] ?? 0;
        $travelers = $tripData['travelers'] ?? 2;
        $style     = $tripData['travel_type'] ?? 'leisure';
        $activities = implode(', ', (array)($tripData['activities'] ?? ['sightseeing']));
        // FIX (Round 113, AI-upgrade priority): these three were interpolated
        // directly from $tripData below with no ?? fallback, unlike every
        // other field on this same trip-details block. With APP_DEBUG=true
        // (this project's local-dev default -- see .env's own comment) a
        // missing key here is a PHP warning that gets embedded straight into
        // this JSON-building request, corrupting the itinerary API response
        // for any caller that omits one of these three optional fields (e.g.
        // custom-trip.php's ad-hoc planner, which doesn't collect all three).
        // AIEvaluator's own fixtures already exercise exactly this gap.
        $accommodation   = $tripData['accommodation'] ?? 'No preference';
        $transport       = $tripData['transport'] ?? 'No preference';
        $specialRequests = $tripData['special_requests'] ?? 'None';

        $userMessage = <<<MSG
Create a complete {$duration}-day travel itinerary for the following trip:

TRIP DETAILS:
- Destination: {$dest}
- Travel dates: {$startDate} ({$duration} days)
- Travelers: {$travelers} people ({$style} trip)
- Total budget: {$budget} BDT
- Preferred activities: {$activities}
- Accommodation: {$accommodation}
- Transport preference: {$transport}
- Special requests: {$specialRequests}

{$weatherContext['prompt_block']}
{$crowdContext}
{$marketContext}
{$nearbyContext['prompt_block']}

Return this exact JSON structure:
{
  "trip_title": "<creative trip title>",
  "destination": "<destination>",
  "total_duration": <days>,
  "best_visiting_time": "<best months/season to visit this destination and why>",
  "weather_risk_analysis": {
    "summary": "<1-2 sentence overall weather outlook for this specific trip window, grounded in the live data above if provided>",
    "risk_level": "<low|moderate|high>",
    "contingency_plan": "<concrete fallback plan if the forecast risk materializes, e.g. indoor alternatives, rescheduled days>"
  },
  "estimated_cost": {
    "accommodation": <BDT>,
    "transport": <BDT>,
    "food": <BDT>,
    "activities": <BDT>,
    "miscellaneous": <BDT>,
    "total": <BDT>
  },
  "days": [
    {
      "day": 1,
      "date": "<YYYY-MM-DD>",
      "title": "<Day theme e.g. 'Arrival & City Orientation'>",
      "weather_note": "<expected weather & what to wear — use the live forecast above for days it covers, otherwise general seasonal expectation>",
      "schedule": [
        {
          "time": "09:00",
          "activity": "<specific activity>",
          "location": "<exact place name>",
          "duration": "<e.g. 2 hours>",
          "cost": <BDT per person>,
          "notes": "<tips, alternatives>",
          "type": "<sightseeing|meal|transport|accommodation|activity>"
        }
      ],
      "meal_suggestions": {
        "breakfast": "<restaurant or food suggestion>",
        "lunch": "<restaurant or food suggestion>",
        "dinner": "<restaurant or food suggestion>"
      },
      "daily_budget": <BDT>,
      "pro_tip": "<insider tip for the day>"
    }
  ],
  "hotel_recommendations": [
    {"name": "<hotel name>", "tier": "<Budget|Mid-range|Luxury>", "price_range": "<e.g. BDT 2000-4000/night>", "why": "<one-line reason>"}
  ],
  "restaurant_recommendations": [
    {"name": "<restaurant name>", "cuisine": "<cuisine type>", "price_range": "<e.g. BDT 300-600/person>", "must_try": "<signature dish>"}
  ],
  "transportation_suggestions": ["<how to get there and get around, with rough costs>"],
  "packing_essentials": ["<item1>", "<item2>"],
  "emergency_info": {
    "local_police": "<number>",
    "ambulance": "<number>",
    "nearest_hospital": "<name>",
    "embassy_if_abroad": "<contact>"
  },
  "safety_tips": ["<general safety guideline 1>", "<guideline 2>", "<guideline 3>"],
  "money_saving_tips": ["<tip1>", "<tip2>", "<tip3>"],
  "hidden_gems": ["<gem1>", "<gem2>"],
  "ai_notes": "<any important caveats or recommendations from the AI>"
}

Generate ALL {$duration} days in full detail. No other text.
MSG;

        // Cache key includes the weather context's own freshness marker so a
        // materially different forecast (e.g. a storm appearing) produces a
        // fresh itinerary instead of silently reusing a stale cached one —
        // see buildWeatherContext()'s $cacheBucket for what this covers.
        $cacheKey = md5('itinerary_' . json_encode($tripData) . '_' . $weatherContext['cache_bucket']);
        $result   = $this->ai->ask($system, $userMessage, $cacheKey, $email, 90, 85, feature: 'itinerary'); // FIX (live testing, round 19): longer budget than the interactive-chat default -- a full itinerary genuinely needs it, see AIClient::send().

        if (!$result['success']) {
            // FIX (live testing, round 19): this used to `return` here directly,
            // which skipped saveItinerary() below entirely. The AJAX response
            // still came back {success:true} (the fallback template IS a valid
            // itinerary shape), so my-trip.php's JS reloaded the page expecting
            // to see it -- but nothing was ever written to tbl_ai_itineraries,
            // so the reload always landed back on "No itinerary generated for
            // this trip yet." with zero error shown to the user. Every fallback
            // itinerary is now saved too, so a real AI outage still leaves the
            // traveler with the honest, clearly-labeled template (see ai_notes
            // below) instead of the button silently doing nothing.
            $itinerary = $this->fallbackItinerary($tripData);
            $this->saveItinerary($itinerary, $tripData, $email, $bookingId);
            return $itinerary;
        }

        $itinerary = $this->ai->parseJson($result['content']);


        if (!$itinerary || empty($itinerary['days'])) {
            // Same fix as above: a response that came back but didn't parse
            // into valid JSON (e.g. truncated by the output token limit on a
            // long multi-day itinerary) must not be saved silently as if it
            // succeeded -- but it must still be saved as a fallback so the
            // trip page has something to show instead of nothing.
            $itinerary = $this->fallbackItinerary($tripData);
            $this->saveItinerary($itinerary, $tripData, $email, $bookingId);
            return $itinerary;
        }

        // Deterministic, threshold-based alerts (heat/rain/wind) straight from
        // Open-Meteo data — attached regardless of what the AI wrote in its own
        // narrative weather_risk_analysis field, so a real safety-relevant
        // alert is never solely dependent on the LLM remembering to mention it.
        $itinerary['live_weather_alerts'] = $weatherContext['risk_alerts'];

        // NEW (AI-trip-planning round): don't just trust the system prompt's
        // "prefer the real list" instruction -- verify it deterministically.
        // Rule 3 applies here the same way it already does elsewhere in this
        // codebase (e.g. TravelRecommender's inventory-attribute rule): never
        // let the UI present the AI's own self-report of factuality as fact.
        // A place actually present in the live nearby-places data gets marked
        // verified; anything else is honestly marked not verified rather than
        // silently implied to be equally real.
        $itinerary['hotel_recommendations'] = $this->markVerified(
            $itinerary['hotel_recommendations'] ?? [], $nearbyContext['real_hotel_names']
        );
        $itinerary['restaurant_recommendations'] = $this->markVerified(
            $itinerary['restaurant_recommendations'] ?? [], $nearbyContext['real_restaurant_names']
        );

        // Save to database for future reference
        $this->saveItinerary($itinerary, $tripData, $email, $bookingId);

        return $itinerary;
    }

    /**
     * Regenerate itinerary with changed requirements.
     * Includes previous itinerary as context for refinement.
     *
     * FIX (AI-trip-planning round): this was fully implemented but never
     * actually reachable -- a project-wide grep confirmed the "Regenerate"
     * buttons on ai-planner.php and custom-trip.php both just called the
     * same initial-generation function a second time with identical inputs.
     * Since generate()'s cache key is a hash of $tripData plus the weather
     * cache bucket, an unchanged form meant a click on "Regenerate" could
     * silently hand back the exact same cached itinerary -- worse, from the
     * visitor's point of view, than doing nothing. Wiring this in (see
     * api/ai.php's new 'itinerary_regenerate' action) also fixed the missing
     * $bookingId passthrough below, which would have silently unlinked a
     * regenerated plan from the real booking it belonged to.
     */
    public function regenerate(array $tripData, array $feedback, string $email = 'guest', ?int $bookingId = null): array {
        $feedback = array_values(array_filter(array_map('trim', $feedback), fn($f) => $f !== ''));
        $tripData['special_requests'] = trim(($tripData['special_requests'] ?? '') . ' Regeneration feedback -- the traveler asked for changes: '
            . ($feedback ? implode('; ', $feedback) : 'please provide a meaningfully different alternative (different pacing/options), even without specific feedback.'));
        // Clear cache for fresh generation -- without this, an otherwise
        // identical $tripData hits the exact same cache key as the original
        // generate() call and returns the very same cached itinerary.
        $tripData['_regen'] = time();
        return $this->generate($tripData, $email, $bookingId);
    }

    /**
     * Get saved itinerary by booking ID.
     */
    public function getByBookingId(int $bookingId): ?array {
        try {
            $stmt = $this->db->prepare("SELECT itinerary_json FROM tbl_ai_itineraries WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$bookingId]);
            $row = $stmt->fetch(PDO::FETCH_OBJ);
            if (!$row) return null;
            return json_decode($row->itinerary_json, true);
        } catch (Exception $e) {
            return null;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Geocodes the destination and pulls a real forecast to ground the AI's
     * itinerary in actual conditions instead of guessing from general
     * knowledge alone. Reuses ExploreService's existing Nominatim/Open-Meteo
     * integration — no new geocoding or weather API call implemented here.
     *
     * Fully fail-soft: geocoding failure, weather API failure, or a trip that
     * starts beyond Open-Meteo's ~7-day forecast horizon all degrade to an
     * empty prompt block rather than blocking itinerary generation. The
     * system prompt already tells the AI to fall back to general seasonal
     * knowledge when no live data is given.
     *
     * @return array{prompt_block: string, risk_alerts: array, cache_bucket: string, lat: ?float, lng: ?float}
     */
    private function buildWeatherContext(string $destination, string $startDate, int $duration): array {
        $empty = ['prompt_block' => '', 'risk_alerts' => [], 'cache_bucket' => 'no-weather', 'lat' => null, 'lng' => null];
        if (!$destination || $destination === 'Unknown') return $empty;

        try {
            $geo = $this->explore->searchDestinations($destination, 1);
            if (empty($geo['success']) || empty($geo['results'][0])) return $empty;
            $lat = (float)$geo['results'][0]['lat'];
            $lng = (float)$geo['results'][0]['lng'];
            if (!$lat && !$lng) return $empty;
            // NEW (AI-trip-planning round): buildNearbyPlacesContext() below
            // needs these coordinates too -- geocoding is the slow/rate-limited
            // step (Nominatim), so this reuses the one lookup instead of a
            // second one, even if the weather call itself fails below.
            $empty['lat'] = $lat;
            $empty['lng'] = $lng;

            $wx = $this->explore->getWeather($lat, $lng);
            if (empty($wx['success']) || empty($wx['data'])) return $empty;
            $data = $wx['data'];

            $tripStart = strtotime($startDate) ?: time();
            $tripEnd   = $tripStart + ($duration * 86400);

            // Only include forecast days that actually fall inside this trip's
            // date range AND inside what Open-Meteo actually forecast — this is
            // the "intelligent context compression" step: we don't dump all 7
            // days of raw forecast into the prompt when the trip is 20 days
            // away and only 2 of those days would even overlap, and we never
            // send the AI numbers it doesn't need.
            $relevantDays = array_values(array_filter($data['daily'] ?? [], function ($d) use ($tripStart, $tripEnd) {
                $t = strtotime($d['date']);
                return $t !== false && $t >= $tripStart && $t < $tripEnd;
            }));

            $lines = [];
            $lines[] = "LIVE WEATHER DATA for {$destination} (source: Open-Meteo, fetched just now):";
            $lines[] = "Current conditions: {$data['current']['temp']}°C (feels like {$data['current']['feels_like']}°C), "
                      . "{$data['current']['description']}, humidity {$data['current']['humidity']}%.";

            if ($relevantDays) {
                foreach ($relevantDays as $d) {
                    $lines[] = "- {$d['date']}: {$d['tmin']}–{$d['tmax']}°C, rain chance {$d['pop']}%.";
                }
            } else {
                $daysOut = round(($tripStart - time()) / 86400);
                $lines[] = ($daysOut > 7)
                    ? "This trip starts {$daysOut} days from now — beyond real-time forecast range. Use general seasonal/climate knowledge for these dates instead of the current snapshot above."
                    : "No day-by-day forecast available for the exact trip dates — use the current conditions above as a general guide.";
            }

            if (!empty($data['air_quality']['label'])) {
                $lines[] = "Air quality right now: {$data['air_quality']['label']} (US AQI {$data['air_quality']['us_aqi']}).";
            }

            $riskAlerts = $data['alerts'] ?? [];

            // Cache bucket: changes every EXPLORE_CACHE_TTL_WEATHER window (the
            // same TTL the underlying weather cache itself uses), so the
            // itinerary cache naturally goes stale in step with the weather
            // data it was built from — not before, not needlessly after.
            $ttl = defined('EXPLORE_CACHE_TTL_WEATHER') ? EXPLORE_CACHE_TTL_WEATHER : 1200;
            $bucket = 'wx-' . round($lat, 2) . '_' . round($lng, 2) . '-' . intdiv(time(), max(1, $ttl));

            return [
                'prompt_block' => implode("\n", $lines),
                'risk_alerts'  => $riskAlerts,
                'cache_bucket' => $bucket,
                'lat'          => $lat,
                'lng'          => $lng,
            ];
        } catch (Exception $e) {
            error_log('[TMS AI] Weather context build failed (non-fatal): ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * NEW (AI-trip-planning round): real, verified nearby hotels/restaurants
     * from the exact same ExploreService::getNearbyPlaces() integration
     * Explore Mode already uses (Google Places if a key is configured, else
     * OpenStreetMap/Overpass) — no new integration, no new API key.
     *
     * Before this, hotel_recommendations/restaurant_recommendations came
     * entirely from the model's own general knowledge with nothing to check
     * them against — the same category of risk PlaceDetailsService's Round 14
     * rewrite was built to eliminate elsewhere in this app (a named place
     * that may not actually exist, or exist somewhere else entirely). This
     * doesn't ban the AI from suggesting a place outside this list (a real,
     * well-known local institution the nearby-places radius simply missed is
     * still worth suggesting) — it grounds the common case in verified data
     * and lets generate() mark the result deterministically either way (see
     * markVerified() below), rather than trusting the model's own say-so.
     *
     * Fully fail-soft: no coordinates (geocoding failed/skipped) or an
     * Overpass/Google outage both degrade to an empty prompt block and empty
     * name lists — exactly the behavior this app had before this method
     * existed, never a hard failure of itinerary generation.
     *
     * @return array{prompt_block: string, real_hotel_names: string[], real_restaurant_names: string[]}
     */
    private function buildNearbyPlacesContext(?float $lat, ?float $lng, string $destination): array {
        $empty = ['prompt_block' => '', 'real_hotel_names' => [], 'real_restaurant_names' => []];
        if ($lat === null || $lng === null || (!$lat && !$lng)) return $empty;

        try {
            // Round 113 (AI-upgrade priority, speed pass): these two calls
            // (hotels, restaurants) are mutually independent -- neither needs
            // the other's result -- so they now fire concurrently via
            // ExploreService::getNearbyPlacesMulti() instead of one after
            // the other. On a cache miss for both, this roughly halves the
            // network time this method adds to every itinerary generation.
            $places      = $this->explore->getNearbyPlacesMulti($lat, $lng, ['hotel', 'restaurant'], 6000);
            $hotels      = $places['hotel']      ?? ['success' => false];
            $restaurants = $places['restaurant'] ?? ['success' => false];

            $hotelNames      = !empty($hotels['success']) ? array_column(array_slice($hotels['results'], 0, 8), 'name') : [];
            $restaurantNames = !empty($restaurants['success']) ? array_column(array_slice($restaurants['results'], 0, 8), 'name') : [];
            $hotelNames      = array_values(array_unique(array_filter($hotelNames)));
            $restaurantNames = array_values(array_unique(array_filter($restaurantNames)));

            if (!$hotelNames && !$restaurantNames) return $empty;

            $lines = ["REAL NEARBY PLACES for {$destination} (source: live map data, verified to actually exist here):"];
            if ($hotelNames) {
                $lines[] = 'Real hotels/guesthouses nearby: ' . implode(', ', $hotelNames) . '.';
            }
            if ($restaurantNames) {
                $lines[] = 'Real restaurants nearby: ' . implode(', ', $restaurantNames) . '.';
            }

            return [
                'prompt_block'           => implode("\n", $lines),
                'real_hotel_names'       => $hotelNames,
                'real_restaurant_names'  => $restaurantNames,
            ];
        } catch (Exception $e) {
            error_log('[TMS AI] Nearby-places context build failed (non-fatal): ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Deterministically flags whether each AI-suggested hotel/restaurant name
     * matches one of the real, verified names fetched above — see the Rule 3
     * reasoning in buildNearbyPlacesContext()'s docblock. Case-insensitive
     * substring match in both directions so "The Cox's Bazar Grand Hotel"
     * still matches a real listing named just "Cox's Bazar Grand", and vice
     * versa. Never removes or reorders a recommendation — only labels it.
     */
    private function markVerified(array $recommendations, array $realNames): array {
        foreach ($recommendations as &$rec) {
            $name = trim((string)($rec['name'] ?? ''));
            $isVerified = false;
            if ($name !== '') {
                foreach ($realNames as $real) {
                    if ($real === '') continue;
                    if (stripos($name, $real) !== false || stripos($real, $name) !== false) {
                        $isVerified = true;
                        break;
                    }
                }
            }
            $rec['verified'] = $isVerified;
            $rec['source']   = $isVerified ? 'live_map_data' : 'ai_suggestion';
        }
        unset($rec);
        return $recommendations;
    }

    /**
     * Grounds the "best_visiting_time" field in real booking-seasonality
     * data when there's enough of it (see CrowdPredictor::MIN_BOOKINGS_FOR_REAL_DATA),
     * instead of letting it be a pure AI guess with no connection to actual
     * platform activity. Fully fail-soft — an empty string here just means
     * the AI falls back to its own general knowledge for that field, which
     * is what it already did before this existed.
     */
    private function buildCrowdContext(string $destination): string {
        if (!$destination || $destination === 'Unknown') return '';
        try {
            $seasonality = $this->crowd->getBookingSeasonality($destination);
            $total = array_sum($seasonality);
            if ($total < 8) return ''; // too little real data — let the AI use general knowledge, same as before

            arsort($seasonality);
            $monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
            $busiest  = array_map(fn($m) => $monthNames[$m], array_slice(array_keys($seasonality), 0, 2));
            $quietest = array_map(fn($m) => $monthNames[$m], array_slice(array_reverse(array_keys($seasonality)), 0, 2));

            return "REAL BOOKING DATA for {$destination}: based on {$total} actual past bookings on this platform, "
                 . implode('/', $busiest) . " are the busiest months and " . implode('/', $quietest) . " the quietest. "
                 . "Factor this into your best_visiting_time field alongside general climate/festival knowledge.";
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Round 113 (AI-upgrade priority, quality pass): grounds estimated_cost/
     * daily_budget in this platform's real catalog prices for the same
     * destination, the same way BudgetOptimizer::generateBudgetPlan() and
     * predictPackagePrice() already ground THEIR numbers — reuses that exact
     * method (fetchComparablePackages(), widened to public for this) rather
     * than a second, separately-maintained price query. Before this,
     * ItineraryPlanner was the one AI feature in this file's own neighborhood
     * that still let cost numbers float entirely on the model's general
     * knowledge despite the platform already having real prices to check
     * them against — same Rule 3 gap BudgetOptimizer already closed for
     * itself two files over. Fully fail-soft: fewer than 2 comparable
     * packages (not enough for a meaningful range) degrades to an empty
     * string, exactly like the prior behavior with no market context at all.
     */
    private function buildMarketPriceContext(string $destination): string {
        if (!$destination || $destination === 'Unknown') return '';
        try {
            $comparables = $this->budget->fetchComparablePackages($destination, null, null, 'location');
            if (count($comparables) < 2) return '';

            $prices = array_map(fn($p) => (int)$p->PackagePrice, $comparables);
            $min = min($prices);
            $max = max($prices);
            $avg = (int)round(array_sum($prices) / count($prices));

            return "REAL PLATFORM PRICING DATA: " . count($prices) . " existing packages this platform "
                 . "already sells for {$destination} range from {$min} to {$max} BDT total (avg {$avg} BDT). "
                 . "This is the total package price, not per-day — use it to sanity-check estimated_cost/daily_budget.";
        } catch (Exception $e) {
            return '';
        }
    }

    private function saveItinerary(array $itinerary, array $tripData, string $email, ?int $bookingId = null): void {
        try {
            // $bookingId links this AI-generated plan to a real, paid-for
            // booking (see my-trip.php) so getByBookingId() below actually
            // has something to find -- before this, nothing in the codebase
            // ever passed a booking_id here (custom-trip.php's ad-hoc trip
            // planner has no real booking to link to), so every row was
            // saved with booking_id NULL and getByBookingId() was dead code.
            $stmt = $this->db->prepare(
                "INSERT INTO tbl_ai_itineraries (booking_id, user_email, itinerary_json, ai_version, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            // FIX (Round 108, AI-upgrade audit): the fallback literal here used
            // to be 'gemini-1.5-flash', a model config.php:34 explicitly notes
            // is shut down as of mid-2026. GEMINI_MODEL is always defined by
            // config.php before this class can load (AIClient.php requires it
            // transitively), so this branch is unreachable in normal operation --
            // but an unreachable branch that records a dead model name into
            // tbl_ai_itineraries.ai_version is still a latent honesty bug (Rule 3)
            // waiting to trigger the moment someone changes the require order.
            // Fallback now matches AIClient.php:82's own fallback literal.
            $stmt->execute([$bookingId, $email, json_encode($itinerary), defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash']);
        } catch (Exception $e) {
            error_log('[TMS AI] Failed to save itinerary: ' . $e->getMessage());
        }
    }

    private function fallbackItinerary(array $tripData): array {
        $duration = $tripData['duration'] ?? 3;
        $dest     = $tripData['destination'] ?? 'Your Destination';
        $days     = [];
        for ($i = 1; $i <= $duration; $i++) {
            $days[] = [
                'day'   => $i,
                'title' => $i === 1 ? 'Arrival & Orientation' : ($i === $duration ? 'Departure Day' : "Exploration Day $i"),
                'schedule' => [
                    ['time' => '09:00', 'activity' => 'Morning exploration', 'location' => $dest, 'duration' => '2 hours', 'cost' => 500, 'notes' => 'AI unavailable - please try again', 'type' => 'sightseeing'],
                    ['time' => '13:00', 'activity' => 'Lunch break', 'location' => 'Local restaurant', 'duration' => '1 hour', 'cost' => 300, 'notes' => '', 'type' => 'meal'],
                    ['time' => '15:00', 'activity' => 'Afternoon activities', 'location' => $dest, 'duration' => '3 hours', 'cost' => 400, 'notes' => '', 'type' => 'activity'],
                ],
                'daily_budget' => 2000,
                'pro_tip' => 'Ask locals for the best hidden spots.',
            ];
        }
        return [
            'trip_title'  => "Your Trip to $dest",
            'destination' => $dest,
            'total_duration' => $duration,
            'best_visiting_time' => 'Varies by destination — check the Weather Guide for seasonal conditions.',
            'weather_risk_analysis' => [
                'summary' => 'Live weather analysis unavailable right now — check the Weather Guide before you travel.',
                'risk_level' => 'unknown',
                'contingency_plan' => 'Check weather.php for live conditions closer to your travel date.',
            ],
            'live_weather_alerts' => [],
            'estimated_cost'  => ['total' => $tripData['budget'] ?? 0],
            'days'            => $days,
            'hotel_recommendations' => [
                ['name' => 'See Weather/Map guide or contact our travel desk', 'tier' => 'Various', 'price_range' => 'Depends on destination', 'why' => 'AI unavailable — our team can suggest specific hotels'],
            ],
            'restaurant_recommendations' => [],
            'transportation_suggestions' => ['Contact our travel desk for destination-specific transport options.'],
            'packing_essentials' => ['Passport/ID', 'Sunscreen', 'Comfortable shoes', 'First aid kit'],
            'safety_tips' => ['Keep copies of your ID and important documents.', 'Share your itinerary with someone at home.', 'Keep local emergency numbers handy.'],
            'money_saving_tips'  => ['Book in advance', 'Eat local food', 'Use public transport'],
            'hidden_gems'        => ['Ask your hotel concierge for local recommendations'],
            'ai_notes'           => 'AI generation temporarily unavailable. This is a basic template. Please try regenerating.',
        ];
    }
}
