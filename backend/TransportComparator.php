<?php
/**
 * TransportComparator.php — Transport System (master prompt item 14)
 *
 * Supports the 9 named modes (walking, bicycle, car, taxi, bus, train,
 * metro, ferry, flight) and the 5 named comparisons (Fastest, Cheapest,
 * Most comfortable, Lowest estimated carbon, Best for family).
 *
 * "Use provider abstractions so map/routing providers can be replaced" is
 * satisfied by reusing ExploreService::getRoute() (already an abstraction
 * over OSRM / Google Directions, built in an earlier round) rather than
 * adding a second, competing routing layer -- Rule 1 (never rebuild a
 * working feature) and Rule 73 (don't overengineer) both point the same
 * way here.
 *
 * HONESTY / RULE 3 (never fabricate real-world data as if verified):
 * This project has no live transport-schedule or fare API for buses,
 * trains, metro, ferries, or flights -- only real-time driving/walking/
 * cycling ROUTES (via OSRM/Google, see ExploreService) and real great-
 * circle DISTANCE (haversine). So every row this class returns carries an
 * explicit `data_source`:
 *   - 'real_route'  : distance AND duration came from an actual routing
 *                     API call for this exact origin/destination today
 *                     (walking, bicycle, car; taxi reuses the car route
 *                     since it drives the same roads).
 *   - 'estimated'   : duration is derived from a real great-circle
 *                     distance but a published/typical average speed for
 *                     that mode (bus, train, metro, ferry, flight) --
 *                     never a real timetable, and the UI must say so.
 * Cost is NEVER shown as an absolute price (this app has no verified fare
 * data for any of these modes/routes/operators/seasons) -- only a
 * relative 1-5 cost TIER per mode, using the well-established general
 * ordering that walking/cycling are free and flights are the most
 * expensive mode for the same distance. "Most comfortable" and "best for
 * family" are the same kind of general, documented travel-planning
 * guidance already used elsewhere in this app (e.g. TravelRecommender's
 * fallback "cost_savings_tip"), not a claim about a specific trip.
 */

require_once __DIR__ . '/ExploreService.php';

class TransportComparator {

    private PDO $db;
    private ExploreService $explore;

    /** The 9 modes named in the master prompt, in the order it lists them. */
    public const MODES = ['walking', 'bicycle', 'car', 'taxi', 'bus', 'train', 'metro', 'ferry', 'flight'];

    /**
     * Grams of CO2-equivalent per passenger-km. Reuses
     * SustainabilityCalculator's exact figures for the 4 modes it already
     * covers (car/bus/train/flight) rather than maintaining a second set
     * of numbers that could silently drift apart. Walking/bicycle are 0 by
     * physical fact, not an estimate. 'taxi' reuses 'car' (same vehicle
     * type). 'metro' reuses 'train' (both electrified fixed-line transit)
     * with that reuse called out explicitly rather than presenting it as a
     * separately-sourced metro figure. 'ferry' has no verified factor in
     * this project and is deliberately left out of this table -- see
     * emissionGrams() below, which returns null for it rather than
     * guessing.
     */
    private const EMISSION_FACTORS = [
        'walking' => 0,
        'bicycle' => 0,
        'car'     => 171,
        'taxi'    => 171,
        'bus'     => 68,
        'train'   => 41,
        'metro'   => 41,
        // 'ferry' intentionally absent -- see emissionGrams()
        'flight'  => 255,
    ];

    /**
     * Typical average speeds (km/h), used ONLY for the modes with no real
     * routing API in this project (bus/train/metro/ferry) to turn a real
     * haversine distance into an honest ESTIMATED duration. These are
     * broad, commonly-cited planning averages (intercity bus/train
     * accounting for stops; urban metro; a typical passenger ferry cruise
     * speed) -- not a schedule for any specific route, and the UI must
     * label them as estimates, never as real timetable data.
     */
    private const TYPICAL_SPEED_KMH = [
        'bus'     => 40,
        'train'   => 55,
        'metro'   => 30,
        'ferry'   => 25,
        // Fallback-only entries for the real-routed modes, used solely when
        // the routing API is unreachable or returned an implausible result
        // (see PLAUSIBLE_SPEED_KMH) -- not the normal path for these modes.
        'walking' => 5,
        'bicycle' => 15,
        'car'     => 45,
        'taxi'    => 45,
    ];

    /** Flight-time estimate inputs: typical short/medium-haul cruise speed
     *  plus a fixed overhead for boarding/taxi/takeoff/landing/deplaning --
     *  the standard back-of-envelope method for estimating flight time from
     *  distance alone, not a specific airline's schedule. */
    private const FLIGHT_CRUISE_KMH = 800;
    private const FLIGHT_OVERHEAD_MIN = 75;

