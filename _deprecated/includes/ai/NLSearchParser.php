<?php
/**
 * NLSearchParser.php — Round 36 (item 30: "Build intelligent structured
 * search").
 *
 * Converts a free-text query ("cheap 3-day beach trip under ৳20,000") into
 * the same structured filters package-list.php's search form already
 * produces (type, max price, a duration range, a highly-rated toggle, a
 * free-text keyword) -- it never produces results itself. The item 30 spec
 * is explicit about this split: "The database must determine actual
 * results. AI should interpret intent, not invent results." So this class
 * has exactly one job -- text in, a small validated filter array out -- and
 * package-list.php's existing, already-trusted SQL query is what actually
 * runs against the catalog. Nothing here ever writes a package name, price,
 * or description into a response.
 *
 * Every AI-produced field is validated against the catalog's REAL current
 * values before use (the allowed `type` list is fetched fresh from
 * tbltourpackages, not hardcoded), so the model cannot invent a package
 * category or push through an out-of-range number -- if a field doesn't
 * pass validation, it's dropped (null), never substituted with a guess.
 *
 * One field the master prompt's own example asks for -- filtering by month
 * ("in December") -- is deliberately NOT implemented as a filter: packages
 * in this catalog are evergreen inventory with no per-package travel month
 * or seasonal-availability column (see DATABASE.md's schema notes), so
 * "filtering" by month would either silently do nothing or have to invent
 * seasonality data that doesn't exist (Rule 3). Instead, a mentioned month
 * is surfaced back to the caller as an honest `note` ("packages aren't
 * tracked by travel month, so 'December' wasn't used as a filter") rather
 * than pretended to work.
 *
 * Like every other AI feature in this codebase, this degrades gracefully:
 * if the AI provider is unconfigured, rate-limited, or returns something
 * that doesn't validate, parse() falls back to a small local regex-based
 * heuristic that covers the same fields at reduced accuracy, so the search
 * box keeps working with zero AI dependency.
 */

require_once __DIR__ . '/AIClient.php';

class NLSearchParser {

    private AIClient $ai;
    private PDO      $db;

