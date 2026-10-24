<?php
/**
 * PackageGenerator.php — AI Dynamic Package Generator
 * Creates fully personalized travel packages on demand using Google Gemini
 * (via the provider-agnostic AIClient — see backend/ai/AIClient.php and
 * backend/ai/providers/). Docblock corrected Round 108: this previously said
 * "Claude AI", left over from an earlier provider, which was stale/inaccurate
 * documentation (Rule 75) — the live provider has been Gemini since the
 * AIClient abstraction was introduced.
 * Replaces the fixed-package model with intelligent, custom package creation.
 */

require_once __DIR__ . '/AIClient.php';
require_once __DIR__ . '/PackageDraft.php';

class PackageGenerator {

    private AIClient $ai;
    private PDO      $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
    }

    /**
     * Generate a fully personalized AI travel package.
     *
     * @param array  $prefs  User preferences (budget, style, dates, etc.)
     * @param string $email  User email for personalization + rate limiting
     * @return array         Complete package object ready for display
     */
    public function generate(array $prefs, string $email = 'guest'): array {
        $system = <<<SYSTEM
You are a senior travel package designer at TMS, a premium travel platform.

You create hyper-personalized travel packages that feel custom-crafted for each traveler.
Every package should feel UNIQUE — not generic.

Rules:
1. Match destination to budget, style, and dates realistically
2. Be specific: real hotel names/types, actual attractions, real transport options
3. Include a catchy package name that matches the travel style
4. Calculate costs accurately for Bangladesh/South Asia context
5. Return ONLY valid JSON — no markdown, no extra text
SYSTEM;

        // Numbers are cast and bounded; free text is stripped of markup/control characters and length-capped before it
        // reaches the prompt (PackageDraft::text), so a pasted instruction cannot ride along in a field.
        $budget    = max(0, (int)($prefs['budget'] ?? 30000));
        $travelers = max(1, min(50, (int)($prefs['travelers'] ?? 2)));
        $days      = max(1, min(30, (int)($prefs['duration'] ?? 5)));
        $style     = PackageDraft::text($prefs['travel_type'] ?? 'leisure', 40) ?: 'leisure';
        $start     = (isset($prefs['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$prefs['start_date']) && strtotime((string)$prefs['start_date']))
                        ? (string)$prefs['start_date'] : date('Y-m-d', strtotime('+7 days'));
        $climate   = PackageDraft::text($prefs['climate'] ?? 'warm', 30) ?: 'warm';
        $activities = PackageDraft::text(is_array($prefs['activities'] ?? null) ? implode(', ', $prefs['activities']) : ($prefs['activities'] ?? 'sightseeing'), 200) ?: 'sightseeing';
        $accommodation = PackageDraft::text($prefs['accommodation'] ?? 'hotel', 60) ?: 'hotel';
        $prefs = ['budget' => $budget, 'travelers' => $travelers, 'duration' => $days, 'start_date' => $start, 'travel_type' => $style,
                  'destination' => PackageDraft::text($prefs['destination'] ?? '', 100)] + $prefs;
        // FIX (Round 113, F9 -- same undefined-array-key bug class fixed in
        // ItineraryPlanner/BudgetOptimizer/TravelRecommender this round):
        // 'special_requests' is an optional field on every caller of
        // generate() -- unlike every other $prefs key just above, it was
        // interpolated directly into the heredoc below with no fallback.
        $specialRequests = PackageDraft::text($prefs['special_requests'] ?? 'None', 500) ?: 'None';

        $perPerson = intdiv($budget, max(1, (int)$travelers));

        $userMsg = <<<MSG
Design a personalized travel package for:
- Budget: ৳{$budget} total for {$travelers} travelers (৳{$perPerson}/person)
- Duration: {$days} days starting {$start}
- Style: {$style} trip
- Climate preference: {$climate}
- Activities: {$activities}
- Accommodation: {$accommodation}
- Special notes: {$specialRequests}

Return this EXACT JSON structure:
{
  "package_name": "<creative, catchy package title>",
  "tagline": "<one-line marketing description>",
  "destination": "<primary destination>",
  "secondary_destinations": ["<optional day trip>"],
  "duration": {$days},
  "start_date": "{$start}",
  "travelers": {$travelers},
  "travel_style": "{$style}",

  "pricing": {
    "total": <int BDT all travelers>,
    "per_person": <int BDT>,
    "accommodation_cost": <int>,
    "transport_cost": <int>,
    "activities_cost": <int>,
    "food_cost": <int>,
    "miscellaneous": <int>,
    "emergency_fund": <int>
  },

  "accommodation": {
    "name": "<hotel/resort name>",
    "type": "<budget|3-star|4-star|resort|eco-lodge>",
    "location": "<area in destination>",
    "check_in": "{$start}",
    "check_out": "<end date>",
    "features": ["<feature1>", "<feature2>"],
    "estimated_rate": "<per night in BDT>"
  },

  "transport": {
    "to_destination": "<bus/train/flight details>",
    "local_transport": "<how to get around>",
    "return_journey": "<return transport details>"
  },

  "itinerary_preview": [
    {"day": 1, "title": "<day title>", "highlights": ["<activity>"]},
    {"day": 2, "title": "<day title>", "highlights": ["<activity>"]},
    {"day": 3, "title": "<day title>", "highlights": ["<activity>"]}
  ],

  "included": ["<what is included>"],
  "excluded": ["<what is NOT included>"],
  "highlights": ["<top 5 experiences>"],
  "best_for": "<who this package is ideal for>",
  "ai_reasoning": "<2-3 sentences on why this package was designed this way>",

  "eco_score": <0-100>,
  "carbon_note": "<brief note on environmental impact>",

  "booking_deadline": "<recommended booking deadline>",
  "cancellation_policy": "<flexible|moderate|strict>",
  "difficulty_level": "<easy|moderate|challenging>",
  "age_suitability": "<all ages|adults|families|seniors>"
}

Generate ONE complete package. No other text.
MSG;

        $cacheKey = md5('package_' . json_encode($prefs));
        $result   = $this->ai->ask($system, $userMsg, $cacheKey, $email, feature: 'package_gen');

        if (!$result['success']) {
            return $this->fallbackPackage($prefs);
        }

        $package = $this->ai->parseJson($result['content']);
        // Validate and normalise in code (PackageDraft): shape, request-owned fields (days/travellers/start date), totals recomputed
        // from the cost lines, plain-text fields, capped lists. The result is always labelled an AI draft, not bookable, price = suggestion.
        $checked = is_array($package) ? PackageDraft::validate($package, $prefs) : ['ok' => false];
        if (empty($checked['ok'])) {
            return $this->fallbackPackage($prefs);
        }
        $package = $checked['package'];

        // Save the generated package to database
        $packageId = $this->savePackage($package, $email);
        $package['ai_package_id'] = $packageId;
        $package['is_ai_generated'] = true;

        return $package;
    }

    /**
     * Get all AI-generated packages for a user (their custom packages).
     */
    public function getUserPackages(string $email): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM tbl_ai_packages
                 WHERE user_email = ? AND expires_at > NOW()
                 ORDER BY created_at DESC LIMIT 10"
            );
            $stmt->execute([$email]);
            $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

            $packages = [];
            foreach ($rows as $row) {
                $pkg = json_decode($row->package_json, true);
                if ($pkg) {
                    $pkg['ai_package_id'] = $row->id;
                    $pkg['created_at']    = $row->created_at;
                    $packages[]           = $pkg;
                }
            }
            return $packages;
        } catch (Exception $e) {
            return [];
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function savePackage(array $package, string $email): int {
        try {
            $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
            $stmt = $this->db->prepare(
                "INSERT INTO tbl_ai_packages
                 (user_email, package_json, match_score, expires_at, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $email,
                json_encode($package, JSON_UNESCAPED_UNICODE),
                $package['eco_score'] ?? 75,
                $expires,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('[TMS PackageGen] Save error: ' . $e->getMessage());
            return 0;
        }
    }

    private function fallbackPackage(array $prefs): array {
        // No invented package or price: an honest, clearly-labelled placeholder (see PackageDraft::placeholder).
        return PackageDraft::placeholder($prefs);
    }
}
