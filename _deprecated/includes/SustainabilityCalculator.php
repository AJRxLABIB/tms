<?php
/**
 * SustainabilityCalculator.php — Sustainability Scoring & Carbon Footprint
 *
 * Like AchievementEngine, this has ZERO AI involvement by design. A carbon
 * footprint estimate is arithmetic (distance × a published emission factor),
 * not a judgment call — an AI has no business inventing or "estimating" a
 * number here when the actual math is well-defined and more trustworthy.
 *
 * Distance is real (geocoded via ExploreService's existing Nominatim
 * integration + haversine great-circle distance — no new geocoding
 * implementation). Emission factors are widely-published per-passenger-km
 * averages, not invented:
 *   - Domestic short-haul flight: ~255 g CO2e/km  (UK DEFRA/ICAO average for short-haul)
 *   - Car (average occupancy):    ~171 g CO2e/km  (DEFRA average petrol/diesel car, per passenger)
 *   - Bus/coach:                  ~ 68 g CO2e/km  (DEFRA average local bus, per passenger)
 *   - Rail:                       ~ 41 g CO2e/km  (DEFRA average national rail, per passenger)
 * These are the same broad figures widely cited in travel-carbon calculators
 * (source families: UK DEFRA/BEIS greenhouse gas conversion factors, ICAO
 * carbon emissions calculator methodology). They're reasonable, commonly-used
 * reference averages, not a precise measurement of any specific vehicle/flight
 * — the tool is explicit about that rather than presenting false precision.
 */

require_once __DIR__ . '/ExploreService.php';

class SustainabilityCalculator {

    private PDO $db;
    private ExploreService $explore;

    /** Grams of CO2-equivalent per passenger-km. Published reference averages, see class docblock. */
    private const EMISSION_FACTORS = [
        'flight' => 255,
        'car'    => 171,
        'bus'    => 68,
        'train'  => 41,
    ];

