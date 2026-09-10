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

class ItineraryPlanner {

    private AIClient $ai;
    private PDO      $db;
    private ExploreService $explore;
    private CrowdPredictor $crowd;

    public function __construct(PDO $db) {
        $this->db      = $db;
        $this->ai      = new AIClient($db);
        // Reuses the exact same Open-Meteo/Nominatim integration Explore Mode
        // already uses — no second geocoding or weather implementation.
        $this->explore = new ExploreService($db);
        $this->crowd   = new CrowdPredictor($db);
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
- Return ONLY valid JSON
SYSTEM;

        $budget    = $tripData['budget'] ?? 0;
        $travelers = $tripData['travelers'] ?? 2;
        $style     = $tripData['travel_type'] ?? 'leisure';
        $activities = implode(', ', (array)($tripData['activities'] ?? ['sightseeing']));

        $userMessage = <<<MSG
Create a complete {$duration}-day travel itinerary for the following trip:

TRIP DETAILS:
- Destination: {$dest}
- Travel dates: {$startDate} ({$duration} days)
- Travelers: {$travelers} people ({$style} trip)
- Total budget: {$budget} BDT
- Preferred activities: {$activities}
- Accommodation: {$tripData['accommodation']}
- Transport preference: {$tripData['transport']}
- Special requests: {$tripData['special_requests']}

{$weatherContext['prompt_block']}
{$crowdContext}

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
        $result   = $this->ai->ask($system, $userMessage, $cacheKey, $email);

        if (!$result['success']) {
            return $this->fallbackItinerary($tripData);
        }

        $itinerary = $this->ai->parseJson($result['content']);

        if (!$itinerary || empty($itinerary['days'])) {
            return $this->fallbackItinerary($tripData);
        }

        // Deterministic, threshold-based alerts (heat/rain/wind) straight from
        // Open-Meteo data — attached regardless of what the AI wrote in its own
        // narrative weather_risk_analysis field, so a real safety-relevant
        // alert is never solely dependent on the LLM remembering to mention it.
        $itinerary['live_weather_alerts'] = $weatherContext['risk_alerts'];

        // Save to database for future reference
        $this->saveItinerary($itinerary, $tripData, $email, $bookingId);

        return $itinerary;
    }

    /**
     * Regenerate itinerary with changed requirements.
     * Includes previous itinerary as context for refinement.
     */
    public function regenerate(array $tripData, array $feedback, string $email = 'guest'): array {
        $tripData['special_requests'] = ($tripData['special_requests'] ?? '') . ' Regeneration feedback: ' . implode('; ', $feedback);
        // Clear cache for fresh generation
        $tripData['_regen'] = time();
        return $this->generate($tripData, $email);
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
     * @return array{prompt_block: string, risk_alerts: array, cache_bucket: string}
     */
    private function buildWeatherContext(string $destination, string $startDate, int $duration): array {
        $empty = ['prompt_block' => '', 'risk_alerts' => [], 'cache_bucket' => 'no-weather'];
        if (!$destination || $destination === 'Unknown') return $empty;

        try {
            $geo = $this->explore->searchDestinations($destination, 1);
            if (empty($geo['success']) || empty($geo['results'][0])) return $empty;
            $lat = (float)$geo['results'][0]['lat'];
            $lng = (float)$geo['results'][0]['lng'];
            if (!$lat && !$lng) return $empty;

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
            ];
        } catch (Exception $e) {
            error_log('[TMS AI] Weather context build failed (non-fatal): ' . $e->getMessage());
            return $empty;
        }
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
            $stmt->execute([$bookingId, $email, json_encode($itinerary), defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash']);
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
