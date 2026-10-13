import sys

path = sys.argv[1]
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

repls = []

# 1. generate() signature: add optional $save param
old1 = "    public function generate(array $tripData, string $email = 'guest', ?int $bookingId = null): array {"
new1 = ("    // Round 115 (master prompt item 12, \"AI Dynamic Trip Replanning\"):\n"
        "    // $save defaults to true so every existing caller (ai-planner.php,\n"
        "    // custom-trip.php, my-trip.php's own \"Generate an AI Itinerary\") keeps\n"
        "    // behaving exactly as before. The weather-replanning preview flow\n"
        "    // below is the only caller that ever passes false -- it needs a\n"
        "    // proposed itinerary the traveler can look at and explicitly approve\n"
        "    // or reject, never one silently written over their existing saved\n"
        "    // plan the moment it's generated.\n"
        "    public function generate(array $tripData, string $email = 'guest', ?int $bookingId = null, bool $save = true): array {")
repls.append((old1, new1))

# 2. First saveItinerary call site (fallback on AI failure)
old2 = """            $itinerary = $this->fallbackItinerary($tripData);
            $this->saveItinerary($itinerary, $tripData, $email, $bookingId);
            return $itinerary;
        }

        $itinerary = $this->ai->parseJson($result['content']);"""
new2 = """            $itinerary = $this->fallbackItinerary($tripData);
            if ($save) { $this->saveItinerary($itinerary, $tripData, $email, $bookingId); }
            return $itinerary;
        }

        $itinerary = $this->ai->parseJson($result['content']);"""
repls.append((old2, new2))

# 3. Second saveItinerary call site (fallback on parse failure)
old3 = """            $itinerary = $this->fallbackItinerary($tripData);
            $this->saveItinerary($itinerary, $tripData, $email, $bookingId);
            return $itinerary;
        }
"""
new3 = """            $itinerary = $this->fallbackItinerary($tripData);
            if ($save) { $this->saveItinerary($itinerary, $tripData, $email, $bookingId); }
            return $itinerary;
        }
"""
repls.append((old3, new3))

# 4. Third saveItinerary call site (success path)
old4 = """        // Save to database for future reference
        $this->saveItinerary($itinerary, $tripData, $email, $bookingId);

        return $itinerary;
    }"""
new4 = """        // Save to database for future reference -- unless this is a
        // preview-only call (Round 115, weather replanning), which must not
        // touch tbl_ai_itineraries until the traveler explicitly approves it
        // via saveApprovedItinerary() below.
        if ($save) { $this->saveItinerary($itinerary, $tripData, $email, $bookingId); }

        return $itinerary;
    }"""
repls.append((old4, new4))

# 5. regenerate() signature + passthrough
old5 = """    public function regenerate(array $tripData, array $feedback, string $email = 'guest', ?int $bookingId = null): array {
        $feedback = array_values(array_filter(array_map('trim', $feedback), fn($f) => $f !== ''));
        $tripData['special_requests'] = trim(($tripData['special_requests'] ?? '') . ' Regeneration feedback -- the traveler asked for changes: '
            . ($feedback ? implode('; ', $feedback) : 'please provide a meaningfully different alternative (different pacing/options), even without specific feedback.'));
        // Clear cache for fresh generation -- without this, an otherwise
        // identical $tripData hits the exact same cache key as the original
        // generate() call and returns the very same cached itinerary.
        $tripData['_regen'] = time();
        return $this->generate($tripData, $email, $bookingId);
    }"""