    /**
     * Relative cost tier per mode, 1 (cheapest) to 5 (most expensive), for
     * the SAME distance. This is a general, widely-understood ordering
     * (walking/cycling cost nothing; public transit is cheaper than
     * hiring a car or taxi; flights carry the highest per-trip cost for a
     * comparable distance) -- not a fare quote for any specific route,
     * operator, or season, and never rendered as a currency amount.
     */
    private const COST_TIER = [
        'walking' => 1, 'bicycle' => 1, 'bus' => 2, 'train' => 2,
        'metro' => 2, 'ferry' => 3, 'car' => 4, 'taxi' => 4, 'flight' => 5,
    ];

    /** General comfort tier, 1 (least comfortable) to 5 (most), for a
     *  typical trip of meaningful distance -- documented travel-planning
     *  guidance, not a measurement of any specific vehicle/operator. */
    private const COMFORT_TIER = [
        'walking' => 1, 'bicycle' => 1, 'bus' => 2, 'metro' => 2,
        'ferry' => 3, 'taxi' => 3, 'train' => 4, 'car' => 4, 'flight' => 5,
    ];

    /** "Best for family" tier, 1-5, weighting low physical demand, no
     *  transfers/luggage-handling burden, and predictability -- again
     *  general guidance, not a per-trip judgment. */
    private const FAMILY_TIER = [
        'walking' => 1, 'bicycle' => 1, 'ferry' => 2, 'bus' => 2,
        'metro' => 3, 'taxi' => 4, 'train' => 4, 'car' => 5, 'flight' => 4,
    ];

    /** Beyond this one-way distance (km), a mode stops being a realistic
     *  choice and is flagged rather than silently offered as equal --
     *  same principle SustainabilityCalculator already applies to ground
     *  transport vs. flights. */
    private const MAX_REALISTIC_KM = [
        'walking' => 15, 'bicycle' => 60, 'bus' => 800, 'car' => 1200,
        'taxi' => 300, 'train' => 1500, 'metro' => 40, 'ferry' => 2000,
        // 'flight' has no practical upper bound.
    ];