    /** Default origin for footprint estimates on a Bangladesh-focused platform. */
    private const DEFAULT_ORIGIN = 'Dhaka, Bangladesh';

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->explore = new ExploreService($db);
    }

    public static function emissionFactors(): array {
        return self::EMISSION_FACTORS;
    }

    /**
     * Real round-trip distance (great-circle, via geocoding) between an
     * origin and a destination. Returns null if either can't be geocoded —
     * fully fail-soft, callers should treat null as "can't estimate right now."
     */
    public function getDistanceKm(string $destination, string $origin = self::DEFAULT_ORIGIN): ?float {
        try {
            $originGeo = $this->explore->searchDestinations($origin, 1);
            $destGeo   = $this->explore->searchDestinations($destination, 1);
            if (empty($originGeo['success']) || empty($originGeo['results'][0])) return null;
            if (empty($destGeo['success']) || empty($destGeo['results'][0])) return null;

            $o = $originGeo['results'][0];
            $d = $destGeo['results'][0];
            $meters = $this->explore->haversine((float)$o['lat'], (float)$o['lng'], (float)$d['lat'], (float)$d['lng']);
            return round($meters / 1000, 1);
        } catch (Exception $e) {
            error_log('[TMS Sustainability] Distance lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Estimated round-trip carbon footprint (in kg CO2e) for each transport
     * mode, for the given number of travelers, plus which modes are even
     * realistic given the real distance (a "bus" option for a 3,000km trip
     * isn't a sensible choice — flagged, not silently offered as equal).
     */
    public function estimateFootprint(string $destination, int $travelers = 1, string $origin = self::DEFAULT_ORIGIN): array {
        $distanceKm = $this->getDistanceKm($destination, $origin);
        if ($distanceKm === null) {
            return ['has_data' => false, 'message' => "Couldn't determine distance to {$destination} right now."];
        }

        $roundTripKm = $distanceKm * 2;
        $byMode = [];
        foreach (self::EMISSION_FACTORS as $mode => $gramsPerKm) {
            $kgPerPerson = round(($roundTripKm * $gramsPerKm) / 1000, 1);
            $byMode[$mode] = [
                'kg_co2e_per_person' => $kgPerPerson,
                'kg_co2e_total'      => round($kgPerPerson * $travelers, 1),
                // Ground transport isn't realistic much beyond ~600km one-way for a typical trip —
                // flagged so the tool doesn't imply a 12-hour bus ride is an equally valid choice.
                'realistic'          => in_array($mode, ['flight']) || $distanceKm <= 600,
            ];
        }

        $lowestMode = null; $lowestKg = PHP_FLOAT_MAX;
        foreach ($byMode as $mode => $data) {
            if (!$data['realistic']) continue;
            if ($data['kg_co2e_total'] < $lowestKg) { $lowestKg = $data['kg_co2e_total']; $lowestMode = $mode; }
        }

        // Carbon offset estimate — reviewer's "carbon offset suggestions" ask.
        // Uses a conservative, publicly-documented voluntary-offset price
        // range (~$10–$20 USD per tonne CO2e is the commonly cited range for
        // verified reforestation/renewable-energy offset projects) rather
        // than a single falsely-precise number. This is informational only
        // — it does NOT process a purchase or claim to be a verified offset
        // itself, since no payment/verification integration with an actual
        // registry exists in this app. Points to real third-party verified
        // registries rather than an in-house "offset" that wouldn't be
        // independently audited.
        $flightKg = $byMode['flight']['kg_co2e_total'] ?? null;
        $offsetEstimate = null;
        if ($flightKg !== null && $flightKg > 0) {
            $tonnes = $flightKg / 1000;
            $offsetEstimate = [
                'tonnes_co2e'   => round($tonnes, 2),
                'est_cost_usd_low'  => round($tonnes * 10, 2),
                'est_cost_usd_high' => round($tonnes * 20, 2),
                'trees_equivalent'  => (int) ceil($flightKg / 21), // ~21kg CO2 absorbed per mature tree per year (commonly cited estimate)
                'note' => 'Estimate only — for a verified offset, use a registry like Gold Standard or Verra rather than an unaudited estimate.',
            ];
        }

        return [
            'has_data'        => true,
            'destination'     => $destination,
            'origin'          => $origin,
            'one_way_km'      => $distanceKm,
            'round_trip_km'   => $roundTripKm,
            'travelers'       => $travelers,
            'by_mode'         => $byMode,
            'lowest_footprint_mode' => $lowestMode,
            'lowest_footprint_kg'   => $lowestMode ? $byMode[$lowestMode]['kg_co2e_total'] : null,
            'offset_estimate' => $offsetEstimate,
            'score'           => $this->scoreFromDistance($distanceKm),
        ];
    }

    /**
     * A simple, transparent A–E sustainability grade based purely on
     * round-trip distance (shorter = fewer emissions regardless of mode
     * chosen, so distance is the single biggest lever a traveler controls
     * by picking a destination). Bands are documented plainly, not a black
     * box — a traveler can see exactly why a destination scored what it did.
     */
    private function scoreFromDistance(float $oneWayKm): array {
        $bands = [
            ['max' => 150,  'grade' => 'A', 'label' => 'Very Low Footprint', 'color' => '#059669'],
            ['max' => 400,  'grade' => 'B', 'label' => 'Low Footprint',      'color' => '#65a30d'],
            ['max' => 800,  'grade' => 'C', 'label' => 'Moderate Footprint', 'color' => '#d97706'],
            ['max' => 1500, 'grade' => 'D', 'label' => 'High Footprint',     'color' => '#ea580c'],
            ['max' => PHP_FLOAT_MAX, 'grade' => 'E', 'label' => 'Very High Footprint', 'color' => '#dc2626'],
        ];
        foreach ($bands as $band) {
            if ($oneWayKm <= $band['max']) return $band;
        }
        return end($bands);
    }
}