    /** Recognized month names, for the honest "not used as a filter" note only. */
    private const MONTHS = [
        'january','february','march','april','may','june','july','august',
        'september','october','november','december',
    ];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
    }

    /**
     * @param string $query free-text trip request from the visitor
     * @param string $email for rate limiting / telemetry only
     * @return array{
     *   filters: array{type: ?string, max_price: ?int, duration_min: ?int, duration_max: ?int, keyword: ?string, rated_only: bool},
     *   ai_used: bool,
     *   note: ?string
     * }
     */
    public function parse(string $query, string $email = 'guest'): array {
        $query = trim($query);
        if ($query === '') {
            return ['filters' => $this->emptyFilters(), 'ai_used' => false, 'note' => null];
        }

        $allowedTypes = $this->getRealTypes();
        $mentionedMonth = $this->findMentionedMonth($query);

        $system = <<<SYSTEM
You convert a traveler's free-text trip request into a strict JSON search-filter object for a real, existing package database. You do not know what packages exist beyond the category list given to you, and you must never invent destination names, package names, or results -- only the filter fields below are your job. A separate, non-AI database query does the actual searching with whatever you return.

Return ONLY a JSON object, no markdown, no explanation, with exactly these fields:
{
  "type": "<one string EXACTLY as given in the allowed category list, or null if none clearly matches>",
  "max_price": <integer price ceiling in BDT if the query mentions a budget/price limit, else null>,
  "duration_days": <integer number of days/nights if the query mentions a trip length, else null>,
  "keyword": "<a short destination or place name from the query, else null>",
  "rated_only": <true if the query asks for highly-rated/best/top-rated trips, else false>
}

Rules:
- "type" must be copied verbatim from the allowed list or be null -- never a category you invent.
- Ignore any mention of travel month/season entirely -- do not try to encode it anywhere in this JSON.
- If nothing in the query maps to a field, use null (or false for rated_only). Do not guess.
SYSTEM;

        $userMessage = "Allowed categories: " . implode(', ', $allowedTypes) . "\n\nTraveler's request: \"{$query}\"";

        $cacheKey = 'nlsearch_' . md5(strtolower($query) . '|' . implode(',', $allowedTypes));
        $result = $this->ai->ask($system, $userMessage, $cacheKey, $email, feature: 'nl_search');

        $filters = null;
        $aiUsed = false;
        if ($result['success']) {
            $parsed = $this->ai->parseJson($result['content']);
            if (is_array($parsed)) {
                $filters = $this->validateFilters($parsed, $allowedTypes);
                $aiUsed = true;
            }
        }

        if ($filters === null) {
            $filters = $this->heuristicParse($query, $allowedTypes);
            $aiUsed = false;
        }

        $note = null;
        if ($mentionedMonth !== null) {
            $note = "Packages aren't tracked by travel month, so \"" . ucfirst($mentionedMonth) . "\" wasn't used as a filter -- showing results for your other criteria.";
        }

        return ['filters' => $filters, 'ai_used' => $aiUsed, 'note' => $note];
    }

    // ── Validation (both AI output and the manual dropdown/URL params go through here) ──

    /**
     * Turns a single duration number into the same [min, max] shape the
     * manual duration-bucket dropdown uses, so package-list.php only ever
     * has to reason about one range-overlap filter regardless of which UI
     * produced it.
     */
    public function validateFilters(array $raw, array $allowedTypes): array {
        $type = null;
        if (isset($raw['type']) && is_string($raw['type']) && $raw['type'] !== '') {
            foreach ($allowedTypes as $t) {
                if (strcasecmp(trim($raw['type']), $t) === 0) { $type = $t; break; }
            }
        }

        $maxPrice = null;
        if (isset($raw['max_price']) && is_numeric($raw['max_price'])) {
            $v = (int)$raw['max_price'];
            if ($v > 0 && $v <= 10_000_000) { $maxPrice = $v; }
        }

        $durationMin = $durationMax = null;
        if (isset($raw['duration_days']) && is_numeric($raw['duration_days'])) {
            $v = (int)$raw['duration_days'];
            if ($v >= 1 && $v <= 60) { $durationMin = $durationMax = $v; }
        }

        $keyword = null;
        if (isset($raw['keyword']) && is_string($raw['keyword'])) {
            $k = trim(strip_tags($raw['keyword']));
            if ($k !== '' && mb_strlen($k) <= 100) { $keyword = $k; }
        }

        $ratedOnly = !empty($raw['rated_only']);

        return [
            'type'          => $type,
            'max_price'     => $maxPrice,
            'duration_min'  => $durationMin,
            'duration_max'  => $durationMax,
            'keyword'       => $keyword,
            'rated_only'    => $ratedOnly,
        ];
    }

    /**
     * Does a package's own free-text duration ("3-4 Days", "10-14 Days",
     * "Half Day") overlap the requested [min, max] day range? Both a single
     * NL-parsed day count (min===max) and a manual bucket dropdown (a real
     * range) go through this same overlap check.
     */
    public static function durationOverlaps(?string $durationText, int $reqMin, int $reqMax): bool {
        $durationText = (string)$durationText;
        preg_match_all('/\d+/', $durationText, $m);
        $nums = array_map('intval', $m[0] ?? []);
        if (empty($nums)) {
            // No digits at all -- e.g. "Half Day". Treat as a sub-1-day trip.
            $pMin = 0; $pMax = 1;
        } elseif (count($nums) === 1) {
            $pMin = $pMax = $nums[0];
        } else {
            $pMin = min($nums); $pMax = max($nums);
        }
        return $pMin <= $reqMax && $pMax >= $reqMin;
    }

    // ── Fallback (no AI / AI unavailable / AI output didn't validate) ──────────

    private function heuristicParse(string $query, array $allowedTypes): array {
        $q = mb_strtolower($query);

        $type = null;
        foreach ($allowedTypes as $t) {
            // Match on the first word of a compound type ("Family Package" -> "family")
            // so "family trip" still matches without requiring the exact DB string.
            $firstWord = mb_strtolower(trim(explode('/', $t)[0]));
            $firstWord = mb_strtolower(trim(explode(' ', $firstWord)[0]));
            if ($firstWord !== '' && str_contains($q, $firstWord)) { $type = $t; break; }
        }

        $maxPrice = null;
        // "under 20000", "under ৳20,000", "below 20k", "less than 20000"
        if (preg_match('/(?:under|below|less than|within|max(?:imum)?)\D{0,6}([\d,]+)\s*(k)?/i', $q, $m)) {
            $val = (int)str_replace(',', '', $m[1]);
            if (!empty($m[2])) { $val *= 1000; }
            if ($val > 0) { $maxPrice = $val; }
        }

        $durationMin = $durationMax = null;
        if (preg_match('/(\d+)\s*-\s*(\d+)\s*[- ]?(day|days|night|nights)/i', $q, $m)) {
            $durationMin = (int)$m[1]; $durationMax = (int)$m[2];
        } elseif (preg_match('/(\d+)\s*-?\s*(day|days|night|nights)/i', $q, $m)) {
            // Covers both "3 days" and the common hyphenated adjective form
            // "3-day trip" -- found live-testing this exact phrasing, which
            // the master prompt's own example query uses.
            $durationMin = $durationMax = (int)$m[1];
        }

        $ratedOnly = (bool)preg_match('/\b(highly[- ]rated|best[- ]rated|top[- ]rated|5[- ]star|four[- ]star|4[- ]star)\b/i', $q);

        // No reliable place-name extraction without AI -- leave the whole
        // query available as a plain-keyword fallback rather than guessing
        // which word is the destination. package-list.php already knows how
        // to LIKE-match a free-text query against name/location/country.
        $keyword = null;

        return [
            'type'          => $type,
            'max_price'     => $maxPrice,
            'duration_min'  => $durationMin,
            'duration_max'  => $durationMax,
            'keyword'       => $keyword,
            'rated_only'    => $ratedOnly,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function getRealTypes(): array {
        try {
            return $this->db->query(
                "SELECT DISTINCT PackageType FROM tbltourpackages WHERE Status=1 AND PackageType IS NOT NULL AND PackageType <> '' ORDER BY PackageType"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function findMentionedMonth(string $query): ?string {
        $q = mb_strtolower($query);
        foreach (self::MONTHS as $month) {
            if (str_contains($q, $month)) { return $month; }
        }
        return null;
    }

    private function emptyFilters(): array {
        return ['type' => null, 'max_price' => null, 'duration_min' => null, 'duration_max' => null, 'keyword' => null, 'rated_only' => false];
    }
}
