<?php
/**
 * TravelRecommender.php — AI-powered destination recommendation engine
 * 
 * Replaces the manual scoring loop in ai-recommend.php with an AI-assisted
 * scoring pipeline (provider-agnostic AIClient, live provider: Google
 * Gemini — see backend/ai/AIClient.php). Docblock corrected Round 108: this
 * previously said "Claude AI", stale documentation left over from an
 * earlier provider (Rule 75).
 * Returns destinations with match scores, reasoning, and structured breakdown.
 */

require_once __DIR__ . '/AIClient.php';
require_once __DIR__ . '/../PricingEngine.php';
require_once __DIR__ . '/../SustainabilityCalculator.php';
require_once __DIR__ . '/../ExploreService.php';

class TravelRecommender {

    private AIClient $ai;
    private PDO      $db;
    private SustainabilityCalculator $sustainability;
    private ExploreService $explore;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
        // Round 100: used only by the new deterministic getMatchScore()
        // below -- never by the AI-driven recommend() path above it.
        $this->sustainability = new SustainabilityCalculator($db);
        $this->explore        = new ExploreService($db);
    }

    /**
     * Get AI-powered destination recommendations.
     * 
     * @param array  $prefs  User preferences (budget, dates, travelers, style, etc.)
     * @param string $email  User email (for personalization + rate limiting)
     * @return array         Structured recommendations with scores
     */
    public function recommend(array $prefs, string $email = 'guest'): array {
        // Fetch available packages to anchor AI recommendations to real inventory
        $packages = $this->getActivePackages();
        $packageSummary = $this->buildPackageSummary($packages);

        // Build user profile context
        $profileContext = $this->getUserProfileContext($email);

        // NEW (master prompt item 67 — Travel Modes): per-mode behavioral
        // guidance for the traveler-type preset (family/couple/solo/
        // business/accessible/group/adventure), appended to the system
        // prompt only when a mode was actually selected. Kept separate from
        // "travel style" -- this is the specific 5(+2)-mode list the master
        // prompt names, not a vague style tag.
        $modeGuidance = $this->travelModeGuidance($prefs['travel_type'] ?? '');
        $modeSection  = $modeGuidance !== ''
            ? "\n\nTRAVEL MODE GUIDANCE (apply this on top of the rules below):\n{$modeGuidance}"
            : '';

        // FIX (Round 113, AI-upgrade priority): every one of these was
        // interpolated directly from $prefs below with no ?? fallback --
        // unlike $modeGuidance/$modeSection just above, which are already
        // built defensively. With APP_DEBUG=true (this project's local-dev
        // default) a caller that omits any one of these optional preference
        // fields gets a PHP warning embedded straight into this JSON-building
        // request. AIEvaluator's own recommender fixture already omits
        // start_date/end_date/accommodation/food_preference and hits this
        // exact gap on every eval run.
        $budget         = $prefs['budget'] ?? 0;
        $travelers      = $prefs['travelers'] ?? 1;
        $startDate      = $prefs['start_date'] ?? 'Flexible';
        $endDate        = $prefs['end_date'] ?? 'Flexible';
        $duration       = $prefs['duration'] ?? '';
        $travelType     = $prefs['travel_type'] ?? 'No preference';
        $climate        = $prefs['climate'] ?? 'No preference';
        $activities     = $prefs['activities'] ?? 'General sightseeing';
        $accommodation  = $prefs['accommodation'] ?? 'No preference';
        $foodPreference = $prefs['food_preference'] ?? 'No preference';

        $system = <<<SYSTEM
You are an expert senior travel consultant at TMS, a premium travel platform specializing in destinations across Bangladesh, South Asia, and international locations.

Your job is to analyze a traveler's profile and recommend the BEST matching destinations and packages from our inventory.

Rules:
1. Only recommend from the provided package inventory
2. Calculate honest match scores — don't inflate scores to please users
3. Always explain WHY a destination is recommended
4. Flag any concerns (weather, budget shortfall, crowd levels)
5. Consider the full travel context: group type, interests, season
6. Return ONLY valid JSON — no markdown, no explanations outside the JSON
7. Never claim or imply a package has an attribute (accessibility features,
   certifications, etc.) that isn't present in the inventory data given to
   you — if you don't know, say so in "warnings" instead of guessing.{$modeSection}
SYSTEM;

        $userMessage = <<<MSG
TRAVELER PROFILE:
- Budget: {$budget} BDT total for {$travelers} travelers
- Travel dates: {$startDate} to {$endDate} ({$duration} days)
- Travel style: {$travelType}
- Climate preference: {$climate}
- Activities wanted: {$activities}
- Accommodation preference: {$accommodation}
- Dietary/food preference: {$foodPreference}
- Previous trips: {$profileContext}

AVAILABLE PACKAGES IN OUR INVENTORY:
$packageSummary

TASK: Recommend exactly 5 best-matching packages from the inventory above.

For each recommendation return this exact JSON structure:
{
  "package_id": <integer from inventory>,
  "destination": "<name>",
  "package_name": "<exact name from inventory>",
  "overall_match": <0-100>,
  "scores": {
    "budget_match": <0-100>,
    "weather_suitability": <0-100>,
    "style_match": <0-100>,
    "safety": <0-100>,
    "experience": <0-100>
  },
  "why_recommended": "<2-3 sentences explaining the match>",
  "best_for": "<who this is ideal for>",
  "estimated_total_cost": <integer in BDT>,
  "cost_savings_tip": "<one actionable tip to save money>",
  "warnings": ["<any concern or caveat>"],
  "highlights": ["<top 3 things to do/see>"],
  "ai_confidence": "<high|medium|low>"
}

Return a JSON array of exactly 5 objects. No other text.
MSG;

        $cacheKey = md5('recommend_v2_' . json_encode($prefs) . '|' . $profileContext); // personalised text must be part of the key
        $result = $this->ai->ask($system, $userMessage, $cacheKey, $email, feature: 'recommender');

        if (!$result['success']) {
            // Fallback to rule-based scoring
            return $this->fallbackRuleBasedRecommend($prefs, $packages);
        }

        $recommendations = $this->ai->parseJson($result['content']);

        if (!$recommendations) {
            return $this->fallbackRuleBasedRecommend($prefs, $packages);
        }

        // NEW (AI-trip-planning round): the system prompt above only ever
        // told the AI to "consider weather suitability" from its own general
        // knowledge -- unlike getMatchScore() below, which grounds the same
        // dimension in a real Open-Meteo forecast via realAvgRainChance().
        // For a trip starting within the ~7-day forecast horizon, this
        // replaces the AI's guessed weather_suitability score with the real
        // one instead, and only for these ~5 recommended packages (not the
        // full 50-package inventory) so it stays cheap. See groundWeatherScores().
        $this->groundWeatherScores($recommendations, $prefs);

        // Enrich with package images from database
        return $this->enrichWithPackageData($recommendations, $packages, $prefs);
    }

    /**
     * Overwrites each recommendation's guessed weather_suitability score with
     * a real one when a genuine forecast is available for the stated travel
     * dates -- reuses realAvgRainChance(), the exact same Open-Meteo call
     * getMatchScore() already trusts for this (Round 100), so this doesn't
     * duplicate that integration. Silently leaves the AI's own score in place
     * for anything outside the ~7-day forecast horizon or where geocoding
     * fails — same fail-soft posture as every other real-data hook in this
     * class (Rule 3: never fabricate, but an honest "we don't know, so we
     * left the estimate as-is" is not a fabrication).
     */
    private function groundWeatherScores(array &$recommendations, array $prefs): void {
        $startDate = (string)($prefs['start_date'] ?? '');
        if ($startDate === '') return;
        $duration = max(1, (int)($prefs['duration'] ?? 1));

        // FIX (Round 113, F1 -- AI-upgrade priority): recommend() always asks
        // for exactly 5 packages, and it's common for several of them to
        // share the same destination string (e.g. two packages both in
        // "Cox's Bazar"). Before this fix, every recommendation triggered
        // its own geocode+forecast pair via realAvgRainChance(), so 5
        // recommendations for 2 distinct destinations meant 5 sequential
        // network round-trips instead of 2. This caches the result per
        // unique destination string within this single call, so repeats
        // are free.
        $avgPopByDestination = [];

        foreach ($recommendations as &$rec) {
            $destination = (string)($rec['destination'] ?? '');
            if ($destination === '') continue;
            if (!array_key_exists($destination, $avgPopByDestination)) {
                $avgPopByDestination[$destination] = $this->realAvgRainChance($destination, $startDate, $duration);
            }
            $avgPop = $avgPopByDestination[$destination];
            if ($avgPop === null) continue; // no real forecast available -- leave the AI's own estimate untouched

            $realScore = (int)round(max(0, 100 - $avgPop));
            if (!isset($rec['scores']) || !is_array($rec['scores'])) $rec['scores'] = [];
            $rec['scores']['weather_suitability'] = $realScore;
            $rec['weather_grounded'] = true;
            $rec['warnings'] = array_values(array_filter((array)($rec['warnings'] ?? [])));
            $rec['warnings'][] = "Weather score updated from a live forecast: {$avgPop}% average rain chance for your travel dates.";
        }
        unset($rec);
    }

    /**
     * Round 100 rewrite (item 31 -- Recommendation Engine's exact 7-factor
     * weighted breakdown: Budget 25%, Weather 20%, Preference 20%,
     * Availability 15%, Rating 10%, Distance 5%, Sustainability 5%). Used
     * on package-details.php to show a personalized match % for a single
     * package.
     *
     * This REPLACES the previous AI-based implementation entirely. That
     * version was dead code -- confirmed by a project-wide grep, it was
     * never actually called from package-details.php or anywhere else,
     * despite its own docblock claiming it was -- and its AI-failure
     * fallback silently returned a FABRICATED score of 75 ("Good match for
     * your profile") with zero real data behind it whenever the AI call
     * didn't return valid JSON. This rewrite can't fail into a fabricated
     * number because it never calls an AI at all: every one of the 7
     * factors below is real, already-existing platform data, computed
     * deterministically -- the same philosophy SustainabilityCalculator's
     * own docblock states ("arithmetic, not a judgment call... an AI has
     * no business inventing or estimating a number here"). A factor whose
     * real input isn't available this call (no stated budget, no travel
     * dates for weather, etc.) is honestly EXCLUDED from the result and
     * its weight is redistributed proportionally across whichever factors
     * DO have real data -- so a call made with less input information
     * gets an honest score computed from what's actually known, never a
     * padded or guessed one.
     *
     * @param array $userPrefs Optional keys: budget, travelers, travel_type,
     *                         start_date (Y-m-d), duration (days).
     * @return array{overall_match: ?int, factors: array<string,array>, method: string}
     */
    public function getMatchScore(int $packageId, array $userPrefs, string $email = 'guest'): array {
        $pkg = null;
        foreach ($this->getActivePackages() as $p) {
            if ((int)$p->PackageId === $packageId) { $pkg = $p; break; }
        }
        if (!$pkg) {
            // getActivePackages() caps at the top 50 by AvgRating -- fall
            // back to a direct lookup so a real, active, just-lower-rated
            // package still gets scored instead of a blanket "not found".
            try {
                $stmt = $this->db->prepare("SELECT * FROM tbltourpackages WHERE PackageId = ? AND Status = 1");
                $stmt->execute([$packageId]);
                $pkg = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
            } catch (Exception $e) { $pkg = null; }
        }
        if (!$pkg) {
            return ['overall_match' => null, 'factors' => [], 'method' => 'deterministic', 'note' => 'Package not found or inactive.'];
        }

        $travelers = max(1, (int)($userPrefs['travelers'] ?? 1));
        $factors = [];

        // 1) Budget fit (25%) -- only when a real budget was stated.
        $budget = (float)($userPrefs['budget'] ?? 0);
        if ($budget > 0) {
            $totalCost = (float)$pkg->PackagePrice * $travelers;
            $score = ($totalCost <= $budget) ? 100 : max(0, 100 - (($totalCost - $budget) / $budget) * 150);
            $factors['budget_match'] = [
                'weight' => 25, 'score' => (int)round($score), 'label' => 'Budget fit',
                'detail' => "Est. {$totalCost} BDT for {$travelers} traveler(s) vs your {$budget} BDT budget",
            ];
        }

        // 2) Weather suitability (20%) -- only with real stated travel dates,
        // reusing the exact same ExploreService Open-Meteo integration
        // ItineraryPlanner/TripCopilot already use -- no second weather call.
        $startDate = (string)($userPrefs['start_date'] ?? '');
        if ($startDate !== '' && !empty($pkg->PackageLocation)) {
            $avgPop = $this->realAvgRainChance($pkg->PackageLocation, $startDate, max(1, (int)($userPrefs['duration'] ?? 1)));
            if ($avgPop !== null) {
                $factors['weather_suitability'] = [
                    'weight' => 20, 'score' => (int)round(max(0, 100 - $avgPop)), 'label' => 'Weather suitability',
                    'detail' => "Average rain chance {$avgPop}% for your dates (real forecast)",
                ];
            }
        }

        // 3) Preference/style match (20%) -- only when a real style was stated,
        // compared against the package's own real PackageType (the only
        // structured "style" field on both sides -- no fabricated attribute).
        //
        // Round 107 fix: a live audit of every PackageType value actually in
        // this catalog (413 packages) found real values like Nature, Beach,
        // Adventure, Heritage, City, Mountain, Wildlife, Island, Religious --
        // destination/theme categories -- plus a small legacy set of literal
        // traveler-composition labels (Family Package, Couple Package, Group
        // Package, Family, Group). ai-recommend.php's "travel style" dropdown
        // offers seven values: family, couple, group, solo, business,
        // accessible, adventure. Four of those ("adventure" via the real
        // Adventure category; "family"/"couple"/"group" via the legacy
        // labels) have a genuine corresponding PackageType somewhere in the
        // catalog. The other three -- "solo", "business", "accessible" --
        // have ZERO matching PackageType anywhere in the data, so comparing
        // against PackageType for those three would read "differs" for
        // literally every package in the catalog: not a real evaluation,
        // just a guaranteed-wrong signal dressed up as one. Rather than
        // fabricate a verdict with nothing real behind it, this factor is
        // honestly omitted for those three styles -- the same "excluded,
        // never guessed, when the real input isn't available" principle
        // already used for every other factor here (Rule 3). Omitting a
        // factor doesn't penalize the package: $availableWeight below only
        // sums the weights of factors actually present, so its 20% is
        // fairly redistributed across whichever real factors did compute.
        $travelType = trim((string)($userPrefs['travel_type'] ?? ''));
        $stylesWithRealCatalogSignal = ['family', 'couple', 'group', 'adventure'];
        if ($travelType !== '' && in_array(strtolower($travelType), $stylesWithRealCatalogSignal, true)) {
            $isMatch = stripos((string)$pkg->PackageType, $travelType) !== false || stripos($travelType, (string)$pkg->PackageType) !== false;
            $factors['preference_match'] = [
                'weight' => 20, 'score' => $isMatch ? 100 : 40, 'label' => 'Preference match',
                'detail' => $isMatch ? "Matches your \"{$travelType}\" style" : "Package type \"{$pkg->PackageType}\" differs from your \"{$travelType}\" preference",
            ];
        }

        // 4) Availability (15%) -- real remaining-seats calculation, same
        // helper used by checkout/operations-dashboard (never a new query).
        $remaining = tms_remaining_seats($this->db, $packageId);
        if ($remaining !== null) {
            $score = $remaining >= $travelers ? 100 : ($remaining > 0 ? ($remaining / $travelers) * 100 : 0);
            $factors['availability'] = [
                'weight' => 15, 'score' => (int)round($score), 'label' => 'Availability',
                'detail' => $remaining > 0 ? "{$remaining} seat(s) left for {$travelers} traveler(s)" : 'No seats currently available',
            ];
        }

        // 5) Rating (10%) -- real AvgRating column.
        if (!empty($pkg->AvgRating) && $pkg->AvgRating > 0) {
            $factors['rating'] = [
                'weight' => 10, 'score' => (int)round(((float)$pkg->AvgRating / 5) * 100), 'label' => 'Rating',
                'detail' => number_format((float)$pkg->AvgRating, 1) . ' / 5 average rating',
            ];
        }

        // 6) Distance (5%) and 7) Sustainability (5%) -- both derived from
        // SustainabilityCalculator's real geocoded round-trip distance (one
        // real lookup serves both factors, not two separate calls).
        try {
            $footprint = $this->sustainability->estimateFootprint((string)$pkg->PackageLocation, $travelers);
            if (!empty($footprint['has_data'])) {
                $km = (float)$footprint['one_way_km'];
                $factors['distance'] = [
                    'weight' => 5, 'score' => (int)round(max(0, 100 - $km / 30)), 'label' => 'Distance',
                    'detail' => round($km) . ' km one-way from Dhaka',
                ];
                $gradeScore = ['A' => 100, 'B' => 80, 'C' => 60, 'D' => 40, 'E' => 20][$footprint['score']['grade'] ?? ''] ?? null;
                if ($gradeScore !== null) {
                    $factors['sustainability'] = [
                        'weight' => 5, 'score' => $gradeScore, 'label' => 'Sustainability',
                        'detail' => $footprint['score']['label'] . ' (grade ' . $footprint['score']['grade'] . ')',
                    ];
                }
            }
        } catch (Exception $e) {
            // No real distance data available this call -- both factors
            // excluded, not faked (same fail-soft philosophy as the class
            // they come from).
        }

        $availableWeight = array_sum(array_column($factors, 'weight'));
        if ($availableWeight === 0) {
            return ['overall_match' => null, 'factors' => $factors, 'method' => 'deterministic', 'note' => 'Not enough real preference data was given to compute a match score.'];
        }
        $weightedSum = 0;
        foreach ($factors as $f) { $weightedSum += $f['score'] * $f['weight']; }

        return [
            'overall_match' => (int)round($weightedSum / $availableWeight),
            'factors'       => $factors,
            'method'        => 'deterministic',
        ];
    }

    /**
     * Real average rain-chance percentage across a trip's date window, from
     * the same live Open-Meteo forecast ExploreService already wraps. Returns
     * null (not 0) when there's no real forecast to check (geocoding miss,
     * dates outside the ~7-day horizon, API failure) -- null means "unknown",
     * never a fabricated "0% chance of rain".
     */
    private function realAvgRainChance(string $destination, string $startDate, int $duration): ?float {
        try {
            $geo = $this->explore->searchDestinations($destination, 1);
            if (empty($geo['success']) || empty($geo['results'][0])) return null;
            $wx = $this->explore->getWeather((float)$geo['results'][0]['lat'], (float)$geo['results'][0]['lng']);
            if (empty($wx['success']) || empty($wx['data']['daily'])) return null;

            $tripStart = strtotime($startDate);
            if ($tripStart === false) return null;
            $tripEnd = $tripStart + ($duration * 86400);

            $relevant = array_values(array_filter($wx['data']['daily'], function ($d) use ($tripStart, $tripEnd) {
                $t = strtotime($d['date']);
                return $t !== false && $t >= $tripStart && $t < $tripEnd;
            }));
            if (!$relevant) return null;

            return round(array_sum(array_column($relevant, 'pop')) / count($relevant), 1);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * NEW (master prompt item 67 — Travel Modes): short behavioral guidance
     * per traveler-type preset, injected into the AI system prompt. Returns
     * '' for an unrecognized/empty mode (no guidance added, not an error).
     * 'accessible' is deliberately the most restrictive entry here: this
     * project's package inventory (tbltourpackages) has no verified
     * per-package accessibility column today, so the instruction is to
     * disclose that honestly rather than let the model infer or invent
     * accessibility support (Rule 3 — never fabricate real-world data).
     */
    private function travelModeGuidance(string $mode): string {
        return match ($mode) {
            'family'     => '- FAMILY trip: favor a comfortable pace and kid-friendly activities; flag anything with long transit legs or very late-night activities.',
            'couple'     => '- COUPLE/honeymoon trip: favor relaxed, romantic pacing and privacy-oriented accommodation.',
            'solo'       => '- SOLO trip: give real weight to safety and ease of getting around alone; call out any genuine safety concerns honestly rather than glossing over them.',
            'business'   => '- BUSINESS trip: favor shorter, efficient itineraries and reliable transport/higher-rated accommodation over leisure-heavy activities.',
            'accessible' => "- ACCESSIBLE-travel request: our package inventory does NOT currently track verified per-package accessibility data (step-free access, sensory accommodations, etc). Do not claim, imply, or guess accessibility support for any package. Explicitly add a warning that accessibility details are unverified and must be confirmed directly with us before booking.",
            'group'      => '- GROUP trip: favor packages/activities that work well for a larger party.',
            'adventure'  => '- ADVENTURE trip: favor active, outdoor-oriented experiences.',
            default      => '',
        };
    }

    /**
     * NEW (master prompt item 67 — Travel Modes): nudges the deterministic
     * fallback scorer's per-dimension scores based on the traveler-type
     * preset, using only real columns already on tbltourpackages
     * (PackageDuration, HotelRating, MealPlan) -- never a fabricated
     * per-package attribute. Public + static so this one piece of logic is
     * independently unit-testable (tests/run.php) without needing the AI
     * client or a live DB connection. Returns the adjusted scores plus an
     * optional honest-disclosure warning (used for 'accessible', where the
     * correct answer is "we don't know" rather than any score at all).
     *
     * @param array  $scores     The scores array as already built by the caller.
     * @param string $travelMode One of family/couple/solo/business/
     *                           accessible/group/adventure, or '' for none.
     * @param object $pkg        A tbltourpackages row (stdClass, PDO::FETCH_OBJ).
     * @return array{scores: array, warning: ?string}
     */
    public static function applyTravelModeAdjustment(array $scores, string $travelMode, object $pkg): array {
        $duration    = (int)($pkg->PackageDuration ?? 0);
        $hotelRating = (float)($pkg->HotelRating ?? 0);
        $mealPlan    = trim((string)($pkg->MealPlan ?? ''));
        $warning     = null;

        switch ($travelMode) {
            case 'business':
                // Shorter trips and higher-rated hotels suit a business
                // traveler better; nudge existing dimensions rather than
                // inventing a "business_fit" column that doesn't exist.
                if ($duration > 0 && $duration <= 5) {
                    $scores['style_match'] = min(100, $scores['style_match'] + 15);
                }
                if ($hotelRating >= 4) {
                    $scores['style_match'] = min(100, $scores['style_match'] + 10);
                }
                break;
            case 'family':
                if ($mealPlan !== '') {
                    // A package that already specifies a meal plan is a real,
                    // verifiable signal of family-oriented full-board service.
                    $scores['style_match'] = min(100, $scores['style_match'] + 10);
                }
                break;
            case 'solo':
                if ($hotelRating >= 4) {
                    $scores['safety'] = min(100, $scores['safety'] + 10);
                }
                break;
            case 'accessible':
                // Rule 3: never fabricate a filter/score for accessibility
                // when we have no real column backing it -- disclose instead.
                $warning = 'Accessibility features are not yet verified per-package — please confirm directly with us before booking if you have specific access needs.';
                break;
            // 'couple', 'group', 'adventure', '', or anything unrecognized:
            // no adjustment -- no real column maps cleanly to these without
            // guessing, so the scores pass through unchanged.
        }

        return ['scores' => $scores, 'warning' => $warning];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getActivePackages(): array {
        try {
            $stmt = $this->db->prepare("SELECT * FROM tbltourpackages WHERE Status=1 ORDER BY AvgRating DESC LIMIT 50");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            return [];
        }
    }

    private function buildPackageSummary(array $packages): string {
        $lines = [];
        foreach ($packages as $p) {
            $lines[] = sprintf(
                '[ID:%d] %s | %s | %s BDT/person | %s | Rating: %s/5',
                $p->PackageId,
                $p->PackageName,
                $p->PackageLocation,
                $p->PackagePrice,
                $p->PackageType ?? 'General',
                $p->AvgRating ?? '4.0'
            );
        }
        return implode("\n", $lines);
    }

    private function getUserProfileContext(string $email): string {
        if ($email === 'guest') return 'First-time user, no history available';
        try {
            // NEW (Round 20, spec item 48 -- Privacy): honor
            // PersonalizationEnabled before reading any booking history into
            // the AI prompt. A missing tblusers row (shouldn't happen for a
            // real logged-in email, but defensively) doesn't opt someone
            // OUT by accident -- only an explicit 0 does, matching the
            // column's own DEFAULT 1.
            $prefStmt = $this->db->prepare("SELECT PersonalizationEnabled FROM tblusers WHERE EmailId = ? LIMIT 1");
            $prefStmt->execute([$email]);
            $personalizationEnabled = $prefStmt->fetchColumn();
            if ($personalizationEnabled !== false && (int)$personalizationEnabled === 0) {
                return 'Personalization disabled by user -- do not use booking history';
            }

            // Get past bookings
            $stmt = $this->db->prepare(
                "SELECT p.PackageName, p.PackageLocation
                 FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
                 WHERE b.UserEmail = ? AND b.status = 1 ORDER BY b.BookingId DESC LIMIT 5"
            );
            $stmt->execute([$email]);
            $bookings = $stmt->fetchAll(PDO::FETCH_OBJ);
            if (empty($bookings)) return 'No previous bookings';
            $trips = array_map(fn($b) => "{$b->PackageName} ({$b->PackageLocation})", $bookings);
            return 'Previously booked: ' . implode(', ', $trips);
        } catch (Exception $e) {
            return 'History unavailable';
        }
    }

    private function enrichWithPackageData(array $recommendations, array $packages, array $prefs = []): array {
        $pkgMap = [];
        foreach ($packages as $p) {
            $pkgMap[(int)$p->PackageId] = $p;
        }
        foreach ($recommendations as &$rec) {
            $id = (int)($rec['package_id'] ?? 0);
            if (isset($pkgMap[$id])) {
                $rec['image']         = $pkgMap[$id]->PackageImage ?? 'images/1.jpg';
                $rec['price']         = $pkgMap[$id]->PackagePrice;
                $rec['duration']      = $pkgMap[$id]->PackageDuration;
                $rec['avg_rating']    = $pkgMap[$id]->AvgRating;
                // Round 99 fix (items 19/31/66 audit): this used to read
                // "?pid={$id}" -- package-details.php only ever reads
                // $_GET['pkgid'] (never 'pid'), and tms_int($_GET['pkgid'] ??
                // 0, 1) clamps a missing value to a MINIMUM of 1, not an
                // error. That meant every "View & Book" click from the AI
                // Travel Recommender silently landed on PackageId=1
                // regardless of which package was actually recommended --
                // confirmed live, not a hypothetical (see SESSION_SUMMARY_
                // INDEX-1.md Round 99).
                // Round 100: carry the visitor's own stated preferences
                // through in the query string so package-details.php's new
                // deterministic getMatchScore() widget (item 31) is
                // personalized to what they actually typed on this form,
                // instead of asking them to re-enter budget/dates/style a
                // second time on the destination page.
                $rec['package_url']   = "package-details.php?pkgid={$id}" . self::buildMatchQueryString($prefs);
            }
        }
        return $recommendations;
    }

    /**
     * Turns real, stated recommender preferences into a query string
     * package-details.php can feed straight into getMatchScore(). Only
     * includes a param when the value is real -- e.g. this form's
     * "start_date" is often the literal placeholder string "Flexible / not
     * yet chosen" (see ai-recommend.php), which is honestly omitted here
     * rather than passed through as a fake date.
     */
    private static function buildMatchQueryString(array $prefs): string {
        $params = [];
        if (!empty($prefs['budget']) && (float)$prefs['budget'] > 0) {
            $params['budget'] = (int)$prefs['budget'];
        }
        if (!empty($prefs['travelers']) && (int)$prefs['travelers'] > 0) {
            $params['travelers'] = (int)$prefs['travelers'];
        }
        if (!empty($prefs['travel_type']) && is_string($prefs['travel_type'])) {
            $params['travel_type'] = $prefs['travel_type'];
        }
        $startDate = (string)($prefs['start_date'] ?? '');
        if ($startDate !== '' && strtotime($startDate) !== false) {
            $params['start_date'] = $startDate;
        }
        if (!empty($prefs['duration']) && (int)$prefs['duration'] > 0) {
            $params['duration'] = (int)$prefs['duration'];
        }
        return $params ? ('&' . http_build_query($params)) : '';
    }

    private function fallbackRuleBasedRecommend(array $prefs, array $packages): array {
        // Simplified rule fallback if AI is unavailable
        $travelMode = (string)($prefs['travel_type'] ?? '');
        // FIX (Round 113, F9 -- same undefined-array-key bug class fixed in
        // recommend() above): this fallback path runs precisely when
        // something already went wrong upstream (AI unavailable), so it's
        // the worst possible place to also throw PHP warnings on an
        // incomplete $prefs array. 'travelers' defaults to 1 (never 0 --
        // would divide/multiply nonsensically below) and 'budget' to 0
        // (no budget filter applied, same as the AI path's "No preference").
        $travelers = max(1, (int)($prefs['travelers'] ?? 1));
        $budget    = (float)($prefs['budget'] ?? 0);
        $results = [];
        foreach ($packages as $pkg) {
            $score = 50;
            if ($budget > 0 && $pkg->PackagePrice * $travelers <= $budget) $score += 30;
            $scores = ['budget_match'=>$score,'weather_suitability'=>70,'style_match'=>65,'safety'=>80,'experience'=>75];
            $warnings = [];

            // NEW (master prompt item 67 — Travel Modes): nudge scores using
            // only real package columns, and surface an honest disclosure
            // warning where a mode (accessible) has no real data to score.
            $adj = self::applyTravelModeAdjustment($scores, $travelMode, $pkg);
            $scores = $adj['scores'];
            if ($adj['warning'] !== null) $warnings[] = $adj['warning'];

            $results[] = [
                'package_id'        => $pkg->PackageId,
                'destination'       => $pkg->PackageLocation,
                'package_name'      => $pkg->PackageName,
                // FIX (Round 113, F14): this used to be
                // `min(95, $score + rand(0, 15))` -- a random 0-15 point
                // boost added to every fallback match score for no reason
                // tied to real data. TravelRecommender's own getMatchScore()
                // docblock states the project's philosophy explicitly: "an
                // AI has no business inventing or estimating a number here"
                // -- the same applies to this deterministic rule-based
                // fallback fabricating one with rand(). $score is already a
                // real, explainable number (50 base + 30 for a real budget
                // fit + travel-mode adjustments); reporting it as-is is
                // honest, reporting score+random is not.
                'overall_match'     => min(95, $score),
                'scores'            => $scores,
                'why_recommended'   => 'This package fits your budget and travel style.',
                'best_for'          => $prefs['travel_type'] ?? 'No preference',
                'estimated_total_cost' => $pkg->PackagePrice * $travelers,
                'cost_savings_tip'  => 'Book at least 2 weeks in advance for best rates.',
                'warnings'          => $warnings,
                'highlights'        => ['Beautiful scenery', 'Local culture', 'Great food'],
                'ai_confidence'     => 'low',
                'image'             => $pkg->PackageImage ?? 'images/1.jpg',
                'price'             => $pkg->PackagePrice,
                'duration'          => $pkg->PackageDuration,
                'avg_rating'        => $pkg->AvgRating ?? 4.0,
                // Round 99 fix: same "?pid=" -> "?pkgid=" bug as the AI path
                // above -- this is the deterministic fallback used when the
                // AI call is unavailable, so it had the identical broken
                // link too.
                // Round 100: same personalized-query-string carry-through as
                // the AI path in enrichWithPackageData() above.
                'package_url'       => "package-details.php?pkgid={$pkg->PackageId}" . self::buildMatchQueryString($prefs),
            ];
        }
        usort($results, fn($a,$b) => $b['overall_match'] <=> $a['overall_match']);
        return array_slice($results, 0, 5);
    }
}
