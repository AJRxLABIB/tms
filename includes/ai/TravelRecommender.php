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
SYSTEM;

        $userMessage = <<<MSG
TRAVELER PROFILE:
- Budget: {$prefs['budget']} BDT total for {$prefs['travelers']} travelers
- Travel dates: {$prefs['start_date']} to {$prefs['end_date']} ({$prefs['duration']} days)
- Travel style: {$prefs['travel_type']}
- Climate preference: {$prefs['climate']}
- Activities wanted: {$prefs['activities']}
- Accommodation preference: {$prefs['accommodation']}
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
        $result = $this->ai->ask($system, $userMessage, $cacheKey, $email);

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

        $result = $this->ai->ask($system, $userMsg, 'match_' . $packageId . '_' . md5(json_encode($userPrefs)), $email);
        $data   = $result['success'] ? ($this->ai->parseJson($result['content']) ?? []) : [];
        return ['score' => $data['score'] ?? 75, 'reason' => $data['reason'] ?? 'Good match for your profile'];
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
                $rec['package_url']   = "package-details.php?pid={$id}";
            }
        }
        return $recommendations;
    }

    private function fallbackRuleBasedRecommend(array $prefs, array $packages): array {
        // Simplified rule fallback if AI is unavailable
        $results = [];
        foreach ($packages as $pkg) {
            $score = 50;
            if ($pkg->PackagePrice * $prefs['travelers'] <= $prefs['budget']) $score += 30;
            $results[] = [
                'package_id'        => $pkg->PackageId,
                'destination'       => $pkg->PackageLocation,
                'package_name'      => $pkg->PackageName,
                'overall_match'     => min(95, $score + rand(0, 15)),
                'scores'            => ['budget_match'=>$score,'weather_suitability'=>70,'style_match'=>65,'safety'=>80,'experience'=>75],
                'why_recommended'   => 'This package fits your budget and travel style.',
                'best_for'          => $prefs['travel_type'],
                'estimated_total_cost' => $pkg->PackagePrice * $prefs['travelers'],
                'cost_savings_tip'  => 'Book at least 2 weeks in advance for best rates.',
                'warnings'          => [],
                'highlights'        => ['Beautiful scenery', 'Local culture', 'Great food'],
                'ai_confidence'     => 'low',
                'image'             => $pkg->PackageImage ?? 'images/1.jpg',
                'price'             => $pkg->PackagePrice,
                'duration'          => $pkg->PackageDuration,
                'avg_rating'        => $pkg->AvgRating ?? 4.0,
                'package_url'       => "package-details.php?pid={$pkg->PackageId}",
            ];
        }
        usort($results, fn($a,$b) => $b['overall_match'] <=> $a['overall_match']);
        return array_slice($results, 0, 5);
    }
}
