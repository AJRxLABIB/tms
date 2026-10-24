<?php
/**
 * BudgetOptimizer.php — AI-powered travel budget planner and optimizer
 *
 * Generates min/recommended/premium budget breakdowns,
 * savings recommendations, and date-based cost optimization.
 */

require_once __DIR__ . '/AIClient.php';

class BudgetOptimizer {

    private AIClient $ai;
    private PDO      $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
    }

    /**
     * Generate a full budget plan with three tiers.
     */
    public function generateBudgetPlan(array $tripParams, string $email = 'guest'): array {
        $system = <<<SYSTEM
You are an expert travel budget analyst specializing in South Asian travel, particularly Bangladesh and neighboring countries.

You produce accurate, realistic budget estimates based on current market rates.
You know typical costs for hotels, transport, food, and activities in these regions.
Return ONLY valid JSON. No other text.
SYSTEM;

        $dest      = $tripParams['destination'] ?? 'Bangladesh';
        $days      = $tripParams['duration'] ?? 3;
        $travelers = $tripParams['travelers'] ?? 2;
        $style     = $tripParams['travel_type'] ?? 'leisure';

        // Ground the AI's tier numbers in real catalog prices for this
        // destination where we have them, instead of letting three budget
        // tiers float entirely on the AI's general knowledge with no anchor
        // to what packages on this actual platform actually cost.
        $marketLine = '';
        $comparables = $this->fetchComparablePackages($dest, null, null, 'location');
        if (count($comparables) >= 2) {
            $prices = array_map(fn($p) => (int)$p->PackagePrice, $comparables);
            $marketLine = "\nREAL PLATFORM DATA: " . count($prices) . " existing packages for {$dest} on this platform range from "
                        . min($prices) . " to " . max($prices) . " BDT (avg " . round(array_sum($prices)/count($prices)) . " BDT total package price, not per-day). "
                        . "Keep your tier estimates realistic relative to this — don't wildly contradict actual market data we already have.\n";
        }

        $userMsg = <<<MSG
Generate a detailed travel budget plan for:
- Destination: {$dest}
- Duration: {$days} days
- Travelers: {$travelers} people
- Travel style: {$style}
{$marketLine}
Return this exact JSON:
{
  "destination": "{$dest}",
  "duration": {$days},
  "travelers": {$travelers},
  "tiers": {
    "budget": {
      "label": "Budget Traveler",
      "daily_per_person": <BDT>,
      "total": <BDT for all travelers all days>,
      "accommodation": <BDT>,
      "food": <BDT>,
      "transport": <BDT>,
      "activities": <BDT>,
      "emergency_fund": <BDT>,
      "miscellaneous": <BDT>,
      "accommodation_type": "<hostel/budget hotel>",
      "food_style": "<street food/local restaurants>",
      "transport_type": "<local bus/shared transport>"
    },
    "recommended": {
      "label": "Smart Traveler",
      "daily_per_person": <BDT>,
      "total": <BDT>,
      "accommodation": <BDT>,
      "food": <BDT>,
      "transport": <BDT>,
      "activities": <BDT>,
      "emergency_fund": <BDT>,
      "miscellaneous": <BDT>,
      "accommodation_type": "<3-star hotel>",
      "food_style": "<mix of local and restaurant>",
      "transport_type": "<private/AC transport>"
    },
    "premium": {
      "label": "Premium Experience",
      "daily_per_person": <BDT>,
      "total": <BDT>,
      "accommodation": <BDT>,
      "food": <BDT>,
      "transport": <BDT>,
      "activities": <BDT>,
      "emergency_fund": <BDT>,
      "miscellaneous": <BDT>,
      "accommodation_type": "<4-5 star hotel/resort>",
      "food_style": "<fine dining + hotel>",
      "transport_type": "<private car/charter>"
    }
  },
  "savings_tips": [
    {"tip": "<actionable tip>", "estimated_savings": "<e.g. 15-20%>"},
    {"tip": "<tip>", "estimated_savings": "<amount>"},
    {"tip": "<tip>", "estimated_savings": "<amount>"},
    {"tip": "<tip>", "estimated_savings": "<amount>"},
    {"tip": "<tip>", "estimated_savings": "<amount>"}
  ],
  "best_value_dates": "<which months/weeks offer best value>",
  "hidden_costs": ["<cost travelers often miss 1>", "<cost 2>", "<cost 3>"],
  "free_activities": ["<free thing to do 1>", "<free thing 2>", "<free thing 3>"],
  "currency_tips": "<exchange rate advice if international>",
  "ai_budget_verdict": "<1-2 sentence overall budget assessment>"
}
MSG;

        $cacheKey = md5('budget_' . json_encode($tripParams));
        $result   = $this->ai->ask($system, $userMsg, $cacheKey, $email, feature: 'budget');

        if (!$result['success']) {
            return $this->fallbackBudget($tripParams);
        }

        $plan = $this->ai->parseJson($result['content']);
        return $plan ?: $this->fallbackBudget($tripParams);
    }

    /**
     * Real, deterministic price prediction for a package — grounded in this
     * platform's actual catalog data, not an LLM guess. Works two ways:
     *   - Existing package: pass $packageId, compares it against comparable
     *     packages (same location, falling back to same type).
     *   - New/hypothetical package (e.g. an admin drafting one): pass
     *     $location/$type directly with $packageId = null.
     *
     * This is the deterministic core of "AI Price Prediction" — the numbers
     * themselves come straight from SQL aggregates over real PackagePrice
     * rows, not from Gemini. An optional one-line AI verdict is layered on
     * top purely for a human-readable summary; if the AI call fails, the
     * real stats are still returned and still useful on their own.
     */
    public function predictPackagePrice(?int $packageId, ?string $location = null, ?string $type = null, string $email = 'admin'): array {
        $ownPrice = null;
        $ownName  = null;

        if ($packageId) {
            $stmt = $this->db->prepare("SELECT PackageName, PackageLocation, PackageType, PackagePrice FROM tbltourpackages WHERE PackageId = ? LIMIT 1");
            $stmt->execute([$packageId]);
            $pkg = $stmt->fetch(PDO::FETCH_OBJ);
            if ($pkg) {
                $ownPrice = (int)$pkg->PackagePrice;
                $ownName  = $pkg->PackageName;
                $location = $location ?: $pkg->PackageLocation;
                $type     = $type ?: $pkg->PackageType;
            }
        }

        // Try location match first (more specific comparison), fall back to
        // type match if too few location comparables exist to be meaningful.
        $comparables = $this->fetchComparablePackages($location, $type, $packageId, 'location');
        $matchBasis  = 'location';
        if (count($comparables) < 3 && $type) {
            $byType = $this->fetchComparablePackages($location, $type, $packageId, 'type');
            if (count($byType) > count($comparables)) {
                $comparables = $byType;
                $matchBasis  = 'type';
            }
        }

        if (empty($comparables)) {
            return [
                'has_data'   => false,
                'message'    => 'No comparable packages in the catalog yet to base a prediction on.',
                'own_price'  => $ownPrice,
            ];
        }

        $prices = array_map(fn($p) => (int)$p->PackagePrice, $comparables);
        sort($prices);
        $count = count($prices);
        $min   = $prices[0];
        $max   = $prices[$count - 1];
        $avg   = (int)round(array_sum($prices) / $count);
        $median = $count % 2 === 0
            ? (int)round(($prices[$count/2 - 1] + $prices[$count/2]) / 2)
            : $prices[intdiv($count, 2)];

        $result = [
            'has_data'      => true,
            'match_basis'   => $matchBasis, // 'location' or 'type'
            'comparable_count' => $count,
            'min_price'     => $min,
            'max_price'     => $max,
            'avg_price'     => $avg,
            'median_price'  => $median,
            'suggested_price' => $median, // median is more robust to outliers than mean for a suggestion
            'own_price'     => $ownPrice,
        ];

        if ($ownPrice) {
            $diffPct = $avg > 0 ? round((($ownPrice - $avg) / $avg) * 100) : 0;
            $result['position'] = $diffPct > 10 ? 'above_market' : ($diffPct < -10 ? 'below_market' : 'at_market');
            $result['diff_percent'] = $diffPct;
        }

        // Optional AI narrative on top of the real numbers — fail-soft, the
        // deterministic stats above are already a complete, useful result on
        // their own if this doesn't succeed.
        try {
            $system = "You are a pricing analyst. Given real comparable-package statistics, write ONE concise sentence of pricing advice. Return ONLY that sentence, no JSON, no quotes.";
            $userMsg = "Package" . ($ownName ? " '{$ownName}'" : '') . " priced at " . ($ownPrice ?: 'TBD')
                     . " BDT. Comparable packages (" . $result['comparable_count'] . " matched by {$matchBasis}): "
                     . "min {$min}, median {$median}, avg {$avg}, max {$max} BDT. One sentence of pricing advice:";
            $aiResult = $this->ai->ask($system, $userMsg, '', $email, feature: 'budget_pricing');
            if ($aiResult['success']) {
                $result['ai_verdict'] = trim($aiResult['content']);
            }
        } catch (Exception $e) { /* fail-soft — deterministic stats stand on their own */ }

        return $result;
    }

    /**
     * Compare user's current budget against AI recommendation.
     * Returns optimization suggestions.
     */
    public function optimizeBudget(array $userBudget, string $destination, string $email = 'guest'): array {
        $system = "You are a travel budget optimizer. Analyze the given budget allocation and suggest improvements. Return JSON only.";

        $totalBudget = array_sum(array_filter($userBudget, 'is_numeric'));

        $userMsg = "Budget for {$destination}: " . json_encode($userBudget) . " Total: {$totalBudget} BDT.
Analyze this allocation and return: {\"overall_assessment\":\"<good/tight/generous>\",\"overspent_categories\":[\"<cat>\"],\"underspent_categories\":[\"<cat>\"],\"recommendations\":[{\"category\":\"<cat>\",\"current\":<amt>,\"suggested\":<amt>,\"reason\":\"<why>\"}],\"potential_savings\":<BDT>,\"savings_percentage\":<pct>}";

        $result = $this->ai->ask($system, $userMsg, '', $email, feature: 'budget_optimize');
        if (!$result['success']) return ['overall_assessment' => 'Looks reasonable', 'recommendations' => []];

        return $this->ai->parseJson($result['content']) ?? ['overall_assessment' => 'Looks reasonable', 'recommendations' => []];
    }

    /**
     * Generate packing list based on destination and trip parameters.
     */
    public function generatePackingList(array $tripParams, string $email = 'guest'): array {
        $system = "You are a travel packing expert. Generate a smart packing list. Return ONLY JSON.";

        $dest = $tripParams['destination'] ?? 'Unknown';
        $days = $tripParams['duration'] ?? 3;

        $userMsg = "Generate a packing list for {$days} days in {$dest}, {$tripParams['travel_type']} trip, weather: {$tripParams['climate']}.
Return: {\"essentials\":[{\"item\":\"<item>\",\"quantity\":\"<qty>\",\"priority\":\"must|should|nice\"}],\"clothing\":[...],\"toiletries\":[...],\"electronics\":[...],\"documents\":[...],\"medicines\":[...],\"destination_specific\":[{\"item\":\"<item>\",\"reason\":\"<why needed here>\"}],\"packing_tips\":[\"<tip>\"]}";

        $cacheKey = md5('packing_' . json_encode($tripParams));
        $result   = $this->ai->ask($system, $userMsg, $cacheKey, $email, feature: 'budget_packing');

        if (!$result['success']) {
            return [
                'essentials' => [['item'=>'Passport/NID','quantity'=>'1','priority'=>'must'],['item'=>'Cash','quantity'=>'Sufficient','priority'=>'must']],
                'clothing'   => [['item'=>'Comfortable clothes','quantity'=>$days . ' sets','priority'=>'must']],
                'documents'  => [['item'=>'Booking confirmation','quantity'=>'1','priority'=>'must']],
                'medicines'  => [['item'=>'Basic first aid','quantity'=>'1 kit','priority'=>'should']],
                'packing_tips' => ['Roll clothes to save space','Pack a day bag separately'],
            ];
        }

        return $this->ai->parseJson($result['content']) ?? [];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function fetchComparablePackages(?string $location, ?string $type, ?int $excludeId, string $basis): array {
        if ($basis === 'location' && $location) {
            $sql = "SELECT PackagePrice FROM tbltourpackages WHERE Status = 1 AND PackagePrice > 0 AND PackageLocation LIKE ?" . ($excludeId ? " AND PackageId != ?" : '');
            $params = ['%' . $location . '%'];
        } elseif ($type) {
            $sql = "SELECT PackagePrice FROM tbltourpackages WHERE Status = 1 AND PackagePrice > 0 AND PackageType = ?" . ($excludeId ? " AND PackageId != ?" : '');
            $params = [$type];
        } else {
            return [];
        }
        if ($excludeId) $params[] = $excludeId;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            return [];
        }
    }

    private function fallbackBudget(array $params): array {
        $days      = $params['duration'] ?? 3;
        $travelers = $params['travelers'] ?? 2;
        $perDay    = 2500;

        return [
            'destination' => $params['destination'] ?? 'Unknown',
            'duration'    => $days,
            'travelers'   => $travelers,
            'tiers' => [
                'budget'      => ['label'=>'Budget Traveler','total'=>$perDay*$days*$travelers*0.6,'daily_per_person'=>$perDay*0.6],
                'recommended' => ['label'=>'Smart Traveler','total'=>$perDay*$days*$travelers,'daily_per_person'=>$perDay],
                'premium'     => ['label'=>'Premium Experience','total'=>$perDay*$days*$travelers*1.8,'daily_per_person'=>$perDay*1.8],
            ],
            'savings_tips' => [['tip'=>'Book 2+ weeks in advance','estimated_savings'=>'10-15%']],
            'ai_budget_verdict' => 'AI budget analysis temporarily unavailable. These are estimated figures.',
        ];
    }
}
