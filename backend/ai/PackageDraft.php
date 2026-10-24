<?php
/**
 * PackageDraft — turns whatever the model returned for "design me a package" into a validated, clearly-labelled DRAFT.
 * Pure PHP (no DB, no model, no network).
 *
 * Rules enforced here, in code:
 *  - an AI package is never live: it is a draft ('AI draft', bookable=false). It is stored only in the traveller's own
 *    tbl_ai_packages row; nothing here (or in PackageGenerator) inserts into tbltourpackages.
 *  - prices are suggestions: the total is recomputed from the model's own cost lines (not trusted as a free-standing number),
 *    per-person is derived, and both are flagged price_basis='AI suggestion, not a quote'. An admin can edit them if the draft is
 *    ever turned into a real package (that is a manual, separate step).
 *  - structure is checked: duration, traveller count and start date come from the REQUEST, not the model; itinerary preview is
 *    trimmed to the real duration; text is plain text (markup stripped); lists are capped; unknown enum values are dropped.
 */
final class PackageDraft
{
    private const COST_LINES = ['accommodation_cost', 'transport_cost', 'activities_cost', 'food_cost', 'miscellaneous', 'emergency_fund'];
    private const ENUM = [
        'cancellation_policy' => ['flexible', 'moderate', 'strict'],
        'difficulty_level' => ['easy', 'moderate', 'challenging'],
        'age_suitability' => ['all ages', 'adults', 'families', 'seniors'],
    ];

