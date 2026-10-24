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

        $budget    = $prefs['budget'] ?? 30000;
        $travelers = $prefs['travelers'] ?? 2;
        $days      = $prefs['duration'] ?? 5;
        $style     = $prefs['travel_type'] ?? 'leisure';
        $start     = $prefs['start_date'] ?? date('Y-m-d', strtotime('+7 days'));
        $climate   = $prefs['climate'] ?? 'warm';
        $activities = is_array($prefs['activities']) ? implode(', ', $prefs['activities']) : ($prefs['activities'] ?? 'sightseeing');
        $accommodation = $prefs['accommodation'] ?? 'hotel';

        $perPerson = intdiv($budget, $travelers);

        $userMsg = <<<MSG
Design a personalized travel package for:
- Budget: ৳{$budget} total for {$travelers} travelers (৳{$perPerson}/person)
- Duration: {$days} days starting {$start}
- Style: {$style} trip
- Climate preference: {$climate}
- Activities: {$activities}
- Accommodation: {$accommodation}
- Special notes: {$prefs['special_requests']}

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
        if (!$package) {
            return $this->fallbackPackage($prefs);
        }

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
        $budget    = $prefs['budget'] ?? 30000;
        $travelers = $prefs['travelers'] ?? 2;
        $days      = $prefs['duration'] ?? 5;

        // FIX (Round 108, AI-upgrade audit): this hardcoded placeholder used to
        // claim 'is_ai_generated' => true — the exact same flag the REAL
        // Gemini-generated package sets on success (see generate() above,
        // line ~140). That's a Rule 3/7 honesty bug: a static template with
        // three generic bullet points was being labeled as AI output. No
        // frontend page currently reads this field (grepped the full
        // codebase — zero call sites), so flipping it to false changes no
        // visible behavior today, but it makes the field trustworthy the
        // moment a UI does start showing an "AI Generated" badge from it.
        return [
            'package_name'           => 'Custom Travel Package',
            'tagline'                => 'A personalized journey just for you',
            'destination'            => $prefs['destination'] ?? 'Bangladesh',
            'secondary_destinations' => [],
            'duration'               => $days,
            'travelers'              => $travelers,
            'travel_style'           => $prefs['travel_type'] ?? 'leisure',
            'pricing' => [
                'total'      => $budget,
                'per_person' => intdiv($budget, $travelers),
            ],
            'highlights' => ['Explore local culture', 'Enjoy scenic views', 'Taste local cuisine'],
            'best_for'   => 'All travelers',
            'ai_reasoning' => 'AI package generation temporarily unavailable. This is a placeholder package.',
            'eco_score'  => 70,
            'is_ai_generated' => false,
            'ai_package_id'   => 0,
        ];
    }
}
