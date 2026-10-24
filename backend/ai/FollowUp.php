<?php
/**
 * FollowUp — resolves references to the PREVIOUS answer ("the second one", "the cheaper one", "compare them") to real package
 * ids, in code. The ids come only from package links the server itself saved in the assistant's last reply that listed
 * packages; nothing is guessed. If a reference cannot be resolved unambiguously the result says so (never a silent pick).
 */
require_once __DIR__ . '/BanglaNormalizer.php';
require_once __DIR__ . '/QueryParser.php';

final class FollowUp
{
    private const ORD = [
        'first' => 1, '1st' => 1, 'second' => 2, '2nd' => 2, 'third' => 3, '3rd' => 3, 'fourth' => 4, '4th' => 4,
        'প্রথম' => 1, 'প্রথমটি' => 1, 'দ্বিতীয়' => 2, 'দ্বিতীয়টি' => 2, 'তৃতীয়' => 3, 'তৃতীয়টি' => 3, 'চতুর্থ' => 4, 'fifth' => 5, '5th' => 5, 'sixth' => 6, '6th' => 6, 'পঞ্চম' => 5, 'ষষ্ঠ' => 6,
        // Banglish
        'prothom' => 1, 'prothomta' => 1, 'ditiyo' => 2, 'ditiyota' => 2, 'dwitiyo' => 2, 'tritiyo' => 3, 'tritiyota' => 3, 'tritio' => 3,
    ];