new5 = """    public function regenerate(array $tripData, array $feedback, string $email = 'guest', ?int $bookingId = null, bool $save = true): array {
        $feedback = array_values(array_filter(array_map('trim', $feedback), fn($f) => $f !== ''));
        $tripData['special_requests'] = trim(($tripData['special_requests'] ?? '') . ' Regeneration feedback -- the traveler asked for changes: '
            . ($feedback ? implode('; ', $feedback) : 'please provide a meaningfully different alternative (different pacing/options), even without specific feedback.'));
        // Clear cache for fresh generation -- without this, an otherwise
        // identical $tripData hits the exact same cache key as the original
        // generate() call and returns the very same cached itinerary.
        $tripData['_regen'] = time();
        return $this->generate($tripData, $email, $bookingId, $save);
    }

    /**
     * Round 115 (master prompt item 12): persist a proposal the traveler has
     * already seen (via regenerate(..., save: false)) and explicitly chosen
     * to apply. Deliberately takes the itinerary array as-is rather than
     * re-generating it, so what gets saved is exactly what was shown and
     * approved -- never a second, possibly-different AI call.
     */
    public function saveApprovedItinerary(array $itinerary, array $tripData, string $email, ?int $bookingId = null): bool {
        try {
            $this->saveItinerary($itinerary, $tripData, $email, $bookingId);
            return true;
        } catch (Exception $e) {
            error_log('[TMS AI] saveApprovedItinerary failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Round 115 (master prompt item 12, "AI Dynamic Trip Replanning"):
     * read-only weather-vs-itinerary check. Pure detection -- no AI call,
     * nothing written anywhere -- so it's safe to run as often as the
     * traveler wants ("Check Weather Impact" button on my-trip.php) without
     * any risk of silently changing their saved plan. Reuses the exact same
     * live-forecast fetch buildWeatherContext() already uses for generation,
     * so "the weather data itinerary generation was grounded in" and "the
     * weather data this check flags against" are always the same source.
     *
     * A day is flagged when its forecast rain chance is >= 60% or a live
     * severe-weather alert exists for the destination -- a deliberately
     * simple, explicit, stated threshold rather than an AI judgment call
     * about whether that day's activities are "outdoor" (Rule 3: don't
     * present a guess as a fact) -- the traveler sees the day's own planned
     * activities right alongside the forecast and decides for themselves
     * whether it's worth replanning.
     *
     * @return array{has_itinerary: bool, no_forecast_available?: bool, flagged_days: array, checked_at: string}
     */
    public function checkWeatherRisk(int $bookingId, string $destination, string $startDate, int $duration): array {
        $checkedAt = date('c');
        $itinerary = $bookingId > 0 ? $this->getByBookingId($bookingId) : null;
        if (!$itinerary || empty($itinerary['days'])) {
            return ['has_itinerary' => false, 'flagged_days' => [], 'checked_at' => $checkedAt];
        }

        $weatherContext = $this->buildWeatherContext($destination, $startDate, $duration);
        if (empty($weatherContext['relevant_days']) && empty($weatherContext['risk_alerts'])) {
            // Fail-soft, same as generation: too far out for a forecast, or
            // the weather/geocoding lookup failed. Not an error -- just
            // nothing to flag right now.
            return ['has_itinerary' => true, 'flagged_days' => [], 'no_forecast_available' => true, 'checked_at' => $checkedAt];
        }

        $tripStart = strtotime($startDate) ?: time();
        $flagged = [];
        foreach (($weatherContext['relevant_days'] ?? []) as $d) {
            $pop = (int)($d['pop'] ?? 0);
            if ($pop < 60) continue;
            $dayNum = (int)round((strtotime($d['date']) - $tripStart) / 86400) + 1;
            $itinDay = null;
            foreach ($itinerary['days'] as $day) {
                if ((int)($day['day'] ?? 0) === $dayNum) { $itinDay = $day; break; }
            }
            $flagged[] = [
                'day'               => $dayNum,
                'date'              => $d['date'],
                'rain_chance'       => $pop,
                'temp_range'        => ($d['tmin'] ?? '?') . '–' . ($d['tmax'] ?? '?') . '°C',
                'itinerary_title'   => $itinDay['title'] ?? null,
                'itinerary_preview' => !empty($itinDay['schedule'][0]['activity']) ? $itinDay['schedule'][0]['activity'] : null,
            ];
        }

        return [
            'has_itinerary' => true,
            'flagged_days'  => $flagged,
            'checked_at'    => $checkedAt,
        ];
    }"""
repls.append((old5, new5))

# 6. buildWeatherContext: expose the raw per-day forecast alongside the
#    prompt text, so checkWeatherRisk() above can flag specific dates
#    without a second geocode/weather fetch.
old6 = """            $empty['lat'] = $lat;
            $empty['lng'] = $lng;"""
new6 = """            $empty['lat'] = $lat;
            $empty['lng'] = $lng;
            $empty['relevant_days'] = []; // overwritten below on success -- kept here so an early return (weather fetch failure) still has the key."""
repls.append((old6, new6))

old7 = """            return [
                'prompt_block' => implode("\\n", $lines),
                'risk_alerts'  => $riskAlerts,
                'cache_bucket' => $bucket,
                'lat'          => $lat,
                'lng'          => $lng,
            ];"""
new7 = """            return [
                'prompt_block'  => implode("\\n", $lines),
                'risk_alerts'   => $riskAlerts,
                'relevant_days' => $relevantDays, // Round 115: raw per-day forecast, for checkWeatherRisk()'s date-based flagging.
                'cache_bucket'  => $bucket,
                'lat'           => $lat,
                'lng'           => $lng,
            ];"""
repls.append((old7, new7))

missing = []
for i, (old, new) in enumerate(repls, 1):
    n = content.count(old)
    if n != 1:
        missing.append((i, n))
    else:
        content = content.replace(old, new)

if missing:
    print(f"[{path}] ABORTED. bad counts: {missing}")
else:
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    print(f"[{path}] OK: {len(repls)} replacements applied")
