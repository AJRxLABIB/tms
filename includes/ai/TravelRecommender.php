<?php
/**
 * TravelRecommender.php — AI-powered destination recommendation engine
 * 
 * Replaces the manual scoring loop in ai-recommend.php with Claude AI.
 * Returns destinations with match scores, reasoning, and structured breakdown.
 */

require_once __DIR__ . '/AIClient.php';

class TravelRecommender {

    private AIClient $ai;
    private PDO      $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
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
- Budget: {$prefs['budget']} BDT total for {$prefs['travelers']} travelers
- Travel dates: {$prefs['start_date']} to {$prefs['end_date']} ({$prefs['duration']} days)
- Travel style: {$prefs['travel_type']}
- Climate preference: {$prefs['climate']}
- Activities wanted: {$prefs['activities']}
- Accommodation preference: {$prefs['accommodation']}
- Dietary/food preference: {$prefs['food_preference']}
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

        $cacheKey = md5('recommend_' . json_encode($prefs));
        $result = $this->ai->ask($system, $userMessage, $cacheKey, $email, feature: 'recommender');

        if (!$result['success']) {
            // Fallback to rule-based scoring
            return $this->fallbackRuleBasedRecommend($prefs, $packages);
        }

        $recommendations = $this->ai->parseJson($result['content']);

        if (!$recommendations) {
            return $this->fallbackRuleBasedRecommend($prefs, $packages);
        }

        // Enrich with package images from database
        return $this->enrichWithPackageData($recommendations, $packages);
    }

    /**
     * Get AI match score for a single package against user preferences.
     * Used on package-details.php to show personalized match %.
     */
    public function getMatchScore(int $packageId, array $userPrefs, string $email = 'guest'): array {
        $packages = $this->getActivePackages();
        $pkg = null;
        foreach ($packages as $p) {
            if ((int)$p->PackageId === $packageId) { $pkg = $p; break; }
        }
        if (!$pkg) return ['score' => 0, 'reason' => 'Package not found'];

        $system = "You are a travel match scoring engine. Return only a JSON object with score (0-100) and reason (1 sentence). No other text.";

        $userMsg = "Package: {$pkg->PackageName}, Location: {$pkg->PackageLocation}, Price: {$pkg->PackagePrice} BDT, Type: {$pkg->PackageType}.
User: Budget {$userPrefs['budget']} BDT, {$userPrefs['travelers']} travelers, style {$userPrefs['travel_type']}, climate {$userPrefs['climate']}.
Return: {\"score\": <int>, \"reason\": \"<string>\"}";

        $result = $this->ai->ask($system, $userMsg, 'match_' . $packageId . '_' . md5(json_encode($userPrefs)), $email, feature: 'recommender');
        $data   = $result['success'] ? ($this->ai->parseJson($result['content']) ?? []) : [];
        return ['score' => $data['score'] ?? 75, 'reason' => $data['reason'] ?? 'Good match for your profile'];
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

    private function enrichWithPackageData(array $recommendations, array $packages): array {
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
                $rec['package_url']   = "package-details.php?pkgid={$id}";
            }
        }
        return $recommendations;
    }

    private function fallbackRuleBasedRecommend(array $prefs, array $packages): array {
        // Simplified rule fallback if AI is unavailable
        $travelMode = (string)($prefs['travel_type'] ?? '');
        $results = [];
        foreach ($packages as $pkg) {
            $score = 50;
            if ($pkg->PackagePrice * $prefs['travelers'] <= $prefs['budget']) $score += 30;
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
                'overall_match'     => min(95, $score + rand(0, 15)),
                'scores'            => $scores,
                'why_recommended'   => 'This package fits your budget and travel style.',
                'best_for'          => $prefs['travel_type'],
                'estimated_total_cost' => $pkg->PackagePrice * $prefs['travelers'],
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
                'package_url'       => "package-details.php?pkgid={$pkg->PackageId}",
            ];
        }
        usort($results, fn($a,$b) => $b['overall_match'] <=> $a['overall_match']);
        return array_slice($results, 0, 5);
    }
}