    public static function text($v, int $max = 300): string
    {
        if (!is_scalar($v)) return '';
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v) ?? '';   // before strip_tags, which would silently drop NUL bytes
        $s = strip_tags($s);
        return trim(mb_substr(preg_replace('/\s+/u', ' ', $s) ?? '', 0, $max, 'UTF-8'));
    }

    private static function list($v, int $maxItems, int $maxLen): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $x) { $t = self::text($x, $maxLen); if ($t !== '') $out[] = $t; if (count($out) >= $maxItems) break; }
        return $out;
    }

    /**
     * @param array $pkg   decoded model output
     * @param array $prefs the traveller's request (budget, travelers, duration, start_date, destination)
     * @return array{ok:bool,package:array,problems:string[]}  ok=false means the shape is unusable (caller falls back)
     */
    public static function validate(array $pkg, array $prefs): array
    {
        $problems = [];
        $name = self::text($pkg['package_name'] ?? '', 120);
        if ($name === '') return ['ok' => false, 'package' => [], 'problems' => ['no package name']];
        $pricing = is_array($pkg['pricing'] ?? null) ? $pkg['pricing'] : [];
        $travellers = max(1, min(50, (int)($prefs['travelers'] ?? 2)));
        $days = max(1, min(30, (int)($prefs['duration'] ?? 5)));
        $budget = isset($prefs['budget']) && is_numeric($prefs['budget']) ? max(0, (int)$prefs['budget']) : 0;
        $start = (isset($prefs['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$prefs['start_date']) && strtotime((string)$prefs['start_date'])) ? (string)$prefs['start_date'] : '';

        $lines = []; $sum = 0;
        foreach (self::COST_LINES as $k) {
            $v = isset($pricing[$k]) && is_numeric($pricing[$k]) ? max(0, (int)round((float)$pricing[$k])) : 0;
            $lines[$k] = $v; $sum += $v;
        }
        $modelTotal = isset($pricing['total']) && is_numeric($pricing['total']) ? (int)round((float)$pricing['total']) : 0;
        if ($sum > 0) {
            $total = $sum;
            if ($modelTotal > 0 && abs($modelTotal - $sum) > 0.02 * $modelTotal) $problems[] = 'model total ' . $modelTotal . ' did not match its own cost lines (' . $sum . '); the sum of the lines is used';
        } elseif ($modelTotal > 0) {
            $total = $modelTotal; $problems[] = 'no cost breakdown given; the model total is shown as a rough suggestion only';
        } else {
            return ['ok' => false, 'package' => [], 'problems' => ['no usable price']];
        }

        $preview = [];
        foreach (is_array($pkg['itinerary_preview'] ?? null) ? array_values($pkg['itinerary_preview']) : [] as $i => $d) {
            if (!is_array($d) || count($preview) >= $days) continue;
            $preview[] = ['day' => count($preview) + 1, 'title' => self::text($d['title'] ?? '', 120), 'highlights' => self::list($d['highlights'] ?? [], 6, 150)];
        }

        $out = [
            'package_name' => $name,
            'tagline' => self::text($pkg['tagline'] ?? '', 200),
            'destination' => self::text($pkg['destination'] ?? ($prefs['destination'] ?? ''), 100),
            'secondary_destinations' => self::list($pkg['secondary_destinations'] ?? [], 5, 100),
            'duration' => $days, 'travelers' => $travellers, 'start_date' => $start,          // from the request, never the model
            'travel_style' => self::text($pkg['travel_style'] ?? ($prefs['travel_type'] ?? ''), 40),
            'pricing' => $lines + ['total' => $total, 'per_person' => intdiv($total, $travellers)],
            'accommodation' => is_array($pkg['accommodation'] ?? null) ? [
                'name' => self::text($pkg['accommodation']['name'] ?? '', 120), 'type' => self::text($pkg['accommodation']['type'] ?? '', 40),
                'location' => self::text($pkg['accommodation']['location'] ?? '', 100), 'features' => self::list($pkg['accommodation']['features'] ?? [], 8, 80),
                'estimated_rate' => self::text($pkg['accommodation']['estimated_rate'] ?? '', 60),
            ] : null,
            'transport' => is_array($pkg['transport'] ?? null) ? array_map(fn($x) => self::text($x, 200), array_intersect_key($pkg['transport'], array_flip(['to_destination', 'local_transport', 'return_journey']))) : null,
            'itinerary_preview' => $preview,
            'included' => self::list($pkg['included'] ?? [], 12, 120), 'excluded' => self::list($pkg['excluded'] ?? [], 12, 120),
            'highlights' => self::list($pkg['highlights'] ?? [], 6, 120),
            'best_for' => self::text($pkg['best_for'] ?? '', 150),
            'ai_reasoning' => self::text($pkg['ai_reasoning'] ?? '', 500),
            'eco_score' => isset($pkg['eco_score']) && is_numeric($pkg['eco_score']) ? max(0, min(100, (int)$pkg['eco_score'])) : null,
            'carbon_note' => self::text($pkg['carbon_note'] ?? '', 200),
            'booking_deadline' => self::text($pkg['booking_deadline'] ?? '', 60),
        ];
        foreach (self::ENUM as $k => $allowed) {
            $v = strtolower(self::text($pkg[$k] ?? '', 30));
            $out[$k] = in_array($v, $allowed, true) ? $v : null;
        }
        if ($days > count($preview)) $problems[] = 'itinerary preview covers ' . count($preview) . ' of ' . $days . ' days (a preview, not the full plan)';
        if ($budget > 0 && $total > $budget) $problems[] = 'suggested total ' . $total . ' is above the stated budget ' . $budget;

        $out += [
            'is_draft' => true, 'status_label' => 'AI draft', 'bookable' => false, 'is_ai_generated' => true,
            'price_basis' => 'AI suggestion, not a quote. Prices are not from our catalogue and can be edited by an admin before any real package is created.',
            'budget_check' => $budget > 0 ? ['budget' => $budget, 'suggested_total' => $total, 'status' => $total <= $budget ? 'within_budget' : 'over_budget', 'over_by' => max(0, $total - $budget)] : null,
            'validation_notes' => $problems,
        ];
        return ['ok' => true, 'package' => $out, 'problems' => $problems];
    }

    /** Honest placeholder when the model failed: no invented price, no claim that it was designed for the traveller. */
    public static function placeholder(array $prefs): array
    {
        $travellers = max(1, min(50, (int)($prefs['travelers'] ?? 2)));
        $days = max(1, min(30, (int)($prefs['duration'] ?? 5)));
        $budget = isset($prefs['budget']) && is_numeric($prefs['budget']) ? max(0, (int)$prefs['budget']) : 0;
        return [
            'package_name' => 'Draft package (AI unavailable)', 'tagline' => 'Not generated: the AI planner could not be reached.',
            'destination' => self::text($prefs['destination'] ?? '', 100), 'secondary_destinations' => [],
            'duration' => $days, 'travelers' => $travellers, 'start_date' => '', 'travel_style' => self::text($prefs['travel_type'] ?? '', 40),
            'pricing' => ['total' => $budget, 'per_person' => intdiv($budget, $travellers)],
            'highlights' => [], 'best_for' => '', 'itinerary_preview' => [],
            'ai_reasoning' => 'The AI package designer is unavailable right now, so nothing was designed. The only number shown is your own budget, repeated back. Please try again, or browse our published packages.',
            'eco_score' => null, 'is_draft' => true, 'status_label' => 'AI draft', 'bookable' => false, 'is_ai_generated' => false, 'is_fallback' => true,
            'price_basis' => 'Your stated budget, not a price.', 'budget_check' => null, 'validation_notes' => ['placeholder'], 'ai_package_id' => 0,
        ];
    }
}