    /** Package ids, in order of appearance, from the most recent assistant turn that linked at least one package. @return int[] */
    public static function recentIds(array $history): array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') !== 'assistant') continue;
            if (!preg_match_all('/package-details\.php\?pkgid=(\d{1,9})/', (string)($history[$i]['content'] ?? ''), $m)) continue;
            $ids = [];
            foreach ($m[1] as $id) { $id = (int)$id; if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id; }
            return array_slice($ids, 0, 6);
        }
        return [];
    }

    /**
     * @param int[]               $ids    from recentIds()
     * @param array<int,int|float> $prices package id => price per person (only used for "cheaper/cheapest")
     * @return array{kind:string,ids:int[],target:?int,unresolved:bool}
     *   kind: none | compare | single. unresolved=true means the visitor referred to an earlier result but it cannot be identified.
     */
    public static function resolve(string $message, array $ids, array $prices = []): array
    {
        $none = ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => false];
        $m = mb_strtolower(BanglaNormalizer::digits($message), 'UTF-8');
        $compare = (bool)preg_match('/\b(compare|comparison|versus|vs\.?|difference between|which (?:one )?is better|which (?:one )?(?:should|to) (?:i|we) (?:pick|choose|take))\b|তুলনা|কোনটা ভালো|কোনটি ভালো/u', $m);
        $ordinals = [];
        foreach (self::ORD as $w => $n) if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $m)) $ordinals[$n] = true;
        $last = (bool)preg_match('/\b(the )?last one\b|\bthe last\b|শেষ(?:টি|টা)?/u', $m);
        $cheap = (bool)preg_match('/\b(cheaper|cheapest|less expensive|lowest price|more affordable)\b|সস্তা|কম দামের|কম দাম|\b(?:sosta|shosta|sasta)\b/u', $m);
        // "make that trip cheaper" / "find something cheaper" asks for a NEW search with a lower ceiling, not a pick from the list:
        // refine() owns that, so resolve() must not turn it into a single pick.
        if ($cheap && self::wantsCheaperSearch($m)) $cheap = false;
        $thatOne = (bool)preg_match('/\b(that one|this one|the one you (?:just )?(?:mentioned|showed|suggested)|those|these|them|both)\b|ওটা|এটা|ওগুলো|দুটো|দুটি/u', $m);
        $ref = $ordinals || $last || $cheap || ($compare && $thatOne) || ($compare && preg_match('/\b(them|these|those|both|all)\b|দুটো|দুটি/u', $m));
        if (!$compare && !$ordinals && !$last && !$cheap) return $none; // "that one" alone is too vague to act on without another cue
        if (!$ids) return $compare || $ref ? ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => $ref || $compare] : $none;

        // Which ids does the message point at?
        $picked = [];
        foreach (array_keys($ordinals) as $n) { if (isset($ids[$n - 1])) $picked[] = $ids[$n - 1]; else return ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => true]; }
        if ($last) $picked[] = $ids[count($ids) - 1];
        $picked = array_values(array_unique($picked));
        if ($cheap) {
            $pool = $picked ?: $ids; $best = null; $tie = false;
            foreach ($pool as $id) {
                if (!isset($prices[$id])) continue;
                if ($best === null || $prices[$id] < $prices[$best]) { $best = $id; $tie = false; }
                elseif ($prices[$id] === $prices[$best]) $tie = true;
            }
            if ($best === null || $tie) return ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => true];
            if (!$compare) return ['kind' => 'single', 'ids' => [$best], 'target' => $best, 'unresolved' => false];
        }
        if ($compare) {
            $set = $picked ?: $ids;
            $set = array_slice($set, 0, 4);
            if (count($set) < 2) return ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => true];
            return ['kind' => 'compare', 'ids' => $set, 'target' => null, 'unresolved' => false];
        }
        if (count($picked) === 1) return ['kind' => 'single', 'ids' => $picked, 'target' => $picked[0], 'unresolved' => false];
        return ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Conversation state refinements: "make that trip cheaper", "what if I travel with my family?", "change day three",
    // "keep the hotel but replace the activities". Resolved by code against explicit state (the last search arguments and the
    // package in focus); the language model only writes the answer. Nothing here reads or changes a booking.
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private const DAYWORD = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
                             'prothom' => 1, 'ditiyo' => 2, 'tritiyo' => 3, 'এক' => 1, 'দুই' => 2, 'তিন' => 3, 'চার' => 4, 'পাঁচ' => 5];
    private const PARTS = 'hotel|hotels|accommodation|activities|activity|transport|transfers?|flights?|food|meals?|dates?|destination|budget';

    /** True for "make that trip cheaper" style requests (a new, cheaper search) as opposed to "the cheaper one" (a pick from the list). */
    public static function wantsCheaperSearch(string $lowerMessage): bool
    {
        $m = $lowerMessage;
        if (preg_match('/\b(?:the )?(?:cheaper|cheapest) one\b|\bwhich (?:one )?is (?:the )?cheap|\bwhich\b.{0,20}\b(?:cheaper|cheapest)\b|কোনটা সস্তা|কোনটি সস্তা/u', $m)) return false;
        return (bool)preg_match('/\b(?:make|get|find|show|give|want|need|looking for|something|anything|reduce|lower|bring)\b.{0,40}\b(?:cheaper|less expensive|more affordable|lower|down|cheap)\b'
            . '|\b(?:cheaper|less expensive|more affordable)\b.{0,20}\b(?:version|alternative|options?|trips?|packages?|plan)\b'
            . '|\b(?:a )?(?:cheaper|less expensive)\b\s*(?:one|trip|plan)?\s*(?:please|pls)?\s*$|কম দামে|সস্তায়|\b(?:sosta|shosta|sasta)\b.{0,15}\b(?:dekhao|chai|den|koren|kore|hobe)\b|\b(?:sosta|shosta|sasta)\s*(?:ta|ti)\b/u', $m);
    }

    /**
     * Interpret a follow-up against the explicit conversation state.
     *
     * @param array $state {ids?:int[], prices?:array<int,int>, focus?:?int, search?:array{query?:string,max_price?:?int,min_price?:?int,days_min?:?int,days_max?:?int,travelers?:?int,mode?:?string}}
     * @return array{intent:string,search:array,note:string,day:?int,keep:string[],replace:string[],unresolved:bool,assumed:?string}
     *   intent: none | refine_search | itinerary_edit. For refine_search, 'search' holds the NEW arguments (merge into search_packages).
     */
    public static function refine(string $message, array $state): array
    {
        $none = ['intent' => 'none', 'search' => [], 'note' => '', 'day' => null, 'keep' => [], 'replace' => [], 'unresolved' => false, 'assumed' => null];
        $m = mb_strtolower(BanglaNormalizer::digits($message), 'UTF-8');
        $prev = is_array($state['search'] ?? null) ? $state['search'] : [];
        $prices = is_array($state['prices'] ?? null) ? $state['prices'] : [];
        $focus = isset($state['focus']) ? (int)$state['focus'] : 0;

        // 1) itinerary edits: "change day three", "replace day 2", "keep the hotel but replace the activities"
        $day = null; $keep = []; $replace = [];
        $dw = implode('|', array_map('preg_quote', array_keys(self::DAYWORD)));
        if (preg_match('/\b(?:change|replace|swap|redo|modify|edit|update|different|new)\b.{0,25}\bday\s*(\d{1,2}|' . $dw . ')\b|\bday\s*(\d{1,2}|' . $dw . ')\b.{0,20}\b(?:change|replace|swap|redo|different|boring)\b|(\d{1,2})\s*(?:নম্বর\s*)?দিন(?:টা|টি)?.{0,12}(?:বদল|পাল্টা|change)|\bday\s*(\d{1,2})\s*(?:ta|er)?\s*(?:bodlao|palto|change)/u', $m, $d)) {
            $tok = '';
            foreach ([1, 2, 3, 4] as $i) if (isset($d[$i]) && $d[$i] !== '') { $tok = $d[$i]; break; }
            $n = ctype_digit($tok) ? (int)$tok : (self::DAYWORD[$tok] ?? 0);
            if ($n >= 1 && $n <= 30) $day = $n;
        }
        if (preg_match('/\b(?:keep|retain|stay (?:at|in)|same)\b\s*(?:the |my |same )?(' . self::PARTS . ')\b/u', $m, $k)) $keep[] = self::canonPart($k[1]);
        if (preg_match('/\b(?:replace|change|swap|different|new|redo)\b\s*(?:the |my |all )?(' . self::PARTS . ')\b/u', $m, $r)) $replace[] = self::canonPart($r[1]);
        $keep = array_values(array_unique($keep)); $replace = array_values(array_diff(array_unique($replace), $keep));
        if ($day !== null || ($keep && $replace)) {
            $what = $day !== null ? "day {$day}" : 'the ' . implode(' and ', $replace);
            $note = "The visitor wants to change {$what} of the itinerary discussed earlier"
                . ($keep ? ' while keeping the ' . implode(' and ', $keep) . ' as it is' : '')
                . ". Propose alternatives using ONLY packages, hotels and activities returned in the reference data; keep everything else unchanged, show what changes and what it does to the cost, and ask them to confirm. Never say a booking was changed: nothing is changed until they confirm and complete it themselves.";
            return ['intent' => 'itinerary_edit', 'search' => [], 'note' => $note, 'day' => $day, 'keep' => $keep, 'replace' => $replace, 'unresolved' => false, 'assumed' => null];
        }

        // 2) cheaper search
        if (self::wantsCheaperSearch($m)) {
            $base = null;
            if ($focus > 0 && isset($prices[$focus])) $base = (int)$prices[$focus] - 1;             // strictly cheaper than the one they were looking at
            elseif (!empty($prev['max_price'])) $base = (int)(floor(((int)$prev['max_price'] * 0.8) / 100) * 100);
            elseif ($prices) $base = (int)min($prices) - 1;
            if ($base === null || $base < 500) return ['intent' => 'refine_search', 'search' => $prev, 'note' => 'The visitor wants something cheaper but there is no earlier price to go below. Ask what budget they have in mind; do not invent one.', 'day' => null, 'keep' => [], 'replace' => [], 'unresolved' => true, 'assumed' => null];
            $new = $prev; $new['max_price'] = $base; unset($new['min_price']);
            return ['intent' => 'refine_search', 'search' => $new, 'note' => "Cheaper alternatives requested: show packages priced at or under " . $base . " BDT per person (from the reference data only).", 'day' => null, 'keep' => [], 'replace' => [], 'unresolved' => false, 'assumed' => null];
        }

        // 3) "what if I travel with my family / with 6 people"
        if (preg_match('/\b(?:what if|how about|and if|if we|if i|going with|travel(?:l?ing)? with|with my|we are|instead)\b/u', $m)
            && preg_match('/\b(family|kids?|children|child|parents|wife|husband|friends|group)\b|পরিবার|\bporibar(?:er)?\b|\bbondhu(?:ra)?\b/u', $m)) {
            $q = QueryParser::parse($message);
            $assumed = null; $n = $q['travelers'];
            if ($n === null) { $n = preg_match('/\b(friends|group)\b|\bbondhu/u', $m) ? 4 : 4; $assumed = 'travellers not stated: assumed ' . $n . ' for the estimate'; }
            $new = $prev; $new['travelers'] = $n; $new['mode'] = preg_match('/\bfriends|group|\bbondhu/u', $m) ? 'group' : 'family';
            $q0 = trim((string)($prev['query'] ?? ''));
            if ($new['mode'] === 'family' && stripos($q0, 'family') === false) $new['query'] = trim($q0 . ' family');
            $note = "The visitor now travels as a {$new['mode']} of {$n}" . ($assumed ? " ({$assumed}; say so and ask them to confirm the number)" : '')
                  . ". Package prices are per person, so multiply by the travellers and say the total is an estimate; keep their earlier destination, dates and budget unless they changed them.";
            return ['intent' => 'refine_search', 'search' => $new, 'note' => $note, 'day' => null, 'keep' => [], 'replace' => [], 'unresolved' => false, 'assumed' => $assumed];
        }
        return $none;
    }

    private static function canonPart(string $p): string
    {
        $p = rtrim(strtolower($p), 's');
        return match ($p) { 'accommodation', 'hotel' => 'hotel', 'activitie', 'activity' => 'activities', 'transfer', 'transport', 'flight' => 'transport', 'meal', 'food' => 'food', 'date' => 'dates', default => $p };
    }
}