    /**
     * Plausible average-speed range (km/h) per real-routed mode, used ONLY
     * to sanity-check a "real" route response before trusting it — found
     * necessary live (Round 45): for a long Dhaka→Cox's Bazar test, the
     * walking/cycling routing calls both came back reporting the exact
     * same duration as driving (physically impossible — that implies
     * walking 305km in under 5 hours), most likely because either the
     * configured Google Directions key or the OSRM foot/bike profile
     * silently degrades to a driving-equivalent result for a route this
     * long rather than erroring outright. A response outside this range is
     * discarded and the mode falls through to the honest distance ÷
     * typical-speed ESTIMATE instead of badging implausible data "Live
     * Route" (Rule 3/4 — never present a wrong number as verified just
     * because it came from an API call that returned HTTP 200).
     */
    private const PLAUSIBLE_SPEED_KMH = [
        'walking' => [1, 8],
        'bicycle' => [4, 35],
        'car'     => [3, 140],
        'taxi'    => [3, 140],
    ];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->explore = new ExploreService($db);
    }

    /**
     * Geocodes origin/destination (same Nominatim-backed lookup
     * ExploreService/SustainabilityCalculator already use -- no new
     * geocoding implementation) and builds one comparison row per mode.
     * Returns ['success'=>false,'error'=>...] if either place can't be
     * geocoded, fully fail-soft.
     */
    public function compare(string $origin, string $destination): array {
        $o = $this->geocode($origin);
        $d = $this->geocode($destination);
        if ($o === null) return ['success' => false, 'error' => "Couldn't locate \"$origin\"."];
        if ($d === null) return ['success' => false, 'error' => "Couldn't locate \"$destination\"."];

        $distanceKm = round($this->explore->haversine($o['lat'], $o['lng'], $d['lat'], $d['lng']) / 1000, 1);

        $rows = [];
        foreach (self::MODES as $mode) {
            $rows[$mode] = $this->buildRow($mode, $o, $d, $distanceKm);
        }

        return [
            'success'      => true,
            'origin'       => $o['label'],
            'destination'  => $d['label'],
            'distance_km'  => $distanceKm,
            'modes'        => $rows,
            'best'         => [
                'fastest'          => $this->pickBest($rows, fn($r) => $r['duration_min']),
                'cheapest'         => $this->pickBest($rows, fn($r) => $r['cost_tier']),
                'most_comfortable' => $this->pickBest($rows, fn($r) => -$r['comfort_tier']),
                'lowest_carbon'    => $this->pickBest($rows, fn($r) => $r['emission_grams_per_km'] ?? PHP_INT_MAX),
                'best_for_family'  => $this->pickBest($rows, fn($r) => -$r['family_tier']),
            ],
        ];
    }

    private function geocode(string $place): ?array {
        try {
            $res = $this->explore->searchDestinations($place, 1);
            if (empty($res['success']) || empty($res['results'][0])) return null;
            $r = $res['results'][0];
            return ['lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'label' => $r['name'] ?? $place];
        } catch (Exception $e) {
            error_log('[TourBuddy Transport] Geocode failed for "' . $place . '": ' . $e->getMessage());
            return null;
        }
    }

    private function buildRow(string $mode, array $o, array $d, float $distanceKm): array {
        $realistic = !isset(self::MAX_REALISTIC_KM[$mode]) || $distanceKm <= self::MAX_REALISTIC_KM[$mode];

        // Real routing API for the 3 modes ExploreService/OSRM actually
        // supports; taxi reuses the car/driving route (same roads).
        $routeMode = ['walking' => 'walking', 'bicycle' => 'cycling', 'car' => 'driving', 'taxi' => 'driving'][$mode] ?? null;
        if ($routeMode !== null) {
            $route = $this->explore->getRoute($o['lat'], $o['lng'], $d['lat'], $d['lng'], $routeMode);
            if (!empty($route['success']) && $route['data']['duration_s'] > 0) {
                $durationMin = (int)round($route['data']['duration_s'] / 60);
                $impliedKmh = $distanceKm / ($durationMin / 60);
                [$minKmh, $maxKmh] = self::PLAUSIBLE_SPEED_KMH[$mode] ?? [0, PHP_FLOAT_MAX];
                if ($impliedKmh >= $minKmh && $impliedKmh <= $maxKmh) {
                    return $this->finishRow($mode, $distanceKm, $durationMin, 'real_route', $realistic, $route['data']['source'] ?? 'osrm');
                }
                error_log("[TourBuddy Transport] Discarded implausible '$mode' route: {$distanceKm}km in {$durationMin}min "
                    . "(implied " . round($impliedKmh, 1) . " km/h, outside [$minKmh, $maxKmh]) -- falling back to a distance-based estimate instead.");
            }
            // Routing API unreachable, or returned an implausible result --
            // fall through to the same honest estimate path as the
            // non-routed modes rather than badging bad data "Live Route".
        }

        if ($mode === 'flight') {
            $durationMin = (int)round(($distanceKm / self::FLIGHT_CRUISE_KMH) * 60 + self::FLIGHT_OVERHEAD_MIN);
        } else {
            $speed = self::TYPICAL_SPEED_KMH[$mode] ?? 40;
            $durationMin = (int)round(($distanceKm / $speed) * 60);
        }
        return $this->finishRow($mode, $distanceKm, $durationMin, 'estimated', $realistic, null);
    }

    private function finishRow(string $mode, float $distanceKm, int $durationMin, string $dataSource, bool $realistic, ?string $routeSource): array {
        return [
            'mode'                   => $mode,
            'distance_km'            => $distanceKm,
            'duration_min'           => max(1, $durationMin),
            'data_source'            => $dataSource, // 'real_route' | 'estimated'
            'route_source'           => $routeSource, // 'osrm' | 'google_directions' | null
            'realistic'              => $realistic,
            'cost_tier'              => self::COST_TIER[$mode] ?? 3,
            'comfort_tier'           => self::COMFORT_TIER[$mode] ?? 3,
            'family_tier'            => self::FAMILY_TIER[$mode] ?? 3,
            'emission_grams_per_km'  => $this->emissionGrams($mode),
        ];
    }

    /** Returns null (never a guessed number) for a mode with no verified
     *  factor in this project -- currently just 'ferry'. */
    private function emissionGrams(string $mode): ?int {
        return self::EMISSION_FACTORS[$mode] ?? null;
    }

    /** @param callable $scoreFn (array $row) => number, lower is better */
    private function pickBest(array $rows, callable $scoreFn): ?string {
        $best = null; $bestScore = null;
        foreach ($rows as $mode => $row) {
            if (!$row['realistic']) continue;
            $score = $scoreFn($row);
            if ($score === null) continue;
            if ($bestScore === null || $score < $bestScore) { $bestScore = $score; $best = $mode; }
        }
        return $best;
    }
}
